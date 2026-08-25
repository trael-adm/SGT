(function () {
    'use strict';

    // Chart.js >= 4 / chartjs-plugin-datalabels v2
    if (window.Chart && window.ChartDataLabels) {
        Chart.register(window.ChartDataLabels);
    }

    var DATA = window.BOLETIM_CHART_DATA || {};
    var CORES = { ENR: '#82c341', JC: '#4a90e2', EMP: '#00a86b', LAB: '#dc2626' };
    var VERDE  = '#16a34a'; // Realizado
    var AMBAR  = '#e8a020'; // Planejado / Meta
    var TENDENCIA = '#7c3aed'; // Tendência de Fábrica (ritmo atual projetado)

    // ─── 1. Gráfico "Produção — Quantidade" (barras agrupadas + Executado Total + Meta Diária) ─
    var elProducao = document.getElementById('chart-producao');
    if (elProducao && DATA.dias) {
        new Chart(elProducao, {
            type: 'bar',
            data: {
                labels: DATA.dias,
                datasets: [
                    {
                        label: 'ENR',
                        data: DATA.producao.ENR,
                        backgroundColor: CORES.ENR,
                        datalabels: {
                            display: true,
                            anchor: 'end',
                            align: 'top',
                            offset: 2,
                            color: CORES.ENR,
                            font: { weight: 'bold', size: 9 },
                            formatter: function (v) { return v || ''; },
                        },
                    },
                    {
                        label: 'JC-TRIF',
                        data: DATA.producao.JC,
                        backgroundColor: CORES.JC,
                        datalabels: {
                            display: true,
                            anchor: 'end',
                            align: 'top',
                            offset: 2,
                            color: CORES.JC,
                            font: { weight: 'bold', size: 9 },
                            formatter: function (v) { return v || ''; },
                        },
                    },
                    {
                        label: 'EMP',
                        data: DATA.producao.EMP,
                        backgroundColor: CORES.EMP,
                        datalabels: {
                            display: true,
                            anchor: 'end',
                            align: 'top',
                            offset: 2,
                            color: CORES.EMP,
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
                layout: { padding: { top: 24 } },
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

                    var tipo = (label === 'REP') ? 'LAB' : (label === 'JC-TRIF' ? 'JC' : (label === 'Executado Total' ? 'TOTAL' : label));
                    window.abrirModalDetalhesPecas(dataYmd, tipo);
                },
                scales: {
                    x: { title: { display: true, text: 'Dia do mês' } },
                    y: {
                        beginAtZero: true,
                        suggestedMax: function () {
                            var vals = (DATA.metaTotal || []).concat(DATA.executadoTotal || []);
                            return vals.length ? Math.max.apply(null, vals) * 1.15 : undefined;
                        }()
                    },
                },
                plugins: {
                    legend: { position: 'bottom' },
                    datalabels: { display: false }
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
                datasets: [{
                    label: '% do Planejado',
                    data: DATA.percentual,
                    borderColor: VERDE,
                    backgroundColor: 'rgba(22,163,74,0.08)',
                    tension: 0.2,
                    fill: true,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    datalabels: {
                        display: function (ctx) {
                            var v = ctx.dataset.data[ctx.dataIndex];
                            return v > 0;
                        },
                        // No primeiro ponto o balão "top" colide com o rótulo do eixo Y
                        // (fica colado na borda esquerda do gráfico) — nesse caso alinha
                        // pro lado em vez de para cima.
                        align: function (ctx) { return ctx.dataIndex === 0 ? 'right' : 'top'; },
                        offset: 6,
                        color: '#ffffff',
                        backgroundColor: VERDE,
                        borderRadius: 4,
                        padding: { top: 3, bottom: 3, left: 5, right: 5 },
                        font: { weight: 'bold', size: 11 },
                        formatter: function (v) { return v ? v + '%' : ''; }
                    }
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: { top: 30 } },
                scales: {
                    x: { title: { display: true, text: 'Dia do mês' } },
                    y: {
                        beginAtZero: true,
                        suggestedMax: 140,
                        ticks: {
                            callback: function (v) { return v + '%'; }
                        }
                    },
                },
                plugins: {
                    legend: { display: false },
                    datalabels: { display: true }
                },
            },
        });
    }

    // ─── 3. Gráfico "Produção Acumulada" (Realizado Acumulado × Meta Linear) ────
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
                        backgroundColor: 'rgba(22,163,74,0.15)',
                        fill: true,
                        tension: 0.15,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        datalabels: {
                            display: function (ctx) {
                                var v = ctx.dataset.data[ctx.dataIndex];
                                var prev = ctx.dataIndex > 0 ? ctx.dataset.data[ctx.dataIndex - 1] : 0;
                                return v > 0 && v !== prev;
                            },
                            align: 'top',
                            offset: 4,
                            color: VERDE,
                            font: { weight: 'bold', size: 9 },
                            formatter: function (v) {
                                return v ? v.toLocaleString('pt-BR') : '';
                            }
                        }
                    },
                    {
                        label: 'Meta (linear)',
                        data: DATA.acumuladoMeta,
                        borderColor: AMBAR,
                        borderDash: [6, 4],
                        fill: false,
                        pointRadius: 0,
                        datalabels: { display: false }
                    },
                    {
                        label: 'Tendência de Fábrica',
                        data: DATA.acumuladoTendencia,
                        borderColor: TENDENCIA,
                        borderDash: [2, 2],
                        borderWidth: 2,
                        fill: false,
                        pointRadius: 0,
                        datalabels: { display: false }
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: { top: 22 } },
                scales: {
                    x: { title: { display: true, text: 'Dia do mês' } },
                    y: { beginAtZero: true },
                },
                plugins: {
                    legend: { position: 'bottom' },
                    datalabels: { display: true }
                },
            },
        });
    }

    // ─── 4. Gráfico "Mix Produção" (Programado × Realizado em Pizzas) ───────────
    var elMixProg = document.getElementById('chart-mix-prog');
    var elMixReal = document.getElementById('chart-mix-real');
    var chartMixProg = null;
    var chartMixReal = null;
    var CORES_MIX = [CORES.ENR, CORES.EMP, CORES.JC]; // ENR (Verde Claro #82c341), CONV (Verde #00a86b), JC TRIF (Azul #4a90e2)

    if (elMixProg && elMixReal && DATA.mixPorDia) {
        var diaInicial = DATA.diaPadraoMix || Object.keys(DATA.mixPorDia)[0];
        var dadosIniciais = DATA.mixPorDia[diaInicial] || {
            programado: { ENR: 0, EMP: 0, JC: 0 },
            realizado: { ENR: 0, EMP: 0, JC: 0 }
        };

        var progData = [
            dadosIniciais.programado.ENR || 0,
            dadosIniciais.programado.EMP || 0,
            dadosIniciais.programado.JC || 0
        ];
        var realData = [
            dadosIniciais.realizado.ENR || 0,
            dadosIniciais.realizado.EMP || 0,
            dadosIniciais.realizado.JC || 0
        ];

        var pieOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function (context) {
                            var val = context.raw || 0;
                            var sum = context.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                            var pct = sum > 0 ? Math.round((val / sum) * 100) : 0;
                            return ' ' + context.label + ': ' + val.toLocaleString('pt-BR') + ' un (' + pct + '%)';
                        }
                    }
                },
                datalabels: {
                    display: function (ctx) {
                        var v = ctx.dataset.data[ctx.dataIndex];
                        return v > 0;
                    },
                    color: '#ffffff',
                    font: { weight: 'bold', size: 14 },
                    formatter: function (v, ctx) {
                        var sum = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                        if (sum <= 0 || !v) return '';
                        var pct = Math.round((v / sum) * 100);
                        return pct + '%';
                    }
                }
            }
        };

        chartMixProg = new Chart(elMixProg, {
            type: 'pie',
            data: {
                labels: ['%ENR', '% CONV', '% JC TRIF'],
                datasets: [{
                    data: progData,
                    backgroundColor: CORES_MIX,
                    borderWidth: 1,
                    borderColor: '#ffffff'
                }]
            },
            options: pieOptions
        });

        chartMixReal = new Chart(elMixReal, {
            type: 'pie',
            data: {
                labels: ['%ENR', '% CONV', '% JC TRIF'],
                datasets: [{
                    data: realData,
                    backgroundColor: CORES_MIX,
                    borderWidth: 1,
                    borderColor: '#ffffff'
                }]
            },
            options: pieOptions
        });

        // Inicializa legendas com os percentuais iniciais
        if (dadosIniciais.programado) {
            document.getElementById('lbl-prog-enr').textContent = (dadosIniciais.programado.pctENR || 0) + '%';
            document.getElementById('lbl-prog-emp').textContent = (dadosIniciais.programado.pctEMP || 0) + '%';
            document.getElementById('lbl-prog-jc').textContent  = (dadosIniciais.programado.pctJC || 0) + '%';
        }
        if (dadosIniciais.realizado) {
            document.getElementById('lbl-real-enr').textContent = (dadosIniciais.realizado.pctENR || 0) + '%';
            document.getElementById('lbl-real-emp').textContent = (dadosIniciais.realizado.pctEMP || 0) + '%';
            document.getElementById('lbl-real-jc').textContent  = (dadosIniciais.realizado.pctJC || 0) + '%';
        }
    }

    // Função para atualizar dinamicamente o Mix ao mudar o dia no dropdown
    window.atualizarMixDia = function (dataStr) {
        var diaObj = DATA.mixPorDia && DATA.mixPorDia[dataStr];
        if (!diaObj) return;

        var prog = diaObj.programado || { ENR: 0, EMP: 0, JC: 0, pctENR: 0, pctEMP: 0, pctJC: 0 };
        var real = diaObj.realizado || { ENR: 0, EMP: 0, JC: 0, pctENR: 0, pctEMP: 0, pctJC: 0 };

        if (chartMixProg) {
            chartMixProg.data.datasets[0].data = [prog.ENR || 0, prog.EMP || 0, prog.JC || 0];
            chartMixProg.update();
        }
        if (chartMixReal) {
            chartMixReal.data.datasets[0].data = [real.ENR || 0, real.EMP || 0, real.JC || 0];
            chartMixReal.update();
        }

        var elProgEnr = document.getElementById('lbl-prog-enr');
        var elProgEmp = document.getElementById('lbl-prog-emp');
        var elProgJc  = document.getElementById('lbl-prog-jc');
        if (elProgEnr) elProgEnr.textContent = (prog.pctENR || 0) + '%';
        if (elProgEmp) elProgEmp.textContent = (prog.pctEMP || 0) + '%';
        if (elProgJc)  elProgJc.textContent  = (prog.pctJC || 0) + '%';

        var elRealEnr = document.getElementById('lbl-real-enr');
        var elRealEmp = document.getElementById('lbl-real-emp');
        var elRealJc  = document.getElementById('lbl-real-jc');
        if (elRealEnr) elRealEnr.textContent = (real.pctENR || 0) + '%';
        if (elRealEmp) elRealEmp.textContent = (real.pctEMP || 0) + '%';
        if (elRealJc)  elRealJc.textContent  = (real.pctJC || 0) + '%';

        var elDestaque = document.getElementById('mix-data-destaque');
        if (elDestaque) {
            elDestaque.textContent = diaObj.dataFmt || dataStr;
        }
    };

    // Função para imprimir o gráfico de Produção - Quantidade e a tabela de Produção por Núcleo em folha A4 Paisagem
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
        var mesTxt = DATA.mesTxt || 'Distribuição';

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
                <title>Produção — Quantidade & Produção por Núcleo</title>
                <style>
                    @page {
                        size: A4 landscape;
                        margin: 5mm 7mm;
                    }
                    * {
                        box-sizing: border-box;
                        margin: 0;
                        padding: 0;
                        -webkit-print-color-adjust: exact !important;
                        print-color-adjust: exact !important;
                    }
                    html, body {
                        width: 100%;
                        height: 100%;
                        background: #ffffff;
                        color: #0f172a;
                        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                    }
                    .print-card {
                        width: 100%;
                        background: #ffffff;
                        border: 1px solid #e2e8f0;
                        border-radius: 8px;
                        padding: 12px 16px;
                        box-sizing: border-box;
                    }
                    .card-header {
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        margin-bottom: 8px;
                    }
                    .card-title {
                        font-size: 15px;
                        font-weight: 700;
                        color: #0f172a;
                    }
                    .chart-box {
                        width: 100%;
                        height: 100mm;
                        margin-bottom: 8px;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                    }
                    .chart-box img {
                        width: 100%;
                        height: 100%;
                        object-fit: contain;
                        display: block;
                    }
                    .table-section {
                        border-top: 1px solid #e2e8f0;
                        padding-top: 10px;
                        margin-top: 4px;
                    }
                    .table-header {
                        margin-bottom: 8px;
                    }
                    .table-header .table-title {
                        font-size: 13px;
                        font-weight: 700;
                        color: #0f172a;
                    }
                    .bo-table-wrap {
                        width: 100%;
                        overflow: hidden;
                    }
                    .bo-nucleo-table {
                        width: 100% !important;
                        border-collapse: collapse !important;
                        font-size: 8.5px !important;
                        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif !important;
                    }
                    .bo-nucleo-table th,
                    .bo-nucleo-table td {
                        padding: 4px 5px !important;
                        text-align: right !important;
                        border: none !important;
                        border-bottom: 1px solid #e2e8f0 !important;
                        white-space: nowrap !important;
                        font-size: 8.5px !important;
                        color: #1e293b !important;
                        line-height: 1.25 !important;
                    }
                    .bo-nucleo-table th {
                        background: #f8fafc !important;
                        color: #64748b !important;
                        font-size: 8px !important;
                        text-transform: uppercase !important;
                        font-weight: 700 !important;
                        letter-spacing: 0.5px !important;
                        border-bottom: 1.5px solid #cbd5e1 !important;
                    }
                    .bo-nucleo-table th:first-child,
                    .bo-nucleo-table td:first-child {
                        text-align: left !important;
                        font-weight: 600 !important;
                        padding-left: 4px !important;
                        width: 10% !important;
                    }
                    .bo-nucleo-table th:nth-last-child(2),
                    .bo-nucleo-table td:nth-last-child(2),
                    .bo-nucleo-table th:last-child,
                    .bo-nucleo-table td:last-child {
                        font-weight: 700 !important;
                    }
                    .bo-nucleo-table td.bo-zero {
                        color: #cbd5e1 !important;
                    }
                    .bo-nucleo-table tr:nth-last-child(3) td {
                        font-weight: 700 !important;
                        border-top: 1px solid #cbd5e1 !important;
                        border-bottom: 1px solid #e2e8f0 !important;
                    }
                    .bo-nucleo-table tr:nth-last-child(2) td {
                        font-weight: 700 !important;
                        color: #0f172a !important;
                    }
                    .bo-nucleo-table tr:last-child td {
                        border-bottom: none !important;
                    }
                </style>
            </head>
            <body>
                <div class="print-card">
                    <div class="card-header">
                        <span class="card-title">Produção - Laboratório</span>
                    </div>
                    <div class="chart-box">
                        <img src="${prodImg}" alt="Produção - Laboratório">
                    </div>
                    <div class="table-section">
                        <div class="table-header">
                            <span class="table-title">Produção por Núcleo — ${mesTxt}</span>
                        </div>
                        <div class="bo-table-wrap">
                            ${tabelaHtml}
                        </div>
                    </div>
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
    };

    // Função para imprimir exclusivamente o widget de Mix Produção centralizado
    window.imprimirMixProducao = function () {
        var sel = document.getElementById('mix-dia-select');
        var diaStr = sel ? sel.value : (DATA.diaPadraoMix || '');
        var diaObj = DATA.mixPorDia && DATA.mixPorDia[diaStr];
        var dataFmt = (diaObj && diaObj.dataFmt) ? diaObj.dataFmt : diaStr;

        var progCanvas = document.getElementById('chart-mix-prog');
        var realCanvas = document.getElementById('chart-mix-real');
        if (!progCanvas || !realCanvas) {
            alert('Gráficos não encontrados para impressão.');
            return;
        }

        var progImg = progCanvas.toDataURL('image/png');
        var realImg = realCanvas.toDataURL('image/png');

        var prog = diaObj ? diaObj.programado : { pctENR: 0, pctEMP: 0, pctJC: 0 };
        var real = diaObj ? diaObj.realizado : { pctENR: 0, pctEMP: 0, pctJC: 0 };

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
                    body {
                        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                        background: #ffffff;
                        margin: 0;
                        padding: 20px;
                        display: flex;
                        flex-direction: column;
                        align-items: center;
                        justify-content: center;
                        min-height: 90vh;
                        box-sizing: border-box;
                    }
                    .mix-print-card {
                        width: 100%;
                        max-width: 860px;
                        border: 3px solid #1d4ed8;
                        border-radius: 12px;
                        padding: 28px 24px;
                        box-sizing: border-box;
                        background: #ffffff;
                    }
                    .charts-row {
                        display: flex;
                        justify-content: space-around;
                        align-items: center;
                        gap: 20px;
                    }
                    .chart-column {
                        flex: 1;
                        display: flex;
                        flex-direction: column;
                        align-items: center;
                        text-align: center;
                    }
                    .chart-column:first-child {
                        border-right: 1.5px solid #cbd5e1;
                        padding-right: 20px;
                    }
                    .chart-column h2 {
                        font-size: 26px;
                        font-weight: 800;
                        margin: 0 0 16px 0;
                        color: #1e293b;
                    }
                    .chart-column img {
                        width: 260px;
                        height: 260px;
                        display: block;
                    }
                    .legend-row {
                        display: flex;
                        justify-content: center;
                        gap: 16px;
                        margin-top: 18px;
                        font-size: 14px;
                        font-weight: 700;
                        color: #1e293b;
                    }
                    .dot {
                        display: inline-block;
                        width: 14px;
                        height: 14px;
                        margin-right: 5px;
                        vertical-align: -2px;
                        border-radius: 2px;
                    }
                    .date-banner {
                        font-size: 48px;
                        font-weight: 900;
                        text-align: center;
                        margin-top: 30px;
                        color: #000000;
                        letter-spacing: 2px;
                        font-family: Arial, Helvetica, sans-serif;
                    }
                </style>
            </head>
            <body>
                <div class="mix-print-card">
                    <div class="charts-row">
                        <div class="chart-column">
                            <h2>Programado</h2>
                            <img src="${progImg}" alt="Gráfico Programado">
                            <div class="legend-row">
                                <span><span class="dot" style="background:#82c341;"></span>%ENR (${prog.pctENR}%)</span>
                                <span><span class="dot" style="background:#00a86b;"></span>% CONV (${prog.pctEMP}%)</span>
                                <span><span class="dot" style="background:#4a90e2;"></span>% JC TRIF (${prog.pctJC}%)</span>
                            </div>
                        </div>
                        <div class="chart-column">
                            <h2>Realizado</h2>
                            <img src="${realImg}" alt="Gráfico Realizado">
                            <div class="legend-row">
                                <span><span class="dot" style="background:#82c341;"></span>%ENR (${real.pctENR}%)</span>
                                <span><span class="dot" style="background:#00a86b;"></span>% CONV (${real.pctEMP}%)</span>
                                <span><span class="dot" style="background:#4a90e2;"></span>% JC TRIF (${real.pctJC}%)</span>
                            </div>
                        </div>
                    </div>
                    <div class="date-banner">${dataFmt}</div>
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
    };

    // ─── 5. Gráficos "Produção por Linha" (ENROLADO, CONVENCIONAL, JC - TRIFÁSICO) ──
    var chartsLinhas = {};
    if (DATA.producaoPorLinha && DATA.diasFormatados) {
        var labelsComMedia = DATA.diasFormatados.concat(['MÉDIA']);

        ['ENR', 'EMP', 'JC'].forEach(function (c) {
            var elCanvas = document.getElementById('chart-linha-' + c.toLowerCase());
            var linhaData = DATA.producaoPorLinha[c];
            if (!elCanvas || !linhaData) return;

            var valoresComMedia = linhaData.execs.concat([linhaData.mediaExec || 0]);
            var corPrincipal = linhaData.info.cor || CORES[c];

            // Cores: mesma cor para os dias e destaque na barra de MÉDIA
            var bgColors = linhaData.execs.map(function () { return corPrincipal; });
            bgColors.push(corPrincipal);

            var maxVal = Math.max.apply(null, valoresComMedia);

            chartsLinhas[c] = new Chart(elCanvas, {
                type: 'bar',
                data: {
                    labels: labelsComMedia,
                    datasets: [{
                        data: valoresComMedia,
                        backgroundColor: bgColors,
                        borderRadius: 3,
                        datalabels: {
                            display: true,
                            anchor: 'end',
                            align: 'top',
                            offset: 2,
                            color: '#1e293b',
                            font: { weight: 'bold', size: 10 },
                            formatter: function (v) {
                                if (!v || v <= 0) return '';
                                return Number.isInteger(v) ? v : v.toFixed(1);
                            }
                        }
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: { padding: { top: 24 } },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: {
                                font: function (ctx) {
                                    return ctx.tick && ctx.tick.label === 'MÉDIA'
                                        ? { weight: 'bold', size: 11 }
                                        : { size: 10 };
                                }
                            }
                        },
                        y: {
                            beginAtZero: true,
                            suggestedMax: maxVal > 0 ? maxVal * 1.22 : 10,
                            ticks: { font: { size: 10 } }
                        }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    var isMedia = ctx.label === 'MÉDIA';
                                    return (isMedia ? 'Média Diária: ' : 'Produção: ') + ctx.raw + ' un';
                                }
                            }
                        }
                    }
                }
            });
        });
    }

    // Função para imprimir os 3 blocos de "Produção por Linha" exatamente em 1 página A4 Paisagem
    window.imprimirProducaoPorLinha = function () {
        var enrCanvas = document.getElementById('chart-linha-enr');
        var empCanvas = document.getElementById('chart-linha-emp');
        var jcCanvas  = document.getElementById('chart-linha-jc');

        var enrImg = enrCanvas ? enrCanvas.toDataURL('image/png') : '';
        var empImg = empCanvas ? empCanvas.toDataURL('image/png') : '';
        var jcImg  = jcCanvas ? jcCanvas.toDataURL('image/png') : '';

        var areaEl = document.getElementById('area-print-producao-linhas');
        if (!areaEl) {
            alert('Área de impressão não encontrada.');
            return;
        }

        var titulos = [
            'ENROLADO - PRODUÇÃO',
            'CONVENCIONAL - PRODUÇÃO',
            'JC - TRIFÁSICO - PRODUÇÃO'
        ];

        var blocos = areaEl.querySelectorAll('.bloco-linha-prod');
        var htmlBlocos = '';

        blocos.forEach(function (bloco, idx) {
            var titulo = titulos[idx] || (bloco.querySelector('h3') ? bloco.querySelector('h3').textContent.trim() : '');
            var tabela = bloco.querySelector('table') ? bloco.querySelector('table').outerHTML : '';
            var imgTag = '';
            if (idx === 0 && enrImg) imgTag = `<img src="${enrImg}" alt="${titulo}">`;
            if (idx === 1 && empImg) imgTag = `<img src="${empImg}" alt="${titulo}">`;
            if (idx === 2 && jcImg)  imgTag = `<img src="${jcImg}" alt="${titulo}">`;

            htmlBlocos += `
                <div class="bloco-linha">
                    <div class="bloco-lateral">
                        <span>${titulo}</span>
                    </div>
                    <div class="bloco-conteudo">
                        <div class="bloco-grafico">
                            ${imgTag}
                        </div>
                        <div class="bloco-tabela">
                            ${tabela}
                        </div>
                    </div>
                </div>
            `;
        });

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
                <title>Produção por Linha - Distribuição</title>
                <style>
                    @page {
                        size: A4 landscape;
                        margin: 2.5mm 3.5mm;
                    }
                    * {
                        box-sizing: border-box;
                        margin: 0;
                        padding: 0;
                        -webkit-print-color-adjust: exact !important;
                        print-color-adjust: exact !important;
                    }
                    html, body {
                        width: 100%;
                        height: 100%;
                        background: #ffffff;
                        color: #0f172a;
                        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
                        overflow: hidden;
                    }
                    .print-container {
                        width: 100%;
                        height: 100%;
                        display: flex;
                        flex-direction: column;
                        justify-content: space-between;
                    }
                    .print-header {
                        text-align: center;
                        padding: 0;
                        margin-bottom: 2px;
                    }
                    .print-header h1 {
                        font-size: 13px;
                        font-weight: 900;
                        color: #0f172a;
                        letter-spacing: 0.5px;
                        text-transform: uppercase;
                        line-height: 1.1;
                    }
                    .blocos-wrapper {
                        flex: 1;
                        display: flex;
                        flex-direction: column;
                        justify-content: space-between;
                        gap: 3px;
                        height: calc(100% - 18px);
                    }
                    .bloco-linha {
                        display: flex;
                        flex-direction: row;
                        border: 1px solid #64748b;
                        border-radius: 2px;
                        height: calc((100% - 6px) / 3);
                        max-height: calc((100% - 6px) / 3);
                        box-sizing: border-box;
                        overflow: hidden;
                        page-break-inside: avoid;
                    }
                    .bloco-lateral {
                        width: 24px;
                        min-width: 24px;
                        max-width: 24px;
                        background: #f8fafc;
                        border-right: 1px solid #64748b;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        box-sizing: border-box;
                    }
                    .bloco-lateral span {
                        writing-mode: vertical-rl;
                        transform: rotate(180deg);
                        white-space: nowrap;
                        font-size: 8px;
                        font-weight: 800;
                        color: #0f172a;
                        letter-spacing: 0.5px;
                        text-transform: uppercase;
                    }
                    .bloco-conteudo {
                        flex: 1;
                        display: flex;
                        flex-direction: column;
                        width: calc(100% - 24px);
                        height: 100%;
                        box-sizing: border-box;
                    }
                    .bloco-grafico {
                        height: 53%;
                        width: 100%;
                        border-bottom: 1px solid #cbd5e1;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        overflow: hidden;
                        background: #ffffff;
                    }
                    .bloco-grafico img {
                        width: 100%;
                        height: 100%;
                        object-fit: fill;
                        display: block;
                    }
                    .bloco-tabela {
                        height: 47%;
                        width: 100%;
                        display: flex;
                        box-sizing: border-box;
                        overflow: hidden;
                    }
                    .bloco-tabela table {
                        width: 100% !important;
                        height: 100% !important;
                        border-collapse: collapse !important;
                        table-layout: fixed !important;
                        font-size: 7.8px !important;
                        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif !important;
                    }
                    .bloco-tabela th, .bloco-tabela td {
                        border: 0.5px solid #cbd5e1 !important;
                        padding: 1px 1px !important;
                        text-align: center !important;
                        line-height: 1.1 !important;
                        overflow: hidden !important;
                        text-overflow: ellipsis !important;
                        white-space: nowrap !important;
                        font-size: 7.8px !important;
                    }
                    .bloco-tabela th {
                        background: #f1f5f9 !important;
                        font-weight: 700 !important;
                        color: #1e293b !important;
                    }
                    .bloco-tabela th:first-child, .bloco-tabela td:first-child {
                        width: 13.5% !important;
                        min-width: 0 !important;
                        text-align: left !important;
                        padding-left: 3px !important;
                        font-weight: 700 !important;
                    }
                    .bloco-tabela th:nth-last-child(2), .bloco-tabela td:nth-last-child(2),
                    .bloco-tabela th:last-child, .bloco-tabela td:last-child {
                        width: 4.2% !important;
                        font-weight: 800 !important;
                        background: #f8fafc !important;
                    }
                </style>
            </head>
            <body>
                <div class="print-container">
                    <div class="print-header">
                        <h1>PRODUÇÃO POR LINHA - DISTRIBUIÇÃO</h1>
                    </div>
                    <div class="blocos-wrapper">
                        ${htmlBlocos}
                    </div>
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
        var area = window.BOLETIM_AREA || 'distrib';

        var tituloTxt = 'Relação de Peças';
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
        var area = window.BOLETIM_AREA || 'distrib';
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
            var badgeNucleoClass = 'badge-nucleo-' + (p.nucleo_cod || p.linha || 'ENR');

            var situacaoHtml = '';
            if (isRep) {
                situacaoHtml = '<span class="badge badge-nucleo-LAB" style="margin-bottom:2px;">REPROVADO LAB</span>' + 
                               '<div style="font-size:0.7rem;color:#b91c1c;margin-top:2px;">' + escapeHtml(p.motivo_reprova || 'Almoxarifado 22/422') + '</div>';
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
                } else if (filtroPecaAtivo === 'JC') {
                    if (nuc !== 'JC') return false;
                } else if (filtroPecaAtivo === 'ENR') {
                    if (nuc !== 'ENR') return false;
                } else if (filtroPecaAtivo === 'EMP') {
                    if (nuc !== 'EMP') return false;
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
        var headers = ['Tipo', 'Data Turno', 'Horario Apontamento', 'Nº Serie', 'OF', 'Projeto/Ref', 'Descricao', 'Pedido', 'Cliente', 'Nucleo/Linha', 'Operador', 'Motivo Reprova'];
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
        link.download = 'relacao_pecas_' + (window.MES_REFERENCIA || 'export') + '.csv';
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

