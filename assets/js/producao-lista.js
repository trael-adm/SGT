(function () {
    'use strict';

    var API          = window.LISTA_API || '';
    var PRODUCAO_API = window.LISTA_PRODUCAO_API || '';
    var REPROVAS     = window.LISTA_REPROVAS || [];

    var LOCAL_LABEL = { IQF: 'IQF — Inspeção final', LAB: 'LAB — Laboratório', RET: 'RET — Retrabalho', GER: 'GER — Geral' };

    function escapeHtml(str) {
        return (str || '').replace(/[&<>"']/g, function (m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
        });
    }

    function removeAccents(str) {
        return (str || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    }

    function notify(msg, type) {
        if (typeof window.showAlert === 'function') window.showAlert(msg, type || 'info');
        else alert(msg);
    }

    function postJson(url, payload) {
        var body = new URLSearchParams(payload);
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (r) {
            return r.json().catch(function () {
                return { sucesso: false, erro: 'Resposta inválida do servidor.' };
            });
        });
    }

    function postAcao(payload) { return postJson(API, payload); }
    function postProducaoAcao(payload) { return postJson(PRODUCAO_API, payload); }

    var modal        = document.getElementById('rep-modal');
    var form         = document.getElementById('rep-form');
    var erroEl       = document.getElementById('rep-form-erro');
    var submitBtn    = document.getElementById('rep-btn-submit') || document.getElementById('rep-form-submit');
    var reprovasList = document.getElementById('rep-reprovas-list');
    var addBtn       = document.getElementById('rep-add-reprova-btn') || document.getElementById('rep-add-reprova');
    var template     = document.getElementById('rep-reprova-template');

    if (!modal || !form || !reprovasList || !template) return;

    function openModal()  { modal.style.display = 'flex'; }
    function closeModal() { modal.style.display = 'none'; }

    function mostraErro(msg) {
        if (!erroEl) {
            erroEl = document.createElement('div');
            erroEl.id = 'rep-form-erro';
            erroEl.style.cssText = 'background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:8px 12px;border-radius:8px;font-size:12px;margin-bottom:12px;';
            form.querySelector('.modal-body').insertBefore(erroEl, form.querySelector('.modal-body').firstChild);
        }
        erroEl.textContent = msg;
        erroEl.style.display = 'block';
    }

    function limpaErro() {
        if (erroEl) {
            erroEl.textContent = '';
            erroEl.style.display = 'none';
        }
    }

    // ─── Preencher campos automáticos do bloco de reprova ────────────────────────
    function preencherReprovaBlock(block, idReprova) {
        var r = REPROVAS.filter(function (x) { return String(x.id) === String(idReprova); })[0];
        var infoFam = block.querySelector('.rep-info-familia');
        var infoLoc = block.querySelector('.rep-info-local');
        var detWrap = block.querySelector('.lst-reprova-detalhes');

        if (infoFam) infoFam.textContent = r ? (r.familia || '—') : '—';
        if (infoLoc) infoLoc.textContent = r ? (LOCAL_LABEL[r.local] || r.local || '—') : '—';
        if (detWrap) detWrap.style.display = r ? 'block' : 'none';
    }

    // ─── Combobox com filtro em tempo real por partes do código/descrição ─────────
    function initReprovaCombobox(block) {
        var wrap      = block.querySelector('.rep-search-combobox');
        if (!wrap) return;
        var input     = wrap.querySelector('.rep-search-input');
        var hidden    = wrap.querySelector('.rep-hidden-id') || wrap.querySelector('.rep-reprova-select');
        var toggleBtn = wrap.querySelector('.rep-search-toggle');
        var dropdown  = wrap.querySelector('.rep-search-dropdown');
        var activeIdx = -1;
        var currentFiltered = [];

        function renderList(query) {
            var qNorm = removeAccents(query);
            currentFiltered = REPROVAS.filter(function (r) {
                if (!qNorm) return true;
                var cNorm = removeAccents(r.codigo || '');
                var dNorm = removeAccents(r.descricao || '');
                var fNorm = removeAccents(r.familia || '');
                return cNorm.indexOf(qNorm) !== -1 || dNorm.indexOf(qNorm) !== -1 || fNorm.indexOf(qNorm) !== -1;
            });

            if (!currentFiltered.length) {
                dropdown.innerHTML = '<div class="rep-empty-item" style="padding:12px;text-align:center;color:#9ca3af;font-size:12px;">Nenhuma reprovação encontrada</div>';
                activeIdx = -1;
                return;
            }

            var html = '';
            currentFiltered.forEach(function (r, idx) {
                var isSel = String(hidden.value) === String(r.id);
                var isAct = idx === activeIdx;
                html += '<div class="rep-item' + (isSel ? ' is-selected' : '') + (isAct ? ' is-active' : '') + '" data-id="' + r.id + '" data-idx="' + idx + '" style="padding:8px 10px;border-radius:6px;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:8px;font-size:12px;">' +
                    '<div style="display:flex;align-items:center;gap:8px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' +
                        '<span style="font-family:\'JetBrains Mono\',monospace;font-weight:700;color:#111827;background:#e5e7eb;padding:2px 6px;border-radius:4px;font-size:11px;flex-shrink:0;">' + escapeHtml(r.codigo) + '</span>' +
                        '<span style="font-weight:500;color:#374151;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escapeHtml(r.descricao) + '</span>' +
                    '</div>' +
                    (r.familia ? '<span style="font-size:10px;font-weight:600;padding:2px 6px;border-radius:9999px;background:#f0fdf4;color:#16a34a;white-space:nowrap;flex-shrink:0;">' + escapeHtml(r.familia) + '</span>' : '') +
                '</div>';
            });
            dropdown.innerHTML = html;
        }

        function openDropdown() {
            document.querySelectorAll('.rep-search-combobox.is-open').forEach(function (other) {
                if (other !== wrap) {
                    other.classList.remove('is-open');
                    var dd = other.querySelector('.rep-search-dropdown');
                    if (dd) dd.style.display = 'none';
                }
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
                var sel = REPROVAS.filter(function (r) { return String(r.id) === String(hidden.value); })[0];
                if (sel) input.value = sel.codigo + ' — ' + sel.descricao;
            } else {
                input.value = '';
            }
        }

        function selectItem(r) {
            hidden.value = r.id;
            input.value = r.codigo + ' — ' + r.descricao;
            closeDropdown();
            preencherReprovaBlock(block, r.id);
        }

        input.addEventListener('focus', function () {
            openDropdown();
            input.select();
        });

        input.addEventListener('input', function () {
            if (!wrap.classList.contains('is-open')) {
                wrap.classList.add('is-open');
                dropdown.style.display = 'block';
            }
            hidden.value = '';
            preencherReprovaBlock(block, null);
            activeIdx = 0;
            renderList(input.value);
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!wrap.classList.contains('is-open')) { openDropdown(); return; }
                if (currentFiltered.length > 0) {
                    activeIdx = (activeIdx + 1) % currentFiltered.length;
                    renderList(input.value);
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (!wrap.classList.contains('is-open')) { openDropdown(); return; }
                if (currentFiltered.length > 0) {
                    activeIdx = (activeIdx - 1 + currentFiltered.length) % currentFiltered.length;
                    renderList(input.value);
                }
            } else if (e.key === 'Enter') {
                if (wrap.classList.contains('is-open') && currentFiltered.length > 0) {
                    e.preventDefault();
                    var chosen = activeIdx >= 0 ? currentFiltered[activeIdx] : currentFiltered[0];
                    if (chosen) selectItem(chosen);
                }
            } else if (e.key === 'Escape') {
                closeDropdown();
            }
        });

        if (toggleBtn) {
            toggleBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (wrap.classList.contains('is-open')) closeDropdown();
                else { input.focus(); openDropdown(); }
            });
        }

        dropdown.addEventListener('mousedown', function (e) {
            var itemEl = e.target.closest('.rep-item');
            if (!itemEl) return;
            var id = itemEl.getAttribute('data-id');
            var chosen = REPROVAS.filter(function (r) { return String(r.id) === String(id); })[0];
            if (chosen) selectItem(chosen);
        });
    }

    // Fecha dropdown ao clicar fora
    document.addEventListener('click', function (e) {
        document.querySelectorAll('.rep-search-combobox.is-open').forEach(function (wrap) {
            if (!wrap.contains(e.target)) {
                wrap.classList.remove('is-open');
                var dd = wrap.querySelector('.rep-search-dropdown');
                if (dd) dd.style.display = 'none';
                var hidden = wrap.querySelector('.rep-hidden-id') || wrap.querySelector('.rep-reprova-select');
                var input  = wrap.querySelector('.rep-search-input');
                if (hidden && input) {
                    if (hidden.value) {
                        var sel = REPROVAS.filter(function (r) { return String(r.id) === String(hidden.value); })[0];
                        if (sel) input.value = sel.codigo + ' — ' + sel.descricao;
                    } else {
                        input.value = '';
                    }
                }
            }
        });
    });

    function atualizarBotoesRemover() {
        var blocos = reprovasList.querySelectorAll('.lst-reprova-block');
        blocos.forEach(function (b) {
            var btnRem = b.querySelector('.lst-reprova-remove');
            if (btnRem) btnRem.style.display = blocos.length > 1 ? 'flex' : 'none';
        });
    }

    function addReprovaBlock() {
        var frag  = template.content.cloneNode(true);
        var block = frag.querySelector('.lst-reprova-block');

        initReprovaCombobox(block);

        var btnRem = block.querySelector('.lst-reprova-remove');
        if (btnRem) {
            btnRem.addEventListener('click', function () {
                if (reprovasList.querySelectorAll('.lst-reprova-block').length <= 1) return;
                block.remove();
                atualizarBotoesRemover();
            });
        }

        reprovasList.appendChild(frag);
        atualizarBotoesRemover();
        return block;
    }

    function resetReprovasList() {
        reprovasList.innerHTML = '';
        addReprovaBlock();
    }

    if (addBtn) addBtn.addEventListener('click', function () { addReprovaBlock(); });

    // ─── Abrir o popup a partir de uma linha da lista ───────────────────────────────
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-reprovar');
        if (!btn) return;

        form.reset();
        limpaErro();
        resetReprovasList();

        var d = btn.dataset;
        var idProjInput = document.getElementById('rep-form-id-projeto') || document.getElementById('rep-id_projeto');
        var nsInput     = document.getElementById('rep-form-ns') || document.getElementById('rep-ns');

        if (idProjInput) idProjInput.value = d.id_projeto || '';
        if (nsInput) nsInput.value = d.ns_transformador || '';

        var nsDisplay = document.getElementById('rep-modal-ns') || document.getElementById('rep-ns-display');
        var projDisplay = document.getElementById('rep-modal-projeto') || document.getElementById('rep-projeto-display');

        if (nsDisplay) nsDisplay.textContent = d.ns_transformador || '—';
        if (projDisplay) {
            var txt = d.projeto_codigo || '—';
            if (d.projeto_descricao) txt += ' (' + d.projeto_descricao + ')';
            projDisplay.textContent = txt;
        }

        openModal();
    });

    ['rep-modal-close', 'rep-modal-cancelar', 'rep-modal-cancel'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('click', closeModal);
    });
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });

    // ─── Submeter ─────────────────────────────────────────────────────────────────
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        limpaErro();

        var idProjInput = document.getElementById('rep-form-id-projeto') || document.getElementById('rep-id_projeto');
        var nsInput     = document.getElementById('rep-form-ns') || document.getElementById('rep-ns');
        var idProjeto   = idProjInput ? idProjInput.value : '';
        var ns          = nsInput ? nsInput.value : '';
        var obsEl       = document.getElementById('rep-obs') || document.getElementById('rep-observacoes');
        var observacoes = obsEl ? obsEl.value : '';
        var dataReprova = new Date().toISOString().slice(0, 10);

        var hiddenInputs = reprovasList.querySelectorAll('.rep-hidden-id, .rep-reprova-select');
        var idsReprova = [];
        hiddenInputs.forEach(function (inp) {
            if (inp.value) idsReprova.push(inp.value);
        });

        if (!idsReprova.length) {
            mostraErro('Selecione pelo menos um motivo de reprovação.');
            return;
        }

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Reprovando…';
        }

        var estEl = form.querySelector('input[name="estacao"]');
        var estVal = estEl ? estEl.value : 'LAB';

        var params = new URLSearchParams();
        params.append('acao', 'registrar');
        params.append('id_projeto', idProjeto);
        params.append('ns_transformador', ns);
        params.append('data_reprova', dataReprova);
        params.append('observacoes', observacoes);
        params.append('estacao', estVal);
        idsReprova.forEach(function (idR) {
            params.append('id_reprova[]', idR);
        });

        fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        })
        .then(function (r) {
            return r.json().catch(function () {
                return { sucesso: false, erro: 'Resposta inválida do servidor.' };
            });
        })
        .then(function (res) {
            if (res && res.sucesso) {
                var msg = res.mensagem || (res.vai_retrabalho ? 'Reprovação registrada e enviada para o Retrabalho.' : 'Reprovação registrada. Encaminhada para Retornos para correção interna.');
                notify(msg, 'success');
                window.location.reload();
            } else {
                mostraErro((res && res.erro) || 'Erro ao registrar reprovação.');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Confirmar Reprovação';
                }
            }
        })
        .catch(function (err) {
            mostraErro('Falha de conexão com o servidor: ' + (err.message || ''));
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Confirmar Reprovação';
            }
        });
    });

    // ─── Excluir registro (lixeira) ──────────────────────────────────────────
    var excluirModal = document.getElementById('excluir-modal');
    var excluirNsSpan = document.getElementById('excluir-modal-ns');
    var excluirConfirmarBtn = document.getElementById('excluir-modal-confirmar');
    var excluirCancelarBtn = document.getElementById('excluir-modal-cancelar');
    var excluirCloseBtn = document.getElementById('excluir-modal-close');
    var nsParaExcluir = null;

    function fecharExcluirModal() {
        if (excluirModal) excluirModal.style.display = 'none';
        nsParaExcluir = null;
    }

    if (excluirModal) {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.js-excluir-registro');
            if (!btn) return;
            nsParaExcluir = btn.dataset.ns_transformador;
            if (excluirNsSpan) excluirNsSpan.textContent = nsParaExcluir;
            excluirModal.style.display = 'flex';
        });

        if (excluirCloseBtn) excluirCloseBtn.addEventListener('click', fecharExcluirModal);
        if (excluirCancelarBtn) excluirCancelarBtn.addEventListener('click', fecharExcluirModal);
        excluirModal.addEventListener('click', function (e) { if (e.target === excluirModal) fecharExcluirModal(); });

        if (excluirConfirmarBtn) {
            excluirConfirmarBtn.addEventListener('click', function () {
                if (!nsParaExcluir) return;
                excluirConfirmarBtn.disabled = true;
                excluirConfirmarBtn.textContent = 'Excluindo…';

                postProducaoAcao({ acao: 'remover_etapa', ns_transformador: nsParaExcluir })
                    .then(function (res) {
                        if (res && res.sucesso) {
                            window.location.reload();
                        } else {
                            alert((res && res.erro) || 'Erro ao excluir registro.');
                            excluirConfirmarBtn.disabled = false;
                            excluirConfirmarBtn.textContent = 'Excluir Registro';
                        }
                    })
                    .catch(function () {
                        alert('Falha de conexão.');
                        excluirConfirmarBtn.disabled = false;
                        excluirConfirmarBtn.textContent = 'Excluir Registro';
                    });
            });
        }
    }
})();
