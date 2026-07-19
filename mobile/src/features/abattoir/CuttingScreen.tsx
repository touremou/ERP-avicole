/**
 * Atelier de découpe (désassemblage) — l'opérateur pèse l'entrée (carcasses)
 * puis saisit le poids obtenu pour chaque coupe, déchets compris. La jauge
 * colorée (rouge → orange → vert) montre la progression vers le poids entré
 * sans calcul mental. Règles métier rejouées par le serveur au push
 * (ordre terminé, conservation de matière, coûts par valeur, routage) :
 * SyncService::slaughterCutting (gate abattoir.C).
 */
import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { t } from '../../i18n'
import type { RefSlaughterOrder } from '../../api/types'

// Coupes standards volaille (miroir de config/butchery.php — le serveur reste
// l'autorité : recette active OU nomenclature, codes libres via « autre »).
const CUTS = [
  { code: 'cuisse',   label: '🍗 Cuisses',          destination: 'stock_frais', default: true },
  { code: 'aile',     label: '🪽 Ailes',            destination: 'stock_frais', default: true },
  { code: 'poitrine', label: '🥩 Poitrine/Blancs',  destination: 'stock_frais', default: true },
  { code: 'dos',      label: '🦴 Dos/Carcasse',     destination: 'vente_directe', default: true },
  { code: 'abats',    label: '🫀 Abats divers',     destination: 'stock_frais', default: true },
  { code: 'foie',     label: '🟤 Foies',            destination: 'stock_frais', default: false },
  { code: 'gesier',   label: '🟠 Gésiers',          destination: 'stock_frais', default: false },
  { code: 'dechet',   label: '🗑️ Déchets (os, parures)', destination: 'dechet', default: false },
] as const

type Line = { code: string; label: string; destination: string; kg: string }

export function CuttingScreen() {
  const navigate = useNavigate()

  const [orders, setOrders] = useState<RefSlaughterOrder[]>([])
  const [orderId, setOrderId] = useState('')
  const [inputKg, setInputKg] = useState('')
  const [lines, setLines] = useState<Line[]>(
    CUTS.filter((c) => c.default).map((c) => ({ code: c.code, label: c.label, destination: c.destination, kg: '' })),
  )
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    // Cycle clôturé = plus de découpe (le serveur refuse aussi au push).
    void db.ref_slaughter_orders
      .where('status').equals('termine')
      .filter((o) => !o.closed_at)
      .reverse().sortBy('planned_date')
      .then((found) => setOrders(found.slice(0, 30)))
  }, [])

  const input = Number(inputKg)
  const totalOut = useMemo(() => lines.reduce((s, l) => s + (Number(l.kg) || 0), 0), [lines])
  const progress = input > 0 ? Math.min(100, (totalOut / input) * 100) : 0
  const over = input > 0 && totalOut > input + 0.001
  // Jauge : rouge loin du compte, orange en approche, vert au complet.
  const gaugeClass = over || progress < 70 ? 'gauge-red' : progress < 95 ? 'gauge-amber' : 'gauge-green'
  const lossKg = Math.max(0, input - totalOut)
  const lossPct = input > 0 ? ((lossKg / input) * 100).toFixed(1) : '0.0'

  const inactiveCuts = CUTS.filter((c) => !lines.some((l) => l.code === c.code))

  function setLineKg(code: string, kg: string) {
    setLines((prev) => prev.map((l) => (l.code === code ? { ...l, kg } : l)))
  }

  function addLine(code: string) {
    const cut = CUTS.find((c) => c.code === code)
    if (cut && !lines.some((l) => l.code === code)) {
      setLines((prev) => [...prev, { code: cut.code, label: cut.label, destination: cut.destination, kg: '' }])
    }
  }

  const canSubmit = Boolean(orderId) && input > 0 && totalOut > 0 && !over

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!canSubmit) return

    const products = lines
      .filter((l) => (Number(l.kg) || 0) > 0)
      .map((l) => ({
        type: l.code,
        name: l.label.replace(/^[^\p{L}]+/u, ''), // libellé sans l'emoji
        kg: Number(l.kg),
        destination: l.destination,
      }))

    await enqueue(
      'slaughter.cutting',
      {
        slaughter_order_id: Number(orderId),
        session_date: new Date().toISOString().slice(0, 10),
        total_input_kg: input,
        products,
      },
      t('Découpe :order (:kg kg)', {
        order: orders.find((o) => o.id === Number(orderId))?.order_number ?? orderId,
        kg: input,
      }),
    )

    setSaved(true)
    setTimeout(() => navigate('/'), 900)
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">{t('✓ Découpe enregistrée')}</p>
        <p className="muted">{t('Conservation de matière et coûts seront re-vérifiés par le serveur au push.')}</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>✂️ {t('Atelier de découpe')}</h2>

      <label htmlFor="order">{t("Ordre d'abattage (terminé)")}</label>
      <select id="order" required value={orderId} onChange={(e) => setOrderId(e.target.value)}>
        <option value="">{t('Sélectionner…')}</option>
        {orders.map((o) => (
          <option key={o.id} value={o.id}>{o.order_number} · {o.planned_date}</option>
        ))}
      </select>
      {orders.length === 0 && (
        <p className="muted">{t('Aucun ordre terminé en local — synchronisez d’abord.')}</p>
      )}

      <label htmlFor="inputkg">{t('Poids carcasses entrées (kg)')}</label>
      <input
        id="inputkg"
        type="number"
        inputMode="decimal"
        min={0.1}
        step="0.1"
        required
        value={inputKg}
        onChange={(e) => setInputKg(e.target.value)}
        placeholder={t('ex. 15.0')}
      />

      {/* JAUGE DE PROGRESSION : se remplit vers le poids entré. */}
      {input > 0 && (
        <div className="gauge-block">
          <div className="gauge-track">
            <div className={`gauge-fill ${gaugeClass}`} style={{ width: `${progress}%` }} />
          </div>
          <p className={over ? 'error' : 'muted'}>
            {over
              ? t('⚠️ :out kg saisis pour :in kg entrés — une découpe ne crée pas de matière.', { out: totalOut.toFixed(1), in: input.toFixed(1) })
              : t(':out / :in kg · perte :loss kg (:pct %)', { out: totalOut.toFixed(1), in: input.toFixed(1), loss: lossKg.toFixed(1), pct: lossPct })}
          </p>
        </div>
      )}

      <label>{t('Poids par coupe (kg)')}</label>
      {lines.map((line) => (
        <div key={line.code} className="cut-line">
          <span className="cut-label">{t(line.label)}</span>
          <input
            type="number"
            inputMode="decimal"
            min={0}
            step="0.1"
            value={line.kg}
            onChange={(e) => setLineKg(line.code, e.target.value)}
            placeholder="0.0"
            aria-label={t(line.label)}
          />
        </div>
      ))}

      {inactiveCuts.length > 0 && (
        <div className="chip-row">
          {inactiveCuts.map((c) => (
            <button key={c.code} type="button" className="chip" onClick={() => addLine(c.code)}>
              + {t(c.label)}
            </button>
          ))}
        </div>
      )}

      <button type="submit" className="btn-primary" disabled={!canSubmit}>
        {t('Valider la découpe')}
      </button>
    </form>
  )
}
