<?php
declare(strict_types=1);

/**
 * Helper reproducible de fixtures sintéticos para QA manual en TransportesSegurosSA_Test.
 *
 * REGLAS DE SEGURIDAD:
 * - Exige estrictamente DB_NAME() = 'TransportesSegurosSA_Test' vía require_test_database().
 * - NUNCA modifica admin_proyecto ni datos preexistentes.
 * - Crea/limpia únicamente entidades prefijadas con QA_ / qa_ / @example.test.
 *
 * Uso:
 *   $env:TSSA_DB_DATABASE = 'TransportesSegurosSA_Test'
 *   php tests/qa_seed_test_fixtures.php seed
 *   php tests/qa_seed_test_fixtures.php cleanup
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo ejecutable por CLI.');
}

putenv('TSSA_DB_DATABASE=TransportesSegurosSA_Test');
require_once __DIR__ . '/../app/bootstrap.php';

if (configured_database_name() !== 'TransportesSegurosSA_Test') {
    fwrite(STDERR, "ERROR: Este script solo puede ejecutarse contra TransportesSegurosSA_Test.\n");
    exit(1);
}

$pdo = db();
require_test_database($pdo);

$action = $argv[1] ?? '';
if (!in_array($action, ['seed', 'cleanup'], true)) {
    fwrite(STDERR, "Uso: php tests/qa_seed_test_fixtures.php [seed|cleanup]\n");
    exit(1);
}

function qa_cleanup(PDO $pdo): void
{
    require_test_database($pdo);

    // 1. Viajes ligados a vehículos/conductores QA
    $pdo->exec("DELETE FROM dbo.viaje_solicitudes WHERE viaje_id IN (SELECT viaje_id FROM dbo.viajes WHERE codigo_viaje LIKE N'QA-%' OR vehiculo_id IN (SELECT vehiculo_id FROM dbo.vehiculos WHERE placa LIKE N'QA-%'))");
    $pdo->exec("DELETE FROM dbo.paradas_viaje WHERE viaje_id IN (SELECT viaje_id FROM dbo.viajes WHERE codigo_viaje LIKE N'QA-%' OR vehiculo_id IN (SELECT vehiculo_id FROM dbo.vehiculos WHERE placa LIKE N'QA-%'))");
    $pdo->exec("DELETE FROM dbo.viajes WHERE codigo_viaje LIKE N'QA-%' OR vehiculo_id IN (SELECT vehiculo_id FROM dbo.vehiculos WHERE placa LIKE N'QA-%')");

    // 2. Vehículos y conductores QA
    $pdo->exec("DELETE FROM dbo.auditorias WHERE entidad = N'vehiculos' AND registro_id IN (SELECT CONVERT(NVARCHAR(100), vehiculo_id) FROM dbo.vehiculos WHERE placa LIKE N'QA-%')");
    $pdo->exec("DELETE FROM dbo.vehiculos WHERE placa LIKE N'QA-%'");

    $pdo->exec("DELETE FROM dbo.auditorias WHERE entidad IN (N'conductores', N'conductores_licencias') AND (registro_id IN (SELECT CONVERT(NVARCHAR(100), conductor_id) FROM dbo.conductores WHERE identificacion LIKE N'QA-COND-%') OR EXISTS (SELECT 1 FROM dbo.conductores c WHERE c.identificacion LIKE N'QA-COND-%' AND dbo.auditorias.registro_id LIKE CONCAT(c.conductor_id, N':%')))");
    $pdo->exec("DELETE FROM dbo.conductores_licencias WHERE conductor_id IN (SELECT conductor_id FROM dbo.conductores WHERE identificacion LIKE N'QA-COND-%')");
    $pdo->exec("DELETE FROM dbo.conductores WHERE identificacion LIKE N'QA-COND-%'");

    // 3. Solicitudes y clientes QA
    $pdo->exec("DELETE FROM dbo.documentos WHERE solicitud_id IN (SELECT solicitud_id FROM dbo.solicitudes WHERE cliente_id IN (SELECT cliente_id FROM dbo.clientes WHERE identificacion IN (N'QA-FIS-001', N'QA-JUR-001')))");
    $pdo->exec("DELETE FROM dbo.incidencias WHERE solicitud_id IN (SELECT solicitud_id FROM dbo.solicitudes WHERE cliente_id IN (SELECT cliente_id FROM dbo.clientes WHERE identificacion IN (N'QA-FIS-001', N'QA-JUR-001')))");
    $pdo->exec("DELETE FROM dbo.entregas WHERE viaje_solicitud_id IN (SELECT viaje_solicitud_id FROM dbo.viaje_solicitudes WHERE solicitud_id IN (SELECT solicitud_id FROM dbo.solicitudes WHERE cliente_id IN (SELECT cliente_id FROM dbo.clientes WHERE identificacion IN (N'QA-FIS-001', N'QA-JUR-001'))))");
    $pdo->exec("DELETE FROM dbo.viaje_solicitudes WHERE solicitud_id IN (SELECT solicitud_id FROM dbo.solicitudes WHERE cliente_id IN (SELECT cliente_id FROM dbo.clientes WHERE identificacion IN (N'QA-FIS-001', N'QA-JUR-001')))");
    $pdo->exec("DELETE FROM dbo.mercancias WHERE solicitud_id IN (SELECT solicitud_id FROM dbo.solicitudes WHERE cliente_id IN (SELECT cliente_id FROM dbo.clientes WHERE identificacion IN (N'QA-FIS-001', N'QA-JUR-001')))");
    $pdo->exec("DELETE FROM dbo.historial_estados_solicitud WHERE solicitud_id IN (SELECT solicitud_id FROM dbo.solicitudes WHERE cliente_id IN (SELECT cliente_id FROM dbo.clientes WHERE identificacion IN (N'QA-FIS-001', N'QA-JUR-001')))");
    $pdo->exec("DELETE FROM dbo.solicitudes WHERE cliente_id IN (SELECT cliente_id FROM dbo.clientes WHERE identificacion IN (N'QA-FIS-001', N'QA-JUR-001'))");
    $pdo->exec("DELETE FROM dbo.auditorias WHERE entidad = N'clientes' AND registro_id IN (SELECT CONVERT(NVARCHAR(100), cliente_id) FROM dbo.clientes WHERE identificacion IN (N'QA-FIS-001', N'QA-JUR-001'))");
    $pdo->exec("DELETE FROM dbo.clientes_fisicos WHERE cliente_id IN (SELECT cliente_id FROM dbo.clientes WHERE identificacion IN (N'QA-FIS-001', N'QA-JUR-001'))");
    $pdo->exec("DELETE FROM dbo.clientes_juridicos WHERE cliente_id IN (SELECT cliente_id FROM dbo.clientes WHERE identificacion IN (N'QA-FIS-001', N'QA-JUR-001'))");
    $pdo->exec("DELETE FROM dbo.clientes WHERE identificacion IN (N'QA-FIS-001', N'QA-JUR-001')");

    // 4. Invitaciones QA
    $pdo->exec("DELETE FROM dbo.auditorias WHERE entidad IN (N'invitaciones_usuario', N'invitaciones_permisos') AND registro_id IN (SELECT CONVERT(NVARCHAR(100), invitacion_id) FROM dbo.invitaciones_usuario WHERE correo_destino IN (N'qa.pendiente@example.test', N'qa.expirada@example.test'))");
    $pdo->exec("DELETE FROM dbo.invitaciones_permisos WHERE invitacion_id IN (SELECT invitacion_id FROM dbo.invitaciones_usuario WHERE correo_destino IN (N'qa.pendiente@example.test', N'qa.expirada@example.test'))");
    $pdo->exec("DELETE FROM dbo.invitaciones_usuario WHERE correo_destino IN (N'qa.pendiente@example.test', N'qa.expirada@example.test')");

    // 5. Usuarios QA (nunca admin_proyecto)
    $pdo->exec("DELETE FROM dbo.auditorias WHERE usuario_id IN (SELECT usuario_id FROM dbo.usuarios WHERE nombre_usuario IN (N'qa_operador_demo', N'qa_lectura_demo'))");
    $pdo->exec("DELETE FROM dbo.usuarios_permisos WHERE usuario_id IN (SELECT usuario_id FROM dbo.usuarios WHERE nombre_usuario IN (N'qa_operador_demo', N'qa_lectura_demo'))");
    $pdo->exec("DELETE FROM dbo.usuarios_perfil WHERE usuario_id IN (SELECT usuario_id FROM dbo.usuarios WHERE nombre_usuario IN (N'qa_operador_demo', N'qa_lectura_demo'))");
    $pdo->exec("DELETE FROM dbo.usuarios WHERE nombre_usuario IN (N'qa_operador_demo', N'qa_lectura_demo')");
}

if ($action === 'cleanup') {
    qa_cleanup($pdo);
    echo "OK: Fixtures QA eliminados limpiamente en TransportesSegurosSA_Test.\n";
    exit(0);
}

// Re-sembrar idempotentemente
qa_cleanup($pdo);

$opPass = 'QaDemoOp!' . bin2hex(random_bytes(4));
$roPass = 'QaDemoRo!' . bin2hex(random_bytes(4));

$insUser = $pdo->prepare('INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo) OUTPUT INSERTED.usuario_id VALUES (?, ?, ?, 1)');
$insUser->execute(['qa_operador_demo', 'qa_operador_demo@example.test', password_hash($opPass, PASSWORD_BCRYPT)]);
$opId = (int)$insUser->fetchColumn();
$insUser->closeCursor();

$pdo->prepare('INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id) SELECT ?, permiso_id FROM dbo.permisos WHERE activo = 1')->execute([$opId]);

$insUser->execute(['qa_lectura_demo', 'qa_lectura_demo@example.test', password_hash($roPass, PASSWORD_BCRYPT)]);
$roId = (int)$insUser->fetchColumn();
$insUser->closeCursor();

$pdo->prepare("INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id) SELECT ?, permiso_id FROM dbo.permisos WHERE activo = 1 AND codigo LIKE N'%_VER'")->execute([$roId]);

// Contexto de sesión del operador QA para crear fixtures vía SPs
$ctx = $pdo->prepare("EXEC sys.sp_set_session_context @key=N'usuario_id', @value=?");
$ctx->execute([$opId]);
$ctx->closeCursor();

// Clientes sintéticos
$pdo->exec("DECLARE @cid INT; EXEC dbo.sp_crear_cliente_fisico @identificacion=N'QA-FIS-001', @nombre=N'Camila', @primer_apellido=N'Vargas', @segundo_apellido=N'Mora', @correo=N'qa.fisico@example.test', @telefono=N'8888-1001', @cliente_id=@cid OUTPUT;");
$pdo->exec("DECLARE @cid INT; EXEC dbo.sp_crear_cliente_juridico @identificacion=N'QA-JUR-001', @razon_social=N'Logística Demo QA S.A.', @nombre_comercial=N'Logística QA', @representante_legal=N'Roberto Salas', @correo=N'qa.juridico@example.test', @cliente_id=@cid OUTPUT;");

// Vehículos sintéticos (B1, B2, B4 articulado y bloqueado NO_CONFORME)
$pdo->exec("DECLARE @vid INT; EXEC dbo.sp_crear_vehiculo @placa=N'QA-B1-4000', @marca=N'Toyota', @modelo=N'Hilux QA', @anio=2025, @capacidad_carga_kg=1200, @capacidad_volumen_m3=5, @kilometraje_actual=0, @estado='DISPONIBLE', @pma_kg=4000, @clase_legal='LIVIANO', @es_articulado=0, @estado_homologacion='PENDIENTE_VERIFICACION', @vehiculo_id=@vid OUTPUT;");
$pdo->exec("DECLARE @vid INT; EXEC dbo.sp_crear_vehiculo @placa=N'QA-B2-7500', @marca=N'Isuzu', @modelo=N'NPR QA', @anio=2025, @capacidad_carga_kg=4000, @capacidad_volumen_m3=18, @kilometraje_actual=0, @estado='DISPONIBLE', @pma_kg=7500, @clase_legal='MEDIANO_RIGIDO', @es_articulado=0, @estado_homologacion='PENDIENTE_VERIFICACION', @vehiculo_id=@vid OUTPUT;");
$pdo->exec("DECLARE @vid INT; EXEC dbo.sp_crear_vehiculo @placa=N'QA-B4-ART', @marca=N'Freightliner', @modelo=N'Cascadia QA', @anio=2025, @capacidad_carga_kg=20000, @capacidad_volumen_m3=65, @kilometraje_actual=0, @estado='DISPONIBLE', @pma_kg=32000, @clase_legal='PESADO_ARTICULADO', @es_articulado=1, @estado_homologacion='PENDIENTE_VERIFICACION', @vehiculo_id=@vid OUTPUT;");
$pdo->exec("DECLARE @vid INT; EXEC dbo.sp_crear_vehiculo @placa=N'QA-BLK-NC', @marca=N'Hino', @modelo=N'300 Bloqueado QA', @anio=2024, @capacidad_carga_kg=1500, @capacidad_volumen_m3=10, @kilometraje_actual=12345.5, @estado='DISPONIBLE', @pma_kg=3500, @clase_legal='LIVIANO', @es_articulado=0, @estado_homologacion='NO_CONFORME', @vehiculo_id=@vid OUTPUT;");

// Conductores sintéticos (B1, B2, B4 y multicategoría heredado)
$future = gmdate('Y-m-d', time() + 86400 * 500);
$stmtCond = $pdo->prepare('DECLARE @cid INT; EXEC dbo.sp_crear_conductor @identificacion=?, @nombre=?, @primer_apellido=?, @segundo_apellido=?, @telefono=?, @correo=?, @numero_licencia=?, @fecha_vencimiento_licencia=?, @estado_operativo=\'DISPONIBLE\', @usuario_id=NULL, @codigo_licencia_inicial=?, @conductor_id=@cid OUTPUT; SELECT @cid AS cid;');
foreach ([
    ['QA-COND-B1', 'Diego', 'Solano', 'Arias', '8800-0001', 'qa.b1@example.test', 'QA-LIC-B1', $future, 'B1'],
    ['QA-COND-B2', 'Elena', 'Quesada', 'Vega', '8800-0002', 'qa.b2@example.test', 'QA-LIC-B2', $future, 'B2'],
    ['QA-COND-B4', 'Marco', 'Alfaro', 'Chaves', '8800-0004', 'qa.b4@example.test', 'QA-LIC-B4', $future, 'B4'],
] as $c) {
    $stmtCond->execute($c);
    $stmtCond->closeCursor();
}

$stmtCond->execute(['QA-COND-MULTI', 'Andrea', 'Navarro', 'Rojas', '8800-0009', 'qa.multi@example.test', 'QA-LIC-MULTI', $future, 'B1']);
$multiId = 0;
do {
    if ($stmtCond->columnCount() > 0) {
        $r = $stmtCond->fetch();
        if (is_array($r) && isset($r['cid'])) {
            $multiId = (int)$r['cid'];
        }
    }
} while ($stmtCond->nextRowset());
$stmtCond->closeCursor();

if ($multiId > 0) {
    $pdo->prepare("INSERT INTO dbo.conductores_licencias (conductor_id, codigo_licencia, numero_licencia, fecha_vencimiento, estado_verificacion, fecha_verificacion, usuario_verificador_id) VALUES (?, 'B4', 'QA-LIC-MULTI', ?, 'VERIFICADA', SYSUTCDATETIME(), ?)")
        ->execute([$multiId, $future, $opId]);
}

// Invitaciones sintéticas (una pendiente y una expirada)
$tokPend = hash('sha256', bin2hex(random_bytes(32)));
$tokExp = hash('sha256', bin2hex(random_bytes(32)));
$pdo->prepare("DECLARE @id INT; EXEC dbo.sp_crear_invitacion_usuario @correo_destino=N'qa.pendiente@example.test', @token_hash=?, @horas_vigencia=24, @invitacion_id=@id OUTPUT; EXEC dbo.sp_actualizar_estado_envio_invitacion @invitacion_id=@id, @estado_envio='SIMULADO';")->execute([$tokPend]);
$pdo->prepare("INSERT INTO dbo.invitaciones_usuario (correo_destino, token_hash, estado, fecha_expiracion, creado_por_usuario_id, estado_envio) VALUES (N'qa.expirada@example.test', ?, 'EXPIRADA', DATEADD(HOUR, -6, SYSUTCDATETIME()), ?, 'SIMULADO')")->execute([$tokExp, $opId]);

echo "=== FIXTURES QA SEMBRADOS EN TransportesSegurosSA_Test ===\n";
echo "Cuenta operador QA : qa_operador_demo  | Clave temporal: {$opPass}\n";
echo "Cuenta lectura QA  : qa_lectura_demo   | Clave temporal: {$roPass}\n";
echo "Vehículos          : QA-B1-4000, QA-B2-7500, QA-B4-ART, QA-BLK-NC (NO_CONFORME, km=12345.5)\n";
echo "Conductores        : QA-COND-B1, QA-COND-B2, QA-COND-B4, QA-COND-MULTI (B1+B4 heredado)\n";
echo "Clientes           : QA-FIS-001 (físico), QA-JUR-001 (jurídico)\n";
echo "Invitaciones       : qa.pendiente@example.test (PENDIENTE), qa.expirada@example.test (EXPIRADA)\n";
echo "Para limpiar       : php tests/qa_seed_test_fixtures.php cleanup\n";
