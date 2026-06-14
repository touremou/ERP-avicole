<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class PwaController extends Controller
{
    /**
     * Manifest PWA dynamique : nom et icône pilotés par les paramètres
     * (general.company_name / general.company_logo), avec repli sur les
     * icônes AviSmart par défaut (public/images/pwa).
     */
    public function manifest(): JsonResponse
    {
        $name = setting('general.company_name', 'AviSmart');

        $icons = [];

        // Le logo de l'entreprise (Paramètres → Général) sert d'icône
        // d'application s'il est défini et exploitable (PNG/JPEG/WebP).
        if ($logo = $this->companyLogoIcon()) {
            $icons[] = $logo;
        }

        $icons[] = ['src' => '/images/pwa/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'];
        $icons[] = ['src' => '/images/pwa/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'];
        $icons[] = ['src' => '/images/pwa/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'];

        return response()->json([
            'name' => $name,
            'short_name' => mb_substr($name, 0, 12),
            'description' => 'ERP de gestion avicole — élevage, couvoir, provenderie, commerce.',
            'lang' => 'fr',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'theme_color' => '#16a34a',
            'background_color' => '#ffffff',
            'icons' => $icons,
        ], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function companyLogoIcon(): ?array
    {
        $path = setting('general.company_logo');

        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $absolute = Storage::disk('public')->path($path);
        $info = @getimagesize($absolute);

        if (! $info || ! in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
            return null;
        }

        return [
            'src' => Storage::url($path),
            'sizes' => $info[0].'x'.$info[1],
            'type' => $info['mime'],
            'purpose' => 'any',
        ];
    }
}
