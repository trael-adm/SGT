<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireAcessoModulo('analise');

$pdo  = getDB();
$base = defined('APP_URL') ? APP_URL : '';

// Relação do Histórico: relação completa de todos os retrabalhos registrados
// (em andamento e finalizados), a partir do momento em que foram reprovados.
$STATUS_HISTORICO = ['agu_abertura', 'agu_causa_raiz', 'finalizado'];
$LOCAIS_VALIDOS   = ['IQF', 'LAB', 'RET'];

// ─── Filtros (GET) ──────────────────────────────────────────────────────────
$fBusca  = trim((string) ($_GET['busca'] ?? ''));
$fLocal  = trim((string) ($_GET['local'] ?? ''));
$fStatus = trim((string) ($_GET['status'] ?? ''));
$fMes    = trim((string) ($_GET['mes'] ?? ''));

if (!in_array($fLocal, $LOCAIS_VALIDOS, true))    $fLocal = '';
if (!in_array($fStatus, $STATUS_HISTORICO, true)) $fStatus = '';
if (!preg_match('/^\d{4}-\d{2}$/', $fMes))        $fMes = '';

$SORT_COLS_VALIDAS = ['ns', 'pedido', 'projeto', 'descricao', 'origem', 'potencia', 'classe', 'data_reprova', 'status', 'reincidencias'];
$sortCol = (string) ($_GET['sort'] ?? '');
if ($sortCol !== '' && !in_array($sortCol, $SORT_COLS_VALIDAS, true)) $sortCol = '';
$sortDir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

$PORPAGINA_OPCOES = [10, 25, 50, 100];
$porPagina = (int) ($_GET['porPagina'] ?? 10);
if (!in_array($porPagina, $PORPAGINA_OPCOES, true)) $porPagina = 10;
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));

$dataExpr = 'DATE(COALESCE(r.data_reprova, r.concluido_em, r.created_at))';

$where  = ['r.deleted_at IS NULL'];
$params = [];

if ($fLocal !== '')  { $where[] = 'COALESCE(r.estacao, rep.local) = ?'; $params[] = $fLocal; }
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
    try {
        $stmtMat = $pdo->prepare("
            SELECT rmu.id_lote, rmu.codigo, rmu.descricao, rmu.unidade, rmu.quantidade,
                   COALESCE(rmu.preco_medio, ic.preco_medio) AS preco_medio
            FROM retrabalho_material_uso rmu
            LEFT JOIN itens_catalogo ic ON ic.codigo = rmu.codigo
            WHERE rmu.id_lote IN ($ph)
            ORDER BY rmu.id
        ");
        $stmtMat->execute($idLotes);
        foreach ($stmtMat->fetchAll() as $m) {
            $materiaisPorLote[(int) $m['id_lote']][] = $m;
        }
    } catch (\Throwable $e) {
        try {
            $stmtMat = $pdo->prepare("
                SELECT id_lote, codigo, descricao, unidade, quantidade
                FROM retrabalho_material_uso
                WHERE id_lote IN ($ph)
                ORDER BY id
            ");
            $stmtMat->execute($idLotes);
            foreach ($stmtMat->fetchAll() as $m) {
                $m['preco_medio'] = null;
                $materiaisPorLote[(int) $m['id_lote']][] = $m;
            }
        } catch (\Throwable $e2) {}
    }
}
foreach ($registros as &$r) {
    $idLoteR = (int) ($r['id_lote'] ?: $r['id']);
    $r['_materiais'] = $materiaisPorLote[$idLoteR] ?? [];
}
unset($r);

// ─── Auto-healing: garante que peças aguardando retorno não fiquem 'finalizado' ──
try {
    $pdo->query("
        UPDATE retrabalhos r
        JOIN producao_etapas pe ON pe.ns_transformador = r.ns_transformador 
                              AND pe.id_projeto = r.id_projeto
                              AND pe.deleted_at IS NULL 
                              AND pe.status = 'aguardando_retorno'
        SET r.status = 'agu_causa_raiz', 
            r.concluido_em = NULL
        WHERE r.deleted_at IS NULL 
          AND r.status = 'finalizado'
    ");
} catch (\Throwable $e) {}

// ─── Retornos ativos no chão de fábrica (producao_etapas) ────────────────────
$retornosAtivos = $pdo->query("
    SELECT ns_transformador, estacao
    FROM producao_etapas
    WHERE status = 'aguardando_retorno' AND deleted_at IS NULL
")->fetchAll(PDO::FETCH_KEY_PAIR);

// ─── Reincidências: quantas vezes o transformador foi reprovado no LAB ou IQF ─
$qtdReincidenciasPorNs = $pdo->query("
    SELECT ns_transformador, COUNT(DISTINCT COALESCE(id_lote, id)) AS qtd
    FROM retrabalhos
    WHERE deleted_at IS NULL AND estacao IN ('LAB', 'IQF')
    GROUP BY ns_transformador
")->fetchAll(PDO::FETCH_KEY_PAIR);

// ─── Agrupamento por transformador/projeto ───────────────────────────────────
$grupos = [];
foreach ($registros as $r) {
    $gid = (string) $r['ns_transformador'];
    if ($gid === '') $gid = 's_ns_' . $r['id'];

    if (!isset($grupos[$gid])) {
        [$potencia, $classe] = parsePotenciaClasse($r['projeto_descricao'] ?? null);
        $grupos[$gid] = [
            'ns_transformador'  => $r['ns_transformador'],
            'id_projeto'        => $r['id_projeto'] ?? 0,
            'projeto_codigo'    => $r['projeto_codigo'],
            'projeto_descricao' => $r['projeto_descricao'],
            'pedido_numero'     => $r['pedido_numero'],
            'potencia'          => $potencia,
            'classe'            => $classe,
            'data_reprova'      => $r['data_reprova'],
            'status'            => $r['status'],
            'reincidencias'     => max(1, (int) ($qtdReincidenciasPorNs[$r['ns_transformador']] ?? 1)),
            'itens'             => [],
            '_maxId'            => 0,
        ];
    }
    $g = &$grupos[$gid];
    $g['itens'][] = $r;
    $g['_maxId']  = max($g['_maxId'], (int) $r['id']);
    if ($r['data_reprova'] && (!$g['data_reprova'] || $r['data_reprova'] > $g['data_reprova'])) {
        $g['data_reprova'] = $r['data_reprova'];
    }
    unset($g);
}
foreach ($grupos as &$g) {
    usort($g['itens'], function (array $a, array $b): int {
        $cmp = strcmp((string) ($b['concluido_em'] ?? $b['data_reprova'] ?? ''), (string) ($a['concluido_em'] ?? $a['data_reprova'] ?? ''));
        return $cmp !== 0 ? $cmp : ($b['id'] <=> $a['id']);
    });
    $g['qtdRegistros'] = count($g['itens']);

    $locais = [];
    $temAbertura = false;
    $temCausaRaiz = false;
    foreach ($g['itens'] as $item) {
        $loc = strtoupper(trim((string) ($item['estacao'] ?: $item['reprova_local'] ?: '')));
        if ($loc === 'GER') $loc = 'LAB';
        if ($loc !== '' && !in_array($loc, $locais, true)) {
            $locais[] = $loc;
        }
        if ($item['status'] === 'agu_abertura') $temAbertura = true;
        if ($item['status'] === 'agu_causa_raiz') $temCausaRaiz = true;
    }
    $g['origens_str'] = $locais ? implode(' / ', $locais) : '—';
    $g['locais']      = $locais;

    $nsStr = (string) ($g['ns_transformador'] ?? '');
    if (isset($retornosAtivos[$nsStr])) {
        $g['status'] = 'aguardando_retorno';
    } elseif ($temAbertura) {
        $g['status'] = 'agu_abertura';
    } elseif ($temCausaRaiz) {
        $g['status'] = 'agu_causa_raiz';
    } else {
        $g['status'] = 'finalizado';
    }
}
unset($g);
$grupos = array_values($grupos);

function histGroupSortValue(array $g, string $col): string|int
{
    return match ($col) {
        'ns'           => (string) ($g['ns_transformador'] ?? ''),
        'pedido'       => (string) ($g['pedido_numero'] ?? ''),
        'projeto'      => (string) ($g['projeto_codigo'] ?? ''),
        'descricao'    => (string) ($g['projeto_descricao'] ?? ''),
        'origem'       => (string) ($g['origens_str'] ?? ''),
        'potencia'     => $g['potencia'] !== null ? (int) round((float) $g['potencia']) : -1,
        'classe'       => $g['classe']   !== null ? (int) round((float) $g['classe'])   : -1,
        'data_reprova' => (string) ($g['data_reprova'] ?? ''),
        'status'       => (string) ($g['status'] ?? ''),
        'reincidencias'=> (int) ($g['reincidencias'] ?? 1),
        'registros'    => $g['qtdRegistros'],
        default        => $g['_maxId'],
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
    SELECT DISTINCT DATE_FORMAT(COALESCE(data_reprova, concluido_em, created_at), '%Y-%m') AS ym
    FROM retrabalhos
    WHERE deleted_at IS NULL
    ORDER BY ym DESC
")->fetchAll(PDO::FETCH_COLUMN);

$MESES_PT = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho',
             7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];

$statusMap = [
    'agu_abertura'       => ['label' => 'Agu. Abertura',   'bg' => '#fef2f2', 'fg' => '#dc2626'],
    'agu_causa_raiz'     => ['label' => 'Agu. Causa Raiz', 'bg' => '#fffbeb', 'fg' => '#b45309'],
    'aguardando_retorno' => ['label' => 'Agu. Retorno',    'bg' => '#eff6ff', 'fg' => '#1d4ed8'],
    'finalizado'         => ['label' => 'Finalizado',      'bg' => '#ecfdf5', 'fg' => '#16a34a'],
];
$localMap = [
    'IQF' => ['label' => 'IQF', 'title' => 'Inspeção final', 'bg' => '#eff6ff', 'fg' => '#2563eb', 'border' => '#bfdbfe'],
    'LAB' => ['label' => 'LAB', 'title' => 'Laboratório',    'bg' => '#f5f3ff', 'fg' => '#7c3aed', 'border' => '#ddd6fe'],
    'RET' => ['label' => 'RET', 'title' => 'Retrabalho',     'bg' => '#fff7ed', 'fg' => '#c2410c', 'border' => '#ffedd5'],
];
$setoresLabel = retrabalhoSetoresTriagem();

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
function histMontarDetalhe(array $r, array $localMap, array $setoresLabel, array $retornosAtivos = []): array
{
    $cod = (string)($r['reprova_codigo'] ?? '');
    if (str_starts_with($cod, 'R')) {
        $origemItem = 'GER';
    } else {
        $origemItem = strtoupper(trim((string) ($r['estacao'] ?: ($r['reprova_local'] !== 'GER' ? $r['reprova_local'] : 'IQF'))));
        if ($origemItem === 'GER' || $origemItem === '') $origemItem = 'IQF';
    }
    $lo = $localMap[$origemItem] ?? null;
    $setores = $r['setores_destino'] !== null ? explode(',', $r['setores_destino']) : [];

    $materiais = array_map(function (array $m) {
        return [
            'codigo'     => $m['codigo'] ?? '',
            'descricao'  => $m['descricao'],
            'quantidade' => rtrim(rtrim(number_format((float) $m['quantidade'], 2, ',', '.'), '0'), ','),
            'unidade'    => $m['unidade'] ?? '',
        ];
    }, $r['_materiais']);

    $nsTransformador = (string)($r['ns_transformador'] ?? '');
    $estaEmRetorno   = isset($retornosAtivos[$nsTransformador]);
    $isFinalizado    = ($r['status'] === 'finalizado' && !$estaEmRetorno);

    $statusExibir    = $estaEmRetorno ? 'aguardando_retorno' : $r['status'];
    $dataConclusao   = $isFinalizado ? ($r['concluido_em'] ?: $r['data_finalizacao']) : null;

    return [
        'ns'              => $r['ns_transformador'] ?? '—',
        'reprova_codigo'  => $r['reprova_codigo'] ?? '—',
        'reprova_familia' => $r['reprova_familia'] ?? '—',
        'reprova_desc'    => $r['reprova_descricao'] ?? '—',
        'reprova_local'   => $lo ? $lo['label'] . ' — ' . $lo['title'] : ($origemItem ?: '—'),
        'status'          => $statusExibir,
        'responsavel'     => $r['responsavel_nome'] ?? '—',
        'data_reprova'    => histFmtData($r['data_reprova']),
        'data_chegada'    => histFmtData($r['data_chegada']),
        'data_inicio'     => histFmtDataHora($r['data_inicio']),
        'concluido_em'    => histFmtDataHora($dataConclusao),
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

$temFiltroAtivo = $fBusca !== '' || $fLocal !== '' || $fStatus !== '' || $fMes !== '';

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
    .hist-table > thead > tr > th { position:sticky; top:0; z-index:10; background:var(--color-surface-2,#f8fafc); box-shadow:0 1px 2px rgba(0,0,0,0.05); }
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
    .hist-toggle-btn svg { transition: transform 0.15s ease; }
    .hist-toggle-btn[aria-expanded="true"] svg { transform: rotate(90deg); }
    .hist-toggle-btn[aria-expanded="true"] { background: #fff7ed; border-color: #ea580c; color: #9a3412; }
    .hist-detail-row { display:none; }
    .hist-detail-row.is-open { display:table-row; }
    .hist-detail-wrap { background:var(--color-surface-2,#f9fafb); border-radius:8px; padding:8px 10px; margin:2px 0; }
    .hist-subtable { width:100%; border-collapse:collapse; font-size:12px; }
    .hist-subtable th { position:static !important; top:auto !important; z-index:1 !important; background:transparent !important; box-shadow:none !important; text-align:left; font-size:9px; text-transform:uppercase; letter-spacing:.5px; color:var(--color-text-muted,#6b7280); padding:6px 8px; border-bottom:1px solid var(--color-border,#e5e7eb); white-space:nowrap; }
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
    .btn-expand-all {
        display: inline-flex; align-items: center; gap: 7px; padding: 6px 14px;
        border: 1.5px solid #cbd5e1; border-radius: 6px; background: #ffffff;
        color: #334155; font-size: 12px; font-weight: 600; cursor: pointer;
        user-select: none; transition: all 0.15s ease;
    }
    .btn-expand-all:hover { background: #f8fafc; border-color: #94a3b8; color: #0f172a; }
    .btn-expand-all.is-active { background: #fff7ed; border-color: #ea580c; color: #9a3412; }
    .btn-expand-all .ico-expand { transition: transform 0.15s ease; }
    .btn-expand-all[aria-expanded="true"] .ico-expand { transform: rotate(90deg); }
    .btn-expand-col {
        display: inline-flex; align-items: center; justify-content: center;
        width: 24px; height: 24px; border: 1px solid #cbd5e1; border-radius: 6px;
        background: #f8fafc; color: #475569; cursor: pointer; transition: all 0.15s ease;
    }
    .btn-expand-col:hover { background: #f1f5f9; border-color: #94a3b8; }
    .btn-expand-col svg { transition: transform 0.15s ease; }
    .btn-expand-col[aria-expanded="true"] { background: #fff7ed; border-color: #ea580c; color: #9a3412; }
    .btn-expand-col[aria-expanded="true"] svg { transform: rotate(90deg); }
</style>

<!-- Container de Página com Rolagem Exclusiva na Tabela -->
<div class="page-fixed-layout">

    <!-- Cabeçalho -->
    <div class="page-fixed-header">
        <h1 style="font-size:var(--font-size-xl,20px);font-weight:700;margin-top:2px;">Relação do Histórico</h1>
        <p class="text-secondary" style="font-size:13px;color:var(--color-text-secondary,#6b7280);margin-top:2px;">
            Listagem completa de todos os retrabalhos registrados — em andamento e finalizados
        </p>
    </div>

    <!-- Abas por local -->
    <div class="hist-tabs">
        <a class="hist-tab<?= $fLocal === '' ? ' active' : '' ?>" href="<?= histUrl(['local' => null, 'pagina' => 1]) ?>">Todos</a>
        <?php foreach ($localMap as $k => $info): ?>
            <a class="hist-tab<?= $fLocal === $k ? ' active' : '' ?>" href="<?= histUrl(['local' => $k, 'pagina' => 1]) ?>"><?= htmlspecialchars($info['label']) ?> — <?= htmlspecialchars($info['title']) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="hist-card">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
        <h3 style="margin:0;display:flex;align-items:center;gap:8px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
            Histórico
        </h3>
    </div>

    <!-- Filtros -->
    <form method="GET" class="filter-bar" id="hist-filtros">
        <input type="hidden" name="local" value="<?= htmlspecialchars($fLocal) ?>">
        <div class="filter-group-left">
            <div class="filter-search-wrap">
                <svg class="filter-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="search" name="busca" class="filter-search-input" value="<?= htmlspecialchars($fBusca) ?>" placeholder="Buscar projeto, pedido, NS, reprova…">
            </div>
            <select name="status" class="filter-select" onchange="this.form.submit()">
                <option value="">Status…</option>
                <option value="agu_abertura" <?= $fStatus === 'agu_abertura' ? 'selected' : '' ?>>Agu. Abertura</option>
                <option value="agu_causa_raiz" <?= $fStatus === 'agu_causa_raiz' ? 'selected' : '' ?>>Agu. Causa Raiz</option>
                <option value="finalizado" <?= $fStatus === 'finalizado' ? 'selected' : '' ?>>Finalizado</option>
            </select>
            <select name="mes" class="filter-select" onchange="this.form.submit()">
                <option value="">Mês…</option>
                <?php foreach ($mesesDisponiveis as $ym): if (!$ym) continue;
                    $lbl = ($MESES_PT[(int) substr($ym, 5, 2)] ?? $ym) . '/' . substr($ym, 0, 4);
                ?>
                    <option value="<?= htmlspecialchars($ym) ?>" <?= $fMes === $ym ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="filter-btn filter-btn-secondary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                Filtrar
            </button>
            <?php if ($temFiltroAtivo): ?>
                <a href="<?= htmlspecialchars($base) ?>/pages/retrabalho/historico.php" class="filter-btn filter-btn-clear" title="Limpar filtros">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    Limpar
                </a>
            <?php endif; ?>
            <button type="button" id="btn-toggle-all-hist" class="filter-btn btn-expand-all" aria-expanded="false" title="Expandir ou recolher todas as linhas">
                <svg class="ico-expand" viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                <span class="lbl-expand">Expandir Todos</span>
            </button>
        </div>
    </form>

    <div class="hist-table-wrap">
        <table class="hist-table">
            <thead>
                <tr>
                    <th style="width:36px;text-align:center;">
                        <button type="button" class="btn-expand-col js-toggle-all-quick" aria-expanded="false" title="Expandir/Recolher todos">
                            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                        </button>
                    </th>
                    <?php
                    histSortTh('N° Série', 'ns');
                    histSortTh('Pedido', 'pedido');
                    histSortTh('Projeto', 'projeto');
                    histSortTh('Descrição', 'descricao');
                    histSortTh('Origem', 'origem');
                    histSortTh('Potência', 'potencia');
                    histSortTh('Classe', 'classe');
                    histSortTh('Data Reprova', 'data_reprova');
                    histSortTh('Status', 'status');
                    histSortTh('Reincidências', 'reincidencias');
                    ?>
                    <th style="width:40px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$gruposPagina): ?>
                    <tr><td colspan="12"><div class="hist-empty">Nenhum retrabalho encontrado para os filtros selecionados.</div></td></tr>
                <?php else: foreach ($gruposPagina as $g):
                    $detId = 'hist-det-' . (!empty($g['ns_transformador']) ? $g['ns_transformador'] : $g['_maxId']);
                ?>
                    <tr>
                        <td>
                            <button type="button" class="hist-toggle-btn js-toggle-hist" data-target="<?= htmlspecialchars($detId) ?>" aria-expanded="false" title="Mostrar reprovas">
                                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                            </button>
                        </td>
                        <td><span class="hist-code" style="font-weight:700;color:#111827;"><?= htmlspecialchars($g['ns_transformador'] ?? '—') ?></span></td>
                        <td><?= htmlspecialchars($g['pedido_numero'] ?? '—') ?></td>
                        <td><span class="hist-code"><?= htmlspecialchars($g['projeto_codigo'] ?? '—') ?></span></td>
                        <td><?= htmlspecialchars($g['projeto_descricao'] ?? '—') ?></td>
                        <td>
                            <?php
                            $locais = $g['locais'] ?? [];
                            if (!$locais): ?>
                                <span style="color:#9ca3af;">—</span>
                            <?php else: ?>
                                <div style="display:inline-flex;gap:4px;align-items:center;flex-wrap:nowrap;">
                                    <?php foreach ($locais as $idx => $loc):
                                        $lo  = $localMap[$loc] ?? null;
                                        $lbl = $lo ? $lo['label'] : $loc;
                                        $bg  = $lo['bg'] ?? '#f3f4f6';
                                        $fg  = $lo['fg'] ?? '#374151';
                                        $brd = $lo['border'] ?? '#e5e7eb';
                                        if ($idx > 0): ?>
                                            <span style="color:#94a3b8;font-weight:700;font-size:11px;">/</span>
                                        <?php endif; ?>
                                        <span class="hist-badge" style="background:<?= $bg ?>;color:<?= $fg ?>;border:1px solid <?= $brd ?>;font-size:11px;font-weight:700;padding:2px 7px;" title="<?= htmlspecialchars($lo['title'] ?? $lbl) ?>">
                                            <?= htmlspecialchars($lbl) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?= $g['potencia'] !== null ? htmlspecialchars($g['potencia']) . ' kVA' : '—' ?></td>
                        <td><?= $g['classe'] !== null ? htmlspecialchars($g['classe']) . ' kV' : '—' ?></td>
                        <td><span class="hist-code" style="font-size:12px;"><?= htmlspecialchars(histFmtData($g['data_reprova'])) ?></span></td>
                        <td>
                            <?php 
                                $st = $statusMap[$g['status']] ?? ['label' => ucfirst($g['status']), 'bg' => '#f3f4f6', 'fg' => '#374151'];
                            ?>
                            <span class="hist-badge" style="background:<?= $st['bg'] ?>;color:<?= $st['fg'] ?>;font-size:11px;font-weight:600;">
                                <?= htmlspecialchars($st['label']) ?>
                            </span>
                        </td>
                        <td style="text-align:center;">
                            <span class="hist-badge" style="background:#eef2ff;color:#4338ca;"><?= (int) $g['reincidencias'] ?></span>
                        </td>
                        <td style="text-align:center;">
                            <button type="button" class="btn-icon btn-icon-danger btn-icon-sm js-hist-del-grupo"
                                    data-ids="<?= htmlspecialchars(implode(',', array_map(fn ($it) => (int) $it['id'], $g['itens']))) ?>"
                                    data-ns="<?= htmlspecialchars((string) ($g['ns_transformador'] ?? '')) ?>"
                                    title="Excluir todas as reprovas deste N° de série">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                            </button>
                        </td>
                    </tr>
                    <tr class="hist-detail-row" id="<?= htmlspecialchars($detId) ?>">
                        <td colspan="12">
                            <div class="hist-detail-wrap">
                                <div style="overflow-x:auto;">
                                <table class="hist-subtable">
                                    <thead>
                                        <tr>
                                            <th>Data Reprova</th>
                                            <th>Contenção</th>
                                            <th>Família</th>
                                            <th>Origem</th>
                                            <th>Causa da Reprova</th>
                                            <th>Concluído em</th>
                                            <th style="text-align:right;">Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($g['itens'] as $r):
                                            $origemItem   = strtoupper(trim((string) ($r['estacao'] ?: $r['reprova_local'] ?: '')));
                                            if ($origemItem === 'GER') $origemItem = 'LAB';
                                            $lo           = $localMap[$origemItem] ?? null;
                                            $origemLabel  = $lo ? $lo['label'] : ($origemItem ?: '—');
                                            $origemTitle  = $lo ? $lo['title'] : ($origemItem ?: '');
                                            $origemBg     = $lo['bg'] ?? '#f3f4f6';
                                            $origemFg     = $lo['fg'] ?? '#374151';
                                            $origemBorder = $lo['border'] ?? '#e5e7eb';
                                            $nsTransformador = (string) ($r['ns_transformador'] ?? '');
                                            $estaEmRetorno   = isset($retornosAtivos[$nsTransformador]);
                                            $isFinalizado    = ($r['status'] === 'finalizado' && !$estaEmRetorno);
                                            $dataConc        = $isFinalizado ? ($r['concluido_em'] ?: $r['data_finalizacao']) : null;
                                        ?>
                                            <tr>
                                                <td><span class="hist-code" style="font-size:12px;font-weight:600;"><?= htmlspecialchars(histFmtData($r['data_reprova'])) ?></span></td>
                                                <td>
                                                    <span class="hist-code" style="font-weight:500;"><?= htmlspecialchars($r['reprova_codigo'] ?? '—') ?></span>
                                                    <?php if (!empty($r['reprova_descricao'])): ?>
                                                        <div style="font-size:11px;color:#6b7280;"><?= htmlspecialchars($r['reprova_descricao']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="font-size:12px;"><?= htmlspecialchars($r['reprova_familia'] ?? '—') ?></td>
                                                <td>
                                                    <span class="hist-badge" style="background:<?= $origemBg ?>;color:<?= $origemFg ?>;border:1px solid <?= $origemBorder ?>;font-size:11px;font-weight:700;padding:2px 7px;" title="<?= htmlspecialchars($origemTitle) ?>">
                                                        <?= htmlspecialchars($origemLabel) ?>
                                                    </span>
                                                </td>
                                                <td style="font-size:12px;">
                                                    <?php if (!empty($r['causa_raiz'])): ?>
                                                        <?= htmlspecialchars($r['causa_raiz']) ?>
                                                    <?php elseif (!empty($r['causa_reprova'])): ?>
                                                        <?= htmlspecialchars($r['causa_reprova']) ?>
                                                        <span style="display:inline-block;margin-left:4px;font-size:10px;font-weight:600;color:#64748b;background:#f1f5f9;padding:1px 5px;border-radius:4px;border:1px solid #e2e8f0;" title="Registro importado da planilha histórica">Histórico</span>
                                                    <?php else: ?>
                                                        <span style="color:#94a3b8;">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="font-size:12px;"><?= htmlspecialchars(histFmtData($dataConc)) ?></td>
                                                <td style="text-align:right;">
                                                    <div style="display:inline-flex;align-items:center;gap:6px;">
                                                        <button type="button" class="hist-ver-btn js-ver-detalhe"
                                                                data-detalhe='<?= htmlspecialchars(json_encode(histMontarDetalhe($r, $localMap, $setoresLabel, $retornosAtivos), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'
                                                                title="Ver tudo o que foi registrado na Triagem">
                                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                                            Ver detalhes
                                                        </button>
                                                        <button type="button" class="btn-icon btn-icon-danger btn-icon-sm js-hist-del-item" data-id="<?= (int) $r['id'] ?>" title="Excluir esta reprova">
                                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                                                        </button>
                                                    </div>
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
                <div class="hd-field"><div class="lbl">Concluído em</div><div class="val" id="hd-concluido-em"></div></div>
            </div>

            <div class="hd-section">Causa &amp; observações</div>
            <div class="hd-grid">
                <div class="hd-field full"><div class="lbl">Causa da Reprova</div><div class="val" id="hd-causa-raiz"></div></div>
                <div class="hd-field full"><div class="lbl">Observações</div><div class="val" id="hd-observacoes"></div></div>
                <div class="hd-field full"><div class="lbl">Próximos setores</div><div class="val" id="hd-setores"></div></div>
            </div>

            <div class="hd-section">Materiais utilizados</div>
            <div id="hd-materiais-vazio" class="hd-vazio">Nenhum material registrado nesta Triagem.</div>
            <table class="hd-materiais" id="hd-materiais-tabela" style="display:none;width:100%;">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Descrição</th>
                        <th>Qtd</th>
                        <th>Unidade</th>
                        <th style="text-align:right;">Preço Médio</th>
                        <th style="text-align:right;">Subtotal</th>
                    </tr>
                </thead>
                <tbody id="hd-materiais-corpo"></tbody>
                <tfoot id="hd-materiais-foot">
                    <tr style="background:#f8fafc;font-weight:700;border-top:2px solid #e2e8f0;">
                        <td colspan="4" style="text-align:right;padding:8px 10px;font-size:12px;color:#475569;">Custo Total dos Materiais:</td>
                        <td colspan="2" style="text-align:right;padding:8px 10px;font-size:13px;color:#1e40af;" id="hd-materiais-total">R$ 0,00</td>
                    </tr>
                </tfoot>
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

            var stLbl = d.status;
            if (d.status === 'finalizado') stLbl = 'Finalizado';
            else if (d.status === 'agu_causa_raiz') stLbl = 'Em Retrabalho';
            else if (d.status === 'agu_abertura') stLbl = 'Aguardando Abertura';
            else if (d.status === 'aguardando_retorno') stLbl = 'Aguardando Retorno';

            setTxt('hd-ns', d.ns);
            setTxt('hd-reprova-codigo', d.reprova_codigo);
            setTxt('hd-reprova-familia', d.reprova_familia);
            setTxt('hd-reprova-desc', d.reprova_desc);
            setTxt('hd-reprova-local', d.reprova_local);
            setTxt('hd-status', stLbl);
            setTxt('hd-responsavel', d.responsavel);
            setTxt('hd-data-reprova', d.data_reprova);
            setTxt('hd-data-chegada', d.data_chegada);
            setTxt('hd-data-inicio', d.data_inicio);
            setTxt('hd-concluido-em', d.concluido_em);
            setTxt('hd-causa-raiz', d.causa_raiz);
            setTxt('hd-observacoes', d.observacoes);
            setTxt('hd-setores', d.setores);

            var corpo = document.getElementById('hd-materiais-corpo');
            var tabela = document.getElementById('hd-materiais-tabela');
            var vazio = document.getElementById('hd-materiais-vazio');
            corpo.innerHTML = '';
            if (d.materiais && d.materiais.length) {
                var totalSoma = 0;
                d.materiais.forEach(function (m) {
                    var tr = document.createElement('tr');
                    var tdCod = document.createElement('td');
                    tdCod.textContent = m.codigo || '—';
                    var tdDesc = document.createElement('td');
                    tdDesc.textContent = m.descricao;
                    var tdQtd = document.createElement('td');
                    tdQtd.textContent = m.quantidade;
                    var tdUnid = document.createElement('td');
                    tdUnid.textContent = m.unidade || '—';

                    var preco = m.preco_medio != null && m.preco_medio !== '' ? parseFloat(m.preco_medio) : null;
                    var qtd = parseFloat(m.quantidade) || 0;
                    var sub = (preco !== null && !isNaN(preco)) ? (qtd * preco) : null;
                    if (sub !== null) totalSoma += sub;

                    var tdPreco = document.createElement('td');
                    tdPreco.style.textAlign = 'right';
                    tdPreco.style.whiteSpace = 'nowrap';
                    tdPreco.textContent = preco !== null ? 'R$ ' + preco.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '—';

                    var tdSub = document.createElement('td');
                    tdSub.style.textAlign = 'right';
                    tdSub.style.fontWeight = '700';
                    tdSub.style.whiteSpace = 'nowrap';
                    tdSub.textContent = sub !== null ? 'R$ ' + sub.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '—';

                    tr.appendChild(tdCod);
                    tr.appendChild(tdDesc);
                    tr.appendChild(tdQtd);
                    tr.appendChild(tdUnid);
                    tr.appendChild(tdPreco);
                    tr.appendChild(tdSub);
                    corpo.appendChild(tr);
                });
                var totalEl = document.getElementById('hd-materiais-total');
                if (totalEl) totalEl.textContent = 'R$ ' + totalSoma.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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

    (function() {
        // Limpa chaves legadas de persistência para sempre iniciar com as linhas recolhidas
        try {
            localStorage.removeItem('sgt_historico_expand_all');
            localStorage.removeItem('sgt_historico_open_rows');
        } catch (e) {}

        function syncHeaderButtons(expandAll) {
            var btnAll = document.getElementById('btn-toggle-all-hist');
            if (btnAll) {
                btnAll.setAttribute('aria-expanded', expandAll ? 'true' : 'false');
                btnAll.classList.toggle('is-active', expandAll);
                var lbl = btnAll.querySelector('.lbl-expand');
                if (lbl) lbl.textContent = expandAll ? 'Recolher Todos' : 'Expandir Todos';
            }
            var quickBtn = document.querySelector('.js-toggle-all-quick');
            if (quickBtn) {
                // Ícone gira via CSS a partir de aria-expanded (ver .btn-expand-col) —
                // não mexe no conteúdo do botão (é um SVG, não texto).
                quickBtn.setAttribute('aria-expanded', expandAll ? 'true' : 'false');
                quickBtn.title = expandAll ? 'Recolher todos' : 'Expandir todos';
            }
        }

        function setAllRows(expand) {
            syncHeaderButtons(expand);
            document.querySelectorAll('.js-toggle-hist').forEach(function(btn) {
                var targetId = btn.dataset.target;
                var row = document.getElementById(targetId);
                if (!row) return;

                if (expand) {
                    row.classList.add('is-open');
                    btn.setAttribute('aria-expanded', 'true');
                } else {
                    row.classList.remove('is-open');
                    btn.setAttribute('aria-expanded', 'false');
                }
            });
        }

        document.addEventListener('click', function (e) {
            var btnAll = e.target.closest('#btn-toggle-all-hist') || e.target.closest('.js-toggle-all-quick');
            if (btnAll) {
                var isCurrentlyExpanded = btnAll.classList.contains('is-active') || btnAll.getAttribute('aria-expanded') === 'true';
                setAllRows(!isCurrentlyExpanded);
                return;
            }

            var btn = e.target.closest('.js-toggle-hist');
            if (!btn) return;
            var targetId = btn.dataset.target;
            var row = document.getElementById(targetId);
            if (!row) return;
            var aberto = row.classList.toggle('is-open');
            btn.setAttribute('aria-expanded', aberto ? 'true' : 'false');

            // Se alguma linha for fechada manualmente, desmarca o botão de "Expandir Todos"
            if (!aberto) {
                syncHeaderButtons(false);
            }
        });
    })();

    (function() {
        var formTimer;
        var buscaInput = document.querySelector('#hist-filtros input[name="busca"]');
        if (buscaInput) {
            buscaInput.addEventListener('input', function() {
                clearTimeout(formTimer);
                formTimer = setTimeout(function() {
                    buscaInput.form.submit();
                }, 600);
            });
        }
    })();

    // ─── Excluir reprova(s) direto do Histórico (soft delete) ──────────────────
    (function () {
        var HIST_API = <?= json_encode($base . '/api/retrabalho-acao.php') ?>;

        function histNotify(msg) {
            if (typeof window.showAlert === 'function') window.showAlert(msg, 'danger');
            else alert(msg);
        }

        function postAcao(body) {
            return fetch(HIST_API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(function (r) {
                return r.json().catch(function () { return { sucesso: false, erro: 'Resposta inválida do servidor.' }; });
            });
        }

        // Excluir uma única reprova (dentro do "+")
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.js-hist-del-item');
            if (!btn || btn.disabled) return;
            if (!window.confirm('Excluir esta reprova? Esta ação não pode ser desfeita por aqui.')) return;

            btn.disabled = true;
            postAcao(new URLSearchParams({ acao: 'excluir', id: btn.dataset.id })).then(function (res) {
                if (res && res.sucesso) {
                    window.location.reload();
                } else {
                    histNotify((res && res.erro) || 'Erro ao excluir a reprova.');
                    btn.disabled = false;
                }
            }).catch(function () {
                histNotify('Falha de conexão ao excluir a reprova.');
                btn.disabled = false;
            });
        });

        // Excluir todas as reprovas de um N° de série (linha principal)
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.js-hist-del-grupo');
            if (!btn || btn.disabled) return;

            var ids = (btn.dataset.ids || '').split(',').filter(Boolean);
            if (!ids.length) return;

            var ns = btn.dataset.ns;
            var msg = ids.length > 1
                ? 'Excluir todas as ' + ids.length + ' reprovas do N° de série ' + ns + '? Esta ação não pode ser desfeita por aqui.'
                : 'Excluir esta reprova? Esta ação não pode ser desfeita por aqui.';
            if (!window.confirm(msg)) return;

            btn.disabled = true;
            var body = new URLSearchParams({ acao: 'excluir_lote' });
            ids.forEach(function (id) { body.append('ids[]', id); });

            postAcao(body).then(function (res) {
                if (res && res.sucesso) {
                    if (res.bloqueadas > 0) alert(res.mensagem);
                    window.location.reload();
                } else {
                    histNotify((res && res.erro) || 'Erro ao excluir as reprovas.');
                    btn.disabled = false;
                }
            }).catch(function () {
                histNotify('Falha de conexão ao excluir as reprovas.');
                btn.disabled = false;
            });
        });
    })();
</script>

<?php layoutFooter(); ?>
