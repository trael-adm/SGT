(function () {
    'use strict';

    var API      = window.RETRABALHO_API || '';
    var REPROVAS = window.RETRABALHO_REPROVAS || [];
    var TEM_REPROVA_ABERTA = window.RETRABALHO_TEM_REPROVA_ABERTA === true;
    var VOLTAR   = window.RETRABALHO_VOLTAR || '';
    var LOCAL_LABEL = { IQF: 'IQF — Inspeção final', LAB: 'LAB — Laboratório', GER: 'GER — Geral' };

    /** Envia o form como multipart/form-data (necessário para os anexos). */
    function postAcaoForm(form, acao) {
        var body = new FormData(form);
        body.append('acao', acao);
        return fetch(API, {
            method: 'POST',
            body: body
        }).then(function (r) { return r.json().catch(function () { return { sucesso: false, erro: 'Resposta inválida do servidor.' }; }); });
    }

    // ─── Expandir / recolher "Adicionar nova reprova" + Triagem ────────────────
    var toggleBtn = document.getElementById('rtd-toggle-add');
    var addWrap   = document.getElementById('rtd-add-wrap');

    function setAddWrapAberto(aberto) {
        if (!addWrap || !toggleBtn) return;
        addWrap.style.display = aberto ? 'block' : 'none';
        toggleBtn.textContent = aberto ? '× Fechar' : '+ Nova Reprova';
        toggleBtn.setAttribute('aria-expanded', aberto ? 'true' : 'false');
    }

    if (toggleBtn && addWrap) {
        toggleBtn.addEventListener('click', function () {
            setAddWrapAberto(addWrap.style.display === 'none');
        });
    }

    var listaReprovas    = document.getElementById('rtd-reprovas-lista');
    var subtitleReprovas = document.getElementById('rtd-reprovas-subtitle');

    // ─── Causa raiz de uma reprova específica (popup) ──────────────────────────
    // Cada reprova tem sua própria causa raiz — ao contrário dos outros campos da
    // Triagem (causa da reprova/observações/setores), que são compartilhados por
    // todo o lote, este é gravado numa ação separada (acao=definir_causa_raiz,
    // ver api/retrabalho-acao.php) que só toca esta reprova, inclusive no status
    // (finaliza só ela, sem mexer nas demais do mesmo N° de série).
    var crModal   = document.getElementById('rtd-causaraiz-modal');
    var crIdEl    = document.getElementById('rtd-causaraiz-id');
    var crTexto   = document.getElementById('rtd-causaraiz-texto');
    var crErro    = document.getElementById('rtd-causaraiz-erro');
    var crSalvar  = document.getElementById('rtd-causaraiz-salvar');
    var crBtnAtual = null;

    function abrirCausaRaiz(btn) {
        crBtnAtual = btn;
        crIdEl.value = btn.getAttribute('data-id');
        crTexto.value = btn.getAttribute('data-causa-raiz') || '';
        if (crErro) { crErro.style.display = 'none'; crErro.textContent = ''; }
        if (crModal) crModal.style.display = 'flex';
    }

    function fecharCausaRaiz() {
        if (crModal) crModal.style.display = 'none';
        crBtnAtual = null;
    }

    if (listaReprovas) {
        listaReprovas.addEventListener('click', function (e) {
            var btn = e.target.closest('.js-abrir-causa-raiz');
            if (btn) abrirCausaRaiz(btn);
        });
    }

    ['rtd-causaraiz-close', 'rtd-causaraiz-cancelar'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('click', fecharCausaRaiz);
    });
    if (crModal) {
        crModal.addEventListener('click', function (e) { if (e.target === crModal) fecharCausaRaiz(); });
    }

    if (crSalvar) {
        crSalvar.addEventListener('click', function () {
            if (crErro) { crErro.style.display = 'none'; crErro.textContent = ''; }
            crSalvar.disabled = true;

            var body = new URLSearchParams({
                acao: 'definir_causa_raiz',
                id: crIdEl.value,
                causa_raiz: crTexto.value
            });
            fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(function (r) {
                return r.json().catch(function () { return { sucesso: false, erro: 'Resposta inválida do servidor.' }; });
            }).then(function (res) {
                crSalvar.disabled = false;
                if (!res || !res.sucesso) {
                    if (crErro) { crErro.textContent = (res && res.erro) || 'Erro ao salvar a causa raiz.'; crErro.style.display = 'block'; }
                    return;
                }

                // Só grava o texto aqui — a reprova continua na lista até a Triagem
                // inteira ser reenviada (botão "Enviar"), que é quando finaliza de fato.
                if (crBtnAtual) {
                    var texto = crTexto.value.trim();
                    crBtnAtual.setAttribute('data-causa-raiz', texto);
                    crBtnAtual.textContent = texto ? '✓ Ver / editar causa raiz' : '+ Adicionar causa raiz';
                }
                fecharCausaRaiz();
            }).catch(function () {
                crSalvar.disabled = false;
                if (crErro) { crErro.textContent = 'Falha de conexão ao salvar a causa raiz.'; crErro.style.display = 'block'; }
            });
        });
    }

    // ─── Excluir uma reprova já registrada ─────────────────────────────────────
    // Exclusão é soft delete (acao=excluir, ver api/retrabalho-acao.php) — some da
    // lista imediatamente. Se essa era a última reprova deste N° de série, o
    // registro inteiro sai da Relação de Retrabalhos (mesmo filtro deleted_at IS
    // NULL usado lá): aqui, isso significa que não sobra mais nada pra mostrar
    // nesta página, então volta pra Relação — o usuário pode começar do zero
    // (escanear + registrar reprovas novas) como se nunca tivesse histórico.
    if (listaReprovas) {
        listaReprovas.addEventListener('click', function (e) {
            var btn = e.target.closest('.js-remover-reprova');
            if (!btn) return;

            if (!window.confirm('Excluir esta reprova? Esta ação não pode ser desfeita por aqui.')) return;

            var id = btn.getAttribute('data-id');
            btn.disabled = true;

            var body = new URLSearchParams({ acao: 'excluir', id: id });
            fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(function (r) {
                return r.json().catch(function () { return { sucesso: false, erro: 'Resposta inválida do servidor.' }; });
            }).then(function (res) {
                if (!res || !res.sucesso) {
                    alert((res && res.erro) || 'Erro ao excluir a reprova.');
                    btn.disabled = false;
                    return;
                }
                var item = btn.closest('.rtd-item');
                if (item) item.remove();

                var restantes = listaReprovas.querySelectorAll('.rtd-item').length;
                if (restantes === 0) {
                    window.location.href = VOLTAR || window.location.href;
                    return;
                }
                if (subtitleReprovas) {
                    subtitleReprovas.textContent = restantes + ' reprova(s) — excluir remove o código; sem nenhuma reprova, este N° de série sai da Relação de Retrabalhos';
                }
            }).catch(function () {
                alert('Falha de conexão ao excluir a reprova.');
                btn.disabled = false;
            });
        });
    }

    // ─── Blocos repetíveis de "Código de reprova" (uma triagem, várias reprovas) ─
    var blocosWrap = document.getElementById('rtd-reprova-blocos');
    var addBlocoBtn = document.getElementById('rtd-add-bloco');

    function preencherReprovaNoBloco(bloco, idReprova) {
        var r = REPROVAS.filter(function (x) { return String(x.id) === String(idReprova); })[0];
        var familiaEl = bloco.querySelector('.js-familia');
        var localEl   = bloco.querySelector('.js-local');
        if (familiaEl) familiaEl.value = r ? r.familia : '';
        if (localEl)   localEl.value   = r ? (LOCAL_LABEL[r.local] || r.local) : '';
    }

    if (blocosWrap) {
        // Delegação: troca de código de reprova em qualquer bloco (existente ou clonado).
        blocosWrap.addEventListener('change', function (e) {
            if (!e.target.classList.contains('js-reprova-sel')) return;
            var bloco = e.target.closest('.rtd-reprova-bloco');
            if (bloco) preencherReprovaNoBloco(bloco, e.target.value);
        });

        // Delegação: excluir um bloco específico. Se for o único bloco restante,
        // fecha a seção inteira (cancela) e recria um bloco em branco para a próxima vez.
        blocosWrap.addEventListener('click', function (e) {
            var btn = e.target.closest('.js-remover-bloco');
            if (!btn) return;
            var bloco = btn.closest('.rtd-reprova-bloco');
            if (!bloco) return;

            var ultimo = blocosWrap.querySelectorAll('.rtd-reprova-bloco').length <= 1;
            bloco.remove();
            if (ultimo) {
                blocosWrap.appendChild(criarBlocoVazio());
                setAddWrapAberto(false);
            }
        });
    }

    if (addBlocoBtn && blocosWrap) {
        addBlocoBtn.addEventListener('click', function () {
            var blocos = blocosWrap.querySelectorAll('.rtd-reprova-bloco');
            var base = blocos.length ? blocos[blocos.length - 1] : null;
            var novo = base ? base.cloneNode(true) : criarBlocoVazio();
            if (base) {
                novo.querySelector('.js-reprova-sel').value = '';
                var dataEl = novo.querySelector('.js-data-reprova');
                if (dataEl) dataEl.value = new Date().toISOString().slice(0, 10);
                novo.querySelector('.js-familia').value = '';
                novo.querySelector('.js-local').value = '';
            }
            blocosWrap.appendChild(novo);
        });
    }

    /** Fallback caso todos os blocos tenham sido excluídos: recria um a partir do template original. */
    function criarBlocoVazio() {
        var tpl = document.getElementById('rtd-bloco-template');
        return tpl.content.firstElementChild.cloneNode(true);
    }

    // ─── Materiais utilizados: marca o checkbox sozinho ao digitar quantidade/descrição ──
    var materiaisWrap = document.querySelector('.rtd-materiais');
    if (materiaisWrap) {
        materiaisWrap.addEventListener('input', function (e) {
            if (!e.target.matches('input[type=number], input.rtd-material-outros-desc')) return;
            var item = e.target.closest('.rtd-material-item');
            var chk = item && item.querySelector('.js-material-check');
            if (chk && e.target.value.trim() !== '') chk.checked = true;
        });
    }

    // ─── Envio (Triagem + 1 ou mais reprovas) ──────────────────────────────────
    var formAdd  = document.getElementById('rtd-form-add');
    var erroEl   = document.getElementById('rtd-add-erro');
    var submitEl = document.getElementById('rtd-add-submit');

    function mostraErro(msg) {
        if (erroEl) { erroEl.textContent = msg; erroEl.style.display = 'block'; }
    }

    if (formAdd) {
        formAdd.addEventListener('submit', function (e) {
            e.preventDefault();
            if (erroEl) { erroEl.style.display = 'none'; erroEl.textContent = ''; }

            var temCodigo = Array.prototype.some.call(
                formAdd.querySelectorAll('.js-reprova-sel'),
                function (sel) { return sel.value; }
            );
            // Nova reprova é opcional quando já existe uma aberta para este NS/projeto —
            // o envio, nesse caso, só atualiza a Triagem (causa/data/observações/setores).
            if (!temCodigo && !TEM_REPROVA_ABERTA) {
                mostraErro('Selecione ao menos um código de reprova — abra "+ Nova Reprova" acima e escolha um código antes de enviar.');
                // A seção "Adicionar nova reprova" pode estar fechada (ou nem ter sido
                // aberta ainda) — sem isso, o erro aparece longe do campo que falta, sem
                // pista nenhuma de onde ele está.
                if (addWrap && addWrap.style.display === 'none') setAddWrapAberto(true);
                var primeiroSelect = formAdd.querySelector('.js-reprova-sel');
                if (primeiroSelect) {
                    primeiroSelect.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    primeiroSelect.focus();
                }
                return;
            }

            if (submitEl) { submitEl.disabled = true; submitEl.textContent = 'Enviando…'; }

            postAcaoForm(formAdd, 'registrar').then(function (res) {
                if (res && res.sucesso) {
                    window.location.href = VOLTAR || window.location.href;
                } else {
                    mostraErro((res && res.erro) || 'Erro ao registrar.');
                    if (submitEl) { submitEl.disabled = false; submitEl.textContent = 'Enviar'; }
                }
            }).catch(function () {
                mostraErro('Falha de conexão.');
                if (submitEl) { submitEl.disabled = false; submitEl.textContent = 'Enviar'; }
            });
        });
    }

    // ─── Registrar início do retrabalho (dia + horário) via leitura de QR ──────
    // Abre um overlay embutido na própria página (não navega para outra tela, já
    // que o resto da Triagem pode estar preenchido) e, ao ler o QR do transformador,
    // grava data_inicio = agora no servidor (acao=confirmar_inicio) — não é só um
    // preenchimento local, o próprio scan já é o registro.
    (function () {
        var scanBtn   = document.getElementById('rtd-inicio-scan-btn');
        var overlay   = document.getElementById('rtd-inicio-overlay');
        var display   = document.getElementById('rtd-inicio-display');
        if (!scanBtn || !overlay || !display) return;

        var IDPROJETO = window.RETRABALHO_ID_PROJETO || 0;
        var NS        = window.RETRABALHO_NS || '';

        var closeBtn      = document.getElementById('rtd-inicio-close');
        var stage          = document.getElementById('rtd-inicio-stage');
        var stageEmptyText = document.getElementById('rtd-inicio-stage-empty-text');
        var video          = document.getElementById('rtd-inicio-video');
        var viewfinder     = document.getElementById('rtd-inicio-viewfinder');
        var hint           = document.getElementById('rtd-inicio-hint');
        var overlayError   = document.getElementById('rtd-inicio-error');
        var manualInput    = document.getElementById('rtd-inicio-manual-ns');
        var manualBtn      = document.getElementById('rtd-inicio-manual-btn');
        var liveRegion     = document.getElementById('rtd-inicio-live-region');

        var mediaStream = null;
        var detectTimer = null;
        var scanCanvas  = document.createElement('canvas');
        var scanCtx     = scanCanvas.getContext('2d', { willReadFrequently: true });
        var locked      = false;

        function announce(msg) { if (liveRegion) liveRegion.textContent = msg; }

        function showOverlayError(msg) { overlayError.style.display = 'block'; overlayError.textContent = msg; }
        function clearOverlayError() { overlayError.style.display = 'none'; overlayError.textContent = ''; }

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
                    stage.classList.add('has-video');
                    if (typeof window.jsQR === 'function') {
                        detectTimer = setInterval(scanFrame, 350);
                    } else {
                        hint.textContent = 'Câmera ativa, mas a biblioteca de leitura não carregou (verifique sua conexão) — use a leitura manual abaixo.';
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
            stage.classList.remove('has-video');
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

        function abrirOverlay() {
            overlay.style.display = 'flex';
            locked = false;
            clearOverlayError();
            viewfinder.classList.remove('is-success', 'is-error');
            hint.textContent = 'Aponte a câmera para o QR Code do transformador';
            startCamera();
        }

        function fecharOverlay() {
            stopCamera();
            overlay.style.display = 'none';
        }

        function handleCodigo(raw) {
            if (locked) return;
            var codigo = (raw || '').trim();
            if (!codigo) return;
            locked = true;
            clearOverlayError();

            var body = new URLSearchParams({
                acao: 'confirmar_inicio',
                id_projeto: IDPROJETO,
                ns_transformador: NS,
                codigo: codigo
            });
            fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(function (r) {
                return r.json().catch(function () { return { sucesso: false, erro: 'Resposta inválida do servidor.' }; });
            }).then(function (res) {
                if (res && res.sucesso) {
                    flashViewfinder(true);
                    stopCamera();
                    hint.textContent = 'Início registrado! Fechando…';
                    announce('Início do retrabalho registrado.');
                    if (res.data_inicio) {
                        var ts = Date.parse(res.data_inicio.replace(' ', 'T'));
                        if (!isNaN(ts)) {
                            var d = new Date(ts);
                            var pad = function (n) { return String(n).padStart(2, '0'); };
                            display.value = pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + String(d.getFullYear()).slice(-2)
                                + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
                        }
                    }
                    setTimeout(fecharOverlay, 900);
                    return;
                }
                flashViewfinder(false);
                showOverlayError((res && res.erro) || 'Falha ao registrar início.');
                announce((res && res.erro) || 'Falha ao registrar início.');
                setTimeout(function () {
                    locked = false;
                    viewfinder.classList.remove('is-success', 'is-error');
                }, 1500);
            }).catch(function () {
                flashViewfinder(false);
                showOverlayError('Falha de conexão ao registrar início.');
                setTimeout(function () {
                    locked = false;
                    viewfinder.classList.remove('is-success', 'is-error');
                }, 1500);
            });
        }

        scanBtn.addEventListener('click', abrirOverlay);
        if (closeBtn) closeBtn.addEventListener('click', fecharOverlay);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) fecharOverlay(); });

        if (manualBtn && manualInput) {
            manualBtn.addEventListener('click', function () { if (manualInput.value.trim()) handleCodigo(manualInput.value); });
            manualInput.addEventListener('keydown', function (e) { if (e.key === 'Enter' && manualInput.value.trim()) handleCodigo(manualInput.value); });
        }
    }());

}());
