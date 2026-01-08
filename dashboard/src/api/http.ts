import axios, { type InternalAxiosRequestConfig } from "axios";
import { auth } from "../composables/useAuth";

const baseURL = import.meta.env.VITE_API_BASE_URL || "http://localhost:8000";

export const http = axios.create({
    baseURL,
    headers: {
        Accept: "application/json",
    },
});

http.interceptors.request.use((config: InternalAxiosRequestConfig) => {
    const token = auth.getToken();
    if (token) {
        config.headers = config.headers ?? {};
        config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
});
