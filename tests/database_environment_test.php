<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

function assert_database_environment(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

putenv('TSSA_DB_DATABASE');
assert_database_environment(configured_database_name() === 'TransportesSegurosSA', 'La base local predeterminada debe conservarse.');

putenv('TSSA_DB_DATABASE=TransportesSegurosSA_Test');
assert_database_environment(configured_database_name() === 'TransportesSegurosSA_Test', 'La base de pruebas debe poder seleccionarse explícitamente.');

putenv('TSSA_DB_DATABASE=master');
try {
    configured_database_name();
    throw new RuntimeException('Una base no autorizada debe rechazarse.');
} catch (LogicException) {
    // Resultado esperado.
}

putenv('TSSA_DB_DATABASE');
echo "OK: selección de base limitada y verificada sin conexión SQL.\n";
