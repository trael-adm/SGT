<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireAcessoModulo('retrabalho');

$pdo  = getDB();
$base = defined('APP_URL') ? APP_URL : '';

// ─── Data Atual (Timezone Cuiabá) ───────────────────────────────────────────
$tz   = new DateTimeZone('America/Cuiaba');
$hoje = new DateTime('today', $tz);
$dataHojeFormatada = (new DateTime('now', $tz))->format('d/m/Y');
$dataHojeIso = (new DateTime('now', $tz))->format('Y-m-d');
$mesAtualIso = (new DateTime('now', $tz))->format('Y-m');
$mesAnteriorIso = (new DateTime('first day of last month', $tz))->format('Y-m');

// ─── Filtros (GET) ───────────────────────────────────────────────────────────
$fEstacao = trim((string) ($_GET['estacao'] ?? ''));
$fStatus  = trim((string) ($_GET['status'] ?? 'em_andamento')); // 'em_andamento' | 'finalizado' | 'todos'
if (!in_array($fStatus, ['em_andamento', 'ativos', 'finalizado', 'todos'], true)) {
    $fStatus = 'em_andamento';
}
$fBusca   = trim((string) ($_GET['busca'] ?? ''));

$ESTACOES_VALIDAS = ['LAB', 'IQF', 'GER'];
if (!in_array($fEstacao, $ESTACOES_VALIDAS, true)) $fEstacao = '';

// ─── Filtro de Data Avançado (Hoje | Mês | Personalizável) ───────────────────
$tipoData = trim((string) ($_GET['tipo_data'] ?? ''));
$fDataHoje = trim((string) ($_GET['data_hoje'] ?? $dataHojeIso));
$fDataMes  = trim((string) ($_GET['data_mes'] ?? $mesAtualIso));
$fDataDe   = trim((string) ($_GET['data_de'] ?? ''));
$fDataAte  = trim((string) ($_GET['data_ate'] ?? ''));
$fDatasEsp = trim((string) ($_GET['datas_especificas'] ?? ''));

// Rótulo da data para o cabeçalho (padrão se nenhum filtro de data: data de hoje)
$labelDataExibicao = $dataHojeFormatada;
$dataExpr = 'DATE(COALESCE(r.data_reprova, r.data_inicio, r.created_at))';

// Condição de status e reprova
$where = [
    'r.deleted_at IS NULL',
    'r.id_reprova IS NOT NULL',
];
$params = [];

if ($fStatus === 'em_andamento' || $fStatus === 'ativos') {
    $where[] = "(
        r.`status` IN ('agu_chegada', 'agu_abertura', 'agu_causa_raiz')
        OR EXISTS (
            SELECT 1 FROM producao_etapas pe
            WHERE pe.ns_transformador = r.ns_transformador
              AND pe.id_projeto = r.id_projeto
              AND pe.status = 'aguardando_retorno'
              AND pe.deleted_at IS NULL
        )
    )";
} elseif ($fStatus === 'finalizado') {
    $where[] = "r.`status` = 'finalizado' AND NOT EXISTS (
        SELECT 1 FROM producao_etapas pe
        WHERE pe.ns_transformador = r.ns_transformador
          AND pe.id_projeto = r.id_projeto
          AND pe.status = 'aguardando_retorno'
          AND pe.deleted_at IS NULL
    )";
}

if ($fEstacao !== '') {
    $where[] = '(r.estacao = ? OR rep.local = ?)';
    $params[] = $fEstacao;
    $params[] = $fEstacao;
}

if ($fBusca !== '') {
    $where[] = '(pr.codigo LIKE ? OR ped.numero LIKE ? OR r.ns_transformador LIKE ? OR rep.codigo LIKE ? OR rep.descricao LIKE ?)';
    $like = '%' . $fBusca . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

// Aplicação dos Filtros de Data
if ($tipoData === 'hoje') {
    $where[] = "$dataExpr = ?";
    $params[] = $dataHojeIso;
    $labelDataExibicao = "Hoje ({$dataHojeFormatada})";
} elseif ($tipoData === 'mes') {
    $mesValido = preg_match('/^\d{4}-\d{2}$/', $fDataMes) ? $fDataMes : $mesAtualIso;
    $where[] = "DATE_FORMAT($dataExpr, '%Y-%m') = ?";
    $params[] = $mesValido;
    $dtMesObj = DateTime::createFromFormat('Y-m', $mesValido, $tz);
    $mesAnoFormatado = $dtMesObj ? $dtMesObj->format('m/Y') : $mesValido;
    $labelDataExibicao = "Mês: {$mesAnoFormatado}";
} elseif ($tipoData === 'intervalo') {
    if ($fDataDe !== '' && $fDataAte !== '') {
        $where[] = "$dataExpr BETWEEN ? AND ?";
        $params[] = $fDataDe;
        $params[] = $fDataAte;
        $dtDeFmt = date('d/m/y', strtotime($fDataDe));
        $dtAteFmt = date('d/m/y', strtotime($fDataAte));
        $labelDataExibicao = "{$dtDeFmt} a {$dtAteFmt}";
    } elseif ($fDataDe !== '') {
        $where[] = "$dataExpr >= ?";
        $params[] = $fDataDe;
        $dtDeFmt = date('d/m/y', strtotime($fDataDe));
        $labelDataExibicao = "A partir de {$dtDeFmt}";
    } elseif ($fDataAte !== '') {
        $where[] = "$dataExpr <= ?";
        $params[] = $fDataAte;
        $dtAteFmt = date('d/m/y', strtotime($fDataAte));
        $labelDataExibicao = "Até {$dtAteFmt}";
    }
} elseif ($tipoData === 'especificas') {
    $listaDatas = array_values(array_filter(array_map('trim', explode(',', $fDatasEsp)), fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)));
    if (!empty($listaDatas)) {
        $ph = implode(',', array_fill(0, count($listaDatas), '?'));
        $where[] = "$dataExpr IN ($ph)";
        foreach ($listaDatas as $d) $params[] = $d;
        if (count($listaDatas) === 1) {
            $labelDataExibicao = date('d/m/Y', strtotime($listaDatas[0]));
        } else {
            $labelDataExibicao = count($listaDatas) . ' datas sel.';
        }
    }
}

$whereSql = implode(' AND ', $where);

$sql = "
    SELECT r.id,
           r.id_lote,
           r.ns_transformador,
           r.data_reprova,
           r.data_chegada,
           r.data_inicio,
           r.data_finalizacao,
           r.created_at,
           r.`status`,
           r.estacao,
           pr.codigo AS projeto_codigo,
           pr.descricao AS projeto_descricao,
           ped.numero AS pedido_numero,
           rep.codigo AS reprova_codigo,
           rep.descricao AS reprova_descricao,
           rep.familia AS reprova_familia,
           rep.local AS reprova_local
    FROM retrabalhos r
    LEFT JOIN projetos pr  ON pr.id  = r.id_projeto
    LEFT JOIN pedidos ped  ON ped.id = pr.id_pedido
    LEFT JOIN reprovas rep ON rep.id = r.id_reprova
    WHERE $whereSql
    ORDER BY COALESCE(r.data_reprova, r.created_at) ASC, r.id ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rawRegistros = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ─── Processamento e Cálculos de Negócio ─────────────────────────────────────
$registros = [];
$motivosCount = [];
$pecasUnicas = [];

foreach ($rawRegistros as $row) {
    // Data da reprova de referência
    $rawDate = $row['data_reprova'] ?: $row['created_at'];
    $dtReprova = null;
    $dataReprovaFormatada = '—';
    $diasUteis = 0;

    if ($rawDate) {
        try {
            $dtReprova = new DateTime((string) $rawDate, $tz);
            $dtReprova->setTime(0, 0, 0);
            $dataReprovaFormatada = $dtReprova->format('d/m/Y');
            $diasUteis = diasUteisEntre($dtReprova, $hoje);
        } catch (\Exception $e) {
            $diasUteis = 0;
        }
    }

    $isForaPrazo = ($diasUteis > 5);
    $statusSla = $isForaPrazo ? 'Fora do Prazo' : 'Dentro do Prazo';

    $motivoDesc = trim((string) ($row['reprova_descricao'] ?: ($row['reprova_codigo'] ?: 'NÃO INFORMADO')));
    $motivoDescUpper = mb_strtoupper($motivoDesc, 'UTF-8');
    $motivosCount[$motivoDescUpper] = ($motivosCount[$motivoDescUpper] ?? 0) + 1;

    // Consolidação de Transformador/Peça física única para KPIs
    $chavePeca = trim((string) ($row['ns_transformador'] ?? ''));
    if ($chavePeca === '') {
        $chavePeca = !empty($row['id_lote']) ? 'LOTE_' . $row['id_lote'] : 'ID_' . $row['id'];
    }

    if (!isset($pecasUnicas[$chavePeca])) {
        $pecasUnicas[$chavePeca] = [
            'dias_uteis'    => $diasUteis,
            'is_fora_prazo' => $isForaPrazo,
        ];
    } else {
        // Se a peça possui múltiplas reprovas, considera o maior tempo parado (reprova mais antiga)
        if ($diasUteis > $pecasUnicas[$chavePeca]['dias_uteis']) {
            $pecasUnicas[$chavePeca]['dias_uteis'] = $diasUteis;
            $pecasUnicas[$chavePeca]['is_fora_prazo'] = $isForaPrazo;
        }
    }

    $row['dias_uteis'] = $diasUteis;
    $row['status_sla'] = $statusSla;
    $row['is_fora_prazo'] = $isForaPrazo;
    $row['data_reprova_fmt'] = $dataReprovaFormatada;
    $row['motivo_fmt'] = $motivoDescUpper;
    $row['ns_fmt'] = (string) ($row['ns_transformador'] ?: ($row['id_lote'] ? 'Lote #' . $row['id_lote'] : '—'));
    $row['projeto_fmt'] = (string) ($row['projeto_codigo'] ?: '—');

    $registros[] = $row;
}

// ─── Consolidação e Cálculo do Custo Total de Materiais Utilizados ───────────
$lotesUnicos = [];
foreach ($rawRegistros as $r) {
    $idL = !empty($r['id_lote']) ? (int) $r['id_lote'] : null;
    if ($idL) {
        $lotesUnicos[$idL] = true;
    }
}
$lotesIds = array_keys($lotesUnicos);

$custoTotalMateriais = 0.0;
$totalItensMateriais = 0;
$custoPorLote = [];

if (!empty($lotesIds)) {
    $ph = implode(',', array_fill(0, count($lotesIds), '?'));
    try {
        $stmtCusto = $pdo->prepare("
            SELECT rmu.id_lote,
                   COALESCE(SUM(rmu.quantidade * COALESCE(rmu.preco_medio, 0)), 0) AS custo_lote,
                   COUNT(*) AS total_itens
            FROM retrabalho_material_uso rmu
            WHERE rmu.id_lote IN ($ph)
            GROUP BY rmu.id_lote
        ");
        $stmtCusto->execute($lotesIds);
        foreach ($stmtCusto->fetchAll(PDO::FETCH_ASSOC) as $rowC) {
            $idL = (int) $rowC['id_lote'];
            $cVal = (float) $rowC['custo_lote'];
            $custoPorLote[$idL] = $cVal;
            $custoTotalMateriais += $cVal;
            $totalItensMateriais += (int) $rowC['total_itens'];
        }
    } catch (\Throwable $e) {}
}

// Vincula o custo do lote a cada registro para filtros dinâmicos
foreach ($registros as &$r) {
    $idL = !empty($r['id_lote']) ? (int) $r['id_lote'] : 0;
    $r['custo_reprova'] = $custoPorLote[$idL] ?? 0.0;
}
unset($r);

$custoTotalFormatado = 'R$ ' . number_format($custoTotalMateriais, 2, ',', '.');

// ─── Ordenação da Planilha: Sempre pelo dia útil mais antigo primeiro ────────
usort($registros, function ($a, $b) {
    if ($a['dias_uteis'] !== $b['dias_uteis']) {
        return $b['dias_uteis'] <=> $a['dias_uteis']; // Maior dias_uteis primeiro
    }
    return strcmp((string) ($a['data_reprova'] ?? ''), (string) ($b['data_reprova'] ?? ''));
});

// ─── Métricas dos Cards: Consolidadas por Transformador/Peça Única ──────────
$totalReprovados = count($pecasUnicas);
$totalDiasUteisPecas = array_sum(array_column($pecasUnicas, 'dias_uteis'));
$totalMais5Dias = count(array_filter($pecasUnicas, fn($p) => !empty($p['is_fora_prazo'])));

$mediaDias = $totalReprovados > 0 ? ($totalDiasUteisPecas / $totalReprovados) : 0.0;
$mediaDiasFormatada = number_format($mediaDias, 1, ',', '.');
$mais5DiasExibicao = $totalMais5Dias > 0 ? (string) $totalMais5Dias : '--';

// ─── Ordenação dos Principais Motivos (Decrescente) ─────────────────────────
arsort($motivosCount);
$chartMotivosLabels = array_keys($motivosCount);
$chartMotivosValues = array_values($motivosCount);

// ─── Status Diário: Histórico de Quantidade de Transformadores Reprovados ────
// Busca todo o histórico de reprovações para calcular o backlog de transformadores únicos em cada dia
$sqlHist = "
    SELECT r.id,
           r.ns_transformador,
           r.id_lote,
           DATE(COALESCE(r.data_reprova, r.data_inicio, r.created_at)) AS dt_inicio,
           CASE 
               WHEN r.`status` = 'finalizado' AND NOT EXISTS (
                   SELECT 1 FROM producao_etapas pe
                   WHERE pe.ns_transformador = r.ns_transformador
                     AND pe.id_projeto = r.id_projeto
                     AND pe.status = 'aguardando_retorno'
                     AND pe.deleted_at IS NULL
               ) THEN DATE(COALESCE(r.concluido_em, r.data_finalizacao, r.updated_at))
               ELSE NULL 
           END AS dt_fim
    FROM retrabalhos r
    LEFT JOIN reprovas rep ON rep.id = r.id_reprova
    WHERE r.deleted_at IS NULL
      AND r.id_reprova IS NOT NULL
";
$paramsHist = [];
if ($fEstacao !== '') {
    $sqlHist .= " AND (r.estacao = ? OR rep.local = ?)";
    $paramsHist[] = $fEstacao;
    $paramsHist[] = $fEstacao;
}
$stmtHist = $pdo->prepare($sqlHist);
$stmtHist->execute($paramsHist);
$todosHistoricos = $stmtHist->fetchAll(PDO::FETCH_ASSOC);

// Determinar as datas a serem analisadas no gráfico diário
$datasParaAnalise = [];

if ($tipoData === 'intervalo' && $fDataDe !== '' && $fDataAte !== '') {
    try {
        $dtIni = new DateTime($fDataDe, $tz);
        $dtFim = new DateTime($fDataAte, $tz);
        if ($dtIni <= $dtFim) {
            $cur = clone $dtIni;
            while ($cur <= $dtFim && count($datasParaAnalise) < 31) {
                if ((int) $cur->format('N') !== 7) {
                    $datasParaAnalise[] = clone $cur;
                }
                $cur->modify('+1 day');
            }
        }
    } catch (\Throwable $e) {}
} elseif ($tipoData === 'especificas' && !empty($listaDatas)) {
    sort($listaDatas);
    foreach ($listaDatas as $dIso) {
        try {
            $datasParaAnalise[] = new DateTime($dIso, $tz);
        } catch (\Throwable $e) {}
    }
} elseif ($tipoData === 'mes') {
    try {
        $mesValido = preg_match('/^\d{4}-\d{2}$/', $fDataMes) ? $fDataMes : $mesAtualIso;
        $dtIni = new DateTime($mesValido . '-01', $tz);
        $dtFim = clone $dtIni;
        $dtFim->modify('last day of this month');
        $cur = clone $dtIni;
        while ($cur <= $dtFim && count($datasParaAnalise) < 31) {
            if ((int) $cur->format('N') !== 7) {
                $datasParaAnalise[] = clone $cur;
            }
            $cur->modify('+1 day');
        }
    } catch (\Throwable $e) {}
}

if (empty($datasParaAnalise)) {
    // Padrão: últimos 10 dias operacionais até hoje
    $cursor = clone $hoje;
    $diasColetados = [];
    $maxIter = 30;
    while (count($diasColetados) < 10 && $maxIter > 0) {
        $maxIter--;
        if ((int) $cursor->format('N') !== 7) {
            $diasColetados[] = clone $cursor;
        }
        $cursor->modify('-1 day');
    }
    $datasParaAnalise = array_reverse($diasColetados);
}

$chartDiarioLabels = [];
$chartDiarioValues = [];

foreach ($datasParaAnalise as $dtItem) {
    $diaIso = $dtItem->format('Y-m-d');
    $diaLabel = $dtItem->format('d/m/y');
    
    $pecasAtivasNoDia = [];
    foreach ($todosHistoricos as $h) {
        $inicio = $h['dt_inicio'];
        $fim = $h['dt_fim'];
        
        // Estava reprovado se iniciou em ou antes de $diaIso E não havia finalizado antes/neste dia
        if ($inicio && $inicio <= $diaIso) {
            if ($fim === null || $fim > $diaIso) {
                $chavePeca = trim((string) ($h['ns_transformador'] ?? ''));
                if ($chavePeca === '') {
                    $chavePeca = !empty($h['id_lote']) ? 'LOTE_' . $h['id_lote'] : 'ID_' . $h['id'];
                }
                $pecasAtivasNoDia[$chavePeca] = true;
            }
        }
    }
    
    $chartDiarioLabels[] = $diaLabel;
    $chartDiarioValues[] = count($pecasAtivasNoDia);
}

// Se estiver vazio (ex.: base zerada), provê dados neutros para renderizar o gráfico
if (empty($chartMotivosLabels)) {
    $chartMotivosLabels = ['Sem registros'];
    $chartMotivosValues = [0];
}
if (empty($chartDiarioLabels)) {
    $chartDiarioLabels = [date('d/m/y')];
    $chartDiarioValues = [0];
}

$pageTitle = 'Dashboard de Reprovas';
require_once __DIR__ . '/../../includes/layout.php';
layoutHeader($pageTitle);
?>

<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@500;700&display=swap">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
/* ==========================================================================
   DASHBOARD DE REPROVAS - RETRABALHO
   ========================================================================== */
:root {
    --dash-red: #e50914;
    --dash-red-dark: #b8050e;
    --dash-red-light: #fef2f2;
    --dash-red-border: #ef4444;
    --dash-card-bg: #ffffff;
    --dash-text-main: #0f172a;
    --dash-text-muted: #64748b;
}

.dash-page-wrapper {
    max-width: 1760px;
    width: 100%;
    margin: 0 auto;
    padding: 10px 18px 40px;
    font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
    color: var(--dash-text-main);
}

/* ─── Top Control Bar & Filters ──────────────────────────────────────────── */
.dash-top-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    background: #ffffff;
    border: 1px solid #fee2e2;
    border-radius: 16px;
    padding: 14px 20px;
    box-shadow: 0 4px 20px rgba(229, 9, 20, 0.04);
    margin-bottom: 20px;
}

.dash-header-title {
    display: flex;
    align-items: center;
    gap: 12px;
}

.dash-header-title h1 {
    font-size: 30px;
    font-weight: 900;
    color: var(--dash-red);
    letter-spacing: 0.04em;
    margin: 0;
    line-height: 1;
    text-transform: uppercase;
    display: flex;
    align-items: center;
}

.dash-date-filter-wrapper {
    position: relative;
    margin-left: auto;
}

.dash-header-date-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    height: 38px;
    padding: 0 14px;
    background: #ffffff;
    border: 1.5px solid var(--dash-red);
    border-radius: 8px;
    font-size: 14px;
    font-weight: 800;
    color: var(--dash-red);
    font-family: 'JetBrains Mono', monospace;
    cursor: pointer;
    transition: all 0.2s ease;
    box-shadow: 0 2px 6px rgba(229, 9, 20, 0.08);
}

.dash-header-date-btn:hover,
.dash-header-date-btn.is-active-btn {
    background: var(--dash-red-light);
    border-color: var(--dash-red-dark);
    box-shadow: 0 4px 12px rgba(229, 9, 20, 0.18);
    transform: translateY(-1px);
}

.dash-header-date-btn.is-filtered {
    background: #fef2f2;
    border-color: var(--dash-red);
    box-shadow: 0 0 0 3px rgba(229, 9, 20, 0.15);
}

.dash-date-popover {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    z-index: 100;
    width: 360px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 20px 40px rgba(15, 23, 42, 0.18);
    padding: 16px;
    animation: datePopoverIn 0.18s ease;
}

@keyframes datePopoverIn {
    from { opacity: 0; transform: translateY(-6px); }
    to { opacity: 1; transform: translateY(0); }
}

.ddp-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-bottom: 10px;
    border-bottom: 1px solid #f1f5f9;
    margin-bottom: 12px;
}

.ddp-title {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    font-weight: 800;
    color: #0f172a;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.ddp-close {
    background: none;
    border: none;
    font-size: 20px;
    line-height: 1;
    color: #94a3b8;
    cursor: pointer;
    padding: 0 4px;
}
.ddp-close:hover { color: #0f172a; }

.ddp-tabs {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 6px;
    background: #f1f5f9;
    padding: 4px;
    border-radius: 8px;
    margin-bottom: 14px;
}

.ddp-tab {
    background: transparent;
    border: none;
    padding: 7px 4px;
    font-size: 12px;
    font-weight: 700;
    color: #64748b;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.15s ease;
    text-align: center;
}

.ddp-tab.is-active {
    background: #ffffff;
    color: var(--dash-red);
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.06);
}

.ddp-hoje-box {
    text-align: center;
    background: #fef2f2;
    border: 1px solid #fee2e2;
    border-radius: 8px;
    padding: 14px 10px;
    margin-bottom: 14px;
}

.ddp-hoje-label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #991b1b; }
.ddp-hoje-val { display: block; font-size: 20px; font-weight: 900; color: var(--dash-red); font-family: 'JetBrains Mono', monospace; margin: 4px 0 6px; }
.ddp-hint { font-size: 11px; color: #64748b; margin: 0; line-height: 1.35; }

.ddp-field { margin-bottom: 12px; }
.ddp-label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #475569; margin-bottom: 5px; }

.ddp-shortcuts {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 14px;
}

.ddp-chip {
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 4px 8px;
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    cursor: pointer;
    transition: all 0.15s ease;
}

.ddp-chip:hover {
    border-color: var(--dash-red);
    color: var(--dash-red);
    background: #fef2f2;
}

.ddp-submode-toggle {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 1px dashed #e2e8f0;
}

.ddp-radio-label {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 700;
    color: #334155;
    cursor: pointer;
}

.ddp-range-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}

.ddp-tags-wrap {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    min-height: 34px;
    max-height: 90px;
    overflow-y: auto;
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    border-radius: 8px;
    padding: 6px;
    margin-top: 8px;
    margin-bottom: 12px;
}

.ddp-date-tag {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 4px;
    padding: 2px 6px;
    font-size: 11px;
    font-weight: 700;
    font-family: 'JetBrains Mono', monospace;
    color: #0f172a;
}

.ddp-date-tag button {
    background: none;
    border: none;
    color: #ef4444;
    cursor: pointer;
    font-weight: 800;
    padding: 0 2px;
    line-height: 1;
}

.ddp-footer {
    border-top: 1px solid #f1f5f9;
    padding-top: 10px;
    margin-top: 12px;
    text-align: center;
}

.ddp-btn-clear {
    background: none;
    border: none;
    color: #64748b;
    font-size: 11.5px;
    font-weight: 700;
    text-decoration: underline;
    cursor: pointer;
}
.ddp-btn-clear:hover { color: var(--dash-red); }

.dash-custo-card-top {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    height: 38px;
    padding: 0 14px;
    background: #fef2f2;
    border: 1.5px solid var(--dash-red);
    border-radius: 8px;
    box-shadow: 0 2px 6px rgba(229, 9, 20, 0.08);
    transition: all 0.2s ease;
}
.dash-custo-card-top:hover {
    box-shadow: 0 4px 12px rgba(229, 9, 20, 0.16);
    transform: translateY(-1px);
}
.dash-custo-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--dash-red);
}
.dash-custo-texts {
    display: flex;
    flex-direction: column;
    justify-content: center;
    line-height: 1.15;
}
.dash-custo-label {
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    color: #991b1b;
    letter-spacing: 0.03em;
}
.dash-custo-val {
    font-size: 15px;
    font-weight: 900;
    color: var(--dash-red);
    font-family: 'JetBrains Mono', monospace;
}

.dash-filter-form {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
}

.dash-select, .dash-input {
    height: 38px;
    padding: 0 12px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    color: #1e293b;
    background-color: #ffffff;
    transition: all 0.2s ease;
}
.dash-select:focus, .dash-input:focus {
    outline: none;
    border-color: var(--dash-red);
    box-shadow: 0 0 0 3px rgba(229, 9, 20, 0.15);
}

.btn-dash {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 38px;
    padding: 0 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
    border: none;
}

.btn-dash-primary {
    background: var(--dash-red);
    color: #ffffff;
    box-shadow: 0 2px 8px rgba(229, 9, 20, 0.25);
}
.btn-dash-primary:hover {
    background: var(--dash-red-dark);
    transform: translateY(-1px);
}

.btn-dash-secondary {
    background: #f8fafc;
    color: #475569;
    border: 1px solid #e2e8f0;
}
.btn-dash-secondary:hover {
    background: #f1f5f9;
    color: #0f172a;
}

.dash-live-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    height: 28px;
    padding: 0 12px;
    background: #fef2f2;
    border: 1px solid #fee2e2;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    color: var(--dash-red);
    line-height: 1;
}

.dash-pulse-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--dash-red);
    animation: pulseDot 1.8s infinite;
}

@keyframes pulseDot {
    0% { transform: scale(0.9); box-shadow: 0 0 0 0 rgba(229, 9, 20, 0.7); }
    70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(229, 9, 20, 0); }
    100% { transform: scale(0.9); box-shadow: 0 0 0 0 rgba(229, 9, 20, 0); }
}

/* ─── Dashboard Main Grid ────────────────────────────────────────────────── */
.dash-main-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 265px;
    gap: 20px;
    align-items: stretch;
    width: 100%;
    min-width: 0;
}

.dash-content-area {
    display: flex;
    flex-direction: column;
    gap: 20px;
    min-width: 0;
    width: 100%;
}

.dash-split-row {
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 20px;
    align-items: start;
    min-width: 0;
}

/* ─── Standard Dash Card ─────────────────────────────────────────────────── */
.dash-card {
    background: var(--dash-card-bg);
    border: 2px solid var(--dash-red);
    border-radius: 20px;
    padding: 16px 20px;
    box-shadow: 0 4px 16px rgba(229, 9, 20, 0.05);
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.dash-card-top {
    padding: 14px 20px;
}

.dash-card-header {
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    margin-bottom: 10px;
}

.dash-card-title {
    font-size: 18px;
    font-weight: 800;
    color: var(--dash-red);
    margin: 0;
    text-align: center;
    letter-spacing: -0.01em;
}

.dash-chart-container {
    position: relative;
    width: 100%;
    min-width: 0;
}

/* ─── Right KPI Column ───────────────────────────────────────────────────── */
.dash-kpi-column {
    display: flex;
    flex-direction: column;
    gap: 16px;
    background: var(--dash-red);
    border-radius: 20px;
    padding: 16px;
    box-shadow: 0 8px 30px rgba(229, 9, 20, 0.25);
    height: 100%;
}

.dash-kpi-card {
    background: transparent;
    border: 2px solid rgba(255, 255, 255, 0.6);
    border-radius: 16px;
    padding: 24px 16px;
    text-align: center;
    color: #ffffff;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    flex: 1;
    transition: all 0.25s ease;
}

.dash-kpi-card:hover {
    background: rgba(255, 255, 255, 0.08);
    border-color: #ffffff;
    transform: scale(1.02);
}

.dash-kpi-label {
    font-size: 18px;
    font-weight: 800;
    letter-spacing: -0.01em;
    margin-bottom: 12px;
    text-shadow: 0 1px 2px rgba(0,0,0,0.15);
}

.dash-kpi-value {
    font-size: 52px;
    font-weight: 900;
    line-height: 1;
    font-family: 'JetBrains Mono', sans-serif;
    letter-spacing: -0.03em;
    text-shadow: 0 2px 4px rgba(0,0,0,0.25);
}

/* ─── Planilha / Tabela de Reprovas ──────────────────────────────────────── */
.dash-table-wrapper {
    background: #ffffff;
    border: 2px solid #ef4444;
    border-radius: 20px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 4px 16px rgba(229, 9, 20, 0.05);
    min-width: 0;
    width: 100%;
    align-self: start;
}

.dash-table-scroll {
    max-height: 290px;
    overflow-y: auto;
    overflow-x: auto;
}

.dash-table-scroll::-webkit-scrollbar {
    width: 7px;
    height: 7px;
}
.dash-table-scroll::-webkit-scrollbar-track {
    background: #f1f5f9;
}
.dash-table-scroll::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
}
.dash-table-scroll::-webkit-scrollbar-thumb:hover {
    background: var(--dash-red);
}

.dash-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11.5px;
    text-align: left;
}

.dash-table th {
    background: var(--dash-red);
    color: #ffffff;
    font-weight: 800;
    padding: 10px 8px;
    white-space: nowrap;
    position: sticky;
    top: 0;
    z-index: 10;
    border: 1px solid var(--dash-red-dark);
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}

.dash-table td {
    padding: 8px 8px;
    border-bottom: 1px solid #fee2e2;
    color: #1e293b;
    font-weight: 600;
}

.dash-table .col-ns {
    white-space: nowrap;
    width: 95px;
}

.dash-table .col-projeto {
    white-space: nowrap;
    width: 105px;
}

.dash-table .col-motivo {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 160px;
}

.dash-table .col-data {
    white-space: nowrap;
    text-align: center;
    width: 100px;
}

.dash-table .col-dias {
    white-space: nowrap;
    text-align: center;
    width: 90px;
}

.dash-table .col-sla {
    white-space: nowrap;
    text-align: center;
    width: 115px;
}

.dash-table tr:nth-child(even) {
    background-color: #fffaf0;
}

.dash-table tr:hover {
    background-color: #fef2f2;
}

.dash-table tr.row-atrasada {
    background-color: #fff1f2;
}

.badge-sla {
    display: inline-flex;
    align-items: center;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 800;
    white-space: nowrap;
}

.badge-sla-ok {
    background: #e6f4ea;
    color: #137333;
    border: 1px solid #ceead6;
}

.badge-sla-alert {
    background: #fce8e6;
    color: #c5221f;
    border: 1px solid #fad2cf;
}

/* ─── Modo Tela Cheia (Fullscreen TV Display) ────────────────────────────── */
body.tv-mode {
    background: #f8fafc;
}
body.tv-mode .sidebar,
body.tv-mode .app-header {
    display: none !important;
}
body.tv-mode .app-main {
    width: 100% !important;
    max-width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
}
body.tv-mode .app-content {
    margin-top: 0 !important;
    padding: 10px 18px 24px !important;
    width: 100% !important;
    max-width: 100% !important;
}
body.tv-mode .dash-page-wrapper {
    max-width: 100% !important;
    padding: 0 !important;
}

/* ─── Responsividade ─────────────────────────────────────────────────────── */
@media (max-width: 1200px) {
    .dash-main-layout {
        grid-template-columns: 1fr;
    }
    .dash-kpi-column {
        flex-direction: row;
        flex-wrap: wrap;
    }
    .dash-kpi-card {
        min-width: 200px;
    }
    .dash-split-row {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="dash-page-wrapper" id="dashboardWrapper">

    <!-- Top Control Bar -->
    <div class="dash-top-bar">
        <div class="dash-header-title">
            <h1>REPROVAS</h1>
            <span class="dash-live-badge">
                <span class="dash-pulse-dot"></span>
                <span>Tempo Real</span>
            </span>
        </div>

        <!-- Formulário de Filtros -->
        <form method="GET" class="dash-filter-form" id="dashFilterForm">
            <!-- Preserva parâmetros de data -->
            <input type="hidden" name="tipo_data" id="formTipoData" value="<?= htmlspecialchars($tipoData) ?>">
            <input type="hidden" name="data_hoje" id="formDataHoje" value="<?= htmlspecialchars($fDataHoje) ?>">
            <input type="hidden" name="data_mes" id="formDataMes" value="<?= htmlspecialchars($fDataMes) ?>">
            <input type="hidden" name="data_de" id="formDataDe" value="<?= htmlspecialchars($fDataDe) ?>">
            <input type="hidden" name="data_ate" id="formDataAte" value="<?= htmlspecialchars($fDataAte) ?>">
            <input type="hidden" name="datas_especificas" id="formDatasEsp" value="<?= htmlspecialchars($fDatasEsp) ?>">

            <select name="status" class="dash-select" onchange="this.form.submit()">
                <option value="em_andamento" <?= ($fStatus === 'em_andamento' || $fStatus === 'ativos') ? 'selected' : '' ?>>Status: Em andamento</option>
                <option value="finalizado" <?= $fStatus === 'finalizado' ? 'selected' : '' ?>>Status: Finalizado</option>
                <option value="todos" <?= $fStatus === 'todos' ? 'selected' : '' ?>>Status: Todos</option>
            </select>

            <select name="estacao" class="dash-select" onchange="this.form.submit()">
                <option value="">Estação: Todas</option>
                <option value="LAB" <?= $fEstacao === 'LAB' ? 'selected' : '' ?>>Laboratório (LAB)</option>
                <option value="IQF" <?= $fEstacao === 'IQF' ? 'selected' : '' ?>>Inspeção Final (IQF)</option>
                <option value="GER" <?= $fEstacao === 'GER' ? 'selected' : '' ?>>Geral (GER)</option>
            </select>

            <?php if ($fEstacao || ($fStatus !== 'em_andamento' && $fStatus !== 'ativos') || $fBusca || $tipoData): ?>
                <a href="dashboard.php" class="btn-dash btn-dash-secondary" title="Limpar Todos os Filtros">Limpar</a>
            <?php endif; ?>

            <button type="button" class="btn-dash btn-dash-secondary" onclick="toggleFullscreen()" title="Alternar Modo Tela Cheia">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/></svg>
                <span>Tela Cheia</span>
            </button>

            <button type="button" class="btn-dash btn-dash-primary" onclick="window.location.reload()" title="Recarregar Dados">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                <span>Atualizar</span>
            </button>

            <!-- Card Topo: Contabilização de Custos em R$ -->
            <div class="dash-custo-card-top" title="Custo total de retrabalho dos materiais utilizados">
                <span class="dash-custo-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                </span>
                <div class="dash-custo-texts">
                    <span class="dash-custo-label">Custo de Retrabalho</span>
                    <span class="dash-custo-val" id="topCustoTotal"><?= $custoTotalFormatado ?></span>
                </div>
            </div>
        </form>

        <!-- Filtro Multi-selecionável de Data (Hoje | Mês | Personalizável) -->
        <div class="dash-date-filter-wrapper">
            <button type="button" class="dash-header-date-btn <?= $tipoData ? 'is-filtered' : '' ?>" id="btnDateFilterToggle" onclick="toggleDateFilterModal()" title="Clique para filtrar por data">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                <span id="dashDateLabelExibicao"><?= htmlspecialchars($labelDataExibicao) ?></span>
                <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="3"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </button>

            <!-- Popover de Seleção de Datas -->
            <div class="dash-date-popover" id="dashDatePopover" style="display:none;">
                <div class="ddp-header">
                    <div class="ddp-title">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                        <span>Filtro de Data</span>
                    </div>
                    <button type="button" class="ddp-close" onclick="toggleDateFilterModal(false)">&times;</button>
                </div>

                <div class="ddp-tabs">
                    <button type="button" class="ddp-tab <?= ($tipoData === 'hoje' || !$tipoData) ? 'is-active' : '' ?>" id="tabBtn_hoje" onclick="switchDateTab('hoje')">Hoje</button>
                    <button type="button" class="ddp-tab <?= $tipoData === 'mes' ? 'is-active' : '' ?>" id="tabBtn_mes" onclick="switchDateTab('mes')">Mês</button>
                    <button type="button" class="ddp-tab <?= in_array($tipoData, ['intervalo', 'especificas'], true) ? 'is-active' : '' ?>" id="tabBtn_personalizado" onclick="switchDateTab('personalizado')">Personalizável</button>
                </div>

                <!-- ABA 1: Hoje -->
                <div class="ddp-content" id="ddpTab_hoje" style="<?= ($tipoData === 'hoje' || !$tipoData) ? '' : 'display:none;' ?>">
                    <div class="ddp-hoje-box">
                        <span class="ddp-hoje-label">Data de Hoje</span>
                        <span class="ddp-hoje-val"><?= $dataHojeFormatada ?></span>
                        <p class="ddp-hint">Filtra os apontamentos registrados exclusivamente na data de hoje.</p>
                    </div>
                    <div class="ddp-actions">
                        <button type="button" class="btn-dash btn-dash-primary" onclick="aplicarFiltroData('hoje')" style="width:100%;justify-content:center;">
                            Aplicar Data de Hoje
                        </button>
                    </div>
                </div>

                <!-- ABA 2: Mês -->
                <div class="ddp-content" id="ddpTab_mes" style="<?= $tipoData === 'mes' ? '' : 'display:none;' ?>">
                    <div class="ddp-field">
                        <label class="ddp-label">Mês e Ano:</label>
                        <input type="month" id="ddpInputMes" class="dash-input" style="width:100%;" value="<?= htmlspecialchars($fDataMes ?: $mesAtualIso) ?>">
                    </div>
                    <div class="ddp-shortcuts">
                        <button type="button" class="ddp-chip" onclick="document.getElementById('ddpInputMes').value='<?= $mesAtualIso ?>'">Mês Atual (<?= date('m/Y') ?>)</button>
                        <button type="button" class="ddp-chip" onclick="document.getElementById('ddpInputMes').value='<?= $mesAnteriorIso ?>'">Mês Anterior</button>
                    </div>
                    <div class="ddp-actions">
                        <button type="button" class="btn-dash btn-dash-primary" onclick="aplicarFiltroData('mes')" style="width:100%;justify-content:center;">
                            Aplicar Mês
                        </button>
                    </div>
                </div>

                <!-- ABA 3: Personalizável (Intervalo ou Específicas) -->
                <div class="ddp-content" id="ddpTab_personalizado" style="<?= in_array($tipoData, ['intervalo', 'especificas'], true) ? '' : 'display:none;' ?>">
                    <div class="ddp-submode-toggle">
                        <label class="ddp-radio-label">
                            <input type="radio" name="ddp_submode" value="intervalo" <?= ($tipoData !== 'especificas') ? 'checked' : '' ?> onchange="switchSubmode('intervalo')">
                            <span>Intervalo de Datas</span>
                        </label>
                        <label class="ddp-radio-label">
                            <input type="radio" name="ddp_submode" value="especificas" <?= ($tipoData === 'especificas') ? 'checked' : '' ?> onchange="switchSubmode('especificas')">
                            <span>Datas Específicas</span>
                        </label>
                    </div>

                    <!-- Submodo: Intervalo -->
                    <div id="ddpSub_intervalo" style="<?= ($tipoData !== 'especificas') ? '' : 'display:none;' ?>">
                        <div class="ddp-range-grid">
                            <div class="ddp-field">
                                <label class="ddp-label">De (Início):</label>
                                <input type="date" id="ddpInputDataDe" class="dash-input" style="width:100%;" value="<?= htmlspecialchars($fDataDe) ?>">
                            </div>
                            <div class="ddp-field">
                                <label class="ddp-label">Até (Fim):</label>
                                <input type="date" id="ddpInputDataAte" class="dash-input" style="width:100%;" value="<?= htmlspecialchars($fDataAte) ?>">
                            </div>
                        </div>
                        <div class="ddp-shortcuts">
                            <button type="button" class="ddp-chip" onclick="setIntervaloAtalho(7)">Últimos 7 dias</button>
                            <button type="button" class="ddp-chip" onclick="setIntervaloAtalho(15)">Últimos 15 dias</button>
                            <button type="button" class="ddp-chip" onclick="setIntervaloAtalho(30)">Últimos 30 dias</button>
                        </div>
                    </div>

                    <!-- Submodo: Datas Específicas -->
                    <div id="ddpSub_especificas" style="<?= ($tipoData === 'especificas') ? '' : 'display:none;' ?>">
                        <div class="ddp-field">
                            <label class="ddp-label">Adicionar Data à Seleção:</label>
                            <div style="display:flex;gap:6px;">
                                <input type="date" id="ddpInputDataAdd" class="dash-input" style="flex:1;">
                                <button type="button" class="btn-dash btn-dash-secondary" onclick="adicionarDataEspecifica()" style="white-space:nowrap;">+ Adicionar</button>
                            </div>
                        </div>
                        <label class="ddp-label" style="margin-top:6px;">Datas Selecionadas:</label>
                        <div class="ddp-tags-wrap" id="ddpDatasTagsWrap">
                            <!-- Inserido dinamicamente via JS -->
                        </div>
                    </div>

                    <div class="ddp-actions" style="margin-top:12px;">
                        <button type="button" class="btn-dash btn-dash-primary" onclick="aplicarFiltroData('personalizado')" style="width:100%;justify-content:center;">
                            Aplicar Filtro Personalizado
                        </button>
                    </div>
                </div>

                <div class="ddp-footer">
                    <button type="button" class="ddp-btn-clear" onclick="limparFiltroData()">
                        Ver Todas as Datas (Limpar filtro de data)
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Layout Principal -->
    <div class="dash-main-layout">

        <!-- Área Principal (Gráfico Topo + Linha Inferior com Tabela e Status Diário) -->
        <div class="dash-content-area">

            <!-- Card Topo: Principais Motivos de Reprova -->
            <div class="dash-card dash-card-top">
                <div class="dash-card-header">
                    <h2 class="dash-card-title">Principais Motivos de Reprova</h2>
                </div>
                <div class="dash-chart-container" id="containerChartMotivos" style="height: 230px;">
                    <canvas id="chartMotivos"></canvas>
                </div>
            </div>

            <!-- Linha Dividida: Planilha de Dados + Status Diário -->
            <div class="dash-split-row">

                <!-- Planilha / Tabela de Reprovas -->
                <div class="dash-table-wrapper">
                    <!-- Banner de Filtro Dinâmico Ativo -->
                    <div id="filterBannerMotivo" style="display:none; padding:8px 14px; background:#fef2f2; border-bottom:1px solid #fee2e2; align-items:center; justify-content:space-between; font-size:12px;">
                        <div style="display:flex; align-items:center; gap:6px;">
                            <span style="color:#64748b; font-weight:600;">Filtrando por:</span>
                            <strong id="filterMotivoNome" style="color:var(--dash-red); font-weight:800;"></strong>
                            <span id="filterMotivoCount" style="color:#94a3b8; font-size:11px; font-weight:700;"></span>
                        </div>
                        <button type="button" onclick="limparFiltroMotivo()" style="background:#ef4444; color:#ffffff; border:none; padding:3px 10px; border-radius:6px; font-weight:800; font-size:11px; cursor:pointer; display:inline-flex; align-items:center; gap:4px; transition:all 0.2s ease;">
                            <span>Limpar filtro</span>
                            <span>&times;</span>
                        </button>
                    </div>

                    <div class="dash-table-scroll">
                        <table class="dash-table" id="tabelaReprovas">
                            <thead>
                                <tr>
                                    <th class="col-ns">N° DE SÉRIE</th>
                                    <th class="col-projeto">PROJETO</th>
                                    <th class="col-motivo">MOTIVO</th>
                                    <th class="col-data">DATA DA REPROVA</th>
                                    <th class="col-dias">DIAS ÚTEIS</th>
                                    <th class="col-sla">STATUS SLA</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($registros)): ?>
                                    <tr>
                                        <td colspan="6" style="text-align:center; padding: 24px; color: #94a3b8;">
                                             Nenhuma reprovação encontrada com os filtros selecionados.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($registros as $r): ?>
                                        <tr class="<?= $r['is_fora_prazo'] ? 'row-atrasada' : '' ?> item-row-reprova" data-motivo="<?= htmlspecialchars($r['motivo_fmt']) ?>" data-lote="<?= (int) ($r['id_lote'] ?? 0) ?>" data-custo="<?= (float) ($r['custo_reprova'] ?? 0) ?>">
                                            <td class="col-ns"><strong><?= htmlspecialchars($r['ns_fmt']) ?></strong></td>
                                            <td class="col-projeto"><?= htmlspecialchars($r['projeto_fmt']) ?></td>
                                            <td class="col-motivo" title="Clique para filtrar por este motivo" style="cursor:pointer;" onclick="alternarFiltroMotivo('<?= htmlspecialchars(addslashes($r['motivo_fmt'])) ?>')">
                                                <?= htmlspecialchars($r['motivo_fmt']) ?>
                                            </td>
                                            <td class="col-data"><?= htmlspecialchars($r['data_reprova_fmt']) ?></td>
                                            <td class="col-dias" style="font-family: 'JetBrains Mono', monospace; font-weight:700;">
                                                <?= (int) $r['dias_uteis'] ?>
                                            </td>
                                            <td class="col-sla">
                                                <?php if ($r['is_fora_prazo']): ?>
                                                    <span class="badge-sla badge-sla-alert">Fora do Prazo</span>
                                                <?php else: ?>
                                                    <span class="badge-sla badge-sla-ok">Dentro do Prazo</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr id="rowEmptyFiltro" style="display:none;">
                                        <td colspan="6" style="text-align:center; padding: 24px; color: #94a3b8; font-weight:600;">
                                            Nenhum transformador encontrado para o motivo selecionado.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Card Inferior Direito: Status Diário -->
                <div class="dash-card">
                    <div class="dash-card-header">
                        <h2 class="dash-card-title">Status Diário</h2>
                    </div>
                    <div class="dash-chart-container" style="height: 220px;">
                        <canvas id="chartDiario"></canvas>
                    </div>
                </div>

            </div>

        </div>

        <!-- Coluna Lateral Direita: 3 Cards de KPIs Vermelhos -->
        <div class="dash-kpi-column">
            
            <!-- Card 1: Total Reprovados -->
            <div class="dash-kpi-card">
                <div class="dash-kpi-label">Total Reprovados</div>
                <div class="dash-kpi-value"><?= (int) $totalReprovados ?></div>
            </div>

            <!-- Card 2: Média de Dias -->
            <div class="dash-kpi-card">
                <div class="dash-kpi-label">Média de Dias</div>
                <div class="dash-kpi-value"><?= $mediaDiasFormatada ?></div>
            </div>

            <!-- Card 3: +5 Dias Reprovados -->
            <div class="dash-kpi-card">
                <div class="dash-kpi-label">+5 Dias Reprovados</div>
                <div class="dash-kpi-value"><?= htmlspecialchars($mais5DiasExibicao) ?></div>
            </div>

        </div>

    </div>

</div>

<script>
(function () {
    // ─── Estado do Filtro Dinâmico por Motivo ─────────────────────────────────
    let filtroMotivoAtivo = null;

    window.alternarFiltroMotivo = function (motivo) {
        if (!motivo) return;
        if (filtroMotivoAtivo === motivo) {
            window.limparFiltroMotivo();
        } else {
            aplicarFiltroMotivo(motivo);
        }
    };

    function aplicarFiltroMotivo(motivo) {
        filtroMotivoAtivo = motivo;
        const motivoNorm = String(motivo).trim().toUpperCase();

        // 1. Atualizar cores das barras no gráfico de motivos (apenas a selecionada fica vermelha)
        if (chartMotivosInst) {
            const bgColors = chartMotivosInst.data.labels.map(lbl => {
                const lblStr = Array.isArray(lbl) ? lbl.join(' ') : String(lbl);
                return (lblStr.trim().toUpperCase() === motivoNorm) ? '#e50914' : 'rgba(229, 9, 20, 0.16)';
            });
            const hoverColors = chartMotivosInst.data.labels.map(lbl => {
                const lblStr = Array.isArray(lbl) ? lbl.join(' ') : String(lbl);
                return (lblStr.trim().toUpperCase() === motivoNorm) ? '#b8050e' : 'rgba(229, 9, 20, 0.28)';
            });
            chartMotivosInst.data.datasets[0].backgroundColor = bgColors;
            chartMotivosInst.data.datasets[0].hoverBackgroundColor = hoverColors;
            chartMotivosInst.update();
        }

        // 2. Filtrar linhas da tabela
        const rows = document.querySelectorAll('#tabelaReprovas tbody tr.item-row-reprova');
        let visiveis = 0;
        rows.forEach(tr => {
            const m = tr.getAttribute('data-motivo') || '';
            if (m.trim().toUpperCase() === motivoNorm) {
                tr.style.display = '';
                visiveis++;
            } else {
                tr.style.display = 'none';
            }
        });

        // 3. Atualizar banner de filtro
        const banner = document.getElementById('filterBannerMotivo');
        const lblNome = document.getElementById('filterMotivoNome');
        const lblCount = document.getElementById('filterMotivoCount');
        const rowEmpty = document.getElementById('rowEmptyFiltro');

        if (banner && lblNome && lblCount) {
            lblNome.textContent = motivo;
            lblCount.textContent = `(${visiveis} ${visiveis === 1 ? 'registro' : 'registros'})`;
            banner.style.display = 'flex';
        }

        if (rowEmpty) {
            rowEmpty.style.display = (visiveis === 0) ? '' : 'none';
        }

        // 4. Atualizar o custo dinâmico dos registros visíveis
        let somaCustoVisivel = 0;
        let lotesContados = new Set();
        rows.forEach(tr => {
            if (tr.style.display !== 'none') {
                const idLote = tr.getAttribute('data-lote');
                const custo = parseFloat(tr.getAttribute('data-custo') || 0);
                if (idLote && idLote !== '0') {
                    if (!lotesContados.has(idLote)) {
                        lotesContados.add(idLote);
                        somaCustoVisivel += custo;
                    }
                } else {
                    somaCustoVisivel += custo;
                }
            }
        });
        const custoFmt = 'R$ ' + somaCustoVisivel.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const elTopCusto = document.getElementById('topCustoTotal');
        if (elTopCusto) elTopCusto.textContent = custoFmt;
    }

    window.limparFiltroMotivo = function () {
        filtroMotivoAtivo = null;

        // 1. Restaurar cores sólidas em todas as barras do gráfico
        if (chartMotivosInst) {
            const resetBg = chartMotivosInst.data.labels.map(() => '#e50914');
            const resetHover = chartMotivosInst.data.labels.map(() => '#b8050e');
            chartMotivosInst.data.datasets[0].backgroundColor = resetBg;
            chartMotivosInst.data.datasets[0].hoverBackgroundColor = resetHover;
            chartMotivosInst.update();
        }

        // 2. Exibir todas as linhas da tabela
        const rows = document.querySelectorAll('#tabelaReprovas tbody tr.item-row-reprova');
        rows.forEach(tr => {
            tr.style.display = '';
        });

        // 3. Ocultar banner de filtro
        const banner = document.getElementById('filterBannerMotivo');
        const rowEmpty = document.getElementById('rowEmptyFiltro');
        if (banner) banner.style.display = 'none';
        if (rowEmpty) rowEmpty.style.display = 'none';

        // 4. Restaurar custo total original
        const custoOrigFmt = <?= json_encode($custoTotalFormatado) ?>;
        const elTopCusto = document.getElementById('topCustoTotal');
        if (elTopCusto) elTopCusto.textContent = custoOrigFmt;
    };

    // ─── Plugin Customizado Chart.js para Valores no Topo das Barras ─────────
    const topValuesPlugin = {
        id: 'topValuesPlugin',
        afterDatasetsDraw(chart) {
            const { ctx } = chart;
            chart.data.datasets.forEach((dataset, i) => {
                const meta = chart.getDatasetMeta(i);
                if (!meta.hidden) {
                    meta.data.forEach((bar, index) => {
                        const val = dataset.data[index];
                        if (val !== undefined && val !== null && val > 0) {
                            ctx.save();
                            ctx.fillStyle = '#0f172a';
                            ctx.font = 'bold 12px "Plus Jakarta Sans", sans-serif';
                            ctx.textAlign = 'center';
                            ctx.textBaseline = 'bottom';
                            ctx.fillText(val, bar.x, bar.y - 4);
                            ctx.restore();
                        }
                    });
                }
            });
        }
    };

    let chartMotivosInst = null;
    let chartDiarioInst = null;

    // ─── 1. Gráfico: Principais Motivos de Reprova ───────────────────────────
    const ctxMotivos = document.getElementById('chartMotivos');
    if (ctxMotivos) {
        const labelsMotivos = <?= json_encode($chartMotivosLabels, JSON_UNESCAPED_UNICODE) ?>;
        const dataMotivos = <?= json_encode($chartMotivosValues, JSON_UNESCAPED_UNICODE) ?>;

        const maxValMotivos = Math.max(...dataMotivos, 5);

        chartMotivosInst = new Chart(ctxMotivos, {
            type: 'bar',
            data: {
                labels: labelsMotivos,
                datasets: [{
                    label: 'Reprovações',
                    data: dataMotivos,
                    backgroundColor: labelsMotivos.map(() => '#e50914'),
                    hoverBackgroundColor: labelsMotivos.map(() => '#b8050e'),
                    borderRadius: 4,
                    maxBarThickness: 46
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: {
                    padding: { top: 12, bottom: 0 }
                },
                onClick: function (event, elements) {
                    if (elements && elements.length > 0) {
                        const index = elements[0].index;
                        const motivo = labelsMotivos[index];
                        if (motivo && motivo !== 'Sem registros') {
                            window.alternarFiltroMotivo(motivo);
                        }
                    }
                },
                onHover: function (event, elements) {
                    event.native.target.style.cursor = (elements && elements.length > 0) ? 'pointer' : 'default';
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        titleFont: { size: 13, weight: 'bold' },
                        bodyFont: { size: 12 },
                        padding: 10,
                        cornerRadius: 8
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: {
                            color: '#1e293b',
                            font: { size: 10, weight: '700' },
                            autoSkip: false,
                            maxRotation: 45,
                            minRotation: 20,
                            callback: function(val, index) {
                                const text = this.getLabelForValue(val) || '';
                                if (text.length > 18) {
                                    return text.substring(0, 16) + '...';
                                }
                                return text;
                            }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        max: Math.ceil(maxValMotivos * 1.15),
                        ticks: {
                            precision: 0,
                            color: '#64748b',
                            font: { size: 11 }
                        },
                        grid: {
                            color: '#fee2e2',
                            borderDash: [3, 3]
                        }
                    }
                }
            },
            plugins: [topValuesPlugin]
        });
    }

    // ─── 2. Gráfico: Status Diário ───────────────────────────────────────────
    const ctxDiario = document.getElementById('chartDiario');
    if (ctxDiario) {
        const labelsDiario = <?= json_encode($chartDiarioLabels, JSON_UNESCAPED_UNICODE) ?>;
        const dataDiario = <?= json_encode($chartDiarioValues, JSON_UNESCAPED_UNICODE) ?>;

        const maxValDiario = Math.max(...dataDiario, 5);

        chartDiarioInst = new Chart(ctxDiario, {
            type: 'bar',
            data: {
                labels: labelsDiario,
                datasets: [{
                    label: 'Reprovações no dia',
                    data: dataDiario,
                    backgroundColor: '#e50914',
                    borderRadius: 4,
                    maxBarThickness: 42
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: {
                    padding: { top: 20 }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        titleFont: { size: 13, weight: 'bold' },
                        bodyFont: { size: 12 },
                        padding: 10,
                        cornerRadius: 8
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: {
                            color: '#1e293b',
                            font: { size: 11, weight: '700' },
                            maxRotation: 45,
                            minRotation: 30
                        }
                    },
                    y: {
                        beginAtZero: true,
                        max: Math.ceil(maxValDiario * 1.25),
                        ticks: {
                            precision: 0,
                            color: '#64748b',
                            font: { size: 11 }
                        },
                        grid: {
                            color: '#fee2e2',
                            borderDash: [3, 3]
                        }
                    }
                }
            },
            plugins: [topValuesPlugin]
        });
    }

    // ─── 3. Filtro de Data Avançado (Hoje | Mês | Personalizável) ─────────────
    window.toggleDateFilterModal = function (forcar) {
        var pop = document.getElementById('dashDatePopover');
        var btn = document.getElementById('btnDateFilterToggle');
        if (!pop) return;
        var abrir = (forcar !== undefined) ? forcar : (pop.style.display === 'none');
        pop.style.display = abrir ? 'block' : 'none';
        if (btn) btn.classList.toggle('is-active-btn', abrir);
    };

    document.addEventListener('click', function (e) {
        var wrapper = document.querySelector('.dash-date-filter-wrapper');
        var pop = document.getElementById('dashDatePopover');
        if (!wrapper || !pop || pop.style.display === 'none') return;
        if (!wrapper.contains(e.target)) {
            window.toggleDateFilterModal(false);
        }
    });

    window.switchDateTab = function (tab) {
        ['hoje', 'mes', 'personalizado'].forEach(function (t) {
            var elContent = document.getElementById('ddpTab_' + t);
            var elBtn = document.getElementById('tabBtn_' + t);
            if (elContent) elContent.style.display = (t === tab) ? 'block' : 'none';
            if (elBtn) elBtn.classList.toggle('is-active', t === tab);
        });
    };

    window.switchSubmode = function (sub) {
        var elInt = document.getElementById('ddpSub_intervalo');
        var elEsp = document.getElementById('ddpSub_especificas');
        if (elInt) elInt.style.display = (sub === 'intervalo') ? 'block' : 'none';
        if (elEsp) elEsp.style.display = (sub === 'especificas') ? 'block' : 'none';
    };

    // Gerenciamento de tags para datas específicas
    var datasEspecificasSet = new Set();
    var initialDatasEsp = <?= json_encode($fDatasEsp) ?>;
    if (initialDatasEsp) {
        initialDatasEsp.split(',').forEach(function (d) {
            d = d.trim();
            if (d) datasEspecificasSet.add(d);
        });
    }

    function renderDatasTags() {
        var wrap = document.getElementById('ddpDatasTagsWrap');
        if (!wrap) return;
        wrap.innerHTML = '';
        if (!datasEspecificasSet.size) {
            wrap.innerHTML = '<span style="font-size:11px;color:#94a3b8;padding:4px;">Nenhuma data adicionada ainda.</span>';
            return;
        }
        var sorted = Array.from(datasEspecificasSet).sort();
        sorted.forEach(function (dIso) {
            var parts = dIso.split('-');
            var dFmt = (parts.length === 3) ? (parts[2] + '/' + parts[1] + '/' + parts[0]) : dIso;
            var tag = document.createElement('span');
            tag.className = 'ddp-date-tag';
            tag.innerHTML = dFmt + ' <button type="button" onclick="removerDataEspecifica(\'' + dIso + '\')" title="Remover">&times;</button>';
            wrap.appendChild(tag);
        });
    }
    renderDatasTags();

    window.adicionarDataEspecifica = function () {
        var input = document.getElementById('ddpInputDataAdd');
        if (!input || !input.value) return;
        datasEspecificasSet.add(input.value);
        input.value = '';
        renderDatasTags();
    };

    window.removerDataEspecifica = function (dIso) {
        datasEspecificasSet.delete(dIso);
        renderDatasTags();
    };

    window.setIntervaloAtalho = function (dias) {
        var hoje = new Date();
        var ateIso = hoje.toISOString().split('T')[0];
        var deDate = new Date();
        deDate.setDate(hoje.getDate() - dias + 1);
        var deIso = deDate.toISOString().split('T')[0];

        var inputDe = document.getElementById('ddpInputDataDe');
        var inputAte = document.getElementById('ddpInputDataAte');
        if (inputDe) inputDe.value = deIso;
        if (inputAte) inputAte.value = ateIso;
    };

    window.aplicarFiltroData = function (tipo) {
        var form = document.getElementById('dashFilterForm');
        if (!form) return;

        var tipoInput = document.getElementById('formTipoData');
        var mesInput = document.getElementById('formDataMes');
        var deInput = document.getElementById('formDataDe');
        var ateInput = document.getElementById('formDataAte');
        var espInput = document.getElementById('formDatasEsp');

        if (tipo === 'hoje') {
            tipoInput.value = 'hoje';
        } else if (tipo === 'mes') {
            tipoInput.value = 'mes';
            var ddpMes = document.getElementById('ddpInputMes');
            mesInput.value = ddpMes ? ddpMes.value : '';
        } else if (tipo === 'personalizado') {
            var submode = document.querySelector('input[name="ddp_submode"]:checked');
            var subVal = submode ? submode.value : 'intervalo';
            if (subVal === 'intervalo') {
                tipoInput.value = 'intervalo';
                var ddpDe = document.getElementById('ddpInputDataDe');
                var ddpAte = document.getElementById('ddpInputDataAte');
                deInput.value = ddpDe ? ddpDe.value : '';
                ateInput.value = ddpAte ? ddpAte.value : '';
            } else {
                if (datasEspecificasSet.size === 0) {
                    var inputAdd = document.getElementById('ddpInputDataAdd');
                    if (inputAdd && inputAdd.value) {
                        datasEspecificasSet.add(inputAdd.value);
                    } else {
                        alert('Por favor, adicione pelo menos uma data para filtrar.');
                        return;
                    }
                }
                tipoInput.value = 'especificas';
                espInput.value = Array.from(datasEspecificasSet).join(',');
            }
        }

        form.submit();
    };

    window.limparFiltroData = function () {
        var tipoInput = document.getElementById('formTipoData');
        var deInput = document.getElementById('formDataDe');
        var ateInput = document.getElementById('formDataAte');
        var espInput = document.getElementById('formDatasEsp');
        if (tipoInput) tipoInput.value = '';
        if (deInput) deInput.value = '';
        if (ateInput) ateInput.value = '';
        if (espInput) espInput.value = '';
        var form = document.getElementById('dashFilterForm');
        if (form) form.submit();
    };

    // ─── Redimensionamento Suave dos Gráficos ─────────────────────────────────
    function resizeAllCharts() {
        if (chartMotivosInst) chartMotivosInst.resize();
        if (chartDiarioInst) chartDiarioInst.resize();
    }

    // ─── Sincronização de Estado Tela Cheia (Botão, ESC, F11) ─────────────────
    function syncFullscreenState() {
        const isFs = !!(document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement);
        if (isFs) {
            document.body.classList.add('tv-mode');
        } else {
            document.body.classList.remove('tv-mode');
        }
        requestAnimationFrame(function () {
            resizeAllCharts();
            setTimeout(resizeAllCharts, 100);
            setTimeout(resizeAllCharts, 300);
        });
    }

    document.addEventListener('fullscreenchange', syncFullscreenState);
    document.addEventListener('webkitfullscreenchange', syncFullscreenState);
    document.addEventListener('mozfullscreenchange', syncFullscreenState);

    window.addEventListener('resize', function () {
        resizeAllCharts();
    });

    // ─── Alternar Modo Tela Cheia ────────────────────────────────────────────
    window.toggleFullscreen = function () {
        const isFs = !!(document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement);
        if (!isFs) {
            const el = document.documentElement;
            const req = el.requestFullscreen || el.webkitRequestFullscreen || el.msRequestFullscreen;
            if (req) {
                req.call(el).then(syncFullscreenState).catch(function () {
                    document.body.classList.toggle('tv-mode');
                    setTimeout(resizeAllCharts, 100);
                });
            } else {
                document.body.classList.toggle('tv-mode');
                setTimeout(resizeAllCharts, 100);
            }
        } else {
            const exit = document.exitFullscreen || document.webkitExitFullscreen || document.msExitFullscreen;
            if (exit) {
                exit.call(document).then(syncFullscreenState).catch(function () {
                    document.body.classList.remove('tv-mode');
                    setTimeout(resizeAllCharts, 100);
                });
            } else {
                document.body.classList.remove('tv-mode');
                setTimeout(resizeAllCharts, 100);
            }
        }
    };

    // ─── Auto-Refresh (a cada 45 segundos) ───────────────────────────────────
    let autoRefreshTimer = setTimeout(function () {
        window.location.reload();
    }, 45000);
}());
</script>

<?php
layoutFooter();
