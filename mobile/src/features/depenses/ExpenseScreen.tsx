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
  const [supplierName, setSupplierName] = useState('')
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
      payment_method: 'especes',
      supplier_name: supplierName.trim() || null,
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

    await enqueue('expense.create', payload, `Dépense ${label.trim()}`)

    setSaved(true)
    setTimeout(() => navigate('/'), 900)
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">✓ Dépense enregistrée</p>
        <p className="muted">Elle attend la validation du gestionnaire (en ligne).</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>🧾 Dépense terrain</h2>

      <label htmlFor="category">Catégorie</label>
      <select id="category" required value={category} onChange={(e) => setCategory(e.target.value)}>
        <option value="" disabled>
          — Choisir —
        </option>
        {Object.entries(CATEGORIES).map(([value, text]) => (
          <option key={value} value={value}>
            {text}
          </option>
        ))}
      </select>

      <label htmlFor="label">Libellé</label>
      <input
        id="label"
        required
        maxLength={255}
        value={label}
        onChange={(e) => setLabel(e.target.value)}
        placeholder="ex. Gasoil groupe électrogène"
      />

      <label htmlFor="amount">Montant</label>
      <input
        id="amount"
        type="number"
        inputMode="numeric"
        required
        min="1"
        value={amount}
        onChange={(e) => setAmount(e.target.value)}
      />

      <label htmlFor="supplier">Fournisseur / bénéficiaire — optionnel</label>
      <input id="supplier" maxLength={255} value={supplierName} onChange={(e) => setSupplierName(e.target.value)} />

      <button type="button" className="btn-secondary" onClick={() => void attachPhoto()}>
        📷 {photoBlob ? 'Reprendre le reçu' : 'Photographier le reçu'}
      </button>
      {photoPreview && <img src={photoPreview} alt="Reçu" className="photo-preview" />}

      <button type="submit" className="btn-primary" disabled={!category || !label.trim() || Number(amount) < 1}>
        Enregistrer la dépense
      </button>
    </form>
  )
}
