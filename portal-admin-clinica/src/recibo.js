/* Recibo imprimible de una venta, con la configuración de Ajustes → Inventario
   y ventas → Impresión (paridad con ACVet, captura 5 del dossier).

   Es un COMPROBANTE INTERNO, no una boleta tributaria: la boleta la emite el
   sistema del SII aparte, y el documento que se pidió ahí viaja como
   `documento_id`. Por eso el título dice «Comprobante de venta».

   Una venta en BORRADOR se imprime como «Presupuesto», con la retención
   informativa si la clínica la configuró: es referencia, no cambia el total.

   Se imprime desde una ventana nueva con su propio HTML, no desde el panel:
   así el recibo no hereda el layout ni los estilos de la aplicación, y una
   impresora térmica recibe una tira de 80 mm y no una hoja A4 encogida. */
import { api } from './api.js';
import { CLINICA } from './marca.js';

const IVA = 0.19;

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const clp = (n) => '$' + Math.round(Number(n) || 0).toLocaleString('es-CL');

export async function cargarConfigImpresion() {
  try {
    const { data } = await api.get('/configuracion/impresion');
    return data;
  } catch {
    return { tipo_impresora: 'escritorio', retencion_pct: null, desglose_iva: false, mensaje_recibo: '', auto_imprimir: false,
      margenes_mm: { superior: 21.17, derecho: 10.58, inferior: 18.52, izquierdo: 10.58 } };
  }
}

/**
 * @param {object} t  la venta tal como la devuelve el backend (con `ventas`).
 * @param {object} cfg  la configuración de impresión.
 * @param {object} extra  { cliente, clinica }
 */
/* El backend devuelve la venta con dos formas: la del alta (`ventas`, pagos
   sueltos) y la del historial/detalle (`lineas`, `pagos`, `pendiente`). Se
   lleva todo a la primera para armar el recibo una sola vez. */
function normalizar(t) {
  if (t.ventas) return t;
  const p = t.pagos || {};
  return {
    ...t,
    ventas: (t.lineas || []).map((l) => ({ nombre: l.nombre, cantidad: l.cantidad, precio_venta: l.precio_venta, descuento: l.descuento })),
    pago_efectivo: p.pago_efectivo, pago_debito: p.pago_debito, pago_credito: p.pago_credito,
    pago_transferencia: p.pago_transferencia, pago_otro: p.pago_otro,
    total_pendiente: t.pendiente,
  };
}

export function htmlRecibo(venta, cfg, extra = {}) {
  const t = normalizar(venta);
  const presupuesto = t.estado_venta === 'borrador';
  const lineas = (t.ventas || []).map((v) => {
    const sub = Math.max(0, Number(v.precio_venta) * Number(v.cantidad) - Number(v.descuento || 0));
    return { nombre: v.nombre, cantidad: Number(v.cantidad), precio: Number(v.precio_venta), descuento: Number(v.descuento || 0), sub };
  });
  const bruto = lineas.reduce((n, l) => n + l.sub, 0);
  const canje = Number(t.monto_canje || 0);
  const total = Math.max(0, bruto - canje);
  const pagos = [
    ['Efectivo', t.pago_efectivo], ['Débito', t.pago_debito], ['Crédito', t.pago_credito],
    ['Transferencia', t.pago_transferencia], ['Otro', t.pago_otro],
  ].filter(([, m]) => Number(m) > 0);
  const termica = cfg.tipo_impresora === 'termica';
  const m = cfg.margenes_mm || {};
  const fecha = (t.fecha || t.created_at || '').replace('T', ' ').slice(0, 16);

  const filas = lineas.map((l) => `
    <tr><td>${esc(l.nombre)}${l.cantidad > 1 ? ` <span class="c">× ${l.cantidad}</span>` : ''}${l.descuento ? `<br><span class="c">desc. ${clp(l.descuento)}</span>` : ''}</td>
    <td class="n">${clp(l.sub)}</td></tr>`).join('');

  const iva = cfg.desglose_iva
    ? `<tr><td>Neto</td><td class="n">${clp(total / (1 + IVA))}</td></tr><tr><td>IVA 19%</td><td class="n">${clp(total - total / (1 + IVA))}</td></tr>`
    : '';
  const retencion = presupuesto && cfg.retencion_pct
    ? `<p class="nota">Retención informativa ${esc(cfg.retencion_pct)}%: ${clp(total * cfg.retencion_pct / 100)}. Referencia: no cambia el total a pagar.</p>`
    : '';

  return `<!doctype html><html lang="es"><head><meta charset="utf-8"><title>${presupuesto ? 'Presupuesto' : 'Comprobante'} ${esc(t.numero ?? '')}</title>
<style>
  @page { ${termica ? 'size: 80mm auto; margin: 3mm;' : `margin: ${m.superior}mm ${m.derecho}mm ${m.inferior}mm ${m.izquierdo}mm;`} }
  body { font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; color: #111; margin: 0; ${termica ? 'width: 74mm; font-size: 11px;' : 'font-size: 13px;'} }
  h1 { font-size: ${termica ? '14px' : '18px'}; margin: 0 0 2px; }
  .sub { color: #444; margin: 0 0 8px; }
  table { width: 100%; border-collapse: collapse; }
  td { padding: 3px 0; vertical-align: top; border-bottom: 1px dotted #bbb; }
  .n { text-align: right; white-space: nowrap; padding-left: 8px; }
  .c { color: #555; }
  .tot td { font-weight: 700; border-bottom: 0; padding-top: 6px; }
  .nota { color: #444; margin: 8px 0 0; }
  .pie { margin-top: 12px; text-align: center; white-space: pre-wrap; }
</style></head><body>
  <h1>${esc(extra.clinica || CLINICA)}</h1>
  <p class="sub">${presupuesto ? 'Presupuesto' : 'Comprobante de venta'}${t.numero ? ` N° ${esc(t.numero)}` : ''} · ${esc(fecha)}${t.documento_id ? `<br>Documento: ${esc(t.documento_id)}` : ''}${extra.cliente ? `<br>Cliente: ${esc(extra.cliente)}` : ''}</p>
  <table>
    ${filas}
    ${canje ? `<tr><td>Canje de puntos (${esc(t.puntos_canjeados)})</td><td class="n">−${clp(canje)}</td></tr>` : ''}
    ${iva}
    <tr class="tot"><td>Total</td><td class="n">${clp(total)}</td></tr>
  </table>
  ${pagos.length && !presupuesto ? `<p class="nota">Pagado: ${pagos.map(([n, mm]) => `${n} ${clp(mm)}`).join(' · ')}${Number(t.total_pendiente) > 0 ? ` · Pendiente ${clp(t.total_pendiente)}` : ''}</p>` : ''}
  ${t.puntos_ganados ? `<p class="nota">Puntos ganados en esta compra: ${esc(t.puntos_ganados)}</p>` : ''}
  ${retencion}
  ${cfg.mensaje_recibo ? `<p class="pie">${esc(cfg.mensaje_recibo)}</p>` : ''}
</body></html>`;
}

/** Abre el recibo en una ventana nueva y lanza el diálogo de impresión. */
export function imprimirRecibo(t, cfg, extra = {}) {
  const w = window.open('', '_blank', 'width=420,height=640');
  if (!w) return false;   // bloqueador de ventanas: quien llama avisa
  w.document.open();
  w.document.write(htmlRecibo(t, cfg, extra));
  w.document.close();
  w.focus();
  // Una sola vez: `onload` no siempre llega después de `document.write`, y
  // con los dos caminos sin guarda el diálogo salía dos veces.
  let impreso = false;
  const imprimir = () => { if (!impreso) { impreso = true; w.print(); } };
  w.onload = imprimir;
  setTimeout(imprimir, 400);
  return true;
}
