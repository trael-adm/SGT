<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/layout.php';

requireAcessoModulo('retrabalho');

$pdo  = getDB();
$base = defined('APP_URL') ? APP_URL : '';

// ─── Permissão de Visualização Financeira (R$) ───────────────────────────────
$podeVerValores = isAdmin() || podeEditar('ret.cus') || hasAcesso('ret.cus') || podeEditar('tab:retrabalho');

// ─── Parâmetros Globais de Custos ────────────────────────────────────────────
$configRows = $pdo->query("SELECT chave, valor FROM retrabalho_configuracoes")->fetchAll(PDO::FETCH_KEY_PAIR);
$custoHoraHomem   = (float) ($configRows['custo_hora_homem'] ?? 45.00);
$horasTrabalhoDia = (float) ($configRows['horas_trabalho_dia'] ?? 8.80);
if ($horasTrabalhoDia <= 0) $horasTrabalhoDia = 8.80;

// Catálogo de Materiais com Custos (para valorar peças usadas na triagem)
$materiaisCatalogo = $pdo->query("
    SELECT id, descricao, unidade, custo_unitario
    FROM retrabalho_materiais_catalogo
")->fetchAll(PDO::FETCH_ASSOC);

$catalogoLookup = [];
$catalogoDescLookup = [];
foreach ($materiaisCatalogo as $mc) {
    $id = (int) $mc['id'];
    $descNorm = mb_strtoupper(trim((string) $mc['descricao']));
    $itemInfo = [
        'id'             => $id,
        'descricao'      => (string) $mc['descricao'],
        'unidade'        => (string) $mc['unidade'],
        'custo_unitario' => (float) $mc['custo_unitario']
    ];
    $catalogoLookup[$id] = $itemInfo;
    $catalogoDescLookup[$descNorm] = $itemInfo;
}

// ─── Tratamento de Datas e Filtros (GET) ─────────────────────────────────────
$tz = new DateTimeZone('America/Cuiaba');
$agora = new DateTime('now', $tz);

$dataHojeIso = $agora->format('Y-m-d');
$primeiroDiaMes = $agora->format('Y-m-01');

$fDataDe   = trim((string) ($_GET['data_de'] ?? $primeiroDiaMes));
$fDataAte  = trim((string) ($_GET['data_ate'] ?? $dataHojeIso));
$fBusca    = trim((string) ($_GET['busca'] ?? ''));
$fEstacao  = trim((string) ($_GET['estacao'] ?? ''));
$fStatus   = trim((string) ($_GET['status'] ?? 'todos')); // todos | finalizado | em_andamento
$fSetor    = trim((string) ($_GET['setor'] ?? ''));
$fReprova  = (int) ($_GET['id_reprova'] ?? 0);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDataDe))  $fDataDe = $primeiroDiaMes;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDataAte)) $fDataAte = $dataHojeIso;

// ─── Setores Disponíveis ────────────────────────────────────────────────────
$setoresDisponiveis = [
    'bobinagem_at'    => 'Bobinagem AT',
    'bobinagem_bt'    => 'Bobinagem BT',
    'montagem_nucleo' => 'Montagem de Núcleo',
    'solda'           => 'Solda / Caldeiraria',
    'radiador'        => 'Radiadores',
    'pintura'         => 'Pintura',
    'montagem_final'  => 'Montagem Final',
    'laboratorio'     => 'Laboratório',
    'inspecao_final'  => 'Inspeção Final'
];

// ─── Query Base de Retrabalhos ───────────────────────────────────────────────
$where = [
    'r.deleted_at IS NULL',
    'r.id_reprova IS NOT NULL'
];
$params = [];

// Filtro por Data de Referência (data_reprova -> data_inicio -> created_at)
$where[] = 'DATE(COALESCE(r.data_reprova, r.data_inicio, r.created_at)) >= :data_de';
$params['data_de'] = $fDataDe;

$where[] = 'DATE(COALESCE(r.data_reprova, r.data_inicio, r.created_at)) <= :data_ate';
$params['data_ate'] = $fDataAte;

if ($fEstacao !== '' && in_array($fEstacao, ['LAB', 'IQF', 'GER'], true)) {
    $where[] = 'r.estacao = :estacao';
    $params['estacao'] = $fEstacao;
}

if ($fReprova > 0) {
    $where[] = 'r.id_reprova = :id_reprova';
    $params['id_reprova'] = $fReprova;
}

if ($fStatus === 'finalizado') {
    $where[] = "r.status IN ('finalizado', 'aprovado')";
} elseif ($fStatus === 'em_andamento') {
    $where[] = "r.status NOT IN ('finalizado', 'aprovado')";
}

if ($fSetor !== '' && isset($setoresDisponiveis[$fSetor])) {
    $where[] = 'r.setores_destino LIKE :setor_like';
    $params['setor_like'] = '%' . $fSetor . '%';
}

if ($fBusca !== '') {
    $where[] = '(r.ns_transformador LIKE :busca OR p.codigo LIKE :busca OR ped.numero LIKE :busca OR rep.descricao LIKE :busca)';
    $params['busca'] = '%' . $fBusca . '%';
}

$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT 
        r.id,
        r.id_lote,
        r.ns_transformador,
        r.id_projeto,
        r.id_reprova,
        r.estacao,
        r.prioridade,
        r.status,
        r.data_reprova,
        r.data_chegada,
        r.data_inicio,
        r.data_finalizacao,
        r.concluido_em,
        r.created_at,
        r.updated_at,
        r.setores_destino,
        r.causa_reprova,
        r.causa_raiz,
        r.correcao,
        p.codigo AS projeto_codigo,
        p.descricao AS projeto_descricao,
        ped.numero AS pedido_numero,
        rep.codigo AS reprova_codigo,
        rep.familia AS reprova_familia,
        rep.descricao AS reprova_descricao,
        rep.local AS reprova_local,
        rep.tempo_padrao_minutos AS reprova_tempo_minutos,
        u.nome AS responsavel_nome
    FROM retrabalhos r
    LEFT JOIN reprovas rep ON rep.id = r.id_reprova
    LEFT JOIN projetos p ON p.id = r.id_projeto
    LEFT JOIN pedidos ped ON ped.id = p.id_pedido
    LEFT JOIN usuarios u ON u.id = r.id_responsavel
    WHERE {$whereSql}
    ORDER BY r.id DESC
");
$stmt->execute($params);
$registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ─── Coletar Materiais Utilizados nos Lotes / Retrabalhos ───────────────────
$lotesIds = [];
$retrabalhoIds = [];
foreach ($registros as $reg) {
    if (!empty($reg['id_lote'])) {
        $lotesIds[(int) $reg['id_lote']] = true;
    }
    $retrabalhoIds[(int) $reg['id']] = true;
}

$materiaisUsadosPorLote = [];
$todosMateriaisConsumidos = [];

if (!empty($lotesIds) || !empty($retrabalhoIds)) {
    $idsParaBusca = !empty($lotesIds) ? array_keys($lotesIds) : array_keys($retrabalhoIds);
    $inPlaceholders = implode(',', array_fill(0, count($idsParaBusca), '?'));
    
    $stmtMat = $pdo->prepare("
        SELECT id, id_lote, codigo, descricao, quantidade, preco_medio, unidade, created_at
        FROM retrabalho_material_uso
        WHERE id_lote IN ({$inPlaceholders})
    ");
    $stmtMat->execute($idsParaBusca);
    $materiaisRows = $stmtMat->fetchAll(PDO::FETCH_ASSOC);

    foreach ($materiaisRows as $mr) {
        $loteId = (int) $mr['id_lote'];
        $qtd    = (float) $mr['quantidade'];
        if ($qtd <= 0) continue;

        $desc   = trim((string) $mr['descricao']);
        $un     = !empty($mr['unidade']) ? trim((string) $mr['unidade']) : 'UND';
        $descNorm = mb_strtoupper($desc);

        $custoUnit = !empty($mr['preco_medio']) ? (float) $mr['preco_medio'] : 0.0;
        $idMat = null;

        if (isset($catalogoDescLookup[$descNorm])) {
            $idMat = $catalogoDescLookup[$descNorm]['id'];
            if ($custoUnit <= 0) {
                $custoUnit = $catalogoDescLookup[$descNorm]['custo_unitario'];
            }
            if (empty($mr['unidade'])) {
                $un = $catalogoDescLookup[$descNorm]['unidade'];
            }
        }

        $custoTotalMat = $qtd * $custoUnit;

        $itemMat = [
            'id_material'    => $idMat,
            'descricao'      => $desc,
            'unidade'        => $un,
            'quantidade'     => $qtd,
            'custo_unitario' => $custoUnit,
            'custo_total'    => $custoTotalMat
        ];

        $materiaisUsadosPorLote[$loteId][] = $itemMat;

        $chaveConsol = ($idMat ? 'CAT_' . $idMat : 'OUT_' . $descNorm) . '_' . $un;
        if (!isset($todosMateriaisConsumidos[$chaveConsol])) {
            $todosMateriaisConsumidos[$chaveConsol] = [
                'descricao'      => $desc,
                'unidade'        => $un,
                'quantidade'     => 0.0,
                'custo_unitario' => $custoUnit,
                'custo_total'    => $custoTotalMat,
                'ocorrencias'    => 0
            ];
        }
        $todosMateriaisConsumidos[$chaveConsol]['quantidade']  += $qtd;
        $todosMateriaisConsumidos[$chaveConsol]['custo_total'] += $custoTotalMat;
        $todosMateriaisConsumidos[$chaveConsol]['ocorrencias']++;
    }
}

// ─── Processamento Analítico com Horas pelo Tipo de Reprova ─────────────────
$totalCasosRetrabalho = count($registros);
$totalHorasRetrabalho = 0.0;
$totalCustoMaoObra    = 0.0;
$totalCustoPecas      = 0.0;
$totalCustoGeral      = 0.0;
$casosFinalizadosCont = 0;

$analiseReprovas = [];
$analiseSetores  = [];
$registrosProcessados = [];

foreach ($registros as $r) {
    // 1. Contabilização de Horas com base no Tempo Padrão da Reprova (Minutos)
    $minutosPadrao = !empty($r['reprova_tempo_minutos']) ? (int) $r['reprova_tempo_minutos'] : 60;
    if ($minutosPadrao <= 0) $minutosPadrao = 60;

    $horasTrabalhadas = round($minutosPadrao / 60, 2);

    $isFinalizado = in_array($r['status'], ['finalizado', 'aprovado'], true);
    if ($isFinalizado) {
        $casosFinalizadosCont++;
    }

    // 2. Custo de Mão de Obra
    $custoMO = round($horasTrabalhadas * $custoHoraHomem, 2);

    // 3. Custo de Peças
    $loteId = !empty($r['id_lote']) ? (int) $r['id_lote'] : (int) $r['id'];
    $pecasDoLote = $materiaisUsadosPorLote[$loteId] ?? [];
    $custoPecasItem = 0.0;
    $qtdPecasItem = 0.0;

    foreach ($pecasDoLote as $pItem) {
        $custoPecasItem += (float) $pItem['custo_total'];
        $qtdPecasItem   += (float) $pItem['quantidade'];
    }

    $custoTotalItem = round($custoMO + $custoPecasItem, 2);

    $totalHorasRetrabalho += $horasTrabalhadas;
    $totalCustoMaoObra    += $custoMO;
    $totalCustoPecas      += $custoPecasItem;
    $totalCustoGeral      += $custoTotalItem;

    // 4. Agrupamento por Tipo de Reprova
    $codReprova  = $r['reprova_codigo'] ?: 'N/D';
    $descReprova = $r['reprova_descricao'] ?: ($r['causa_reprova'] ?: 'Não Especificada');
    $famReprova  = $r['reprova_familia'] ?: 'GERAL';

    if (!isset($analiseReprovas[$codReprova])) {
        $analiseReprovas[$codReprova] = [
            'codigo'        => $codReprova,
            'familia'       => $famReprova,
            'descricao'     => $descReprova,
            'tempo_minutos' => $minutosPadrao,
            'ocorrencias'   => 0,
            'horas'         => 0.0,
            'custo_mo'      => 0.0,
            'custo_pecas'   => 0.0,
            'custo_total'   => 0.0
        ];
    }
    $analiseReprovas[$codReprova]['ocorrencias']++;
    $analiseReprovas[$codReprova]['horas']       += $horasTrabalhadas;
    $analiseReprovas[$codReprova]['custo_mo']    += $custoMO;
    $analiseReprovas[$codReprova]['custo_pecas'] += $custoPecasItem;
    $analiseReprovas[$codReprova]['custo_total'] += $custoTotalItem;

    // 5. Agrupamento por Setor de Destino
    $setoresDestStr = trim((string) ($r['setores_destino'] ?? ''));
    $listaSetores = $setoresDestStr !== '' ? explode(',', $setoresDestStr) : ['nao_definido'];
    foreach ($listaSetores as $slugSetor) {
        $slugSetor = trim($slugSetor);
        if ($slugSetor === '') continue;
        $nomeSetor = $setoresDisponiveis[$slugSetor] ?? ($slugSetor === 'nao_definido' ? 'Não definido' : ucfirst(str_replace('_', ' ', $slugSetor)));

        if (!isset($analiseSetores[$slugSetor])) {
            $analiseSetores[$slugSetor] = [
                'slug'        => $slugSetor,
                'nome'        => $nomeSetor,
                'ocorrencias' => 0,
                'horas'       => 0.0,
                'custo_total' => 0.0
            ];
        }
        $analiseSetores[$slugSetor]['ocorrencias']++;
        $analiseSetores[$slugSetor]['horas']       += $horasTrabalhadas;
        $analiseSetores[$slugSetor]['custo_total'] += $custoTotalItem;
    }

    $r['minutos_padrao']    = $minutosPadrao;
    $r['horas_trabalhadas'] = $horasTrabalhadas;
    $r['custo_mo']          = $custoMO;
    $r['custo_pecas']       = $custoPecasItem;
    $r['custo_total']       = $custoTotalItem;
    $r['qtd_pecas']         = $qtdPecasItem;
    $r['pecas_detalhes']    = $pecasDoLote;

    $registrosProcessados[] = $r;
}

// Médias Gerais
$mediaHorasPorCaso = $totalCasosRetrabalho > 0 ? round($totalHorasRetrabalho / $totalCasosRetrabalho, 2) : 0.0;
$custoMedioPorCaso = $totalCasosRetrabalho > 0 ? round($totalCustoGeral / $totalCasosRetrabalho, 2) : 0.0;

// Ordenar Reprovas pelo Maior Custo Total
uasort($analiseReprovas, fn($a, $b) => $b['custo_total'] <=> $a['custo_total']);

// Ordenar Setores pelo Maior Custo
uasort($analiseSetores, fn($a, $b) => $b['custo_total'] <=> $a['custo_total']);

// Ordenar Materiais pelo Maior Consumo/Custo
uasort($todosMateriaisConsumidos, fn($a, $b) => $b['custo_total'] <=> $a['custo_total']);

// ─── EXPORTAÇÃO CSV ──────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="relatorio_retrabalho_operacional_custos_' . date('Ymd_His') . '.csv"');
    
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8

    $headerCsv = [
        'ID', 'NS Transformador', 'Pedido', 'Projeto', 'Estação', 'Status', 
        'Código Reprova', 'Família', 'Descrição Reprova', 'Tempo Reprova (Min)', 'Horas Parametrizadas'
    ];
    if ($podeVerValores) {
        $headerCsv[] = 'Custo Mão de Obra (R$)';
        $headerCsv[] = 'Custo Peças (R$)';
        $headerCsv[] = 'Custo Total (R$)';
    }
    $headerCsv[] = 'Setores Destino';
    $headerCsv[] = 'Peças Utilizadas';
    fputcsv($out, $headerCsv, ';');

    foreach ($registrosProcessados as $row) {
        $pecasTxt = [];
        foreach ($row['pecas_detalhes'] as $pDet) {
            $pecasTxt[] = $pDet['descricao'] . ' (' . $pDet['quantidade'] . ' ' . $pDet['unidade'] . ')';
        }

        $linha = [
            $row['id'],
            $row['ns_transformador'],
            $row['pedido_numero'] ?? '-',
            $row['projeto_codigo'] ?? '-',
            $row['estacao'],
            $row['status'],
            $row['reprova_codigo'] ?? '-',
            $row['reprova_familia'] ?? '-',
            $row['reprova_descricao'] ?? '-',
            $row['minutos_padrao'],
            number_format((float) $row['horas_trabalhadas'], 2, ',', '.')
        ];
        if ($podeVerValores) {
            $linha[] = number_format((float) $row['custo_mo'], 2, ',', '.');
            $linha[] = number_format((float) $row['custo_pecas'], 2, ',', '.');
            $linha[] = number_format((float) $row['custo_total'], 2, ',', '.');
        }
        $linha[] = $row['setores_destino'] ?? '-';
        $linha[] = implode(' | ', $pecasTxt);
        fputcsv($out, $linha, ';');
    }
    fclose($out);
    exit;
}

// ─── Dados para Gráficos Chart.js ────────────────────────────────────────────
// Gráfico 1: Por Tipo de Reprova (Top 8)
$chartReprovaLabels = [];
$chartReprovaCustos = [];
$chartReprovaHoras  = [];
$chartReprovaQtd    = [];

$limiteReprovas = 8;
$contR = 0;
$outrosCusto = 0.0;
$outrosHoras = 0.0;
$outrosQtd   = 0;

foreach ($analiseReprovas as $ar) {
    if ($contR < $limiteReprovas) {
        $chartReprovaLabels[] = $ar['codigo'] . ' - ' . mb_substr($ar['descricao'], 0, 24);
        $chartReprovaCustos[] = round($ar['custo_total'], 2);
        $chartReprovaHoras[]  = round($ar['horas'], 2);
        $chartReprovaQtd[]    = (int) $ar['ocorrencias'];
        $contR++;
    } else {
        $outrosCusto += $ar['custo_total'];
        $outrosHoras += $ar['horas'];
        $outrosQtd   += $ar['ocorrencias'];
    }
}
if ($outrosQtd > 0) {
    $chartReprovaLabels[] = 'Outras Reprovas';
    $chartReprovaCustos[] = round($outrosCusto, 2);
    $chartReprovaHoras[]  = round($outrosHoras, 2);
    $chartReprovaQtd[]    = $outrosQtd;
}

// Gráfico 2: Setores de Destino com Paleta de Cores Harmônica e Exclusiva
$mapaCoresSetores = [
    'inspecao_final'  => '#133a27', // Verde Floresta Trael
    'nao_definido'    => '#94a3b8', // Slate / Cinza Neutro
    'pintura'         => '#e8a020', // Gold Trael
    'laboratorio'     => '#0284c7', // Azul Elétrico
    'solda'           => '#7c3aed', // Roxo Moderno
    'montagem_final'  => '#dc2626', // Rubi / Vermelho
    'bobinagem_at'    => '#059669', // Esmeralda
    'bobinagem_bt'    => '#0d9488', // Teal
    'radiador'        => '#ea580c', // Laranja
    'montagem_nucleo' => '#475569'  // Cinza Escuro
];

$chartSetorLabels = [];
$chartSetorCustos = [];
$chartSetorHoras  = [];
$chartSetorColors = [];

foreach ($analiseSetores as $as) {
    $chartSetorLabels[] = $as['nome'];
    $chartSetorCustos[] = round($as['custo_total'], 2);
    $chartSetorHoras[]  = round($as['horas'], 2);
    $chartSetorColors[] = $mapaCoresSetores[$as['slug']] ?? '#64748b';
}

$pageTitle = 'Relatório Operacional & Financeiro de Retrabalho';
layoutHeader($pageTitle);
?>

<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@500;700;800&display=swap">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
/* ==========================================================================
   RELATÓRIO OPERACIONAL E FINANCEIRO DE RETRABALHO — IMPECCABLE POLISH
   ========================================================================== */
:root {
    --rep-primary: #133a27;
    --rep-primary-light: #1e5a3d;
    --rep-gold: #e8a020;
    --rep-gold-hover: #cf8b13;
    --rep-red: #dc2626;
    --rep-red-light: #fef2f2;
    --rep-border: #e2e8f0;
    --rep-card-bg: #ffffff;
    --rep-text-main: #0f172a;
    --rep-text-muted: #64748b;
}

.report-container {
    max-width: 1760px;
    width: 100%;
    margin: 0 auto;
    padding: 14px 20px 60px;
    font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
    color: var(--rep-text-main);
}

/* ─── Top Control Bar ────────────────────────────────────────────────────── */
.report-top-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    background: #ffffff;
    border: 1px solid var(--rep-border);
    border-radius: 14px;
    padding: 16px 22px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    margin-bottom: 20px;
}

.report-title-wrap {
    display: flex;
    align-items: center;
    gap: 14px;
}

.report-icon-badge {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: linear-gradient(135deg, var(--rep-primary), var(--rep-primary-light));
    color: var(--rep-gold);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    box-shadow: 0 4px 10px rgba(19, 58, 39, 0.18);
}

.report-title-wrap h1 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--rep-primary);
    letter-spacing: -0.02em;
}

.report-title-wrap p {
    margin: 2px 0 0;
    font-size: 0.82rem;
    color: var(--rep-text-muted);
}

.report-nav-actions {
    display: flex;
    align-items: center;
    gap: 12px;
}

.report-info-pill {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 6px 14px;
    user-select: none;
}

.info-pill-icon {
    width: 28px;
    height: 28px;
    border-radius: 7px;
    background: #e0f2fe;
    color: #0369a1;
    display: flex;
    align-items: center;
    justify-content: center;
}

.info-pill-text {
    display: flex;
    flex-direction: column;
    line-height: 1.15;
}

.info-pill-label {
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #64748b;
}

.info-pill-val {
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.88rem;
    font-weight: 800;
    color: #0f172a;
}

.btn-report-action {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: 9px;
    font-size: 0.85rem;
    font-weight: 600;
    text-decoration: none;
    background: #f1f5f9;
    color: #334155;
    border: 1px solid #cbd5e1;
    transition: all 0.2s ease;
}

.btn-report-action:hover {
    background: #e2e8f0;
    color: var(--rep-primary);
}

.btn-report-gold {
    background: linear-gradient(135deg, var(--rep-gold), var(--rep-gold-hover));
    color: #133a27;
    border: none;
    font-weight: 700;
}

.btn-report-gold:hover {
    box-shadow: 0 2px 8px rgba(232, 160, 32, 0.35);
    color: #133a27;
}

/* ─── Barra de Filtros Avançados & Presets de Data ────────────────────────── */
.filter-card {
    background: #ffffff;
    border: 1px solid var(--rep-border);
    border-radius: 14px;
    padding: 18px 22px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    margin-bottom: 24px;
}

.filter-presets-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin-bottom: 14px;
    padding-bottom: 12px;
    border-bottom: 1px solid #f1f5f9;
}

.filter-presets-label {
    font-size: 0.76rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #64748b;
    margin-right: 4px;
}

.preset-chip {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #475569;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 0.78rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.preset-chip:hover {
    background: #e2e8f0;
    color: var(--rep-primary);
}

.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 14px;
    align-items: flex-end;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.filter-group label {
    font-size: 0.74rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: #475569;
}

.filter-input {
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 7px 12px;
    font-size: 0.86rem;
    color: #0f172a;
    background: #ffffff;
    outline: none;
    transition: border-color 0.2s ease;
}

.filter-input:focus {
    border-color: var(--rep-primary);
    box-shadow: 0 0 0 2px rgba(19, 58, 39, 0.1);
}

.btn-filter-submit {
    background: linear-gradient(135deg, var(--rep-primary), var(--rep-primary-light));
    color: #ffffff;
    border: none;
    border-radius: 8px;
    padding: 8px 16px;
    font-weight: 700;
    font-size: 0.88rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    cursor: pointer;
    transition: opacity 0.2s ease;
}

.btn-filter-submit:hover {
    opacity: 0.92;
}

.btn-filter-reset {
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 8px 14px;
    font-weight: 600;
    font-size: 0.85rem;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
}

/* ─── Grid de KPIs de Topo ───────────────────────────────────────────────── */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.kpi-card {
    background: #ffffff;
    border: 1px solid var(--rep-border);
    border-radius: 14px;
    padding: 18px 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    position: relative;
    overflow: hidden;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.06);
}

.kpi-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background: var(--rep-primary);
}

.kpi-card.gold::before { background: var(--rep-gold); }
.kpi-card.red::before { background: var(--rep-red); }
.kpi-card.blue::before { background: #0284c7; }
.kpi-card.emerald::before { background: #059669; }

.kpi-label {
    font-size: 0.74rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--rep-text-muted);
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.kpi-val {
    font-family: 'JetBrains Mono', monospace;
    font-size: 1.55rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.2;
}

.kpi-sub {
    font-size: 0.74rem;
    color: var(--rep-text-muted);
    margin-top: 6px;
}

/* ─── Seções de Gráficos ─────────────────────────────────────────────────── */
.charts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(480px, 1fr));
    gap: 20px;
    margin-bottom: 24px;
}

.chart-card {
    background: #ffffff;
    border: 1px solid var(--rep-border);
    border-radius: 14px;
    padding: 22px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}

.chart-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
}

.chart-card-header h3 {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 700;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 8px;
}

.chart-wrapper {
    position: relative;
    height: 310px;
    width: 100%;
}

/* ─── Tabelas Analíticas ─────────────────────────────────────────────────── */
.section-card {
    background: #ffffff;
    border: 1px solid var(--rep-border);
    border-radius: 14px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    margin-bottom: 24px;
}

.section-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
}

.section-card-header h3 {
    margin: 0;
    font-size: 1.02rem;
    font-weight: 750;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 10px;
}

.table-scroll {
    overflow-x: auto;
}

.report-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
}

.report-table th {
    background: #f8fafc;
    color: #475569;
    font-weight: 700;
    text-align: left;
    padding: 10px 14px;
    border-bottom: 2px solid var(--rep-border);
    font-size: 0.74rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    white-space: nowrap;
}

.report-table td {
    padding: 10px 14px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}

.report-table tr:hover td {
    background: #f8fafc;
}

.badge-tag {
    display: inline-block;
    padding: 2px 7px;
    border-radius: 5px;
    font-size: 0.72rem;
    font-weight: 700;
    font-family: 'JetBrains Mono', monospace;
}

.badge-tag.lab { background: #e0f2fe; color: #0369a1; }
.badge-tag.iqf { background: #fef3c7; color: #b45309; }
.badge-tag.ger { background: #f1f5f9; color: #475569; }

.badge-status {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 8px;
    border-radius: 9999px;
    font-size: 0.72rem;
    font-weight: 700;
}

.badge-status.finalizado { background: #dcfce7; color: #15803d; }
.badge-status.em_andamento { background: #fef3c7; color: #b45309; }

.num-mono {
    font-family: 'JetBrains Mono', monospace;
    font-weight: 600;
}

.btn-drilldown {
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 4px 8px;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--rep-primary);
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-drilldown:hover {
    background: var(--rep-primary);
    color: #ffffff;
    border-color: var(--rep-primary);
}

/* ─── Modal de Drilldown ─────────────────────────────────────────────────── */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(3px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.modal-content-box {
    background: #ffffff;
    border-radius: 16px;
    max-width: 780px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 10px 30px rgba(0,0,0,0.25);
    padding: 24px;
    position: relative;
    animation: modalPop 0.2s ease-out;
}

@keyframes modalPop {
    from { transform: scale(0.95); opacity: 0; }
    to { transform: scale(1); opacity: 1; }
}

.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 14px;
    margin-bottom: 16px;
}

.modal-header h3 {
    margin: 0;
    font-size: 1.15rem;
    font-weight: 800;
    color: var(--rep-primary);
}

.modal-close-btn {
    background: none;
    border: none;
    font-size: 24px;
    color: #94a3b8;
    cursor: pointer;
    line-height: 1;
}

.modal-close-btn:hover { color: #0f172a; }
</style>

<div class="report-container">
    <!-- Top Bar -->
    <div class="report-top-bar">
        <div class="report-title-wrap">
            <div class="report-icon-badge">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/>
                    <line x1="16" y1="17" x2="8" y2="17"/>
                </svg>
            </div>
            <div>
                <h1>Relatório Operacional & Financeiro de Retrabalho</h1>
                <p>Contabilização de horas pelo tempo padrão de cada reprova e apuração de custos de mão-de-obra e peças</p>
            </div>
        </div>

        <div class="report-nav-actions">
            <!-- Card Informativo de Tempo & Custo Atual -->
            <div class="report-info-pill" title="Taxa horária parametrizada no sistema para o cálculo do retrabalho">
                <div class="info-pill-icon">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                </div>
                <div class="info-pill-text">
                    <span class="info-pill-label">R$/H Mão de Obra</span>
                    <span class="info-pill-val">R$ <?= number_format($custoHoraHomem, 2, ',', '.') ?>/h</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Barra de Filtros com Presets Rápidos -->
    <div class="filter-card">
        <div class="filter-presets-bar">
            <span class="filter-presets-label">Período Rápido:</span>
            <button type="button" class="preset-chip" onclick="aplicarPresetData('hoje')">Hoje</button>
            <button type="button" class="preset-chip" onclick="aplicarPresetData('7d')">Últimos 7 dias</button>
            <button type="button" class="preset-chip" onclick="aplicarPresetData('mes_atual')">Este Mês</button>
            <button type="button" class="preset-chip" onclick="aplicarPresetData('mes_anterior')">Mês Anterior</button>
            <button type="button" class="preset-chip" onclick="aplicarPresetData('90d')">Últimos 90 dias</button>
        </div>

        <form method="GET" action="" id="formFiltroRelatorio" class="filter-grid">
            <div class="filter-group">
                <label for="data_de">Data Inicial (De)</label>
                <input type="date" id="data_de" name="data_de" value="<?= htmlspecialchars($fDataDe) ?>" class="filter-input">
            </div>

            <div class="filter-group">
                <label for="data_ate">Data Final (Até)</label>
                <input type="date" id="data_ate" name="data_ate" value="<?= htmlspecialchars($fDataAte) ?>" class="filter-input">
            </div>

            <div class="filter-group">
                <label for="estacao">Estação / Origem</label>
                <select id="estacao" name="estacao" class="filter-input">
                    <option value="">Todas as Estações</option>
                    <option value="LAB" <?= $fEstacao === 'LAB' ? 'selected' : '' ?>>Laboratório (LAB)</option>
                    <option value="IQF" <?= $fEstacao === 'IQF' ? 'selected' : '' ?>>Inspeção Final (IQF)</option>
                    <option value="GER" <?= $fEstacao === 'GER' ? 'selected' : '' ?>>Geral (GER)</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="setor">Setor de Destino</label>
                <select id="setor" name="setor" class="filter-input">
                    <option value="">Todos os Setores</option>
                    <?php foreach ($setoresDisponiveis as $sKey => $sLabel): ?>
                        <option value="<?= htmlspecialchars($sKey) ?>" <?= $fSetor === $sKey ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="status">Status do Retrabalho</label>
                <select id="status" name="status" class="filter-input">
                    <option value="todos" <?= $fStatus === 'todos' ? 'selected' : '' ?>>Todos</option>
                    <option value="finalizado" <?= $fStatus === 'finalizado' ? 'selected' : '' ?>>Finalizados</option>
                    <option value="em_andamento" <?= $fStatus === 'em_andamento' ? 'selected' : '' ?>>Em Andamento</option>
                </select>
            </div>

            <div class="filter-group" style="grid-column: span 2;">
                <label for="busca">Buscar por NS, Projeto, Pedido ou Reprova</label>
                <input type="text" id="busca" name="busca" value="<?= htmlspecialchars($fBusca) ?>" placeholder="Ex: 855404, P-1234, VAZAMENTO..." class="filter-input">
            </div>

            <div class="filter-group" style="display: flex; flex-direction: row; gap: 8px;">
                <button type="submit" class="btn-filter-submit" style="flex: 1;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    Filtrar
                </button>
                <a href="<?= htmlspecialchars($_SERVER['SCRIPT_NAME']) ?>" class="btn-filter-reset" title="Limpar Filtros">
                    Limpar
                </a>
            </div>
        </form>
    </div>

    <!-- Cards de Indicadores de Topo (KPIs) -->
    <div class="kpi-grid">
        <div class="kpi-card emerald">
            <div class="kpi-label">
                Casos de Retrabalho
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-emerald-600"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>
            </div>
            <div class="kpi-val"><?= number_format($totalCasosRetrabalho, 0, ',', '.') ?></div>
            <div class="kpi-sub"><?= $casosFinalizadosCont ?> concluídos / <?= ($totalCasosRetrabalho - $casosFinalizadosCont) ?> em aberto</div>
        </div>

        <div class="kpi-card blue">
            <div class="kpi-label">
                Horas Acumuladas
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-sky-600"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div class="kpi-val"><?= number_format($totalHorasRetrabalho, 1, ',', '.') ?> <span style="font-size: 0.85rem; font-weight: normal; color: #64748b;">h</span></div>
            <div class="kpi-sub">Média por caso: <?= number_format($mediaHorasPorCaso, 2, ',', '.') ?> h (<?= round($mediaHorasPorCaso * 60) ?> min)</div>
        </div>

        <?php if ($podeVerValores): ?>
            <div class="kpi-card">
                <div class="kpi-label">
                    Custo Mão-de-Obra
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-slate-600"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                </div>
                <div class="kpi-val" style="color: #1e293b;">R$ <?= number_format($totalCustoMaoObra, 2, ',', '.') ?></div>
                <div class="kpi-sub">Baseada no tempo padrão das reprovas</div>
            </div>

            <div class="kpi-card gold">
                <div class="kpi-label">
                    Custo Peças / Materiais
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-amber-600"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                </div>
                <div class="kpi-val" style="color: var(--rep-gold-hover);">R$ <?= number_format($totalCustoPecas, 2, ',', '.') ?></div>
                <div class="kpi-sub"><?= count($todosMateriaisConsumidos) === 1 ? '1 tipo de item aplicado' : count($todosMateriaisConsumidos) . ' tipos de itens aplicados' ?></div>
            </div>

            <div class="kpi-card red">
                <div class="kpi-label">
                    Custo Total de Retrabalho
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-rose-600"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                </div>
                <div class="kpi-val" style="color: #b91c1c;">R$ <?= number_format($totalCustoGeral, 2, ',', '.') ?></div>
                <div class="kpi-sub">Média por transformador: R$ <?= number_format($custoMedioPorCaso, 2, ',', '.') ?></div>
            </div>
        <?php else: ?>
            <div class="kpi-card">
                <div class="kpi-label">Média de Horas / Caso</div>
                <div class="kpi-val"><?= number_format($mediaHorasPorCaso, 2, ',', '.') ?> h</div>
                <div class="kpi-sub"><?= round($mediaHorasPorCaso * 60) ?> minutos médios por transformador</div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Gráficos Interativos -->
    <div class="charts-grid">
        <!-- Gráfico 1: Por Tipo de Reprova -->
        <div class="chart-card">
            <div class="chart-card-header">
                <h3>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-rose-600">
                        <polygon points="12 2 2 7 12 12 22 7 12 2"/>
                        <polyline points="2 17 12 22 22 17"/>
                        <polyline points="2 12 12 17 22 12"/>
                    </svg>
                    <?= $podeVerValores ? 'Impacto Financeiro por Tipo de Reprova (R$)' : 'Horas de Reparo por Tipo de Reprova (h)' ?>
                </h3>
            </div>
            <div class="chart-wrapper">
                <canvas id="chartReprovas"></canvas>
            </div>
        </div>

        <!-- Gráfico 2: Por Setor de Destino -->
        <div class="chart-card">
            <div class="chart-card-header">
                <h3>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-emerald-700">
                        <rect x="3" y="3" width="7" height="7"/>
                        <rect x="14" y="3" width="7" height="7"/>
                        <rect x="14" y="14" width="7" height="7"/>
                        <rect x="3" y="14" width="7" height="7"/>
                    </svg>
                    Distribuição por Setor de Destino
                </h3>
            </div>
            <div class="chart-wrapper">
                <canvas id="chartSetores"></canvas>
            </div>
        </div>
    </div>

    <!-- Tabela 1: Resumo Analítico por Tipo de Reprova -->
    <div class="section-card">
        <div class="section-card-header">
            <h3>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-amber-500">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 16 14"/>
                </svg>
                Análise de Horas e Custos por Tipo de Reprova
            </h3>
            <span class="text-xs font-semibold px-2.5 py-1 bg-slate-100 text-slate-700 rounded-full">
                <?= count($analiseReprovas) ?> causas identificadas
            </span>
        </div>

        <div class="table-scroll">
            <table class="report-table">
                <thead>
                    <tr>
                        <th style="width: 80px;">Código</th>
                        <th>Família / Categoria</th>
                        <th>Descrição da Falha</th>
                        <th style="text-align: center; width: 110px;">Tempo Padrão</th>
                        <th style="text-align: center; width: 90px;">Casos</th>
                        <th style="text-align: right; width: 120px;">Horas Totais</th>
                        <?php if ($podeVerValores): ?>
                            <th style="text-align: right; width: 130px;">Custo MO</th>
                            <th style="text-align: right; width: 130px;">Custo Peças</th>
                            <th style="text-align: right; width: 140px;">Custo Total</th>
                            <th style="text-align: right; width: 90px;">% Custo</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($analiseReprovas)): ?>
                        <tr><td colspan="10" style="text-align: center; color: #94a3b8; padding: 24px;">Nenhum registro encontrado no período selecionado.</td></tr>
                    <?php else: ?>
                        <?php foreach ($analiseReprovas as $ar): 
                            $pctCusto = $totalCustoGeral > 0 ? round(($ar['custo_total'] / $totalCustoGeral) * 100, 1) : 0.0;
                        ?>
                            <tr>
                                <td>
                                    <strong style="color: var(--rep-primary); font-family: monospace;"><?= htmlspecialchars($ar['codigo']) ?></strong>
                                </td>
                                <td>
                                    <span class="badge-tag ger"><?= htmlspecialchars($ar['familia']) ?></span>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($ar['descricao']) ?></strong>
                                </td>
                                <td style="text-align: center;" class="num-mono">
                                    <?= (int) ($ar['tempo_minutos'] ?? 60) ?> min
                                </td>
                                <td style="text-align: center;" class="num-mono">
                                    <?= (int) $ar['ocorrencias'] ?>
                                </td>
                                <td style="text-align: right;" class="num-mono">
                                    <?= number_format((float) $ar['horas'], 2, ',', '.') ?> h
                                </td>
                                <?php if ($podeVerValores): ?>
                                    <td style="text-align: right; color: #475569;" class="num-mono">
                                        R$ <?= number_format((float) $ar['custo_mo'], 2, ',', '.') ?>
                                    </td>
                                    <td style="text-align: right; color: #d97706;" class="num-mono">
                                        R$ <?= number_format((float) $ar['custo_pecas'], 2, ',', '.') ?>
                                    </td>
                                    <td style="text-align: right; font-weight: 800; color: #b91c1c;" class="num-mono">
                                        R$ <?= number_format((float) $ar['custo_total'], 2, ',', '.') ?>
                                    </td>
                                    <td style="text-align: right; color: #64748b;" class="num-mono">
                                        <?= number_format($pctCusto, 1, ',', '.') ?>%
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Tabela 2: Consumo Físico e Financeiro de Materiais -->
    <div class="section-card">
        <div class="section-card-header">
            <h3>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-amber-600">
                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                </svg>
                Consumo de Peças & Materiais de Retrabalho
            </h3>
            <span class="text-xs font-semibold px-2.5 py-1 bg-amber-50 text-amber-800 rounded-full">
                <?= count($todosMateriaisConsumidos) ?> itens distintos
            </span>
        </div>

        <div class="table-scroll">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Material / Peça Aplicada</th>
                        <th style="text-align: center; width: 100px;">Unidade</th>
                        <th style="text-align: right; width: 120px;">Quantidade Total</th>
                        <th style="text-align: center; width: 120px;">Ocorrências</th>
                        <?php if ($podeVerValores): ?>
                            <th style="text-align: right; width: 150px;">Custo Unitário</th>
                            <th style="text-align: right; width: 160px;">Custo Total Acumulado</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($todosMateriaisConsumidos)): ?>
                        <tr><td colspan="6" style="text-align: center; color: #94a3b8; padding: 20px;">Nenhum material registrado nas triagens deste período.</td></tr>
                    <?php else: ?>
                        <?php foreach ($todosMateriaisConsumidos as $mat): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($mat['descricao']) ?></strong>
                                </td>
                                <td style="text-align: center;">
                                    <span class="badge-tag ger"><?= htmlspecialchars($mat['unidade']) ?></span>
                                </td>
                                <td style="text-align: right;" class="num-mono">
                                    <?= number_format((float) $mat['quantidade'], 2, ',', '.') ?>
                                </td>
                                <td style="text-align: center;" class="num-mono">
                                    <?= (int) $mat['ocorrencias'] ?>
                                </td>
                                <?php if ($podeVerValores): ?>
                                    <td style="text-align: right; color: #475569;" class="num-mono">
                                        R$ <?= number_format((float) $mat['custo_unitario'], 2, ',', '.') ?>
                                    </td>
                                    <td style="text-align: right; font-weight: 800; color: #d97706;" class="num-mono">
                                        R$ <?= number_format((float) $mat['custo_total'], 2, ',', '.') ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Tabela 3: Relação Detalhada com Botão de Drilldown -->
    <div class="section-card">
        <div class="section-card-header">
            <h3>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-emerald-700">
                    <line x1="8" y1="6" x2="21" y2="6"/>
                    <line x1="8" y1="12" x2="21" y2="12"/>
                    <line x1="8" y1="18" x2="21" y2="18"/>
                    <line x1="3" y1="6" x2="3.01" y2="6"/>
                    <line x1="3" y1="12" x2="3.01" y2="12"/>
                    <line x1="3" y1="18" x2="3.01" y2="18"/>
                </svg>
                Relação Analítica por Transformador / Caso
            </h3>
            <span class="text-xs text-slate-500">Exibindo <?= count($registrosProcessados) ?> lançamentos</span>
        </div>

        <div class="table-scroll">
            <table class="report-table">
                <thead>
                    <tr>
                        <th style="width: 100px;">NS</th>
                        <th>Projeto / Pedido</th>
                        <th style="width: 80px; text-align: center;">Estação</th>
                        <th>Causa da Reprova</th>
                        <th style="text-align: center; width: 110px;">Tempo Reprova</th>
                        <th>Setores Destino</th>
                        <th style="text-align: center; width: 100px;">Status</th>
                        <th style="text-align: right; width: 80px;">Horas</th>
                        <?php if ($podeVerValores): ?>
                            <th style="text-align: right; width: 120px;">Custo Total</th>
                        <?php endif; ?>
                        <th style="text-align: center; width: 80px;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($registrosProcessados)): ?>
                        <tr><td colspan="10" style="text-align: center; color: #94a3b8; padding: 24px;">Nenhum registro encontrado com os filtros informados.</td></tr>
                    <?php else: ?>
                        <?php foreach ($registrosProcessados as $idx => $reg): ?>
                            <tr>
                                <td>
                                    <strong style="color: var(--rep-primary); font-family: monospace; font-size: 0.95rem;">
                                        <?= htmlspecialchars($reg['ns_transformador'] ?: '-') ?>
                                    </strong>
                                </td>
                                <td>
                                    <div style="font-weight: 600; color: #1e293b;">
                                        <?= htmlspecialchars($reg['projeto_codigo'] ?: '-') ?>
                                    </div>
                                    <div style="font-size: 0.74rem; color: #64748b;">
                                        Ped: <?= htmlspecialchars($reg['pedido_numero'] ?: '-') ?>
                                    </div>
                                </td>
                                <td style="text-align: center;">
                                    <span class="badge-tag <?= strtolower($reg['estacao'] ?? 'ger') ?>">
                                        <?= htmlspecialchars($reg['estacao'] ?? '-') ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="font-family: monospace; font-weight: 700; color: var(--rep-primary);">
                                        <?= htmlspecialchars($reg['reprova_codigo'] ?: '') ?>
                                    </span>
                                    <div style="font-size: 0.8rem; color: #334155;">
                                        <?= htmlspecialchars($reg['reprova_descricao'] ?: ($reg['causa_reprova'] ?: '-')) ?>
                                    </div>
                                </td>
                                <td style="text-align: center;" class="num-mono">
                                    <?= (int) ($reg['minutos_padrao'] ?? 60) ?> min
                                </td>
                                <td>
                                    <?php 
                                    $destStr = (string) ($reg['setores_destino'] ?? '');
                                    if ($destStr !== ''):
                                        $dList = explode(',', $destStr);
                                        foreach ($dList as $dItem):
                                            $lbl = $setoresDisponiveis[trim($dItem)] ?? trim($dItem);
                                    ?>
                                        <span class="badge-tag ger" style="margin: 1px;"><?= htmlspecialchars($lbl) ?></span>
                                    <?php 
                                        endforeach;
                                    else: 
                                    ?>
                                        <span style="color: #94a3b8; font-size: 0.78rem;">Não definido</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <span class="badge-status <?= in_array($reg['status'], ['finalizado', 'aprovado'], true) ? 'finalizado' : 'em_andamento' ?>">
                                        <?= htmlspecialchars($reg['status'] === 'finalizado' ? 'Finalizado' : ($reg['status'] === 'aprovado' ? 'Aprovado' : 'Em Aberto')) ?>
                                    </span>
                                </td>
                                <td style="text-align: right;" class="num-mono">
                                    <?= number_format((float) $reg['horas_trabalhadas'], 2, ',', '.') ?> h
                                </td>
                                <?php if ($podeVerValores): ?>
                                    <td style="text-align: right; font-weight: 750; color: #b91c1c;" class="num-mono">
                                        R$ <?= number_format((float) $reg['custo_total'], 2, ',', '.') ?>
                                    </td>
                                <?php endif; ?>
                                <td style="text-align: center;">
                                    <button type="button" class="btn-drilldown" onclick="abrirDrilldown(<?= htmlspecialchars(json_encode($reg, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>)">
                                        Detalhes
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal de Drilldown -->
<div id="modalDrilldown" class="modal-overlay" onclick="fecharDrilldown(event)">
    <div class="modal-content-box" onclick="event.stopPropagation()">
        <div class="modal-header">
            <h3>Detalhes do Retrabalho & Apontamentos</h3>
            <button type="button" class="modal-close-btn" onclick="fecharDrilldown()">&times;</button>
        </div>
        <div id="modalBodyDrilldown">
            <!-- Conteúdo dinâmico preenchido via JavaScript -->
        </div>
    </div>
</div>

<script>
// ─── Presets Rápidos de Data ──────────────────────────────────────────────────
function aplicarPresetData(tipo) {
    const hoje = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    const toIso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

    let de, ate;

    if (tipo === 'hoje') {
        de = toIso(hoje);
        ate = toIso(hoje);
    } else if (tipo === '7d') {
        const d7 = new Date();
        d7.setDate(hoje.getDate() - 7);
        de = toIso(d7);
        ate = toIso(hoje);
    } else if (tipo === 'mes_atual') {
        de = `${hoje.getFullYear()}-${pad(hoje.getMonth() + 1)}-01`;
        ate = toIso(hoje);
    } else if (tipo === 'mes_anterior') {
        const priAnt = new Date(hoje.getFullYear(), hoje.getMonth() - 1, 1);
        const ultAnt = new Date(hoje.getFullYear(), hoje.getMonth(), 0);
        de = toIso(priAnt);
        ate = toIso(ultAnt);
    } else if (tipo === '90d') {
        const d90 = new Date();
        d90.setDate(hoje.getDate() - 90);
        de = toIso(d90);
        ate = toIso(hoje);
    }

    document.getElementById('data_de').value = de;
    document.getElementById('data_ate').value = ate;
    document.getElementById('formFiltroRelatorio').submit();
}

// ─── Renderização dos Gráficos Chart.js ───────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    // 1. Gráfico Reprovas (Horizontal Bar Chart para legibilidade perfeita dos textos)
    const ctxR = document.getElementById('chartReprovas');
    if (ctxR) {
        new Chart(ctxR, {
            type: 'bar',
            data: {
                labels: <?= json_encode($chartReprovaLabels) ?>,
                datasets: [{
                    label: '<?= $podeVerValores ? "Custo Total (R$)" : "Horas de Reparo (h)" ?>',
                    data: <?= json_encode($podeVerValores ? $chartReprovaCustos : $chartReprovaHoras) ?>,
                    backgroundColor: 'rgba(220, 38, 38, 0.85)',
                    borderColor: '#b91c1c',
                    borderWidth: 1,
                    borderRadius: 6,
                    hoverBackgroundColor: '#b91c1c'
                }]
            },
            options: {
                indexAxis: 'y', // Barra horizontal elimina sobreposição de rótulos
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return '<?= $podeVerValores ? "R$ " : "" ?>' + ctx.raw.toLocaleString('pt-BR', {minimumFractionDigits: 2}) + '<?= $podeVerValores ? "" : " h" ?>';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: { color: '#f1f5f9' },
                        ticks: {
                            callback: function(v) { return '<?= $podeVerValores ? "R$ " : "" ?>' + v.toLocaleString('pt-BR'); }
                        }
                    },
                    y: {
                        grid: { display: false },
                        ticks: {
                            font: { size: 11, weight: '600' },
                            color: '#334155'
                        }
                    }
                }
            }
        });
    }

    // 2. Gráfico Setores (Doughnut com paleta refinada e central text)
    const ctxS = document.getElementById('chartSetores');
    if (ctxS) {
        new Chart(ctxS, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($chartSetorLabels) ?>,
                datasets: [{
                    data: <?= json_encode($podeVerValores ? $chartSetorCustos : $chartSetorHoras) ?>,
                    backgroundColor: <?= json_encode($chartSetorColors) ?>,
                    borderWidth: 2,
                    borderColor: '#ffffff',
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: {
                        position: 'right',
                        labels: { 
                            boxWidth: 12, 
                            font: { size: 11, family: 'Plus Jakarta Sans', weight: '600' },
                            padding: 10
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                const val = ctx.raw || 0;
                                return ' ' + ctx.label + ': <?= $podeVerValores ? "R$ " : "" ?>' + val.toLocaleString('pt-BR', {minimumFractionDigits: 2}) + '<?= $podeVerValores ? "" : " h" ?>';
                            }
                        }
                    }
                }
            }
        });
    }
});

// ─── Modal de Drilldown ───────────────────────────────────────────────────────
function abrirDrilldown(data) {
    const modal = document.getElementById('modalDrilldown');
    const body  = document.getElementById('modalBodyDrilldown');

    let pecasHtml = '<p style="color: #94a3b8; font-size: 0.85rem;">Nenhuma peça registrada nesta triagem.</p>';
    if (data.pecas_detalhes && data.pecas_detalhes.length > 0) {
        pecasHtml = `
            <table class="report-table" style="margin-top: 8px;">
                <thead>
                    <tr>
                        <th>Material / Peça</th>
                        <th style="text-align: center;">Unidade</th>
                        <th style="text-align: right;">Qtd</th>
                        ${ <?= $podeVerValores ? 'true' : 'false' ?> ? '<th style="text-align: right;">Custo Unit.</th><th style="text-align: right;">Custo Total</th>' : '' }
                    </tr>
                </thead>
                <tbody>
                    ${data.pecas_detalhes.map(p => `
                        <tr>
                            <td><strong>${p.descricao}</strong></td>
                            <td style="text-align: center;"><span class="badge-tag ger">${p.unidade}</span></td>
                            <td style="text-align: right;" class="num-mono">${Number(p.quantidade).toLocaleString('pt-BR')}</td>
                            ${ <?= $podeVerValores ? 'true' : 'false' ?> ? `
                                <td style="text-align: right;" class="num-mono">R$ ${Number(p.custo_unitario).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</td>
                                <td style="text-align: right; font-weight: 700; color: #b91c1c;" class="num-mono">R$ ${Number(p.custo_total).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</td>
                            ` : '' }
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;
    }

    body.innerHTML = `
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px;">
            <div style="background: #f8fafc; padding: 12px; border-radius: 10px; border: 1px solid #e2e8f0;">
                <div style="font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase;">Transformador / Projeto</div>
                <div style="font-size: 1.1rem; font-weight: 800; color: #133a27;">NS ${data.ns_transformador || '-'}</div>
                <div style="font-size: 0.85rem; color: #334155;">Projeto: <strong>${data.projeto_codigo || '-'}</strong> (Ped: ${data.pedido_numero || '-'})</div>
            </div>
            <div style="background: #f8fafc; padding: 12px; border-radius: 10px; border: 1px solid #e2e8f0;">
                <div style="font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase;">Tempo Padrão & Custos</div>
                <div style="font-size: 1.1rem; font-weight: 800; color: #b91c1c;">
                    ${ <?= $podeVerValores ? 'true' : 'false' ?> ? 'R$ ' + Number(data.custo_total).toLocaleString('pt-BR', {minimumFractionDigits: 2}) : data.horas_trabalhadas + ' h' }
                </div>
                <div style="font-size: 0.85rem; color: #334155;">Tempo Padrão: <strong>${data.minutos_padrao} min</strong> (${data.horas_trabalhadas}h)</div>
            </div>
        </div>

        <div style="margin-bottom: 16px;">
            <h4 style="margin: 0 0 6px; font-size: 0.9rem; color: #1e293b;">Diagnóstico & Causa Raiz</h4>
            <div style="background: #f1f5f9; padding: 10px 14px; border-radius: 8px; font-size: 0.85rem; color: #334155;">
                <div><strong>Reprova:</strong> [${data.reprova_codigo || '-'}] ${data.reprova_descricao || data.causa_reprova || '-'}</div>
                ${data.causa_raiz ? `<div style="margin-top: 4px;"><strong>Causa Raiz:</strong> ${data.causa_raiz}</div>` : ''}
                ${data.correcao ? `<div style="margin-top: 4px;"><strong>Ação Corretiva:</strong> ${data.correcao}</div>` : ''}
            </div>
        </div>

        <div>
            <h4 style="margin: 0 0 6px; font-size: 0.9rem; color: #1e293b;">Peças e Materiais Trocados</h4>
            ${pecasHtml}
        </div>
    `;

    modal.style.display = 'flex';
}

function fecharDrilldown() {
    document.getElementById('modalDrilldown').style.display = 'none';
}
</script>

<?php layoutFooter(); ?>
