import { createApp } from "vue";

import AdminLayout from "../layouts/admin/AdminLayout.vue";
import router from "../router/admin";

import { initAdminTheme } from "./theme";

const mountEl = document.getElementById("admin-app");

// Mount only on the admin Blade view
if (mountEl) {
    initAdminTheme();
    createApp(AdminLayout).use(router).mount(mountEl);
}
