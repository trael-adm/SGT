<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/layout.php';

requireLogin();
requireAcessoPapel();

// Carregar Dataset Completo
$jsonPcpFile = __DIR__ . '/dados_pcp_completo.json';
$rawJson = file_exists($jsonPcpFile) ? file_get_contents($jsonPcpFile) : '';
$rawJson = preg_replace('/^\xEF\xBB\xBF/', '', (string)$rawJson);
$dadosPcp = !empty($rawJson) ? json_decode($rawJson, true) : null;
$rows = $dadosPcp['rows'] ?? [];
$mats = $dadosPcp['mats'] ?? [];
$pecas = $dadosPcp['pecas'] ?? [];
$tpds = $dadosPcp['tpds'] ?? [];

$maquinaId = (int)($_GET['maquina_id'] ?? 17001);

$maquinasMap = [
    17001 => ['nome' => 'Sliter de Bobina 17001', 'tipo' => 'Slitter', 'esp_max' => 1.0, 'operador' => 'Valter / Operador 01'],
    17002 => ['nome' => 'Guilhotina de Pedal 17002', 'tipo' => 'Guilhotina', 'esp_max' => 3.0, 'operador' => 'Carlos / Operador 02'],
    17003 => ['nome' => 'Dobradeira de Papel 17003', 'tipo' => 'Dobradeira', 'esp_max' => 0.5, 'operador' => 'Lucas / Operador 03'],
    17012 => ['nome' => 'Guilhotina Elétrica 17012', 'tipo' => 'Guilhotina', 'esp_max' => 5.0, 'operador' => 'Marcos / Operador 04'],
];

$maqAtual = $maquinasMap[$maquinaId] ?? $maquinasMap[17001];

// Funções Auxiliares pt-BR
function formatarMassaPhp(float $kg): string {
    if ($kg <= 0) return '—';
    if ($kg >= 1000.0) {
        $t = $kg / 1000.0;
        return number_format($t, 2, ',', '.') . ' t';
    }
    return number_format($kg, 1, ',', '.') . ' kg';
}

function formatarEspessuraPhp(float|string $esp): string {
    $val = (float)$esp;
    return number_format($val, ($val == (int)$val ? 0 : 2), ',', '.') . ' mm';
}

// Agrupar filas para a máquina
$mapLotes = [];
foreach ($rows as $r) {
    $esp = (float)$r[5];
    $mat = $mats[$r[4]] ?? 'MATERIAL';
    
    // Roteamento por tipo de máquina
    if ($maqAtual['tipo'] === 'Slitter' && $esp > 1.0) continue;
    if ($maqAtual['tipo'] === 'Guilhotina' && $esp < 1.0) continue;

    $key = "{$r[0]}-{$mat}-{$esp}";
    if (!isset($mapLotes[$key])) {
        $mapLotes[$key] = [
            'semana' => $r[0],
            'mat' => $mat,
            'esp' => $esp,
            'ofs' => [],
            'tpds' => [],
            'pecas' => 0,
            'massa' => 0.0,
            'status' => ($r[13] ?? 0)
        ];
    }
    $mapLotes[$key]['ofs'][] = $r[9];
    $mapLotes[$key]['tpds'][] = $r[8];
    $mapLotes[$key]['pecas'] += (int)$r[10];
    $mapLotes[$key]['massa'] += (float)($r[11] ?? 0);
}

$lotesLista = array_values($mapLotes);
usort($lotesLista, fn($a, $b) => $b['pecas'] <=> $a['pecas']);

$loteExecucao = $lotesLista[0] ?? null;
$lotesFila = array_slice($lotesLista, 1, 8);

$pageTitle = "Fila de Máquina — {$maqAtual['nome']}";
layoutHeader($pageTitle);
?>

<style>
.wrap-maq { max-width: 1200px; margin: 0 auto; padding: 10px 0 40px; }
.card-maq { background: #fff; border: 1px solid #e2e6ed; border-radius: 8px; box-shadow: 0 1px 3px rgba(16,24,40,.06); margin-bottom: 18px; }
.card-maq-head { padding: 12px 16px; border-bottom: 1px solid #eef1f5; display: flex; align-items: center; justify-content: space-between; }
.card-maq-head h2 { margin: 0; font-size: 15px; font-weight: 700; color: #1a2133; }
.card-maq-body { padding: 16px; }
.card-maq-body.flush { padding: 0; }
.table-wrap-maq { overflow-x: auto; width: 100%; }
.table-maq { width: 100%; border-collapse: collapse; margin: 0; }
.table-maq th { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #64748b; font-weight: 700; text-align: left; padding: 10px 12px; border-bottom: 1px solid #e2e6ed; background: #f8fafc; white-space: nowrap; }
.table-maq td { padding: 10px 12px; border-bottom: 1px solid #eef1f5; font-size: 13px; white-space: nowrap; }
.table-maq tbody tr:hover { background: #fdf6e8; }
.mono { font-family: 'JetBrains Mono', monospace; font-variant-numeric: tabular-nums; }
.select-maq { padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; font-weight: 600; background: #fff; }
.btn-maq { display: inline-block; padding: 8px 16px; font-size: 13px; font-weight: 600; color: #fff; background: #1a3d2a; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; }
.btn-maq:hover { background: #0e2c1d; }
.btn-maq-baixa { background: #15803d; font-size: 14px; font-weight: 700; }
.btn-maq-baixa:hover { background: #166534; }
</style>

<div class="wrap-maq">

  <!-- Topo: Seleção de Posto de Trabalho -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px">
    <div>
      <h1 style="font-size:22px;font-weight:700;margin:0 0 4px;color:#1a2133">Fila de Trabalho por Máquina</h1>
      <div style="font-size:12px;color:#5a6474">
        Empresa 01 (Matriz Trael) · Sequenciamento de lotes de corte por posto de trabalho
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:10px">
      <label style="font-size:13px;font-weight:600;color:#334155">Posto / Máquina:</label>
      <select class="select-maq" onchange="location.href='?maquina_id='+this.value">
        <?php foreach ($maquinasMap as $id => $m): ?>
          <option value="<?= $id ?>" <?= $maquinaId === $id ? 'selected' : '' ?>>
            <?= htmlspecialchars($m['nome']) ?> (<?= $m['tipo'] ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <!-- Cartão do Lote Ativo em Execução -->
  <?php if ($loteExecucao): ?>
  <div class="card-maq" style="border-left:5px solid #15803d">
    <div class="card-maq-body" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px">
      <div>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
          <span style="background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700">⚡ EM EXECUÇÃO AGORA</span>
          <span class="mono" style="background:#1a3d2a;color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700">
            LOTE #<?= $loteExecucao['semana'] ?>-01
          </span>
          <span style="font-size:12px;color:#64748b">Operador: <b><?= htmlspecialchars($maqAtual['operador']) ?></b></span>
        </div>
        <h2 style="font-size:20px;font-weight:700;color:#1a2133;margin:4px 0">
          Bobina <?= htmlspecialchars($loteExecucao['mat']) ?> <?= formatarEspessuraPhp($loteExecucao['esp']) ?>
        </h2>
        <div style="font-size:13px;color:#475569">
          <b><?= number_format(count(array_unique($loteExecucao['ofs'])), 0, ',', '.') ?> OFs</b> vinculadas · <?= number_format(count(array_unique($loteExecucao['tpds'])), 0, ',', '.') ?> Projetos TPD · Semana <?= $loteExecucao['semana'] ?>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:20px">
        <div style="text-align:right">
          <div class="mono" style="font-size:32px;font-weight:700;color:#15803d">
            <?= number_format($loteExecucao['pecas'], 0, ',', '.') ?>
          </div>
          <div style="font-size:11px;color:#64748b">peças a cortar neste lote</div>
        </div>
        <div>
          <button class="btn-maq btn-maq-baixa" onclick="abrirModalBaixa('LOTE #<?= $loteExecucao['semana'] ?>-01', '<?= $loteExecucao['mat'] ?> <?= formatarEspessuraPhp($loteExecucao['esp']) ?>', <?= $loteExecucao['pecas'] ?>)">
            ✓ Dar Baixa no Lote
          </button>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Próximos Lotes da Fila -->
  <div class="card-maq">
    <div class="card-maq-head">
      <h2>📋 Sequência de Próximos Lotes na Fila</h2>
      <span style="font-size:12px;color:#64748b"><?= number_format(count($lotesFila), 0, ',', '.') ?> lotes aguardando set-up</span>
    </div>
    <div class="card-maq-body flush">
      <div class="table-wrap-maq">
        <table class="table-maq">
          <thead>
            <tr>
              <th>Ordem na Fila</th>
              <th>Identificação do Lote</th>
              <th>Material da Bobina</th>
              <th>Espessura</th>
              <th style="text-align:right">Total OFs</th>
              <th style="text-align:right">Total Peças</th>
              <th>Massa Est.</th>
              <th style="text-align:center">Ação do Operador</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($lotesFila as $idx => $lt): 
              $pos = $idx + 2;
              $loteId = "LOTE #{$lt['semana']}-" . str_pad((string)$pos, 2, '0', STR_PAD_LEFT);
            ?>
            <tr>
              <td class="mono" style="font-weight:700;color:#64748b"><?= $pos ?>º na fila</td>
              <td class="mono" style="font-weight:700;color:#1a3d2a"><?= $loteId ?></td>
              <td><b><?= htmlspecialchars($lt['mat']) ?></b></td>
              <td class="mono"><?= formatarEspessuraPhp($lt['esp']) ?></td>
              <td class="mono" style="text-align:right"><?= number_format(count(array_unique($lt['ofs'])), 0, ',', '.') ?> OFs</td>
              <td class="mono" style="text-align:right;font-weight:700;color:#1a2133"><?= number_format($lt['pecas'], 0, ',', '.') ?> pçs</td>
              <td class="mono"><?= formatarMassaPhp((float)$lt['massa']) ?></td>
              <td style="text-align:center">
                <button class="btn-maq" style="padding:4px 10px;font-size:11px" onclick="puxarParaExecucao('<?= $loteId ?>')">
                  Puxar p/ Execução
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<!-- Modal de Apontamento de Baixa -->
<div class="modal-overlay" id="modal-baixa" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(15,23,42,.6);backdrop-filter:blur(4px);display:none;align-items:center;justify-content:center;z-index:9999">
  <div style="background:#fff;border-radius:12px;width:90%;max-width:480px;box-shadow:0 20px 25px -5px rgba(0,0,0,.1);overflow:hidden">
    <div style="padding:14px 18px;border-bottom:1px solid #eef1f5;border-left:4px solid #15803d;display:flex;justify-content:space-between;align-items:center">
      <h3 style="margin:0;font-size:16px;font-weight:700;color:#1a2133">✓ Apontamento de Conclusão do Lote</h3>
      <button style="background:none;border:none;font-size:22px;cursor:pointer;color:#64748b" onclick="fecharModalBaixa()">&times;</button>
    </div>
    <div style="padding:18px">
      <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px;margin-bottom:14px;font-size:13px;color:#166534">
        <b id="baixa-lote-id">LOTE #31-01</b> · <span id="baixa-lote-desc">PRESSPHAN 2,00 mm</span>
      </div>

      <div style="margin-bottom:12px">
        <label style="display:block;font-size:12px;font-weight:600;color:#334155;margin-bottom:4px">Quantidade de Peças Produzidas / Boas</label>
        <input type="number" id="baixa-qtd-boas" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:14px;font-weight:700">
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
        <div>
          <label style="display:block;font-size:12px;font-weight:600;color:#334155;margin-bottom:4px">Perdas / Sucata (pçs)</label>
          <input type="number" id="baixa-qtd-perdas" value="0" min="0" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:600;color:#334155;margin-bottom:4px">Motivo da Perda (se houver)</label>
          <select id="baixa-motivo-perda" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
            <option value="Nenhuma">Nenhuma</option>
            <option value="Aparas de Início de Bobina">Aparas de Bobina</option>
            <option value="Erro de Medida / Ajuste de Faca">Ajuste de Faca</option>
            <option value="Amassamento / Vinco">Amassamento</option>
          </select>
        </div>
      </div>

      <div style="margin-bottom:12px">
        <label style="display:block;font-size:12px;font-weight:600;color:#334155;margin-bottom:4px">Operador Responsável</label>
        <input type="text" id="baixa-operador" value="<?= htmlspecialchars($maqAtual['operador']) ?>" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
      </div>
    </div>
    <div style="padding:12px 18px;background:#f8fafc;border-top:1px solid #eef1f5;display:flex;justify-content:flex-end;gap:10px">
      <button style="background:#fff;border:1px solid #cbd5e1;padding:8px 14px;border-radius:6px;cursor:pointer;font-size:13px" onclick="fecharModalBaixa()">Cancelar</button>
      <button style="background:#15803d;color:#fff;border:none;padding:8px 18px;border-radius:6px;cursor:pointer;font-weight:700;font-size:13px" onclick="confirmarBaixaLote()">✓ Confirmar Baixa no Chão</button>
    </div>
  </div>
</div>

<script>
function abrirModalBaixa(loteId, desc, qtd) {
  document.getElementById('baixa-lote-id').textContent = loteId;
  document.getElementById('baixa-lote-desc').textContent = desc;
  document.getElementById('baixa-qtd-boas').value = qtd;
  document.getElementById('modal-baixa').style.display = 'flex';
}

function fecharModalBaixa() {
  document.getElementById('modal-baixa').style.display = 'none';
}

function confirmarBaixaLote() {
  const loteId = document.getElementById('baixa-lote-id').textContent;
  const qtdBoas = Number(document.getElementById('baixa-qtd-boas').value).toLocaleString('pt-BR');
  const perdas = Number(document.getElementById('baixa-qtd-perdas').value).toLocaleString('pt-BR');
  const operador = document.getElementById('baixa-operador').value;

  fecharModalBaixa();
  alert(`✅ Lote ${loteId} apontado e finalizado com sucesso!\n\nPeças Boas: ${qtdBoas}\nPerdas Registradas: ${perdas}\nOperador: ${operador}\n\nStatus atualizado para CORTADO e enviado para a Montagem!`);
}

function puxarParaExecucao(loteId) {
  alert(`⚡ ${loteId} puxado para execução no posto de trabalho atual!`);
}
</script>

<?php
layoutFooter();
