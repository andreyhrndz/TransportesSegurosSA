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
    // Generar token seguro de uso único con CSPRNG (32 bytes = 64 caracteres hex)
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

    // Despacho de correo según transporte configurado
    $transportInfo = get_mail_transport_info();
    $inviteLink = get_app_base_url() . '/auth/onboarding.php?token=' . urlencode($token);
    $mailSender = get_mail_sender();
    $mailHtml = render_invitation_email($inviteLink, $email);
    $sent = $mailSender->send($email, 'Invitación a Transportes Seguros S.A.', $mailHtml);

    $deliveryStatus = 'SIMULADO';
    if ($transportInfo['is_brevo']) {
        $deliveryStatus = $sent ? 'ENVIADO' : 'FALLIDO';
    }

    if ($invitacionId > 0) {
        $stmtStatus = db()->prepare('EXEC dbo.sp_actualizar_estado_envio_invitacion @invitacion_id=?, @estado_envio=?;');
        $stmtStatus->execute([$invitacionId, $deliveryStatus]);
        $stmtStatus->closeCursor();
    }

} catch (PDOException $exception) {
    $driverCode = (int)($exception->errorInfo[1] ?? 0);
    $sqlMessage = (string)($exception->errorInfo[2] ?? $exception->getMessage());

    http_response_code(422);
    if ($driverCode === 50430 || str_contains($sqlMessage, '50430')) {
        echo ui_notice('Ya existe una cuenta activa con esta dirección de correo electrónico.', 'danger');
    } elseif ($driverCode === 50431 || str_contains($sqlMessage, '50431')) {
        echo ui_notice('Ya existe una invitación pendiente para este correo. Puedes reenviarla o revocarla desde la lista de invitaciones.', 'warning');
    } elseif ($driverCode === 50439 || str_contains($sqlMessage, '50439')) {
        echo ui_notice('Se ha alcanzado el límite de 5 invitaciones para este correo en un período de 24 horas.', 'warning');
    } elseif ($driverCode === 50440 || str_contains($sqlMessage, '50440')) {
        echo ui_notice('Debes esperar al menos 60 segundos antes de reenviar una invitación a este correo.', 'warning');
    } else {
        echo ui_notice('No se pudo procesar la invitación: ' . e($sqlMessage), 'danger');
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
<?php if ($transportInfo['is_brevo'] && $sent): ?>
    <div class="notification notification-success mb-4" role="status">
        <?= svg_icon('activate') ?>
        <span><strong>¡Invitación emitida y aceptada por Brevo!</strong> Se solicitó el despacho del correo transaccional a <strong><?= e($email) ?></strong> con el enlace de activación seguro.</span>
    </div>
<?php elseif ($transportInfo['is_brevo'] && !$sent): ?>
    <div class="notification notification-warning mb-4" role="status">
        <?= svg_icon('alert-triangle') ?>
        <span><strong>Invitación registrada en el sistema</strong>, pero la API de Brevo rechazó o no pudo completar la entrega del correo (verifica credenciales o remitente verificado). Puedes reenviarla desde el listado una vez corregida la configuración.</span>
    </div>
<?php else: ?>
    <div class="notification notification-info mb-4" role="status">
        <?= svg_icon('info') ?>
        <span><strong>Invitación registrada con éxito (Modo Simulación / Fake).</strong> En este entorno de pruebas el correo no fue enviado a buzones externos, sino capturado de forma segura localmente. El token y el enlace temporal quedaron válidos por 24 horas.</span>
    </div>
<?php endif; ?>
<div class="has-text-right">
    <button type="button" class="button is-light" data-close-modal>Entendido</button>
</div>
