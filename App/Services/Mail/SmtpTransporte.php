<?php

declare(strict_types=1);

namespace App\Services\Mail;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Envío por SMTP con PHPMailer, con la configuración de .env (SMTP_HOST/PORT/USER/PASS,
 * STARTTLS). Remitente: MAIL_FROM / MAIL_FROM_NAME (opcionales).
 */
final class SmtpTransporte implements Transporte
{
    public function enviar(string $para, string $asunto, string $html, array $adjuntos = []): void
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $_ENV['SMTP_HOST'] ?? '';
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['SMTP_USER'] ?? '';
        $mail->Password   = $_ENV['SMTP_PASS'] ?? '';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)($_ENV['SMTP_PORT'] ?? 2525);
        $mail->Timeout    = 20;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(self::remitente(), self::nombreRemitente());
        $mail->addAddress($para);
        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $mail->Body    = $html;
        $mail->AltBody = self::textoPlano($html);
        foreach ($adjuntos as $a) {
            $mail->addStringAttachment($a['contenido'], $a['nombre'], PHPMailer::ENCODING_BASE64, $a['tipo']);
        }
        $mail->send();
    }

    public static function remitente(): string
    {
        return !empty($_ENV['MAIL_FROM']) ? (string)$_ENV['MAIL_FROM'] : 'no-reply@sires.com';
    }

    public static function nombreRemitente(): string
    {
        return !empty($_ENV['MAIL_FROM_NAME']) ? (string)$_ENV['MAIL_FROM_NAME'] : 'Sistema SIRES';
    }

    /** Versión en texto para clientes sin HTML. */
    private static function textoPlano(string $html): string
    {
        $texto = preg_replace(['#<(br|/p|/tr|/h[1-6]|/li)[^>]*>#i', '#<[^>]+>#'], ["\n", ''], $html) ?? '';
        $texto = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string)preg_replace("/\n\s*\n+/", "\n\n", (string)preg_replace('/[ \t]+/', ' ', $texto)));
    }
}
