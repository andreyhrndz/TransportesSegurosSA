<?php
declare(strict_types=1);

/**
 * Construye el TVP dbo.tipo_mercancias para sp_crear_solicitud.
 *
 * El llamador debe enlazar el valor resultante con PDO::PARAM_LOB mediante
 * PDO_SQLSRV. Esta función no abre conexiones ni escribe datos.
 *
 * @param list<array{tipo_mercancia_id:mixed,descripcion:mixed,peso_kg:mixed,volumen_m3:mixed}> $mercancias
 * @return array{tipo_mercancias:list<array{0:int,1:string,2:string,3:?string}>,0:string}
 */
function build_tipo_mercancias_tvp(array $mercancias): array
{
    if ($mercancias === []) {
        throw new InvalidArgumentException('Debe incluir al menos una mercancía.');
    }

    $rows = [];
    foreach ($mercancias as $index => $mercancia) {
        $tipoId = filter_var($mercancia['tipo_mercancia_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $descripcion = trim((string)($mercancia['descripcion'] ?? ''));
        $peso = normalize_positive_decimal($mercancia['peso_kg'] ?? null, 2, 10);
        $volumenValue = trim((string)($mercancia['volumen_m3'] ?? ''));
        $volumen = $volumenValue === '' ? null : normalize_positive_decimal($volumenValue, 3, 9);

        if ($tipoId === false || !is_transport_text($descripcion, 250) || $peso === null || ($volumenValue !== '' && $volumen === null)) {
            throw new InvalidArgumentException('Revisa los datos de la mercancía ' . ($index + 1) . '.');
        }

        $rows[] = [(int)$tipoId, $descripcion, $peso, $volumen];
    }

    // Forma documentada por PDO_SQLSRV: tipo SQL y esquema, seguido de filas posicionales.
    return ['tipo_mercancias' => $rows, 'dbo'];
}

function is_transport_text(string $value, int $maxLength): bool
{
    if ($value === '') {
        return false;
    }

    $length = preg_match_all('/./us', $value);
    return $length !== false && $length <= $maxLength;
}

function normalize_positive_decimal(mixed $value, int $scale, int $integerDigits): ?string
{
    $text = trim((string)$value);
    $pattern = '/^[0-9]{1,' . $integerDigits . '}(?:\\.[0-9]{1,' . $scale . '})?$/D';
    if (!preg_match($pattern, $text) || (float)$text <= 0.0) {
        return null;
    }

    return $text;
}
