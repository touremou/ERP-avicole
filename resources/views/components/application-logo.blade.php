@php($companyLogo = setting('general.company_logo'))

@if($companyLogo)
<img src="{{ media_url($companyLogo) }}" alt="{{ setting('general.company_name', 'AviSmart') }}" {{ $attributes->merge(['class' => 'h-8 w-auto object-contain']) }}>
@else
{{-- Marque Biocrest (coq + épi). Le SVG porte ses propres couleurs de charte
     (vert #349937) ; les classes de taille passées par l'appelant s'appliquent. --}}
<img src="{{ asset('biocrest-mark.svg') }}" alt="Biocrest" {{ $attributes->merge(['class' => 'object-contain']) }}>
@endif
