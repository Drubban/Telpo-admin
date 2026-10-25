import axios from 'axios';

// Misma origen: la sesion viaja en una cookie httpOnly y Laravel exige el token CSRF que axios lee
// de la cookie XSRF-TOKEN y reenvia en X-XSRF-TOKEN.
const api = axios.create({
    baseURL: '/',
    withCredentials: true,
    xsrfCookieName: 'XSRF-TOKEN',
    xsrfHeaderName: 'X-XSRF-TOKEN',
    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
    timeout: 30000,
});

let onUnauthorized = () => {};
export function setUnauthorizedHandler(fn) {
    onUnauthorized = fn;
}

api.interceptors.response.use(
    (response) => response,
    (error) => {
        const status = error.response?.status;
        const url = error.config?.url ?? '';
        // 401 en cualquier llamada que no sea el login: la sesion expiro.
        if (status === 401 && !url.endsWith('/login')) onUnauthorized();
        // 419: el token CSRF caduco; recargar obtiene uno nuevo.
        if (status === 419) window.location.reload();
        return Promise.reject(error);
    },
);

/** Mensaje legible para mostrar al usuario a partir de un error de axios. */
export function errorMessage(error, fallback = 'No se pudo completar la acción.') {
    const res = error?.response;
    if (!res) return 'Sin conexión con el servidor. Revisa tu red e inténtalo de nuevo.';
    if (res.status === 422 && res.data?.errors) {
        const first = Object.values(res.data.errors)[0];
        return Array.isArray(first) ? first[0] : String(first);
    }
    if (res.status === 429) return 'Demasiados intentos. Espera un momento.';
    if (res.status >= 500) return 'Error del servidor. Inténtalo de nuevo en unos minutos.';
    return res.data?.message || fallback;
}

/** Errores de validacion por campo: { campo: 'mensaje' }. */
export function fieldErrors(error) {
    const errors = error?.response?.data?.errors;
    if (!errors) return {};
    return Object.fromEntries(Object.entries(errors).map(([k, v]) => [k, Array.isArray(v) ? v[0] : String(v)]));
}

export default api;
