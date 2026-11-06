<?php
declare(strict_types=1);

require_once __DIR__ . '/MailSender.php';

final class FakeMailCaptureSender implements MailSender
{
    private static array $messages = [];
    private static string $storagePath = '';

    private static function getStoragePath(): string
    {
        if (self::$storagePath === '') {
            self::$storagePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tssa_captured_mails.json';
        }
        return self::$storagePath;
    }

    public function send(string $to, string $subject, string $htmlContent, string $toName = ''): bool
    {
        $message = [
            'to' => $to,
            'to_name' => $toName,
            'subject' => $subject,
            'html' => $htmlContent,
            'htmlBody' => $htmlContent,
            'sent_at' => gmdate('Y-m-d H:i:s'),
        ];

        self::$messages[] = $message;

        try {
            $existing = [];
            $path = self::getStoragePath();
            if (file_exists($path)) {
                $decoded = json_decode((string)file_get_contents($path), true);
                if (is_array($decoded)) {
                    $existing = $decoded;
                }
            }
            $existing[] = $message;
            file_put_contents($path, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } catch (Throwable) {
            // No bloquear la captura en memoria si el almacenamiento temporal falla
        }

        return true;
    }

    public static function count(): int
    {
        return count(self::getMessages());
    }

    public static function getMessages(): array
    {
        if (empty(self::$messages)) {
            $path = self::getStoragePath();
            if (file_exists($path)) {
                $decoded = json_decode((string)file_get_contents($path), true);
                if (is_array($decoded)) {
                    self::$messages = $decoded;
                }
            }
        }
        return self::$messages;
    }

    public static function getLastMessage(): ?array
    {
        $messages = self::getMessages();
        if (empty($messages)) {
            return null;
        }
        return $messages[count($messages) - 1];
    }

    public static function findMessageByEmail(string $email): ?array
    {
        $email = strtolower(trim($email));
        $messages = array_reverse(self::getMessages());
        foreach ($messages as $msg) {
            if (strtolower(trim((string)($msg['to'] ?? ''))) === $email) {
                return $msg;
            }
        }
        return null;
    }

    public static function extractTokenFromHtml(string $html): ?string
    {
        if (preg_match('/[?&]token=([a-f0-9]{64})/i', $html, $matches)) {
            return $matches[1];
        }
        return null;
    }

    public static function clear(): void
    {
        self::$messages = [];
        $path = self::getStoragePath();
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}
