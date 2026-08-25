<?php
/**
 * Modal de Detalhamento Analítico de Peças / Apontamentos do PCP
 * Utilizado pelos dashboards Indicador Distribuição e Indicador Média Força ao clicar no gráfico ou tabela.
 */
?>
<style>
/* ─── Modal de Detalhes de Peças ────────────────────────────────────────── */
.modal-pecas-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(4px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 20px;
    box-sizing: border-box;
}
.modal-pecas-overlay.open {
    display: flex;
}
.modal-pecas-card {
    background: var(--color-surface, #ffffff);
    border: 1px solid var(--color-border, #e2e8f0);
    border-radius: var(--radius-lg, 12px);
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2), 0 10px 10px -5px rgba(0, 0, 0, 0.1);
    width: 100%;
    max-width: 1200px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    animation: modalSlideUp 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes modalSlideUp {
    from { opacity: 0; transform: translateY(16px) scale(0.98); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
.modal-pecas-header {
    padding: 16px 20px;
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
    font-weight: 700;
    color: var(--color-text-primary, #0f172a);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.modal-pecas-badge {
    font-size: 0.75rem;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 9999px;
    background: var(--color-surface, #ffffff);
    border: 1px solid var(--color-border, #cbd5e1);
    color: var(--color-text-secondary, #475569);
}
.modal-pecas-close {
    background: transparent;
    border: none;
    cursor: pointer;
    padding: 6px;
    border-radius: 6px;
    color: var(--color-text-muted, #94a3b8);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s ease;
}
.modal-pecas-close:hover {
    background: var(--color-border, #e2e8f0);
    color: var(--color-text-primary, #0f172a);
}

.modal-pecas-toolbar {
    padding: 12px 20px;
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
    min-width: 240px;
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
    padding: 7px 12px 7px 32px;
    border-radius: var(--radius-md, 6px);
    border: 1px solid var(--color-border-strong, #cbd5e1);
    font-size: 0.85rem;
    background: var(--color-surface, #ffffff);
    color: var(--color-text-primary, #0f172a);
    box-sizing: border-box;
    outline: none;
    transition: border-color 0.15s;
}
.modal-pecas-search-input:focus {
    border-color: var(--color-primary, #16a34a);
    box-shadow: 0 0 0 2px rgba(22, 163, 74, 0.15);
}
.modal-pecas-chips {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.modal-pecas-chip-btn {
    font-size: 0.75rem;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 9999px;
    border: 1px solid var(--color-border, #e2e8f0);
    background: var(--color-surface-2, #f8fafc);
    color: var(--color-text-secondary, #64748b);
    cursor: pointer;
    transition: all 0.15s ease;
}
.modal-pecas-chip-btn:hover {
    border-color: var(--color-border-strong, #cbd5e1);
    color: var(--color-text-primary, #0f172a);
}
.modal-pecas-chip-btn.active {
    background: #0f172a;
    color: #ffffff;
    border-color: #0f172a;
}

.modal-pecas-body {
    flex: 1;
    overflow-y: auto;
    overflow-x: auto;
    padding: 0;
    max-height: calc(90vh - 170px);
}
.modal-pecas-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8rem;
    text-align: left;
}
.modal-pecas-table th {
    background: var(--color-surface-2, #f8fafc);
    color: var(--color-text-secondary, #475569);
    font-weight: 700;
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 9px 12px;
    border-bottom: 1px solid var(--color-border, #e2e8f0);
    white-space: nowrap;
    position: sticky;
    top: 0;
    z-index: 10;
}
.modal-pecas-table td {
    padding: 8px 12px;
    border-bottom: 1px solid var(--color-border, #e2e8f0);
    color: var(--color-text-primary, #1e293b);
    vertical-align: middle;
}
.modal-pecas-table tbody tr:hover {
    background: rgba(241, 245, 249, 0.65);
}
.modal-pecas-table tr.is-reprova {
    background: #fef2f2;
}
.modal-pecas-table tr.is-reprova:hover {
    background: #fee2e2;
}

.badge-peca-serie {
    font-family: 'JetBrains Mono', monospace;
    font-weight: 700;
    font-size: 0.8rem;
    color: #0f172a;
    background: #e2e8f0;
    padding: 2px 6px;
    border-radius: 4px;
    display: inline-block;
}
.badge-nucleo-tag {
    font-size: 0.72rem;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 4px;
    display: inline-block;
    white-space: nowrap;
}
.badge-nucleo-ENR { background: #dcfce7; color: #15803d; }
.badge-nucleo-JC  { background: #dbeafe; color: #1d4ed8; }
.badge-nucleo-EMP { background: #ccfbf1; color: #0f766e; }
.badge-nucleo-LAB { background: #fee2e2; color: #b91c1c; font-weight: 800; }
.badge-nucleo-TPD { background: #dcfce7; color: #15803d; }
.badge-nucleo-TPS { background: #dbeafe; color: #1d4ed8; }
.badge-nucleo-TPM { background: #ccfbf1; color: #0f766e; }

.modal-pecas-footer {
    padding: 10px 20px;
    border-top: 1px solid var(--color-border, #e2e8f0);
    background: var(--color-surface-2, #f8fafc);
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 0.75rem;
    color: var(--color-text-muted, #64748b);
}

.modal-pecas-empty {
    text-align: center;
    padding: 48px 20px;
    color: var(--color-text-muted, #94a3b8);
}
.modal-pecas-spinner {
    display: inline-block;
    width: 28px;
    height: 28px;
    border: 3px solid #cbd5e1;
    border-top-color: var(--color-primary, #16a34a);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}
@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>

<!-- Modal Estrutura -->
<div class="modal-pecas-overlay" id="modal-detalhes-pecas" onclick="if(event.target === this) fecharModalDetalhesPecas();">
    <div class="modal-pecas-card">
        
        <!-- Header -->
        <div class="modal-pecas-header">
            <div class="modal-pecas-title-group">
                <h3 class="modal-pecas-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="color:var(--color-primary, #16a34a);"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
                    <span id="modal-pecas-titulo-texto">Relação de Peças</span>
                </h3>
                <span class="modal-pecas-badge" id="modal-pecas-badge-total">0 peças</span>
            </div>
            <button type="button" class="modal-pecas-close" onclick="fecharModalDetalhesPecas()" aria-label="Fechar">&times;</button>
        </div>

        <!-- Toolbar: Busca Instantânea, Filtros e Exportação -->
        <div class="modal-pecas-toolbar">
            <div class="modal-pecas-search-wrap">
                <svg class="modal-pecas-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="modal-pecas-input-busca" class="modal-pecas-search-input" placeholder="Buscar por série, OF, projeto, pedido, cliente, operador ou motivo..." oninput="filtrarTabelaPecasModal()">
            </div>

            <div class="modal-pecas-chips" id="modal-pecas-chips-container">
                <!-- Injetado dinamicamente via JS -->
            </div>

            <button type="button" class="btn btn-secondary btn-sm" onclick="exportarPecasModalCSV()" title="Exportar para CSV" style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;font-size:0.75rem;font-weight:600;">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Exportar CSV
            </button>
        </div>

        <!-- Body / Tabela -->
        <div class="modal-pecas-body" id="modal-pecas-body">
            <div class="modal-pecas-empty" id="modal-pecas-loading" style="display:none;">
                <div class="modal-pecas-spinner"></div>
                <div style="margin-top:12px;font-weight:600;">Consultando apontamentos de produção...</div>
            </div>

            <table class="modal-pecas-table" id="modal-pecas-table">
                <thead>
                    <tr>
                        <th style="width:40px;text-align:center;">#</th>
                        <th style="width:110px;">Nº Série</th>
                        <th style="width:100px;">OF</th>
                        <th style="width:150px;">Projeto / Ref</th>
                        <th>Descrição / Potência</th>
                        <th style="width:100px;">Pedido</th>
                        <th style="width:160px;">Cliente</th>
                        <th style="width:90px;text-align:center;">Núcleo/Linha</th>
                        <th style="width:140px;">Horário Apontamento</th>
                        <th style="width:110px;">Operador</th>
                        <th style="width:180px;">Motivo Reprova / Situação</th>
                    </tr>
                </thead>
                <tbody id="modal-pecas-tbody">
                    <!-- Preenchido via JS -->
                </tbody>
            </table>

            <div class="modal-pecas-empty" id="modal-pecas-empty-msg" style="display:none;">
                <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:#94a3b8;margin-bottom:8px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <div style="font-weight:600;color:var(--color-text-secondary, #475569);">Nenhuma peça encontrada para os critérios selecionados.</div>
            </div>
        </div>

        <!-- Footer -->
        <div class="modal-pecas-footer">
            <span id="modal-pecas-contador-exibidos">Exibindo 0 registros</span>
            <span>Apontamentos integrados ao Kardex e Turno da Fábrica (07:30 - 02:48)</span>
        </div>

    </div>
</div>
