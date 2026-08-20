<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireAcessoModulo('inspecao_final');

$pdo  = getDB();
$base = defined('APP_URL') ? APP_URL : '';

if (!hasAcesso('iqf.reg')) {
    if (hasAcesso('iqf.lis')) {
        header('Location: ' . $base . '/pages/inspecao_final/lista.php');
        exit;
    } elseif (hasAcesso('iqf.ret')) {
        header('Location: ' . $base . '/pages/inspecao_final/retornos.php');
        exit;
    }
}

// Estação coberta nesta 1ª atividade do módulo (IQF/GER seguem o mesmo padrão de card
// quando forem especificadas — ver PROJETO-SGT/producao-tela-spec.md).
$estacaoAtual = 'IQF';

$estacaoMap = [
    'IQF' => ['label' => 'IQF', 'title' => 'Inspeção Final', 'bg' => '#ecfeff', 'fg' => '#0891b2'],
];

// Item em andamento no Laboratório agora — carregado direto do banco (estado real,
// sobrevive a reload de página e é visível para qualquer tablet que abra esta tela).
$stmt = $pdo->prepare("
    SELECT pe.id, pe.ns_transformador, pe.data_inicio, pr.codigo AS projeto_codigo, ped.numero AS pedido_numero
    FROM producao_etapas pe
    JOIN projetos pr ON pr.id = pe.id_projeto
    JOIN pedidos ped ON ped.id = pr.id_pedido
    WHERE pe.estacao = ? AND pe.status = 'em_andamento' AND pe.deleted_at IS NULL
    ORDER BY pe.data_inicio DESC
    LIMIT 1
");
$stmt->execute([$estacaoAtual]);
$itemAtualLab = $stmt->fetch() ?: null;
if ($itemAtualLab) {
    // ISO-8601 com offset explícito — o JS não deve interpretar a hora como fuso local do
    // dispositivo (ver isoComOffset() em includes/helpers.php).
    $itemAtualLab['data_inicio'] = isoComOffset($itemAtualLab['data_inicio']);
}

$pageTitle = 'Tela de Produção';
require_once __DIR__ . '/../../includes/layout.php';
layoutHeader($pageTitle);
?>



<div class="station-grid">

    <section class="station-card" id="iqfCard">
        <header class="station-card__head">
            <span class="station-pill" style="background:<?= $estacaoMap['IQF']['bg'] ?>;color:<?= $estacaoMap['IQF']['fg'] ?>;">IQF</span>
            <div>
                <h2>Inspeção Final</h2>
                <p class="station-card__sub" id="iqfSub">Nenhum transformador em andamento</p>
            </div>
        </header>

        <div class="station-card__status" id="iqfStatus"></div>

        <div class="scan-panel">
            <p class="scan-review__empty" id="scanReviewEmpty">Aguardando leitura…</p>

            <div class="scan-review__data" id="scanReviewData">
                <div class="scan-head">
                    <div>
                        <span class="scan-head__date" id="scanDate"></span>
                        <span class="scan-head__status" id="scanStatusLabel"></span>
                    </div>
                    <span class="scan-panel__meta" id="scanMeta">Aguardando leitura</span>
                </div>

                <div class="scan-fields">
                    <div class="scan-field">
                        <span class="scan-field__label">Número de série</span>
                        <span class="scan-field__value" id="fieldNs"></span>
                    </div>
                    <div class="scan-field">
                        <span class="scan-field__label">Projeto</span>
                        <span class="scan-field__value" id="fieldProjeto"></span>
                    </div>
                    <div class="scan-field scan-field--full">
                        <span class="scan-field__label">Descrição do projeto</span>
                        <span class="scan-field__value" id="fieldDescricao"></span>
                    </div>
                    <div class="scan-field">
                        <span class="scan-field__label">Pedido</span>
                        <span class="scan-field__value" id="fieldPedido"></span>
                    </div>
                    <div class="scan-field">
                        <span class="scan-field__label">Cliente</span>
                        <span class="scan-field__value" id="fieldCliente"></span>
                    </div>
                </div>

                <div class="scan-conflict" id="scanConflict"></div>
                <div class="scan-conflict" id="scanUnresolved">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    <span>Projeto não cadastrado no sistema — cadastre o Pedido/Projeto em <strong>Pedidos e Projetos</strong> antes de registrar.</span>
                </div>

                <div class="scan-actions" id="scanActions">
                    <button type="button" class="btn btn-secondary" id="cancelScanBtn">Cancelar leitura</button>
                    <button type="button" class="btn btn-primary" id="confirmScanBtn">Registrar</button>
                </div>
                <div class="scan-actions" id="conflictActions">
                    <button type="button" class="btn btn-secondary" id="clearConflictBtn">Descartar</button>
                    <button type="button" class="btn btn-primary" id="retryConflictBtn">Ler novamente</button>
                </div>
            </div>
        </div>
    </section>

</div>

<button class="fab fab--secondary" id="uploadImageBtn" type="button" aria-label="Carregar imagem com QR Code">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
</button>
<input type="file" id="qrImageInput" accept="image/*" style="display:none">

<button class="fab" id="fabBtn" type="button" aria-haspopup="dialog" aria-label="Ler QR Code do transformador">
    <?= _sidebarIcon('scan-eye') ?>
</button>

<div class="scan-overlay" id="scanOverlay" role="dialog" aria-modal="true" aria-label="Leitor de QR Code">
    <div class="scan-overlay__bar">
        <span>Leitor de QR Code</span>
        <button class="scan-overlay__close" id="closeOverlayBtn" type="button" aria-label="Fechar leitor">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
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

<div id="liveRegion" aria-live="polite" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;"></div>

<script>
    window.PRODUCAO_API = <?= json_encode($base . '/api/producao-acao.php', JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    window.PRODUCAO_ESTACAO = <?= json_encode($estacaoAtual, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    window.PRODUCAO_ITEM_ATUAL = <?= json_encode($itemAtualLab, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
<?php $pJsVer = @filemtime(__DIR__ . '/../../assets/js/producao.js') ?: (defined('APP_VERSION') ? APP_VERSION : '1'); ?>
<script src="<?= htmlspecialchars($base) ?>/assets/js/producao.js?v=<?= htmlspecialchars((string) $pJsVer) ?>"></script>

<?php layoutFooter(); ?>
