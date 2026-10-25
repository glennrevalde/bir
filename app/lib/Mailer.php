<?php
/**
 * Outgoing mail. Every message is recorded in mail_outbox; when mail_mode is "mail"
 * PHP mail() is also attempted. On a local XAMPP machine without SMTP, administrators
 * read verification and reset links from Platform settings > Mail outbox.
 */
class Mailer
{
    public static function send($to, $subject, $body)
    {
        $ok = 0;
        if (sys_config('mail_mode', 'outbox') === 'mail' && function_exists('mail')) {
            $from = config('mail.from', 'no-reply@example.com');
            $name = config('mail.from_name', 'G-SMaRT BIR');
            $headers = 'From: ' . $name . ' <' . $from . ">\r\nContent-Type: text/plain; charset=UTF-8\r\nX-Mailer: G-SMaRT-BIR";
            $ok = @mail($to, $subject, $body, $headers) ? 1 : 0;
        }
        DB::insert('mail_outbox', array('to_email' => $to, 'subject' => $subject, 'body' => $body, 'sent_ok' => $ok, 'created_at' => now()));
        return (bool)$ok;
    }
}
