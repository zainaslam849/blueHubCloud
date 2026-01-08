<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="dark">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="csrf-token" content="{{ csrf_token() }}" />

        <title>{{ config("app.name", "BlueHubCloud") }} — Admin</title>

        <script>
            (function () {
                try {
                    var key = "admin_theme";
                    var stored = localStorage.getItem(key);
                    var prefersDark =
                        window.matchMedia &&
                        window.matchMedia("(prefers-color-scheme: dark)")
                            .matches;

                    var theme;
                    if (stored === "dark" || stored === "light") {
                        theme = stored;
                    } else if (stored === "system") {
                        theme = prefersDark ? "dark" : "light";
                    } else {
                        // Default admin theme
                        theme = "dark";
                    }
                    document.documentElement.dataset.theme = theme;
                } catch (e) {
                    // Ignore
                }
            })();
        </script>

        @vite(['resources/css/admin.css', 'resources/js/admin/app.js'])
    </head>

    <body class="admin-body">
        <div id="admin-app" class="admin-app"></div>
    </body>
</html>
