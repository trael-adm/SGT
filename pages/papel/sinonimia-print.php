<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();
requireAcessoPapel();

$pdo = getDB();
$pecas = [];

try {
    $stmt = $pdo->query('SELECT * FROM papel_pecas WHERE deleted_at IS NULL ORDER BY bloco ASC, nome_canonico ASC');
    $pecas = $stmt->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $pecas = [
        ['codigo_vsat' => '2087281', 'nome_canonico' => 'Isolação Viga-Núcleo', 'apelido_chao' => 'Isolação Viga/Núcleo', 'nome_f29' => 'PAPEL VIGA/NÚCLEO', 'nome_fx01' => 'ISOL VIGA NUCLEO', 'bloco' => 'nucleo', 'material' => 'PRESSPHAN', 'espessura' => 2.0],
        ['codigo_vsat' => '2087284', 'nome_canonico' => 'Cilindro BT', 'apelido_chao' => 'Forma', 'nome_f29' => 'FORMA 1x', 'nome_fx01' => 'CILINDRO BT', 'bloco' => 'enrolamento_bt', 'material' => 'DIAMANT', 'espessura' => 0.06],
        ['codigo_vsat' => '2088290', 'nome_canonico' => 'Papel Entre Camadas', 'apelido_chao' => 'Papel de Camada', 'nome_f29' => 'PAPEL ENTRE CAMADAS', 'nome_fx01' => 'PAPEL CAMADAS', 'bloco' => 'enrolamento_at', 'material' => 'KRAFT', 'espessura' => 0.18],
        ['codigo_vsat' => '2088277', 'nome_canonico' => 'Canudo Longo Saída AT', 'apelido_chao' => 'Canudão', 'nome_f29' => 'CANUDO LONGO - SAÍDA AT P/ BUCHA', 'nome_fx01' => 'CANUDO LONGO AT', 'bloco' => 'parte_ativa', 'material' => 'PRESSPHAN', 'espessura' => 1.0],
    ];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>Dicionário de Sinonímia — Setor de Papel | SGE Trael</title>
  <style>
    body { font-family: system-ui, -apple-system, sans-serif; font-size: 12px; color: #111; margin: 20px; }
    h1 { font-size: 18px; margin: 0 0 4px; }
    .sub { color: #666; margin-bottom: 16px; font-size: 11px; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th { background: #f0f2f5; text-transform: uppercase; font-size: 10px; padding: 6px 8px; border: 1px solid #ccc; text-align: left; }
    td { padding: 6px 8px; border: 1px solid #ddd; font-size: 11px; }
    .mono { font-family: monospace; font-size: 11px; }
    .btn-print { background: #1a3d2a; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; margin-bottom: 16px; }
    @media print {
      .btn-print { display: none; }
      body { margin: 0; }
    }
  </style>
</head>
<body>

  <button class="btn-print" onclick="window.print()">Imprimir Dicionário</button>
  
  <h1>Setor de Papel — Tabela de Sinonímia (Validação)</h1>
  <div class="sub">TRAEL Transformadores Elétricos · Emitido em <?= date('d/m/Y H:i') ?></div>

  <table>
    <thead>
      <tr>
        <th style="width: 80px;">Cód. VSAT</th>
        <th>Nome Canônico (SGE)</th>
        <th>Apelido no Chão</th>
        <th>Nome no F-29 (Desenho)</th>
        <th>Nome no FX-01</th>
        <th>Bloco CMI</th>
        <th>Mat. / Esp.</th>
        <th style="width: 150px;">Anotações / Validação</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($pecas as $p): ?>
        <tr>
          <td class="mono"><?= htmlspecialchars((string)$p['codigo_vsat']) ?></td>
          <td><strong><?= htmlspecialchars((string)$p['nome_canonico']) ?></strong></td>
          <td><?= htmlspecialchars((string)($p['apelido_chao'] ?? '—')) ?></td>
          <td class="mono"><?= htmlspecialchars((string)($p['nome_f29'] ?? '—')) ?></td>
          <td class="mono"><?= htmlspecialchars((string)($p['nome_fx01'] ?? '—')) ?></td>
          <td><?= htmlspecialchars(strtoupper((string)($p['bloco'] ?? '—'))) ?></td>
          <td><?= htmlspecialchars((string)($p['material'] ?? '')) ?> <?= isset($p['espessura']) ? $p['espessura'].'mm' : '' ?></td>
          <td></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

</body>
</html>
