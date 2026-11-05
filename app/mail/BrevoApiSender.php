<?php
declare(strict_types=1);

require_once __DIR__ . '/MailSender.php';

final class BrevoApiSender implements MailSender
{
    private string $apiKey;
    private string $senderEmail;
    private string $senderName;

    public function __construct(string $apiKey, string $senderEmail, string $senderName = 'Transportes Seguros S.A.')
    {
        $this->apiKey = trim($apiKey);
        $this->senderEmail = trim($senderEmail);
        $this->senderName = trim($senderName);
    }

    public function send(string $to, string $subject, string $htmlContent, string $toName = ''): bool
    {
        if ($this->apiKey === '' || $this->senderEmail === '') {
            error_log('[BrevoApiSender] Error: API key o correo remitente no configurados.');
            return false;
        }

        $url = 'https://api.brevo.com/v3/smtp/email';

        $payload = [
            'sender' => [
                'name' => $this->senderName,
                'email' => $this->senderEmail,
            ],
            'to' => [
                [
                    'email' => $to,
                    'name' => $toName !== '' ? $toName : $to,
                ],
            ],
            'subject' => $subject,
            'htmlContent' => $htmlContent,
        ];

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

        if (!function_exists('curl_init')) {
            error_log('[BrevoApiSender] Error: cURL no está disponible.');
            return false;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            error_log('[BrevoApiSender] Error al inicializar cURL.');
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'accept: application/json',
                'api-key: ' . $this->apiKey,
                'content-type: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            error_log('[BrevoApiSender] Error cURL: ' . $curlError);
            return false;
        }

        // Brevo responde 201 Created cuando el correo se encola exitosamente
        if ($httpCode >= 200 && $httpCode < 300) {
            return true;
        }

        error_log("[BrevoApiSender] Falló el envío. HTTP Code: {$httpCode}. Respuesta: " . substr((string)$response, 0, 300));
        return false;
    }
}
