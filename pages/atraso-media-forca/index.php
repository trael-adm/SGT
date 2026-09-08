<?php
declare(strict_types=1);

/**
 * Dashboard de Atraso - Fábrica de Média Força (Trael).
 *
 * Exibe métricas de backlog e capacidade diária por linha (TPD ≤ 300 kVA, TPM > 300 kVA e TPS Seco),
 * distribuição mensal e semanal com drilldown, série temporal de 15 dias e relação analítica
 * com rastreabilidade de números de série no chão de fábrica.
 */

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/boletim-atraso-forca.php';

requireLogin();

$usuario = currentUser();
$base    = defined('APP_URL') ? APP_URL : '';

// ─── Parâmetros de Filtro (Base de Dados MySQL) ─────────────────────────────
$dataCorte     = isset($_GET['data_corte']) ? trim((string)$_GET['data_corte']) : '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataCorte)) {
    $dataCorte = date('Y-m-d');
}

$dataExtracao  = isset($_GET['data_extracao']) ? trim((string)$_GET['data_extracao']) : null;
$mesesFiltroRaw = isset($_GET['meses']) ? trim((string)$_GET['meses']) : '';
$mesesFiltro   = $mesesFiltroRaw !== '' ? array_filter(explode(',', $mesesFiltroRaw)) : [];
$forcarRefresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';

// Se solicitado refresh, força sobrescrita do snapshot do dia
if ($forcarRefresh) {
    boletimExtrairSnapshotAtrasoForca($dataExtracao ?: $dataCorte, true);
}

// ─── Carrega Métricas Consolidadas via Banco de Dados ───────────────────────
$metricas = [];
$erroMsg = '';

try {
    $metricas = boletimCalcularMetricasAtrasoForca($dataCorte, $dataExtracao, $mesesFiltro);
    if (!$metricas['sucesso']) {
        $erroMsg = $metricas['erro'] ?? 'Erro desconhecido ao carregar indicadores de Média Força.';
    }
} catch (Exception $e) {
    $erroMsg = $e->getMessage();
}

layoutHeader('Atraso Média Força');
?>

<style>
/* ─── Estilização do Painel de Atraso Média Força ─── */
.atraso-dashboard {
    --atraso-bg: #13161f;
    --atraso-card-bg: #1a1e29;
    --atraso-card-hover: #212634;
    --atraso-card-border: #262c3d;
    --atraso-red: #e63946;
    --atraso-red-light: #ff6b6b;
    --atraso-red-glow: rgba(230, 57, 70, 0.25);
    --atraso-text-main: #f8fafc;
    --atraso-text-muted: #94a3b8;
    --atraso-text-dim: #64748b;
    
    background-color: var(--atraso-bg);
    color: var(--atraso-text-main);
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 30px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
    font-family: 'Inter', sans-serif;
}

.atraso-card {
    background-color: var(--atraso-card-bg);
    border: 1px solid var(--atraso-card-border);
    border-radius: 10px;
    padding: 18px 20px;
    transition: all 0.2s ease-in-out;
}
.atraso-card:hover {
    border-color: rgba(230, 57, 70, 0.4);
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
}

.atraso-val-big {
    font-family: 'JetBrains Mono', monospace;
    font-size: 2.75rem;
    font-weight: 700;
    line-height: 1.1;
    color: var(--atraso-red);
    text-shadow: 0 0 16px var(--atraso-red-glow);
    letter-spacing: -0.03em;
}

.atraso-val-medium {
    font-family: 'JetBrains Mono', monospace;
    font-size: 2.25rem;
    font-weight: 700;
    line-height: 1.1;
    color: var(--atraso-red);
    letter-spacing: -0.02em;
}

.atraso-lbl {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--atraso-text-muted);
    margin-top: 6px;
}

.atraso-sub {
    font-size: 0.72rem;
    color: var(--atraso-text-dim);
    margin-top: 2px;
}

.atraso-hero-date {
    font-family: 'Inter', sans-serif;
    font-size: 1.95rem;
    font-weight: 800;
    line-height: 1.15;
    color: #ffffff;
    letter-spacing: -0.02em;
}

/* ─── Filtro Tabs / Switcher de Módulos ─── */
.modulo-tab-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 7px 14px;
    border-radius: 8px;
    font-size: 0.8rem;
    font-weight: 600;
    transition: all 0.15s ease;
    text-decoration: none;
}
.modulo-tab-btn.active {
    background: #e63946;
    color: #ffffff;
    box-shadow: 0 4px 12px rgba(230, 57, 70, 0.35);
}
.modulo-tab-btn:not(.active) {
    background: #11141e;
    color: #94a3b8;
    border: 1px solid #262c3d;
}
.modulo-tab-btn:not(.active):hover {
    background: #1a202c;
    color: #ffffff;
    border-color: #3b4252;
}

/* ─── Badges / Pills de Mês (Padrão Indicador de Distribuição) ─── */
.atraso-pill-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 14px;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s ease;
    border: 1px solid #5a1e24;
    background-color: #0f1219;
    color: #ff9999;
}

.atraso-pill-btn.active {
    background-color: rgba(230, 57, 70, 0.28);
    border-color: #e63946;
    color: #ffffff;
    box-shadow: 0 0 10px rgba(230, 57, 70, 0.35);
}

.atraso-pill-btn:hover {
    border-color: #ff6b6b;
    color: #ffffff;
}

/* ─── Tabela Analítica ─── */
.atraso-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 0.84rem;
}

.atraso-table thead {
    position: sticky;
    top: 0;
    z-index: 20;
}

.atraso-table th {
    position: sticky;
    top: 0;
    z-index: 20;
    background: #0d1017;
    color: #94a3b8;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.72rem;
    letter-spacing: 0.05em;
    padding: 12px 14px;
    border-bottom: 2px solid #262c3d;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.5);
    text-align: left;
}

.atraso-table td {
    padding: 10px 14px;
    border-bottom: 1px solid #1e2433;
    color: #e2e8f0;
}

.atraso-table tr:hover td {
    background-color: #222736;
}

.atraso-table tr:last-child td {
    border-bottom: none;
}

.badge-linha-tpd {
    background-color: rgba(56, 189, 248, 0.12);
    color: #38bdf8;
    border: 1px solid rgba(56, 189, 248, 0.3);
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.7rem;
    font-weight: 700;
}
.badge-linha-tpm {
    background-color: rgba(245, 158, 11, 0.12);
    color: #f59e0b;
    border: 1px solid rgba(245, 158, 11, 0.3);
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.7rem;
    font-weight: 700;
}
.badge-linha-tps {
    background-color: rgba(16, 185, 129, 0.12);
    color: #10b981;
    border: 1px solid rgba(16, 185, 129, 0.3);
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.7rem;
    font-weight: 700;
}

/* ─── Botão de Número de Série ─── */
.badge-ns-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #0f172a;
    border: 1px solid #38bdf8;
    color: #38bdf8;
    padding: 3px 8px;
    border-radius: 6px;
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.75rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.15s ease;
}
.badge-ns-btn:hover {
    background: #38bdf8;
    color: #0f172a;
    box-shadow: 0 0 10px rgba(56, 189, 248, 0.4);
}

/* ─── Inputs de Filtro ─── */
.atraso-filter-input, .atraso-filter-select {
    background-color: #0d1017;
    border: 1px solid #262c3d;
    color: #ffffff;
    border-radius: 6px;
    padding: 6px 10px;
    font-size: 0.8rem;
    outline: none;
    transition: border-color 0.15s ease;
}
.atraso-filter-input:focus, .atraso-filter-select:focus {
    border-color: #e63946;
}

.atraso-filter-input-mini {
    background: #0f1219;
    border: 1px solid #334155;
    color: #ffffff;
    font-size: 0.78rem;
    font-weight: 600;
    font-family: var(--font-mono, monospace);
    border-radius: 6px;
    padding: 3px 8px;
    height: 30px;
    outline: none;
    transition: all 0.15s ease;
    cursor: pointer;
}
.atraso-filter-input-mini:hover,
.atraso-filter-input-mini:focus {
    border-color: #e63946;
}

.atraso-pill-btn-mini {
    display: inline-flex;
    align-items: center;
    padding: 3px 10px;
    border-radius: 6px;
    font-size: 0.74rem;
    font-weight: 700;
    cursor: pointer;
    border: 1px solid #334155;
    background-color: #0f1219;
    color: #94a3b8;
    transition: all 0.15s ease;
}
.atraso-pill-btn-mini:hover {
    color: #ffffff;
    border-color: #e63946;
}
.atraso-pill-btn-mini.active {
    background-color: rgba(230, 57, 70, 0.18);
    border-color: #e63946;
    color: #ff8585;
}

.atraso-search-wrapper {
    position: relative;
    display: inline-flex;
    align-items: center;
}
.atraso-search-icon {
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    pointer-events: none;
    color: #64748b;
    z-index: 2;
}
.atraso-search-input {
    padding-left: 32px !important;
    padding-right: 12px !important;
}

/* Botões Seletores Interativos de Mês e Semana */
.btn-drilldown-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    background: #1e2433;
    border: 1px solid #334155;
    color: #e2e8f0;
    font-size: 11px;
    font-weight: 700;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.15s ease;
    text-decoration: none;
    box-shadow: 0 1px 2px rgba(0,0,0,0.2);
}
.btn-drilldown-pill:hover {
    background: #2a3349;
    color: #ffffff;
    border-color: #e63946;
    transform: translateY(-1px);
}
.btn-drilldown-pill.active {
    background: #e63946;
    color: #ffffff;
    border-color: #e63946;
    box-shadow: 0 2px 8px rgba(230, 57, 70, 0.4);
}
.btn-drilldown-pill .badge-count {
    background: rgba(0, 0, 0, 0.35);
    padding: 1px 6px;
    border-radius: 9999px;
    font-size: 10px;
    font-family: var(--font-mono, monospace);
    font-weight: 800;
}
.btn-drilldown-pill.active .badge-count {
    background: rgba(255, 255, 255, 0.25);
    color: #ffffff;
}

/* ─── Modal de Fluxo de Produção & Apontamentos ─── */
.modal-fluxo-overlay {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 99990;
    background: rgba(4, 7, 13, 0.82);
    backdrop-filter: blur(5px);
    align-items: center;
    justify-content: center;
    padding: 16px;
    animation: fadeIn 0.2s ease-out;
}
.modal-fluxo-overlay.active {
    display: flex;
}
/* Modal de Rastreabilidade / Nº Série fica SEMPRE NA FRENTE do modal de semanas */
#modalFluxoAtraso {
    z-index: 100050 !important;
}
#modalSemanaDetalhes {
    z-index: 99990 !important;
}
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
.modal-fluxo-container {
    background: #11141e;
    border: 1px solid #262c3d;
    border-radius: 14px;
    max-width: 860px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.8), 0 0 25px rgba(56, 189, 248, 0.1);
    padding: 24px;
    color: #f1f5f9;
}

/* ─── Botão Imprimir (Ícone Minimalista) ─── */
.btn-imprimir-atraso {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    padding: 0;
    color: #cbd5e1;
    background: #151a26;
    border: 1px solid #334155;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.15s ease;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
}
.btn-imprimir-atraso:hover {
    background: #1e2536;
    border-color: #e63946;
    color: #ffffff;
    box-shadow: 0 0 10px rgba(230, 57, 70, 0.25);
}

/* ─── Regras de Impressão Exclusivas para Folha A4 Paisagem ─── */
@media print {
    @page {
        size: landscape !important;
        margin: 4mm 6mm !important;
    }
    
    * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        color-adjust: exact !important;
        box-sizing: border-box !important;
    }
    
    html, body {
        background: #090c14 !important;
        color: #f1f5f9 !important;
        width: 100% !important;
        min-width: 100% !important;
        height: auto !important;
        min-height: auto !important;
        overflow: visible !important;
        margin: 0 !important;
        padding: 0 !important;
        font-size: 11px !important;
    }
    
    /* Remove restrições de overflow e flex do layout do sistema */
    .app-wrapper, .app-main, .app-content, main, #app, .content-wrapper {
        display: block !important;
        height: auto !important;
        min-height: auto !important;
        overflow: visible !important;
        position: static !important;
        padding: 0 !important;
        margin: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        border: none !important;
        background: transparent !important;
    }

    /* Oculta tudo que não faz parte do layout executivo */
    header, nav, aside, .sidebar, .navbar, .sgt-sidebar, .sgt-header, 
    .btn-imprimir-atraso, #btnVoltarMeses, .modal-fluxo-overlay, 
    .atraso-table-wrapper, .atraso-card.overflow-hidden, .nao-imprimir,
    .sgt-layout-sidebar, .sgt-layout-nav, .sgt-app-header,
    .app-header, .app-sidebar, #sidebar, #header {
        display: none !important;
    }

    .atraso-dashboard {
        max-width: 100% !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    /* Top Bar do título */
    .atraso-dashboard > div.border-b {
        margin-bottom: 6px !important;
        padding-bottom: 4px !important;
        border-color: #262c3d !important;
    }

    /* Grid Superior: Data de Ref (4 cols) + Filtros (8 cols) */
    #filtroForm > div.grid {
        display: grid !important;
        grid-template-columns: 4fr 8fr !important;
        gap: 8px !important;
        margin-bottom: 8px !important;
    }
    #filtroForm .md\:col-span-4 {
        grid-column: span 1 !important;
    }
    #filtroForm .md\:col-span-8 {
        grid-column: span 1 !important;
    }

    /* Grid Principal: Gráfico Mês (4 cols) + 8 KPIs (8 cols) */
    .atraso-dashboard > .grid.lg\:grid-cols-12 {
        display: grid !important;
        grid-template-columns: 4fr 8fr !important;
        gap: 8px !important;
        margin-bottom: 8px !important;
    }
    .atraso-dashboard > .grid.lg\:grid-cols-12 > .lg\:col-span-4 {
        grid-column: span 1 !important;
    }
    .atraso-dashboard > .grid.lg\:grid-cols-12 > .lg\:col-span-8 {
        grid-column: span 1 !important;
        display: flex !important;
        flex-direction: column !important;
        gap: 6px !important;
    }

    /* Matriz de 8 Cards de KPI */
    .atraso-dashboard .lg\:col-span-8 .sm\:grid-cols-3 {
        display: grid !important;
        grid-template-columns: repeat(3, 1fr) !important;
        gap: 6px !important;
    }
    .atraso-dashboard .lg\:col-span-8 .sm\:grid-cols-2 {
        display: grid !important;
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 6px !important;
    }

    /* Cards Compactos e Sem Quebras */
    .atraso-card {
        background: #11141e !important;
        border: 1px solid #262c3d !important;
        border-radius: 8px !important;
        box-shadow: none !important;
        padding: 6px 10px !important;
        break-inside: avoid !important;
        page-break-inside: avoid !important;
    }

    .atraso-val-big {
        font-size: 1.35rem !important;
        line-height: 1.1 !important;
    }
    .atraso-val-medium {
        font-size: 1.2rem !important;
        line-height: 1.1 !important;
    }
    .atraso-lbl {
        font-size: 0.65rem !important;
        margin-top: 2px !important;
    }
    .atraso-sub {
        font-size: 0.6rem !important;
        margin-top: 1px !important;
    }

    /* Alturas dos Gráficos na Folha A4 Paisagem */
    .relative.h-\[240px\] {
        height: 155px !important;
    }
    .relative.h-\[220px\] {
        height: 130px !important;
    }
}
</style>

<div class="atraso-dashboard">

    <!-- Top Bar: Título e Ações -->
    <div class="flex flex-wrap items-center justify-between gap-4 pb-5 border-b border-[#262c3d] mb-6">
        <div>
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center justify-center p-2 rounded-lg bg-[#e63946]/10 text-[#e63946] border border-[#e63946]/30">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 15"/><path d="M19 19l2 2"/>
                    </svg>
                </span>
                <div>
                    <h1 class="text-2xl font-bold text-white tracking-tight">Atraso Média Força</h1>
                </div>
            </div>
        </div>

        <!-- Botão Imprimir (Somente Ícone) -->
        <div class="flex items-center gap-2">
            <button type="button" onclick="imprimirLayoutAtraso()" class="btn-imprimir-atraso" title="Imprimir Relatório (A4)">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            </button>
        </div>
    </div>

    <?php if ($erroMsg): ?>
    <div class="p-4 mb-6 rounded-lg bg-red-950/50 border border-red-800 text-red-200 text-sm flex items-center gap-3">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span><strong>Aviso:</strong> <?= htmlspecialchars($erroMsg) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($metricas): ?>
    <!-- ─── Data de Referência na Esquerda e Filtros na Direita ─────── -->
    <form method="GET" id="filtroForm" class="mb-5">
        <input type="hidden" name="data_extracao" value="<?= htmlspecialchars($dataExtracao ?? $metricas['data_extracao']) ?>">
        <input type="hidden" name="meses" id="inputMesesFiltro" value="<?= htmlspecialchars(implode(',', $mesesFiltro)) ?>">

        <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-stretch">
            
            <!-- Esquerda: Card do Dia de Referência (4 cols) -->
            <div class="md:col-span-4 atraso-card flex flex-col justify-center items-center text-center p-3">
                <span class="text-[0.7rem] font-bold uppercase tracking-widest text-[#e63946] mb-0.5">Data de Referência</span>
                <div class="text-lg font-black text-white capitalize leading-tight">
                    <?= htmlspecialchars($metricas['data_corte_formatada']) ?>
                </div>
            </div>

            <!-- Direita: Filtro Minimalista (8 cols) -->
            <div class="md:col-span-8 atraso-card flex flex-wrap items-center gap-3 p-3.5 px-4">
                <div class="flex items-center gap-2">
                    <label class="text-xs text-[#94a3b8] font-semibold whitespace-nowrap">Data de Corte:</label>
                    <input type="date" name="data_corte" value="<?= htmlspecialchars($dataCorte) ?>" 
                           class="atraso-filter-input-mini"
                           onchange="document.getElementById('filtroForm').submit();"
                           title="Filtrar atraso até esta data">
                </div>

                <div class="h-4 w-[1px] bg-[#262c3d] hidden sm:block"></div>

                <!-- Mês do Atraso (Pills minimalistas) -->
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="text-xs text-[#94a3b8] mr-1 hidden lg:inline font-semibold">Mês:</span>
                    
                    <button type="button" onclick="toggleTodosMeses()" class="atraso-pill-btn-mini <?= empty($mesesFiltro) ? 'active' : '' ?>">
                        Todos
                    </button>

                    <?php foreach ($metricas['meses_disponiveis'] as $mKey => $mRotulo): 
                        $isSelected = empty($mesesFiltro) || in_array($mKey, $mesesFiltro, true);
                    ?>
                    <button type="button" 
                            onclick="toggleMesFiltro('<?= htmlspecialchars($mKey) ?>')" 
                            class="atraso-pill-btn-mini <?= $isSelected ? 'active' : '' ?>"
                            data-mes-key="<?= htmlspecialchars($mKey) ?>">
                        <?= htmlspecialchars($mRotulo) ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>
    </form>

    <!-- ══════════════════════════════════════════════════════════════════════════ -->
    <!-- SEÇÃO 1: BLOCO PRINCIPAL (Mês/Semanas à Esquerda + 8 Cartões de KPI) -->
    <!-- ══════════════════════════════════════════════════════════════════════════ -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 mb-6">
        
        <!-- Coluna Esquerda: Peças Atrasadas por Mês com Drilldown Semanal (4 cols) -->
        <div class="lg:col-span-4 flex flex-col">
            <div class="atraso-card flex-1 flex flex-col justify-between p-5">
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <div class="flex items-center gap-2">
                            <h3 id="tituloGraficoMes" class="text-sm font-bold uppercase tracking-wider text-white">Peças atrasadas por mês</h3>
                            <button id="btnVoltarMeses" type="button" onclick="renderizarGraficoMesOuSemana('mes')" class="hidden text-[11px] bg-sky-500/20 text-sky-400 hover:bg-sky-500/30 px-2 py-0.5 rounded transition-all flex items-center gap-1 font-medium cursor-pointer" title="Voltar para a visão geral por meses">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
                                Meses
                            </button>
                        </div>
                        <span id="badgeTotalMes" class="text-xs font-semibold text-[#e63946] bg-[#e63946]/10 px-2 py-0.5 rounded">
                            Total: <?= number_format($metricas['pecas_atraso']['TOTAL'], 0, ',', '.') ?>
                        </span>
                    </div>
                    <p id="subtituloGraficoMes" class="text-xs text-[#94a3b8] mb-4">Clique no mês para detalhar as semanas</p>
                </div>
                
                <div class="relative h-[240px] w-full flex items-center justify-center">
                    <canvas id="chartPecasMes" class="cursor-pointer"></canvas>
                </div>

                <div class="mt-4 pt-3 border-t border-[#262c3d] flex justify-between items-center text-xs text-[#94a3b8]">
                    <span id="lblEixoY">MÊS</span>
                    <span class="font-bold text-white uppercase tracking-wider">QUANTIDADE</span>
                </div>
            </div>
        </div>

        <!-- Coluna Direita: Matriz de 8 Cartões de KPI (8 cols) -->
        <div class="lg:col-span-8 flex flex-col gap-4">
            
            <!-- Linha 1: Dias de Atraso por Linha (3 cards) -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                
                <!-- Atraso TPD -->
                <div class="atraso-card text-center flex flex-col justify-center items-center py-4">
                    <div class="atraso-val-big">
                        <?= number_format($metricas['atraso_dias']['TPD'], 2, ',', '.') ?>
                    </div>
                    <div class="atraso-lbl">Atraso TPD</div>
                    <div class="atraso-sub">
                        Média: <?= number_format($metricas['media_diaria']['TPD'], 1, ',', '.') ?> un/dia
                    </div>
                </div>

                <!-- Atraso TPM -->
                <div class="atraso-card text-center flex flex-col justify-center items-center py-4">
                    <div class="atraso-val-big">
                        <?= number_format($metricas['atraso_dias']['TPM'], 2, ',', '.') ?>
                    </div>
                    <div class="atraso-lbl">Atraso TPM</div>
                    <div class="atraso-sub">
                        Média: <?= number_format($metricas['media_diaria']['TPM'], 1, ',', '.') ?> un/dia
                    </div>
                </div>

                <!-- Atraso TPS (Seco) -->
                <div class="atraso-card text-center flex flex-col justify-center items-center py-4">
                    <div class="atraso-val-big">
                        <?= number_format($metricas['atraso_dias']['TPS'], 2, ',', '.') ?>
                    </div>
                    <div class="atraso-lbl">Atraso TPS</div>
                    <div class="atraso-sub">
                        Média: <?= number_format($metricas['media_diaria']['TPS'], 1, ',', '.') ?> un/dia
                    </div>
                </div>
            </div>

            <!-- Linha 2: Peças em Atraso por Linha (3 cards) -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                
                <!-- Peças TPD -->
                <div class="atraso-card text-center flex flex-col justify-center items-center py-4">
                    <div class="atraso-val-big">
                        <?= number_format($metricas['pecas_atraso']['TPD'], 0, ',', '.') ?>
                    </div>
                    <div class="atraso-lbl">Atraso Peças TPD</div>
                </div>

                <!-- Peças TPM -->
                <div class="atraso-card text-center flex flex-col justify-center items-center py-4">
                    <div class="atraso-val-big">
                        <?= number_format($metricas['pecas_atraso']['TPM'], 0, ',', '.') ?>
                    </div>
                    <div class="atraso-lbl">Atraso Peças TPM</div>
                </div>

                <!-- Peças TPS (Seco) -->
                <div class="atraso-card text-center flex flex-col justify-center items-center py-4">
                    <div class="atraso-val-big">
                        <?= number_format($metricas['pecas_atraso']['TPS'], 0, ',', '.') ?>
                    </div>
                    <div class="atraso-lbl">Atraso Peças SECO</div>
                </div>
            </div>

            <!-- Linha 3: Totalizadores Finais (2 cards) -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                
                <!-- Atraso Peças Total -->
                <div class="atraso-card text-center flex flex-col justify-center items-center py-4">
                    <div class="atraso-val-big text-3xl">
                        <?= number_format($metricas['pecas_atraso']['TOTAL'], 0, ',', '.') ?>
                    </div>
                    <div class="atraso-lbl">Atraso Peças Total</div>
                </div>

                <!-- Média Geral em Dias -->
                <div class="atraso-card text-center flex flex-col justify-center items-center py-4">
                    <div class="atraso-val-big text-3xl">
                        <?= number_format($metricas['atraso_dias']['MEDIA_GERAL'], 2, ',', '.') ?>
                    </div>
                    <div class="atraso-lbl">Média Geral</div>
                </div>
            </div>

        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════════ -->
    <!-- SEÇÃO 2: GRÁFICO DE SÉRIE HISTÓRICA (MÉDIA DE DIAS EM ATRASO)            -->
    <!-- ══════════════════════════════════════════════════════════════════════════ -->
    <div class="atraso-card p-5 mb-6">
        <div class="flex items-center justify-between mb-2">
            <div>
                <h3 class="text-sm font-bold uppercase tracking-wider text-white">Média de dias em atraso</h3>
                <p class="text-xs text-[#94a3b8]">Evolução diária da média aritmética do atraso (TPD, TPM, TPS) nos últimos 15 dias úteis</p>
            </div>
            <span class="text-xs text-[#94a3b8] bg-[#090c14] px-2.5 py-1 rounded border border-[#262c3d]">
                Tendência: <strong class="<?= (end($metricas['serie_evolucao'])['media_geral'] ?? 0) <= 12 ? 'text-emerald-400' : 'text-[#ff6b6b]' ?>"><?= end($metricas['serie_evolucao'])['media_geral'] ?? 0 ?> dias</strong>
            </span>
        </div>

        <div class="relative h-[220px] w-full mt-3">
            <canvas id="chartEvolucaoAtraso"></canvas>
        </div>
        
        <div class="mt-3 pt-3 border-t border-[#262c3d] flex justify-between items-center text-xs text-[#94a3b8]">
            <span>DIAS</span>
            <span class="font-bold text-white uppercase tracking-wider">MÊS / HISTÓRICO</span>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════════ -->
    <!-- SEÇÃO 3: TABELA ANALÍTICA DE ORDENS EM ATRASO                            -->
    <!-- ══════════════════════════════════════════════════════════════════════════ -->
    <div class="atraso-card overflow-hidden mb-8">
        
        <div class="p-4 border-b border-[#262c3d] flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="text-sm font-bold uppercase tracking-wider text-white">Relação Analítica de Ordens em Atraso (Média Força)</h3>
                <p class="text-xs text-[#94a3b8]">Lista detalhada de pedidos do Plano Mestre de Média Força pendentes de apontamento no laboratório</p>
            </div>

            <div class="flex items-center gap-3">
                <div class="atraso-search-wrapper">
                    <svg class="atraso-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" id="tabelaBusca" placeholder="Buscar pedido, cliente, projeto, NS..." 
                           class="atraso-filter-input atraso-search-input w-64 text-xs" oninput="filtrarTabelaDetalhes()">
                </div>

                <select id="tabelaFiltroLinha" class="atraso-filter-select text-xs" onchange="filtrarTabelaDetalhes()">
                    <option value="">Todas as Linhas</option>
                    <option value="TPD">TPD (≤ 300 kVA)</option>
                    <option value="TPM">TPM (> 300 kVA)</option>
                    <option value="TPS">TPS (Seco)</option>
                </select>
            </div>
        </div>

        <div class="overflow-auto max-h-[520px] relative">
            <table class="atraso-table" id="tabelaAtrasos">
                <thead>
                    <tr>
                        <th style="width: 75px;">Pedido</th>
                        <th>Cliente</th>
                        <th style="width: 105px; text-align: center;">Nº Série</th>
                        <th style="width: 100px;">Projeto</th>
                        <th>Descrição do Transformador</th>
                        <th style="width: 65px; text-align: right;">Potência</th>
                        <th style="width: 95px; text-align: center;">Linha</th>
                        <th style="width: 85px; text-align: center;">Data Prev.</th>
                        <th style="width: 75px; text-align: center;">Atraso</th>
                    </tr>
                </thead>
                <tbody id="tabelaCorpo">
                    <?php foreach ($metricas['itens_detalhados'] as $idx => $it): 
                        $pedStr   = (string)($it['cd_pedido'] ?? $it['pedido'] ?? '-');
                        $cliStr   = (string)($it['cliente_apelido'] ?? $it['cliente_nome'] ?? $it['cliente'] ?? '-');
                        $refStr   = (string)($it['cd_referencia'] ?? $it['referencia'] ?? '-');
                        $descStr  = (string)($it['ds_produto'] ?? $it['descricao'] ?? '-');
                        $kvaNum   = (float)($it['potencia_kva'] ?? 0);
                        $kvaStr   = $kvaNum > 0 ? number_format($kvaNum, 0, ',', '.') . ' kVA' : '-';
                        $linhaStr = (string)($it['linha'] ?? 'TPD');
                        $dtProg   = (string)($it['data_programada'] ?? '');
                        $dtProgFmt = $dtProg ? date('d/m/Y', strtotime($dtProg)) : '-';
                        $diasAtr  = (int)($it['dias_atraso_individual'] ?? 0);
                        $nsFmt    = (string)($it['nr_serie_formatado'] ?? '—');
                        $buscaStr = strtolower("$pedStr $cliStr $refStr $descStr $linhaStr $nsFmt");
                        $itJson   = json_encode($it, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
                    ?>
                    <tr data-linha="<?= htmlspecialchars($linhaStr) ?>" 
                        data-mes="<?= htmlspecialchars($it['mes_chave'] ?? '') ?>"
                        data-semana="<?= (int)($it['semana_ano'] ?? 0) ?>"
                        data-texto="<?= htmlspecialchars($buscaStr) ?>">
                        <td class="font-mono text-emerald-400 font-bold"><?= htmlspecialchars($pedStr) ?></td>
                        <td class="font-medium text-white"><?= htmlspecialchars($cliStr) ?></td>
                        <td class="text-center">
                            <?php if ($nsFmt !== '—'): ?>
                                <button type="button" class="badge-ns-btn" onclick="abrirModalFluxoAtraso(<?= htmlspecialchars($itJson) ?>)" title="Clique para ver os apontamentos e as 10 células desta peça">
                                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg>
                                    <?= htmlspecialchars($nsFmt) ?>
                                </button>
                            <?php else: ?>
                                <span class="text-slate-500 font-mono text-xs">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="font-mono text-slate-300"><?= htmlspecialchars($refStr) ?></td>
                        <td class="text-xs text-slate-300" title="<?= htmlspecialchars($descStr) ?>">
                            <?= htmlspecialchars(strlen($descStr) > 48 ? substr($descStr, 0, 45) . '...' : $descStr) ?>
                        </td>
                        <td class="font-mono text-right text-slate-200"><?= htmlspecialchars($kvaStr) ?></td>
                        <td class="text-center">
                            <?php if ($linhaStr === 'TPM'): ?>
                                <span class="badge-linha-tpm">TPM</span>
                            <?php elseif ($linhaStr === 'TPS'): ?>
                                <span class="badge-linha-tps">TPS Seco</span>
                            <?php else: ?>
                                <span class="badge-linha-tpd">TPD</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center font-mono text-xs text-slate-300">
                            <?= htmlspecialchars($dtProgFmt) ?>
                        </td>
                        <td class="text-center font-mono font-bold text-[#ff6b6b]">
                            +<?= $diasAtr ?> d
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="p-3 border-t border-[#262c3d] flex justify-between items-center text-xs text-[#94a3b8]">
            <span id="tabelaContador">Exibindo <?= count($metricas['itens_detalhados']) ?> ordens</span>
            <span>Clique no <strong class="text-sky-400">Nº de Série</strong> para ver a rastreabilidade e apontamentos das 10 células</span>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- MODAL DE RASTREABILIDADE & FLUXO DE PRODUÇÃO                              -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div id="modalFluxoAtraso" class="modal-fluxo-overlay" onclick="fecharModalFluxo(event)">
    <div class="modal-fluxo-container" onclick="event.stopPropagation()">
        <div class="flex items-start justify-between pb-4 border-b border-[#262c3d]">
            <div>
                <div class="flex items-center gap-2">
                    <span class="inline-block w-2.5 h-2.5 rounded-full bg-emerald-400 animate-pulse"></span>
                    <h3 class="text-lg font-bold text-white">Rastreabilidade & Apontamentos da Peça</h3>
                </div>
                <p class="text-xs text-[#94a3b8] mt-1" id="modalFluxoSubtitulo">Diagnóstico de onde a peça está parada na esteira de produção</p>
            </div>
            <button type="button" onclick="fecharModalFluxo()" class="text-slate-400 hover:text-white p-1.5 rounded-lg hover:bg-[#1a202c] transition-colors">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>

        <!-- Cabeçalho com Detalhes da Ordem -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 my-4 p-3.5 bg-[#090c14] border border-[#262c3d] rounded-xl text-xs">
            <div>
                <span class="text-slate-400 block text-[11px] uppercase tracking-wider">Pedido / Projeto</span>
                <span class="font-bold text-emerald-400 font-mono text-sm" id="modalPedProj">-</span>
            </div>
            <div>
                <span class="text-slate-400 block text-[11px] uppercase tracking-wider">Cliente</span>
                <span class="font-semibold text-white truncate block" id="modalCliente">-</span>
            </div>
            <div>
                <span class="text-slate-400 block text-[11px] uppercase tracking-wider">Nº Série</span>
                <span class="font-bold text-sky-400 font-mono text-sm" id="modalNrSerie">-</span>
            </div>
            <div>
                <span class="text-slate-400 block text-[11px] uppercase tracking-wider">Atraso</span>
                <span class="font-bold text-[#ff6b6b] font-mono text-sm" id="modalAtraso">-</span>
            </div>
        </div>

        <!-- Diagnóstico de Gargalo -->
        <div id="modalDiagnosticoBox" class="p-3 mb-4 rounded-lg bg-amber-500/10 border border-amber-500/30 text-amber-300 text-xs flex items-center gap-2.5">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span id="modalDiagnosticoTexto">Localizando o estágio atual de produção da peça...</span>
        </div>

        <!-- Grade das 10 Células Industriais -->
        <div class="mb-5">
            <h4 class="text-xs font-bold text-slate-300 uppercase tracking-wider mb-2.5">Status das 10 Células Industriais:</h4>
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-2" id="modalGridCelulas">
                <!-- Preenchido via JavaScript -->
            </div>
        </div>

        <!-- Lista de Apontamentos por Transformador -->
        <div id="modalTrafosSection" class="hidden">
            <h4 class="text-xs font-bold text-slate-300 uppercase tracking-wider mb-2">Transformadores Vinculados a este Lote:</h4>
            <div class="border border-[#262c3d] rounded-lg overflow-hidden max-h-48 overflow-y-auto">
                <table class="w-full text-xs">
                    <thead class="bg-[#090c14] text-slate-400 text-left">
                        <tr>
                            <th class="p-2 font-semibold">Nº Série</th>
                            <th class="p-2 font-semibold">OF-Mãe</th>
                            <th class="p-2 font-semibold">Seq.</th>
                            <th class="p-2 font-semibold text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody id="modalTrafosCorpo" class="divide-y divide-[#1e2433]">
                        <!-- Preenchido via JS -->
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Rodapé do Modal -->
        <div class="mt-5 pt-3 border-t border-[#262c3d] flex justify-between items-center text-xs">
            <a href="/pages/fluxo-pedidos/setor.php" target="_blank" class="text-sky-400 hover:text-sky-300 font-medium inline-flex items-center gap-1">
                Abrir Painel Completo de Fluxo de Produção
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
            </a>
            <button type="button" onclick="fecharModalFluxo()" class="px-4 py-1.5 bg-[#1e2433] hover:bg-[#2a3449] text-white rounded-lg transition-colors font-medium cursor-pointer">
                Fechar
            </button>
        </div>
    </div>
</div>

<!-- ─── Modal de Relação da Semana (Média Força) ────────────────────────────── -->
<div class="modal-fluxo-overlay" id="modalSemanaDetalhes" onclick="if(event.target === this) fecharModalSemana();">
    <div class="modal-fluxo-container max-w-4xl" onclick="event.stopPropagation()">
        
        <!-- Header Modal -->
        <div class="flex items-start justify-between pb-4 border-b border-[#262c3d]">
            <div>
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center justify-center p-1.5 rounded bg-sky-500/10 text-sky-400 border border-sky-500/30">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </span>
                    <h2 class="text-lg font-bold text-white tracking-tight" id="modalSemanaTitulo">Relação de Ordens da Semana</h2>
                </div>
                <div class="flex flex-wrap items-center gap-2 mt-2" id="modalSemanaBadges"></div>
            </div>

            <button type="button" onclick="fecharModalSemana()" class="text-slate-400 hover:text-white p-1 rounded-lg hover:bg-[#1f2433] transition-colors" title="Fechar (Esc)">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>

        <!-- Filtro Rápido dentro do Modal -->
        <div class="py-3 flex items-center justify-between gap-3">
            <div class="atraso-search-wrapper w-full max-w-xs">
                <svg class="atraso-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="modalSemanaBusca" placeholder="Filtrar pedido, cliente, NS..." 
                       class="atraso-filter-input atraso-search-input w-full text-xs" oninput="filtrarTabelaModalSemana()">
            </div>
            <span class="text-xs text-slate-400 font-mono" id="modalSemanaContador">0 ordens</span>
        </div>

        <!-- Tabela de Ordens da Semana -->
        <div class="overflow-auto max-h-[420px] rounded border border-[#262c3d] bg-[#0d1017]">
            <table class="atraso-table" id="tabelaModalSemana">
                <thead>
                    <tr>
                        <th style="width: 80px;">Pedido</th>
                        <th>Cliente</th>
                        <th style="width: 110px; text-align: center;">Nº Série</th>
                        <th style="width: 100px;">Projeto</th>
                        <th>Descrição</th>
                        <th style="width: 70px; text-align: right;">Potência</th>
                        <th style="width: 95px; text-align: center;">Linha</th>
                        <th style="width: 85px; text-align: center;">Data Prev.</th>
                        <th style="width: 75px; text-align: center;">Atraso</th>
                    </tr>
                </thead>
                <tbody id="modalSemanaCorpo"></tbody>
            </table>
        </div>

        <!-- Footer Modal -->
        <div class="pt-4 mt-3 border-t border-[#262c3d] flex flex-wrap items-center justify-between gap-3 text-xs">
            <span class="text-slate-400">Clique no <strong class="text-sky-400">Nº de Série</strong> para abrir a rastreabilidade das 10 células</span>
            <div class="flex items-center gap-2">
                <button type="button" onclick="irParaTabelaPrincipalFiltrada()" class="px-3.5 py-1.5 rounded bg-sky-600 hover:bg-sky-500 text-white font-semibold shadow transition-colors cursor-pointer flex items-center gap-1.5">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                    Ver na Tabela Principal
                </button>
                <button type="button" onclick="fecharModalSemana()" class="px-3.5 py-1.5 rounded bg-[#1e2433] hover:bg-[#2a3246] text-slate-300 font-semibold transition-colors cursor-pointer">
                    Fechar
                </button>
            </div>
        </div>

    </div>
</div>

</div>

<!-- Scripts de Gráficos (Chart.js + Plugin DataLabels) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0"></script>

<script>
<?php if ($metricas): ?>
// ─── Dados para os Gráficos ────────────────────────────────────────────────
const dadosPecasMes = <?= json_encode($metricas['pecas_por_mes'], JSON_UNESCAPED_UNICODE) ?>;
const dadosSemanasPorMes = <?= json_encode($metricas['semanas_por_mes'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
const dadosEvolucao = <?= json_encode($metricas['serie_evolucao'], JSON_UNESCAPED_UNICODE) ?>;
const todosItensAtraso = <?= json_encode($metricas['itens_detalhados'], JSON_UNESCAPED_UNICODE) ?>;

let chartPecasMesInstancia = null;
let visaoGraficoAtual = 'mes';
let filtroMesTabela = null;
let filtroSemanaTabela = null;
let itensModalSemanaAtual = [];

function renderizarGraficoMesOuSemana(tipo, chaveMes = null) {
    const ctxMes = document.getElementById('chartPecasMes')?.getContext('2d');
    if (!ctxMes) return;

    if (chartPecasMesInstancia) {
        chartPecasMesInstancia.destroy();
        chartPecasMesInstancia = null;
    }

    const btnVoltar = document.getElementById('btnVoltarMeses');
    const titulo = document.getElementById('tituloGraficoMes');
    const subtitulo = document.getElementById('subtituloGraficoMes');
    const lblEixoY = document.getElementById('lblEixoY');
    const badgeTotal = document.getElementById('badgeTotalMes');

    if (tipo === 'semana' && chaveMes && dadosSemanasPorMes[chaveMes]) {
        visaoGraficoAtual = 'semana';
        filtroMesTabela = chaveMes;
        filtroSemanaTabela = null;

        const sems = dadosSemanasPorMes[chaveMes];
        const rotuloMes = sems[0]?.mes_rotulo || chaveMes;
        const totalSem = sems.reduce((acc, s) => acc + s.qtd, 0);

        if (titulo) titulo.textContent = `Atraso Semanal — ${rotuloMes}`;
        if (subtitulo) subtitulo.textContent = 'Clique em uma semana para ver a relação de peças';
        if (btnVoltar) btnVoltar.classList.remove('hidden');
        if (lblEixoY) lblEixoY.textContent = 'SEMANA';
        if (badgeTotal) badgeTotal.textContent = `Total: ${totalSem.toLocaleString('pt-BR')}`;

        const labelsSem = sems.map(s => s.rotulo);
        const valoresSem = sems.map(s => s.qtd);

        const coresPaleta = ['#e63946', '#f77f00', '#fcbf49', '#06d6a0', '#118ab2', '#a8dadc'];

        chartPecasMesInstancia = new Chart(ctxMes, {
            type: 'bar',
            data: {
                labels: labelsSem,
                datasets: [{
                    data: valoresSem,
                    backgroundColor: sems.map((_, i) => coresPaleta[i % coresPaleta.length]),
                    hoverBackgroundColor: '#ff6b6b',
                    borderRadius: 4,
                    barThickness: Math.min(26, Math.max(14, Math.floor(150 / sems.length))),
                    minBarLength: 14,
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                layout: {
                    padding: { right: 45, left: 10 }
                },
                onHover: (evt, elements, chart) => {
                    if (!chart || !chart.canvas) return;
                    const yPixel = evt.native ? evt.native.offsetY : (evt.y ?? 0);
                    const yVal = chart.scales.y ? chart.scales.y.getValueForPixel(yPixel) : -1;
                    if (yVal >= -0.4 && yVal < sems.length + 0.4) {
                        chart.canvas.style.cursor = 'pointer';
                    } else {
                        chart.canvas.style.cursor = 'default';
                    }
                },
                onClick: (evt, elements) => {
                    let clickedIdx = -1;
                    if (elements && elements.length > 0) {
                        clickedIdx = elements[0].index;
                    } else if (chartPecasMesInstancia && chartPecasMesInstancia.scales.y) {
                        const yPixel = evt.native ? evt.native.offsetY : (evt.y ?? 0);
                        const yVal = chartPecasMesInstancia.scales.y.getValueForPixel(yPixel);
                        if (yVal !== undefined && yVal >= -0.4 && yVal < sems.length + 0.4) {
                            clickedIdx = Math.max(0, Math.min(sems.length - 1, Math.round(yVal)));
                        }
                    }

                    if (clickedIdx >= 0 && sems[clickedIdx]) {
                        const semItem = sems[clickedIdx];
                        filtroSemanaTabela = semItem.semana;
                        filtrarTabelaDetalhes();
                        abrirModalSemana(semItem);
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0f1219',
                        titleColor: '#fff',
                        bodyColor: '#e2e8f0',
                        borderColor: '#262c3d',
                        borderWidth: 1,
                        callbacks: {
                            label: function(ctx) {
                                const s = sems[ctx.dataIndex];
                                return [
                                    ` ${s.qtd.toLocaleString('pt-BR')} peças em atraso (Clique para abrir)`,
                                    ` • TPD: ${s.tpd} | TPM: ${s.tpm} | TPS: ${s.tps}`
                                ];
                            }
                        }
                    },
                    datalabels: {
                        anchor: 'end',
                        align: 'right',
                        color: '#ffffff',
                        font: {
                            family: 'JetBrains Mono, monospace',
                            size: 12,
                            weight: 'bold'
                        },
                        formatter: function(val) {
                            return val.toLocaleString('pt-BR');
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            color: 'rgba(255, 255, 255, 0.07)',
                            borderDash: [3, 3]
                        },
                        ticks: {
                            color: '#94a3b8',
                            font: { size: 10 },
                            callback: function(val) {
                                if (val >= 1000) return (val / 1000) + ' Mil';
                                return val;
                            }
                        },
                        border: { display: false }
                    },
                    y: {
                        grid: { display: false },
                        ticks: {
                            color: '#ffffff',
                            font: {
                                family: 'Inter, sans-serif',
                                size: 11,
                                weight: '600'
                            }
                        },
                        border: { color: '#334155' }
                    }
                }
            },
            plugins: [ChartDataLabels]
        });

        filtrarTabelaDetalhes();

    } else {
        visaoGraficoAtual = 'mes';
        filtroMesTabela = null;
        filtroSemanaTabela = null;

        if (titulo) titulo.textContent = 'Peças atrasadas por mês';
        if (subtitulo) subtitulo.textContent = 'Clique no mês para detalhar as semanas';
        if (btnVoltar) btnVoltar.classList.add('hidden');
        if (lblEixoY) lblEixoY.textContent = 'MÊS';
        const totalGeral = dadosPecasMes.reduce((acc, m) => acc + m.qtd, 0);
        if (badgeTotal) badgeTotal.textContent = `Total: ${totalGeral.toLocaleString('pt-BR')}`;

        const labelsMes = dadosPecasMes.map(d => d.rotulo);
        const valoresMes = dadosPecasMes.map(d => d.qtd);

        chartPecasMesInstancia = new Chart(ctxMes, {
            type: 'bar',
            data: {
                labels: labelsMes,
                datasets: [{
                    data: valoresMes,
                    backgroundColor: '#e63946',
                    hoverBackgroundColor: '#ff4d4d',
                    borderRadius: 4,
                    barThickness: 28,
                    minBarLength: 16,
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                layout: {
                    padding: { right: 40, left: 10 }
                },
                onHover: (evt, elements, chart) => {
                    if (!chart || !chart.canvas) return;
                    const yPixel = evt.native ? evt.native.offsetY : (evt.y ?? 0);
                    const yVal = chart.scales.y ? chart.scales.y.getValueForPixel(yPixel) : -1;
                    if (yVal >= -0.4 && yVal < dadosPecasMes.length + 0.4) {
                        chart.canvas.style.cursor = 'pointer';
                    } else {
                        chart.canvas.style.cursor = 'default';
                    }
                },
                onClick: (evt, elements) => {
                    let clickedIdx = -1;
                    if (elements && elements.length > 0) {
                        clickedIdx = elements[0].index;
                    } else if (chartPecasMesInstancia && chartPecasMesInstancia.scales.y) {
                        const yPixel = evt.native ? evt.native.offsetY : (evt.y ?? 0);
                        const yVal = chartPecasMesInstancia.scales.y.getValueForPixel(yPixel);
                        if (yVal !== undefined && yVal >= -0.4 && yVal < dadosPecasMes.length + 0.4) {
                            clickedIdx = Math.max(0, Math.min(dadosPecasMes.length - 1, Math.round(yVal)));
                        }
                    }

                    if (clickedIdx >= 0 && dadosPecasMes[clickedIdx]) {
                        const mesObj = dadosPecasMes[clickedIdx];
                        if (mesObj && mesObj.chave) {
                            renderizarGraficoMesOuSemana('semana', mesObj.chave);
                        }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0f1219',
                        titleColor: '#fff',
                        bodyColor: '#e63946',
                        borderColor: '#262c3d',
                        borderWidth: 1,
                        callbacks: {
                            label: function(ctx) {
                                return ' ' + ctx.raw.toLocaleString('pt-BR') + ' peças em atraso (Clique para ver semanas)';
                            }
                        }
                    },
                    datalabels: {
                        anchor: 'end',
                        align: 'right',
                        color: '#ffffff',
                        font: {
                            family: 'JetBrains Mono, monospace',
                            size: 13,
                            weight: 'bold'
                        },
                        formatter: function(val) {
                            return val.toLocaleString('pt-BR');
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            color: 'rgba(255, 255, 255, 0.07)',
                            borderDash: [3, 3]
                        },
                        ticks: {
                            color: '#94a3b8',
                            font: { size: 11 },
                            callback: function(val) {
                                if (val >= 1000) return (val / 1000) + ' Mil';
                                return val;
                            }
                        },
                        border: { display: false }
                    },
                    y: {
                        grid: { display: false },
                        ticks: {
                            color: '#ffffff',
                            font: {
                                family: 'Inter, sans-serif',
                                size: 12,
                                weight: 'bold'
                            }
                        },
                        border: { color: '#334155' }
                    }
                }
            },
            plugins: [ChartDataLabels]
        });

        filtrarTabelaDetalhes();
    }
}

// Inicia no modo Mensal
renderizarGraficoMesOuSemana('mes');

// 2. Gráfico de Área: Evolução da "Média de dias em atraso"
const ctxEvolucao = document.getElementById('chartEvolucaoAtraso')?.getContext('2d');
if (ctxEvolucao && dadosEvolucao.length > 0) {
    const labelsEvolucao = dadosEvolucao.map(d => d.label);
    const valoresEvolucao = dadosEvolucao.map(d => d.media_geral);

    const grad = ctxEvolucao.createLinearGradient(0, 0, 0, 200);
    grad.addColorStop(0, 'rgba(230, 57, 70, 0.65)');
    grad.addColorStop(1, 'rgba(230, 57, 70, 0.05)');

    new Chart(ctxEvolucao, {
        type: 'line',
        data: {
            labels: labelsEvolucao,
            datasets: [{
                label: 'Média Geral (Dias)',
                data: valoresEvolucao,
                borderColor: '#e63946',
                borderWidth: 3,
                backgroundColor: grad,
                fill: true,
                tension: 0.35,
                pointBackgroundColor: '#ffffff',
                pointBorderColor: '#e63946',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: {
                padding: { top: 25, right: 15, left: 10 }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#0f1219',
                    titleColor: '#fff',
                    bodyColor: '#ff8585',
                    borderColor: '#334155',
                    borderWidth: 1,
                    callbacks: {
                        label: function(ctx) {
                            return ' Média de atraso: ' + ctx.raw.toLocaleString('pt-BR', { minimumFractionDigits: 1 }) + ' dias';
                        }
                    }
                },
                datalabels: {
                    anchor: 'top',
                    align: 'top',
                    offset: 4,
                    color: '#ffffff',
                    font: {
                        family: 'JetBrains Mono, monospace',
                        size: 11,
                        weight: 'bold'
                    },
                    formatter: function(val) {
                        return val.toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        color: 'rgba(255, 255, 255, 0.05)',
                        borderDash: [3, 3]
                    },
                    ticks: {
                        color: '#94a3b8',
                        font: { size: 10 }
                    },
                    border: { display: false }
                },
                y: {
                    beginAtZero: true,
                    suggestedMax: Math.max(...valoresEvolucao) + 3,
                    grid: {
                        color: 'rgba(255, 255, 255, 0.07)',
                        borderDash: [3, 3]
                    },
                    ticks: {
                        color: '#94a3b8',
                        font: { size: 11 }
                    },
                    border: { display: false }
                }
            }
        },
        plugins: [ChartDataLabels]
    });
}

// ─── Manipulação de Filtros de Meses (Padrão Indicador de Distribuição) ──────
window.toggleMesFiltro = function(mKey) {
    const input = document.getElementById('inputMesesFiltro');
    let atuais = input.value ? input.value.split(',').filter(Boolean) : [];
    
    if (atuais.includes(mKey)) {
        atuais = atuais.filter(x => x !== mKey);
    } else {
        atuais.push(mKey);
    }
    
    input.value = atuais.join(',');
    document.getElementById('filtroForm').submit();
};

window.toggleTodosMeses = function() {
    document.getElementById('inputMesesFiltro').value = '';
    document.getElementById('filtroForm').submit();
};
<?php endif; ?>

// ─── Busca e Filtro na Tabela de Detalhes ────────────────────────────────────
function filtrarTabelaDetalhes() {
    const busca = (document.getElementById('tabelaBusca')?.value || '').toLowerCase().trim();
    const linha = (document.getElementById('tabelaFiltroLinha')?.value || '').trim();
    const linhas = document.querySelectorAll('#tabelaCorpo tr');
    let visiveis = 0;

    linhas.forEach(tr => {
        const trLinha = tr.getAttribute('data-linha') || '';
        const trTexto = tr.getAttribute('data-texto') || '';
        const trMes = tr.getAttribute('data-mes') || '';
        const trSemana = tr.getAttribute('data-semana') || '';

        const matchBusca = !busca || trTexto.includes(busca);
        const matchLinha = !linha || trLinha === linha;
        const matchMes = !filtroMesTabela || trMes === filtroMesTabela;
        const matchSemana = !filtroSemanaTabela || trSemana == filtroSemanaTabela;

        if (matchBusca && matchLinha && matchMes && matchSemana) {
            tr.style.display = '';
            visiveis++;
        } else {
            tr.style.display = 'none';
        }
    });

    const contador = document.getElementById('tabelaContador');
    if (contador) {
        let textoContador = 'Exibindo ' + visiveis + ' ordens';
        if (filtroSemanaTabela) {
            textoContador += ` (Filtro: Semana ${filtroSemanaTabela})`;
        } else if (filtroMesTabela) {
            textoContador += ` (Filtro: ${filtroMesTabela})`;
        }
        contador.textContent = textoContador;
    }
}

// ─── Exportação da Relação para CSV ──────────────────────────────────────────
function exportarTabelaCSV() {
    const linhas = document.querySelectorAll('#tabelaAtrasos tr');
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
    link.download = 'relacao_ordens_atraso_media_forca_' + (new Date().toISOString().slice(0, 10)) + '.csv';
    link.click();
}

// ─── Modal de Rastreabilidade & Fluxo de Produção ───────────────────────────
const NOMES_SETORES = {
    'CH': 'Chapa / Corte',
    'BT': 'Bobinagem BT',
    'AT': 'Bobinagem AT',
    'CNC': 'Corte Núcleo',
    'SOL': 'Solda / Tanque',
    'MN': 'Montagem Núcleo',
    'PIN': 'Pintura',
    'ME': 'Montagem Elétrica',
    'MF': 'Montagem Final',
    'LAB': 'Laboratório / Ensaios'
};

const ORDEM_SETORES = ['CH', 'BT', 'AT', 'CNC', 'SOL', 'MN', 'PIN', 'ME', 'MF', 'LAB'];

window.abrirModalFluxoAtraso = function(item) {
    const modal = document.getElementById('modalFluxoAtraso');
    if (!modal || !item) return;

    document.getElementById('modalPedProj').textContent = (item.cd_pedido || item.pedido || '-') + ' / ' + (item.cd_referencia || item.referencia || '-');
    document.getElementById('modalCliente').textContent = item.cliente_apelido || item.cliente_nome || item.cliente || '-';
    document.getElementById('modalNrSerie').textContent = item.nr_serie_formatado || item.nr_serie || '—';
    document.getElementById('modalAtraso').textContent = '+' + (item.dias_atraso_individual || 0) + ' dias de atraso';

    const grid = document.getElementById('modalGridCelulas');
    grid.innerHTML = '';

    const setores = item.setores || {};
    let primeiroPendente = null;

    ORDEM_SETORES.forEach(code => {
        const st = setores[code] || 'PEND';
        const isOk = (st === 'OK');
        const nome = NOMES_SETORES[code] || code;

        if (!isOk && !primeiroPendente) {
            primeiroPendente = { code, nome };
        }

        const card = document.createElement('div');
        card.className = `p-2.5 rounded-lg border text-center transition-all ${
            isOk 
                ? 'bg-emerald-950/40 border-emerald-800/80 text-emerald-200' 
                : 'bg-amber-950/40 border-amber-800/80 text-amber-200'
        }`;

        card.innerHTML = `
            <div class="flex items-center justify-between mb-1">
                <span class="font-bold text-xs font-mono">${code}</span>
                <span class="text-[10px] font-bold px-1.5 py-0.5 rounded ${isOk ? 'bg-emerald-500/20 text-emerald-300' : 'bg-amber-500/20 text-amber-300'}">
                    ${isOk ? 'OK' : 'PEND'}
                </span>
            </div>
            <div class="text-[11px] text-slate-300 truncate text-left" title="${nome}">
                ${nome}
            </div>
        `;
        grid.appendChild(card);
    });

    const diagTexto = document.getElementById('modalDiagnosticoTexto');
    if (primeiroPendente) {
        diagTexto.innerHTML = `Gargalo identificado: Peça aguardando apontamento na etapa <strong>${primeiroPendente.code} (${primeiroPendente.nome})</strong>.`;
    } else {
        diagTexto.innerHTML = `Todas as células intermediárias foram concluídas. Peça aguardando apenas liberação do <strong>Laboratório / Faturamento</strong>.`;
    }

    const trafosSec = document.getElementById('modalTrafosSection');
    const trafosCorpo = document.getElementById('modalTrafosCorpo');
    const detalhes = item.fluxo_detalhes || [];

    if (detalhes.length > 0) {
        trafosCorpo.innerHTML = '';
        detalhes.forEach(t => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="p-2 font-mono font-bold text-sky-400">${t.nr_serie || '—'}</td>
                <td class="p-2 font-mono text-slate-300">${t.of_mae || '—'}</td>
                <td class="p-2 font-mono text-slate-400">${t.seq || '—'}</td>
                <td class="p-2 text-center">
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold ${t.is_concluido ? 'bg-emerald-500/20 text-emerald-300' : 'bg-amber-500/20 text-amber-300'}">
                        ${t.is_concluido ? 'CONCLUÍDO' : 'EM PROCESSO'}
                    </span>
                </td>
            `;
            trafosCorpo.appendChild(tr);
        });
        trafosSec.classList.remove('hidden');
    } else {
        trafosSec.classList.add('hidden');
    }

    modal.classList.add('active');
};

window.fecharModalFluxo = function(e) {
    if (!e || e.target.id === 'modalFluxoAtraso' || e.target.closest('button')) {
        const modal = document.getElementById('modalFluxoAtraso');
        if (modal) modal.classList.remove('active');
    }
};

// ─── Funções do Modal da Relação da Semana (Média Força) ────────────────────
window.abrirModalSemana = function(semItem) {
    if (!semItem) return;
    const modal = document.getElementById('modalSemanaDetalhes');
    if (!modal) return;

    // Atualiza título e badges
    document.getElementById('modalSemanaTitulo').textContent = `Relação de Ordens — ${semItem.rotulo}`;
    
    const badgesWrap = document.getElementById('modalSemanaBadges');
    if (badgesWrap) {
        badgesWrap.innerHTML = `
            <span class="text-xs px-2.5 py-0.5 rounded-full font-bold bg-[#e63946]/20 text-[#ff8585] border border-[#e63946]/40">
                Total: ${semItem.qtd.toLocaleString('pt-BR')} peças
            </span>
            <span class="text-xs px-2 py-0.5 rounded-full font-semibold bg-sky-500/15 text-sky-300 border border-sky-500/30">
                TPD: ${semItem.tpd || 0}
            </span>
            <span class="text-xs px-2 py-0.5 rounded-full font-semibold bg-amber-500/15 text-amber-300 border border-amber-500/30">
                TPM: ${semItem.tpm || 0}
            </span>
            <span class="text-xs px-2 py-0.5 rounded-full font-semibold bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">
                TPS: ${semItem.tps || 0}
            </span>
        `;
    }

    // Filtra os itens da semana e mês
    itensModalSemanaAtual = todosItensAtraso.filter(it => 
        (Number(it.semana_ano) === Number(semItem.semana)) && (!semItem.mes_chave || it.mes_chave === semItem.mes_chave)
    );

    const inputBusca = document.getElementById('modalSemanaBusca');
    if (inputBusca) inputBusca.value = '';
    renderizarTabelaModalSemana(itensModalSemanaAtual);

    modal.classList.add('active');
};

function renderizarTabelaModalSemana(itens) {
    const corpo = document.getElementById('modalSemanaCorpo');
    const contador = document.getElementById('modalSemanaContador');
    if (!corpo) return;

    corpo.innerHTML = '';
    if (contador) contador.textContent = `${itens.length} ordens`;

    if (!itens.length) {
        corpo.innerHTML = `<tr><td colspan="9" class="p-6 text-center text-slate-500">Nenhuma ordem encontrada para esta semana.</td></tr>`;
        return;
    }

    itens.forEach(it => {
        const pedStr   = it.cd_pedido || it.pedido || '-';
        const cliStr   = it.cliente_apelido || it.cliente_nome || it.cliente || '-';
        const refStr   = it.cd_referencia || it.referencia || '-';
        const descStr  = it.ds_produto || it.descricao || '-';
        const kvaNum   = Number(it.potencia_kva || 0);
        const kvaStr   = kvaNum > 0 ? kvaNum.toLocaleString('pt-BR') + ' kVA' : '-';
        const linhaStr = it.linha || 'TPD';
        const dtProgFmt = it.data_programada ? new Date(it.data_programada + 'T00:00:00').toLocaleDateString('pt-BR') : '-';
        const diasAtr  = it.dias_atraso_individual || 0;
        const nsFmt    = it.nr_serie_formatado || '—';
        const itJson   = JSON.stringify(it).replace(/"/g, '&quot;');

        let badgeLinhaHtml = `<span class="badge-linha-tpd">TPD</span>`;
        if (linhaStr === 'TPM') badgeLinhaHtml = `<span class="badge-linha-tpm">TPM</span>`;
        else if (linhaStr === 'TPS') badgeLinhaHtml = `<span class="badge-linha-tps">TPS Seco</span>`;

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td class="font-mono text-emerald-400 font-bold">${pedStr}</td>
            <td class="font-medium text-white">${cliStr}</td>
            <td class="text-center">
                ${nsFmt !== '—' 
                    ? `<button type="button" class="badge-ns-btn" onclick="abrirModalFluxoAtraso(${itJson})" title="Ver apontamentos das 10 células">
                         <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg>
                         ${nsFmt}
                       </button>` 
                    : `<span class="text-slate-500 font-mono text-xs">—</span>`
                }
            </td>
            <td class="font-mono text-slate-300">${refStr}</td>
            <td class="text-xs text-slate-300" title="${descStr}">${descStr.length > 42 ? descStr.slice(0, 40) + '...' : descStr}</td>
            <td class="font-mono text-right text-slate-200">${kvaStr}</td>
            <td class="text-center">${badgeLinhaHtml}</td>
            <td class="text-center font-mono text-xs text-slate-300">${dtProgFmt}</td>
            <td class="text-center font-mono font-bold text-[#ff6b6b]">+${diasAtr} d</td>
        `;
        corpo.appendChild(tr);
    });
}

window.filtrarTabelaModalSemana = function() {
    const busca = (document.getElementById('modalSemanaBusca')?.value || '').toLowerCase().trim();
    if (!busca) {
        renderizarTabelaModalSemana(itensModalSemanaAtual);
        return;
    }
    const filtrados = itensModalSemanaAtual.filter(it => {
        const texto = `${it.cd_pedido} ${it.cliente_apelido} ${it.cliente_nome} ${it.cd_referencia} ${it.ds_produto} ${it.linha} ${it.nr_serie_formatado}`.toLowerCase();
        return texto.includes(busca);
    });
    renderizarTabelaModalSemana(filtrados);
};

window.fecharModalSemana = function() {
    const modal = document.getElementById('modalSemanaDetalhes');
    if (modal) modal.classList.remove('active');
};

window.irParaTabelaPrincipalFiltrada = function() {
    fecharModalSemana();
    const tabelaElem = document.getElementById('tabelaAtrasos');
    if (tabelaElem) {
        tabelaElem.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
};

window.imprimirLayoutAtraso = function() {
    window.print();
};

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        window.fecharModalFluxo();
        window.fecharModalSemana();
    }
});
</script>

<?php layoutFooter(); ?>
