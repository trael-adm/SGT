<?php
/**
 * Modal de Análise Diária de Produção & Detalhes de Peças
 * Utilizado pelos dashboards Indicador Distribuição e Indicador Média Força ao clicar no gráfico ou tabela.
 */
?>
<style>
/* ─── Modal de Análise Diária de Produção ────────────────────────────────── */
.modal-pecas-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(15, 23, 42, 0.72);
    backdrop-filter: blur(6px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 16px;
    box-sizing: border-box;
}
.modal-pecas-overlay.open {
    display: flex;
}
.modal-pecas-card {
    background: var(--color-surface, #ffffff);
    border: 1px solid var(--color-border, #e2e8f0);
    border-radius: 14px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(0,0,0,0.05);
    width: 100%;
    max-width: 1280px;
    max-height: 94vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    animation: modalAnaliseSlide 0.22s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes modalAnaliseSlide {
    from { opacity: 0; transform: translateY(18px) scale(0.98); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

/* Header */
.modal-pecas-header {
    padding: 14px 20px;
    border-bottom: 1px solid var(--color-border, #e2e8f0);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    background: var(--color-surface-2, #f8fafc);
}
.modal-pecas-title-group {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.modal-pecas-title {
    font-size: 1.1rem;
    font-weight: 800;
    color: var(--color-text-primary, #0f172a);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
    letter-spacing: -0.02em;
}
.modal-pecas-close {
    background: transparent;
    border: none;
    cursor: pointer;
    width: 32px;
    height: 32px;
    border-radius: 6px;
    color: var(--color-text-muted, #64748b);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    line-height: 1;
    transition: all 0.15s ease;
}
.modal-pecas-close:hover {
    background: #e2e8f0;
    color: #0f172a;
}

/* Painel de KPIs Rápidos do Dia */
.modal-kpis-bar {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    padding: 12px 20px;
    background: #ffffff;
    border-bottom: 1px solid #f1f5f9;
}
@media (max-width: 768px) {
    .modal-kpis-bar { grid-template-columns: repeat(2, 1fr); }
}
.modal-kpi-item {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 8px 12px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}
.modal-kpi-item.accent {
    border-left: 3.5px solid #16a34a;
}
.modal-kpi-item.warning {
    border-left: 3.5px solid #d97706;
}
.modal-kpi-item.danger {
    border-left: 3.5px solid #dc2626;
}
.modal-kpi-item.info {
    border-left: 3.5px solid #0284c7;
}
.modal-kpi-label {
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #64748b;
    margin-bottom: 2px;
}
.modal-kpi-value {
    font-size: 1.15rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.1;
    font-family: monospace;
}
.modal-kpi-sub {
    font-size: 0.68rem;
    font-weight: 600;
    color: #94a3b8;
    margin-top: 2px;
}

/* Painel de Gráficos Analíticos por Cliente */
.modal-charts-panel {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
    padding: 14px 20px;
    background: #ffffff;
    border-bottom: 1px solid #e2e8f0;
}
@media (max-width: 820px) {
    .modal-charts-panel { grid-template-columns: 1fr; }
}
.modal-chart-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 12px 16px;
    display: flex;
    flex-direction: column;
}
.modal-chart-box-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
}
.modal-chart-box-title {
    font-size: 0.78rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #334155;
    display: flex;
    align-items: center;
    gap: 6px;
}
.modal-chart-canvas-wrap {
    position: relative;
    height: 235px;
    width: 100%;
}

/* Toolbar: Busca Instantânea e Filtros por Núcleo */
.modal-pecas-toolbar {
    padding: 10px 20px;
    border-bottom: 1px solid var(--color-border, #e2e8f0);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    background: var(--color-surface, #ffffff);
}
.modal-pecas-search-wrap {
    position: relative;
    flex: 1;
    min-width: 220px;
}
.modal-pecas-search-icon {
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--color-text-muted, #94a3b8);
    pointer-events: none;
}
.modal-pecas-search-input {
    width: 100%;
    padding: 6px 28px 6px 32px;
    border-radius: 6px;
    border: 1px solid #cbd5e1;
    font-size: 0.8rem;
    background: #ffffff;
    color: #0f172a;
    box-sizing: border-box;
    outline: none;
    transition: all 0.15s ease;
}
.modal-pecas-search-input:focus {
    border-color: #16a34a;
    box-shadow: 0 0 0 2px rgba(22, 163, 74, 0.15);
}
.modal-pecas-search-clear {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    background: #e2e8f0;
    border: none;
    border-radius: 50%;
    width: 18px;
    height: 18px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    color: #475569;
    cursor: pointer;
    line-height: 1;
    padding: 0;
    transition: all 0.15s ease;
}
.modal-pecas-search-clear:hover {
    background: #cbd5e1;
    color: #0f172a;
}
.modal-pecas-cliente-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #0284c7;
    color: #ffffff;
    font-size: 0.72rem;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 9999px;
    cursor: pointer;
    box-shadow: 0 1px 2px rgba(2, 132, 199, 0.25);
    transition: all 0.15s ease;
    animation: fadeInBadge 0.2s ease;
}
.modal-pecas-cliente-badge:hover {
    background: #0369a1;
}
.modal-pecas-cliente-badge-close {
    font-size: 14px;
    font-weight: 800;
    line-height: 1;
    margin-left: 2px;
    opacity: 0.85;
}
.modal-pecas-cliente-badge:hover .modal-pecas-cliente-badge-close {
    opacity: 1;
}
@keyframes fadeInBadge {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}

.modal-pecas-chips {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.modal-pecas-chip-btn {
    font-size: 0.72rem;
    font-weight: 700;
    padding: 3px 9px;
    border-radius: 9999px;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    color: #64748b;
    cursor: pointer;
    transition: all 0.15s ease;
}
.modal-pecas-chip-btn:hover {
    border-color: #cbd5e1;
    color: #0f172a;
}
.modal-pecas-chip-btn.active {
    background: #0f172a;
    color: #ffffff;
    border-color: #0f172a;
}

/* Body / Tabela de Peças */
.modal-pecas-body {
    flex: 1;
    overflow-y: auto;
    overflow-x: auto;
    padding: 0;
    max-height: calc(94vh - 460px);
    min-height: 180px;
}
.modal-pecas-body::-webkit-scrollbar { width: 8px; height: 8px; }
.modal-pecas-body::-webkit-scrollbar-track { background: #f8fafc; }
.modal-pecas-body::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
.modal-pecas-body::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

.modal-pecas-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 0.78rem;
    text-align: left;
}
.modal-pecas-table th {
    background: #f8fafc;
    color: #475569;
    font-weight: 800;
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 9px 12px;
    border-bottom: 2px solid #e2e8f0;
    white-space: nowrap;
    position: sticky;
    top: 0;
    z-index: 10;
    box-shadow: 0 1px 0 #e2e8f0;
}
.modal-pecas-table td {
    padding: 7px 12px;
    border-bottom: 1px solid #f1f5f9;
    color: #334155;
    vertical-align: middle;
}
.modal-pecas-table tbody tr:nth-child(even) td {
    background: #fafbfc;
}
.modal-pecas-table tbody tr:hover td {
    background: #f0fdf4 !important;
}
.modal-pecas-table tr.is-reprova td {
    background: #fef2f2 !important;
}

.badge-nucleo-tag {
    font-size: 0.68rem;
    font-weight: 800;
    padding: 2px 7px;
    border-radius: 4px;
    display: inline-block;
    white-space: nowrap;
}
.badge-nucleo-ENR { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
.badge-nucleo-JC  { background: #dbeafe; color: #1d4ed8; border: 1px solid #93c5fd; }
.badge-nucleo-EMP { background: #ccfbf1; color: #0f766e; border: 1px solid #5eead4; }
.badge-nucleo-LAB { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
.badge-nucleo-TPD { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
.badge-nucleo-TPS { background: #dbeafe; color: #1d4ed8; border: 1px solid #93c5fd; }
.badge-nucleo-TPM { background: #ccfbf1; color: #0f766e; border: 1px solid #5eead4; }

.modal-pecas-footer {
    padding: 8px 20px;
    border-top: 1px solid var(--color-border, #e2e8f0);
    background: var(--color-surface-2, #f8fafc);
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 0.74rem;
    color: #64748b;
}

.modal-pecas-empty {
    text-align: center;
    padding: 36px 20px;
    color: #94a3b8;
}
.modal-pecas-spinner {
    display: inline-block;
    width: 24px;
    height: 24px;
    border: 3px solid #cbd5e1;
    border-top-color: #16a34a;
    border-radius: 50%;
    animation: spinModal 0.8s linear infinite;
}
@keyframes spinModal {
    to { transform: rotate(360deg); }
}

/* ─── Efeito de Glow Sincronizado nas Colunas da Tabela de Núcleos ─────────── */
.bo-nucleo-table th.bo-col-glow,
.bo-nucleo-table td.bo-col-glow {
    background: #ecfdf5 !important;
    color: #065f46 !important;
    box-shadow: inset 0 0 0 1px #34d399, 0 0 10px rgba(52, 211, 153, 0.25) !important;
    font-weight: 800 !important;
    transition: background 0.15s ease, box-shadow 0.15s ease;
}
</style>

<!-- Modal Estrutura -->
<div class="modal-pecas-overlay" id="modal-detalhes-pecas" onclick="if(event.target === this) fecharModalDetalhesPecas();">
    <div class="modal-pecas-card">
        
        <!-- Header -->
        <div class="modal-pecas-header">
            <div class="modal-pecas-title-group">
                <h3 class="modal-pecas-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="color:#16a34a;"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
                    <span id="modal-pecas-titulo-texto">Análise Diária de Produção</span>
                </h3>
            </div>
            <button type="button" class="modal-pecas-close" onclick="fecharModalDetalhesPecas()" aria-label="Fechar">&times;</button>
        </div>

        <!-- KPIs Rápidos do Dia Selecionado -->
        <div class="modal-kpis-bar">
            <div class="modal-kpi-item accent">
                <span class="modal-kpi-label">Total Produzido</span>
                <span class="modal-kpi-value" id="modal-kpi-total">0 un</span>
                <span class="modal-kpi-sub" id="modal-kpi-total-sub">0 projetos</span>
            </div>
            <div class="modal-kpi-item warning">
                <span class="modal-kpi-label">Meta Diária</span>
                <span class="modal-kpi-value" id="modal-kpi-meta">—</span>
                <span class="modal-kpi-sub" id="modal-kpi-meta-sub">planejamento</span>
            </div>
            <div class="modal-kpi-item danger">
                <span class="modal-kpi-label">Reprovas (Lab)</span>
                <span class="modal-kpi-value" id="modal-kpi-reprovas">0 un</span>
                <span class="modal-kpi-sub" id="modal-kpi-reprovas-sub">0 projetos</span>
            </div>
            <div class="modal-kpi-item info">
                <span class="modal-kpi-label">Clientes Atendidos</span>
                <span class="modal-kpi-value" id="modal-kpi-clientes">0</span>
                <span class="modal-kpi-sub" id="modal-kpi-clientes-sub">grupos econômicos</span>
            </div>
        </div>


        <!-- Painel com 2 Gráficos Analíticos por Cliente -->
        <div class="modal-charts-panel">
            <!-- Gráfico 1: Pizza / Rosca (% por Cliente) -->
            <div class="modal-chart-box">
                <div class="modal-chart-box-header">
                    <span class="modal-chart-box-title">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="color:#0284c7;"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>
                        Distribuição por Cliente
                    </span>
                    <span style="font-size:0.68rem;color:#94a3b8;font-weight:600;">(clique para filtrar)</span>
                </div>
                <div class="modal-chart-canvas-wrap">
                    <canvas id="chart-modal-cliente-pie"></canvas>
                </div>
            </div>

            <!-- Gráfico 2: Barras Horizontais (Qtd por Cliente) -->
            <div class="modal-chart-box">
                <div class="modal-chart-box-header">
                    <span class="modal-chart-box-title">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="color:#16a34a;"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                        Ranking por Volume (Peças)
                    </span>
                    <span style="font-size:0.68rem;color:#94a3b8;font-weight:600;">(clique para filtrar)</span>
                </div>
                <div class="modal-chart-canvas-wrap">
                    <canvas id="chart-modal-cliente-bar"></canvas>
                </div>
            </div>
        </div>

        <!-- Toolbar: Busca Instantânea e Filtros por Núcleo -->
        <div class="modal-pecas-toolbar">
            <div class="modal-pecas-search-wrap">
                <svg class="modal-pecas-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="modal-pecas-input-busca" class="modal-pecas-search-input" placeholder="Buscar por projeto, cliente ou descrição..." oninput="window.atualizarBotaoLimparBuscaModal(); filtrarTabelaPecasModal();">
                <button type="button" id="modal-pecas-search-clear" class="modal-pecas-search-clear" onclick="window.limparBuscaModalPecas()" title="Limpar busca" style="display:none;">&times;</button>
            </div>

            <!-- Chip Indicador de Filtro de Cliente Selecionado no Gráfico -->
            <div id="modal-pecas-cliente-filtro-badge" class="modal-pecas-cliente-badge" style="display:none;" onclick="window.limparFiltroClienteModal()" title="Clique para remover o filtro de cliente">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <span id="modal-pecas-cliente-filtro-nome">Cliente</span>
                <span class="modal-pecas-cliente-badge-close">&times;</span>
            </div>

            <div class="modal-pecas-chips" id="modal-pecas-chips-container">
                <!-- Injetado dinamicamente via JS -->
            </div>

        </div>

        <!-- Body / Tabela Simplificada -->

        <div class="modal-pecas-body" id="modal-pecas-body">
            <div class="modal-pecas-empty" id="modal-pecas-loading" style="display:none;">
                <div class="modal-pecas-spinner"></div>
                <div style="margin-top:10px;font-weight:600;font-size:0.8rem;">Carregando análise e peças do dia...</div>
            </div>

            <table class="modal-pecas-table" id="modal-pecas-table">
                <thead>
                    <tr>
                        <th style="width:40px;text-align:center;">#</th>
                        <th style="width:140px;">PROJETO</th>
                        <th>DESCRIÇÃO</th>
                        <th style="width:200px;">CLIENTE</th>
                        <th style="width:100px;text-align:center;">QUANTIDADE</th>
                        <th style="width:100px;text-align:center;">NÚCLEO</th>
                    </tr>
                </thead>
                <tbody id="modal-pecas-tbody">
                    <!-- Preenchido via JS -->
                </tbody>
            </table>

            <div class="modal-pecas-empty" id="modal-pecas-empty-msg" style="display:none;">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:#94a3b8;margin-bottom:6px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <div style="font-weight:600;color:#64748b;font-size:0.8rem;">Nenhuma peça encontrada para os filtros selecionados.</div>
            </div>
        </div>

        <!-- Footer -->
        <div class="modal-pecas-footer">
            <span id="modal-pecas-contador-exibidos">Exibindo 0 projetos</span>
            <span>Dados consolidados da produção diária</span>
        </div>

    </div>
</div>

