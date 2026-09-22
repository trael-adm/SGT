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

// ─── Parâmetros de Filtro ───────────────────────────────────────────────────
$anoAtual = (int) date('Y');
$mesAtual = (int) date('m');

$empresa = (int) ($_GET['empresa'] ?? 1);
if (!in_array($empresa, [1, 4], true)) $empresa = 1;

$ano   = (int) ($_GET['ano'] ?? $anoAtual);
$mes   = (int) ($_GET['mes'] ?? $mesAtual);
$setor = trim((string) ($_GET['setor'] ?? 'CONSOLIDADO'));
$linha = trim((string) ($_GET['linha'] ?? 'TODOS'));

// Configuração dinâmica das linhas / tipos de núcleo por empresa
$linhasDisponiveis = ($empresa === 4)
    ? [
        'TODOS' => 'Todos',
        'TPD'   => 'TPD',
        'TPM'   => 'TPM',
        'TPS'   => 'TPS',
      ]
    : [
        'TODOS' => 'Todos',
        'ENR'   => 'ENR (Monofásico)',
        'EMP'   => 'EMP (Convencional)',
        'JC'    => 'JC-TRIF',
      ];

if (!array_key_exists($linha, $linhasDisponiveis)) {
    $linha = 'TODOS';
}

$dados    = boletimCalcularAderenciaMensal($ano, $mes, $empresa, $setor, $linha);
$kpis     = $dados['kpis'];
$evolucao = $dados['evolucao_diaria'];

$setoresList = boletimObter11SetoresFabris();

$mesesNomesCompletos = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];

$pageTitle = 'Aderência Mensal — Dashboard de Produção';
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
        padding: 16px 20px;
        box-shadow: var(--shadow-sm, 0 1px 3px rgba(26,39,68,0.08));
        margin-bottom: 20px;
    }
    .sgt-kpi-grid {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 14px;
        margin-bottom: 20px;
    }
    @media (max-width: 1200px) {
        .sgt-kpi-grid {
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        }
    }
    .sgt-kpi-card {
        background: var(--color-surface, #ffffff);
        border: 1px solid var(--color-border, #e2e6ed);
        border-radius: var(--radius-lg, 10px);
        padding: 14px 16px;
        text-align: center;
        box-shadow: var(--shadow-sm, 0 1px 3px rgba(26,39,68,0.08));
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    .sgt-kpi-card:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-md, 0 4px 12px rgba(26,39,68,0.10));
    }
    .sgt-kpi-label {
        display: block;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--color-text-muted, #9aa3b8);
        margin-bottom: 4px;
    }
    .sgt-kpi-value {
        font-size: 1.25rem;
        font-weight: 800;
        font-family: var(--font-mono, monospace);
        color: var(--color-text-primary, #1a2133);
        line-height: 1.2;
    }
    .sgt-chart-card {
        background: var(--color-surface, #ffffff);
        border: 1px solid var(--color-border, #e2e6ed);
        border-radius: var(--radius-lg, 10px);
        padding: 20px;
        box-shadow: var(--shadow-sm, 0 1px 3px rgba(26,39,68,0.08));
        margin-bottom: 24px;
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

    /* Modal de Detalhes de Peças */
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

    /* Impressão — mostra só a área do dashboard (KPIs + gráficos), sem título nem filtros */
    @media print {
        /* html/body ficam com overflow:hidden + height:100% pra controlar o scroll da tela
           (ver main.css) — sem resetar isso, o navegador trata o documento como 1 viewport só
           e recorta tudo que passar da altura da tela, ignorando qualquer page-break-before. */
        html, body {
            height: auto !important;
            overflow: visible !important;
        }
        .sgt-dash-header,
        .sgt-filter-card,
        #dicaEvolucaoDiaria,
        #resumoAderenciaDiaria {
            display: none !important;
        }
        /* O layout padrão (sidebar/header) usa flex com overflow controlado — sem isso,
           .app-content mantém a largura/rolagem da tela e o gráfico sai cortado pela metade. */
        .app-wrapper, .app-main, .app-content {
            display: block !important;
            height: auto !important;
            overflow: visible !important;
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
        }
        .sgt-kpi-grid {
            grid-template-columns: repeat(5, 1fr) !important;
            gap: 8px !important;
            margin-bottom: 14px !important;
        }
        .sgt-kpi-card {
            padding: 8px 6px !important;
            box-shadow: none !important;
            border: 1px solid #d0d5dd !important;
        }
        .sgt-kpi-value { font-size: 1rem !important; }
        .sgt-chart-card {
            box-shadow: none !important;
            border: 1px solid #d0d5dd !important;
            page-break-inside: avoid;
            break-inside: avoid;
            margin-bottom: 16px !important;
        }
        .sgt-chart-card + .sgt-chart-card {
            page-break-before: always;
        }
        .sgt-chart-card canvas { max-height: 340px !important; max-width: 100% !important; width: 100% !important; }
    }
</style>

<div style="max-width: 100%; margin: 0 auto; padding: 4px 0 24px 0;">

    <!-- Top Header do Dashboard -->
    <div class="sgt-dash-header">
        <div>
            <h1 class="sgt-dash-title">
                <span>Dashboard de Produção</span>
                <span class="badge" style="background:#e0f2fe;color:#0369a1;font-size:0.75rem;padding:3px 10px;border-radius:9999px;font-weight:700;">
                    ADERÊNCIA MENSAL
                </span>
            </h1>
            <p style="font-size:var(--font-size-sm, 0.875rem);color:var(--color-text-secondary, #5a6480);margin-top:3px;">
                Análise de Desempenho &bull; Programado vs Realizado do Mês
            </p>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <button type="button" onclick="window.print()" class="btn btn-primary btn-sm btn-print" style="display:inline-flex;align-items:center;gap:6px;font-weight:600;cursor:pointer;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                Imprimir
            </button>
            <a href="/pages/painel-setor/index.php" class="btn btn-secondary btn-sm" style="display:inline-flex;align-items:center;gap:6px;font-weight:600;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 14l-4-4 4-4"/><path d="M5 10h11a4 4 0 1 1 0 8h-1"/></svg>
                Painel por Setor
            </a>
        </div>
    </div>

    <!-- Filtros do Dashboard -->
    <form method="GET" class="sgt-filter-card">
        <div style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:14px;">
            <!-- Filtro Empresa -->
            <div style="width:160px;">
                <label style="display:block;font-size:11px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;margin-bottom:5px;">Empresa</label>
                <select name="empresa" onchange="this.form.submit()" class="form-select" style="width:100%;height:36px;font-size:12px;font-weight:600;background:var(--color-surface-2);border:1px solid var(--color-border);border-radius:var(--radius-md);padding:0 10px;">
                    <option value="1" <?= $empresa === 1 ? 'selected' : '' ?>>1 - Distribuição</option>
                    <option value="4" <?= $empresa === 4 ? 'selected' : '' ?>>4 - Média Força</option>
                </select>
            </div>

            <!-- Filtro Ano -->
            <div style="width:100px;">
                <label style="display:block;font-size:11px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;margin-bottom:5px;">Ano</label>
                <select name="ano" onchange="this.form.submit()" class="form-select" style="width:100%;height:36px;font-size:12px;font-weight:600;background:var(--color-surface-2);border:1px solid var(--color-border);border-radius:var(--radius-md);padding:0 10px;">
                    <?php for ($a = 2024; $a <= 2027; $a++): ?>
                        <option value="<?= $a ?>" <?= $ano === $a ? 'selected' : '' ?>><?= $a ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <!-- Filtro Mês -->
            <div style="width:130px;">
                <label style="display:block;font-size:11px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;margin-bottom:5px;">Mês</label>
                <select name="mes" onchange="this.form.submit()" class="form-select" style="width:100%;height:36px;font-size:12px;font-weight:600;background:var(--color-surface-2);border:1px solid var(--color-border);border-radius:var(--radius-md);padding:0 10px;">
                    <?php foreach ($mesesNomesCompletos as $mNum => $mNome): ?>
                        <option value="<?= $mNum ?>" <?= $mes === $mNum ? 'selected' : '' ?>><?= $mNome ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Filtro Setor -->
            <div style="width:190px;">
                <label style="display:block;font-size:11px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;margin-bottom:5px;">Setor</label>
                <select name="setor" onchange="this.form.submit()" class="form-select" style="width:100%;height:36px;font-size:12px;font-weight:600;background:var(--color-surface-2);border:1px solid var(--color-border);border-radius:var(--radius-md);padding:0 10px;">
                    <option value="CONSOLIDADO" <?= $setor === 'CONSOLIDADO' ? 'selected' : '' ?>>Todos os Setores</option>
                    <?php foreach ($setoresList as $stKey => $stCfg): ?>
                        <option value="<?= $stKey ?>" <?= $setor === $stKey ? 'selected' : '' ?>><?= htmlspecialchars($stCfg['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Filtro Linha / Tipo de Núcleo -->
            <div style="flex:1;min-width:280px;">
                <label style="display:block;font-size:11px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;margin-bottom:5px;">
                    <?= ($empresa === 4) ? 'Linha (Média Força)' : 'Tipo de Núcleo (Distribuição)' ?>
                </label>
                <div style="display:flex;flex-wrap:wrap;gap:6px;">
                    <?php foreach ($linhasDisponiveis as $linCod => $linLabel): ?>
                        <button type="submit" name="linha" value="<?= $linCod ?>" class="sgt-pill-btn <?= $linha === $linCod ? 'active' : '' ?>">
                            <?= htmlspecialchars($linLabel) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </form>

    <!-- Grid dos 9 KPIs de Aderência -->
    <div class="sgt-kpi-grid">
        <!-- 1. Média Programada -->
        <div class="sgt-kpi-card">
            <span class="sgt-kpi-label">Média Programada</span>
            <span class="sgt-kpi-value"><?= number_format($kpis['media_programada'], 2, ',', '.') ?></span>
        </div>

        <!-- 2. Programado Parcial -->
        <div class="sgt-kpi-card">
            <span class="sgt-kpi-label">Programado Parcial</span>
            <span class="sgt-kpi-value"><?= number_format($kpis['programado_parcial'], 0, ',', '.') ?></span>
        </div>

        <!-- 3. Programado Total -->
        <div class="sgt-kpi-card">
            <span class="sgt-kpi-label">Programado Total</span>
            <span class="sgt-kpi-value"><?= number_format($kpis['programado_total'], 0, ',', '.') ?></span>
        </div>

        <!-- 4. Dias Úteis -->
        <div class="sgt-kpi-card">
            <span class="sgt-kpi-label">Dias Úteis</span>
            <span class="sgt-kpi-value"><?= $kpis['dias_uteis'] ?> <span style="font-size:12px;color:var(--color-text-muted);font-weight:500;">/ <?= $kpis['total_dias_uteis'] ?></span></span>
        </div>

        <!-- 5. Aderência Anual -->
        <div class="sgt-kpi-card">
            <span class="sgt-kpi-label">Aderência Anual</span>
            <span class="sgt-kpi-value" style="color:var(--color-success, #16a34a);"><?= number_format($kpis['aderencia_anual'], 2, ',', '.') ?>%</span>
        </div>

        <!-- 6. Média Produzida -->
        <div class="sgt-kpi-card">
            <span class="sgt-kpi-label">Média Produzida</span>
            <span class="sgt-kpi-value"><?= number_format($kpis['media_produzida'], 2, ',', '.') ?></span>
        </div>

        <!-- 7. Produzido Parcial -->
        <div class="sgt-kpi-card">
            <span class="sgt-kpi-label">Produzido Parcial</span>
            <span class="sgt-kpi-value"><?= number_format($kpis['produzido_parcial'], 0, ',', '.') ?></span>
        </div>

        <!-- 8. Alcance da Meta -->
        <div class="sgt-kpi-card">
            <span class="sgt-kpi-label">Alcance da Meta</span>
            <span class="sgt-kpi-value" style="color:#0284c7;"><?= number_format($kpis['alcance_meta'], 2, ',', '.') ?>%</span>
        </div>

        <!-- 9. Aderência Mensal -->
        <div class="sgt-kpi-card" style="grid-column: span 2;">
            <span class="sgt-kpi-label">Aderência Mensal</span>
            <span class="sgt-kpi-value" style="color: <?= $kpis['aderencia_mensal'] >= 100 ? 'var(--color-success, #16a34a)' : 'var(--color-warning, #d97706)' ?>;">
                <?= number_format($kpis['aderencia_mensal'], 2, ',', '.') ?>%
            </span>
        </div>
    </div>

    <!-- Card do Gráfico de Evolução Diária -->
    <div class="sgt-chart-card">
        <div class="sgt-card-header">
            <div>
                <h3 style="font-size:14px;font-weight:700;color:var(--color-text-primary);margin:0;">Evolução Diária</h3>
                <p style="font-size:11px;color:var(--color-text-muted);margin:2px 0 0 0;">Meta programada vs produção realizada por dia</p>
            </div>
            <!-- Legenda -->
            <div style="display:flex;align-items:center;gap:14px;font-size:11px;font-weight:700;color:var(--color-text-secondary);">
                <span style="display:flex;align-items:center;gap:5px;"><span style="width:10px;height:10px;border-radius:50%;background:#2563eb;display:inline-block;"></span> Programado</span>
                <span style="display:flex;align-items:center;gap:5px;"><span style="width:10px;height:10px;border-radius:50%;background:#dc2626;display:inline-block;"></span> Realizado (Até a Meta)</span>
                <span style="display:flex;align-items:center;gap:5px;"><span style="width:10px;height:10px;border-radius:50%;background:#16a34a;display:inline-block;"></span> Superávit (Acima da Meta)</span>
            </div>
        </div>

        <div style="position:relative;width:100%;height:380px;">
            <canvas id="chartEvolucaoDiaria"></canvas>
        </div>

        <div id="dicaEvolucaoDiaria" style="display:flex;align-items:center;justify-content:space-between;margin-top:12px;padding-top:10px;border-top:1px dashed var(--color-border);font-size:11px;color:var(--color-text-secondary);flex-wrap:wrap;gap:8px;">
            <span style="display:inline-flex;align-items:center;gap:6px;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                <span><strong>Dica:</strong> Clique em qualquer barra de dia para ver a <strong>relação completa de peças</strong> programadas e produzidas.</span>
            </span>
            <span style="font-size:11px;color:var(--color-text-muted);">Programação oficial: <strong>Plano Mestre (DataHoraProducaoAux / Laboratório)</strong></span>
        </div>
    </div>

    <!-- Card do Gráfico de Aderência Diária em % -->
    <div class="sgt-chart-card">
        <div class="sgt-card-header">
            <div>
                <h3 style="font-size:14px;font-weight:700;color:var(--color-text-primary);margin:0;">Fabricação x Programado (%)</h3>
                <p style="font-size:11px;color:var(--color-text-muted);margin:2px 0 0 0;">Percentual do programado que foi realmente produzido, dia a dia</p>
            </div>
            <!-- Legenda -->
            <div style="display:flex;align-items:center;gap:14px;font-size:11px;font-weight:700;color:var(--color-text-secondary);">
                <span style="display:flex;align-items:center;gap:5px;"><span style="width:10px;height:10px;border-radius:50%;background:#16a34a;display:inline-block;"></span> Na meta (&ge;100%)</span>
                <span style="display:flex;align-items:center;gap:5px;"><span style="width:10px;height:10px;border-radius:50%;background:#0284c7;display:inline-block;"></span> Próximo (&ge;90%)</span>
                <span style="display:flex;align-items:center;gap:5px;"><span style="width:10px;height:10px;border-radius:50%;background:#dc2626;display:inline-block;"></span> Abaixo (&lt;90%)</span>
                <span style="display:flex;align-items:center;gap:5px;"><span style="width:14px;height:0;border-top:2px dashed #94a3b8;display:inline-block;"></span> Meta (100%)</span>
            </div>
        </div>

        <div style="position:relative;width:100%;height:320px;">
            <canvas id="chartAderenciaDiariaPercent"></canvas>
        </div>

        <div id="resumoAderenciaDiaria" style="display:flex;align-items:center;justify-content:space-between;margin-top:12px;padding-top:10px;border-top:1px dashed var(--color-border);font-size:11px;color:var(--color-text-secondary);flex-wrap:wrap;gap:8px;">
            <span style="display:inline-flex;align-items:center;gap:6px;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                <span><strong>Dica:</strong> Clique em qualquer barra para ver a relação de peças daquele dia.</span>
            </span>
            <span id="resumoAderenciaDiariaTexto" style="font-size:11px;color:var(--color-text-muted);">—</span>
        </div>
    </div>

    <!-- Modal Relação de Peças por Dia -->
    <div id="modalRelaçãoPecas" class="sgt-modal-backdrop" onclick="fecharModalPecasSeBackdrop(event)">
        <div class="sgt-modal-dialog">
            <div class="sgt-modal-header">
                <div>
                    <h2 style="font-size:1.15rem;font-weight:800;color:var(--color-text-primary);margin:0;display:flex;align-items:center;gap:8px;">
                        <span>📋 Relação de Peças</span>
                        <span style="color:#94a3b8;font-weight:400;">—</span>
                        <span id="modalDataLabel" style="color:#0284c7;">—</span>
                    </h2>
                    <p style="font-size:11px;color:var(--color-text-secondary);margin:2px 0 0 0;">
                        Comparativo detalhado entre o Programado no Plano Mestre e o Realizado no Laboratório
                    </p>
                </div>
                <button type="button" onclick="fecharModalPecas()" style="border:none;background:transparent;cursor:pointer;font-size:24px;color:#94a3b8;line-height:1;padding:4px 8px;border-radius:6px;" title="Fechar (Esc)">
                    &times;
                </button>
            </div>

            <div class="sgt-modal-body">
                <!-- Mini KPIs Resumo do Dia -->
                <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:12px;margin-bottom:18px;">
                    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 14px;text-align:center;">
                        <span style="font-size:10px;font-weight:700;color:#1d4ed8;text-transform:uppercase;display:block;">Programado (Plano)</span>
                        <span id="modalKpiProg" style="font-size:1.25rem;font-weight:800;font-family:monospace;color:#1e40af;">—</span>
                    </div>
                    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 14px;text-align:center;">
                        <span style="font-size:10px;font-weight:700;color:#15803d;text-transform:uppercase;display:block;">Produzido (Laboratório)</span>
                        <span id="modalKpiReal" style="font-size:1.25rem;font-weight:800;font-family:monospace;color:#166534;">—</span>
                    </div>
                    <div id="modalKpiDiffBox" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 14px;text-align:center;">
                        <span style="font-size:10px;font-weight:700;color:#475569;text-transform:uppercase;display:block;">Saldo / Superávit</span>
                        <span id="modalKpiDiff" style="font-size:1.25rem;font-weight:800;font-family:monospace;color:#0f172a;">—</span>
                    </div>
                    <div style="background:#fefce8;border:1px solid #fef08a;border-radius:8px;padding:10px 14px;text-align:center;">
                        <span style="font-size:10px;font-weight:700;color:#a16207;text-transform:uppercase;display:block;">Aderência Diária</span>
                        <span id="modalKpiAdr" style="font-size:1.25rem;font-weight:800;font-family:monospace;color:#854d0e;">—</span>
                    </div>
                </div>

                <!-- Abas e Barra de Pesquisa -->
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:12px;">
                    <div class="sgt-modal-tabs" style="margin-bottom:0;border-bottom:none;">
                        <button type="button" id="tabBtnProg" onclick="trocarAbaModal('prog')" class="sgt-modal-tab-btn active">
                            <span>📋 Peças Programadas</span>
                            <span id="badgeCountProg" style="background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:9999px;font-size:11px;font-weight:700;">0</span>
                        </button>
                        <button type="button" id="tabBtnReal" onclick="trocarAbaModal('real')" class="sgt-modal-tab-btn">
                            <span>🏭 Peças Produzidas / Concluídas</span>
                            <span id="badgeCountReal" style="background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:9999px;font-size:11px;font-weight:700;">0</span>
                        </button>
                    </div>
                    <div style="min-width:260px;position:relative;">
                        <input type="text" id="modalInputBusca" onkeyup="filtrarTabelaModal()" placeholder="Buscar por OF, Série, Projeto, Pedido, Cliente..." style="width:100%;height:34px;font-size:12px;padding:0 10px 0 32px;border:1px solid var(--color-border);border-radius:6px;background:var(--color-surface-2);color:var(--color-text-primary);box-sizing:border-box;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" style="position:absolute;left:10px;top:10px;"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                    </div>
                </div>

                <!-- Conteúdo Aba 1: Programadas (Plano Mestre) -->
                <div id="tabContentProg" style="display:block;">
                    <div style="max-height:380px;overflow-y:auto;border:1px solid var(--color-border);border-radius:8px;">
                        <table class="sgt-modal-table" id="tabelaPecasProg">
                            <thead>
                                <tr>
                                    <th style="width:50px;">Seq</th>
                                    <th>Projeto / Ref</th>
                                    <th>Descrição do Trafo</th>
                                    <th>Pedido</th>
                                    <th>Cliente</th>
                                    <th>Potência</th>
                                    <th>Núcleo</th>
                                    <th style="text-align:right;">Qtd Prog</th>
                                    <th style="text-align:right;">Qtd Prod</th>
                                    <th style="text-align:right;">Saldo</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyPecasProg">
                                <tr><td colspan="10" style="text-align:center;padding:30px;color:#94a3b8;">Carregando peças...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Conteúdo Aba 2: Produzidas (Kardex / Laboratório) -->
                <div id="tabContentReal" style="display:none;">
                    <div style="max-height:380px;overflow-y:auto;border:1px solid var(--color-border);border-radius:8px;">
                        <table class="sgt-modal-table" id="tabelaPecasReal">
                            <thead>
                                <tr>
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
                            <tbody id="tbodyPecasReal">
                                <tr><td colspan="9" style="text-align:center;padding:30px;color:#94a3b8;">Carregando peças...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div style="padding:12px 24px;border-top:1px solid var(--color-border);background:var(--color-surface-2);display:flex;align-items:center;justify-content:space-between;">
                <span style="font-size:11px;color:#64748b;">
                    Clique nas abas acima para alternar entre as peças programadas e os apontamentos concluídos.
                </span>
                <button type="button" onclick="fecharModalPecas()" class="btn btn-secondary btn-sm" style="font-weight:600;padding:6px 16px;border-radius:6px;cursor:pointer;">Fechar</button>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
<script>
    Chart.register(ChartDataLabels);

    const rawData = <?= json_encode($evolucao) ?>;
    const labels = rawData.map(d => d.label);

    // 1. Programado (Barra Azul Lado a Lado)
    const progData = rawData.map(d => d.programado);

    // 2. Realizado - Parte Base até a Meta (Vermelho)
    const realBaseData = rawData.map(d => {
        if (!d.is_passado) return null;
        const real = Number(d.realizado) || 0;
        const prog = Number(d.programado) || 0;
        return Math.min(real, prog);
    });

    // 3. Realizado - Parte Excedente acima da Meta (Verde Empilhado no Topo)
    const realExcedenteData = rawData.map(d => {
        if (!d.is_passado) return null;
        const real = Number(d.realizado) || 0;
        const prog = Number(d.programado) || 0;
        return Math.max(0, real - prog);
    });

    const ctx = document.getElementById('chartEvolucaoDiaria').getContext('2d');
    const chartEvolucaoDiaria = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Programado',
                    data: progData,
                    backgroundColor: '#2563eb',
                    stack: 'stack_prog',
                    borderRadius: 4,
                    barPercentage: 0.65,
                    categoryPercentage: 0.8,
                    datalabels: {
                        clip: false,
                        color: '#1a2133',
                        anchor: 'end',
                        align: 'top',
                        offset: 4,
                        font: { size: 10, weight: '700', family: 'monospace' },
                        formatter: function(val) {
                            return (val && val > 0) ? Math.round(val) : '';
                        }
                    }
                },
                {
                    label: 'Realizado (Até a Meta)',
                    data: realBaseData,
                    backgroundColor: '#dc2626',
                    stack: 'stack_real',
                    borderRadius: (ctx) => {
                        const i = ctx.dataIndex;
                        const exc = realExcedenteData[i] || 0;
                        return exc > 0 ? { topLeft: 0, topRight: 0, bottomLeft: 4, bottomRight: 4 } : 4;
                    },
                    barPercentage: 0.65,
                    categoryPercentage: 0.8,
                    datalabels: {
                        clip: false,
                        color: '#1a2133',
                        anchor: 'end',
                        align: 'top',
                        offset: 4,
                        font: { size: 10, weight: '700', family: 'monospace' },
                        formatter: function(val, ctx) {
                            const i = ctx.dataIndex;
                            const exc = realExcedenteData[i] || 0;
                            if (exc > 0) return ''; // Não exibe dentro da barra; o total aparecerá no topo da fatia verde
                            return (val && val > 0) ? Math.round(val) : '';
                        }
                    }
                },
                {
                    label: 'Superávit (Acima da Meta)',
                    data: realExcedenteData,
                    backgroundColor: '#16a34a',
                    stack: 'stack_real',
                    borderRadius: { topLeft: 4, topRight: 4, bottomLeft: 0, bottomRight: 0 },
                    barPercentage: 0.65,
                    categoryPercentage: 0.8,
                    datalabels: {
                        clip: false,
                        color: '#1a2133',
                        anchor: 'end',
                        align: 'top',
                        offset: 4,
                        font: { size: 10, weight: '700', family: 'monospace' },
                        formatter: function(val, ctx) {
                            const i = ctx.dataIndex;
                            if (!val || val <= 0) return '';
                            const total = (realBaseData[i] || 0) + val;
                            return total > 0 ? Math.round(total) : '';
                        }
                    }
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: {
                padding: {
                    top: 25,
                    left: 6,
                    right: 6,
                    bottom: 0
                }
            },
            onHover: (event, chartElement) => {
                event.native.target.style.cursor = chartElement[0] ? 'pointer' : 'default';
            },
            onClick: (evt, activeElements) => {
                if (!activeElements || activeElements.length === 0) return;
                const index = activeElements[0].index;
                const diaInfo = rawData[index];
                if (diaInfo && diaInfo.data) {
                    abrirModalPecas(diaInfo.data, diaInfo.label);
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { color: '#5a6480', font: { weight: '600', size: 11 } }
                },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    suggestedMax: 2,
                    grace: '20%',
                    grid: { color: '#f1f5f9' },
                    ticks: {
                        color: '#9aa3b8',
                        font: { family: 'monospace' },
                        precision: 0,
                        stepSize: 1
                    }
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1a2133',
                    titleColor: '#ffffff',
                    borderColor: '#e2e6ed',
                    borderWidth: 1,
                    padding: 10,
                    callbacks: {
                        label: function(context) {
                            const i = context.dataIndex;
                            if (context.datasetIndex === 0) {
                                return 'Programado: ' + context.raw + ' un';
                            } else if (context.datasetIndex === 1) {
                                const total = (realBaseData[i] || 0) + (realExcedenteData[i] || 0);
                                const exc = realExcedenteData[i] || 0;
                                if (exc > 0) {
                                    return 'Realizado Total: ' + total + ' un (' + context.raw + ' até a meta + ' + exc + ' excedente)';
                                }
                                return 'Realizado: ' + total + ' un (Abaixo da meta)';
                            } else {
                                return null;
                            }
                        }
                    }
                }
            }
        }
    });

    // 2. Aderência Diária em % (QtdProduzida das peças programadas ÷ Programado do dia) —
    // mede se o que foi programado pro dia (peças/NS do Plano Mestre) realmente foi feito,
    // não o volume total de fábrica no dia (que é o que o gráfico Evolução Diária mostra).
    // Mesmos thresholds de cor do Resumo Diário.
    function corAderencia(pct) {
        if (pct >= 100) return '#16a34a';
        if (pct >= 90) return '#0284c7';
        return '#dc2626';
    }

    const aderenciaPercentData = rawData.map(d => {
        if (!d.is_passado) return null;
        const prog = Number(d.programado) || 0;
        if (prog <= 0) return null;
        const realPlano = Number(d.realizado_plano) || 0;
        return Math.round((realPlano / prog) * 1000) / 10;
    });

    const metaLinhaData = rawData.map((d, i) => aderenciaPercentData[i] === null ? null : 100);

    const diasComDado = aderenciaPercentData.filter(v => v !== null);
    const diasNaMeta = diasComDado.filter(v => v >= 100).length;
    const diasProximo = diasComDado.filter(v => v >= 90 && v < 100).length;
    const diasAbaixo = diasComDado.filter(v => v < 90).length;
    const mediaAderenciaDiaria = diasComDado.length > 0
        ? (diasComDado.reduce((a, b) => a + b, 0) / diasComDado.length)
        : 0;

    document.getElementById('resumoAderenciaDiariaTexto').innerHTML =
        `Média do período: <strong>${mediaAderenciaDiaria.toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%</strong> &bull; ` +
        `<span style="color:#16a34a;font-weight:700;">${diasNaMeta} na meta</span> &bull; ` +
        `<span style="color:#0284c7;font-weight:700;">${diasProximo} próximo</span> &bull; ` +
        `<span style="color:#dc2626;font-weight:700;">${diasAbaixo} abaixo</span>`;

    const ctxAderencia = document.getElementById('chartAderenciaDiariaPercent').getContext('2d');
    const chartAderenciaDiaria = new Chart(ctxAderencia, {
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'bar',
                    label: 'Aderência do Dia',
                    data: aderenciaPercentData,
                    backgroundColor: aderenciaPercentData.map(v => v === null ? 'transparent' : corAderencia(v)),
                    borderRadius: 4,
                    barPercentage: 0.55,
                    categoryPercentage: 0.8,
                    order: 1,
                    datalabels: {
                        clip: false,
                        color: '#1a2133',
                        anchor: 'end',
                        align: 'top',
                        offset: 4,
                        font: { size: 10, weight: '700', family: 'monospace' },
                        formatter: (val) => (val === null) ? '' : val.toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%'
                    }
                },
                {
                    type: 'line',
                    label: 'Meta (100%)',
                    data: metaLinhaData,
                    borderColor: '#94a3b8',
                    borderWidth: 2,
                    borderDash: [6, 4],
                    pointRadius: 0,
                    fill: false,
                    order: 0,
                    datalabels: { display: false }
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: {
                padding: { top: 25, left: 6, right: 6, bottom: 0 }
            },
            onHover: (event, chartElement) => {
                event.native.target.style.cursor = chartElement[0] ? 'pointer' : 'default';
            },
            onClick: (evt, activeElements) => {
                if (!activeElements || activeElements.length === 0) return;
                const index = activeElements[0].index;
                const diaInfo = rawData[index];
                if (diaInfo && diaInfo.data) {
                    abrirModalPecas(diaInfo.data, diaInfo.label);
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { color: '#5a6480', font: { weight: '600', size: 11 } }
                },
                y: {
                    beginAtZero: true,
                    suggestedMax: 110,
                    grace: '15%',
                    grid: { color: '#f1f5f9' },
                    ticks: {
                        color: '#9aa3b8',
                        font: { family: 'monospace' },
                        callback: (val) => val + '%'
                    }
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1a2133',
                    titleColor: '#ffffff',
                    borderColor: '#e2e6ed',
                    borderWidth: 1,
                    padding: 10,
                    filter: (item) => item.datasetIndex === 0 && item.raw !== null,
                    callbacks: {
                        label: function(context) {
                            const i = context.dataIndex;
                            const d = rawData[i];
                            return `Aderência: ${context.raw.toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}% (${d.realizado_plano} de ${d.programado} programadas)`;
                        }
                    }
                }
            }
        }
    });

    // Lógica do Modal de Relação de Peças
    function abrirModalPecas(dataYmd, diaLabel) {
        const modal = document.getElementById('modalRelaçãoPecas');
        const dataLabelEl = document.getElementById('modalDataLabel');
        
        dataLabelEl.textContent = 'Carregando dia ' + (diaLabel || dataYmd) + '...';
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        // Reset KPIs
        document.getElementById('modalKpiProg').textContent = '...';
        document.getElementById('modalKpiReal').textContent = '...';
        document.getElementById('modalKpiDiff').textContent = '...';
        document.getElementById('modalKpiAdr').textContent = '...';
        document.getElementById('modalInputBusca').value = '';

        // Reset Tabelas com Loading
        document.getElementById('tbodyPecasProg').innerHTML = '<tr><td colspan="10" style="text-align:center;padding:30px;color:#94a3b8;">Carregando peças programadas...</td></tr>';
        document.getElementById('tbodyPecasReal').innerHTML = '<tr><td colspan="9" style="text-align:center;padding:30px;color:#94a3b8;">Carregando peças produzidas...</td></tr>';

        const urlParams = new URLSearchParams(window.location.search);
        const empresa = urlParams.get('empresa') || '<?= $empresa ?>';
        const linha = urlParams.get('linha') || '<?= $linha ?>';

        const apiUrl = '../../api/producao-aderencia-pecas.php';
        fetch(`${apiUrl}?data=${encodeURIComponent(dataYmd)}&empresa=${encodeURIComponent(empresa)}&linha=${encodeURIComponent(linha)}`)
            .then(res => {
                if (!res.ok) {
                    throw new Error('Servidor retornou status ' + res.status);
                }
                return res.json();
            })
            .then(res => {
                if (!res.sucesso) {
                    alert(res.erro || 'Erro ao carregar peças.');
                    return;
                }
                renderizarModalPecas(res);
            })
            .catch(err => {
                console.error('Erro na requisição:', err);
                document.getElementById('tbodyPecasProg').innerHTML = '<tr><td colspan="10" style="text-align:center;padding:20px;color:#ef4444;">Erro de conexão ao consultar peças: ' + escapeHtml(err.message) + '</td></tr>';
                document.getElementById('tbodyPecasReal').innerHTML = '<tr><td colspan="9" style="text-align:center;padding:20px;color:#ef4444;">Erro de conexão ao consultar peças: ' + escapeHtml(err.message) + '</td></tr>';
            });
    }

    function fecharModalPecas() {
        const modal = document.getElementById('modalRelaçãoPecas');
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }

    function fecharModalPecasSeBackdrop(e) {
        if (e.target.id === 'modalRelaçãoPecas') {
            fecharModalPecas();
        }
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') fecharModalPecas();
    });

    function renderizarModalPecas(res) {
        document.getElementById('modalDataLabel').textContent = `${res.data_formatada} (${res.dia_semana})`;
        
        // KPIs
        document.getElementById('modalKpiProg').textContent = Number(res.resumo.programado).toLocaleString('pt-BR') + ' un';
        document.getElementById('modalKpiReal').textContent = Number(res.resumo.produzido).toLocaleString('pt-BR') + ' un';
        
        const diff = res.resumo.superavit;
        const diffEl = document.getElementById('modalKpiDiff');
        const diffBox = document.getElementById('modalKpiDiffBox');
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

        const adrEl = document.getElementById('modalKpiAdr');
        adrEl.textContent = Number(res.resumo.aderencia).toLocaleString('pt-BR', { minimumFractionDigits: 2 }) + '%';
        adrEl.style.color = res.resumo.atingiu_meta ? '#15803d' : '#b45309';

        // Badges de contagem
        document.getElementById('badgeCountProg').textContent = res.pecas_programadas.length;
        document.getElementById('badgeCountReal').textContent = res.pecas_produzidas.length;

        // Renderizar tabela programadas
        const tbodyProg = document.getElementById('tbodyPecasProg');
        if (!res.pecas_programadas || res.pecas_programadas.length === 0) {
            tbodyProg.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:30px;color:#94a3b8;">Nenhuma peça programada nesta data no Plano Mestre.</td></tr>';
        } else {
            let html = '';
            res.pecas_programadas.forEach((p, i) => {
                const badgeNucleo = p.nucleo === 'ENR' ? 'background:#dbeafe;color:#1e40af;' : (p.nucleo === 'JC' ? 'background:#fef3c7;color:#92400e;' : 'background:#f1f5f9;color:#475569;');
                html += `<tr>
                    <td style="font-family:monospace;font-weight:700;color:#64748b;">${p.seq_plano || (i + 1)}</td>
                    <td style="font-weight:700;color:#0f172a;white-space:nowrap;">${escapeHtml(p.op)}</td>
                    <td style="color:#475569;max-width:280px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${escapeHtml(p.descricao)}">${escapeHtml(p.descricao)}</td>
                    <td style="font-family:monospace;font-weight:600;">${escapeHtml(p.pedido)}</td>
                    <td style="color:#475569;white-space:nowrap;">${escapeHtml(p.cliente)}</td>
                    <td style="font-family:monospace;white-space:nowrap;">${escapeHtml(p.potencia)}</td>
                    <td><span style="font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px;${badgeNucleo}">${escapeHtml(p.nucleo)}</span></td>
                    <td style="text-align:right;font-weight:800;font-family:monospace;color:#1e40af;">${p.quantidade}</td>
                    <td style="text-align:right;font-family:monospace;color:#15803d;">${p.qtd_produzida}</td>
                    <td style="text-align:right;font-family:monospace;font-weight:700;color:${p.qtd_a_produzir > 0 ? '#b91c1c' : '#15803d'};">${p.qtd_a_produzir}</td>
                </tr>`;
            });
            tbodyProg.innerHTML = html;
        }

        // Renderizar tabela produzidas
        const tbodyReal = document.getElementById('tbodyPecasReal');
        if (!res.pecas_produzidas || res.pecas_produzidas.length === 0) {
            tbodyReal.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:30px;color:#94a3b8;">Nenhum apontamento/produção registrada nesta data no Laboratório.</td></tr>';
        } else {
            let html = '';
            res.pecas_produzidas.forEach((p) => {
                const badgeNucleo = p.nucleo.includes('ENR') ? 'background:#dbeafe;color:#1e40af;' : (p.nucleo.includes('JC') ? 'background:#fef3c7;color:#92400e;' : 'background:#f1f5f9;color:#475569;');
                html += `<tr>
                    <td style="font-family:monospace;font-weight:800;color:#0369a1;white-space:nowrap;">${escapeHtml(p.serie)}</td>
                    <td style="font-family:monospace;font-weight:700;color:#334155;white-space:nowrap;">${escapeHtml(p.of)}</td>
                    <td style="font-weight:700;color:#0f172a;white-space:nowrap;">${escapeHtml(p.referencia)}</td>
                    <td style="color:#475569;max-width:280px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${escapeHtml(p.descricao)}">${escapeHtml(p.descricao)}</td>
                    <td style="font-family:monospace;font-weight:600;">${escapeHtml(p.pedido)}</td>
                    <td style="color:#475569;white-space:nowrap;">${escapeHtml(p.cliente)}</td>
                    <td style="font-family:monospace;white-space:nowrap;">${escapeHtml(p.potencia)}</td>
                    <td><span style="font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px;${badgeNucleo}">${escapeHtml(p.nucleo)}</span></td>
                    <td style="font-family:monospace;color:#64748b;white-space:nowrap;">${escapeHtml(p.data_audit)}</td>
                </tr>`;
            });
            tbodyReal.innerHTML = html;
        }
    }

    function trocarAbaModal(aba) {
        const btnProg = document.getElementById('tabBtnProg');
        const btnReal = document.getElementById('tabBtnReal');
        const contentProg = document.getElementById('tabContentProg');
        const contentReal = document.getElementById('tabContentReal');

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
        filtrarTabelaModal();
    }

    function filtrarTabelaModal() {
        const q = (document.getElementById('modalInputBusca').value || '').toLowerCase().trim();
        const isProgActive = document.getElementById('tabBtnProg').classList.contains('active');
        const targetTbody = isProgActive ? document.getElementById('tbodyPecasProg') : document.getElementById('tbodyPecasReal');
        const rows = targetTbody.getElementsByTagName('tr');

        for (let i = 0; i < rows.length; i++) {
            const text = rows[i].textContent.toLowerCase();
            rows[i].style.display = (q === '' || text.includes(q)) ? '' : 'none';
        }
    }

    function escapeHtml(str) {
        if (!str) return '—';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Redimensiona os gráficos ao entrar/sair do modo impressão, já que o layout
    // (sidebar/filtros ocultos) muda a largura disponível depois que os gráficos já foram desenhados.
    window.addEventListener('beforeprint', () => {
        chartEvolucaoDiaria.resize();
        chartAderenciaDiaria.resize();
    });
    window.addEventListener('afterprint', () => {
        chartEvolucaoDiaria.resize();
        chartAderenciaDiaria.resize();
    });
</script>

<?php layoutFooter(); ?>
