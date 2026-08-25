<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/layout.php';

requirePerfil([1, 2, 201, 202, 203, 204, 205, 206, 207, 212]);

$pdo     = getDB();
$usuario = currentUser();
$base    = defined('APP_URL') ? APP_URL : '';

// ─── Mês de referência das metas — independente da data de hoje ─────────────
$mes = trim((string) ($_GET['mes'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
    $mes = date('Y-m');
}

$stmt = $pdo->prepare("SELECT * FROM boletim_config_metas WHERE `month` = ?");
$stmt->execute([$mes]);
$metas = $stmt->fetch() ?: [
    'meta_tpm' => 0, 'meta_tpd_distribuicao' => 0,
    'meta_enrolado' => 0, 'meta_convencional' => 0, 'meta_jctrif' => 0,
    'meta_tpd_forca' => 0, 'meta_tps' => 0,
];

// Calendário automático (pedido do usuário em 18/08/2026) — dias úteis
// (seg-sex) calculados direto do mês de referência, não mais digitados.
$diasUteisCalc       = boletimDiasUteisDoMes($mes);
$diasTrabalhadosCalc = boletimDiasUteisTrabalhados($mes);

$MESES_PT = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho',
             7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
[$anoRef, $mesRef] = array_map('intval', explode('-', $mes));
$mesTxt = $MESES_PT[$mesRef] . '/' . $anoRef;

$pageTitle = 'Configurações';
layoutHeader($pageTitle);
?>
<style>
    .bo-ref-row { display:flex; flex-wrap:wrap; gap:12px; margin-bottom:20px; }
    .bo-ref-chip {
        display:flex; align-items:center; gap:10px;
        background:var(--color-surface); border:1px solid var(--color-border);
        border-radius:var(--radius-lg); padding:10px 16px; box-shadow:var(--shadow-sm);
    }
    .bo-ref-chip .bo-ref-label { font-size:var(--font-size-xs); font-weight:600; text-transform:uppercase; letter-spacing:.6px; color:var(--color-text-muted); }
    .bo-ref-chip .bo-ref-value { font-size:var(--font-size-base); font-weight:600; color:var(--color-text-primary); }
    .bo-ref-chip form { display:flex; align-items:center; gap:8px; }
    .bo-ref-chip input[type=month] { border:1px solid var(--color-border-strong); border-radius:var(--radius-md); padding:5px 8px; font-size:var(--font-size-sm); font-family:var(--font-sans); }

    .bo-settings-grid { display:grid; grid-template-columns:2fr 1fr; gap:16px; margin-bottom:20px; align-items:start; }
    @media (max-width:900px) { .bo-settings-grid { grid-template-columns:1fr; } }

    .bo-form-grid { display:grid; grid-template-columns:repeat(4, 1fr); gap:14px; }
    @media (max-width:700px) { .bo-form-grid { grid-template-columns:1fr 1fr; } }
    .bo-form-actions { display:flex; gap:8px; grid-column:1 / -1; margin-top:6px; }

    .bo-section-title { font-size:var(--font-size-sm); font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--color-text-secondary); margin:18px 0 10px; }
    .bo-section-title:first-of-type { margin-top:0; }

    .bo-calendario-item { margin-bottom:16px; }
    .bo-calendario-item .form-label { margin-bottom:4px; }
    .bo-calendario-value { font-size:var(--font-size-xl); font-weight:700; color:var(--color-text-primary); }
    .bo-calendario-hint { font-size:var(--font-size-xs); color:var(--color-text-muted); margin-top:2px; }
</style>

<div style="margin-bottom:18px;">
    <h1 style="font-size:var(--font-size-xl);font-weight:700;">Configurações</h1>
    <p class="text-secondary" style="font-size:var(--font-size-base);margin-top:2px;">
        Metas mensais e calendário de produção
    </p>
</div>

<div class="bo-ref-row">
    <div class="bo-ref-chip">
        <span class="bo-ref-label">Mês das metas</span>
        <form method="GET">
            <input type="month" name="mes" value="<?= htmlspecialchars($mes) ?>" onchange="this.form.submit()">
            <span class="bo-ref-value"><?= htmlspecialchars($mesTxt) ?></span>
        </form>
    </div>
</div>

<!-- Metas Mensais + Calendário -->
<form id="bo-metas-form">
    <input type="hidden" name="month" value="<?= htmlspecialchars($mes) ?>">
    <div class="bo-settings-grid">
        <div class="card">
            <div class="card-header"><span class="card-title">Metas Mensais</span></div>
            <div id="bo-metas-erro" class="alert alert-danger" style="display:none;"></div>

            <h3 class="bo-section-title">Média Força</h3>
            <div class="bo-form-grid" style="grid-template-columns:repeat(3, 1fr);">
                <div class="form-group">
                    <label class="form-label" for="m-tpm">Meta TPM</label>
                    <input type="number" name="meta_tpm" id="m-tpm" class="form-control" min="0" value="<?= (int) $metas['meta_tpm'] ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="m-tps">Meta TPS</label>
                    <input type="number" name="meta_tps" id="m-tps" class="form-control" min="0" value="<?= (int) $metas['meta_tps'] ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="m-tpd-forca">Meta TPD — Média Força</label>
                    <input type="number" name="meta_tpd_forca" id="m-tpd-forca" class="form-control" min="0" value="<?= (int) $metas['meta_tpd_forca'] ?>">
                </div>
            </div>

            <h3 class="bo-section-title">Distribuição</h3>
            <div class="bo-form-grid">
                <div class="form-group">
                    <label class="form-label" for="m-tpd-distrib">Meta Total</label>
                    <input type="number" name="meta_tpd_distribuicao" id="m-tpd-distrib" class="form-control" min="0" value="<?= (int) $metas['meta_tpd_distribuicao'] ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="m-enrolado">Meta Enrolado</label>
                    <input type="number" name="meta_enrolado" id="m-enrolado" class="form-control" min="0" value="<?= (int) $metas['meta_enrolado'] ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="m-convencional">Meta Convencional</label>
                    <input type="number" name="meta_convencional" id="m-convencional" class="form-control" min="0" value="<?= (int) $metas['meta_convencional'] ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="m-jctrif">Meta JC-TRIF</label>
                    <input type="number" name="meta_jctrif" id="m-jctrif" class="form-control" min="0" value="<?= (int) $metas['meta_jctrif'] ?>">
                </div>
            </div>
            <button type="submit" class="btn btn-primary" id="bo-metas-submit" style="width:100%;margin-top:8px;">Salvar Metas do Mês</button>
        </div>

        <div class="card">
            <div class="card-header"><span class="card-title">Calendário Mensal</span></div>
            <p class="text-muted text-sm" style="margin-bottom:16px;">Calculado automaticamente — considera só dias úteis (seg. a sex.).</p>
            <div class="bo-calendario-item">
                <div class="form-label">Dias Úteis</div>
                <div class="bo-calendario-value"><?= $diasUteisCalc ?></div>
                <div class="bo-calendario-hint">no mês inteiro</div>
            </div>
            <div class="bo-calendario-item" style="margin-bottom:0;">
                <div class="form-label">Dias Trabalhados</div>
                <div class="bo-calendario-value"><?= $diasTrabalhadosCalc ?></div>
                <div class="bo-calendario-hint">dias úteis já decorridos</div>
            </div>
        </div>
    </div>
</form>

<script>
    window.BOLETIM_API = <?= json_encode($base . '/api/boletim-acao.php') ?>;
</script>
<?php $settingsJsVer = @filemtime(__DIR__ . '/../../assets/js/settings.js') ?: (defined('APP_VERSION') ? APP_VERSION : '1'); ?>
<script src="<?= htmlspecialchars($base) ?>/assets/js/settings.js?v=<?= htmlspecialchars((string) $settingsJsVer) ?>"></script>

<?php layoutFooter(); ?>
