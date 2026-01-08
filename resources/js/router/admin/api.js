import axios from "axios";

const tokenEl = document.querySelector('meta[name="csrf-token"]');
const csrfToken = tokenEl?.getAttribute("content") || "";

const adminApi = axios.create({
    baseURL: "/admin/api",
    withCredentials: true,
    headers: {
        "X-Requested-With": "XMLHttpRequest",
        ...(csrfToken ? { "X-CSRF-TOKEN": csrfToken } : {}),
    },
});

export default adminApi;
