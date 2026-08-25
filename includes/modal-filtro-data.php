<?php
/**
 * Modal de Filtro de Data / Mês / Personalizado — Padrão visual do Painel por Setor
 */
$_mfdModoData   = $modoData ?? (isset($_GET['modo_data']) ? trim((string)$_GET['modo_data']) : '');
if ($_mfdModoData === '') {
    $_mfdModoData = (!empty($dataInicio) && !empty($dataFim)) ? 'personalizado' : (($mes ?? '') === date('Y-m') ? 'hoje' : 'mes');
}
$_mfdMesAtual   = date('Y-m');
$_mfdMesSel     = $mes ?? $_mfdMesAtual;
$_mfdDataInicio = !empty($dataInicio) ? $dataInicio : date('Y-m-01');
$_mfdDataFim    = !empty($dataFim) ? $dataFim : date('Y-m-d');
?>
<style>
/* ─── Modal de Filtro de Data (Estilo Painel por Setor) ───────────────────── */
.date-filter-overlay {
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
.date-filter-overlay.open {
    display: flex;
}
.date-filter-modal {
    background: var(--color-surface, #ffffff);
    border: 1px solid var(--color-border, #e2e8f0);
    border-radius: var(--radius-lg, 12px);
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2), 0 10px 10px -5px rgba(0, 0, 0, 0.1);
    width: 100%;
    max-width: 440px;
    padding: 20px;
    box-sizing: border-box;
    animation: modalSlideUp 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
.df-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--color-border, #e2e8f0);
}
.df-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--color-text-primary, #0f172a);
}
.df-close-btn {
    background: transparent;
    border: none;
    font-size: 1.25rem;
    line-height: 1;
    color: var(--color-text-muted, #94a3b8);
    cursor: pointer;
    padding: 4px;
    border-radius: 6px;
    transition: color 0.15s;
}
.df-close-btn:hover { color: var(--color-text-primary, #0f172a); }

.df-tabs-container {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    background: var(--color-surface-2, #f1f5f9);
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
    color: var(--color-text-secondary, #64748b);
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

.df-hoje-box {
    background: #fef2f2;
    border: 1px solid #fecdd3;
    border-radius: 12px;
    padding: 20px 16px;
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
    font-size: 1.8rem;
    font-weight: 800;
    color: #dc2626;
    letter-spacing: -0.02em;
    margin-bottom: 8px;
}
.df-hoje-desc {
    font-size: 0.78rem;
    color: var(--color-text-muted, #64748b);
    line-height: 1.4;
}

.df-btn-apply {
    display: block;
    width: 100%;
    background: #dc2626;
    color: #ffffff;
    font-weight: 700;
    font-size: 0.88rem;
    padding: 11px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    box-shadow: 0 4px 6px -1px rgba(220, 38, 38, 0.25);
    transition: background 0.15s;
}
.df-btn-apply:hover {
    background: #b91c1c;
}
</style>

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
            <button type="button" class="df-tab-btn <?= $_mfdModoData === 'hoje' ? 'active' : '' ?>" id="tabBtnHoje" onclick="trocarTabFiltroData('hoje')">
                Hoje
            </button>
            <button type="button" class="df-tab-btn <?= $_mfdModoData === 'mes' ? 'active' : '' ?>" id="tabBtnMes" onclick="trocarTabFiltroData('mes')">
                Mês
            </button>
            <button type="button" class="df-tab-btn <?= $_mfdModoData === 'personalizado' ? 'active' : '' ?>" id="tabBtnPersonalizado" onclick="trocarTabFiltroData('personalizado')">
                Personalizável
            </button>
        </div>

        <!-- FORM: TAB 1 - HOJE -->
        <form method="GET" id="formFiltroHoje" style="display: <?= $_mfdModoData === 'hoje' ? 'block' : 'none' ?>;">
            <input type="hidden" name="modo_data" value="hoje">
            <input type="hidden" name="mes" value="<?= date('Y-m') ?>">

            <div class="df-hoje-box">
                <div class="df-hoje-tag">DATA DE HOJE</div>
                <div class="df-hoje-date"><?= date('d/m/Y') ?></div>
                <div class="df-hoje-desc">
                    Exibe a produção e os apontamentos de <strong><?= date('m/Y') ?></strong>.
                </div>
            </div>

            <button type="submit" class="df-btn-apply">
                Aplicar Mês Atual
            </button>
        </form>

        <!-- FORM: TAB 2 - MÊS -->
        <form method="GET" id="formFiltroMes" style="display: <?= $_mfdModoData === 'mes' ? 'block' : 'none' ?>;">
            <input type="hidden" name="modo_data" value="mes">

            <div class="df-hoje-box" style="background:#f8fafc;border-color:#e2e6ed;">
                <div class="df-hoje-tag" style="color:#475569;">MÊS DE REFERÊNCIA</div>
                <div style="margin: 12px 0;">
                    <input type="month" name="mes" value="<?= htmlspecialchars($_mfdMesSel) ?>" 
                           style="border:1px solid #cbd5e1;border-radius:8px;padding:8px 12px;font-size:16px;font-weight:700;color:#0f172a;background:#ffffff;outline:none;width:100%;max-width:240px;box-sizing:border-box;">
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
        <form method="GET" id="formFiltroPersonalizado" style="display: <?= $_mfdModoData === 'personalizado' ? 'block' : 'none' ?>;">
            <input type="hidden" name="modo_data" value="personalizado">

            <div class="df-hoje-box" style="background:#f8fafc;border-color:#e2e6ed;text-align:left;">
                <div class="df-hoje-tag" style="color:#475569;text-align:center;">INTERVALO PERSONALIZADO</div>
                
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:12px 0;">
                    <div>
                        <label style="display:block;font-size:11px;font-weight:700;color:#64748b;margin-bottom:4px;">De:</label>
                        <input type="date" name="data_inicio" value="<?= htmlspecialchars($_mfdDataInicio) ?>" required
                               style="width:100%;border:1px solid #cbd5e1;border-radius:6px;padding:6px 8px;font-size:13px;font-weight:600;background:#ffffff;outline:none;box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;font-weight:700;color:#64748b;margin-bottom:4px;">Até:</label>
                        <input type="date" name="data_fim" value="<?= htmlspecialchars($_mfdDataFim) ?>" required
                               style="width:100%;border:1px solid #cbd5e1;border-radius:6px;padding:6px 8px;font-size:13px;font-weight:600;background:#ffffff;outline:none;box-sizing:border-box;">
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

    </div>
</div>

<script>
function abrirFiltroDataModal() {
    document.getElementById('dateFilterModal')?.classList.add('open');
}
function fecharFiltroDataModal() {
    document.getElementById('dateFilterModal')?.classList.remove('open');
}
function trocarTabFiltroData(modo) {
    document.getElementById('tabBtnHoje')?.classList.toggle('active', modo === 'hoje');
    document.getElementById('tabBtnMes')?.classList.toggle('active', modo === 'mes');
    document.getElementById('tabBtnPersonalizado')?.classList.toggle('active', modo === 'personalizado');
    
    const formHoje = document.getElementById('formFiltroHoje');
    const formMes = document.getElementById('formFiltroMes');
    const formPersonalizado = document.getElementById('formFiltroPersonalizado');
    
    if (formHoje) formHoje.style.display = (modo === 'hoje') ? 'block' : 'none';
    if (formMes) formMes.style.display = (modo === 'mes') ? 'block' : 'none';
    if (formPersonalizado) formPersonalizado.style.display = (modo === 'personalizado') ? 'block' : 'none';
}
</script>

