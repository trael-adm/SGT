<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/layout.php';

requireLogin();
requireAcessoPapel();

$pdo = getDB();
$id = (int)($_GET['id'] ?? 0);
$mensagem = '';
$erro = '';

$peca = [
    'id' => 0,
    'codigo_vsat' => '',
    'nome_engenharia' => '',
    'bloco' => 'parte_ativa',
    'categoria' => '',
    'material' => '',
    'espessura' => ''
];

if ($id > 0) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM papel_pecas WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$id]);
        $dados = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($dados) {
            $peca = array_merge($peca, $dados);
            if (empty($peca['nome_engenharia']) && !empty($dados['nome_canonico'])) {
                $peca['nome_engenharia'] = $dados['nome_canonico'];
            }
        }
    } catch (\Throwable $e) {
        $peca = [
            'id' => $id,
            'codigo_vsat' => '2087284',
            'nome_engenharia' => 'Cilindro BT',
            'bloco' => 'enrolamento_bt',
            'categoria' => 'FORMA (CILINDRO)',
            'material' => 'DIAMANT',
            'espessura' => '0.06'
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nomeEngenharia = trim((string)($_POST['nome_engenharia'] ?? ''));
    $bloco = trim((string)($_POST['bloco'] ?? ''));

    if ($nomeEngenharia === '') {
        $erro = 'O Nome da Engenharia é obrigatório.';
    } else {
        try {
            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE papel_pecas SET nome_engenharia = ?, bloco = ? WHERE id = ?');
                $stmt->execute([$nomeEngenharia, $bloco, $id]);
                $mensagem = 'Nome padronizado da Engenharia atualizado com sucesso!';
                $peca['nome_engenharia'] = $nomeEngenharia;
                $peca['bloco'] = $bloco;
            }
        } catch (\Throwable $e) {
            $erro = 'Erro ao salvar peça: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Editar Peça (Engenharia) — Setor de Papel';
layoutHeader($pageTitle);
?>

<style>
.wrap-mc { max-width: 700px; margin: 0 auto; padding: 10px 0 40px; }
.card-mc { background: #fff; border: 1px solid #e2e6ed; border-radius: 8px; box-shadow: 0 1px 3px rgba(16,24,40,.06); margin-bottom: 18px; }
.card-mc-head { padding: 12px 16px; border-bottom: 1px solid #eef1f5; display: flex; align-items: center; justify-content: space-between; }
.card-mc-head h2 { margin: 0; font-size: 15px; font-weight: 600; color: #1a2133; }
.card-mc-body { padding: 18px; }
.card-mc-foot { padding: 12px 18px; border-top: 1px solid #eef1f5; display: flex; justify-content: space-between; background: #f8f9fb; }
.form-field-mc { margin-bottom: 16px; }
.form-field-mc label { display: block; font-size: 12px; font-weight: 600; color: #5a6474; margin-bottom: 4px; }
.form-field-mc input, .form-field-mc select { width: 100%; padding: 8px 10px; border: 1px solid #e2e6ed; border-radius: 6px; font-size: 13px; font-family: inherit; }
.form-field-mc .hint { font-size: 11px; color: #9aa3b8; margin-top: 3px; }
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.btn-ordem { display: inline-block; padding: 6px 14px; font-size: 13px; font-weight: 600; color: #5a6474; border: 1px solid #e2e6ed; border-radius: 6px; text-decoration: none; background: #fff; }
.btn-save { background: #1a3d2a; color: #fff; border: none; padding: 7px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
.mono { font-family: 'JetBrains Mono', monospace; }
</style>

<div class="wrap-mc">

  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
    <h2>Padronização da Engenharia</h2>
    <a href="pecas.php" class="btn-ordem">Voltar ao Catálogo</a>
  </div>

  <?php if ($mensagem !== ''): ?>
    <div style="background:#dcfce7;color:#14532d;padding:10px 14px;border-radius:6px;margin-bottom:16px;font-size:13px;"><?= htmlspecialchars($mensagem) ?></div>
  <?php endif; ?>

  <?php if ($erro !== ''): ?>
    <div style="background:#fee2e2;color:#7f1d1d;padding:10px 14px;border-radius:6px;margin-bottom:16px;font-size:13px;"><?= htmlspecialchars($erro) ?></div>
  <?php endif; ?>

  <div class="card-mc">
    <div class="card-mc-head" style="background:#f8f9fb">
      <h2>Identidade no ERP (Somente Leitura)</h2>
    </div>
    <div class="card-mc-body">
      <div class="grid-2">
        <div class="form-field-mc">
          <label>Código VSAT</label>
          <input type="text" class="mono" value="<?= htmlspecialchars((string)$peca['codigo_vsat']) ?>" readonly style="background:#f8f9fb">
        </div>
        <div class="form-field-mc">
          <label>Material / Espessura ERP</label>
          <input type="text" value="<?= htmlspecialchars((string)($peca['material'] ?? '—')) ?> (<?= htmlspecialchars((string)($peca['espessura'] ?? '—')) ?> mm)" readonly style="background:#f8f9fb">
        </div>
      </div>
    </div>
  </div>

  <form method="POST" class="card-mc">
    <div class="card-mc-head">
      <h2>Nomenclatura da Engenharia</h2>
    </div>
    <div class="card-mc-body">
      <div class="form-field-mc">
        <label>Nome Oficial da Engenharia <span style="color:red">*</span></label>
        <input type="text" name="nome_engenharia" value="<?= htmlspecialchars((string)($peca['nome_engenharia'] ?? '')) ?>" required>
        <div class="hint">Nome único padronizado pela Engenharia para todo o sistema (sem sinonímias).</div>
      </div>

      <div class="form-field-mc">
        <label>Bloco CMI</label>
        <select name="bloco">
          <option value="parte_ativa" <?= $peca['bloco'] === 'parte_ativa' ? 'selected' : '' ?>>Parte Ativa</option>
          <option value="nucleo" <?= $peca['bloco'] === 'nucleo' ? 'selected' : '' ?>>Núcleo</option>
          <option value="enrolamento_at" <?= $peca['bloco'] === 'enrolamento_at' ? 'selected' : '' ?>>Enrolamento AT</option>
          <option value="enrolamento_bt" <?= $peca['bloco'] === 'enrolamento_bt' ? 'selected' : '' ?>>Enrolamento BT</option>
          <option value="mfl" <?= $peca['bloco'] === 'mfl' ? 'selected' : '' ?>>MFL</option>
        </select>
      </div>
    </div>
    <div class="card-mc-foot">
      <a href="pecas.php" class="btn-ordem">Cancelar</a>
      <button type="submit" class="btn-save">Salvar Nome Padronizado</button>
    </div>
  </form>

</div>

<?php
layoutFooter();
