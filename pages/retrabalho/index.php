<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';

requireLogin();

$pdo  = getDB();
$base = defined('APP_URL') ? APP_URL : '';

// ─── Filtros (GET) ────────────────────────────────────────────────────────────
$fStatus  = trim((string) ($_GET['status'] ?? ''));
$fLocal   = trim((string) ($_GET['local'] ?? ''));
$fPeriodo = trim((string) ($_GET['periodo'] ?? 'todos'));
$fBusca   = trim((string) ($_GET['busca'] ?? ''));

$STATUS_VALIDOS = ['agu_abertura', 'agu_causa_raiz', 'finalizado'];
$LOCAIS_VALIDOS = ['IQF', 'LAB', 'GER'];
$PERIODOS       = ['hoje', 'semana', 'mes', 'todos'];
if (!in_array($fPeriodo, $PERIODOS, true)) $fPeriodo = 'todos';

// Data de referência do lançamento: reprova → início → created_at
$dataExpr = 'DATE(COALESCE(r.data_reprova, r.data_inicio, r.created_at))';

$where  = ['r.deleted_at IS NULL'];
$params = [];

if (in_array($fStatus, $STATUS_VALIDOS, true))   { $where[] = 'r.status = ?';      $params[] = $fStatus; }
if (in_array($fLocal, $LOCAIS_VALIDOS, true))    { $where[] = 'rep.local = ?';     $params[] = $fLocal; }

if ($fPeriodo === 'hoje')   { $where[] = "$dataExpr = CURDATE()"; }
if ($fPeriodo === 'semana') { $where[] = "$dataExpr >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)"; }
if ($fPeriodo === 'mes')    { $where[] = "$dataExpr >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"; }

if ($fBusca !== '') {
    $where[] = '(pr.codigo LIKE ? OR ped.numero LIKE ? OR r.ns_transformador LIKE ? OR rep.codigo LIKE ? OR rep.descricao LIKE ?)';
    $like = '%' . $fBusca . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

$whereSql = implode(' AND ', $where);

// ─── Registros ────────────────────────────────────────────────────────────────
$sql = "
    SELECT r.*,
           u.nome    AS responsavel_nome,
           pr.codigo AS projeto_codigo, pr.descricao AS projeto_descricao, pr.id_pedido AS id_pedido,
           ped.numero AS pedido_numero,
           rep.codigo AS reprova_codigo, rep.familia AS reprova_familia,
           rep.descricao AS reprova_descricao, rep.local AS reprova_local
    FROM retrabalhos r
    LEFT JOIN usuarios u   ON u.id   = r.id_responsavel
    LEFT JOIN projetos pr  ON pr.id  = r.id_projeto
    LEFT JOIN pedidos ped  ON ped.id = pr.id_pedido
    LEFT JOIN reprovas rep ON rep.id = r.id_reprova
    WHERE $whereSql
    ORDER BY COALESCE(r.data_reprova, r.data_inicio, DATE(r.created_at)) DESC, r.id DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$registros = $stmt->fetchAll();

// ─── KPIs (visão global, não filtrada) ────────────────────────────────────────
$kpi = $pdo->query("
    SELECT
        COALESCE(SUM(status IN ('agu_abertura','agu_causa_raiz')), 0) AS em_aberto,
        COALESCE(SUM(status = 'finalizado' AND DATE(concluido_em) = CURDATE()), 0) AS concl_hoje,
        COALESCE(SUM(DATE(COALESCE(data_reprova, data_inicio, created_at)) >= DATE_FORMAT(CURDATE(), '%Y-%m-01')), 0) AS total_mes,
        COUNT(DISTINCT CASE WHEN status IN ('agu_abertura','agu_causa_raiz') THEN ns_transformador END) AS ns_abertos
    FROM retrabalhos
    WHERE deleted_at IS NULL
")->fetch() ?: ['em_aberto' => 0, 'concl_hoje' => 0, 'total_mes' => 0, 'ns_abertos' => 0];

// ─── Distribuições (gráficos) — sobre o conjunto filtrado ─────────────────────
$porFamilia = [];
$porLocal   = [];
foreach ($registros as $r) {
    $f = $r['reprova_familia'] ?: '—';
    $l = $r['reprova_local']   ?: '—';
    $porFamilia[$f] = ($porFamilia[$f] ?? 0) + 1;
    $porLocal[$l]   = ($porLocal[$l]   ?? 0) + 1;
}
arsort($porFamilia);
arsort($porLocal);

// ─── Listas auxiliares (selects do formulário/filtros) ────────────────────────
// Obs.: o responsável = usuário logado é definido no servidor (não aparece no formulário).
$pedidos  = $pdo->query("SELECT id, numero FROM pedidos WHERE deleted_at IS NULL ORDER BY numero")->fetchAll();
$projetos = $pdo->query("SELECT id, codigo, descricao, id_pedido FROM projetos WHERE deleted_at IS NULL ORDER BY codigo")->fetchAll();
$reprovas = $pdo->query("SELECT id, codigo, familia, descricao, local FROM reprovas WHERE ativo = 1 ORDER BY ordem, codigo")->fetchAll();

// ─── Helpers de exibição ──────────────────────────────────────────────────────
$statusMap = [
    'agu_abertura'   => ['label' => 'Agu. Abertura',   'bg' => '#fef2f2', 'fg' => '#dc2626'],
    'agu_causa_raiz' => ['label' => 'Agu. Causa Raiz', 'bg' => '#fffbeb', 'fg' => '#d97706'],
    'finalizado'     => ['label' => 'Finalizado',      'bg' => '#ecfdf5', 'fg' => '#16a34a'],
];
$localMap = [
    'IQF' => ['label' => 'IQF', 'title' => 'Inspeção final', 'bg' => '#eff6ff', 'fg' => '#2563eb'],
    'LAB' => ['label' => 'LAB', 'title' => 'Laboratório',    'bg' => '#f5f3ff', 'fg' => '#7c3aed'],
    'GER' => ['label' => 'GER', 'title' => 'Geral',          'bg' => '#f0fdf4', 'fg' => '#16a34a'],
];
function fmtDataBR(?string $iso): string
{
    if (!$iso) return '—';
    $ts = strtotime($iso);
    return $ts ? date('d/m/Y', $ts) : '—';
}

$pageTitle = 'Painel de Retrabalho';
require_once __DIR__ . '/../../includes/layout.php';
layoutHeader($pageTitle);
?>

<style>
    .rt-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:14px; margin-bottom:20px; }
    .rt-kpi { background:var(--color-surface,#fff); border:1px solid var(--color-border,#e5e7eb); border-radius:var(--radius-lg,10px); padding:16px 18px; }
    .rt-kpi-val { font-size:28px; font-weight:700; line-height:1; color:var(--color-text-primary,#111827); }
    .rt-kpi-lbl { font-size:12px; color:var(--color-text-muted,#6b7280); margin-top:6px; text-transform:uppercase; letter-spacing:.4px; }
    .rt-grid { display:grid; grid-template-columns:1.3fr 1fr; gap:16px; margin-bottom:20px; }
    @media (max-width:900px){ .rt-grid{ grid-template-columns:1fr; } }
    .rt-card { background:var(--color-surface,#fff); border:1px solid var(--color-border,#e5e7eb); border-radius:var(--radius-lg,10px); padding:16px 18px; }
    .rt-card h3 { font-size:14px; font-weight:600; margin-bottom:14px; color:var(--color-text-primary,#111827); }
    .rt-chart-wrap { position:relative; height:280px; width:100%; }
    .rt-chart-wrap canvas { display:block; }
    .rt-filtros { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:14px; }
    .rt-filtros select, .rt-filtros input { padding:8px 10px; border:1px solid var(--color-border,#d1d5db); border-radius:8px; font-size:13px; background:#fff; }
    .rt-table { width:100%; border-collapse:collapse; font-size:13px; }
    .rt-table th { text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.6px; color:var(--color-text-muted,#6b7280); padding:8px 10px; border-bottom:1px solid var(--color-border,#e5e7eb); white-space:nowrap; }
    .rt-table td { padding:9px 10px; border-bottom:1px solid var(--color-border,#f1f5f9); vertical-align:middle; }
    .rt-table tr:hover td { background:var(--color-surface-2,#f9fafb); }
    .rt-code { font-family:'JetBrains Mono',monospace; font-weight:600; }
    .rt-badge { display:inline-block; padding:2px 9px; border-radius:9999px; font-size:11px; font-weight:600; }
    .rt-btn-acc { background:#E89B1C; color:#0e2c1d; border:none; padding:9px 16px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; }
    .rt-btn-acc:hover { opacity:.92; }
    .rt-row-act { background:none; border:1px solid var(--color-border,#e5e7eb); border-radius:6px; padding:4px 8px; font-size:11px; cursor:pointer; color:var(--color-text-secondary,#374151); }
    .rt-row-act:hover { background:var(--color-surface-2,#f3f4f6); }
    .rt-row-act.danger:hover { background:#fef2f2; color:#dc2626; border-color:#fecaca; }
    /* Modal */
    .modal-overlay { position:fixed; inset:0; background:rgba(15,23,42,.5); display:none; align-items:flex-start; justify-content:center; z-index:1000; padding:30px 16px; overflow-y:auto; }
    .modal-box { background:#fff; border-radius:14px; width:100%; max-width:660px; box-shadow:0 20px 50px rgba(0,0,0,.3); }
    .modal-head { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #e5e7eb; }
    .modal-head h2 { font-size:16px; font-weight:700; }
    .modal-close { background:none; border:none; font-size:22px; line-height:1; cursor:pointer; color:#9ca3af; }
    .modal-body { padding:18px 20px; }
    .rt-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
    .rt-form-grid .full { grid-column:1 / -1; }
    .rt-section { grid-column:1 / -1; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.7px; color:#1a3d2a; margin:4px 0 -4px; display:flex; align-items:center; gap:10px; }
    .rt-section::after { content:""; flex:1; height:1px; background:#e5e7eb; }
    .rt-field label { display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:4px; }
    .rt-field input, .rt-field select, .rt-field textarea { width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:8px; font-size:13px; }
    .rt-field textarea { resize:vertical; min-height:60px; }
    .rt-field.auto input { background:#f3f6f4; color:#374151; border-style:dashed; }
    .rt-hint { font-size:11px; color:#6b7280; margin-top:4px; }
    .modal-foot { display:flex; justify-content:flex-end; gap:10px; padding:16px 20px; border-top:1px solid #e5e7eb; }
    .rt-btn-secondary { background:#fff; border:1px solid #d1d5db; border-radius:8px; padding:9px 16px; font-size:13px; font-weight:600; cursor:pointer; }
    .rt-empty { text-align:center; padding:36px 16px; color:var(--color-text-muted,#6b7280); font-size:13px; }
</style>

<!-- Cabeçalho -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:22px;">
    <div>
        <h1 style="font-size:var(--font-size-xl,20px);font-weight:700;">Painel de Retrabalho</h1>
        <p class="text-secondary" style="font-size:13px;color:var(--color-text-secondary,#6b7280);margin-top:2px;">
            Registro e acompanhamento de retrabalhos de transformadores
        </p>
    </div>
    <button type="button" class="rt-btn-acc" id="btn-novo-retrabalho">+ Registrar um retrabalho</button>
</div>

<!-- KPIs -->
<div class="rt-kpis">
    <div class="rt-kpi">
        <div class="rt-kpi-val" style="color:#dc2626;"><?= (int) $kpi['em_aberto'] ?></div>
        <div class="rt-kpi-lbl">Em Aberto</div>
    </div>
    <div class="rt-kpi">
        <div class="rt-kpi-val" style="color:#16a34a;"><?= (int) $kpi['concl_hoje'] ?></div>
        <div class="rt-kpi-lbl">Finalizadas Hoje</div>
    </div>
    <div class="rt-kpi">
        <div class="rt-kpi-val"><?= (int) $kpi['total_mes'] ?></div>
        <div class="rt-kpi-lbl">No Mês</div>
    </div>
    <div class="rt-kpi">
        <div class="rt-kpi-val" style="color:#E89B1C;"><?= (int) $kpi['ns_abertos'] ?></div>
        <div class="rt-kpi-lbl">Transformadores em Aberto</div>
    </div>
</div>

<!-- Gráficos -->
<div class="rt-grid">
    <div class="rt-card">
        <h3>Ranking por família de contenção</h3>
        <?php if ($porFamilia): ?>
            <div class="rt-chart-wrap"><canvas id="chartRanking"></canvas></div>
        <?php else: ?>
            <p class="rt-empty">Sem dados para o filtro atual.</p>
        <?php endif; ?>
    </div>
    <div class="rt-card">
        <h3>Distribuição por local (IQF / LAB / GER)</h3>
        <?php if ($porLocal): ?>
            <div class="rt-chart-wrap"><canvas id="chartDonut"></canvas></div>
        <?php else: ?>
            <p class="rt-empty">Sem dados para o filtro atual.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Tabela de registros -->
<div class="rt-card">
    <h3 style="margin-bottom:14px;">Retrabalhos registrados</h3>

    <!-- Filtros -->
    <form method="GET" class="rt-filtros" id="rt-filtros">
        <select name="periodo" onchange="this.form.submit()">
            <option value="todos"  <?= $fPeriodo === 'todos'  ? 'selected' : '' ?>>Todo período</option>
            <option value="hoje"   <?= $fPeriodo === 'hoje'   ? 'selected' : '' ?>>Hoje</option>
            <option value="semana" <?= $fPeriodo === 'semana' ? 'selected' : '' ?>>Últimos 7 dias</option>
            <option value="mes"    <?= $fPeriodo === 'mes'    ? 'selected' : '' ?>>Este mês</option>
        </select>
        <select name="local" onchange="this.form.submit()">
            <option value="">Todos os locais</option>
            <?php foreach ($localMap as $k => $info): ?>
                <option value="<?= $k ?>" <?= $fLocal === $k ? 'selected' : '' ?>><?= $info['label'] ?> — <?= $info['title'] ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" onchange="this.form.submit()">
            <option value="">Todos os status</option>
            <?php foreach ($statusMap as $k => $info): ?>
                <option value="<?= $k ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= $info['label'] ?></option>
            <?php endforeach; ?>
        </select>
        <input type="search" name="busca" value="<?= htmlspecialchars($fBusca) ?>" placeholder="Buscar projeto, pedido, NS, reprova…" style="min-width:220px;">
        <button type="submit" class="rt-btn-secondary">Filtrar</button>
        <?php if ($fStatus || $fLocal || $fBusca || $fPeriodo !== 'todos'): ?>
            <a href="<?= htmlspecialchars($base) ?>/pages/retrabalho/index.php" class="rt-row-act" style="text-decoration:none;">Limpar</a>
        <?php endif; ?>
    </form>

    <div style="overflow-x:auto;">
        <table class="rt-table">
            <thead>
                <tr>
                    <th>Data reprova</th><th>Pedido</th><th>Projeto</th><th>N° série</th>
                    <th>Contenção</th><th>Família</th><th>Local</th>
                    <th>Responsável</th><th>Início</th><th>Finalização</th><th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$registros): ?>
                    <tr><td colspan="11"><div class="rt-empty">Nenhum retrabalho encontrado.</div></td></tr>
                <?php else: foreach ($registros as $r):
                    $st = $statusMap[$r['status']] ?? $statusMap['agu_abertura'];
                    $lo = $localMap[$r['reprova_local']] ?? null;
                ?>
                    <tr>
                        <td style="white-space:nowrap;"><?= htmlspecialchars(fmtDataBR($r['data_reprova'])) ?></td>
                        <td><span class="rt-code" style="font-weight:500;"><?= htmlspecialchars($r['pedido_numero'] ?? '—') ?></span></td>
                        <td>
                            <span class="rt-code"><?= htmlspecialchars($r['projeto_codigo'] ?? '—') ?></span>
                            <?php if (!empty($r['projeto_descricao'])): ?>
                                <div style="font-size:11px;color:#6b7280;"><?= htmlspecialchars($r['projeto_descricao']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="rt-code"><?= htmlspecialchars($r['ns_transformador'] ?? '—') ?></span></td>
                        <td>
                            <span class="rt-code" style="font-weight:500;"><?= htmlspecialchars($r['reprova_codigo'] ?? '—') ?></span>
                            <?php if (!empty($r['reprova_descricao'])): ?>
                                <div style="font-size:11px;color:#6b7280;"><?= htmlspecialchars($r['reprova_descricao']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px;"><?= htmlspecialchars($r['reprova_familia'] ?? '—') ?></td>
                        <td>
                            <?php if ($lo): ?>
                                <span class="rt-badge" style="background:<?= $lo['bg'] ?>;color:<?= $lo['fg'] ?>;" title="<?= htmlspecialchars($lo['title']) ?>"><?= $lo['label'] ?></span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($r['responsavel_nome'] ?? '—') ?></td>
                        <td style="white-space:nowrap;font-size:12px;"><?= htmlspecialchars(fmtDataBR($r['data_inicio'])) ?></td>
                        <td style="white-space:nowrap;font-size:12px;"><?= htmlspecialchars(fmtDataBR($r['data_finalizacao'])) ?></td>
                        <td><span class="rt-badge" style="background:<?= $st['bg'] ?>;color:<?= $st['fg'] ?>;"><?= $st['label'] ?></span></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Registrar / Editar retrabalho -->
<div class="modal-overlay" id="rt-modal">
    <div class="modal-box">
        <form id="rt-form">
            <div class="modal-head">
                <h2 id="rt-modal-title">Registrar retrabalho</h2>
                <button type="button" class="modal-close" id="rt-modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id" id="f-id" value="">
                <div id="rt-form-erro" style="display:none;background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:8px;padding:8px 12px;font-size:13px;margin-bottom:14px;"></div>
                <div class="rt-form-grid">

                    <div class="rt-section">Projeto &amp; transformador</div>
                    <div class="rt-field">
                        <label for="f-pedido">Pedido *</label>
                        <select name="id_pedido" id="f-pedido" required>
                            <option value="">Selecione o pedido…</option>
                            <?php foreach ($pedidos as $p): ?>
                                <option value="<?= (int) $p['id'] ?>"><?= htmlspecialchars($p['numero']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rt-field">
                        <label for="f-projeto">Projeto *</label>
                        <select name="id_projeto" id="f-projeto" required>
                            <option value="">Selecione o pedido primeiro…</option>
                        </select>
                    </div>
                    <div class="rt-field full">
                        <label for="f-ns">N° de série do transformador *</label>
                        <input type="text" name="ns_transformador" id="f-ns" placeholder="Ex.: NS-2026-00841" required>
                        <div class="rt-hint">Único no sistema — 1 NS pertence a 1 projeto. Repetido em outro projeto é bloqueado.</div>
                    </div>

                    <div class="rt-section">Reprova / contenção</div>
                    <div class="rt-field">
                        <label for="f-reprova">Código de reprova *</label>
                        <select name="id_reprova" id="f-reprova" required>
                            <option value="">Selecione…</option>
                            <?php foreach ($reprovas as $rp): ?>
                                <option value="<?= (int) $rp['id'] ?>"><?= htmlspecialchars($rp['codigo'] . ' — ' . $rp['descricao']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rt-field">
                        <label for="f-data_reprova">Data da reprova</label>
                        <input type="date" name="data_reprova" id="f-data_reprova" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="rt-field full auto">
                        <label for="f-rep-descricao">Descrição da contenção</label>
                        <input type="text" id="f-rep-descricao" placeholder="— selecione o código —" readonly>
                    </div>
                    <div class="rt-field auto">
                        <label for="f-rep-familia">Família da contenção</label>
                        <input type="text" id="f-rep-familia" placeholder="—" readonly>
                    </div>
                    <div class="rt-field auto">
                        <label for="f-rep-local">Local</label>
                        <input type="text" id="f-rep-local" placeholder="—" readonly>
                    </div>

                    <div class="rt-section">Retrabalho</div>
                    <div class="rt-field">
                        <label for="f-data_inicio">Data de início do retrabalho</label>
                        <input type="date" name="data_inicio" id="f-data_inicio">
                    </div>
                    <div class="rt-field">
                        <label for="f-data_finalizacao">Data de finalização do retrabalho</label>
                        <input type="date" name="data_finalizacao" id="f-data_finalizacao">
                        <div class="rt-hint">Ao preencher, o status passa para "Agu. Causa Raiz".</div>
                    </div>
                    <div class="rt-field full">
                        <label for="f-causa_raiz">Causa Raiz</label>
                        <textarea name="causa_raiz" id="f-causa_raiz" placeholder="Descreva a causa raiz"></textarea>
                        <div class="rt-hint">Ao preencher, o status passa para "Finalizado".</div>
                    </div>
                    <div class="rt-field full">
                        <label for="f-observacoes">Observações</label>
                        <textarea name="observacoes" id="f-observacoes" placeholder="Detalhes adicionais (opcional)"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="rt-btn-secondary" id="rt-modal-cancel">Cancelar</button>
                <button type="submit" class="rt-btn-acc" id="rt-form-submit">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
    window.RETRABALHO_API = <?= json_encode($base . '/api/retrabalho-acao.php') ?>;
    window.RETRABALHO_PROJETOS = <?= json_encode($projetos, JSON_UNESCAPED_UNICODE) ?>;
    window.RETRABALHO_REPROVAS = <?= json_encode($reprovas, JSON_UNESCAPED_UNICODE) ?>;
    window.RETRABALHO_CHART = {
        familia: {
            labels: <?= json_encode(array_keys($porFamilia), JSON_UNESCAPED_UNICODE) ?>,
            values: <?= json_encode(array_values($porFamilia)) ?>
        },
        local: {
            labels: <?= json_encode(array_keys($porLocal), JSON_UNESCAPED_UNICODE) ?>,
            values: <?= json_encode(array_values($porLocal)) ?>
        }
    };
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<?php $rtJsVer = @filemtime(__DIR__ . '/../../assets/js/retrabalho.js') ?: (defined('APP_VERSION') ? APP_VERSION : '1'); ?>
<script src="<?= htmlspecialchars($base) ?>/assets/js/retrabalho.js?v=<?= htmlspecialchars((string) $rtJsVer) ?>"></script>

<?php layoutFooter(); ?>
