<?php
declare(strict_types=1);

/**
 * Histórico das validações do Paint Check (checklist por campo, ver
 * api/paint-check-salvar-validacao.php) — consulta/auditoria, sem edição.
 * Tela separada da Relação de Retrabalhos (pages/pintura/relacao.php) porque
 * o Paint Check é uma modalidade de qualidade diferente: aqui é o resultado
 * da inspeção em si (o que a IA leu, o que o operador confirmou), não o
 * acompanhamento de um retrabalho já aberto.
 *
 * Mesmo padrão visual/estrutural de pages/pintura/relacao.php e
 * pages/producao/retornos.php: page-fixed-layout + card + data-table, coluna
 * de expandir dedicada + "Expandir Todos", em vez de <details> nativo.
 *
 * Três estados possíveis por validação (v.status + v.todos_campos_ok):
 * - Confirmado: status='validado', todos_campos_ok=1 (100% IA, zero manual).
 * - Manual: status='validado', todos_campos_ok=0, mas todo campo não
 *   confirmado pela IA foi confirmado manualmente (garantido pelo gate de
 *   salvamento em pages/qualidade/paint-check.php — não dá pra salvar
 *   incompleto, ver salvarValidacaoChecklist()).
 * - Reprovado: status='reprovado' (nasceu do modal "Reprovar" do Paint
 *   Check, ver api/pintura-retornos-acao.php).
 *
 * Sem cards de subtotal por design — uma tela "gerencial" separada vai cobrir
 * essa visão agregada futuramente; esta tela é só a lista de auditoria.
 */

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/layout.php';

requireLogin();
if (!hasAcesso('tab:pintura') && !hasAcesso('pin.pai') && !hasAcesso('admin')) {
    requireAcessoModulo('tab:pintura');
}

$pdo = getDB();
$base = defined('APP_URL') ? APP_URL : '';

$fNs = trim((string) ($_GET['ns'] ?? ''));
$fConcessionaria = trim((string) ($_GET['concessionaria'] ?? ''));
$fStatus = trim((string) ($_GET['status'] ?? '')); // 'confirmado' | 'manual' | 'reprovado' | ''
$fDataIni = trim((string) ($_GET['data_ini'] ?? ''));
$fDataFim = trim((string) ($_GET['data_fim'] ?? ''));

$where = [];
$params = [];
if ($fNs !== '') { $where[] = 'v.num_serie LIKE ?'; $params[] = '%' . $fNs . '%'; }
if ($fConcessionaria !== '') { $where[] = 'v.nome_grupo = ?'; $params[] = $fConcessionaria; }
if ($fDataIni !== '') { $where[] = 'DATE(v.created_at) >= ?'; $params[] = $fDataIni; }
if ($fDataFim !== '') { $where[] = 'DATE(v.created_at) <= ?'; $params[] = $fDataFim; }

// "Manual" só é distinguível de "pendente" (residual — não deveria existir em
// dados novos, ver gate de salvamento em paint-check.php) checando que TODO
// campo não confirmado pela IA foi confirmado manualmente.
if ($fStatus === 'confirmado') {
    $where[] = "v.status = 'validado' AND v.todos_campos_ok = 1";
} elseif ($fStatus === 'reprovado') {
    $where[] = "v.status = 'reprovado'";
} elseif ($fStatus === 'manual') {
    $where[] = "v.status = 'validado' AND v.todos_campos_ok = 0 AND NOT EXISTS (
        SELECT 1 FROM paint_check_validacao_campos c2
        WHERE c2.id_validacao = v.id AND c2.status <> 'confirmado' AND c2.confirmado_manualmente = 0
    )";
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("
    SELECT v.id, v.num_serie, v.cliente, v.nome_grupo, v.concessionaria_fallback, v.todos_campos_ok, v.status, v.created_at,
           u.nome AS usuario_nome
    FROM paint_check_validacoes v
    LEFT JOIN usuarios u ON u.id = v.id_usuario
    $whereSql
    ORDER BY v.created_at DESC
    LIMIT 300
");
$stmt->execute($params);
$validacoes = $stmt->fetchAll();

$idsValidacoes = array_map(fn ($v) => (int) $v['id'], $validacoes);
$camposPorValidacao = [];
if ($idsValidacoes) {
    $ph = implode(',', array_fill(0, count($idsValidacoes), '?'));
    $stmtCampos = $pdo->prepare("
        SELECT id_validacao, slot, label, status, esperado, leitura_proxima, distancia, leituras_json, detalhe, confirmado_manualmente, foto_recorte_path
        FROM paint_check_validacao_campos
        WHERE id_validacao IN ($ph)
        ORDER BY id ASC
    ");
    $stmtCampos->execute($idsValidacoes);
    foreach ($stmtCampos->fetchAll() as $campo) {
        $camposPorValidacao[(int) $campo['id_validacao']][] = $campo;
    }
}

$concessionarias = $pdo->query("
    SELECT DISTINCT nome_grupo FROM paint_check_validacoes WHERE nome_grupo IS NOT NULL AND nome_grupo != '' ORDER BY nome_grupo
")->fetchAll(PDO::FETCH_COLUMN);

/** Classifica uma validação em 'confirmado' | 'manual' | 'reprovado' | 'pendente' (caso residual, ver comentário no topo). */
function pchBucket(array $v, array $campos): string
{
    if ($v['status'] === 'reprovado') return 'reprovado';
    if ((int) $v['todos_campos_ok'] === 1) return 'confirmado';
    foreach ($campos as $c) {
        if ($c['status'] !== 'confirmado' && !$c['confirmado_manualmente']) return 'pendente';
    }
    return 'manual';
}

$BUCKET_LABEL = ['confirmado' => 'Confirmado', 'manual' => 'Manual', 'reprovado' => 'Reprovado', 'pendente' => 'Pendente'];
$BUCKET_BADGE_CLASS = ['confirmado' => 'pch-badge-ok', 'manual' => 'pch-badge-manual', 'reprovado' => 'pch-badge-reprovado', 'pendente' => 'pch-badge-erro'];

$pageTitle = 'Histórico do Paint Check';
layoutHeader($pageTitle);
?>
<style>
.pch-detail-row { display: none; }
.pch-detail-row.is-open { display: table-row; }
.pch-detail-wrap { background: var(--color-surface-2); border-radius: var(--radius-md); padding: 10px 14px; margin: 4px 0; border: 1px solid var(--color-border); }
.pch-campo { padding: 8px 0; border-bottom: 1px dashed var(--color-border,#e5e7eb); font-size: 12.5px; }
.pch-campo:last-child { border-bottom: none; }
.pch-campo-vazio { padding: 4px 0; font-size: 12.5px; color: #9ca3af; }
.pch-expand-btn { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border: 1px solid #cbd5e1; border-radius: 6px; background: #f8fafc; color: #475569; flex-shrink: 0; cursor: pointer; transition: all 0.15s ease; }
.pch-expand-btn:hover { background: #f1f5f9; border-color: #94a3b8; }
.pch-expand-btn svg { transition: transform 0.15s ease; }
.pch-expand-btn[aria-expanded="true"] svg { transform: rotate(90deg); }
.pch-expand-btn[aria-expanded="true"] { background: #fff7ed; border-color: #ea580c; color: #9a3412; }
.pch-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 700; white-space: nowrap; }
.pch-badge-ok { background: #dcfce7; color: #166534; }
.pch-badge-manual { background: #fef9c3; color: #854d0e; }
.pch-badge-reprovado { background: #fee2e2; color: #b91c1c; }
.pch-badge-erro { background: #fef3c7; color: #92400e; }
.pch-ver-foto { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; color: #2563eb; cursor: pointer; background: none; border: none; padding: 0; font-family: inherit; }
.pch-ver-foto:hover { text-decoration: underline; }
.btn-expand-all { display: inline-flex; align-items: center; gap: 7px; padding: 6px 14px; border: 1.5px solid #cbd5e1; border-radius: 6px; background: #ffffff; color: #334155; font-size: 12px; font-weight: 600; cursor: pointer; user-select: none; transition: all 0.15s ease; }
.btn-expand-all:hover { background: #f8fafc; border-color: #94a3b8; color: #0f172a; }
.btn-expand-all.is-active { background: #fff7ed; border-color: #ea580c; color: #9a3412; }
.btn-expand-all .ico-expand { transition: transform 0.15s ease; }
.btn-expand-all[aria-expanded="true"] .ico-expand { transform: rotate(90deg); }

/* Cabeçalho da tabela fixo ao rolar dentro de .table-wrap — sem isso, ao
   expandir uma linha com muitos campos e rolar, o contexto (Nº Série,
   Cliente…) some, mesmo padrão local já usado em pages/producao/lista.php. */
.table-wrap { overflow-y: auto; max-height: calc(100vh - 280px); max-height: calc(100dvh - 280px); position: relative; }
.data-table thead th { position: sticky; top: 0; z-index: 10; background: #f8fafc; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }

/* Barra flutuante com o NS/cliente/etc. da linha aberta, visível enquanto seu
   detalhe rola por dentro de .table-wrap. NÃO é sticky em <tr>/<td> de tabela
   (testei 3 variações ao vivo no Chrome — mostravam conteúdo do detalhe
   vazando por baixo da linha grudada, limitação conhecida de sticky dentro de
   <table>) — é um <div> comum, sticky robusto, mostrado/escondido e
   preenchido via JS de scroll (ver pchUpdatePinnedBar() no <script> abaixo). */
.pch-pinned-bar {
    position: sticky; top: var(--pch-thead-h, 37px); z-index: 11;
    background: var(--color-surface,#fff); border-bottom: 1px solid var(--color-border,#e2e8f0);
    box-shadow: 0 2px 4px rgba(0,0,0,0.08);
    padding: 10px 16px; font-size: 13px;
    display: none; align-items: center; gap: 14px; flex-wrap: nowrap; overflow: hidden;
}
.pch-pinned-bar.is-visible { display: flex; }
.pch-pinned-bar .ppb-ns { font-family: monospace; font-weight: 700; white-space: nowrap; flex-shrink: 0; }
.pch-pinned-bar .ppb-cliente { color: #374151; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; min-width: 60px; }
.pch-pinned-bar .ppb-concessionaria { color: #6b7280; white-space: nowrap; flex-shrink: 0; }
.pch-pinned-bar .ppb-data { color: #9ca3af; white-space: nowrap; flex-shrink: 0; }

/* Pop-up de foto — mesmo padrão visual do .camera-overlay de pages/qualidade/paint-check.php (barra escura no topo com X). */
.pch-foto-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.85); z-index: 999999; display: none; flex-direction: column; }
.pch-foto-overlay.is-open { display: flex; }
.pch-foto-top { display: flex; justify-content: flex-end; padding: 14px 18px; }
.pch-foto-close { background: rgba(255,255,255,0.12); border: none; color: #fff; width: 36px; height: 36px; border-radius: 50%; font-size: 20px; line-height: 1; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.pch-foto-close:hover { background: rgba(255,255,255,0.22); }
.pch-foto-body { flex: 1; display: flex; align-items: center; justify-content: center; padding: 0 24px 24px; min-height: 0; }
.pch-foto-body img { max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 6px; }
</style>

<div class="page-fixed-layout">

    <div class="page-fixed-header">
        <h1 style="font-size:var(--font-size-xl,20px);font-weight:700;margin-top:2px;">Histórico do Paint Check</h1>
        <p class="text-secondary" style="font-size:13px;color:var(--color-text-secondary,#6b7280);margin-top:2px;">Consulta das validações registradas — resultado da IA e confirmações manuais por campo. Só leitura.</p>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <div class="card-title">Validações</div>
                <div class="card-subtitle"><?= count($validacoes) ?> registro<?= count($validacoes) === 1 ? '' : 's' ?></div>
            </div>
        </div>

        <form method="GET" class="filter-bar">
            <div class="filter-group-left">
                <div class="filter-search-wrap">
                    <svg class="filter-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    <input type="search" name="ns" class="filter-search-input" value="<?= htmlspecialchars($fNs) ?>" placeholder="Buscar por Nº de Série…">
                </div>
                <select name="concessionaria" class="filter-select" onchange="this.form.submit()">
                    <option value="">Todos os grupos de cliente</option>
                    <?php foreach ($concessionarias as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>" <?= $fConcessionaria === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="status" class="filter-select" onchange="this.form.submit()">
                    <option value="">Todos os status</option>
                    <option value="confirmado" <?= $fStatus === 'confirmado' ? 'selected' : '' ?>>Confirmado</option>
                    <option value="manual" <?= $fStatus === 'manual' ? 'selected' : '' ?>>Manual</option>
                    <option value="reprovado" <?= $fStatus === 'reprovado' ? 'selected' : '' ?>>Reprovado</option>
                </select>
                <input type="date" name="data_ini" class="filter-select" value="<?= htmlspecialchars($fDataIni) ?>" onchange="this.form.submit()">
                <input type="date" name="data_fim" class="filter-select" value="<?= htmlspecialchars($fDataFim) ?>" onchange="this.form.submit()">
                <button type="submit" class="filter-btn filter-btn-secondary">Filtrar</button>
                <?php if ($fNs !== '' || $fConcessionaria !== '' || $fStatus !== '' || $fDataIni !== '' || $fDataFim !== ''): ?>
                    <a href="<?= htmlspecialchars($base) ?>/pages/qualidade/paint-check-historico.php" class="filter-btn filter-btn-clear" title="Limpar filtros">Limpar</a>
                <?php endif; ?>
                <button type="button" id="btn-toggle-all-pch" class="filter-btn btn-expand-all" aria-expanded="false" title="Expandir ou recolher todas as linhas">
                    <svg class="ico-expand" viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                    <span class="lbl-expand">Expandir Todos</span>
                </button>
            </div>
        </form>

        <?php if (!$validacoes): ?>
            <p style="text-align:center;padding:36px 16px;color:var(--color-text-muted);font-size:13px;">Nenhuma validação encontrada com esses filtros.</p>
        <?php else: ?>
            <div class="table-wrap" id="pchTableWrap">
                <div class="pch-pinned-bar" id="pchPinnedBar">
                    <span class="ppb-ns"></span>
                    <span class="ppb-cliente"></span>
                    <span class="ppb-concessionaria"></span>
                    <span class="ppb-status"></span>
                    <span class="ppb-data"></span>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width:36px;text-align:center;">
                                <button type="button" class="pch-expand-btn js-toggle-all-quick" aria-expanded="false" title="Expandir/Recolher todos">
                                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                                </button>
                            </th>
                            <th>Nº Série</th>
                            <th>Cliente</th>
                            <th>Grupo do Cliente</th>
                            <th>Status</th>
                            <th>Data</th>
                            <th>Usuário</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($validacoes as $i => $v):
                            $campos = $camposPorValidacao[(int) $v['id']] ?? [];
                            $bucket = pchBucket($v, $campos);
                            $dataFmt = date('d/m/y H:i', strtotime((string) $v['created_at']));
                            $detId = 'pch-det-' . $i;
                        ?>
                            <tr data-summary-for="<?= htmlspecialchars($detId) ?>">
                                <td style="text-align:center;">
                                    <button type="button" class="pch-expand-btn js-toggle-pch" data-target="<?= htmlspecialchars($detId) ?>" aria-expanded="false" title="Mostrar campos">
                                        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                                    </button>
                                </td>
                                <td><span class="font-mono font-600"><?= htmlspecialchars($v['num_serie']) ?></span></td>
                                <td><?= htmlspecialchars($v['cliente'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($v['nome_grupo'] ?? '—') ?><?= $v['concessionaria_fallback'] ? ' (ABNT/fallback)' : '' ?></td>
                                <td><span class="pch-badge <?= $BUCKET_BADGE_CLASS[$bucket] ?>"><?= htmlspecialchars($BUCKET_LABEL[$bucket]) ?></span></td>
                                <td><?= htmlspecialchars($dataFmt) ?></td>
                                <td><?= htmlspecialchars($v['usuario_nome'] ?? '—') ?></td>
                            </tr>
                            <tr class="pch-detail-row" id="<?= htmlspecialchars($detId) ?>">
                                <td colspan="7" style="padding:4px 12px 12px;background:var(--color-surface-2);">
                                    <div class="pch-detail-wrap">
                                        <?php if (!$campos): ?>
                                            <p class="pch-campo-vazio">Nenhum campo registrado nesta validação.</p>
                                        <?php endif; ?>
                                        <?php foreach ($campos as $campo):
                                            $ehOk = $campo['status'] === 'confirmado';
                                            $ehDivergente = $campo['status'] === 'divergente';
                                            $leituras = $campo['leituras_json'] ? (json_decode((string) $campo['leituras_json'], true) ?: []) : [];
                                            $temTecnico = $leituras || $campo['distancia'] !== null;
                                        ?>
                                            <div class="pch-campo">
                                                <b><?= htmlspecialchars($campo['label']) ?></b> —
                                                <?php if ($ehOk): ?>
                                                    <span style="color:#16a34a;font-weight:700;">Validado: <?= htmlspecialchars($campo['esperado'] ?? '—') ?></span>
                                                <?php elseif ($ehDivergente): ?>
                                                    <span style="color:#be123c;">Esperado: <b><?= htmlspecialchars($campo['esperado'] ?? '—') ?></b> &nbsp;|&nbsp; Lido: <b><?= htmlspecialchars($campo['leitura_proxima'] ?? '—') ?></b></span>
                                                <?php else: ?>
                                                    <span style="color:#92400e;">Esperado: <b><?= htmlspecialchars($campo['esperado'] ?? '—') ?></b> (ilegível)</span>
                                                <?php endif; ?>
                                                <?php if ($campo['confirmado_manualmente']): ?> · <span style="color:#166534;">confirmado manualmente</span><?php endif; ?>
                                                <?php if ($campo['detalhe']): ?><br><span style="color:#92400e;font-size:12px;"><?= htmlspecialchars($campo['detalhe']) ?></span><?php endif; ?>
                                                <?php if ($campo['foto_recorte_path']): ?>
                                                    <br><button type="button" class="pch-ver-foto" data-src="<?= htmlspecialchars($base . '/uploads/' . $campo['foto_recorte_path']) ?>">Ver foto</button>
                                                <?php endif; ?>
                                                <?php if ($temTecnico): ?>
                                                    <details style="margin-top:4px;">
                                                        <summary style="cursor:pointer;font-size:11.5px;color:#9ca3af;">detalhes técnicos</summary>
                                                        <div style="font-size:11.5px;color:#9ca3af;">
                                                            <?php if ($leituras): ?>Leitura bruta: <?= htmlspecialchars(implode(', ', $leituras)) ?><br><?php endif; ?>
                                                            <?php if ($campo['distancia'] !== null): ?>Distância: <?= (int) $campo['distancia'] ?><?php endif; ?>
                                                        </div>
                                                    </details>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Pop-up de foto (recorte salvo) -->
<div class="pch-foto-overlay" id="pchFotoOverlay">
    <div class="pch-foto-top">
        <button type="button" class="pch-foto-close" id="pchFotoClose" aria-label="Fechar">✕</button>
    </div>
    <div class="pch-foto-body">
        <img id="pchFotoImg" src="" alt="Recorte da evidência">
    </div>
</div>

<script>
(function () {
    // ─── Barra flutuante com NS/cliente/etc. da linha aberta que está sendo
    // rolada — <div> comum, não linha de tabela (ver comentário no <style>
    // sobre o bug de sticky em <tr>/<td>). Recalculada a cada scroll/toggle.
    var tableWrap = document.getElementById('pchTableWrap');
    var pinnedBar = document.getElementById('pchPinnedBar');
    var theadH = 37;

    function pchShowBarFor(summaryRow) {
        var cells = summaryRow.querySelectorAll('td');
        if (cells.length < 6) return;
        pinnedBar.querySelector('.ppb-ns').textContent = cells[1].textContent.trim();
        pinnedBar.querySelector('.ppb-cliente').textContent = cells[2].textContent.trim();
        pinnedBar.querySelector('.ppb-concessionaria').textContent = cells[3].textContent.trim();
        pinnedBar.querySelector('.ppb-status').innerHTML = cells[4].innerHTML;
        pinnedBar.querySelector('.ppb-data').textContent = cells[5].textContent.trim();
        pinnedBar.classList.add('is-visible');
    }
    function pchHideBar() { pinnedBar.classList.remove('is-visible'); }

    function pchUpdatePinnedBar() {
        if (!tableWrap || !pinnedBar) return;
        var threshold = tableWrap.getBoundingClientRect().top + theadH;
        var active = null;
        document.querySelectorAll('.js-toggle-pch[aria-expanded="true"]').forEach(function (btn) {
            if (active) return;
            var detail = document.getElementById(btn.dataset.target);
            var summary = document.querySelector('tr[data-summary-for="' + btn.dataset.target + '"]');
            if (!detail || !summary) return;
            var sRect = summary.getBoundingClientRect();
            var dRect = detail.getBoundingClientRect();
            // Ativa quando o resumo já passou do topo (ficaria escondido atrás
            // do thead) mas o detalhe dele ainda não terminou de passar.
            if (sRect.bottom < threshold && dRect.bottom > threshold) {
                active = summary;
            }
        });
        if (active) pchShowBarFor(active); else pchHideBar();
    }

    if (tableWrap && pinnedBar) {
        var thead = tableWrap.querySelector('.data-table thead');
        if (thead) {
            theadH = thead.offsetHeight;
            document.documentElement.style.setProperty('--pch-thead-h', theadH + 'px');
        }
        var pchTicking = false;
        tableWrap.addEventListener('scroll', function () {
            if (pchTicking) return;
            pchTicking = true;
            requestAnimationFrame(function () { pchUpdatePinnedBar(); pchTicking = false; });
        });
    }

    // ─── Expandir/recolher campos por trás do "+" & Expandir Todos — mesmo
    // padrão de assets/js/producao-retornos.js / pintura-retornos.js.
    function syncHeaderButtons(expandAll) {
        var btnAll = document.getElementById('btn-toggle-all-pch');
        if (btnAll) {
            btnAll.setAttribute('aria-expanded', expandAll ? 'true' : 'false');
            btnAll.classList.toggle('is-active', expandAll);
            var lbl = btnAll.querySelector('.lbl-expand');
            if (lbl) lbl.textContent = expandAll ? 'Recolher Todos' : 'Expandir Todos';
        }
        var quickBtn = document.querySelector('.js-toggle-all-quick');
        if (quickBtn) {
            // Ícone gira via CSS a partir de aria-expanded (mesmo padrão do
            // botão por linha) — não mexe no conteúdo (é um SVG, não texto).
            quickBtn.setAttribute('aria-expanded', expandAll ? 'true' : 'false');
            quickBtn.title = expandAll ? 'Recolher todos' : 'Expandir todos';
        }
    }

    function setAllRows(expand) {
        syncHeaderButtons(expand);
        document.querySelectorAll('.js-toggle-pch').forEach(function (btn) {
            var row = document.getElementById(btn.dataset.target);
            if (!row) return;
            // O ícone gira via CSS a partir de aria-expanded (ver .pch-expand-btn) —
            // não mexe no conteúdo do botão (é um SVG, não texto).
            row.classList.toggle('is-open', expand);
            btn.setAttribute('aria-expanded', expand ? 'true' : 'false');
        });
        requestAnimationFrame(pchUpdatePinnedBar);
    }

    document.addEventListener('click', function (e) {
        var btnAll = e.target.closest('#btn-toggle-all-pch') || e.target.closest('.js-toggle-all-quick');
        if (btnAll) {
            // Estado sempre lido do botão canônico "Expandir/Recolher Todos" —
            // o botão rápido do cabeçalho só espelha o texto, nunca guarda o
            // próprio estado (bug: clicar nele repetidas vezes sempre expandia).
            var refBtn = document.getElementById('btn-toggle-all-pch');
            var isCurrentlyExpanded = refBtn ? refBtn.getAttribute('aria-expanded') === 'true' : false;
            setAllRows(!isCurrentlyExpanded);
            return;
        }

        var btn = e.target.closest('.js-toggle-pch');
        if (btn) {
            var row = document.getElementById(btn.dataset.target);
            if (!row) return;
            var aberto = row.classList.toggle('is-open');
            btn.setAttribute('aria-expanded', aberto ? 'true' : 'false');
            syncHeaderButtons(false);
            requestAnimationFrame(pchUpdatePinnedBar);
            return;
        }
    });

    // ─── Pop-up de foto ───────────────────────────────────────────────────────
    var overlay = document.getElementById('pchFotoOverlay');
    var img = document.getElementById('pchFotoImg');
    var closeBtn = document.getElementById('pchFotoClose');

    function abrirFoto(src) {
        img.src = src;
        overlay.classList.add('is-open');
    }
    function fecharFoto() {
        overlay.classList.remove('is-open');
        img.src = '';
    }

    document.addEventListener('click', function (e) {
        var btnFoto = e.target.closest('.pch-ver-foto');
        if (btnFoto) { abrirFoto(btnFoto.dataset.src); return; }
        if (e.target === overlay) fecharFoto();
    });
    closeBtn.addEventListener('click', fecharFoto);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('is-open')) fecharFoto();
    });
})();
</script>

<?php layoutFooter(); ?>
