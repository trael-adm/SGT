<?php
declare(strict_types=1);

/**
 * Componente de Navegação Secundária (Sub-abas) do Módulo SOMA
 */

require_once __DIR__ . '/soma-helpers.php';
somaGarantirTabelas();

$somaAbaAtual = $somaAbaAtual ?? 'dashboard';
$somaUser     = currentUser();
$somaIdPerfil = (int) ($somaUser['id_perfil'] ?? 0);
$somaBaseUrl  = defined('APP_URL') ? APP_URL : '';

$somaAbas = [
    [
        'id'    => 'dashboard',
        'label' => 'Dashboard',
        'href'  => '/pages/soma/index.php',
        'icon'  => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>',
    ],
    [
        'id'    => 'digitador',
        'label' => 'Leitor',
        'href'  => '/pages/soma/digitador.php',
        'icon'  => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
    ],
    [
        'id'    => 'registros',
        'label' => 'Base de Dados',
        'href'  => '/pages/soma/registros.php',
        'icon'  => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2 1.5 3 3.5 3h9c2 0 3.5-1 3.5-3V7c0-2-1.5-3-3.5-3h-9C5.5 4 4 5 4 7zM9 9h6M9 13h6M9 17h4"/></svg>',
    ],
    [
        'id'    => 'paradas',
        'label' => 'Análise de Paradas',
        'href'  => '/pages/soma/paradas.php',
        'icon'  => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
    ],
    [
        'id'    => 'operador',
        'label' => 'Eficiência Operador',
        'href'  => '/pages/soma/operador.php',
        'icon'  => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>',
    ],
    [
        'id'    => 'relatorios',
        'label' => 'Relatórios',
        'href'  => '/pages/soma/relatorios.php',
        'icon'  => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>',
    ],
];

$podeConfigSoma = isAdmin() || in_array($somaIdPerfil, [1, 2, 201, 202, 203, 204, 205, 206, 207, 212], true);
$podeAuditSoma  = isAdmin() || in_array($somaIdPerfil, [1, 201, 202], true);

if ($podeConfigSoma) {
    $somaAbas[] = [
        'id'    => 'settings',
        'label' => 'Configurações',
        'href'  => '/pages/soma/settings.php',
        'icon'  => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
    ];
}

if ($podeAuditSoma) {
    $somaAbas[] = [
        'id'    => 'auditoria',
        'label' => 'Auditoria',
        'href'  => '/pages/soma/auditoria.php',
        'icon'  => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>',
    ];
}
?>

<!-- Barra de Cabeçalho do Módulo SOMA -->
<div class="mb-6 bg-white border border-[#e2e6ed] rounded-xl p-4 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-[#e8a020] text-[#1a3d2a] font-bold text-lg flex items-center justify-center shadow-sm flex-shrink-0">
            S
        </div>
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-lg font-bold text-[#1a2133] leading-tight">SOMA — Cronoanálise Industrial</h1>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-100 text-emerald-800">
                    Trael Transformadores
                </span>
            </div>
            <p class="text-xs text-[#5a6480] mt-0.5">Gestão de tempos padrão, apontamentos de turno, perdas por parada e OEE em tempo real.</p>
        </div>
    </div>
</div>

<!-- Abas de Navegação do SOMA -->
<div class="flex items-center gap-1.5 overflow-x-auto pb-2 border-b border-[#e2e6ed] mb-6">
    <?php foreach ($somaAbas as $aba): 
        $isActive = ($somaAbaAtual === $aba['id']);
    ?>
        <a href="<?= htmlspecialchars($somaBaseUrl . $aba['href']) ?>" 
           class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-xs font-semibold whitespace-nowrap transition-all duration-150 <?= $isActive ? 'bg-[#1a3d2a] text-white shadow-sm font-bold' : 'text-[#5a6480] hover:text-[#1a2133] hover:bg-[#f8f9fb]' ?>">
            <?= $aba['icon'] ?>
            <span><?= htmlspecialchars($aba['label']) ?></span>
        </a>
    <?php endforeach; ?>
</div>
