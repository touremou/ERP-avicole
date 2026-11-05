/**
 * MON POSTE — les gestes du métier en tête de l'accueil (offline/postes.ts).
 *
 * Décision de l'exploitation : la personne connectée voit d'abord ce qu'elle
 * fait chaque jour, selon son profil — pas l'inventaire de tout ce que
 * l'application sait faire. Le « + » garde l'accès à toutes ses saisies.
 *
 * À la première connexion sur l'appareil, un mot d'accueil nomme l'espace et
 * dit où trouver le reste ; il ne revient pas une fois lu.
 */
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../app/AuthContext'
import { accessForPath, allows } from '../../offline/access'
import { espaceDeLaSession, tuilesDuPoste } from '../../offline/postes'
import { t } from '../../i18n'

const CLE_ACCUEIL = 'mon-poste:accueil-lu:'

function accueilDejaLu(userId: number | undefined): boolean {
  try {
    return userId !== undefined && localStorage.getItem(CLE_ACCUEIL + userId) === '1'
  } catch {
    return true   // stockage indisponible : on ne répète pas le mot d'accueil à chaque écran
  }
}

export function MonPoste() {
  const { me, can } = useAuth()
  const userId = me?.user.id
  const [accueilLu, setAccueilLu] = useState(() => accueilDejaLu(userId))

  const ctx = { can, hasEmployee: me?.scope.employee_id != null }
  const espace = espaceDeLaSession(me?.role.workspace, can)
  const tuiles = tuilesDuPoste(espace, (chemin) => {
    const spec = accessForPath(chemin)
    return spec !== null && allows(spec, ctx)
  })

  if (tuiles.length === 0) return null

  const libelle = me?.role.workspace_label ?? me?.role.label ?? ''

  function fermerAccueil() {
    try {
      if (userId !== undefined) localStorage.setItem(CLE_ACCUEIL + userId, '1')
    } catch {
      /* stockage indisponible : le mot d'accueil se refermera pour cette session */
    }
    setAccueilLu(true)
  }

  return (
    <section className="mon-poste">
      {!accueilLu && (
        <div className="poste-accueil">
          <p>
            <b>{t('Bienvenue dans votre espace :espace.', { espace: libelle })}</b>{' '}
            {t('Vos gestes du quotidien sont ci-dessous ; le bouton + donne accès à toutes vos saisies.')}
          </p>
          <button type="button" className="poste-accueil__ok" onClick={fermerAccueil}>{t('Compris')}</button>
        </div>
      )}

      <div className="section-head">
        <h3>{t('Mon poste')}</h3>
        {libelle && <span className="section-link">{libelle}</span>}
      </div>

      <div className="poste-grille">
        {tuiles.map((tuile) => (
          <Link key={tuile.to} to={tuile.to} className="poste-tuile">
            <span className="poste-tuile__icone" aria-hidden="true">{tuile.icon}</span>
            <span className="poste-tuile__titre">{t(tuile.label)}</span>
            <span className="poste-tuile__aide">{t(tuile.hint)}</span>
          </Link>
        ))}
      </div>
    </section>
  )
}
