<?php
declare(strict_types=1);

/**
 * Lista de Registros da Pintura (apontamentos na estação PIN) — cópia
 * adaptada de pages/producao/lista.php. O botão "Reprovar" fala com
 * api/pintura-retornos-acao.php (ação `reprovar_pintura`, já existente e
 * testada) — não com api/retrabalho-acao.php.
 */

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();
if (!hasAcesso('tab:pintura') && !hasAcesso('admin')) {
    requireAcessoModulo('tab:pintura');
}

$canEdit = podeEditar('pin.lis') || isAdmin();

$pdo  = getDB();
$base = defined('APP_URL') ? APP_URL : '';

$STATUS_VALIDOS = ['em_andamento', 'finalizado'];

// ─── Filtros (GET) ────────────────────────────────────────────────────────────
$fBusca = trim((string) ($_GET['busca'] ?? ''));
$fMes   = trim((string) ($_GET['mes'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $fMes)) $fMes = '';

$fStatus = $STATUS_VALIDOS;

$SORT_COLS_VALIDAS = ['ns', 'projeto', 'pedido', 'estacao', 'metodo_insercao', 'status', 'inicio', 'fim', 'responsavel'];
$sortCol = (string) ($_GET['sort'] ?? 'inicio');
if (!in_array($sortCol, $SORT_COLS_VALIDAS, true)) $sortCol = 'inicio';
$sortDir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

$PORPAGINA_OPCOES = [10, 25, 50, 100];
$porPagina = (int) ($_GET['porPagina'] ?? 25);
if (!in_array($porPagina, $PORPAGINA_OPCOES, true)) $porPagina = 25;
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));

$where  = ['pe.deleted_at IS NULL', "pe.estacao = 'PIN'"];
$params = [];

if ($fMes !== '') { $where[] = "DATE_FORMAT(pe.data_inicio, '%Y-%m') = ?"; $params[] = $fMes; }

if ($fBusca !== '') {
    $where[] = '(pe.ns_transformador LIKE ? OR pr.codigo LIKE ? OR ped.numero LIKE ? OR u.nome LIKE ?)';
    $like = '%' . $fBusca . '%';
    array_push($params, $like, $like, $like, $like);
}

if ($fStatus) {
    $where[] = 'pe.status IN (' . implode(',', array_fill(0, count($fStatus), '?')) . ')';
    array_push($params, ...$fStatus);
} else {
    $where[] = '1=0';
}

$whereSql = implode(' AND ', $where);

$sql = "
    SELECT pe.*,
           pr.codigo AS projeto_codigo, pr.descricao AS projeto_descricao,
           ped.numero AS pedido_numero,
           u.nome AS responsavel_nome
    FROM producao_etapas pe
    JOIN projetos pr      ON pr.id  = pe.id_projeto
    JOIN pedidos ped      ON ped.id = pr.id_pedido
    LEFT JOIN usuarios u  ON u.id   = pe.id_responsavel
    WHERE $whereSql
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$registros = $stmt->fetchAll();

$agora = new DateTime('now');
foreach ($registros as &$r) {
    $ini = $r['data_inicio'] ? new DateTime($r['data_inicio']) : null;
    $fim = $r['data_fim'] ? new DateTime($r['data_fim']) : ($r['status'] === 'em_andamento' ? $agora : null);
    $r['_duracaoS'] = ($ini && $fim) ? max(0, $fim->getTimestamp() - $ini->getTimestamp()) : null;
}
unset($r);

function pinLstSortValue(array $r, string $col): string|int
{
    return match ($col) {
        'ns'              => (string) ($r['ns_transformador'] ?? ''),
        'pedido'          => (string) ($r['pedido_numero'] ?? ''),
        'projeto'         => (string) ($r['projeto_codigo'] ?? ''),
        'descricao'       => (string) ($r['projeto_descricao'] ?? ''),
        'estacao'         => (string) ($r['estacao'] ?? ''),
        'metodo_insercao' => (string) ($r['metodo_insercao'] ?? ''),
        'status'          => (string) ($r['status'] ?? ''),
        'inicio'          => (string) ($r['data_inicio'] ?? ''),
        'fim'             => (string) ($r['data_fim'] ?? ''),
        'responsavel'     => (string) ($r['responsavel_nome'] ?? ''),
        default           => '',
    };
}

usort($registros, function (array $a, array $b) use ($sortCol, $sortDir): int {
    $va = pinLstSortValue($a, $sortCol);
    $vb = pinLstSortValue($b, $sortCol);
    $cmp = strcasecmp((string) $va, (string) $vb);
    if ($sortDir === 'desc') $cmp = -$cmp;
    return $cmp !== 0 ? $cmp : ($b['id'] <=> $a['id']);
});

$totalRegistros = count($registros);
$totalPaginas   = max(1, (int) ceil($totalRegistros / $porPagina));
if ($pagina > $totalPaginas) $pagina = $totalPaginas;
$offset          = ($pagina - 1) * $porPagina;
$registrosPagina = array_slice($registros, $offset, $porPagina);

$mesesDisponiveis = $pdo->query("
    SELECT DISTINCT DATE_FORMAT(data_inicio, '%Y-%m') AS ym
    FROM producao_etapas
    WHERE deleted_at IS NULL AND estacao = 'PIN'
    ORDER BY ym DESC
")->fetchAll(PDO::FETCH_COLUMN);

$MESES_PT = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho',
             7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];

$estacaoMap = [
    'PIN' => ['label' => 'PIN', 'title' => 'Pintura', 'bg' => '#fdf2f8', 'fg' => '#be185d'],
];

$statusMap = [
    'em_andamento' => ['label' => 'Em andamento', 'bg' => 'var(--color-warning-bg)', 'fg' => 'var(--color-warning-text)'],
    'finalizado'   => ['label' => 'Finalizado',   'bg' => 'var(--color-success-bg)', 'fg' => 'var(--color-success-text)'],
];

// Catálogo de reprovas da Pintura — mesmo filtro usado pelo modal "Reprovar"
// do Paint Check (setor_causador, não local — ver pages/qualidade/paint-check.php).
$reprovasCatalogo = $pdo->query("
    SELECT id, codigo, familia, descricao, local
    FROM reprovas
    WHERE ativo = 1 AND setor_causador IN ('PINTURA', 'CALDEIRARIA')
    ORDER BY ordem ASC, LENGTH(codigo) ASC, codigo ASC
")->fetchAll();

$temFiltroAtivo = ($fBusca !== '' || $fMes !== '');

function pinLstUrl(array $novosParams): string
{
    $params = $_GET;
    foreach ($novosParams as $k => $v) {
        if ($v === null || $v === '') unset($params[$k]);
        else $params[$k] = $v;
    }
    $query = http_build_query($params);
    return '?' . $query;
}

function pinLstSortTh(string $label, string $col): void
{
    global $sortCol, $sortDir;
    $isAtivo = ($sortCol === $col);
    $proxDir = ($isAtivo && $sortDir === 'asc') ? 'desc' : 'asc';
    $seta    = $isAtivo ? ($sortDir === 'asc' ? ' &uarr;' : ' &darr;') : '';
    $url     = pinLstUrl(['sort' => $col, 'dir' => $proxDir, 'pagina' => 1]);
    echo '<th><a href="' . htmlspecialchars($url) . '" style="color:inherit;text-decoration:none;display:block;">' . htmlspecialchars($label) . $seta . '</a></th>';
}

$pageTitle = 'Lista de Registros — Pintura';
require_once __DIR__ . '/../../includes/layout.php';
layoutHeader($pageTitle);
?>

<style>
    /* Combobox de Busca de Reprovas — mesmo padrão de pages/producao/lista.php */
    .rep-search-combobox { position: relative; width: 100%; }
    .rep-search-input {
        width: 100%; height: 38px; padding: 0 34px 0 12px;
        border: 1px solid var(--color-border-strong); border-radius: var(--radius-md);
        font-size: var(--font-size-base); font-family: var(--font-sans);
        background: var(--color-surface); color: var(--color-text-primary); outline: none;
    }
    .rep-search-input:focus { border-color: var(--color-accent); box-shadow: 0 0 0 3px rgba(232,160,32,0.15); }
    .rep-search-toggle {
        position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
        background: none; border: none; padding: 4px; cursor: pointer; color: var(--color-text-muted);
        display: flex; align-items: center; justify-content: center;
    }
    .rep-search-toggle svg { width: 16px; height: 16px; transition: transform .2s; }
    .rep-search-combobox.is-open .rep-search-toggle svg { transform: rotate(180deg); color: var(--color-text-primary); }
    .rep-search-dropdown {
        position: absolute; top: calc(100% + 4px); left: 0; right: 0; max-height: 220px;
        overflow-y: auto; background: var(--color-surface); border: 1px solid var(--color-border-strong);
        border-radius: var(--radius-md); box-shadow: var(--shadow-lg); z-index: 1050; padding: 4px;
    }
    .rep-item {
        padding: 8px 10px; border-radius: var(--radius-sm); cursor: pointer;
        display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: var(--font-size-sm);
    }
    .rep-item:hover, .rep-item.is-selected { background: var(--color-surface-2); }
    .rep-item.is-active { background: var(--color-accent-light); color: var(--color-accent-text); }
    .lst-reprova-block {
        border: 1px solid var(--color-border); border-radius: var(--radius-lg);
        padding: 12px 14px; background: var(--color-surface-2);
    }
    .lst-reprova-head { display: flex; gap: 10px; align-items: flex-end; }
    .lst-reprova-select-wrap { flex: 1; }
    .lst-reprova-remove {
        background: var(--color-surface); border: 1px solid var(--color-border);
        border-radius: var(--radius-md); width: 38px; height: 38px; flex-shrink: 0;
        cursor: pointer; color: var(--color-text-muted); font-size: 18px; line-height: 1;
        display: flex; align-items: center; justify-content: center; transition: all var(--transition);
    }
    .lst-reprova-remove:hover { border-color: var(--color-danger); color: var(--color-danger); background: var(--color-danger-bg); }
    .table-wrap { overflow-x:auto; overflow-y:auto; max-height:calc(100vh - 280px); max-height:calc(100dvh - 280px); }
    .data-table thead th { position:sticky; top:0; z-index:10; background:#f8fafc; box-shadow:0 1px 2px rgba(0,0,0,0.05); }
</style>

<div class="page-fixed-layout">

    <div class="page-fixed-header">
        <h1 style="font-size:var(--font-size-xl,20px);font-weight:700;margin-top:2px;">Lista de Registros</h1>
        <p class="text-secondary" style="font-size:13px;color:var(--color-text-secondary,#6b7280);margin-top:2px;">
            Transformadores lidos e registrados na Pintura, com filtros e paginação
        </p>
    </div>

    <div class="card">
        <div class="card-header">
        <div>
            <div class="card-title">Registros da Pintura</div>
            <div class="card-subtitle"><?= $totalRegistros ?> registro<?= $totalRegistros === 1 ? '' : 's' ?></div>
        </div>
    </div>

    <form method="GET" class="filter-bar" id="lst-filtros">
        <div class="filter-group-left">
            <div class="filter-search-wrap">
                <svg class="filter-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="search" name="busca" class="filter-search-input" value="<?= htmlspecialchars($fBusca) ?>" placeholder="Buscar N° de série, projeto, pedido, responsável…">
            </div>
            <select name="mes" class="filter-select" onchange="this.form.submit()">
                <option value="">Mês…</option>
                <?php foreach ($mesesDisponiveis as $ym): if (!$ym) continue;
                    $lbl = ($MESES_PT[(int) substr($ym, 5, 2)] ?? $ym) . '/' . substr($ym, 0, 4);
                ?>
                    <option value="<?= htmlspecialchars($ym) ?>" <?= $fMes === $ym ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="filter-btn filter-btn-secondary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                Filtrar
            </button>
            <?php if ($temFiltroAtivo): ?>
                <a href="<?= htmlspecialchars($base) ?>/pages/pintura/lista.php" class="filter-btn filter-btn-clear" title="Limpar filtros">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    Limpar
                </a>
            <?php endif; ?>
        </div>
    </form>

    <div class="table-wrap" id="lst-table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <?php
                    pinLstSortTh('N° Série', 'ns');
                    pinLstSortTh('Pedido', 'pedido');
                    pinLstSortTh('Projeto', 'projeto');
                    pinLstSortTh('Descrição', 'descricao');
                    pinLstSortTh('Estação', 'estacao');
                    pinLstSortTh('Método', 'metodo_insercao');
                    echo '<th style="text-align:right;cursor:default;">Ações</th>';
                    ?>
                </tr>
            </thead>
            <tbody>
                <?php if (!$registrosPagina): ?>
                    <tr><td colspan="7" style="text-align:center;padding:36px 16px;color:var(--color-text-muted);">Nenhum registro encontrado para os filtros selecionados.</td></tr>
                <?php else: foreach ($registrosPagina as $r):
                    $es = $estacaoMap[$r['estacao']] ?? null;
                    $st = $statusMap[$r['status']] ?? $statusMap['em_andamento'];
                ?>
                    <tr>
                        <td><span class="font-mono font-600"><?= htmlspecialchars((string) $r['ns_transformador']) ?></span></td>
                        <td><?= htmlspecialchars($r['pedido_numero'] ?? '—') ?></td>
                        <td><span class="font-mono font-600"><?= htmlspecialchars($r['projeto_codigo'] ?? '—') ?></span></td>
                        <td><?= htmlspecialchars($r['projeto_descricao'] ?? '—') ?></td>
                        <td>
                            <?php if ($es): ?>
                                <span class="badge" style="background:<?= $es['bg'] ?>;color:<?= $es['fg'] ?>;" title="<?= htmlspecialchars($es['title']) ?>"><?= $es['label'] ?></span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td>
                            <?php if (($r['metodo_insercao'] ?? 'scanner') === 'manual'): ?>
                                <span class="badge badge-warning">Manual</span>
                            <?php else: ?>
                                <span class="badge badge-success">Scanner</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;">
                            <?php if ($canEdit): ?>
                                <div style="display:flex; gap:6px; align-items:center; justify-content:flex-end;">
                                    <button type="button" class="btn btn-danger btn-sm js-reprovar"
                                            data-id_projeto="<?= (int) $r['id_projeto'] ?>"
                                            data-ns_transformador="<?= htmlspecialchars((string) $r['ns_transformador']) ?>"
                                            data-projeto_codigo="<?= htmlspecialchars((string) ($r['projeto_codigo'] ?? '')) ?>"
                                            data-projeto_descricao="<?= htmlspecialchars((string) ($r['projeto_descricao'] ?? '')) ?>"
                                            data-pedido_numero="<?= htmlspecialchars((string) ($r['pedido_numero'] ?? '')) ?>">
                                        Reprovar
                                    </button>
                                    <button type="button" class="btn-icon btn-icon-danger js-excluir-registro" title="Excluir registro" data-ns_transformador="<?= htmlspecialchars((string) $r['ns_transformador']) ?>">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                                    </button>
                                </div>
                            <?php else: ?>
                                <span style="color:#94a3b8;font-size:12px;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-top:16px;padding-top:14px;border-top:1px solid var(--color-border);font-size:var(--font-size-sm);color:var(--color-text-secondary);">
        <div style="display:flex;align-items:center;gap:8px;">
            <span>Exibir</span>
            <select class="filter-select" style="height:32px;padding:0 8px;font-size:12px;" onchange="location.href=this.value">
                <?php foreach ($PORPAGINA_OPCOES as $opt): ?>
                    <option value="<?= pinLstUrl(['porPagina' => $opt, 'pagina' => 1]) ?>" <?= $porPagina === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                <?php endforeach; ?>
            </select>
            <span>por página &middot; <?= $totalRegistros ?> registro<?= $totalRegistros === 1 ? '' : 's' ?></span>
        </div>
        <?php if ($totalPaginas > 1): ?>
            <div class="pagination">
                <?php if ($pagina > 1): ?>
                    <a href="<?= pinLstUrl(['pagina' => $pagina - 1]) ?>" class="page-btn">&larr;</a>
                <?php endif; ?>
                <span style="padding:0 8px;font-weight:600;"><?= $pagina ?> / <?= $totalPaginas ?></span>
                <?php if ($pagina < $totalPaginas): ?>
                    <a href="<?= pinLstUrl(['pagina' => $pagina + 1]) ?>" class="page-btn">&rarr;</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
</div>

<!-- Modal: Reprovar Transformador -->
<div class="modal-overlay" id="rep-modal" style="display:none;">
    <div class="modal" style="max-width:600px;">
        <div class="modal-header">
            <div>
                <div class="modal-title">Reprovação de Transformador</div>
                <div class="card-subtitle">
                    NS <strong id="rep-modal-ns">—</strong> &middot; Projeto <strong id="rep-modal-projeto">—</strong>
                </div>
            </div>
            <button type="button" class="modal-close" id="rep-modal-close">&times;</button>
        </div>

        <form id="rep-form">
            <input type="hidden" name="id_projeto" id="rep-form-id-projeto" value="">
            <input type="hidden" name="ns_transformador" id="rep-form-ns" value="">

            <div class="modal-body" style="display:flex;flex-direction:column;gap:14px;">
                <div id="rep-form-erro" style="display:none;" class="alert alert-danger"></div>

                <div id="rep-reprovas-container">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                        <label class="form-label font-600" style="margin:0;text-transform:uppercase;font-size:11px;letter-spacing:.6px;">Motivos da Reprovação *</label>
                        <button type="button" class="btn btn-secondary btn-sm" id="rep-add-reprova-btn">+ Adicionar outra reprova</button>
                    </div>
                    <div id="rep-reprovas-list" style="display:flex;flex-direction:column;gap:10px;"></div>
                </div>

                <div class="form-group" style="margin:0;">
                    <label class="form-label">Observações gerais (opcional)</label>
                    <textarea name="observacoes" id="rep-obs" rows="2" class="form-control" placeholder="Detalhes adicionais sobre o motivo da reprovação…"></textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="rep-modal-cancelar">Cancelar</button>
                <button type="submit" class="btn btn-danger" id="rep-btn-submit">Confirmar Reprovação</button>
            </div>
        </form>
    </div>
</div>

<!-- Template de Linha de Reprova -->
<template id="rep-reprova-template">
    <div class="lst-reprova-block" data-index="__INDEX__">
        <div class="lst-reprova-head">
            <div class="lst-reprova-select-wrap">
                <label class="form-label" style="margin-bottom:4px;">Selecione a Reprova / Não Conformidade *</label>
                <div class="rep-search-combobox">
                    <input type="text" class="rep-search-input" placeholder="Buscar código ou descrição…" autocomplete="off">
                    <input type="hidden" name="reprovas[__INDEX__][id_reprova]" class="rep-hidden-id" required>
                    <button type="button" class="rep-search-toggle" aria-label="Abrir opções">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <div class="rep-search-dropdown" style="display:none;"></div>
                </div>
            </div>
            <button type="button" class="lst-reprova-remove" title="Remover esta reprova">&times;</button>
        </div>
        <div class="lst-reprova-detalhes" style="display:none;margin-top:10px;padding-top:10px;border-top:1px dashed var(--color-border);font-size:var(--font-size-sm);color:var(--color-text-secondary);">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                <div><span style="color:var(--color-text-muted);">Família:</span> <strong class="rep-info-familia font-600">—</strong></div>
                <div><span style="color:var(--color-text-muted);">Local:</span> <strong class="rep-info-local font-600">—</strong></div>
            </div>
        </div>
    </div>
</template>

<!-- Modal: Excluir Registro -->
<div class="modal-overlay" id="excluir-modal" style="display:none;">
    <div class="modal" style="max-width:420px;">
        <div class="modal-header">
            <span class="modal-title">Confirmar Exclusão</span>
            <button type="button" class="modal-close" id="excluir-modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <p style="font-size:var(--font-size-base);color:var(--color-text-secondary);margin:0 0 16px;">
                Deseja realmente excluir o registro do transformador <strong id="excluir-modal-ns" style="color:var(--color-text-primary);"></strong>?
            </p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="excluir-modal-cancelar">Cancelar</button>
            <button type="button" class="btn btn-danger" id="excluir-modal-confirmar">Excluir Registro</button>
        </div>
    </div>
</div>

<script>
    window.PINTURA_LISTA_API = <?= json_encode($base . '/api/pintura-retornos-acao.php') ?>;
    window.PINTURA_API = <?= json_encode($base . '/api/pintura-acao.php') ?>;
    window.PINTURA_REPROVAS = <?= json_encode($reprovasCatalogo, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<?php $pinLstJsVer = @filemtime(__DIR__ . '/../../assets/js/pintura-lista.js') ?: (defined('APP_VERSION') ? APP_VERSION : '1'); ?>
<script src="<?= htmlspecialchars($base) ?>/assets/js/pintura-lista.js?v=<?= htmlspecialchars((string) $pinLstJsVer) ?>"></script>

<?php layoutFooter(); ?>
