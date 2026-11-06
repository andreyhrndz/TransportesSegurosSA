<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/transport_request_tvp.php';

function assert_tvp(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$tvp = build_tipo_mercancias_tvp([
    ['tipo_mercancia_id' => '4', 'descripcion' => 'Cajas selladas', 'peso_kg' => '12.50', 'volumen_m3' => '0.125'],
    ['tipo_mercancia_id' => 9, 'descripcion' => 'Repuestos', 'peso_kg' => '1', 'volumen_m3' => ''],
]);
assert_tvp(array_key_exists('tipo_mercancias', $tvp) && $tvp[0] === 'dbo', 'El TVP debe declarar tipo y esquema.');
assert_tvp($tvp['tipo_mercancias'][0] === [4, 'Cajas selladas', '12.50', '0.125'], 'El orden de columnas debe coincidir con dbo.tipo_mercancias.');
assert_tvp($tvp['tipo_mercancias'][1][3] === null, 'volumen_m3 permite NULL según el esquema.');

foreach ([
    [],
    [['tipo_mercancia_id' => 0, 'descripcion' => 'A', 'peso_kg' => '1', 'volumen_m3' => '1']],
    [['tipo_mercancia_id' => 1, 'descripcion' => '', 'peso_kg' => '1', 'volumen_m3' => '1']],
    [['tipo_mercancia_id' => 1, 'descripcion' => 'A', 'peso_kg' => '0', 'volumen_m3' => '1']],
    [['tipo_mercancia_id' => 1, 'descripcion' => 'A', 'peso_kg' => '1.999', 'volumen_m3' => '1']],
    [['tipo_mercancia_id' => 1, 'descripcion' => 'A', 'peso_kg' => '1', 'volumen_m3' => '0']],
] as $invalid) {
    try {
        build_tipo_mercancias_tvp($invalid);
        throw new RuntimeException('Un TVP inválido debe ser rechazado antes de tocar SQL Server.');
    } catch (InvalidArgumentException) {
        // Resultado esperado.
    }
}

echo "OK: TVP de mercancías validado sin conexión ni escrituras SQL.\n";
