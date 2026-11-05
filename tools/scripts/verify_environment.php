<?php
declare(strict_types=1);

/**
 * Script de diagnóstico del entorno de ejecución PHP y Microsoft SQL Server.
 * Ejecutar con: php tools/scripts/verify_environment.php
 */

echo "====================================================================\n";
echo "Transportes Seguros S.A. — Diagnóstico del Entorno de Ejecución\n";
echo "====================================================================\n\n";

$errors = 0;

// 1. Versión de PHP
$phpVersion = PHP_VERSION;
echo "[PHP] Versión detectada: {$phpVersion}\n";
if (version_compare($phpVersion, '8.4.0', '>=')) {
    echo "  ✓ Conforme (PHP 8.4+ requerido)\n";
} else {
    echo "  ✗ ERROR: Se requiere PHP 8.4 o superior.\n";
    $errors++;
}

// 2. Extensiones requeridas
$requiredExtensions = [
    'pdo' => 'PDO Abstraction Layer',
    'pdo_sqlsrv' => 'Microsoft Drivers for PHP for SQL Server (PDO)',
    'sqlsrv' => 'Microsoft SQL Server Driver',
    'openssl' => 'Criptografía y TLS',
    'mbstring' => 'Manejo seguro de UTF-8 multibyte',
    'curl' => 'Cliente HTTP / APIs externas',
    'fileinfo' => 'Detección MIME segura de comprobantes',
];

echo "\n[Extensiones]\n";
foreach ($requiredExtensions as $ext => $desc) {
    if (extension_loaded($ext)) {
        echo "  ✓ {$ext} ({$desc})\n";
    } else {
        echo "  ✗ ERROR: Extensión requerida '{$ext}' NO está cargada ({$desc}).\n";
        $errors++;
    }
}

// 3. Conexión de prueba a SQL Server
echo "\n[SQL Server]\n";
if (extension_loaded('pdo_sqlsrv')) {
    $database = getenv('TSSA_DB_DATABASE') ?: 'TransportesSegurosSA_Test';
    $server = getenv('TSSA_DB_SERVER') ?: 'localhost';
    try {
        $dsn = "sqlsrv:Server={$server};Database={$database};Encrypt=yes;TrustServerCertificate=yes;LoginTimeout=3";
        $pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8,
        ]);
        $stmt = $pdo->query('SELECT @@VERSION AS ver, DB_NAME() AS db, SYSUTCDATETIME() AS fecha_utc;');
        $info = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "  ✓ Conexión establecida con éxito a '{$info['db']}' en {$server}\n";
        echo "  ✓ Versión de SQL Server: " . strtok((string)$info['ver'], "\n") . "\n";
        echo "  ✓ Hora UTC del servidor de BD: {$info['fecha_utc']}\n";
    } catch (Throwable $e) {
        echo "  ✗ ERROR: No se pudo conectar a SQL Server: " . $e->getMessage() . "\n";
        $errors++;
    }
} else {
    echo "  - Omitido: el controlador pdo_sqlsrv no está disponible.\n";
}

echo "\n====================================================================\n";
if ($errors === 0) {
    echo "RESULTADO: ENTORNO CONFORME. Todos los requisitos están satisfechos.\n";
    exit(0);
} else {
    echo "RESULTADO: SE ENCONTRARON {$errors} PROBLEMA(S) EN EL ENTORNO.\n";
    exit(1);
}
