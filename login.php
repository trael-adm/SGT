<?php
declare(strict_types=1);

require_once __DIR__ . '/config/conexao.php';
require_once __DIR__ . '/config/session.php';

// Logged-in user: show "em construção" notice or go to index
if (isLoggedIn()) {
    if (isset($_GET['aviso']) && $_GET['aviso'] === 'em_construcao') {
        $usuario = currentUser();
        ?>
        <!DOCTYPE html>
        <html lang="pt-BR">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>SGT — Em Construção</title>
            <script src="https://cdn.tailwindcss.com"></script>
            <link rel="preconnect" href="https://fonts.googleapis.com">
            <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
            <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
            <style>
                body { font-family:'Manrope',sans-serif; background-color:#0e2c1d;
                       background-image: radial-gradient(1100px 520px at 50% -8%, rgba(232,155,28,.10), transparent 62%); }
            </style>
        </head>
        <body class="min-h-screen flex items-center justify-center px-4">
            <div class="w-full max-w-md text-center">
                <h1 class="text-white text-6xl font-extrabold tracking-tight leading-none mb-2">SGT</h1>
                <p class="text-base font-medium mb-8" style="color:#E89B1C;">Sistema de Gestão Trael</p>
                <div class="bg-white rounded-3xl shadow-2xl p-8 sm:p-10">
                    <div class="text-5xl mb-4">🚧</div>
                    <h2 class="text-lg font-bold text-gray-800 mb-2">Página em construção</h2>
                    <p class="text-sm text-gray-500 mb-6">
                        Olá, <strong><?= htmlspecialchars($usuario['nome'] ?? '') ?></strong>.<br>
                        Esta área ainda está sendo desenvolvida.
                    </p>
                    <a href="<?= htmlspecialchars(APP_URL) ?>/logout.php"
                       class="inline-block w-full py-3 px-4 rounded-xl font-bold text-sm text-white text-center transition-opacity hover:opacity-90"
                       style="background-color:#E89B1C;">
                        Sair
                    </a>
                </div>
                <p class="text-center text-xs mt-8" style="color:rgba(255,255,255,.4);">Trael · Sistema Interno</p>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    header('Location: ' . APP_URL . '/index.php');
    exit;
}

// ─── Process login form ────────────────────────────────────────────────────────

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $ip    = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua    = $_SERVER['HTTP_USER_AGENT'] ?? '';

    try {
        $pdo = getDB();

        // Brute-force protection desativada neste ambiente (SGT-dev) a pedido, só para
        // destravar os testes manuais da Tela de Produção — o projeto original em
        // c:\laragon\www\SGT mantém a proteção normalmente.

        if (!$erro) {
            if ($email === '' || $senha === '') {
                $erro = 'Preencha e-mail e senha.';
            } else {
                $stmt = $pdo->prepare("
                    SELECT * FROM usuarios
                    WHERE email = ? AND deleted_at IS NULL
                    LIMIT 1
                ");
                $stmt->execute([$email]);
                $usuario = $stmt->fetch();

                if ($usuario && $usuario['ativo'] && password_verify($senha, $usuario['senha'])) {
                    session_regenerate_id(true);
                    $_SESSION['usuario'] = [
                        'id'          => $usuario['id'],
                        'nome'        => $usuario['nome'],
                        'email'       => $usuario['email'],
                        'id_perfil'   => $usuario['id_perfil'],
                        'id_alocacao' => $usuario['id_alocacao'],
                        'e_executor'  => (bool)($usuario['e_executor'] ?? false),
                        'foto'        => $usuario['foto'] ?? null,
                    ];

                    $log = $pdo->prepare("
                        INSERT INTO logs_atividade (id_usuario, tipo, ip, user_agent)
                        VALUES (?, 'login_ok', ?, ?)
                    ");
                    $log->execute([$usuario['id'], $ip, $ua]);

                    header('Location: ' . APP_URL . '/index.php');
                    exit;
                } else {
                    $idUsuario = ($usuario && $usuario['ativo'] === false) ? $usuario['id'] : null;
                    if ($usuario && isset($usuario['id']) && $usuario['ativo']) {
                        $idUsuario = null; // senha errada, não revela o ID
                    }

                    $log = $pdo->prepare("
                        INSERT INTO logs_atividade (id_usuario, tipo, descricao, ip, user_agent)
                        VALUES (?, 'login_erro', 'E-mail ou senha incorretos', ?, ?)
                    ");
                    $log->execute([$idUsuario, $ip, $ua]);

                    $erro = 'E-mail ou senha incorretos.';
                }
            }
        }
    } catch (PDOException $e) {
        $erro = 'Erro ao conectar ao banco de dados. Tente novamente.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SGT — Entrar</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Manrope', sans-serif; }
        .bg-forest {
            background-color:#0e2c1d;
            background-image: radial-gradient(1100px 520px at 50% -8%, rgba(232,155,28,.10), transparent 62%);
        }
        .field {
            background:#FBF6EA;
            border:1px solid #E7DFC9;
            transition: border-color .15s ease, box-shadow .15s ease, background-color .15s ease;
        }
        .field:focus {
            outline:none;
            background:#ffffff;
            border-color:#E89B1C;
            box-shadow:0 0 0 3px rgba(232,155,28,.20);
        }
        .btn-entrar {
            background:#E89B1C;
            box-shadow:0 14px 30px -8px rgba(232,155,28,.55);
            transition: background-color .15s ease, transform .05s ease;
        }
        .btn-entrar:hover { background:#d98f16; }
        .btn-entrar:active { transform: translateY(1px); }
    </style>
</head>
<body class="bg-forest min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-md">

        <div class="text-center mb-8">
            <h1 class="text-white font-extrabold tracking-tight leading-none text-7xl">SGT</h1>
            <p class="mt-3 text-lg font-medium" style="color:#E89B1C;">Sistema de Gestão Trael</p>
        </div>

        <div class="bg-white rounded-3xl shadow-2xl p-8 sm:p-10">

            <?php if ($erro): ?>
            <div class="mb-6 px-4 py-3 rounded-xl bg-red-50 border border-red-200 text-red-700 text-sm">
                <?= htmlspecialchars($erro) ?>
            </div>
            <?php endif; ?>

            <form method="POST" novalidate>

                <div class="mb-5">
                    <label class="block text-sm font-bold mb-2" style="color:#0e3b25;" for="email">
                        E-mail
                    </label>
                    <input
                        type="email"
                        name="email"
                        id="email"
                        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                        class="field w-full px-4 py-3 rounded-xl text-sm text-gray-800 placeholder-gray-400"
                        placeholder="seu@email.com"
                        autocomplete="email"
                        required
                    >
                </div>

                <div class="mb-7">
                    <label class="block text-sm font-bold mb-2" style="color:#0e3b25;" for="senha">
                        Senha
                    </label>
                    <input
                        type="password"
                        name="senha"
                        id="senha"
                        class="field w-full px-4 py-3 rounded-xl text-sm text-gray-800 placeholder-gray-400"
                        placeholder="••••••••"
                        autocomplete="current-password"
                        required
                    >
                </div>

                <button
                    type="submit"
                    class="btn-entrar w-full py-3.5 px-4 rounded-xl font-bold text-sm text-white"
                >
                    Entrar
                </button>

            </form>

            <div class="mt-5 text-center">
                <a href="#" class="text-sm text-gray-400 hover:text-gray-600 transition-colors">
                    Esqueci minha senha
                </a>
            </div>

        </div>

        <p class="text-center text-xs mt-8" style="color:rgba(255,255,255,0.4);">
            Trael · Sistema Interno
        </p>

    </div>
</body>
</html>
