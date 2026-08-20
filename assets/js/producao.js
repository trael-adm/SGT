(function () {
    'use strict';

    var API      = window.PRODUCAO_API || '';
    var ESTACAO  = window.PRODUCAO_ESTACAO || 'LAB';
    var ESTACAO_NOME = ESTACAO === 'IQF' ? 'na Inspeção Final' : 'no Laboratório';

    // ─── Helpers ──────────────────────────────────────────────────────────────
    function notify(msg, type) {
        if (typeof window.showAlert === 'function') window.showAlert(msg, type || 'info');
    }

    function postAcao(payload) {
        var body = new URLSearchParams(payload);
        return fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (r) { return r.json().catch(function () { return { sucesso: false, erro: 'Resposta inválida do servidor.' }; }); });
    }

    function escapeHtml(s) {
        return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // ─── Elementos ────────────────────────────────────────────────────────────
    var labCard   = document.querySelector('.station-card');
    var labStatus = document.querySelector('.station-card__status');
    var labSub    = document.querySelector('.station-card__sub');

    var scanMeta        = document.getElementById('scanMeta');
    var scanReviewEmpty = document.getElementById('scanReviewEmpty');
    var scanReviewData  = document.getElementById('scanReviewData');
    var scanDate        = document.getElementById('scanDate');
    var scanStatusLabel = document.getElementById('scanStatusLabel');
    var fieldNs         = document.getElementById('fieldNs');
    var fieldProjeto    = document.getElementById('fieldProjeto');
    var fieldDescricao  = document.getElementById('fieldDescricao');
    var fieldPedido     = document.getElementById('fieldPedido');
    var fieldCliente    = document.getElementById('fieldCliente');
    var scanConflict    = document.getElementById('scanConflict');
    var scanUnresolved  = document.getElementById('scanUnresolved');
    var scanActions     = document.getElementById('scanActions');
    var conflictActions = document.getElementById('conflictActions');

    var fabBtn          = document.getElementById('fabBtn');
    var uploadBtn        = document.getElementById('uploadImageBtn');
    var imageInput       = document.getElementById('qrImageInput');
    var overlay          = document.getElementById('scanOverlay');
    var closeBtn          = document.getElementById('closeOverlayBtn');
    var scanStage         = document.getElementById('scanStage');
    var stageEmptyText    = document.getElementById('scanStageEmptyText');
    var video             = document.getElementById('scanVideo');
    var viewfinder        = document.getElementById('viewfinder');
    var scanHint          = document.getElementById('scanHint');
    var overlayError      = document.getElementById('overlayError');
    var manualInput       = document.getElementById('manualNs');
    var manualBtn         = document.getElementById('manualBtn');
    var showManualBtn     = document.getElementById('showManualBtn');
    var manualInputRow    = document.getElementById('manualInputRow');
    var liveRegion        = document.getElementById('liveRegion');
    var confirmBtn        = document.getElementById('confirmScanBtn');

    var mediaStream = null;
    var detectTimer = null;
    var scanCanvas = document.createElement('canvas');
    var scanCtx = scanCanvas.getContext('2d', { willReadFrequently: true });
    var locked = false;
    var timerHandle = null;
    var pendingResult = null;
    var currentItemId = null;
    var overlayToken = 0;   // invalidado a cada abertura — descarta stream de câmera que chegue atrasado
    var stateOpSeq = 0;     // sequência de operações de estado — descarta respostas de polling desatualizadas

    if (!labCard || !fabBtn || !overlay) return; // página não carregou os elementos esperados

    function announce(msg) { if (liveRegion) liveRegion.textContent = msg; }
    function pad(n) { return n < 10 ? '0' + n : '' + n; }

    function fmtDataHoje() {
        var d = new Date();
        return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear();
    }

    // Preenche um campo fixo da Área de Leitura — sem valor, mostra "Não registrado"
    // (os campos não são mais editáveis manualmente, ver applyResult()).
    function setField(el, value) {
        if (value) {
            el.textContent = value;
            el.classList.remove('is-missing');
        } else {
            el.textContent = 'Não registrado';
            el.classList.add('is-missing');
        }
    }

    // As datas chegam da API já em ISO-8601 com offset explícito (ver isoComOffset() em
    // includes/helpers.php) — nunca depender do fuso horário do navegador para interpretá-las.
    function fmtHora(iso) {
        var d = iso ? new Date(iso) : new Date();
        if (isNaN(d.getTime())) d = new Date();
        return pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    // ─── Cronômetro do card (Removido) ───────────────────────────────────────

    // ─── Renderiza o estado da estação (vazio / em andamento) ───────────────
    // Única fonte de verdade de renderização — usada no carregamento inicial,
    // após confirmar uma entrada e a cada rodada do polling de status.
    function renderStationState(item) {
        // O laboratório não precisa manter o item "em andamento" preso no card,
        // pois a peça vai direto para a aba Lista para ser reprovada.
        
        // Evita re-renderizar o estado vazio se já estiver vazio
        if (currentItemId === 'vazio' && labStatus.innerHTML !== '') return;
        currentItemId = 'vazio';

        labStatus.innerHTML =
            '<div class="station-status-empty">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' +
            '<path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>' +
            '<line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="13" y2="16"/></svg>' +
            '<p>Aguardando leitura de peças.</p>' +
            '<span>Toque no botão para iniciar um novo registro.</span>' +
            '</div>';
        labSub.textContent = 'Pronto para leitura';
        labCard.classList.remove('is-active');
    }

    renderStationState(window.PRODUCAO_ITEM_ATUAL || null);

    // ─── Overlay do leitor ───────────────────────────────────────────────────
    function openOverlay() {
        overlayToken++;
        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        overlayError.style.display = 'none';
        manualInput.value = '';
        viewfinder.classList.remove('is-success', 'is-error');
        scanHint.textContent = 'Aponte a câmera para o QR Code do transformador';
        stageEmptyText.textContent = 'Solicitando acesso à câmera…';
        scanStage.classList.remove('has-video');
        locked = false;
        if (showManualBtn) showManualBtn.style.display = 'flex';
        if (manualInputRow) manualInputRow.style.display = 'none';
        startCamera();
    }

    function closeOverlay() {
        overlayToken++; // qualquer getUserMedia() ainda pendente passa a ser descartado ao resolver
        overlay.style.display = 'none';
        document.body.style.overflow = '';
        stopCamera();
    }

    // Abre o mesmo overlay, mas sem acionar a câmera — usado pelo botão "carregar
    // imagem" abaixo, que decodifica um arquivo escolhido em vez de vídeo ao vivo.
    function openOverlayForImage() {
        overlayToken++; // invalida qualquer getUserMedia() pendente de uma abertura anterior
        stopCamera();
        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        overlayError.style.display = 'none';
        manualInput.value = '';
        viewfinder.classList.remove('is-success', 'is-error');
        scanStage.classList.remove('has-video');
        scanHint.textContent = 'Selecione uma imagem com o QR Code do transformador';
        stageEmptyText.textContent = 'Nenhuma câmera será usada — escolha um arquivo de imagem.';
        locked = false;
        if (showManualBtn) showManualBtn.style.display = 'flex';
        if (manualInputRow) manualInputRow.style.display = 'none';
    }

    function decodeImageFile(file) {
        if (typeof window.jsQR !== 'function') {
            showOverlayError('A biblioteca de leitura não carregou — verifique sua conexão e tente novamente.');
            return;
        }
        stageEmptyText.textContent = 'Lendo imagem…';

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
                stageEmptyText.textContent = 'Nenhuma câmera será usada — escolha um arquivo de imagem.';
                showOverlayError('Nenhum QR Code encontrado nessa imagem. Tente outra foto, com mais luz e o código bem enquadrado.');
                announce('Nenhum QR Code encontrado na imagem selecionada.');
                return;
            }
            handleCodigo(resultado.data, 'imagem');
        };
        img.onerror = function () {
            URL.revokeObjectURL(url);
            showOverlayError('Não foi possível abrir essa imagem.');
        };
        img.src = url;
    }

    function startCamera() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            stageEmptyText.textContent = 'Este navegador não expõe câmera — use a leitura manual abaixo.';
            return;
        }
        var myToken = overlayToken;
        // Solicitando resolução HD para facilitar leitura de QR Codes densos
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } } })
            .then(function (stream) {
                if (myToken !== overlayToken) {
                    // overlay foi fechado (ou reaberto) antes da permissão resolver — não usar este stream
                    stream.getTracks().forEach(function (t) { t.stop(); });
                    return;
                }
                mediaStream = stream;
                video.srcObject = stream;
                if (myToken !== overlayToken) return;
                scanStage.classList.add('has-video');
                if ('BarcodeDetector' in window) {
            // A API nativa do Android/Chrome é acelerada por hardware e muito mais rápida
            if (!window.globalBarcodeDetector) {
                try {
                    // Força a câmera a procurar apenas QR Codes, ignorando os códigos de barras lineares
                    window.globalBarcodeDetector = new BarcodeDetector({ formats: ['qr_code'] });
                } catch (e) {
                    try {
                        window.globalBarcodeDetector = new BarcodeDetector(); // fallback se o array formats falhar
                    } catch (e2) {
                        window.globalBarcodeDetector = null;
                    }
                }
            }
            if (window.globalBarcodeDetector) {
                detectTimer = setInterval(scanFrameNative, 200); // 5x por segundo, pois é leve
            } else if (typeof window.jsQR === 'function') {
                detectTimer = setInterval(scanFrame, 500);
            }
        } else if (typeof window.jsQR === 'function') {
            detectTimer = setInterval(scanFrame, 500);
        } else {
            scanHint.textContent = 'Câmera ativa, mas a biblioteca de leitura não carregou (verifique sua conexão) — use a leitura manual abaixo.';
        }
            })
            .catch(function (err) {
                if (myToken !== overlayToken) return; // overlay já fechado — não atualizar mensagem
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

    function scanFrameNative() {
        if (locked || !mediaStream || !window.globalBarcodeDetector) return;
        if (video.readyState !== video.HAVE_ENOUGH_DATA || !video.videoWidth) return;

        window.globalBarcodeDetector.detect(video).then(function(barcodes) {
            if (barcodes.length > 0) {
                // Se a etiqueta tiver vários códigos (QR + Barras), pega o primeiro que a câmera focar bem
                handleCodigo(barcodes[0].rawValue, 'camera');
            }
        }).catch(function(e) {
            // Silencioso
        });
    }

    function scanFrame() {
        if (locked || !mediaStream || typeof window.jsQR !== 'function') return;
        if (video.readyState !== video.HAVE_ENOUGH_DATA || !video.videoWidth) return; // quadro ainda não pronto

        scanCanvas.width = video.videoWidth;
        scanCanvas.height = video.videoHeight;
        scanCtx.drawImage(video, 0, 0, scanCanvas.width, scanCanvas.height);

        var imageData;
        try {
            imageData = scanCtx.getImageData(0, 0, scanCanvas.width, scanCanvas.height);
        } catch (e) {
            return; // navegador recusou ler o canvas neste quadro — tenta de novo no próximo tick
        }

        var resultado = window.jsQR(imageData.data, imageData.width, imageData.height, { inversionAttempts: 'dontInvert' });
        if (resultado && resultado.data) handleCodigo(resultado.data, 'qr');
    }

    // ─── Resolver código lido/digitado no servidor ──────────────────────────
    function handleCodigo(raw, origem) {
        if (locked) return;
        var codigo = (raw || '').trim();
        if (!codigo) return;
        locked = true;

        postAcao({ acao: 'ler', codigo: codigo }).then(function (res) {
            if (!res || !res.sucesso) {
                locked = false;
                showOverlayError((res && res.erro) || 'Falha ao consultar o transformador.');
                return;
            }
            // 'nao_registrado' também segue adiante — a Área de Leitura mostra os campos
            // fixos como "Não registrado" em vez de travar o overlay num erro.
            flashViewfinder(true);
            setTimeout(function () {
                closeOverlay();
                applyResult(res, origem);
            }, 380);
        }).catch(function () {
            locked = false;
            showOverlayError('Falha de conexão ao consultar o transformador.');
        });
    }

    function showOverlayError(msg) {
        overlayError.style.display = 'block';
        overlayError.textContent = msg;
    }

    function flashViewfinder(ok) {
        viewfinder.classList.remove('is-success', 'is-error');
        viewfinder.classList.add(ok ? 'is-success' : 'is-error');
        if (navigator.vibrate) navigator.vibrate(ok ? 90 : [60, 40, 60]);
    }

    // ─── Aplicar resultado na Área de leitura ───────────────────────────────
    // Três estados mutuamente exclusivos, todos partindo de um código já resolvido no
    // servidor: 'ok' → pronto para registrar; 'conflict' → banner bloqueando; 'nao_registrado'
    // → projeto não existe no sistema, nada a fazer além de cancelar (sem opção de o
    // operador editar/escolher manualmente — ver resolverTransformador() no servidor).
    function applyResult(res, origem) {
        res.origem = origem;
        pendingResult = res;
        var ORIGEM_LABEL = { qr: 'via QR', manual: 'manual', imagem: 'via imagem' };
        scanMeta.textContent = (origem === 'manual' ? 'Informado às ' : 'Lido às ') + fmtHora() +
            ' · ' + (ORIGEM_LABEL[origem] || 'via QR') + (res.cd_of ? ' · OF ' + res.cd_of : '');
        scanMeta.classList.add('is-fresh');
        scanDate.textContent = fmtDataHoje();

        // Esconde o transformador atualmente em andamento na estação enquanto uma nova
        // leitura está sendo revisada — evita confundir "o que já está rodando" com "o
        // que acabou de ser lido e ainda não foi registrado". Volta a aparecer em
        // resetScanPanel() (cancelar/descartar) ou já atualizado após um registro.
        labStatus.style.display = 'none';

        setField(fieldNs, res.ns);
        setField(fieldProjeto, res.projeto_codigo);
        setField(fieldDescricao, res.descricao);
        setField(fieldPedido, res.pedido_numero);
        setField(fieldCliente, res.cliente);

        scanReviewEmpty.style.display = 'none';
        scanReviewData.style.display = 'flex';
        scanConflict.style.display = 'none';
        scanUnresolved.style.display = 'none';
        confirmBtn.textContent = 'Registrar';

        if (res.status === 'nao_registrado') {
            scanStatusLabel.textContent = 'Projeto não cadastrado';
            scanUnresolved.style.display = 'flex';
            scanActions.style.display = 'flex';
            conflictActions.style.display = 'none';
            confirmBtn.disabled = true;
            announce('Transformador lido, mas o projeto não está cadastrado no sistema. Registro bloqueado.');
            return;
        }

        confirmBtn.disabled = false;

        if (res.status === 'conflict') {
            scanStatusLabel.textContent = 'Conflito de estação';
            scanConflict.style.display = 'flex';
            scanConflict.innerHTML =
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>' +
                '<line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>' +
                '<span><strong>Já em andamento na estação ' + escapeHtml(res.estacao_atual) + '</strong> desde ' + escapeHtml(fmtHora(res.data_inicio)) +
                '. Resolva o conflito antes de registrar a entrada ' + ESTACAO_NOME + '.</span>';
            scanActions.style.display = 'none';
            conflictActions.style.display = 'flex';
            announce('Conflito: transformador já em andamento em outra estação.');
            return;
        }

        scanStatusLabel.textContent = 'Aguardando registro';
        scanActions.style.display = 'flex';
        conflictActions.style.display = 'none';
        announce('Leitura concluída. Confira os dados e registre a entrada.');
    }

    function resetScanPanel() {
        labStatus.style.display = '';
        setField(fieldNs, '');
        setField(fieldProjeto, '');
        setField(fieldDescricao, '');
        setField(fieldPedido, '');
        setField(fieldCliente, '');
        scanReviewData.style.display = 'none';
        scanReviewEmpty.style.display = 'block';
        confirmBtn.disabled = false;
        confirmBtn.textContent = 'Registrar';
        scanMeta.textContent = 'Aguardando leitura';
        scanMeta.classList.remove('is-fresh');
        scanConflict.style.display = 'none';
        scanUnresolved.style.display = 'none';
        scanActions.style.display = 'none';
        conflictActions.style.display = 'none';
        pendingResult = null;
        locked = false; // Libera o sistema para a próxima leitura global
    }

    function confirmEntry() {
        if (!pendingResult || pendingResult.status !== 'ok') return;

        var payload = { acao: 'confirmar', codigo: pendingResult.codigo, estacao: ESTACAO, metodo_insercao: (pendingResult.origem === 'manual' ? 'manual' : 'scanner') };

        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Registrando…';

        postAcao(payload).then(function (res) {
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'Registrar';

            if (!res || !res.sucesso) {
                notify((res && res.erro) || 'Erro ao confirmar entrada.', 'danger');
                return;
            }

            stateOpSeq++; // invalida qualquer resposta de polling mais antiga ainda em voo
            currentItemId = null; // força a re-renderização mesmo que o id coincida por acaso
            renderStationState(res.item);
            resetScanPanel();
            notify('Entrada confirmada ' + ESTACAO_NOME + '.', 'success');
            announce('Entrada confirmada ' + ESTACAO_NOME + '.');
        }).catch(function () {
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'Registrar';
            notify('Falha de conexão ao confirmar.', 'danger');
        });
    }

    // ─── Mantém o card sincronizado entre tablets/abas ──────────────────────
    function pollStatus() {
        var seq = ++stateOpSeq;
        postAcao({ acao: 'status', estacao: ESTACAO }).then(function (res) {
            if (seq !== stateOpSeq) return; // uma confirmação/poll mais recente já aconteceu — descarta
            if (res && res.sucesso) renderStationState(res.item);
        }).catch(function () { /* silencioso — próxima rodada tenta de novo */ });
    }
    setInterval(pollStatus, 20000);

    // ─── Eventos ──────────────────────────────────────────────────────────────
    fabBtn.addEventListener('click', openOverlay);
    if (uploadBtn && imageInput) {
        uploadBtn.addEventListener('click', function () {
            openOverlayForImage();
            imageInput.click();
        });
        imageInput.addEventListener('change', function () {
            var file = imageInput.files && imageInput.files[0];
            imageInput.value = ''; // permite escolher o mesmo arquivo de novo depois
            if (file) decodeImageFile(file);
        });
    }
    closeBtn.addEventListener('click', closeOverlay);
    document.getElementById('cancelScanBtn').addEventListener('click', resetScanPanel);
    document.getElementById('clearConflictBtn').addEventListener('click', resetScanPanel);
    document.getElementById('retryConflictBtn').addEventListener('click', function () { resetScanPanel(); openOverlay(); });
    confirmBtn.addEventListener('click', confirmEntry);
    if (showManualBtn) {
        showManualBtn.addEventListener('click', function() {
            showManualBtn.style.display = 'none';
            manualInputRow.style.display = 'flex';
            manualInput.focus();
        });
    }
    manualBtn.addEventListener('click', function () { if (manualInput.value.trim()) handleCodigo(manualInput.value, 'manual'); });
    manualInput.addEventListener('keydown', function (e) { if (e.key === 'Enter' && manualInput.value.trim()) handleCodigo(manualInput.value, 'manual'); });
    var barcodeBuffer = '';
    var barcodeTimer = null;

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && window.getComputedStyle(overlay).display !== 'none') {
            closeOverlay();
            return;
        }

        // Ignora digitação se o usuário estiver digitando manualmente em algum input
        if (e.target && (e.target.tagName.toLowerCase() === 'input' || e.target.tagName.toLowerCase() === 'textarea')) {
            return;
        }

        // Se apertar Enter, verifica se há um código no buffer (scanner costuma enviar Enter no final)
        if (e.key === 'Enter') {
            if (barcodeBuffer.trim().length >= 3) {
                e.preventDefault(); // Impede que o Enter "clique" no último botão que estava focado (ex: Cancelar)
                if (document.activeElement) document.activeElement.blur();
                
                handleCodigo(barcodeBuffer.trim(), 'scanner');
                if (window.getComputedStyle(overlay).display !== 'none') closeOverlay();
            }
            barcodeBuffer = '';
            clearTimeout(barcodeTimer);
            return;
        }

        // Adiciona ao buffer apenas se for caractere imprimível
        if (e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey) {
            barcodeBuffer += e.key;
            clearTimeout(barcodeTimer);
            // 100ms de tolerância: o scanner injeta teclas muito rápido. Se demorar mais que isso, descarta.
            barcodeTimer = setTimeout(function () {
                barcodeBuffer = '';
            }, 100);
        }
    });
}());
