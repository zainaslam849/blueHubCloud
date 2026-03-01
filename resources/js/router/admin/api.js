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

// Response interceptor to handle 419 CSRF token mismatch errors
let isRefreshingToken = false;
let failedQueue = [];

const processQueue = (error, token = null) => {
    failedQueue.forEach((prom) => {
        if (error) {
            prom.reject(error);
        } else {
            prom.resolve(token);
        }
    });
    failedQueue = [];
};

adminApi.interceptors.response.use(
    (response) => response,
    async (error) => {
        const originalRequest = error.config;

        // Check if error is 419 (CSRF token mismatch)
        if (error.response?.status === 419 && !originalRequest._retry) {
            if (isRefreshingToken) {
                // If already refreshing, queue this request
                return new Promise((resolve, reject) => {
                    failedQueue.push({ resolve, reject });
                })
                    .then((token) => {
                        originalRequest.headers["X-CSRF-TOKEN"] = token;
                        return adminApi(originalRequest);
                    })
                    .catch((err) => {
                        return Promise.reject(err);
                    });
            }

            originalRequest._retry = true;
            isRefreshingToken = true;

            try {
                // Fetch fresh CSRF token from /me endpoint
                const response = await axios.get("/admin/api/me", {
                    withCredentials: true,
                    headers: {
                        "X-Requested-With": "XMLHttpRequest",
                    },
                });

                const newToken = response.data?.csrf_token;

                if (newToken) {
                    setCsrfToken(newToken);
                    processQueue(null, newToken);

                    // Retry the original request with new token
                    originalRequest.headers["X-CSRF-TOKEN"] = newToken;
                    return adminApi(originalRequest);
                } else {
                    throw new Error("No CSRF token in response");
                }
            } catch (refreshError) {
                processQueue(refreshError, null);
                // If refresh fails, redirect to login
                if (
                    refreshError.response?.status === 401 ||
                    refreshError.response?.status === 403
                ) {
                    window.location.href = "/admin/login";
                }
                return Promise.reject(refreshError);
            } finally {
                isRefreshingToken = false;
            }
        }

        return Promise.reject(error);
    },
);

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
