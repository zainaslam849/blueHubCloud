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

// Response interceptor: on 401/403, clear auth state and redirect to /login
// preserving the current location so the user lands back here after re-auth.
http.interceptors.response.use(
    (response) => response,
    (error) => {
        const status = error?.response?.status;
        if (status === 401 || status === 403) {
            try {
                auth.logout();
            } catch {
                // ignore
            }

            if (typeof window !== "undefined") {
                const current = window.location.pathname + window.location.search;
                const onLogin = window.location.pathname.startsWith("/login");
                if (!onLogin) {
                    const redirect = encodeURIComponent(current);
                    window.location.assign(`/login?redirect=${redirect}`);
                }
            }
        }
        return Promise.reject(error);
    },
);
