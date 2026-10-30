<?php
/*
 * Outgoing email through SMTP (settings in config/config.php -> 'mail').
 * Returns true only when the message was accepted by the mail server.
 */
require_once __DIR__ . '/config.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

function cr_mail_configured(): bool {
    return trim((string)cr_config('mail.host', '')) !== '' && filter_var((string)cr_config('mail.from_email', ''), FILTER_VALIDATE_EMAIL);
}

function sendEmail(string $to, string $subject, string $htmlBody, string $textBody = ''): bool {
    if (!cr_mail_configured()) return false;
    $to = trim(str_replace(["\r", "\n"], '', $to));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = (string)cr_config('mail.host');
        $mail->Port = (int)cr_config('mail.port', 587);
        $username = (string)cr_config('mail.username', '');
        if ($username !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = $username;
            $mail->Password = (string)cr_config('mail.password', '');
        }
        $encryption = strtolower((string)cr_config('mail.encryption', 'tls'));
        if ($encryption === 'ssl') $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        elseif ($encryption === 'tls') $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        else { $mail->SMTPSecure = ''; $mail->SMTPAutoTLS = false; }
        $mail->Timeout = 15;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;

        $mail->setFrom((string)cr_config('mail.from_email'), (string)cr_config('mail.from_name', 'Car Rental'));
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = str_replace(["\r", "\n"], ' ', $subject);
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody !== '' ? $textBody : trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody)), ENT_QUOTES, 'UTF-8'));
        $mail->send();
        return true;
    } catch (MailException $e) {
        error_log('Email to ' . $to . ' failed: ' . $mail->ErrorInfo);
        return false;
    }
}
