/* version initiale avant modification du 20-04-2026

// resources/js/sync-engine.js
import { db } from './offline-db';

window.addEventListener('online', async () => {
    console.log("🌐 Réseau rétabli. Tentative de synchronisation...");
    syncData();
});

async function syncData() {
    const unsynced = await db.batches.where('is_synced').equals(0).toArray();

    for (const data of unsynced) {
        const response = await fetch('/api/sync/reconcile', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });

        const result = await response.json();

        if (result.status === 'success') {
            await db.batches.update(data.uuid, { is_synced: 1 });
        } else if (result.status === 'conflict') {
            // Rigueur industrielle : On prévient l'utilisateur
            console.warn(`⚠️ Conflit sur le lot ${data.code}. Version serveur conservée.`);
            // On met à jour le local avec la version "vérité" du serveur
            await db.batches.put(result.data);
        }
    }
} 
    

*/

// resources/js/sync-engine.js
import { db } from './offline-db';

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
 * Orchestrateur Principal de Synchronisation (Exporté globalement)
 */
export async function syncData() {
    try {
        // Ordre strict : d'abord les parents (lots), puis les enfants (pointages)
        await syncBatches();     
        await syncDailyChecks();  
    } catch (globalError) {
        console.error("❌ Erreur critique dans le cycle de synchronisation :", globalError);
    }

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