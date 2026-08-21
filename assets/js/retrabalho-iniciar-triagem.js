(function () {
    'use strict';

    var API        = window.RETRABALHO_API || '';
    var ID_PROJETO = window.RETRABALHO_INICIO_ID_PROJETO || 0;
    var NS         = window.RETRABALHO_INICIO_NS || '';
    var VOLTAR     = window.RETRABALHO_INICIO_VOLTAR || 'relacao.php';
    var DESTINO    = window.RETRABALHO_INICIO_DESTINO || 'detalhe.php';

    var inputBarcode   = document.getElementById('inputBarcodeNs');
    var btnConfirmar   = document.getElementById('btnConfirmarInicio');
    var msgErro        = document.getElementById('inicioMsgErro');
    var barcodeBox     = document.getElementById('barcodeBox');

    var btnAbrirCamera = document.getElementById('btnAbrirCamera');
    var btnFecharCamera = document.getElementById('btnFecharCamera');
    var btnVoltarBarcode = document.getElementById('btnVoltarBarcode');
    var scanOverlay    = document.getElementById('scanOverlay');
    var scanStage      = document.getElementById('scanStage');
    var stageEmptyText = document.getElementById('scanStageEmptyText');
    var video          = document.getElementById('scanVideo');
    var viewfinder     = document.getElementById('viewfinder');
    var scanHint       = document.getElementById('scanHint');
    var overlayError   = document.getElementById('overlayError');
    var liveRegion     = document.getElementById('liveRegion');

    var mediaStream    = null;
    var scanCanvas     = document.createElement('canvas');
    var scanCtx        = scanCanvas.getContext('2d', { willReadFrequently: true });
    var locked         = false;

    function announce(msg) {
        if (liveRegion) liveRegion.textContent = msg;
    }

    function mostrarErro(texto) {
        if (msgErro) {
            msgErro.textContent = texto;
            msgErro.style.display = 'block';
        }
        if (overlayError) {
            overlayError.textContent = texto;
            overlayError.style.display = 'block';
        }
        if (inputBarcode) {
            inputBarcode.classList.add('is-invalid');
            inputBarcode.select();
        }
    }

    function limparErro() {
        if (msgErro) {
            msgErro.textContent = '';
            msgErro.style.display = 'none';
        }
        if (overlayError) {
            overlayError.textContent = '';
            overlayError.style.display = 'none';
        }
        if (inputBarcode) {
            inputBarcode.classList.remove('is-invalid');
        }
    }

    function processarConfirmacao(codigoLido) {
        if (locked) return;
        var codigo = (codigoLido || '').trim().toUpperCase();
        if (!codigo) {
            mostrarErro('Por favor, bipe ou digite o número de série.');
            return;
        }

        limparErro();
        locked = true;

        if (btnConfirmar) {
            btnConfirmar.disabled = true;
            btnConfirmar.textContent = 'Registrando início da triagem…';
        }

        var body = new URLSearchParams({
            acao: 'confirmar_inicio',
            id_projeto: ID_PROJETO,
            ns_transformador: NS,
            codigo: codigo
        });

        fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function (r) {
            return r.json().catch(function () {
                return { sucesso: false, erro: 'Resposta inválida do servidor.' };
            });
        })
        .then(function (res) {
            if (res && res.sucesso) {
                announce('Início da triagem registrado com sucesso.');
                if (scanOverlay) scanOverlay.style.display = 'none';
                stopCamera();
                
                if (barcodeBox) {
                    barcodeBox.innerHTML = '<div style="font-size:32px;margin-bottom:8px;">✅</div><h3 style="color:#059669;font-size:16px;font-weight:700;">Início registrado com sucesso!</h3><p style="font-size:12px;color:#64748b;">Abrindo triagem…</p>';
                }
                setTimeout(function () {
                    window.location.href = DESTINO;
                }, 500);
            } else {
                locked = false;
                if (btnConfirmar) {
                    btnConfirmar.disabled = false;
                    btnConfirmar.textContent = 'Confirmar Início da Triagem (Enter)';
                }
                mostrarErro(res.erro || 'Não foi possível registrar o início da triagem deste transformador.');
            }
        })
        .catch(function (err) {
            locked = false;
            if (btnConfirmar) {
                btnConfirmar.disabled = false;
                btnConfirmar.textContent = 'Confirmar Início da Triagem (Enter)';
            }
            mostrarErro('Erro de conexão: ' + (err.message || 'Verifique sua rede.'));
        });
    }

    window.submeterInicioManual = function () {
        if (inputBarcode) {
            processarConfirmacao(inputBarcode.value);
        }
    };

    // ─── Foco Automático e Destaque ──────────────────────────────────────────
    if (inputBarcode) {
        inputBarcode.focus();
        inputBarcode.addEventListener('focus', function () {
            if (barcodeBox) barcodeBox.classList.add('is-focused');
        });
        inputBarcode.addEventListener('blur', function () {
            if (barcodeBox) barcodeBox.classList.remove('is-focused');
        });
    }

    // ─── Scanner Físico de Código de Barras USB / Teclado ────────────────────
    var barcodeBuffer = '';
    var barcodeTimer = null;

    window.addEventListener('keydown', function (e) {
        // Se a câmera estiver aberta, ignora scanner
        if (scanOverlay && scanOverlay.style.display === 'flex') return;

        // Se pressionar Enter fora do formulário ou com leitor
        if (e.key === 'Enter') {
            if (barcodeBuffer.trim().length >= 3) {
                e.preventDefault();
                processarConfirmacao(barcodeBuffer.trim());
                barcodeBuffer = '';
                clearTimeout(barcodeTimer);
                return;
            }
            barcodeBuffer = '';
            clearTimeout(barcodeTimer);
            return;
        }

        // Se o usuário estiver focado no input normal, deixa o navegador tratar
        if (document.activeElement === inputBarcode) {
            return;
        }

        // Captura caracteres rápidos de leitores USB
        if (e.key && e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey) {
            barcodeBuffer += e.key;
            clearTimeout(barcodeTimer);
            barcodeTimer = setTimeout(function () {
                barcodeBuffer = '';
            }, 100);
        }
    });

    // ─── Controle de Câmera / QR Code (Opcional) ─────────────────────────────
    function startCamera() {
        if (mediaStream) return;
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            if (stageEmptyText) stageEmptyText.textContent = 'Câmera não suportada neste navegador.';
            return;
        }
        if (stageEmptyText) stageEmptyText.textContent = 'Solicitando acesso à câmera…';
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
            .then(function (stream) {
                mediaStream = stream;
                video.srcObject = stream;
                video.setAttribute('playsinline', true);
                video.play();
                if (scanStage) scanStage.classList.add('has-video');
                requestAnimationFrame(tick);
            })
            .catch(function () {
                if (stageEmptyText) stageEmptyText.textContent = 'Não foi possível acessar a câmera.';
                if (scanStage) scanStage.classList.remove('has-video');
            });
    }

    function stopCamera() {
        if (mediaStream) {
            mediaStream.getTracks().forEach(function (t) { t.stop(); });
            mediaStream = null;
            if (video) video.srcObject = null;
            if (scanStage) scanStage.classList.remove('has-video');
        }
    }

    function tick() {
        if (!mediaStream) return;
        if (video && video.readyState === video.HAVE_ENOUGH_DATA) scanFrame();
        requestAnimationFrame(tick);
    }

    function scanFrame() {
        if (locked || !mediaStream || typeof window.jsQR !== 'function') return;
        if (!video || video.readyState !== video.HAVE_ENOUGH_DATA || !video.videoWidth) return;
        scanCanvas.width = video.videoWidth;
        scanCanvas.height = video.videoHeight;
        scanCtx.drawImage(video, 0, 0, scanCanvas.width, scanCanvas.height);
        var imageData;
        try { imageData = scanCtx.getImageData(0, 0, scanCanvas.width, scanCanvas.height); } catch (e) { return; }
        var resultado = window.jsQR(imageData.data, imageData.width, imageData.height, { inversionAttempts: 'dontInvert' });
        if (resultado && resultado.data) {
            processarConfirmacao(resultado.data);
        }
    }

    function abrirCameraOverlay() {
        limparErro();
        if (scanOverlay) scanOverlay.style.display = 'flex';
        startCamera();
    }

    function fecharCameraOverlay() {
        stopCamera();
        if (scanOverlay) scanOverlay.style.display = 'none';
        if (inputBarcode) inputBarcode.focus();
    }

    if (btnAbrirCamera) btnAbrirCamera.addEventListener('click', abrirCameraOverlay);
    if (btnFecharCamera) btnFecharCamera.addEventListener('click', fecharCameraOverlay);
    if (btnVoltarBarcode) btnVoltarBarcode.addEventListener('click', fecharCameraOverlay);

})();
