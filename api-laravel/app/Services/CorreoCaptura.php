<?php

namespace App\Services;

/** El transporte de la vista previa: anota y no envía. */
class CorreoCaptura extends Correo
{
    public array $enviados = [];

    public function __construct()
    {
    }

    public function enviar(string $destinatario, string $asunto,
                           string $cuerpoTexto, ?string $cuerpoHtml = null): bool
    {
        $this->enviados[] = ['asunto' => $asunto, 'texto' => $cuerpoTexto, 'html' => $cuerpoHtml];

        return true;
    }
}
