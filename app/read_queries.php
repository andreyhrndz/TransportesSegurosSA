<?php
declare(strict_types=1);

/** Utilidades para contratos de lectura con SQL Server; los SQL son estáticos en cada controlador. */

function read_page_number(mixed $value): int
{
    $page = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $page === false ? 1 : $page;
}

function read_optional_text(mixed $value, int $maxLength): ?string
{
    $text = trim((string)$value);
    if ($text === '') {
        return null;
    }

    return valid_text($text, $maxLength) ? $text : null;
}

function read_optional_date(mixed $value): ?string
{
    $date = (string)$value;
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
    return $parsed !== false && $parsed->format('Y-m-d') === $date ? $date : null;
}

/**
 * Ejecuta un contrato paginado que finaliza en SELECT @total_registros.
 * ASVS 1.2.4: cada controlador provee SQL fijo y los valores se enlazan por PDO.
 *
 * @return array{rows: array<int, array<string, mixed>>, total: int}
 */
function read_procedure_list(string $sql, array $params): array
{
    $statement = db()->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll();
    $total = 0;

    while ($statement->nextRowset()) {
        if ($statement->columnCount() === 0) {
            continue;
        }
        $value = $statement->fetch();
        if (is_array($value) && array_key_exists('total_registros', $value)) {
            $total = (int)$value['total_registros'];
        }
    }
    $statement->closeCursor();

    return ['rows' => $rows, 'total' => $total];
}

/** @return array<int, array<int, array<string, mixed>>> */
function read_procedure_sets(string $sql, array $params): array
{
    $statement = db()->prepare($sql);
    $statement->execute($params);
    $sets = [];
    do {
        if ($statement->columnCount() > 0) {
            $sets[] = $statement->fetchAll();
        }
    } while ($statement->nextRowset());
    $statement->closeCursor();

    return $sets;
}

function read_safe_reference_url(mixed $value): ?string
{
    $url = trim((string)$value);
    if ($url === '') {
        return null;
    }
    if (str_starts_with($url, 'comprobantes/')) {
        return $url;
    }

    $parts = parse_url($url);
    return is_array($parts) && in_array($parts['scheme'] ?? '', ['https', 'http'], true) ? $url : null;
}

function read_error_response(string $message = 'No se pudo consultar la información. Inténtalo más tarde.'): never
{
    // ASVS 16.5.1/16.5.3: no exponer detalles SQL y no intercambiar datos parciales.
    error_log('TSSA: failed operational read');
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    exit(ui_notice($message, 'danger'));
}

function read_is_missing_record(PDOException $exception): bool
{
    return (int)($exception->errorInfo[1] ?? 0) === 50101;
}
