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
                <h3 style="font-size:14px;font-weight:700;color:var(--color-text-primary);margin:0;">Gargalo Real por Célula de Produção</h3>
                <p style="font-size:11px;color:var(--color-text-muted);margin:2px 0 0 0;">
                    Peças em atraso cuja célula de produção real (via decomposição de sub-OFs) ainda está pendente — ordem do fluxo fabril, não por volume.
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

        <div style="position:relative;width:100%;height:380px;">
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
            <!-- Campo de Busca Rápida -->
            <div style="position:relative;width:280px;">
                <input type="text" id="filtroTabelaInput" onkeyup="filtrarTabelaStatus()" placeholder="Buscar por OP, cliente, pedido, potência..." style="width:100%;height:34px;font-size:12px;background:var(--color-surface-2);border:1px solid var(--color-border);border-radius:var(--radius-md);padding:0 10px 0 30px;outline:none;">
                <svg style="position:absolute;left:9px;top:10px;color:var(--color-text-muted);" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            </div>
        </div>

        <div style="overflow-x:auto;max-height:480px;overflow-y:auto;border:1px solid var(--color-border);border-radius:var(--radius-md);">
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
                        <th style="text-align:center;">DATA MF</th>
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
            <button type="button" class="sgt-modal-close" id="modalCelulaFechar" aria-label="Fechar">&times;</button>
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
                        <th style="text-align:center;">DATA MF</th>
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
    Chart.register(ChartDataLabels);

    const labelsSetores = <?= json_encode($dados['ranking_labels']) ?>;
    const valoresSetores = <?= json_encode($dados['ranking_valores']) ?>;
    const corBarra = <?= json_encode($dados['cor_grafico']) ?>;
    const pecasPorCelula = <?= json_encode(array_map(function ($p) {
        return [
            'seq' => $p['seq_plano'] ?: '—',
            'op' => (string) $p['op'],
            'ns' => $p['nr_serie_formatado'] ?? '—',
            'celula' => $p['setor_atual_nome'] ?? 'Não classificado',
            'pedido' => $p['pedido'] ?: '—',
            'potencia' => $p['potencia_fmt'],
            'fase' => (string) $p['fase'],
            'data_mf' => $p['data_mf_fmt'],
            'cliente' => (string) $p['cliente'],
            'qtd_total' => $p['qtd_total'],
            'qtd_aberto' => $p['qtd_aberto'],
            'status_of' => $p['status_of'],
            'dias_atraso' => (int) ($p['dias_atraso'] ?? 0),
        ];
    }, $dados['pecas_tabela']), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS) ?>;

    const ctx = document.getElementById('chartStatusSetores').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labelsSetores,
            datasets: [{
                data: valoresSetores,
                backgroundColor: corBarra,
                borderRadius: 4,
                barPercentage: 0.75,
                categoryPercentage: 0.85
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
            layout: { padding: { right: 40 } },
            scales: {
                x: {
                    beginAtZero: true,
                    grid: { color: '#f1f5f9' },
                    ticks: { color: '#9aa3b8', font: { family: 'monospace' } }
                },
                y: {
                    grid: { display: false },
                    ticks: { color: '#1a2133', font: { size: 11, weight: '700' } }
                }
            },
            plugins: {
                legend: { display: false },
                datalabels: {
                    color: '#1a2133',
                    anchor: 'end',
                    align: 'end',
                    offset: 4,
                    font: { size: 11, weight: '700', family: 'monospace' },
                    formatter: function(val) {
                        return val ? val + ' un' : '';
                    }
                },
                tooltip: {
                    backgroundColor: '#1a2133',
                    titleColor: '#ffffff',
                    borderColor: '#e2e6ed',
                    borderWidth: 1,
                    padding: 10
                }
            }
        }
    });

    function filtrarTabelaStatus() {
        const input = document.getElementById('filtroTabelaInput');
        const filter = input.value.toLowerCase().trim();
        const table = document.getElementById('tabelaStatusPecas');
        const tr = table.getElementsByTagName('tr');

        for (let i = 1; i < tr.length; i++) {
            const rowText = tr[i].textContent.toLowerCase();
            tr[i].style.display = rowText.includes(filter) ? '' : 'none';
        }
    }

    function escapeHtml(valor) {
        return String(valor ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }

    function abrirModalCelula(nomeCelula) {
        const itens = pecasPorCelula.filter((p) => p.celula === nomeCelula);
        const corpo = document.getElementById('tabelaModalCelulaBody');

        if (itens.length === 0) {
            corpo.innerHTML = '<tr><td colspan="12" style="text-align:center;padding:24px;color:var(--color-text-muted);font-family:var(--font-sans);">Nenhuma OF encontrada para esta célula.</td></tr>';
        } else {
            corpo.innerHTML = itens.map((p) => `
                <tr>
                    <td style="text-align:center;color:var(--color-text-muted);font-size:11px;font-weight:600;">${escapeHtml(p.seq)}</td>
                    <td style="text-align:left;font-weight:700;color:var(--color-text-primary);">${escapeHtml(p.op)}</td>
                    <td style="text-align:center;">${escapeHtml(p.ns)}</td>
                    <td style="text-align:center;color:#0284c7;font-weight:700;">${escapeHtml(p.pedido)}</td>
                    <td style="text-align:center;font-weight:600;">${escapeHtml(p.potencia)}</td>
                    <td style="text-align:center;color:var(--color-sidebar, #1a3d2a);font-weight:700;">${escapeHtml(p.fase)}</td>
                    <td style="text-align:center;color:var(--color-text-muted);">${escapeHtml(p.data_mf)}</td>
                    <td style="text-align:center;font-family:var(--font-sans);font-weight:600;">${escapeHtml(p.cliente)}</td>
                    <td style="text-align:center;font-weight:700;">${escapeHtml(p.qtd_total)} un</td>
                    <td style="text-align:center;font-weight:700;color:${Number(p.qtd_aberto) > 0 ? '#dc2626' : 'var(--color-text-muted)'};">${escapeHtml(p.qtd_aberto)} un</td>
                    <td style="text-align:center;">${escapeHtml(p.status_of)}</td>
                    <td style="text-align:center;font-weight:800;color:${p.dias_atraso > 0 ? 'var(--color-danger, #dc2626)' : 'var(--color-text-muted)'};">${p.dias_atraso > 0 ? p.dias_atraso + ' dias' : '—'}</td>
                </tr>
            `).join('');
        }

        const totalAberto = itens.reduce((soma, p) => soma + Number(p.qtd_aberto || 0), 0);
        document.getElementById('modalCelulaTitulo').textContent = nomeCelula;
        document.getElementById('modalCelulaSubtitulo').textContent = `${itens.length} OFs • ${totalAberto} peças em aberto`;
        document.getElementById('modalCelulaOverlay').hidden = false;
    }

    function fecharModalCelula() {
        document.getElementById('modalCelulaOverlay').hidden = true;
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
