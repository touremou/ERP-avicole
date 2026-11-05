/**
 * TOUS MES ÉCRANS — le menu complet du terrain, par rubrique, avec recherche.
 *
 * Demande de l'exploitation : « mon profil s'affiche à la connexion et change
 * de menu, comment retrouver facilement ? ». « Mon poste » met en avant les
 * gestes du métier ; cet écran garde TOUT le reste à portée : chaque écran que
 * le profil peut ouvrir (offline/postes.ts, CATALOGUE), et rien de ce qu'il
 * ne peut pas — pas de lien qui mène à « Accès refusé ».
 */
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../app/AuthContext'
import { accessForPath, allows } from '../../offline/access'
import { catalogueOuvert } from '../../offline/postes'
import { t } from '../../i18n'

export function TousMesEcransScreen() {
  const { me, can } = useAuth()
  const [recherche, setRecherche] = useState('')

  const ctx = { can, hasEmployee: me?.scope.employee_id != null }
  const rubriques = catalogueOuvert((chemin) => {
    const spec = accessForPath(chemin)
    return spec !== null && allows(spec, ctx)
  }, recherche, (cle) => t(cle))

  return (
    <div className="screen">
      <h2>{t('Tous mes écrans')}</h2>
      <p className="muted">
        {t('Tout ce que votre profil :profil peut ouvrir, par rubrique.', { profil: me?.role.label ?? '' })}
      </p>

      <input
        type="search"
        className="menu-recherche"
        value={recherche}
        onChange={(e) => setRecherche(e.target.value)}
        placeholder={t('Rechercher un écran…')}
        aria-label={t('Rechercher un écran…')}
        autoComplete="off"
      />

      {rubriques.length === 0 && (
        <p className="muted">{recherche ? t('Aucun écran ne correspond.') : t('Aucun écran n’est ouvert à votre profil. Demandez vos droits à l’administrateur.')}</p>
      )}

      {rubriques.map((rubrique) => (
        <section key={rubrique.titre} className="menu-rubrique">
          <div className="section-head">
            <h3>{t(rubrique.titre)}</h3>
            <span className="section-count">{rubrique.tuiles.length}</span>
          </div>
          {rubrique.tuiles.map((tuile) => (
            <Link key={tuile.to} to={tuile.to} className="task-card">
              <span className="menu-icone" aria-hidden="true">{tuile.icon}</span>
              <span className="menu-texte">
                <span className="task-title">{t(tuile.label)}</span>
                <span className="menu-aide">{t(tuile.hint)}</span>
              </span>
            </Link>
          ))}
        </section>
      ))}
    </div>
  )
}
