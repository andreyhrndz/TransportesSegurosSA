<?php
declare(strict_types=1);

/**
 * Motor determinístico de despacho inteligente (Transportes Seguros S.A.).
 * Evalúa compatibilidad de capacidad (peso/volumen), corredor geográfico (Haversine)
 * y ventana de tiempo entre solicitudes y viajes planificados sin crear tablas nuevas.
 */

function dispatch_haversine_km(?float $lat1, ?float $lon1, ?float $lat2, ?float $lon2): ?float
{
    if ($lat1 === null || $lon1 === null || $lat2 === null || $lon2 === null) {
        return null;
    }
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * (sin($dLon / 2) ** 2);
    return 2.0 * $r * asin(min(1.0, sqrt($a)));
}

function dispatch_to_float(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    return is_numeric($value) ? (float)$value : null;
}

/**
 * Evalúa qué tan bien encaja una solicitud en un viaje PLANIFICADO.
 *
 * @param array<string, mixed> $req
 * @param array<string, mixed> $trip
 * @param array<int, array<string, mixed>> $tripStops
 * @return array{
 *   cabe: bool,
 *   nivel: 'optimo'|'compatible'|'desvio'|'sin_capacidad',
 *   etiqueta: string,
 *   tag_class: string,
 *   puntaje: int,
 *   desvio_km: ?float,
 *   peso_libre_kg: float,
 *   motivo: string
 * }
 */
function dispatch_evaluate_fit(array $req, array $trip, array $tripStops): array
{
    $reqPeso = (float)($req['peso_solicitud_kg'] ?? $req['peso_total_kg'] ?? 0);
    $reqVol = (float)($req['volumen_solicitud_m3'] ?? $req['volumen_total_m3'] ?? 0);

    $capPeso = (float)($trip['capacidad_carga_kg'] ?? 0);
    $usadoPeso = (float)($trip['peso_usado_kg'] ?? 0);
    $pesoLibre = max(0.0, $capPeso - $usadoPeso);

    $capVol = isset($trip['capacidad_volumen_m3']) && $trip['capacidad_volumen_m3'] !== null ? (float)$trip['capacidad_volumen_m3'] : null;
    $usadoVol = (float)($trip['volumen_usado_m3'] ?? 0);
    $volLibre = ($capVol !== null && $capVol > 0) ? max(0.0, $capVol - $usadoVol) : null;

    if ($reqPeso > $pesoLibre + 0.001) {
        return [
            'cabe' => false,
            'nivel' => 'sin_capacidad',
            'etiqueta' => 'Excede peso',
            'tag_class' => 'is-danger',
            'puntaje' => -100,
            'desvio_km' => null,
            'peso_libre_kg' => $pesoLibre,
            'motivo' => 'La carga (' . format_cr_measure($reqPeso) . ' kg) supera los ' . format_cr_measure($pesoLibre) . ' kg disponibles.',
        ];
    }
    if ($volLibre !== null && $reqVol > $volLibre + 0.0001) {
        return [
            'cabe' => false,
            'nivel' => 'sin_capacidad',
            'etiqueta' => 'Excede volumen',
            'tag_class' => 'is-danger',
            'puntaje' => -95,
            'desvio_km' => null,
            'peso_libre_kg' => $pesoLibre,
            'motivo' => 'El volumen (' . format_cr_measure($reqVol, 2) . ' m³) supera los ' . format_cr_measure($volLibre, 2) . ' m³ disponibles.',
        ];
    }

    $reqOriId = (int)($req['origen_id'] ?? 0);
    $reqDesId = (int)($req['destino_id'] ?? 0);
    $reqOriLat = dispatch_to_float($req['origen_lat'] ?? null);
    $reqOriLon = dispatch_to_float($req['origen_lon'] ?? null);
    $reqDesLat = dispatch_to_float($req['destino_lat'] ?? null);
    $reqDesLon = dispatch_to_float($req['destino_lon'] ?? null);

    // Puntos de referencia del corredor del viaje (ruta + paradas existentes)
    $corridorIds = [];
    $corridorCoords = [];
    if (!empty($trip['ruta_origen_id'])) {
        $corridorIds[(int)$trip['ruta_origen_id']] = true;
    }
    if (!empty($trip['ruta_destino_id'])) {
        $corridorIds[(int)$trip['ruta_destino_id']] = true;
    }
    $rOriLat = dispatch_to_float($trip['ruta_origen_lat'] ?? null);
    $rOriLon = dispatch_to_float($trip['ruta_origen_lon'] ?? null);
    $rDesLat = dispatch_to_float($trip['ruta_destino_lat'] ?? null);
    $rDesLon = dispatch_to_float($trip['ruta_destino_lon'] ?? null);
    if ($rOriLat !== null && $rOriLon !== null) {
        $corridorCoords[] = [$rOriLat, $rOriLon];
    }
    if ($rDesLat !== null && $rDesLon !== null) {
        $corridorCoords[] = [$rDesLat, $rDesLon];
    }
    foreach ($tripStops as $st) {
        if (!empty($st['ubicacion_id'])) {
            $corridorIds[(int)$st['ubicacion_id']] = true;
        }
        $sLat = dispatch_to_float($st['latitud'] ?? null);
        $sLon = dispatch_to_float($st['longitud'] ?? null);
        if ($sLat !== null && $sLon !== null) {
            $corridorCoords[] = [$sLat, $sLon];
        }
    }

    $matchOri = $reqOriId > 0 && isset($corridorIds[$reqOriId]);
    $matchDes = $reqDesId > 0 && isset($corridorIds[$reqDesId]);

    $minDistToCorridor = static function (?float $lat, ?float $lon) use ($corridorCoords): ?float {
        if ($lat === null || $lon === null || !$corridorCoords) {
            return null;
        }
        $best = null;
        foreach ($corridorCoords as [$cLat, $cLon]) {
            $d = dispatch_haversine_km($lat, $lon, $cLat, $cLon);
            if ($d !== null && ($best === null || $d < $best)) {
                $best = $d;
            }
        }
        return $best;
    };

    $distOri = $matchOri ? 0.0 : $minDistToCorridor($reqOriLat, $reqOriLon);
    $distDes = $matchDes ? 0.0 : $minDistToCorridor($reqDesLat, $reqDesLon);

    $maxDesvio = null;
    if ($distOri !== null && $distDes !== null) {
        $maxDesvio = max($distOri, $distDes);
    } elseif ($distDes !== null) {
        $maxDesvio = $distDes;
    } elseif ($distOri !== null) {
        $maxDesvio = $distOri;
    }

    $score = 0;
    $reasons = [];

    if ($matchOri && $matchDes) {
        $score += 80;
        $reasons[] = 'Coincide origen y destino del corredor';
    } elseif ($matchDes) {
        $score += 55;
        $reasons[] = 'Coincide destino en ruta';
        if ($distOri !== null && $distOri <= 20.0) {
            $score += 18;
            $reasons[] = 'origen cercano (~' . round($distOri) . ' km)';
        } elseif ($distOri !== null && $distOri > 60.0) {
            $score -= 25;
            $reasons[] = 'recogida alejada (~' . round($distOri) . ' km)';
        }
    } elseif ($matchOri) {
        $score += 35;
        $reasons[] = 'Coincide origen en ruta';
        if ($distDes !== null && $distDes <= 20.0) {
            $score += 30;
            $reasons[] = 'destino muy cercano (~' . round($distDes) . ' km)';
        } elseif ($distDes !== null && $distDes <= 45.0) {
            $score += 15;
            $reasons[] = 'destino en misma zona (~' . round($distDes) . ' km)';
        } elseif ($distDes !== null && $distDes > 75.0) {
            $score -= 35;
            $reasons[] = 'destino fuera de corredor (~' . round($distDes) . ' km)';
        }
    } elseif ($maxDesvio !== null) {
        if ($maxDesvio <= 20.0) {
            $score += 60;
            $reasons[] = 'Misma zona operativa (~' . round($maxDesvio) . ' km)';
        } elseif ($maxDesvio <= 45.0) {
            $score += 35;
            $reasons[] = 'Zona cercana (~' . round($maxDesvio) . ' km)';
        } else {
            $score -= 20;
            $reasons[] = 'Fuera de corredor (~' . round($maxDesvio) . ' km de desvío)';
        }
    } else {
        $score += 20;
        $reasons[] = 'Sin coordenadas para medir desvío';
    }

    // Ventana de tiempo
    $tsSalida = !empty($trip['fecha_programada_salida']) ? strtotime((string)$trip['fecha_programada_salida'] . ' UTC') : false;
    $tsReq = !empty($req['fecha_entrega_requerida']) ? strtotime((string)$req['fecha_entrega_requerida'] . ' UTC') : false;
    if ($tsSalida !== false && $tsReq !== false) {
        if ($tsSalida <= $tsReq) {
            $score += 20;
            $reasons[] = 'sale a tiempo';
        } else {
            $score -= 15;
            $reasons[] = 'salida posterior a fecha requerida';
        }
    }

    $reasons[] = format_cr_measure($pesoLibre) . ' kg libres';

    if ($maxDesvio !== null && $maxDesvio > 75.0) {
        $nivel = 'desvio';
        $etiqueta = 'Desvío alto (~' . round($maxDesvio) . ' km)';
        $tagClass = 'is-warning';
    } elseif ($score >= 70) {
        $nivel = 'optimo';
        $etiqueta = 'Óptimo';
        $tagClass = 'is-success';
    } elseif ($score >= 30) {
        $nivel = 'compatible';
        $etiqueta = 'Compatible';
        $tagClass = 'is-info';
    } else {
        $nivel = 'desvio';
        $etiqueta = $maxDesvio !== null ? ('Otro corredor (~' . round($maxDesvio) . ' km)') : 'Poco eficiente';
        $tagClass = 'is-warning';
    }

    return [
        'cabe' => true,
        'nivel' => $nivel,
        'etiqueta' => $etiqueta,
        'tag_class' => $tagClass,
        'puntaje' => $score,
        'desvio_km' => $maxDesvio !== null ? round($maxDesvio, 1) : null,
        'peso_libre_kg' => $pesoLibre,
        'motivo' => implode(' · ', $reasons),
    ];
}

/**
 * Obtiene y rankea viajes PLANIFICADO sugeridos para una solicitud.
 *
 * @return array{req: ?array<string, mixed>, sugeridos: array<int, array<string, mixed>>, descartados_capacidad: int}
 */
function dispatch_suggest_trips_for_request(int $solicitudId): array
{
    $sets = read_procedure_sets('EXEC dbo.sp_sugerir_viajes_para_solicitud @solicitud_id=?;', [$solicitudId]);
    $req = $sets[0][0] ?? null;
    $trips = $sets[1] ?? [];
    $allStops = $sets[2] ?? [];

    if (!is_array($req)) {
        return ['req' => null, 'sugeridos' => [], 'descartados_capacidad' => 0];
    }

    $stopsByTrip = [];
    foreach ($allStops as $st) {
        $stopsByTrip[(int)$st['viaje_id']][] = $st;
    }

    $sugeridos = [];
    $descartados = 0;

    foreach ($trips as $trip) {
        $vid = (int)$trip['viaje_id'];
        $fit = dispatch_evaluate_fit($req, $trip, $stopsByTrip[$vid] ?? []);
        if (!$fit['cabe']) {
            $descartados++;
            continue;
        }
        $sugeridos[] = array_merge($trip, ['fit' => $fit]);
    }

    usort($sugeridos, static function (array $a, array $b): int {
        return ($b['fit']['puntaje'] <=> $a['fit']['puntaje'])
            ?: strcmp((string)$a['fecha_programada_salida'], (string)$b['fecha_programada_salida']);
    });

    return [
        'req' => $req,
        'sugeridos' => $sugeridos,
        'descartados_capacidad' => $descartados,
    ];
}

/**
 * Selecciona la ruta activa más adecuada para una solicitud al abrir "Crear viaje con esta solicitud".
 *
 * @param array<int, array<string, mixed>> $rutas
 */
function dispatch_best_route_id_for_request(array $rutas, int $origenId, int $destinoId): int
{
    $bestId = 0;
    $bestScore = -1;
    foreach ($rutas as $r) {
        $rOri = (int)($r['origen_id'] ?? 0);
        $rDes = (int)($r['destino_id'] ?? 0);
        $score = 0;
        if ($rOri === $origenId && $rDes === $destinoId) {
            $score = 100;
        } elseif ($rDes === $destinoId) {
            $score = 60;
        } elseif ($rOri === $origenId) {
            $score = 40;
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestId = $score > 0 ? (int)$r['ruta_id'] : 0;
        }
    }
    return $bestId;
}

/**
 * Calcula y persiste el orden óptimo de paradas (Nearest Neighbor + Haversine)
 * respetando estrictamente recogida < entrega, usando dbo.sp_reordenar_paradas_viaje.
 */
function dispatch_optimize_trip_stops(PDO $pdo, int $viajeId): bool
{
    $sets = read_procedure_sets('EXEC dbo.sp_obtener_viaje @viaje_id=?;', [$viajeId]);
    $viaje = $sets[0][0] ?? null;
    $paradas = $sets[1] ?? [];
    $asignaciones = $sets[2] ?? [];

    if (!is_array($viaje) || ($viaje['estado'] ?? '') !== 'PLANIFICADO' || count($paradas) < 2) {
        return false;
    }

    $nodes = [];
    $currentOrderIds = [];
    foreach ($paradas as $idx => $p) {
        $pid = (int)$p['parada_id'];
        $lat = dispatch_to_float($p['latitud'] ?? null);
        $lon = dispatch_to_float($p['longitud'] ?? null);
        $nodes[$pid] = [
            'id' => $pid,
            'idx' => $idx,
            'tipo' => strtoupper(trim((string)($p['tipo_parada'] ?? ''))),
            'lat' => $lat,
            'lon' => $lon,
            'hasCoords' => ($lat !== null && $lon !== null),
        ];
        $currentOrderIds[] = $pid;
    }

    $prereqs = [];
    foreach ($nodes as $pid => $_) {
        $prereqs[$pid] = [];
    }
    foreach ($asignaciones as $asig) {
        $rec = (int)($asig['parada_recogida_id'] ?? 0);
        $ent = (int)($asig['parada_entrega_id'] ?? 0);
        if (isset($prereqs[$ent], $prereqs[$rec]) && $rec !== $ent) {
            $prereqs[$ent][$rec] = true;
        }
    }

    $remaining = $nodes;
    $visited = [];
    $orderedIds = [];
    $current = null;

    while ($remaining) {
        $eligible = [];
        foreach ($remaining as $pid => $node) {
            $ok = true;
            foreach ($prereqs[$pid] as $reqId => $_) {
                if (!isset($visited[$reqId])) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $eligible[] = $node;
            }
        }
        $pool = $eligible ?: array_values($remaining);
        $chosen = null;

        if ($current === null) {
            foreach ($pool as $cand) {
                if ($cand['hasCoords'] && in_array($cand['tipo'], ['RECOGIDA', 'MIXTA'], true)) {
                    $chosen = $cand;
                    break;
                }
            }
            if ($chosen === null) {
                foreach ($pool as $cand) {
                    if ($cand['hasCoords']) {
                        $chosen = $cand;
                        break;
                    }
                }
            }
            if ($chosen === null) {
                $chosen = $pool[0];
            }
        } elseif ($current['hasCoords']) {
            $bestDist = INF;
            foreach ($pool as $cand) {
                if ($cand['hasCoords']) {
                    $d = dispatch_haversine_km($current['lat'], $current['lon'], $cand['lat'], $cand['lon']);
                    if ($d !== null && $d < $bestDist) {
                        $bestDist = $d;
                        $chosen = $cand;
                    }
                }
            }
            if ($chosen === null) {
                $chosen = $pool[0];
            }
        } else {
            foreach ($pool as $cand) {
                if ($cand['hasCoords']) {
                    $chosen = $cand;
                    break;
                }
            }
            if ($chosen === null) {
                $chosen = $pool[0];
            }
        }

        $orderedIds[] = $chosen['id'];
        $visited[$chosen['id']] = true;
        unset($remaining[$chosen['id']]);
        if ($chosen['hasCoords']) {
            $current = $chosen;
        }
    }

    if ($orderedIds === $currentOrderIds || count($orderedIds) < 2) {
        return false;
    }

    $placeholders = implode(',', array_fill(0, count($orderedIds), '(?,?)'));
    $sql = 'DECLARE @paradas dbo.tipo_orden_paradas; '
        . 'INSERT INTO @paradas (parada_id, orden) VALUES ' . $placeholders . '; '
        . 'EXEC dbo.sp_reordenar_paradas_viaje @viaje_id = ?, @paradas = @paradas;';

    $params = [];
    foreach ($orderedIds as $idx => $paradaId) {
        $params[] = $paradaId;
        $params[] = $idx + 1;
    }
    $params[] = $viajeId;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $stmt->closeCursor();

    return true;
}
