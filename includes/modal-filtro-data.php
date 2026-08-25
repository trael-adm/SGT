<?php
/**
 * Modal de Filtro de Data / Mês / Personalizado
 * Padrão visual idêntico ao Dashboard do Retrabalho
 */
$_mfdModoData   = $modoData ?? (isset($_GET['modo_data']) ? trim((string)$_GET['modo_data']) : '');
if ($_mfdModoData === '') {
    $_mfdModoData = (!empty($dataInicio) && !empty($dataFim)) ? 'personalizado' : (($mes ?? '') === date('Y-m') ? 'hoje' : 'mes');
}
$_mfdMesAtual   = date('Y-m');
$_mfdMesSel     = $mes ?? $_mfdMesAtual;
$_mfdDataInicio = !empty($dataInicio) ? $dataInicio : '';
$_mfdDataFim    = !empty($dataFim) ? $dataFim : '';
$_mfdDatasEsp   = isset($_GET['datas_especificas']) ? trim((string)$_GET['datas_especificas']) : '';
?>
<style>
/* ─── Modal de Filtro de Data (Padrão Dashboard Retrabalho) ───────────────── */
.date-filter-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(15, 23, 42, 0.6);
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
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 20px 40px rgba(15, 23, 42, 0.18);
    width: 100%;
    max-width: 380px;
    padding: 18px 20px;
    box-sizing: border-box;
    animation: dfModalSlideUp 0.18s ease;
}
@keyframes dfModalSlideUp {
    from { opacity: 0; transform: translateY(-8px) scale(0.98); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}
.df-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-bottom: 10px;
    border-bottom: 1px solid #f1f5f9;
    margin-bottom: 14px;
}
.df-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 800;
    color: #0f172a;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.df-close-btn {
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
.df-close-btn:hover { color: #0f172a; }

.df-tabs-container {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 6px;
    background: #f1f5f9;
    padding: 4px;
    border-radius: 8px;
    margin-bottom: 14px;
}
.df-tab-btn {
    background: transparent;
    border: none;
    padding: 7px 4px;
    font-size: 12px;
    font-weight: 700;
    color: #64748b;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.15s ease;
    text-align: center;
}
.df-tab-btn.active {
    background: #ffffff;
    color: #dc2626;
    font-weight: 700;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.06);
}

.df-hoje-box {
    text-align: center;
    background: #fef2f2;
    border: 1px solid #fee2e2;
    border-radius: 8px;
    padding: 14px 10px;
    margin-bottom: 14px;
}
.df-hoje-label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #991b1b; }
.df-hoje-date { display: block; font-size: 20px; font-weight: 900; color: #dc2626; font-family: 'JetBrains Mono', monospace; margin: 4px 0 6px; }
.df-hoje-hint { font-size: 11px; color: #64748b; margin: 0; line-height: 1.35; }

.df-field { margin-bottom: 12px; }
.df-label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #475569; margin-bottom: 5px; }

.df-input {
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
.df-input:focus {
    border-color: #dc2626;
    box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.12);
}

.df-shortcuts {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 14px;
}
.df-chip {
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 4px 8px;
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    cursor: pointer;
    transition: all 0.15s ease;
}
.df-chip:hover {
    border-color: #dc2626;
    color: #dc2626;
    background: #fef2f2;
}

.df-submode-toggle {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 1px dashed #e2e8f0;
}
.df-radio-label {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 700;
    color: #334155;
    cursor: pointer;
}

.df-range-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}

.df-tags-wrap {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    min-height: 34px;
    max-height: 90px;
    overflow-y: auto;
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    border-radius: 8px;
    padding: 6px;
    margin-top: 8px;
    margin-bottom: 12px;
}
.df-date-tag {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 4px;
    padding: 2px 6px;
    font-size: 11px;
    font-weight: 700;
    font-family: 'JetBrains Mono', monospace;
    color: #0f172a;
}
.df-date-tag button {
    background: none;
    border: none;
    color: #ef4444;
    cursor: pointer;
    font-weight: 800;
    padding: 0 2px;
    line-height: 1;
}

.df-btn-apply {
    display: block;
    width: 100%;
    background: #dc2626;
    color: #ffffff;
    font-weight: 700;
    font-size: 13px;
    padding: 11px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    box-shadow: 0 4px 6px -1px rgba(220, 38, 38, 0.25);
    transition: background 0.15s;
    text-align: center;
}
.df-btn-apply:hover {
    background: #b91c1c;
}
.df-btn-secondary {
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 6px 12px;
    font-size: 12px;
    font-weight: 700;
    color: #334155;
    cursor: pointer;
    transition: all 0.15s;
}
.df-btn-secondary:hover {
    background: #e2e8f0;
}

.df-footer {
    border-top: 1px solid #f1f5f9;
    padding-top: 10px;
    margin-top: 12px;
    text-align: center;
}
.df-btn-clear {
    background: none;
    border: none;
    color: #64748b;
    font-size: 11.5px;
    font-weight: 700;
    text-decoration: underline;
    cursor: pointer;
    transition: color 0.15s;
}
.df-btn-clear:hover {
    color: #dc2626;
}
</style>

<div class="date-filter-overlay" id="dateFilterModal" onclick="if(event.target === this) fecharFiltroDataModal();">
    <div class="date-filter-modal">
        
        <!-- Header do Modal -->
        <div class="df-header">
            <div class="df-title">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
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
            <?php if (!empty($extraHiddenInputs)): foreach ($extraHiddenInputs as $k => $v): ?>
                <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars((string)$v) ?>">
            <?php endforeach; endif; ?>
            <input type="hidden" name="modo_data" value="hoje">
            <input type="hidden" name="mes" value="<?= date('Y-m') ?>">

            <div class="df-hoje-box">
                <span class="df-hoje-label">Data de Hoje</span>
                <span class="df-hoje-date"><?= date('d/m/Y') ?></span>
                <p class="df-hoje-hint">Filtra os apontamentos registrados exclusivamente na data de hoje.</p>
            </div>

            <button type="submit" class="df-btn-apply">
                Aplicar Data de Hoje
            </button>
        </form>

        <!-- FORM: TAB 2 - MÊS -->
        <form method="GET" id="formFiltroMes" style="display: <?= $_mfdModoData === 'mes' ? 'block' : 'none' ?>;">
            <?php if (!empty($extraHiddenInputs)): foreach ($extraHiddenInputs as $k => $v): ?>
                <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars((string)$v) ?>">
            <?php endforeach; endif; ?>
            <input type="hidden" name="modo_data" value="mes">

            <div class="df-field" style="margin-bottom:12px;">
                <label class="df-label">De (Mês / Ano):</label>
                <input type="month" id="inputMesModal" name="mes" value="<?= htmlspecialchars($_mfdMesSel) ?>" class="df-input">
            </div>

            <div class="df-shortcuts">
                <button type="button" class="df-chip" onclick="document.getElementById('inputMesModal').value='<?= date('Y-m') ?>'">Mês Atual (<?= date('m/Y') ?>)</button>
                <button type="button" class="df-chip" onclick="document.getElementById('inputMesModal').value='<?= date('Y-m', strtotime('-1 month')) ?>'">Mês Anterior</button>
            </div>

            <button type="submit" class="df-btn-apply">
                Aplicar Mês
            </button>
        </form>

        <!-- FORM: TAB 3 - PERSONALIZÁVEL -->
        <form method="GET" id="formFiltroPersonalizado" style="display: <?= $_mfdModoData === 'personalizado' ? 'block' : 'none' ?>;">
            <?php if (!empty($extraHiddenInputs)): foreach ($extraHiddenInputs as $k => $v): ?>
                <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars((string)$v) ?>">
            <?php endforeach; endif; ?>
            <input type="hidden" name="modo_data" value="personalizado">

            <div class="df-submode-toggle">
                <label class="df-radio-label">
                    <input type="radio" name="df_submode" value="intervalo" <?= empty($_mfdDatasEsp) ? 'checked' : '' ?> onchange="trocarSubmodoPersonalizado('intervalo')">
                    <span>Intervalo de Datas</span>
                </label>
                <label class="df-radio-label">
                    <input type="radio" name="df_submode" value="especificas" <?= !empty($_mfdDatasEsp) ? 'checked' : '' ?> onchange="trocarSubmodoPersonalizado('especificas')">
                    <span>Datas Específicas</span>
                </label>
            </div>

            <!-- Submodo 1: Intervalo de Datas -->
            <div id="dfSubIntervalo" style="<?= empty($_mfdDatasEsp) ? '' : 'display:none;' ?>">
                <div class="df-range-grid">
                    <div class="df-field">
                        <label class="df-label">DE (INÍCIO):</label>
                        <input type="date" id="dfInputDataInicio" name="data_inicio" value="<?= htmlspecialchars($_mfdDataInicio) ?>" class="df-input">
                    </div>
                    <div class="df-field">
                        <label class="df-label">ATÉ (FIM):</label>
                        <input type="date" id="dfInputDataFim" name="data_fim" value="<?= htmlspecialchars($_mfdDataFim) ?>" class="df-input">
                    </div>
                </div>

                <div class="df-shortcuts">
                    <button type="button" class="df-chip" onclick="setAtalhoIntervaloModal(7)">Últimos 7 dias</button>
                    <button type="button" class="df-chip" onclick="setAtalhoIntervaloModal(15)">Últimos 15 dias</button>
                    <button type="button" class="df-chip" onclick="setAtalhoIntervaloModal(30)">Últimos 30 dias</button>
                </div>
            </div>

            <!-- Submodo 2: Datas Específicas -->
            <div id="dfSubEspecificas" style="<?= !empty($_mfdDatasEsp) ? '' : 'display:none;' ?>">
                <div class="df-field">
                    <label class="df-label">Adicionar Data à Seleção:</label>
                    <div style="display:flex;gap:6px;">
                        <input type="date" id="dfInputDataAdd" class="df-input" style="flex:1;">
                        <button type="button" class="df-btn-secondary" onclick="adicionarDataEspecificaModal()" style="white-space:nowrap;">+ Adicionar</button>
                    </div>
                </div>
                <label class="df-label" style="margin-top:6px;">Datas Selecionadas:</label>
                <div class="df-tags-wrap" id="dfDatasTagsWrap">
                    <!-- Inserido via JS -->
                </div>
                <input type="hidden" name="datas_especificas" id="dfInputDatasEspecificas" value="<?= htmlspecialchars($_mfdDatasEsp) ?>">
            </div>

            <button type="submit" class="df-btn-apply" style="margin-top:4px;">
                Aplicar Filtro Personalizado
            </button>
        </form>

        <!-- Footer com Link para Limpar Filtro -->
        <div class="df-footer">
            <?php 
                // Monta query string limpando apenas os parâmetros de data
                $clearParams = $_GET;
                unset($clearParams['modo_data'], $clearParams['tipo_data'], $clearParams['mes'], $clearParams['data_inicio'], $clearParams['data_fim'], $clearParams['data_de'], $clearParams['data_ate'], $clearParams['datas_especificas']);
                $clearUrl = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
                if (!empty($clearParams)) {
                    $clearUrl .= '?' . http_build_query($clearParams);
                }
            ?>
            <a href="<?= htmlspecialchars($clearUrl) ?>" class="df-btn-clear">
                Ver Todas as Datas (Limpar filtro de data)
            </a>
        </div>

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

function trocarSubmodoPersonalizado(sub) {
    var elInt = document.getElementById('dfSubIntervalo');
    var elEsp = document.getElementById('dfSubEspecificas');
    if (elInt) elInt.style.display = (sub === 'intervalo') ? 'block' : 'none';
    if (elEsp) elEsp.style.display = (sub === 'especificas') ? 'block' : 'none';
}

function setAtalhoIntervaloModal(dias) {
    var hoje = new Date();
    var ateIso = hoje.toISOString().split('T')[0];
    var deDate = new Date();
    deDate.setDate(hoje.getDate() - dias + 1);
    var deIso = deDate.toISOString().split('T')[0];

    var inputDe = document.getElementById('dfInputDataInicio');
    var inputAte = document.getElementById('dfInputDataFim');
    if (inputDe) inputDe.value = deIso;
    if (inputAte) inputAte.value = ateIso;
}

// Datas Específicas
var mfdDatasEspecificasSet = new Set();
var initialMfdDatasEsp = <?= json_encode($_mfdDatasEsp) ?>;
if (initialMfdDatasEsp) {
    initialMfdDatasEsp.split(',').forEach(function (d) {
        d = d.trim();
        if (d) mfdDatasEspecificasSet.add(d);
    });
}

function renderMfdDatasTags() {
    var wrap = document.getElementById('dfDatasTagsWrap');
    var hidden = document.getElementById('dfInputDatasEspecificas');
    if (!wrap) return;
    wrap.innerHTML = '';
    if (!mfdDatasEspecificasSet.size) {
        wrap.innerHTML = '<span style="font-size:11px;color:#94a3b8;padding:4px;">Nenhuma data adicionada ainda.</span>';
        if (hidden) hidden.value = '';
        return;
    }
    var sorted = Array.from(mfdDatasEspecificasSet).sort();
    if (hidden) hidden.value = sorted.join(',');
    sorted.forEach(function (dIso) {
        var parts = dIso.split('-');
        var dFmt = (parts.length === 3) ? (parts[2] + '/' + parts[1] + '/' + parts[0]) : dIso;
        var tag = document.createElement('span');
        tag.className = 'df-date-tag';
        tag.innerHTML = dFmt + ' <button type="button" onclick="removerDataEspecificaModal(\'' + dIso + '\')" title="Remover">&times;</button>';
        wrap.appendChild(tag);
    });
}
renderMfdDatasTags();

function adicionarDataEspecificaModal() {
    var input = document.getElementById('dfInputDataAdd');
    if (!input || !input.value) return;
    mfdDatasEspecificasSet.add(input.value);
    input.value = '';
    renderMfdDatasTags();
}

function removerDataEspecificaModal(dIso) {
    mfdDatasEspecificasSet.delete(dIso);
    renderMfdDatasTags();
}
</script>
