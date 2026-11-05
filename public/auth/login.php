<?php
require_once __DIR__ . '/../../app/bootstrap.php';

if (!empty($_SESSION['usuario_id']) && session_is_current()) {
    header('Location: /');
    exit;
}

header('Cache-Control: no-store, max-age=0');
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!valid_csrf($_POST['csrf_token'] ?? null)) {
        // ASVS 3.5.1, 16.3.3: rechazar y registrar solicitudes forjadas sin detalles internos.
        error_log('TSSA security: invalid CSRF token on login');
        $error = 'La sesión del formulario caducó. Vuelve a intentarlo.';
    } else {
        $window = $_SESSION['login_window'] ?? ['since' => time(), 'count' => 0];
        if (time() - (int)$window['since'] > 300) {
            $window = ['since' => time(), 'count' => 0];
        }
        // ASVS 2.4.1, 6.3.1: limitar intentos por sesión sin bloquear cuentas de terceros.
        if ($window['count'] >= 8) {
            error_log('TSSA security: login rate limit reached');
            $error = 'Demasiados intentos. Vuelve a intentarlo en unos minutos.';
        } else {
            $usuario = trim((string)($_POST['usuario'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            if ($usuario === '' || strlen($usuario) > 150 || $password === '' || strlen($password) > 1024) {
                $window['count']++;
                $_SESSION['login_window'] = $window;
                error_log('TSSA security: failed password login');
                $error = 'Usuario o contraseña incorrectos.';
            } else {
                // ASVS 1.2.4, 6.2.7: consulta parametrizada y password_verify contra el hash existente.
                $stmt = db()->prepare('SELECT TOP (1) usuario_id, nombre_usuario, password_hash FROM dbo.usuarios WHERE (nombre_usuario = ? OR correo_acceso = ?) AND activo = 1');
                $stmt->execute([$usuario, $usuario]);
                $registro = $stmt->fetch();
                $stmt->closeCursor();
                if ($registro && password_verify($password, (string)$registro['password_hash'])) {
                    // ASVS 7.2.2: rotar identificador y token CSRF después de autenticar.
                    session_regenerate_id(true);
                    $_SESSION['usuario_id'] = (int)$registro['usuario_id'];
                    $_SESSION['authenticated_at'] = time();
                    $_SESSION['last_activity_at'] = time();
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    unset($_SESSION['login_window']);
                    error_log('TSSA security: successful password login');
                    header('Location: /');
                    exit;
                }
                $window['count']++;
                $_SESSION['login_window'] = $window;
                error_log('TSSA security: failed password login');
                // ASVS 6.3.8: respuesta uniforme para usuario inexistente, inactivo o contraseña inválida.
                $error = 'Usuario o contraseña incorrectos.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>Iniciar sesión — Transportes Seguros S.A.</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
    <link rel="stylesheet" href="/assets/css/estilos.css">
</head>
<body class="login-page">
<div class="login-side">
    <div class="login-logo">
        <strong>Transportes Seguros S.A.</strong>
        <span>Centro de Operaciones Logísticas</span>
    </div>
    <div class="login-claim">
        <span class="login-badge">Plataforma Empresarial</span>
        <h1>Coordinación logística con visibilidad operativa en tiempo real.</h1>
        <p>Gestiona clientes, solicitudes de transporte, viajes en ruta, flota vehicular y comprobación de entregas desde una consola centralizada.</p>
        <div class="login-highlights">
            <div class="login-highlight-item">
                <?= svg_icon('trips') ?>
                <div>
                    <strong>Despacho y seguimiento de viajes</strong>
                    <span>Control de paradas, asignaciones y estados de ruta en Costa Rica.</span>
                </div>
            </div>
            <div class="login-highlight-item">
                <?= svg_icon('deliveries') ?>
                <div>
                    <strong>Entregas e incidencias verificadas</strong>
                    <span>Registro documental y atención oportuna de eventos operativos.</span>
                </div>
            </div>
            <div class="login-highlight-item">
                <?= svg_icon('shield') ?>
                <div>
                    <strong>Acceso seguro por permisos</strong>
                    <span>Trazabilidad completa de operaciones por cuenta autorizada.</span>
                </div>
            </div>
        </div>
    </div>
    <div class="login-footer-note">Transportes Seguros S.A. · Costa Rica</div>
</div>
<div class="login-panel">
    <div class="login-box">
        <div class="login-mobile-logo">
            <strong>Transportes Seguros S.A.</strong>
            <span>Operaciones Logísticas</span>
        </div>
        <div class="login-box-header">
            <h1 class="title is-3">Iniciar sesión</h1>
            <p class="login-subtitle">Ingresa tus credenciales corporativas para continuar.</p>
        </div>
        <?php if ($error !== ''): ?><?= ui_notice($error, 'danger') ?><?php endif; ?>
        <form action="/auth/login.php" method="post" class="login-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <div class="field">
                <label class="label" for="usuario">Usuario o correo electrónico</label>
                <div class="control input-with-icon">
                    <?= svg_icon('user', '', 'input-icon') ?>
                    <input id="usuario" class="input" name="usuario" placeholder="Ej. admin o correo@empresa.cr" autocomplete="username" maxlength="150" required autofocus>
                </div>
            </div>
            <div class="field">
                <div class="is-flex is-justify-content-space-between is-align-items-center mb-1">
                    <label class="label mb-0" for="password">Contraseña</label>
                    <a href="/auth/recuperar.php" class="is-size-7 has-text-grey-light">¿Olvidaste tu contraseña?</a>
                </div>
                <div class="control input-with-icon">
                    <?= svg_icon('lock', '', 'input-icon') ?>
                    <input id="password" class="input" name="password" type="password" placeholder="Ingresa tu contraseña" autocomplete="current-password" maxlength="1024" required>
                </div>
            </div>
            <button class="button is-primary is-fullwidth mt-5" type="submit">
                <span>Entrar al panel</span>
                <?= svg_icon('chevron-right') ?>
            </button>
        </form>
    </div>
</div>
</body>
</html>
