<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/operational_writes.php';
require_once __DIR__ . '/../../app/mail/mail_service.php';

$fromModal = (($_POST['origen'] ?? '') === 'modal');

function invitation_resend_error(string $message, int $status = 422, string $variant = 'danger', bool $fromModal = false): never
{
    if (is_htmx_request() && !$fromModal) {
        header('HX-Retarget: #invitaciones-feedback');
        header('HX-Reswap: innerHTML');
    }
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    exit(ui_notice($message, $variant));
}

require_permission('USUARIOS_GESTIONAR_CUENTAS');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    invitation_resend_error('Método no permitido.', 405, 'danger', $fromModal);
}
if (!valid_csrf($_POST['csrf_token'] ?? null)) {
    error_log('TSSA security: invalid CSRF token on invitation resend');
    invitation_resend_error('El formulario caducó. Recarga la página y vuelve a intentarlo.', 403, 'danger', $fromModal);
}

$currentUser = require_login();

$invitacionId = filter_var($_POST['invitacion_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($invitacionId === false) {
    invitation_resend_error('Identificador de invitación no válido.', 422, 'danger', $fromModal);
}

$nuevoCorreoInput = array_key_exists('nuevo_correo', $_POST) ? trim((string)$_POST['nuevo_correo']) : null;
$nuevoCorreoParam = null;
if ($nuevoCorreoInput !== null) {
    if ($nuevoCorreoInput === '' || !filter_var($nuevoCorreoInput, FILTER_VALIDATE_EMAIL) || strlen($nuevoCorreoInput) > 150) {
        invitation_resend_error('Ingresa una dirección de correo electrónico válida (máximo 150 caracteres).', 422, 'danger', $fromModal);
    }
    $nuevoCorreoParam = strtolower($nuevoCorreoInput);
}

$emailOriginal = '';
$targetEmail = '';
$emailChanged = false;
$nuevaId = 0;
$sent = false;
$transportInfo = ['is_brevo' => false];

try {
    $stmtFind = db()->prepare('SELECT correo_destino, estado FROM dbo.invitaciones_usuario WHERE invitacion_id = ?;');
    $stmtFind->execute([$invitacionId]);
    $invRow = $stmtFind->fetch();
    $stmtFind->closeCursor();

    if (!$invRow) {
        invitation_resend_error('La invitación original no fue encontrada.', 404, 'danger', $fromModal);
    }
    if (strtoupper((string)$invRow['estado']) === 'COMPLETADA') {
        invitation_resend_error('Una invitación ya completada no puede ser reenviada. Administra la cuenta desde el panel de usuarios.', 409, 'warning', $fromModal);
    }

    $emailOriginal = strtolower(trim((string)$invRow['correo_destino']));
    $targetEmail = $nuevoCorreoParam ?? $emailOriginal;
    $emailChanged = ($targetEmail !== $emailOriginal);

    $nuevoToken = bin2hex(random_bytes(32));
    $nuevoTokenHash = hash('sha256', $nuevoToken);

    $sql = 'DECLARE @nueva_id INT; EXEC dbo.sp_reenviar_invitacion_usuario @invitacion_id=?, @nuevo_token_hash=?, @horas_vigencia=24, @nueva_invitacion_id=@nueva_id OUTPUT, @nuevo_correo=?; SELECT @nueva_id AS nueva_invitacion_id;';
    $stmt = db()->prepare($sql);
    $stmt->execute([$invitacionId, $nuevoTokenHash, $nuevoCorreoParam]);
    do {
        if ($stmt->columnCount() > 0) {
            $row = $stmt->fetch();
            if (is_array($row) && isset($row['nueva_invitacion_id'])) {
                $nuevaId = (int)$row['nueva_invitacion_id'];
            }
        }
    } while ($stmt->nextRowset());
    $stmt->closeCursor();

    // Despacho de correo fuera de la transacción SQL
    $transportInfo = get_mail_transport_info();
    $link = get_app_base_url() . '/auth/onboarding.php?token=' . urlencode($nuevoToken);
    $sender = get_mail_sender();
    $sent = $sender->send($targetEmail, 'Nueva invitación a Transportes Seguros S.A.', render_invitation_email($link, $targetEmail));

    $deliveryStatus = 'SIMULADO';
    if ($transportInfo['is_brevo']) {
        $deliveryStatus = $sent ? 'ENVIADO' : 'FALLIDO';
    }

    if ($nuevaId > 0) {
        $stmtStatus = db()->prepare('EXEC dbo.sp_actualizar_estado_envio_invitacion @invitacion_id=?, @estado_envio=?;');
        $stmtStatus->execute([$nuevaId, $deliveryStatus]);
        $stmtStatus->closeCursor();
    }
} catch (PDOException $e) {
    $driverCode = (int)($e->errorInfo[1] ?? 0);
    error_log('[reenviar_invitacion.php] Error de base de datos [' . $driverCode . ']');

    if ($driverCode === 50412) {
        invitation_resend_error('No tienes permiso para gestionar invitaciones.', 403, 'danger', $fromModal);
    } elseif ($driverCode === 50413) {
        invitation_resend_error('El correo electrónico proporcionado no tiene un formato válido.', 422, 'danger', $fromModal);
    } elseif ($driverCode === 50440) {
        invitation_resend_error('Debes esperar al menos 60 segundos antes de reenviar una invitación.', 422, 'warning', $fromModal);
    } elseif ($driverCode === 50439) {
        invitation_resend_error('Se ha alcanzado el límite de 5 invitaciones en un período de 24 horas.', 422, 'warning', $fromModal);
    } elseif ($driverCode === 50430) {
        invitation_resend_error('El correo ya pertenece a una cuenta activa en el sistema.', 409, 'danger', $fromModal);
    } elseif ($driverCode === 50431) {
        invitation_resend_error('Ya existe una invitación pendiente vigente para ese correo electrónico.', 409, 'warning', $fromModal);
    } elseif ($driverCode === 50437) {
        invitation_resend_error('La invitación original no fue encontrada.', 404, 'danger', $fromModal);
    } elseif ($driverCode === 50441) {
        invitation_resend_error('Una invitación ya completada no puede ser reenviada.', 409, 'warning', $fromModal);
    } elseif ($driverCode === 50443) {
        invitation_resend_error('Otra operación sobre esta invitación está en curso. Inténtalo nuevamente en unos segundos.', 409, 'warning', $fromModal);
    } else {
        invitation_resend_error('No se pudo reenviar la invitación en este momento. Verifica los datos e inténtalo nuevamente.', 422, 'danger', $fromModal);
    }
} catch (Throwable) {
    error_log('[reenviar_invitacion.php] Excepción inesperada en reenvío.');
    invitation_resend_error('Ocurrió un error inesperado al procesar el reenvío de la invitación.', 500, 'danger', $fromModal);
}

if ($transportInfo['is_brevo'] && !$sent) {
    $failMsg = 'Se generó un nuevo enlace para ' . $targetEmail . ', pero el proveedor Brevo no aceptó el envío (estado: Fallido). La aceptación del proveedor no garantiza entrega en buzón; puedes reintentar cuando se restablezca el servicio.';
    if ($fromModal) {
        header('HX-Trigger: {"invitacionActualizada": true}');
        header('Content-Type: text/html; charset=UTF-8');
        $retryVals = json_encode([
            'csrf_token' => csrf_token(),
            'invitacion_id' => $nuevaId > 0 ? $nuevaId : $invitacionId,
            'origen' => 'modal',
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $retryTarget = (($_POST['nuevo_correo'] ?? null) !== null) ? '#mensaje-reenviar-invitacion' : '#mensaje-form-usuario';
        echo '<div class="notification notification-warning mb-3" role="alert" data-disable-parent-form="true">'
            . svg_icon('alert-triangle')
            . '<div><p>' . e($failMsg) . '</p>'
            . '<div class="buttons mt-3">'
            . '<button type="button" class="button is-small is-primary" hx-post="/usuarios/reenviar_invitacion.php" hx-target="' . e($retryTarget) . '" hx-swap="innerHTML" hx-vals="' . e($retryVals) . '" hx-disabled-elt="this">' . svg_icon('refresh') . '<span>Reintentar reenvío</span></button>'
            . '<button type="button" class="button is-small button-secondary" data-close-modal>Cerrar</button>'
            . '</div></div></div>';
        exit;
    }

    if (is_htmx_request()) {
        $trigger = json_encode([
            'tssaNotice' => [
                'message' => $failMsg,
                'error' => true,
                'closeModal' => false,
            ],
        ], JSON_THROW_ON_ERROR);
        header('HX-Trigger-After-Settle: ' . $trigger);
        echo '<div id="invitaciones-feedback" hx-swap-oob="innerHTML">' . ui_notice($failMsg, 'warning') . '</div>';
    }
    require __DIR__ . '/invitaciones_lista.php';
    exit;
}

if ($emailChanged) {
    $successMsg = $transportInfo['is_brevo']
        ? 'Correo corregido y nueva invitación aceptada por el proveedor. El enlace anterior quedó invalidado.'
        : 'Correo corregido e invitación registrada en modo prueba (sin envío real). El enlace anterior quedó invalidado.';
} else {
    $successMsg = $transportInfo['is_brevo']
        ? 'Invitación reenviada (aceptada por el proveedor de correo; no garantiza entrega en buzón).'
        : 'Invitación reenviada en modo prueba (sin envío real de correo).';
}

if ($fromModal) {
    operation_modal_success($successMsg, 'invitacionActualizada', '/usuarios.php', ['usuarioActualizado' => true]);
}

if (is_htmx_request()) {
    $trigger = json_encode([
        'tssaNotice' => [
            'message' => $successMsg,
            'error' => false,
            'closeModal' => false,
        ],
    ], JSON_THROW_ON_ERROR);
    header('HX-Trigger-After-Settle: ' . $trigger);
    echo '<div id="invitaciones-feedback" hx-swap-oob="innerHTML"></div>';
}

// Retornar listado actualizado
require __DIR__ . '/invitaciones_lista.php';
