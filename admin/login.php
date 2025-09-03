<?php
// admin/login.php
require_once __DIR__ . '/config.php';

$pdo = get_db();

// If no admin user set, redirect to setup
$adminUser = get_setting('admin_user', null);
$adminHash = get_setting('admin_pass', null);
if (!$adminUser || !$adminHash) {
    header('Location: setup.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = isset($_POST['username']) ? trim($_POST['username']) : '';
    $pass = isset($_POST['password']) ? $_POST['password'] : '';

    if ($user === $adminUser && password_verify($pass, $adminHash)) {
        // success
        $_SESSION['is_admin'] = true;
        $_SESSION['admin_user'] = $adminUser;
        header('Location: index.php');
        exit;
    } else {
        $error = 'Usuário ou senha incorretos.';
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Admin — Login</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="admin-login">
    <h1>Painel Administrativo</h1>
    <?php if ($error): ?><div class="error"><?=htmlspecialchars($error)?></div><?php endif; ?>
    <form method="post" action="">
        <label>Usuário
            <input type="text" name="username" required autofocus>
        </label>
        <label>Senha
            <input type="password" name="password" required>
        </label>
        <div class="actions">
            <button type="submit">Entrar</button>
        </div>
    </form>
    <p class="note">Se for a primeira vez, crie uma conta de administrador em <a href="setup.php">Criar Admin</a>.</p>
</div>
</body>
</html>