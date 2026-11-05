<?php
declare(strict_types=1);

/**
 * Transportes Seguros S.A. — Diagnóstico del Entorno de Ejecución (PHP y SQL Server)
 * Script de solo lectura para certificar que el entorno cumple todos los requisitos
 * del sistema sin generar escrituras, mutaciones ni revelar datos sensibles.
 */

$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . '/app/bootstrap.php';

echo "====================================================================\n";
echo "Transportes Seguros S.A. — Diagnóstico del Entorno de Ejecución\n";
echo "====================================================================\n\n";

$errors = 0;
$warnings = 0;

// 1. Versión y arquitectura de PHP
$phpVersion = PHP_VERSION;
$is64Bit = PHP_INT_SIZE === 8;
$isNts = !defined('PHP_ZTS') || !PHP_ZTS;

echo "[1/4] Runtime de PHP\n";
echo "  Versión detectada: {$phpVersion} (" . ($is64Bit ? 'x64' : 'x86') . ', ' . ($isNts ? 'NTS' : 'ZTS') . ")\n";

if (version_compare($phpVersion, '8.4.0', '>=')) {
    echo "  [OK] Conforme a la especificación oficial (PHP 8.4+ requerido).\n";
} else {
    echo "  [ERROR] Se requiere PHP 8.4 o superior.\n";
    $errors++;
}

$loadedIni = php_ini_loaded_file();
echo "  php.ini: " . ($loadedIni ?: 'Ninguno (configuración predeterminada)') . "\n";

$defaultCharset = strtoupper((string)ini_get('default_charset'));
if ($defaultCharset === 'UTF-8') {
    echo "  [OK] default_charset = UTF-8 conforme.\n";
} else {
    echo "  [ADVERTENCIA] default_charset es '{$defaultCharset}', se recomienda UTF-8.\n";
    $warnings++;
}

// 2. Extensiones requeridas
echo "\n[2/4] Extensiones Obligatorias de PHP\n";
$requiredExtensions = [
    'pdo' => 'Capa de abstracción PDO',
    'pdo_sqlsrv' => 'Microsoft Drivers for PHP for SQL Server (PDO)',
    'sqlsrv' => 'Microsoft SQL Server Driver nativo',
    'openssl' => 'Criptografía y TLS',
    'mbstring' => 'Codificación segura UTF-8 multibyte',
    'curl' => 'Cliente HTTP (Hacienda y Brevo)',
    'fileinfo' => 'Detección MIME segura de archivos y comprobantes',
];

foreach ($requiredExtensions as $ext => $desc) {
    if (extension_loaded($ext)) {
        echo "  [OK] {$ext} ({$desc})\n";
    } else {
        echo "  [ERROR] Falta extensión obligatoria: '{$ext}' ({$desc})\n";
        $errors++;
    }
}

// 3. Conexión de solo lectura a Microsoft SQL Server
echo "\n[3/4] Conectividad y Autenticación con SQL Server\n";
$targetDb = configured_database_name();
$server = getenv('TSSA_DB_SERVER') ?: 'localhost';

try {
    $pdo = db();
    $serverInfo = $pdo->query('SELECT @@SERVERNAME AS srv, DB_NAME() AS db, @@VERSION AS ver, SYSUTCDATETIME() AS fecha_utc;')->fetch(PDO::FETCH_ASSOC);

    $effectiveDb = (string)$serverInfo['db'];
    echo "  [OK] Conexión establecida con éxito al servidor '{$serverInfo['srv']}'.\n";
    echo "  [OK] Base de datos activa reportada por motor: '{$effectiveDb}'.\n";

    if ($effectiveDb !== $targetDb) {
        echo "  [ERROR] Discrepancia: se solicitó '{$targetDb}' pero el motor reportó '{$effectiveDb}'.\n";
        $errors++;
    }

    $sqlVerLine = strtok((string)$serverInfo['ver'], "\n");
    echo "  Versión del motor: {$sqlVerLine}\n";
    echo "  Hora UTC del motor: {$serverInfo['fecha_utc']}\n";
} catch (PDOException $pe) {
    echo "  [ERROR] Falló la conexión con Microsoft SQL Server:\n";
    $msg = $pe->getMessage();
    if (str_contains($msg, 'denegó expresamente') || str_contains($msg, 'Could not open a connection') || str_contains($msg, 'Named Pipes Provider') || str_contains($msg, 'TCP Provider')) {
        echo "    Causa probable: el servicio SQL Server no está iniciado o el puerto/instancia es incorrecto.\n";
    } elseif (str_contains($msg, 'Login failed') || str_contains($msg, 'inició sesión')) {
        echo "    Causa probable: falla de autenticación. Verifique credenciales o permisos de Windows Integrated Auth.\n";
    } elseif (str_contains($msg, 'Cannot open database') || str_contains($msg, 'No se puede abrir la base de datos')) {
        echo "    Causa probable: la base de datos '{$targetDb}' no existe en la instancia.\n";
        echo "    Sugerencia: ejecute .\\tools\\scripts\\init-test-db.ps1 para inicializar la base de pruebas.\n";
    } elseif (str_contains($msg, 'SSL') || str_contains($msg, 'certificate') || str_contains($msg, 'TrustServerCertificate')) {
        echo "    Causa probable: validación TLS/certificado del servidor SQL.\n";
    } else {
        echo "    Detalle del error: {$msg}\n";
    }
    $errors++;
    $pdo = null;
} catch (Throwable $e) {
    echo "  [ERROR] Error inesperado de conexión: " . $e->getMessage() . "\n";
    $errors++;
    $pdo = null;
}

// 4. Verificación de Contratos y Esquemas de Base de Datos
echo "\n[4/4] Verificación de Contratos SQL y Esquemas\n";
if ($pdo instanceof PDO) {
    $criticalTables = [
        'usuarios', 'permisos', 'usuarios_permisos',
        'clientes', 'clientes_fisicos', 'clientes_juridicos',
        'solicitudes', 'mercancias', 'rutas', 'ubicaciones',
        'vehiculos', 'conductores', 'viajes', 'viaje_solicitudes',
        'paradas_viaje', 'entregas', 'incidencias', 'documentos',
        'notificaciones', 'historial_estados_solicitud', 'auditorias',
        'tipos_mercancia', 'tipos_incidencia', 'tipos_documento',
        'cat_licencias', 'conductores_licencias', 'invitaciones_usuario', 'invitaciones_permisos'
    ];

    $existingTables = [];
    $stmtTables = $pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE'");
    while ($row = $stmtTables->fetch(PDO::FETCH_ASSOC)) {
        $existingTables[] = strtolower((string)$row['TABLE_NAME']);
    }

    $missingTables = [];
    foreach ($criticalTables as $t) {
        if (!in_array(strtolower($t), $existingTables, true)) {
            $missingTables[] = $t;
        }
    }

    if (empty($missingTables)) {
        echo "  [OK] Todas las tablas base y normativas (" . count($criticalTables) . ") están presentes.\n";
    } else {
        echo "  [ERROR] Faltan tablas requeridas en '{$targetDb}': " . implode(', ', $missingTables) . "\n";
        $errors++;
    }

    // Verificar catálogo de licencias (14 categorías normativas)
    if (in_array('cat_licencias', $existingTables, true)) {
        $catCount = (int)$pdo->query('SELECT COUNT(*) FROM dbo.cat_licencias WHERE activo = 1')->fetchColumn();
        if ($catCount >= 14) {
            echo "  [OK] Catálogo de licencias oficial conforme (Ley 9078/9460): {$catCount} categorías activas.\n";
        } else {
            echo "  [ADVERTENCIA] Catálogo de licencias incompleto: se encontraron {$catCount} de 14 categorías esperadas.\n";
            $warnings++;
        }
    }

    // Verificar función autoritativa y SPs clave
    $criticalObjects = [
        'fn_conductor_habilitado_vehiculo',
        'sp_crear_viaje',
        'sp_iniciar_viaje',
        'sp_asignar_licencia_conductor',
        'sp_listar_catalogo_licencias',
        'sp_crear_invitacion_usuario',
        'sp_completar_invitacion_usuario',
        'sp_cancelar_solicitud'
    ];

    $existingObjects = [];
    $stmtObj = $pdo->query("SELECT name FROM sys.objects WHERE name IN ('" . implode("','", $criticalObjects) . "')");
    while ($row = $stmtObj->fetch(PDO::FETCH_ASSOC)) {
        $existingObjects[] = strtolower((string)$row['name']);
    }

    $missingObjects = [];
    foreach ($criticalObjects as $obj) {
        if (!in_array(strtolower($obj), $existingObjects, true)) {
            $missingObjects[] = $obj;
        }
    }

    if (empty($missingObjects)) {
        echo "  [OK] Procedimientos y funciones autoritativas de seguridad verificados.\n";
    } else {
        echo "  [ERROR] Faltan contratos/procedimientos requeridos: " . implode(', ', $missingObjects) . "\n";
        $errors++;
    }
} else {
    echo "  - Omitida verificación de contratos por falla previa en la conexión SQL.\n";
}

echo "\n====================================================================\n";
if ($errors === 0 && $warnings === 0) {
    echo "RESULTADO: ENTORNO CONFORME. Todos los requisitos y contratos están verificados.\n";
    exit(0);
} elseif ($errors === 0) {
    echo "RESULTADO: ENTORNO APTO CON {$warnings} OBSERVACION(ES).\n";
    exit(0);
} else {
    echo "RESULTADO: SE ENCONTRARON {$errors} ERROR(ES) Y {$warnings} OBSERVACION(ES).\n";
    exit(1);
}
