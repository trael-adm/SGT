<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();

$pdo  = getDB();
$base = defined('APP_URL') ? APP_URL : '';

// Transformadores enviados de volta ao Laboratório pela Triagem do Retrabalho
// (ver api/retrabalho-acao.php::case 'registrar') e ainda não recebidos de novo
// por lá (a leitura do QR em "Registro" resolve a linha sozinha — ver
// api/producao-acao.php::case 'confirmar').
$retornos = $pdo->query("
    SELECT pe.ns_transformador, pe.id_projeto, pe.data_inicio,
           pr.codigo AS projeto_codigo, pr.descricao AS projeto_descricao,
           ped.numero AS pedido_numero, u.nome AS responsavel_nome
    FROM producao_etapas pe
    JOIN projetos pr      ON pr.id  = pe.id_projeto
    JOIN pedidos ped      ON ped.id = pr.id_pedido
    LEFT JOIN usuarios u  ON u.id   = pe.id_responsavel
    WHERE pe.estacao = 'LAB' AND pe.status = 'aguardando_retorno' AND pe.deleted_at IS NULL
    ORDER BY pe.data_inicio DESC
")->fetchAll();

// ─── Reprovas que motivaram cada retorno — ocultas atrás do "+" na listagem ────
// Mesmo N°/projeto que a Triagem enviou pro Laboratório (setores_destino contém
// "laboratorio"), ainda em aberto (não finalizado). Uma triagem pode ter mais de
// 1 reprova no mesmo lote — todas aparecem juntas no detalhe deste retorno.
$reprovasPorItem = [];
if ($retornos) {
    $nsList = array_values(array_unique(array_column($retornos, 'ns_transformador')));
    $ph     = implode(',', array_fill(0, count($nsList), '?'));
    $stmtRep = $pdo->prepare("
        SELECT r.id_projeto, r.ns_transformador, r.data_reprova,
               rep.codigo AS reprova_codigo, rep.familia AS reprova_familia, rep.descricao AS reprova_descricao
        FROM retrabalhos r
        LEFT JOIN reprovas rep ON rep.id = r.id_reprova
        WHERE r.deleted_at IS NULL AND r.status <> 'finalizado'
          AND r.setores_destino IS NOT NULL AND FIND_IN_SET('laboratorio', r.setores_destino)
          AND r.ns_transformador IN ($ph)
        ORDER BY r.data_reprova DESC, r.id DESC
    ");
    $stmtRep->execute($nsList);
    foreach ($stmtRep->fetchAll() as $rep) {
        $chave = $rep['id_projeto'] . '|' . $rep['ns_transformador'];
        $reprovasPorItem[$chave][] = $rep;
    }
}

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
    .ret-toggle-btn { background:#fff; border:1px solid var(--color-border,#e5e7eb); border-radius:6px; width:24px; height:24px; padding:0; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; font-size:15px; font-weight:700; line-height:1; color:var(--color-text-secondary,#5a6480); }
    .ret-toggle-btn:hover { border-color:#E89B1C; color:#E89B1C; }
    .ret-detail-row { display:none; }
    .ret-detail-row.is-open { display:table-row; }
    .ret-detail-wrap { background:var(--color-surface-2,#f9fafb); border-radius:8px; padding:8px 10px; margin:2px 0; }
    .ret-subtable { width:100%; border-collapse:collapse; font-size:12px; }
    .ret-subtable th { text-align:left; font-size:9px; text-transform:uppercase; letter-spacing:.5px; color:var(--color-text-muted,#6b7280); padding:6px 8px; border-bottom:1px solid var(--color-border,#e5e7eb); white-space:nowrap; }
    .ret-subtable td { padding:7px 8px; border-bottom:1px solid var(--color-border,#eef1f5); vertical-align:middle; }
    .ret-subtable tr:last-child td { border-bottom:none; }
    .ret-code { font-family:'JetBrains Mono',monospace; font-weight:600; }
</style>

<!-- Cabeçalho -->
<div style="margin-bottom:18px;">
    <a href="<?= htmlspecialchars($base) ?>/pages/producao/index.php" style="font-size:12px;color:var(--color-text-muted,#9aa3b8);text-decoration:none;">&larr; Registro</a>
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

    <?php if (!$retornos): ?>
        <p style="text-align:center;padding:36px 16px;color:var(--color-text-muted);font-size:13px;">Nenhum transformador aguardando retorno.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width:32px;"></th>
                        <th>N° Série</th>
                        <th>Projeto</th>
                        <th>Pedido</th>
                        <th>Enviado em</th>
                        <th>Registrado por</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($retornos as $i => $r):
                        $chave    = $r['id_projeto'] . '|' . $r['ns_transformador'];
                        $reprovas = $reprovasPorItem[$chave] ?? [];
                        $detId    = 'ret-det-' . $i;
                    ?>
                        <tr>
                            <td>
                                <button type="button" class="ret-toggle-btn js-toggle-retorno" data-target="<?= htmlspecialchars($detId) ?>" aria-expanded="false" title="Mostrar reprovas">+</button>
                            </td>
                            <td><span class="ret-code"><?= htmlspecialchars($r['ns_transformador']) ?></span></td>
                            <td>
                                <span class="ret-code"><?= htmlspecialchars($r['projeto_codigo'] ?? '—') ?></span>
                                <?php if (!empty($r['projeto_descricao'])): ?>
                                    <div style="font-size:11px;color:var(--color-text-muted);"><?= htmlspecialchars($r['projeto_descricao']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($r['pedido_numero'] ?? '—') ?></td>
                            <td><?= htmlspecialchars(retFmtDataHora($r['data_inicio'])) ?></td>
                            <td><?= htmlspecialchars($r['responsavel_nome'] ?? '—') ?></td>
                        </tr>
                        <tr class="ret-detail-row" id="<?= htmlspecialchars($detId) ?>">
                            <td colspan="6">
                                <div class="ret-detail-wrap">
                                    <?php if (!$reprovas): ?>
                                        <p style="font-size:12px;color:var(--color-text-muted);padding:6px 8px;margin:0;">Nenhuma reprova associada encontrada.</p>
                                    <?php else: ?>
                                        <div style="overflow-x:auto;">
                                        <table class="ret-subtable">
                                            <thead>
                                                <tr><th>Contenção</th><th>Família</th><th>Data reprova</th></tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($reprovas as $rep): ?>
                                                    <tr>
                                                        <td>
                                                            <span class="ret-code" style="font-weight:500;"><?= htmlspecialchars($rep['reprova_codigo'] ?? '—') ?></span>
                                                            <?php if (!empty($rep['reprova_descricao'])): ?>
                                                                <div style="font-size:11px;color:#6b7280;"><?= htmlspecialchars($rep['reprova_descricao']) ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?= htmlspecialchars($rep['reprova_familia'] ?? '—') ?></td>
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

<script>
    // Expandir/recolher reprovas por trás do "+" — delegado no document (sobrevive
    // a qualquer futura troca de HTML da tabela, ex.: um auto-refresh).
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-toggle-retorno');
        if (!btn) return;
        var row = document.getElementById(btn.dataset.target);
        if (!row) return;
        var aberto = row.classList.toggle('is-open');
        btn.textContent = aberto ? '−' : '+';
        btn.setAttribute('aria-expanded', aberto ? 'true' : 'false');
    });
</script>

<?php layoutFooter(); ?>
