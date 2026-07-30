(function () {
    'use strict';

    var STORAGE_KEY = 'trael_sidebar_collapsed';
    var sidebar     = document.querySelector('.sidebar');
    var overlay     = document.getElementById('mobile-overlay');

    // ─── Sidebar: desktop collapse ────────────────────────────────
    function setSidebarCollapsed(collapsed) {
        if (!sidebar) return;
        sidebar.classList.toggle('collapsed', collapsed);
        document.body.classList.toggle('sidebar-collapsed', collapsed);
        localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
    }

    // ─── Sidebar: mobile drawer ───────────────────────────────────
    function toggleMobileSidebar() {
        if (!sidebar) return;
        var isOpen = sidebar.classList.contains('mobile-open');
        sidebar.classList.toggle('mobile-open', !isOpen);
        if (overlay) overlay.classList.toggle('active', !isOpen);
    }

    // Restore saved state on desktop
    if (window.innerWidth >= 768 && localStorage.getItem(STORAGE_KEY) === '1') {
        setSidebarCollapsed(true);
    }

    // Hamburger toggle
    var toggleBtn = document.getElementById('sidebar-toggle');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            if (window.innerWidth < 768) {
                toggleMobileSidebar();
            } else {
                setSidebarCollapsed(!sidebar.classList.contains('collapsed'));
            }
        });
    }

    // Close drawer via overlay click
    if (overlay) {
        overlay.addEventListener('click', function () {
            if (sidebar) sidebar.classList.remove('mobile-open');
            overlay.classList.remove('active');
        });
    }

    // ─── User dropdown ────────────────────────────────────────────
    var userBtn      = document.getElementById('user-menu-btn');
    var userDropdown = document.getElementById('user-dropdown');

    if (userBtn && userDropdown) {
        userBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            var visible = userDropdown.style.display === 'block';
            userDropdown.style.display = visible ? 'none' : 'block';
        });

        document.addEventListener('click', function () {
            if (userDropdown) userDropdown.style.display = 'none';
        });
    }

    // Base da aplicação injetada pelo footer (window.__APP_BASE).
    var APP_BASE = (typeof window.__APP_BASE === 'string') ? window.__APP_BASE : '';

    // ─── fmtPrazoBadge(datetimeStr) ──────────────────────────────
    // Retorna HTML do badge de prazo, ou '' se prazo OK / sem prazo.
    // Mesma lógica de calcularPrazoBadge() do PHP.
    window.fmtPrazoBadge = function (datetimeStr) {
        if (!datetimeStr) return '';
        var prazo = new Date(datetimeStr.replace(' ', 'T'));
        prazo.setHours(0, 0, 0, 0);
        var hoje = new Date(); hoje.setHours(0, 0, 0, 0);
        var diff = Math.floor((prazo - hoje) / 86400000);
        var label = prazo.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
        if (diff < 0)  return '<span style="background:#dc2626;color:#fff;padding:1px 6px;border-radius:9999px;font-size:10px;font-weight:600;">⚠ ATRASADA ' + label + '</span>';
        if (diff <= 1) return '<span style="background:#d97706;color:#fff;padding:1px 6px;border-radius:9999px;font-size:10px;font-weight:600;">⚠ URGENTE '  + label + '</span>';
        return '';
    };

    // ─── calcularSegmentos(eventos) ──────────────────────────────
    // Agrupa eventos em segmentos de trabalho e pausa com início/fim/duração
    window.calcularSegmentos = function (eventos) {
        var segs = [];
        var aberto = null;
        var WORK  = { iniciada: 1, retomada: 1 };
        var PAUSE = { pausada: 1 };
        var CLOSE = { enviada_controle: 1, concluida: 1, cancelada: 1, devolvida: 1 };

        (eventos || []).forEach(function (ev) {
            var ts = new Date(ev.created_at);
            if (WORK[ev.tipo]) {
                if (aberto) segs.push({ tipo: aberto.tipo, inicio: aberto.inicio, fim: ts, motivo: aberto.motivo });
                aberto = { tipo: 'trabalho', inicio: ts, motivo: null };
            } else if (PAUSE[ev.tipo]) {
                if (aberto) segs.push({ tipo: aberto.tipo, inicio: aberto.inicio, fim: ts, motivo: aberto.motivo });
                aberto = { tipo: 'pausa', inicio: ts, motivo: ev.motivo || null };
            } else if (CLOSE[ev.tipo]) {
                if (aberto) { segs.push({ tipo: aberto.tipo, inicio: aberto.inicio, fim: ts, motivo: aberto.motivo }); aberto = null; }
            }
        });
        // Segmento ainda aberto (tarefa em execução sem evento de fechamento)
        if (aberto) {
            segs.push({ tipo: aberto.tipo, inicio: aberto.inicio, fim: new Date(), motivo: aberto.motivo, emAndamento: true });
        }
        return segs;
    };

    // ─── fmtDurSegmento(ms) ──────────────────────────────────────
    // Formata milissegundos em "2h 30min", "45min", "30s"
    window.fmtDurSegmento = function (ms) {
        var s = Math.floor(ms / 1000);
        var h = Math.floor(s / 3600);
        var m = Math.floor((s % 3600) / 60);
        s = s % 60;
        if (h > 0 && m > 0) return h + 'h ' + m + 'min';
        if (h > 0) return h + 'h';
        if (m > 0) return m + 'min';
        return s + 's';
    };

    // ─── renderTimeline(container, eventos) ──────────────────────
    // Renderiza a timeline de segmentos no container informado
    window.renderTimeline = function (container, eventos) {
        container.innerHTML = '';
        var segs = calcularSegmentos(eventos);

        if (!segs.length) {
            // Tarefa concluída de forma forçada pelo planejador (etapas atrás, sem
            // execução registrada): em vez de "Sem timeline", identificar a ação.
            // Sinalizada por um evento 'concluida' cujo motivo cita "planejador"
            // (gerado apenas por api/planejador-concluir-tarefa.php).
            var forcado = (eventos || []).filter(function (ev) {
                return ev.tipo === 'concluida' && ev.motivo &&
                       ev.motivo.toLowerCase().indexOf('planejador') !== -1;
            }).pop();

            if (forcado) {
                var quem = forcado.executor_nome ? forcado.executor_nome.split(' ')[0] : 'Planejador';
                var quando = '';
                if (forcado.created_at) {
                    var dtf = new Date(String(forcado.created_at).replace(' ', 'T'));
                    if (!isNaN(dtf.getTime())) {
                        quando = dtf.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: '2-digit' }) +
                                 ' ' + dtf.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
                    }
                }
                var obs = forcado.notas
                    ? '<p style="margin:8px 0 0;font-size:12px;color:var(--color-text-secondary);font-style:italic;white-space:pre-wrap;">' +
                      '“' + String(forcado.notas).replace(/</g, '&lt;') + '”</p>'
                    : '';
                container.innerHTML =
                    '<div style="text-align:center;padding:20px 16px;">' +
                      '<div style="display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;' +
                            'border-radius:9999px;background:var(--color-warning-bg);color:var(--color-warning-text);margin-bottom:8px;">' +
                        '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" ' +
                            'stroke-linecap="round" stroke-linejoin="round"><polyline points="4 12 9 17 20 6"/></svg>' +
                      '</div>' +
                      '<p style="margin:0;font-size:13px;font-weight:600;color:var(--color-text-primary);">Conclusão forçada pelo planejador</p>' +
                      '<p style="margin:3px 0 0;font-size:12px;color:var(--color-text-muted);">' + quem + (quando ? ' · ' + quando : '') + '</p>' +
                      obs +
                    '</div>';
                return;
            }

            container.innerHTML = '<p style="text-align:center;padding:16px;color:var(--color-text-muted);font-size:12px;">Sem timeline disponível.</p>';
            return;
        }

        var fmtTs = function (dt) {
            return dt.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' }) + ' ' +
                   dt.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
        };

        var table = document.createElement('table');
        table.style.cssText = 'width:100%;border-collapse:collapse;font-size:12px;';
        table.innerHTML =
            '<thead><tr style="background:var(--color-surface-2);">' +
            ['Tipo','Início','Fim','Duração','Obs.'].map(function(h) {
                return '<th style="padding:5px 9px;text-align:left;font-size:10px;font-weight:600;' +
                       'text-transform:uppercase;letter-spacing:.6px;color:var(--color-text-muted);' +
                       'border-bottom:1px solid var(--color-border);white-space:nowrap;">' + h + '</th>';
            }).join('') + '</tr></thead>';

        var tbody = document.createElement('tbody');
        var totalTrab = 0, totalPausa = 0;

        segs.forEach(function (seg) {
            var dur = seg.fim - seg.inicio;
            // O balde (trabalhado/parado) segue sempre o tipo do segmento — inclusive
            // o segmento em andamento: uma pausa em curso conta como parado, não trabalhado.
            if (seg.tipo === 'trabalho') totalTrab += dur; else totalPausa += dur;

            var badge = seg.tipo === 'trabalho'
                ? '<span style="background:var(--color-success-bg);color:var(--color-success-text);padding:1px 7px;border-radius:9999px;font-size:10px;font-weight:600;">Trabalhando</span>'
                : '<span style="background:var(--color-warning-bg);color:var(--color-warning-text);padding:1px 7px;border-radius:9999px;font-size:10px;font-weight:600;">Parado</span>';

            var fimCell = seg.emAndamento
                ? '<span style="color:var(--color-accent);font-weight:600;font-size:10px;">● Em andamento</span>'
                : fmtTs(seg.fim);

            var tdb = 'padding:5px 9px;border-bottom:1px solid var(--color-border);';
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td style="' + tdb + '">' + badge + '</td>' +
                '<td style="' + tdb + 'white-space:nowrap;color:var(--color-text-secondary);">' + fmtTs(seg.inicio) + '</td>' +
                '<td style="' + tdb + 'white-space:nowrap;">' + fimCell + '</td>' +
                '<td style="' + tdb + 'font-weight:600;">' + fmtDurSegmento(dur) + '</td>' +
                '<td style="' + tdb + 'font-size:11px;color:var(--color-text-muted);font-style:italic;">' + (seg.motivo || '') + '</td>';
            tbody.appendChild(tr);
        });

        table.appendChild(tbody);
        container.appendChild(table);

        // Totais
        var tot = document.createElement('div');
        tot.style.cssText = 'display:flex;gap:20px;flex-wrap:wrap;padding:8px 10px;' +
            'background:var(--color-surface-2);border-top:1px solid var(--color-border);font-size:12px;';
        tot.innerHTML =
            '<span><span style="color:var(--color-text-muted);">Trabalhado:</span> <strong>' + fmtDurSegmento(totalTrab) + '</strong></span>' +
            (totalPausa > 0
                ? '<span><span style="color:var(--color-text-muted);">Parado:</span> <strong style="color:var(--color-warning);">' + fmtDurSegmento(totalPausa) + '</strong></span>'
                : '');
        container.appendChild(tot);
    };

    // ─── fmtDecimal(value) ───────────────────────────────────────
    // 300 → "300"  |  112.5 → "112,5"  |  1000 → "1.000"
    window.fmtDecimal = function (value) {
        if (value === null || value === undefined || value === '') return '—';
        var n = parseFloat(value);
        if (isNaN(n)) return '—';
        return n.toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 1 });
    };

    // ─── fmtTensaoAt(value) / fmtTensaoBt(value) ─────────────────
    // Espelham gftFmtTensaoAt/Bt() do PHP (includes/helpers.php).
    // AT: ponto de milhar + "v"  (13800 → "13.800v");  BT: só "v"  (380/220 → "380/220v").
    // Idempotente: se já vier com ponto e/ou "v", não duplica.
    window.fmtTensaoAt = function (value) {
        if (value === null || value === undefined) return '—';
        var core = String(value).trim().replace(/\s*[vV]\s*$/, '');   // tira "v" final
        if (core === '') return '—';
        if (/^\d+$/.test(core) || /^\d{1,3}(\.\d{3})+$/.test(core)) {
            core = parseInt(core.replace(/\./g, ''), 10).toLocaleString('pt-BR');
        }
        return core + 'v';
    };
    window.fmtTensaoBt = function (value) {
        if (value === null || value === undefined) return '—';
        var core = String(value).trim().replace(/\s*[vV]\s*$/, '');
        if (core === '') return '—';
        return core + 'v';
    };

    // ─── showAlert(message, type) ─────────────────────────────────
    // type: 'success' | 'warning' | 'danger' | 'info'
    window.showAlert = function (message, type) {
        var container = document.getElementById('alert-container');
        if (!container) return;

        var el = document.createElement('div');
        el.className = 'alert alert-' + (type || 'info');
        el.textContent = message;
        container.appendChild(el);

        setTimeout(function () {
            if (el.parentNode) el.parentNode.removeChild(el);
        }, 5000);
    };

    // ─── Clearable inputs (botão ×) ──────────────────────────────
    (function () {
        var MOVE_STYLES = ['marginLeft','marginRight','marginTop','marginBottom',
                           'flex','flexGrow','flexShrink','maxWidth','minWidth'];

        function enhance(input) {
            if (input.dataset.clearEnhanced) return;
            input.dataset.clearEnhanced = '1';

            var wrap = document.createElement('span');
            wrap.className = 'field-search';

            // mover estilos de layout do input para o wrapper
            MOVE_STYLES.forEach(function (p) {
                if (input.style[p]) { wrap.style[p] = input.style[p]; input.style[p] = ''; }
            });
            if (input.style.width && input.style.width !== '100%') {
                wrap.style.width = input.style.width; input.style.width = '100%';
            }

            input.parentNode.insertBefore(wrap, input);
            wrap.appendChild(input);

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'field-clear-btn';
            btn.setAttribute('tabindex', '-1');
            btn.setAttribute('aria-label', 'Limpar');
            btn.innerHTML = '&times;';
            wrap.appendChild(btn);

            function sync() { btn.style.display = input.value ? 'block' : 'none'; }
            input.addEventListener('input', sync);
            sync();

            // mousedown preventDefault evita blur no input ao clicar no botão
            btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
            btn.addEventListener('click', function () {
                input.value = '';
                input.dispatchEvent(new Event('input', { bubbles: true }));
                var isFormOnly = input.name && input.form
                    && input.getAttribute('type') !== 'search'
                    && !input.hasAttribute('oninput');
                if (isFormOnly) { input.form.submit(); } else { input.focus(); }
                sync();
            });
        }

        document.querySelectorAll(
            'input[type="search"], input[id*="busca"], input[name*="busca"]'
        ).forEach(enhance);
    }());

    // ─── renderExecutoresBadges(elId, d) ─────────────────────────
    // Renderiza badges Fazer/Controlar no modal de detalhes
    window.renderExecutoresBadges = function (elId, d) {
        var el = document.getElementById(elId);
        if (!el) return;
        var exFazer = d.papel === 'fazer' ? d.executor_proprio : d.executor_par;
        var exCtrl  = d.papel === 'fazer' ? d.executor_par     : d.executor_proprio;
        var html = '';
        if (exFazer) html += '<span style="background:var(--color-info-bg);color:var(--color-info-text);padding:2px 8px;border-radius:9999px;font-size:11px;font-weight:600;">Fazer: ' + exFazer.split(' ')[0] + '</span>';
        if (exCtrl)  html += '<span style="background:var(--color-surface-2);color:var(--color-text-secondary);padding:2px 8px;border-radius:9999px;font-size:11px;font-weight:600;border:1px solid var(--color-border);">Controlar: ' + exCtrl.split(' ')[0] + '</span>';
        el.innerHTML = html;
        el.style.display = html ? 'flex' : 'none';
    };

    // ─── Abrir o seletor nativo de data ao clicar em qualquer parte do campo,
    // não só no ícone de calendário (delegado no document — cobre inputs
    // adicionados depois, ex.: blocos de reprova clonados via JS).
    document.addEventListener('click', function (e) {
        var input = e.target.closest('input[type="date"]');
        if (!input || input.disabled || input.readOnly) return;
        if (typeof input.showPicker === 'function') {
            try { input.showPicker(); } catch (err) { /* navegador recusou neste contexto — ignora */ }
        }
    });

    // ─── Auto-refresh de listas ───────────────────────────────────
    // Busca a mesma URL da página em segundo plano e troca só os trechos do DOM
    // indicados em `seletores` (ex.: a tabela e o rodapé de paginação) — sem dar
    // reload, sem perder a rolagem nem o que estiver digitado num filtro ainda não
    // enviado (o formulário de filtro nunca entra em `seletores`). Pausa sozinho
    // enquanto qualquer seletor de `modaisPausa` estiver visível, pra não trocar o
    // chão debaixo de quem está preenchendo um modal.
    window.iniciarAutoRefresh = function (config) {
        var seletores   = config.seletores || [];
        var intervaloS  = config.intervaloS || 30;
        var elIndicador = config.elIndicador || null;
        var modaisPausa = config.modaisPausa || [];
        var aoAtualizar = config.aoAtualizar || function () {};

        var restante = intervaloS;
        var buscando = false;

        function modalAberto() {
            return modaisPausa.some(function (sel) {
                var el = document.querySelector(sel);
                return el && window.getComputedStyle(el).display !== 'none';
            });
        }

        function atualizarIndicador() {
            if (!elIndicador) return;
            elIndicador.textContent = modalAberto() ? 'Atualização pausada' : ('Atualiza em ' + restante + 's');
        }

        function buscarEAtualizar() {
            if (buscando) return;
            buscando = true;
            fetch(window.location.href, { cache: 'no-store' })
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    seletores.forEach(function (sel) {
                        var atual = document.querySelector(sel);
                        var novo  = doc.querySelector(sel);
                        if (atual && novo) atual.outerHTML = novo.outerHTML;
                    });
                    aoAtualizar();
                })
                .catch(function () { /* falha silenciosa — tenta de novo no próximo ciclo */ })
                .then(function () { buscando = false; });
        }

        setInterval(function () {
            if (modalAberto()) { atualizarIndicador(); return; }
            restante--;
            if (restante <= 0) { restante = intervaloS; buscarEAtualizar(); }
            atualizarIndicador();
        }, 1000);

        atualizarIndicador();
    };

    // ─── Tecla ESC para fechar modais ────────────────────────────
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay').forEach(function (overlay) {
                if (window.getComputedStyle(overlay).display !== 'none') {
                    var closeBtn = overlay.querySelector('.modal-close');
                    if (closeBtn) closeBtn.click();
                }
            });
        }
    });

}());
