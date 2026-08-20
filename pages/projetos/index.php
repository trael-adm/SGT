<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';

requireAcessoModulo('retrabalho');

$pdo  = getDB();
$base = defined('APP_URL') ? APP_URL : '';

// ─── Pedidos (com contagem de projetos) ───────────────────────────────────────
$pedidos = $pdo->query("
    SELECT p.*,
           (SELECT COUNT(*) FROM projetos pr WHERE pr.id_pedido = p.id AND pr.deleted_at IS NULL) AS qtd_projetos
    FROM pedidos p
    WHERE p.deleted_at IS NULL
    ORDER BY p.id DESC
")->fetchAll();

// ─── Projetos (com número do pedido) ──────────────────────────────────────────
$projetos = $pdo->query("
    SELECT pr.*, ped.numero AS pedido_numero
    FROM projetos pr
    JOIN pedidos ped ON ped.id = pr.id_pedido
    WHERE pr.deleted_at IS NULL
    ORDER BY pr.id DESC
")->fetchAll();

function fmtDataBR(?string $iso): string
{
    if (!$iso) return '—';
    $ts = strtotime($iso);
    return $ts ? date('d/m/Y', $ts) : '—';
}

$pageTitle = 'Cadastro de Projetos';
require_once __DIR__ . '/../../includes/layout.php';
layoutHeader($pageTitle);
?>

<style>
    .pj-card { background:var(--color-surface,#fff); border:1px solid var(--color-border,#e5e7eb); border-radius:var(--radius-lg,10px); padding:16px 18px; margin-bottom:20px; }
    .pj-card-head { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px; flex-wrap:wrap; }
    .pj-card-head h3 { font-size:14px; font-weight:600; color:var(--color-text-primary,#111827); }
    .pj-table { width:100%; border-collapse:collapse; font-size:13px; }
    .pj-table th { text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.6px; color:var(--color-text-muted,#6b7280); padding:8px 10px; border-bottom:1px solid var(--color-border,#e5e7eb); white-space:nowrap; }
    .pj-table td { padding:9px 10px; border-bottom:1px solid var(--color-border,#f1f5f9); vertical-align:middle; }
    .pj-table tr:hover td { background:var(--color-surface-2,#f9fafb); }
    .pj-badge { display:inline-block; padding:2px 9px; border-radius:9999px; font-size:11px; font-weight:600; background:#eef2ff; color:#4338ca; }
    .pj-code { font-family:'JetBrains Mono',monospace; font-weight:600; color:var(--color-text-primary,#111827); }
    .pj-btn-acc { background:#E89B1C; color:#0e2c1d; border:none; padding:9px 16px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; }
    .pj-btn-acc:hover { opacity:.92; }
    .pj-row-act { background:none; border:1px solid var(--color-border,#e5e7eb); border-radius:6px; padding:4px 8px; font-size:11px; cursor:pointer; color:var(--color-text-secondary,#374151); }
    .pj-row-act:hover { background:var(--color-surface-2,#f3f4f6); }
    .pj-row-act.danger:hover { background:#fef2f2; color:#dc2626; border-color:#fecaca; }
    .pj-empty { text-align:center; padding:30px 16px; color:var(--color-text-muted,#6b7280); font-size:13px; }
    /* Modal */
    .modal-overlay { position:fixed; inset:0; background:rgba(15,23,42,.5); display:none; align-items:flex-start; justify-content:center; z-index:1000; padding:40px 16px; overflow-y:auto; }
    .modal-box { background:#fff; border-radius:14px; width:100%; max-width:480px; box-shadow:0 20px 50px rgba(0,0,0,.3); }
    .modal-head { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #e5e7eb; }
    .modal-head h2 { font-size:16px; font-weight:700; }
    .modal-close { background:none; border:none; font-size:22px; line-height:1; cursor:pointer; color:#9ca3af; }
    .modal-body { padding:18px 20px; }
    .pj-field { margin-bottom:14px; }
    .pj-field:last-child { margin-bottom:0; }
    .pj-field label { display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:4px; }
    .pj-field input, .pj-field select { width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:8px; font-size:13px; }
    .pj-hint { font-size:11px; color:#6b7280; margin-top:4px; }
    .modal-foot { display:flex; justify-content:flex-end; gap:10px; padding:16px 20px; border-top:1px solid #e5e7eb; }
    .pj-btn-secondary { background:#fff; border:1px solid #d1d5db; border-radius:8px; padding:9px 16px; font-size:13px; font-weight:600; cursor:pointer; }
    .pj-form-erro { display:none; background:#fef2f2; color:#dc2626; border:1px solid #fecaca; border-radius:8px; padding:8px 12px; font-size:13px; margin-bottom:14px; }
</style>

<!-- Cabeçalho -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:22px;">
    <div>
        <h1 style="font-size:var(--font-size-xl,20px);font-weight:700;">Cadastro de Projetos</h1>
        <p class="text-secondary" style="font-size:13px;color:var(--color-text-secondary,#6b7280);margin-top:2px;">
            Pedidos e seus projetos — usados no registro de retrabalho
        </p>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <button type="button" class="pj-btn-secondary" id="btn-novo-pedido">+ Novo pedido</button>
        <button type="button" class="pj-btn-acc" id="btn-novo-projeto">+ Novo projeto</button>
    </div>
</div>

<!-- Pedidos -->
<div class="pj-card">
    <div class="pj-card-head">
        <h3>Pedidos</h3>
        <span style="font-size:12px;color:var(--color-text-muted,#6b7280);"><?= count($pedidos) ?> pedido(s)</span>
    </div>
    <div style="overflow-x:auto;">
        <table class="pj-table">
            <thead>
                <tr>
                    <th>Número</th><th style="text-align:center;">Projetos</th><th>Cadastrado em</th><th style="text-align:right;">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$pedidos): ?>
                    <tr><td colspan="4"><div class="pj-empty">Nenhum pedido cadastrado. Comece criando um pedido.</div></td></tr>
                <?php else: foreach ($pedidos as $p): ?>
                    <tr>
                        <td><span class="pj-code"><?= htmlspecialchars($p['numero']) ?></span></td>
                        <td style="text-align:center;"><span class="pj-badge"><?= (int) $p['qtd_projetos'] ?></span></td>
                        <td style="white-space:nowrap;"><?= htmlspecialchars(fmtDataBR($p['created_at'])) ?></td>
                        <td style="text-align:right;white-space:nowrap;">
                            <div style="display:flex;gap:6px;justify-content:flex-end;align-items:center;">
                                <button type="button" class="btn-icon btn-icon-edit js-edit-pedido"
                                    data-id="<?= (int) $p['id'] ?>"
                                    data-numero="<?= htmlspecialchars($p['numero']) ?>"
                                    title="Editar pedido">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                </button>
                                <button type="button" class="btn-icon btn-icon-danger js-del-pedido" data-id="<?= (int) $p['id'] ?>" title="Excluir pedido">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Projetos -->
<div class="pj-card">
    <div class="pj-card-head">
        <h3>Projetos</h3>
        <span style="font-size:12px;color:var(--color-text-muted,#6b7280);"><?= count($projetos) ?> projeto(s)</span>
    </div>
    <div style="overflow-x:auto;">
        <table class="pj-table">
            <thead>
                <tr>
                    <th>Código</th><th>Descrição / modelo</th><th>Pedido</th><th>Cadastrado em</th><th style="text-align:right;">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$projetos): ?>
                    <tr><td colspan="5"><div class="pj-empty">Nenhum projeto cadastrado.</div></td></tr>
                <?php else: foreach ($projetos as $pr): ?>
                    <tr>
                        <td><span class="pj-code"><?= htmlspecialchars($pr['codigo']) ?></span></td>
                        <td><?= htmlspecialchars($pr['descricao'] ?? '—') ?></td>
                        <td><span class="pj-code" style="font-weight:500;"><?= htmlspecialchars($pr['pedido_numero']) ?></span></td>
                        <td style="white-space:nowrap;"><?= htmlspecialchars(fmtDataBR($pr['created_at'])) ?></td>
                        <td style="text-align:right;white-space:nowrap;">
                            <div style="display:flex;gap:6px;justify-content:flex-end;align-items:center;">
                                <button type="button" class="btn-icon btn-icon-edit js-edit-projeto"
                                    data-id="<?= (int) $pr['id'] ?>"
                                    data-codigo="<?= htmlspecialchars($pr['codigo']) ?>"
                                    data-descricao="<?= htmlspecialchars($pr['descricao'] ?? '') ?>"
                                    data-id_pedido="<?= (int) $pr['id_pedido'] ?>"
                                    title="Editar projeto">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                </button>
                                <button type="button" class="btn-icon btn-icon-danger js-del-projeto" data-id="<?= (int) $pr['id'] ?>" title="Excluir projeto">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Pedido -->
<div class="modal-overlay" id="pj-modal-pedido">
    <div class="modal-box">
        <form id="pj-form-pedido">
            <div class="modal-head">
                <h2 id="pj-pedido-title">Novo pedido</h2>
                <button type="button" class="modal-close" data-close="pj-modal-pedido">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id" id="ped-id" value="">
                <div class="pj-form-erro" id="pj-pedido-erro"></div>
                <div class="pj-field">
                    <label for="ped-numero">Número do pedido *</label>
                    <input type="text" name="numero" id="ped-numero" placeholder="Ex.: PED-10422" required>
                    <div class="pj-hint">Deve ser único. Um pedido agrupa vários projetos.</div>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="pj-btn-secondary" data-close="pj-modal-pedido">Cancelar</button>
                <button type="submit" class="pj-btn-acc" id="pj-pedido-submit">Salvar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Projeto -->
<div class="modal-overlay" id="pj-modal-projeto">
    <div class="modal-box">
        <form id="pj-form-projeto">
            <div class="modal-head">
                <h2 id="pj-projeto-title">Novo projeto</h2>
                <button type="button" class="modal-close" data-close="pj-modal-projeto">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id" id="proj-id" value="">
                <div class="pj-form-erro" id="pj-projeto-erro"></div>
                <div class="pj-field">
                    <label for="proj-id_pedido">Pedido *</label>
                    <select name="id_pedido" id="proj-id_pedido" required>
                        <option value="">Selecione o pedido…</option>
                        <?php foreach ($pedidos as $p): ?>
                            <option value="<?= (int) $p['id'] ?>"><?= htmlspecialchars($p['numero']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$pedidos): ?>
                        <div class="pj-hint" style="color:#dc2626;">Cadastre um pedido antes de criar um projeto.</div>
                    <?php endif; ?>
                </div>
                <div class="pj-field">
                    <label for="proj-codigo">Código do projeto *</label>
                    <input type="text" name="codigo" id="proj-codigo" placeholder="Ex.: TPD-378787" required>
                    <div class="pj-hint">Deve ser único no sistema.</div>
                </div>
                <div class="pj-field">
                    <label for="proj-descricao">Descrição / modelo</label>
                    <input type="text" name="descricao" id="proj-descricao" placeholder="Ex.: Transformador 500 kVA">
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="pj-btn-secondary" data-close="pj-modal-projeto">Cancelar</button>
                <button type="submit" class="pj-btn-acc" id="pj-projeto-submit">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
    window.PROJETOS_API = <?= json_encode($base . '/api/projetos-acao.php') ?>;
</script>
<?php $pjJsVer = @filemtime(__DIR__ . '/../../assets/js/projetos.js') ?: (defined('APP_VERSION') ? APP_VERSION : '1'); ?>
<script src="<?= htmlspecialchars($base) ?>/assets/js/projetos.js?v=<?= htmlspecialchars((string) $pjJsVer) ?>"></script>

<?php layoutFooter(); ?>
