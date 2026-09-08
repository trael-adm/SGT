<?php
declare(strict_types=1);
/**
 * Corpo (<tbody>) da tabela "Análise de Horas e Custos por Tipo de Reprova".
 * Espera em escopo: $analiseReprovas, $podeVerValores, $totalCustoGeral.
 * Usado tanto pelo render inicial de pages/retrabalho/relatorio.php quanto
 * pelo endpoint api/retrabalho-relatorio-atualizar.php (via output buffering).
 */
?>
<?php if (empty($analiseReprovas)): ?>
    <tr><td colspan="10" style="text-align: center; color: #94a3b8; padding: 24px;">Nenhum registro encontrado no período selecionado.</td></tr>
<?php else: ?>
    <?php foreach ($analiseReprovas as $ar):
        $pctCusto = $totalCustoGeral > 0 ? round(($ar['custo_total'] / $totalCustoGeral) * 100, 1) : 0.0;
    ?>
        <tr>
            <td>
                <strong style="color: var(--rep-primary); font-family: monospace;"><?= htmlspecialchars($ar['codigo']) ?></strong>
            </td>
            <td>
                <span class="badge-tag ger"><?= htmlspecialchars($ar['familia']) ?></span>
            </td>
            <td>
                <strong><?= htmlspecialchars($ar['descricao']) ?></strong>
            </td>
            <td style="text-align: center;" class="num-mono">
                <?= (int) ($ar['tempo_minutos'] ?? 60) ?> min
            </td>
            <td style="text-align: center;" class="num-mono">
                <?= (int) $ar['ocorrencias'] ?>
            </td>
            <td style="text-align: right;" class="num-mono">
                <?= number_format((float) $ar['horas'], 2, ',', '.') ?> h
            </td>
            <?php if ($podeVerValores): ?>
                <td style="text-align: right; color: #475569;" class="num-mono">
                    R$ <?= number_format((float) $ar['custo_mo'], 2, ',', '.') ?>
                </td>
                <td style="text-align: right; color: #d97706;" class="num-mono">
                    R$ <?= number_format((float) $ar['custo_pecas'], 2, ',', '.') ?>
                </td>
                <td style="text-align: right; font-weight: 800; color: #b91c1c;" class="num-mono">
                    R$ <?= number_format((float) $ar['custo_total'], 2, ',', '.') ?>
                </td>
                <td style="text-align: right; color: #64748b;" class="num-mono">
                    <?= number_format($pctCusto, 1, ',', '.') ?>%
                </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
<?php endif; ?>
