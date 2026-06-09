<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation - AviSmart ERP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-50 flex items-center justify-center min-h-screen p-4 italic">

    <div class="max-w-md w-full">
        <div class="text-center mb-8">
            <div class="w-20 h-20 bg-blue-600 rounded-3xl mx-auto flex items-center justify-center shadow-xl rotate-3 mb-4">
                <i class="fa-solid fa-rocket text-4xl text-white -rotate-3"></i>
            </div>
            <h1 class="text-3xl font-black text-slate-800 uppercase tracking-tighter">AviSmart <span class="text-blue-600">ERP</span></h1>
            <p class="text-slate-400 font-bold text-[10px] uppercase tracking-widest mt-2">Configuration initiale du système</p>
        </div>

        <div class="bg-white p-10 rounded-[3rem] shadow-2xl border border-slate-100 relative overflow-hidden">
            <div class="absolute top-0 left-0 w-full h-2 bg-gradient-to-r from-blue-500 to-emerald-500"></div>
            
            <h2 class="text-sm font-black text-slate-800 uppercase mb-6 tracking-widest flex items-center gap-2">
                <i class="fa-solid fa-crown text-amber-500"></i> Création du Super Admin
            </h2>

            <form action="{{ route('setup.store') }}" method="POST" class="space-y-5">
                @csrf

                <div>
                    <label class="text-[9px] font-black uppercase text-slate-400 ml-4 mb-2 block tracking-widest">Nom complet</label>
                    <input type="text" name="name" required placeholder="Ex: Directeur Général"
                           class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-sm shadow-inner outline-none focus:ring-2 focus:ring-blue-500 transition-all">
                </div>

                <div>
                    <label class="text-[9px] font-black uppercase text-slate-400 ml-4 mb-2 block tracking-widest">Email Administrateur</label>
                    <input type="email" name="email" required placeholder="admin@avismart.com"
                           class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-sm shadow-inner outline-none focus:ring-2 focus:ring-blue-500 transition-all">
                </div>

                <div>
                    <label class="text-[9px] font-black uppercase text-slate-400 ml-4 mb-2 block tracking-widest">Mot de passe (Min 8)</label>
                    <input type="password" name="password" required 
                           class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-sm shadow-inner outline-none focus:ring-2 focus:ring-blue-500 transition-all">
                </div>

                <div>
                    <label class="text-[9px] font-black uppercase text-slate-400 ml-4 mb-2 block tracking-widest">Confirmation</label>
                    <input type="password" name="password_confirmation" required 
                           class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-sm shadow-inner outline-none focus:ring-2 focus:ring-blue-500 transition-all">
                </div>

                <button type="submit" class="w-full bg-slate-900 text-white py-5 mt-4 rounded-2xl font-black text-xs uppercase tracking-[0.3em] hover:bg-blue-600 transition-all border-none cursor-pointer shadow-xl">
                    Initialiser l'ERP <i class="fa-solid fa-arrow-right ml-2"></i>
                </button>
            </form>
        </div>
        
        <p class="text-center text-[9px] font-black uppercase text-slate-400 tracking-widest mt-8">
            <i class="fa-solid fa-lock mr-1"></i> Cette page sera détruite après validation.
        </p>
    </div>

</body>
</html>