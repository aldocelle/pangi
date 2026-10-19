/* Lector mínimo de .zip, solo para el importador de ACVet.

   Sin librería a propósito: el panel no tiene dependencias para esto y el
   navegador ya trae el descompresor (DecompressionStream). Lee el directorio
   central, así que sirve también con zips que traen «data descriptor» (los que
   arma macOS). Soporta método 0 (guardado) y 8 (deflate), que es todo lo que
   produce Finder o `zip`. Sin cifrado ni zip64: un respaldo de CSV no los usa. */

const dec = new TextDecoder('utf-8');

async function inflar(bytes) {
  const flujo = new Blob([bytes]).stream().pipeThrough(new DecompressionStream('deflate-raw'));
  return new Uint8Array(await new Response(flujo).arrayBuffer());
}

/** @returns {Promise<{nombre: string, texto: () => Promise<string>}[]>} */
export async function leerZip(buffer) {
  const v = new DataView(buffer);
  const u8 = new Uint8Array(buffer);

  // Fin del directorio central: se busca desde el final (puede llevar comentario).
  let eocd = -1;
  for (let i = buffer.byteLength - 22; i >= Math.max(0, buffer.byteLength - 22 - 65535); i--) {
    if (v.getUint32(i, true) === 0x06054b50) { eocd = i; break; }
  }
  if (eocd < 0) throw new Error('El archivo no es un .zip válido.');
  const total = v.getUint16(eocd + 10, true);
  let p = v.getUint32(eocd + 16, true);

  const out = [];
  for (let n = 0; n < total; n++) {
    if (v.getUint32(p, true) !== 0x02014b50) throw new Error('El .zip está dañado.');
    const flags = v.getUint16(p + 8, true);
    const metodo = v.getUint16(p + 10, true);
    const comprimido = v.getUint32(p + 20, true);
    const largoNombre = v.getUint16(p + 28, true);
    const largoExtra = v.getUint16(p + 30, true);
    const largoComentario = v.getUint16(p + 32, true);
    const cabecera = v.getUint32(p + 42, true);
    const nombre = dec.decode(u8.subarray(p + 46, p + 46 + largoNombre));
    p += 46 + largoNombre + largoExtra + largoComentario;

    if (nombre.endsWith('/')) continue;           // carpeta
    if (flags & 1) throw new Error('El .zip tiene contraseña: súbelo sin cifrar.');

    out.push({
      nombre,
      texto: async () => {
        const inicio = cabecera + 30 + v.getUint16(cabecera + 26, true) + v.getUint16(cabecera + 28, true);
        const datos = u8.subarray(inicio, inicio + comprimido);
        if (metodo === 0) return dec.decode(datos);
        if (metodo === 8) return dec.decode(await inflar(datos));
        throw new Error(`Método de compresión no soportado (${metodo}).`);
      },
    });
  }
  return out;
}

/** «propietarios_respaldo (1).csv» → 'propietarios'. null si no es de los que se importan. */
export function tipoDeArchivo(nombre) {
  const base = nombre.split('/').pop().toLowerCase();
  if (base.startsWith('._') || nombre.includes('__MACOSX') || !base.endsWith('.csv')) return null;
  const mapa = [
    ['propietarios', 'propietarios'], ['mascotas', 'mascotas'], ['inventario', 'inventario'],
    ['casos', 'casos_clinicos'], ['transacciones', 'transacciones'], ['ventas', 'ventas'],
  ];
  const hit = mapa.find(([prefijo]) => base.startsWith(prefijo));
  return hit ? hit[1] : null;
}
