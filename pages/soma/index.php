<?php
declare(strict_types=1);

/**
 * SOMA — Dashboard Executivo & Painel OEE (Cronoanálise Industrial)
 */

require_once dirname(__DIR__, 2) . '/config/conexao.php';
require_once dirname(__DIR__, 2) . '/config/session.php';
require_once dirname(__DIR__, 2) . '/includes/helpers.php';
require_once dirname(__DIR__, 2) . '/includes/soma-helpers.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

requireLogin();

$db = getDB();
somaGarantirTabelas($db);

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

// 2. Monta Cláusulas WHERE
$where = ['t.deleted_at IS NULL'];
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

$whereSql = implode(' AND ', $where);

// 3. Totais Globais dos Turnos
$stmtTotais = $db->prepare("
    SELECT 
        COUNT(t.id) AS total_turnos,
        COALESCE(SUM(t.minutos_disponiveis), 0) AS total_disp,
        COALESCE(SUM(t.minutos_produzidos), 0)  AS total_prod,
        COALESCE(SUM(t.minutos_paradas), 0)     AS total_paradas,
        COALESCE(AVG(t.eficiencia), 0)          AS media_eficiencia
    FROM soma_turnos t
    WHERE {$whereSql}
");
$stmtTotais->execute($params);
$totais = $stmtTotais->fetch() ?: [];

$totalTurnos = (int) ($totais['total_turnos'] ?? 0);
$totalDisp = (float) ($totais['total_disp'] ?? 0.0);
$totalProd = (float) ($totais['total_prod'] ?? 0.0);
$totalParadas = (float) ($totais['total_paradas'] ?? 0.0);

$eficienciaGlobal = $totalDisp > 0 ? round(($totalProd / $totalDisp) * 100, 1) : 0.0;
$tempoResidualMin = max(0, $totalDisp - ($totalProd + $totalParadas));

// 4. Volume Total de Peças
$stmtPecas = $db->prepare("
    SELECT COALESCE(SUM(rp.qtd), 0) AS volume_pecas
    FROM soma_registros_producao rp
    INNER JOIN soma_turnos t ON t.id = rp.id_turno
    WHERE {$whereSql} AND rp.deleted_at IS NULL
");
$stmtPecas->execute($params);
$volumeTotalPecas = (int) ($stmtPecas->fetchColumn() ?: 0);

// 5. Paradas Críticas (Gargalos)
$stmtGargalo = $db->prepare("
    SELECT 
        m.id, m.cod, m.descricao, m.tipo,
        COALESCE(SUM(p.duracao_minutos), 0) AS total_min,
        COUNT(p.id) AS total_ocorrencias
    FROM soma_registros_paradas p
    INNER JOIN soma_turnos t ON t.id = p.id_turno
    INNER JOIN soma_paradas_motivos m ON m.id = p.id_motivo
    WHERE {$whereSql} AND p.deleted_at IS NULL
    GROUP BY m.id, m.cod, m.descricao, m.tipo
    ORDER BY total_min DESC
    LIMIT 3
");
$stmtGargalo->execute($params);
$topGargalos = $stmtGargalo->fetchAll();

// 6. Histograma por Operador
$stmtOp = $db->prepare("
    SELECT 
        op.id, op.nome, op.cod,
        COALESCE(SUM(t.minutos_disponiveis), 0) AS disp,
        COALESCE(SUM(t.minutos_produzidos), 0)  AS prod,
        COALESCE(AVG(t.eficiencia), 0)          AS ef
    FROM soma_turnos t
    INNER JOIN soma_operadores op ON op.id = t.id_operador
    WHERE {$whereSql}
    GROUP BY op.id, op.nome, op.cod
    ORDER BY ef DESC
    LIMIT 8
");
$stmtOp->execute($params);
$operadoresRank = $stmtOp->fetchAll();

// Dados para Chart.js - Operadores
$opNomes = [];
$opEficiencias = [];
$opCores = [];
foreach ($operadoresRank as $op) {
    $opNomes[] = (string) $op['nome'];
    $efVal = round((float) $op['ef'], 1);
    $opEficiencias[] = $efVal;
    $opCores[] = $efVal >= 80.0 ? '#16a34a' : ($efVal >= 60.0 ? '#d97706' : '#dc2626');
}

// 7. Gráfico Diário de Horas (Produzidas vs Disponíveis vs Paradas)
$stmtDiario = $db->prepare("
    SELECT 
        t.data,
        COALESCE(SUM(t.minutos_disponiveis) / 60, 0) AS horas_disp,
        COALESCE(SUM(t.minutos_produzidos) / 60, 0)  AS horas_prod,
        COALESCE(SUM(t.minutos_paradas) / 60, 0)     AS horas_paradas
    FROM soma_turnos t
    WHERE {$whereSql}
    GROUP BY t.data
    ORDER BY t.data ASC
");
$stmtDiario->execute($params);
$diasRows = $stmtDiario->fetchAll();

$diasLabels = [];
$diasProd = [];
$diasDisp = [];
$diasParadas = [];
foreach ($diasRows as $d) {
    $diasLabels[] = date('d/m', strtotime((string) $d['data']));
    $diasProd[] = round((float) $d['horas_prod'], 1);
    $diasDisp[] = round((float) $d['horas_disp'], 1);
    $diasParadas[] = round((float) $d['horas_paradas'], 1);
}

// 8. Tabela Comparativa por Setor
$stmtSetores = $db->prepare("
    SELECT 
        st.id, st.cod, st.descricao, st.meta,
        COUNT(t.id) AS turnos,
        COALESCE(SUM(t.minutos_disponiveis), 0) AS disp,
        COALESCE(SUM(t.minutos_produzidos), 0)  AS prod,
        COALESCE(SUM(t.minutos_paradas), 0)     AS paradas
    FROM soma_setores st
    LEFT JOIN soma_turnos t ON t.id_setor = st.id AND {$whereSql}
    WHERE st.deleted_at IS NULL
    GROUP BY st.id, st.cod, st.descricao, st.meta
    ORDER BY st.descricao ASC
");
$stmtSetores->execute($params);
$tabelaSetores = $stmtSetores->fetchAll();

// 9. Cadastros para Selects
$listaSetores = $db->query('SELECT id, cod, descricao FROM soma_setores WHERE ativo = 1 AND deleted_at IS NULL ORDER BY descricao ASC')->fetchAll();
$listaEmpresas = $db->query('SELECT id, cod, nome FROM soma_empresas WHERE ativo = 1 AND deleted_at IS NULL ORDER BY nome ASC')->fetchAll();

layoutHeader('SOMA — Dashboard Executivo & OEE', 'soma');

$somaAbaAtual = 'dashboard';
require dirname(__DIR__, 2) . '/includes/soma-subnav.php';
?>

<!-- Filtros Globais -->
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
            <label class="block text-xs font-semibold text-[#5a6480] mb-1">Setor</label>
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
            <label class="block text-xs font-semibold text-[#5a6480] mb-1">Turno</label>
            <select name="turno" class="form-input text-xs">
                <option value="">Todos os Turnos</option>
                <option value="D" <?= $turnoFiltro === 'D' ? 'selected' : '' ?>>Diurno (D)</option>
                <option value="N" <?= $turnoFiltro === 'N' ? 'selected' : '' ?>>Noturno (N)</option>
                <option value="M" <?= $turnoFiltro === 'M' ? 'selected' : '' ?>>Misto / Especial</option>
            </select>
        </div>

        <div class="flex gap-2">
            <button type="submit" class="btn btn-primary text-xs w-full justify-center">
                <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                Filtrar
            </button>
            <a href="<?= APP_URL ?>/pages/soma/index.php" class="btn btn-neutral text-xs px-2.5" title="Limpar Filtros">
                ✕
            </a>
        </div>
    </form>
</div>

<!-- Grid de KPIs Principais -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    
    <!-- KPI 1: Eficiência Global -->
    <div class="card p-4 relative overflow-hidden border-l-4 <?= $eficienciaGlobal >= 80 ? 'border-l-[#16a34a]' : ($eficienciaGlobal >= 60 ? 'border-l-[#d97706]' : 'border-l-[#dc2626]') ?>">
        <div class="flex justify-between items-start">
            <div>
                <span class="text-xs font-semibold text-[#5a6480] uppercase tracking-wider">Eficiência Global</span>
                <div class="text-2xl font-bold font-mono text-[#1a2133] mt-1">
                    <?= number_format($eficienciaGlobal, 1, ',', '.') ?>%
                </div>
            </div>
            <div class="p-2.5 rounded-lg <?= $eficienciaGlobal >= 80 ? 'bg-emerald-50 text-[#16a34a]' : 'bg-amber-50 text-[#d97706]' ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
            </div>
        </div>
        <div class="mt-3 flex items-center gap-2 text-xs">
            <?= $eficienciaGlobal >= 80 ? '<span class="badge badge-success">[DENTRO DO PADRÃO]</span>' : ($eficienciaGlobal >= 60 ? '<span class="badge badge-warning">[DESVIO MODERADO]</span>' : '<span class="badge badge-danger">[GARGALO CRÍTICO]</span>') ?>
            <span class="text-[#9aa3b8]">Meta: 80.0%</span>
        </div>
    </div>

    <!-- KPI 2: Volume Total Fabricado -->
    <div class="card p-4 relative overflow-hidden border-l-4 border-l-[#2563eb]">
        <div class="flex justify-between items-start">
            <div>
                <span class="text-xs font-semibold text-[#5a6480] uppercase tracking-wider">Volume Fabricado</span>
                <div class="text-2xl font-bold font-mono text-[#1a2133] mt-1">
                    <?= number_format($volumeTotalPecas, 0, ',', '.') ?>
                </div>
            </div>
            <div class="p-2.5 rounded-lg bg-blue-50 text-[#2563eb]">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
            </div>
        </div>
        <div class="mt-3 text-xs text-[#5a6480]">
            Apontados em <strong><?= $totalTurnos ?> turnos</strong> registrados
        </div>
    </div>

    <!-- KPI 3: Tempo Total Produzido -->
    <div class="card p-4 relative overflow-hidden border-l-4 border-l-[#16a34a]">
        <div class="flex justify-between items-start">
            <div>
                <span class="text-xs font-semibold text-[#5a6480] uppercase tracking-wider">Horas Produtivas</span>
                <div class="text-2xl font-bold font-mono text-emerald-800 mt-1">
                    <?= formatarMinutosHoras($totalProd) ?>
                </div>
            </div>
            <div class="p-2.5 rounded-lg bg-emerald-50 text-[#16a34a]">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
        </div>
        <div class="mt-3 text-xs text-[#5a6480]">
            De um total de <strong><?= formatarMinutosHoras($totalDisp) ?></strong> disponíveis
        </div>
    </div>

    <!-- KPI 4: Perdas por Paradas -->
    <div class="card p-4 relative overflow-hidden border-l-4 border-l-[#dc2626]">
        <div class="flex justify-between items-start">
            <div>
                <span class="text-xs font-semibold text-[#5a6480] uppercase tracking-wider">Tempo em Paradas</span>
                <div class="text-2xl font-bold font-mono text-red-800 mt-1">
                    <?= formatarMinutosHoras($totalParadas) ?>
                </div>
            </div>
            <div class="p-2.5 rounded-lg bg-red-50 text-[#dc2626]">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
            </div>
        </div>
        <div class="mt-3 text-xs text-[#5a6480]">
            Impacto de <strong><?= $totalDisp > 0 ? number_format(($totalParadas / $totalDisp) * 100, 1) : 0 ?>%</strong> no tempo total
        </div>
    </div>

</div>

<!-- Resumo Executivo Automático -->
<div class="card mb-6 p-4 bg-[#f8f9fb] border-l-4 border-l-[#e8a020]">
    <h3 class="text-xs font-bold uppercase tracking-wider text-[#1a3d2a] mb-1 flex items-center gap-1.5">
        <svg class="w-4 h-4 text-[#e8a020]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        Resumo Executivo de Cronoanálise
    </h3>
    <p class="text-xs text-[#5a6480] leading-relaxed">
        No período selecionado, a eficiência operacional apurada é de <strong><?= number_format($eficienciaGlobal, 1, ',', '.') ?>%</strong>.
        Foram contabilizados <strong><?= number_format($totalDisp / 60, 1) ?> horas</strong> de capacidade instalada com <strong><?= number_format($totalProd / 60, 1) ?> horas</strong> de tempo padrão produzido e <strong><?= number_format($totalParadas / 60, 1) ?> horas</strong> retidas em paradas de linha.
        <?php if (!empty($topGargalos)): ?>
            O principal gargalo registrado foi <strong><?= e($topGargalos[0]['descricao']) ?></strong> acumulando <strong><?= formatarMinutosHoras($topGargalos[0]['total_min']) ?></strong>.
        <?php endif; ?>
    </p>
</div>

<!-- Linha de Gráficos OEE e Histograma (2 Colunas) -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    
    <!-- Painel OEE & Rosca de Tempos (1 Coluna) -->
    <div class="card p-4 space-y-4">
        <div class="pb-3 border-b border-[#e2e6ed] flex items-center justify-between">
            <h3 class="text-sm font-bold text-[#1a2133]">Distribuição de Tempos (OEE)</h3>
            <span class="text-xs text-[#9aa3b8] font-mono">Horas de Turno</span>
        </div>

        <div class="relative h-48 flex items-center justify-center">
            <canvas id="chartOeeRosca"></canvas>
        </div>

        <div class="grid grid-cols-3 gap-2 pt-2 border-t border-[#e2e6ed] text-center">
            <div class="p-2 bg-emerald-50 rounded-lg">
                <span class="text-[10px] uppercase font-bold text-emerald-800">Produtivo</span>
                <div class="text-xs font-mono font-bold text-emerald-900 mt-0.5"><?= round($totalProd) ?>m</div>
            </div>
            <div class="p-2 bg-amber-50 rounded-lg">
                <span class="text-[10px] uppercase font-bold text-amber-800">Paradas</span>
                <div class="text-xs font-mono font-bold text-amber-900 mt-0.5"><?= round($totalParadas) ?>m</div>
            </div>
            <div class="p-2 bg-slate-100 rounded-lg">
                <span class="text-[10px] uppercase font-bold text-slate-700">Residual</span>
                <div class="text-xs font-mono font-bold text-slate-800 mt-0.5"><?= round($tempoResidualMin) ?>m</div>
            </div>
        </div>
    </div>

    <!-- Histograma de Eficiência por Operador (2 Colunas) -->
    <div class="card p-4 lg:col-span-2 space-y-4">
        <div class="pb-3 border-b border-[#e2e6ed] flex items-center justify-between">
            <h3 class="text-sm font-bold text-[#1a2133]">Eficiência por Operador (Top Ranking)</h3>
            <span class="text-xs text-[#9aa3b8] font-mono">Meta: 80%</span>
        </div>

        <div class="relative h-60">
            <?php if (empty($operadoresRank)): ?>
                <div class="flex items-center justify-center h-full text-xs text-[#9aa3b8]">
                    Nenhum dado de operador no período filtrado.
                </div>
            <?php else: ?>
                <canvas id="chartOperadores"></canvas>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- Gráfico Diário de Horas (Linha Completa) -->
<div class="card p-4 mb-6 space-y-4">
    <div class="pb-3 border-b border-[#e2e6ed] flex items-center justify-between">
        <h3 class="text-sm font-bold text-[#1a2133]">Evolução Diária de Horas (Produtivas × Disponíveis × Paradas)</h3>
        <span class="text-xs text-[#9aa3b8] font-mono">Tempo em Horas</span>
    </div>

    <div class="relative h-64">
        <?php if (empty($diasRows)): ?>
            <div class="flex items-center justify-center h-full text-xs text-[#9aa3b8]">
                Nenhum dado temporal no período selecionado.
            </div>
        <?php else: ?>
            <canvas id="chartDiario"></canvas>
        <?php endif; ?>
    </div>
</div>

<!-- Tabela Comparativa de Desempenho por Setor -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    
    <div class="card p-4 lg:col-span-2 space-y-4">
        <div class="pb-3 border-b border-[#e2e6ed] flex items-center justify-between">
            <h3 class="text-sm font-bold text-[#1a2133]">Comparativo por Setor Fabril</h3>
            <span class="text-xs text-[#9aa3b8] font-mono"><?= count($tabelaSetores) ?> centros de trabalho</span>
        </div>

        <div class="overflow-x-auto border border-[#e2e6ed] rounded-lg">
            <table class="w-full text-left text-xs">
                <thead class="bg-[#f8f9fb] text-[#5a6480] border-b border-[#e2e6ed]">
                    <tr>
                        <th class="py-2.5 px-3">Setor</th>
                        <th class="py-2.5 px-3 text-right">Disponível</th>
                        <th class="py-2.5 px-3 text-right">Produzido</th>
                        <th class="py-2.5 px-3 text-right">Meta</th>
                        <th class="py-2.5 px-3 text-right">Eficiência</th>
                        <th class="py-2.5 px-3 text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#e2e6ed]">
                    <?php if (empty($tabelaSetores)): ?>
                        <tr><td colspan="6" class="text-center py-4 text-[#9aa3b8]">Nenhum setor cadastrado.</td></tr>
                    <?php else: foreach ($tabelaSetores as $st): 
                        $dispSt = (float) $st['disp'];
                        $prodSt = (float) $st['prod'];
                        $metaSt = (float) $st['meta'];
                        $efSt = $dispSt > 0 ? round(($prodSt / $dispSt) * 100, 1) : 0.0;
                    ?>
                        <tr class="hover:bg-[#f8f9fb] transition">
                            <td class="py-2 px-3 font-semibold text-[#1a2133]">
                                <?= e($st['cod']) ?> <span class="font-normal text-[#5a6480]">— <?= e($st['descricao']) ?></span>
                            </td>
                            <td class="text-right font-mono text-[#5a6480]">
                                <?= formatarMinutosHoras($dispSt) ?>
                            </td>
                            <td class="text-right font-mono font-bold text-emerald-700">
                                <?= formatarMinutosHoras($prodSt) ?>
                            </td>
                            <td class="text-right font-mono text-[#5a6480]">
                                <?= number_format($metaSt, 1) ?>%
                            </td>
                            <td class="text-right font-mono font-bold <?= $efSt >= $metaSt ? 'text-[#16a34a]' : ($efSt >= 60 ? 'text-[#d97706]' : 'text-[#dc2626]') ?>">
                                <?= number_format($efSt, 1, ',', '.') ?>%
                            </td>
                            <td class="text-center">
                                <?= $dispSt === 0.0 ? '<span class="badge badge-neutral">Sem Dados</span>' : ($efSt >= $metaSt ? '<span class="badge badge-success">[DENTRO DO PADRÃO]</span>' : ($efSt >= 60 ? '<span class="badge badge-warning">[DESVIO MODERADO]</span>' : '<span class="badge badge-danger">[GARGALO CRÍTICO]</span>')) ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Ganhos e Alertas & Projeção (1 Coluna) -->
    <div class="card p-4 space-y-4 flex flex-col justify-between">
        <div>
            <div class="pb-3 border-b border-[#e2e6ed] flex items-center justify-between">
                <h3 class="text-sm font-bold text-[#1a2133]">Ganhos e Alertas</h3>
                <span class="badge badge-warning">Cronoanálise</span>
            </div>

            <div class="py-3 space-y-3 text-xs leading-relaxed">
                <div class="p-3 bg-emerald-50 border border-emerald-200 rounded-lg text-emerald-900">
                    <div class="font-bold flex items-center gap-1 mb-1">
                        <svg class="w-3.5 h-3.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Oportunidade de Ganho
                    </div>
                    <span>Garantir abastecimento prévio de materiais no início dos turnos para reduzir o tempo de setup e espera.</span>
                </div>

                <div class="p-3 bg-red-50 border border-red-200 rounded-lg text-red-900">
                    <div class="font-bold flex items-center gap-1 mb-1">
                        <svg class="w-3.5 h-3.5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        Alerta de Produtividade
                    </div>
                    <span>Postos com desvio moderado devem ser auditados quanto ao fator de fadiga do operador e calibragem de máquina.</span>
                </div>
            </div>
        </div>

        <div class="p-3 bg-[#f8f9fb] border border-[#e2e6ed] rounded-xl">
            <div class="text-[11px] font-bold text-[#1a3d2a] uppercase tracking-wider mb-1">Projeção de Entrega</div>
            <div class="text-xs text-[#5a6480]">
                Estimativa de conclusão: <strong>+<?= round($volumeTotalPecas * 0.15) ?> peças</strong> projetadas caso mantido o ritmo padrão de <?= number_format($eficienciaGlobal, 0) ?>%.
            </div>
        </div>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // 1. Gráfico de Rosca OEE
    const ctxOee = document.getElementById('chartOeeRosca')?.getContext('2d');
    if (ctxOee) {
        new Chart(ctxOee, {
            type: 'doughnut',
            data: {
                labels: ['Produtivo', 'Paradas', 'Desvio/Residual'],
                datasets: [{
                    data: [
                        <?= round($totalProd) ?>,
                        <?= round($totalParadas) ?>,
                        <?= round($tempoResidualMin) ?>
                    ],
                    backgroundColor: ['#16a34a', '#d97706', '#e2e6ed'],
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '72%',
                plugins: {
                    legend: { display: false }
                }
            }
        });
    }

    // 2. Histograma por Operador
    const ctxOp = document.getElementById('chartOperadores')?.getContext('2d');
    if (ctxOp) {
        new Chart(ctxOp, {
            type: 'bar',
            data: {
                labels: <?= json_encode($opNomes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                datasets: [{
                    label: 'Eficiência (%)',
                    data: <?= json_encode($opEficiencias) ?>,
                    backgroundColor: <?= json_encode($opCores) ?>,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 120,
                        ticks: { callback: v => v + '%' }
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

    // 3. Gráfico Diário de Horas
    const ctxDiario = document.getElementById('chartDiario')?.getContext('2d');
    if (ctxDiario) {
        new Chart(ctxDiario, {
            type: 'line',
            data: {
                labels: <?= json_encode($diasLabels) ?>,
                datasets: [
                    {
                        label: 'Horas Produtivas',
                        data: <?= json_encode($diasProd) ?>,
                        borderColor: '#16a34a',
                        backgroundColor: 'rgba(22, 163, 74, 0.1)',
                        fill: true,
                        tension: 0.3
                    },
                    {
                        label: 'Horas Disponíveis',
                        data: <?= json_encode($diasDisp) ?>,
                        borderColor: '#2563eb',
                        borderDash: [5, 5],
                        fill: false,
                        tension: 0.1
                    },
                    {
                        label: 'Horas Paradas',
                        data: <?= json_encode($diasParadas) ?>,
                        borderColor: '#dc2626',
                        fill: false,
                        tension: 0.3
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { callback: v => v + 'h' }
                    }
                },
                plugins: {
                    legend: { position: 'top', labels: { boxWidth: 12, font: { size: 11 } } }
                }
            }
        });
    }
});
</script>

<?php
layoutFooter();
