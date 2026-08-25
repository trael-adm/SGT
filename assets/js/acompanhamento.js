(function () {
    'use strict';

    var DATA = Array.isArray(window.BOLETIM_ACOMPANHAMENTO_DATA) ? window.BOLETIM_ACOMPANHAMENTO_DATA : [];

    var tbody          = document.getElementById('ac-tbody');
    var buscaInput     = document.getElementById('ac-busca');
    var buscaClear     = document.getElementById('ac-search-clear');
    var porPaginaEl    = document.getElementById('ac-por-pagina');
    var paginacaoEl    = document.getElementById('ac-paginacao');
    var contadorEl     = document.getElementById('ac-contador');
    var tabelaEl       = document.getElementById('ac-tabela');
    var btnExportar    = document.getElementById('btnExportarCsv');
    var btnImprimir    = document.getElementById('btnImprimirRelatorio');
    var filtroCont     = document.getElementById('filtroAtivoContainer');
    var filtroTxt      = document.getElementById('filtroAtivoTexto');
    var kpiCards       = document.querySelectorAll('.ac-kpi-card');

    if (!tbody || !tabelaEl) return;

    var state = {
        busca: '',
        filtroAcao: '',
        ordenarPor: 'data',
        ordemAsc: true,
        pagina: 1,
        porPagina: 25,
    };

    var ACAO_BADGES = {
        'DESCER PARA MONTAGEM FINAL': '<span class="action-badge action-descer">Descer Mont. Final</span>',
        'PINTAR TANQUE':              '<span class="action-badge action-pintar">Pintar Tanque</span>',
        'GUARDAR NA ESTUFA':          '<span class="action-badge action-estufa">Guardar na Estufa</span>',
        'VERIFICAR APONTAMENTO':      '<span class="action-badge action-verificar">Verif. Apontamento</span>',
    };

    function escapeHtml(str) {
        return String(str == null ? '' : str).replace(/[&<>"']/g, function (m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
        });
    }

    function removeAccents(str) {
        return String(str == null ? '' : str).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    }

    function statusCellBadge(status) {
        if (status === 'OK') {
            return '<span class="cell-badge-ok">OK</span>';
        }
        return '<span class="cell-badge-pend">PEND</span>';
    }

    function filtrarDados() {
        var q = removeAccents(state.busca);
        return DATA.filter(function (item) {
            if (state.filtroAcao && item.acao !== state.filtroAcao) {
                return false;
            }
            if (!q) return true;
            return removeAccents(item.pedido).indexOf(q) !== -1
                || removeAccents(item.data).indexOf(q) !== -1
                || String(item.seq || '').indexOf(q) !== -1
                || removeAccents(item.projeto).indexOf(q) !== -1
                || removeAccents(item.desc_projeto).indexOf(q) !== -1
                || removeAccents(item.cliente).indexOf(q) !== -1
                || String(item.nr_serie || '').indexOf(state.busca.trim()) !== -1;
        });
    }

    function ordenarDados(lista) {
        var campo = state.ordenarPor;
        var asc = state.ordemAsc ? 1 : -1;
        lista.sort(function (a, b) {
            var va = a[campo];
            var vb = b[campo];

            if (campo === 'data') {
                va = a.data_raw || 0;
                vb = b.data_raw || 0;
            } else if (campo === 'seq') {
                va = a.seq_num || 0;
                vb = b.seq_num || 0;
            } else if (campo === 'nr_serie' || campo === 'pedido') {
                va = Number(a[campo]) || 0;
                vb = Number(b[campo]) || 0;
            }

            if (typeof va === 'number' && typeof vb === 'number') {
                if (va !== vb) {
                    return (va - vb) * asc;
                }
            } else {
                va = removeAccents(String(va || ''));
                vb = removeAccents(String(vb || ''));
                if (va < vb) return -1 * asc;
                if (va > vb) return 1 * asc;
            }

            // Critérios estáveis de desempate
            if ((a.data_raw || 0) !== (b.data_raw || 0)) return ((a.data_raw || 0) - (b.data_raw || 0));
            if ((a.seq_num || 0) !== (b.seq_num || 0)) return ((a.seq_num || 0) - (b.seq_num || 0));
            if (a.pedido !== b.pedido) return String(a.pedido).localeCompare(String(b.pedido));
            return (a.nr_serie || 0) - (b.nr_serie || 0);
        });
        return lista;
    }

    function renderLinha(item) {
        var acaoHtml = ACAO_BADGES[item.acao] || ('<span class="action-badge">' + escapeHtml(item.acao) + '</span>');
        return '<tr>'
            + '<td class="col-pedido">' + escapeHtml(item.pedido) + '</td>'
            + '<td class="col-data-pcp">' + escapeHtml(item.data) + '</td>'
            + '<td class="col-seq">' + escapeHtml(item.seq) + '</td>'
            + '<td style="font-weight:700; color:#0f172a;" title="' + escapeHtml(item.projeto) + '">' + escapeHtml(item.projeto) + '</td>'
            + '<td style="text-align:left !important; padding-left:10px; max-width:250px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="' + escapeHtml(item.desc_projeto) + '">' + escapeHtml(item.desc_projeto) + '</td>'
            + '<td style="text-align:left !important; padding-left:10px; max-width:170px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="' + escapeHtml(item.cliente) + '">' + escapeHtml(item.cliente) + '</td>'
            + '<td class="col-ns">' + escapeHtml(item.nr_serie) + '</td>'
            + '<td>' + statusCellBadge(item.pintura) + '</td>'
            + '<td>' + statusCellBadge(item.montagem_eletrica) + '</td>'
            + '<td>' + statusCellBadge(item.montagem_final) + '</td>'
            + '<td>' + acaoHtml + '</td>'
            + '</tr>';
    }

    function renderPaginacao(totalItens) {
        var totalPaginas = Math.max(1, Math.ceil(totalItens / state.porPagina));
        if (state.pagina > totalPaginas) state.pagina = totalPaginas;

        var html = '';
        html += '<button class="page-btn" ' + (state.pagina <= 1 ? 'disabled' : '') + ' data-pag="' + (state.pagina - 1) + '">‹</button>';

        var inicio = Math.max(1, state.pagina - 2);
        var fim = Math.min(totalPaginas, inicio + 4);
        inicio = Math.max(1, fim - 4);

        for (var p = inicio; p <= fim; p++) {
            html += '<button class="page-btn' + (p === state.pagina ? ' active' : '') + '" data-pag="' + p + '">' + p + '</button>';
        }

        html += '<button class="page-btn" ' + (state.pagina >= totalPaginas ? 'disabled' : '') + ' data-pag="' + (state.pagina + 1) + '">›</button>';
        paginacaoEl.innerHTML = html;

        paginacaoEl.querySelectorAll('.page-btn[data-pag]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var novaPag = parseInt(btn.getAttribute('data-pag'), 10);
                if (!novaPag || novaPag < 1 || novaPag > totalPaginas) return;
                state.pagina = novaPag;
                render();
            });
        });
    }

    function atualizarIndicadoresOrdenacao() {
        tabelaEl.querySelectorAll('th[data-sort]').forEach(function (th) {
            var campo = th.getAttribute('data-sort');
            var icon = th.querySelector('.sort-icon');
            if (state.ordenarPor === campo) {
                th.classList.add('is-sorted');
                if (icon) icon.textContent = state.ordemAsc ? '▲' : '▼';
            } else {
                th.classList.remove('is-sorted');
                if (icon) icon.textContent = '▲▼';
            }
        });
    }

    function render() {
        atualizarIndicadoresOrdenacao();

        // 1. Atualizar cards de KPI
        kpiCards.forEach(function (card) {
            var acao = card.getAttribute('data-acao');
            card.classList.toggle('is-active', state.filtroAcao === acao);
        });

        // 2. Atualizar Pill de Filtro Ativo
        if (filtroCont && filtroTxt) {
            if (state.filtroAcao) {
                filtroCont.style.display = 'block';
                filtroTxt.textContent = state.filtroAcao;
            } else {
                filtroCont.style.display = 'none';
            }
        }

        // 3. Atualizar botão de limpar busca
        if (buscaClear) {
            buscaClear.style.display = state.busca.trim() ? 'block' : 'none';
        }

        var filtrados = ordenarDados(filtrarDados());
        var total = filtrados.length;

        if (contadorEl) {
            contadorEl.textContent = total + (total === 1 ? ' item' : ' itens');
        }

        if (total === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="11">
                        <div class="ac-empty-box">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
                            <div style="font-weight:700; color:#334155; font-size:0.85rem;">Nenhum transformador encontrado</div>
                            <div style="font-size:0.75rem; color:#94a3b8; margin-top:2px;">Tente ajustar sua busca ou remover o filtro de ação selecionado.</div>
                            <button type="button" class="ac-btn" onclick="window.limparTodosFiltros()" style="margin-top:10px; font-size:0.72rem;">Limpar Filtros</button>
                        </div>
                    </td>
                </tr>
            `;
            paginacaoEl.innerHTML = '';
            return;
        }

        var inicio = (state.pagina - 1) * state.porPagina;
        var pagina = filtrados.slice(inicio, inicio + state.porPagina);

        tbody.innerHTML = pagina.map(renderLinha).join('');
        renderPaginacao(total);
    }

    window.limparFiltroAcao = function () {
        state.filtroAcao = '';
        state.pagina = 1;
        render();
    };

    window.limparTodosFiltros = function () {
        state.filtroAcao = '';
        state.busca = '';
        if (buscaInput) buscaInput.value = '';
        state.pagina = 1;
        render();
    };

    // Eventos dos cards de KPI (Filtro tátil com toggle)
    kpiCards.forEach(function (card) {
        card.addEventListener('click', function () {
            var acao = card.getAttribute('data-acao');
            if (state.filtroAcao === acao) {
                state.filtroAcao = '';
            } else {
                state.filtroAcao = acao;
            }
            state.pagina = 1;
            render();
        });
    });

    if (buscaInput) {
        buscaInput.addEventListener('input', function () {
            state.busca = buscaInput.value;
            state.pagina = 1;
            render();
        });
    }

    if (buscaClear) {
        buscaClear.addEventListener('click', function () {
            state.busca = '';
            if (buscaInput) buscaInput.value = '';
            state.pagina = 1;
            render();
            if (buscaInput) buscaInput.focus();
        });
    }

    if (porPaginaEl) {
        porPaginaEl.addEventListener('change', function () {
            state.porPagina = parseInt(porPaginaEl.value, 10) || 25;
            state.pagina = 1;
            render();
        });
    }

    tabelaEl.querySelectorAll('th[data-sort]').forEach(function (th) {
        th.addEventListener('click', function () {
            var campo = th.getAttribute('data-sort');
            if (state.ordenarPor === campo) {
                state.ordemAsc = !state.ordemAsc;
            } else {
                state.ordenarPor = campo;
                state.ordemAsc = true;
            }
            render();
        });
    });

    // ──────────────────────────────────────────────────────────────────────────
    // EXPORTAÇÃO CSV
    // ──────────────────────────────────────────────────────────────────────────
    if (btnExportar) {
        btnExportar.addEventListener('click', function () {
            var filtrados = ordenarDados(filtrarDados());
            if (!filtrados.length) {
                alert('Nenhum dado para exportar.');
                return;
            }

            var cabecalho = ['PEDIDO', 'DATA_PCP', 'SEQ', 'PROJETO', 'DESCRICAO', 'CLIENTE', 'NR_SERIE', 'PIN', 'ME', 'MF', 'ACAO'];
            var linhasCsv = [cabecalho.join(';')];

            filtrados.forEach(function (it) {
                var linha = [
                    '"' + (it.pedido || '').replace(/"/g, '""') + '"',
                    '"' + (it.data || '').replace(/"/g, '""') + '"',
                    '"' + (it.seq || '').replace(/"/g, '""') + '"',
                    '"' + (it.projeto || '').replace(/"/g, '""') + '"',
                    '"' + (it.desc_projeto || '').replace(/"/g, '""') + '"',
                    '"' + (it.cliente || '').replace(/"/g, '""') + '"',
                    '"' + (it.nr_serie || '') + '"',
                    '"' + (it.pintura || '') + '"',
                    '"' + (it.montagem_eletrica || '') + '"',
                    '"' + (it.montagem_final || '') + '"',
                    '"' + (it.acao || '').replace(/"/g, '""') + '"'
                ];
                linhasCsv.push(linha.join(';'));
            });

            var csvContent = '\uFEFF' + linhasCsv.join('\r\n');
            var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = 'Acompanhamento_Producao_Trael_' + (new Date().toISOString().slice(0, 10)) + '.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        });
    }

    // ──────────────────────────────────────────────────────────────────────────
    // IMPRESSÃO A4 PAISAGEM (100% IDÊNTICA AO DESIGN DA TELA)
    // ──────────────────────────────────────────────────────────────────────────
    if (btnImprimir) {
        btnImprimir.addEventListener('click', function () {
            var filtrados = ordenarDados(filtrarDados());
            if (!filtrados.length) {
                alert('Nenhum item para imprimir.');
                return;
            }

            var printWin = window.open('', '_blank', 'width=1200,height=850');
            if (!printWin) {
                alert('Por favor, permita popups no navegador para gerar a impressão.');
                return;
            }

            var dataHora = new Date().toLocaleString('pt-BR');
            var filtroTexto = state.filtroAcao ? state.filtroAcao : 'Todas as Ações';
            if (state.busca) filtroTexto += ' (Busca: "' + state.busca + '")';

            var cDescer = 0, cPintar = 0, cEstufa = 0, cVerif = 0;
            filtrados.forEach(function (it) {
                if (it.acao === 'DESCER PARA MONTAGEM FINAL') cDescer++;
                else if (it.acao === 'PINTAR TANQUE') cPintar++;
                else if (it.acao === 'GUARDAR NA ESTUFA') cEstufa++;
                else if (it.acao === 'VERIFICAR APONTAMENTO') cVerif++;
            });

            var rowsHtml = filtrados.map(function (it) {
                var badgePin = it.pintura === 'OK' 
                    ? '<span class="cell-badge-ok">OK</span>' 
                    : '<span class="cell-badge-pend">PEND</span>';
                var badgeMe = it.montagem_eletrica === 'OK' 
                    ? '<span class="cell-badge-ok">OK</span>' 
                    : '<span class="cell-badge-pend">PEND</span>';
                var badgeMf = it.montagem_final === 'OK' 
                    ? '<span class="cell-badge-ok">OK</span>' 
                    : '<span class="cell-badge-pend">PEND</span>';

                var badgeAcao = ACAO_BADGES[it.acao] || ('<span class="action-badge">' + escapeHtml(it.acao) + '</span>');

                return `
                    <tr>
                        <td class="col-pedido">${escapeHtml(it.pedido)}</td>
                        <td class="col-data-pcp">${escapeHtml(it.data)}</td>
                        <td class="col-seq">${escapeHtml(it.seq)}</td>
                        <td style="font-weight:700; color:#0f172a;">${escapeHtml(it.projeto)}</td>
                        <td style="text-align:left !important; padding-left:8px; max-width:240px; font-size:9.5px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escapeHtml(it.desc_projeto)}</td>
                        <td style="text-align:left !important; padding-left:8px; max-width:140px; font-size:9.5px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escapeHtml(it.cliente)}</td>
                        <td class="col-ns">${escapeHtml(it.nr_serie)}</td>
                        <td>${badgePin}</td>
                        <td>${badgeMe}</td>
                        <td>${badgeMf}</td>
                        <td>${badgeAcao}</td>
                    </tr>
                `;
            }).join('');

            printWin.document.write(`
                <!DOCTYPE html>
                <html lang="pt-BR">
                <head>
                    <meta charset="utf-8">
                    <title>Acompanhamento em Produção — Trael</title>
                    <style>
                        * { box-sizing: border-box; margin: 0; padding: 0; }
                        @page { size: A4 landscape; margin: 8mm 6mm 6mm 6mm; }
                        @media print {
                            body { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
                            .no-print { display: none !important; }
                        }
                        body {
                            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                            color: #0f172a;
                            background: #ffffff;
                            padding: 8px 12px;
                            font-size: 11px;
                            -webkit-print-color-adjust: exact !important;
                            print-color-adjust: exact !important;
                        }

                        /* ─── Cabeçalho ──────────────────────────────────────── */
                        .bo-header-row {
                            display: flex;
                            align-items: center;
                            justify-content: space-between;
                            border-bottom: 2px solid #e2e8f0;
                            padding-bottom: 8px;
                            margin-bottom: 10px;
                        }
                        .bo-title-group h1 {
                            font-size: 14px;
                            font-weight: 800;
                            color: #0f172a;
                            letter-spacing: -0.02em;
                            display: flex;
                            align-items: center;
                            gap: 8px;
                        }
                        .bo-title-badge {
                            font-size: 9px;
                            font-weight: 700;
                            text-transform: uppercase;
                            background: #e2e8f0;
                            color: #475569;
                            padding: 2px 7px;
                            border-radius: 9999px;
                        }
                        .bo-subtitle {
                            font-size: 10px;
                            color: #64748b;
                            margin-top: 1px;
                            font-weight: 500;
                        }
                        .print-meta {
                            text-align: right;
                            font-size: 9.5px;
                            color: #64748b;
                            line-height: 1.35;
                        }

                        /* ─── Cards de KPI Idênticos à Tela ───────────────────── */
                        .ac-kpis-grid {
                            display: grid;
                            grid-template-columns: repeat(4, 1fr);
                            gap: 10px;
                            margin-bottom: 10px;
                        }
                        .ac-kpi-card {
                            background: #ffffff;
                            border: 1.5px solid #e2e8f0;
                            border-radius: 8px;
                            padding: 6px 10px;
                            display: flex;
                            flex-direction: column;
                            justify-content: space-between;
                        }
                        .ac-kpi-card-header {
                            display: flex;
                            align-items: center;
                            justify-content: space-between;
                            margin-bottom: 2px;
                        }
                        .ac-kpi-pill {
                            display: inline-flex;
                            align-items: center;
                            gap: 4px;
                            font-size: 8.5px;
                            font-weight: 700;
                            text-transform: uppercase;
                            letter-spacing: 0.04em;
                        }
                        .ac-kpi-dot {
                            width: 6px;
                            height: 6px;
                            border-radius: 50%;
                            display: inline-block;
                        }
                        .ac-kpi-body {
                            display: flex;
                            align-items: baseline;
                            justify-content: space-between;
                            gap: 6px;
                        }
                        .ac-kpi-number {
                            font-size: 17px;
                            font-weight: 800;
                            line-height: 1;
                        }
                        .ac-kpi-desc {
                            font-size: 8.5px;
                            color: #64748b;
                            font-weight: 500;
                            white-space: nowrap;
                        }

                        .kpi-descer { border-color: #bbf7d0; background: #f0fdf4 !important; }
                        .kpi-descer .ac-kpi-pill, .kpi-descer .ac-kpi-number { color: #15803d; }
                        .kpi-descer .ac-kpi-dot { background: #16a34a; }

                        .kpi-pintar { border-color: #fde68a; background: #fffbeb !important; }
                        .kpi-pintar .ac-kpi-pill, .kpi-pintar .ac-kpi-number { color: #b45309; }
                        .kpi-pintar .ac-kpi-dot { background: #d97706; }

                        .kpi-estufa { border-color: #bae6fd; background: #f0f9ff !important; }
                        .kpi-estufa .ac-kpi-pill, .kpi-estufa .ac-kpi-number { color: #0369a1; }
                        .kpi-estufa .ac-kpi-dot { background: #0284c7; }

                        .kpi-verificar { border-color: #fecaca; background: #fef2f2 !important; }
                        .kpi-verificar .ac-kpi-pill, .kpi-verificar .ac-kpi-number { color: #b91c1c; }
                        .kpi-verificar .ac-kpi-dot { background: #dc2626; }

                        /* ─── Tabela ─────────────────────────────────────────── */
                        table {
                            width: 100%;
                            border-collapse: separate;
                            border-spacing: 0;
                            border: 1px solid #cbd5e1;
                            border-radius: 6px;
                            overflow: hidden;
                            page-break-inside: auto;
                        }
                        tr { page-break-inside: avoid; page-break-after: auto; }
                        thead { display: table-header-group; }
                        th {
                            background: #f1f5f9 !important;
                            color: #475569 !important;
                            font-size: 8.5px;
                            font-weight: 800;
                            text-transform: uppercase;
                            letter-spacing: 0.04em;
                            padding: 6px 4px;
                            border-bottom: 1.5px solid #cbd5e1;
                            border-right: 1px solid #e2e8f0;
                            text-align: center;
                            vertical-align: middle;
                        }
                        th:last-child { border-right: none; }
                        td {
                            padding: 4.5px 6px;
                            border-bottom: 1px solid #e2e8f0;
                            border-right: 1px solid #f1f5f9;
                            vertical-align: middle;
                            text-align: center;
                            font-size: 9.5px;
                            color: #334155;
                        }
                        td:last-child { border-right: none; }
                        tbody tr:nth-child(even) td { background-color: #fafbfc; }

                        .col-pedido { font-weight: 800; color: #16a34a; font-family: monospace; font-size: 10px; }
                        .col-data-pcp { font-family: monospace; font-size: 9px; color: #64748b; font-weight: 600; }
                        .col-seq { font-family: monospace; font-weight: 700; color: #334155; }
                        .col-ns { font-family: monospace; font-weight: 700; color: #0f172a; font-size: 9.5px; }

                        /* Badges Idênticos */
                        .cell-badge-ok {
                            display: inline-flex;
                            align-items: center;
                            justify-content: center;
                            min-width: 28px;
                            padding: 1px 4px;
                            font-size: 8px;
                            font-weight: 800;
                            background: #dcfce7 !important;
                            color: #15803d !important;
                            border: 1px solid #86efac;
                            border-radius: 3px;
                        }
                        .cell-badge-pend {
                            display: inline-flex;
                            align-items: center;
                            justify-content: center;
                            min-width: 28px;
                            padding: 1px 4px;
                            font-size: 8px;
                            font-weight: 700;
                            background: #ffedd5 !important;
                            color: #c2410c !important;
                            border: 1px solid #fdba74;
                            border-radius: 3px;
                        }

                        .action-badge {
                            display: inline-flex;
                            align-items: center;
                            padding: 1.5px 7px;
                            font-size: 8.5px;
                            font-weight: 800;
                            border-radius: 9999px;
                            white-space: nowrap;
                        }
                        .action-descer { background: #dcfce7 !important; color: #15803d !important; border: 1px solid #86efac; }
                        .action-pintar { background: #fef3c7 !important; color: #b45309 !important; border: 1px solid #fde68a; }
                        .action-estufa { background: #e0f2fe !important; color: #0369a1 !important; border: 1px solid #bae6fd; }
                        .action-verificar { background: #fee2e2 !important; color: #b91c1c !important; border: 1px solid #fecaca; }

                        .print-footer {
                            margin-top: 10px;
                            text-align: center;
                            font-size: 8.5px;
                            color: #94a3b8;
                            border-top: 1px solid #e2e8f0;
                            padding-top: 4px;
                        }
                    </style>
                </head>
                <body>
                    <div class="bo-header-row">
                        <div class="bo-title-group">
                            <h1>
                                <span>Acompanhamento em Produção</span>
                                <span class="bo-title-badge">Empresa 1</span>
                            </h1>
                            <p class="bo-subtitle">Fluxo de Montagem: Tanque (PIN) × Parte Ativa (ME) → Montagem Final (MF)</p>
                        </div>
                        <div class="print-meta">
                            <div><strong>Emissão:</strong> ${dataHora}</div>
                            <div><strong>Filtro:</strong> ${escapeHtml(filtroTexto)} · <strong>${filtrados.length} transformadores</strong></div>
                        </div>
                    </div>

                    <!-- 4 Cards de KPI Idênticos à Tela -->
                    <div class="ac-kpis-grid">
                        <div class="ac-kpi-card kpi-descer">
                            <div class="ac-kpi-card-header">
                                <span class="ac-kpi-pill"><span class="ac-kpi-dot"></span>Descer Mont. Final</span>
                            </div>
                            <div class="ac-kpi-body">
                                <span class="ac-kpi-number">${cDescer}</span>
                                <span class="ac-kpi-desc">Tanque & Parte Ativa prontos</span>
                            </div>
                        </div>
                        <div class="ac-kpi-card kpi-pintar">
                            <div class="ac-kpi-card-header">
                                <span class="ac-kpi-pill"><span class="ac-kpi-dot"></span>Pintar Tanque</span>
                            </div>
                            <div class="ac-kpi-body">
                                <span class="ac-kpi-number">${cPintar}</span>
                                <span class="ac-kpi-desc">Parte Ativa pronta, falta PIN</span>
                            </div>
                        </div>
                        <div class="ac-kpi-card kpi-estufa">
                            <div class="ac-kpi-card-header">
                                <span class="ac-kpi-pill"><span class="ac-kpi-dot"></span>Guardar na Estufa</span>
                            </div>
                            <div class="ac-kpi-body">
                                <span class="ac-kpi-number">${cEstufa}</span>
                                <span class="ac-kpi-desc">Tanque pintado, falta ME</span>
                            </div>
                        </div>
                        <div class="ac-kpi-card kpi-verificar">
                            <div class="ac-kpi-card-header">
                                <span class="ac-kpi-pill"><span class="ac-kpi-dot"></span>Verif. Apontamento</span>
                            </div>
                            <div class="ac-kpi-body">
                                <span class="ac-kpi-number">${cVerif}</span>
                                <span class="ac-kpi-desc">Montagem Final sem etapa prévia</span>
                            </div>
                        </div>
                    </div>

                    <!-- Tabela Formatada Exatamente Como na Tela -->
                    <table>
                        <thead>
                            <tr>
                                <th style="width:55px;">PEDIDO</th>
                                <th style="width:68px;">DATA PCP</th>
                                <th style="width:40px;">SEQ</th>
                                <th style="width:85px;">PROJETO</th>
                                <th style="text-align:left !important; padding-left:8px;">DESCRIÇÃO</th>
                                <th style="width:130px; text-align:left !important; padding-left:8px;">CLIENTE</th>
                                <th style="width:68px;">Nº SÉRIE</th>
                                <th style="width:38px;">PIN</th>
                                <th style="width:38px;">ME</th>
                                <th style="width:38px;">MF</th>
                                <th style="width:145px;">AÇÃO REQUERIDA</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${rowsHtml}
                        </tbody>
                    </table>

                    <div class="print-footer">
                        Sistema SGT / BOLETIM — Trael Transformadores — Documento de Controle Interno da Produção
                    </div>

                    <script>
                        window.onload = function() {
                            setTimeout(function() {
                                window.print();
                            }, 300);
                        };
                    <\/script>
                </body>
                </html>
            `);
            printWin.document.close();
        });
    }

    render();
})();