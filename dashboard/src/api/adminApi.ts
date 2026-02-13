import { http } from "./http";
import type { AxiosRequestConfig, AxiosResponse } from "axios";

function normalizePath(path: string): string {
    if (!path.startsWith("/")) {
        return `/admin/api/${path}`;
    }
    return `/admin/api${path}`;
}

export const adminApi = {
    get<T = any>(
        path: string,
        config?: AxiosRequestConfig,
    ): Promise<AxiosResponse<T>> {
        return http.get(normalizePath(path), config);
    },
    post<T = any>(
        path: string,
        data?: any,
        config?: AxiosRequestConfig,
    ): Promise<AxiosResponse<T>> {
        return http.post(normalizePath(path), data, config);
    },
    put<T = any>(
        path: string,
        data?: any,
        config?: AxiosRequestConfig,
    ): Promise<AxiosResponse<T>> {
        return http.put(normalizePath(path), data, config);
    },
    delete<T = any>(
        path: string,
        config?: AxiosRequestConfig,
    ): Promise<AxiosResponse<T>> {
        return http.delete(normalizePath(path), config);
    },
};
