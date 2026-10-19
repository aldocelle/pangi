<?php

namespace App\Services;

/**
 * Fernet, compatible con `cryptography.fernet` de Python.
 *
 * **No es una elección de diseño: es un requisito de compatibilidad.** Los
 * tokens de Google ya guardados en la base están cifrados con Fernet por el
 * backend de Python, y este backend tiene que poder leerlos con la MISMA
 * `TOKEN_ENCRYPTION_KEY` — si no, al cortar habría que reconectar la cuenta de
 * Google a mano.
 *
 * El formato es fijo (spec de Fernet v1):
 *
 *     0x80 | timestamp (8 bytes, big-endian) | IV (16) | ciphertext | HMAC (32)
 *
 * todo junto en base64url. La clave son 32 bytes en base64url: los 16 primeros
 * para el HMAC y los 16 últimos para AES-128-CBC.
 */
class Fernet
{
    private string $claveFirma;

    private string $claveCifrado;

    private bool $disponible;

    public function __construct(?string $claveBase64 = null)
    {
        $clave = $claveBase64 ?? (string) env('TOKEN_ENCRYPTION_KEY', '');
        $bruta = $clave === '' ? '' : (string) base64_decode(strtr($clave, '-_', '+/'), true);

        // Sin clave —solo development— se guarda en texto plano, igual que
        // Python. `config.py` lanza una excepción fuera de development, así que
        // este caso no puede llegar a producción.
        $this->disponible = strlen($bruta) === 32;
        $this->claveFirma = $this->disponible ? substr($bruta, 0, 16) : '';
        $this->claveCifrado = $this->disponible ? substr($bruta, 16, 16) : '';
    }

    public function disponible(): bool
    {
        return $this->disponible;
    }

    public function cifrar(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        if (! $this->disponible) {
            return $valor;
        }

        $iv = random_bytes(16);
        $cifrado = openssl_encrypt($valor, 'aes-128-cbc', $this->claveCifrado,
            OPENSSL_RAW_DATA, $iv);
        // El timestamp va en 8 bytes big-endian. `pack('J')` no existe en PHP
        // de 32 bits, así que se arma con dos enteros de 32.
        $ts = pack('N2', 0, time());
        $cuerpo = "\x80" . $ts . $iv . $cifrado;
        $hmac = hash_hmac('sha256', $cuerpo, $this->claveFirma, true);

        return $this->b64url($cuerpo . $hmac);
    }

    /**
     * Descifra. **Si el valor no es un Fernet válido lo devuelve tal cual**, no
     * lanza: en la base puede haber tokens guardados antes de que existiera el
     * cifrado, o con otra clave. Es exactamente lo que hace el `except
     * InvalidToken` de Python — y romper ahí dejaría la sincronización de
     * Google caída en vez de degradada.
     */
    public function descifrar(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        if (! $this->disponible) {
            return $valor;
        }

        $bruto = base64_decode(strtr($valor, '-_', '+/'), true);
        if ($bruto === false || strlen($bruto) < 57 || $bruto[0] !== "\x80") {
            return $valor;
        }

        $cuerpo = substr($bruto, 0, -32);
        $hmac = substr($bruto, -32);
        // `hash_equals`: comparación en tiempo constante, como el HMAC de Fernet.
        if (! hash_equals(hash_hmac('sha256', $cuerpo, $this->claveFirma, true), $hmac)) {
            return $valor;
        }

        $iv = substr($cuerpo, 9, 16);
        $cifrado = substr($cuerpo, 25);
        $claro = openssl_decrypt($cifrado, 'aes-128-cbc', $this->claveCifrado,
            OPENSSL_RAW_DATA, $iv);

        return $claro === false ? $valor : $claro;
    }

    private function b64url(string $s): string
    {
        return strtr(base64_encode($s), '+/', '-_');
    }
}
