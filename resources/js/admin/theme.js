const STORAGE_KEY = "admin_theme"; // 'light' | 'dark' | 'system'

function getSystemTheme() {
    if (
        typeof window !== "undefined" &&
        window.matchMedia &&
        window.matchMedia("(prefers-color-scheme: dark)").matches
    ) {
        return "dark";
    }

    return "light";
}

export function getThemePreference() {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored === "light" || stored === "dark" || stored === "system") {
            return stored;
        }
    } catch (e) {
        // ignore
    }

    // Premium admin default: dark, unless user chooses otherwise.
    return "dark";
}

export function resolveTheme(preference) {
    return preference === "system" ? getSystemTheme() : preference;
}

export function applyResolvedTheme(resolvedTheme) {
    document.documentElement.dataset.theme = resolvedTheme;
}

export function setThemePreference(preference) {
    try {
        localStorage.setItem(STORAGE_KEY, preference);
    } catch (e) {
        // ignore
    }

    applyResolvedTheme(resolveTheme(preference));
}

let mediaQuery;
let mediaQueryListener;

export function initAdminTheme() {
    const preference = getThemePreference();
    applyResolvedTheme(resolveTheme(preference));

    if (typeof window === "undefined" || !window.matchMedia) return;

    mediaQuery = window.matchMedia("(prefers-color-scheme: dark)");

    mediaQueryListener = () => {
        const pref = getThemePreference();
        if (pref !== "system") return;
        applyResolvedTheme(getSystemTheme());
    };

    if (typeof mediaQuery.addEventListener === "function") {
        mediaQuery.addEventListener("change", mediaQueryListener);
    } else if (typeof mediaQuery.addListener === "function") {
        mediaQuery.addListener(mediaQueryListener);
    }
}

export function cycleThemePreference(currentPreference) {
    if (currentPreference === "system") return "light";
    if (currentPreference === "light") return "dark";
    return "system";
}
