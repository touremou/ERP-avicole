/**
 * Fiche d'actions d'un lot — cible du scan QR et pivot des opérations
 * terrain : ce qu'on PEUT faire sur ce lot, selon son type de production et
 * les permissions en cache (gate hors-ligne).
 */
import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useAuth } from '../../app/AuthContext'
import { db } from '../../offline/db'
import type { RefBatch, RefBuilding, RefProductionType } from '../../api/types'

export function BatchScreen() {
  const { batchId } = useParams()
  const { can } = useAuth()
  const [batch, setBatch] = useState<RefBatch | null>(null)
  const [building, setBuilding] = useState<RefBuilding | null>(null)
  const [productionType, setProductionType] = useState<RefProductionType | null>(null)

  useEffect(() => {
    void (async () => {
      const found = await db.ref_batches.get(Number(batchId))
      setBatch(found ?? null)
      if (found) {
        setBuilding((await db.ref_buildings.get(found.building_id)) ?? null)
        if (found.production_type_id) {
          setProductionType((await db.ref_production_types.get(found.production_type_id)) ?? null)
        }
      }
    })()
  }, [batchId])

  if (!batch) {
    return (
      <div className="screen">
        <p className="muted">Lot introuvable en local — synchronisez d'abord.</p>
      </div>
    )
  }

  const isPonte = productionType?.slug === 'ponte'

  return (
    <div className="screen">
      <h2>{batch.code}</h2>
      <p className="muted">
        {productionType?.name_fr ?? 'Lot'} · {building?.name ?? `Bâtiment #${batch.building_id}`} ·{' '}
        {batch.current_quantity} sujets
      </p>

      {can('elevage', 'C') && (
        <Link to={`/elevage/pointage/${batch.id}`} className="task-card">
          <span className="task-title">📋 Pointage du jour</span>
          <span className="task-meta">mortalité · aliment</span>
        </Link>
      )}

      {isPonte && can('production', 'C') && (
        <Link to={`/elevage/collecte/${batch.id}`} className="task-card">
          <span className="task-title">🥚 Collecte d'œufs</span>
          <span className="task-meta">par passage</span>
        </Link>
      )}

      {can('elevage', 'C') && (
        <Link to={`/elevage/incident/${batch.id}`} className="task-card">
          <span className="task-title">🩺 Déclarer un incident</span>
          <span className="task-meta">symptômes · photo</span>
        </Link>
      )}
    </div>
  )
}
