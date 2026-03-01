import adminApi, { setCsrfToken } from "./api";

let cachedUser = null;
let loaded = false;
let access = "unknown";

export function getAdminAccess() {
    return access;
}

export async function getAdminUser(force = false) {
    if (loaded && !force) return cachedUser;

    try {
        const res = await adminApi.get("/me");
        cachedUser = res?.data?.user ?? null;
        access = cachedUser ? "ok" : "unauthenticated";

        // Update CSRF token if present in response
        if (res?.data?.csrf_token) {
            setCsrfToken(res.data.csrf_token);
        }
    } catch (e) {
        // Axios error objects usually include response.status.
        // We treat 403 as a distinct "forbidden" state.
        const status = e?.response?.status;
        access = status === 403 ? "forbidden" : "unauthenticated";
        cachedUser = null;
    } finally {
        loaded = true;
    }

    return cachedUser;
}

export function setAdminUser(user) {
    cachedUser = user;
    loaded = true;
    access = user ? "ok" : "unauthenticated";
}

export function clearAdminUser() {
    cachedUser = null;
    loaded = true;
    access = "unauthenticated";
}
