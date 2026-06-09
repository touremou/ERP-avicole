/* // resources/js/offline-db.js
import Dexie from 'dexie';

export const db = new Dexie('AviSmartOffline');

// Définition des tables locales (doivent matcher vos tables SQL)
db.version(1).stores({
    batches: 'uuid, code, building_id, is_synced',
    daily_checks: 'uuid, batch_uuid, check_date, is_synced',
    incubations: 'uuid, incubator_id, is_synced'
});

db.version(2).stores({
    batches: 'uuid, code, building_id, is_synced',
    buildings: 'id, name, status', // On ajoute les bâtiments pour consultation
    stocks: 'id, item_name, current_quantity' // Et les stocks
});

/**
 * Aspire les données du serveur vers le local
 */
/*
export async function refreshLocalData() {
    if (!navigator.onLine) return;

    try {
        // 1. Récupération des lots actifs
        const batchesRes = await fetch('/api/offline/batches');
        const batches = await batchesRes.json();
        await db.batches.clear();
        await db.batches.bulkAdd(batches);

        // 2. Récupération des bâtiments
        const buildingsRes = await fetch('/api/offline/buildings');
        const buildings = await buildingsRes.json();
        await db.buildings.clear();
        await db.buildings.bulkAdd(buildings);

        console.log("✅ Données de terrain synchronisées localement.");
    } catch (e) {
        console.error("Échec du préchargement :", e);
    }
} */

// resources/js/offline-db.js
import Dexie from 'dexie';

export const db = new Dexie('AviSmartOffline');

// Rendre Dexie disponible globalement pour le script de votre Layout / Blade
window.db = db;

// Définition des schémas
db.version(3).stores({
    batches: 'uuid, code, building_id, is_synced, updated_at',
    buildings: 'id, name, status, type', 
    stocks: 'id, item_name, current_quantity, category',
    employees: 'id, last_name', 
    providers: 'id, name',      
    protocols: 'id, name, type', 
    norms: 'id, model_name, batch_type',
    daily_checks: 'uuid, batch_id, check_date, is_synced'
});

/**
 * Aspire l'intégralité des référentiels du serveur vers le local
 */
export async function refreshLocalData() {
    if (!navigator.onLine) return;

    console.log("🛰️ AviSmart : Tentative de rafraîchissement des référentiels...");

    try {
        // Liste des endpoints à synchroniser
        const syncMap = [
            { url: '/api/offline/batches', table: db.batches },
            { url: '/api/offline/buildings', table: db.buildings },
            { url: '/api/offline/employees', table: db.employees },
            { url: '/api/offline/providers', table: db.providers },
            { url: '/api/offline/protocols', table: db.protocols },
            { url: '/api/offline/norms', table: db.norms },
            { url: '/api/offline/stocks', table: db.stocks },
        ];

        for (const item of syncMap) {
            const response = await fetch(item.url);
            if (response.ok) {
                const data = await response.json();
                await item.table.clear();
                if (data && data.length > 0) {
                    await item.table.bulkAdd(data);
                }
            }
        }

        console.log("✅ Miroir local mis à jour. Prêt pour le mode terrain.");
        
        // Optionnel : déclencher le rendu offline s'il est à l'écoute
        window.dispatchEvent(new CustomEvent('local-data-refreshed'));
        
    } catch (e) {
        console.error("❌ Erreur de préchargement des données :", e);
    }
}

// Rendre également la fonction disponible globalement si nécessaire
window.refreshLocalData = refreshLocalData;