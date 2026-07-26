/**
 * Écran de refus — ce que voit un agent qui atteint une page hors de ses
 * droits (URL en favori, retour d'historique, lien partagé).
 *
 * Volontairement SANS détail technique : on n'apprend pas au passage quels
 * modules existent. On dit ce qui se passe, et on renvoie au travail.
 */
import { Link } from 'react-router-dom'
import { t } from '../i18n'

export function AccessDenied() {
  return (
    <div className="screen">
      <h2>{t('Accès refusé')}</h2>
      <p className="muted">
        {t("Votre profil ne donne pas accès à cet écran. Demandez le droit correspondant à votre responsable si vous en avez besoin.")}
      </p>
      <Link to="/" className="task-card">
        <span className="task-title">🏠 {t("Retour à l'accueil")}</span>
      </Link>
    </div>
  )
}
