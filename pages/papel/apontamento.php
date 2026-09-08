<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/layout.php';

requireLogin();
requireAcessoPapel();

$pageTitle = 'Apontamento do Chão — Setor de Papel';
layoutHeader($pageTitle);
?>

<style>
.wrap-mobile { max-width: 520px; margin: 0 auto; padding: 10px 0 40px; }
.card-ap { background: #fff; border: 1px solid #e2e6ed; border-radius: 8px; box-shadow: 0 1px 3px rgba(16,24,40,.06); margin-bottom: 16px; padding: 16px; }
.btn-baixa { background: #15803d; color: #fff; border: none; padding: 14px; border-radius: 8px; font-size: 15px; font-weight: 700; width: 100%; cursor: pointer; text-align: center; margin-bottom: 8px; }
.btn-baixa:hover { background: #166534; }
.btn-sub { background: #fff; color: #5a6474; border: 1px solid #e2e6ed; padding: 8px; border-radius: 6px; font-size: 13px; font-weight: 500; width: 100%; cursor: pointer; text-align: center; }
.btn-sub:hover { background: #f8f9fb; }
.badge-lote { background: #1a3d2a; color: #fff; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; font-family: 'JetBrains Mono', monospace; }
</style>

<div class="wrap-mobile">

  <div style="text-align:center;margin-bottom:20px;">
    <h1 style="font-size:20px;font-weight:700;margin:0 0 4px">Apontamento do Chão</h1>
    <div style="font-size:12px;color:#5a6474">Baixa simplificada por lote (Mobile / Tablet)</div>
  </div>

  <div class="card-ap" style="border-left:4px solid #1a3d2a">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px">
      <div>
        <span class="badge-lote">LOTE #31-01</span>
        <h3 style="font-size:16px;font-weight:700;margin:6px 0 0">PRESSPHAN 2,00 mm</h3>
      </div>
      <span style="background:#fef3c7;color:#78350f;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600">Em Corte</span>
    </div>
    <div style="font-size:12px;color:#64748b;margin-bottom:14px">24 OFs liberadas · Slitter 17001 · 459 peças total</div>
    
    <button class="btn-baixa" onclick="alert('Lote #31-01 baixado com sucesso!')">DAR BAIXA NO LOTE COMPLETO</button>
    <button class="btn-sub" onclick="alert('Opção de baixa parcial ativada')">Baixa Parcial / Pausa</button>
  </div>

  <div class="card-ap">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px">
      <div>
        <span class="badge-lote" style="background:#64748b">LOTE #31-02</span>
        <h3 style="font-size:16px;font-weight:700;margin:6px 0 0">KRAFT 0,18 mm</h3>
      </div>
      <span style="background:#f1f5f9;color:#64748b;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600">Aguardando</span>
    </div>
    <div style="font-size:12px;color:#64748b;margin-bottom:14px">18 OFs liberadas · Guilhotina Manual 17002 · 1.250 peças</div>
    
    <button class="btn-sub" style="border-color:#1a3d2a;color:#1a3d2a;font-weight:600" onclick="alert('Iniciando Lote #31-02...')">Iniciar Corte</button>
  </div>

</div>

<?php
layoutFooter();
