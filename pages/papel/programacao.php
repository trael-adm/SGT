<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/layout.php';

requireLogin();
requireAcessoPapel();

$pageTitle = 'Programação de Corte — Setor de Papel';
layoutHeader($pageTitle);
?>

<style>
/* Programação de Corte SGT — reaproveita .card, .data-table, .badge, .btn, .modal,
   .form-control de assets/css/main.css (mesmo main.css do SGE); aqui só o que é
   específico desta tela: grid de 2 colunas e o banner/rodapé de rastreabilidade ERP. */
.wrap-mc { max-width: 100%; margin: 0 auto; padding: 8px 14px 40px; }
.mono { font-family: var(--font-mono); font-variant-numeric: tabular-nums; }

.btn-ordem {
  display: inline-flex;
  align-items: center;
  white-space: nowrap;
  padding: 4px 10px;
  font-size: var(--font-size-xs);
  font-weight: 600;
  color: var(--color-text-primary);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  text-decoration: none;
  background: var(--color-surface);
  transition: all var(--transition);
  cursor: pointer;
}
.btn-ordem:hover { background: var(--color-surface-2); text-decoration: none; }
.btn-ordem.disabled {
  opacity: 0.45;
  cursor: not-allowed;
  background: var(--color-surface-2);
  border-color: var(--color-border);
  color: var(--color-text-muted);
}

.grid-programacao { display: grid; grid-template-columns: 340px 1fr; gap: 18px; }
@media (max-width: 900px) { .grid-programacao { grid-template-columns: 1fr; } }

/* Banner de Metadados ERP */
.banner-erp-mc {
  background: #f8fafc;
  border: 1px solid #cbd5e1;
  border-left: 4px solid #1a3d2a;
  border-radius: 8px;
  padding: 12px 16px;
  margin-bottom: 18px;
  font-size: 12px;
  color: #334155;
}
.banner-erp-mc .titulo-erp { font-weight: 700; font-size: 13px; color: #1a2133; margin-bottom: 4px; display: flex; align-items: center; justify-content: space-between; }
.banner-erp-mc .grid-filtros-erp { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 8px; margin-top: 8px; font-size: 11.5px; color: #475569; }

/* Rodapé de Rastreabilidade ERP */
.rodape-rastreio-erp {
  background: #f1f5f9;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  padding: 10px 14px;
  margin-top: 24px;
  font-size: 11px;
  color: #64748b;
  font-family: 'JetBrains Mono', monospace;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

</style>

<div class="wrap-mc">

  <!-- Barra Superior de Ações -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px">
    <div>
      <h1 style="font-size:22px;font-weight:700;margin:0 0 4px;color:#1a2133">Programação de Corte — Setor de Papel</h1>
      <div style="font-size:12px;color:#5a6474">
        Otimização de setup de bobinas no mês/semana e liberação de kits de isolação (PCP / Gesiane)
      </div>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
      <button class="btn btn-primary" id="btn-sync-vsat" onclick="sincronizarComVsat()">
        <span style="display:inline-flex;align-items:center;gap:6px">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
          Sincronizar com ERP VSAT
        </span>
      </button>
      <a href="mesa-de-corte.php" class="btn-ordem" style="padding:7px 14px">Ver Mesa de Corte</a>
    </div>
  </div>

  <!-- Banner de Metadados e Rastreabilidade ERP -->
  <div class="banner-erp-mc">
    <div class="titulo-erp">
      <span style="display:inline-flex;align-items:center;gap:6px">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/><line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="13" y2="16"/></svg>
        PROGRAMAÇÃO DE CORTE PCP (ERP VSAT — TRAEL TRANSFORMADORES)
      </span>
      <span style="font-size:11px;font-weight:normal;color:#64748b">Integrado a dw.vw_apontamento_celula_cte_serie e ControleProjetoPCP</span>
    </div>
    <div class="grid-filtros-erp">
      <div><b>Código Empresa Destino:</b> 01 (TRAEL Matriz)</div>
      <div><b>Unidade Fabril:</b> 01 - Distribuição</div>
      <div><b>Status OF:</b> Aguardando Reserva, Mat. Prima Reservada, OF Encerrada</div>
      <div><b>Tipo Construtivo:</b> 16 (A Óleo)</div>
    </div>
  </div>

  <div class="grid-programacao">
    
    <!-- Coluna Esquerda: Filtros e Estratégia de Programação -->
    <div class="card">
      <div class="card-header">
        <h2 class="card-title">1. Parâmetros de Programação</h2>
      </div>

      <div>
        <div class="metric-label">Período</div>
        <label class="form-label">Mês do PCP</label>
        <select id="sel-mes" class="form-control" style="margin-bottom:14px">
        </select>

        <label class="form-label">Semana / Período do PCP</label>
        <select id="sel-sem" class="form-control">
        </select>
      </div>

      <div style="border-top:1px solid var(--color-border);margin-top:16px;padding-top:16px">
        <div class="metric-label">Estratégia</div>
        <label class="form-label">Modo de Programação</label>
        <select id="sel-modo" class="form-control">
          <option value="bobina" selected>Por Bobina (Espessura × Largura) — Visão Mensal</option>
          <option value="projeto">Por Projeto Completo (Kit CMI da Engenharia)</option>
          <option value="peca">Por Peça Específica (Seleção Manual)</option>
        </select>
      </div>

      <div style="border-top:1px solid var(--color-border);margin-top:16px;padding-top:16px">
        <div class="metric-label">Filtros de Status</div>
        <label class="form-label">Status do Projeto (Engenharia)</label>
        <select id="sel-filtro-eng" class="form-control" style="margin-bottom:14px">
          <option value="todos" selected>Todos os Status de Projeto</option>
          <option value="liberados">🟢 Apenas Liberados pela Engenharia</option>
          <option value="aguardando">🟡 Aguardando Liberação / Em Elaboração</option>
        </select>

        <label class="form-label">Status da Demanda</label>
        <select id="sel-filtro-status" class="form-control">
          <option value="pendentes" selected>🟡 Mostrar Apenas O Que Falta (Pendentes)</option>
          <option value="todos">Ver Todas as Demandas (Pendentes + Concluídas)</option>
        </select>
      </div>

      <div style="border-top:1px solid var(--color-border);margin-top:16px;padding-top:16px">
        <div class="metric-label">Busca &amp; Ações</div>
        <label class="form-label">Buscar por Projeto, OF ou Peça</label>
        <input type="search" id="input-busca" class="form-control" style="margin-bottom:14px" placeholder="ex: TPD-402969, Cilindro, 2087281…">

        <button id="btn-carregar" class="btn btn-primary" style="width:100%;margin-bottom:10px;justify-content:center">Carregar Demanda PCP</button>

        <button id="btn-corte-extra" class="btn btn-danger" style="width:100%;justify-content:center" onclick="solicitarCorteExtra()">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Solicitar Corte Extra / Reposição
        </button>
      </div>
    </div>

    <!-- Coluna Direita: Lotes Sugeridos / Matriz Mensal de Bobinas -->
    <div class="card" style="padding:0;overflow:hidden">
      <div style="padding:14px 16px;border-bottom:1px solid var(--color-border);display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
        <h2 class="card-title" id="head-titulo-direita">2. Sugestão de Programação por Bobina (Setup Otimizado)</h2>
        <span class="badge badge-success">Pronto para liberar</span>
      </div>
      <div style="padding:16px">
        <p class="text-secondary text-sm" style="margin-top:0;margin-bottom:14px" id="desc-modo-direita">
          O sistema consolida a demanda do mês por Material, Espessura e Largura de Bobina, permitindo avaliar o corte conjunto de múltiplas semanas ou do mês inteiro para minimizar trocas de bobina e setups de máquina.
        </p>

        <div id="container-lotes"></div>
      </div>
    </div>

  </div>

  <!-- Rodapé Oficial de Rastreabilidade ERP -->
  <div class="rodape-rastreio-erp">
    <div>
      <b>Local:</b> /Tabelas Básicas Especialistas/Indústria e Comércio de Transformadores/Relatórios/Programação TRAEL - Semana Ano - DzOiD: 10101451 Revisão: 100 Consulta: 10101256
    </div>
    <div>
      <b>ERP:</b> VSAT (TRAEL TRANSFORMADORES ELÉTRICOS) · Banco VsatTrael
    </div>
  </div>

</div>

<!-- Modal: Solicitar Corte Extra / Reposição -->
<div class="modal-overlay" id="modal-corte-extra" style="display:none;">
  <div class="modal" style="max-width:540px">
    <div class="modal-header" style="border-left:4px solid var(--color-danger)">
      <h3 class="modal-title" style="display:flex;align-items:center;gap:8px">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        Solicitar Corte Extra / Reposição de Papel
      </h3>
      <button class="modal-close" onclick="fecharModal('modal-corte-extra')">&times;</button>
    </div>
    <div class="modal-body">
      <div style="font-size:12px;color:#64748b;margin-bottom:16px">
        Utilize este formulário para solicitar peças de reposição urgentes que faltaram ou foram danificadas na fábrica.
      </div>
      
      <div class="form-group">
        <label class="form-label">1. Projeto (TPD / TPS)</label>
        <select id="extra-tpd" class="form-control"></select>
      </div>

      <div class="form-group">
        <label class="form-label">2. Selecionar Peça / Papel da Engenharia</label>
        <select id="extra-peca-select" class="form-control">
          <option value="">Selecione o Projeto primeiro...</option>
        </select>
      </div>

      <!-- Card de Pré-visualização com Especificações Técnicas -->
      <div id="extra-peca-preview" style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;padding:12px;margin-bottom:14px;display:none;border-left:4px solid #2563eb">
        <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px">Especificações do Desenho / Engenharia</div>
        <div id="preview-peca-nome" style="font-size:13px;font-weight:700;color:#1e293b"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px;font-size:12px;color:#475569">
          <div><b>Material:</b> <span id="preview-peca-mat" style="color:#2563eb;font-weight:600"></span></div>
          <div><b>Espessura:</b> <span id="preview-peca-esp"></span></div>
          <div><b>Medidas (L × C):</b> <span id="preview-peca-dim" style="font-family:monospace;font-weight:600"></span></div>
          <div><b>Qtd Projeto Original:</b> <span id="preview-peca-qtd" style="font-weight:700"></span></div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group">
          <label class="form-label">Quantidade Extra (peças)</label>
          <input type="number" id="extra-qtd" value="5" min="1" class="form-control">
        </div>
        <div class="form-group">
          <label class="form-label">Motivo da Reposição</label>
          <select id="extra-motivo" class="form-control">
            <option value="Danificado na Montagem">Danificado na Montagem</option>
            <option value="Perda de Material / Refugo">Perda de Material / Refugo</option>
            <option value="Defeito de Bobina">Defeito de Bobina</option>
            <option value="Ajuste Construtivo de Engenharia">Ajuste Construtivo de Engenharia</option>
            <option value="Sobra / Ajuste de Estoque">Sobra / Ajuste de Estoque</option>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Observações para o Operador</label>
        <textarea id="extra-obs" rows="2" class="form-control" placeholder="Informações adicionais sobre o corte urgente…"></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="fecharModal('modal-corte-extra')">Cancelar</button>
      <button class="btn btn-danger" onclick="confirmarCorteExtraModal()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        Emitir Corte Extra URGENTE
      </button>
    </div>
  </div>
</div>

<!-- Modal: Detalhes dos Papéis do Projeto (Kit CMI) -->
<div class="modal-overlay" id="modal-detalhes-projeto" style="display:none;">
  <div class="modal" style="max-width:1180px;width:94%">
    <div class="modal-header" style="border-left:4px solid var(--color-info)">
      <h3 class="modal-title" style="display:flex;align-items:center;gap:8px">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
        Kit Completo de Isolação (CMI): <span id="modal-tpd-nome" class="mono" style="color:var(--color-info)">TPD-XXXXXX</span>
      </h3>
      <button class="modal-close" onclick="fecharModal('modal-detalhes-projeto')">&times;</button>
    </div>
    <div class="modal-body">
      <!-- Resumo do Projeto -->
      <div id="modal-tpd-resumo" style="background:var(--color-info-bg);padding:12px 16px;border-radius:var(--radius-lg);margin-bottom:16px;display:flex;gap:20px;flex-wrap:wrap;font-size:13px;color:var(--color-info-text);border:1px solid #bfdbfe"></div>

      <!-- Seções dos Conjuntos de Isolação (AT, BT, Parte Ativa) -->
      <div id="modal-tpd-secoes"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="fecharModal('modal-detalhes-projeto')">Fechar</button>
    </div>
  </div>
</div>

<!-- Modal: Análise de Aproveitamento do Estoque (Almoxarifado) -->
<div class="modal-overlay" id="modal-aproveitamento-estoque" style="display:none;">
  <div class="modal" style="max-width:680px;width:92%">
    <div class="modal-header" style="border-left:4px solid var(--color-warning)">
      <h3 class="modal-title" style="display:flex;align-items:center;gap:8px">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
        Análise de Aproveitamento de Estoque (Almoxarifado)
      </h3>
      <button class="modal-close" onclick="fecharModal('modal-aproveitamento-estoque')">&times;</button>
    </div>
    <div class="modal-body" id="modal-aproveitamento-corpo">
      <!-- Conteúdo dinâmico -->
    </div>
    <div class="modal-footer" id="modal-aproveitamento-foot">
      <button class="btn btn-secondary" onclick="fecharModal('modal-aproveitamento-estoque')">Fechar</button>
      <button id="btn-rejeitar-aproveitamento" class="btn" style="background:var(--color-danger-bg);color:var(--color-danger-text);border-color:#fca5a5">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        Cortar Novo na Máquina
      </button>
      <button id="btn-aprovar-aproveitamento" class="btn btn-success">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
        Aprovar Aproveitamento (Abater)
      </button>
    </div>
  </div>
</div>

<?php
$jsonPcpFile = __DIR__ . '/dados_pcp_completo.json';
$jsonPcpData = file_exists($jsonPcpFile) ? file_get_contents($jsonPcpFile) : '{}';
$jsonPcpData = preg_replace('/^\xEF\xBB\xBF/', '', (string)$jsonPcpData);
if (empty($jsonPcpData) || trim($jsonPcpData) === '') { $jsonPcpData = '{}'; }
$_progJsVer = @filemtime(__DIR__ . '/../../assets/js/papel/programacao.js') ?: (defined('APP_VERSION') ? APP_VERSION : '1');
$_progBaseUrl = defined('APP_URL') ? APP_URL : '';
?>
<script>
const D = <?= $jsonPcpData ?>;
</script>
<script src="<?= htmlspecialchars($_progBaseUrl) ?>/assets/js/papel/programacao.js?v=<?= htmlspecialchars((string) $_progJsVer) ?>"></script>

<?php
layoutFooter();
