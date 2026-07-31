<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();

$pdo  = getDB();
$base = defined('APP_URL') ? APP_URL : '';

$pedidos = $pdo->query("
    SELECT p.id, p.numero, p.prioridade,
           (SELECT COUNT(*) FROM projetos pr WHERE pr.id_pedido = p.id AND pr.deleted_at IS NULL) AS qtd_projetos
    FROM pedidos p
    WHERE p.deleted_at IS NULL
    ORDER BY p.id DESC
")->fetchAll();

$prioridades = pedidoPrioridades();

$pageTitle = 'Prioridade de Pedidos';
require_once __DIR__ . '/../../includes/layout.php';
layoutHeader($pageTitle);
?>

<style>
    .pp-card { background:var(--color-surface,#fff); border:1px solid var(--color-border,#e5e7eb); border-radius:var(--radius-lg,10px); padding:16px 18px; }
    .pp-table { width:100%; border-collapse:collapse; font-size:13px; }
    .pp-table th { text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.6px; color:var(--color-text-muted,#6b7280); padding:8px 10px; border-bottom:1px solid var(--color-border,#e5e7eb); white-space:nowrap; }
    .pp-table td { padding:10px; border-bottom:1px solid var(--color-border,#f1f5f9); vertical-align:middle; }
    .pp-table tr:hover td { background:var(--color-surface-2,#f9fafb); }
    .pp-code { font-family:'JetBrains Mono',monospace; font-weight:600; color:var(--color-text-primary,#111827); }
    .pp-badge { display:inline-block; padding:2px 9px; border-radius:9999px; font-size:11px; font-weight:600; background:#eef2ff; color:#4338ca; }
    .pp-chips { display:flex; flex-wrap:wrap; gap:6px; }
    .pp-chip {
        display:inline-flex; align-items:center; gap:6px; padding:5px 12px;
        border:1px solid var(--color-border-strong,#d1d5db); border-radius:9999px;
        font-size:12px; font-weight:600; cursor:pointer; user-select:none; background:#fff; color:var(--color-text-secondary,#5a6480);
        transition:opacity .15s;
    }
    .pp-chip:hover { opacity:.85; }
    .pp-chip.active { border-color:transparent; }
    .pp-chip[data-saving="1"] { opacity:.5; pointer-events:none; }
    .pp-empty { text-align:center; padding:30px 16px; color:var(--color-text-muted,#6b7280); font-size:13px; }
    .pp-saved-tag { font-size:11px; color:#16a34a; margin-left:8px; opacity:0; transition:opacity .3s; }
    .pp-saved-tag.show { opacity:1; }
</style>

<!-- Cabeçalho -->
<div style="margin-bottom:22px;">
    <h1 style="font-size:var(--font-size-xl,20px);font-weight:700;">Prioridade de Pedidos</h1>
    <p class="text-secondary" style="font-size:13px;color:var(--color-text-secondary,#6b7280);margin-top:2px;">
        Defina a prioridade de produção de cada pedido — aparece na coluna "Prioridade" da Relação de Retrabalhos.
    </p>
</div>

<div class="pp-card">
    <div style="overflow-x:auto;">
        <table class="pp-table">
            <thead>
                <tr>
                    <th>Pedido</th><th style="text-align:center;">Projetos</th><th>Prioridade</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$pedidos): ?>
                    <tr><td colspan="3"><div class="pp-empty">Nenhum pedido cadastrado.</div></td></tr>
                <?php else: foreach ($pedidos as $p): ?>
                    <tr>
                        <td><span class="pp-code"><?= htmlspecialchars($p['numero']) ?></span></td>
                        <td style="text-align:center;"><span class="pp-badge"><?= (int) $p['qtd_projetos'] ?></span></td>
                        <td>
                            <div class="pp-chips" data-id="<?= (int) $p['id'] ?>">
                                <?php foreach ($prioridades as $slug => $info): $ativo = $p['prioridade'] === $slug; ?>
                                    <span class="pp-chip js-prioridade-chip<?= $ativo ? ' active' : '' ?>"
                                          data-slug="<?= htmlspecialchars($slug) ?>"
                                          style="<?= $ativo ? 'background:' . $info['bg'] . ';color:' . $info['fg'] . ';' : '' ?>">
                                        <?= htmlspecialchars($info['label']) ?>
                                    </span>
                                <?php endforeach; ?>
                                <span class="pp-saved-tag">Salvo</span>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    window.PROJETOS_API = <?= json_encode($base . '/api/projetos-acao.php') ?>;
    window.PRIORIDADES_INFO = <?= json_encode($prioridades, JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php $ppJsVer = @filemtime(__DIR__ . '/../../assets/js/pedidos-prioridade.js') ?: (defined('APP_VERSION') ? APP_VERSION : '1'); ?>
<script src="<?= htmlspecialchars($base) ?>/assets/js/pedidos-prioridade.js?v=<?= htmlspecialchars((string) $ppJsVer) ?>"></script>

<?php layoutFooter(); ?>
