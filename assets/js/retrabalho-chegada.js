(function () {
    'use strict';

    var API    = window.RETRABALHO_API || '';
    var ID     = window.RETRABALHO_CHEGADA_ID || 0;
    var VOLTAR = window.RETRABALHO_CHEGADA_VOLTAR || '';

    function postAcao(payload) {
        var body = new URLSearchParams(payload);
        return fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (r) { return r.json().catch(function () { return { sucesso: false, erro: 'Resposta inválida do servidor.' }; }); });
    }

    var scanStage      = document.getElementById('scanStage');
    var stageEmptyText = document.getElementById('scanStageEmptyText');
    var video          = document.getElementById('scanVideo');
    var viewfinder      = document.getElementById('viewfinder');
    var scanHint        = document.getElementById('scanHint');
    var overlayError    = document.getElementById('overlayError');
    var manualInput     = document.getElementById('manualNs');
    var manualBtn       = document.getElementById('manualBtn');
    var showManualBtn   = document.getElementById('showManualBtn');
    var manualInputRow  = document.getElementById('manualInputRow');
    var uploadBtn        = document.getElementById('uploadImageBtn');
    var imageInput       = document.getElementById('qrImageInput');
    var liveRegion       = document.getElementById('liveRegion');

    if (!scanStage || !video) return; // página não carregou os elementos esperados (ex.: chegada já confirmada)

    var mediaStream = null;
    var scanCanvas  = document.createElement('canvas');
    var scanCtx     = scanCanvas.getContext('2d', { willReadFrequently: true });
    var locked      = false;

    function announce(msg) { if (liveRegion) liveRegion.textContent = msg; }

    function showOverlayError(msg) {
        overlayError.style.display = 'block';
        overlayError.textContent = msg;
    }
    function clearOverlayError() {
        overlayError.style.display = 'none';
        overlayError.textContent = '';
    }

    function flashViewfinder(ok) {
        viewfinder.classList.remove('is-success', 'is-error');
        viewfinder.classList.add(ok ? 'is-success' : 'is-error');
        if (navigator.vibrate) navigator.vibrate(ok ? 90 : [60, 40, 60]);
    }

    function startCamera() {
        if (mediaStream) return;
        stageEmptyText.textContent = 'Solicitando acesso à câmera…';
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
            .then(function (stream) {
                mediaStream = stream;
                video.srcObject = stream;
                video.setAttribute('playsinline', true);
                video.play();
                scanStage.classList.add('has-video');
                requestAnimationFrame(tick);
            })
            .catch(function () {
                stageEmptyText.textContent = 'Não foi possível acessar a câmera.';
                scanStage.classList.remove('has-video');
            });
    }

    function stopCamera() {
        if (mediaStream) {
            mediaStream.getTracks().forEach(function (t) { t.stop(); });
            mediaStream = null;
            video.srcObject = null;
            scanStage.classList.remove('has-video');
        }
    }

    function tick() {
        if (!mediaStream) return;
        if (video.readyState === video.HAVE_ENOUGH_DATA) scanFrame();
        requestAnimationFrame(tick);
    }

    function scanFrame() {
        if (locked || !mediaStream || typeof window.jsQR !== 'function') return;
        if (video.readyState !== video.HAVE_ENOUGH_DATA || !video.videoWidth) return;
        scanCanvas.width = video.videoWidth;
        scanCanvas.height = video.videoHeight;
        scanCtx.drawImage(video, 0, 0, scanCanvas.width, scanCanvas.height);
        var imageData;
        try { imageData = scanCtx.getImageData(0, 0, scanCanvas.width, scanCanvas.height); } catch (e) { return; }
        var resultado = window.jsQR(imageData.data, imageData.width, imageData.height, { inversionAttempts: 'dontInvert' });
        if (resultado && resultado.data) handleCodigo(resultado.data);
    }

    function decodeImageFile(file) {
        var reader = new FileReader();
        reader.onload = function (e) {
            var img = new Image();
            img.onload = function () {
                var c = document.createElement('canvas');
                c.width = img.width;
                c.height = img.height;
                var ctx = c.getContext('2d');
                ctx.drawImage(img, 0, 0);
                var idata = ctx.getImageData(0, 0, c.width, c.height);
                var resultado = typeof window.jsQR === 'function' ? window.jsQR(idata.data, idata.width, idata.height) : null;
                if (resultado && resultado.data) {
                    handleCodigo(resultado.data);
                } else {
                    showOverlayError('Nenhum QR Code encontrado na imagem.');
                    announce('Nenhum QR Code encontrado na imagem.');
                    setTimeout(clearOverlayError, 3000);
                }
            };
            img.src = e.target.result;
        };
        reader.readAsDataURL(file);
    }

    // ─── Confirmar chegada: o próprio scan já é a tentativa de confirmação ─────
    function handleCodigo(raw) {
        if (locked) return;
        var codigo = (raw || '').trim();
        if (!codigo) return;
        locked = true;
        clearOverlayError();

        var body = new URLSearchParams({
            acao: 'confirmar_chegada',
            id: ID,
            codigo: codigo
        });

        postAcao(body).then(function (res) {
            if (res && res.sucesso) {
                flashViewfinder(true);
                stopCamera();
                scanHint.textContent = 'Chegada confirmada! Voltando…';
                announce('Chegada confirmada.');
                setTimeout(function () { if (VOLTAR) window.location.href = VOLTAR; }, 900);
                return;
            }
            flashViewfinder(false);
            showOverlayError((res && res.erro) || 'Falha ao confirmar chegada.');
            announce((res && res.erro) || 'Falha ao confirmar chegada.');
            setTimeout(function () {
                locked = false;
                viewfinder.classList.remove('is-success', 'is-error');
            }, 1500);
        }).catch(function () {
            flashViewfinder(false);
            showOverlayError('Falha de conexão ao confirmar chegada.');
            setTimeout(function () {
                locked = false;
                viewfinder.classList.remove('is-success', 'is-error');
            }, 1500);
        });
    }

    startCamera();

    if (showManualBtn && manualInputRow) {
        showManualBtn.addEventListener('click', function() {
            showManualBtn.style.display = 'none';
            manualInputRow.style.display = 'flex';
            if (manualInput) manualInput.focus();
        });
    }

    if (manualBtn && manualInput) {
        manualBtn.addEventListener('click', function () { if (manualInput.value.trim()) handleCodigo(manualInput.value); });
        manualInput.addEventListener('keydown', function (e) { if (e.key === 'Enter' && manualInput.value.trim()) handleCodigo(manualInput.value); });
    }

    if (uploadBtn && imageInput) {
        uploadBtn.addEventListener('click', function () { imageInput.click(); });
        imageInput.addEventListener('change', function () {
            var file = imageInput.files && imageInput.files[0];
            imageInput.value = '';
            if (file) decodeImageFile(file);
        });
    }
}());
