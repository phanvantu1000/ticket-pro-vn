<?php
declare(strict_types=1);

final class LicenseKeyCipher {
    private const CIPHER = 'aes-256-gcm';
    private string $key;

    public function __construct(string $keyFile) {
        if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
            throw new RuntimeException('OpenSSL is required for protected license key storage.');
        }
        $secret = @file_get_contents($keyFile);
        if (!is_string($secret) || strlen(trim($secret)) < 32) {
            throw new RuntimeException('The protected license key storage secret is unavailable.');
        }
        $this->key = hash('sha256', trim($secret), true);
    }

    public function encrypt(string $plaintext): string {
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if ($ivLength === false) { throw new RuntimeException('The configured cipher is unavailable.'); }
        $iv = random_bytes($ivLength);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) { throw new RuntimeException('Could not protect the license key.'); }
        $payload = json_encode(array('v' => 1, 'iv' => base64_encode($iv), 'tag' => base64_encode($tag), 'data' => base64_encode($ciphertext)), JSON_UNESCAPED_SLASHES);
        if ($payload === false) { throw new RuntimeException('Could not encode the protected license key.'); }
        return base64_encode($payload);
    }

    public function decrypt(string $stored): ?string {
        $payload = base64_decode($stored, true);
        $payload = $payload === false ? null : json_decode($payload, true);
        if (!is_array($payload) || ($payload['v'] ?? 0) !== 1 || empty($payload['iv']) || empty($payload['tag']) || empty($payload['data'])) {
            return null;
        }
        $iv = base64_decode((string) $payload['iv'], true);
        $tag = base64_decode((string) $payload['tag'], true);
        $ciphertext = base64_decode((string) $payload['data'], true);
        if ($iv === false || $tag === false || $ciphertext === false) { return null; }
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plaintext === false ? null : $plaintext;
    }
}
