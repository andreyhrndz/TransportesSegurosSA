<?php
require_once __DIR__ . '/../../app/bootstrap.php';

if (!empty($_SESSION['usuario_id'])) {
    header('Location: /');
    exit;
}

header('Cache-Control: no-store');
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!valid_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'La sesión del formulario caducó. Vuelve a intentarlo.';
    } else {
        $window = $_SESSION['login_window'] ?? ['since' => time(), 'count' => 0];
        if (time() - (int)$window['since'] > 300) {
            $window = ['since' => time(), 'count' => 0];
        }
        if ($window['count'] >= 8) {
            $error = 'Demasiados intentos. Vuelve a intentarlo en unos minutos.';
        } else {
            $usuario = trim((string)($_POST['usuario'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $stmt = db()->prepare('SELECT TOP (1) usuario_id, nombre_usuario, password_hash FROM dbo.usuarios WHERE (nombre_usuario = ? OR correo_acceso = ?) AND activo = 1');
            $stmt->execute([$usuario, $usuario]);
            $registro = $stmt->fetch();
            $stmt->closeCursor();
            if ($registro && password_verify($password, (string)$registro['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['usuario_id'] = (int)$registro['usuario_id'];
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                unset($_SESSION['login_window']);
                header('Location: /');
                exit;
            }
            $window['count']++;
            $_SESSION['login_window'] = $window;
            $error = 'Usuario o contraseña incorrectos.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es" data-theme="light">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Iniciar sesión — Transportes Seguros S.A.</title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css"><link rel="stylesheet" href="/assets/css/estilos.css"></head>
<body class="login-page">
<div class="login-side"><div class="login-logo"><span class="brand-icon">TS</span><strong>Transportes Seguros S.A.</strong></div><div class="login-claim"><p class="eyebrow">OPERACIÓN Y CONTROL</p><h1>Tu operación logística, en un solo lugar.</h1><p>Gestión de clientes, solicitudes, viajes y entregas con información centralizada en SQL Server.</p></div><p class="login-note">Proyecto académico · Universidad Nacional · Grupo 6</p></div>
<div class="login-panel"><div class="login-box"><div class="login-mobile-logo">Transportes Seguros S.A.</div><span class="login-eyebrow">BIENVENIDO</span><h2 class="title is-3">Iniciar sesión</h2><p class="mb-5 has-text-grey">Accede al sistema con tu usuario registrado.</p>
<?php if ($error !== ''): ?><div class="notification is-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
<form action="/auth/login.php" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><div class="field"><label class="label" for="usuario">Usuario o correo</label><div class="control"><input id="usuario" class="input" name="usuario" autocomplete="username" maxlength="150" required autofocus></div></div><div class="field"><label class="label" for="password">Contraseña</label><div class="control"><input id="password" class="input" name="password" type="password" autocomplete="current-password" required></div></div><button class="button is-primary is-fullwidth mt-5" type="submit">Entrar al sistema</button></form><p class="login-foot">Utiliza la cuenta <strong>admin_proyecto</strong> y la contraseña que configuraste en SQL Server.</p></div></div>
</body></html>
