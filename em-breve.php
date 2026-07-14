<?php
declare(strict_types=1);

require_once __DIR__ . '/config/conexao.php';
require_once __DIR__ . '/config/session.php';

requireLogin();

$base = defined('APP_URL') ? APP_URL : '';

// Nome do sistema solicitado (apenas para exibição)
$sistema = trim((string) ($_GET['s'] ?? ''));
$sistemasValidos = ['SGE', 'SOMA', 'Produção', 'Retrabalho', '5S', 'Ausências', 'Incidentes', 'Perdas', 'Paradas', 'Resumo Gerencial'];
if ($sistema === '' || !in_array($sistema, $sistemasValidos, true)) {
    $sistema = 'Este módulo';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SGT — Em breve</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; background-color:#0e2c1d; }</style>
</head>
<body class="min-h-screen flex items-center justify-center text-white">
    <div class="w-full max-w-md mx-4 text-center">

        <div class="flex items-center justify-center gap-3 mb-8">
            <svg width="36" height="36" viewBox="0 0 80 80">
                <rect width="80" height="80" rx="18" fill="#E89B1C"/>
                <polygon points="50,22 28,56 46,56 44,80 68,48 50,48 52,22" fill="#0e2c1d"/>
            </svg>
            <div class="text-left">
                <p class="text-lg font-extrabold leading-none">SGT</p>
                <p class="text-[11px] font-medium" style="color:#E89B1C;">Sistema de Gestão Trael</p>
            </div>
        </div>

        <div class="bg-white/[0.06] border border-white/10 rounded-2xl p-10">
            <div class="text-5xl mb-4">🚧</div>
            <h1 class="text-xl font-bold mb-2"><?= htmlspecialchars($sistema) ?> — em breve</h1>
            <p class="text-sm text-white/55 mb-8">
                Este módulo ainda está em desenvolvimento e estará disponível em uma próxima atualização.
            </p>
            <a href="<?= htmlspecialchars($base) ?>/index.php"
               class="inline-block w-full py-2.5 px-4 rounded-lg font-semibold text-sm text-center transition-opacity hover:opacity-90"
               style="background-color:#E89B1C;color:#0e2c1d;">
                ← Voltar ao Hub
            </a>
        </div>

    </div>
</body>
</html>
