/**
 * axiosStock.js — Client HTTP pour l'API /stock (service stock)
 * Distinct de axios.js qui pointe vers /monpanier/api.
 */
import axios from 'axios';

const axiosStock = axios.create({
    baseURL: import.meta.env.VITE_STOCK_API_URL ?? '/stock/api',
    withCredentials: true,
});

axiosStock.interceptors.response.use(
    (response) => response,
    (error) => {
        const status = error.response?.status;
        if (status >= 400 && status <= 500) {
            return Promise.resolve(error.response);
        }
        return Promise.reject(error);
    }
);

export default axiosStock;
