<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/layout.php';

requireLogin();
requireAcessoPapel();

$pdo = getDB();
$mensagem = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string) ($_POST['acao'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $codigoVsat = trim((string) ($_POST['codigo_vsat'] ?? ''));
    $nome = trim((string) ($_POST['nome'] ?? ''));
    $apelidoChao = trim((string) ($_POST['apelido_chao'] ?? ''));
    $observacao = trim((string) ($_POST['observacao'] ?? ''));

    if ($acao === 'salvar') {
        if ($codigoVsat === '' || $nome === '') {
            $erro = 'Código VSAT e Nome da Máquina são obrigatórios.';
        } else {
            try {
                if ($id > 0) {
                    $stmt = $pdo->prepare('UPDATE papel_maquinas SET codigo_vsat = ?, nome = ?, apelido_chao = ?, observacao = ? WHERE id = ?');
                    $stmt->execute([$codigoVsat, $nome, $apelidoChao, $observacao, $id]);
                    $mensagem = 'Máquina atualizada com sucesso!';
                } else {
                    $stmt = $pdo->prepare('INSERT INTO papel_maquinas (codigo_vsat, nome, apelido_chao, observacao, ativo) VALUES (?, ?, ?, ?, 1)');
                    $stmt->execute([$codigoVsat, $nome, $apelidoChao, $observacao]);
                    $mensagem = 'Máquina cadastrada com sucesso!';
                }
            } catch (\Throwable $e) {
                $erro = 'Erro ao salvar máquina: ' . $e->getMessage();
            }
        }
    } elseif ($acao === 'toggle_status' && $id > 0) {
        try {
            $stmt = $pdo->prepare('UPDATE papel_maquinas SET ativo = IF(ativo=1, 0, 1), desativado_em = IF(ativo=1, NOW(), NULL) WHERE id = ?');
            $stmt->execute([$id]);
            $mensagem = 'Status da máquina alterado com sucesso!';
        } catch (\Throwable $e) {
            $erro = 'Erro ao alterar status: ' . $e->getMessage();
        }
    }
}

$maquinas = [];
try {
    $stmt = $pdo->query('SELECT * FROM papel_maquinas WHERE deleted_at IS NULL ORDER BY codigo_vsat ASC');
    $maquinas = $stmt->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $maquinas = [
        ['id' => 1, 'codigo_vsat' => '17001', 'nome' => 'Sliter Papel', 'apelido_chao' => 'Slitter', 'observacao' => 'Corte de bobinas principais', 'ativo' => 1],
        ['id' => 2, 'codigo_vsat' => '17002', 'nome' => 'Guilhotina de Pedal', 'apelido_chao' => 'Guilhotina Manual', 'observacao' => 'Corte de tiras finas', 'ativo' => 1],
        ['id' => 3, 'codigo_vsat' => '17003', 'nome' => 'Máquina Dobradeira de Papel', 'apelido_chao' => 'Dobradeira', 'observacao' => 'Dobras de viga e núcleo', 'ativo' => 1],
        ['id' => 4, 'codigo_vsat' => '17004', 'nome' => 'Sanfonadeira de Papel', 'apelido_chao' => 'Sanfonadeira', 'observacao' => 'Sanfonado de isolação', 'ativo' => 1],
        ['id' => 5, 'codigo_vsat' => '17007', 'nome' => 'Mesa para Colagem Talisca 02', 'apelido_chao' => 'Mesa Colagem 02', 'observacao' => 'Colagem de taliscas', 'ativo' => 1],
        ['id' => 6, 'codigo_vsat' => '17008', 'nome' => 'Mesa para Colagem Talisca 03', 'apelido_chao' => 'Mesa Colagem 03', 'observacao' => 'Colagem de taliscas', 'ativo' => 1],
        ['id' => 7, 'codigo_vsat' => '17012', 'nome' => 'Guilhotina Elétrica', 'apelido_chao' => 'Guilhotina Motorizada', 'observacao' => 'Corte pesado', 'ativo' => 1],
    ];
}

$pageTitle = 'Máquinas — Setor de Papel';
layoutHeader($pageTitle);
?>

<style>
.wrap-mc { max-width: 1360px; margin: 0 auto; padding: 10px 0 40px; }
.card-mc { background: #fff; border: 1px solid #e2e6ed; border-radius: 8px; box-shadow: 0 1px 3px rgba(16,24,40,.06); margin-bottom: 18px; }
.card-mc-head { padding: 12px 16px; border-bottom: 1px solid #eef1f5; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.card-mc-head h2 { margin: 0; font-size: 15px; font-weight: 600; color: #1a2133; }
.card-mc-body { padding: 14px 16px; }
.card-mc-body.flush { padding: 0; }
.table-wrap-mc { overflow-x: auto; width: 100%; }
.table-mc { width: 100%; border-collapse: collapse; margin: 0; }
.table-mc th { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #9aa3b8; font-weight: 600; text-align: left; padding: 9px 12px; border-bottom: 1px solid #e2e6ed; background: #f8f9fb; white-space: nowrap; }
.table-mc td { padding: 9px 12px; border-bottom: 1px solid #eef1f5; font-size: 13px; white-space: nowrap; }
.table-mc tbody tr:hover { background: #fdf6e8; }
.mono { font-family: 'JetBrains Mono', monospace; font-variant-numeric: tabular-nums; }

.btn-add-mc { background: #1a3d2a; color: #fff; border: none; padding: 7px 14px; border-radius: 6px; font-size: 13px; font-weight: 500; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
.btn-add-mc:hover { background: #2d6e45; }
.btn-act { padding: 4px 10px; font-size: 12px; font-weight: 500; border-radius: 4px; cursor: pointer; border: 1px solid #e2e6ed; background: #fff; color: #5a6474; }
.btn-act:hover { background: #f8f9fb; }
.btn-act.danger { color: #dc2626; border-color: #fee2e2; background: #fff5f5; }
.btn-act.danger:hover { background: #fee2e2; }
.btn-act.success { color: #16a34a; border-color: #dcfce7; background: #f0fdf4; }
.btn-act.success:hover { background: #dcfce7; }

.badge-st { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
.b-ativa { background: #dcfce7; color: #15803d; }
.b-inativa { background: #f1f5f9; color: #64748b; }

.alert-mc { padding: 10px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; }
.alert-mc.ok { background: #dcfce7; color: #14532d; border: 1px solid #bbf7d0; }
.alert-mc.err { background: #fee2e2; color: #7f1d1d; border: 1px solid #fecaca; }

/* Modal Customizado sem dependência de Bootstrap */
.modal-overlay-mc { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.4); display: none; align-items: center; justify-content: center; z-index: 9999; }
.modal-overlay-mc.open { display: flex; }
.modal-box-mc { background: #fff; border-radius: 8px; width: 100%; max-width: 480px; box-shadow: 0 10px 25px rgba(0,0,0,0.15); overflow: hidden; }
.modal-box-head { padding: 14px 18px; border-bottom: 1px solid #eef1f5; display: flex; align-items: center; justify-content: space-between; }
.modal-box-head h3 { margin: 0; font-size: 15px; font-weight: 600; }
.modal-box-body { padding: 18px; }
.modal-box-foot { padding: 12px 18px; border-top: 1px solid #eef1f5; display: flex; justify-content: flex-end; gap: 8px; background: #f8f9fb; }
.form-field-mc { margin-bottom: 14px; }
.form-field-mc label { display: block; font-size: 12px; font-weight: 600; color: #5a6474; margin-bottom: 4px; }
.form-field-mc input, .form-field-mc textarea { width: 100%; padding: 7px 10px; border: 1px solid #e2e6ed; border-radius: 6px; font-size: 13px; font-family: inherit; }
</style>

<div class="wrap-mc">

  <div class="card-mc">
    <div class="card-mc-head">
      <h2>Máquinas do Setor de Papel (<?= count($maquinas) ?>)</h2>
      <button class="btn-add-mc" onclick="abrirModal()">+ Nova Máquina</button>
    </div>
    <div class="card-mc-body flush">

      <?php if ($mensagem !== ''): ?>
        <div style="padding:14px 16px 0;"><div class="alert-mc ok"><?= htmlspecialchars($mensagem) ?></div></div>
      <?php endif; ?>

      <?php if ($erro !== ''): ?>
        <div style="padding:14px 16px 0;"><div class="alert-mc err"><?= htmlspecialchars($erro) ?></div></div>
      <?php endif; ?>

      <div class="table-wrap-mc">
        <table class="table-mc">
          <thead>
            <tr>
              <th style="width:110px">Código VSAT</th>
              <th>Nome da Máquina</th>
              <th>Apelido no Chão</th>
              <th>Observação</th>
              <th style="width:100px">Situação</th>
              <th style="width:140px;text-align:right">Ações</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($maquinas as $m): ?>
              <tr style="<?= ((int)$m['ativo'] === 0) ? 'opacity:0.6;' : '' ?>">
                <td class="mono" style="font-weight:600"><?= htmlspecialchars((string)$m['codigo_vsat']) ?></td>
                <td style="font-weight:500"><?= htmlspecialchars((string)$m['nome']) ?></td>
                <td><?= htmlspecialchars((string)($m['apelido_chao'] ?? '—')) ?></td>
                <td style="color:#64748b"><?= htmlspecialchars((string)($m['observacao'] ?? '—')) ?></td>
                <td>
                  <?php if ((int)$m['ativo'] === 1): ?>
                    <span class="badge-st b-ativa">Ativa</span>
                  <?php else: ?>
                    <span class="badge-st b-inativa">Desativada</span>
                  <?php endif; ?>
                </td>
                <td style="text-align:right">
                  <button type="button" class="btn-act" 
                          data-id="<?= $m['id'] ?>"
                          data-codigo="<?= htmlspecialchars((string)$m['codigo_vsat']) ?>"
                          data-nome="<?= htmlspecialchars((string)$m['nome']) ?>"
                          data-apelido="<?= htmlspecialchars((string)($m['apelido_chao'] ?? '')) ?>"
                          data-obs="<?= htmlspecialchars((string)($m['observacao'] ?? '')) ?>"
                          onclick="editarMaquina(this)">
                    Editar
                  </button>
                  <form method="POST" style="display:inline;" onsubmit="return confirm('Deseja alterar a situação desta máquina?');">
                    <input type="hidden" name="acao" value="toggle_status">
                    <input type="hidden" name="id" value="<?= $m['id'] ?>">
                    <?php if ((int)$m['ativo'] === 1): ?>
                      <button type="submit" class="btn-act danger">Desativar</button>
                    <?php else: ?>
                      <button type="submit" class="btn-act success">Reativar</button>
                    <?php endif; ?>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<!-- Modal Form -->
<div class="modal-overlay-mc" id="modalMaquina">
  <div class="modal-box-mc">
    <form method="POST">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" id="m_id" value="0">
      
      <div class="modal-box-head">
        <h3 id="modalTitle">Cadastrar Máquina</h3>
        <button type="button" style="border:none;background:none;font-size:18px;cursor:pointer" onclick="fecharModal()">✕</button>
      </div>
      
      <div class="modal-box-body">
        <div class="form-field-mc">
          <label>Código VSAT <span style="color:red">*</span></label>
          <input type="text" name="codigo_vsat" id="m_codigo" placeholder="ex.: 17001" required>
        </div>
        
        <div class="form-field-mc">
          <label>Nome Oficial <span style="color:red">*</span></label>
          <input type="text" name="nome" id="m_nome" placeholder="ex.: Sliter Papel" required>
        </div>
        
        <div class="form-field-mc">
          <label>Apelido no Chão</label>
          <input type="text" name="apelido_chao" id="m_apelido" placeholder="ex.: Slitter">
        </div>
        
        <div class="form-field-mc">
          <label>Observações</label>
          <textarea name="observacao" id="m_obs" rows="2" placeholder="Detalhes técnicos..."></textarea>
        </div>
      </div>
      
      <div class="modal-box-foot">
        <button type="button" class="btn-act" onclick="fecharModal()">Cancelar</button>
        <button type="submit" class="btn-add-mc">Salvar Máquina</button>
      </div>
    </form>
  </div>
</div>

<script>
function abrirModal() {
  document.getElementById('m_id').value = '0';
  document.getElementById('m_codigo').value = '';
  document.getElementById('m_nome').value = '';
  document.getElementById('m_apelido').value = '';
  document.getElementById('m_obs').value = '';
  document.getElementById('modalTitle').textContent = 'Cadastrar Máquina';
  document.getElementById('modalMaquina').classList.add('open');
}

function fecharModal() {
  document.getElementById('modalMaquina').classList.remove('open');
}

function editarMaquina(el) {
  document.getElementById('m_id').value = el.getAttribute('data-id');
  document.getElementById('m_codigo').value = el.getAttribute('data-codigo');
  document.getElementById('m_nome').value = el.getAttribute('data-nome');
  document.getElementById('m_apelido').value = el.getAttribute('data-apelido');
  document.getElementById('m_obs').value = el.getAttribute('data-obs');
  document.getElementById('modalTitle').textContent = 'Editar Máquina';
  document.getElementById('modalMaquina').classList.add('open');
}
</script>

<?php
layoutFooter();
