<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __("Erreur") }} — AviSmart</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-6 italic">
    <div class="max-w-md w-full text-center">
        <div class="bg-white p-12 rounded-[3rem] border border-slate-100 shadow-xl">
            {{-- Icône --}}
            <div class="w-20 h-20 bg-red-50 rounded-[1.5rem] flex items-center justify-center mx-auto mb-8">
                <i class="fa-solid fa-triangle-exclamation text-red-400 text-3xl"></i>
            </div>

            <h1 class="text-2xl font-black text-slate-800 uppercase tracking-tighter mb-3">{{ __("Oups !") }}</h1>
            <p class="text-sm text-slate-500 font-bold mb-8">
                {{ __("Une erreur technique est survenue. L'équipe a été alertée automatiquement.") }}
            </p>

            {{-- Message technique (en mode debug uniquement) --}}
            @if(config('app.debug') && isset($exception))
                <div class="bg-red-50 p-4 rounded-2xl text-left mb-6 overflow-x-auto">
                    <p class="text-[8px] font-black text-red-400 uppercase tracking-widest mb-2">{{ __("Détail technique") }}</p>
                    <p class="text-[10px] font-bold text-red-700 break-words">{{ $exception->getMessage() }}</p>
                    <p class="text-[8px] text-red-400 mt-2">{{ str_replace(base_path(), '', $exception->getFile()) }}:{{ $exception->getLine() }}</p>
                </div>
            @endif

            <div class="flex gap-3 justify-center">
                <a href="{{ url()->previous() }}" class="bg-slate-100 text-slate-600 px-6 py-3 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-slate-200 transition-all no-underline">
                    <i class="fa-solid fa-arrow-left mr-1"></i> {{ __("Retour") }}
                </a>
                <a href="{{ route('dashboard') }}" class="bg-slate-900 text-white px-6 py-3 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-blue-600 transition-all no-underline">
                    <i class="fa-solid fa-house mr-1"></i> {{ __("Dashboard") }}
                </a>
            </div>
        </div>

        <p class="text-[8px] text-slate-300 font-black uppercase tracking-widest mt-6">
            AviSmart ERP — {{ __("L'admin a été notifié par WhatsApp") }}
        </p>
    </div>
</body>
</html>
