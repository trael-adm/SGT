<?php
declare(strict_types=1);

/**
 * SOMA — Base de Dados (Listagem de Registros de Cronoanálise)
 */

require_once dirname(__DIR__, 2) . '/config/conexao.php';
require_once dirname(__DIR__, 2) . '/config/session.php';
require_once dirname(__DIR__, 2) . '/includes/helpers.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

requireLogin();

$db = getDB();
$usuario = currentUser();
$podeExcluir = isAdmin() || in_array((int) ($usuario['id_perfil'] ?? 0), [1, 2, 201, 202, 203, 204, 205, 206, 207, 212], true);

// 1. Filtros de Período
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

// Outros filtros
$idOperador = !empty($_GET['id_operador']) ? (int) $_GET['id_operador'] : null;
$idMaquina = !empty($_GET['id_maquina']) ? (int) $_GET['id_maquina'] : null;
$idSetor = !empty($_GET['id_setor']) ? (int) $_GET['id_setor'] : null;
$idEmpresa = !empty($_GET['id_empresa']) ? (int) $_GET['id_empresa'] : null;
$turnoFiltro = trim((string) ($_GET['turno'] ?? ''));
$statusFiltro = trim((string) ($_GET['status'] ?? ''));

// Paginação
$pagina = max(1, (int) ($_GET['p'] ?? 1));
$limite = 15;
$offset = ($pagina - 1) * $limite;

// 2. Monta Cláusula WHERE
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
if ($idOperador) {
    $where[] = 't.id_operador = :id_operador';
    $params['id_operador'] = $idOperador;
}
if ($idMaquina) {
    $where[] = 't.id_maquina = :id_maquina';
    $params['id_maquina'] = $idMaquina;
}
if ($idSetor) {
    $where[] = 't.id_setor = :id_setor';
    $params['id_setor'] = $idSetor;
}
if ($idEmpresa) {
    $where[] = 't.id_empresa = :id_empresa';
    $params['id_empresa'] = $idEmpresa;
}
if ($turnoFiltro !== '') {
    $where[] = 't.turno = :turno';
    $params['turno'] = $turnoFiltro;
}
if ($statusFiltro !== '') {
    $where[] = 't.status = :status';
    $params['status'] = $statusFiltro;
}

$whereSql = implode(' AND ', $where);

// Contagem total
$stmtCount = $db->prepare("SELECT COUNT(*) AS total, COALESCE(AVG(t.eficiencia), 0) AS media_eficiencia, COALESCE(SUM(t.minutos_produzidos), 0) AS total_prod, COALESCE(SUM(t.minutos_paradas), 0) AS total_paradas FROM soma_turnos t WHERE {$whereSql}");
$stmtCount->execute($params);
$totaisRow = $stmtCount->fetch();
$totalRegistros = (int) ($totaisRow['total'] ?? 0);
$mediaEficiencia = (float) ($totaisRow['media_eficiencia'] ?? 0.0);
$totalMinProd = (float) ($totaisRow['total_prod'] ?? 0.0);
$totalMinPar = (float) ($totaisRow['total_paradas'] ?? 0.0);
$totalPaginas = max(1, (int) ceil($totalRegistros / $limite));

// Consulta dos Registros
$sqlRegistros = "
    SELECT 
        t.*,
        op.nome AS operador_nome, op.cod AS operador_cod,
        maq.nome AS maquina_nome, maq.cod AS maquina_cod,
        st.descricao AS setor_nome, st.cod AS setor_cod, st.meta AS setor_meta,
        emp.nome AS empresa_nome, emp.cod AS empresa_cod,
        u.nome AS criador_nome
    FROM soma_turnos t
    LEFT JOIN soma_operadores op ON op.id = t.id_operador
    LEFT JOIN soma_maquinas maq ON maq.id = t.id_maquina
    LEFT JOIN soma_setores st ON st.id = t.id_setor
    LEFT JOIN soma_empresas emp ON emp.id = t.id_empresa
    LEFT JOIN usuarios u ON u.id = t.id_criador
    WHERE {$whereSql}
    ORDER BY t.data DESC, t.id DESC
    LIMIT {$limite} OFFSET {$offset}
";
$stmtReg = $db->prepare($sqlRegistros);
$stmtReg->execute($params);
$registros = $stmtReg->fetchAll();

// Cadastros para Filtros
$operadores = $db->query('SELECT id, cod, nome FROM soma_operadores WHERE deleted_at IS NULL ORDER BY nome ASC')->fetchAll();
$maquinas = $db->query('SELECT id, cod, nome FROM soma_maquinas WHERE deleted_at IS NULL ORDER BY nome ASC')->fetchAll();
$setores = $db->query('SELECT id, cod, descricao FROM soma_setores WHERE deleted_at IS NULL ORDER BY descricao ASC')->fetchAll();
$empresas = $db->query('SELECT id, cod, nome FROM soma_empresas WHERE deleted_at IS NULL ORDER BY nome ASC')->fetchAll();

layoutHeader('SOMA — Base de Dados de Cronoanálise', 'soma');

$somaAbaAtual = 'registros';
require dirname(__DIR__, 2) . '/includes/soma-subnav.php';
?>

<!-- Filtros de Busca Avançada -->
<div class="card mb-6 p-4">
    <form method="GET" action="" class="space-y-4">
        
        <!-- Linha 1: Período e Datas -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <div>
                <label class="block text-xs font-semibold text-[#5a6480] mb-1">Período Pré-definido</label>
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
                <label class="block text-xs font-semibold text-[#5a6480] mb-1">Empresa / Unidade</label>
                <select name="id_empresa" class="form-input text-xs">
                    <option value="">Todas as Empresas</option>
                    <?php foreach ($empresas as $emp): ?>
                        <option value="<?= (int) $emp['id'] ?>" <?= $idEmpresa === (int) $emp['id'] ? 'selected' : '' ?>>
                            <?= e($emp['cod'] . ' - ' . $emp['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Linha 2: Entidades e Filtros Operacionais -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 pt-2 border-t border-[#e2e6ed] items-end">
            <div>
                <label class="block text-xs font-semibold text-[#5a6480] mb-1">Operador</label>
                <select name="id_operador" class="form-input text-xs">
                    <option value="">Todos os Operadores</option>
                    <?php foreach ($operadores as $op): ?>
                        <option value="<?= (int) $op['id'] ?>" <?= $idOperador === (int) $op['id'] ? 'selected' : '' ?>>
                            <?= e($op['cod'] . ' — ' . $op['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-[#5a6480] mb-1">Máquina / Ativo</label>
                <select name="id_maquina" class="form-input text-xs">
                    <option value="">Todas as Máquinas</option>
                    <?php foreach ($maquinas as $maq): ?>
                        <option value="<?= (int) $maq['id'] ?>" <?= $idMaquina === (int) $maq['id'] ? 'selected' : '' ?>>
                            <?= e($maq['cod'] . ' — ' . $maq['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-[#5a6480] mb-1">Setor Fabril</label>
                <select name="id_setor" class="form-input text-xs">
                    <option value="">Todos os Setores</option>
                    <?php foreach ($setores as $st): ?>
                        <option value="<?= (int) $st['id'] ?>" <?= $idSetor === (int) $st['id'] ? 'selected' : '' ?>>
                            <?= e($st['cod'] . ' — ' . $st['descricao']) ?>
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

            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary text-xs w-full justify-center">
                    <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                    Filtrar
                </button>
                <a href="<?= APP_URL ?>/pages/soma/registros.php" class="btn btn-neutral text-xs px-2.5" title="Limpar Filtros">✕</a>
            </div>
        </div>

    </form>
</div>

<!-- Resumo em Badges dos Resultados -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-4">
    <div class="card p-3 flex items-center justify-between">
        <div>
            <div class="text-[10px] uppercase font-bold text-[#5a6480]">Turnos Encontrados</div>
            <div class="font-mono text-base font-bold text-[#1a2133] mt-0.5"><?= number_format($totalRegistros, 0, ',', '.') ?></div>
        </div>
        <span class="p-2 bg-slate-100 rounded-lg text-slate-700 text-xs">📋</span>
    </div>

    <div class="card p-3 flex items-center justify-between">
        <div>
            <div class="text-[10px] uppercase font-bold text-[#5a6480]">Eficiência Média</div>
            <div class="font-mono text-base font-bold text-[#1a3d2a] mt-0.5"><?= number_format($mediaEficiencia, 1, ',', '.') ?>%</div>
        </div>
        <span class="p-2 bg-emerald-50 rounded-lg text-emerald-700 text-xs">⚡</span>
    </div>

    <div class="card p-3 flex items-center justify-between">
        <div>
            <div class="text-[10px] uppercase font-bold text-[#5a6480]">Tempo Produzido</div>
            <div class="font-mono text-base font-bold text-emerald-700 mt-0.5"><?= formatarMinutosHoras($totalMinProd) ?></div>
        </div>
        <span class="p-2 bg-emerald-50 rounded-lg text-emerald-700 text-xs">⚙️</span>
    </div>

    <div class="card p-3 flex items-center justify-between">
        <div>
            <div class="text-[10px] uppercase font-bold text-[#5a6480]">Tempo em Paradas</div>
            <div class="font-mono text-base font-bold text-amber-700 mt-0.5"><?= formatarMinutosHoras($totalMinPar) ?></div>
        </div>
        <span class="p-2 bg-amber-50 rounded-lg text-amber-700 text-xs">⏱️</span>
    </div>
</div>

<!-- Tabela de Registros -->
<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <thead class="bg-[#f8f9fb] text-[#5a6480] border-b border-[#e2e6ed]">
                <tr>
                    <th class="py-2.5 px-3">Data / Turno</th>
                    <th class="py-2.5 px-3">Empresa / Setor</th>
                    <th class="py-2.5 px-3">Operador</th>
                    <th class="py-2.5 px-3">Máquina</th>
                    <th class="py-2.5 px-3 text-right">Disp.</th>
                    <th class="py-2.5 px-3 text-right">Prod.</th>
                    <th class="py-2.5 px-3 text-right">Paradas</th>
                    <th class="py-2.5 px-3 text-right">Eficiência</th>
                    <th class="py-2.5 px-3 text-center">Status</th>
                    <th class="py-2.5 px-3 text-center">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#e2e6ed]">
                <?php if (empty($registros)): ?>
                    <tr>
                        <td colspan="10" class="py-8 text-center text-[#9aa3b8] text-xs">
                            Nenhum registro de turno encontrado com os filtros informados.
                        </td>
                    </tr>
                <?php else: foreach ($registros as $r): 
                    $ef = (float) $r['eficiencia'];
                    $turnoLabel = match ($r['turno']) {
                        'D' => 'Diurno',
                        'N' => 'Noturno',
                        'M' => 'Misto',
                        default => $r['turno']
                    };
                ?>
                    <tr class="hover:bg-[#f8f9fb] transition">
                        <td class="py-2 px-3">
                            <div class="font-bold text-[#1a2133]"><?= formatarDataBr($r['data']) ?></div>
                            <span class="text-[10px] text-[#5a6480]"><?= $turnoLabel ?> (<?= substr($r['h_inicio'], 0, 5) ?>-<?= substr($r['h_fim'], 0, 5) ?>)</span>
                        </td>
                        <td class="py-2 px-3">
                            <div class="font-semibold text-[#1a2133]"><?= e($r['setor_descricao'] ?? $r['setor_nome'] ?? '—') ?></div>
                            <span class="text-[10px] text-[#9aa3b8]"><?= e($r['empresa_cod'] ?? 'Trael') ?></span>
                        </td>
                        <td class="py-2 px-3">
                            <div class="font-semibold text-[#1a2133]"><?= e($r['operador_nome'] ?? '—') ?></div>
                            <span class="text-[10px] font-mono text-[#9aa3b8]"><?= e($r['operador_cod'] ?? '') ?></span>
                        </td>
                        <td class="py-2 px-3">
                            <div class="text-[#1a2133]"><?= e($r['maquina_nome'] ?? '—') ?></div>
                            <span class="text-[10px] font-mono text-[#9aa3b8]"><?= e($r['maquina_cod'] ?? '') ?></span>
                        </td>
                        <td class="py-2 px-3 text-right font-mono text-[#5a6480]">
                            <?= formatarMinutosHoras($r['minutos_disponiveis']) ?>
                        </td>
                        <td class="py-2 px-3 text-right font-mono font-bold text-emerald-700">
                            <?= formatarMinutosHoras($r['minutos_produzidos']) ?>
                        </td>
                        <td class="py-2 px-3 text-right font-mono font-bold text-amber-700">
                            <?= formatarMinutosHoras($r['minutos_paradas']) ?>
                        </td>
                        <td class="py-2 px-3 text-right font-mono font-bold <?= $ef >= 80 ? 'text-[#16a34a]' : ($ef >= 60 ? 'text-[#d97706]' : 'text-[#dc2626]') ?>">
                            <?= number_format($ef, 1, ',', '.') ?>%
                        </td>
                        <td class="py-2 px-3 text-center">
                            <?= renderBadgeStatus($r['status']) ?>
                        </td>
                        <td class="py-2 px-3 text-center">
                            <div class="flex items-center justify-center gap-1.5">
                                <button type="button" onclick="abrirModalDetalhes(<?= (int) $r['id'] ?>)" class="p-1.5 text-[#5a6480] hover:text-[#1a3d2a] hover:bg-slate-100 rounded transition" title="Ver Detalhes do Turno">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                </button>
                                <?php if ($podeExcluir): ?>
                                    <button type="button" onclick="excluirTurno(<?= (int) $r['id'] ?>, '<?= e(formatarDataBr($r['data']) . ' - ' . ($r['operador_nome'] ?? '')) ?>')" class="p-1.5 text-[#9aa3b8] hover:text-red-600 hover:bg-red-50 rounded transition" title="Excluir Registro">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Paginação -->
    <?php if ($totalPaginas > 1): ?>
        <div class="py-3 px-4 bg-[#f8f9fb] border-t border-[#e2e6ed] flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
            <span class="text-[#5a6480]">
                Página <strong><?= $pagina ?></strong> de <strong><?= $totalPaginas ?></strong> (<?= $totalRegistros ?> registros totais)
            </span>
            <div class="flex items-center gap-1">
                <?php
                $queryParams = $_GET;
                ?>
                <?php if ($pagina > 1): $queryParams['p'] = $pagina - 1; ?>
                    <a href="?<?= http_build_query($queryParams) ?>" class="px-2.5 py-1 bg-white border border-[#e2e6ed] rounded text-[#5a6480] hover:bg-[#f8f9fb]">
                        &laquo; Anterior
                    </a>
                <?php endif; ?>

                <?php for ($i = max(1, $pagina - 2); $i <= min($totalPaginas, $pagina + 2); $i++): 
                    $queryParams['p'] = $i;
                ?>
                    <a href="?<?= http_build_query($queryParams) ?>" class="px-2.5 py-1 border rounded <?= $i === $pagina ? 'bg-[#1a3d2a] text-white border-[#1a3d2a] font-bold' : 'bg-white border-[#e2e6ed] text-[#5a6480] hover:bg-[#f8f9fb]' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>

                <?php if ($pagina < $totalPaginas): $queryParams['p'] = $pagina + 1; ?>
                    <a href="?<?= http_build_query($queryParams) ?>" class="px-2.5 py-1 bg-white border border-[#e2e6ed] rounded text-[#5a6480] hover:bg-[#f8f9fb]">
                        Próxima &raquo;
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Modal de Detalhes do Turno -->
<div id="modal-detalhes-turno" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl max-w-3xl w-full max-h-[90vh] flex flex-col overflow-hidden animate-in fade-in zoom-in-95 duration-150">
        
        <!-- Header do Modal -->
        <div class="py-3 px-6 bg-[#f8f9fb] border-b border-[#e2e6ed] flex items-center justify-between">
            <div>
                <h3 class="text-sm font-bold text-[#1a2133]" id="det-modal-titulo">Detalhes do Turno</h3>
                <span class="text-xs text-[#5a6480]" id="det-modal-subtitulo">Operador & Máquina</span>
            </div>
            <button type="button" onclick="fecharModalDetalhes()" class="text-[#9aa3b8] hover:text-[#1a2133] text-xl font-bold">&times;</button>
        </div>

        <!-- Corpo do Modal com Scroll -->
        <div class="p-6 overflow-y-auto space-y-6 text-xs" id="det-modal-conteudo">
            <div class="text-center py-8 text-[#9aa3b8]">Carregando dados do turno...</div>
        </div>

        <!-- Footer do Modal -->
        <div class="py-3 px-6 bg-[#f8f9fb] border-t border-[#e2e6ed] flex justify-end">
            <button type="button" onclick="fecharModalDetalhes()" class="btn btn-neutral text-xs">
                Fechar
            </button>
        </div>

    </div>
</div>

<script>
const API_URL = '<?= APP_URL ?>/api/soma-acao.php';
const CSRF_TOKEN = '<?= csrfToken() ?>';

async function abrirModalDetalhes(idTurno) {
    const modal = document.getElementById('modal-detalhes-turno');
    const conteudo = document.getElementById('det-modal-conteudo');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    conteudo.innerHTML = '<div class="text-center py-8 text-[#9aa3b8]">Carregando dados do turno...</div>';

    const formData = new FormData();
    formData.append('acao', 'obter_detalhes_turno');
    formData.append('id_turno', idTurno);
    formData.append('csrf_token', CSRF_TOKEN);

    try {
        const resp = await fetch(API_URL, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const res = await resp.json();

        if (!res.sucesso) {
            conteudo.innerHTML = `<div class="p-4 bg-red-50 text-red-700 text-xs rounded-lg">${res.erro || 'Erro ao carregar detalhes.'}</div>`;
            return;
        }

        const t = res.turno;
        const pecas = res.pecas || [];
        const paradas = res.paradas || [];
        const obs = res.observacoes || [];

        document.getElementById('det-modal-titulo').innerText = `Turno em ${t.data} (${t.turno === 'D' ? 'Diurno' : (t.turno === 'N' ? 'Noturno' : 'Misto')})`;
        document.getElementById('det-modal-subtitulo').innerText = `${t.operador_nome || 'Operador'} • ${t.maquina_nome || 'Máquina'} (${t.setor_nome || 'Setor'})`;

        let pecasHtml = '';
        if (pecas.length === 0) {
            pecasHtml = '<tr><td colspan="5" class="text-center py-3 text-[#9aa3b8] text-xs">Nenhuma peça registrada.</td></tr>';
        } else {
            pecas.forEach(p => {
                pecasHtml += `
                    <tr>
                        <td class="py-2 px-2 font-mono font-bold text-xs text-[#1a2133]">${p.cod_peca}</td>
                        <td class="py-2 px-2 text-xs text-[#5a6480]">${p.descricao_peca || '—'}</td>
                        <td class="py-2 px-2 text-right font-mono text-xs font-semibold">${p.qtd}</td>
                        <td class="py-2 px-2 text-right font-mono text-xs">${parseFloat(p.tp_padrao_min).toFixed(1)} min</td>
                        <td class="py-2 px-2 text-right font-mono font-bold text-xs text-emerald-700">${parseFloat(p.tp_total_produzido_min).toFixed(1)} min</td>
                    </tr>
                `;
            });
        }

        let paradasHtml = '';
        if (paradas.length === 0) {
            paradasHtml = '<tr><td colspan="4" class="text-center py-3 text-[#9aa3b8] text-xs">Nenhuma parada registrada.</td></tr>';
        } else {
            paradas.forEach(pr => {
                const tipoBadge = pr.motivo_tipo === 'PROG' ? '<span class="badge badge-info text-[10px]">Programada</span>' : '<span class="badge badge-warning text-[10px]">Não Programada</span>';
                paradasHtml += `
                    <tr>
                        <td class="py-2 px-2 font-mono font-bold text-xs text-[#1a2133]">${pr.motivo_cod || ''}</td>
                        <td class="py-2 px-2 text-xs text-[#1a2133]">${pr.motivo_descricao || '—'}</td>
                        <td class="py-2 px-2 text-center">${tipoBadge}</td>
                        <td class="py-2 px-2 text-right font-mono font-bold text-xs text-amber-700">${parseFloat(pr.duracao_minutos).toFixed(0)} min</td>
                    </tr>
                `;
            });
        }

        let obsHtml = '';
        if (obs.length > 0) {
            obs.forEach(o => {
                obsHtml += `<p class="p-3 bg-[#f8f9fb] border border-[#e2e6ed] rounded-lg text-xs text-[#5a6480] italic">"${o.texto}"</p>`;
            });
        } else {
            obsHtml = '<p class="text-xs text-[#9aa3b8] italic">Nenhuma observação de campo informada.</p>';
        }

        conteudo.innerHTML = `
            <!-- Painel de Indicadores -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                <div class="p-2.5 bg-[#f8f9fb] rounded-lg border border-[#e2e6ed]">
                    <div class="text-[10px] uppercase font-bold text-[#5a6480]">Tempo Disponível</div>
                    <div class="font-mono text-sm font-bold text-[#1a2133]">${parseFloat(t.minutos_disponiveis).toFixed(0)} min</div>
                </div>
                <div class="p-2.5 bg-[#f8f9fb] rounded-lg border border-[#e2e6ed]">
                    <div class="text-[10px] uppercase font-bold text-[#5a6480]">Tempo Produzido</div>
                    <div class="font-mono text-sm font-bold text-emerald-700">${parseFloat(t.minutos_produzidos).toFixed(0)} min</div>
                </div>
                <div class="p-2.5 bg-[#f8f9fb] rounded-lg border border-[#e2e6ed]">
                    <div class="text-[10px] uppercase font-bold text-[#5a6480]">Tempo Parado</div>
                    <div class="font-mono text-sm font-bold text-amber-700">${parseFloat(t.minutos_paradas).toFixed(0)} min</div>
                </div>
                <div class="p-2.5 bg-[#f8f9fb] rounded-lg border border-[#e2e6ed]">
                    <div class="text-[10px] uppercase font-bold text-[#5a6480]">Eficiência</div>
                    <div class="font-mono text-sm font-bold text-[#1a3d2a]">${parseFloat(t.eficiencia).toFixed(1)}%</div>
                </div>
            </div>

            <!-- Peças -->
            <div>
                <h4 class="text-xs font-bold text-[#1a2133] mb-2 flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span> Peças Fabricadas no Turno
                </h4>
                <div class="overflow-x-auto border border-[#e2e6ed] rounded-lg">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-[#f8f9fb] text-[#5a6480] border-b border-[#e2e6ed]">
                            <tr>
                                <th class="py-2 px-2">Código</th>
                                <th class="py-2 px-2">Descrição</th>
                                <th class="py-2 px-2 text-right">Qtd</th>
                                <th class="py-2 px-2 text-right">TP Unit.</th>
                                <th class="py-2 px-2 text-right">Total Produzido</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#e2e6ed]">
                            ${pecasHtml}
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Paradas -->
            <div>
                <h4 class="text-xs font-bold text-[#1a2133] mb-2 flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-amber-500"></span> Paradas e Interrupções
                </h4>
                <div class="overflow-x-auto border border-[#e2e6ed] rounded-lg">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-[#f8f9fb] text-[#5a6480] border-b border-[#e2e6ed]">
                            <tr>
                                <th class="py-2 px-2">Código</th>
                                <th class="py-2 px-2">Motivo</th>
                                <th class="py-2 px-2 text-center">Tipo</th>
                                <th class="py-2 px-2 text-right">Duração</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#e2e6ed]">
                            ${paradasHtml}
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Observações -->
            <div>
                <h4 class="text-xs font-bold text-[#1a2133] mb-2 flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-blue-500"></span> Observações de Campo & Ergonomia
                </h4>
                ${obsHtml}
            </div>
        `;
    } catch (e) {
        conteudo.innerHTML = '<div class="p-4 bg-red-50 text-red-700 text-xs rounded-lg">Erro ao comunicar com o servidor.</div>';
    }
}

function fecharModalDetalhes() {
    document.getElementById('modal-detalhes-turno').classList.add('hidden');
    document.getElementById('modal-detalhes-turno').classList.remove('flex');
}

async function excluirTurno(idTurno, descricao) {
    if (!confirm(`Deseja realmente excluir o apontamento de turno "${descricao}"? Esta ação removerá os dados de peças e paradas associadas.`)) {
        return;
    }

    const formData = new FormData();
    formData.append('acao', 'excluir_turno');
    formData.append('id_turno', idTurno);
    formData.append('csrf_token', CSRF_TOKEN);

    try {
        const resp = await fetch(API_URL, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const res = await resp.json();

        if (res.sucesso) {
            Toast.success(res.mensagem || 'Turno excluído com sucesso!');
            setTimeout(() => window.location.reload(), 600);
        } else {
            Toast.error(res.erro || 'Falha ao excluir o turno.');
        }
    } catch (e) {
        Toast.error('Erro de conexão ao excluir.');
    }
}
</script>

<?php
layoutFooter();
