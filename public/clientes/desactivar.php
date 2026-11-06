<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/operational_writes.php';

// ASVS 3.5.1, 8.2.1: exige permiso CLIENTES_DESACTIVAR, POST y CSRF válido.
require_operation_post('CLIENTES_DESACTIVAR');

try {
    $clienteId = operation_id($_POST['cliente_id'] ?? null);
} catch (InvalidArgumentException $exception) {
    operation_error($exception->getMessage());
}

try {
    // ASVS 1.2.4: desactivación no destructiva mediante procedimiento almacenado.
    $stmt = db()->prepare('EXEC dbo.sp_desactivar_cliente @cliente_id=?;');
    $stmt->execute([$clienteId]);
    $stmt->closeCursor();
} catch (PDOException $exception) {
    $code = (int)($exception->errorInfo[1] ?? 0);
    if ($code === 50218) {
        error_log('TSSA security: customer deactivation rejected with SQL code 50218');
        operation_error('El cliente solicitado no existe.', 404);
    }
    operation_sql_error($exception, [
        50217 => 'No tienes permiso para desactivar clientes.',
        50220 => 'No se puede desactivar el cliente porque tiene solicitudes operativas activas.',
        50221 => 'El cliente ya se encuentra inactivo.',
    ]);
}

operation_modal_success('Cliente desactivado correctamente.', 'clienteActualizado', '/clientes/detalle.php?id=' . $clienteId);
