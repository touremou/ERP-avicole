/**
 * Dépense terrain — créée EN ATTENTE (validation en ligne au bureau), avec
 * photo du reçu prise sur place (même pipeline hors-ligne que les incidents :
 * Dexie → upload au retour réseau → photo_path → justificatif).
 * Contrat : SyncService::expenseCreate (gate depenses.C).
 */
import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { platform, compressImage } from '../../platform'
import { t } from '../../i18n'

/** Miroir de App\Models\Expense::CATEGORIES (référentiel stable). */
const CATEGORIES: Record<string, string> = {
  carburant: 'Carburant',
  transport: 'Transport / Déplacement',
  entretien: 'Entretien / Réparation',
  fournitures: 'Fournitures & petit matériel',
  communication: 'Communication (crédit, internet)',
  administratif: 'Frais administratifs',
  taxes: 'Taxes & impôts',
  location: 'Location',
  main_oeuvre: "Main-d'œuvre journalière",
  sante_animale: 'Santé animale (achat ponctuel)',
  eau_energie: 'Eau & énergie (appoint)',
  divers: 'Divers',
}

export function ExpenseScreen() {
  const navigate = useNavigate()
  const [category, setCategory] = useState('')
  const [label, setLabel] = useState('')
  const [amount, setAmount] = useState('')
  const [paymentMethod, setPaymentMethod] = useState('especes')
  const [supplierName, setSupplierName] = useState('')
  const [notes, setNotes] = useState('')
  const [photoBlob, setPhotoBlob] = useState<Blob | null>(null)
  const [photoPreview, setPhotoPreview] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)

  async function attachPhoto() {
    const file = await platform.takePhoto()
    if (!file) return
    const compressed = await compressImage(file, 1280, 0.8)
    setPhotoBlob(compressed)
    setPhotoPreview(URL.createObjectURL(compressed))
  }

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!category || !label.trim() || Number(amount) < 1) return

    const payload: Record<string, unknown> = {
      category,
      label: label.trim(),
      amount: Number(amount),
      expense_date: new Date().toISOString().slice(0, 10),
      payment_method: paymentMethod,
      supplier_name: supplierName.trim() || null,
      notes: notes.trim() || null,
    }

    if (photoBlob) {
      const photoUuid = crypto.randomUUID()
      await db.photos.add({
        uuid: photoUuid,
        blob: photoBlob,
        context: 'expense',
        uploaded_path: null,
        created_at: new Date().toISOString(),
      })
      payload.photo_uuid = photoUuid
    }

    await enqueue('expense.create', payload, t('Dépense :label', { label: label.trim() }))

    setSaved(true)
    setTimeout(() => navigate('/'), 900)
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">{t('✓ Dépense enregistrée')}</p>
        <p className="muted">{t('Elle attend la validation du gestionnaire (en ligne).')}</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>{t('🧾 Dépense terrain')}</h2>

      <label htmlFor="category">{t('Catégorie')}</label>
      <select id="category" required value={category} onChange={(e) => setCategory(e.target.value)}>
        <option value="" disabled>
          {t('— Choisir —')}
        </option>
        {Object.entries(CATEGORIES).map(([value, text]) => (
          <option key={value} value={value}>
            {t(text)}
          </option>
        ))}
      </select>

      <label htmlFor="label">{t('Libellé')}</label>
      <input
        id="label"
        required
        maxLength={255}
        value={label}
        onChange={(e) => setLabel(e.target.value)}
        placeholder={t('ex. Gasoil groupe électrogène')}
      />

      <label htmlFor="amount">{t('Montant')}</label>
      <input
        id="amount"
        type="number"
        inputMode="numeric"
        required
        min="1"
        value={amount}
        onChange={(e) => setAmount(e.target.value)}
      />

      <label>{t('Mode de paiement')}</label>
      <div className="chip-row">
        {([
          ['especes', `💵 ${t('Espèces')}`],
          ['mobile_money', `📱 ${t('Mobile Money')}`],
          ['virement', `🏦 ${t('Virement')}`],
          ['cheque', `🧾 ${t('Chèque')}`],
        ] as const).map(([value, lbl]) => (
          <button
            key={value}
            type="button"
            className={`chip ${paymentMethod === value ? 'chip-on' : ''}`}
            onClick={() => setPaymentMethod(value)}
          >
            {lbl}
          </button>
        ))}
      </div>

      <label htmlFor="supplier">{t('Fournisseur / bénéficiaire — optionnel')}</label>
      <input id="supplier" maxLength={255} value={supplierName} onChange={(e) => setSupplierName(e.target.value)} />

      <label htmlFor="notes">{t('Observations — optionnel')}</label>
      <textarea id="notes" rows={2} maxLength={2000} value={notes} onChange={(e) => setNotes(e.target.value)} />

      <button type="button" className="btn-secondary" onClick={() => void attachPhoto()}>
        📷 {photoBlob ? t('Reprendre le reçu') : t('Photographier le reçu')}
      </button>
      {photoPreview && <img src={photoPreview} alt={t('Reçu')} className="photo-preview" />}

      <button type="submit" className="btn-primary" disabled={!category || !label.trim() || Number(amount) < 1}>
        {t('Enregistrer la dépense')}
      </button>
    </form>
  )
}
