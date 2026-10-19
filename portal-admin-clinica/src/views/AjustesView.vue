<script setup>
import { computed, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth.js';

import AjustesModal from '../components/AjustesModal.vue';
import { SECCIONES_AJUSTES, puedeVerSeccion } from '../secciones-ajustes.js';
import ConsultasView from './ConsultasView.vue';
import EspecialistasView from './EspecialistasView.vue';
import EquipoView from './EquipoView.vue';
import CamposView from './CamposView.vue';
import ProtocolosView from './ProtocolosView.vue';
import AsistenteView from './AsistenteView.vue';
import AuditoriaView from './AuditoriaView.vue';
import AjustesRecordatoriosView from './AjustesRecordatoriosView.vue';
import AjustesVentasView from './AjustesVentasView.vue';
import EstadoSistemaView from './EstadoSistemaView.vue';
import AjustesCorreosView from './AjustesCorreosView.vue';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const VISTAS = {
  consultas: ConsultasView,
  especialistas: EspecialistasView,
  equipo: EquipoView,
  campos: CamposView,
  protocolos: ProtocolosView,
  asistente: AsistenteView,
  recordatorios: AjustesRecordatoriosView,
  ventas: AjustesVentasView,
  estado: EstadoSistemaView,
  correos: AjustesCorreosView,
  auditoria: AuditoriaView,
};

const visibles = computed(() => SECCIONES_AJUSTES.filter((s) => puedeVerSeccion(auth, s)));

/* Un tono por grupo, mismo recurso que el sidebar usa con Operación, Personas
   y Ventas: once enlaces seguidos en gris obligan a leerlos todos para
   ubicarse, y el color deja ver de un vistazo dónde empieza cada tema.
   El color nunca es la única señal — los títulos de grupo siguen ahí. */
const TONOS = {
  'La clínica': 'var(--aj-clinica)',
  'La atención': 'var(--aj-atencion)',
  'El equipo': 'var(--aj-equipo)',
};

/* Agrupadas para que la columna no sea once enlaces seguidos: «la clínica»,
   «la atención» y «el equipo» son los tres temas reales, y un grupo entero
   desaparece si el usuario no puede ver ninguna de sus secciones. */
const grupos = computed(() => {
  const orden = [];
  const porGrupo = new Map();
  for (const s of visibles.value) {
    if (!porGrupo.has(s.grupo)) { porGrupo.set(s.grupo, []); orden.push(s.grupo); }
    porGrupo.get(s.grupo).push(s);
  }
  return orden.map((titulo) => ({
    titulo, items: porGrupo.get(titulo), tono: TONOS[titulo] || 'var(--ink-soft)',
  }));
});

const actual = computed(() =>
  visibles.value.find((s) => s.id === route.params.seccion) || visibles.value[0] || null
);

/* La URL siempre nombra la sección que se está viendo. Sin esto, entrar a
   /ajustes mostraría la primera con la barra de direcciones diciendo otra
   cosa, y recargar o compartir el enlace devolvería a alguien a un lugar
   distinto del que estaba mirando. `replace` para no llenar el historial con
   un paso que la persona no dio. */
watch(actual, (s) => {
  if (s && route.params.seccion !== s.id) {
    router.replace({ name: 'ajustes', params: { seccion: s.id } });
  }
}, { immediate: true });
</script>

<template>
  <div class="page ajustes">
    <div class="head-row">
      <div>
        <h1 class="page-title">Config</h1>
        <p class="page-sub">Todo lo que se configura del sistema, en un solo lugar.</p>
      </div>
    </div>

    <p v-if="!actual" class="empty">
      Tu cuenta no tiene acceso a ninguna sección de Config.
    </p>

    <div v-else class="aj-layout">
      <nav class="aj-menu" aria-label="Secciones de Config">
        <div v-for="g in grupos" :key="g.titulo" class="aj-grupo" :style="{ '--tono': g.tono }">
          <p class="aj-grupo-tit">{{ g.titulo }}</p>
          <RouterLink
            v-for="s in g.items" :key="s.id"
            :to="{ name: 'ajustes', params: { seccion: s.id } }"
            class="aj-item" :class="{ activo: actual.id === s.id }"
          >{{ s.label }}</RouterLink>
        </div>
      </nav>

      <section class="aj-panel">
        <!-- Las cinco que ya vivían en el modal de la tuerca: se monta el
             mismo componente en modo embebido, sin duplicar su lógica. -->
        <AjustesModal v-if="actual.general" embebido :seccion="actual.id" />
        <!-- Las que eran pantallas propias del sidebar, montadas tal cual.
             `key` fuerza el remontaje al cambiar de sección: sin él, pasar de
             Especialistas a Campos reusaría la instancia y dejaría los datos
             de la anterior en pantalla mientras carga la nueva. -->
        <component v-else :is="VISTAS[actual.id]" :key="actual.id" />
      </section>
    </div>
  </div>
</template>

<style scoped>
/* 16rem y no 15: el riel de color y su separación se comen unos 11px, y con
   15rem la etiqueta más larga («Quién atiende cada consulta») se partía en dos
   líneas y rompía el ritmo de la columna. */
.aj-layout { display: grid; grid-template-columns: 16rem minmax(0, 1fr); gap: 1.5rem; align-items: start; }

.aj-menu { display: flex; flex-direction: column; gap: 1.1rem; position: sticky; top: 1rem; }
.aj-grupo {
  display: flex; flex-direction: column; gap: 0.15rem;
  /* Un riel del tono a lo largo del grupo entero: sin él el color viviría
     solo en el título y no se leería como «todo esto es un bloque». */
  /* 75% y no 30%: al 30% el riel daba 1.55:1 contra --bg, o sea no se veía,
     y el trabajo de «esto es un bloque» lo hacían solos los títulos. A 75%
     llega a 3.42:1, que es el mínimo con el que un elemento no textual se
     percibe de verdad. */
  border-left: 2px solid color-mix(in oklab, var(--tono) 75%, transparent);
  padding-left: 0.55rem;
}
.aj-grupo-tit {
  font-size: var(--t-micro); font-weight: 700; letter-spacing: 0.07em; text-transform: uppercase;
  color: var(--tono, var(--ink-soft)); margin: 0 0 0.3rem 0.15rem;
}
.aj-item {
  display: block; padding: 0.5em 0.7em; border-radius: var(--r-md);
  font-size: var(--t-base); color: var(--ink); text-decoration: none;
}
/* Tres estados, tres pesos distintos. Antes hover y activo compartían fondo Y
   color: solo los separaban el grosor de la letra y el riel izquierdo, así que
   pasar el mouse por encima de cualquier sección la hacía parecer la elegida.
   Y sobre esta página casi blanca el hover no se veía: --accent-tint da 1.04
   contra --bg.

   · Reposo  — sin fondo, texto --ink.
   · Hover   — tinte de hover (1.24) y el texto SE QUEDA en --ink, que tiene
               12.2 de contraste: al apuntar, la etiqueta se lee mejor, no
               peor. Es «candidato», no «elegido».
   · Activo  — turquesa sólido con texto blanco, igual que el ítem activo del
               menú principal. No se confunde con nada.

   Se mantiene la regla de antes: el tono del grupo dice «de qué tema es esto»
   y el turquesa dice «acá estás», así que el hover no se tiñe del grupo. */
.aj-item:hover { background: var(--accent-hover); color: var(--ink); }
/* Sin riel propio: `--accent-strong` sobre `--accent` es 1.30:1 y la pastilla
   ya viene redondeada, así que ese borde no se veía. El fondo sólido alcanza. */
.aj-item.activo { background: var(--accent); color: #fff; font-weight: 600; }
.aj-item.activo:hover { background: var(--accent-strong); color: #fff; }

/* La sección montada trae su propio `.page` con título y márgenes propios:
   acá se le quita el padding de página para que no sume al del contenedor. */
.aj-panel { min-width: 0; }
.aj-panel :deep(.page) { padding: 0; }
/* Su h1 sobra: el de arriba ya dice Ajustes y el menú dice en qué sección se
   está. Dos títulos seguidos hacían leer lo mismo dos veces. */
.aj-panel :deep(.page > .head-row > div > .page-title) { display: none; }

@media (max-width: 900px) {
  .aj-layout { grid-template-columns: 1fr; }
  /* Apilado, el menú deja de ser una columna y pasa a ser una fila de
     pastillas: una lista vertical de once enlaces empujaría el contenido
     una pantalla entera hacia abajo. */
  .aj-menu { position: static; flex-direction: row; flex-wrap: wrap; gap: 0.4rem; }
  .aj-grupo { flex-direction: row; flex-wrap: wrap; gap: 0.3rem; }
  /* El título del grupo SE QUEDA, y a ancho completo para que corte la fila:
     así cada grupo empieza en su propia línea y se lee dónde termina uno y
     empieza el otro. Ocultarlo dejaba el tinte del borde como ÚNICA señal de
     agrupación —y da 1.86:1, contra 1.14:1 de un borde gris cualquiera—, así
     que las doce pastillas se leían como un bloque corrido. Es además la
     misma regla que sigue el escritorio: el color nunca es la única señal.
     Cuesta tres líneas de 0.8rem; el bloque corrido costaba encontrar. */
  .aj-grupo-tit { width: 100%; margin: 0.35rem 0 0.1rem; }
  /* Apilado no hay riel ni título de grupo, así que el tono se muda al borde
     de la pastilla: es lo único que queda para agrupar visualmente. */
  .aj-grupo { border-left: 0; padding-left: 0; }
  .aj-item {
    border: 1px solid color-mix(in oklab, var(--tono) 35%, var(--line));
    border-left-width: 1px; padding: 0.35em 0.75em; font-size: var(--t-sm);
  }
  .aj-item.activo { border-color: var(--accent-strong); }
}
</style>
