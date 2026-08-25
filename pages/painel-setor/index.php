<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/boletim-painel-setor.php';

requireLogin();

$usuario = currentUser();
$base    = defined('APP_URL') ? APP_URL : '';

// ─── Parâmetros de Filtro ───────────────────────────────────────────────────
$mes = trim((string) ($_GET['mes'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
    $mes = date('Y-m');
}

$setorSel   = trim((string) ($_GET['setor'] ?? 'CONSOLIDADO'));
$dataCorte  = trim((string) ($_GET['data_corte'] ?? ''));
$modoData   = trim((string) ($_GET['modo_data'] ?? 'mes'));
$dataInicio = trim((string) ($_GET['data_inicio'] ?? ''));
$dataFim    = trim((string) ($_GET['data_fim'] ?? ''));

if ($dataCorte !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataCorte)) {
    $dataCorte = null;
}
if ($dataInicio !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInicio)) {
    $dataInicio = null;
}
if ($dataFim !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFim)) {
    $dataFim = null;
}

// ─── Carrega Métricas do Painel do Setor ────────────────────────────────────
$dadosPainel           = boletimCalcularPainelSetor($mes, $setorSel, $dataCorte, $modoData, $dataInicio, $dataFim);
$setorInfo             = $dadosPainel['setor_info'];
$analiseGargalos       = $dadosPainel['analise_gargalos'];
$graficoAcompanhamento = $dadosPainel['grafico_acompanhamento'];

$pageTitle = 'Painel por Setor — ' . $setorInfo['nome'];
layoutHeader($pageTitle);
?>

<style>
    /* ─── Design System Boletim de Medição ────────────────────────────────── */
    .ps-header-row {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 18px;
    }

    .ps-ref-row {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 10px;
    }

    .ps-ref-chip {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: var(--color-surface, #ffffff);
        border: 1px solid var(--color-border, #e2e6ed);
        border-radius: var(--radius-md, 6px);
        padding: 0 12px;
        height: 36px;
        box-sizing: border-box;
        box-shadow: var(--shadow-sm, 0 1px 2px rgba(0,0,0,0.05));
    }
    .ps-ref-chip .ps-ref-label {
        font-size: var(--font-size-xs, 0.75rem);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .6px;
        color: var(--color-text-muted, #9aa3b8);
        line-height: 1;
    }
    .ps-ref-chip .ps-ref-value {
        font-size: var(--font-size-sm, 0.875rem);
        font-weight: 600;
        color: var(--color-text-primary, #1a2133);
        line-height: 1;
    }

    /* Pulse Dot do Auto-Refresh */
    .pulse-dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: #16a34a;
        display: inline-block;
        box-shadow: 0 0 0 0 rgba(22, 163, 74, 0.7);
        animation: pulseAnimation 2s infinite;
    }
    @keyframes pulseAnimation {
        0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(22, 163, 74, 0.7); }
        70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(22, 163, 74, 0); }
        100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(22, 163, 74, 0); }
    }

    /* Setor Pills Bar */
    .ps-pills-bar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px;
        margin-bottom: 20px;
        padding: 10px 14px;
        background: var(--color-surface, #ffffff);
        border: 1px solid var(--color-border, #e2e6ed);
        border-radius: var(--radius-lg, 8px);
        box-shadow: var(--shadow-sm, 0 1px 2px rgba(0,0,0,0.05));
    }

    .ps-pill-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 14px;
        border-radius: var(--radius-full, 9999px);
        font-size: var(--font-size-xs, 0.75rem);
        font-weight: 600;
        cursor: pointer;
        border: 1px solid var(--color-border, #e2e6ed);
        background: var(--color-surface-2, #f8f9fb);
        color: var(--color-text-secondary, #5a6480);
        transition: all 0.15s ease;
        text-decoration: none;
    }

    .ps-pill-btn:hover {
        border-color: var(--color-accent, #e8a020);
        color: var(--color-text-primary, #1a2133);
    }

    .ps-pill-btn.active {
        background: var(--color-sidebar, #1a3d2a);
        color: #ffffff;
        border-color: var(--color-sidebar, #1a3d2a);
        box-shadow: 0 2px 4px rgba(26, 61, 42, 0.2);
    }

    /* Grid de KPIs */
    .ps-kpis-grid {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 16px;
        margin-bottom: 20px;
    }
    @media (max-width: 1200px) {
        .ps-kpis-grid {
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        }
    }

    /* Gráficos Cards */
    .ps-chart-card {
        background: var(--color-surface, #ffffff);
        border: 1px solid var(--color-border, #e2e6ed);
        border-radius: var(--radius-lg, 8px);
        padding: 18px 20px;
        box-shadow: var(--shadow-sm, 0 1px 2px rgba(0,0,0,0.05));
        margin-bottom: 20px;
    }

    .ps-card-header {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 14px;
        padding-bottom: 10px;
        border-bottom: 1px solid var(--color-border, #e2e6ed);
    }

    .ps-card-title {
        font-size: var(--font-size-md, 1rem);
        font-weight: 700;
        color: var(--color-text-primary, #1a2133);
    }

    .ps-card-subtitle {
        font-size: var(--font-size-xs, 0.75rem);
        color: var(--color-text-muted, #9aa3b8);
        margin-top: 2px;
    }

    /* ─── Cards de Análise de Gargalos por Setor ──────────────────────────── */
    .gargalos-grid {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 14px;
        margin-bottom: 20px;
    }
    @media (max-width: 1300px) {
        .gargalos-grid {
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        }
    }

    .gargalo-card {
        background: #ffffff;
        border: 1px solid #e2e6ed;
        border-radius: var(--radius-md, 8px);
        padding: 14px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        position: relative;
        transition: all 0.2s ease;
    }
    .gargalo-card.critico {
        border-color: #fca5a5;
        background: #fffafa;
        box-shadow: 0 4px 12px rgba(239, 68, 68, 0.08);
    }
    .gargalo-card.atencao {
        border-color: #fde68a;
        background: #fffdf5;
    }

    .gargalo-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 2px 7px;
        border-radius: 9999px;
        font-size: 10px;
        font-weight: 800;
        letter-spacing: 0.3px;
        text-transform: uppercase;
    }
    .gargalo-badge.critico {
        background: #fee2e2;
        color: #b91c1c;
        border: 1px solid #fecdd3;
    }
    .gargalo-badge.atencao {
        background: #fef3c7;
        color: #92400e;
        border: 1px solid #fde68a;
    }
    .gargalo-badge.normal {
        background: #dcfce7;
        color: #15803d;
        border: 1px solid #bbf7d0;
    }

    .gargalo-metric-row {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        margin: 4px 0;
        font-size: 12px;
    }
    .gargalo-metric-lbl {
        color: var(--color-text-secondary, #5a6480);
        font-size: 11px;
    }
    .gargalo-metric-val {
        font-family: 'JetBrains Mono', monospace;
        font-weight: 700;
        color: var(--color-text-primary, #1a2133);
    }

    .gargalo-prog-bar {
        height: 6px;
        background: #e2e8f0;
        border-radius: 9999px;
        overflow: hidden;
        margin-top: 6px;
    }
    .gargalo-prog-fill {
        height: 100%;
        border-radius: 9999px;
    }

    /* Tabela */
    .ps-table-wrap {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        background: var(--color-surface, #ffffff);
        border: 1px solid var(--color-border, #e2e6ed);
        border-radius: var(--radius-lg, 8px);
        box-shadow: var(--shadow-sm, 0 1px 2px rgba(0,0,0,0.05));
    }

    .ps-data-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
    }

    .ps-data-table th {
        background: var(--color-surface-2, #f8f9fb);
        color: var(--color-text-secondary, #5a6480);
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .4px;
        padding: 10px 14px;
        border-bottom: 1px solid var(--color-border, #e2e6ed);
        text-align: left;
    }

    .ps-data-table td {
        padding: 9px 14px;
        border-bottom: 1px solid var(--color-border, #e2e6ed);
        color: var(--color-text-primary, #1a2133);
        font-size: 12px;
    }

    .ps-data-table tr:hover td {
        background-color: #f8fafc;
    }

    .ps-data-table tr:last-child td {
        border-bottom: none;
    }

    /* ─── Modal de Filtro de Data (Fiel à Imagem) ────────────────────────── */
    .date-filter-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.45);
        backdrop-filter: blur(4px);
        z-index: 9999;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 16px;
    }

    .date-filter-overlay.open {
        display: flex;
    }

    .date-filter-modal {
        background: #ffffff;
        border-radius: 16px;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15), 0 10px 10px -5px rgba(0, 0, 0, 0.08);
        width: 100%;
        max-width: 400px;
        padding: 20px;
        border: 1px solid #e2e8f0;
        animation: dfModalScale 0.18s ease-out;
    }

    @keyframes dfModalScale {
        from { transform: scale(0.95); opacity: 0; }
        to { transform: scale(1); opacity: 1; }
    }

    .df-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 14px;
    }

    .df-title {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.92rem;
        font-weight: 800;
        color: #0f172a;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .df-close-btn {
        background: transparent;
        border: none;
        font-size: 1.25rem;
        line-height: 1;
        color: #94a3b8;
        cursor: pointer;
        padding: 4px;
        border-radius: 6px;
        transition: color 0.15s;
    }
    .df-close-btn:hover { color: #334155; }

    .df-tabs-container {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        background: #f1f5f9;
        padding: 4px;
        border-radius: 10px;
        gap: 4px;
        margin-bottom: 16px;
    }

    .df-tab-btn {
        padding: 8px 10px;
        font-size: 0.84rem;
        font-weight: 600;
        border-radius: 7px;
        border: none;
        background: transparent;
        color: #64748b;
        cursor: pointer;
        transition: all 0.15s ease;
        text-align: center;
    }

    .df-tab-btn.active {
        background: #ffffff;
        color: #dc2626;
        font-weight: 700;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    }

    /* Conteúdo da Tab Hoje */
    .df-hoje-box {
        background: #fef2f2;
        border: 1px solid #fecdd3;
        border-radius: 12px;
        padding: 22px 16px;
        text-align: center;
        margin-bottom: 16px;
    }

    .df-hoje-tag {
        font-size: 0.72rem;
        font-weight: 800;
        color: #991b1b;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        margin-bottom: 6px;
    }

    .df-hoje-date {
        font-family: 'JetBrains Mono', monospace;
        font-size: 1.95rem;
        font-weight: 800;
        color: #dc2626;
        letter-spacing: -0.02em;
        margin-bottom: 8px;
    }

    .df-hoje-desc {
        font-size: 0.78rem;
        color: #64748b;
        line-height: 1.4;
    }

    .df-btn-apply {
        display: block;
        width: 100%;
        background: #dc2626;
        color: #ffffff;
        font-weight: 700;
        font-size: 0.88rem;
        padding: 12px;
        border-radius: 8px;
        border: none;
        cursor: pointer;
        box-shadow: 0 4px 6px -1px rgba(220, 38, 38, 0.25);
        transition: background 0.15s;
    }
    .df-btn-apply:hover {
        background: #b91c1c;
    }

    .df-clear-link {
        display: block;
        text-align: center;
        font-size: 0.78rem;
        font-weight: 600;
        color: #2563eb;
        text-decoration: underline;
        margin-top: 14px;
        cursor: pointer;
    }
    .df-clear-link:hover {
        color: #1d4ed8;
    }

    /* ─── Estilos de Impressão (Folha A4 Paisagem / Retrato) ──────────────── */
    @page {
        size: A4 landscape;
        margin: 8mm 6mm 6mm 6mm;
    }
    @media print {
        body {
            background: #ffffff !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            font-size: 11px !important;
        }
        .sidebar, .navbar, .no-print, .date-filter-overlay, .ps-pills-bar, button, #chipAutoRefresh, input, .app-sidebar, .app-header {
            display: none !important;
        }
        .print-only-header {
            display: block !important;
        }
        .app-main, .main-content, .app-content {
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
        }
        .ps-chart-card, .metric-card, .gargalo-card, .ps-table-wrap {
            break-inside: avoid !important;
            page-break-inside: avoid !important;
            box-shadow: none !important;
            border: 1px solid #cbd5e1 !important;
            margin-bottom: 10px !important;
        }
        canvas {
            max-width: 100% !important;
            height: auto !important;
        }
    }
</style>

<!-- ─── Cabeçalho Formal Exclusivo para Impressão A4 ──────────────────────── -->
<div class="print-only-header" style="display:none;margin-bottom:12px;border-bottom:2px solid #334155;padding-bottom:8px;">
    <div style="display:flex;justify-content:space-between;align-items:center;">
        <div>
            <h2 style="font-size:16px;font-weight:800;color:#0f172a;margin:0;">TRAEL TRANSFORMATORES — PAINEL DE SETOR</h2>
            <p style="font-size:11px;color:#475569;margin:2px 0 0 0;">
                Setor: <?= htmlspecialchars($setorInfo['nome']) ?> (<?= htmlspecialchars($setorInfo['codigo']) ?>) &bull; Mês: <?= htmlspecialchars($dadosPainel['mes']) ?> &bull; Data de Corte: <?= htmlspecialchars(date('d/m/Y', strtotime($dadosPainel['data_corte']))) ?>
            </p>
        </div>
        <div style="text-align:right;font-size:10px;color:#64748b;">
            <div>Emissão: <?= date('d/m/Y H:i:s') ?></div>
            <div>Usuário: <?= htmlspecialchars($usuario['nome'] ?? 'Sistema') ?></div>
        </div>
    </div>
</div>

<!-- ─── 1. Estrutura de Cabeçalho e Filtros (Layout Boletim) ────────────────── -->
<div class="ps-header-row">
    <div>
        <!-- Título Dinâmico -->
        <h1 style="font-size:var(--font-size-xl, 1.35rem);font-weight:700;color:var(--color-text-primary, #1a2133);display:flex;align-items:center;gap:8px;">
            <span>Painel por Setor: <?= htmlspecialchars($setorInfo['nome']) ?></span>
            <span class="badge" style="background:#e0f2fe;color:#0369a1;font-size:0.75rem;padding:2px 8px;border-radius:9999px;font-weight:700;">
                <?= htmlspecialchars($setorInfo['codigo']) ?>
            </span>
        </h1>
        <p class="text-secondary" style="font-size:var(--font-size-sm, 0.875rem);margin-top:2px;">
            <?= htmlspecialchars($setorInfo['descricao']) ?> &bull; Acompanhamento Executivo de Metas & Produção
        </p>
    </div>

    <!-- Chips de Referência / Filtros -->
    <div class="ps-ref-row">
        
        <!-- Chip de Auto-Refresh (30s) -->
        <div class="ps-ref-chip" id="chipAutoRefresh" title="Clique para pausar / retomar a atualização automática" onclick="alternarAutoRefresh()" style="cursor:pointer;user-select:none;">
            <span class="pulse-dot"></span>
            <span class="ps-ref-label">Auto-Refresh</span>
            <span class="ps-ref-value font-mono" id="labelTimerRefresh" style="font-weight:700;color:#16a34a;">30s</span>
        </div>

        <!-- Botão Disparador do Modal de Filtro de Data (Estilo idêntico ao Meta Diária) -->
        <button type="button" class="ps-ref-chip" onclick="abrirFiltroDataModal()" style="cursor:pointer;transition:all 0.15s ease;background:var(--color-surface, #ffffff);border:1px solid var(--color-border, #e2e6ed);color:inherit;font-family:inherit;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--color-text-muted, #9aa3b8);"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <span class="ps-ref-label">Filtro de Data</span>
            <span class="ps-ref-value font-mono">
                <?= $modoData === 'hoje' ? 'Hoje' : ($modoData === 'personalizado' ? 'Intervalo' : date('m/Y', strtotime($dadosPainel['mes'] . '-01'))) ?>
            </span>
        </button>

        <!-- Card Superior de Referência: Meta do Setor -->
        <div class="ps-ref-chip" style="background:var(--color-accent-light, #fef3dc);border-color:#fcd34d;">
            <span class="ps-ref-label" style="color:#7a4f08;">Meta Setor</span>
            <span class="ps-ref-value font-mono" style="color:#7a4f08;font-weight:800;">
                <?= number_format($dadosPainel['meta_setor'], 0, ',', '.') ?> un
            </span>
        </div>

        <div class="ps-ref-chip">
            <span class="ps-ref-label">Meta Diária</span>
            <span class="ps-ref-value font-mono"><?= number_format($dadosPainel['meta_diaria'], 1, ',', '.') ?> un/dia</span>
        </div>

        <!-- Botão Imprimir A4 -->
        <button type="button" class="btn btn-secondary btn-sm" onclick="imprimirPainelA4()" title="Imprimir Relatório em Folha A4" style="display:inline-flex;align-items:center;gap:6px;padding:8px 12px;font-weight:600;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            Imprimir A4
        </button>

        <!-- Botão Atualizar -->
        <button type="button" class="btn btn-secondary btn-sm" onclick="window.location.reload();" style="display:inline-flex;align-items:center;gap:6px;padding:8px 12px;font-weight:600;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
            Atualizar
        </button>
    </div>
</div>

<!-- Barra de Segmentação de Setores (Pills no Estilo Boletim) -->
<div class="ps-pills-bar">
    <span style="font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--color-text-secondary, #5a6480);margin-right:4px;">
        Setor:
    </span>
    <?php foreach ($dadosPainel['setores_disponiveis'] as $chave => $info): 
        $isActive = ($chave === $dadosPainel['setor_chave']);
        $urlSetor = '?mes=' . urlencode($dadosPainel['mes']) . '&setor=' . urlencode($chave) . '&modo_data=' . urlencode($modoData) . ($dataInicio ? '&data_inicio=' . urlencode($dataInicio) : '') . ($dataFim ? '&data_fim=' . urlencode($dataFim) : '');
    ?>
    <a href="<?= htmlspecialchars($urlSetor) ?>" class="ps-pill-btn <?= $isActive ? 'active' : '' ?>">
        <?= htmlspecialchars($info['nome']) ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- ─── 2. Bloco Superior de KPIs (Cards de Métricas & Indicador Central Gauge) ─── -->
<div class="ps-kpis-grid">
    
    <!-- Card 1: Meta de Produção -->
    <div class="metric-card" style="border-left: 4px solid #3b82f6;">
        <div class="metric-label" style="display:flex;align-items:center;gap:6px;">
            <span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:#3b82f6;"></span>
            Meta de Produção
        </div>
        <div class="metric-value"><?= number_format($dadosPainel['meta_acumulada_corte'], 0, ',', '.') ?></div>
        <div class="metric-sub">unidades programadas no período</div>
    </div>

    <!-- Card 2: Produção Total -->
    <div class="metric-card" style="border-left: 4px solid #16a34a;">
        <div class="metric-label" style="display:flex;align-items:center;gap:6px;">
            <span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:#16a34a;"></span>
            Produção Total
        </div>
        <div class="metric-value" style="color:#16a34a;"><?= number_format($dadosPainel['producao_total'], 0, ',', '.') ?></div>
        <div class="metric-sub">unidades realizadas (média <?= number_format($dadosPainel['producao_total'] / max(1, $dadosPainel['dias_trabalhados']), 1, ',', '.') ?> un/dia)</div>
    </div>

    <!-- Indicador Central: Gráfico Circular / Gauge (Eficiência Geral de Produção %) -->
    <div class="metric-card" style="border-left: 4px solid var(--color-accent, #e8a020);text-align:center;padding:12px 14px;display:flex;flex-direction:column;align-items:center;justify-content:center;">
        <div class="metric-label" style="margin-bottom:2px;">Eficiência Geral</div>
        <div style="position:relative;width:95px;height:95px;margin:2px auto;">
            <canvas id="gaugeEficiencia"></canvas>
            <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;">
                <span style="font-family:'JetBrains Mono',monospace;font-size:1.15rem;font-weight:800;color:<?= $dadosPainel['eficiencia_geral'] >= 100 ? '#16a34a' : ($dadosPainel['eficiencia_geral'] >= 85 ? '#d97706' : '#dc2626') ?>;">
                    <?= number_format($dadosPainel['eficiencia_geral'], 1, ',', '.') ?>%
                </span>
            </div>
        </div>
        <div class="metric-sub" style="margin-top:2px;">atingimento da meta acumulada</div>
    </div>

    <!-- Card 3: Acumulado -->
    <div class="metric-card" style="border-left: 4px solid #e8a020;">
        <div class="metric-label" style="display:flex;align-items:center;gap:6px;">
            <span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:#e8a020;"></span>
            Acumulado em Fila
        </div>
        <div class="metric-value"><?= number_format($dadosPainel['acumulado_fila'], 0, ',', '.') ?></div>
        <div class="metric-sub">ordens pendentes no fluxo fabril</div>
    </div>

    <!-- Card 4: Nova Meta -->
    <div class="metric-card" style="border-left: 4px solid #8b5cf6;">
        <div class="metric-label" style="display:flex;align-items:center;gap:6px;">
            <span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:#8b5cf6;"></span>
            Nova Meta / Saldo
        </div>
        <div class="metric-value" style="color:#8b5cf6;"><?= number_format($dadosPainel['nova_meta_diaria'], 1, ',', '.') ?></div>
        <div class="metric-sub">un/dia para os <?= $dadosPainel['dias_restantes'] ?> dias restantes</div>
    </div>

</div>

<!-- ─── 3. Gráficos Principais ───────────────────────────────────────────── -->

<!-- Gráfico 1: Produção vs. Programado por Linha / Setor (Superior) -->
<div class="ps-chart-card">
    <div class="ps-card-header">
        <div>
            <span class="ps-card-title">Produção vs. Programado por Linha de Fabricação</span>
            <p class="ps-card-subtitle">Volume programado versus executado consolidado por célula no período</p>
        </div>
        <div style="display:flex;align-items:center;gap:14px;font-size:12px;font-weight:600;">
            <span style="display:inline-flex;align-items:center;gap:5px;color:var(--color-text-secondary);">
                <span style="width:10px;height:10px;border-radius:2px;background:#3b82f6;"></span> Programado
            </span>
            <span style="display:inline-flex;align-items:center;gap:5px;color:var(--color-text-secondary);">
                <span style="width:10px;height:10px;border-radius:2px;background:#16a34a;"></span> Produzido
            </span>
        </div>
    </div>
    <div style="position:relative;height:260px;width:100%;">
        <canvas id="chartProgVsProd"></canvas>
    </div>
</div>

<!-- Gráfico 2: Histograma de Produção Diária com Linha de Meta (Inferior - Largura Total) -->
<div class="ps-chart-card">
    <div class="ps-card-header">
        <div>
            <span class="ps-card-title">Histograma de Produção Diária com Linha de Meta</span>
            <p class="ps-card-subtitle">
                Acompanhamento diário da fábrica &bull; 
                <span style="color:#16a34a;font-weight:600;">Verde: $\ge$ Meta Diária (<?= $dadosPainel['meta_diaria'] ?> un)</span> &bull; 
                <span style="color:#dc2626;font-weight:600;">Vermelho: $<$ Meta Diária</span>
            </p>
        </div>
        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;font-size:12px;">
            <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 8px;border-radius:4px;background:#dcfce7;color:#15803d;font-weight:600;">
                <span style="width:8px;height:8px;border-radius:50%;background:#16a34a;"></span> $\ge$ Meta
            </span>
            <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 8px;border-radius:4px;background:#fee2e2;color:#b91c1c;font-weight:600;">
                <span style="width:8px;height:8px;border-radius:50%;background:#dc2626;"></span> $<$ Meta
            </span>
            <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 8px;border-radius:4px;background:#fef3c7;color:#92400e;font-weight:600;">
                <span style="width:12px;height:2px;background:#e8a020;"></span> Meta Diária (<?= $dadosPainel['meta_diaria'] ?> un)
            </span>
        </div>
    </div>
    <div style="position:relative;height:270px;width:100%;">
        <canvas id="chartHistogramaDiario"></canvas>
    </div>
</div>

<!-- ─── 4. Acompanhamento de Produção: Em Aberto no Setor × Programado PCP ── -->
<div class="ps-chart-card" style="border-top: 3px solid #f59e0b;">
    <div class="ps-card-header">
        <div>
            <div style="display:flex;align-items:center;gap:8px;">
                <span class="ps-card-title" style="display:flex;align-items:center;gap:6px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2.5"><path d="M12 20V10M18 20V4M6 20v-4"/></svg>
                    Acompanhamento de Produção: Em Aberto no Setor × Programado PCP
                </span>
                <span class="badge" style="background:#fef3c7;color:#92400e;font-size:11px;font-weight:700;padding:2px 8px;">
                    Fila Total: <?= (int) $graficoAcompanhamento['total_aberto'] ?> Itens
                </span>
            </div>
            <p class="ps-card-subtitle">
                Rastreabilidade da esteira: Pintar Tanque (<?= (int) ($graficoAcompanhamento['contagem']['PINTAR TANQUE'] ?? 0) ?>) &bull; Guardar na Estufa (<?= (int) ($graficoAcompanhamento['contagem']['GUARDAR NA ESTUFA'] ?? 0) ?>) &bull; Descer Mont. Final (<?= (int) ($graficoAcompanhamento['contagem']['DESCER PARA MONTAGEM FINAL'] ?? 0) ?>) &bull; Verif. Apontamento (<?= (int) ($graficoAcompanhamento['contagem']['VERIFICAR APONTAMENTO'] ?? 0) ?>)
            </p>
        </div>
        <div style="display:flex;align-items:center;gap:14px;font-size:12px;font-weight:600;">
            <span style="display:inline-flex;align-items:center;gap:5px;color:var(--color-text-secondary);">
                <span style="width:10px;height:10px;border-radius:2px;background:#f59e0b;"></span> Em Aberto no Setor
            </span>
            <span style="display:inline-flex;align-items:center;gap:5px;color:var(--color-text-secondary);">
                <span style="width:10px;height:10px;border-radius:2px;background:#3b82f6;"></span> Programado PCP (Dia)
            </span>
        </div>
    </div>

    <div style="position:relative;height:280px;width:100%;">
        <canvas id="chartAcompanhamentoProg"></canvas>
    </div>
</div>

<!-- ─── 5. Painel de Análise de Gargalos e Atrasos por Setor ──────────────── -->
<div class="ps-chart-card" style="border-top: 3px solid #ef4444;">
    <div class="ps-card-header">
        <div>
            <div style="display:flex;align-items:center;gap:8px;">
                <span class="ps-card-title" style="display:flex;align-items:center;gap:6px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    Análise de Gargalos e Atrasos por Setor
                </span>
                <?php if ($analiseGargalos['gargalo_principal']['is_gargalo']): ?>
                <span class="gargalo-badge critico" style="font-size:11px;padding:3px 10px;">
                    🔴 Gargalo Principal: <?= htmlspecialchars($analiseGargalos['gargalo_principal']['nome']) ?>
                </span>
                <?php else: ?>
                <span class="gargalo-badge normal" style="font-size:11px;padding:3px 10px;">
                    🟢 Fluxo Fabril Conforme / Sem Gargalo Crítico
                </span>
                <?php endif; ?>
            </div>
            <p class="ps-card-subtitle">
                Identificação em tempo real de desvios de prazo, taxa de não conformidade e tempo médio de espera (Lead Time) por célula.
            </p>
        </div>
        <div style="font-size:12px;color:var(--color-text-muted);">
            Tolerância: $\le 25\%$ Não Conformidade &bull; $\le 5$ dias Lead Time
        </div>
    </div>

    <!-- Grid de Métricas por Setor -->
    <div class="gargalos-grid">
        <?php foreach ($analiseGargalos['setores'] as $chave => $st): 
            $statusClass = strtolower($st['status']);
        ?>
        <div class="gargalo-card <?= $statusClass ?>">
            <div>
                <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:6px;margin-bottom:8px;">
                    <div>
                        <div style="font-weight:700;font-size:12.5px;color:var(--color-text-primary);"><?= htmlspecialchars($st['nome']) ?></div>
                        <span class="badge" style="font-size:10px;padding:1px 5px;background:#f1f5f9;color:#475569;"><?= htmlspecialchars($st['codigo']) ?></span>
                    </div>
                    <span class="gargalo-badge <?= $statusClass ?>">
                        <?php 
                        if ($st['status'] === 'CRITICO') echo '🔴 GARGALO';
                        elseif ($st['status'] === 'ATENCAO') echo '🟡 ATENÇÃO';
                        else echo '🟢 OK';
                        ?>
                    </span>
                </div>

                <div class="gargalo-metric-row">
                    <span class="gargalo-metric-lbl">Ordens em Atraso:</span>
                    <span class="gargalo-metric-val" style="color:<?= $st['volume_pecas_atraso'] > 0 ? '#b91c1c' : '#15803d' ?>;">
                        <?= number_format($st['volume_pecas_atraso'], 0, ',', '.') ?> un
                    </span>
                </div>

                <div class="gargalo-metric-row">
                    <span class="gargalo-metric-lbl">% Não Conformidade:</span>
                    <span class="gargalo-metric-val" style="color:<?= $st['nao_conformidade_pct'] >= $st['tolerancia_pct'] ? '#dc2626' : ($st['nao_conformidade_pct'] >= 15 ? '#d97706' : '#16a34a') ?>;">
                        <?= number_format($st['nao_conformidade_pct'], 1, ',', '.') ?>%
                    </span>
                </div>

                <div class="gargalo-metric-row">
                    <span class="gargalo-metric-lbl">Tempo Médio (Lead Time):</span>
                    <span class="gargalo-metric-val"><?= number_format($st['lead_time_medio'], 1, ',', '.') ?> dias</span>
                </div>
            </div>

            <!-- Barra de Progresso de Não Conformidade vs Tolerância -->
            <div style="margin-top:10px;">
                <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--color-text-muted);margin-bottom:2px;">
                    <span>Tolerância: <?= (int) $st['tolerancia_pct'] ?>%</span>
                    <span><?= number_format($st['nao_conformidade_pct'], 0) ?>%</span>
                </div>
                <div class="gargalo-prog-bar">
                    <div class="gargalo-prog-fill" style="width:<?= min(100, $st['nao_conformidade_pct']) ?>%;background:<?= $st['status'] === 'CRITICO' ? '#ef4444' : ($st['status'] === 'ATENCAO' ? '#f59e0b' : '#10b981') ?>;"></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Ranking de Criticidade: Gráfico de Barras Horizontais -->
    <div style="border-top:1px solid var(--color-border);padding-top:16px;margin-top:6px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
            <div>
                <span style="font-size:0.92rem;font-weight:700;color:var(--color-text-primary);">
                    Ranking de Criticidade por Setor (Maior &rarr; Menor Tempo Médio de Atraso)
                </span>
                <p style="font-size:0.75rem;color:var(--color-text-muted);margin-top:2px;">
                    Tempo médio de espera (Lead Time em dias) e volume de ordens com desvio de prazo por célula
                </p>
            </div>
            <div style="font-size:11px;font-weight:600;color:#64748b;">
                Tolerância Máxima: <span style="color:#ef4444;font-weight:700;">5 dias</span>
            </div>
        </div>
        <div style="position:relative;height:240px;width:100%;">
            <canvas id="chartRankingCriticidade"></canvas>
        </div>
    </div>

</div>

<!-- ─── 6. Tabela Analítica de Ordens e Acompanhamento do Setor ─────────── -->
<div class="ps-table-wrap">
    <div style="padding:14px 18px;border-bottom:1px solid var(--color-border);display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;">
        <div>
            <span style="font-size:0.95rem;font-weight:700;color:var(--color-text-primary);">Ordens em Andamento / Fila do Setor</span>
            <p style="font-size:0.75rem;color:var(--color-text-muted);margin-top:2px;">Detalhamento das ordens de fabricação associadas à área</p>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <input type="text" id="tabelaBuscaSetor" placeholder="Buscar pedido, cliente, projeto..." 
                   style="border:1px solid var(--color-border);border-radius:var(--radius-sm);padding:5px 10px;font-size:12px;width:240px;outline:none;" 
                   oninput="filtrarTabelaSetor()">
            <button type="button" class="btn btn-secondary btn-sm" onclick="exportarTabelaSetorCSV()" style="display:inline-flex;align-items:center;gap:5px;">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Exportar CSV
            </button>
        </div>
    </div>

    <table class="ps-data-table" id="tabelaOrdensSetor">
        <thead>
            <tr>
                <th style="width: 80px;">Pedido</th>
                <th>Cliente</th>
                <th style="width: 110px;">Projeto</th>
                <th>Descrição do Transformador</th>
                <th style="width: 80px; text-align: right;">Potência</th>
                <th style="width: 100px; text-align: center;">Linha</th>
                <th style="width: 95px; text-align: center;">Data Prev.</th>
                <th style="width: 65px; text-align: right;">Qtd</th>
            </tr>
        </thead>
        <tbody id="tabelaCorpoSetor">
            <?php foreach ($dadosPainel['ordens_detalhes'] as $it): ?>
            <tr data-texto="<?= htmlspecialchars(strtolower($it['pedido'] . ' ' . $it['cliente'] . ' ' . $it['referencia'] . ' ' . $it['descricao'])) ?>">
                <td class="font-mono" style="font-weight:600;"><?= htmlspecialchars($it['pedido']) ?></td>
                <td style="font-weight:500;"><?= htmlspecialchars($it['cliente_apelido'] ?: $it['cliente']) ?></td>
                <td class="font-mono"><?= htmlspecialchars($it['referencia']) ?></td>
                <td style="color:var(--color-text-secondary);" title="<?= htmlspecialchars($it['descricao']) ?>">
                    <?= htmlspecialchars(strlen($it['descricao']) > 48 ? substr($it['descricao'], 0, 45) . '...' : $it['descricao']) ?>
                </td>
                <td class="font-mono" style="text-align:right;"><?= htmlspecialchars($it['potencia_str'] ?: ($it['potencia_kva'] . ' kVA')) ?></td>
                <td style="text-align:center;">
                    <span style="display:inline-block;padding:2px 7px;border-radius:4px;font-size:11px;font-weight:700;background:#e0f2fe;color:#0284c7;">
                        <?= htmlspecialchars($it['linha']) ?>
                    </span>
                </td>
                <td class="font-mono" style="text-align:center;font-size:11px;color:var(--color-text-secondary);">
                    <?= date('d/m/Y', strtotime($it['data_programada'])) ?>
                </td>
                <td class="font-mono" style="text-align:right;font-weight:700;">
                    <?= number_format($it['quantidade'], 0, ',', '.') ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div style="padding:10px 18px;border-top:1px solid var(--color-border);display:flex;justify-content:space-between;align-items:center;font-size:11px;color:var(--color-text-muted);">
        <span id="tabelaContadorSetor">Exibindo <?= count($dadosPainel['ordens_detalhes']) ?> ordens</span>
        <span>Sincronizado com Kardex e Plano Mestre</span>
    </div>
</div>

<!-- ─── MODAL: FILTRO DE DATA (Idêntico à Referência do Usuário) ─────────── -->
<div class="date-filter-overlay" id="dateFilterModal" onclick="if(event.target === this) fecharFiltroDataModal();">
    <div class="date-filter-modal">
        
        <!-- Header do Modal -->
        <div class="df-header">
            <div class="df-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <span>FILTRO DE DATA</span>
            </div>
            <button type="button" class="df-close-btn" onclick="fecharFiltroDataModal()" aria-label="Fechar">&times;</button>
        </div>

        <!-- Segmented Tabs: Hoje | Mês | Personalizável -->
        <div class="df-tabs-container">
            <button type="button" class="df-tab-btn <?= $modoData === 'hoje' ? 'active' : '' ?>" id="tabBtnHoje" onclick="trocarTabFiltro('hoje')">
                Hoje
            </button>
            <button type="button" class="df-tab-btn <?= $modoData === 'mes' ? 'active' : '' ?>" id="tabBtnMes" onclick="trocarTabFiltro('mes')">
                Mês
            </button>
            <button type="button" class="df-tab-btn <?= $modoData === 'personalizado' ? 'active' : '' ?>" id="tabBtnPersonalizado" onclick="trocarTabFiltro('personalizado')">
                Personalizável
            </button>
        </div>

        <!-- FORM: TAB 1 - HOJE -->
        <form method="GET" id="formFiltroHoje" style="display: <?= $modoData === 'hoje' ? 'block' : 'none' ?>;">
            <input type="hidden" name="setor" value="<?= htmlspecialchars($dadosPainel['setor_chave']) ?>">
            <input type="hidden" name="modo_data" value="hoje">

            <div class="df-hoje-box">
                <div class="df-hoje-tag">DATA DE HOJE</div>
                <div class="df-hoje-date"><?= date('d/m/Y') ?></div>
                <div class="df-hoje-desc">
                    Filtra os apontamentos registrados exclusivamente na data de hoje.
                </div>
            </div>

            <button type="submit" class="df-btn-apply">
                Aplicar Data de Hoje
            </button>
        </form>

        <!-- FORM: TAB 2 - MÊS -->
        <form method="GET" id="formFiltroMes" style="display: <?= $modoData === 'mes' ? 'block' : 'none' ?>;">
            <input type="hidden" name="setor" value="<?= htmlspecialchars($dadosPainel['setor_chave']) ?>">
            <input type="hidden" name="modo_data" value="mes">

            <div class="df-hoje-box" style="background:#f8fafc;border-color:#e2e6ed;">
                <div class="df-hoje-tag" style="color:#475569;">MÊS DE REFERÊNCIA</div>
                <div style="margin: 12px 0;">
                    <input type="month" name="mes" value="<?= htmlspecialchars($dadosPainel['mes']) ?>" 
                           style="border:1px solid #cbd5e1;border-radius:8px;padding:8px 12px;font-size:16px;font-weight:700;color:#0f172a;background:#ffffff;outline:none;">
                </div>
                <div class="df-hoje-desc">
                    Filtra todos os apontamentos e metas do mês selecionado.
                </div>
            </div>

            <button type="submit" class="df-btn-apply">
                Aplicar Mês
            </button>
        </form>

        <!-- FORM: TAB 3 - PERSONALIZÁVEL -->
        <form method="GET" id="formFiltroPersonalizado" style="display: <?= $modoData === 'personalizado' ? 'block' : 'none' ?>;">
            <input type="hidden" name="setor" value="<?= htmlspecialchars($dadosPainel['setor_chave']) ?>">
            <input type="hidden" name="modo_data" value="personalizado">

            <div class="df-hoje-box" style="background:#f8fafc;border-color:#e2e6ed;text-align:left;">
                <div class="df-hoje-tag" style="color:#475569;text-align:center;">INTERVALO PERSONALIZADO</div>
                
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:12px 0;">
                    <div>
                        <label style="display:block;font-size:11px;font-weight:700;color:#64748b;margin-bottom:4px;">De:</label>
                        <input type="date" name="data_inicio" value="<?= htmlspecialchars($dadosPainel['data_inicio']) ?>" 
                               style="width:100%;border:1px solid #cbd5e1;border-radius:6px;padding:6px 8px;font-size:13px;font-weight:600;background:#ffffff;outline:none;">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;font-weight:700;color:#64748b;margin-bottom:4px;">Até:</label>
                        <input type="date" name="data_fim" value="<?= htmlspecialchars($dadosPainel['data_fim']) ?>" 
                               style="width:100%;border:1px solid #cbd5e1;border-radius:6px;padding:6px 8px;font-size:13px;font-weight:600;background:#ffffff;outline:none;">
                    </div>
                </div>

                <div class="df-hoje-desc" style="text-align:center;">
                    Filtra os apontamentos dentro do intervalo de datas especificado.
                </div>
            </div>

            <button type="submit" class="df-btn-apply">
                Aplicar Intervalo
            </button>
        </form>

        <!-- Link para Limpar Filtro -->
        <a href="?setor=<?= urlencode($dadosPainel['setor_chave']) ?>" class="df-clear-link">
            Ver Todas as Datas (Limpar filtro de data)
        </a>

    </div>
</div>

<!-- Scripts de Gráficos (Chart.js + Plugin DataLabels) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0"></script>

<script>
// ─── Atualização Automática a cada 30 Segundos ──────────────────────────────
let autoRefreshSegundos = 30;
let autoRefreshPausado = false;

const intervalAutoRefresh = setInterval(() => {
    // Se o modal de filtro estiver aberto ou o usuário digitando, não interrompe
    const modalAberto = document.getElementById('dateFilterModal')?.classList.contains('open');
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
            lbl.style.color = '#16a34a';
        }
        if (dot) dot.style.background = '#16a34a';
    }
}

// ─── Impressão Formal A4 ────────────────────────────────────────────────────
function imprimirPainelA4() {
    window.print();
}

// ─── Modal de Filtro de Data ────────────────────────────────────────────────
function abrirFiltroDataModal() {
    document.getElementById('dateFilterModal')?.classList.add('open');
}

function fecharFiltroDataModal() {
    document.getElementById('dateFilterModal')?.classList.remove('open');
}

function trocarTabFiltro(modo) {
    // Tabs
    document.getElementById('tabBtnHoje')?.classList.toggle('active', modo === 'hoje');
    document.getElementById('tabBtnMes')?.classList.toggle('active', modo === 'mes');
    document.getElementById('tabBtnPersonalizado')?.classList.toggle('active', modo === 'personalizado');

    // Forms
    const formHoje = document.getElementById('formFiltroHoje');
    const formMes = document.getElementById('formFiltroMes');
    const formPers = document.getElementById('formFiltroPersonalizado');

    if (formHoje) formHoje.style.display = (modo === 'hoje') ? 'block' : 'none';
    if (formMes) formMes.style.display = (modo === 'mes') ? 'block' : 'none';
    if (formPers) formPers.style.display = (modo === 'personalizado') ? 'block' : 'none';
}

// ─── 1. Gauge / Donut de Eficiência Geral (%) ────────────────────────────────
const ctxGauge = document.getElementById('gaugeEficiencia')?.getContext('2d');
if (ctxGauge) {
    const efPerc = <?= json_encode((float) $dadosPainel['eficiencia_geral']) ?>;
    const corEf = efPerc >= 100 ? '#16a34a' : (efPerc >= 85 ? '#d97706' : '#dc2626');
    const valorRestante = Math.max(0, 100 - Math.min(100, efPerc));

    new Chart(ctxGauge, {
        type: 'doughnut',
        data: {
            datasets: [{
                data: [Math.min(100, efPerc), valorRestante],
                backgroundColor: [corEf, '#e2e6ed'],
                borderWidth: 0,
                circumference: 270,
                rotation: 225,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '78%',
            plugins: {
                legend: { display: false },
                tooltip: { enabled: false },
                datalabels: { display: false }
            }
        }
    });
}

// ─── 2. Gráfico de Colunas: Produção vs. Programado por Linha ────────────────
const ctxLinhas = document.getElementById('chartProgVsProd')?.getContext('2d');
if (ctxLinhas) {
    const dadosLinhas = <?= json_encode($dadosPainel['grafico_linhas'], JSON_UNESCAPED_UNICODE) ?>;

    new Chart(ctxLinhas, {
        type: 'bar',
        data: {
            labels: dadosLinhas.labels,
            datasets: [
                {
                    label: 'Programado',
                    data: dadosLinhas.programado,
                    backgroundColor: '#3b82f6',
                    hoverBackgroundColor: '#2563eb',
                    borderRadius: 4,
                    barPercentage: 0.65,
                    categoryPercentage: 0.55,
                },
                {
                    label: 'Produzido',
                    data: dadosLinhas.produzido,
                    backgroundColor: '#16a34a',
                    hoverBackgroundColor: '#15803d',
                    borderRadius: 4,
                    barPercentage: 0.65,
                    categoryPercentage: 0.55,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: { padding: { top: 25 } },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1a2133',
                    titleColor: '#fff',
                    borderColor: '#e2e6ed',
                    borderWidth: 1,
                    callbacks: {
                        label: function(ctx) {
                            return ' ' + ctx.dataset.label + ': ' + ctx.raw.toLocaleString('pt-BR') + ' un';
                        }
                    }
                },
                datalabels: {
                    anchor: 'end',
                    align: 'top',
                    offset: 2,
                    color: '#1a2133',
                    font: {
                        family: 'JetBrains Mono, monospace',
                        size: 11,
                        weight: 'bold'
                    },
                    formatter: function(val) {
                        return val > 0 ? val.toLocaleString('pt-BR') : '';
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: {
                        color: '#1a2133',
                        font: { family: 'Inter', size: 12, weight: '600' }
                    },
                    border: { color: '#e2e6ed' }
                },
                y: {
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)',
                        borderDash: [3, 3]
                    },
                    ticks: { color: '#5a6480', font: { size: 11 } },
                    border: { display: false }
                }
            }
        },
        plugins: [ChartDataLabels]
    });
}

// ─── 3. Histograma Diário com Linha de Meta e Cores Condicionais ────────────
const ctxHist = document.getElementById('chartHistogramaDiario')?.getContext('2d');
if (ctxHist) {
    const dadosHist = <?= json_encode($dadosPainel['histograma_diario'], JSON_UNESCAPED_UNICODE) ?>;
    const labelsHist = dadosHist.map(d => d.label);
    const totaisHist = dadosHist.map(d => d.total);
    const coresBarras = dadosHist.map(d => d.cor_barra);
    const metaLinha = dadosHist.map(d => d.meta);

    new Chart(ctxHist, {
        type: 'bar',
        data: {
            labels: labelsHist,
            datasets: [
                {
                    type: 'line',
                    label: 'Meta Diária',
                    data: metaLinha,
                    borderColor: '#e8a020',
                    borderWidth: 2.5,
                    borderDash: [5, 4],
                    pointRadius: 0,
                    fill: false,
                    order: 1,
                    datalabels: { display: false }
                },
                {
                    type: 'bar',
                    label: 'Produção Diária',
                    data: totaisHist,
                    backgroundColor: coresBarras,
                    borderRadius: 4,
                    barPercentage: 0.65,
                    order: 2,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: { padding: { top: 25, right: 10, left: 10 } },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1a2133',
                    titleColor: '#fff',
                    borderColor: '#e2e6ed',
                    borderWidth: 1,
                    callbacks: {
                        label: function(ctx) {
                            if (ctx.dataset.type === 'line') {
                                return ' Meta Diária: ' + ctx.raw.toLocaleString('pt-BR') + ' un';
                            }
                            const status = ctx.raw >= dadosHist[ctx.dataIndex].meta ? 'Atingiu a Meta' : 'Abaixo da Meta';
                            return ' Produção: ' + ctx.raw.toLocaleString('pt-BR') + ' un (' + status + ')';
                        }
                    }
                },
                datalabels: {
                    anchor: 'end',
                    align: 'top',
                    offset: 2,
                    color: function(ctx) {
                        return ctx.dataset.type === 'line' ? 'transparent' : '#1a2133';
                    },
                    font: {
                        family: 'JetBrains Mono, monospace',
                        size: 10,
                        weight: 'bold'
                    },
                    formatter: function(val, ctx) {
                        if (ctx.dataset.type === 'line') return '';
                        return val > 0 ? val.toLocaleString('pt-BR') : '';
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: {
                        color: '#5a6480',
                        font: { size: 10 },
                        maxRotation: 45
                    },
                    border: { color: '#e2e6ed' }
                },
                y: {
                    min: 0,
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)',
                        borderDash: [3, 3]
                    },
                    ticks: { color: '#5a6480', font: { size: 11 } },
                    border: { display: false }
                }
            }
        },
        plugins: [ChartDataLabels]
    });
}

// ─── 4. Acompanhamento: Em Aberto no Setor × Programado PCP ────────────────
const ctxAcomp = document.getElementById('chartAcompanhamentoProg')?.getContext('2d');
if (ctxAcomp) {
    const dadosAcomp = <?= json_encode($graficoAcompanhamento, JSON_UNESCAPED_UNICODE) ?>;

    new Chart(ctxAcomp, {
        type: 'bar',
        data: {
            labels: dadosAcomp.labels,
            datasets: [
                {
                    label: 'Em Aberto no Setor',
                    data: dadosAcomp.aberto,
                    backgroundColor: '#f59e0b',
                    hoverBackgroundColor: '#d97706',
                    borderRadius: 4,
                    barPercentage: 0.65,
                    categoryPercentage: 0.55,
                },
                {
                    label: 'Programado PCP (Dia)',
                    data: dadosAcomp.programado,
                    backgroundColor: '#3b82f6',
                    hoverBackgroundColor: '#2563eb',
                    borderRadius: 4,
                    barPercentage: 0.65,
                    categoryPercentage: 0.55,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: { padding: { top: 25 } },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1a2133',
                    titleColor: '#fff',
                    borderColor: '#e2e6ed',
                    borderWidth: 1,
                    callbacks: {
                        label: function(ctx) {
                            return ' ' + ctx.dataset.label + ': ' + ctx.raw.toLocaleString('pt-BR') + ' un';
                        }
                    }
                },
                datalabels: {
                    anchor: 'end',
                    align: 'top',
                    offset: 2,
                    color: '#1a2133',
                    font: {
                        family: 'JetBrains Mono, monospace',
                        size: 11,
                        weight: 'bold'
                    },
                    formatter: function(val) {
                        return val > 0 ? val.toLocaleString('pt-BR') : '0';
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: {
                        color: '#1a2133',
                        font: { family: 'Inter', size: 11.5, weight: '600' }
                    },
                    border: { color: '#e2e6ed' }
                },
                y: {
                    min: 0,
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)',
                        borderDash: [3, 3]
                    },
                    ticks: { color: '#5a6480', font: { size: 11 } },
                    border: { display: false }
                }
            }
        },
        plugins: [ChartDataLabels]
    });
}

// ─── 5. Gráfico do Ranking de Criticidade por Setor (Horizontal Bar) ────────
const ctxRanking = document.getElementById('chartRankingCriticidade')?.getContext('2d');
if (ctxRanking) {
    const dadosRanking = <?= json_encode($analiseGargalos['chart_ranking'], JSON_UNESCAPED_UNICODE) ?>;
    const maxDias = Math.max(...dadosRanking.lead_times, 10);
    const limiteEixoX = Math.ceil((maxDias * 1.35) / 5) * 5;

    new Chart(ctxRanking, {
        type: 'bar',
        data: {
            labels: dadosRanking.labels,
            datasets: [{
                axis: 'y',
                label: 'Tempo Médio de Espera (Dias)',
                data: dadosRanking.lead_times,
                backgroundColor: dadosRanking.cores,
                hoverBackgroundColor: dadosRanking.cores,
                borderRadius: 5,
                barPercentage: 0.65,
                categoryPercentage: 0.75,
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            layout: { padding: { right: 140, left: 10, top: 10, bottom: 5 } },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1a2133',
                    titleColor: '#fff',
                    borderColor: '#e2e6ed',
                    borderWidth: 1,
                    padding: 10,
                    callbacks: {
                        label: function(ctx) {
                            const idx = ctx.dataIndex;
                            const lt = ctx.raw;
                            const vol = dadosRanking.volume_atraso[idx] || 0;
                            const nc = dadosRanking.nao_conformidade[idx] || 0;
                            return [
                                ' Tempo Médio: ' + lt.toLocaleString('pt-BR', { minimumFractionDigits: 1 }) + ' dias',
                                ' Volume em Atraso: ' + vol.toLocaleString('pt-BR') + ' un',
                                ' % Não Conformidade: ' + nc + '%'
                            ];
                        }
                    }
                },
                datalabels: {
                    anchor: 'end',
                    align: 'right',
                    offset: 8,
                    color: '#1a2133',
                    font: {
                        family: 'JetBrains Mono, monospace',
                        size: 11.5,
                        weight: 'bold'
                    },
                    formatter: function(val, ctx) {
                        const idx = ctx.dataIndex;
                        const vol = dadosRanking.volume_atraso[idx] || 0;
                        const diasStr = val.toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
                        return diasStr + ' dias (' + vol + ' un)';
                    }
                }
            },
            scales: {
                x: {
                    min: 0,
                    max: limiteEixoX,
                    grid: {
                        color: 'rgba(0, 0, 0, 0.06)',
                        borderDash: [3, 3]
                    },
                    ticks: {
                        color: '#5a6480',
                        font: { size: 11, family: 'Inter' },
                        callback: function(v) { return v + ' dias'; }
                    },
                    border: { display: false }
                },
                y: {
                    grid: { display: false },
                    ticks: {
                        color: '#1a2133',
                        font: { family: 'Inter', size: 12, weight: '700' }
                    },
                    border: { color: '#e2e6ed' }
                }
            }
        },
        plugins: [ChartDataLabels]
    });
}

// ─── Filtro na Tabela de Ordens do Setor ─────────────────────────────────────
function filtrarTabelaSetor() {
    const busca = (document.getElementById('tabelaBuscaSetor')?.value || '').toLowerCase().trim();
    const linhas = document.querySelectorAll('#tabelaCorpoSetor tr');
    let visiveis = 0;

    linhas.forEach(tr => {
        const texto = tr.getAttribute('data-texto') || '';
        if (!busca || texto.includes(busca)) {
            tr.style.display = '';
            visiveis++;
        } else {
            tr.style.display = 'none';
        }
    });

    const contador = document.getElementById('tabelaContadorSetor');
    if (contador) {
        contador.textContent = 'Exibindo ' + visiveis + ' ordens';
    }
}

// ─── Exportar Tabela para CSV ────────────────────────────────────────────────
function exportarTabelaSetorCSV() {
    const linhas = document.querySelectorAll('#tabelaOrdensSetor tr');
    if (!linhas.length) return;

    let csvContent = '\uFEFF';
    linhas.forEach((tr, i) => {
        if (tr.style.display === 'none') return;
        const cols = tr.querySelectorAll(i === 0 ? 'th' : 'td');
        const rowData = [];
        cols.forEach(td => {
            let txt = td.innerText.replace(/"/g, '""').trim();
            rowData.push('"' + txt + '"');
        });
        csvContent += rowData.join(';') + '\r\n';
    });

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'painel_setor_' + new Date().toISOString().slice(0, 10) + '.csv';
    link.click();
}
</script>

<?php layoutFooter(); ?>
