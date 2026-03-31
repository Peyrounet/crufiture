/**
 * axiosPeyrounet.js — Client HTTP pour l'API /peyrounet (services transverses)
 * Distinct de axios.js qui pointe vers /monpanier/api.
 */
import axios from 'axios';

const axiosPeyrounet = axios.create({
    baseURL: import.meta.env.VITE_PEYROUNET_API_URL ?? '/peyrounet/api',
    withCredentials: true,
});

axiosPeyrounet.interceptors.response.use(
    (response) => response,
    (error) => {
        const status = error.response?.status;
        if (status >= 400 && status <= 500) {
            return Promise.resolve(error.response);
        }
        return Promise.reject(error);
    }
);

export default axiosPeyrounet;
