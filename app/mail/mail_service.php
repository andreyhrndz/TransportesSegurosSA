<?php
declare(strict_types=1);

require_once __DIR__ . '/MailSender.php';
require_once __DIR__ . '/BrevoApiSender.php';
require_once __DIR__ . '/FakeMailCaptureSender.php';

function get_app_base_url(): string
{
    $envUrl = getenv('TSSA_APP_URL');
    if ($envUrl && trim($envUrl) !== '') {
        return rtrim(trim($envUrl), '/');
    }

    if (!empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = preg_replace('/[^a-zA-Z0-9.:_-]/', '', (string)$_SERVER['HTTP_HOST']);
        if ($host !== '') {
            return $scheme . '://' . $host;
        }
    }

    // Puerto 8001 es el utilizado localmente por el propietario de la aplicación
    return 'http://127.0.0.1:8001';
}

function get_mail_transport_mode(): string
{
    $transport = strtolower(trim((string)(getenv('TSSA_MAIL_TRANSPORT') ?: 'fake')));
    return ($transport === 'brevo') ? 'brevo' : 'fake';
}

function get_mail_transport_info(): array
{
    $mode = get_mail_transport_mode();
    $apiKey = trim((string)(getenv('BREVO_API_KEY') ?: ''));
    $senderEmail = trim((string)(getenv('BREVO_SENDER_EMAIL') ?: 'notificaciones@transportesseguros.cr'));
    $senderName = trim((string)(getenv('BREVO_SENDER_NAME') ?: 'Transportes Seguros S.A.'));

    return [
        'mode' => $mode,
        'is_brevo' => ($mode === 'brevo'),
        'has_api_key' => ($apiKey !== ''),
        'is_ready' => ($mode === 'fake' || ($mode === 'brevo' && $apiKey !== '' && $senderEmail !== '')),
        'sender_email' => $senderEmail,
        'sender_name' => $senderName,
        'app_url' => get_app_base_url(),
    ];
}

function get_mail_sender(): MailSender
{
    $mode = get_mail_transport_mode();
    $apiKey = trim((string)(getenv('BREVO_API_KEY') ?: ''));
    $senderEmail = trim((string)(getenv('BREVO_SENDER_EMAIL') ?: 'notificaciones@transportesseguros.cr'));
    $senderName = trim((string)(getenv('BREVO_SENDER_NAME') ?: 'Transportes Seguros S.A.'));

    // Si el modo configurado es Brevo y dispone de clave y remitente, usar el cliente HTTP API
    if ($mode === 'brevo' && $apiKey !== '' && $senderEmail !== '') {
        return new BrevoApiSender($apiKey, $senderEmail, $senderName);
    }

    // Modo fake de captura segura para pruebas locales y desarrollo
    return new FakeMailCaptureSender();
}

function render_invitation_email(string $inviteLink, string $email): string
{
    $safeEmail = htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeLink = htmlspecialchars($inviteLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Invitación a Transportes Seguros S.A.</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background: #0b111b; color: #e1e7f0; margin: 0; padding: 24px; }
        .card { max-width: 580px; margin: 0 auto; background: #121c2b; border: 1px solid #223247; border-radius: 12px; padding: 32px; }
        .brand { font-size: 20px; font-weight: 700; color: #648fff; margin-bottom: 20px; }
        h1 { font-size: 22px; color: #ffffff; margin-top: 0; }
        p { font-size: 15px; line-height: 1.6; color: #cbd5e1; }
        .btn-wrap { text-align: center; margin: 32px 0; }
        .btn { display: inline-block; background: #648fff; color: #0b111b !important; font-weight: 600; text-decoration: none; padding: 14px 28px; border-radius: 8px; font-size: 16px; }
        .btn:hover { background: #82a5ff; }
        .footer { font-size: 12px; color: #64748b; margin-top: 32px; border-top: 1px solid #1e293b; padding-top: 16px; }
        .url-text { word-break: break-all; color: #648fff; font-size: 13px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand">Transportes Seguros S.A.</div>
        <h1>Invitación a la plataforma logística</h1>
        <p>Has sido invitado a formar parte del sistema de gestión operativa de <strong>Transportes Seguros S.A.</strong> con el correo <strong>{$safeEmail}</strong>.</p>
        <p>Para activar tu cuenta y establecer tu contraseña personal de forma segura, haz clic en el siguiente botón:</p>
        <div class="btn-wrap">
            <a class="btn" href="{$safeLink}" target="_blank" rel="noopener noreferrer">Activar mi cuenta</a>
        </div>
        <p>Este enlace es de uso único y tiene una vigencia de <strong>24 horas</strong>.</p>
        <p class="footer">
            Si no puedes hacer clic en el botón, copia y pega este enlace en tu navegador:<br>
            <span class="url-text">{$safeLink}</span><br><br>
            Si no esperabas esta invitación, puedes ignorar este mensaje; tu cuenta no será activada.
        </p>
    </div>
</body>
</html>
HTML;
}

function render_password_reset_email(string $resetLink, string $email): string
{
    $safeEmail = htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeLink = htmlspecialchars($resetLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Restablecer contraseña — Transportes Seguros S.A.</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background: #0b111b; color: #e1e7f0; margin: 0; padding: 24px; }
        .card { max-width: 580px; margin: 0 auto; background: #121c2b; border: 1px solid #223247; border-radius: 12px; padding: 32px; }
        .brand { font-size: 20px; font-weight: 700; color: #648fff; margin-bottom: 20px; }
        h1 { font-size: 22px; color: #ffffff; margin-top: 0; }
        p { font-size: 15px; line-height: 1.6; color: #cbd5e1; }
        .btn-wrap { text-align: center; margin: 32px 0; }
        .btn { display: inline-block; background: #648fff; color: #0b111b !important; font-weight: 600; text-decoration: none; padding: 14px 28px; border-radius: 8px; font-size: 16px; }
        .btn:hover { background: #82a5ff; }
        .footer { font-size: 12px; color: #64748b; margin-top: 32px; border-top: 1px solid #1e293b; padding-top: 16px; }
        .url-text { word-break: break-all; color: #648fff; font-size: 13px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand">Transportes Seguros S.A.</div>
        <h1>Restablecimiento de contraseña</h1>
        <p>Hemos recibido una solicitud para restablecer la contraseña asociada a tu cuenta <strong>{$safeEmail}</strong> en la plataforma de Transportes Seguros S.A.</p>
        <p>Para crear una nueva contraseña, haz clic en el siguiente enlace:</p>
        <div class="btn-wrap">
            <a class="btn" href="{$safeLink}" target="_blank" rel="noopener noreferrer">Restablecer mi contraseña</a>
        </div>
        <p>Este enlace tiene una validez de <strong>2 horas</strong> y solo puede ser utilizado una vez.</p>
        <p class="footer">
            Si no solicitaste este cambio, puedes ignorar este correo de forma segura; tu contraseña actual no será modificada.<br><br>
            Enlace directo: <span class="url-text">{$safeLink}</span>
        </p>
    </div>
</body>
</html>
HTML;
}
