import { http } from "./http";

export type LoginResponse = {
    token: string;
};

export async function login(
    email: string,
    password: string
): Promise<LoginResponse> {
    const { data } = await http.post<LoginResponse>("/api/v1/auth/login", {
        email,
        password,
    });
    return data;
}

export async function logout(): Promise<void> {
    await http.post("/api/v1/auth/logout");
}
