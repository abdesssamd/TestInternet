<?php
/**
 * Client SMTP minimal (socket brut, STARTTLS + AUTH LOGIN), sans dependance externe.
 * XAMPP/Windows ne fournit pas de MTA local (mail() natif n'est pas fiable) :
 * ce client parle directement au serveur SMTP fourni (Gmail, Office365, relais interne...).
 */

declare(strict_types=1);

require_once __DIR__ . '/../models/Setting.php';
require_once __DIR__ . '/../config/config.php';

final class Mailer
{
    /**
     * Envoie un email texte simple a une liste de destinataires (CSV).
     * Retourne true si l'envoi a reussi, false sinon (voir error_log pour le detail).
     */
    public static function send(string $toCsv, string $subject, string $bodyText): bool
    {
        $host = SettingModel::get('smtp_host', '');
        $port = SettingModel::getInt('smtp_port', 587);
        $encryption = SettingModel::get('smtp_encryption', 'tls'); // tls|ssl|none
        $username = SettingModel::get('smtp_username', '');
        $passwordEnc = SettingModel::get('smtp_password_enc', '');
        $fromEmail = SettingModel::get('smtp_from_email', '');
        $fromName = SettingModel::get('smtp_from_name', APP_NAME);

        $recipients = array_filter(array_map('trim', explode(',', $toCsv)));

        if (!$host || !$fromEmail || empty($recipients)) {
            error_log('Mailer::send abandonne : configuration SMTP incomplete');
            return false;
        }

        $password = $passwordEnc !== '' ? self::decryptSecret($passwordEnc) : '';

        try {
            $transport = ($encryption === 'ssl') ? 'ssl://' : '';
            $socket = @stream_socket_client(
                $transport . $host . ':' . $port,
                $errno,
                $errstr,
                10,
                STREAM_CLIENT_CONNECT
            );
            if (!$socket) {
                error_log("Mailer::send: connexion SMTP echouee ($errno) $errstr");
                return false;
            }
            stream_set_timeout($socket, 10);

            self::expect($socket, '220');
            self::command($socket, "EHLO " . gethostname(), '250');

            if ($encryption === 'tls') {
                self::command($socket, "STARTTLS", '220');
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    error_log('Mailer::send: negociation STARTTLS echouee');
                    fclose($socket);
                    return false;
                }
                self::command($socket, "EHLO " . gethostname(), '250');
            }

            if ($username !== '') {
                self::command($socket, "AUTH LOGIN", '334');
                self::command($socket, base64_encode($username), '334');
                self::command($socket, base64_encode($password), '235');
            }

            self::command($socket, "MAIL FROM:<$fromEmail>", '250');
            foreach ($recipients as $rcpt) {
                self::command($socket, "RCPT TO:<$rcpt>", '250');
            }
            self::command($socket, "DATA", '354');

            $headers = [
                'From: ' . self::encodeHeader($fromName) . " <$fromEmail>",
                'To: ' . implode(', ', $recipients),
                'Subject: ' . self::encodeHeader($subject),
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Date: ' . date('r'),
            ];
            $data = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", $bodyText) . "\r\n.";
            self::command($socket, $data, '250');

            self::command($socket, "QUIT", '221');
            fclose($socket);

            return true;
        } catch (Throwable $e) {
            error_log('Mailer::send erreur: ' . $e->getMessage());
            return false;
        }
    }

    private static function command($socket, string $cmd, string $expectedCode): void
    {
        fwrite($socket, $cmd . "\r\n");
        self::expect($socket, $expectedCode);
    }

    private static function expect($socket, string $expectedCode): void
    {
        $response = '';
        while ($line = fgets($socket, 512)) {
            $response .= $line;
            // Une ligne de reponse SMTP multi-lignes utilise '-' apres le code ; la derniere ligne utilise ' '.
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        if (strncmp($response, $expectedCode, strlen($expectedCode)) !== 0) {
            throw new RuntimeException("Reponse SMTP inattendue (attendu $expectedCode) : $response");
        }
    }

    private static function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }

    /**
     * Chiffre un secret (ex: mot de passe SMTP) avec AES-256-GCM avant stockage en base.
     */
    public static function encryptSecret(string $plaintext): string
    {
        $key = hex2bin(SETTINGS_ENC_KEY);
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $ciphertext);
    }

    private static function decryptSecret(string $encoded): string
    {
        $raw = base64_decode($encoded);
        if ($raw === false || strlen($raw) < 28) {
            return '';
        }
        $key = hex2bin(SETTINGS_ENC_KEY);
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plaintext === false ? '' : $plaintext;
    }
}
