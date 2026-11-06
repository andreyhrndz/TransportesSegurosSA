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
    // Verificar que la invitación exista y no esté completada
    $stmtFind = db()->prepare('SELECT correo_destino, estado, ultimo_envio_en FROM dbo.invitaciones_usuario WHERE invitacion_id = ?;');
    $stmtFind->execute([$invitacionId]);
    $invRow = $stmtFind->fetch();
    $stmtFind->closeCursor();

    if (!$invRow) {
        http_response_code(404);
        echo ui_notice('La invitación original no fue encontrada.', 'danger');
        exit;
    }
    if ($invRow['estado'] === 'COMPLETADA') {
        http_response_code(422);
        echo ui_notice('Una invitación ya completada no puede ser reenviada. Administra la cuenta desde el panel de usuarios.', 'warning');
        exit;
    }

    $email = (string)$invRow['correo_destino'];
    $nuevoToken = bin2hex(random_bytes(32));
    $nuevoTokenHash = hash('sha256', $nuevoToken);

    $sql = 'DECLARE @nueva_id INT; EXEC dbo.sp_reenviar_invitacion_usuario @invitacion_id=?, @nuevo_token_hash=?, @horas_vigencia=24, @nueva_invitacion_id=@nueva_id OUTPUT; SELECT @nueva_id AS nueva_invitacion_id;';
    $stmt = db()->prepare($sql);
    $stmt->execute([$invitacionId, $nuevoTokenHash]);
    $nuevaId = 0;
    do {
        if ($stmt->columnCount() > 0) {
            $row = $stmt->fetch();
            if (is_array($row) && isset($row['nueva_invitacion_id'])) {
                $nuevaId = (int)$row['nueva_invitacion_id'];
            }
        }
    } while ($stmt->nextRowset());
    $stmt->closeCursor();

    // Despacho de correo
    $transportInfo = get_mail_transport_info();
    $link = get_app_base_url() . '/auth/onboarding.php?token=' . urlencode($nuevoToken);
    $sender = get_mail_sender();
    $sent = $sender->send($email, 'Nueva invitación a Transportes Seguros S.A.', render_invitation_email($link, $email));

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
    $sqlMessage = (string)($e->errorInfo[2] ?? $e->getMessage());
    error_log('[reenviar_invitacion.php] Error de base de datos [' . $driverCode . ']: ' . $sqlMessage);

    http_response_code(422);
    if ($driverCode === 50440 || str_contains($sqlMessage, '50440')) {
        echo ui_notice('Debes esperar al menos 60 segundos antes de reenviar una invitación a este correo.', 'warning');
    } elseif ($driverCode === 50439 || str_contains($sqlMessage, '50439')) {
        echo ui_notice('Se ha alcanzado el límite de 5 invitaciones para este correo en un período de 24 horas.', 'warning');
    } elseif ($driverCode === 50430 || str_contains($sqlMessage, '50430')) {
        echo ui_notice('El correo ya pertenece a una cuenta activa en el sistema.', 'danger');
    } else {
        echo ui_notice('No se pudo reenviar la invitación en este momento. Verifique el estado de la solicitud.', 'danger');
    }
    exit;
} catch (Throwable $e) {
    error_log('[reenviar_invitacion.php] Excepción inesperada: ' . $e->getMessage());
    http_response_code(500);
    echo ui_notice('Ocurrió un error inesperado al procesar el reenvío de la invitación.', 'danger');
    exit;
}

// Retornar listado actualizado
require __DIR__ . '/invitaciones_lista.php';
