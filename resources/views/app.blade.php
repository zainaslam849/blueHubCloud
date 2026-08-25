<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" />
        <meta name="csrf-token" content="{{ csrf_token() }}" />
        <title>{{ config("app.name", "BlueHubCloud") }}</title>

        @if(app()->environment('local'))
            {{-- Development: load from the dashboard Vite dev server (port 5174) --}}
            <script type="module" src="http://localhost:5174/dashboard-app/@@vite/client"></script>
            <script type="module" src="http://localhost:5174/dashboard-app/src/main.ts"></script>
        @else
            {{-- Production: load from the built manifest --}}
            @php
                $manifest = json_decode(file_get_contents(public_path('dashboard-app/.vite/manifest.json')), true);
                $entry = $manifest['src/main.ts'] ?? null;
            @endphp
            @if($entry)
                @foreach(($entry['css'] ?? []) as $css)
                    <link rel="stylesheet" href="/dashboard-app/{{ $css }}" />
                @endforeach
                <script type="module" src="/dashboard-app/{{ $entry['file'] }}"></script>
            @endif
        @endif
    </head>

    <body>
        <div id="app"></div>
    </body>
</html>
