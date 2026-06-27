<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center text-left">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-emerald-600 rounded-xl flex items-center justify-center text-white shadow-lg"><i class="fa-solid fa-comment-dots text-lg"></i></div>
                <div>
                    <h2 class="font-black text-xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ __('Modèles de notification') }}</h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">{{ __('Personnaliser les messages WhatsApp') }}</p>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 text-left">

            @foreach(['success', 'error'] as $msg)
                @if(session($msg))
                <div @class(['mb-6 p-4 rounded-2xl font-black text-[10px] uppercase tracking-widest shadow-sm',
                    'bg-emerald-500 text-white' => $msg === 'success',
                    'bg-red-500 text-white' => $msg === 'error']) >{{ session($msg) }}</div>
                @endif
            @endforeach

            <div class="bg-blue-50 border border-blue-100 rounded-2xl p-4 mb-6 text-[11px] text-blue-800 font-bold italic">
                <i class="fa-solid fa-circle-info mr-1"></i>
                {{ __('Utilisez les variables entre accolades, ex.') }} <code class="bg-white px-1 rounded">@{{ batch_code }}</code>.
                {{ __('Les variables non reconnues sont ignorées. Le formatage WhatsApp (*gras*) est conservé.') }}
            </div>

            <div class="space-y-5">
                @foreach($templates as $key => $tpl)
                    @php $model = $tpl['model']; @endphp
                    <div class="bg-white rounded-3xl border border-slate-100 shadow-sm p-6">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="font-black text-sm text-slate-800 uppercase italic">{{ $model->label }}</h3>
                            <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">{{ $key }}</span>
                        </div>

                        <form action="{{ route('notifications.templates.update', $model->id) }}" method="POST">
                            @csrf @method('PUT')
                            <textarea name="body" rows="7"
                                class="w-full text-[12px] font-mono bg-slate-50 border border-slate-200 rounded-2xl p-4 focus:ring-2 focus:ring-emerald-400 outline-none">{{ old('body', $model->body) }}</textarea>

                            <div class="flex flex-wrap items-center gap-2 mt-3">
                                @foreach($tpl['variables'] as $var)
                                    <code class="text-[10px] bg-slate-100 text-slate-600 px-2 py-1 rounded-lg">@{{ {{ $var }} }}</code>
                                @endforeach
                            </div>

                            <div class="flex items-center justify-between mt-4">
                                <label class="flex items-center gap-2 text-[11px] font-black text-slate-600 uppercase tracking-widest cursor-pointer">
                                    <input type="checkbox" name="is_active" value="1" {{ $model->is_active ? 'checked' : '' }} class="rounded">
                                    {{ __('Actif') }}
                                </label>
                                <div class="flex items-center gap-2">
                                    <button type="submit" class="bg-emerald-600 text-white px-6 py-3 rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-emerald-700 transition-all">
                                        <i class="fa-solid fa-floppy-disk mr-1"></i> {{ __('Enregistrer') }}
                                    </button>
                                </div>
                            </div>
                        </form>

                        <form action="{{ route('notifications.templates.reset', $model->id) }}" method="POST" class="mt-2 text-right" onsubmit="return confirm(@json(__('Restaurer le texte d\'origine ?')))">
                            @csrf @method('PUT')
                            <button type="submit" class="text-[10px] font-black text-slate-400 hover:text-rose-500 uppercase tracking-widest transition">
                                <i class="fa-solid fa-rotate-left mr-1"></i> {{ __('Réinitialiser') }}
                            </button>
                        </form>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>
