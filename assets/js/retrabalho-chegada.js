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
    var uploadBtn        = document.getElementById('uploadImageBtn');
    var imageInput       = document.getElementById('qrImageInput');
    var liveRegion       = document.getElementById('liveRegion');

    if (!scanStage || !video) return; // página não carregou os elementos esperados (ex.: chegada já confirmada)

    var mediaStream = null;
    var detectTimer = null;
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
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            stageEmptyText.textContent = 'Este navegador não expõe câmera — use a leitura manual abaixo.';
            return;
        }
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
            .then(function (stream) {
                mediaStream = stream;
                video.srcObject = stream;
                scanStage.classList.add('has-video');
                if (typeof window.jsQR === 'function') {
                    detectTimer = setInterval(scanFrame, 350);
                } else {
                    scanHint.textContent = 'Câmera ativa, mas a biblioteca de leitura não carregou (verifique sua conexão) — use a leitura manual abaixo.';
                }
            })
            .catch(function (err) {
                var msg = 'Não foi possível acessar a câmera.';
                if (err && err.name === 'NotAllowedError') msg = 'Permissão de câmera negada — use a leitura manual abaixo.';
                if (err && err.name === 'NotFoundError') msg = 'Nenhuma câmera disponível neste dispositivo — use a leitura manual abaixo.';
                stageEmptyText.textContent = msg;
            });
    }

    function stopCamera() {
        if (detectTimer) { clearInterval(detectTimer); detectTimer = null; }
        if (mediaStream) { mediaStream.getTracks().forEach(function (t) { t.stop(); }); mediaStream = null; }
        video.srcObject = null;
    }

    function scanFrame() {
        if (locked || !mediaStream || typeof window.jsQR !== 'function') return;
        if (video.readyState !== video.HAVE_ENOUGH_DATA || !video.videoWidth) return;

        scanCanvas.width = video.videoWidth;
        scanCanvas.height = video.videoHeight;
        scanCtx.drawImage(video, 0, 0, scanCanvas.width, scanCanvas.height);

        var imageData;
        try {
            imageData = scanCtx.getImageData(0, 0, scanCanvas.width, scanCanvas.height);
        } catch (e) {
            return;
        }

        var resultado = window.jsQR(imageData.data, imageData.width, imageData.height, { inversionAttempts: 'dontInvert' });
        if (resultado && resultado.data) handleCodigo(resultado.data);
    }

    function decodeImageFile(file) {
        if (typeof window.jsQR !== 'function') {
            showOverlayError('A biblioteca de leitura não carregou — verifique sua conexão e tente novamente.');
            return;
        }
        var url = URL.createObjectURL(file);
        var img = new Image();
        img.onload = function () {
            URL.revokeObjectURL(url);
            scanCanvas.width = img.naturalWidth;
            scanCanvas.height = img.naturalHeight;
            scanCtx.drawImage(img, 0, 0);

            var imageData;
            try {
                imageData = scanCtx.getImageData(0, 0, scanCanvas.width, scanCanvas.height);
            } catch (e) {
                showOverlayError('Não foi possível processar essa imagem.');
                return;
            }

            var resultado = window.jsQR(imageData.data, imageData.width, imageData.height);
            if (!resultado || !resultado.data) {
                showOverlayError('Nenhum QR Code encontrado nessa imagem. Tente outra foto, com mais luz e o código bem enquadrado.');
                announce('Nenhum QR Code encontrado na imagem selecionada.');
                return;
            }
            handleCodigo(resultado.data);
        };
        img.onerror = function () {
            URL.revokeObjectURL(url);
            showOverlayError('Não foi possível abrir essa imagem.');
        };
        img.src = url;
    }

    // ─── Confirmar chegada: o próprio scan já é a tentativa de confirmação ─────
    function handleCodigo(raw) {
        if (locked) return;
        var codigo = (raw || '').trim();
        if (!codigo) return;
        locked = true;
        clearOverlayError();

        postAcao({ acao: 'confirmar_chegada', id: ID, codigo: codigo }).then(function (res) {
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

    manualBtn.addEventListener('click', function () { if (manualInput.value.trim()) handleCodigo(manualInput.value); });
    manualInput.addEventListener('keydown', function (e) { if (e.key === 'Enter' && manualInput.value.trim()) handleCodigo(manualInput.value); });

    if (uploadBtn && imageInput) {
        uploadBtn.addEventListener('click', function () { imageInput.click(); });
        imageInput.addEventListener('change', function () {
            var file = imageInput.files && imageInput.files[0];
            imageInput.value = '';
            if (file) decodeImageFile(file);
        });
    }
}());
