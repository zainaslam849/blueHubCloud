import { http } from "./http";
import type { UserProfile } from "../composables/useAuth";

type AuthResponse = {
    user: UserProfile;
    csrf_token: string;
};

export async function login(email: string, password: string): Promise<UserProfile> {
    const { data } = await http.post<AuthResponse>("/api/v1/auth/login", {
        email,
        password,
    });
    return data.user;
}

type RegisterPendingResponse = {
    status: "pending_verification";
    message: string;
    email: string;
};

export async function register(
    name: string,
    email: string,
    password: string,
    password_confirmation: string
): Promise<RegisterPendingResponse> {
    const { data } = await http.post<RegisterPendingResponse>("/api/v1/auth/register", {
        name,
        email,
        password,
        password_confirmation,
    });
    return data;
}

export async function verifyEmail(email: string, code: string): Promise<UserProfile> {
    const { data } = await http.post<AuthResponse>("/api/v1/auth/verify-email", { email, code });
    return data.user;
}

export async function resendVerification(email: string): Promise<void> {
    await http.post("/api/v1/auth/resend-verification", { email });
}

export async function me(): Promise<UserProfile> {
    const { data } = await http.get<AuthResponse>("/api/v1/auth/me");
    return data.user;
}

export async function logout(): Promise<void> {
    await http.post("/api/v1/auth/logout");
}
