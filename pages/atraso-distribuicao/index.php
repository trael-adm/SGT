<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/boletim-atraso.php';

requireLogin();

$usuario = currentUser();
$base    = defined('APP_URL') ? APP_URL : '';

// ─── Parâmetros de Filtro ───────────────────────────────────────────────────
$snapshotsDisponiveis = boletimListarSnapshotsDisponiveis();
$ultimoSnapshotData = !empty($snapshotsDisponiveis) ? (string) array_key_first($snapshotsDisponiveis) : date('Y-m-d');

$snapshotSel = trim((string) ($_GET['snapshot'] ?? $ultimoSnapshotData));
if (!isset($snapshotsDisponiveis[$snapshotSel]) && !empty($snapshotsDisponiveis)) {
    $snapshotSel = (string) array_key_first($snapshotsDisponiveis);
}

// Data de corte: padrão é a data do snapshot menos 1 dia ou a data máxima do snapshot
$dataCorte = trim((string) ($_GET['data_corte'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataCorte)) {
    // Se a data do snapshot for 2026-08-25, o último dia útil de produção lançado é 2026-08-24
    $dataCorte = date('Y-m-d', strtotime($snapshotSel . ' -1 day'));
    if ((int) date('N', strtotime($dataCorte)) > 5) {
        $dataCorte = boletimAjustarDataFimDeSemanaParaSexta($dataCorte);
    }
}

// Filtro de Meses selecionados (array)
$mesesFiltroRaw = $_GET['meses'] ?? null;
$mesesFiltro = [];
if (is_array($mesesFiltroRaw)) {
    foreach ($mesesFiltroRaw as $m) {
        $mClean = trim((string) $m);
        if (preg_match('/^\d{4}-\d{2}$/', $mClean)) {
            $mesesFiltro[] = $mClean;
        }
    }
} elseif (is_string($mesesFiltroRaw) && $mesesFiltroRaw !== '') {
    $parts = explode(',', $mesesFiltroRaw);
    foreach ($parts as $p) {
        $pClean = trim($p);
        if (preg_match('/^\d{4}-\d{2}$/', $pClean)) {
            $mesesFiltro[] = $pClean;
        }
    }
}

// ─── Carrega Métricas Consolidadas ──────────────────────────────────────────
$resultado = boletimCalcularMetricasAtraso($dataCorte, $mesesFiltro, $snapshotSel);
$metricas  = $resultado['sucesso'] ? $resultado : null;
$erroMsg   = !$resultado['sucesso'] ? ($resultado['erro'] ?? 'Erro ao processar dados de atraso.') : null;

layoutHeader('Atraso Distribuição');
?>

<style>
/* ─── Estilos Personalizados do Dashboard de Atraso (Dark Theme & Red Accent) ─── */
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
    font-size: 0.85rem;
    font-weight: 600;
    color: #cbd5e1;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-top: 6px;
}

.atraso-sub {
    font-size: 0.75rem;
    color: var(--atraso-text-dim);
    margin-top: 4px;
}

.atraso-hero-date {
    font-size: 2.25rem;
    font-weight: 800;
    color: #ffffff;
    line-height: 1.1;
    letter-spacing: -0.02em;
}

.atraso-filter-select, .atraso-filter-input {
    background-color: #0f1219;
    border: 1px solid #334155;
    color: #f1f5f9;
    padding: 7px 12px;
    border-radius: 6px;
    font-size: 0.85rem;
    outline: none;
    transition: border-color 0.15s ease;
}

.atraso-filter-select:focus, .atraso-filter-input:focus {
    border-color: var(--atraso-red);
    box-shadow: 0 0 0 2px var(--atraso-red-glow);
}

.atraso-pill-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid #334155;
    background-color: #0f1219;
    color: #cbd5e1;
    transition: all 0.15s ease;
}

.atraso-pill-btn.active {
    background-color: rgba(230, 57, 70, 0.18);
    border-color: var(--atraso-red);
    color: #ff8585;
}

.atraso-pill-btn:hover {
    border-color: var(--atraso-red-light);
}

.badge-linha {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
}
.badge-mono { background-color: rgba(59, 130, 246, 0.2); color: #93c5fd; border: 1px solid rgba(59, 130, 246, 0.3); }
.badge-conv { background-color: rgba(230, 57, 70, 0.2); color: #fca5a5; border: 1px solid rgba(230, 57, 70, 0.3); }
.badge-jc   { background-color: rgba(234, 179, 8, 0.2); color: #fde047; border: 1px solid rgba(234, 179, 8, 0.3); }

/* Tabela analítica */
.atraso-table-wrapper {
    background: var(--atraso-card-bg);
    border: 1px solid var(--atraso-card-border);
    border-radius: 10px;
    overflow: hidden;
}

.atraso-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.84rem;
}

.atraso-table th {
    background: #0f1219;
    color: #94a3b8;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.72rem;
    letter-spacing: 0.05em;
    padding: 12px 14px;
    border-bottom: 1px solid var(--atraso-card-border);
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
</style>

<div class="atraso-dashboard">

    <!-- Top Bar: Título e Status de Sincronização -->
    <div class="flex flex-wrap items-center justify-between gap-4 pb-5 border-b border-[#262c3d] mb-6">
        <div>
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center justify-center p-2 rounded-lg bg-[#e63946]/10 text-[#e63946] border border-[#e63946]/30">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 15"/><path d="M19 19l2 2"/>
                    </svg>
                </span>
                <div>
                    <h1 class="text-2xl font-bold text-white tracking-tight">Atraso Distribuição</h1>
                    <p class="text-xs text-[#94a3b8] mt-0.5">
                        Relação de peças do Plano Mestre sem apontamento final no Laboratório × Média de Produção Diária
                    </p>
                </div>
            </div>
        </div>

        <!-- Ações do Cabeçalho -->
        <div class="flex items-center gap-3">
            <?php if ($metricas): ?>
            <span class="text-xs text-[#94a3b8] bg-[#0f1219] px-3 py-1.5 rounded-md border border-[#262c3d]">
                <strong class="text-slate-200">Snapshot:</strong> <?= htmlspecialchars($metricas['snapshot_info']['basename'] ?? '') ?> 
                <span class="text-[#64748b] ml-1">(<?= number_format($metricas['total_ordens'], 0, ',', '.') ?> ordens em atraso)</span>
            </span>
            <?php endif; ?>

            <button type="button" onclick="exportarTabelaCSV()" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-[#e63946] hover:bg-[#d62828] text-xs font-semibold text-white rounded-md shadow-sm transition-colors" title="Exportar Relação em CSV">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Exportar CSV
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
    <!-- ─── Filtros e Data Principal (Conforme Painel PowerBI) ────────────────── -->
    <form method="GET" id="filtroForm" class="mb-6">
        <input type="hidden" name="snapshot" value="<?= htmlspecialchars($snapshotSel) ?>">
        <input type="hidden" name="meses" id="inputMesesFiltro" value="<?= htmlspecialchars(implode(',', $mesesFiltro)) ?>">

        <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-stretch">
            
            <!-- Hero Date Callout (Ex: "24 de agosto") -->
            <div class="md:col-span-3 atraso-card flex flex-col justify-center items-center text-center p-4">
                <span class="text-[0.72rem] font-bold uppercase tracking-widest text-[#e63946] mb-1">Data de Referência</span>
                <div class="atraso-hero-date capitalize">
                    <?= htmlspecialchars($metricas['data_corte_formatada']) ?>
                </div>
                <span class="text-xs text-[#94a3b8] mt-1">
                    <?= date('Y', strtotime($dataCorte)) ?> &bull; <?= $metricas['dias_trabalhados'] ?> dias úteis trabalhados
                </span>
            </div>

            <!-- Controles de Filtro -->
            <div class="md:col-span-9 atraso-card flex flex-col justify-between gap-3 p-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    
                    <!-- Seletor Data de Corte -->
                    <div>
                        <label class="block text-xs font-medium text-[#94a3b8] mb-1">Data de Corte (Atraso até):</label>
                        <input type="date" name="data_corte" value="<?= htmlspecialchars($dataCorte) ?>" 
                               class="atraso-filter-input w-full"
                               onchange="document.getElementById('filtroForm').submit();">
                    </div>

                    <!-- Seletor de Snapshot (se houver mais de 1) -->
                    <div>
                        <label class="block text-xs font-medium text-[#94a3b8] mb-1">Arquivo Snapshot (PCP):</label>
                        <select name="snapshot" class="atraso-filter-select w-full" onchange="document.getElementById('filtroForm').submit();">
                            <?php foreach ($snapshotsDisponiveis as $snapData => $snapInfo): ?>
                            <option value="<?= htmlspecialchars($snapData) ?>" <?= $snapData === $snapshotSel ? 'selected' : '' ?>>
                                <?= htmlspecialchars($snapInfo['basename']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Botão de Limpar / Reset -->
                    <div class="flex items-end">
                        <a href="index.php" class="w-full text-center px-3 py-2 bg-[#0f1219] hover:bg-[#1e2433] text-xs font-semibold text-[#cbd5e1] rounded-md border border-[#334155] transition-colors">
                            Redefinir Filtros
                        </a>
                    </div>
                </div>

                <!-- Filtro Mês Referente ao Atraso (Multi-select pills) -->
                <div class="pt-2 border-t border-[#262c3d] flex flex-wrap items-center gap-2">
                    <span class="text-xs font-semibold text-[#cbd5e1] mr-1">Mês referente ao atraso:</span>
                    
                    <button type="button" onclick="toggleTodosMeses()" class="atraso-pill-btn <?= empty($mesesFiltro) ? 'active' : '' ?>">
                        Todos os Meses
                    </button>

                    <?php foreach ($metricas['meses_disponiveis'] as $mKey => $mRotulo): 
                        $isSelected = empty($mesesFiltro) || in_array($mKey, $mesesFiltro, true);
                    ?>
                    <button type="button" 
                            onclick="toggleMesFiltro('<?= htmlspecialchars($mKey) ?>')" 
                            class="atraso-pill-btn <?= $isSelected ? 'active' : '' ?>"
                            data-mes-key="<?= htmlspecialchars($mKey) ?>">
                        <?= htmlspecialchars($mRotulo) ?>
                    </button>
                    <?php endforeach; ?>
                </div>

            </div>

        </div>
    </form>

    <!-- ─── Grid Principal de Cards e Gráficos (Fiel ao Layout PowerBI) ─────── -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 mb-6">
        
        <!-- Coluna Esquerda: Gráfico "Peças atrasadas por mês" (4 cols) -->
        <div class="lg:col-span-4 flex flex-col">
            <div class="atraso-card flex-1 flex flex-col justify-between p-5">
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-sm font-bold uppercase tracking-wider text-white">Peças atrasadas por mês</h3>
                        <span class="text-xs font-semibold text-[#e63946] bg-[#e63946]/10 px-2 py-0.5 rounded">
                            Total: <?= number_format($metricas['pecas_atraso']['TOTAL'], 0, ',', '.') ?>
                        </span>
                    </div>
                    <p class="text-xs text-[#94a3b8] mb-4">Distribuição das peças em atraso pelo mês original da programação</p>
                </div>
                
                <div class="relative h-[240px] w-full flex items-center justify-center">
                    <canvas id="chartPecasMes"></canvas>
                </div>

                <div class="mt-4 pt-3 border-t border-[#262c3d] flex justify-between items-center text-xs text-[#94a3b8]">
                    <span>MÊS</span>
                    <span class="font-bold text-white uppercase tracking-wider">QUANTIDADE</span>
                </div>
            </div>
        </div>

        <!-- Coluna Direita: Matriz de 8 Cartões de KPI (8 cols) -->
        <div class="lg:col-span-8 flex flex-col gap-4">
            
            <!-- Linha 1: Dias de Atraso por Linha (3 cards) -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                
                <!-- Atraso Monofásico -->
                <div class="atraso-card text-center flex flex-col justify-center items-center py-4">
                    <div class="atraso-val-big">
                        <?= number_format($metricas['atraso_dias']['MONOFASICO'], 2, ',', '.') ?>
                    </div>
                    <div class="atraso-lbl">Atraso Monofásico</div>
                    <div class="atraso-sub">
                        Média: <?= number_format($metricas['media_diaria']['MONOFASICO'], 1, ',', '.') ?> un/dia
                    </div>
                </div>

                <!-- Atraso Convencional -->
                <div class="atraso-card text-center flex flex-col justify-center items-center py-4">
                    <div class="atraso-val-big">
                        <?= number_format($metricas['atraso_dias']['CONVENCIONAL'], 2, ',', '.') ?>
                    </div>
                    <div class="atraso-lbl">Atraso Convencional</div>
                    <div class="atraso-sub">
                        Média: <?= number_format($metricas['media_diaria']['CONVENCIONAL'], 1, ',', '.') ?> un/dia
                    </div>
                </div>

                <!-- Atraso JC-TRIF -->
                <div class="atraso-card text-center flex flex-col justify-center items-center py-4">
                    <div class="atraso-val-big">
                        <?= number_format($metricas['atraso_dias']['JC_TRIF'], 2, ',', '.') ?>
                    </div>
                    <div class="atraso-lbl">Atraso JC-TRIF</div>
                    <div class="atraso-sub">
                        Média: <?= number_format($metricas['media_diaria']['JC_TRIF'], 1, ',', '.') ?> un/dia
                    </div>
                </div>

            </div>

            <!-- Linha 2: Peças em Atraso por Linha (3 cards) -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                
                <!-- Atraso Peças Monofásico -->
                <div class="atraso-card text-center flex flex-col justify-center items-center py-4">
                    <div class="atraso-val-medium">
                        <?= number_format($metricas['pecas_atraso']['MONOFASICO'], 0, ',', '.') ?>
                    </div>
                    <div class="atraso-lbl">Atraso Peças Monofásico</div>
                    <div class="atraso-sub">Transformadores 1F / ENR / JC-1F</div>
                </div>

                <!-- Atraso Peças Convencional -->
                <div class="atraso-card text-center flex flex-col justify-center items-center py-4">
                    <div class="atraso-val-medium">
                        <?= number_format($metricas['pecas_atraso']['CONVENCIONAL'], 0, ',', '.') ?>
                    </div>
                    <div class="atraso-lbl">Atraso Peças Convencional</div>
                    <div class="atraso-sub">Transformadores EMP / EMP-LM 3F</div>
                </div>

                <!-- Atraso Peças JC-TRIF -->
                <div class="atraso-card text-center flex flex-col justify-center items-center py-4">
                    <div class="atraso-val-medium">
                        <?= number_format($metricas['pecas_atraso']['JC_TRIF'], 0, ',', '.') ?>
                    </div>
                    <div class="atraso-lbl">Atraso Peças JC-TRIF</div>
                    <div class="atraso-sub">Transformadores JC Trifásicos</div>
                </div>

            </div>

            <!-- Linha 3: Total Geral de Peças & Média Geral Simples (2 cards) -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                
                <!-- Atraso Peças Total -->
                <div class="atraso-card text-center flex flex-col justify-center items-center py-4 bg-gradient-to-b from-[#1a1e29] to-[#201c24] border-[#38262d]">
                    <div class="atraso-val-big text-[#ff5252]">
                        <?= number_format($metricas['pecas_atraso']['TOTAL'], 0, ',', '.') ?>
                    </div>
                    <div class="atraso-lbl text-white">Atraso Peças Total</div>
                    <div class="atraso-sub text-slate-400">Total acumulado de transformadores sem apontamento</div>
                </div>

                <!-- Média Geral -->
                <div class="atraso-card text-center flex flex-col justify-center items-center py-4 bg-gradient-to-b from-[#1a1e29] to-[#201c24] border-[#38262d]">
                    <div class="atraso-val-big text-[#ff5252]">
                        <?= number_format($metricas['atraso_dias']['MEDIA_GERAL'], 2, ',', '.') ?>
                    </div>
                    <div class="atraso-lbl text-white">Média Geral</div>
                    <div class="atraso-sub text-slate-400">Média simples dos dias de atraso (Mono + Conv + JC) / 3</div>
                </div>

            </div>

        </div>

    </div>

    <!-- ─── Gráfico Inferior: Evolução da "Média de dias em atraso" ─────────── -->
    <div class="atraso-card p-5 mb-6">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
            <div>
                <h3 class="text-base font-bold uppercase tracking-wider text-white">Média de dias em atraso</h3>
                <p class="text-xs text-[#94a3b8]">Evolução diária dos dias de atraso ao longo dos dias úteis trabalhados do mês</p>
            </div>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center gap-1.5 text-xs text-[#f87171] font-medium bg-[#e63946]/10 px-2.5 py-1 rounded-md border border-[#e63946]/20">
                    <span class="w-2.5 h-2.5 rounded-full bg-[#e63946]"></span>
                    Média Geral de Atraso (Dias)
                </span>
            </div>
        </div>

        <div class="relative h-[220px] w-full mt-2">
            <canvas id="chartEvolucaoAtraso"></canvas>
        </div>

        <div class="flex justify-between items-center mt-3 pt-2 border-t border-[#262c3d] text-xs text-[#94a3b8]">
            <span class="font-bold uppercase tracking-wider">DIAS</span>
            <span class="font-bold uppercase tracking-wider">MÊS DE REFERÊNCIA</span>
        </div>
    </div>

    <!-- ─── Tabela Analítica de Detalhes dos Itens em Atraso ────────────────── -->
    <div class="atraso-table-wrapper">
        <div class="p-4 border-b border-[#262c3d] flex flex-wrap items-center justify-between gap-4">
            <div>
                <h3 class="text-sm font-bold uppercase tracking-wider text-white">Relação Analítica de Ordens em Atraso</h3>
                <p class="text-xs text-[#94a3b8]">Lista detalhada de pedidos do Plano Mestre pendentes de apontamento no laboratório</p>
            </div>

            <!-- Filtros da Tabela -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative">
                    <input type="text" id="tabelaBusca" placeholder="Buscar pedido, cliente, projeto..." 
                           class="atraso-filter-input pl-8 pr-3 w-64 text-xs" oninput="filtrarTabelaDetalhes()">
                    <svg class="absolute left-2.5 top-2.5 text-slate-400" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                </div>

                <select id="tabelaFiltroLinha" class="atraso-filter-select text-xs" onchange="filtrarTabelaDetalhes()">
                    <option value="">Todas as Linhas</option>
                    <option value="MONOFASICO">Monofásico</option>
                    <option value="CONVENCIONAL">Convencional</option>
                    <option value="JC_TRIF">JC-TRIF</option>
                </select>
            </div>
        </div>

        <div class="overflow-x-auto max-h-[460px]">
            <table class="atraso-table" id="tabelaAtrasos">
                <thead>
                    <tr>
                        <th style="width: 75px;">Pedido</th>
                        <th>Cliente</th>
                        <th style="width: 110px;">Projeto</th>
                        <th>Descrição do Transformador</th>
                        <th style="width: 70px; text-align: right;">Potência</th>
                        <th style="width: 100px; text-align: center;">Linha</th>
                        <th style="width: 95px; text-align: center;">Data Prev.</th>
                        <th style="width: 85px; text-align: center;">Atraso</th>
                        <th style="width: 65px; text-align: right;">Qtd</th>
                    </tr>
                </thead>
                <tbody id="tabelaCorpo">
                    <?php foreach ($metricas['itens_detalhados'] as $idx => $it): ?>
                    <tr data-linha="<?= htmlspecialchars($it['linha']) ?>" 
                        data-texto="<?= htmlspecialchars(strtolower($it['pedido'] . ' ' . $it['cliente'] . ' ' . $it['referencia'] . ' ' . $it['descricao'])) ?>">
                        <td class="font-mono text-slate-300 font-medium"><?= htmlspecialchars($it['pedido']) ?></td>
                        <td class="font-medium text-white"><?= htmlspecialchars($it['cliente_apelido'] ?: $it['cliente']) ?></td>
                        <td class="font-mono text-slate-300"><?= htmlspecialchars($it['referencia']) ?></td>
                        <td class="text-xs text-slate-300" title="<?= htmlspecialchars($it['descricao']) ?>">
                            <?= htmlspecialchars(strlen($it['descricao']) > 50 ? substr($it['descricao'], 0, 47) . '...' : $it['descricao']) ?>
                        </td>
                        <td class="font-mono text-right text-slate-200"><?= htmlspecialchars($it['potencia_str'] ?: ($it['potencia_kva'] . ' kVA')) ?></td>
                        <td class="text-center">
                            <?php if ($it['linha'] === 'MONOFASICO'): ?>
                                <span class="badge-linha badge-mono">Monofásico</span>
                            <?php elseif ($it['linha'] === 'JC_TRIF'): ?>
                                <span class="badge-linha badge-jc">JC-TRIF</span>
                            <?php else: ?>
                                <span class="badge-linha badge-conv">Convencional</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center font-mono text-xs text-slate-300">
                            <?= date('d/m/Y', strtotime($it['data_programada'])) ?>
                        </td>
                        <td class="text-center font-mono font-bold text-[#ff6b6b]">
                            +<?= $it['dias_atraso_individual'] ?> d
                        </td>
                        <td class="text-right font-mono font-bold text-white">
                            <?= number_format($it['quantidade'], 0, ',', '.') ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="p-3 border-t border-[#262c3d] flex justify-between items-center text-xs text-[#94a3b8]">
            <span id="tabelaContador">Exibindo <?= count($metricas['itens_detalhados']) ?> ordens</span>
            <span>Dados consolidados em <?= date('d/m/Y H:i') ?></span>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- Scripts de Gráficos (Chart.js + Plugin DataLabels) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0"></script>

<script>
<?php if ($metricas): ?>
// ─── Dados para os Gráficos ────────────────────────────────────────────────
const dadosPecasMes = <?= json_encode($metricas['pecas_por_mes'], JSON_UNESCAPED_UNICODE) ?>;
const dadosEvolucao = <?= json_encode($metricas['serie_evolucao'], JSON_UNESCAPED_UNICODE) ?>;

// 1. Gráfico Horizontal de Peças Atrasadas por Mês
const ctxMes = document.getElementById('chartPecasMes')?.getContext('2d');
if (ctxMes && dadosPecasMes.length > 0) {
    const labelsMes = dadosPecasMes.map(d => d.rotulo);
    const valoresMes = dadosPecasMes.map(d => d.qtd);

    new Chart(ctxMes, {
        type: 'bar',
        data: {
            labels: labelsMes,
            datasets: [{
                data: valoresMes,
                backgroundColor: '#e63946',
                hoverBackgroundColor: '#ff4d4d',
                borderRadius: 4,
                barThickness: 28,
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            layout: {
                padding: { right: 40, left: 10 }
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
                            return ' ' + ctx.raw.toLocaleString('pt-BR') + ' peças em atraso';
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
}

// 2. Gráfico de Área: Evolução da "Média de dias em atraso"
const ctxEvolucao = document.getElementById('chartEvolucaoAtraso')?.getContext('2d');
if (ctxEvolucao && dadosEvolucao.length > 0) {
    const labelsEvolucao = dadosEvolucao.map(d => d.label);
    const valoresEvolucao = dadosEvolucao.map(d => d.media_geral);

    // Gradiente vermelho rubro profundo
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
                        borderDash: [2, 2]
                    },
                    ticks: {
                        color: '#94a3b8',
                        font: { size: 10 },
                        maxRotation: 45,
                        minRotation: 35
                    },
                    border: { color: '#334155' }
                },
                y: {
                    min: 0,
                    grid: {
                        color: 'rgba(255, 255, 255, 0.06)',
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
<?php endif; ?>

// ─── Manipulação de Filtros de Meses ─────────────────────────────────────────
function toggleMesFiltro(mKey) {
    const input = document.getElementById('inputMesesFiltro');
    let atuais = input.value ? input.value.split(',').filter(Boolean) : [];
    
    if (atuais.includes(mKey)) {
        atuais = atuais.filter(x => x !== mKey);
    } else {
        atuais.push(mKey);
    }
    
    input.value = atuais.join(',');
    document.getElementById('filtroForm').submit();
}

function toggleTodosMeses() {
    document.getElementById('inputMesesFiltro').value = '';
    document.getElementById('filtroForm').submit();
}

// ─── Busca e Filtro na Tabela de Detalhes ────────────────────────────────────
function filtrarTabelaDetalhes() {
    const busca = (document.getElementById('tabelaBusca')?.value || '').toLowerCase().trim();
    const linha = (document.getElementById('tabelaFiltroLinha')?.value || '').trim();
    const linhas = document.querySelectorAll('#tabelaCorpo tr');
    let visiveis = 0;

    linhas.forEach(tr => {
        const trLinha = tr.getAttribute('data-linha') || '';
        const trTexto = tr.getAttribute('data-texto') || '';

        const matchBusca = !busca || trTexto.includes(busca);
        const matchLinha = !linha || trLinha === linha;

        if (matchBusca && matchLinha) {
            tr.style.display = '';
            visiveis++;
        } else {
            tr.style.display = 'none';
        }
    });

    const contador = document.getElementById('tabelaContador');
    if (contador) {
        contador.textContent = 'Exibindo ' + visiveis + ' ordens';
    }
}

// ─── Exportação da Relação para CSV ──────────────────────────────────────────
function exportarTabelaCSV() {
    const linhas = document.querySelectorAll('#tabelaAtrasos tr');
    if (!linhas.length) return;

    let csvContent = '\uFEFF'; // UTF-8 BOM
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
    link.download = 'atraso_distribuicao_' + new Date().toISOString().slice(0, 10) + '.csv';
    link.click();
}
</script>

<?php layoutFooter(); ?>
