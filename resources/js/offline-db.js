// resources/js/offline-db.js
import Dexie from 'dexie';

export const db = new Dexie('AviSmartOffline');

// Rendre Dexie disponible globalement pour le script du Layout / des vues Blade
window.db = db;

// Schéma local : miroir des référentiels serveur + files d'attente de synchro.
db.version(3).stores({
    batches: 'uuid, code, building_id, is_synced, updated_at',
    buildings: 'id, name, status, type',
    stocks: 'id, item_name, current_quantity, category',
    employees: 'id, last_name',
    providers: 'id, name',
    protocols: 'id, name, type',
    norms: 'id, model_name, batch_type',
    daily_checks: 'uuid, batch_id, check_date, is_synced',
});

// v4 : ajout de l'index `id` sur batches pour permettre de retrouver un lot
// hors-ligne par son identifiant numérique (utilisé par daily-checks/create).
db.version(4).stores({
    batches: 'uuid, id, code, building_id, is_synced, updated_at',
});

/**
 * Aspire les référentiels du serveur vers le miroir local (IndexedDB).
 * Appelée au chargement (si en ligne) et après chaque synchro réussie.
 */
export async function refreshLocalData() {
    if (!navigator.onLine) return;

    console.log("🛰️ AviSmart : rafraîchissement des référentiels locaux...");

    try {
        // Lots actifs : bulkPut (upsert par uuid) pour ne JAMAIS écraser les
        // lots créés hors-ligne et pas encore synchronisés (uuid distincts,
        // donc préservés par cette opération).
        const batchesRes = await fetch('/api/offline/batches');
        if (batchesRes.ok) {
            const batches = await batchesRes.json();
            if (batches && batches.length > 0) {
                await db.batches.bulkPut(batches);
            }
        }

        // Référentiels en lecture seule : on remplace intégralement.
        const syncMap = [
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

        window.dispatchEvent(new CustomEvent('local-data-refreshed'));
    } catch (e) {
        console.error("❌ Erreur de préchargement des référentiels :", e);
    }
}

window.refreshLocalData = refreshLocalData;
