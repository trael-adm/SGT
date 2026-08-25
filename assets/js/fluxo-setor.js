/**
 * SGT — Sistema de Gestão Trael
 * Módulo: Fluxo do Pedido & Acompanhamento de Produção (2026)
 * Tela Dedicada com Topo Travado, Filtros Universais e Linha de Produtos Expansível
 */

document.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    const setorAtivo = urlParams.get('setor') || 'COMERCIAL';

    const state = {
        setor: setorAtivo,
        visaoProducao: 'lotes',  // 'lotes' (padrão) ou 'individual'
        filtroUrgencia: 'todos',
        empresa: '',             // '' (ambas/todas), '1' ou '4'
        dtInicio: '',
        dtFim: '',
        semana: '',
        mes: '',
        ano: '',
        statusFila: 'em_aberto', // Fase 1.1: Inicializa estritamente em 'em_aberto'
        filtrosColuna: {},       // { col: ['val1', 'val2'] }
        ordenacao: { coluna: '', asc: true },
        dadosSetor: null,
        dadosProducao: null,
        expandidos: new Set(),        // IDs dos pedidos com accordion expandido (Setores comerciais)
        lotesExpandidos: new Set(),   // IDs dos lotes com accordion expandido (Produção)
        popupAberto: null,
        carregando: false,
    };

    const tableHeader = document.getElementById('tableHeader');
    const tableBody = document.getElementById('tableBody');
    const modalOverlay = document.getElementById('modalOverlay');
    const modalCloseBtn = document.getElementById('modalCloseBtn');
    const modalBodyContent = document.getElementById('modalBodyContent');
    const advancedFilterBar = document.getElementById('advancedFilterBar');

    montarBarraSuperior();
    carregarSetor();

    document.addEventListener('click', (e) => {
        if (!e.target.closest('.excel-filter-popup') && !e.target.closest('.excel-th-filter-btn')) {
            fecharTodosPopups();
        }
    });

    modalCloseBtn.addEventListener('click', () => modalOverlay.classList.remove('active'));
    modalOverlay.addEventListener('click', (e) => {
        if (e.target === modalOverlay) modalOverlay.classList.remove('active');
    });

    // ──────────────────────────────────────────────────────────────────────────
    // BARRA SUPERIOR DE CONTROLE E FILTROS
    // ──────────────────────────────────────────────────────────────────────────
    function montarBarraSuperior() {
        if (state.setor === 'PRODUCAO') {
            advancedFilterBar.innerHTML = `
                <div class="filter-top-row">
                    <div class="filter-dates-group">
                        <label title="Data de Programação de Produção no PCP">Data PCP:</label>
                        <input type="date" id="filtroDtInicio" class="input-date" title="Data Inicial">
                        <span class="text-muted">até</span>
                        <input type="date" id="filtroDtFim" class="input-date" title="Data Final">

                        <label style="margin-left:6px;">Mês:</label>
                        <select id="filtroMes" class="select-filter">
                            <option value="">Todos</option>
                            <option value="1">Janeiro</option>
                            <option value="2">Fevereiro</option>
                            <option value="3">Março</option>
                            <option value="4">Abril</option>
                            <option value="5">Maio</option>
                            <option value="6">Junho</option>
                            <option value="7">Julho</option>
                            <option value="8">Agosto</option>
                            <option value="9">Setembro</option>
                            <option value="10">Outubro</option>
                            <option value="11">Novembro</option>
                            <option value="12">Dezembro</option>
                        </select>

                        <label style="margin-left:6px;">Ano:</label>
                        <select id="filtroAno" class="select-filter">
                            <option value="">Todos</option>
                            <option value="2026" selected>2026</option>
                            <option value="2025">2025</option>
                            <option value="2024">2024</option>
                        </select>

                        <label style="margin-left:6px;">Semana:</label>
                        <select id="filtroSemana" class="select-filter">
                            <option value="">Todas</option>
                            ${gerarOpcoesSemanas()}
                        </select>

                        <label style="margin-left:6px;">Empresa:</label>
                        <select id="filtroEmpresa" class="select-filter">
                            <option value="" selected>Ambas</option>
                            <option value="1">Empresa 1</option>
                            <option value="4">Empresa 4</option>
                        </select>

                        <label style="margin-left:6px;">Fila:</label>
                        <select id="filtroStatusFila" class="select-filter">
                            <option value="em_aberto" selected>Em Aberto</option>
                            <option value="concluidos">Concluídos</option>
                            <option value="todos">Todos os Registros</option>
                        </select>

                        <button class="btn btn-primary btn-sm" id="btnAplicarFiltros">Filtrar</button>
                        <button class="btn btn-secondary btn-sm" id="btnLimparFiltros">Limpar Filtros</button>
                    </div>

                    <div class="d-flex align-center gap-3">
                        <!-- ALTERNADOR DE VISUAIS (LOTES / INDIVIDUAL) -->
                        <div class="view-mode-toggle" id="viewModeToggle">
                            <button type="button" class="view-mode-btn ${state.visaoProducao === 'lotes' ? 'active' : ''}" data-mode="lotes" title="Visualização agrupada por Lotes com faixa de número de série e expansão">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                                Visão Lotes
                            </button>
                            <button type="button" class="view-mode-btn ${state.visaoProducao === 'individual' ? 'active' : ''}" data-mode="individual" title="Visualização individual linha a linha de cada número de série">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                                Visão Individual
                            </button>
                        </div>

                        <div id="producaoKpisBadges" class="d-flex align-center gap-2"></div>
                    </div>
                </div>
                <!-- QUADRADINHOS DAS 11 CÉLULAS PENDENTES -->
                <div class="celulas-kpis-grid" id="celulasKpisGrid"></div>
            `;

            // Listeners para alternar modos de visão
            document.querySelectorAll('.view-mode-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const mode = btn.dataset.mode;
                    if (state.visaoProducao === mode) return;
                    state.visaoProducao = mode;
                    document.querySelectorAll('.view-mode-btn').forEach(b => b.classList.toggle('active', b.dataset.mode === mode));
                    montarCabecalhoProducao();
                    renderizarProducao();
                });
            });

            document.getElementById('btnAplicarFiltros').addEventListener('click', () => {
                state.dtInicio = document.getElementById('filtroDtInicio').value;
                state.dtFim = document.getElementById('filtroDtFim').value;
                state.mes = document.getElementById('filtroMes').value;
                state.ano = document.getElementById('filtroAno').value;
                state.semana = document.getElementById('filtroSemana').value;
                state.empresa = document.getElementById('filtroEmpresa').value;
                state.statusFila = document.getElementById('filtroStatusFila').value;
                carregarSetor();
            });

            document.getElementById('btnLimparFiltros').addEventListener('click', () => {
                document.getElementById('filtroDtInicio').value = '';
                document.getElementById('filtroDtFim').value = '';
                document.getElementById('filtroMes').value = '';
                document.getElementById('filtroAno').value = '2026';
                document.getElementById('filtroSemana').value = '';
                document.getElementById('filtroEmpresa').value = '';
                document.getElementById('filtroStatusFila').value = 'em_aberto';
                state.dtInicio = '';
                state.dtFim = '';
                state.mes = '';
                state.ano = '2026';
                state.semana = '';
                state.empresa = '';
                state.statusFila = 'em_aberto';
                state.filtrosColuna = {};
                carregarSetor();
            });
        } else {
            advancedFilterBar.innerHTML = `
                <div class="d-flex align-center justify-between" style="flex-wrap:wrap; gap:8px;">
                    <div class="d-flex align-center gap-2">
                        <span class="text-sm font-600 text-secondary">Filtros de Prazo:</span>
                        <button class="urg-btn active" data-urgency="todos">Todos</button>
                        <button class="urg-btn" data-urgency="atrasados">Atrasados</button>
                        <button class="urg-btn" data-urgency="alerta">&lt; 7 Dias</button>
                        <button class="urg-btn" data-urgency="normal">No Prazo</button>
                        <button class="btn btn-secondary btn-sm" id="btnLimparFiltrosSetor" style="margin-left:8px;">Limpar Filtros</button>
                    </div>
                    <div id="setorKpisBadges" class="d-flex align-center gap-2"></div>
                </div>
            `;

            document.querySelectorAll('.urg-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.querySelectorAll('.urg-btn').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    state.filtroUrgencia = btn.dataset.urgency;
                    renderizar();
                });
            });

            document.getElementById('btnLimparFiltrosSetor').addEventListener('click', () => {
                state.filtrosColuna = {};
                state.filtroUrgencia = 'todos';
                document.querySelectorAll('.urg-btn').forEach(b => b.classList.remove('active'));
                document.querySelector('.urg-btn[data-urgency="todos"]').classList.add('active');
                renderizar();
            });
        }
    }

    function gerarOpcoesSemanas() {
        let options = '';
        for (let i = 1; i <= 52; i++) {
            options += `<option value="${i}">Sem. ${i}</option>`;
        }
        return options;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // CARREGAMENTO DOS DADOS (API)
    // ──────────────────────────────────────────────────────────────────────────
    async function carregarSetor() {
        state.carregando = true;
        tableBody.innerHTML = `<tr><td colspan="21" class="text-center text-muted" style="padding: 36px;">Carregando registros do setor ${state.setor}...</td></tr>`;

        if (state.setor === 'PRODUCAO') {
            try {
                let url = `${window.FLUXO_API_URL}?action=planilha_producao&status_fila=${state.statusFila}`;
                if (state.dtInicio) url += `&dt_inicio=${encodeURIComponent(state.dtInicio)}`;
                if (state.dtFim) url += `&dt_fim=${encodeURIComponent(state.dtFim)}`;
                if (state.mes) url += `&mes=${encodeURIComponent(state.mes)}`;
                if (state.ano) url += `&ano=${encodeURIComponent(state.ano)}`;
                if (state.semana) url += `&semana=${encodeURIComponent(state.semana)}`;
                if (state.empresa) url += `&empresa=${encodeURIComponent(state.empresa)}`;

                const res = await fetch(url);
                const data = await res.json();
                state.dadosProducao = data;
                montarCabecalhoProducao();
                renderizarProducao();
            } catch (err) {
                tableBody.innerHTML = `<tr><td colspan="21" class="text-center text-danger" style="padding:30px;">Erro ao carregar planilha de produção.</td></tr>`;
            }
        } else {
            try {
                const res = await fetch(`${window.FLUXO_API_URL}?action=setor_pedidos&setor=${encodeURIComponent(state.setor)}`);
                const data = await res.json();
                state.dadosSetor = data;
                montarCabecalhoSetor();
                renderizarSetor();
            } catch (err) {
                tableBody.innerHTML = `<tr><td colspan="21" class="text-center text-danger" style="padding:30px;">Erro ao carregar pedidos do setor.</td></tr>`;
            }
        }
        state.carregando = false;
    }

    function renderizar() {
        if (state.setor === 'PRODUCAO') {
            renderizarProducao();
        } else {
            renderizarSetor();
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PRODUÇÃO: Montar Cabeçalho (Suporta Modo Lotes e Modo Individual)
    // ──────────────────────────────────────────────────────────────────────────
    function montarCabecalhoProducao() {
        const celulas = ['CH', 'BT', 'AT', 'CNC', 'SOL', 'MN', 'PIN', 'ME', 'MF', 'LAB'];
        
        let thCelulas = celulas.map(c => `
            <th style="width:36px; min-width:36px;">
                <div class="th-content">
                    <span class="th-title" onclick="window.ordenarColuna('${c}')">${c}</span>
                    <button class="excel-th-filter-btn ${temFiltroAtivo(c.toLowerCase()) ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, '${c.toLowerCase()}')" title="Filtrar ${c}">▾</button>
                </div>
            </th>
        `).join('');

        if (state.visaoProducao === 'lotes') {
            tableHeader.innerHTML = `
                <tr>
                    <th style="width:36px; text-align:center;"></th>
                    <th style="width:56px; min-width:54px;">
                        <div class="th-content">
                            <span class="th-title" onclick="window.ordenarColuna('pedido')">PEDIDO</span>
                            <button class="excel-th-filter-btn ${temFiltroAtivo('pedido') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'pedido')" title="Filtrar Pedido">▾</button>
                        </div>
                    </th>
                    <th style="width:42px; min-width:40px; text-align:center;">
                        <div class="th-content" style="justify-content:center;">
                            <span class="th-title" onclick="window.ordenarColuna('empresa')">EMP</span>
                            <button class="excel-th-filter-btn ${temFiltroAtivo('empresa') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'empresa')" title="Filtrar Empresa">▾</button>
                        </div>
                    </th>
                    <th style="width:78px; min-width:75px;" title="Data de Programação de Produção no PCP">
                        <div class="th-content">
                            <span class="th-title" onclick="window.ordenarColuna('data_raw')">DATA PCP</span>
                            <button class="excel-th-filter-btn ${temFiltroAtivo('data') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'data')" title="Filtrar Data">▾</button>
                        </div>
                    </th>
                    <th style="width:115px; min-width:110px;">
                        <div class="th-content">
                            <span class="th-title" onclick="window.ordenarColuna('projeto')">PROJETO</span>
                            <button class="excel-th-filter-btn ${temFiltroAtivo('projeto') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'projeto')" title="Filtrar Projeto">▾</button>
                        </div>
                    </th>
                    <th style="min-width:140px;">
                        <div class="th-content">
                            <span class="th-title" onclick="window.ordenarColuna('cliente')">CLIENTE</span>
                            <button class="excel-th-filter-btn ${temFiltroAtivo('cliente') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'cliente')" title="Filtrar Cliente">▾</button>
                        </div>
                    </th>
                    <th style="width:44px;">
                        <div class="th-content">
                            <span class="th-title" onclick="window.ordenarColuna('qtde')">QTDE</span>
                        </div>
                    </th>
                    <th style="width:42px;">
                        <div class="th-content">
                            <span class="th-title" onclick="window.ordenarColuna('pot')">POT</span>
                            <button class="excel-th-filter-btn ${temFiltroAtivo('pot') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'pot')" title="Filtrar Pot">▾</button>
                        </div>
                    </th>
                    <th style="width:40px;">
                        <div class="th-content">
                            <span class="th-title" onclick="window.ordenarColuna('class')">CLASS</span>
                            <button class="excel-th-filter-btn ${temFiltroAtivo('class') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'class')" title="Filtrar Classe">▾</button>
                        </div>
                    </th>
                    <th style="width:48px;">
                        <div class="th-content">
                            <span class="th-title" onclick="window.ordenarColuna('taps')">TAPS</span>
                            <button class="excel-th-filter-btn ${temFiltroAtivo('taps') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'taps')" title="Filtrar Taps">▾</button>
                        </div>
                    </th>
                    <th style="width:85px; min-width:80px;" title="Tipo Construtivo do Transformador">
                        <div class="th-content">
                            <span class="th-title" onclick="window.ordenarColuna('tipo_constr')">TIPO CONSTR.</span>
                            <button class="excel-th-filter-btn ${temFiltroAtivo('tipo_constr') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'tipo_constr')" title="Filtrar Tipo Construtivo">▾</button>
                        </div>
                    </th>
                    <th style="width:38px;">
                        <div class="th-content">
                            <span class="th-title" onclick="window.ordenarColuna('sem')">SEM</span>
                            <button class="excel-th-filter-btn ${temFiltroAtivo('sem') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'sem')" title="Filtrar Semana">▾</button>
                        </div>
                    </th>
                    <th style="width:130px; min-width:120px;" title="Faixa de Números de Série do Lote (Inicial a Final)">
                        <div class="th-content">
                            <span class="th-title" onclick="window.ordenarColuna('nr_serie')">FAIXA DE SÉRIE</span>
                            <button class="excel-th-filter-btn ${temFiltroAtivo('nr_serie') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'nr_serie')" title="Filtrar Série">▾</button>
                        </div>
                    </th>
                    ${thCelulas}
                </tr>
            `;
        } else {
            tableHeader.innerHTML = `
                <tr>
                    <th style="width:56px; min-width:54px;">
                        <div class="th-content">
                            <span class="th-title" onclick="window.ordenarColuna('pedido')">PEDIDO</span>
                            <button class="excel-th-filter-btn ${temFiltroAtivo('pedido') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'pedido')" title="Filtrar Pedido">▾</button>
                        </div>
                    </th>
                    <th style="width:42px; min-width:40px; text-align:center;">
                        <div class="th-content" style="justify-content:center;">
                            <span class="th-title" onclick="window.ordenarColuna('empresa')">EMP</span>
                            <button class="excel-th-filter-btn ${temFiltroAtivo('empresa') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'empresa')" title="Filtrar Empresa">▾</button>
                        </div>
                    </th>
                    <th style="width:78px; min-width:75px;" title="Data de Programação de Produção no PCP">
                        <div class="th-content">
                            <span class="th-title" onclick="window.ordenarColuna('data_raw')">DATA PCP</span>
                            <button class="excel-th-filter-btn ${temFiltroAtivo('data') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'data')" title="Filtrar Data">▾</button>
                        </div>
                    </th>
                    <th style="width:115px; min-width:110px;">
                        <div class="th-content">
                            <span class="th-title" onclick="window.ordenarColuna('projeto')">PROJETO</span>
                            <button class="excel-th-filter-btn ${temFiltroAtivo('projeto') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'projeto')" title="Filtrar Projeto">▾</button>
                        </div>
                    </th>
                    <th style="min-width:140px;">
                        <div class="th-content">
                            <span class="th-title" onclick="window.ordenarColuna('cliente')">CLIENTE</span>
                            <button class="excel-th-filter-btn ${temFiltroAtivo('cliente') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'cliente')" title="Filtrar Cliente">▾</button>
                        </div>
                    </th>
                    <th style="width:38px;">
                        <div class="th-content">
                            <span class="th-title" onclick="window.ordenarColuna('qtde')">QTDE</span>
                        </div>
                    </th>
                    <th style="width:42px;">
                        <div class="th-content">
                            <span class="th-title" onclick="window.ordenarColuna('pot')">POT</span>
                            <button class="excel-th-filter-btn ${temFiltroAtivo('pot') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'pot')" title="Filtrar Pot">▾</button>
                        </div>
                    </th>
                    <th style="width:40px;">
                        <div class="th-content">
                            <span class="th-title" onclick="window.ordenarColuna('class')">CLASS</span>
                            <button class="excel-th-filter-btn ${temFiltroAtivo('class') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'class')" title="Filtrar Classe">▾</button>
                        </div>
                    </th>
                    <th style="width:48px;">
                        <div class="th-content">
                            <span class="th-title" onclick="window.ordenarColuna('taps')">TAPS</span>
                            <button class="excel-th-filter-btn ${temFiltroAtivo('taps') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'taps')" title="Filtrar Taps">▾</button>
                        </div>
                    </th>
                    <th style="width:85px; min-width:80px;" title="Tipo Construtivo do Transformador">
                        <div class="th-content">
                            <span class="th-title" onclick="window.ordenarColuna('tipo_constr')">TIPO CONSTR.</span>
                            <button class="excel-th-filter-btn ${temFiltroAtivo('tipo_constr') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'tipo_constr')" title="Filtrar Tipo Construtivo">▾</button>
                        </div>
                    </th>
                    <th style="width:38px;">
                        <div class="th-content">
                            <span class="th-title" onclick="window.ordenarColuna('sem')">SEM</span>
                            <button class="excel-th-filter-btn ${temFiltroAtivo('sem') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'sem')" title="Filtrar Semana">▾</button>
                        </div>
                    </th>
                    <th style="width:70px; min-width:70px;">
                        <div class="th-content">
                            <span class="th-title" onclick="window.ordenarColuna('nr_serie')">NR SÉRIE</span>
                            <button class="excel-th-filter-btn ${temFiltroAtivo('nr_serie') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'nr_serie')" title="Filtrar Série">▾</button>
                        </div>
                    </th>
                    ${thCelulas}
                </tr>
            `;
        }
    }

    // Função de Agrupamento em Lotes (Produção)
    function agruparTransformadoresEmLotes(lista) {
        const lotesMap = new Map();

        lista.forEach(t => {
            // Chave do lote: Pedido + Empresa + Projeto + Data PCP + Potência
            const emp = t.empresa || '1';
            const key = `${t.pedido}__${emp}__${t.projeto}__${t.data}__${t.pot}`;
            if (!lotesMap.has(key)) {
                lotesMap.set(key, {
                    id: key,
                    pedido: t.pedido,
                    empresa: emp,
                    data: t.data,
                    data_raw: t.data_raw,
                    projeto: t.projeto,
                    desc_projeto: t.desc_projeto,
                    cliente: t.cliente,
                    pot: t.pot,
                    class: t.class,
                    taps: t.taps,
                    tipo_constr: t.tipo_constr || '—',
                    sem: t.sem,
                    itens: [],
                });
            }
            lotesMap.get(key).itens.push(t);
        });

        const lotes = Array.from(lotesMap.values());
        const celulas = ['CH', 'BT', 'AT', 'CNC', 'SOL', 'MN', 'PIN', 'ME', 'MF', 'LAB'];

        lotes.forEach(lote => {
            lote.itens.sort((a, b) => (Number(a.nr_serie) || 0) - (Number(b.nr_serie) || 0));
            lote.qtde = lote.itens.length;
            lote.ns_inicial = lote.itens[0].nr_serie;
            lote.ns_final = lote.itens[lote.itens.length - 1].nr_serie;

            if (lote.ns_inicial === lote.ns_final) {
                lote.faixa_serie = String(lote.ns_inicial);
            } else {
                lote.faixa_serie = `${lote.ns_inicial} – ${lote.ns_final}`;
            }

            lote.celulasStatus = {};
            celulas.forEach(c => {
                let okCount = 0;
                lote.itens.forEach(item => {
                    if (item.setores && item.setores[c] === 'OK') okCount++;
                });
                const pct = lote.qtde > 0 ? Math.round((okCount / lote.qtde) * 100) : 0;
                lote.celulasStatus[c] = {
                    ok: okCount,
                    total: lote.qtde,
                    pct: pct,
                    status: (okCount === lote.qtde) ? 'OK' : ((okCount === 0) ? 'PEND' : `${pct}%`)
                };
            });

            lote.is_concluido = lote.itens.every(x => x.is_concluido);
        });

        return lotes;
    }

    // Toggle de expansão do lote
    window.toggleExpandirLote = function(loteId, event) {
        if (event) event.stopPropagation();
        if (state.lotesExpandidos.has(loteId)) {
            state.lotesExpandidos.delete(loteId);
        } else {
            state.lotesExpandidos.add(loteId);
        }
        renderizarProducao();
    };

    // ──────────────────────────────────────────────────────────────────────────
    // PRODUÇÃO: Renderizar Tabela do Chão de Fábrica (Lotes / Individual)
    // ──────────────────────────────────────────────────────────────────────────
    function renderizarProducao() {
        if (!state.dadosProducao || !state.dadosProducao.itens) return;

        let lista = [...state.dadosProducao.itens];

        // 1. Contagem das 10 Células Pendentes (para os quadradinhos de topo)
        const celulas = ['CH', 'BT', 'AT', 'CNC', 'SOL', 'MN', 'PIN', 'ME', 'MF', 'LAB'];
        const contagemPend = {};
        celulas.forEach(c => contagemPend[c] = 0);

        lista.forEach(item => {
            if (item.setores) {
                celulas.forEach(c => {
                    if (item.setores[c] === 'PEND') contagemPend[c]++;
                });
            }
        });

        // 2. Renderizar Quadradinhos de Células no topo
        const celulasGrid = document.getElementById('celulasKpisGrid');
        if (celulasGrid) {
            celulasGrid.innerHTML = celulas.map(c => {
                const pend = contagemPend[c];
                const isActive = state.filtrosColuna[c.toLowerCase()] && state.filtrosColuna[c.toLowerCase()].includes('PEND');
                return `
                    <div class="celula-badge-card ${isActive ? 'active-filter' : ''}" onclick="window.filtrarPendenciasCelula('${c.toLowerCase()}')" title="Clique para filtrar apenas transformadores pendentes em ${c}">
                        <span class="celula-code-tag">${c}</span>
                        <span class="celula-count-pend ${pend === 0 ? 'zero' : ''}">${pend}</span>
                    </div>
                `;
            }).join('');
        }

        // 3. Aplicar Filtros das Colunas
        Object.keys(state.filtrosColuna).forEach(col => {
            const selecionados = state.filtrosColuna[col];
            if (!selecionados || selecionados.length === 0) return;

            lista = lista.filter(item => {
                let val = '';
                if (col === 'pedido') val = String(item.pedido || '');
                else if (col === 'empresa') val = String(item.empresa || '1');
                else if (col === 'data') val = String(item.data || '');
                else if (col === 'projeto') val = String(item.projeto);
                else if (col === 'cliente') val = String(item.cliente);
                else if (col === 'pot') val = String(item.pot);
                else if (col === 'class') val = String(item.class);
                else if (col === 'taps') val = String(item.taps);
                else if (col === 'tipo_constr') val = String(item.tipo_constr || '');
                else if (col === 'sem') val = String(item.sem);
                else if (col === 'nr_serie') val = String(item.nr_serie);
                else if (item.setores && item.setores[col.toUpperCase()]) {
                    val = item.setores[col.toUpperCase()];
                }
                return selecionados.includes(val);
            });
        });

        const kpiBadge = document.getElementById('producaoKpisBadges');
        const cellVivid = (val) => val === 'OK' ? '<span class="badge badge-success">OK</span>' : '<span class="badge badge-warning">PEND</span>';

        // ──────────────────────────────────────────────────────────────────────
        // RENDERIZAÇÃO: MODO LOTES (PADRÃO)
        // ──────────────────────────────────────────────────────────────────────
        if (state.visaoProducao === 'lotes') {
            const lotes = agruparTransformadoresEmLotes(lista);

            // Ordenação dos Lotes
            if (state.ordenacao.coluna) {
                const col = state.ordenacao.coluna;
                const asc = state.ordenacao.asc ? 1 : -1;
                lotes.sort((a, b) => {
                    let vA = a[col] ?? (a.celulasStatus && a.celulasStatus[col.toUpperCase()] ? a.celulasStatus[col.toUpperCase()].status : '');
                    let vB = b[col] ?? (b.celulasStatus && b.celulasStatus[col.toUpperCase()] ? b.celulasStatus[col.toUpperCase()].status : '');
                    if (col === 'nr_serie') {
                        vA = Number(a.ns_inicial) || 0;
                        vB = Number(b.ns_inicial) || 0;
                    }
                    if (typeof vA === 'string') return vA.localeCompare(vB) * asc;
                    return (vA - vB) * asc;
                });
            } else {
                lotes.sort((a, b) => {
                    let pA = Number(a.pedido) || 0;
                    let pB = Number(b.pedido) || 0;
                    if (pA !== pB) return pA - pB;
                    return (Number(a.ns_inicial) || 0) - (Number(b.ns_inicial) || 0);
                });
            }

            // Atualizar Badges de Resumo
            if (kpiBadge) {
                const concluidos = lotes.filter(x => x.is_concluido).length;
                const pendentes = lotes.length - concluidos;
                kpiBadge.innerHTML = `
                    <span class="badge badge-neutral">Lotes: ${lotes.length} (${lista.length} trafos)</span>
                    <span class="badge badge-warning">Aberto: ${pendentes}</span>
                    <span class="badge badge-success">Pronto: ${concluidos}</span>
                `;
            }

            if (lotes.length === 0) {
                tableBody.innerHTML = `<tr><td colspan="22" class="text-center text-muted" style="padding:40px;">Nenhum lote encontrado com os filtros selecionados.</td></tr>`;
                return;
            }

            let html = '';
            lotes.forEach(lote => {
                const isExpanded = state.lotesExpandidos.has(lote.id);

                const celulasHtml = celulas.map(c => {
                    const info = lote.celulasStatus[c] || { ok: 0, total: lote.qtde, status: 'PEND', pct: 0 };
                    if (info.ok === lote.qtde) {
                        return `<td class="col-cell"><span class="badge badge-success">OK</span></td>`;
                    }
                    if (info.ok === 0) {
                        return `<td class="col-cell"><span class="badge badge-warning">PEND</span></td>`;
                    }
                    const pct = info.pct !== undefined ? info.pct : Math.round((info.ok / lote.qtde) * 100);
                    return `<td class="col-cell" title="${info.ok} de ${lote.qtde} peças concluídas (${pct}%)"><span class="badge badge-orange font-bold">${pct}%</span></td>`;
                }).join('');

                html += `
                    <tr class="order-main-row" onclick="window.toggleExpandirLote('${lote.id}', event)">
                        <td style="text-align:center; width:36px;">
                            <button class="order-expand-btn ${isExpanded ? 'is-expanded' : ''}" title="Clique para detalhar os números de série deste lote">
                                ▸
                            </button>
                        </td>
                        <td style="font-weight:800; color:#16a34a; text-align:center;">${lote.pedido}</td>
                        <td style="text-align:center;"><span class="badge ${lote.empresa === '4' ? 'badge-info' : 'badge-neutral'}" style="font-size:0.75rem; padding:2px 6px; font-weight:700;">${lote.empresa}</span></td>
                        <td style="text-align:center; font-family:var(--font-mono);">${lote.data}</td>
                        <td class="col-proj" title="${lote.projeto}">${lote.projeto}</td>
                        <td style="max-width:160px; overflow:hidden; text-overflow:ellipsis;" title="${lote.cliente}">${lote.cliente}</td>
                        <td style="text-align:center; font-weight:800; color:var(--color-accent);">${lote.qtde}</td>
                        <td style="text-align:right; font-family:var(--font-mono); font-weight:700;">${lote.pot}</td>
                        <td style="text-align:center;">${lote.class}</td>
                        <td style="text-align:center; font-weight:600;">${lote.taps}</td>
                        <td style="text-align:center; font-size:0.75rem; font-weight:600; color:var(--color-text-secondary);" title="${lote.tipo_constr}">${lote.tipo_constr}</td>
                        <td style="text-align:center; font-family:var(--font-mono); font-weight:700;">${lote.sem}</td>
                        <td class="col-faixa-ns" title="Série inicial a final">${lote.faixa_serie}</td>
                        ${celulasHtml}
                    </tr>
                `;

                // Sub-linha Expansível: Detalhamento por Número de Série do Lote
                if (isExpanded) {
                    const subRows = lote.itens.map((item, idx) => {
                        const s = item.setores || {};
                        return `
                            <tr>
                                <td style="font-family:var(--font-mono); font-weight:700; color:var(--color-accent); text-align:center;">${idx + 1}</td>
                                <td style="font-family:var(--font-mono); font-weight:800; color:var(--color-info-text); text-align:center; background:var(--color-info-bg);">${item.nr_serie}</td>
                                <td style="font-family:var(--font-mono); text-align:center; color:var(--color-text-secondary);">${item.of_mae || '—'}</td>
                                <td style="text-align:center;"><span class="badge ${item.empresa === '4' ? 'badge-info' : 'badge-neutral'}" style="font-size:0.75rem; padding:1px 5px; font-weight:700;">${item.empresa || '1'}</span></td>
                                <td class="col-cell">${cellVivid(s.CH)}</td>
                                <td class="col-cell">${cellVivid(s.BT)}</td>
                                <td class="col-cell">${cellVivid(s.AT)}</td>
                                <td class="col-cell">${cellVivid(s.CNC)}</td>
                                <td class="col-cell">${cellVivid(s.SOL)}</td>
                                <td class="col-cell">${cellVivid(s.MN)}</td>
                                <td class="col-cell">${cellVivid(s.PIN)}</td>
                                <td class="col-cell">${cellVivid(s.ME)}</td>
                                <td class="col-cell">${cellVivid(s.MF)}</td>
                                <td class="col-cell">${cellVivid(s.LAB)}</td>
                                <td style="text-align:center;">
                                    ${item.is_concluido ? '<span class="badge badge-success">Pronto</span>' : '<span class="badge badge-warning">Em Aberto</span>'}
                                </td>
                            </tr>
                        `;
                    }).join('');

                    html += `
                        <tr class="order-subrow">
                            <td colspan="22">
                                <div class="sub-card-wrap">
                                    <div class="sub-card-header">
                                        <div class="sub-card-title">
                                            <svg class="sub-card-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <line x1="8" y1="6" x2="21" y2="6"></line>
                                                <line x1="8" y1="12" x2="21" y2="12"></line>
                                                <line x1="8" y1="18" x2="21" y2="18"></line>
                                                <line x1="3" y1="6" x2="3.01" y2="6"></line>
                                                <line x1="3" y1="12" x2="3.01" y2="12"></line>
                                                <line x1="3" y1="18" x2="3.01" y2="18"></line>
                                            </svg>
                                            <span>Detalhamento dos Números de Série — Pedido ${lote.pedido} • Projeto ${lote.projeto}</span>
                                        </div>
                                        <span class="text-xs text-muted font-mono">${lote.qtde} peça(s) • Empresa ${lote.empresa} • Faixa de Série: ${lote.faixa_serie}</span>
                                    </div>
                                    <table class="sub-data-table">
                                        <thead>
                                            <tr>
                                                <th style="width:36px; text-align:center;">#</th>
                                                <th style="width:100px; text-align:center;">Nº DE SÉRIE</th>
                                                <th style="width:85px; text-align:center;">OF-MÃE</th>
                                                <th style="width:42px; text-align:center;">EMP</th>
                                                <th style="width:36px; text-align:center;">CH</th>
                                                <th style="width:36px; text-align:center;">BT</th>
                                                <th style="width:36px; text-align:center;">AT</th>
                                                <th style="width:36px; text-align:center;">CNC</th>
                                                <th style="width:36px; text-align:center;">SOL</th>
                                                <th style="width:36px; text-align:center;">MN</th>
                                                <th style="width:36px; text-align:center;">PIN</th>
                                                <th style="width:36px; text-align:center;">ME</th>
                                                <th style="width:36px; text-align:center;">MF</th>
                                                <th style="width:36px; text-align:center;">LAB</th>
                                                <th style="text-align:center; width:95px;">STATUS</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${subRows}
                                        </tbody>
                                    </table>
                                </div>
                            </td>
                        </tr>
                    `;
                }
            });

            tableBody.innerHTML = html;
            return;
        }

        // ──────────────────────────────────────────────────────────────────────
        // RENDERIZAÇÃO: MODO INDIVIDUAL (ORIGINAL LINHA A LINHA)
        // ──────────────────────────────────────────────────────────────────────
        // 4. Ordenação (Padrão: Mais antigo para o mais novo)
        if (state.ordenacao.coluna) {
            const col = state.ordenacao.coluna;
            const asc = state.ordenacao.asc ? 1 : -1;
            lista.sort((a, b) => {
                let vA = a[col] ?? (a.setores ? a.setores[col.toUpperCase()] : '');
                let vB = b[col] ?? (b.setores ? b.setores[col.toUpperCase()] : '');
                if (typeof vA === 'string') return vA.localeCompare(vB) * asc;
                return (vA - vB) * asc;
            });
        } else {
            lista.sort((a, b) => {
                let pA = Number(a.pedido) || 0;
                let pB = Number(b.pedido) || 0;
                if (pA !== pB) return pA - pB;
                return (Number(a.nr_serie) || 0) - (Number(b.nr_serie) || 0);
            });
        }

        // Atualizar Badges de Resumo
        if (kpiBadge) {
            const concluidos = lista.filter(x => x.is_concluido).length;
            const pendentes = lista.length - concluidos;
            kpiBadge.innerHTML = `
                <span class="badge badge-neutral">Filtrados: ${lista.length}</span>
                <span class="badge badge-warning">Aberto: ${pendentes}</span>
                <span class="badge badge-success">Pronto: ${concluidos}</span>
            `;
        }

        if (lista.length === 0) {
            tableBody.innerHTML = `<tr><td colspan="21" class="text-center text-muted" style="padding:40px;">Nenhum transformador encontrado com os filtros selecionados.</td></tr>`;
            return;
        }

        tableBody.innerHTML = lista.map(item => {
            const s = item.setores;
            return `
                <tr>
                    <td style="font-weight:800; color:#16a34a; text-align:center;">${item.pedido}</td>
                    <td style="text-align:center;"><span class="badge ${item.empresa === '4' ? 'badge-info' : 'badge-neutral'}" style="font-size:0.75rem; padding:2px 6px; font-weight:700;">${item.empresa || '1'}</span></td>
                    <td style="text-align:center; font-family:var(--font-mono);">${item.data}</td>
                    <td class="col-proj" title="${item.projeto}">${item.projeto}</td>
                    <td style="max-width:160px; overflow:hidden; text-overflow:ellipsis;" title="${item.cliente}">${item.cliente}</td>
                    <td style="text-align:center; font-weight:700;">${item.qtde}</td>
                    <td style="text-align:right; font-family:var(--font-mono);">${item.pot}</td>
                    <td style="text-align:center;">${item.class}</td>
                    <td style="text-align:center; font-weight:600;">${item.taps}</td>
                    <td style="text-align:center; font-size:0.75rem; font-weight:600; color:var(--color-text-secondary);" title="${item.tipo_constr}">${item.tipo_constr}</td>
                    <td style="text-align:center; font-family:var(--font-mono); font-weight:700;">${item.sem}</td>
                    <td class="col-ns">${item.nr_serie}</td>
                    <td class="col-cell">${cellVivid(s.CH)}</td>
                    <td class="col-cell">${cellVivid(s.BT)}</td>
                    <td class="col-cell">${cellVivid(s.AT)}</td>
                    <td class="col-cell">${cellVivid(s.CNC)}</td>
                    <td class="col-cell">${cellVivid(s.SOL)}</td>
                    <td class="col-cell">${cellVivid(s.MN)}</td>
                    <td class="col-cell">${cellVivid(s.PIN)}</td>
                    <td class="col-cell" title="Montagem Elétrica / Parte Ativa">${cellVivid(s.ME)}</td>
                    <td class="col-cell">${cellVivid(s.MF)}</td>
                    <td class="col-cell" title="Laboratório / Ensaios">${cellVivid(s.LAB)}</td>
                </tr>
            `;
        }).join('');
    }

    // Ação ao clicar no quadradinho da célula para filtrar direto os pendentes
    window.filtrarPendenciasCelula = function(coluna) {
        if (state.filtrosColuna[coluna] && state.filtrosColuna[coluna].includes('PEND') && state.filtrosColuna[coluna].length === 1) {
            delete state.filtrosColuna[coluna];
        } else {
            state.filtrosColuna[coluna] = ['PEND'];
        }
        montarCabecalhoProducao();
        renderizarProducao();
    };

    // ──────────────────────────────────────────────────────────────────────────
    // POPUP FLUTUANTE DE FILTRO EXCEL (UNIVERSAL PARA TODAS AS COLUNAS)
    // ──────────────────────────────────────────────────────────────────────────
    window.abrirPopupFiltro = function(event, coluna) {
        event.stopPropagation();
        fecharTodosPopups();

        const btn = event.currentTarget;
        const th = btn.closest('th');

        const dataset = (state.setor === 'PRODUCAO') ? (state.dadosProducao?.itens || []) : (state.dadosSetor?.pedidos || []);
        const valoresSet = new Set();

        dataset.forEach(item => {
            let val = '';
            if (coluna === 'pedido' || coluna === 'cdPedido') val = String(item.pedido || item.cdPedido);
            else if (coluna === 'empresa') val = String(item.empresa || '1');
            else if (coluna === 'data' || coluna === 'dt_pedido') val = String(item.data || item.dt_pedido);
            else if (coluna === 'dt_entrega') val = String(item.dt_entrega || '');
            else if (coluna === 'projeto' || coluna === 'produto') {
                if (item.projeto) val = String(item.projeto);
                else if (item.produtos && item.produtos.length > 0) {
                    item.produtos.forEach(pr => valoresSet.add(String(pr.cd_referencia)));
                    return;
                }
            }
            else if (coluna === 'cliente') val = String(item.cliente || item.cliente_apelido || '');
            else if (coluna === 'status_pedido_erp') val = String(item.status_pedido_erp || '');
            else if (coluna === 'setor_motivo') val = String(item.setor_motivo || '');
            else if (coluna === 'tramite_sac') {
                val = item.ultimo_followup ? `${item.ultimo_followup.tipo_cod} - ${item.ultimo_followup.tipo_desc}` : 'Sem SAC';
            }
            else if (coluna === 'pot') val = String(item.pot);
            else if (coluna === 'class') val = String(item.class);
            else if (coluna === 'taps') val = String(item.taps);
            else if (coluna === 'tipo_constr') val = String(item.tipo_constr || '');
            else if (coluna === 'sem') val = String(item.sem);
            else if (coluna === 'nr_serie') val = String(item.nr_serie);
            else if (item.setores && item.setores[coluna.toUpperCase()]) {
                val = item.setores[coluna.toUpperCase()];
            }

            if (val) valoresSet.add(val);
        });

        const valoresArray = Array.from(valoresSet).sort((a, b) => a.localeCompare(b, undefined, { numeric: true }));
        const selecionados = state.filtrosColuna[coluna] || [];

        const popup = document.createElement('div');
        popup.className = 'excel-filter-popup';
        popup.id = `popup_${coluna}`;

        popup.innerHTML = `
            <div class="popup-sort-group">
                <button class="popup-sort-btn" onclick="window.ordenarEFechar('${coluna}', true)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M6 12h12M9 18h6"/></svg>
                    Classificar de A a Z / Menor para Maior
                </button>
                <button class="popup-sort-btn" onclick="window.ordenarEFechar('${coluna}', false)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 18h18M6 12h12M9 6h6"/></svg>
                    Classificar de Z a A / Maior para Menor
                </button>
            </div>
            <input type="text" class="popup-search-input" placeholder="Pesquisar..." oninput="window.filtrarValoresPopup(this, '${coluna}')">
            <div class="popup-values-list" id="list_${coluna}">
                <label class="popup-value-item font-bold" style="border-bottom:1px solid var(--color-border); padding-bottom:4px;">
                    <input type="checkbox" id="chk_all_${coluna}" ${selecionados.length === 0 ? 'checked' : ''} onchange="window.toggleTodosValoresPopup(this, '${coluna}')">
                    (Selecionar Tudo)
                </label>
                ${valoresArray.map(v => `
                    <label class="popup-value-item">
                        <input type="checkbox" class="chk-val-${coluna}" value="${escapeHtml(v)}" ${selecionados.length === 0 || selecionados.includes(v) ? 'checked' : ''}>
                        <span>${escapeHtml(v)}</span>
                    </label>
                `).join('')}
            </div>
            <div class="popup-actions">
                <button class="btn-popup-clear" onclick="window.limparFiltroColuna('${coluna}')">Limpar</button>
                <button class="btn-popup-apply" onclick="window.aplicarFiltroColuna('${coluna}')">Aplicar</button>
            </div>
        `;

        th.appendChild(popup);
        state.popupAberto = popup;

        const inputSearch = popup.querySelector('.popup-search-input');
        if (inputSearch) inputSearch.focus();
    };

    window.filtrarValoresPopup = function(input, coluna) {
        const query = input.value.toLowerCase().trim();
        const items = document.querySelectorAll(`#list_${coluna} .popup-value-item`);
        items.forEach((item, idx) => {
            if (idx === 0) return; // pular "Selecionar Tudo"
            const txt = item.textContent.toLowerCase();
            item.style.display = txt.includes(query) ? 'flex' : 'none';
        });
    };

    window.toggleTodosValoresPopup = function(masterChk, coluna) {
        const checkboxes = document.querySelectorAll(`.chk-val-${coluna}`);
        checkboxes.forEach(c => {
            if (c.closest('.popup-value-item').style.display !== 'none') {
                c.checked = masterChk.checked;
            }
        });
    };

    window.aplicarFiltroColuna = function(coluna) {
        const checkedBoxes = document.querySelectorAll(`.chk-val-${coluna}:checked`);
        const totalBoxes = document.querySelectorAll(`.chk-val-${coluna}`);

        if (checkedBoxes.length === totalBoxes.length || checkedBoxes.length === 0) {
            delete state.filtrosColuna[coluna];
        } else {
            state.filtrosColuna[coluna] = Array.from(checkedBoxes).map(c => c.value);
        }

        fecharTodosPopups();
        if (state.setor === 'PRODUCAO') {
            montarCabecalhoProducao();
        } else {
            montarCabecalhoSetor();
        }
        renderizar();
    };

    window.limparFiltroColuna = function(coluna) {
        delete state.filtrosColuna[coluna];
        fecharTodosPopups();
        if (state.setor === 'PRODUCAO') {
            montarCabecalhoProducao();
        } else {
            montarCabecalhoSetor();
        }
        renderizar();
    };

    window.ordenarColuna = function(coluna) {
        if (state.ordenacao.coluna === coluna) {
            state.ordenacao.asc = !state.ordenacao.asc;
        } else {
            state.ordenacao.coluna = coluna;
            state.ordenacao.asc = true;
        }
        renderizar();
    };

    window.ordenarEFechar = function(coluna, asc) {
        state.ordenacao.coluna = coluna;
        state.ordenacao.asc = asc;
        fecharTodosPopups();
        renderizar();
    };

    function fecharTodosPopups() {
        document.querySelectorAll('.excel-filter-popup').forEach(p => p.remove());
        state.popupAberto = null;
    }

    function temFiltroAtivo(coluna) {
        return !!(state.filtrosColuna[coluna] && state.filtrosColuna[coluna].length > 0);
    }

    function escapeHtml(str) {
        return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // DEMAIS SETORES: Montar Cabeçalhos com Filtros Universais (Excel Style)
    // ──────────────────────────────────────────────────────────────────────────
    function montarCabecalhoSetor() {
        const setor = state.setor;
        if (setor === 'COMERCIAL') {
            tableHeader.innerHTML = `
                <tr>
                    <th style="width:30px;"></th>
                    <th style="width:75px;"><div class="th-content"><span class="th-title" onclick="window.ordenarColuna('cdPedido')">PEDIDO</span><button class="excel-th-filter-btn ${temFiltroAtivo('cdPedido') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'cdPedido')">▾</button></div></th>
                    <th style="width:85px;"><div class="th-content"><span class="th-title" onclick="window.ordenarColuna('dt_pedido')">EMISSÃO</span><button class="excel-th-filter-btn ${temFiltroAtivo('dt_pedido') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'dt_pedido')">▾</button></div></th>
                    <th style="width:130px;"><div class="th-content"><span class="th-title" onclick="window.ordenarColuna('dt_entrega')">PRAZO ENTREGA</span><button class="excel-th-filter-btn ${temFiltroAtivo('dt_entrega') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'dt_entrega')">▾</button></div></th>
                    <th><div class="th-content"><span class="th-title" onclick="window.ordenarColuna('cliente')">CLIENTE</span><button class="excel-th-filter-btn ${temFiltroAtivo('cliente') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'cliente')">▾</button></div></th>
                    <th class="text-center" style="width:50px;"><span class="th-title" onclick="window.ordenarColuna('qtd_saldo_itens')">QTD</span></th>
                    <th class="text-center" style="width:85px;"><div class="th-content"><span class="th-title" onclick="window.ordenarColuna('status_pedido_erp')">STATUS ERP</span><button class="excel-th-filter-btn ${temFiltroAtivo('status_pedido_erp') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'status_pedido_erp')">▾</button></div></th>
                    <th><div class="th-content"><span class="th-title" onclick="window.ordenarColuna('setor_motivo')">SITUAÇÃO DO PEDIDO</span><button class="excel-th-filter-btn ${temFiltroAtivo('setor_motivo') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'setor_motivo')">▾</button></div></th>
                </tr>
            `;
        } else if (setor === 'ENGENHARIA') {
            tableHeader.innerHTML = `
                <tr>
                    <th style="width:30px;"></th>
                    <th style="width:75px;"><div class="th-content"><span class="th-title" onclick="window.ordenarColuna('cdPedido')">PEDIDO</span><button class="excel-th-filter-btn ${temFiltroAtivo('cdPedido') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'cdPedido')">▾</button></div></th>
                    <th><div class="th-content"><span class="th-title" onclick="window.ordenarColuna('cliente')">CLIENTE</span><button class="excel-th-filter-btn ${temFiltroAtivo('cliente') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'cliente')">▾</button></div></th>
                    <th><div class="th-content"><span class="th-title" onclick="window.ordenarColuna('tramite_sac')">TRÂMITE SAC (FOLLOW-UP)</span><button class="excel-th-filter-btn ${temFiltroAtivo('tramite_sac') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'tramite_sac')">▾</button></div></th>
                    <th>DE ➔ PARA</th>
                    <th>DATA ENTRADA</th>
                    <th style="width:130px;"><div class="th-content"><span class="th-title" onclick="window.ordenarColuna('dt_entrega')">PRAZO ENTREGA</span><button class="excel-th-filter-btn ${temFiltroAtivo('dt_entrega') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'dt_entrega')">▾</button></div></th>
                    <th class="text-center" style="width:50px;">QTD</th>
                    <th>MOTIVO TÉCNICO</th>
                </tr>
            `;
        } else if (setor === 'PCP') {
            tableHeader.innerHTML = `
                <tr>
                    <th style="width:30px;"></th>
                    <th style="width:75px;"><div class="th-content"><span class="th-title" onclick="window.ordenarColuna('cdPedido')">PEDIDO</span><button class="excel-th-filter-btn ${temFiltroAtivo('cdPedido') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'cdPedido')">▾</button></div></th>
                    <th><div class="th-content"><span class="th-title" onclick="window.ordenarColuna('cliente')">CLIENTE</span><button class="excel-th-filter-btn ${temFiltroAtivo('cliente') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'cliente')">▾</button></div></th>
                    <th class="text-center">QTD ORIGINAL</th>
                    <th class="text-center">QTD PROGRAMADA</th>
                    <th class="text-center">% PROGRAMADO</th>
                    <th style="width:130px;"><div class="th-content"><span class="th-title" onclick="window.ordenarColuna('dt_entrega')">PRAZO ENTREGA</span><button class="excel-th-filter-btn ${temFiltroAtivo('dt_entrega') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'dt_entrega')">▾</button></div></th>
                    <th><div class="th-content"><span class="th-title" onclick="window.ordenarColuna('setor_motivo')">STATUS PCP</span><button class="excel-th-filter-btn ${temFiltroAtivo('setor_motivo') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'setor_motivo')">▾</button></div></th>
                    <th>TRÂMITE SAC</th>
                </tr>
            `;
        } else if (setor === 'LOGISTICA') {
            tableHeader.innerHTML = `
                <tr>
                    <th style="width:30px;"></th>
                    <th style="width:75px;"><div class="th-content"><span class="th-title" onclick="window.ordenarColuna('cdPedido')">PEDIDO</span><button class="excel-th-filter-btn ${temFiltroAtivo('cdPedido') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'cdPedido')">▾</button></div></th>
                    <th><div class="th-content"><span class="th-title" onclick="window.ordenarColuna('cliente')">CLIENTE</span><button class="excel-th-filter-btn ${temFiltroAtivo('cliente') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'cliente')">▾</button></div></th>
                    <th class="text-center">QTD TOTAL</th>
                    <th class="text-center">SALDO ALMOX. 10 E 11</th>
                    <th><div class="th-content"><span class="th-title" onclick="window.ordenarColuna('setor_motivo')">STATUS EXPEDIÇÃO</span><button class="excel-th-filter-btn ${temFiltroAtivo('setor_motivo') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'setor_motivo')">▾</button></div></th>
                    <th style="width:130px;"><div class="th-content"><span class="th-title" onclick="window.ordenarColuna('dt_entrega')">PRAZO ENTREGA</span><button class="excel-th-filter-btn ${temFiltroAtivo('dt_entrega') ? 'has-filter' : ''}" onclick="window.abrirPopupFiltro(event, 'dt_entrega')">▾</button></div></th>
                </tr>
            `;
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // DEMAIS SETORES: Renderizar Pedidos com Accordion Expansível de Produtos
    // ──────────────────────────────────────────────────────────────────────────
    function renderizarSetor() {
        if (!state.dadosSetor || !state.dadosSetor.pedidos) return;

        let lista = [...state.dadosSetor.pedidos];

        // 1. Filtros de Prazo
        if (state.filtroUrgencia === 'atrasados') {
            lista = lista.filter(p => p.status_prazo === 'atrasado');
        } else if (state.filtroUrgencia === 'alerta') {
            lista = lista.filter(p => p.status_prazo === 'alerta');
        } else if (state.filtroUrgencia === 'normal') {
            lista = lista.filter(p => p.status_prazo === 'no_prazo');
        }

        // 2. Filtros Dinâmicos de Colunas
        Object.keys(state.filtrosColuna).forEach(col => {
            const selecionados = state.filtrosColuna[col];
            if (!selecionados || selecionados.length === 0) return;

            lista = lista.filter(p => {
                let val = '';
                if (col === 'cdPedido') val = String(p.cdPedido);
                else if (col === 'cliente') val = String(p.cliente || p.cliente_apelido || '');
                else if (col === 'dt_pedido') val = String(p.dt_pedido);
                else if (col === 'dt_entrega') val = String(p.dt_entrega);
                else if (col === 'status_pedido_erp') val = String(p.status_pedido_erp);
                else if (col === 'setor_motivo') val = String(p.setor_motivo);
                else if (col === 'tramite_sac') {
                    val = p.ultimo_followup ? `${p.ultimo_followup.tipo_cod} - ${p.ultimo_followup.tipo_desc}` : 'Sem SAC';
                }
                else if (col === 'produto') {
                    const refs = (p.produtos || []).map(pr => pr.cd_referencia);
                    return selecionados.some(s => refs.includes(s));
                }
                return selecionados.includes(val);
            });
        });

        // 3. Ordenação (Padrão: Mais antigo para o mais novo)
        if (state.ordenacao.coluna) {
            const col = state.ordenacao.coluna;
            const asc = state.ordenacao.asc ? 1 : -1;
            lista.sort((a, b) => {
                let vA = a[col] ?? '';
                let vB = b[col] ?? '';
                if (typeof vA === 'string') return vA.localeCompare(vB) * asc;
                return (vA - vB) * asc;
            });
        } else {
            lista.sort((a, b) => {
                let tA = a.dt_pedido_raw ? new Date(a.dt_pedido_raw).getTime() : 0;
                let tB = b.dt_pedido_raw ? new Date(b.dt_pedido_raw).getTime() : 0;
                if (tA !== tB) return tA - tB;
                return (Number(a.cdPedido) || 0) - (Number(b.cdPedido) || 0);
            });
        }

        // Badges no topo
        const kpiBadge = document.getElementById('setorKpisBadges');
        if (kpiBadge) {
            kpiBadge.innerHTML = `
                <span class="badge badge-neutral">Pedidos: ${lista.length}</span>
                <span class="badge badge-info">Transformadores: ${lista.reduce((acc, p) => acc + (p.qtd_saldo_itens || 0), 0)}</span>
            `;
        }

        const setor = state.setor;
        if (lista.length === 0) {
            tableBody.innerHTML = `<tr><td colspan="12" class="text-center text-muted" style="padding:40px;">Nenhum pedido localizado com os filtros selecionados.</td></tr>`;
            return;
        }

        let html = '';
        lista.forEach(p => {
            const cdPed = p.cdPedido;
            const prazoBadge = `<span class="badge ${badgeClassePrazo(p.status_prazo)}">${p.dt_entrega} (${p.dias_para_entrega !== null ? (p.dias_para_entrega < 0 ? `${Math.abs(p.dias_para_entrega)}d atraso` : `${p.dias_para_entrega}d`) : '—'})</span>`;
            const fu = p.ultimo_followup;
            const isExpanded = state.expandidos.has(cdPed);
            const toggleIcon = isExpanded ? '▾' : '▸';

            const expandBtn = `
                <button class="order-expand-btn ${isExpanded ? 'is-expanded' : ''}" onclick="window.toggleExpandirPedido(${cdPed}, event)" title="Ver composição dos produtos">
                    ${toggleIcon}
                </button>
            `;

            // Linha Principal
            if (setor === 'COMERCIAL') {
                html += `
                    <tr class="order-main-row" onclick="window.abrirModalPedido(${cdPed})">
                        <td style="text-align:center; padding:4px 6px;" onclick="event.stopPropagation()">${expandBtn}</td>
                        <td class="font-bold" style="color:var(--setor-comercial);">${cdPed}</td>
                        <td style="font-family:var(--font-mono);">${p.dt_pedido}</td>
                        <td>${prazoBadge}</td>
                        <td style="max-width:220px; overflow:hidden; text-overflow:ellipsis;" title="${p.cliente}"><strong>${p.cliente_apelido || p.cliente}</strong></td>
                        <td class="text-center font-bold">${Number(p.qtd_saldo_itens).toLocaleString('pt-BR')}</td>
                        <td class="text-center"><span class="badge badge-neutral">${p.status_pedido_erp}</span></td>
                        <td class="text-sm text-secondary">${p.setor_motivo}</td>
                    </tr>
                `;
            } else if (setor === 'ENGENHARIA') {
                html += `
                    <tr class="order-main-row" onclick="window.abrirModalPedido(${cdPed})">
                        <td style="text-align:center; padding:4px 6px;" onclick="event.stopPropagation()">${expandBtn}</td>
                        <td class="font-bold" style="color:var(--setor-engenharia);">${cdPed}</td>
                        <td style="max-width:200px; overflow:hidden; text-overflow:ellipsis;">${p.cliente_apelido || p.cliente}</td>
                        <td><span class="badge badge-info font-bold">${fu ? `${fu.tipo_cod} - ${fu.tipo_desc}` : 'Análise Técnica'}</span></td>
                        <td class="text-sm text-secondary">${fu ? `${fu.de || '—'} ➔ ${fu.para || '—'}` : '—'}</td>
                        <td style="font-family:var(--font-mono);">${fu ? fu.data_str : p.dt_pedido}</td>
                        <td>${prazoBadge}</td>
                        <td class="text-center font-bold">${Number(p.qtd_saldo_itens).toLocaleString('pt-BR')}</td>
                        <td class="text-sm text-secondary">${p.setor_motivo}</td>
                    </tr>
                `;
            } else if (setor === 'PCP') {
                const pctCls = p.programado_pcp_pct >= 100 ? 'badge-success' : 'badge-warning';
                html += `
                    <tr class="order-main-row" onclick="window.abrirModalPedido(${cdPed})">
                        <td style="text-align:center; padding:4px 6px;" onclick="event.stopPropagation()">${expandBtn}</td>
                        <td class="font-bold" style="color:var(--setor-pcp);">${cdPed}</td>
                        <td style="max-width:200px; overflow:hidden; text-overflow:ellipsis;">${p.cliente_apelido || p.cliente}</td>
                        <td class="text-center font-bold">${p.qtd_saldo_itens}</td>
                        <td class="text-center font-bold text-success">${p.qtd_programada_pcp}</td>
                        <td class="text-center"><span class="badge ${pctCls}">${p.programado_pcp_pct}%</span></td>
                        <td>${prazoBadge}</td>
                        <td><span class="badge ${p.programado_pcp_pct > 0 ? 'badge-success' : 'badge-warning'}">${p.setor_motivo}</span></td>
                        <td class="text-sm text-secondary">${fu ? `${fu.tipo_cod} - ${fu.tipo_desc}` : '—'}</td>
                    </tr>
                `;
            } else if (setor === 'LOGISTICA') {
                html += `
                    <tr class="order-main-row" onclick="window.abrirModalPedido(${cdPed})">
                        <td style="text-align:center; padding:4px 6px;" onclick="event.stopPropagation()">${expandBtn}</td>
                        <td class="font-bold" style="color:var(--setor-logistica);">${cdPed}</td>
                        <td style="max-width:200px; overflow:hidden; text-overflow:ellipsis;">${p.cliente_apelido || p.cliente}</td>
                        <td class="text-center font-bold">${p.qtd_saldo_itens}</td>
                        <td class="text-center font-bold text-success">${p.saldo_almox_total}</td>
                        <td><span class="badge badge-success">${p.setor_motivo}</span></td>
                        <td>${prazoBadge}</td>
                    </tr>
                `;
            }

            // Sub-linha Expansível (Accordion com os produtos detalhados)
            if (isExpanded && p.produtos && p.produtos.length > 0) {
                const subRows = p.produtos.map((pr, idx) => `
                    <tr>
                        <td style="font-family:var(--font-mono); font-weight:700; color:var(--color-accent); text-align:center;">${idx + 1}</td>
                        <td style="font-family:var(--font-mono); font-weight:700; color:var(--color-text-primary);">${pr.cd_referencia}</td>
                        <td style="font-weight:500;">${pr.ds_prod}</td>
                        <td style="text-align:right; font-family:var(--font-mono); font-weight:700;">${pr.potencia_kva} kVA</td>
                        <td style="text-align:center;"><span class="badge badge-neutral">${pr.classe_tensao || '15kV'}${pr.fases ? ` (${pr.fases})` : ''}</span></td>
                        <td style="font-family:var(--font-mono); font-size:12px; color:var(--color-text-secondary);">${pr.norma || '—'}</td>
                        <td style="text-align:center; font-weight:700;">${pr.qtd}</td>
                        <td style="text-align:center; font-weight:800; color:#16a34a;">${pr.saldo}</td>
                    </tr>
                `).join('');

                html += `
                    <tr class="order-subrow">
                        <td colspan="12">
                            <div class="sub-card-wrap">
                                <div class="sub-card-header">
                                    <div class="sub-card-title">
                                        <svg class="sub-card-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                                            <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
                                            <line x1="12" y1="22.08" x2="12" y2="12"/>
                                        </svg>
                                        <span>COMPOSIÇÃO DOS PRODUTOS DO PEDIDO ${cdPed}</span>
                                    </div>
                                    <span class="text-xs text-muted font-mono">${p.produtos.length} item(ns)</span>
                                </div>
                                <table class="sub-data-table">
                                    <thead>
                                        <tr>
                                            <th style="width:36px; text-align:center;">#</th>
                                            <th style="width:130px;">CÓDIGO / PROJETO</th>
                                            <th>DESCRIÇÃO DO TRANSFORMADOR</th>
                                            <th style="text-align:right; width:95px;">POTÊNCIA</th>
                                            <th style="text-align:center; width:120px;">CLASSE / FASES</th>
                                            <th style="width:150px;">NORMA (ESPECIFICAÇÃO)</th>
                                            <th style="text-align:center; width:70px;">QTD PEDIDA</th>
                                            <th style="text-align:center; width:70px;">SALDO RESTANTE</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${subRows}
                                    </tbody>
                                </table>
                            </div>
                        </td>
                    </tr>
                `;
            }
        });

        tableBody.innerHTML = html;
    }

    // Toggle para expandir/recolher accordion do pedido
    window.toggleExpandirPedido = function(cdPedido, event) {
        if (event) event.stopPropagation();
        if (state.expandidos.has(cdPedido)) {
            state.expandidos.delete(cdPedido);
        } else {
            state.expandidos.add(cdPedido);
        }
        renderizarSetor();
    };

    function badgeClassePrazo(status) {
        if (status === 'atrasado') return 'badge-danger';
        if (status === 'alerta') return 'badge-warning';
        return 'badge-success';
    }

    // ──────────────────────────────────────────────────────────────────────────
    // MODAL ENRIQUECIDO (PRODUTOS DETALHADOS + SAC TIMELINE)
    // ──────────────────────────────────────────────────────────────────────────
    window.abrirModalPedido = async function(cdPedido) {
        modalOverlay.classList.add('active');
        modalBodyContent.innerHTML = `<div class="text-center py-6 text-muted"><span class="loading-spinner"></span> <span style="margin-left:8px;">Carregando detalhes do pedido ${cdPedido}...</span></div>`;

        try {
            const res = await fetch(`${window.FLUXO_API_URL}?action=detalhe_pedido&cdPedido=${cdPedido}`);
            const data = await res.json();

            if (!data.sucesso || !data.pedido) {
                modalBodyContent.innerHTML = `<div class="text-danger p-4">Não foi possível carregar as informações do pedido ${cdPedido}.</div>`;
                return;
            }

            const p = data.pedido;
            const followups = data.followups || [];

            // Tabela de Produtos no Modal
            let prodsHtml = '';
            if (p.produtos && p.produtos.length > 0) {
                const prodsTr = p.produtos.map((pr, idx) => `
                    <tr>
                        <td style="font-family:var(--font-mono); font-weight:700; color:var(--color-accent); text-align:center;">${idx + 1}</td>
                        <td style="font-family:var(--font-mono); font-weight:700;">${pr.cd_referencia}</td>
                        <td>${pr.ds_prod}</td>
                        <td style="text-align:right; font-family:var(--font-mono);">${pr.potencia_kva} kVA</td>
                        <td style="text-align:center;"><span class="badge badge-neutral">${pr.classe_tensao || '15kV'}${pr.fases ? ` (${pr.fases})` : ''}</span></td>
                        <td style="font-family:var(--font-mono); font-size:12px; color:var(--color-text-secondary);">${pr.norma || '—'}</td>
                        <td style="text-align:center; font-weight:700;">${pr.qtd}</td>
                        <td style="text-align:center; font-weight:800; color:#16a34a;">${pr.saldo}</td>
                    </tr>
                `).join('');

                prodsHtml = `
                    <div class="modal-section-title">
                        <svg class="modal-section-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                            <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
                            <line x1="12" y1="22.08" x2="12" y2="12"/>
                        </svg>
                        <span>Transformadores do Pedido (${p.produtos.length} item${p.produtos.length > 1 ? 's' : ''})</span>
                    </div>
                    <table class="modal-prods-table">
                        <thead>
                            <tr>
                                <th style="width:36px; text-align:center;">#</th>
                                <th style="width:120px;">PROJETO</th>
                                <th>DESCRIÇÃO</th>
                                <th style="text-align:right; width:90px;">POTÊNCIA</th>
                                <th style="text-align:center; width:110px;">CLASSE</th>
                                <th style="width:130px;">NORMA</th>
                                <th style="text-align:center; width:60px;">QTD</th>
                                <th style="text-align:center; width:60px;">SALDO</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${prodsTr}
                        </tbody>
                    </table>
                `;
            }

            // Linha do tempo de Follow-ups do SAC
            let timelineHtml = '<div class="text-muted text-sm" style="padding:10px 0;">Nenhum evento de SAC/Follow-Up registrado para este pedido.</div>';
            if (followups.length > 0) {
                timelineHtml = `
                    <div class="timeline-list">
                        ${followups.map(fu => `
                            <div class="timeline-card">
                                <div class="timeline-bullet"></div>
                                <div class="d-flex align-center justify-between">
                                    <span class="timeline-title-txt">Tipo ${fu.tipo_cod}: ${fu.tipo_desc}</span>
                                    <span class="badge badge-neutral text-xs">${fu.data_str}</span>
                                </div>
                                <div class="timeline-sub-txt">
                                    <strong>De:</strong> ${fu.de || '—'} ➔ <strong>Para:</strong> ${fu.para || '—'} 
                                    ${fu.situacao ? `| <strong>Situação:</strong> ${fu.situacao}` : ''}
                                </div>
                                ${fu.observacoes ? `<div class="timeline-obs">${fu.observacoes}</div>` : ''}
                                ${fu.solucao ? `<div class="timeline-obs" style="border-left:3px solid #16a34a; margin-top:4px;"><strong>Solução:</strong> ${fu.solucao}</div>` : ''}
                            </div>
                        `).join('')}
                    </div>
                `;
            }

            modalBodyContent.innerHTML = `
                <div class="d-flex justify-between align-center" style="border-bottom:1px solid var(--color-border); padding-bottom:12px; margin-bottom:12px;">
                    <div>
                        <div class="text-xl font-bold" style="color:var(--color-text-primary);">Pedido ${p.cdPedido}</div>
                        <div class="text-sm text-secondary">${p.cliente_apelido || p.cliente} ${p.pedido_cliente ? `(Ped. Cliente: ${p.pedido_cliente})` : ''}</div>
                    </div>
                    <div class="text-right">
                        <span class="badge ${badgeClassePrazo(p.status_prazo)}">${p.dt_entrega}</span>
                        <div class="text-xs text-muted" style="margin-top:4px;">Emissão: ${p.dt_pedido}</div>
                    </div>
                </div>

                <div class="d-grid" style="grid-template-columns:repeat(auto-fit, minmax(130px, 1fr)); gap:8px; margin-bottom:12px;">
                    <div class="p-2 border rounded bg-surface">
                        <div class="text-xs text-muted">Setor Atual</div>
                        <div class="font-bold text-sm" style="color:var(--setor-${p.setor_atual.toLowerCase()});">${p.setor_atual}</div>
                    </div>
                    <div class="p-2 border rounded bg-surface">
                        <div class="text-xs text-muted">Status ERP</div>
                        <div class="font-bold text-sm">${p.status_pedido_erp}</div>
                    </div>
                    <div class="p-2 border rounded bg-surface">
                        <div class="text-xs text-muted">Total Peças</div>
                        <div class="font-bold text-sm">${p.qtd_total_itens}</div>
                    </div>
                    <div class="p-2 border rounded bg-surface">
                        <div class="text-xs text-muted">kVA Total</div>
                        <div class="font-bold text-sm">${Number(p.kva_total).toLocaleString('pt-BR')} kVA</div>
                    </div>
                    <div class="p-2 border rounded bg-surface">
                        <div class="text-xs text-muted">Prog. PCP</div>
                        <div class="font-bold text-sm ${p.programado_pcp_pct >= 100 ? 'text-success' : 'text-warning'}">${p.programado_pcp_pct}%</div>
                    </div>
                </div>

                ${prodsHtml}

                <div class="modal-section-title">
                    <svg class="modal-section-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                    <span>Histórico & Trâmites do SAC (${followups.length} evento${followups.length > 1 ? 's' : ''})</span>
                </div>
                ${timelineHtml}
            `;
        } catch (err) {
            modalBodyContent.innerHTML = `<div class="text-danger p-4">Erro ao processar dados do pedido.</div>`;
        }
    };
});
