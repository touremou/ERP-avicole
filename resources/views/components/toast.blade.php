<div x-data="{ 
        show: false, 
        message: '', 
        type: 'success',
        timer: null
    }"
    x-on:notify.window="
        message = $event.detail.message; 
        type = $event.detail.type || 'success';
        show = true;
        clearTimeout(timer);
        timer = setTimeout(() => show = false, 4000)
    "
    x-show="show"
    x-transition:enter="transition ease-out duration-500"
    x-transition:enter-start="opacity-0 translate-y-4 md:translate-y-0 md:translate-x-8"
    x-transition:enter-end="opacity-100 translate-y-0 md:translate-x-0"
    x-transition:leave="transition ease-in duration-300"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    class="fixed bottom-5 right-5 z-[100] w-full max-w-xs"
    style="display: none;">
    
    <div :class="{
            'bg-slate-900 text-white': type === 'success',
            'bg-red-600 text-white': type === 'error',
            'bg-orange-500 text-white': type === 'warning'
        }"
        class="p-5 rounded-[2rem] shadow-2xl border border-white/10 flex items-center gap-4 italic">
        
        <div class="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center shrink-0">
            <i class="fas" :class="{
                'fa-check': type === 'success',
                'fa-exclamation-triangle': type === 'error' || type === 'warning'
            } text-[10px]"></i>
        </div>
        
        <p class="text-[10px] font-black uppercase tracking-widest leading-tight" x-text="message"></p>
    </div>
</div>