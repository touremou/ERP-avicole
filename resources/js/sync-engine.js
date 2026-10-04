// resources/js/sync-engine.js
import { db, refreshLocalData } from './offline-db';
import { issueDeSynchro, rangerSaisie, REFUSEE, EN_ATTENTE, contexteCourant, poussableIci, corpsAEnvoyer, traduire } from './sync-outcome';

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
    const contexte = contexteCourant();
    const unsyncedBatches = (await db.batches.where('is_synced').equals(EN_ATTENTE).toArray())
        .filter(b => poussableIci(b, contexte));

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
                body: JSON.stringify(corpsAEnvoyer(batch))
            });

            const result = await response.json().catch(() => ({}));

            // Refus définitif (422, 403…) : sort de la file, reste visible.
            if (!response.ok) {
                const issue = issueDeSynchro(response.status, result);
                if (issue.etat === 'refuse') {
                    await marquerRefusee(db.batches, batch.uuid, issue.motif);
                    continue;
                }
                throw new Error(`Erreur serveur: ${response.status}`);
            }

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
 * Pousse une file locale, saisie par saisie, et range chacune selon la réponse.
 *
 * Remplace cinq fonctions quasi identiques qui décidaient chacune à sa façon —
 * et perdaient chacune des saisies (cf. `sync-outcome.js`). La décision est
 * désormais UNE fonction pure, éprouvée hors navigateur.
 */
async function pousserFile(table, url, libelle) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    // Seulement les saisies du compte connecté, sur le site actif.
    const contexte = contexteCourant();
    const enAttente = (await table.where('is_synced').equals(EN_ATTENTE).toArray())
        .filter(s => poussableIci(s, contexte));

    if (enAttente.length === 0) return;

    console.log(`📤 Moteur de synchro : ${enAttente.length} ${libelle}(s) en attente...`);

    for (const saisie of enAttente) {
        let statut = 0;
        let corps = {};

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(corpsAEnvoyer(saisie))
            });
            statut = response.status;
            corps = await response.json().catch(() => ({}));
        } catch (error) {
            // Réseau tombé : statut 0, on réessaiera au prochain retour en ligne.
            console.error(`❌ Erreur réseau sur ${libelle}:`, error);
        }

        const issue = await rangerSaisie(table, saisie.uuid, statut, corps);

        if (issue.etat === 'refuse') {
            console.warn(`⚠️ ${libelle} refusée par le serveur : ${issue.motif}`);
        }
    }
}

/** Une saisie refusée sort de la file, mais reste — avec son motif. */
async function marquerRefusee(table, uuid, motif) {
    console.warn(`⚠️ Saisie refusée par le serveur : ${motif}`);
    await table.update(uuid, { is_synced: REFUSEE, refus_motif: motif, refus_le: new Date().toISOString() });
}

/** Les files de saisies hors-ligne, dans l'ordre de synchronisation. */
const FILES = [
    { table: () => db.daily_checks,    url: '/api/sync/daily-checks',    libelle: 'pointage' },
    { table: () => db.egg_productions, url: '/api/sync/egg-collections', libelle: 'collecte d’œufs' },
    { table: () => db.stock_movements, url: '/api/sync/stock-movements', libelle: 'mouvement de stock' },
    { table: () => db.sales,           url: '/api/sync/sales',           libelle: 'vente' },
    { table: () => db.expenses,        url: '/api/sync/expenses',        libelle: 'dépense' },
];

/**
 * LE BANDEAU DES SAISIES REFUSÉES.
 *
 * Sans lui, un refus restait une ligne de console. L'opérateur croyait sa
 * sortie de stock ou sa vente enregistrée. Il voit désormais ce qui a été
 * refusé et pourquoi, et retire lui-même chaque ligne une fois traitée.
 */
async function afficherRefus() {
    const refus = [];
    let enAttenteAilleurs = 0;
    const contexte = contexteCourant();
    for (const { table, libelle } of [{ table: () => db.batches, libelle: 'lot' }, ...FILES]) {
        const lignes = await table().where('is_synced').equals(REFUSEE).toArray();
        lignes.forEach(l => refus.push({ table: table(), uuid: l.uuid, libelle, motif: l.refus_motif }));

        if (contexte.auteur_id) {
            enAttenteAilleurs += (await table().where('is_synced').equals(EN_ATTENTE).toArray())
                .filter(l => !poussableIci(l, contexte)).length;
        }
    }

    document.getElementById('saisies-refusees')?.remove();
    if (refus.length === 0 && enAttenteAilleurs === 0) return;

    const bandeau = document.createElement('div');
    bandeau.id = 'saisies-refusees';
    bandeau.setAttribute('role', 'alert');
    bandeau.style.cssText = 'position:fixed;bottom:1rem;left:1rem;right:1rem;z-index:9999;max-width:40rem;margin:auto;'
        + (refus.length ? 'background:#fff1f2;border:2px solid #f43f5e;' : 'background:#fff7ed;border:2px solid #fb923c;') + 'border-radius:1rem;padding:1rem;font-size:0.85rem;'
        + 'box-shadow:0 10px 25px rgba(0,0,0,.15);max-height:50vh;overflow:auto;';

    // Gardées, pas perdues : on dit pourquoi elles ne partent pas.
    if (enAttenteAilleurs > 0) {
        const attente = document.createElement('p');
        attente.id = 'saisies-autre-compte';
        attente.style.cssText = 'margin:0 0 .5rem;color:#9a3412;';
        attente.textContent = traduire(':n saisie(s) hors-ligne d’un autre compte ou d’un autre site attendent sur ce navigateur. Elles partiront quand leur auteur se reconnectera sur le site où il les a saisies.', { n: enAttenteAilleurs });
        bandeau.appendChild(attente);
    }

    if (refus.length > 0) {
        const titre = document.createElement('strong');
        titre.textContent = traduire(':n saisie(s) hors-ligne refusée(s) par le serveur — à ressaisir ou à corriger :', { n: refus.length });
        bandeau.appendChild(titre);
    }

    const liste = document.createElement('ul');
    liste.style.cssText = 'margin:.5rem 0 0;padding:0;list-style:none;';
    for (const r of refus) {
        const item = document.createElement('li');
        item.style.cssText = 'display:flex;justify-content:space-between;gap:.5rem;padding:.25rem 0;border-top:1px solid #fecdd3;';
        const texte = document.createElement('span');
        texte.textContent = `${traduire(r.libelle)} — ${r.motif || traduire('refusée')}`;   // textContent : le motif vient du serveur, jamais injecté en HTML
        const retirer = document.createElement('button');
        retirer.type = 'button';
        retirer.textContent = traduire('Compris, retirer');
        retirer.style.cssText = 'white-space:nowrap;font-weight:700;color:#be123c;background:none;border:none;cursor:pointer;';
        retirer.addEventListener('click', async () => { await r.table.delete(r.uuid); afficherRefus(); });
        item.append(texte, retirer);
        liste.appendChild(item);
    }
    bandeau.appendChild(liste);
    document.body.appendChild(bandeau);
}

/**
 * Orchestrateur Principal de Synchronisation (Exporté globalement)
 */
export async function syncData() {
    try {
        // Ordre strict : d'abord les parents (lots), puis les enfants (pointages, collectes)
        await syncBatches();
        for (const { table, url, libelle } of FILES) {
            await pousserFile(table(), url, libelle);
        }
    } catch (globalError) {
        console.error("❌ Erreur critique dans le cycle de synchronisation :", globalError);
    }

    // Rafraîchit le miroir local (référentiels + lots) une fois la file vidée.
    await refreshLocalData();

    // Ce qui a été refusé doit SE VOIR — cf. sync-outcome.js.
    try { await afficherRefus(); } catch (e) { console.error(e); }

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
