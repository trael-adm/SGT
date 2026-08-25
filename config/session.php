<?php
declare(strict_types=1);

date_default_timezone_set('America/Cuiaba');

// ─── Session handler com armazenamento em MySQL ───────────────────────────────
// Necessário no Railway (filesystem efêmero perde sessões em reinicializações).

class TraelDbSessionHandler implements SessionHandlerInterface
{
    public function __construct(private PDO $pdo) {}

    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }

    public function read(string $id): string
    {
        try {
            $maxAge = time() - (int) ini_get('session.gc_maxlifetime');
            $stmt = $this->pdo->prepare(
                'SELECT data FROM php_sessions WHERE id = ? AND updated_at > ? LIMIT 1'
            );
            $stmt->execute([$id, $maxAge]);
            return (string) ($stmt->fetchColumn() ?: '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO php_sessions (id, data, updated_at) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = VALUES(updated_at)'
            )->execute([$id, $data, time()]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $this->pdo->prepare('DELETE FROM php_sessions WHERE id = ?')->execute([$id]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM php_sessions WHERE updated_at < ?');
            $stmt->execute([time() - $max_lifetime]);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (session_status() === PHP_SESSION_NONE) {
    // Registrar handler de sessão no banco — com fallback para arquivo se DB falhar
    try {
        $handler = new TraelDbSessionHandler(getDB());
        session_set_save_handler($handler, true);
    } catch (\Exception $e) {
        // Fallback silencioso para sessões em arquivo (evita quebrar login se BD cair)
    }

    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '86400'); // 24h
    session_start();
}

function isLoggedIn(): bool
{
    return isset($_SESSION['usuario']['id']);
}

function currentUser(): array
{
    return $_SESSION['usuario'] ?? [];
}

function isAdmin(): bool
{
    if (!isLoggedIn()) return false;
    $user = currentUser();
    if (in_array((int) ($user['id_perfil'] ?? 0), [1, 201, 202], true)) return true;
    return hasAcesso('admin') || hasAcesso('adm.usu') || hasAcesso('adm.per');
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        $base = defined('APP_URL') ? APP_URL : '';
        header('Location: ' . $base . '/login.php');
        exit;
    }
}

function requirePerfil(array $perfis): void
{
    requireLogin();
    $user = currentUser();
    if (!in_array((int) ($user['id_perfil'] ?? 0), $perfis, true)) {
        http_response_code(403);
        $base = defined('APP_URL') ? APP_URL : '';
        echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">'
            . '<title>Acesso Negado — SGT</title>'
            . '<style>body{font-family:sans-serif;text-align:center;padding:4rem;color:#333}'
            . 'h1{color:#b91c1c}a{color:#e8a020}</style></head><body>'
            . '<h1>403 — Acesso Negado</h1>'
            . '<p>Você não tem permissão para acessar esta página.</p>'
            . '<a href="' . htmlspecialchars($base) . '/index.php">Voltar ao início</a>'
            . '</body></html>';
        exit;
    }
}

function getPermissoesUsuario(): array
{
    if (!isLoggedIn()) return [];
    $user = currentUser();

    static $perms = null;
    if ($perms === null) {
        $perms = [];
        try {
            $pdo = getDB();
            // 1. Carrega permissões do perfil base
            if (!empty($user['id_perfil'])) {
                $stmt = $pdo->prepare('SELECT perms FROM perfis WHERE id = ? AND status = "ativo"');
                $stmt->execute([$user['id_perfil']]);
                if ($json = $stmt->fetchColumn()) {
                    $p = json_decode($json, true);
                    if (is_array($p)) {
                        $perms = $p;
                    }
                }
            }
            // 2. Sobrepõe com exceções do usuário
            $stmt = $pdo->prepare('SELECT tela, nivel FROM usuario_acessos WHERE id_usuario = ?');
            $stmt->execute([$user['id']]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $perms[$row['tela']] = $row['nivel'];
            }

            // Sincronização de Aliases
            if (isset($perms['ret.pri']) && !array_key_exists('pcp.pri', $perms)) {
                $perms['pcp.pri'] = $perms['ret.pri'];
            }
            if (isset($perms['pcp.pri']) && !array_key_exists('ret.pri', $perms)) {
                $perms['ret.pri'] = $perms['pcp.pri'];
            }
            if (isset($perms['ana.his']) && !array_key_exists('ana.aco', $perms)) {
                $perms['ana.aco'] = $perms['ana.his'];
            }
            if (isset($perms['adm.per']) && !array_key_exists('adm.usu', $perms)) {
                $perms['adm.usu'] = $perms['adm.per'];
            }

        } catch (\Throwable $e) {
            // fallback silencioso
        }
    }

    return $perms;
}

function getNivelAcesso(string $recurso): string
{
    if (!isLoggedIn()) return 'off';
    $user = currentUser();
    // Administrador tem acesso total irrestrito (ID 1, 201 ou 202 dependendo do banco)
    if (in_array((int) ($user['id_perfil'] ?? 0), [1, 201, 202], true)) return 'total';

    $perms = getPermissoesUsuario();

    // Mapeamento de abas / grupos legados para suas telas
    if ($recurso === 'tab:pcp') {
        $lvl = $perms['pcp.pri'] ?? ($perms['ret.pri'] ?? 'off');
        return ($lvl === 'total' || $lvl === 'view') ? $lvl : 'off';
    }
    if ($recurso === 'tab:laboratorio') {
        $lvls = [$perms['lab.reg'] ?? 'off', $perms['lab.lis'] ?? 'off', $perms['lab.ret'] ?? 'off'];
        if (in_array('total', $lvls, true)) return 'total';
        if (in_array('view', $lvls, true)) return 'view';
        return 'off';
    }
    if ($recurso === 'tab:inspecao_final') {
        $lvls = [$perms['iqf.reg'] ?? 'off', $perms['iqf.lis'] ?? 'off', $perms['iqf.ret'] ?? 'off'];
        if (in_array('total', $lvls, true)) return 'total';
        if (in_array('view', $lvls, true)) return 'view';
        return 'off';
    }
    if ($recurso === 'tab:pintura') {
        $lvls = [$perms['pin.pai'] ?? 'off', $perms['pin.ret'] ?? 'off'];
        if (in_array('total', $lvls, true)) return 'total';
        if (in_array('view', $lvls, true)) return 'view';
        return 'off';
    }
    if ($recurso === 'tab:retrabalho') {
        $lvls = [$perms['ret.pan'] ?? 'off', $perms['ret.rel'] ?? 'off', $perms['ret.dash'] ?? 'off', $perms['ret.pri'] ?? 'off'];
        if (in_array('total', $lvls, true)) return 'total';
        if (in_array('view', $lvls, true)) return 'view';
        return 'off';
    }
    if ($recurso === 'tab:analise') {
        $lvls = [$perms['ana.aco'] ?? 'off', $perms['ana.his'] ?? 'off'];
        if (in_array('total', $lvls, true)) return 'total';
        if (in_array('view', $lvls, true)) return 'view';
        return 'off';
    }
    if ($recurso === 'admin') {
        $lvl = $perms['adm.usu'] ?? ($perms['adm.per'] ?? ($perms['admin'] ?? 'off'));
        return ($lvl === 'total' || $lvl === 'view') ? $lvl : 'off';
    }

    $lvl = $perms[$recurso] ?? 'off';
    if ($lvl === 'off' || $lvl === '' || $lvl === null) {
        if ($recurso === 'pcp.pri') $lvl = $perms['ret.pri'] ?? 'off';
        elseif ($recurso === 'ret.pri') $lvl = $perms['pcp.pri'] ?? 'off';
        elseif ($recurso === 'ana.aco') $lvl = $perms['ana.his'] ?? 'off';
        elseif ($recurso === 'ana.his') $lvl = $perms['ana.aco'] ?? 'off';
        elseif ($recurso === 'adm.usu') $lvl = $perms['adm.per'] ?? 'off';
        elseif ($recurso === 'adm.per') $lvl = $perms['adm.usu'] ?? 'off';
    }

    return ($lvl === 'total' || $lvl === 'view') ? $lvl : 'off';
}

function hasAcesso(string $recurso): bool
{
    return getNivelAcesso($recurso) !== 'off';
}

function podeEditar(string $recurso): bool
{
    return getNivelAcesso($recurso) === 'total';
}

function requirePodeEditar(string $recurso, string $msg = 'Apenas consulta. Você não tem permissão para realizar alterações.'): void
{
    if (!podeEditar($recurso)) {
        http_response_code(403);
        $isJson = isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')
            || (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'))
            || str_ends_with($_SERVER['SCRIPT_NAME'] ?? '', '-acao.php')
            || str_ends_with($_SERVER['SCRIPT_NAME'] ?? '', '-api.php');
        if ($isJson) {
            echo json_encode(['sucesso' => false, 'erro' => $msg]);
        } else {
            $base = defined('APP_URL') ? APP_URL : '';
            echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><title>Acesso Negado — SGT</title></head><body><h1>403 — Apenas Consulta</h1><p>' . htmlspecialchars($msg) . '</p><a href="' . htmlspecialchars($base) . '/index.php">Voltar</a></body></html>';
        }
        exit;
    }
}

function requireAcessoModulo(string $modulo): void
{
    requireLogin();
    
    // Mapeamento de compatibilidade com nomes legados
    $recursoMap = [
        'producao'       => 'tab:laboratorio',
        'inspecao_final' => 'tab:inspecao_final',
        'pintura'        => 'tab:pintura',
        'pcp'            => 'tab:pcp',
        'retrabalho'     => 'tab:retrabalho',
        'analise'        => 'tab:analise',
        'qualidade'      => 'qua.tip'
    ];
    $recurso = $recursoMap[$modulo] ?? $modulo;

    if (!hasAcesso($recurso)) {
        http_response_code(403);
        $base = defined('APP_URL') ? APP_URL : '';
        echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">'
            . '<title>Acesso Negado — SGT</title>'
            . '<style>body{font-family:sans-serif;text-align:center;padding:4rem;color:#333}'
            . 'h1{color:#b91c1c}a{color:#e8a020}</style></head><body>'
            . '<h1>403 — Acesso Negado</h1>'
            . '<p>Você não tem permissão para acessar o módulo de ' . htmlspecialchars($modulo) . '.</p>'
            . '<a href="' . htmlspecialchars($base) . '/index.php">Voltar ao início</a>'
            . '</body></html>';
        exit;
    }
}
