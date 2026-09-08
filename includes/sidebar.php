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
    || str_starts_with($_sRelSelf, '/pages/producao/aderencia-mensal.php')
    || str_starts_with($_sRelSelf, '/pages/producao/aderencia-anual.php')
    || str_starts_with($_sRelSelf, '/pages/producao/status-pecas.php')
    || str_starts_with($_sRelSelf, '/pages/producao/resumo-diario.php')
    || str_starts_with($_sRelSelf, '/pages/producao/painel-setor.php')
    || str_starts_with($_sRelSelf, '/pages/producao/fluxo-pedidos.php')
    || str_starts_with($_sRelSelf, '/pages/producao/acompanhamento.php')
    || str_starts_with($_sRelSelf, '/pages/producao/settings.php')
    || str_starts_with($_sRelSelf, '/pages/papel/');

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

// 4. Módulo Papel (liberado apenas para a conta admin@trael.com.br; visível junto do menu de Produção)
$_hidePapel = !hasAcessoPapel() || $_hideModuloProducao;

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
            [
                'href'  => '/pages/admin/concessionaria-regras.php',
                'label' => 'Regras Paint-Check',
                'icon'  => '<path d="M22 9L12 2 2 9l10 7 10-7z"/><path d="M6 10.5V16a2 2 0 002 2h8a2 2 0 002-2v-5.5"/><path d="M2 9v6"/><path d="M22 9v6"/>',
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
            [
                'href'  => '/pages/atraso-media-forca/index.php',
                'label' => 'Atraso Média Força',
                'icon'  => '<circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 15"/><path d="M19 19l2 2"/>',
                'permissao' => 'prod.for'
            ],
        ],
    ],
    [
        'rotulo' => 'Produção',
        'esconder' => $_hideModuloProducao,
        'itens'  => [
            [
                'href'  => '/pages/producao/aderencia-mensal.php',
                'label' => 'Aderência Mensal',
                'icon'  => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
                'permissao' => 'prod.set'
            ],
            [
                'href'  => '/pages/producao/aderencia-anual.php',
                'label' => 'Aderência Anual',
                'icon'  => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
                'permissao' => 'prod.set'
            ],
            [
                'href'  => '/pages/producao/status-pecas.php',
                'label' => 'Status Peças',
                'icon'  => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>',
                'permissao' => 'prod.set'
            ],
            [
                'href'  => '/pages/producao/resumo-diario.php',
                'label' => 'Resumo Diário',
                'icon'  => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
                'permissao' => 'prod.set'
            ],
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
        'rotulo' => 'PAPEL',
        'esconder' => $_hidePapel,
        'itens'  => [
            [
                'href'  => '/pages/papel/mesa-de-corte.php',
                'label' => 'Mesa de Corte',
                'icon'  => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/>',
            ],
            [
                'href'  => '/pages/papel/programacao.php',
                'label' => 'Programação',
                'icon'  => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
            ],
            [
                'href'  => '/pages/papel/inventario.php',
                'label' => 'Estoque Almoxarifado',
                'icon'  => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
            ],
            [
                'href'  => '/pages/papel/ordem-corte.php',
                'label' => 'Ordem de Corte (F-29)',
                'icon'  => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
            ],
            [
                'href'  => '/pages/papel/apontamento.php',
                'label' => 'Apontamento do Chão',
                'icon'  => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
            ],
            [
                'href'  => '/pages/papel/minha-maquina.php',
                'label' => 'Fila por Máquina',
                'icon'  => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
            ],
            [
                'href'  => '/pages/papel/maquinas.php',
                'label' => 'Máquinas',
                'icon'  => '<rect x="2" y="6" width="20" height="12" rx="2"/><path d="M6 12h.01M10 12h.01M14 12h.01M18 12h.01"/>',
            ],
            [
                'href'  => '/pages/papel/pecas.php',
                'label' => 'Peças (Engenharia)',
                'icon'  => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
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
            [
                'href'  => '/pages/retrabalho/dashboard-custos.php',
                'label' => 'Dashboard Custos',
                'icon'  => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M7 16V10M12 16V7M17 16V13"/>',
                'permissao' => 'ret.rel'
            ],
            [
                'href'  => '/pages/retrabalho/analise-custos.php',
                'label' => 'Análise Custos',
                'icon'  => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>',
                'permissao' => 'ret.rel'
            ],
            [
                'href'  => '/pages/retrabalho/custos.php',
                'label' => 'Parâmetros & Custos',
                'icon'  => '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
                'permissao' => 'ret.cus'
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
                'href'  => '/pages/qualidade/paint-check-historico.php',
                'label' => 'Histórico do Paint Check',
                'icon'  => '<path d="M3 3v18h18"/><path d="M18.7 8l-5.1 5.2-2.8-2.7L7 14.3"/>',
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
        'rotulo' => 'Linha Pintura',
        'esconder' => $_hidePintura,
        'itens'  => [
            [
                'href'  => '/pages/pintura/index.php',
                'label' => 'Registro de Reprova',
                'icon'  => '<path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/>',
                'permissao' => 'pin.reg'
            ],
            [
                'href'  => '/pages/pintura/lista.php',
                'label' => 'Lista',
                'icon'  => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
                'permissao' => 'pin.lis'
            ],
            [
                'href'  => '/pages/pintura/retornos.php',
                'label' => 'Retornos',
                'icon'  => '<polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/>',
                'permissao' => 'pin.retornos'
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
    <nav class="sidebar-nav" id="sidebarNav">
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
    <script>
        // Restaura a rolagem da sidebar de forma síncrona, antes do navegador pintar
        // este trecho — evita o "flash" de nascer no topo e só depois pular pra
        // posição salva (ver assets/js/app.js, que grava o scrollTop em sessionStorage).
        (function () {
            try {
                var v = sessionStorage.getItem('sgt_sidebar_scroll');
                if (v !== null) document.getElementById('sidebarNav').scrollTop = parseInt(v, 10) || 0;
            } catch (e) {}
        })();
    </script>

    <!-- Footer -->
    <div class="sidebar-footer">
        <span class="sidebar-version">SGT v<?= htmlspecialchars($_sVersao) ?></span>
    </div>

</aside>
