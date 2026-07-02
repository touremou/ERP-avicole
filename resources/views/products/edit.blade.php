<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Modifier l\'article')" icon="fa-box-open" accent="teal" :back="route('products.index')" />
    </x-slot>

    <div class="py-10">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8 text-left">
            @if($errors->any())
            <div class="mb-6 p-4 rounded-2xl bg-red-500 text-white font-black text-[10px] uppercase tracking-widest">{{ $errors->first() }}</div>
            @endif
            <form action="{{ route('products.update', $product) }}" method="POST" enctype="multipart/form-data" class="bg-white rounded-3xl border border-slate-100 shadow-sm p-6">
                @csrf @method('PUT')
                @include('products._form')
                <div class="flex justify-between items-center mt-6">
                    <a href="{{ route('products.index') }}" class="text-[10px] font-black text-slate-400 uppercase tracking-widest no-underline"><i class="fa-solid fa-arrow-left mr-1"></i>{{ __('Retour') }}</a>
                    <button type="submit" class="bg-slate-900 text-white px-8 py-3 rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-teal-600 transition-all"><i class="fa-solid fa-floppy-disk mr-1"></i>{{ __('Enregistrer') }}</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
