<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/operational_writes.php';
require_once __DIR__ . '/../../app/mail/mail_service.php';

// ASVS 3.5.1, 8.2.1: exige permiso, método POST y CSRF válido.
require_operation_post('USUARIOS_GESTIONAR_CUENTAS');

$currentUser = require_login();

$correoRaw = trim((string)($_POST['correo'] ?? ''));
if ($correoRaw === '' || !filter_var($correoRaw, FILTER_VALIDATE_EMAIL) || strlen($correoRaw) > 150) {
    http_response_code(422);
    echo ui_notice('Ingrese una dirección de correo electrónico válida (máximo 150 caracteres).', 'danger');
    exit;
}

$email = strtolower($correoRaw);

// Validación de permisos solicitados
$permisosPost = $_POST['permisos'] ?? [];
if (!is_array($permisosPost)) {
    http_response_code(422);
    echo ui_notice('La lista de permisos enviada no tiene un formato válido.', 'danger');
    exit;
}

$canManagePermissions = has_permission('USUARIOS_GESTIONAR_PERMISOS');

if (!empty($permisosPost) && !$canManagePermissions) {
    http_response_code(403);
    echo ui_notice('No tienes autorización para asignar permisos individuales en la invitación.', 'danger');
    exit;
}

$permisosSanitizados = [];
if ($canManagePermissions && !empty($permisosPost)) {
    foreach ($permisosPost as $codigo) {
        $cod = trim((string)$codigo);
        if ($cod === '') continue;

        if (!preg_match('/^[A-Z0-9_]{3,100}$/', $cod)) {
            http_response_code(422);
            echo ui_notice("Código de permiso inválido: {$cod}", 'danger');
            exit;
        }

        // Prevención estricta de elevación de privilegios: el actor debe tener el permiso
        if (!has_permission($cod)) {
            http_response_code(403);
            echo ui_notice("No puedes otorgar el permiso '{$cod}' porque tu propia cuenta no lo posee.", 'danger');
            exit;
        }

        $permisosSanitizados[] = $cod;
    }
    $permisosSanitizados = array_values(array_unique($permisosSanitizados));
}

// Si puede gestionar permisos pero seleccionó 0, exigir confirmación explícita
if ($canManagePermissions && empty($permisosSanitizados) && empty($_POST['confirmar_sin_permisos'])) {
    http_response_code(422);
    echo ui_notice('No has seleccionado ningún permiso para el colaborador. Si deseas invitarlo con cero permisos, marca la casilla de confirmación.', 'warning');
    exit;
}

$db = db();
$invitacionId = 0;

try {
    // ASVS 11.5.1: token criptográfico de un solo uso (32 bytes = 64 hex); en BD sólo se almacena el hash SHA-256.
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);

    $db->beginTransaction();

    $sql = 'DECLARE @inv_id INT; EXEC dbo.sp_crear_invitacion_usuario @correo_destino=?, @token_hash=?, @horas_vigencia=24, @invitacion_id=@inv_id OUTPUT; SELECT @inv_id AS invitacion_id;';
    $stmt = $db->prepare($sql);
    $stmt->execute([$email, $tokenHash]);
    do {
        if ($stmt->columnCount() > 0) {
            $row = $stmt->fetch();
            if (is_array($row) && isset($row['invitacion_id'])) {
                $invitacionId = (int)$row['invitacion_id'];
            }
        }
    } while ($stmt->nextRowset());
    $stmt->closeCursor();

    if ($invitacionId < 1) {
        throw new RuntimeException('No se pudo generar la invitación.');
    }

    // Asignar los permisos preseleccionados dentro de la misma transacción
    if (!empty($permisosSanitizados)) {
        $stmtPerm = $db->prepare('EXEC dbo.sp_asignar_permiso_invitacion @invitacion_id=?, @codigo=?;');
        foreach ($permisosSanitizados as $codPerm) {
            $stmtPerm->execute([$invitacionId, $codPerm]);
            $stmtPerm->closeCursor();
        }
    }

    $db->commit();

    // Despacho de correo según transporte configurado
    $transportInfo = get_mail_transport_info();
    $inviteLink = get_app_base_url() . '/auth/onboarding.php?token=' . urlencode($token);
    $mailSender = get_mail_sender();
    $mailHtml = render_invitation_email($inviteLink, $email);
    $sent = $mailSender->send($email, 'Invitación a Transportes Seguros S.A.', $mailHtml);

    $mailCategory = ($mailSender instanceof BrevoApiSender) ? $mailSender->getLastCategory() : 'unknown';

    $deliveryStatus = 'SIMULADO';
    if ($transportInfo['is_brevo']) {
        $deliveryStatus = $sent ? 'ENVIADO' : 'FALLIDO';
    }

    if ($invitacionId > 0) {
        $stmtStatus = $db->prepare('EXEC dbo.sp_actualizar_estado_envio_invitacion @invitacion_id=?, @estado_envio=?;');
        $stmtStatus->execute([$invitacionId, $deliveryStatus]);
        $stmtStatus->closeCursor();
    }
} catch (PDOException $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $driverCode = (int)($exception->errorInfo[1] ?? 0);
    error_log('[invitar.php] Error de base de datos [' . $driverCode . ']');

    $status = in_array($driverCode, [50430, 50431], true) ? 409 : ($driverCode === 50445 ? 403 : 422);
    http_response_code($status);
    if ($driverCode === 50430) {
        echo ui_notice('Ya existe una cuenta activa con esta dirección de correo electrónico.', 'danger');
    } elseif ($driverCode === 50431) {
        echo ui_notice('Ya existe una invitación pendiente para este correo. Puedes reenviarla o revocarla desde la lista de invitaciones.', 'warning');
    } elseif ($driverCode === 50439) {
        echo ui_notice('Se ha alcanzado el límite de 5 invitaciones para este correo en un período de 24 horas.', 'warning');
    } elseif ($driverCode === 50440) {
        echo ui_notice('Debes esperar al menos 60 segundos antes de reenviar una invitación a este correo.', 'warning');
    } elseif ($driverCode === 50445) {
        echo ui_notice('No puedes otorgar un permiso que tu propia cuenta no posee.', 'danger');
    } else {
        echo ui_notice('No se pudo procesar la invitación debido a una regla del sistema. Por favor revise los datos ingresados.', 'danger');
    }
    exit;
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[invitar.php] Excepción inesperada en despacho de invitación.');
    http_response_code(500);
    echo ui_notice('Ocurrió un error inesperado al procesar la invitación. Por favor intente nuevamente más tarde.', 'danger');
    exit;
}

$totalPermsConfig = count($permisosSanitizados);

// Caso 1: Brevo aceptó el envío (HTTP 2xx) -> cerrar modal automáticamente con toast y refrescar listados.
if ($transportInfo['is_brevo'] && $sent) {
    operation_modal_success(
        'Invitación enviada (aceptada por el proveedor de correo).',
        'invitacionActualizada',
        '/usuarios.php',
        ['usuarioActualizado' => true]
    );
}

// Caso 2: Modo simulación / Fake -> cerrar modal automáticamente con mensaje transparente de modo prueba.
if (!$transportInfo['is_brevo']) {
    if (is_htmx_request()) {
        $triggerPayload = json_encode([
            'usuarioActualizado' => true,
            'invitacionActualizada' => true,
            'tssaNotice' => [
                'message' => 'Invitación registrada en modo prueba (sin envío real de correo).',
                'error' => false,
                'closeModal' => true,
            ],
        ], JSON_THROW_ON_ERROR);
        header('HX-Trigger-After-Settle: ' . $triggerPayload);
        header('Content-Type: text/html; charset=UTF-8');
        exit(ui_notice(
            "Invitación registrada en modo prueba con {$totalPermsConfig} permiso(s) planificado(s). El enlace quedó vigente por 24 horas.",
            'info'
        ));
    }

    $_SESSION['tssa_flash_notice'] = [
        'message' => 'Invitación registrada en modo prueba (sin envío real de correo).',
        'error' => false,
    ];
    header('Location: /usuarios.php', true, 303);
    exit;
}

// Caso 3: Invitación guardada con estado_envio = 'FALLIDO', pero falló el despacho en Brevo.
// Mantener modal abierto sin falso éxito verde, refrescar tabla en segundo plano y ofrecer reenvío explícito sin duplicar el alta.
header('HX-Trigger: {"usuarioActualizado": true, "invitacionActualizada": true}');
header('Content-Type: text/html; charset=UTF-8');
$catMensaje = match ($mailCategory) {
    'config_invalid' => 'Configuración de remitente o credenciales inválida.',
    'curl_missing' => 'La extensión cURL no está disponible en el servidor.',
    'dns' => 'Error de resolución DNS al conectar con Brevo.',
    'tls' => 'Error de validación de certificados de seguridad TLS.',
    'timeout' => 'Tiempo de espera agotado al comunicar con Brevo.',
    'provider_reject' => 'El proveedor Brevo rechazó el despacho (verifique el remitente autorizado).',
    default => 'Fallo de comunicación con el servicio de correo.',
};
$resendVals = json_encode([
    'csrf_token' => csrf_token(),
    'invitacion_id' => $invitacionId,
    'origen' => 'modal',
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
?>
<div class="notification notification-warning mb-3" role="alert" data-disable-parent-form="true">
    <?= svg_icon('alert-triangle') ?>
    <div>
        <p><strong>Invitación registrada (estado de entrega: Fallido)</strong> con <strong><?= $totalPermsConfig ?></strong> permiso(s) planificado(s), pero no pudo completarse el despacho del correo vía Brevo (<?= e($catMensaje) ?>).</p>
        <p class="is-size-7 mt-1">La invitación ya quedó guardada para <strong><?= e($email) ?></strong>. Para evitar duplicados, utiliza la acción de reenvío o gestiónala desde la pestaña Invitaciones.</p>
        <div class="buttons mt-3">
            <button type="button" class="button is-small is-primary" hx-post="/usuarios/reenviar_invitacion.php" hx-target="#mensaje-form-usuario" hx-swap="innerHTML" hx-vals="<?= e($resendVals) ?>" hx-disabled-elt="this">
                <?= svg_icon('refresh') ?>
                <span>Reenviar invitación</span>
            </button>
            <button type="button" class="button is-small button-secondary" data-close-modal>Cerrar</button>
        </div>
    </div>
</div>
