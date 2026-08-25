<?php
declare(strict_types=1);

/**
 * SOMA — Leitor Inteligente de Folhas de Produção & Cronoanálise (OCR Trael)
 */

require_once dirname(__DIR__, 2) . '/config/conexao.php';
require_once dirname(__DIR__, 2) . '/config/session.php';
require_once dirname(__DIR__, 2) . '/includes/helpers.php';
require_once dirname(__DIR__, 2) . '/includes/soma-helpers.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

requirePerfil([1, 2, 3]);

$db = getDB();
somaGarantirTabelas($db);

// Carrega cadastros ativos
$operadores = $db->query('SELECT id, cod, nome FROM soma_operadores WHERE ativo = 1 AND deleted_at IS NULL ORDER BY nome ASC')->fetchAll();
$maquinas = $db->query('SELECT id, cod, nome, setor_local FROM soma_maquinas WHERE ativo = 1 AND deleted_at IS NULL ORDER BY nome ASC')->fetchAll();
$setores = $db->query('SELECT id, cod, descricao, meta FROM soma_setores WHERE ativo = 1 AND deleted_at IS NULL ORDER BY descricao ASC')->fetchAll();
$empresas = $db->query('SELECT id, cod, nome FROM soma_empresas WHERE ativo = 1 AND deleted_at IS NULL ORDER BY nome ASC')->fetchAll();
$motivosParada = $db->query('SELECT id, cod, descricao, tipo FROM soma_paradas_motivos WHERE ativo = 1 AND deleted_at IS NULL ORDER BY tipo ASC, CAST(cod AS UNSIGNED) ASC, cod ASC')->fetchAll();

layoutHeader('SOMA — Leitor de Folha de Produção (OCR)', 'soma');

$somaAbaAtual = 'digitador';
require dirname(__DIR__, 2) . '/includes/soma-subnav.php';
?>

<!-- CDN do Tesseract.js para OCR óptico -->
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>

<!-- CABEÇALHO DO MÓDULO -->
<div class="mb-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <div class="flex items-center gap-2">
            <h2 class="text-lg font-bold text-[#1a2133]">Leitor de Folhas de Produção</h2>
            <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-emerald-100 text-emerald-800 border border-emerald-300">
                ⚡ OCR Inteligente Trael
            </span>
        </div>
        <p class="text-xs text-[#5a6480] mt-0.5">
            Faça upload ou tire foto da folha física para extração automática de bobinador, máquina, peças e paradas.
        </p>
    </div>

    <div class="flex items-center gap-2">
        <div id="btn-trocar-folha-container" class="hidden">
            <button type="button" onclick="SOMA_LEITOR.limparLeitor()" class="btn btn-outline text-xs py-1.5 px-3 bg-white text-rose-600 border-rose-200 hover:bg-rose-50 flex items-center gap-1">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                Escanear Outra Folha
            </button>
        </div>

        <button type="button" onclick="SOMA_LEITOR.carregarDemonstracaoFolha()" class="btn btn-outline text-xs py-1.5 px-3 flex items-center gap-1.5 bg-white border-[#e2e6ed] hover:border-[#e8a020] text-[#1a2133] shadow-sm font-semibold">
            <span>📄</span> Exemplo de Folha (Bobinagem)
        </button>
    </div>
</div>

<!-- 1. ÁREA DE CAPTURA / UPLOAD DA FOLHA -->
<div class="card p-5 mb-5 border-2 border-dashed border-[#cbd5e1] hover:border-[#e8a020] transition-colors rounded-2xl bg-gradient-to-b from-[#f8fafc] to-white" id="leitor-dropzone">
    <input type="file" id="leitor-file-input" accept="image/*,.pdf" class="hidden">
    <input type="file" id="leitor-camera-input" accept="image/*" capture="environment" class="hidden">

    <div class="text-center py-4">
        <div class="w-14 h-14 mx-auto mb-2.5 rounded-2xl bg-[#fff7ed] border border-[#ffedd5] flex items-center justify-center text-[#e8a020] shadow-sm">
            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
        </div>

        <h3 class="text-sm font-bold text-[#1a2133]">Arraste e solte a foto da folha física aqui</h3>
        <p class="text-xs text-[#64748b] mt-0.5">Selecione uma foto da folha de Controle de Produção Individual ou use a câmera do aparelho.</p>

        <div class="mt-3.5 flex flex-wrap items-center justify-center gap-2.5">
            <button type="button" onclick="document.getElementById('leitor-file-input').click()" class="btn btn-primary text-xs py-2 px-4 shadow-sm flex items-center gap-2 bg-[#1a3d2a] hover:bg-[#132e20]">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                Carregar Foto da Folha
            </button>

            <button type="button" onclick="document.getElementById('leitor-camera-input').click()" class="btn btn-outline text-xs py-2 px-4 shadow-sm flex items-center gap-2 bg-white">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/></svg>
                Tirar Foto (Câmera)
            </button>
        </div>
    </div>
</div>

<!-- BARRA DE PROGRESSO DO OCR -->
<div id="leitor-progresso-container" class="card p-4 mb-5 hidden border-l-4 border-amber-500 bg-amber-50/60">
    <div class="flex items-center justify-between mb-2">
        <span class="text-xs font-bold text-amber-900 flex items-center gap-2">
            <span class="animate-spin inline-block">⏳</span>
            <span id="leitor-progresso-texto">Lendo folha física e extraindo campos...</span>
        </span>
        <span class="text-[11px] text-amber-700 font-mono font-bold">Processando...</span>
    </div>
    <div class="w-full bg-amber-200/60 rounded-full h-2 overflow-hidden">
        <div id="leitor-progresso-bar" class="bg-amber-600 h-2 rounded-full transition-all duration-300" style="width: 25%"></div>
    </div>
</div>

<!-- 2. FORMULÁRIO DE CONFERÊNCIA & GRAVAÇÃO (SPLIT VIEW) -->
<form id="form-digitador" onsubmit="enviarApontamentoTurno(event)">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="acao" value="salvar_lancamento_turno">

    <!-- DATALISTS PARA AUTOCOMPLETE DE OPERADORES E MÁQUINAS -->
    <datalist id="lista-operadores">
        <?php foreach ($operadores as $op): ?>
            <option value="<?= e($op['nome']) ?>"><?= e($op['cod']) ?></option>
        <?php endforeach; ?>
    </datalist>

    <datalist id="lista-maquinas">
        <?php foreach ($maquinas as $maq): ?>
            <option value="<?= e($maq['nome']) ?>"><?= e($maq['cod']) ?></option>
            <option value="<?= e($maq['cod']) ?>"></option>
        <?php endforeach; ?>
    </datalist>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">
        
        <!-- Preview da Imagem da Folha (Lado Esquerdo: 5 colunas) -->
        <div id="leitor-preview-container" class="lg:col-span-5 hidden">
            <div class="card p-3 sticky top-4 shadow-sm border border-[#e2e6ed]">
                <div class="flex items-center justify-between pb-2 mb-2 border-b border-[#e2e6ed]">
                    <span class="text-xs font-bold text-[#1a2133] flex items-center gap-1.5">
                        <span>📷</span> Foto Original da Folha
                    </span>
                    <div class="flex items-center gap-1">
                        <button type="button" onclick="SOMA_LEITOR.zoomIn()" class="w-6 h-6 rounded bg-[#f1f5f9] hover:bg-[#e2e6ed] text-xs font-bold text-[#334155]" title="Aumentar Zoom">+</button>
                        <button type="button" onclick="SOMA_LEITOR.zoomOut()" class="w-6 h-6 rounded bg-[#f1f5f9] hover:bg-[#e2e6ed] text-xs font-bold text-[#334155]" title="Diminuir Zoom">-</button>
                        <button type="button" onclick="SOMA_LEITOR.rotateImg()" class="w-6 h-6 rounded bg-[#f1f5f9] hover:bg-[#e2e6ed] text-xs font-bold text-[#334155]" title="Girar">🔄</button>
                        <button type="button" onclick="SOMA_LEITOR.resetView()" class="px-1.5 py-0.5 rounded bg-[#f1f5f9] hover:bg-[#e2e6ed] text-[10px] font-semibold text-[#334155]">Reset</button>
                    </div>
                </div>

                <div class="overflow-auto max-h-[600px] rounded-lg border border-[#e2e6ed] bg-[#0f172a]/5 flex items-center justify-center p-1.5">
                    <img id="leitor-preview-img" src="" alt="Folha de Produção" class="max-w-full h-auto transition-transform duration-200 origin-center rounded shadow">
                </div>
            </div>
        </div>

        <!-- Formulário de Campos Extraídos (Lado Direito: 7 colunas ou 12 se sem imagem) -->
        <div class="lg:col-span-12 space-y-5" id="leitor-form-container">
            
            <!-- 1. Identificação do Turno -->
            <div class="card p-4 shadow-sm border border-[#e2e6ed]">
                <div class="pb-2.5 border-b border-[#e2e6ed] mb-3.5 flex items-center justify-between">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-[#1a3d2a] flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-[#16a34a]"></span>
                        Identificação do Turno & Posto de Trabalho
                    </h3>
                    <span class="text-[11px] text-[#64748b]">Campos preenchidos automaticamente</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <div>
                        <label class="block text-[11px] font-semibold text-[#5a6480] mb-1">Data do Turno *</label>
                        <input type="date" name="data" required value="<?= date('Y-m-d') ?>" class="form-input text-xs font-medium">
                    </div>

                    <div>
                        <label class="block text-[11px] font-semibold text-[#5a6480] mb-1">Turno *</label>
                        <select name="turno" required class="form-input text-xs font-medium">
                            <option value="D" selected>Diurno (1º Turno)</option>
                            <option value="N">Noturno (2º Turno)</option>
                            <option value="M">Misto / Especial</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[11px] font-semibold text-[#5a6480] mb-1">Operador / Bobinador(a) *</label>
                        <input type="text" name="nome_operador" id="input-operador-nome" list="lista-operadores" required class="form-input text-xs font-semibold text-[#1a2133] bg-[#f8fafc] focus:bg-white" placeholder="Ex: Ana Paula D">
                    </div>

                    <div>
                        <label class="block text-[11px] font-semibold text-[#5a6480] mb-1">Máquina / Ativo *</label>
                        <input type="text" name="nome_maquina" id="input-maquina-nome" list="lista-maquinas" required class="form-input text-xs font-semibold text-[#1a2133] bg-[#f8fafc] focus:bg-white" placeholder="Ex: Máquina 40">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mt-3 pt-3 border-t border-[#f1f5f9]">
                    <div>
                        <label class="block text-[11px] font-semibold text-[#5a6480] mb-1">Setor Fabril *</label>
                        <select name="id_setor" id="select-setor" required class="form-input text-xs" onchange="recalcularTotais()">
                            <?php foreach ($setores as $st): ?>
                                <option value="<?= (int) $st['id'] ?>" data-meta="<?= (float) $st['meta'] ?>" <?= $st['id'] == 1 ? 'selected' : '' ?>>
                                    <?= e($st['cod'] . ' — ' . $st['descricao']) ?> (Meta: <?= number_format((float) $st['meta'], 0) ?>%)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[11px] font-semibold text-[#5a6480] mb-1">Hora Início</label>
                        <input type="time" name="h_inicio" id="h_inicio" value="07:30" class="form-input text-xs font-mono" onchange="calcularTempoDisponivel()">
                    </div>

                    <div>
                        <label class="block text-[11px] font-semibold text-[#5a6480] mb-1">Hora Fim (Saída)</label>
                        <input type="time" name="h_fim" id="h_fim" value="17:18" class="form-input text-xs font-mono" onchange="calcularTempoDisponivel()">
                    </div>

                    <div>
                        <label class="block text-[11px] font-semibold text-[#5a6480] mb-1">Tempo Disponível (Min)</label>
                        <input type="number" step="1" name="minutos_disponiveis" id="input-minutos-disponiveis" value="528" required class="form-input text-xs font-mono font-bold text-[#1a2133]" oninput="recalcularTotais()">
                    </div>
                </div>
            </div>

            <!-- 2. Peças Fabricadas & Tempos Padrão -->
            <div class="card p-4 shadow-sm border border-[#e2e6ed]">
                <div class="pb-2.5 border-b border-[#e2e6ed] mb-3 flex items-center justify-between">
                    <div>
                        <h3 class="text-xs font-bold uppercase tracking-wider text-[#1a3d2a] flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-[#16a34a]"></span>
                            Peças Fabricadas & Tempos Padrão (TP)
                        </h3>
                    </div>
                    <button type="button" onclick="adicionarLinhaPeca()" class="btn btn-outline text-xs py-1 px-2.5 bg-white">
                        + Adicionar Peça
                    </button>
                </div>

                <div class="overflow-x-auto border border-[#e2e6ed] rounded-lg">
                    <table class="w-full text-left text-xs" id="tabela-pecas">
                        <thead class="bg-[#f8f9fb] text-[#5a6480] border-b border-[#e2e6ed]">
                            <tr>
                                <th class="py-2 px-3 w-1/4">Projeto / Peça *</th>
                                <th class="py-2 px-3 w-1/3">Descrição / Operação</th>
                                <th class="py-2 px-3 text-right w-20">Qtd *</th>
                                <th class="py-2 px-3 text-right w-28">TP Unit. (min) *</th>
                                <th class="py-2 px-3 text-right w-28">Total Produzido</th>
                                <th class="py-2 px-3 text-center w-10"></th>
                            </tr>
                        </thead>
                        <tbody id="tbody-pecas" class="divide-y divide-[#e2e6ed]">
                            <!-- Linhas inseridas via JS -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 3. Paradas Apontadas -->
            <div class="card p-4 shadow-sm border border-[#e2e6ed]">
                <div class="pb-2.5 border-b border-[#e2e6ed] mb-3 flex items-center justify-between">
                    <div>
                        <h3 class="text-xs font-bold uppercase tracking-wider text-[#1a3d2a] flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-[#d97706]"></span>
                            Paradas e Interrupções Apontadas
                        </h3>
                    </div>
                    <button type="button" onclick="adicionarLinhaParada()" class="btn btn-outline text-xs py-1 px-2.5 bg-white">
                        + Adicionar Parada
                    </button>
                </div>

                <div class="overflow-x-auto border border-[#e2e6ed] rounded-lg">
                    <table class="w-full text-left text-xs" id="tabela-paradas">
                        <thead class="bg-[#f8f9fb] text-[#5a6480] border-b border-[#e2e6ed]">
                            <tr>
                                <th class="py-2 px-3 w-1/2">Motivo da Parada (Catálogo Oficial Trael) *</th>
                                <th class="py-2 px-3 text-right w-28">Duração (min) *</th>
                                <th class="py-2 px-3">Observação / Intervalo</th>
                                <th class="py-2 px-3 text-center w-10"></th>
                            </tr>
                        </thead>
                        <tbody id="tbody-paradas" class="divide-y divide-[#e2e6ed]">
                            <!-- Linhas inseridas via JS -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 4. Apuração de Eficiência e Gravação -->
            <div class="card p-4 bg-gradient-to-br from-white to-[#f8fafc] border border-[#e2e6ed] shadow-sm">
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 items-center mb-4 pb-3.5 border-b border-[#e2e6ed]">
                    <div class="p-2.5 bg-[#f1f5f9] rounded-xl text-center">
                        <span class="block text-[10px] text-[#64748b] font-medium uppercase tracking-wider">Tempo Disponível</span>
                        <strong class="text-sm font-mono text-[#0f172a]" id="card-tempo-disponivel">528 min</strong>
                    </div>

                    <div class="p-2.5 bg-emerald-50 rounded-xl text-center border border-emerald-200">
                        <span class="block text-[10px] text-emerald-700 font-medium uppercase tracking-wider">Tempo Produzido</span>
                        <strong class="text-sm font-mono text-emerald-950" id="card-tempo-produzido">0 min</strong>
                        <span class="block text-[10px] text-emerald-600 font-mono" id="card-tempo-produzido-h">0h 00min</span>
                    </div>

                    <div class="p-2.5 bg-amber-50 rounded-xl text-center border border-amber-200">
                        <span class="block text-[10px] text-amber-700 font-medium uppercase tracking-wider">Tempo em Paradas</span>
                        <strong class="text-sm font-mono text-amber-950" id="card-tempo-parado">0 min</strong>
                        <span class="block text-[10px] text-amber-600 font-mono" id="card-tempo-parado-h">0h 00min</span>
                    </div>

                    <div class="p-2.5 bg-slate-900 text-white rounded-xl text-center shadow">
                        <span class="block text-[10px] text-slate-400 font-medium uppercase tracking-wider">Eficiência (OEE)</span>
                        <strong class="text-lg font-mono text-emerald-400 font-bold" id="txt-eficiencia-val">0.0%</strong>
                        <span class="block text-[10px] text-slate-300" id="badge-eficiencia-status">[DENTRO DO PADRÃO]</span>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row items-center justify-between gap-3">
                    <button type="button" onclick="limparFormulario()" class="btn btn-outline text-xs py-2 px-3.5 text-[#64748b] bg-white">
                        🗑️ Limpar Campos
                    </button>

                    <button type="submit" id="btn-submit-turno" class="btn btn-primary text-xs py-2.5 px-6 font-bold shadow flex items-center gap-2 bg-[#16a34a] hover:bg-[#15803d] border-none text-white">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Confirmar & Gravar Turno no SOMA
                    </button>
                </div>
            </div>

        </div>

    </div>
</form>

<script>
window.SOMA_CSRF = '<?= csrfToken() ?>';
const MOTIVOS_PARADA = <?= json_encode($motivosParada) ?>;
const API_URL = '<?= APP_URL ?>/api/soma-acao.php';

// Inicializa linhas padrão ao carregar
document.addEventListener('DOMContentLoaded', () => {
    adicionarLinhaPeca();
    adicionarLinhaParada();
    calcularTempoDisponivel();
});

function calcularTempoDisponivel() {
    const ini = document.getElementById('h_inicio').value;
    const fim = document.getElementById('h_fim').value;
    if (ini && fim) {
        const [h1, m1] = ini.split(':').map(Number);
        const [h2, m2] = fim.split(':').map(Number);
        let min1 = h1 * 60 + m1;
        let min2 = h2 * 60 + m2;
        if (min2 < min1) min2 += 24 * 60; // Vira a noite
        const total = min2 - min1;
        if (total > 0) {
            document.getElementById('input-minutos-disponiveis').value = total;
        }
    }
    recalcularTotais();
}

function adicionarLinhaPeca(cod = '', desc = '', qtd = 1, tp = 0) {
    const tbody = document.getElementById('tbody-pecas');
    const tr = document.createElement('tr');
    tr.className = 'hover:bg-[#f8f9fb] transition-colors';
    tr.innerHTML = `
        <td class="py-2 px-3">
            <input type="text" class="form-input text-xs font-mono font-semibold peca-cod" value="${cod}" placeholder="Ex: 423536" required>
        </td>
        <td class="py-2 px-3">
            <input type="text" class="form-input text-xs peca-desc" value="${desc}" placeholder="Ex: Bobinagem AT Projeto...">
        </td>
        <td class="py-2 px-3">
            <input type="number" step="1" min="1" class="form-input text-xs text-right font-mono peca-qtd" value="${qtd}" oninput="recalcularTotais()" required>
        </td>
        <td class="py-2 px-3">
            <input type="number" step="0.01" min="0.01" class="form-input text-xs text-right font-mono peca-tp" value="${tp || ''}" placeholder="Ex: 0.8" oninput="recalcularTotais()" required>
        </td>
        <td class="py-2 px-3 text-right font-mono font-bold text-[#1a2133] peca-total">
            0 min
        </td>
        <td class="py-2 px-3 text-center">
            <button type="button" onclick="removerLinha(this)" class="text-rose-500 hover:text-rose-700 font-bold text-base">&times;</button>
        </td>
    `;
    tbody.appendChild(tr);
    recalcularTotais();
}

function adicionarLinhaParada(idOuCodMotivo = '', duracao = 0, obs = '') {
    const tbody = document.getElementById('tbody-paradas');
    const tr = document.createElement('tr');
    tr.className = 'hover:bg-[#f8f9fb] transition-colors';

    let options = '<option value="">-- Selecione o Motivo --</option>';
    MOTIVOS_PARADA.forEach(m => {
        // Match exato pelo código (ex: '9' ou '63') ou ID
        const isSelected = (String(m.cod) === String(idOuCodMotivo) || String(m.id) === String(idOuCodMotivo)) ? 'selected' : '';
        const tag = m.tipo === 'PROG' ? '[PROG]' : '[NÃO PROG]';
        options += `<option value="${m.id}" ${isSelected}>${m.cod} — ${m.descricao} ${tag}</option>`;
    });

    tr.innerHTML = `
        <td class="py-2 px-3">
            <select class="form-input text-xs parada-motivo" required onchange="recalcularTotais()">
                ${options}
            </select>
        </td>
        <td class="py-2 px-3">
            <input type="number" step="1" min="1" class="form-input text-xs text-right font-mono parada-duracao" value="${duracao || ''}" placeholder="Minutos" oninput="recalcularTotais()" required>
        </td>
        <td class="py-2 px-3">
            <input type="text" class="form-input text-xs parada-obs" value="${obs}" placeholder="Ex: 12:20 às 13:20">
        </td>
        <td class="py-2 px-3 text-center">
            <button type="button" onclick="removerLinha(this)" class="text-rose-500 hover:text-rose-700 font-bold text-base">&times;</button>
        </td>
    `;
    tbody.appendChild(tr);
    recalcularTotais();
}

function removerLinha(btn) {
    const tr = btn.closest('tr');
    const tbody = tr.parentElement;
    if (tbody.children.length > 1) {
        tr.remove();
        recalcularTotais();
    } else {
        Toast.warning('O turno deve conter ao menos uma linha.');
    }
}

function formatarHorasMinutos(minutos) {
    const m = Math.round(minutos);
    const h = Math.floor(m / 60);
    const rest = m % 60;
    return `${h}h ${String(rest).padStart(2, '0')}min`;
}

function recalcularTotais() {
    let totalProduzido = 0;
    document.querySelectorAll('#tbody-pecas tr').forEach(tr => {
        const qtd = parseFloat(tr.querySelector('.peca-qtd')?.value || 0);
        const tp = parseFloat(tr.querySelector('.peca-tp')?.value || 0);
        const sub = qtd * tp;
        totalProduzido += sub;
        const totalCell = tr.querySelector('.peca-total');
        if (totalCell) {
            totalCell.innerText = `${Math.round(sub)} min`;
        }
    });

    let totalParadas = 0;
    document.querySelectorAll('#tbody-paradas tr').forEach(tr => {
        const motivo = tr.querySelector('.parada-motivo')?.value;
        const dur = parseFloat(tr.querySelector('.parada-duracao')?.value || 0);
        if (motivo && dur > 0) {
            totalParadas += dur;
        }
    });

    const minutosDisp = parseFloat(document.getElementById('input-minutos-disponiveis').value || 0);
    const eficiencia = minutosDisp > 0 ? (totalProduzido / minutosDisp) * 100 : 0;

    // Atualiza Painel de Métricas
    document.getElementById('card-tempo-produzido').innerText = `${Math.round(totalProduzido)} min`;
    document.getElementById('card-tempo-produzido-h').innerText = formatarHorasMinutos(totalProduzido);

    document.getElementById('card-tempo-parado').innerText = `${Math.round(totalParadas)} min`;
    document.getElementById('card-tempo-parado-h').innerText = formatarHorasMinutos(totalParadas);

    document.getElementById('card-tempo-disponivel').innerText = `${Math.round(minutosDisp)} min`;

    const efStr = eficiencia.toFixed(1) + '%';
    document.getElementById('txt-eficiencia-val').innerText = efStr;

    // Busca meta do setor
    const selectSetor = document.getElementById('select-setor');
    const metaSelected = selectSetor.options[selectSetor.selectedIndex]?.getAttribute('data-meta');
    const metaVal = metaSelected ? parseFloat(metaSelected) : 80.0;

    const badgeStatus = document.getElementById('badge-eficiencia-status');
    if (eficiencia >= metaVal) {
        badgeStatus.className = 'block text-[10px] text-emerald-400 font-bold';
        badgeStatus.innerText = '[DENTRO DO PADRÃO]';
    } else if (eficiencia >= (metaVal * 0.75)) {
        badgeStatus.className = 'block text-[10px] text-amber-400 font-bold';
        badgeStatus.innerText = '[DESVIO MODERADO]';
    } else {
        badgeStatus.className = 'block text-[10px] text-rose-400 font-bold';
        badgeStatus.innerText = '[GARGALO CRÍTICO]';
    }
}

async function enviarApontamentoTurno(event) {
    event.preventDefault();

    const pecas = [];
    document.querySelectorAll('#tbody-pecas tr').forEach(tr => {
        const cod = tr.querySelector('.peca-cod')?.value.trim();
        const desc = tr.querySelector('.peca-desc')?.value.trim();
        const qtd = parseInt(tr.querySelector('.peca-qtd')?.value || '0', 10);
        const tp = parseFloat(tr.querySelector('.peca-tp')?.value || '0');
        if (cod && qtd > 0 && tp > 0) {
            pecas.push({ cod_peca: cod, descricao_peca: desc, qtd: qtd, tp_padrao_min: tp });
        }
    });

    if (pecas.length === 0) {
        Toast.error('Adicione pelo menos uma peça válida produzida no turno.');
        return;
    }

    const paradas = [];
    document.querySelectorAll('#tbody-paradas tr').forEach(tr => {
        const mot = tr.querySelector('.parada-motivo')?.value;
        const dur = parseFloat(tr.querySelector('.parada-duracao')?.value || '0');
        const obs = tr.querySelector('.parada-obs')?.value.trim();
        if (mot && dur > 0) {
            paradas.push({ id_motivo: parseInt(mot, 10), duracao_minutos: dur, observacao: obs });
        }
    });

    const form = document.getElementById('form-digitador');
    const formData = new FormData(form);
    formData.append('pecas', JSON.stringify(pecas));
    formData.append('paradas', JSON.stringify(paradas));

    const btn = document.getElementById('btn-submit-turno');
    btn.disabled = true;
    btn.innerHTML = '<span class="animate-spin inline-block mr-1">⏳</span> Gravando no SOMA...';

    try {
        const resp = await fetch(API_URL, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        const res = await resp.json();

        if (res.sucesso) {
            Toast.success(res.mensagem || 'Turno registrado com sucesso no SOMA!');
            setTimeout(() => {
                window.location.href = '<?= APP_URL ?>/pages/soma/registros.php';
            }, 1200);
        } else {
            Toast.error(res.erro || 'Falha ao registrar o turno.');
        }
    } catch (e) {
        Toast.error('Erro de conexão ao salvar apontamento.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg> Confirmar & Gravar Turno no SOMA';
    }
}

function limparFormulario() {
    if (confirm('Deseja realmente limpar todos os campos preenchidos?')) {
        document.getElementById('form-digitador').reset();
        document.getElementById('tbody-pecas').innerHTML = '';
        document.getElementById('tbody-paradas').innerHTML = '';
        adicionarLinhaPeca();
        adicionarLinhaParada();
        calcularTempoDisponivel();
        SOMA_LEITOR.limparLeitor();
    }
}
</script>

<script src="<?= APP_URL ?>/assets/js/soma-leitor.js"></script>

<?php
layoutFooter();
