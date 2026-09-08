<?php
declare(strict_types=1);
/**
 * Corpo (<tbody>) da tabela "Relação Analítica por Transformador / Caso".
 * Espera em escopo: $registrosProcessados, $podeVerValores, $setoresDisponiveis.
 * Usado tanto pelo render inicial de pages/retrabalho/relatorio.php quanto
 * pelo endpoint api/retrabalho-relatorio-atualizar.php (via output buffering).
 */
?>
<?php if (empty($registrosProcessados)): ?>
    <tr><td colspan="10" style="text-align: center; color: #94a3b8; padding: 24px;">Nenhum registro encontrado com os filtros informados.</td></tr>
<?php else: ?>
    <?php foreach ($registrosProcessados as $idx => $reg): ?>
        <tr>
            <td>
                <strong style="color: var(--rep-primary); font-family: monospace; font-size: 0.95rem;">
                    <?= htmlspecialchars($reg['ns_transformador'] ?: '-') ?>
                </strong>
            </td>
            <td>
                <div style="font-weight: 600; color: #1e293b;">
                    <?= htmlspecialchars($reg['projeto_codigo'] ?: '-') ?>
                </div>
                <div style="font-size: 0.74rem; color: #64748b;">
                    Ped: <?= htmlspecialchars($reg['pedido_numero'] ?: '-') ?>
                </div>
            </td>
            <td style="text-align: center;">
                <span class="badge-tag <?= strtolower($reg['estacao'] ?? 'ger') ?>">
                    <?= htmlspecialchars($reg['estacao'] ?? '-') ?>
                </span>
            </td>
            <td>
                <span style="font-family: monospace; font-weight: 700; color: var(--rep-primary);">
                    <?= htmlspecialchars($reg['reprova_codigo'] ?: '') ?>
                </span>
                <div style="font-size: 0.8rem; color: #334155;">
                    <?= htmlspecialchars($reg['reprova_descricao'] ?: ($reg['causa_reprova'] ?: '-')) ?>
                </div>
            </td>
            <td style="text-align: center;" class="num-mono">
                <?= (int) ($reg['minutos_padrao'] ?? 60) ?> min
            </td>
            <td>
                <?php
                $destStr = (string) ($reg['setores_destino'] ?? '');
                if ($destStr !== ''):
                    $dList = explode(',', $destStr);
                    $dListUnicos = [];
                    foreach ($dList as $dItem):
                        $dSlug = trim($dItem);
                        if ($dSlug === '') continue;
                        if ($dSlug === 'inspecao_final') $dSlug = 'montagem_final';
                        $dListUnicos[$dSlug] = true;
                    endforeach;
                    foreach (array_keys($dListUnicos) as $dSlug):
                        $lbl = $setoresDisponiveis[$dSlug] ?? ($dSlug === 'nao_definido' ? 'Não definido' : ucfirst(str_replace('_', ' ', $dSlug)));
                ?>
                    <span class="badge-tag ger" style="margin: 1px;"><?= htmlspecialchars($lbl) ?></span>
                <?php
                    endforeach;
                else:
                ?>
                    <span style="color: #94a3b8; font-size: 0.78rem;">Não definido</span>
                <?php endif; ?>
            </td>
            <td style="text-align: center;">
                <span class="badge-status <?= in_array($reg['status'], ['finalizado', 'aprovado'], true) ? 'finalizado' : 'em_andamento' ?>">
                    <?= htmlspecialchars($reg['status'] === 'finalizado' ? 'Finalizado' : ($reg['status'] === 'aprovado' ? 'Aprovado' : 'Em Aberto')) ?>
                </span>
            </td>
            <td style="text-align: right;" class="num-mono">
                <?= number_format((float) $reg['horas_trabalhadas'], 2, ',', '.') ?> h
            </td>
            <?php if ($podeVerValores): ?>
                <td style="text-align: right; font-weight: 750; color: #b91c1c;" class="num-mono">
                    R$ <?= number_format((float) $reg['custo_total'], 2, ',', '.') ?>
                </td>
            <?php endif; ?>
            <td style="text-align: center;">
                <button type="button" class="btn-drilldown" onclick="abrirDrilldown(<?= htmlspecialchars(json_encode($reg, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>)">
                    Detalhes
                </button>
            </td>
        </tr>
    <?php endforeach; ?>
<?php endif; ?>
