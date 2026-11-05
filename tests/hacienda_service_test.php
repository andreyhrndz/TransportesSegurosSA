<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/hacienda_service.php';

echo "=== INICIO DE PRUEBAS DE SERVICIO HACIENDA ===\n";

function hacienda_assert(bool $cond, string $msg): void {
    if (!$cond) {
        throw new RuntimeException("FALLO HACIENDA TEST: $msg");
    }
}

// 1. Validación de cédula corta / inválida
$resp = HaciendaService::consultar('12345');
hacienda_assert($resp['status'] === 'invalido', 'Cédula corta debe retornar status invalido');
hacienda_assert(isset($resp['mensaje']), 'Debe contener mensaje explicativo');

// 2. Mock fixtures
HaciendaService::setMockResponses([
    '102340567' => [
        'status' => 'encontrado',
        'nombre' => 'MORALES RAMIREZ ANDRES',
        'tipo' => '01',
    ],
    '3101999888' => [
        'status' => 'encontrado',
        'nombre' => 'LOGISTICA CENTROAMERICANA SOCIEDAD ANONIMA',
        'tipo' => '02',
    ],
    '100000000' => [
        'status' => 'no_encontrado',
        'mensaje' => 'No registrada en Hacienda',
    ],
    '200000000' => [
        'status' => 'limite_excedido',
        'mensaje' => 'Tasa de peticiones excedida',
    ],
    '300000000' => [
        'status' => 'no_disponible',
        'mensaje' => 'Error de timeout simulado',
    ],
]);

// 3. Caso física encontrada
$resp = HaciendaService::consultar('1-0234-0567');
hacienda_assert($resp['status'] === 'encontrado', 'Cédula física formateada debe resolverse');
hacienda_assert($resp['nombre'] === 'MORALES RAMIREZ ANDRES', 'Nombre debe coincidir con el registro oficial');
hacienda_assert($resp['tipo'] === '01', 'Tipo debe ser 01');

// 4. Caso jurídica encontrada
$resp = HaciendaService::consultar('3-101-999888');
hacienda_assert($resp['status'] === 'encontrado', 'Cédula jurídica debe resolverse');
hacienda_assert($resp['nombre'] === 'LOGISTICA CENTROAMERICANA SOCIEDAD ANONIMA', 'Razón social debe coincidir');
hacienda_assert($resp['tipo'] === '02', 'Tipo debe ser 02');

// 5. Caso no encontrado
$resp = HaciendaService::consultar('1-0000-0000');
hacienda_assert($resp['status'] === 'no_encontrado', 'Cédula inexistente debe retornar no_encontrado');

// 6. Caso 429 límite excedido
$resp = HaciendaService::consultar('2-0000-0000');
hacienda_assert($resp['status'] === 'limite_excedido', 'Debe retornar limite_excedido');

// 7. Caso indisponible / timeout
$resp = HaciendaService::consultar('3-0000-0000');
hacienda_assert($resp['status'] === 'no_disponible', 'Debe retornar no_disponible');

// 8. Deducción de tipo de identificación
hacienda_assert(HaciendaService::deducirTipoIdentificacion('102340567') === '01', '9 dígitos -> física');
hacienda_assert(HaciendaService::deducirTipoIdentificacion('3101999888') === '02', '10 dígitos -> jurídica');
hacienda_assert(HaciendaService::deducirTipoIdentificacion('155823456789') === '03', '12 dígitos -> DIMEX');

HaciendaService::setMockResponses(null);

echo "OK: Pruebas unitarias de HaciendaService superadas con éxito.\n";
