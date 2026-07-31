<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();

$base = defined('APP_URL') ? APP_URL : '';

$pageTitle = 'Painel de Retrabalho';
require_once __DIR__ . '/../../includes/layout.php';
layoutHeader($pageTitle);
?>

<div class="card" style="max-width:480px;margin:60px auto;padding:40px 30px;text-align:center;">
    <div style="font-size:40px;line-height:1;margin-bottom:14px;">🚧</div>
    <h1 style="font-size:var(--font-size-xl,20px);font-weight:700;margin-bottom:8px;">Painel de Retrabalho</h1>
    <p style="font-size:13px;color:var(--color-text-secondary,#6b7280);margin-bottom:22px;">
        Em construção — em breve por aqui.
    </p>
    <a href="<?= htmlspecialchars($base) ?>/pages/retrabalho/relacao.php" class="btn btn-primary">
        Ir para a Relação de Retrabalhos
    </a>
</div>

<?php layoutFooter(); ?>
