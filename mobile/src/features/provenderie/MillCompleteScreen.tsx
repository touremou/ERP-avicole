/**
 * Clôture d'OP provenderie — l'OP est créé au bureau (web) ; le meunier
 * clôture depuis le terrain une fois la production faite. La clôture
 * consomme les matières premières et crédite le silo d'aliment fini au
 * coût de revient — CES CONTRÔLES SONT SERVEUR (stock MP insuffisant,
 * machine en panne, MP sans prix → conflict → bac « À corriger »).
 * Contrat : SyncService::millProductionComplete (gate provenderie.M).
 */
import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import type { RefFormula, RefMillProduction } from '../../api/types'

export function MillCompleteScreen() {
  const { opId } = useParams()
  const navigate = useNavigate()

  const [production, setProduction] = useState<RefMillProduction | null>(null)
  const [formula, setFormula] = useState<RefFormula | null>(null)
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    if (!opId) return
    void db.ref_mill_productions.get(Number(opId)).then(async (found) => {
      setProduction(found ?? null)
      if (found?.formula_id) setFormula((await db.ref_formulas.get(found.formula_id)) ?? null)
    })
  }, [opId])

  async function onConfirm() {
    if (!production) return

    await enqueue(
      'mill_production.complete',
      { mill_production_id: production.id },
      `Clôture OP ${production.batch_number}`,
    )

    setSaved(true)
    setTimeout(() => navigate('/'), 900)
  }

  if (!production) {
    return (
      <div className="screen">
        <p className="muted">Ordre de production introuvable en local — synchronisez d'abord.</p>
      </div>
    )
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">✓ Clôture envoyée</p>
        <p className="muted">MP consommées et silo crédité après validation serveur.</p>
      </div>
    )
  }

  const alreadyDone = production.status === 'Terminé' || production.status === 'Annulé'

  return (
    <div className="screen">
      <h2>🏭 Clôturer l'OP {production.batch_number}</h2>
      <p className="muted">
        {formula ? `${formula.name} · ` : ''}
        {Number(production.quantity_produced).toLocaleString('fr-FR')} kg · statut : {production.status}
      </p>

      {alreadyDone ? (
        <p className="error">Cet OP est déjà « {production.status} » — rien à clôturer.</p>
      ) : (
        <>
          <p className="muted">
            La clôture déstocke les matières premières de la formule et crédite le silo d'aliment
            fini au coût de revient. En cas de stock MP insuffisant ou de machine en panne, le
            serveur refusera au push (bac « À corriger »).
          </p>
          <button type="button" className="btn-primary" onClick={() => void onConfirm()}>
            ✓ Confirmer la clôture
          </button>
        </>
      )}
    </div>
  )
}
