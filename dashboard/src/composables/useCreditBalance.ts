import { reactive } from "vue";
import { userApi } from "../api/user";

type PlanResponse = {
    credits: number;
    auto_topup: { enabled: boolean };
};

type CreditBalanceState = {
    credits: number | null;
    autoTopupEnabled: boolean;
    loaded: boolean;
};

const state = reactive<CreditBalanceState>({
    credits: null,
    autoTopupEnabled: false,
    loaded: false,
});

let inFlight: Promise<void> | null = null;

async function load(): Promise<void> {
    if (inFlight) return inFlight;
    inFlight = userApi
        .get<PlanResponse>("/plan")
        .then((res) => {
            state.credits = res.data.credits;
            state.autoTopupEnabled = res.data.auto_topup?.enabled ?? false;
            state.loaded = true;
        })
        .catch(() => {
            // No company assigned yet, or not authenticated — leave state as-is.
        })
        .finally(() => {
            inFlight = null;
        });
    return inFlight;
}

export function useCreditBalance() {
    return { state, refresh: load };
}
