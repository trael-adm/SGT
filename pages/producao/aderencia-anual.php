<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/boletim-painel-producao.php';
require_once __DIR__ . '/../../includes/producao-tabs-nav.php';

requireLogin();

$usuario = currentUser();
$base    = defined('APP_URL') ? APP_URL : '';

// ─── Parâmetros de Filtro de Anos (2010 a 2026) ──────────────────────────────
$anosDisponiveis = range(2010, 2026);

$anosDeRaw = (int) ($_GET['anos_de'] ?? 0);
$anosAteRaw = (int) ($_GET['anos_ate'] ?? 0);

if ($anosDeRaw > 0 && $anosAteRaw > 0) {
    if ($anosDeRaw > $anosAteRaw) {
        [$anosDeRaw, $anosAteRaw] = [$anosAteRaw, $anosDeRaw];
    }
    $anosSelecionadosRaw = range(max(2010, $anosDeRaw), min(2026, $anosAteRaw));
} else {
    $anosSelecionadosRaw = $_GET['anos'] ?? [2024, 2025, 2026];
    if (!is_array($anosSelecionadosRaw)) {
        $anosSelecionadosRaw = explode(',', (string) $anosSelecionadosRaw);
    }
}

$anosSelecionados = array_map('intval', (array) $anosSelecionadosRaw);
if (empty($anosSelecionados)) {
    $anosSelecionados = [2024, 2025, 2026];
}
sort($anosSelecionados);

$empresa = (int) ($_GET['empresa'] ?? 1);
if (!in_array($empresa, [1, 4, 0], true)) $empresa = 1;

$linha = trim((string) ($_GET['linha'] ?? 'TODOS'));

// ─── Rótulo do botão de Filtro de Período (aba ativa por padrão no modal) ────
$anosUlt3 = [2024, 2025, 2026];
$anosUlt5 = [2022, 2023, 2024, 2025, 2026];
$anos2010_2026 = [2010, 2026];
$isUlt3 = ($anosSelecionados === $anosUlt3);
$isUlt5 = ($anosSelecionados === $anosUlt5);
$is2010_2026 = ($anosSelecionados === $anos2010_2026);
$isCompleto = (count($anosSelecionados) === count($anosDisponiveis));
$isApenas2026 = ($anosSelecionados === [2026]);

if ($isUlt3) {
    $labelPeriodo = 'Últimos 3 Anos (2024-2026)';
} elseif ($isUlt5) {
    $labelPeriodo = 'Últimos 5 Anos (2022-2026)';
} elseif ($is2010_2026) {
    $labelPeriodo = 'Comparar 2010 com 2026';
} elseif ($isCompleto) {
    $labelPeriodo = 'Histórico Completo (2010-2026)';
} elseif ($isApenas2026) {
    $labelPeriodo = 'Apenas 2026';
} elseif (count($anosSelecionados) === 1) {
    $labelPeriodo = 'Ano ' . $anosSelecionados[0];
} else {
    $minAnoSel = min($anosSelecionados);
    $maxAnoSel = max($anosSelecionados);
    $isContiguo = (($maxAnoSel - $minAnoSel + 1) === count($anosSelecionados));
    $labelPeriodo = $isContiguo
        ? "{$minAnoSel}–{$maxAnoSel} (" . count($anosSelecionados) . ' anos)'
        : count($anosSelecionados) . ' anos selecionados';
}
$modoPeriodoAtivo = ($anosDeRaw > 0 && $anosAteRaw > 0) ? 'intervalo' : (($isUlt3 || $isUlt5 || $is2010_2026 || $isCompleto || $isApenas2026) ? 'presets' : 'especificos');

$dados = boletimCalcularAderenciaAnual($anosSelecionados, $empresa, $linha);
$kpis  = $dados['kpis'];
$modalComposicao = $dados['modal_composicao'];

$pageTitle = 'Aderência Anual — Dashboard de Produção';
layoutHeader($pageTitle);
?>

<style>
    .sgt-dash-header {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 20px;
    }
    .sgt-dash-title {
        font-size: var(--font-size-xl, 1.35rem);
        font-weight: 700;
        color: var(--color-text-primary, #1a2133);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .sgt-filter-card {
        background: var(--color-surface, #ffffff);
        border: 1px solid var(--color-border, #e2e6ed);
        border-radius: var(--radius-lg, 10px);
        padding: 14px 20px;
        box-shadow: var(--shadow-sm, 0 1px 3px rgba(26,39,68,0.08));
        margin-bottom: 20px;
    }
    .sgt-modal-backdrop {
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(15, 23, 42, 0.65);
        backdrop-filter: blur(5px);
        z-index: 9999;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 16px;
    }
    .sgt-modal-backdrop.active {
        display: flex;
    }
    .sgt-modal-dialog {
        background: #ffffff;
        border-radius: 14px;
        box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.4);
        border: 1px solid var(--color-border, #e2e6ed);
        overflow: hidden;
        max-height: 92vh;
        display: flex;
        flex-direction: column;
        animation: sgtModalZoomIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    }
    @keyframes sgtModalZoomIn {
        from { transform: scale(0.95); opacity: 0; }
        to { transform: scale(1); opacity: 1; }
    }
    .sgt-modal-header {
        padding: 16px 22px;
        border-bottom: 1px solid var(--color-border, #e2e6ed);
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: var(--color-surface-2, #f8f9fb);
    }
    .sgt-modal-body {
        padding: 20px 22px;
        overflow-y: auto;
        flex: 1;
    }
    .sgt-kpi-grid-3 {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 16px;
        margin-bottom: 20px;
    }
    @media (max-width: 900px) {
        .sgt-kpi-grid-3 {
            grid-template-columns: 1fr;
        }
    }
    .sgt-kpi-card {
        background: var(--color-surface, #ffffff);
        border: 1px solid var(--color-border, #e2e6ed);
        border-radius: var(--radius-lg, 10px);
        padding: 18px 20px;
        text-align: center;
        box-shadow: var(--shadow-sm, 0 1px 3px rgba(26,39,68,0.08));
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    .sgt-kpi-card:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-md, 0 4px 12px rgba(26,39,68,0.10));
    }
    .sgt-kpi-label {
        display: block;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--color-text-muted, #9aa3b8);
        margin-bottom: 4px;
    }
    .sgt-kpi-value {
        font-size: 1.65rem;
        font-weight: 800;
        font-family: var(--font-mono, monospace);
        color: var(--color-text-primary, #1a2133);
        line-height: 1.2;
    }
    .sgt-chart-card {
        background: var(--color-surface, #ffffff);
        border: 1px solid var(--color-border, #e2e6ed);
        border-radius: var(--radius-lg, 10px);
        padding: 20px;
        box-shadow: var(--shadow-sm, 0 1px 3px rgba(26,39,68,0.08));
        margin-bottom: 24px;
    }
    .sgt-card-header {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding-bottom: 12px;
        margin-bottom: 16px;
        border-bottom: 1px solid var(--color-border, #e2e6ed);
    }
    .sgt-pill-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 16px;
        border-radius: var(--radius-full, 9999px);
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        border: 1px solid var(--color-border, #e2e6ed);
        background: var(--color-surface-2, #f8f9fb);
        color: var(--color-text-secondary, #5a6480);
        transition: all 0.15s ease;
        text-decoration: none;
    }
    .sgt-pill-btn:hover {
        border-color: var(--color-accent, #e8a020);
        color: var(--color-text-primary, #1a2133);
        background: var(--color-surface, #ffffff);
    }
    .sgt-pill-btn.active {
        background: var(--color-sidebar, #1a3d2a);
        color: #ffffff;
        border-color: var(--color-sidebar, #1a3d2a);
        box-shadow: 0 2px 4px rgba(26, 61, 42, 0.2);
    }

    /* ─── Filtro de Período (Modal padrão dos indicadores, adaptado pra anos) ── */
    .sgt-periodo-trigger {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        height: 36px;
        padding: 0 14px;
        background: var(--color-surface-2, #f8f9fb);
        border: 1px solid var(--color-border, #e2e6ed);
        border-radius: var(--radius-md, 6px);
        cursor: pointer;
        font-family: inherit;
        transition: all 0.15s ease;
    }
    .sgt-periodo-trigger:hover {
        border-color: var(--color-accent, #e8a020);
        background: var(--color-surface, #ffffff);
    }
    .sgt-periodo-trigger .pf-trigger-label {
        font-size: 11px;
        font-weight: 700;
        color: var(--color-text-muted, #9aa3b8);
        text-transform: uppercase;
    }
    .sgt-periodo-trigger .pf-trigger-value {
        font-size: 13px;
        font-weight: 700;
        font-family: var(--font-mono, monospace);
        color: var(--color-text-primary, #1a2133);
    }

    .pf-overlay {
        display: none;
        position: fixed;
        top: 0; left: 0; width: 100vw; height: 100vh;
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(4px);
        z-index: 9999;
        align-items: center;
        justify-content: center;
        padding: 20px;
        box-sizing: border-box;
    }
    .pf-overlay.open { display: flex; }
    .pf-modal {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: 0 20px 40px rgba(15, 23, 42, 0.18);
        width: 100%;
        max-width: 420px;
        padding: 18px 20px;
        box-sizing: border-box;
        animation: pfModalSlideUp 0.18s ease;
    }
    @keyframes pfModalSlideUp {
        from { opacity: 0; transform: translateY(-8px) scale(0.98); }
        to   { opacity: 1; transform: translateY(0) scale(1); }
    }
    .pf-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding-bottom: 10px;
        border-bottom: 1px solid #f1f5f9;
        margin-bottom: 14px;
    }
    .pf-title {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        font-weight: 800;
        color: #0f172a;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }
    .pf-close-btn {
        background: none;
        border: none;
        font-size: 20px;
        line-height: 1;
        color: #94a3b8;
        cursor: pointer;
        padding: 0 4px;
        border-radius: 4px;
        transition: color 0.15s;
    }
    .pf-close-btn:hover { color: #0f172a; }

    .pf-tabs-container {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 6px;
        background: #f1f5f9;
        padding: 4px;
        border-radius: 8px;
        margin-bottom: 14px;
    }
    .pf-tab-btn {
        background: transparent;
        border: none;
        padding: 7px 4px;
        font-size: 11.5px;
        font-weight: 700;
        color: #64748b;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.15s ease;
        text-align: center;
    }
    .pf-tab-btn.active {
        background: #ffffff;
        color: #15803d;
        font-weight: 700;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.06);
    }

    .pf-field { margin-bottom: 12px; }
    .pf-label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #475569; margin-bottom: 5px; }
    .pf-input {
        width: 100%;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        padding: 7px 10px;
        font-size: 13px;
        font-weight: 600;
        color: #0f172a;
        background: #ffffff;
        box-sizing: border-box;
        outline: none;
        transition: border-color 0.15s, box-shadow 0.15s;
    }
    .pf-input:focus {
        border-color: #16a34a;
        box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.12);
    }
    .pf-range-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }
    .pf-preset-btn {
        display: block;
        width: 100%;
        text-align: left;
        background: #f8fafc;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 10px 12px;
        font-size: 12.5px;
        font-weight: 700;
        color: #334155;
        cursor: pointer;
        margin-bottom: 8px;
        transition: all 0.15s ease;
    }
    .pf-preset-btn:hover {
        border-color: #16a34a;
        color: #15803d;
        background: #f0fdf4;
    }
    .pf-preset-btn.active {
        background: #f0fdf4;
        border-color: #16a34a;
        color: #15803d;
        box-shadow: 0 0 0 1px #16a34a inset;
    }
    .pf-years-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 6px;
        max-height: 220px;
        overflow-y: auto;
        margin-bottom: 14px;
        padding: 2px;
    }
    .pf-year-chip {
        display: flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        padding: 6px 4px;
        font-size: 12px;
        font-weight: 700;
        font-family: var(--font-mono, monospace);
        color: #334155;
        cursor: pointer;
        background: #ffffff;
        transition: all 0.15s ease;
        user-select: none;
    }
    .pf-year-chip:hover { border-color: #16a34a; }
    .pf-year-chip:has(input:checked) {
        background: #16a34a;
        border-color: #16a34a;
        color: #ffffff;
    }
    .pf-year-chip input { display: none; }

    .pf-btn-apply {
        display: block;
        width: 100%;
        background: #16a34a;
        color: #ffffff;
        font-weight: 700;
        font-size: 13px;
        padding: 11px;
        border-radius: 8px;
        border: none;
        cursor: pointer;
        box-shadow: 0 4px 6px -1px rgba(22, 163, 74, 0.25);
        transition: background 0.15s;
        text-align: center;
    }
    .pf-btn-apply:hover { background: #15803d; }

    .pf-footer {
        border-top: 1px solid #f1f5f9;
        padding-top: 10px;
        margin-top: 12px;
        text-align: center;
    }
    .pf-btn-clear {
        background: none;
        border: none;
        color: #64748b;
        font-size: 11.5px;
        font-weight: 700;
        text-decoration: underline;
        cursor: pointer;
        transition: color 0.15s;
    }
    .pf-btn-clear:hover { color: #16a34a; }

    /* Impressão — mostra só a área do dashboard (KPIs + gráfico), sem título nem filtros */
    @media print {
        /* html/body ficam com overflow:hidden + height:100% pra controlar o scroll da tela
           (ver main.css) — sem resetar isso, o navegador trata o documento como 1 viewport só
           e recorta tudo que passar da altura da tela. */
        html, body {
            height: auto !important;
            overflow: visible !important;
        }
        /* Margem padrão do navegador (~1in) sobra pouco espaço vertical pro card do
           gráfico não estourar pra 2ª folha — reduzindo aqui garante caber tudo numa só. */
        @page {
            size: landscape;
            margin: 8mm;
        }
        .sgt-dash-header,
        .sgt-filter-card {
            display: none !important;
        }
        /* O layout padrão (sidebar/header) usa flex com overflow controlado — sem isso,
           .app-content mantém a largura/rolagem da tela e o gráfico sai cortado pela metade. */
        .app-wrapper, .app-main, .app-content {
            display: block !important;
            height: auto !important;
            overflow: visible !important;
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
        }
        .sgt-kpi-grid-3 {
            grid-template-columns: repeat(3, 1fr) !important;
            gap: 8px !important;
            margin-bottom: 14px !important;
        }
        .sgt-kpi-card {
            padding: 10px 8px !important;
            box-shadow: none !important;
            border: 1px solid #d0d5dd !important;
        }
        .sgt-kpi-value { font-size: 1.25rem !important; }
        .sgt-chart-card {
            box-shadow: none !important;
            border: 1px solid #d0d5dd !important;
            page-break-inside: avoid;
            break-inside: avoid;
            margin-bottom: 0 !important;
            padding: 12px !important;
        }
        .sgt-chart-card canvas { max-height: 330px !important; max-width: 100% !important; width: 100% !important; }
    }
</style>

<div style="max-width: 100%; margin: 0 auto; padding: 4px 0 24px 0;">

    <!-- Top Header do Dashboard -->
    <div class="sgt-dash-header">
        <div>
            <h1 class="sgt-dash-title">
                <span>Dashboard de Produção</span>
                <span class="badge" style="background:#dcfce7;color:#15803d;font-size:0.75rem;padding:3px 10px;border-radius:9999px;font-weight:700;">
                    ADERÊNCIA ANUAL
                </span>
            </h1>
            <p style="font-size:var(--font-size-sm, 0.875rem);color:var(--color-text-secondary, #5a6480);margin-top:3px;">
                Análise de Desempenho &bull; Comparativo Multi-Ano da Produção
            </p>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <button type="button" onclick="window.print()" class="btn btn-primary btn-sm btn-print" style="display:inline-flex;align-items:center;gap:6px;font-weight:600;cursor:pointer;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                Imprimir
            </button>
            <a href="/pages/painel-setor/index.php" class="btn btn-secondary btn-sm" style="display:inline-flex;align-items:center;gap:6px;font-weight:600;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 14l-4-4 4-4"/><path d="M5 10h11a4 4 0 1 1 0 8h-1"/></svg>
                Painel por Setor
            </a>
        </div>
    </div>

    <!-- Filtros de Fábrica e Anos (Multi-Seletor) -->
    <div class="sgt-filter-card" style="display:flex;flex-direction:column;gap:12px;align-items:flex-start;">
        <!-- 1. Filtro de Fábrica -->
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;width:100%;padding-bottom:12px;border-bottom:1px solid var(--color-border, #e2e6ed);">
            <span style="font-size:11px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;min-width:60px;">Fábrica:</span>
            <a href="?empresa=1&anos=<?= implode(',', $anosSelecionados) ?>&linha=<?= urlencode($linha) ?>" 
               class="sgt-pill-btn <?= $empresa === 1 ? 'active' : '' ?>" style="display:inline-flex;align-items:center;gap:6px;">
                <span>🏭</span> Fábrica 1 - Distribuição
            </a>
            <a href="?empresa=4&anos=<?= implode(',', $anosSelecionados) ?>&linha=<?= urlencode($linha) ?>" 
               class="sgt-pill-btn <?= $empresa === 4 ? 'active' : '' ?>" style="display:inline-flex;align-items:center;gap:6px;">
                <span>⚡</span> Fábrica 2 - Média Força
            </a>
            <a href="?empresa=0&anos=<?= implode(',', $anosSelecionados) ?>&linha=<?= urlencode($linha) ?>" 
               class="sgt-pill-btn <?= $empresa === 0 ? 'active' : '' ?>" style="display:inline-flex;align-items:center;gap:6px;">
                <span>🌐</span> Todas as Fábricas
            </a>
        </div>

        <!-- 2. Filtro de Período (Anos) -->
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;width:100%;">
            <span style="font-size:11px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;min-width:60px;">Período:</span>
            <button type="button" class="sgt-periodo-trigger" onclick="abrirFiltroPeriodo()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--color-text-muted);"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <span class="pf-trigger-label">Filtro de Período</span>
                <span class="pf-trigger-value"><?= htmlspecialchars($labelPeriodo) ?></span>
            </button>
            <span style="font-size:11px;color:var(--color-text-muted);font-weight:600;">
                (<?= count($anosSelecionados) ?> <?= count($anosSelecionados) === 1 ? 'ano selecionado' : 'anos selecionados' ?>)
            </span>
        </div>
    </div>

    <!-- Grid de KPIs Anuais -->
    <div class="sgt-kpi-grid-3">
        <!-- 1. Média de Dias Úteis -->
        <div class="sgt-kpi-card">
            <span class="sgt-kpi-label">MÉDIA DE DIAS ÚTEIS</span>
            <span class="sgt-kpi-value"><?= number_format($kpis['media_dias_uteis'], 2, ',', '.') ?></span>
        </div>

        <!-- 2. Aderência Anual -->
        <?php
            $anosAderCard = $kpis['aderencia_anual_anos'] ?? [];
            $corAder = $kpis['aderencia_anual'] >= 100
                ? 'var(--color-success, #16a34a)'
                : ($kpis['aderencia_anual'] >= 90 ? '#0ea5e9' : '#dc2626');
        ?>
        <div class="sgt-kpi-card">
            <span class="sgt-kpi-label">ADERÊNCIA ANUAL</span>
            <span class="sgt-kpi-value" style="color:<?= $corAder ?>;"><?= number_format($kpis['aderencia_anual'], 2, ',', '.') ?>%</span>
            <?php if (!empty($anosAderCard)): ?>
                <span style="display:block;font-size:0.7rem;color:var(--color-text-secondary, #5a6480);margin-top:2px;">
                    Real ÷ Programado (Plano Mestre) &bull; <?= count($anosAderCard) > 1 ? (min($anosAderCard) . '–' . max($anosAderCard)) : $anosAderCard[0] ?>
                </span>
            <?php endif; ?>
        </div>

        <!-- 3. Total Produzido -->
        <div class="sgt-kpi-card">
            <span class="sgt-kpi-label">TOTAL PRODUZIDO (<?= htmlspecialchars($modalComposicao['nome_fabrica']) ?>)</span>
            <span class="sgt-kpi-value"><?= number_format($kpis['total_produzido'], 0, ',', '.') ?></span>
        </div>
    </div>

    <!-- Gráfico Comparativo Anual -->
    <div class="sgt-chart-card">
        <div class="sgt-card-header">
            <div>
                <h3 style="font-size:14px;font-weight:700;color:var(--color-text-primary);margin:0;">
                    Comparativo Anual &bull; <?= htmlspecialchars($modalComposicao['nome_fabrica']) ?>
                </h3>
                <p style="font-size:11px;color:var(--color-text-muted);margin:2px 0 0 0;">
                    Volume de peças produzidas mês a mês &bull; <strong style="color:#0284c7;">Clique em qualquer barra para abrir os gráficos detalhados de <?= ($empresa === 4) ? 'Tipo Construtivo' : 'Tipo de Núcleo' ?></strong>
                </p>
            </div>
            
            <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                <!-- Botão Abrir Janela de Gráficos -->
                <button type="button" onclick="abrirModalComposicao()" class="btn btn-sm" style="background:#f0fdf4;border:1px solid #86efac;color:#15803d;font-size:12px;font-weight:700;padding:6px 14px;border-radius:9999px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all 0.15s ease;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>
                    <?= ($empresa === 4) ? 'Janela: Tipo Construtivo' : 'Janela: Tipo de Núcleo' ?>
                </button>

                <!-- Legenda Dinâmica para todos os anos selecionados -->
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-size:11px;font-weight:700;color:var(--color-text-secondary);">
                    <?php foreach ($dados['datasets'] as $ds): ?>
                        <span style="display:flex;align-items:center;gap:5px;">
                            <span style="width:10px;height:10px;border-radius:50%;background:<?= $ds['backgroundColor'] ?>;display:inline-block;"></span>
                            <?= htmlspecialchars($ds['label']) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div style="position:relative;width:100%;height:440px;">
            <canvas id="chartComparativoAnual"></canvas>
        </div>
    </div>

</div>

<!-- ═══════════════════════════════════════════════════════════════════════════════ -->
<!-- JANELA MODAL: Gráficos de Tipos de Núcleo / Construtivo                       -->
<!-- ═══════════════════════════════════════════════════════════════════════════════ -->
<div id="modalComposicao" class="sgt-modal-backdrop" onclick="fecharModalSeFora(event)">
    <div class="sgt-modal-dialog" style="max-width:860px;width:95%;">
        <!-- Header -->
        <div class="sgt-modal-header">
            <div>
                <h3 id="modalTitulo" style="margin:0;font-size:15px;font-weight:800;color:var(--color-text-primary);display:flex;align-items:center;gap:8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.2"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>
                    <?= htmlspecialchars($modalComposicao['tipo_titulo']) ?>
                </h3>
                <p id="modalSubtitulo" style="margin:2px 0 0 0;font-size:12px;color:var(--color-text-muted);">
                    <?= htmlspecialchars($modalComposicao['nome_fabrica']) ?> &bull; Análise detalhada por tipo e volume
                </p>
            </div>
            <button type="button" onclick="fecharModalComposicao()" style="border:none;background:transparent;font-size:22px;line-height:1;color:var(--color-text-muted);cursor:pointer;padding:4px 8px;border-radius:6px;">&times;</button>
        </div>

        <!-- Body -->
        <div class="sgt-modal-body">
            <!-- Barra de Seleção de Visualização -->
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;background:var(--color-surface-2,#f8f9fb);padding:10px 14px;border-radius:8px;border:1px solid var(--color-border,#e2e6ed);">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <label style="font-size:11px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;">Filtrar no Gráfico:</label>
                    
                    <select id="modalSelectAno" onchange="atualizarGraficosModal()" class="form-select" style="height:32px;font-size:12px;font-weight:600;padding:0 10px;border-radius:6px;border:1px solid var(--color-border);background:#fff;">
                        <?php foreach ($anosSelecionados as $anoOp): ?>
                            <option value="<?= $anoOp ?>" <?= $anoOp === max($anosSelecionados) ? 'selected' : '' ?>><?= $anoOp ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select id="modalSelectMes" onchange="atualizarGraficosModal()" class="form-select" style="height:32px;font-size:12px;font-weight:600;padding:0 10px;border-radius:6px;border:1px solid var(--color-border);background:#fff;">
                        <option value="0">Ano Inteiro (Acumulado)</option>
                        <?php foreach ($dados['meses_labels'] as $mIdx => $mNome): ?>
                            <option value="<?= $mIdx + 1 ?>"><?= $mNome ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="modalTotalPecasBadge" style="font-size:13px;font-weight:800;color:var(--color-text-primary);font-family:monospace;background:#fff;padding:4px 12px;border-radius:6px;border:1px solid var(--color-border);">
                    Total: 0 peças
                </div>
            </div>

            <!-- Gráfico Donut + Cards de Proporção -->
            <div style="display:grid;grid-template-columns: 280px 1fr;gap:20px;align-items:center;">
                <div style="position:relative;height:220px;display:flex;align-items:center;justify-content:center;">
                    <canvas id="chartDonutModal"></canvas>
                </div>
                <div>
                    <div id="modalCardsSubtipos" style="display:flex;flex-direction:column;gap:10px;">
                        <!-- Cards preenchidos via JavaScript -->
                    </div>
                </div>
            </div>

            <!-- Gráfico de Evolução Mês a Mês -->
            <div style="margin-top:22px;border-top:1px solid var(--color-border,#e2e6ed);padding-top:14px;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                    <h4 id="modalTituloBarras" style="font-size:12px;font-weight:700;color:var(--color-text-primary);margin:0;text-transform:uppercase;letter-spacing:0.5px;">
                        Evolução Mensal no Ano de <?= max($anosSelecionados) ?>
                    </h4>
                    <span style="font-size:11px;color:var(--color-text-muted);">Valores mês a mês por categoria</span>
                </div>
                <div style="position:relative;height:200px;width:100%;">
                    <canvas id="chartBarrasModal"></canvas>
                </div>
                <p id="modalNotaMesParcial" style="display:none;margin:8px 0 0 0;font-size:10.5px;color:#d97706;font-weight:600;align-items:center;gap:5px;">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <span id="modalNotaMesParcialTexto"></span>
                </p>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
<script>
    Chart.register(ChartDataLabels);

    function hexParaRgba(hex, alpha) {
        const h = hex.replace('#', '');
        const r = parseInt(h.substring(0, 2), 16);
        const g = parseInt(h.substring(2, 4), 16);
        const b = parseInt(h.substring(4, 6), 16);
        return `rgba(${r}, ${g}, ${b}, ${alpha})`;
    }

    const mesesLabels = <?= json_encode($dados['meses_labels']) ?>;
    const datasetsConfig = <?= json_encode($dados['datasets']) ?>;
    const modalComposicao = <?= json_encode($modalComposicao) ?>;

    // ─── 1. Gráfico Principal Comparativo Anual ──────────────────────────────
    const ctx = document.getElementById('chartComparativoAnual').getContext('2d');
    const chartPrincipal = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: mesesLabels,
            datasets: datasetsConfig
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: {
                padding: { top: 24 }
            },
            onClick: function(evt, elements) {
                if (elements && elements.length > 0) {
                    const el = elements[0];
                    const dsIdx = el.datasetIndex;
                    const mesIdx = el.index;
                    const anoSel = datasetsConfig[dsIdx].label;
                    abrirModalComposicao(anoSel, mesIdx + 1);
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { color: '#5a6480', font: { weight: '600', size: 11 } }
                },
                y: {
                    beginAtZero: true,
                    grace: '8%',
                    grid: { color: '#f1f5f9' },
                    ticks: { color: '#9aa3b8', font: { family: 'monospace' } }
                }
            },
            plugins: {
                legend: { display: false },
                datalabels: {
                    clip: false,
                    color: '#1a2133',
                    anchor: 'end',
                    align: 'top',
                    offset: 3,
                    font: { size: 12, weight: '700', family: 'monospace' },
                    formatter: function(val) {
                        return (val && val > 0) ? val : '';
                    }
                },
                tooltip: {
                    backgroundColor: '#1a2133',
                    titleColor: '#ffffff',
                    borderColor: '#e2e6ed',
                    borderWidth: 1,
                    padding: 10,
                    callbacks: {
                        afterFooter: function() {
                            return '💡 Clique na barra para ver a janela de tipos';
                        }
                    }
                }
            }
        }
    });

    // ─── 2. Lógica da Janela Modal de Gráficos ────────────────────────────────
    let chartDonutInstance = null;
    let chartBarrasInstance = null;

    function abrirModalComposicao(ano = null, mes = 0) {
        const modal = document.getElementById('modalComposicao');
        const selectAno = document.getElementById('modalSelectAno');
        const selectMes = document.getElementById('modalSelectMes');

        if (ano && selectAno.querySelector(`option[value="${ano}"]`)) {
            selectAno.value = ano;
        }
        selectMes.value = mes;

        atualizarGraficosModal();
        modal.classList.add('active');
    }

    function fecharModalComposicao() {
        document.getElementById('modalComposicao').classList.remove('active');
    }

    function fecharModalSeFora(e) {
        if (e.target.id === 'modalComposicao') {
            fecharModalComposicao();
        }
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') fecharModalComposicao();
    });

    function atualizarGraficosModal() {
        const ano = document.getElementById('modalSelectAno').value;
        const mes = parseInt(document.getElementById('modalSelectMes').value, 10);

        const subtipos = modalComposicao.subtipos;
        const labelsMap = modalComposicao.labels;
        const coresMap = modalComposicao.cores;

        let contagens = {};
        let totalPeriodo = 0;

        if (mes === 0) {
            // Ano inteiro consolidado
            const dadosAno = (modalComposicao.por_ano && modalComposicao.por_ano[ano]) ? modalComposicao.por_ano[ano] : {};
            subtipos.forEach(s => {
                contagens[s] = dadosAno[s] || 0;
                totalPeriodo += contagens[s];
            });
            document.getElementById('modalTituloBarras').innerText = `Evolução Mensal no Ano de ${ano}`;
        } else {
            // Mês específico
            const dadosMes = (modalComposicao.por_mes && modalComposicao.por_mes[ano] && modalComposicao.por_mes[ano][mes]) 
                ? modalComposicao.por_mes[ano][mes] : {};
            subtipos.forEach(s => {
                contagens[s] = dadosMes[s] || 0;
                totalPeriodo += contagens[s];
            });
            const nomeMes = mesesLabels[mes - 1] || `Mês ${mes}`;
            document.getElementById('modalTituloBarras').innerText = `Evolução Mensal no Ano de ${ano} (Foco: ${nomeMes})`;
        }

        // Atualiza badge de total
        document.getElementById('modalTotalPecasBadge').innerText = `Total: ${totalPeriodo.toLocaleString('pt-BR')} peças`;

        // Monta os Cards de Subtipos com percentuais
        const containerCards = document.getElementById('modalCardsSubtipos');
        containerCards.innerHTML = '';

        subtipos.forEach(s => {
            const qtd = contagens[s] || 0;
            const pct = totalPeriodo > 0 ? ((qtd / totalPeriodo) * 100).toFixed(1) : '0.0';
            const cor = coresMap[s] || '#64748b';
            const labelNome = labelsMap[s] || s;

            const card = document.createElement('div');
            card.style.cssText = 'background:var(--color-surface-2,#f8f9fb);border:1px solid var(--color-border,#e2e6ed);border-radius:8px;padding:10px 14px;';
            card.innerHTML = `
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                    <span style="font-size:12px;font-weight:700;color:var(--color-text-primary);display:flex;align-items:center;gap:6px;">
                        <span style="width:10px;height:10px;border-radius:3px;background:${cor};display:inline-block;"></span>
                        ${labelNome}
                    </span>
                    <span style="font-size:12px;font-weight:800;font-family:monospace;color:var(--color-text-primary);">
                        ${qtd.toLocaleString('pt-BR')} <span style="font-size:10px;color:var(--color-text-muted);font-weight:600;">(${pct}%)</span>
                    </span>
                </div>
                <div style="width:100%;height:6px;background:#e2e8f0;border-radius:9999px;overflow:hidden;">
                    <div style="width:${pct}%;height:100%;background:${cor};border-radius:9999px;transition:width 0.3s ease;"></div>
                </div>
            `;
            containerCards.appendChild(card);
        });

        // ─── Atualiza Gráfico Donut ───
        const donutLabels = subtipos.map(s => labelsMap[s] || s);
        const donutData = subtipos.map(s => contagens[s] || 0);
        const donutColors = subtipos.map(s => coresMap[s] || '#64748b');

        const ctxDonut = document.getElementById('chartDonutModal').getContext('2d');
        if (chartDonutInstance) chartDonutInstance.destroy();

        // Plugin local: escreve o total no vazio central da rosca (em vez de deixar
        // o miolo vazio) — só desenha quando há dado, evita "0" órfão sem contexto.
        const centroDonutPlugin = {
            id: 'centroDonutTotal',
            afterDraw(chart) {
                const totalCentro = chart.data.datasets[0].data.reduce((a, b) => a + b, 0);
                if (!totalCentro) return;
                const { ctx: c, chartArea } = chart;
                const cx = (chartArea.left + chartArea.right) / 2;
                const cy = (chartArea.top + chartArea.bottom) / 2;
                c.save();
                c.textAlign = 'center';
                c.textBaseline = 'middle';
                c.fillStyle = '#1a2133';
                c.font = "800 20px 'SFMono-Regular', Consolas, monospace";
                c.fillText(totalCentro.toLocaleString('pt-BR'), cx, cy - 8);
                c.fillStyle = '#9aa3b8';
                c.font = "700 10px system-ui, sans-serif";
                c.fillText('PEÇAS', cx, cy + 12);
                c.restore();
            }
        };

        chartDonutInstance = new Chart(ctxDonut, {
            type: 'doughnut',
            data: {
                labels: donutLabels,
                datasets: [{
                    data: donutData,
                    backgroundColor: donutColors,
                    borderWidth: 2,
                    borderColor: '#ffffff',
                    hoverOffset: 6
                }]
            },
            plugins: [centroDonutPlugin],
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: { display: false },
                    datalabels: {
                        color: '#ffffff',
                        font: { size: 10, weight: '700' },
                        formatter: function(val, ctx) {
                            if (!val || val === 0) return '';
                            const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                            const p = total > 0 ? ((val / total) * 100).toFixed(0) : 0;
                            return p >= 5 ? `${p}%` : '';
                        }
                    },
                    tooltip: {
                        backgroundColor: '#1a2133',
                        titleColor: '#ffffff',
                        borderColor: '#e2e6ed',
                        borderWidth: 1,
                        padding: 10,
                        callbacks: {
                            label: function(ctx) {
                                const val = ctx.raw || 0;
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                const p = total > 0 ? ((val / total) * 100).toFixed(1) : 0;
                                return ` ${val.toLocaleString('pt-BR')} peças (${p}%)`;
                            }
                        }
                    }
                }
            }
        });

        // ─── Atualiza Gráfico de Barras Mensais do Ano ───
        // Mês corrente e ainda em andamento (ex.: dia 8 de setembro) produz uma barra
        // baixa que não é queda de produção — é mês incompleto. Marcamos essa barra com
        // cor esmaecida + aviso abaixo do gráfico em vez de deixar o dado "mentir" mudo.
        const hoje = new Date();
        const mesEmAndamentoIdx = (Number(ano) === hoje.getFullYear()) ? hoje.getMonth() : -1; // 0-based

        const dadosMesAno = (modalComposicao.por_mes && modalComposicao.por_mes[ano]) ? modalComposicao.por_mes[ano] : {};
        const barrasDatasets = subtipos.map(s => {
            const dataSerie = [];
            for (let m = 1; m <= 12; m++) {
                dataSerie.push((dadosMesAno[m] && dadosMesAno[m][s]) ? dadosMesAno[m][s] : 0);
            }
            const corBase = coresMap[s] || '#64748b';
            return {
                label: labelsMap[s] || s,
                data: dataSerie,
                backgroundColor: dataSerie.map((_, idx) => idx === mesEmAndamentoIdx ? hexParaRgba(corBase, 0.4) : corBase),
                borderRadius: 4,
                barPercentage: 0.85,
                minBarLength: 2
            };
        });

        const notaEl = document.getElementById('modalNotaMesParcial');
        if (mesEmAndamentoIdx >= 0) {
            document.getElementById('modalNotaMesParcialTexto').textContent =
                `${mesesLabels[mesEmAndamentoIdx]}/${ano} ainda está em andamento — a barra clara não é comparável aos meses fechados.`;
            notaEl.style.display = 'flex';
        } else {
            notaEl.style.display = 'none';
        }

        const ctxBarras = document.getElementById('chartBarrasModal').getContext('2d');
        if (chartBarrasInstance) chartBarrasInstance.destroy();

        chartBarrasInstance = new Chart(ctxBarras, {
            type: 'bar',
            data: {
                labels: mesesLabels,
                datasets: barrasDatasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: {
                            font: { weight: '600', size: 10 },
                            color: (ctx) => ctx.index === mesEmAndamentoIdx ? '#d97706' : '#5a6480'
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f1f5f9' },
                        ticks: { color: '#9aa3b8', font: { family: 'monospace', size: 10 } }
                    }
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: { boxWidth: 10, font: { size: 11, weight: '600' } }
                    },
                    datalabels: { display: false },
                    tooltip: {
                        backgroundColor: '#1a2133',
                        titleColor: '#ffffff',
                        borderColor: '#e2e6ed',
                        borderWidth: 1,
                        padding: 10,
                        callbacks: {
                            title: function(items) {
                                const idx = items[0].dataIndex;
                                return idx === mesEmAndamentoIdx ? `${items[0].label} (mês em andamento)` : items[0].label;
                            },
                            label: function(ctx) {
                                return ` ${ctx.dataset.label}: ${(ctx.raw || 0).toLocaleString('pt-BR')} peças`;
                            }
                        }
                    }
                }
            }
        });
    }
</script>

<!-- ─── MODAL: FILTRO DE PERÍODO (Presets / Anos Específicos / Intervalo) ──── -->
<div class="pf-overlay" id="pfOverlay" onclick="if(event.target === this) fecharFiltroPeriodo();">
    <div class="pf-modal">

        <div class="pf-header">
            <div class="pf-title">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <span>FILTRO DE PERÍODO</span>
            </div>
            <button type="button" class="pf-close-btn" onclick="fecharFiltroPeriodo()" aria-label="Fechar">&times;</button>
        </div>

        <div class="pf-tabs-container">
            <button type="button" class="pf-tab-btn <?= $modoPeriodoAtivo === 'presets' ? 'active' : '' ?>" id="pfTabBtnPresets" onclick="trocarTabPeriodo('presets')">Presets</button>
            <button type="button" class="pf-tab-btn <?= $modoPeriodoAtivo === 'especificos' ? 'active' : '' ?>" id="pfTabBtnEspecificos" onclick="trocarTabPeriodo('especificos')">Anos Específicos</button>
            <button type="button" class="pf-tab-btn <?= $modoPeriodoAtivo === 'intervalo' ? 'active' : '' ?>" id="pfTabBtnIntervalo" onclick="trocarTabPeriodo('intervalo')">Intervalo</button>
        </div>

        <!-- ABA 1: PRESETS -->
        <form method="GET" id="pfFormPresets" style="display: <?= $modoPeriodoAtivo === 'presets' ? 'block' : 'none' ?>;">
            <input type="hidden" name="empresa" value="<?= $empresa ?>">
            <input type="hidden" name="linha" value="<?= htmlspecialchars($linha) ?>">
            <input type="hidden" name="anos" id="pfPresetAnos" value="">
            <button type="button" class="pf-preset-btn <?= $isUlt3 ? 'active' : '' ?>" onclick="aplicarPreset('2024,2025,2026')">Últimos 3 Anos (2024-2026)</button>
            <button type="button" class="pf-preset-btn <?= $isUlt5 ? 'active' : '' ?>" onclick="aplicarPreset('2022,2023,2024,2025,2026')">Últimos 5 Anos (2022-2026)</button>
            <button type="button" class="pf-preset-btn <?= $is2010_2026 ? 'active' : '' ?>" onclick="aplicarPreset('2010,2026')">Comparar 2010 com 2026</button>
            <button type="button" class="pf-preset-btn <?= $isCompleto ? 'active' : '' ?>" onclick="aplicarPreset('<?= implode(',', $anosDisponiveis) ?>')">Histórico Completo (2010-2026)</button>
            <button type="button" class="pf-preset-btn <?= $isApenas2026 ? 'active' : '' ?>" onclick="aplicarPreset('2026')" style="margin-bottom:0;">Apenas 2026</button>
        </form>

        <!-- ABA 2: ANOS ESPECÍFICOS -->
        <form method="GET" id="pfFormEspecificos" style="display: <?= $modoPeriodoAtivo === 'especificos' ? 'block' : 'none' ?>;">
            <input type="hidden" name="empresa" value="<?= $empresa ?>">
            <input type="hidden" name="linha" value="<?= htmlspecialchars($linha) ?>">
            <div class="pf-years-grid">
                <?php foreach (array_reverse($anosDisponiveis) as $a): ?>
                    <label class="pf-year-chip">
                        <input type="checkbox" name="anos[]" value="<?= $a ?>" <?= in_array($a, $anosSelecionados, true) ? 'checked' : '' ?>>
                        <?= $a ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="pf-btn-apply">Aplicar Seleção</button>
        </form>

        <!-- ABA 3: INTERVALO -->
        <form method="GET" id="pfFormIntervalo" style="display: <?= $modoPeriodoAtivo === 'intervalo' ? 'block' : 'none' ?>;">
            <input type="hidden" name="empresa" value="<?= $empresa ?>">
            <input type="hidden" name="linha" value="<?= htmlspecialchars($linha) ?>">
            <div class="pf-range-grid">
                <div class="pf-field">
                    <label class="pf-label">De (Ano):</label>
                    <select name="anos_de" class="pf-input">
                        <?php foreach ($anosDisponiveis as $a): ?>
                            <option value="<?= $a ?>" <?= ($anosDeRaw ?: min($anosSelecionados)) === $a ? 'selected' : '' ?>><?= $a ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="pf-field">
                    <label class="pf-label">Até (Ano):</label>
                    <select name="anos_ate" class="pf-input">
                        <?php foreach ($anosDisponiveis as $a): ?>
                            <option value="<?= $a ?>" <?= ($anosAteRaw ?: max($anosSelecionados)) === $a ? 'selected' : '' ?>><?= $a ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit" class="pf-btn-apply" style="margin-top:4px;">Aplicar Intervalo</button>
        </form>

        <div class="pf-footer">
            <a href="?empresa=<?= $empresa ?>&anos=2024,2025,2026" class="pf-btn-clear">Voltar ao Padrão (Últimos 3 Anos)</a>
        </div>

    </div>
</div>

<script>
    function abrirFiltroPeriodo() {
        document.getElementById('pfOverlay')?.classList.add('open');
    }
    function fecharFiltroPeriodo() {
        document.getElementById('pfOverlay')?.classList.remove('open');
    }
    function trocarTabPeriodo(modo) {
        document.getElementById('pfTabBtnPresets')?.classList.toggle('active', modo === 'presets');
        document.getElementById('pfTabBtnEspecificos')?.classList.toggle('active', modo === 'especificos');
        document.getElementById('pfTabBtnIntervalo')?.classList.toggle('active', modo === 'intervalo');

        document.getElementById('pfFormPresets').style.display = (modo === 'presets') ? 'block' : 'none';
        document.getElementById('pfFormEspecificos').style.display = (modo === 'especificos') ? 'block' : 'none';
        document.getElementById('pfFormIntervalo').style.display = (modo === 'intervalo') ? 'block' : 'none';
    }
    function aplicarPreset(anosCsv) {
        document.getElementById('pfPresetAnos').value = anosCsv;
        document.getElementById('pfFormPresets').submit();
    }
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') fecharFiltroPeriodo();
    });
</script>

<?php layoutFooter(); ?>
