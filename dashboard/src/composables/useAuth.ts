import { reactive } from "vue";
import { me as fetchMe } from "../api/auth";

export type UserProfile = {
    id: number;
    name: string;
    email: string;
    role: string;
    company_id: number | null;
    company_name: string | null;
    company_slug: string | null;
    created_at: string | null;
};

type AuthState = {
    user: UserProfile | null;
    checked: boolean;
};

const state = reactive<AuthState>({
    user: null,
    checked: false,
});

export const auth = {
    state,

    isAuthenticated(): boolean {
        return state.user !== null;
    },

    /**
     * Call once on app mount to restore session state after a page refresh.
     * Silently returns if the session cookie has expired (user stays on login).
     */
    async checkSession(): Promise<void> {
        if (state.checked) return;
        state.checked = true;
        try {
            const user = await fetchMe();
            state.user = user;
        } catch {
            state.user = null;
        }
    },

    setUser(user: UserProfile | null): void {
        state.user = user;
        state.checked = true;
    },

    logout(): void {
        state.user = null;
    },
};
