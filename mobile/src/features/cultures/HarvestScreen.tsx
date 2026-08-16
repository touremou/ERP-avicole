/**
 * Récolte — saisie terrain sur un cycle de culture en cours. Le serveur
 * bascule le cycle en phase « recolte » à la première saisie et refuse un
 * cycle clos (conflict → bac « À corriger »). Contrat :
 * SyncService::harvestCreate (gate cultures.C).
 *
 * DESTINATION (T1) — première question posée, parce qu'elle change le sens de
 * tout le reste : une récolte vendue porte un prix encaissé ; une récolte
 * séchée ou gardée pour vendre plus cher plus tard n'encaisse RIEN, entre en
 * stock au coût de production, et doit donc être pesée en kg.
 */
import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { dateLocale, t } from '../../i18n'
import { NumberStepper } from '../../ui/NumberStepper'
import type { RefCropCycle, RefCropSpecies } from '../../api/types'

const UNITS = ['kg', 'sac', 'botte', 'régime', 'unité'] as const
const QUALITIES = [
  { value: 'bon', label: '✅ Bon' },
  { value: 'moyen', label: '➖ Moyen' },
  { value: 'mediocre', label: '⚠️ Médiocre' },
] as const

/** Miroir de App\Models\Harvest::DESTINATIONS. */
const DESTINATIONS = [
  { value: 'vente', label: '💰 Vendue', hint: 'prix encaissé' },
  { value: 'transformation', label: '🏭 À transformer', hint: 'séchage, mouture…' },
  { value: 'stockage', label: '📦 Stockée', hint: 'vente différée' },
] as const

type Destination = (typeof DESTINATIONS)[number]['value']

export function HarvestScreen() {
  const { cycleId } = useParams()
  const navigate = useNavigate()

  const [cycle, setCycle] = useState<RefCropCycle | null>(null)
  const [quantity, setQuantity] = useState(0)
  const [unit, setUnit] = useState<string>('kg')
  const [quality, setQuality] = useState<string>('bon')
  const [destination, setDestination] = useState<Destination>('vente')
  const [unitPrice, setUnitPrice] = useState('')
  const [notes, setNotes] = useState('')
  const [saved, setSaved] = useState(false)

  // Détails optionnels (repliés) : parité web — pesée précise, pertes, stock.
  const [showDetails, setShowDetails] = useState(false)
  const [netWeightKg, setNetWeightKg] = useState('')
  const [lossQuantity, setLossQuantity] = useState('')
  const [syncToStock, setSyncToStock] = useState(false)
  const [stockItemName, setStockItemName] = useState('')

  const [species, setSpecies] = useState<RefCropSpecies | null>(null)

  useEffect(() => {
    if (cycleId) void db.ref_crop_cycles.get(Number(cycleId)).then((c) => setCycle(c ?? null))
  }, [cycleId])

  // Espèce du catalogue : elle porte le poids moyen d'une unité récoltée.
  useEffect(() => {
    if (!cycle?.crop_name) return
    const needle = cycle.crop_name.trim().toLowerCase()
    void db.ref_crop_species
      .toArray()
      .then((all) => setSpecies(all.find((s) => s.name.toLowerCase() === needle) ?? null))
  }, [cycle])

  const held = destination !== 'vente'
  const inKg = unit.trim().toLowerCase() === 'kg'

  /**
   * Poids net PROPOSÉ à partir du comptage.
   *
   * Au champ, le technicien compte des fruits ou des régimes — pas des kilos.
   * Une récolte conservée exige pourtant une pesée en kg, sinon la matière sort
   * du revenu sans pouvoir être valorisée (T1). Le poids moyen du catalogue
   * permet de proposer ce chiffre au lieu de laisser le champ vide.
   *
   * On ne propose RIEN si l'unité saisie est déjà le kilo — il n'y a rien à
   * convertir — ni sans poids moyen : on ne devine pas un calibre.
   */
  const suggestedNetWeight = useMemo(() => {
    const avg = Number(species?.avg_unit_weight_kg ?? 0)
    if (inKg || !(avg > 0) || !(quantity > 0)) return null

    return Math.round(quantity * avg * 100) / 100
  }, [species, inKg, quantity])
  const effectiveKg = netWeightKg.trim() !== '' ? Number(netWeightKg) : (inKg ? quantity : 0)
  // Miroir de RecordHarvest : conservée sans pesée = matière non valorisable.
  const missingWeight = held && !(effectiveKg > 0)

  // Récolte conservée : les détails s'ouvrent d'office, la pesée y vit.
  useEffect(() => {
    if (held) setShowDetails(true)
  }, [held])

  /*
   * DÉLAI AVANT RÉCOLTE (résidus phytosanitaires).
   *
   * Le serveur refuse DÉFINITIVEMENT une récolte sous délai — le refus part au
   * bac « À corriger » et n'est pas rejouable. Jusqu'ici le terrain l'ignorait :
   * le technicien récoltait, saisissait, et l'apprenait à la synchronisation.
   * Le refus arrivait donc APRÈS le geste qu'il devait empêcher.
   *
   * La date descend maintenant avec le cycle. On avertit avant le sécateur.
   */
  const blockedUntil = cycle?.harvest_blocked_until ?? null
  const harvestBlocked =
    blockedUntil !== null && new Date().toISOString().slice(0, 10) < blockedUntil

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!cycle || quantity <= 0 || missingWeight || harvestBlocked) return

    const num = (v: string) => (v.trim() !== '' ? Number(v) : null)

    await enqueue(
      'harvest.create',
      {
        crop_cycle_id: cycle.id,
        harvest_date: new Date().toISOString().slice(0, 10),
        quantity,
        unit,
        quality,
        destination,
        // Le prix n'existe que sur une vente : conservé sur une récolte gardée,
        // il finirait resommé comme un revenu jamais encaissé.
        unit_price: held ? null : num(unitPrice),
        net_weight_kg: num(netWeightKg),
        loss_quantity: num(lossQuantity),
        // Conservée = entrée en stock non négociable (le serveur la force aussi).
        sync_to_stock: held ? true : syncToStock,
        stock_item_name: (held || syncToStock) && stockItemName.trim() ? stockItemName.trim() : null,
        notes: notes || null,
      },
      t('Récolte :crop — :code (:qty :unit)', { crop: cycle.crop_name, code: cycle.code, qty: quantity, unit }),
    )

    setSaved(true)
    setTimeout(() => navigate('/'), 900)
  }

  if (!cycle) {
    return (
      <div className="screen">
        <p className="muted">{t("Cycle de culture introuvable en local — synchronisez d'abord.")}</p>
      </div>
    )
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">{t('✓ Récolte enregistrée')}</p>
        <p className="muted">{t('Le cycle passe en phase récolte côté serveur.')}</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>{t('🌾 Récolte — :crop', { crop: cycle.crop_name })}</h2>
      <p className="muted">
        {cycle.code}
        {cycle.variety ? ` · ${cycle.variety}` : ''} · {new Date().toLocaleDateString(dateLocale())}
      </p>

      {harvestBlocked && (
        <p className="proof-hint" role="alert">
          ⛔{' '}
          {t(
            'Délai avant récolte en cours : la récolte est interdite jusqu’au :date (résidus phytosanitaires). Le serveur la refusera — attendez l’échéance.',
            { date: new Date(blockedUntil as string).toLocaleDateString(dateLocale()) },
          )}
        </p>
      )}

      <label>{t('Que devient cette récolte ?')}</label>
      <div className="chip-row">
        {DESTINATIONS.map((option) => (
          <button
            key={option.value}
            type="button"
            className={`chip ${destination === option.value ? 'chip-on' : ''}`}
            aria-pressed={destination === option.value}
            onClick={() => setDestination(option.value)}
          >
            {t(option.label)}
          </button>
        ))}
      </div>
      {held && (
        <p className="proof-hint">
          ℹ️ {t('Aucun revenu inscrit au cycle : la récolte entre en stock, valorisée au coût de production. La marge se fera à la vente réelle.')}
        </p>
      )}

      <NumberStepper label={t('Quantité récoltée (:unit)', { unit })} value={quantity} onChange={setQuantity} min={0} step={5} />

      <label>{t('Unité')}</label>
      <div className="chip-row">
        {UNITS.map((option) => (
          <button
            key={option}
            type="button"
            className={`chip ${unit === option ? 'chip-on' : ''}`}
            onClick={() => setUnit(option)}
          >
            {option}
          </button>
        ))}
      </div>
      {unit !== 'kg' && (
        <p className="muted">
          {t("Sans pesée en kg, cette récolte n'alimentera pas les KPI de rendement — le poids net pourra être complété sur le web.")}
        </p>
      )}

      <label>{t('Qualité')}</label>
      <div className="chip-row">
        {QUALITIES.map((option) => (
          <button
            key={option.value}
            type="button"
            className={`chip ${quality === option.value ? 'chip-on' : ''}`}
            onClick={() => setQuality(option.value)}
          >
            {t(option.label)}
          </button>
        ))}
      </div>

      {!held && (
        <>
          <label htmlFor="price">{t('Prix de vente encaissé (par kg) — optionnel')}</label>
          <input id="price" type="number" inputMode="numeric" min="0" step="100" value={unitPrice} onChange={(e) => setUnitPrice(e.target.value)} />
        </>
      )}

      <button type="button" className="btn-secondary" onClick={() => setShowDetails((s) => !s)}>
        {showDetails ? t('▲ Masquer les détails') : t('▼ Plus de détails (optionnel)')}
      </button>

      {showDetails && (
        <>
          <label htmlFor="netw">
            {held ? t('Poids net pesé (kg) *') : t('Poids net pesé (kg) — optionnel')}
          </label>
          <input
            id="netw"
            type="number"
            inputMode="decimal"
            min="0"
            step="0.001"
            required={held && !inKg}
            value={netWeightKg}
            onChange={(e) => setNetWeightKg(e.target.value)}
            placeholder={held ? t('obligatoire — valorise le stock') : t('pesée précise pour le rendement')}
          />
          {/* Suggestion TAPPABLE, jamais automatique : une pesée réelle vaut
              toujours mieux qu'un poids moyen. On propose quand le champ est vide,
              c'est-à-dire précisément quand l'alternative serait « rien ». */}
          {suggestedNetWeight !== null && netWeightKg.trim() === '' && (
            <button type="button" className="chip" onClick={() => setNetWeightKg(String(suggestedNetWeight))}>
              {t('Proposer :n kg (:qty × :avg kg)', {
                n: suggestedNetWeight.toLocaleString('fr-FR'),
                qty: quantity,
                avg: Number(species?.avg_unit_weight_kg ?? 0).toLocaleString('fr-FR'),
              })}
            </button>
          )}
          {missingWeight && (
            <p className="error">
              {t('⚠️ Récolte non vendue : pesez-la en kg. Sans pesée, elle sort du revenu sans pouvoir être valorisée ni transformée.')}
            </p>
          )}

          <label htmlFor="loss">{t('Pertes / déchets (:unit)', { unit })}</label>
          <input id="loss" type="number" inputMode="decimal" min="0" value={lossQuantity} onChange={(e) => setLossQuantity(e.target.value)} placeholder="0" />

          {held ? (
            <p className="muted">✓ {t('Entrée en stock automatique (récolte conservée)')}</p>
          ) : (
            <button
              type="button"
              className={`chip ${syncToStock ? 'chip-on' : ''}`}
              onClick={() => setSyncToStock((v) => !v)}
            >
              {syncToStock ? `✓ ${t('Versé au stock')}` : t('Verser cette récolte au stock ?')}
            </button>
          )}
          {(syncToStock || held) && (
            <>
              <label htmlFor="stockname">{t('Nom en stock — optionnel')}</label>
              <input id="stockname" maxLength={255} value={stockItemName} onChange={(e) => setStockItemName(e.target.value)} placeholder={cycle.crop_name} />
            </>
          )}
        </>
      )}

      <label htmlFor="notes">{t('Observations — optionnel')}</label>
      <textarea id="notes" rows={2} maxLength={1000} value={notes} onChange={(e) => setNotes(e.target.value)} />

      <button
        type="submit"
        className="btn-primary"
        disabled={quantity <= 0 || missingWeight || harvestBlocked}
      >
        {t('Enregistrer la récolte')}
      </button>

      <Link to={`/cultures/intrant/${cycle.id}`} className="btn-secondary" style={{ textAlign: 'center', lineHeight: '56px', textDecoration: 'none' }}>
        {t('🧪 Saisir un intrant plutôt')}
      </Link>
    </form>
  )
}
