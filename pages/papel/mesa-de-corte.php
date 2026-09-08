<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/layout.php';

requireLogin();
requireAcessoPapel();

$pageTitle = 'Mesa de Corte — Setor de Papel';
layoutHeader($pageTitle);
?>

<style>
/* CSS Mesa de Corte SGT — reaproveita os componentes padrão do design system
   (.card, .card-header, .metric-card, .data-table, .badge, .btn, .modal, .form-control
   de assets/css/main.css, o mesmo main.css do SGE) e mantém aqui só o que é
   específico desta tela: seletor de máquinas, chips de filtro, abas, controle
   segmentado e o banner/rodapé de rastreabilidade do ERP. */
.wrap-mc { max-width: 100%; margin: 0 auto; padding: 6px 14px 40px; }
.mono { font-family: var(--font-mono); font-variant-numeric: tabular-nums; }

/* Navegação Superior por Máquinas */
.nav-maquinas-mc {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
  gap: 10px;
  margin-bottom: 16px;
}
.tab-maq-btn {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 10px 14px;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 12px;
  text-align: left;
  transition: all 0.15s ease;
  box-shadow: 0 1px 2px rgba(0,0,0,0.03);
}
.tab-maq-btn:hover {
  border-color: #94a3b8;
  background: #f8fafc;
  transform: translateY(-1px);
}
.tab-maq-btn.active {
  background: #f0fdf4;
  border-color: #1a3d2a;
  box-shadow: 0 2px 6px rgba(26,61,42,0.12);
}
.tab-maq-btn .maq-ico {
  color: #475569;
  background: #f1f5f9;
  padding: 8px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.tab-maq-btn.active .maq-ico {
  background: #dcfce7;
  color: #1a3d2a;
}
.tab-maq-btn .maq-tit {
  font-size: 13px;
  font-weight: 700;
  color: #1a2133;
  line-height: 1.2;
}
.tab-maq-btn.active .maq-tit {
  color: #1a3d2a;
}
.tab-maq-btn .maq-sub {
  font-size: 11px;
  color: #64748b;
  margin-top: 3px;
}

/* Filtros */
.filtros-grid-container {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 12px;
}
.filtro-bloco {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 8px 10px;
}
.filtro-bloco > label {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 10.5px;
  text-transform: uppercase;
  letter-spacing: .04em;
  color: #64748b;
  font-weight: 700;
  margin-bottom: 6px;
}
.chips-mc { display: flex; flex-wrap: wrap; gap: 4px; }
.chip-mc {
  border: 1px solid var(--color-border);
  background: var(--color-surface);
  border-radius: var(--radius-full);
  padding: 2px 9px;
  font-size: var(--font-size-xs);
  cursor: pointer;
  color: var(--color-text-secondary);
  font-family: inherit;
  transition: var(--transition);
}
.chip-mc:hover { border-color: var(--color-text-muted); }
.chip-mc[aria-pressed="true"] {
  background: var(--color-accent);
  border-color: var(--color-accent);
  color: #fff;
  font-weight: 600;
}

/* Dropdown de multi-seleção por checkbox (Tamanho do Papel) — mesmo espírito
   do filtro de executores da Gestão de Cards (SGE), sem o arraste-pra-reordenar
   (aqui não há colunas pra reordenar, só a lista de medidas). */
.ms-dropdown { position: relative; }
.ms-toggle { display: flex; align-items: center; justify-content: space-between; gap: 8px; width: 100%; cursor: pointer; text-align: left; }
.ms-caret { opacity: .6; font-size: 10px; }
.ms-panel { position: absolute; z-index: 50; top: calc(100% + 4px); left: 0; right: 0; min-width: 240px; background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-md); box-shadow: var(--shadow-lg); padding: 6px; max-height: 280px; display: none; flex-direction: column; }
.ms-panel.open { display: flex; }
.ms-list { overflow-y: auto; flex: 1; padding: 2px; }
.ms-opt { display: flex; align-items: center; gap: 8px; padding: 5px 6px; border-radius: var(--radius-sm); cursor: pointer; font-size: 12px; }
.ms-opt:hover { background: rgba(127,127,127,.08); }
.ms-opt input { width: 14px; height: 14px; flex-shrink: 0; cursor: pointer; }
.ms-vazio { font-size: 11px; color: var(--color-text-muted); padding: 10px 8px; margin: 0; text-align: center; }
.ms-dica { font-size: 10px; color: var(--color-text-muted); padding: 2px 6px 6px; margin: 0; }
.ms-busca { width: 100%; box-sizing: border-box; margin: 0 0 6px; padding: 6px 8px; font-size: 12px; border: 1px solid var(--color-border); border-radius: var(--radius-sm); }
.ms-busca:focus { outline: none; border-color: var(--color-accent); }
.ms-opt.ms-oculto { display: none; }
.ms-actions { display: flex; gap: 8px; justify-content: space-between; padding: 6px 4px 2px; border-top: 1px solid var(--color-border); margin-top: 4px; }
.ms-btn { background: none; border: none; color: var(--color-accent); font-size: 12px; font-weight: 600; cursor: pointer; padding: 2px 4px; }
.ms-btn:hover { text-decoration: underline; }

/* Tabelas: usa .table-wrap / .data-table do main.css; aqui só o alinhamento
   numérico com fonte monoespaçada, que não é padrão do design system. */
.num { text-align: right; font-family: var(--font-mono); font-variant-numeric: tabular-nums; }

/* Unidade inline ao lado do .metric-value dos KPIs (ex.: "1.234 pçs") */
.metric-value .un { font-size: var(--font-size-sm); font-weight: 400; color: var(--color-text-muted); margin-left: 3px; font-family: var(--font-sans); }

/* Badges de material/máquina — cores próprias do domínio, sobre a base .badge do main.css */
.badge-press { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
.badge-diam { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
.badge-kraft { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }

/* Badges de Máquinas */
.badge-maq-17001 { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
.badge-maq-17002 { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
.badge-maq-17012 { background: #ffedd5; color: #c2410c; border: 1px solid #fed7aa; }
.badge-maq-17003 { background: #f3e8ff; color: #6b21a8; border: 1px solid #e9d5ff; }

.barra-mc { height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; min-width: 60px; }
.barra-mc i { display: block; height: 100%; background: #1a3d2a; border-radius: 3px; }

/* Banner de Metadados ERP */
.banner-erp-mc {
  background: #f8fafc;
  border: 1px solid #cbd5e1;
  border-left: 4px solid #1a3d2a;
  border-radius: 8px;
  padding: 10px 14px;
  margin-bottom: 16px;
  font-size: 12px;
  color: #334155;
}
.banner-erp-mc .titulo-erp { font-weight: 700; font-size: 12.5px; color: #1a2133; margin-bottom: 4px; display: flex; align-items: center; justify-content: space-between; }
.banner-erp-mc .grid-filtros-erp { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 6px; margin-top: 6px; font-size: 11px; color: #475569; }

/* Rodapé de Rastreabilidade ERP */
.rodape-rastreio-erp {
  background: #f1f5f9;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  padding: 8px 12px;
  margin-top: 20px;
  font-size: 10.5px;
  color: #64748b;
  font-family: 'JetBrains Mono', monospace;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

.abas-mc { display: flex; gap: 2px; margin-left: auto; }
.aba-mc {
  border: 1px solid var(--color-border);
  background: var(--color-surface);
  padding: 4px 10px;
  font-size: 11.5px;
  font-family: inherit;
  cursor: pointer;
  color: var(--color-text-secondary);
}
.aba-mc:first-child { border-radius: var(--radius-md) 0 0 var(--radius-md); }
.aba-mc:last-child { border-radius: 0 var(--radius-md) var(--radius-md) 0; }
.aba-mc[aria-pressed="true"] { background: var(--color-accent); border-color: var(--color-accent); color: #fff; font-weight: 600; }

/* .btn-ordem / .btn-detalhes-agrup: o JS desta tela já gera essas classes em
   dezenas de linhas de HTML dinâmico — em vez de renomear tudo (alto risco de
   quebrar a filtragem), a aparência foi alinhada aqui aos tokens de .btn do
   main.css (mesmo padding/raio/hover que .btn-secondary e .btn-primary). */
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

.btn-detalhes-agrup {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 4px 10px;
  font-size: var(--font-size-sm);
  font-weight: 600;
  color: var(--color-info-text);
  background: var(--color-info-bg);
  border: 1px solid #bfdbfe;
  border-radius: var(--radius-md);
  cursor: pointer;
  transition: all var(--transition);
}
.btn-detalhes-agrup:hover { background: #dbeafe; border-color: #93c5fd; }

/* Segmented Control para Modo de Programação (Semana vs Mês) */
.seg-horizonte {
  display: inline-flex;
  background: var(--color-neutral-bg);
  border-radius: var(--radius-md);
  padding: 3px;
  border: 1px solid var(--color-border);
}
.seg-btn {
  border: none;
  background: transparent;
  padding: 4px 10px;
  font-size: 11.5px;
  font-weight: 600;
  color: var(--color-text-secondary);
  border-radius: var(--radius-sm);
  cursor: pointer;
  transition: all var(--transition);
  display: inline-flex;
  align-items: center;
  gap: 5px;
}
.seg-btn.active {
  background: var(--color-surface);
  color: var(--color-accent-text);
  box-shadow: var(--shadow-sm);
}
</style>

<div class="wrap-mc">

  <!-- Cabeçalho Principal -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px">
    <div>
      <h1 style="font-size:21px;font-weight:700;margin:0 0 2px;color:#1a2133">Mesa de Corte — Setor de Papel</h1>
      <div style="font-size:12px;color:#5a6474">
        Planejamento e Otimização de Corte de Isolação por Máquina, Espessura, Tamanho e Quantidade
      </div>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
      <button class="btn btn-primary" id="btn-sync-vsat" onclick="sincronizarComVsat()">
        <span style="display:inline-flex;align-items:center;gap:6px">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
          Sincronizar com ERP VSAT
        </span>
      </button>
      <a href="programacao.php" class="btn-ordem" style="padding:6px 12px">Ir para Programação de Bobinas</a>
      <a href="maquinas.php" class="btn-ordem" style="padding:6px 12px;display:inline-flex;align-items:center;gap:6px">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
        Cadastro Máquinas
      </a>
    </div>
  </div>

  <!-- Banner de Metadados e Rastreabilidade ERP -->
  <div class="banner-erp-mc">
    <div class="titulo-erp">
      <span style="display:inline-flex;align-items:center;gap:6px">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/><line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="13" y2="16"/></svg>
        PLANO MESTRE DE PRODUÇÃO ANALÍTICO (ERP VSAT — TRAEL TRANSFORMADORES)
      </span>
      <span style="font-size:11px;font-weight:normal;color:#64748b" id="lbl-data-sync">Sincronizado diretamente do banco de dados</span>
    </div>
    <div class="grid-filtros-erp">
      <div><b>Código Empresa Destino:</b> 01 (TRAEL Matriz)</div>
      <div><b>Unidade Fabril:</b> 01 - Distribuição</div>
      <div><b>Status OF:</b> Aguardando Reserva, Mat. Prima Reservada, OF Encerrada</div>
      <div><b>Tipo Construtivo:</b> 16 (A Óleo)</div>
    </div>
  </div>

  <!-- Navegação por Máquinas (Abas Superiores) -->
  <div class="nav-maquinas-mc" id="nav-maquinas-container">
    <button type="button" class="tab-maq-btn active" data-maq="" onclick="selecionarAbaMaquina('')">
      <span class="maq-ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg></span>
      <div class="maq-info">
        <div class="maq-tit">Todas as Máquinas (Geral)</div>
        <div class="maq-sub" id="maq-cnt-todas">Demanda Consolidada</div>
      </div>
    </button>
    <button type="button" class="tab-maq-btn" data-maq="17001" onclick="selecionarAbaMaquina('17001')">
      <span class="maq-ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg></span>
      <div class="maq-info">
        <div class="maq-tit">Slitter de Bobina (17001)</div>
        <div class="maq-sub" id="maq-cnt-17001">Bobinas / Fitas ≤ 0,50mm</div>
      </div>
    </button>
    <button type="button" class="tab-maq-btn" data-maq="17002" onclick="selecionarAbaMaquina('17002')">
      <span class="maq-ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><line x1="20" y1="4" x2="8.12" y2="15.88"/><line x1="14.47" y1="14.48" x2="20" y2="20"/><line x1="8.12" y1="8.12" x2="12" y2="12"/></svg></span>
      <div class="maq-info">
        <div class="maq-tit">Guilhotina Pedal (17002)</div>
        <div class="maq-sub" id="maq-cnt-17002">Placas / Tiras 1,0 e 2,0mm</div>
      </div>
    </button>
    <button type="button" class="tab-maq-btn" data-maq="17012" onclick="selecionarAbaMaquina('17012')">
      <span class="maq-ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg></span>
      <div class="maq-info">
        <div class="maq-tit">Guilhotina Elétrica (17012)</div>
        <div class="maq-sub" id="maq-cnt-17012">Chapas Pesadas 3,0 e 4,0mm</div>
      </div>
    </button>
    <button type="button" class="tab-maq-btn" data-maq="17003" onclick="selecionarAbaMaquina('17003')">
      <span class="maq-ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg></span>
      <div class="maq-info">
        <div class="maq-tit">Dobradeira de Papel (17003)</div>
        <div class="maq-sub" id="maq-cnt-17003">Canudos, Colarinhos e Dobras</div>
      </div>
    </button>
  </div>

  <!-- Card de Filtros Reorganizado -->
  <!-- overflow:visible (não hidden como os outros .card) para o dropdown
       de medidas (.ms-panel) não ser cortado no fim da caixa -->
  <div class="card" style="padding:0;overflow:visible">
    <div style="padding:14px 16px;border-bottom:1px solid var(--color-border);display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <h2 class="card-title">Filtros da Demanda & Horizonte de Corte</h2>
        <!-- Alternador de Horizonte: Semana vs Mês -->
        <div class="seg-horizonte">
          <button type="button" class="seg-btn active" id="btn-modo-semana" onclick="alternarModoHorizonte('semana')">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            Por Semana Específica
          </button>
          <button type="button" class="seg-btn" id="btn-modo-mes" onclick="alternarModoHorizonte('mes')">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="14" x2="16" y2="14"/></svg>
            Mês Inteiro (Agrupado)
          </button>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:8px">
        <button class="btn btn-sm btn-primary" id="btn-emitir-f29-filtro" onclick="emitirF29DoFiltro()" disabled>📄 Emitir F-29 do Filtro Atual</button>
        <button class="btn btn-sm btn-ghost" id="limpar">Limpar todos os filtros</button>
      </div>
    </div>
    <div style="padding:14px 16px">
      <div class="filtros-grid-container">
        
        <!-- 1. Período do PCP -->
        <div class="filtro-bloco">
          <label>Mês do PCP</label>
          <div class="chips-mc" id="f-mes"></div>
          <div style="margin-top:8px" id="wrap-f-sem">
            <label style="font-size:10px;margin-bottom:3px">Semana do PCP</label>
            <div class="chips-mc" id="f-sem"></div>
          </div>
        </div>

        <!-- 2. Material e Espessura do Projeto -->
        <div class="filtro-bloco">
          <label>Material do Papel</label>
          <div class="chips-mc" id="f-mat"></div>
          <div style="margin-top:8px">
            <label style="font-size:10px;margin-bottom:3px">Espessura Padronizada</label>
            <div class="chips-mc" id="f-esp"></div>
          </div>
        </div>

        <!-- 3. Tamanho / Medidas e Categoria -->
        <div class="filtro-bloco">
          <label>Tamanho do Papel (L × C mm)</label>
          <div class="ms-dropdown" id="ms-medida">
            <button type="button" class="form-control ms-toggle" id="ms-medida-toggle" onclick="msMedidaToggle(event)" aria-haspopup="true" aria-expanded="false">
              <span id="ms-medida-label">Todas as Medidas de Projeto</span>
              <span class="ms-caret">▼</span>
            </button>
            <div class="ms-panel" id="ms-medida-panel" role="menu">
              <p class="ms-dica">Marque para filtrar (pode escolher mais de uma)</p>
              <input type="text" class="ms-busca" id="ms-medida-busca" placeholder="Buscar medida (ex.: 490)" autocomplete="off">
              <div class="ms-list" id="ms-medida-list"></div>
              <p id="ms-medida-vazio" class="ms-vazio" style="display:none">Nenhuma medida encontrada.</p>
              <div class="ms-actions">
                <button type="button" class="ms-btn" onclick="msMedidaClear()">Limpar</button>
                <button type="button" class="ms-btn" onclick="document.getElementById('ms-medida-panel').classList.remove('open')">Fechar</button>
              </div>
            </div>
          </div>
          <div style="margin-top:8px">
            <label style="font-size:10px;margin-bottom:3px">Categoria de Isolação</label>
            <select id="f-cat" class="form-control">
              <option value="">Todas as Categorias</option>
            </select>
          </div>
        </div>

        <!-- 4. Status e Busca -->
        <div class="filtro-bloco">
          <label>Status de Engenharia</label>
          <div class="chips-mc" id="f-status-eng"></div>
          <div style="margin-top:6px">
            <label style="font-size:10px;margin-bottom:3px">Status do Corte</label>
            <div class="chips-mc" id="f-status"></div>
          </div>
          <div style="margin-top:6px">
            <input type="search" id="f-busca" class="form-control" placeholder="Buscar medida, projeto ou OF…">
          </div>
        </div>

      </div>
    </div>
  </div>

  <!-- KPIs -->
  <div class="grid-3 mb-4" id="kpis"></div>

  <!-- Tabela Principal: Demanda Agrupada por Máquina, Espessura, Tamanho e Quantidade -->
  <div class="card" style="padding:0;overflow:hidden">
    <div style="padding:14px 16px;border-bottom:1px solid var(--color-border)">
      <h2 class="card-title" id="titulo-grade-principal">Grade de Programação por Máquina, Espessura e Tamanho</h2>
      <span class="text-muted text-xs">Visão limpa de setup industrial (especificação de matéria-prima e dimensões de corte)</span>
    </div>
    <div>
      <div class="table-wrap"><table class="data-table" id="t-mat">
        <thead><tr>
          <th>Máquina Destino</th>
          <th>Material</th>
          <th class="num" style="text-align:left">Espessura</th>
          <th>Tamanho do Papel (L × C)</th>
          <th class="num" title="Quantidade unitária de peças no kit por transformador">Qtd / Trafo</th>
          <th class="num" title="Quantidade de Projetos TPD distintos vinculados a esta medida">Projetos</th>
          <th class="num" title="Quantidade total de Transformadores no lote">Trafos</th>
          <th class="num" style="background:#f0fdf4;color:#166534;font-weight:700">Total Peças a Cortar</th>
          <th class="num">Massa Total</th>
          <th style="width:80px">Fatia</th>
          <th style="text-align:center">Ação</th>
        </tr></thead>
        <tbody></tbody>
      </table></div>
      <div class="vazio-mc" id="v-mat" hidden style="padding:24px;text-align:center;color:#64748b">Nenhuma demanda de corte encontrada com os filtros selecionados.</div>
    </div>
  </div>

  <!-- Tabela 2: Peças de corte e Projetos (Visão de Auditoria Opcional) -->
  <div class="card" style="padding:0;overflow:hidden">
    <div style="padding:14px 16px;border-bottom:1px solid var(--color-border);display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <h2 class="card-title">Auditoria & Detalhamento Geral do Lote</h2>
      <span class="text-muted text-xs" id="h-peca"></span>
      <div class="abas-mc">
        <button class="aba-mc" data-aba="tpd" aria-pressed="true">Projetos TPD (Kits CMI)</button>
        <button class="aba-mc" data-aba="peca" aria-pressed="false">Peças por Desenho</button>
        <button class="aba-mc" data-aba="of" aria-pressed="false">OFs Filhas</button>
      </div>
    </div>
    <div>

      <!-- Aba Projetos TPD -->
      <div class="table-wrap" id="wrap-aba-tpd"><table class="data-table" id="t-tpd">
        <thead><tr>
          <th>Projeto</th>
          <th class="num">Trafos Programados</th>
          <th class="num">OFs no Kit</th>
          <th class="num">Peças Distintas</th>
          <th class="num">Total Peças</th>
          <th class="num">Massa</th>
          <th>Semanas</th>
          <th style="text-align:center">Engenharia</th>
          <th style="text-align:center">Corte</th>
          <th style="text-align:center">Ordem</th>
        </tr></thead>
        <tbody></tbody>
      </table></div>

      <!-- Aba Peças por Desenho -->
      <div class="table-wrap" id="wrap-aba-peca" hidden><table class="data-table" id="t-peca">
        <thead><tr>
          <th>Peça (Desenho)</th>
          <th>Categoria</th>
          <th>Máquina</th>
          <th>Material</th>
          <th class="num">Espessura</th>
          <th>Tamanho (L × C)</th>
          <th class="num" title="Peças necessárias por transformador">Qtd / Trafo</th>
          <th class="num">Projetos</th>
          <th class="num">Trafos</th>
          <th class="num">Total Peças</th>
          <th class="num">Massa</th>
          <th style="text-align:center">Ações</th>
        </tr></thead>
        <tbody></tbody>
      </table></div>

      <!-- Aba OFs Filhas -->
      <div class="table-wrap" id="wrap-aba-of" hidden><table class="data-table" id="t-of">
        <thead><tr>
          <th>OF Filha</th>
          <th>Projeto Principal</th>
          <th>Peça</th>
          <th>Máquina</th>
          <th>Material</th>
          <th class="num">Espessura</th>
          <th>Tamanho (L × C)</th>
          <th class="num">Qtd Peças</th>
          <th>Semana</th>
          <th style="text-align:center">Engenharia</th>
          <th style="text-align:center">Corte</th>
          <th style="text-align:center">Ações</th>
        </tr></thead>
        <tbody></tbody>
      </table></div>

      <div class="vazio-mc" id="v-auditoria" hidden style="padding:20px;text-align:center;color:#64748b">Nenhum registro encontrado.</div>
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

<!-- Modal: Detalhes dos Projetos, OFs e Peças da Especificação -->
<div class="modal-overlay" id="modal-detalhe-agrupamento" style="display:none;">
  <div class="modal" style="max-width:1500px;width:96vw">
    <div class="modal-header" style="border-left:4px solid var(--color-sidebar)">
      <h3 class="modal-title" id="modal-agrup-titulo" style="display:flex;align-items:center;gap:8px">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/><line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="13" y2="16"/></svg>
        <span id="lbl-modal-agrup-nome">Detalhamento & Programação de Corte</span>
      </h3>
      <button class="modal-close" onclick="fecharModal('modal-detalhe-agrupamento')">&times;</button>
    </div>
    <div class="modal-body">
      
      <!-- Resumo do Setup / Especificação -->
      <div id="modal-agrup-resumo" style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;padding:12px 16px;margin-bottom:14px;display:flex;gap:18px;flex-wrap:wrap;font-size:12.5px;color:#334155">
      </div>

      <!-- Barra de Controle do Modal (Abas e Busca) -->
      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;flex-wrap:wrap">
        <div class="abas-mc" style="margin-left:0">
          <button class="aba-mc aba-modal-btn" id="btn-aba-modal-pecas" aria-pressed="true" onclick="alternarAbaModal('pecas')">Peças de Engenharia</button>
          <button class="aba-mc aba-modal-btn" id="btn-aba-modal-projetos" aria-pressed="false" onclick="alternarAbaModal('projetos')">Projetos Agrupados (TPDs)</button>
          <button class="aba-mc aba-modal-btn" id="btn-aba-modal-ofs" aria-pressed="false" onclick="alternarAbaModal('ofs')">OFs Filhas Individuais</button>
        </div>
        <div style="flex:1;max-width:320px">
          <input type="search" id="modal-busca-input" class="form-control" placeholder="Buscar peça, projeto, OF..." oninput="filtrarModalInterno()">
        </div>
      </div>

      <!-- Tabela 1 Modal: Peças de Engenharia Envolvidas -->
      <div class="table-wrap" id="wrap-modal-pecas">
        <table class="data-table" id="t-modal-pecas">
          <thead>
            <tr>
              <th>Peça (Desenho Engenharia)</th>
              <th>Categoria CMI</th>
              <th>Máquina</th>
              <th>Material</th>
              <th class="num">Espessura</th>
              <th>Medida (L × C)</th>
              <th class="num">Qtd / Trafo</th>
              <th class="num">Total Peças a Cortar</th>
              <th class="num">Massa Est.</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>

      <!-- Tabela 2 Modal: Projetos Agrupados -->
      <div class="table-wrap" id="wrap-modal-projetos" hidden>
        <table class="data-table" id="t-modal-projetos">
          <thead>
            <tr>
              <th>Projeto (TPD)</th>
              <th>Semanas</th>
              <th class="num">Trafos (un)</th>
              <th class="num" title="Quantidade unitária por transformador">Qtd / Trafo</th>
              <th class="num">Total Peças a Cortar</th>
              <th class="num">Massa Est.</th>
              <th style="text-align:center">Status Engenharia</th>
              <th style="text-align:center">Status Corte</th>
              <th style="text-align:center">Ordem de Corte</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>

      <!-- Tabela 3 Modal: OFs Individuais -->
      <div class="table-wrap" id="wrap-modal-ofs" hidden>
        <table class="data-table" id="t-modal-ofs">
          <thead>
            <tr>
              <th>OF Filha</th>
              <th>Projeto Principal</th>
              <th>Peça (Engenharia)</th>
              <th>Material & Esp.</th>
              <th>Medida (L × C)</th>
              <th class="num">Qtd Peças</th>
              <th>Semana</th>
              <th style="text-align:center">Status Engenharia</th>
              <th style="text-align:center">Status Corte</th>
              <th style="text-align:center">Ações</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="fecharModal('modal-detalhe-agrupamento')">Fechar Detalhes</button>
    </div>
  </div>
</div>

<!-- Form oculto: envia as linhas filtradas da Grade de Programação pra Ordem de Corte (F-29 avulso) -->
<form id="form-f29-filtro" method="POST" action="ordem-corte.php" target="_blank" style="display:none">
  <input type="hidden" name="linhas" id="input-f29-linhas">
  <input type="hidden" name="resumo_filtro" id="input-f29-resumo">
</form>

<?php
$jsonPcpFile = __DIR__ . '/dados_pcp_completo.json';
$jsonPcpData = file_exists($jsonPcpFile) ? file_get_contents($jsonPcpFile) : '{}';
$jsonPcpData = preg_replace('/^\xEF\xBB\xBF/', '', (string)$jsonPcpData);
if (empty($jsonPcpData) || trim($jsonPcpData) === '') { $jsonPcpData = '{}'; }
?>
<script>
const D = <?= $jsonPcpData ?>;
</script>
<?php
$_mcJsVer = @filemtime(__DIR__ . '/../../assets/js/papel/mesa-de-corte.js') ?: (defined('APP_VERSION') ? APP_VERSION : '1');
$_mcBaseUrl = defined('APP_URL') ? APP_URL : '';
?>
<script src="<?= htmlspecialchars($_mcBaseUrl) ?>/assets/js/papel/mesa-de-corte.js?v=<?= htmlspecialchars((string) $_mcJsVer) ?>"></script>

<?php
layoutFooter();
