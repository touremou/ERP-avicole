// resources/js/sync-engine.js
import { db, refreshLocalData } from './offline-db';

/**
 * Écouteur d'événement réseau
 */
window.addEventListener('online', () => {
    console.log("🌐 Réseau détecté. Initialisation du tunnel de synchronisation...");
    syncData();
});

/**
 * 1. Synchronisation des Lots (Batches)
 */
async function syncBatches() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const unsyncedBatches = await db.batches.where('is_synced').equals(0).toArray();

    if (unsyncedBatches.length === 0) return;

    console.log(`📤 Moteur de synchro : ${unsyncedBatches.length} lot(s) en attente...`);

    for (const batch of unsyncedBatches) {
        try {
            const response = await fetch('/api/sync/reconcile', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(batch)
            });

            if (!response.ok) throw new Error(`Erreur serveur: ${response.status}`);

            const result = await response.json();

            if (result.status === 'success') {
                await db.batches.update(batch.uuid, { is_synced: 1 });
                console.log(`✅ Lot ${batch.code} synchronisé avec succès.`);
            }
            else if (result.status === 'conflict') {
                console.warn(`⚠️ Conflit sur ${batch.code}. Version serveur prioritaire.`);
                await db.batches.put({ ...result.data, is_synced: 1 });
            }
        } catch (error) {
            console.error(`❌ Échec de synchronisation pour le lot ${batch.code}:`, error);
        }
    }
}

/**
 * 2. Synchronisation des Suivis Journaliers (Daily Checks)
 */
async function syncDailyChecks() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const unsyncedChecks = await db.daily_checks.where('is_synced').equals(0).toArray();

    if (unsyncedChecks.length === 0) return;

    console.log(`📤 Moteur de synchro : ${unsyncedChecks.length} pointage(s) en attente...`);

    for (const check of unsyncedChecks) {
        try {
            const response = await fetch('/api/sync/daily-checks', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(check)
            });

            if (response.ok) {
                await db.daily_checks.update(check.uuid, { is_synced: 1 });
                console.log(`📊 Pointage du ${check.check_date} synchronisé.`);
            } else {
                throw new Error(`Statut HTTP: ${response.status}`);
            }
        } catch (error) {
            console.error(`❌ Erreur synchro pointage journalier:`, error);
        }
    }
}

/**
 * 3. Synchronisation des Collectes d'œufs (Egg Productions)
 */
async function syncEggProductions() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const unsynced = await db.egg_productions.where('is_synced').equals(0).toArray();

    if (unsynced.length === 0) return;

    console.log(`📤 Moteur de synchro : ${unsynced.length} collecte(s) d'œufs en attente...`);

    for (const collection of unsynced) {
        try {
            const response = await fetch('/api/sync/egg-collections', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(collection)
            });

            if (!response.ok) throw new Error(`Statut HTTP: ${response.status}`);

            const result = await response.json();

            if (result.status === 'success' || result.status === 'already_synced') {
                await db.egg_productions.update(collection.uuid, { is_synced: 1 });
                console.log(`🥚 Collecte du ${collection.production_date} synchronisée (${result.status}).`);
            } else if (result.status === 'conflict') {
                // Jour déjà trié côté serveur : on retire la collecte locale obsolète.
                console.warn(`⚠️ Collecte ${collection.production_date} en conflit : ${result.message}`);
                await db.egg_productions.update(collection.uuid, { is_synced: 1 });
            }
        } catch (error) {
            console.error(`❌ Erreur synchro collecte d'œufs:`, error);
        }
    }
}

/**
 * 4. Synchronisation des Mouvements de stock (entrée / sortie / ajustement)
 */
async function syncStockMovements() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const unsynced = await db.stock_movements.where('is_synced').equals(0).toArray();

    if (unsynced.length === 0) return;

    console.log(`📤 Moteur de synchro : ${unsynced.length} mouvement(s) de stock en attente...`);

    for (const movement of unsynced) {
        try {
            const response = await fetch('/api/sync/stock-movements', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(movement)
            });

            if (!response.ok) throw new Error(`Statut HTTP: ${response.status}`);

            const result = await response.json();

            if (result.status === 'success' || result.status === 'already_synced') {
                await db.stock_movements.update(movement.uuid, { is_synced: 1 });
                console.log(`📦 Mouvement stock #${movement.stock_id} (${movement.type}) synchronisé (${result.status}).`);
            } else if (result.status === 'conflict') {
                // Sortie refusée (stock insuffisant au moment de la synchro) :
                // on retire le mouvement local pour ne pas boucler indéfiniment.
                console.warn(`⚠️ Mouvement stock #${movement.stock_id} en conflit : ${result.message}`);
                await db.stock_movements.update(movement.uuid, { is_synced: 1 });
            }
        } catch (error) {
            console.error(`❌ Erreur synchro mouvement de stock:`, error);
        }
    }
}

/**
 * 5. Synchronisation des Ventes rapides (saisies hors-ligne)
 */
async function syncSales() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const unsynced = await db.sales.where('is_synced').equals(0).toArray();

    if (unsynced.length === 0) return;

    console.log(`📤 Moteur de synchro : ${unsynced.length} vente(s) rapide(s) en attente...`);

    for (const sale of unsynced) {
        try {
            const response = await fetch('/api/sync/sales', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(sale)
            });

            if (!response.ok) throw new Error(`Statut HTTP: ${response.status}`);

            const result = await response.json();

            if (result.status === 'success' || result.status === 'already_synced') {
                await db.sales.update(sale.uuid, { is_synced: 1 });
                console.log(`🧾 Vente ${sale.uuid} synchronisée (${result.status}${result.reference ? ' → ' + result.reference : ''}).`);
            } else if (result.status === 'conflict') {
                // Vente refusée côté serveur : on la marque traitée pour ne pas boucler.
                console.warn(`⚠️ Vente ${sale.uuid} en conflit : ${result.message}`);
                await db.sales.update(sale.uuid, { is_synced: 1 });
            }
        } catch (error) {
            console.error(`❌ Erreur synchro vente rapide:`, error);
        }
    }
}

/**
 * Orchestrateur Principal de Synchronisation (Exporté globalement)
 */
export async function syncData() {
    try {
        // Ordre strict : d'abord les parents (lots), puis les enfants (pointages, collectes)
        await syncBatches();
        await syncDailyChecks();
        await syncEggProductions();
        await syncStockMovements();
        await syncSales();
    } catch (globalError) {
        console.error("❌ Erreur critique dans le cycle de synchronisation :", globalError);
    }

    // Rafraîchit le miroir local (référentiels + lots) une fois la file vidée.
    await refreshLocalData();

    // Rafraîchissement visuel de l'interface si la fonction existe
    if (typeof loadOfflineContent === 'function') {
        try { loadOfflineContent(); } catch (e) {}
    }
}

// Rendre la fonction disponible globalement pour des appels manuels
window.syncData = syncData;

// Lancer une vérification au démarrage si le navigateur est déjà en ligne
if (navigator.onLine) {
    // Exécution retardée d'une seconde pour laisser Alpine.js et le DOM s'initialiser tranquillement
    setTimeout(() => {
        syncData();
    }, 1000);
}
