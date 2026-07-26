/**
 * Atelier de transformation végétale — le séchoir est DEHORS, sur claies, sans
 * réseau : le lot se pèse et se saisit sur place, à la sortie.
 *
 * On part de la RÉCOLTE, pas du produit : la choisir apporte d'un coup la
 * traçabilité au lot, la quantité engagée et le coût matière (coût de production
 * de son cycle). C'est ce lien qui permettra, quatre mois plus tard, de prouver
 * que sécher a rapporté — et de remonter du sac vendu à la parcelle.
 *
 * La jauge de rendement reprend la logique de l'atelier de découpe : elle situe
 * la sortie par rapport au rendement de référence de la recette, pour que
 * l'opérateur voie tout de suite une pesée aberrante.
 *
 * Contrat : SyncService::cropTransformationCreate (gate cultures.C).
 */
import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { safeLoad } from '../../offline/safeLoad'
import { t, dateLocale } from '../../i18n'
import type { RefCropCycle, RefCropRecipe, RefPendingHarvest } from '../../api/types'

/** Miroir de App\Models\CropTransformation::TYPES. */
const TYPES = [
  { value: 'sechage', label: '☀️ Séchage' },
  { value: 'mouture', label: '⚙️ Mouture' },
  { value: 'jus', label: '🥤 Jus / pressage' },
  { value: 'fermentation', label: '🫙 Fermentation' },
  { value: 'torrefaction', label: '🔥 Torréfaction' },
  { value: 'conserverie', label: '🥫 Conserverie' },
  { value: 'autre', label: '📦 Autre' },
] as const

function harvestKg(harvest: RefPendingHarvest): number {
  if (harvest.net_weight_kg != null) return Number(harvest.net_weight_kg)
  return (harvest.unit ?? 'kg').trim().toLowerCase() === 'kg' ? Number(harvest.quantity) : 0
}

export function CropTransformationScreen() {
  const navigate = useNavigate()

  const [harvests, setHarvests] = useState<RefPendingHarvest[]>([])
  const [cycles, setCycles] = useState<RefCropCycle[]>([])
  const [recipes, setRecipes] = useState<RefCropRecipe[]>([])

  const [harvestId, setHarvestId] = useState('')
  const [recipeId, setRecipeId] = useState('')
  const [type, setType] = useState<string>('sechage')
  const [inputProduct, setInputProduct] = useState('')
  const [outputProduct, setOutputProduct] = useState('')
  const [inputQty, setInputQty] = useState('')
  const [outputQty, setOutputQty] = useState('')
  const [productionCost, setProductionCost] = useState('')
  const [salePrice, setSalePrice] = useState('')
  const [notes, setNotes] = useState('')
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    void safeLoad('atelier:vegetal', async () => {
      const [pending, allCycles, allRecipes] = await Promise.all([
        db.ref_pending_harvests.toArray(),
        db.ref_crop_cycles.toArray(),
        db.ref_crop_recipes.toArray(),
      ])
      setHarvests([...pending].sort((a, b) => b.harvest_date.localeCompare(a.harvest_date)))
      setCycles(allCycles)
      setRecipes(allRecipes.filter((r) => r.is_active))
    })
  }, [])

  const harvest = useMemo(
    () => harvests.find((h) => h.id === Number(harvestId)) ?? null,
    [harvests, harvestId],
  )
  const cycle = useMemo(
    () => (harvest ? cycles.find((c) => c.id === harvest.crop_cycle_id) ?? null : null),
    [cycles, harvest],
  )
  const recipe = useMemo(
    () => recipes.find((r) => r.id === Number(recipeId)) ?? null,
    [recipes, recipeId],
  )

  // Choisir la récolte pré-remplit ce qui est déjà connu : le terrain ne
  // ressaisit ni la culture, ni le poids engagé.
  useEffect(() => {
    if (!harvest) return
    const kg = harvestKg(harvest)
    if (kg > 0) setInputQty(String(kg))
    const crop = cycles.find((c) => c.id === harvest.crop_cycle_id)?.crop_name
    if (crop) setInputProduct(crop)
  }, [harvest, cycles])

  // La recette porte le produit fini et le rendement de référence.
  useEffect(() => {
    if (!recipe) return
    if (recipe.output_product) setOutputProduct(recipe.output_product)
    if (recipe.transformation_type) setType(recipe.transformation_type)
  }, [recipe])

  const input = Number(inputQty) || 0
  const output = Number(outputQty) || 0
  const yieldPercent = input > 0 && output > 0 ? Math.round((output / input) * 1000) / 10 : null
  const expected = recipe?.expected_yield_percent != null ? Number(recipe.expected_yield_percent) : null

  // Sortie > entrée dans la même unité : erreur de pesée, refusée serveur.
  const aberrant = input > 0 && output > input * 1.5

  /**
   * Écart au rendement de référence. Vert = dans le tunnel, orange = suspect,
   * rouge = à re-peser. Sans recette, pas de jugement : on n'invente pas de
   * norme de séchage.
   */
  const gauge = useMemo(() => {
    if (yieldPercent === null || expected === null || expected <= 0) return null
    const drift = ((yieldPercent - expected) / expected) * 100
    const tone = Math.abs(drift) <= 10 ? 'ok' : Math.abs(drift) <= 25 ? 'warn' : 'bad'
    return { drift: Math.round(drift), tone }
  }, [yieldPercent, expected])

  // Coût de revient — la question qui décide s'il fallait sécher. Le coût
  // matière définitif est calculé SERVEUR (il connaît le coût du cycle) ; ici on
  // ne montre que ce que le terrain peut vérifier lui-même.
  const outputUnitCostFromOperation = output > 0 ? (Number(productionCost) || 0) / output : 0

  const canSubmit = Boolean(inputProduct.trim()) && Boolean(outputProduct.trim())
    && input > 0 && output > 0 && !aberrant

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!canSubmit) return

    await enqueue(
      'crop_transformation.create',
      {
        harvest_id: harvest?.id ?? null,
        crop_cycle_id: harvest?.crop_cycle_id ?? null,
        crop_recipe_id: recipe?.id ?? null,
        input_product: inputProduct.trim(),
        output_product: outputProduct.trim(),
        transformation_type: type,
        input_quantity: input,
        input_unit: 'kg',
        output_quantity: output,
        output_unit: recipe?.output_unit ?? 'kg',
        production_date: new Date().toISOString().slice(0, 10),
        production_cost: productionCost !== '' ? Number(productionCost) : null,
        output_unit_price: salePrice !== '' ? Number(salePrice) : null,
        // La matière engagée sort du stock « récoltes », le produit fini y entre
        // au COÛT DE REVIENT (calculé serveur) — pas au prix de vente visé.
        consumed_from_stock: Boolean(harvest?.stock_item_name),
        input_stock_item: harvest?.stock_item_name ?? null,
        synced_to_stock: true,
        output_stock_item: outputProduct.trim(),
        notes: notes.trim() || null,
      },
      t('Transformation :out — :n :unit', {
        out: outputProduct.trim(), n: output, unit: recipe?.output_unit ?? 'kg',
      }),
    )

    setSaved(true)
    setTimeout(() => navigate('/'), 1100)
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">{t('✓ Lot transformé enregistré')}</p>
        <p className="muted">
          {t('Le coût de revient et la valorisation du stock seront calculés par le serveur au push.')}
        </p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>🏭 {t('Atelier de transformation')}</h2>

      <label htmlFor="harvest">{t('Récolte engagée')}</label>
      <select id="harvest" value={harvestId} onChange={(e) => setHarvestId(e.target.value)}>
        <option value="">{t('— Aucune (saisie libre) —')}</option>
        {harvests.map((h) => {
          const crop = cycles.find((c) => c.id === h.crop_cycle_id)?.crop_name ?? ''
          return (
            <option key={h.id} value={h.id}>
              {new Date(h.harvest_date).toLocaleDateString(dateLocale())} · {crop}
              {' · '}{harvestKg(h).toLocaleString(dateLocale())} kg
            </option>
          )
        })}
      </select>
      {harvests.length === 0 ? (
        <p className="muted">
          {t('Aucune récolte « à transformer » en local. Marquez la destination à la saisie de récolte pour la retrouver ici.')}
        </p>
      ) : (
        <p className="muted">
          {t('Choisir la récolte porte la traçabilité au lot et le coût matière — rien à ressaisir.')}
        </p>
      )}
      {cycle && (
        <p className="proof-hint">🌾 {t('Cycle :code — :crop', { code: cycle.code, crop: cycle.crop_name })}</p>
      )}

      {recipes.length > 0 && (
        <>
          <label htmlFor="recipe">{t('Recette — optionnel')}</label>
          <select id="recipe" value={recipeId} onChange={(e) => setRecipeId(e.target.value)}>
            <option value="">{t('— Saisie libre —')}</option>
            {recipes.map((r) => (
              <option key={r.id} value={r.id}>
                {r.name}
                {r.expected_yield_percent != null ? ` · ${t('rdt')} ${r.expected_yield_percent}%` : ''}
              </option>
            ))}
          </select>
        </>
      )}

      <label>{t('Type de transformation')}</label>
      <div className="chip-row">
        {TYPES.map((option) => (
          <button
            key={option.value}
            type="button"
            className={`chip ${type === option.value ? 'chip-on' : ''}`}
            onClick={() => setType(option.value)}
          >
            {t(option.label)}
          </button>
        ))}
      </div>

      <label htmlFor="inprod">{t('Produit entrant')}</label>
      <input id="inprod" required maxLength={255} value={inputProduct} onChange={(e) => setInputProduct(e.target.value)} placeholder={t('Gombo frais…')} />

      <label htmlFor="outprod">{t('Produit fini')}</label>
      <input id="outprod" required maxLength={255} value={outputProduct} onChange={(e) => setOutputProduct(e.target.value)} placeholder={t('Gombo séché…')} />

      <label htmlFor="inqty">{t('Quantité engagée (kg)')}</label>
      <input id="inqty" type="number" inputMode="decimal" min="0.001" step="0.1" required value={inputQty} onChange={(e) => setInputQty(e.target.value)} />

      <label htmlFor="outqty">
        {t('Quantité obtenue (:unit)', { unit: recipe?.output_unit ?? 'kg' })}
      </label>
      <input id="outqty" type="number" inputMode="decimal" min="0" step="0.1" required value={outputQty} onChange={(e) => setOutputQty(e.target.value)} />

      {aberrant && (
        <p className="error">
          {t('⚠️ La sortie dépasse l’entrée : une transformation perd de la matière. Vérifiez les deux pesées (ou l’unité).')}
        </p>
      )}

      {yieldPercent !== null && !aberrant && (
        <>
          <p className="big">{t('Rendement : :rate %', { rate: yieldPercent })}</p>
          {gauge && expected !== null && (
            <p className={gauge.tone === 'ok' ? 'success' : gauge.tone === 'warn' ? 'muted' : 'error'}>
              {gauge.tone === 'ok'
                ? t('✓ Conforme à la recette (:expected % attendu)', { expected })
                : t('⚠️ Écart de :drift % au rendement attendu (:expected %) — repesez si possible.', {
                    drift: gauge.drift, expected,
                  })}
            </p>
          )}
        </>
      )}

      <label htmlFor="cost">{t('Coût de l’opération (GNF) — main d’œuvre, bois, emballage')}</label>
      <input id="cost" type="number" inputMode="numeric" min="0" step="1000" value={productionCost} onChange={(e) => setProductionCost(e.target.value)} />
      {outputUnitCostFromOperation > 0 && (
        <p className="muted">
          {t('Soit :n GNF/:unit pour la seule opération — la matière première s’y ajoutera (calcul serveur).', {
            n: Math.round(outputUnitCostFromOperation).toLocaleString(dateLocale()),
            unit: recipe?.output_unit ?? 'kg',
          })}
        </p>
      )}

      <label htmlFor="target">{t('Prix de vente visé (GNF/:unit) — optionnel', { unit: recipe?.output_unit ?? 'kg' })}</label>
      <input id="target" type="number" inputMode="numeric" min="0" step="500" value={salePrice} onChange={(e) => setSalePrice(e.target.value)} />

      <label htmlFor="notes">{t('Observations — optionnel')}</label>
      <textarea id="notes" rows={2} maxLength={1000} value={notes} onChange={(e) => setNotes(e.target.value)} />

      <button type="submit" className="btn-primary" disabled={!canSubmit}>
        {t('Enregistrer le lot transformé')}
      </button>
    </form>
  )
}
