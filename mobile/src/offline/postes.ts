/**
 * MON POSTE — les gestes d'un métier, en tête de l'accueil terrain.
 * Fonctions PURES (mobile/tests/postes.test.mjs).
 *
 * Décision de l'exploitation : selon le profil connecté, l'application met en
 * avant ce que la personne fait chaque jour, pour qu'elle ne se perde pas
 * dans des écrans qui ne la concernent pas. L'espace vient du serveur
 * (me.role.workspace, ProfilsMetier) ; un serveur plus ancien ne l'envoie pas,
 * il se déduit alors des droits (miroir de ProfilsMetier::espaceDeduit).
 *
 * Une tuile n'apparaît QUE si son écran est ouvert au profil (le contrôle
 * des routes, access.ts, passé par l'écran) : un poste ne mène jamais à
 * « Accès refusé ».
 */
import type { AccessContext } from './access'

export type Espace =
  | 'direction' | 'gerant' | 'elevage' | 'terrain' | 'abattoir' | 'provenderie'
  | 'cultures' | 'vente' | 'magasin' | 'finance' | 'rh'

export interface Tuile {
  to: string
  icon: string
  /** Clé i18n du libellé. */
  label: string
  /** Clé i18n de la précision. */
  hint: string
}

const T = (to: string, icon: string, label: string, hint: string): Tuile => ({ to, icon, label, hint })

const POINTAGE = T('/elevage/pointage', '📋', 'Pointage du jour', 'mortalité · aliment')
const SCANNER = T('/scan', '📷', 'Scanner un lot', 'collecte · incident · fiche')
const SOIN = T('/elevage/soin', '💉', 'Soin / vaccination', 'produit · dose · délai d’attente')
const TRAITE = T('/elevage/traite', '🥛', 'Traite du jour', 'matin · soir')
const RELEVE = T('/ressources/releve', '💧', 'Relevé eau / énergie', 'compteurs · citernes')
const TACHES = T('/taches', '✅', 'Mes tâches', 'du jour et en retard')
const SAISIES = T('/nouvelle', '➕', 'Toutes mes saisies', 'collectes · récoltes · ordres')
const RECEPTION_ALIMENT = T('/logistique/reception-aliment', '🚚', 'Réception d’aliment', 'livraison · sacs · fournisseur')
const STOCKS = T('/logistique/stocks', '📦', 'Stocks', 'quantités · seuils')
const VENTE = T('/commerce/vente', '🧾', 'Nouvelle vente', 'client · articles')
const ENCAISSER = T('/commerce/encaisser', '💵', 'Encaisser', 'règlement d’une vente')
const VENTES = T('/commerce/journal', '📒', 'Journal des ventes', 'du jour · impayés')
const DEPENSE = T('/depenses/nouvelle', '💸', 'Nouvelle dépense', 'montant · justificatif')
const TRESORERIE = T('/tresorerie/journal', '🏦', 'Trésorerie', 'mouvements · soldes')
const AFFECTER = T('/taches/affecter', '🗂️', 'Affecter une tâche', 'à un agent ou au pool')
const SEMAINE = T('/mon-espace/semaine', '📊', 'Ma semaine', 'indicateurs · réalisé')

export const POSTES: Record<Espace, Tuile[]> = {
  terrain: [POINTAGE, SCANNER, SOIN, TRAITE, RELEVE, TACHES],
  elevage: [
    POINTAGE, SCANNER, SOIN,
    T('/elevage/couvoir', '🐣', 'Couvoir', 'mirage · éclosion'),
    T('/elevage/mise-en-lot', '🐔', 'Mise en lot', 'arrivée d’une bande'),
    RECEPTION_ALIMENT,
  ],
  abattoir: [
    T('/abattoir/reception', '🚚', 'Réception des animaux', 'arrivée · contrôle'),
    T('/abattoir/temperature/tournee', '🌡️', 'Tournée des températures', 'chambres froides'),
    T('/abattoir/ccp', '🛡️', 'Point critique (CCP)', 'mesure · action corrective'),
    T('/abattoir/nettoyage/tournee', '🧽', 'Tournée de nettoyage', 'zones · produits'),
    T('/abattoir/decoupe', '🔪', 'Découpe', 'pièces · rendement'),
    SAISIES,
  ],
  provenderie: [
    T('/provenderie/lancer', '🏭', 'Lancer une fabrication', 'formule · quantité'),
    RECEPTION_ALIMENT,
    T('/provenderie/journal', '📒', 'Journal de fabrication', 'OP en cours · clôturées'),
    STOCKS,
  ],
  cultures: [
    T('/cultures/semis', '🌱', 'Pointer un semis', 'parcelle · culture'),
    SAISIES,
    T('/cultures/transformation', '🏭', 'Atelier de transformation', 'séchage · mouture'),
    T('/cultures/journal', '📒', 'Journal des cultures', 'cycles · récoltes'),
    RELEVE,
  ],
  vente: [VENTE, ENCAISSER, VENTES, STOCKS],
  magasin: [
    T('/logistique/mouvement', '🔁', 'Mouvement de stock', 'entrée · sortie'),
    T('/logistique/inventaire', '🧮', 'Inventaire', 'comptage'),
    RECEPTION_ALIMENT,
    STOCKS,
    T('/logistique/conservation', '🗄️', 'Lots stockés', 'contrôle de conservation'),
  ],
  finance: [TRESORERIE, ENCAISSER, DEPENSE, VENTES],
  rh: [T('/rh/presence', '🕘', 'Présences', 'pointage du personnel'), AFFECTER, TACHES, SEMAINE],
  gerant: [SEMAINE, AFFECTER, POINTAGE, SCANNER, VENTES, STOCKS],
  direction: [SEMAINE, TRESORERIE, VENTES, STOCKS, AFFECTER, SCANNER],
}

export const ESPACES: Espace[] = Object.keys(POSTES) as Espace[]

export function estEspace(valeur: unknown): valeur is Espace {
  return typeof valeur === 'string' && (ESPACES as string[]).includes(valeur)
}

/**
 * L'espace DÉDUIT des droits — miroir de ProfilsMetier::espaceDeduit, pour un
 * serveur qui ne l'envoie pas encore.
 */
export function espaceDeduit(can: AccessContext['can']): Espace {
  if (can('admin', 'S')) return 'direction'

  const saisit = (module: string) => can(module, 'C')
  const operations = ['elevage', 'production', 'abattoir', 'provenderie', 'cultures', 'commerce', 'logistique'].filter(saisit)
  if (operations.length >= 4) return 'gerant'

  if (saisit('elevage') && can('elevage', 'M')) return 'elevage'
  if (saisit('elevage') || saisit('production')) return 'terrain'
  if (saisit('abattoir')) return 'abattoir'
  if (saisit('provenderie')) return 'provenderie'
  if (saisit('cultures')) return 'cultures'
  if (saisit('commerce') || saisit('caisse')) return 'vente'
  if (saisit('logistique')) return 'magasin'
  if (saisit('tresorerie') || saisit('depenses')) return 'finance'
  if (saisit('rh')) return 'rh'
  return 'terrain'
}

/** L'espace de la session : celui du serveur, sinon celui des droits. */
export function espaceDeLaSession(workspace: string | null | undefined, can: AccessContext['can']): Espace {
  return estEspace(workspace) ? workspace : espaceDeduit(can)
}

/**
 * Les tuiles du poste que le profil peut ouvrir, dans l'ordre du métier.
 * `peutOuvrir` : le contrôle des routes (accessForPath + allows, access.ts).
 */
export function tuilesDuPoste(espace: Espace, peutOuvrir: (chemin: string) => boolean): Tuile[] {
  return POSTES[espace].filter((tuile) => peutOuvrir(tuile.to))
}
