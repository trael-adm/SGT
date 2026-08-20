(function () {
    'use strict';

    var API  = window.PROJETOS_API || '';
    var INFO = window.PRIORIDADES_INFO || {};

    function removeAccents(str) {
        return (str || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
    }

    // ─── Salvar Prioridade (Chips) ─────────────────────────────────────────────
    function savePriority(wrap, chipSlug, seqValue) {
        if (wrap.dataset.saving === '1') return;

        var acao = wrap.getAttribute('data-acao') || 'pedido_prioridade';
        var idItem = wrap.getAttribute('data-id');
        var nsItem = wrap.getAttribute('data-ns');

        wrap.dataset.saving = '1';
        wrap.querySelectorAll('.js-prioridade-chip, .js-prioridade-seq').forEach(function (c) { 
            c.setAttribute('data-saving', '1');
            if (c.tagName === 'INPUT') c.disabled = true;
        });

        var params = { acao: acao, prioridade: chipSlug, sequencia: seqValue };
        if (idItem) params.id = idItem;
        if (nsItem) params.ns_transformador = nsItem;

        var body = new URLSearchParams(params);
        fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (r) {
            return r.json().catch(function () { return { sucesso: false }; });
        }).then(function (res) {
            if (res && res.sucesso) {
                wrap.querySelectorAll('.js-prioridade-chip').forEach(function (c) {
                    var s = c.getAttribute('data-slug');
                    var ativo = s === chipSlug;
                    c.classList.toggle('active', ativo);
                    c.style.background = ativo ? (INFO[s] ? INFO[s].bg : '') : '';
                    c.style.color      = ativo ? (INFO[s] ? INFO[s].fg : '') : '';
                });
                var row = wrap.closest('.pp-row');
                if (row) {
                    row.setAttribute('data-prioridade', chipSlug);
                }
            } else {
                alert((res && res.erro) || 'Erro ao salvar a prioridade.');
            }
        }).catch(function () {
            alert('Falha de conexão ao salvar a prioridade.');
        }).finally(function () {
            wrap.dataset.saving = '0';
            wrap.querySelectorAll('.js-prioridade-chip, .js-prioridade-seq').forEach(function (c) { 
                c.removeAttribute('data-saving'); 
                if (c.tagName === 'INPUT') c.disabled = false;
            });
        });
    }

    document.querySelectorAll('.pp-chips').forEach(function (wrap) {
        wrap.addEventListener('click', function (e) {
            var chip = e.target.closest('.js-prioridade-chip');
            if (!chip || wrap.dataset.saving === '1') return;
            
            var slug = chip.getAttribute('data-slug');
            savePriority(wrap, slug, 0);
        });
    });

    // ─── Filtro Dinâmico em Tempo Real ──────────────────────────────────────────
    var searchInput    = document.getElementById('pp-search-input');
    var clearBtn       = document.getElementById('pp-search-clear');
    var priorityFilter = document.getElementById('pp-priority-filter');
    var counterInfo    = document.getElementById('pp-counter-info');

    function applyFilter() {
        var query      = searchInput ? removeAccents(searchInput.value) : '';
        var prioFilter = priorityFilter ? priorityFilter.value : '';

        if (clearBtn) {
            clearBtn.style.display = query ? 'flex' : 'none';
        }

        var activePane = document.querySelector('.tab-pane.active');
        if (!activePane) return;

        var rows = activePane.querySelectorAll('.pp-row');
        var noResultsRow = activePane.querySelector('.pp-no-results-row');
        var totalRows = rows.length;
        var visibleCount = 0;

        rows.forEach(function (row) {
            var searchData = removeAccents(row.getAttribute('data-search') || '');
            var prioData   = row.getAttribute('data-prioridade') || '';

            var matchText = !query || searchData.indexOf(query) !== -1;
            var matchPrio = !prioFilter || prioData === prioFilter;

            if (matchText && matchPrio) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        if (noResultsRow) {
            noResultsRow.style.display = (totalRows > 0 && visibleCount === 0) ? '' : 'none';
        }

        if (counterInfo) {
            var tabName = 'itens';
            if (activePane.id === 'tab-pedidos') tabName = 'pedidos';
            else if (activePane.id === 'tab-projetos') tabName = 'projetos';
            else if (activePane.id === 'tab-ns') tabName = 'N° de série';

            if (query || prioFilter) {
                counterInfo.textContent = 'Exibindo ' + visibleCount + ' de ' + totalRows + ' ' + tabName;
            } else {
                counterInfo.textContent = totalRows + ' ' + tabName + ' no total';
            }
        }
    }

    if (searchInput) {
        searchInput.addEventListener('input', applyFilter);
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            searchInput.value = '';
            searchInput.focus();
            applyFilter();
        });
    }

    if (priorityFilter) {
        priorityFilter.addEventListener('change', applyFilter);
    }

    // ─── Tab Switching ─────────────────────────────────────────────────────────
    document.querySelectorAll('.rt-tab').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.rt-tab').forEach(function (b) { b.classList.remove('active'); });
            document.querySelectorAll('.tab-pane').forEach(function (p) { p.classList.remove('active'); });
            this.classList.add('active');
            var target = document.getElementById(this.dataset.tab);
            if (target) target.classList.add('active');
            applyFilter();
        });
    });

    applyFilter();
}());
