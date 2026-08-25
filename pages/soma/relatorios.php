<?php
declare(strict_types=1);

/**
 * SOMA — Relatórios Executivos de Cronoanálise e OEE
 */

require_once dirname(__DIR__, 2) . '/config/conexao.php';
require_once dirname(__DIR__, 2) . '/config/session.php';
require_once dirname(__DIR__, 2) . '/includes/helpers.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

requireLogin();

$db = getDB();
$usuario = currentUser();

// 1. Parâmetros de Filtro
$mes = trim((string) ($_GET['mes'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
    $mes = date('Y-m');
}

$dataInicio = $mes . '-01';
$dataFim = date('Y-m-t', strtotime($dataInicio));

$idEmpresa = !empty($_GET['id_empresa']) ? (int) $_GET['id_empresa'] : null;
$turnoFiltro = trim((string) ($_GET['turno'] ?? ''));
$idSetor = !empty($_GET['id_setor']) ? (int) $_GET['id_setor'] : null;

// Metas Editáveis
$metaUtilizacao = isset($_GET['meta_utilizacao']) ? (float) str_replace(',', '.', (string) $_GET['meta_utilizacao']) : 80.0;
$metaEficiencia = isset($_GET['meta_eficiencia']) ? (float) str_replace(',', '.', (string) $_GET['meta_eficiencia']) : 80.0;
$metaProdutividade = isset($_GET['meta_produtividade']) ? (float) str_replace(',', '.', (string) $_GET['meta_produtividade']) : round(($metaUtilizacao * $metaEficiencia) / 100, 1);

// 2. WHERE Clause
$where = ['t.deleted_at IS NULL', 't.data >= :data_inicio', 't.data <= :data_fim'];
$params = ['data_inicio' => $dataInicio, 'data_fim' => $dataFim];

if ($idEmpresa) {
    $where[] = 't.id_empresa = :id_empresa';
    $params['id_empresa'] = $idEmpresa;
}
if ($turnoFiltro !== '') {
    $where[] = 't.turno = :turno';
    $params['turno'] = $turnoFiltro;
}
if ($idSetor) {
    $where[] = 't.id_setor = :id_setor';
    $params['id_setor'] = $idSetor;
}

$whereSql = implode(' AND ', $where);

// ========================================================
// 3. EXPORTAÇÃO CSV
// ========================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmtCsv = $db->prepare("
        SELECT 
            t.id, t.data, t.turno,
            emp.nome AS empresa_nome,
            st.descricao AS setor_nome,
            op.nome AS operador_nome,
            maq.nome AS maquina_nome,
            t.minutos_disponiveis,
            t.minutos_produzidos,
            t.minutos_paradas,
            t.eficiencia,
            t.status
        FROM soma_turnos t
        LEFT JOIN soma_empresas emp ON emp.id = t.id_empresa
        LEFT JOIN soma_setores st ON st.id = t.id_setor
        LEFT JOIN soma_operadores op ON op.id = t.id_operador
        LEFT JOIN soma_maquinas maq ON maq.id = t.id_maquina
        WHERE {$whereSql}
        ORDER BY t.data ASC, t.id ASC
    ");
    $stmtCsv->execute($params);
    $linhasCsv = $stmtCsv->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="relatorio_cronoanalise_' . $mes . '.csv"');

    $saida = fopen('php://output', 'w');
    // UTF-8 BOM para Excel
    fprintf($saida, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($saida, [
        'ID Turno', 'Data', 'Turno', 'Empresa', 'Setor', 'Operador', 'Máquina',
        'Tempo Disponível (min)', 'Tempo Produzido (min)', 'Tempo Paradas (min)', 'Eficiência (%)', 'Status Cronoanálise'
    ], ';');

    foreach ($linhasCsv as $l) {
        fputcsv($saida, [
            $l['id'],
            $l['data'],
            $l['turno'],
            $l['empresa_nome'] ?? 'Trael',
            $l['setor_nome'] ?? '—',
            $l['operador_nome'] ?? '—',
            $l['maquina_nome'] ?? '—',
            number_format((float) $l['minutos_disponiveis'], 1, ',', ''),
            number_format((float) $l['minutos_produzidos'], 1, ',', ''),
            number_format((float) $l['minutos_paradas'], 1, ',', ''),
            number_format((float) $l['eficiencia'], 1, ',', ''),
            $l['status']
        ], ';');
    }

    fclose($saida);
    exit;
}

// 4. Totais Gerais do Mês
$stmtTot = $db->prepare("
    SELECT 
        COUNT(t.id) AS total_turnos,
        COALESCE(SUM(t.minutos_disponiveis), 0) AS total_disp,
        COALESCE(SUM(t.minutos_produzidos), 0)  AS total_prod,
        COALESCE(SUM(t.minutos_paradas), 0)     AS total_paradas,
        COALESCE(AVG(t.eficiencia), 0)          AS media_eficiencia
    FROM soma_turnos t
    WHERE {$whereSql}
");
$stmtTot->execute($params);
$totais = $stmtTot->fetch() ?: [];

$totalTurnos = (int) ($totais['total_turnos'] ?? 0);
$totalDisp = (float) ($totais['total_disp'] ?? 0.0);
$totalProd = (float) ($totais['total_prod'] ?? 0.0);
$totalParadas = (float) ($totais['total_paradas'] ?? 0.0);

// Métricas de OEE Real
$utilizacaoReal = $totalDisp > 0 ? round((($totalDisp - $totalParadas) / $totalDisp) * 100, 1) : 0.0;
$eficienciaReal = $totalDisp > 0 ? round(($totalProd / $totalDisp) * 100, 1) : 0.0;
$produtividadeReal = round(($utilizacaoReal * $eficienciaReal) / 100, 1);

// 5. Volume Total de Peças no Mês
$stmtVol = $db->prepare("
    SELECT COALESCE(SUM(rp.qtd), 0) AS volume_mes
    FROM soma_registros_producao rp
    INNER JOIN soma_turnos t ON t.id = rp.id_turno
    WHERE {$whereSql} AND rp.deleted_at IS NULL
");
$stmtVol->execute($params);
$volumeTotalMes = (int) ($stmtVol->fetchColumn() ?: 0);

// 6. Dados Agrupados por Setor
$stmtSetores = $db->prepare("
    SELECT 
        st.id, st.cod, st.descricao, st.meta,
        COUNT(t.id) AS total_turnos,
        COALESCE(SUM(t.minutos_disponiveis), 0) AS min_disp,
        COALESCE(SUM(t.minutos_produzidos), 0)  AS min_prod,
        COALESCE(SUM(t.minutos_paradas), 0)     AS min_parada
    FROM soma_setores st
    LEFT JOIN soma_turnos t ON t.id_setor = st.id AND {$whereSql}
    WHERE st.deleted_at IS NULL
    GROUP BY st.id, st.cod, st.descricao, st.meta
    ORDER BY st.descricao ASC
");
$stmtSetores->execute($params);
$setoresDados = $stmtSetores->fetchAll();

// 7. Principais Paradas do Período
$stmtTopParadas = $db->prepare("
    SELECT 
        m.id, m.cod, m.descricao, m.tipo,
        COALESCE(SUM(rp.duracao_minutos), 0) AS total_minutos,
        COUNT(rp.id) AS total_ocorrencias,
        COALESCE(AVG(rp.duracao_minutos), 0) AS media_minutos
    FROM soma_registros_paradas rp
    INNER JOIN soma_turnos t ON t.id = rp.id_turno
    INNER JOIN soma_paradas_motivos m ON m.id = rp.id_motivo
    WHERE {$whereSql} AND rp.deleted_at IS NULL
    GROUP BY m.id, m.cod, m.descricao, m.tipo
    ORDER BY total_minutos DESC
    LIMIT 6
");
$stmtTopParadas->execute($params);
$topParadas = $stmtTopParadas->fetchAll();

// 8. Desempenho por Operador
$stmtOpRel = $db->prepare("
    SELECT 
        op.id, op.cod, op.nome,
        COUNT(t.id) AS total_turnos,
        COALESCE(SUM(t.minutos_disponiveis), 0) AS min_disp,
        COALESCE(SUM(t.minutos_produzidos), 0)  AS min_prod,
        COALESCE(SUM(t.minutos_paradas), 0)     AS min_parada,
        (SELECT COALESCE(SUM(qtd), 0) FROM soma_registros_producao WHERE id_turno IN (SELECT id FROM soma_turnos WHERE id_operador = op.id AND {$whereSql})) AS volume_op
    FROM soma_operadores op
    INNER JOIN soma_turnos t ON t.id_operador = op.id AND {$whereSql}
    WHERE op.deleted_at IS NULL
    GROUP BY op.id, op.cod, op.nome
    ORDER BY min_prod DESC
");
$stmtOpRel->execute($params);
$operadoresRelatorio = $stmtOpRel->fetchAll();

// Cadastros para Filtro
$listaEmpresas = $db->query('SELECT id, cod, nome FROM soma_empresas WHERE deleted_at IS NULL ORDER BY nome ASC')->fetchAll();
$listaSetores = $db->query('SELECT id, cod, descricao FROM soma_setores WHERE deleted_at IS NULL ORDER BY descricao ASC')->fetchAll();

layoutHeader('SOMA — Relatórios Executivos de Cronoanálise', 'soma');

$somaAbaAtual = 'relatorios';
require dirname(__DIR__, 2) . '/includes/soma-subnav.php';
?>

<!-- Filtros e Metas Editáveis do Relatório -->
<div class="card mb-6 p-4">
    <form method="GET" action="" class="space-y-4">
        
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <div>
                <label class="block text-xs font-semibold text-[#5a6480] mb-1">Mês de Referência *</label>
                <input type="month" name="mes" value="<?= e($mes) ?>" required class="form-input text-xs font-semibold text-[#1a2133]" onchange="this.form.submit()">
            </div>

            <div>
                <label class="block text-xs font-semibold text-[#5a6480] mb-1">Empresa / Unidade</label>
                <select name="id_empresa" class="form-input text-xs">
                    <option value="">Todas as Unidades</option>
                    <?php foreach ($listaEmpresas as $emp): ?>
                        <option value="<?= (int) $emp['id'] ?>" <?= $idEmpresa === (int) $emp['id'] ? 'selected' : '' ?>>
                            <?= e($emp['cod'] . ' - ' . $emp['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-[#5a6480] mb-1">Setor Fabril</label>
                <select name="id_setor" class="form-input text-xs">
                    <option value="">Todos os Setores</option>
                    <?php foreach ($listaSetores as $st): ?>
                        <option value="<?= (int) $st['id'] ?>" <?= $idSetor === (int) $st['id'] ? 'selected' : '' ?>>
                            <?= e($st['cod'] . ' - ' . $st['descricao']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-[#5a6480] mb-1">Turno</label>
                <select name="turno" class="form-input text-xs">
                    <option value="">Todos os Turnos</option>
                    <option value="D" <?= $turnoFiltro === 'D' ? 'selected' : '' ?>>Diurno (1º Turno)</option>
                    <option value="N" <?= $turnoFiltro === 'N' ? 'selected' : '' ?>>Noturno (2º Turno)</option>
                    <option value="M" <?= $turnoFiltro === 'M' ? 'selected' : '' ?>>Misto / Especial</option>
                </select>
            </div>
        </div>

        <!-- Parâmetros de Metas Editáveis -->
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 pt-3 border-t border-[#e2e6ed] items-end">
            <div>
                <label class="block text-xs font-semibold text-[#5a6480] mb-1">Meta Utilização (%)</label>
                <input type="number" step="0.5" name="meta_utilizacao" value="<?= number_format($metaUtilizacao, 1, '.', '') ?>" class="form-input text-xs font-mono">
            </div>

            <div>
                <label class="block text-xs font-semibold text-[#5a6480] mb-1">Meta Eficiência (%)</label>
                <input type="number" step="0.5" name="meta_eficiencia" value="<?= number_format($metaEficiencia, 1, '.', '') ?>" class="form-input text-xs font-mono">
            </div>

            <div class="flex gap-2 lg:col-span-2">
                <button type="submit" class="btn btn-primary text-xs w-full justify-center">
                    <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Atualizar Relatório
                </button>
                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-outline text-xs px-3 whitespace-nowrap" title="Exportar dados para Excel/CSV">
                    📥 CSV
                </a>
                <button type="button" onclick="window.print()" class="btn btn-neutral text-xs px-3 whitespace-nowrap" title="Imprimir Relatório">
                    🖨️ Imprimir
                </button>
            </div>
        </div>

    </form>
</div>

<!-- Painel de Índices OEE Consolidados do Mês -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    
    <!-- Utilização -->
    <div class="card p-4 border-l-4 <?= $utilizacaoReal >= $metaUtilizacao ? 'border-l-[#16a34a]' : 'border-l-[#d97706]' ?>">
        <span class="text-[10px] font-bold uppercase text-[#5a6480] tracking-wider">Índice de Utilização</span>
        <div class="text-3xl font-mono font-bold <?= $utilizacaoReal >= $metaUtilizacao ? 'text-[#16a34a]' : 'text-[#d97706]' ?> mt-1">
            <?= number_format($utilizacaoReal, 1, ',', '.') ?>%
        </div>
        <div class="mt-2 text-xs flex justify-between items-center text-[#5a6480]">
            <span>Meta: <?= number_format($metaUtilizacao, 1) ?>%</span>
            <span class="font-semibold <?= $utilizacaoReal >= $metaUtilizacao ? 'text-emerald-700' : 'text-amber-700' ?>">
                <?= ($utilizacaoReal >= $metaUtilizacao ? '+' : '') . number_format($utilizacaoReal - $metaUtilizacao, 1) ?>%
            </span>
        </div>
    </div>

    <!-- Eficiência -->
    <div class="card p-4 border-l-4 <?= $eficienciaReal >= $metaEficiencia ? 'border-l-[#16a34a]' : 'border-l-[#dc2626]' ?>">
        <span class="text-[10px] font-bold uppercase text-[#5a6480] tracking-wider">Índice de Eficiência</span>
        <div class="text-3xl font-mono font-bold <?= $eficienciaReal >= $metaEficiencia ? 'text-[#16a34a]' : 'text-[#dc2626]' ?> mt-1">
            <?= number_format($eficienciaReal, 1, ',', '.') ?>%
        </div>
        <div class="mt-2 text-xs flex justify-between items-center text-[#5a6480]">
            <span>Meta: <?= number_format($metaEficiencia, 1) ?>%</span>
            <span class="font-semibold <?= $eficienciaReal >= $metaEficiencia ? 'text-emerald-700' : 'text-red-700' ?>">
                <?= ($eficienciaReal >= $metaEficiencia ? '+' : '') . number_format($eficienciaReal - $metaEficiencia, 1) ?>%
            </span>
        </div>
    </div>

    <!-- Produtividade Global -->
    <div class="card p-4 border-l-4 <?= $produtividadeReal >= $metaProdutividade ? 'border-l-[#16a34a]' : 'border-l-[#2563eb]' ?>">
        <span class="text-[10px] font-bold uppercase text-[#5a6480] tracking-wider">Produtividade Global (OEE)</span>
        <div class="text-3xl font-mono font-bold text-[#1a2133] mt-1">
            <?= number_format($produtividadeReal, 1, ',', '.') ?>%
        </div>
        <div class="mt-2 text-xs flex justify-between items-center text-[#5a6480]">
            <span>Meta: <?= number_format($metaProdutividade, 1) ?>%</span>
            <span class="font-semibold text-blue-700">Util × Efic</span>
        </div>
    </div>

    <!-- Volume Mensal -->
    <div class="card p-4 border-l-4 border-l-[#1a3d2a]">
        <span class="text-[10px] font-bold uppercase text-[#5a6480] tracking-wider">Volume Fabricado</span>
        <div class="text-3xl font-mono font-bold text-emerald-800 mt-1">
            <?= number_format($volumeTotalMes, 0, ',', '.') ?>
        </div>
        <div class="mt-2 text-xs text-[#5a6480]">
            Em <strong><?= $totalTurnos ?> turnos</strong> no mês
        </div>
    </div>

</div>

<!-- 1. Tabela de Desempenho por Setor Fabril -->
<div class="card p-4 mb-6 space-y-4">
    <div class="pb-3 border-b border-[#e2e6ed] flex items-center justify-between">
        <div>
            <h3 class="text-sm font-bold text-[#1a2133]">1. Desempenho por Centro de Trabalho / Setor</h3>
            <p class="text-[11px] text-[#5a6480]">Apuração consolidada de tempo disponível, produzido e eficiência por célula fabril.</p>
        </div>
        <span class="badge badge-neutral text-xs font-mono"><?= count($setoresDados) ?> setores</span>
    </div>

    <div class="overflow-x-auto border border-[#e2e6ed] rounded-lg">
        <table class="w-full text-left text-xs">
            <thead class="bg-[#f8f9fb] text-[#5a6480] border-b border-[#e2e6ed]">
                <tr>
                    <th class="py-2.5 px-3">Setor Fabril</th>
                    <th class="py-2.5 px-3 text-center">Turnos</th>
                    <th class="py-2.5 px-3 text-right">Disponível</th>
                    <th class="py-2.5 px-3 text-right">Produzido</th>
                    <th class="py-2.5 px-3 text-right">Paradas</th>
                    <th class="py-2.5 px-3 text-right">Meta</th>
                    <th class="py-2.5 px-3 text-right">Eficiência</th>
                    <th class="py-2.5 px-3 text-center">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#e2e6ed]">
                <?php if (empty($setoresDados)): ?>
                    <tr><td colspan="8" class="text-center py-4 text-[#9aa3b8]">Sem registros no período.</td></tr>
                <?php else: foreach ($setoresDados as $st): 
                    $dispSt = (float) $st['min_disp'];
                    $prodSt = (float) $st['min_prod'];
                    $parSt = (float) $st['min_parada'];
                    $metaSt = (float) $st['meta'];
                    $efSt = $dispSt > 0 ? ($prodSt / $dispSt) * 100 : 0.0;
                ?>
                    <tr class="hover:bg-[#f8f9fb] transition">
                        <td class="py-2 px-3">
                            <div class="font-bold text-[#1a2133]"><?= e($st['descricao']) ?></div>
                            <div class="text-[10px] text-[#9aa3b8] font-mono"><?= e($st['cod']) ?></div>
                        </td>
                        <td class="py-2 px-3 text-center font-mono"><?= (int) $st['total_turnos'] ?></td>
                        <td class="py-2 px-3 text-right font-mono text-[#5a6480]"><?= formatarMinutosHoras($dispSt) ?></td>
                        <td class="py-2 px-3 text-right font-mono text-emerald-700 font-semibold"><?= formatarMinutosHoras($prodSt) ?></td>
                        <td class="py-2 px-3 text-right font-mono text-amber-700"><?= formatarMinutosHoras($parSt) ?></td>
                        <td class="py-2 px-3 text-right font-mono text-[#5a6480]"><?= number_format($metaSt, 1) ?>%</td>
                        <td class="py-2 px-3 text-right font-mono font-bold <?= $efSt >= $metaSt ? 'text-[#16a34a]' : ($efSt >= 60 ? 'text-[#d97706]' : 'text-[#dc2626]') ?>">
                            <?= number_format($efSt, 1, ',', '.') ?>%
                        </td>
                        <td class="py-2 px-3 text-center">
                            <?= $dispSt === 0.0 ? '<span class="badge badge-neutral">Sem Dados</span>' : ($efSt >= $metaSt ? '<span class="badge badge-success">[DENTRO DO PADRÃO]</span>' : ($efSt >= 60 ? '<span class="badge badge-warning">[DESVIO MODERADO]</span>' : '<span class="badge badge-danger">[GARGALO CRÍTICO]</span>')) ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 2. Tabela de Principais Ofensores de Parada -->
<div class="card p-4 mb-6 space-y-4">
    <div class="pb-3 border-b border-[#e2e6ed] flex items-center justify-between">
        <div>
            <h3 class="text-sm font-bold text-[#1a2133]">2. Diagnóstico de Principais Paradas Industriais</h3>
            <p class="text-[11px] text-[#5a6480]">Ranking dos motivos com maior incidência de tempo improdutivo no período.</p>
        </div>
        <span class="badge badge-neutral text-xs font-mono">Top Ofensores</span>
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
                <?php if (empty($topParadas)): ?>
                    <tr><td colspan="7" class="text-center py-4 text-[#9aa3b8]">Nenhuma parada registrada.</td></tr>
                <?php else: foreach ($topParadas as $tp): 
                    $minP = (float) $tp['total_minutos'];
                    $pctP = $totalParadas > 0 ? ($minP / $totalParadas) * 100 : 0.0;
                ?>
                    <tr class="hover:bg-[#f8f9fb] transition">
                        <td class="py-2 px-3 font-mono font-bold text-[#1a2133]"><?= e($tp['cod']) ?></td>
                        <td class="py-2 px-3 font-medium text-[#1a2133]"><?= e($tp['descricao']) ?></td>
                        <td class="py-2 px-3 text-center">
                            <?= $tp['tipo'] === 'PROG' 
                                ? '<span class="badge badge-info text-[10px]">Programada</span>' 
                                : '<span class="badge badge-danger text-[10px]">Não Programada</span>' ?>
                        </td>
                        <td class="py-2 px-3 text-right font-mono font-bold <?= $tp['tipo'] === 'NAO_PROG' ? 'text-red-700' : 'text-blue-700' ?>">
                            <?= formatarMinutosHoras($minP) ?>
                        </td>
                        <td class="py-2 px-3 text-right font-mono text-[#5a6480]"><?= number_format($pctP, 1) ?>%</td>
                        <td class="py-2 px-3 text-center font-mono font-semibold"><?= (int) $tp['total_ocorrencias'] ?></td>
                        <td class="py-2 px-3 text-right font-mono text-[#5a6480]"><?= number_format((float) $tp['media_minutos'], 1) ?> min</td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 3. Tabela de Desempenho por Operador -->
<div class="card p-4 mb-6 space-y-4">
    <div class="pb-3 border-b border-[#e2e6ed] flex items-center justify-between">
        <div>
            <h3 class="text-sm font-bold text-[#1a2133]">3. Desempenho Individual de Operadores</h3>
            <p class="text-[11px] text-[#5a6480]">Consolidação de volume e eficiência por colaborador no mês de referência.</p>
        </div>
        <span class="badge badge-neutral text-xs font-mono"><?= count($operadoresRelatorio) ?> operadores ativos</span>
    </div>

    <div class="overflow-x-auto border border-[#e2e6ed] rounded-lg">
        <table class="w-full text-left text-xs">
            <thead class="bg-[#f8f9fb] text-[#5a6480] border-b border-[#e2e6ed]">
                <tr>
                    <th class="py-2.5 px-3 w-24">Código</th>
                    <th class="py-2.5 px-3">Nome do Operador</th>
                    <th class="py-2.5 px-3 text-center w-24">Turnos</th>
                    <th class="py-2.5 px-3 text-right w-28">Volume Fabricado</th>
                    <th class="py-2.5 px-3 text-right w-32">Tempo Produtivo</th>
                    <th class="py-2.5 px-3 text-right w-28">Eficiência Real</th>
                    <th class="py-2.5 px-3 text-center w-36">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#e2e6ed]">
                <?php if (empty($operadoresRelatorio)): ?>
                    <tr><td colspan="7" class="text-center py-4 text-[#9aa3b8]">Nenhum operador com apontamento no período.</td></tr>
                <?php else: foreach ($operadoresRelatorio as $op): 
                    $dispOp = (float) $op['min_disp'];
                    $prodOp = (float) $op['min_prod'];
                    $efOp = $dispOp > 0 ? ($prodOp / $dispOp) * 100 : 0.0;
                ?>
                    <tr class="hover:bg-[#f8f9fb] transition">
                        <td class="py-2 px-3 font-mono font-bold text-[#1a2133]"><?= e($op['cod']) ?></td>
                        <td class="py-2 px-3 font-medium text-[#1a2133]"><?= e($op['nome']) ?></td>
                        <td class="py-2 px-3 text-center font-mono"><?= (int) $op['total_turnos'] ?></td>
                        <td class="py-2 px-3 text-right font-mono font-semibold text-[#1a2133]"><?= number_format((float) $op['volume_op'], 0) ?> pçs</td>
                        <td class="py-2 px-3 text-right font-mono text-emerald-700"><?= formatarMinutosHoras($prodOp) ?></td>
                        <td class="py-2 px-3 text-right font-mono font-bold <?= $efOp >= 80 ? 'text-[#16a34a]' : ($efOp >= 60 ? 'text-[#d97706]' : 'text-[#dc2626]') ?>">
                            <?= number_format($efOp, 1, ',', '.') ?>%
                        </td>
                        <td class="py-2 px-3 text-center">
                            <?= $efOp >= 80 ? '<span class="badge badge-success">[DENTRO DO PADRÃO]</span>' : ($efOp >= 60 ? '<span class="badge badge-warning">[DESVIO MODERADO]</span>' : '<span class="badge badge-danger">[GARGALO CRÍTICO]</span>') ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
layoutFooter();
