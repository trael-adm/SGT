<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/retrabalho-relatorio-dados.php';

requireAcessoModulo('retrabalho');

$pdo  = getDB();
$base = defined('APP_URL') ? APP_URL : '';

// ─── Permissão de Visualização Financeira (R$) ───────────────────────────────
$podeVerValores = isAdmin() || podeEditar('ret.cus') || hasAcesso('ret.cus') || podeEditar('tab:retrabalho');
$podeGravar = isAdmin() || podeEditar('ret.cus');

// ─── Cálculo dos Dados do Relatório (KPIs e Séries de Gráficos) ──────────────
// Toda a lógica de cálculo mora em calcularRelatorioRetrabalho() (includes/
// retrabalho-relatorio-dados.php) para ser reutilizada também pela tela de
// Análise de Custos e pelo endpoint de atualização via AJAX.
$dadosRelatorio = calcularRelatorioRetrabalho($pdo, $_GET, $podeVerValores);
extract($dadosRelatorio);

$pageTitle = 'Dashboard de Custos de Retrabalho';
layoutHeader($pageTitle);
?>

<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@500;700;800&display=swap">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
/* ==========================================================================
   DASHBOARD DE CUSTOS DE RETRABALHO
   ========================================================================== */
:root {
    --rep-primary: #133a27;
    --rep-primary-light: #1e5a3d;
    --rep-gold: #e8a020;
    --rep-gold-hover: #cf8b13;
    --rep-red: #dc2626;
    --rep-border: #e2e8f0;
    --rep-text-main: #0f172a;
    --rep-text-muted: #64748b;
}

/* Container ocupa a altura toda disponivel e os graficos (flex:1) absorvem o
   espaco restante, para caber tudo sem barra de rolagem, mantendo o mesmo
   tamanho original de fontes/paddings do restante da tela. */
.app-content:has(.report-container),
body:has(.report-container) .app-content {
    overflow: hidden !important;
    padding: 14px 20px !important;
    display: flex;
    flex-direction: column;
}

.report-container {
    max-width: 1760px;
    width: 100%;
    margin: 0 auto;
    padding: 0;
    font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
    color: var(--rep-text-main);
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
    height: 100%;
    gap: 20px;
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
    margin-bottom: 0;
    flex-shrink: 0;
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

.mo-dropdown-wrap {
    position: relative;
}

.mo-dropdown-panel {
    display: none;
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    z-index: 60;
    width: 420px;
    max-width: 90vw;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 12px 32px rgba(0,0,0,0.14);
}

.mo-dropdown-panel.open {
    display: block;
}

.mo-dropdown-status {
    margin-top: 10px;
    font-size: 0.75rem;
    color: #64748b;
    text-align: right;
    min-height: 16px;
}

.params-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 16px;
}

.param-input-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px;
    transition: border-color 0.2s ease;
}

.param-input-box:focus-within {
    border-color: var(--rep-primary);
    background: #ffffff;
}

.param-input-box label {
    display: block;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: #475569;
    margin-bottom: 6px;
}

.param-input-wrapper {
    display: flex;
    align-items: center;
    gap: 8px;
}

.param-input-wrapper .prefix {
    font-family: 'JetBrains Mono', monospace;
    font-weight: 700;
    color: #64748b;
    font-size: 0.9rem;
}

.param-input-wrapper input {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 7px 10px;
    font-family: 'JetBrains Mono', monospace;
    font-size: 1rem;
    font-weight: 700;
    color: #0f172a;
    background: #ffffff;
    outline: none;
    transition: all 0.2s ease;
}

.param-input-wrapper input:focus {
    border-color: var(--rep-primary);
    box-shadow: 0 0 0 3px rgba(19, 58, 39, 0.12);
}

.param-helper {
    font-size: 0.72rem;
    color: #64748b;
    margin-top: 6px;
    line-height: 1.4;
}

/* ─── Barra de Filtros Avançados & Presets de Data ────────────────────────── */
.filter-card {
    background: #ffffff;
    border: 1px solid var(--rep-border);
    border-radius: 14px;
    padding: 18px 22px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    margin-bottom: 0;
    flex-shrink: 0;
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
    margin-bottom: 0;
    flex-shrink: 0;
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
    margin-bottom: 0;
    flex: 1;
    min-height: 0;
}

.chart-card {
    background: #ffffff;
    border: 1px solid var(--rep-border);
    border-radius: 14px;
    padding: 22px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    display: flex;
    flex-direction: column;
    min-height: 0;
    overflow: hidden;
}

.chart-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
    flex-shrink: 0;
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
    flex: 1;
    min-height: 0;
    width: 100%;
    height: 100%;
}
</style>

<div class="report-container">
    <!-- Top Bar -->
    <div class="report-top-bar">
        <div class="report-title-wrap">
            <div class="report-icon-badge">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                    <rect x="3" y="3" width="18" height="18" rx="2"/>
                    <path d="M7 16V10M12 16V7M17 16V13"/>
                </svg>
            </div>
            <div>
                <h1>Dashboard de Custos de Retrabalho</h1>
                <p>Indicadores gerenciais e curva de impacto financeiro por tipo de reprova</p>
            </div>
        </div>

        <div class="report-nav-actions">
            <div class="mo-dropdown-wrap">
                <button type="button" class="btn-report-action" onclick="toggleMaoObraDropdown()">Mão de Obra</button>
                <div class="mo-dropdown-panel" id="maoObraDropdown">
                    <form id="formMaoObra">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                        <div class="params-grid">
                            <div class="param-input-box">
                                <label for="mo_custo_hora_homem">Custo Médio da Hora-Homem (R$/h)</label>
                                <div class="param-input-wrapper">
                                    <span class="prefix">R$</span>
                                    <input type="text" id="mo_custo_hora_homem" name="custo_hora_homem"
                                           value="<?= number_format($custoHoraHomem, 2, ',', '.') ?>"
                                           onchange="autoSalvarParametrosMaoObra()"
                                           <?= !$podeGravar ? 'readonly disabled' : '' ?> required>
                                </div>
                                <div class="param-helper">Fórmula aplicada: <strong>Custo MO = (Minutos ÷ 60) × Taxa R$/h</strong>.</div>
                            </div>
                            <div class="param-input-box">
                                <label for="mo_horas_trabalho_dia">Horas Padrão por Dia Útil (h/dia)</label>
                                <div class="param-input-wrapper">
                                    <input type="text" id="mo_horas_trabalho_dia" name="horas_trabalho_dia"
                                           value="<?= number_format($horasTrabalhoDia, 2, ',', '.') ?>"
                                           onchange="autoSalvarParametrosMaoObra()"
                                           <?= !$podeGravar ? 'readonly disabled' : '' ?> required>
                                    <span class="prefix">h/dia</span>
                                </div>
                                <div class="param-helper">Base diária de expediente da fábrica para métricas de produtividade.</div>
                            </div>
                        </div>
                        <div class="mo-dropdown-status" id="moDropdownStatus"></div>
                    </form>
                </div>
            </div>
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
                    <span class="info-pill-val" id="infoPillCustoHora">R$ <?= number_format($custoHoraHomem, 2, ',', '.') ?>/h</span>
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
                <label for="setor">Setor Causador</label>
                <select id="setor" name="setor" class="filter-input">
                    <option value="">Todos os Setores</option>
                    <?php foreach ($setoresCausadoresDisponiveis as $sKey => $sLabel): ?>
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
    <div class="kpi-grid" id="kpiGrid">
        <?php include __DIR__ . '/../../includes/retrabalho-relatorio-kpis.php'; ?>
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
                    <?= $podeVerValores ? 'Impacto Financeiro por Tipo de Reprova (Pareto / R$)' : 'Horas de Reparo por Tipo de Reprova (h)' ?>
                </h3>
            </div>
            <div class="chart-wrapper">
                <canvas id="chartReprovas"></canvas>
            </div>
        </div>

        <!-- Gráfico 2: Por Setor Causador -->
        <div class="chart-card">
            <div class="chart-card-header">
                <h3>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-emerald-700">
                        <rect x="3" y="3" width="7" height="7"/>
                        <rect x="14" y="3" width="7" height="7"/>
                        <rect x="14" y="14" width="7" height="7"/>
                        <rect x="3" y="14" width="7" height="7"/>
                    </svg>
                    Distribuição por Setor Causador
                </h3>
            </div>
            <div class="chart-wrapper">
                <canvas id="chartSetores"></canvas>
            </div>
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
// Instâncias guardadas em escopo de módulo para poderem ser destruídas e
// recriadas quando os dados são atualizados via AJAX (ver atualizarRelatorioViaAjax).
const REP_PODE_VER_VALORES = <?= $podeVerValores ? 'true' : 'false' ?>;
let graficoReprovas = null;
let graficoSetores = null;

// Gradiente 30/70 (Pareto): os "poucos vitais" que somam até ~70% do impacto
// acumulado ganham um vermelho intenso e decrescente; o restante ("muitos
// triviais") esmaece para tons claros/neutros.
function corGradientePareto(cumPercent, cumPercentAnterior, borda) {
    const LIMIAR = 70;
    if (cumPercentAnterior < LIMIAR) {
        const t = Math.max(0, Math.min(1, cumPercentAnterior / LIMIAR));
        const l = 30 + t * 22; // 30% -> 52% de luminosidade (vermelho bem forte)
        return `hsl(0, 88%, ${borda ? Math.max(l - 12, 20) : l}%)`;
    }
    const t = Math.max(0, Math.min(1, (cumPercentAnterior - LIMIAR) / (100 - LIMIAR)));
    const l = 78 + t * 15; // 78% -> 93% de luminosidade (esmaecido)
    return `hsl(0, 25%, ${borda ? Math.max(l - 12, 55) : l}%)`;
}

// 1. Gráfico Reprovas (Horizontal Bar Chart para legibilidade perfeita dos textos)
function renderGraficoReprovas(labels, acumuladoR, serieValores) {
    const ctxR = document.getElementById('chartReprovas');
    if (!ctxR) return;

    const coresBarras = acumuladoR.map((v, i) => corGradientePareto(v, i === 0 ? 0 : acumuladoR[i - 1], false));
    const coresBordas = acumuladoR.map((v, i) => corGradientePareto(v, i === 0 ? 0 : acumuladoR[i - 1], true));

    if (graficoReprovas) {
        graficoReprovas.destroy();
    }

    graficoReprovas = new Chart(ctxR, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: REP_PODE_VER_VALORES ? 'Custo Total (R$)' : 'Horas de Reparo (h)',
                data: serieValores,
                backgroundColor: coresBarras,
                borderColor: coresBordas,
                borderWidth: 2,
                borderRadius: 6,
                hoverBackgroundColor: coresBarras,
                hoverBorderColor: coresBordas,
                hoverBorderWidth: 2.5
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
                            return (REP_PODE_VER_VALORES ? 'R$ ' : '') + ctx.raw.toLocaleString('pt-BR', {minimumFractionDigits: 2}) + (REP_PODE_VER_VALORES ? '' : ' h');
                        }
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    grid: { color: '#f1f5f9' },
                    ticks: {
                        callback: function(v) { return (REP_PODE_VER_VALORES ? 'R$ ' : '') + v.toLocaleString('pt-BR'); }
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
function renderGraficoSetores(labels, serieValores, colors) {
    const ctxS = document.getElementById('chartSetores');
    if (!ctxS) return;

    if (graficoSetores) {
        graficoSetores.destroy();
    }

    graficoSetores = new Chart(ctxS, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: serieValores,
                backgroundColor: colors,
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
                            return ' ' + ctx.label + ': ' + (REP_PODE_VER_VALORES ? 'R$ ' : '') + val.toLocaleString('pt-BR', {minimumFractionDigits: 2}) + (REP_PODE_VER_VALORES ? '' : ' h');
                        }
                    }
                }
            }
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    renderGraficoReprovas(
        <?= json_encode($chartReprovaLabels) ?>,
        <?= json_encode($chartReprovaAcumulado) ?>,
        <?= json_encode($podeVerValores ? $chartReprovaCustos : $chartReprovaHoras) ?>
    );
    renderGraficoSetores(
        <?= json_encode($chartSetorLabels) ?>,
        <?= json_encode($podeVerValores ? $chartSetorCustos : $chartSetorHoras) ?>,
        <?= json_encode($chartSetorColors) ?>
    );
});

function toggleMaoObraDropdown() {
    const painel = document.getElementById('maoObraDropdown');
    painel.classList.toggle('open');
}

document.addEventListener('click', function (evento) {
    const wrap = document.querySelector('.mo-dropdown-wrap');
    const painel = document.getElementById('maoObraDropdown');
    if (wrap && painel && !wrap.contains(evento.target)) {
        painel.classList.remove('open');
    }
});

// ─── Atualização ao Vivo do Dashboard (sem reload de página) ──────────────────
// Busca os dados recalculados (KPIs e gráficos) no endpoint
// api/retrabalho-relatorio-atualizar.php, usando os MESMOS filtros já ativos
// na URL, e substitui apenas o conteúdo dinâmico da tela.
async function atualizarRelatorioViaAjax() {
    const appBase = (typeof window.__APP_BASE === 'string') ? window.__APP_BASE : '<?= htmlspecialchars($base) ?>';
    const res = await fetch(appBase + '/api/retrabalho-relatorio-atualizar.php' + window.location.search, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await res.json();
    if (!data.sucesso) {
        throw new Error(data.erro || 'Falha ao atualizar o dashboard');
    }

    const infoPill = document.getElementById('infoPillCustoHora');
    if (infoPill) {
        infoPill.textContent = 'R$ ' + Number(data.custo_hora_homem).toLocaleString('pt-BR', { minimumFractionDigits: 2 }) + '/h';
    }

    document.getElementById('kpiGrid').innerHTML = data.html.kpis;

    renderGraficoReprovas(
        data.grafico_reprovas.labels,
        data.grafico_reprovas.acumulado,
        data.grafico_reprovas.pode_ver_valores ? data.grafico_reprovas.custos : data.grafico_reprovas.horas
    );
    renderGraficoSetores(
        data.grafico_setores.labels,
        data.grafico_reprovas.pode_ver_valores ? data.grafico_setores.custos : data.grafico_setores.horas,
        data.grafico_setores.colors
    );
}

async function autoSalvarParametrosMaoObra() {
    const statusEl = document.getElementById('moDropdownStatus');
    statusEl.textContent = 'Salvando...';

    const form = document.getElementById('formMaoObra');
    const formData = new FormData(form);
    const appBase = (typeof window.__APP_BASE === 'string') ? window.__APP_BASE : '<?= htmlspecialchars($base) ?>';

    try {
        const res = await fetch(appBase + '/api/retrabalho-custos-salvar.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();
        if (data.sucesso) {
            statusEl.textContent = 'Atualizando tela...';
            try {
                await atualizarRelatorioViaAjax();
                statusEl.textContent = 'Salvo!';
            } catch (err) {
                statusEl.textContent = 'Salvo, mas falhou ao atualizar a tela.';
            }
        } else {
            statusEl.textContent = data.erro || 'Falha ao salvar';
        }
    } catch (err) {
        statusEl.textContent = 'Erro de conexão';
    }
}
</script>

<?php layoutFooter(); ?>
