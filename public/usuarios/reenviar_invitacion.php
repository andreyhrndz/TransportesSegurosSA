<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/operational_writes.php';
require_once __DIR__ . '/../../app/mail/mail_service.php';

require_operation_post('USUARIOS_GESTIONAR_CUENTAS');

$currentUser = require_login();

$invitacionId = filter_var($_POST['invitacion_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($invitacionId === false) {
    http_response_code(422);
    echo ui_notice('Identificador de invitación no válido.', 'danger');
    exit;
}

try {
    // Verificar que la invitación exista y esté pendiente
    $stmtFind = db()->prepare('SELECT correo_destino, estado FROM dbo.invitaciones_usuario WHERE invitacion_id = ?;');
    $stmtFind->execute([$invitacionId]);
    $invRow = $stmtFind->fetch();
    $stmtFind->closeCursor();

    if (!$invRow) {
        http_response_code(404);
        echo ui_notice('La invitación no existe.', 'danger');
        exit;
    }
    if ($invRow['estado'] !== 'PENDIENTE') {
        http_response_code(422);
        echo ui_notice('Sólo es posible reenviar invitaciones en estado pendiente.', 'warning');
        exit;
    }

    $email = (string)$invRow['correo_destino'];
    $nuevoToken = bin2hex(random_bytes(32));
    $nuevoTokenHash = hash('sha256', $nuevoToken);

    $stmt = db()->prepare('DECLARE @nueva_id INT; EXEC dbo.sp_reenviar_invitacion_usuario @invitacion_id=?, @nuevo_token_hash=?, @horas_vigencia=24, @nueva_invitacion_id=@nueva_id OUTPUT; SELECT @nueva_id AS nueva_invitacion_id;');
    $stmt->execute([$invitacionId, $nuevoTokenHash]);
    $stmt->closeCursor();

    // Enviar correo
    $link = get_app_base_url() . '/auth/onboarding.php?token=' . urlencode($nuevoToken);
    $sender = get_mail_sender();
    $sender->send($email, 'Nueva invitación a Transportes Seguros S.A.', render_invitation_email($link, $email));

} catch (PDOException $e) {
    http_response_code(500);
    echo ui_notice('Error en base de datos al reenviar la invitación: ' . e($e->getMessage()), 'danger');
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo ui_notice('Error al reenviar el correo: ' . e($e->getMessage()), 'danger');
    exit;
}

// Retornar listado actualizado
require __DIR__ . '/invitaciones_lista.php';
