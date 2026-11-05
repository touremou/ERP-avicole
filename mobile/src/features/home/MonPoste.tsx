/**
 * MON POSTE — les gestes du métier en tête de l'accueil (offline/postes.ts).
 *
 * Décision de l'exploitation : la personne connectée voit d'abord ce qu'elle
 * fait chaque jour, selon son profil — pas l'inventaire de tout ce que
 * l'application sait faire. Le « + » garde l'accès à toutes ses saisies.
 *
 * À la première connexion sur l'appareil, un mot d'accueil nomme l'espace et
 * dit où trouver le reste ; il ne revient pas une fois lu.
 *
 * « Comment retrouver le reste ? » : le lien « Tout voir » et l'onglet « Menu »
 * mènent à TOUS les écrans du profil (/menu). Un profil large (gérant,
 * direction) peut aussi AFFICHER le poste d'un autre métier — la vue change,
 * pas les droits : chaque tuile reste filtrée par le contrôle des routes. Le
 * choix est retenu par compte sur l'appareil (téléphones partagés).
 */
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../app/AuthContext'
import { accessForPath, allows } from '../../offline/access'
import { espaceDeLaSession, espacesAffichables, estEspace, LIBELLES_ESPACES, tuilesDuPoste, type Espace } from '../../offline/postes'
import { t } from '../../i18n'

const CLE_ACCUEIL = 'mon-poste:accueil-lu:'
const CLE_VUE = 'mon-poste:vue:'

function accueilDejaLu(userId: number | undefined): boolean {
  try {
    return userId !== undefined && localStorage.getItem(CLE_ACCUEIL + userId) === '1'
  } catch {
    return true   // stockage indisponible : on ne répète pas le mot d'accueil à chaque écran
  }
}

function vueRetenue(userId: number | undefined): Espace | null {
  try {
    const vue = userId === undefined ? null : localStorage.getItem(CLE_VUE + userId)
    return estEspace(vue) ? vue : null
  } catch {
    return null
  }
}

export function MonPoste() {
  const { me, can } = useAuth()
  const userId = me?.user.id
  const [accueilLu, setAccueilLu] = useState(() => accueilDejaLu(userId))
  const [vue, setVue] = useState<Espace | null>(() => vueRetenue(userId))

  const ctx = { can, hasEmployee: me?.scope.employee_id != null }
  const peutOuvrir = (chemin: string) => {
    const spec = accessForPath(chemin)
    return spec !== null && allows(spec, ctx)
  }

  const espaceDuProfil = espaceDeLaSession(me?.role.workspace, can)
  const choix = espacesAffichables(peutOuvrir, espaceDuProfil)

  // Une vue retenue que les droits n'ouvrent plus (profil changé) : retour au poste du profil.
  const espace = vue !== null && choix.includes(vue) ? vue : espaceDuProfil
  const tuiles = tuilesDuPoste(espace, peutOuvrir)

  const libelleDuProfil = me?.role.workspace_label ?? me?.role.label ?? ''
  const libelle = espace === espaceDuProfil ? libelleDuProfil : t(LIBELLES_ESPACES[espace])

  function fermerAccueil() {
    try {
      if (userId !== undefined) localStorage.setItem(CLE_ACCUEIL + userId, '1')
    } catch {
      /* stockage indisponible : le mot d'accueil se refermera pour cette session */
    }
    setAccueilLu(true)
  }

  function changerDeVue(valeur: string) {
    const nouvelle = estEspace(valeur) && valeur !== espaceDuProfil ? valeur : null
    try {
      if (userId !== undefined) {
        if (nouvelle === null) localStorage.removeItem(CLE_VUE + userId)
        else localStorage.setItem(CLE_VUE + userId, nouvelle)
      }
    } catch {
      /* stockage indisponible : la vue vaut pour cette session */
    }
    setVue(nouvelle)
  }

  return (
    <section className="mon-poste">
      {!accueilLu && (
        <div className="poste-accueil">
          <p>
            <b>{t('Bienvenue dans votre espace :espace.', { espace: libelleDuProfil })}</b>{' '}
            {t('Vos gestes du quotidien sont ci-dessous. Tous vos autres écrans sont dans « Menu », en bas ; le bouton + ouvre toutes vos saisies.')}
          </p>
          <button type="button" className="poste-accueil__ok" onClick={fermerAccueil}>{t('Compris')}</button>
        </div>
      )}

      <div className="section-head">
        <h3>{t('Mon poste')}</h3>
        {libelle && <span className="section-link">{libelle}</span>}
      </div>

      {choix.length > 1 && (
        <label className="poste-vue">
          {t('Vue')}
          <select value={espace} onChange={(e) => changerDeVue(e.target.value)}>
            {choix.map((option) => (
              <option key={option} value={option}>
                {option === espaceDuProfil
                  ? t(':espace (mon profil)', { espace: libelleDuProfil || t(LIBELLES_ESPACES[option]) })
                  : t(LIBELLES_ESPACES[option])}
              </option>
            ))}
          </select>
        </label>
      )}

      {tuiles.length > 0 && (
        <div className="poste-grille">
          {tuiles.map((tuile) => (
            <Link key={tuile.to} to={tuile.to} className="poste-tuile">
              <span className="poste-tuile__icone" aria-hidden="true">{tuile.icon}</span>
              <span className="poste-tuile__titre">{t(tuile.label)}</span>
              <span className="poste-tuile__aide">{t(tuile.hint)}</span>
            </Link>
          ))}
        </div>
      )}

      <Link to="/menu" className="poste-tout-voir">{t('Tout voir : tous mes écrans')} →</Link>
    </section>
  )
}
