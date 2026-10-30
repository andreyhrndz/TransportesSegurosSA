<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/operational_writes.php';

// ASVS 3.5.1, 8.2.1: valida permiso CLIENTES_EDITAR, método POST y token CSRF.
require_operation_post('CLIENTES_EDITAR');

$subtipo = strtoupper(trim((string)($_POST['subtipo'] ?? '')));
if (!in_array($subtipo, ['FISICA', 'JURIDICA'], true)) {
    operation_error('El subtipo del cliente no es válido.');
}

try {
    // ASVS 2.2.1, 2.2.2: validación de tipos y longitudes antes de invocar SP.
    $clienteId = operation_id($_POST['cliente_id'] ?? null);
    $correo = (string)operation_email($_POST['correo'] ?? null, 150);
    $telefono = operation_text($_POST['telefono'] ?? null, 30, false);
    $direccion = operation_text($_POST['direccion'] ?? null, 250, false);

    if ($subtipo === 'FISICA') {
        $nombre = (string)operation_text($_POST['nombre'] ?? null, 100);
        $primerApellido = (string)operation_text($_POST['primer_apellido'] ?? null, 100);
        $segundoApellido = operation_text($_POST['segundo_apellido'] ?? null, 100, false);
        $sql = 'EXEC dbo.sp_actualizar_cliente_fisico @cliente_id=?, @correo=?, @telefono=?, @direccion=?, @nombre=?, @primer_apellido=?, @segundo_apellido=?;';
        $params = [$clienteId, $correo, $telefono, $direccion, $nombre, $primerApellido, $segundoApellido];
    } else {
        $razonSocial = (string)operation_text($_POST['razon_social'] ?? null, 150);
        $nombreComercial = operation_text($_POST['nombre_comercial'] ?? null, 150, false);
        $representanteLegal = operation_text($_POST['representante_legal'] ?? null, 150, false);
        $sql = 'EXEC dbo.sp_actualizar_cliente_juridico @cliente_id=?, @correo=?, @telefono=?, @direccion=?, @razon_social=?, @nombre_comercial=?, @representante_legal=?;';
        $params = [$clienteId, $correo, $telefono, $direccion, $razonSocial, $nombreComercial, $representanteLegal];
    }
} catch (InvalidArgumentException $exception) {
    operation_error($exception->getMessage());
}

try {
    // ASVS 1.2.4: consulta parametrizada hacia procedimiento almacenado.
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $stmt->closeCursor();
} catch (PDOException $exception) {
    $code = (int)($exception->errorInfo[1] ?? 0);
    if ($code === 50218) {
        error_log('TSSA security: customer update rejected with SQL code 50218');
        operation_error('El cliente no existe o su subtipo no coincide.', 409);
    }
    operation_sql_error($exception, [
        50216 => 'No tienes permiso para editar clientes.',
        50219 => 'Revisa los datos obligatorios y el correo electrónico del cliente.',
    ]);
}

operation_modal_success('Cliente actualizado correctamente.', 'clienteActualizado', '/clientes/detalle.php?id=' . $clienteId);
