<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireAcessoModulo('retrabalho');

$pdo  = getDB();
$base = defined('APP_URL') ? APP_URL : '';

// ─── Pedidos ─────────────────────────────────────────────────────────────────
$pedidos = $pdo->query("
    SELECT p.id, p.numero, p.prioridade, p.sequencia,
           COUNT(DISTINCT pr.id) AS qtd_projetos
    FROM pedidos p
    JOIN projetos pr    ON pr.id_pedido = p.id AND pr.deleted_at IS NULL
    JOIN retrabalhos r  ON r.id_projeto = pr.id AND r.deleted_at IS NULL
                        AND r.status IN ('agu_abertura', 'agu_causa_raiz')
    LEFT JOIN reprovas rep ON rep.id = r.id_reprova
    WHERE p.deleted_at IS NULL
      AND (rep.familia IS NULL OR rep.familia NOT IN ('PINTURA', 'SERIGRAFIA', 'CAMADA'))
      AND (r.setores_destino NOT LIKE '%pintura%' OR r.setores_destino IS NULL)
    GROUP BY p.id, p.numero, p.prioridade, p.sequencia
    ORDER BY p.id DESC
")->fetchAll();

// ─── Projetos ────────────────────────────────────────────────────────────────
$projetos = $pdo->query("
    SELECT pr.id, pr.codigo, pr.descricao, pr.prioridade, pr.sequencia,
           ped.numero AS pedido_numero
    FROM projetos pr
    JOIN pedidos ped ON ped.id = pr.id_pedido
    JOIN retrabalhos r ON r.id_projeto = pr.id AND r.deleted_at IS NULL AND r.status IN ('agu_abertura', 'agu_causa_raiz')
    LEFT JOIN reprovas rep ON rep.id = r.id_reprova
    WHERE pr.deleted_at IS NULL
      AND (rep.familia IS NULL OR rep.familia NOT IN ('PINTURA', 'SERIGRAFIA', 'CAMADA'))
      AND (r.setores_destino NOT LIKE '%pintura%' OR r.setores_destino IS NULL)
    GROUP BY pr.id, pr.codigo, pr.descricao, pr.prioridade, pr.sequencia, ped.numero
    ORDER BY pr.id DESC
")->fetchAll();

// ─── N° de Série ─────────────────────────────────────────────────────────────
$registrosNs = $pdo->query("
    SELECT r.*,
           pr.codigo AS projeto_codigo, pr.descricao AS projeto_descricao, pr.id_pedido AS id_pedido,
           ped.numero AS pedido_numero,
           rep.codigo AS reprova_codigo, rep.descricao AS reprova_descricao
    FROM retrabalhos r
    LEFT JOIN projetos pr  ON pr.id  = r.id_projeto
    LEFT JOIN pedidos ped  ON ped.id = pr.id_pedido
    LEFT JOIN reprovas rep ON rep.id = r.id_reprova
    WHERE r.deleted_at IS NULL AND r.status IN ('agu_abertura', 'agu_causa_raiz')
      AND (rep.familia IS NULL OR rep.familia NOT IN ('PINTURA', 'SERIGRAFIA', 'CAMADA'))
      AND (r.setores_destino NOT LIKE '%pintura%' OR r.setores_destino IS NULL)
")->fetchAll();

$qtdPorNs = $pdo->query("
    SELECT ns_transformador, COUNT(DISTINCT COALESCE(id_lote, id)) AS qtd
    FROM retrabalhos
    WHERE deleted_at IS NULL
    GROUP BY ns_transformador
")->fetchAll(PDO::FETCH_KEY_PAIR);

$hoje = new DateTime('today');
$gruposNs = [];
foreach ($registrosNs as $r) {
    $ns = (string) $r['ns_transformador'];
    if ($ns === '') $ns = 's_ns_' . $r['id'];

    if (!isset($gruposNs[$ns])) {
        [$potencia, $classe] = parsePotenciaClasse($r['projeto_descricao'] ?? null);
        $gruposNs[$ns] = [
            'ns_transformador'  => $r['ns_transformador'],
            'projeto_codigo'    => $r['projeto_codigo'],
            'projeto_descricao' => $r['projeto_descricao'],
            'pedido_numero'     => $r['pedido_numero'],
            'potencia'          => $potencia,
            'classe'            => $classe,
            'prioridade'        => $r['prioridade'],
            'sequencia'         => $r['sequencia'],
            'itens'             => [],
            '_dias'             => -1,
            '_reincidencias'    => $qtdPorNs[$ns] ?? 1,
            'min_data'          => null,
        ];
    }
    
    $g = &$gruposNs[$ns];
    $g['itens'][] = $r;
    
    $baseData = $r['data_inicio'] ?? $r['data_reprova'] ?? $r['created_at'];
    if ($baseData) {
        $ini = new DateTime(substr((string) $baseData, 0, 10));
        $d = diasUteisEntre($ini, $hoje);
        if ($d > $g['_dias']) $g['_dias'] = $d;
        
        $ts = strtotime($baseData);
        if ($g['min_data'] === null || $ts < $g['min_data']) {
            $g['min_data'] = $ts;
        }
    }
    unset($g);
}

// FIFO: oldest min_data first
usort($gruposNs, function($a, $b) {
    $rankA = ($a['prioridade'] !== 'neutro') ? (['emergente'=>4,'urgente'=>3,'importante'=>2,'neutro'=>1][$a['prioridade']] ?? 1) : 0;
    $rankB = ($b['prioridade'] !== 'neutro') ? (['emergente'=>4,'urgente'=>3,'importante'=>2,'neutro'=>1][$b['prioridade']] ?? 1) : 0;
    
    if ($rankA !== $rankB) return $rankB <=> $rankA; // DESC
    if ($rankA > 0 && $rankA === $rankB) {
        if ($a['sequencia'] != $b['sequencia']) return $a['sequencia'] <=> $b['sequencia']; // ASC
    }
    
    // Fallback: FIFO (oldest first)
    return ($a['min_data'] ?? PHP_INT_MAX) <=> ($b['min_data'] ?? PHP_INT_MAX);
});

$prioridades = pedidoPrioridades();

$pageTitle = 'Prioridades';
require_once __DIR__ . '/../../includes/layout.php';
layoutHeader($pageTitle);
?>

<style>
    .pp-card { background:var(--color-surface,#fff); border:1px solid var(--color-border,#e5e7eb); border-radius:var(--radius-lg,10px); padding:16px 18px; }
    .pp-table { width:100%; border-collapse:collapse; font-size:13px; }
    .pp-table th { text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.6px; color:var(--color-text-muted,#6b7280); padding:8px 10px; border-bottom:1px solid var(--color-border,#e5e7eb); white-space:nowrap; }
    .pp-table td { padding:10px; border-bottom:1px solid var(--color-border,#f1f5f9); vertical-align:middle; }
    .pp-table tr:hover td { background:var(--color-surface-2,#f9fafb); }
    
    /* Subtable stacked cells style */
    .ns-cell-stacked { padding:0 !important; }
    .ns-stacked-item { padding:9px 10px; border-bottom:1px solid #eef1f5; display:flex; align-items:center; min-height:42px; }
    .ns-stacked-item:last-child { border-bottom:none; }
    
    .pp-code { font-family:'JetBrains Mono',monospace; font-weight:600; color:var(--color-text-primary,#111827); }
    .pp-badge { display:inline-block; padding:2px 9px; border-radius:9999px; font-size:11px; font-weight:600; background:#eef2ff; color:#4338ca; }
    .pp-chips { display:flex; flex-wrap:nowrap; gap:6px; align-items:center; }
    .pp-chip {
        display:inline-flex; align-items:center; gap:6px; padding:5px 12px;
        border:1px solid var(--color-border-strong,#d1d5db); border-radius:9999px;
        font-size:12px; font-weight:600; cursor:pointer; user-select:none; background:#fff; color:var(--color-text-secondary,#5a6480);
        transition:opacity .15s;
    }
    .pp-chip:hover { opacity:.85; }
    .pp-chip.active { border-color:transparent; }
    .pp-chip[data-saving="1"] { opacity:.5; pointer-events:none; }
    .pp-seq-input { width:54px; padding:4px 8px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; text-align:center; }
    .pp-empty { text-align:center; padding:30px 16px; color:var(--color-text-muted,#6b7280); font-size:13px; }

    /* Tabs & Toolbar */
    .pp-header-row { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px; margin-bottom:16px; }
    .rt-tabs { display:flex; flex-wrap:wrap; gap:8px; }
    .rt-tab {
        display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border-radius:9999px;
        font-size:13px; font-weight:600; text-decoration:none; border:1px solid var(--color-border,#e5e7eb);
        background:#fff; color:var(--color-text-secondary,#5a6480); cursor:pointer; transition:all .15s;
    }
    .rt-tab:hover { text-decoration:none; border-color:#E89B1C; color:#E89B1C; }
    .rt-tab.active { background:#E89B1C; border-color:#E89B1C; color:#0e2c1d; }
    .rt-tab-count { font-size:11px; padding:1px 6px; border-radius:9999px; background:rgba(0,0,0,0.08); font-weight:700; }
    .rt-tab.active .rt-tab-count { background:#0e2c1d; color:#fff; }
    
    /* Filtro Dinâmico */
    .pp-filter-toolbar {
        display:flex; flex-wrap:wrap; align-items:center; gap:10px; margin-bottom:14px;
        background:#fff; padding:10px 14px; border:1px solid var(--color-border,#e5e7eb); border-radius:10px;
    }
    .pp-search-box { position:relative; flex:1; min-width:260px; }
    .pp-search-icon {
        position:absolute; left:11px; top:50%; transform:translateY(-50%); width:16px; height:16px;
        color:var(--color-text-muted,#9ca3af); pointer-events:none;
    }
    .pp-search-input {
        width:100%; padding:8px 32px 8px 34px; border:1px solid var(--color-border,#d1d5db); border-radius:8px;
        font-size:13px; background:#f9fafb; transition:border-color .15s, background .15s, box-shadow .15s;
    }
    .pp-search-input:focus {
        outline:none; border-color:#E89B1C; background:#fff; box-shadow:0 0 0 3px rgba(232,155,28,0.15);
    }
    .pp-search-clear {
        position:absolute; right:8px; top:50%; transform:translateY(-50%); width:20px; height:20px;
        border:none; background:#e5e7eb; color:#6b7280; border-radius:50%; font-size:13px; line-height:1;
        cursor:pointer; display:none; align-items:center; justify-content:center;
    }
    .pp-search-clear:hover { background:#d1d5db; color:#111827; }
    
    .pp-filter-select {
        padding:8px 12px; border:1px solid var(--color-border,#d1d5db); border-radius:8px;
        font-size:13px; background:#fff; color:#374151; cursor:pointer; min-width:160px;
    }
    .pp-filter-select:focus { outline:none; border-color:#E89B1C; box-shadow:0 0 0 3px rgba(232,155,28,0.15); }
    
    .pp-counter-info {
        font-size:12px; font-weight:600; color:var(--color-text-secondary,#6b7280); white-space:nowrap;
        padding-left:4px;
    }
    
    .tab-pane { display: none; }
    .tab-pane.active { display: block; }
</style>

<!-- Cabeçalho -->
<div style="margin-bottom:18px;">
    <h1 style="font-size:var(--font-size-xl,20px);font-weight:700;">Prioridades</h1>
    <p class="text-secondary" style="font-size:13px;color:var(--color-text-secondary,#6b7280);margin-top:2px;">
        Defina a prioridade e sequência por Pedido, Projeto ou N° de Série. A regra mais específica sempre vence.
    </p>
</div>

<!-- Abas e Contadores -->
<div class="pp-header-row">
    <div class="rt-tabs">
        <button type="button" class="rt-tab active" data-tab="tab-pedidos">
            Pedidos <span class="rt-tab-count" id="count-tab-pedidos"><?= count($pedidos) ?></span>
        </button>
        <button type="button" class="rt-tab" data-tab="tab-projetos">
            Projetos <span class="rt-tab-count" id="count-tab-projetos"><?= count($projetos) ?></span>
        </button>
        <button type="button" class="rt-tab" data-tab="tab-ns">
            N° de Série <span class="rt-tab-count" id="count-tab-ns"><?= count($gruposNs) ?></span>
        </button>
    </div>
</div>

<!-- Barra de Filtro Dinâmico em Tempo Real -->
<div class="pp-filter-toolbar">
    <div class="pp-search-box">
        <svg class="pp-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
        <input type="text" id="pp-search-input" class="pp-search-input" placeholder="Filtrar por Pedido, Projeto, N° de Série, Descrição ou Contenção..." autocomplete="off">
        <button type="button" id="pp-search-clear" class="pp-search-clear" title="Limpar busca">&times;</button>
    </div>
    
    <select id="pp-priority-filter" class="pp-filter-select">
        <option value="">Todas as prioridades</option>
        <?php foreach ($prioridades as $slug => $info): ?>
            <option value="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($info['label']) ?></option>
        <?php endforeach; ?>
    </select>
    
    <div class="pp-counter-info" id="pp-counter-info"></div>
</div>

<!-- Aba: Pedidos -->
<div id="tab-pedidos" class="tab-pane active pp-card">
    <div style="overflow-x:auto;">
        <table class="pp-table" id="table-pedidos">
            <thead>
                <tr>
                    <th style="width:1%; white-space:nowrap;">Prioridade</th>
                    <th style="width:1%; white-space:nowrap;">Pedido</th>
                    <th style="width:1%; white-space:nowrap; text-align:center;">Projetos</th>
                    <th style="width:100%;"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$pedidos): ?>
                    <tr class="pp-empty-row"><td colspan="4"><div class="pp-empty">Nenhum pedido com retrabalhos abertos.</div></td></tr>
                <?php else: foreach ($pedidos as $p): ?>
                    <tr class="pp-row" data-search="<?= htmlspecialchars(mb_strtolower($p['numero'] . ' ' . $p['prioridade'])) ?>" data-prioridade="<?= htmlspecialchars($p['prioridade']) ?>">
                        <td>
                            <div class="pp-chips" data-acao="pedido_prioridade" data-id="<?= (int) $p['id'] ?>">
                                <?php foreach ($prioridades as $slug => $info): $ativo = $p['prioridade'] === $slug; ?>
                                    <span class="pp-chip js-prioridade-chip<?= $ativo ? ' active' : '' ?>"
                                          data-slug="<?= htmlspecialchars($slug) ?>"
                                          style="<?= $ativo ? 'background:' . $info['bg'] . ';color:' . $info['fg'] . ';' : '' ?>">
                                        <?= htmlspecialchars($info['label']) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td style="width:1%; white-space:nowrap;"><span class="pp-code"><?= htmlspecialchars($p['numero']) ?></span></td>
                        <td style="width:1%; white-space:nowrap; text-align:center;"><span class="pp-badge"><?= (int) $p['qtd_projetos'] ?></span></td>
                        <td></td>
                    </tr>
                <?php endforeach; endif; ?>
                <tr class="pp-no-results-row" style="display:none;"><td colspan="4"><div class="pp-empty">Nenhum pedido encontrado para o filtro.</div></td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Aba: Projetos -->
<div id="tab-projetos" class="tab-pane pp-card">
    <div style="overflow-x:auto;">
        <table class="pp-table" id="table-projetos">
            <thead>
                <tr>
                    <th style="width:1%; white-space:nowrap;">Prioridade</th>
                    <th style="width:1%; white-space:nowrap;">Projeto</th>
                    <th style="width:1%; white-space:nowrap;">Descrição</th>
                    <th style="width:1%; white-space:nowrap;">Pedido</th>
                    <th style="width:100%;"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$projetos): ?>
                    <tr class="pp-empty-row"><td colspan="5"><div class="pp-empty">Nenhum projeto com retrabalhos abertos.</div></td></tr>
                <?php else: foreach ($projetos as $pr): ?>
                    <tr class="pp-row" data-search="<?= htmlspecialchars(mb_strtolower($pr['codigo'] . ' ' . ($pr['descricao'] ?? '') . ' ' . $pr['pedido_numero'] . ' ' . $pr['prioridade'])) ?>" data-prioridade="<?= htmlspecialchars($pr['prioridade']) ?>">
                        <td>
                            <div class="pp-chips" data-acao="projeto_prioridade" data-id="<?= (int) $pr['id'] ?>">
                                <?php foreach ($prioridades as $slug => $info): $ativo = $pr['prioridade'] === $slug; ?>
                                    <span class="pp-chip js-prioridade-chip<?= $ativo ? ' active' : '' ?>"
                                          data-slug="<?= htmlspecialchars($slug) ?>"
                                          style="<?= $ativo ? 'background:' . $info['bg'] . ';color:' . $info['fg'] . ';' : '' ?>">
                                        <?= htmlspecialchars($info['label']) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td style="width:1%; white-space:nowrap;"><span class="pp-code"><?= htmlspecialchars($pr['codigo']) ?></span></td>
                        <td style="width:1%; white-space:nowrap;"><?= htmlspecialchars($pr['descricao'] ?? '—') ?></td>
                        <td style="width:1%; white-space:nowrap;"><?= htmlspecialchars($pr['pedido_numero']) ?></td>
                        <td></td>
                    </tr>
                <?php endforeach; endif; ?>
                <tr class="pp-no-results-row" style="display:none;"><td colspan="5"><div class="pp-empty">Nenhum projeto encontrado para o filtro.</div></td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Aba: N° de Série -->
<div id="tab-ns" class="tab-pane pp-card">
    <div style="overflow-x:auto;">
        <table class="pp-table" id="table-ns">
            <thead>
                <tr>
                    <th style="width:1%; white-space:nowrap;">Prioridade</th>
                    <th style="width:1%; white-space:nowrap;">N° Série</th>
                    <th style="width:1%; white-space:nowrap;">Projeto</th>
                    <th style="width:1%; white-space:nowrap; text-align:center;">Potência</th>
                    <th style="width:1%; white-space:nowrap; text-align:center;">Classe</th>
                    <th style="width:1%; white-space:nowrap;">Contenção</th>
                    <th style="width:1%; white-space:nowrap; text-align:center;">Parado há</th>
                    <th style="width:1%; white-space:nowrap; text-align:center;">Data Reprova</th>
                    <th style="width:1%; white-space:nowrap; text-align:center;">Reincidências</th>
                    <th style="width:100%;"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$gruposNs): ?>
                    <tr class="pp-empty-row"><td colspan="10"><div class="pp-empty">Nenhum N° de série em retrabalho.</div></td></tr>
                <?php else: foreach ($gruposNs as $g):
                    $repText = [];
                    foreach ($g['itens'] as $r) {
                        $repText[] = ($r['reprova_codigo'] ?? '') . ' ' . ($r['reprova_descricao'] ?? '');
                    }
                    $searchText = mb_strtolower($g['ns_transformador'] . ' ' . ($g['projeto_codigo'] ?? '') . ' ' . ($g['projeto_descricao'] ?? '') . ' ' . ($g['pedido_numero'] ?? '') . ' ' . implode(' ', $repText) . ' ' . $g['prioridade']);
                ?>
                    <tr class="pp-row" data-search="<?= htmlspecialchars($searchText) ?>" data-prioridade="<?= htmlspecialchars($g['prioridade']) ?>">
                        <td style="width:1%; white-space:nowrap;">
                            <div class="pp-chips" data-acao="ns_prioridade" data-ns="<?= htmlspecialchars($g['ns_transformador']) ?>">
                                <?php foreach ($prioridades as $slug => $info): $ativo = $g['prioridade'] === $slug; ?>
                                    <span class="pp-chip js-prioridade-chip<?= $ativo ? ' active' : '' ?>"
                                          data-slug="<?= htmlspecialchars($slug) ?>"
                                          style="<?= $ativo ? 'background:' . $info['bg'] . ';color:' . $info['fg'] . ';' : '' ?>">
                                        <?= htmlspecialchars($info['label']) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td style="width:1%; white-space:nowrap;"><span class="pp-code" style="font-weight:700;color:#111827;"><?= htmlspecialchars($g['ns_transformador']) ?></span></td>
                        <td style="width:1%; white-space:nowrap;"><span class="pp-code"><?= htmlspecialchars($g['projeto_codigo'] ?? '—') ?></span></td>
                        <td style="width:1%; white-space:nowrap; text-align:center;"><?= $g['potencia'] !== null ? htmlspecialchars($g['potencia']) . ' kVA' : '—' ?></td>
                        <td style="width:1%; white-space:nowrap; text-align:center;"><?= $g['classe'] !== null ? htmlspecialchars($g['classe']) . ' kV' : '—' ?></td>
                        
                        <td style="width:1%; white-space:nowrap;">
                            <?php foreach ($g['itens'] as $r): ?>
                                <div class="ns-stacked-item">
                                    <span class="pp-code" style="font-weight:500;margin-right:8px;"><?= htmlspecialchars($r['reprova_codigo'] ?? '—') ?></span>
                                    <span style="font-size:11px;color:#6b7280;white-space:nowrap;"><?= htmlspecialchars($r['reprova_descricao'] ?? '') ?></span>
                                </div>
                            <?php endforeach; ?>
                        </td>
                        
                        <td style="width:1%; white-space:nowrap; text-align:center; font-weight:600;">
                            <?= $g['_dias'] >= 0 ? $g['_dias'] . 'd' : '—' ?>
                        </td>
                        
                        <td class="ns-cell-stacked" style="width:1%; white-space:nowrap; text-align:center;">
                            <?php foreach ($g['itens'] as $r): ?>
                                <div class="ns-stacked-item" style="justify-content:center; font-size:12px;">
                                    <?php
                                        $ts = strtotime($r['data_reprova'] ?? '');
                                        echo $ts ? date('d/m/y', $ts) : '—';
                                    ?>
                                </div>
                            <?php endforeach; ?>
                        </td>
                        
                        <td style="width:1%; white-space:nowrap; text-align:center;">
                            <?php if ($g['_reincidencias'] > 1): ?>
                                <span class="pp-badge" style="background:#fef2f2;color:#dc2626;"><?= (int) $g['_reincidencias'] ?></span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td></td>
                    </tr>
                <?php endforeach; endif; ?>
                <tr class="pp-no-results-row" style="display:none;"><td colspan="10"><div class="pp-empty">Nenhum N° de série encontrado para o filtro.</div></td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
    window.PROJETOS_API = <?= json_encode($base . '/api/projetos-acao.php') ?>;
    window.PRIORIDADES_INFO = <?= json_encode($prioridades, JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php $ppJsVer = @filemtime(__DIR__ . '/../../assets/js/pedidos-prioridade.js') ?: (defined('APP_VERSION') ? APP_VERSION : '1'); ?>
<script src="<?= htmlspecialchars($base) ?>/assets/js/pedidos-prioridade.js?v=<?= htmlspecialchars((string) $ppJsVer) ?>"></script>

<?php layoutFooter(); ?>