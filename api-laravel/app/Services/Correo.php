<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

/**
 * Envío de correo. Port de `email_service.enviar`.
 *
 * **Nunca lanza.** Devuelve `false` y loggea si el envío falla: crear una cita
 * no puede fallar porque el correo no salió. El llamador trata cualquier
 * `false` como «no se pudo avisar, la operación principal igual quedó
 * guardada».
 *
 * El transporte real (Gmail API → Resend → SMTP, en cascada) queda pendiente
 * de la Fase 5, cuando haya credenciales en el Hostinger. Lo que se porta acá
 * y sí es contrato desde ya es **la decisión**: a quién se le escribe, con qué
 * asunto y con qué cuerpo — que es lo que compara el modo C del arnés.
 */
class Correo
{
    /** Seis segundos contra Google, igual que el resto de esa integración. */
    private const TIMEOUT = 6;

    /**
     * Dominios que por definición no reciben correo.
     *
     * `test.cl` son las cuentas de prueba del sistema; el resto los reserva la
     * RFC 2606/6761 para que nunca se resuelvan.
     *
     * `sin-correo.local` NO es de prueba: es el correo inventado por el
     * importador de ACVet (`legado-<id>@sin-correo.local`, ~1.047 usuarios)
     * para el cliente migrado que no dejó uno real, porque la columna es NOT
     * NULL y única. Sin este filtro, cada confirmación le rebota a una
     * dirección que no existe — y Gmail acepta el envío con 200, así que el
     * rechazo ni siquiera se ve en los logs.
     */
    private const DOMINIOS_DE_PRUEBA = ['test.cl', 'example.com', 'example.org', 'example.net'];

    private const DOMINIOS_INVENTADOS = ['sin-correo.local'];

    private const TLD_RESERVADOS = ['.test', '.example', '.invalid', '.localhost', '.local'];

    public function esDireccionDePrueba(?string $destinatario): bool
    {
        $partes = explode('@', (string) $destinatario);
        $dominio = mb_strtolower(trim(end($partes)));

        if (in_array($dominio, self::DOMINIOS_DE_PRUEBA, true)
            || in_array($dominio, self::DOMINIOS_INVENTADOS, true)) {
            return true;
        }
        foreach (self::TLD_RESERVADOS as $tld) {
            if (str_ends_with($dominio, $tld)) {
                return true;
            }
        }

        return false;
    }

    public function enviar(string $destinatario, string $asunto,
                           string $cuerpoTexto, ?string $cuerpoHtml = null): bool
    {
        try {
            // Antes que nada, y antes de elegir transporte: a una dirección de
            // prueba no se le envía por ninguna vía.
            //
            // Devuelve TRUE y no false a propósito, aunque no se haya enviado
            // nada. `false` significa «falló, reintenta», y el ciclo de
            // recordatorios lo toma al pie de la letra: una cita de prueba se
            // reintentaría cada 60 segundos para siempre sin que ningún
            // reintento pueda funcionar. Acá no hay nada que reintentar — la
            // dirección no existe y no va a existir.
            if ($this->esDireccionDePrueba($destinatario)) {
                Log::info("Correo omitido, dirección de prueba: {$destinatario} ({$asunto})");
                return true;
            }

            // Modo C del arnés: se anota la DECISIÓN (a quién, con qué asunto
            // y con qué cuerpo), no el diálogo con el servidor de correo. Un
            // port correcto puede usar otra librería de envío.
            if (RegistroEfectos::activo()) {
                RegistroEfectos::anotar('correo', [
                    'para' => $destinatario,
                    'asunto' => $asunto,
                    // El cuerpo entero haría el diff ilegible; el hash detecta
                    // cualquier cambio sin volcar 4 KB por correo.
                    'texto_sha' => substr(hash('sha256', $cuerpoTexto), 0, 16),
                    'texto_muestra' => mb_substr($cuerpoTexto, 0, 220),
                ]);
                return true;
            }

            return $this->despachar($destinatario, $asunto, $cuerpoTexto, $cuerpoHtml);
        } catch (\Throwable $e) {
            // Red de seguridad: asunto y cuerpo pueden venir de un formulario
            // público, y una entrada rara ahí no puede tumbar la tarea entera.
            Log::error("Fallo inesperado enviando correo a {$destinatario}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * El transporte, en cascada de tres. **El orden importa.**
     *
     *   1. **La API de Gmail** de la cuenta conectada. Va por HTTPS, no por
     *      SMTP, así que funciona donde el puerto 587 está bloqueado — es la
     *      única vía que atravesaba el bloqueo de Railway, y se conserva
     *      primero porque es la que está probada en producción.
     *   2. **Resend**, si hay API key.
     *   3. **SMTP directo.**
     *
     * Lee `SMTP_*` y `RESEND_API_KEY`, los MISMOS nombres que usa Python y no
     * los `MAIL_*` de Laravel: durante la convivencia de los dos backends el
     * `.env` es uno solo, y dos juegos de nombres para lo mismo terminan con
     * uno configurado y el otro no.
     *
     * Nunca lanza. Cada transporte devuelve false y deja el motivo en el log.
     */
    protected function despachar(string $destinatario, string $asunto,
                                 string $cuerpoTexto, ?string $cuerpoHtml): bool
    {
        // Solo un ÉXITO de Gmail corta la cascada. Antes cortaba también su
        // fallo, y con eso un token sin `gmail.send` dejaba mudo al sistema
        // entero aunque hubiera Resend o SMTP configurados detrás: el correo se
        // perdía con un solo intento. Si no hay más transportes, el `false`
        // sale igual por la rama de «no configurado».
        $porGmail = $this->porGmail($destinatario, $asunto, $cuerpoTexto, $cuerpoHtml);
        if ($porGmail === true) {
            return true;
        }

        $resend = (string) env('RESEND_API_KEY', '');
        $host = (string) env('SMTP_HOST', '');
        $user = (string) env('SMTP_USER', '');
        $clave = (string) env('SMTP_PASSWORD', '');

        if (! $resend && ! ($host && $user && $clave)) {
            Log::warning(($porGmail === false
                    ? "Gmail falló y no hay otro transporte configurado"
                    : 'Envío de correo no configurado')
                . ": {$destinatario} no recibió ({$asunto})");
            return false;
        }

        $remitente = (string) env('SMTP_FROM', Marca::nombre() . ' <noreply@clinica.test>');

        if ($resend) {
            return $this->porResend($resend, $remitente, $destinatario, $asunto,
                $cuerpoTexto, $cuerpoHtml);
        }

        return $this->porSmtp($host, $user, $clave, $remitente, $destinatario,
            $asunto, $cuerpoTexto, $cuerpoHtml);
    }

    /**
     * La cuenta de Google conectada.
     *
     * Devuelve `true`/`false` si había cuenta y se intentó, o **`null` si no
     * hay ninguna** — para que el llamador pase al siguiente transporte. Los
     * tres valores son distintos y confundir `null` con `false` haría que un
     * sistema sin Google conectado nunca llegue a SMTP.
     *
     * La cuenta de la clínica es la que apunta `ajustes.google_account_id`, no
     * «la primera que haya»: al reconectar con otra cuenta la anterior queda
     * como fila huérfana, y con `first()` los correos seguirían saliendo desde
     * la cuenta vieja mientras el calendario ya usa la nueva, sin que nada lo
     * delate.
     */
    private function porGmail(string $destinatario, string $asunto,
                              string $texto, ?string $html): ?bool
    {
        try {
            $ajustes = DB::table('ajustes')->first();
            $cuenta = ($ajustes->google_account_id ?? null)
                ? DB::table('google_accounts')->where('id', $ajustes->google_account_id)->first()
                : null;
            // Sin ajustes todavía (instalación nueva), vale cualquiera que haya.
            $cuenta = $cuenta ?: DB::table('google_accounts')->first();
            if (! $cuenta) {
                return null;
            }

            $t = app(GoogleOAuth::class)->token($cuenta);
            if (! $t) {
                Log::error("Gmail: no se pudo obtener un token para {$cuenta->email}");
                return false;
            }

            // `raw` en Base64 **URL-safe** y sin relleno posicional: es lo que
            // exige la API. Un Base64 normal la hace responder 400.
            $crudo = rtrim(strtr(base64_encode(
                $this->mime($cuenta->email, $destinatario, $asunto, $texto, $html)
            ), '+/', '-_'), '=');

            $r = Http::withToken($t)->timeout(self::TIMEOUT)
                ->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send',
                    ['raw' => $crudo]);

            if (! $r->successful()) {
                // Un 403 con «insufficient authentication scopes» significa que
                // hay que reconectar la cuenta para conceder `gmail.send`.
                Log::error("Gmail rechazó el envío a {$destinatario} ({$r->status()}): "
                    . mb_substr($r->body(), 0, 300));
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            Log::error("Gmail: fallo inesperado enviando a {$destinatario}: " . $e->getMessage());
            return false;
        }
    }

    /** El mensaje MIME crudo que la API de Gmail espera. */
    private function mime(string $de, string $para, string $asunto,
                          string $texto, ?string $html): string
    {
        $email = (new Email())->from($de)->to($para)->subject($asunto)->text($texto);
        if ($html) {
            $email->html($html);
        }
        return $email->toString();
    }

    private function porResend(string $clave, string $remitente, string $destinatario,
                               string $asunto, string $texto, ?string $html): bool
    {
        $cuerpo = ['from' => $remitente, 'to' => [$destinatario],
                   'subject' => $asunto, 'text' => $texto];
        if ($html) {
            $cuerpo['html'] = $html;
        }
        try {
            $r = Http::withToken($clave)->timeout(15)
                ->post('https://api.resend.com/emails', $cuerpo);
            if (! $r->successful()) {
                // El cuerpo trae el motivo (dominio sin verificar, remitente no
                // autorizado); sin él no hay forma de diagnosticar.
                Log::error("Resend rechazó el correo a {$destinatario} ({$r->status()}): "
                    . mb_substr($r->body(), 0, 300));
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            Log::error("Error llamando a Resend para {$destinatario}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * SMTP con STARTTLS, como `smtplib` con `starttls()`.
     *
     * Se arma el transporte a mano en vez de usar el mailer de Laravel porque
     * la configuración vive en `SMTP_*` y no en `MAIL_*` (ver la nota de
     * `despachar`), y porque así el timeout es explícito: sin él, un puerto
     * bloqueado deja la petición colgada — que es exactamente lo que pasó en
     * Railway y por lo que el aviso de contacto se manda fuera de la respuesta.
     */
    private function porSmtp(string $host, string $user, string $clave, string $remitente,
                             string $destinatario, string $asunto,
                             string $texto, ?string $html): bool
    {
        try {
            $puerto = (int) (env('SMTP_PORT') ?: 587);
            $transporte = new EsmtpTransport($host, $puerto, false);
            $transporte->setUsername($user);
            $transporte->setPassword($clave);
            $transporte->getStream()->setTimeout(10);

            $email = (new Email())->from($remitente)->to($destinatario)
                ->subject($asunto)->text($texto);
            if ($html) {
                $email->html($html);
            }
            (new Mailer($transporte))->send($email);

            return true;
        } catch (\Throwable $e) {
            Log::error("Error enviando correo a {$destinatario}: " . $e->getMessage());
            return false;
        }
    }
}
