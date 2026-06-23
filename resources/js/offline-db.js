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

// v5 : file d'attente des collectes d'œufs (ponte) saisies en mode terrain.
db.version(5).stores({
    egg_productions: 'uuid, batch_id, production_date, is_synced',
});

// v6 : file d'attente des mouvements de stock (entrée / sortie / ajustement).
db.version(6).stores({
    stock_movements: 'uuid, stock_id, type, is_synced',
});

// v7 : référentiel clients (miroir lecture seule) + file d'attente des
// ventes rapides saisies hors-ligne (synchronisées en brouillon).
db.version(7).stores({
    clients: 'id, name',
    sales: 'uuid, client_id, sale_date, is_synced',
});

// v8 : file d'attente des dépenses saisies hors-ligne (synchronisées en
// « en_attente », validées en ligne par un responsable).
db.version(8).stores({
    expenses: 'uuid, category, expense_date, is_synced',
});

/**
 * Aspire les référentiels du serveur vers le miroir local (IndexedDB).
 * Appelée au chargement (si en ligne) et après chaque synchro réussie.
 */
export async function refreshLocalData() {
    if (!navigator.onLine) return;

    console.log("🛰️ AviSmart : rafraîchissement des référentiels locaux...");

    try {
        // Lots actifs : le serveur fait foi pour les lots déjà synchronisés.
        // On réconcilie le miroir local :
        //   1. on SUPPRIME les lots synchronisés qui ne sont plus renvoyés par
        //      le serveur (lots clôturés, supprimés ou base réinitialisée) —
        //      sinon ils restaient affichés indéfiniment en « mode terrain » ;
        //   2. on PRÉSERVE les lots créés hors-ligne et pas encore synchronisés
        //      (is_synced === 0), qui n'existent pas encore côté serveur ;
        //   3. on upsert (bulkPut par uuid) la liste serveur.
        const batchesRes = await fetch('/api/offline/batches');
        if (batchesRes.ok) {
            const batches = await batchesRes.json();
            const serverUuids = new Set((batches || []).map(b => b.uuid));

            await db.batches
                .filter(b => b.is_synced !== 0 && !serverUuids.has(b.uuid))
                .delete();

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
            { url: '/api/offline/clients', table: db.clients },
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

/**
 * Purge complète du miroir hors-ligne (IndexedDB). À utiliser après une
 * réinitialisation serveur (migrate:fresh) ou pour repartir d'un cache propre.
 *
 * ⚠️ Supprime aussi les saisies hors-ligne NON synchronisées encore en file
 * d'attente. À n'exécuter que lorsque l'on est sûr qu'il n'y a rien à remonter.
 *
 * Usage console : await purgeOfflineData()
 */
export async function purgeOfflineData() {
    try {
        await Promise.all(db.tables.map(t => t.clear()));
        console.log('🧹 Miroir hors-ligne vidé. Rechargez la page.');
    } catch (e) {
        console.error('❌ Échec de la purge hors-ligne :', e);
    }
}

window.purgeOfflineData = purgeOfflineData;
