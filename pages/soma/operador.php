<?php
declare(strict_types=1);

/**
 * SOMA — Eficiência do Operador (Ficha Individual e Evolução)
 */

require_once dirname(__DIR__, 2) . '/config/conexao.php';
require_once dirname(__DIR__, 2) . '/config/session.php';
require_once dirname(__DIR__, 2) . '/includes/helpers.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

requireLogin();

$db = getDB();

// 1. Carrega lista de operadores
$todosOperadores = $db->query('SELECT id, cod, nome FROM soma_operadores WHERE deleted_at IS NULL ORDER BY nome ASC')->fetchAll();

if (empty($todosOperadores)) {
    layoutHeader('SOMA — Eficiência do Operador', 'soma');
    $somaAbaAtual = 'operador';
    require dirname(__DIR__, 2) . '/includes/soma-subnav.php';
    echo '<div class="card text-center py-12 text-[#9aa3b8] text-xs">Nenhum operador cadastrado no sistema.</div>';
    layoutFooter();
    exit;
}

// 2. Operador Selecionado
$idOperador = !empty($_GET['id_operador']) ? (int) $_GET['id_operador'] : (int) $todosOperadores[0]['id'];

// Carrega dados do operador selecionado
$stmtOp = $db->prepare('SELECT * FROM soma_operadores WHERE id = :id AND deleted_at IS NULL LIMIT 1');
$stmtOp->execute(['id' => $idOperador]);
$operadorAtual = $stmtOp->fetch();

if (!$operadorAtual) {
    $operadorAtual = $todosOperadores[0];
    $idOperador = (int) $operadorAtual['id'];
}

// 3. Filtro de Período
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

// 4. Cláusula WHERE
$where = ['t.deleted_at IS NULL', 't.id_operador = :id_operador'];
$params = ['id_operador' => $idOperador];

if ($dataInicio !== '') {
    $where[] = 't.data >= :data_inicio';
    $params['data_inicio'] = $dataInicio;
}
if ($dataFim !== '') {
    $where[] = 't.data <= :data_fim';
    $params['data_fim'] = $dataFim;
}

$whereSql = implode(' AND ', $where);

// 5. Métricas Consolidadas do Operador
$stmtMetricas = $db->prepare("
    SELECT 
        COUNT(t.id) AS total_turnos,
        COALESCE(SUM(t.minutos_disponiveis), 0) AS total_disp,
        COALESCE(SUM(t.minutos_produzidos), 0)  AS total_prod,
        COALESCE(SUM(t.minutos_paradas), 0)     AS total_paradas,
        COALESCE(AVG(t.eficiencia), 0)          AS media_eficiencia
    FROM soma_turnos t
    WHERE {$whereSql}
");
$stmtMetricas->execute($params);
$metricas = $stmtMetricas->fetch() ?: [];

$totalTurnos = (int) ($metricas['total_turnos'] ?? 0);
$totalDisp = (float) ($metricas['total_disp'] ?? 0.0);
$totalProd = (float) ($metricas['total_prod'] ?? 0.0);
$totalParadas = (float) ($metricas['total_paradas'] ?? 0.0);
$mediaEficiencia = $totalDisp > 0 ? round(($totalProd / $totalDisp) * 100, 1) : 0.0;

// 6. Volume Total de Peças Produzidas
$stmtVol = $db->prepare("
    SELECT COALESCE(SUM(rp.qtd), 0) AS total_pecas
    FROM soma_registros_producao rp
    INNER JOIN soma_turnos t ON t.id = rp.id_turno
    WHERE {$whereSql} AND rp.deleted_at IS NULL
");
$stmtVol->execute($params);
$volumeTotalPecas = (int) ($stmtVol->fetchColumn() ?: 0);

// 7. Gráfico de Evolução Diária da Eficiência
$stmtEvolucao = $db->prepare("
    SELECT 
        t.data,
        t.turno,
        t.eficiencia,
        t.minutos_produzidos,
        t.minutos_disponiveis
    FROM soma_turnos t
    WHERE {$whereSql}
    ORDER BY t.data ASC, t.id ASC
");
$stmtEvolucao->execute($params);
$evolucaoRows = $stmtEvolucao->fetchAll();

$diasLabels = [];
$diasEficiencias = [];
$metasArray = [];
foreach ($evolucaoRows as $ev) {
    $diasLabels[] = date('d/m', strtotime((string) $ev['data'])) . " ({$ev['turno']})";
    $diasEficiencias[] = round((float) $ev['eficiencia'], 1);
    $metasArray[] = 80.0;
}

// 8. Principais Paradas do Operador
$stmtOpParadas = $db->prepare("
    SELECT 
        m.id, m.cod, m.descricao, m.tipo,
        COALESCE(SUM(rp.duracao_minutos), 0) AS total_minutos,
        COUNT(rp.id) AS ocorrencias
    FROM soma_registros_paradas rp
    INNER JOIN soma_turnos t ON t.id = rp.id_turno
    INNER JOIN soma_paradas_motivos m ON m.id = rp.id_motivo
    WHERE {$whereSql} AND rp.deleted_at IS NULL
    GROUP BY m.id, m.cod, m.descricao, m.tipo
    ORDER BY total_minutos DESC
    LIMIT 5
");
$stmtOpParadas->execute($params);
$opParadas = $stmtOpParadas->fetchAll();

// 9. Histórico Detalhado dos Turnos do Operador
$stmtHist = $db->prepare("
    SELECT 
        t.*,
        maq.nome AS maquina_nome, maq.cod AS maquina_cod,
        st.descricao AS setor_nome,
        (SELECT COALESCE(SUM(qtd), 0) FROM soma_registros_producao WHERE id_turno = t.id AND deleted_at IS NULL) AS volume_turno
    FROM soma_turnos t
    LEFT JOIN soma_maquinas maq ON maq.id = t.id_maquina
    LEFT JOIN soma_setores st ON st.id = t.id_setor
    WHERE {$whereSql}
    ORDER BY t.data DESC, t.id DESC
");
$stmtHist->execute($params);
$historicoTurnos = $stmtHist->fetchAll();

layoutHeader('SOMA — Eficiência do Operador', 'soma');

$somaAbaAtual = 'operador';
require dirname(__DIR__, 2) . '/includes/soma-subnav.php';
?>

<!-- Filtros de Operador e Período -->
<div class="card mb-6 p-4">
    <form method="GET" action="" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
        
        <div class="lg:col-span-2">
            <label class="block text-xs font-semibold text-[#5a6480] mb-1">Selecionar Colaborador / Operador</label>
            <select name="id_operador" onchange="this.form.submit()" class="form-input text-xs font-semibold text-[#1a2133]">
                <?php foreach ($todosOperadores as $op): ?>
                    <option value="<?= (int) $op['id'] ?>" <?= $idOperador === (int) $op['id'] ? 'selected' : '' ?>>
                        <?= e($op['cod'] . ' — ' . $op['nome']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

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

        <div class="flex gap-2">
            <button type="submit" class="btn btn-primary text-xs w-full justify-center">
                Filtrar
            </button>
            <a href="<?= APP_URL ?>/pages/soma/operador.php" class="btn btn-neutral text-xs px-2.5" title="Limpar Filtros">✕</a>
        </div>

    </form>
</div>

<!-- Ficha de Identificação e Resumo de Métricas do Operador -->
<div class="grid grid-cols-1 lg:grid-cols-4 gap-4 mb-6">
    <!-- Card Perfil -->
    <div class="card p-4 bg-[#1a3d2a] text-white flex flex-col justify-between">
        <div>
            <div class="flex items-center gap-3 mb-3">
                <div class="w-12 h-12 rounded-xl bg-[#e8a020] text-[#1a3d2a] font-bold text-lg flex items-center justify-center shadow-md">
                    <?= strtoupper(substr((string) ($operadorAtual['nome'] ?? 'OP'), 0, 2)) ?>
                </div>
                <div>
                    <h3 class="text-sm font-bold text-white"><?= e($operadorAtual['nome']) ?></h3>
                    <span class="text-xs font-mono text-emerald-300"><?= e($operadorAtual['cod']) ?></span>
                </div>
            </div>
            <p class="text-xs text-emerald-100">Colaborador registrado para cronoanálise fabril.</p>
        </div>

        <div class="mt-4 pt-3 border-t border-white/10 flex items-center justify-between text-xs">
            <span>Status no Sistema:</span>
            <span class="badge badge-success">Ativo</span>
        </div>
    </div>

    <!-- KPI 1: Eficiência Média -->
    <div class="card p-4 border-l-4 <?= $mediaEficiencia >= 80 ? 'border-l-[#16a34a]' : ($mediaEficiencia >= 60 ? 'border-l-[#d97706]' : 'border-l-[#dc2626]') ?> flex flex-col justify-between">
        <div>
            <span class="text-[10px] font-bold uppercase text-[#5a6480] tracking-wider">Eficiência Apurada</span>
            <div class="text-3xl font-mono font-bold <?= $mediaEficiencia >= 80 ? 'text-[#16a34a]' : ($mediaEficiencia >= 60 ? 'text-[#d97706]' : 'text-[#dc2626]') ?> mt-1">
                <?= number_format($mediaEficiencia, 1, ',', '.') ?>%
            </div>
        </div>
        <div class="mt-2 text-xs flex items-center justify-between">
            <span class="text-[#5a6480]">Meta Padrão: 80.0%</span>
            <?= $mediaEficiencia >= 80 ? '<span class="badge badge-success text-[10px]">[DENTRO DO PADRÃO]</span>' : ($mediaEficiencia >= 60 ? '<span class="badge badge-warning text-[10px]">[DESVIO MODERADO]</span>' : '<span class="badge badge-danger text-[10px]">[GARGALO CRÍTICO]</span>') ?>
        </div>
    </div>

    <!-- KPI 2: Volume Fabricado -->
    <div class="card p-4 border-l-4 border-l-[#2563eb] flex flex-col justify-between">
        <div>
            <span class="text-[10px] font-bold uppercase text-[#5a6480] tracking-wider">Peças Fabricadas</span>
            <div class="text-3xl font-mono font-bold text-[#1a2133] mt-1">
                <?= number_format($volumeTotalPecas, 0, ',', '.') ?>
            </div>
        </div>
        <div class="mt-2 text-xs text-[#5a6480]">
            Em <strong><?= $totalTurnos ?> turnos</strong> trabalhados
        </div>
    </div>

    <!-- KPI 3: Tempo Parado Acumulado -->
    <div class="card p-4 border-l-4 border-l-[#d97706] flex flex-col justify-between">
        <div>
            <span class="text-[10px] font-bold uppercase text-[#5a6480] tracking-wider">Tempo Total em Paradas</span>
            <div class="text-2xl font-mono font-bold text-amber-700 mt-1">
                <?= formatarMinutosHoras($totalParadas) ?>
            </div>
        </div>
        <div class="mt-2 text-xs text-[#5a6480]">
            Tempo registrado em interrupções
        </div>
    </div>
</div>

<!-- Gráficos: Evolução Diária & Principais Paradas do Colaborador -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    <!-- Gráfico de Linha da Eficiência (2 Colunas) -->
    <div class="card p-4 lg:col-span-2 space-y-4">
        <div class="pb-3 border-b border-[#e2e6ed] flex items-center justify-between">
            <div>
                <h3 class="text-sm font-bold text-[#1a2133]">Evolução da Eficiência Dia a Dia</h3>
                <p class="text-[11px] text-[#5a6480]">Rendimento de trabalho versus meta padrão de 80%.</p>
            </div>
            <span class="badge badge-success text-[10px]">Meta 80%</span>
        </div>
        <div class="h-72">
            <?php if (empty($diasLabels)): ?>
                <div class="h-full flex items-center justify-center text-[#9aa3b8] text-xs">Sem dados diários no período selecionado.</div>
            <?php else: ?>
                <canvas id="chartEvolucaoOperador"></canvas>
            <?php endif; ?>
        </div>
    </div>

    <!-- Principais Paradas do Operador (1 Coluna) -->
    <div class="card p-4 flex flex-col justify-between space-y-4">
        <div>
            <div class="pb-3 border-b border-[#e2e6ed] flex items-center justify-between">
                <h3 class="text-sm font-bold text-[#1a2133]">Paradas Recorrentes</h3>
                <span class="badge badge-neutral text-xs">Ofensores</span>
            </div>

            <div class="py-3 space-y-2.5">
                <?php if (empty($opParadas)): ?>
                    <p class="text-xs text-[#9aa3b8] py-8 text-center italic">Nenhuma parada registrada para este colaborador.</p>
                <?php else: foreach ($opParadas as $opp): ?>
                    <div class="p-2.5 bg-[#f8f9fb] border border-[#e2e6ed] rounded-lg">
                        <div class="flex items-center justify-between">
                            <span class="font-semibold text-xs text-[#1a2133] truncate max-w-[180px]"><?= e($opp['descricao']) ?></span>
                            <span class="font-mono font-bold text-xs text-amber-700"><?= (int) $opp['total_minutos'] ?> min</span>
                        </div>
                        <div class="flex items-center justify-between text-[10px] text-[#5a6480] mt-1">
                            <span><?= e($opp['cod']) ?> (<?= $opp['tipo'] === 'PROG' ? 'Prog' : 'Não Prog' ?>)</span>
                            <span><?= $opp['ocorrencias'] ?> vez(es)</span>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div class="p-3 bg-[#fef3dc] border border-[#fbd38d] rounded-xl text-xs text-[#7a4f08]">
            <strong>Diagnóstico:</strong> Acompanhar tempos de setup e trocas de bobinas para elevar a utilização efetiva.
        </div>
    </div>
</div>

<!-- Tabela de Histórico de Turnos do Operador -->
<div class="card p-4 space-y-4">
    <div class="pb-3 border-b border-[#e2e6ed] flex items-center justify-between">
        <div>
            <h3 class="text-sm font-bold text-[#1a2133]">Histórico de Apontamentos do Colaborador</h3>
            <p class="text-[11px] text-[#5a6480]">Últimos turnos trabalhados e eficiência individual apurada.</p>
        </div>
        <span class="badge badge-neutral text-xs font-mono"><?= count($historicoTurnos) ?> registros</span>
    </div>

    <div class="overflow-x-auto border border-[#e2e6ed] rounded-lg">
        <table class="w-full text-left text-xs">
            <thead class="bg-[#f8f9fb] text-[#5a6480] border-b border-[#e2e6ed]">
                <tr>
                    <th class="py-2.5 px-3 w-24">Data</th>
                    <th class="py-2.5 px-3 w-16 text-center">Turno</th>
                    <th class="py-2.5 px-3">Máquina / Ativo</th>
                    <th class="py-2.5 px-3">Setor</th>
                    <th class="py-2.5 px-3 text-right">Volume</th>
                    <th class="py-2.5 px-3 text-right">Produtivo</th>
                    <th class="py-2.5 px-3 text-right">Paradas</th>
                    <th class="py-2.5 px-3 text-right">Eficiência</th>
                    <th class="py-2.5 px-3 text-center">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#e2e6ed]">
                <?php if (empty($historicoTurnos)): ?>
                    <tr><td colspan="9" class="text-center py-6 text-[#9aa3b8] text-xs">Nenhum turno registrado para este operador no período.</td></tr>
                <?php else: foreach ($historicoTurnos as $ht): ?>
                    <tr class="hover:bg-[#f8f9fb] transition">
                        <td class="py-2 px-3 font-mono font-medium text-[#1a2133]"><?= formatarDataBr($ht['data']) ?></td>
                        <td class="py-2 px-3 text-center">
                            <span class="badge badge-neutral text-[10px] font-mono"><?= e($ht['turno']) ?></span>
                        </td>
                        <td class="py-2 px-3"><?= e($ht['maquina_nome'] ?? '—') ?></td>
                        <td class="py-2 px-3 text-[#5a6480]"><?= e($ht['setor_nome'] ?? '—') ?></td>
                        <td class="py-2 px-3 text-right font-mono font-bold text-[#1a2133]"><?= (int) $ht['volume_turno'] ?> pçs</td>
                        <td class="py-2 px-3 text-right font-mono text-emerald-700 font-medium"><?= (int) round((float) $ht['minutos_produzidos']) ?> min</td>
                        <td class="py-2 px-3 text-right font-mono text-amber-700"><?= (int) round((float) $ht['minutos_paradas']) ?> min</td>
                        <td class="py-2 px-3 text-right font-mono font-bold <?= (float) $ht['eficiencia'] >= 80 ? 'text-[#16a34a]' : ((float) $ht['eficiencia'] >= 60 ? 'text-[#d97706]' : 'text-[#dc2626]') ?>">
                            <?= number_format((float) $ht['eficiencia'], 1, ',', '.') ?>%
                        </td>
                        <td class="py-2 px-3 text-center"><?= renderBadgeStatus((string) $ht['status']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const ctxEv = document.getElementById('chartEvolucaoOperador')?.getContext('2d');
    if (ctxEv) {
        new Chart(ctxEv, {
            type: 'line',
            data: {
                labels: <?= json_encode($diasLabels) ?>,
                datasets: [
                    {
                        label: 'Eficiência Real (%)',
                        data: <?= json_encode($diasEficiencias) ?>,
                        borderColor: '#16a34a',
                        backgroundColor: 'rgba(22, 163, 74, 0.15)',
                        fill: true,
                        tension: 0.3
                    },
                    {
                        label: 'Meta Padrão (80%)',
                        data: <?= json_encode($metasArray) ?>,
                        borderColor: '#d97706',
                        borderDash: [4, 4],
                        fill: false,
                        pointRadius: 0
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 120,
                        ticks: { callback: v => v + '%' }
                    }
                },
                plugins: {
                    legend: { position: 'top', labels: { boxWidth: 12 } }
                }
            }
        });
    }
});
</script>

<?php
layoutFooter();
