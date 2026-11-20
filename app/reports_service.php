<?php
declare(strict_types=1);

/**
 * Servicio de reportes operacionales (base transaccional) y desempeño histórico
 * de solo lectura conectado a TransportesSegurosSA_DW, más exportación CSV segura.
 */

const REPORT_EXPORT_MAX_ROWS = 2000;

/** @return array<string, array{label: string, subtitle: string, icon: string, source_label: string}> */
function report_available_sections(): array
{
    $sections = [];
    if (!has_permission('REPORTES_VER')) {
        return $sections;
    }
    if (has_permission('SOLICITUDES_VER')) {
        $sections['solicitudes'] = [
            'label' => 'Solicitudes',
            'subtitle' => 'Volumen de solicitudes en la base operacional, distribución por estado y prioridad, y detalle actual.',
            'icon' => 'requests',
            'source_label' => 'Base operacional (datos en tiempo real)',
        ];
    }
    if (has_permission('ENTREGAS_VER')) {
        $sections['entregas'] = [
            'label' => 'Entregas operativas',
            'subtitle' => 'Resultados registrados en la base operacional, tasa de éxito sobre entregas concluidas y entregas a tiempo.',
            'icon' => 'deliveries',
            'source_label' => 'Base operacional (datos en tiempo real)',
        ];
    }
    $sections['historico_dw'] = [
        'label' => 'Análisis histórico (DW)',
        'subtitle' => 'Indicadores consolidados de entregas históricas desde el Data Warehouse (solo lectura).',
        'icon' => 'database',
        'source_label' => 'Data Warehouse de solo lectura (datos históricos consolidados por ETL)',
    ];
    return $sections;
}

/**
 * @param array<string, mixed> $input
 * @return array{
 *   buscar: ?string,
 *   estado: ?string,
 *   prioridad: ?string,
 *   resultado: ?string,
 *   ruta_key: ?int,
 *   desde: ?string,
 *   hasta: ?string,
 *   error: ?string
 * }
 */
function report_parse_filters(string $section, array $input): array
{
    $buscar = read_optional_text($input['buscar'] ?? '', 120);
    $desde = read_optional_date($input['desde'] ?? null);
    $hasta = read_optional_date($input['hasta'] ?? null);
    $error = null;
    if ($desde !== null && $hasta !== null && $desde > $hasta) {
        $error = 'La fecha «Desde» no puede ser posterior a la fecha «Hasta».';
    }

    $estadoRaw = strtoupper(trim((string)($input['estado'] ?? '')));
    $estado = null;
    if ($section === 'solicitudes') {
        $valid = ['REGISTRADA', 'PENDIENTE_ASIGNACION', 'ASIGNADA', 'EN_TRANSITO', 'ENTREGADA', 'ENTREGA_FALLIDA', 'CANCELADA'];
        $estado = in_array($estadoRaw, $valid, true) ? $estadoRaw : null;
    } elseif ($section === 'entregas') {
        $estado = in_array($estadoRaw, ['ENTREGADA', 'FALLIDA'], true) ? $estadoRaw : null;
    }

    $prioridadRaw = strtoupper(trim((string)($input['prioridad'] ?? '')));
    $prioridad = ($section === 'solicitudes' && in_array($prioridadRaw, ['NORMAL', 'ALTA', 'URGENTE'], true))
        ? $prioridadRaw
        : null;

    $resultadoRaw = strtoupper(trim((string)($input['resultado'] ?? '')));
    $resultado = ($section === 'historico_dw' && in_array($resultadoRaw, ['ENTREGADA', 'FALLIDA'], true))
        ? $resultadoRaw
        : null;

    $rutaRaw = filter_var($input['ruta_key'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $rutaKey = ($section === 'historico_dw' && $rutaRaw !== false) ? (int)$rutaRaw : null;

    return [
        'buscar' => $section === 'solicitudes' ? $buscar : null,
        'estado' => $estado,
        'prioridad' => $prioridad,
        'resultado' => $resultado,
        'ruta_key' => $rutaKey,
        'desde' => $desde,
        'hasta' => $hasta,
        'error' => $error,
    ];
}

function report_period_label(?string $desde, ?string $hasta): string
{
    if ($desde !== null && $hasta !== null) {
        return format_cr_date($desde) . ' al ' . format_cr_date($hasta);
    }
    if ($desde !== null) {
        return 'Desde ' . format_cr_date($desde);
    }
    if ($hasta !== null) {
        return 'Hasta ' . format_cr_date($hasta);
    }
    return 'Todo el período disponible';
}

/**
 * Carga KPIs, distribuciones y tabla para el reporte 1: Solicitudes por período.
 * @param array<string, mixed> $filters
 * @return array<string, mixed>
 */
function report_solicitudes_data(array $filters, int $page = 1, int $perPage = 20, bool $forExport = false): array
{
    $sets = read_procedure_sets(
        'EXEC dbo.sp_reporte_solicitudes_resumen @buscar=?,@estado=?,@prioridad=?,@desde=?,@hasta=?;',
        [$filters['buscar'], $filters['estado'], $filters['prioridad'], $filters['desde'], $filters['hasta']]
    );

    $kpiRow = $sets[0][0] ?? [];
    $byStatus = $sets[1] ?? [];
    $byPriority = $sets[2] ?? [];

    $rows = [];
    $total = 0;
    $truncated = false;

    if ($forExport) {
        $batchPage = 1;
        $batchSize = 100;
        do {
            $res = read_procedure_list(
                'DECLARE @total_registros INT; '
                . 'EXEC dbo.sp_listar_solicitudes @buscar=?,@estado=?,@cliente_id=NULL,@desde=?,@hasta=?,@pagina=?,@por_pagina=?,@total_registros=@total_registros OUTPUT,@prioridad=?; '
                . 'SELECT @total_registros AS total_registros;',
                [$filters['buscar'], $filters['estado'], $filters['desde'], $filters['hasta'], $batchPage, $batchSize, $filters['prioridad']]
            );
            $total = $res['total'];
            foreach ($res['rows'] as $r) {
                if (count($rows) >= REPORT_EXPORT_MAX_ROWS) {
                    $truncated = true;
                    break 2;
                }
                $rows[] = $r;
            }
            $batchPage++;
        } while (count($res['rows']) === $batchSize && count($rows) < $total);
        if ($total > REPORT_EXPORT_MAX_ROWS) {
            $truncated = true;
        }
    } else {
        $res = read_procedure_list(
            'DECLARE @total_registros INT; '
            . 'EXEC dbo.sp_listar_solicitudes @buscar=?,@estado=?,@cliente_id=NULL,@desde=?,@hasta=?,@pagina=?,@por_pagina=?,@total_registros=@total_registros OUTPUT,@prioridad=?; '
            . 'SELECT @total_registros AS total_registros;',
            [$filters['buscar'], $filters['estado'], $filters['desde'], $filters['hasta'], $page, $perPage, $filters['prioridad']]
        );
        $rows = $res['rows'];
        $total = $res['total'];
    }

    return [
        'kpis' => [
            'total_solicitudes' => (int)($kpiRow['total_solicitudes'] ?? 0),
            'pendientes_asignacion' => (int)($kpiRow['pendientes_asignacion'] ?? 0),
            'en_operacion' => (int)($kpiRow['en_operacion'] ?? 0),
            'entregadas' => (int)($kpiRow['entregadas'] ?? 0),
            'fallidas' => (int)($kpiRow['fallidas'] ?? 0),
            'canceladas' => (int)($kpiRow['canceladas'] ?? 0),
            'peso_total_kg' => (float)($kpiRow['peso_total_kg'] ?? 0.0),
        ],
        'by_status' => $byStatus,
        'by_priority' => $byPriority,
        'rows' => $rows,
        'total' => $total,
        'truncated' => $truncated,
        'source_db' => configured_database_name(),
    ];
}

/**
 * Carga KPIs, serie diaria y tabla para el reporte 2: Entregas y resultados.
 * @param array<string, mixed> $filters
 * @return array<string, mixed>
 */
function report_entregas_data(array $filters, int $page = 1, int $perPage = 20, bool $forExport = false): array
{
    $sets = read_procedure_sets(
        'EXEC dbo.sp_reporte_entregas_resumen @estado=?,@desde=?,@hasta=?;',
        [$filters['estado'], $filters['desde'], $filters['hasta']]
    );

    $kpiRow = $sets[0][0] ?? [];
    $daily = $sets[1] ?? [];

    $rows = [];
    $total = 0;
    $truncated = false;

    if ($forExport) {
        $batchPage = 1;
        $batchSize = 100;
        do {
            $res = read_procedure_list(
                'DECLARE @total_registros INT; '
                . 'EXEC dbo.sp_listar_entregas @estado=?,@viaje_id=NULL,@desde=?,@hasta=?,@pagina=?,@por_pagina=?,@total_registros=@total_registros OUTPUT; '
                . 'SELECT @total_registros AS total_registros;',
                [$filters['estado'], $filters['desde'], $filters['hasta'], $batchPage, $batchSize]
            );
            $total = $res['total'];
            foreach ($res['rows'] as $r) {
                if (count($rows) >= REPORT_EXPORT_MAX_ROWS) {
                    $truncated = true;
                    break 2;
                }
                $rows[] = $r;
            }
            $batchPage++;
        } while (count($res['rows']) === $batchSize && count($rows) < $total);
        if ($total > REPORT_EXPORT_MAX_ROWS) {
            $truncated = true;
        }
    } else {
        $res = read_procedure_list(
            'DECLARE @total_registros INT; '
            . 'EXEC dbo.sp_listar_entregas @estado=?,@viaje_id=NULL,@desde=?,@hasta=?,@pagina=?,@por_pagina=?,@total_registros=@total_registros OUTPUT; '
            . 'SELECT @total_registros AS total_registros;',
            [$filters['estado'], $filters['desde'], $filters['hasta'], $page, $perPage]
        );
        $rows = $res['rows'];
        $total = $res['total'];
    }

    $entregadas = (int)($kpiRow['entregadas'] ?? 0);
    $fallidas = (int)($kpiRow['fallidas'] ?? 0);
    $totalResultados = (int)($kpiRow['total_resultados'] ?? ($entregadas + $fallidas));
    $tasaExito = ($totalResultados > 0 && $kpiRow['tasa_exito_pct'] !== null)
        ? (float)$kpiRow['tasa_exito_pct']
        : null;

    return [
        'kpis' => [
            'total_resultados' => $totalResultados,
            'entregadas' => $entregadas,
            'fallidas' => $fallidas,
            'tasa_exito_pct' => $tasaExito,
            'entregadas_a_tiempo' => (int)($kpiRow['entregadas_a_tiempo'] ?? 0),
        ],
        'daily' => $daily,
        'rows' => $rows,
        'total' => $total,
        'truncated' => $truncated,
        'source_db' => configured_database_name(),
    ];
}

/**
 * Obtiene la lista de rutas registradas en TransportesSegurosSA_DW para el selector del reporte 3.
 * @return array<int, array{ruta_key: int, nombre: string}>
 */
function report_dw_routes(): array
{
    try {
        $dw = db_dw();
        $stmt = $dw->query('SELECT ruta_key, nombre FROM dbo.dim_ruta ORDER BY nombre ASC');
        $rows = $stmt ? $stmt->fetchAll() : [];
        return array_map(
            static fn(array $r): array => [
                'ruta_key' => (int)$r['ruta_key'],
                'nombre' => (string)$r['nombre'],
            ],
            $rows
        );
    } catch (Throwable) {
        return [];
    }
}

/**
 * Reporte 3: Desempeño histórico conectado en modo solo lectura a TransportesSegurosSA_DW.
 * Nunca ejecuta ETL ni escribe en el Data Warehouse; degrada limpiamente si el DW no responde.
 * @param array<string, mixed> $filters
 * @return array<string, mixed>
 */
function report_dw_historic_data(array $filters, int $page = 1, int $perPage = 20, bool $forExport = false): array
{
    try {
        $dw = db_dw();
        $effectiveDb = (string)$dw->query('SELECT DB_NAME()')->fetchColumn();
        if ($effectiveDb !== 'TransportesSegurosSA_DW') {
            throw new RuntimeException('La conexión analítica no apunta al Data Warehouse esperado.');
        }

        // Metadatos globales de la última carga conocida en el DW
        $metaStmt = $dw->query('SELECT COUNT(*) AS total_hechos_dw, MAX(fecha_carga_utc) AS ultima_carga_utc FROM dbo.fact_entregas');
        $meta = $metaStmt ? ($metaStmt->fetch() ?: []) : [];
        $totalHechosDw = (int)($meta['total_hechos_dw'] ?? 0);
        $ultimaCargaUtc = isset($meta['ultima_carga_utc']) && $meta['ultima_carga_utc'] !== null
            ? (string)$meta['ultima_carga_utc']
            : null;

        $where = ['1 = 1'];
        $params = [];
        if (!empty($filters['resultado'])) {
            $where[] = 'f.resultado = ?';
            $params[] = $filters['resultado'];
        }
        if (!empty($filters['ruta_key'])) {
            $where[] = 'f.ruta_key = ?';
            $params[] = (int)$filters['ruta_key'];
        }
        if (!empty($filters['desde'])) {
            $where[] = 'df.fecha >= ?';
            $params[] = $filters['desde'];
        }
        if (!empty($filters['hasta'])) {
            $where[] = 'df.fecha <= ?';
            $params[] = $filters['hasta'];
        }
        $whereSql = implode(' AND ', $where);

        // 1. KPIs consolidados desde fact_entregas + dim_fecha
        $kpiSql = "
            SELECT
                COUNT(*) AS total_entregas,
                SUM(CASE WHEN f.resultado = 'ENTREGADA' THEN 1 ELSE 0 END) AS entregadas,
                SUM(CASE WHEN f.resultado = 'FALLIDA' THEN 1 ELSE 0 END) AS fallidas,
                SUM(CASE WHEN f.resultado = 'ENTREGADA' AND f.entrega_puntual = 1 THEN 1 ELSE 0 END) AS entregadas_puntuales,
                ISNULL(SUM(f.peso_kg), 0) AS peso_total_kg,
                ISNULL(SUM(f.volumen_m3), 0) AS volumen_total_m3,
                ISNULL(SUM(f.incidencias), 0) AS total_incidencias
            FROM dbo.fact_entregas AS f
            JOIN dbo.dim_fecha AS df ON df.fecha_key = f.fecha_key
            WHERE {$whereSql}
        ";
        $kpiStmt = $dw->prepare($kpiSql);
        $kpiStmt->execute($params);
        $kpiRow = $kpiStmt->fetch() ?: [];
        $kpiStmt->closeCursor();

        $total = (int)($kpiRow['total_entregas'] ?? 0);
        $entregadas = (int)($kpiRow['entregadas'] ?? 0);
        $fallidas = (int)($kpiRow['fallidas'] ?? 0);
        $puntuales = (int)($kpiRow['entregadas_puntuales'] ?? 0);
        $tasaExito = $total > 0 ? round(($entregadas * 100.0) / $total, 1) : null;
        $tasaPuntualidad = $entregadas > 0 ? round(($puntuales * 100.0) / $entregadas, 1) : null;

        // 2. Desglose por ruta (dim_ruta)
        $routeSql = "
            SELECT
                dr.nombre AS ruta_nombre,
                COUNT(*) AS total,
                SUM(CASE WHEN f.resultado = 'ENTREGADA' THEN 1 ELSE 0 END) AS entregadas,
                SUM(CASE WHEN f.resultado = 'FALLIDA' THEN 1 ELSE 0 END) AS fallidas,
                ISNULL(SUM(f.peso_kg), 0) AS peso_kg,
                ISNULL(SUM(f.incidencias), 0) AS incidencias
            FROM dbo.fact_entregas AS f
            JOIN dbo.dim_fecha AS df ON df.fecha_key = f.fecha_key
            JOIN dbo.dim_ruta AS dr ON dr.ruta_key = f.ruta_key
            WHERE {$whereSql}
            GROUP BY dr.nombre
            ORDER BY COUNT(*) DESC, dr.nombre ASC
        ";
        $routeStmt = $dw->prepare($routeSql);
        $routeStmt->execute($params);
        $byRoute = $routeStmt->fetchAll();
        $routeStmt->closeCursor();

        // 3. Serie histórica por fecha (dim_fecha)
        $trendSql = "
            SELECT
                df.fecha AS fecha,
                SUM(CASE WHEN f.resultado = 'ENTREGADA' THEN 1 ELSE 0 END) AS entregadas,
                SUM(CASE WHEN f.resultado = 'FALLIDA' THEN 1 ELSE 0 END) AS fallidas,
                ISNULL(SUM(f.peso_kg), 0) AS peso_kg
            FROM dbo.fact_entregas AS f
            JOIN dbo.dim_fecha AS df ON df.fecha_key = f.fecha_key
            WHERE {$whereSql}
            GROUP BY df.fecha
            ORDER BY df.fecha ASC
        ";
        $trendStmt = $dw->prepare($trendSql);
        $trendStmt->execute($params);
        $trend = $trendStmt->fetchAll();
        $trendStmt->closeCursor();

        // 4. Detalle de hechos (con las 5 dimensiones del esquema en estrella)
        $offset = $forExport ? 0 : max(0, ($page - 1) * $perPage);
        $limit = $forExport ? REPORT_EXPORT_MAX_ROWS : $perPage;
        $rowsSql = "
            SELECT
                f.entrega_id_origen,
                df.fecha,
                dc.identificacion AS cliente_identificacion,
                dr.nombre AS ruta_nombre,
                dv.placa AS vehiculo_placa,
                dco.nombre_completo AS conductor_nombre,
                f.viaje_id_origen,
                f.solicitud_id_origen,
                f.resultado,
                f.peso_kg,
                f.volumen_m3,
                f.incidencias,
                f.entrega_puntual,
                f.fecha_carga_utc
            FROM dbo.fact_entregas AS f
            JOIN dbo.dim_fecha AS df ON df.fecha_key = f.fecha_key
            JOIN dbo.dim_cliente AS dc ON dc.cliente_key = f.cliente_key
            JOIN dbo.dim_ruta AS dr ON dr.ruta_key = f.ruta_key
            JOIN dbo.dim_vehiculo AS dv ON dv.vehiculo_key = f.vehiculo_key
            JOIN dbo.dim_conductor AS dco ON dco.conductor_key = f.conductor_key
            WHERE {$whereSql}
            ORDER BY df.fecha DESC, f.entrega_id_origen DESC
            OFFSET " . (int)$offset . " ROWS FETCH NEXT " . (int)$limit . " ROWS ONLY
        ";
        $rowsStmt = $dw->prepare($rowsSql);
        $rowsStmt->execute($params);
        $rows = $rowsStmt->fetchAll();
        $rowsStmt->closeCursor();

        return [
            'available' => true,
            'error' => null,
            'dw_db_name' => $effectiveDb,
            'total_hechos_dw' => $totalHechosDw,
            'ultima_carga_utc' => $ultimaCargaUtc,
            'kpis' => [
                'total_entregas' => $total,
                'entregadas' => $entregadas,
                'fallidas' => $fallidas,
                'tasa_exito_pct' => $tasaExito,
                'entregadas_puntuales' => $puntuales,
                'tasa_puntualidad_pct' => $tasaPuntualidad,
                'peso_total_kg' => (float)($kpiRow['peso_total_kg'] ?? 0.0),
                'volumen_total_m3' => (float)($kpiRow['volumen_total_m3'] ?? 0.0),
                'total_incidencias' => (int)($kpiRow['total_incidencias'] ?? 0),
            ],
            'by_route' => $byRoute,
            'trend' => $trend,
            'rows' => $rows,
            'total' => $total,
            'truncated' => $forExport && ($total > REPORT_EXPORT_MAX_ROWS),
        ];
    } catch (Throwable $ex) {
        error_log('TSSA DW report degraded: ' . $ex->getMessage());
        return [
            'available' => false,
            'error' => 'El Data Warehouse histórico (TransportesSegurosSA_DW) no se encuentra accesible en este momento. Los reportes operacionales continúan disponibles con normalidad.',
            'dw_db_name' => 'TransportesSegurosSA_DW',
            'total_hechos_dw' => 0,
            'ultima_carga_utc' => null,
            'kpis' => [
                'total_entregas' => 0,
                'entregadas' => 0,
                'fallidas' => 0,
                'tasa_exito_pct' => null,
                'entregadas_puntuales' => 0,
                'tasa_puntualidad_pct' => null,
                'peso_total_kg' => 0.0,
                'volumen_total_m3' => 0.0,
                'total_incidencias' => 0,
            ],
            'by_route' => [],
            'trend' => [],
            'rows' => [],
            'total' => 0,
            'truncated' => false,
        ];
    }
}

/**
 * Sanitiza una celda CSV contra CSV Formula Injection (OWASP).
 * Neutraliza cualquier texto que inicie con =, +, -, @, tabulación o salto de línea.
 */
function report_csv_safe_cell(mixed $value): string
{
    $str = (string)($value ?? '');
    $str = str_replace(["\r\n", "\r"], "\n", $str);
    if ($str === '') {
        return '';
    }
    $first = $str[0];
    $trimmedFirst = ltrim($str, " \t\n\r\0\x0B");
    $lead = $trimmedFirst !== '' ? $trimmedFirst[0] : '';
    if (in_array($first, ['=', '+', '-', '@', "\t", "\n"], true)
        || in_array($lead, ['=', '+', '-', '@'], true)) {
        return "'" . $str;
    }
    return $str;
}

/**
 * Envía un archivo CSV con BOM UTF-8 compatible con Excel y protección contra inyección de fórmulas.
 * @param array<int, string> $headers
 * @param array<int, array<int, mixed>> $rows
 */
function report_send_csv(string $filename, array $headers, array $rows): never
{
    $safeFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'reporte.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, max-age=0');

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        http_response_code(500);
        exit;
    }
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_map('report_csv_safe_cell', $headers), ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($out, array_map('report_csv_safe_cell', $row), ',', '"', '');
    }
    fclose($out);
    exit;
}
