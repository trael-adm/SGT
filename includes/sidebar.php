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

// Dentro do módulo Produção, o menu mostra só Home + Produção — os itens de
// Retrabalho/Projetos ficam escondidos nessa seção (mas continuam normais nas demais).
// O mesmo vale ao contrário: dentro de Retrabalho/Relação/Projetos, o item Produção some.
$_sEmProducao   = str_starts_with($_sRelSelf, '/pages/producao/');
$_sEmRetrabalho = str_starts_with($_sRelSelf, '/pages/retrabalho/') || str_starts_with($_sRelSelf, '/pages/projetos/')
    || str_starts_with($_sRelSelf, '/pages/pedidos/');

// Itens do menu, agrupados por subtítulo (nav-group-label). Um grupo com 'rotulo' null
// não imprime cabeçalho — segue direto após o grupo anterior.
$_sGrupos = [
    [
        'rotulo' => $_sEmRetrabalho ? 'Retrabalho' : 'Fábrica',
        'itens'  => [
            [
                'href'  => '/index.php',
                'label' => 'Home',
                'icon'  => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
            ],
        ],
    ],
    [
        'rotulo' => 'Laboratório',
        'itens'  => [
            [
                'href'  => '/pages/producao/index.php',
                'label' => 'Registro',
                'icon'  => '<path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/>',
                'esconderEmRetrabalho' => true,
            ],
            [
                'href'  => '/pages/producao/lista.php',
                'label' => 'Lista',
                'icon'  => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
                'esconderEmRetrabalho' => true,
            ],
            [
                'href'  => '/pages/producao/retornos.php',
                'label' => 'Retornos',
                'icon'  => '<polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/>',
                'esconderEmRetrabalho' => true,
            ],
        ],
    ],
    [
        'rotulo' => null,
        'itens'  => [
            [
                'href'  => '/pages/retrabalho/index.php',
                'label' => 'Retrabalho',
                'icon'  => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/>',
                'esconderEmProducao' => true,
            ],
            [
                'href'  => '/pages/retrabalho/relacao.php',
                'label' => 'Relação de Retrabalhos',
                'icon'  => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
                'esconderEmProducao' => true,
            ],
            [
                'href'  => '/pages/pedidos/prioridade.php',
                'label' => 'Prioridade',
                'icon'  => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/>',
                'esconderEmProducao' => true,
            ],
        ],
    ],
    [
        'rotulo' => 'Análise',
        'itens'  => [
            [
                'href'  => '/pages/retrabalho/historico.php',
                'label' => 'Histórico',
                'icon'  => '<path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/>',
                'esconderEmProducao' => true,
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
            $_sItensVisiveis = array_filter($_sGrupo['itens'], function ($item) use ($_sEmProducao, $_sEmRetrabalho) {
                if ($_sEmProducao && !empty($item['esconderEmProducao'])) return false;
                if ($_sEmRetrabalho && !empty($item['esconderEmRetrabalho'])) return false;
                return true;
            });
            if (!$_sItensVisiveis) continue; // grupo inteiro escondido no contexto atual — nem o rótulo aparece
        ?>
        <?php if ($_sGrupo['rotulo'] !== null): ?>
        <p class="nav-group-label"><?= htmlspecialchars($_sGrupo['rotulo']) ?></p>
        <?php endif; ?>
        <?php foreach ($_sItensVisiveis as $_sItem):
            $isActive = ($_sRelSelf === $_sItem['href']);
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
