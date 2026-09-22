<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/boletim-painel-producao.php';

requireLogin();

$usuario = currentUser();
$base    = defined('APP_URL') ? APP_URL : '';

// ─── Parâmetros de Filtro ───────────────────────────────────────────────────
$tipoFiltro = trim((string) ($_GET['tipo'] ?? 'atraso'));
$linha      = trim((string) ($_GET['linha'] ?? 'TODOS'));
$dataCorte  = trim((string) ($_GET['data_corte'] ?? ''));

$dados  = boletimCalcularStatusPecas($tipoFiltro, $linha, $dataCorte ?: null);
$stats  = $dados['stats'];
$dataCorteVal = $dados['data_corte'] ?? date('Y-m-d');
$linhasPills = ['TODOS', 'EPO', 'MON', 'POT', 'TRI'];

$statusFiltros = [
    'atraso'       => ['label' => 'Em Atraso',       'cor' => '#dc2626'],
    'aberto'       => ['label' => 'Em Aberto (Total)', 'cor' => '#0284c7'],
    'adiantamento' => ['label' => 'No Prazo / Adiant.', 'cor' => '#16a34a'],
    'apontada'     => ['label' => 'Apontadas / Concl.', 'cor' => '#10b981'],
    'todas'        => ['label' => 'Todas as OFs',     'cor' => '#5a6480'],
];

$pageTitle = 'Status Peças — Dashboard de Produção';
layoutHeader($pageTitle);
?>

<style>
    .sgt-dash-header {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 20px;
    }
    .sgt-dash-title {
        font-size: var(--font-size-xl, 1.35rem);
        font-weight: 700;
        color: var(--color-text-primary, #1a2133);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .sgt-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 14px;
        margin-bottom: 20px;
    }
    @media (max-width: 700px) {
        .sgt-stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }
    .sgt-stat-help {
        display: block;
        font-size: 10px;
        color: var(--color-text-muted, #9aa3b8);
        margin-top: 3px;
    }
    .sgt-stat-card {
        background: var(--color-surface, #ffffff);
        border: 1px solid var(--color-border, #e2e6ed);
        border-radius: var(--radius-lg, 10px);
        padding: 14px 16px;
        text-align: center;
        box-shadow: var(--shadow-sm, 0 1px 3px rgba(26,39,68,0.08));
    }
    .sgt-stat-label {
        display: block;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--color-text-muted, #9aa3b8);
        margin-bottom: 4px;
    }
    .sgt-stat-value {
        font-size: 1.35rem;
        font-weight: 800;
        font-family: var(--font-mono, monospace);
        color: var(--color-text-primary, #1a2133);
        line-height: 1.2;
    }
    .sgt-filter-card {
        background: var(--color-surface, #ffffff);
        border: 1px solid var(--color-border, #e2e6ed);
        border-radius: var(--radius-lg, 10px);
        padding: 14px 20px;
        box-shadow: var(--shadow-sm, 0 1px 3px rgba(26,39,68,0.08));
        margin-bottom: 20px;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
    }
    .sgt-chart-card {
        background: var(--color-surface, #ffffff);
        border: 1px solid var(--color-border, #e2e6ed);
        border-radius: var(--radius-lg, 10px);
        padding: 20px;
        box-shadow: var(--shadow-sm, 0 1px 3px rgba(26,39,68,0.08));
        margin-bottom: 20px;
    }
    .sgt-card-header {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding-bottom: 12px;
        margin-bottom: 16px;
        border-bottom: 1px solid var(--color-border, #e2e6ed);
    }
    .sgt-pill-btn {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 5px 14px;
        border-radius: var(--radius-full, 9999px);
        font-size: 11px;
        font-weight: 600;
        cursor: pointer;
        border: 1px solid var(--color-border, #e2e6ed);
        background: var(--color-surface-2, #f8f9fb);
        color: var(--color-text-secondary, #5a6480);
        transition: all 0.15s ease;
        text-decoration: none;
    }
    .sgt-pill-btn:hover {
        border-color: var(--color-accent, #e8a020);
        color: var(--color-text-primary, #1a2133);
        background: var(--color-surface, #ffffff);
    }
    .sgt-pill-btn.active {
        background: var(--color-sidebar, #1a3d2a);
        color: #ffffff;
        border-color: var(--color-sidebar, #1a3d2a);
        box-shadow: 0 2px 4px rgba(26, 61, 42, 0.2);
    }
    .sgt-table-card {
        background: var(--color-surface, #ffffff);
        border: 1px solid var(--color-border, #e2e6ed);
        border-radius: var(--radius-lg, 10px);
        padding: 20px;
        box-shadow: var(--shadow-sm, 0 1px 3px rgba(26,39,68,0.08));
    }
    .sgt-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
    }
    .sgt-table th {
        background: var(--color-surface-2, #f8f9fb);
        color: var(--color-text-secondary, #5a6480);
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .4px;
        padding: 10px 12px;
        border-bottom: 1px solid var(--color-border, #e2e6ed);
    }
    .sgt-table td {
        padding: 8px 12px;
        border-bottom: 1px solid var(--color-border, #e2e6ed);
        color: var(--color-text-primary, #1a2133);
    }
    .sgt-table tr:hover td {
        background: #f8fafc;
    }
    .sgt-table tr:last-child td {
        border-bottom: none;
    }
    .badge-status-of {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 9999px;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0.3px;
        text-transform: uppercase;
        font-family: var(--font-sans, sans-serif);
    }
    .badge-status-atraso {
        background: #fee2e2;
        color: #b91c1c;
        border: 1px solid #fecdd3;
    }
    .badge-status-aberto {
        background: #e0f2fe;
        color: #0369a1;
        border: 1px solid #bae6fd;
    }
    .badge-status-concluido {
        background: #dcfce7;
        color: #15803d;
        border: 1px solid #bbf7d0;
    }
    .sgt-modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.55);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 1000;
    }
    .sgt-modal-overlay[hidden] {
        display: none;
    }
    .sgt-modal-box {
        background: var(--color-surface, #ffffff);
        border-radius: var(--radius-lg, 10px);
        width: min(1100px, 94vw);
        max-height: 86vh;
        display: flex;
        flex-direction: column;
        box-shadow: 0 20px 50px rgba(15, 23, 42, 0.35);
    }
    .sgt-modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 16px 20px;
        border-bottom: 1px solid var(--color-border, #e2e6ed);
    }
    .sgt-modal-title {
        font-size: 15px;
        font-weight: 800;
        color: var(--color-text-primary, #1a2133);
    }
    .sgt-modal-subtitle {
        font-size: 11px;
        color: var(--color-text-muted, #9aa3b8);
        margin-top: 2px;
    }
    .sgt-modal-close {
        background: none;
        border: none;
        font-size: 22px;
        line-height: 1;
        cursor: pointer;
        color: var(--color-text-muted, #9aa3b8);
        padding: 4px 8px;
    }
    .sgt-modal-close:hover {
        color: var(--color-text-primary, #1a2133);
    }
    .sgt-modal-body {
        overflow: auto;
        padding: 0 20px 20px 20px;
    }

    @media print {
        .sidebar, .navbar, .no-print, button, input,
        .app-sidebar, .app-header,
        .sgt-dash-header, .print-only-header-indicadores,
        .sgt-filter-card, .sgt-table-card, .sgt-stat-help {
            display: none !important;
        }
        body {
            background: #ffffff !important;
            color: #000000 !important;
        }
        .app-main, .main-content, .app-content {
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
        }
        .sgt-stats-grid {
            display: grid !important;
            grid-template-columns: repeat(2, 1fr) !important;
            gap: 14px !important;
            margin-bottom: 0 !important;
        }
        .sgt-stat-card {
            box-shadow: none !important;
            border: 1px solid #cbd5e1 !important;
            break-inside: avoid !important;
            page-break-inside: avoid !important;
        }
        .sgt-chart-card {
            box-shadow: none !important;
            border: 1px solid #cbd5e1 !important;
            break-inside: avoid !important;
            page-break-inside: avoid !important;
            margin-top: 16px !important;
        }
        canvas {
            max-width: 100% !important;
            height: auto !important;
        }
    }
</style>

<div style="max-width: 100%; margin: 0 auto; padding: 4px 0 24px 0;">

    <!-- Top Header do Dashboard -->
    <div class="sgt-dash-header">
        <div>
            <h1 class="sgt-dash-title">
                <span>Dashboard de Produção</span>
                <span class="badge" style="background:#fee2e2;color:#b91c1c;font-size:0.75rem;padding:3px 10px;border-radius:9999px;font-weight:700;">
                    STATUS PEÇAS &bull; OFs
                </span>
            </h1>
            <p style="font-size:var(--font-size-sm, 0.875rem);color:var(--color-text-secondary, #5a6480);margin-top:3px;">
                Relação Analítica das Ordens de Fabricação Apontadas e em Aberto por Célula / Setor Fabril
            </p>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <button type="button" class="btn btn-secondary btn-sm" onclick="imprimirIndicadoresStatusPecas()" title="Imprimir apenas os indicadores (KPIs) desta tela" style="display:inline-flex;align-items:center;gap:6px;font-weight:600;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                Imprimir Indicadores
            </button>
            <a href="/pages/painel-setor/index.php" class="btn btn-secondary btn-sm" style="display:inline-flex;align-items:center;gap:6px;font-weight:600;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 14l-4-4 4-4"/><path d="M5 10h11a4 4 0 1 1 0 8h-1"/></svg>
                Painel por Setor
            </a>
        </div>
    </div>

    <!-- 4 Cards de Métricas Reais do Snapshot -->
    <div class="sgt-stats-grid">
        <div class="sgt-stat-card">
            <span class="sgt-stat-label">Total de OFs (Snapshot)</span>
            <span class="sgt-stat-value"><?= number_format($stats['total_ofs'], 0, ',', '.') ?></span>
        </div>
        <div class="sgt-stat-card">
            <span class="sgt-stat-label">Peças em Aberto (Pendente)</span>
            <span class="sgt-stat-value" style="color:#0284c7;"><?= number_format($stats['pecas_aberto'], 0, ',', '.') ?></span>
        </div>
        <div class="sgt-stat-card">
            <span class="sgt-stat-label">Peças em Atraso</span>
            <span class="sgt-stat-value" style="color:var(--color-danger, #dc2626);"><?= number_format($stats['pecas_atraso'], 0, ',', '.') ?></span>
        </div>
        <div class="sgt-stat-card">
            <span class="sgt-stat-label">Peças Apontadas / Concluídas</span>
            <span class="sgt-stat-value" style="color:var(--color-success, #16a34a);"><?= number_format($stats['pecas_apontadas'], 0, ',', '.') ?></span>
            <span class="sgt-stat-help">Só fecha quando a OF-mãe encerra — fica em 0 aqui até então, mesmo com a fábrica pronta.</span>
        </div>
        <div class="sgt-stat-card">
            <span class="sgt-stat-label">Aguardando Material / Compra</span>
            <span class="sgt-stat-value" style="color:#d97706;"><?= number_format($dados['pecas_aguardando_material'], 0, ',', '.') ?></span>
            <span class="sgt-stat-help"><?= number_format($dados['ofs_aguardando_material'], 0, ',', '.') ?> OFs com todas as células de produção já concluídas — trava é de PCP/Compras, não de fábrica.</span>
        </div>
    </div>

    <!-- Filtros de Status, Linha e Data de Corte -->
    <div class="sgt-filter-card">
        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:18px;">
            <!-- Seletor de Data de Corte Minimalista -->
            <form method="GET" id="filtroDataForm" style="display:flex;align-items:center;gap:8px;padding-right:16px;border-right:1px solid var(--color-border, #e2e6ed);">
                <input type="hidden" name="tipo" value="<?= htmlspecialchars($tipoFiltro) ?>">
                <input type="hidden" name="linha" value="<?= htmlspecialchars($linha) ?>">
                
                <span style="font-size:11px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;display:flex;align-items:center;gap:5px;white-space:nowrap;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--color-sidebar, #1a3d2a)" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    Corte:
                </span>
                <input type="date" name="data_corte" value="<?= htmlspecialchars($dataCorteVal) ?>" 
                       style="height:32px;padding:0 8px;font-size:12px;font-weight:700;font-family:var(--font-mono, monospace);background:var(--color-surface-2, #f8fafc);border:1px solid var(--color-border, #e2e6ed);border-radius:var(--radius-md, 6px);color:var(--color-text-primary);outline:none;cursor:pointer;"
                       onchange="document.getElementById('filtroDataForm').submit();"
                       title="Filtrar atraso e status até esta data">
            </form>

            <!-- Alternador de Status -->
            <div>
                <span style="display:block;font-size:10px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;margin-bottom:4px;">Status da OF:</span>
                <div style="display:flex;flex-wrap:wrap;align-items:center;gap:5px;">
                    <?php foreach ($statusFiltros as $stKey => $stCfg): ?>
                        <a href="?tipo=<?= urlencode($stKey) ?>&linha=<?= urlencode($linha) ?>&data_corte=<?= urlencode($dataCorteVal) ?>" class="sgt-pill-btn <?= $tipoFiltro === $stKey ? 'active' : '' ?>">
                            <?= htmlspecialchars($stCfg['label']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Filtro de Linha -->
            <div>
                <span style="display:block;font-size:10px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;margin-bottom:4px;">Linha:</span>
                <div style="display:flex;align-items:center;gap:5px;">
                    <?php foreach ($linhasPills as $linCod): ?>
                        <a href="?tipo=<?= urlencode($tipoFiltro) ?>&linha=<?= urlencode($linCod) ?>&data_corte=<?= urlencode($dataCorteVal) ?>" class="sgt-pill-btn <?= $linha === $linCod ? 'active' : '' ?>">
                            <?= htmlspecialchars($linCod === 'TODOS' ? 'Todas' : $linCod) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div style="font-size:11px;color:var(--color-text-muted);font-weight:600;margin-left:auto;">
            Exibindo: <strong style="color:var(--color-text-primary);"><?= count($dados['pecas_tabela']) ?> OFs</strong> &bull; Snapshot: <strong style="color:var(--color-text-primary);"><?= date('d/m/Y', strtotime($dados['data_extracao'])) ?></strong>
        </div>
    </div>

    <!-- Gráfico: Distribuição por Setor -->
    <div class="sgt-chart-card">
        <div class="sgt-card-header">
            <div>
                <h3 style="font-size:14px;font-weight:700;color:var(--color-text-primary);margin:0;">Peças em Atraso por Célula de Produção</h3>
                <p class="no-print" style="font-size:11px;color:var(--color-text-muted);margin:2px 0 0 0;">
                    Peças em atraso em cada célula fabril (visão real multicelular independente) — exibe pendências reais de cada setor mesmo se outros setores anteriores estiverem abertos.
                    <?php if (!empty($dados['pecas_nao_classificadas'])): ?>
                        <strong style="color:#d97706;"><?= number_format($dados['pecas_nao_classificadas'], 0, ',', '.') ?> peças</strong> ainda não classificadas (snapshot anterior a esta sincronização).
                    <?php endif; ?>
                </p>
            </div>
            <div style="display:flex;align-items:center;gap:6px;font-size:11px;font-weight:700;">
                <span>Filtro Ativo:</span>
                <span style="display:inline-flex;align-items:center;gap:5px;color: <?= $dados['cor_grafico'] ?>;">
                    <span style="width:8px;height:8px;border-radius:50%;background: <?= $dados['cor_grafico'] ?>;display:inline-block;"></span>
                    <?= ucfirst($tipoFiltro) ?>
                </span>
            </div>
        </div>

        <div style="position:relative;width:100%;height:460px;">
            <canvas id="chartStatusSetores" style="cursor:pointer;"></canvas>
        </div>
        <p style="font-size:10px;color:var(--color-text-muted);margin:8px 0 0 0;text-align:right;">Clique numa barra para ver as OFs dessa célula</p>
    </div>

    <!-- Tabela Analítica: Status das OFs e Peças -->
    <div class="sgt-table-card">
        <div class="sgt-card-header">
            <div>
                <h3 style="font-size:14px;font-weight:700;color:var(--color-text-primary);margin:0;">Relação das OFs (Ordens de Fabricação)</h3>
                <p style="font-size:11px;color:var(--color-text-muted);margin:2px 0 0 0;">Lista real com apontamentos concluídos e saldo em aberto no chão de fábrica</p>
            </div>
            <div style="display:flex;align-items:center;gap:10px;">
                <!-- Botão Imprimir Relação das OFs -->
                <button type="button" class="btn btn-primary btn-sm" onclick="imprimirRelacaoOFsStatus()" title="Imprimir a relação de OFs exibida (respeita a busca ativa)" style="height:34px;font-weight:600;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    Imprimir
                </button>

                <!-- Campo de Busca Rápida -->
                <div style="position:relative;width:280px;">
                    <input type="text" id="filtroTabelaInput" onkeyup="filtrarTabelaStatus()" placeholder="Buscar por OP, cliente, pedido, potência..." style="width:100%;height:34px;font-size:12px;background:var(--color-surface-2);border:1px solid var(--color-border);border-radius:var(--radius-md);padding:0 10px 0 30px;outline:none;">
                    <svg style="position:absolute;left:9px;top:10px;color:var(--color-text-muted);" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                </div>
            </div>
        </div>

        <div style="overflow-x:auto;max-height:480px;overflow-y:auto;border:1px solid var(--color-border);border-radius:var(--radius-md);display:none;">
            <table class="sgt-table" id="tabelaStatusPecas">
                <thead>
                    <tr>
                        <th style="text-align:center;">SEQ</th>
                        <th style="text-align:left;">OP / PROJETO</th>
                        <th style="text-align:center;">Nº SÉRIE</th>
                        <th style="text-align:center;" title="Gargalo real via decomposição de sub-OFs — laranja = aguardando material/compra">CÉLULA / GARGALO</th>
                        <th style="text-align:center;">PEDIDO</th>
                        <th style="text-align:center;">POTÊNCIA</th>
                        <th style="text-align:center;">FASE</th>
                        <th style="text-align:center;">DATA DA OF</th>
                        <th style="text-align:center;">CLIENTE</th>
                        <th style="text-align:center;">QTD TOTAL</th>
                        <th style="text-align:center;">APONTADA</th>
                        <th style="text-align:center;">EM ABERTO</th>
                        <th style="text-align:center;">STATUS</th>
                        <th style="text-align:center;">DIAS ATRASO</th>
                    </tr>
                </thead>
                <tbody style="font-family:var(--font-mono, monospace);">
                    <?php if (empty($dados['pecas_tabela'])): ?>
                        <tr>
                            <td colspan="14" style="text-align:center;padding:24px;color:var(--color-text-muted);font-family:var(--font-sans);">
                                Nenhuma ordem de fabricação encontrada para os filtros selecionados.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($dados['pecas_tabela'] as $p): ?>
                            <?php
                                $statusClass = 'badge-status-aberto';
                                if ($p['status_of'] === 'Atraso') {
                                    $statusClass = 'badge-status-atraso';
                                } elseif (str_contains($p['status_of'], 'Apontada')) {
                                    $statusClass = 'badge-status-concluido';
                                }
                                $nsFmt = $p['nr_serie_formatado'] ?? '—';
                                $celulaSigla = $p['setor_atual_sigla'] ?? '—';
                                $celulaNome = $p['setor_atual_nome'] ?? 'Não classificado';
                                $celulaCorFundo = $p['tipo_bloqueio'] === 'MATERIAL' ? '#fef3c7' : '#e0e7ff';
                                $celulaCorTexto = $p['tipo_bloqueio'] === 'MATERIAL' ? '#92400e' : '#3730a3';
                                $diasAtraso = (int) ($p['dias_atraso'] ?? 0);
                            ?>
                            <tr>
                                <td style="text-align:center;color:var(--color-text-muted);font-size:11px;font-weight:600;"><?= $p['seq_plano'] ?: '—' ?></td>
                                <td style="text-align:left;font-weight:700;color:var(--color-text-primary);"><?= htmlspecialchars((string)$p['op']) ?></td>
                                <td style="text-align:center;">
                                    <?php if ($nsFmt !== '—'): ?>
                                        <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:6px;background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;font-weight:700;font-size:11px;" title="Número de série rastreado">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg>
                                            <?= htmlspecialchars($nsFmt) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:var(--color-text-muted);font-size:11px;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center;">
                                    <span class="badge" style="background:<?= $celulaCorFundo ?>;color:<?= $celulaCorTexto ?>;font-size:10px;font-weight:800;padding:2px 8px;border-radius:9999px;" title="<?= htmlspecialchars($celulaNome) ?>">
                                        <?= htmlspecialchars($celulaSigla) ?>
                                    </span>
                                </td>
                                <td style="text-align:center;color:#0284c7;font-weight:700;"><?= $p['pedido'] ?: '—' ?></td>
                                <td style="text-align:center;color:var(--color-text-primary);font-weight:600;"><?= $p['potencia_fmt'] ?></td>
                                <td style="text-align:center;color:var(--color-sidebar, #1a3d2a);font-weight:700;"><?= htmlspecialchars((string)$p['fase']) ?></td>
                                <td style="text-align:center;color:var(--color-text-muted);"><?= $p['data_mf_fmt'] ?></td>
                                <td style="text-align:center;font-family:var(--font-sans);font-weight:600;color:var(--color-text-secondary);max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars((string)$p['cliente']) ?>">
                                    <?= htmlspecialchars((string)$p['cliente']) ?>
                                </td>
                                <td style="text-align:center;font-weight:700;color:var(--color-text-primary);"><?= $p['qtd_total'] ?> un</td>
                                <td style="text-align:center;font-weight:700;color:var(--color-success, #16a34a);"><?= $p['qtd_apontada'] ?> un</td>
                                <td style="text-align:center;font-weight:700;color: <?= (int)$p['qtd_aberto'] > 0 ? '#dc2626' : 'var(--color-text-muted)' ?>;"><?= $p['qtd_aberto'] ?> un</td>
                                <td style="text-align:center;">
                                    <span class="badge-status-of <?= $statusClass ?>">
                                        <?= htmlspecialchars($p['status_of']) ?>
                                    </span>
                                </td>
                                <td style="text-align:center;font-weight:800;color: <?= $diasAtraso > 0 ? 'var(--color-danger, #dc2626)' : 'var(--color-text-muted)' ?>;">
                                    <?= $diasAtraso > 0 ? $diasAtraso . ' dias' : '—' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal: Relação de OFs por Célula (aberto ao clicar numa barra do gráfico) -->
<div class="sgt-modal-overlay" id="modalCelulaOverlay" hidden>
    <div class="sgt-modal-box">
        <div class="sgt-modal-header">
            <div>
                <div class="sgt-modal-title" id="modalCelulaTitulo">—</div>
                <div class="sgt-modal-subtitle" id="modalCelulaSubtitulo">—</div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                <button type="button" class="btn btn-secondary btn-sm" onclick="imprimirRelacaoModalCelula()" title="Imprimir a relação desta célula (respeita a busca ativa)" style="display:inline-flex;align-items:center;gap:6px;font-weight:600;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    Imprimir
                </button>
                <button type="button" class="sgt-modal-close" id="modalCelulaFechar" aria-label="Fechar">&times;</button>
            </div>
        </div>
        <div style="padding: 10px 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; border-bottom: 1px solid var(--color-border, #e2e6ed); background: var(--color-surface-2, #f8f9fb);">
            <div style="position: relative; width: 100%; max-width: 400px;">
                <input type="text" id="filtroModalCelulaInput" onkeyup="filtrarTabelaModalCelula()" placeholder="Buscar por OP, cliente, pedido, potência, série..." style="width: 100%; height: 32px; font-size: 12px; background: #ffffff; border: 1px solid var(--color-border, #e2e6ed); border-radius: var(--radius-md, 6px); padding: 0 10px 0 32px; outline: none;">
                <svg style="position: absolute; left: 10px; top: 9px; color: var(--color-text-muted);" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            </div>
            <span id="modalCelulaContador" style="font-size: 11px; font-weight: 700; color: var(--color-text-muted); white-space: nowrap;"></span>
        </div>
        <div class="sgt-modal-body">
            <table class="sgt-table" id="tabelaModalCelula">
                <thead>
                    <tr>
                        <th style="text-align:center;">SEQ</th>
                        <th style="text-align:left;">OP / PROJETO</th>
                        <th style="text-align:center;">Nº SÉRIE</th>
                        <th style="text-align:center;">PEDIDO</th>
                        <th style="text-align:center;">POTÊNCIA</th>
                        <th style="text-align:center;">FASE</th>
                        <th style="text-align:center;">DATA DA OF</th>
                        <th style="text-align:center;">CLIENTE</th>
                        <th style="text-align:center;">QTD TOTAL</th>
                        <th style="text-align:center;">EM ABERTO</th>
                        <th style="text-align:center;">STATUS</th>
                        <th style="text-align:center;">DIAS ATRASO</th>
                    </tr>
                </thead>
                <tbody id="tabelaModalCelulaBody" style="font-family:var(--font-mono, monospace);"></tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
<script>
    function imprimirIndicadoresStatusPecas() {
        window.print();
    }

    Chart.register(ChartDataLabels);

    const labelsSetores = <?= json_encode($dados['ranking_labels']) ?>;
    const valoresSetores = <?= json_encode($dados['ranking_valores']) ?>;
    const corBarra = <?= json_encode($dados['cor_grafico']) ?>;

    const ctx = document.getElementById('chartStatusSetores').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labelsSetores,
            datasets: [{
                data: valoresSetores,
                backgroundColor: corBarra,
                borderRadius: 5,
                barPercentage: 0.85,
                categoryPercentage: 0.9
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            onClick: (evt, elements) => {
                if (!elements.length) return;
                abrirModalCelula(labelsSetores[elements[0].index]);
            },
            onHover: (evt, elements) => {
                evt.native.target.style.cursor = elements.length ? 'pointer' : 'default';
            },
            layout: { padding: { right: 70 } },
            scales: {
                x: {
                    beginAtZero: true,
                    grid: { color: 'rgba(0, 0, 0, 0.06)', borderDash: [3, 3] },
                    ticks: { color: '#5a6480', font: { size: 12.5, family: 'Inter, sans-serif' } },
                    border: { display: false }
                },
                y: {
                    grid: { display: false },
                    ticks: { color: '#1a2133', font: { size: 14, weight: '700', family: 'Inter, sans-serif' } },
                    border: { color: '#e2e6ed' }
                }
            },
            plugins: {
                legend: { display: false },
                datalabels: {
                    color: '#1a2133',
                    anchor: 'end',
                    align: 'end',
                    offset: 8,
                    clamp: true,
                    backgroundColor: 'rgba(255, 255, 255, 0.9)',
                    borderRadius: 4,
                    padding: { top: 3, bottom: 3, left: 6, right: 6 },
                    font: { size: 15, weight: '700', family: 'Inter, sans-serif' },
                    formatter: function(val) {
                        return val ? val.toLocaleString('pt-BR') + ' un' : '';
                    }
                },
                tooltip: {
                    backgroundColor: '#1a2133',
                    titleColor: '#ffffff',
                    borderColor: '#e2e6ed',
                    borderWidth: 1,
                    padding: 10,
                    callbacks: {
                        label: function(ctx) {
                            return ' ' + ctx.raw.toLocaleString('pt-BR') + ' un';
                        }
                    }
                }
            }
        }
    });

    // Sigla/nome digitado na busca -> nome exato da célula (mesmo rótulo do gráfico de barras).
    // A tabela principal só traz 1 célula/gargalo por OF; a relação completa "multicelular" de
    // cada célula só existe nessa API (mesma usada ao clicar na barra), então quando o termo
    // buscado bate com uma célula conhecida abrimos a mesma relação do clique no bloco.
    const SIGLA_PARA_CELULA = {
        'fun': 'Laser', 'laser': 'Laser',
        'bt': 'BT',
        'at': 'AT',
        'cnc': 'Corte de Núcleo', 'corte de nucleo': 'Corte de Núcleo', 'corte': 'Corte de Núcleo',
        'tp': 'Solda', 'sol': 'Solda', 'solda': 'Solda',
        'mn': 'Montagem de Núcleo', 'montagem de nucleo': 'Montagem de Núcleo',
        'tq': 'Pintura', 'pin': 'Pintura', 'pintura': 'Pintura',
        'me': 'Montagem Elétrica', 'montagem eletrica': 'Montagem Elétrica',
        'mf': 'Montagem Final', 'montagem final': 'Montagem Final',
        'ave': 'Averiguar', 'mat': 'Averiguar', 'averiguar': 'Averiguar', 'material': 'Averiguar',
    };

    function removerAcentos(str) {
        return str.normalize('NFD').replace(/[̀-ͯ]/g, '');
    }

    let ultimaCelulaAutoAberta = '';

    function filtrarTabelaStatus() {
        const input = document.getElementById('filtroTabelaInput');
        const filterRaw = input.value.toLowerCase().trim();
        const filter = removerAcentos(filterRaw);
        const table = document.getElementById('tabelaStatusPecas');
        const tr = table.getElementsByTagName('tr');

        for (let i = 1; i < tr.length; i++) {
            const rowText = removerAcentos(tr[i].textContent.toLowerCase());
            tr[i].style.display = rowText.includes(filter) ? '' : 'none';
        }

        const nomeCelula = SIGLA_PARA_CELULA[filter];
        if (nomeCelula && labelsSetores.includes(nomeCelula)) {
            if (ultimaCelulaAutoAberta !== nomeCelula) {
                ultimaCelulaAutoAberta = nomeCelula;
                abrirModalCelula(nomeCelula);
            }
        } else {
            ultimaCelulaAutoAberta = '';
        }
    }

    // ─── Imprimir Relação das OFs (respeita a busca ativa) ──────────────────
    function imprimirRelacaoOFsStatus() {
        const linhas = document.querySelectorAll('#tabelaStatusPecas tbody tr');
        const visiveis = Array.from(linhas).filter(tr => tr.style.display !== 'none');

        if (!visiveis.length) {
            alert('Não há ordens visíveis para impressão com a busca atual.');
            return;
        }

        const busca = (document.getElementById('filtroTabelaInput')?.value || '').trim();
        const dataHora = new Date().toLocaleString('pt-BR');
        const rowsHtml = visiveis.map(tr => '<tr>' + tr.innerHTML + '</tr>').join('');

        const printWin = window.open('', '_blank', 'width=1200,height=780');
        if (!printWin) {
            alert('Por favor, permita pop-ups no navegador para imprimir a relação de OFs.');
            return;
        }

        printWin.document.write(`<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Relação das OFs (Ordens de Fabricação) - SGT Trael</title>
    <style>
        @page { size: landscape; margin: 8mm 10mm; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            color: #1e293b; background: #fff; padding: 10px 14px; font-size: 11px;
            -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important;
        }
        .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #1e3a8a; padding-bottom: 8px; margin-bottom: 10px; }
        .header h1 { font-size: 16px; font-weight: 800; color: #1e3a8a; letter-spacing: -0.2px; }
        .header p { font-size: 11px; color: #475569; margin-top: 2px; }
        .meta { text-align: right; font-size: 10.5px; line-height: 1.45; color: #334155; }
        .badge-filtro { display: inline-block; background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; padding: 2px 8px; border-radius: 4px; font-weight: 700; font-size: 10px; }
        .btn-reprint { display: inline-block; margin-bottom: 8px; padding: 5px 12px; background: #1e40af; color: #fff; border: none; border-radius: 4px; font-size: 11px; font-weight: 700; cursor: pointer; }
        @media print { .no-print { display: none !important; } body { padding: 0; } }
        table { width: 100%; border-collapse: collapse; font-size: 8.5px; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th { background: #f1f5f9; color: #0f172a; font-weight: 700; font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.2px; border: 1px solid #cbd5e1; padding: 4px 5px; text-align: left; }
        td { border: 1px solid #e2e8f0; padding: 3px 5px; vertical-align: middle; }
        tr:nth-child(even) { background: #f8fafc; }
        .font-mono, .sgt-table td[style*="font-family"] { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
        .badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-weight: 700; font-size: 8.5px; text-align: center; }
        .footer { margin-top: 10px; padding-top: 6px; border-top: 1px solid #cbd5e1; display: flex; justify-content: space-between; font-size: 9px; color: #64748b; }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center;">
        <button type="button" class="btn-reprint" onclick="window.print()">🖨️ Imprimir Página / Salvar em PDF</button>
        <span style="font-size: 11px; color: #64748b;">Dica: você pode salvar como PDF ou selecionar sua impressora.</span>
    </div>

    <div class="header">
        <div>
            <h1>TRAEL TRANSFORMADORES — SGT</h1>
            <p>Relação das OFs (Ordens de Fabricação) &bull; Status de Peças</p>
        </div>
        <div class="meta">
            <div>${busca ? 'Busca: <span class="badge-filtro">&quot;' + busca + '&quot;</span>' : '<span class="badge-filtro">Sem filtro de busca</span>'}</div>
            <div>Emissão: <strong>${dataHora}</strong> &bull; Total: <strong>${visiveis.length} ordens</strong></div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:35px;text-align:center;">SEQ</th>
                <th>OP / Projeto</th>
                <th style="text-align:center;">Nº Série</th>
                <th style="text-align:center;">Célula / Gargalo</th>
                <th style="text-align:center;">Pedido</th>
                <th style="text-align:center;">Potência</th>
                <th style="text-align:center;">Fase</th>
                <th style="text-align:center;">Data da OF</th>
                <th>Cliente</th>
                <th style="text-align:center;">Qtd Total</th>
                <th style="text-align:center;">Apontada</th>
                <th style="text-align:center;">Em Aberto</th>
                <th style="text-align:center;">Status</th>
                <th style="text-align:center;">Dias Atraso</th>
            </tr>
        </thead>
        <tbody>
            ${rowsHtml}
        </tbody>
    </table>

    <div class="footer">
        <span>Sistema SGT &bull; Controle de Produção Trael Transformadores</span>
        <span>Documento emitido para controle interno da fábrica &bull; ${visiveis.length} registros</span>
    </div>

    <script>
        window.onload = function() {
            setTimeout(function() { window.print(); }, 300);
        };
    <\/script>
</body>
</html>`);
        printWin.document.close();
    }

    function escapeHtml(valor) {
        return String(valor ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }

    let todasOfsModalAtual = [];

    function renderizarTabelaModal(itens) {
        const corpo = document.getElementById('tabelaModalCelulaBody');
        if (!itens || itens.length === 0) {
            corpo.innerHTML = '<tr><td colspan="12" style="text-align:center;padding:32px;color:var(--color-text-muted);font-family:var(--font-sans);">Nenhuma OF encontrada para esta célula com os filtros ativos.</td></tr>';
            return;
        }

        corpo.innerHTML = itens.map((p) => {
            const statusClass = p.status_of === 'Atraso' ? 'badge-status-atraso' : (p.status_of.includes('Apontada') ? 'badge-status-concluido' : 'badge-status-aberto');
            return `
                <tr title="${p.setores_pendentes ? 'Setores com pendência: ' + escapeHtml(p.setores_pendentes) : ''}">
                    <td style="text-align:center;color:var(--color-text-muted);font-size:11px;font-weight:600;">${escapeHtml(p.seq)}</td>
                    <td style="text-align:left;font-weight:700;color:var(--color-text-primary);">${escapeHtml(p.op)}</td>
                    <td style="text-align:center;">
                        ${p.ns && p.ns !== '—' 
                            ? `<span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:6px;background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;font-weight:700;font-size:11px;" title="Número de série unitário">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg>
                                ${escapeHtml(p.ns)}
                               </span>`
                            : `<span style="color:var(--color-text-muted);font-size:11px;">—</span>`}
                    </td>
                    <td style="text-align:center;color:#0284c7;font-weight:700;">${escapeHtml(p.pedido)}</td>
                    <td style="text-align:center;font-weight:600;">${escapeHtml(p.potencia)}</td>
                    <td style="text-align:center;color:var(--color-sidebar, #1a3d2a);font-weight:700;">${escapeHtml(p.fase)}</td>
                    <td style="text-align:center;color:var(--color-text-muted);">${escapeHtml(p.data_mf)}</td>
                    <td style="text-align:center;font-family:var(--font-sans);font-weight:600;">${escapeHtml(p.cliente)}</td>
                    <td style="text-align:center;font-weight:700;">${escapeHtml(p.qtd_total)} un</td>
                    <td style="text-align:center;font-weight:700;color:${Number(p.qtd_aberto) > 0 ? '#dc2626' : 'var(--color-text-muted)'};">${escapeHtml(p.qtd_aberto)} un</td>
                    <td style="text-align:center;"><span class="badge-status-of ${statusClass}">${escapeHtml(p.status_of)}</span></td>
                    <td style="text-align:center;font-weight:800;color:${p.dias_atraso > 0 ? 'var(--color-danger, #dc2626)' : 'var(--color-text-muted)'};">${p.dias_atraso > 0 ? p.dias_atraso + ' dias' : '—'}</td>
                </tr>
            `;
        }).join('');
    }

    function filtrarTabelaModalCelula() {
        const termo = (document.getElementById('filtroModalCelulaInput').value || '').toLowerCase().trim();
        const filtradas = !termo ? todasOfsModalAtual : todasOfsModalAtual.filter(p => {
            return (p.op && p.op.toLowerCase().includes(termo))
                || (p.cliente && p.cliente.toLowerCase().includes(termo))
                || (p.pedido && String(p.pedido).toLowerCase().includes(termo))
                || (p.potencia && p.potencia.toLowerCase().includes(termo))
                || (p.ns && p.ns.toLowerCase().includes(termo));
        });
        renderizarTabelaModal(filtradas);
        const contadorEl = document.getElementById('modalCelulaContador');
        if (contadorEl) {
            contadorEl.textContent = termo ? `Exibindo ${filtradas.length} de ${todasOfsModalAtual.length} OFs` : '';
        }
    }

    function abrirModalCelula(nomeCelula) {
        document.getElementById('modalCelulaTitulo').textContent = nomeCelula;
        document.getElementById('modalCelulaSubtitulo').textContent = 'Carregando todas as ordens de fabricação...';

        const inputBusca = document.getElementById('filtroModalCelulaInput');
        if (inputBusca) inputBusca.value = '';
        const contadorEl = document.getElementById('modalCelulaContador');
        if (contadorEl) contadorEl.textContent = '';

        const corpo = document.getElementById('tabelaModalCelulaBody');
        corpo.innerHTML = `<tr><td colspan="12" style="text-align:center;padding:36px;color:var(--color-text-muted);font-family:var(--font-sans);"><div style="display:inline-flex;align-items:center;gap:8px;"><span style="display:inline-block;width:14px;height:14px;border:2px solid var(--color-sidebar,#1a3d2a);border-top-color:transparent;border-radius:50%;animation:spin 0.8s linear infinite;"></span> Carregando todas as OFs de <strong>${escapeHtml(nomeCelula)}</strong>...</div></td></tr>`;

        document.getElementById('modalCelulaOverlay').hidden = false;
        document.body.style.overflow = 'hidden';

        let apiUrl = '../../api/producao-status-pecas-celula.php';
        if (typeof window.__APP_BASE === 'string' && window.__APP_BASE.trim() !== '') {
            try {
                const urlObj = new URL(window.__APP_BASE, window.location.href);
                apiUrl = (urlObj.pathname.replace(/\/+$/, '') || '') + '/api/producao-status-pecas-celula.php';
            } catch (e) {
                apiUrl = window.__APP_BASE.replace(/\/+$/, '') + '/api/producao-status-pecas-celula.php';
            }
        }
        const params = new URLSearchParams({
            celula: nomeCelula,
            tipo: <?= json_encode($tipoFiltro) ?>,
            linha: <?= json_encode($linha) ?>,
            data_corte: <?= json_encode($dataCorteVal) ?>,
            data_extracao: <?= json_encode($dados['data_extracao']) ?>
        });

        fetch(`${apiUrl}?${params.toString()}`)
            .then(res => {
                if (!res.ok) throw new Error('Status HTTP ' + res.status);
                return res.json();
            })
            .then(data => {
                if (!data.sucesso) {
                    corpo.innerHTML = `<tr><td colspan="12" style="text-align:center;padding:24px;color:var(--color-danger);font-family:var(--font-sans);">${escapeHtml(data.erro || 'Erro ao carregar dados')}</td></tr>`;
                    document.getElementById('modalCelulaSubtitulo').textContent = 'Erro ao consultar';
                    return;
                }
                todasOfsModalAtual = data.ofs || [];
                document.getElementById('modalCelulaSubtitulo').textContent = `${data.total_ofs} OFs • ${data.total_aberto} peças em aberto`;
                renderizarTabelaModal(todasOfsModalAtual);
            })
            .catch(err => {
                corpo.innerHTML = `<tr><td colspan="12" style="text-align:center;padding:24px;color:var(--color-danger);font-family:var(--font-sans);">Erro de conexão ao carregar os dados desta célula.</td></tr>`;
                document.getElementById('modalCelulaSubtitulo').textContent = 'Falha de comunicação';
            });
    }

    // ─── Imprimir Relação da Célula (modal aberto ao clicar numa barra/buscar) ──
    function imprimirRelacaoModalCelula() {
        const linhas = document.querySelectorAll('#tabelaModalCelula tbody tr');
        const visiveis = Array.from(linhas).filter(tr => tr.style.display !== 'none');

        if (!visiveis.length) {
            alert('Não há ordens visíveis para impressão com a busca atual.');
            return;
        }

        const nomeCelula = document.getElementById('modalCelulaTitulo').textContent || 'Célula';
        const busca = (document.getElementById('filtroModalCelulaInput')?.value || '').trim();
        const dataHora = new Date().toLocaleString('pt-BR');
        const rowsHtml = visiveis.map(tr => '<tr>' + tr.innerHTML + '</tr>').join('');

        const printWin = window.open('', '_blank', 'width=1200,height=780');
        if (!printWin) {
            alert('Por favor, permita pop-ups no navegador para imprimir a relação de OFs.');
            return;
        }

        printWin.document.write(`<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Relação de OFs — ${escapeHtml(nomeCelula)} - SGT Trael</title>
    <style>
        @page { size: landscape; margin: 8mm 10mm; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            color: #1e293b; background: #fff; padding: 10px 14px; font-size: 11px;
            -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important;
        }
        .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #1e3a8a; padding-bottom: 8px; margin-bottom: 10px; }
        .header h1 { font-size: 16px; font-weight: 800; color: #1e3a8a; letter-spacing: -0.2px; }
        .header p { font-size: 11px; color: #475569; margin-top: 2px; }
        .meta { text-align: right; font-size: 10.5px; line-height: 1.45; color: #334155; }
        .badge-filtro { display: inline-block; background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; padding: 2px 8px; border-radius: 4px; font-weight: 700; font-size: 10px; }
        .btn-reprint { display: inline-block; margin-bottom: 8px; padding: 5px 12px; background: #1e40af; color: #fff; border: none; border-radius: 4px; font-size: 11px; font-weight: 700; cursor: pointer; }
        @media print { .no-print { display: none !important; } body { padding: 0; } }
        table { width: 100%; border-collapse: collapse; font-size: 8.5px; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th { background: #f1f5f9; color: #0f172a; font-weight: 700; font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.2px; border: 1px solid #cbd5e1; padding: 4px 5px; text-align: left; }
        td { border: 1px solid #e2e8f0; padding: 3px 5px; vertical-align: middle; }
        tr:nth-child(even) { background: #f8fafc; }
        .font-mono, .sgt-table td[style*="font-family"] { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
        .badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-weight: 700; font-size: 8.5px; text-align: center; }
        .footer { margin-top: 10px; padding-top: 6px; border-top: 1px solid #cbd5e1; display: flex; justify-content: space-between; font-size: 9px; color: #64748b; }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center;">
        <button type="button" class="btn-reprint" onclick="window.print()">🖨️ Imprimir Página / Salvar em PDF</button>
        <span style="font-size: 11px; color: #64748b;">Dica: você pode salvar como PDF ou selecionar sua impressora.</span>
    </div>

    <div class="header">
        <div>
            <h1>TRAEL TRANSFORMADORES — SGT</h1>
            <p>Relação das OFs (Ordens de Fabricação) &bull; Célula: ${escapeHtml(nomeCelula)}</p>
        </div>
        <div class="meta">
            <div>${busca ? 'Busca: <span class="badge-filtro">&quot;' + escapeHtml(busca) + '&quot;</span>' : '<span class="badge-filtro">Sem filtro de busca</span>'}</div>
            <div>Emissão: <strong>${dataHora}</strong> &bull; Total: <strong>${visiveis.length} ordens</strong></div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:35px;text-align:center;">SEQ</th>
                <th>OP / Projeto</th>
                <th style="text-align:center;">Nº Série</th>
                <th style="text-align:center;">Pedido</th>
                <th style="text-align:center;">Potência</th>
                <th style="text-align:center;">Fase</th>
                <th style="text-align:center;">Data da OF</th>
                <th>Cliente</th>
                <th style="text-align:center;">Qtd Total</th>
                <th style="text-align:center;">Em Aberto</th>
                <th style="text-align:center;">Status</th>
                <th style="text-align:center;">Dias Atraso</th>
            </tr>
        </thead>
        <tbody>
            ${rowsHtml}
        </tbody>
    </table>

    <div class="footer">
        <span>Sistema SGT &bull; Controle de Produção Trael Transformadores</span>
        <span>Documento emitido para controle interno da fábrica &bull; ${visiveis.length} registros</span>
    </div>

    <script>
        window.onload = function() {
            setTimeout(function() { window.print(); }, 300);
        };
    <\/script>
</body>
</html>`);
        printWin.document.close();
    }

    function fecharModalCelula() {
        document.getElementById('modalCelulaOverlay').hidden = true;
        document.body.style.overflow = '';
    }

    document.getElementById('modalCelulaFechar').addEventListener('click', fecharModalCelula);
    document.getElementById('modalCelulaOverlay').addEventListener('click', (evt) => {
        if (evt.target.id === 'modalCelulaOverlay') fecharModalCelula();
    });
    document.addEventListener('keydown', (evt) => {
        if (evt.key === 'Escape') fecharModalCelula();
    });
</script>

<?php layoutFooter(); ?>
