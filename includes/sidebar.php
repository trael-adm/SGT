<?php
$_sSelf    = strtok($_SERVER['PHP_SELF'] ?? '', '?');
$_sBaseUrl = defined('APP_URL') ? APP_URL : '';
$_sVersao  = defined('APP_VERSION') ? APP_VERSION : '1.0.0';

// Caminho da página atual relativo à raiz do app (remove o prefixo de pasta, se houver)
$_sAppPath = parse_url($_sBaseUrl, PHP_URL_PATH) ?: '';
$_sRelSelf = ($_sAppPath !== '' && str_starts_with($_sSelf, $_sAppPath))
    ? substr($_sSelf, strlen($_sAppPath))
    : $_sSelf;
if ($_sRelSelf === '') $_sRelSelf = '/';

// Contexto da URL para menus dinâmicos
$isAdminContext = str_starts_with($_sRelSelf, '/pages/admin/');

$isProducaoContext = str_starts_with($_sRelSelf, '/pages/distribuicao/')
    || str_starts_with($_sRelSelf, '/pages/atraso-distribuicao/')
    || str_starts_with($_sRelSelf, '/pages/forca-seco/')
    || str_starts_with($_sRelSelf, '/pages/painel-setor/')
    || str_starts_with($_sRelSelf, '/pages/fluxo-pedidos/')
    || str_starts_with($_sRelSelf, '/pages/acompanhamento/')
    || str_starts_with($_sRelSelf, '/pages/soma/')
    || str_starts_with($_sRelSelf, '/pages/settings/')
    || str_starts_with($_sRelSelf, '/pages/producao/distribuicao.php')
    || str_starts_with($_sRelSelf, '/pages/producao/atraso-distribuicao.php')
    || str_starts_with($_sRelSelf, '/pages/producao/forca-seco.php')
    || str_starts_with($_sRelSelf, '/pages/producao/painel-setor.php')
    || str_starts_with($_sRelSelf, '/pages/producao/fluxo-pedidos.php')
    || str_starts_with($_sRelSelf, '/pages/producao/acompanhamento.php')
    || str_starts_with($_sRelSelf, '/pages/producao/settings.php');

$isRetrabalhoContext = str_starts_with($_sRelSelf, '/pages/retrabalho/')
    || str_starts_with($_sRelSelf, '/pages/pedidos/')
    || str_starts_with($_sRelSelf, '/pages/projetos/')
    || str_starts_with($_sRelSelf, '/pages/qualidade/')
    || str_starts_with($_sRelSelf, '/pages/inspecao_final/')
    || str_starts_with($_sRelSelf, '/pages/pintura/')
    || (str_starts_with($_sRelSelf, '/pages/producao/') && !$isProducaoContext);

$isSpecificContext = $isAdminContext || $isProducaoContext || $isRetrabalhoContext;

// Regras de visibilidade por contexto de módulo:
// 1. Módulo Administração
$_hideAdmin = !$isAdminContext || !hasAcesso('admin');

// 2. Módulo Produção (visível apenas dentro do sistema de Produção)
$_hideModuloProducao = ($isSpecificContext && !$isProducaoContext) || $isAdminContext;

// 3. Módulo Retrabalho & Fábrica (visível apenas dentro do sistema de Retrabalho)
$_hideRetrabalhoContext = ($isSpecificContext && !$isRetrabalhoContext) || $isAdminContext;

$_hidePcp           = $_hideRetrabalhoContext || (!hasAcesso('tab:pcp') && !hasAcesso('pcp.pri') && !hasAcesso('ret.pri'));
$_hideLaboratorio   = $_hideRetrabalhoContext || !hasAcesso('tab:laboratorio');
$_hideInspecaoFinal = $_hideRetrabalhoContext || !hasAcesso('tab:inspecao_final');
$_hideRetrabalho    = $_hideRetrabalhoContext || !hasAcesso('tab:retrabalho');
$_hideQualidade     = $_hideRetrabalhoContext || !hasAcesso('qua.tip');
$_hidePintura       = $_hideRetrabalhoContext || !hasAcesso('tab:pintura');
$_hideAnalise       = $_hideRetrabalhoContext || !hasAcesso('tab:analise');

// Itens do menu, agrupados por subtítulo (nav-group-label). Um grupo com 'rotulo' null
// não imprime cabeçalho — segue direto após o grupo anterior.
$_sGrupos = [
    [
        'rotulo' => null,
        'esconder' => false,
        'itens'  => [
            [
                'href'  => '/index.php',
                'label' => 'Home',
                'icon'  => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
            ],
        ],
    ],
    [
        'rotulo' => 'Administração',
        'esconder' => $_hideAdmin,
        'itens'  => [
            [
                'href'  => '/pages/admin/usuarios.php',
                'label' => 'Usuários & Setores',
                'icon'  => '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/>',
                'permissao' => 'adm.usu'
            ],
        ],
    ],
    [
        'rotulo' => 'Distribuição',
        'esconder' => $_hideModuloProducao,
        'itens'  => [
            [
                'href'  => '/pages/distribuicao/index.php',
                'label' => 'Indicador Distribuição',
                'icon'  => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
                'permissao' => 'prod.dis'
            ],
            [
                'href'  => '/pages/atraso-distribuicao/index.php',
                'label' => 'Atraso Distribuição',
                'icon'  => '<circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 15"/><path d="M19 19l2 2"/>',
                'permissao' => 'prod.atr'
            ],
        ],
    ],
    [
        'rotulo' => 'Média Força',
        'esconder' => $_hideModuloProducao,
        'itens'  => [
            [
                'href'  => '/pages/forca-seco/index.php',
                'label' => 'Indicador Média Força',
                'icon'  => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
                'permissao' => 'prod.for'
            ],
        ],
    ],
    [
        'rotulo' => 'Produção',
        'esconder' => $_hideModuloProducao,
        'itens'  => [
            [
                'href'  => '/pages/painel-setor/index.php',
                'label' => 'Painel por Setor',
                'icon'  => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
                'permissao' => 'prod.set'
            ],
            [
                'href'  => '/pages/fluxo-pedidos/index.php',
                'label' => 'Fluxo de Pedidos',
                'icon'  => '<circle cx="5" cy="6" r="3"/><circle cx="19" cy="18" r="3"/><path d="M8 6h5a4 4 0 0 1 4 4v2a4 4 0 0 0 4 4h-2"/>',
                'permissao' => 'prod.flu'
            ],
            [
                'href'  => '/pages/acompanhamento/index.php',
                'label' => 'Acompanhamento',
                'icon'  => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
                'permissao' => 'prod.aco'
            ],
        ],
    ],
    [
        'rotulo' => 'Cronoanálise',
        'esconder' => $_hideModuloProducao,
        'itens'  => [
            [
                'href'  => '/pages/soma/index.php',
                'label' => 'SOMA',
                'icon'  => '<circle cx="12" cy="12" r="9"/><polyline points="12 6 12 12 16 14"/><path d="M10 2h4"/>',
                'permissao' => 'soma.hub'
            ],
        ],
    ],
    [
        'rotulo' => 'Gestão',
        'esconder' => true, // Ocultado do módulo de produção a pedido do usuário
        'itens'  => [
            [
                'href'  => '/pages/settings/index.php',
                'label' => 'Configurações',
                'icon'  => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>',
                'permissao' => 'prod.met'
            ],
        ],
    ],
    [
        'rotulo' => 'PCP & Planejamento',
        'esconder' => $_hidePcp,
        'itens'  => [
            [
                'href'  => '/pages/pedidos/prioridade.php',
                'label' => 'Prioridades',
                'icon'  => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/>',
                'permissao' => 'pcp.pri'
            ],
        ],
    ],
    [
        'rotulo' => 'Laboratório',
        'esconder' => $_hideLaboratorio,
        'itens'  => [
            [
                'href'  => '/pages/producao/index.php',
                'label' => 'Registro de Reprova',
                'icon'  => '<path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/>',
                'permissao' => 'lab.reg'
            ],
            [
                'href'  => '/pages/producao/lista.php',
                'label' => 'Lista',
                'icon'  => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
                'permissao' => 'lab.lis'
            ],
            [
                'href'  => '/pages/producao/retornos.php',
                'label' => 'Retornos',
                'icon'  => '<polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/>',
                'permissao' => 'lab.ret'
            ],
        ],
    ],
    [
        'rotulo' => 'Inspeção Final',
        'esconder' => $_hideInspecaoFinal,
        'itens'  => [
            [
                'href'  => '/pages/inspecao_final/index.php',
                'label' => 'Registro de Reprova',
                'icon'  => '<path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/>',
                'permissao' => 'iqf.reg'
            ],
            [
                'href'  => '/pages/inspecao_final/lista.php',
                'label' => 'Lista',
                'icon'  => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
                'permissao' => 'iqf.lis'
            ],
            [
                'href'  => '/pages/inspecao_final/retornos.php',
                'label' => 'Retornos',
                'icon'  => '<polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/>',
                'permissao' => 'iqf.ret'
            ],
        ],
    ],
    [
        'rotulo' => 'Retrabalho',
        'esconder' => $_hideRetrabalho,
        'itens'  => [
            [
                'href'  => '/pages/retrabalho/dashboard.php',
                'label' => 'Dashboard',
                'icon'  => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M7 16V10M12 16V7M17 16V13"/>',
                'permissao' => 'ret.dash'
            ],
            [
                'href'  => '/pages/retrabalho/index.php',
                'label' => 'Retrabalho',
                'icon'  => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/>',
                'permissao' => 'ret.pan'
            ],
            [
                'href'  => '/pages/retrabalho/relacao.php',
                'label' => 'Relação de Retrabalhos',
                'icon'  => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
                'permissao' => 'ret.rel'
            ],
        ],
    ],
    [
        'rotulo' => 'Qualidade',
        'esconder' => $_hideQualidade,
        'itens'  => [
            [
                'href'  => '/pages/qualidade/reprovas.php',
                'label' => 'Tipos de Reprova',
                'icon'  => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
                'permissao' => 'qua.tip'
            ],
        ],
    ],
    [
        'rotulo' => 'Pintura',
        'esconder' => $_hidePintura,
        'itens'  => [
            [
                'href'  => '/pages/pintura/paint-check.php',
                'label' => 'Paint Check (Robô)',
                'icon'  => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
                'permissao' => 'pin.pai'
            ],
            [
                'href'  => '/pages/pintura/relacao.php',
                'label' => 'Relação de Retrabalhos',
                'icon'  => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
                'permissao' => 'pin.ret'
            ],
        ],
    ],
    [
        'rotulo' => 'Análise',
        'esconder' => $_hideAnalise,
        'itens'  => [
            [
                'href'  => '/pages/retrabalho/acompanhamento.php',
                'label' => 'Acompanhamento',
                'icon'  => '<path d="M11 21H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v7"/><path d="M21 16v6"/><path d="M18 19h6"/><path d="M9 7h6"/><path d="M9 11h6"/><path d="M9 15h4"/>',
                'permissao' => 'ana.aco'
            ],
            [
                'href'  => '/pages/retrabalho/historico.php',
                'label' => 'Histórico',
                'icon'  => '<path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/>',
                'permissao' => 'ana.his'
            ],
        ],
    ],
];
?>
<aside class="sidebar" id="sidebar">

    <!-- Logo -->
    <div class="sidebar-logo">
        <div class="sidebar-logo-icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="white">
                <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>
            </svg>
        </div>
        <span class="sidebar-logo-text">SGT</span>
    </div>

    <!-- Navigation -->
    <nav class="sidebar-nav">
        <?php foreach ($_sGrupos as $_sGrupo):
            if (!empty($_sGrupo['esconder'])) continue; // Se o grupo estiver escondido para este usuário
            
            $_sItensVisiveis = [];
            foreach ($_sGrupo['itens'] as $_i) {
                if (!isset($_i['permissao']) || hasAcesso($_i['permissao'])) {
                    $_sItensVisiveis[] = $_i;
                }
            }
            if (!$_sItensVisiveis) continue;
        ?>
        <?php if ($_sGrupo['rotulo'] !== null): ?>
        <p class="nav-group-label"><?= htmlspecialchars($_sGrupo['rotulo']) ?></p>
        <?php endif; ?>
        <?php foreach ($_sItensVisiveis as $_sItem):
            $isActive = ($_sRelSelf === $_sItem['href'])
                || (str_starts_with($_sItem['href'], '/pages/soma/') && str_starts_with($_sRelSelf, '/pages/soma/'))
                || (str_starts_with($_sItem['href'], '/pages/fluxo-pedidos/') && str_starts_with($_sRelSelf, '/pages/fluxo-pedidos/'))
                || (str_starts_with($_sItem['href'], '/pages/distribuicao/') && (str_starts_with($_sRelSelf, '/pages/distribuicao/') || str_starts_with($_sRelSelf, '/pages/producao/distribuicao.php')))
                || (str_starts_with($_sItem['href'], '/pages/atraso-distribuicao/') && (str_starts_with($_sRelSelf, '/pages/atraso-distribuicao/') || str_starts_with($_sRelSelf, '/pages/producao/atraso-distribuicao.php')))
                || (str_starts_with($_sItem['href'], '/pages/forca-seco/') && (str_starts_with($_sRelSelf, '/pages/forca-seco/') || str_starts_with($_sRelSelf, '/pages/producao/forca-seco.php')))
                || (str_starts_with($_sItem['href'], '/pages/painel-setor/') && (str_starts_with($_sRelSelf, '/pages/painel-setor/') || str_starts_with($_sRelSelf, '/pages/producao/painel-setor.php')))
                || (str_starts_with($_sItem['href'], '/pages/acompanhamento/') && (str_starts_with($_sRelSelf, '/pages/acompanhamento/') || str_starts_with($_sRelSelf, '/pages/producao/acompanhamento.php')))
                || (str_starts_with($_sItem['href'], '/pages/settings/') && (str_starts_with($_sRelSelf, '/pages/settings/') || str_starts_with($_sRelSelf, '/pages/producao/settings.php')));
        ?>
        <a class="nav-item<?= $isActive ? ' active' : '' ?>"
           href="<?= htmlspecialchars($_sBaseUrl . $_sItem['href']) ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round"><?= $_sItem['icon'] ?></svg>
            <span class="nav-item-text"><?= htmlspecialchars($_sItem['label']) ?></span>
        </a>
        <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>

    <!-- Footer -->
    <div class="sidebar-footer">
        <span class="sidebar-version">SGT v<?= htmlspecialchars($_sVersao) ?></span>
    </div>

</aside>
