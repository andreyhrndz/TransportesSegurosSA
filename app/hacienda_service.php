<?php
declare(strict_types=1);

/**
 * Servicio de consulta a la API de Actividades Económicas del Ministerio de Hacienda de Costa Rica.
 * Endpoint público: https://api.hacienda.go.cr/fe/ae?identificacion={id}
 */

final class HaciendaService
{
    private static array $cache = [];
    private static ?array $mockResponses = null;

    /**
     * Permite registrar respuestas simuladas para suites de pruebas automatizadas.
     */
    public static function setMockResponses(?array $mocks): void
    {
        self::$mockResponses = $mocks;
    }

    /**
     * Limpia la caché en memoria del servicio.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Consulta una identificación costarricense y retorna información normalizada.
     *
     * @return array{
     *     status: 'encontrado'|'no_encontrado'|'invalido'|'limite_excedido'|'no_disponible',
     *     identificacion: string,
     *     nombre?: string,
     *     tipo?: string,
     *     mensaje?: string
     * }
     */
    public static function consultar(string $identificacion): array
    {
        $limpia = preg_replace('/\D/', '', trim($identificacion)) ?? '';
        $longitud = strlen($limpia);

        // Validación de longitud para identificaciones en Costa Rica (Física: 9, Jurídica/NITE: 10, DIMEX: 11-12)
        if ($longitud < 9 || $longitud > 12) {
            return [
                'status' => 'invalido',
                'identificacion' => $limpia,
                'mensaje' => 'La identificación debe contener entre 9 y 12 dígitos numéricos.',
            ];
        }

        // Consultar fixtures simulados si están activos
        if (self::$mockResponses !== null && array_key_exists($limpia, self::$mockResponses)) {
            return array_merge(['identificacion' => $limpia], self::$mockResponses[$limpia]);
        }

        if (getenv('TSSA_HACIENDA_MOCK') === '1') {
            return self::generarMockPorDefecto($limpia);
        }

        // Verificar caché en memoria
        if (isset(self::$cache[$limpia])) {
            return self::$cache[$limpia];
        }

        $url = 'https://api.hacienda.go.cr/fe/ae?identificacion=' . rawurlencode($limpia);

        if (!function_exists('curl_init')) {
            return [
                'status' => 'no_disponible',
                'identificacion' => $limpia,
                'mensaje' => 'La extensión cURL no está disponible en el servidor.',
            ];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'TransportesSegurosSA/1.0 (Sistema Logistico)',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
            ],
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false || $curlError !== '') {
            return [
                'status' => 'no_disponible',
                'identificacion' => $limpia,
                'mensaje' => 'No fue posible conectar con el servicio de Hacienda. Complete los datos manualmente.',
            ];
        }

        $resultado = match ($httpCode) {
            200 => self::procesarRespuesta200($limpia, (string)$responseBody),
            400 => [
                'status' => 'invalido',
                'identificacion' => $limpia,
                'mensaje' => 'Formato de identificación no admitido por el Ministerio de Hacienda.',
            ],
            404 => [
                'status' => 'no_encontrado',
                'identificacion' => $limpia,
                'mensaje' => 'Identificación no encontrada en el registro tributario de Hacienda.',
            ],
            429 => [
                'status' => 'limite_excedido',
                'identificacion' => $limpia,
                'mensaje' => 'Límite de consultas excedido temporalmente. Ingrese los datos manualmente.',
            ],
            default => [
                'status' => 'no_disponible',
                'identificacion' => $limpia,
                'mensaje' => 'Respuesta no disponible del servicio de Hacienda (' . $httpCode . '). Complete manualmente.',
            ],
        };

        if ($resultado['status'] === 'encontrado') {
            self::$cache[$limpia] = $resultado;
        }

        return $resultado;
    }

    private static function procesarRespuesta200(string $identificacion, string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['nombre'])) {
            return [
                'status' => 'no_disponible',
                'identificacion' => $identificacion,
                'mensaje' => 'Estructura inesperada en la respuesta de Hacienda.',
            ];
        }

        $nombre = trim((string)$data['nombre']);
        $tipo = trim((string)($data['tipoIdentificacion'] ?? ''));

        return [
            'status' => 'encontrado',
            'identificacion' => $identificacion,
            'nombre' => $nombre,
            'tipo' => $tipo !== '' ? $tipo : self::deducirTipoIdentificacion($identificacion),
        ];
    }

    public static function deducirTipoIdentificacion(string $identificacion): string
    {
        $len = strlen($identificacion);
        return match ($len) {
            9 => '01',   // Cédula física
            10 => '02',  // Cédula jurídica
            11, 12 => '03', // DIMEX
            default => '04', // NITE / Otro
        };
    }

    private static function generarMockPorDefecto(string $limpia): array
    {
        if ($limpia === '000000000' || $limpia === '999999999') {
            return [
                'status' => 'no_encontrado',
                'identificacion' => $limpia,
                'mensaje' => 'Identificación simulada no encontrada.',
            ];
        }

        $tipo = self::deducirTipoIdentificacion($limpia);
        $nombre = match ($tipo) {
            '02' => 'CORPORACION LOGISTICA SIMULADA S.A.',
            default => 'ALFARO VARGAS JUAN CARLOS',
        };

        return [
            'status' => 'encontrado',
            'identificacion' => $limpia,
            'nombre' => $nombre,
            'tipo' => $tipo,
        ];
    }
}
