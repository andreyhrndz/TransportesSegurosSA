<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

function cl_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function cl_error_code(PDOException $exception): int
{
    return (int)($exception->errorInfo[1] ?? 0);
}

function cl_expect_code(callable $callback, int $expected, string $message): void
{
    try {
        $callback();
    } catch (PDOException $exception) {
        if (cl_error_code($exception) === $expected) {
            return;
        }
        throw new RuntimeException($message . ' Código inesperado: ' . cl_error_code($exception));
    }
    throw new RuntimeException($message . ' No se rechazó la operación.');
}

function cl_exec(PDO $connection, string $sql, array $params = []): void
{
    $stmt = $connection->prepare($sql);
    $stmt->execute($params);
    while ($stmt->nextRowset()) {
    }
    $stmt->closeCursor();
}

function cl_output_id(PDO $connection, string $sql, array $params, string $column): int
{
    $stmt = $connection->prepare($sql);
    $stmt->execute($params);
    $id = 0;
    do {
        if ($stmt->columnCount() > 0) {
            $row = $stmt->fetch();
            if (is_array($row) && isset($row[$column])) {
                $id = (int)$row[$column];
            }
        }
    } while ($stmt->nextRowset());
    $stmt->closeCursor();
    cl_assert($id > 0, 'No se devolvió ' . $column . '.');
    return $id;
}

if (configured_database_name() !== 'TransportesSegurosSA_Test') {
    throw new RuntimeException('Esta prueba requiere TSSA_DB_DATABASE=TransportesSegurosSA_Test.');
}

$connection = db();
require_test_database($connection);

$connection->beginTransaction();
try {
    $suffix = bin2hex(random_bytes(4));

    // Actor sin permisos
    cl_exec($connection, "EXEC sys.sp_set_session_context @key=N'usuario_id', @value=?", [-1]);
    cl_expect_code(
        fn () => cl_exec($connection, 'EXEC dbo.sp_actualizar_cliente_fisico @cliente_id=?,@correo=?,@telefono=?,@direccion=?,@nombre=?,@primer_apellido=?,@segundo_apellido=?;', [1, 'a@b.test', null, null, 'Ana', 'Mora', null]),
        50216,
        'Edición sin permiso debe rechazarse con 50216.'
    );
    cl_expect_code(
        fn () => cl_exec($connection, 'EXEC dbo.sp_desactivar_cliente @cliente_id=?;', [1]),
        50217,
        'Desactivación sin permiso debe rechazarse con 50217.'
    );

    // Actor autorizado (admin_proyecto en Test)
    cl_exec($connection, "EXEC sys.sp_set_session_context @key=N'usuario_id', @value=?", [1]);

    $fisicoId = cl_output_id(
        $connection,
        'DECLARE @id INT; EXEC dbo.sp_crear_cliente_fisico @identificacion=?,@nombre=?,@primer_apellido=?,@segundo_apellido=?,@correo=?,@telefono=?,@cliente_id=@id OUTPUT; SELECT @id AS cliente_id;',
        ['__PDO_FIS_' . $suffix, 'Laura', 'Gómez', 'Rojas', 'laura.' . $suffix . '@example.test', '8888-1111'],
        'cliente_id'
    );

    $juridicoId = cl_output_id(
        $connection,
        'DECLARE @id INT; EXEC dbo.sp_crear_cliente_juridico @identificacion=?,@razon_social=?,@nombre_comercial=?,@representante_legal=?,@correo=?,@cliente_id=@id OUTPUT; SELECT @id AS cliente_id;',
        ['__PDO_JUR_' . $suffix, 'Logística ' . $suffix . ' S.A.', 'Logi' . $suffix, 'Mario Soto', 'corp.' . $suffix . '@example.test'],
        'cliente_id'
    );

    // Edición válida física y jurídica
    cl_exec(
        $connection,
        'EXEC dbo.sp_actualizar_cliente_fisico @cliente_id=?,@correo=?,@telefono=?,@direccion=?,@nombre=?,@primer_apellido=?,@segundo_apellido=?;',
        [$fisicoId, 'laura.edit.' . $suffix . '@example.test', '8888-2222', 'Cartago Centro', 'Laura Elena', 'Gómez', 'Rojas']
    );

    cl_exec(
        $connection,
        'EXEC dbo.sp_actualizar_cliente_juridico @cliente_id=?,@correo=?,@telefono=?,@direccion=?,@razon_social=?,@nombre_comercial=?,@representante_legal=?;',
        [$juridicoId, 'corp.edit.' . $suffix . '@example.test', '2222-3333', 'Alajuela Coyol', 'Logística Global ' . $suffix . ' S.A.', 'LogiGlobal', 'Elena Castro']
    );

    // Verificar inmutabilidad de identificación y subtipo
    $check = $connection->prepare('EXEC dbo.sp_obtener_cliente @cliente_id=?;');
    $check->execute([$fisicoId]);
    $fisico = $check->fetch();
    $check->closeCursor();
    cl_assert(is_array($fisico) && $fisico['identificacion'] === '__PDO_FIS_' . $suffix, 'La identificación física debe permanecer inmutable.');
    cl_assert($fisico['tipo_cliente'] === 'FISICA' && $fisico['nombre'] === 'Laura Elena' && $fisico['direccion'] === 'Cartago Centro', 'Los campos editables físicos deben actualizarse.');

    // Subtipo cruzado e inexistente
    cl_expect_code(
        fn () => cl_exec($connection, 'EXEC dbo.sp_actualizar_cliente_juridico @cliente_id=?,@correo=?,@telefono=?,@direccion=?,@razon_social=?,@nombre_comercial=?,@representante_legal=?;', [$fisicoId, 'x@y.test', null, null, 'Empresa', null, null]),
        50218,
        'Subtipo incompatible debe rechazarse con 50218.'
    );
    cl_expect_code(
        fn () => cl_exec($connection, 'EXEC dbo.sp_desactivar_cliente @cliente_id=?;', [99999999]),
        50218,
        'Cliente inexistente debe rechazarse con 50218.'
    );
    cl_expect_code(
        fn () => cl_exec($connection, 'EXEC dbo.sp_actualizar_cliente_fisico @cliente_id=?,@correo=?,@telefono=?,@direccion=?,@nombre=?,@primer_apellido=?,@segundo_apellido=?;', [$fisicoId, 'invalido', null, null, 'Laura', 'Gómez', null]),
        50219,
        'Correo inválido debe rechazarse con 50219.'
    );

    // Solicitud activa impide desactivación
    $date = (new DateTimeImmutable('+2 days', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $solId = cl_output_id(
        $connection,
        'DECLARE @sid INT; DECLARE @m dbo.tipo_mercancias; INSERT INTO @m (tipo_mercancia_id,descripcion,peso_kg,volumen_m3) VALUES (1,N\'Caja\',10.0,1.0); EXEC dbo.sp_crear_solicitud @codigo=?,@cliente_id=?,@origen_id=1,@destino_id=2,@destinatario_nombre=N\'Dest\',@destinatario_apellido1=N\'Uno\',@destinatario_apellido2=N\'Dos\',@destinatario_identificacion=NULL,@destinatario_telefono=NULL,@fecha_entrega_requerida=?,@mercancias=@m,@solicitud_id=@sid OUTPUT; SELECT @sid AS solicitud_id;',
        ['__CL_SOL_' . $suffix, $fisicoId, $date],
        'solicitud_id'
    );

    cl_expect_code(
        fn () => cl_exec($connection, 'EXEC dbo.sp_desactivar_cliente @cliente_id=?;', [$fisicoId]),
        50220,
        'Cliente con solicitud activa debe rechazarse con 50220.'
    );

    // Cancelar solicitud (pasa a terminal) y desactivar cliente
    cl_exec($connection, 'EXEC dbo.sp_cancelar_solicitud @solicitud_id=?,@motivo=?;', [$solId, 'Cancelación para desactivar cliente en prueba.']);
    cl_exec($connection, 'EXEC dbo.sp_desactivar_cliente @cliente_id=?;', [$fisicoId]);

    cl_expect_code(
        fn () => cl_exec($connection, 'EXEC dbo.sp_desactivar_cliente @cliente_id=?;', [$fisicoId]),
        50221,
        'Cliente ya inactivo debe rechazarse con 50221.'
    );

    // Verificar trazabilidad real en auditorias
    $auditStmt = $connection->prepare("SELECT COUNT(*) FROM dbo.auditorias WHERE entidad = N'clientes' AND registro_id IN (?, ?)");
    $auditStmt->execute([(string)$fisicoId, (string)$juridicoId]);
    $auditCount = (int)$auditStmt->fetchColumn();
    $auditStmt->closeCursor();
    cl_assert($auditCount === 3, 'Se esperaban 3 registros de auditoría para clientes, obtenidos: ' . $auditCount);

    $connection->rollBack();
    echo "OK: contratos SQL de ciclo de vida de clientes verificados con PDO_SQLSRV y revertidos en TransportesSegurosSA_Test.\n";
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    throw $exception;
}
