/**
 * axios.js — Client HTTP
 *
 * baseURL pointe vers l'API du socle monpanier.
 * Le cookie JWT est envoyé automatiquement (withCredentials).
 */
import axios from 'axios';

// L'API est celle de monpanier (socle partagé)
axios.defaults.baseURL = import.meta.env.VITE_API_URL ?? '/monpanier/api';
axios.defaults.withCredentials = true;

axios.interceptors.response.use(
    (response) => response,
    (error) => {
        const status = error.response?.status;
        if (status >= 400 && status <= 500) {
            return Promise.resolve(error.response);
        }
        return Promise.reject(error);
    }
);

export default axios;
