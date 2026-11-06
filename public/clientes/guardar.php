<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/operational_writes.php';

require_operation_post('CLIENTES_CREAR');

$tipo = (string)($_POST['tipo'] ?? '');
if (!in_array($tipo, ['fisico', 'juridico'], true)) {
    operation_error('Tipo de cliente no válido.');
}

try {
    // ASVS 2.2.1, 2.2.2: validación estricta en servidor antes de invocar SP.
    $identificacion = (string)operation_text($_POST['identificacion'] ?? null, 30);
    $correo = (string)operation_email($_POST['correo'] ?? null, 150);

    if ($tipo === 'fisico') {
        $nombre = (string)operation_text($_POST['nombre'] ?? null, 100);
        $apellido = (string)operation_text($_POST['primer_apellido'] ?? null, 100);
        $apellido2 = operation_text($_POST['segundo_apellido'] ?? null, 100, false);
        $telefono = operation_text($_POST['telefono'] ?? null, 30, false);
        $sql = 'DECLARE @nuevo_id INT; EXEC dbo.sp_crear_cliente_fisico @identificacion=?, @nombre=?, @primer_apellido=?, @segundo_apellido=?, @correo=?, @telefono=?, @cliente_id=@nuevo_id OUTPUT;';
        $params = [$identificacion, $nombre, $apellido, $apellido2, $correo, $telefono];
    } else {
        $razon = (string)operation_text($_POST['razon_social'] ?? null, 150);
        $comercial = operation_text($_POST['nombre_comercial'] ?? null, 150, false);
        $representante = operation_text($_POST['representante_legal'] ?? null, 150, false);
        $sql = 'DECLARE @nuevo_id INT; EXEC dbo.sp_crear_cliente_juridico @identificacion=?, @razon_social=?, @nombre_comercial=?, @representante_legal=?, @correo=?, @cliente_id=@nuevo_id OUTPUT;';
        $params = [$identificacion, $razon, $comercial, $representante, $correo];
    }
} catch (InvalidArgumentException $exception) {
    operation_error($exception->getMessage());
}

try {
    // ASVS 1.2.4: ejecución parametrizada mediante contrato SQL aprobado, sin acceso directo a tabla.
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $stmt->closeCursor();
} catch (PDOException $exception) {
    operation_sql_error($exception, [
        2601 => 'Ya existe un cliente con esa identificación.',
        2627 => 'Ya existe un cliente con esa identificación.',
    ]);
}

header('HX-Trigger-After-Settle: clienteCreado');
header('Content-Type: text/html; charset=UTF-8');
echo ui_notice('Cliente registrado correctamente.', 'success');
