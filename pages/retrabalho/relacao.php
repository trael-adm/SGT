<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/planilha-ns-of.php';

requireAcessoModulo('retrabalho');

$pdo  = getDB();
$base = defined('APP_URL') ? APP_URL : '';

// ─── Filtros (GET) ────────────────────────────────────────────────────────────
$fBusca   = trim((string) ($_GET['busca'] ?? ''));
$fLocal   = trim((string) ($_GET['local'] ?? ''));
$fFamilia = trim((string) ($_GET['familia'] ?? ''));
$fMes     = trim((string) ($_GET['mes'] ?? ''));

$LOCAIS_VALIDOS  = ['IQF', 'LAB', 'GER'];
// Finalizado escondido a pedido — esse status agora vive só na
// Relação do Histórico (pages/retrabalho/historico.php).
$STATUS_VALIDOS  = ['agu_abertura', 'agu_causa_raiz'];
$DIAS_FLAG       = 15; // acima disso, sinaliza "parado há muito tempo"

if (!in_array($fLocal, $LOCAIS_VALIDOS, true)) $fLocal = '';
if (!preg_match('/^\d{4}-\d{2}$/', $fMes))     $fMes = '';

// "Mostrar" (status): só usa o default (em aberto) se o form ainda não foi submetido
if (isset($_GET['status_touched'])) {
    $fStatus = array_values(array_intersect((array) ($_GET['status'] ?? []), $STATUS_VALIDOS));
} else {
    $fStatus = ['agu_abertura', 'agu_causa_raiz'];
}

// Ordenação (clique nas colunas da tabela) — agora em nível de projeto (grupo).
// Sem "sort" na URL, usa a ordenação padrão: projeto com o caso mais urgente primeiro.
$SORT_COLS_VALIDAS = ['data_pcp', 'prioridade', 'pedido', 'projeto', 'descricao', 'potencia', 'classe', 'registros', 'repetencias', 'ns', 'dias', 'flags', 'status'];
$sortCol = (string) ($_GET['sort'] ?? '');
if ($sortCol !== '' && !in_array($sortCol, $SORT_COLS_VALIDAS, true)) $sortCol = '';
$sortDir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

// Paginação
$PORPAGINA_OPCOES = [10, 25, 50, 100];
$porPagina = (int) ($_GET['porPagina'] ?? 10);
if (!in_array($porPagina, $PORPAGINA_OPCOES, true)) $porPagina = 10;
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));

// Data de referência do lançamento: reprova → início → created_at
$dataExpr = 'DATE(COALESCE(r.data_reprova, r.data_inicio, r.created_at))';

// Enviado para o Laboratório (checkbox "Próximos setores" na Triagem) também sai
// daqui enquanto aguarda o retorno — vive na aba Retornos do Produção (ver
// pages/producao/retornos.php) até ser aprovado (-> Histórico) ou reprovado
// (-> volta pra cá, ver acao=reprovar_retorno em api/retrabalho-acao.php).
$where  = [
    'r.deleted_at IS NULL',
    "rep.familia NOT IN ('PINTURA', 'SERIGRAFIA', 'CAMADA')",
    "(r.setores_destino NOT LIKE '%pintura%' OR r.setores_destino IS NULL)",
    "NOT EXISTS (
        SELECT 1 FROM producao_etapas pe
        WHERE pe.ns_transformador = r.ns_transformador AND pe.id_projeto = r.id_projeto
          AND pe.estacao IN ('LAB', 'IQF') AND pe.status = 'aguardando_retorno' AND pe.deleted_at IS NULL
    )",
];
$params = [];

if ($fLocal !== '') {
    if ($fLocal === 'GER') {
        $where[] = "(rep.codigo LIKE 'R%' OR rep.setor_causador = 'REVITALIZAÇÃO' OR (r.estacao = 'GER' AND rep.codigo LIKE 'R%'))";
    } else {
        $where[] = '(r.estacao = ? OR (rep.local = ? AND rep.codigo NOT LIKE \'R%\'))';
        $params[] = $fLocal;
        $params[] = $fLocal;
    }
}
if ($fFamilia !== '') { $where[] = 'rep.familia = ?'; $params[] = $fFamilia; }
if ($fMes !== '')     { $where[] = "DATE_FORMAT($dataExpr, '%Y-%m') = ?"; $params[] = $fMes; }

if ($fBusca !== '') {
    $where[] = '(pr.codigo LIKE ? OR ped.numero LIKE ? OR r.ns_transformador LIKE ? OR rep.codigo LIKE ? OR rep.descricao LIKE ? OR u.nome LIKE ?)';
    $like = '%' . $fBusca . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}

if ($fStatus) {
    $where[] = 'r.status IN (' . implode(',', array_fill(0, count($fStatus), '?')) . ')';
    array_push($params, ...$fStatus);
} else {
    $where[] = '1=0';
}

$whereSql = implode(' AND ', $where);

$fromSql = "
    FROM retrabalhos r
    LEFT JOIN usuarios u   ON u.id   = r.id_responsavel
    LEFT JOIN projetos pr  ON pr.id  = r.id_projeto
    LEFT JOIN pedidos ped  ON ped.id = pr.id_pedido
    LEFT JOIN reprovas rep ON rep.id = r.id_reprova
    WHERE $whereSql
";

// ─── Registros filtrados ────────────────────────────────────────────────────────
// Ordenação e paginação acontecem em PHP (mais abaixo): "Parado há" e "Flags" são
// calculados a partir dos dados, não são colunas do banco.
$sql = "
    SELECT r.*,
           u.nome    AS responsavel_nome,
           pr.codigo AS projeto_codigo, pr.descricao AS projeto_descricao, pr.id_pedido AS id_pedido,
           pr.prioridade AS projeto_prioridade, pr.sequencia AS projeto_sequencia,
           ped.numero AS pedido_numero, ped.prioridade AS pedido_prioridade, ped.sequencia AS pedido_sequencia,
           rep.codigo AS reprova_codigo, rep.familia AS reprova_familia,
           rep.descricao AS reprova_descricao, rep.local AS reprova_local
    $fromSql
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$registros = $stmt->fetchAll();

// ─── Reprovas de Pintura/Serigrafia abertas (para identificação de peças mistas) ─
$nsList = array_values(array_filter(array_unique(array_column($registros, 'ns_transformador'))));
$pinturaReprovasPorNs = [];
if ($nsList) {
    $phNs = implode(',', array_fill(0, count($nsList), '?'));
    $stmtPin = $pdo->prepare("
        SELECT r.ns_transformador, rep.codigo, rep.descricao, rep.familia
        FROM retrabalhos r
        JOIN reprovas rep ON rep.id = r.id_reprova
        WHERE r.ns_transformador IN ($phNs)
          AND r.deleted_at IS NULL
          AND r.status IN ('agu_abertura', 'agu_causa_raiz')
          AND (rep.familia IN ('PINTURA', 'SERIGRAFIA', 'CAMADA') OR r.setores_destino LIKE '%pintura%')
    ");
    $stmtPin->execute($nsList);
    foreach ($stmtPin->fetchAll(PDO::FETCH_ASSOC) as $pin) {
        $pinturaReprovasPorNs[$pin['ns_transformador']][] = $pin;
    }
}

// ─── Reincidências: quantas vezes cada N° de série já apareceu no retrabalho ───
// Reprovas de uma mesma triagem (mesmo id_lote) contam como 1 única ocorrência —
// só a quantidade de reprovas (qtdRegistros) soma cada uma individualmente.
$qtdPorNs = $pdo->query("
    SELECT ns_transformador, COUNT(DISTINCT COALESCE(id_lote, id)) AS qtd
    FROM retrabalhos
    WHERE deleted_at IS NULL AND estacao IN ('LAB', 'IQF')
    GROUP BY ns_transformador
")->fetchAll(PDO::FETCH_KEY_PAIR);

// ─── Dias úteis em retrabalho / flag de urgência ───────────────────────────────
// Contado em dias úteis (seg-sex) a partir da data de início do retrabalho (não da reprova).
$hoje        = new DateTime('today');
$RANK_FLAG   = ['vermelho' => 4, 'laranja' => 3, 'amarelo' => 2, 'verde' => 1];
$RANK_STATUS = ['agu_abertura' => 2, 'agu_causa_raiz' => 1, 'finalizado' => 0];
// "finalizado" (causa raiz documentada ou aprovado na reinspeção do Laboratório)
// é o status encerrado.
$STATUS_ENCERRADOS = ['finalizado'];
foreach ($registros as &$r) {
    $r['_qtdReprovas']     = $qtdPorNs[$r['ns_transformador']] ?? 1;
    $r['_dataPcp']         = buscarDataPcpPorNs((string) $r['ns_transformador']);
    $r['_pinturaReprovas'] = $pinturaReprovasPorNs[$r['ns_transformador']] ?? [];
    $encerrado = in_array($r['status'], $STATUS_ENCERRADOS, true);
    // Usa a data da reprova (quando falhou) ou a data de criação do registro no sistema
    $baseData = !empty($r['data_reprova']) ? $r['data_reprova'] : $r['created_at'];
    $dias = null;
    if ($baseData) {
        $ini = new DateTime(substr((string) $baseData, 0, 10));
        if ($encerrado && $r['concluido_em']) {
            $fim  = new DateTime(substr((string) $r['concluido_em'], 0, 10));
            $dias = diasUteisEntre($ini, $fim);
        } elseif (!$encerrado) {
            $dias = diasUteisEntre($ini, $hoje);
        }
    }
    $r['_dias'] = $dias;

    // Flag por faixa de dias em retrabalho (só para os em aberto):
    // verde 1-3 · amarelo 4-5 · laranja 6-10 · vermelho 11+
    $corFlag = null;
    if (!$encerrado && $dias !== null) {
        if ($dias <= 3)      $corFlag = 'verde';
        elseif ($dias <= 5)  $corFlag = 'amarelo';
        elseif ($dias <= 10) $corFlag = 'laranja';
        else                 $corFlag = 'vermelho';
    }
    $r['_flagCor'] = $corFlag;
    $r['_flagRank']   = $RANK_FLAG[$corFlag] ?? 0;
    $r['_statusRank'] = $RANK_STATUS[$r['status']] ?? 0;
}
unset($r);

// ─── Agrupamento por N° de Série ──────────────────────────────────────────────
$grupos = [];
foreach ($registros as $r) {
    $gid = (string) $r['ns_transformador'];
    if ($gid === '') $gid = 's_ns_' . $r['id'];

    if (!isset($grupos[$gid])) {
        [$potencia, $classe] = parsePotenciaClasse($r['projeto_descricao'] ?? null);
        
        $ns_prio = $r['prioridade'] ?? 'neutro';
        $pr_prio = $r['projeto_prioridade'] ?? 'neutro';
        $ped_prio = $r['pedido_prioridade'] ?? 'neutro';
        
        $eff_prio = 'neutro';
        if ($ns_prio !== 'neutro') {
            $eff_prio = $ns_prio;
        } elseif ($pr_prio !== 'neutro') {
            $eff_prio = $pr_prio;
        } else {
            $eff_prio = $ped_prio;
        }

        // Data mais antiga do grupo (para FIFO)
        $baseDataGrupo = !empty($r['data_reprova']) ? $r['data_reprova'] : $r['created_at'];

        $grupos[$gid] = [
            'ns_transformador'  => $r['ns_transformador'],
            'id_projeto'        => $r['id_projeto'] ?? 0,
            'projeto_codigo'    => $r['projeto_codigo'],
            'projeto_descricao' => $r['projeto_descricao'],
            'pedido_numero'     => $r['pedido_numero'],
            'prioridade'        => $eff_prio,
            'potencia'          => $potencia,
            'classe'            => $classe,
            'itens'             => [],
            '_flagRank'         => 0,
            '_statusRank'       => 0,
            '_dias'             => -1,
            '_maxId'            => 0,
            '_dataPcp'          => null,
            '_qtdReprovas'      => $r['_qtdReprovas'] ?? 1,
            '_dataFifo'         => $baseDataGrupo,
            '_pinturaReprovas'  => $r['_pinturaReprovas'] ?? [],
        ];
    }
    $g = &$grupos[$gid];
    $g['itens'][]     = $r;
    $g['_flagRank']   = max($g['_flagRank'], $r['_flagRank']);
    $g['_statusRank'] = max($g['_statusRank'], $r['_statusRank']);
    $g['_dias']       = max($g['_dias'], $r['_dias'] ?? -1);
    $g['_maxId']      = max($g['_maxId'], (int) $r['id']);
    // Manter a data FIFO mais antiga do grupo
    $baseDataItem = !empty($r['data_reprova']) ? $r['data_reprova'] : $r['created_at'];
    if ($baseDataItem !== null && ($g['_dataFifo'] === null || $baseDataItem < $g['_dataFifo'])) {
        $g['_dataFifo'] = $baseDataItem;
    }
    if ($r['_dataPcp'] !== null && ($g['_dataPcp'] === null || $r['_dataPcp'] < $g['_dataPcp'])) {
        $g['_dataPcp'] = $r['_dataPcp'];
    }
    unset($g);
}
foreach ($grupos as &$g) {
    usort($g['itens'], function (array $a, array $b): int {
        $cmp = strcmp((string) ($b['data_reprova'] ?? ''), (string) ($a['data_reprova'] ?? ''));
        return $cmp !== 0 ? $cmp : ($b['id'] <=> $a['id']);
    });
    $g['qtdRegistros'] = count($g['itens']);
}
unset($g);
$grupos = array_values($grupos);

// ─── Ordenação padrão: Prioridade (desc) → FIFO por data (asc) ────────────────
const RT_PRIORIDADE_RANK = ['emergente' => 4, 'urgente' => 3, 'importante' => 2, 'neutro' => 1];

/** Valor de um grupo (N° série) usado para ordenar a listagem principal. */
function rtGroupSortValue(array $g, string $col): string|int
{
    return match ($col) {
        'data_pcp'    => (string) ($g['_dataPcp'] ?? ''),
        'prioridade'  => RT_PRIORIDADE_RANK[$g['prioridade'] ?? ''] ?? 0,
        'pedido'      => (string) ($g['pedido_numero'] ?? ''),
        'projeto'     => (string) ($g['projeto_codigo'] ?? ''),
        'ns'          => (string) ($g['ns_transformador'] ?? ''),
        'potencia'    => $g['potencia'] !== null ? (int) round((float) $g['potencia']) : -1,
        'classe'      => $g['classe']   !== null ? (int) round((float) $g['classe'])   : -1,
        'registros'   => $g['qtdRegistros'],
        'repetencias' => $g['_qtdReprovas'],
        'dias'        => $g['_dias'],
        'flags'       => $g['_flagRank'],
        'status'      => $g['_statusRank'],
        // Padrão (sem coluna clicada): Prioridade primeiro, FIFO depois
        default       => 0,
    };
}

usort($grupos, function (array $a, array $b) use ($sortCol, $sortDir): int {
    if ($sortCol === '' || $sortCol === 'default') {
        // Ordenação padrão: 1° Prioridade desc, 2° FIFO por data asc
        $prioA = RT_PRIORIDADE_RANK[$a['prioridade'] ?? ''] ?? 0;
        $prioB = RT_PRIORIDADE_RANK[$b['prioridade'] ?? ''] ?? 0;
        $cmp = $prioB <=> $prioA; // desc (emergente primeiro)
        if ($cmp !== 0) return $cmp;
        // Empate de prioridade: FIFO (data mais antiga primeiro)
        return strcmp((string) ($a['_dataFifo'] ?? '9999'), (string) ($b['_dataFifo'] ?? '9999'));
    }
    // Ordenação por clique em coluna
    $va = rtGroupSortValue($a, $sortCol);
    $vb = rtGroupSortValue($b, $sortCol);
    $cmp = is_int($va) ? ($va <=> $vb) : strcasecmp((string) $va, (string) $vb);
    if ($sortDir === 'desc') $cmp = -$cmp;
    return $cmp !== 0 ? $cmp : ($a['_maxId'] <=> $b['_maxId']);
});

// ─── Numeração da posição na fila (1°, 2°, 3°...) ─────────────────────────────
foreach ($grupos as $k => &$g) {
    $g['_posicaoFila'] = $k + 1;
}
unset($g);

// ─── Paginação (sobre os grupos já ordenados) ──────────────────────────────────
$totalRegistros = count($registros);
$totalGrupos    = count($grupos);
$totalPaginas   = max(1, (int) ceil($totalGrupos / $porPagina));
if ($pagina > $totalPaginas) $pagina = $totalPaginas;
$offset       = ($pagina - 1) * $porPagina;
$gruposPagina = array_slice($grupos, $offset, $porPagina);

// ─── Listas auxiliares (filtros + modal) ───────────────────────────────────────
$pedidos  = $pdo->query("SELECT id, numero FROM pedidos WHERE deleted_at IS NULL ORDER BY numero")->fetchAll();
$projetos = $pdo->query("SELECT id, codigo, descricao, id_pedido FROM projetos WHERE deleted_at IS NULL ORDER BY codigo")->fetchAll();
$reprovas = $pdo->query("
    SELECT id, codigo, familia, descricao, local 
    FROM reprovas 
    WHERE ativo = 1 
      AND familia NOT IN ('PINTURA', 'SERIGRAFIA', 'CAMADA')
    ORDER BY ordem, LENGTH(codigo), codigo
")->fetchAll();

$familiasDisponiveis = $pdo->query("
    SELECT DISTINCT familia FROM reprovas
    WHERE ativo = 1 AND familia IS NOT NULL AND familia <> ''
      AND familia NOT IN ('PINTURA', 'SERIGRAFIA', 'CAMADA')
    ORDER BY familia
")->fetchAll(PDO::FETCH_COLUMN);

$mesesDisponiveis = $pdo->query("
    SELECT DISTINCT DATE_FORMAT(COALESCE(r.data_reprova, r.data_inicio, r.created_at), '%Y-%m') AS ym
    FROM retrabalhos r
    JOIN reprovas rep ON rep.id = r.id_reprova
    WHERE r.deleted_at IS NULL
      AND rep.familia NOT IN ('PINTURA', 'SERIGRAFIA', 'CAMADA')
      AND (r.setores_destino NOT LIKE '%pintura%' OR r.setores_destino IS NULL)
    ORDER BY ym DESC
")->fetchAll(PDO::FETCH_COLUMN);

$MESES_PT = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho',
             7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];

// ─── Helpers de exibição ──────────────────────────────────────────────────────
$statusMap = [
    'agu_abertura'   => ['label' => 'Agu. Abertura',   'bg' => '#fef2f2', 'fg' => '#dc2626'],
    'agu_causa_raiz' => ['label' => 'Agu. Causa Raiz', 'bg' => '#fffbeb', 'fg' => '#d97706'],
    'finalizado'     => ['label' => 'Finalizado',      'bg' => '#ecfdf5', 'fg' => '#16a34a'],
];
$localMap = [
    'IQF' => ['label' => 'IQF', 'title' => 'Inspeção final', 'bg' => '#eff6ff', 'fg' => '#2563eb'],
    'LAB' => ['label' => 'LAB', 'title' => 'Laboratório',    'bg' => '#f5f3ff', 'fg' => '#7c3aed'],
    'GER' => ['label' => 'GER', 'title' => 'Geral',          'bg' => '#f0fdf4', 'fg' => '#16a34a'],
];
$respMap = [
    'Laboratório'    => ['bg' => '#f5f3ff', 'fg' => '#7c3aed'],
    'Inspeção Final' => ['bg' => '#eff6ff', 'fg' => '#2563eb'],
    'Retrabalho'     => ['bg' => '#fff7ed', 'fg' => '#ea580c'],
];
$prioridadesInfo = pedidoPrioridades();

function fmtDataBR(?string $iso): string
{
    if (!$iso) return '—';
    $ts = strtotime($iso);
    return $ts ? date('d/m/y', $ts) : '—';
}

/** Monta uma URL desta página preservando os filtros atuais, com overrides pontuais. */
function rtUrl(array $overrides = []): string
{
    global $base;
    $params = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($params[$k]);
        else $params[$k] = $v;
    }
    $qs = http_build_query($params);
    return htmlspecialchars($base . '/pages/retrabalho/relacao.php' . ($qs !== '' ? '?' . $qs : ''));
}

/** Renderiza um <th> clicável que ordena pela coluna, com setinha indicando a direção ativa. */
function rtSortTh(string $label, string $key): void
{
    global $sortCol, $sortDir;
    $ativo = $sortCol === $key;
    $prox  = ($ativo && $sortDir === 'desc') ? 'asc' : 'desc';
    $seta  = $ativo ? ($sortDir === 'desc' ? ' &#9660;' : ' &#9650;') : '';
    echo '<th><a class="rt-th-link' . ($ativo ? ' active' : '') . '" href="'
       . rtUrl(['sort' => $key, 'dir' => $prox, 'pagina' => 1]) . '">'
       . htmlspecialchars($label) . $seta . '</a></th>';
}

$temFiltroAtivo = $fBusca !== '' || $fLocal !== '' || $fFamilia !== '' || $fMes !== ''
    || (isset($_GET['status_touched']) && $fStatus !== ['agu_abertura', 'agu_causa_raiz']);

$pageTitle = 'Relação de Retrabalhos';
require_once __DIR__ . '/../../includes/layout.php';
layoutHeader($pageTitle);
?>

<style>
    .rt-tabs { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:18px; }
    .rt-tab {
        display:inline-flex; align-items:center; padding:8px 16px; border-radius:9999px;
        font-size:13px; font-weight:600; text-decoration:none; border:1px solid var(--color-border,#e5e7eb);
        background:#fff; color:var(--color-text-secondary,#5a6480);
    }
    .rt-tab:hover { text-decoration:none; border-color:#E89B1C; color:#E89B1C; }
    .rt-tab.active { background:#E89B1C; border-color:#E89B1C; color:#0e2c1d; }
    .rt-card { background:var(--color-surface,#fff); border:1px solid var(--color-border,#e5e7eb); border-radius:var(--radius-lg,10px); padding:16px 18px; }
    .rt-card h3 { font-size:14px; font-weight:600; margin-bottom:4px; color:var(--color-text-primary,#111827); display:flex; align-items:center; gap:8px; }
    .rt-card h3 svg { width:16px; height:16px; color:#E89B1C; }
    .rt-filtros { display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between; margin:14px 0; }
    .rt-filtros-left, .rt-filtros-right { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
    .rt-filtros select, .rt-filtros input[type=search] { padding:8px 10px; border:1px solid var(--color-border,#d1d5db); border-radius:8px; font-size:13px; background:#fff; }
    .rt-check-row { display:flex; align-items:center; gap:12px; flex-wrap:wrap; font-size:12px; color:var(--color-text-secondary,#5a6480); }
    .rt-check-row label { display:flex; align-items:center; gap:5px; cursor:pointer; white-space:nowrap; }
    .rt-check-row .lbl { font-weight:600; color:var(--color-text-primary,#1a2133); }
    .rt-table { width:100%; border-collapse:collapse; font-size:13px; }
    #rt-table-wrap { overflow-x:auto; overflow-y:auto; max-height:calc(100vh - 280px); max-height:calc(100dvh - 280px); }
    .rt-table thead th { position:sticky; top:0; z-index:10; background:#f8fafc; box-shadow:0 1px 2px rgba(0,0,0,0.05); }
    .rt-table th { text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.6px; color:var(--color-text-muted,#6b7280); padding:8px 10px; border-bottom:1px solid var(--color-border,#e5e7eb); white-space:nowrap; }
    .rt-th-link { color:inherit; text-decoration:none; }
    .rt-th-link:hover { color:#E89B1C; text-decoration:none; }
    .rt-th-link.active { color:#1a3d2a; font-weight:700; }
    .rt-table td { padding:9px 10px; border-bottom:1px solid var(--color-border,#f1f5f9); vertical-align:middle; }
    .rt-table th:nth-child(3), .rt-table td:nth-child(3),
    .rt-table th:nth-child(5), .rt-table td:nth-child(5),
    .rt-table th:nth-child(6), .rt-table td:nth-child(6),
    .rt-table th:nth-child(7), .rt-table td:nth-child(7),
    .rt-table th:nth-child(9), .rt-table td:nth-child(9),
    .rt-table th:nth-child(10), .rt-table td:nth-child(10) { text-align:center; }
    .rt-table tr:hover td { background:var(--color-surface-2,#f9fafb); }
    .rt-code { font-family:'JetBrains Mono',monospace; font-weight:600; }
    .rt-badge { display:inline-block; padding:2px 9px; border-radius:9999px; font-size:11px; font-weight:600; white-space:nowrap; }
    .rt-btn-acc { background:#E89B1C; color:#0e2c1d; border:none; padding:9px 16px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; }
    .rt-btn-acc:hover { opacity:.92; text-decoration:none; color:#0e2c1d; }
    .rt-btn-secondary { background:#fff; border:1px solid #d1d5db; border-radius:8px; padding:9px 16px; font-size:13px; font-weight:600; cursor:pointer; text-decoration:none; color:inherit; display:inline-flex; align-items:center; }
    .rt-btn-secondary:hover { background:#f9fafb; text-decoration:none; }
    .rt-flag-dot { display:inline-block; width:9px; height:9px; border-radius:50%; cursor:help; }
    .rt-flag-dot.verde    { background:#16a34a; box-shadow:0 0 0 2px #dcfce7; }
    .rt-flag-dot.amarelo  { background:#eab308; box-shadow:0 0 0 2px #fef9c3; }
    .rt-flag-dot.laranja  { background:#f97316; box-shadow:0 0 0 2px #ffedd5; }
    .rt-flag-dot.vermelho { background:#dc2626; box-shadow:0 0 0 2px #fee2e2; }
    .rt-edit-btn { background:#E89B1C; border:1px solid #E89B1C; border-radius:6px; padding:6px 12px; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; color:#fff; text-decoration:none; font-size:12px; font-weight:600; white-space:nowrap; }
    .rt-edit-btn:hover { background:#cf8710; border-color:#cf8710; color:#fff; text-decoration:none; }
    .rt-btn-chegada { background:#16a34a; border:1px solid #16a34a; border-radius:8px; padding:6px 12px; display:inline-flex; align-items:center; gap:6px; cursor:pointer; color:#fff; text-decoration:none; font-size:12px; font-weight:600; white-space:nowrap; }
    .rt-btn-chegada:hover { background:#15803d; border-color:#15803d; color:#fff; text-decoration:none; }
    .rt-btn-chegada svg { width:14px; height:14px; }
    .rt-del-btn { background:none; border:1px solid var(--color-border,#e5e7eb); border-radius:6px; width:28px; height:28px; padding:0; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; color:var(--color-text-muted,#9aa3b8); }
    .rt-del-btn svg { width:14px; height:14px; }
    .rt-del-btn:hover { border-color:#dc2626; color:#dc2626; background:#fef2f2; }
    .rt-toggle-btn { background:#fff; border:1px solid var(--color-border,#e5e7eb); border-radius:6px; width:24px; height:24px; padding:0; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; font-size:15px; font-weight:700; line-height:1; color:var(--color-text-secondary,#5a6480); }
    .rt-toggle-btn:hover { border-color:#E89B1C; color:#E89B1C; }
    .rt-detail-row { display:none; }
    .rt-detail-row.is-open { display:table-row; }
    .rt-detail-wrap { background:var(--color-surface-2,#f9fafb); border-radius:8px; padding:8px 10px; margin:2px 0; }
    .rt-subtable { width:100%; border-collapse:collapse; font-size:12px; }
    .rt-subtable th { text-align:left; font-size:9px; text-transform:uppercase; letter-spacing:.5px; color:var(--color-text-muted,#6b7280); padding:6px 8px; border-bottom:1px solid var(--color-border,#e5e7eb); white-space:nowrap; }
    .rt-subtable td { padding:7px 8px; border-bottom:1px solid var(--color-border,#eef1f5); vertical-align:middle; white-space:nowrap; }
    .rt-subtable tr:last-child td { border-bottom:none; }
    .rt-empty { text-align:center; padding:36px 16px; color:var(--color-text-muted,#6b7280); font-size:13px; }
    .rt-pager { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px; margin-top:16px; padding-top:14px; border-top:1px solid var(--color-border,#e5e7eb); font-size:12px; color:var(--color-text-secondary,#5a6480); }
    .rt-pager-left { display:flex; align-items:center; gap:8px; }
    .rt-pager-left select { padding:5px 8px; border:1px solid var(--color-border,#d1d5db); border-radius:6px; font-size:12px; }
    /* Modal (reaproveitado do painel) */
    .modal-overlay { position:fixed; inset:0; background:rgba(15,23,42,.5); display:none; align-items:flex-start; justify-content:center; z-index:1000; padding:30px 16px; overflow-y:auto; }
    .modal-box { background:#fff; border-radius:14px; width:100%; max-width:660px; box-shadow:0 20px 50px rgba(0,0,0,.3); }
    .modal-head { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #e5e7eb; }
    .modal-head h2 { font-size:16px; font-weight:700; }
    .modal-close { background:none; border:none; font-size:22px; line-height:1; cursor:pointer; color:#9ca3af; }
    .modal-body { padding:18px 20px; }
    .rt-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
    .rt-form-grid .full { grid-column:1 / -1; }
    .rt-section { grid-column:1 / -1; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.7px; color:#1a3d2a; margin:4px 0 -4px; display:flex; align-items:center; gap:10px; }
    .rt-section::after { content:""; flex:1; height:1px; background:#e5e7eb; }
    .rt-field label { display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:4px; }
    .rt-field input, .rt-field select, .rt-field textarea { width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:8px; font-size:13px; }
    .rt-field textarea { resize:vertical; min-height:60px; }
    .rt-field.auto input { background:#f3f6f4; color:#374151; border-style:dashed; }
    .rt-hint { font-size:11px; color:#6b7280; margin-top:4px; }
    .modal-foot { display:flex; justify-content:flex-end; gap:10px; padding:16px 20px; border-top:1px solid #e5e7eb; }
</style>

<!-- Container de Página com Rolagem Exclusiva na Tabela -->
<div class="page-fixed-layout">

    <!-- Cabeçalho -->
    <div class="page-fixed-header">
        <h1 style="font-size:var(--font-size-xl,20px);font-weight:700;margin-top:2px;">Relação de Retrabalhos</h1>
        <p class="text-secondary" style="font-size:13px;color:var(--color-text-secondary,#6b7280);margin-top:2px;">
            Listagem detalhada de todos os retrabalhos, com filtros e paginação
        </p>
    </div>

    <!-- Abas por local -->
    <div class="rt-tabs">
        <a class="rt-tab<?= $fLocal === '' ? ' active' : '' ?>" href="<?= rtUrl(['local' => null, 'pagina' => 1]) ?>">Todos</a>
        <?php foreach ($localMap as $k => $info): ?>
            <a class="rt-tab<?= $fLocal === $k ? ' active' : '' ?>" href="<?= rtUrl(['local' => $k, 'pagina' => 1]) ?>"><?= htmlspecialchars($info['label']) ?> — <?= htmlspecialchars($info['title']) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="rt-card">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
        <h3 style="margin:0;display:flex;align-items:center;gap:8px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
            Retrabalhos
        </h3>
        <span id="rt-autorefresh-indicador" style="font-size:11px;color:var(--color-text-muted,#9aa3b8);white-space:nowrap;"></span>
    </div>

    <!-- Filtros -->
    <form method="GET" class="filter-bar" id="rt-filtros">
        <input type="hidden" name="local" value="<?= htmlspecialchars($fLocal) ?>">
        <input type="hidden" name="status_touched" value="1">

        <div class="filter-group-left">
            <div class="filter-search-wrap">
                <svg class="filter-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="search" name="busca" class="filter-search-input" value="<?= htmlspecialchars($fBusca) ?>" placeholder="Buscar projeto, pedido, NS, reprova, responsável…">
            </div>
            <select name="mes" class="filter-select" onchange="this.form.submit()">
                <option value="">Mês…</option>
                <?php foreach ($mesesDisponiveis as $ym): if (!$ym) continue;
                    $lbl = ($MESES_PT[(int) substr($ym, 5, 2)] ?? $ym) . '/' . substr($ym, 0, 4);
                ?>
                    <option value="<?= htmlspecialchars($ym) ?>" <?= $fMes === $ym ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="familia" class="filter-select" onchange="this.form.submit()">
                <option value="">Família…</option>
                <?php foreach ($familiasDisponiveis as $fam): ?>
                    <option value="<?= htmlspecialchars($fam) ?>" <?= $fFamilia === $fam ? 'selected' : '' ?>><?= htmlspecialchars($fam) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="filter-btn filter-btn-secondary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                Filtrar
            </button>
            <?php if ($temFiltroAtivo): ?>
                <a href="<?= htmlspecialchars($base) ?>/pages/retrabalho/relacao.php" class="filter-btn filter-btn-clear" title="Limpar filtros">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    Limpar
                </a>
            <?php endif; ?>
            <button type="button" id="btn-toggle-all-rt" class="filter-btn btn-expand-all" aria-expanded="false" title="Expandir ou recolher todas as linhas">
                <svg class="ico-expand" viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
                <span class="lbl-expand">Expandir Todos</span>
            </button>
        </div>

        <div class="filter-group-right">
            <div class="rt-check-row">
                <span class="lbl">Mostrar:</span>
                <?php foreach ($STATUS_VALIDOS as $k): $info = $statusMap[$k]; ?>
                    <label>
                        <input type="checkbox" name="status[]" value="<?= $k ?>" onchange="this.form.submit()" <?= in_array($k, $fStatus, true) ? 'checked' : '' ?>>
                        <?= htmlspecialchars($info['label']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
    </form>

    <div style="overflow-x:auto;" id="rt-table-wrap">
        <table class="rt-table" style="min-width:1400px;">
            <thead>
                <tr>
                    <?php
                    echo '<th style="width:36px;text-align:center;"><button type="button" class="btn-expand-col js-toggle-all-quick" title="Expandir/Recolher todos" style="cursor:pointer;border:1px solid #cbd5e1;border-radius:4px;background:#f8fafc;color:#475569;font-weight:700;font-size:13px;width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;padding:0;line-height:1;">⤢</button></th>';
                    rtSortTh('Prioridade', 'prioridade');
                    echo '<th>#</th>';
                    rtSortTh('N° Série', 'ns');
                    rtSortTh('Pedido', 'pedido');
                    rtSortTh('Projeto', 'projeto');
                    rtSortTh('Potência', 'potencia');
                    rtSortTh('Classe', 'classe');
                    rtSortTh('Status', 'status');
                    rtSortTh('Parado há', 'dias');
                    rtSortTh('Flags', 'flags');
                    rtSortTh('Reincidências', 'repetencias');
                    echo '<th style="text-align:right;">Ações</th>';
                    ?>
                </tr>
            </thead>
            <tbody>
                <?php if (!$gruposPagina): ?>
                    <tr><td colspan="13"><div class="rt-empty">Nenhum retrabalho encontrado para os filtros selecionados.</div></td></tr>
                <?php else: foreach ($gruposPagina as $g):
                    $gPrio = $prioridadesInfo[$g['prioridade']] ?? null;
                    $itemPrincipal = $g['itens'][0]; // Mais crítico/recente
                ?>
                    <tr class="rt-group-row">
                        <td>
                            <button type="button" class="rt-toggle-btn js-toggle-rt" data-target="rt-det-<?= (int)$itemPrincipal['id'] ?>" aria-expanded="false" title="Mostrar reprovas">+</button>
                        </td>
                        <td style="text-align:center;">
                            <?php if ($gPrio): ?>
                                <span class="rt-badge" style="background:<?= $gPrio['bg'] ?>;color:<?= $gPrio['fg'] ?>;" title="<?= htmlspecialchars($gPrio['label']) ?>"><?= htmlspecialchars($gPrio['label']) ?></span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td style="text-align:center;"><span style="font-size:12px;color:var(--color-text-muted,#6b7280);font-weight:600;"><?= $g['_posicaoFila'] ?>°</span></td>
                        <td><span class="rt-code" style="font-weight:700;color:#111827;"><?= htmlspecialchars($g['ns_transformador'] ?? '—') ?></span></td>
                        <td><?= htmlspecialchars($g['pedido_numero'] ?? '—') ?></td>
                        <td><span class="rt-code"><?= htmlspecialchars($g['projeto_codigo'] ?? '—') ?></span></td>
                        <td><?= $g['potencia'] !== null ? htmlspecialchars($g['potencia']) . ' kVA' : '—' ?></td>
                        <td><?= $g['classe'] !== null ? htmlspecialchars($g['classe']) . ' kV' : '—' ?></td>
                        
                        <?php 
                            $st = $statusMap[$itemPrincipal['status']] ?? $statusMap['agu_abertura'];
                            $diasTxt = '—';
                            $diasClass = '';
                            if ($g['_dias'] >= 0) {
                                if (in_array($itemPrincipal['status'], ['finalizado'], true)) {
                                    $diasTxt = $g['_dias'] . 'd (concluído)';
                                } else {
                                    $diasTxt = $g['_dias'] . 'd';
                                    $diasClass = $g['_dias'] > $DIAS_FLAG ? 'prazo-atraso' : ($g['_dias'] >= 7 ? 'prazo-risco' : 'prazo-ok');
                                }
                            }
                        ?>
                        <td style="text-align:center;vertical-align:middle;"><span class="rt-badge" style="background:<?= $st['bg'] ?>;color:<?= $st['fg'] ?>;"><?= $st['label'] ?></span></td>
                        <td class="<?= $diasClass ?>" style="text-align:center;vertical-align:middle;font-size:12px;font-weight:600;"><?= htmlspecialchars($diasTxt) ?></td>
                        
                        <td style="text-align:center;vertical-align:middle;">
                            <?php if ($itemPrincipal['_flagCor']): ?>
                                <span class="rt-flag-dot <?= $itemPrincipal['_flagCor'] ?>" title="<?= $g['_dias'] ?> dia(s) útil(eis) em retrabalho"></span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        
                        <td style="text-align:center;vertical-align:middle;">
                            <?php if ($g['_qtdReprovas'] > 1): ?>
                                <span class="rt-badge" style="background:#fef2f2;color:#dc2626;" title="Este N° de série já apareceu <?= (int) $g['_qtdReprovas'] ?> vezes no retrabalho"><?= (int) $g['_qtdReprovas'] ?></span>
                            <?php else: ?>—<?php endif; ?>
                        </td>

                        <td style="text-align:right;vertical-align:middle;">
                            <?php if ($itemPrincipal['data_chegada'] === null): ?>
                                <a class="rt-btn-chegada" href="<?= htmlspecialchars($base) ?>/pages/retrabalho/confirmar-chegada.php?id=<?= (int) $itemPrincipal['id'] ?>&origem=retrabalho" title="Confirmar chegada">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                    Aguardando chegada
                                </a>
                            <?php elseif ($itemPrincipal['data_inicio'] === null): ?>
                                <a class="rt-edit-btn" href="<?= htmlspecialchars($base) ?>/pages/retrabalho/iniciar-triagem.php?id=<?= (int) $itemPrincipal['id'] ?>&origem=retrabalho" title="Leitura de início da triagem">
                                    Triagem
                                </a>
                            <?php else: ?>
                                <a class="rt-edit-btn" href="<?= htmlspecialchars($base) ?>/pages/retrabalho/detalhe.php?id=<?= (int) $itemPrincipal['id'] ?>&origem=retrabalho" title="Ver / editar triagem">
                                    Triagem
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr class="rt-detail-row" id="rt-det-<?= (int)$itemPrincipal['id'] ?>" style="display:none;">
                        <td colspan="13">
                            <div class="rt-detail-wrap" style="padding:16px; background:#f9fafb; border-bottom:1px solid #eef1f5;">
                                <table class="rt-subtable" style="width:100%; font-size:13px; border-collapse:collapse;">
                                    <thead>
                                        <tr style="border-bottom:1px solid #e5e7eb; color:#6b7280; font-size:11px; text-transform:uppercase;">
                                            <th style="padding:8px;text-align:left;">Contenção</th>
                                            <th style="padding:8px;text-align:left;">Família</th>
                                            <th style="padding:8px;text-align:left;">Responsável</th>
                                            <th style="padding:8px;text-align:center;">Data Reprova</th>
                                            <th style="padding:8px;text-align:right;"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($g['itens'] as $r): 
                                            $respNome = $r['responsavel_nome'] ?? '—';
                                            $respStyle = $respMap[$respNome] ?? ['bg' => '#f3f4f6', 'fg' => '#374151'];
                                        ?>
                                            <tr style="border-bottom:1px solid #eef1f5;">
                                                <td style="padding:8px;">
                                                    <span class="rt-code" style="font-weight:500;"><?= htmlspecialchars($r['reprova_codigo'] ?? '—') ?></span>
                                                    <?php if (!empty($r['reprova_descricao'])): ?>
                                                        <div style="font-size:11px;color:#6b7280;"><?= htmlspecialchars($r['reprova_descricao']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="padding:8px;"><?= htmlspecialchars($r['reprova_familia'] ?? '—') ?></td>
                                                <td style="padding:8px;text-align:left;">
                                                    <span class="rt-badge" style="background:<?= $respStyle['bg'] ?>;color:<?= $respStyle['fg'] ?>;"><?= htmlspecialchars($respNome) ?></span>
                                                </td>
                                                <td style="padding:8px;text-align:center;"><?= htmlspecialchars(fmtDataBR($r['data_reprova'])) ?></td>
                                                <td style="padding:8px;text-align:right;">
                                                    <button type="button" class="btn-icon btn-icon-danger btn-icon-sm js-del-reprova" data-id="<?= (int) $r['id'] ?>" title="Excluir esta reprova">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Paginação -->
    <div class="rt-pager">
        <div class="rt-pager-left">
            <span>Exibir</span>
            <select onchange="location.href=this.value">
                <?php foreach ($PORPAGINA_OPCOES as $opt): ?>
                    <option value="<?= rtUrl(['porPagina' => $opt, 'pagina' => 1]) ?>" <?= $porPagina === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                <?php endforeach; ?>
            </select>
            <span>por página · <?= $totalGrupos ?> projeto<?= $totalGrupos === 1 ? '' : 's' ?> · <?= $totalRegistros ?> registro<?= $totalRegistros === 1 ? '' : 's' ?></span>
        </div>
        <div class="pagination">
            <a class="page-btn<?= $pagina <= 1 ? ' disabled' : '' ?>" href="<?= $pagina > 1 ? rtUrl(['pagina' => 1]) : '#' ?>" style="text-decoration:none;<?= $pagina <= 1 ? 'opacity:.4;pointer-events:none;' : '' ?>">&laquo;</a>
            <a class="page-btn<?= $pagina <= 1 ? ' disabled' : '' ?>" href="<?= $pagina > 1 ? rtUrl(['pagina' => $pagina - 1]) : '#' ?>" style="text-decoration:none;<?= $pagina <= 1 ? 'opacity:.4;pointer-events:none;' : '' ?>">&lsaquo;</a>
            <?php
            $janela = 2;
            $ini = max(1, $pagina - $janela);
            $fim = min($totalPaginas, $pagina + $janela);
            for ($p = $ini; $p <= $fim; $p++):
            ?>
                <a class="page-btn<?= $p === $pagina ? ' active' : '' ?>" href="<?= rtUrl(['pagina' => $p]) ?>" style="text-decoration:none;"><?= $p ?></a>
            <?php endfor; ?>
            <a class="page-btn<?= $pagina >= $totalPaginas ? ' disabled' : '' ?>" href="<?= $pagina < $totalPaginas ? rtUrl(['pagina' => $pagina + 1]) : '#' ?>" style="text-decoration:none;<?= $pagina >= $totalPaginas ? 'opacity:.4;pointer-events:none;' : '' ?>">&rsaquo;</a>
            <a class="page-btn<?= $pagina >= $totalPaginas ? ' disabled' : '' ?>" href="<?= $pagina < $totalPaginas ? rtUrl(['pagina' => $totalPaginas]) : '#' ?>" style="text-decoration:none;<?= $pagina >= $totalPaginas ? 'opacity:.4;pointer-events:none;' : '' ?>">&raquo;</a>
    </div>
</div>
</div>

<!-- Modal: Registrar / Editar retrabalho -->
<div class="modal-overlay" id="rt-modal">
    <div class="modal-box">
        <form id="rt-form">
            <div class="modal-head">
                <h2 id="rt-modal-title">Registrar retrabalho</h2>
                <button type="button" class="modal-close" id="rt-modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id" id="f-id" value="">
                <div id="rt-form-erro" style="display:none;background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:8px;padding:8px 12px;font-size:13px;margin-bottom:14px;"></div>
                <div class="rt-form-grid">

                    <div class="rt-section">Projeto &amp; transformador</div>
                    <div class="rt-field">
                        <label for="f-pedido">Pedido *</label>
                        <select name="id_pedido" id="f-pedido" required>
                            <option value="">Selecione o pedido…</option>
                            <?php foreach ($pedidos as $p): ?>
                                <option value="<?= (int) $p['id'] ?>"><?= htmlspecialchars($p['numero']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rt-field">
                        <label for="f-projeto">Projeto *</label>
                        <select name="id_projeto" id="f-projeto" required>
                            <option value="">Selecione o pedido primeiro…</option>
                        </select>
                    </div>
                    <div class="rt-field full">
                        <label for="f-ns">N° de série do transformador *</label>
                        <input type="text" name="ns_transformador" id="f-ns" placeholder="Ex.: NS-2026-00841" required>
                        <div class="rt-hint">Único no sistema — 1 NS pertence a 1 projeto. Repetido em outro projeto é bloqueado.</div>
                    </div>

                    <div class="rt-section">Reprova / contenção</div>
                    <div class="rt-field">
                        <label for="f-reprova">Código de reprova *</label>
                        <select name="id_reprova" id="f-reprova" required>
                            <option value="">Selecione…</option>
                            <?php foreach ($reprovas as $rp): ?>
                                <option value="<?= (int) $rp['id'] ?>"><?= htmlspecialchars($rp['codigo'] . ' — ' . $rp['descricao']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rt-field">
                        <label for="f-data_reprova">Data da reprova</label>
                        <input type="date" name="data_reprova" id="f-data_reprova" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="rt-field full auto">
                        <label for="f-rep-descricao">Descrição da contenção</label>
                        <input type="text" id="f-rep-descricao" placeholder="— selecione o código —" readonly>
                    </div>
                    <div class="rt-field auto">
                        <label for="f-rep-familia">Família da contenção</label>
                        <input type="text" id="f-rep-familia" placeholder="—" readonly>
                    </div>
                    <div class="rt-field auto">
                        <label for="f-rep-local">Local</label>
                        <input type="text" id="f-rep-local" placeholder="—" readonly>
                    </div>

                    <div class="rt-section">Retrabalho</div>
                    <div class="rt-field">
                        <label for="f-data_inicio">Data de início do retrabalho</label>
                        <input type="date" name="data_inicio" id="f-data_inicio">
                    </div>
                    <div class="rt-field">
                        <label for="f-data_finalizacao">Data de finalização do retrabalho</label>
                        <input type="date" name="data_finalizacao" id="f-data_finalizacao">
                        <div class="rt-hint">Ao preencher, o status passa para "Agu. Causa Raiz".</div>
                    </div>
                    <div class="rt-field full">
                        <label for="f-causa_raiz">Causa Raiz</label>
                        <textarea name="causa_raiz" id="f-causa_raiz" placeholder="Descreva a causa raiz"></textarea>
                        <div class="rt-hint">Ao preencher, o status passa para "Finalizado".</div>
                    </div>
                    <div class="rt-field full">
                        <label for="f-observacoes">Observações</label>
                        <textarea name="observacoes" id="f-observacoes" placeholder="Detalhes adicionais (opcional)"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="rt-btn-secondary" id="rt-modal-cancel">Cancelar</button>
                <button type="submit" class="rt-btn-acc" id="rt-form-submit">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
    window.RETRABALHO_API = <?= json_encode($base . '/api/retrabalho-acao.php') ?>;
    window.RETRABALHO_PROJETOS = <?= json_encode($projetos, JSON_UNESCAPED_UNICODE) ?>;
    window.RETRABALHO_REPROVAS = <?= json_encode($reprovas, JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php $rtJsVer = @filemtime(__DIR__ . '/../../assets/js/retrabalho.js') ?: (defined('APP_VERSION') ? APP_VERSION : '1'); ?>
<script src="<?= htmlspecialchars($base) ?>/assets/js/retrabalho.js?v=<?= htmlspecialchars((string) $rtJsVer) ?>"></script>

<style>
.btn-expand-all {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 6px 14px;
    border: 1.5px solid #cbd5e1;
    border-radius: 6px;
    background: #ffffff;
    color: #334155;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    user-select: none;
    transition: all 0.15s ease;
}
.btn-expand-all:hover {
    background: #f8fafc;
    border-color: #94a3b8;
    color: #0f172a;
}
.btn-expand-all.is-active {
    background: #fff7ed;
    border-color: #ea580c;
    color: #9a3412;
}
.rt-toggle-btn {
    width: 24px; height: 24px; border: 1px solid #d1d5db; background: #fff; color: #374151; border-radius: 4px; font-weight: bold; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; padding: 0; line-height: 1; transition: all 0.2s;
}
.rt-toggle-btn:hover { background: #f3f4f6; }
</style>
<script>
(function() {
    const STORAGE_KEY_ALL = 'sgt_relacao_expand_all';
    const STORAGE_KEY_ROWS = 'sgt_relacao_open_rows';

    function getOpenRows() {
        try {
            return JSON.parse(localStorage.getItem(STORAGE_KEY_ROWS) || '[]');
        } catch (e) {
            return [];
        }
    }

    function saveOpenRows(rows) {
        localStorage.setItem(STORAGE_KEY_ROWS, JSON.stringify(rows));
    }

    function syncHeaderButtons(expandAll) {
        const btnAll = document.getElementById('btn-toggle-all-rt');
        if (btnAll) {
            btnAll.setAttribute('aria-expanded', expandAll ? 'true' : 'false');
            btnAll.classList.toggle('is-active', expandAll);
            const lbl = btnAll.querySelector('.lbl-expand');
            if (lbl) lbl.textContent = expandAll ? 'Recolher Todos' : 'Expandir Todos';
        }
        const quickBtn = document.querySelector('.js-toggle-all-quick');
        if (quickBtn) {
            quickBtn.textContent = expandAll ? '−' : '⤢';
            quickBtn.title = expandAll ? 'Recolher todos' : 'Expandir todos';
        }
    }

    function aplicarEstado() {
        const expandAll = localStorage.getItem(STORAGE_KEY_ALL) === 'true';
        const openRows = getOpenRows();
        syncHeaderButtons(expandAll);

        document.querySelectorAll('.js-toggle-rt').forEach(function(btn) {
            const targetId = btn.getAttribute('data-target');
            const targetRow = document.getElementById(targetId);
            if (!targetRow) return;

            const shouldOpen = expandAll || openRows.includes(targetId);
            btn.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
            btn.textContent = shouldOpen ? '−' : '+';
            targetRow.style.display = shouldOpen ? 'table-row' : 'none';
        });
    }

    function setAllRows(expand) {
        localStorage.setItem(STORAGE_KEY_ALL, expand ? 'true' : 'false');
        if (!expand) {
            saveOpenRows([]);
        }
        aplicarEstado();
    }

    document.addEventListener('click', function(e) {
        const btnAll = e.target.closest('#btn-toggle-all-rt') || e.target.closest('.js-toggle-all-quick');
        if (btnAll) {
            const isCurrentlyExpanded = localStorage.getItem(STORAGE_KEY_ALL) === 'true';
            setAllRows(!isCurrentlyExpanded);
            return;
        }

        const btn = e.target.closest('.js-toggle-rt');
        if (!btn) return;
        
        const targetId = btn.getAttribute('data-target');
        const targetRow = document.getElementById(targetId);
        if (!targetRow) return;
        
        const isExpanded = btn.getAttribute('aria-expanded') === 'true';
        const nextState = !isExpanded;
        btn.setAttribute('aria-expanded', nextState ? 'true' : 'false');
        btn.textContent = nextState ? '−' : '+';
        targetRow.style.display = nextState ? 'table-row' : 'none';

        let openRows = getOpenRows();
        if (nextState) {
            if (!openRows.includes(targetId)) openRows.push(targetId);
        } else {
            openRows = openRows.filter(id => id !== targetId);
            localStorage.setItem(STORAGE_KEY_ALL, 'false');
            syncHeaderButtons(false);
        }
        saveOpenRows(openRows);
    });

    window.sgtAplicarExpansao = aplicarEstado;

    document.addEventListener('DOMContentLoaded', function() {
        aplicarEstado();
    });
})();
</script>
<?php layoutFooter(); ?>
