<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Profile Photo') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Shared with the mobile app — a single photo for both.') }}
        </p>
    </header>

    <div class="mt-6 flex items-center gap-6">
        @if($user->avatar_url)
            <img src="{{ $user->avatar_url }}" alt="{{ $user->name }}" class="w-20 h-20 rounded-full object-cover shadow ring-1 ring-slate-200">
        @else
            <span class="w-20 h-20 rounded-full bg-slate-900 flex items-center justify-center text-white text-2xl font-black shadow">
                {{ strtoupper(substr($user->name ?? 'U', 0, 1)) }}
            </span>
        @endif

        <div class="space-y-3">
            <form method="post" action="{{ route('profile.avatar.update') }}" enctype="multipart/form-data" class="flex items-center gap-3">
                @csrf
                <input type="file" name="photo" accept="image/*" required
                       class="text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-slate-700 hover:file:bg-slate-200">
                <x-primary-button>{{ __('Upload') }}</x-primary-button>
            </form>

            @if($user->avatar_url)
                <form method="post" action="{{ route('profile.avatar.destroy') }}">
                    @csrf
                    @method('delete')
                    <button type="submit" class="text-sm font-semibold text-red-600 hover:text-red-800 bg-transparent border-0 cursor-pointer p-0">
                        {{ __('Remove photo') }}
                    </button>
                </form>
            @endif

            <x-input-error class="mt-1" :messages="$errors->get('photo')" />

            @if (in_array(session('status'), ['avatar-updated', 'avatar-removed']))
                <p x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 2000)" class="text-sm text-green-600">
                    {{ __('Saved.') }}
                </p>
            @endif
        </div>
    </div>
</section>
