<?php
declare(strict_types=1);

require_once __DIR__ . '/MailSender.php';

final class BrevoApiSender implements MailSender
{
    private string $apiKey;
    private string $senderEmail;
    private string $senderName;
    private string $baseUrl;

    private string $lastCategory = 'none';
    private int $lastHttpCode = 0;
    private ?string $lastMessageId = null;
    private ?string $lastErrorMessage = null;

    public function __construct(
        string $apiKey,
        string $senderEmail,
        string $senderName = 'Transportes Seguros S.A.',
        ?string $baseUrl = null
    ) {
        $this->apiKey = trim($apiKey);
        $this->senderEmail = trim($senderEmail);
        $this->senderName = trim($senderName);
        $this->baseUrl = trim($baseUrl ?? (getenv('BREVO_API_BASE_URL') ?: 'https://api.brevo.com/v3'));
    }

    public function getLastCategory(): string
    {
        return $this->lastCategory;
    }

    public function getLastHttpCode(): int
    {
        return $this->lastHttpCode;
    }

    public function getLastMessageId(): ?string
    {
        return $this->lastMessageId;
    }

    public function getLastErrorMessage(): ?string
    {
        return $this->lastErrorMessage;
    }

    public function send(string $to, string $subject, string $htmlContent, string $toName = ''): bool
    {
        $this->lastCategory = 'none';
        $this->lastHttpCode = 0;
        $this->lastMessageId = null;
        $this->lastErrorMessage = null;

        // 1. Validar configuración requerida
        if ($this->apiKey === '' || $this->senderEmail === '' || !filter_var($this->senderEmail, FILTER_VALIDATE_EMAIL)) {
            $this->lastCategory = 'config_invalid';
            $this->lastErrorMessage = 'Credenciales o remitente de Brevo incompletos o inválidos.';
            error_log('[BrevoApiSender] Error: Configuración de Brevo inválida (falta API key o remitente válido).');
            return false;
        }

        // 2. Validar cURL
        if (!function_exists('curl_init')) {
            $this->lastCategory = 'curl_missing';
            $this->lastErrorMessage = 'La extensión cURL no está disponible en el entorno PHP.';
            error_log('[BrevoApiSender] Error: cURL no está disponible.');
            return false;
        }

        $url = rtrim($this->baseUrl, '/') . '/smtp/email';

        // 3. Construir payload transaccional normalizado (alineado con especificación Brevo v3)
        $payload = [
            'sender' => [
                'name' => $this->senderName !== '' ? $this->senderName : 'Transportes Seguros S.A.',
                'email' => $this->senderEmail,
            ],
            'to' => [
                [
                    'email' => trim($to),
                    'name' => $toName !== '' ? trim($toName) : trim($to),
                ],
            ],
            'subject' => trim($subject),
            'htmlContent' => $htmlContent,
            'textContent' => trim(strip_tags($htmlContent)),
        ];

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($jsonPayload === false) {
            $this->lastCategory = 'config_invalid';
            $this->lastErrorMessage = 'Fallo al codificar el payload JSON para Brevo.';
            return false;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            $this->lastCategory = 'network_error';
            $this->lastErrorMessage = 'No fue posible inicializar el recurso cURL.';
            return false;
        }

        $headers = [
            'accept: application/json',
            'api-key: ' . $this->apiKey,
            'content-type: application/json',
        ];

        $caInfo = ini_get('curl.cainfo');
        $curlOpts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if (!empty($caInfo) && file_exists($caInfo)) {
            $curlOpts[CURLOPT_CAINFO] = $caInfo;
        } elseif (file_exists('C:/AdmBD2026/Herramientas/PHP/8.4/extras/ssl/cacert.pem')) {
            $curlOpts[CURLOPT_CAINFO] = 'C:/AdmBD2026/Herramientas/PHP/8.4/extras/ssl/cacert.pem';
        }

        curl_setopt_array($ch, $curlOpts);

        $responseBody = curl_exec($ch);
        $this->lastHttpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        // 4. Mapear errores de transporte a nivel cURL
        if ($curlErrno !== 0) {
            $this->categorizarErrorCurl($curlErrno, $curlError);
            error_log(sprintf(
                '[BrevoApiSender] Error de transporte cURL [categoría: %s, errno: %d]: %s',
                $this->lastCategory,
                $curlErrno,
                $curlError
            ));
            return false;
        }

        // 5. Evaluar código de respuesta HTTP del proveedor
        if ($this->lastHttpCode >= 200 && $this->lastHttpCode < 300) {
            $this->lastCategory = 'accepted';
            $data = json_decode((string)$responseBody, true);
            if (is_array($data) && !empty($data['messageId'])) {
                $this->lastMessageId = (string)$data['messageId'];
            }
            return true;
        }

        // Si el proveedor rechazó la solicitud (4xx, 5xx)
        $this->lastCategory = 'provider_reject';
        $safeResponse = $this->sanitizarRespuestaProveedor((string)$responseBody);
        $this->lastErrorMessage = sprintf('Proveedor rechazó la solicitud con HTTP %d.', $this->lastHttpCode);

        error_log(sprintf(
            '[BrevoApiSender] Rechazo de proveedor Brevo [HTTP %d]: %s',
            $this->lastHttpCode,
            $safeResponse
        ));

        return false;
    }

    private function categorizarErrorCurl(int $errno, string $errorMsg): void
    {
        // 6 = CURLE_COULDNT_RESOLVE_HOST
        if ($errno === 6) {
            $this->lastCategory = 'dns';
            $this->lastErrorMessage = 'Fallo en la resolución DNS del servicio de correo.';
            return;
        }

        // 35 = CURLE_SSL_CONNECT_ERROR, 58 = CURLE_SSL_CERTPROBLEM, 60 = CURLE_PEER_FAILED_VERIFICATION, 77 = CURLE_SSL_CACERT
        if (in_array($errno, [35, 58, 60, 77], true)) {
            $this->lastCategory = 'tls';
            $this->lastErrorMessage = 'Error de validación TLS/certificado al conectar con el proveedor.';
            return;
        }

        // 7 = CURLE_COULDNT_CONNECT, 28 = CURLE_OPERATION_TIMEDOUT
        if (in_array($errno, [7, 28], true)) {
            $this->lastCategory = 'timeout';
            $this->lastErrorMessage = 'Tiempo de espera agotado al conectar con el servicio de correo.';
            return;
        }

        $this->lastCategory = 'network_error';
        $this->lastErrorMessage = 'Error de red al intentar despachar el correo transaccional.';
    }

    private function sanitizarRespuestaProveedor(string $raw): string
    {
        $sanitized = preg_replace('/(api-key|key|token|authorization)[:=]\s*["\']?[a-zA-Z0-9_\-\.]+["\']?/i', '$1:***', $raw);
        return substr((string)$sanitized, 0, 300);
    }
}
