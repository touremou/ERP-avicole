/**
 * Fiche d'actions d'un lot — cible du scan QR et pivot des opérations
 * terrain : ce qu'on PEUT faire sur ce lot, selon son type de production et
 * les permissions en cache (gate hors-ligne).
 */
import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useAuth } from '../../app/AuthContext'
import { db } from '../../offline/db'
import { t } from '../../i18n'
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
        <p className="muted">{t("Lot introuvable en local — synchronisez d'abord.")}</p>
      </div>
    )
  }

  // Éligibilité collecte : booléen calculé serveur (âge/phase de ponte selon
  // la souche) ; repli sur le slug 'ponte' si le serveur ne l'a pas fourni.
  const canCollect = batch.can_collect_eggs ?? productionType?.slug === 'ponte'

  const ageDays = Math.max(
    0,
    Math.floor((Date.now() - new Date(batch.arrival_date).getTime()) / 86_400_000) + 1,
  )
  const ageWeeks = Math.floor(ageDays / 7)
  const mortalityRate =
    batch.initial_quantity > 0 ? (batch.qty_dead / batch.initial_quantity) * 100 : 0

  return (
    <div className="screen">
      <h2>{batch.code}</h2>
      <p className="muted">
        {productionType?.name_fr ?? t('Lot')} · {building?.name ?? t('Bâtiment #:id', { id: batch.building_id })}
      </p>

      <div className="kpi-row">
        <div className="kpi">
          <div className="kpi-val">{ageWeeks}</div>
          <div className="kpi-lab">{t(':days j · sem.', { days: ageDays })}</div>
        </div>
        <div className="kpi">
          <div className="kpi-val">{batch.current_quantity}</div>
          <div className="kpi-lab">{t('Effectif')}</div>
        </div>
        <div className="kpi">
          <div className="kpi-val">{mortalityRate.toFixed(1)}%</div>
          <div className="kpi-lab">{t('Mortalité')}</div>
        </div>
      </div>

      {can('elevage', 'C') && (
        <Link to={`/elevage/pointage/${batch.id}`} className="task-card">
          <span className="task-title">📋 {t('Pointage du jour')}</span>
          <span className="task-meta">{t('mortalité · aliment')}</span>
        </Link>
      )}

      {canCollect && can('production', 'C') && (
        <Link to={`/elevage/collecte/${batch.id}`} className="task-card">
          <span className="task-title">🥚 {t("Collecte d'œufs")}</span>
          <span className="task-meta">{t('par passage')}</span>
        </Link>
      )}

      {can('elevage', 'C') && (
        <Link to={`/elevage/incident/${batch.id}`} className="task-card">
          <span className="task-title">🩺 {t('Déclarer un incident')}</span>
          <span className="task-meta">{t('symptômes · photo')}</span>
        </Link>
      )}
    </div>
  )
}
