<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireAcessoModulo('retrabalho');

$pdo  = getDB();
$base = defined('APP_URL') ? APP_URL : '';

$id     = (int) ($_GET['id'] ?? 0);
$origem = trim((string) ($_GET['origem'] ?? 'retrabalho'));
$canEdit = ($origem === 'pintura') 
    ? (podeEditar('pin.ret') || isAdmin()) 
    : (($origem === 'painel') ? (podeEditar('ret.pan') || isAdmin()) : (podeEditar('ret.rel') || isAdmin()));
$voltarUrl = ($origem === 'pintura') 
    ? $base . '/pages/pintura/relacao.php' 
    : $base . '/pages/retrabalho/relacao.php';

$origemNome = ($origem === 'pintura') ? 'Pintura' : 'Retrabalho';
$destinoUrl = $base . '/pages/retrabalho/detalhe.php?id=' . $id . '&origem=' . urlencode($origem);

if (!$canEdit) {
    header('Location: ' . $destinoUrl);
    exit;
}

$stmt = $pdo->prepare("
    SELECT r.id, r.id_projeto, r.ns_transformador, r.data_chegada, r.data_inicio,
           pr.codigo AS projeto_codigo, pr.descricao AS projeto_descricao,
           ped.numero AS pedido_numero,
           rep.codigo AS reprova_codigo, rep.descricao AS reprova_descricao
    FROM retrabalhos r
    JOIN projetos pr  ON pr.id  = r.id_projeto
    JOIN pedidos ped  ON ped.id = pr.id_pedido
    LEFT JOIN reprovas rep ON rep.id = r.id_reprova
    WHERE r.id = ? AND r.deleted_at IS NULL
");
$stmt->execute([$id]);
$registro = $stmt->fetch();

// Se o início já foi registrado anteriormente, redireciona direto para a tela de triagem
if ($registro && $registro['data_inicio'] !== null) {
    header('Location: ' . $destinoUrl);
    exit;
}

// Se a chegada ainda não foi confirmada, redireciona para a confirmação de chegada
if ($registro && $registro['data_chegada'] === null) {
    header('Location: ' . $base . '/pages/retrabalho/confirmar-chegada.php?id=' . $id . '&origem=' . urlencode($origem));
    exit;
}

$pageTitle = 'Iniciar Triagem — ' . $origemNome;
require_once __DIR__ . '/../../includes/layout.php';
layoutHeader($pageTitle);

if (!$registro) {
    ?>
    <div class="card" style="max-width:600px;margin:32px auto;text-align:center;padding:32px;">
        <div style="font-size:36px;margin-bottom:12px;">⚠️</div>
        <h2 style="font-size:18px;font-weight:700;color:#0f172a;margin-bottom:8px;">
            Registro não encontrado
        </h2>
        <p style="font-size:13px;color:#64748b;margin-bottom:20px;">
            O transformador solicitado não existe ou foi excluído.
        </p>
        <a href="<?= htmlspecialchars($voltarUrl) ?>" class="btn btn-secondary">
            &larr; Voltar para a Relação de <?= htmlspecialchars($origemNome) ?>
        </a>
    </div>
    <?php
    layoutFooter();
    exit;
}
?>

<style>
    .triagem-leitura-container {
        max-width: 680px;
        margin: 20px auto;
    }
    .triagem-leitura-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
        overflow: hidden;
    }
    .triagem-leitura-header {
        padding: 16px 20px;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }
    .btn-camera-toggle {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        background: #ffffff;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
        color: #334155;
        cursor: pointer;
        transition: all 0.15s ease;
    }
    .btn-camera-toggle:hover {
        background: #f1f5f9;
        border-color: #94a3b8;
        color: #0f172a;
    }
    .info-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 12px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 12px 16px;
        margin-bottom: 20px;
    }
    .info-item {
        display: flex;
        flex-direction: column;
    }
    .info-label {
        font-size: 11px;
        font-weight: 600;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 2px;
    }
    .info-val {
        font-size: 14px;
        font-weight: 700;
        color: #0f172a;
    }
    .barcode-box {
        border: 2px dashed #cbd5e1;
        border-radius: 10px;
        padding: 24px;
        text-align: center;
        background: #fafafa;
        transition: all 0.2s ease;
    }
    .barcode-box.is-focused {
        border-color: #2563eb;
        background: #f0fdf4;
    }
    .barcode-input {
        width: 100%;
        max-width: 360px;
        height: 48px;
        font-size: 18px;
        font-weight: 700;
        text-align: center;
        font-family: monospace;
        letter-spacing: 2px;
        border: 2px solid #2563eb;
        border-radius: 8px;
        padding: 0 16px;
        outline: none;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        text-transform: uppercase;
    }
</style>

<div class="triagem-leitura-container">
    <!-- Card Principal de Leitura Intermediária de Triagem -->
    <div class="triagem-leitura-card">
        <div class="triagem-leitura-header">
            <div>
                <span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">Módulo <?= htmlspecialchars($origemNome) ?></span>
                <h1 style="font-size:16px;font-weight:700;color:#0f172a;margin:2px 0 0;">Leitura de Início da Triagem</h1>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                <button type="button" id="btnAbrirCamera" class="btn-camera-toggle" title="Usar câmera do dispositivo para ler QR Code">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px;"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    Usar Câmera (QR Code)
                </button>
                <a href="<?= htmlspecialchars($voltarUrl) ?>" class="btn-icon" title="Voltar" style="width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:6px;border:1px solid #e2e8f0;color:#64748b;text-decoration:none;">
                    &times;
                </a>
            </div>
        </div>

        <div style="padding: 20px;">
            <!-- Grid de Informações da Peça Esperada -->
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Transformador Esperado</span>
                    <span class="info-val" style="color:#2563eb;font-family:monospace;font-size:16px;">
                        <?= htmlspecialchars($registro['ns_transformador']) ?>
                    </span>
                </div>
                <div class="info-item">
                    <span class="info-label">Projeto</span>
                    <span class="info-val"><?= htmlspecialchars($registro['projeto_codigo'] ?? '—') ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Pedido</span>
                    <span class="info-val"><?= htmlspecialchars($registro['pedido_numero'] ?? '—') ?></span>
                </div>
            </div>

            <!-- Caixa do Leitor de Código de Barras -->
            <div class="barcode-box" id="barcodeBox">
                <div style="display:inline-flex;align-items:center;justify-content:center;width:52px;height:52px;border-radius:50%;background:#eff6ff;color:#2563eb;margin-bottom:12px;box-shadow:0 0 0 6px rgba(37,99,235,0.08);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:28px;height:28px;"><rect x="2" y="4" width="20" height="16" rx="2" ry="2"></rect><line x1="6" y1="8" x2="6" y2="16"></line><line x1="10" y1="8" x2="10" y2="16"></line><line x1="14" y1="8" x2="14" y2="16"></line><line x1="18" y1="8" x2="18" y2="16"></line></svg>
                </div>
                <h3 style="font-size:16px;font-weight:700;color:#0f172a;margin-bottom:4px;">
                    Aguardando leitura do leitor de código de barras
                </h3>
                <p style="font-size:13px;color:#64748b;margin-bottom:16px;line-height:1.4;">
                    Bipe a etiqueta do transformador <strong><?= htmlspecialchars($registro['ns_transformador']) ?></strong> diretamente com o leitor físico.
                </p>

                <!-- Botão para ativar teclado manual (evita abrir teclado do tablet sozinho) -->
                <div id="wrapBtnAtivarTeclado" style="margin-bottom:8px;">
                    <button type="button" class="btn btn-secondary" id="btnAtivarTeclado" style="font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:8px;padding:8px 16px;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;"><rect x="2" y="4" width="20" height="16" rx="2" ry="2"></rect><line x1="6" y1="8" x2="6" y2="8"></line><line x1="10" y1="8" x2="10" y2="8"></line><line x1="14" y1="8" x2="14" y2="8"></line><line x1="18" y1="8" x2="18" y2="8"></line><line x1="6" y1="12" x2="6" y2="12"></line><line x1="10" y1="12" x2="10" y2="12"></line><line x1="14" y1="12" x2="14" y2="12"></line><line x1="18" y1="12" x2="18" y2="12"></line><line x1="7" y1="16" x2="17" y2="16"></line></svg>
                        Digitar número de série manualmente
                    </button>
                </div>

                <!-- Formulário de digitação manual (oculto por padrão) -->
                <div id="wrapInputManual" style="display:none;width:100%;max-width:380px;margin:0 auto;">
                    <form id="formInicioBarcode" onsubmit="event.preventDefault(); submeterInicioManual();" style="display:flex;flex-direction:column;align-items:center;gap:10px;">
                        <input type="text" 
                               id="inputBarcodeNs" 
                               class="barcode-input" 
                               placeholder="Digite o N° de série..." 
                               autocomplete="off" 
                               value="">
                        
                        <div style="display:flex;gap:8px;width:100%;justify-content:center;">
                            <button type="submit" class="btn btn-primary" id="btnConfirmarInicio" style="padding:8px 20px;font-weight:700;font-size:13px;">
                                Confirmar Início (Enter)
                            </button>
                            <button type="button" class="btn btn-secondary" id="btnOcultarTeclado" style="padding:8px 14px;font-size:13px;">
                                Fechar
                            </button>
                        </div>
                    </form>
                </div>

                <div id="inicioMsgErro" style="display:none;margin-top:14px;padding:10px 14px;border-radius:6px;background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;font-size:13px;font-weight:600;"></div>
            </div>
        </div>
    </div>
</div>

<!-- Overlay de Câmera / QR Code (Abre apenas se o usuário clicar no botão superior) -->
<div class="scan-overlay" id="scanOverlay" role="dialog" aria-modal="true" aria-label="Leitor por Câmera" style="display:none;">
    <div class="scan-overlay__bar">
        <span>
            Escanear QR Code de Início — <strong><?= htmlspecialchars($registro['ns_transformador']) ?></strong>
        </span>
        <button type="button" class="scan-overlay__close" id="btnFecharCamera" aria-label="Fechar câmera">
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

    <div style="padding:16px;text-align:center;">
        <button type="button" class="btn btn-secondary" id="btnVoltarBarcode">
            &larr; Voltar ao Leitor de Código de Barras
        </button>
    </div>
</div>

<div id="liveRegion" aria-live="polite" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;"></div>

<script>
    window.RETRABALHO_API = <?= json_encode($base . '/api/retrabalho-acao.php') ?>;
    window.RETRABALHO_INICIO_ID = <?= json_encode($registro['id']) ?>;
    window.RETRABALHO_INICIO_ID_PROJETO = <?= json_encode($registro['id_projeto']) ?>;
    window.RETRABALHO_INICIO_NS = <?= json_encode($registro['ns_transformador']) ?>;
    window.RETRABALHO_INICIO_VOLTAR = <?= json_encode($voltarUrl) ?>;
    window.RETRABALHO_INICIO_DESTINO = <?= json_encode($destinoUrl) ?>;
</script>
<script src="<?= htmlspecialchars($base) ?>/assets/js/vendor/jsQR.js"></script>
<?php $riJsVer = @filemtime(__DIR__ . '/../../assets/js/retrabalho-iniciar-triagem.js') ?: (defined('APP_VERSION') ? APP_VERSION : '1'); ?>
<script src="<?= htmlspecialchars($base) ?>/assets/js/retrabalho-iniciar-triagem.js?v=<?= htmlspecialchars((string) $riJsVer) ?>"></script>

<?php layoutFooter(); ?>
