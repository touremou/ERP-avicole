/**
 * Lancement d'un OP au moulin — le meunier démarre la fabrication SUR PLACE
 * (jusqu'ici il ne pouvait que clôturer un OP planifié au bureau).
 * L'OP naît « Planifié » ; la consommation des matières et le coût réel sont
 * calculés à la clôture (mill_production.complete, déjà en place).
 * Contrat : SyncService::millProductionCreate (gate provenderie.C).
 */
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { lastPayloadOf } from '../../offline/prefill'
import { NumberStepper } from '../../ui/NumberStepper'
import { t } from '../../i18n'
import type { RefEmployee, RefFormula, RefMillMachine } from '../../api/types'

/** Miroir de UnitConverter::sacksToKg (1 sac = 50 kg). */
const KG_PER_BAG = 50

export function MillStartScreen() {
  const navigate = useNavigate()

  const [formulas, setFormulas] = useState<RefFormula[]>([])
  const [machines, setMachines] = useState<RefMillMachine[]>([])
  const [employees, setEmployees] = useState<RefEmployee[]>([])
  const [formulaId, setFormulaId] = useState('')
  const [machineIds, setMachineIds] = useState<number[]>([])
  const [nbBags, setNbBags] = useState(0)
  const [supervisorId, setSupervisorId] = useState('')
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    void db.ref_formulas.toArray().then(setFormulas)
    void db.ref_mill_machines.toArray().then(setMachines)
    void db.ref_employees.toArray().then(setEmployees)
  }, [])

  // Anti-corvée : on refabrique souvent la même formule avec les mêmes
  // machines et le même superviseur.
  useEffect(() => {
    void lastPayloadOf('mill_production.create', () => true).then((last) => {
      if (!last) return
      if (last.formula_id) setFormulaId(String(last.formula_id))
      if (last.supervisor_id) setSupervisorId(String(last.supervisor_id))
      if (Array.isArray(last.machine_ids)) setMachineIds(last.machine_ids as number[])
    })
  }, [])

  function toggleMachine(id: number) {
    setMachineIds((prev) => (prev.includes(id) ? prev.filter((m) => m !== id) : [...prev, id]))
  }

  const totalKg = nbBags * KG_PER_BAG
  const canSubmit = Boolean(formulaId) && Boolean(supervisorId) && machineIds.length > 0 && nbBags > 0

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!canSubmit) return

    const formula = formulas.find((f) => f.id === Number(formulaId))
    await enqueue(
      'mill_production.create',
      {
        formula_id: Number(formulaId),
        machine_ids: machineIds,
        nb_bags: nbBags,
        supervisor_id: Number(supervisorId),
      },
      t('OP moulin :formula — :n sacs', { formula: formula?.name ?? formulaId, n: nbBags }),
    )

    setSaved(true)
    setTimeout(() => navigate('/'), 1100)
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">{t('✓ OP lancée')}</p>
        <p className="muted">{t('L’occupation des machines sera re-vérifiée par le serveur au push ; clôturez l’OP après la fabrication.')}</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>🏭 {t('Lancer un OP au moulin')}</h2>

      <label htmlFor="formula">{t('Formule à fabriquer')}</label>
      <select id="formula" required value={formulaId} onChange={(e) => setFormulaId(e.target.value)}>
        <option value="">{t('Sélectionner…')}</option>
        {formulas.map((f) => (
          <option key={f.id} value={f.id}>{f.name}</option>
        ))}
      </select>
      {formulas.length === 0 && (
        <p className="muted">{t('Aucune formule en local — synchronisez d’abord.')}</p>
      )}

      <label>{t('Machine(s) engagée(s)')}</label>
      <div className="chip-row">
        {machines.map((m) => (
          <button
            key={m.id}
            type="button"
            className={`chip ${machineIds.includes(m.id) ? 'chip-on' : ''}`}
            onClick={() => toggleMachine(m.id)}
          >
            {m.name}
          </button>
        ))}
      </div>
      {machines.length === 0 && (
        <p className="muted">{t('Aucune machine en local — synchronisez d’abord.')}</p>
      )}
      <p className="muted">{t('Une machine déjà engagée sur un OP ouvert sera refusée au push.')}</p>

      <NumberStepper label={t('Nombre de sacs à produire')} value={nbBags} onChange={setNbBags} min={0} />
      {nbBags > 0 && (
        <p className="proof-hint">{t('Soit :kg kg planifiés', { kg: totalKg })}</p>
      )}

      <label htmlFor="supervisor">{t('Superviseur')}</label>
      <select id="supervisor" required value={supervisorId} onChange={(e) => setSupervisorId(e.target.value)}>
        <option value="">{t('Sélectionner…')}</option>
        {employees.map((e) => (
          <option key={e.id} value={e.id}>
            {[e.first_name, e.last_name].filter(Boolean).join(' ')}{e.job_title ? ` · ${e.job_title}` : ''}
          </option>
        ))}
      </select>
      {employees.length === 0 && (
        <p className="muted">{t('Aucun employé en local — synchronisez d’abord.')}</p>
      )}

      <button type="submit" className="btn-primary" disabled={!canSubmit}>
        {t('Lancer la fabrication')}
      </button>
    </form>
  )
}
