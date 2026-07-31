<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();

$pdo  = getDB();
$base = defined('APP_URL') ? APP_URL : '';

$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT id_projeto, ns_transformador FROM retrabalhos WHERE id = ? AND deleted_at IS NULL');
$stmt->execute([$id]);
$anchor = $stmt->fetch();

$pageTitle = 'Retrabalho';
require_once __DIR__ . '/../../includes/layout.php';
layoutHeader($pageTitle);

if (!$anchor) {
    ?>
    <div class="card">
        <p style="font-size:13px;color:var(--color-text-secondary,#6b7280);">Registro não encontrado.</p>
        <a href="<?= htmlspecialchars($base) ?>/pages/retrabalho/relacao.php" class="btn btn-secondary" style="margin-top:10px;">&larr; Voltar para a Relação de Retrabalhos</a>
    </div>
    <?php
    layoutFooter();
    exit;
}

$idProjeto = (int) $anchor['id_projeto'];
$ns        = (string) $anchor['ns_transformador'];

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

// Se já existe reprova aberta (não finalizada) para este NS/projeto, uma reprova
// nova na Triagem é opcional — ver acao=registrar em api/retrabalho-acao.php.
$temReprovaAberta = (bool) array_filter($itens, fn ($r) => $r['status'] !== 'finalizado');

// data_inicio é compartilhada por todo o lote — pega a primeira já gravada (se houver).
$dataInicioAtual = null;
foreach ($itens as $it) { if (!empty($it['data_inicio'])) { $dataInicioAtual = $it['data_inicio']; break; } }

$reprovas = $pdo->query("SELECT id, codigo, familia, descricao, local FROM reprovas WHERE ativo = 1 ORDER BY ordem, codigo")->fetchAll();
$materiaisCatalogo = retrabalhoMateriaisCatalogo($pdo);
$UNIDADE_LABEL = ['KG' => 'kg', 'L' => 'L', 'UND' => 'und'];

function fmtDataBR(?string $iso): string
{
    if (!$iso) return '—';
    $ts = strtotime($iso);
    return $ts ? date('d/m/y', $ts) : '—';
}

function fmtDataHoraBR(?string $iso): string
{
    if (!$iso) return '—';
    $ts = strtotime($iso);
    return $ts ? date('d/m/y H:i', $ts) : '—';
}

/** Markup de um bloco "Código de reprova" — reaproveitado no bloco inicial e no <template> de fallback do JS. */
function rtdBlocoReprova(array $reprovas): string
{
    ob_start();
    ?>
    <div class="rtd-reprova-bloco">
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
        <div class="rtd-bloco-actions">
            <button type="button" class="btn btn-danger btn-sm js-remover-bloco">✕ Excluir</button>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
?>

<style>
    .rtd-grid { display:grid; grid-template-columns:repeat(3, 1fr); gap:14px; }
    .rtd-grid .full { grid-column:1 / -1; }
    .rtd-item { position:relative; border:1px solid var(--color-border,#e5e7eb); border-radius:10px; padding:14px 16px; margin-bottom:14px; }
    .rtd-item:last-child { margin-bottom:0; }
    .rtd-item-del-wrap { position:absolute; top:10px; right:10px; }
    .rtd-item-del { background:none; border:1px solid var(--color-border,#e5e7eb); border-radius:6px; width:26px; height:26px; padding:0; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; color:var(--color-text-muted,#9aa3b8); }
    .rtd-item-del svg { width:14px; height:14px; }
    .rtd-item-del:hover { border-color:#dc2626; color:#dc2626; background:#fef2f2; }
    .rtd-item-head { display:flex; flex-wrap:wrap; align-items:center; gap:10px; margin-bottom:12px; padding-bottom:12px; border-bottom:1px solid var(--color-border,#f1f5f9); font-size:13px; }
    .rtd-item-head .rt-code { font-family:'JetBrains Mono',monospace; font-weight:600; }
    .rtd-item-head .rt-desc { color:var(--color-text-secondary,#6b7280); font-size:12px; }
    .rtd-badge { display:inline-block; padding:2px 9px; border-radius:9999px; font-size:11px; font-weight:600; white-space:nowrap; }
    .rtd-item-form { display:grid; grid-template-columns:repeat(2, 1fr); gap:12px 14px; }
    .rtd-item-form .full { grid-column:1 / -1; }
    .rtd-item-form textarea { resize:vertical; min-height:52px; }
    .rtd-item-foot { grid-column:1 / -1; display:flex; align-items:center; gap:12px; }
    .rtd-wrap { max-width:820px; margin:0 auto; }
    .rtd-hint { font-size:11px; color:var(--color-text-muted,#6b7280); margin-top:4px; }
    .rtd-setores { display:flex; flex-wrap:wrap; gap:8px; }
    .rtd-setor-chip {
        display:inline-flex; align-items:center; gap:6px; padding:6px 12px;
        border:1px solid var(--color-border-strong,#d1d5db); border-radius:9999px;
        font-size:12px; font-weight:500; cursor:pointer; user-select:none;
        background:var(--color-surface,#fff); color:var(--color-text-secondary,#5a6480);
    }
    .rtd-setor-chip input { margin:0; }
    .rtd-setor-chip:has(input:checked) { background:var(--color-accent-light,#fef3e2); border-color:var(--color-accent,#E89B1C); color:var(--color-accent-text,#a15c0a); font-weight:600; }
    .rtd-anexos { display:flex; flex-wrap:wrap; gap:8px; margin-top:6px; }
    .rtd-anexo-link {
        display:inline-flex; align-items:center; gap:5px; font-size:11px; padding:3px 9px;
        border-radius:6px; background:var(--color-surface-2,#f3f4f6); color:var(--color-text-secondary,#374151);
        text-decoration:none; white-space:nowrap;
    }
    .rtd-anexo-link:hover { background:var(--color-border,#e5e7eb); text-decoration:none; }
    .rtd-reprova-bloco { border:1px solid var(--color-border,#e5e7eb); border-radius:10px; padding:14px 16px; margin-bottom:12px; }
    .rtd-reprova-bloco:last-child { margin-bottom:0; }
    .rtd-bloco-actions { text-align:right; margin-top:10px; }
    .rtd-materiais { display:grid; grid-template-columns:repeat(2, 1fr); gap:0 18px; border:1px solid var(--color-border,#e5e7eb); border-radius:10px; padding:4px 14px; }
    .rtd-material-item { display:flex; align-items:center; gap:8px; padding:7px 0; border-bottom:1px dashed var(--color-border,#f1f5f9); font-size:13px; }
    .rtd-material-item:nth-last-child(-n+3) { border-bottom:none; }
    .rtd-material-desc { flex:1; display:flex; align-items:center; gap:6px; }
    .rtd-material-unid { color:var(--color-text-muted,#9aa3b8); font-size:11px; }
    .rtd-material-qtd input { width:72px; padding:5px 8px; font-size:12px; }
    .rtd-material-outros { grid-column:1 / -1; }
    .rtd-material-outros-desc { flex:1; padding:5px 8px; font-size:12px; }
    /* Modal: Causa raiz (por reprova) */
    .modal-overlay { position:fixed; inset:0; background:rgba(15,23,42,.5); display:none; align-items:flex-start; justify-content:center; z-index:1000; padding:40px 16px; overflow-y:auto; }
    .modal-box { background:#fff; border-radius:14px; width:100%; max-width:520px; box-shadow:0 20px 50px rgba(0,0,0,.3); }
    .modal-head { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #e5e7eb; }
    .modal-head h2 { font-size:16px; font-weight:700; }
    .modal-close { background:none; border:none; font-size:22px; line-height:1; cursor:pointer; color:#9ca3af; }
    .modal-body { padding:18px 20px; }
    .modal-body textarea { width:100%; min-height:120px; padding:8px 10px; border:1px solid #d1d5db; border-radius:8px; font-size:13px; resize:vertical; }
    .modal-foot { display:flex; justify-content:flex-end; gap:10px; padding:16px 20px; border-top:1px solid #e5e7eb; }
</style>

<div class="rtd-wrap">

<div style="margin-bottom:18px;">
    <a href="<?= htmlspecialchars($base) ?>/pages/retrabalho/relacao.php" style="font-size:12px;color:var(--color-text-muted,#9aa3b8);text-decoration:none;">&larr; Relação de Retrabalhos</a>
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
            <div class="card-subtitle" id="rtd-reprovas-subtitle"><?= count($itens) ?> reprova(s) — excluir remove o código; sem nenhuma reprova, este N° de série sai da Relação de Retrabalhos</div>
        </div>
        <button type="button" class="btn btn-danger btn-sm" id="rtd-toggle-add" aria-expanded="false">+ Nova Reprova</button>
    </div>
    <div id="rtd-reprovas-lista">
    <?php foreach ($itens as $r): ?>
        <div class="rtd-item" data-id="<?= (int) $r['id'] ?>">
            <div class="rtd-item-del-wrap">
                <button type="button" class="rtd-item-del js-remover-reprova" data-id="<?= (int) $r['id'] ?>" title="Excluir esta reprova">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                </button>
            </div>
            <div class="rtd-grid">
                <div class="form-group"><label class="form-label">Código</label><input class="form-control" value="<?= htmlspecialchars($r['reprova_codigo'] ?? '—') ?>" disabled></div>
                <div class="form-group"><label class="form-label">Família</label><input class="form-control" value="<?= htmlspecialchars($r['reprova_familia'] ?? '—') ?>" disabled></div>
                <div class="form-group"><label class="form-label">Descrição</label><input class="form-control" value="<?= htmlspecialchars($r['reprova_descricao'] ?? '—') ?>" disabled></div>
                <div class="form-group"><label class="form-label">Data da reprova</label><input class="form-control" value="<?= htmlspecialchars(fmtDataBR($r['data_reprova'])) ?>" disabled></div>
                <div class="form-group">
                    <label class="form-label">Causa Raiz</label>
                    <button type="button" class="btn btn-secondary btn-sm js-abrir-causa-raiz"
                            data-id="<?= (int) $r['id'] ?>"
                            data-causa-raiz="<?= htmlspecialchars($r['causa_raiz'] ?? '') ?>">
                        <?= !empty($r['causa_raiz']) ? '✓ Ver / editar causa raiz' : '+ Adicionar causa raiz' ?>
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

    <div id="rtd-add-wrap" style="display:none;">
        <div class="card" style="margin-bottom:20px;">
            <div class="card-header"><div><div class="card-title">Adicionar nova reprova</div><div class="card-subtitle">Uma ou mais novas ocorrências de reprova para este mesmo N° de série</div></div></div>
            <div id="rtd-reprova-blocos"><?= rtdBlocoReprova($reprovas) ?></div>
            <div style="margin-top:6px;">
                <button type="button" class="btn btn-danger btn-sm" id="rtd-add-bloco">+ Adicionar outra reprova</button>
            </div>
        </div>
        <template id="rtd-bloco-template"><?= rtdBlocoReprova($reprovas) ?></template>
    </div>

    <div class="card">
        <div class="card-header"><div><div class="card-title">Triagem</div><div class="card-subtitle">Chegada ao retrabalho, causa, evidências e para onde encaminhar</div></div></div>
        <div class="rtd-item-form">
            <div class="form-group full">
                <label class="form-label">Data de início do retrabalho</label>
                <div style="display:flex;gap:10px;align-items:center;">
                    <input type="text" id="rtd-inicio-display" class="form-control" readonly
                           placeholder="— aguardando leitura do QR Code —"
                           value="<?= $dataInicioAtual ? htmlspecialchars(fmtDataHoraBR($dataInicioAtual)) : '' ?>">
                    <button type="button" class="btn btn-secondary" id="rtd-inicio-scan-btn" style="white-space:nowrap;">
                        Escanear QR
                    </button>
                </div>
                <p class="rtd-hint">Registrado automaticamente (dia e horário) ao ler o QR Code do transformador.</p>
            </div>
            <div class="form-group full">
                <label class="form-label">Materiais utilizados</label>
                <p class="rtd-hint" style="margin-top:-2px;margin-bottom:8px;">Marque o que foi gasto neste retrabalho e informe a quantidade.</p>
                <div class="rtd-materiais">
                    <?php foreach ($materiaisCatalogo as $mat): ?>
                        <label class="rtd-material-item">
                            <input type="checkbox" name="material_usado[]" value="<?= (int) $mat['id'] ?>" class="js-material-check">
                            <span class="rtd-material-desc">
                                <?= htmlspecialchars($mat['descricao']) ?>
                                <?php if ($mat['unidade'] !== 'UND'): ?><span class="rtd-material-unid">(<?= $UNIDADE_LABEL[$mat['unidade']] ?>)</span><?php endif; ?>
                            </span>
                            <span class="rtd-material-qtd">
                                <input type="number" name="material_qtd[<?= (int) $mat['id'] ?>]" step="0.01" min="0" placeholder="Qtd" class="form-control">
                            </span>
                        </label>
                    <?php endforeach; ?>
                    <label class="rtd-material-item rtd-material-outros">
                        <input type="checkbox" name="material_outro_check" value="1" class="js-material-check">
                        <span class="rtd-material-desc">
                            Outros:
                            <input type="text" name="material_outro_desc" placeholder="Descreva o material" class="form-control rtd-material-outros-desc">
                        </span>
                        <span class="rtd-material-qtd">
                            <input type="number" name="material_outro_qtd" step="0.01" min="0" placeholder="Qtd" class="form-control">
                        </span>
                    </label>
                </div>
            </div>
            <div class="form-group full">
                <label class="form-label">Observações</label>
                <textarea name="observacoes" class="form-control" placeholder="Detalhes adicionais (opcional)"></textarea>
            </div>
            <div class="form-group full">
                <label class="form-label">Próximos setores</label>
                <div class="rtd-setores">
                    <?php foreach (retrabalhoSetoresTriagem() as $slug => $label): ?>
                        <label class="rtd-setor-chip">
                            <input type="checkbox" name="setores_destino[]" value="<?= htmlspecialchars($slug) ?>">
                            <?= htmlspecialchars($label) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div id="rtd-add-erro" style="display:none;background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:8px;padding:8px 12px;font-size:13px;margin-bottom:14px;grid-column:1 / -1;"></div>
            <div class="rtd-item-foot">
                <button type="submit" class="btn btn-primary" id="rtd-add-submit">Enviar</button>
            </div>
        </div>
    </div>
</form>

<!-- Modal: Causa raiz de uma reprova específica -->
<div class="modal-overlay" id="rtd-causaraiz-modal">
    <div class="modal-box">
        <div class="modal-head">
            <h2>Causa raiz</h2>
            <button type="button" class="modal-close" id="rtd-causaraiz-close">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="rtd-causaraiz-id" value="">
            <div id="rtd-causaraiz-erro" style="display:none;background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:8px;padding:8px 12px;font-size:13px;margin-bottom:14px;"></div>
            <label class="form-label" for="rtd-causaraiz-texto">Descreva a causa raiz desta reprova</label>
            <textarea id="rtd-causaraiz-texto" placeholder="Ex.: &quot;Fio rompido por fadiga no ponto de solda da bobina AT&quot;"></textarea>
            <p class="rtd-hint">Preenchida, esta reprova específica passa para "Finalizado" — as demais reprovas deste N° de série não são afetadas.</p>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-secondary" id="rtd-causaraiz-cancelar">Cancelar</button>
            <button type="button" class="btn btn-primary" id="rtd-causaraiz-salvar">Salvar</button>
        </div>
    </div>
</div>

</div><!-- /.rtd-wrap -->

<!-- Scan overlay: leitura do QR Code para registrar dia + horário de início do retrabalho -->
<div class="scan-overlay" id="rtd-inicio-overlay" role="dialog" aria-modal="true" aria-label="Registrar início do retrabalho por QR Code">
    <div class="scan-overlay__bar">
        <span>
            Início do retrabalho — <strong><?= htmlspecialchars($ns) ?></strong>
            · <?= htmlspecialchars($projeto['projeto_codigo'] ?? '—') ?>
        </span>
        <button type="button" class="scan-overlay__close" id="rtd-inicio-close" aria-label="Fechar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>

    <div class="scan-stage" id="rtd-inicio-stage">
        <video id="rtd-inicio-video" autoplay playsinline muted></video>
        <div class="viewfinder" id="rtd-inicio-viewfinder">
            <span class="corner corner--tl"></span><span class="corner corner--tr"></span>
            <span class="corner corner--bl"></span><span class="corner corner--br"></span>
            <span class="scan-line"></span>
        </div>
        <div class="scan-stage__empty" id="rtd-inicio-stage-empty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
            <p id="rtd-inicio-stage-empty-text">Solicitando acesso à câmera…</p>
        </div>
    </div>

    <p class="scan-hint" id="rtd-inicio-hint">Aponte a câmera para o QR Code do transformador</p>
    <div class="scan-overlay__error" id="rtd-inicio-error"></div>

    <div class="scan-overlay__manual">
        <label for="rtd-inicio-manual-ns">Ou digite o número de série manualmente</label>
        <div class="manual-row">
            <input id="rtd-inicio-manual-ns" type="text" placeholder="Ex.: 900201" autocomplete="off">
            <button class="btn btn-secondary" id="rtd-inicio-manual-btn" type="button">Buscar</button>
        </div>
    </div>
</div>

<div id="rtd-inicio-live-region" aria-live="polite" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;"></div>

<script>
    window.RETRABALHO_API = <?= json_encode($base . '/api/retrabalho-acao.php') ?>;
    window.RETRABALHO_REPROVAS = <?= json_encode($reprovas, JSON_UNESCAPED_UNICODE) ?>;
    window.RETRABALHO_TEM_REPROVA_ABERTA = <?= json_encode($temReprovaAberta) ?>;
    window.RETRABALHO_ID_PROJETO = <?= json_encode($idProjeto) ?>;
    window.RETRABALHO_NS = <?= json_encode($ns) ?>;
    window.RETRABALHO_VOLTAR = <?= json_encode($base . '/pages/retrabalho/relacao.php') ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
<?php $rtdJsVer = @filemtime(__DIR__ . '/../../assets/js/retrabalho-detalhe.js') ?: (defined('APP_VERSION') ? APP_VERSION : '1'); ?>
<script src="<?= htmlspecialchars($base) ?>/assets/js/retrabalho-detalhe.js?v=<?= htmlspecialchars((string) $rtdJsVer) ?>"></script>

<?php layoutFooter(); ?>
