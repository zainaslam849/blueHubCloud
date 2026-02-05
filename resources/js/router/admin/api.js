import axios from "axios";

const tokenEl = () => document.querySelector('meta[name="csrf-token"]');
const getCsrfToken = () => tokenEl()?.getAttribute("content") || "";

const adminApi = axios.create({
    baseURL: "/admin/api",
    withCredentials: true,
    headers: {
        "X-Requested-With": "XMLHttpRequest",
    },
});

adminApi.interceptors.request.use((config) => {
    const csrfToken = getCsrfToken();
    if (csrfToken) {
        config.headers["X-CSRF-TOKEN"] = csrfToken;
    }
    return config;
});

export function setCsrfToken(token) {
    const el = tokenEl();
    if (el) {
        el.setAttribute("content", token);
    }
    if (token) {
        adminApi.defaults.headers["X-CSRF-TOKEN"] = token;
    }
}

export default adminApi;
