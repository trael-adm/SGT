<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/layout.php';

requireLogin();
if (!hasAcesso('tab:pintura') && !hasAcesso('pin.pai') && !hasAcesso('admin')) {
    requireAcessoModulo('tab:pintura');
}

$canEdit = podeEditar('pin.pai') || isAdmin();

// Catálogo de reprovas do modal "Reprovar" (ver botão #btnAbrirRetrabalhoSerigrafia
// / abrirModalReprovacao()) — só motivos de Pintura/Serigrafia/Camada e Caldeiraria
// (setor_causador, não família — nenhuma reprova de pintura tem `local` LAB/GER,
// então o filtro por local usado em pages/producao/lista.php viria vazio aqui).
$reprovasCatalogoPintura = getDB()->query("
    SELECT id, codigo, familia, descricao, local
    FROM reprovas
    WHERE ativo = 1 AND setor_causador IN ('PINTURA', 'CALDEIRARIA')
    ORDER BY ordem ASC, LENGTH(codigo) ASC, codigo ASC
")->fetchAll();

layoutHeader('Paint Check (Múltiplas Evidências)');
?>
<!-- Dependências do Cropper.js -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>
<script src="<?= htmlspecialchars(defined('APP_URL') ? APP_URL : '') ?>/assets/js/vendor/jsQR.js"></script>

<style>
.pc-container { max-width: 1280px; margin: 0 auto; display: flex; flex-direction: column; gap: 20px; }

/* Switches (Toggles) */
.switch { position: relative; display: inline-block; width: 40px; height: 22px; flex-shrink: 0; }
.switch input { opacity: 0; width: 0; height: 0; }
.slider { position: absolute; cursor: pointer; inset: 0; background-color: #cbd5e1; transition: .25s ease; border-radius: 22px; }
.slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 3px; bottom: 3px; background-color: white; transition: .25s ease; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.15); }
input:checked + .slider { background-color: #16a34a; }
input:checked + .slider:before { transform: translateX(18px); }

/* Cards e Seções */
.pc-card { background: var(--color-surface,#fff); border: 1px solid var(--color-border,#e5e7eb); border-radius: var(--radius-lg,10px); padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); }
.pc-card-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--color-border,#e5e7eb); }
.pc-card-title { font-size: 14px; font-weight: 700; color: var(--color-text-primary,#111827); margin: 0; display: flex; align-items: center; gap: 8px; }
.pc-card-subtitle { font-size: 12px; color: var(--color-text-secondary,#6b7280); margin: 2px 0 0 0; }

.section-label { font-size: 12px; font-weight: 700; color: #1a3d2a; margin: 18px 0 10px 0; text-transform: uppercase; letter-spacing: .5px; display: flex; align-items: center; gap: 6px; }
.section-label:first-of-type { margin-top: 4px; }
.section-tag { display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; border-radius: 50%; background: #1a3d2a; color: #e8a020; font-size: 10px; font-weight: 800; }

.pc-required-badge {
    display: inline-flex; align-items: center; gap: 6px;
    background: #e6f4ea; color: #166534; border: 1px solid #bbe4c8;
    padding: 6px 12px; border-radius: 8px; font-size: 12.5px; font-weight: 700;
}
.pc-mais-opcoes-toggle {
    background: none; border: none; color: #E89B1C; font-size: 12.5px; font-weight: 700;
    cursor: pointer; padding: 6px 0; display: inline-flex; align-items: center; gap: 4px;
}
.pc-mais-opcoes-toggle:hover { text-decoration: underline; }

.filter-row { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 12px; }
.filter-item { background: #f8fafc; border: 1px solid var(--color-border,#e2e8f0); padding: 12px 14px; border-radius: 8px; display: flex; align-items: center; justify-content: space-between; gap: 10px; transition: border-color .2s; }
.filter-item:hover { border-color: #cbd5e1; }
.filter-item-info strong { display: block; font-size: 13px; font-weight: 600; color: var(--color-text-primary,#111827); margin-bottom: 2px; }
.filter-item-info span { font-size: 11px; color: var(--color-text-secondary,#6b7280); }

/* Grid de fotos */
.photo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 16px; margin-top: 14px; }
.photo-card { background: #0f172a; border: 1px solid #1e293b; border-radius: 12px; padding: 16px; position: relative; color: white; display: flex; flex-direction: column; align-items: center; justify-content: space-between; min-height: 190px; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08); transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1); }
.photo-card:hover { border-color: #334155; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(15, 23, 42, 0.15); }
.card-disabled { opacity: 0.3; pointer-events: none; filter: grayscale(100%); }

.photo-card-label { font-size: 11px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .5px; align-self: flex-start; margin-bottom: 12px; width: 100%; text-align: left; }
.photo-preview { width: 76px; height: 76px; background: #1e293b; border: 1px solid #334155; border-radius: 10px; display: flex; align-items: center; justify-content: center; margin-bottom: 14px; overflow: hidden; }
.photo-preview img { width: 100%; height: 100%; object-fit: cover; display: none; }
.photo-preview span { font-size: 26px; }
.photo-actions { display: flex; gap: 8px; width: 100%; }
.btn-photo { flex: 1; padding: 8px 10px; font-size: 12px; font-weight: 600; border-radius: 6px; border: 1px solid #334155; background: #1e293b; color: #f8fafc; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px; transition: all 0.2s; }
.btn-photo:hover { background: #334155; border-color: #475569; }
.check-badge { position: absolute; top: 12px; right: 12px; width: 22px; height: 22px; background: #16a34a; color: white; border-radius: 50%; display: none; align-items: center; justify-content: center; font-weight: bold; font-size: 12px; box-shadow: 0 2px 6px rgba(22, 163, 74, 0.4); }

/* CAMERA OVERLAY */
.camera-overlay { position: fixed; inset: 0; background: #000; display: none; flex-direction: column; z-index: 2000; }
.camera-top { padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; background: rgba(0,0,0,0.5); position: absolute; top: 0; left: 0; right: 0; z-index: 2001; }
.camera-title { color: white; font-weight: 600; font-size: 18px; text-shadow: 0 1px 3px rgba(0,0,0,0.8); }
.camera-close { background: none; border: none; color: white; font-size: 24px; cursor: pointer; line-height: 1; padding: 4px; text-shadow: 0 1px 3px rgba(0,0,0,0.8); }
.camera-video { flex: 1; width: 100%; height: 100%; object-fit: cover; }
.camera-bottom { padding: 24px; display: flex; justify-content: center; align-items: center; background: rgba(0,0,0,0.5); position: absolute; bottom: 0; left: 0; right: 0; z-index: 2001; }
.btn-capture { width: 72px; height: 72px; border-radius: 50%; background: #e8a020; border: 4px solid white; cursor: pointer; box-shadow: 0 4px 12px rgba(0,0,0,0.3); transition: transform 0.2s; display: flex; justify-content: center; align-items: center; }
.btn-capture:active { transform: scale(0.9); }
.btn-capture::after { content: ''; display: block; width: 24px; height: 24px; border-radius: 50%; background: white; }

/* Modal Customizado (Avisos) */
.custom-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); display: none; align-items: center; justify-content: center; z-index: 2000; padding: 20px; }
.custom-modal { background: #fff; border-radius: 16px; padding: 30px; width: 100%; max-width: 450px; text-align: center; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
.modal-icon { width: 60px; height: 60px; border-radius: 50%; margin: 0 auto 20px auto; display: flex; align-items: center; justify-content: center; font-size: 30px; }
.modal-icon.success { background: #dcfce7; color: #16a34a; }
.modal-icon.error { background: #fee2e2; color: #dc2626; }
.custom-modal h3 { font-size: 18px; font-weight: 700; margin-bottom: 8px; color: #111; }
.custom-modal p { font-size: 13px; color: #4b5563; margin-bottom: 22px; line-height: 1.5; }
.custom-modal button { width: 100%; padding: 11px; border-radius: 8px; font-size: 14px; font-weight: 700; cursor: pointer; border: none; }
.btn-close-modal { background: #f3f4f6; color: #374151; }
.btn-close-modal:hover { background: #e5e7eb; }

/* Modal de Recorte (Cropper) */
.crop-window { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: #000; display: none; flex-direction: column; z-index: 999999; }
.cropper-header { display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; background: #111; color: white; border-bottom: 1px solid #333; }
.cropper-title { display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: bold; text-transform: uppercase; color: #fbbf24; }
.cropper-title-sub { color: #888; font-size: 11px; margin-top: 2px; }
.cropper-close { background: none; border: none; color: white; font-size: 24px; cursor: pointer; padding: 5px; }
.cropper-body { flex: 1; position: relative; overflow: hidden; background: #000; min-height: 0; display: flex; align-items: center; justify-content: center; }
.cropper-body img { max-width: 100%; max-height: 100%; display: block; }
.cropper-footer { padding: 15px 20px; background: #111; border-top: 1px solid #333; display: flex; gap: 15px; justify-content: center; }
.btn-cropper { flex: 1; max-width: 250px; padding: 12px; border-radius: 8px; font-weight: 800; font-size: 13px; text-transform: uppercase; cursor: pointer; border: none; display: flex; align-items: center; justify-content: center; gap: 8px; }
.btn-cropper-cancel { background: #333; color: white; }
.btn-cropper-cancel:hover { background: #444; }
.btn-cropper-confirm { background: #fbbf24; color: #000; }
.btn-cropper-confirm:hover { background: #f59e0b; }

/* Passo 0: tela de leitura de etiqueta — mesmo visual de pages/retrabalho/confirmar-chegada.php */
.barcode-box { border: 2px dashed #cbd5e1; border-radius: 10px; padding: 24px; text-align: center; background: #fafafa; transition: all 0.2s ease; }
.barcode-box.is-focused { border-color: #2563eb; background: #f0fdf4; }
.barcode-input { width: 100%; max-width: 360px; height: 48px; font-size: 18px; font-weight: 700; text-align: center; font-family: monospace; letter-spacing: 2px; border: 2px solid #2563eb; border-radius: 8px; padding: 0 16px; outline: none; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15); text-transform: uppercase; }

.btn-camera-toggle { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; font-weight: 600; color: #334155; cursor: pointer; transition: all 0.15s ease; }
.btn-camera-toggle:hover { background: #f1f5f9; border-color: #94a3b8; color: #0f172a; }

/* Card de contexto da peça (Passo 1/2) — mesmo padrão de pages/retrabalho/confirmar-chegada.php,
   mantém NS/Cliente/Projeto/Descrição visíveis o tempo todo, não só de relance no Passo 0. */
.info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; }
.info-item { display: flex; flex-direction: column; min-width: 0; }
.info-label { font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px; }
.info-val { font-size: 14px; font-weight: 700; color: #0f172a; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* Modal "Reprovar" (mesmo padrão do combobox de reprovas de pages/producao/lista.php) */
.rep-search-combobox { position: relative; width: 100%; }
.rep-search-input {
    width: 100%; height: 38px; padding: 0 34px 0 12px;
    border: 1px solid var(--color-border-strong); border-radius: var(--radius-md);
    font-size: var(--font-size-base); font-family: var(--font-sans);
    background: var(--color-surface); color: var(--color-text-primary); outline: none;
}
.rep-search-input:focus { border-color: var(--color-accent); box-shadow: 0 0 0 3px rgba(232,160,32,0.15); }
.rep-search-toggle {
    position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
    background: none; border: none; padding: 4px; cursor: pointer; color: var(--color-text-muted);
    display: flex; align-items: center; justify-content: center;
}
.rep-search-toggle svg { width: 16px; height: 16px; transition: transform .2s; }
.rep-search-combobox.is-open .rep-search-toggle svg { transform: rotate(180deg); color: var(--color-text-primary); }
.rep-search-dropdown {
    position: absolute; top: calc(100% + 4px); left: 0; right: 0; max-height: 220px;
    overflow-y: auto; background: var(--color-surface); border: 1px solid var(--color-border-strong);
    border-radius: var(--radius-md); box-shadow: var(--shadow-lg); z-index: 1050; padding: 4px;
}
.rep-item { padding: 8px 10px; border-radius: var(--radius-sm); cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: var(--font-size-sm); }
.rep-item:hover, .rep-item.is-selected { background: var(--color-surface-2); }
.rep-item.is-active { background: var(--color-accent-light); color: var(--color-accent-text); }
.lst-reprova-block { border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: 12px 14px; background: var(--color-surface-2); }
.lst-reprova-head { display: flex; gap: 10px; align-items: flex-end; }
.lst-reprova-select-wrap { flex: 1; }
.lst-reprova-remove {
    background: var(--color-surface); border: 1px solid var(--color-border);
    border-radius: var(--radius-md); width: 38px; height: 38px; flex-shrink: 0;
    cursor: pointer; color: var(--color-text-muted); font-size: 18px; line-height: 1;
    display: flex; align-items: center; justify-content: center; transition: all var(--transition);
}
.lst-reprova-remove:hover { border-color: var(--color-danger); color: var(--color-danger); background: var(--color-danger-bg); }
</style>

<div class="pc-container">
    <?php if (!$canEdit): ?>
        <div style="background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:13px;display:flex;align-items:center;gap:10px;">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            <div>
                <strong>Modo Somente Leitura:</strong> Validações e envios fotográficos desativados para o seu perfil.
            </div>
        </div>
    <?php endif; ?>

    <!-- Cabeçalho Padrão SGT (título sempre visível, mesmo durante o gate de identificação) -->
    <div style="margin-bottom:6px;">
        <h1 style="font-size:var(--font-size-xl,20px);font-weight:700;margin-top:2px;">Paint Check</h1>
        <p class="text-secondary" style="font-size:13px;color:var(--color-text-secondary,#6b7280);margin-top:2px;">
            Inspeção visual e validação de evidências fotográficas por inteligência artificial
        </p>
    </div>

    <!-- Gate obrigatório: identificação por etiqueta/NS antes de liberar o resto da tela. Ao ler o QR/código,
         o sistema já sabe a concessionária e liga sozinho as evidências que ela exige (aplicarRegraIdentificada()) —
         o operador não escolhe nada. Mesmo padrão visual de pages/retrabalho/confirmar-chegada.php. -->
    <div class="pc-card" id="cardPasso0" style="max-width:680px;margin:0 auto;">
        <div class="pc-card-header">
            <div>
                <h3 class="pc-card-title">Passo 0: Identificação Rápida</h3>
                <p class="pc-card-subtitle">Use a câmera para ler o QR Code ou digite o Nº de Série.</p>
            </div>
        </div>

        <div class="barcode-box" id="barcodeBoxPasso0">
            <div id="barcodeBoxPasso0Conteudo">
                <div style="display:inline-flex;align-items:center;justify-content:center;width:52px;height:52px;border-radius:50%;background:#eff6ff;color:#2563eb;margin-bottom:12px;box-shadow:0 0 0 6px rgba(37,99,235,0.08);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:28px;height:28px;"><rect x="3" y="3" width="7" height="7" rx="1"></rect><rect x="14" y="3" width="7" height="7" rx="1"></rect><rect x="3" y="14" width="7" height="7" rx="1"></rect><line x1="14" y1="14" x2="14" y2="17"></line><line x1="14" y1="20" x2="14" y2="21"></line><line x1="17" y1="14" x2="21" y2="14"></line><line x1="17" y1="17" x2="21" y2="17"></line><line x1="17" y1="21" x2="21" y2="21"></line><line x1="21" y1="17" x2="21" y2="21"></line></svg>
                </div>
                <h3 style="font-size:16px;font-weight:700;color:#0f172a;margin-bottom:4px;">Aperte o botão de câmera para ler a peça</h3>
                <p style="font-size:13px;color:#64748b;margin-bottom:16px;line-height:1.4;">Use o botão "Usar Câmera (QR Code)" abaixo para ler o QR Code da etiqueta do transformador.</p>

                <!-- Câmera é a ação principal do Passo 0 (leitor USB é passivo, câmera é o fallback mais usado) -->
                <div style="margin-bottom:10px;">
                    <button type="button" id="btnAbrirCameraPasso0" class="btn btn-primary" style="font-size:13px;font-weight:700;display:inline-flex;align-items:center;gap:8px;padding:8px 20px;" title="Usar câmera do dispositivo para ler QR Code">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                        Usar Câmera (QR Code)
                    </button>
                </div>

                <!-- Botão pra ativar teclado manual (evita abrir o teclado do tablet sozinho) — ação secundária -->
                <div id="wrapBtnAtivarTecladoPasso0" style="margin-bottom:8px;">
                    <button type="button" class="btn-camera-toggle" id="btnAtivarTecladoPasso0" title="Digitar número de série manualmente">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px;"><rect x="2" y="4" width="20" height="16" rx="2" ry="2"></rect><line x1="6" y1="8" x2="6" y2="8"></line><line x1="10" y1="8" x2="10" y2="8"></line><line x1="14" y1="8" x2="14" y2="8"></line><line x1="18" y1="8" x2="18" y2="8"></line><line x1="6" y1="12" x2="6" y2="12"></line><line x1="10" y1="12" x2="10" y2="12"></line><line x1="14" y1="12" x2="14" y2="12"></line><line x1="18" y1="12" x2="18" y2="12"></line><line x1="7" y1="16" x2="17" y2="16"></line></svg>
                        Digitar número de série manualmente
                    </button>
                </div>

                <!-- Formulário de digitação manual (oculto por padrão) -->
                <div id="wrapInputManualPasso0" style="display:none;width:100%;max-width:380px;margin:0 auto;">
                    <form id="formPasso0" onsubmit="event.preventDefault(); identificarPorCodigo();" style="display:flex;flex-direction:column;align-items:center;gap:10px;">
                        <input type="text" id="inputPasso0" class="barcode-input" placeholder="Digite o N° de série…" autocomplete="off">
                        <div style="display:flex;gap:8px;width:100%;justify-content:center;">
                            <button type="submit" class="btn btn-primary" id="btnIdentificarPasso0" style="padding:8px 20px;font-weight:700;font-size:13px;">Identificar (Enter)</button>
                            <button type="button" class="btn btn-secondary" id="btnOcultarTecladoPasso0" style="padding:8px 14px;font-size:13px;">Fechar</button>
                        </div>
                    </form>
                </div>

                <div id="passo0Feedback" style="display:none;margin-top:14px;padding:10px 14px;border-radius:6px;font-size:13px;font-weight:600;"></div>

                <button type="button" id="btnSemEtiqueta" onclick="pularIdentificacaoPorEtiqueta()" style="margin-top:16px;background:none;border:none;color:#6b7280;font-size:12.5px;text-decoration:underline;cursor:pointer;">
                    Não tem etiqueta legível? Identificar pelas fotos de tampa e gancho
                </button>
            </div>
        </div>
    </div>

    <!-- Overlay de Câmera / QR Code do Passo 0 — mesmo padrão de pages/retrabalho/confirmar-chegada.php
         (classes .scan-overlay/.scan-stage/.viewfinder já globais em assets/css/main.css). Só abre com clique
         explícito em "Abrir Câmera" — nunca sozinho ao carregar a tela. -->
    <div class="scan-overlay" id="scanOverlayPasso0" role="dialog" aria-modal="true" aria-label="Leitor por Câmera" style="display:none;">
        <div class="scan-overlay__bar">
            <span>Escanear etiqueta / QR Code</span>
            <button type="button" class="scan-overlay__close" id="btnFecharCameraPasso0" aria-label="Fechar câmera">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="scan-stage" id="scanStagePasso0">
            <video id="scanVideoPasso0" autoplay playsinline muted></video>
            <div class="viewfinder" id="viewfinderPasso0">
                <span class="corner corner--tl"></span><span class="corner corner--tr"></span>
                <span class="corner corner--bl"></span><span class="corner corner--br"></span>
                <span class="scan-line"></span>
            </div>
            <div class="scan-stage__empty" id="scanStageEmptyPasso0">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                <p id="scanStageEmptyTextPasso0">Solicitando acesso à câmera…</p>
            </div>
        </div>
        <p class="scan-hint">Aponte a câmera para o QR Code da etiqueta</p>
        <div class="scan-overlay__error" id="overlayErrorPasso0"></div>
    </div>

    <!-- Painel principal (Passo 1 + Passo 2): só aparece depois que o Passo 0 identifica o transformador
         (ou o operador escolhe explicitamente "não tenho etiqueta") — ver aplicarRegraIdentificada()/pularIdentificacaoPorEtiqueta(). -->
    <div id="painelPrincipal" style="display:none;">
        <div class="info-grid" id="infoTransformador">
            <div class="info-item"><span class="info-label">Nº de Série</span><span class="info-val" id="infoNs">—</span></div>
            <div class="info-item"><span class="info-label">Cliente</span><span class="info-val" id="infoCliente">—</span></div>
            <div class="info-item"><span class="info-label">Grupo do Cliente</span><span class="info-val" id="infoConcessionaria">—</span></div>
            <div class="info-item"><span class="info-label">Projeto</span><span class="info-val" id="infoProjeto">—</span></div>
            <div class="info-item"><span class="info-label">Pedido</span><span class="info-val" id="infoPedido">—</span></div>
            <div class="info-item" style="flex:2;"><span class="info-label">Descrição</span><span class="info-val" id="infoDescricao" style="white-space:normal;">—</span></div>
        </div>

        <div style="display:flex;justify-content:flex-end;margin-bottom:6px;">
            <?php if ($canEdit): ?>
                <button class="btn btn-primary" id="btnValidarTudo" onclick="validarTudo()" style="background:#16a34a;color:#fff;font-weight:700;padding:9px 20px;border-radius:8px;display:inline-flex;align-items:center;gap:6px;">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                    Validar Tudo
                </button>
            <?php endif; ?>
        </div>

        <!-- Passo 1: Evidências (simplificado — Tampa/Gancho sempre ativos, universais a toda concessionária; resto ligado automaticamente pela regra identificada no Passo 0) -->
        <div class="pc-card">
            <div class="pc-card-header">
                <div>
                    <h3 class="pc-card-title">Passo 1: Evidências</h3>
                    <p class="pc-card-subtitle">Tampa e gancho são sempre fotografados. O resto é ligado automaticamente conforme a concessionária identificada.</p>
                </div>
            </div>

            <!-- Fixos: sempre ativos, sem toggle — checkboxes ocultos preservam a mesma lógica de initGrid()/toggleCard() -->
            <input type="checkbox" id="tg_tampa_serie" checked style="display:none">
            <input type="checkbox" id="tg_gancho_serie" checked style="display:none">
            <div class="pc-required-row" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:4px;">
                <span class="pc-required-badge">✓ Nº Série Tampa</span>
                <span class="pc-required-badge">✓ Nº Série Gancho</span>
                <!-- Só aparece quando a concessionária identificada também exige puncionamento
                     no tanque (regraAtual.locais_obrigatorios) — ver aplicarRegraIdentificada(). -->
                <span class="pc-required-badge" id="badgeTanqueSerie" style="display:none">✓ Nº Série Tanque</span>
            </div>

            <button type="button" id="btn-mais-opcoes" class="pc-mais-opcoes-toggle" aria-expanded="false">
                <span id="mais-opcoes-label">+ Mais opções (patrimônio, potência, elo fusível)</span>
            </button>

            <div id="pc-opcoes-extra" style="display:none;margin-top:14px;">
                <div class="filter-row">
                    <div class="filter-item">
                        <div class="filter-item-info"><strong>Nº Série Tanque</strong><span>Ligado automático se a concessionária exigir</span></div>
                        <label class="switch"><input type="checkbox" id="tg_tanque_serie" onchange="toggleCard('tanque_serie')"><span class="slider"></span></label>
                    </div>
                    <div class="filter-item">
                        <div class="filter-item-info"><strong>Patrimônio Tampa</strong><span>Código de identificação</span></div>
                        <label class="switch"><input type="checkbox" id="tg_tampa_codigo" onchange="toggleCard('tampa_codigo')"><span class="slider"></span></label>
                    </div>
                    <div class="filter-item">
                        <div class="filter-item-info"><strong>Potência Tampa</strong><span>Ex: 15kVA, 75kVA</span></div>
                        <label class="switch"><input type="checkbox" id="tg_tampa_potencia" onchange="toggleCard('tampa_potencia')"><span class="slider"></span></label>
                    </div>
                    <div class="filter-item">
                        <div class="filter-item-info"><strong>Patrimônio Tanque</strong><span>Patrimônio/tombamento</span></div>
                        <label class="switch"><input type="checkbox" id="tg_tanque_cliente" onchange="toggleCard('tanque_cliente')"><span class="slider"></span></label>
                    </div>
                    <div class="filter-item">
                        <div class="filter-item-info"><strong>Potência Tanque</strong><span>Ex: 15kVA, 75kVA</span></div>
                        <label class="switch"><input type="checkbox" id="tg_tanque_potencia" onchange="toggleCard('tanque_potencia')"><span class="slider"></span></label>
                    </div>
                    <div class="filter-item">
                        <div class="filter-item-info"><strong>Elo Fusível</strong><span>Esquema de elo aceito</span></div>
                        <label class="switch"><input type="checkbox" id="tg_elo_fusivel" onchange="toggleCard('elo_fusivel')"><span class="slider"></span></label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Passo 2: Grid de Fotos -->
        <div class="pc-card">
            <div class="pc-card-header" style="margin-bottom:0;border-bottom:none;">
                <div>
                    <h3 class="pc-card-title">Passo 2: Evidências Fotográficas</h3>
                    <p class="pc-card-subtitle">Anexe ou capture fotos para cada evidência ativa</p>
                </div>
            </div>
            <div class="photo-grid" id="gridFotos">
                <!-- Renderizado dinamicamente pelo JS -->
            </div>
        </div>
    </div>
</div>

<!-- CAMERA OVERLAY -->
<div class="camera-overlay" id="cameraOverlay">
    <div class="camera-top">
        <div class="camera-title">Tirar Foto</div>
        <button class="camera-close" onclick="closeCamera()">✕</button>
    </div>
    <video class="camera-video" id="cameraVideo" autoplay playsinline muted></video>
    <div class="camera-bottom">
        <button class="btn-capture" onclick="takePhoto()" aria-label="Capturar"></button>
    </div>
</div>

<!-- Modal Customizado de Avisos -->
<div class="custom-modal-overlay" id="avisoModal">
    <div class="custom-modal">
        <div class="modal-icon" id="modalIcon"></div>
        <h3 id="modalTitle">Título</h3>
        <p id="modalMsg">Mensagem de erro ou sucesso aparecerá aqui.</p>
        <button class="btn btn-danger btn-sm" id="btnAbrirRetrabalhoSerigrafia" style="display:none;margin:0 auto 8px;" onclick="abrirModalReprovacao()">Reprovar</button>
        <button class="btn-close-modal" id="btnSalvarValidacao" style="display:none;margin-bottom:8px;background:#16a34a;color:#fff;font-weight:700;" onclick="salvarValidacaoChecklist()">Salvar Validação</button>
        <div id="salvarValidacaoFeedback" style="display:none;font-size:12.5px;margin-bottom:8px;"></div>
        <button class="btn-close-modal" id="btnFecharModal" onclick="fecharModal()">OK, Entendi</button>
    </div>
</div>

<!-- Modal "Reprovar" — mesmo padrão visual de pages/producao/lista.php (combobox buscável,
     blocos repetíveis de reprova), só que direto na tela do Paint Check: sem navegar pra
     pages/pintura/relacao.php. Catálogo restrito a Pintura/Serigrafia/Camada + Caldeiraria
     ($reprovasCatalogoPintura, setor_causador — ver topo do arquivo). -->
<div class="modal-overlay" id="repModal" style="display:none;">
    <div class="modal" style="max-width:600px;">
        <div class="modal-header">
            <div>
                <div class="modal-title">Nova Reprovação</div>
                <div class="card-subtitle">NS <strong id="repModalNs">—</strong> · Projeto <strong id="repModalProjeto">—</strong></div>
            </div>
            <button type="button" class="modal-close" id="repModalClose">&times;</button>
        </div>

        <form id="repForm">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:14px;">
                <div id="repFormErro" style="display:none;" class="alert alert-danger"></div>

                <div id="repReprovasContainer">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                        <label class="form-label font-600" style="margin:0;text-transform:uppercase;font-size:11px;letter-spacing:.6px;">Motivos da Reprovação *</label>
                        <button type="button" class="btn btn-secondary btn-sm" id="repAddReprovaBtn">+ Adicionar outra reprova</button>
                    </div>
                    <div id="repReprovasList" style="display:flex;flex-direction:column;gap:10px;"></div>
                </div>

                <div class="form-group" style="margin:0;">
                    <label class="form-label">Observações gerais (opcional)</label>
                    <textarea id="repObs" rows="2" class="form-control" placeholder="Detalhes adicionais sobre a reprovação…"></textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="repModalCancelar">Cancelar</button>
                <button type="submit" class="btn btn-danger" id="repBtnSubmit">Confirmar Reprovação</button>
            </div>
        </form>
    </div>
</div>

<!-- Template de bloco de reprova repetível (Modal "Reprovar") -->
<template id="repReprovaTemplate">
    <div class="lst-reprova-block">
        <div class="lst-reprova-head">
            <div class="lst-reprova-select-wrap">
                <label class="form-label" style="margin-bottom:4px;">Selecione a Reprova / Não Conformidade *</label>
                <div class="rep-search-combobox">
                    <input type="text" class="rep-search-input" placeholder="Buscar código ou descrição…" autocomplete="off">
                    <input type="hidden" class="rep-hidden-id" required>
                    <button type="button" class="rep-search-toggle" aria-label="Abrir opções">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <div class="rep-search-dropdown" style="display:none;"></div>
                </div>
            </div>
            <button type="button" class="lst-reprova-remove" title="Remover esta reprova">&times;</button>
        </div>
        <div class="lst-reprova-detalhes" style="display:none;margin-top:10px;padding-top:10px;border-top:1px dashed var(--color-border);font-size:var(--font-size-sm);color:var(--color-text-secondary);">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                <div><span style="color:var(--color-text-muted);">Família:</span> <strong class="rep-info-familia font-600">—</strong></div>
                <div><span style="color:var(--color-text-muted);">Local:</span> <strong class="rep-info-local font-600">—</strong></div>
            </div>
        </div>
    </div>
</template>

<!-- Modal de Pintura/Máscara -->
<div class="crop-window" id="cropperModal">
    <div class="cropper-header">
        <div>
            <div class="cropper-title">🎯 IDENTIFICAR: <span id="cropperTargetLabel"></span></div>
            <div class="cropper-title-sub">Esfregue o dedo/mouse sobre os números para marcá-los de amarelo.</div>
        </div>
        <button class="cropper-close" onclick="fecharCropper()">✕</button>
    </div>
    
    <!-- Canvas de Pintura -->
    <div class="cropper-body" id="canvasContainer" style="background: #222; overflow: hidden; position: relative;">
        <!-- Canvas principal visível -->
        <canvas id="paintCanvas" style="cursor: crosshair; touch-action: none; max-width: 100%; max-height: 100%; object-fit: contain; margin: 0 auto; display: block;"></canvas>
        <!-- Bounding Box Visual -->
        <div id="focusBox" style="position: absolute; border: 2px dashed #fbbf24; pointer-events: none; display: none; z-index: 9999; box-shadow: 0 0 10px rgba(0,0,0,0.5);">
            <span style="position:absolute; top:-20px; left:0; color:#fbbf24; font-size:12px; font-weight:bold; white-space:nowrap; text-shadow: 1px 1px 2px #000;">ÁREA DE FOCO IA</span>
        </div>
    </div>
    
    <!-- Controles do Pincel -->
    <div style="background:#111; padding:15px 20px; display:flex; gap:15px; align-items:center; color:white; font-size:12px; border-top: 1px solid #333;">
        <label style="font-weight: bold; text-transform: uppercase;">Tamanho do Pincel:</label>
        <input type="range" id="brushSize" min="10" max="100" value="40" style="flex:1;">
    </div>

    <div class="cropper-footer">
        <button class="btn-cropper btn-cropper-cancel" onclick="resetarPintura()">LIMPAR PINTURA</button>
        <button class="btn-cropper btn-cropper-confirm" onclick="confirmarRecorte()">✓ USAR ÁREA</button>
    </div>
</div>

<!-- Cursor da Mira do Pincel -->
<div id="brushCursor" style="position:fixed; border:2px solid #fbbf24; border-radius:50%; pointer-events:none; display:none; z-index:9999999; transform: translate(-50%, -50%); box-shadow: 0 0 5px rgba(0,0,0,0.8);"></div>

<script>
// Move os modais para o final do body para não sofrerem interferência do z-index da sidebar
document.body.appendChild(document.getElementById('cropperModal'));
document.body.appendChild(document.getElementById('cameraOverlay'));
document.body.appendChild(document.getElementById('avisoModal'));
document.body.appendChild(document.getElementById('repModal'));
document.body.appendChild(document.getElementById('brushCursor'));
document.body.appendChild(document.getElementById('scanOverlayPasso0'));

const photoConfigs = [
    { id: 'tampa_serie', label: 'Nº SÉRIE DA TAMPA', local: 'tampa' },
    { id: 'tampa_codigo', label: 'PATRIMÔNIO TAMPA', local: 'tampa' },
    { id: 'tampa_potencia', label: 'POTÊNCIA DA TAMPA', local: 'tampa' },
    { id: 'tanque_serie', label: 'Nº SÉRIE TANQUE', local: 'tanque' },
    { id: 'tanque_cliente', label: 'PATRIMÔNIO TANQUE', local: 'tanque' },
    { id: 'tanque_potencia', label: 'POTÊNCIA TANQUE', local: 'tanque' },
    { id: 'gancho_serie', label: 'Nº SÉRIE GANCHO', local: 'gancho' },
    { id: 'elo_fusivel', label: 'ELO FUSÍVEL', local: 'tanque' }
];

let imagesData = {};
let currentCropId = null;
let regraAtual = null;
let faseAtual = 'identificar'; // 'identificar' (Passo 1, OCR reverso de tampa+gancho) | 'validar' (Passo 2, já sabemos a regra)
let numSerieAtual = null; // NS já identificado pelo Passo 0/Passo 1 — mandado pro backend na validação final (ver validarTudo())
let projetoAtual = ''; // cd_referencia (código do projeto) do transformador identificado — exibido no modal "Reprovar"
let idProjetoAtual = null; // id numérico de projetos (não o código) — exigido pela ação `reprovar_pintura` de api/pintura-retornos-acao.php (ver abrirModalReprovacao())
let camposConfirmadosManualmente = new Set(); // slots que o operador conferiu visualmente quando a IA não confirmou
let ultimoChecklistData = null; // resultado do último checklist (etapa 2), usado por salvarValidacaoChecklist()

// Variáveis do Canvas / Máscara
let isDrawing = false;
let originalImageObj = new Image();
let canvas = document.getElementById('paintCanvas');
let ctx = canvas.getContext('2d', { willReadFrequently: true });
let maskCanvas = document.createElement('canvas'); // Canvas invisível que guarda onde foi pintado
let maskCtx = maskCanvas.getContext('2d', { willReadFrequently: true });
let scaleRatio = 1;
let brushCursor = document.getElementById('brushCursor');
let cropMinX = Infinity, cropMaxX = -Infinity, cropMinY = Infinity, cropMaxY = -Infinity;

document.getElementById('btn-mais-opcoes')?.addEventListener('click', () => {
    const box = document.getElementById('pc-opcoes-extra');
    const label = document.getElementById('mais-opcoes-label');
    const btn = document.getElementById('btn-mais-opcoes');
    const abrindo = box.style.display === 'none';
    box.style.display = abrindo ? 'block' : 'none';
    btn.setAttribute('aria-expanded', abrindo ? 'true' : 'false');
    label.textContent = abrindo ? '− Ocultar mais opções' : '+ Mais opções (patrimônio, potência, elo fusível)';
});

// ----------------- PASSO 0: IDENTIFICAÇÃO POR ETIQUETA/NS -----------------
// Mesmo padrão de pages/retrabalho/confirmar-chegada.php + assets/js/retrabalho-chegada.js:
// leitor USB "digita" rápido no teclado sem precisar de foco num campo (buffer
// global por keydown), e a digitação manual fica atrás de um botão pra não
// abrir o teclado do tablet sozinho.

const inputPasso0 = document.getElementById('inputPasso0');
const barcodeBoxPasso0 = document.getElementById('barcodeBoxPasso0');
let passo0Resolvido = false;

function mostrarFeedbackPasso0(tipo, texto) {
    const el = document.getElementById('passo0Feedback');
    if (!el) return;
    el.style.display = 'block';
    el.textContent = texto;
    if (tipo === 'erro') {
        el.style.background = '#fef2f2'; el.style.color = '#b91c1c'; el.style.border = '1px solid #fecaca';
    } else {
        el.style.background = '#f0fdf4'; el.style.color = '#166534'; el.style.border = '1px solid #bbf7d0';
    }
}

function identificarPorCodigo(codigoForcado) {
    const codigo = (codigoForcado || inputPasso0?.value || '').trim();
    if (!codigo) return;

    fetch(window.__APP_BASE + '/api/paint-check-identificar.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ codigo })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            passo0Resolvido = true;
            const conteudo = document.getElementById('barcodeBoxPasso0Conteudo');
            if (conteudo) {
                conteudo.innerHTML = `<div style="font-size:32px;margin-bottom:8px;">✅</div>
                    <h3 style="color:#059669;font-size:16px;font-weight:700;margin-bottom:4px;">Transformador identificado</h3>
                    <p style="font-size:13px;color:#64748b;">NS <b>${data.num_serie}</b> — ${data.cliente} (${data.concessionaria.nome_grupo})</p>`;
            }
            aplicarRegraIdentificada(data);
        } else {
            mostrarFeedbackPasso0('erro', (data.error || 'Não encontrado') + ' — confira o código ou use "Não tem etiqueta legível?" abaixo.');
            if (inputPasso0) inputPasso0.select();
        }
    })
    .catch(err => mostrarFeedbackPasso0('erro', 'Erro de comunicação: ' + err.message));
}

/** Libera o painel principal sem identificação pelo Passo 0 — o operador vai fotografar tampa+gancho no Passo 1, que dispara a identificação por OCR reverso ao clicar em "Validar Tudo" (faseAtual continua 'identificar'). */
function pularIdentificacaoPorEtiqueta() {
    document.getElementById('cardPasso0').style.display = 'none';
    document.getElementById('painelPrincipal').style.display = 'block';
}

// ─── Teclado manual sob demanda ───────────────────────────────────────────
const btnAtivarTecladoPasso0 = document.getElementById('btnAtivarTecladoPasso0');
const btnOcultarTecladoPasso0 = document.getElementById('btnOcultarTecladoPasso0');
const wrapBtnAtivarTecladoPasso0 = document.getElementById('wrapBtnAtivarTecladoPasso0');
const wrapInputManualPasso0 = document.getElementById('wrapInputManualPasso0');

btnAtivarTecladoPasso0?.addEventListener('click', () => {
    wrapBtnAtivarTecladoPasso0.style.display = 'none';
    wrapInputManualPasso0.style.display = 'block';
    inputPasso0?.focus();
});

btnOcultarTecladoPasso0?.addEventListener('click', () => {
    wrapInputManualPasso0.style.display = 'none';
    wrapBtnAtivarTecladoPasso0.style.display = 'block';
    if (inputPasso0) inputPasso0.value = '';
});

inputPasso0?.addEventListener('focus', () => barcodeBoxPasso0?.classList.add('is-focused'));
inputPasso0?.addEventListener('blur', () => barcodeBoxPasso0?.classList.remove('is-focused'));

// ─── Scanner físico de código de barras USB/teclado (funciona sem foco no campo) ───
let passo0BarcodeBuffer = '';
let passo0BarcodeTimer = null;

window.addEventListener('keydown', (e) => {
    if (passo0Resolvido) return; // já identificou, não precisa mais ouvir o leitor

    if (e.key === 'Enter') {
        if (passo0BarcodeBuffer.trim().length >= 3) {
            e.preventDefault();
            identificarPorCodigo(passo0BarcodeBuffer.trim());
            passo0BarcodeBuffer = '';
            clearTimeout(passo0BarcodeTimer);
        }
        return;
    }

    // Focado num campo de texto normal (ex.: digitação manual) — deixa o navegador tratar.
    if (document.activeElement && document.activeElement.tagName === 'INPUT') return;

    if (e.key && e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey) {
        passo0BarcodeBuffer += e.key;
        clearTimeout(passo0BarcodeTimer);
        passo0BarcodeTimer = setTimeout(() => { passo0BarcodeBuffer = ''; }, 100);
    }
});

// ─── Câmera / QR Code — só abre com clique explícito em "Abrir Câmera" ────
// Mesmo padrão de assets/js/retrabalho-chegada.js (startCamera/scanFrame com
// jsQR), adaptado pra chamar identificarPorCodigo() em vez de confirmar chegada.
const btnAbrirCameraPasso0 = document.getElementById('btnAbrirCameraPasso0');
const btnFecharCameraPasso0 = document.getElementById('btnFecharCameraPasso0');
const scanOverlayPasso0 = document.getElementById('scanOverlayPasso0');
const scanStagePasso0 = document.getElementById('scanStagePasso0');
const scanStageEmptyTextPasso0 = document.getElementById('scanStageEmptyTextPasso0');
const scanVideoPasso0 = document.getElementById('scanVideoPasso0');
const overlayErrorPasso0 = document.getElementById('overlayErrorPasso0');

let passo0MediaStream = null;
const passo0ScanCanvas = document.createElement('canvas');
const passo0ScanCtx = passo0ScanCanvas.getContext('2d', { willReadFrequently: true });

function passo0IniciarCamera() {
    if (passo0MediaStream) return;
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        if (scanStageEmptyTextPasso0) scanStageEmptyTextPasso0.textContent = 'Câmera não suportada neste navegador.';
        return;
    }
    if (scanStageEmptyTextPasso0) scanStageEmptyTextPasso0.textContent = 'Solicitando acesso à câmera…';
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
        .then((stream) => {
            passo0MediaStream = stream;
            scanVideoPasso0.srcObject = stream;
            scanVideoPasso0.setAttribute('playsinline', true);
            scanVideoPasso0.play();
            scanStagePasso0?.classList.add('has-video');
            requestAnimationFrame(passo0TickCamera);
        })
        .catch(() => {
            if (scanStageEmptyTextPasso0) scanStageEmptyTextPasso0.textContent = 'Não foi possível acessar a câmera.';
            scanStagePasso0?.classList.remove('has-video');
        });
}

function passo0PararCamera() {
    if (passo0MediaStream) {
        passo0MediaStream.getTracks().forEach((t) => t.stop());
        passo0MediaStream = null;
        scanVideoPasso0.srcObject = null;
        scanStagePasso0?.classList.remove('has-video');
    }
}

function passo0TickCamera() {
    if (!passo0MediaStream) return;
    if (scanVideoPasso0.readyState === scanVideoPasso0.HAVE_ENOUGH_DATA) passo0ScanFrame();
    requestAnimationFrame(passo0TickCamera);
}

function passo0ScanFrame() {
    if (passo0Resolvido || !passo0MediaStream || typeof window.jsQR !== 'function') return;
    if (scanVideoPasso0.readyState !== scanVideoPasso0.HAVE_ENOUGH_DATA || !scanVideoPasso0.videoWidth) return;
    passo0ScanCanvas.width = scanVideoPasso0.videoWidth;
    passo0ScanCanvas.height = scanVideoPasso0.videoHeight;
    passo0ScanCtx.drawImage(scanVideoPasso0, 0, 0, passo0ScanCanvas.width, passo0ScanCanvas.height);
    let imageData;
    try {
        imageData = passo0ScanCtx.getImageData(0, 0, passo0ScanCanvas.width, passo0ScanCanvas.height);
    } catch (e) { return; }
    const resultado = window.jsQR(imageData.data, imageData.width, imageData.height, { inversionAttempts: 'dontInvert' });
    if (resultado && resultado.data) {
        passo0FecharCamera();
        identificarPorCodigo(resultado.data);
    }
}

function passo0AbrirCamera() {
    if (overlayErrorPasso0) { overlayErrorPasso0.textContent = ''; overlayErrorPasso0.style.display = 'none'; }
    if (scanOverlayPasso0) scanOverlayPasso0.style.display = 'flex';
    passo0IniciarCamera();
}

function passo0FecharCamera() {
    passo0PararCamera();
    if (scanOverlayPasso0) scanOverlayPasso0.style.display = 'none';
}

btnAbrirCameraPasso0?.addEventListener('click', passo0AbrirCamera);
btnFecharCameraPasso0?.addEventListener('click', passo0FecharCamera);

/** Aplica a regra identificada (via Passo 0 ou via OCR reverso de tampa+gancho): liga os campos extras que a etapa 2 precisa e destrava o botão principal pra fase de validação final. */
function aplicarRegraIdentificada(data) {
    regraAtual = data.regra;
    numSerieAtual = data.num_serie;
    projetoAtual = data.cd_referencia || '';
    idProjetoAtual = data.id_projeto ?? null;
    faseAtual = 'validar';
    camposConfirmadosManualmente.clear();

    // Card de contexto (Passo 1/2) — mantém NS/Cliente/Projeto/Descrição visíveis
    // durante todo o resto do fluxo, não só de relance no Passo 0.
    document.getElementById('infoNs').textContent = data.num_serie || '—';
    document.getElementById('infoCliente').textContent = data.cliente || '—';
    document.getElementById('infoConcessionaria').textContent = data.concessionaria?.nome_grupo || '—';
    document.getElementById('infoProjeto').textContent = data.cd_referencia || '—';
    document.getElementById('infoPedido').textContent = data.cd_pedido || '—';
    document.getElementById('infoDescricao').textContent = data.descricao || '—';

    // Identificação resolvida (pelo Passo 0 ou pelo OCR reverso de tampa+gancho no
    // Passo 1) — libera o painel principal já com as evidências certas ligadas, sem
    // o operador precisar escolher nada.
    document.getElementById('cardPasso0').style.display = 'none';
    document.getElementById('painelPrincipal').style.display = 'block';

    const tanqueSerieObrigatoria = regraAtual.locais_obrigatorios.includes('tanque');
    document.getElementById('tg_tanque_serie').checked = tanqueSerieObrigatoria;
    // Selo "obrigatório" do topo (Passo 1) era fixo só Tampa+Gancho — não refletia
    // quando a concessionária também exige tanque (ex.: Energisa, NS 856174).
    document.getElementById('badgeTanqueSerie').style.display = tanqueSerieObrigatoria ? 'inline-flex' : 'none';

    // Toda concessionária cadastrada tem código adicional/patrimônio documentado no
    // tanque e/ou tampa (mesmo sem regex de formato fechada ainda — a IA já valida
    // contra o VSAT via OCR, o formato só aperta a checagem quando existe) — só o
    // fallback ABNT/Particular não tem nada disso formalizado.
    const temCodigoAdicional = !data.concessionaria.fallback;
    document.getElementById('tg_tanque_cliente').checked = temCodigoAdicional && regraAtual.local_codigo_adicional.includes('tanque');
    document.getElementById('tg_tampa_codigo').checked = temCodigoAdicional && regraAtual.local_codigo_adicional.includes('tampa');

    // Potência é serigrafia universal (todas as concessionárias exigem no tanque,
    // pintura.md) — só a Energisa exige também na tampa (local_potencia).
    document.getElementById('tg_tanque_potencia').checked = regraAtual.local_potencia.includes('tanque');
    document.getElementById('tg_tampa_potencia').checked = regraAtual.local_potencia.includes('tampa');

    // Elo fusível — configurável por concessionária (regraAtual.exige_elo_fusivel).
    // Só Equatorial tem tabela pra validar o valor de verdade (pintura.md 3.1.B);
    // as demais que exigem a evidência ficam sem checagem de valor — ver
    // api/paint-check-multi.php.
    document.getElementById('tg_elo_fusivel').checked = !!regraAtual.exige_elo_fusivel;

    initGrid();

    const btn = document.getElementById('btnValidarTudo');
    if (btn && !btn.disabled) btn.innerText = 'Enviar Evidências Completas';
}

function initGrid() {
    const grid = document.getElementById('gridFotos');
    grid.innerHTML = '';

    photoConfigs.forEach(conf => {
        const isActive = document.getElementById('tg_' + conf.id).checked;
        const displayStyle = isActive ? 'flex' : 'none';

        const cardHtml = `
            <div class="photo-card" id="card_${conf.id}" style="display: ${displayStyle};">
                <div class="check-badge" id="badge_${conf.id}">✓</div>
                <div class="photo-card-label">${conf.label}</div>
                <div class="photo-preview">
                    <span id="icon_${conf.id}">🖼️</span>
                    <img id="img_${conf.id}">
                </div>
                <div class="photo-actions">
                    <input type="file" id="file_${conf.id}" accept="image/*" style="display:none" onchange="processImage(event, '${conf.id}')">
                    <button type="button" class="btn-photo" onclick="document.getElementById('file_${conf.id}').click()">↑ Anexar</button>
                    <button type="button" class="btn-photo" onclick="openCamera('${conf.id}')">📷 Câmera</button>
                </div>
            </div>
        `;
        grid.innerHTML += cardHtml;
    });
}

function toggleCard(id) {
    const isActive = document.getElementById('tg_' + id).checked;
    const card = document.getElementById('card_' + id);
    if (isActive) {
        card.style.display = 'flex';
    } else {
        card.style.display = 'none';
        delete imagesData[id];
        document.getElementById('img_' + id).style.display = 'none';
        document.getElementById('img_' + id).src = '';
        document.getElementById('icon_' + id).style.display = 'block';
        document.getElementById('badge_' + id).style.display = 'none';
    }
}

// Quando o usuário seleciona a foto
function processImage(event, id) {
    const file = event.target.files[0];
    if (!file) return;
    event.target.value = '';

    const reader = new FileReader();
    reader.onload = (evt) => {
        abrirCropper(evt.target.result, id);
    };
    reader.readAsDataURL(file);
}

// ----------------- IN-BROWSER CAMERA LOGIC -----------------
let cameraStream = null;

async function openCamera(id) {
    currentCropId = id;
    const overlay = document.getElementById('cameraOverlay');
    const video = document.getElementById('cameraVideo');
    overlay.style.display = 'flex';
    
    try {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            throw new Error('Acesso à câmera bloqueado. Verifique se o tablet está acessando o sistema via conexão segura (HTTPS).');
        }

        cameraStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment', width: { ideal: 4096 }, height: { ideal: 2160 } }
        });
        video.srcObject = cameraStream;
    } catch (err) {
        alert('Erro ao ligar câmera: ' + err.message);
        closeCamera();
    }
}

function closeCamera() {
    document.getElementById('cameraOverlay').style.display = 'none';
    if (cameraStream) {
        cameraStream.getTracks().forEach(track => track.stop());
        cameraStream = null;
    }
}

function takePhoto() {
    if (!cameraStream) return;
    const video = document.getElementById('cameraVideo');
    
    // Verifica se o vídeo já tem dimensões
    if (!video.videoWidth) {
        alert('Câmera ainda não está pronta.');
        return;
    }
    
    // Cria um canvas temporário na resolução MÁXIMA capturada pelo vídeo
    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    const context = canvas.getContext('2d');
    context.drawImage(video, 0, 0, canvas.width, canvas.height);
    
    // Usa PNG (formato sem perdas) em vez de JPEG para não dar dupla compressão na hora do crop!
    const base64Data = canvas.toDataURL('image/png'); 
    
    closeCamera(); // Encerra a câmera
    abrirCropper(base64Data, currentCropId); // Vai para o pincel
}

// ----------------- CROPPER / MASK LOGIC -----------------

function abrirCropper(base64Image, id) {
    currentCropId = id;
    const conf = photoConfigs.find(c => c.id === id);
    document.getElementById('cropperTargetLabel').innerText = conf.label;

    document.getElementById('cropperModal').style.display = 'flex';

    originalImageObj.onload = function() {
        // Redimensionar para no máximo 1500px para não travar o celular
        let targetW = originalImageObj.width;
        let targetH = originalImageObj.height;
        const maxDim = 1500;
        
        if (targetW > maxDim || targetH > maxDim) {
            const ratio = Math.min(maxDim / targetW, maxDim / targetH);
            targetW *= ratio;
            targetH *= ratio;
        }

        canvas.width = targetW;
        canvas.height = targetH;
        maskCanvas.width = targetW;
        maskCanvas.height = targetH;

        resetarPintura();
    }
    originalImageObj.src = base64Image;
}

function resetarPintura() {
    // Desenha a imagem original no canvas visível
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(originalImageObj, 0, 0, canvas.width, canvas.height);
    
    // Limpa a máscara (transparente)
    maskCtx.clearRect(0, 0, maskCanvas.width, maskCanvas.height);
    
    // Reseta limites
    cropMinX = Infinity; cropMaxX = -Infinity; cropMinY = Infinity; cropMaxY = -Infinity;
    updateFocusBox();
}

function updateFocusBox() {
    const focusBox = document.getElementById('focusBox');
    if (cropMinX > cropMaxX) {
        focusBox.style.display = 'none';
        return;
    }
    focusBox.style.display = 'block';
    
    const rect = canvas.getBoundingClientRect();
    const containerRect = document.getElementById('canvasContainer').getBoundingClientRect();
    const scaleX = rect.width / canvas.width;
    const scaleY = rect.height / canvas.height;
    
    const padding = 20;
    const finalMinX = Math.max(0, cropMinX - padding);
    const finalMinY = Math.max(0, cropMinY - padding);
    const finalMaxX = Math.min(canvas.width, cropMaxX + padding);
    const finalMaxY = Math.min(canvas.height, cropMaxY + padding);
    
    const offsetX = rect.left - containerRect.left;
    const offsetY = rect.top - containerRect.top;
    
    focusBox.style.left = (offsetX + finalMinX * scaleX) + 'px';
    focusBox.style.top = (offsetY + finalMinY * scaleY) + 'px';
    focusBox.style.width = ((finalMaxX - finalMinX) * scaleX) + 'px';
    focusBox.style.height = ((finalMaxY - finalMinY) * scaleY) + 'px';
}

function fecharCropper() {
    document.getElementById('cropperModal').style.display = 'none';
    currentCropId = null;
}

// Lógica de Desenho Livre
function startDrawing(e) {
    isDrawing = true;
    draw(e);
}
function stopDrawing() {
    isDrawing = false;
    ctx.beginPath();
    maskCtx.beginPath();
}

function updateCursor(e) {
    let clientX = e.clientX || (e.touches && e.touches[0].clientX);
    let clientY = e.clientY || (e.touches && e.touches[0].clientY);
    if (!clientX || !clientY) return;

    brushCursor.style.display = 'block';
    brushCursor.style.left = clientX + 'px';
    brushCursor.style.top = clientY + 'px';

    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;
    
    const brushSize = document.getElementById('brushSize').value * (Math.max(scaleX, scaleY) / 1.5);
    const visualSize = brushSize / scaleX; 
    
    brushCursor.style.width = visualSize + 'px';
    brushCursor.style.height = visualSize + 'px';
}

function draw(e) {
    updateCursor(e);
    if (!isDrawing) return;
    e.preventDefault();

    const rect = canvas.getBoundingClientRect();
    let clientX = e.clientX || (e.touches && e.touches[0].clientX);
    let clientY = e.clientY || (e.touches && e.touches[0].clientY);
    
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;
    
    const x = (clientX - rect.left) * scaleX;
    const y = (clientY - rect.top) * scaleY;

    const brushSize = document.getElementById('brushSize').value * (Math.max(scaleX, scaleY) / 1.5);

    // 1. Desenha no Canvas Visível (Marca-texto muito transparente para não cobrir o número)
    ctx.lineWidth = brushSize;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = 'rgba(255, 255, 0, 0.05)';
    ctx.lineTo(x, y);
    ctx.stroke();
    
    // 2. Desenha no Mask Canvas (Branco opaco, para servir de máscara)
    maskCtx.lineWidth = brushSize;
    maskCtx.lineCap = 'round';
    maskCtx.lineJoin = 'round';
    maskCtx.strokeStyle = 'rgba(255, 255, 255, 1)';
    maskCtx.lineTo(x, y);
    maskCtx.stroke();
    
    // Atualiza limites dinâmicos da Caixa Delimitadora
    const radius = brushSize / 2;
    if (x - radius < cropMinX) cropMinX = Math.max(0, x - radius);
    if (x + radius > cropMaxX) cropMaxX = Math.min(canvas.width, x + radius);
    if (y - radius < cropMinY) cropMinY = Math.max(0, y - radius);
    if (y + radius > cropMaxY) cropMaxY = Math.min(canvas.height, y + radius);
    updateFocusBox();
    
    ctx.beginPath();
    ctx.moveTo(x, y);
    maskCtx.beginPath();
    maskCtx.moveTo(x, y);
}

// Eventos de Mouse
canvas.addEventListener('mousedown', startDrawing);
canvas.addEventListener('mousemove', (e) => { updateCursor(e); draw(e); });
canvas.addEventListener('mouseup', stopDrawing);
canvas.addEventListener('mouseout', () => { stopDrawing(); brushCursor.style.display = 'none'; });

// Eventos de Touch
canvas.addEventListener('touchstart', startDrawing, {passive: false});
canvas.addEventListener('touchmove', (e) => { updateCursor(e); draw(e); }, {passive: false});
canvas.addEventListener('touchend', () => { stopDrawing(); brushCursor.style.display = 'none'; });

function confirmarRecorte() {
    if (!currentCropId) return;

    // Se o maskCanvas estiver 100% vazio, avisa o usuário
    const maskData = maskCtx.getImageData(0, 0, maskCanvas.width, maskCanvas.height).data;
    let hasPainted = false;
    let minX = maskCanvas.width, minY = maskCanvas.height, maxX = 0, maxY = 0;

    // Encontra o Bounding Box (Caixa Delimitadora) do que foi pintado
    for (let y = 0; y < maskCanvas.height; y++) {
        for (let x = 0; x < maskCanvas.width; x++) {
            let alpha = maskData[(y * maskCanvas.width + x) * 4 + 3];
            if (alpha > 0) {
                hasPainted = true;
                if (x < minX) minX = x;
                if (x > maxX) maxX = x;
                if (y < minY) minY = y;
                if (y > maxY) maxY = y;
            }
        }
    }

    if (!hasPainted) {
        alert("Pinte os números de amarelo antes de continuar!");
        return;
    }

    // Adiciona uma margem de segurança ao redor da pintura para não cortar as bordas dos números
    const padding = 20;
    minX = Math.max(0, minX - padding);
    minY = Math.max(0, minY - padding);
    maxX = Math.min(canvas.width, maxX + padding);
    maxY = Math.min(canvas.height, maxY + padding);

    const cropWidth = maxX - minX;
    const cropHeight = maxY - minY;

    // Calcula a proporção real da imagem original x canvas reduzido
    const scaleX_img = originalImageObj.width / canvas.width;
    const scaleY_img = originalImageObj.height / canvas.height;
    
    const sx = minX * scaleX_img;
    const sy = minY * scaleY_img;
    const sWidth = cropWidth * scaleX_img;
    const sHeight = cropHeight * scaleY_img;

    // Cria o Canvas Final recortado
    const finalCanvas = document.createElement('canvas');
    finalCanvas.width = sWidth; // Mantém a resolução alta
    finalCanvas.height = sHeight;
    const fCtx = finalCanvas.getContext('2d');

    // Desenha apenas a área recortada da imagem ORIGINAL (preserva contraste e resolução alta)
    fCtx.drawImage(originalImageObj, sx, sy, sWidth, sHeight, 0, 0, sWidth, sHeight);

    // Extrai o base64
    const base64 = finalCanvas.toDataURL('image/jpeg', 0.9);
    
    // Salva na memória do formulário
    imagesData[currentCropId] = base64;

    // Atualiza o Card
    document.getElementById('icon_' + currentCropId).style.display = 'none';
    document.getElementById('img_' + currentCropId).src = base64;
    document.getElementById('img_' + currentCropId).style.display = 'block';
    document.getElementById('badge_' + currentCropId).style.display = 'flex';

    fecharCropper();
}

// ----------------- FINAL: ENVIO P/ API -----------------

function mostrarModal(tipo, titulo, mensagem) {
    const modal = document.getElementById('avisoModal');
    const icon = document.getElementById('modalIcon');
    const title = document.getElementById('modalTitle');
    const msg = document.getElementById('modalMsg');

    // Reset do botão "Salvar Validação" — só a chamada que monta o checklist
    // (ver validarTudo()) volta a exibi-lo, com ultimoChecklistData preenchido.
    const btnSalvar = document.getElementById('btnSalvarValidacao');
    btnSalvar.style.display = 'none';
    btnSalvar.disabled = false;
    btnSalvar.innerText = 'Salvar Validação';
    const feedbackSalvar = document.getElementById('salvarValidacaoFeedback');
    feedbackSalvar.style.display = 'none';
    feedbackSalvar.innerHTML = '';

    // Reset do "OK, Entendi" — o checklist da etapa 2 (ver validarTudo()) o
    // esconde, já que ali só fazem sentido "Salvar Validação"/"Reprovar".
    const btnFechar = document.getElementById('btnFecharModal');
    btnFechar.style.display = '';
    btnFechar.innerText = 'OK, Entendi';

    if(tipo === 'sucesso') {
        icon.className = 'modal-icon success';
        icon.innerHTML = '✓';
    } else {
        icon.className = 'modal-icon error';
        icon.innerHTML = '✕';
    }

    title.innerText = titulo;
    msg.innerHTML = mensagem;
    modal.style.display = 'flex';
}

// Bloco extra do resultado: concessionária identificada + regras específicas ✓/✗
// (mesmo <ul> que api/paint-check-multi.php já monta pros erros de leitura —
// reaproveita o vocabulário visual, não é um componente novo).
function montarBlocoConcessionaria(data) {
    if (!data || !data.concessionaria) return '';

    let html = '<hr style="margin:14px 0;border:none;border-top:1px solid #e5e7eb;">';
    html += `<div style="text-align:left;font-size:13px;"><b>Grupo do Cliente:</b> ${data.concessionaria.nome_grupo}</div>`;

    if (data.concessionaria.fallback) {
        html += '<div style="text-align:left;font-size:12.5px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:6px 10px;margin-top:8px;">⚠️ Regra específica não cadastrada para este cliente — usando o padrão ABNT (Particular).</div>';
    }

    if (Array.isArray(data.regras_checadas) && data.regras_checadas.length > 0) {
        html += '<ul style="text-align:left;margin-top:10px;padding-left:20px;">';
        data.regras_checadas.forEach(chk => {
            const cor = chk.ok ? '#16a34a' : '#dc2626';
            const marca = chk.ok ? '✓' : '✗';
            html += `<li style="margin-bottom:4px;"><span style="color:${cor};font-weight:700;">${marca}</span> ${chk.label}`;
            if (!chk.ok && chk.detalhe) {
                html += `<br><span style="font-size:12px;color:#6b7280;">${chk.detalhe}</span>`;
            }
            html += '</li>';
        });
        html += '</ul>';
    }

    return html;
}

// Checklist por campo da validação final (etapa 2) — cada campo que a IA já
// confirmou aparece só com o ✓; campo que a IA não confirmou vem com a
// leitura bruta e um checkbox pro operador confirmar visualmente (foto legível
// e correta) ou aceitar como ilegível mesmo assim. Não bloqueia nada — é só
// registro visual, a tela não salva o resultado em nenhuma tabela hoje.
function montarChecklistCampos(data) {
    if (!data || !Array.isArray(data.campos)) return '';

    let html = '<hr style="margin:14px 0;border:none;border-top:1px solid #e5e7eb;">';
    html += `<div style="text-align:left;font-size:13px;"><b>Grupo do Cliente:</b> ${data.concessionaria.nome_grupo}</div>`;

    if (data.concessionaria.fallback) {
        html += '<div style="text-align:left;font-size:12.5px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:6px 10px;margin-top:8px;">⚠️ Regra específica não cadastrada para este cliente — usando o padrão ABNT (Particular).</div>';
    }

    let temDivergencia = false;

    html += '<ul style="text-align:left;margin-top:10px;padding-left:0;list-style:none;">';
    data.campos.forEach(campo => {
        if (campo.ok) {
            html += `<li style="margin-bottom:6px;"><span style="color:#16a34a;font-weight:700;">✓</span> ${campo.label}</li>`;
            return;
        }

        const leituraTxt = (campo.leituras && campo.leituras.length) ? campo.leituras.join(', ') : '(vazio/ilegível)';
        const jaConfirmado = camposConfirmadosManualmente.has(campo.slot);
        const ehDivergencia = campo.status === 'divergente';
        if (ehDivergencia) temDivergencia = true;

        // Campo com $detalhe preenchido (ex.: NS da tampa bateu, mas falta o ano
        // junto; código lido mas fora do formato esperado) — o valor em si já foi
        // lido certo, o problema é outro, explicado em $detalhe (ver
        // api/paint-check-multi.php). Não é "diverge" (não há dois valores
        // diferentes pra comparar) nem "ilegível" no sentido literal — mostra o
        // motivo direto, sem esconder atrás do <details>.
        const ehApenasDetalhe = !ehDivergencia && !!campo.detalhe;

        // Divergência (leu com clareza algo quase igual, mas diferente) é tratada
        // como possível erro REAL de gravação — visual mais forte que o caso
        // "não consegui ler". Card enxuto por padrão (só o essencial pro operador
        // decidir rápido); leitura bruta fica atrás de um <details>.
        const estilo = ehDivergencia
            ? 'background:#fff1f2;border:2px solid #e11d48;'
            : 'background:#fffbeb;border:1px solid #fde68a;';
        const tag = ehDivergencia
            ? `<span style="color:#be123c;">⚠️ ${campo.label} — diverge</span>`
            : ehApenasDetalhe
                ? `<span>${campo.label} — confira</span>`
                : `<span>${campo.label} — ilegível</span>`;
        const comparacaoHtml = ehDivergencia
            ? `Esperado: <b>${campo.esperado}</b> &nbsp;|&nbsp; Lido: <b>${campo.leitura_proxima}</b>`
            : ehApenasDetalhe
                ? campo.detalhe
                : `Esperado: <b>${campo.esperado || '—'}</b>`;
        const rotuloConfirmacao = campo.esperado ? `Confirmo: é ${campo.esperado}` : 'Confirmo, conferi visualmente';

        // Já mostrado como motivo principal acima (ehApenasDetalhe) — não duplica aqui.
        const detalheHtml = (campo.detalhe && !ehApenasDetalhe) ? `<div>${campo.detalhe}</div>` : '';
        const detalhesTecnicos = `
            <details style="margin-top:2px;">
                <summary style="cursor:pointer;font-size:11.5px;color:#9ca3af;">detalhes</summary>
                <div style="font-size:11.5px;color:#9ca3af;">Leitura bruta: ${leituraTxt}${detalheHtml}</div>
            </details>`;

        html += `
            <li style="margin-bottom:6px;${estilo}border-radius:6px;padding:6px 8px;">
                <label style="display:flex;align-items:flex-start;gap:6px;cursor:pointer;font-size:12.5px;">
                    <input type="checkbox" ${jaConfirmado ? 'checked' : ''} onchange="toggleConfirmacaoManual('${campo.slot}', this.checked)" style="margin-top:2px;">
                    <span>
                        <b>${tag}</b><br>
                        ${comparacaoHtml}<br>
                        ${rotuloConfirmacao}
                        ${detalhesTecnicos}
                    </span>
                </label>
            </li>`;
    });
    html += '</ul>';

    // Reprovar: dispara o mesmo atalho de retrabalho já usado no resto do Paint
    // Check — só faz sentido quando há divergência de verdade (leitura clara,
    // mas diferente do esperado), não pra campo simplesmente ilegível/foto ruim.
    if (temDivergencia) {
        exibirBotaoRetrabalhoSerigrafia(data);
    }

    return html;
}

/** Registra a confirmação/desconfirmação manual de um campo que a IA não bateu — reflete o estado do checkbox e alimenta salvarValidacaoChecklist(). */
function toggleConfirmacaoManual(slot, checked) {
    if (checked) {
        camposConfirmadosManualmente.add(slot);
    } else {
        camposConfirmadosManualmente.delete(slot);
    }
}

/** Persiste o checklist da última validação (auditoria ISO + dataset de fine-tuning do OCR — ver api/paint-check-salvar-validacao.php): cada campo já tem o recorte em imagesData, só falta mandar junto com o resultado que a IA já calculou. */
function salvarValidacaoChecklist() {
    if (!ultimoChecklistData) return;

    const btn = document.getElementById('btnSalvarValidacao');
    const feedback = document.getElementById('salvarValidacaoFeedback');

    // Trava só na hora do clique (não desabilita o botão o tempo todo, ver
    // sessão de design): sobrou campo divergente/ilegível sem confirmação
    // manual e o operador não reprovou — não deixa salvar assim.
    const pendente = ultimoChecklistData.campos.find(campo => !campo.ok && !camposConfirmadosManualmente.has(campo.slot));
    if (pendente) {
        feedback.style.color = '#b91c1c';
        feedback.innerHTML = `Confirme "${pendente.label}" (marque o quadrinho) ou reprove a peça antes de salvar.`;
        feedback.style.display = 'block';
        return;
    }

    btn.disabled = true;
    btn.innerText = 'Salvando…';
    feedback.style.display = 'none';

    const campos = ultimoChecklistData.campos.map(campo => ({
        slot: campo.slot,
        label: campo.label,
        status: campo.status,
        ok: campo.ok,
        esperado: campo.esperado,
        leitura_proxima: campo.leitura_proxima,
        distancia: campo.distancia,
        leituras: campo.leituras,
        detalhe: campo.detalhe,
        confirmado_manualmente: camposConfirmadosManualmente.has(campo.slot),
        foto_recorte: imagesData[campo.slot] || null,
    }));

    const payload = {
        num_serie: ultimoChecklistData.num_serie,
        cliente: ultimoChecklistData.cliente,
        nome_grupo: ultimoChecklistData.concessionaria.nome_grupo,
        fallback: ultimoChecklistData.concessionaria.fallback,
        campos,
    };

    fetch(window.__APP_BASE + '/api/paint-check-salvar-validacao.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(res => {
        if (res.success) {
            feedback.style.color = '#166534';
            feedback.innerHTML = '✓ Validação registrada.';
            feedback.style.display = 'block';
            btn.style.display = 'none';
            // Salvo: mostra a confirmação por um instante e recarrega a tela —
            // volta pro Passo 0 limpo, pronto pra identificar o próximo
            // transformador (recarregar é mais seguro que zerar campo a campo:
            // garante câmera/leitor/canvas de recorte também resetados).
            setTimeout(() => window.location.reload(), 900);
        } else {
            feedback.style.color = '#b91c1c';
            feedback.innerHTML = res.error || 'Não foi possível salvar.';
            feedback.style.display = 'block';
            btn.disabled = false;
            btn.innerText = 'Salvar Validação';
        }
    })
    .catch(err => {
        feedback.style.color = '#b91c1c';
        feedback.innerHTML = 'Erro de comunicação: ' + err.message;
        feedback.style.display = 'block';
        btn.disabled = false;
        btn.innerText = 'Salvar Validação';
    });
}

function fecharModal() {
    document.getElementById('avisoModal').style.display = 'none';
}

// Transformador identificado numa validação que falhou nas regras específicas
// da concessionária (não no "nenhum transformador bateu") — só nesse caso dá
// pra oferecer o atalho de reprovação, porque só aí o sistema sabe de qual
// transformador está falando. Guarda o objeto inteiro (não só o NS) porque o
// modal de reprovação (abrirModalReprovacao()) precisa do id_projeto também.
let ultimoAlvoReprovacao = null;

function exibirBotaoRetrabalhoSerigrafia(dados) {
    ultimoAlvoReprovacao = (dados && dados.num_serie) ? dados : null;
    const btn = document.getElementById('btnAbrirRetrabalhoSerigrafia');
    if (btn) btn.style.display = ultimoAlvoReprovacao ? 'block' : 'none';
}

// ----------------- MODAL "REPROVAR" -----------------
// Mesmo padrão visual/de combobox de pages/producao/lista.php, adaptado pra
// rodar direto nesta tela (sem navegar pra pages/pintura/relacao.php). Fala
// com um backend próprio e isolado (api/pintura-retornos-acao.php, ação
// `reprovar_pintura`) — não com api/retrabalho-acao.php: a peça reprovada
// pelo Paint Check entra numa fila de retorno da estação PIN, com a mesma
// lógica de LAB/IQF mas em código totalmente separado (ver
// pages/pintura/retornos.php). Ao confirmar, além de gravar o retrabalho,
// também salva um registro em paint_check_validacoes (status='reprovado')
// pra aparecer no histórico do
// Paint Check (ver api/paint-check-salvar-validacao.php).

const REPROVAS_PINTURA = <?= json_encode($reprovasCatalogoPintura, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const LOCAL_LABEL_REP = { IQF: 'IQF — Inspeção final', LAB: 'LAB — Laboratório', RET: 'RET — Retrabalho', GER: 'GER — Geral' };

function escapeHtmlRep(str) {
    return (str || '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]));
}

function removeAccentsRep(str) {
    return (str || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

const repModal = document.getElementById('repModal');
const repForm = document.getElementById('repForm');
const repFormErro = document.getElementById('repFormErro');
const repBtnSubmit = document.getElementById('repBtnSubmit');
const repReprovasList = document.getElementById('repReprovasList');
const repAddReprovaBtn = document.getElementById('repAddReprovaBtn');
const repReprovaTemplate = document.getElementById('repReprovaTemplate');

function repMostraErro(msg) {
    repFormErro.textContent = msg;
    repFormErro.style.display = 'block';
}

function repLimpaErro() {
    repFormErro.textContent = '';
    repFormErro.style.display = 'none';
}

function repPreencherDetalhes(block, idReprova) {
    const r = REPROVAS_PINTURA.filter(x => String(x.id) === String(idReprova))[0];
    const infoFam = block.querySelector('.rep-info-familia');
    const infoLoc = block.querySelector('.rep-info-local');
    const detWrap = block.querySelector('.lst-reprova-detalhes');
    if (infoFam) infoFam.textContent = r ? (r.familia || '—') : '—';
    if (infoLoc) infoLoc.textContent = r ? (LOCAL_LABEL_REP[r.local] || r.local || '—') : '—';
    if (detWrap) detWrap.style.display = r ? 'block' : 'none';
}

function repInitCombobox(block) {
    const wrap = block.querySelector('.rep-search-combobox');
    if (!wrap) return;
    const input = wrap.querySelector('.rep-search-input');
    const hidden = wrap.querySelector('.rep-hidden-id');
    const toggleBtn = wrap.querySelector('.rep-search-toggle');
    const dropdown = wrap.querySelector('.rep-search-dropdown');
    let activeIdx = -1;
    let currentFiltered = [];

    function renderList(query) {
        const qNorm = removeAccentsRep(query);
        currentFiltered = REPROVAS_PINTURA.filter(r => {
            if (!qNorm) return true;
            return removeAccentsRep(r.codigo).indexOf(qNorm) !== -1
                || removeAccentsRep(r.descricao).indexOf(qNorm) !== -1
                || removeAccentsRep(r.familia).indexOf(qNorm) !== -1;
        });

        if (!currentFiltered.length) {
            dropdown.innerHTML = '<div style="padding:12px;text-align:center;color:#9ca3af;font-size:12px;">Nenhuma reprovação encontrada</div>';
            activeIdx = -1;
            return;
        }

        dropdown.innerHTML = currentFiltered.map((r, idx) => {
            const isSel = String(hidden.value) === String(r.id);
            const isAct = idx === activeIdx;
            return `<div class="rep-item${isSel ? ' is-selected' : ''}${isAct ? ' is-active' : ''}" data-id="${r.id}">
                <div style="display:flex;align-items:center;gap:8px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <span style="font-family:monospace;font-weight:700;color:#111827;background:#e5e7eb;padding:2px 6px;border-radius:4px;font-size:11px;flex-shrink:0;">${escapeHtmlRep(r.codigo)}</span>
                    <span style="font-weight:500;color:#374151;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtmlRep(r.descricao)}</span>
                </div>
                ${r.familia ? `<span style="font-size:10px;font-weight:600;padding:2px 6px;border-radius:9999px;background:#f0fdf4;color:#16a34a;white-space:nowrap;flex-shrink:0;">${escapeHtmlRep(r.familia)}</span>` : ''}
            </div>`;
        }).join('');
    }

    function openDropdown() {
        document.querySelectorAll('.rep-search-combobox.is-open').forEach(other => {
            if (other !== wrap) { other.classList.remove('is-open'); other.querySelector('.rep-search-dropdown').style.display = 'none'; }
        });
        wrap.classList.add('is-open');
        dropdown.style.display = 'block';
        activeIdx = -1;
        renderList(input.value.indexOf(' — ') !== -1 ? '' : input.value);
    }

    function closeDropdown() {
        wrap.classList.remove('is-open');
        dropdown.style.display = 'none';
        activeIdx = -1;
        if (hidden.value) {
            const sel = REPROVAS_PINTURA.filter(r => String(r.id) === String(hidden.value))[0];
            if (sel) input.value = sel.codigo + ' — ' + sel.descricao;
        } else {
            input.value = '';
        }
    }

    function selectItem(r) {
        hidden.value = r.id;
        input.value = r.codigo + ' — ' + r.descricao;
        closeDropdown();
        repPreencherDetalhes(block, r.id);
    }

    input.addEventListener('focus', () => { openDropdown(); input.select(); });
    input.addEventListener('input', () => {
        if (!wrap.classList.contains('is-open')) { wrap.classList.add('is-open'); dropdown.style.display = 'block'; }
        hidden.value = '';
        repPreencherDetalhes(block, null);
        activeIdx = 0;
        renderList(input.value);
    });
    input.addEventListener('keydown', e => {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (!wrap.classList.contains('is-open')) { openDropdown(); return; }
            if (currentFiltered.length) { activeIdx = (activeIdx + 1) % currentFiltered.length; renderList(input.value); }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (!wrap.classList.contains('is-open')) { openDropdown(); return; }
            if (currentFiltered.length) { activeIdx = (activeIdx - 1 + currentFiltered.length) % currentFiltered.length; renderList(input.value); }
        } else if (e.key === 'Enter') {
            if (wrap.classList.contains('is-open') && currentFiltered.length) {
                e.preventDefault();
                selectItem(activeIdx >= 0 ? currentFiltered[activeIdx] : currentFiltered[0]);
            }
        } else if (e.key === 'Escape') {
            closeDropdown();
        }
    });
    toggleBtn.addEventListener('click', e => {
        e.preventDefault(); e.stopPropagation();
        if (wrap.classList.contains('is-open')) closeDropdown(); else { input.focus(); openDropdown(); }
    });
    dropdown.addEventListener('mousedown', e => {
        const itemEl = e.target.closest('.rep-item');
        if (!itemEl) return;
        const chosen = REPROVAS_PINTURA.filter(r => String(r.id) === String(itemEl.dataset.id))[0];
        if (chosen) selectItem(chosen);
    });
}

document.addEventListener('click', e => {
    document.querySelectorAll('.rep-search-combobox.is-open').forEach(wrap => {
        if (wrap.contains(e.target)) return;
        wrap.classList.remove('is-open');
        wrap.querySelector('.rep-search-dropdown').style.display = 'none';
        const hidden = wrap.querySelector('.rep-hidden-id');
        const input = wrap.querySelector('.rep-search-input');
        if (hidden.value) {
            const sel = REPROVAS_PINTURA.filter(r => String(r.id) === String(hidden.value))[0];
            if (sel) input.value = sel.codigo + ' — ' + sel.descricao;
        } else {
            input.value = '';
        }
    });
});

function repAtualizarBotoesRemover() {
    const blocos = repReprovasList.querySelectorAll('.lst-reprova-block');
    blocos.forEach(b => { b.querySelector('.lst-reprova-remove').style.display = blocos.length > 1 ? 'flex' : 'none'; });
}

function repAddReprovaBlock() {
    const frag = repReprovaTemplate.content.cloneNode(true);
    const block = frag.querySelector('.lst-reprova-block');
    repInitCombobox(block);
    block.querySelector('.lst-reprova-remove').addEventListener('click', () => {
        if (repReprovasList.querySelectorAll('.lst-reprova-block').length <= 1) return;
        block.remove();
        repAtualizarBotoesRemover();
    });
    repReprovasList.appendChild(frag);
    repAtualizarBotoesRemover();
}

repAddReprovaBtn.addEventListener('click', () => repAddReprovaBlock());

function repFecharModal() { repModal.style.display = 'none'; }
document.getElementById('repModalClose').addEventListener('click', repFecharModal);
document.getElementById('repModalCancelar').addEventListener('click', repFecharModal);
repModal.addEventListener('click', e => { if (e.target === repModal) repFecharModal(); });

/** Abre o modal "Reprovar" pro transformador identificado em ultimoAlvoReprovacao (ver exibirBotaoRetrabalhoSerigrafia()). */
function abrirModalReprovacao() {
    if (!ultimoAlvoReprovacao) return;

    // id_projeto não encontrado (VSAT e `projetos` são catálogos diferentes —
    // caso raro, ver Q15 da sessão de design): não dá pra gravar o retrabalho
    // sem ele, orienta contatar o administrador em vez de travar sem explicação.
    if (!ultimoAlvoReprovacao.id_projeto) {
        mostrarModal('erro', 'Projeto não encontrado', 'Este transformador (NS ' + escapeHtmlRep(ultimoAlvoReprovacao.num_serie) + ') não tem projeto cadastrado no SGT — situação rara. Contate o administrador do sistema pra reprovar esta peça.');
        return;
    }

    repForm.reset();
    repLimpaErro();
    repReprovasList.innerHTML = '';
    repAddReprovaBlock();

    document.getElementById('repModalNs').textContent = ultimoAlvoReprovacao.num_serie || '—';
    document.getElementById('repModalProjeto').textContent = projetoAtual || '—';

    repModal.style.display = 'flex';
}

repForm.addEventListener('submit', e => {
    e.preventDefault();
    repLimpaErro();

    const idsReprova = [];
    repReprovasList.querySelectorAll('.rep-hidden-id').forEach(inp => { if (inp.value) idsReprova.push(inp.value); });

    if (!idsReprova.length) {
        repMostraErro('Selecione pelo menos um motivo de reprovação.');
        return;
    }

    repBtnSubmit.disabled = true;
    repBtnSubmit.textContent = 'Reprovando…';

    const params = new URLSearchParams();
    params.append('acao', 'reprovar_pintura');
    params.append('id_projeto', ultimoAlvoReprovacao.id_projeto);
    params.append('ns_transformador', ultimoAlvoReprovacao.num_serie);
    params.append('observacoes', document.getElementById('repObs').value);
    idsReprova.forEach(id => params.append('id_reprova[]', id));

    fetch(window.__APP_BASE + '/api/pintura-retornos-acao.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
    .then(r => r.json().catch(() => ({ sucesso: false, erro: 'Resposta inválida do servidor.' })))
    .then(res => {
        if (!res || !res.sucesso) {
            repMostraErro((res && res.erro) || 'Erro ao registrar a reprovação.');
            repBtnSubmit.disabled = false;
            repBtnSubmit.textContent = 'Confirmar Reprovação';
            return;
        }

        // Retrabalho gravado — agora registra no histórico do Paint Check
        // (status='reprovado'), aproveitando o checklist já lido se existir
        // (ver ultimoChecklistData, pode não haver nenhum se reprovou antes
        // de qualquer campo ser validado).
        const campos = (ultimoChecklistData && Array.isArray(ultimoChecklistData.campos))
            ? ultimoChecklistData.campos.map(campo => ({
                slot: campo.slot, label: campo.label, status: campo.status, ok: campo.ok,
                esperado: campo.esperado, leitura_proxima: campo.leitura_proxima, distancia: campo.distancia,
                leituras: campo.leituras, detalhe: campo.detalhe,
                confirmado_manualmente: camposConfirmadosManualmente.has(campo.slot),
                foto_recorte: imagesData[campo.slot] || null,
            }))
            : [];

        fetch(window.__APP_BASE + '/api/paint-check-salvar-validacao.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                num_serie: ultimoAlvoReprovacao.num_serie,
                cliente: ultimoAlvoReprovacao.cliente || '',
                nome_grupo: ultimoAlvoReprovacao.concessionaria?.nome_grupo || '',
                fallback: ultimoAlvoReprovacao.concessionaria?.fallback || false,
                status: 'reprovado',
                campos,
            })
        }).finally(() => {
            repFecharModal();
            mostrarModal('sucesso', 'Reprovação Registrada', 'NS ' + escapeHtmlRep(ultimoAlvoReprovacao.num_serie) + ' foi encaminhado pro Retrabalho.');
            document.getElementById('btnFecharModal').style.display = 'none';
            setTimeout(() => window.location.reload(), 1400);
        });
    })
    .catch(err => {
        repMostraErro('Falha de conexão: ' + err.message);
        repBtnSubmit.disabled = false;
        repBtnSubmit.textContent = 'Confirmar Reprovação';
    });
});

function validarTudo() {
    exibirBotaoRetrabalhoSerigrafia(null); // evita ficar com o atalho de uma tentativa anterior enquanto processa esta
    const ativos = photoConfigs.filter(conf => document.getElementById('tg_' + conf.id).checked);
    const pendentes = ativos.filter(conf => !imagesData[conf.id]);

    if (pendentes.length > 0) {
        const nomes = pendentes.map(p => p.label).join(', ');
        mostrarModal('erro', 'Faltam Evidências', 'Você precisa recortar e anexar as fotos ativas:<br><br><b>' + nomes + '</b>');
        return;
    }

    if (ativos.length === 0) {
        mostrarModal('erro', 'Nenhuma Seleção', 'Você deve ativar pelo menos um campo para validar.');
        return;
    }

    const btn = document.getElementById('btnValidarTudo');
    const txtOriginal = btn.innerText;
    btn.disabled = true;
    btn.innerText = 'Processando IA...';

    const payload = {};
    ativos.forEach(conf => { payload[conf.id] = imagesData[conf.id]; });
    if (faseAtual === 'identificar') {
        payload._modo = 'identificar';
    } else if (numSerieAtual) {
        // Etapa 2: já sabemos o transformador (Passo 0 ou OCR reverso do Passo 1) —
        // o backend valida cada campo contra ESSE NS específico, sem precisar buscar
        // candidato de novo, e devolve um checklist por campo (ver montarChecklistCampos()).
        payload._num_serie_identificado = numSerieAtual;
    }

    fetch(window.__APP_BASE + '/api/paint-check-multi.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
    })
    .then(async res => {
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            throw new Error('Resposta inválida: ' + text.substring(0, 100));
        }
    })
    .then(data => {
        if (data.success && faseAtual === 'identificar') {
            // Passo 1 (fallback do Passo 0): achou o transformador via OCR reverso de
            // tampa+gancho — agora liga os campos extras da etapa 2, sem modal final ainda.
            mostrarFeedbackPasso0('sucesso', `Identificado: NS ${data.num_serie} — ${data.cliente} (${data.concessionaria.nome_grupo})`);
            aplicarRegraIdentificada(data);
        } else if (data.success && Array.isArray(data.campos)) {
            // Etapa 2 concluída: checklist por campo (não é mais tudo-ou-nada) —
            // campos que a IA não confirmou ficam com checkbox pro operador conferir
            // visualmente (ver montarChecklistCampos()).
            exibirBotaoRetrabalhoSerigrafia(null);
            const todosOk = data.campos.every(c => c.ok);
            mostrarModal(
                todosOk ? 'sucesso' : 'erro',
                todosOk ? 'Validação Concluída!' : 'Confira os campos não identificados',
                `Nº de Série: <b>${data.num_serie}</b><br>Cliente: ${data.cliente}` + montarChecklistCampos(data)
            );
            // Guarda o resultado pra "Salvar Validação" poder mandar pro backend
            // junto com os recortes já em imagesData (ver salvarValidacaoChecklist()).
            ultimoChecklistData = data;
            document.getElementById('btnSalvarValidacao').style.display = 'block';
            // Nesta tela só fazem sentido "Salvar Validação" (sempre) e "Reprovar"
            // (só quando há divergência — ver montarChecklistCampos()); "OK, Entendi"
            // some daqui e volta como "Fechar" só depois que a validação for salva
            // (ver salvarValidacaoChecklist()), senão o modal fica sem saída.
            document.getElementById('btnFecharModal').style.display = 'none';
        } else if (data.success) {
            exibirBotaoRetrabalhoSerigrafia(null);
            mostrarModal('sucesso', 'Validação Concluída!', `As evidências foram verificadas com sucesso e <b>pertencem ao mesmo transformador</b>!<br><br>Nº de Série Confirmado: <b>${data.num_serie}</b><br>Cliente: ${data.cliente}` + montarBlocoConcessionaria(data));
        } else {
            // Só oferece o atalho de retrabalho quando o transformador foi identificado
            // (falhou numa regra específica da concessionária) — se nenhum candidato bateu,
            // não há NS pra vincular a um retrabalho.
            exibirBotaoRetrabalhoSerigrafia(data.num_serie ? data : null);
            mostrarModal('erro', 'Divergência Encontrada', (data.error || '') + montarBlocoConcessionaria(data));
        }
    })
    .catch(err => {
        mostrarModal('erro', 'Erro de Comunicação', err.message);
    })
    .finally(() => {
        btn.disabled = false;
        // Depois de identificar com sucesso (faseAtual vira 'validar' em aplicarRegraIdentificada()),
        // o botão passa a refletir a etapa 2, mesmo com o rótulo original ("Validar Tudo")
        // tendo sido perdido pelo innerText = 'Processando IA...' logo acima.
        btn.innerText = (faseAtual === 'validar') ? 'Enviar Evidências Completas' : txtOriginal;
    });
}

initGrid();
</script>

<?php layoutFooter(); ?>
