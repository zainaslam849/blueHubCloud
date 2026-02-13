import { http } from "./http";

export interface Company {
    id: number;
    name: string;
    status: string;
}

export interface CallCategory {
    id: number;
    name: string;
    description: string | null;
    is_enabled: boolean;
    deleted_at: string | null;
    created_at: string;
    updated_at: string;
    company_id: number;
    company?: Company;
}

export interface CreateCategoryPayload {
    name: string;
    description?: string;
    is_enabled?: boolean;
}

export interface UpdateCategoryPayload {
    name?: string;
    description?: string;
    is_enabled?: boolean;
}

export const categoriesApi = {
    /**
     * Get all categories (including soft-deleted)
     */
    async getAll(companyId?: number) {
        const params = new URLSearchParams();
        if (companyId) {
            params.append("company_id", String(companyId));
        }
        const queryString = params.toString();
        const url = `/admin/api/categories${queryString ? "?" + queryString : ""}`;
        const response = await http.get<{
            data: CallCategory[];
            meta: { total: number };
        }>(url);
        return response.data;
    },

    /**
     * Get only enabled categories
     */
    async getEnabled() {
        const response = await http.get<{
            data: CallCategory[];
        }>("/admin/api/categories/enabled");
        return response.data;
    },

    /**
     * Get a single category
     */
    async get(id: number) {
        const response = await http.get<{ data: CallCategory }>(
            `/admin/api/categories/${id}`,
        );
        return response.data;
    },

    /**
     * Create a new category
     */
    async create(payload: CreateCategoryPayload) {
        const response = await http.post<{
            data: CallCategory;
            message: string;
        }>("/admin/api/categories", payload);
        return response.data;
    },

    /**
     * Update a category
     */
    async update(id: number, payload: UpdateCategoryPayload) {
        const response = await http.put<{
            data: CallCategory;
            message: string;
        }>(`/admin/api/categories/${id}`, payload);
        return response.data;
    },

    /**
     * Toggle category enabled/disabled status
     */
    async toggle(id: number) {
        const response = await http.patch<{
            data: CallCategory;
            message: string;
        }>(`/admin/api/categories/${id}/toggle`);
        return response.data;
    },

    /**
     * Soft delete a category
     */
    async delete(id: number) {
        const response = await http.delete<{
            message: string;
        }>(`/admin/api/categories/${id}`);
        return response.data;
    },

    /**
     * Restore a soft-deleted category
     */
    async restore(id: number) {
        const response = await http.post<{
            data: CallCategory;
            message: string;
        }>(`/admin/api/categories/${id}/restore`);
        return response.data;
    },

    /**
     * Permanently delete a category
     */
    async forceDelete(id: number) {
        const response = await http.delete<{
            message: string;
        }>(`/admin/api/categories/${id}/force-delete`);
        return response.data;
    },
};
