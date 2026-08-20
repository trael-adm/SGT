<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/layout.php';

requireAcessoModulo('admin');

$pdo = getDB();

// Carregar Perfis
$perfisDB = $pdo->query("
    SELECT id, cod, nome, grupo, descricao AS `desc`, status, sistema, perms, DATE_FORMAT(created_at, '%d/%m/%Y') as criado 
    FROM perfis 
    ORDER BY nome ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Contar usuários por perfil
$userCountByPerfil = $pdo->query("SELECT id_perfil, COUNT(id) as total FROM usuarios WHERE deleted_at IS NULL GROUP BY id_perfil")->fetchAll(PDO::FETCH_KEY_PAIR);

$perfis = [];
$perfilIdToCod = [];
foreach ($perfisDB as $p) {
    $p['sistema'] = (bool)$p['sistema'];
    $p['usuarios'] = (int)($userCountByPerfil[$p['id']] ?? 0);
    $p['perms'] = json_decode((string)$p['perms'], true) ?: [];
    // O mock espera que o ID seja usado em partes do layout, mas o 'cod' é a chave usada pelas relações.
    $perfis[] = $p;
    $perfilIdToCod[$p['id']] = $p['cod'];
}

// Carregar Setores
$setoresDB = $pdo->query("
    SELECT id, cod, nome, resp, cc, turnos, perfil, status, DATE_FORMAT(created_at, '%d/%m/%Y') as criado 
    FROM setores 
    ORDER BY nome ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Contar usuários por setor
$userCountBySetor = $pdo->query("SELECT id_setor, COUNT(id) as total FROM usuarios WHERE deleted_at IS NULL GROUP BY id_setor")->fetchAll(PDO::FETCH_KEY_PAIR);

$setores = [];
foreach ($setoresDB as $s) {
    $s['turnos'] = json_decode((string)$s['turnos'], true) ?: [];
    $s['usuarios'] = (int)($userCountBySetor[$s['id']] ?? 0);
    $setores[] = $s;
}

layoutHeader('Perfis de Acesso e Setores');
?>

<style>
:root{
  --sidebar:#133A27;--sidebar-active:#1D5136;--sidebar-label:#7FA890;--sidebar-text:#DCE9E1;
  --orange:#E0951F;--orange-dark:#C87F12;--green:#16A34A;--green-dark:#15803D;
  --page:#F4F5F7;--line:#E5E7EB;--line-soft:#F1F2F4;--ink:#111827;--ink-2:#374151;--muted:#6B7280;--muted-2:#9CA3AF;
}
.mono{font-family:"SFMono-Regular",Consolas,"Liberation Mono",Menlo,monospace;font-weight:600;letter-spacing:.3px}
.content{padding:24px 26px 40px;max-width:1680px;width:100%}

.crumb{color:var(--muted);font-size:12.5px;text-decoration:none;display:inline-block;margin-bottom:8px}
.crumb:hover{color:var(--orange-dark)}
.subtitle{color:var(--muted);font-size:13.5px}
.pills{display:flex;gap:11px;flex-wrap:wrap;margin:19px 0 17px}
.pill{padding:9px 19px;border-radius:999px;border:1px solid var(--line);background:#fff;font-size:13.5px;color:var(--ink-2);white-space:nowrap}
.pill:hover{border-color:#D1D5DB;background:#FAFAFA}
.pill.is-active{background:var(--orange);border-color:var(--orange);color:#fff;font-weight:600}

.card{background:#fff;border:1px solid var(--line);border-radius:11px;overflow:hidden}
.card-head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px 18px 4px}
.card-title{display:flex;align-items:center;gap:9px;font-size:15.5px;font-weight:600}
.card-title .ico{color:var(--orange)}
.card-body{padding:12px 18px 0}
.search{width:100%;padding:11px 14px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;font-family:inherit}
.search::placeholder{color:var(--muted-2)}
.search:focus{outline:none;border-color:var(--orange);box-shadow:0 0 0 3px rgba(224,149,31,.15)}
.filters{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:13px}
select.f{padding:9px 12px;border:1px solid var(--line);border-radius:8px;background:#fff;font-size:13.5px;font-family:inherit;color:var(--ink-2);min-width:158px}
.btn-filter{padding:9px 20px;border-radius:8px;background:var(--orange);color:#fff;font-weight:600;font-size:13.5px}
.btn-filter:hover{background:var(--orange-dark)}
.btn-new{padding:8px 15px;border-radius:8px;background:var(--orange);color:#fff;font-weight:600;font-size:13px;display:inline-flex;align-items:center;gap:7px}
.btn-new:hover{background:var(--orange-dark)}
.toggles{margin-left:auto;display:flex;align-items:center;gap:16px;font-size:13px;color:var(--ink-2)}
.toggles .lbl{font-weight:600}
.chk{display:flex;align-items:center;gap:6px;cursor:pointer}
.chk input{width:15px;height:15px;accent-color:#2563EB;cursor:pointer}

.table-wrap{overflow-x:auto;margin-top:16px}
.table-wrap table{width:100%;border-collapse:collapse;min-width:1080px}
.table-wrap thead th{font-size:10.5px;font-weight:700;letter-spacing:.75px;text-transform:uppercase;color:var(--muted);padding:10px 12px;text-align:center;white-space:nowrap;border:none;}
.table-wrap thead th.l{text-align:left}
.table-wrap thead th.r{text-align:right}
.table-wrap tbody td{padding:13px 12px;text-align:center;border-top:1px solid var(--line-soft);font-size:13.5px;color:var(--ink-2);border-left:none;border-right:none;}
.table-wrap tbody td.l{text-align:left}
.table-wrap tbody td.r{text-align:right}
.table-wrap tbody tr.row:hover{background:#FCFCFD}
.expander{width:26px;height:26px;border:1px solid var(--line);border-radius:6px;color:var(--muted);font-size:15px;display:grid;place-items:center}
.expander:hover{border-color:var(--orange);color:var(--orange)}
.cell{display:flex;flex-direction:column;gap:2px;min-width:210px}
.cell b{font-weight:700;color:var(--ink);font-size:13.5px;display:flex;align-items:center;gap:7px}
.cell span{font-size:11.5px;color:var(--muted-2)}
.lock{font-size:10px;font-weight:700;color:#475569;background:#EEF2F5;padding:2px 6px;border-radius:5px}
.badge{display:inline-block;padding:4px 11px;border-radius:999px;font-size:11.5px;font-weight:700;white-space:nowrap}
.b-ADM{background:#FEE2E2;color:#DC2626}.b-GES{background:#FEF0DA;color:#B45309}
.b-SUP{background:#DBEAFE;color:#1D4ED8}.b-OPE{background:#F3F4F6;color:#4B5563}.b-VIS{background:#F3F4F6;color:#6B7280}
.s-ativo{background:#DCFCE7;color:#15803D}.s-inativo{background:#F3F4F6;color:#6B7280}
.users-pill{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:var(--ink-2);background:#F6F7F9;border:1px solid var(--line);border-radius:999px;padding:4px 11px;font-weight:600}
.users-pill.zero{color:var(--muted-2);font-weight:500}
.meter{display:inline-flex;flex-direction:column;align-items:center;gap:4px;min-width:118px}
.meter-top{font-size:12.5px;color:var(--ink-2)}.meter-top b{color:var(--ink)}
.meter-bar{width:104px;height:6px;border-radius:99px;background:#EDF0F3;overflow:hidden}
.meter-bar i{display:block;height:100%;background:var(--green);border-radius:99px}
.meter-bar.partial i{background:#3B82F6}.meter-bar.low i{background:#94A3B8}
.turnos{display:inline-flex;gap:5px;justify-content:center}
.turno{width:26px;height:22px;border-radius:5px;display:grid;place-items:center;font-size:10.5px;font-weight:700;background:#F5F5F5;color:#B0B5BC}
.turno.on{background:#E7F6EC;color:#15803D}
.act{padding:8px 15px;border-radius:8px;font-size:13px;font-weight:600;color:#fff;display:inline-flex;align-items:center;gap:7px}
.act-green{background:var(--green)}.act-green:hover{background:var(--green-dark)}
.act-orange{background:var(--orange)}.act-orange:hover{background:var(--orange-dark)}
.act-ghost{background:#fff;border:1px solid var(--line);color:var(--ink-2);padding:8px 12px;border-radius:8px;font-size:13px;font-weight:600}
.act-ghost:hover{border-color:#CBD5E1;background:#F9FAFB}
.act-ghost[disabled]{opacity:.45;cursor:not-allowed}
.act-group{display:inline-flex;gap:8px;justify-content:flex-end}

.table-wrap tbody tr.detail td{background:#FAFBFC;padding:0}
.detail-inner{padding:18px 22px 20px;display:grid;grid-template-columns:1fr 290px;gap:26px}
.detail-h{font-size:10.5px;font-weight:700;letter-spacing:.75px;text-transform:uppercase;color:var(--muted);margin-bottom:11px}
.area-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:8px}
.area-row{display:grid;grid-template-columns:1fr 92px 74px;align-items:center;gap:10px;background:#fff;border:1px solid var(--line);border-radius:8px;padding:9px 12px}
.area-name{font-size:12.5px;font-weight:600;color:var(--ink-2)}
.area-row.off .area-name{color:var(--muted-2);font-weight:500}
.tag{font-size:10.5px;font-weight:700;padding:3px 8px;border-radius:5px;text-align:center}
.t-total{background:#E7F6EC;color:#15803D}.t-edit{background:#FEF0DA;color:#B45309}
.t-view{background:#EEF2F5;color:#475569}.t-off{background:#F5F5F5;color:#B0B5BC}
.count{font-size:11.5px;color:var(--muted);text-align:right}
.facts{display:flex;flex-direction:column;gap:9px}
.fact{display:flex;justify-content:space-between;gap:12px;font-size:12.5px;border-bottom:1px dashed var(--line);padding-bottom:8px}
.fact span:first-child{color:var(--muted)}
.fact span:last-child{color:var(--ink-2);font-weight:600;text-align:right}
.people{display:flex;flex-wrap:wrap;gap:7px}
.person{background:#fff;border:1px solid var(--line);border-radius:7px;padding:6px 10px;font-size:12px;color:var(--ink-2)}
.person span{color:var(--muted-2);font-size:11px}

.card-foot{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:14px 18px;border-top:1px solid var(--line-soft);margin-top:6px}
.foot-left{display:flex;align-items:center;gap:9px;font-size:13px;color:var(--muted)}
.foot-left select{padding:6px 9px;border:1px solid var(--line);border-radius:7px;font-family:inherit;font-size:13px;color:var(--ink-2)}
.pager{display:flex;gap:6px}
.pg{min-width:31px;height:31px;padding:0 9px;border:1px solid var(--line);border-radius:7px;background:#fff;color:var(--muted);font-size:13px;display:grid;place-items:center}
.pg:hover:not(:disabled):not(.is-active){border-color:#CBD5E1;color:var(--ink-2)}
.pg.is-active{background:var(--orange);border-color:var(--orange);color:#fff;font-weight:700}
.pg:disabled{opacity:.45;cursor:not-allowed}
.empty{padding:46px 20px;text-align:center;color:var(--muted)}
.empty strong{display:block;color:var(--ink-2);font-size:14.5px;margin-bottom:5px}

.overlay{position:fixed;inset:0;background:rgba(17,24,39,.45);display:none;align-items:center;justify-content:center;padding:20px 18px;overflow-y:auto;z-index:50}
.overlay.open{display:flex}
.modal{background:#fff;border-radius:12px;width:100%;max-width:720px;box-shadow:0 18px 48px rgba(0,0,0,.22);display:flex;flex-direction:column;max-height:calc(100vh - 40px);flex-shrink:0;overflow:hidden}
.modal.sm{max-width:560px}
.modal-head{padding:18px 22px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:flex-start;gap:14px;background:#fff;flex-shrink:0}
.modal-head h2{font-size:17px;margin-bottom:3px}
.modal-head p{font-size:12.5px;color:var(--muted)}
.x{font-size:20px;color:var(--muted);padding:2px 7px;border-radius:6px;background:none;border:none;cursor:pointer}
.x:hover{background:var(--line-soft);color:var(--ink)}
.modal-body{padding:20px 22px;display:flex;flex-direction:column;gap:17px;overflow-y:auto;flex:1}
.field{display:flex;flex-direction:column;gap:6px}
.field label{font-size:11px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:var(--muted)}
.field input,.field select,.field textarea{padding:10px 12px;border:1px solid var(--line);border-radius:8px;font-family:inherit;font-size:13.5px;color:var(--ink);background:#fff}
.field textarea{resize:vertical;min-height:62px}
.field input:focus,.field select:focus,.field textarea:focus{outline:none;border-color:var(--orange);box-shadow:0 0 0 3px rgba(224,149,31,.15)}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}
.turno-pick{display:flex;gap:9px;flex-wrap:wrap}
.turno-pick label{display:flex;align-items:center;gap:7px;border:1px solid var(--line);border-radius:8px;padding:9px 13px;font-size:13px;color:var(--ink-2);cursor:pointer}
.turno-pick label:has(input:checked){border-color:var(--green);background:#F2FBF5;color:#15803D;font-weight:600}
.turno-pick input{accent-color:var(--green);width:15px;height:15px}

.access{border:1px solid var(--line);border-radius:10px;overflow:hidden}
.access-top{padding:14px 15px;background:#FAFBFC;border-bottom:1px solid var(--line);display:flex;flex-direction:column;gap:11px}
.access-line{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.access-note{font-size:12px;color:var(--muted)}
.link{font-size:12px;font-weight:700;color:var(--orange-dark);text-decoration:underline;text-underline-offset:2px;background:none;border:none;cursor:pointer}
.link:hover{color:var(--orange)}
.mini-search{width:100%;padding:8px 11px;border:1px solid var(--line);border-radius:7px;font-size:13px;font-family:inherit}
.mini-search:focus{outline:none;border-color:var(--orange)}
.groups{max-height:280px;overflow-y:auto}
.group{border-bottom:1px solid var(--line-soft)}.group:last-child{border-bottom:none}
.g-head{width:100%;display:grid;grid-template-columns:18px 1fr auto auto;align-items:center;gap:10px;padding:11px 15px;text-align:left;background:none;border:none;}
.g-head:hover{background:#FAFBFC}
.chev{color:var(--muted-2);font-size:11px;transition:transform .15s}
.group.open .chev{transform:rotate(90deg)}
.g-name{font-size:13.5px;font-weight:600;color:var(--ink)}
.g-sum{font-size:11.5px;color:var(--muted)}
.g-select{padding:5px 8px;border:1px solid var(--line);border-radius:6px;font-size:11.5px;font-family:inherit;color:var(--ink-2);background:#fff}
.g-body{display:none;padding:2px 15px 12px 43px}
.group.open .g-body{display:block}
.tela{display:grid;grid-template-columns:1fr 132px;align-items:center;gap:12px;padding:6px 0}
.tela-name{font-size:12.5px;color:var(--ink-2)}
.lv{width:100%;padding:5px 8px;border-radius:6px;font-size:11.5px;font-weight:700;font-family:inherit;border:1px solid transparent;cursor:pointer}
.lv.v-total{background:#E7F6EC;color:#15803D}.lv.v-edit{background:#FEF0DA;color:#B45309}
.lv.v-view{background:#EEF2F5;color:#475569}.lv.v-off{background:#F5F5F5;color:#8E959E}
.access-foot{padding:11px 15px;background:#FAFBFC;border-top:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;font-size:12.5px;color:var(--muted)}
.access-foot b{color:var(--ink-2)}
.no-hit{padding:16px;text-align:center;color:var(--muted-2);font-size:12.5px}
.warn{background:#FEF6E7;border:1px solid #F5D9A3;color:#8A5A08;border-radius:8px;padding:10px 13px;font-size:12.5px}
.modal-foot{padding:15px 22px;border-top:1px solid var(--line);display:flex;justify-content:space-between;gap:12px;align-items:center;background:#FAFBFC;flex-shrink:0}
.hint{font-size:12px;color:var(--muted)}
.toast{position:fixed;bottom:24px;right:24px;background:var(--sidebar);color:#fff;padding:13px 18px;border-radius:9px;font-size:13.5px;box-shadow:0 10px 26px rgba(0,0,0,.25);transform:translateY(16px);opacity:0;pointer-events:none;transition:.22s;z-index:60}
.toast.show{transform:none;opacity:1}
</style>

<div class="content">
  <div class="mb-4">
    <h1 class="text-2xl font-bold tracking-tight">Perfis de Acesso e Setores</h1>
    <p class="subtitle">Cadastros que alimentam a tela de usuários: o perfil define o que a pessoa vê, o setor define onde ela trabalha</p>
  </div>

  <div class="pills" id="pills">
    <button class="pill is-active" data-v="perfis">Perfis de acesso</button>
    <button class="pill" data-v="setores">Setores</button>
  </div>

  <section class="card">
    <div class="card-head">
      <div class="card-title"><span class="ico">◈</span> <span id="cardTitle">Perfis de acesso</span></div>
      <button class="btn-new" id="btnNew">＋ Novo perfil</button>
    </div>
    <div class="card-body">
      <input class="search" id="q" type="search" placeholder="Buscar perfil, código, descrição...">
      <div class="filters">
        <select class="f" id="f1"></select>
        <select class="f" id="f2"></select>
        <button class="btn-filter" id="btnFilter">Filtrar</button>
        <div class="toggles">
          <span class="lbl">Mostrar:</span>
          <label class="chk"><input type="checkbox" id="cAtivos" checked> Ativos</label>
          <label class="chk"><input type="checkbox" id="cInativos" checked> Inativos</label>
        </div>
      </div>
      <div class="table-wrap"><table><thead id="thead"></thead><tbody id="tbody"></tbody></table></div>
    </div>
    <div class="card-foot">
      <div class="foot-left">
        <span>Exibir</span>
        <select id="perPage"><option>10</option><option>25</option><option>50</option></select>
        <span id="footInfo">por página</span>
      </div>
      <div class="pager" id="pager"></div>
    </div>
  </section>
</div>

<!-- modal perfil -->
<div class="overlay" id="ovPerfil">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="pTitle">
    <div class="modal-head">
      <div><h2 id="pTitle">Novo perfil</h2><p id="pSub">Defina o que este perfil enxerga em cada tela do sistema</p></div>
      <button class="x" data-close="ovPerfil" aria-label="Fechar">✕</button>
    </div>
    <div class="modal-body">
      <div id="pLock" class="warn" style="display:none">Perfil do sistema: o nome e o nível são fixos, mas as permissões podem ser ajustadas.</div>
      <div class="grid-3">
        <div class="field" style="grid-column:span 2"><label for="pNome">Nome do perfil</label><input id="pNome" type="text" placeholder="Ex.: Gestor de Retrabalho"></div>
        <div class="field"><label for="pCod">Código</label><input id="pCod" type="text" placeholder="GES-RET" class="mono"></div>
      </div>
      <div class="grid-2">
        <div class="field"><label for="pGrupo">Nível hierárquico</label><select id="pGrupo"></select></div>
        <div class="field"><label for="pStatus">Status</label><select id="pStatus"><option value="ativo">Ativo</option><option value="inativo">Inativo</option></select></div>
      </div>
      <div class="field"><label for="pDesc">Descrição</label><textarea id="pDesc" placeholder="Para quem serve este perfil e o que ele pode fazer"></textarea></div>
      <div class="field" id="pBaseWrap"><label for="pBase">Copiar permissões de</label>
        <select id="pBase"><option value="">Começar do zero (sem acesso)</option></select></div>

      <div class="access">
        <div class="access-top">
          <div class="access-line">
            <span class="access-note" id="pNote"></span>
            <input class="mini-search" id="pFind" type="search" placeholder="Buscar módulo ou tela..." style="max-width:230px">
          </div>
        </div>
        <div class="groups" id="pGroups"></div>
        <div class="access-foot"><span id="pCount"></span><button class="link" id="pToggleAll">Expandir tudo</button></div>
      </div>
    </div>
    <div class="modal-foot">
      <span class="hint" id="pHint">Usuários vinculados recebem a mudança no próximo login.</span>
      <div style="display:flex;gap:10px">
        <button class="act-ghost" data-close="ovPerfil">Cancelar</button>
        <button class="act act-green" id="pSave" data-loading-text="Salvando...">✓ Salvar perfil</button>
      </div>
    </div>
  </div>
</div>

<!-- modal setor -->
<div class="overlay" id="ovSetor">
  <div class="modal sm" role="dialog" aria-modal="true" aria-labelledby="sTitle">
    <div class="modal-head">
      <div><h2 id="sTitle">Novo setor</h2><p id="sSub">O setor organiza os usuários e sugere um perfil no cadastro</p></div>
      <button class="x" data-close="ovSetor" aria-label="Fechar">✕</button>
    </div>
    <div class="modal-body">
      <div class="grid-3">
        <div class="field" style="grid-column:span 2"><label for="sNome">Nome do setor</label><input id="sNome" type="text" placeholder="Ex.: Inspeção Final"></div>
        <div class="field"><label for="sCod">Sigla</label><input id="sCod" type="text" placeholder="IQF" class="mono"></div>
      </div>
      <div class="grid-2">
        <div class="field"><label for="sResp">Responsável</label><input id="sResp" type="text" placeholder="Ex.: Sônia Ribeiro"></div>
        <div class="field"><label for="sCC">Centro de custo</label><input id="sCC" type="text" placeholder="3120" class="mono"></div>
      </div>
      <div class="field"><label for="sPerfil">Perfil sugerido no cadastro</label><select id="sPerfil"></select></div>
      <div class="field"><label>Turnos que operam</label>
        <div class="turno-pick">
          <label><input type="checkbox" value="1" class="sTurno"> 1º turno</label>
          <label><input type="checkbox" value="2" class="sTurno"> 2º turno</label>
          <label><input type="checkbox" value="3" class="sTurno"> 3º turno</label>
          <label><input type="checkbox" value="A" class="sTurno"> Administrativo</label>
        </div>
      </div>
      <div class="field"><label for="sStatus">Status</label><select id="sStatus"><option value="ativo">Ativo</option><option value="inativo">Inativo</option></select></div>
      <div class="warn" id="sWarn" style="display:none"></div>
    </div>
    <div class="modal-foot">
      <span class="hint">Setores inativos deixam de aparecer no cadastro de usuários.</span>
      <div style="display:flex;gap:10px">
        <button class="act-ghost" data-close="ovSetor">Cancelar</button>
        <button class="act act-green" id="sSave" data-loading-text="Salvando...">✓ Salvar setor</button>
      </div>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
window.__APP_BASE = '<?= APP_URL ?>';

/* ===== Injeção de Dados do Backend ===== */
const perfis = <?= json_encode($perfis, JSON_UNESCAPED_UNICODE) ?>;
const setores = <?= json_encode($setores, JSON_UNESCAPED_UNICODE) ?>;

/* ===== estrutura do sistema ===== */
const AREAS=[
 {id:'fab',nome:'Fábrica',telas:[['fab.home','Home']]},
 {id:'lab',nome:'Laboratório',telas:[['lab.reg','Registro de Reprova'],['lab.lis','Lista'],['lab.ret','Retornos']]},
 {id:'iqf',nome:'Inspeção Final',telas:[['iqf.reg','Registro de Reprova'],['iqf.lis','Lista'],['iqf.ret','Retornos']]},
 {id:'ret',nome:'Retrabalho',telas:[['ret.dash','Dashboard'],['ret.pan','Retrabalho'],['ret.rel','Relação de Retrabalhos'],['ret.pri','Prioridade']]},
 {id:'qua',nome:'Qualidade',telas:[['qua.tip','Tipos de Reprova']]},
 {id:'pin',nome:'Pintura',telas:[['pin.pai','Paint Check (Robô)'],['pin.ret','Relação de Retrabalhos']]},
 {id:'ana',nome:'Análise',telas:[['ana.aco','Acompanhamento'],['ana.his','Histórico']]},
 {id:'adm',nome:'Administração',telas:[['adm.usu','Usuários'],['adm.per','Perfis e setores']]}
];
const TELAS=AREAS.flatMap(a=>a.telas.map(t=>({id:t[0],nome:t[1],area:a.id})));
const TOTAL=TELAS.length;
const NIVEIS={off:'Sem acesso',view:'Consulta',edit:'Edição',total:'Total'};
const RANK={off:0,view:1,edit:2,total:3};
const GRUPOS={ADM:'Administrador',GES:'Gestor',SUP:'Supervisor',OPE:'Operador',VIS:'Visualizador'};
const TURNO_LB={'1':'1º','2':'2º','3':'3º','A':'Adm'};
function byArea(m,pad='off'){const o={};TELAS.forEach(t=>o[t.id]=m[t.area]||pad);return o}

/* ===== estado ===== */
let view='perfis',page=1,perPage=10,expanded=new Set();
let editP=null,dPerms={},openG=new Set(),findTxt='',editS=null;
const $=s=>document.querySelector(s);
const esc=s=>String(s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
function toast(m){const t=$('#toast');t.textContent=m;t.classList.add('show');clearTimeout(t._t);t._t=setTimeout(()=>t.classList.remove('show'),2600)}
const liberadas=p=>TELAS.filter(t=>p[t.id]!=='off').length;
function resumoArea(p,a){const on=a.telas.filter(t=>p[t[0]]!=='off');
 return{on:on.length,tot:a.telas.length,nivel:on.reduce((m,t)=>RANK[p[t[0]]]>RANK[m]?p[t[0]]:m,'off')}}

/* ===== filtros por visão ===== */
function setupFilters(){
 if(view==='perfis'){
  $('#f1').innerHTML='<option value="">Nível...</option>'+Object.entries(GRUPOS).map(([k,v])=>`<option value="${k}">${v}</option>`).join('');
  $('#f2').innerHTML='<option value="">Área liberada...</option>'+AREAS.map(a=>`<option value="${a.id}">${a.nome}</option>`).join('');
  $('#q').placeholder='Buscar perfil, código, descrição...';
  $('#cardTitle').textContent='Perfis de acesso';$('#btnNew').textContent='＋ Novo perfil';
 }else{
  $('#f1').innerHTML='<option value="">Turno...</option>'+Object.entries(TURNO_LB).map(([k,v])=>`<option value="${k}">${v} turno</option>`).join('');
  $('#f2').innerHTML='<option value="">Perfil sugerido...</option>'+perfis.map(p=>`<option value="${p.cod}">${p.nome}</option>`).join('');
  $('#q').placeholder='Buscar setor, sigla, responsável...';
  $('#cardTitle').textContent='Setores';$('#btnNew').textContent='＋ Novo setor';
 }
}
function filtered(){
 const q=$('#q').value.trim().toLowerCase(),v1=$('#f1').value,v2=$('#f2').value;
 const A=$('#cAtivos').checked,I=$('#cInativos').checked;
 const src=view==='perfis'?perfis:setores;
 return src.filter(r=>{
  if(r.status==='ativo'&&!A)return false;
  if(r.status==='inativo'&&!I)return false;
  if(view==='perfis'){
   if(v1&&r.grupo!==v1)return false;
   if(v2&&!AREAS.find(a=>a.id===v2).telas.some(t=>r.perms[t[0]]!=='off'))return false;
   if(q&&![r.nome,r.cod,r.desc,GRUPOS[r.grupo]].join(' ').toLowerCase().includes(q))return false;
  }else{
   if(v1&&!r.turnos.includes(v1))return false;
   if(v2&&r.perfil!==v2)return false;
   if(q&&![r.nome,r.cod,r.resp,r.cc].join(' ').toLowerCase().includes(q))return false;
  }
  return true;
 });
}

/* ===== tabela ===== */
function render(){
 const list=filtered(),pages=Math.max(1,Math.ceil(list.length/perPage));
 if(page>pages)page=pages;
 const slice=list.slice((page-1)*perPage,page*perPage);

 $('#thead').innerHTML=view==='perfis'
 ? `<tr><th style="width:44px"></th><th>Nível</th><th>#</th><th>Código</th><th class="l">Perfil</th>
    <th>Usuários</th><th>Acesso</th><th>Status</th><th class="r">Ações</th></tr>`
 : `<tr><th style="width:44px"></th><th>Sigla</th><th>#</th><th class="l">Setor</th><th class="l">Responsável</th>
    <th>Centro de custo</th><th>Turnos</th><th class="l">Perfil sugerido</th><th>Usuários</th><th>Status</th><th class="r">Ações</th></tr>`;

 const cols=view==='perfis'?9:11;
 if(!list.length){
  $('#tbody').innerHTML=`<tr><td colspan="${cols}"><div class="empty"><strong>Nenhum registro encontrado</strong>Ajuste a busca ou os filtros para ver outros resultados.</div></td></tr>`;
 }else if(view==='perfis'){
  $('#tbody').innerHTML=slice.map((p,i)=>{
   const lib=liberadas(p.perms),pct=Math.round(lib/TOTAL*100),open=expanded.has(p.id);
   const cls=pct>85?'':pct>40?'partial':'low';
   const main=`<tr class="row">
    <td><button class="expander" data-exp="${p.id}">${open?'−':'+'}</button></td>
    <td><span class="badge b-${p.grupo}">${GRUPOS[p.grupo]}</span></td>
    <td>${(page-1)*perPage+i+1}º</td>
    <td class="mono">${p.cod}</td>
    <td class="l"><div class="cell"><b>${esc(p.nome)}${p.sistema?' <span class="lock">SISTEMA</span>':''}</b><span>${esc(p.desc)}</span></div></td>
    <td><span class="users-pill ${p.usuarios?'':'zero'}">${p.usuarios} usuário${p.usuarios===1?'':'s'}</span></td>
    <td><span class="meter"><span class="meter-top"><b>${lib}</b>/${TOTAL} telas</span>
      <span class="meter-bar ${cls}"><i style="width:${pct}%"></i></span></span></td>
    <td><span class="badge s-${p.status}">${p.status==='ativo'?'Ativo':'Inativo'}</span></td>
    <td class="r"><span class="act-group">
      <button class="act-ghost" data-dup="${p.id}">Duplicar</button>
      <button class="act act-green" data-editp="${p.id}">✓ Editar permissões</button></span></td></tr>`;
   if(!open)return main;
   const areas=AREAS.map(a=>{const r=resumoArea(p.perms,a);
    return `<div class="area-row ${r.on?'':'off'}"><span class="area-name">${a.nome}</span>
      <span class="tag t-${r.nivel}">${NIVEIS[r.nivel]}</span><span class="count">${r.on} de ${r.tot}</span></div>`}).join('');
   const setoresQueUsam=setores.filter(s=>s.perfil===p.cod).map(s=>s.nome).join(', ')||'nenhum';
   return main+`<tr class="detail"><td colspan="${cols}"><div class="detail-inner">
    <div><div class="detail-h">Permissões por área</div><div class="area-list">${areas}</div></div>
    <div><div class="detail-h">Dados do perfil</div><div class="facts">
      <div class="fact"><span>Código</span><span class="mono">${p.cod}</span></div>
      <div class="fact"><span>Nível</span><span>${GRUPOS[p.grupo]}</span></div>
      <div class="fact"><span>Telas liberadas</span><span>${lib} de ${TOTAL}</span></div>
      <div class="fact"><span>Usuários vinculados</span><span>${p.usuarios}</span></div>
      <div class="fact"><span>Sugerido nos setores</span><span>${setoresQueUsam}</span></div>
      <div class="fact"><span>Criado em</span><span>${p.criado}</span></div>
    </div></div></div></td></tr>`;
  }).join('');
 }else{
  $('#tbody').innerHTML=slice.map((s,i)=>{
   const open=expanded.has(s.id),per=perfis.find(p=>p.cod===s.perfil);
   const turnos=Object.keys(TURNO_LB).map(k=>`<span class="turno ${s.turnos.includes(k)?'on':''}" title="${TURNO_LB[k]}">${TURNO_LB[k]}</span>`).join('');
   const main=`<tr class="row">
    <td><button class="expander" data-exp="${s.id}">${open?'−':'+'}</button></td>
    <td class="mono">${s.cod}</td>
    <td>${(page-1)*perPage+i+1}º</td>
    <td class="l"><div class="cell"><b>${esc(s.nome)}</b><span>${s.usuarios} pessoa${s.usuarios===1?'':'s'} vinculada${s.usuarios===1?'':'s'}</span></div></td>
    <td class="l">${esc(s.resp)}</td>
    <td class="mono">${s.cc}</td>
    <td><span class="turnos">${turnos}</span></td>
    <td class="l">${per?`<span class="badge b-${per.grupo}">${per.nome}</span>`:'—'}</td>
    <td><span class="users-pill ${s.usuarios?'':'zero'}">${s.usuarios}</span></td>
    <td><span class="badge s-${s.status}">${s.status==='ativo'?'Ativo':'Inativo'}</span></td>
    <td class="r"><span class="act-group">
      <button class="act-ghost" data-del="${s.id}" ${s.usuarios?'disabled title="Setor com usuários vinculados"':''}>Excluir</button>
      <button class="act act-green" data-edits="${s.id}">✓ Editar setor</button></span></td></tr>`;
   if(!open)return main;
   return main+`<tr class="detail"><td colspan="${cols}"><div class="detail-inner">
    <div><div class="detail-h">Perfil sugerido no cadastro de usuários</div>
      <div class="area-list">${per?AREAS.map(a=>{const r=resumoArea(per.perms,a);
        return `<div class="area-row ${r.on?'':'off'}"><span class="area-name">${a.nome}</span>
        <span class="tag t-${r.nivel}">${NIVEIS[r.nivel]}</span><span class="count">${r.on} de ${r.tot}</span></div>`}).join('')
      :'<div class="no-hit">Nenhum perfil sugerido para este setor.</div>'}</div></div>
    <div><div class="detail-h">Dados do setor</div><div class="facts">
      <div class="fact"><span>Sigla</span><span class="mono">${s.cod}</span></div>
      <div class="fact"><span>Centro de custo</span><span class="mono">${s.cc}</span></div>
      <div class="fact"><span>Responsável</span><span>${esc(s.resp)}</span></div>
      <div class="fact"><span>Turnos</span><span>${s.turnos.map(t=>TURNO_LB[t]).join(', ')||'—'}</span></div>
      <div class="fact"><span>Usuários</span><span>${s.usuarios}</span></div>
      <div class="fact"><span>Criado em</span><span>${s.criado}</span></div>
    </div></div></div></td></tr>`;
  }).join('');
 }

 const info=view==='perfis'
  ? `por página · ${list.length} perfis · ${list.reduce((a,p)=>a+p.usuarios,0)} usuários vinculados`
  : `por página · ${list.length} setores · ${list.reduce((a,s)=>a+s.usuarios,0)} usuários vinculados`;
 $('#footInfo').textContent=info;

 const pg=[`<button class="pg" data-pg="1" ${page===1?'disabled':''}>«</button>`,`<button class="pg" data-pg="${page-1}" ${page===1?'disabled':''}>‹</button>`];
 for(let p=1;p<=pages;p++)pg.push(`<button class="pg ${p===page?'is-active':''}" data-pg="${p}">${p}</button>`);
 pg.push(`<button class="pg" data-pg="${page+1}" ${page===pages?'disabled':''}>›</button>`,`<button class="pg" data-pg="${pages}" ${page===pages?'disabled':''}>»</button>`);
 $('#pager').innerHTML=pg.join('');
}

/* ===== modal perfil ===== */
function openPerfil(p,dup){
 editP=p&&!dup?p.id:null;
 $('#pTitle').textContent=p?(dup?'Duplicar perfil':'Editar perfil'):'Novo perfil';
 $('#pSub').textContent=p&&!dup?`${p.nome} · ${p.usuarios} usuário(s) vinculado(s)`:'Defina o que este perfil enxerga em cada tela do sistema';
 $('#pNome').value=p?(dup?p.nome+' (cópia)':p.nome):'';
 $('#pCod').value=p?(dup?'':p.cod):'';
 $('#pGrupo').value=p?p.grupo:'OPE';
 $('#pStatus').value=p&&!dup?p.status:'ativo';
 $('#pDesc').value=p?p.desc:'';
 const trava=!!(p&&p.sistema&&!dup);
 $('#pLock').style.display=trava?'':'none';
 $('#pNome').disabled=trava;$('#pGrupo').disabled=trava;$('#pCod').disabled=trava;
 $('#pBaseWrap').style.display=p?'none':'';
 $('#pBase').value='';
 dPerms=p?{...p.perms}:byArea({},'off');
 openG=new Set();findTxt='';$('#pFind').value='';
 renderAccess();$('#ovPerfil').classList.add('open');setTimeout(()=>$('#pNome').focus(),40);
}
function renderAccess(){
 const f=findTxt.trim().toLowerCase();
 const html=AREAS.map(a=>{
  const telas=a.telas.filter(t=>!f||t[1].toLowerCase().includes(f)||a.nome.toLowerCase().includes(f));
  if(!telas.length)return'';
  const r=resumoArea(dPerms,a),isOpen=openG.has(a.id)||!!f;
  const rows=telas.map(t=>`<div class="tela"><span class="tela-name">${t[1]}</span>
    <select class="lv v-${dPerms[t[0]]}" data-tela="${t[0]}">${Object.keys(NIVEIS).map(k=>`<option value="${k}" ${k===dPerms[t[0]]?'selected':''}>${NIVEIS[k]}</option>`).join('')}</select></div>`).join('');
  return `<div class="group ${isOpen?'open':''}">
    <button class="g-head" type="button" data-toggle="${a.id}"><span class="chev">▶</span>
      <span class="g-name">${a.nome}</span><span class="g-sum">${r.on} de ${r.tot} · ${NIVEIS[r.nivel]}</span>
      <select class="g-select" data-area="${a.id}"><option value="">Definir área...</option>${Object.keys(NIVEIS).map(k=>`<option value="${k}">${NIVEIS[k]} em tudo</option>`).join('')}</select>
    </button><div class="g-body">${rows}</div></div>`;
 }).join('');
 $('#pGroups').innerHTML=html||'<div class="no-hit">Nenhuma tela corresponde à busca.</div>';
 const lib=liberadas(dPerms);
 $('#pCount').innerHTML=`<b>${lib}</b> de ${TOTAL} telas liberadas`;
 $('#pNote').textContent=lib?`Este perfil abre ${lib} tela${lib>1?'s':''} do sistema`:'Nenhuma tela liberada até aqui';
 $('#pToggleAll').textContent=openG.size===AREAS.length?'Recolher tudo':'Expandir tudo';
}

/* ===== modal setor ===== */
function openSetor(s){
 editS=s?s.id:null;
 $('#sTitle').textContent=s?'Editar setor':'Novo setor';
 $('#sSub').textContent=s?`${s.nome} · ${s.usuarios} usuário(s)`:'O setor organiza os usuários e sugere um perfil no cadastro';
 $('#sNome').value=s?s.nome:'';$('#sCod').value=s?s.cod:'';$('#sResp').value=s?s.resp:'';
 $('#sCC').value=s?s.cc:'';$('#sPerfil').value=s?s.perfil:(perfis[0]?.cod||'');$('#sStatus').value=s?s.status:'ativo';
 document.querySelectorAll('.sTurno').forEach(c=>c.checked=s?s.turnos.includes(c.value):false);
 const w=$('#sWarn');
 if(s&&s.usuarios){w.style.display='';w.textContent=`${s.usuarios} usuário(s) estão neste setor. Ao inativá-lo, eles continuam ativos, mas o setor deixa de aparecer em novos cadastros.`}
 else w.style.display='none';
 $('#ovSetor').classList.add('open');setTimeout(()=>$('#sNome').focus(),40);
}
const close=id=>$('#'+id).classList.remove('open');

/* ===== eventos ===== */
$('#pGrupo').innerHTML=Object.entries(GRUPOS).map(([k,v])=>`<option value="${k}">${v}</option>`).join('');
function refreshSelects(){
 $('#sPerfil').innerHTML=perfis.filter(p=>p.status==='ativo').map(p=>`<option value="${p.cod}">${p.nome}</option>`).join('');
 $('#pBase').innerHTML='<option value="">Começar do zero (sem acesso)</option>'+perfis.map(p=>`<option value="${p.cod}">${p.nome}</option>`).join('');
}
refreshSelects();setupFilters();

$('#pills').addEventListener('click',e=>{const b=e.target.closest('.pill');if(!b)return;
 document.querySelectorAll('.pill').forEach(p=>p.classList.remove('is-active'));b.classList.add('is-active');
 view=b.dataset.v;page=1;expanded.clear();$('#q').value='';setupFilters();render()});
$('#q').addEventListener('input',()=>{page=1;render()});
$('#btnFilter').addEventListener('click',()=>{page=1;render();toast('Filtros aplicados')});
['f1','f2','cAtivos','cInativos'].forEach(id=>$('#'+id).addEventListener('change',()=>{page=1;render()}));
$('#perPage').addEventListener('change',e=>{perPage=+e.target.value;page=1;render()});
$('#pager').addEventListener('click',e=>{const b=e.target.closest('[data-pg]');if(!b||b.disabled)return;
 page=+b.dataset.pg;render();window.scrollTo({top:0,behavior:'smooth'})});
$('#btnNew').addEventListener('click',()=>view==='perfis'?openPerfil(null):openSetor(null));

$('#tbody').addEventListener('click',e=>{
 const x=e.target.closest('[data-exp]');
 if(x){const id=+x.dataset.exp;expanded.has(id)?expanded.delete(id):expanded.add(id);render();return}
 const ep=e.target.closest('[data-editp]');if(ep)return openPerfil(perfis.find(p=>p.id===+ep.dataset.editp));
 const du=e.target.closest('[data-dup]');if(du)return openPerfil(perfis.find(p=>p.id===+du.dataset.dup),true);
 const es=e.target.closest('[data-edits]');if(es)return openSetor(setores.find(s=>s.id===+es.dataset.edits));
 const de=e.target.closest('[data-del]');
 if(de&&!de.disabled){
  const s=setores.find(x=>x.id===+de.dataset.del);
  if(!confirm(`Deseja realmente excluir o setor ${s.nome}?`)) return;
  fetch(window.__APP_BASE+'/api/admin-v2-acao.php',{
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:new URLSearchParams({acao:'excluir_setor',id:s.id})
  }).then(r=>r.json()).then(res=>{
      if(res.sucesso){
          setores.splice(setores.indexOf(s),1);refreshSelects();render();toast(`Setor ${s.nome} excluído`);
      } else toast(res.erro||'Erro ao excluir setor');
  }).catch(()=>toast('Erro de rede'));
 }});

document.addEventListener('click',e=>{const c=e.target.closest('[data-close]');if(c)close(c.dataset.close)});
document.querySelectorAll('.overlay').forEach(o=>o.addEventListener('click',e=>{if(e.target===o)o.classList.remove('open')}));
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.overlay.open').forEach(o=>o.classList.remove('open'))});

$('#pFind').addEventListener('input',e=>{findTxt=e.target.value;renderAccess()});
$('#pToggleAll').addEventListener('click',()=>{openG.size===AREAS.length?openG.clear():AREAS.forEach(a=>openG.add(a.id));renderAccess()});
$('#pBase').addEventListener('change',e=>{const src=perfis.find(p=>p.cod===e.target.value);
 dPerms=src?{...src.perms}:byArea({},'off');renderAccess();
 if(src)toast(`Permissões copiadas de ${src.nome}`)});
$('#pGroups').addEventListener('click',e=>{const t=e.target.closest('[data-toggle]');
 if(t&&!e.target.closest('.g-select')){const id=t.dataset.toggle;openG.has(id)?openG.delete(id):openG.add(id);renderAccess()}});
$('#pGroups').addEventListener('change',e=>{
 const tela=e.target.closest('[data-tela]');if(tela){dPerms[tela.dataset.tela]=tela.value;renderAccess();return}
 const area=e.target.closest('[data-area]');
 if(area&&area.value){AREAS.find(a=>a.id===area.dataset.area).telas.forEach(t=>dPerms[t[0]]=area.value);
  openG.add(area.dataset.area);renderAccess()}});

$('#pSave').addEventListener('click',()=>{
 const nome=$('#pNome').value.trim(),cod=$('#pCod').value.trim().toUpperCase();
 if(!nome||!cod){toast('Informe nome e código do perfil');return}
 if(perfis.some(p=>p.cod===cod&&p.id!==editP)){toast(`O código ${cod} já está em uso`);return}
 
 const btn = $('#pSave');
 const oTxt = btn.textContent;
 btn.textContent = btn.dataset.loadingText;
 btn.disabled = true;

 const dados={acao:'salvar_perfil', id:editP||'', nome, cod, grupo:$('#pGrupo').value, desc:$('#pDesc').value.trim()||'Sem descrição.', status:$('#pStatus').value, perms:JSON.stringify(dPerms)};
 
 fetch(window.__APP_BASE+'/api/admin-v2-acao.php',{
     method:'POST',
     headers:{'Content-Type':'application/x-www-form-urlencoded'},
     body:new URLSearchParams(dados)
 }).then(r=>r.json()).then(res=>{
     if(res.sucesso){
         const nDados = {nome,cod,grupo:dados.grupo,desc:dados.desc,status:dados.status,perms:{...dPerms}};
         if(editP){
             const p=perfis.find(x=>x.id===editP);Object.assign(p,nDados);
             toast(p.usuarios?`Perfil atualizado — ${p.usuarios} usuário(s) afetado(s)`:'Perfil atualizado');
         }else{
             perfis.push({id:res.id,sistema:false,usuarios:0,criado:res.criado,...nDados});
             toast(`Perfil ${nome} criado com ${liberadas(dPerms)} telas`);
         }
         refreshSelects();setupFilters();close('ovPerfil');render();
     } else {
         toast(res.erro||'Erro ao salvar perfil');
     }
 }).catch(()=>toast('Erro de rede')).finally(()=>{ btn.textContent=oTxt; btn.disabled=false; });
});

$('#sSave').addEventListener('click',()=>{
 const nome=$('#sNome').value.trim(),cod=$('#sCod').value.trim().toUpperCase();
 if(!nome||!cod){toast('Informe nome e sigla do setor');return}
 if(setores.some(s=>s.cod===cod&&s.id!==editS)){toast(`A sigla ${cod} já está em uso`);return}
 const turnos=[...document.querySelectorAll('.sTurno:checked')].map(c=>c.value);
 if(!turnos.length){toast('Selecione ao menos um turno');return}
 
 const btn = $('#sSave');
 const oTxt = btn.textContent;
 btn.textContent = btn.dataset.loadingText;
 btn.disabled = true;

 const dados={acao:'salvar_setor', id:editS||'', nome, cod, resp:$('#sResp').value.trim()||'—', cc:$('#sCC').value.trim()||'—', turnos:JSON.stringify(turnos), perfil:$('#sPerfil').value, status:$('#sStatus').value};
 
 fetch(window.__APP_BASE+'/api/admin-v2-acao.php',{
     method:'POST',
     headers:{'Content-Type':'application/x-www-form-urlencoded'},
     body:new URLSearchParams(dados)
 }).then(r=>r.json()).then(res=>{
     if(res.sucesso){
         const nDados = {nome,cod,resp:dados.resp,cc:dados.cc,turnos,perfil:dados.perfil,status:dados.status};
         if(editS){
             Object.assign(setores.find(s=>s.id===editS),nDados);
             toast(`Setor ${nome} atualizado`);
         }else{
             setores.push({id:res.id,usuarios:0,criado:res.criado,...nDados});
             toast(`Setor ${nome} criado`);
         }
         setupFilters();close('ovSetor');render();
     } else {
         toast(res.erro||'Erro ao salvar setor');
     }
 }).catch(()=>toast('Erro de rede')).finally(()=>{ btn.textContent=oTxt; btn.disabled=false; });
});

render();
</script>

<?php layoutFooter(); ?>
