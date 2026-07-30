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
    SELECT pe.ns_transformador, pe.data_inicio,
           pr.codigo AS projeto_codigo, pr.descricao AS projeto_descricao,
           ped.numero AS pedido_numero, u.nome AS responsavel_nome
    FROM producao_etapas pe
    JOIN projetos pr      ON pr.id  = pe.id_projeto
    JOIN pedidos ped      ON ped.id = pr.id_pedido
    LEFT JOIN usuarios u  ON u.id   = pe.id_responsavel
    WHERE pe.estacao = 'LAB' AND pe.status = 'aguardando_retorno' AND pe.deleted_at IS NULL
    ORDER BY pe.data_inicio DESC
")->fetchAll();

function retFmtDataHora(?string $iso): string
{
    if (!$iso) return '—';
    $ts = strtotime($iso);
    return $ts ? date('d/m/y H:i', $ts) : '—';
}

$pageTitle = 'Retornos ao Laboratório';
require_once __DIR__ . '/../../includes/layout.php';
layoutHeader($pageTitle);
?>

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
                        <th>N° Série</th>
                        <th>Projeto</th>
                        <th>Pedido</th>
                        <th>Enviado em</th>
                        <th>Registrado por</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($retornos as $r): ?>
                        <tr>
                            <td><span style="font-family:'JetBrains Mono',monospace;font-weight:600;"><?= htmlspecialchars($r['ns_transformador']) ?></span></td>
                            <td>
                                <span style="font-family:'JetBrains Mono',monospace;font-weight:600;"><?= htmlspecialchars($r['projeto_codigo'] ?? '—') ?></span>
                                <?php if (!empty($r['projeto_descricao'])): ?>
                                    <div style="font-size:11px;color:var(--color-text-muted);"><?= htmlspecialchars($r['projeto_descricao']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($r['pedido_numero'] ?? '—') ?></td>
                            <td><?= htmlspecialchars(retFmtDataHora($r['data_inicio'])) ?></td>
                            <td><?= htmlspecialchars($r['responsavel_nome'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php layoutFooter(); ?>
