<?php
$_hUser = currentUser();

// Iniciais para o avatar
$_hNome   = $_hUser['nome'] ?? 'U';
$_hPartes = explode(' ', trim($_hNome));
$_hIniciais = strtoupper(substr($_hPartes[0], 0, 1));
if (count($_hPartes) > 1) {
    $_hIniciais .= strtoupper(substr(end($_hPartes), 0, 1));
}
$_hBaseUrl = defined('APP_URL') ? APP_URL : '';
?>
<header class="app-header" id="app-header">

    <div class="d-flex align-center gap-3">
        <button id="sidebar-toggle"
                class="btn btn-ghost sidebar-toggle-btn"
                aria-label="Alternar sidebar">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <line x1="3" y1="6"  x2="21" y2="6"/>
                <line x1="3" y1="12" x2="21" y2="12"/>
                <line x1="3" y1="18" x2="21" y2="18"/>
            </svg>
        </button>
    </div>

    <div class="header-right">

        <!-- Trocar de sistema (voltar ao Hub) -->
        <a href="<?= htmlspecialchars($_hBaseUrl) ?>/index.php"
           class="btn btn-ghost"
           style="display:inline-flex;align-items:center;gap:6px;font-size:var(--font-size-sm);">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
                <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
            </svg>
            <span class="hide-mobile">Trocar de sistema</span>
        </a>

        <!-- Menu do usuário -->
        <div style="position:relative;">
            <button class="user-btn" id="user-menu-btn" type="button">
                <div class="avatar-placeholder"><?= htmlspecialchars($_hIniciais) ?></div>
                <span class="user-btn-name"><?= htmlspecialchars($_hPartes[0]) ?></span>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" style="color:var(--color-text-muted);">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>

            <div id="user-dropdown"
                 style="display:none;position:absolute;right:0;top:calc(100% + 6px);
                        width:210px;background:var(--color-surface);
                        border:1px solid var(--color-border);border-radius:var(--radius-lg);
                        box-shadow:var(--shadow-md);z-index:500;overflow:hidden;">

                <div style="padding:12px 14px;border-bottom:1px solid var(--color-border);">
                    <p style="font-size:var(--font-size-sm);font-weight:600;color:var(--color-text-primary);">
                        <?= htmlspecialchars($_hNome) ?>
                    </p>
                    <p style="font-size:var(--font-size-xs);color:var(--color-text-muted);margin-top:2px;">
                        <?= htmlspecialchars($_hUser['email'] ?? '') ?>
                    </p>
                </div>

                <a href="<?= htmlspecialchars($_hBaseUrl) ?>/logout.php"
                   class="dropdown-item danger">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/>
                        <polyline points="16 17 21 12 16 7"/>
                        <line x1="21" y1="12" x2="9" y2="12"/>
                    </svg>
                    Sair
                </a>
            </div>
        </div>

    </div>
</header>
