<nav class="flex px-4 py-2 text-slate-400 italic mb-6" aria-label="Breadcrumb">
    <ol class="inline-flex items-center space-x-2 uppercase tracking-[0.15em] text-[7px] font-black">
        {{-- HOME --}}
        <li class="inline-flex items-center">
            <a href="{{ route('dashboard') }}" class="hover:text-slate-900 transition-colors flex items-center group">
                <i class="fa-solid fa-house-chimney mr-2 text-[9px] opacity-50 group-hover:text-blue-500 transition-colors"></i>
                {{ __("Dashboard") }}
            </a>
        </li>

        @foreach($autoBreadcrumbs as $breadcrumb)
            @php
                // 1. On détermine si le segment doit être cliquable
                // Si c'est 'manage-batches' ou 'batches-admin', on force vers la liste index
                $isTechnical = str_contains($breadcrumb['url'], 'manage-batches') || str_contains($breadcrumb['url'], 'batches-admin');
                
                $targetUrl = $isTechnical ? route('batches.index') : $breadcrumb['url'];
                
                // 2. Nettoyage du label pour l'affichage
                $displayLabel = str_replace(['-', '_'], ' ', $breadcrumb['label']);
            @endphp

            <li class="flex items-center">
                <i class="fa-solid fa-chevron-right mx-2 text-[6px] opacity-20"></i>
                
                @if (!$loop->last)
                    <a href="{{ $targetUrl }}" class="hover:text-slate-900 transition-colors">
                        {{ __($displayLabel) }}
                    </a>
                @else
                    {{-- Page actuelle (non cliquable) --}}
                    <span class="text-slate-900 font-black">
                        {{ __($displayLabel) }}
                    </span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>