import { onMounted, onUnmounted } from 'vue';

/* Marca con .is-visible cualquier [data-reveal] dentro de `root` cuando
   entra en viewport. Con stagger opcional vía --reveal-delay por índice.
   Sin JS o sin soporte de IntersectionObserver, el CSS base ya deja todo
   visible (ver [data-reveal] en app.css) — nunca oculta contenido.

   Usa un MutationObserver además del IntersectionObserver: varias vistas
   cargan su contenido de forma async (fetch al backend, v-else tras
   "cargando"), así que los [data-reveal] no existen todavía en el montaje
   inicial. Sin esto, esos elementos quedan en opacity:0 para siempre. */
export function useReveal(root) {
  let io;
  let mo;
  const vistos = new WeakSet();

  function engancharNuevos(scope) {
    const items = scope.querySelectorAll ? scope.querySelectorAll('[data-reveal]') : [];
    items.forEach((item) => {
      if (vistos.has(item)) return;
      vistos.add(item);
      /* El escalonado se cuenta DENTRO de la lista, no sobre toda la página.
         Con un contador global el tope de 420ms se alcanzaba a los seis
         elementos, así que de ahí hacia abajo todo compartía el mismo
         retardo y nada volvía a escalonar: las listas bajo el pliegue
         aparecían de golpe. El escalonado pertenece a la lista. */
      const hermanos = item.parentElement
        ? [...item.parentElement.children].filter((h) => h.hasAttribute('data-reveal'))
        : [];
      const idx = Math.max(hermanos.indexOf(item), 0);
      item.style.setProperty('--reveal-delay', `${Math.min(idx * 70, 420)}ms`);
      io.observe(item);
      // Red de seguridad: si por lo que sea el observer nunca dispara para
      // este elemento (scroll instantáneo al cargar, navegador atípico),
      // se revela solo tras un tiempo — nunca debe quedar en blanco.
      setTimeout(() => item.classList.add('is-visible'), 1200);
    });
  }

  onMounted(() => {
    const el = root?.value ?? document;
    if (!el.querySelectorAll || typeof IntersectionObserver === 'undefined') return;

    io = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) {
            entry.target.classList.add('is-visible');
            io.unobserve(entry.target);
          }
        }
      },
      // rootMargin positivo: dispara ANTES de que el elemento sea visible
      // (300px de anticipación), no cuando ya está en pantalla. Con scroll
      // rápido (rueda, trackpad con inercia) el usuario podía llegar a ver
      // la sección ya centrada mientras el observer recién procesaba el
      // evento — quedaba en blanco un instante. threshold: 0 porque alcanza
      // con tocar ese borde extendido, no con un % del elemento visible.
      { threshold: 0, rootMargin: '0px 0px 300px 0px' }
    );

    engancharNuevos(el);

    if (typeof MutationObserver !== 'undefined') {
      mo = new MutationObserver(() => engancharNuevos(el));
      mo.observe(el, { childList: true, subtree: true });
    }
  });

  onUnmounted(() => {
    io?.disconnect();
    mo?.disconnect();
  });
}
