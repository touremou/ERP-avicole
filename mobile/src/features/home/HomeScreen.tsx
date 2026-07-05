/**
 * Home par rôle — la doctrine « organisé par tâche du moment » (RFC §1) :
 * on ne montre PAS un menu de modules, mais les actions du jour permises
 * par la matrice de permissions mise en cache (gate hors-ligne).
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
  const [pointedToday, setPointedToday] = useState<Set<number>>(new Set())

  useEffect(() => {
    const load = async () => {
      const active = await db.ref_batches.where('status').equals('Actif').toArray()
      setBatches(active)

      // Lots déjà pointés aujourd'hui (saisies locales, synced ou non).
      const today = new Date().toISOString().slice(0, 10)
      const records = await db.my_records.where('type').equals('daily_check.create').toArray()
      const done = new Set<number>()
      for (const record of records) {
        const payload = record.payload as { batch_id?: number; check_date?: string }
        if (payload.check_date === today && payload.batch_id) done.add(payload.batch_id)
      }
      setPointedToday(done)
    }

    void load()
    // Se recharge à chaque cycle de sync : au premier login, le pull qui
    // rapatrie les lots aboutit APRÈS le montage de la home.
    return onSyncChange(() => void load())
  }, [])

  const todo = batches.filter((b) => !pointedToday.has(b.id))

  return (
    <div className="screen">
      <h2>Bonjour {me?.user.name?.split(' ')[0]} 👋</h2>

      {can('elevage', 'C') && (
        <section>
          <h3>À faire maintenant</h3>
          {todo.length === 0 && batches.length > 0 && (
            <p className="success">✓ Tous les lots sont pointés aujourd'hui.</p>
          )}
          {batches.length === 0 && (
            <p className="muted">
              Aucun lot local — la synchronisation les rapatriera au premier passage réseau.
            </p>
          )}
          {todo.map((batch) => (
            <Link key={batch.id} to={`/elevage/pointage/${batch.id}`} className="task-card">
              <span className="task-title">Pointage du jour — {batch.code}</span>
              <span className="task-meta">{batch.current_quantity} sujets</span>
            </Link>
          ))}
        </section>
      )}

      {!can('elevage', 'C') && (
        <p className="muted">
          Votre rôle n'a pas encore d'action terrain dans cette version — consultez « Mon espace ».
        </p>
      )}
    </div>
  )
}
