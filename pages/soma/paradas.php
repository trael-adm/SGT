<?php
declare(strict_types=1);

/**
 * SOMA — Análise de Paradas Industriais (Ranking & Pareto)
 */

require_once dirname(__DIR__, 2) . '/config/conexao.php';
require_once dirname(__DIR__, 2) . '/config/session.php';
require_once dirname(__DIR__, 2) . '/includes/helpers.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

requireLogin();

$db = getDB();

// 1. Filtros
$periodo = trim((string) ($_GET['periodo'] ?? 'mes_atual'));
$hoje = date('Y-m-d');
$dataInicio = trim((string) ($_GET['data_inicio'] ?? ''));
$dataFim = trim((string) ($_GET['data_fim'] ?? ''));

if ($periodo === 'hoje') {
    $dataInicio = $hoje;
    $dataFim = $hoje;
} elseif ($periodo === '7dias') {
    $dataInicio = date('Y-m-d', strtotime('-6 days'));
    $dataFim = $hoje;
} elseif ($periodo === 'mes_atual') {
    $dataInicio = date('Y-m-01');
    $dataFim = date('Y-m-t');
} elseif ($periodo === 'mes_anterior') {
    $dataInicio = date('Y-m-01', strtotime('first day of last month'));
    $dataFim = date('Y-m-t', strtotime('last day of last month'));
} elseif ($periodo === 'todos') {
    $dataInicio = '';
    $dataFim = '';
}

$idSetor = !empty($_GET['id_setor']) ? (int) $_GET['id_setor'] : null;
$turnoFiltro = trim((string) ($_GET['turno'] ?? ''));
$idEmpresa = !empty($_GET['id_empresa']) ? (int) $_GET['id_empresa'] : null;
$tipoFiltro = trim((string) ($_GET['tipo'] ?? ''));

// 2. WHERE Clause
$where = ['rp.deleted_at IS NULL', 't.deleted_at IS NULL'];
$params = [];

if ($dataInicio !== '') {
    $where[] = 't.data >= :data_inicio';
    $params['data_inicio'] = $dataInicio;
}
if ($dataFim !== '') {
    $where[] = 't.data <= :data_fim';
    $params['data_fim'] = $dataFim;
}
if ($idSetor) {
    $where[] = 't.id_setor = :id_setor';
    $params['id_setor'] = $idSetor;
}
if ($turnoFiltro !== '') {
    $where[] = 't.turno = :turno';
    $params['turno'] = $turnoFiltro;
}
if ($idEmpresa) {
    $where[] = 't.id_empresa = :id_empresa';
    $params['id_empresa'] = $idEmpresa;
}
if ($tipoFiltro !== '') {
    $where[] = 'm.tipo = :tipo';
    $params['tipo'] = $tipoFiltro;
}

$whereSql = implode(' AND ', $where);

// 3. Totais Globais de Paradas
$stmtTot = $db->prepare("
    SELECT 
        COALESCE(SUM(rp.duracao_minutos), 0) AS total_minutos,
        COUNT(rp.id) AS total_ocorrencias,
        COALESCE(SUM(CASE WHEN m.tipo = 'PROG' THEN rp.duracao_minutos ELSE 0 END), 0) AS min_prog,
        COALESCE(SUM(CASE WHEN m.tipo = 'NAO_PROG' THEN rp.duracao_minutos ELSE 0 END), 0) AS min_nao_prog
    FROM soma_registros_paradas rp
    INNER JOIN soma_turnos t ON t.id = rp.id_turno
    INNER JOIN soma_paradas_motivos m ON m.id = rp.id_motivo
    WHERE {$whereSql}
");
$stmtTot->execute($params);
$totais = $stmtTot->fetch() ?: [];

$totalMinutos = (float) ($totais['total_minutos'] ?? 0.0);
$totalOcorrencias = (int) ($totais['total_ocorrencias'] ?? 0);
$minProg = (float) ($totais['min_prog'] ?? 0.0);
$minNaoProg = (float) ($totais['min_nao_prog'] ?? 0.0);

$pctProg = $totalMinutos > 0 ? round(($minProg / $totalMinutos) * 100, 1) : 0.0;
$pctNaoProg = $totalMinutos > 0 ? round(($minNaoProg / $totalMinutos) * 100, 1) : 0.0;

// 4. Ranking de Paradas por Motivo (Pareto)
$stmtRank = $db->prepare("
    SELECT 
        m.id, m.cod, m.descricao, m.tipo,
        COALESCE(SUM(rp.duracao_minutos), 0) AS total_minutos,
        COUNT(rp.id) AS total_ocorrencias,
        COALESCE(AVG(rp.duracao_minutos), 0) AS media_minutos
    FROM soma_registros_paradas rp
    INNER JOIN soma_turnos t ON t.id = rp.id_turno
    INNER JOIN soma_paradas_motivos m ON m.id = rp.id_motivo
    WHERE {$whereSql}
    GROUP BY m.id, m.cod, m.descricao, m.tipo
    ORDER BY total_minutos DESC
");
$stmtRank->execute($params);
$rankingParadas = $stmtRank->fetchAll();

// Dados para Chart.js - Pareto
$labelsPareto = [];
$dadosPareto = [];
$coresPareto = [];
foreach ($rankingParadas as $rp) {
    $labelsPareto[] = (string) $rp['descricao'];
    $dadosPareto[] = round((float) $rp['total_minutos'], 1);
    $coresPareto[] = $rp['tipo'] === 'PROG' ? '#2563eb' : '#dc2626';
}

// 5. Cadastros para selects
$listaSetores = $db->query('SELECT id, cod, descricao FROM soma_setores WHERE ativo = 1 AND deleted_at IS NULL ORDER BY descricao ASC')->fetchAll();
$listaEmpresas = $db->query('SELECT id, cod, nome FROM soma_empresas WHERE ativo = 1 AND deleted_at IS NULL ORDER BY nome ASC')->fetchAll();

layoutHeader('SOMA — Análise de Paradas & Pareto', 'soma');

$somaAbaAtual = 'paradas';
require dirname(__DIR__, 2) . '/includes/soma-subnav.php';
?>

<!-- Filtros de Busca -->
<div class="card mb-6 p-4">
    <form method="GET" action="" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 items-end">
        <div>
            <label class="block text-xs font-semibold text-[#5a6480] mb-1">Período</label>
            <select name="periodo" onchange="this.form.submit()" class="form-input text-xs">
                <option value="hoje" <?= $periodo === 'hoje' ? 'selected' : '' ?>>Hoje</option>
                <option value="7dias" <?= $periodo === '7dias' ? 'selected' : '' ?>>Últimos 7 dias</option>
                <option value="mes_atual" <?= $periodo === 'mes_atual' ? 'selected' : '' ?>>Mês Atual</option>
                <option value="mes_anterior" <?= $periodo === 'mes_anterior' ? 'selected' : '' ?>>Mês Anterior</option>
                <option value="todos" <?= $periodo === 'todos' ? 'selected' : '' ?>>Todo o Período</option>
            </select>
        </div>

        <div>
            <label class="block text-xs font-semibold text-[#5a6480] mb-1">Data Início</label>
            <input type="date" name="data_inicio" value="<?= e($dataInicio) ?>" class="form-input text-xs">
        </div>

        <div>
            <label class="block text-xs font-semibold text-[#5a6480] mb-1">Data Fim</label>
            <input type="date" name="data_fim" value="<?= e($dataFim) ?>" class="form-input text-xs">
        </div>

        <div>
            <label class="block text-xs font-semibold text-[#5a6480] mb-1">Setor Fabril</label>
            <select name="id_setor" class="form-input text-xs">
                <option value="">Todos os Setores</option>
                <?php foreach ($listaSetores as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= $idSetor === (int) $s['id'] ? 'selected' : '' ?>>
                        <?= e($s['cod'] . ' - ' . $s['descricao']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="block text-xs font-semibold text-[#5a6480] mb-1">Natureza da Parada</label>
            <select name="tipo" class="form-input text-xs">
                <option value="">Todas as Paradas</option>
                <option value="NAO_PROG" <?= $tipoFiltro === 'NAO_PROG' ? 'selected' : '' ?>>Não Programada (Falhas)</option>
                <option value="PROG" <?= $tipoFiltro === 'PROG' ? 'selected' : '' ?>>Programada (Setup/DDS)</option>
            </select>
        </div>

        <div class="flex gap-2">
            <button type="submit" class="btn btn-primary text-xs w-full justify-center">
                <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                Filtrar
            </button>
            <a href="<?= APP_URL ?>/pages/soma/paradas.php" class="btn btn-neutral text-xs px-2.5" title="Limpar Filtros">✕</a>
        </div>
    </form>
</div>

<!-- Cards Resumo de Perdas -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="card p-4 border-l-4 border-l-[#dc2626]">
        <span class="text-xs font-semibold text-[#5a6480] uppercase tracking-wider">Tempo Total Parado</span>
        <div class="text-2xl font-bold font-mono text-red-700 mt-1">
            <?= formatarMinutosHoras($totalMinutos) ?>
        </div>
        <div class="mt-2 text-xs text-[#5a6480]">
            Em <strong><?= number_format($totalOcorrencias, 0, ',', '.') ?> ocorrências</strong> no período
        </div>
    </div>

    <div class="card p-4 border-l-4 border-l-[#dc2626]">
        <span class="text-xs font-semibold text-[#5a6480] uppercase tracking-wider">Não Programadas (Críticas)</span>
        <div class="text-2xl font-bold font-mono text-red-800 mt-1">
            <?= formatarMinutosHoras($minNaoProg) ?>
        </div>
        <div class="mt-2 text-xs text-red-600 font-semibold">
            <?= number_format($pctNaoProg, 1) ?>% do tempo perdido total
        </div>
    </div>

    <div class="card p-4 border-l-4 border-l-[#2563eb]">
        <span class="text-xs font-semibold text-[#5a6480] uppercase tracking-wider">Programadas (Setup/DDS)</span>
        <div class="text-2xl font-bold font-mono text-blue-700 mt-1">
            <?= formatarMinutosHoras($minProg) ?>
        </div>
        <div class="mt-2 text-xs text-blue-600 font-semibold">
            <?= number_format($pctProg, 1) ?>% do tempo perdido total
        </div>
    </div>

    <div class="card p-4 border-l-4 border-l-[#d97706]">
        <span class="text-xs font-semibold text-[#5a6480] uppercase tracking-wider">Duração Média / Evento</span>
        <div class="text-2xl font-bold font-mono text-amber-700 mt-1">
            <?= $totalOcorrencias > 0 ? number_format($totalMinutos / $totalOcorrencias, 1) . ' min' : '0 min' ?>
        </div>
        <div class="mt-2 text-xs text-[#5a6480]">
            Tempo médio por parada
        </div>
    </div>
</div>

<!-- Gráficos de Paradas (Pareto e Rosca) -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    <!-- Gráfico de Pareto (2 Colunas) -->
    <div class="card p-4 lg:col-span-2 space-y-4">
        <div class="pb-3 border-b border-[#e2e6ed] flex items-center justify-between">
            <div>
                <h3 class="text-sm font-bold text-[#1a2133]">Principais Ofensores de Parada (Ranking de Tempo)</h3>
                <p class="text-[11px] text-[#5a6480]">Distribuição dos motivos com maior impacto em minutos perdidos.</p>
            </div>
            <span class="badge badge-neutral text-xs font-mono">Top Motivos</span>
        </div>
        <div class="h-72">
            <?php if (empty($dadosPareto)): ?>
                <div class="h-full flex items-center justify-center text-[#9aa3b8] text-xs">Nenhuma parada registrada no período.</div>
            <?php else: ?>
                <canvas id="chartParetoParadas"></canvas>
            <?php endif; ?>
        </div>
    </div>

    <!-- Rosca PROG vs NAO PROG (1 Coluna) -->
    <div class="card p-4 flex flex-col justify-between space-y-4">
        <div>
            <div class="pb-3 border-b border-[#e2e6ed] flex items-center justify-between">
                <h3 class="text-sm font-bold text-[#1a2133]">Natureza das Paradas</h3>
                <span class="badge badge-neutral text-xs">Classificação</span>
            </div>
            <div class="py-4 flex justify-center">
                <div class="relative w-44 h-44">
                    <canvas id="chartRoscaTipo"></canvas>
                </div>
            </div>
            <div class="space-y-2 pt-2 border-t border-[#e2e6ed] text-xs">
                <div class="flex items-center justify-between">
                    <span class="flex items-center gap-1.5 text-[#5a6480]">
                        <span class="w-2.5 h-2.5 rounded-full bg-[#dc2626]"></span> Não Programadas
                    </span>
                    <span class="font-mono font-bold text-[#1a2133]"><?= number_format($pctNaoProg, 1) ?>% (<?= formatarMinutosHoras($minNaoProg) ?>)</span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="flex items-center gap-1.5 text-[#5a6480]">
                        <span class="w-2.5 h-2.5 rounded-full bg-[#2563eb]"></span> Programadas
                    </span>
                    <span class="font-mono font-bold text-[#1a2133]"><?= number_format($pctProg, 1) ?>% (<?= formatarMinutosHoras($minProg) ?>)</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Tabela Analítica Completa de Motivos de Parada -->
<div class="card p-4 mb-6 space-y-4">
    <div class="pb-3 border-b border-[#e2e6ed] flex items-center justify-between">
        <div>
            <h3 class="text-sm font-bold text-[#1a2133]">Quadro Geral de Motivos de Parada</h3>
            <p class="text-[11px] text-[#5a6480]">Detalhamento de impacto, frequência e tempo médio por classificação.</p>
        </div>
        <span class="badge badge-neutral text-xs font-mono"><?= count($rankingParadas) ?> motivos identificados</span>
    </div>

    <div class="overflow-x-auto border border-[#e2e6ed] rounded-lg">
        <table class="w-full text-left text-xs">
            <thead class="bg-[#f8f9fb] text-[#5a6480] border-b border-[#e2e6ed]">
                <tr>
                    <th class="py-2.5 px-3 w-24">Código</th>
                    <th class="py-2.5 px-3">Motivo da Interrupção</th>
                    <th class="py-2.5 px-3 text-center w-36">Natureza</th>
                    <th class="py-2.5 px-3 text-right w-32">Tempo Total</th>
                    <th class="py-2.5 px-3 text-right w-28">% Impacto</th>
                    <th class="py-2.5 px-3 text-center w-28">Ocorrências</th>
                    <th class="py-2.5 px-3 text-right w-32">Duração Média</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#e2e6ed]">
                <?php if (empty($rankingParadas)): ?>
                    <tr><td colspan="7" class="text-center py-6 text-[#9aa3b8] text-xs">Nenhum evento de parada no período.</td></tr>
                <?php else: foreach ($rankingParadas as $rp): 
                    $minMot = (float) $rp['total_minutos'];
                    $pctMot = $totalMinutos > 0 ? ($minMot / $totalMinutos) * 100 : 0.0;
                ?>
                    <tr class="hover:bg-[#f8f9fb] transition">
                        <td class="py-2 px-3 font-mono font-bold text-[#1a2133]"><?= e($rp['cod']) ?></td>
                        <td class="py-2 px-3 font-medium text-[#1a2133]"><?= e($rp['descricao']) ?></td>
                        <td class="py-2 px-3 text-center">
                            <?= $rp['tipo'] === 'PROG' 
                                ? '<span class="badge badge-info text-[10px]">Programada</span>' 
                                : '<span class="badge badge-danger text-[10px]">Não Programada</span>' ?>
                        </td>
                        <td class="py-2 px-3 text-right font-mono font-bold <?= $rp['tipo'] === 'NAO_PROG' ? 'text-red-700' : 'text-blue-700' ?>">
                            <?= formatarMinutosHoras($minMot) ?>
                        </td>
                        <td class="py-2 px-3 text-right font-mono text-xs text-[#5a6480]">
                            <?= number_format($pctMot, 1) ?>%
                        </td>
                        <td class="py-2 px-3 text-center font-mono font-semibold text-[#1a2133]">
                            <?= (int) $rp['total_ocorrencias'] ?>
                        </td>
                        <td class="py-2 px-3 text-right font-mono text-[#5a6480]">
                            <?= number_format((float) $rp['media_minutos'], 1) ?> min
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // 1. Pareto Chart
    const ctxPareto = document.getElementById('chartParetoParadas')?.getContext('2d');
    if (ctxPareto) {
        new Chart(ctxPareto, {
            type: 'bar',
            data: {
                labels: <?= json_encode($labelsPareto, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                datasets: [{
                    label: 'Minutos Parados',
                    data: <?= json_encode($dadosPareto) ?>,
                    backgroundColor: <?= json_encode($coresPareto) ?>,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { callback: v => v + ' min' }
                    },
                    x: {
                        ticks: { font: { size: 10 } }
                    }
                },
                plugins: {
                    legend: { display: false }
                }
            }
        });
    }

    // 2. Rosca Chart
    const ctxRosca = document.getElementById('chartRoscaTipo')?.getContext('2d');
    if (ctxRosca) {
        new Chart(ctxRosca, {
            type: 'doughnut',
            data: {
                labels: ['Não Programadas', 'Programadas'],
                datasets: [{
                    data: [<?= round($minNaoProg) ?>, <?= round($minProg) ?>],
                    backgroundColor: ['#dc2626', '#2563eb'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: {
                    legend: { display: false }
                }
            }
        });
    }
});
</script>

<?php
layoutFooter();
