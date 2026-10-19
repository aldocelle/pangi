import axios from 'axios';
import { API_URL } from './config.js';

// Landing pública: sin login, solo consume endpoints públicos del backend
// (servicios, especialistas). Nunca envía token.
export const api = axios.create({
  baseURL: API_URL,
});

export function formatCLP(n) {
  if (n == null) return null;
  return n.toLocaleString('es-CL') + ' CLP';
}
