<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();
if (!hasAcesso('tab:pcp') && !hasAcesso('pcp.pri') && !hasAcesso('ret.pri') && !hasAcesso('tab:retrabalho') && !hasAcesso('admin')) {
    requireAcessoModulo('pcp');
}

$canEdit = podeEditar('pcp.pri') || podeEditar('ret.pri') || isAdmin();

$pdo  = getDB();
$base = defined('APP_URL') ? APP_URL : '';

// ─── Busca de registros de retrabalho ativos (excluindo S/P, Pintura e peças aguardando retorno) ─
$registrosRaw = $pdo->query("
    SELECT r.*,
           pr.id AS projeto_id, pr.codigo AS projeto_codigo, pr.descricao AS projeto_descricao,
           pr.prioridade AS projeto_prioridade, pr.sequencia AS projeto_sequencia,
           ped.id AS pedido_id, ped.numero AS pedido_numero,
           ped.prioridade AS pedido_prioridade, ped.sequencia AS pedido_sequencia,
           rep.codigo AS reprova_codigo, rep.descricao AS reprova_descricao, rep.familia AS reprova_familia
    FROM retrabalhos r
    JOIN projetos pr       ON pr.id  = r.id_projeto AND pr.deleted_at IS NULL
    JOIN pedidos ped       ON ped.id = pr.id_pedido AND ped.deleted_at IS NULL
    LEFT JOIN reprovas rep ON rep.id = r.id_reprova
    WHERE r.deleted_at IS NULL
      AND r.status IN ('agu_abertura', 'agu_causa_raiz')
      AND ped.numero NOT IN ('S/P', 'SP', 'SEM PEDIDO', 's/p', 'sp', 'Sem Pedido')
      AND (rep.familia IS NULL OR rep.familia NOT IN ('PINTURA', 'SERIGRAFIA', 'CAMADA'))
      AND (r.setores_destino NOT LIKE '%pintura%' OR r.setores_destino IS NULL)
      AND NOT EXISTS (
          SELECT 1 FROM producao_etapas pe
          WHERE pe.ns_transformador = r.ns_transformador AND pe.id_projeto = r.id_projeto
            AND pe.estacao IN ('LAB', 'IQF') AND pe.status = 'aguardando_retorno' AND pe.deleted_at IS NULL
      )
    ORDER BY ped.id DESC, pr.id DESC, r.id DESC
")->fetchAll();

$qtdPorNs = $pdo->query("
    SELECT ns_transformador, COUNT(DISTINCT COALESCE(id_lote, id)) AS qtd
    FROM retrabalhos
    WHERE deleted_at IS NULL
    GROUP BY ns_transformador
")->fetchAll(PDO::FETCH_KEY_PAIR);

$hoje = new DateTime('today');
$prioridadesDef = pedidoPrioridades();

// ─── Construção da Árvore Hierárquica (Pedido ➔ Projeto ➔ NS) ─────────────────
$pedidosTree = [];
$nsFlattened = [];

foreach ($registrosRaw as $r) {
    $pedId = (int) $r['pedido_id'];
    $pedNum = (string) $r['pedido_numero'];
    $pedPrio = (string) ($r['pedido_prioridade'] ?: 'neutro');
    $pedSeq = (int) ($r['pedido_sequencia'] ?? 0);

    $projId = (int) $r['projeto_id'];
    $projCod = (string) $r['projeto_codigo'];
    $projDesc = (string) ($r['projeto_descricao'] ?? '');
    $projPrio = (string) ($r['projeto_prioridade'] ?: 'neutro');
    $projSeq = (int) ($r['projeto_sequencia'] ?? 0);
    [$potencia, $classe] = parsePotenciaClasse($projDesc);

    $ns = (string) ($r['ns_transformador'] ?? '');
    if ($ns === '') $ns = 's_ns_' . $r['id'];
    $nsPrio = (string) ($r['prioridade'] ?: 'neutro');
    $nsSeq = (int) ($r['sequencia'] ?? 0);

    // 1. Inicializa Pedido
    if (!isset($pedidosTree[$pedId])) {
        $pedidosTree[$pedId] = [
            'id' => $pedId,
            'numero' => $pedNum,
            'prioridade' => $pedPrio,
            'sequencia' => $pedSeq,
            'projetos' => [],
            'tot_trafos' => 0,
            'min_data' => PHP_INT_MAX,
        ];
    }

    // 2. Inicializa Projeto dentro do Pedido
    if (!isset($pedidosTree[$pedId]['projetos'][$projId])) {
        $pedidosTree[$pedId]['projetos'][$projId] = [
            'id' => $projId,
            'codigo' => $projCod,
            'descricao' => $projDesc,
            'potencia' => $potencia,
            'classe' => $classe,
            'prioridade' => $projPrio,
            'sequencia' => $projSeq,
            'pedido_numero' => $pedNum,
            'ns_list' => [],
            'tot_trafos' => 0,
            'min_data' => PHP_INT_MAX,
        ];
    }

    // 3. Inicializa NS dentro do Projeto
    if (!isset($pedidosTree[$pedId]['projetos'][$projId]['ns_list'][$ns])) {
        $pedidosTree[$pedId]['projetos'][$projId]['ns_list'][$ns] = [
            'ns_transformador' => $r['ns_transformador'],
            'prioridade' => $nsPrio,
            'sequencia' => $nsSeq,
            'pedido_id' => $pedId,
            'pedido_numero' => $pedNum,
            'projeto_id' => $projId,
            'projeto_codigo' => $projCod,
            'projeto_descricao' => $projDesc,
            'potencia' => $potencia,
            'classe' => $classe,
            'reincidencias' => (int) ($qtdPorNs[$ns] ?? 1),
            'dias' => 0,
            'min_data' => null,
            'reprovas' => [],
        ];
        $pedidosTree[$pedId]['projetos'][$projId]['tot_trafos']++;
        $pedidosTree[$pedId]['tot_trafos']++;
    }

    // Cálculo de dias e data do NS
    $baseData = $r['data_inicio'] ?? $r['data_reprova'] ?? $r['created_at'];
    $diasCalc = 0;
    if ($baseData) {
        $ini = new DateTime(substr((string) $baseData, 0, 10));
        $diasCalc = diasUteisEntre($ini, $hoje);
        $ts = strtotime((string) $baseData);
        $curMin = $pedidosTree[$pedId]['projetos'][$projId]['ns_list'][$ns]['min_data'];
        if ($curMin === null || $ts < $curMin) {
            $pedidosTree[$pedId]['projetos'][$projId]['ns_list'][$ns]['min_data'] = $ts;
        }
        if ($ts < $pedidosTree[$pedId]['projetos'][$projId]['min_data']) {
            $pedidosTree[$pedId]['projetos'][$projId]['min_data'] = $ts;
        }
        if ($ts < $pedidosTree[$pedId]['min_data']) {
            $pedidosTree[$pedId]['min_data'] = $ts;
        }
    }

    $refNs = &$pedidosTree[$pedId]['projetos'][$projId]['ns_list'][$ns];
    if ($diasCalc > $refNs['dias']) {
        $refNs['dias'] = $diasCalc;
    }

    if (!empty($r['reprova_codigo'])) {
        $refNs['reprovas'][] = [
            'codigo' => $r['reprova_codigo'],
            'descricao' => $r['reprova_descricao'] ?? '',
            'familia' => $r['reprova_familia'] ?? '',
            'data' => $r['data_reprova'],
        ];
    }
    unset($refNs);
}

// ─── Resolução de Prioridades Efetivas e Lista Direta ──────────────────────────
$rankPrio = ['emergente' => 4, 'urgente' => 3, 'importante' => 2, 'neutro' => 1];
$totKPI = ['emergente' => 0, 'urgente' => 0, 'importante' => 0, 'neutro' => 0, 'trafos' => 0, 'pedidos' => count($pedidosTree)];

foreach ($pedidosTree as &$ped) {
    $pedPrio = $ped['prioridade'];

    foreach ($ped['projetos'] as &$proj) {
        $projPrioEfetiva = ($proj['prioridade'] !== 'neutro') ? $proj['prioridade'] : $pedPrio;
        $proj['prioridade_efetiva'] = $projPrioEfetiva;
        $proj['herdada'] = ($proj['prioridade'] === 'neutro' && $pedPrio !== 'neutro');

        foreach ($proj['ns_list'] as &$nsItem) {
            $nsPrioEfetiva = ($nsItem['prioridade'] !== 'neutro') ? $nsItem['prioridade'] : $projPrioEfetiva;
            $nsItem['prioridade_efetiva'] = $nsPrioEfetiva;
            $nsItem['herdada'] = ($nsItem['prioridade'] === 'neutro' && $projPrioEfetiva !== 'neutro');
            $nsItem['herdada_de'] = ($nsItem['prioridade'] === 'neutro') ? (($proj['prioridade'] !== 'neutro') ? 'projeto' : (($pedPrio !== 'neutro') ? 'pedido' : '')) : '';

            $totKPI['trafos']++;
            $totKPI[$nsPrioEfetiva] = ($totKPI[$nsPrioEfetiva] ?? 0) + 1;

            $nsFlattened[] = $nsItem;
        }
        unset($nsItem);
    }
    unset($proj);
}
unset($ped);

// Ordenação dos Pedidos por criticidade (Emergente > Urgente > Importante > Neutro, depois FIFO)
uasort($pedidosTree, function ($a, $b) use ($rankPrio) {
    $rA = $rankPrio[$a['prioridade']] ?? 1;
    $rB = $rankPrio[$b['prioridade']] ?? 1;
    if ($rA !== $rB) return $rB <=> $rA;
    if ($a['sequencia'] != $b['sequencia']) return $a['sequencia'] <=> $b['sequencia'];
    return $a['min_data'] <=> $b['min_data'];
});

// Ordenação da Lista Direta por prioridade efetiva do NS, depois dias parado DESC
usort($nsFlattened, function ($a, $b) use ($rankPrio) {
    $rA = $rankPrio[$a['prioridade_efetiva']] ?? 1;
    $rB = $rankPrio[$b['prioridade_efetiva']] ?? 1;
    if ($rA !== $rB) return $rB <=> $rA;
    if ($a['sequencia'] != $b['sequencia']) return $a['sequencia'] <=> $b['sequencia'];
    return $b['dias'] <=> $a['dias'];
});

$pageTitle = 'Prioridades';
require_once __DIR__ . '/../../includes/layout.php';
layoutHeader($pageTitle);
?>

<style>
    /* ─── Padrão Visual SGT ─────────────────────────────────────────────────── */
    .prio-page-container {
        display: flex;
        flex-direction: column;
        height: calc(100vh - var(--header-height) - 48px);
        height: calc(100dvh - var(--header-height) - 48px);
        min-height: 0;
        overflow: hidden;
    }

    .rt-card { background:var(--color-surface,#fff); border:1px solid var(--color-border,#e5e7eb); border-radius:var(--radius-lg,10px); }
    .rt-code { font-family:'JetBrains Mono',monospace; font-weight:600; color:var(--color-text-primary,#111827); }
    
    /* ─── Menu Superior Fixo (Sticky Header & KPIs) ─────────────────────────── */
    .prio-sticky-top {
        flex-shrink: 0;
        background: var(--color-bg, #f4f5f7);
        padding-bottom: 8px;
    }

    /* ─── KPIs Cards ────────────────────────────────────────────────────────── */
    .prio-kpi-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 12px;
        margin-bottom: 14px;
    }
    .prio-kpi-card {
        background: #fff;
        border: 1px solid var(--color-border,#e5e7eb);
        border-radius: var(--radius-lg,10px);
        padding: 10px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        box-shadow: 0 1px 2px rgba(0,0,0,0.03);
    }
    .prio-kpi-card .kpi-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: var(--color-text-muted,#6b7280); }
    .prio-kpi-card .kpi-val { font-size: 22px; font-weight: 800; line-height: 1.1; margin-top: 2px; }
    .prio-kpi-card.kpi-emergente { border-left: 4px solid #ef4444; }
    .prio-kpi-card.kpi-emergente .kpi-val { color: #dc2626; }
    .prio-kpi-card.kpi-urgente { border-left: 4px solid #f97316; }
    .prio-kpi-card.kpi-urgente .kpi-val { color: #ea580c; }
    .prio-kpi-card.kpi-importante { border-left: 4px solid #eab308; }
    .prio-kpi-card.kpi-importante .kpi-val { color: #ca8a04; }
    .prio-kpi-card.kpi-neutro { border-left: 4px solid #94a3b8; }
    .prio-kpi-card.kpi-neutro .kpi-val { color: #475569; }

    /* ─── Toolbar e Filtros ─────────────────────────────────────────────────── */
    .rt-filtros {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: center;
        justify-content: space-between;
        margin: 0 0 10px 0;
    }
    .rt-filtros-left, .rt-filtros-right { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
    .rt-filtros select, .rt-filtros input[type=search] {
        padding: 7px 12px;
        border: 1px solid var(--color-border,#d1d5db);
        border-radius: 8px;
        font-size: 13px;
        background: #fff;
        color: var(--color-text-primary,#111827);
    }
    .rt-filtros select:focus, .rt-filtros input[type=search]:focus {
        outline: none;
        border-color: #E89B1C;
        box-shadow: 0 0 0 3px rgba(232,155,28,0.15);
    }

    /* Switch de Visualização */
    .prio-view-switch {
        display: inline-flex;
        background: #f1f5f9;
        border: 1px solid var(--color-border,#e5e7eb);
        border-radius: 9999px;
        padding: 3px;
    }
    .prio-switch-btn {
        border: none;
        background: transparent;
        padding: 5px 12px;
        border-radius: 9999px;
        font-size: 12px;
        font-weight: 600;
        color: var(--color-text-secondary,#5a6480);
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.15s ease;
    }
    .prio-switch-btn.is-active {
        background: #E89B1C;
        color: #0e2c1d;
        font-weight: 700;
    }

    .btn-expand-all {
        display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px;
        border: 1.5px solid #d1d5db; border-radius: 8px; background: #ffffff;
        color: var(--color-text-primary,#111827); font-size: 12px; font-weight: 600; cursor: pointer;
        user-select: none; transition: all 0.15s ease;
    }
    .btn-expand-all:hover { background: #f9fafb; border-color: #94a3b8; }
    .btn-expand-all.is-active { background: #fff7ed; border-color: #E89B1C; color: #9a3412; }

    /* ─── Scroll Container do Relatório de Peças ────────────────────────────── */
    .prio-report-wrapper {
        flex: 1;
        min-height: 0;
        display: flex;
        flex-direction: column;
    }
    .prio-report-wrapper .rt-card {
        flex: 1;
        min-height: 0;
        display: flex;
        flex-direction: column;
    }
    .prio-table-scroll-area {
        flex: 1;
        min-height: 0;
        overflow-y: auto;
        overflow-x: auto;
    }

    /* ─── Tabela Principal ──────────────────────────────────────────────────── */
    .rt-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .rt-table thead th {
        position: sticky;
        top: 0;
        z-index: 10;
        background: #f8fafc;
        text-align: left;
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: .6px;
        color: var(--color-text-muted,#6b7280);
        padding: 9px 12px;
        border-bottom: 1px solid var(--color-border,#e5e7eb);
        white-space: nowrap;
        box-shadow: 0 1px 2px rgba(0,0,0,0.04);
    }
    .rt-table td {
        padding: 9px 12px;
        border-bottom: 1px solid var(--color-border,#f1f5f9);
        vertical-align: middle;
    }
    .rt-table tr:hover td { background: var(--color-surface-2,#f9fafb); }

    /* Nível 1: Pedido */
    .row-pedido td { background: #fff; border-bottom: 1px solid #eef2f6; }
    .row-pedido:hover td { background: #f8fafc; }
    .pedido-title { font-family:'JetBrains Mono',monospace; font-weight:700; font-size:13.5px; color:#0f172a; }
    .meta-tag {
        display: inline-block;
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        padding: 2px 7px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
        color: #475569;
    }

    /* Nível 2: Projeto */
    .row-projeto td { background: #fafcff; }
    .row-projeto:hover td { background: #f1f6fd; }
    .tree-indent-proj { display: flex; align-items: center; padding-left: 26px; position: relative; }
    .tree-indent-proj::before {
        content: '';
        position: absolute;
        left: 10px;
        top: 50%;
        width: 12px;
        height: 1px;
        background: #cbd5e1;
    }

    /* Nível 3: NS (Transformador) */
    .row-ns td { background: #ffffff; }
    .tree-indent-ns { display: flex; align-items: center; padding-left: 54px; position: relative; }
    .tree-indent-ns::before {
        content: '';
        position: absolute;
        left: 38px;
        top: 50%;
        width: 12px;
        height: 1px;
        background: #e2e8f0;
    }

    .tree-toggle-btn {
        width: 22px;
        height: 22px;
        border: 1px solid #d1d5db;
        background: #ffffff;
        color: #374151;
        border-radius: 4px;
        font-weight: 700;
        font-size: 13px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0;
        line-height: 1;
        margin-right: 8px;
        transition: all 0.15s ease;
        flex-shrink: 0;
    }
    .tree-toggle-btn:hover { background: #f3f4f6; color: #111827; border-color: #9ca3af; }
    .tree-toggle-btn svg { transition: transform 0.15s ease; }
    .tree-toggle-btn[aria-expanded="true"] svg { transform: rotate(90deg); }
    .tree-toggle-btn[aria-expanded="true"] { background: #fff7ed; border-color: #ea580c; color: #9a3412; }

    /* ─── Badges de Prioridade Interativos ──────────────────────────────────── */
    .prio-badge-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px;
        border-radius: 9999px;
        font-size: 11.5px;
        font-weight: 600;
        cursor: pointer;
        border: 1px solid transparent;
        transition: all 0.15s ease;
        user-select: none;
        white-space: nowrap;
    }
    .prio-badge-btn:hover { filter: brightness(0.95); }
    .prio-badge-btn .badge-dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; }
    .prio-badge-btn .badge-arrow { font-size: 8px; opacity: 0.6; margin-left: 2px; }

    .prio-style-emergente { background: #fee2e2; color: #dc2626; border-color: #fca5a5; }
    .prio-style-emergente .badge-dot { background: #dc2626; }
    .prio-style-urgente { background: #ffedd5; color: #ea580c; border-color: #fdba74; }
    .prio-style-urgente .badge-dot { background: #ea580c; }
    .prio-style-importante { background: #fef9c3; color: #a16207; border-color: #fde047; }
    .prio-style-importante .badge-dot { background: #ca8a04; }
    .prio-style-neutro { background: #f1f5f9; color: #64748b; border-color: #cbd5e1; }
    .prio-style-neutro .badge-dot { background: #94a3b8; }

    .prio-badge-btn.is-inherited {
        font-style: italic;
        opacity: 0.9;
        background: transparent;
        border: 1px dashed currentColor;
    }

    /* ─── Menu Popover Flutuante ────────────────────────────────────────────── */
    .prio-popover-menu {
        position: absolute;
        background: #ffffff;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        box-shadow: 0 10px 25px -5px rgba(0,0,0,0.12), 0 8px 10px -6px rgba(0,0,0,0.08);
        padding: 5px;
        z-index: 9999;
        min-width: 170px;
        display: none;
    }
    .prio-popover-menu.is-open { display: block; }
    .prio-popover-title {
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--color-text-muted,#6b7280);
        padding: 4px 8px 6px;
        border-bottom: 1px solid #f1f5f9;
        margin-bottom: 4px;
    }
    .prio-popover-item {
        display: flex;
        align-items: center;
        gap: 8px;
        width: 100%;
        padding: 6px 8px;
        border: none;
        background: transparent;
        border-radius: 6px;
        font-size: 12.5px;
        font-weight: 600;
        color: var(--color-text-primary,#111827);
        cursor: pointer;
        text-align: left;
        transition: background 0.1s ease;
    }
    .prio-popover-item:hover { background: #f8fafc; }
    .prio-popover-item.is-selected { background: #f1f5f9; font-weight: 700; }
    .prio-popover-item .item-dot { width: 8px; height: 8px; border-radius: 50%; }

    /* ─── Reprovas e Dias ───────────────────────────────────────────────────── */
    .reprova-item-line {
        display: flex;
        align-items: baseline;
        gap: 6px;
        margin-bottom: 3px;
    }
    .reprova-item-line:last-child { margin-bottom: 0; }
    .reprova-code-badge {
        font-family: 'JetBrains Mono', monospace;
        font-weight: 700;
        font-size: 11px;
        padding: 1px 5px;
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        border-radius: 4px;
        color: #1e293b;
        white-space: nowrap;
    }
    .reprova-desc-text {
        font-size: 12px;
        color: var(--color-text-secondary,#5a6480);
        line-height: 1.3;
    }

    .badge-dias-pill {
        display: inline-block;
        padding: 2px 7px;
        border-radius: 4px;
        font-weight: 600;
        font-size: 12px;
    }
    .badge-dias-pill.dias-ok { background: #f0fdf4; color: #166534; }
    .badge-dias-pill.dias-risco { background: #fffbeb; color: #b45309; }
    .badge-dias-pill.dias-atraso { background: #fef2f2; color: #b91c1c; font-weight: 700; }

    .badge-reinc-pill {
        background: #fef2f2;
        color: #dc2626;
        padding: 1px 6px;
        border-radius: 4px;
        font-weight: 700;
        font-size: 11px;
    }
</style>

<div class="prio-page-container">

    <!-- Topo Fixo (Título, KPIs e Barra de Filtros) -->
    <div class="prio-sticky-top">
        <div style="margin-bottom:12px;">
            <h1 style="font-size:var(--font-size-xl,20px);font-weight:700;color:var(--color-text-primary,#111827);margin:0;">
                Prioridades
            </h1>
            <p class="text-secondary" style="font-size:13px;color:var(--color-text-secondary,#6b7280);margin-top:2px;">
                Defina a prioridade por Pedido, Projeto ou N° de Série. A regra mais específica sempre vence.
            </p>
        </div>

        <!-- KPIs de Fila de Prioridades -->
        <div class="prio-kpi-grid">
            <div class="prio-kpi-card kpi-emergente">
                <div>
                    <div class="kpi-label">Emergentes</div>
                    <div class="kpi-val" id="kpi-emergente"><?= $totKPI['emergente'] ?></div>
                </div>
                <span style="font-size:11px;color:var(--color-text-muted,#6b7280);font-weight:600;">transformadores</span>
            </div>
            <div class="prio-kpi-card kpi-urgente">
                <div>
                    <div class="kpi-label">Urgentes</div>
                    <div class="kpi-val" id="kpi-urgente"><?= $totKPI['urgente'] ?></div>
                </div>
                <span style="font-size:11px;color:var(--color-text-muted,#6b7280);font-weight:600;">transformadores</span>
            </div>
            <div class="prio-kpi-card kpi-importante">
                <div>
                    <div class="kpi-label">Importantes</div>
                    <div class="kpi-val" id="kpi-importante"><?= $totKPI['importante'] ?></div>
                </div>
                <span style="font-size:11px;color:var(--color-text-muted,#6b7280);font-weight:600;">transformadores</span>
            </div>
            <div class="prio-kpi-card kpi-neutro">
                <div>
                    <div class="kpi-label">Fila Neutra (FIFO)</div>
                    <div class="kpi-val" id="kpi-neutro"><?= $totKPI['neutro'] ?></div>
                </div>
                <span style="font-size:11px;color:var(--color-text-muted,#6b7280);font-weight:600;">transformadores</span>
            </div>
        </div>

        <!-- Barra de Filtros e Controles -->
        <div class="rt-filtros">
            <div class="rt-filtros-left">
                <input type="search" id="prio-search-input" placeholder="Buscar pedido, projeto, NS, reprova..." style="min-width:260px;" autocomplete="off">

                <select id="prio-filter-select">
                    <option value="">Todas as prioridades</option>
                    <option value="priorizados">Apenas Priorizados (Emerg/Urg/Imp)</option>
                    <option value="emergente">Emergente</option>
                    <option value="urgente">Urgente</option>
                    <option value="importante">Importante</option>
                    <option value="neutro">Neutro</option>
                </select>

                <button type="button" id="btn-prio-expand-all" class="btn-expand-all" aria-expanded="false" title="Expandir ou recolher todos os níveis">
                    <span class="lbl-expand">Expandir Todos</span>
                </button>
            </div>

            <div class="rt-filtros-right">
                <div class="prio-view-switch">
                    <button type="button" class="prio-switch-btn is-active" id="btn-view-tree" data-view="tree">
                        Árvore Hierárquica
                    </button>
                    <button type="button" class="prio-switch-btn" id="btn-view-flat" data-view="flat">
                        Lista Direta (NS)
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Relatório de Peças Rolável -->
    <div class="prio-report-wrapper">

        <!-- VISÃO 1: Árvore Hierárquica (Pedido ➔ Projeto ➔ NS) -->
        <div class="rt-card" id="view-tree-container" style="padding:0;overflow:hidden;">
            <div class="prio-table-scroll-area">
                <table class="rt-table">
                    <thead>
                        <tr>
                            <th style="width:38px;text-align:center;">
                                <button type="button" class="tree-toggle-btn js-toggle-all-quick" aria-expanded="false" title="Expandir/Recolher todos" style="margin:0;">
                                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                                </button>
                            </th>
                            <th>Estrutura (Pedido ➔ Projeto ➔ N° Série)</th>
                            <th style="width:38%;">Reprovas / Contenções</th>
                            <th style="width:110px;text-align:center;">Parado há</th>
                            <th style="width:160px;text-align:right;">Prioridade</th>
                        </tr>
                    </thead>
                    <tbody id="tree-tbody">
                        <?php if (!$pedidosTree): ?>
                            <tr><td colspan="5" style="text-align:center;padding:30px 16px;color:var(--color-text-muted,#6b7280);">Nenhum transformador em retrabalho no momento.</td></tr>
                        <?php else: foreach ($pedidosTree as $pedId => $ped):
                            $pedPrio = $ped['prioridade'];
                            $searchPed = mb_strtolower($ped['numero'] . ' ' . $pedPrio);
                        ?>
                            <!-- Linha 1: Pedido -->
                            <tr class="row-pedido js-prio-row"
                                data-type="pedido"
                                data-id="<?= $pedId ?>"
                                data-pedido-id="<?= $pedId ?>"
                                data-prioridade="<?= htmlspecialchars($pedPrio) ?>"
                                data-search="<?= htmlspecialchars($searchPed) ?>">
                                <td style="text-align:center;">
                                    <button type="button" class="tree-toggle-btn js-tree-toggle" data-target="grp-ped-<?= $pedId ?>" aria-expanded="false">
                                        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                                    </button>
                                </td>
                                <td>
                                    <div style="display:flex;align-items:center;flex-wrap:wrap;gap:8px;">
                                        <span class="pedido-title">Pedido <?= htmlspecialchars($ped['numero']) ?></span>
                                        <span class="meta-tag"><?= count($ped['projetos']) ?> <?= count($ped['projetos']) === 1 ? 'projeto' : 'projetos' ?></span>
                                        <span class="meta-tag"><?= $ped['tot_trafos'] ?> <?= $ped['tot_trafos'] === 1 ? 'transformador' : 'transformadores' ?></span>
                                    </div>
                                </td>
                                <td style="color:var(--color-text-muted,#6b7280);">—</td>
                                <td style="text-align:center;color:var(--color-text-muted,#6b7280);">—</td>
                                <td style="text-align:right;">
                                    <?php if ($canEdit): ?>
                                        <button type="button"
                                                class="prio-badge-btn prio-style-<?= htmlspecialchars($pedPrio) ?> js-prio-trigger"
                                                data-tipo="pedido"
                                                data-id="<?= $pedId ?>"
                                                data-prioridade="<?= htmlspecialchars($pedPrio) ?>"
                                                title="Clique para alterar a prioridade do Pedido">
                                            <span class="badge-dot"></span>
                                            <span class="badge-label"><?= htmlspecialchars($prioridadesDef[$pedPrio]['label']) ?></span>
                                            <span class="badge-arrow">▼</span>
                                        </button>
                                    <?php else: ?>
                                        <span class="prio-badge-btn prio-style-<?= htmlspecialchars($pedPrio) ?>" style="cursor:default;" title="Apenas Consulta (Sem permissão para alterar prioridade)">
                                            <span class="badge-dot"></span>
                                            <span class="badge-label"><?= htmlspecialchars($prioridadesDef[$pedPrio]['label']) ?></span>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <!-- Sub-linhas do Pedido (Projetos e NS) -->
                            <?php foreach ($ped['projetos'] as $projId => $proj):
                                $projPrio = $proj['prioridade'];
                                $projPrioEfetiva = $proj['prioridade_efetiva'];
                                $searchProj = mb_strtolower($proj['codigo'] . ' ' . $proj['descricao'] . ' ' . $ped['numero'] . ' ' . $projPrioEfetiva);
                            ?>
                                <!-- Linha 2: Projeto -->
                                <tr class="row-projeto js-prio-row grp-ped-<?= $pedId ?>"
                                    data-type="projeto"
                                    data-id="<?= $projId ?>"
                                    data-pedido-id="<?= $pedId ?>"
                                    data-projeto-id="<?= $projId ?>"
                                    data-prioridade="<?= htmlspecialchars($projPrioEfetiva) ?>"
                                    data-prioridade-propria="<?= htmlspecialchars($projPrio) ?>"
                                    data-search="<?= htmlspecialchars($searchProj) ?>"
                                    style="display:none;">
                                    <td style="text-align:center;">
                                        <button type="button" class="tree-toggle-btn js-tree-toggle" data-target="grp-proj-<?= $projId ?>" aria-expanded="false" style="margin-left:8px;">
                                            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                                        </button>
                                    </td>
                                    <td>
                                        <div class="tree-indent-proj">
                                            <div style="display:flex;align-items:center;flex-wrap:wrap;gap:8px;">
                                                <span class="rt-code"><?= htmlspecialchars($proj['codigo']) ?></span>
                                                <?php if ($proj['descricao']): ?>
                                                    <span style="font-size:12px;color:var(--color-text-secondary,#5a6480);"><?= htmlspecialchars($proj['descricao']) ?></span>
                                                <?php endif; ?>
                                                <?php if ($proj['potencia'] !== null || $proj['classe'] !== null): ?>
                                                    <span class="meta-tag"><?= $proj['potencia'] !== null ? htmlspecialchars((string)$proj['potencia']).' kVA' : '' ?> <?= $proj['classe'] !== null ? htmlspecialchars((string)$proj['classe']).' kV' : '' ?></span>
                                                <?php endif; ?>
                                                <span class="meta-tag"><?= $proj['tot_trafos'] ?> trafos</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="color:var(--color-text-muted,#6b7280);">—</td>
                                    <td style="text-align:center;color:var(--color-text-muted,#6b7280);">—</td>
                                    <td style="text-align:right;">
                                        <?php if ($canEdit): ?>
                                            <button type="button"
                                                    class="prio-badge-btn prio-style-<?= htmlspecialchars($projPrioEfetiva) ?><?= $proj['herdada'] ? ' is-inherited' : '' ?> js-prio-trigger"
                                                    data-tipo="projeto"
                                                    data-id="<?= $projId ?>"
                                                    data-pedido-id="<?= $pedId ?>"
                                                    data-prioridade="<?= htmlspecialchars($projPrio) ?>"
                                                    data-prioridade-efetiva="<?= htmlspecialchars($projPrioEfetiva) ?>"
                                                    title="Clique para alterar prioridade do Projeto">
                                                <span class="badge-dot"></span>
                                                <span class="badge-label"><?= $proj['herdada'] ? '↳ ' . htmlspecialchars($prioridadesDef[$projPrioEfetiva]['label']) : htmlspecialchars($prioridadesDef[$projPrio]['label']) ?></span>
                                                <span class="badge-arrow">▼</span>
                                            </button>
                                        <?php else: ?>
                                            <span class="prio-badge-btn prio-style-<?= htmlspecialchars($projPrioEfetiva) ?><?= $proj['herdada'] ? ' is-inherited' : '' ?>" style="cursor:default;" title="Apenas Consulta (Sem permissão para alterar prioridade)">
                                                <span class="badge-dot"></span>
                                                <span class="badge-label"><?= $proj['herdada'] ? '↳ ' . htmlspecialchars($prioridadesDef[$projPrioEfetiva]['label']) : htmlspecialchars($prioridadesDef[$projPrio]['label']) ?></span>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>

                                <!-- Linha 3: Transformadores (NS) -->
                                <?php foreach ($proj['ns_list'] as $nsKey => $nsItem):
                                    $nsPrio = $nsItem['prioridade'];
                                    $nsPrioEfetiva = $nsItem['prioridade_efetiva'];
                                    $repBusca = array_map(fn($rp) => $rp['codigo'] . ' ' . $rp['descricao'], $nsItem['reprovas']);
                                    $searchNs = mb_strtolower($nsItem['ns_transformador'] . ' ' . $proj['codigo'] . ' ' . $ped['numero'] . ' ' . implode(' ', $repBusca) . ' ' . $nsPrioEfetiva);
                                ?>
                                    <tr class="row-ns js-prio-row grp-ped-<?= $pedId ?> grp-proj-<?= $projId ?>"
                                        data-type="ns"
                                        data-ns="<?= htmlspecialchars($nsItem['ns_transformador']) ?>"
                                        data-pedido-id="<?= $pedId ?>"
                                        data-projeto-id="<?= $projId ?>"
                                        data-prioridade="<?= htmlspecialchars($nsPrioEfetiva) ?>"
                                        data-prioridade-propria="<?= htmlspecialchars($nsPrio) ?>"
                                        data-search="<?= htmlspecialchars($searchNs) ?>"
                                        style="display:none;">
                                        <td></td>
                                        <td>
                                            <div class="tree-indent-ns">
                                                <div style="display:flex;align-items:center;gap:8px;">
                                                    <span class="rt-code" style="font-size:13.5px;"><?= htmlspecialchars($nsItem['ns_transformador']) ?></span>
                                                    <?php if ($nsItem['reincidencias'] > 1): ?>
                                                        <span class="badge-reinc-pill" title="<?= $nsItem['reincidencias'] ?> reincidências"><?= $nsItem['reincidencias'] ?>x</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (!$nsItem['reprovas']): ?>
                                                <span style="color:var(--color-text-muted,#6b7280);font-size:12px;">—</span>
                                            <?php else: ?>
                                                <div>
                                                    <?php foreach ($nsItem['reprovas'] as $rp): ?>
                                                        <div class="reprova-item-line">
                                                            <span class="reprova-code-badge"><?= htmlspecialchars($rp['codigo']) ?></span>
                                                            <span class="reprova-desc-text"><?= htmlspecialchars($rp['descricao'] ?: 'Sem descrição') ?></span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:center;">
                                            <span class="badge-dias-pill <?= $nsItem['dias'] > 15 ? 'dias-atraso' : ($nsItem['dias'] >= 7 ? 'dias-risco' : 'dias-ok') ?>">
                                                <?= $nsItem['dias'] ?>d
                                            </span>
                                        </td>
                                        <td style="text-align:right;">
                                            <?php if ($canEdit): ?>
                                                <button type="button"
                                                        class="prio-badge-btn prio-style-<?= htmlspecialchars($nsPrioEfetiva) ?><?= $nsItem['herdada'] ? ' is-inherited' : '' ?> js-prio-trigger"
                                                        data-tipo="ns"
                                                        data-ns="<?= htmlspecialchars($nsItem['ns_transformador']) ?>"
                                                        data-projeto-id="<?= $projId ?>"
                                                        data-pedido-id="<?= $pedId ?>"
                                                        data-prioridade="<?= htmlspecialchars($nsPrio) ?>"
                                                        data-prioridade-efetiva="<?= htmlspecialchars($nsPrioEfetiva) ?>"
                                                        title="Clique para alterar prioridade do Transformador">
                                                    <span class="badge-dot"></span>
                                                    <span class="badge-label"><?= $nsItem['herdada'] ? '↳ ' . htmlspecialchars($prioridadesDef[$nsPrioEfetiva]['label']) : htmlspecialchars($prioridadesDef[$nsPrio]['label']) ?></span>
                                                    <span class="badge-arrow">▼</span>
                                                </button>
                                            <?php else: ?>
                                                <span class="prio-badge-btn prio-style-<?= htmlspecialchars($nsPrioEfetiva) ?><?= $nsItem['herdada'] ? ' is-inherited' : '' ?>" style="cursor:default;" title="Apenas Consulta (Sem permissão para alterar prioridade)">
                                                    <span class="badge-dot"></span>
                                                    <span class="badge-label"><?= $nsItem['herdada'] ? '↳ ' . htmlspecialchars($prioridadesDef[$nsPrioEfetiva]['label']) : htmlspecialchars($prioridadesDef[$nsPrio]['label']) ?></span>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        <?php endforeach; endif; ?>
                        <tr class="js-prio-no-results" style="display:none;"><td colspan="5" style="text-align:center;padding:30px 16px;color:var(--color-text-muted,#6b7280);">Nenhum registro encontrado para o filtro aplicado.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- VISÃO 2: Lista Direta de Transformadores (Fila Plana) -->
        <div class="rt-card" id="view-flat-container" style="padding:0;overflow:hidden;display:none;">
            <div class="prio-table-scroll-area">
                <table class="rt-table">
                    <thead>
                        <tr>
                            <th style="width:130px;">Prioridade</th>
                            <th style="width:110px;">N° de Série</th>
                            <th style="width:90px;">Pedido</th>
                            <th style="width:180px;">Projeto</th>
                            <th style="width:120px;text-align:center;">Potência / Classe</th>
                            <th>Reprovas / Contenções</th>
                            <th style="width:90px;text-align:center;">Parado há</th>
                            <th style="width:70px;text-align:center;">Reinc.</th>
                        </tr>
                    </thead>
                    <tbody id="flat-tbody">
                        <?php if (!$nsFlattened): ?>
                            <tr><td colspan="8" style="text-align:center;padding:30px 16px;color:var(--color-text-muted,#6b7280);">Nenhum transformador em retrabalho no momento.</td></tr>
                        <?php else: foreach ($nsFlattened as $nsItem):
                            $nsPrio = $nsItem['prioridade'];
                            $nsPrioEfetiva = $nsItem['prioridade_efetiva'];
                            $repBusca = array_map(fn($rp) => $rp['codigo'] . ' ' . $rp['descricao'], $nsItem['reprovas']);
                            $searchFlat = mb_strtolower($nsItem['ns_transformador'] . ' ' . $nsItem['projeto_codigo'] . ' ' . $nsItem['pedido_numero'] . ' ' . implode(' ', $repBusca) . ' ' . $nsPrioEfetiva);
                        ?>
                            <tr class="js-prio-flat-row"
                                data-search="<?= htmlspecialchars($searchFlat) ?>"
                                data-prioridade="<?= htmlspecialchars($nsPrioEfetiva) ?>">
                                <td>
                                    <?php if ($canEdit): ?>
                                        <button type="button"
                                                class="prio-badge-btn prio-style-<?= htmlspecialchars($nsPrioEfetiva) ?><?= $nsItem['herdada'] ? ' is-inherited' : '' ?> js-prio-trigger"
                                                data-tipo="ns"
                                                data-ns="<?= htmlspecialchars($nsItem['ns_transformador']) ?>"
                                                data-projeto-id="<?= (int)$nsItem['projeto_id'] ?>"
                                                data-pedido-id="<?= (int)$nsItem['pedido_id'] ?>"
                                                data-prioridade="<?= htmlspecialchars($nsPrio) ?>"
                                                data-prioridade-efetiva="<?= htmlspecialchars($nsPrioEfetiva) ?>"
                                                title="Clique para alterar prioridade">
                                            <span class="badge-dot"></span>
                                            <span class="badge-label"><?= $nsItem['herdada'] ? '↳ ' . htmlspecialchars($prioridadesDef[$nsPrioEfetiva]['label']) : htmlspecialchars($prioridadesDef[$nsPrio]['label']) ?></span>
                                            <span class="badge-arrow">▼</span>
                                        </button>
                                    <?php else: ?>
                                        <span class="prio-badge-btn prio-style-<?= htmlspecialchars($nsPrioEfetiva) ?><?= $nsItem['herdada'] ? ' is-inherited' : '' ?>" style="cursor:default;" title="Apenas Consulta (Sem permissão para alterar prioridade)">
                                            <span class="badge-dot"></span>
                                            <span class="badge-label"><?= $nsItem['herdada'] ? '↳ ' . htmlspecialchars($prioridadesDef[$nsPrioEfetiva]['label']) : htmlspecialchars($prioridadesDef[$nsPrio]['label']) ?></span>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="rt-code"><?= htmlspecialchars($nsItem['ns_transformador']) ?></span></td>
                                <td><span class="rt-code"><?= htmlspecialchars($nsItem['pedido_numero']) ?></span></td>
                                <td>
                                    <span class="rt-code"><?= htmlspecialchars($nsItem['projeto_codigo']) ?></span>
                                    <?php if ($nsItem['projeto_descricao']): ?>
                                        <div style="font-size:11px;color:var(--color-text-secondary,#5a6480);margin-top:1px;"><?= htmlspecialchars($nsItem['projeto_descricao']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center;">
                                    <span class="meta-tag"><?= $nsItem['potencia'] !== null ? htmlspecialchars((string)$nsItem['potencia']).' kVA' : '—' ?> <?= $nsItem['classe'] !== null ? htmlspecialchars((string)$nsItem['classe']).' kV' : '' ?></span>
                                </td>
                                <td>
                                    <?php if (!$nsItem['reprovas']): ?>
                                        <span style="color:var(--color-text-muted,#6b7280);font-size:12px;">—</span>
                                    <?php else: ?>
                                        <div>
                                            <?php foreach ($nsItem['reprovas'] as $rp): ?>
                                                <div class="reprova-item-line">
                                                    <span class="reprova-code-badge"><?= htmlspecialchars($rp['codigo']) ?></span>
                                                    <span class="reprova-desc-text"><?= htmlspecialchars($rp['descricao'] ?: 'Sem descrição') ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center;">
                                    <span class="badge-dias-pill <?= $nsItem['dias'] > 15 ? 'dias-atraso' : ($nsItem['dias'] >= 7 ? 'dias-risco' : 'dias-ok') ?>">
                                        <?= $nsItem['dias'] ?>d
                                    </span>
                                </td>
                                <td style="text-align:center;">
                                    <?php if ($nsItem['reincidencias'] > 1): ?>
                                        <span class="badge-reinc-pill"><?= $nsItem['reincidencias'] ?>x</span>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        <tr class="js-prio-flat-no-results" style="display:none;"><td colspan="8" style="text-align:center;padding:30px 16px;color:var(--color-text-muted,#6b7280);">Nenhum registro encontrado para o filtro aplicado.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

</div>

<!-- Popover Flutuante de Seleção de Prioridade -->
<div id="prio-popover" class="prio-popover-menu">
    <div class="prio-popover-title">Definir Prioridade</div>
    <button type="button" class="prio-popover-item" data-value="emergente">
        <span class="item-dot" style="background:#dc2626;"></span>
        <span>Emergente</span>
    </button>
    <button type="button" class="prio-popover-item" data-value="urgente">
        <span class="item-dot" style="background:#ea580c;"></span>
        <span>Urgente</span>
    </button>
    <button type="button" class="prio-popover-item" data-value="importante">
        <span class="item-dot" style="background:#ca8a04;"></span>
        <span>Importante</span>
    </button>
    <button type="button" class="prio-popover-item" data-value="neutro">
        <span class="item-dot" style="background:#94a3b8;"></span>
        <span id="popover-neutro-label">Neutro (Padrão)</span>
    </button>
</div>

<script>
    window.PROJETOS_API = <?= json_encode($base . '/api/projetos-acao.php') ?>;
    window.PRIORIDADES_INFO = <?= json_encode($prioridadesDef, JSON_UNESCAPED_UNICODE) ?>;
    window.CAN_EDIT = <?= json_encode($canEdit) ?>;
</script>
<?php $ppJsVer = @filemtime(__DIR__ . '/../../assets/js/pedidos-prioridade.js') ?: (defined('APP_VERSION') ? APP_VERSION : '1'); ?>
<script src="<?= htmlspecialchars($base) ?>/assets/js/pedidos-prioridade.js?v=<?= htmlspecialchars((string) $ppJsVer) ?>"></script>

<?php layoutFooter(); ?>