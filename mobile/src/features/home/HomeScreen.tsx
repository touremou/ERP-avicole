/**
 * Home par rôle — la doctrine « organisé par tâche du moment » (RFC §1) :
 * on ne montre PAS un menu de modules, mais les actions du jour permises
 * par la matrice de permissions mise en cache (gate hors-ligne) :
 * pointage pour tous les lots actifs, collecte pour les lots en ponte.
 */
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../app/AuthContext'
import { db } from '../../offline/db'
import { onSyncChange } from '../../offline/sync'
import { t, dateLocale } from '../../i18n'
import type { RefBatch, RefCropCycle, RefMillProduction, RefSlaughterOrder } from '../../api/types'

export function HomeScreen() {
  const { me, can } = useAuth()
  const [batches, setBatches] = useState<RefBatch[]>([])
  const [ponteIds, setPonteIds] = useState<Set<number>>(new Set())
  const [doneToday, setDoneToday] = useState<{ checks: Set<number>; eggs: Set<number> }>({
    checks: new Set(),
    eggs: new Set(),
  })
  // Phase 3 : cycles de culture en cours, ordres d'abattage planifiés,
  // OP provenderie ouverts — masqués dès qu'une saisie locale les traite.
  const [cropCycles, setCropCycles] = useState<RefCropCycle[]>([])
  const [slaughterOrders, setSlaughterOrders] = useState<RefSlaughterOrder[]>([])
  const [millProductions, setMillProductions] = useState<RefMillProduction[]>([])

  useEffect(() => {
    const load = async () => {
      const active = await db.ref_batches.where('status').equals('Actif').toArray()
      setBatches(active)

      const ponteTypes = await db.ref_production_types.where('slug').equals('ponte').toArray()
      const ponteTypeIds = new Set(ponteTypes.map((t) => t.id))
      setPonteIds(
        new Set(
          active
            .filter((b) => b.production_type_id && ponteTypeIds.has(b.production_type_id))
            .map((b) => b.id),
        ),
      )

      // Saisies locales du jour (synced ou non) : masquent les tâches faites.
      const today = new Date().toISOString().slice(0, 10)
      const records = await db.my_records.toArray()
      const checks = new Set<number>()
      const eggs = new Set<number>()
      const executedOrders = new Set<number>()
      const completedOps = new Set<number>()
      for (const record of records) {
        const payload = record.payload as {
          batch_id?: number
          check_date?: string
          production_date?: string
          slaughter_order_id?: number
          mill_production_id?: number
        }
        if (record.type === 'daily_check.create' && payload.batch_id && payload.check_date === today) checks.add(payload.batch_id)
        if (record.type === 'egg_collection.create' && payload.batch_id && payload.production_date === today) eggs.add(payload.batch_id)
        // Un ordre/OP traité localement disparaît de la home avant même que
        // le pull ne rapatrie son nouveau statut serveur.
        if (record.type === 'slaughter.execute' && payload.slaughter_order_id) executedOrders.add(payload.slaughter_order_id)
        if (record.type === 'mill_production.complete' && payload.mill_production_id) completedOps.add(payload.mill_production_id)
      }
      setDoneToday({ checks, eggs })

      // Phase 3 — seules les entités « à traiter » remontent en tâches.
      setCropCycles(await db.ref_crop_cycles.where('status').anyOf('en_cours', 'recolte').toArray())
      const orders = await db.ref_slaughter_orders.where('status').equals('planifie').toArray()
      setSlaughterOrders(orders.filter((o) => !executedOrders.has(o.id)))
      const ops = await db.ref_mill_productions.where('status').anyOf('Planifié', 'En cours').toArray()
      setMillProductions(ops.filter((op) => !completedOps.has(op.id)))
    }

    void load()
    // Se recharge à chaque cycle de sync : au premier login, le pull qui
    // rapatrie les lots aboutit APRÈS le montage de la home.
    return onSyncChange(() => void load())
  }, [])

  const checksTodo = batches.filter((b) => !doneToday.checks.has(b.id))
  const eggsTodo = batches.filter((b) => ponteIds.has(b.id) && !doneToday.eggs.has(b.id))
  const canElevage = can('elevage', 'C')
  const canProduction = can('production', 'C')
  const canCultures = can('cultures', 'C')
  const canAbattoir = can('abattoir', 'M')
  const abattoirC = can('abattoir', 'C')
  const canProvenderie = can('provenderie', 'M')
  const nothingTodo =
    (!canElevage || checksTodo.length === 0) &&
    (!canProduction || eggsTodo.length === 0) &&
    (!canAbattoir || slaughterOrders.length === 0) &&
    (!canProvenderie || millProductions.length === 0)

  // Ruban KPI adaptatif : on ne montre que les tuiles pertinentes au rôle.
  const totalTodo =
    (canElevage ? checksTodo.length : 0) +
    (canProduction ? eggsTodo.length : 0) +
    (canAbattoir ? slaughterOrders.length : 0) +
    (canProvenderie ? millProductions.length : 0) +
    (canCultures ? cropCycles.length : 0)
  const kpis: { val: number | string; lab: string }[] = []
  kpis.push({ val: totalTodo, lab: t('À faire') })
  if (canElevage || canProduction) kpis.push({ val: batches.length, lab: t('Lots actifs') })
  if (canAbattoir) kpis.push({ val: slaughterOrders.length, lab: t('Abattages') })
  else if (canProvenderie) kpis.push({ val: millProductions.length, lab: t('OP') })
  else if (canCultures) kpis.push({ val: cropCycles.length, lab: t('Cultures') })

  const today = new Date()
  const dateLabel = today.toLocaleDateString(dateLocale(), { weekday: 'long', day: 'numeric', month: 'long' })

  return (
    <div className="screen">
      <div className="welcome">
        <span className="welcome-eyebrow">{dateLabel}</span>
        <h2>{t('Bonjour')} {me?.user.name?.split(' ')[0]} 👋</h2>
        <span className="welcome-sub">
          {totalTodo > 0
            ? t(':count tâche(s) à traiter aujourd’hui', { count: totalTodo })
            : t('Rien d’urgent — bonne journée sur le terrain.')}
        </span>
      </div>

      {kpis.length >= 2 && (
        <div className="kpi-row" style={{ gridTemplateColumns: `repeat(${kpis.length}, 1fr)` }}>
          {kpis.map((k) => (
            <div className="kpi" key={k.lab}>
              <div className="kpi-val">{k.val}</div>
              <div className="kpi-lab">{k.lab}</div>
            </div>
          ))}
        </div>
      )}

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

      {(can('commerce', 'C') || can('logistique', 'M') || can('depenses', 'C') || abattoirC) && (
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
            </>
          )}
        </section>
      )}

      {nothingTodo && batches.length > 0 && (
        <p className="success">✓ {t("Tout est à jour pour aujourd'hui.")}</p>
      )}
      {batches.length === 0 && (
        <p className="muted">
          {t('Aucun lot local — la synchronisation les rapatriera au premier passage réseau.')}
        </p>
      )}
      {!canElevage && !canProduction && !canCultures && !canAbattoir && !canProvenderie && (
        <p className="muted">
          {t("Votre rôle n'a pas encore d'action terrain dans cette version — consultez « Mon espace ».")}
        </p>
      )}

      {/* Dégagement pour que la dernière carte ne passe pas sous le FAB. */}
      <div style={{ height: 64 }} aria-hidden="true" />
    </div>
  )
}
