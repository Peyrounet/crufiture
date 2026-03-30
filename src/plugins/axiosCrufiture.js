/**
 * axiosCrufiture.js — Client HTTP pour l'API /crufiture
 * Distinct de axios.js qui pointe vers /monpanier/api.
 */
import axios from 'axios';

const axiosCrufiture = axios.create({
    baseURL: import.meta.env.VITE_CRUFITURE_API_URL ?? '/crufiture/api',
    withCredentials: true,
});

axiosCrufiture.interceptors.response.use(
    (response) => response,
    (error) => {
        const status = error.response?.status;
        if (status >= 400 && status <= 500) {
            return Promise.resolve(error.response);
        }
        return Promise.reject(error);
    }
);

export default axiosCrufiture;
