<?php
declare(strict_types=1);

/**
 * SOMA — Auditoria de Atividades (Últimas Alterações)
 * Acesso restrito: somente Administrador (perfil 1)
 */

require_once dirname(__DIR__, 2) . '/config/conexao.php';
require_once dirname(__DIR__, 2) . '/config/session.php';
require_once dirname(__DIR__, 2) . '/includes/helpers.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

requirePerfil([1]);

$db      = getDB();
$usuario = currentUser();

// ========================================================
// 1. Parâmetros de Filtro
// ========================================================
$pagina  = max(1, (int) ($_GET['pagina'] ?? 1));
$perPage = 60;
$offset  = ($pagina - 1) * $perPage;

$busca      = trim((string) ($_GET['busca']   ?? ''));
$tipoAcao   = trim((string) ($_GET['acao']    ?? ''));
$idUsuarioF = !empty($_GET['id_usuario']) ? (int) $_GET['id_usuario'] : null;

// WHERE dinâmico
$where  = [];
$params = [];

if ($busca !== '') {
    $where[]          = '(u.nome LIKE :busca OR l.descricao LIKE :busca2 OR l.ip LIKE :busca3)';
    $params['busca']  = "%{$busca}%";
    $params['busca2'] = "%{$busca}%";
    $params['busca3'] = "%{$busca}%";
}
if ($tipoAcao !== '') {
    $where[]             = 'l.tipo = :tipo_acao';
    $params['tipo_acao'] = $tipoAcao;
}
if ($idUsuarioF) {
    $where[]               = 'l.id_usuario = :id_usuario_f';
    $params['id_usuario_f'] = $idUsuarioF;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ========================================================
// 2. Contagem e Paginação
// ========================================================
$stmtTotal = $db->prepare("
    SELECT COUNT(*) AS total
    FROM logs_atividade l
    LEFT JOIN usuarios u ON u.id = l.id_usuario
    {$whereSql}
");
$stmtTotal->execute($params);
$totalRegistros = (int) ($stmtTotal->fetchColumn() ?: 0);
$totalPaginas   = max(1, (int) ceil($totalRegistros / $perPage));
$pagina         = min($pagina, $totalPaginas);

// ========================================================
// 3. Registros do Log
// ========================================================
$stmtLogs = $db->prepare("
    SELECT
        l.id,
        l.tipo AS acao,
        l.descricao AS detalhes,
        l.ip,
        l.created_at,
        u.id   AS usuario_id,
        u.nome AS usuario_nome,
        p.nome AS perfil_nome
    FROM logs_atividade l
    LEFT JOIN usuarios u ON u.id = l.id_usuario
    LEFT JOIN perfis p   ON p.id = u.id_perfil
    {$whereSql}
    ORDER BY l.id DESC
    LIMIT :limit OFFSET :offset
");
$stmtLogs->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmtLogs->bindValue(':offset', $offset,  PDO::PARAM_INT);
foreach ($params as $k => $v) {
    $stmtLogs->bindValue(':' . $k, $v);
}
$stmtLogs->execute();
$logs = $stmtLogs->fetchAll();

// ========================================================
// 4. Lista de Tipos de Ação (para filtro)
// ========================================================
try {
    $tiposAcao = $db->query("
        SELECT DISTINCT tipo FROM logs_atividade WHERE tipo IS NOT NULL ORDER BY tipo ASC
    ")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $tiposAcao = [];
}

// ========================================================
// 5. Lista de Usuários (para filtro)
// ========================================================
$usuariosLista = $db->query("
    SELECT id, nome, email FROM usuarios WHERE deleted_at IS NULL ORDER BY nome ASC
")->fetchAll();

layoutHeader('SOMA — Auditoria de Atividades', 'soma');

$somaAbaAtual = 'auditoria';
require dirname(__DIR__, 2) . '/includes/soma-subnav.php';
?>

<!-- Filtros de Auditoria -->
<div class="card mb-6 p-4">
    <form method="GET" action="" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
        <div>
            <label class="block text-xs font-semibold text-[#5a6480] mb-1">Buscar por Palavra-chave</label>
            <input type="text" name="busca" value="<?= e($busca) ?>" placeholder="Nome, IP, ação..." class="form-input text-xs">
        </div>

        <div>
            <label class="block text-xs font-semibold text-[#5a6480] mb-1">Tipo de Ação</label>
            <select name="acao" class="form-input text-xs">
                <option value="">Todas as Ações</option>
                <?php foreach ($tiposAcao as $ta): ?>
                    <option value="<?= e($ta) ?>" <?= $tipoAcao === $ta ? 'selected' : '' ?>><?= e($ta) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="block text-xs font-semibold text-[#5a6480] mb-1">Usuário</label>
            <select name="id_usuario" class="form-input text-xs">
                <option value="">Todos os Usuários</option>
                <?php foreach ($usuariosLista as $u): ?>
                    <option value="<?= (int) $u['id'] ?>" <?= $idUsuarioF === (int) $u['id'] ? 'selected' : '' ?>>
                        <?= e($u['nome']) ?> (<?= e($u['email']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="flex gap-2">
            <button type="submit" class="btn btn-primary text-xs w-full justify-center">
                <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                Filtrar Logs
            </button>
            <a href="<?= APP_URL ?>/pages/soma/auditoria.php" class="btn btn-neutral text-xs px-2.5" title="Limpar Filtros">✕</a>
        </div>
    </form>
</div>

<!-- Tabela de Logs de Auditoria -->
<div class="card p-4 space-y-4">
    <div class="pb-3 border-b border-[#e2e6ed] flex items-center justify-between">
        <div>
            <h3 class="text-sm font-bold text-[#1a2133]">Trilha de Auditoria & Alterações</h3>
            <p class="text-[11px] text-[#5a6480]">Histórico de ações, logins e operações realizadas no sistema.</p>
        </div>
        <span class="badge badge-neutral text-xs font-mono"><?= number_format($totalRegistros, 0, ',', '.') ?> registros</span>
    </div>

    <div class="overflow-x-auto border border-[#e2e6ed] rounded-lg">
        <table class="w-full text-left text-xs">
            <thead class="bg-[#f8f9fb] text-[#5a6480] border-b border-[#e2e6ed]">
                <tr>
                    <th class="py-2.5 px-3 w-36">Data / Hora</th>
                    <th class="py-2.5 px-3">Usuário</th>
                    <th class="py-2.5 px-3 w-28">Perfil</th>
                    <th class="py-2.5 px-3 w-32">Ação</th>
                    <th class="py-2.5 px-3 w-28">IP</th>
                    <th class="py-2.5 px-3">Detalhes</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#e2e6ed]">
                <?php if (empty($logs)): ?>
                    <tr><td colspan="6" class="text-center py-8 text-[#9aa3b8]">Nenhum registro de log encontrado.</td></tr>
                <?php else: foreach ($logs as $l): ?>
                    <tr class="hover:bg-[#f8f9fb] transition">
                        <td class="py-2 px-3 font-mono text-[#5a6480] whitespace-nowrap">
                            <?= date('d/m/Y H:i:s', strtotime((string) $l['created_at'])) ?>
                        </td>
                        <td class="py-2 px-3 font-semibold text-[#1a2133]">
                            <?= e($l['usuario_nome'] ?? 'Sistema / Anônimo') ?>
                        </td>
                        <td class="py-2 px-3">
                            <span class="badge badge-neutral text-[10px]"><?= e($l['perfil_nome'] ?? '—') ?></span>
                        </td>
                        <td class="py-2 px-3 font-mono font-bold text-xs text-[#1a3d2a]">
                            <?= e($l['acao'] ?? 'acao') ?>
                        </td>
                        <td class="py-2 px-3 font-mono text-[11px] text-[#9aa3b8]">
                            <?= e($l['ip'] ?? '—') ?>
                        </td>
                        <td class="py-2 px-3 text-[#5a6480]">
                            <?= e($l['detalhes'] ?? '—') ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Paginação -->
    <?php if ($totalPaginas > 1): ?>
        <div class="py-3 px-4 bg-[#f8f9fb] border border-[#e2e6ed] rounded-lg flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
            <span class="text-[#5a6480]">
                Página <strong><?= $pagina ?></strong> de <strong><?= $totalPaginas ?></strong> (<?= $totalRegistros ?> eventos)
            </span>
            <div class="flex items-center gap-1">
                <?php $queryParams = $_GET; ?>
                <?php if ($pagina > 1): $queryParams['pagina'] = $pagina - 1; ?>
                    <a href="?<?= http_build_query($queryParams) ?>" class="px-2.5 py-1 bg-white border border-[#e2e6ed] rounded text-[#5a6480] hover:bg-[#f8f9fb]">
                        &laquo; Anterior
                    </a>
                <?php endif; ?>

                <?php for ($i = max(1, $pagina - 2); $i <= min($totalPaginas, $pagina + 2); $i++): 
                    $queryParams['pagina'] = $i;
                ?>
                    <a href="?<?= http_build_query($queryParams) ?>" class="px-2.5 py-1 border rounded <?= $i === $pagina ? 'bg-[#1a3d2a] text-white border-[#1a3d2a] font-bold' : 'bg-white border-[#e2e6ed] text-[#5a6480] hover:bg-[#f8f9fb]' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>

                <?php if ($pagina < $totalPaginas): $queryParams['pagina'] = $pagina + 1; ?>
                    <a href="?<?= http_build_query($queryParams) ?>" class="px-2.5 py-1 bg-white border border-[#e2e6ed] rounded text-[#5a6480] hover:bg-[#f8f9fb]">
                        Próxima &raquo;
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
layoutFooter();
