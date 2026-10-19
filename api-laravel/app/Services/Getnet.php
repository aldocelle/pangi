<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Getnet Web Checkout (Santander): cobra el abono que la clínica exige para
 * dejar firme una hora.
 *
 * **A diferencia de Google Calendar, falla CERRADO**: si no se pudo crear la
 * sesión no hay nada que pagar, y el error tiene que llegar al cliente. Un
 * fallo silencioso acá deja una hora tomada sin cobrar.
 */
class Getnet
{
    private const TIMEOUT = 20;

    public function habilitado(): bool
    {
        return (string) env('GETNET_LOGIN', '') !== ''
            && (string) env('GETNET_SECRET_KEY', '') !== '';
    }

    /**
     * ¿Se está simulando el cobro?
     *
     * Solo cuando NO hay credenciales y estamos en desarrollo, o en una demo
     * publicada que lo pide con `PAGO_SIMULADO=true`. Ojo con leerlo al
     * revés: este campo **no dice en qué ambiente de Getnet se está**. Con
     * credenciales puestas siempre es `false`, apunte `GETNET_BASE_URL` al
     * sandbox o a producción — lo único que dice dónde se cobra de verdad es esa
     * variable.
     */
    public function simulado(): bool
    {
        return ! $this->habilitado()
            && (env('APP_ENV') === 'local' || filter_var(env('PAGO_SIMULADO', false), FILTER_VALIDATE_BOOLEAN));
    }

    /**
     * La autenticación WSSE.
     *
     * `tranKey = Base64(SHA-256(nonce_crudo + seed + secretKey))`, y **al hash
     * entra el nonce CRUDO, no el que viaja en Base64**. Ese es el detalle que
     * rompe la mayoría de las integraciones con Getnet: el nonce se envía
     * codificado, así que es natural (y erróneo) hashear la versión codificada.
     *
     * El `seed` es la hora de la CLÍNICA en ISO-8601 sin microsegundos: forma
     * parte del hash, así que un formato distinto invalida la firma entera.
     */
    public function auth(): array
    {
        $nonceCrudo = bin2hex(random_bytes(16));
        $seed = CarbonImmutable::now('America/Santiago')->format('Y-m-d\TH:i:sP');
        $digest = hash('sha256', $nonceCrudo . $seed . (string) env('GETNET_SECRET_KEY'), true);

        return [
            'login' => (string) env('GETNET_LOGIN'),
            'tranKey' => base64_encode($digest),
            'nonce' => base64_encode($nonceCrudo),
            'seed' => $seed,
        ];
    }

    /**
     * Una petición a Getnet. **Lanza** si algo falla: quien llama tiene que
     * enterarse, no seguir como si el cobro existiera.
     */
    public function post(string $ruta, array $cuerpo = []): array
    {
        if (! $this->habilitado()) {
            throw new \RuntimeException('La pasarela de pago no está configurada.');
        }

        $url = rtrim((string) env('GETNET_BASE_URL'), '/') . '/' . ltrim($ruta, '/');

        try {
            $r = Http::timeout(self::TIMEOUT)
                ->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json'])
                ->post($url, $cuerpo + ['auth' => $this->auth()]);
        } catch (\Throwable $e) {
            Log::error("Getnet inalcanzable ({$ruta}): " . $e->getMessage());
            throw new \RuntimeException('No pudimos conectar con la pasarela de pago.');
        }

        if (! $r->successful()) {
            Log::error("Getnet respondió {$r->status()} en {$ruta}: " . $r->body());
            throw new \RuntimeException('La pasarela de pago rechazó la solicitud.');
        }

        return $r->json() ?? [];
    }

    /**
     * La firma del webhook: `SHA-1(requestId + status + date + secretKey)`.
     *
     * **SHA-1 lo impone el protocolo de Getnet, no es una elección nuestra.**
     * Cambiarlo por SHA-256 haría que ninguna notificación validara.
     */
    public function firmaWebhookValida(array $notificacion): bool
    {
        $firma = $notificacion['signature'] ?? null;
        $requestId = $notificacion['requestId'] ?? null;
        $status = $notificacion['status']['status'] ?? null;
        $date = $notificacion['status']['date'] ?? null;

        // Sin credenciales NINGUNA firma vale: no hay secreto con el que
        // verificarla, y aceptar el aviso sería creerle a cualquiera que postee
        // «pago aprobado» al webhook.
        if (! $this->habilitado()) {
            return false;
        }
        if (! $firma || $requestId === null || ! $status || ! $date) {
            return false;
        }

        $esperada = sha1((string) $requestId . $status . $date . (string) env('GETNET_SECRET_KEY'));

        return hash_equals($esperada, (string) $firma);
    }

    /**
     * Caracteres que Getnet acepta en `description`, y los que hay que
     * traducir antes de mandarlos.
     *
     * Verificado contra el sandbox: la comilla doble devuelve **400**, y « · »
     * y « — » también. Las tildes y la ñ SÍ pasan (comprobado con «Ecografía
     * 30% dcto.»), así que no se transliteran: quitarlas dejaría «Peluquera» y
     * «Dermatologa» en el detalle del cobro que ve el cliente.
     *
     * El resto de los signos raros (: ; # + & ! ? * _ @ comillas) quedan fuera
     * sin haberlos probado uno a uno: el sandbox empezó a responder 429 a
     * mitad del barrido. Se prefiere perder un signo a arriesgar un 400.
     */
    private const EQUIVALENTES = [
        '—' => '-', '–' => '-', '‑' => '-', '·' => '-', '•' => '-',
        '“' => '"', '”' => '"', '„' => '"', '‘' => "'", '’' => "'",
        '…' => '...', ' ' => ' ', '​' => '',
    ];

    private const SIGNOS_OK = " .,-()/%";

    /**
     * Deja `description` en algo que Getnet acepte, sin perder legibilidad.
     *
     * Hace falta porque ese texto se arma con lo que una persona escribió en
     * el panel —el nombre de la consulta— y con el nombre de la mascota. Un
     * nombre de consulta con raya larga tumbaría **todos** los cobros de esa
     * consulta, con un 400 que no dice cuál fue el carácter.
     *
     * Nunca devuelve vacío: la API exige mínimo 2 caracteres, así que si el
     * saneado se lleva todo (un nombre escrito solo con emojis) cae a un texto
     * genérico en vez de hacer fallar el cobro.
     */
    public function limpiarDescripcion(?string $texto, int $tope = 120): string
    {
        $texto = strtr((string) $texto, self::EQUIVALENTES);

        $limpio = '';
        foreach (preg_split('//u', $texto, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $c) {
            // `\p{L}` y `\p{N}` y no `ctype_alnum`: hay que dejar pasar las
            // tildes y la ñ, que son multibyte.
            if (preg_match('/^[\p{L}\p{N}]$/u', $c) || str_contains(self::SIGNOS_OK, $c)) {
                $limpio .= $c;
            }
        }

        // Espacios repetidos: quedan al caer caracteres intermedios.
        $limpio = trim(preg_replace('/\s+/u', ' ', $limpio) ?? '');
        $limpio = trim(mb_substr($limpio, 0, $tope), " -");

        return mb_strlen($limpio) >= 2 ? $limpio : 'Pago ' . Marca::nombre();
    }
}
