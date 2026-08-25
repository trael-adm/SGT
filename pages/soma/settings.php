<?php
declare(strict_types=1);

/**
 * SOMA — Configurações e Cadastros Base
 */

require_once dirname(__DIR__, 2) . '/config/conexao.php';
require_once dirname(__DIR__, 2) . '/config/session.php';
require_once dirname(__DIR__, 2) . '/includes/helpers.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

requirePerfil([1, 2]);

$db = getDB();

// 1. Carrega dados de cada domínio
$operadores = $db->query('SELECT * FROM soma_operadores WHERE deleted_at IS NULL ORDER BY nome ASC')->fetchAll();
$maquinas = $db->query('SELECT * FROM soma_maquinas WHERE deleted_at IS NULL ORDER BY nome ASC')->fetchAll();
$setores = $db->query('SELECT * FROM soma_setores WHERE deleted_at IS NULL ORDER BY descricao ASC')->fetchAll();
$empresas = $db->query('SELECT * FROM soma_empresas WHERE deleted_at IS NULL ORDER BY nome ASC')->fetchAll();
$motivosParada = $db->query('SELECT * FROM soma_paradas_motivos WHERE deleted_at IS NULL ORDER BY tipo ASC, descricao ASC')->fetchAll();

layoutHeader('SOMA — Configurações e Cadastros', 'soma');

$somaAbaAtual = 'settings';
require dirname(__DIR__, 2) . '/includes/soma-subnav.php';
?>

<div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <h2 class="text-base font-bold text-[#1a2133]">Parâmetros de Chão de Fábrica</h2>
        <p class="text-xs text-[#5a6480] mt-0.5">Gerenciamento de operadores, ativos industriais, centros de trabalho e motivos de parada.</p>
    </div>
    <div class="flex items-center gap-2">
        <span class="badge badge-neutral text-xs font-mono">
            <?= count($operadores) + count($maquinas) + count($setores) + count($empresas) + count($motivosParada) ?> itens cadastrados
        </span>
    </div>
</div>

<!-- Abas de Navegação Internas (Pills) -->
<div class="flex items-center gap-2 overflow-x-auto pb-2 border-b border-[#e2e6ed] mb-6">
    <button type="button" onclick="trocarAbaConfig('operadores')" id="tab-btn-operadores" class="tab-config-pill active">
        Operadores (<?= count($operadores) ?>)
    </button>
    <button type="button" onclick="trocarAbaConfig('maquinas')" id="tab-btn-maquinas" class="tab-config-pill">
        Máquinas & Ativos (<?= count($maquinas) ?>)
    </button>
    <button type="button" onclick="trocarAbaConfig('setores')" id="tab-btn-setores" class="tab-config-pill">
        Setores Fabris (<?= count($setores) ?>)
    </button>
    <button type="button" onclick="trocarAbaConfig('empresas')" id="tab-btn-empresas" class="tab-config-pill">
        Empresas / Unidades (<?= count($empresas) ?>)
    </button>
    <button type="button" onclick="trocarAbaConfig('paradas')" id="tab-btn-paradas" class="tab-config-pill">
        Motivos de Parada (<?= count($motivosParada) ?>)
    </button>
</div>

<!-- ======================================================== -->
<!-- 1. ABA: OPERADORES -->
<!-- ======================================================== -->
<div id="aba-operadores" class="tab-config-conteudo">
    <div class="card p-4 space-y-4">
        <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 pb-3 border-b border-[#e2e6ed]">
            <div>
                <h3 class="text-sm font-bold text-[#1a2133]">Operadores e Cronometristas</h3>
                <p class="text-[11px] text-[#5a6480]">Cadastro de colaboradores responsáveis pelas etapas de manufatura.</p>
            </div>
            <button type="button" onclick="abrirModalOperador()" class="btn btn-primary text-xs py-2 px-3">
                + Novo Operador
            </button>
        </div>

        <div class="overflow-x-auto border border-[#e2e6ed] rounded-lg">
            <table class="w-full text-left text-xs">
                <thead class="bg-[#f8f9fb] text-[#5a6480] border-b border-[#e2e6ed]">
                    <tr>
                        <th class="py-2.5 px-3 w-28">Código</th>
                        <th class="py-2.5 px-3">Nome do Operador</th>
                        <th class="py-2.5 px-3 text-center w-28">Status</th>
                        <th class="py-2.5 px-3 text-center w-24">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#e2e6ed]">
                    <?php if (empty($operadores)): ?>
                        <tr><td colspan="4" class="text-center py-6 text-[#9aa3b8]">Nenhum operador cadastrado.</td></tr>
                    <?php else: foreach ($operadores as $op): ?>
                        <tr class="hover:bg-[#f8f9fb] transition">
                            <td class="py-2 px-3 font-mono font-bold text-[#1a2133]"><?= e($op['cod']) ?></td>
                            <td class="py-2 px-3 font-medium text-[#1a2133]"><?= e($op['nome']) ?></td>
                            <td class="py-2 px-3 text-center">
                                <?= (int) $op['ativo'] === 1 ? '<span class="badge badge-success">Ativo</span>' : '<span class="badge badge-neutral">Inativo</span>' ?>
                            </td>
                            <td class="py-2 px-3 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <button type="button" onclick="editarOperador(<?= htmlspecialchars(json_encode($op)) ?>)" class="text-xs text-blue-600 hover:underline">Editar</button>
                                    <button type="button" onclick="excluirItem('excluir_operador', <?= (int) $op['id'] ?>, '<?= e($op['nome']) ?>')" class="text-xs text-red-600 hover:underline">Excluir</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- 2. ABA: MÁQUINAS -->
<!-- ======================================================== -->
<div id="aba-maquinas" class="tab-config-conteudo hidden">
    <div class="card p-4 space-y-4">
        <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 pb-3 border-b border-[#e2e6ed]">
            <div>
                <h3 class="text-sm font-bold text-[#1a2133]">Máquinas & Ativos Industriais</h3>
                <p class="text-[11px] text-[#5a6480]">Postos de trabalho, tornos, pontes e bobinadeiras monitoradas.</p>
            </div>
            <button type="button" onclick="abrirModalMaquina()" class="btn btn-primary text-xs py-2 px-3">
                + Nova Máquina
            </button>
        </div>

        <div class="overflow-x-auto border border-[#e2e6ed] rounded-lg">
            <table class="w-full text-left text-xs">
                <thead class="bg-[#f8f9fb] text-[#5a6480] border-b border-[#e2e6ed]">
                    <tr>
                        <th class="py-2.5 px-3 w-28">Código</th>
                        <th class="py-2.5 px-3">Nome da Máquina</th>
                        <th class="py-2.5 px-3 w-32">Patrimônio</th>
                        <th class="py-2.5 px-3">Setor Local</th>
                        <th class="py-2.5 px-3 text-center w-28">Status</th>
                        <th class="py-2.5 px-3 text-center w-24">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#e2e6ed]">
                    <?php if (empty($maquinas)): ?>
                        <tr><td colspan="6" class="text-center py-6 text-[#9aa3b8]">Nenhuma máquina cadastrada.</td></tr>
                    <?php else: foreach ($maquinas as $maq): ?>
                        <tr class="hover:bg-[#f8f9fb] transition">
                            <td class="py-2 px-3 font-mono font-bold text-[#1a2133]"><?= e($maq['cod']) ?></td>
                            <td class="py-2 px-3 font-medium text-[#1a2133]"><?= e($maq['nome']) ?></td>
                            <td class="py-2 px-3 font-mono text-[#5a6480]"><?= e($maq['patrimonio'] ?? '—') ?></td>
                            <td class="py-2 px-3 text-[#5a6480]"><?= e($maq['setor_local'] ?? '—') ?></td>
                            <td class="py-2 px-3 text-center">
                                <?= (int) $maq['ativo'] === 1 ? '<span class="badge badge-success">Ativo</span>' : '<span class="badge badge-neutral">Inativo</span>' ?>
                            </td>
                            <td class="py-2 px-3 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <button type="button" onclick="editarMaquina(<?= htmlspecialchars(json_encode($maq)) ?>)" class="text-xs text-blue-600 hover:underline">Editar</button>
                                    <button type="button" onclick="excluirItem('excluir_maquina', <?= (int) $maq['id'] ?>, '<?= e($maq['nome']) ?>')" class="text-xs text-red-600 hover:underline">Excluir</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- 3. ABA: SETORES -->
<!-- ======================================================== -->
<div id="aba-setores" class="tab-config-conteudo hidden">
    <div class="card p-4 space-y-4">
        <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 pb-3 border-b border-[#e2e6ed]">
            <div>
                <h3 class="text-sm font-bold text-[#1a2133]">Setores Fabris</h3>
                <p class="text-[11px] text-[#5a6480]">Centros de trabalho e respectivas metas de eficiência da fábrica.</p>
            </div>
            <button type="button" onclick="abrirModalSetor()" class="btn btn-primary text-xs py-2 px-3">
                + Novo Setor
            </button>
        </div>

        <div class="overflow-x-auto border border-[#e2e6ed] rounded-lg">
            <table class="w-full text-left text-xs">
                <thead class="bg-[#f8f9fb] text-[#5a6480] border-b border-[#e2e6ed]">
                    <tr>
                        <th class="py-2.5 px-3 w-28">Código</th>
                        <th class="py-2.5 px-3">Descrição do Setor</th>
                        <th class="py-2.5 px-3 text-right w-32">Meta Padrão</th>
                        <th class="py-2.5 px-3 text-center w-28">Status</th>
                        <th class="py-2.5 px-3 text-center w-24">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#e2e6ed]">
                    <?php if (empty($setores)): ?>
                        <tr><td colspan="5" class="text-center py-6 text-[#9aa3b8]">Nenhum setor cadastrado.</td></tr>
                    <?php else: foreach ($setores as $st): ?>
                        <tr class="hover:bg-[#f8f9fb] transition">
                            <td class="py-2 px-3 font-mono font-bold text-[#1a2133]"><?= e($st['cod']) ?></td>
                            <td class="py-2 px-3 font-medium text-[#1a2133]"><?= e($st['descricao']) ?></td>
                            <td class="py-2 px-3 text-right font-mono font-bold text-emerald-700"><?= number_format((float) $st['meta'], 1) ?>%</td>
                            <td class="py-2 px-3 text-center">
                                <?= (int) $st['ativo'] === 1 ? '<span class="badge badge-success">Ativo</span>' : '<span class="badge badge-neutral">Inativo</span>' ?>
                            </td>
                            <td class="py-2 px-3 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <button type="button" onclick="editarSetor(<?= htmlspecialchars(json_encode($st)) ?>)" class="text-xs text-blue-600 hover:underline">Editar</button>
                                    <button type="button" onclick="excluirItem('excluir_setor', <?= (int) $st['id'] ?>, '<?= e($st['descricao']) ?>')" class="text-xs text-red-600 hover:underline">Excluir</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- 4. ABA: EMPRESAS -->
<!-- ======================================================== -->
<div id="aba-empresas" class="tab-config-conteudo hidden">
    <div class="card p-4 space-y-4">
        <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 pb-3 border-b border-[#e2e6ed]">
            <div>
                <h3 class="text-sm font-bold text-[#1a2133]">Empresas / Unidades Trael</h3>
                <p class="text-[11px] text-[#5a6480]">Filiais e unidades fabris para segregação de apontamento.</p>
            </div>
            <button type="button" onclick="abrirModalEmpresa()" class="btn btn-primary text-xs py-2 px-3">
                + Nova Empresa
            </button>
        </div>

        <div class="overflow-x-auto border border-[#e2e6ed] rounded-lg">
            <table class="w-full text-left text-xs">
                <thead class="bg-[#f8f9fb] text-[#5a6480] border-b border-[#e2e6ed]">
                    <tr>
                        <th class="py-2.5 px-3 w-32">Código</th>
                        <th class="py-2.5 px-3">Razão Social / Nome da Unidade</th>
                        <th class="py-2.5 px-3 text-center w-28">Status</th>
                        <th class="py-2.5 px-3 text-center w-24">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#e2e6ed]">
                    <?php if (empty($empresas)): ?>
                        <tr><td colspan="4" class="text-center py-6 text-[#9aa3b8]">Nenhuma empresa cadastrada.</td></tr>
                    <?php else: foreach ($empresas as $emp): ?>
                        <tr class="hover:bg-[#f8f9fb] transition">
                            <td class="py-2 px-3 font-mono font-bold text-[#1a2133]"><?= e($emp['cod']) ?></td>
                            <td class="py-2 px-3 font-medium text-[#1a2133]"><?= e($emp['nome']) ?></td>
                            <td class="py-2 px-3 text-center">
                                <?= (int) $emp['ativo'] === 1 ? '<span class="badge badge-success">Ativo</span>' : '<span class="badge badge-neutral">Inativo</span>' ?>
                            </td>
                            <td class="py-2 px-3 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <button type="button" onclick="editarEmpresa(<?= htmlspecialchars(json_encode($emp)) ?>)" class="text-xs text-blue-600 hover:underline">Editar</button>
                                    <button type="button" onclick="excluirItem('excluir_empresa', <?= (int) $emp['id'] ?>, '<?= e($emp['nome']) ?>')" class="text-xs text-red-600 hover:underline">Excluir</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- 5. ABA: MOTIVOS DE PARADA -->
<!-- ======================================================== -->
<div id="aba-paradas" class="tab-config-conteudo hidden">
    <div class="card p-4 space-y-4">
        <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 pb-3 border-b border-[#e2e6ed]">
            <div>
                <h3 class="text-sm font-bold text-[#1a2133]">Motivos de Parada Industriais</h3>
                <p class="text-[11px] text-[#5a6480]">Tipificação de paradas programadas e não programadas (quebras/gargalos).</p>
            </div>
            <button type="button" onclick="abrirModalParada()" class="btn btn-primary text-xs py-2 px-3">
                + Novo Motivo
            </button>
        </div>

        <div class="overflow-x-auto border border-[#e2e6ed] rounded-lg">
            <table class="w-full text-left text-xs">
                <thead class="bg-[#f8f9fb] text-[#5a6480] border-b border-[#e2e6ed]">
                    <tr>
                        <th class="py-2.5 px-3 w-32">Código</th>
                        <th class="py-2.5 px-3">Descrição do Motivo</th>
                        <th class="py-2.5 px-3 text-center w-36">Natureza</th>
                        <th class="py-2.5 px-3 text-center w-28">Status</th>
                        <th class="py-2.5 px-3 text-center w-24">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#e2e6ed]">
                    <?php if (empty($motivosParada)): ?>
                        <tr><td colspan="5" class="text-center py-6 text-[#9aa3b8]">Nenhum motivo de parada cadastrado.</td></tr>
                    <?php else: foreach ($motivosParada as $mp): ?>
                        <tr class="hover:bg-[#f8f9fb] transition">
                            <td class="py-2 px-3 font-mono font-bold text-[#1a2133]"><?= e($mp['cod']) ?></td>
                            <td class="py-2 px-3 font-medium text-[#1a2133]"><?= e($mp['descricao']) ?></td>
                            <td class="py-2 px-3 text-center">
                                <?= $mp['tipo'] === 'PROG' 
                                    ? '<span class="badge badge-info text-[10px]">Programada</span>' 
                                    : '<span class="badge badge-danger text-[10px]">Não Programada</span>' ?>
                            </td>
                            <td class="py-2 px-3 text-center">
                                <?= (int) $mp['ativo'] === 1 ? '<span class="badge badge-success">Ativo</span>' : '<span class="badge badge-neutral">Inativo</span>' ?>
                            </td>
                            <td class="py-2 px-3 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <button type="button" onclick="editarParada(<?= htmlspecialchars(json_encode($mp)) ?>)" class="text-xs text-blue-600 hover:underline">Editar</button>
                                    <button type="button" onclick="excluirItem('excluir_motivo_parada', <?= (int) $mp['id'] ?>, '<?= e($mp['descricao']) ?>')" class="text-xs text-red-600 hover:underline">Excluir</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Genérico de Cadastro / Edição -->
<div id="modal-config" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl max-w-lg w-full overflow-hidden animate-in fade-in zoom-in-95 duration-150">
        
        <form id="modal-form" onsubmit="enviarFormularioConfig(event)">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="acao" id="form-acao" value="">
            <input type="hidden" name="id" id="form-id" value="">

            <div class="py-3 px-6 bg-[#f8f9fb] border-b border-[#e2e6ed] flex items-center justify-between">
                <h3 class="text-sm font-bold text-[#1a2133]" id="modal-titulo">Cadastrar</h3>
                <button type="button" onclick="fecharModalConfig()" class="text-[#9aa3b8] hover:text-[#1a2133] text-xl font-bold">&times;</button>
            </div>

            <div class="p-6 space-y-4 text-xs" id="form-campos-dinamicos">
                <!-- Campos injetados via JS -->
            </div>

            <div class="py-3 px-6 bg-[#f8f9fb] border-t border-[#e2e6ed] flex justify-end gap-2">
                <button type="button" onclick="fecharModalConfig()" class="btn btn-neutral text-xs">
                    Cancelar
                </button>
                <button type="submit" id="modal-btn-submit" class="btn btn-primary text-xs font-bold">
                    Salvar Registro
                </button>
            </div>
        </form>

    </div>
</div>

<style>
.tab-config-pill {
    padding: 0.4rem 0.85rem;
    font-size: 0.75rem;
    font-weight: 600;
    color: #5a6480;
    border-radius: 0.5rem;
    background: transparent;
    border: 1px solid transparent;
    white-space: nowrap;
    cursor: pointer;
    transition: all 0.15s ease;
}
.tab-config-pill:hover {
    color: #1a2133;
    background: #f8f9fb;
}
.tab-config-pill.active {
    color: #1a3d2a;
    background: #fef3dc;
    border-color: #fbd38d;
    font-weight: 700;
}
</style>

<script>
const API_URL = '<?= APP_URL ?>/api/soma-acao.php';
const CSRF_TOKEN = '<?= csrfToken() ?>';

function trocarAbaConfig(abaId) {
    document.querySelectorAll('.tab-config-conteudo').forEach(el => el.classList.add('hidden'));
    document.querySelectorAll('.tab-config-pill').forEach(el => el.classList.remove('active'));

    const tabEl = document.getElementById('aba-' + abaId);
    const btnEl = document.getElementById('tab-btn-' + abaId);

    if (tabEl) tabEl.classList.remove('hidden');
    if (btnEl) btnEl.classList.add('active');
}

function abrirModalConfig() {
    const modal = document.getElementById('modal-config');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function fecharModalConfig() {
    const modal = document.getElementById('modal-config');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

// 1. Operador
function abrirModalOperador() {
    document.getElementById('modal-titulo').innerText = 'Cadastrar Novo Operador';
    document.getElementById('form-acao').value = 'salvar_operador';
    document.getElementById('form-id').value = '';
    document.getElementById('form-campos-dinamicos').innerHTML = `
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
                <label class="block text-xs font-semibold text-[#5a6480] mb-1">Código *</label>
                <input type="text" name="cod" required placeholder="OP-005" class="form-input text-xs font-mono uppercase">
            </div>
            <div class="sm:col-span-2">
                <label class="block text-xs font-semibold text-[#5a6480] mb-1">Nome Completo *</label>
                <input type="text" name="nome" required placeholder="Ex.: João da Silva" class="form-input text-xs">
            </div>
        </div>
        <div>
            <label class="block text-xs font-semibold text-[#5a6480] mb-1">Status</label>
            <select name="ativo" class="form-input text-xs">
                <option value="1">Ativo</option>
                <option value="0">Inativo</option>
            </select>
        </div>
    `;
    abrirModalConfig();
}

function editarOperador(op) {
    abrirModalOperador();
    document.getElementById('modal-titulo').innerText = 'Editar Operador (' + op.cod + ')';
    document.getElementById('form-id').value = op.id;
    document.querySelector('[name="cod"]').value = op.cod;
    document.querySelector('[name="nome"]').value = op.nome;
    document.querySelector('[name="ativo"]').value = op.ativo;
}

// 2. Máquina
function abrirModalMaquina() {
    document.getElementById('modal-titulo').innerText = 'Cadastrar Nova Máquina / Ativo';
    document.getElementById('form-acao').value = 'salvar_maquina';
    document.getElementById('form-id').value = '';
    document.getElementById('form-campos-dinamicos').innerHTML = `
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-xs font-semibold text-[#5a6480] mb-1">Código *</label>
                <input type="text" name="cod" required placeholder="BOB-03" class="form-input text-xs font-mono uppercase">
            </div>
            <div>
                <label class="block text-xs font-semibold text-[#5a6480] mb-1">Nº Patrimônio</label>
                <input type="text" name="patrimonio" placeholder="PAT-10520" class="form-input text-xs font-mono">
            </div>
        </div>
        <div>
            <label class="block text-xs font-semibold text-[#5a6480] mb-1">Nome da Máquina *</label>
            <input type="text" name="nome" required placeholder="Ex.: Bobinadeira MT 03" class="form-input text-xs">
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-xs font-semibold text-[#5a6480] mb-1">Setor Local</label>
                <input type="text" name="setor_local" placeholder="Ex.: Bobinagem MT/BT" class="form-input text-xs">
            </div>
            <div>
                <label class="block text-xs font-semibold text-[#5a6480] mb-1">Status</label>
                <select name="ativo" class="form-input text-xs">
                    <option value="1">Ativo</option>
                    <option value="0">Inativo</option>
                </select>
            </div>
        </div>
        <div>
            <label class="block text-xs font-semibold text-[#5a6480] mb-1">Descrição / Especificações</label>
            <textarea name="descricao_completa" rows="2" placeholder="Detalhes técnicos do ativo..." class="form-input text-xs"></textarea>
        </div>
    `;
    abrirModalConfig();
}

function editarMaquina(maq) {
    abrirModalMaquina();
    document.getElementById('modal-titulo').innerText = 'Editar Máquina (' + maq.cod + ')';
    document.getElementById('form-id').value = maq.id;
    document.querySelector('[name="cod"]').value = maq.cod;
    document.querySelector('[name="patrimonio"]').value = maq.patrimonio || '';
    document.querySelector('[name="nome"]').value = maq.nome;
    document.querySelector('[name="setor_local"]').value = maq.setor_local || '';
    document.querySelector('[name="descricao_completa"]').value = maq.descricao_completa || '';
    document.querySelector('[name="ativo"]').value = maq.ativo;
}

// 3. Setor
function abrirModalSetor() {
    document.getElementById('modal-titulo').innerText = 'Cadastrar Novo Setor Fabril';
    document.getElementById('form-acao').value = 'salvar_setor';
    document.getElementById('form-id').value = '';
    document.getElementById('form-campos-dinamicos').innerHTML = `
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
                <label class="block text-xs font-semibold text-[#5a6480] mb-1">Código *</label>
                <input type="text" name="cod" required placeholder="BOBIN" class="form-input text-xs font-mono uppercase">
            </div>
            <div class="sm:col-span-2">
                <label class="block text-xs font-semibold text-[#5a6480] mb-1">Descrição do Setor *</label>
                <input type="text" name="descricao" required placeholder="Ex.: Bobinagem MT/BT" class="form-input text-xs">
            </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-xs font-semibold text-[#5a6480] mb-1">Meta de Eficiência (%)</label>
                <input type="number" step="0.1" name="meta" value="80.0" class="form-input text-xs font-mono">
            </div>
            <div>
                <label class="block text-xs font-semibold text-[#5a6480] mb-1">Status</label>
                <select name="ativo" class="form-input text-xs">
                    <option value="1">Ativo</option>
                    <option value="0">Inativo</option>
                </select>
            </div>
        </div>
    `;
    abrirModalConfig();
}

function editarSetor(st) {
    abrirModalSetor();
    document.getElementById('modal-titulo').innerText = 'Editar Setor (' + st.cod + ')';
    document.getElementById('form-id').value = st.id;
    document.querySelector('[name="cod"]').value = st.cod;
    document.querySelector('[name="descricao"]').value = st.descricao;
    document.querySelector('[name="meta"]').value = st.meta;
    document.querySelector('[name="ativo"]').value = st.ativo;
}

// 4. Empresa
function abrirModalEmpresa() {
    document.getElementById('modal-titulo').innerText = 'Cadastrar Nova Empresa / Unidade';
    document.getElementById('form-acao').value = 'salvar_empresa';
    document.getElementById('form-id').value = '';
    document.getElementById('form-campos-dinamicos').innerHTML = `
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
                <label class="block text-xs font-semibold text-[#5a6480] mb-1">Código *</label>
                <input type="text" name="cod" required placeholder="TRAEL-MT" class="form-input text-xs font-mono uppercase">
            </div>
            <div class="sm:col-span-2">
                <label class="block text-xs font-semibold text-[#5a6480] mb-1">Razão Social / Nome *</label>
                <input type="text" name="nome" required placeholder="Ex.: Trael Transformadores — Matriz" class="form-input text-xs">
            </div>
        </div>
        <div>
            <label class="block text-xs font-semibold text-[#5a6480] mb-1">Status</label>
            <select name="ativo" class="form-input text-xs">
                <option value="1">Ativo</option>
                <option value="0">Inativo</option>
            </select>
        </div>
    `;
    abrirModalConfig();
}

function editarEmpresa(emp) {
    abrirModalEmpresa();
    document.getElementById('modal-titulo').innerText = 'Editar Empresa (' + emp.cod + ')';
    document.getElementById('form-id').value = emp.id;
    document.querySelector('[name="cod"]').value = emp.cod;
    document.querySelector('[name="nome"]').value = emp.nome;
    document.querySelector('[name="ativo"]').value = emp.ativo;
}

// 5. Motivo de Parada
function abrirModalParada() {
    document.getElementById('modal-titulo').innerText = 'Cadastrar Motivo de Parada';
    document.getElementById('form-acao').value = 'salvar_motivo_parada';
    document.getElementById('form-id').value = '';
    document.getElementById('form-campos-dinamicos').innerHTML = `
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
                <label class="block text-xs font-semibold text-[#5a6480] mb-1">Código *</label>
                <input type="text" name="cod" required placeholder="FALHA-MEC" class="form-input text-xs font-mono uppercase">
            </div>
            <div class="sm:col-span-2">
                <label class="block text-xs font-semibold text-[#5a6480] mb-1">Classificação *</label>
                <select name="tipo" required class="form-input text-xs">
                    <option value="NAO_PROG">Não Programada (Quebras, falta de insumo)</option>
                    <option value="PROG">Programada (Manutenção, DDS, Setup)</option>
                </select>
            </div>
        </div>
        <div>
            <label class="block text-xs font-semibold text-[#5a6480] mb-1">Descrição do Motivo *</label>
            <input type="text" name="descricao" required placeholder="Ex.: Falha Mecânica no Equipamento" class="form-input text-xs">
        </div>
        <div>
            <label class="block text-xs font-semibold text-[#5a6480] mb-1">Status</label>
            <select name="ativo" class="form-input text-xs">
                <option value="1">Ativo</option>
                <option value="0">Inativo</option>
            </select>
        </div>
    `;
    abrirModalConfig();
}

function editarParada(mp) {
    abrirModalParada();
    document.getElementById('modal-titulo').innerText = 'Editar Motivo de Parada (' + mp.cod + ')';
    document.getElementById('form-id').value = mp.id;
    document.querySelector('[name="cod"]').value = mp.cod;
    document.querySelector('[name="tipo"]').value = mp.tipo;
    document.querySelector('[name="descricao"]').value = mp.descricao;
    document.querySelector('[name="ativo"]').value = mp.ativo;
}

// Submissão do Formulário
async function enviarFormularioConfig(event) {
    event.preventDefault();
    const btn = document.getElementById('modal-btn-submit');
    const form = document.getElementById('modal-form');
    const formData = new FormData(form);

    btn.disabled = true;
    btn.innerText = 'Salvando...';

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
            Toast.success(res.mensagem || 'Operação realizada com sucesso!');
            fecharModalConfig();
            setTimeout(() => window.location.reload(), 600);
        } else {
            Toast.error(res.erro || 'Falha ao salvar o registro.');
        }
    } catch (e) {
        Toast.error('Erro de comunicação com o servidor.');
    } finally {
        btn.disabled = false;
        btn.innerText = 'Salvar Registro';
    }
}

// Exclusão de Item
async function excluirItem(acao, id, nome) {
    if (!confirm(`Deseja realmente excluir o registro "${nome}"?`)) {
        return;
    }

    const formData = new FormData();
    formData.append('acao', acao);
    formData.append('id', id);
    formData.append('csrf_token', CSRF_TOKEN);

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
            Toast.success(res.mensagem || 'Registro excluído!');
            setTimeout(() => window.location.reload(), 600);
        } else {
            Toast.error(res.erro || 'Não foi possível excluir.');
        }
    } catch (e) {
        Toast.error('Erro de conexão ao tentar excluir.');
    }
}
</script>

<?php
layoutFooter();
