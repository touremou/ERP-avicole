/**
 * Pointage journalier — LA balle traçante de la Phase 0 : saisie 2-3 taps,
 * debout, hors-ligne. Steppers plutôt que clavier, confirmation instantanée
 * (optimistic), la sync part en arrière-plan.
 *
 * Payload aligné sur SyncService::dailyCheckCreate (validation serveur).
 */
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { lastPayloadOf } from '../../offline/prefill'
import { NumberStepper } from '../../ui/NumberStepper'
import { VoiceDictation } from '../../ui/VoiceDictation'
import { t, dateLocale } from '../../i18n'
import type { RefBatch, RefStock } from '../../api/types'

export function DailyCheckScreen() {
  const { batchId } = useParams()
  const navigate = useNavigate()

  const [batch, setBatch] = useState<RefBatch | null>(null)
  const [feedStocks, setFeedStocks] = useState<RefStock[]>([])
  const [healthStatus, setHealthStatus] = useState<'Normal' | 'Alerte' | 'Critique'>('Normal')
  const [mortality, setMortality] = useState(0)
  const [feedConsumed, setFeedConsumed] = useState(0)
  const [feedType, setFeedType] = useState('')
  const [avgWeight, setAvgWeight] = useState('')
  const [observations, setObservations] = useState('')
  const [prefilled, setPrefilled] = useState(false)
  const [saved, setSaved] = useState(false)

  // Détails optionnels (repliés par défaut : la saisie rapide reste 3 taps).
  const [showDetails, setShowDetails] = useState(false)
  const [waterConsumed, setWaterConsumed] = useState('')
  const [tempMin, setTempMin] = useState('')
  const [tempMax, setTempMax] = useState('')
  const [humidity, setHumidity] = useState('')
  const [mortInfirmary, setMortInfirmary] = useState('')
  const [quarantineIn, setQuarantineIn] = useState('')
  const [quarantineOut, setQuarantineOut] = useState('')
  const [lameCount, setLameCount] = useState('')
  const [peckingCount, setPeckingCount] = useState('')
  const [litterChanged, setLitterChanged] = useState(false)
  const [manureKg, setManureKg] = useState('')

  useEffect(() => {
    if (batchId) void db.ref_batches.get(Number(batchId)).then((b) => setBatch(b ?? null))
    // Types d'aliment = stocks « conso » du miroir local (preset, pas de
    // clavier) — le serveur décrémentera CE stock au push.
    void db.ref_stocks
      .where('category')
      .equals('conso')
      .toArray()
      .then((stocks) => {
        setFeedStocks(stocks)
        if (stocks.length === 1) setFeedType(stocks[0].item_name)
      })

    // Anti-corvée : la conso d'aliment et son type varient peu d'un jour à
    // l'autre → préremplis depuis le dernier pointage LOCAL du même lot.
    // La mortalité, elle, repart toujours de zéro (jamais présumée).
    if (batchId) {
      void lastPayloadOf('daily_check.create', (p) => p.batch_id === Number(batchId)).then((last) => {
        if (!last) return
        if (typeof last.feed_consumed === 'number' && last.feed_consumed > 0) {
          setFeedConsumed(last.feed_consumed)
          if (typeof last.feed_type === 'string' && last.feed_type) setFeedType(last.feed_type)
          setPrefilled(true)
        }
      })
    }
  }, [batchId])

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!batch) return

    const num = (v: string) => (v.trim() !== '' ? Number(v) : null)

    await enqueue(
      'daily_check.create',
      {
        batch_id: batch.id,
        check_date: new Date().toISOString().slice(0, 10),
        health_status: healthStatus,
        mortality,
        feed_consumed: feedConsumed,
        feed_type: feedConsumed > 0 ? feedType : null,
        avg_weight: num(avgWeight),
        water_consumed: num(waterConsumed),
        temp_min: num(tempMin),
        temp_max: num(tempMax),
        humidity: num(humidity),
        mortality_infirmary: num(mortInfirmary),
        qty_quarantine_in: num(quarantineIn),
        qty_quarantine_out: num(quarantineOut),
        lame_count: num(lameCount),
        pecking_injury_count: num(peckingCount),
        litter_changed: litterChanged,
        manure_collected_kg: litterChanged ? num(manureKg) : null,
        observations: observations || null,
      },
      t('Pointage :code', { code: batch.code }),
    )

    // Confirmation instantanée (règle UX n°8 : jamais de spinner bloquant).
    setSaved(true)
    setTimeout(() => navigate('/'), 900)
  }

  if (!batch) {
    return (
      <div className="screen">
        <p className="muted">{t("Lot introuvable en local — synchronisez d'abord.")}</p>
      </div>
    )
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">✓ {t('Pointage enregistré')}</p>
        <p className="muted">{t('Il partira au serveur dès que le réseau le permet.')}</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>{t('Pointage')} — {batch.code}</h2>
      <p className="muted">
        {batch.current_quantity} {t('sujets')} · {new Date().toLocaleDateString(dateLocale())}
      </p>

      <label>{t('État sanitaire')}</label>
      <div className="chip-row">
        {([
          ['Normal', `🟢 ${t('Normal')}`],
          ['Alerte', `🟡 ${t('Alerte')}`],
          ['Critique', `🔴 ${t('Critique')}`],
        ] as const).map(([value, label]) => (
          <button
            key={value}
            type="button"
            className={`chip ${healthStatus === value ? 'chip-on' : ''}`}
            onClick={() => setHealthStatus(value)}
          >
            {label}
          </button>
        ))}
      </div>

      <NumberStepper label={t('Mortalité (sujets)')} value={mortality} onChange={setMortality} min={0} />

      <NumberStepper
        label={t('Aliment consommé (kg)')}
        value={feedConsumed}
        onChange={setFeedConsumed}
        min={0}
        step={5}
      />
      {prefilled && (
        <p className="muted">{t('↺ Aliment prérempli d’après votre dernier pointage — corrigez si besoin.')}</p>
      )}

      {feedConsumed > 0 && (
        <>
          <label htmlFor="feed_type">{t("Type d'aliment (stock décrémenté)")}</label>
          <select
            id="feed_type"
            required
            value={feedType}
            onChange={(e) => setFeedType(e.target.value)}
          >
            <option value="" disabled>
              {t('— Choisir dans le stock —')}
            </option>
            {feedStocks.map((stock) => (
              <option key={stock.id} value={stock.item_name}>
                {stock.item_name} ({stock.current_quantity} {stock.unit})
              </option>
            ))}
          </select>
        </>
      )}

      <label htmlFor="avg_weight">{t('Poids moyen (kg) — optionnel')}</label>
      <input
        id="avg_weight"
        type="number"
        inputMode="decimal"
        step="0.01"
        min="0"
        value={avgWeight}
        onChange={(e) => setAvgWeight(e.target.value)}
        placeholder={t('ex. 1.25')}
      />

      <button
        type="button"
        className="btn-secondary"
        onClick={() => setShowDetails((s) => !s)}
      >
        {showDetails ? t('▲ Masquer les détails') : t('▼ Plus de détails (optionnel)')}
      </button>

      {showDetails && (
        <>
          <h3>{t('Ambiance')}</h3>
          <label htmlFor="water">{t('Eau consommée (L)')}</label>
          <input id="water" type="number" inputMode="decimal" min="0" value={waterConsumed} onChange={(e) => setWaterConsumed(e.target.value)} placeholder={t('ex. 120')} />
          <label htmlFor="tmin">{t('Température min (°C)')}</label>
          <input id="tmin" type="number" inputMode="decimal" value={tempMin} onChange={(e) => setTempMin(e.target.value)} placeholder={t('ex. 22')} />
          <label htmlFor="tmax">{t('Température max (°C)')}</label>
          <input id="tmax" type="number" inputMode="decimal" value={tempMax} onChange={(e) => setTempMax(e.target.value)} placeholder={t('ex. 31')} />
          <label htmlFor="hum">{t('Humidité (%)')}</label>
          <input id="hum" type="number" inputMode="decimal" min="0" max="100" value={humidity} onChange={(e) => setHumidity(e.target.value)} placeholder={t('ex. 60')} />

          <h3>{t('Infirmerie & tri')}</h3>
          <label htmlFor="infin">{t('Mise en infirmerie (sujets)')}</label>
          <input id="infin" type="number" inputMode="numeric" min="0" value={quarantineIn} onChange={(e) => setQuarantineIn(e.target.value)} placeholder="0" />
          <label htmlFor="infout">{t('Rétablis (sortis d’infirmerie)')}</label>
          <input id="infout" type="number" inputMode="numeric" min="0" value={quarantineOut} onChange={(e) => setQuarantineOut(e.target.value)} placeholder="0" />
          <label htmlFor="morti">{t('Morts en infirmerie')}</label>
          <input id="morti" type="number" inputMode="numeric" min="0" value={mortInfirmary} onChange={(e) => setMortInfirmary(e.target.value)} placeholder="0" />
          <label htmlFor="lame">{t('Boiteux observés')}</label>
          <input id="lame" type="number" inputMode="numeric" min="0" value={lameCount} onChange={(e) => setLameCount(e.target.value)} placeholder="0" />
          <label htmlFor="peck">{t('Picage / blessés')}</label>
          <input id="peck" type="number" inputMode="numeric" min="0" value={peckingCount} onChange={(e) => setPeckingCount(e.target.value)} placeholder="0" />

          <h3>{t('Litière')}</h3>
          <button
            type="button"
            className={`chip ${litterChanged ? 'chip-on' : ''}`}
            onClick={() => setLitterChanged((v) => !v)}
          >
            {litterChanged ? `✓ ${t('Litière changée')}` : t('Litière changée ?')}
          </button>
          {litterChanged && (
            <>
              <label htmlFor="manure">{t('Fumier ramassé (kg)')}</label>
              <input id="manure" type="number" inputMode="decimal" min="0" value={manureKg} onChange={(e) => setManureKg(e.target.value)} placeholder={t('ex. 40')} />
            </>
          )}
        </>
      )}

      <label htmlFor="observations">{t('Observations — optionnel')}</label>
      <textarea
        id="observations"
        rows={2}
        maxLength={1000}
        value={observations}
        onChange={(e) => setObservations(e.target.value)}
        placeholder={t('Comportement, litière…')}
      />
      <div className="chip-row">
        <VoiceDictation onText={(text) => setObservations((prev) => (prev ? prev + ' ' : '') + text)} />
      </div>

      <button type="submit" className="btn-primary">
        {t('Enregistrer le pointage')}
      </button>
    </form>
  )
}
