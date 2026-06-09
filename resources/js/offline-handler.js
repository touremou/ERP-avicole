// resources/js/offline-handler.js
import { db } from './offline-db';

async function saveFormOffline(tableName, formData) {
    // On ajoute les métadonnées de synchro
    const data = Object.fromEntries(formData.entries());
    data.uuid = crypto.randomUUID(); // Génération UUID côté client
    data.is_synced = 0;
    data.created_at = new Date().toISOString();

    await db[tableName].add(data);
    
    alert("💾 Mode Hors-ligne : Données enregistrées localement. Elles seront synchronisées dès le retour du réseau.");
    window.location.href = "/dashboard"; // Redirection fluide
}

// Exemple d'intercepteur sur le formulaire de Batch
document.getElementById('batchForm')?.addEventListener('submit', async (e) => {
    if (!navigator.onLine) {
        e.preventDefault();
        const formData = new FormData(e.target);
        await saveFormOffline('batches', formData);
    }
});