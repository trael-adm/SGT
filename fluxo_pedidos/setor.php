<?php
declare(strict_types=1);

$setor = $_GET['setor'] ?? 'COMERCIAL';
$setorUpper = strtoupper(trim($setor));

$setoresConfig = [
    'COMERCIAL'  => ['titulo' => 'COMERCIAL',  'desc' => 'Carteira de Pedidos & Entrada Comercial',             'cor' => '#2563eb'],
    'ENGENHARIA' => ['titulo' => 'ENGENHARIA', 'desc' => 'Validações de Projeto & Análise Técnica (SAC)',       'cor' => '#9333ea'],
    'PCP'        => ['titulo' => 'PCP',        'desc' => 'Planejamento, Pré-Programação & Fila de Ordens',      'cor' => '#d97706'],
    'PRODUCAO'   => ['titulo' => 'PRODUÇÃO',   'desc' => 'Planilha Detalhada do Chão de Fábrica (11 Células)',  'cor' => '#16a34a'],
    'LOGISTICA'  => ['titulo' => 'LOGÍSTICA',  'desc' => 'Armazenamento & Expedição (Almoxarifados 10 e 11)',   'cor' => '#0891b2'],
];

$cfg = $setoresConfig[$setorUpper] ?? $setoresConfig['COMERCIAL'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setor: <?= htmlspecialchars($cfg['titulo']) ?> | SGT</title>

    <!-- Fontes e design system oficiais do SGT (mesmos de includes/layout.php) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/main.css">
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Estilo específico deste módulo (circuito, planilha, popup de filtro) -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?= file_exists(__DIR__ . '/assets/css/style.css') ? filemtime(__DIR__ . '/assets/css/style.css') : time() ?>">
</head>
<body class="page-setor">

    <div class="fp-wrapper">

        <!-- Top Header & Navegação Limpa -->
        <header class="top-header">
            <div class="top-brand">
                <a href="index.php" class="btn btn-secondary btn-sm">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                    Voltar ao Circuito
                </a>
                <div class="brand-info">
                    <h1 style="color: <?= $cfg['cor'] ?>;">Setor: <?= htmlspecialchars($cfg['titulo']) ?></h1>
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

    <!-- Script Oficial do Setor -->
    <script src="assets/js/setor.js?v=<?= file_exists(__DIR__ . '/assets/js/setor.js') ? filemtime(__DIR__ . '/assets/js/setor.js') : time() ?>"></script>
</body>
</html>
