<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/user_security.php';
require_once __DIR__ . '/../../app/components.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

$isJson = (
    str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') ||
    strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
);

// Soporte para visualización GET de pantalla de éxito protegida y de un solo uso
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    if (($_GET['exito'] ?? '') === '1') {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $reqTicket = trim((string)($_GET['ticket'] ?? ''));
        $sessionData = $_SESSION['onboarding_activation_success'] ?? null;

        $isLegit = (
            is_array($sessionData) &&
            !empty($sessionData['ticket']) &&
            $reqTicket !== '' &&
            hash_equals((string)$sessionData['ticket'], $reqTicket) &&
            empty($sessionData['consumed']) &&
            time() <= (int)($sessionData['expires_at'] ?? 0)
        );

        // Consumo inmediato de un solo uso: el ticket se destruye de la sesión
        unset($_SESSION['onboarding_activation_success']);

        if (!$isLegit) {
            // Rechazo inmediato de acceso directo, ticket inexistente/vencido o reutilización
            header('Location: /auth/login.php');
            exit;
        }
        ?>
<!DOCTYPE html>
<html lang="es" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>¡Cuenta activada! — Transportes Seguros S.A.</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
    <link rel="stylesheet" href="/assets/css/estilos.css">
</head>
<body class="onboarding-page">
<div class="onboarding-shell">
    <div class="onboarding-box">
        <div class="has-text-centered mb-5">
            <strong class="is-size-5 has-text-primary">Transportes Seguros S.A.</strong>
            <h1 class="title is-3 mt-2">¡Cuenta activada!</h1>
        </div>
        <div class="notification notification-success mb-5" role="status">
            <?= svg_icon('activate') ?>
            <span>Tu cuenta ha sido activada exitosamente. Ya puedes iniciar sesión con tu nombre de usuario o correo y la contraseña que acabas de configurar.</span>
        </div>
        <p class="has-text-grey-light mb-5 has-text-centered">Los permisos asignados a tu cuenta por el administrador han sido configurados según tus funciones operativas.</p>
        <div class="has-text-centered">
            <a href="/auth/login.php" class="button is-primary is-medium is-fullwidth">
                <span>Iniciar sesión ahora</span>
                <?= svg_icon('chevron-right') ?>
            </a>
        </div>
    </div>
</div>
</body>
</html>
        <?php
        exit;
    }

    header('Location: /auth/login.php');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: /auth/login.php');
    exit;
}

$error = null;
$errorField = null;
$errorPaso = null;
$httpStatus = 422;
$tokenRaw = trim((string)($_POST['token'] ?? ''));

if (!valid_csrf($_POST['csrf_token'] ?? null)) {
    $httpStatus = 403;
    $error = 'La sesión del formulario caducó o es inválida. Vuelve a intentarlo.';
} elseif ($tokenRaw === '' || strlen($tokenRaw) !== 64 || !ctype_xdigit($tokenRaw)) {
    $httpStatus = 422;
    $error = 'El token de activación no es válido.';
}

$email = '';
$tokenHash = '';
if ($error === null) {
    try {
        $tokenHash = hash('sha256', $tokenRaw);
        $stmt = db()->prepare('EXEC dbo.sp_validar_token_invitacion @token_hash=?;');
        $stmt->execute([$tokenHash]);
        $inv = $stmt->fetch();
        $stmt->closeCursor();

        $resultado = (string)($inv['resultado'] ?? 'invalido');
        if ($resultado !== 'valida') {
            $httpStatus = 409;
            if ($resultado === 'expirada') {
                $error = 'Este enlace de invitación ha expirado (su validez era de 24 horas). Solicita a un administrador que te reenvíe la invitación.';
            } elseif ($resultado === 'revocada') {
                $error = 'Este enlace de invitación ha sido revocado por el administrador. Solicita una nueva invitación si requieres acceso.';
            } elseif ($resultado === 'completada') {
                $error = 'Esta invitación ya fue completada previamente.';
            } else {
                $error = 'El enlace de invitación ya no es válido o ha expirado.';
            }
        } else {
            $email = (string)($inv['correo_destino'] ?? '');
        }
    } catch (PDOException $e) {
        $httpStatus = 500;
        $error = 'Error de conexión al validar la invitación.';
    }
}

$targetUserId = 0;
if ($error === null) {
    $nombre = trim((string)($_POST['nombre'] ?? ''));
    $primerApellido = trim((string)($_POST['primer_apellido'] ?? ''));
    $segundoApellido = trim((string)($_POST['segundo_apellido'] ?? '')) ?: null;
    $identificacion = trim((string)($_POST['identificacion'] ?? ''));
    $rawUsername = (string)($_POST['nombre_usuario'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $confirmacion = (string)($_POST['password_confirmacion'] ?? '');

    if ($identificacion === '' || strlen($identificacion) > 30) {
        $error = 'El número de cédula o identificación es obligatorio (máximo 30 caracteres).';
        $errorField = 'identificacion';
        $errorPaso = 1;
        $httpStatus = 422;
    } elseif ($nombre === '' || strlen($nombre) > 100) {
        $error = 'El nombre es obligatorio (máximo 100 caracteres).';
        $errorField = 'nombre';
        $errorPaso = 1;
        $httpStatus = 422;
    } elseif ($primerApellido === '' || strlen($primerApellido) > 100) {
        $error = 'El primer apellido es obligatorio (máximo 100 caracteres).';
        $errorField = 'primer_apellido';
        $errorPaso = 1;
        $httpStatus = 422;
    } else {
        $nombreUsuario = '';
        $passwordHash = '';
        try {
            $nombreUsuario = validate_username($rawUsername);
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
            $errorField = 'nombre_usuario';
            $errorPaso = 2;
            $httpStatus = 422;
        }

        if ($error === null) {
            try {
                $passwordHash = validate_new_password_hash($password, $confirmacion, $nombreUsuario, $email);
            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();
                $errorPaso = 2;
                $httpStatus = 422;
                $msgLower = strtolower($error);
                if (str_contains($msgLower, 'coincide') || str_contains($msgLower, 'confirmación')) {
                    $errorField = 'password_confirmacion';
                } else {
                    $errorField = 'password';
                }
            }
        }

        if ($error === null) {
            try {
                $sql = 'DECLARE @usr_id INT; EXEC dbo.sp_completar_invitacion_usuario @token_hash=?, @identificacion=?, @tipo_identificacion=\'01\', @nombre=?, @primer_apellido=?, @segundo_apellido=?, @nombre_usuario=?, @password_hash=?, @telefono=NULL, @nuevo_usuario_id=@usr_id OUTPUT; SELECT @usr_id AS usuario_id;';
                $stmt = db()->prepare($sql);
                $stmt->execute([
                    $tokenHash,
                    $identificacion,
                    $nombre,
                    $primerApellido,
                    $segundoApellido,
                    $nombreUsuario,
                    $passwordHash,
                ]);

                do {
                    if ($stmt->columnCount() > 0) {
                        $row = $stmt->fetch();
                        if (is_array($row) && isset($row['usuario_id'])) {
                            $targetUserId = (int)$row['usuario_id'];
                        }
                    }
                } while ($stmt->nextRowset());
                $stmt->closeCursor();

            } catch (PDOException $e) {
                $driverCode = (int)($e->errorInfo[1] ?? 0);
                $sqlMsg = (string)($e->errorInfo[2] ?? $e->getMessage());

                if ($driverCode === 50432 || str_contains($sqlMsg, '50432')) {
                    $httpStatus = 409;
                    $error = 'La invitación ya fue completada o no está disponible.';
                } elseif ($driverCode === 50433 || str_contains($sqlMsg, '50433')) {
                    $httpStatus = 409;
                    $errorField = 'identificacion';
                    $errorPaso = 1;
                    $error = 'El número de identificación ya está asociado a otra cuenta de usuario.';
                } elseif ($driverCode === 50434 || str_contains($sqlMsg, '50434')) {
                    $httpStatus = 409;
                    $errorField = 'nombre_usuario';
                    $errorPaso = 2;
                    $error = 'El nombre de usuario o correo ya está en uso. Elige un nombre de usuario diferente.';
                } else {
                    $httpStatus = 500;
                    $error = 'Ocurrió un error al registrar los datos en el sistema. Verifica que el nombre de usuario o identificación no estén duplicados.';
                }
            }
        }
    }
}

// Si la activación fue legítima y completada exitosamente, emitir ticket efímero de un solo uso en sesión
$redirectUrl = '';
if ($error === null && $targetUserId > 0) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $successTicket = bin2hex(random_bytes(16));
    $_SESSION['onboarding_activation_success'] = [
        'ticket' => $successTicket,
        'usuario_id' => $targetUserId,
        'expires_at' => time() + 120, // 2 minutos de validez máxima
        'consumed' => false,
    ];
    $redirectUrl = '/auth/onboarding_guardar.php?exito=1&ticket=' . urlencode($successTicket);
}

// Respuesta estructurada para cliente JS (fetch)
if ($isJson) {
    if ($error !== null) {
        http_response_code($httpStatus);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => $error,
            'field' => $errorField,
            'paso' => $errorPaso,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'redirect' => $redirectUrl,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Fallback HTML tradicional sin JavaScript
if ($error !== null) {
    http_response_code($httpStatus);
    ?>
<!DOCTYPE html>
<html lang="es" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>Error de activación — Transportes Seguros S.A.</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
    <link rel="stylesheet" href="/assets/css/estilos.css">
</head>
<body class="onboarding-page">
<div class="onboarding-shell">
    <div class="onboarding-box">
        <div class="has-text-centered mb-5">
            <strong class="is-size-5 has-text-primary">Transportes Seguros S.A.</strong>
            <h1 class="title is-3 mt-2">Error de activación</h1>
        </div>
        <div class="notification notification-danger mb-5" role="alert">
            <?= svg_icon('alert-triangle') ?>
            <span><?= e($error) ?></span>
        </div>
        <div class="is-flex is-justify-content-center" style="gap: 1rem;">
            <a href="/auth/onboarding.php?token=<?= urlencode($tokenRaw) ?>" class="button is-primary">Volver al formulario</a>
            <a href="/auth/login.php" class="button button-secondary">Ir a inicio de sesión</a>
        </div>
    </div>
</div>
</body>
</html>
    <?php
    exit;
}

// Si es fallback sin JS y tuvo éxito: redirección PRG a la pantalla protegida
if ($redirectUrl !== '') {
    header('Location: ' . $redirectUrl);
    exit;
}

header('Location: /auth/login.php');
exit;
