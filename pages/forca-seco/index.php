<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/boletim-planilha.php';

requireLogin();

$pdo        = getDB();
$usuario    = currentUser();
$podeEditar = isAdmin() || !in_array((int) ($usuario['id_perfil'] ?? 0), [4, 211], true);

// ─── Linhas de Produção da Média Força ───────────────────────────────────────
// TPD: Transformadores até 300 kVA (corresponde ao ENR na Distribuição)
// TPS: Transformadores Seco (início 'TPS', corresponde ao JC-TRIF)
// TPM: Transformadores acima de 300 kVA (corresponde ao EMP)
// LAB: Reprovas de Laboratório (Almoxarifado 422)
$NUCLEOS          = ['TPD' => 'TPD', 'TPS' => 'TPS', 'TPM' => 'TPM', 'LAB' => 'REP'];
$NUCLEOS_PRODUCAO = ['TPD', 'TPS', 'TPM'];
$CORES_NUCLEO     = ['TPD' => '#82c341', 'TPS' => '#4a90e2', 'TPM' => '#00a86b', 'LAB' => '#dc2626'];

// ─── Mês e Intervalo de Datas do relatório ──────────────────────────────────
$modoData   = trim((string) ($_GET['modo_data'] ?? ''));
$dataInicio = trim((string) ($_GET['data_inicio'] ?? ''));
$dataFim    = trim((string) ($_GET['data_fim'] ?? ''));
$mes        = trim((string) ($_GET['mes'] ?? ''));

if ($dataInicio !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInicio)) {
    $dataInicio = '';
}
if ($dataFim !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFim)) {
    $dataFim = '';
}

$isPersonalizado = ($modoData === 'personalizado' && $dataInicio !== '' && $dataFim !== '');

if ($isPersonalizado) {
    if ($dataInicio > $dataFim) {
        [$dataInicio, $dataFim] = [$dataFim, $dataInicio];
    }
    $primeiroDia = $dataInicio;
    $ultimoDia   = $dataFim;
    $mes         = date('Y-m', strtotime($dataInicio));
    [$anoRef, $mesRef] = array_map('intval', explode('-', $mes));
    $labelFiltroData = date('d/m/Y', strtotime($dataInicio)) . ' a ' . date('d/m/Y', strtotime($dataFim));
    
    $diasAtivosCalendario = [];
    $currTs = strtotime($primeiroDia);
    $fimTs  = strtotime($ultimoDia);
    while ($currTs <= $fimTs) {
        $dStr = date('Y-m-d', $currTs);
        if ((int) date('N', $currTs) <= 5) {
            $diasAtivosCalendario[] = $dStr;
        }
        $currTs = strtotime('+1 day', $currTs);
    }
    if (empty($diasAtivosCalendario)) {
        $currTs = strtotime($primeiroDia);
        while ($currTs <= $fimTs) {
            $diasAtivosCalendario[] = date('Y-m-d', $currTs);
            $currTs = strtotime('+1 day', $currTs);
        }
    }
    $diasNoMes = count($diasAtivosCalendario);
    $diasUteis = count($diasAtivosCalendario);
} else {
    if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
        $mes = date('Y-m');
    }
    if ($modoData === '') {
        $modoData = ($mes === date('Y-m')) ? 'hoje' : 'mes';
    }
    [$anoRef, $mesRef] = array_map('intval', explode('-', $mes));
    $primeiroDia = sprintf('%04d-%02d-01', $anoRef, $mesRef);
    $diasNoMes   = (int) date('t', strtotime($primeiroDia));
    $ultimoDia   = sprintf('%04d-%02d-%02d', $anoRef, $mesRef, $diasNoMes);
    $labelFiltroData = date('m/Y', strtotime($mes . '-01'));

    // Config de metas do mês
    $diasAtivosCalendario = [];
    for ($i = 1; $i <= $diasNoMes; $i++) {
        $d = sprintf('%04d-%02d-%02d', $anoRef, $mesRef, $i);
        if ((int) date('N', strtotime($d)) <= 5) $diasAtivosCalendario[] = $d;
    }
    $diasUteis = count($diasAtivosCalendario);
}

// ─── Config de metas do mês ──────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM boletim_config_metas WHERE `month` = ?");
$stmt->execute([$mes]);
$config = $stmt->fetch() ?: [
    'meta_tpm' => 0,
    'meta_tps' => 0,
    'meta_tpd_forca' => 0,
    'dias_customizados' => null,
];
$metaTpd        = (int) ($config['meta_tpd_forca'] ?? 0);
$metaTps        = (int) ($config['meta_tps'] ?? 0);
$metaTpm        = (int) ($config['meta_tpm'] ?? 0);
$metaTotalForca = $metaTpd + $metaTps + $metaTpm;

// Dias de produção do calendário interativo do modal "Métricas"
$diasCustomizadosRaw = $config['dias_customizados'] ?? null;
$diasAtivosArray = null;
if (!empty($diasCustomizadosRaw)) {
    $diasAtivosArray = json_decode($diasCustomizadosRaw, true);
}
if (!$isPersonalizado && is_array($diasAtivosArray) && !empty($diasAtivosArray)) {
    $diasAtivosCalendario = $diasAtivosArray;
    $diasUteis = count($diasAtivosCalendario);
}
$diasTrabalhados = boletimDiasUteisTrabalhados($mes);

// Dados de todos os dias do mês para o Calendário Interativo do Modal
$todosDiasCalendario = [];
$primeiroDiaSemanaMes = (int) date('w', mktime(0, 0, 0, $mesRef, 1, $anoRef)); // 0=Dom, 6=Sáb
for ($i = 1; $i <= $diasNoMes; $i++) {
    $dataStr = sprintf('%04d-%02d-%02d', $anoRef, $mesRef, $i);
    $diaSemana = (int) date('w', strtotime($dataStr));
    $todosDiasCalendario[] = [
        'dia' => $i,
        'date' => $dataStr,
        'diaSemana' => $diaSemana,
        'fimDeSemana' => ($diaSemana === 0 || $diaSemana === 6),
        'ativo' => in_array($dataStr, $diasAtivosCalendario, true),
    ];
}

// ─── Registros do mês ────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT * FROM boletim_registros
    WHERE `area` = 'forca' AND deleted_at IS NULL
      AND `date` BETWEEN ? AND ?
    ORDER BY `date`, id
");
$stmt->execute([$primeiroDia, $ultimoDia]);
$registros = $stmt->fetchAll();

$diasDoMes = [];
for ($i = 1; $i <= $diasNoMes; $i++) {
    $diasDoMes[] = sprintf('%04d-%02d-%02d', $anoRef, $mesRef, $i);
}

// ─── Agregações por Linha e Dia ──────────────────────────────────────────────
$porDiaCore = []; // 'Y-m-d' => ['TPD'=>['prog'=>0,'real'=>0], 'TPS'=>..., 'TPM'=>..., 'LAB'=>...]
$totalCore  = [
    'TPD' => ['prog' => 0, 'real' => 0],
    'TPS' => ['prog' => 0, 'real' => 0],
    'TPM' => ['prog' => 0, 'real' => 0],
    'LAB' => ['prog' => 0, 'real' => 0],
];

// Metas diárias planejadas
$metaDiariaTpd   = $diasUteis > 0 ? round($metaTpd / $diasUteis, 2) : 0;
$metaDiariaTps   = $diasUteis > 0 ? round($metaTps / $diasUteis, 2) : 0;
$metaDiariaTpm   = $diasUteis > 0 ? round($metaTpm / $diasUteis, 2) : 0;
$metaDiariaTotal = $diasUteis > 0 ? round($metaTotalForca / $diasUteis, 2) : 0;

$metaPorLinha = [
    'TPD' => $metaDiariaTpd,
    'TPS' => $metaDiariaTps,
    'TPM' => $metaDiariaTpm,
];

// Preenche a partir do Kardex em tempo real com fallback manual
foreach ($diasDoMes as $d) {
    $isDiaAtivo = in_array($d, $diasAtivosCalendario, true);

    $kardexTpd = boletimKardexForcaLinhaDoDia($d, 'TPD');
    $kardexTps = boletimKardexForcaLinhaDoDia($d, 'TPS');
    $kardexTpm = boletimKardexForcaLinhaDoDia($d, 'TPM');
    $kardexRep = boletimKardexReprovasDoDia($d, 'forca');

    $porDiaCore[$d] = [
        'TPD' => ['prog' => $isDiaAtivo ? $metaDiariaTpd : 0, 'real' => $kardexTpd ?? 0],
        'TPS' => ['prog' => $isDiaAtivo ? $metaDiariaTps : 0, 'real' => $kardexTps ?? 0],
        'TPM' => ['prog' => $isDiaAtivo ? $metaDiariaTpm : 0, 'real' => $kardexTpm ?? 0],
        'LAB' => ['prog' => 0, 'real' => $kardexRep ?? 0],
    ];

    // Se houver registro manual salvo que precise sobrepor ou complementar:
    foreach ($registros as $r) {
        if ($r['date'] !== $d) continue;
        $c = $r['line'];
        if ($c === 'TPD' && $kardexTpd === null) $porDiaCore[$d]['TPD']['real'] = (int) $r['real'];
        if ($c === 'TPS' && $kardexTps === null) $porDiaCore[$d]['TPS']['real'] = (int) $r['real'];
        if ($c === 'TPM' && $kardexTpm === null) $porDiaCore[$d]['TPM']['real'] = (int) $r['real'];
        if ($r['core_type'] === 'LAB' && $kardexRep === null) $porDiaCore[$d]['LAB']['real'] = (int) $r['real'];
    }

    foreach (array_keys($totalCore) as $c) {
        $totalCore[$c]['prog'] += $porDiaCore[$d][$c]['prog'];
        $totalCore[$c]['real'] += $porDiaCore[$d][$c]['real'];
    }
}

// ─── Séries para Gráficos ───────────────────────────────────────────────────
$serieProducao = [
    'TPD' => [],
    'TPS' => [],
    'TPM' => [],
    'LAB' => [],
];
$serieExecutadoTotal = [];
$serieMetaTotal      = [];
$seriePercentual     = [];
$acumuladoReal       = [];
$acumuladoMeta       = [];
$accReal             = 0;
$accMeta             = 0;

$totalRealAteHoje = $totalCore['TPD']['real'] + $totalCore['TPS']['real'] + $totalCore['TPM']['real'];

foreach ($diasDoMes as $d) {
    $execDia = $porDiaCore[$d]['TPD']['real'] + $porDiaCore[$d]['TPS']['real'] + $porDiaCore[$d]['TPM']['real'];
    $progDia = $porDiaCore[$d]['TPD']['prog'] + $porDiaCore[$d]['TPS']['prog'] + $porDiaCore[$d]['TPM']['prog'];

    foreach (['TPD', 'TPS', 'TPM', 'LAB'] as $c) {
        $serieProducao[$c][] = $porDiaCore[$d][$c]['real'];
    }

    $serieExecutadoTotal[] = $execDia;
    $serieMetaTotal[]      = $metaDiariaTotal;
    $seriePercentual[]     = $progDia > 0 ? round(($execDia / $progDia) * 100, 1) : 0;

    $accReal += $execDia;
    $accMeta += $progDia;
    $acumuladoReal[] = $accReal;
    $acumuladoMeta[] = round($accMeta, 1);
}

// Dias que de fato tiveram produção (Executado Total > 0) — denominador das
// médias (KPIs e tabela "Produção por Linha"), em vez de dias corridos/úteis
// até hoje, para não diluir a média com dias ainda sem produção lançada.
$diasComProducaoReal = count(array_filter($serieExecutadoTotal, fn($v) => $v > 0));

// ─── Mix de Produção por Dia ────────────────────────────────────────────────
$mixPorDia = [];
foreach ($diasDoMes as $d) {
    $rTpd = $porDiaCore[$d]['TPD']['real'] ?? 0;
    $rTps = $porDiaCore[$d]['TPS']['real'] ?? 0;
    $rTpm = $porDiaCore[$d]['TPM']['real'] ?? 0;
    $somaReal = $rTpd + $rTps + $rTpm;

    $pTpd = $porDiaCore[$d]['TPD']['prog'] ?? 0;
    $pTps = $porDiaCore[$d]['TPS']['prog'] ?? 0;
    $pTpm = $porDiaCore[$d]['TPM']['prog'] ?? 0;
    $somaProg = $pTpd + $pTps + $pTpm;

    $mixPorDia[$d] = [
        'programado' => [
            'TPD'    => $pTpd,
            'TPM'    => $pTpm,
            'TPS'    => $pTps,
            'pctTPD' => $somaProg > 0 ? round(($pTpd / $somaProg) * 100, 1) : 0,
            'pctTPM' => $somaProg > 0 ? round(($pTpm / $somaProg) * 100, 1) : 0,
            'pctTPS' => $somaProg > 0 ? round(($pTps / $somaProg) * 100, 1) : 0,
        ],
        'realizado' => [
            'TPD'    => $rTpd,
            'TPM'    => $rTpm,
            'TPS'    => $rTps,
            'pctTPD' => $somaReal > 0 ? round(($rTpd / $somaReal) * 100, 1) : 0,
            'pctTPM' => $somaReal > 0 ? round(($rTpm / $somaReal) * 100, 1) : 0,
            'pctTPS' => $somaReal > 0 ? round(($rTps / $somaReal) * 100, 1) : 0,
        ],
    ];
}

// Dia padrão para o Mix: o dia mais recente com produção
$diaPadraoMix = $diasDoMes[0];
for ($i = count($diasDoMes) - 1; $i >= 0; $i--) {
    $d = $diasDoMes[$i];
    $prodDia = ($porDiaCore[$d]['TPD']['real'] ?? 0) + ($porDiaCore[$d]['TPS']['real'] ?? 0) + ($porDiaCore[$d]['TPM']['real'] ?? 0);
    if ($prodDia > 0) {
        $diaPadraoMix = $d;
        break;
    }
}

// ─── Dados de Produção por Linha ─────────────────────────────────────────────
$linhasConfig = [
    'TPD' => [
        'nome'       => 'TPD (ATÉ 300 kVA)',
        'cor'        => '#82c341',
        'meta'       => $metaDiariaTpd,
        'label_exec' => 'Produção Realizada TPD',
    ],
    'TPS' => [
        'nome'       => 'TPS (SECO)',
        'cor'        => '#4a90e2',
        'meta'       => $metaDiariaTps,
        'label_exec' => 'Produção Realizada TPS',
    ],
    'TPM' => [
        'nome'       => 'TPM (MÉDIA FORÇA > 300 kVA)',
        'cor'        => '#00a86b',
        'meta'       => $metaDiariaTpm,
        'label_exec' => 'Produção Realizada TPM',
    ],
];

$dadosProducaoPorLinha = [];
foreach ($linhasConfig as $c => $info) {
    $metaDia = (float) $info['meta'];
    $execs   = [];
    $diffs   = [];
    $pcts    = [];
    $diasComProd = 0;

    foreach ($diasDoMes as $d) {
        $exec = (int) ($porDiaCore[$d][$c]['real'] ?? 0);
        $execs[] = $exec;
        if ($exec > 0) $diasComProd++;

        $diff = round($exec - $metaDia, 1);
        $diffs[] = $diff;

        $pct = $metaDia > 0 ? round(($exec / $metaDia) * 100) : 0;
        $pcts[]  = $pct;
    }

    $somaExec  = array_sum($execs);
    $mediaExec = $diasComProd > 0 ? round($somaExec / $diasComProd, 1) : 0;
    $somaMeta  = (int) round($metaDia * count($diasDoMes));
    $diffTotal = $somaExec - $somaMeta;
    $diffMedia = round($mediaExec - $metaDia, 1);
    $pctMedia  = $metaDia > 0 ? round(($mediaExec / $metaDia) * 100) : 0;
    $pctTotal  = $somaMeta > 0 ? round(($somaExec / $somaMeta) * 100) : 0;

    $dadosProducaoPorLinha[$c] = [
        'info'       => $info,
        'execs'      => $execs,
        'diffs'      => $diffs,
        'pcts'       => $pcts,
        'somaExec'   => $somaExec,
        'mediaExec'  => $mediaExec,
        'somaMeta'   => $somaMeta,
        'diffTotal'  => $diffTotal,
        'diffMedia'  => $diffMedia,
        'pctMedia'   => $pctMedia,
        'pctTotal'   => $pctTotal,
    ];
}

// ─── KPIs ────────────────────────────────────────────────────────────────────
$diasParaMedia = $diasComProducaoReal;
$mediaDiaria   = [];
foreach ($NUCLEOS_PRODUCAO as $c) {
    $mediaDiaria[$c] = $diasParaMedia > 0 ? $totalCore[$c]['real'] / $diasParaMedia : 0;
}
$totalLab = $totalCore['LAB']['real'];
$mediaDiariaLab = $diasParaMedia > 0 ? $totalLab / $diasParaMedia : 0;
$pctReprovas = ($totalRealAteHoje > 0) ? round(($totalLab / $totalRealAteHoje) * 100, 2) : 0.0;

// Potências Médias por Linha
$potenciaMediaLinha = [
    'TPD' => boletimKardexPotenciaMediaForcaLinha($mes, 'TPD'),
    'TPS' => boletimKardexPotenciaMediaForcaLinha($mes, 'TPS'),
    'TPM' => boletimKardexPotenciaMediaForcaLinha($mes, 'TPM'),
];

// Potência Média Geral Média Força / Seco
$seriePotencia = [];
foreach ($diasDoMes as $d) {
    $kVal = boletimKardexPotenciaMediaDoDia($d, 'forca_total');
    if ($kVal === null) {
        $kVal = boletimKardexPotenciaMediaDoDia($d, 'forca_tpm_tpd');
    }
    $seriePotencia[] = $kVal;
}
$valoresPotencia = array_filter($seriePotencia, fn($v) => $v !== null);
$potenciaMediaMensal = count($valoresPotencia) > 0 ? array_sum($valoresPotencia) / count($valoresPotencia) : 0;

$mediaExecutadaGeral = $diasParaMedia > 0 ? $totalRealAteHoje / $diasParaMedia : 0;

// ─── Bloco "HOJE" ────────────────────────────────────────────────────────────
$MESES_PT = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho',
             7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
$hojeTs   = time();
$hojeTxt  = date('d/m/Y', $hojeTs);
$mesTxt   = $MESES_PT[$mesRef] . '/' . $anoRef;

$pageTitle = 'Indicador Média Força';
layoutHeader($pageTitle);
?>
<style>
    .bo-ref-row { display:flex; flex-wrap:wrap; align-items:center; gap:10px; margin-bottom:20px; }
    .bo-ref-chip {
        display:inline-flex; align-items:center; gap:8px;
        background:var(--color-surface); border:1px solid var(--color-border);
        border-radius:var(--radius-md); padding:0 12px; height:36px; box-sizing:border-box; box-shadow:var(--shadow-sm);
    }
    .bo-ref-chip .bo-ref-label { font-size:var(--font-size-xs); font-weight:600; text-transform:uppercase; letter-spacing:.6px; color:var(--color-text-muted); line-height:1; }
    .bo-ref-chip .bo-ref-value { font-size:var(--font-size-sm); font-weight:600; color:var(--color-text-primary); line-height:1; }
    .bo-ref-chip form { display:flex; align-items:center; margin:0; }
    .bo-ref-chip input[type=month] {
        border:1px solid var(--color-border-strong);
        border-radius:var(--radius-sm);
        padding:2px 6px;
        height:26px;
        font-size:var(--font-size-xs);
        font-family:var(--font-sans);
        color:var(--color-text-primary);
        background:transparent;
        box-sizing:border-box;
    }

    .bo-header-row { display:flex; flex-wrap:wrap; align-items:flex-start; justify-content:space-between; gap:16px; margin-bottom:18px; }
    .bo-kpis-grid-5 { display:grid; grid-template-columns:repeat(5, 1fr); gap:16px; margin-bottom:20px; }
    @media (max-width:1100px) { .bo-kpis-grid-5 { grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); } }

    .bo-charts-grid { display:grid; grid-template-columns:minmax(0, 1fr); min-width:0; width:100%; max-width:100%; gap:16px; margin-bottom:16px; }
    .bo-chart-card { min-width:0; max-width:100%; width:100%; overflow:hidden; }
    .bo-chart-card canvas { max-height:420px; max-width:100%; }

    .bo-mix-badge {
        font-size:var(--font-size-xs); color:var(--color-text-muted);
        background:var(--color-surface-2); border:1px solid var(--color-border);
        border-radius:var(--radius-full); padding:3px 10px;
    }
    .bo-mix-body { display:flex; align-items:center; gap:24px; flex-wrap:wrap; }
    .bo-mix-body canvas { max-height:180px; max-width:180px; }
    .bo-mix-legend { display:flex; flex-direction:column; gap:10px; font-size:var(--font-size-base); }
    .bo-mix-legend .dot { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:8px; }

    .bo-table-wrap { width:100%; max-width:100%; min-width:0; overflow-x:auto; -webkit-overflow-scrolling:touch; }
    .bo-nucleo-table { width:100%; border-collapse:collapse; font-size:12px; }
    .bo-nucleo-table th, .bo-nucleo-table td {
        padding:5px 4px; text-align:center; border-bottom:1px solid var(--color-border); white-space:nowrap; font-size:11px;
    }
    .bo-nucleo-table th:first-child, .bo-nucleo-table td:first-child {
        text-align:left; position:sticky; left:0; background:var(--color-surface); z-index:2; min-width:95px; padding-left:6px;
    }
    .bo-nucleo-table th { background:var(--color-surface-2); color:var(--color-text-secondary); font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.3px; }
    .bo-nucleo-table td.bo-zero { color:var(--color-text-muted); }

    /* Estilos do Calendário Interativo no Modal */
    .cal-day-btn {
        height: 38px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        border-radius: var(--radius-sm);
        font-family: inherit;
        cursor: pointer;
        transition: all 0.15s ease;
        user-select: none;
        border: 1px solid transparent;
        background: var(--color-surface-2);
        color: var(--color-text-muted);
    }
    .cal-day-btn.active {
        background: var(--color-accent);
        color: #ffffff;
        border-color: var(--color-accent);
        font-weight: 700;
        box-shadow: 0 1px 3px rgba(0,0,0,0.15);
    }
    .cal-day-btn.active:hover { filter: brightness(0.92); }
    .cal-day-btn.inactive {
        background: var(--color-surface-2);
        color: var(--color-text-muted);
        border-color: var(--color-border);
        opacity: 0.55;
    }
    .cal-day-btn.inactive:hover { opacity: 0.9; border-color: var(--color-border-strong); }
</style>

<div class="bo-header-row">
    <div>
        <h1 style="font-size:var(--font-size-xl);font-weight:700;">Indicador Média Força</h1>
        <p class="text-secondary" style="font-size:var(--font-size-base);margin-top:2px;">
            Meta × Realizado do mês (Média Força / Seco)
        </p>
    </div>
    <div class="bo-ref-row" style="margin-bottom:0;align-items:center;">
        <button type="button" class="btn btn-primary btn-sm" id="btn-abrir-metricas" onclick="abrirModalMetricas()" style="display:inline-flex;align-items:center;gap:6px;padding:8px 14px;font-weight:700;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20v-6M6 20V10M18 20V4"/></svg>
            Métricas
        </button>
        <div class="bo-ref-chip">
            <span class="bo-ref-label">Sincronizado</span>
            <span class="bo-ref-value" style="font-size:var(--font-size-sm);color:var(--color-text-secondary);"><?= htmlspecialchars(boletimKardexUltimaSincronizacao($mes)) ?></span>
        </div>
        <div class="bo-ref-chip">
            <span class="bo-ref-label">Hoje</span>
            <span class="bo-ref-value"><?= htmlspecialchars($hojeTxt) ?></span>
        </div>
        <!-- Botão Filtro de Data (Estilo Painel por Setor) -->
        <button type="button" class="bo-ref-chip" onclick="abrirFiltroDataModal()" style="cursor:pointer;transition:all 0.15s ease;background:var(--color-surface);border:1px solid var(--color-border);color:inherit;font-family:inherit;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--color-text-muted);"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <span class="bo-ref-label">Filtro de Data</span>
            <span class="bo-ref-value font-mono">
                <?= htmlspecialchars($labelFiltroData) ?>
            </span>
        </button>
        <!-- Chip Auto-Refresh 30s (Padrão Retrabalho) -->
        <div class="bo-ref-chip" id="chipAutoRefresh" onclick="alternarAutoRefresh()" style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;" title="Clique para pausar/retomar auto-refresh">
            <span class="pulse-dot" style="width:8px;height:8px;background:#22c55e;border-radius:50%;display:inline-block;"></span>
            <span class="bo-ref-label">Auto-refresh:</span>
            <span class="bo-ref-value font-mono" id="labelTimerRefresh">30s</span>
        </div>
        <div style="display:flex;gap:8px;">
            <button type="button" class="btn btn-secondary btn-sm" id="btn-sync-banco" onclick="atualizarDoBanco('<?= htmlspecialchars($mes) ?>')" style="display:inline-flex;align-items:center;gap:6px;padding:8px 12px;font-weight:600;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
                Atualizar do Banco
            </button>
            <button type="button" class="btn btn-secondary btn-sm" id="btn-export-csv" onclick="baixarArquivoComSpinner(this, '<?= htmlspecialchars($base) ?>/api/boletim-exportar.php?mes=<?= htmlspecialchars($mes) ?>&area=forca', 'boletim_auditoria_media_forca_<?= htmlspecialchars($mes) ?>.csv')" style="display:inline-flex;align-items:center;gap:6px;padding:8px 12px;font-weight:600;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Exportar Dados (CSV)
            </button>
        </div>
    </div>
</div>

<!-- KPIs - Linha 1: Quantidade Total Produzida & Reprovas -->
<div class="bo-kpis-grid-5" style="margin-bottom:16px;">
    <div class="metric-card" style="border-left: 4px solid #82c341;">
        <div class="metric-label" style="display:flex;align-items:center;gap:6px;">
            <span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:#82c341;"></span>
            Quantidade Total TPD
        </div>
        <div class="metric-value"><?= (int) $totalCore['TPD']['real'] ?></div>
        <div class="metric-sub">unidades (≤ 300 kVA) · média <?= htmlspecialchars(fmtDecimal($mediaDiaria['TPD'])) ?> un/dia</div>
    </div>
    <div class="metric-card" style="border-left: 4px solid #4a90e2;">
        <div class="metric-label" style="display:flex;align-items:center;gap:6px;">
            <span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:#4a90e2;"></span>
            Quantidade Total TPS
        </div>
        <div class="metric-value"><?= (int) $totalCore['TPS']['real'] ?></div>
        <div class="metric-sub">unidades (Seco) · média <?= htmlspecialchars(fmtDecimal($mediaDiaria['TPS'])) ?> un/dia</div>
    </div>
    <div class="metric-card" style="border-left: 4px solid #00a86b;">
        <div class="metric-label" style="display:flex;align-items:center;gap:6px;">
            <span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:#00a86b;"></span>
            Quantidade Total TPM
        </div>
        <div class="metric-value"><?= (int) $totalCore['TPM']['real'] ?></div>
        <div class="metric-sub">unidades (> 300 kVA) · média <?= htmlspecialchars(fmtDecimal($mediaDiaria['TPM'])) ?> un/dia</div>
    </div>
    <div class="metric-card" style="border-left: 4px solid #dc2626;">
        <div class="metric-label" style="display:flex;align-items:center;gap:6px;">
            <span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:#dc2626;"></span>
            Reprovas
        </div>
        <div class="metric-value" style="color:#dc2626;"><?= (int) $totalLab ?></div>
        <div class="metric-sub">
            <div style="font-weight:700;color:#dc2626;margin-bottom:2px;">Percentual: <?= htmlspecialchars(fmtDecimal($pctReprovas, 1)) ?>% do PCP</div>
            <div>unidades (almox. 422) · média <?= htmlspecialchars(fmtDecimal($mediaDiariaLab)) ?> un/dia</div>
        </div>
    </div>
    <div class="metric-card" style="border-left: 4px solid var(--color-primary);background:var(--color-surface);">
        <div class="metric-label" style="display:flex;align-items:center;gap:6px;">
            <span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:var(--color-primary);"></span>
            Produção Total
        </div>
        <div class="metric-value" style="color:var(--color-primary);"><?= (int) $totalRealAteHoje ?></div>
        <div class="metric-sub">total no mês · meta <?= (int) $metaTotalForca ?> un</div>
    </div>
</div>

<!-- KPIs - Linha 2: Potências Médias por Linha, Potência Média Geral e Média Executada -->
<div class="bo-kpis-grid-5">
    <div class="metric-card" style="border-left: 4px solid #82c341;">
        <div class="metric-label" style="display:flex;align-items:center;gap:6px;">
            <span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:#82c341;"></span>
            Potência Média TPD
        </div>
        <div class="metric-value"><?= $potenciaMediaLinha['TPD'] !== null ? htmlspecialchars(fmtDecimal($potenciaMediaLinha['TPD'])) . ' kVA' : '—' ?></div>
        <div class="metric-sub">kVA médio no mês</div>
    </div>
    <div class="metric-card" style="border-left: 4px solid #4a90e2;">
        <div class="metric-label" style="display:flex;align-items:center;gap:6px;">
            <span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:#4a90e2;"></span>
            Potência Média TPS
        </div>
        <div class="metric-value"><?= $potenciaMediaLinha['TPS'] !== null ? htmlspecialchars(fmtDecimal($potenciaMediaLinha['TPS'])) . ' kVA' : '—' ?></div>
        <div class="metric-sub">kVA médio no mês</div>
    </div>
    <div class="metric-card" style="border-left: 4px solid #00a86b;">
        <div class="metric-label" style="display:flex;align-items:center;gap:6px;">
            <span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:#00a86b;"></span>
            Potência Média TPM
        </div>
        <div class="metric-value"><?= $potenciaMediaLinha['TPM'] !== null ? htmlspecialchars(fmtDecimal($potenciaMediaLinha['TPM'])) . ' kVA' : '—' ?></div>
        <div class="metric-sub">kVA médio no mês</div>
    </div>
    <div class="metric-card accent">
        <div class="metric-label">Potência Média</div>
        <div class="metric-value"><?= htmlspecialchars(fmtDecimal($potenciaMediaMensal)) ?> kVA</div>
        <div class="metric-sub">kVA médio geral</div>
    </div>
    <div class="metric-card accent">
        <div class="metric-label">Média Executada</div>
        <div class="metric-value"><?= htmlspecialchars(fmtDecimal($mediaExecutadaGeral)) ?></div>
        <div class="metric-sub">un/dia (média geral)</div>
    </div>
</div>

<!-- Gráficos -->
<div class="bo-charts-grid">
    <div class="card bo-chart-card" id="card-producao-quantidade" style="margin-bottom:8px;">
        <div class="card-header" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px;">
            <span class="card-title" style="font-size:var(--font-size-lg);font-weight:700;">Produção - Laboratório</span>
            <button type="button" class="btn btn-secondary btn-sm" onclick="imprimirProducaoQuantidade()" title="Imprimir Gráfico" aria-label="Imprimir Gráfico" style="display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;padding:0;border-radius:var(--radius-md);">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            </button>
        </div>
        <div style="position:relative;height:360px;width:100%;margin-bottom:18px;">
            <canvas id="chart-producao"></canvas>
        </div>

        <!-- Tabela Produção por Linha embutida juntamente abaixo do gráfico -->
        <div style="border-top:1px solid var(--color-border);padding-top:12px;margin-top:8px;">
            <div class="bo-table-wrap">
                <table class="bo-nucleo-table">
                    <thead>
                        <tr>
                            <th>Linha</th>
                            <?php foreach ($diasDoMes as $d): ?>
                                <th><?= (int) date('j', strtotime($d)) ?></th>
                            <?php endforeach; ?>
                            <th>Média</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $qtdDias = $diasComProducaoReal ?: 1; ?>
                        <?php foreach ($NUCLEOS as $c => $label): 
                            $corBadge = $CORES_NUCLEO[$c] ?? '#dc2626';
                        ?>
                        <tr>
                            <td>
                                <span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:<?= $corBadge ?>;margin-right:6px;vertical-align:middle;"></span>
                                <?= htmlspecialchars($label) ?>
                            </td>
                            <?php foreach ($diasDoMes as $d): $v = $porDiaCore[$d][$c]['real'] ?? 0; ?>
                                <td class="<?= $v === 0 ? 'bo-zero' : '' ?>" style="<?= $v > 0 ? 'cursor:pointer;' : '' ?>" <?= $v > 0 ? "onclick=\"abrirModalDetalhesPecas('" . $d . "', '" . $c . "')\" title=\"Clique para ver detalhes das peças\"" : "" ?>><?= $v ?: '—' ?></td>
                            <?php endforeach; ?>
                            <td><?= htmlspecialchars(fmtDecimal($totalCore[$c]['real'] / $qtdDias)) ?></td>
                            <td style="font-weight:600;<?= $totalCore[$c]['real'] > 0 ? 'cursor:pointer;' : '' ?>" <?= $totalCore[$c]['real'] > 0 ? "onclick=\"abrirModalDetalhesPecas('', '" . $c . "')\" title=\"Clique para ver todas as peças desta linha no mês\"" : "" ?>><?= (int) $totalCore[$c]['real'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td style="font-weight:600;">Executado Total</td>
                            <?php foreach ($diasDoMes as $i => $d): $v = $serieExecutadoTotal[$i]; ?>
                                <td class="<?= $v === 0 ? 'bo-zero' : '' ?>" style="font-weight:600;<?= $v > 0 ? 'cursor:pointer;' : '' ?>" <?= $v > 0 ? "onclick=\"abrirModalDetalhesPecas('" . $d . "', 'TOTAL')\" title=\"Clique para ver todas as peças do dia\"" : "" ?>><?= $v ?: '—' ?></td>
                            <?php endforeach; ?>
                            <td style="font-weight:600;"><?= htmlspecialchars(fmtDecimal(array_sum($serieExecutadoTotal) / $qtdDias)) ?></td>
                            <td style="font-weight:600;cursor:pointer;" onclick="abrirModalDetalhesPecas('', 'TOTAL')" title="Clique para ver todas as peças produzidas no mês"><?= array_sum($serieExecutadoTotal) ?></td>
                        </tr>
                        <tr>
                            <td style="font-weight:600;">Meta Diária</td>
                            <?php foreach ($diasDoMes as $d): ?>
                                <td style="font-weight:600;"><?= $metaDiariaTotal > 0 ? (int) round($metaDiariaTotal) : '—' ?></td>
                            <?php endforeach; ?>
                            <td style="font-weight:600;"><?= $metaDiariaTotal > 0 ? (int) round($metaDiariaTotal) : '—' ?></td>
                            <td style="font-weight:700;color:var(--color-accent-text);"><?= $metaTotalForca > 0 ? (int) $metaTotalForca : '—' ?></td>
                        </tr>
                        <tr>
                            <td>Potência Média (kVA)</td>
                            <?php foreach ($diasDoMes as $d):
                                $v = $seriePotencia[array_search($d, $diasDoMes)] ?? null;
                            ?>
                                <td class="<?= $v === null ? 'bo-zero' : '' ?>"><?= $v !== null ? htmlspecialchars(fmtDecimal($v)) : '—' ?></td>
                            <?php endforeach; ?>
                            <td style="font-weight:600;"><?= htmlspecialchars(fmtDecimal($potenciaMediaMensal)) ?></td>
                            <td style="font-weight:600;"><?= htmlspecialchars(fmtDecimal($potenciaMediaMensal)) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="card bo-chart-card">
        <div class="card-header"><span class="card-title">Percentual do Planejado (%)</span></div>
        <div style="position:relative;height:320px;width:100%;">
            <canvas id="chart-percentual"></canvas>
        </div>
    </div>
    <div class="card bo-chart-card">
        <div class="card-header"><span class="card-title">Produção Acumulada</span></div>
        <div style="position:relative;height:320px;width:100%;">
            <canvas id="chart-acumulada"></canvas>
        </div>
    </div>
    <!-- Card Mix Produção com dois gráficos de pizza (Programado x Realizado) -->
    <div class="card bo-chart-card" id="card-mix-producao" style="padding:20px;">
        <div class="card-header" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px;">
            <div style="display:flex;align-items:center;gap:8px;">
                <span class="card-title" style="font-size:var(--font-size-lg);font-weight:700;">Mix Produção (Programado × Realizado)</span>
            </div>
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="display:flex;align-items:center;gap:6px;">
                    <label for="mix-dia-select" style="font-size:var(--font-size-sm);font-weight:700;color:var(--color-text-secondary);">Dia:</label>
                    <select id="mix-dia-select" class="form-control form-control-sm" style="font-weight:700;border:1px solid var(--color-border);border-radius:var(--radius-md);padding:4px 8px;font-size:var(--font-size-sm);" onchange="atualizarMixDia(this.value)">
                        <?php foreach ($diasDoMes as $d):
                            $prodDia = ($porDiaCore[$d]['TPD']['real'] ?? 0) + ($porDiaCore[$d]['TPS']['real'] ?? 0) + ($porDiaCore[$d]['TPM']['real'] ?? 0);
                        ?>
                            <option value="<?= htmlspecialchars($d) ?>" <?= $d === $diaPadraoMix ? 'selected' : '' ?>>
                                <?= date('d/m/Y', strtotime($d)) ?> <?= $prodDia > 0 ? "· {$prodDia} un" : '· 0 un' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" onclick="imprimirMixProducao()" title="Imprimir Mix Produção" aria-label="Imprimir Mix Produção" style="display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;padding:0;border-radius:var(--radius-md);">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                </button>
            </div>
        </div>

        <div id="mix-print-area" style="background:#fff;border:2px solid #4a90e2;border-radius:var(--radius-lg);padding:24px 16px;box-shadow:var(--shadow-sm);">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:center;">
                <!-- Pizza Programado -->
                <div style="display:flex;flex-direction:column;align-items:center;border-right:1px solid var(--color-border);padding-right:12px;">
                    <h3 style="font-size:20px;font-weight:800;color:#1e293b;margin-bottom:12px;">Programado</h3>
                    <div style="position:relative;width:240px;height:240px;">
                        <canvas id="chart-mix-prog"></canvas>
                    </div>
                    <div class="bo-mix-legend-custom" style="display:flex;justify-content:center;gap:14px;margin-top:16px;font-size:13px;font-weight:700;">
                        <span><span style="display:inline-block;width:12px;height:12px;background:#82c341;margin-right:5px;border-radius:2px;"></span>% TPD (<span id="lbl-prog-tpd"><?= $mixPorDia[$diaPadraoMix]['programado']['pctTPD'] ?? 0 ?>%</span>)</span>
                        <span><span style="display:inline-block;width:12px;height:12px;background:#00a86b;margin-right:5px;border-radius:2px;"></span>% TPM (<span id="lbl-prog-tpm"><?= $mixPorDia[$diaPadraoMix]['programado']['pctTPM'] ?? 0 ?>%</span>)</span>
                        <span><span style="display:inline-block;width:12px;height:12px;background:#4a90e2;margin-right:5px;border-radius:2px;"></span>% TPS (<span id="lbl-prog-tps"><?= $mixPorDia[$diaPadraoMix]['programado']['pctTPS'] ?? 0 ?>%</span>)</span>
                    </div>
                </div>

                <!-- Pizza Realizado -->
                <div style="display:flex;flex-direction:column;align-items:center;padding-left:12px;">
                    <h3 style="font-size:20px;font-weight:800;color:#1e293b;margin-bottom:12px;">Realizado</h3>
                    <div style="position:relative;width:240px;height:240px;">
                        <canvas id="chart-mix-real"></canvas>
                    </div>
                    <div class="bo-mix-legend-custom" style="display:flex;justify-content:center;gap:14px;margin-top:16px;font-size:13px;font-weight:700;">
                        <span><span style="display:inline-block;width:12px;height:12px;background:#82c341;margin-right:5px;border-radius:2px;"></span>% TPD (<span id="lbl-real-tpd"><?= $mixPorDia[$diaPadraoMix]['realizado']['pctTPD'] ?? 0 ?>%</span>)</span>
                        <span><span style="display:inline-block;width:12px;height:12px;background:#00a86b;margin-right:5px;border-radius:2px;"></span>% TPM (<span id="lbl-real-tpm"><?= $mixPorDia[$diaPadraoMix]['realizado']['pctTPM'] ?? 0 ?>%</span>)</span>
                        <span><span style="display:inline-block;width:12px;height:12px;background:#4a90e2;margin-right:5px;border-radius:2px;"></span>% TPS (<span id="lbl-real-tps"><?= $mixPorDia[$diaPadraoMix]['realizado']['pctTPS'] ?? 0 ?>%</span>)</span>
                    </div>
                </div>
            </div>

            <!-- Data em destaque embaixo -->
            <div id="mix-data-destaque" style="font-size:36px;font-weight:900;text-align:center;margin-top:24px;color:#000;letter-spacing:1px;font-family:var(--font-sans);">
                <?= date('d/m/Y', strtotime($diaPadraoMix)) ?>
            </div>
        </div>
    </div>
</div>

<!-- Card Produção por Linha (TPD, TPS, TPM) -->
<div class="card" id="card-producao-por-linha" style="margin-bottom:24px;padding:24px;">
    <div class="card-header" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:20px;border-bottom:1px solid var(--color-border);padding-bottom:14px;">
        <div>
            <h2 style="font-size:var(--font-size-xl);font-weight:800;color:var(--color-text-primary);margin:0;">
                PRODUÇÃO POR LINHA - MÉDIA FORÇA / SECO
            </h2>
            <p style="font-size:var(--font-size-xs);color:var(--color-text-muted);margin:2px 0 0 0;">
                Acompanhamento diário com metas, variações e percentuais por linha de fabricação
            </p>
        </div>
        <button type="button" class="btn btn-secondary btn-sm" onclick="imprimirProducaoPorLinha()" title="Imprimir Produção por Linha" aria-label="Imprimir Produção por Linha" style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;padding:0;border-radius:var(--radius-md);">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        </button>
    </div>

    <div id="area-print-producao-linhas" style="display:flex;flex-direction:column;gap:28px;">
        <?php foreach ($dadosProducaoPorLinha as $c => $linha): ?>
            <div class="bloco-linha-prod" style="background:var(--color-surface);border:1px solid var(--color-border);border-radius:var(--radius-lg);padding:16px 20px;box-shadow:var(--shadow-sm);">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:14px;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:<?= $linha['info']['cor'] ?>;"></span>
                        <h3 style="font-size:16px;font-weight:800;color:var(--color-text-primary);margin:0;letter-spacing:.3px;">
                            <?= htmlspecialchars($linha['info']['nome']) ?>
                        </h3>
                    </div>
                    <div style="font-size:12px;font-weight:700;color:var(--color-text-secondary);display:flex;flex-wrap:wrap;gap:16px;">
                        <span>Meta Diária: <strong style="color:var(--color-text-primary);"><?= (int) $linha['info']['meta'] ?> un</strong></span>
                        <span>Média Real: <strong style="color:var(--color-text-primary);"><?= fmtDecimal($linha['mediaExec']) ?> un/dia</strong></span>
                        <span>Total Realizado: <strong style="color:var(--color-text-primary);"><?= number_format($linha['somaExec'], 0, ',', '.') ?> un</strong></span>
                    </div>
                </div>

                <!-- Gráfico de Barras com altura de 190px -->
                <div style="position:relative;height:190px;width:100%;margin-bottom:12px;">
                    <canvas id="chart-linha-<?= strtolower($c) ?>"></canvas>
                </div>

                <!-- Tabela de Dados Idêntica ao PDF -->
                <div class="bo-table-wrap">
                    <table class="bo-nucleo-table" style="font-size:12px;text-align:center;">
                        <thead>
                            <tr>
                                <th style="text-align:left;font-weight:700;background:var(--color-surface-2);white-space:nowrap;padding-left:10px;min-width:140px;">Indicador</th>
                                <?php foreach ($diasDoMes as $d): ?>
                                    <th style="text-align:center;"><?= date('d/m', strtotime($d)) ?></th>
                                <?php endforeach; ?>
                                <th style="text-align:center;background:var(--color-surface-2);font-weight:800;color:var(--color-text-primary);">MÉDIA</th>
                                <th style="text-align:center;background:var(--color-surface-2);font-weight:800;color:var(--color-text-primary);">SOMA</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Linha 1: Executado -->
                            <tr>
                                <td style="text-align:left;font-weight:700;"><?= htmlspecialchars($linha['info']['label_exec']) ?></td>
                                <?php foreach ($linha['execs'] as $v): ?>
                                    <td class="<?= $v === 0 ? 'bo-zero' : '' ?>"><?= $v ?: '—' ?></td>
                                <?php endforeach; ?>
                                <td style="font-weight:700;"><?= fmtDecimal($linha['mediaExec']) ?></td>
                                <td style="font-weight:700;"><?= number_format($linha['somaExec'], 0, ',', '.') ?></td>
                            </tr>
                            <!-- Linha 2: Meta Diária -->
                            <tr>
                                <td style="text-align:left;color:var(--color-text-secondary);">Meta Diária</td>
                                <?php foreach ($diasDoMes as $d): ?>
                                    <td style="color:var(--color-text-secondary);"><?= (int) $linha['info']['meta'] ?></td>
                                <?php endforeach; ?>
                                <td style="color:var(--color-text-secondary);"><?= (int) $linha['info']['meta'] ?></td>
                                <td style="color:var(--color-text-secondary);"><?= number_format($linha['somaMeta'], 0, ',', '.') ?></td>
                            </tr>
                            <!-- Linha 3: Diferença Média Diária -->
                            <tr>
                                <td style="text-align:left;">Diferença Média Diária</td>
                                <?php foreach ($linha['diffs'] as $v):
                                    $corDiff = $v > 0 ? '#16a34a' : ($v < 0 ? '#dc2626' : 'inherit');
                                ?>
                                    <td style="color:<?= $corDiff ?>;font-weight:600;"><?= ($v > 0 ? '+' : '') . fmtDecimal($v) ?></td>
                                <?php endforeach; ?>
                                <td style="font-weight:700;color:<?= $linha['diffMedia'] >= 0 ? '#16a34a' : '#dc2626' ?>;"><?= ($linha['diffMedia'] > 0 ? '+' : '') . fmtDecimal($linha['diffMedia']) ?></td>
                                <td style="font-weight:700;color:<?= $linha['diffTotal'] >= 0 ? '#16a34a' : '#dc2626' ?>;"><?= ($linha['diffTotal'] > 0 ? '+' : '') . number_format($linha['diffTotal'], 0, ',', '.') ?></td>
                            </tr>
                            <!-- Linha 4: % Atingimento da Meta Diária -->
                            <tr>
                                <td style="text-align:left;">% Realizado / Meta Diária</td>
                                <?php foreach ($linha['pcts'] as $v):
                                    $corPct = $v >= 100 ? '#16a34a' : ($v > 0 ? '#ea580c' : 'inherit');
                                ?>
                                    <td style="color:<?= $corPct ?>;font-weight:600;"><?= $v > 0 ? $v . '%' : '—' ?></td>
                                <?php endforeach; ?>
                                <td style="font-weight:700;color:<?= $linha['pctMedia'] >= 100 ? '#16a34a' : '#ea580c' ?>;"><?= $linha['pctMedia'] ?>%</td>
                                <td style="font-weight:700;color:<?= $linha['pctTotal'] >= 100 ? '#16a34a' : '#ea580c' ?>;"><?= $linha['pctTotal'] ?>%</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Modal Métricas e Calendário de Produção -->
<div id="modal-metricas" class="modal-overlay" style="display:none;" onclick="if(event.target === this) fecharModalMetricas()">
    <div class="modal" style="max-width:860px;width:95%;">
        <div class="modal-header" style="background:var(--color-surface);border-bottom:1px solid var(--color-border);padding:16px 24px;">
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:36px;height:36px;border-radius:var(--radius-md);background:var(--color-accent-light);display:flex;align-items:center;justify-content:center;color:var(--color-accent-text);">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20v-6M6 20V10M18 20V4"/></svg>
                </div>
                <div>
                    <h3 class="modal-title" style="margin:0;font-size:var(--font-size-lg);font-weight:700;">Métricas & Calendário de Produção</h3>
                    <p style="margin:0;font-size:var(--font-size-xs);color:var(--color-text-muted);">Mês de Referência: <strong><?= $mesTxt ?> (Média Força / Seco)</strong></p>
                </div>
            </div>
            <button type="button" class="modal-close" onclick="fecharModalMetricas()" aria-label="Fechar">&times;</button>
        </div>

        <form id="form-modal-metricas" onsubmit="salvarMetricasModal(event)">
            <div class="modal-body" style="padding:20px 24px;display:grid;grid-template-columns:1fr 1.35fr;gap:24px;">
                <!-- Coluna 1: Metas Diárias das Linhas -->
                <div style="display:flex;flex-direction:column;gap:16px;">
                    <div>
                        <h4 style="margin:0 0 4px 0;font-size:var(--font-size-sm);font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--color-text-secondary);">
                            🎯 Metas Diárias das Linhas
                        </h4>
                        <p style="margin:0;font-size:var(--font-size-xs);color:var(--color-text-muted);">
                            Informe a quantidade diária planejada para cada linha:
                        </p>
                    </div>

                    <div style="display:flex;flex-direction:column;gap:12px;">
                        <div style="background:var(--color-surface-2);border:1px solid var(--color-border);border-radius:var(--radius-md);padding:10px 14px;">
                            <label for="modal-meta-tpd" style="display:flex;justify-content:space-between;align-items:center;font-size:var(--font-size-xs);font-weight:700;color:#16a34a;margin-bottom:4px;">
                                <span>META DIÁRIA TPD (≤ 300 kVA)</span>
                                <span style="font-size:10px;color:var(--color-text-muted);">un/dia</span>
                            </label>
                            <input type="number" step="any" id="modal-meta-tpd" name="meta_dia_tpd" value="<?= (float) $metaDiariaTpd ?>" min="0" required class="form-control" style="font-weight:700;font-size:var(--font-size-md);" oninput="recalcularMetasModal()">
                        </div>

                        <div style="background:var(--color-surface-2);border:1px solid var(--color-border);border-radius:var(--radius-md);padding:10px 14px;">
                            <label for="modal-meta-tps" style="display:flex;justify-content:space-between;align-items:center;font-size:var(--font-size-xs);font-weight:700;color:#2563eb;margin-bottom:4px;">
                                <span>META DIÁRIA TPS (SECO)</span>
                                <span style="font-size:10px;color:var(--color-text-muted);">un/dia</span>
                            </label>
                            <input type="number" step="any" id="modal-meta-tps" name="meta_dia_tps" value="<?= (float) $metaDiariaTps ?>" min="0" required class="form-control" style="font-weight:700;font-size:var(--font-size-md);" oninput="recalcularMetasModal()">
                        </div>

                        <div style="background:var(--color-surface-2);border:1px solid var(--color-border);border-radius:var(--radius-md);padding:10px 14px;">
                            <label for="modal-meta-tpm" style="display:flex;justify-content:space-between;align-items:center;font-size:var(--font-size-xs);font-weight:700;color:#059669;margin-bottom:4px;">
                                <span>META DIÁRIA TPM (> 300 kVA)</span>
                                <span style="font-size:10px;color:var(--color-text-muted);">un/dia</span>
                            </label>
                            <input type="number" step="any" id="modal-meta-tpm" name="meta_dia_tpm" value="<?= (float) $metaDiariaTpm ?>" min="0" required class="form-control" style="font-weight:700;font-size:var(--font-size-md);" oninput="recalcularMetasModal()">
                        </div>
                    </div>

                    <!-- Card Resumo do Cálculo -->
                    <div style="margin-top:auto;background:var(--color-accent-light);border:1px solid var(--color-accent);border-radius:var(--radius-lg);padding:14px;">
                        <div style="display:flex;justify-content:space-between;margin-bottom:6px;font-size:var(--font-size-xs);color:var(--color-accent-text);">
                            <span>Dias de Produção Ativos:</span>
                            <strong id="modal-resumo-dias-uteis"><?= (int) $diasUteis ?> dias</strong>
                        </div>
                        <div style="border-top:1px dashed var(--color-accent);padding-top:8px;display:flex;flex-direction:column;gap:4px;">
                            <div style="display:flex;justify-content:space-between;align-items:baseline;">
                                <span style="font-size:var(--font-size-xs);font-weight:700;color:var(--color-accent-text);text-transform:uppercase;">Meta Prevista TPD:</span>
                                <span id="modal-resumo-tpd" style="font-size:var(--font-size-md);font-weight:800;color:var(--color-accent-text);"><?= htmlspecialchars(fmtDecimal($metaTpd)) ?> un</span>
                            </div>
                            <div style="display:flex;justify-content:space-between;align-items:baseline;">
                                <span style="font-size:var(--font-size-xs);font-weight:700;color:var(--color-accent-text);text-transform:uppercase;">Meta Prevista TPS:</span>
                                <span id="modal-resumo-tps" style="font-size:var(--font-size-md);font-weight:800;color:var(--color-accent-text);"><?= htmlspecialchars(fmtDecimal($metaTps)) ?> un</span>
                            </div>
                            <div style="display:flex;justify-content:space-between;align-items:baseline;">
                                <span style="font-size:var(--font-size-xs);font-weight:700;color:var(--color-accent-text);text-transform:uppercase;">Meta Prevista TPM:</span>
                                <span id="modal-resumo-tpm" style="font-size:var(--font-size-md);font-weight:800;color:var(--color-accent-text);"><?= htmlspecialchars(fmtDecimal($metaTpm)) ?> un</span>
                            </div>
                            <div style="border-top:1px solid var(--color-accent);margin-top:4px;padding-top:4px;display:flex;justify-content:space-between;align-items:baseline;">
                                <span style="font-size:var(--font-size-xs);font-weight:800;color:var(--color-accent-text);text-transform:uppercase;">Meta Total:</span>
                                <span id="modal-resumo-total" style="font-size:var(--font-size-lg);font-weight:900;color:var(--color-accent-text);"><?= htmlspecialchars(fmtDecimal($metaTotalForca)) ?> un</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Coluna 2: Calendário de Dias de Produção & Feriados -->
                <div style="display:flex;flex-direction:column;gap:12px;border-left:1px solid var(--color-border);padding-left:24px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                        <div>
                            <h4 style="margin:0;font-size:var(--font-size-sm);font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--color-text-secondary);">
                                📅 Calendário de Produção
                            </h4>
                            <p style="margin:2px 0 0 0;font-size:var(--font-size-xs);color:var(--color-text-muted);">
                                Clique nos dias para ativar ou desativar a produção:
                            </p>
                        </div>
                        <div style="display:flex;gap:4px;">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="modalMarcarSegSex()" style="font-size:11px;padding:4px 8px;">Seg-Sex</button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="modalMarcarTodos()" style="font-size:11px;padding:4px 8px;">Todos</button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="modalLimparTodos()" style="font-size:11px;padding:4px 8px;">Limpar</button>
                        </div>
                    </div>

                    <!-- Grade do Calendário -->
                    <div style="background:var(--color-surface);border:1px solid var(--color-border);border-radius:var(--radius-md);padding:10px;">
                        <div style="display:grid;grid-template-columns:repeat(7, 1fr);gap:4px;text-align:center;font-size:11px;font-weight:700;color:var(--color-text-muted);margin-bottom:8px;">
                            <div>DOM</div><div>SEG</div><div>TER</div><div>QUA</div><div>QUI</div><div>SEX</div><div>SÁB</div>
                        </div>
                        <div id="modal-grade-calendario" style="display:grid;grid-template-columns:repeat(7, 1fr);gap:4px;"></div>
                    </div>

                    <div style="display:flex;align-items:center;gap:14px;font-size:11px;color:var(--color-text-muted);">
                        <div style="display:flex;align-items:center;gap:4px;">
                            <span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:var(--color-accent);border:1px solid var(--color-accent);"></span>
                            <span>Dia de Produção</span>
                        </div>
                        <div style="display:flex;align-items:center;gap:4px;">
                            <span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:var(--color-surface-2);border:1px solid var(--color-border);"></span>
                            <span>Feriado / Folga</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer" style="background:var(--color-surface);padding:14px 24px;border-top:1px solid var(--color-border);display:flex;justify-content:flex-end;gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="fecharModalMetricas()">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="btn-salvar-metricas-modal" style="font-weight:700;display:inline-flex;align-items:center;gap:6px;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    Salvar Métricas & Calendário
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels"></script>
<script>
    window.BOLETIM_API = <?= json_encode($base . '/api/boletim-acao.php') ?>;
    window.BOLETIM_AREA = 'forca';
    window.BOLETIM_CHART_DATA = {
        dias: <?= json_encode(array_map(fn($d) => (int) date('j', strtotime($d)), $diasDoMes)) ?>,
        diasFormatados: <?= json_encode(array_map(fn($d) => date('d/m', strtotime($d)), $diasDoMes)) ?>,
        producao: <?= json_encode($serieProducao) ?>,
        executadoTotal: <?= json_encode($serieExecutadoTotal) ?>,
        metaTotal: <?= json_encode($serieMetaTotal) ?>,
        percentual: <?= json_encode($seriePercentual) ?>,
        acumuladoReal: <?= json_encode($acumuladoReal) ?>,
        acumuladoMeta: <?= json_encode($acumuladoMeta) ?>,
        mixPorDia: <?= json_encode($mixPorDia) ?>,
        diaPadraoMix: <?= json_encode($diaPadraoMix) ?>,
        dadosLinhas: <?= json_encode($dadosProducaoPorLinha) ?>,
        mesTxt: <?= json_encode($mesTxt) ?>,
    };
    window.CALENDARIO_DIAS = <?= json_encode($todosDiasCalendario) ?>;
    window.PRIMEIRO_DIA_SEMANA = <?= $primeiroDiaSemanaMes ?>;
    window.MES_REFERENCIA = <?= json_encode($mes) ?>;

    // ─── Auto-Refresh 30 Segundos (Padrão Retrabalho) ───────────────────────────
    let autoRefreshSegundos = 30;
    let autoRefreshPausado = false;

    const intervalAutoRefresh = setInterval(() => {
        const modalAberto = document.querySelector('.date-filter-overlay.open, .bo-modal-overlay.open, .bo-modal-backdrop.open');
        const inputFocado = document.activeElement && ['INPUT', 'SELECT', 'TEXTAREA'].includes(document.activeElement.tagName);

        if (!autoRefreshPausado && !modalAberto && !inputFocado) {
            autoRefreshSegundos--;
            const lbl = document.getElementById('labelTimerRefresh');
            if (lbl) {
                lbl.textContent = autoRefreshSegundos + 's';
            }

            if (autoRefreshSegundos <= 0) {
                window.location.reload();
            }
        }
    }, 1000);

    function alternarAutoRefresh() {
        autoRefreshPausado = !autoRefreshPausado;
        const lbl = document.getElementById('labelTimerRefresh');
        const chip = document.getElementById('chipAutoRefresh');
        const dot = chip?.querySelector('.pulse-dot');

        if (autoRefreshPausado) {
            if (lbl) {
                lbl.textContent = 'Pausado';
                lbl.style.color = '#64748b';
            }
            if (dot) dot.style.background = '#94a3b8';
        } else {
            autoRefreshSegundos = 30;
            if (lbl) {
                lbl.textContent = '30s';
                lbl.style.color = '';
            }
            if (dot) dot.style.background = '#22c55e';
        }
    }
</script>
<?php 
require_once __DIR__ . '/../../includes/modal-filtro-data.php';
require_once __DIR__ . '/../../includes/modal-detalhes-pecas.php';
?>
<?php $forcaJsVer = @filemtime(__DIR__ . '/../../assets/js/forca-seco.js') ?: (defined('APP_VERSION') ? APP_VERSION : '1'); ?>
<script src="<?= htmlspecialchars($base) ?>/assets/js/forca-seco.js?v=<?= htmlspecialchars((string) $forcaJsVer) ?>"></script>

<?php layoutFooter(); ?>
