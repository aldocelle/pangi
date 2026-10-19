<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Los tokens que viajan por correo: confirmación de registro y reset de clave.
 *
 * **Se guardan solo como SHA-256.** Si alguien lee la tabla `usuarios` no puede
 * confirmar cuentas ajenas ni cambiar contraseñas. No hace falta HMAC con la
 * clave secreta: el token ya es aleatorio de 256 bits, y el hash acá solo
 * protege el valor en reposo.
 *
 * A diferencia del resto de los correos del sistema, el de confirmación **NO
 * falla en silencio**: si no sale, la cuenta queda inservible porque nadie
 * podría confirmarla. Por eso `enviarConfirmacion` devuelve el resultado y el
 * endpoint responde 502.
 */
class TokensCorreo
{
    /** La confirmación de registro vive 24 h. */
    public const VALIDEZ_HORAS = 24;

    /**
     * El reset vive **1 hora, no 24**. Es más corto a propósito: ese enlace da
     * acceso directo a la cuenta, mientras que el de confirmación solo activa
     * una clave que su dueño ya eligió.
     */
    public const VALIDEZ_RESET_HORAS = 1;

    public function __construct(private Correo $correo)
    {
    }

    public function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Un token en claro, aleatorio. Es la única vez que existe fuera del correo. */
    public function generar(): string
    {
        // `random_bytes` y base64url, equivalente a `secrets.token_urlsafe(32)`.
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Deja la cuenta a la espera de confirmación. Devuelve el token en claro.
     *
     * La contraseña va a `password_hash_pendiente` y **no** a `password_hash`:
     * hasta que no se confirme el correo, esa clave no sirve para entrar. Es lo
     * que impide que registrarse con el correo de un cliente dé acceso a su
     * ficha.
     */
    public function prepararConfirmacion(string $usuarioId, string $passwordHash): string
    {
        $token = $this->generar();
        DB::table('usuarios')->where('id', $usuarioId)->update([
            'password_hash_pendiente' => $passwordHash,
            'token_confirmacion' => $this->hashToken($token),
            'token_confirmacion_expira' => CarbonImmutable::now('UTC')
                ->addHours(self::VALIDEZ_HORAS)->format('Y-m-d H:i:s.u'),
        ]);

        return $token;
    }

    /** Promueve la contraseña pendiente y da el correo por probado. */
    public function confirmar($usuario): void
    {
        $set = [
            'email_verificado' => true,
            'password_hash_pendiente' => null,
            'token_confirmacion' => null,
            'token_confirmacion_expira' => null,
        ];
        if ($usuario->password_hash_pendiente) {
            $set['password_hash'] = $usuario->password_hash_pendiente;
        }
        DB::table('usuarios')->where('id', $usuario->id)->update($set);
    }

    /**
     * ¿El enlace del correo sigue vivo?
     *
     * El `'UTC'` del `parse` NO es decorativo. `token_confirmacion_expira` y
     * `token_reset_expira` son marcas TÉCNICAS y se guardan en UTC —las escribe
     * `prepararReset()` con `now('UTC')`—, pero `APP_TIMEZONE` de esta
     * aplicación es `America/Santiago`: sin la zona explícita, Carbon lee
     * «14:00» como las 14:00 de Chile y la compara contra las 14:00 UTC, o sea
     * **cuatro horas de más**.
     *
     * El efecto era que un enlace vencido seguía funcionando cuatro horas más.
     * En el de confirmación es feo; en el de restablecer contraseña es una
     * credencial que dura cinco veces lo que dice (la ventana son 60 minutos).
     * Lo encontró `modo_b_registro`, que siembra un token vencido a propósito:
     * Python contestaba 400 y Laravel entregaba sesión.
     *
     * Es la misma trampa que el §9.6 del CLAUDE.md describe para Python —
     * comparar una marca UTC contra un «ahora» local—, con los papeles
     * cambiados: acá lo local es el intérprete, no la columna.
     */
    public function vigente(?string $expira): bool
    {
        return $expira !== null
            && CarbonImmutable::parse($expira, 'UTC')->gt(CarbonImmutable::now('UTC'));
    }

    public function prepararReset(string $usuarioId): string
    {
        $token = $this->generar();
        DB::table('usuarios')->where('id', $usuarioId)->update([
            'token_reset' => $this->hashToken($token),
            'token_reset_expira' => CarbonImmutable::now('UTC')
                ->addHours(self::VALIDEZ_RESET_HORAS)->format('Y-m-d H:i:s.u'),
        ]);

        return $token;
    }

    public function limpiarReset(string $usuarioId): void
    {
        DB::table('usuarios')->where('id', $usuarioId)
            ->update(['token_reset' => null, 'token_reset_expira' => null]);
    }

    /**
     * El correo de confirmación. **Devuelve si salió**, y el endpoint responde
     * 502 cuando no: una cuenta sin confirmar es una cuenta muerta.
     *
     * `esReclamo` cambia el texto para el cliente que ya estaba en la ficha de
     * la clínica (importado de ACVet) y está activando su acceso por primera
     * vez: sin eso, el correo parecería decirle que le crearon una cuenta sin
     * avisarle.
     */
    public function enviarConfirmacion($usuario, string $token, bool $esReclamo = false): bool
    {
        $enlace = config('emr.portal_reservas_url') . '/confirmar?token=' . rawurlencode($token);
        $nombre = explode(' ', (string) ($usuario->nombre ?? ''))[0] ?: 'hola';

        if ($esReclamo) {
            $intro = 'Ya teníamos tus datos en la ficha de la clínica. Para activar tu acceso '
                . 'al portal y ver la información de tus mascotas, confirma que este correo es tuyo:';
            $asunto = 'Activa tu acceso al portal — ' . Marca::nombre();
            $introHtml = 'Ya teníamos tus datos en la ficha de la clínica. Para activar tu acceso al portal '
                . 'y ver la información de tus mascotas, confirma que este correo es tuyo:';
        } else {
            $intro = $introHtml = 'Gracias por crear tu cuenta en el portal de ' . Marca::nombre() . '. '
                . 'Solo falta confirmar que este correo es tuyo:';
            $asunto = 'Confirma tu correo — ' . Marca::nombre();
        }

        $h = self::VALIDEZ_HORAS;
        $texto = "Hola {$nombre},\n\n{$intro}\n\n{$enlace}\n\n"
            . "El enlace vence en {$h} horas.\n"
            . "Si no fuiste tú quien pidió esto, ignora este correo: sin este clic no se "
            . "activa ningún acceso a la cuenta.\n\n"
            . Marca::nombre();

        $e = fn ($v) => Plantilla::e($v);
        $html = Plantilla::pagina(
            'Confirma tu correo',
            'Hola ' . $e($nombre),
            Plantilla::parrafo($e($introHtml))
            . Plantilla::boton($e($enlace), 'Confirmar mi correo')
            . Plantilla::enlaceAlterno($enlace, 'O copia este enlace:'),
            "El enlace vence en {$h} horas. Si no fuiste tú quien pidió esto, ignora este correo: "
            . 'sin este clic no se activa ningún acceso a la cuenta.'
        );

        return $this->correo->enviar($usuario->email, $asunto, $texto, $html);
    }

    /**
     * El correo de reset. **Falla en silencio a propósito**: si no sale, el
     * endpoint deja una `SolicitudReset` para que alguien del equipo la
     * resuelva a mano, en vez de decirle a quien pidió el reset que algo se
     * rompió — eso también revelaría que el correo existe.
     */
    public function enviarReset($usuario, string $token): bool
    {
        $enlace = config('emr.portal_reservas_url') . '/restablecer?token=' . rawurlencode($token);
        $nombre = explode(' ', (string) ($usuario->nombre ?? ''))[0] ?: 'hola';
        $h = self::VALIDEZ_RESET_HORAS;

        $texto = "Hola {$nombre},\n\n"
            . 'Recibimos una solicitud para restablecer tu contraseña en el portal de '
            . Marca::nombre() . ". Si fuiste tú, elige una nueva clave aquí:\n\n"
            . "{$enlace}\n\n"
            . "El enlace vence en {$h} hora.\n"
            . "Si no fuiste tú, ignora este correo: tu contraseña actual sigue funcionando.\n\n"
            . Marca::nombre();

        $e = fn ($v) => Plantilla::e($v);
        $html = Plantilla::pagina(
            'Restablece tu contraseña',
            'Hola ' . $e($nombre),
            Plantilla::parrafo('Recibimos una solicitud para restablecer tu contraseña en el portal de '
                . Marca::nombre() . '. Si fuiste tú, elige una nueva clave aquí:')
            . Plantilla::boton($e($enlace), 'Elegir nueva contraseña')
            . Plantilla::enlaceAlterno($enlace, 'O copia este enlace:'),
            "El enlace vence en {$h} hora. "
            . 'Si no fuiste tú, ignora este correo: tu contraseña actual sigue funcionando.'
        );

        return $this->correo->enviar($usuario->email, 'Restablece tu contraseña — ' . Marca::nombre(),
            $texto, $html);
    }

    /**
     * «Ya tienes una cuenta»: alguien intentó registrarse con el correo de una
     * cuenta ACTIVA. Solo se le avisa a la cuenta real (ver AuthController::
     * register). Vivía dentro del controlador; salió acá para que la vista
     * previa de Ajustes → Correos muestre exactamente lo que se envía.
     */
    public function enviarCuentaExistente(object $usuario): bool
    {
        $primer = explode(' ', (string) ($usuario->nombre ?? ''))[0] ?? '';
        $enlaceLogin = rtrim((string) config('emr.portal_reservas_url'), '/') . '/login';

        return $this->correo->enviar(
            $usuario->email,
            'Ya tienes una cuenta — ' . Marca::nombre(),
            "Hola {$primer},\n\n"
            . 'Alguien intentó crear una cuenta en el portal de ' . Marca::nombre() . ' '
            . "con este correo, pero ya tienes una. Si fuiste tú, inicia sesión o usa "
            . "'¿Olvidaste tu contraseña?' desde el portal:\n{$enlaceLogin}\n\n"
            . 'Si no fuiste tú, ignora este correo: '
            . 'tu cuenta sigue igual, no se hizo ningún cambio.',
            Plantilla::pagina(
                'Ya tienes una cuenta',
                'Hola ' . Plantilla::e($primer),
                Plantilla::parrafo('Alguien intentó crear una cuenta en el portal de '
                    . Marca::nombre() . ' con este correo, pero ya tienes una. Si fuiste tú, inicia sesión '
                    . 'o usa «¿Olvidaste tu contraseña?» desde el portal:')
                . Plantilla::boton(Plantilla::e($enlaceLogin), 'Iniciar sesión'),
                'Si no fuiste tú, ignora este correo: tu cuenta sigue igual, no se hizo ningún cambio.'
            )
        );
    }
}
