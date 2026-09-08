(function () {
    'use strict';

    var API = window.RETORNOS_API || '';
    var catalogo = window.RETORNOS_REPROVAS_CATALOGO || [];
    var reprovasPorItem = window.RETORNOS_REPROVAS_ITENS || {};

    function postAcao(payload) {
        var body = new URLSearchParams();
        for (var key in payload) {
            if (Object.prototype.hasOwnProperty.call(payload, key)) {
                var val = payload[key];
                if (Array.isArray(val)) {
                    val.forEach(function (v) {
                        body.append(key + '[]', v);
                    });
                } else if (val !== null && val !== undefined) {
                    body.append(key, val);
                }
            }
        }

        return fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (r) {
            return r.json().catch(function () {
                return { sucesso: false, erro: 'Resposta inválida do servidor.' };
            });
        });
    }

    // ─── Expandir/recolher reprovas por trás do "+" & Expandir Todos ───────────
    // ─── Expandir/recolher reprovas por trás do "+" & Expandir Todos ───────────
    // Limpa chaves legadas de persistência para sempre iniciar com as linhas recolhidas
    try {
        localStorage.removeItem('sgt_retornos_expand_all');
        localStorage.removeItem('sgt_retornos_open_rows');
    } catch (e) {}

    function syncHeaderButtons(expandAll) {
        var btnAll = document.getElementById('btn-toggle-all-retornos');
        if (btnAll) {
            btnAll.setAttribute('aria-expanded', expandAll ? 'true' : 'false');
            btnAll.classList.toggle('is-active', expandAll);
            var lbl = btnAll.querySelector('.lbl-expand');
            if (lbl) lbl.textContent = expandAll ? 'Recolher Todos' : 'Expandir Todos';
        }
        var quickBtn = document.querySelector('.js-toggle-all-quick');
        if (quickBtn) {
            // Ícone gira via CSS a partir de aria-expanded (ver .btn-expand-col) —
            // não mexe no conteúdo do botão (é um SVG, não texto).
            quickBtn.setAttribute('aria-expanded', expandAll ? 'true' : 'false');
            quickBtn.title = expandAll ? 'Recolher todos' : 'Expandir todos';
        }
    }

    function setAllRows(expand) {
        syncHeaderButtons(expand);
        document.querySelectorAll('.js-toggle-retorno').forEach(function(btn) {
            var targetId = btn.dataset.target;
            var row = document.getElementById(targetId);
            if (!row) return;

            if (expand) {
                row.classList.add('is-open');
                btn.setAttribute('aria-expanded', 'true');
            } else {
                row.classList.remove('is-open');
                btn.setAttribute('aria-expanded', 'false');
            }
        });
    }

    document.addEventListener('click', function (e) {
        var btnAll = e.target.closest('#btn-toggle-all-retornos') || e.target.closest('.js-toggle-all-quick');
        if (btnAll) {
            var isCurrentlyExpanded = btnAll.classList.contains('is-active') || btnAll.getAttribute('aria-expanded') === 'true';
            setAllRows(!isCurrentlyExpanded);
            return;
        }

        var btn = e.target.closest('.js-toggle-retorno');
        if (!btn) return;
        var targetId = btn.dataset.target;
        var row = document.getElementById(targetId);
        if (!row) return;
        var aberto = row.classList.toggle('is-open');
        btn.setAttribute('aria-expanded', aberto ? 'true' : 'false');

        // Se alguma linha for fechada manualmente, desmarca o botão de "Expandir Todos"
        if (!aberto) {
            syncHeaderButtons(false);
        }
    });

    // ─── Aprovado: ação direta ────────────────────────────────────────────────
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-aprovar-retorno');
        if (!btn) return;
        var d = btn.dataset;

        btn.disabled = true;
        postAcao({ acao: 'aprovar_retorno', id_projeto: d.id_projeto, ns_transformador: d.ns_transformador })
            .then(function (res) {
                if (res && res.sucesso) {
                    window.location.reload();
                } else {
                    if (typeof window.showAlert === 'function') window.showAlert((res && res.erro) || 'Erro ao aprovar retorno.', 'danger');
                    else alert((res && res.erro) || 'Erro ao aprovar retorno.');
                    btn.disabled = false;
                }
            })
            .catch(function () {
                if (typeof window.showAlert === 'function') window.showAlert('Falha de conexão.', 'danger');
                else alert('Falha de conexão.');
                btn.disabled = false;
            });
    });

    // ─── Reprovação: Modais e Fluxo ──────────────────────────────────────────
    var miniModal       = document.getElementById('ret-reprovar-modal');
    var miniNsEl        = document.getElementById('ret-reprovar-ns');
    var miniCloseBtn    = document.getElementById('ret-reprovar-close');
    var btnReincidencia = document.getElementById('ret-btn-reincidencia');
    var btnNovaReprova  = document.getElementById('ret-btn-nova-reprova');

    var grandeModal     = document.getElementById('ret-nova-reprova-modal');
    var grandeTitulo    = document.getElementById('ret-rep-modal-title');
    var grandeNsDisp    = document.getElementById('ret-rep-ns-display');
    var grandeProjDisp  = document.getElementById('ret-rep-projeto-display');
    var grandeIdProj    = document.getElementById('ret-rep-id_projeto');
    var grandeNs        = document.getElementById('ret-rep-ns');
    var grandeCloseBtn  = document.getElementById('ret-nova-reprova-close');
    var grandeCancelBtn = document.getElementById('ret-nova-reprova-cancelar');
    var grandeSubmitBtn = document.getElementById('ret-nova-reprova-confirmar');
    var grandeForm      = document.getElementById('ret-rep-form');
    var grandeList      = document.getElementById('ret-rep-reprovas-list');
    var grandeAddBtn    = document.getElementById('ret-rep-add-reprova');
    var grandeErro      = document.getElementById('ret-rep-form-erro');
    var template        = document.getElementById('ret-rep-reprova-template');

    var itemPendente    = null; // { id_projeto, ns_transformador, projeto_codigo, projeto_descricao, pedido_numero }

    function fecharMiniModal() {
        if (miniModal) miniModal.style.display = 'none';
    }

    function fecharGrandeModal() {
        if (grandeModal) grandeModal.style.display = 'none';
        if (grandeErro) grandeErro.style.display = 'none';
        if (grandeList) grandeList.innerHTML = '';
        if (grandeSubmitBtn) {
            grandeSubmitBtn.disabled = false;
            grandeSubmitBtn.textContent = 'Confirmar Reprovação';
        }
    }

    // Clique no botão "Reprovar" da tabela
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-reprovar-retorno');
        if (!btn) return;
        var d = btn.dataset;
        itemPendente = {
            id_projeto: d.id_projeto,
            ns_transformador: d.ns_transformador,
            projeto_codigo: d.projeto_codigo,
            projeto_descricao: d.projeto_descricao,
            pedido_numero: d.pedido_numero
        };

        if (miniNsEl) miniNsEl.textContent = itemPendente.ns_transformador;
        if (miniModal) miniModal.style.display = 'flex';
    });

    if (miniCloseBtn) miniCloseBtn.addEventListener('click', fecharMiniModal);
    if (miniModal) {
        miniModal.addEventListener('click', function (e) {
            if (e.target === miniModal) fecharMiniModal();
        });
    }

    // Caso 1: Reincidência -> Abre modal preenchido com as reprovas anteriores + permite adicionar novas
    if (btnReincidencia) {
        btnReincidencia.addEventListener('click', function () {
            fecharMiniModal();
            if (!itemPendente || !grandeModal) return;

            if (grandeTitulo) grandeTitulo.textContent = 'Reincidência de Reprovação no Retorno';
            if (grandeNsDisp) grandeNsDisp.textContent = itemPendente.ns_transformador;
            if (grandeProjDisp) grandeProjDisp.textContent = (itemPendente.projeto_codigo || '—') + (itemPendente.projeto_descricao ? ' (' + itemPendente.projeto_descricao + ')' : '');
            if (grandeIdProj) grandeIdProj.value = itemPendente.id_projeto;
            if (grandeNs) grandeNs.value = itemPendente.ns_transformador;

            var chave = itemPendente.id_projeto + '|' + itemPendente.ns_transformador;
            var reprovasAnteriores = reprovasPorItem[chave] || [];

            if (grandeList) {
                grandeList.innerHTML = '';
                if (reprovasAnteriores.length > 0) {
                    reprovasAnteriores.forEach(function (rep) {
                        var idVal = rep.id_reprova || rep.reprova_id;
                        var itemObj = {
                            id: idVal,
                            codigo: rep.reprova_codigo || '',
                            descricao: rep.reprova_descricao || '',
                            familia: rep.reprova_familia || '—',
                            local: rep.reprova_local || '—'
                        };
                        adicionarLinhaReprova(itemObj);
                    });
                } else {
                    adicionarLinhaReprova();
                }
            }

            grandeModal.style.display = 'flex';
        });
    }

    // Caso 2: Nova Reprova -> Abre modal limpo para cadastrar nova(s) reprova(s)
    if (btnNovaReprova) {
        btnNovaReprova.addEventListener('click', function () {
            fecharMiniModal();
            if (!itemPendente || !grandeModal) return;

            if (grandeTitulo) grandeTitulo.textContent = 'Nova Reprovação no Retorno';
            if (grandeNsDisp) grandeNsDisp.textContent = itemPendente.ns_transformador;
            if (grandeProjDisp) grandeProjDisp.textContent = (itemPendente.projeto_codigo || '—') + (itemPendente.projeto_descricao ? ' (' + itemPendente.projeto_descricao + ')' : '');
            if (grandeIdProj) grandeIdProj.value = itemPendente.id_projeto;
            if (grandeNs) grandeNs.value = itemPendente.ns_transformador;

            if (grandeList) {
                grandeList.innerHTML = '';
                adicionarLinhaReprova(); // Linha limpa inicial
            }

            grandeModal.style.display = 'flex';
        });
    }

    if (grandeCloseBtn) grandeCloseBtn.addEventListener('click', fecharGrandeModal);
    if (grandeCancelBtn) grandeCancelBtn.addEventListener('click', fecharGrandeModal);
    if (grandeModal) {
        grandeModal.addEventListener('click', function (e) {
            if (e.target === grandeModal) fecharGrandeModal();
        });
    }

    function configurarCombobox(comboboxEl, hiddenInput, onSelect) {
        var input = comboboxEl.querySelector('.rep-search-input');
        var toggle = comboboxEl.querySelector('.rep-search-toggle');
        var dropdown = comboboxEl.querySelector('.rep-search-dropdown');
        if (!input || !dropdown) return;

        function renderLista(termo) {
            dropdown.innerHTML = '';
            var t = (termo || '').toLowerCase().trim();
            var filtrados = catalogo.filter(function (item) {
                if (!t) return true;
                return (item.codigo && item.codigo.toLowerCase().indexOf(t) !== -1) ||
                       (item.descricao && item.descricao.toLowerCase().indexOf(t) !== -1) ||
                       (item.familia && item.familia.toLowerCase().indexOf(t) !== -1);
            });

            if (filtrados.length === 0) {
                var vazio = document.createElement('div');
                vazio.style.padding = '8px 10px';
                vazio.style.fontSize = '12px';
                vazio.style.color = '#9ca3af';
                vazio.textContent = 'Nenhuma reprova encontrada';
                dropdown.appendChild(vazio);
                return;
            }

            filtrados.forEach(function (item) {
                var div = document.createElement('div');
                div.className = 'rep-item';
                div.innerHTML = '<div><strong class="font-mono font-600">' + escapeHtml(item.codigo) + '</strong> <span style="color:#6b7280;">— ' + escapeHtml(item.descricao || '') + '</span></div>' +
                                '<span class="badge badge-neutral font-mono" style="font-size:10px;">' + escapeHtml(item.familia || 'GERAL') + '</span>';
                div.addEventListener('click', function () {
                    input.value = item.codigo + (item.descricao ? ' — ' + item.descricao : '');
                    hiddenInput.value = item.id;
                    fecharDropdown();
                    if (typeof onSelect === 'function') onSelect(item);
                });
                dropdown.appendChild(div);
            });
        }

        function abrirDropdown() {
            renderLista(input.value);
            dropdown.style.display = 'block';
            comboboxEl.classList.add('is-open');
        }

        function fecharDropdown() {
            dropdown.style.display = 'none';
            comboboxEl.classList.remove('is-open');
        }

        input.addEventListener('focus', abrirDropdown);
        input.addEventListener('input', function () {
            hiddenInput.value = '';
            abrirDropdown();
        });

        if (toggle) {
            toggle.addEventListener('click', function (e) {
                e.stopPropagation();
                if (dropdown.style.display === 'block') fecharDropdown();
                else { input.focus(); abrirDropdown(); }
            });
        }

        document.addEventListener('click', function (e) {
            if (!comboboxEl.contains(e.target)) fecharDropdown();
        });
    }

    function escapeHtml(str) {
        return (str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function adicionarLinhaReprova(itemPreenchido) {
        if (!template || !grandeList) return;
        var clone = template.content.cloneNode(true);
        var block = clone.querySelector('.lst-reprova-block');
        var combobox = block.querySelector('.rep-search-combobox');
        var input = combobox.querySelector('.rep-search-input');
        var hiddenInput = block.querySelector('.rep-reprova-select');
        var removeBtn = block.querySelector('.lst-reprova-remove');
        var famField = block.querySelector('.rep-familia-field');
        var locField = block.querySelector('.rep-local-field');

        configurarCombobox(combobox, hiddenInput, function (item) {
            if (famField) famField.textContent = item.familia || '—';
            if (locField) locField.textContent = item.local || '—';
        });

        if (itemPreenchido && itemPreenchido.id) {
            input.value = itemPreenchido.codigo + (itemPreenchido.descricao ? ' — ' + itemPreenchido.descricao : '');
            hiddenInput.value = itemPreenchido.id;
            if (famField) famField.textContent = itemPreenchido.familia || '—';
            if (locField) locField.textContent = itemPreenchido.local || '—';
        }

        if (removeBtn) {
            removeBtn.addEventListener('click', function () {
                block.remove();
                atualizarBotoesRemover();
            });
        }

        grandeList.appendChild(clone);
        atualizarBotoesRemover();
    }

    function atualizarBotoesRemover() {
        var blocks = grandeList.querySelectorAll('.lst-reprova-block');
        blocks.forEach(function (b) {
            var btn = b.querySelector('.lst-reprova-remove');
            if (btn) btn.style.display = blocks.length > 1 ? 'flex' : 'none';
        });
    }

    if (grandeAddBtn) grandeAddBtn.addEventListener('click', function () {
        adicionarLinhaReprova();
    });

    if (grandeForm) {
        grandeForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (grandeErro) grandeErro.style.display = 'none';

            var idReprovas = [];
            grandeList.querySelectorAll('.rep-reprova-select').forEach(function (input) {
                if (input.value) idReprovas.push(input.value);
            });

            if (idReprovas.length === 0) {
                if (grandeErro) {
                    grandeErro.textContent = 'Selecione pelo menos um motivo de reprovação.';
                    grandeErro.style.display = 'block';
                }
                return;
            }

            grandeSubmitBtn.disabled = true;
            grandeSubmitBtn.textContent = 'Enviando…';

            postAcao({
                acao: 'reprovar_retorno',
                id_projeto: grandeIdProj.value,
                ns_transformador: grandeNs.value,
                id_reprova: idReprovas
            })
            .then(function (res) {
                if (res && res.sucesso) {
                    window.location.reload();
                } else {
                    if (grandeErro) {
                        grandeErro.textContent = (res && res.erro) || 'Erro ao registrar reprova no retorno.';
                        grandeErro.style.display = 'block';
                    }
                    grandeSubmitBtn.disabled = false;
                    grandeSubmitBtn.textContent = 'Confirmar Reprovação';
                }
            })
            .catch(function () {
                if (grandeErro) {
                    grandeErro.textContent = 'Falha de comunicação com o servidor.';
                    grandeErro.style.display = 'block';
                }
                grandeSubmitBtn.disabled = false;
                grandeSubmitBtn.textContent = 'Confirmar Reprovação';
            });
        });
    }

    // ─── Excluir Retorno (Lixeira) ──────────────────────────────────────────
    var excluirModal        = document.getElementById('ret-excluir-modal');
    var excluirNsEl         = document.getElementById('ret-excluir-ns');
    var excluirCloseBtn     = document.getElementById('ret-excluir-close');
    var excluirCancelBtn    = document.getElementById('ret-excluir-cancelar');
    var excluirConfirmarBtn = document.getElementById('ret-excluir-confirmar');
    var itemExcluirPendente = null;

    function fecharExcluirModal() {
        if (excluirModal) excluirModal.style.display = 'none';
        itemExcluirPendente = null;
        if (excluirConfirmarBtn) {
            excluirConfirmarBtn.disabled = false;
            excluirConfirmarBtn.textContent = 'Excluir Retorno';
        }
    }

    if (excluirModal) {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.js-excluir-retorno');
            if (!btn) return;
            var d = btn.dataset;
            itemExcluirPendente = {
                id_projeto: d.id_projeto,
                ns_transformador: d.ns_transformador
            };
            if (excluirNsEl) excluirNsEl.textContent = itemExcluirPendente.ns_transformador;
            excluirModal.style.display = 'flex';
        });

        if (excluirCloseBtn) excluirCloseBtn.addEventListener('click', fecharExcluirModal);
        if (excluirCancelBtn) excluirCancelBtn.addEventListener('click', fecharExcluirModal);
        excluirModal.addEventListener('click', function (e) {
            if (e.target === excluirModal) fecharExcluirModal();
        });

        if (excluirConfirmarBtn) {
            excluirConfirmarBtn.addEventListener('click', function () {
                if (!itemExcluirPendente) return;
                excluirConfirmarBtn.disabled = true;
                excluirConfirmarBtn.textContent = 'Excluindo…';

                postAcao({
                    acao: 'excluir_retorno',
                    id_projeto: itemExcluirPendente.id_projeto,
                    ns_transformador: itemExcluirPendente.ns_transformador
                })
                .then(function (res) {
                    if (res && res.sucesso) {
                        window.location.reload();
                    } else {
                        var err = (res && res.erro) || 'Erro ao excluir retorno.';
                        if (typeof window.showAlert === 'function') window.showAlert(err, 'danger');
                        else alert(err);
                        excluirConfirmarBtn.disabled = false;
                        excluirConfirmarBtn.textContent = 'Excluir Retorno';
                    }
                })
                .catch(function () {
                    if (typeof window.showAlert === 'function') window.showAlert('Falha de conexão.', 'danger');
                    else alert('Falha de conexão.');
                    excluirConfirmarBtn.disabled = false;
                    excluirConfirmarBtn.textContent = 'Excluir Retorno';
                });
            });
        }
    }

    // ─── Fechar modais ao pressionar tecla ESC ──────────────────────────────
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            if (excluirModal && excluirModal.style.display === 'flex') fecharExcluirModal();
            else if (miniModal && miniModal.style.display === 'flex') fecharMiniModal();
            else if (grandeModal && grandeModal.style.display === 'flex') fecharGrandeModal();
        }
    });
}());

