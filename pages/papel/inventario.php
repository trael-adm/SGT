<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/layout.php';

requireLogin();
requireAcessoPapel();

$pageTitle = 'Inventário Almoxarifado de Papel — SGT';
layoutHeader($pageTitle);
?>

<style>
.wrap-inv { max-width: 100%; margin: 0 auto; padding: 8px 14px 40px; }
.mono { font-family: 'JetBrains Mono', monospace; font-variant-numeric: tabular-nums; }

.card-inv {
  background: var(--color-surface, #ffffff);
  border: 1px solid var(--color-border, #e2e6ed);
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(16,24,40,.06);
  margin-bottom: 18px;
}
.card-inv-head {
  padding: 12px 18px;
  border-bottom: 1px solid #eef1f5;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}
.card-inv-head h2 { margin: 0; font-size: 15px; font-weight: 700; color: var(--color-text-primary, #1a2133); }
.card-inv-body { padding: 16px 18px; }
.card-inv-body.flush { padding: 0; }

.kpis-inv { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 18px; }
.kpi-inv {
  background: var(--color-surface, #ffffff);
  border: 1px solid var(--color-border, #e2e6ed);
  border-left: 4px solid var(--color-accent, #e8a020);
  border-radius: 8px;
  padding: 14px 16px;
  box-shadow: 0 1px 3px rgba(16,24,40,.06);
}
.kpi-inv.green { border-left-color: #10b981; }
.kpi-inv.blue { border-left-color: #0284c7; }
.kpi-inv.purple { border-left-color: #8b5cf6; }

.kpi-inv .rot { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--color-text-muted, #9aa3b8); font-weight: 600; }
.kpi-inv .val { font-family: 'JetBrains Mono', monospace; font-variant-numeric: tabular-nums; font-size: 22px; font-weight: 700; margin-top: 4px; letter-spacing: -.02em; color: #1e293b; }
.kpi-inv .un { font-size: 12px; color: var(--color-text-muted, #9aa3b8); margin-left: 3px; font-family: inherit; font-weight: normal; }

.filtros-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; align-items: flex-end; }
.f-ctrl label { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--color-text-muted, #9aa3b8); font-weight: 700; margin-bottom: 5px; }
.f-ctrl select, .f-ctrl input {
  width: 100%;
  border: 1px solid var(--color-border, #e2e6ed);
  border-radius: 6px;
  padding: 7px 10px;
  font-size: 13px;
  font-family: inherit;
  background: var(--color-surface, #ffffff);
  color: var(--color-text-primary, #1a2133);
}

.table-inv { width: 100%; border-collapse: collapse; margin: 0; }
.table-inv th {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .05em;
  color: var(--color-text-muted, #9aa3b8);
  font-weight: 700;
  text-align: left;
  padding: 10px 12px;
  border-bottom: 1px solid var(--color-border, #e2e6ed);
  background: #f8f9fb;
  white-space: nowrap;
  position: sticky;
  top: 0;
  z-index: 1;
}
.table-inv td { padding: 9px 12px; border-bottom: 1px solid #eef1f5; font-size: 13px; white-space: nowrap; vertical-align: middle; }
.table-inv tbody tr:nth-child(even) { background: #f8f9fb; }
.table-inv tbody tr:hover { background: #fdf6e8; }

.badge-local {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: #f1f5f9;
  border: 1px solid #cbd5e1;
  padding: 2px 7px;
  border-radius: 4px;
  font-family: 'JetBrains Mono', monospace;
  font-weight: 700;
  color: #334155;
  font-size: 12px;
}
.badge-tag { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; }
.tag-diam { background: #e0f2fe; color: #0369a1; }
.tag-press { background: #e8f7ed; color: #15703c; }
.tag-kraft { background: #fef3c7; color: #b45309; }
.tag-disp { background: #dcfce7; color: #15803d; }
.tag-res { background: #fef9c3; color: #a16207; }
.tag-cons { background: #f1f5f9; color: #64748b; }

.btn-act {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 5px 10px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid transparent;
  transition: .15s ease-in-out;
}
.btn-act-primary { background: var(--color-sidebar, #1a3d2a); color: #fff; }
.btn-act-primary:hover { background: #132e20; }
.btn-act-outline { background: #fff; border-color: #cbd5e1; color: #475569; }
.btn-act-outline:hover { background: #f8fafc; border-color: #94a3b8; }
.btn-act-danger { background: #fee2e2; border-color: #fca5a5; color: #b91c1c; }
.btn-act-danger:hover { background: #fecaca; }

/* Modal */
.modal-overlay {
  position: fixed; inset: 0; background: rgba(15,23,42,.6);
  display: none; align-items: center; justify-content: center; z-index: 9999;
  backdrop-filter: blur(2px);
}
.modal-overlay.active { display: flex; }
.modal-card {
  background: #fff; border-radius: 12px; width: 100%; max-width: 560px;
  box-shadow: 0 20px 25px -5px rgba(0,0,0,.15); overflow: hidden;
}
.modal-head {
  padding: 14px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;
  display: flex; align-items: center; justify-content: space-between;
}
.modal-head h3 { margin: 0; font-size: 16px; font-weight: 700; color: #0f172a; }
.modal-body { padding: 20px; max-height: 75vh; overflow-y: auto; }
.modal-foot { padding: 14px 20px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 8px; }
</style>

<div class="wrap-inv">
  <!-- Cabeçalho -->
  <div class="flex items-center justify-between flex-wrap gap-4 mb-4">
    <div>
      <h1 class="text-2xl font-bold text-slate-900 tracking-tight flex items-center gap-2">
        <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
        Inventário Almoxarifado de Papel
      </h1>
      <p class="text-xs text-slate-500 mt-0.5">Estoque físico de tiras, bobinas, kits BT e cabeceiras para aproveitamento antes do corte.</p>
    </div>
    <div class="flex items-center gap-2">
      <button onclick="abrirModalNovo()" class="btn-act btn-act-primary">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        Novo Lançamento no Estoque
      </button>
      <a href="mesa-de-corte.php" class="btn-act btn-act-outline">
        <span>Ir para Mesa de Corte</span>
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      </a>
    </div>
  </div>

  <!-- KPIs de Saldo do Almoxarifado -->
  <div class="kpis-inv" id="kpis-container">
    <div class="kpi-inv">
      <div class="rot">Estoque Total Disponível</div>
      <div class="val" id="kpi-kg">0,00 <span class="un">kg</span></div>
    </div>
    <div class="kpi-inv green">
      <div class="rot">Total de Itens Cadastrados</div>
      <div class="val" id="kpi-itens">0 <span class="un">itens</span></div>
    </div>
    <div class="kpi-inv blue">
      <div class="rot">Ruas do Almoxarifado</div>
      <div class="val" id="kpi-ruas">0 <span class="un">ruas</span></div>
    </div>
    <div class="kpi-inv purple">
      <div class="rot">Kits BT e Cabeceiras</div>
      <div class="val" id="kpi-kits">0 <span class="un">kits</span></div>
    </div>
  </div>

  <!-- Filtros -->
  <div class="card-inv">
    <div class="card-inv-body">
      <div class="filtros-grid">
        <div class="f-ctrl">
          <label>Buscar (Tamanho, Projeto, Rua)</label>
          <input type="search" id="filtro-busca" placeholder="Ex: 330-15, 378241, Rua B..." oninput="filtrarDebounce()">
        </div>
        <div class="f-ctrl">
          <label>Rua</label>
          <select id="filtro-rua" onchange="carregarInventario()">
            <option value="">Todas as Ruas</option>
            <option value="A">Rua A</option>
            <option value="B">Rua B</option>
            <option value="C">Rua C</option>
            <option value="D">Rua D</option>
            <option value="E">Rua E</option>
            <option value="F">Rua F</option>
            <option value="1">Rua 1 (Kits)</option>
            <option value="2">Rua 2</option>
            <option value="3">Rua 3</option>
            <option value="4">Rua 4</option>
          </select>
        </div>
        <div class="f-ctrl">
          <label>Categoria</label>
          <select id="filtro-categoria" onchange="carregarInventario()">
            <option value="">Todas as Categorias</option>
            <option value="papel_tiras">Tiras de Papel (Dobras / Lisos)</option>
            <option value="kit_bt">Kits BT (Por Projeto)</option>
            <option value="cabeceiras">Cabeceiras / Calços</option>
          </select>
        </div>
        <div class="f-ctrl">
          <label>Material</label>
          <select id="filtro-material" onchange="carregarInventario()">
            <option value="">Todos os Materiais</option>
            <option value="DIAMANTADO">DIAMANTADO</option>
            <option value="PRESSPHAN">PRESSPHAN</option>
            <option value="KRAFT">KRAFT</option>
          </select>
        </div>
        <div class="f-ctrl">
          <label>Espessura</label>
          <select id="filtro-espessura" onchange="carregarInventario()">
            <option value="">Todas as Espessuras</option>
            <option value="0.18">0,18 mm</option>
            <option value="0.25">0,25 mm</option>
            <option value="0.38">0,38 mm</option>
            <option value="0.50">0,50 mm</option>
            <option value="1.00">1,00 mm</option>
            <option value="2.00">2,00 mm</option>
            <option value="3.00">3,00 mm</option>
          </select>
        </div>
        <div class="f-ctrl">
          <label>Status</label>
          <select id="filtro-status" onchange="carregarInventario()">
            <option value="">Todos os Status</option>
            <option value="disponivel" selected>Disponível</option>
            <option value="reservado">Reservado</option>
            <option value="consumido">Consumido (Zerado)</option>
          </select>
        </div>
        <div>
          <button onclick="limparFiltros()" class="btn-act btn-act-outline w-full justify-center">
            Limpar Filtros
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Tabela de Itens em Estoque -->
  <div class="card-inv">
    <div class="card-inv-head">
      <div class="flex items-center gap-2">
        <h2 id="titulo-tabela">Itens do Estoque</h2>
        <span class="text-xs text-slate-400" id="contador-tabela">(Carregando...)</span>
      </div>
      <div class="text-xs text-slate-500">
        Localização física por Rua e Prateleira cadastrada no almoxarifado
      </div>
    </div>
    <div class="card-inv-body flush">
      <div class="overflow-x-auto">
        <table class="table-inv">
          <thead>
            <tr>
              <th>Localização</th>
              <th>Categoria</th>
              <th>Material / Tipo</th>
              <th>Especificação / Tamanho</th>
              <th>Espessura</th>
              <th>Projeto TPD</th>
              <th class="text-right">Saldo Disponível</th>
              <th>Status</th>
              <th class="text-right">Ações</th>
            </tr>
          </thead>
          <tbody id="tabela-itens-corpo">
            <tr>
              <td colspan="9" class="text-center py-8 text-slate-400">Carregando itens do almoxarifado...</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Modal Novo / Editar Item -->
<div class="modal-overlay" id="modal-item">
  <div class="modal-card">
    <div class="modal-head">
      <h3 id="modal-item-titulo">Novo Item no Estoque</h3>
      <button onclick="fecharModal('modal-item')" class="text-slate-400 hover:text-slate-600 font-bold text-xl">&times;</button>
    </div>
    <form id="form-item" onsubmit="salvarItemEstoque(event)">
      <input type="hidden" id="item-id" name="id">
      <div class="modal-body space-y-4">
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Categoria *</label>
            <select id="item-categoria" name="categoria" class="w-full border rounded p-2 text-sm" required onchange="ajustarCamposModal()">
              <option value="papel_tiras">Tiras de Papel (Dobra/Liso)</option>
              <option value="kit_bt">Kit BT (Por Projeto)</option>
              <option value="cabeceiras">Cabeceira / Calço</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Material *</label>
            <select id="item-material" name="material" class="w-full border rounded p-2 text-sm" required>
              <option value="DIAMANTADO">DIAMANTADO</option>
              <option value="PRESSPHAN">PRESSPHAN</option>
              <option value="KRAFT">KRAFT</option>
            </select>
          </div>
        </div>

        <div class="grid grid-cols-3 gap-3">
          <div>
            <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Rua *</label>
            <input type="text" id="item-rua" name="rua" placeholder="Ex: B, A, 1" class="w-full border rounded p-2 text-sm uppercase" required>
          </div>
          <div>
            <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Prateleira</label>
            <input type="text" id="item-prateleira" name="prateleira" placeholder="Ex: 4, 2" class="w-full border rounded p-2 text-sm">
          </div>
          <div>
            <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Caixa</label>
            <input type="text" id="item-caixa" name="caixa" placeholder="Ex: 1, 2" class="w-full border rounded p-2 text-sm">
          </div>
        </div>

        <div class="grid grid-cols-3 gap-3">
          <div>
            <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Tipo de Papel</label>
            <select id="item-tipo-papel" name="tipo_papel" class="w-full border rounded p-2 text-sm">
              <option value="DOBRA">DOBRA</option>
              <option value="LISO">LISO</option>
              <option value="KIT">KIT</option>
              <option value="CABECEIRA">CABECEIRA</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Tamanho / Medida *</label>
            <input type="text" id="item-tamanho" name="tamanho" placeholder="Ex: 330-15, 185-25" class="w-full border rounded p-2 text-sm" required>
          </div>
          <div>
            <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Espessura (mm)</label>
            <input type="number" step="0.01" id="item-espessura" name="espessura" placeholder="0,25" class="w-full border rounded p-2 text-sm">
          </div>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Projeto TPD (Opcional)</label>
            <input type="text" id="item-projeto" name="tpd_projeto" placeholder="Ex: 378241" class="w-full border rounded p-2 text-sm">
          </div>
          <div>
            <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Saldo em Estoque (kg) *</label>
            <input type="number" step="0.01" id="item-estoque-kg" name="estoque_real_kg" placeholder="0,00" class="w-full border rounded p-2 text-sm" required>
          </div>
        </div>

        <div>
          <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Observações</label>
          <textarea id="item-obs" name="observacoes" rows="2" placeholder="Observações opcionais..." class="w-full border rounded p-2 text-sm"></textarea>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" onclick="fecharModal('modal-item')" class="btn-act btn-act-outline">Cancelar</button>
        <button type="submit" class="btn-act btn-act-primary">Salvar no Estoque</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Dar Baixa / Retirada -->
<div class="modal-overlay" id="modal-baixa">
  <div class="modal-card max-w-md">
    <div class="modal-head">
      <h3>Registrar Baixa de Material</h3>
      <button onclick="fecharModal('modal-baixa')" class="text-slate-400 hover:text-slate-600 font-bold text-xl">&times;</button>
    </div>
    <form id="form-baixa" onsubmit="executarBaixaEstoque(event)">
      <input type="hidden" id="baixa-id" name="id">
      <div class="modal-body space-y-4">
        <div class="bg-amber-50 border border-amber-200 rounded p-3 text-xs text-amber-900" id="baixa-resumo-item">
          <!-- Detalhes do item selecionado -->
        </div>

        <div>
          <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Quantidade a Retirar (kg) *</label>
          <input type="number" step="0.01" id="baixa-qtd-kg" name="quantidade_kg" placeholder="0,00" class="w-full border rounded p-2 text-sm font-bold" required>
        </div>

        <div>
          <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Responsável pela Retirada</label>
          <input type="text" id="baixa-resp" name="responsavel_retirada" placeholder="Nome de quem retirou na prateleira" class="w-full border rounded p-2 text-sm">
        </div>

        <div>
          <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Motivo / Destino</label>
          <input type="text" id="baixa-motivo" name="motivo" value="Retirada para Bobinagem / Mesa" class="w-full border rounded p-2 text-sm">
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" onclick="fecharModal('modal-baixa')" class="btn-act btn-act-outline">Cancelar</button>
        <button type="submit" class="btn-act btn-act-danger">Confirmar Baixa</button>
      </div>
    </form>
  </div>
</div>

<script>
let dadosInventario = [];
let debounceTimer = null;

function filtrarDebounce() {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => {
    carregarInventario();
  }, 250);
}

function limparFiltros() {
  document.getElementById('filtro-busca').value = '';
  document.getElementById('filtro-rua').value = '';
  document.getElementById('filtro-categoria').value = '';
  document.getElementById('filtro-material').value = '';
  document.getElementById('filtro-espessura').value = '';
  document.getElementById('filtro-status').value = 'disponivel';
  carregarInventario();
}

function carregarInventario() {
  const busca = document.getElementById('filtro-busca').value;
  const rua = document.getElementById('filtro-rua').value;
  const categoria = document.getElementById('filtro-categoria').value;
  const material = document.getElementById('filtro-material').value;
  const espessura = document.getElementById('filtro-espessura').value;
  const status = document.getElementById('filtro-status').value;

  const appBase = window.__APP_BASE || '';
  const url = `${appBase}/api/papel-inventario-acao.php?acao=listar_inventario&busca=${encodeURIComponent(busca)}&rua=${encodeURIComponent(rua)}&categoria=${encodeURIComponent(categoria)}&material=${encodeURIComponent(material)}&espessura=${encodeURIComponent(espessura)}&status=${encodeURIComponent(status)}`;

  fetch(url)
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        dadosInventario = res.itens || [];
        renderizarKpis(res.kpis);
        renderizarTabela(dadosInventario);
      } else {
        alert('Erro ao listar inventário: ' + (res.error || 'Falha desconhecida'));
      }
    })
    .catch(err => {
      console.error(err);
      document.getElementById('tabela-itens-corpo').innerHTML = `<tr><td colspan="9" class="text-center py-6 text-red-500">Erro de comunicação com a API: ${err.message}</td></tr>`;
    });
}

function renderizarKpis(k) {
  if (!k) return;
  const kgFmt = Number(k.total_kg_disponivel || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  document.getElementById('kpi-kg').innerHTML = `${kgFmt} <span class="un">kg</span>`;
  document.getElementById('kpi-itens').innerHTML = `${k.total_itens || 0} <span class="un">itens</span>`;
  document.getElementById('kpi-ruas').innerHTML = `${k.total_ruas || 0} <span class="un">ruas</span>`;
  const totalKits = (Number(k.total_kits_bt || 0) + Number(k.total_cabeceiras || 0));
  document.getElementById('kpi-kits').innerHTML = `${totalKits} <span class="un">un</span>`;
}

function renderizarTabela(itens) {
  const corpo = document.getElementById('tabela-itens-corpo');
  const contador = document.getElementById('contador-tabela');
  contador.textContent = `(${itens.length} registro${itens.length === 1 ? '' : 's'})`;

  if (!itens || itens.length === 0) {
    corpo.innerHTML = `<tr><td colspan="9" class="text-center py-10 text-slate-400">Nenhum item encontrado no estoque com os filtros selecionados.</td></tr>`;
    return;
  }

  let html = '';
  itens.forEach(i => {
    let tagMat = 'tag-diam';
    if (i.material === 'PRESSPHAN') tagMat = 'tag-press';
    else if (i.material === 'KRAFT') tagMat = 'tag-kraft';

    let tagSt = 'tag-disp';
    let labelSt = 'Disponível';
    if (i.status === 'reservado') { tagSt = 'tag-res'; labelSt = 'Reservado'; }
    else if (i.status === 'consumido') { tagSt = 'tag-cons'; labelSt = 'Consumido'; }

    let localFmt = `Rua <strong>${i.rua}</strong>`;
    if (i.prateleira) localFmt += ` / Prat. <strong>${i.prateleira}</strong>`;
    if (i.caixa) localFmt += ` / Cx. <strong>${i.caixa}</strong>`;

    let saldoFmt = '—';
    if (Number(i.estoque_real_kg) > 0) {
      saldoFmt = `<strong class="text-slate-800">${Number(i.estoque_real_kg).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} kg</strong>`;
    } else if (Number(i.estoque_real_qtd) > 0) {
      saldoFmt = `<strong class="text-slate-800">${i.estoque_real_qtd} un</strong>`;
    }

    let espFmt = i.espessura > 0 ? `${Number(i.espessura).toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 2 })} mm` : '—';
    let catFmt = i.categoria === 'papel_tiras' ? 'Tiras' : (i.categoria === 'kit_bt' ? 'Kit BT' : 'Cabeceira');

    html += `
      <tr>
        <td>
          <span class="badge-local">${localFmt}</span>
        </td>
        <td><span class="text-xs font-semibold text-slate-600">${catFmt}</span></td>
        <td>
          <span class="badge-tag ${tagMat}">${i.material}</span>
          ${i.tipo_papel ? `<span class="text-xs text-slate-500 ml-1">(${i.tipo_papel})</span>` : ''}
        </td>
        <td>
          <strong class="text-slate-900 font-mono text-sm">${i.tamanho || '—'}</strong>
        </td>
        <td class="font-mono text-slate-700">${espFmt}</td>
        <td>
          ${i.tpd_projeto ? `<span class="bg-amber-100 text-amber-900 px-2 py-0.5 rounded text-xs font-bold font-mono">${i.tpd_projeto}</span>` : '<span class="text-slate-300">—</span>'}
        </td>
        <td class="text-right font-mono">${saldoFmt}</td>
        <td><span class="badge-tag ${tagSt}">${labelSt}</span></td>
        <td class="text-right">
          <div class="flex items-center justify-end gap-1">
            <button onclick="abrirModalBaixa(${i.id})" class="btn-act btn-act-outline text-amber-700 hover:bg-amber-50" title="Dar baixa ou registrar retirada">
              ⬇ Baixa
            </button>
            <button onclick="abrirModalEditar(${i.id})" class="btn-act btn-act-outline" title="Editar item">
              ✏
            </button>
          </div>
        </td>
      </tr>
    `;
  });

  corpo.innerHTML = html;
}

function abrirModalNovo() {
  document.getElementById('form-item').reset();
  document.getElementById('item-id').value = '';
  document.getElementById('modal-item-titulo').textContent = 'Novo Item no Estoque';
  document.getElementById('modal-item').classList.add('active');
}

function abrirModalEditar(id) {
  const item = dadosInventario.find(x => Number(x.id) === Number(id));
  if (!item) return;

  document.getElementById('item-id').value = item.id;
  document.getElementById('item-categoria').value = item.categoria;
  document.getElementById('item-material').value = item.material;
  document.getElementById('item-rua').value = item.rua;
  document.getElementById('item-prateleira').value = item.prateleira || '';
  document.getElementById('item-caixa').value = item.caixa || '';
  document.getElementById('item-tipo-papel').value = item.tipo_papel || 'DOBRA';
  document.getElementById('item-tamanho').value = item.tamanho || '';
  document.getElementById('item-espessura').value = item.espessura || '';
  document.getElementById('item-projeto').value = item.tpd_projeto || '';
  document.getElementById('item-estoque-kg').value = item.estoque_real_kg || '0';
  document.getElementById('item-obs').value = item.observacoes || '';

  document.getElementById('modal-item-titulo').textContent = `Editar Item #${item.id} (${item.rua}/${item.prateleira || item.caixa})`;
  document.getElementById('modal-item').classList.add('active');
}

function fecharModal(id) {
  document.getElementById(id).classList.remove('active');
}

function salvarItemEstoque(e) {
  e.preventDefault();
  const form = document.getElementById('form-item');
  const formData = new FormData(form);
  formData.append('acao', 'salvar_item');

  const appBase = window.__APP_BASE || '';
  fetch(`${appBase}/api/papel-inventario-acao.php`, {
    method: 'POST',
    body: formData
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      alert(res.mensagem || 'Item salvo com sucesso!');
      fecharModal('modal-item');
      carregarInventario();
    } else {
      alert('Erro ao salvar: ' + (res.error || 'Falha desconhecida'));
    }
  })
  .catch(err => alert('Erro de comunicação: ' + err.message));
}

function abrirModalBaixa(id) {
  const item = dadosInventario.find(x => Number(x.id) === Number(id));
  if (!item) return;

  document.getElementById('baixa-id').value = item.id;
  document.getElementById('baixa-qtd-kg').value = item.estoque_real_kg;
  document.getElementById('baixa-qtd-kg').max = item.estoque_real_kg;

  document.getElementById('baixa-resumo-item').innerHTML = `
    <strong>Item:</strong> ${item.tamanho} (${item.material} - ${item.espessura}mm)<br>
    <strong>Localização:</strong> Rua ${item.rua} ${item.prateleira ? '/ Prat. ' + item.prateleira : ''}<br>
    <strong>Saldo Atual:</strong> <span class="font-bold">${Number(item.estoque_real_kg).toLocaleString('pt-BR', {minimumFractionDigits: 2})} kg</span>
  `;

  document.getElementById('modal-baixa').classList.add('active');
}

function executarBaixaEstoque(e) {
  e.preventDefault();
  const form = document.getElementById('form-baixa');
  const formData = new FormData(form);
  formData.append('acao', 'dar_baixa');

  const appBase = window.__APP_BASE || '';
  fetch(`${appBase}/api/papel-inventario-acao.php`, {
    method: 'POST',
    body: formData
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      alert(res.mensagem || 'Baixa realizada!');
      fecharModal('modal-baixa');
      carregarInventario();
    } else {
      alert('Erro ao dar baixa: ' + (res.error || 'Falha desconhecida'));
    }
  })
  .catch(err => alert('Erro de comunicação: ' + err.message));
}

document.addEventListener('DOMContentLoaded', () => {
  carregarInventario();
});
</script>

<?php
layoutFooter();
?>
