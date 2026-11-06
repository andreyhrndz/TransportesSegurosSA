<?php
declare(strict_types=1);

require_once __DIR__ . '/read_queries.php';

/**
 * Metadatos, validadores y buscadores autorizados (mediante procedimientos de lectura)
 * para Datos Maestros y Flota. No ejecuta consultas directas a tablas.
 */

/**
 * @return array{
 *   section: string,
 *   label: string,
 *   read_permission: string,
 *   write_permission: string,
 *   id_column: string
 * }
 */
function master_entity_meta(string $entity): array
{
    $catalog = [
        'vehiculo' => [
            'section' => 'vehiculos',
            'label' => 'vehículo',
            'read_permission' => 'FLOTAS_VER',
            'write_permission' => 'FLOTAS_GESTIONAR',
            'id_column' => 'vehiculo_id',
        ],
        'conductor' => [
            'section' => 'conductores',
            'label' => 'conductor',
            'read_permission' => 'FLOTAS_VER',
            'write_permission' => 'FLOTAS_GESTIONAR',
            'id_column' => 'conductor_id',
        ],
        'ruta' => [
            'section' => 'rutas',
            'label' => 'ruta',
            'read_permission' => 'RUTAS_VER',
            'write_permission' => 'RUTAS_GESTIONAR',
            'id_column' => 'ruta_id',
        ],
        'ubicacion' => [
            'section' => 'ubicaciones',
            'label' => 'ubicación',
            'read_permission' => 'UBICACIONES_VER',
            'write_permission' => 'UBICACIONES_GESTIONAR',
            'id_column' => 'ubicacion_id',
        ],
        'tipo_mercancia' => [
            'section' => 'catalogos',
            'label' => 'tipo de mercancía',
            'read_permission' => 'CATALOGOS_VER',
            'write_permission' => 'CATALOGOS_GESTIONAR',
            'id_column' => 'tipo_mercancia_id',
        ],
        'tipo_incidencia' => [
            'section' => 'catalogos',
            'label' => 'tipo de incidencia',
            'read_permission' => 'CATALOGOS_VER',
            'write_permission' => 'CATALOGOS_GESTIONAR',
            'id_column' => 'tipo_incidencia_id',
        ],
        'tipo_documento' => [
            'section' => 'catalogos',
            'label' => 'tipo de documento',
            'read_permission' => 'CATALOGOS_VER',
            'write_permission' => 'CATALOGOS_GESTIONAR',
            'id_column' => 'tipo_documento_id',
        ],
    ];

    $key = strtolower(trim($entity));
    if (!isset($catalog[$key])) {
        throw new InvalidArgumentException('Tipo de registro no reconocido.');
    }

    return $catalog[$key];
}

/**
 * Localiza un registro maestro usando únicamente los contratos de lectura autorizados.
 * @return array<string, mixed>|null
 */
function master_find_record(string $entity, int $id): ?array
{
    $meta = master_entity_meta($entity);
    $idColumn = $meta['id_column'];

    if ($entity === 'tipo_mercancia') {
        $rows = read_procedure_sets('EXEC dbo.sp_listar_tipos_mercancia @solo_activos=NULL;', [])[0] ?? [];
        foreach ($rows as $row) {
            if ((int)$row[$idColumn] === $id) {
                return $row;
            }
        }
        return null;
    }

    if ($entity === 'tipo_incidencia') {
        $rows = read_procedure_sets('EXEC dbo.sp_listar_tipos_incidencia @solo_activos=NULL;', [])[0] ?? [];
        foreach ($rows as $row) {
            if ((int)$row[$idColumn] === $id) {
                return $row;
            }
        }
        return null;
    }

    if ($entity === 'tipo_documento') {
        $rows = read_procedure_sets('EXEC dbo.sp_listar_tipos_documento @solo_activos=NULL;', [])[0] ?? [];
        foreach ($rows as $row) {
            if ((int)$row[$idColumn] === $id) {
                return $row;
            }
        }
        return null;
    }

    $sqlMap = [
        'vehiculo' => 'DECLARE @total_registros INT; EXEC dbo.sp_listar_vehiculos @estado=NULL,@solo_activos=NULL,@buscar=NULL,@pagina=?,@por_pagina=100,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;',
        'conductor' => 'DECLARE @total_registros INT; EXEC dbo.sp_listar_conductores @estado=NULL,@solo_activos=NULL,@buscar=NULL,@pagina=?,@por_pagina=100,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;',
        'ruta' => 'DECLARE @total_registros INT; EXEC dbo.sp_listar_rutas @origen_id=NULL,@destino_id=NULL,@solo_activas=NULL,@buscar=NULL,@pagina=?,@por_pagina=100,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;',
        'ubicacion' => 'DECLARE @total_registros INT; EXEC dbo.sp_listar_ubicaciones @buscar=NULL,@solo_activos=NULL,@pagina=?,@por_pagina=100,@total_registros=@total_registros OUTPUT; SELECT @total_registros AS total_registros;',
    ];

    $sql = $sqlMap[$entity];
    $page = 1;
    do {
        $result = read_procedure_list($sql, [$page]);
        foreach ($result['rows'] as $row) {
            if ((int)$row[$idColumn] === $id) {
                return $row;
            }
        }
        $totalPages = max(1, (int)ceil($result['total'] / 100));
        $page++;
    } while ($page <= $totalPages && $page <= 20);

    return null;
}

function master_decimal(mixed $value, float $min, ?float $max, bool $required, string $fieldLabel, bool $strictGreater = false): ?string
{
    $raw = str_replace(',', '.', trim((string)$value));
    if ($raw === '' && !$required) {
        return null;
    }
    if ($raw === '' || !preg_match('/^-?\d+(?:\.\d{1,6})?$/', $raw)) {
        throw new InvalidArgumentException("Ingresa un valor numérico válido para {$fieldLabel}.");
    }
    $num = (float)$raw;
    if (($strictGreater && $num <= $min) || (!$strictGreater && $num < $min)) {
        throw new InvalidArgumentException("El valor de {$fieldLabel} está fuera del rango permitido.");
    }
    if ($max !== null && $num > $max) {
        throw new InvalidArgumentException("El valor de {$fieldLabel} supera el máximo permitido.");
    }
    return $raw;
}

function master_int(mixed $value, int $min, int $max, bool $required, string $fieldLabel): ?int
{
    $raw = trim((string)$value);
    if ($raw === '' && !$required) {
        return null;
    }
    $num = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
    if ($num === false) {
        throw new InvalidArgumentException("Ingresa un número entero válido para {$fieldLabel}.");
    }
    return (int)$num;
}

function master_license_date(mixed $value): string
{
    $raw = trim((string)$value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new DateTimeZone('UTC'));
    $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
    if ($date === false || $date->format('Y-m-d') !== $raw) {
        throw new InvalidArgumentException('Indica una fecha de vencimiento de licencia válida.');
    }
    if ($date < $today) {
        throw new InvalidArgumentException('La licencia del conductor no puede estar vencida.');
    }
    return $raw;
}

/** @return array{0:?string,1:?string} */
function master_coordinates(mixed $latRaw, mixed $lonRaw): array
{
    $latText = trim((string)$latRaw);
    $lonText = trim((string)$lonRaw);
    if ($latText === '' && $lonText === '') {
        return [null, null];
    }
    if ($latText === '' || $lonText === '') {
        throw new InvalidArgumentException('Indica tanto la latitud como la longitud, o deja ambas vacías.');
    }
    $lat = master_decimal($latText, -90.0, 90.0, true, 'latitud');
    $lon = master_decimal($lonText, -180.0, 180.0, true, 'longitud');
    return [$lat, $lon];
}

/**
 * Retorna las categorías oficiales de licencias de Costa Rica (Ley 9078).
 * @return array<int, array<string, mixed>>
 */
function master_license_catalog(): array
{
    try {
        return read_procedure_sets('EXEC dbo.sp_listar_catalogo_licencias @solo_activos=1;', [])[0] ?? [];
    } catch (PDOException) {
        return [];
    }
}

/**
 * Retorna las licencias asignadas y verificadas de un conductor.
 * @return array<int, array<string, mixed>>
 */
function master_driver_licenses(int $conductorId): array
{
    try {
        return read_procedure_sets('EXEC dbo.sp_listar_licencias_conductor @conductor_id=?;', [$conductorId])[0] ?? [];
    } catch (PDOException) {
        return [];
    }
}
