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

$tpds = $dadosPcp['tpds'] ?? [];
$pecas = $dadosPcp['pecas'] ?? [];
$mats = $dadosPcp['mats'] ?? [];
$cats = $dadosPcp['cats'] ?? [];
$rows = $dadosPcp['rows'] ?? [];
$tpdTrafos = $dadosPcp['tpd_trafos'] ?? [];
$tpdStatusEng = $dadosPcp['tpd_status_eng'] ?? [];

// Modo avulso: F-29 emitido a partir do filtro ativo na Mesa de Corte (pode
// juntar peças de vários TPDs), em vez de um projeto único via ?tpd=.
$modoAvulso = false;
$resumoFiltro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['linhas'])) {
    $linhasPost = json_decode((string)$_POST['linhas'], true);
    if (is_array($linhasPost) && count($linhasPost) > 0) {
        $modoAvulso = true;
        $resumoFiltro = trim((string)($_POST['resumo_filtro'] ?? ''));
    }
}

if ($modoAvulso) {
    $tpdParam = 'Plano de Corte Avulso';
    $rowsProj = [];
    foreach ($linhasPost as $item) {
        $tpdNome = trim((string)($item['tpd'] ?? '—'));
        $tpdIdxItem = array_search($tpdNome, $tpds, true);
        if ($tpdIdxItem === false) { $tpds[] = $tpdNome; $tpdIdxItem = count($tpds) - 1; }

        $pecaNome = trim((string)($item['peca'] ?? 'Peça de Papel'));
        $pecaIdxItem = array_search($pecaNome, $pecas, true);
        if ($pecaIdxItem === false) { $pecas[] = $pecaNome; $pecaIdxItem = count($pecas) - 1; }

        $matNome = trim((string)($item['material'] ?? 'MATERIAL'));
        $matIdxItem = array_search($matNome, $mats, true);
        if ($matIdxItem === false) { $mats[] = $matNome; $matIdxItem = count($mats) - 1; }

        $trafosItem = (int)($item['trafos'] ?? 0);

        $rowsProj[] = [
            (int)($item['semana'] ?? 0),   // 0: semana
            '',                             // 1: data (não usada no modo avulso)
            null,                           // 2: catIdx (classificação usa só o nome da peça)
            $pecaIdxItem,                   // 3: pecaIdx
            $matIdxItem,                    // 4: matIdx
            (float)($item['espessura'] ?? 0), // 5: esp
            (float)($item['largura'] ?? 0),   // 6: larg
            (float)($item['comprimento'] ?? 0), // 7: comp
            $tpdIdxItem,                    // 8: tpdIdx
            (string)($item['of'] ?? ''),    // 9: OF filha
            (int)($item['qtd'] ?? 0),       // 10: qtd total
            (float)($item['massa'] ?? 0),   // 11: massa total
            $trafosItem > 0 ? $trafosItem : null, // 12: trafos do lote de origem
        ];
    }

    $semanaPcp = null;
    $dataPcp = date('Y-m-d');
    $trafosQtd = 1;
    $statusEngenharia = 'LIBERADO';
    $tpdsEnvolvidos = count(array_unique(array_column($rowsProj, 8)));
} else {
    $tpdParam = trim((string)($_GET['tpd'] ?? ''));
    if ($tpdParam === '' && !empty($tpds)) {
        $tpdParam = $tpds[0];
    }

    // Buscar índice do TPD com tolerância a prefixo
    $tpdIdx = array_search($tpdParam, $tpds, true);
    if ($tpdIdx === false) {
        $alt = str_starts_with($tpdParam, 'TPD-') ? substr($tpdParam, 4) : 'TPD-' . $tpdParam;
        $altIdx = array_search($alt, $tpds, true);
        if ($altIdx !== false) {
            $tpdIdx = $altIdx;
            $tpdParam = $tpds[$altIdx];
        }
    }
    if ($tpdIdx === false && !empty($tpds)) {
        $tpdIdx = 0;
        $tpdParam = $tpds[0];
    }

    // Filtrar todas as peças do TPD
    $rowsProj = array_filter($rows, fn($r) => ($r[8] ?? null) === $tpdIdx);

    // Determinar dados de cabeçalho
    $primeiroItem = reset($rowsProj) ?: null;
    $semanaPcp = $primeiroItem ? (int)$primeiroItem[0] : 32;
    $dataPcp = $primeiroItem ? (string)$primeiroItem[1] : date('Y-m-d');
    $trafosQtd = (int)($primeiroItem[12] ?? ($tpdTrafos[(string)$tpdIdx] ?? 5));
    if ($trafosQtd <= 0) $trafosQtd = 5;

    $statusEngenharia = (string)($primeiroItem[16] ?? ($tpdStatusEng[(string)$tpdIdx] ?? 'LIBERADO'));
}

$totalOfs = count(array_unique(array_column($rowsProj, 9)));
$totalPecasLote = array_sum(array_column($rowsProj, 10));
$totalMassaEst = array_sum(array_filter(array_column($rowsProj, 11)));

// Funções Auxiliares pt-BR
function formatarMassaPhp(float $kg): string {
    if ($kg <= 0) return '—';
    if ($kg >= 1000.0) {
        $t = $kg / 1000.0;
        return number_format($t, 2, ',', '.') . ' t';
    }
    return number_format($kg, 2, ',', '.') . ' kg';
}

function formatarMassaUnitPhp(float $pesoKg): string {
    if ($pesoKg <= 0) return '—';
    if ($pesoKg < 0.001) {
        return number_format($pesoKg * 1000.0, 2, ',', '.') . ' g';
    }
    return number_format($pesoKg, 3, ',', '.') . ' kg';
}

function calcMassaUnitPhp(float $esp, float $larg, float $comp, string $mat): float {
    $dens = ($mat === 'PRESSPHAN') ? 1.2 : (($mat === 'DIAMANT') ? 0.95 : 0.85);
    $volCm3 = ($esp / 10.0) * ($larg / 10.0) * ($comp / 10.0);
    return ($volCm3 * $dens) / 1000.0;
}

function formatarEspessuraPhp(float|string $esp): string {
    $val = (float)$esp;
    return number_format($val, ($val == (int)$val ? 0 : 2), ',', '.') . ' mm';
}

// Classificador CMI
function classificarCmiOrdem(string $pecaNome, string $catNome): string {
    $txt = strtoupper($pecaNome . ' ' . $catNome);
    if (str_contains($txt, 'CANUDO') || str_contains($txt, 'ENTRE CAMADAS') || str_contains($txt, 'REFORÇO') || str_contains($txt, 'REFORCO') || (str_contains($txt, 'AT') && !str_contains($txt, 'NÚCLEO') && !str_contains($txt, 'NUCLEO'))) {
        return 'AT';
    } elseif (str_contains($txt, 'CILINDRO') || str_contains($txt, 'FORMA') || str_contains($txt, 'FAIXA') || (str_contains($txt, 'BT') && !str_contains($txt, 'NÚCLEO') && !str_contains($txt, 'NUCLEO'))) {
        return 'BT';
    } else {
        return 'PARTE_ATIVA';
    }
}

$grupoAT = [];
$grupoBT = [];
$grupoPA = [];

foreach ($rowsProj as $r) {
    $nomePeca = $pecas[$r[3]] ?? 'Peça de Papel';
    $nomeCat = $cats[$r[2]] ?? 'Geral';
    $cmi = classificarCmiOrdem($nomePeca, $nomeCat);
    
    if ($cmi === 'AT') {
        $grupoAT[] = $r;
    } elseif ($cmi === 'BT') {
        $grupoBT[] = $r;
    } else {
        $grupoPA[] = $r;
    }
}

// Cruzamento com aproveitamento de almoxarifado é por TPD único - não se aplica
// ao modo avulso (que mistura peças de vários projetos).
$aproveitamentos = [];
if (!$modoAvulso) {
    try {
        $pdo = getDB();
        $stmtAprov = $pdo->prepare("
            SELECT a.peca_idx, a.of_filha, a.status, a.nome_usuario_decisao, e.rua, e.prateleira, e.caixa, e.tamanho, e.estoque_real_kg
            FROM papel_aproveitamento_analise a
            JOIN papel_estoque_inventario e ON e.id = a.id_estoque_inventario
            WHERE a.tpd_projeto = ? AND a.status = 'aprovado'
        ");
        $stmtAprov->execute([$tpdParam]);
        foreach ($stmtAprov->fetchAll(PDO::FETCH_ASSOC) as $ap) {
            $aproveitamentos[(int)$ap['peca_idx']] = $ap;
        }
    } catch (Throwable $e) {
        // Continua caso o banco esteja indisponível
    }
}

$pageTitle = $modoAvulso ? 'Folha de Ordem de Corte (F-29) — Plano de Corte Avulso' : "Folha de Ordem de Corte (F-29) — {$tpdParam}";
layoutHeader($pageTitle);
?>

<style>
.wrap-f29 { max-width: 1320px; margin: 0 auto; padding: 10px 0 40px; }
.card-f29 { background: #fff; border: 1px solid #e2e6ed; border-radius: 8px; box-shadow: 0 1px 3px rgba(16,24,40,.06); margin-bottom: 18px; }
.card-f29-head { padding: 12px 16px; border-bottom: 1px solid #eef1f5; display: flex; align-items: center; justify-content: space-between; }
.card-f29-head h2 { margin: 0; font-size: 15px; font-weight: 700; color: #1a2133; display: flex; align-items: center; gap: 8px; }
.card-f29-body { padding: 16px; }
.card-f29-body.flush { padding: 0; }
.table-wrap-f29 { width: 100%; }
.table-f29 { width: 100%; table-layout: fixed; border-collapse: collapse; margin: 0; }
.table-f29 th { font-size: 10.5px; text-transform: uppercase; letter-spacing: .03em; color: #64748b; font-weight: 700; text-align: left; padding: 8px 6px; border-bottom: 1px solid #e2e6ed; background: #f8fafc; white-space: normal; word-break: break-word; }
.table-f29 td { padding: 8px 6px; border-bottom: 1px solid #eef1f5; font-size: 12px; white-space: normal; word-break: break-word; overflow-wrap: anywhere; }
.table-f29 tbody tr:hover { background: #fdf6e8; }
.mono { font-family: 'JetBrains Mono', monospace; font-variant-numeric: tabular-nums; }
.btn-f29 { display: inline-block; padding: 7px 16px; font-size: 13px; font-weight: 600; color: #1a3d2a; border: 1px solid #1a3d2a; border-radius: 6px; text-decoration: none; background: #fff; cursor: pointer; transition: all .15s ease; }
.btn-f29:hover { background: #1a3d2a; color: #fff; text-decoration: none; }
.btn-f29-primary { background: #1a3d2a; color: #fff; border: 1px solid #1a3d2a; }
.btn-f29-primary:hover { background: #0e2c1d; }
.grid-head-f29 { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; }
.lbl-info { font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 700; margin-bottom: 4px; }
.val-info { font-size: 14px; font-weight: 600; color: #1a2133; }

/* Estilos de Impressão (Oficial F-29) */
@media print {
  body { background: #fff !important; font-size: 10pt; }
  .no-print, header, aside, footer, nav, .btn-f29, .sidebar, .app-header { display: none !important; }
  .wrap-f29 { max-width: 100% !important; margin: 0 !important; padding: 0 !important; }
  .card-f29 { border: 1px solid #000 !important; box-shadow: none !important; margin-bottom: 14px !important; page-break-inside: avoid; }
  .table-f29 th { background: #eee !important; color: #000 !important; border-bottom: 1px solid #000 !important; padding: 6px 8px !important; }
  .table-f29 td { border-bottom: 1px solid #ddd !important; padding: 6px 8px !important; }
  .print-only { display: block !important; }
}
.print-only { display: none; }
</style>

<div class="wrap-f29">

  <!-- Ações e Barra Superior -->
  <div class="no-print" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px">
    <div>
      <h1 style="font-size:22px;font-weight:700;margin:0 0 4px;color:#1a2133">Folha do Projeto — Ordem de Corte (F-29)</h1>
      <div style="font-size:12px;color:#5a6474">
        Empresa 01 (Matriz — TRAEL TRANSFORMADORES) · Programação oficial de papéis isolantes por projeto do PCP
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
      <?php if (!$modoAvulso): ?>
      <!-- Seletor de Projetos -->
      <form method="GET" style="display:inline-block;margin:0">
        <select name="tpd" onchange="this.form.submit()" style="padding:7px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;font-weight:600;background:#fff">
          <?php foreach (array_slice($tpds, 0, 150) as $t): ?>
            <option value="<?= htmlspecialchars($t) ?>" <?= $t === $tpdParam ? 'selected' : '' ?>>
              <?= htmlspecialchars($t) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php endif; ?>
      <button class="btn-f29 btn-f29-primary" onclick="window.print()">🖨️ Imprimir Folha F-29</button>
      <a href="mesa-de-corte.php" class="btn-f29">Voltar à Mesa</a>
    </div>
  </div>

  <!-- Cabeçalho Oficial da Folha F-29 -->
  <div class="card-f29">
    <div class="card-f29-head" style="background:#f8fafc;border-left:4px solid #1a3d2a">
      <h2>
        <span>EMPRESA 01 — TRAEL TRANSFORMADORES ELÉTRICOS</span>
        <span style="font-size:12px;font-weight:normal;color:#64748b;margin-left:6px">· SETOR DE PAPEL (FOLHA F-29)</span>
      </h2>
      <span style="background:#dcfce7;color:#15803d;padding:3px 10px;border-radius:4px;font-size:11px;font-weight:700">
        EMISSÃO: <?= date('d/m/Y') ?>
      </span>
    </div>
    <div class="card-f29-body">
      <div class="grid-head-f29">
        <?php if ($modoAvulso): ?>
        <div style="grid-column:1 / -1">
          <div class="lbl-info">Plano de Corte Avulso — Filtro Aplicado</div>
          <div class="val-info" style="font-size:15px;color:#1a3d2a"><?= htmlspecialchars($resumoFiltro !== '' ? $resumoFiltro : 'Sem descrição de filtro') ?></div>
        </div>
        <div>
          <div class="lbl-info">Status Engenharia</div>
          <div class="val-info">
            <span style="background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:700">🟢 Só peças liberadas</span>
          </div>
        </div>
        <div>
          <div class="lbl-info">Projetos Incluídos</div>
          <div class="val-info" style="color:#15803d;font-size:16px">📦 <?= number_format($tpdsEnvolvidos, 0, ',', '.') ?> TPDs</div>
        </div>
        <div>
          <div class="lbl-info">Emitido em</div>
          <div class="val-info">Data: <?= date('d/m/Y H:i') ?></div>
        </div>
        <div>
          <div class="lbl-info">Total de OFs / Peças</div>
          <div class="val-info"><?= number_format($totalOfs, 0, ',', '.') ?> OFs · <?= number_format($totalPecasLote, 0, ',', '.') ?> pçs</div>
        </div>
        <div>
          <div class="lbl-info">Massa Estimada Total</div>
          <div class="val-info mono"><?= formatarMassaPhp((float)$totalMassaEst) ?></div>
        </div>
        <?php else: ?>
        <div>
          <div class="lbl-info">Projeto (Engenharia)</div>
          <div class="val-info mono" style="font-size:16px;color:#1a3d2a"><?= htmlspecialchars($tpdParam) ?></div>
        </div>
        <div>
          <div class="lbl-info">Status Engenharia</div>
          <div class="val-info">
            <?php if ($statusEngenharia === 'LIBERADO'): ?>
              <span style="background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:700">🟢 LIBERADO</span>
            <?php elseif ($statusEngenharia === 'BLOQUEADO'): ?>
              <span style="background:#fee2e2;color:#b91c1c;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:700">🔴 BLOQUEADO</span>
            <?php else: ?>
              <span style="background:#fef3c7;color:#78350f;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:700">🟡 AGUARDANDO LIBERAÇÃO</span>
            <?php endif; ?>
          </div>
        </div>
        <div>
          <div class="lbl-info">Lote de Transformadores</div>
          <div class="val-info" style="color:#15803d;font-size:16px">⚡ <?= number_format($trafosQtd, 0, ',', '.') ?> unidades</div>
        </div>
        <div>
          <div class="lbl-info">Semana do PCP</div>
          <div class="val-info">Semana <?= $semanaPcp ?> (<?= date('d/m/Y', strtotime($dataPcp)) ?>)</div>
        </div>
        <div>
          <div class="lbl-info">Total de OFs / Peças</div>
          <div class="val-info"><?= number_format($totalOfs, 0, ',', '.') ?> OFs · <?= number_format($totalPecasLote, 0, ',', '.') ?> pçs</div>
        </div>
        <div>
          <div class="lbl-info">Massa Estimada Total</div>
          <div class="val-info mono"><?= formatarMassaPhp((float)$totalMassaEst) ?></div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php
  // Função auxiliar para renderizar tabela de cada seção
  function renderTabelaSecaoF29(string $titulo, string $icone, string $corBorda, array $itens, int $trafosQtd, array $pecas, array $mats, array $aproveitamentos, bool $modoAvulso = false, array $tpds = []): void {
      if (empty($itens)) return;
      ?>
      <div class="card-f29">
        <div class="card-f29-head" style="border-left:4px solid <?= $corBorda ?>">
          <h2>
            <span><?= $icone ?></span>
            <span><?= htmlspecialchars($titulo) ?></span>
            <span style="font-size:12px;font-weight:600;background:#f1f5f9;color:#475569;padding:2px 8px;border-radius:4px;margin-left:6px">
              <?= count($itens) ?> itens de isolação
            </span>
          </h2>
        </div>
        <div class="card-f29-body flush">
          <div class="table-wrap-f29">
            <table class="table-f29">
              <thead>
                <tr>
                  <?php if ($modoAvulso): ?>
                  <th style="width:11%">Projeto (TPD)</th>
                  <?php endif; ?>
                  <th style="width:7%">OF Filha</th>
                  <th style="width:16%">Peça (Desenho Engenharia)</th>
                  <th style="width:9%">Material</th>
                  <th style="width:6%">Esp.</th>
                  <th style="width:12%">Medida de Corte (L × C)</th>
                  <th style="width:8%;text-align:right">Qtd / Trafo</th>
                  <th style="width:12%;text-align:right">Total a Cortar<?= $modoAvulso ? '' : (' (× ' . number_format($trafosQtd, 0, ',', '.') . ' Trafo)') ?></th>
                  <th style="width:9%;text-align:right;background:#f1f5f9;color:#1e293b">Massa Unit.</th>
                  <th style="width:9%;text-align:right;background:#f1f5f9;color:#1e293b">Massa Total</th>
                  <th style="width:12%;text-align:center">Instrução / Máquina / Local</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($itens as $r):
                  $pecaIdx = (int)$r[3];
                  $ofNum = $r[9];
                  $nomePeca = $pecas[$pecaIdx] ?? 'Peça de Papel';
                  $nomeMat = $mats[$r[4]] ?? 'MATERIAL';
                  $esp = (float)$r[5];
                  $larg = (float)$r[6];
                  $comp = (float)$r[7];
                  $dim = "{$r[6]} × {$r[7]} mm";
                  $qtdLote = (int)$r[10];
                  $trafosItem = (int)($r[12] ?? $trafosQtd);
                  if ($trafosItem <= 0) $trafosItem = ($trafosQtd > 0 ? $trafosQtd : 1);
                  $qtdUnitaria = (int)ceil($qtdLote / $trafosItem);
                  if ($qtdUnitaria <= 0) $qtdUnitaria = 1;

                  $pesoUnit = calcMassaUnitPhp($esp, $larg, $comp, $nomeMat);
                  $pesoTotalItem = (float)($r[11] ?? ($pesoUnit * $qtdLote));

                  $aprov = $aproveitamentos[$pecaIdx] ?? null;
                  $estaNoEstoque = ($aprov !== null);
                ?>
                <tr <?= $estaNoEstoque ? 'style="background:#f0fdf4"' : '' ?>>
                  <?php if ($modoAvulso): ?>
                  <td class="mono" style="font-weight:600;color:#1a3d2a"><?= htmlspecialchars($tpds[(int)$r[8]] ?? '—') ?></td>
                  <?php endif; ?>
                  <td class="mono" style="font-weight:600;color:#1e293b"><?= htmlspecialchars((string)$ofNum) ?></td>
                  <td style="font-weight:600;color:#1a2133">
                    <?= htmlspecialchars($nomePeca) ?>
                    <?php if ($estaNoEstoque): ?>
                      <span style="display:block;font-size:11px;color:#15803d;font-weight:700">📦 APROVEITADO DO ALMOXARIFADO</span>
                    <?php endif; ?>
                  </td>
                  <td><span style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:11px;font-weight:600"><?= htmlspecialchars($nomeMat) ?></span></td>
                  <td class="mono"><?= formatarEspessuraPhp($esp) ?></td>
                  <td class="mono" style="font-weight:600;color:#1a3d2a"><?= $dim ?></td>
                  <td class="mono" style="text-align:right;color:#64748b"><?= number_format($qtdUnitaria, 0, ',', '.') ?> pç</td>
                  <td class="mono" style="text-align:right;font-weight:700;color:#1a3d2a;font-size:14px"><?= number_format($qtdLote, 0, ',', '.') ?> pçs</td>
                  <td class="mono" style="text-align:right;color:#475569;font-size:12px"><?= formatarMassaUnitPhp($pesoUnit) ?></td>
                  <td class="mono" style="text-align:right;font-weight:700;color:#1a3d2a;font-size:13px"><?= formatarMassaPhp($pesoTotalItem) ?></td>
                  <td style="text-align:center">
                    <?php if ($estaNoEstoque): ?>
                      <span style="background:#dcfce7;color:#15803d;border:1px solid #86efac;padding:3px 8px;border-radius:4px;font-size:11.5px;font-weight:700">
                        📦 RETIRAR: Rua <?= htmlspecialchars($aprov['rua']) ?><?= !empty($aprov['prateleira']) ? ' / Prat. ' . htmlspecialchars($aprov['prateleira']) : '' ?>
                      </span>
                    <?php else: ?>
                      <span style="background:#eef1f5;padding:2px 8px;border-radius:4px;font-size:11px">
                        <?= ($esp >= 2.0) ? 'Guilhotina / Fresa' : 'Slitter / Bobina' ?>
                      </span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <?php
  }

  // 1. Alta Tensão
  renderTabelaSecaoF29('1. CONJUNTO ALTA TENSÃO (AT)', '⚡', '#2563eb', $grupoAT, $trafosQtd, $pecas, $mats, $aproveitamentos, $modoAvulso, $tpds);

  // 2. Baixa Tensão
  renderTabelaSecaoF29('2. CONJUNTO BAIXA TENSÃO (BT)', '⚡', '#16a34a', $grupoBT, $trafosQtd, $pecas, $mats, $aproveitamentos, $modoAvulso, $tpds);

  // 3. Montagem Parte Ativa & Núcleo
  renderTabelaSecaoF29('3. MONTAGEM DA PARTE ATIVA (ELÉTRICA + NÚCLEO)', '⚙️', '#d97706', $grupoPA, $trafosQtd, $pecas, $mats, $aproveitamentos, $modoAvulso, $tpds);
  ?>

  <!-- Bloco de Assinaturas e Controle de Chão (visível na impressão) -->
  <div class="card-f29" style="margin-top:24px">
    <div class="card-f29-body" style="padding:18px">
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;text-align:center;font-size:12px;color:#475569">
        <div>
          <div style="border-bottom:1px solid #334155;height:40px;margin-bottom:6px"></div>
          <b>Operador de Corte</b><br>
          Data: ___/___/______
        </div>
        <div>
          <div style="border-bottom:1px solid #334155;height:40px;margin-bottom:6px"></div>
          <b>Supervisor / PCP</b><br>
          Data: ___/___/______
        </div>
        <div>
          <div style="border-bottom:1px solid #334155;height:40px;margin-bottom:6px"></div>
          <b>Controle de Qualidade (IQF)</b><br>
          Data: ___/___/______
        </div>
      </div>
    </div>
  </div>

  <!-- Rodapé Oficial de Rastreabilidade ERP -->
  <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:#64748b;padding:8px 0;border-top:1px solid #cbd5e1;margin-top:12px;display:flex;justify-content:space-between">
    <div>Local: /Tabelas Básicas Especialistas/Indústria e Comércio de Transformadores/Relatórios/Programação TRAEL - Semana Ano - DzOiD: 10101451 Revisão: 100 Consulta: 10101256</div>
    <div>ERP VSAT · TRAEL TRANSFORMADORES</div>
  </div>

</div>

<?php
layoutFooter();
