<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/boletim-painel-producao.php';
require_once __DIR__ . '/../../includes/producao-tabs-nav.php';

requireLogin();

$usuario = currentUser();
$base    = defined('APP_URL') ? APP_URL : '';

// ─── Parâmetros de Filtro de Data (Hoje / Mês / Personalizável) ────────────
$modoData        = trim((string) ($_GET['modo_data'] ?? ''));
$mes             = trim((string) ($_GET['mes'] ?? ''));
$dataInicioParam = trim((string) ($_GET['data_inicio'] ?? ''));
$dataFimParam    = trim((string) ($_GET['data_fim'] ?? ''));
$linha           = trim((string) ($_GET['linha'] ?? 'TODOS'));
$empresa         = (int) ($_GET['empresa'] ?? 1);
if (!in_array($empresa, [1, 4], true)) $empresa = 1;

if ($dataInicioParam !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInicioParam)) $dataInicioParam = '';
if ($dataFimParam !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFimParam)) $dataFimParam = '';

if ($modoData === 'hoje') {
    $dataInicio = $dataFim = date('Y-m-d');
    $mes        = date('Y-m');
} elseif ($modoData === 'mes') {
    if (!preg_match('/^\d{4}-\d{2}$/', $mes)) $mes = date('Y-m');
    $dataInicio = $mes . '-01';
    $dataFim    = date('Y-m-t', strtotime($dataInicio));
} elseif ($modoData === 'personalizado' && $dataInicioParam !== '' && $dataFimParam !== '') {
    $dataInicio = $dataInicioParam;
    $dataFim    = $dataFimParam;
    if ($dataInicio > $dataFim) [$dataInicio, $dataFim] = [$dataFim, $dataInicio];
} else {
    // Primeira visita (sem filtro na URL): abre sempre no dia de hoje — o usuário troca pelo filtro.
    $modoData   = 'hoje';
    $dataInicio = $dataFim = date('Y-m-d');
    $mes        = date('Y-m');
}
if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
    $mes = date('Y-m', strtotime($dataInicio));
}

$labelFiltroData = ($dataInicio === $dataFim)
    ? date('d/m/Y', strtotime($dataInicio))
    : date('d/m/Y', strtotime($dataInicio)) . ' a ' . date('d/m/Y', strtotime($dataFim));

$dados = boletimCalcularResumoDiario($dataInicio, $dataFim, $linha, $empresa);
$linhasPills = ($empresa === 4)
    ? ['TPD' => 'TPD (Selado)', 'TPM' => 'TPM (Conservador)', 'TPS' => 'TPS (Seco)']
    : ['ENR' => 'Monofásico', 'EMP' => 'Convencional', 'JC' => 'JC-TRIF'];
$totalSetores = count($dados['tabela_esquerda']) + count($dados['tabela_direita']);
$qsBase = ['modo_data' => $modoData, 'mes' => $mes, 'data_inicio' => $dataInicio, 'data_fim' => $dataFim, 'empresa' => $empresa];

$pageTitle = 'Resumo Diário de Produção — Dashboard de Produção';
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
    .sgt-filter-card {
        background: var(--color-surface, #ffffff);
        border: 1px solid var(--color-border, #e2e6ed);
        border-radius: var(--radius-lg, 10px);
        padding: 14px 20px;
        box-shadow: var(--shadow-sm, 0 1px 3px rgba(26,39,68,0.08));
        margin-bottom: 20px;
        display: flex;
        flex-wrap: wrap;
        align-items: flex-end;
        justify-content: space-between;
        gap: 16px;
    }
    .sgt-pill-btn {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 5px 12px;
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
    .sgt-datefilter-trigger {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        height: 34px;
        padding: 0 12px;
        background: var(--color-surface-2, #f8f9fb);
        border: 1px solid var(--color-border, #e2e6ed);
        border-radius: var(--radius-md, 6px);
        cursor: pointer;
        font-family: inherit;
        transition: all 0.15s ease;
    }
    .sgt-datefilter-trigger:hover {
        border-color: var(--color-accent, #e8a020);
        background: var(--color-surface, #ffffff);
    }
    .sgt-datefilter-trigger .df-trigger-label {
        font-size: 11px;
        font-weight: 700;
        color: var(--color-text-muted, #9aa3b8);
        text-transform: uppercase;
    }
    .sgt-datefilter-trigger .df-trigger-value {
        font-size: 12px;
        font-weight: 700;
        font-family: var(--font-mono, monospace);
        color: var(--color-text-primary, #1a2133);
    }
    .sgt-grid-2 {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
        margin-bottom: 24px;
    }
    @media (max-width: 1000px) {
        .sgt-grid-2 {
            grid-template-columns: 1fr;
        }
    }
    .sgt-table-card {
        background: var(--color-surface, #ffffff);
        border: 1px solid var(--color-border, #e2e6ed);
        border-radius: var(--radius-lg, 10px);
        overflow: hidden;
        box-shadow: var(--shadow-sm, 0 1px 3px rgba(26,39,68,0.08));
    }
    .sgt-table-card-header {
        padding: 12px 18px;
        background: var(--color-surface-2, #f8f9fb);
        border-bottom: 1px solid var(--color-border, #e2e6ed);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .sgt-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
    }
    .sgt-table th {
        background: var(--color-surface, #ffffff);
        color: var(--color-text-secondary, #5a6480);
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .4px;
        padding: 11px 16px;
        border-bottom: 1px solid var(--color-border, #e2e6ed);
    }
    .sgt-table td {
        padding: 11px 16px;
        border-bottom: 1px solid var(--color-border, #e2e6ed);
        color: var(--color-text-primary, #1a2133);
    }
    .sgt-table tr:hover td {
        background: #f8fafc;
    }
    .sgt-table tr:last-child td {
        border-bottom: none;
    }
    .aderencia-badge {
        font-family: var(--font-mono, monospace);
        font-weight: 800;
        font-size: 13px;
    }
    .sgt-row-clicavel {
        cursor: pointer;
    }

    /* Modal de Relação de OFs */
    .sgt-modal-backdrop {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(15, 23, 42, 0.65);
        backdrop-filter: blur(4px);
        z-index: 9999;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 20px;
        opacity: 0;
        transition: opacity 0.2s ease;
    }
    .sgt-modal-backdrop.active {
        display: flex;
        opacity: 1;
    }
    .sgt-modal-dialog {
        background: var(--color-surface, #ffffff);
        border: 1px solid var(--color-border, #e2e6ed);
        border-radius: var(--radius-xl, 14px);
        width: 100%;
        max-width: 1100px;
        max-height: 90vh;
        display: flex;
        flex-direction: column;
        box-shadow: 0 20px 40px -10px rgba(15, 23, 42, 0.35);
        overflow: hidden;
        transform: scale(0.96);
        transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .sgt-modal-backdrop.active .sgt-modal-dialog {
        transform: scale(1);
    }
    .sgt-modal-header {
        padding: 16px 24px;
        border-bottom: 1px solid var(--color-border, #e2e6ed);
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: var(--color-surface-2, #f8f9fb);
    }
    .sgt-modal-body {
        padding: 20px 24px;
        overflow-y: auto;
        flex: 1;
    }
    .sgt-modal-tabs {
        display: flex;
        gap: 8px;
        border-bottom: 2px solid var(--color-border, #e2e6ed);
        margin-bottom: 16px;
    }
    .sgt-modal-tab-btn {
        padding: 8px 16px;
        font-size: 13px;
        font-weight: 700;
        color: var(--color-text-secondary, #5a6480);
        background: transparent;
        border: none;
        border-bottom: 2px solid transparent;
        margin-bottom: -2px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.15s ease;
    }
    .sgt-modal-tab-btn:hover {
        color: var(--color-text-primary, #1a2133);
    }
    .sgt-modal-tab-btn.active {
        color: #0284c7;
        border-bottom-color: #0284c7;
    }
    .sgt-modal-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
    }
    .sgt-modal-table th {
        background: var(--color-surface-2, #f8f9fb);
        color: var(--color-text-muted, #9aa3b8);
        text-transform: uppercase;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0.5px;
        padding: 10px 12px;
        text-align: left;
        border-bottom: 1px solid var(--color-border, #e2e6ed);
    }
    .sgt-modal-table td {
        padding: 10px 12px;
        border-bottom: 1px solid var(--color-border, #e2e6ed);
        color: var(--color-text-primary, #1a2133);
    }
    .sgt-modal-table tr:hover td {
        background: #f8fafc;
    }

    @media print {
        .sgt-no-print,
        .sgt-filter-card,
        #modalOfs,
        aside,
        nav,
        header,
        .sidebar {
            display: none !important;
        }
        .sgt-grid-2 {
            grid-template-columns: 1fr 1fr !important;
            gap: 12px !important;
        }
        .sgt-table-card {
            box-shadow: none !important;
            border: 1px solid #ccc !important;
            break-inside: avoid;
        }
        body {
            background: #fff !important;
        }
    }
</style>

<div style="max-width: 100%; margin: 0 auto; padding: 4px 0 24px 0;">

    <!-- Top Header do Dashboard -->
    <div class="sgt-dash-header">
        <div>
            <h1 class="sgt-dash-title">
                <span>Dashboard de Produção</span>
                <span class="badge" style="background:#e0f2fe;color:#0369a1;font-size:0.75rem;padding:3px 10px;border-radius:9999px;font-weight:700;">
                    RESUMO DIÁRIO
                </span>
            </h1>
            <p style="font-size:var(--font-size-sm, 0.875rem);color:var(--color-text-secondary, #5a6480);margin-top:3px;">
                Análise Executiva &bull; Programado vs Realizado de Todos os Setores
            </p>
        </div>
        <div style="display:flex;align-items:center;gap:10px;" class="sgt-no-print">
            <button type="button" onclick="window.print()" class="btn btn-primary btn-sm" style="display:inline-flex;align-items:center;gap:6px;font-weight:600;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>
                Imprimir Resumo
            </button>
            <a href="/pages/painel-setor/index.php" class="btn btn-secondary btn-sm" style="display:inline-flex;align-items:center;gap:6px;font-weight:600;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 14l-4-4 4-4"/><path d="M5 10h11a4 4 0 1 1 0 8h-1"/></svg>
                Painel por Setor
            </a>
        </div>
    </div>

    <!-- Filtros: Empresa, Período de Data e Linhas -->
    <div class="sgt-filter-card">
        <div style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:16px;">
            <!-- Filtro de Empresa (Fábrica) -->
            <div>
                <label style="display:block;font-size:11px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;margin-bottom:5px;">Empresa</label>
                <div style="display:flex;flex-wrap:wrap;gap:5px;">
                    <a href="?<?= http_build_query(array_merge($qsBase, ['empresa' => 1, 'linha' => 'TODOS'])) ?>" class="sgt-pill-btn <?= $empresa === 1 ? 'active' : '' ?>">
                        1 - Distribuição
                    </a>
                    <a href="?<?= http_build_query(array_merge($qsBase, ['empresa' => 4, 'linha' => 'TODOS'])) ?>" class="sgt-pill-btn <?= $empresa === 4 ? 'active' : '' ?>">
                        4 - Média Força
                    </a>
                </div>
            </div>

            <!-- Filtro Intervalo de Data (Modal Hoje / Mês / Personalizável) -->
            <div>
                <label style="display:block;font-size:11px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;margin-bottom:5px;">Período</label>
                <button type="button" class="sgt-datefilter-trigger" onclick="abrirFiltroDataModal()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--color-text-muted);"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span class="df-trigger-label">Filtro de Data</span>
                    <span class="df-trigger-value"><?= htmlspecialchars($labelFiltroData) ?></span>
                </button>
            </div>

            <!-- Filtro de Linha -->
            <div>
                <label style="display:block;font-size:11px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;margin-bottom:5px;">Linha</label>
                <div style="display:flex;flex-wrap:wrap;gap:5px;">
                    <a href="?<?= http_build_query(array_merge($qsBase, ['linha' => 'TODOS'])) ?>" class="sgt-pill-btn <?= $linha === 'TODOS' ? 'active' : '' ?>">
                        Todos
                    </a>
                    <?php foreach ($linhasPills as $linCod => $linLabel): ?>
                        <a href="?<?= http_build_query(array_merge($qsBase, ['linha' => $linCod])) ?>" class="sgt-pill-btn <?= $linha === $linCod ? 'active' : '' ?>">
                            <?= htmlspecialchars($linLabel) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div style="font-size:11px;color:var(--color-text-muted);font-weight:600;">
            <?php if ($empresa === 1): ?>
                Setores Fabris Monitorados: <strong style="color:var(--color-text-primary);"><?= $totalSetores ?> Células</strong>
            <?php else: ?>
                Fábrica: <strong style="color:var(--color-text-primary);">Média Força (Consolidado)</strong>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($empresa === 1): ?>
    <!-- Matriz de 2 Tabelas Lado a Lado (Distribuição) -->
    <div class="sgt-grid-2">

        <!-- Tabela 1: Bloco Esquerdo (Isolante, Corte, Montagem Núcleo, Bobinas) -->
        <div class="sgt-table-card">
            <div class="sgt-table-card-header">
                <span style="font-size:12px;font-weight:700;color:var(--color-text-primary);text-transform:uppercase;letter-spacing:0.3px;">Pré-Montagem & Núcleo</span>
                <span class="badge" style="background:#e0f2fe;border:1px solid #bae6fd;font-size:10px;color:#0369a1;font-weight:700;">Distribuição</span>
            </div>
            <table class="sgt-table">
                <thead>
                    <tr>
                        <th>SETOR</th>
                        <th style="text-align:center;">PROGRAMADO</th>
                        <th style="text-align:center;">REALIZADO</th>
                        <th style="text-align:right;">ADERÊNCIA</th>
                    </tr>
                </thead>
                <tbody style="font-family:var(--font-mono, monospace);">
                    <?php foreach ($dados['tabela_esquerda'] as $row): ?>
                        <?php $isOk = ($row['aderencia_val'] >= 100.0); ?>
                        <tr class="sgt-row-clicavel" onclick="abrirModalOfs('<?= htmlspecialchars($row['codigo'], ENT_QUOTES) ?>', '<?= htmlspecialchars($row['setor'], ENT_QUOTES) ?>')">
                            <td style="font-weight:700;color:var(--color-text-primary);font-family:var(--font-sans, sans-serif);"><?= htmlspecialchars($row['setor']) ?></td>
                            <td style="text-align:center;color:var(--color-text-secondary);"><?= $row['programado'] ?></td>
                            <td style="text-align:center;font-weight:700;color:var(--color-text-primary);"><?= $row['realizado'] ?></td>
                            <td style="text-align:right;" class="aderencia-badge">
                                <span style="color: <?= $isOk ? 'var(--color-success, #16a34a)' : ($row['aderencia_val'] >= 90 ? '#0284c7' : 'var(--color-danger, #dc2626)') ?>;">
                                    <?= $row['aderencia'] ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Tabela 2: Bloco Direito (Laser, Solda, Pintura, MPA, Montagem Final) -->
        <div class="sgt-table-card">
            <div class="sgt-table-card-header">
                <span style="font-size:12px;font-weight:700;color:var(--color-text-primary);text-transform:uppercase;letter-spacing:0.3px;">Acabamento, Solda & Final</span>
                <span class="badge" style="background:#e0f2fe;border:1px solid #bae6fd;font-size:10px;color:#0369a1;font-weight:700;">Distribuição</span>
            </div>
            <table class="sgt-table">
                <thead>
                    <tr>
                        <th>SETOR</th>
                        <th style="text-align:center;">PROGRAMADO</th>
                        <th style="text-align:center;">REALIZADO</th>
                        <th style="text-align:right;">ADERÊNCIA</th>
                    </tr>
                </thead>
                <tbody style="font-family:var(--font-mono, monospace);">
                    <?php foreach ($dados['tabela_direita'] as $row): ?>
                        <?php $isOk = ($row['aderencia_val'] >= 100.0); ?>
                        <tr class="sgt-row-clicavel" onclick="abrirModalOfs('<?= htmlspecialchars($row['codigo'], ENT_QUOTES) ?>', '<?= htmlspecialchars($row['setor'], ENT_QUOTES) ?>')">
                            <td style="font-weight:700;color:var(--color-text-primary);font-family:var(--font-sans, sans-serif);"><?= htmlspecialchars($row['setor']) ?></td>
                            <td style="text-align:center;color:var(--color-text-secondary);"><?= $row['programado'] ?></td>
                            <td style="text-align:center;font-weight:700;color:var(--color-text-primary);"><?= $row['realizado'] ?></td>
                            <td style="text-align:right;" class="aderencia-badge">
                                <span style="color: <?= $isOk ? 'var(--color-success, #16a34a)' : ($row['aderencia_val'] >= 90 ? '#0284c7' : 'var(--color-danger, #dc2626)') ?>;">
                                    <?= $row['aderencia'] ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>
    <?php else: ?>
    <!-- Tabela(s) Média Força — Óleo (TPD/TPM) e Seco (TPS), com status por célula do Fluxo de Pedidos -->
    <?php
        $ordemCelulasForca = ['CH', 'BT', 'AT', 'CNC', 'SOL', 'MN', 'PIN', 'ME', 'MF', 'LAB'];
        $nomesCelulasForca = [
            'CH' => 'Chaparia', 'BT' => 'Bobinagem BT', 'AT' => 'Bobinagem AT', 'CNC' => 'Corte Núcleo',
            'SOL' => 'Solda', 'MN' => 'Montagem Núcleo', 'PIN' => 'Pintura', 'ME' => 'Montagem Elétrica',
            'MF' => 'Montagem Final', 'LAB' => 'Laboratório',
        ];
        // Mini-tabela por célula, no mesmo estilo visual (SETOR/PROGRAMADO/REALIZADO/ADERÊNCIA) da
        // tabela de setores da Distribuição — aqui TOTAL/CONCLUÍDAS/ADERÊNCIA vêm do Fluxo de Pedidos.
        $renderCelulasTabelaForca = function (array $celulas) use ($ordemCelulasForca, $nomesCelulasForca): string {
            $linhasHtml = '';
            foreach ($ordemCelulasForca as $cel) {
                if (empty($celulas[$cel]) || (int) $celulas[$cel]['total'] === 0) continue;
                $ok = (int) $celulas[$cel]['ok'];
                $total = (int) $celulas[$cel]['total'];
                $pct = $total > 0 ? round(($ok / $total) * 100, 2) : 0.0;
                $cor = $pct >= 100.0 ? 'var(--color-success, #16a34a)' : ($pct >= 90 ? '#0284c7' : 'var(--color-danger, #dc2626)');
                $nome = ($nomesCelulasForca[$cel] ?? $cel) . ' (' . $cel . ')';
                $linhasHtml .= '<tr>'
                    . '<td style="padding:6px 10px;font-weight:700;color:var(--color-text-primary);font-family:var(--font-sans, sans-serif);">' . htmlspecialchars($nome) . '</td>'
                    . '<td style="padding:6px 10px;text-align:center;color:var(--color-text-secondary);">' . $total . '</td>'
                    . '<td style="padding:6px 10px;text-align:center;font-weight:700;color:var(--color-text-primary);">' . $ok . '</td>'
                    . '<td style="padding:6px 10px;text-align:right;font-weight:800;color:' . $cor . ';">' . number_format($pct, 2, ',', '.') . '%</td>'
                    . '</tr>';
            }
            if ($linhasHtml === '') {
                return '<p style="padding:6px 10px;margin:0;font-size:11px;color:var(--color-text-muted);">— sem correspondência no Fluxo de Pedidos</p>';
            }
            return '<table style="width:100%;border-collapse:collapse;font-size:11px;">'
                . '<thead><tr>'
                . '<th style="text-align:left;padding:4px 10px;font-size:9px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.3px;border-bottom:1px solid var(--color-border, #e2e6ed);">Célula</th>'
                . '<th style="text-align:center;padding:4px 10px;font-size:9px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.3px;border-bottom:1px solid var(--color-border, #e2e6ed);">Total</th>'
                . '<th style="text-align:center;padding:4px 10px;font-size:9px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.3px;border-bottom:1px solid var(--color-border, #e2e6ed);">Concluídas</th>'
                . '<th style="text-align:right;padding:4px 10px;font-size:9px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.3px;border-bottom:1px solid var(--color-border, #e2e6ed);">Aderência</th>'
                . '</tr></thead>'
                . '<tbody style="font-family:var(--font-mono, monospace);">' . $linhasHtml . '</tbody>'
                . '</table>';
        };

        $linhaRenderRow = function (array $row, bool $destaque = false) use ($renderCelulasTabelaForca): void {
            $isOk = ($row['aderencia_val'] >= 100.0);
            $onclick = $destaque
                ? "abrirModalOfs('', '" . htmlspecialchars($row['setor'], ENT_QUOTES) . "')"
                : "abrirModalOfs('', '" . htmlspecialchars($row['setor'], ENT_QUOTES) . "', '" . htmlspecialchars($row['codigo'] ?? '', ENT_QUOTES) . "')";
            $pesoSetor = $destaque ? '800' : '700';
            $pesoProg = $destaque ? '700' : '400';
            $pesoReal = $destaque ? '800' : '700';
            $bgLinha = $destaque ? 'background:var(--color-surface-2, #f8f9fb);' : '';
            ?>
            <tr class="sgt-row-clicavel" onclick="<?= $onclick ?>" style="<?= $bgLinha ?>">
                <td style="border-bottom:none;font-weight:<?= $pesoSetor ?>;color:var(--color-text-primary);font-family:var(--font-sans, sans-serif);"><?= htmlspecialchars($row['setor']) ?></td>
                <td style="border-bottom:none;text-align:center;font-weight:<?= $pesoProg ?>;color:var(--color-text-secondary);"><?= $row['programado'] ?></td>
                <td style="border-bottom:none;text-align:center;font-weight:<?= $pesoReal ?>;color:var(--color-text-primary);"><?= $row['realizado'] ?></td>
                <td style="border-bottom:none;text-align:right;" class="aderencia-badge">
                    <span style="color: <?= $isOk ? 'var(--color-success, #16a34a)' : ($row['aderencia_val'] >= 90 ? '#0284c7' : 'var(--color-danger, #dc2626)') ?>;">
                        <?= $row['aderencia'] ?>
                    </span>
                </td>
            </tr>
            <tr style="<?= $bgLinha ?>">
                <td colspan="4" style="padding:2px 16px 14px 16px;">
                    <div style="font-size:9px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.3px;margin-bottom:2px;">Células (Fluxo de Pedidos)</div>
                    <?= $renderCelulasTabelaForca($row['celulas'] ?? []) ?>
                </td>
            </tr>
            <?php
        };

        $totalRow = $dados['total_consolidado'];
        $linhasForca = $dados['tabela_forca'];
        $linhasOleo = array_values(array_filter($linhasForca, fn($r) => in_array($r['codigo'], ['TPD', 'TPM'], true)));
        $linhasSeco = array_values(array_filter($linhasForca, fn($r) => $r['codigo'] === 'TPS'));
    ?>
    <?php if (!empty($linhasOleo) && !empty($linhasSeco)): ?>
        <!-- Ambas as famílias presentes (filtro "Todos"): 2 blocos lado a lado, igual ao layout da Distribuição -->
        <div class="sgt-grid-2">
            <div class="sgt-table-card">
                <div class="sgt-table-card-header">
                    <span style="font-size:12px;font-weight:700;color:var(--color-text-primary);text-transform:uppercase;letter-spacing:0.3px;">Média Força — Óleo (TPD e TPM)</span>
                    <span class="badge" style="background:var(--color-surface);border:1px solid var(--color-border);font-size:10px;color:var(--color-text-muted);font-weight:700;">Bloco 1</span>
                </div>
                <table class="sgt-table">
                    <thead>
                        <tr>
                            <th>LINHA</th>
                            <th style="text-align:center;">PROGRAMADO</th>
                            <th style="text-align:center;">REALIZADO</th>
                            <th style="text-align:right;">ADERÊNCIA</th>
                        </tr>
                    </thead>
                    <tbody style="font-family:var(--font-mono, monospace);">
                        <?php foreach ($linhasOleo as $row) $linhaRenderRow($row); ?>
                    </tbody>
                </table>
            </div>
            <div class="sgt-table-card">
                <div class="sgt-table-card-header">
                    <span style="font-size:12px;font-weight:700;color:var(--color-text-primary);text-transform:uppercase;letter-spacing:0.3px;">Média Força — Seco (TPS)</span>
                    <span class="badge" style="background:var(--color-surface);border:1px solid var(--color-border);font-size:10px;color:var(--color-text-muted);font-weight:700;">Bloco 2</span>
                </div>
                <table class="sgt-table">
                    <thead>
                        <tr>
                            <th>LINHA</th>
                            <th style="text-align:center;">PROGRAMADO</th>
                            <th style="text-align:center;">REALIZADO</th>
                            <th style="text-align:right;">ADERÊNCIA</th>
                        </tr>
                    </thead>
                    <tbody style="font-family:var(--font-mono, monospace);">
                        <?php foreach ($linhasSeco as $row) $linhaRenderRow($row); ?>
                        <?php $linhaRenderRow($totalRow, true); ?>
                    </tbody>
                </table>
            </div>
        </div>
        <p style="padding:10px 4px;margin:0;font-size:11px;color:var(--color-text-muted);">
            Programado/Realizado por linha vêm do Plano Mestre + Kardex. As "Células" (CH/BT/AT/CNC/SOL/MN/PIN/ME/MF/LAB) vêm de uma fonte separada, o Fluxo de Pedidos — nem toda OF tem correspondência lá (regras mais restritas do ERP), então uma linha pode aparecer sem nenhuma célula. "Média Força — Consolidado" é o total das linhas somadas.
        </p>
    <?php else: ?>
        <!-- Filtro de linha específico ativo: só uma família tem linhas, um card só -->
        <?php $linhasUnicas = !empty($linhasOleo) ? $linhasOleo : $linhasSeco; ?>
        <?php $tituloUnico = !empty($linhasOleo) ? 'Média Força — Óleo (TPD e TPM)' : 'Média Força — Seco (TPS)'; ?>
        <div class="sgt-table-card" style="margin-bottom:24px;">
            <div class="sgt-table-card-header">
                <span style="font-size:12px;font-weight:700;color:var(--color-text-primary);text-transform:uppercase;letter-spacing:0.3px;"><?= htmlspecialchars($tituloUnico) ?></span>
                <span class="badge" style="background:var(--color-surface);border:1px solid var(--color-border);font-size:10px;color:var(--color-text-muted);font-weight:700;">TPD · TPM · TPS</span>
            </div>
            <table class="sgt-table">
                <thead>
                    <tr>
                        <th>LINHA</th>
                        <th style="text-align:center;">PROGRAMADO</th>
                        <th style="text-align:center;">REALIZADO</th>
                        <th style="text-align:right;">ADERÊNCIA</th>
                    </tr>
                </thead>
                <tbody style="font-family:var(--font-mono, monospace);">
                    <?php foreach ($linhasUnicas as $row) $linhaRenderRow($row); ?>
                    <?php $linhaRenderRow($totalRow, true); ?>
                </tbody>
            </table>
            <p style="padding:12px 18px;margin:0;font-size:11px;color:var(--color-text-muted);border-top:1px dashed var(--color-border);">
                As "Células" vêm do Fluxo de Pedidos (fonte separada do Plano Mestre) — nem toda OF tem correspondência lá.
            </p>
        </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- Modal Relação de OFs por Setor/Empresa -->
    <div id="modalOfs" class="sgt-modal-backdrop" onclick="fecharModalOfsSeBackdrop(event)">
        <div class="sgt-modal-dialog">
            <div class="sgt-modal-header">
                <div>
                    <h2 style="font-size:1.15rem;font-weight:800;color:var(--color-text-primary);margin:0;display:flex;align-items:center;gap:8px;">
                        <span>📋 Relação de OFs</span>
                        <span style="color:#94a3b8;font-weight:400;">—</span>
                        <span id="modalOfsSetorLabel" style="color:#0284c7;">—</span>
                    </h2>
                    <p id="modalOfsSubtitulo" style="font-size:11px;color:var(--color-text-secondary);margin:2px 0 0 0;">
                        Comparativo entre o Programado no Plano Mestre e o Realizado no Laboratório
                    </p>
                </div>
                <button type="button" onclick="fecharModalOfs()" style="border:none;background:transparent;cursor:pointer;font-size:24px;color:#94a3b8;line-height:1;padding:4px 8px;border-radius:6px;" title="Fechar (Esc)">
                    &times;
                </button>
            </div>

            <div class="sgt-modal-body">
                <!-- Mini KPIs Resumo -->
                <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:12px;margin-bottom:18px;">
                    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 14px;text-align:center;">
                        <span style="font-size:10px;font-weight:700;color:#1d4ed8;text-transform:uppercase;display:block;">Programado (Plano)</span>
                        <span id="modalOfsKpiProg" style="font-size:1.25rem;font-weight:800;font-family:monospace;color:#1e40af;">—</span>
                    </div>
                    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 14px;text-align:center;">
                        <span style="font-size:10px;font-weight:700;color:#15803d;text-transform:uppercase;display:block;">Produzido (Laboratório)</span>
                        <span id="modalOfsKpiReal" style="font-size:1.25rem;font-weight:800;font-family:monospace;color:#166534;">—</span>
                    </div>
                    <div id="modalOfsKpiDiffBox" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 14px;text-align:center;">
                        <span style="font-size:10px;font-weight:700;color:#475569;text-transform:uppercase;display:block;">Saldo / Superávit</span>
                        <span id="modalOfsKpiDiff" style="font-size:1.25rem;font-weight:800;font-family:monospace;color:#0f172a;">—</span>
                    </div>
                    <div style="background:#fefce8;border:1px solid #fef08a;border-radius:8px;padding:10px 14px;text-align:center;">
                        <span style="font-size:10px;font-weight:700;color:#a16207;text-transform:uppercase;display:block;">Aderência</span>
                        <span id="modalOfsKpiAdr" style="font-size:1.25rem;font-weight:800;font-family:monospace;color:#854d0e;">—</span>
                    </div>
                </div>

                <!-- Abas e Barra de Pesquisa -->
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:12px;">
                    <div class="sgt-modal-tabs" style="margin-bottom:0;border-bottom:none;">
                        <button type="button" id="modalOfsTabBtnProg" onclick="trocarAbaModalOfs('prog')" class="sgt-modal-tab-btn active">
                            <span>📋 OFs Programadas</span>
                            <span id="modalOfsBadgeCountProg" style="background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:9999px;font-size:11px;font-weight:700;">0</span>
                        </button>
                        <button type="button" id="modalOfsTabBtnReal" onclick="trocarAbaModalOfs('real')" class="sgt-modal-tab-btn">
                            <span>🏭 OFs Produzidas / Concluídas</span>
                            <span id="modalOfsBadgeCountReal" style="background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:9999px;font-size:11px;font-weight:700;">0</span>
                        </button>
                    </div>
                    <div style="min-width:260px;position:relative;">
                        <input type="text" id="modalOfsInputBusca" onkeyup="filtrarTabelaModalOfs()" placeholder="Buscar por OF, Série, Projeto, Pedido, Cliente..." style="width:100%;height:34px;font-size:12px;padding:0 10px 0 32px;border:1px solid var(--color-border);border-radius:6px;background:var(--color-surface-2);color:var(--color-text-primary);box-sizing:border-box;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" style="position:absolute;left:10px;top:10px;"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                    </div>
                </div>

                <!-- Conteúdo Aba 1: Programadas (Plano Mestre) -->
                <div id="modalOfsTabContentProg" style="display:block;">
                    <div style="max-height:380px;overflow-y:auto;overflow-x:auto;border:1px solid var(--color-border);border-radius:8px;">
                        <table class="sgt-modal-table" id="modalOfsTabelaProg">
                            <thead>
                                <tr>
                                    <th style="width:90px;">Data</th>
                                    <th>Projeto / Ref</th>
                                    <th>Descrição do Trafo</th>
                                    <th>Pedido</th>
                                    <th>Cliente</th>
                                    <th>Potência</th>
                                    <th>Núcleo</th>
                                    <th style="text-align:right;">Qtd Prog</th>
                                    <th style="text-align:right;">Qtd Prod</th>
                                    <th style="text-align:right;">Saldo</th>
                                    <th id="modalOfsThCelulas" style="display:none;min-width:200px;" title="Status por célula do Fluxo de Pedidos (CH/BT/AT/CNC/SOL/MN/PIN/ME/MF/LAB)">Células (Fluxo)</th>
                                </tr>
                            </thead>
                            <tbody id="modalOfsTbodyProg">
                                <tr><td colspan="10" style="text-align:center;padding:30px;color:#94a3b8;">Carregando OFs...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Conteúdo Aba 2: Produzidas (Kardex / Laboratório) -->
                <div id="modalOfsTabContentReal" style="display:none;">
                    <div style="max-height:380px;overflow-y:auto;overflow-x:auto;border:1px solid var(--color-border);border-radius:8px;">
                        <table class="sgt-modal-table" id="modalOfsTabelaReal">
                            <thead>
                                <tr>
                                    <th style="width:90px;" title="Data programada da OF (não a data do apontamento no Laboratório)">Data (OF)</th>
                                    <th>N° Série</th>
                                    <th>OF</th>
                                    <th>Projeto / Ref</th>
                                    <th>Descrição do Trafo</th>
                                    <th>Pedido</th>
                                    <th>Cliente</th>
                                    <th>Potência</th>
                                    <th>Núcleo</th>
                                    <th>Hora Ensaio</th>
                                </tr>
                            </thead>
                            <tbody id="modalOfsTbodyReal">
                                <tr><td colspan="10" style="text-align:center;padding:30px;color:#94a3b8;">Carregando OFs...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div style="padding:12px 24px;border-top:1px solid var(--color-border);background:var(--color-surface-2);display:flex;align-items:center;justify-content:space-between;">
                <span style="font-size:11px;color:#64748b;">
                    Clique nas abas acima para alternar entre as OFs programadas e os apontamentos concluídos.
                </span>
                <button type="button" onclick="fecharModalOfs()" class="btn btn-secondary btn-sm" style="font-weight:600;padding:6px 16px;border-radius:6px;cursor:pointer;">Fechar</button>
            </div>
        </div>
    </div>

</div>

<script>
    const sgtResumoDiarioCtx = {
        dataInicio: <?= json_encode($dataInicio) ?>,
        dataFim: <?= json_encode($dataFim) ?>,
        empresa: <?= json_encode($empresa) ?>,
        linha: <?= json_encode($linha) ?>,
    };

    // Auto-atualização: recarrega a página a cada 10 min pra refletir novas
    // versões do PLANO MESTRE.xlsx assim que o PCP resalvar o arquivo.
    setInterval(function () {
        const modalAberto = document.getElementById('modalOfs')?.classList.contains('active');
        if (modalAberto) return; // não interrompe quem está revisando a Relação de OFs
        window.location.reload();
    }, 10 * 60 * 1000);

    function abrirModalOfs(setorCod, setorLabel, linhaOverride) {
        const modal = document.getElementById('modalOfs');
        document.getElementById('modalOfsSetorLabel').textContent = 'Carregando ' + (setorLabel || 'setor') + '...';
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        document.getElementById('modalOfsKpiProg').textContent = '...';
        document.getElementById('modalOfsKpiReal').textContent = '...';
        document.getElementById('modalOfsKpiDiff').textContent = '...';
        document.getElementById('modalOfsKpiAdr').textContent = '...';
        document.getElementById('modalOfsInputBusca').value = '';
        document.getElementById('modalOfsTbodyProg').innerHTML = '<tr><td colspan="10" style="text-align:center;padding:30px;color:#94a3b8;">Carregando OFs programadas...</td></tr>';
        document.getElementById('modalOfsTbodyReal').innerHTML = '<tr><td colspan="10" style="text-align:center;padding:30px;color:#94a3b8;">Carregando OFs produzidas...</td></tr>';

        const params = new URLSearchParams({
            data_inicio: sgtResumoDiarioCtx.dataInicio,
            data_fim: sgtResumoDiarioCtx.dataFim,
            empresa: sgtResumoDiarioCtx.empresa,
            // Linhas da Média Força (TPD/TPM/TPS) são clicadas individualmente — usam a própria
            // linha da célula em vez do filtro global de "Linha" da tela.
            linha: linhaOverride || sgtResumoDiarioCtx.linha,
            setor: linhaOverride ? '' : (setorCod || ''),
        });

        fetch('../../api/producao-aderencia-pecas.php?' + params.toString())
            .then(res => {
                if (!res.ok) throw new Error('Servidor retornou status ' + res.status);
                return res.json();
            })
            .then(res => {
                if (!res.sucesso) {
                    alert(res.erro || 'Erro ao carregar OFs.');
                    return;
                }
                renderizarModalOfs(res, setorLabel);
            })
            .catch(err => {
                console.error('Erro na requisição:', err);
                document.getElementById('modalOfsTbodyProg').innerHTML = '<tr><td colspan="10" style="text-align:center;padding:20px;color:#ef4444;">Erro de conexão ao consultar OFs: ' + escapeHtmlOfs(err.message) + '</td></tr>';
                document.getElementById('modalOfsTbodyReal').innerHTML = '<tr><td colspan="10" style="text-align:center;padding:20px;color:#ef4444;">Erro de conexão ao consultar OFs: ' + escapeHtmlOfs(err.message) + '</td></tr>';
            });
    }

    function fecharModalOfs() {
        const modal = document.getElementById('modalOfs');
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }

    function fecharModalOfsSeBackdrop(e) {
        if (e.target.id === 'modalOfs') {
            fecharModalOfs();
        }
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') fecharModalOfs();
    });

    const ORDEM_CELULAS_FLUXO = ['CH', 'BT', 'AT', 'CNC', 'SOL', 'MN', 'PIN', 'ME', 'MF', 'LAB'];
    function renderizarCelulasFluxo(celulas) {
        if (!celulas) return '<span style="color:#94a3b8;">— (fora do Fluxo de Pedidos)</span>';
        let html = '<span style="display:inline-flex;gap:2px;flex-wrap:wrap;">';
        ORDEM_CELULAS_FLUXO.forEach((cel) => {
            const c = celulas[cel];
            if (!c || !c.total) return;
            const cor = c.ok === c.total ? 'background:#dcfce7;color:#15803d;' : (c.ok === 0 ? 'background:#fee2e2;color:#b91c1c;' : 'background:#fef3c7;color:#92400e;');
            html += `<span title="${cel}: ${c.ok}/${c.total} OK" style="font-size:9px;font-weight:800;padding:1px 4px;border-radius:3px;${cor}">${cel}</span>`;
        });
        html += '</span>';
        return html;
    }

    function renderizarModalOfs(res, setorLabel) {
        document.getElementById('modalOfsSetorLabel').textContent = setorLabel || '—';
        document.getElementById('modalOfsSubtitulo').textContent =
            'Período: ' + res.data_formatada + (res.dia_semana ? ' (' + res.dia_semana + ')' : '') +
            ' — Empresa ' + res.empresa + (res.linha && res.linha !== 'TODOS' ? ' — Linha ' + res.linha : '');

        document.getElementById('modalOfsKpiProg').textContent = Number(res.resumo.programado).toLocaleString('pt-BR') + ' un';
        document.getElementById('modalOfsKpiReal').textContent = Number(res.resumo.produzido).toLocaleString('pt-BR') + ' un';

        const diff = res.resumo.superavit;
        const diffEl = document.getElementById('modalOfsKpiDiff');
        const diffBox = document.getElementById('modalOfsKpiDiffBox');
        if (diff > 0) {
            diffEl.textContent = '+' + Number(diff).toLocaleString('pt-BR') + ' un';
            diffEl.style.color = '#15803d';
            diffBox.style.background = '#f0fdf4';
            diffBox.style.borderColor = '#bbf7d0';
        } else if (diff < 0) {
            diffEl.textContent = Number(diff).toLocaleString('pt-BR') + ' un';
            diffEl.style.color = '#b91c1c';
            diffBox.style.background = '#fef2f2';
            diffBox.style.borderColor = '#fecaca';
        } else {
            diffEl.textContent = '0 un (Na meta)';
            diffEl.style.color = '#475569';
            diffBox.style.background = '#f8fafc';
            diffBox.style.borderColor = '#e2e8f0';
        }

        const adrEl = document.getElementById('modalOfsKpiAdr');
        adrEl.textContent = Number(res.resumo.aderencia).toLocaleString('pt-BR', { minimumFractionDigits: 2 }) + '%';
        adrEl.style.color = res.resumo.atingiu_meta ? '#15803d' : '#b45309';

        document.getElementById('modalOfsBadgeCountProg').textContent = res.pecas_programadas.length;
        document.getElementById('modalOfsBadgeCountReal').textContent = res.pecas_produzidas.length;

        const mostrarCelulas = Number(res.empresa) === 4;
        document.getElementById('modalOfsThCelulas').style.display = mostrarCelulas ? '' : 'none';

        const tbodyProg = document.getElementById('modalOfsTbodyProg');
        if (!res.pecas_programadas || res.pecas_programadas.length === 0) {
            tbodyProg.innerHTML = '<tr><td colspan="' + (mostrarCelulas ? 11 : 10) + '" style="text-align:center;padding:30px;color:#94a3b8;">Nenhuma OF programada nesse período no Plano Mestre.</td></tr>';
        } else {
            let html = '';
            res.pecas_programadas.forEach((p) => {
                const badgeNucleo = p.nucleo === 'ENR' ? 'background:#dbeafe;color:#1e40af;' : (p.nucleo === 'JC' ? 'background:#fef3c7;color:#92400e;' : 'background:#f1f5f9;color:#475569;');
                html += `<tr>
                    <td style="font-family:monospace;color:#64748b;white-space:nowrap;">${escapeHtmlOfs(formatarDataBrOfs(p.data))}</td>
                    <td style="font-weight:700;color:#0f172a;white-space:nowrap;">${escapeHtmlOfs(p.op)}</td>
                    <td style="color:#475569;max-width:280px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${escapeHtmlOfs(p.descricao)}">${escapeHtmlOfs(p.descricao)}</td>
                    <td style="font-family:monospace;font-weight:600;">${escapeHtmlOfs(p.pedido)}</td>
                    <td style="color:#475569;white-space:nowrap;">${escapeHtmlOfs(p.cliente)}</td>
                    <td style="font-family:monospace;white-space:nowrap;">${escapeHtmlOfs(p.potencia)}</td>
                    <td><span style="font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px;${badgeNucleo}">${escapeHtmlOfs(p.nucleo)}</span></td>
                    <td style="text-align:right;font-weight:800;font-family:monospace;color:#1e40af;">${p.quantidade}</td>
                    <td style="text-align:right;font-family:monospace;color:#15803d;">${p.qtd_produzida}</td>
                    <td style="text-align:right;font-family:monospace;font-weight:700;color:${p.qtd_a_produzir > 0 ? '#b91c1c' : '#15803d'};">${p.qtd_a_produzir}</td>
                    ${mostrarCelulas ? `<td>${renderizarCelulasFluxo(p.celulas)}</td>` : ''}
                </tr>`;
            });
            tbodyProg.innerHTML = html;
        }

        const tbodyReal = document.getElementById('modalOfsTbodyReal');
        if (!res.pecas_produzidas || res.pecas_produzidas.length === 0) {
            tbodyReal.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:30px;color:#94a3b8;">Nenhum apontamento/produção registrada nesse período no Laboratório.</td></tr>';
        } else {
            let html = '';
            res.pecas_produzidas.forEach((p) => {
                const badgeNucleo = p.nucleo.includes('ENR') ? 'background:#dbeafe;color:#1e40af;' : (p.nucleo.includes('JC') ? 'background:#fef3c7;color:#92400e;' : 'background:#f1f5f9;color:#475569;');
                html += `<tr>
                    <td style="font-family:monospace;color:#64748b;white-space:nowrap;">${escapeHtmlOfs(formatarDataBrOfs(p.data))}</td>
                    <td style="font-family:monospace;font-weight:800;color:#0369a1;white-space:nowrap;">${escapeHtmlOfs(p.serie)}</td>
                    <td style="font-family:monospace;font-weight:700;color:#334155;white-space:nowrap;">${escapeHtmlOfs(p.of)}</td>
                    <td style="font-weight:700;color:#0f172a;white-space:nowrap;">${escapeHtmlOfs(p.referencia)}</td>
                    <td style="color:#475569;max-width:280px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${escapeHtmlOfs(p.descricao)}">${escapeHtmlOfs(p.descricao)}</td>
                    <td style="font-family:monospace;font-weight:600;">${escapeHtmlOfs(p.pedido)}</td>
                    <td style="color:#475569;white-space:nowrap;">${escapeHtmlOfs(p.cliente)}</td>
                    <td style="font-family:monospace;white-space:nowrap;">${escapeHtmlOfs(p.potencia)}</td>
                    <td><span style="font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px;${badgeNucleo}">${escapeHtmlOfs(p.nucleo)}</span></td>
                    <td style="font-family:monospace;color:#64748b;white-space:nowrap;">${escapeHtmlOfs(p.data_audit)}</td>
                </tr>`;
            });
            tbodyReal.innerHTML = html;
        }
    }

    function trocarAbaModalOfs(aba) {
        const btnProg = document.getElementById('modalOfsTabBtnProg');
        const btnReal = document.getElementById('modalOfsTabBtnReal');
        const contentProg = document.getElementById('modalOfsTabContentProg');
        const contentReal = document.getElementById('modalOfsTabContentReal');

        if (aba === 'prog') {
            btnProg.classList.add('active');
            btnReal.classList.remove('active');
            contentProg.style.display = 'block';
            contentReal.style.display = 'none';
        } else {
            btnReal.classList.add('active');
            btnProg.classList.remove('active');
            contentReal.style.display = 'block';
            contentProg.style.display = 'none';
        }
        filtrarTabelaModalOfs();
    }

    function filtrarTabelaModalOfs() {
        const q = (document.getElementById('modalOfsInputBusca').value || '').toLowerCase().trim();
        const isProgActive = document.getElementById('modalOfsTabBtnProg').classList.contains('active');
        const targetTbody = isProgActive ? document.getElementById('modalOfsTbodyProg') : document.getElementById('modalOfsTbodyReal');
        const rows = targetTbody.getElementsByTagName('tr');

        for (let i = 0; i < rows.length; i++) {
            const text = rows[i].textContent.toLowerCase();
            rows[i].style.display = (q === '' || text.includes(q)) ? '' : 'none';
        }
    }

    function formatarDataBrOfs(ymd) {
        if (!ymd || ymd.length < 10) return '—';
        const [ano, mes, dia] = ymd.split('-');
        return `${dia}/${mes}/${ano}`;
    }

    function escapeHtmlOfs(str) {
        if (!str && str !== 0) return '—';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
</script>

<?php
$extraHiddenInputs = ['linha' => $linha, 'empresa' => $empresa];
require_once __DIR__ . '/../../includes/modal-filtro-data.php';
?>

<?php layoutFooter(); ?>
