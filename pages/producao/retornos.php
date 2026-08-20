<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireAcessoModulo('producao');

$pdo  = getDB();
$base = defined('APP_URL') ? APP_URL : '';

// ─── Filtros (GET) ────────────────────────────────────────────────────────────
$fBusca = trim((string) ($_GET['busca'] ?? ''));
$fMes   = trim((string) ($_GET['mes'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $fMes)) $fMes = '';

$where  = ["pe.estacao = 'LAB'", "pe.status = 'aguardando_retorno'", "pe.deleted_at IS NULL"];
$params = [];

if ($fMes !== '') {
    $where[]  = "DATE_FORMAT(pe.data_inicio, '%Y-%m') = ?";
    $params[] = $fMes;
}

if ($fBusca !== '') {
    $where[] = '(pe.ns_transformador LIKE ? OR pr.codigo LIKE ? OR ped.numero LIKE ? OR pr.descricao LIKE ?)';
    $like = '%' . $fBusca . '%';
    array_push($params, $like, $like, $like, $like);
}

$whereSql = implode(' AND ', $where);

// Transformadores enviados de volta ao Laboratório pela Triagem do Retrabalho
$stmtRetornos = $pdo->prepare("
    SELECT pe.ns_transformador, pe.id_projeto, pe.data_inicio,
           pr.codigo AS projeto_codigo, pr.descricao AS projeto_descricao,
           ped.numero AS pedido_numero
    FROM producao_etapas pe
    JOIN projetos pr ON pr.id  = pe.id_projeto
    JOIN pedidos ped ON ped.id = pr.id_pedido
    WHERE $whereSql
    ORDER BY pe.data_inicio DESC
");
$stmtRetornos->execute($params);
$retornos = $stmtRetornos->fetchAll();

$temFiltroAtivo = ($fBusca !== '' || $fMes !== '');

$mesesDisponiveis = $pdo->query("
    SELECT DISTINCT DATE_FORMAT(data_inicio, '%Y-%m') AS ym
    FROM producao_etapas
    WHERE estacao = 'LAB' AND status = 'aguardando_retorno' AND deleted_at IS NULL
    ORDER BY ym DESC
")->fetchAll(PDO::FETCH_COLUMN);

$MESES_PT = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho',
             7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];

// Reprovas que motivaram cada retorno
$reprovasPorItem = [];
if ($retornos) {
    $nsList = array_values(array_unique(array_column($retornos, 'ns_transformador')));
    $ph     = implode(',', array_fill(0, count($nsList), '?'));
    $stmtRep = $pdo->prepare("
        SELECT r.id_projeto, r.ns_transformador, r.data_reprova, r.causa_raiz, r.id_reprova,
               rep.id AS reprova_id, rep.codigo AS reprova_codigo, rep.familia AS reprova_familia,
               rep.descricao AS reprova_descricao, rep.local AS reprova_local
        FROM retrabalhos r
        INNER JOIN (
            SELECT ns_transformador, MAX(id) AS max_id
            FROM retrabalhos
            WHERE deleted_at IS NULL AND setores_destino IS NOT NULL AND FIND_IN_SET('laboratorio', setores_destino)
            GROUP BY ns_transformador
        ) AS ultimos ON r.ns_transformador = ultimos.ns_transformador
        LEFT JOIN retrabalhos r_max ON r_max.id = ultimos.max_id
        LEFT JOIN reprovas rep ON rep.id = r.id_reprova
        WHERE r.deleted_at IS NULL
          AND r.setores_destino IS NOT NULL AND FIND_IN_SET('laboratorio', r.setores_destino)
          AND r.ns_transformador IN ($ph)
          AND IFNULL(r.id_lote, r.id) = IFNULL(r_max.id_lote, r_max.id)
        ORDER BY r.data_reprova DESC, r.id DESC
    ");
    $stmtRep->execute($nsList);
    foreach ($stmtRep->fetchAll() as $rep) {
        $chave = $rep['id_projeto'] . '|' . $rep['ns_transformador'];
        $reprovasPorItem[$chave][] = $rep;
    }
}

// Catálogo de reprovas para o modal de Nova Reprova (apenas LAB e GER)
$reprovasCatalogo = $pdo->query("
    SELECT id, codigo, familia, descricao, local
    FROM reprovas
    WHERE ativo = 1 AND (local LIKE '%LAB%' OR local = 'GER' OR local = '' OR local IS NULL)
    ORDER BY ordem ASC, LENGTH(codigo) ASC, codigo ASC
")->fetchAll();

function retFmtDataHora(?string $iso): string
{
    if (!$iso) return '—';
    $ts = strtotime($iso);
    return $ts ? date('d/m/y H:i', $ts) : '—';
}

function retFmtData(?string $iso): string
{
    if (!$iso) return '—';
    $ts = strtotime($iso);
    return $ts ? date('d/m/y', $ts) : '—';
}

$pageTitle = 'Retornos ao Laboratório';
require_once __DIR__ . '/../../includes/layout.php';
layoutHeader($pageTitle);
?>

<style>
    .ret-detail-row { display: none; }
    .ret-detail-row.is-open { display: table-row; }
    .ret-detail-wrap {
        background: var(--color-surface-2);
        border-radius: var(--radius-md);
        padding: 10px 14px;
        margin: 4px 0;
        border: 1px solid var(--color-border);
    }
    .ret-subtable { width: 100%; border-collapse: collapse; font-size: var(--font-size-sm); }
    .ret-subtable th {
        text-align: left; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px;
        color: var(--color-text-muted); padding: 8px 10px; border-bottom: 1px solid var(--color-border); white-space: nowrap;
    }
    .ret-subtable td { padding: 8px 10px; border-bottom: 1px solid var(--color-border); vertical-align: middle; }
    .ret-subtable tr:last-child td { border-bottom: none; }
    
    /* Searchable Combobox */
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
</style>

<!-- Cabeçalho -->
<div style="margin-bottom:18px;">
    <h1 style="font-size:var(--font-size-xl,20px);font-weight:700;margin-top:2px;">Retornos ao Laboratório</h1>
    <p class="text-secondary" style="font-size:13px;color:var(--color-text-secondary,#6b7280);margin-top:2px;">
        Transformadores encaminhados pelo Retrabalho, aguardando dar entrada de novo no Laboratório
    </p>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <div class="card-title">Aguardando retorno</div>
            <div class="card-subtitle"><?= count($retornos) ?> transformador<?= count($retornos) === 1 ? '' : 'es' ?></div>
        </div>
    </div>

    <!-- Filtros Padrão main.css -->
    <form method="GET" class="filter-bar">
        <div class="filter-group-left">
            <div class="filter-search-wrap">
                <svg class="filter-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="search" name="busca" class="filter-search-input" value="<?= htmlspecialchars($fBusca) ?>" placeholder="Buscar N° de série, projeto, pedido…">
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
                <a href="<?= htmlspecialchars($base) ?>/pages/producao/retornos.php" class="filter-btn filter-btn-clear" title="Limpar filtros">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    Limpar
                </a>
            <?php endif; ?>
        </div>
    </form>

    <?php if (!$retornos): ?>
        <p style="text-align:center;padding:36px 16px;color:var(--color-text-muted);font-size:13px;">
            <?= $temFiltroAtivo ? 'Nenhum transformador encontrado para os filtros selecionados.' : 'Nenhum transformador aguardando retorno.' ?>
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width:32px;cursor:default;"></th>
                        <th>N° Série</th>
                        <th>Pedido</th>
                        <th>Projeto</th>
                        <th>Descrição</th>
                        <th>Potência</th>
                        <th>Classe</th>
                        <th>Enviado em</th>
                        <th style="text-align:right;cursor:default;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($retornos as $i => $r):
                        $chave    = $r['id_projeto'] . '|' . $r['ns_transformador'];
                        $reprovas = $reprovasPorItem[$chave] ?? [];
                        $detId    = 'ret-det-' . $i;
                        [$potencia, $classe] = parsePotenciaClasse($r['projeto_descricao'] ?? null);
                    ?>
                        <tr>
                            <td style="text-align:center;">
                                <button type="button" class="btn-icon btn-icon-sm js-toggle-retorno" data-target="<?= htmlspecialchars($detId) ?>" aria-expanded="false" title="Mostrar reprovas">+</button>
                            </td>
                            <td><span class="font-mono font-600"><?= htmlspecialchars($r['ns_transformador']) ?></span></td>
                            <td><?= htmlspecialchars($r['pedido_numero'] ?? '—') ?></td>
                            <td><span class="font-mono font-600"><?= htmlspecialchars($r['projeto_codigo'] ?? '—') ?></span></td>
                            <td><?= htmlspecialchars($r['projeto_descricao'] ?? '—') ?></td>
                            <td><?= $potencia !== null ? htmlspecialchars($potencia) . ' kVA' : '—' ?></td>
                            <td><?= $classe !== null ? htmlspecialchars($classe) . ' kV' : '—' ?></td>
                            <td><?= htmlspecialchars(retFmtDataHora($r['data_inicio'])) ?></td>
                            <td style="text-align:right;">
                                <div style="display:flex; gap:6px; justify-content:flex-end; align-items:center;">
                                    <button type="button" class="btn btn-success btn-sm js-aprovar-retorno"
                                            data-id_projeto="<?= (int) $r['id_projeto'] ?>"
                                            data-ns_transformador="<?= htmlspecialchars($r['ns_transformador']) ?>">
                                        Aprovado
                                    </button>
                                    <button type="button" class="btn btn-danger btn-sm js-reprovar-retorno"
                                            data-id_projeto="<?= (int) $r['id_projeto'] ?>"
                                            data-ns_transformador="<?= htmlspecialchars($r['ns_transformador']) ?>"
                                            data-projeto_codigo="<?= htmlspecialchars($r['projeto_codigo'] ?? '') ?>"
                                            data-projeto_descricao="<?= htmlspecialchars($r['projeto_descricao'] ?? '') ?>"
                                            data-pedido_numero="<?= htmlspecialchars($r['pedido_numero'] ?? '') ?>">
                                        Reprovar
                                    </button>
                                    <button type="button" class="btn-icon btn-icon-danger js-excluir-retorno"
                                            title="Excluir retorno"
                                            data-id_projeto="<?= (int) $r['id_projeto'] ?>"
                                            data-ns_transformador="<?= htmlspecialchars($r['ns_transformador']) ?>">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr class="ret-detail-row" id="<?= htmlspecialchars($detId) ?>">
                            <td colspan="9" style="padding:4px 12px 12px;background:var(--color-surface-2);">
                                <div class="ret-detail-wrap">
                                    <?php if (!$reprovas): ?>
                                        <p style="font-size:12px;color:var(--color-text-muted);padding:6px 8px;margin:0;">Nenhuma reprova associada encontrada.</p>
                                    <?php else: ?>
                                        <div style="overflow-x:auto;">
                                        <table class="ret-subtable">
                                            <thead>
                                                <tr><th>Contenção</th><th>Família</th><th>Causa da Reprova</th><th>Data reprova</th></tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($reprovas as $rep): ?>
                                                    <tr>
                                                        <td>
                                                            <span class="font-mono font-600"><?= htmlspecialchars($rep['reprova_codigo'] ?? '—') ?></span>
                                                            <?php if (!empty($rep['reprova_descricao'])): ?>
                                                                <div style="font-size:11px;color:var(--color-text-secondary);"><?= htmlspecialchars($rep['reprova_descricao']) ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?= htmlspecialchars($rep['reprova_familia'] ?? '—') ?></td>
                                                        <td><?= htmlspecialchars($rep['causa_raiz'] ?? '—') ?></td>
                                                        <td><?= htmlspecialchars(retFmtData($rep['data_reprova'])) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Modal: Excluir Retorno -->
<div class="modal-overlay" id="ret-excluir-modal" style="display:none;">
    <div class="modal" style="max-width:420px;">
        <div class="modal-header">
            <span class="modal-title">Confirmar Exclusão</span>
            <button type="button" class="modal-close" id="ret-excluir-close">&times;</button>
        </div>
        <div class="modal-body">
            <p style="font-size:var(--font-size-base);color:var(--color-text-primary);margin:0 0 8px;">
                Deseja realmente excluir o retorno do transformador <strong id="ret-excluir-ns"></strong>?
            </p>
            <p style="font-size:var(--font-size-sm);color:var(--color-text-secondary);margin:0;">
                O transformador será removido da lista de retornos pendentes.
            </p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="ret-excluir-cancelar">Cancelar</button>
            <button type="button" class="btn btn-danger" id="ret-excluir-confirmar">Excluir Retorno</button>
        </div>
    </div>
</div>

<!-- Mini Modal: Decisão de Reprovação -->
<div class="modal-overlay" id="ret-reprovar-modal" style="display:none;">
    <div class="modal" style="max-width:420px;">
        <div class="modal-header">
            <span class="modal-title">Confirmar Reprovação</span>
            <button type="button" class="modal-close" id="ret-reprovar-close">&times;</button>
        </div>
        <div class="modal-body">
            <p style="font-size:var(--font-size-base);color:var(--color-text-primary);margin:0 0 16px;">
                O transformador <strong id="ret-reprovar-ns"></strong> será reprovado.<br>
                Qual é o tipo da reprovação?
            </p>
            <div style="display:flex; flex-direction:column; gap:10px;">
                <button type="button" class="btn btn-secondary btn-block" id="ret-btn-reincidencia">
                    É uma Reincidência (Mesmos motivos)
                </button>
                <button type="button" class="btn btn-danger btn-block" id="ret-btn-nova-reprova">
                    É uma Nova Reprova (Outros motivos)
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Grande: Nova Reprova -->
<div class="modal-overlay" id="ret-nova-reprova-modal" style="display:none;">
    <div class="modal" style="max-width:600px;">
        <form id="ret-rep-form">
            <div class="modal-header">
                <div>
                    <span class="modal-title" id="ret-rep-modal-title">Reprovação no Retorno</span>
                    <div class="card-subtitle">
                        NS <strong id="ret-rep-ns-display">—</strong> &middot; Projeto <strong id="ret-rep-projeto-display">—</strong>
                    </div>
                </div>
                <button type="button" class="modal-close" id="ret-nova-reprova-close">&times;</button>
            </div>
            <div class="modal-body" style="display:flex;flex-direction:column;gap:14px;">
                <div id="ret-rep-form-erro" style="display:none;" class="alert alert-danger"></div>
                
                <input type="hidden" id="ret-rep-id_projeto">
                <input type="hidden" id="ret-rep-ns">

                <div>
                    <label class="form-label font-600" style="text-transform:uppercase;font-size:11px;letter-spacing:.5px;margin-bottom:8px;">Motivos da Reprovação *</label>
                    <div id="ret-rep-reprovas-list" style="display:flex;flex-direction:column;gap:10px;"></div>
                    <button type="button" id="ret-rep-add-reprova" class="btn btn-secondary btn-sm" style="width:100%;margin-top:10px;justify-content:center;">
                        + Adicionar outra reprova
                    </button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="ret-nova-reprova-cancelar">Cancelar</button>
                <button type="submit" class="btn btn-danger" id="ret-nova-reprova-confirmar">Confirmar Reprovação</button>
            </div>
        </form>
    </div>
</div>

<!-- Modelo de bloco de reprova -->
<template id="ret-rep-reprova-template">
    <div class="lst-reprova-block">
        <div class="lst-reprova-head">
            <div class="lst-reprova-select-wrap">
                <label class="form-label" style="margin-bottom:4px;">Selecione a Reprova / Não Conformidade *</label>
                <div class="rep-search-combobox">
                    <input type="text" class="rep-search-input" placeholder="Buscar código ou descrição…" autocomplete="off" required>
                    <input type="hidden" class="rep-reprova-select" required>
                    <button type="button" class="rep-search-toggle" tabindex="-1" title="Abrir lista">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <div class="rep-search-dropdown" style="display:none;"></div>
                </div>
            </div>
            <button type="button" class="lst-reprova-remove" title="Remover esta reprova" style="display:none;">&times;</button>
        </div>
        <div class="rep-details-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px;padding-top:10px;border-top:1px dashed var(--color-border);font-size:var(--font-size-sm);color:var(--color-text-secondary);">
            <div><span style="color:var(--color-text-muted);">Família:</span> <strong class="rep-familia-field font-600">—</strong></div>
            <div><span style="color:var(--color-text-muted);">Local:</span> <strong class="rep-local-field font-600">—</strong></div>
        </div>
    </div>
</template>

<script>
    window.RETORNOS_API = <?= json_encode($base . '/api/retrabalho-acao.php') ?>;
    window.RETORNOS_REPROVAS_CATALOGO = <?= json_encode($reprovasCatalogo, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    window.RETORNOS_REPROVAS_ITENS = <?= json_encode($reprovasPorItem, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<?php $retJsVer = @filemtime(__DIR__ . '/../../assets/js/producao-retornos.js') ?: (defined('APP_VERSION') ? APP_VERSION : '1'); ?>
<script src="<?= htmlspecialchars($base) ?>/assets/js/producao-retornos.js?v=<?= htmlspecialchars((string) $retJsVer) ?>"></script>

<?php layoutFooter(); ?>
