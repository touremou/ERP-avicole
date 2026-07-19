/**
 * Nouvelle saisie — cible du bouton « + ». Encastre TOUS les lanceurs de
 * formulaires terrain, filtrés par la matrice de permissions (hors-ligne).
 * Les formulaires contextuels (pointage, collecte, abattage, récolte, OP)
 * listent les entités éligibles ; les formulaires libres (température, CCP,
 * vente…) s'ouvrent directement. Libère l'accueil, devenu tableau de bord.
 */
import { Link } from 'react-router-dom'
import { t, dateLocale } from '../../i18n'
import { useFieldTasks } from '../home/useFieldTasks'

export function NouvelleSaisieScreen() {
  const { batches, checksTodo, eggsTodo, slaughterOrders, millProductions, cropCycles, can } = useFieldTasks()

  const canElevage = can('elevage', 'C')
  const canProduction = can('production', 'C')
  const canCultures = can('cultures', 'C')
  const canAbattoir = can('abattoir', 'M')
  const abattoirC = can('abattoir', 'C')
  const canProvenderie = can('provenderie', 'M')

  const hasContextual =
    (canElevage && checksTodo.length > 0) ||
    (canProduction && eggsTodo.length > 0) ||
    (canAbattoir && slaughterOrders.length > 0) ||
    (canProvenderie && millProductions.length > 0) ||
    (canCultures && cropCycles.length > 0)
  const canRessources = can('ressources', 'C')
  const hasQuick =
    can('commerce', 'C') || can('logistique', 'M') || can('depenses', 'C') || abattoirC || canRessources
  const nothing = !hasContextual && !hasQuick && !(canElevage || canProduction)

  return (
    <div className="screen">
      <h2>{t('Nouvelle saisie')}</h2>
      <p className="muted">{t('Choisissez ce que vous voulez enregistrer.')}</p>

      {(canElevage || canProduction) && (
        <Link to="/scan" className="task-card scan-card">
          <span className="task-title">📷 {t('Scanner un lot')}</span>
          <span className="task-meta">{t('QR de traçabilité')}</span>
        </Link>
      )}

      {canElevage && checksTodo.length > 0 && (
        <section>
          <div className="section-head"><h3>{t('Pointages du jour')}</h3><span className="section-count">{checksTodo.length}</span></div>
          {checksTodo.map((batch) => (
            <Link key={batch.id} to={`/elevage/pointage/${batch.id}`} className="task-card">
              <span className="task-title">{t('Pointage')} — {batch.code}</span>
              <span className="task-meta">{batch.current_quantity} {t('sujets')}</span>
            </Link>
          ))}
        </section>
      )}

      {canProduction && eggsTodo.length > 0 && (
        <section>
          <div className="section-head"><h3>{t("Collectes d'œufs")}</h3><span className="section-count">{eggsTodo.length}</span></div>
          {eggsTodo.map((batch) => (
            <Link key={batch.id} to={`/elevage/collecte/${batch.id}`} className="task-card">
              <span className="task-title">🥚 {t('Collecte')} — {batch.code}</span>
              <span className="task-meta">{batch.current_quantity} {t('pondeuses')}</span>
            </Link>
          ))}
        </section>
      )}

      {canAbattoir && slaughterOrders.length > 0 && (
        <section>
          <div className="section-head"><h3>{t('Abattages à exécuter')}</h3><span className="section-count">{slaughterOrders.length}</span></div>
          {slaughterOrders.map((order) => (
            <Link key={order.id} to={`/abattoir/execution/${order.id}`} className="task-card">
              <span className="task-title">🔪 {order.order_number}</span>
              <span className="task-meta">{order.planned_quantity} {t('sujets')} · {order.planned_date}</span>
            </Link>
          ))}
        </section>
      )}

      {canProvenderie && millProductions.length > 0 && (
        <section>
          <div className="section-head"><h3>{t('OP provenderie à clôturer')}</h3><span className="section-count">{millProductions.length}</span></div>
          {millProductions.map((op) => (
            <Link key={op.id} to={`/provenderie/cloture/${op.id}`} className="task-card">
              <span className="task-title">🏭 {op.batch_number}</span>
              <span className="task-meta">{Number(op.quantity_produced).toLocaleString(dateLocale())} kg · {op.status}</span>
            </Link>
          ))}
        </section>
      )}

      {canCultures && (
        <section>
          <h3>{t('Cultures')}</h3>
          <Link to="/cultures/semis" className="task-card">
            <span className="task-title">🌱 {t('Pointer un semis')}</span>
            <span className="task-meta">{t('Déclarer une nouvelle culture sur une parcelle')}</span>
          </Link>
        </section>
      )}

      {canCultures && cropCycles.length > 0 && (
        <section>
          <div className="section-head"><h3>{t('Cultures en cours')}</h3><span className="section-count">{cropCycles.length}</span></div>
          {cropCycles.map((cycle) => (
            <Link key={cycle.id} to={`/cultures/recolte/${cycle.id}`} className="task-card">
              <span className="task-title">🌾 {cycle.crop_name} — {cycle.code}</span>
              <span className="task-meta">{cycle.status === 'recolte' ? t('récolte en cours') : t('récolte · intrant')}</span>
            </Link>
          ))}
        </section>
      )}

      {hasQuick && (
        <section>
          <h3>{t('Actions rapides')}</h3>
          {can('commerce', 'C') && (
            <Link to="/commerce/vente" className="task-card">
              <span className="task-title">💰 {t('Vente rapide')}</span>
              <span className="task-meta">{t('brouillon')}</span>
            </Link>
          )}
          {can('logistique', 'M') && (
            <Link to="/logistique/mouvement" className="task-card">
              <span className="task-title">📦 {t('Mouvement de stock')}</span>
              <span className="task-meta">{t('entrée · sortie')}</span>
            </Link>
          )}
          {can('depenses', 'C') && (
            <Link to="/depenses/nouvelle" className="task-card">
              <span className="task-title">🧾 {t('Dépense')}</span>
              <span className="task-meta">{t('reçu photo')}</span>
            </Link>
          )}
          {canRessources && (
            <Link to="/ressources/ravitaillement" className="task-card">
              <span className="task-title">💧 {t('Ravitaillement citerne')}</span>
              <span className="task-meta">{t('appoint d’eau')}</span>
            </Link>
          )}
          {abattoirC && (
            <>
              <Link to="/abattoir/temperature" className="task-card">
                <span className="task-title">🌡️ {t('Relevé température')}</span>
                <span className="task-meta">{t('registre HACCP')}</span>
              </Link>
              <Link to="/abattoir/nettoyage" className="task-card">
                <span className="task-title">🧽 {t('Nettoyage')}</span>
                <span className="task-meta">{t('zone · produit')}</span>
              </Link>
              <Link to="/abattoir/reception" className="task-card">
                <span className="task-title">🚚 {t('Réception vif')}</span>
                <span className="task-meta">{t('contrôle ante-mortem')}</span>
              </Link>
              <Link to="/abattoir/ccp" className="task-card">
                <span className="task-title">📋 {t('Relevé CCP')}</span>
                <span className="task-meta">{t('points critiques')}</span>
              </Link>
              <Link to="/abattoir/sousproduit" className="task-card">
                <span className="task-title">♻️ {t('Sous-produit')}</span>
                <span className="task-meta">{t('sang · plumes · viscères')}</span>
              </Link>
              <Link to="/abattoir/decoupe" className="task-card">
                <span className="task-title">✂️ {t('Découpe')}</span>
                <span className="task-meta">{t('atelier de désassemblage')}</span>
              </Link>
            </>
          )}
        </section>
      )}

      {batches.length === 0 && (
        <p className="muted">
          {t('Aucun lot local — la synchronisation les rapatriera au premier passage réseau.')}
        </p>
      )}
      {nothing && batches.length > 0 && (
        <p className="muted">
          {t("Votre rôle n'a pas encore d'action terrain dans cette version — consultez « Mon espace ».")}
        </p>
      )}

      <div style={{ height: 24 }} aria-hidden="true" />
    </div>
  )
}
