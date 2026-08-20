<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/layout.php';

requireAcessoModulo('admin');

$pdo = getDB();

// Carregar Perfis (cod => dados)
$perfisDB = $pdo->query("SELECT id, cod, nome, grupo, descricao, status, sistema, perms FROM perfis ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
$PERFIS = [];
foreach ($perfisDB as $p) {
    $p['perms'] = json_decode((string)$p['perms'], true) ?: [];
    $PERFIS[$p['cod']] = $p;
}

// Carregar Setores
$setoresDB = $pdo->query("SELECT id, nome FROM setores WHERE status = 'ativo' ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
$SETORES = $setoresDB;

// Carregar Usuários
$usersDB = $pdo->query("
    SELECT u.id, u.nome, u.email, u.matricula as mat, u.status, u.id_setor,
           DATE_FORMAT(u.created_at, '%d/%m/%Y') as criado,
           p.cod as perfil, s.nome as setor
    FROM usuarios u
    LEFT JOIN perfis p ON u.id_perfil = p.id
    LEFT JOIN setores s ON u.id_setor = s.id
    WHERE u.deleted_at IS NULL
    ORDER BY u.nome ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Carregar Exceções por usuário
$acessosDB = $pdo->query("SELECT id_usuario, tela, nivel FROM usuario_acessos")->fetchAll(PDO::FETCH_ASSOC);
$excByUser = [];
foreach ($acessosDB as $a) {
    $excByUser[(int)$a['id_usuario']][$a['tela']] = $a['nivel'];
}

$users = [];
foreach ($usersDB as $u) {
    $u['mat'] = $u['mat'] ?: '';
    $u['perfil'] = $u['perfil'] ?: '';
    $u['setor'] = $u['setor'] ?: '';
    $u['id_setor'] = (int)($u['id_setor'] ?? 0);
    $u['acesso'] = 'Nunca acessou'; // TODO: implementar rastreio de último acesso
    $u['sinal'] = $u['status'] === 'ativo' ? 'ok' : ($u['status'] === 'pendente' ? 'warn' : 'bad');
    $u['exc'] = (object)($excByUser[(int)$u['id']] ?? []);
    $users[] = $u;
}

layoutHeader('Controle de Usuários');
?>

<style>
:root{
  --sidebar:#133A27;--sidebar-active:#1D5136;--sidebar-label:#7FA890;--sidebar-text:#DCE9E1;
  --orange:#E0951F;--orange-dark:#C87F12;--green:#16A34A;--green-dark:#15803D;
  --page:#F4F5F7;--line:#E5E7EB;--line-soft:#F1F2F4;--ink:#111827;--ink-2:#374151;--muted:#6B7280;--muted-2:#9CA3AF;
}
.mono{font-family:"SFMono-Regular",Consolas,"Liberation Mono",Menlo,monospace;font-weight:600;letter-spacing:.2px}
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
select.f{padding:9px 12px;border:1px solid var(--line);border-radius:8px;background:#fff;font-size:13.5px;font-family:inherit;color:var(--ink-2);min-width:150px}
.btn-filter{padding:9px 20px;border-radius:8px;background:var(--orange);color:#fff;font-weight:600;font-size:13.5px}
.btn-filter:hover{background:var(--orange-dark)}
.btn-new{padding:8px 15px;border-radius:8px;background:var(--orange);color:#fff;font-weight:600;font-size:13px;display:inline-flex;align-items:center;gap:7px}
.btn-new:hover{background:var(--orange-dark)}
.toggles{margin-left:auto;display:flex;align-items:center;gap:16px;font-size:13px;color:var(--ink-2)}
.toggles .lbl{font-weight:600}
.chk{display:flex;align-items:center;gap:6px;cursor:pointer}
.chk input{width:15px;height:15px;accent-color:#2563EB;cursor:pointer}

.table-wrap{overflow-x:auto;margin-top:16px}
table{width:100%;border-collapse:collapse;min-width:1150px}
thead th{font-size:10.5px;font-weight:700;letter-spacing:.75px;text-transform:uppercase;color:var(--muted);padding:10px 12px;text-align:center;white-space:nowrap}
thead th.l{text-align:left}thead th.r{text-align:right}
tbody td{padding:13px 12px;text-align:center;border-top:1px solid var(--line-soft);font-size:13.5px;color:var(--ink-2)}
tbody td.l{text-align:left}tbody td.r{text-align:right}
tbody tr.row:hover{background:#FCFCFD}
.expander{width:26px;height:26px;border:1px solid var(--line);border-radius:6px;color:var(--muted);font-size:15px;display:grid;place-items:center}
.expander:hover{border-color:var(--orange);color:var(--orange)}
.user-cell{display:flex;flex-direction:column;gap:2px;min-width:190px}
.user-name{font-weight:700;color:var(--ink);font-size:13.5px}
.user-role{font-size:11.5px;color:var(--muted-2)}
.email{font-size:13px}
.badge{display:inline-block;padding:4px 11px;border-radius:999px;font-size:11.5px;font-weight:700;white-space:nowrap}
.b-ADM{background:#FEE2E2;color:#DC2626}
.b-GES{background:#FEF0DA;color:#B45309}
.b-SUP{background:#DBEAFE;color:#1D4ED8}
.b-OPE{background:#F3F4F6;color:#4B5563}
.b-VIS{background:#F3F4F6;color:#6B7280}
.s-ativo{background:#DCFCE7;color:#15803D}.s-inativo{background:#F3F4F6;color:#6B7280}
.s-pendente{background:#FDE8E4;color:#C2410C}.s-bloqueado{background:#FEE2E2;color:#B91C1C}
.dot{display:inline-block;width:10px;height:10px;border-radius:50%}
.d-ok{background:#22C55E}.d-warn{background:#EAB308}.d-bad{background:#EF4444}

.meter{display:inline-flex;flex-direction:column;align-items:center;gap:4px;min-width:118px}
.meter-top{display:flex;align-items:center;gap:7px;font-size:12.5px;color:var(--ink-2)}
.meter-top b{font-weight:700;color:var(--ink)}
.meter-bar{width:104px;height:6px;border-radius:99px;background:#EDF0F3;overflow:hidden}
.meter-bar i{display:block;height:100%;background:var(--green);border-radius:99px}
.meter-bar.partial i{background:#3B82F6}
.meter-bar.low i{background:#94A3B8}
.exc{font-size:10.5px;font-weight:700;color:#B45309;background:#FEF0DA;padding:2px 7px;border-radius:5px}
.exc.none{color:var(--muted-2);background:transparent;font-weight:500}

.act{padding:8px 15px;border-radius:8px;font-size:13px;font-weight:600;color:#fff;display:inline-flex;align-items:center;gap:7px}
.act-green{background:var(--green)}.act-green:hover{background:var(--green-dark)}
.act-orange{background:var(--orange)}.act-orange:hover{background:var(--orange-dark)}
.act-ghost{background:#fff;border:1px solid var(--line);color:var(--ink-2);padding:8px 12px;border-radius:8px;font-size:13px;font-weight:600}
.act-ghost:hover{border-color:#CBD5E1;background:#F9FAFB}

tr.detail td{background:#FAFBFC;padding:0}
.detail-inner{padding:18px 22px 20px;display:grid;grid-template-columns:1fr 290px;gap:26px}
.detail-h{font-size:10.5px;font-weight:700;letter-spacing:.75px;text-transform:uppercase;color:var(--muted);margin-bottom:11px}
.area-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:8px}
.area-row{display:grid;grid-template-columns:1fr 92px 74px;align-items:center;gap:10px;background:#fff;border:1px solid var(--line);border-radius:8px;padding:9px 12px}
.area-name{font-size:12.5px;font-weight:600;color:var(--ink-2);display:flex;align-items:center;gap:6px}
.area-name em{font-style:normal;font-size:10px;color:#B45309;background:#FEF0DA;padding:1px 5px;border-radius:4px}
.area-row.off .area-name{color:var(--muted-2);font-weight:500}
.tag{font-size:10.5px;font-weight:700;padding:3px 8px;border-radius:5px;text-align:center}
.t-total{background:#E7F6EC;color:#15803D}.t-edit{background:#FEF0DA;color:#B45309}
.t-view{background:#EEF2F5;color:#475569}.t-off{background:#F5F5F5;color:#B0B5BC}
.count{font-size:11.5px;color:var(--muted);text-align:right}
.facts{display:flex;flex-direction:column;gap:9px}
.fact{display:flex;justify-content:space-between;gap:12px;font-size:12.5px;border-bottom:1px dashed var(--line);padding-bottom:8px}
.fact span:first-child{color:var(--muted)}
.fact span:last-child{color:var(--ink-2);font-weight:600;text-align:right}

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

.overlay{position:fixed;inset:0;background:rgba(17,24,39,.45);display:none;justify-content:center;padding:40px 18px 60px;overflow-y:auto;z-index:50}
.overlay.open{display:flex}
.modal{background:#fff;border-radius:12px;width:100%;max-width:720px;box-shadow:0 18px 48px rgba(0,0,0,.22);flex-shrink:0;align-self:flex-start}
.modal-head{padding:18px 22px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:flex-start;gap:14px}
.modal-head h2{font-size:17px;margin-bottom:3px}
.modal-head p{font-size:12.5px;color:var(--muted)}
.x{font-size:20px;color:var(--muted);padding:2px 7px;border-radius:6px}
.x:hover{background:var(--line-soft);color:var(--ink)}
.modal-body{padding:20px 22px;display:flex;flex-direction:column;gap:17px}
.field{display:flex;flex-direction:column;gap:6px}
.field label{font-size:11px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:var(--muted)}
.field input,.field select{padding:10px 12px;border:1px solid var(--line);border-radius:8px;font-family:inherit;font-size:13.5px;color:var(--ink);background:#fff}
.field input:focus,.field select:focus{outline:none;border-color:var(--orange);box-shadow:0 0 0 3px rgba(224,149,31,.15)}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px}

.access{border:1px solid var(--line);border-radius:10px;overflow:hidden}
.access-top{padding:14px 15px;background:#FAFBFC;border-bottom:1px solid var(--line);display:flex;flex-direction:column;gap:11px}
.access-line{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.access-note{font-size:12px;color:var(--muted)}
.access-note b{color:#B45309}
.link{font-size:12px;font-weight:700;color:var(--orange-dark);text-decoration:underline;text-underline-offset:2px}
.link:hover{color:var(--orange)}
.mini-search{width:100%;padding:8px 11px;border:1px solid var(--line);border-radius:7px;font-size:13px;font-family:inherit}
.mini-search:focus{outline:none;border-color:var(--orange)}
.groups{max-height:290px;overflow-y:auto}
.group{border-bottom:1px solid var(--line-soft)}
.group:last-child{border-bottom:none}
.g-head{width:100%;display:grid;grid-template-columns:18px 1fr auto auto;align-items:center;gap:10px;padding:11px 15px;text-align:left}
.g-head:hover{background:#FAFBFC}
.chev{color:var(--muted-2);font-size:11px;transition:transform .15s}
.group.open .chev{transform:rotate(90deg)}
.g-name{font-size:13.5px;font-weight:600;color:var(--ink)}
.g-sum{font-size:11.5px;color:var(--muted)}
.g-select{padding:5px 8px;border:1px solid var(--line);border-radius:6px;font-size:11.5px;font-family:inherit;color:var(--ink-2);background:#fff}
.g-body{display:none;padding:2px 15px 12px 43px}
.group.open .g-body{display:block}
.tela{display:grid;grid-template-columns:1fr 132px;align-items:center;gap:12px;padding:6px 0}
.tela-name{font-size:12.5px;color:var(--ink-2);display:flex;align-items:center;gap:6px}
.tela-name em{font-style:normal;font-size:9.5px;font-weight:700;color:#B45309;background:#FEF0DA;padding:1px 5px;border-radius:4px}
.lv{width:100%;padding:5px 8px;border-radius:6px;font-size:11.5px;font-weight:700;font-family:inherit;border:1px solid transparent;cursor:pointer}
.lv.v-total{background:#E7F6EC;color:#15803D}
.lv.v-edit{background:#FEF0DA;color:#B45309}
.lv.v-view{background:#EEF2F5;color:#475569}
.lv.v-off{background:#F5F5F5;color:#8E959E}
.access-foot{padding:11px 15px;background:#FAFBFC;border-top:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:12px;font-size:12.5px;color:var(--muted)}
.access-foot b{color:var(--ink-2)}
.no-hit{padding:16px;text-align:center;color:var(--muted-2);font-size:12.5px}
.modal-foot{padding:15px 22px;border-top:1px solid var(--line);display:flex;justify-content:space-between;gap:12px;align-items:center;background:#FAFBFC}
.hint{font-size:12px;color:var(--muted)}
.toast{position:fixed;bottom:24px;right:24px;background:var(--sidebar);color:#fff;padding:13px 18px;border-radius:9px;font-size:13.5px;box-shadow:0 10px 26px rgba(0,0,0,.25);transform:translateY(16px);opacity:0;pointer-events:none;transition:.22s;z-index:60}
.toast.show{transform:none;opacity:1}

@media (max-width:1080px){.detail-inner{grid-template-columns:1fr}.toggles{margin-left:0;width:100%}}
@media (max-width:620px){.content{padding:18px 14px 34px}.grid-2{grid-template-columns:1fr}.g-head{grid-template-columns:18px 1fr auto;row-gap:6px}.g-select{grid-column:2/4;justify-self:start}}
@media (prefers-reduced-motion:reduce){*{transition:none!important}}
</style>

<div class="content">
  <h1 class="text-2xl font-bold tracking-tight">Controle de Usuários</h1>
  <p class="subtitle">Cada usuário recebe um perfil de acesso; ajustes individuais entram como exceções</p>

  <div class="pills" id="pills">
    <button class="pill is-active" data-tab="todos">Todos</button>
    <button class="pill" data-tab="ADM">ADM — Administradores</button>
    <button class="pill" data-tab="GES">GES — Gestores</button>
    <button class="pill" data-tab="SUP">SUP — Supervisores</button>
    <button class="pill" data-tab="OPE">OPE — Operadores</button>
    <button class="pill" data-tab="exc">Com exceções</button>
    <button class="pill" data-tab="pendentes">Pendentes</button>
  </div>

  <section class="card">
    <div class="card-head">
      <div class="card-title"><span class="ico">☰</span> Usuários</div>
      <button class="btn-new" id="btnNew">＋ Novo usuário</button>
    </div>
    <div class="card-body">
      <input class="search" id="q" type="search" placeholder="Buscar nome, e-mail, setor..." autocomplete="off">
      <div class="filters">
        <select class="f" id="fSetor"></select>
        <select class="f" id="fPerfil"></select>
        <select class="f" id="fArea"></select>
        <button class="btn-filter" id="btnFilter">Filtrar</button>
        <div class="toggles">
          <span class="lbl">Mostrar:</span>
          <label class="chk"><input type="checkbox" id="cAtivos" checked> Ativos</label>
          <label class="chk"><input type="checkbox" id="cInativos" checked> Inativos</label>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr>
            <th style="width:44px"></th>
            <th class="l">Perfil de acesso</th>
            <th>#</th>
            <th class="l">Usuário</th><th class="l">E-mail</th>
            <th>Setor</th><th>Status</th><th>Último acesso</th><th>Sinal</th>
            <th>Acesso</th><th class="r">Ações</th>
          </tr></thead>
          <tbody id="tbody"></tbody>
        </table>
      </div>
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

<!-- modal -->
<div class="overlay" id="overlay">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="mTitle">
    <div class="modal-head">
      <div><h2 id="mTitle">Editar usuário</h2><p id="mSub"></p></div>
      <button class="x" id="mClose" aria-label="Fechar">✕</button>
    </div>
    <div class="modal-body">
      <div class="field"><label for="mNome">Nome completo</label><input id="mNome" type="text" placeholder="Ex.: Ana Beatriz Moraes" autocomplete="off"></div>
      <input id="mMat" type="hidden" value="">
      <div class="field"><label for="mEmail">E-mail corporativo</label><input id="mEmail" type="email" placeholder="nome.sobrenome@empresa.com.br" autocomplete="off"></div>
      <div class="field" id="wrapSenha"><label for="mSenha">Senha de acesso <span style="text-transform:none;color:var(--orange);font-weight:400" id="senhaHint"></span></label><input id="mSenha" type="password" placeholder="Mínimo 6 caracteres" autocomplete="new-password"></div>
      <div class="grid-2">
        <div class="field"><label for="mSetor">Setor</label><select id="mSetor"></select></div>
        <div class="field"><label for="mStatus">Status da conta</label>
          <select id="mStatus"><option value="ativo">Ativo</option><option value="pendente">Pendente</option><option value="inativo">Inativo</option><option value="bloqueado">Bloqueado</option></select>
        </div>
      </div>

      <div class="access">
        <div class="access-top">
          <div class="field"><label for="mPerfil">Perfil de acesso</label><select id="mPerfil"></select></div>
          <div class="access-line">
            <span class="access-note" id="mExcNote"></span>
            <button class="link" id="mReset">Voltar ao padrão do perfil</button>
          </div>
          <input class="mini-search" id="mFind" type="search" placeholder="Buscar módulo ou tela..." autocomplete="off">
        </div>
        <div class="groups" id="mGroups"></div>
        <div class="access-foot">
          <span id="mCount"></span>
          <button class="link" id="mToggleAll">Expandir tudo</button>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <span class="hint">Alterações passam a valer no próximo login do usuário.</span>
      <div style="display:flex;gap:10px">
        <button class="act-ghost" id="mCancel">Cancelar</button>
        <button class="act act-green" id="mSave">✓ Salvar acesso</button>
      </div>
    </div>
  </div>
</div>
<div class="toast" id="toast"></div>

<script>
window.__APP_BASE = '<?= APP_URL ?>';

/* ============ estrutura do sistema ============ */
const AREAS=[
 {id:'fab',nome:'Fábrica',telas:[['fab.home','Home']]},
 {id:'lab',nome:'Laboratório',telas:[['lab.reg','Registro de Reprova'],['lab.lis','Lista'],['lab.ret','Retornos']]},
 {id:'iqf',nome:'Inspeção Final',telas:[['iqf.reg','Registro de Reprova'],['iqf.lis','Lista'],['iqf.ret','Retornos']]},
 {id:'ret',nome:'Retrabalho',telas:[['ret.dash','Dashboard'],['ret.pan','Retrabalho'],['ret.rel','Relação de Retrabalhos'],['ret.pri','Prioridade']]},
 {id:'qua',nome:'Qualidade',telas:[['qua.tip','Tipos de Reprova']]},
 {id:'pin',nome:'Pintura',telas:[['pin.pai','Paint Check (Robô)'],['pin.ret','Relação de Retrabalhos']]},
 {id:'ana',nome:'Análise',telas:[['ana.aco','Acompanhamento'],['ana.his','Histórico']]},
 {id:'adm',nome:'Administração',telas:[['adm.usu','Usuários'],['adm.per','Perfis de acesso']]}
];
const TELAS=AREAS.flatMap(a=>a.telas.map(t=>({id:t[0],nome:t[1],area:a.id})));
const TOTAL=TELAS.length;
const NIVEIS={off:'Sem acesso',view:'Consulta',edit:'Edição',total:'Total'};
const RANK={off:0,view:1,edit:2,total:3};
const STATUS={ativo:'Ativo',inativo:'Inativo',pendente:'Pendente',bloqueado:'Bloqueado'};

/* ===== Dados do backend ===== */
const PERFIS = <?= json_encode($PERFIS, JSON_UNESCAPED_UNICODE) ?>;
const SETORES = <?= json_encode($SETORES, JSON_UNESCAPED_UNICODE) ?>;
const users = <?= json_encode($users, JSON_UNESCAPED_UNICODE) ?>;

/* Mapas de CSS para grupo */
const GRP_CLS={ADM:'b-ADM',GES:'b-GES',SUP:'b-SUP',OPE:'b-OPE',VIS:'b-VIS'};

/* ============ regras ============ */
function getPerfil(cod){return PERFIS[cod]||null}
function perfilPerms(cod){const p=getPerfil(cod);return p&&p.perms?{...p.perms}:{}}
const eff=u=>{const base=perfilPerms(u.perfil);const exc=u.exc||{};return {...base,...exc}};
const nExc=u=>{const base=perfilPerms(u.perfil);const exc=u.exc||{};return Object.keys(exc).filter(k=>exc[k]!==base[k]).length};
function resumoArea(perms,a){
 const on=a.telas.filter(t=>perms[t[0]]!=='off'&&perms[t[0]]!==undefined);
 const top=on.reduce((m,t)=>RANK[perms[t[0]]]>RANK[m]?perms[t[0]]:m,'off');
 return {on:on.length,tot:a.telas.length,nivel:top};
}
const liberadas=perms=>TELAS.filter(t=>(perms[t.id]||'off')!=='off').length;

/* ============ estado ============ */
let tab='todos',page=1,perPage=10,expanded=new Set(),editingId=null;
let dPerfil='',dPerms={},openG=new Set(),findTxt='';
const $=s=>document.querySelector(s);
const esc=s=>String(s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
function toast(m){const t=$('#toast');t.textContent=m;t.classList.add('show');clearTimeout(t._t);t._t=setTimeout(()=>t.classList.remove('show'),2600)}

/* ============ filtros ============ */
function filtered(){
 const q=$('#q').value.trim().toLowerCase(),setor=$('#fSetor').value,perfil=$('#fPerfil').value,area=$('#fArea').value;
 const A=$('#cAtivos').checked,I=$('#cInativos').checked;
 return users.filter(u=>{
  const P=getPerfil(u.perfil);
  if(tab==='pendentes'){if(u.status!=='pendente')return false}
  else if(tab==='exc'){if(!nExc(u))return false}
  else if(tab!=='todos'&&(!P||P.grupo!==tab))return false;
  const off=(u.status==='inativo'||u.status==='bloqueado');
  if(off&&!I)return false; if(!off&&!A)return false;
  if(setor&&u.setor!==setor)return false;
  if(perfil&&u.perfil!==perfil)return false;
  if(area){const p=eff(u);if(!AREAS.find(a=>a.id===area).telas.some(t=>(p[t[0]]||'off')!=='off'))return false}
  if(q&&![u.nome,u.email,u.setor,P?P.nome:''].join(' ').toLowerCase().includes(q))return false;
  return true;
 });
}

/* ============ tabela ============ */
function render(){
 const list=filtered(),pages=Math.max(1,Math.ceil(list.length/perPage));
 if(page>pages)page=pages;
 const slice=list.slice((page-1)*perPage,page*perPage),tb=$('#tbody');

 if(!list.length){
  tb.innerHTML='<tr><td colspan="11"><div class="empty"><strong>Nenhum usuário encontrado</strong>Ajuste a busca ou os filtros para ver outros resultados.</div></td></tr>';
 }else{
  tb.innerHTML=slice.map((u,i)=>{
   const P=getPerfil(u.perfil),perms=eff(u),lib=liberadas(perms),pct=Math.round(lib/TOTAL*100),ex=nExc(u);
   const cls=pct>85?'':pct>40?'partial':'low';
   const open=expanded.has(u.id);
   const pNome=P?P.nome:'Sem perfil';
   const pGrupo=P?P.grupo:'VIS';
   const main=`<tr class="row">
     <td><button class="expander" data-exp="${u.id}" aria-expanded="${open}" aria-label="Detalhes de ${esc(u.nome)}">${open?'−':'+'}</button></td>
     <td class="l"><span class="badge ${GRP_CLS[pGrupo]||'b-VIS'}">${pNome}</span></td>
     <td>${(page-1)*perPage+i+1}º</td>
     <td class="l"><div class="user-cell"><span class="user-name">${esc(u.nome)}</span><span class="user-role">${esc(u.setor)||'—'} · Administrativo</span></div></td>
     <td class="l"><span class="email">${esc(u.email)}</span></td>
     <td>${esc(u.setor)||'—'}</td>
     <td><span class="badge s-${u.status}">${STATUS[u.status]||u.status}</span></td>
     <td>${u.acesso}</td>
     <td><span class="dot d-${u.sinal}" title="${u.sinal==='ok'?'Acesso normal':u.sinal==='warn'?'Requer atenção':'Acesso suspenso'}"></span></td>
     <td><span class="meter">
        <span class="meter-top"><b>${lib}</b>/${TOTAL} telas</span>
        <span class="meter-bar ${cls}"><i style="width:${pct}%"></i></span>
        <span class="${ex?'exc':'exc none'}">${ex?ex+' exceção'+(ex>1?'es':''):'padrão do perfil'}</span>
     </span></td>
     <td class="r">${u.status==='pendente'?`<button class="act act-orange" data-edit="${u.id}">Liberar acesso</button>`
        :u.status==='bloqueado'?`<button class="act act-orange" data-edit="${u.id}">Desbloquear</button>`
        :`<button class="act act-green" data-edit="${u.id}">✓ Editar acesso</button>`}</td>
   </tr>`;
   if(!open)return main;
   const areas=AREAS.map(a=>{
    const r=resumoArea(perms,a);
    const base=perfilPerms(u.perfil);
    const exA=a.telas.filter(t=>u.exc&&u.exc[t[0]]!==undefined&&u.exc[t[0]]!==base[t[0]]).length;
    return `<div class="area-row ${r.on?'':'off'}">
      <span class="area-name">${a.nome}${exA?` <em>${exA} exc.</em>`:''}</span>
      <span class="tag t-${r.nivel}">${NIVEIS[r.nivel]}</span>
      <span class="count">${r.on} de ${r.tot}</span></div>`;
   }).join('');
   return main+`<tr class="detail"><td colspan="11"><div class="detail-inner">
     <div><div class="detail-h">Acesso por área — perfil ${pNome}</div><div class="area-list">${areas}</div></div>
     <div><div class="detail-h">Dados da conta</div><div class="facts">
       <div class="fact"><span>Setor</span><span>${esc(u.setor)||'—'}</span></div>
       <div class="fact"><span>Perfil</span><span>${pNome}</span></div>
       <div class="fact"><span>Exceções</span><span>${ex||'nenhuma'}</span></div>
       <div class="fact"><span>Cadastrado em</span><span>${u.criado}</span></div>
       <div class="fact"><span>Último acesso</span><span>${u.acesso}</span></div>
     </div></div></div></td></tr>`;
  }).join('');
 }

 $('#footInfo').textContent=`por página · ${list.length} usuário${list.length===1?'':'s'} · ${list.filter(u=>nExc(u)).length} com exceções`;
 const pg=[`<button class="pg" data-pg="1" ${page===1?'disabled':''}>«</button>`,`<button class="pg" data-pg="${page-1}" ${page===1?'disabled':''}>‹</button>`];
 for(let p=1;p<=pages;p++)pg.push(`<button class="pg ${p===page?'is-active':''}" data-pg="${p}">${p}</button>`);
 pg.push(`<button class="pg" data-pg="${page+1}" ${page===pages?'disabled':''}>›</button>`,`<button class="pg" data-pg="${pages}" ${page===pages?'disabled':''}>»</button>`);
 $('#pager').innerHTML=pg.join('');
}

/* ============ modal ============ */
function openModal(u){
 editingId=u?u.id:null;
 $('#mTitle').textContent=u?'Editar usuário':'Novo usuário';
 $('#mSub').textContent=u?u.nome:'Escolha o perfil e ajuste apenas o que for exceção';
 $('#mNome').value=u?u.nome:'';$('#mMat').value=u?u.mat:'';$('#mEmail').value=u?u.email:'';
 $('#mSenha').value='';
 $('#senhaHint').textContent=u?'(Deixe em branco para manter)':'*';
 $('#mSetor').value=u?(u.id_setor||''):'';$('#mStatus').value=u?u.status:'pendente';
 /* Definir perfil padrão */
 const firstPerfil=Object.keys(PERFIS)[0]||'';
 dPerfil=u?u.perfil:firstPerfil;$('#mPerfil').value=dPerfil;
 dPerms=u?eff(u):{...perfilPerms(dPerfil)};
 openG=new Set();findTxt='';$('#mFind').value='';
 renderAccess();$('#overlay').classList.add('open');setTimeout(()=>$('#mNome').focus(),40);
}
function renderAccess(){
 const base=perfilPerms(dPerfil);
 const diff=TELAS.filter(t=>(dPerms[t.id]||'off')!==(base[t.id]||'off'));
 const f=findTxt.trim().toLowerCase();
 const html=AREAS.map(a=>{
  const telas=a.telas.filter(t=>!f||t[1].toLowerCase().includes(f)||a.nome.toLowerCase().includes(f));
  if(!telas.length)return '';
  const r=resumoArea(dPerms,a);
  const exA=a.telas.filter(t=>(dPerms[t[0]]||'off')!==(base[t[0]]||'off')).length;
  const isOpen=openG.has(a.id)||!!f;
  const rows=telas.map(t=>{
   const v=dPerms[t[0]]||'off',ch=v!==(base[t[0]]||'off');
   return `<div class="tela"><span class="tela-name">${t[1]}${ch?' <em>alterado</em>':''}</span>
    <select class="lv v-${v}" data-tela="${t[0]}">${Object.keys(NIVEIS).map(k=>`<option value="${k}" ${k===v?'selected':''}>${NIVEIS[k]}</option>`).join('')}</select></div>`;
  }).join('');
  return `<div class="group ${isOpen?'open':''}" data-g="${a.id}">
    <button class="g-head" type="button" data-toggle="${a.id}">
      <span class="chev">▶</span>
      <span class="g-name">${a.nome}${exA?` <em style="font-style:normal;font-size:10px;color:#B45309;background:#FEF0DA;padding:1px 5px;border-radius:4px">${exA}</em>`:''}</span>
      <span class="g-sum">${r.on} de ${r.tot} · ${NIVEIS[r.nivel]}</span>
      <select class="g-select" data-area="${a.id}"><option value="">Definir área...</option>${Object.keys(NIVEIS).map(k=>`<option value="${k}">${NIVEIS[k]} em tudo</option>`).join('')}</select>
    </button>
    <div class="g-body">${rows}</div></div>`;
 }).join('');
 $('#mGroups').innerHTML=html||'<div class="no-hit">Nenhuma tela corresponde à busca.</div>';
 const pNome=getPerfil(dPerfil)?getPerfil(dPerfil).nome:'Sem perfil';
 $('#mExcNote').innerHTML=diff.length?`<b>${diff.length} exceção${diff.length>1?'ões':''}</b> em relação ao perfil ${pNome}`:`Seguindo exatamente o perfil ${pNome}`;
 $('#mReset').style.display=diff.length?'':'none';
 const lib=liberadas(dPerms);
 $('#mCount').innerHTML=`<b>${lib}</b> de ${TOTAL} telas liberadas`;
 $('#mToggleAll').textContent=openG.size===AREAS.length?'Recolher tudo':'Expandir tudo';
}
const closeModal=()=>{$('#overlay').classList.remove('open');editingId=null};

/* ============ eventos ============ */
$('#fSetor').innerHTML='<option value="">Setor...</option>'+SETORES.map(s=>`<option value="${esc(s.nome)}">${esc(s.nome)}</option>`).join('');
$('#mSetor').innerHTML='<option value="">Selecione um setor...</option>'+SETORES.map(s=>`<option value="${s.id}">${esc(s.nome)}</option>`).join('');
$('#fPerfil').innerHTML='<option value="">Perfil...</option>'+Object.entries(PERFIS).map(([k,p])=>`<option value="${k}">${p.nome}</option>`).join('');
$('#mPerfil').innerHTML=Object.entries(PERFIS).map(([k,p])=>`<option value="${k}">${p.nome}</option>`).join('');
$('#fArea').innerHTML='<option value="">Área liberada...</option>'+AREAS.map(a=>`<option value="${a.id}">${a.nome}</option>`).join('');

$('#pills').addEventListener('click',e=>{const b=e.target.closest('.pill');if(!b)return;
 document.querySelectorAll('.pill').forEach(p=>p.classList.remove('is-active'));b.classList.add('is-active');
 tab=b.dataset.tab;page=1;expanded.clear();render()});
$('#q').addEventListener('input',()=>{page=1;render()});
$('#btnFilter').addEventListener('click',()=>{page=1;render();toast('Filtros aplicados')});
['fSetor','fPerfil','fArea','cAtivos','cInativos'].forEach(id=>$('#'+id).addEventListener('change',()=>{page=1;render()}));
$('#perPage').addEventListener('change',e=>{perPage=+e.target.value;page=1;render()});
$('#tbody').addEventListener('click',e=>{
 const x=e.target.closest('[data-exp]');
 if(x){const id=+x.dataset.exp;expanded.has(id)?expanded.delete(id):expanded.add(id);render();return}
 const ed=e.target.closest('[data-edit]');if(ed)openModal(users.find(u=>u.id==ed.dataset.edit))});
$('#pager').addEventListener('click',e=>{const b=e.target.closest('[data-pg]');if(!b||b.disabled)return;
 page=+b.dataset.pg;render();window.scrollTo({top:0,behavior:'smooth'})});

$('#btnNew').addEventListener('click',()=>openModal(null));
$('#mClose').addEventListener('click',closeModal);$('#mCancel').addEventListener('click',closeModal);
$('#overlay').addEventListener('click',e=>{if(e.target===$('#overlay'))closeModal()});
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&$('#overlay').classList.contains('open'))closeModal()});

$('#mPerfil').addEventListener('change',e=>{dPerfil=e.target.value;dPerms={...perfilPerms(dPerfil)};renderAccess();
 const pn=getPerfil(dPerfil);toast(`Perfil ${pn?pn.nome:dPerfil} aplicado`)});
$('#mReset').addEventListener('click',()=>{dPerms={...perfilPerms(dPerfil)};renderAccess();toast('Exceções removidas')});
$('#mFind').addEventListener('input',e=>{findTxt=e.target.value;renderAccess()});
$('#mToggleAll').addEventListener('click',()=>{openG.size===AREAS.length?openG.clear():AREAS.forEach(a=>openG.add(a.id));renderAccess()});
$('#mGroups').addEventListener('click',e=>{
 const t=e.target.closest('[data-toggle]');
 if(t&&!e.target.closest('.g-select')){const id=t.dataset.toggle;openG.has(id)?openG.delete(id):openG.add(id);renderAccess()}});
$('#mGroups').addEventListener('change',e=>{
 const tela=e.target.closest('[data-tela]');
 if(tela){dPerms[tela.dataset.tela]=tela.value;renderAccess();return}
 const area=e.target.closest('[data-area]');
 if(area&&area.value){AREAS.find(a=>a.id===area.dataset.area).telas.forEach(t=>dPerms[t[0]]=area.value);
  openG.add(area.dataset.area);renderAccess()}});

$('#mSave').addEventListener('click',()=>{
 const nome=$('#mNome').value.trim(),email=$('#mEmail').value.trim(),mat=$('#mMat').value.trim(),senha=$('#mSenha').value;
 if(!nome||!email){toast('Informe nome e e-mail para salvar');return}
 if(!editingId&&!senha){toast('Informe a senha para o novo usuário');return}

 /* Calcular exceções: só o que difere do perfil base */
 const base=perfilPerms(dPerfil);
 const exc={};
 TELAS.forEach(t=>{
  const cur=dPerms[t.id]||'off', bs=base[t.id]||'off';
  if(cur!==bs) exc[t.id]=cur;
 });

 const pRow=getPerfil(dPerfil);
 const idPerfil=pRow?pRow.id:'';
 const st=$('#mStatus').value;

 const btn=$('#mSave');
 const oTxt=btn.textContent;
 btn.textContent='Salvando...';btn.disabled=true;

 const dados={
   acao:'salvar_usuario', id:editingId||'', nome, email, mat, senha,
   setor:$('#mSetor').value, perfil:idPerfil, status:st, exc:JSON.stringify(exc)
 };

 fetch(window.__APP_BASE+'/api/admin-v2-acao.php',{
     method:'POST',
     headers:{'Content-Type':'application/x-www-form-urlencoded'},
     body:new URLSearchParams(dados)
 }).then(r=>r.json()).then(res=>{
     if(res.sucesso){
         const selSetor = SETORES.find(s=>s.id==$('#mSetor').value);
         const setorNome = selSetor ? selSetor.nome : '';
         const idSetor = selSetor ? selSetor.id : 0;
         const newData={nome,email,mat,perfil:dPerfil,id_setor:idSetor,setor:setorNome,status:st,exc:{...exc},
           sinal:st==='ativo'?'ok':st==='pendente'?'warn':'bad'};
         if(editingId){
             const u=users.find(x=>x.id==editingId);
             Object.assign(u,newData);
             toast(`Acesso de ${nome.split(' ')[0]} atualizado`);
         }else{
             users.unshift({id:res.id,acesso:'Nunca acessou',criado:res.criado,...newData});
             toast(`${nome.split(' ')[0]} cadastrado com ${liberadas(dPerms)} telas liberadas`);
         }
         closeModal();render();
     } else {
         toast(res.erro||'Erro ao salvar usuário');
     }
 }).catch(()=>toast('Erro de rede')).finally(()=>{btn.textContent=oTxt;btn.disabled=false});
});

render();
</script>

<?php layoutFooter(); ?>
