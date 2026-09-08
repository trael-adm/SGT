<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/layout.php';

requireAcessoModulo('admin');

$canEdit = podeEditar('adm.usu') || isAdmin();

$pdo = getDB();
$base = defined('APP_URL') ? APP_URL : '';

$stmt = $pdo->query("
    SELECT r.*, GROUP_CONCAT(a.apelido ORDER BY a.apelido SEPARATOR '||') AS apelidos_concat
    FROM concessionaria_regras r
    LEFT JOIN concessionaria_regras_apelidos a ON a.id_regra = r.id
    WHERE r.deleted_at IS NULL
    GROUP BY r.id
    ORDER BY (r.nome_grupo = 'PARTICULAR') DESC, r.nome_grupo ASC
");
$regras = $stmt->fetchAll();
foreach ($regras as &$r) {
    $r['apelidos'] = $r['apelidos_concat'] ? explode('||', $r['apelidos_concat']) : [];
    $r['locais_obrigatorios_arr'] = $r['locais_obrigatorios'] ? explode(',', $r['locais_obrigatorios']) : [];
    $r['local_codigo_adicional_arr'] = $r['local_codigo_adicional'] ? explode(',', $r['local_codigo_adicional']) : [];
    $r['local_potencia_arr'] = $r['local_potencia'] ? explode(',', $r['local_potencia']) : ['tanque'];
}
unset($r);

layoutHeader('Paint-Check Robô — Regras de Validação');
?>
<style>
    /* ─── Layout Unificado SGT (mesmo padrão de pages/admin/usuarios.php) ────── */
    .admin-page-container {
        display: flex;
        flex-direction: column;
        height: calc(100vh - var(--header-height) - 40px);
        height: calc(100dvh - var(--header-height) - 40px);
        min-height: 0;
        overflow: hidden;
    }
    .admin-sticky-top { flex-shrink: 0; background: var(--color-bg, #f4f5f7); padding-bottom: 12px; }

    .admin-pane-card {
        flex: 1;
        min-height: 0;
        display: flex;
        flex-direction: column;
        background: #ffffff;
        border: 1px solid var(--color-border,#e5e7eb);
        border-radius: var(--radius-lg,10px);
        overflow: hidden;
    }
    .admin-toolbar {
        padding: 12px 16px;
        border-bottom: 1px solid var(--color-border,#f1f5f9);
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 10px;
        flex-shrink: 0;
        background: #ffffff;
    }
    .admin-toolbar-left, .admin-toolbar-right { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; }
    .admin-toolbar input[type=search] {
        padding: 7px 12px;
        border: 1px solid var(--color-border,#d1d5db);
        border-radius: 8px;
        font-size: 13px;
        background: #fff;
        color: var(--color-text-primary,#111827);
    }
    .admin-toolbar input[type=search]:focus {
        outline: none;
        border-color: #E89B1C;
        box-shadow: 0 0 0 3px rgba(232,155,28,0.15);
    }
    .btn-action-primary {
        background: #E89B1C;
        color: #0e2c1d;
        border: none;
        padding: 7px 16px;
        border-radius: 8px;
        font-size: 12.5px;
        font-weight: 700;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.15s ease;
    }
    .btn-action-primary:hover { background: #d98e16; }
    .admin-table-scroll { flex: 1; min-height: 0; overflow-y: auto; overflow-x: auto; }

    .table-admin { width: 100%; border-collapse: collapse; font-size: 13px; }
    .table-admin thead th {
        position: sticky; top: 0; z-index: 10; background: #f8fafc;
        text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .6px;
        color: var(--color-text-muted,#6b7280); padding: 10px 14px;
        border-bottom: 1px solid var(--color-border,#e5e7eb); white-space: nowrap;
    }
    .table-admin tbody td { padding: 10px 14px; border-bottom: 1px solid var(--color-border,#f1f5f9); vertical-align: middle; color: #1f2937; }
    .table-admin tbody tr:hover { background: #fbfcfd; }
    .table-admin tbody tr.is-particular { background: #fafaf5; }

    .badge-status {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 3px 10px; border-radius: 9999px; font-size: 11.5px; font-weight: 700;
    }
    .badge-status.is-ativo { background: #dcfce7; color: #15803d; }
    .badge-status.is-inativo { background: #f1f5f9; color: #64748b; }
    .badge-status .dot { width: 6px; height: 6px; border-radius: 50%; display: inline-block; }
    .badge-status.is-ativo .dot { background: #16a34a; }
    .badge-status.is-inativo .dot { background: #94a3b8; }

    .badge-local {
        display: inline-block; padding: 2px 8px; border-radius: 6px;
        font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px;
        background: #e6f4ea; color: #166534; margin: 1px 2px 1px 0;
    }
    .badge-local.is-off { background: #f1f5f9; color: #94a3b8; }

    .badge-apelido {
        display: inline-block; padding: 2px 8px; border-radius: 9999px;
        font-size: 11px; background: #f1f5f9; color: #334155; margin: 1px 3px 1px 0;
    }

    .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .form-hint { font-size: 11.5px; color: #6b7280; margin-top: 4px; }

    /* ─── Seções do modal Nova/Editar Regra — mesmo padrão de pages/qualidade/paint-check.php ─── */
    .section-label { font-size: 12px; font-weight: 700; color: #1a3d2a; margin: 18px 0 10px 0; text-transform: uppercase; letter-spacing: .5px; display: flex; align-items: center; gap: 6px; }
    .section-label:first-of-type { margin-top: 4px; }
    .section-tag { display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; border-radius: 50%; background: #1a3d2a; color: #e8a020; font-size: 10px; font-weight: 800; flex-shrink: 0; }
    .checkbox-row { display: flex; gap: 16px; flex-wrap: wrap; margin-top: 4px; }
    .checkbox-inline { display: flex; align-items: center; gap: 6px; font-weight: 500; font-size: 13px; cursor: pointer; }

    /* ─── Chips de apelido (modal) ──────────────────────────────────────────── */
    .chip-input-box {
        display: flex; flex-wrap: wrap; gap: 6px; align-items: center;
        border: 1px solid var(--color-border,#d1d5db); border-radius: 8px; padding: 8px 10px;
        min-height: 42px;
    }
    .chip-input-box:focus-within { border-color: #E89B1C; box-shadow: 0 0 0 3px rgba(232,155,28,0.15); }
    .chip {
        display: inline-flex; align-items: center; gap: 6px;
        background: #eef2f7; color: #1f2937; border-radius: 9999px;
        padding: 4px 6px 4px 10px; font-size: 12.5px; font-weight: 600;
    }
    .chip button {
        border: none; background: #dbe3ea; color: #475569; border-radius: 50%;
        width: 16px; height: 16px; line-height: 1; cursor: pointer; font-size: 11px;
        display: inline-flex; align-items: center; justify-content: center;
    }
    .chip button:hover { background: #cbd5e1; color: #1f2937; }
    #concreg-apelido-input { border: none; outline: none; flex: 1; min-width: 120px; font-size: 13px; padding: 4px 2px; }
</style>

<div class="page-fixed-layout admin-page-container">
    <div class="admin-sticky-top">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;">
            <div>
                <h1 style="font-size:var(--font-size-xl,20px);font-weight:700;margin-top:2px;">Regras de Validação</h1>
                <p class="text-secondary" style="font-size:13px;color:var(--color-text-secondary,#6b7280);margin-top:2px;">
                    Formato do número gravado/serigrafado, locais obrigatórios de puncionamento e apelidos de cadastro (VSAT/produção) usados pelo Paint Check.
                </p>
            </div>
        </div>
    </div>

    <div class="admin-pane-card">
        <div class="admin-toolbar">
            <div class="admin-toolbar-left">
                <input type="search" id="filtro-concreg-busca" placeholder="Buscar por concessionária ou apelido..." style="min-width:280px;">
            </div>
            <div class="admin-toolbar-right">
                <?php if ($canEdit): ?>
                    <button type="button" class="btn-action-primary" id="btn-nova-regra">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:14px;height:14px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        <span>Nova Regra</span>
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="admin-table-scroll">
            <table class="table-admin">
                <thead>
                    <tr>
                        <th>Grupo do Cliente</th>
                        <th>Norma de referência</th>
                        <th>Locais obrigatórios</th>
                        <th>Patrimônio</th>
                        <th>Potência</th>
                        <th style="width:80px;text-align:center;">Elo Fusível</th>
                        <th style="width:90px;text-align:center;">Status</th>
                        <th style="width:90px;text-align:center;">Ações</th>
                    </tr>
                </thead>
                <tbody id="tbody-concreg">
                    <?php if (!$regras): ?>
                        <tr><td colspan="8" style="text-align:center;padding:30px;color:#64748b;">Nenhuma regra cadastrada.</td></tr>
                    <?php else: foreach ($regras as $r):
                        $ehParticular = $r['nome_grupo'] === 'PARTICULAR';
                        $search = mb_strtolower($r['nome_grupo'] . ' ' . implode(' ', $r['apelidos']));
                    ?>
                        <tr class="js-row-concreg <?= $ehParticular ? 'is-particular' : '' ?>" data-search="<?= htmlspecialchars($search) ?>">
                            <td>
                                <strong style="font-size:13.5px;color:#0f172a;"><?= htmlspecialchars($r['nome_grupo']) ?></strong>
                                <?php if ($r['apelidos']): ?>
                                    <div style="margin-top:4px;">
                                        <?php foreach (array_slice($r['apelidos'], 0, 2) as $ap): ?>
                                            <span class="badge-apelido"><?= htmlspecialchars($ap) ?></span>
                                        <?php endforeach; ?>
                                        <?php if (count($r['apelidos']) > 2): ?>
                                            <span class="badge-apelido">+<?= count($r['apelidos']) - 2 ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?= $r['norma_referencia'] ? htmlspecialchars($r['norma_referencia']) : '<span style="color:#94a3b8;">—</span>' ?></td>
                            <td>
                                <?php foreach (['tampa', 'tanque', 'gancho'] as $local): ?>
                                    <span class="badge-local <?= in_array($local, $r['locais_obrigatorios_arr'], true) ? '' : 'is-off' ?>"><?= ucfirst($local) ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <?php if ($r['local_codigo_adicional_arr']): ?>
                                    <?= htmlspecialchars(implode(', ', array_map('ucfirst', $r['local_codigo_adicional_arr']))) ?>
                                <?php else: ?>
                                    <span style="color:#94a3b8;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= htmlspecialchars(implode(', ', array_map('ucfirst', $r['local_potencia_arr']))) ?>
                            </td>
                            <td style="text-align:center;color:<?= $r['exige_elo_fusivel'] ? '#0f172a' : '#94a3b8' ?>;">
                                <?= $r['exige_elo_fusivel'] ? 'Sim' : '—' ?>
                            </td>
                            <td style="text-align:center;">
                                <span class="badge-status <?= $r['ativo'] ? 'is-ativo' : 'is-inativo' ?>">
                                    <span class="dot"></span>
                                    <span><?= $r['ativo'] ? 'Ativa' : 'Inativa' ?></span>
                                </span>
                            </td>
                            <td style="text-align:center;">
                                <?php if ($canEdit): ?>
                                    <div style="display:flex;gap:6px;justify-content:center;align-items:center;">
                                        <button type="button" class="btn-icon btn-icon-edit js-btn-editar-concreg" data-regra='<?= htmlspecialchars(json_encode($r, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>' title="Editar Regra">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                        </button>
                                        <button type="button" class="btn-icon btn-icon-danger js-btn-excluir-concreg" data-id="<?= (int) $r['id'] ?>" data-nome="<?= htmlspecialchars($r['nome_grupo']) ?>" title="Excluir Regra">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <span style="color:#94a3b8;font-size:12px;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    <tr class="js-concreg-empty-row" style="display:none;"><td colspan="8" style="text-align:center;padding:30px;color:#64748b;">Nenhuma regra encontrada.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- MODAL: NOVA / EDITAR REGRA                                                  -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-concreg" style="display:none;">
    <div class="modal" style="max-width:600px;">
        <div class="modal-header">
            <div>
                <span class="modal-title" id="m-concreg-title">Nova Regra de Concessionária</span>
                <p style="font-size:12px;color:#64748b;margin:2px 0 0;">Define como o Paint Check valida a serigrafia/puncionamento desta concessionária.</p>
            </div>
            <button type="button" class="modal-close js-close-modal">&times;</button>
        </div>

        <form id="form-concreg">
            <input type="hidden" name="id" id="cr-id" value="0">
            <div class="modal-body" style="overflow-y:auto;max-height:65vh;">
                <div id="cr-error" class="alert alert-danger" style="display:none;margin-bottom:14px;padding:8px 12px;font-size:12.5px;color:#dc2626;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;"></div>

                <p class="section-label"><span class="section-tag">1</span> Identificação</p>
                <div class="form-grid-2">
                    <div class="form-group">
                        <label for="cr-nome-grupo" class="form-label">Grupo do Cliente <span class="required">*</span></label>
                        <input type="text" id="cr-nome-grupo" name="nome_grupo" class="form-control" placeholder="Ex.: Equatorial" required>
                    </div>
                    <div class="form-group">
                        <label for="cr-norma" class="form-label">Norma de Referência</label>
                        <input type="text" id="cr-norma" name="norma_referencia" class="form-control" placeholder="Ex.: NTC 810010, NTC 810011">
                    </div>
                </div>
                <div class="form-group">
                    <label for="concreg-apelido-input" class="form-label">Apelidos / nomes de cadastro no VSAT</label>
                    <div class="chip-input-box" id="chip-box">
                        <input type="text" id="concreg-apelido-input" placeholder="Digite e pressione Enter (ex.: EQTL)">
                    </div>
                    <div class="form-hint">Nome que o cliente aparece no VSAT/produção — pode ser bem diferente do nome oficial (ex.: a Cemig aparece como "Arrow Transportes e Logistica Ltda").</div>
                </div>

                <p class="section-label"><span class="section-tag">2</span> Puncionamento</p>
                <div class="form-group">
                    <label class="form-label">Locais obrigatórios de puncionamento <span class="required">*</span></label>
                    <div class="checkbox-row">
                        <label class="checkbox-inline"><input type="checkbox" name="locais_obrigatorios[]" value="tampa" class="cr-local-obrig"> Tampa</label>
                        <label class="checkbox-inline"><input type="checkbox" name="locais_obrigatorios[]" value="tanque" class="cr-local-obrig"> Tanque</label>
                        <label class="checkbox-inline"><input type="checkbox" name="locais_obrigatorios[]" value="gancho" class="cr-local-obrig"> Gancho</label>
                    </div>
                </div>

                <p class="section-label"><span class="section-tag">3</span> Patrimônio</p>
                <div class="form-group">
                    <label class="form-label">Local do patrimônio</label>
                    <div class="checkbox-row">
                        <label class="checkbox-inline"><input type="checkbox" name="local_codigo_adicional[]" value="tanque" class="cr-local-codigo"> Tanque</label>
                        <label class="checkbox-inline"><input type="checkbox" name="local_codigo_adicional[]" value="tampa" class="cr-local-codigo"> Tampa</label>
                    </div>
                    <div class="form-hint">Onde o patrimônio/tombamento do cliente aparece serigrafado — marque os dois se aparecer nos dois locais (ex.: Equatorial, Neoenergia).</div>
                </div>

                <p class="section-label"><span class="section-tag">4</span> Potência</p>
                <div class="form-group">
                    <label class="form-label">Local da potência (kVA) <span class="required">*</span></label>
                    <div class="checkbox-row">
                        <label class="checkbox-inline"><input type="checkbox" name="local_potencia[]" value="tanque" class="cr-local-potencia"> Tanque</label>
                        <label class="checkbox-inline"><input type="checkbox" name="local_potencia[]" value="tampa" class="cr-local-potencia"> Tampa</label>
                    </div>
                    <div class="form-hint">Serigrafia universal — todas as concessionárias exigem no tanque; só a Energisa exige também na tampa.</div>
                </div>

                <p class="section-label"><span class="section-tag">5</span> Elo Fusível</p>
                <div class="form-group">
                    <label class="checkbox-inline" style="font-weight:500;"><input type="checkbox" name="exige_elo_fusivel" id="cr-elo-fusivel" value="1"> Exige evidência de elo fusível</label>
                    <div class="form-hint">Liga automaticamente a foto do elo fusível no Paint Check pra essa concessionária (ex.: Equatorial, Amazonas Energia).</div>
                </div>

                <p class="section-label"><span class="section-tag">6</span> Observações & Status</p>
                <div class="form-group">
                    <label for="cr-observacoes" class="form-label">Observações</label>
                    <textarea id="cr-observacoes" name="observacoes" class="form-control" rows="3" placeholder="Notas internas sobre essa concessionária"></textarea>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="checkbox-inline" style="font-weight:500;"><input type="checkbox" name="ativo" id="cr-ativo" value="1" checked> Regra ativa</label>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary js-close-modal">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="btn-submit-concreg">Salvar Regra</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- MODAL: CONFIRMAR EXCLUSÃO                                                   -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-confirm-delete-concreg" style="display:none;">
    <div class="modal" style="max-width:440px;">
        <div class="modal-header">
            <span class="modal-title">Confirmar Exclusão</span>
            <button type="button" class="modal-close js-close-modal">&times;</button>
        </div>
        <div class="modal-body">
            <p style="font-size:var(--font-size-base,14px);color:var(--color-text-secondary,#6b7280);margin:0 0 12px;line-height:1.5;" id="confirm-del-concreg-msg">
                Deseja realmente excluir esta regra?
            </p>
            <div id="confirm-del-concreg-error" class="alert alert-danger" style="display:none;margin-top:10px;padding:8px 12px;font-size:12.5px;color:#dc2626;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary js-close-modal">Cancelar</button>
            <button type="button" class="btn btn-danger" id="btn-exec-delete-concreg">Excluir Regra</button>
        </div>
    </div>
</div>

<script>
(function () {
    const API = <?= json_encode($base . '/api/concessionaria-regras-acao.php') ?>;

    function openModal(id) { const el = document.getElementById(id); if (el) el.style.display = 'flex'; }
    function closeModal(id) { const el = document.getElementById(id); if (el) el.style.display = 'none'; }

    document.querySelectorAll('.js-close-modal').forEach(b => {
        b.addEventListener('click', () => { const ov = b.closest('.modal-overlay'); if (ov) ov.style.display = 'none'; });
    });
    document.querySelectorAll('.modal-overlay').forEach(ov => {
        ov.addEventListener('click', (e) => { if (e.target === ov) ov.style.display = 'none'; });
    });

    // ─── Busca ────────────────────────────────────────────────────────────────
    const inputBusca = document.getElementById('filtro-concreg-busca');
    inputBusca?.addEventListener('input', () => {
        const q = inputBusca.value.toLowerCase().trim();
        let visible = 0;
        document.querySelectorAll('.js-row-concreg').forEach(r => {
            const match = !q || (r.dataset.search || '').includes(q);
            r.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        const empty = document.querySelector('.js-concreg-empty-row');
        if (empty) empty.style.display = (visible === 0) ? '' : 'none';
    });

    // ─── Chips de apelido ────────────────────────────────────────────────────
    const chipBox = document.getElementById('chip-box');
    const chipInput = document.getElementById('concreg-apelido-input');
    let apelidosAtuais = [];

    function renderChips() {
        chipBox.querySelectorAll('.chip').forEach(c => c.remove());
        apelidosAtuais.forEach((ap, idx) => {
            const chip = document.createElement('span');
            chip.className = 'chip';
            chip.innerHTML = `<span></span><button type="button" data-idx="${idx}">&times;</button>`;
            chip.querySelector('span').textContent = ap;
            chip.querySelector('button').addEventListener('click', () => {
                apelidosAtuais.splice(idx, 1);
                renderChips();
            });
            chipBox.insertBefore(chip, chipInput);
        });
    }

    function addApelido(valor) {
        valor = valor.trim();
        if (valor === '' || apelidosAtuais.includes(valor)) return;
        apelidosAtuais.push(valor);
        renderChips();
    }

    chipInput?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ',') {
            e.preventDefault();
            addApelido(chipInput.value);
            chipInput.value = '';
        } else if (e.key === 'Backspace' && chipInput.value === '' && apelidosAtuais.length > 0) {
            apelidosAtuais.pop();
            renderChips();
        }
    });
    chipBox?.addEventListener('click', () => chipInput.focus());

    // ─── Novo / Editar ───────────────────────────────────────────────────────
    const form = document.getElementById('form-concreg');
    const crId = document.getElementById('cr-id');
    const crNomeGrupo = document.getElementById('cr-nome-grupo');
    const crNorma = document.getElementById('cr-norma');
    const crObservacoes = document.getElementById('cr-observacoes');
    const crAtivo = document.getElementById('cr-ativo');
    const crEloFusivel = document.getElementById('cr-elo-fusivel');
    const crError = document.getElementById('cr-error');

    function resetForm() {
        crId.value = '0';
        crNomeGrupo.value = '';
        crNomeGrupo.disabled = false;
        crNorma.value = '';
        crObservacoes.value = '';
        crAtivo.checked = true;
        crEloFusivel.checked = false;
        apelidosAtuais = [];
        renderChips();
        document.querySelectorAll('.cr-local-obrig').forEach(cb => { cb.checked = false; });
        document.querySelectorAll('.cr-local-codigo').forEach(cb => { cb.checked = false; });
        // Potência é universal no tanque — pré-marcado por padrão em regra nova.
        document.querySelectorAll('.cr-local-potencia').forEach(cb => { cb.checked = (cb.value === 'tanque'); });
        if (crError) crError.style.display = 'none';
    }

    document.getElementById('btn-nova-regra')?.addEventListener('click', () => {
        resetForm();
        document.getElementById('m-concreg-title').textContent = 'Nova Regra de Concessionária';
        openModal('modal-concreg');
    });

    document.querySelectorAll('.js-btn-editar-concreg').forEach(btn => {
        btn.addEventListener('click', () => {
            const r = JSON.parse(btn.dataset.regra || '{}');
            resetForm();
            crId.value = r.id || '0';
            crNomeGrupo.value = r.nome_grupo || '';
            crNomeGrupo.disabled = r.nome_grupo === 'PARTICULAR';
            crNorma.value = r.norma_referencia || '';
            crObservacoes.value = r.observacoes || '';
            crAtivo.checked = !!Number(r.ativo);
            crEloFusivel.checked = !!Number(r.exige_elo_fusivel);
            apelidosAtuais = Array.isArray(r.apelidos) ? r.apelidos.slice() : [];
            renderChips();
            (r.locais_obrigatorios_arr || []).forEach(local => {
                const cb = document.querySelector(`.cr-local-obrig[value="${local}"]`);
                if (cb) cb.checked = true;
            });
            (r.local_codigo_adicional_arr || []).forEach(local => {
                const cb = document.querySelector(`.cr-local-codigo[value="${local}"]`);
                if (cb) cb.checked = true;
            });
            // resetForm() já pré-marca "Tanque" como default de regra nova — limpa antes
            // de aplicar o valor real da regra sendo editada.
            document.querySelectorAll('.cr-local-potencia').forEach(cb => { cb.checked = false; });
            (r.local_potencia_arr || []).forEach(local => {
                const cb = document.querySelector(`.cr-local-potencia[value="${local}"]`);
                if (cb) cb.checked = true;
            });
            if (crError) crError.style.display = 'none';
            document.getElementById('m-concreg-title').textContent = 'Editar Regra — ' + (r.nome_grupo || '');
            openModal('modal-concreg');
        });
    });

    form?.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (chipInput.value.trim() !== '') { addApelido(chipInput.value); chipInput.value = ''; }

        const btnSave = document.getElementById('btn-submit-concreg');
        btnSave.disabled = true;
        btnSave.textContent = 'Salvando...';

        const formData = new FormData(form);
        formData.set('nome_grupo', crNomeGrupo.value);
        formData.append('acao', 'salvar');
        apelidosAtuais.forEach(ap => formData.append('apelidos[]', ap));

        try {
            const res = await fetch(API, { method: 'POST', body: formData });
            const data = await res.json();
            if (data.sucesso) {
                window.location.reload();
            } else {
                if (crError) { crError.textContent = data.erro || 'Erro ao salvar regra.'; crError.style.display = ''; }
            }
        } catch (err) {
            if (crError) { crError.textContent = 'Erro de conexão com o servidor.'; crError.style.display = ''; }
        } finally {
            btnSave.disabled = false;
            btnSave.textContent = 'Salvar Regra';
        }
    });

    // ─── Excluir ─────────────────────────────────────────────────────────────
    let pendingDeleteId = null;
    const btnExecDelete = document.getElementById('btn-exec-delete-concreg');
    const deleteError = document.getElementById('confirm-del-concreg-error');

    document.querySelectorAll('.js-btn-excluir-concreg').forEach(btn => {
        btn.addEventListener('click', () => {
            pendingDeleteId = btn.dataset.id;
            document.getElementById('confirm-del-concreg-msg').innerHTML =
                `Deseja realmente excluir a regra de <strong>${btn.dataset.nome}</strong>?`;
            if (deleteError) deleteError.style.display = 'none';
            btnExecDelete.disabled = false;
            btnExecDelete.textContent = 'Excluir Regra';
            openModal('modal-confirm-delete-concreg');
        });
    });

    btnExecDelete?.addEventListener('click', async () => {
        if (!pendingDeleteId) return;
        btnExecDelete.disabled = true;
        btnExecDelete.textContent = 'Excluindo...';
        try {
            const formData = new FormData();
            formData.append('acao', 'excluir');
            formData.append('id', pendingDeleteId);
            const res = await fetch(API, { method: 'POST', body: formData });
            const data = await res.json();
            if (data.sucesso) {
                window.location.reload();
            } else {
                if (deleteError) { deleteError.textContent = data.erro || 'Não foi possível excluir a regra.'; deleteError.style.display = ''; }
                btnExecDelete.disabled = false;
                btnExecDelete.textContent = 'Excluir Regra';
            }
        } catch (err) {
            if (deleteError) { deleteError.textContent = 'Erro de conexão com o servidor.'; deleteError.style.display = ''; }
            btnExecDelete.disabled = false;
            btnExecDelete.textContent = 'Excluir Regra';
        }
    });
})();
</script>

<?php layoutFooter(); ?>
