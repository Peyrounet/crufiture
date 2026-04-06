/**
 * axiosPrix.js — Client HTTP pour l'API /prix (services transverses)
 * Distinct de axios.js qui pointe vers /monpanier/api.
 */
import axios from 'axios';

const axiosPrix = axios.create({
    baseURL: import.meta.env.VITE_PRIX_API_URL ?? '/prix/api',
    withCredentials: true,
});

axiosPrix.interceptors.response.use(
    (response) => response,
    (error) => {
        const status = error.response?.status;
        if (status >= 400 && status <= 500) {
            return Promise.resolve(error.response);
        }
        return Promise.reject(error);
    }
);

export default axiosPrix;
