<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/layout.php';

requireAcessoModulo('retrabalho');

$pdo  = getDB();
$base = defined('APP_URL') ? APP_URL : '';

// ─── Permissões ─────────────────────────────────────────────────────────────
$podeGravar = isAdmin() || podeEditar('ret.cus');

// ─── Carregar Catálogo de Reprovas com Tempos ────────────────────────────────
$reprovas = [];
try {
    $reprovas = $pdo->query("
        SELECT id, codigo, familia, descricao, local, setor_causador, tempo_padrao_minutos, ativo, ordem
        FROM reprovas
        WHERE ativo = 1
        ORDER BY
            CASE local WHEN 'LAB' THEN 1 WHEN 'IQF' THEN 2 ELSE 3 END,
            ordem ASC, 
            codigo ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    try {
        $pdo->exec("ALTER TABLE reprovas ADD COLUMN tempo_padrao_minutos INT NOT NULL DEFAULT 60 AFTER setor_causador");
        $reprovas = $pdo->query("
            SELECT id, codigo, familia, descricao, local, setor_causador, tempo_padrao_minutos, ativo, ordem
            FROM reprovas
            WHERE ativo = 1
            ORDER BY
                CASE local WHEN 'LAB' THEN 1 WHEN 'IQF' THEN 2 ELSE 3 END,
                ordem ASC, 
                codigo ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e2) {}
}

$pageTitle = 'Catálogo de Tempos & Custos de Retrabalho';
layoutHeader($pageTitle);
?>

<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@500;700&display=swap">

<style>
/* ==========================================================================
   TELA DE PARÂMETROS E TEMPOS DE REPROVAS — SGT (SINGLE SCREEN FIT)
   ========================================================================== */
:root {
    --brand-primary: #133a27;
    --brand-primary-light: #1e5a3d;
    --brand-gold: #e8a020;
    --brand-gold-hover: #cf8b13;
    --bg-page: #f8fafc;
    --text-main: #0f172a;
    --text-muted: #64748b;
    --border-color: #e2e8f0;
}

.app-content:has(.custos-page-wrapper),
body:has(.custos-page-wrapper) .app-content {
    overflow: hidden !important;
    padding: 14px 20px !important;
    display: flex;
    flex-direction: column;
}

.custos-page-wrapper {
    max-width: 1500px;
    width: 100%;
    margin: 0 auto;
    padding: 0;
    font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
    color: var(--text-main);
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
    height: 100%;
}

/* ─── Top Bar ────────────────────────────────────────────────────────────── */
.custos-top-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    background: #ffffff;
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 10px 18px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    margin-bottom: 12px;
    flex-shrink: 0;
}

.custos-title-wrap {
    display: flex;
    align-items: center;
    gap: 12px;
}

.custos-icon-badge {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: linear-gradient(135deg, var(--brand-primary), var(--brand-primary-light));
    color: var(--brand-gold);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    box-shadow: 0 4px 10px rgba(19, 58, 39, 0.18);
}

.custos-title-wrap h1 {
    margin: 0;
    font-size: 1.18rem;
    font-weight: 800;
    color: var(--brand-primary);
    letter-spacing: -0.02em;
}

.custos-title-wrap p {
    margin: 1px 0 0;
    font-size: 0.82rem;
    color: var(--text-muted);
}

/* ─── Indicador de Auto-Save ─────────────────────────────────────────────── */
.auto-save-status {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 9999px;
    font-size: 0.8rem;
    font-weight: 600;
    color: #166534;
    transition: all 0.25s ease;
}

.auto-save-status.saving {
    background: #fefce8;
    border-color: #fef08a;
    color: #854d0e;
}

.auto-save-status.error {
    background: #fef2f2;
    border-color: #fecaca;
    color: #991b1b;
}

.status-icon-spin {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

/* ─── Form & Cards ───────────────────────────────────────────────────────── */
#formCustos {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
    height: 100%;
}

.custos-card {
    background: #ffffff;
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 14px 18px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    margin-bottom: 0;
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
    overflow: hidden;
}

.custos-card-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border-bottom: 1px solid #f1f5f9;
    padding-bottom: 10px;
    margin-bottom: 10px;
    flex-shrink: 0;
}

.custos-card-header h2 {
    margin: 0;
    font-size: 1rem;
    font-weight: 700;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 8px;
}

.custos-card-header p {
    margin: 2px 0 0;
    font-size: 0.78rem;
    color: var(--text-muted);
}

/* ─── Filtros Rápidos do Catálogo ────────────────────────────────────────── */
.catalog-filter-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 8px 12px;
    margin-bottom: 10px;
    flex-shrink: 0;
}

.catalog-search-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 1;
    max-width: 420px;
}

.catalog-search-input {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    padding: 6px 11px;
    font-size: 0.84rem;
    outline: none;
    background: #ffffff;
}

.catalog-search-input:focus {
    border-color: var(--brand-primary);
}

.filter-btn-group {
    display: flex;
    gap: 6px;
}

.filter-chip {
    border: 1px solid #cbd5e1;
    background: #ffffff;
    color: #475569;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 0.78rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.filter-chip.active {
    background: var(--brand-primary);
    color: #ffffff;
    border-color: var(--brand-primary);
}

/* ─── Tabela de Reprovas com Scroll Interno Único ────────────────────────── */
.table-responsive {
    overflow-x: auto;
    overflow-y: auto;
    flex: 1;
    min-height: 0;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #ffffff;
}

.tabela-reprovas {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.86rem;
}

.tabela-reprovas th {
    background: #f1f5f9;
    color: #334155;
    font-weight: 700;
    text-align: left;
    padding: 9px 12px;
    border-bottom: 2px solid #e2e8f0;
    font-size: 0.74rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    position: sticky;
    top: 0;
    z-index: 10;
}

.tabela-reprovas td {
    padding: 8px 12px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}

.tabela-reprovas tr:hover td {
    background: #f8fafc;
}

/* ─── Quadradinhos / Badges Coloridos de Locais ──────────────────────────── */
.badge-tag {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 2px 7px;
    border-radius: 5px;
    font-size: 0.72rem;
    font-weight: 700;
    font-family: 'JetBrains Mono', monospace;
    line-height: 1.3;
    white-space: nowrap;
}

.badge-tag.lab { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
.badge-tag.iqf { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
.badge-tag.ret { background: #fce7f3; color: #be185d; border: 1px solid #fbcfe8; }
.badge-tag.ger { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }

.input-tempo-min {
    width: 85px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 5px 8px;
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.92rem;
    font-weight: 700;
    color: #0f172a;
    outline: none;
    text-align: right;
    transition: all 0.2s ease;
}

.input-tempo-min:focus {
    border-color: var(--brand-primary);
    box-shadow: 0 0 0 2px rgba(19, 58, 39, 0.12);
}

.tempo-conversao {
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.76rem;
    color: #64748b;
    min-width: 58px;
    text-align: right;
}
</style>

<div class="custos-page-wrapper">
    <!-- Top Control Bar -->
    <div class="custos-top-bar">
        <div class="custos-title-wrap">
            <div class="custos-icon-badge">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 16 14"/>
                </svg>
            </div>
            <div>
                <h1>Catálogo de Tempos & Custos de Retrabalho</h1>
                <p>Configuração da taxa horária e do tempo padrão em minutos para cada tipo de reprova</p>
            </div>
        </div>
        <div id="autoSaveIndicator" class="auto-save-status" style="display: inline-flex;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <polyline points="20 6 9 17 4 12"/>
            </svg>
            <span id="autoSaveText">Salvo automaticamente</span>
        </div>
    </div>


    <form id="formCustos">
        <input type="hidden" id="csrfToken" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">

        <!-- Bloco: Catálogo de Tempos por Reprova -->
        <div class="custos-card">
            <div class="custos-card-header">
                <div>
                    <h2>
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-emerald-600">
                            <circle cx="12" cy="12" r="10"/>
                            <polyline points="12 6 12 12 16 14"/>
                        </svg>
                        Catálogo de Tempo Médio por Reprova (Minutos)
                    </h2>
                    <p>Tempo padrão estimado para diagnóstico, desmontagem e reparo de cada tipo de defeito</p>
                </div>
                <span class="text-xs font-semibold px-2.5 py-1 bg-slate-100 text-slate-700 rounded-full" id="contadorReprovas">
                    <?= count($reprovas) ?> reprovas cadastradas
                </span>
            </div>

            <!-- Barra de Busca e Filtros Rápidos -->
            <div class="catalog-filter-bar">
                <div class="catalog-search-wrap">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-slate-400">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <input type="text" id="inputBuscaReprova" placeholder="Filtrar por código (M1, E2...), falha ou família..." class="catalog-search-input" oninput="filtrarTabela()">
                </div>

                <?php 
                $countLab = count(array_filter($reprovas, fn($r) => str_contains((string)($r['local'] ?? ''), 'LAB')));
                $countIqf = count(array_filter($reprovas, fn($r) => str_contains((string)($r['local'] ?? ''), 'IQF')));
                $countGer = count(array_filter($reprovas, fn($r) => str_contains((string)($r['local'] ?? ''), 'GER')));
                ?>
                <div class="filter-btn-group">
                    <button type="button" class="filter-chip active" onclick="filtrarPorLocal('', this)">Todas (<?= count($reprovas) ?>)</button>
                    <button type="button" class="filter-chip" onclick="filtrarPorLocal('LAB', this)">Laboratório (<?= $countLab ?>)</button>
                    <button type="button" class="filter-chip" onclick="filtrarPorLocal('IQF', this)">Inspeção Final (<?= $countIqf ?>)</button>
                    <button type="button" class="filter-chip" onclick="filtrarPorLocal('GER', this)">Geral (<?= $countGer ?>)</button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="tabela-reprovas" id="tabelaReprovas">
                    <thead>
                        <tr>
                            <th style="width: 80px;">Código</th>
                            <th style="width: 160px;">Família</th>
                            <th>Descrição da Não-Conformidade</th>
                            <th style="width: 140px;">Setor Causador</th>
                            <th style="width: 130px; text-align: center;">Local</th>
                            <th style="width: 210px; text-align: right;">Tempo Médio (Minutos)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reprovas as $rep): 
                            $min = (int) ($rep['tempo_padrao_minutos'] ?? 60);
                            $horasCalc = round($min / 60, 2);
                            $locais = array_filter(array_map('trim', explode(',', (string)($rep['local'] ?? ''))));
                            if (empty($locais)) {
                                $locais = ['-'];
                            }
                        ?>
                            <tr class="reprova-row" 
                                data-codigo="<?= htmlspecialchars(mb_strtolower($rep['codigo'])) ?>"
                                data-descricao="<?= htmlspecialchars(mb_strtolower($rep['descricao'])) ?>"
                                data-familia="<?= htmlspecialchars(mb_strtolower($rep['familia'])) ?>"
                                data-local="<?= htmlspecialchars($rep['local']) ?>">
                                <td>
                                    <strong style="color: var(--brand-primary); font-family: 'JetBrains Mono', monospace;">
                                        <?= htmlspecialchars($rep['codigo']) ?>
                                    </strong>
                                </td>
                                <td>
                                    <span class="badge-tag ger"><?= htmlspecialchars($rep['familia']) ?></span>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($rep['descricao']) ?></strong>
                                </td>
                                <td>
                                    <span style="font-size: 0.8rem; color: #475569; font-weight: 500;">
                                        <?= htmlspecialchars($rep['setor_causador'] ?: '-') ?>
                                    </span>
                                </td>
                                <td style="text-align: center;">
                                    <div style="display: inline-flex; flex-wrap: wrap; gap: 4px; justify-content: center; align-items: center;">
                                        <?php foreach ($locais as $loc): 
                                            $locClean = trim($loc);
                                            $locClass = strtolower($locClean);
                                            if (!in_array($locClass, ['lab', 'iqf', 'ret', 'ger'], true)) {
                                                $locClass = 'ger';
                                            }
                                        ?>
                                            <span class="badge-tag <?= $locClass ?>"><?= htmlspecialchars($locClean) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: inline-flex; align-items: center; justify-content: flex-end; gap: 8px;">
                                        <input type="number" 
                                               name="reprovas_tempos[<?= (int) $rep['id'] ?>]" 
                                               value="<?= $min ?>" 
                                               min="0" 
                                               step="5"
                                               class="input-tempo-min"
                                               data-reprova-id="<?= (int) $rep['id'] ?>"
                                               oninput="onTempoInput(this)"
                                               onchange="autoSalvarReprova(this)"
                                               <?= !$podeGravar ? 'readonly disabled' : '' ?>>
                                        <span class="tempo-conversao">
                                             (<?= number_format($horasCalc, 2, ',', '.') ?> h)
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </form>
</div>

<script>
let filtroLocalAtual = '';
let debounceTimer = null;

function filtrarPorLocal(local, btn) {
    filtroLocalAtual = local;
    document.querySelectorAll('.filter-chip').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    filtrarTabela();
}

function filtrarTabela() {
    const busca = document.getElementById('inputBuscaReprova').value.toLowerCase().trim();
    const rows = document.querySelectorAll('.reprova-row');
    let visiveis = 0;

    rows.forEach(row => {
        const codigo = row.getAttribute('data-codigo') || '';
        const desc   = row.getAttribute('data-descricao') || '';
        const fam    = row.getAttribute('data-familia') || '';
        const local  = row.getAttribute('data-local') || '';

        const matchBusca = busca === '' || codigo.includes(busca) || desc.includes(busca) || fam.includes(busca);
        
        let matchLocal = true;
        if (filtroLocalAtual !== '') {
            const locaisArray = local.split(',').map(s => s.trim().toUpperCase());
            matchLocal = locaisArray.includes(filtroLocalAtual.toUpperCase());
        }

        if (matchBusca && matchLocal) {
            row.style.display = '';
            visiveis++;
        } else {
            row.style.display = 'none';
        }
    });

    const contador = document.getElementById('contadorReprovas');
    if (contador) {
        contador.textContent = `${visiveis} reprovas exibidas`;
    }
}

function onTempoInput(input) {
    const min = parseFloat(input.value) || 0;
    const horas = (min / 60).toFixed(2).replace('.', ',');
    const span = input.parentElement.querySelector('.tempo-conversao');
    if (span) {
        span.textContent = `(${horas} h)`;
    }

    // Auto-save com debounce de 600ms enquanto digita
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        autoSalvarReprova(input);
    }, 600);
}

function setAutoSaveStatus(status, text = '') {
    const el = document.getElementById('autoSaveIndicator');
    if (!el) return;

    if (status === 'saving') {
        el.className = 'auto-save-status saving';
        el.innerHTML = `
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="status-icon-spin">
                <line x1="12" y1="2" x2="12" y2="6"/>
                <line x1="12" y1="18" x2="12" y2="22"/>
                <line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/>
                <line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/>
                <line x1="2" y1="12" x2="6" y2="12"/>
                <line x1="18" y1="12" x2="22" y2="12"/>
                <line x1="4.93" y1="19.07" x2="7.76" y2="16.24"/>
                <line x1="16.24" y1="7.76" x2="19.07" y2="4.93"/>
            </svg>
            <span id="autoSaveText">${text || 'Salvando...'}</span>
        `;
    } else if (status === 'saved') {
        el.className = 'auto-save-status';
        el.innerHTML = `
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <polyline points="20 6 9 17 4 12"/>
            </svg>
            <span id="autoSaveText">${text || 'Salvo automaticamente'}</span>
        `;
    } else if (status === 'error') {
        el.className = 'auto-save-status error';
        el.innerHTML = `
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <circle cx="12" cy="12" r="10"/>
                <line x1="15" y1="9" x2="9" y2="15"/>
                <line x1="9" y1="9" x2="15" y2="15"/>
            </svg>
            <span id="autoSaveText">${text || 'Erro ao salvar'}</span>
        `;
    }
}

async function autoSalvarReprova(input) {
    const reprovaId = input.getAttribute('data-reprova-id');
    const minutos   = input.value;
    const token     = document.getElementById('csrfToken')?.value || '';

    setAutoSaveStatus('saving');

    const formData = new FormData();
    formData.append('csrf_token', token);
    formData.append('reprova_id', reprovaId);
    formData.append('tempo_minutos', minutos);

    const appBase = (typeof window.__APP_BASE === 'string') ? window.__APP_BASE : '<?= htmlspecialchars($base) ?>';

    try {
        const res = await fetch(appBase + '/api/retrabalho-custos-salvar.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();
        if (data.sucesso) {
            setAutoSaveStatus('saved', 'Salvo automaticamente');
        } else {
            setAutoSaveStatus('error', data.erro || 'Falha ao salvar');
        }
    } catch (err) {
        setAutoSaveStatus('error', 'Erro de conexão');
    }
}

// Garantir layout em tela única
document.addEventListener('DOMContentLoaded', () => {
    const appContent = document.querySelector('.app-content');
    if (appContent) {
        appContent.style.overflow = 'hidden';
        appContent.style.display = 'flex';
        appContent.style.flexDirection = 'column';
        appContent.style.padding = '14px 20px';
    }
});
</script>

<?php layoutFooter(); ?>
