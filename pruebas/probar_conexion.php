
<?php

// ==========================================
// TRANSPORTES SEGUROS S.A.
// Prueba de conexión a SQL Server
// ==========================================

// Debe coincidir con el servidor usado en SSMS.
$servidor = 'localhost';

$baseDatos = 'TransportesSegurosSA';

try {

    // Conexión local con autenticación de Windows.
    $pdo = new PDO(
        "sqlsrv:Server=$servidor;Database=$baseDatos;Encrypt=yes;TrustServerCertificate=yes",
        null,
        null,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    echo "CONEXION EXITOSA\n\n";

    // Consultar información del servidor.
    $sql = "
        SELECT
            @@SERVERNAME AS servidor,
            DB_NAME() AS base_datos,
            SUSER_SNAME() AS usuario
    ";

    $datos = $pdo->query($sql)->fetch();

    echo "Servidor: " . $datos['servidor'] . "\n";
    echo "Base de datos: " . $datos['base_datos'] . "\n";
    echo "Usuario: " . $datos['usuario'] . "\n\n";

    // Verificar las tablas del proyecto.
    $sql = "
        SELECT COUNT(*)
        FROM sys.tables
        WHERE is_ms_shipped = 0
    ";

    $total = $pdo->query($sql)->fetchColumn();

    echo "Total de tablas: $total\n";

    if ((int)$total === 24) {
        echo "ESTRUCTURA VERIFICADA CORRECTAMENTE\n";
    }

} catch (PDOException $e) {

    echo "ERROR DE CONEXION\n";
    echo $e->getMessage() . "\n";

    exit(1);
}
