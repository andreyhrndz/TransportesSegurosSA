<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/operational_writes.php';
require_once __DIR__ . '/../../app/mail/mail_service.php';

// Exige permiso, método POST y CSRF válido.
require_operation_post('USUARIOS_GESTIONAR_CUENTAS');

$currentUser = require_login();

$correoRaw = trim((string)($_POST['correo'] ?? ''));
if ($correoRaw === '' || !filter_var($correoRaw, FILTER_VALIDATE_EMAIL) || strlen($correoRaw) > 150) {
    http_response_code(422);
    echo ui_notice('Ingrese una dirección de correo electrónico válida (máximo 150 caracteres).', 'danger');
    exit;
}

$email = strtolower($correoRaw);

try {
    // Generar token seguro de uso único (32 bytes = 64 caracteres hex)
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);

    $sql = 'DECLARE @inv_id INT; EXEC dbo.sp_crear_invitacion_usuario @correo_destino=?, @token_hash=?, @horas_vigencia=24, @invitacion_id=@inv_id OUTPUT; SELECT @inv_id AS invitacion_id;';
    $stmt = db()->prepare($sql);
    $stmt->execute([$email, $tokenHash]);
    $invitacionId = 0;
    do {
        if ($stmt->columnCount() > 0) {
            $row = $stmt->fetch();
            if (is_array($row) && isset($row['invitacion_id'])) {
                $invitacionId = (int)$row['invitacion_id'];
            }
        }
    } while ($stmt->nextRowset());
    $stmt->closeCursor();

    // Enviar correo de invitación
    $inviteLink = get_app_base_url() . '/auth/onboarding.php?token=' . urlencode($token);
    $mailSender = get_mail_sender();
    $mailHtml = render_invitation_email($inviteLink, $email);
    $mailSender->send($email, 'Invitación a Transportes Seguros S.A.', $mailHtml);

} catch (PDOException $exception) {
    $driverCode = (int)($exception->errorInfo[1] ?? 0);
    $sqlMessage = (string)($exception->errorInfo[2] ?? $exception->getMessage());

    http_response_code(422);
    if ($driverCode === 50430 || str_contains($sqlMessage, '50430')) {
        echo ui_notice('Ya existe una cuenta activa con esta dirección de correo electrónico.', 'danger');
    } elseif ($driverCode === 50431 || str_contains($sqlMessage, '50431')) {
        echo ui_notice('Ya existe una invitación pendiente para este correo. Puede reenviarla desde la lista de invitaciones.', 'warning');
    } else {
        echo ui_notice('No se pudo procesar la invitación. Verifique los datos o consulte al administrador.', 'danger');
    }
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo ui_notice('Error inesperado al generar o enviar la invitación: ' . e($e->getMessage()), 'danger');
    exit;
}

// Éxito: notificar al cliente HTMX para refrescar tablas
header('HX-Trigger: {"usuarioActualizado": true, "invitacionActualizada": true}');
?>
<div class="notification notification-success mb-4" role="status">
    <?= svg_icon('activate') ?>
    <span><strong>¡Invitación enviada con éxito!</strong> Se ha enviado un correo a <strong><?= e($email) ?></strong> con las instrucciones para completar su registro.</span>
</div>
<div class="has-text-right">
    <button type="button" class="button is-light" data-close-modal>Entendido</button>
</div>
