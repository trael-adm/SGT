<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireAcessoModulo('qualidade');

$pdo = getDB();

$sql = "SELECT * FROM reprovas WHERE ativo = 1 ORDER BY familia ASC, ordem ASC, LENGTH(codigo) ASC, codigo ASC";
$stmt = $pdo->query($sql);
$reprovas = $stmt->fetchAll();

$pageTitle = 'Tipos de Reprova';
require_once __DIR__ . '/../../includes/layout.php';
layoutHeader($pageTitle);
?>

<style>
    /* ─── Botão Nova Reprova ─── */
    .btn-nova-reprova {
        background: #e8a020;
        border: 1px solid #e8a020;
        color: #ffffff;
        font-weight: 700;
        font-size: 13px;
        border-radius: 6px;
        padding: 8px 18px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        cursor: pointer;
        transition: all 0.15s ease;
        text-decoration: none;
    }
    .btn-nova-reprova:hover {
        background: #d48b14;
        border-color: #d48b14;
        color: #ffffff;
    }

    /* ─── Pílulas / Botões de Filtro de Setor Superior (Mais Quadradinhos) ─── */
    .filter-pill {
        padding: 5px 14px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        color: #64748b;
        transition: all 0.15s ease;
        user-select: none;
        line-height: 1.3;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .filter-pill:hover {
        transform: translateY(-1px);
    }
    .filter-pill.active[data-setor=""] {
        background: #0f172a;
        border-color: #0f172a;
        color: #ffffff;
    }
    .filter-pill.pill-lab {
        color: #7c3aed;
        border-color: #ddd6fe;
    }
    .filter-pill.pill-lab:hover, .filter-pill.pill-lab.active {
        background: #f5f3ff;
        border-color: #7c3aed;
        color: #7c3aed;
    }
    .filter-pill.pill-ret {
        color: #ea580c;
        border-color: #fed7aa;
    }
    .filter-pill.pill-ret:hover, .filter-pill.pill-ret.active {
        background: #fff7ed;
        border-color: #ea580c;
        color: #ea580c;
    }
    .filter-pill.pill-iqf {
        color: #2563eb;
        border-color: #bfdbfe;
    }
    .filter-pill.pill-iqf:hover, .filter-pill.pill-iqf.active {
        background: #eff6ff;
        border-color: #2563eb;
        color: #2563eb;
    }

    /* ─── Botões na Linha da Tabela (Pílulas Quadradinhas com Borda) ─── */
    .row-pill {
        padding: 4px 12px;
        border-radius: 6px;
        font-size: 11px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.15s ease;
        user-select: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 44px;
        line-height: 1.3;
        border: 1px solid transparent;
        background: #ffffff;
    }
    .row-pill:hover {
        transform: scale(1.05);
    }
    .row-pill.active-lab {
        background: #f5f3ff;
        color: #7c3aed;
        border: 1px solid #7c3aed;
    }
    .row-pill.active-ret {
        background: #fff7ed;
        color: #ea580c;
        border: 1px solid #ea580c;
    }
    .row-pill.active-iqf {
        background: #eff6ff;
        color: #2563eb;
        border: 1px solid #2563eb;
    }
    .row-pill.pill-vai-sim {
        background: #ecfdf5;
        color: #059669;
        border: 1px solid #10b981;
    }
    .row-pill.pill-vai-sim:hover {
        background: #d1fae5;
    }
    .row-pill.pill-vai-nao {
        background: #fff7ed;
        color: #ea580c;
        border: 1px solid #ea580c;
    }
    .row-pill.pill-vai-nao:hover {
        background: #ffedd5;
    }
    .row-pill.inactive {
        background: #ffffff;
        color: #cbd5e1;
        border: 1px solid #e2e8f0;
        opacity: 0.45;
    }
    .row-pill.inactive:hover {
        opacity: 1;
        border-color: #cbd5e1;
        color: #64748b;
    }
    .row-pill.loading {
        opacity: 0.5;
        pointer-events: none;
    }

    /* ─── Botões de Seleção de Setor no Modal (Sem Checkbox/Radio Nativos) ─── */
    .modal-sector-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 7px 18px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        user-select: none;
        transition: all 0.15s ease;
        border: 1px solid #e2e8f0;
        background: #ffffff;
        color: #94a3b8;
        opacity: 0.7;
    }
    .modal-sector-btn input[type="checkbox"],
    .modal-sector-btn input[type="radio"] {
        display: none !important;
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }
    .modal-sector-btn:hover {
        transform: translateY(-1px);
        opacity: 1;
    }
    .modal-sector-btn.btn-modal-lab.active,
    .modal-sector-btn.btn-modal-lab:has(input:checked) {
        border-color: #7c3aed;
        background: #f5f3ff;
        color: #7c3aed;
        opacity: 1;
    }
    .modal-sector-btn.btn-modal-ret.active,
    .modal-sector-btn.btn-modal-ret:has(input:checked) {
        border-color: #ea580c;
        background: #fff7ed;
        color: #ea580c;
        opacity: 1;
    }
    .modal-sector-btn.btn-modal-iqf.active,
    .modal-sector-btn.btn-modal-iqf:has(input:checked) {
        border-color: #2563eb;
        background: #eff6ff;
        color: #2563eb;
        opacity: 1;
    }
    .modal-sector-btn.btn-modal-vai-sim.active,
    .modal-sector-btn.btn-modal-vai-sim:has(input:checked) {
        border-color: #10b981;
        background: #ecfdf5;
        color: #059669;
        opacity: 1;
    }
    .modal-sector-btn.btn-modal-vai-nao.active,
    .modal-sector-btn.btn-modal-vai-nao:has(input:checked) {
        border-color: #ea580c;
        background: #fff7ed;
        color: #ea580c;
        opacity: 1;
    }
</style>

<!-- Bloco Superior Totalmente Travado (Header + Filtros) -->
<div style="position:sticky;top:-8px;z-index:20;background:var(--color-bg,#f8fafc);padding-bottom:14px;margin-top:-4px;">
    <!-- Cabeçalho -->
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px;">
        <div>
            <h1 style="font-size:20px;font-weight:700;margin-top:2px;color:#0f172a;">Tipos de Reprova</h1>
            <p style="font-size:13px;color:#64748b;margin-top:2px;">
                Gerencie os tipos de reprovas, setores aplicáveis e defina se a não conformidade deve ir para o Retrabalho ou para Retornos internos.
            </p>
        </div>
        <button type="button" onclick="abrirModalNovaReprova()" class="btn-nova-reprova">
            + Nova Reprova
        </button>
    </div>

    <!-- Filtros Superiores -->
    <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 18px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;box-shadow:0 1px 2px rgba(0,0,0,0.02);">
        <div style="flex:1;min-width:280px;">
            <input type="search" id="busca" oninput="filtrarTabela()" placeholder="Buscar código, família ou descrição…" style="width:100%;height:38px;padding:0 14px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;color:#334155;background:#ffffff;outline:none;transition:border-color .15s ease;">
        </div>
        <div style="display:flex;align-items:center;gap:8px;">
            <span style="font-size:12px;font-weight:600;color:#64748b;white-space:nowrap;">Filtrar Setor:</span>
            <button type="button" class="filter-pill active" data-setor="" onclick="filtrarPorSetorBtn('', this)">Todos</button>
            <button type="button" class="filter-pill pill-lab" data-setor="LAB" onclick="filtrarPorSetorBtn('LAB', this)">LAB</button>
            <button type="button" class="filter-pill pill-ret" data-setor="RET" onclick="filtrarPorSetorBtn('RET', this)">RET</button>
            <button type="button" class="filter-pill pill-iqf" data-setor="IQF" onclick="filtrarPorSetorBtn('IQF', this)">IQF</button>
        </div>
    </div>
</div>

<!-- Tabela com Cabeçalho Sticky e Scroll Interno -->
<div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.03);display:flex;flex-direction:column;height:calc(100vh - 250px);">
    <div style="overflow-y:auto;overflow-x:auto;flex:1;min-height:0;">
        <table style="width:100%;border-collapse:collapse;text-align:left;" id="tabelaReprovas">
            <thead style="position:sticky;top:0;z-index:10;background:#f8fafc;border-bottom:1px solid #e2e8f0;box-shadow:0 1px 2px rgba(0,0,0,0.03);">
                <tr>
                    <th style="padding:12px 18px;font-size:11px;font-weight:700;color:#64748b;letter-spacing:0.5px;text-transform:uppercase;cursor:pointer;" onclick="ordenarTabela(0, this)">CÓDIGO <span class="sort-icon"></span></th>
                    <th style="padding:12px 18px;font-size:11px;font-weight:700;color:#64748b;letter-spacing:0.5px;text-transform:uppercase;cursor:pointer;" onclick="ordenarTabela(1, this)">FAMÍLIA <span class="sort-icon"></span></th>
                    <th style="padding:12px 18px;font-size:11px;font-weight:700;color:#64748b;letter-spacing:0.5px;text-transform:uppercase;cursor:pointer;" onclick="ordenarTabela(2, this)">DESCRIÇÃO <span class="sort-icon"></span></th>
                    <th style="padding:12px 18px;font-size:11px;font-weight:700;color:#64748b;letter-spacing:0.5px;text-transform:uppercase;">SETORES ONDE É SELECIONÁVEL</th>
                    <th style="padding:12px 18px;font-size:11px;font-weight:700;color:#64748b;letter-spacing:0.5px;text-transform:uppercase;">DEVE IR PARA O RETRABALHO?</th>
                    <th style="padding:12px 18px;font-size:11px;font-weight:700;color:#64748b;letter-spacing:0.5px;text-transform:uppercase;text-align:right;">AÇÕES</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reprovas)): ?>
                    <tr id="linhaVazia">
                        <td colspan="6" style="text-align:center;padding:36px 16px;color:#94a3b8;">
                            Nenhuma reprova cadastrada.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($reprovas as $r):
                        $locStr = strtoupper(trim((string)$r['local']));
                        $locArray = [];
                        if ($locStr === 'GER' || $locStr === '') {
                            $locArray = ['LAB', 'RET', 'IQF'];
                        } else {
                            $locArray = array_values(array_filter(array_map('trim', explode(',', $locStr))));
                        }
                        $hasLab = in_array('LAB', $locArray, true);
                        $hasRet = in_array('RET', $locArray, true);
                        $hasIqf = in_array('IQF', $locArray, true);
                        $vaiRet = (int)($r['vai_retrabalho'] ?? 1) === 1;
                    ?>
                        <tr class="linha-reprova" style="border-bottom:1px solid #f1f5f9;" data-id="<?= (int)$r['id'] ?>" data-local="<?= htmlspecialchars(implode(',', $locArray)) ?>" data-raw-local="<?= htmlspecialchars($r['local']) ?>">
                            <td style="padding:14px 18px;font-weight:700;color:#0f172a;font-size:13px;font-family:monospace;">
                                <?= htmlspecialchars($r['codigo']) ?>
                            </td>
                            <td style="padding:14px 18px;font-weight:600;color:#334155;font-size:12px;text-transform:uppercase;">
                                <?= htmlspecialchars($r['familia']) ?>
                            </td>
                            <td style="padding:14px 18px;font-weight:500;color:#475569;font-size:12px;text-transform:uppercase;">
                                <?= htmlspecialchars($r['descricao']) ?>
                            </td>
                            <td style="padding:14px 18px;">
                                <div style="display:inline-flex;gap:6px;align-items:center;flex-wrap:nowrap;">
                                    <button type="button" 
                                            class="row-pill <?= $hasLab ? 'active-lab' : 'inactive' ?>" 
                                            onclick="toggleSetorLinha(<?= (int)$r['id'] ?>, 'LAB', this)"
                                            title="Clique para alternar LAB">
                                        LAB
                                    </button>
                                    <button type="button" 
                                            class="row-pill <?= $hasRet ? 'active-ret' : 'inactive' ?>" 
                                            onclick="toggleSetorLinha(<?= (int)$r['id'] ?>, 'RET', this)"
                                            title="Clique para alternar RET">
                                        RET
                                    </button>
                                    <button type="button" 
                                            class="row-pill <?= $hasIqf ? 'active-iqf' : 'inactive' ?>" 
                                            onclick="toggleSetorLinha(<?= (int)$r['id'] ?>, 'IQF', this)"
                                            title="Clique para alternar IQF">
                                        IQF
                                    </button>
                                </div>
                            </td>
                            <td style="padding:14px 18px;">
                                <div style="display:inline-flex;gap:6px;align-items:center;flex-wrap:nowrap;">
                                    <button type="button" 
                                            class="row-pill <?= $vaiRet ? 'pill-vai-sim' : 'inactive' ?>" 
                                            onclick="setVaiRetrabalhoLinha(<?= (int)$r['id'] ?>, 1, this)"
                                            title="Definir para ir ao Retrabalho (Sim)">
                                        Sim
                                    </button>
                                    <button type="button" 
                                            class="row-pill <?= !$vaiRet ? 'pill-vai-nao' : 'inactive' ?>" 
                                            onclick="setVaiRetrabalhoLinha(<?= (int)$r['id'] ?>, 0, this)"
                                            title="Definir para correção interna no setor (Não)">
                                        Não
                                    </button>
                                </div>
                            </td>
                            <td style="padding:14px 18px;text-align:right;">
                                <div style="display:inline-flex;gap:6px;align-items:center;justify-content:flex-end;">
                                    <button type="button" onclick="abrirModalEdicao(<?= htmlspecialchars(json_encode([
                                        'id' => $r['id'],
                                        'codigo' => $r['codigo'],
                                        'familia' => $r['familia'],
                                        'descricao' => $r['descricao'],
                                        'local' => $r['local'],
                                        'vai_retrabalho' => $r['vai_retrabalho'] ?? 1
                                    ])) ?>)" 
                                            class="btn-icon btn-icon-edit"
                                            title="Editar dados da reprova">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                    </button>
                                    
                                    <button type="button" onclick="confirmarExclusaoReprova(<?= $r['id'] ?>, '<?= htmlspecialchars($r['codigo'], ENT_QUOTES) ?>')" 
                                            class="btn-icon btn-icon-danger"
                                            title="Excluir tipo de reprova">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Nova/Editar Reprova -->
<div id="modalReprova" class="modal-overlay" style="display:none;">
    <div class="modal">
        <div class="modal-header">
            <div>
                <span class="modal-title" id="modalTitulo">Nova Reprova</span>
                <div class="card-subtitle">
                    Defina o código, família, descrição, setores e se deve ir para o Retrabalho
                </div>
            </div>
            <button type="button" class="modal-close" onclick="fecharModalReprova()">&times;</button>
        </div>
        
        <form id="formReprova" onsubmit="event.preventDefault(); salvarReprova();">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:14px;">
                <input type="hidden" name="id" id="inputId">
                
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Código (Ex: M1, E2, R1) <span class="required">*</span></label>
                    <input type="text" name="codigo" id="inputCodigo" required class="form-control font-mono font-600" style="text-transform:uppercase;">
                </div>
                
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Família / Grupo <span class="required">*</span></label>
                    <input type="text" name="familia" id="inputFamilia" required class="form-control" style="text-transform:uppercase;" placeholder="Ex: VAZAMENTO, COMUTADOR, PINTURA…">
                </div>
                
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Descrição da Não Conformidade <span class="required">*</span></label>
                    <input type="text" name="descricao" id="inputDescricao" required class="form-control" style="text-transform:uppercase;" placeholder="Ex: VAZAMENTO NA AT, FALTA SERIGRAFIA…">
                </div>
                
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Setores onde é selecionável <span class="required">*</span></label>
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:6px;">
                        <label class="modal-sector-btn btn-modal-lab">
                            <input type="checkbox" name="locais[]" value="LAB" id="chkSectorLab" onchange="atualizarModalSectorBtns()">
                            <span>LAB</span>
                        </label>
                        <label class="modal-sector-btn btn-modal-ret">
                            <input type="checkbox" name="locais[]" value="RET" id="chkSectorRet" onchange="atualizarModalSectorBtns()">
                            <span>RET</span>
                        </label>
                        <label class="modal-sector-btn btn-modal-iqf">
                            <input type="checkbox" name="locais[]" value="IQF" id="chkSectorIqf" onchange="atualizarModalSectorBtns()">
                            <span>IQF</span>
                        </label>
                    </div>
                    <span class="form-hint">Clique para ativar ou desativar os setores onde esta reprova poderá ser apontada.</span>
                </div>

                <div class="form-group" style="margin:0;">
                    <label class="form-label">Deve ir para o retrabalho? <span class="required">*</span></label>
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:6px;">
                        <label class="modal-sector-btn btn-modal-vai-sim">
                            <input type="radio" name="vai_retrabalho" value="1" id="radVaiSim" checked onchange="atualizarModalVaiBtns()">
                            <span>Sim (Retrabalho)</span>
                        </label>
                        <label class="modal-sector-btn btn-modal-vai-nao">
                            <input type="radio" name="vai_retrabalho" value="0" id="radVaiNao" onchange="atualizarModalVaiBtns()">
                            <span>Não (Retornos do Setor)</span>
                        </label>
                    </div>
                    <span class="form-hint">Se 'Sim', a peça segue o fluxo normal para o Retrabalho. Se 'Não', a peça volta para a tela de Retornos do próprio setor para correção interna.</span>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" onclick="fecharModalReprova()" class="btn btn-secondary">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="btnSalvarReprova">
                    Salvar Reprova
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Confirmar Exclusão de Reprova -->
<div id="modalExcluirReprova" class="modal-overlay" style="display:none;">
    <div class="modal" style="max-width:420px;">
        <div class="modal-header">
            <span class="modal-title">Confirmar Exclusão</span>
            <button type="button" class="modal-close" onclick="fecharModalExcluirReprova()">&times;</button>
        </div>
        <div class="modal-body">
            <p style="font-size:var(--font-size-base);color:var(--color-text-secondary);margin:0 0 16px;">
                Deseja realmente apagar o código de reprova <strong id="modalExcluirCodigo" style="color:var(--color-text-primary);"></strong>?
            </p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="fecharModalExcluirReprova()">Cancelar</button>
            <button type="button" class="btn btn-danger" id="btnConfirmarExclusaoReprova" onclick="executarExclusaoReprova()">Excluir Reprova</button>
        </div>
    </div>
</div>

<script>
    const modal = document.getElementById('modalReprova');
    const form = document.getElementById('formReprova');
    let idReprovaExcluir = null;
    let setorAtivoFiltro = '';
    const API_URL = (typeof window.__APP_BASE === 'string' ? window.__APP_BASE : '<?= defined('APP_URL') ? APP_URL : '' ?>') + '/api/qualidade-acao.php';

    function atualizarModalSectorBtns() {
        const chkLab = document.getElementById('chkSectorLab');
        const chkRet = document.getElementById('chkSectorRet');
        const chkIqf = document.getElementById('chkSectorIqf');

        const labelLab = chkLab.closest('.modal-sector-btn');
        const labelRet = chkRet.closest('.modal-sector-btn');
        const labelIqf = chkIqf.closest('.modal-sector-btn');

        if (labelLab) labelLab.classList.toggle('active', chkLab.checked);
        if (labelRet) labelRet.classList.toggle('active', chkRet.checked);
        if (labelIqf) labelIqf.classList.toggle('active', chkIqf.checked);
    }

    function atualizarModalVaiBtns() {
        const radSim = document.getElementById('radVaiSim');
        const radNao = document.getElementById('radVaiNao');

        const labelSim = radSim.closest('.modal-sector-btn');
        const labelNao = radNao.closest('.modal-sector-btn');

        if (labelSim) labelSim.classList.toggle('active', radSim.checked);
        if (labelNao) labelNao.classList.toggle('active', radNao.checked);
    }

    function abrirModalNovaReprova() {
        form.reset();
        document.getElementById('inputId').value = '';
        document.getElementById('chkSectorLab').checked = true;
        document.getElementById('chkSectorRet').checked = true;
        document.getElementById('chkSectorIqf').checked = true;
        document.getElementById('radVaiSim').checked = true;
        atualizarModalSectorBtns();
        atualizarModalVaiBtns();
        document.getElementById('modalTitulo').textContent = 'Nova Reprova';
        modal.style.display = 'flex';
        setTimeout(() => document.getElementById('inputCodigo').focus(), 100);
    }

    function abrirModalEdicao(dados) {
        document.getElementById('inputId').value = dados.id;
        document.getElementById('inputCodigo').value = dados.codigo;
        document.getElementById('inputFamilia').value = dados.familia;
        document.getElementById('inputDescricao').value = dados.descricao;
        
        const loc = (dados.local || 'GER').toUpperCase();
        if (loc === 'GER' || loc === '') {
            document.getElementById('chkSectorLab').checked = true;
            document.getElementById('chkSectorRet').checked = true;
            document.getElementById('chkSectorIqf').checked = true;
        } else {
            const arr = loc.split(',').map(s => s.trim());
            document.getElementById('chkSectorLab').checked = arr.includes('LAB');
            document.getElementById('chkSectorRet').checked = arr.includes('RET');
            document.getElementById('chkSectorIqf').checked = arr.includes('IQF');
        }
        atualizarModalSectorBtns();

        const vai = (dados.vai_retrabalho !== undefined && dados.vai_retrabalho !== null) ? Number(dados.vai_retrabalho) : 1;
        document.getElementById('radVaiSim').checked = (vai === 1);
        document.getElementById('radVaiNao').checked = (vai === 0);
        atualizarModalVaiBtns();
        
        document.getElementById('modalTitulo').textContent = 'Editar Reprova: ' + dados.codigo;
        modal.style.display = 'flex';
    }

    function fecharModalReprova() {
        modal.style.display = 'none';
    }

    function salvarReprova() {
        if (!form.reportValidity()) return;

        const chkLab = document.getElementById('chkSectorLab').checked;
        const chkRet = document.getElementById('chkSectorRet').checked;
        const chkIqf = document.getElementById('chkSectorIqf').checked;

        if (!chkLab && !chkRet && !chkIqf) {
            alert('Selecione pelo menos um setor onde esta reprova poderá ser apontada.');
            return;
        }

        const btn = document.getElementById('btnSalvarReprova');
        btn.disabled = true;
        btn.innerHTML = 'Salvando...';

        const formData = new FormData(form);
        formData.append('acao', 'salvar_reprova');

        fetch(API_URL, {
            method: 'POST',
            body: formData
        })
        .then(async res => {
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.sucesso) {
                location.reload(); 
            } else {
                alert(data.erro || 'Erro ao processar requisição (HTTP ' + res.status + ').');
                btn.disabled = false;
                btn.innerHTML = 'Salvar Reprova';
            }
        })
        .catch(err => {
            alert('Erro de conexão: ' + (err.message || 'Verifique sua conexão.'));
            btn.disabled = false;
            btn.innerHTML = 'Salvar Reprova';
        });
    }

    // Definir se deve ir ao retrabalho diretamente na linha da tabela (Sim / Não)
    function setVaiRetrabalhoLinha(id, valor, btn) {
        const wrap = btn.parentElement;
        const btnSim = wrap.querySelector('.row-pill:nth-child(1)');
        const btnNao = wrap.querySelector('.row-pill:nth-child(2)');

        if (btnSim.classList.contains('loading') || btnNao.classList.contains('loading')) return;
        btnSim.classList.add('loading');
        btnNao.classList.add('loading');

        const formData = new FormData();
        formData.append('acao', 'set_vai_retrabalho');
        formData.append('id', id);
        formData.append('valor', valor);

        fetch(API_URL, {
            method: 'POST',
            body: formData
        })
        .then(async res => {
            const data = await res.json().catch(() => ({}));
            btnSim.classList.remove('loading');
            btnNao.classList.remove('loading');
            if (res.ok && data.sucesso) {
                const isSim = (Number(data.vai_retrabalho) === 1);
                btnSim.className = 'row-pill ' + (isSim ? 'pill-vai-sim' : 'inactive');
                btnNao.className = 'row-pill ' + (!isSim ? 'pill-vai-nao' : 'inactive');
            } else {
                alert(data.erro || 'Erro ao alterar status.');
            }
        })
        .catch(err => {
            btnSim.classList.remove('loading');
            btnNao.classList.remove('loading');
            alert('Erro de conexão ao alterar status.');
        });
    }

    // Toggle de compatibilidade
    function toggleVaiRetrabalhoLinha(id, btn) {
        const wrap = btn.parentElement;
        const btnSim = wrap.querySelector('.row-pill:nth-child(1)');
        const isSimAtivo = btnSim && btnSim.classList.contains('pill-vai-sim');
        setVaiRetrabalhoLinha(id, isSimAtivo ? 0 : 1, btn);
    }

    // Alternar setor diretamente na linha da tabela
    function toggleSetorLinha(id, setor, btn) {
        if (btn.classList.contains('loading')) return;
        btn.classList.add('loading');

        const formData = new FormData();
        formData.append('acao', 'toggle_setor');
        formData.append('id', id);
        formData.append('setor', setor);

        fetch(API_URL, {
            method: 'POST',
            body: formData
        })
        .then(async res => {
            const data = await res.json().catch(() => ({}));
            btn.classList.remove('loading');
            if (res.ok && data.sucesso) {
                const row = btn.closest('tr.linha-reprova');
                const activeSetores = data.locais || [];
                
                const btnLab = row.querySelector('td:nth-child(4) .row-pill:nth-child(1)');
                const btnRet = row.querySelector('td:nth-child(4) .row-pill:nth-child(2)');
                const btnIqf = row.querySelector('td:nth-child(4) .row-pill:nth-child(3)');

                if (btnLab) {
                    btnLab.className = 'row-pill ' + (activeSetores.includes('LAB') ? 'active-lab' : 'inactive');
                }
                if (btnRet) {
                    btnRet.className = 'row-pill ' + (activeSetores.includes('RET') ? 'active-ret' : 'inactive');
                }
                if (btnIqf) {
                    btnIqf.className = 'row-pill ' + (activeSetores.includes('IQF') ? 'active-iqf' : 'inactive');
                }

                row.setAttribute('data-local', activeSetores.join(','));
                row.setAttribute('data-raw-local', data.novo_local || '');

                if (setorAtivoFiltro) {
                    filtrarTabela();
                }
            } else {
                alert(data.erro || 'Erro ao alternar setor da reprova.');
            }
        })
        .catch(err => {
            btn.classList.remove('loading');
            alert('Erro de conexão ao alternar setor.');
        });
    }

    function confirmarExclusaoReprova(id, codigo) {
        idReprovaExcluir = id;
        document.getElementById('modalExcluirCodigo').textContent = codigo;
        document.getElementById('modalExcluirReprova').style.display = 'flex';
    }

    function fecharModalExcluirReprova() {
        idReprovaExcluir = null;
        document.getElementById('modalExcluirReprova').style.display = 'none';
    }

    function executarExclusaoReprova() {
        if (!idReprovaExcluir) return;
        const btn = document.getElementById('btnConfirmarExclusaoReprova');
        btn.disabled = true;
        btn.textContent = 'Excluindo...';

        const formData = new FormData();
        formData.append('acao', 'excluir_reprova');
        formData.append('id', idReprovaExcluir);

        fetch(API_URL, {
            method: 'POST',
            body: formData
        })
        .then(async res => {
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.sucesso) {
                location.reload(); 
            } else {
                alert(data.erro || 'Erro ao excluir tipo de reprova.');
                btn.disabled = false;
                btn.textContent = 'Excluir Reprova';
            }
        })
        .catch(err => {
            alert('Erro ao excluir reprova: ' + (err.message || 'Verifique sua conexão.'));
            btn.disabled = false;
            btn.textContent = 'Excluir Reprova';
        });
    }

    // Filtragem rápida por Botões de Setor (Pílulas)
    function filtrarPorSetorBtn(setor, btnEl) {
        setorAtivoFiltro = setor;
        document.querySelectorAll('.filter-pill').forEach(b => b.classList.remove('active'));
        if (btnEl) btnEl.classList.add('active');
        filtrarTabela();
    }

    function filtrarTabela() {
        const termo = document.getElementById('busca').value.toLowerCase().trim();
        const linhas = document.querySelectorAll('#tabelaReprovas tbody tr.linha-reprova');
        let visiveis = 0;

        linhas.forEach(linha => {
            const codigo = linha.querySelector('td:nth-child(1)').textContent.toLowerCase();
            const familia = linha.querySelector('td:nth-child(2)').textContent.toLowerCase();
            const descricao = linha.querySelector('td:nth-child(3)').textContent.toLowerCase();
            const setoresArray = (linha.getAttribute('data-local') || '').split(',').map(s => s.trim());

            const matchTexto = !termo || codigo.includes(termo) || familia.includes(termo) || descricao.includes(termo);
            const matchSetor = !setorAtivoFiltro || setoresArray.includes(setorAtivoFiltro);

            if (matchTexto && matchSetor) {
                linha.style.display = '';
                visiveis++;
            } else {
                linha.style.display = 'none';
            }
        });

        let linhaVazia = document.getElementById('linhaVazia');
        if (visiveis === 0) {
            if (!linhaVazia) {
                linhaVazia = document.createElement('tr');
                linhaVazia.id = 'linhaVazia';
                linhaVazia.innerHTML = '<td colspan="6" style="text-align:center;padding:36px 16px;color:#94a3b8;">Nenhum tipo de reprova encontrado.</td>';
                document.querySelector('#tabelaReprovas tbody').appendChild(linhaVazia);
            }
            linhaVazia.style.display = '';
        } else if (linhaVazia) {
            linhaVazia.style.display = 'none';
        }
    }

    // Ordenação da Tabela
    let direcaoOrdenacao = {};
    function ordenarTabela(colunaIdx, thElement) {
        const tabela = document.getElementById('tabelaReprovas');
        const tbody = tabela.querySelector('tbody');
        const linhas = Array.from(tbody.querySelectorAll('tr.linha-reprova'));
        
        const asc = direcaoOrdenacao[colunaIdx] !== true;
        direcaoOrdenacao = {};
        direcaoOrdenacao[colunaIdx] = asc;

        tabela.querySelectorAll('th').forEach(th => th.classList.remove('sort-asc', 'sort-desc'));
        thElement.classList.add(asc ? 'sort-asc' : 'sort-desc');

        linhas.sort((a, b) => {
            const valA = a.children[colunaIdx].innerText.trim();
            const valB = b.children[colunaIdx].innerText.trim();
            return asc 
                ? valA.localeCompare(valB, 'pt-BR', { numeric: true, sensitivity: 'base' }) 
                : valB.localeCompare(valA, 'pt-BR', { numeric: true, sensitivity: 'base' });
        });

        linhas.forEach(linha => tbody.appendChild(linha));
    }
</script>

<?php layoutFooter(); ?>
