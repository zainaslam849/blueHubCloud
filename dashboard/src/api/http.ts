import axios from "axios";
import { auth } from "../composables/useAuth";

// Empty string = relative URLs — axios sends to the same origin the page loaded from.
// Set VITE_API_BASE_URL only when the API is on a different host (e.g. separate deployment).
const baseURL = import.meta.env.VITE_API_BASE_URL || "";

export const http = axios.create({
    baseURL,
    withCredentials: true, // Send session cookies on every request
    headers: {
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest", // Tells Laravel this is an AJAX request
    },
});

// Response interceptor: on 401, clear auth state and redirect to /login
http.interceptors.response.use(
    (response) => response,
    (error) => {
        const status = error?.response?.status;
        if (status === 401) {
            try {
                auth.logout();
            } catch {
                // ignore
            }

            if (typeof window !== "undefined") {
                const onLogin = window.location.pathname.startsWith("/login");
                const onRegister = window.location.pathname.startsWith("/register");
                if (!onLogin && !onRegister) {
                    const redirect = encodeURIComponent(
                        window.location.pathname + window.location.search
                    );
                    window.location.assign(`/login?redirect=${redirect}`);
                }
            }
        }
        return Promise.reject(error);
    },
);
