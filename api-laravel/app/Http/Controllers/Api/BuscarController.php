<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Autenticar;
use App\Services\HistorialVentas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Buscador general: un cuadro de texto, resultados de varias áreas a la vez.
 *
 * **Cada categoría se consulta solo si el usuario tiene su permiso granular.**
 * Un veterinario restringido a Agenda no puede encontrar clientes por acá
 * aunque no tenga el módulo en el sidebar — si no, el buscador sería la puerta
 * de atrás de todo el sistema de permisos.
 */
class BuscarController extends Controller
{
    private const LIMITE_POR_CATEGORIA = 6;

    public function __construct(private HistorialVentas $hv)
    {
    }

    /** GET /buscar?q= */
    public function buscar(Request $request)
    {
        // `min_length=2`: con una letra, el buscador devolvería media base.
        $datos = $request->validate(['q' => 'required|string|min:2']);
        $q = $datos['q'];
        $like = '%' . $this->escaparLike($q) . '%';

        $permisos = $this->permisosDe($request->attributes->get('usuario'));
        $r = [];

        if (in_array('personas', $permisos, true)) {
            $r['pacientes'] = DB::table('mascotas')
                ->where('nombre', 'like', $like)
                ->orderByRaw($this->claveOrden('nombre'))->orderBy('id')
                ->limit(self::LIMITE_POR_CATEGORIA)->get()
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'titulo' => $m->nombre,
                    'subtitulo' => $m->especie,
                    'ruta' => "/ficha/{$m->id}",
                ])->values();

            $r['tutores'] = DB::table('usuarios')
                ->where('rol', 'dueño')
                ->where(fn ($w) => $w->where('nombre', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('telefono', 'like', $like))
                ->orderByRaw($this->claveOrden('nombre'))->orderBy('id')
                ->limit(self::LIMITE_POR_CATEGORIA)->get()
                ->map(fn ($u) => [
                    'id' => $u->id,
                    'titulo' => $u->nombre,
                    'subtitulo' => $u->telefono ?: $u->email,
                    'ruta' => "/clientes?id={$u->id}",
                ])->values();
        }

        if (in_array('inventario', $permisos, true)) {
            $r['productos'] = DB::table('items_inventario')
                ->where('activo', true)->where('nombre', 'like', $like)
                ->orderByRaw($this->claveOrden('nombre'))->orderBy('id')
                ->limit(self::LIMITE_POR_CATEGORIA)->get()
                ->map(fn ($i) => [
                    'id' => $i->id,
                    'titulo' => $i->nombre,
                    'subtitulo' => $i->tipo,
                    'ruta' => '/inventario',
                ])->values();
        }

        if (in_array('config_consultas', $permisos, true)) {
            $r['prestaciones'] = DB::table('servicios')
                ->where('activo', true)->where('nombre', 'like', $like)
                ->orderByRaw($this->claveOrden('nombre'))->orderBy('id')
                ->limit(self::LIMITE_POR_CATEGORIA)->get()
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'titulo' => $s->nombre,
                    'subtitulo' => 'Consulta',
                    'ruta' => '/consultas',
                ])->values();
        }

        if (in_array('ventas', $permisos, true)) {
            // MISMA condición que el buscador del módulo Ventas: número,
            // documento, cliente por nombre/correo/RUT, paciente, producto o
            // servicio. Compartir el filtro es lo que evita que los dos
            // buscadores encuentren cosas distintas.
            $consulta = $this->hv->filtrarTexto(DB::table('transacciones as t'), $q);
            $filas = $consulta->select('t.*')
                ->orderBy('t.fecha', 'desc')->orderBy('t.id', 'desc')
                ->limit(self::LIMITE_POR_CATEGORIA)->get();

            $r['ventas'] = collect($this->hv->serializar($filas))->map(fn ($v) => [
                'id' => $v['id'],
                'titulo' => $v['numero']
                    ? 'Venta #' . str_pad((string) $v['numero'], 6, '0', STR_PAD_LEFT)
                    : ($v['documento_id'] ?: 'Venta'),
                'subtitulo' => implode(' · ', array_filter([
                    $v['cliente']['nombre'] ?? null,
                    $v['detalle'],
                    $this->fechaCorta($v['fecha']),
                ])),
                // Con el id, el panel abre el detalle de esa venta en vez de
                // dejar al usuario buscarla otra vez dentro del listado.
                'ruta' => "/ventas?venta={$v['id']}",
            ])->values();
        }

        // `(object)` y no el array pelado: PHP serializa un array vacío como
        // `[]` y Python devuelve `{}`. Es la cuarta vez que aparece este caso
        // —slots, tope de anticipación, `citas.respuestas`— y el frontend hace
        // `Object.keys()` sobre esto.
        return (object) $r;
    }

    /**
     * Las áreas a las que este usuario tiene acceso.
     *
     * Un `admin` las tiene todas, auditoría incluida. Un `veterinario` con
     * `permisos` en NULL las tiene todas SALVO auditoría — que es lo que
     * significa ese NULL en el resto del sistema. Cualquier otro rol, ninguna:
     * un dueño con token válido no busca en el panel.
     */
    private function permisosDe($u): array
    {
        $todas = array_keys(Autenticar::AREAS);
        $sinAuditoria = array_values(array_diff($todas, ['auditoria']));

        if ($u->rol === 'admin') {
            return $todas;
        }
        if ($u->rol !== 'veterinario') {
            return [];
        }

        return $u->permisos === null
            ? $sinAuditoria : (json_decode($u->permisos, true) ?: []);
    }

    /**
     * El nombre en minúsculas y **sin puntuación**, para ordenar igual en los
     * dos motores.
     *
     * `LIMIT` sin orden dejaba que la base eligiera «los 6 primeros»; con orden
     * por el nombre CRUDO seguían difiriendo, porque Postgres pone
     * «Consulta + Microchip» antes que «Consulta - Sucursal» y MariaDB al revés
     * (ver `migracion/ESQUEMA.md`). Con más coincidencias que el límite, ese
     * orden decide CUÁLES entran en el corte, no solo cómo se ven: la misma
     * búsqueda devolvía ítems distintos en cada motor.
     *
     * `[^a-z0-9]` deja fuera también los espacios, así que «VacunaKC» y
     * «Vacuna KC» caen juntos — que es lo que una persona espera.
     */
    private function claveOrden(string $columna): string
    {
        return "REGEXP_REPLACE(LOWER({$columna}), '[^a-z0-9]', '')";
    }

    /** '2026-08-20T12:00:00' -> '20-08-2026', el formato que usa el panel. */
    private function fechaCorta(?string $iso): ?string
    {
        if (! $iso) {
            return null;
        }
        [$anio, $mes, $dia] = explode('-', substr($iso, 0, 10));

        return "{$dia}-{$mes}-{$anio}";
    }

    private function escaparLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $s);
    }
}
