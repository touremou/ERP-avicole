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
import type { RefBatch } from '../../api/types'

export function HomeScreen() {
  const { me, can } = useAuth()
  const [batches, setBatches] = useState<RefBatch[]>([])
  const [ponteIds, setPonteIds] = useState<Set<number>>(new Set())
  const [doneToday, setDoneToday] = useState<{ checks: Set<number>; eggs: Set<number> }>({
    checks: new Set(),
    eggs: new Set(),
  })

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
      for (const record of records) {
        const payload = record.payload as {
          batch_id?: number
          check_date?: string
          production_date?: string
        }
        if (!payload.batch_id) continue
        if (record.type === 'daily_check.create' && payload.check_date === today) checks.add(payload.batch_id)
        if (record.type === 'egg_collection.create' && payload.production_date === today) eggs.add(payload.batch_id)
      }
      setDoneToday({ checks, eggs })
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
  const nothingTodo = (!canElevage || checksTodo.length === 0) && (!canProduction || eggsTodo.length === 0)

  return (
    <div className="screen">
      <h2>Bonjour {me?.user.name?.split(' ')[0]} 👋</h2>

      {(canElevage || canProduction) && (
        <Link to="/scan" className="task-card scan-card">
          <span className="task-title">📷 Scanner un lot</span>
          <span className="task-meta">QR de traçabilité</span>
        </Link>
      )}

      {canElevage && checksTodo.length > 0 && (
        <section>
          <h3>Pointages du jour</h3>
          {checksTodo.map((batch) => (
            <Link key={batch.id} to={`/elevage/pointage/${batch.id}`} className="task-card">
              <span className="task-title">Pointage — {batch.code}</span>
              <span className="task-meta">{batch.current_quantity} sujets</span>
            </Link>
          ))}
        </section>
      )}

      {canProduction && eggsTodo.length > 0 && (
        <section>
          <h3>Collectes d'œufs</h3>
          {eggsTodo.map((batch) => (
            <Link key={batch.id} to={`/elevage/collecte/${batch.id}`} className="task-card">
              <span className="task-title">🥚 Collecte — {batch.code}</span>
              <span className="task-meta">{batch.current_quantity} pondeuses</span>
            </Link>
          ))}
        </section>
      )}

      {(can('commerce', 'C') || can('logistique', 'M') || can('depenses', 'C')) && (
        <section>
          <h3>Actions rapides</h3>
          {can('commerce', 'C') && (
            <Link to="/commerce/vente" className="task-card">
              <span className="task-title">💰 Vente rapide</span>
              <span className="task-meta">brouillon</span>
            </Link>
          )}
          {can('logistique', 'M') && (
            <Link to="/logistique/mouvement" className="task-card">
              <span className="task-title">📦 Mouvement de stock</span>
              <span className="task-meta">entrée · sortie</span>
            </Link>
          )}
          {can('depenses', 'C') && (
            <Link to="/depenses/nouvelle" className="task-card">
              <span className="task-title">🧾 Dépense</span>
              <span className="task-meta">reçu photo</span>
            </Link>
          )}
        </section>
      )}

      {nothingTodo && batches.length > 0 && (
        <p className="success">✓ Tout est à jour pour aujourd'hui.</p>
      )}
      {batches.length === 0 && (
        <p className="muted">
          Aucun lot local — la synchronisation les rapatriera au premier passage réseau.
        </p>
      )}
      {!canElevage && !canProduction && (
        <p className="muted">
          Votre rôle n'a pas encore d'action terrain dans cette version — consultez « Mon espace ».
        </p>
      )}
    </div>
  )
}
