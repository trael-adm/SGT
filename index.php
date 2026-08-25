<?php
declare(strict_types=1);

require_once __DIR__ . '/config/conexao.php';
require_once __DIR__ . '/config/session.php';

requireLogin();

$user  = currentUser();
$base  = defined('APP_URL') ? APP_URL : '';
$nome  = trim($user['nome'] ?? 'Usuário');
$primeiroNome = explode(' ', $nome)[0];

// Iniciais para o avatar
$partes   = preg_split('/\s+/', $nome) ?: [$nome];
$iniciais = mb_strtoupper(mb_substr($partes[0], 0, 1));
if (count($partes) > 1) {
    $iniciais .= mb_strtoupper(mb_substr(end($partes), 0, 1));
}

// Nome do perfil (cargo) exibido no cabeçalho
$perfilNome = '';
try {
    $stmtP = getDB()->prepare('SELECT nome FROM perfis WHERE id = ? LIMIT 1');
    $stmtP->execute([(int) ($user['id_perfil'] ?? 0)]);
    $perfilNome = (string) ($stmtP->fetchColumn() ?: '');
} catch (\Throwable $e) {
    $perfilNome = '';
}
if ($perfilNome === '') {
    $mapaPerfil = [1 => 'Administrador', 2 => 'Planejador', 3 => 'Executor', 4 => 'Dashboard', 5 => 'Cliente Interno'];
    $perfilNome = $mapaPerfil[(int) ($user['id_perfil'] ?? 0)] ?? 'Colaborador';
}

// URL dinâmica para o card "Retrabalho" baseada nas permissões
if (hasAcesso('ret.rel')) {
    $urlRetrabalho = 'pages/retrabalho/relacao.php';
} elseif (hasAcesso('ret.pan')) {
    $urlRetrabalho = 'pages/retrabalho/index.php';
} elseif (hasAcesso('ret.dash')) {
    $urlRetrabalho = 'pages/retrabalho/dashboard.php';
} elseif (hasAcesso('pcp.pri') || hasAcesso('ret.pri')) {
    $urlRetrabalho = 'pages/pedidos/prioridade.php';
} elseif (hasAcesso('lab.reg')) {
    $urlRetrabalho = 'pages/producao/index.php';
} elseif (hasAcesso('lab.lis')) {
    $urlRetrabalho = 'pages/producao/lista.php';
} elseif (hasAcesso('lab.ret')) {
    $urlRetrabalho = 'pages/producao/retornos.php';
} elseif (hasAcesso('iqf.reg')) {
    $urlRetrabalho = 'pages/inspecao_final/index.php';
} elseif (hasAcesso('iqf.lis')) {
    $urlRetrabalho = 'pages/inspecao_final/lista.php';
} elseif (hasAcesso('iqf.ret')) {
    $urlRetrabalho = 'pages/inspecao_final/retornos.php';
} elseif (hasAcesso('pin.pai')) {
    $urlRetrabalho = 'pages/pintura/paint-check.php';
} elseif (hasAcesso('pin.ret')) {
    $urlRetrabalho = 'pages/pintura/relacao.php';
} elseif (hasAcesso('ana.aco')) {
    $urlRetrabalho = 'pages/retrabalho/acompanhamento.php';
} elseif (hasAcesso('ana.his')) {
    $urlRetrabalho = 'pages/retrabalho/historico.php';
} elseif (hasAcesso('qua.tip')) {
    $urlRetrabalho = 'pages/qualidade/reprovas.php';
} else {
    $urlRetrabalho = 'pages/producao/index.php';
}

// URL dinâmica para o card "Produção" baseada nas permissões
if (hasAcesso('prod.dis')) {
    $urlProducao = 'pages/distribuicao/index.php';
} elseif (hasAcesso('prod.atr')) {
    $urlProducao = 'pages/atraso-distribuicao/index.php';
} elseif (hasAcesso('prod.for')) {
    $urlProducao = 'pages/forca-seco/index.php';
} elseif (hasAcesso('prod.set')) {
    $urlProducao = 'pages/painel-setor/index.php';
} elseif (hasAcesso('prod.flu')) {
    $urlProducao = 'pages/fluxo-pedidos/index.php';
} elseif (hasAcesso('prod.aco')) {
    $urlProducao = 'pages/acompanhamento/index.php';
} elseif (hasAcesso('prod.reg') || hasAcesso('lab.reg')) {
    $urlProducao = 'pages/producao/index.php';
} elseif (hasAcesso('prod.lis') || hasAcesso('lab.lis')) {
    $urlProducao = 'pages/producao/lista.php';
} elseif (hasAcesso('prod.ret') || hasAcesso('lab.ret')) {
    $urlProducao = 'pages/producao/retornos.php';
} else {
    $urlProducao = 'pages/distribuicao/index.php';
}

// URL dinâmica para o card "SOMA" baseada nas permissões
if (hasAcesso('soma.hub')) {
    $urlSoma = 'pages/soma/index.php';
} elseif (hasAcesso('soma.dig')) {
    $urlSoma = 'pages/soma/digitador.php';
} elseif (hasAcesso('soma.ope')) {
    $urlSoma = 'pages/soma/operador.php';
} elseif (hasAcesso('soma.par')) {
    $urlSoma = 'pages/soma/paradas.php';
} elseif (hasAcesso('soma.rel')) {
    $urlSoma = 'pages/soma/relatorios.php';
} elseif (hasAcesso('soma.aud')) {
    $urlSoma = 'pages/soma/auditoria.php';
} elseif (hasAcesso('soma.cfg')) {
    $urlSoma = 'pages/soma/settings.php';
} else {
    $urlSoma = 'pages/soma/index.php';
}

// ─── Módulos da fábrica (HUB) ─────────────────────────────────────────────────
$sistemas = [];
$todosSistemas = [
    [
        'nome' => 'SGE', 'cat' => 'Engenharia',
        'desc' => 'Gestão de demandas, projetos e etapas de produção.',
        'dot' => '#E89B1C', 'iconBg' => '#FBEBD2', 'iconColor' => '#C6800F',
        'icone' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
        'url' => 'em-breve.php?s=SGE',
        'req' => 'hub:sge',
    ],
    [
        'nome' => 'SOMA', 'cat' => 'PCP',
        'desc' => 'Análise de tempos: peça/hora, paradas e dados de produção.',
        'dot' => '#2E6CB8', 'iconBg' => '#E3EDF9', 'iconColor' => '#2E6CB8',
        'icone' => '<circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 16 14"/>',
        'url' => $urlSoma,
        'req' => ['hub:soma', 'soma.hub', 'soma.dig', 'soma.ope', 'soma.par', 'soma.reg', 'soma.rel', 'soma.aud', 'soma.cfg'],
    ],
    [
        'nome' => 'Produção', 'cat' => 'Fábrica',
        'desc' => 'Planejamento e controle de produção (PCP) e acompanhamento em tempo real.',
        'dot' => '#0F766E', 'iconBg' => '#CCFBF1', 'iconColor' => '#0F766E',
        'icone' => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/>',
        'url' => $urlProducao,
        'req' => ['hub:producao', 'prod.dis', 'prod.atr', 'prod.for', 'prod.set', 'prod.flu', 'prod.aco', 'prod.met', 'prod.reg', 'prod.lis', 'prod.ret', 'lab.reg', 'lab.lis', 'lab.ret'],
    ],
    [
        'nome' => 'Retrabalho', 'cat' => 'Fábrica',
        'desc' => 'Registro e acompanhamento de retrabalhos e produção.',
        'dot' => '#C0453B', 'iconBg' => '#F7E4E2', 'iconColor' => '#C0453B',
        'icone' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        'url' => $urlRetrabalho,
        'req' => ['tab:pcp', 'tab:laboratorio', 'tab:inspecao_final', 'tab:pintura', 'tab:retrabalho', 'tab:analise'], // Pode ver se tiver pelo menos uma
    ],
    [
        'nome' => '5S', 'cat' => 'Organização',
        'desc' => 'Auditorias e checklists de 5S por setor.',
        'dot' => '#2E8B57', 'iconBg' => '#E0F0E7', 'iconColor' => '#2E8B57',
        'icone' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
        'url' => 'em-breve.php?s=5S',
        'req' => 'hub:5s',
    ],
    [
        'nome' => 'Ausências', 'cat' => 'Pessoas',
        'desc' => 'Faltas, férias e afastamentos da equipe.',
        'dot' => '#7C5CBF', 'iconBg' => '#ECE6F7', 'iconColor' => '#7C5CBF',
        'icone' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="14.5" y1="14" x2="9.5" y2="19"/><line x1="9.5" y1="14" x2="14.5" y2="19"/>',
        'url' => 'em-breve.php?s=Ausências',
        'req' => 'hub:ausencias',
    ],
    [
        'nome' => 'Incidentes', 'cat' => 'Segurança',
        'desc' => 'Registro e acompanhamento de incidentes no processo.',
        'dot' => '#D0453B', 'iconBg' => '#F8E3E1', 'iconColor' => '#D0453B',
        'icone' => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'url' => 'em-breve.php?s=Incidentes',
        'req' => 'hub:incidentes',
    ],
    [
        'nome' => 'Perdas', 'cat' => 'Descartes',
        'desc' => 'Lançamento de descartes do setor: sucatas e perdas.',
        'dot' => '#B5852A', 'iconBg' => '#F5ECD8', 'iconColor' => '#B5852A',
        'icone' => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/>',
        'url' => 'em-breve.php?s=Perdas',
        'req' => 'hub:perdas',
    ],
    [
        'nome' => 'Paradas', 'cat' => 'Operação',
        'desc' => 'Registro de paradas de máquina: motivos e tempo.',
        'dot' => '#C08A1E', 'iconBg' => '#F5ECD6', 'iconColor' => '#C08A1E',
        'icone' => '<circle cx="12" cy="12" r="9"/><line x1="10" y1="9" x2="10" y2="15"/><line x1="14" y1="9" x2="14" y2="15"/>',
        'url' => 'em-breve.php?s=Paradas',
        'req' => 'hub:paradas',
    ],
];

// Filtra módulos permitidos
foreach ($todosSistemas as $s) {
    if (is_array($s['req'])) {
        $permitido = false;
        foreach ($s['req'] as $r) {
            if (hasAcesso($r)) {
                $permitido = true;
                break;
            }
        }
        if ($permitido) $sistemas[] = $s;
    } else {
        if (hasAcesso($s['req'])) {
            $sistemas[] = $s;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trael — Selecione um sistema</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family:'Manrope',sans-serif; }
        .bg-forest {
            background-color:#0e2c1d;
            background-image: radial-gradient(1200px 600px at 50% -10%, rgba(232,155,28,.08), transparent 60%);
        }
        .sys-card { transition: transform .15s ease, box-shadow .15s ease; }
        .sys-card:hover { transform: translateY(-4px); box-shadow:0 20px 40px -12px rgba(0,0,0,.45); }
        .sys-card:hover .arrow { background-color:#E89B1C; color:#fff; }
        .featured { transition: transform .15s ease, box-shadow .15s ease; box-shadow:0 18px 40px -10px rgba(232,155,28,.55); }
        .featured:hover { transform: translateY(-3px); box-shadow:0 24px 50px -10px rgba(232,155,28,.70); }
    </style>
</head>
<body class="bg-forest min-h-screen text-white">

    <!-- Top bar -->
    <header class="max-w-7xl mx-auto px-6 pt-6 flex items-center justify-between gap-4 flex-wrap">
        <div class="flex items-center gap-3">
            <span class="w-11 h-11 rounded-2xl flex items-center justify-center shrink-0" style="background-color:#E89B1C;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="#0e2c1d" stroke="#0e2c1d" stroke-width="1.5" stroke-linejoin="round">
                    <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
                </svg>
            </span>
            <span class="text-lg sm:text-xl font-extrabold tracking-tight">Trael Transformadores Elétricos</span>
        </div>

        <div class="flex items-center gap-3 sm:gap-4">
            <div class="text-right leading-tight hidden sm:block">
                <p class="text-sm font-semibold"><?= htmlspecialchars($nome) ?></p>
                <p class="text-xs text-white/55"><?= htmlspecialchars($perfilNome) ?></p>
            </div>
            <span class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold shrink-0"
                  style="background-color:#2f7a52;color:#fff;"><?= htmlspecialchars($iniciais) ?></span>
                  
            <?php if (hasAcesso('admin')): ?>
            <a href="<?= htmlspecialchars($base) ?>/pages/admin/usuarios.php"
               class="inline-flex items-center gap-2 text-sm px-3.5 py-2 rounded-xl border border-white/15 text-white/85 hover:bg-white/10 transition-colors">
               <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 11c1.66 0 3-1.34 3-3S13.66 5 12 5 9 6.34 9 8s1.34 3 3 3z"/><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><path d="M21.5 12l-2-2-2 2M19.5 10v4"/>
               </svg>
               Painel Admin
            </a>
            <?php endif; ?>

            <a href="<?= htmlspecialchars($base) ?>/logout.php"
               class="inline-flex items-center gap-2 text-sm px-3.5 py-2 rounded-xl border border-white/15 text-white/85 hover:bg-white/10 transition-colors">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
                </svg>
                Sair
            </a>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-6 py-10">

        <!-- Boas-vindas + destaque -->
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6 mb-10">
            <div>
                <p class="text-xs font-bold uppercase tracking-widest mb-2" style="color:#E89B1C;">Bem-vindo, <?= htmlspecialchars(mb_strtoupper($primeiroNome)) ?></p>
                <h1 class="text-4xl sm:text-5xl font-extrabold tracking-tight">Selecione um sistema</h1>
                <p class="text-white/55 mt-2">Você tem acesso aos módulos abaixo.</p>
            </div>

            <?php if (hasAcesso('hub:resumo_gerencial')): ?>
            <a href="<?= htmlspecialchars($base) ?>/em-breve.php?s=Resumo%20Gerencial"
               class="featured rounded-2xl p-5 w-full lg:w-auto lg:min-w-[340px]"
               style="background-image:linear-gradient(135deg,#E89B1C,#d98f16);">
                <div class="flex items-center gap-4">
                    <span class="w-11 h-11 rounded-xl flex items-center justify-center shrink-0" style="background-color:rgba(14,44,29,.15);">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#0e2c1d" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                        </svg>
                    </span>
                    <div class="flex-1 min-w-0">
                        <p class="text-[11px] font-bold uppercase tracking-wider" style="color:rgba(14,44,29,.65);">Visão geral · Destaque</p>
                        <p class="text-lg font-extrabold" style="color:#0e2c1d;">Resumo Gerencial</p>
                    </div>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0e2c1d" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="9 18 15 12 9 6"/>
                    </svg>
                </div>
            </a>
            <?php endif; ?>
        </div>

        <!-- Grid de módulos -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
            <?php foreach ($sistemas as $s): ?>
                <a href="<?= htmlspecialchars($base . '/' . $s['url']) ?>"
                   class="sys-card block bg-white rounded-2xl p-5 border border-black/5 shadow-sm">
                    <div class="flex items-start justify-between">
                        <span class="w-12 h-12 rounded-xl flex items-center justify-center"
                              style="background-color:<?= $s['iconBg'] ?>;color:<?= $s['iconColor'] ?>;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <?= $s['icone'] ?>
                            </svg>
                        </span>
                        <span class="arrow w-8 h-8 rounded-full flex items-center justify-center bg-gray-100 text-gray-400 transition-colors">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="9 18 15 12 9 6"/>
                            </svg>
                        </span>
                    </div>
                    <h2 class="text-lg font-extrabold text-gray-900 mt-4"><?= htmlspecialchars($s['nome']) ?></h2>
                    <p class="text-sm text-gray-500 mt-1 leading-snug"><?= htmlspecialchars($s['desc']) ?></p>
                    <div class="flex items-center gap-2 mt-4">
                        <span class="w-2 h-2 rounded-full" style="background-color:<?= $s['dot'] ?>;"></span>
                        <span class="text-[11px] font-bold uppercase tracking-wider" style="color:<?= $s['dot'] ?>;"><?= htmlspecialchars($s['cat']) ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </main>

    <footer class="max-w-7xl mx-auto px-6 pb-8 text-center">
        <p class="text-xs text-white/30">Trael · Sistema Interno · SGT v<?= htmlspecialchars(defined('APP_VERSION') ? APP_VERSION : '1.0.0') ?></p>
    </footer>

</body>
</html>
