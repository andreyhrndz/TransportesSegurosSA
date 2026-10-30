<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (configured_database_name() !== 'TransportesSegurosSA_Test') {
    throw new RuntimeException('Esta prueba requiere TSSA_DB_DATABASE=TransportesSegurosSA_Test.');
}

$connection = db();
require_test_database($connection);

echo "OK: PDO_SQLSRV confirmó TransportesSegurosSA_Test antes de una prueba de escritura.\n";
