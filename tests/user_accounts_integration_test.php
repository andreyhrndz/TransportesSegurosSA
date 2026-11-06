<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

function ua_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function ua_code(PDOException $exception): int
{
    return (int)($exception->errorInfo[1] ?? 0);
}

function ua_expect_code(callable $callback, int $expected, string $message): void
{
    try {
        $callback();
    } catch (PDOException $exception) {
        if (ua_code($exception) === $expected) {
            return;
        }
        throw new RuntimeException($message . ' Código inesperado: ' . ua_code($exception));
    }
    throw new RuntimeException($message . ' No se rechazó la operación.');
}

function ua_exec(PDO $connection, string $sql, array $params = []): void
{
    $stmt = $connection->prepare($sql);
    $stmt->execute($params);
    while ($stmt->nextRowset()) {
    }
    $stmt->closeCursor();
}

function ua_output_id(PDO $connection, string $sql, array $params, string $column): int
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
    ua_assert($id > 0, 'No se devolvió ' . $column . '.');
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
    $initialSecret = 'ClaveInicialSegura_' . $suffix;
    $resetSecret = 'ClaveRestablecida_' . $suffix;
    $initialHash = password_hash($initialSecret, PASSWORD_BCRYPT);
    $resetHash = password_hash($resetSecret, PASSWORD_BCRYPT);

    // 1. Actor sin permiso -> 50412 y 50410
    ua_exec($connection, "EXEC sys.sp_set_session_context @key=N'usuario_id', @value=?", [-1]);
    ua_expect_code(
        fn () => ua_exec($connection, 'DECLARE @id INT; EXEC dbo.sp_crear_usuario @nombre_usuario=?,@correo_acceso=?,@password_hash=?,@usuario_id=@id OUTPUT;', ['usr_' . $suffix, 'usr.' . $suffix . '@example.test', $initialHash]),
        50412,
        'Alta de usuario sin permiso debe rechazarse con 50412.'
    );
    ua_expect_code(
        fn () => ua_exec($connection, 'EXEC dbo.sp_revocar_permiso_seguro @usuario_destino=1,@codigo=N\'CLIENTES_VER\';'),
        50410,
        'Revocación sin permiso debe rechazarse con 50410.'
    );

    // 2. Actor autorizado (admin_proyecto = 1)
    ua_exec($connection, "EXEC sys.sp_set_session_context @key=N'usuario_id', @value=?", [1]);

    $userId = ua_output_id(
        $connection,
        'DECLARE @id INT; EXEC dbo.sp_crear_usuario @nombre_usuario=?,@correo_acceso=?,@password_hash=?,@usuario_id=@id OUTPUT; SELECT @id AS usuario_id;',
        ['operador_' . $suffix, 'operador.' . $suffix . '@example.test', $initialHash],
        'usuario_id'
    );

    // Verificar compatibilidad con password_verify del login y ausencia de hash en sp_obtener_usuario
    $hashStmt = $connection->prepare('SELECT password_hash FROM dbo.usuarios WHERE usuario_id = ?');
    $hashStmt->execute([$userId]);
    $storedHash = (string)$hashStmt->fetchColumn();
    $hashStmt->closeCursor();
    ua_assert(password_verify($initialSecret, $storedHash), 'El hash almacenado debe verificar con password_verify().');

    $readStmt = $connection->prepare('EXEC dbo.sp_obtener_usuario @usuario_id=?;');
    $readStmt->execute([$userId]);
    $publicRow = $readStmt->fetch();
    while ($readStmt->nextRowset()) {
    }
    $readStmt->closeCursor();
    ua_assert(is_array($publicRow) && !array_key_exists('password_hash', $publicRow), 'sp_obtener_usuario no debe exponer password_hash.');

    // 3. Duplicado y hash inválido
    ua_expect_code(
        fn () => ua_exec($connection, 'DECLARE @id INT; EXEC dbo.sp_crear_usuario @nombre_usuario=?,@correo_acceso=?,@password_hash=?,@usuario_id=@id OUTPUT;', ['operador_' . $suffix, 'otro.' . $suffix . '@example.test', $initialHash]),
        50416,
        'Usuario duplicado debe rechazarse con 50416.'
    );
    ua_expect_code(
        fn () => ua_exec($connection, 'EXEC dbo.sp_restablecer_password @usuario_id=?,@password_hash=?;', [$userId, 'contraseña-plana']),
        50414,
        'Contraseña sin hash bcrypt válido debe rechazarse con 50414.'
    );

    // 4. Edición y restablecimiento válidos
    ua_exec($connection, 'EXEC dbo.sp_actualizar_usuario @usuario_id=?,@nombre_usuario=?,@correo_acceso=?;', [$userId, 'operador_edit_' . $suffix, 'edit.' . $suffix . '@example.test']);
    ua_exec($connection, 'EXEC dbo.sp_restablecer_password @usuario_id=?,@password_hash=?;', [$userId, $resetHash]);

    $hashStmt->execute([$userId]);
    $newStoredHash = (string)$hashStmt->fetchColumn();
    $hashStmt->closeCursor();
    ua_assert(password_verify($resetSecret, $newStoredHash) && !password_verify($initialSecret, $newStoredHash), 'El restablecimiento debe actualizar el hash bcrypt.');

    // 5. Auto-desactivación (50417) y auto-revocación administrativa (50419)
    ua_expect_code(
        fn () => ua_exec($connection, 'EXEC dbo.sp_cambiar_estado_usuario @usuario_id=1,@activo=0;'),
        50417,
        'Auto-desactivación debe rechazarse con 50417.'
    );
    ua_expect_code(
        fn () => ua_exec($connection, 'EXEC dbo.sp_revocar_permiso_seguro @usuario_destino=1,@codigo=N\'USUARIOS_GESTIONAR_CUENTAS\';'),
        50419,
        'Auto-revocación administrativa crítica debe rechazarse con 50419.'
    );

    // 6. Último gestor activo (50418 y 50420) usando segundo usuario gestor
    ua_exec($connection, 'EXEC dbo.sp_conceder_permiso @usuario_destino=?,@codigo=N\'USUARIOS_GESTIONAR_CUENTAS\';', [$userId]);
    ua_exec($connection, 'EXEC dbo.sp_conceder_permiso @usuario_destino=?,@codigo=N\'USUARIOS_GESTIONAR_PERMISOS\';', [$userId]);

    // Como admin_proyecto (1) sigue siendo el único con USUARIOS_VER, el segundo gestor no puede desactivar a admin_proyecto
    ua_exec($connection, "EXEC sys.sp_set_session_context @key=N'usuario_id', @value=?", [$userId]);
    ua_expect_code(
        fn () => ua_exec($connection, 'EXEC dbo.sp_cambiar_estado_usuario @usuario_id=1,@activo=0;'),
        50418,
        'No debe permitir desactivar al último gestor activo de USUARIOS_VER (50418).'
    );
    ua_expect_code(
        fn () => ua_exec($connection, 'EXEC dbo.sp_revocar_permiso_seguro @usuario_destino=1,@codigo=N\'USUARIOS_VER\';'),
        50420,
        'No debe permitir revocar el último acceso activo de USUARIOS_VER (50420).'
    );

    // 7. Activar/desactivar cuenta ordinaria desde admin_proyecto
    ua_exec($connection, "EXEC sys.sp_set_session_context @key=N'usuario_id', @value=?", [1]);
    ua_exec($connection, 'EXEC dbo.sp_revocar_permiso_seguro @usuario_destino=?,@codigo=N\'USUARIOS_GESTIONAR_CUENTAS\';', [$userId]);
    ua_exec($connection, 'EXEC dbo.sp_revocar_permiso_seguro @usuario_destino=?,@codigo=N\'USUARIOS_GESTIONAR_PERMISOS\';', [$userId]);
    ua_exec($connection, 'EXEC dbo.sp_cambiar_estado_usuario @usuario_id=?,@activo=0;', [$userId]);
    ua_exec($connection, 'EXEC dbo.sp_cambiar_estado_usuario @usuario_id=?,@activo=1;', [$userId]);

    $connection->rollBack();
    echo "OK: contratos SQL de cuentas y permisos verificados con PDO_SQLSRV y revertidos en TransportesSegurosSA_Test.\n";
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    throw $exception;
}
