(function () {
    'use strict';

    // Chart.js >= 4 / chartjs-plugin-datalabels v2
    if (window.Chart && window.ChartDataLabels) {
        Chart.register(window.ChartDataLabels);
    }

    var DATA = window.BOLETIM_CHART_DATA || {};
    var CORES = { TPD: '#82c341', TPS: '#4a90e2', TPM: '#00a86b', LAB: '#dc2626' };
    var VERDE  = '#16a34a'; // Realizado
    var AMBAR  = '#e8a020'; // Planejado / Meta

    // ─── 1. Gráfico "Produção — Quantidade" (barras agrupadas + Executado Total + Meta Diária) ─
    var elProducao = document.getElementById('chart-producao');
    if (elProducao && DATA.dias) {
        new Chart(elProducao, {
            type: 'bar',
            data: {
                labels: DATA.dias,
                datasets: [
                    {
                        label: 'TPD',
                        data: DATA.producao.TPD,
                        backgroundColor: CORES.TPD,
                        datalabels: {
                            display: true,
                            anchor: 'end',
                            align: 'top',
                            offset: 2,
                            color: CORES.TPD,
                            font: { weight: 'bold', size: 9 },
                            formatter: function (v) { return v || ''; },
                        },
                    },
                    {
                        label: 'TPS',
                        data: DATA.producao.TPS,
                        backgroundColor: CORES.TPS,
                        datalabels: {
                            display: true,
                            anchor: 'end',
                            align: 'top',
                            offset: 2,
                            color: CORES.TPS,
                            font: { weight: 'bold', size: 9 },
                            formatter: function (v) { return v || ''; },
                        },
                    },
                    {
                        label: 'TPM',
                        data: DATA.producao.TPM,
                        backgroundColor: CORES.TPM,
                        datalabels: {
                            display: true,
                            anchor: 'end',
                            align: 'top',
                            offset: 2,
                            color: CORES.TPM,
                            font: { weight: 'bold', size: 9 },
                            formatter: function (v) { return v || ''; },
                        },
                    },
                    {
                        label: 'REP',
                        data: DATA.producao.LAB,
                        backgroundColor: CORES.LAB,
                        datalabels: {
                            display: true,
                            anchor: 'end',
                            align: 'top',
                            offset: 2,
                            color: CORES.LAB,
                            font: { weight: 'bold', size: 9 },
                            formatter: function (v) { return v || ''; },
                        },
                    },
                    {
                        type: 'line',
                        label: 'Executado Total',
                        data: DATA.executadoTotal,
                        borderColor: VERDE,
                        backgroundColor: VERDE,
                        tension: 0.25,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        fill: false,
                        order: 0,
                        datalabels: {
                            display: true,
                            offset: 6,
                            color: VERDE,
                            font: { weight: 'bold', size: 10 },
                            formatter: function (v) { return v || ''; },
                            anchor: function (ctx) {
                                var meta = DATA.metaTotal[ctx.dataIndex] || 0;
                                var val = ctx.dataset.data[ctx.dataIndex] || 0;
                                return (meta - val) < (meta * 0.12) ? 'start' : 'end';
                            },
                            align: function (ctx) {
                                var meta = DATA.metaTotal[ctx.dataIndex] || 0;
                                var val = ctx.dataset.data[ctx.dataIndex] || 0;
                                return (meta - val) < (meta * 0.12) ? 'bottom' : 'top';
                            },
                        },
                    },
                    {
                        type: 'line',
                        label: 'Meta Diária',
                        data: DATA.metaTotal,
                        borderColor: AMBAR,
                        backgroundColor: AMBAR,
                        borderDash: [6, 4],
                        pointRadius: 2,
                        fill: false,
                        order: 0,
                        datalabels: { display: false }
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                onHover: function (evt, elements) {
                    if (evt.native && evt.native.target) {
                        evt.native.target.style.cursor = (elements && elements.length > 0) ? 'pointer' : 'default';
                    }
                },
                onClick: function (evt, elements, chart) {
                    if (!elements || elements.length === 0) return;
                    var firstElem = elements[0];
                    var datasetIndex = firstElem.datasetIndex;
                    var index = firstElem.index;
                    var dataset = chart.data.datasets[datasetIndex];
                    var label = dataset.label;
                    if (label === 'Meta Diária') return;

                    var diaNum = DATA.dias[index];
                    var mes = window.MES_REFERENCIA || (new Date().toISOString().substring(0, 7));
                    var dataYmd = mes + '-' + String(diaNum).padStart(2, '0');

                    var tipo = (label === 'REP') ? 'LAB' : (label === 'Executado Total' ? 'TOTAL' : label);
                    window.abrirModalDetalhesPecas(dataYmd, tipo);
                },
                scales: {
                    x: {
                        title: { display: true, text: 'Dia do mês' },
                        grid: { display: false },
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0,0,0,0.06)' },
                    },
                },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            title: function (items) {
                                return 'Dia ' + items[0].label;
                            },
                        },
                    },
                },
            },
        });
    }

    // ─── 2. Gráfico "Percentual do Planejado (%)" ──────────────────────────────
    var elPercentual = document.getElementById('chart-percentual');
    if (elPercentual && DATA.dias) {
        new Chart(elPercentual, {
            type: 'line',
            data: {
                labels: DATA.dias,
                datasets: [
                    {
                        label: '% Atingido',
                        data: DATA.percentual,
                        borderColor: VERDE,
                        backgroundColor: 'rgba(22,163,74,0.08)',
                        fill: true,
                        tension: 0.25,
                        pointRadius: 2,
                        datalabels: { display: false },
                    },
                    {
                        label: '100% (Meta)',
                        data: DATA.dias.map(function () { return 100; }),
                        borderColor: AMBAR,
                        borderDash: [6, 4],
                        pointRadius: 0,
                        fill: false,
                        datalabels: { display: false },
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { title: { display: true, text: 'Dia do mês' }, grid: { display: false } },
                    y: {
                        beginAtZero: true,
                        suggestedMax: 120,
                        ticks: { callback: function (v) { return v + '%'; } },
                    },
                },
                plugins: { legend: { position: 'bottom' } },
            },
        });
    }

    // ─── 3. Gráfico "Produção Acumulada" (área acumulada real vs meta) ──────────
    var elAcumulada = document.getElementById('chart-acumulada');
    if (elAcumulada && DATA.dias) {
        new Chart(elAcumulada, {
            type: 'line',
            data: {
                labels: DATA.dias,
                datasets: [
                    {
                        label: 'Realizado Acumulado',
                        data: DATA.acumuladoReal,
                        borderColor: VERDE,
                        backgroundColor: 'rgba(22,163,74,0.12)',
                        fill: true,
                        tension: 0.2,
                        pointRadius: 2,
                        datalabels: { display: false },
                    },
                    {
                        label: 'Meta Acumulada',
                        data: DATA.acumuladoMeta,
                        borderColor: AMBAR,
                        borderDash: [6, 4],
                        fill: false,
                        pointRadius: 0,
                        datalabels: { display: false },
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { title: { display: true, text: 'Dia do mês' }, grid: { display: false } },
                    y: { beginAtZero: true },
                },
                plugins: { legend: { position: 'bottom' } },
            },
        });
    }

    // ─── 4. Gráficos "Mix Produção" (Pizzas Programado vs Realizado) ──────────
    var chartMixProg = null;
    var chartMixReal = null;
    var elMixProg = document.getElementById('chart-mix-prog');
    var elMixReal = document.getElementById('chart-mix-real');

    if (elMixProg && elMixReal && DATA.mixPorDia && DATA.diaPadraoMix) {
        var mixInicial = DATA.mixPorDia[DATA.diaPadraoMix] || {
            programado: { TPD: 0, TPM: 0, TPS: 0, pctTPD: 0, pctTPM: 0, pctTPS: 0 },
            realizado:  { TPD: 0, TPM: 0, TPS: 0, pctTPD: 0, pctTPM: 0, pctTPS: 0 },
        };

        var mixColors = [CORES.TPD, CORES.TPM, CORES.TPS];
        var mixLabels = ['TPD', 'TPM', 'TPS'];

        var optionsPizza = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            var val = ctx.parsed || 0;
                            var dataset = ctx.dataset.data;
                            var soma = dataset.reduce(function (a, b) { return a + b; }, 0);
                            var pct = soma > 0 ? Math.round((val / soma) * 100) : 0;
                            return ' ' + ctx.label + ': ' + val + ' un (' + pct + '%)';
                        },
                    },
                },
                datalabels: {
                    display: function (ctx) {
                        return (ctx.dataset.data[ctx.dataIndex] || 0) > 0;
                    },
                    color: '#ffffff',
                    font: { weight: 'bold', size: 12 },
                    formatter: function (val, ctx) {
                        var dataset = ctx.dataset.data;
                        var soma = dataset.reduce(function (a, b) { return a + b; }, 0);
                        var pct = soma > 0 ? Math.round((val / soma) * 100) : 0;
                        return pct + '%';
                    },
                },
            },
        };

        chartMixProg = new Chart(elMixProg, {
            type: 'pie',
            data: {
                labels: mixLabels,
                datasets: [{
                    data: [mixInicial.programado.TPD || 0, mixInicial.programado.TPM || 0, mixInicial.programado.TPS || 0],
                    backgroundColor: mixColors,
                    borderWidth: 2,
                    borderColor: '#ffffff',
                }],
            },
            options: optionsPizza,
        });

        chartMixReal = new Chart(elMixReal, {
            type: 'pie',
            data: {
                labels: mixLabels,
                datasets: [{
                    data: [mixInicial.realizado.TPD || 0, mixInicial.realizado.TPM || 0, mixInicial.realizado.TPS || 0],
                    backgroundColor: mixColors,
                    borderWidth: 2,
                    borderColor: '#ffffff',
                }],
            },
            options: optionsPizza,
        });
    }

    window.atualizarMixDia = function (dataStr) {
        if (!DATA.mixPorDia || !DATA.mixPorDia[dataStr]) return;
        var diaObj = DATA.mixPorDia[dataStr];
        var prog = diaObj.programado || {};
        var real = diaObj.realizado  || {};

        if (chartMixProg) {
            chartMixProg.data.datasets[0].data = [prog.TPD || 0, prog.TPM || 0, prog.TPS || 0];
            chartMixProg.update();
        }
        if (chartMixReal) {
            chartMixReal.data.datasets[0].data = [real.TPD || 0, real.TPM || 0, real.TPS || 0];
            chartMixReal.update();
        }

        var elProgTpd = document.getElementById('lbl-prog-tpd');
        var elProgTpm = document.getElementById('lbl-prog-tpm');
        var elProgTps = document.getElementById('lbl-prog-tps');
        if (elProgTpd) elProgTpd.textContent = (prog.pctTPD || 0) + '%';
        if (elProgTpm) elProgTpm.textContent = (prog.pctTPM || 0) + '%';
        if (elProgTps) elProgTps.textContent = (prog.pctTPS || 0) + '%';

        var elRealTpd = document.getElementById('lbl-real-tpd');
        var elRealTpm = document.getElementById('lbl-real-tpm');
        var elRealTps = document.getElementById('lbl-real-tps');
        if (elRealTpd) elRealTpd.textContent = (real.pctTPD || 0) + '%';
        if (elRealTpm) elRealTpm.textContent = (real.pctTPM || 0) + '%';
        if (elRealTps) elRealTps.textContent = (real.pctTPS || 0) + '%';

        var elDestaque = document.getElementById('mix-data-destaque');
        if (elDestaque) {
            var partes = dataStr.split('-');
            elDestaque.textContent = (partes.length === 3) ? (partes[2] + '/' + partes[1] + '/' + partes[0]) : dataStr;
        }
    };

    // ─── 5. Gráficos "Produção por Linha" (TPD, TPS, TPM) ──────────────────────
    var chartsLinhas = {};
    if (DATA.dadosLinhas && DATA.diasFormatados) {
        var labelsComMedia = DATA.diasFormatados.concat(['MÉDIA']);

        ['TPD', 'TPS', 'TPM'].forEach(function (c) {
            var elCanvas = document.getElementById('chart-linha-' + c.toLowerCase());
            var linhaData = DATA.dadosLinhas[c];
            if (!elCanvas || !linhaData) return;

            var valoresComMedia = linhaData.execs.concat([linhaData.mediaExec || 0]);
            var corPrincipal = linhaData.info.cor || CORES[c];

            var bgColors = linhaData.execs.map(function () { return corPrincipal; });
            bgColors.push(corPrincipal);

            chartsLinhas[c] = new Chart(elCanvas, {
                type: 'bar',
                data: {
                    labels: labelsComMedia,
                    datasets: [
                        {
                            label: 'Produção Realizada',
                            data: valoresComMedia,
                            backgroundColor: bgColors,
                            borderRadius: 3,
                            datalabels: {
                                display: true,
                                anchor: 'end',
                                align: 'top',
                                offset: 2,
                                color: function (ctx) {
                                    return ctx.dataIndex === valoresComMedia.length - 1 ? '#0f172a' : corPrincipal;
                                },
                                font: { weight: 'bold', size: 10 },
                                formatter: function (v) { return v || ''; },
                            },
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: { font: { size: 10, weight: '600' } },
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(0,0,0,0.06)' },
                        },
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                title: function (items) {
                                    return items[0].label === 'MÉDIA' ? 'Média Diária Realizada' : 'Dia ' + items[0].label;
                                },
                                label: function (ctx) {
                                    return ' ' + ctx.dataset.label + ': ' + ctx.parsed.y + ' un';
                                },
                            },
                        },
                    },
                },
            });
        });
    }

    // ─── Funções de Impressão (A4 Paisagem) ──────────────────────────────────
    window.imprimirProducaoQuantidade = function () {
        var prodCanvas = document.getElementById('chart-producao');
        if (!prodCanvas) {
            alert('Gráfico de produção não encontrado para impressão.');
            return;
        }

        var prodImg = prodCanvas.toDataURL('image/png');
        var cardEl = document.getElementById('card-producao-quantidade');
        var tabelaEl = cardEl ? cardEl.querySelector('.bo-table-wrap') : null;
        var tabelaHtml = tabelaEl ? tabelaEl.innerHTML : '';
        var mesTxt = DATA.mesTxt || 'Média Força / Seco';

        var printWin = window.open('', '_blank', 'width=1150,height=850');
        if (!printWin) {
            alert('Por favor, permita popups para imprimir o gráfico.');
            return;
        }

        printWin.document.write(`
            <!DOCTYPE html>
            <html lang="pt-BR">
            <head>
                <meta charset="utf-8">
                <title>Produção — Quantidade & Produção por Linha</title>
                <style>
                    @page { size: A4 landscape; margin: 5mm 7mm; }
                    * { box-sizing: border-box; margin: 0; padding: 0; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
                    html, body { width: 100%; height: 100%; background: #ffffff; color: #0f172a; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; }
                    .print-card { width: 100%; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px; box-sizing: border-box; }
                    .card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
                    .card-title { font-size: 15px; font-weight: 700; color: #0f172a; }
                    .chart-box { width: 100%; height: 100mm; margin-bottom: 8px; display: flex; align-items: center; justify-content: center; }
                    .chart-box img { width: 100%; height: 100%; object-fit: contain; display: block; }
                    .table-section { border-top: 1px solid #e2e8f0; padding-top: 10px; margin-top: 4px; }
                    .table-header { margin-bottom: 8px; }
                    .table-header .table-title { font-size: 13px; font-weight: 700; color: #0f172a; }
                    .bo-table-wrap { width: 100%; overflow: hidden; }
                    .bo-nucleo-table { width: 100% !important; border-collapse: collapse !important; font-size: 8.5px !important; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif !important; }
                    .bo-nucleo-table th, .bo-nucleo-table td { padding: 4px 5px !important; text-align: right !important; border: none !important; border-bottom: 1px solid #e2e8f0 !important; white-space: nowrap !important; font-size: 8.5px !important; color: #1e293b !important; line-height: 1.25 !important; }
                    .bo-nucleo-table th { background: #f8fafc !important; color: #64748b !important; font-size: 8px !important; text-transform: uppercase !important; font-weight: 700 !important; letter-spacing: 0.5px !important; border-bottom: 1.5px solid #cbd5e1 !important; }
                    .bo-nucleo-table th:first-child, .bo-nucleo-table td:first-child { text-align: left !important; font-weight: 600 !important; padding-left: 4px !important; width: 10% !important; }
                    .bo-nucleo-table th:nth-last-child(2), .bo-nucleo-table td:nth-last-child(2), .bo-nucleo-table th:last-child, .bo-nucleo-table td:last-child { font-weight: 700 !important; }
                    .bo-nucleo-table td.bo-zero { color: #cbd5e1 !important; }
                </style>
            </head>
            <body>
                <div class="print-card">
                    <div class="card-header"><span class="card-title">Produção - Laboratório (Média Força / Seco)</span></div>
                    <div class="chart-box"><img src="${prodImg}" alt="Produção - Laboratório"></div>
                    <div class="table-section">
                        <div class="table-header"><span class="table-title">Produção por Linha — ${mesTxt}</span></div>
                        <div class="bo-table-wrap">${tabelaHtml}</div>
                    </div>
                </div>
                <script>window.onload = function() { setTimeout(function() { window.print(); }, 300); };<\/script>
            </body>
            </html>
        `);
        printWin.document.close();
    };

    window.imprimirMixProducao = function () {
        var sel = document.getElementById('mix-dia-select');
        var diaStr = sel ? sel.value : (DATA.diaPadraoMix || '');
        var diaObj = DATA.mixPorDia && DATA.mixPorDia[diaStr];

        var progCanvas = document.getElementById('chart-mix-prog');
        var realCanvas = document.getElementById('chart-mix-real');
        if (!progCanvas || !realCanvas) {
            alert('Gráficos não encontrados para impressão.');
            return;
        }

        var progImg = progCanvas.toDataURL('image/png');
        var realImg = realCanvas.toDataURL('image/png');

        var prog = diaObj ? diaObj.programado : { pctTPD: 0, pctTPM: 0, pctTPS: 0 };
        var real = diaObj ? diaObj.realizado  : { pctTPD: 0, pctTPM: 0, pctTPS: 0 };
        var partes = diaStr.split('-');
        var dataFmt = (partes.length === 3) ? (partes[2] + '/' + partes[1] + '/' + partes[0]) : diaStr;

        var printWin = window.open('', '_blank', 'width=950,height=700');
        if (!printWin) {
            alert('Por favor, permita popups para imprimir o gráfico.');
            return;
        }

        printWin.document.write(`
            <!DOCTYPE html>
            <html lang="pt-BR">
            <head>
                <meta charset="utf-8">
                <title>Mix Produção - ${dataFmt}</title>
                <style>
                    @page { size: A4 landscape; margin: 10mm; }
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #ffffff; margin: 0; padding: 20px; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 90vh; box-sizing: border-box; }
                    .mix-print-card { width: 100%; max-width: 860px; border: 3px solid #1d4ed8; border-radius: 12px; padding: 28px 24px; box-sizing: border-box; background: #ffffff; }
                    .charts-row { display: flex; justify-content: space-around; align-items: center; gap: 20px; }
                    .chart-column { flex: 1; display: flex; flex-direction: column; align-items: center; text-align: center; }
                    .chart-column:first-child { border-right: 1.5px solid #cbd5e1; padding-right: 20px; }
                    .chart-column h2 { font-size: 26px; font-weight: 800; margin: 0 0 16px 0; color: #1e293b; }
                    .chart-column img { width: 260px; height: 260px; display: block; }
                    .legend-row { display: flex; justify-content: center; gap: 16px; margin-top: 18px; font-size: 14px; font-weight: 700; color: #1e293b; }
                    .dot { display: inline-block; width: 14px; height: 14px; margin-right: 5px; vertical-align: -2px; border-radius: 2px; }
                    .date-banner { font-size: 48px; font-weight: 900; text-align: center; margin-top: 30px; color: #000000; letter-spacing: 2px; }
                </style>
            </head>
            <body>
                <div class="mix-print-card">
                    <div class="charts-row">
                        <div class="chart-column">
                            <h2>Programado</h2>
                            <img src="${progImg}" alt="Gráfico Programado">
                            <div class="legend-row">
                                <span><span class="dot" style="background:#82c341;"></span>% TPD (${prog.pctTPD}%)</span>
                                <span><span class="dot" style="background:#00a86b;"></span>% TPM (${prog.pctTPM}%)</span>
                                <span><span class="dot" style="background:#4a90e2;"></span>% TPS (${prog.pctTPS}%)</span>
                            </div>
                        </div>
                        <div class="chart-column">
                            <h2>Realizado</h2>
                            <img src="${realImg}" alt="Gráfico Realizado">
                            <div class="legend-row">
                                <span><span class="dot" style="background:#82c341;"></span>% TPD (${real.pctTPD}%)</span>
                                <span><span class="dot" style="background:#00a86b;"></span>% TPM (${real.pctTPM}%)</span>
                                <span><span class="dot" style="background:#4a90e2;"></span>% TPS (${real.pctTPS}%)</span>
                            </div>
                        </div>
                    </div>
                    <div class="date-banner">${dataFmt}</div>
                </div>
                <script>window.onload = function() { setTimeout(function() { window.print(); }, 300); };<\/script>
            </body>
            </html>
        `);
        printWin.document.close();
    };

    window.imprimirProducaoPorLinha = function () {
        var cardEl = document.getElementById('card-producao-por-linha');
        if (!cardEl) {
            alert('Elemento de Produção por Linha não encontrado.');
            return;
        }

        var blocos = cardEl.querySelectorAll('.bloco-linha-prod');
        var blocosHtml = '';

        blocos.forEach(function (bloco, idx) {
            var canvas = bloco.querySelector('canvas');
            var imgData = canvas ? canvas.toDataURL('image/png') : '';
            var titulo = bloco.querySelector('h3') ? bloco.querySelector('h3').textContent.trim() : ('Linha ' + (idx + 1));
            var corSpan = bloco.querySelector('span[style*="background"]');
            var corBg = corSpan ? corSpan.style.backgroundColor : '#82c341';
            var headerMetricas = bloco.querySelector('div[style*="font-size:12px"]') ? bloco.querySelector('div[style*="font-size:12px"]').innerHTML : '';
            var tabelaEl = bloco.querySelector('.bo-table-wrap');
            var tabelaHtml = tabelaEl ? tabelaEl.innerHTML : '';

            blocosHtml += `
                <div class="bloco-print">
                    <div class="bloco-header">
                        <div class="bloco-titulo">
                            <span class="dot" style="background:${corBg};"></span>
                            <h3>${titulo}</h3>
                        </div>
                        <div class="bloco-metricas">
                            ${headerMetricas}
                        </div>
                    </div>
                    <div class="chart-box">
                        <img src="${imgData}" alt="${titulo}">
                    </div>
                    <div class="tabela-box">
                        ${tabelaHtml}
                    </div>
                </div>
            `;
        });

        var mesTxt = DATA.mesTxt || 'Média Força / Seco';
        var printWin = window.open('', '_blank', 'width=1150,height=850');
        if (!printWin) {
            alert('Por favor, permita popups para imprimir o relatório.');
            return;
        }

        printWin.document.write(`
            <!DOCTYPE html>
            <html lang="pt-BR">
            <head>
                <meta charset="utf-8">
                <title>PRODUÇÃO POR LINHA - MÉDIA FORÇA / SECO — ${mesTxt}</title>
                <style>
                    @page { size: A4 landscape; margin: 4mm 6mm; }
                    * { box-sizing: border-box; margin: 0; padding: 0; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #ffffff; color: #0f172a; padding: 2px 4px; }
                    .page-title { font-size: 14px; font-weight: 800; color: #0f172a; margin-bottom: 4px; text-transform: uppercase; }
                    .bloco-print { border: 1px solid #e2e8f0; border-radius: 6px; padding: 6px 8px; margin-bottom: 6px; background: #ffffff; }
                    .bloco-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
                    .bloco-titulo { display: flex; align-items: center; gap: 6px; }
                    .bloco-titulo h3 { font-size: 11px; font-weight: 800; color: #0f172a; }
                    .bloco-titulo .dot { width: 10px; height: 10px; border-radius: 2px; }
                    .bloco-metricas { font-size: 9.5px; font-weight: 700; color: #475569; display: flex; gap: 12px; }
                    .bloco-metricas strong { color: #0f172a; }
                    .chart-box { width: 100%; height: 38mm; display: flex; align-items: center; justify-content: center; margin-bottom: 3px; }
                    .chart-box img { width: 100%; height: 100%; object-fit: fill; }
                    .tabela-box table { width: 100% !important; border-collapse: collapse !important; font-size: 7.5px !important; text-align: center; }
                    .tabela-box th, .tabela-box td { padding: 2px 3px !important; border: 1px solid #e2e8f0 !important; white-space: nowrap !important; line-height: 1.15 !important; }
                    .tabela-box th { background: #f8fafc !important; font-weight: 700 !important; color: #475569 !important; }
                    .tabela-box td:first-child, .tabela-box th:first-child { text-align: left !important; min-width: 90px !important; width: 10% !important; padding-left: 4px !important; }
                </style>
            </head>
            <body>
                <div class="page-title">PRODUÇÃO POR LINHA - MÉDIA FORÇA / SECO — ${mesTxt}</div>
                ${blocosHtml}
                <script>window.onload = function() { setTimeout(function() { window.print(); }, 300); };<\/script>
            </body>
            </html>
        `);
        printWin.document.close();
    };

    // ─── 6. Modal de Métricas & Calendário Interativo ─────────────────────────
    var stateDiasAtivos = [];
    var inputMetaTpd = document.getElementById('modal-meta-tpd');
    var inputMetaTps = document.getElementById('modal-meta-tps');
    var inputMetaTpm = document.getElementById('modal-meta-tpm');

    function inicializarDiasModal() {
        var dias = window.CALENDARIO_DIAS || [];
        stateDiasAtivos = [];
        dias.forEach(function (d) {
            if (d.ativo) stateDiasAtivos.push(d.date);
        });
    }
    inicializarDiasModal();

    window.abrirModalMetricas = function () {
        var modal = document.getElementById('modal-metricas');
        if (!modal) return;
        modal.style.display = 'flex';
        inicializarDiasModal();
        renderGradeCalendarioModal();
        recalcularMetasModal();
    };

    window.fecharModalMetricas = function () {
        var modal = document.getElementById('modal-metricas');
        if (modal) modal.style.display = 'none';
    };

    window.renderGradeCalendarioModal = function () {
        var container = document.getElementById('modal-grade-calendario');
        if (!container) return;
        container.innerHTML = '';

        var primeiroDiaSemana = window.PRIMEIRO_DIA_SEMANA || 0;
        var dias = window.CALENDARIO_DIAS || [];

        for (var i = 0; i < primeiroDiaSemana; i++) {
            var emptyDiv = document.createElement('div');
            container.appendChild(emptyDiv);
        }

        dias.forEach(function (d) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'cal-day-btn ' + (stateDiasAtivos.indexOf(d.date) !== -1 ? 'active' : 'inactive');
            btn.innerHTML = '<span style="font-size:12px;font-weight:700;">' + d.dia + '</span>';
            btn.onclick = function () { modalToggleDia(d.date); };
            container.appendChild(btn);
        });

        var elResumoDias = document.getElementById('modal-resumo-dias-uteis');
        if (elResumoDias) elResumoDias.textContent = stateDiasAtivos.length + ' dias';
    };

    window.modalToggleDia = function (dateStr) {
        var idx = stateDiasAtivos.indexOf(dateStr);
        if (idx !== -1) {
            stateDiasAtivos.splice(idx, 1);
        } else {
            stateDiasAtivos.push(dateStr);
        }
        renderGradeCalendarioModal();
        recalcularMetasModal();
    };

    window.modalMarcarSegSex = function () {
        var dias = window.CALENDARIO_DIAS || [];
        stateDiasAtivos = [];
        dias.forEach(function (d) {
            if (!d.fimDeSemana) stateDiasAtivos.push(d.date);
        });
        renderGradeCalendarioModal();
        recalcularMetasModal();
    };

    window.modalMarcarTodos = function () {
        var dias = window.CALENDARIO_DIAS || [];
        stateDiasAtivos = dias.map(function (d) { return d.date; });
        renderGradeCalendarioModal();
        recalcularMetasModal();
    };

    window.modalLimparTodos = function () {
        stateDiasAtivos = [];
        renderGradeCalendarioModal();
        recalcularMetasModal();
    };

    window.recalcularMetasModal = function () {
        var diasUteis = stateDiasAtivos.length;
        var metaDiaTpd = parseFloat(inputMetaTpd ? inputMetaTpd.value : 0) || 0;
        var metaDiaTps = parseFloat(inputMetaTps ? inputMetaTps.value : 0) || 0;
        var metaDiaTpm = parseFloat(inputMetaTpm ? inputMetaTpm.value : 0) || 0;

        var prevTpd = Math.round(metaDiaTpd * diasUteis);
        var prevTps = Math.round(metaDiaTps * diasUteis);
        var prevTpm = Math.round(metaDiaTpm * diasUteis);
        var prevTotal = prevTpd + prevTps + prevTpm;

        var elTpd = document.getElementById('modal-resumo-tpd');
        var elTps = document.getElementById('modal-resumo-tps');
        var elTpm = document.getElementById('modal-resumo-tpm');
        var elTotal = document.getElementById('modal-resumo-total');

        if (elTpd) elTpd.textContent = prevTpd.toLocaleString('pt-BR') + ' un';
        if (elTps) elTps.textContent = prevTps.toLocaleString('pt-BR') + ' un';
        if (elTpm) elTpm.textContent = prevTpm.toLocaleString('pt-BR') + ' un';
        if (elTotal) elTotal.textContent = prevTotal.toLocaleString('pt-BR') + ' un';
    };

    window.salvarMetricasModal = function (e) {
        e.preventDefault();
        var btn = document.getElementById('btn-salvar-metricas-modal');
        var origText = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = 'Salvando...';
        }

        var metaDiaTpd = inputMetaTpd ? inputMetaTpd.value : 0;
        var metaDiaTps = inputMetaTps ? inputMetaTps.value : 0;
        var metaDiaTpm = inputMetaTpm ? inputMetaTpm.value : 0;

        var fd = new FormData();
        fd.append('acao', 'metas_forca_salvar');
        fd.append('month', window.MES_REFERENCIA);
        fd.append('meta_dia_tpd', metaDiaTpd);
        fd.append('meta_dia_tps', metaDiaTps);
        fd.append('meta_dia_tpm', metaDiaTpm);
        fd.append('dias_producao', JSON.stringify(stateDiasAtivos));

        fetch(window.BOLETIM_API, {
            method: 'POST',
            body: fd,
        })
        .then(function (res) { return res.json(); })
        .then(function (res) {
            if (res.sucesso) {
                fecharModalMetricas();
                window.location.reload();
            } else {
                alert(res.erro || res.mensagem || 'Erro ao salvar métricas e calendário.');
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = origText;
                }
            }
        })
        .catch(function (err) {
            alert('Erro de comunicação ao salvar métricas e calendário.');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = origText;
            }
        });
    };

    // ─── 7. Atualizar do Banco & Download com Spinner ─────────────────────────
    window.atualizarDoBanco = function (mes) {
        var btn = document.getElementById('btn-sync-banco');
        var origHtml = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" style="display:inline-block;width:14px;height:14px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:spin .75s linear infinite;margin-right:6px;"></span> Sincronizando...';
        }

        var fd = new FormData();
        fd.append('acao', 'atualizar_banco');
        fd.append('mes', mes);

        fetch(window.BOLETIM_API, {
            method: 'POST',
            body: fd,
        })
        .then(function (res) { return res.json(); })
        .then(function (res) {
            if (res.sucesso) {
                window.location.reload();
            } else {
                alert('Erro ao sincronizar: ' + (res.erro || 'Erro desconhecido.'));
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = origHtml;
                }
            }
        })
        .catch(function (err) {
            alert('Erro de conexão ao sincronizar com o banco.');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = origHtml;
            }
        });
    };

    window.baixarArquivoComSpinner = function (btn, url, nomeArquivo) {
        if (!btn || btn.disabled) return;
        var originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" style="display:inline-block;width:14px;height:14px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:spin .75s linear infinite;margin-right:6px;"></span> Gerando CSV...';

        fetch(url)
            .then(function (res) {
                if (!res.ok) throw new Error('Erro no download');
                return res.blob();
            })
            .then(function (blob) {
                var blobUrl = window.URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.style.display = 'none';
                a.href = blobUrl;
                a.download = nomeArquivo;
                document.body.appendChild(a);
                a.click();
                setTimeout(function () {
                    window.URL.revokeObjectURL(blobUrl);
                    a.remove();
                }, 1000);
            })
            .catch(function (err) {
                alert('Erro ao exportar arquivo CSV.');
            })
            .finally(function () {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            });
    };

    // ─── Modal de Detalhes Analíticos de Peças ────────────────────────────────
    var pecasCache = [];
    var filtroPecaAtivo = 'TODOS';

    window.abrirModalDetalhesPecas = async function (dataDia, tipo) {
        var modal = document.getElementById('modal-detalhes-pecas');
        if (!modal) return;

        modal.classList.add('open');
        document.body.style.overflow = 'hidden';

        var mes = window.MES_REFERENCIA || (new Date().toISOString().substring(0, 7));
        var area = window.BOLETIM_AREA || 'forca';

        var tituloTxt = 'Relação de Peças — Média Força';
        if (dataDia) {
            var partes = dataDia.split('-');
            var dataFmt = (partes.length === 3) ? (partes[2] + '/' + partes[1] + '/' + partes[0]) : dataDia;
            tituloTxt += ' — ' + dataFmt;
        } else {
            tituloTxt += ' — Mês ' + mes;
        }

        if (tipo && tipo !== 'TOTAL' && tipo !== 'TODOS') {
            tituloTxt += ' (' + tipo + ')';
        }
        var elTitulo = document.getElementById('modal-pecas-titulo-texto');
        if (elTitulo) elTitulo.textContent = tituloTxt;

        var elLoading = document.getElementById('modal-pecas-loading');
        var elTable = document.getElementById('modal-pecas-table');
        var elEmpty = document.getElementById('modal-pecas-empty-msg');
        var elTbody = document.getElementById('modal-pecas-tbody');
        var elBadgeTotal = document.getElementById('modal-pecas-badge-total');
        var inputBusca = document.getElementById('modal-pecas-input-busca');

        if (inputBusca) inputBusca.value = '';
        if (elLoading) elLoading.style.display = 'block';
        if (elTable) elTable.style.display = 'none';
        if (elEmpty) elEmpty.style.display = 'none';
        if (elTbody) elTbody.innerHTML = '';
        if (elBadgeTotal) elBadgeTotal.textContent = 'Carregando...';

        filtroPecaAtivo = (tipo && tipo !== 'TOTAL') ? tipo : 'TODOS';
        renderizarChipsFiltroPecas(area);

        try {
            var url = (window.BOLETIM_API || '/api/boletim-acao.php') + 
                      '?acao=buscar_detalhes_pecas&mes=' + encodeURIComponent(mes) + 
                      '&data=' + encodeURIComponent(dataDia || '') + 
                      '&area=' + encodeURIComponent(area);

            var res = await fetch(url);
            var data = await res.json();

            if (elLoading) elLoading.style.display = 'none';

            if (data.sucesso && Array.isArray(data.itens)) {
                pecasCache = data.itens;
                window.filtrarTabelaPecasModal();
            } else {
                if (elEmpty) {
                    elEmpty.style.display = 'block';
                    elEmpty.innerHTML = '<div style="color:#dc2626;font-weight:600;">' + (data.erro || 'Não foi possível carregar as peças.') + '</div>';
                }
                if (elBadgeTotal) elBadgeTotal.textContent = '0 peças';
            }
        } catch (err) {
            if (elLoading) elLoading.style.display = 'none';
            if (elEmpty) {
                elEmpty.style.display = 'block';
                elEmpty.innerHTML = '<div style="color:#dc2626;font-weight:600;">Erro de comunicação ao carregar peças.</div>';
            }
            if (elBadgeTotal) elBadgeTotal.textContent = 'Erro';
        }
    };

    window.fecharModalDetalhesPecas = function () {
        var modal = document.getElementById('modal-detalhes-pecas');
        if (!modal) return;
        modal.classList.remove('open');
        document.body.style.overflow = '';
    };

    function renderizarChipsFiltroPecas(area) {
        var container = document.getElementById('modal-pecas-chips-container');
        if (!container) return;

        var tipos = (area === 'forca') 
            ? [{ id: 'TODOS', label: 'Todos' }, { id: 'TPD', label: 'TPD' }, { id: 'TPS', label: 'TPS' }, { id: 'TPM', label: 'TPM' }, { id: 'LAB', label: 'Reprovas' }]
            : [{ id: 'TODOS', label: 'Todos' }, { id: 'ENR', label: 'ENR' }, { id: 'JC', label: 'JC-TRIF' }, { id: 'EMP', label: 'EMP' }, { id: 'LAB', label: 'Reprovas' }];

        container.innerHTML = tipos.map(function (t) {
            var active = (filtroPecaAtivo === t.id) ? 'active' : '';
            return '<button type="button" class="modal-pecas-chip-btn ' + active + '" onclick="window.aplicarChipFiltroPeca(\'' + t.id + '\')">' + t.label + '</button>';
        }).join('');
    }

    window.aplicarChipFiltroPeca = function (tipoId) {
        filtroPecaAtivo = tipoId;
        var area = window.BOLETIM_AREA || 'forca';
        renderizarChipsFiltroPecas(area);
        window.filtrarTabelaPecasModal();
    };

    function renderizarTabelaPecas(lista) {
        var elTable = document.getElementById('modal-pecas-table');
        var elEmpty = document.getElementById('modal-pecas-empty-msg');
        var elTbody = document.getElementById('modal-pecas-tbody');
        var elBadgeTotal = document.getElementById('modal-pecas-badge-total');
        var elContador = document.getElementById('modal-pecas-contador-exibidos');

        if (!elTbody) return;
        elTbody.innerHTML = '';

        if (!lista || lista.length === 0) {
            if (elTable) elTable.style.display = 'none';
            if (elEmpty) elEmpty.style.display = 'block';
            if (elBadgeTotal) elBadgeTotal.textContent = '0 peças';
            if (elContador) elContador.textContent = 'Exibindo 0 registros';
            return;
        }

        if (elTable) elTable.style.display = 'table';
        if (elEmpty) elEmpty.style.display = 'none';
        if (elBadgeTotal) elBadgeTotal.textContent = lista.length + (lista.length === 1 ? ' peça' : ' peças');
        if (elContador) elContador.textContent = 'Exibindo ' + lista.length + ' de ' + pecasCache.length + ' registros';

        var html = '';
        lista.forEach(function (p, idx) {
            var isRep = (p.tipo === 'REPROVA LAB' || p.linha === 'LAB' || p.nucleo_cod === 'LAB');
            var trClass = isRep ? 'is-reprova' : '';
            var badgeNucleoClass = 'badge-nucleo-' + (p.nucleo_cod || p.linha || 'TPD');

            var situacaoHtml = '';
            if (isRep) {
                situacaoHtml = '<span class="badge badge-nucleo-LAB" style="margin-bottom:2px;">REPROVADO LAB</span>' + 
                               '<div style="font-size:0.7rem;color:#b91c1c;margin-top:2px;">' + escapeHtml(p.motivo_reprova || 'Almoxarifado 422') + '</div>';
            } else {
                situacaoHtml = '<span style="color:#15803d;font-weight:600;font-size:0.75rem;">Concluído (Produção)</span>';
            }

            var horario = p.data_audit || p.data_mov || '';
            var dataTurno = p.data_turno || '';

            html += '<tr class="' + trClass + '">' +
                '<td style="text-align:center;color:#94a3b8;font-size:0.75rem;">' + (idx + 1) + '</td>' +
                '<td><span class="badge-peca-serie">' + escapeHtml(p.serie || '—') + '</span></td>' +
                '<td style="font-weight:600;font-family:monospace;">' + escapeHtml(p.of || '—') + '</td>' +
                '<td style="font-weight:600;color:#0f172a;" title="' + escapeHtml(p.projeto || p.referencia || '') + '">' + escapeHtml(p.projeto || p.referencia || '—') + '</td>' +
                '<td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + escapeHtml(p.descricao || '') + '">' + escapeHtml(p.descricao || '—') + '</td>' +
                '<td style="font-weight:600;">' + escapeHtml(p.pedido || '—') + '</td>' +
                '<td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + escapeHtml(p.cliente || '') + '">' + escapeHtml(p.cliente || '—') + '</td>' +
                '<td style="text-align:center;"><span class="badge-nucleo-tag ' + badgeNucleoClass + '">' + escapeHtml(p.nucleo_cod || p.linha || '—') + '</span></td>' +
                '<td style="font-size:0.75rem;white-space:nowrap;" title="Data Turno: ' + escapeHtml(dataTurno) + '">' + escapeHtml(horario) + '</td>' +
                '<td style="font-size:0.75rem;">' + escapeHtml(p.operador || '—') + '</td>' +
                '<td>' + situacaoHtml + '</td>' +
            '</tr>';
        });

        elTbody.innerHTML = html;
    }

    window.filtrarTabelaPecasModal = function () {
        var input = document.getElementById('modal-pecas-input-busca');
        var query = (input ? input.value : '').toLowerCase().trim();

        var filtrados = pecasCache.filter(function (p) {
            // Filtro por Chip ativo
            if (filtroPecaAtivo !== 'TODOS') {
                var nuc = (p.nucleo_cod || '').toUpperCase();
                var lin = (p.linha || '').toUpperCase();
                if (filtroPecaAtivo === 'LAB' || filtroPecaAtivo === 'REP') {
                    if (lin !== 'LAB' && nuc !== 'LAB' && p.tipo !== 'REPROVA LAB') return false;
                } else if (filtroPecaAtivo === 'TPD') {
                    if (lin !== 'TPD' && nuc !== 'TPD') return false;
                } else if (filtroPecaAtivo === 'TPS') {
                    if (lin !== 'TPS' && nuc !== 'TPS') return false;
                } else if (filtroPecaAtivo === 'TPM') {
                    if (lin !== 'TPM' && nuc !== 'TPM') return false;
                }
            }

            if (!query) return true;

            var texto = [
                p.serie,
                p.of,
                p.projeto,
                p.referencia,
                p.descricao,
                p.pedido,
                p.pedido_cliente,
                p.cliente,
                p.operador,
                p.nucleo,
                p.motivo_reprova
            ].join(' ').toLowerCase();

            return texto.indexOf(query) !== -1;
        });

        renderizarTabelaPecas(filtrados);
    };

    window.exportarPecasModalCSV = function () {
        if (!pecasCache || pecasCache.length === 0) {
            alert('Não há dados para exportar.');
            return;
        }

        var csv = [];
        var headers = ['Tipo', 'Data Turno', 'Horario Apontamento', 'Nº Serie', 'OF', 'Projeto/Ref', 'Descricao', 'Pedido', 'Cliente', 'Linha/Nucleo', 'Operador', 'Motivo Reprova'];
        csv.push(headers.join(';'));

        pecasCache.forEach(function (p) {
            var row = [
                '"' + (p.tipo || '').replace(/"/g, '""') + '"',
                '"' + (p.data_turno || '').replace(/"/g, '""') + '"',
                '"' + (p.data_audit || p.data_mov || '').replace(/"/g, '""') + '"',
                '"' + (p.serie || '').replace(/"/g, '""') + '"',
                '"' + (p.of || '').replace(/"/g, '""') + '"',
                '"' + (p.projeto || p.referencia || '').replace(/"/g, '""') + '"',
                '"' + (p.descricao || '').replace(/"/g, '""') + '"',
                '"' + (p.pedido || '').replace(/"/g, '""') + '"',
                '"' + (p.cliente || '').replace(/"/g, '""') + '"',
                '"' + (p.nucleo_cod || p.linha || '').replace(/"/g, '""') + '"',
                '"' + (p.operador || '').replace(/"/g, '""') + '"',
                '"' + (p.motivo_reprova || '').replace(/"/g, '""') + '"',
            ];
            csv.push(row.join(';'));
        });

        var blob = new Blob(['\uFEFF' + csv.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'relacao_pecas_forca_' + (window.MES_REFERENCIA || 'export') + '.csv';
        link.click();
    };

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

})();

