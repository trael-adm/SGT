<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/layout.php';

requireLogin();

$base = defined('APP_URL') ? APP_URL : '';

$pageTitle = 'Fluxo de Pedidos';
layoutHeader($pageTitle);
?>
<?php $fluxoCssVer = @filemtime(__DIR__ . '/../../assets/css/fluxo-pedidos.css') ?: (defined('APP_VERSION') ? APP_VERSION : '1'); ?>
<link rel="stylesheet" href="<?= htmlspecialchars($base) ?>/assets/css/fluxo-pedidos.css?v=<?= htmlspecialchars((string) $fluxoCssVer) ?>">

<div class="fp-wrapper">

    <!-- Top Control Bar -->
    <header class="top-header">
        <div class="top-brand">
            <div class="brand-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
            </div>
            <div class="brand-info">
                <h1>Fluxo do Pedido & Esteira Industrial</h1>
                <p>Circuito Executivo de Setores & Chão de Fábrica — Trael Transformadores</p>
            </div>
        </div>
        <div class="top-actions" style="display:flex;align-items:center;gap:10px;">
            <div id="chipAutoRefresh" onclick="window.alternarAutoRefreshFluxo()" style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-size:12px;color:var(--color-text-secondary,#94a3b8);background:var(--color-surface,#1a1e29);border:1px solid var(--color-border,#262c3d);border-radius:8px;padding:6px 12px;" title="Clique para pausar/retomar auto-refresh">
                <span class="pulse-dot" style="width:8px;height:8px;background:#22c55e;border-radius:50%;display:inline-block;"></span>
                <span>Auto-refresh: <strong id="labelTimerRefresh" style="color:var(--color-text-primary,#fff);font-family:monospace;">30s</strong></span>
                <span style="opacity:0.4;">|</span>
                <span>Atualizado: <strong id="labelLastUpdate" style="color:var(--color-text-primary,#fff);font-family:monospace;">--:--:--</strong></span>
            </div>
            <button class="btn btn-primary btn-sm" id="btnRefresh">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                <span id="btnRefreshLabel">Atualizar Dados</span>
            </button>
        </div>
    </header>

    <!-- Macro KPIs Executivos -->
    <section class="metrics-row">
        <div class="metric-card accent">
            <div class="metric-label">Carteira Ativa</div>
            <div class="metric-value" id="kpiCarteira">—</div>
            <div class="metric-sub" id="kpiCarteiraSub">Carregando pedidos...</div>
        </div>
        <div class="metric-card danger">
            <div class="metric-label">Risco & Alertas de Prazo</div>
            <div class="metric-value" id="kpiRisco">—</div>
            <div class="metric-sub" id="kpiRiscoSub">Carregando prazos...</div>
        </div>
    </section>

    <!-- Painel de Busca & Destaque de Setores -->
    <section class="search-glow-panel">
        <div class="search-input-wrap">
            <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" id="searchInput" placeholder="Digite o Pedido, Cliente, Projeto ou Nº de Série para localizar no mapa...">
        </div>
    </section>

    <!-- O MAPA DE FÁBRICA / CIRCUITO DOS SETORES COM DUTOS INDUSTRIAIS -->
    <main class="mapa-canvas-wrap">
        <div class="circuit-container">

            <div class="pipeline-track">

                <!-- 1. COMERCIAL -->
                <div class="station-node" data-setor="COMERCIAL" onclick="window.abrirTelaSetor('COMERCIAL')">
                    <div class="station-header">
                        <div class="station-code-wrap">
                            <span class="station-code">COM</span>
                            <span class="station-name">Comercial</span>
                        </div>
                        <div class="station-pill-count">
                            <span class="station-count" id="count_COMERCIAL">—</span>
                        </div>
                    </div>
                    <div class="station-details">
                        <div class="station-sub" id="status_COMERCIAL">—</div>
                    </div>
                    <button class="station-action-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg> Ver Setor
                    </button>
                </div>

                <!-- Duto Conector 1 -->
                <div class="pipe-segment-h"></div>

                <!-- 2. ENGENHARIA -->
                <div class="station-node" data-setor="ENGENHARIA" onclick="window.abrirTelaSetor('ENGENHARIA')">
                    <div class="station-header">
                        <div class="station-code-wrap">
                            <span class="station-code">ENG</span>
                            <span class="station-name">Engenharia</span>
                        </div>
                        <div class="station-pill-count">
                            <span class="station-count" id="count_ENGENHARIA">—</span>
                        </div>
                    </div>
                    <div class="station-details">
                        <div class="station-sub" id="status_ENGENHARIA">—</div>
                    </div>
                    <button class="station-action-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg> Ver Setor
                    </button>
                </div>

                <!-- Duto Conector 2 -->
                <div class="pipe-segment-h"></div>

                <!-- 3. PCP -->
                <div class="station-node" data-setor="PCP" onclick="window.abrirTelaSetor('PCP')">
                    <div class="station-header">
                        <div class="station-code-wrap">
                            <span class="station-code">PCP</span>
                            <span class="station-name">Planejamento</span>
                        </div>
                        <div class="station-pill-count">
                            <span class="station-count" id="count_PCP">—</span>
                        </div>
                    </div>
                    <div class="station-details">
                        <div class="station-sub" id="status_PCP">—</div>
                    </div>
                    <button class="station-action-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg> Ver Setor
                    </button>
                </div>

                <!-- Duto Conector 3 -->
                <div class="pipe-segment-h"></div>

                <!-- 4. PRODUÇÃO -->
                <div class="station-node" data-setor="PRODUCAO" onclick="window.abrirTelaSetor('PRODUCAO')">
                    <div class="station-header">
                        <div class="station-code-wrap">
                            <span class="station-code">PROD</span>
                            <span class="station-name">Produção</span>
                        </div>
                        <div class="station-pill-count">
                            <span class="station-count" id="count_PRODUCAO">—</span>
                        </div>
                    </div>
                    <div class="station-details">
                        <div class="station-sub" id="status_PRODUCAO">—</div>
                    </div>
                    <button class="station-action-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg> Ver Chão Fábrica
                    </button>
                </div>

                <!-- Duto Conector 4 -->
                <div class="pipe-segment-h"></div>

                <!-- 5. LOGÍSTICA -->
                <div class="station-node" data-setor="LOGISTICA" onclick="window.abrirTelaSetor('LOGISTICA')">
                    <div class="station-header">
                        <div class="station-code-wrap">
                            <span class="station-code">LOG</span>
                            <span class="station-name">Logística</span>
                        </div>
                        <div class="station-pill-count">
                            <span class="station-count" id="count_LOGISTICA">—</span>
                        </div>
                    </div>
                    <div class="station-details">
                        <div class="station-sub" id="status_LOGISTICA">—</div>
                    </div>
                    <button class="station-action-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg> Ver Almox 10/11
                    </button>
                </div>

            </div>

        </div>
    </main>

</div>

<script>window.FLUXO_API_URL = <?= json_encode($base . '/api/boletim-fluxo.php') ?>;</script>
<?php $fluxoMapaJsVer = @filemtime(__DIR__ . '/../../assets/js/fluxo-mapa.js') ?: (defined('APP_VERSION') ? APP_VERSION : '1'); ?>
<script src="<?= htmlspecialchars($base) ?>/assets/js/fluxo-mapa.js?v=<?= htmlspecialchars((string) $fluxoMapaJsVer) ?>"></script>

<?php layoutFooter(); ?>
