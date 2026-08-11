/**
 * MISE EN LOT DEPUIS LE TERRAIN, SANS RÉSEAU.
 *
 * Le serveur savait déjà recevoir l'arrivée d'un lot hors ligne : le type
 * d'opération `batch.upsert` existe, avec son validateur de 14 champs et sa
 * résolution de conflit (SyncService::batchUpsert). Mais AUCUN écran ne l'émettait.
 * Déclarer une arrivée exigeait donc un passage au bureau — et à Kérouané, sans
 * réseau, les chiffres du jour J se reconstituaient de mémoire des jours plus tard.
 *
 * ─── CE QUE CET ÉCRAN FAIT, ET CE QU'IL NE FAIT PAS ───
 *
 * IL CAPTURE l'arrivée sur place : bâtiment, effectif, date, fournisseur, prix
 * d'achat, mortalité de transport. Ces chiffres-là ne se retrouvent plus après.
 *
 * IL NE PERMET PAS d'enchaîner un pointage sur le lot avant la première synchro.
 * Toutes les autres opérations (pointage, collecte, incident) référencent le lot
 * par son IDENTIFIANT SERVEUR, qu'un lot créé hors ligne n'a pas encore. L'écran
 * le DIT à l'opérateur au lieu de le laisser buter : une saisie qui échoue en
 * silence est le défaut que cette exploitation a signalé le plus souvent.
 *
 * Contrat : SyncService::batchUpsert (gate elevage.C à la création).
 */
import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { NumberStepper } from '../../ui/NumberStepper'
import { t } from '../../i18n'
import type { RefBuilding, RefEmployee, RefProductionType, RefProvider } from '../../api/types'

/** Bâtiments capables d'accueillir une bande : libres, jamais occupés. */
const AVAILABLE_STATUSES = ['Vide', 'Disponible']

export function NewBatchScreen() {
  const navigate = useNavigate()

  const [buildings, setBuildings] = useState<RefBuilding[]>([])
  const [productionTypes, setProductionTypes] = useState<RefProductionType[]>([])
  const [employees, setEmployees] = useState<RefEmployee[]>([])
  const [providers, setProviders] = useState<RefProvider[]>([])

  const [buildingId, setBuildingId] = useState('')
  const [type, setType] = useState('')
  const [initialQuantity, setInitialQuantity] = useState(0)
  const [deadOnArrival, setDeadOnArrival] = useState(0)
  const [arrivalDate, setArrivalDate] = useState(new Date().toISOString().slice(0, 10))
  const [employeeId, setEmployeeId] = useState('')
  const [providerId, setProviderId] = useState('')
  const [buyPrice, setBuyPrice] = useState(0)
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    void (async () => {
      const [b, pt, emp, prov] = await Promise.all([
        db.ref_buildings.toArray(),
        db.ref_production_types.toArray(),
        db.ref_employees.toArray(),
        db.ref_providers.toArray(),
      ])

      setBuildings(b.filter((item) => AVAILABLE_STATUSES.includes(item.status)))
      setProductionTypes(pt)
      setEmployees(emp)
      setProviders(prov)
    })()
  }, [])

  /**
   * Code de bande proposé — modifiable. Le serveur upsert sur l'UUID, jamais sur
   * le code : deux appareils hors ligne ne peuvent donc pas s'écraser l'un
   * l'autre. Le code reste une étiquette lisible pour l'humain.
   */
  const suggestedCode = useMemo(() => {
    const slug = productionTypes.find((pt) => pt.slug === type)?.slug ?? 'lot'
    const stamp = arrivalDate.replaceAll('-', '').slice(2)

    return `${slug.slice(0, 3).toUpperCase()}-${stamp}`
  }, [type, arrivalDate, productionTypes])

  const [code, setCode] = useState('')
  const effectiveCode = code.trim() || suggestedCode

  const building = buildings.find((b) => String(b.id) === buildingId)

  // L'effectif ne peut pas dépasser la capacité du bâtiment : c'est une erreur de
  // saisie qui se paierait en densité, et le serveur ne la voit pas.
  const overCapacity = Boolean(building && initialQuantity > building.capacity)

  const valid =
    buildingId !== '' &&
    type !== '' &&
    initialQuantity > 0 &&
    deadOnArrival <= initialQuantity &&
    !overCapacity

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!valid) return

    const uuid = crypto.randomUUID()
    const now = new Date().toISOString()

    await enqueue(
      'batch.upsert',
      {
        uuid,
        code: effectiveCode,
        type,
        building_id: Number(buildingId),
        initial_quantity: initialQuantity,
        // L'effectif VIVANT à l'arrivée : les sujets morts au transport sont
        // déduits d'emblée, comme sur le web.
        current_quantity: initialQuantity - deadOnArrival,
        qty_dead: deadOnArrival,
        arrival_mortality_rate:
          initialQuantity > 0 ? Number(((deadOnArrival / initialQuantity) * 100).toFixed(2)) : 0,
        status: 'Actif',
        arrival_date: arrivalDate,
        employee_id: employeeId ? Number(employeeId) : null,
        provider_id: providerId ? Number(providerId) : null,
        buy_price_per_unit: buyPrice,
        updated_at: now,
      },
      t('Mise en lot :code', { code: effectiveCode }),
    )

    setSaved(true)
  }

  if (buildings.length === 0 || productionTypes.length === 0) {
    return (
      <div className="screen">
        <h2>🐣 {t('Mise en lot')}</h2>
        <p className="muted">
          {t(
            'Référentiels absents en local (bâtiments, types de production). Synchronisez une fois avec du réseau avant de déclarer une arrivée hors ligne.',
          )}
        </p>
      </div>
    )
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">✓ {t('Arrivée enregistrée')}</p>
        <p className="muted">
          {t('Lot :code — :qty sujets vivants.', {
            code: effectiveCode,
            qty: String(initialQuantity - deadOnArrival),
          })}
        </p>
        {/* La limite, dite franchement plutôt que découverte au premier pointage. */}
        <p className="muted">
          {t(
            'Les pointages sur ce lot seront possibles après la première synchronisation : le serveur doit d’abord lui attribuer son numéro.',
          )}
        </p>
        <button type="button" className="btn" onClick={() => navigate('/')}>
          {t('Retour')}
        </button>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>🐣 {t('Mise en lot')}</h2>

      <label htmlFor="type">{t('Type de production')}</label>
      <select id="type" value={type} onChange={(e) => setType(e.target.value)} required>
        <option value="">{t('— Choisir —')}</option>
        {productionTypes.map((pt) => (
          <option key={pt.id} value={pt.slug}>
            {pt.name_fr}
          </option>
        ))}
      </select>

      <label htmlFor="building">{t('Bâtiment')}</label>
      <select id="building" value={buildingId} onChange={(e) => setBuildingId(e.target.value)} required>
        <option value="">{t('— Choisir —')}</option>
        {buildings.map((b) => (
          <option key={b.id} value={b.id}>
            {b.name} ({t(':n places', { n: String(b.capacity) })})
          </option>
        ))}
      </select>

      <label htmlFor="code">{t('Code de la bande')}</label>
      <input
        id="code"
        value={code}
        onChange={(e) => setCode(e.target.value)}
        placeholder={suggestedCode}
        maxLength={50}
      />

      <NumberStepper
        label={t('Sujets reçus')}
        value={initialQuantity}
        onChange={setInitialQuantity}
        min={0}
        step={10}
      />

      {overCapacity && building && (
        <p className="warn">
          ⚠️ {t('Dépasse la capacité du bâtiment (:n places).', { n: String(building.capacity) })}
        </p>
      )}

      <NumberStepper
        label={t('Morts au transport')}
        value={deadOnArrival}
        onChange={setDeadOnArrival}
        min={0}
        max={initialQuantity}
      />

      <label htmlFor="arrival">{t('Date d’arrivée')}</label>
      <input
        id="arrival"
        type="date"
        value={arrivalDate}
        onChange={(e) => setArrivalDate(e.target.value)}
        required
      />

      <label htmlFor="provider">{t('Fournisseur')}</label>
      <select id="provider" value={providerId} onChange={(e) => setProviderId(e.target.value)}>
        <option value="">{t('— Non précisé —')}</option>
        {providers.map((p) => (
          <option key={p.id} value={p.id}>
            {p.name}
          </option>
        ))}
      </select>

      <NumberStepper
        label={t('Prix d’achat par sujet (GNF)')}
        value={buyPrice}
        onChange={setBuyPrice}
        min={0}
        step={500}
      />

      <label htmlFor="employee">{t('Responsable')}</label>
      <select id="employee" value={employeeId} onChange={(e) => setEmployeeId(e.target.value)}>
        <option value="">{t('— Non précisé —')}</option>
        {employees.map((emp) => (
          <option key={emp.id} value={emp.id}>
            {emp.first_name} {emp.last_name}
          </option>
        ))}
      </select>

      <button type="submit" className="btn-primary" disabled={!valid}>
        {t('Enregistrer l’arrivée')}
      </button>

      <p className="muted">
        {t(
          'Enregistrement hors ligne : la déclaration part dès le retour du réseau. Les pointages sur ce lot suivront après cette première synchronisation.',
        )}
      </p>
    </form>
  )
}
