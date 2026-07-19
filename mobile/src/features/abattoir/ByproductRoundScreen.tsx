/**
 * Tournée de collecte des sous-produits — sang, plumes, viscères pesés sur un
 * seul écran (ordre d'abattage commun optionnel), une validation → une op de
 * sync PAR type pesé. L'écran unitaire reste disponible (notes, type autre).
 * Contrat : SyncService::byproductCreate (gate abattoir.C).
 */
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { t } from '../../i18n'
import type { RefSlaughterOrder } from '../../api/types'

/** Miroir de App\Models\SlaughterByproduct::TYPES / DESTINATIONS. */
const TYPES = [
  { value: 'sang', label: '🩸 Sang' },
  { value: 'plumes', label: '🪶 Plumes' },
  { value: 'visceres', label: '🫀 Viscères non comestibles' },
] as const

const DESTINATIONS = [
  { value: 'equarrissage', label: 'Équarrissage' },
  { value: 'vente', label: 'Vente' },
  { value: 'compost', label: 'Compost' },
  { value: 'dechets', label: 'Déchets' },
] as const

type Row = { kg: string; destination: string }

export function ByproductRoundScreen() {
  const navigate = useNavigate()
  const [orders, setOrders] = useState<RefSlaughterOrder[]>([])
  const [orderId, setOrderId] = useState('')
  const [rows, setRows] = useState<Record<string, Row>>(
    Object.fromEntries(TYPES.map((tp) => [tp.value, { kg: '', destination: 'equarrissage' }])),
  )
  const [saved, setSaved] = useState(0)

  useEffect(() => {
    void db.ref_slaughter_orders
      .where('status').anyOf('termine', 'en_cours', 'bloque')
      .reverse().sortBy('planned_date')
      .then((found) => setOrders(found.slice(0, 30)))
  }, [])

  function setRow(type: string, patch: Partial<Row>) {
    setRows((prev) => ({ ...prev, [type]: { ...prev[type], ...patch } }))
  }

  const filled = TYPES.filter((tp) => (Number(rows[tp.value].kg) || 0) > 0)

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (filled.length === 0) return

    for (const tp of filled) {
      const row = rows[tp.value]
      await enqueue(
        'byproduct.create',
        {
          slaughter_order_id: orderId ? Number(orderId) : null,
          type: tp.value,
          quantity_kg: Number(row.kg),
          destination: row.destination,
          notes: null,
          collected_at: new Date().toISOString(),
        },
        t('Sous-produit :type (:qty kg)', { type: t(tp.label), qty: Number(row.kg) }),
      )
    }

    setSaved(filled.length)
    setTimeout(() => navigate('/'), 1100)
  }

  if (saved > 0) {
    return (
      <div className="screen-center">
        <p className="success big">{t('✓ Tournée enregistrée (:n sous-produits)', { n: saved })}</p>
        <p className="muted">{t('Volumes et destinations tracés au registre.')}</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>♻️ {t('Tournée sous-produits')}</h2>
      <p className="muted">{t('Pesez ce qui a été collecté, laissez vide le reste — une seule validation.')}</p>

      <label htmlFor="order">{t("Ordre d'abattage — commun, optionnel")}</label>
      <select id="order" value={orderId} onChange={(e) => setOrderId(e.target.value)}>
        <option value="">{t('— Aucun —')}</option>
        {orders.map((o) => (
          <option key={o.id} value={o.id}>{o.order_number}</option>
        ))}
      </select>

      {TYPES.map((tp) => {
        const row = rows[tp.value]
        const has = (Number(row.kg) || 0) > 0
        return (
          <div key={tp.value} className="round-row">
            <div className="cut-line">
              <span className="cut-label">{t(tp.label)}</span>
              <input
                type="number"
                inputMode="decimal"
                min={0}
                step="0.1"
                value={row.kg}
                onChange={(e) => setRow(tp.value, { kg: e.target.value })}
                placeholder="0.0 kg"
                aria-label={t(tp.label)}
              />
            </div>
            {has && (
              <div className="chip-row">
                {DESTINATIONS.map((d) => (
                  <button
                    key={d.value}
                    type="button"
                    className={`chip ${row.destination === d.value ? 'chip-on' : ''}`}
                    onClick={() => setRow(tp.value, { destination: d.value })}
                  >
                    {t(d.label)}
                  </button>
                ))}
              </div>
            )}
          </div>
        )
      })}

      <button type="submit" className="btn-primary" disabled={filled.length === 0}>
        {t('Valider la tournée (:n sous-produits)', { n: filled.length })}
      </button>
      <Link to="/abattoir/sousproduit" className="muted center-link">{t('Saisie unitaire (notes, autre type) →')}</Link>
    </form>
  )
}
