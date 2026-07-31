<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();

$pdo  = getDB();
$base = defined('APP_URL') ? APP_URL : '';

// Relação do Histórico: retrabalhos encerrados — "aprovado" (retorno ao
// Laboratório aprovado na reinspeção, ver pages/producao/retornos.php) e
// "finalizado" (causa raiz documentada via Relação de Retrabalhos). Nunca
// mostra os em aberto (agu_abertura/agu_causa_raiz) — esses ficam só na
// Relação de Retrabalhos.
$STATUS_HISTORICO = ['aprovado', 'finalizado'];

// ─── Filtros (GET) ──────────────────────────────────────────────────────────
$fBusca  = trim((string) ($_GET['busca'] ?? ''));
$fStatus = trim((string) ($_GET['status'] ?? ''));
$fMes    = trim((string) ($_GET['mes'] ?? ''));

if (!in_array($fStatus, $STATUS_HISTORICO, true)) $fStatus = '';
if (!preg_match('/^\d{4}-\d{2}$/', $fMes))        $fMes = '';

$SORT_COLS_VALIDAS = ['pedido', 'projeto', 'descricao', 'potencia', 'classe', 'registros'];
$sortCol = (string) ($_GET['sort'] ?? '');
if ($sortCol !== '' && !in_array($sortCol, $SORT_COLS_VALIDAS, true)) $sortCol = '';
$sortDir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

$PORPAGINA_OPCOES = [10, 25, 50, 100];
$porPagina = (int) ($_GET['porPagina'] ?? 10);
if (!in_array($porPagina, $PORPAGINA_OPCOES, true)) $porPagina = 10;
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));

$dataExpr = 'DATE(COALESCE(r.concluido_em, r.data_reprova, r.created_at))';

$where  = ['r.deleted_at IS NULL', "r.status IN ('" . implode("','", $STATUS_HISTORICO) . "')"];
$params = [];

if ($fStatus !== '') { $where[] = 'r.status = ?'; $params[] = $fStatus; }
if ($fMes !== '')    { $where[] = "DATE_FORMAT($dataExpr, '%Y-%m') = ?"; $params[] = $fMes; }

if ($fBusca !== '') {
    $where[] = '(pr.codigo LIKE ? OR ped.numero LIKE ? OR r.ns_transformador LIKE ? OR rep.codigo LIKE ? OR rep.descricao LIKE ?)';
    $like = '%' . $fBusca . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

$whereSql = implode(' AND ', $where);

$sql = "
    SELECT r.*,
           u.nome    AS responsavel_nome,
           pr.codigo AS projeto_codigo, pr.descricao AS projeto_descricao, pr.id_pedido AS id_pedido,
           ped.numero AS pedido_numero,
           rep.codigo AS reprova_codigo, rep.familia AS reprova_familia,
           rep.descricao AS reprova_descricao, rep.local AS reprova_local
    FROM retrabalhos r
    LEFT JOIN usuarios u   ON u.id   = r.id_responsavel
    LEFT JOIN projetos pr  ON pr.id  = r.id_projeto
    LEFT JOIN pedidos ped  ON ped.id = pr.id_pedido
    LEFT JOIN reprovas rep ON rep.id = r.id_reprova
    WHERE $whereSql
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$registros = $stmt->fetchAll();

// ─── Materiais utilizados por lote — mesmo agrupamento da Triagem (id_lote,
// ver retrabalho_material_uso / api/retrabalho-acao.php::gravarMateriaisUsados) ─
$idLotes = array_values(array_unique(array_map(
    fn ($r) => (int) ($r['id_lote'] ?: $r['id']),
    $registros
)));
$materiaisPorLote = [];
if ($idLotes) {
    $ph = implode(',', array_fill(0, count($idLotes), '?'));
    $stmtMat = $pdo->prepare("
        SELECT mu.id_lote, mu.quantidade, mu.material_outro,
               mc.descricao AS material_descricao, mc.unidade AS material_unidade
        FROM retrabalho_material_uso mu
        LEFT JOIN retrabalho_materiais_catalogo mc ON mc.id = mu.id_material
        WHERE mu.id_lote IN ($ph)
        ORDER BY mc.ordem, mu.id
    ");
    $stmtMat->execute($idLotes);
    foreach ($stmtMat->fetchAll() as $m) {
        $materiaisPorLote[(int) $m['id_lote']][] = $m;
    }
}
foreach ($registros as &$r) {
    $idLoteR = (int) ($r['id_lote'] ?: $r['id']);
    $r['_materiais'] = $materiaisPorLote[$idLoteR] ?? [];
}
unset($r);

// ─── Agrupamento por projeto — mesmo padrão da Relação de Retrabalhos ──────────
$grupos = [];
foreach ($registros as $r) {
    $gid = (int) ($r['id_projeto'] ?? 0);
    if (!isset($grupos[$gid])) {
        [$potencia, $classe] = parsePotenciaClasse($r['projeto_descricao'] ?? null);
        $grupos[$gid] = [
            'id_projeto'        => $gid,
            'projeto_codigo'    => $r['projeto_codigo'],
            'projeto_descricao' => $r['projeto_descricao'],
            'pedido_numero'     => $r['pedido_numero'],
            'potencia'          => $potencia,
            'classe'            => $classe,
            'itens'             => [],
            '_maxId'            => 0,
        ];
    }
    $g = &$grupos[$gid];
    $g['itens'][] = $r;
    $g['_maxId']  = max($g['_maxId'], (int) $r['id']);
    unset($g);
}
foreach ($grupos as &$g) {
    usort($g['itens'], function (array $a, array $b): int {
        $cmp = strcmp((string) ($b['concluido_em'] ?? $b['data_reprova'] ?? ''), (string) ($a['concluido_em'] ?? $a['data_reprova'] ?? ''));
        return $cmp !== 0 ? $cmp : ($b['id'] <=> $a['id']);
    });
    $g['qtdRegistros'] = count($g['itens']);
}
unset($g);
$grupos = array_values($grupos);

function histGroupSortValue(array $g, string $col): string|int
{
    return match ($col) {
        'pedido'    => (string) ($g['pedido_numero'] ?? ''),
        'projeto'   => (string) ($g['projeto_codigo'] ?? ''),
        'descricao' => (string) ($g['projeto_descricao'] ?? ''),
        'potencia'  => $g['potencia'] !== null ? (int) round((float) $g['potencia']) : -1,
        'classe'    => $g['classe']   !== null ? (int) round((float) $g['classe'])   : -1,
        'registros' => $g['qtdRegistros'],
        default     => $g['_maxId'],
    };
}

usort($grupos, function (array $a, array $b) use ($sortCol, $sortDir): int {
    $va = histGroupSortValue($a, $sortCol);
    $vb = histGroupSortValue($b, $sortCol);
    $cmp = is_int($va) ? ($va <=> $vb) : strcasecmp((string) $va, (string) $vb);
    if ($sortDir === 'desc') $cmp = -$cmp;
    return $cmp !== 0 ? $cmp : ($b['_maxId'] <=> $a['_maxId']);
});

$totalRegistros = count($registros);
$totalGrupos    = count($grupos);
$totalPaginas   = max(1, (int) ceil($totalGrupos / $porPagina));
if ($pagina > $totalPaginas) $pagina = $totalPaginas;
$offset       = ($pagina - 1) * $porPagina;
$gruposPagina = array_slice($grupos, $offset, $porPagina);

$mesesDisponiveis = $pdo->query("
    SELECT DISTINCT DATE_FORMAT(COALESCE(concluido_em, data_reprova, created_at), '%Y-%m') AS ym
    FROM retrabalhos
    WHERE deleted_at IS NULL AND status IN ('aprovado','finalizado')
    ORDER BY ym DESC
")->fetchAll(PDO::FETCH_COLUMN);

$MESES_PT = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho',
             7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];

$statusMap = [
    'aprovado'   => ['label' => 'Aprovado',   'bg' => '#eff6ff', 'fg' => '#2563eb'],
    'finalizado' => ['label' => 'Finalizado', 'bg' => '#ecfdf5', 'fg' => '#16a34a'],
];
$localMap = [
    'IQF' => ['label' => 'IQF', 'title' => 'Inspeção final', 'bg' => '#eff6ff', 'fg' => '#2563eb'],
    'LAB' => ['label' => 'LAB', 'title' => 'Laboratório',    'bg' => '#f5f3ff', 'fg' => '#7c3aed'],
    'GER' => ['label' => 'GER', 'title' => 'Geral',          'bg' => '#f0fdf4', 'fg' => '#16a34a'],
];
$setoresLabel = retrabalhoSetoresTriagem();
$unidadeLabel = ['KG' => 'kg', 'L' => 'L', 'UND' => 'und'];

function histFmtData(?string $iso): string
{
    if (!$iso) return '—';
    $ts = strtotime($iso);
    return $ts ? date('d/m/y', $ts) : '—';
}

function histFmtDataHora(?string $iso): string
{
    if (!$iso) return '—';
    $ts = strtotime($iso);
    return $ts ? date('d/m/y H:i', $ts) : '—';
}

/** Monta o payload (JSON) com tudo que foi registrado na Triagem desta reprova, pro popup "Ver detalhes". */
function histMontarDetalhe(array $r, array $localMap, array $setoresLabel, array $unidadeLabel): array
{
    $lo = $localMap[$r['reprova_local']] ?? null;
    $setores = $r['setores_destino'] !== null ? explode(',', $r['setores_destino']) : [];

    $materiais = array_map(function (array $m) use ($unidadeLabel) {
        $desc = $m['material_descricao'] ?? $m['material_outro'] ?? 'Outros';
        $unid = $m['material_descricao'] !== null ? ($unidadeLabel[$m['material_unidade']] ?? '') : '';
        return [
            'descricao'  => $desc,
            'quantidade' => rtrim(rtrim(number_format((float) $m['quantidade'], 2, ',', '.'), '0'), ','),
            'unidade'    => $unid,
        ];
    }, $r['_materiais']);

    return [
        'ns'              => $r['ns_transformador'] ?? '—',
        'reprova_codigo'  => $r['reprova_codigo'] ?? '—',
        'reprova_familia' => $r['reprova_familia'] ?? '—',
        'reprova_desc'    => $r['reprova_descricao'] ?? '—',
        'reprova_local'   => $lo ? $lo['label'] . ' — ' . $lo['title'] : '—',
        'status'          => $r['status'],
        'responsavel'     => $r['responsavel_nome'] ?? '—',
        'data_reprova'    => histFmtData($r['data_reprova']),
        'data_chegada'    => histFmtData($r['data_chegada']),
        'data_inicio'     => histFmtDataHora($r['data_inicio']),
        'data_finalizacao'=> histFmtData($r['data_finalizacao']),
        'concluido_em'    => histFmtDataHora($r['concluido_em']),
        'causa_reprova'   => $r['causa_reprova'] ?: '—',
        'causa_raiz'      => $r['causa_raiz'] ?: '—',
        'observacoes'     => $r['observacoes'] ?: '—',
        'setores'         => $setores ? implode(', ', array_map(fn ($s) => $setoresLabel[$s] ?? $s, $setores)) : '—',
        'materiais'       => $materiais,
    ];
}

function histUrl(array $overrides = []): string
{
    global $base;
    $params = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($params[$k]);
        else $params[$k] = $v;
    }
    $qs = http_build_query($params);
    return htmlspecialchars($base . '/pages/retrabalho/historico.php' . ($qs !== '' ? '?' . $qs : ''));
}

function histSortTh(string $label, string $key): void
{
    global $sortCol, $sortDir;
    $ativo = $sortCol === $key;
    $prox  = ($ativo && $sortDir === 'desc') ? 'asc' : 'desc';
    $seta  = $ativo ? ($sortDir === 'desc' ? ' &#9660;' : ' &#9650;') : '';
    echo '<th><a class="hist-th-link' . ($ativo ? ' active' : '') . '" href="'
       . histUrl(['sort' => $key, 'dir' => $prox, 'pagina' => 1]) . '">'
       . htmlspecialchars($label) . $seta . '</a></th>';
}

$temFiltroAtivo = $fBusca !== '' || $fStatus !== '' || $fMes !== '';

$pageTitle = 'Relação do Histórico';
require_once __DIR__ . '/../../includes/layout.php';
layoutHeader($pageTitle);
?>

<style>
    .hist-tabs { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:18px; }
    .hist-tab {
        display:inline-flex; align-items:center; padding:8px 16px; border-radius:9999px;
        font-size:13px; font-weight:600; text-decoration:none; border:1px solid var(--color-border,#e5e7eb);
        background:#fff; color:var(--color-text-secondary,#5a6480);
    }
    .hist-tab:hover { text-decoration:none; border-color:#E89B1C; color:#E89B1C; }
    .hist-tab.active { background:#E89B1C; border-color:#E89B1C; color:#0e2c1d; }
    .hist-card { background:var(--color-surface,#fff); border:1px solid var(--color-border,#e5e7eb); border-radius:var(--radius-lg,10px); padding:16px 18px; }
    .hist-card h3 { font-size:14px; font-weight:600; margin-bottom:4px; color:var(--color-text-primary,#111827); display:flex; align-items:center; gap:8px; }
    .hist-card h3 svg { width:16px; height:16px; color:#E89B1C; }
    .hist-filtros { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin:14px 0; }
    .hist-filtros select, .hist-filtros input[type=search] { padding:8px 10px; border:1px solid var(--color-border,#d1d5db); border-radius:8px; font-size:13px; background:#fff; }
    .hist-table { width:100%; border-collapse:collapse; font-size:13px; }
    .hist-table th { text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.6px; color:var(--color-text-muted,#6b7280); padding:8px 10px; border-bottom:1px solid var(--color-border,#e5e7eb); white-space:nowrap; }
    .hist-th-link { color:inherit; text-decoration:none; }
    .hist-th-link:hover { color:#E89B1C; text-decoration:none; }
    .hist-th-link.active { color:#1a3d2a; font-weight:700; }
    .hist-table td { padding:9px 10px; border-bottom:1px solid var(--color-border,#f1f5f9); vertical-align:middle; }
    .hist-table tr:hover td { background:var(--color-surface-2,#f9fafb); }
    .hist-code { font-family:'JetBrains Mono',monospace; font-weight:600; }
    .hist-badge { display:inline-block; padding:2px 9px; border-radius:9999px; font-size:11px; font-weight:600; white-space:nowrap; }
    .hist-btn-secondary { background:#fff; border:1px solid #d1d5db; border-radius:8px; padding:9px 16px; font-size:13px; font-weight:600; cursor:pointer; text-decoration:none; color:inherit; display:inline-flex; align-items:center; }
    .hist-btn-secondary:hover { background:#f9fafb; text-decoration:none; }
    .hist-empty { text-align:center; padding:36px 16px; color:var(--color-text-muted,#6b7280); font-size:13px; }
    .hist-toggle-btn { background:#fff; border:1px solid var(--color-border,#e5e7eb); border-radius:6px; width:24px; height:24px; padding:0; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; font-size:15px; font-weight:700; line-height:1; color:var(--color-text-secondary,#5a6480); }
    .hist-toggle-btn:hover { border-color:#E89B1C; color:#E89B1C; }
    .hist-detail-row { display:none; }
    .hist-detail-row.is-open { display:table-row; }
    .hist-detail-wrap { background:var(--color-surface-2,#f9fafb); border-radius:8px; padding:8px 10px; margin:2px 0; }
    .hist-subtable { width:100%; border-collapse:collapse; font-size:12px; }
    .hist-subtable th { text-align:left; font-size:9px; text-transform:uppercase; letter-spacing:.5px; color:var(--color-text-muted,#6b7280); padding:6px 8px; border-bottom:1px solid var(--color-border,#e5e7eb); white-space:nowrap; }
    .hist-subtable td { padding:7px 8px; border-bottom:1px solid var(--color-border,#eef1f5); vertical-align:middle; }
    .hist-subtable tr:last-child td { border-bottom:none; }
    .hist-pager { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px; margin-top:16px; padding-top:14px; border-top:1px solid var(--color-border,#e5e7eb); font-size:12px; color:var(--color-text-secondary,#5a6480); }
    .hist-pager-left { display:flex; align-items:center; gap:8px; }
    .hist-pager-left select { padding:5px 8px; border:1px solid var(--color-border,#d1d5db); border-radius:6px; font-size:12px; }
    .hist-ver-btn { background:#fff; border:1px solid var(--color-border,#e5e7eb); border-radius:6px; padding:5px 10px; display:inline-flex; align-items:center; gap:5px; cursor:pointer; color:var(--color-text-secondary,#374151); font-size:11px; font-weight:600; white-space:nowrap; }
    .hist-ver-btn:hover { border-color:#E89B1C; color:#E89B1C; }
    .hist-ver-btn svg { width:13px; height:13px; }
    /* Modal: Ver detalhes (registrado na Triagem) */
    .modal-overlay { position:fixed; inset:0; background:rgba(15,23,42,.5); display:none; align-items:flex-start; justify-content:center; z-index:1000; padding:40px 16px; overflow-y:auto; }
    .modal-box { background:#fff; border-radius:14px; width:100%; max-width:640px; box-shadow:0 20px 50px rgba(0,0,0,.3); }
    .modal-head { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #e5e7eb; }
    .modal-head h2 { font-size:16px; font-weight:700; }
    .modal-close { background:none; border:none; font-size:22px; line-height:1; cursor:pointer; color:#9ca3af; }
    .modal-body { padding:18px 20px; }
    .hd-section { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.7px; color:#1a3d2a; margin:16px 0 8px; display:flex; align-items:center; gap:10px; }
    .hd-section:first-child { margin-top:0; }
    .hd-section::after { content:""; flex:1; height:1px; background:#e5e7eb; }
    .hd-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px 16px; }
    .hd-field .lbl { font-size:11px; font-weight:600; color:#6b7280; margin-bottom:2px; }
    .hd-field .val { font-size:13px; color:#1a2133; white-space:pre-wrap; }
    .hd-field.full { grid-column:1 / -1; }
    .hd-materiais { width:100%; border-collapse:collapse; font-size:12px; }
    .hd-materiais th { text-align:left; font-size:9px; text-transform:uppercase; color:#9ca3af; padding:4px 6px; border-bottom:1px solid #e5e7eb; }
    .hd-materiais td { padding:5px 6px; border-bottom:1px solid #f1f5f9; }
    .hd-vazio { color:#9ca3af; font-size:12px; }
</style>

<!-- Cabeçalho -->
<div style="margin-bottom:18px;">
    <a href="<?= htmlspecialchars($base) ?>/pages/retrabalho/relacao.php" style="font-size:12px;color:var(--color-text-muted,#9aa3b8);text-decoration:none;">&larr; Relação de Retrabalhos</a>
    <h1 style="font-size:var(--font-size-xl,20px);font-weight:700;margin-top:2px;">Relação do Histórico</h1>
    <p class="text-secondary" style="font-size:13px;color:var(--color-text-secondary,#6b7280);margin-top:2px;">
        Retrabalhos encerrados — aprovados na reinspeção do Laboratório ou finalizados com causa raiz documentada
    </p>
</div>

<!-- Abas por status -->
<div class="hist-tabs">
    <a class="hist-tab<?= $fStatus === '' ? ' active' : '' ?>" href="<?= histUrl(['status' => null, 'pagina' => 1]) ?>">Todos</a>
    <?php foreach ($statusMap as $k => $info): ?>
        <a class="hist-tab<?= $fStatus === $k ? ' active' : '' ?>" href="<?= histUrl(['status' => $k, 'pagina' => 1]) ?>"><?= htmlspecialchars($info['label']) ?></a>
    <?php endforeach; ?>
</div>

<div class="hist-card">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <h3>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
            Histórico
        </h3>
    </div>

    <!-- Filtros -->
    <form method="GET" class="hist-filtros" id="hist-filtros">
        <input type="hidden" name="status" value="<?= htmlspecialchars($fStatus) ?>">
        <input type="search" name="busca" value="<?= htmlspecialchars($fBusca) ?>" placeholder="Buscar projeto, pedido, NS, reprova…" style="min-width:260px;">
        <select name="mes" onchange="this.form.submit()">
            <option value="">Mês…</option>
            <?php foreach ($mesesDisponiveis as $ym): if (!$ym) continue;
                $lbl = ($MESES_PT[(int) substr($ym, 5, 2)] ?? $ym) . '/' . substr($ym, 0, 4);
            ?>
                <option value="<?= htmlspecialchars($ym) ?>" <?= $fMes === $ym ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="hist-btn-secondary">Filtrar</button>
        <?php if ($temFiltroAtivo): ?>
            <a href="<?= htmlspecialchars($base) ?>/pages/retrabalho/historico.php" class="hist-btn-secondary">Limpar</a>
        <?php endif; ?>
    </form>

    <div style="overflow-x:auto;">
        <table class="hist-table">
            <thead>
                <tr>
                    <th style="width:32px;"></th>
                    <?php
                    histSortTh('Pedido', 'pedido');
                    histSortTh('Projeto', 'projeto');
                    histSortTh('Descrição', 'descricao');
                    histSortTh('Potência', 'potencia');
                    histSortTh('Classe', 'classe');
                    histSortTh('Reprovas', 'registros');
                    ?>
                </tr>
            </thead>
            <tbody>
                <?php if (!$gruposPagina): ?>
                    <tr><td colspan="8"><div class="hist-empty">Nenhum retrabalho encerrado encontrado para os filtros selecionados.</div></td></tr>
                <?php else: foreach ($gruposPagina as $g):
                    $detId = 'hist-det-' . $g['id_projeto'] . '-' . $g['_maxId'];
                ?>
                    <tr>
                        <td>
                            <button type="button" class="hist-toggle-btn js-toggle-hist" data-target="<?= htmlspecialchars($detId) ?>" aria-expanded="false" title="Mostrar números de série">+</button>
                        </td>
                        <td><?= htmlspecialchars($g['pedido_numero'] ?? '—') ?></td>
                        <td><span class="hist-code"><?= htmlspecialchars($g['projeto_codigo'] ?? '—') ?></span></td>
                        <td><?= htmlspecialchars($g['projeto_descricao'] ?? '—') ?></td>
                        <td><?= $g['potencia'] !== null ? htmlspecialchars($g['potencia']) . ' kVA' : '—' ?></td>
                        <td><?= $g['classe'] !== null ? htmlspecialchars($g['classe']) . ' kV' : '—' ?></td>
                        <td style="text-align:center;">
                            <span class="hist-badge" style="background:#eef2ff;color:#4338ca;"><?= (int) $g['qtdRegistros'] ?></span>
                        </td>
                    </tr>
                    <tr class="hist-detail-row" id="<?= htmlspecialchars($detId) ?>">
                        <td colspan="8">
                            <div class="hist-detail-wrap">
                                <div style="overflow-x:auto;">
                                <table class="hist-subtable">
                                    <thead>
                                        <tr>
                                            <th>N° Série</th><th>Contenção</th><th>Família</th><th>Causa da Reprova</th>
                                            <th>Causa Raiz</th><th>Status</th><th>Concluído em</th><th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($g['itens'] as $r):
                                            $st = $statusMap[$r['status']] ?? $statusMap['finalizado'];
                                        ?>
                                            <tr>
                                                <td><span class="hist-code"><?= htmlspecialchars($r['ns_transformador'] ?? '—') ?></span></td>
                                                <td>
                                                    <span class="hist-code" style="font-weight:500;"><?= htmlspecialchars($r['reprova_codigo'] ?? '—') ?></span>
                                                    <?php if (!empty($r['reprova_descricao'])): ?>
                                                        <div style="font-size:11px;color:#6b7280;"><?= htmlspecialchars($r['reprova_descricao']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="font-size:12px;"><?= htmlspecialchars($r['reprova_familia'] ?? '—') ?></td>
                                                <td style="font-size:12px;"><?= htmlspecialchars($r['causa_reprova'] ?? '—') ?></td>
                                                <td style="font-size:12px;"><?= htmlspecialchars($r['causa_raiz'] ?? '—') ?></td>
                                                <td><span class="hist-badge" style="background:<?= $st['bg'] ?>;color:<?= $st['fg'] ?>;"><?= $st['label'] ?></span></td>
                                                <td style="font-size:12px;"><?= htmlspecialchars(histFmtData($r['concluido_em'])) ?></td>
                                                <td>
                                                    <button type="button" class="hist-ver-btn js-ver-detalhe"
                                                            data-detalhe='<?= htmlspecialchars(json_encode(histMontarDetalhe($r, $localMap, $setoresLabel, $unidadeLabel), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'
                                                            title="Ver tudo o que foi registrado na Triagem">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                                        Ver detalhes
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Paginação -->
    <div class="hist-pager">
        <div class="hist-pager-left">
            <span>Exibir</span>
            <select onchange="location.href=this.value">
                <?php foreach ($PORPAGINA_OPCOES as $opt): ?>
                    <option value="<?= histUrl(['porPagina' => $opt, 'pagina' => 1]) ?>" <?= $porPagina === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                <?php endforeach; ?>
            </select>
            <span>por página · <?= $totalGrupos ?> projeto<?= $totalGrupos === 1 ? '' : 's' ?> · <?= $totalRegistros ?> registro<?= $totalRegistros === 1 ? '' : 's' ?></span>
        </div>
        <div class="pagination">
            <a class="page-btn<?= $pagina <= 1 ? ' disabled' : '' ?>" href="<?= $pagina > 1 ? histUrl(['pagina' => 1]) : '#' ?>" style="text-decoration:none;<?= $pagina <= 1 ? 'opacity:.4;pointer-events:none;' : '' ?>">&laquo;</a>
            <a class="page-btn<?= $pagina <= 1 ? ' disabled' : '' ?>" href="<?= $pagina > 1 ? histUrl(['pagina' => $pagina - 1]) : '#' ?>" style="text-decoration:none;<?= $pagina <= 1 ? 'opacity:.4;pointer-events:none;' : '' ?>">&lsaquo;</a>
            <?php
            $janela = 2;
            $ini = max(1, $pagina - $janela);
            $fim = min($totalPaginas, $pagina + $janela);
            for ($p = $ini; $p <= $fim; $p++):
            ?>
                <a class="page-btn<?= $p === $pagina ? ' active' : '' ?>" href="<?= histUrl(['pagina' => $p]) ?>" style="text-decoration:none;"><?= $p ?></a>
            <?php endfor; ?>
            <a class="page-btn<?= $pagina >= $totalPaginas ? ' disabled' : '' ?>" href="<?= $pagina < $totalPaginas ? histUrl(['pagina' => $pagina + 1]) : '#' ?>" style="text-decoration:none;<?= $pagina >= $totalPaginas ? 'opacity:.4;pointer-events:none;' : '' ?>">&rsaquo;</a>
            <a class="page-btn<?= $pagina >= $totalPaginas ? ' disabled' : '' ?>" href="<?= $pagina < $totalPaginas ? histUrl(['pagina' => $totalPaginas]) : '#' ?>" style="text-decoration:none;<?= $pagina >= $totalPaginas ? 'opacity:.4;pointer-events:none;' : '' ?>">&raquo;</a>
        </div>
    </div>
</div>

<!-- Modal: Ver detalhes (tudo o que foi registrado na Triagem desta reprova) -->
<div class="modal-overlay" id="hist-detalhe-modal">
    <div class="modal-box">
        <div class="modal-head">
            <h2>N° de série <span id="hd-ns"></span></h2>
            <button type="button" class="modal-close" id="hist-detalhe-close">&times;</button>
        </div>
        <div class="modal-body">
            <div class="hd-section">Reprova</div>
            <div class="hd-grid">
                <div class="hd-field"><div class="lbl">Código</div><div class="val" id="hd-reprova-codigo"></div></div>
                <div class="hd-field"><div class="lbl">Família</div><div class="val" id="hd-reprova-familia"></div></div>
                <div class="hd-field full"><div class="lbl">Descrição</div><div class="val" id="hd-reprova-desc"></div></div>
                <div class="hd-field"><div class="lbl">Local</div><div class="val" id="hd-reprova-local"></div></div>
                <div class="hd-field"><div class="lbl">Status</div><div class="val" id="hd-status"></div></div>
                <div class="hd-field"><div class="lbl">Responsável</div><div class="val" id="hd-responsavel"></div></div>
            </div>

            <div class="hd-section">Dia e hora dos apontamentos</div>
            <div class="hd-grid">
                <div class="hd-field"><div class="lbl">Data da reprova</div><div class="val" id="hd-data-reprova"></div></div>
                <div class="hd-field"><div class="lbl">Data de chegada</div><div class="val" id="hd-data-chegada"></div></div>
                <div class="hd-field"><div class="lbl">Início do retrabalho</div><div class="val" id="hd-data-inicio"></div></div>
                <div class="hd-field"><div class="lbl">Data de finalização</div><div class="val" id="hd-data-finalizacao"></div></div>
                <div class="hd-field full"><div class="lbl">Concluído em</div><div class="val" id="hd-concluido-em"></div></div>
            </div>

            <div class="hd-section">Causa &amp; observações</div>
            <div class="hd-grid">
                <div class="hd-field full"><div class="lbl">Causa da Reprova</div><div class="val" id="hd-causa-reprova"></div></div>
                <div class="hd-field full"><div class="lbl">Causa Raiz</div><div class="val" id="hd-causa-raiz"></div></div>
                <div class="hd-field full"><div class="lbl">Observações</div><div class="val" id="hd-observacoes"></div></div>
                <div class="hd-field full"><div class="lbl">Próximos setores</div><div class="val" id="hd-setores"></div></div>
            </div>

            <div class="hd-section">Materiais utilizados</div>
            <div id="hd-materiais-vazio" class="hd-vazio">Nenhum material registrado nesta Triagem.</div>
            <table class="hd-materiais" id="hd-materiais-tabela" style="display:none;">
                <thead><tr><th>Material</th><th>Quantidade</th></tr></thead>
                <tbody id="hd-materiais-corpo"></tbody>
            </table>
        </div>
    </div>
</div>

<script>
    (function () {
        var modal = document.getElementById('hist-detalhe-modal');
        var closeBtn = document.getElementById('hist-detalhe-close');

        function setTxt(id, val) {
            var el = document.getElementById(id);
            if (el) el.textContent = (val === null || val === undefined || val === '') ? '—' : val;
        }

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.js-ver-detalhe');
            if (!btn) return;
            var d;
            try { d = JSON.parse(btn.getAttribute('data-detalhe')); } catch (err) { return; }

            setTxt('hd-ns', d.ns);
            setTxt('hd-reprova-codigo', d.reprova_codigo);
            setTxt('hd-reprova-familia', d.reprova_familia);
            setTxt('hd-reprova-desc', d.reprova_desc);
            setTxt('hd-reprova-local', d.reprova_local);
            setTxt('hd-status', d.status === 'finalizado' ? 'Finalizado' : (d.status === 'aprovado' ? 'Aprovado' : d.status));
            setTxt('hd-responsavel', d.responsavel);
            setTxt('hd-data-reprova', d.data_reprova);
            setTxt('hd-data-chegada', d.data_chegada);
            setTxt('hd-data-inicio', d.data_inicio);
            setTxt('hd-data-finalizacao', d.data_finalizacao);
            setTxt('hd-concluido-em', d.concluido_em);
            setTxt('hd-causa-reprova', d.causa_reprova);
            setTxt('hd-causa-raiz', d.causa_raiz);
            setTxt('hd-observacoes', d.observacoes);
            setTxt('hd-setores', d.setores);

            var corpo = document.getElementById('hd-materiais-corpo');
            var tabela = document.getElementById('hd-materiais-tabela');
            var vazio = document.getElementById('hd-materiais-vazio');
            corpo.innerHTML = '';
            if (d.materiais && d.materiais.length) {
                d.materiais.forEach(function (m) {
                    var tr = document.createElement('tr');
                    var tdDesc = document.createElement('td');
                    tdDesc.textContent = m.descricao;
                    var tdQtd = document.createElement('td');
                    tdQtd.textContent = m.quantidade + (m.unidade ? ' ' + m.unidade : '');
                    tr.appendChild(tdDesc);
                    tr.appendChild(tdQtd);
                    corpo.appendChild(tr);
                });
                tabela.style.display = '';
                vazio.style.display = 'none';
            } else {
                tabela.style.display = 'none';
                vazio.style.display = '';
            }

            if (modal) modal.style.display = 'flex';
        });

        if (closeBtn) closeBtn.addEventListener('click', function () { modal.style.display = 'none'; });
        if (modal) modal.addEventListener('click', function (e) { if (e.target === modal) modal.style.display = 'none'; });
    }());

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-toggle-hist');
        if (!btn) return;
        var row = document.getElementById(btn.dataset.target);
        if (!row) return;
        var aberto = row.classList.toggle('is-open');
        btn.textContent = aberto ? '−' : '+';
        btn.setAttribute('aria-expanded', aberto ? 'true' : 'false');
    });
</script>

<?php layoutFooter(); ?>
