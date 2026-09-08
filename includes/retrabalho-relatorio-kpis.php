<?php
declare(strict_types=1);
/**
 * Conteúdo interno do grid de KPIs (div.kpi-grid) do Relatório Operacional &
 * Financeiro de Retrabalho.
 * Espera em escopo: $totalCasosRetrabalho, $casosFinalizadosCont,
 * $totalHorasRetrabalho, $mediaHorasPorCaso, $podeVerValores,
 * $totalCustoMaoObra, $totalCustoPecas, $todosMateriaisConsumidos,
 * $totalCustoGeral, $custoMedioPorCaso.
 * Usado tanto pelo render inicial de pages/retrabalho/relatorio.php quanto
 * pelo endpoint api/retrabalho-relatorio-atualizar.php (via output buffering).
 */
?>
<div class="kpi-card emerald">
    <div class="kpi-label">
        Casos de Retrabalho
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-emerald-600"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>
    </div>
    <div class="kpi-val"><?= number_format($totalCasosRetrabalho, 0, ',', '.') ?></div>
    <div class="kpi-sub"><?= $casosFinalizadosCont ?> concluídos / <?= ($totalCasosRetrabalho - $casosFinalizadosCont) ?> em aberto</div>
</div>

<div class="kpi-card blue">
    <div class="kpi-label">
        Horas Acumuladas
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-sky-600"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    </div>
    <div class="kpi-val"><?= number_format($totalHorasRetrabalho, 1, ',', '.') ?> <span style="font-size: 0.85rem; font-weight: normal; color: #64748b;">h</span></div>
    <div class="kpi-sub">Média por caso: <?= number_format($mediaHorasPorCaso, 2, ',', '.') ?> h (<?= round($mediaHorasPorCaso * 60) ?> min)</div>
</div>

<?php if ($podeVerValores): ?>
    <div class="kpi-card">
        <div class="kpi-label">
            Custo Mão-de-Obra
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-slate-600"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        </div>
        <div class="kpi-val" style="color: #1e293b;">R$ <?= number_format($totalCustoMaoObra, 2, ',', '.') ?></div>
        <div class="kpi-sub">Baseada no tempo padrão das reprovas</div>
    </div>

    <div class="kpi-card gold">
        <div class="kpi-label">
            Custo Peças / Materiais
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-amber-600"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
        </div>
        <div class="kpi-val" style="color: var(--rep-gold-hover);">R$ <?= number_format($totalCustoPecas, 2, ',', '.') ?></div>
        <div class="kpi-sub"><?= count($todosMateriaisConsumidos) === 1 ? '1 tipo de item aplicado' : count($todosMateriaisConsumidos) . ' tipos de itens aplicados' ?></div>
    </div>

    <div class="kpi-card red">
        <div class="kpi-label">
            Custo Total de Retrabalho
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-rose-600"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        </div>
        <div class="kpi-val" style="color: #b91c1c;">R$ <?= number_format($totalCustoGeral, 2, ',', '.') ?></div>
        <div class="kpi-sub">Média por transformador: R$ <?= number_format($custoMedioPorCaso, 2, ',', '.') ?></div>
    </div>
<?php else: ?>
    <div class="kpi-card">
        <div class="kpi-label">Média de Horas / Caso</div>
        <div class="kpi-val"><?= number_format($mediaHorasPorCaso, 2, ',', '.') ?> h</div>
        <div class="kpi-sub"><?= round($mediaHorasPorCaso * 60) ?> minutos médios por transformador</div>
    </div>
<?php endif; ?>
