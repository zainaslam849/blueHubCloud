import { http } from "./http";

// Typed wrappers around the /api/v1/* user endpoints

export const userApi = {
    get<T = unknown>(path: string, params?: Record<string, unknown>) {
        return http.get<T>(`/api/v1${path}`, { params });
    },
    post<T = unknown>(path: string, data?: unknown) {
        return http.post<T>(`/api/v1${path}`, data);
    },
};
