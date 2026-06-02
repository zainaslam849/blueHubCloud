import { onMounted, onUnmounted, ref } from "vue";
import { http } from "../api/http";

function fixUrl(url: string) {
    return url.replace(/([^:])\/\/+/g, "$1/");
}

function isDarkTheme() {
    return document.documentElement.dataset.theme === "dark";
}

export function useBranding() {
    const siteName    = ref("");
    const logoUrl     = ref("");      // default fallback
    const logoLightUrl = ref("");     // for dark backgrounds
    const logoDarkUrl  = ref("");     // for light backgrounds
    const activeLogo   = ref("");

    function pickLogo() {
        if (isDarkTheme() && logoLightUrl.value) return logoLightUrl.value;
        if (!isDarkTheme() && logoDarkUrl.value)  return logoDarkUrl.value;
        return logoUrl.value;
    }

    function refresh() {
        activeLogo.value = pickLogo();
    }

    let observer: MutationObserver | null = null;

    onMounted(async () => {
        try {
            const res  = await http.get("/api/v1/settings/branding");
            const data = res.data?.data || {};
            if (data.site_name)      siteName.value     = data.site_name;
            if (data.logo_url)       logoUrl.value      = fixUrl(data.logo_url);
            if (data.logo_light_url) logoLightUrl.value = fixUrl(data.logo_light_url);
            if (data.logo_dark_url)  logoDarkUrl.value  = fixUrl(data.logo_dark_url);
            refresh();
        } catch { /* ignore */ }

        observer = new MutationObserver(refresh);
        observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ["data-theme"],
        });
    });

    onUnmounted(() => {
        observer?.disconnect();
    });

    return { siteName, activeLogo };
}
