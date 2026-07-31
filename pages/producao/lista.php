<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();

$pdo  = getDB();
$base = defined('APP_URL') ? APP_URL : '';

$ESTACOES_VALIDAS = ['IQF', 'LAB', 'GER'];
$STATUS_VALIDOS   = ['em_andamento', 'finalizado'];

// ─── Filtros (GET) ────────────────────────────────────────────────────────────
$fBusca   = trim((string) ($_GET['busca'] ?? ''));
$fEstacao = trim((string) ($_GET['estacao'] ?? ''));
$fMes     = trim((string) ($_GET['mes'] ?? ''));

if (!in_array($fEstacao, $ESTACOES_VALIDAS, true)) $fEstacao = '';
if (!preg_match('/^\d{4}-\d{2}$/', $fMes))          $fMes = '';

// "Mostrar" (status): checkboxes escondidos a pedido — mostra sempre tudo.
$fStatus = $STATUS_VALIDOS;

// Ordenação (clique nas colunas da tabela)
$SORT_COLS_VALIDAS = ['ns', 'projeto', 'pedido', 'estacao', 'status', 'inicio', 'fim', 'responsavel'];
$sortCol = (string) ($_GET['sort'] ?? 'inicio');
if (!in_array($sortCol, $SORT_COLS_VALIDAS, true)) $sortCol = 'inicio';
$sortDir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

// Paginação
$PORPAGINA_OPCOES = [10, 25, 50, 100];
$porPagina = (int) ($_GET['porPagina'] ?? 25);
if (!in_array($porPagina, $PORPAGINA_OPCOES, true)) $porPagina = 25;
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));

$where  = ['pe.deleted_at IS NULL'];
$params = [];

if ($fEstacao !== '') { $where[] = 'pe.estacao = ?'; $params[] = $fEstacao; }
if ($fMes !== '')     { $where[] = "DATE_FORMAT(pe.data_inicio, '%Y-%m') = ?"; $params[] = $fMes; }

if ($fBusca !== '') {
    $where[] = '(pe.ns_transformador LIKE ? OR pr.codigo LIKE ? OR ped.numero LIKE ? OR u.nome LIKE ?)';
    $like = '%' . $fBusca . '%';
    array_push($params, $like, $like, $like, $like);
}

if ($fStatus) {
    $where[] = 'pe.status IN (' . implode(',', array_fill(0, count($fStatus), '?')) . ')';
    array_push($params, ...$fStatus);
} else {
    $where[] = '1=0';
}

$whereSql = implode(' AND ', $where);

$sql = "
    SELECT pe.*,
           pr.codigo AS projeto_codigo, pr.descricao AS projeto_descricao,
           ped.numero AS pedido_numero,
           u.nome AS responsavel_nome
    FROM producao_etapas pe
    JOIN projetos pr      ON pr.id  = pe.id_projeto
    JOIN pedidos ped      ON ped.id = pr.id_pedido
    LEFT JOIN usuarios u  ON u.id   = pe.id_responsavel
    WHERE $whereSql
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$registros = $stmt->fetchAll();

// ─── Duração calculada em PHP (não é coluna do banco) ──────────────────────────
$agora = new DateTime('now');
foreach ($registros as &$r) {
    $ini = $r['data_inicio'] ? new DateTime($r['data_inicio']) : null;
    $fim = $r['data_fim'] ? new DateTime($r['data_fim']) : ($r['status'] === 'em_andamento' ? $agora : null);
    $r['_duracaoS'] = ($ini && $fim) ? max(0, $fim->getTimestamp() - $ini->getTimestamp()) : null;
}
unset($r);

/** Valor de uma linha usado para ordenar, conforme a coluna clicada. */
function lstSortValue(array $r, string $col): string|int
{
    return match ($col) {
        'ns'          => (string) ($r['ns_transformador'] ?? ''),
        'projeto'     => (string) ($r['projeto_codigo'] ?? ''),
        'pedido'      => (string) ($r['pedido_numero'] ?? ''),
        'estacao'     => (string) ($r['estacao'] ?? ''),
        'status'      => (string) ($r['status'] ?? ''),
        'inicio'      => (string) ($r['data_inicio'] ?? ''),
        'fim'         => (string) ($r['data_fim'] ?? ''),
        'responsavel' => (string) ($r['responsavel_nome'] ?? ''),
        default       => '',
    };
}

usort($registros, function (array $a, array $b) use ($sortCol, $sortDir): int {
    $va = lstSortValue($a, $sortCol);
    $vb = lstSortValue($b, $sortCol);
    $cmp = strcasecmp((string) $va, (string) $vb);
    if ($sortDir === 'desc') $cmp = -$cmp;
    return $cmp !== 0 ? $cmp : ($b['id'] <=> $a['id']);
});

// ─── Paginação (sobre a lista já ordenada) ─────────────────────────────────────
$totalRegistros = count($registros);
$totalPaginas   = max(1, (int) ceil($totalRegistros / $porPagina));
if ($pagina > $totalPaginas) $pagina = $totalPaginas;
$offset          = ($pagina - 1) * $porPagina;
$registrosPagina = array_slice($registros, $offset, $porPagina);

$mesesDisponiveis = $pdo->query("
    SELECT DISTINCT DATE_FORMAT(data_inicio, '%Y-%m') AS ym
    FROM producao_etapas
    WHERE deleted_at IS NULL
    ORDER BY ym DESC
")->fetchAll(PDO::FETCH_COLUMN);

$MESES_PT = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho',
             7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];

// ─── Helpers de exibição ──────────────────────────────────────────────────────
$estacaoMap = [
    'IQF' => ['label' => 'IQF', 'title' => 'Inspeção final', 'bg' => '#eff6ff', 'fg' => '#2563eb'],
    'LAB' => ['label' => 'LAB', 'title' => 'Laboratório',    'bg' => '#f5f3ff', 'fg' => '#7c3aed'],
    'GER' => ['label' => 'GER', 'title' => 'Geral',          'bg' => '#f0fdf4', 'fg' => '#16a34a'],
];
$statusMap = [
    'em_andamento' => ['label' => 'Não iniciado', 'bg' => '#fffbeb', 'fg' => '#d97706'],
    'finalizado'   => ['label' => 'Finalizado',    'bg' => '#ecfdf5', 'fg' => '#16a34a'],
];

// ─── Reprovas: lista de apoio para o popup de Reprovação (mesmas usadas no Retrabalho) ──
$reprovas = $pdo->query("SELECT id, codigo, familia, descricao, local FROM reprovas WHERE ativo = 1 ORDER BY ordem, codigo")->fetchAll();

function lstFmtDataHora(?string $iso): string
{
    if (!$iso) return '—';
    $ts = strtotime($iso);
    return $ts ? date('d/m/y H:i', $ts) : '—';
}

function lstFmtDuracao(?int $segundos): string
{
    if ($segundos === null) return '—';
    $h = intdiv($segundos, 3600);
    $m = intdiv($segundos % 3600, 60);
    if ($h > 0) return "{$h}h {$m}min";
    return "{$m}min";
}

/** Monta uma URL desta página preservando os filtros atuais, com overrides pontuais. */
function lstUrl(array $overrides = []): string
{
    global $base;
    $params = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($params[$k]);
        else $params[$k] = $v;
    }
    $qs = http_build_query($params);
    return htmlspecialchars($base . '/pages/producao/lista.php' . ($qs !== '' ? '?' . $qs : ''));
}

/** Renderiza um <th> clicável que ordena pela coluna, com setinha indicando a direção ativa. */
function lstSortTh(string $label, string $key): void
{
    global $sortCol, $sortDir;
    $ativo = $sortCol === $key;
    $prox  = ($ativo && $sortDir === 'desc') ? 'asc' : 'desc';
    $seta  = $ativo ? ($sortDir === 'desc' ? ' &#9660;' : ' &#9650;') : '';
    echo '<th><a class="lst-th-link' . ($ativo ? ' active' : '') . '" href="'
       . lstUrl(['sort' => $key, 'dir' => $prox, 'pagina' => 1]) . '">'
       . htmlspecialchars($label) . $seta . '</a></th>';
}

$temFiltroAtivo = $fBusca !== '' || $fEstacao !== '' || $fMes !== '';

$pageTitle = 'Lista de Registros';
require_once __DIR__ . '/../../includes/layout.php';
layoutHeader($pageTitle);
?>

<style>
    .lst-tabs { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:18px; }
    .lst-tab {
        display:inline-flex; align-items:center; padding:8px 16px; border-radius:9999px;
        font-size:13px; font-weight:600; text-decoration:none; border:1px solid var(--color-border,#e5e7eb);
        background:#fff; color:var(--color-text-secondary,#5a6480);
    }
    .lst-tab:hover { text-decoration:none; border-color:#E89B1C; color:#E89B1C; }
    .lst-tab.active { background:#E89B1C; border-color:#E89B1C; color:#0e2c1d; }
    .lst-card { background:var(--color-surface,#fff); border:1px solid var(--color-border,#e5e7eb); border-radius:var(--radius-lg,10px); padding:16px 18px; }
    .lst-card h3 { font-size:14px; font-weight:600; margin-bottom:4px; color:var(--color-text-primary,#111827); display:flex; align-items:center; gap:8px; }
    .lst-card h3 svg { width:16px; height:16px; color:#E89B1C; }
    .lst-filtros { display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between; margin:14px 0; }
    .lst-filtros-left, .lst-filtros-right { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
    .lst-filtros select, .lst-filtros input[type=search] { padding:8px 10px; border:1px solid var(--color-border,#d1d5db); border-radius:8px; font-size:13px; background:#fff; }
    .lst-check-row { display:flex; align-items:center; gap:12px; flex-wrap:wrap; font-size:12px; color:var(--color-text-secondary,#5a6480); }
    .lst-check-row label { display:flex; align-items:center; gap:5px; cursor:pointer; white-space:nowrap; }
    .lst-check-row .lbl { font-weight:600; color:var(--color-text-primary,#1a2133); }
    .lst-table { width:100%; border-collapse:collapse; font-size:13px; }
    .lst-table th { text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.6px; color:var(--color-text-muted,#6b7280); padding:8px 10px; border-bottom:1px solid var(--color-border,#e5e7eb); white-space:nowrap; }
    .lst-th-link { color:inherit; text-decoration:none; }
    .lst-th-link:hover { color:#E89B1C; text-decoration:none; }
    .lst-th-link.active { color:#1a3d2a; font-weight:700; }
    .lst-table td { padding:9px 10px; border-bottom:1px solid var(--color-border,#f1f5f9); vertical-align:middle; }
    .lst-table tr:hover td { background:var(--color-surface-2,#f9fafb); }
    .lst-code { font-family:'JetBrains Mono',monospace; font-weight:600; }
    .lst-badge { display:inline-block; padding:2px 9px; border-radius:9999px; font-size:11px; font-weight:600; white-space:nowrap; }
    .lst-btn-secondary { background:#fff; border:1px solid #d1d5db; border-radius:8px; padding:9px 16px; font-size:13px; font-weight:600; cursor:pointer; text-decoration:none; color:inherit; display:inline-flex; align-items:center; }
    .lst-btn-secondary:hover { background:#f9fafb; text-decoration:none; }
    .lst-empty { text-align:center; padding:36px 16px; color:var(--color-text-muted,#6b7280); font-size:13px; }
    .lst-pager { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px; margin-top:16px; padding-top:14px; border-top:1px solid var(--color-border,#e5e7eb); font-size:12px; color:var(--color-text-secondary,#5a6480); }
    .lst-pager-left { display:flex; align-items:center; gap:8px; }
    .lst-pager-left select { padding:5px 8px; border:1px solid var(--color-border,#d1d5db); border-radius:6px; font-size:12px; }
    .lst-btn-danger { background:#dc2626; border:1px solid #dc2626; color:#fff; border-radius:8px; padding:6px 14px; font-size:12px; font-weight:600; cursor:pointer; white-space:nowrap; }
    .lst-btn-danger:hover { background:#b91c1c; border-color:#b91c1c; }
    .lst-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
    .lst-form-grid .full { grid-column:1 / -1; }
    .lst-section { grid-column:1 / -1; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.7px; color:#1a3d2a; margin:4px 0 -4px; display:flex; align-items:center; gap:10px; }
    .lst-section::after { content:""; flex:1; height:1px; background:#e5e7eb; }
    .lst-field label { display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:4px; }
    .lst-field input, .lst-field select { width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:8px; font-size:13px; }
    .lst-field.auto input { background:#f3f6f4; color:#374151; border-style:dashed; }
    .lst-field.locked input { background:#f3f6f4; color:#374151; }
    #rep-reprovas-list { display:flex; flex-direction:column; gap:14px; }
    .lst-reprova-block { border:1px solid #e5e7eb; border-radius:10px; padding:14px; }
    .lst-reprova-head { display:flex; gap:10px; align-items:flex-end; }
    .lst-reprova-select-wrap { flex:1; }
    .lst-reprova-select-wrap label { display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:4px; }
    .lst-reprova-select-wrap select { width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:8px; font-size:13px; }
    .lst-reprova-remove {
        background:#fff; border:1px solid #d1d5db; border-radius:8px; width:36px; height:36px; flex-shrink:0;
        cursor:pointer; color:#6b7280; font-size:18px; line-height:1; display:flex; align-items:center; justify-content:center;
    }
    .lst-reprova-remove:hover { border-color:#dc2626; color:#dc2626; }
</style>

<!-- Cabeçalho -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px;">
    <div>
        <a href="<?= htmlspecialchars($base) ?>/pages/producao/index.php" style="font-size:12px;color:var(--color-text-muted,#9aa3b8);text-decoration:none;">&larr; Registro</a>
        <h1 style="font-size:var(--font-size-xl,20px);font-weight:700;margin-top:2px;">Lista de Registros</h1>
        <p class="text-secondary" style="font-size:13px;color:var(--color-text-secondary,#6b7280);margin-top:2px;">
            Transformadores lidos e registrados no chão de fábrica, com filtros e paginação
        </p>
    </div>
</div>

<!-- Abas por estação -->
<div class="lst-tabs">
    <a class="lst-tab<?= $fEstacao === '' ? ' active' : '' ?>" href="<?= lstUrl(['estacao' => null, 'pagina' => 1]) ?>">Todas</a>
    <?php foreach ($estacaoMap as $k => $info): ?>
        <a class="lst-tab<?= $fEstacao === $k ? ' active' : '' ?>" href="<?= lstUrl(['estacao' => $k, 'pagina' => 1]) ?>"><?= htmlspecialchars($info['label']) ?> — <?= htmlspecialchars($info['title']) ?></a>
    <?php endforeach; ?>
</div>

<div class="lst-card">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <h3>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
            Registros de Produção
        </h3>
        <span id="lst-autorefresh-indicador" style="font-size:11px;color:var(--color-text-muted,#9aa3b8);white-space:nowrap;"></span>
    </div>

    <!-- Filtros -->
    <form method="GET" class="lst-filtros" id="lst-filtros">
        <input type="hidden" name="estacao" value="<?= htmlspecialchars($fEstacao) ?>">

        <div class="lst-filtros-left">
            <input type="search" name="busca" value="<?= htmlspecialchars($fBusca) ?>" placeholder="Buscar N° de série, projeto, pedido, responsável…" style="min-width:280px;">
            <select name="mes" onchange="this.form.submit()">
                <option value="">Mês…</option>
                <?php foreach ($mesesDisponiveis as $ym): if (!$ym) continue;
                    $lbl = ($MESES_PT[(int) substr($ym, 5, 2)] ?? $ym) . '/' . substr($ym, 0, 4);
                ?>
                    <option value="<?= htmlspecialchars($ym) ?>" <?= $fMes === $ym ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="lst-btn-secondary">Filtrar</button>
            <?php if ($temFiltroAtivo): ?>
                <a href="<?= htmlspecialchars($base) ?>/pages/producao/lista.php" class="lst-btn-secondary">Limpar</a>
            <?php endif; ?>
        </div>
    </form>

    <div style="overflow-x:auto;" id="lst-table-wrap">
        <table class="lst-table">
            <thead>
                <tr>
                    <?php
                    lstSortTh('N° Série', 'ns');
                    lstSortTh('Projeto', 'projeto');
                    lstSortTh('Pedido', 'pedido');
                    lstSortTh('Estação', 'estacao');
                    lstSortTh('Status', 'status');
                    echo '<th></th>';
                    ?>
                </tr>
            </thead>
            <tbody>
                <?php if (!$registrosPagina): ?>
                    <tr><td colspan="6"><div class="lst-empty">Nenhum registro encontrado para os filtros selecionados.</div></td></tr>
                <?php else: foreach ($registrosPagina as $r):
                    $es = $estacaoMap[$r['estacao']] ?? null;
                    $st = $statusMap[$r['status']] ?? $statusMap['em_andamento'];
                ?>
                    <tr>
                        <td><span class="lst-code"><?= htmlspecialchars((string) $r['ns_transformador']) ?></span></td>
                        <td>
                            <span class="lst-code"><?= htmlspecialchars($r['projeto_codigo'] ?? '—') ?></span>
                            <?php if (!empty($r['projeto_descricao'])): ?>
                                <div style="font-size:11px;color:#6b7280;"><?= htmlspecialchars($r['projeto_descricao']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($r['pedido_numero'] ?? '—') ?></td>
                        <td>
                            <?php if ($es): ?>
                                <span class="lst-badge" style="background:<?= $es['bg'] ?>;color:<?= $es['fg'] ?>;" title="<?= htmlspecialchars($es['title']) ?>"><?= $es['label'] ?></span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><span class="lst-badge" style="background:<?= $st['bg'] ?>;color:<?= $st['fg'] ?>;"><?= $st['label'] ?></span></td>
                        <td>
                            <button type="button" class="lst-btn-danger js-reprovar"
                                    data-id_projeto="<?= (int) $r['id_projeto'] ?>"
                                    data-ns_transformador="<?= htmlspecialchars((string) $r['ns_transformador']) ?>"
                                    data-projeto_codigo="<?= htmlspecialchars((string) ($r['projeto_codigo'] ?? '')) ?>"
                                    data-projeto_descricao="<?= htmlspecialchars((string) ($r['projeto_descricao'] ?? '')) ?>"
                                    data-pedido_numero="<?= htmlspecialchars((string) ($r['pedido_numero'] ?? '')) ?>">
                                Reprovar
                            </button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Paginação -->
    <div class="lst-pager">
        <div class="lst-pager-left">
            <span>Exibir</span>
            <select onchange="location.href=this.value">
                <?php foreach ($PORPAGINA_OPCOES as $opt): ?>
                    <option value="<?= lstUrl(['porPagina' => $opt, 'pagina' => 1]) ?>" <?= $porPagina === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                <?php endforeach; ?>
            </select>
            <span>por página · <?= $totalRegistros ?> registro<?= $totalRegistros === 1 ? '' : 's' ?></span>
        </div>
        <div class="pagination">
            <a class="page-btn<?= $pagina <= 1 ? ' disabled' : '' ?>" href="<?= $pagina > 1 ? lstUrl(['pagina' => 1]) : '#' ?>" style="text-decoration:none;<?= $pagina <= 1 ? 'opacity:.4;pointer-events:none;' : '' ?>">&laquo;</a>
            <a class="page-btn<?= $pagina <= 1 ? ' disabled' : '' ?>" href="<?= $pagina > 1 ? lstUrl(['pagina' => $pagina - 1]) : '#' ?>" style="text-decoration:none;<?= $pagina <= 1 ? 'opacity:.4;pointer-events:none;' : '' ?>">&lsaquo;</a>
            <?php
            $janela = 2;
            $ini = max(1, $pagina - $janela);
            $fim = min($totalPaginas, $pagina + $janela);
            for ($p = $ini; $p <= $fim; $p++):
            ?>
                <a class="page-btn<?= $p === $pagina ? ' active' : '' ?>" href="<?= lstUrl(['pagina' => $p]) ?>" style="text-decoration:none;"><?= $p ?></a>
            <?php endfor; ?>
            <a class="page-btn<?= $pagina >= $totalPaginas ? ' disabled' : '' ?>" href="<?= $pagina < $totalPaginas ? lstUrl(['pagina' => $pagina + 1]) : '#' ?>" style="text-decoration:none;<?= $pagina >= $totalPaginas ? 'opacity:.4;pointer-events:none;' : '' ?>">&rsaquo;</a>
            <a class="page-btn<?= $pagina >= $totalPaginas ? ' disabled' : '' ?>" href="<?= $pagina < $totalPaginas ? lstUrl(['pagina' => $totalPaginas]) : '#' ?>" style="text-decoration:none;<?= $pagina >= $totalPaginas ? 'opacity:.4;pointer-events:none;' : '' ?>">&raquo;</a>
        </div>
    </div>
</div>

<!-- Modal: Reprovação -->
<div class="modal-overlay" id="rep-modal" style="display:none;">
    <div class="modal">
        <form id="rep-form">
            <div class="modal-header">
                <span class="modal-title">Reprovação</span>
                <button type="button" class="modal-close" id="rep-modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <div id="rep-form-erro" style="display:none;background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:8px;padding:8px 12px;font-size:13px;margin-bottom:14px;"></div>
                <div class="lst-form-grid">

                    <div class="lst-section">Projeto &amp; transformador</div>
                    <input type="hidden" id="rep-id_projeto">
                    <input type="hidden" id="rep-ns">
                    <div class="lst-field locked">
                        <label for="rep-pedido-display">Pedido</label>
                        <input type="text" id="rep-pedido-display" readonly>
                    </div>
                    <div class="lst-field locked">
                        <label for="rep-projeto-display">Projeto</label>
                        <input type="text" id="rep-projeto-display" readonly>
                    </div>
                    <div class="lst-field locked full">
                        <label for="rep-projeto-descricao-display">Descrição do projeto</label>
                        <input type="text" id="rep-projeto-descricao-display" readonly>
                    </div>
                    <div class="lst-field locked full">
                        <label for="rep-ns-display">N° de série do transformador</label>
                        <input type="text" id="rep-ns-display" readonly>
                    </div>

                    <div class="lst-section">Reprova / contenção</div>
                    <div id="rep-reprovas-list" class="full"></div>
                    <button type="button" class="lst-btn-secondary full" id="rep-add-reprova" style="align-self:flex-start;width:fit-content;">+ Adicionar outra reprova</button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="lst-btn-secondary" id="rep-modal-cancel">Cancelar</button>
                <button type="submit" class="lst-btn-secondary" id="rep-form-submit" style="background:#dc2626;color:#fff;border-color:#dc2626;">Reprovar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modelo de 1 bloco de reprova — clonado via JS a cada "+ Adicionar outra reprova" -->
<template id="rep-reprova-template">
    <div class="lst-reprova-block">
        <div class="lst-reprova-head">
            <div class="lst-reprova-select-wrap">
                <label>Código da Reprovação *</label>
                <select class="rep-reprova-select" required>
                    <option value="">Selecione…</option>
                    <?php foreach ($reprovas as $rp): ?>
                        <option value="<?= (int) $rp['id'] ?>"><?= htmlspecialchars($rp['codigo'] . ' — ' . $rp['descricao']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="button" class="lst-reprova-remove" title="Remover esta reprova">&times;</button>
        </div>
        <div class="lst-form-grid" style="margin-top:10px;">
            <div class="lst-field full auto">
                <label>Descrição da contenção</label>
                <input type="text" class="rep-descricao-field" placeholder="— selecione o código —" readonly>
            </div>
            <div class="lst-field auto">
                <label>Família da contenção</label>
                <input type="text" class="rep-familia-field" placeholder="—" readonly>
            </div>
            <div class="lst-field auto">
                <label>Local</label>
                <input type="text" class="rep-local-field" placeholder="—" readonly>
            </div>
        </div>
    </div>
</template>

<script>
    window.LISTA_API = <?= json_encode($base . '/api/retrabalho-acao.php', JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    window.LISTA_PRODUCAO_API = <?= json_encode($base . '/api/producao-acao.php', JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    window.LISTA_REPROVAS = <?= json_encode($reprovas, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php $lstJsVer = @filemtime(__DIR__ . '/../../assets/js/producao-lista.js') ?: (defined('APP_VERSION') ? APP_VERSION : '1'); ?>
<script src="<?= htmlspecialchars($base) ?>/assets/js/producao-lista.js?v=<?= htmlspecialchars((string) $lstJsVer) ?>"></script>

<?php layoutFooter(); ?>
