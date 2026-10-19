/* Nombre de la clínica en pantalla. Se fija al compilar (VITE_CLINICA_NOMBRE,
   ver .env); el logo es public/logo.webp y el isotipo, public/isotipo.webp. */
export const CLINICA = import.meta.env.VITE_CLINICA_NOMBRE || 'PANGI';
