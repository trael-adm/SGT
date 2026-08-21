<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireAcessoModulo('retrabalho');

$pdo  = getDB();
$base = defined('APP_URL') ? APP_URL : '';

$id     = (int) ($_GET['id'] ?? 0);
$origem = trim((string) ($_GET['origem'] ?? 'retrabalho'));
$voltarUrl = ($origem === 'pintura') 
    ? $base . '/pages/pintura/relacao.php' 
    : $base . '/pages/retrabalho/relacao.php';

$stmt = $pdo->prepare('SELECT id, id_projeto, ns_transformador, data_inicio, data_chegada FROM retrabalhos WHERE id = ? AND deleted_at IS NULL');
$stmt->execute([$id]);
$anchor = $stmt->fetch();

if (!$anchor) {
    $pageTitle = 'Retrabalho';
    require_once __DIR__ . '/../../includes/layout.php';
    layoutHeader($pageTitle);
    ?>
    <div class="card">
        <p style="font-size:13px;color:var(--color-text-secondary,#6b7280);">Registro não encontrado.</p>
        <a href="<?= htmlspecialchars($voltarUrl) ?>" class="btn btn-secondary" style="margin-top:10px;">&larr; Voltar para a Relação</a>
    </div>
    <?php
    layoutFooter();
    exit;
}

$idProjeto = (int) $anchor['id_projeto'];
$ns        = (string) $anchor['ns_transformador'];

// Busca todos os itens abertos para verificar se data_inicio já foi registrada
$stmtItens = $pdo->prepare("
    SELECT r.*, rep.codigo AS reprova_codigo, rep.familia AS reprova_familia, rep.descricao AS reprova_descricao
    FROM retrabalhos r
    LEFT JOIN reprovas rep ON rep.id = r.id_reprova
    WHERE r.id_projeto = ? AND r.ns_transformador = ? AND r.deleted_at IS NULL
    ORDER BY r.data_reprova DESC, r.id DESC
");
$stmtItens->execute([$idProjeto, $ns]);
$itens = $stmtItens->fetchAll();

$itensAbertos = array_values(array_filter($itens, fn ($r) => !in_array($r['status'], ['finalizado'], true)));

$idLote = null;
$dataInicio = null;
foreach ($itensAbertos as $r) {
    if ($r['id_lote'] && !$idLote) { $idLote = (int) $r['id_lote']; }
    if (!empty($r['data_inicio']) && !$dataInicio) { $dataInicio = $r['data_inicio']; }
}
if (!$dataInicio) {
    foreach ($itens as $r) {
        if (!empty($r['data_inicio'])) { $dataInicio = $r['data_inicio']; break; }
    }
}

// OBRIGATÓRIO: Se a data de início ainda não foi lida/bipada, redireciona para a tela intermediária de leitura
if (!$dataInicio) {
    header('Location: ' . $base . '/pages/retrabalho/iniciar-triagem.php?id=' . $id . '&origem=' . urlencode($origem));
    exit;
}

$pageTitle = 'Retrabalho';
require_once __DIR__ . '/../../includes/layout.php';
layoutHeader($pageTitle);

$stmt = $pdo->prepare("
    SELECT pr.codigo AS projeto_codigo, pr.descricao AS projeto_descricao, ped.numero AS pedido_numero
    FROM projetos pr
    JOIN pedidos ped ON ped.id = pr.id_pedido
    WHERE pr.id = ?
");
$stmt->execute([$idProjeto]);
$projeto = $stmt->fetch();
[$potencia, $classe] = parsePotenciaClasse($projeto['projeto_descricao'] ?? null);

$stmt = $pdo->prepare("
    SELECT r.*, rep.codigo AS reprova_codigo, rep.familia AS reprova_familia, rep.descricao AS reprova_descricao
    FROM retrabalhos r
    LEFT JOIN reprovas rep ON rep.id = r.id_reprova
    WHERE r.id_projeto = ? AND r.ns_transformador = ? AND r.deleted_at IS NULL
    ORDER BY r.data_reprova DESC, r.id DESC
");
$stmt->execute([$idProjeto, $ns]);
$itens = $stmt->fetchAll();

// Reprovas já encerradas (finalizado) saem desta lista — ficam só na Relação do Histórico (historico.php).
$itensAbertos = array_values(array_filter($itens, fn ($r) => !in_array($r['status'], ['finalizado'], true)));

if (empty($itensAbertos)) {
    ?>
    <div class="card">
        <p style="font-size:13px;color:var(--color-text-secondary,#6b7280);">Nenhuma reprova aberta encontrada para este N° de série.</p>
        <a href="<?= htmlspecialchars($base) ?>/pages/retrabalho/relacao.php" class="btn btn-secondary" style="margin-top:10px;">&larr; Voltar para a Relação de Retrabalhos</a>
    </div>
    <?php
    layoutFooter();
    exit;
}

// Se já existe reprova aberta (não finalizada) para este NS/projeto, uma reprova
// nova na Triagem é opcional — ver acao=registrar em api/retrabalho-acao.php.
$temReprovaAberta = (bool) $itensAbertos;

$reprovas = $pdo->query("SELECT id, codigo, familia, descricao, local FROM reprovas WHERE ativo = 1 ORDER BY ordem, LENGTH(codigo), codigo")->fetchAll();

// Materiais já gravados para o lote deste NS/projeto (ver id_lote em
// api/retrabalho-acao.php::case 'registrar') — pré-renderizados na tabela pra
// não se perderem ao reabrir a Triagem pra editar.
$idLote = null;
$dataInicio = null;
foreach ($itensAbertos as $r) {
    if ($r['id_lote'] && !$idLote) { $idLote = (int) $r['id_lote']; }
    if (!empty($r['data_inicio']) && !$dataInicio) { $dataInicio = $r['data_inicio']; }
}
if (!$dataInicio) {
    foreach ($itens as $r) {
        if (!empty($r['data_inicio'])) { $dataInicio = $r['data_inicio']; break; }
    }
}
$materiaisExistentes = [];
if ($idLote) {
    try {
        $stmtMat = $pdo->prepare("SELECT codigo, descricao, unidade, quantidade, preco_medio FROM retrabalho_material_uso WHERE id_lote = ? ORDER BY id");
        $stmtMat->execute([$idLote]);
        $materiaisExistentes = $stmtMat->fetchAll();
    } catch (\Throwable $e) {
        try {
            $pdo->exec("ALTER TABLE retrabalho_material_uso ADD COLUMN preco_medio DECIMAL(14,4) NULL DEFAULT NULL AFTER quantidade");
            $stmtMat = $pdo->prepare("SELECT codigo, descricao, unidade, quantidade, preco_medio FROM retrabalho_material_uso WHERE id_lote = ? ORDER BY id");
            $stmtMat->execute([$idLote]);
            $materiaisExistentes = $stmtMat->fetchAll();
        } catch (\Throwable $e2) {
            $stmtMat = $pdo->prepare("SELECT codigo, descricao, unidade, quantidade FROM retrabalho_material_uso WHERE id_lote = ? ORDER BY id");
            $stmtMat->execute([$idLote]);
            $materiaisExistentes = $stmtMat->fetchAll();
        }
    }
}

if (!function_exists('fmtDataBR')) {
    function fmtDataBR(?string $iso): string
    {
        if (!$iso) return '—';
        $ts = strtotime($iso);
        return $ts ? date('d/m/y', $ts) : '—';
    }
}

if (!function_exists('rtdMaterialLinha')) {
    /**
     * Markup de 1 linha da tabela de materiais utilizados — reaproveitado tanto
     * para pré-renderizar as linhas já gravadas (retrabalho_material_uso) quanto
     * como referência da estrutura que assets/js/retrabalho-detalhe.js clona ao
     * adicionar uma linha nova (busca ou manual).
     */
    function rtdMaterialLinha(array $item): string
    {
        $codigo    = trim((string) ($item['codigo'] ?? ''));
        $descricao = trim((string) ($item['descricao'] ?? ''));
        $unidade   = trim((string) ($item['unidade'] ?? ''));
        $qtd       = $item['quantidade'] ?? '';
        $preco     = isset($item['preco_medio']) && $item['preco_medio'] !== null && $item['preco_medio'] !== '' ? (float) $item['preco_medio'] : null;
        $qtdNum    = (float) ($qtd ?: 0);
        $subtotal  = $preco !== null ? ($qtdNum * $preco) : null;

        $precoFmt    = $preco !== null ? 'R$ ' . number_format($preco, 2, ',', '.') : '—';
        $subtotalFmt = $subtotal !== null ? 'R$ ' . number_format($subtotal, 2, ',', '.') : '—';

        ob_start();
        ?>
        <tr class="rtd-material-linha" data-codigo="<?= htmlspecialchars($codigo) ?>" data-preco="<?= $preco !== null ? htmlspecialchars((string) $preco) : '' ?>">
            <td class="rtd-material-col-codigo">
                <input type="hidden" name="material_codigo[]" value="<?= htmlspecialchars($codigo) ?>"><?= htmlspecialchars($codigo !== '' ? $codigo : '—') ?>
            </td>
            <td class="rtd-material-col-desc">
                <input type="hidden" name="material_descricao[]" value="<?= htmlspecialchars($descricao) ?>"><?= htmlspecialchars($descricao) ?>
            </td>
            <td class="rtd-material-col-qtd">
                <input type="number" name="material_qtd[]" step="0.01" min="0" class="form-control js-material-qtd" value="<?= htmlspecialchars((string) $qtd) ?>" placeholder="Qtd">
            </td>
            <td class="rtd-material-col-unid">
                <input type="hidden" name="material_unidade[]" value="<?= htmlspecialchars($unidade) ?>"><?= htmlspecialchars($unidade !== '' ? $unidade : '—') ?>
            </td>
            <td class="rtd-material-col-preco" style="text-align:right;font-size:12.5px;color:#334155;white-space:nowrap;">
                <input type="hidden" name="material_preco_unitario[]" value="<?= $preco !== null ? htmlspecialchars((string) $preco) : '' ?>">
                <span class="js-material-preco-txt"><?= htmlspecialchars($precoFmt) ?></span>
            </td>
            <td class="rtd-material-col-subtotal" style="text-align:right;font-size:12.5px;font-weight:700;color:#0f172a;white-space:nowrap;">
                <span class="js-material-subtotal-txt"><?= htmlspecialchars($subtotalFmt) ?></span>
            </td>
            <td class="rtd-material-col-acao" style="text-align:center;">
                <button type="button" class="rtd-item-del js-remover-material" title="Remover material">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                </button>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }
}

if (!function_exists('rtdBlocoReprova')) {
    function rtdBlocoReprova(array $reprovas): string
    {
        ob_start();
        ?>
        <div class="rtd-reprova-bloco" style="border:1px solid #e2e8f0;border-radius:8px;padding:14px;margin-bottom:12px;background:#fafbfc;">
            <div class="rtd-item-form">
                <div class="form-group full">
                    <label class="form-label">Código de reprova *</label>
                    <select name="id_reprova[]" class="form-control js-reprova-sel">
                        <option value="">Selecione…</option>
                        <?php foreach ($reprovas as $rp): ?>
                            <option value="<?= (int) $rp['id'] ?>"><?= htmlspecialchars($rp['codigo'] . ' — ' . $rp['descricao']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="hidden" name="data_reprova[]" class="js-data-reprova" value="<?= date('Y-m-d') ?>">
                <div class="form-group full">
                    <label class="form-label">Família</label>
                    <input type="text" class="form-control js-familia" placeholder="— selecione o código —" disabled>
                </div>
            </div>
            <div class="rtd-bloco-actions" style="display:flex;justify-content:flex-end;margin-top:8px;">
                <button type="button" class="btn btn-secondary btn-sm js-remover-bloco">✕ Remover</button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
?>

<style>
    .rtd-grid { display:grid; grid-template-columns:repeat(3, 1fr); gap:14px; }
    .rtd-grid .full { grid-column:1 / -1; }
    .rtd-item { position:relative; border:1px solid var(--color-border,#e5e7eb); border-radius:10px; padding:14px 16px; margin-bottom:14px; }
    .rtd-item:last-child { margin-bottom:0; }
    .rtd-item-del { background:none; border:1px solid var(--color-border,#e5e7eb); border-radius:6px; width:26px; height:26px; padding:0; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; color:var(--color-text-muted,#9aa3b8); }
    .rtd-item-del svg { width:14px; height:14px; }
    .rtd-item-del:hover { border-color:#dc2626; color:#dc2626; background:#fef2f2; }
    .rtd-item-head { display:flex; flex-wrap:wrap; align-items:center; gap:10px; margin-bottom:12px; padding-bottom:12px; border-bottom:1px solid var(--color-border,#f1f5f9); font-size:13px; }
    .rtd-item-head .rt-code { font-family:'JetBrains Mono',monospace; font-weight:600; }
    .rtd-item-head .rt-desc { color:var(--color-text-secondary,#6b7280); font-size:12px; }
    
    @media (max-width: 1024px) {
        .rtd-grid { grid-template-columns:repeat(2, 1fr); }
    }
    @media (max-width: 640px) {
        .rtd-grid { grid-template-columns: 1fr; }
    }
    .rtd-setores { display:flex; flex-wrap:wrap; gap:10px; margin-top:8px; }
    .rtd-setor-pill, .rtd-setor-chip, .rtd-setor-card {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        background: #ffffff;
        border: 1.5px solid #cbd5e1;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        color: #475569;
        cursor: pointer;
        user-select: none;
        transition: all 0.15s ease;
    }
    .rtd-setor-pill:hover, .rtd-setor-chip:hover, .rtd-setor-card:hover {
        border-color: #f59e0b;
        background: #fffdf5;
        color: #1e293b;
    }
    .rtd-setor-pill input[type="checkbox"],
    .rtd-setor-chip input[type="checkbox"],
    .rtd-setor-card input[type="checkbox"] {
        width: 16px;
        height: 16px;
        margin: 0;
        cursor: pointer;
        accent-color: #d97706;
    }
    .rtd-setor-pill:has(input:checked),
    .rtd-setor-chip:has(input:checked),
    .rtd-setor-card:has(input:checked),
    .rtd-setor-pill.is-checked,
    .rtd-setor-chip.is-checked {
        border-color: #d97706 !important;
        background: #fffbeb !important;
        color: #92400e !important;
        box-shadow: 0 1px 3px rgba(245, 158, 11, 0.18);
    }
    .rtd-setor-pill.is-disabled,
    .rtd-setor-chip.is-disabled,
    .rtd-setor-pill:has(input:disabled),
    .rtd-setor-chip:has(input:disabled) {
        opacity: 0.5;
        cursor: not-allowed;
        background: #f1f5f9 !important;
        border-color: #e2e8f0 !important;
        color: #94a3b8 !important;
    }
    .rtd-actions { display:flex; justify-content:flex-end; gap:12px; margin-top:20px; }
    .rtd-anexos-preview { display:flex; flex-wrap:wrap; gap:8px; margin-top:8px; }
    .rtd-anexo-tag { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; background:#f3f4f6; border-radius:6px; font-size:12px; color:#374151; }
    .rtd-anexo-tag a { color:inherit; text-decoration:underline; }
    .rtd-anexo-del { background:none; border:none; color:#9ca3af; cursor:pointer; padding:0; line-height:1; }
    .rtd-anexo-del:hover { color:#ef4444; }

    /* Autocomplete de Materiais */
    .rtd-material-busca { position:relative; margin-bottom:12px; }
    .rtd-material-busca-input-wrap { position:relative; }
    .rtd-material-busca-acoes { display:flex; align-items:center; gap:8px; margin-top:8px; }
    .rtd-material-sugestoes {
        position:absolute; top:calc(100% + 4px); left:0; right:0; z-index:50;
        background:#ffffff; border:1px solid #e5e7eb; border-radius:8px;
        box-shadow:0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.05);
        max-height:280px; overflow-y:auto; padding:0; margin:0;
    }
    .rtd-material-sugestoes-header {
        display:grid; grid-template-columns: 100px 1fr 60px 110px; gap: 10px;
        padding:8px 14px; background:#f9fafb; border-bottom:1px solid #e5e7eb;
        font-size:11px; font-weight:700; text-transform:uppercase; color:#6b7280;
        letter-spacing:0.04em; position:sticky; top:0; z-index:2;
    }
    .rtd-material-sugestoes-header .mat-col-preco { text-align:right; }
    .rtd-material-sugestoes-header .mat-col-unid { text-align:center; }
    .rtd-material-sugestao {
        display:grid; grid-template-columns: 100px 1fr 60px 110px; gap: 10px;
        align-items:center; padding:8px 14px; border-bottom:1px solid #f3f4f6;
        cursor:pointer; font-size:13px; transition:background 0.15s ease;
    }
    .rtd-material-sugestao:last-child { border-bottom:none; }
    .rtd-material-sugestao:hover,
    .rtd-material-sugestao.is-active { background:#f3f4f6; }
    .rtd-material-sugestao .mat-codigo {
        font-family:'JetBrains Mono', monospace; font-weight:600; font-size:12px;
        color:#0f5132; background:#e8f5e9; border:1px solid #c8e6c9;
        padding:2px 6px; border-radius:4px; display:inline-block; width:fit-content;
    }
    .rtd-material-sugestao .mat-desc {
        color:#1f2937; font-weight:500; line-height:1.35;
    }
    .rtd-material-sugestao .mat-unid {
        text-align:center; font-size:11px; font-weight:600;
        color:#4b5563; background:#f3f4f6; border-radius:4px;
        padding:2px 6px; display:inline-block;
        text-transform:uppercase;
    }
    .rtd-material-sugestao .mat-preco {
        text-align:right; font-size:12px; font-weight:700;
        color:#1e40af; background:#eff6ff; border:1px solid #bfdbfe; border-radius:4px;
        padding:2px 8px; display:inline-block; margin-left:auto;
        white-space:nowrap;
    }
    .rtd-material-sugestao.is-vazio {
        display:block; padding:16px 14px; text-align:center;
        color:#9ca3af; font-size:13px; cursor:default;
    }
    @media (max-width: 640px) {
        .rtd-material-sugestoes-header { grid-template-columns: 85px 1fr 45px 85px; gap: 6px; padding: 6px 10px; font-size: 10px; }
        .rtd-material-sugestao { grid-template-columns: 85px 1fr 45px 85px; gap: 6px; padding: 8px 10px; font-size: 12px; }
    }
    .rtd-material-tabela-wrap { border:1px solid #e5e7eb; border-radius:8px; overflow:hidden; margin-bottom:10px; }
    .rtd-material-tabela { width:100%; border-collapse:collapse; font-size:13px; }
    .rtd-material-tabela th { background:#f9fafb; padding:8px 12px; text-align:left; font-weight:600; color:#4b5563; border-bottom:1px solid #e5e7eb; font-size:12px; }
    .rtd-material-tabela td { padding:8px 12px; border-bottom:1px solid #f3f4f6; vertical-align:middle; }
    .rtd-material-tabela tr:last-child td { border-bottom:none; }
    .rtd-material-col-codigo { width:140px; font-family:'JetBrains Mono',monospace; font-weight:500; }
    .rtd-material-col-qtd { width:110px; }
    .rtd-material-col-qtd input { height:32px; padding:2px 8px; font-size:13px; }
    .rtd-material-col-unid { width:70px; color:#6b7280; font-size:12px; text-transform:uppercase; }
    .rtd-material-col-acao { width:40px; text-align:center; }
    .rtd-material-vazio { padding:18px 12px; text-align:center; color:#9ca3af; font-size:12px; }
    .rtd-material-manual-wrap {
        display:grid; grid-template-columns:140px 1fr 90px 100px auto; gap:8px;
        align-items:center; margin-top:8px; padding:10px 12px;
        background:#f9fafb; border:1px dashed #d1d5db; border-radius:8px;
    }
    @media (max-width: 768px) {
        .rtd-material-manual-wrap { grid-template-columns:1fr; }
    }

    /* Modal padrão */
    .modal-overlay { display: none; }
</style>

<div class="rtd-wrap">

<div style="margin-bottom:18px;">
    <h1 style="font-size:var(--font-size-xl,20px);font-weight:700;margin-top:2px;">Retrabalho — N° <?= htmlspecialchars($ns) ?></h1>
    <p class="text-secondary" style="font-size:13px;color:var(--color-text-secondary,#6b7280);margin-top:2px;">
        Projeto <?= htmlspecialchars($projeto['projeto_codigo'] ?? '—') ?> · Pedido <?= htmlspecialchars($projeto['pedido_numero'] ?? '—') ?>
    </p>
</div>

<div class="card" style="margin-bottom:20px;">
    <div class="card-header"><div><div class="card-title">Dados do projeto</div></div></div>
    <div class="rtd-grid">
        <div class="form-group"><label class="form-label">Pedido</label><input class="form-control" value="<?= htmlspecialchars($projeto['pedido_numero'] ?? '—') ?>" disabled></div>
        <div class="form-group"><label class="form-label">Projeto</label><input class="form-control" value="<?= htmlspecialchars($projeto['projeto_codigo'] ?? '—') ?>" disabled></div>
        <div class="form-group"><label class="form-label">N° de série</label><input class="form-control" value="<?= htmlspecialchars($ns) ?>" disabled></div>
        <div class="form-group full"><label class="form-label">Descrição</label><input class="form-control" value="<?= htmlspecialchars($projeto['projeto_descricao'] ?? '—') ?>" disabled></div>
        <div class="form-group"><label class="form-label">Potência</label><input class="form-control" value="<?= $potencia !== null ? htmlspecialchars($potencia) . ' kVA' : '—' ?>" disabled></div>
        <div class="form-group"><label class="form-label">Classe</label><input class="form-control" value="<?= $classe !== null ? htmlspecialchars($classe) . ' kV' : '—' ?>" disabled></div>
    </div>
</div>

<div class="card" style="margin-bottom:20px;" id="rtd-reprovas-card">
    <div class="card-header">
        <div>
            <div class="card-title">Reprovas registradas</div>
            <div class="card-subtitle" id="rtd-reprovas-subtitle"><?= count($itensAbertos) ?> reprova(s) registradas para este N° de série</div>
        </div>
        <button type="button" class="btn btn-primary btn-sm" id="rtd-toggle-add" aria-expanded="false">+ Nova Reprova</button>
    </div>
    <div id="rtd-reprovas-lista">
    <?php foreach ($itensAbertos as $r): 
        $origem = strtoupper(trim((string)($r['estacao'] ?? '')));
        $podeExcluir = ($origem === 'RET');
    ?>
        <div class="rtd-item" data-id="<?= (int) $r['id'] ?>" style="border:1px solid #e2e8f0;border-radius:10px;padding:16px;margin-bottom:14px;background:#ffffff;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid #f1f5f9;">
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="font-weight:700;font-size:13px;color:#0f172a;font-family:monospace;"><?= htmlspecialchars($r['reprova_codigo'] ?? '—') ?></span>
                    <?php if ($origem !== ''): ?>
                        <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:4px;border:1px solid;<?= $origem === 'LAB' ? 'background:#f5f3ff;color:#7c3aed;border-color:#ddd6fe;' : ($origem === 'RET' ? 'background:#fff7ed;color:#ea580c;border-color:#fed7aa;' : 'background:#eff6ff;color:#2563eb;border-color:#bfdbfe;') ?>">
                            Origem: <?= htmlspecialchars($origem) ?>
                        </span>
                    <?php endif; ?>
                </div>
                <?php if ($podeExcluir): ?>
                    <button type="button" class="btn-icon btn-icon-danger btn-icon-sm js-remover-reprova" data-id="<?= (int) $r['id'] ?>" title="Excluir esta reprova adicionada no retrabalho">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                    </button>
                <?php endif; ?>
            </div>
            <div class="rtd-grid">
                <div class="form-group"><label class="form-label">Código</label><input class="form-control" value="<?= htmlspecialchars($r['reprova_codigo'] ?? '—') ?>" disabled></div>
                <div class="form-group"><label class="form-label">Família</label><input class="form-control" value="<?= htmlspecialchars($r['reprova_familia'] ?? '—') ?>" disabled></div>
                <div class="form-group"><label class="form-label">Descrição</label><input class="form-control" value="<?= htmlspecialchars($r['reprova_descricao'] ?? '—') ?>" disabled></div>
                <div class="form-group"><label class="form-label">Data da reprova</label><input class="form-control" value="<?= htmlspecialchars(fmtDataBR($r['data_reprova'])) ?>" disabled></div>
                <div class="form-group">
                    <label class="form-label">Causa da Reprova *</label>
                    <button type="button" class="btn btn-secondary btn-sm js-abrir-causa-raiz"
                            data-id="<?= (int) $r['id'] ?>"
                            data-causa-raiz="<?= htmlspecialchars($r['causa_raiz'] ?? '') ?>">
                        <?= !empty($r['causa_raiz']) ? '✓ Ver / editar causa da reprova' : '+ Adicionar causa da reprova' ?>
                    </button>
                </div>
                <div class="form-group">
                    <label class="form-label">Correção *</label>
                    <button type="button" class="btn btn-secondary btn-sm js-abrir-correcao"
                            data-id="<?= (int) $r['id'] ?>"
                            data-correcao="<?= htmlspecialchars($r['correcao'] ?? '') ?>">
                        <?= !empty($r['correcao']) ? '✓ Ver / editar correção' : '+ Adicionar correção' ?>
                    </button>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
</div>

<form id="rtd-form-add" enctype="multipart/form-data">
    <input type="hidden" name="id_projeto" value="<?= (int) $idProjeto ?>">
    <input type="hidden" name="ns_transformador" value="<?= htmlspecialchars($ns) ?>">
    <input type="hidden" name="estacao" value="RET">

    <div id="rtd-add-wrap" style="display:none;">
        <div class="card" style="margin-bottom:20px;">
            <div class="card-header">
                <div>
                    <div class="card-title">Adicionar nova reprova</div>
                    <div class="card-subtitle">Selecione uma ou mais novas ocorrências de reprova identificadas no Retrabalho</div>
                </div>
            </div>
            <div id="rtd-reprova-blocos"><?= rtdBlocoReprova($reprovas) ?></div>
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:12px;padding-top:12px;border-top:1px solid #f1f5f9;">
                <button type="button" class="btn btn-secondary btn-sm" id="rtd-add-bloco">+ Outro código de reprova</button>
                <button type="button" class="btn btn-primary btn-sm" id="rtd-btn-salvar-nova-reprova" style="font-weight:600;">+ Adicionar Reprova</button>
            </div>
        </div>
        <template id="rtd-bloco-template"><?= rtdBlocoReprova($reprovas) ?></template>
    </div>

    <div class="card">
        <div class="card-header"><div><div class="card-title">Triagem</div><div class="card-subtitle">Chegada ao retrabalho, causa, evidências e para onde encaminhar</div></div></div>
        <div class="rtd-item-form">
            <div class="form-group full">
                <label class="form-label">Data de início do retrabalho</label>
                <input type="text" id="rtd-inicio-display" class="form-control font-mono font-600" readonly
                       value="<?= htmlspecialchars(date('d/m/y H:i', strtotime($dataInicio))) ?>" style="max-width:280px;background:#f8fafc;">
                <p class="rtd-hint">Registrado automaticamente (dia e horário) via leitura de código de barras no início da triagem.</p>
            </div>
            <div class="form-group full">
                <label class="form-label">Materiais utilizados *</label>
                <p class="rtd-hint" style="margin-top:-2px;margin-bottom:8px;">Busque por código ou descrição (aceita <code>%</code> como coringa, ex.: <code>isolador%25kva</code>) e informe a quantidade de cada item usado.</p>

                <div class="rtd-material-busca">
                    <div class="rtd-material-busca-input-wrap">
                        <input type="text" id="rtd-material-busca" class="form-control" placeholder="Buscar material por código ou descrição…" autocomplete="off">
                        <div id="rtd-material-sugestoes" class="rtd-material-sugestoes" style="display:none;"></div>
                    </div>
                    <div class="rtd-material-busca-acoes">
                        <button type="button" class="btn btn-secondary btn-sm" id="rtd-material-manual-toggle" style="white-space:nowrap;">Não encontrei — adicionar manualmente</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="rtd-exportar-csv" style="white-space:nowrap;">Exportar CSV</button>
                    </div>
                </div>

                <div id="rtd-material-manual" class="rtd-material-manual" style="display:none;">
                    <div class="rtd-item-form">
                        <div class="form-group"><label class="form-label">Código (opcional)</label><input type="text" id="rtd-material-manual-codigo" class="form-control"></div>
                        <div class="form-group full"><label class="form-label">Descrição *</label><input type="text" id="rtd-material-manual-descricao" class="form-control" placeholder="Descreva o material"></div>
                        <div class="form-group"><label class="form-label">Unidade</label><input type="text" id="rtd-material-manual-unidade" class="form-control" placeholder="Un, Kg, M…"></div>
                        <div class="form-group"><label class="form-label">Preço Unitário R$ (opcional)</label><input type="number" step="0.01" min="0" id="rtd-material-manual-preco" class="form-control" placeholder="0,00"></div>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm" id="rtd-material-manual-add" style="margin-top:8px;">+ Adicionar à lista</button>
                </div>

                <div class="rtd-material-tabela-wrap">
                    <table class="rtd-material-tabela">
                        <colgroup>
                            <col style="width:100px;">
                            <col>
                            <col style="width:100px;">
                            <col style="width:70px;">
                            <col style="width:115px;">
                            <col style="width:115px;">
                            <col style="width:40px;">
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Descrição</th>
                                <th>Quantidade</th>
                                <th>Unidade</th>
                                <th style="text-align:right;">Preço Médio</th>
                                <th style="text-align:right;">Subtotal</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="rtd-material-linhas">
                            <?php foreach ($materiaisExistentes as $m) echo rtdMaterialLinha($m); ?>
                        </tbody>
                        <tfoot id="rtd-material-total-foot" style="<?= $materiaisExistentes ? '' : 'display:none;' ?>">
                            <tr style="background:#f8fafc;font-weight:700;border-top:2px solid #e2e8f0;">
                                <td colspan="4" style="text-align:right;padding:10px 12px;font-size:13px;color:#475569;">Custo Total dos Materiais:</td>
                                <td colspan="2" style="text-align:right;padding:10px 12px;font-size:14px;color:#1e40af;" id="rtd-material-total-geral">R$ 0,00</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p id="rtd-material-vazio" class="rtd-hint" style="<?= $materiaisExistentes ? 'display:none;' : '' ?>">Nenhum material adicionado ainda.</p>
                
                <div style="margin-top: 14px;">
                    <label class="rtd-setor-pill" style="margin:0;display:inline-flex;align-items:center;cursor:pointer;">
                        <input type="checkbox" id="rtd-material-nenhum" name="nenhum_material" value="1">
                        <span>Nenhum material foi utilizado</span>
                    </label>
                </div>

                <p class="rtd-hint" style="margin-top:12px;margin-bottom:0;">
                    Ao alterar as quantidades acima, o saldo dos materiais será debitado no sistema e o custo refletirá nos relatórios de retrabalho.
                </p>
            </div>
            <div class="form-group full">
                <label class="form-label">Setores de destino *</label>
                <div class="rtd-setores">
                    <?php
                    $setoresChecked = array_filter(array_map('trim', explode(',', (string) ($itensAbertos[0]['setores_destino'] ?? ''))));
                    if (in_array('montagem_final', $setoresChecked, true) && !in_array('inspecao_final', $setoresChecked, true)) {
                        $setoresChecked[] = 'inspecao_final';
                    }
                    foreach (retrabalhoSetoresTriagem() as $chave => $nome):
                        $checked = in_array($chave, $setoresChecked, true) ? 'checked' : '';
                        ?>
                        <label class="rtd-setor-pill">
                            <input type="checkbox" name="setores_destino[]" value="<?= htmlspecialchars($chave) ?>" <?= $checked ?>>
                            <span><?= htmlspecialchars($nome) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-group full">
                <label class="form-label">Observações da Triagem (opcional)</label>
                <textarea id="rtd-observacoes" class="form-control" rows="3" placeholder="Detalhes observados na triagem…"><?= htmlspecialchars($itensAbertos[0]['observacoes'] ?? '') ?></textarea>
            </div>
            <div class="form-group full">
                <label class="form-label">Evidências / Fotos do Retrabalho (opcional)</label>
                <p class="rtd-hint" style="margin-top:-2px;margin-bottom:8px;">Selecione imagens ou PDF do problema identificado (máx. 8MB por arquivo).</p>
                <input type="file" id="rtd-anexos" class="form-control" multiple accept="image/*,application/pdf">
                <div id="rtd-anexos-preview" class="rtd-anexos-preview"></div>
                <?php
                // Lista anexos já gravados (qualquer reprova aberta do NS)
                $idsAbertos = array_map(fn ($r) => (int) $r['id'], $itensAbertos);
                if ($idsAbertos) {
                    $ph = implode(',', array_fill(0, count($idsAbertos), '?'));
                    $stmtAnexos = $pdo->prepare("
                        SELECT a.*, u.nome AS criador_nome
                        FROM retrabalho_anexos a
                        LEFT JOIN usuarios u ON u.id = a.id_criador
                        WHERE a.id_retrabalho IN ($ph) AND a.deleted_at IS NULL
                        ORDER BY a.id DESC
                    ");
                    $stmtAnexos->execute($idsAbertos);
                    $anexosGravados = $stmtAnexos->fetchAll();
                    if ($anexosGravados) {
                        echo '<div class="rtd-anexos-existentes" style="margin-top:12px;">';
                        echo '<div style="font-size:12px;font-weight:600;color:#475569;margin-bottom:6px;">Anexos já vinculados:</div>';
                        echo '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
                        foreach ($anexosGravados as $anx) {
                            $url = $base . '/uploads/retrabalho/' . rawurlencode($anx['nome_arquivo']);
                            $isImg = preg_match('/\.(jpe?g|png|gif|webp)$/i', $anx['nome_arquivo']);
                            echo '<div style="border:1px solid #cbd5e1;border-radius:6px;padding:6px 10px;background:#f8fafc;font-size:12px;display:flex;align-items:center;gap:6px;">';
                            if ($isImg) {
                                echo '<a href="' . htmlspecialchars($url) . '" target="_blank" style="display:flex;align-items:center;gap:6px;color:#1e40af;text-decoration:none;">';
                                echo '<img src="' . htmlspecialchars($url) . '" style="width:28px;height:28px;object-fit:cover;border-radius:4px;" alt="">';
                                echo '<span>' . htmlspecialchars($anx['nome_original']) . '</span>';
                                echo '</a>';
                            } else {
                                echo '<a href="' . htmlspecialchars($url) . '" target="_blank" style="color:#1e40af;text-decoration:none;">📄 ' . htmlspecialchars($anx['nome_original']) . '</a>';
                            }
                            echo '</div>';
                        }
                        echo '</div>';
                        echo '</div>';
                    }
                }
                ?>
            </div>
        </div>

        <div id="rtd-add-erro" style="display:none;background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:8px;padding:8px 12px;font-size:13px;margin-bottom:14px;grid-column:1 / -1;"></div>
        <div class="rtd-item-foot">
            <button type="submit" class="btn btn-primary" id="rtd-add-submit">Enviar</button>
        </div>
    </div>
</form>

<!-- Modal: Causa raiz de uma reprova específica -->
<div class="modal-overlay" id="rtd-causaraiz-modal" style="display:none;">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title">Causa da Reprova</span>
            <button type="button" class="modal-close" id="rtd-causaraiz-close">&times;</button>
        </div>
        <div class="modal-body" style="display:flex;flex-direction:column;gap:14px;">
            <input type="hidden" id="rtd-causaraiz-id" value="">
            <div id="rtd-causaraiz-erro" style="display:none;background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:8px;padding:8px 12px;font-size:13px;"></div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" for="rtd-causaraiz-texto">Descreva a causa da reprova *</label>
                <textarea id="rtd-causaraiz-texto" class="form-control" style="width:100%;min-height:110px;resize:vertical;" placeholder="Ex.: &quot;Fio rompido por fadiga no ponto de solda da bobina AT&quot;"></textarea>
                <span class="form-hint">Salva a causa identificada para esta ocorrência de reprova.</span>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="rtd-causaraiz-cancelar">Cancelar</button>
            <button type="button" class="btn btn-primary" id="rtd-causaraiz-salvar">Salvar Causa</button>
        </div>
    </div>
</div>

<!-- Modal: Correção de uma reprova específica -->
<div class="modal-overlay" id="rtd-correcao-modal" style="display:none;">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title">Correção da Reprova</span>
            <button type="button" class="modal-close" id="rtd-correcao-close">&times;</button>
        </div>
        <div class="modal-body" style="display:flex;flex-direction:column;gap:14px;">
            <input type="hidden" id="rtd-correcao-id" value="">
            <div id="rtd-correcao-erro" style="display:none;background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:8px;padding:8px 12px;font-size:13px;"></div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" for="rtd-correcao-texto">Descreva a correção feita nesta reprova *</label>
                <textarea id="rtd-correcao-texto" class="form-control" style="width:100%;min-height:110px;resize:vertical;" placeholder="Ex.: &quot;Feito nova isolação&quot;"></textarea>
                <span class="form-hint">Salva a ação corretiva executada para esta reprova.</span>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="rtd-correcao-cancelar">Cancelar</button>
            <button type="button" class="btn btn-primary" id="rtd-correcao-salvar">Salvar Correção</button>
        </div>
    </div>
</div>

<!-- Modal: Confirmar Exclusão de Reprova -->
<div class="modal-overlay" id="rtd-excluir-modal" style="display:none;">
    <div class="modal" style="max-width:420px;">
        <div class="modal-header">
            <span class="modal-title">Confirmar Exclusão</span>
            <button type="button" class="modal-close" id="rtd-excluir-close">&times;</button>
        </div>
        <div class="modal-body">
            <p style="font-size:14px;color:var(--color-text-secondary,#6b7280);margin:0 0 6px;">
                Deseja realmente excluir esta reprova adicionada no Retrabalho?
            </p>
            <p style="font-size:12px;color:var(--color-text-muted,#9ca3af);margin:0;">
                Esta ação removerá o apontamento de reprova deste transformador.
            </p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="rtd-excluir-cancelar">Cancelar</button>
            <button type="button" class="btn btn-danger" id="rtd-excluir-confirmar">Excluir Reprova</button>
        </div>
    </div>
</div>

</div><!-- /.rtd-wrap -->

<script>
    window.RETRABALHO_API = <?= json_encode($base . '/api/retrabalho-acao.php') ?>;
    window.RETRABALHO_REPROVAS = <?= json_encode($reprovas, JSON_UNESCAPED_UNICODE) ?>;
    window.RETRABALHO_TEM_REPROVA_ABERTA = <?= json_encode($temReprovaAberta) ?>;
    window.RETRABALHO_ID_PROJETO = <?= json_encode($idProjeto) ?>;
    window.RETRABALHO_NS = <?= json_encode($ns) ?>;
    window.RETRABALHO_VOLTAR = <?= json_encode($voltarUrl) ?>;
</script>
<?php $rtdJsVer = @filemtime(__DIR__ . '/../../assets/js/retrabalho-detalhe.js') ?: (defined('APP_VERSION') ? APP_VERSION : '1'); ?>
<script src="<?= htmlspecialchars($base) ?>/assets/js/retrabalho-detalhe.js?v=<?= htmlspecialchars((string) $rtdJsVer) ?>"></script>

<?php layoutFooter(); ?>
