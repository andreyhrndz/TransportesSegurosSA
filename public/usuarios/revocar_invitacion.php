<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/operational_writes.php';

function invitation_revoke_error(string $message, int $status = 422, string $variant = 'danger'): never
{
    if (is_htmx_request()) {
        header('HX-Retarget: #invitaciones-feedback');
        header('HX-Reswap: innerHTML');
    }
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    exit(ui_notice($message, $variant));
}

// ASVS 3.5.1, 8.2.1: exige USUARIOS_GESTIONAR_CUENTAS, método POST y CSRF válido.
require_permission('USUARIOS_GESTIONAR_CUENTAS');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    invitation_revoke_error('Método no permitido.', 405);
}
if (!valid_csrf($_POST['csrf_token'] ?? null)) {
    error_log('TSSA security: invalid CSRF token on invitation revoke');
    invitation_revoke_error('El formulario caducó. Recarga la página y vuelve a intentarlo.', 403);
}

$currentUser = require_login();

$invitacionId = filter_var($_POST['invitacion_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($invitacionId === false) {
    invitation_revoke_error('Identificador de invitación no válido.', 422);
}

$resultingState = 'REVOCADA';

try {
    $stmt = db()->prepare('EXEC dbo.sp_revocar_invitacion_usuario @invitacion_id=?;');
    $stmt->execute([$invitacionId]);
    $stmt->closeCursor();

    $chk = db()->prepare('SELECT estado FROM dbo.invitaciones_usuario WHERE invitacion_id = ?;');
    $chk->execute([$invitacionId]);
    $stateRow = $chk->fetchColumn();
    $chk->closeCursor();
    if (is_string($stateRow) && $stateRow !== '') {
        $resultingState = strtoupper($stateRow);
    }
} catch (PDOException $e) {
    $driverCode = (int)($e->errorInfo[1] ?? 0);
    // ASVS 16.2.5/16.3.3: registrar únicamente código de error SQL sin exponer detalles crudos al usuario.
    error_log('[revocar_invitacion.php] Error SQL [' . $driverCode . ']');

    if ($driverCode === 50412) {
        invitation_revoke_error('No tienes permiso para gestionar invitaciones.', 403);
    } elseif ($driverCode === 50436) {
        invitation_revoke_error('La invitación solicitada no existe.', 404);
    } elseif ($driverCode === 50441) {
        invitation_revoke_error('Una invitación completada no puede revocarse ni cancelarse. Administra la cuenta desde la pestaña de usuarios.', 409, 'warning');
    } elseif ($driverCode === 50442) {
        invitation_revoke_error('Esta invitación ya fue revocada o cancelada anteriormente.', 409, 'warning');
    } else {
        invitation_revoke_error('No fue posible actualizar el estado de la invitación.', 422);
    }
}

$noticeMsg = $resultingState === 'CANCELADA'
    ? 'Invitación expirada cancelada y conservada en el historial.'
    : 'Invitación revocada correctamente. El enlace quedó invalidado.';

if (is_htmx_request()) {
    $trigger = json_encode([
        'tssaNotice' => [
            'message' => $noticeMsg,
            'error' => false,
            'closeModal' => false,
        ],
    ], JSON_THROW_ON_ERROR);
    header('HX-Trigger-After-Settle: ' . $trigger);
    echo '<div id="invitaciones-feedback" hx-swap-oob="innerHTML"></div>';
}

// Retornar listado actualizado
require __DIR__ . '/invitaciones_lista.php';
