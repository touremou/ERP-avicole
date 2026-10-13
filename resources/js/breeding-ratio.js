import { traduire } from './sync-outcome.js';

/**
 * PROPORTION DE REPRODUCTEURS : UNE RÈGLE, TROIS ÉCRANS.
 *
 * Création de bande, modification, planification : chacun calculait
 * « mâles / femelles × 100 » à sa façon, et seule la planification le
 * jugeait — sur la norme du POULET, pour toutes les espèces. La référence
 * vient désormais de l'espèce (Species::ratioReproducteurs, posée sur
 * l'option de l'espèce en `data-ratio`) et ce jugement est le seul.
 *
 * Fonction PURE (`node --test`).
 *
 * @param {number} males
 * @param {number} femelles
 * @param {{min:number,max:number,male:string,femelles:string}|null} ref
 * @returns {{etat:'attente'|'inconnu'|'aucun_male'|'ok'|'trop'|'peu', pourcentage:number|null, texte:string, conseil:string|null}}
 */
export function jugerRatio(males, femelles, ref) {
    const m = Math.max(0, Math.floor(Number(males) || 0));
    const f = Math.max(0, Math.floor(Number(femelles) || 0));

    if (f === 0) return { etat: 'attente', pourcentage: null, texte: traduire('En attente...'), conseil: null };

    const pourcentage = Math.round((m / f) * 1000) / 10;

    if (!ref) {
        return { etat: 'inconnu', pourcentage, texte: traduire('Pas de référence pour cette espèce'), conseil: null };
    }

    // On juge le NOMBRE DE MÂLES, pas le pourcentage. La référence se lit
    // « 1 bélier pour 25 à 40 brebis » ; en pourcentage (2,5–4 %), 1 bélier
    // pour 19 brebis faisait 5,3 % — « trop de mâles » — et le conseil
    // répétait la saisie : on ne peut pas avoir moins d'UN mâle.
    const { minMales, maxMales } = malesConseilles(f, ref);
    const reference = traduire('Référence : 1 :male pour :a à :b :femelles', {
        male: ref.male, a: arrondi(100 / ref.max), b: arrondi(100 / ref.min), femelles: ref.femelles,
    });
    const nombre = minMales === maxMales ? String(minMales) : traduire(':min à :max', { min: minMales, max: maxMales });
    const conseil = `${reference} — ${traduire('pour :f :femelles : :n :male(s)', { f, femelles: ref.femelles, n: nombre, male: ref.male })}`;

    if (m === 0) return { etat: 'aucun_male', pourcentage, texte: traduire('Aucun mâle'), conseil };
    if (m > maxMales) return { etat: 'trop', pourcentage, texte: traduire('Trop de mâles'), conseil };
    if (m < minMales) return { etat: 'peu', pourcentage, texte: traduire('Pas assez de mâles'), conseil };

    return { etat: 'ok', pourcentage, texte: traduire('Conforme'), conseil };
}

/**
 * Mâles conseillés pour `f` femelles : chaque mâle sert entre 100/max et
 * 100/min femelles (bélier : 25 à 40 brebis). Au moins un mâle.
 */
export function malesConseilles(femelles, ref) {
    const parMaleMax = 100 / ref.min;   // le plus de femelles qu'un mâle peut servir
    const parMaleMin = 100 / ref.max;   // le moins qu'il doit servir
    const minMales = Math.max(1, Math.ceil(femelles / parMaleMax - 1e-9));
    const maxMales = Math.max(minMales, Math.floor(femelles / parMaleMin + 1e-9));
    return { minMales, maxMales };
}

const arrondi = (n) => String(Math.round(n * 10) / 10).replace('.', ',');

/** « 1 bélier pour 2,5 brebis » — la lecture d'éleveur du même rapport. */
export function lectureEleveur(males, femelles, ref) {
    const m = Math.floor(Number(males) || 0);
    const f = Math.floor(Number(femelles) || 0);
    if (!ref || m <= 0 || f <= 0) return '';

    const parMale = Math.round((f / m) * 10) / 10;
    return traduire('1 :male pour :n :femelles', { male: ref.male, n: String(parMale).replace('.', ','), femelles: ref.femelles });
}

const COULEURS = {
    ok: 'bg-emerald-100 text-emerald-700',
    trop: 'bg-red-100 text-red-700',
    peu: 'bg-amber-100 text-amber-700',
    aucun_male: 'bg-red-100 text-red-700',
    inconnu: 'bg-slate-100 text-slate-500',
    attente: 'bg-slate-100 text-slate-400',
};

/**
 * Met à jour le bloc d'un écran : `#ratio_display`, `#ratio_reading`,
 * `#ratio_status`, `#ratio_advice` — ceux qui existent.
 */
export function afficherRatio(doc, males, femelles, ref) {
    const verdict = jugerRatio(males, femelles, ref);
    const el = (id) => doc.getElementById(id);

    if (el('ratio_display')) el('ratio_display').innerText = verdict.pourcentage === null ? '—' : `${String(verdict.pourcentage).replace('.', ',')} %`;
    if (el('ratio_reading')) el('ratio_reading').innerText = lectureEleveur(males, femelles, ref);
    // La référence de l'espèce s'affiche toujours — conforme ou non.
    if (el('ratio_advice')) el('ratio_advice').innerText = verdict.conseil ?? '';
    if (el('ratio_status')) {
        el('ratio_status').innerText = verdict.texte;
        el('ratio_status').className = `px-4 py-2 rounded-xl text-[9px] font-black uppercase italic tracking-widest ${COULEURS[verdict.etat]}`;
    }

    return verdict;
}
