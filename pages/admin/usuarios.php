<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/layout.php';

requireAcessoModulo('admin');

$canEdit = podeEditar('adm.usu') || podeEditar('adm.per') || podeEditar('admin') || isAdmin();

$pdo = getDB();
$base = defined('APP_URL') ? APP_URL : '';
$curUser = currentUser();
$curUserId = (int)($curUser['id'] ?? 0);

// ─── Sincronizador de Permissões e Telas ──────────────────────────────────────
function sincronizarPermissoes(array $perms): array {
    if (isset($perms['ret.pri']) && !array_key_exists('pcp.pri', $perms)) {
        $perms['pcp.pri'] = $perms['ret.pri'];
    }
    if (isset($perms['pcp.pri']) && !array_key_exists('ret.pri', $perms)) {
        $perms['ret.pri'] = $perms['pcp.pri'];
    }
    if (isset($perms['ana.his']) && !array_key_exists('ana.aco', $perms)) {
        $perms['ana.aco'] = $perms['ana.his'];
    }
    if (isset($perms['adm.per']) && !array_key_exists('adm.usu', $perms)) {
        $perms['adm.usu'] = $perms['adm.per'];
    }
    return $perms;
}

// ─── Estrutura Completa de Sistemas da Fábrica -> Módulos -> Telas ───────────
$SISTEMAS_ESTRUTURA = [
    [
        'id' => 'retrabalho',
        'chave' => 'hub:retrabalho',
        'nome' => 'Retrabalho (SGT)',
        'badge' => '8 Módulos Operacionais',
        'badge_color' => '#E89B1C',
        'categoria' => 'Qualidade & Fábrica',
        'desc' => 'Gestão de reprovas, reensaios, prioridades e retrabalho da fábrica.',
        'svg' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        'aberto_padrao' => true,
        'modulos' => [
            [
                'id' => 'pcp',
                'nome' => 'PCP',
                'svg' => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/>',
                'telas' => [
                    ['pcp.pri', 'Prioridades']
                ]
            ],
            [
                'id' => 'lab',
                'nome' => 'Laboratório',
                'svg' => '<path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/>',
                'telas' => [
                    ['lab.reg', 'Registro de Reprova'],
                    ['lab.lis', 'Lista de Registros'],
                    ['lab.ret', 'Retornos ao Laboratório']
                ]
            ],
            [
                'id' => 'iqf',
                'nome' => 'Inspeção Final',
                'svg' => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
                'telas' => [
                    ['iqf.reg', 'Registro de Reprova'],
                    ['iqf.lis', 'Lista de Inspeção'],
                    ['iqf.ret', 'Retornos à Inspeção']
                ]
            ],
            [
                'id' => 'ret',
                'nome' => 'Retrabalho Operacional',
                'svg' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>',
                'telas' => [
                    ['ret.dash', 'Dashboard'],
                    ['ret.pan', 'Retrabalho Operacional'],
                    ['ret.rel', 'Relação de Retrabalhos']
                ]
            ],
            [
                'id' => 'pin',
                'nome' => 'Pintura',
                'svg' => '<path d="m19 11-8-8-8.6 8.6a2 2 0 0 0 0 2.8l5.2 5.2c.8.8 2 .8 2.8 0L19 11Z"/><path d="m5 2 5 5"/><path d="M2 13h15"/><path d="M22 20a2 2 0 1 1-4 0c0-1.6 1.7-2.4 2-4 .3 1.6 2 2.4 2 4Z"/>',
                'telas' => [
                    ['pin.pai', 'Paint Check (Robô)'],
                    ['pin.ret', 'Relação de Retrabalhos']
                ]
            ],
            [
                'id' => 'qua',
                'nome' => 'Qualidade',
                'svg' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
                'telas' => [
                    ['qua.tip', 'Tipos de Reprova']
                ]
            ],
            [
                'id' => 'ana',
                'nome' => 'Análise',
                'svg' => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
                'telas' => [
                    ['ana.aco', 'Acompanhamento'],
                    ['ana.his', 'Histórico']
                ]
            ],
            [
                'id' => 'adm',
                'nome' => 'Administração',
                'svg' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>',
                'telas' => [
                    ['adm.usu', 'Usuários & Setores']
                ]
            ]
        ]
    ],
    [
        'id' => 'sge',
        'chave' => 'hub:sge',
        'nome' => 'SGE — Engenharia',
        'badge' => 'Demandas & Projetos',
        'badge_color' => '#C6800F',
        'categoria' => 'Engenharia',
        'desc' => 'Gestão de demandas técnicas, projetos de engenharia e etapas de produção.',
        'svg' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
        'aberto_padrao' => false,
        'modulos' => [
            [
                'id' => 'sge_proj',
                'nome' => 'Módulo de Projetos',
                'svg' => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
                'telas' => [
                    ['hub:sge', 'Acesso Geral ao SGE']
                ]
            ]
        ]
    ],
    [
        'id' => 'soma',
        'chave' => 'hub:soma',
        'nome' => 'SOMA — PCP & Cronoanálise',
        'badge' => 'Tempos & Produtividade',
        'badge_color' => '#2E6CB8',
        'categoria' => 'PCP / Tempos',
        'desc' => 'Análise de tempos: peça/hora, tempos padrão, paradas e metas de produção.',
        'svg' => '<circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 16 14"/>',
        'aberto_padrao' => false,
        'modulos' => [
            [
                'id' => 'soma_operacional',
                'nome' => 'Operação & Apontamento',
                'svg' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 14 14"/>',
                'telas' => [
                    ['soma.hub', 'Dashboard SOMA'],
                    ['soma.dig', 'Digitador PCP'],
                    ['soma.ope', 'Terminal do Operador'],
                    ['soma.par', 'Apontamento de Paradas']
                ]
            ],
            [
                'id' => 'soma_gestao',
                'nome' => 'Gestão & Auditoria',
                'svg' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>',
                'telas' => [
                    ['soma.reg', 'Relação de Tempos & Ciclos'],
                    ['soma.rel', 'Relatórios de Produtividade'],
                    ['soma.aud', 'Auditoria de Apontamentos'],
                    ['soma.cfg', 'Configurações de Tempos']
                ]
            ]
        ]
    ],
    [
        'id' => 'producao',
        'chave' => 'hub:producao',
        'nome' => 'Produção — PCP & Chão de Fábrica',
        'badge' => 'PCP & Apontamentos',
        'badge_color' => '#0F766E',
        'categoria' => 'Fábrica',
        'desc' => 'Dashboards de planejamento, controle de produção (PCP) e acompanhamento em tempo real.',
        'svg' => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/>',
        'aberto_padrao' => false,
        'modulos' => [
            [
                'id' => 'prod_pcp',
                'nome' => 'PCP — Indicadores & Fluxo',
                'svg' => '<rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>',
                'telas' => [
                    ['prod.dis', 'Indicador Distribuição (TPD)'],
                    ['prod.atr', 'Atraso Distribuição'],
                    ['prod.for', 'Indicador Média Força (TPM/TPS)'],
                    ['prod.set', 'Painel por Setor'],
                    ['prod.flu', 'Fluxo de Pedidos'],
                    ['prod.aco', 'Acompanhamento (Tanque/P. Ativa)'],
                    ['prod.met', 'Configurações & Métricas']
                ]
            ],
            [
                'id' => 'prod_chao',
                'nome' => 'Chão de Fábrica — Estações',
                'svg' => '<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
                'telas' => [
                    ['prod.reg', 'Registro de Entrada (LAB)'],
                    ['prod.lis', 'Lista de Registros'],
                    ['prod.ret', 'Fila de Retornos']
                ]
            ]
        ]
    ],
    [
        'id' => '5s',
        'chave' => 'hub:5s',
        'nome' => '5S — Auditorias & Organização',
        'badge' => 'Checklists por Setor',
        'badge_color' => '#2E8B57',
        'categoria' => 'Organização',
        'desc' => 'Auditorias periódicas e checklists de 5S por setor da fábrica.',
        'svg' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
        'aberto_padrao' => false,
        'modulos' => [
            [
                'id' => '5s_audit',
                'nome' => 'Módulo de Auditorias',
                'svg' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
                'telas' => [
                    ['hub:5s', 'Acesso Geral ao 5S']
                ]
            ]
        ]
    ],
    [
        'id' => 'ausencias',
        'chave' => 'hub:ausencias',
        'nome' => 'Ausências — Gestão de Pessoas',
        'badge' => 'Presença & Equipe',
        'badge_color' => '#7C5CBF',
        'categoria' => 'Pessoas',
        'desc' => 'Controle de faltas, férias, atestados e afastamentos da equipe.',
        'svg' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="14.5" y1="14" x2="9.5" y2="19"/><line x1="9.5" y1="14" x2="14.5" y2="19"/>',
        'aberto_padrao' => false,
        'modulos' => [
            [
                'id' => 'aus_escala',
                'nome' => 'Módulo de Pessoas',
                'svg' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>',
                'telas' => [
                    ['hub:ausencias', 'Acesso Geral a Ausências']
                ]
            ]
        ]
    ],
    [
        'id' => 'incidentes',
        'chave' => 'hub:incidentes',
        'nome' => 'Incidentes — Segurança do Trabalho',
        'badge' => 'Segurança & Saúde',
        'badge_color' => '#D0453B',
        'categoria' => 'Segurança',
        'desc' => 'Registro e acompanhamento de incidentes no processo produtivo.',
        'svg' => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'aberto_padrao' => false,
        'modulos' => [
            [
                'id' => 'inc_reg',
                'nome' => 'Módulo de Segurança',
                'svg' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
                'telas' => [
                    ['hub:incidentes', 'Acesso Geral a Incidentes']
                ]
            ]
        ]
    ],
    [
        'id' => 'perdas',
        'chave' => 'hub:perdas',
        'nome' => 'Perdas & Descartes',
        'badge' => 'Sucatas & Refugos',
        'badge_color' => '#B5852A',
        'categoria' => 'Descartes',
        'desc' => 'Lançamento de descartes de fabricação: sucatas, perdas de cobre e materiais.',
        'svg' => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/>',
        'aberto_padrao' => false,
        'modulos' => [
            [
                'id' => 'perd_sucata',
                'nome' => 'Módulo de Descartes',
                'svg' => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>',
                'telas' => [
                    ['hub:perdas', 'Acesso Geral a Perdas']
                ]
            ]
        ]
    ],
    [
        'id' => 'paradas',
        'chave' => 'hub:paradas',
        'nome' => 'Paradas de Máquina',
        'badge' => 'Motivos & Tempos',
        'badge_color' => '#C08A1E',
        'categoria' => 'Operação',
        'desc' => 'Registro de paradas operacionais, motivos de máquina parada e tempo improdutivo.',
        'svg' => '<circle cx="12" cy="12" r="9"/><line x1="10" y1="9" x2="10" y2="15"/><line x1="14" y1="9" x2="14" y2="15"/>',
        'aberto_padrao' => false,
        'modulos' => [
            [
                'id' => 'par_tempo',
                'nome' => 'Módulo de Paradas',
                'svg' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 8 14"/>',
                'telas' => [
                    ['hub:paradas', 'Acesso Geral a Paradas']
                ]
            ]
        ]
    ],
];

// ─── Carregamento do Banco de Dados ───────────────────────────────────────────
// 1. Perfis
$perfisDB = $pdo->query("
    SELECT id, cod, nome, grupo, descricao, status, sistema, perms, DATE_FORMAT(created_at, '%d/%m/%Y') as criado 
    FROM perfis 
    ORDER BY nome ASC
")->fetchAll(PDO::FETCH_ASSOC);

$userCountByPerfil = $pdo->query("
    SELECT id_perfil, COUNT(id) as total 
    FROM usuarios 
    WHERE deleted_at IS NULL 
    GROUP BY id_perfil
")->fetchAll(PDO::FETCH_KEY_PAIR);

$perfis = [];
foreach ($perfisDB as $p) {
    $p['sistema'] = (bool)($p['sistema'] ?? 0);
    $p['usuarios'] = (int)($userCountByPerfil[$p['id']] ?? 0);
    $rawPerms = json_decode((string)$p['perms'], true) ?: [];
    $p['perms'] = sincronizarPermissoes($rawPerms);
    $perfis[$p['id']] = $p;
}

// 2. Setores
$setoresDB = $pdo->query("
    SELECT id, cod, nome, resp, cc, turnos, perfil, status, DATE_FORMAT(created_at, '%d/%m/%Y') as criado 
    FROM setores 
    ORDER BY nome ASC
")->fetchAll(PDO::FETCH_ASSOC);

$userCountBySetor = $pdo->query("
    SELECT id_setor, COUNT(id) as total 
    FROM usuarios 
    WHERE deleted_at IS NULL 
    GROUP BY id_setor
")->fetchAll(PDO::FETCH_KEY_PAIR);

$setores = [];
foreach ($setoresDB as $s) {
    $s['usuarios'] = (int)($userCountBySetor[$s['id']] ?? 0);
    $setores[$s['id']] = $s;
}

// 3. Usuários e Acessos
$usersDB = $pdo->query("
    SELECT u.id, u.nome, u.email, u.cpf, u.matricula, u.status, u.id_setor, u.id_perfil,
           DATE_FORMAT(u.created_at, '%d/%m/%Y') as criado,
           p.nome as perfil_nome, p.cod as perfil_cod,
           s.nome as setor_nome, s.cod as setor_cod
    FROM usuarios u
    LEFT JOIN perfis p ON u.id_perfil = p.id
    LEFT JOIN setores s ON u.id_setor = s.id
    WHERE u.deleted_at IS NULL
    ORDER BY u.nome ASC
")->fetchAll(PDO::FETCH_ASSOC);

$acessosDB = $pdo->query("SELECT id_usuario, tela, nivel FROM usuario_acessos")->fetchAll(PDO::FETCH_ASSOC);
$acessosPorUsuario = [];
foreach ($acessosDB as $a) {
    $acessosPorUsuario[(int)$a['id_usuario']][$a['tela']] = $a['nivel'];
}

$usuarios = [];

foreach ($usersDB as $u) {
    $uId = (int)$u['id'];
    $uPerId = (int)($u['id_perfil'] ?? 0);
    $uExc = $acessosPorUsuario[$uId] ?? [];
    
    $permsBase = $uPerId > 0 && isset($perfis[$uPerId]) ? $perfis[$uPerId]['perms'] : [];
    $permsEfetivas = sincronizarPermissoes(array_merge($permsBase, $uExc));

    $modulosAtivos = [];
    foreach ($SISTEMAS_ESTRUTURA as $sys) {
        $temSys = false;
        foreach ($sys['modulos'] as $mod) {
            foreach ($mod['telas'] as $t) {
                $lvl = $permsEfetivas[$t[0]] ?? 'off';
                if ($lvl !== 'off' && $lvl !== '') {
                    $temSys = true;
                    if ($sys['id'] === 'retrabalho') {
                        $modulosAtivos[] = $mod['nome'];
                    }
                }
            }
        }
        if ($temSys && $sys['id'] !== 'retrabalho') {
            $modulosAtivos[] = $sys['nome'];
        }
    }

    $u['perms_efetivas'] = $permsEfetivas;
    $u['modulos_ativos'] = array_unique($modulosAtivos);
    $u['tem_excecoes'] = !empty($uExc);
    $u['exc_count'] = count($uExc);

    $usuarios[] = $u;
}

$abaInicial = trim($_GET['aba'] ?? 'usuarios');
if (!in_array($abaInicial, ['usuarios', 'setores', 'perfis'], true)) {
    $abaInicial = 'usuarios';
}

$pageTitle = 'Gestão de Usuários & Setores';
layoutHeader($pageTitle);
?>

<style>
    /* ─── Layout Unificado SGT ──────────────────────────────────────────────── */
    .admin-page-container {
        display: flex;
        flex-direction: column;
        height: calc(100vh - var(--header-height) - 40px);
        height: calc(100dvh - var(--header-height) - 40px);
        min-height: 0;
        overflow: hidden;
    }

    .admin-sticky-top {
        flex-shrink: 0;
        background: var(--color-bg, #f4f5f7);
        padding-bottom: 12px;
    }

    /* ─── Switch de Abas (Pills) ────────────────────────────────────────────── */
    .admin-tab-switch {
        display: inline-flex;
        background: #e2e8f0;
        border-radius: 9999px;
        padding: 3px;
        gap: 4px;
    }
    .admin-switch-btn {
        border: none;
        background: transparent;
        padding: 6px 14px;
        border-radius: 9999px;
        font-size: 12.5px;
        font-weight: 600;
        color: #475569;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.15s ease;
    }
    .admin-switch-btn:hover { color: #0f172a; }
    .admin-switch-btn.is-active {
        background: #ffffff;
        color: #0e2c1d;
        font-weight: 700;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    .admin-switch-btn .tab-count-pill {
        background: #f1f5f9;
        color: #64748b;
        font-size: 11px;
        padding: 1px 7px;
        border-radius: 9999px;
    }
    .admin-switch-btn.is-active .tab-count-pill {
        background: #e6f4ea;
        color: #166534;
        font-weight: 700;
    }

    /* ─── Card de Tabela Rolável ────────────────────────────────────────────── */
    .admin-pane-card {
        flex: 1;
        min-height: 0;
        display: flex;
        flex-direction: column;
        background: #ffffff;
        border: 1px solid var(--color-border,#e5e7eb);
        border-radius: var(--radius-lg,10px);
        overflow: hidden;
    }

    .admin-toolbar {
        padding: 12px 16px;
        border-bottom: 1px solid var(--color-border,#f1f5f9);
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 10px;
        flex-shrink: 0;
        background: #ffffff;
    }
    .admin-toolbar-left, .admin-toolbar-right {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
    }

    .admin-toolbar input[type=search], .admin-toolbar select {
        padding: 7px 12px;
        border: 1px solid var(--color-border,#d1d5db);
        border-radius: 8px;
        font-size: 13px;
        background: #fff;
        color: var(--color-text-primary,#111827);
    }
    .admin-toolbar input[type=search]:focus, .admin-toolbar select:focus {
        outline: none;
        border-color: #E89B1C;
        box-shadow: 0 0 0 3px rgba(232,155,28,0.15);
    }

    .btn-action-primary {
        background: #E89B1C;
        color: #0e2c1d;
        border: none;
        padding: 7px 16px;
        border-radius: 8px;
        font-size: 12.5px;
        font-weight: 700;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.15s ease;
    }
    .btn-action-primary:hover { background: #d98e16; }

    .admin-table-scroll {
        flex: 1;
        min-height: 0;
        overflow-y: auto;
        overflow-x: auto;
    }

    /* ─── Tabela Padrão SGT ─────────────────────────────────────────────────── */
    .table-admin { width: 100%; border-collapse: collapse; font-size: 13px; }
    .table-admin thead th {
        position: sticky;
        top: 0;
        z-index: 10;
        background: #f8fafc;
        text-align: left;
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: .6px;
        color: var(--color-text-muted,#6b7280);
        padding: 10px 14px;
        border-bottom: 1px solid var(--color-border,#e5e7eb);
        white-space: nowrap;
    }
    .table-admin tbody td {
        padding: 10px 14px;
        border-bottom: 1px solid var(--color-border,#f1f5f9);
        vertical-align: middle;
        color: #1f2937;
    }
    .table-admin tbody tr:hover { background: #fbfcfd; }

    /* ─── Badges e Tags ─────────────────────────────────────────────────────── */
    .mono-code {
        font-family: 'JetBrains Mono', monospace;
        font-weight: 600;
        font-size: 12px;
        color: #0f172a;
    }

    .badge-perfil {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 6px;
        font-size: 11.5px;
        font-weight: 600;
        white-space: nowrap;
    }
    .badge-perfil-adm { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; }
    .badge-perfil-ges { background: #ffedd5; color: #ea580c; border: 1px solid #fdba74; }
    .badge-perfil-sup { background: #fef9c3; color: #a16207; border: 1px solid #fde047; }
    .badge-perfil-ope { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
    .badge-perfil-custom { background: #f3e8ff; color: #7e22ce; border: 1px solid #e9d5ff; font-style: italic; }

    .badge-mod-tag {
        display: inline-block;
        font-size: 10.5px;
        padding: 1px 6px;
        border-radius: 4px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        color: #475569;
        font-weight: 600;
        margin: 1px 2px;
    }

    .badge-status {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 2px 8px;
        border-radius: 9999px;
        font-size: 11px;
        font-weight: 700;
    }
    .badge-status.is-ativo { background: #dcfce7; color: #15803d; }
    .badge-status.is-inativo { background: #f1f5f9; color: #64748b; }
    .badge-status .dot { width: 6px; height: 6px; border-radius: 50%; display: inline-block; }
    .badge-status.is-ativo .dot { background: #16a34a; }
    .badge-status.is-inativo .dot { background: #94a3b8; }

    .btn-row-action {
        background: #ffffff;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        padding: 5px 12px;
        font-size: 12px;
        font-weight: 600;
        color: #374151;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        transition: all 0.1s ease;
    }
    .btn-row-action:hover { border-color: #9ca3af; background: #f9fafb; color: #0f172a; }

    /* ─── Modais com Rolagem e Topo Travado ─────────────────────────────────── */
    .admin-modal-card {
        background: #ffffff;
        border-radius: 14px;
        width: 100%;
        max-width: 760px;
        height: 88vh;
        height: 88dvh;
        display: flex;
        flex-direction: column;
        box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
        overflow: hidden;
    }
    .admin-modal-card form {
        display: flex;
        flex-direction: column;
        flex: 1;
        min-height: 0;
        overflow: hidden;
    }
    .admin-modal-head {
        flex-shrink: 0;
        padding: 16px 20px;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #f8fafc;
    }
    .admin-modal-body {
        padding: 18px 20px;
        display: flex;
        flex-direction: column;
        flex: 1;
        min-height: 0;
        overflow: hidden;
    }
    .admin-modal-foot {
        flex-shrink: 0;
        padding: 14px 20px;
        border-top: 1px solid #e5e7eb;
        background: #f8fafc;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .form-full { grid-column: 1 / -1; }
    .form-group { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; }
    .form-group label { font-size: 12px; font-weight: 600; color: #374151; }
    .form-group input, .form-group select, .form-group textarea {
        padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px;
    }

    /* ─── Accordion Tree: Sistema -> Módulos -> Telas ───────────────────────── */
    .system-accordion-card {
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        margin-bottom: 10px;
        background: #ffffff;
        overflow: hidden;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .system-accordion-card:hover {
        border-color: #cbd5e1;
    }
    .system-accordion-card.is-expanded {
        border-color: #f59e0b;
        box-shadow: 0 4px 12px -2px rgba(232, 155, 28, 0.12);
    }
    .system-accordion-header {
        padding: 12px 14px;
        background: #f8fafc;
        display: flex;
        align-items: center;
        justify-content: space-between;
        cursor: pointer;
        user-select: none;
        transition: background-color 0.15s ease;
    }
    .system-accordion-header:hover {
        background: #f1f5f9;
    }
    .system-accordion-card.is-expanded .system-accordion-header {
        background: #fffbeb;
        border-bottom: 1px solid #fef3c7;
    }
    .system-header-left {
        display: flex;
        align-items: center;
        gap: 10px;
        flex: 1;
        min-width: 0;
    }
    .system-icon-box {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        color: #d97706;
    }
    .system-header-title {
        font-weight: 700;
        font-size: 13.5px;
        color: #0f172a;
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .system-badge-pill {
        font-size: 10.5px;
        font-weight: 700;
        padding: 2px 7px;
        border-radius: 9999px;
        background: #fef3c7;
        color: #b45309;
        border: 1px solid #fde68a;
    }
    .system-header-right {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-shrink: 0;
    }
    .btn-toggle-accordion {
        background: #ffffff;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        padding: 4px 8px;
        font-size: 11px;
        font-weight: 700;
        color: #475569;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        cursor: pointer;
        transition: all 0.15s ease;
    }
    .btn-toggle-accordion:hover {
        background: #f8fafc;
        color: #0f172a;
    }
    .chevron-icon {
        transition: transform 0.2s ease;
        width: 14px;
        height: 14px;
    }
    .system-accordion-card.is-expanded .chevron-icon {
        transform: rotate(180deg);
    }
    .system-accordion-body {
        display: none;
        padding: 12px 14px;
        background: #ffffff;
    }
    .system-accordion-card.is-expanded .system-accordion-body {
        display: block;
    }

    /* Submódulo dentro do Sistema */
    .module-group-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 10px 12px;
        margin-bottom: 8px;
    }
    .module-group-box:last-child {
        margin-bottom: 0;
    }
    .module-group-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding-bottom: 6px;
        margin-bottom: 6px;
        border-bottom: 1px solid #e2e8f0;
    }
    .module-group-title {
        font-weight: 700;
        font-size: 12.5px;
        color: #1e293b;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .screen-perm-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 4px 0;
        font-size: 12px;
        color: #475569;
    }
    .screen-perm-item select {
        padding: 3px 8px;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        font-size: 11.5px;
    }
</style>

<div class="admin-page-container">

    <!-- Topo Fixo com Título e Switch de Abas -->
    <div class="admin-sticky-top">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <div>
                <h1 style="font-size:var(--font-size-xl,20px);font-weight:700;color:var(--color-text-primary,#111827);margin:0;">
                    Gestão de Usuários &amp; Setores
                </h1>
                <p class="text-secondary" style="font-size:13px;color:var(--color-text-secondary,#6b7280);margin-top:2px;margin-bottom:0;">
                    Controle unificado de colaboradores, permissões de acesso e estações da fábrica.
                </p>
            </div>

            <!-- Barra de Alternância de Abas -->
            <div class="admin-tab-switch">
                <button type="button" class="admin-switch-btn <?= $abaInicial === 'usuarios' ? 'is-active' : '' ?>" data-tab="usuarios">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                    <span>Usuários</span>
                    <span class="tab-count-pill"><?= count($usuarios) ?></span>
                </button>
                <button type="button" class="admin-switch-btn <?= $abaInicial === 'setores' ? 'is-active' : '' ?>" data-tab="setores">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                    <span>Setores da Fábrica</span>
                    <span class="tab-count-pill"><?= count($setores) ?></span>
                </button>
                <button type="button" class="admin-switch-btn <?= $abaInicial === 'perfis' ? 'is-active' : '' ?>" data-tab="perfis">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    <span>Perfis / Templates</span>
                    <span class="tab-count-pill"><?= count($perfis) ?></span>
                </button>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════════ -->
    <!-- ABA 1: USUÁRIOS                                                             -->
    <!-- ═══════════════════════════════════════════════════════════════════════════ -->
    <div class="admin-pane-card js-admin-tab-pane" id="pane-usuarios" style="<?= $abaInicial !== 'usuarios' ? 'display:none;' : '' ?>">
        
        <div class="admin-toolbar">
            <div class="admin-toolbar-left">
                <input type="search" id="filtro-user-busca" placeholder="Buscar por nome, e-mail, matrícula ou CPF..." style="min-width:280px;">
                
                <select id="filtro-user-setor">
                    <option value="">Todos os Setores</option>
                    <?php foreach ($setores as $s): ?>
                        <option value="<?= htmlspecialchars($s['nome']) ?>"><?= htmlspecialchars($s['nome']) ?> (<?= htmlspecialchars($s['cod']) ?>)</option>
                    <?php endforeach; ?>
                </select>

                <select id="filtro-user-status">
                    <option value="">Todos os Status</option>
                    <option value="ativo" selected>Ativos</option>
                    <option value="inativo">Inativos</option>
                </select>
            </div>
            <div class="admin-toolbar-right">
                <?php if ($canEdit): ?>
                    <button type="button" class="btn-action-primary js-btn-novo-usuario">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:14px;height:14px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        <span>Novo Usuário</span>
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="admin-table-scroll">
            <table class="table-admin">
                <thead>
                    <tr>
                        <th style="width:50px;text-align:center;">#</th>
                        <th>Colaborador / E-mail</th>
                        <th>Setor</th>
                        <th>Perfil / Função</th>
                        <th>Módulos &amp; Sistemas Liberados</th>
                        <th style="width:90px;text-align:center;">Status</th>
                        <th style="width:120px;text-align:center;">Ações</th>
                    </tr>
                </thead>
                <tbody id="tbody-usuarios">
                    <?php if (!$usuarios): ?>
                        <tr><td colspan="7" style="text-align:center;padding:30px;color:#64748b;">Nenhum usuário cadastrado.</td></tr>
                    <?php else: foreach ($usuarios as $idx => $u):
                        $bClass = 'badge-perfil-ope';
                        if ($u['id_perfil'] === 1 || str_contains((string)$u['perfil_cod'], 'ADM')) $bClass = 'badge-perfil-adm';
                        elseif (str_contains((string)$u['perfil_cod'], 'GES')) $bClass = 'badge-perfil-ges';
                        elseif (str_contains((string)$u['perfil_cod'], 'SUP')) $bClass = 'badge-perfil-sup';
                        elseif (!$u['id_perfil']) $bClass = 'badge-perfil-custom';

                        $sSearch = mb_strtolower($u['nome'] . ' ' . $u['email'] . ' ' . $u['cpf'] . ' ' . $u['matricula'] . ' ' . $u['setor_nome'] . ' ' . $u['perfil_nome']);
                    ?>
                        <tr class="js-row-user"
                            data-search="<?= htmlspecialchars($sSearch) ?>"
                            data-setor="<?= htmlspecialchars($u['setor_nome'] ?? '') ?>"
                            data-status="<?= htmlspecialchars($u['status']) ?>">
                            <td style="text-align:center;color:#94a3b8;font-size:11.5px;"><?= $idx + 1 ?>°</td>
                            <td>
                                <div>
                                    <strong style="color:#0f172a;font-size:13.5px;"><?= htmlspecialchars($u['nome']) ?></strong>
                                    <div style="font-size:11.5px;color:#64748b;margin-top:1px;">
                                        <?= htmlspecialchars($u['email']) ?>
                                        <?php if ($u['cpf']): ?>
                                            &middot; <span style="font-family:monospace;"><?= htmlspecialchars($u['cpf']) ?></span>
                                        <?php endif; ?>
                                        <?php if ($u['matricula']): ?>
                                            &middot; <span>Mat: <?= htmlspecialchars($u['matricula']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if ($u['setor_nome']): ?>
                                    <span class="badge-perfil" style="background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;"><?= htmlspecialchars($u['setor_nome']) ?></span>
                                <?php else: ?>
                                    <span style="color:#94a3b8;font-size:12px;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($u['id_perfil'] && $u['perfil_nome']): ?>
                                    <span class="badge-perfil <?= $bClass ?>"><?= htmlspecialchars($u['perfil_nome']) ?></span>
                                <?php else: ?>
                                    <span class="badge-perfil badge-perfil-custom">Personalizado</span>
                                <?php endif; ?>
                                <?php if ($u['tem_excecoes']): ?>
                                    <span style="font-size:10px;color:#ca8a04;font-weight:700;display:block;margin-top:2px;">(<?= $u['exc_count'] ?> ajuste<?= $u['exc_count'] > 1 ? 's' : '' ?>)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$u['modulos_ativos']): ?>
                                    <span style="color:#dc2626;font-size:11.5px;font-weight:600;">Sem acesso</span>
                                <?php else: ?>
                                    <div style="display:flex;flex-wrap:wrap;gap:2px;max-width:340px;">
                                        <?php foreach ($u['modulos_ativos'] as $mod): ?>
                                            <span class="badge-mod-tag"><?= htmlspecialchars($mod) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <span class="badge-status <?= $u['status'] === 'ativo' ? 'is-ativo' : 'is-inativo' ?>">
                                    <span class="dot"></span>
                                    <span><?= $u['status'] === 'ativo' ? 'Ativo' : 'Inativo' ?></span>
                                </span>
                            </td>
                            <td style="text-align:center;">
                                <?php if ($canEdit): ?>
                                    <div style="display:flex;gap:6px;justify-content:center;align-items:center;">
                                        <button type="button" class="btn-icon btn-icon-edit js-btn-editar-usuario" data-user='<?= htmlspecialchars(json_encode($u, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>' title="Editar Colaborador">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                        </button>
                                        <button type="button" class="btn-icon js-btn-reset-senha" data-id="<?= $u['id'] ?>" data-nome="<?= htmlspecialchars($u['nome']) ?>" title="Redefinir Senha">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                        </button>
                                        <?php if ((int)$u['id'] !== $curUserId): ?>
                                            <button type="button" class="btn-icon btn-icon-danger js-btn-excluir-usuario" data-id="<?= $u['id'] ?>" data-nome="<?= htmlspecialchars($u['nome']) ?>" title="Excluir Colaborador">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color:#94a3b8;font-size:12px;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    <tr class="js-user-empty-row" style="display:none;"><td colspan="7" style="text-align:center;padding:30px;color:#64748b;">Nenhum usuário encontrado com os filtros aplicados.</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════════ -->
    <!-- ABA 2: SETORES DA FÁBRICA                                                   -->
    <!-- ═══════════════════════════════════════════════════════════════════════════ -->
    <div class="admin-pane-card js-admin-tab-pane" id="pane-setores" style="<?= $abaInicial !== 'setores' ? 'display:none;' : '' ?>">
        
        <div class="admin-toolbar">
            <div class="admin-toolbar-left">
                <input type="search" id="filtro-setor-busca" placeholder="Buscar setor por nome, sigla ou responsável..." style="min-width:280px;">
            </div>
            <div class="admin-toolbar-right">
                <?php if ($canEdit): ?>
                    <button type="button" class="btn-action-primary js-btn-novo-setor">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:14px;height:14px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        <span>Novo Setor</span>
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="admin-table-scroll">
            <table class="table-admin">
                <thead>
                    <tr>
                        <th style="width:80px;">Sigla</th>
                        <th>Nome do Setor</th>
                        <th>Responsável</th>
                        <th>Centro de Custo</th>
                        <th style="text-align:center;">Colaboradores</th>
                        <th style="width:90px;text-align:center;">Status</th>
                        <th style="width:100px;text-align:center;">Ações</th>
                    </tr>
                </thead>
                <tbody id="tbody-setores">
                    <?php if (!$setores): ?>
                        <tr><td colspan="7" style="text-align:center;padding:30px;color:#64748b;">Nenhum setor cadastrado.</td></tr>
                    <?php else: foreach ($setores as $s):
                        $sSearch = mb_strtolower($s['nome'] . ' ' . $s['cod'] . ' ' . $s['resp'] . ' ' . $s['cc']);
                    ?>
                        <tr class="js-row-setor" data-search="<?= htmlspecialchars($sSearch) ?>">
                            <td><span class="mono-code"><?= htmlspecialchars($s['cod']) ?></span></td>
                            <td><strong style="font-size:13.5px;color:#0f172a;"><?= htmlspecialchars($s['nome']) ?></strong></td>
                            <td><?= $s['resp'] ? htmlspecialchars($s['resp']) : '<span style="color:#94a3b8;">—</span>' ?></td>
                            <td><?= $s['cc'] ? '<span class="mono-code">' . htmlspecialchars($s['cc']) . '</span>' : '<span style="color:#94a3b8;">—</span>' ?></td>
                            <td style="text-align:center;">
                                <a href="javascript:void(0)" class="js-link-filtra-setor badge-perfil" style="background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;cursor:pointer;" data-setor-nome="<?= htmlspecialchars($s['nome']) ?>" title="Ver usuários deste setor">
                                    <?= $s['usuarios'] ?> <?= $s['usuarios'] === 1 ? 'colaborador' : 'colaboradores' ?>
                                </a>
                            </td>
                            <td style="text-align:center;">
                                <span class="badge-status <?= $s['status'] === 'ativo' ? 'is-ativo' : 'is-inativo' ?>">
                                    <span class="dot"></span>
                                    <span><?= $s['status'] === 'ativo' ? 'Ativo' : 'Inativo' ?></span>
                                </span>
                            </td>
                            <td style="text-align:center;">
                                <?php if ($canEdit): ?>
                                    <div style="display:flex;gap:6px;justify-content:center;align-items:center;">
                                        <button type="button" class="btn-icon btn-icon-edit js-btn-editar-setor" data-setor='<?= htmlspecialchars(json_encode($s, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>' title="Editar Setor">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                        </button>
                                        <?php if ($s['usuarios'] === 0): ?>
                                            <button type="button" class="btn-icon btn-icon-danger js-btn-excluir-setor" data-id="<?= $s['id'] ?>" data-nome="<?= htmlspecialchars($s['nome']) ?>" title="Excluir Setor">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color:#94a3b8;font-size:12px;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    <tr class="js-setor-empty-row" style="display:none;"><td colspan="7" style="text-align:center;padding:30px;color:#64748b;">Nenhum setor encontrado.</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════════ -->
    <!-- ABA 3: PERFIS / TEMPLATES DE ACESSO                                         -->
    <!-- ═══════════════════════════════════════════════════════════════════════════ -->
    <div class="admin-pane-card js-admin-tab-pane" id="pane-perfis" style="<?= $abaInicial !== 'perfis' ? 'display:none;' : '' ?>">
        
        <div class="admin-toolbar">
            <div class="admin-toolbar-left">
                <input type="search" id="filtro-perfil-busca" placeholder="Buscar perfil ou descrição..." style="min-width:280px;">
            </div>
            <div class="admin-toolbar-right">
                <?php if ($canEdit): ?>
                    <button type="button" class="btn-action-primary js-btn-novo-perfil">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:14px;height:14px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        <span>Novo Perfil</span>
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="admin-table-scroll">
            <table class="table-admin">
                <thead>
                    <tr>
                        <th style="width:90px;">Código</th>
                        <th>Perfil / Função</th>
                        <th>Descrição</th>
                        <th>Módulos Incluídos</th>
                        <th style="text-align:center;">Usuários</th>
                        <th style="width:90px;text-align:center;">Status</th>
                        <th style="width:100px;text-align:center;">Ações</th>
                    </tr>
                </thead>
                <tbody id="tbody-perfis">
                    <?php if (!$perfis): ?>
                        <tr><td colspan="7" style="text-align:center;padding:30px;color:#64748b;">Nenhum perfil cadastrado.</td></tr>
                    <?php else: foreach ($perfis as $p):
                        $mods = [];
                        foreach ($SISTEMAS_ESTRUTURA as $sys) {
                            $temSys = false;
                            foreach ($sys['modulos'] as $mod) {
                                foreach ($mod['telas'] as $t) {
                                    if (($p['perms'][$t[0]] ?? 'off') !== 'off') {
                                        $temSys = true;
                                        if ($sys['id'] === 'retrabalho') {
                                            $mods[] = $mod['nome'];
                                        }
                                    }
                                }
                            }
                            if ($temSys && $sys['id'] !== 'retrabalho') {
                                $mods[] = $sys['nome'];
                            }
                        }
                        $sSearch = mb_strtolower($p['nome'] . ' ' . $p['cod'] . ' ' . $p['descricao']);
                    ?>
                        <tr class="js-row-perfil" data-search="<?= htmlspecialchars($sSearch) ?>">
                            <td><span class="mono-code"><?= htmlspecialchars($p['cod']) ?></span></td>
                            <td>
                                <strong style="font-size:13.5px;color:#0f172a;"><?= htmlspecialchars($p['nome']) ?></strong>
                                <?php if ($p['sistema']): ?>
                                    <span style="font-size:10px;font-weight:700;color:#64748b;background:#f1f5f9;padding:1px 5px;border-radius:4px;margin-left:4px;">SISTEMA</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:12px;color:#64748b;max-width:240px;"><?= htmlspecialchars($p['descricao'] ?: '—') ?></td>
                            <td>
                                <div style="display:flex;flex-wrap:wrap;gap:2px;">
                                    <?php foreach (array_unique($mods) as $m): ?>
                                        <span class="badge-mod-tag"><?= htmlspecialchars($m) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td style="text-align:center;">
                                <span class="badge-perfil" style="background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;"><?= $p['usuarios'] ?> <?= $p['usuarios'] === 1 ? 'usuário' : 'usuários' ?></span>
                            </td>
                            <td style="text-align:center;">
                                <span class="badge-status <?= $p['status'] === 'ativo' ? 'is-ativo' : 'is-inativo' ?>">
                                    <span class="dot"></span>
                                    <span><?= $p['status'] === 'ativo' ? 'Ativo' : 'Inativo' ?></span>
                                </span>
                            </td>
                            <td style="text-align:center;">
                                <?php if ($canEdit): ?>
                                    <div style="display:flex;gap:6px;justify-content:center;align-items:center;">
                                        <button type="button" class="btn-icon btn-icon-edit js-btn-editar-perfil" data-perfil='<?= htmlspecialchars(json_encode($p, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>' title="Editar Perfil">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                        </button>
                                        <?php if (!$p['sistema'] && $p['usuarios'] === 0): ?>
                                            <button type="button" class="btn-icon btn-icon-danger js-btn-excluir-perfil" data-id="<?= $p['id'] ?>" data-nome="<?= htmlspecialchars($p['nome']) ?>" title="Excluir Perfil">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color:#94a3b8;font-size:12px;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    <tr class="js-perfil-empty-row" style="display:none;"><td colspan="7" style="text-align:center;padding:30px;color:#64748b;">Nenhum perfil encontrado.</td></tr>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- ═══════════════════════════════════════════════════════════════════════════════ -->
<!-- MODAL DE USUÁRIO (Cadastro & Edição)                                           -->
<!-- ═══════════════════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-usuario" style="display:none;">
    <div class="admin-modal-card">
        <div class="admin-modal-head">
            <div>
                <h2 style="font-size:16px;font-weight:700;margin:0;color:#0f172a;" id="m-user-title">Novo Colaborador</h2>
                <p style="font-size:12px;color:#64748b;margin:2px 0 0;">Cadastre os dados e atribua um perfil ou marque as permissões manualmente.</p>
            </div>
            <button type="button" class="btn-icon js-close-modal" style="border:none;background:transparent;font-size:18px;line-height:1;">&times;</button>
        </div>

        <form id="form-usuario">
            <input type="hidden" name="id" id="u-id" value="0">

            <!-- Sub-tabs do Modal -->
            <div style="display:flex;border-bottom:1px solid #e5e7eb;background:#f8fafc;padding:0 20px;flex-shrink:0;">
                <button type="button" class="js-user-subtab is-active" data-subtab="dados" style="padding:10px 16px;border:none;background:none;font-size:13px;font-weight:700;color:#E89B1C;border-bottom:2px solid #E89B1C;cursor:pointer;">
                    1. Dados do Colaborador
                </button>
                <button type="button" class="js-user-subtab" data-subtab="acessos" style="padding:10px 16px;border:none;background:none;font-size:13px;font-weight:600;color:#64748b;border-bottom:2px solid transparent;cursor:pointer;">
                    2. Permissões &amp; Módulos
                </button>
            </div>

            <div class="admin-modal-body">
                <div id="u-error" class="alert alert-danger" style="display:none;margin-bottom:14px;padding:8px 12px;font-size:12.5px;color:#dc2626;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;flex-shrink:0;"></div>

                <!-- Sub-tab 1: Dados -->
                <div id="user-subtab-dados" style="overflow-y:auto;flex:1;">
                    <div class="form-grid-2">
                        <div class="form-group form-full">
                            <label for="u-nome">Nome Completo *</label>
                            <input type="text" id="u-nome" name="nome" placeholder="Ex.: Carlos Eduardo Souza" required>
                        </div>
                        <div class="form-group">
                            <label for="u-email">E-mail Profissional *</label>
                            <input type="email" id="u-email" name="email" placeholder="carlos.souza@trael.com.br" required>
                        </div>
                        <div class="form-group">
                            <label for="u-cpf">CPF <span style="font-weight:400;color:#64748b;">(Opcional)</span></label>
                            <input type="text" id="u-cpf" name="cpf" placeholder="000.000.000-00" maxlength="14">
                        </div>
                        <div class="form-group">
                            <label for="u-mat">Matrícula / Crachá <span style="font-weight:400;color:#64748b;">(Opcional)</span></label>
                            <input type="text" id="u-mat" name="mat" placeholder="Ex.: 4509">
                        </div>
                        <div class="form-group">
                            <label for="u-senha">Senha de Acesso <span id="u-senha-hint" style="font-weight:400;color:#64748b;">(Obrigatória)</span></label>
                            <input type="password" id="u-senha" name="senha" placeholder="Mínimo 4 caracteres">
                        </div>
                        <div class="form-group">
                            <label for="u-setor">Setor da Fábrica</label>
                            <select id="u-setor" name="setor">
                                <option value="">Sem setor definido</option>
                                <?php foreach ($setores as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['nome']) ?> (<?= htmlspecialchars($s['cod']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="u-status">Status da Conta</label>
                            <select id="u-status" name="status">
                                <option value="ativo">Ativo (Acesso Liberado)</option>
                                <option value="inativo">Inativo (Acesso Bloqueado)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Sub-tab 2: Permissões & Módulos -->
                <div id="user-subtab-acessos" style="display:none;flex-direction:column;flex:1;min-height:0;overflow:hidden;">
                    
                    <!-- 🔒 PARTE TRAVADA NO TOPO: Perfil Base e Botões de Controle -->
                    <div style="flex-shrink:0;padding-bottom:10px;border-bottom:1px solid #e2e8f0;margin-bottom:10px;">
                        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 14px;margin-bottom:8px;">
                            <label style="font-size:12px;font-weight:700;color:#0f172a;display:block;margin-bottom:4px;">
                                Perfil Base de Acesso:
                            </label>
                            <select id="u-perfil" name="perfil" style="width:100%;padding:7px 10px;border-radius:6px;border:1px solid #cbd5e1;font-size:13px;">
                                <option value="">Sem perfil fixo (Alocação Manual Direta)</option>
                                <?php foreach ($perfis as $p): ?>
                                    <option value="<?= $p['id'] ?>" data-perms='<?= htmlspecialchars(json_encode($p['perms']), ENT_QUOTES) ?>'>
                                        <?= htmlspecialchars($p['nome']) ?> (<?= htmlspecialchars($p['cod']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span style="font-size:11px;color:#64748b;display:block;margin-top:3px;">
                                💡 Escolha um perfil para pré-carregar permissões ou personalize os módulos e telas individualmente abaixo.
                            </span>
                        </div>

                        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                            <span style="font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#475569;">
                                Sistemas da Fábrica &amp; Módulos:
                            </span>
                            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                <button type="button" class="btn-row-action js-user-expand-all" style="font-size:11px;padding:3px 8px;">Expandir Todos</button>
                                <button type="button" class="btn-row-action js-user-collapse-all" style="font-size:11px;padding:3px 8px;">Recolher Todos</button>
                                <button type="button" class="btn-row-action js-user-quick-all" data-level="total" style="font-size:11px;padding:3px 8px;">Liberar Todos</button>
                                <button type="button" class="btn-row-action js-user-quick-all" data-level="off" style="font-size:11px;padding:3px 8px;">Bloquear Todos</button>
                            </div>
                        </div>
                    </div>

                    <!-- 📜 LISTA ROLÁVEL EM ACCORDION: Sistemas -> Módulos -> Telas -->
                    <div id="user-matrix-wrap" style="flex:1;min-height:0;overflow-y:auto;padding-right:4px;">
                        <?php foreach ($SISTEMAS_ESTRUTURA as $sys): 
                            $isExpanded = !empty($sys['aberto_padrao']);
                        ?>
                            <div class="system-accordion-card js-sys-card <?= $isExpanded ? 'is-expanded' : '' ?>" data-sys="<?= $sys['id'] ?>">
                                
                                <!-- Cabeçalho do Sistema (Clique para expandir) -->
                                <div class="system-accordion-header js-sys-header">
                                    <div class="system-header-left">
                                        <div class="system-icon-box" style="color:<?= $sys['badge_color'] ?>;">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;"><?= $sys['svg'] ?></svg>
                                        </div>
                                        <div>
                                            <div class="system-header-title">
                                                <span><?= htmlspecialchars($sys['nome']) ?></span>
                                                <span class="system-badge-pill"><?= htmlspecialchars($sys['badge']) ?></span>
                                            </div>
                                            <div style="font-size:11.5px;color:#64748b;margin-top:1px;"><?= htmlspecialchars($sys['desc']) ?></div>
                                        </div>
                                    </div>
                                    <div class="system-header-right">
                                        <button type="button" class="btn-row-action js-sys-toggle-all" data-sys="<?= $sys['id'] ?>" onclick="event.stopPropagation();" style="font-size:10.5px;padding:2px 7px;">
                                            Marcar Sistema
                                        </button>
                                        <button type="button" class="btn-toggle-accordion js-sys-toggle-btn">
                                            <span><?= $isExpanded ? 'Recolher' : 'Expandir' ?></span>
                                            <svg class="chevron-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                                        </button>
                                    </div>
                                </div>

                                <!-- Conteúdo Expansível: Módulos e Telas do Sistema -->
                                <div class="system-accordion-body">
                                    <div style="display:flex;flex-direction:column;gap:8px;">
                                        <?php foreach ($sys['modulos'] as $mod): ?>
                                            <div class="module-group-box">
                                                <div class="module-group-head">
                                                    <span class="module-group-title">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px;color:#E89B1C;"><?= $mod['svg'] ?></svg>
                                                        <span><?= htmlspecialchars($mod['nome']) ?></span>
                                                    </span>
                                                    <button type="button" class="btn-row-action js-user-toggle-area" data-area="<?= $mod['id'] ?>" style="font-size:10px;padding:1px 6px;">
                                                        Marcar Módulo
                                                    </button>
                                                </div>
                                                <div style="display:flex;flex-direction:column;gap:4px;">
                                                    <?php foreach ($mod['telas'] as $tela): ?>
                                                        <div class="screen-perm-item">
                                                            <span><?= htmlspecialchars($tela[1]) ?></span>
                                                            <select class="js-user-perm-select" data-screen="<?= $tela[0] ?>" data-area="<?= $mod['id'] ?>" data-sys="<?= $sys['id'] ?>">
                                                                <option value="off">Sem Acesso</option>
                                                                <option value="view">Apenas Consulta</option>
                                                                <option value="total">Acesso Completo</option>
                                                            </select>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                            </div>
                        <?php endforeach; ?>
                    </div>

                </div>

            </div>

            <div class="admin-modal-foot">
                <button type="button" class="btn btn-secondary js-close-modal">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="btn-submit-user">Salvar Colaborador</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════════ -->
<!-- MODAL DE SETOR DA FÁBRICA                                                     -->
<!-- ═══════════════════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-setor" style="display:none;">
    <div class="modal" style="max-width:480px;">
        <div class="modal-header">
            <div>
                <span class="modal-title" id="m-setor-title">Novo Setor</span>
                <p style="font-size:12px;color:#64748b;margin:2px 0 0;">Cadastre um setor ou posto de trabalho operacional.</p>
            </div>
            <button type="button" class="modal-close js-close-modal">&times;</button>
        </div>

        <form id="form-setor">
            <input type="hidden" name="id" id="s-id" value="0">
            <div class="modal-body" style="overflow-y:auto;max-height:65vh;">
                <div id="s-error" class="alert alert-danger" style="display:none;margin-bottom:14px;padding:8px 12px;font-size:12.5px;color:#dc2626;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;"></div>
                
                <div class="form-group">
                    <label for="s-nome">Nome do Setor *</label>
                    <input type="text" id="s-nome" name="nome" placeholder="Ex.: Inspeção Final" required>
                </div>
                <div class="form-grid-2">
                    <div class="form-group">
                        <label for="s-cod">Sigla / Código *</label>
                        <input type="text" id="s-cod" name="cod" placeholder="Ex.: IQF" style="text-transform:uppercase;" required>
                    </div>
                    <div class="form-group">
                        <label for="s-cc">Centro de Custo</label>
                        <input type="text" id="s-cc" name="cc" placeholder="Ex.: 3120">
                    </div>
                </div>
                <div class="form-group">
                    <label for="s-resp">Responsável pelo Setor</label>
                    <input type="text" id="s-resp" name="resp" placeholder="Ex.: Sônia Ribeiro">
                </div>
                <div class="form-group">
                    <label for="s-status">Status</label>
                    <select id="s-status" name="status">
                        <option value="ativo">Ativo</option>
                        <option value="inativo">Inativo</option>
                    </select>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary js-close-modal">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="btn-submit-setor">Salvar Setor</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════════ -->
<!-- MODAL DE PERFIL / TEMPLATE                                                     -->
<!-- ═══════════════════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-perfil" style="display:none;">
    <div class="admin-modal-card">
        <div class="admin-modal-head">
            <div>
                <h2 style="font-size:16px;font-weight:700;margin:0;color:#0f172a;" id="m-perfil-title">Novo Perfil / Cargo</h2>
                <p style="font-size:12px;color:#64748b;margin:2px 0 0;">Crie templates de permissões para atribuir a múltiplos colaboradores.</p>
            </div>
            <button type="button" class="btn-icon js-close-modal" style="border:none;background:transparent;font-size:18px;line-height:1;">&times;</button>
        </div>

        <form id="form-perfil">
            <input type="hidden" name="id" id="p-id" value="0">
            <div class="admin-modal-body" style="overflow:hidden;">
                <div id="p-error" class="alert alert-danger" style="display:none;margin-bottom:14px;padding:8px 12px;font-size:12.5px;color:#dc2626;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;flex-shrink:0;"></div>

                <div style="overflow-y:auto;flex:1;padding-right:4px;">
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label for="p-nome">Nome do Perfil *</label>
                            <input type="text" id="p-nome" name="nome" placeholder="Ex.: Inspetor Final" required>
                        </div>
                        <div class="form-group">
                            <label for="p-cod">Código / Sigla *</label>
                            <input type="text" id="p-cod" name="cod" placeholder="Ex.: OPE-IQF" style="text-transform:uppercase;" required>
                        </div>
                        <div class="form-group">
                            <label for="p-grupo">Nível / Grupo</label>
                            <select id="p-grupo" name="grupo">
                                <option value="OPE">Operador</option>
                                <option value="SUP">Supervisor</option>
                                <option value="GES">Gestor</option>
                                <option value="ADM">Administrador</option>
                                <option value="VIS">Visualizador</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="p-status">Status</label>
                            <select id="p-status" name="status">
                                <option value="ativo">Ativo</option>
                                <option value="inativo">Inativo</option>
                            </select>
                        </div>
                        <div class="form-group form-full">
                            <label for="p-desc">Descrição das Funções</label>
                            <textarea id="p-desc" name="desc" rows="2" placeholder="Descreva as atribuições deste perfil..."></textarea>
                        </div>
                    </div>

                    <div style="display:flex;align-items:center;justify-content:space-between;margin:12px 0 8px;flex-wrap:wrap;gap:6px;">
                        <span style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#64748b;">Módulos do Perfil:</span>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                            <button type="button" class="btn-row-action js-perfil-expand-all" style="font-size:11px;padding:3px 8px;">Expandir Todos</button>
                            <button type="button" class="btn-row-action js-perfil-collapse-all" style="font-size:11px;padding:3px 8px;">Recolher Todos</button>
                            <button type="button" class="btn-row-action js-perfil-quick-all" data-level="total" style="font-size:11px;padding:3px 8px;">Liberar Todos</button>
                            <button type="button" class="btn-row-action js-perfil-quick-all" data-level="off" style="font-size:11px;padding:3px 8px;">Bloquear Todos</button>
                        </div>
                    </div>

                    <div id="perfil-matrix-wrap">
                        <?php foreach ($SISTEMAS_ESTRUTURA as $sys): 
                            $isExpanded = !empty($sys['aberto_padrao']);
                        ?>
                            <div class="system-accordion-card js-sys-card <?= $isExpanded ? 'is-expanded' : '' ?>" data-sys="<?= $sys['id'] ?>">
                                
                                <div class="system-accordion-header js-sys-header">
                                    <div class="system-header-left">
                                        <div class="system-icon-box" style="color:<?= $sys['badge_color'] ?>;">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;"><?= $sys['svg'] ?></svg>
                                        </div>
                                        <div>
                                            <div class="system-header-title">
                                                <span><?= htmlspecialchars($sys['nome']) ?></span>
                                                <span class="system-badge-pill"><?= htmlspecialchars($sys['badge']) ?></span>
                                            </div>
                                            <div style="font-size:11.5px;color:#64748b;margin-top:1px;"><?= htmlspecialchars($sys['desc']) ?></div>
                                        </div>
                                    </div>
                                    <div class="system-header-right">
                                        <button type="button" class="btn-row-action js-perfil-toggle-sys" data-sys="<?= $sys['id'] ?>" onclick="event.stopPropagation();" style="font-size:10.5px;padding:2px 7px;">
                                            Marcar Sistema
                                        </button>
                                        <button type="button" class="btn-toggle-accordion js-sys-toggle-btn">
                                            <span><?= $isExpanded ? 'Recolher' : 'Expandir' ?></span>
                                            <svg class="chevron-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                                        </button>
                                    </div>
                                </div>

                                <div class="system-accordion-body">
                                    <div style="display:flex;flex-direction:column;gap:8px;">
                                        <?php foreach ($sys['modulos'] as $mod): ?>
                                            <div class="module-group-box">
                                                <div class="module-group-head">
                                                    <span class="module-group-title">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px;color:#E89B1C;"><?= $mod['svg'] ?></svg>
                                                        <span><?= htmlspecialchars($mod['nome']) ?></span>
                                                    </span>
                                                    <button type="button" class="btn-row-action js-perfil-toggle-area" data-area="<?= $mod['id'] ?>" style="font-size:10px;padding:1px 6px;">
                                                        Marcar Módulo
                                                    </button>
                                                </div>
                                                <div style="display:flex;flex-direction:column;gap:4px;">
                                                    <?php foreach ($mod['telas'] as $tela): ?>
                                                        <div class="screen-perm-item">
                                                            <span><?= htmlspecialchars($tela[1]) ?></span>
                                                            <select class="js-perfil-perm-select" data-screen="<?= $tela[0] ?>" data-area="<?= $mod['id'] ?>" data-sys="<?= $sys['id'] ?>">
                                                                <option value="off">Sem Acesso</option>
                                                                <option value="view">Apenas Consulta</option>
                                                                <option value="total">Acesso Completo</option>
                                                            </select>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="admin-modal-foot">
                <button type="button" class="btn btn-secondary js-close-modal">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="btn-submit-perfil">Salvar Perfil</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════════ -->
<!-- MODAL DE REDEFINIÇÃO RÁPIDA DE SENHA                                           -->
<!-- ═══════════════════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-reset-senha" style="display:none;">
    <div class="modal" style="max-width:440px;">
        <div class="modal-header">
            <div>
                <span class="modal-title">Redefinir Senha</span>
                <p style="font-size:12px;color:#64748b;margin:2px 0 0;" id="m-reset-user-name">—</p>
            </div>
            <button type="button" class="modal-close js-close-modal">&times;</button>
        </div>

        <form id="form-reset-senha">
            <input type="hidden" id="reset-id" value="0">
            <div class="modal-body" style="overflow-y:auto;">
                <div id="reset-error" class="alert alert-danger" style="display:none;margin-bottom:14px;padding:8px 12px;font-size:12.5px;color:#dc2626;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;"></div>
                
                <div class="form-group">
                    <label for="reset-senha-nova">Nova Senha *</label>
                    <input type="password" id="reset-senha-nova" placeholder="Digite a nova senha" required minlength="4">
                </div>
                <div style="margin-top:8px;">
                    <button type="button" class="btn btn-secondary" id="btn-gerar-senha" style="font-size:12px;padding:5px 10px;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px;"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                        <span>Gerar senha padrão (123456)</span>
                    </button>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary js-close-modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Atualizar Senha</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════════ -->
<!-- MODAL DE CONFIRMAÇÃO DE EXCLUSÃO (PADRÃO SGT)                                   -->
<!-- ═══════════════════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-confirm-delete" style="display:none;">
    <div class="modal" style="max-width:440px;">
        <div class="modal-header">
            <span class="modal-title" id="confirm-del-title">Confirmar Exclusão</span>
            <button type="button" class="modal-close js-close-modal">&times;</button>
        </div>
        <div class="modal-body">
            <p style="font-size:var(--font-size-base,14px);color:var(--color-text-secondary,#6b7280);margin:0 0 12px;line-height:1.5;" id="confirm-del-msg">
                Deseja realmente excluir este registro?
            </p>
            <div id="confirm-del-error" class="alert alert-danger" style="display:none;margin-top:10px;padding:8px 12px;font-size:12.5px;color:#dc2626;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary js-close-modal">Cancelar</button>
            <button type="button" class="btn btn-danger" id="btn-exec-delete">Excluir Registro</button>
        </div>
    </div>
</div>

<script>
    window.ADMIN_API = <?= json_encode($base . '/api/admin-v2-acao.php') ?>;
    window.PERFIS_MAP = <?= json_encode($perfis, JSON_UNESCAPED_UNICODE) ?>;
</script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const API = window.ADMIN_API;
    const PERFIS = window.PERFIS_MAP || {};

    // ─── 1. Alternância de Abas Principais ─────────────────────────────────────────
    const tabButtons = document.querySelectorAll('.admin-switch-btn');
    const tabPanes = document.querySelectorAll('.js-admin-tab-pane');

    function switchTab(tabId) {
        tabButtons.forEach(btn => btn.classList.toggle('is-active', btn.dataset.tab === tabId));
        tabPanes.forEach(pane => pane.style.display = (pane.id === `pane-${tabId}`) ? 'flex' : 'none');
        const url = new URL(window.location);
        url.searchParams.set('aba', tabId);
        window.history.replaceState({}, '', url);
    }

    tabButtons.forEach(btn => btn.addEventListener('click', () => switchTab(btn.dataset.tab)));

    document.querySelectorAll('.js-link-filtra-setor').forEach(link => {
        link.addEventListener('click', () => {
            const setor = link.dataset.setorNome;
            switchTab('usuarios');
            const sel = document.getElementById('filtro-user-setor');
            if (sel) {
                sel.value = setor;
                sel.dispatchEvent(new Event('change'));
            }
        });
    });

    // ─── 2. Busca e Filtros ───────────────────────────────────────────────────────
    // Usuários
    const inputBuscaUser = document.getElementById('filtro-user-busca');
    const selectSetorUser = document.getElementById('filtro-user-setor');
    const selectStatusUser = document.getElementById('filtro-user-status');
    const rowsUser = document.querySelectorAll('.js-row-user');
    const emptyRowUser = document.querySelector('.js-user-empty-row');

    function filterUsers() {
        const q = (inputBuscaUser?.value || '').toLowerCase().trim();
        const sSetor = (selectSetorUser?.value || '').toLowerCase();
        const sStatus = (selectStatusUser?.value || '').toLowerCase();

        let visible = 0;
        rowsUser.forEach(r => {
            const search = (r.dataset.search || '');
            const rSetor = (r.dataset.setor || '').toLowerCase();
            const rStatus = (r.dataset.status || '').toLowerCase();

            const matchQ = !q || search.includes(q);
            const matchSetor = !sSetor || rSetor === sSetor;
            const matchStatus = !sStatus || rStatus === sStatus;

            if (matchQ && matchSetor && matchStatus) {
                r.style.display = '';
                visible++;
            } else {
                r.style.display = 'none';
            }
        });
        if (emptyRowUser) emptyRowUser.style.display = (visible === 0) ? '' : 'none';
    }

    inputBuscaUser?.addEventListener('input', filterUsers);
    selectSetorUser?.addEventListener('change', filterUsers);
    selectStatusUser?.addEventListener('change', filterUsers);

    // Setores
    const inputBuscaSetor = document.getElementById('filtro-setor-busca');
    inputBuscaSetor?.addEventListener('input', () => {
        const q = inputBuscaSetor.value.toLowerCase().trim();
        let visible = 0;
        document.querySelectorAll('.js-row-setor').forEach(r => {
            const match = !q || (r.dataset.search || '').includes(q);
            r.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        const empty = document.querySelector('.js-setor-empty-row');
        if (empty) empty.style.display = (visible === 0) ? '' : 'none';
    });

    // Perfis
    const inputBuscaPerfil = document.getElementById('filtro-perfil-busca');
    inputBuscaPerfil?.addEventListener('input', () => {
        const q = inputBuscaPerfil.value.toLowerCase().trim();
        let visible = 0;
        document.querySelectorAll('.js-row-perfil').forEach(r => {
            const match = !q || (r.dataset.search || '').includes(q);
            r.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        const empty = document.querySelector('.js-perfil-empty-row');
        if (empty) empty.style.display = (visible === 0) ? '' : 'none';
    });

    // ─── 3. Funções de Modal ─────────────────────────────────────────────────────
    function openModal(id) {
        const el = document.getElementById(id);
        if (el) el.style.display = 'flex';
    }
    function closeModal(id) {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    }

    document.querySelectorAll('.js-close-modal').forEach(b => {
        b.addEventListener('click', () => {
            const overlay = b.closest('.modal-overlay');
            if (overlay) overlay.style.display = 'none';
        });
    });
    document.querySelectorAll('.modal-overlay').forEach(ov => {
        ov.addEventListener('click', (e) => {
            if (e.target === ov) ov.style.display = 'none';
        });
    });

    // Subtabs no modal de Usuário
    document.querySelectorAll('.js-user-subtab').forEach(b => {
        b.addEventListener('click', () => {
            const sub = b.dataset.subtab;
            document.querySelectorAll('.js-user-subtab').forEach(x => {
                const act = x === b;
                x.classList.toggle('is-active', act);
                x.style.color = act ? '#E89B1C' : '#64748b';
                x.style.borderBottomColor = act ? '#E89B1C' : 'transparent';
                x.style.fontWeight = act ? '700' : '600';
            });
            document.getElementById('user-subtab-dados').style.display = (sub === 'dados') ? '' : 'none';
            document.getElementById('user-subtab-acessos').style.display = (sub === 'acessos') ? 'flex' : 'none';
        });
    });

    // ─── 4. Accordion Tree dos Sistemas ──────────────────────────────────────────
    function updateAccordionCard(card, expand) {
        if (!card) return;
        if (expand === undefined) {
            card.classList.toggle('is-expanded');
        } else if (expand) {
            card.classList.add('is-expanded');
        } else {
            card.classList.remove('is-expanded');
        }
        const isExp = card.classList.contains('is-expanded');
        const label = card.querySelector('.js-sys-toggle-btn span');
        if (label) label.textContent = isExp ? 'Recolher' : 'Expandir';
    }

    function setupAccordions(container) {
        if (!container) return;
        container.querySelectorAll('.js-sys-card').forEach(card => {
            const header = card.querySelector('.js-sys-header');
            if (header) {
                header.addEventListener('click', (e) => {
                    if (e.target.closest('.js-sys-toggle-all') || e.target.closest('.js-perfil-toggle-sys') || e.target.closest('select') || e.target.closest('input')) {
                        return;
                    }
                    updateAccordionCard(card);
                });
            }
            const isExp = card.classList.contains('is-expanded');
            const label = card.querySelector('.js-sys-toggle-btn span');
            if (label) label.textContent = isExp ? 'Recolher' : 'Expandir';
        });
    }

    setupAccordions(document.getElementById('modal-usuario'));
    setupAccordions(document.getElementById('modal-perfil'));

    // Expandir / Recolher Todos nos Modais
    document.querySelector('.js-user-expand-all')?.addEventListener('click', () => {
        document.querySelectorAll('#user-matrix-wrap .js-sys-card').forEach(c => updateAccordionCard(c, true));
    });
    document.querySelector('.js-user-collapse-all')?.addEventListener('click', () => {
        document.querySelectorAll('#user-matrix-wrap .js-sys-card').forEach(c => updateAccordionCard(c, false));
    });
    document.querySelector('.js-perfil-expand-all')?.addEventListener('click', () => {
        document.querySelectorAll('#perfil-matrix-wrap .js-sys-card').forEach(c => updateAccordionCard(c, true));
    });
    document.querySelector('.js-perfil-collapse-all')?.addEventListener('click', () => {
        document.querySelectorAll('#perfil-matrix-wrap .js-sys-card').forEach(c => updateAccordionCard(c, false));
    });

    // ─── 5. Mapeador de Permissões com Aliases ───────────────────────────────────
    function getEffectivePermLevel(permsObj, screen) {
        if (!permsObj) return 'off';
        let lvl = permsObj[screen];
        if (lvl === undefined) {
            if (screen === 'pcp.pri' && permsObj['ret.pri'] !== undefined) lvl = permsObj['ret.pri'];
            else if (screen === 'ret.pri' && permsObj['pcp.pri'] !== undefined) lvl = permsObj['pcp.pri'];
            else if (screen === 'ana.aco' && permsObj['ana.his'] !== undefined) lvl = permsObj['ana.his'];
            else if (screen === 'ana.his' && permsObj['ana.aco'] !== undefined) lvl = permsObj['ana.aco'];
            else if (screen === 'adm.usu' && (permsObj['adm.per'] !== undefined || permsObj['admin'] !== undefined)) lvl = permsObj['adm.per'] ?? permsObj['admin'];
        }
        return (lvl !== 'off' && lvl !== '' && lvl !== undefined) ? (lvl === 'view' ? 'view' : 'total') : 'off';
    }

    function setMatrixPerms(selectsList, permsObj) {
        selectsList.forEach(sel => {
            const screen = sel.dataset.screen;
            sel.value = getEffectivePermLevel(permsObj, screen);
        });
    }

    // ─── 6. Modal de Confirmação de Exclusão Padrão SGT ─────────────────────────
    let pendingDeleteAction = null;
    const deleteTitle = document.getElementById('confirm-del-title');
    const deleteMsg = document.getElementById('confirm-del-msg');
    const deleteError = document.getElementById('confirm-del-error');
    const btnExecDelete = document.getElementById('btn-exec-delete');

    function promptDelete({ title, message, btnText, onConfirm }) {
        if (deleteTitle) deleteTitle.textContent = title || 'Confirmar Exclusão';
        if (deleteMsg) deleteMsg.innerHTML = message || 'Deseja realmente excluir este registro?';
        if (deleteError) deleteError.style.display = 'none';
        pendingDeleteAction = onConfirm;
        btnExecDelete.disabled = false;
        btnExecDelete.textContent = btnText || 'Excluir Registro';
        openModal('modal-confirm-delete');
    }

    btnExecDelete?.addEventListener('click', async () => {
        if (!pendingDeleteAction) return;
        btnExecDelete.disabled = true;
        btnExecDelete.textContent = 'Excluindo...';
        try {
            await pendingDeleteAction((errMsg) => {
                if (deleteError) {
                    deleteError.textContent = errMsg;
                    deleteError.style.display = '';
                }
                btnExecDelete.disabled = false;
                btnExecDelete.textContent = 'Excluir Registro';
            });
        } catch (err) {
            if (deleteError) {
                deleteError.textContent = 'Erro de comunicação com o servidor.';
                deleteError.style.display = '';
            }
            btnExecDelete.disabled = false;
            btnExecDelete.textContent = 'Excluir Registro';
        }
    });

    // ─── 7. Gerenciamento de Usuários (Novo / Editar / Excluir) ─────────────────
    const formUser = document.getElementById('form-usuario');
    const uId = document.getElementById('u-id');
    const uNome = document.getElementById('u-nome');
    const uEmail = document.getElementById('u-email');
    const uCpf = document.getElementById('u-cpf');
    const uMat = document.getElementById('u-mat');
    const uSenha = document.getElementById('u-senha');
    const uSenhaHint = document.getElementById('u-senha-hint');
    const uSetor = document.getElementById('u-setor');
    const uStatus = document.getElementById('u-status');
    const uPerfil = document.getElementById('u-perfil');
    const uError = document.getElementById('u-error');
    const userPermSelects = document.querySelectorAll('.js-user-perm-select');

    // Máscara de CPF
    uCpf?.addEventListener('input', (e) => {
        let v = e.target.value.replace(/\D/g, '');
        if (v.length > 11) v = v.slice(0, 11);
        if (v.length > 9) v = v.replace(/(\d{3})(\d{3})(\d{3})(\d{1,2})/, '$1.$2.$3-$4');
        else if (v.length > 6) v = v.replace(/(\d{3})(\d{3})(\d{1,3})/, '$1.$2.$3');
        else if (v.length > 3) v = v.replace(/(\d{3})(\d{1,3})/, '$1.$2');
        e.target.value = v;
    });

    uPerfil?.addEventListener('change', () => {
        const pId = uPerfil.value;
        if (pId && PERFIS[pId]) {
            setMatrixPerms(userPermSelects, PERFIS[pId].perms || {});
        }
    });

    document.querySelectorAll('.js-user-quick-all').forEach(btn => {
        btn.addEventListener('click', () => {
            const lvl = btn.dataset.level || 'off';
            userPermSelects.forEach(sel => sel.value = lvl);
        });
    });

    document.querySelectorAll('.js-user-toggle-area').forEach(btn => {
        btn.addEventListener('click', () => {
            const areaId = btn.dataset.area;
            const selects = document.querySelectorAll(`.js-user-perm-select[data-area="${areaId}"]`);
            const allTotal = Array.from(selects).every(s => s.value === 'total');
            selects.forEach(s => s.value = allTotal ? 'off' : 'total');
        });
    });

    document.querySelectorAll('.js-sys-toggle-all').forEach(btn => {
        btn.addEventListener('click', () => {
            const sysId = btn.dataset.sys;
            const selects = document.querySelectorAll(`.js-user-perm-select[data-sys="${sysId}"]`);
            const allTotal = Array.from(selects).every(s => s.value === 'total');
            selects.forEach(s => s.value = allTotal ? 'off' : 'total');
        });
    });

    document.querySelector('.js-btn-novo-usuario')?.addEventListener('click', () => {
        uId.value = '0';
        uNome.value = '';
        uEmail.value = '';
        uCpf.value = '';
        uMat.value = '';
        uSenha.value = '';
        uSenha.required = true;
        if (uSenhaHint) uSenhaHint.textContent = '(Obrigatória)';
        uSetor.value = '';
        uStatus.value = 'ativo';
        uPerfil.value = '';
        setMatrixPerms(userPermSelects, {});
        if (uError) uError.style.display = 'none';

        document.getElementById('m-user-title').textContent = 'Novo Colaborador';
        document.querySelector('.js-user-subtab[data-subtab="dados"]')?.click();
        openModal('modal-usuario');
    });

    document.querySelectorAll('.js-btn-editar-usuario').forEach(btn => {
        btn.addEventListener('click', () => {
            const u = JSON.parse(btn.dataset.user || '{}');
            uId.value = u.id || '0';
            uNome.value = u.nome || '';
            uEmail.value = u.email || '';
            uCpf.value = u.cpf || '';
            uMat.value = u.matricula || '';
            uSenha.value = '';
            uSenha.required = false;
            if (uSenhaHint) uSenhaHint.textContent = '(Deixe em branco para manter a atual)';
            uSetor.value = u.id_setor || '';
            uStatus.value = u.status || 'ativo';
            uPerfil.value = u.id_perfil || '';
            setMatrixPerms(userPermSelects, u.perms_efetivas || {});
            if (uError) uError.style.display = 'none';

            document.getElementById('m-user-title').textContent = 'Editar Colaborador';
            document.querySelector('.js-user-subtab[data-subtab="dados"]')?.click();
            openModal('modal-usuario');
        });
    });

    formUser?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btnSave = document.getElementById('btn-submit-user');
        btnSave.disabled = true;
        btnSave.textContent = 'Salvando...';
        if (uError) uError.style.display = 'none';

        const pId = uPerfil ? uPerfil.value : '';
        const basePerms = (pId && PERFIS[pId]) ? (PERFIS[pId].perms || {}) : null;
        const permsObj = {};

        userPermSelects.forEach(sel => {
            const scr = sel.dataset.screen;
            const lvl = sel.value;

            if (basePerms !== null) {
                // Com perfil base: grava como exceção se for diferente do perfil base
                const baseLvl = getEffectivePermLevel(basePerms, scr);
                if (lvl !== baseLvl) {
                    permsObj[scr] = lvl;
                    if (scr === 'pcp.pri') permsObj['ret.pri'] = lvl;
                    if (scr === 'ana.aco') permsObj['ana.his'] = lvl;
                    if (scr === 'adm.usu') permsObj['adm.per'] = lvl;
                }
            } else {
                // Sem perfil fixo (alocação direta): grava todas as permissões ativas
                if (lvl !== 'off') {
                    permsObj[scr] = lvl;
                    if (scr === 'pcp.pri') permsObj['ret.pri'] = lvl;
                    if (scr === 'ana.aco') permsObj['ana.his'] = lvl;
                    if (scr === 'adm.usu') permsObj['adm.per'] = lvl;
                }
            }
        });

        const formData = new FormData(formUser);
        formData.append('acao', 'salvar_usuario');
        formData.append('exc', JSON.stringify(permsObj));

        try {
            const res = await fetch(API, { method: 'POST', body: formData });
            const data = await res.json();
            if (data.sucesso) {
                window.location.search = '?aba=usuarios';
            } else {
                if (uError) {
                    uError.textContent = data.erro || 'Erro ao salvar usuário.';
                    uError.style.display = '';
                }
            }
        } catch (err) {
            if (uError) {
                uError.textContent = 'Erro de conexão ao salvar usuário.';
                uError.style.display = '';
            }
        } finally {
            btnSave.disabled = false;
            btnSave.textContent = 'Salvar Colaborador';
        }
    });

    document.querySelectorAll('.js-btn-excluir-usuario').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id;
            const nome = btn.dataset.nome;
            promptDelete({
                title: 'Excluir Colaborador',
                message: `Deseja realmente excluir o colaborador <strong style="color:var(--color-text-primary);">${nome}</strong>?`,
                btnText: 'Excluir Colaborador',
                onConfirm: async (showError) => {
                    const formData = new FormData();
                    formData.append('acao', 'excluir_usuario');
                    formData.append('id', id);
                    const res = await fetch(API, { method: 'POST', body: formData });
                    const data = await res.json();
                    if (data.sucesso) {
                        window.location.search = '?aba=usuarios';
                    } else {
                        showError(data.erro || 'Não foi possível excluir o colaborador.');
                    }
                }
            });
        });
    });

    // ─── 8. Redefinição Rápida de Senha ──────────────────────────────────────────
    const formReset = document.getElementById('form-reset-senha');
    const resetIdInput = document.getElementById('reset-id');
    const resetSenhaInput = document.getElementById('reset-senha-nova');
    const resetNameLabel = document.getElementById('m-reset-user-name');
    const resetError = document.getElementById('reset-error');

    document.querySelectorAll('.js-btn-reset-senha').forEach(btn => {
        btn.addEventListener('click', () => {
            resetIdInput.value = btn.dataset.id || '0';
            resetNameLabel.textContent = `Colaborador: ${btn.dataset.nome || '—'}`;
            resetSenhaInput.value = '';
            if (resetError) resetError.style.display = 'none';
            openModal('modal-reset-senha');
        });
    });

    document.getElementById('btn-gerar-senha')?.addEventListener('click', () => {
        resetSenhaInput.value = '123456';
    });

    formReset?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = resetIdInput.value;
        const nova_senha = resetSenhaInput.value;
        if (!nova_senha) return;

        const formData = new FormData();
        formData.append('acao', 'redefinir_senha');
        formData.append('id', id);
        formData.append('nova_senha', nova_senha);

        try {
            const res = await fetch(API, { method: 'POST', body: formData });
            const data = await res.json();
            if (data.sucesso) {
                alert('Senha redefinida com sucesso!');
                closeModal('modal-reset-senha');
            } else {
                if (resetError) {
                    resetError.textContent = data.erro || 'Erro ao redefinir senha.';
                    resetError.style.display = '';
                }
            }
        } catch (err) {
            if (resetError) {
                resetError.textContent = 'Erro de conexão com o servidor.';
                resetError.style.display = '';
            }
        }
    });

    // ─── 9. Gerenciamento de Setores (Novo / Editar / Excluir) ───────────────────
    const formSetor = document.getElementById('form-setor');
    const sId = document.getElementById('s-id');
    const sNome = document.getElementById('s-nome');
    const sCod = document.getElementById('s-cod');
    const sCc = document.getElementById('s-cc');
    const sResp = document.getElementById('s-resp');
    const sStatus = document.getElementById('s-status');
    const sError = document.getElementById('s-error');

    document.querySelector('.js-btn-novo-setor')?.addEventListener('click', () => {
        sId.value = '0';
        sNome.value = '';
        sCod.value = '';
        sCc.value = '';
        sResp.value = '';
        sStatus.value = 'ativo';
        if (sError) sError.style.display = 'none';
        document.getElementById('m-setor-title').textContent = 'Novo Setor';
        openModal('modal-setor');
    });

    document.querySelectorAll('.js-btn-editar-setor').forEach(btn => {
        btn.addEventListener('click', () => {
            const s = JSON.parse(btn.dataset.setor || '{}');
            sId.value = s.id || '0';
            sNome.value = s.nome || '';
            sCod.value = s.cod || '';
            sCc.value = s.cc || '';
            sResp.value = s.resp || '';
            sStatus.value = s.status || 'ativo';
            if (sError) sError.style.display = 'none';
            document.getElementById('m-setor-title').textContent = 'Editar Setor';
            openModal('modal-setor');
        });
    });

    formSetor?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btnSave = document.getElementById('btn-submit-setor');
        btnSave.disabled = true;
        btnSave.textContent = 'Salvando...';

        const formData = new FormData(formSetor);
        formData.append('acao', 'salvar_setor');

        try {
            const res = await fetch(API, { method: 'POST', body: formData });
            const data = await res.json();
            if (data.sucesso) {
                window.location.search = '?aba=setores';
            } else {
                if (sError) {
                    sError.textContent = data.erro || 'Erro ao salvar setor.';
                    sError.style.display = '';
                }
            }
        } catch (err) {
            if (sError) {
                sError.textContent = 'Erro de conexão com o servidor.';
                sError.style.display = '';
            }
        } finally {
            btnSave.disabled = false;
            btnSave.textContent = 'Salvar Setor';
        }
    });

    document.querySelectorAll('.js-btn-excluir-setor').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id;
            const nome = btn.dataset.nome;
            promptDelete({
                title: 'Excluir Setor',
                message: `Deseja realmente excluir o setor <strong style="color:var(--color-text-primary);">${nome}</strong>?`,
                btnText: 'Excluir Setor',
                onConfirm: async (showError) => {
                    const formData = new FormData();
                    formData.append('acao', 'excluir_setor');
                    formData.append('id', id);
                    const res = await fetch(API, { method: 'POST', body: formData });
                    const data = await res.json();
                    if (data.sucesso) {
                        window.location.search = '?aba=setores';
                    } else {
                        showError(data.erro || 'Não foi possível excluir o setor.');
                    }
                }
            });
        });
    });

    // ─── 10. Gerenciamento de Perfis (Novo / Editar / Excluir) ───────────────────
    const formPerfil = document.getElementById('form-perfil');
    const pId = document.getElementById('p-id');
    const pNome = document.getElementById('p-nome');
    const pCod = document.getElementById('p-cod');
    const pGrupo = document.getElementById('p-grupo');
    const pStatus = document.getElementById('p-status');
    const pDesc = document.getElementById('p-desc');
    const pError = document.getElementById('p-error');
    const perfilPermSelects = document.querySelectorAll('.js-perfil-perm-select');

    document.querySelectorAll('.js-perfil-quick-all').forEach(btn => {
        btn.addEventListener('click', () => {
            const lvl = btn.dataset.level || 'off';
            perfilPermSelects.forEach(sel => sel.value = lvl);
        });
    });

    document.querySelectorAll('.js-perfil-toggle-area').forEach(btn => {
        btn.addEventListener('click', () => {
            const areaId = btn.dataset.area;
            const selects = document.querySelectorAll(`.js-perfil-perm-select[data-area="${areaId}"]`);
            const allTotal = Array.from(selects).every(s => s.value === 'total');
            selects.forEach(s => s.value = allTotal ? 'off' : 'total');
        });
    });

    document.querySelectorAll('.js-perfil-toggle-sys').forEach(btn => {
        btn.addEventListener('click', () => {
            const sysId = btn.dataset.sys;
            const selects = document.querySelectorAll(`.js-perfil-perm-select[data-sys="${sysId}"]`);
            const allTotal = Array.from(selects).every(s => s.value === 'total');
            selects.forEach(s => s.value = allTotal ? 'off' : 'total');
        });
    });

    document.querySelector('.js-btn-novo-perfil')?.addEventListener('click', () => {
        pId.value = '0';
        pNome.value = '';
        pCod.value = '';
        pGrupo.value = 'OPE';
        pStatus.value = 'ativo';
        pDesc.value = '';
        setMatrixPerms(perfilPermSelects, {});
        if (pError) pError.style.display = 'none';

        document.getElementById('m-perfil-title').textContent = 'Novo Perfil / Cargo';
        openModal('modal-perfil');
    });

    document.querySelectorAll('.js-btn-editar-perfil').forEach(btn => {
        btn.addEventListener('click', () => {
            const p = JSON.parse(btn.dataset.perfil || '{}');
            pId.value = p.id || '0';
            pNome.value = p.nome || '';
            pCod.value = p.cod || '';
            pGrupo.value = p.grupo || 'OPE';
            pStatus.value = p.status || 'ativo';
            pDesc.value = p.descricao || '';
            setMatrixPerms(perfilPermSelects, p.perms || {});
            if (pError) pError.style.display = 'none';

            document.getElementById('m-perfil-title').textContent = 'Editar Perfil';
            openModal('modal-perfil');
        });
    });

    formPerfil?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btnSave = document.getElementById('btn-submit-perfil');
        btnSave.disabled = true;
        btnSave.textContent = 'Salvando...';

        const permsObj = {};
        perfilPermSelects.forEach(sel => {
            const scr = sel.dataset.screen;
            const lvl = sel.value;
            if (lvl !== 'off') {
                permsObj[scr] = lvl;
                if (scr === 'pcp.pri') permsObj['ret.pri'] = lvl;
                if (scr === 'ana.aco') permsObj['ana.his'] = lvl;
                if (scr === 'adm.usu') permsObj['adm.per'] = lvl;
            }
        });

        const formData = new FormData(formPerfil);
        formData.append('acao', 'salvar_perfil');
        formData.append('perms', JSON.stringify(permsObj));

        try {
            const res = await fetch(API, { method: 'POST', body: formData });
            const data = await res.json();
            if (data.sucesso) {
                window.location.search = '?aba=perfis';
            } else {
                if (pError) {
                    pError.textContent = data.erro || 'Erro ao salvar perfil.';
                    pError.style.display = '';
                }
            }
        } catch (err) {
            if (pError) {
                pError.textContent = 'Erro de conexão com o servidor.';
                pError.style.display = '';
            }
        } finally {
            btnSave.disabled = false;
            btnSave.textContent = 'Salvar Perfil';
        }
    });

    document.querySelectorAll('.js-btn-excluir-perfil').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id;
            const nome = btn.dataset.nome;
            promptDelete({
                title: 'Excluir Perfil',
                message: `Deseja realmente excluir o perfil <strong style="color:var(--color-text-primary);">${nome}</strong>?`,
                btnText: 'Excluir Perfil',
                onConfirm: async (showError) => {
                    const formData = new FormData();
                    formData.append('acao', 'excluir_perfil');
                    formData.append('id', id);
                    const res = await fetch(API, { method: 'POST', body: formData });
                    const data = await res.json();
                    if (data.sucesso) {
                        window.location.search = '?aba=perfis';
                    } else {
                        showError(data.erro || 'Não foi possível excluir o perfil.');
                    }
                }
            });
        });
    });

});
</script>

<?php layoutFooter(); ?>