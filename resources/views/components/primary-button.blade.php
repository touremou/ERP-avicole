<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-biocrest border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-biocrest-600 focus:bg-biocrest-600 active:bg-biocrest-700 focus:outline-none focus:ring-2 focus:ring-biocrest focus:ring-offset-2 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
