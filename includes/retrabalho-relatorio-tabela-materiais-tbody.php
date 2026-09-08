<?php
declare(strict_types=1);
/**
 * Corpo (<tbody>) da tabela "Consumo de Peças & Materiais de Retrabalho".
 * Espera em escopo: $todosMateriaisConsumidos, $podeVerValores.
 * Usado tanto pelo render inicial de pages/retrabalho/relatorio.php quanto
 * pelo endpoint api/retrabalho-relatorio-atualizar.php (via output buffering).
 */
?>
<?php if (empty($todosMateriaisConsumidos)): ?>
    <tr><td colspan="6" style="text-align: center; color: #94a3b8; padding: 20px;">Nenhum material registrado nas triagens deste período.</td></tr>
<?php else: ?>
    <?php foreach ($todosMateriaisConsumidos as $mat): ?>
        <tr>
            <td>
                <strong><?= htmlspecialchars($mat['descricao']) ?></strong>
            </td>
            <td style="text-align: center;">
                <span class="badge-tag ger"><?= htmlspecialchars($mat['unidade']) ?></span>
            </td>
            <td style="text-align: right;" class="num-mono">
                <?= number_format((float) $mat['quantidade'], 2, ',', '.') ?>
            </td>
            <td style="text-align: center;" class="num-mono">
                <?= (int) $mat['ocorrencias'] ?>
            </td>
            <?php if ($podeVerValores): ?>
                <td style="text-align: right; color: #475569;" class="num-mono">
                    R$ <?= number_format((float) $mat['custo_unitario'], 2, ',', '.') ?>
                </td>
                <td style="text-align: right; font-weight: 800; color: #d97706;" class="num-mono">
                    R$ <?= number_format((float) $mat['custo_total'], 2, ',', '.') ?>
                </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
<?php endif; ?>
