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
const NOURRIR_BRUT = T('/elevage/nourrir-brut', '🌿', 'Nourrir (brut)', 'herbe · fanes · temps passé')
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

/** Les espaces qui encadrent plusieurs métiers : eux seuls peuvent regarder un autre poste. */
export const ESPACES_D_ENCADREMENT: Espace[] = ['direction', 'gerant']

/** Tuiles personnelles, ouvertes à tout compte : elles ne font pas un métier. */
const PERSONNELLES = new Set([TACHES.to, SAISIES.to, SEMAINE.to])

export const POSTES: Record<Espace, Tuile[]> = {
  terrain: [POINTAGE, NOURRIR_BRUT, SCANNER, SOIN, TRAITE, RELEVE, TACHES],
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

/** Libellés des espaces (clés i18n) — miroir de ProfilsMetier::ESPACES. */
export const LIBELLES_ESPACES: Record<Espace, string> = {
  direction: 'Direction',
  gerant: 'Gérant de site',
  elevage: 'Technicien d’élevage',
  terrain: 'Agent de terrain',
  abattoir: 'Opérateur abattoir',
  provenderie: 'Agent provenderie',
  cultures: 'Technicien cultures',
  vente: 'Vendeur / caissier',
  magasin: 'Magasinier',
  finance: 'Comptable / trésorier',
  rh: 'Responsable RH',
}

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

/**
 * TOUS MES ÉCRANS — le catalogue complet, par rubrique (écran « /menu »).
 *
 * Demande de l'exploitation : avec « Mon poste », l'accueil change selon le
 * profil ; il faut pouvoir RETROUVER tout le reste facilement. Chaque écran
 * qui s'ouvre sans paramètre y figure (vérifié par mobile/tests/postes.test.mjs) ;
 * l'écran filtre par droits et cherche dans les libellés.
 */
export interface Rubrique {
  /** Clé i18n du titre. */
  titre: string
  tuiles: Tuile[]
}

export const CATALOGUE: Rubrique[] = [
  {
    titre: 'Élevage',
    tuiles: [
      POINTAGE, NOURRIR_BRUT, SCANNER, SOIN,
      T('/elevage/mise-en-lot', '🐔', 'Mise en lot', 'arrivée d’une bande'),
      T('/elevage/couvoir', '🐣', 'Couvoir', 'mirage · éclosion'),
      TRAITE,
    ],
  },
  {
    titre: 'Ventes et caisse',
    tuiles: [VENTE, ENCAISSER, T('/commerce/retour', '↩️', 'Retour de vente', 'avoir · restockage'), VENTES],
  },
  { titre: 'Argent', tuiles: [TRESORERIE, DEPENSE] },
  {
    titre: 'Stocks',
    tuiles: [
      STOCKS,
      T('/logistique/mouvement', '🔁', 'Mouvement de stock', 'entrée · sortie'),
      T('/logistique/inventaire', '🧮', 'Inventaire', 'comptage'),
      RECEPTION_ALIMENT,
      T('/logistique/conservation', '🗄️', 'Lots stockés', 'contrôle de conservation'),
    ],
  },
  {
    titre: 'Eau et énergie',
    tuiles: [RELEVE, T('/ressources/ravitaillement', '🚰', 'Ravitaillement de citerne', 'volume livré')],
  },
  {
    titre: 'Personnel',
    tuiles: [T('/rh/presence', '🕘', 'Présences', 'pointage du personnel'), AFFECTER],
  },
  {
    titre: 'Cultures',
    tuiles: [
      T('/cultures/semis', '🌱', 'Pointer un semis', 'parcelle · culture'),
      T('/cultures/transformation', '🏭', 'Atelier de transformation', 'séchage · mouture'),
      T('/cultures/journal', '📒', 'Journal des cultures', 'cycles · récoltes'),
    ],
  },
  {
    titre: 'Abattoir',
    tuiles: [
      T('/abattoir/reception', '🚚', 'Réception des animaux', 'arrivée · contrôle'),
      T('/abattoir/temperature', '🌡️', 'Relevé de température', 'une chambre'),
      T('/abattoir/temperature/tournee', '🌡️', 'Tournée des températures', 'chambres froides'),
      T('/abattoir/ccp', '🛡️', 'Point critique (CCP)', 'mesure · action corrective'),
      T('/abattoir/nettoyage', '🧽', 'Nettoyage', 'une zone'),
      T('/abattoir/nettoyage/tournee', '🧽', 'Tournée de nettoyage', 'zones · produits'),
      T('/abattoir/decoupe', '🔪', 'Découpe', 'pièces · rendement'),
      T('/abattoir/sousproduit', '♻️', 'Sous-produits', 'un enregistrement'),
      T('/abattoir/sousproduit/tournee', '♻️', 'Tournée des sous-produits', 'peaux · abats · plumes'),
      T('/abattoir/journal', '📒', 'Journal de l’abattoir', 'ordres · abattages'),
    ],
  },
  {
    titre: 'Provenderie',
    tuiles: [
      T('/provenderie/lancer', '🏭', 'Lancer une fabrication', 'formule · quantité'),
      T('/provenderie/journal', '📒', 'Journal de fabrication', 'OP en cours · clôturées'),
    ],
  },
  {
    titre: 'Mon espace',
    tuiles: [
      T('/', '🏠', 'Accueil', 'mon poste · indicateurs'),
      T('/nouvelle', '➕', 'Nouvelle saisie', 'toutes mes saisies'),
      TACHES,
      T('/alertes', '🔔', 'Alertes', 'notifications'),
      T('/mon-espace', '👤', 'Mon espace', 'profil · site · langue'),
      SEMAINE,
      T('/appareils', '📱', 'Mes appareils', 'téléphones connectés'),
    ],
  },
]

/** Sans accents ni casse : « reception » trouve « Réception ». */
const plat = (texte: string) => texte.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()

/**
 * Le catalogue que le profil peut ouvrir, filtré par une recherche (libellé,
 * précision ou rubrique, déjà traduits par `traduire`). Les rubriques vides
 * disparaissent.
 */
export function catalogueOuvert(
  peutOuvrir: (chemin: string) => boolean,
  recherche = '',
  traduire: (cle: string) => string = (cle) => cle,
): Rubrique[] {
  const cherche = plat(recherche.trim())

  return CATALOGUE
    .map((rubrique) => ({
      titre: rubrique.titre,
      tuiles: rubrique.tuiles.filter((tuile) => peutOuvrir(tuile.to) && (cherche === ''
        || plat(`${traduire(tuile.label)} ${traduire(tuile.hint)} ${traduire(rubrique.titre)}`).includes(cherche))),
    }))
    .filter((rubrique) => rubrique.tuiles.length > 0)
}

/**
 * Les vues que ce profil peut choisir à l'accueil : son propre poste, et
 * chaque autre poste qui a au moins deux tuiles ouvrables ET en apporte une
 * que le sien n'a pas. Sinon le choix ne ferait que remontrer les mêmes
 * gestes : un vendeur ne se voit pas proposer une vue « comptable » réduite à
 * ses propres « Encaisser » et « Journal des ventes ».
 */
export function espacesAffichables(peutOuvrir: (chemin: string) => boolean, espaceDuProfil?: Espace): Espace[] {
  // Seuls les gestes du MÉTIER comptent : tâches, semaine et saisies sont
  // ouverts à tous et figurent déjà dans le menu.
  const duMetier = (espace: Espace) => tuilesDuPoste(espace, peutOuvrir).filter((tuile) => !PERSONNELLES.has(tuile.to))
  const siens = new Set(espaceDuProfil ? duMetier(espaceDuProfil).map((tuile) => tuile.to) : [])

  // Seul l'ENCADREMENT change de vue. Un technicien d'élevage se voyait offrir
  // le poste « Magasinier » dès qu'il pouvait ouvrir deux écrans du stock : il
  // choisissait un métier qui n'est pas le sien. Les autres profils gardent
  // leur poste ; tout ce que leurs droits ouvrent reste dans le Menu.
  if (!espaceDuProfil || !ESPACES_D_ENCADREMENT.includes(espaceDuProfil)) {
    return espaceDuProfil ? [espaceDuProfil] : []
  }

  return ESPACES.filter((espace) => {
    if (espace === espaceDuProfil) return true
    const tuiles = duMetier(espace)
    return tuiles.length >= 2 && tuiles.some((tuile) => !siens.has(tuile.to))
  })
}
