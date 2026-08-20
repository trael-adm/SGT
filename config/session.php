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
        $maxAge = time() - (int) ini_get('session.gc_maxlifetime');
        $stmt = $this->pdo->prepare(
            'SELECT data FROM php_sessions WHERE id = ? AND updated_at > ? LIMIT 1'
        );
        $stmt->execute([$id, $maxAge]);
        return (string) ($stmt->fetchColumn() ?: '');
    }

    public function write(string $id, string $data): bool
    {
        $this->pdo->prepare(
            'INSERT INTO php_sessions (id, data, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = VALUES(updated_at)'
        )->execute([$id, $data, time()]);
        return true;
    }

    public function destroy(string $id): bool
    {
        $this->pdo->prepare('DELETE FROM php_sessions WHERE id = ?')->execute([$id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $stmt = $this->pdo->prepare('DELETE FROM php_sessions WHERE updated_at < ?');
        $stmt->execute([time() - $max_lifetime]);
        return $stmt->rowCount();
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

function hasAcesso(string $recurso): bool
{
    if (!isLoggedIn()) return false;
    $user = currentUser();
    // Administrador tem acesso irrestrito (ID 1, 201 ou 202 dependendo do banco)
    if (in_array((int) ($user['id_perfil'] ?? 0), [1, 201, 202], true)) return true;

    static $perms = null;
    if ($perms === null) {
        $perms = [];
        try {
            $pdo = getDB();
            // 1. Carrega permissões do perfil
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
            
            // Retrocompatibilidade: mapear os novos IDs para os antigos
            if (($perms['lab.reg'] ?? 'off') !== 'off' || ($perms['lab.lis'] ?? 'off') !== 'off' || ($perms['lab.ret'] ?? 'off') !== 'off') {
                $perms['tab:laboratorio'] = 'total';
            }
            if (($perms['iqf.reg'] ?? 'off') !== 'off' || ($perms['iqf.lis'] ?? 'off') !== 'off' || ($perms['iqf.ret'] ?? 'off') !== 'off') {
                $perms['tab:inspecao_final'] = 'total';
            }
            if (($perms['pin.pai'] ?? 'off') !== 'off' || ($perms['pin.ret'] ?? 'off') !== 'off') {
                $perms['tab:pintura'] = 'total';
            }
            if (($perms['ret.pan'] ?? 'off') !== 'off' || ($perms['ret.rel'] ?? 'off') !== 'off' || ($perms['ret.dash'] ?? 'off') !== 'off' || ($perms['ret.pri'] ?? 'off') !== 'off') {
                $perms['tab:retrabalho'] = 'total';
            }
            if (($perms['adm.usu'] ?? 'off') !== 'off' || ($perms['adm.per'] ?? 'off') !== 'off') {
                $perms['admin'] = 'total';
            }
            if (!isset($perms['ana.aco']) && isset($perms['ana.his'])) {
                $perms['ana.aco'] = $perms['ana.his'];
            }
            if (($perms['ana.aco'] ?? 'off') !== 'off' || ($perms['ana.his'] ?? 'off') !== 'off') {
                $perms['tab:analise'] = 'total';
            }
            
            // Como bônus, permitir que exceções legadas salvas puramente como "admin" funcionem
            $stmtLegado = $pdo->prepare('SELECT tela FROM usuario_acessos WHERE id_usuario = ? AND nivel = "view"');
            $stmtLegado->execute([$user['id']]);
            foreach ($stmtLegado->fetchAll(PDO::FETCH_COLUMN) as $t) {
                if (!isset($perms[$t])) $perms[$t] = 'view';
            }

        } catch (\Throwable $e) {
            // fallback silencioso
        }
    }
    
    // Considera aprovado se o nível for diferente de 'off' ou se estiver listado em um array sequencial (legado)
    $lvl = $perms[$recurso] ?? 'off';
    return $lvl !== 'off' || in_array($recurso, $perms, true);
}

function requireAcessoModulo(string $modulo): void
{
    requireLogin();
    
    // Mapeamento de compatibilidade com nomes legados
    $recursoMap = [
        'producao'       => 'tab:laboratorio',
        'inspecao_final' => 'tab:inspecao_final',
        'pintura'        => 'tab:pintura',
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
