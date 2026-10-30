<?php
declare(strict_types=1);

/**
 * Procedimiento de Restablecimiento Seguro de Credenciales para admin_proyecto
 * 
 * Este script opera EXCLUSIVAMENTE sobre la base TransportesSegurosSA_Test.
 * Nunca modifica la base original protegida TransportesSegurosSA.
 * 
 * Copia el hash original e intacto desde TransportesSegurosSA hacia TransportesSegurosSA_Test,
 * permitiendo que el usuario vuelva a iniciar sesión con su contraseña original previa al rediseño.
 */

require_once __DIR__ . '/../app/bootstrap.php';

echo "====================================================================\n";
echo " RESTABLECIMIENTO SEGURO DE CREDENCIALES: admin_proyecto (TEST)    \n";
echo "====================================================================\n\n";

// 1. Validar conexión estricta a base de pruebas
$testDb = db();
require_test_database($testDb);

$dbName = (string)$testDb->query('SELECT DB_NAME()')->fetchColumn();
echo "1. Base de datos destino: {$dbName} (CONFIRMADA)\n";

// 2. Conectar en modo solo lectura a la base original protegida para recuperar el hash original
$origDsn = "sqlsrv:Server=localhost;Database=TransportesSegurosSA;Encrypt=yes;TrustServerCertificate=yes";
try {
    $origDb = new PDO($origDsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Exception $e) {
    fwrite(STDERR, "ERROR: No se pudo leer la base original protegida: " . $e->getMessage() . "\n");
    exit(1);
}

$stmtOrig = $origDb->prepare('SELECT usuario_id, nombre_usuario, correo_acceso, activo, password_hash FROM dbo.usuarios WHERE usuario_id = 1');
$stmtOrig->execute();
$origUser = $stmtOrig->fetch();

if (!$origUser || $origUser['nombre_usuario'] !== 'admin_proyecto') {
    fwrite(STDERR, "ERROR: No se encontró la cuenta original admin_proyecto en TransportesSegurosSA.\n");
    exit(1);
}

$origHash = (string)$origUser['password_hash'];
$hashInfo = password_get_info($origHash);

if ($hashInfo['algo'] === 0 || !str_starts_with($origHash, '$2y$')) {
    fwrite(STDERR, "ERROR: El hash original no es un hash BCRYPT válido.\n");
    exit(1);
}

echo "2. Hash original localizado en TransportesSegurosSA:\n";
echo "   - Usuario: {$origUser['nombre_usuario']}\n";
echo "   - Algoritmo: {$hashInfo['algoName']} (BCRYPT)\n";
echo "   - Longitud: " . strlen($origHash) . " caracteres\n";
echo "   - Estado en original: " . ($origUser['activo'] ? 'ACTIVO' : 'INACTIVO') . "\n";

// 3. Verificar estado actual en TransportesSegurosSA_Test
$stmtTest = $testDb->prepare('SELECT usuario_id, nombre_usuario, activo, password_hash FROM dbo.usuarios WHERE usuario_id = 1');
$stmtTest->execute();
$testUser = $stmtTest->fetch();

if (!$testUser || $testUser['nombre_usuario'] !== 'admin_proyecto') {
    fwrite(STDERR, "ERROR: No se encontró la cuenta admin_proyecto en TransportesSegurosSA_Test.\n");
    exit(1);
}

if ($testUser['password_hash'] === $origHash) {
    echo "\n[INFO] El hash de admin_proyecto en TransportesSegurosSA_Test YA coincide exactamente con el original.\n";
    echo "No se requieren modificaciones.\n";
    exit(0);
}

// 4. Aplicar restablecimiento en TransportesSegurosSA_Test
$updateStmt = $testDb->prepare('UPDATE dbo.usuarios SET password_hash = ?, activo = 1 WHERE usuario_id = 1 AND nombre_usuario = ?');
$updateStmt->execute([$origHash, 'admin_proyecto']);
$updateStmt->closeCursor();

// 5. Verificar resultado
$stmtVerify = $testDb->prepare('SELECT password_hash FROM dbo.usuarios WHERE usuario_id = 1');
$stmtVerify->execute();
$verifiedHash = (string)$stmtVerify->fetchColumn();

if ($verifiedHash === $origHash) {
    echo "\n====================================================================\n";
    echo " RESTABLECIMIENTO COMPLETADO CON ÉXITO\n";
    echo "====================================================================\n";
    echo "- Cuenta: admin_proyecto (usuario_id = 1)\n";
    echo "- Base modificada: TransportesSegurosSA_Test\n";
    echo "- Base original TransportesSegurosSA: INTACTA Y PROTEGIDA\n";
    echo "- El hash de admin_proyecto ahora coincide 100% con el original.\n";
    echo "- El propietario de la cuenta ya puede iniciar sesión con su contraseña original.\n";
    exit(0);
} else {
    fwrite(STDERR, "ERROR: La verificación posterior al restablecimiento falló.\n");
    exit(1);
}
