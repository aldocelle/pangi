<script setup>
defineProps({
  name: { type: String, required: true },
  size: { type: [Number, String], default: 20 },
});

// Paths de íconos (stroke, 24x24). Estilo lineal como los mockups.
const P = {
  home: 'M3 10.5 12 3l9 7.5M5 9v11h5v-6h4v6h5V9',
  pets: 'M4.5 12.5a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm5-4a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm5 0a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm5 4a2 2 0 1 0 0-4 2 2 0 0 0 0 4ZM12 13c-2.5 0-5 2-5 4.5C7 19.5 9 21 12 21s5-1.5 5-3.5c0-2.5-2.5-4.5-5-4.5Z',
  calendar: 'M8 3v3m8-3v3M4 8h16M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z',
  bell: 'M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0',
  chat: 'M21 11.5a8.4 8.4 0 0 1-9 8.4 8.9 8.9 0 0 1-4-1L3 20l1.1-4A8.4 8.4 0 0 1 12 3a8.4 8.4 0 0 1 9 8.5Z',
  folder: 'M4 5h5l2 2h9a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z',
  card: 'M3 6h18a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Zm-1 4h20M6 15h4',
  gear: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm7.4-3a7.4 7.4 0 0 0-.1-1.3l2-1.6-2-3.4-2.4 1a7.3 7.3 0 0 0-2.2-1.3L14.3 2h-4l-.4 2.6a7.3 7.3 0 0 0-2.2 1.3l-2.4-1-2 3.4 2 1.6a7.4 7.4 0 0 0 0 2.6l-2 1.6 2 3.4 2.4-1a7.3 7.3 0 0 0 2.2 1.3l.4 2.6h4l.4-2.6a7.3 7.3 0 0 0 2.2-1.3l2.4 1 2-3.4-2-1.6c.06-.43.1-.86.1-1.3Z',
  logout: 'M15 12H3m0 0 4-4m-4 4 4 4M11 3h7a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1h-7',
  chevron: 'm9 6 6 6-6 6',
  chevronDown: 'm6 9 6 6 6-6',
  chevronLeft: 'm15 6-6 6 6 6',
  arrowRight: 'M5 12h14m-6-6 6 6-6 6',
  plus: 'M12 5v14M5 12h14',
  menu: 'M4 6h16M4 12h16M4 18h16',
  clock: 'M12 7v5l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
  timer: 'M9 2h6M12 13.5l3-3M12 22a8.5 8.5 0 1 0 0-17 8.5 8.5 0 0 0 0 17Z',
  stethoscope: 'M4 3h2m6 0h2M5 3v5a4 4 0 0 0 8 0V3M9 12v2.5a5.5 5.5 0 0 0 11 0V13M20 13a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z',
  user: 'M20 21a8 8 0 1 0-16 0M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z',
  mail: 'M4 6h16a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Zm0 1 8 6 8-6',
  phone: 'M4 4h4l2 5-3 2a12 12 0 0 0 6 6l2-3 5 2v4a1 1 0 0 1-1 1A17 17 0 0 1 3 5a1 1 0 0 1 1-1Z',
  pin: 'M12 21s7-6.5 7-12a7 7 0 1 0-14 0c0 5.5 7 12 7 12Zm0-9a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
  shield: 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3Z',
  syringe: 'm14 3 7 7M18 5l-8 8-4 4-3 3M9 12l3 3M14 7l3 3',
  flask: 'M9 3h6M10 3v6l-5 9a2 2 0 0 0 2 3h10a2 2 0 0 0 2-3l-5-9V3',
  doc: 'M6 3h8l4 4v14a0 0 0 0 1 0 0H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Zm8 0v4h4',
  heart: 'M12 20s-7-4.5-9-9a4.5 4.5 0 0 1 9-1 4.5 4.5 0 0 1 9 1c-2 4.5-9 9-9 9Z',
  check: 'm5 12 5 5 9-11',
  // Balanza de dos platos: el peso.
  scale: 'M12 4v16M5 8h14M8 20h8M2.5 14h5L5 8 2.5 14Zm14 0h5L19 8l-2.5 6Z',
  // Triángulo con signo: alergias y avisos. El punto final se dibuja porque
  // el trazo va con `stroke-linecap: round`.
  alert: 'M12 4 2.8 20h18.4L12 4Zm0 6v4m0 3h.01',
  wa: 'M12 3a9 9 0 0 0-7.7 13.6L3 21l4.5-1.2A9 9 0 1 0 12 3Zm4.6 12.7c-.2.5-1.1 1-1.6 1-.4 0-.9.2-3-.9-2.5-1.3-4-3.9-4.2-4-.1-.2-1-1.3-1-2.5s.6-1.7.9-2c.2-.2.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.7 1.8c.1.2 0 .4-.1.5l-.4.5c-.1.2-.3.3-.1.6.1.3.7 1.1 1.4 1.8.9.8 1.7 1 2 1.2.2 0 .4 0 .5-.1l.6-.7c.2-.2.4-.2.6-.1l1.7.8c.2.1.4.2.5.3.1.3.1.6-.1 1Z',
  edit: 'M4 20h4l10-10-4-4L4 16v4ZM14 6l4 4',
  eye: 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Zm10 3a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
  eyeOff: 'M3 3l18 18M10.6 10.6a3 3 0 0 0 4.24 4.24M9.4 5.3A10.4 10.4 0 0 1 12 5c6.5 0 10 7 10 7a17.5 17.5 0 0 1-3.2 4.1M6.5 6.6C4 8.3 2 12 2 12s3.5 7 10 7c1.2 0 2.3-.2 3.3-.6',
  download: 'M12 3v12m0 0 4-4m-4 4-4-4M4 21h16',
  filter: 'M3 5h18l-7 8v5l-4 2v-7L3 5Z',
  send: 'M4 12 20 4l-6 16-2.5-6.5L4 12Z',
  refresh: 'M4 4v5h5M20 20v-5h-5M20 9a8 8 0 0 0-14-3M4 15a8 8 0 0 0 14 3',
  // Una imagen (marco + montaña + sol): radiografías y fotos en Documentos,
  // donde el ojo ya significa «Ver».
  image: 'M5 4h14a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1Zm-1 12 5-5 4 4 2-2 5 5M15.5 9.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z',
};
</script>

<template>
  <svg
    :width="size" :height="size" viewBox="0 0 24 24"
    fill="none" stroke="currentColor" stroke-width="1.8"
    stroke-linecap="round" stroke-linejoin="round"
    aria-hidden="true"
  >
    <path :d="P[name] || ''" />
  </svg>
</template>
