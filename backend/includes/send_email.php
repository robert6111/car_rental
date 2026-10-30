<?php
/*
 * Outgoing email through SMTP. Uses the Email section of Settings when an SMTP host is
 * entered there; otherwise the 'mail' section of config/config.php.
 * sendEmail() returns true only when the mail server accepted the message.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/settings.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

function cr_mail_settings(): array {
    if (trim(cr_setting('smtp_host')) !== '') {
        return [
            'host' => trim(cr_setting('smtp_host')),
            'port' => (int)cr_setting('smtp_port'),
            'encryption' => cr_setting('smtp_encryption'),
            'username' => cr_setting('smtp_username'),
            'password' => cr_setting('smtp_password'),
            'from_email' => cr_setting('mail_from_email'),
            'from_name' => cr_setting('mail_from_name') !== '' ? cr_setting('mail_from_name') : cr_company_name(),
        ];
    }
    $m = (array)cr_config('mail', []);
    $m['from_name'] = ($m['from_name'] ?? '') !== '' ? $m['from_name'] : cr_company_name();
    return $m;
}

function cr_mail_configured(): bool {
    $m = cr_mail_settings();
    return trim((string)($m['host'] ?? '')) !== '' && filter_var((string)($m['from_email'] ?? ''), FILTER_VALIDATE_EMAIL);
}

/** Last mail error (for the Settings "send test email" button). */
function cr_mail_last_error(?string $set = null): string {
    static $last = '';
    if ($set !== null) $last = $set;
    return $last;
}

/** A PHPMailer object with the SMTP connection settings filled in. */
function cr_mailer(): PHPMailer {
    $cfg = cr_mail_settings();
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = (string)$cfg['host'];
    $mail->Port = (int)($cfg['port'] ?: 587);
    if ((string)($cfg['username'] ?? '') !== '') {
        $mail->SMTPAuth = true;
        $mail->Username = (string)$cfg['username'];
        $mail->Password = (string)($cfg['password'] ?? '');
    }
    $encryption = strtolower((string)($cfg['encryption'] ?? 'tls'));
    if ($encryption === 'ssl') $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    elseif ($encryption === 'tls') $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    else { $mail->SMTPSecure = ''; $mail->SMTPAutoTLS = false; }
    $mail->Timeout = 15;
    return $mail;
}

/**
 * Signs in to the mail server without sending anything.
 * Returns null when the server accepted the login, otherwise the error text.
 */
function cr_mail_login_test(): ?string {
    if (!cr_mail_configured()) return 'Email is not configured.';
    $cfg = cr_mail_settings();
    if ((string)($cfg['username'] ?? '') !== '' && (string)($cfg['password'] ?? '') === '') return 'The email password is empty.';
    try {
        $mail = cr_mailer();
        $mail->Timeout = 10;
        $ok = $mail->smtpConnect();
        $mail->smtpClose();
        return $ok ? null : 'Could not sign in to the mail server.';
    } catch (MailException $e) {
        return ($mail->ErrorInfo ?? '') !== '' ? $mail->ErrorInfo : $e->getMessage();
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

function sendEmail(string $to, string $subject, string $htmlBody, string $textBody = ''): bool {
    if (!cr_mail_configured()) { cr_mail_last_error('Email is not configured.'); return false; }
    $to = trim(str_replace(["\r", "\n"], '', $to));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { cr_mail_last_error('Invalid recipient address.'); return false; }

    $cfg = cr_mail_settings();
    try {
        $mail = cr_mailer();
        $mail->CharSet = PHPMailer::CHARSET_UTF8;

        $mail->setFrom((string)$cfg['from_email'], (string)$cfg['from_name']);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = str_replace(["\r", "\n"], ' ', $subject);
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody !== '' ? $textBody : trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody)), ENT_QUOTES, 'UTF-8'));
        $mail->send();
        cr_mail_last_error('');
        return true;
    } catch (MailException $e) {
        $why = ($mail->ErrorInfo ?? '') !== '' ? $mail->ErrorInfo : $e->getMessage();
        cr_mail_last_error($why);
        error_log('Email to ' . $to . ' failed: ' . $why);
        return false;
    }
}
