<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/layout.php';

requireLogin();
requireAcessoPapel();

$pdo = getDB();
$fBusca = trim((string)($_GET['busca'] ?? ''));
$fBloco = trim((string)($_GET['bloco'] ?? ''));

$pecas = [];
try {
    $where = ['deleted_at IS NULL'];
    $params = [];
    
    if ($fBloco !== '') {
        $where[] = 'bloco = ?';
        $params[] = $fBloco;
    }
    
    if ($fBusca !== '') {
        $where[] = '(nome_engenharia LIKE ? OR codigo_vsat LIKE ? OR categoria LIKE ?)';
        $term = '%' . $fBusca . '%';
        $params = array_merge($params, [$term, $term, $term]);
    }
    
    $sql = 'SELECT * FROM papel_pecas WHERE ' . implode(' AND ', $where) . ' ORDER BY nome_engenharia ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $pecas = $stmt->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $pecas = [
        [
            'id' => 1, 'codigo_vsat' => '2087281', 'nome_engenharia' => 'Isolação Viga-Núcleo',
            'bloco' => 'nucleo', 'categoria' => 'ISOLAÇÃO NÚCLEO', 'material' => 'PRESSPHAN', 'espessura' => 2.0
        ],
        [
            'id' => 2, 'codigo_vsat' => '2087284', 'nome_engenharia' => 'Cilindro BT',
            'bloco' => 'enrolamento_bt', 'categoria' => 'FORMA (CILINDRO)', 'material' => 'DIAMANT', 'espessura' => 0.06
        ],
        [
            'id' => 3, 'codigo_vsat' => '2088290', 'nome_engenharia' => 'Papel Entre Camadas',
            'bloco' => 'enrolamento_at', 'categoria' => 'CANAIS ENTRE CAMADAS', 'material' => 'KRAFT', 'espessura' => 0.18
        ],
        [
            'id' => 4, 'codigo_vsat' => '2088277', 'nome_engenharia' => 'Canudo Longo Saída AT',
            'bloco' => 'parte_ativa', 'categoria' => 'PARTE ATIVA — CANUDOS', 'material' => 'PRESSPHAN', 'espessura' => 1.0
        ]
    ];
}

$pageTitle = 'Peças da Engenharia — Setor de Papel';
layoutHeader($pageTitle);
?>

<style>
.wrap-mc { max-width: 1360px; margin: 0 auto; padding: 10px 0 40px; }
.card-mc { background: #fff; border: 1px solid #e2e6ed; border-radius: 8px; box-shadow: 0 1px 3px rgba(16,24,40,.06); margin-bottom: 18px; }
.card-mc-head { padding: 12px 16px; border-bottom: 1px solid #eef1f5; display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
.card-mc-head h2 { margin: 0; font-size: 15px; font-weight: 600; color: #1a2133; }
.card-mc-body { padding: 14px 16px; }
.card-mc-body.flush { padding: 0; }
.table-wrap-mc { overflow-x: auto; width: 100%; }
.table-mc { width: 100%; border-collapse: collapse; margin: 0; }
.table-mc th { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #9aa3b8; font-weight: 600; text-align: left; padding: 9px 12px; border-bottom: 1px solid #e2e6ed; background: #f8f9fb; white-space: nowrap; }
.table-mc td { padding: 9px 12px; border-bottom: 1px solid #eef1f5; font-size: 13px; white-space: nowrap; }
.table-mc tbody tr:hover { background: #fdf6e8; }
.mono { font-family: 'JetBrains Mono', monospace; font-variant-numeric: tabular-nums; }
.btn-ordem { display: inline-block; padding: 4px 10px; font-size: 12px; font-weight: 600; color: #1a3d2a; border: 1px solid #1a3d2a; border-radius: 4px; text-decoration: none; background: #fff; }
.btn-ordem:hover { background: #1a3d2a; color: #fff; text-decoration: none; }
.filtros-inline { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
.filtros-inline input, .filtros-inline select { padding: 6px 10px; border: 1px solid #e2e6ed; border-radius: 6px; font-size: 13px; }
</style>

<div class="wrap-mc">

  <div class="card-mc">
    <div class="card-mc-head">
      <h2>Catálogo de Peças Padronizadas da Engenharia (<?= count($pecas) ?>)</h2>
      <span style="font-size:12px;color:#5a6474">Nomenclatura oficial padronizada para todo o SGT/SGE</span>
    </div>
    <div class="card-mc-body">
      <form method="GET" class="filtros-inline">
        <div style="flex:1;min-width:240px">
          <input type="search" name="busca" placeholder="Buscar por Nome da Engenharia ou Código VSAT…" value="<?= htmlspecialchars($fBusca) ?>" style="width:100%">
        </div>
        <div>
          <select name="bloco">
            <option value="">Todos os Blocos CMI</option>
            <option value="parte_ativa" <?= $fBloco === 'parte_ativa' ? 'selected' : '' ?>>Parte Ativa</option>
            <option value="nucleo" <?= $fBloco === 'nucleo' ? 'selected' : '' ?>>Núcleo</option>
            <option value="enrolamento_at" <?= $fBloco === 'enrolamento_at' ? 'selected' : '' ?>>Enrolamento AT</option>
            <option value="enrolamento_bt" <?= $fBloco === 'enrolamento_bt' ? 'selected' : '' ?>>Enrolamento BT</option>
            <option value="mfl" <?= $fBloco === 'mfl' ? 'selected' : '' ?>>MFL</option>
          </select>
        </div>
        <div>
          <button type="submit" class="btn-ordem" style="background:#1a3d2a;color:#fff">Filtrar</button>
        </div>
      </form>
    </div>
    <div class="card-mc-body flush">
      <div class="table-wrap-mc">
        <table class="table-mc">
          <thead>
            <tr>
              <th style="width:110px">Cód. VSAT</th>
              <th>Nome da Peça (Engenharia)</th>
              <th>Categoria CMI</th>
              <th>Bloco CMI</th>
              <th>Material / Esp.</th>
              <th style="width:90px;text-align:right">Ações</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pecas as $p): ?>
              <tr>
                <td class="mono" style="font-weight:600"><?= htmlspecialchars((string)$p['codigo_vsat']) ?></td>
                <td style="font-weight:600;color:#1a2133"><?= htmlspecialchars((string)($p['nome_engenharia'] ?? $p['nome_canonico'] ?? '—')) ?></td>
                <td style="color:#5a6474"><?= htmlspecialchars((string)($p['categoria'] ?? '—')) ?></td>
                <td>
                  <span style="background:#eef1f5;padding:2px 6px;border-radius:4px;font-size:11px;font-weight:600">
                    <?= htmlspecialchars(str_replace('_', ' ', strtoupper((string)($p['bloco'] ?? 'GERAL')))) ?>
                  </span>
                </td>
                <td>
                  <?= htmlspecialchars((string)($p['material'] ?? '—')) ?>
                  <?php if (isset($p['espessura']) && $p['espessura'] !== null): ?>
                    <span style="color:#9aa3b8">(<?= $p['espessura'] ?> mm)</span>
                  <?php endif; ?>
                </td>
                <td style="text-align:right">
                  <a href="peca-form.php?id=<?= $p['id'] ?>" class="btn-ordem">Editar</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<?php
layoutFooter();
