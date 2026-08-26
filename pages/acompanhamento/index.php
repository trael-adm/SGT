<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/boletim-acompanhamento.php';

requireLogin();

$base = defined('APP_URL') ? APP_URL : '';

$resultado    = carregarAcompanhamentoProducao();
$itens        = $resultado['itens'] ?? [];
$erroConexao  = ($resultado['sucesso'] === false) ? ($resultado['erro'] ?? 'Erro desconhecido ao consultar o ERP.') : null;

$contagem = [
    'DESCER PARA MONTAGEM FINAL' => 0,
    'PINTAR TANQUE'              => 0,
    'GUARDAR NA ESTUFA'          => 0,
    'VERIFICAR APONTAMENTO'      => 0,
];
foreach ($itens as $it) {
    if (isset($contagem[$it['acao']])) {
        $contagem[$it['acao']]++;
    }
}
$totalGeral = count($itens);

$pageTitle = 'Acompanhamento de Produção';
layoutHeader($pageTitle);
?>
<style>
    /* ─── Impeccable Design: Layout com Scroll Fluido ────────────────────── */
    .app-content {
        overflow-y: auto !important;
        display: flex !important;
        flex-direction: column !important;
        min-height: calc(100vh - 60px) !important;
        padding: 14px 20px !important;
        background: #f8fafc;
    }

    .ac-page-layout {
        display: flex;
        flex-direction: column;
        flex: 1;
        gap: 12px;
    }

    .ac-top-section {
        flex-shrink: 0;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    /* ─── Cabeçalho da Página ────────────────────────────────────────────── */
    .bo-header-row {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }

    .bo-title-group h1 {
        font-size: 1.15rem;
        font-weight: 800;
        color: #0f172a;
        letter-spacing: -0.02em;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .bo-title-badge {
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        background: #e2e8f0;
        color: #475569;
        padding: 2px 7px;
        border-radius: 9999px;
    }

    .bo-subtitle {
        font-size: 0.8rem;
        color: #64748b;
        margin-top: 1px;
        font-weight: 500;
    }

    .ac-action-btns {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .ac-btn {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 6px 12px;
        font-size: 0.78rem;
        font-weight: 600;
        border-radius: 6px;
        border: 1px solid #cbd5e1;
        background: #ffffff;
        color: #334155;
        cursor: pointer;
        transition: all 0.15s ease;
        box-shadow: 0 1px 2px rgba(0,0,0,0.03);
    }
    .ac-btn:hover { background: #f1f5f9; color: #0f172a; border-color: #94a3b8; }

    /* ─── Cards de KPI / Filtros Segmentados ─────────────────────────────── */
    .ac-kpis-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 10px;
    }
    @media (max-width: 960px) { .ac-kpis-grid { grid-template-columns: repeat(2, 1fr); } }

    .ac-kpi-card {
        position: relative;
        background: #ffffff;
        border: 1.5px solid #e2e8f0;
        border-radius: 10px;
        padding: 9px 12px;
        cursor: pointer;
        user-select: none;
        transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    }

    .ac-kpi-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 14px -3px rgba(0,0,0,0.06);
        border-color: #cbd5e1;
    }

    .ac-kpi-card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 4px;
    }

    .ac-kpi-pill {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .ac-kpi-dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
    }

    .ac-kpi-check {
        opacity: 0;
        transform: scale(0.6);
        transition: all 0.2s ease;
        font-size: 0.68rem;
        font-weight: 800;
        padding: 1px 6px;
        border-radius: 9999px;
    }

    .ac-kpi-body {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 8px;
    }

    .ac-kpi-number {
        font-size: 1.45rem;
        font-weight: 800;
        line-height: 1;
        font-family: var(--font-sans, system-ui);
    }

    .ac-kpi-desc {
        font-size: 0.72rem;
        color: #64748b;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        font-weight: 500;
    }

    /* Variações Temáticas dos Cards */
    .ac-kpi-card.kpi-descer .ac-kpi-pill { color: #15803d; }
    .ac-kpi-card.kpi-descer .ac-kpi-dot  { background: #16a34a; }
    .ac-kpi-card.kpi-descer .ac-kpi-number { color: #15803d; }
    .ac-kpi-card.kpi-descer.is-active { background: #f0fdf4; border-color: #16a34a; }
    .ac-kpi-card.kpi-descer.is-active .ac-kpi-check { opacity: 1; transform: scale(1); background: #16a34a; color: #fff; }

    .ac-kpi-card.kpi-pintar .ac-kpi-pill { color: #b45309; }
    .ac-kpi-card.kpi-pintar .ac-kpi-dot  { background: #d97706; }
    .ac-kpi-card.kpi-pintar .ac-kpi-number { color: #b45309; }
    .ac-kpi-card.kpi-pintar.is-active { background: #fffbeb; border-color: #d97706; }
    .ac-kpi-card.kpi-pintar.is-active .ac-kpi-check { opacity: 1; transform: scale(1); background: #d97706; color: #fff; }

    .ac-kpi-card.kpi-estufa .ac-kpi-pill { color: #0369a1; }
    .ac-kpi-card.kpi-estufa .ac-kpi-dot  { background: #0284c7; }
    .ac-kpi-card.kpi-estufa .ac-kpi-number { color: #0369a1; }
    .ac-kpi-card.kpi-estufa.is-active { background: #f0f9ff; border-color: #0284c7; }
    .ac-kpi-card.kpi-estufa.is-active .ac-kpi-check { opacity: 1; transform: scale(1); background: #0284c7; color: #fff; }

    .ac-kpi-card.kpi-verificar .ac-kpi-pill { color: #b91c1c; }
    .ac-kpi-card.kpi-verificar .ac-kpi-dot  { background: #dc2626; }
    .ac-kpi-card.kpi-verificar .ac-kpi-number { color: #b91c1c; }
    .ac-kpi-card.kpi-verificar.is-active { background: #fef2f2; border-color: #dc2626; }
    .ac-kpi-card.kpi-verificar.is-active .ac-kpi-check { opacity: 1; transform: scale(1); background: #dc2626; color: #fff; }

    /* ─── Barra de Filtro e Busca ────────────────────────────────────────── */
    .ac-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }

    .ac-search-box {
        position: relative;
        width: 330px;
        max-width: 100%;
    }

    .ac-search-box input {
        width: 100%;
        padding: 6px 30px 6px 32px;
        font-size: 0.8rem;
        background: #ffffff;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        outline: none;
    }
    .ac-search-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; }
    .ac-search-clear { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); color: #94a3b8; cursor: pointer; display: none; }

    .ac-status-bar { display: flex; align-items: center; gap: 8px; font-size: 0.78rem; }
    .ac-filter-pill { display: inline-flex; align-items: center; gap: 6px; padding: 3px 9px; background: #1e293b; color: #ffffff; font-size: 0.72rem; font-weight: 600; border-radius: 9999px; }
    .ac-filter-pill-close { cursor: pointer; opacity: 0.7; }
    .ac-count-badge { font-weight: 700; color: #475569; background: #e2e8f0; padding: 2px 8px; border-radius: 6px; }

    /* ─── Tabela Impeccable (Alta Densidade, Scrollbars Visíveis) ─────────── */
    .ac-table-card {
        display: flex !important;
        flex-direction: column !important;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        overflow: hidden;
    }

    .ac-table-scroll {
        overflow-y: auto !important;
        overflow-x: auto !important;
        max-height: calc(100vh - 270px) !important;
        min-height: 420px !important;
    }

    /* Estilização customizada da barra de rolagem bem visível */
    .ac-table-scroll::-webkit-scrollbar { width: 10px; height: 10px; }
    .ac-table-scroll::-webkit-scrollbar-track { background: #e2e8f0; border-radius: 5px; }
    .ac-table-scroll::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 5px; border: 2px solid #e2e8f0; }
    .ac-table-scroll::-webkit-scrollbar-thumb:hover { background: #64748b; }

    #ac-tabela {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }

    #ac-tabela thead th {
        position: sticky;
        top: 0;
        z-index: 10;
        background: #f1f5f9 !important;
        color: #475569;
        font-size: 0.72rem;
        font-weight: 800;
        text-transform: uppercase;
        padding: 8px 6px;
        text-align: center !important;
        border-bottom: 1.5px solid #cbd5e1;
    }

    #ac-tabela tbody td {
        text-align: center !important;
        padding: 6px 8px;
        border-bottom: 1px solid #f1f5f9;
        font-size: 0.78rem;
        color: #334155;
    }

    .action-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 2px 9px;
        font-size: 0.72rem;
        font-weight: 800;
        border-radius: 9999px;
        white-space: nowrap;
        letter-spacing: 0.02em;
    }
    .action-descer    { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
    .action-pintar    { background: #fef3c7; color: #b45309; border: 1px solid #fcd34d; }
    .action-estufa    { background: #e0f2fe; color: #0369a1; border: 1px solid #7dd3fc; }
    .action-verificar { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }

    /* Rodapé da Tabela */
    .ac-footer-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 8px 12px;
        background: #f8fafc;
        border-top: 1px solid #e2e8f0;
        font-size: 0.76rem;
    }

    .ac-per-page {
        display: flex;
        align-items: center;
        gap: 6px;
        color: #64748b;
    }

    .ac-per-page select {
        padding: 3px 6px;
        border: 1px solid #cbd5e1;
        border-radius: 4px;
        font-size: 0.76rem;
        background: #ffffff;
        color: #0f172a;
    }
</style>

<div class="ac-page-layout">
    <!-- SEÇÃO SUPERIOR: TÍTULO, AÇÕES, CARDS KPIS E BUSCA -->
    <div class="ac-top-section">
        <div class="bo-header-row">
            <div class="bo-title-group">
                <h1>
                    <span>Acompanhamento em Produção</span>
                    <span class="bo-title-badge">Empresa 1</span>
                </h1>
                <p class="bo-subtitle">
                    Fluxo de Montagem: Tanque (PIN) × Parte Ativa (ME) → Montagem Final (MF)
                </p>
            </div>
            <div class="ac-action-btns" style="display:flex;align-items:center;gap:8px;">
                <button type="button" class="ac-btn" id="btnImprimirRelatorio" title="Imprimir relatório completo formatado em folha A4 Paisagem">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                    Imprimir
                </button>
                <button type="button" class="ac-btn" id="btnExportarCsv" title="Exportar dados da tabela para planilha CSV / Excel">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Exportar CSV
                </button>
            </div>
        </div>

        <?php if ($erroConexao !== null): ?>
        <div class="alert alert-danger" style="margin:0;padding:6px 12px;font-size:0.8rem;">
            <span><?= htmlspecialchars($erroConexao) ?></span>
        </div>
        <?php endif; ?>

        <!-- CARDS DE KPIS COM SELEÇÃO TÁTIL E FEEDBACK VISUAL -->
        <div class="ac-kpis-grid">
            <!-- 1. Descer para Montagem Final -->
            <div class="ac-kpi-card kpi-descer" data-filtro="DESCER PARA MONTAGEM FINAL" onclick="window.filtrarPorCardAcao('DESCER PARA MONTAGEM FINAL')">
                <div class="ac-kpi-card-header">
                    <span class="ac-kpi-pill">
                        <span class="ac-kpi-dot"></span>
                        Descer Mont. Final
                    </span>
                    <span class="ac-kpi-check">✓</span>
                </div>
                <div class="ac-kpi-body">
                    <span class="ac-kpi-number"><?= $contagem['DESCER PARA MONTAGEM FINAL'] ?></span>
                    <span class="ac-kpi-desc">Tanque & Parte Ativa prontos</span>
                </div>
            </div>

            <!-- 2. Pintar Tanque -->
            <div class="ac-kpi-card kpi-pintar" data-filtro="PINTAR TANQUE" onclick="window.filtrarPorCardAcao('PINTAR TANQUE')">
                <div class="ac-kpi-card-header">
                    <span class="ac-kpi-pill">
                        <span class="ac-kpi-dot"></span>
                        Pintar Tanque
                    </span>
                    <span class="ac-kpi-check">✓</span>
                </div>
                <div class="ac-kpi-body">
                    <span class="ac-kpi-number"><?= $contagem['PINTAR TANQUE'] ?></span>
                    <span class="ac-kpi-desc">Parte Ativa pronta, falta PIN</span>
                </div>
            </div>

            <!-- 3. Guardar na Estufa -->
            <div class="ac-kpi-card kpi-estufa" data-filtro="GUARDAR NA ESTUFA" onclick="window.filtrarPorCardAcao('GUARDAR NA ESTUFA')">
                <div class="ac-kpi-card-header">
                    <span class="ac-kpi-pill">
                        <span class="ac-kpi-dot"></span>
                        Guardar na Estufa
                    </span>
                    <span class="ac-kpi-check">✓</span>
                </div>
                <div class="ac-kpi-body">
                    <span class="ac-kpi-number"><?= $contagem['GUARDAR NA ESTUFA'] ?></span>
                    <span class="ac-kpi-desc">Tanque pintado, falta ME</span>
                </div>
            </div>

            <!-- 4. Verificar Apontamento -->
            <div class="ac-kpi-card kpi-verificar" data-filtro="VERIFICAR APONTAMENTO" onclick="window.filtrarPorCardAcao('VERIFICAR APONTAMENTO')">
                <div class="ac-kpi-card-header">
                    <span class="ac-kpi-pill">
                        <span class="ac-kpi-dot"></span>
                        Verif. Apontamento
                    </span>
                    <span class="ac-kpi-check">✓</span>
                </div>
                <div class="ac-kpi-body">
                    <span class="ac-kpi-number"><?= $contagem['VERIFICAR APONTAMENTO'] ?></span>
                    <span class="ac-kpi-desc">Montagem Final sem etapa prévia</span>
                </div>
            </div>
        </div>

        <!-- BARRA DE FERRAMENTAS: BUSCA RÁPIDA E FILTRO ATIVO -->
        <div class="ac-toolbar">
            <div class="ac-search-box">
                <svg class="ac-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" id="ac-busca" placeholder="Buscar por pedido, projeto, cliente ou nº série..." autocomplete="off">
                <span class="ac-search-clear" id="ac-busca-limpar" title="Limpar busca">×</span>
            </div>

            <div class="ac-status-bar">
                <div id="containerFiltroAtivo" style="display:none;">
                    <span class="ac-filter-pill">
                        <span id="filtroAtivoTexto"></span>
                        <span class="ac-filter-pill-close" onclick="window.limparFiltroAcao()" title="Remover filtro">×</span>
                    </span>
                </div>
                <span class="ac-count-badge" id="ac-contador"><?= $totalGeral ?> itens</span>
            </div>
        </div>
    </div>

    <!-- TABELA COM SCROLL INTERNO E CABEÇALHO FIXO -->
    <div class="ac-table-card">
        <div class="ac-table-scroll">
            <table id="ac-tabela">
                <thead>
                    <tr>
                        <th data-sort="pedido">PEDIDO</th>
                        <th data-sort="data">DATA PCP</th>
                        <th data-sort="seq">SEQ</th>
                        <th data-sort="projeto">PROJETO</th>
                        <th data-sort="desc_projeto" style="text-align:left !important; padding-left:10px;">DESCRIÇÃO</th>
                        <th data-sort="cliente" style="text-align:left !important; padding-left:10px;">CLIENTE</th>
                        <th data-sort="nr_serie">Nº SÉRIE</th>
                        <th data-sort="pintura">PIN</th>
                        <th data-sort="montagem_eletrica">ME</th>
                        <th data-sort="montagem_final">MF</th>
                        <th data-sort="acao">AÇÃO REQUERIDA</th>
                    </tr>
                </thead>
                <tbody id="ac-tbody"></tbody>
            </table>
        </div>

        <div class="ac-footer-row">
            <div class="ac-per-page">
                <span>Exibir:</span>
                <select id="ac-por-pagina">
                    <option value="25">25 linhas</option>
                    <option value="50">50 linhas</option>
                    <option value="100">100 linhas</option>
                    <option value="500" selected>Todas as peças</option>
                </select>
            </div>
            <div class="pagination" id="ac-paginacao"></div>
        </div>
    </div>
</div>

<script>
    window.BOLETIM_ACOMPANHAMENTO_DATA = <?= json_encode($itens, JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php $acompanhamentoJsVer = @filemtime(__DIR__ . '/../../assets/js/acompanhamento.js') ?: (defined('APP_VERSION') ? APP_VERSION : '1'); ?>
<script src="<?= htmlspecialchars($base) ?>/assets/js/acompanhamento.js?v=<?= htmlspecialchars((string) $acompanhamentoJsVer) ?>"></script>

<?php layoutFooter(); ?>