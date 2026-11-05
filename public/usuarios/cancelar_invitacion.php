<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/operational_writes.php';

require_operation_post('USUARIOS_GESTIONAR_CUENTAS');

$currentUser = require_login();

$invitacionId = filter_var($_POST['invitacion_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($invitacionId === false) {
    http_response_code(422);
    echo ui_notice('Identificador de invitación no válido.', 'danger');
    exit;
}

try {
    $stmt = db()->prepare('EXEC dbo.sp_cancelar_invitacion_usuario @invitacion_id=?;');
    $stmt->execute([$invitacionId]);
    $stmt->closeCursor();
} catch (PDOException $e) {
    http_response_code(500);
    echo ui_notice('Error en base de datos al cancelar la invitación: ' . e($e->getMessage()), 'danger');
    exit;
}

// Retornar listado actualizado
require __DIR__ . '/invitaciones_lista.php';
