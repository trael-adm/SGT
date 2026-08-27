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

// ─── Carregar Parâmetros Globais ─────────────────────────────────────────────
$configRows = [];
try {
    $configRows = $pdo->query("SELECT chave, valor FROM retrabalho_configuracoes")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (\Throwable $e) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS retrabalho_configuracoes (
                chave VARCHAR(50) NOT NULL PRIMARY KEY,
                valor VARCHAR(255) NOT NULL,
                descricao VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $configRows = $pdo->query("SELECT chave, valor FROM retrabalho_configuracoes")->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (\Throwable $e2) {}
}
$custoHoraHomem   = (float) ($configRows['custo_hora_homem'] ?? 45.00);
$horasTrabalhoDia = (float) ($configRows['horas_trabalho_dia'] ?? 8.80);

// ─── Carregar Catálogo de Reprovas com Tempos ────────────────────────────────
$reprovas = [];
try {
    $reprovas = $pdo->query("
        SELECT id, codigo, familia, descricao, local, setor_causador, tempo_padrao_minutos, ativo, ordem
        FROM reprovas
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
   TELA DE PARÂMETROS E TEMPOS DE REPROVAS — SGT
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

.custos-page-wrapper {
    max-width: 1400px;
    width: 100%;
    margin: 0 auto;
    padding: 16px 20px 48px;
    font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
    color: var(--text-main);
}

/* ─── Top Bar ────────────────────────────────────────────────────────────── */
.custos-top-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    background: #ffffff;
    border: 1px solid var(--border-color);
    border-radius: 14px;
    padding: 16px 22px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    margin-bottom: 24px;
}

.custos-title-wrap {
    display: flex;
    align-items: center;
    gap: 14px;
}

.custos-icon-badge {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: linear-gradient(135deg, var(--brand-primary), var(--brand-primary-light));
    color: var(--brand-gold);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    box-shadow: 0 4px 10px rgba(19, 58, 39, 0.18);
}

.custos-title-wrap h1 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--brand-primary);
    letter-spacing: -0.02em;
}

.custos-title-wrap p {
    margin: 2px 0 0;
    font-size: 0.85rem;
    color: var(--text-muted);
}

/* ─── Indicador de Auto-Save ─────────────────────────────────────────────── */
.auto-save-status {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 7px 14px;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 9999px;
    font-size: 0.82rem;
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

/* ─── Cards e Seções ─────────────────────────────────────────────────────── */
.custos-card {
    background: #ffffff;
    border: 1px solid var(--border-color);
    border-radius: 14px;
    padding: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    margin-bottom: 24px;
}

.custos-card-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    border-bottom: 1px solid #f1f5f9;
    padding-bottom: 16px;
    margin-bottom: 20px;
}

.custos-card-header h2 {
    margin: 0;
    font-size: 1.05rem;
    font-weight: 700;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 10px;
}

.custos-card-header p {
    margin: 4px 0 0;
    font-size: 0.8rem;
    color: var(--text-muted);
}

/* ─── Grid de Parâmetros Globais ─────────────────────────────────────────── */
.params-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
}

.param-input-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 16px;
    transition: border-color 0.2s ease;
}

.param-input-box:focus-within {
    border-color: var(--brand-primary-light);
    background: #ffffff;
}

.param-input-box label {
    display: block;
    font-size: 0.78rem;
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
    font-size: 0.95rem;
}

.param-input-wrapper input {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 8px 12px;
    font-family: 'JetBrains Mono', monospace;
    font-size: 1.1rem;
    font-weight: 700;
    color: #0f172a;
    background: #ffffff;
    outline: none;
    transition: all 0.2s ease;
}

.param-input-wrapper input:focus {
    border-color: var(--brand-primary);
    box-shadow: 0 0 0 3px rgba(19, 58, 39, 0.12);
}

.param-helper {
    font-size: 0.75rem;
    color: #64748b;
    margin-top: 6px;
    line-height: 1.4;
}

/* ─── Filtros Rápidos do Catálogo ────────────────────────────────────────── */
.catalog-filter-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 12px 16px;
    margin-bottom: 16px;
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
    border-radius: 8px;
    padding: 7px 12px;
    font-size: 0.86rem;
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
    padding: 5px 12px;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.filter-chip.active {
    background: var(--brand-primary);
    color: #ffffff;
    border-color: var(--brand-primary);
}

/* ─── Tabela de Reprovas ─────────────────────────────────────────────────── */
.table-responsive {
    overflow-x: auto;
    max-height: 620px;
}

.tabela-reprovas {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.88rem;
}

.tabela-reprovas th {
    background: #f1f5f9;
    color: #334155;
    font-weight: 700;
    text-align: left;
    padding: 10px 14px;
    border-bottom: 2px solid #e2e8f0;
    font-size: 0.76rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    position: sticky;
    top: 0;
    z-index: 10;
}

.tabela-reprovas td {
    padding: 10px 14px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}

.tabela-reprovas tr:hover td {
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

.input-tempo-min {
    width: 90px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    padding: 6px 10px;
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.95rem;
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
    font-size: 0.78rem;
    color: #64748b;
    min-width: 60px;
    text-align: right;
}
</style>

<div class="custos-page-wrapper">
    <!-- Top Control Bar -->
    <div class="custos-top-bar">
        <div class="custos-title-wrap">
            <div class="custos-icon-badge">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
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

        <!-- Bloco 1: Parâmetros de Mão-de-Obra -->
        <div class="custos-card">
            <div class="custos-card-header">
                <div>
                    <h2>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-amber-500">
                            <line x1="12" y1="1" x2="12" y2="23"/>
                            <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                        </svg>
                        Taxas Globais de Mão-de-Obra
                    </h2>
                    <p>Valores utilizados para converter os tempos das reprovas em custos operacionais (R$)</p>
                </div>
            </div>

            <div class="params-grid">
                <div class="param-input-box">
                    <label for="custo_hora_homem">Custo Médio da Hora-Homem (R$/h)</label>
                    <div class="param-input-wrapper">
                        <span class="prefix">R$</span>
                        <input type="text" id="custo_hora_homem" name="custo_hora_homem" 
                               value="<?= number_format($custoHoraHomem, 2, ',', '.') ?>" 
                               onchange="autoSalvarParametros()"
                               <?= !$podeGravar ? 'readonly disabled' : '' ?> required>
                    </div>
                    <div class="param-helper">Fórmula aplicada: <strong>Custo MO = (Minutos ÷ 60) × Taxa R$/h</strong>.</div>
                </div>

                <div class="param-input-box">
                    <label for="horas_trabalho_dia">Horas Padrão por Dia Útil (h/dia)</label>
                    <div class="param-input-wrapper">
                        <input type="text" id="horas_trabalho_dia" name="horas_trabalho_dia" 
                               value="<?= number_format($horasTrabalhoDia, 2, ',', '.') ?>" 
                               onchange="autoSalvarParametros()"
                               <?= !$podeGravar ? 'readonly disabled' : '' ?> required>
                        <span class="prefix">h/dia</span>
                    </div>
                    <div class="param-helper">Base diária de expediente da fábrica para métricas de produtividade.</div>
                </div>
            </div>
        </div>

        <!-- Bloco 2: Catálogo de Tempos por Reprova -->
        <div class="custos-card">
            <div class="custos-card-header">
                <div>
                    <h2>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-emerald-600">
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
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-slate-400">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <input type="text" id="inputBuscaReprova" placeholder="Filtrar por código (M1, E2...), falha ou família..." class="catalog-search-input" oninput="filtrarTabela()">
                </div>

                <?php 
                $countLab = count(array_filter($reprovas, fn($r) => $r['local'] === 'LAB'));
                $countIqf = count(array_filter($reprovas, fn($r) => $r['local'] === 'IQF'));
                $countGer = count(array_filter($reprovas, fn($r) => $r['local'] === 'GER'));
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
                            <th style="width: 90px; text-align: center;">Local</th>
                            <th style="width: 220px; text-align: right;">Tempo Médio (Minutos)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reprovas as $rep): 
                            $min = (int) ($rep['tempo_padrao_minutos'] ?? 60);
                            $horasCalc = round($min / 60, 2);
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
                                    <span style="font-size: 0.8rem; color: #475569;">
                                        <?= htmlspecialchars($rep['setor_causador'] ?: '-') ?>
                                    </span>
                                </td>
                                <td style="text-align: center;">
                                    <span class="badge-tag <?= strtolower($rep['local']) ?>">
                                        <?= htmlspecialchars($rep['local']) ?>
                                    </span>
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
        const matchLocal = filtroLocalAtual === '' || local === filtroLocalAtual;

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

async function autoSalvarParametros() {
    setAutoSaveStatus('saving');

    const form = document.getElementById('formCustos');
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
            setAutoSaveStatus('saved', 'Parâmetros salvos');
        } else {
            setAutoSaveStatus('error', data.erro || 'Falha ao salvar');
        }
    } catch (err) {
        setAutoSaveStatus('error', 'Erro de conexão');
    }
}

</script>

<?php layoutFooter(); ?>
