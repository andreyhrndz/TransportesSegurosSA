<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/read_queries.php';

if (configured_database_name() !== 'TransportesSegurosSA_Test') {
    throw new RuntimeException('Esta prueba requiere TSSA_DB_DATABASE=TransportesSegurosSA_Test.');
}

function operational_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$connection = db();
require_test_database($connection);
$context = $connection->prepare("EXEC sys.sp_set_session_context @key=N'usuario_id', @value=1");
$context->execute();
$context->closeCursor();

$contracts = [
    ['solicitudes', 'DECLARE @total_registros INT; EXEC dbo.sp_listar_solicitudes @pagina=1,@por_pagina=10,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;'],
    ['viajes', 'DECLARE @total_registros INT; EXEC dbo.sp_listar_viajes @pagina=1,@por_pagina=10,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;'],
    ['entregas', 'DECLARE @total_registros INT; EXEC dbo.sp_listar_entregas @pagina=1,@por_pagina=10,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;'],
    ['incidencias', 'DECLARE @total_registros INT; EXEC dbo.sp_listar_incidencias @pagina=1,@por_pagina=10,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;'],
    ['vehiculos', 'DECLARE @total_registros INT; EXEC dbo.sp_listar_vehiculos @pagina=1,@por_pagina=10,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;'],
    ['conductores', 'DECLARE @total_registros INT; EXEC dbo.sp_listar_conductores @pagina=1,@por_pagina=10,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;'],
    ['rutas', 'DECLARE @total_registros INT; EXEC dbo.sp_listar_rutas @pagina=1,@por_pagina=10,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;'],
    ['ubicaciones', 'DECLARE @total_registros INT; EXEC dbo.sp_listar_ubicaciones @pagina=1,@por_pagina=10,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;'],
];

foreach ($contracts as [$name, $sql]) {
    $result = read_procedure_list($sql, []);
    operational_assert($result['total'] >= count($result['rows']), "El contrato {$name} devolvió un total inválido.");
}

$details = read_procedure_sets('EXEC dbo.sp_obtener_solicitud @solicitud_id=1;', []);
operational_assert(isset($details[0][0]['codigo_solicitud']), 'El detalle de solicitud no devolvió su cabecera.');
operational_assert(isset($details[1]), 'El detalle de solicitud no devolvió mercancías.');
$tripDetails = read_procedure_sets('EXEC dbo.sp_obtener_viaje @viaje_id=1;', []);
operational_assert(isset($tripDetails[0][0]['codigo_viaje']), 'El detalle de viaje no devolvió su cabecera.');

echo "OK: contratos operativos de lectura consultados mediante PDO_SQLSRV en TransportesSegurosSA_Test.\n";
