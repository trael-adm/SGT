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

    // ─── Glow sincronizado na coluna do dia na tabela de núcleos (retângulo único) ──
    // Escopado a #card-producao-quantidade para não vazar para as tabelas de
    // "Produção por Linha" (que também usam .bo-nucleo-table + [data-dia] e têm
    // seu próprio glow, isolado por bloco — ver mais abaixo).
    function boClearColGlow() {
        document.querySelectorAll('#card-producao-quantidade .bo-col-glow').forEach(function (el) {
            el.classList.remove('bo-col-glow', 'bo-col-glow-top', 'bo-col-glow-bottom');
        });
    }
    function boApplyColGlow(dataYmd) {
        boClearColGlow();
        var cells = document.querySelectorAll('#card-producao-quantidade [data-dia="' + dataYmd + '"]');
        cells.forEach(function (el) {
            el.classList.add('bo-col-glow');
        });
        if (cells.length > 0) {
            cells[0].classList.add('bo-col-glow-top');
            cells[cells.length - 1].classList.add('bo-col-glow-bottom');
        }
    }

    // ─── Plugin para desenhar o Glow no quadrante do dia inteiro no gráfico ──
    var dayColumnGlowPlugin = {
        id: 'dayColumnGlow',
        beforeDraw: function (chart) {
            if (chart._hoveredDayIndex !== undefined && chart._hoveredDayIndex !== null && chart._hoveredDayIndex >= 0) {
                var ctx = chart.ctx;
                var chartArea = chart.chartArea;
                var xAxis = chart.scales.x;
                var index = chart._hoveredDayIndex;
                var totalCols = (chart.data.labels || []).length;
                if (totalCols > 0 && index < totalCols && chartArea) {
                    var xCenter = xAxis.getPixelForTick(index);
                    var colWidth = chartArea.width / totalCols;
                    ctx.save();
                    // Glow suave verde no quadrante vertical do dia
                    ctx.fillStyle = 'rgba(34, 197, 94, 0.15)';
                    ctx.fillRect(xCenter - colWidth / 2, chartArea.top, colWidth, chartArea.bottom - chartArea.top);
                    // Borda do quadrante
                    ctx.strokeStyle = 'rgba(34, 197, 94, 0.55)';
                    ctx.lineWidth = 1.5;
                    ctx.strokeRect(xCenter - colWidth / 2, chartArea.top, colWidth, chartArea.bottom - chartArea.top);
                    ctx.restore();
                }
            }
        }
    };

    // ─── 1. Gráfico "Produção — Quantidade" (barras agrupadas + Executado Total + Meta Diária) ─
    var elProducao = document.getElementById('chart-producao');
    var chartProducaoInstance = null;

    if (elProducao && DATA.dias) {
        chartProducaoInstance = new Chart(elProducao, {
            type: 'bar',
            plugins: [dayColumnGlowPlugin],
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
                        spanGaps: false,
                        fill: false,
                        order: 0,
                        datalabels: {
                            display: true,
                            offset: 6,
                            color: VERDE,
                            font: { weight: 'bold', size: 10 },
                            formatter: function (v) { return (v !== null && v !== undefined && v > 0) ? v : ''; },
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
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                onHover: function (evt, elements, chart) {
                    if (evt.native && evt.native.target) {
                        evt.native.target.style.cursor = 'pointer';
                    }
                    var pts = chart.getElementsAtEventForMode(evt, 'index', { intersect: false }, true);
                    var dayIndex = (pts && pts.length > 0) ? pts[0].index : null;
                    
                    if (chart._hoveredDayIndex !== dayIndex) {
                        chart._hoveredDayIndex = dayIndex;
                        chart.draw();
                    }

                    if (dayIndex !== null && DATA.dias && DATA.dias[dayIndex] !== undefined) {
                        var diaNum = DATA.dias[dayIndex];
                        var mes = window.MES_REFERENCIA || (new Date().toISOString().substring(0, 7));
                        var dataYmd = mes + '-' + String(diaNum).padStart(2, '0');
                        boApplyColGlow(dataYmd);
                    } else {
                        boClearColGlow();
                    }
                },
                onClick: function (evt, elements, chart) {
                    var pts = chart.getElementsAtEventForMode(evt, 'index', { intersect: false }, true);
                    if (!pts || pts.length === 0) return;
                    var index = pts[0].index;
                    var diaNum = DATA.dias[index];
                    var mes = window.MES_REFERENCIA || (new Date().toISOString().substring(0, 7));
                    var dataYmd = mes + '-' + String(diaNum).padStart(2, '0');
                    window.abrirModalDetalhesPecas(dataYmd, 'TOTAL');
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

        // Limpar glow ao sair do gráfico
        elProducao.addEventListener('mouseleave', function () {
            if (chartProducaoInstance) {
                chartProducaoInstance._hoveredDayIndex = null;
                chartProducaoInstance.draw();
            }
            boClearColGlow();
        });

        // Sincronização de Glow ao passar o mouse na tabela de núcleos
        document.querySelectorAll('#card-producao-quantidade [data-dia]').forEach(function (cell) {
            cell.style.cursor = 'pointer';
            cell.addEventListener('mouseenter', function () {
                var d = this.getAttribute('data-dia');
                if (!d) return;
                boApplyColGlow(d);
                if (chartProducaoInstance && DATA.dias) {
                    var partes = d.split('-');
                    var diaNum = parseInt(partes[2] || d, 10);
                    var idx = DATA.dias.indexOf(diaNum);
                    if (idx !== -1) {
                        chartProducaoInstance._hoveredDayIndex = idx;
                        chartProducaoInstance.draw();
                    }
                }
            });
            cell.addEventListener('mouseleave', function () {
                boClearColGlow();
                if (chartProducaoInstance) {
                    chartProducaoInstance._hoveredDayIndex = null;
                    chartProducaoInstance.draw();
                }
            });
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
                plugins: [dayColumnGlowPlugin],
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
                    onHover: function (evt, elements, chart) {
                        var pts = chart.getElementsAtEventForMode(evt, 'index', { intersect: false }, true);
                        var idx = (pts && pts.length > 0 && pts[0].index < linhaData.execs.length) ? pts[0].index : null;
                        if (chart._hoveredDayIndex !== idx) {
                            chart._hoveredDayIndex = idx;
                            chart.draw();
                        }
                        var bloco = elCanvas.closest('.bloco-linha-prod');
                        if (bloco) linhaAplicarGlow(bloco, idx);
                    },
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

            elCanvas.addEventListener('mouseleave', function () {
                var chart = chartsLinhas[c];
                if (chart) { chart._hoveredDayIndex = null; chart.draw(); }
                var bloco = elCanvas.closest('.bloco-linha-prod');
                if (bloco) linhaLimparGlow(bloco);
            });
        });
    }

    // Glow sincronizado nas colunas das tabelas de "Produção por Linha" (um retângulo
    // por bloco/linha, igual ao da tabela "Produção - Laboratório" — ver bo-col-glow),
    // e no quadrante do gráfico daquele mesmo bloco (dayColumnGlowPlugin).
    function linhaLimparGlow(bloco) {
        bloco.querySelectorAll('.bo-col-glow').forEach(function (el) {
            el.classList.remove('bo-col-glow', 'bo-col-glow-top', 'bo-col-glow-bottom');
        });
    }
    function linhaAplicarGlow(bloco, idx) {
        linhaLimparGlow(bloco);
        if (idx === null || idx === undefined || idx < 0) return;
        var linhas = bloco.querySelectorAll('tbody tr');
        var cells = [];
        linhas.forEach(function (tr) {
            var tds = tr.querySelectorAll('[data-dia]');
            if (tds[idx]) cells.push(tds[idx]);
        });
        var ths = bloco.querySelectorAll('thead [data-dia]');
        if (ths[idx]) cells.unshift(ths[idx]);
        cells.forEach(function (el) { el.classList.add('bo-col-glow'); });
        if (cells.length > 0) {
            cells[0].classList.add('bo-col-glow-top');
            cells[cells.length - 1].classList.add('bo-col-glow-bottom');
        }
    }
    document.querySelectorAll('.bloco-linha-prod [data-dia]').forEach(function (cell) {
        cell.addEventListener('mouseenter', function () {
            var bloco = this.closest('.bloco-linha-prod');
            var tr = this.closest('tr');
            if (!bloco || !tr) return;
            var idx = Array.prototype.indexOf.call(tr.children, this) - 1;
            linhaAplicarGlow(bloco, idx);

            var canvas = bloco.querySelector('canvas');
            var chartKey = canvas ? canvas.id.replace('chart-linha-', '').toUpperCase() : null;
            var chartInst = chartKey ? chartsLinhas[chartKey] : null;
            if (chartInst && idx >= 0) {
                chartInst._hoveredDayIndex = idx;
                chartInst.draw();
            }
        });
        cell.addEventListener('mouseleave', function () {
            var bloco = this.closest('.bloco-linha-prod');
            if (!bloco) return;
            linhaLimparGlow(bloco);

            var canvas = bloco.querySelector('canvas');
            var chartKey = canvas ? canvas.id.replace('chart-linha-', '').toUpperCase() : null;
            var chartInst = chartKey ? chartsLinhas[chartKey] : null;
            if (chartInst) {
                chartInst._hoveredDayIndex = null;
                chartInst.draw();
            }
        });
    });

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

    // ─── Modal de Análise Diária & Detalhes de Peças ───────────────────────────
    var pecasCache = [];
    var filtroPecaAtivo = 'TODOS';
    var clienteSelecionadoModal = null;
    var topClientesAtuais = [];
    var chartModalPieInstance = null;
    var chartModalBarInstance = null;
    var paletaCoresClientes = ['#10b981', '#0ea5e9', '#f59e0b', '#8b5cf6', '#ec4899', '#06b6d4', '#64748b'];


    window.abrirModalDetalhesPecas = async function (dataDia, tipo) {
        var modal = document.getElementById('modal-detalhes-pecas');
        if (!modal) return;

        modal.classList.add('open');
        document.body.style.overflow = 'hidden';

        var mes = window.MES_REFERENCIA || (new Date().toISOString().substring(0, 7));
        var area = window.BOLETIM_AREA || 'distrib';

        var tituloTxt = 'Análise Diária de Produção';
        if (dataDia) {
            var partes = dataDia.split('-');
            if (partes.length === 3) {
                var dtObj = new Date(parseInt(partes[0], 10), parseInt(partes[1], 10) - 1, parseInt(partes[2], 10));
                var diasSemana = ['Domingo', 'Segunda-Feira', 'Terça-Feira', 'Quarta-Feira', 'Quinta-Feira', 'Sexta-Feira', 'Sábado'];
                var nomeSemana = diasSemana[dtObj.getDay()] || '';
                tituloTxt = 'Análise de Produção — ' + partes[2] + '/' + partes[1] + '/' + partes[0] + ' (' + nomeSemana + ')';
            } else {
                tituloTxt += ' — ' + dataDia;
            }
        } else {
            tituloTxt += ' — Mês ' + mes;
        }

        var elTitulo = document.getElementById('modal-pecas-titulo-texto');
        if (elTitulo) elTitulo.textContent = tituloTxt;

        var elLoading = document.getElementById('modal-pecas-loading');
        var elTable = document.getElementById('modal-pecas-table');
        var elEmpty = document.getElementById('modal-pecas-empty-msg');
        var elTbody = document.getElementById('modal-pecas-tbody');
        var inputBusca = document.getElementById('modal-pecas-input-busca');

        if (inputBusca) inputBusca.value = '';
        window.atualizarBotaoLimparBuscaModal();
        clienteSelecionadoModal = null;
        window.atualizarVisualFiltroClienteModal();

        if (elLoading) elLoading.style.display = 'block';
        if (elTable) elTable.style.display = 'none';
        if (elEmpty) elEmpty.style.display = 'none';
        if (elTbody) elTbody.innerHTML = '';

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
                atualizarKpisEGraficosModal(dataDia);
                window.filtrarTabelaPecasModal();
            } else {
                if (elEmpty) {
                    elEmpty.style.display = 'block';
                    elEmpty.innerHTML = '<div style="color:#dc2626;font-weight:600;">' + (data.erro || 'Nenhuma produção encontrada para este dia.') + '</div>';
                }
            }
        } catch (err) {
            console.error('Erro ao carregar peças:', err);
            if (elLoading) elLoading.style.display = 'none';
            if (elEmpty) {
                elEmpty.style.display = 'block';
                elEmpty.innerHTML = '<div style="color:#dc2626;font-weight:600;">Erro de comunicação ao carregar os dados.</div>';
            }
        }
    };

    window.fecharModalDetalhesPecas = function () {
        var modal = document.getElementById('modal-detalhes-pecas');
        if (!modal) return;
        modal.classList.remove('open');
        document.body.style.overflow = '';
        closeSeriePopover();
    };

    // ─── Popover da lista completa de Nº de Série (botão de expandir na tabela) ──
    function closeSeriePopover() {
        var pop = document.querySelector('.serie-popover');
        if (!pop) return;
        if (pop._ownerBtn) pop._ownerBtn.classList.remove('is-open');
        pop.remove();
    }

    window.toggleSeriePopover = function (evt, btn) {
        evt.stopPropagation();
        var jaAberto = btn.classList.contains('is-open');
        closeSeriePopover();
        if (jaAberto) return;

        var seriesList = (btn.getAttribute('data-series') || '').split(',').filter(Boolean);
        if (seriesList.length === 0) return;

        var pop = document.createElement('div');
        pop.className = 'serie-popover';
        pop._ownerBtn = btn;
        var tituloPopover = seriesList.length === 1 ? '1 Número de Série' : (seriesList.length + ' Números de Série');
        pop.innerHTML = '<div class="serie-popover-title">' + tituloPopover + '</div>' +
            '<div class="serie-popover-list">' +
            seriesList.map(function (s) { return '<span class="serie-popover-item">' + escapeHtml(s) + '</span>'; }).join('') +
            '</div>';
        document.body.appendChild(pop);

        var rect = btn.getBoundingClientRect();
        var popRect = pop.getBoundingClientRect();
        var top = rect.bottom + 4;
        if (top + popRect.height > window.innerHeight - 8) {
            top = rect.top - popRect.height - 4;
        }
        var left = rect.left;
        if (left + popRect.width > window.innerWidth - 8) {
            left = window.innerWidth - popRect.width - 8;
        }
        pop.style.top = Math.max(8, top) + 'px';
        pop.style.left = Math.max(8, left) + 'px';

        btn.classList.add('is-open');
    };

    document.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('.serie-cell-btn, .serie-popover')) return;
        closeSeriePopover();
    });
    document.addEventListener('scroll', function (e) {
        if (e.target && e.target.closest && e.target.closest('.serie-popover')) return;
        closeSeriePopover();
    }, true);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeSeriePopover();
    });

    function normalizarNomeCliente(nome, tpMercado) {
        if (!nome) return 'Trael';
        var n = String(nome).trim();
        if (!n || n === '—' || n === '-' || n.toUpperCase() === 'CLIENTE NÃO INFORMADO' || n.toUpperCase() === 'CLIENTE NÃO IDENTIFICADO') {
            return 'Trael';
        }
        var upper = n.toUpperCase();
        if (upper.indexOf('EQUATORIAL') !== -1 || upper.indexOf('EQTL') !== -1) {
            return 'Equatorial';
        }
        if (upper.indexOf('ENERGISA') !== -1 || /\b(EMS|EMT|ESE|EPB|ETO|ESS|ERO|EAC|ENF|EMR)\b/.test(upper)) {
            return 'Energisa';
        }
        if (upper.indexOf('COPEL') !== -1) {
            return 'Copel';
        }
        if (upper.indexOf('CEMIG') !== -1) {
            return 'Cemig';
        }
        if (upper.indexOf('COELBA') !== -1 || upper.indexOf('NEOENERGIA') !== -1 || upper.indexOf('CELPE') !== -1 || upper.indexOf('COSERN') !== -1 || upper.indexOf('ELEKTRO') !== -1) {
            return 'Neoenergia';
        }
        if (upper.indexOf('CPFL') !== -1 || upper.indexOf('RGE') !== -1) {
            return 'CPFL';
        }
        if (upper.indexOf('ENEL') !== -1 || upper.indexOf('AMPLA') !== -1 || upper.indexOf('COELCE') !== -1) {
            return 'Enel';
        }
        if (upper.indexOf('EDP') !== -1 || upper.indexOf('ESCELSA') !== -1) {
            return 'EDP';
        }
        if (upper.indexOf('CELESC') !== -1) {
            return 'Celesc';
        }
        if (upper.indexOf('LIGHT') !== -1) {
            return 'Light';
        }
        if (upper.indexOf('TRAEL') !== -1 || upper.indexOf('ESTOQUE') !== -1 || upper.indexOf('INTERNO') !== -1) {
            return 'Trael';
        }
        var mercUpper = (tpMercado ? String(tpMercado) : '').toUpperCase().trim();
        if (mercUpper === 'VAR' || upper.indexOf('VAR') !== -1 || upper.indexOf('PARTICULAR') !== -1) {
            return 'Particular';
        }
        // Qualquer outro cliente privado / particular
        return 'Particular';
    }

    function atualizarKpisEGraficosModal(dataDia) {
        var totalProd = 0;
        var totalReprovas = 0;
        var projetosProdSet = new Set();
        var projetosReprovasSet = new Set();
        var clientesMap = {};

        pecasCache.forEach(function (p) {
            var proj = (p.projeto || p.referencia || '').trim();
            var isRep = (p.tipo === 'REPROVA LAB' || p.linha === 'LAB' || p.nucleo_cod === 'LAB');
            if (isRep) {
                totalReprovas++;
                if (proj) projetosReprovasSet.add(proj);
            } else {
                totalProd++;
                if (proj) projetosProdSet.add(proj);
            }

            var cli = normalizarNomeCliente(p.cliente, p.tp_mercado);
            if (!clientesMap[cli]) clientesMap[cli] = 0;
            clientesMap[cli]++;
        });

        var elTotal = document.getElementById('modal-kpi-total');
        var elTotalSub = document.getElementById('modal-kpi-total-sub');
        var elMeta = document.getElementById('modal-kpi-meta');
        var elRep = document.getElementById('modal-kpi-reprovas');
        var elRepSub = document.getElementById('modal-kpi-reprovas-sub');
        var elCli = document.getElementById('modal-kpi-clientes');
        var elCliSub = document.getElementById('modal-kpi-clientes-sub');

        if (elTotal) elTotal.textContent = totalProd + ' un';
        if (elTotalSub) elTotalSub.textContent = projetosProdSet.size + ' projetos';

        if (elRep) elRep.textContent = totalReprovas + ' un';
        if (elRepSub) elRepSub.textContent = projetosReprovasSet.size + ' projetos';

        var listaClientes = Object.keys(clientesMap).map(function (k) {
            return { nome: k, qtd: clientesMap[k] };
        }).sort(function (a, b) { return b.qtd - a.qtd; });

        if (elCli) elCli.textContent = listaClientes.length + (listaClientes.length === 1 ? ' cliente' : ' clientes');
        if (elCliSub) elCliSub.textContent = 'grupos consolidados';

        if (elMeta) {
            var metaVal = (typeof DATA !== 'undefined' && DATA.metaTotal && DATA.metaTotal[0]) ? DATA.metaTotal[0] : 0;
            if (metaVal > 0) {
                var pct = Math.round((totalProd / metaVal) * 100);
                elMeta.textContent = metaVal + ' un (' + pct + '%)';
            } else {
                elMeta.textContent = '—';
            }
        }

        renderizarGraficosClienteModal(listaClientes, totalProd + totalReprovas);
    }

    function renderizarGraficosClienteModal(listaClientes, totalGeral) {
        try {
            var elPie = document.getElementById('chart-modal-cliente-pie');
            var elBar = document.getElementById('chart-modal-cliente-bar');

            if (chartModalPieInstance) {
                chartModalPieInstance.destroy();
                chartModalPieInstance = null;
            }
            if (chartModalBarInstance) {
                chartModalBarInstance.destroy();
                chartModalBarInstance = null;
            }

            if (!listaClientes || listaClientes.length === 0) return;

            var topLabels = [];
            var topQtds = [];
            var outrosQtd = 0;

            listaClientes.forEach(function (c, idx) {
                if (idx < 6) {
                    topLabels.push(c.nome);
                    topQtds.push(c.qtd);
                } else {
                    outrosQtd += c.qtd;
                }
            });

            if (outrosQtd > 0) {
                topLabels.push('OUTROS (' + (listaClientes.length - 6) + ')');
                topQtds.push(outrosQtd);
            }

            topClientesAtuais = topLabels;
            var coresIniciais = paletaCoresClientes.slice(0, topLabels.length);
            var maxQtd = Math.max.apply(null, topQtds.concat([10]));

            if (elPie) {
                chartModalPieInstance = new Chart(elPie, {
                    type: 'doughnut',
                    data: {
                        labels: topLabels,
                        datasets: [{
                            data: topQtds,
                            backgroundColor: coresIniciais,
                            borderWidth: 2,
                            borderColor: '#ffffff',
                            hoverOffset: 4
                        }]
                    },
                    plugins: [{
                        id: 'donutCenterMetric',
                        beforeDraw: function(chart) {
                            var chartArea = chart.chartArea;
                            if (!chartArea) return;
                            var ctx = chart.ctx;
                            ctx.save();
                            var centerX = (chartArea.left + chartArea.right) / 2;
                            var centerY = (chartArea.top + chartArea.bottom) / 2;
                            
                            ctx.textAlign = 'center';
                            ctx.textBaseline = 'middle';
                            
                            ctx.font = 'bold 16px monospace';
                            ctx.fillStyle = '#0f172a';
                            ctx.fillText(totalGeral + ' un', centerX, centerY - 6);
                            
                            ctx.font = '700 9px sans-serif';
                            ctx.fillStyle = '#64748b';
                            ctx.fillText('TOTAL NO DIA', centerX, centerY + 10);
                            ctx.restore();
                        }
                    }],
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '62%',
                        onHover: function (evt, elements) {
                            if (evt.native && evt.native.target) {
                                evt.native.target.style.cursor = (elements && elements.length > 0) ? 'pointer' : 'default';
                            }
                        },
                        onClick: function (evt, elements) {
                            if (!elements || elements.length === 0) return;
                            var idx = elements[0].index;
                            var cli = topLabels[idx];
                            window.alternarFiltroClienteModal(cli);
                        },
                        plugins: {
                            datalabels: { display: false },
                            legend: {
                                position: 'right',
                                labels: {
                                    boxWidth: 10,
                                    boxHeight: 10,
                                    usePointStyle: true,
                                    pointStyle: 'circle',
                                    font: { size: 12, weight: '700' },
                                    color: '#1e293b',
                                    padding: 8,
                                    generateLabels: function(chart) {
                                        var data = chart.data;
                                        if (data.labels.length && data.datasets.length) {
                                            return data.labels.map(function(lbl, i) {
                                                var qtd = data.datasets[0].data[i];
                                                var pct = totalGeral > 0 ? Math.round((qtd / totalGeral) * 100) : 0;
                                                var shortName = lbl.length > 20 ? lbl.substring(0, 18) + '…' : lbl;
                                                var fill = data.datasets[0].backgroundColor[i];
                                                return {
                                                    text: shortName + ' — ' + qtd + ' un (' + pct + '%)',
                                                    fillStyle: fill,
                                                    strokeStyle: fill,
                                                    lineWidth: 0,
                                                    hidden: false,
                                                    index: i
                                                };
                                            });
                                        }
                                        return [];
                                    }
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function (ctx) {
                                        var val = ctx.raw || 0;
                                        var pct = totalGeral > 0 ? Math.round((val / totalGeral) * 100) : 0;
                                        return ' ' + ctx.label + ': ' + val + ' peças (' + pct + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            if (elBar) {
                chartModalBarInstance = new Chart(elBar, {
                    type: 'bar',
                    data: {
                        labels: topLabels.map(function(l) { return l.length > 20 ? l.substring(0, 18) + '…' : l; }),
                        datasets: [{
                            label: 'Peças Produzidas',
                            data: topQtds,
                            backgroundColor: coresIniciais,
                            borderRadius: 4,
                            barPercentage: 0.75,
                            categoryPercentage: 0.85
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        layout: {
                            padding: { right: 42 }
                        },
                        onHover: function (evt, elements) {
                            if (evt.native && evt.native.target) {
                                evt.native.target.style.cursor = (elements && elements.length > 0) ? 'pointer' : 'default';
                            }
                        },
                        onClick: function (evt, elements) {
                            if (!elements || elements.length === 0) return;
                            var idx = elements[0].index;
                            var cli = topLabels[idx];
                            window.alternarFiltroClienteModal(cli);
                        },
                        scales: {
                            x: {
                                beginAtZero: true,
                                suggestedMax: maxQtd * 1.18,
                                grid: {
                                    color: '#f1f5f9'
                                },
                                ticks: { precision: 0, font: { size: 10 } }
                            },
                            y: {
                                grid: { display: false },
                                ticks: { font: { size: 12, weight: 'bold' }, color: '#1e293b' }
                            }
                        },
                        plugins: {
                            datalabels: {
                                display: true,
                                align: 'end',
                                anchor: 'end',
                                offset: 4,
                                font: { size: 12, weight: 'bold', family: 'monospace' },
                                color: '#0f172a',
                                formatter: function(val) {
                                    return val + ' un';
                                }
                            },
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function (ctx) {
                                        return ' Total: ' + ctx.raw + ' peças produzidas';
                                    }
                                }
                            }
                        }
                    }
                });
            }
        } catch (errGraficos) {
            console.warn('Erro ao renderizar gráficos do modal:', errGraficos);
        }
    }

    window.alternarFiltroClienteModal = function (cliNome) {
        if (!cliNome) return;
        if (clienteSelecionadoModal === cliNome) {
            clienteSelecionadoModal = null;
        } else {
            clienteSelecionadoModal = cliNome;
        }
        window.atualizarVisualFiltroClienteModal();
        window.filtrarTabelaPecasModal();
    };

    window.limparFiltroClienteModal = function () {
        clienteSelecionadoModal = null;
        window.atualizarVisualFiltroClienteModal();
        window.filtrarTabelaPecasModal();
    };

    window.atualizarVisualFiltroClienteModal = function () {
        var badge = document.getElementById('modal-pecas-cliente-filtro-badge');
        var badgeNome = document.getElementById('modal-pecas-cliente-filtro-nome');
        if (badge && badgeNome) {
            if (clienteSelecionadoModal) {
                var labelExib = (clienteSelecionadoModal.indexOf('OUTROS') === 0) ? 'Outros Clientes' : clienteSelecionadoModal;
                badgeNome.textContent = 'Cliente: ' + labelExib;
                badge.style.display = 'inline-flex';
            } else {
                badge.style.display = 'none';
            }
        }

        // Atualizar visual dos gráficos com destaque na seleção
        [chartModalPieInstance, chartModalBarInstance].forEach(function (chart) {
            if (!chart || !chart.data || !chart.data.datasets || !chart.data.datasets[0]) return;
            var labels = topClientesAtuais || [];
            var coresBase = paletaCoresClientes.slice(0, labels.length);
            
            if (clienteSelecionadoModal) {
                var novasCores = labels.map(function (lbl, i) {
                    var match = (clienteSelecionadoModal === lbl);
                    if (match) {
                        return coresBase[i];
                    } else {
                        return 'rgba(148, 163, 184, 0.3)'; // Muted
                    }
                });
                chart.data.datasets[0].backgroundColor = novasCores;
            } else {
                chart.data.datasets[0].backgroundColor = coresBase;
            }
            chart.update();
        });
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

    function formatarFaixaSeries(seriesArray) {
        if (!seriesArray || seriesArray.length === 0) return { texto: '—', title: '', lista: [] };
        var unicos = Array.from(new Set(seriesArray.filter(Boolean)));
        if (unicos.length === 0) return { texto: '—', title: '', lista: [] };
        if (unicos.length === 1) return { texto: unicos[0], title: 'Nº de Série: ' + unicos[0], lista: unicos };

        var todosNumericos = unicos.every(function(s) { return !isNaN(Number(s)); });
        if (todosNumericos) {
            unicos.sort(function(a, b) { return Number(a) - Number(b); });
        } else {
            unicos.sort();
        }

        var titleFull = 'Séries (' + unicos.length + ' un): ' + unicos.join(', ');

        if (todosNumericos) {
            var min = unicos[0];
            var max = unicos[unicos.length - 1];
            var numMin = Number(min);
            var numMax = Number(max);
            if (unicos.length === 2) {
                return { texto: (numMax - numMin === 1) ? (min + ' – ' + max) : (min + ', ' + max), title: titleFull, lista: unicos };
            }
            if (numMax - numMin + 1 === unicos.length) {
                return { texto: min + ' – ' + max, title: titleFull, lista: unicos };
            } else {
                return { texto: min + ' … ' + max, title: titleFull, lista: unicos };
            }
        }

        if (unicos.length === 2) {
            return { texto: unicos[0] + ', ' + unicos[1], title: titleFull, lista: unicos };
        }
        return { texto: unicos[0] + ' … ' + unicos[unicos.length - 1], title: titleFull, lista: unicos };
    }

    function renderizarTabelaPecas(lista) {
        var elTable = document.getElementById('modal-pecas-table');
        var elEmpty = document.getElementById('modal-pecas-empty-msg');
        var elTbody = document.getElementById('modal-pecas-tbody');
        var elContador = document.getElementById('modal-pecas-contador-exibidos');

        if (!elTbody) return;
        elTbody.innerHTML = '';

        if (!lista || lista.length === 0) {
            if (elTable) elTable.style.display = 'none';
            if (elEmpty) elEmpty.style.display = 'block';
            if (elContador) elContador.textContent = 'Exibindo 0 registros';
            return;
        }

        if (elTable) elTable.style.display = 'table';
        if (elEmpty) elEmpty.style.display = 'none';

        var agrupadosMap = {};
        var totalPecasFiltradas = 0;

        lista.forEach(function (p) {
            var proj = (p.projeto || p.referencia || '—').trim();
            var desc = (p.descricao || '—').trim();
            var cli  = normalizarNomeCliente(p.cliente, p.tp_mercado);
            var rawCli = (p.cliente || '').trim();
            var cliDisplay = (rawCli && rawCli !== '—' && rawCli !== '-' && rawCli.toUpperCase() !== 'CLIENTE NÃO INFORMADO') ? rawCli : cli;
            var nuc  = (p.nucleo_cod || p.linha || 'ENR').trim();
            var isRep = (p.tipo === 'REPROVA LAB' || p.linha === 'LAB' || p.nucleo_cod === 'LAB');
            var numSerie = (p.serie !== undefined && p.serie !== null) ? String(p.serie).trim() : '';

            var chave = proj + '||' + desc + '||' + cliDisplay + '||' + nuc + '||' + (isRep ? 'REP' : 'OK');
            if (!agrupadosMap[chave]) {
                agrupadosMap[chave] = {
                    projeto: proj,
                    descricao: desc,
                    cliente: cliDisplay,
                    clienteGrupo: cli,
                    nucleo: nuc,
                    isRep: isRep,
                    quantidade: 0,
                    series: []
                };
            }
            agrupadosMap[chave].quantidade++;
            if (numSerie && numSerie !== '—' && numSerie !== '-' && numSerie !== '0') {
                agrupadosMap[chave].series.push(numSerie);
            }
            totalPecasFiltradas++;
        });

        var itensConsolidados = Object.values(agrupadosMap);

        var html = '';
        itensConsolidados.forEach(function (item, idx) {
            var trClass = item.isRep ? 'is-reprova' : '';
            var badgeNucleoClass = 'badge-nucleo-' + (item.isRep ? 'LAB' : item.nucleo);
            var labelNucleo = item.isRep ? 'REPROVA' : (item.nucleo === 'JC' ? 'JC-TRIF' : item.nucleo);
            var infoSerie = formatarFaixaSeries(item.series);
            var serieCellHtml;
            if (infoSerie.lista && infoSerie.lista.length > 0) {
                var tituloBtnSerie = infoSerie.lista.length === 1 ? 'Ver o número de série' : ('Ver todas as ' + infoSerie.lista.length + ' séries');
                serieCellHtml = '<button type="button" class="serie-cell-btn" data-series="' + escapeHtml(infoSerie.lista.join(',')) + '" onclick="window.toggleSeriePopover(event, this)" title="' + tituloBtnSerie + '">' +
                    escapeHtml(infoSerie.texto) +
                    '<svg class="serie-cell-chevron" width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="6 9 12 15 18 9"/></svg>' +
                    '</button>';
            } else {
                serieCellHtml = '<span style="font-family:monospace;font-size:0.80rem;font-weight:700;color:#0284c7;">' + escapeHtml(infoSerie.texto) + '</span>';
            }

            html += '<tr class="' + trClass + '">' +
                '<td style="text-align:center;color:#94a3b8;font-size:0.75rem;font-weight:600;">' + (idx + 1) + '</td>' +
                '<td style="font-weight:800;color:#0f172a;font-family:monospace;font-size:0.82rem;">' + escapeHtml(item.projeto) + '</td>' +
                '<td style="white-space:nowrap;">' + serieCellHtml + '</td>' +
                '<td style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + escapeHtml(item.descricao) + '">' + escapeHtml(item.descricao) + '</td>' +
                '<td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600;color:#334155;" title="' + escapeHtml(item.cliente) + '">' + escapeHtml(item.cliente) + '</td>' +
                '<td style="text-align:center;font-weight:800;font-size:0.85rem;color:#0f172a;font-family:monospace;">' + item.quantidade + ' un</td>' +
                '<td style="text-align:center;"><span class="badge-nucleo-tag ' + badgeNucleoClass + '">' + escapeHtml(labelNucleo) + '</span></td>' +
            '</tr>';
        });

        elTbody.innerHTML = html;
        if (elContador) {
            var labelCliSuffix = clienteSelecionadoModal ? ' de ' + (clienteSelecionadoModal.indexOf('OUTROS') === 0 ? 'Outros Clientes' : clienteSelecionadoModal) : '';
            elContador.textContent = 'Exibindo ' + itensConsolidados.length + ' projetos (' + totalPecasFiltradas + ' peças' + labelCliSuffix + ')';
        }
    }

    window.filtrarTabelaPecasModal = function () {
        var input = document.getElementById('modal-pecas-input-busca');
        var query = (input ? input.value : '').toLowerCase().trim();

        var filtrados = pecasCache.filter(function (p) {
            var cli = normalizarNomeCliente(p.cliente, p.tp_mercado);
            var rawCli = (p.cliente || '').trim();

            // Filtro por Cliente (Cross-Filtering vindo do gráfico)
            if (clienteSelecionadoModal) {
                if (clienteSelecionadoModal.indexOf('OUTROS') === 0) {
                    var top6 = (topClientesAtuais || []).filter(function(l) { return l.indexOf('OUTROS') !== 0; });
                    if (top6.some(function(t) { return t.toUpperCase() === cli.toUpperCase(); })) {
                        return false;
                    }
                } else {
                    if (cli.toUpperCase() !== clienteSelecionadoModal.toUpperCase()) {
                        return false;
                    }
                }
            }



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
                p.projeto,
                p.referencia,
                p.serie,
                p.descricao,
                cli,
                rawCli,
                p.nucleo_cod,
                p.linha
            ].join(' ').toLowerCase();

            return texto.indexOf(query) !== -1;
        });

        renderizarTabelaPecas(filtrados);
    };

    window.atualizarBotaoLimparBuscaModal = function () {
        var input = document.getElementById('modal-pecas-input-busca');
        var btn = document.getElementById('modal-pecas-search-clear');
        if (input && btn) {
            btn.style.display = input.value.trim() ? 'inline-flex' : 'none';
        }
    };

    window.limparBuscaModalPecas = function () {
        var input = document.getElementById('modal-pecas-input-busca');
        if (input) {
            input.value = '';
            input.focus();
        }
        window.atualizarBotaoLimparBuscaModal();
        window.filtrarTabelaPecasModal();
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



