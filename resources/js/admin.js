import { createApp } from "vue";
import AdminLayout from "./layouts/admin/AdminLayout.vue";
import router from "./router/admin";

const mountEl = document.getElementById("admin-app");

// Mount only on the admin Blade view
if (mountEl) {
    createApp(AdminLayout).use(router).mount(mountEl);
}
