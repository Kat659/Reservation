<?php
// Tiny SMTP client (no Composer needed). Works with Gmail, Outlook, school mail servers.
require_once __DIR__ . '/config.php';

function smtp_send($to, $subject, $text, $html, &$err) {
    $err = '';
    $fp = @stream_socket_client((SMTP_SECURE === 'ssl' ? 'ssl://' : '') . SMTP_HOST . ':' . SMTP_PORT, $en, $es, 15);
    if (!$fp) { $err = "Cannot connect to " . SMTP_HOST . " ($es)"; return false; }
    stream_set_timeout($fp, 15);
    $read = function () use ($fp) {
        $r = '';
        while (($l = fgets($fp, 515)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; }
        return $r;
    };
    $step = function ($line, $ok) use ($fp, $read, &$err) {
        if ($line !== null) fwrite($fp, $line . "\r\n");
        $r = $read();
        if (!in_array((int)substr($r, 0, 3), (array)$ok, true)) { $err = trim($r); return false; }
        return true;
    };
    $from = SMTP_USER;
    $ok = $step(null, 220) && $step('EHLO localhost', 250);
    if ($ok && SMTP_SECURE === 'tls') {
        $ok = $step('STARTTLS', 220) && stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) && $step('EHLO localhost', 250);
        if (!$ok && !$err) $err = 'TLS handshake failed (check OpenSSL is enabled in php.ini).';
    }
    $ok = $ok && $step('AUTH LOGIN', 334) && $step(base64_encode(SMTP_USER), 334) && $step(base64_encode(SMTP_PASS), 235);
    if (!$ok && $err && strpos($err, '535') === 0) $err .= ' (wrong email or app password)';
    $ok = $ok && $step("MAIL FROM:<$from>", 250) && $step("RCPT TO:<$to>", [250, 251]) && $step('DATA', 354);
    if (!$ok) { @fclose($fp); return false; }

    $b = 'b' . bin2hex(random_bytes(8));
    $msg = "From: " . APP_NAME . " <$from>\r\nTo: <$to>\r\nSubject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
         . "Date: " . date('r') . "\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"$b\"\r\n\r\n"
         . "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text)) . "\r\n"
         . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "\r\n--$b--\r\n";
    fwrite($fp, $msg . "\r\n.\r\n");
    $r = $read();
    $sent = (int)substr($r, 0, 3) === 250;
    if (!$sent) $err = trim($r);
    fwrite($fp, "QUIT\r\n");
    @fclose($fp);
    return $sent;
}
