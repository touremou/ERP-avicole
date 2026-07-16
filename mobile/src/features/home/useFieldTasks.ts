/**
 * useFieldTasks — source unique des « tâches du jour » terrain, partagée par
 * le tableau de bord (accueil, en lecture agrégée) et l'écran « Nouvelle
 * saisie » (cible du bouton +, en lanceurs de formulaires). Doctrine RFC §1 :
 * on ne liste que ce qui reste À FAIRE, calculé hors-ligne depuis le miroir
 * Dexie et les saisies locales du jour.
 */
import { useEffect, useState } from 'react'
import { useAuth } from '../../app/AuthContext'
import { db } from '../../offline/db'
import { onSyncChange } from '../../offline/sync'
import type { RefBatch, RefCropCycle, RefMillProduction, RefSlaughterOrder } from '../../api/types'

export interface FieldTasks {
  batches: RefBatch[]
  checksTodo: RefBatch[]
  eggsTodo: RefBatch[]
  slaughterOrders: RefSlaughterOrder[]
  millProductions: RefMillProduction[]
  cropCycles: RefCropCycle[]
  savedToday: number
  can: ReturnType<typeof useAuth>['can']
}

export function useFieldTasks(): FieldTasks {
  const { can } = useAuth()
  const [batches, setBatches] = useState<RefBatch[]>([])
  const [ponteIds, setPonteIds] = useState<Set<number>>(new Set())
  const [doneToday, setDoneToday] = useState<{ checks: Set<number>; eggs: Set<number> }>({
    checks: new Set(),
    eggs: new Set(),
  })
  const [cropCycles, setCropCycles] = useState<RefCropCycle[]>([])
  const [slaughterOrders, setSlaughterOrders] = useState<RefSlaughterOrder[]>([])
  const [millProductions, setMillProductions] = useState<RefMillProduction[]>([])
  const [savedToday, setSavedToday] = useState(0)

  useEffect(() => {
    const load = async () => {
      const active = await db.ref_batches.where('status').equals('Actif').toArray()
      setBatches(active)

      const ponteTypes = await db.ref_production_types.where('slug').equals('ponte').toArray()
      const ponteTypeIds = new Set(ponteTypes.map((pt) => pt.id))
      setPonteIds(
        new Set(
          active
            .filter((b) => b.production_type_id && ponteTypeIds.has(b.production_type_id))
            .map((b) => b.id),
        ),
      )

      const today = new Date().toISOString().slice(0, 10)
      const records = await db.my_records.toArray()
      const checks = new Set<number>()
      const eggs = new Set<number>()
      const executedOrders = new Set<number>()
      const completedOps = new Set<number>()
      let savedCount = 0
      for (const record of records) {
        const payload = record.payload as {
          batch_id?: number
          check_date?: string
          production_date?: string
          slaughter_order_id?: number
          mill_production_id?: number
        }
        if (typeof record.created_at === 'string' && record.created_at.slice(0, 10) === today) savedCount++
        if (record.type === 'daily_check.create' && payload.batch_id && payload.check_date === today) checks.add(payload.batch_id)
        if (record.type === 'egg_collection.create' && payload.batch_id && payload.production_date === today) eggs.add(payload.batch_id)
        if (record.type === 'slaughter.execute' && payload.slaughter_order_id) executedOrders.add(payload.slaughter_order_id)
        if (record.type === 'mill_production.complete' && payload.mill_production_id) completedOps.add(payload.mill_production_id)
      }
      setDoneToday({ checks, eggs })
      setSavedToday(savedCount)

      setCropCycles(await db.ref_crop_cycles.where('status').anyOf('en_cours', 'recolte').toArray())
      const orders = await db.ref_slaughter_orders.where('status').equals('planifie').toArray()
      setSlaughterOrders(orders.filter((o) => !executedOrders.has(o.id)))
      const ops = await db.ref_mill_productions.where('status').anyOf('Planifié', 'En cours').toArray()
      setMillProductions(ops.filter((op) => !completedOps.has(op.id)))
    }

    void load()
    return onSyncChange(() => void load())
  }, [])

  const checksTodo = batches.filter((b) => !doneToday.checks.has(b.id))
  const eggsTodo = batches.filter((b) => ponteIds.has(b.id) && !doneToday.eggs.has(b.id))

  return { batches, checksTodo, eggsTodo, slaughterOrders, millProductions, cropCycles, savedToday, can }
}
