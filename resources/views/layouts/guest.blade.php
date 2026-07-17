<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ setting('general.company_name', config('app.name', 'AviSmart')) }}</title>

        @include('partials.pwa-head')

        {{-- Figtree auto-hébergée (bundlée par Vite via @fontsource, importée
             dans resources/js/app.js) — plus de CDN de police. --}}

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gray-100">
            <div>
                <a href="/">
                    <x-application-logo class="w-20 h-20 fill-current text-gray-500" />
                </a>
            </div>

            <div class="w-full sm:max-w-md mt-6 px-6 py-4 bg-white shadow-md overflow-hidden sm:rounded-lg">
                {{ $slot }}
            </div>
        </div>

        {{-- Audit E2E : jamais de Service Worker sous Dusk — il intercepte
             navigations et POST (cache/file offline) et rend les parcours
             navigateur non déterministes. --}}
        @unless (app()->environment('dusk'))
        <script>
            // Enregistre le Service Worker dès la page de connexion pour que
            // l'application soit installable (PWA) avant même l'authentification.
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.register('/sw.js').catch(() => {});
            }
        </script>
        @endunless
    </body>
</html>
