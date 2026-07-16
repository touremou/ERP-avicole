{{-- PWA : manifest, icônes et couleurs (logo des Paramètres prioritaire, sinon icône AviSmart par défaut) --}}
@php
    $pwaLogo = setting('general.company_logo');
    $pwaIcon = $pwaLogo ? \Illuminate\Support\Facades\Storage::url($pwaLogo) : null;
@endphp
<link rel="manifest" href="{{ route('pwa.manifest') }}">
<meta name="theme-color" content="#349937">
<link rel="icon" type="image/svg+xml" href="{{ asset('biocrest-mark.svg') }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="{{ setting('general.company_name', 'AviSmart') }}">
<link rel="icon" type="image/png" sizes="48x48" href="{{ $pwaIcon ?? asset('images/pwa/favicon-48.png') }}">
<link rel="apple-touch-icon" href="{{ $pwaIcon ?? asset('images/pwa/apple-touch-icon.png') }}">
