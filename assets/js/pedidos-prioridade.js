(function () {
    'use strict';

    var API = window.PROJETOS_API || '';
    var INFO = window.PRIORIDADES_INFO || {
        'emergente':  { label: 'Emergente',  bg: '#fee2e2', fg: '#b91c1c' },
        'urgente':    { label: 'Urgente',    bg: '#ffedd5', fg: '#c2410c' },
        'importante': { label: 'Importante', bg: '#fef9c3', fg: '#a16207' },
        'neutro':     { label: 'Neutro',     bg: '#f1f5f9', fg: '#64748b' }
    };

    function removeAccents(str) {
        return (str || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
    }

    var STORAGE_VIEW = 'sgt_prio_view_mode';
    var STORAGE_EXPAND_ALL = 'sgt_prio_expand_all';
    var STORAGE_OPEN_GROUPS = 'sgt_prio_open_groups';

    function getOpenGroups() {
        try { return JSON.parse(localStorage.getItem(STORAGE_OPEN_GROUPS) || '[]'); } catch (e) { return []; }
    }
    function saveOpenGroups(arr) {
        localStorage.setItem(STORAGE_OPEN_GROUPS, JSON.stringify(arr));
    }

    // ─── Popover Flutuante de Prioridades ───────────────────────────────────────
    var popover = document.getElementById('prio-popover');
    var popoverNeutroLabel = document.getElementById('popover-neutro-label');
    var currentTrigger = null;

    function openPopover(btn) {
        currentTrigger = btn;
        var tipo = btn.getAttribute('data-tipo');
        var curPrio = btn.getAttribute('data-prioridade') || 'neutro';

        if (popoverNeutroLabel) {
            popoverNeutroLabel.textContent = (tipo === 'pedido') ? 'Neutro (Fila Padrão)' : '↳ Padrão / Herdado';
        }

        popover.querySelectorAll('.prio-popover-item').forEach(function (it) {
            var val = it.getAttribute('data-value');
            it.classList.toggle('is-selected', val === curPrio);
        });

        var rect = btn.getBoundingClientRect();
        var top = rect.bottom + window.scrollY + 4;
        var left = rect.right + window.scrollX - 170; // alinha à direita do botão
        if (left < 10) left = 10;

        popover.style.top = top + 'px';
        popover.style.left = left + 'px';
        popover.classList.add('is-open');
    }

    function closePopover() {
        if (popover) popover.classList.remove('is-open');
        currentTrigger = null;
    }

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('.js-prio-trigger');
        if (trigger) {
            if (window.CAN_EDIT === false) return;
            e.stopPropagation();
            if (currentTrigger === trigger && popover.classList.contains('is-open')) {
                closePopover();
            } else {
                openPopover(trigger);
            }
            return;
        }

        if (popover && popover.contains(e.target)) {
            var item = e.target.closest('.prio-popover-item');
            if (item && currentTrigger) {
                var newPrio = item.getAttribute('data-value');
                applyPriorityChange(currentTrigger, newPrio);
                closePopover();
            }
            return;
        }

        closePopover();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closePopover();
    });

    // ─── Atualização de Prioridade e Cascata de Herança ─────────────────────────
    function updateBadgeElement(btn, prioVal, isInherited, inheritedPrio) {
        btn.setAttribute('data-prioridade', prioVal);
        var effective = (prioVal !== 'neutro') ? prioVal : (inheritedPrio || 'neutro');
        btn.setAttribute('data-prioridade-efetiva', effective);

        // Remove classes antigas de estilo
        btn.className = 'prio-badge-btn js-prio-trigger prio-style-' + effective;
        if (isInherited) btn.classList.add('is-inherited');

        var lbl = btn.querySelector('.badge-label');
        if (lbl) {
            var info = INFO[effective] || { label: effective };
            lbl.textContent = isInherited ? ('↳ ' + info.label) : info.label;
        }

        var tr = btn.closest('tr');
        if (tr) {
            tr.setAttribute('data-prioridade', effective);
            tr.setAttribute('data-prioridade-propria', prioVal);
        }
    }

    function recalcKPIs() {
        var counts = { emergente: 0, urgente: 0, importante: 0, neutro: 0 };
        document.querySelectorAll('#tree-tbody tr.row-ns').forEach(function (r) {
            var p = r.getAttribute('data-prioridade') || 'neutro';
            if (counts[p] !== undefined) counts[p]++;
        });

        for (var k in counts) {
            var el = document.getElementById('kpi-' + k);
            if (el) el.textContent = counts[k];
        }
    }

    function applyPriorityChange(btn, newPrio) {
        var tipo = btn.getAttribute('data-tipo');
        var id = btn.getAttribute('data-id');
        var ns = btn.getAttribute('data-ns');
        var pedId = btn.getAttribute('data-pedido-id');
        var projId = btn.getAttribute('data-projeto-id');

        var acao = 'pedido_prioridade';
        var payload = { prioridade: newPrio, sequencia: 0 };

        if (tipo === 'pedido') {
            acao = 'pedido_prioridade';
            payload.acao = acao;
            payload.id = id;
        } else if (tipo === 'projeto') {
            acao = 'projeto_prioridade';
            payload.acao = acao;
            payload.id = id;
        } else if (tipo === 'ns') {
            acao = 'ns_prioridade';
            payload.acao = acao;
            payload.ns_transformador = ns;
        }

        // Atualização Otimista
        if (tipo === 'pedido') {
            updateBadgeElement(btn, newPrio, false);
            // Cascata para projetos e NS deste pedido
            document.querySelectorAll('#tree-tbody tr[data-pedido-id="' + id + '"]').forEach(function (row) {
                var rowType = row.getAttribute('data-type');
                var rowBtn = row.querySelector('.js-prio-trigger');
                if (!rowBtn) return;

                if (rowType === 'projeto') {
                    var projPropria = row.getAttribute('data-prioridade-propria') || 'neutro';
                    if (projPropria === 'neutro') {
                        updateBadgeElement(rowBtn, 'neutro', newPrio !== 'neutro', newPrio);
                    }
                } else if (rowType === 'ns') {
                    var nsPropria = row.getAttribute('data-prioridade-propria') || 'neutro';
                    var pId = row.getAttribute('data-projeto-id');
                    var pRow = document.querySelector('#tree-tbody tr.row-projeto[data-id="' + pId + '"]');
                    var projEff = pRow ? pRow.getAttribute('data-prioridade') : newPrio;
                    if (nsPropria === 'neutro') {
                        updateBadgeElement(rowBtn, 'neutro', projEff !== 'neutro', projEff);
                    }
                }
            });
        } else if (tipo === 'projeto') {
            var pedRow = document.querySelector('#tree-tbody tr.row-pedido[data-id="' + pedId + '"]');
            var pedEff = pedRow ? pedRow.getAttribute('data-prioridade') : 'neutro';
            var isInherited = (newPrio === 'neutro' && pedEff !== 'neutro');
            updateBadgeElement(btn, newPrio, isInherited, pedEff);

            // Cascata para NS deste projeto
            var projEff = btn.getAttribute('data-prioridade-efetiva') || 'neutro';
            document.querySelectorAll('#tree-tbody tr.row-ns[data-projeto-id="' + id + '"]').forEach(function (row) {
                var rowBtn = row.querySelector('.js-prio-trigger');
                if (!rowBtn) return;
                var nsPropria = row.getAttribute('data-prioridade-propria') || 'neutro';
                if (nsPropria === 'neutro') {
                    updateBadgeElement(rowBtn, 'neutro', projEff !== 'neutro', projEff);
                }
            });
        } else if (tipo === 'ns') {
            var projRow = document.querySelector('#tree-tbody tr.row-projeto[data-id="' + projId + '"]');
            var projEff2 = projRow ? projRow.getAttribute('data-prioridade') : 'neutro';
            var isInheritedNs = (newPrio === 'neutro' && projEff2 !== 'neutro');
            updateBadgeElement(btn, newPrio, isInheritedNs, projEff2);

            // Atualiza também na Visão Lista Plana se existir
            document.querySelectorAll('#flat-tbody tr').forEach(function (fRow) {
                var fBtn = fRow.querySelector('.js-prio-trigger[data-ns="' + ns + '"]');
                if (fBtn) {
                    updateBadgeElement(fBtn, newPrio, isInheritedNs, projEff2);
                }
            });
        }

        recalcKPIs();

        // Envio Assíncrono ao Backend
        var body = new URLSearchParams(payload);
        fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (r) {
            return r.json().catch(function () { return { sucesso: false }; });
        }).then(function (res) {
            if (!res || !res.sucesso) {
                alert((res && res.erro) || 'Erro ao sincronizar prioridade com o servidor.');
            }
        }).catch(function () {
            alert('Falha de comunicação com o servidor.');
        });
    }

    // ─── Alternância de Modo de Visão (Árvore vs Lista Direta) ─────────────────
    var viewTreeEl = document.getElementById('view-tree-container');
    var viewFlatEl = document.getElementById('view-flat-container');
    var btnViewTree = document.getElementById('btn-view-tree');
    var btnViewFlat = document.getElementById('btn-view-flat');

    function setViewMode(mode) {
        localStorage.setItem(STORAGE_VIEW, mode);
        var isTree = (mode === 'tree');
        if (viewTreeEl) viewTreeEl.style.display = isTree ? '' : 'none';
        if (viewFlatEl) viewFlatEl.style.display = isTree ? 'none' : '';
        if (btnViewTree) btnViewTree.classList.toggle('is-active', isTree);
        if (btnViewFlat) btnViewFlat.classList.toggle('is-active', !isTree);
        applyFilter();
    }

    if (btnViewTree) btnViewTree.addEventListener('click', function () { setViewMode('tree'); });
    if (btnViewFlat) btnViewFlat.addEventListener('click', function () { setViewMode('flat'); });

    // ─── Árvore Hierárquica: Expansão e Recolhimento ───────────────────────────
    function toggleGroup(targetClass, forceOpen) {
        var rows = document.querySelectorAll('#tree-tbody tr.' + targetClass);
        if (!rows.length) return false;

        var shouldOpen = (forceOpen !== undefined) ? forceOpen : (rows[0].style.display === 'none');
        rows.forEach(function (r) {
            // Se estiver fechando, fecha também os filhos de terceiro nível
            if (!shouldOpen && r.classList.contains('row-projeto')) {
                var projId = r.getAttribute('data-id');
                toggleGroup('grp-proj-' + projId, false);
                var subBtn = r.querySelector('.js-tree-toggle');
                if (subBtn) { subBtn.textContent = '+'; subBtn.setAttribute('aria-expanded', 'false'); }
            }
            r.style.display = shouldOpen ? '' : 'none';
        });

        return shouldOpen;
    }

    function syncExpandAllButton(isAllExpanded) {
        var btnAll = document.getElementById('btn-prio-expand-all');
        if (btnAll) {
            btnAll.setAttribute('aria-expanded', isAllExpanded ? 'true' : 'false');
            btnAll.classList.toggle('is-active', isAllExpanded);
            var lbl = btnAll.querySelector('.lbl-expand');
            if (lbl) lbl.textContent = isAllExpanded ? 'Recolher Todos' : 'Expandir Todos';
        }
        var quickBtn = document.querySelector('.js-toggle-all-quick');
        if (quickBtn) {
            quickBtn.textContent = isAllExpanded ? '−' : '⤢';
            quickBtn.title = isAllExpanded ? 'Recolher todos' : 'Expandir todos';
        }
    }

    function setAllTreeExpansion(expand) {
        localStorage.setItem(STORAGE_EXPAND_ALL, expand ? 'true' : 'false');
        var openGroups = [];

        document.querySelectorAll('#tree-tbody .js-tree-toggle').forEach(function (btn) {
            var target = btn.getAttribute('data-target');
            if (target) {
                toggleGroup(target, expand);
                btn.textContent = expand ? '−' : '+';
                btn.setAttribute('aria-expanded', expand ? 'true' : 'false');
                if (expand) openGroups.push(target);
            }
        });

        saveOpenGroups(openGroups);
        syncExpandAllButton(expand);
    }

    function restoreTreeState() {
        var expandAll = localStorage.getItem(STORAGE_EXPAND_ALL) === 'true';
        if (expandAll) {
            setAllTreeExpansion(true);
            return;
        }

        var openGroups = getOpenGroups();
        document.querySelectorAll('#tree-tbody .js-tree-toggle').forEach(function (btn) {
            var target = btn.getAttribute('data-target');
            if (target && openGroups.includes(target)) {
                toggleGroup(target, true);
                btn.textContent = '−';
                btn.setAttribute('aria-expanded', 'true');
            }
        });
        syncExpandAllButton(false);
    }

    document.addEventListener('click', function (e) {
        var btnExpandAll = e.target.closest('#btn-prio-expand-all') || e.target.closest('.js-toggle-all-quick');
        if (btnExpandAll) {
            var isCurrentlyAll = localStorage.getItem(STORAGE_EXPAND_ALL) === 'true';
            setAllTreeExpansion(!isCurrentlyAll);
            return;
        }

        var toggleBtn = e.target.closest('.js-tree-toggle');
        if (toggleBtn) {
            var target = toggleBtn.getAttribute('data-target');
            if (!target) return;
            var isNowOpen = toggleGroup(target);
            toggleBtn.textContent = isNowOpen ? '−' : '+';
            toggleBtn.setAttribute('aria-expanded', isNowOpen ? 'true' : 'false');

            var openGroups = getOpenGroups();
            if (isNowOpen) {
                if (!openGroups.includes(target)) openGroups.push(target);
            } else {
                openGroups = openGroups.filter(function (g) { return g !== target; });
                localStorage.setItem(STORAGE_EXPAND_ALL, 'false');
                syncExpandAllButton(false);
            }
            saveOpenGroups(openGroups);
        }
    });

    // ─── Busca e Filtro Instantâneo em Tempo Real ───────────────────────────────
    var searchInput = document.getElementById('prio-search-input');
    var clearBtn = document.getElementById('prio-search-clear');
    var filterSelect = document.getElementById('prio-filter-select');

    function applyFilter() {
        var query = searchInput ? removeAccents(searchInput.value) : '';
        var prioFilter = filterSelect ? filterSelect.value : '';

        if (clearBtn) {
            clearBtn.style.display = query ? 'flex' : 'none';
        }

        var isTreeMode = (localStorage.getItem(STORAGE_VIEW) || 'tree') === 'tree';

        if (isTreeMode) {
            var visibleTreeRows = 0;
            var matchedPedidos = new Set();
            var matchedProjetos = new Set();

            var rows = document.querySelectorAll('#tree-tbody tr.js-prio-row');

            // 1. Encontra correspondências em NS, Projetos ou Pedidos
            rows.forEach(function (r) {
                var sData = removeAccents(r.getAttribute('data-search') || '');
                var pData = r.getAttribute('data-prioridade') || '';

                var matchText = !query || sData.indexOf(query) !== -1;
                var matchPrio = true;
                if (prioFilter === 'priorizados') {
                    matchPrio = (pData === 'emergente' || pData === 'urgente' || pData === 'importante');
                } else if (prioFilter) {
                    matchPrio = (pData === prioFilter);
                }

                if (matchText && matchPrio) {
                    var pedId = r.getAttribute('data-pedido-id');
                    var projId = r.getAttribute('data-projeto-id');
                    if (pedId) matchedPedidos.add(pedId);
                    if (projId) matchedProjetos.add(projId);
                }
            });

            // 2. Aplica visibilidade garantindo que nós pais fiquem visíveis
            rows.forEach(function (r) {
                var type = r.getAttribute('data-type');
                var pedId = r.getAttribute('data-pedido-id');
                var projId = r.getAttribute('data-projeto-id');

                var show = false;
                if (!query && !prioFilter) {
                    // Estado padrão: respeita o estado de abertura da árvore
                    if (type === 'pedido') show = true;
                    else if (type === 'projeto') {
                        var pRow = document.querySelector('#tree-tbody tr.row-pedido[data-id="' + pedId + '"]');
                        var pToggle = pRow ? pRow.querySelector('.js-tree-toggle') : null;
                        show = (pToggle && pToggle.getAttribute('aria-expanded') === 'true');
                    } else if (type === 'ns') {
                        var pjRow = document.querySelector('#tree-tbody tr.row-projeto[data-id="' + projId + '"]');
                        var pjToggle = pjRow ? pjRow.querySelector('.js-tree-toggle') : null;
                        var pdRow = document.querySelector('#tree-tbody tr.row-pedido[data-id="' + pedId + '"]');
                        var pdToggle = pdRow ? pdRow.querySelector('.js-tree-toggle') : null;
                        show = (pdToggle && pdToggle.getAttribute('aria-expanded') === 'true' && pjToggle && pjToggle.getAttribute('aria-expanded') === 'true');
                    }
                } else {
                    // Modo filtrado: se o pedido ou seus filhos bateram com o filtro, exibe
                    if (type === 'pedido') {
                        show = matchedPedidos.has(pedId);
                    } else if (type === 'projeto') {
                        show = matchedPedidos.has(pedId) && (matchedProjetos.has(projId) || !query);
                    } else if (type === 'ns') {
                        show = matchedPedidos.has(pedId);
                    }
                }

                r.style.display = show ? '' : 'none';
                if (show) visibleTreeRows++;
            });

            var noRes = document.querySelector('#tree-tbody .js-prio-no-results');
            if (noRes) noRes.style.display = (visibleTreeRows === 0 && rows.length > 0) ? '' : 'none';

        } else {
            // Modo Lista Plana
            var flatRows = document.querySelectorAll('#flat-tbody tr.js-prio-flat-row');
            var visibleFlat = 0;

            flatRows.forEach(function (r) {
                var sData = removeAccents(r.getAttribute('data-search') || '');
                var pData = r.getAttribute('data-prioridade') || '';

                var matchText = !query || sData.indexOf(query) !== -1;
                var matchPrio = true;
                if (prioFilter === 'priorizados') {
                    matchPrio = (pData === 'emergente' || pData === 'urgente' || pData === 'importante');
                } else if (prioFilter) {
                    matchPrio = (pData === prioFilter);
                }

                if (matchText && matchPrio) {
                    r.style.display = '';
                    visibleFlat++;
                } else {
                    r.style.display = 'none';
                }
            });

            var noResFlat = document.querySelector('#flat-tbody .js-prio-flat-no-results');
            if (noResFlat) noResFlat.style.display = (visibleFlat === 0 && flatRows.length > 0) ? '' : 'none';
        }
    }

    if (searchInput) searchInput.addEventListener('input', applyFilter);
    if (clearBtn) clearBtn.addEventListener('click', function () { searchInput.value = ''; searchInput.focus(); applyFilter(); });
    if (filterSelect) filterSelect.addEventListener('change', applyFilter);

    // ─── Inicialização ─────────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        var initialView = localStorage.getItem(STORAGE_VIEW) || 'tree';
        setViewMode(initialView);
        restoreTreeState();
    });

})();
