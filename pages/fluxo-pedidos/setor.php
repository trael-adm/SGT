<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/layout.php';

requireLogin();

$base = defined('APP_URL') ? APP_URL : '';

$setor = $_GET['setor'] ?? 'COMERCIAL';
$setorUpper = strtoupper(trim($setor));

$setoresConfig = [
    'COMERCIAL'  => ['titulo' => 'COMERCIAL',  'desc' => 'Carteira de Pedidos & Entrada Comercial',            'cor' => '#2563eb'],
    'ENGENHARIA' => ['titulo' => 'ENGENHARIA', 'desc' => 'Validações de Projeto & Análise Técnica (SAC)',      'cor' => '#9333ea'],
    'PCP'        => ['titulo' => 'PCP',        'desc' => 'Planejamento, Pré-Programação & Fila de Ordens',     'cor' => '#d97706'],
    'PRODUCAO'   => ['titulo' => 'PRODUÇÃO',   'desc' => 'Planilha Detalhada do Chão de Fábrica (10 Células)', 'cor' => '#16a34a'],
    'LOGISTICA'  => ['titulo' => 'LOGÍSTICA',  'desc' => 'Armazenamento & Expedição (Almoxarifados 10 e 11)',  'cor' => '#0891b2'],
];

$cfg = $setoresConfig[$setorUpper] ?? $setoresConfig['COMERCIAL'];

$pageTitle = 'Fluxo de Pedidos — Setor ' . $cfg['titulo'];
layoutHeader($pageTitle);
?>
<?php $fluxoCssVer = @filemtime(__DIR__ . '/../../assets/css/fluxo-pedidos.css') ?: (defined('APP_VERSION') ? APP_VERSION : '1'); ?>
<link rel="stylesheet" href="<?= htmlspecialchars($base) ?>/assets/css/fluxo-pedidos.css?v=<?= htmlspecialchars((string) $fluxoCssVer) ?>">
<style>
    /* Layout travado 100% viewport — mesmo padrão de pages/acompanhamento/index.php,
       escopado só a esta página (não afeta o resto do PCP). */
    .app-content {
        overflow: hidden !important;
        display: flex !important;
        flex-direction: column !important;
        height: calc(100vh - 60px) !important;
        max-height: calc(100vh - 60px) !important;
    }
</style>

<div class="fp-wrapper page-setor">

    <!-- Top Header & Navegação -->
    <header class="top-header">
        <div class="top-brand">
            <a href="<?= htmlspecialchars($base) ?>/pages/fluxo-pedidos/index.php" class="btn btn-secondary btn-sm">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                Voltar ao Circuito
            </a>
            <div class="brand-info">
                <h1 style="color: <?= htmlspecialchars($cfg['cor']) ?>;">Setor: <?= htmlspecialchars($cfg['titulo']) ?></h1>
                <p><?= htmlspecialchars($cfg['desc']) ?></p>
            </div>
        </div>

        <!-- Navegação Rápida entre Setores -->
        <nav class="setor-nav">
            <a href="setor.php?setor=COMERCIAL" class="setor-nav-btn <?= $setorUpper === 'COMERCIAL' ? 'active' : '' ?>">COM</a>
            <a href="setor.php?setor=ENGENHARIA" class="setor-nav-btn <?= $setorUpper === 'ENGENHARIA' ? 'active' : '' ?>">ENG</a>
            <a href="setor.php?setor=PCP" class="setor-nav-btn <?= $setorUpper === 'PCP' ? 'active' : '' ?>">PCP</a>
            <a href="setor.php?setor=PRODUCAO" class="setor-nav-btn <?= $setorUpper === 'PRODUCAO' ? 'active' : '' ?>">PRODUÇÃO</a>
            <a href="setor.php?setor=LOGISTICA" class="setor-nav-btn <?= $setorUpper === 'LOGISTICA' ? 'active' : '' ?>">LOG</a>
        </nav>
    </header>

    <!-- Caixa Principal do Setor -->
    <main class="sector-screen-box">

        <!-- Barra de Filtros de Período e Resumo (Produção e Outros) -->
        <div class="advanced-filter-bar" id="advancedFilterBar">
            <!-- Preenchido via JavaScript -->
        </div>

        <!-- Tabela com Popups de Filtro nos Cabeçalhos -->
        <div class="table-wrap">
            <table class="data-table">
                <thead id="tableHeader">
                    <!-- Cabeçalho específico gerado pelo JavaScript -->
                </thead>
                <tbody id="tableBody">
                    <tr>
                        <td colspan="21" class="text-center text-muted" style="padding: 40px;">
                            Carregando registros...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

    </main>

</div>

<!-- Modal de Linha do Tempo / Follow-Ups SAC -->
<div class="modal-overlay" id="modalOverlay">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title">Rastreabilidade & Histórico do Pedido</span>
            <button class="modal-close" id="modalCloseBtn">&times;</button>
        </div>
        <div class="modal-body" id="modalBodyContent">
            <!-- Preenchido via JavaScript -->
        </div>
    </div>
</div>

<script>window.FLUXO_API_URL = <?= json_encode($base . '/api/boletim-fluxo.php') ?>;</script>
<?php $fluxoSetorJsVer = @filemtime(__DIR__ . '/../../assets/js/fluxo-setor.js') ?: (defined('APP_VERSION') ? APP_VERSION : '1'); ?>
<script src="<?= htmlspecialchars($base) ?>/assets/js/fluxo-setor.js?v=<?= htmlspecialchars((string) $fluxoSetorJsVer) ?>"></script>

<?php layoutFooter(); ?>
