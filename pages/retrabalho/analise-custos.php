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

// ─── Cálculo dos Dados do Relatório (Análises Detalhadas) ────────────────────
// Toda a lógica de cálculo mora em calcularRelatorioRetrabalho() (includes/
// retrabalho-relatorio-dados.php) para ser reutilizada também pelo Dashboard
// de Custos e pelo endpoint de atualização via AJAX.
$dadosRelatorio = calcularRelatorioRetrabalho($pdo, $_GET, $podeVerValores);
extract($dadosRelatorio);

// ─── EXPORTAÇÃO CSV ──────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="analise_custos_retrabalho_' . date('Ymd_His') . '.csv"');

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

$pageTitle = 'Análise de Custos de Retrabalho';
layoutHeader($pageTitle);
?>

<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@500;700;800&display=swap">

<style>
/* ==========================================================================
   ANÁLISE DE CUSTOS DE RETRABALHO
   ========================================================================== */
:root {
    --rep-primary: #133a27;
    --rep-primary-light: #1e5a3d;
    --rep-gold: #e8a020;
    --rep-border: #e2e8f0;
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

/* ─── Cards Seletores de Painel ──────────────────────────────────────────── */
.selector-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.selector-card {
    background: #ffffff;
    border: 2px solid var(--rep-border);
    border-radius: 14px;
    padding: 18px 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    cursor: pointer;
    text-align: left;
    font-family: inherit;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 14px;
}

.selector-card:hover {
    border-color: var(--rep-primary-light);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.06);
}

.selector-card.active {
    border-color: var(--rep-primary);
    background: #f0fdf4;
    box-shadow: 0 4px 12px rgba(19, 58, 39, 0.12);
}

.selector-card-icon {
    width: 40px;
    height: 40px;
    min-width: 40px;
    border-radius: 10px;
    background: #f1f5f9;
    color: var(--rep-primary);
    display: flex;
    align-items: center;
    justify-content: center;
}

.selector-card.active .selector-card-icon {
    background: var(--rep-primary);
    color: #ffffff;
}

.selector-card-label {
    font-size: 0.92rem;
    font-weight: 700;
    color: #1e293b;
}

.selector-card-count {
    font-size: 0.76rem;
    color: var(--rep-text-muted);
    margin-top: 2px;
}

/* ─── Tabelas Analíticas ─────────────────────────────────────────────────── */
.analise-painel {
    display: none;
}

.analise-painel.ativo {
    display: block;
}

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
                <h1>Análise de Custos de Retrabalho</h1>
                <p>Tabelas detalhadas por tipo de reprova, materiais consumidos e relação por transformador</p>
            </div>
        </div>

        <div class="report-nav-actions">
            <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>" class="btn-report-action">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                Exportar CSV
            </a>
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

    <!-- Cards Seletores de Painel -->
    <div class="selector-cards">
        <button type="button" class="selector-card active" data-alvo="painelReprovas" onclick="selecionarPainel(this)">
            <div class="selector-card-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 16 14"/>
                </svg>
            </div>
            <div>
                <div class="selector-card-label">Análise por Tipo de Reprova</div>
                <div class="selector-card-count"><?= count($analiseReprovas) ?> causas identificadas</div>
            </div>
        </button>

        <button type="button" class="selector-card" data-alvo="painelMateriais" onclick="selecionarPainel(this)">
            <div class="selector-card-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                </svg>
            </div>
            <div>
                <div class="selector-card-label">Consumo de Peças & Materiais</div>
                <div class="selector-card-count"><?= count($todosMateriaisConsumidos) ?> itens distintos</div>
            </div>
        </button>

        <button type="button" class="selector-card" data-alvo="painelRegistros" onclick="selecionarPainel(this)">
            <div class="selector-card-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="8" y1="6" x2="21" y2="6"/>
                    <line x1="8" y1="12" x2="21" y2="12"/>
                    <line x1="8" y1="18" x2="21" y2="18"/>
                    <line x1="3" y1="6" x2="3.01" y2="6"/>
                    <line x1="3" y1="12" x2="3.01" y2="12"/>
                    <line x1="3" y1="18" x2="3.01" y2="18"/>
                </svg>
            </div>
            <div>
                <div class="selector-card-label">Relação Analítica por Transformador</div>
                <div class="selector-card-count"><?= count($registrosProcessados) ?> lançamentos</div>
            </div>
        </button>
    </div>

    <!-- Painel 1: Resumo Analítico por Tipo de Reprova -->
    <div id="painelReprovas" class="analise-painel ativo">
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
                        <?php include __DIR__ . '/../../includes/retrabalho-relatorio-tabela-reprovas-tbody.php'; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Painel 2: Consumo Físico e Financeiro de Materiais -->
    <div id="painelMateriais" class="analise-painel">
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
                        <?php include __DIR__ . '/../../includes/retrabalho-relatorio-tabela-materiais-tbody.php'; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Painel 3: Relação Detalhada com Botão de Drilldown -->
    <div id="painelRegistros" class="analise-painel">
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
                        <?php include __DIR__ . '/../../includes/retrabalho-relatorio-tabela-registros-tbody.php'; ?>
                    </tbody>
                </table>
            </div>
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

// ─── Seleção de Painel (Análise / Materiais / Relação) ────────────────────────
function selecionarPainel(botao) {
    document.querySelectorAll('.selector-card').forEach(c => c.classList.remove('active'));
    botao.classList.add('active');

    document.querySelectorAll('.analise-painel').forEach(p => p.classList.remove('ativo'));
    document.getElementById(botao.dataset.alvo).classList.add('ativo');
}

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
