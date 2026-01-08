import { reactive } from "vue";

const STORAGE_TOKEN_KEY = "bhc.token";

type AuthState = {
    token: string | null;
};

const state = reactive<AuthState>({
    token: localStorage.getItem(STORAGE_TOKEN_KEY),
});

export const auth = {
    state,

    isAuthenticated(): boolean {
        return Boolean(state.token);
    },

    getToken(): string | null {
        return state.token;
    },

    setToken(token: string | null): void {
        state.token = token;

        if (token) {
            localStorage.setItem(STORAGE_TOKEN_KEY, token);
        } else {
            localStorage.removeItem(STORAGE_TOKEN_KEY);
        }
    },

    logout(): void {
        auth.setToken(null);
    },
};
