<?php
// admin/setup.php
require_once __DIR__ . '/config.php';

$pdo = get_db();

// If admin already exists, redirect to login
$adminUser = get_setting('admin_user', null);
$adminHash = get_setting('admin_pass', null);
if ($adminUser && $adminHash) {
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = isset($_POST['username']) ? trim($_POST['username']) : '';
    $pass = isset($_POST['password']) ? $_POST['password'] : '';
    $pass2 = isset($_POST['password2']) ? $_POST['password2'] : '';

    if ($user === '' || $pass === '') {
        $error = 'Informe usuário e senha.';
    } elseif ($pass !== $pass2) {
        $error = 'As senhas não conferem.';
    } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        set_setting('admin_user', $user);
        set_setting('admin_pass', $hash);
        $success = 'Administrador criado. Você pode <a href="login.php">entrar</a> agora.';
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Admin — Criar Administrador</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="admin-login">
    <h1>Criar Administrador</h1>
    <?php if ($error): ?><div class="error"><?=htmlspecialchars($error)?></div><?php endif; ?>
    <?php if ($success): ?><div class="success"><?=$success?></div><?php endif; ?>
    <form method="post" action="">
        <label>Usuário
            <input type="text" name="username" required autofocus>
        </label>
        <label>Senha
            <input type="password" name="password" required>
        </label>
        <label>Confirmar senha
            <input type="password" name="password2" required>
        </label>
        <div class="actions">
            <button type="submit">Criar</button>
        </div>
    </form>
</div>
</body>
</html>