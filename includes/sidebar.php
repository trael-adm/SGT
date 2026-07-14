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

// Itens do menu (contexto Fábrica). Apenas Retrabalho está implementado.
$_sItens = [
    [
        'href'  => '/index.php',
        'label' => 'Home',
        'icon'  => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
    ],
    [
        'href'  => '/pages/retrabalho/index.php',
        'label' => 'Retrabalho',
        'icon'  => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/>',
    ],
    [
        'href'  => '/pages/projetos/index.php',
        'label' => 'Projetos',
        'icon'  => '<path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/>',
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
        <p class="nav-group-label">Fábrica</p>
        <?php foreach ($_sItens as $_sItem):
            $isActive = ($_sRelSelf === $_sItem['href']);
        ?>
        <a class="nav-item<?= $isActive ? ' active' : '' ?>"
           href="<?= htmlspecialchars($_sBaseUrl . $_sItem['href']) ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round"><?= $_sItem['icon'] ?></svg>
            <span class="nav-item-text"><?= htmlspecialchars($_sItem['label']) ?></span>
        </a>
        <?php endforeach; ?>
    </nav>

    <!-- Footer -->
    <div class="sidebar-footer">
        <span class="sidebar-version">SGT v<?= htmlspecialchars($_sVersao) ?></span>
    </div>

</aside>
