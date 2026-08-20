<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireAcessoModulo('retrabalho');

$pdo  = getDB();
$base = defined('APP_URL') ? APP_URL : '';

$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT r.id, r.ns_transformador, r.data_chegada, pr.codigo AS projeto_codigo, ped.numero AS pedido_numero
    FROM retrabalhos r
    JOIN projetos pr  ON pr.id  = r.id_projeto
    JOIN pedidos ped  ON ped.id = pr.id_pedido
    WHERE r.id = ? AND r.deleted_at IS NULL
");
$stmt->execute([$id]);
$registro = $stmt->fetch();

$pageTitle = 'Confirmar Chegada';
require_once __DIR__ . '/../../includes/layout.php';
layoutHeader($pageTitle);

if (!$registro || $registro['data_chegada'] !== null) {
    ?>
    <div class="card">
        <p style="font-size:13px;color:var(--color-text-secondary,#6b7280);">
            <?= !$registro ? 'Registro não encontrado.' : 'A chegada deste registro já foi confirmada.' ?>
        </p>
        <a href="<?= htmlspecialchars($base) ?>/pages/retrabalho/relacao.php" class="btn btn-secondary" style="margin-top:10px;">&larr; Voltar para a Relação de Retrabalhos</a>
    </div>
    <?php
    layoutFooter();
    exit;
}
?>

<div class="scan-overlay" id="scanOverlay" role="dialog" aria-modal="true" aria-label="Confirmar chegada por QR Code" style="display:flex;">
    <div class="scan-overlay__bar">
        <span>
            Confirmar chegada — <strong><?= htmlspecialchars($registro['ns_transformador']) ?></strong>
            · <?= htmlspecialchars($registro['projeto_codigo'] ?? '—') ?>
            · Pedido <?= htmlspecialchars($registro['pedido_numero'] ?? '—') ?>
        </span>
        <a class="scan-overlay__close" href="<?= htmlspecialchars($base) ?>/pages/retrabalho/relacao.php" aria-label="Voltar para a Relação">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </a>
    </div>

    <div class="scan-stage" id="scanStage">
        <video id="scanVideo" autoplay playsinline muted></video>
        <div class="viewfinder" id="viewfinder">
            <span class="corner corner--tl"></span><span class="corner corner--tr"></span>
            <span class="corner corner--bl"></span><span class="corner corner--br"></span>
            <span class="scan-line"></span>
        </div>
        <div class="scan-stage__empty" id="scanStageEmpty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
            <p id="scanStageEmptyText">Solicitando acesso à câmera…</p>
        </div>
    </div>

    <p class="scan-hint" id="scanHint">Aponte a câmera para o QR Code do transformador</p>
    <div class="scan-overlay__error" id="overlayError"></div>

    <div class="scan-overlay__manual">
        <button class="btn btn-secondary btn-block" id="showManualBtn" type="button">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2" ry="2"></rect><line x1="6" y1="8" x2="6" y2="8"></line><line x1="10" y1="8" x2="10" y2="8"></line><line x1="14" y1="8" x2="14" y2="8"></line><line x1="18" y1="8" x2="18" y2="8"></line><line x1="6" y1="12" x2="6" y2="12"></line><line x1="10" y1="12" x2="10" y2="12"></line><line x1="14" y1="12" x2="14" y2="12"></line><line x1="18" y1="12" x2="18" y2="12"></line><line x1="7" y1="16" x2="17" y2="16"></line></svg>
            Digitar manualmente
        </button>
        <div class="manual-row" id="manualInputRow" style="display:none; margin-top:12px;">
            <input id="manualNs" type="text" placeholder="Ex.: 900201" autocomplete="off">
            <button class="btn btn-secondary" id="manualBtn" type="button">Buscar</button>
        </div>
    </div>
</div>

<button class="fab fab--secondary" id="uploadImageBtn" type="button" aria-label="Carregar imagem com QR Code">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
</button>
<input type="file" id="qrImageInput" accept="image/*" style="display:none">

<div id="liveRegion" aria-live="polite" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;"></div>

<script>
    window.RETRABALHO_API = <?= json_encode($base . '/api/retrabalho-acao.php') ?>;
    window.RETRABALHO_CHEGADA_ID = <?= json_encode($registro['id']) ?>;
    window.RETRABALHO_CHEGADA_VOLTAR = <?= json_encode($base . '/pages/retrabalho/relacao.php') ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
<?php $rcJsVer = @filemtime(__DIR__ . '/../../assets/js/retrabalho-chegada.js') ?: (defined('APP_VERSION') ? APP_VERSION : '1'); ?>
<script src="<?= htmlspecialchars($base) ?>/assets/js/retrabalho-chegada.js?v=<?= htmlspecialchars((string) $rcJsVer) ?>"></script>

<?php layoutFooter(); ?>
