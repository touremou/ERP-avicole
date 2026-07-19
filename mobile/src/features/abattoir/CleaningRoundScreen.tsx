/**
 * Tournée de nettoyage — toutes les zones du plan sur un écran : on coche les
 * zones faites (produit/dosage rappelés de la dernière tournée), une seule
 * validation → une op de sync PAR zone cochée. Pour une photo ou des notes,
 * l'écran unitaire reste disponible.
 * Contrat : SyncService::cleaningLogCreate (gate abattoir.C).
 */
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { enqueue } from '../../offline/sync'
import { lastPayloadOf } from '../../offline/prefill'
import { t } from '../../i18n'

/** Miroir de App\Models\CleaningLog::ZONES (hors « autre » : écran unitaire). */
const ZONES = [
  { value: 'surfaces_tables', label: 'Surfaces et tables' },
  { value: 'sols_siphons', label: 'Sols et siphons' },
  { value: 'couteaux_materiel', label: 'Couteaux et petit matériel' },
  { value: 'chambre_froide', label: 'Chambres froides' },
  { value: 'vehicule', label: 'Véhicules frigorifiques' },
  { value: 'zone_dechets', label: 'Zone déchets' },
] as const

type Row = { done: boolean; product: string; dosage: string }

export function CleaningRoundScreen() {
  const navigate = useNavigate()
  const [rows, setRows] = useState<Record<string, Row>>(
    Object.fromEntries(ZONES.map((z) => [z.value, { done: false, product: '', dosage: '' }])),
  )
  const [saved, setSaved] = useState(0)

  // Anti-corvée : produit/dosage de la dernière tournée locale par zone.
  useEffect(() => {
    for (const z of ZONES) {
      void lastPayloadOf('cleaning_log.create', (p) => p.zone === z.value).then((last) => {
        if (!last) return
        setRows((prev) => ({
          ...prev,
          [z.value]: {
            ...prev[z.value],
            product: typeof last.product_used === 'string' ? last.product_used : prev[z.value].product,
            dosage: typeof last.dosage === 'string' ? last.dosage : prev[z.value].dosage,
          },
        }))
      })
    }
  }, [])

  function setRow(zone: string, patch: Partial<Row>) {
    setRows((prev) => ({ ...prev, [zone]: { ...prev[zone], ...patch } }))
  }

  const checked = ZONES.filter((z) => rows[z.value].done)
  const missingProduct = checked.filter((z) => rows[z.value].product.trim() === '')
  const canSubmit = checked.length > 0 && missingProduct.length === 0

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!canSubmit) return

    for (const z of checked) {
      const row = rows[z.value]
      await enqueue(
        'cleaning_log.create',
        {
          zone: z.value,
          product_used: row.product.trim(),
          dosage: row.dosage.trim() || null,
          notes: null,
          done_at: new Date().toISOString(),
        },
        t('Nettoyage :zone (:product)', { zone: t(z.label), product: row.product.trim() }),
      )
    }

    setSaved(checked.length)
    setTimeout(() => navigate('/'), 1100)
  }

  if (saved > 0) {
    return (
      <div className="screen-center">
        <p className="success big">{t('✓ Tournée enregistrée (:n zones)', { n: saved })}</p>
        <p className="muted">{t('Le registre de nettoyage sera consolidé au push.')}</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>🧽 {t('Tournée de nettoyage')}</h2>
      <p className="muted">{t('Cochez les zones nettoyées — produit/dosage rappelés de la dernière tournée.')}</p>

      {ZONES.map((z) => {
        const row = rows[z.value]
        return (
          <div key={z.value} className="round-row">
            <label className="chk-row">
              <input
                type="checkbox"
                checked={row.done}
                onChange={(e) => setRow(z.value, { done: e.target.checked })}
              />
              <span>{t(z.label)}</span>
            </label>
            {row.done && (
              <div className="round-detail">
                <input
                  required
                  maxLength={100}
                  value={row.product}
                  onChange={(e) => setRow(z.value, { product: e.target.value })}
                  placeholder={t('Produit utilisé *')}
                />
                <input
                  maxLength={50}
                  value={row.dosage}
                  onChange={(e) => setRow(z.value, { dosage: e.target.value })}
                  placeholder={t('Dosage (ex. 20 ml/L)')}
                />
              </div>
            )}
          </div>
        )
      })}

      <button type="submit" className="btn-primary" disabled={!canSubmit}>
        {t('Valider la tournée (:n zones)', { n: checked.length })}
      </button>
      <Link to="/abattoir/nettoyage" className="muted center-link">{t('Saisie unitaire (photo, notes) →')}</Link>
    </form>
  )
}
