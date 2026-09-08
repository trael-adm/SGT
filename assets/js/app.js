(function () {
    'use strict';

    var STORAGE_KEY = 'boletim_sidebar_collapsed';
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
    if (window.innerWidth > 1024 && localStorage.getItem(STORAGE_KEY) === '1') {
        setSidebarCollapsed(true);
    }

    // Hamburger toggle
    var toggleBtn = document.getElementById('sidebar-toggle');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            if (window.innerWidth <= 1024) {
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

    // ─── Sidebar: preserva a rolagem entre navegações ──────────────
    // Cada clique num item recarrega a página inteira (sem SPA), então a
    // sidebar nasce zerada; salvamos o scrollTop antes de sair. A restauração
    // ao carregar já acontece antes disto, num <script> inline logo após a
    // <nav> em includes/sidebar.php — síncrono, pra não "piscar" no topo antes
    // de pular pra posição salva.
    var SCROLL_STORAGE_KEY = 'sgt_sidebar_scroll';
    var sidebarNav = document.getElementById('sidebarNav');

    if (sidebarNav) {
        var scrollSaveTimer = null;
        sidebarNav.addEventListener('scroll', function () {
            clearTimeout(scrollSaveTimer);
            scrollSaveTimer = setTimeout(function () {
                sessionStorage.setItem(SCROLL_STORAGE_KEY, String(sidebarNav.scrollTop));
            }, 100);
        });

        // Clique num item navega antes do debounce do scroll disparar — grava na hora.
        sidebarNav.addEventListener('click', function (e) {
            if (e.target.closest('.nav-item')) {
                sessionStorage.setItem(SCROLL_STORAGE_KEY, String(sidebarNav.scrollTop));
            }
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

    // ─── fmtDecimal(value) ───────────────────────────────────────
    // 300 → "300"  |  112.5 → "112,5"  |  1000 → "1.000"
    window.fmtDecimal = function (value) {
        if (value === null || value === undefined || value === '') return '—';
        var n = parseFloat(value);
        if (isNaN(n)) return '—';
        return n.toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 1 });
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

    // ─── Abrir o seletor nativo de data ao clicar em qualquer parte do campo,
    // não só no ícone de calendário (delegado no document — cobre inputs
    // adicionados depois).
    document.addEventListener('click', function (e) {
        var input = e.target.closest('input[type="date"]');
        if (!input || input.disabled || input.readOnly) return;
        if (typeof input.showPicker === 'function') {
            try { input.showPicker(); } catch (err) { /* navegador recusou neste contexto — ignora */ }
        }
    });

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

    // ─── Download de Arquivo com Spinner no Botão ────────────────
    window.baixarArquivoComSpinner = async function (btn, url, fallbackNome) {
        if (!btn || btn.disabled) return;
        var origHtml = btn.innerHTML;
        var origWidth = btn.offsetWidth ? (btn.offsetWidth + 'px') : '';
        btn.disabled = true;
        if (origWidth) btn.style.minWidth = origWidth;
        btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="animate-spin"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg> Gerando CSV...';

        try {
            var res = await fetch(url);
            if (!res.ok) {
                throw new Error('Falha ao processar o arquivo no servidor (status ' + res.status + ').');
            }
            var blob = await res.blob();
            var downloadUrl = window.URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = downloadUrl;

            // Extrai nome do cabeçalho Content-Disposition se existir
            var disposition = res.headers.get('content-disposition');
            var filename = fallbackNome || 'exportacao.csv';
            if (disposition && disposition.indexOf('filename=') !== -1) {
                var matches = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/.exec(disposition);
                if (matches != null && matches[1]) {
                    filename = matches[1].replace(/['"]/g, '');
                }
            }
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            a.remove();
            window.URL.revokeObjectURL(downloadUrl);
        } catch (err) {
            console.error('Erro na exportação:', err);
            if (typeof window.showAlert === 'function') {
                window.showAlert('Erro ao exportar CSV: ' + (err.message || 'Erro de conexão'), 'danger');
            } else {
                alert('Erro ao exportar CSV: ' + (err.message || 'Erro de conexão'));
            }
        } finally {
            btn.disabled = false;
            btn.innerHTML = origHtml;
            btn.style.minWidth = '';
        }
    };

}());

