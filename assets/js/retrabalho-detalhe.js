(function () {
    'use strict';

    var API      = window.RETRABALHO_API || '';
    var REPROVAS = window.RETRABALHO_REPROVAS || [];
    var TEM_REPROVA_ABERTA = window.RETRABALHO_TEM_REPROVA_ABERTA === true;
    var VOLTAR   = window.RETRABALHO_VOLTAR || '';
    var LOCAL_LABEL = { IQF: 'IQF — Inspeção final', LAB: 'LAB — Laboratório', GER: 'GER — Geral' };

    // Trava do "Enviar": se já possui data_inicio registrada, considera confirmado
    var displayInitial = document.getElementById('rtd-inicio-display');
    var qrInicioConfirmadoNestaSessao = Boolean(displayInitial && displayInitial.value.trim() !== '');

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
        toggleBtn.textContent = aberto ? 'Ocultar' : '+ Nova Reprova';
        toggleBtn.className = aberto ? 'btn btn-secondary btn-sm' : 'btn btn-primary btn-sm';
        toggleBtn.setAttribute('aria-expanded', aberto ? 'true' : 'false');
    }

    if (toggleBtn && addWrap) {
        toggleBtn.addEventListener('click', function () {
            setAddWrapAberto(addWrap.style.display === 'none');
        });
    }

    var btnSalvarNovaReprova = document.getElementById('rtd-btn-salvar-nova-reprova');
    if (btnSalvarNovaReprova) {
        btnSalvarNovaReprova.addEventListener('click', function () {
            var blocos = document.querySelectorAll('#rtd-reprova-blocos .rtd-reprova-bloco');
            var selects = document.querySelectorAll('#rtd-reprova-blocos .js-reprova-sel');
            var temSelecionado = false;
            selects.forEach(function (s) {
                if (s.value) temSelecionado = true;
            });

            if (!temSelecionado) {
                alert('Por favor, selecione ao menos um código de reprova.');
                return;
            }

            btnSalvarNovaReprova.disabled = true;
            btnSalvarNovaReprova.textContent = 'Adicionando...';

            var formAdd = document.getElementById('rtd-form-add');
            var formData = new FormData(formAdd);
            formData.append('acao', 'adicionar_reprova_ret');

            fetch(API, {
                method: 'POST',
                body: formData
            }).then(function (r) {
                return r.json().catch(function () { return { sucesso: false, erro: 'Resposta inválida do servidor.' }; });
            }).then(function (res) {
                if (res && res.sucesso) {
                    if (res.id_reprova) {
                        window.location.href = 'detalhe.php?id=' + res.id_reprova;
                    } else {
                        window.location.reload();
                    }
                } else {
                    alert((res && res.erro) || 'Erro ao adicionar nova reprova.');
                    btnSalvarNovaReprova.disabled = false;
                    btnSalvarNovaReprova.textContent = '+ Adicionar Reprova';
                }
            }).catch(function () {
                alert('Falha de conexão ao adicionar a nova reprova.');
                btnSalvarNovaReprova.disabled = false;
                btnSalvarNovaReprova.textContent = '+ Adicionar Reprova';
            });
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
                    if (crErro) { crErro.textContent = (res && res.erro) || 'Erro ao salvar a causa da reprova.'; crErro.style.display = 'block'; }
                    return;
                }

                // Só grava o texto aqui — a reprova continua na lista até a Triagem
                // inteira ser reenviada (botão "Enviar"), que é quando finaliza de fato.
                if (crBtnAtual) {
                    var texto = crTexto.value.trim();
                    crBtnAtual.setAttribute('data-causa-raiz', texto);
                    crBtnAtual.textContent = texto ? '✓ Ver / editar causa da reprova' : '+ Adicionar causa da reprova';
                }
                fecharCausaRaiz();
            }).catch(function () {
                crSalvar.disabled = false;
                if (crErro) { crErro.textContent = 'Falha de conexão ao salvar a causa da reprova.'; crErro.style.display = 'block'; }
            });
        });
    }

    // ─── Correção de uma reprova específica (popup) ────────────────────────────
    // Mesmo padrão da causa raiz acima: gravada numa ação separada
    // (acao=definir_correcao) que só toca esta reprova, sem afetar as demais.
    var coModal   = document.getElementById('rtd-correcao-modal');
    var coIdEl    = document.getElementById('rtd-correcao-id');
    var coTexto   = document.getElementById('rtd-correcao-texto');
    var coErro    = document.getElementById('rtd-correcao-erro');
    var coSalvar  = document.getElementById('rtd-correcao-salvar');
    var coBtnAtual = null;

    function abrirCorrecao(btn) {
        coBtnAtual = btn;
        coIdEl.value = btn.getAttribute('data-id');
        coTexto.value = btn.getAttribute('data-correcao') || '';
        if (coErro) { coErro.style.display = 'none'; coErro.textContent = ''; }
        if (coModal) coModal.style.display = 'flex';
    }

    function fecharCorrecao() {
        if (coModal) coModal.style.display = 'none';
        coBtnAtual = null;
    }

    if (listaReprovas) {
        listaReprovas.addEventListener('click', function (e) {
            var btn = e.target.closest('.js-abrir-correcao');
            if (btn) abrirCorrecao(btn);
        });
    }

    ['rtd-correcao-close', 'rtd-correcao-cancelar'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('click', fecharCorrecao);
    });
    if (coModal) {
        coModal.addEventListener('click', function (e) { if (e.target === coModal) fecharCorrecao(); });
    }

    if (coSalvar) {
        coSalvar.addEventListener('click', function () {
            if (coErro) { coErro.style.display = 'none'; coErro.textContent = ''; }
            coSalvar.disabled = true;

            var body = new URLSearchParams({
                acao: 'definir_correcao',
                id: coIdEl.value,
                correcao: coTexto.value
            });
            fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(function (r) {
                return r.json().catch(function () { return { sucesso: false, erro: 'Resposta inválida do servidor.' }; });
            }).then(function (res) {
                coSalvar.disabled = false;
                if (!res || !res.sucesso) {
                    if (coErro) { coErro.textContent = (res && res.erro) || 'Erro ao salvar a correção.'; coErro.style.display = 'block'; }
                    return;
                }

                if (coBtnAtual) {
                    var texto = coTexto.value.trim();
                    coBtnAtual.setAttribute('data-correcao', texto);
                    coBtnAtual.textContent = texto ? '✓ Ver / editar correção' : '+ Adicionar correção';
                }
                fecharCorrecao();
            }).catch(function () {
                coSalvar.disabled = false;
                if (coErro) { coErro.textContent = 'Falha de conexão ao salvar a correção.'; coErro.style.display = 'block'; }
            });
        });
    }

    // ─── Excluir uma reprova já registrada (Modal Padrão SGT) ───────────────────
    var excluirModal = document.getElementById('rtd-excluir-modal');
    var btnExcluirConfirmar = document.getElementById('rtd-excluir-confirmar');
    var idReprovaExcluir = null;
    var btnExcluirAtual = null;

    function abrirModalExcluir(id, btn) {
        idReprovaExcluir = id;
        btnExcluirAtual = btn;
        if (excluirModal) excluirModal.style.display = 'flex';
    }

    function fecharModalExcluir() {
        if (excluirModal) excluirModal.style.display = 'none';
        idReprovaExcluir = null;
        btnExcluirAtual = null;
    }

    ['rtd-excluir-close', 'rtd-excluir-cancelar'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('click', fecharModalExcluir);
    });
    if (excluirModal) {
        excluirModal.addEventListener('click', function (e) {
            if (e.target === excluirModal) fecharModalExcluir();
        });
    }

    if (btnExcluirConfirmar) {
        btnExcluirConfirmar.addEventListener('click', function () {
            if (!idReprovaExcluir) return;
            btnExcluirConfirmar.disabled = true;
            btnExcluirConfirmar.textContent = 'Excluindo...';

            var body = new URLSearchParams({ acao: 'excluir', id: idReprovaExcluir });
            fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(function (r) {
                return r.json().catch(function () { return { sucesso: false, erro: 'Resposta inválida do servidor.' }; });
            }).then(function (res) {
                btnExcluirConfirmar.disabled = false;
                btnExcluirConfirmar.textContent = 'Excluir Reprova';

                if (!res || !res.sucesso) {
                    alert((res && res.erro) || 'Erro ao excluir a reprova.');
                    return;
                }

                if (btnExcluirAtual) {
                    var item = btnExcluirAtual.closest('.rtd-item');
                    if (item) item.remove();
                }
                fecharModalExcluir();

                var itensRestantes = listaReprovas ? listaReprovas.querySelectorAll('.rtd-item') : [];
                if (itensRestantes.length === 0) {
                    window.location.href = VOLTAR || 'relacao.php';
                    return;
                }

                // Atualiza o ID na URL para o primeiro item restante ativo, evitando que um reload posterior aponte para o ID excluído
                var btnRestante = itensRestantes[0].querySelector('.js-remover-reprova, .js-abrir-causaraiz, .js-abrir-correcao');
                var novoId = btnRestante ? btnRestante.getAttribute('data-id') : null;
                if (novoId && window.history && window.history.replaceState) {
                    var u = new URL(window.location.href);
                    u.searchParams.set('id', novoId);
                    window.history.replaceState(null, '', u.toString());
                }

                if (subtitleReprovas) {
                    subtitleReprovas.textContent = itensRestantes.length + ' reprova(s) registradas para este N° de série';
                }
            }).catch(function () {
                btnExcluirConfirmar.disabled = false;
                btnExcluirConfirmar.textContent = 'Excluir Reprova';
                alert('Falha de conexão ao excluir a reprova.');
            });
        });
    }

    if (listaReprovas) {
        listaReprovas.addEventListener('click', function (e) {
            var btn = e.target.closest('.js-remover-reprova');
            if (!btn) return;
            var id = btn.getAttribute('data-id');
            abrirModalExcluir(id, btn);
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
            if (novo) {
                var sel = novo.querySelector('.js-reprova-sel');
                if (sel) sel.value = '';
                var dataEl = novo.querySelector('.js-data-reprova');
                if (dataEl) dataEl.value = new Date().toISOString().slice(0, 10);
                var famEl = novo.querySelector('.js-familia');
                if (famEl) famEl.value = '';
                var localEl = novo.querySelector('.js-local');
                if (localEl) localEl.value = '';
                blocosWrap.appendChild(novo);
            }
        });
    }

    /** Fallback caso todos os blocos tenham sido excluídos: recria um a partir do template original. */
    function criarBlocoVazio() {
        var tpl = document.getElementById('rtd-bloco-template');
        return tpl.content.firstElementChild.cloneNode(true);
    }

    // ─── Materiais utilizados: busca no catálogo (itens_catalogo, via acao=
    // buscar_material) + linhas adicionadas na tabela. Cada linha guarda seus
    // dados em inputs hidden (material_codigo[]/material_descricao[]/
    // material_unidade[]/material_qtd[]) dentro do próprio #rtd-form-add, então
    // o submit existente (FormData) já pega tudo sem mudança na lógica de envio.
    (function () {
        var buscaInput   = document.getElementById('rtd-material-busca');
        var sugestoesEl  = document.getElementById('rtd-material-sugestoes');
        var linhasWrap   = document.getElementById('rtd-material-linhas');
        var vazioEl      = document.getElementById('rtd-material-vazio');
        var manualToggle = document.getElementById('rtd-material-manual-toggle');
        var manualWrap   = document.getElementById('rtd-material-manual');
        var manualCodigo = document.getElementById('rtd-material-manual-codigo');
        var manualDesc   = document.getElementById('rtd-material-manual-descricao');
        var manualUnid   = document.getElementById('rtd-material-manual-unidade');
        var manualAdd    = document.getElementById('rtd-material-manual-add');
        var chkNenhum    = document.getElementById('rtd-material-nenhum');
        var exportCsvBtn = document.getElementById('rtd-exportar-csv');
        if (!buscaInput || !sugestoesEl || !linhasWrap) return;

        function escapeHtml(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function atualizarVazio() {
            if (vazioEl) vazioEl.style.display = linhasWrap.children.length ? 'none' : 'block';
        }

        function syncNenhumMaterial() {
            if (!chkNenhum) return;
            var temMateriais = linhasWrap && linhasWrap.children.length > 0;
            if (temMateriais) {
                chkNenhum.checked = false;
                chkNenhum.disabled = true;
            } else {
                chkNenhum.disabled = false;
            }

            var chipLabel = chkNenhum.closest('.rtd-setor-chip') || chkNenhum.parentElement;
            if (chipLabel) {
                chipLabel.classList.toggle('is-disabled', chkNenhum.disabled);
            }

            var nenhumMarcado = chkNenhum.checked;
            buscaInput.disabled = nenhumMarcado;
            if (manualToggle) manualToggle.disabled = nenhumMarcado;
            if (exportCsvBtn) exportCsvBtn.disabled = nenhumMarcado || !temMateriais;
            if (nenhumMarcado) {
                buscaInput.value = '';
                fecharSugestoes();
                if (manualWrap) manualWrap.style.display = 'none';
            }
        }

        if (chkNenhum) {
            chkNenhum.addEventListener('change', syncNenhumMaterial);
        }

        function criarLinha(item) {
            var codigo    = (item.codigo || '').trim();
            var descricao = (item.descricao || '').trim();
            var unidade   = (item.unidade || '').trim();
            var qtd       = item.quantidade != null ? item.quantidade : '';

            var tr = document.createElement('tr');
            tr.className = 'rtd-material-linha';
            tr.setAttribute('data-codigo', codigo);
            tr.innerHTML =
                '<td class="rtd-material-col-codigo"><input type="hidden" name="material_codigo[]" value="' + escapeHtml(codigo) + '">' + escapeHtml(codigo || '—') + '</td>' +
                '<td class="rtd-material-col-desc"><input type="hidden" name="material_descricao[]" value="' + escapeHtml(descricao) + '">' + escapeHtml(descricao) + '</td>' +
                '<td class="rtd-material-col-qtd"><input type="number" name="material_qtd[]" step="0.01" min="0" class="form-control" value="' + escapeHtml(String(qtd)) + '" placeholder="Qtd"></td>' +
                '<td class="rtd-material-col-unid"><input type="hidden" name="material_unidade[]" value="' + escapeHtml(unidade) + '">' + escapeHtml(unidade || '—') + '</td>' +
                '<td class="rtd-material-col-acao"><button type="button" class="rtd-item-del js-remover-material" title="Remover material">✕</button></td>';
            return tr;
        }

        // Selecionar um código já adicionado foca a linha existente em vez de duplicar.
        function adicionarLinha(item) {
            var descricao = (item.descricao || '').trim();
            if (!descricao) return;

            var codigo = (item.codigo || '').trim();
            if (codigo && window.CSS && CSS.escape) {
                var existente = linhasWrap.querySelector('.rtd-material-linha[data-codigo="' + CSS.escape(codigo) + '"]');
                if (existente) {
                    existente.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    var qtdInput = existente.querySelector('.rtd-material-col-qtd input');
                    if (qtdInput) qtdInput.focus();
                    return;
                }
            }

            var tr = linhasWrap.appendChild(criarLinha(item));
            atualizarVazio();
            syncNenhumMaterial();
            var novoQtd = tr.querySelector('.rtd-material-col-qtd input');
            if (novoQtd) novoQtd.focus();
        }

        linhasWrap.addEventListener('click', function (e) {
            var btn = e.target.closest('.js-remover-material');
            if (!btn) return;
            var linha = btn.closest('.rtd-material-linha');
            if (linha) linha.remove();
            atualizarVazio();
            syncNenhumMaterial();
        });

        // ─── Busca com debounce + dropdown de sugestões ────────────────────
        var debounceTimer = null;

        function fecharSugestoes() {
            sugestoesEl.style.display = 'none';
            sugestoesEl.innerHTML = '';
        }

        function renderSugestoes(itens, termoBuscado) {
            // Resposta desatualizada (usuário já digitou outra coisa antes de chegar) — descarta.
            if (buscaInput.value.trim() !== termoBuscado) return;

            sugestoesEl.innerHTML = '';
            if (!itens.length) {
                var vazio = document.createElement('div');
                vazio.className = 'rtd-material-sugestao is-vazio';
                vazio.textContent = 'Nenhum material encontrado no catálogo para este termo.';
                sugestoesEl.appendChild(vazio);
            } else {
                var header = document.createElement('div');
                header.className = 'rtd-material-sugestoes-header';
                header.innerHTML = '<span class="mat-col-cod">Código</span><span class="mat-col-desc">Descrição</span><span class="mat-col-unid">Unidade</span>';
                sugestoesEl.appendChild(header);

                itens.forEach(function (item) {
                    var el = document.createElement('div');
                    el.className = 'rtd-material-sugestao';
                    el.innerHTML = '<span class="mat-codigo">' + escapeHtml(item.codigo || '—') + '</span>' +
                                   '<span class="mat-desc" title="' + escapeHtml(item.descricao || '') + '">' + escapeHtml(item.descricao || '') + '</span>' +
                                   '<span class="mat-unid">' + escapeHtml(item.unidade || 'UN') + '</span>';
                    el.addEventListener('click', function () {
                        adicionarLinha(item);
                        buscaInput.value = '';
                        fecharSugestoes();
                    });
                    sugestoesEl.appendChild(el);
                });
            }
            sugestoesEl.style.display = 'block';
        }

        buscaInput.addEventListener('keydown', function (e) {
            var items = sugestoesEl.querySelectorAll('.rtd-material-sugestao:not(.is-vazio)');
            if (!items.length || sugestoesEl.style.display === 'none') return;

            var active = sugestoesEl.querySelector('.rtd-material-sugestao.is-active');
            var index = -1;
            for (var i = 0; i < items.length; i++) {
                if (items[i] === active) { index = i; break; }
            }

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (active) active.classList.remove('is-active');
                var nextIndex = index < items.length - 1 ? index + 1 : 0;
                items[nextIndex].classList.add('is-active');
                items[nextIndex].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (active) active.classList.remove('is-active');
                var prevIndex = index > 0 ? index - 1 : items.length - 1;
                items[prevIndex].classList.add('is-active');
                items[prevIndex].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'Enter') {
                if (active) {
                    e.preventDefault();
                    active.click();
                }
            } else if (e.key === 'Escape') {
                fecharSugestoes();
            }
        });

        buscaInput.addEventListener('input', function () {
            var termo = buscaInput.value.trim();
            if (debounceTimer) clearTimeout(debounceTimer);
            if (!termo) { fecharSugestoes(); return; }

            debounceTimer = setTimeout(function () {
                var body = new URLSearchParams({ acao: 'buscar_material', termo: termo });
                fetch(API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                }).then(function (r) {
                    return r.json().catch(function () { return { sucesso: false, itens: [] }; });
                }).then(function (res) {
                    renderSugestoes((res && res.sucesso) ? (res.itens || []) : [], termo);
                }).catch(function () {
                    renderSugestoes([], termo);
                });
            }, 250);
        });

        document.addEventListener('click', function (e) {
            if (e.target === buscaInput || sugestoesEl.contains(e.target)) return;
            fecharSugestoes();
        });

        // ─── Fallback manual: material fora do catálogo (Item.csv) ─────────
        if (manualToggle && manualWrap) {
            manualToggle.addEventListener('click', function () {
                var abrir = manualWrap.style.display === 'none';
                manualWrap.style.display = abrir ? 'block' : 'none';
                if (abrir && manualDesc) manualDesc.focus();
            });
        }

        if (manualAdd) {
            manualAdd.addEventListener('click', function () {
                var descricao = (manualDesc.value || '').trim();
                if (!descricao) { manualDesc.focus(); return; }
                adicionarLinha({
                    codigo: manualCodigo ? manualCodigo.value : '',
                    descricao: descricao,
                    unidade: manualUnid ? manualUnid.value : '',
                    quantidade: ''
                });
                if (manualCodigo) manualCodigo.value = '';
                manualDesc.value = '';
                if (manualUnid) manualUnid.value = '';
                if (manualWrap) manualWrap.style.display = 'none';
            });
        }

        atualizarVazio();
        syncNenhumMaterial();
    }());

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

            // Toda reprova já registrada (existente antes deste envio) precisa ter causa
            // raiz preenchida — é o que finaliza ela ao reenviar a Triagem. Reprovas novas
            // adicionadas nesta mesma tela ainda não têm causa raiz (não têm id/popup até
            // serem gravadas), então ficam de fora desta checagem — valem na próxima visita.
            var reprovaSemCausaRaiz = listaReprovas && Array.prototype.find.call(
                listaReprovas.querySelectorAll('.js-abrir-causa-raiz'),
                function (btn) { return !(btn.getAttribute('data-causa-raiz') || '').trim(); }
            );
            if (reprovaSemCausaRaiz) {
                mostraErro('Preencha a causa da reprova de todas as reprovas registradas (botão "+ Adicionar causa da reprova") antes de enviar.');
                reprovaSemCausaRaiz.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                return;
            }

            var reprovaSemCorrecao = listaReprovas && Array.prototype.find.call(
                listaReprovas.querySelectorAll('.js-abrir-correcao'),
                function (btn) { return !(btn.getAttribute('data-correcao') || '').trim(); }
            );
            if (reprovaSemCorrecao) {
                mostraErro('Preencha a correção de todas as reprovas registradas (botão "+ Adicionar correção") antes de enviar.');
                reprovaSemCorrecao.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                return;
            }

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
                    primeiroSelect.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    primeiroSelect.focus();
                }
                return;
            }

            if (!qrInicioConfirmadoNestaSessao) {
                mostraErro('Escaneie o QR Code do transformador (botão "Escanear QR" acima) para confirmar o início antes de enviar.');
                var scanBtnEl = document.getElementById('rtd-inicio-scan-btn');
                if (scanBtnEl) scanBtnEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                return;
            }

            var linhasMateriais = document.getElementById('rtd-material-linhas');
            var chkNenhum = document.getElementById('rtd-material-nenhum');
            var temMateriais = linhasMateriais && linhasMateriais.children.length > 0;
            var marcouNenhum = chkNenhum && chkNenhum.checked;
            if (!temMateriais && !marcouNenhum) {
                mostraErro('Adicione os materiais utilizados ou marque "Nenhum material foi utilizado".');
                if (chkNenhum) chkNenhum.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                return;
            }

            var temSetor = formAdd.querySelector('input[name="setores_destino[]"]:checked');
            if (!temSetor) {
                mostraErro('Selecione ao menos um setor em "Próximos setores" antes de enviar.');
                var setoresWrap = formAdd.querySelector('.rtd-setores');
                if (setoresWrap) setoresWrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
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
        if (!overlay || !display) return;

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
        var showManualBtn  = document.getElementById('rtd-inicio-show-manual-btn');
        var manualInputRow = document.getElementById('rtd-inicio-manual-row');
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
                    video.setAttribute('playsinline', true);
                    video.play();
                    stage.classList.add('has-video');
                    requestAnimationFrame(tick);
                })
                .catch(function () {
                    stageEmptyText.textContent = 'Não foi possível acessar a câmera.';
                    stage.classList.remove('has-video');
                });
        }

        function stopCamera() {
            if (mediaStream) {
                mediaStream.getTracks().forEach(function (t) { t.stop(); });
                mediaStream = null;
                video.srcObject = null;
                stage.classList.remove('has-video');
            }
        }

        function tick() {
            if (!mediaStream) return;
            if (video.readyState === video.HAVE_ENOUGH_DATA) scanFrame();
            requestAnimationFrame(tick);
        }

        function flashViewfinder(ok) {
            viewfinder.classList.add(ok ? 'is-success' : 'is-error');
            if (navigator.vibrate) navigator.vibrate(ok ? 90 : [60, 40, 60]);
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

        function abrirOverlay() {
            overlay.style.display = 'flex';
            locked = false;
            clearOverlayError();
            if (showManualBtn && manualInputRow) {
                showManualBtn.style.display = 'flex';
                manualInputRow.style.display = 'none';
            }
            viewfinder.classList.remove('is-success', 'is-error');
            hint.textContent = 'Aponte a câmera para o QR Code do transformador';
            startCamera();
        }

        function fecharOverlay(isSuccess) {
            stopCamera();
            overlay.style.display = 'none';
            if (isSuccess !== true && !qrInicioConfirmadoNestaSessao) {
                window.location.href = VOLTAR || 'relacao.php';
            }
        }

        function cancelarOverlay(e) {
            if (e && typeof e.preventDefault === 'function') e.preventDefault();
            fecharOverlay(false);
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
                    qrInicioConfirmadoNestaSessao = true;
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
                    setTimeout(function () { fecharOverlay(true); }, 900);
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

        if (scanBtn) scanBtn.addEventListener('click', abrirOverlay);
        if (closeBtn) closeBtn.addEventListener('click', cancelarOverlay);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) cancelarOverlay(e); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlay && overlay.style.display === 'flex') {
                cancelarOverlay(e);
            }
        });

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
        
        if (display && display.value.trim() === '') {
            abrirOverlay();
        }
    }());

    // ─── Exportar materiais para CSV ───────────────────────────────────────────
    var exportCsvBtn = document.getElementById('rtd-exportar-csv');
    if (exportCsvBtn) {
        exportCsvBtn.addEventListener('click', function () {
            var linhas = document.querySelectorAll('#rtd-material-linhas .rtd-material-linha');
            if (linhas.length === 0) {
                if (window.app && typeof window.app.toast === 'function') {
                    window.app.toast('Nenhum material adicionado para exportar.', 'error');
                } else {
                    alert('Nenhum material adicionado para exportar.');
                }
                return;
            }
            
            var csv = 'Código,Quantidade\n';
            for (var i = 0; i < linhas.length; i++) {
                var codInput = linhas[i].querySelector('input[name="material_codigo[]"]');
                var qtdInput = linhas[i].querySelector('input[name="material_qtd[]"]');
                
                var cod = codInput ? codInput.value.trim() : '';
                var qtd = qtdInput ? qtdInput.value.trim() : '0';
                
                csv += '"' + cod.replace(/"/g, '""') + '","' + qtd.replace(/"/g, '""') + '"\n';
            }
            
            var blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.style.display = 'none';
            a.href = url;
            a.download = 'materiais_' + (window.RETRABALHO_NS || 'export') + '.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        });
    }

}());
