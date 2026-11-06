<?php
declare(strict_types=1);

interface MailSender
{
    /**
     * Envía un correo transaccional.
     *
     * @param string $to Dirección de correo del destinatario
     * @param string $subject Asunto del correo
     * @param string $htmlContent Contenido HTML del mensaje
     * @param string $toName Nombre opcional del destinatario
     * @return bool True si el envío o captura fue exitoso
     */
    public function send(string $to, string $subject, string $htmlContent, string $toName = ''): bool;
}
