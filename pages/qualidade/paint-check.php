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

layoutHeader('Paint Check (Múltiplas Evidências)');
?>
<!-- Dependências do Cropper.js -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>

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

    <!-- Cabeçalho Padrão SGT -->
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:6px;">
        <div>
            <h1 style="font-size:var(--font-size-xl,20px);font-weight:700;margin-top:2px;">Paint Check</h1>
            <p class="text-secondary" style="font-size:13px;color:var(--color-text-secondary,#6b7280);margin-top:2px;">
                Inspeção visual e validação de evidências fotográficas por inteligência artificial
            </p>
        </div>
        <?php if ($canEdit): ?>
            <button class="btn btn-primary" id="btnValidarTudo" onclick="validarTudo()" style="background:#16a34a;color:#fff;font-weight:700;padding:9px 20px;border-radius:8px;display:inline-flex;align-items:center;gap:6px;">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                Validar Tudo
            </button>
        <?php endif; ?>
    </div>

    <!-- Passo 1: Configurações de Captura -->
    <div class="pc-card">
        <div class="pc-card-header">
            <div>
                <h3 class="pc-card-title">Passo 1: Selecionar Evidências</h3>
                <p class="pc-card-subtitle">Escolha quais componentes serão submetidos à validação da IA</p>
            </div>
            <span style="font-size:11px;font-weight:600;color:var(--color-text-muted,#9aa3b8);background:#f8fafc;border:1px solid #e2e8f0;padding:4px 10px;border-radius:6px;">Desative para ignorar na IA</span>
        </div>

        <div class="section-label"><span class="section-tag">1</span> Tampa (Primeiro)</div>
        <div class="filter-row">
            <div class="filter-item">
                <div class="filter-item-info"><strong>Nº Série Tampa</strong><span>Série gravada na tampa</span></div>
                <label class="switch"><input type="checkbox" id="tg_tampa_serie" checked onchange="toggleCard('tampa_serie')"><span class="slider"></span></label>
            </div>
            <div class="filter-item">
                <div class="filter-item-info"><strong>Código Tampa (Opc)</strong><span>Código de identificação</span></div>
                <label class="switch"><input type="checkbox" id="tg_tampa_codigo" onchange="toggleCard('tampa_codigo')"><span class="slider"></span></label>
            </div>
            <div class="filter-item">
                <div class="filter-item-info"><strong>Potência Tampa (Opc)</strong><span>Ex: 15kVA, 75kVA</span></div>
                <label class="switch"><input type="checkbox" id="tg_tampa_potencia" onchange="toggleCard('tampa_potencia')"><span class="slider"></span></label>
            </div>
        </div>

        <div class="section-label"><span class="section-tag">2</span> Tanque (Segundo)</div>
        <div class="filter-row">
            <div class="filter-item">
                <div class="filter-item-info"><strong>Nº Série Tanque (Opc)</strong><span>Pintura no Tanque</span></div>
                <label class="switch"><input type="checkbox" id="tg_tanque_serie" onchange="toggleCard('tanque_serie')"><span class="slider"></span></label>
            </div>
            <div class="filter-item">
                <div class="filter-item-info"><strong>Série Cliente (Opc)</strong><span>Série patrimonial</span></div>
                <label class="switch"><input type="checkbox" id="tg_tanque_cliente" onchange="toggleCard('tanque_cliente')"><span class="slider"></span></label>
            </div>
            <div class="filter-item">
                <div class="filter-item-info"><strong>Potência Tanque</strong><span>Ex: 15kVA, 75kVA</span></div>
                <label class="switch"><input type="checkbox" id="tg_tanque_potencia" checked onchange="toggleCard('tanque_potencia')"><span class="slider"></span></label>
            </div>
        </div>

        <div class="section-label"><span class="section-tag">3</span> Acessórios (Último)</div>
        <div class="filter-row">
            <div class="filter-item">
                <div class="filter-item-info"><strong>Nº Série Gancho</strong><span>Série gravada no gancho</span></div>
                <label class="switch"><input type="checkbox" id="tg_gancho_serie" checked onchange="toggleCard('gancho_serie')"><span class="slider"></span></label>
            </div>
            <div class="filter-item">
                <div class="filter-item-info"><strong>Elo Fusível (Opc)</strong><span>Esquema de elo aceito</span></div>
                <label class="switch"><input type="checkbox" id="tg_elo_fusivel" onchange="toggleCard('elo_fusivel')"><span class="slider"></span></label>
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
        <button class="btn-close-modal" onclick="fecharModal()">OK, Entendi</button>
    </div>
</div>

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
document.body.appendChild(document.getElementById('brushCursor'));

const photoConfigs = [
    { id: 'tampa_serie', label: 'Nº SÉRIE DA TAMPA' },
    { id: 'tampa_codigo', label: 'CÓDIGO DA TAMPA (OPC)' },
    { id: 'tampa_potencia', label: 'POTÊNCIA DA TAMPA (OPC)' },
    { id: 'tanque_serie', label: 'Nº SÉRIE TANQUE (OPC)' },
    { id: 'tanque_cliente', label: 'SÉRIE CLIENTE TANQUE (OPC)' },
    { id: 'tanque_potencia', label: 'POTÊNCIA TANQUE' },
    { id: 'gancho_serie', label: 'Nº SÉRIE GANCHO' },
    { id: 'elo_fusivel', label: 'ELO FUSÍVEL (OPC)' }
];

let imagesData = {};
let currentCropId = null;

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

function fecharModal() {
    document.getElementById('avisoModal').style.display = 'none';
}

function validarTudo() {
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
    ativos.forEach(conf => {
        payload[conf.id] = imagesData[conf.id];
    });

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
        if(data.success) {
            mostrarModal('sucesso', 'Validação Concluída!', `As evidências foram verificadas com sucesso e <b>pertencem ao mesmo transformador</b>!<br><br>Nº de Série Confirmado: <b>${data.num_serie}</b><br>Cliente: ${data.cliente}`);
        } else {
            mostrarModal('erro', 'Divergência Encontrada', data.error);
        }
    })
    .catch(err => {
        mostrarModal('erro', 'Erro de Comunicação', err.message);
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerText = txtOriginal;
    });
}

initGrid();
</script>

<?php layoutFooter(); ?>
