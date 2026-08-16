/**
 * Affecter une tâche — au rassemblement du matin, pas au bureau le soir.
 *
 * Le terrain pouvait déjà créer une tâche POUR SOI (`task.create`, sans droit
 * RH). Répartir le travail entre collègues restait une opération web : le chef
 * d'équipe notait sur un papier et ressaisissait plus tard.
 *
 * Contrat : SyncService::taskDispatch (gate rh.C), qui porte les MÊMES règles
 * que le formulaire du bureau — employé affectable, pas en congé à la date
 * prévue, service correspondant à la catégorie.
 *
 * Deux de ces trois règles sont VÉRIFIÉES ICI aussi, sur le miroir local :
 * un refus qui n'arrive qu'à la synchronisation arrive après le geste, et le
 * chef d'équipe a déjà annoncé la consigne à son collègue. Le serveur reste
 * l'autorité — le miroir peut dater —, mais l'écran ne laisse pas partir ce
 * qu'il sait déjà refusé.
 *
 * Sans personne désignée, la tâche part au POOL : le premier qui la prend se
 * l'attribue. C'est le comportement du bureau, et il est dérivé, pas codé en
 * dur (une tâche « ni à quelqu'un, ni libre » n'apparaît sur aucun téléphone).
 */
import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { db } from '../../offline/db'
import { useAuth } from '../../app/AuthContext'
import { enqueue } from '../../offline/sync'
import { safeLoad } from '../../offline/safeLoad'
import { t } from '../../i18n'
import type { RefEmployee, RefEmployeeLeave } from '../../api/types'

/** Catégories servies par le serveur — jamais une liste en dur ici. */
type Categorie = { key: string; label: string; emoji: string; group: string }

const PRIORITES = [
  { value: 'basse', label: 'Basse' },
  { value: 'normale', label: 'Normale' },
  { value: 'haute', label: 'Haute' },
  { value: 'critique', label: 'Critique' },
] as const

export function AffecterTacheScreen() {
  const navigate = useNavigate()

  const { me } = useAuth()

  const [employes, setEmployes] = useState<RefEmployee[]>([])
  const [conges, setConges] = useState<RefEmployeeLeave[]>([])

  // Servies par le serveur avec la session, donc disponibles hors ligne —
  // même source que la liste des tâches, pour qu'une catégorie ajoutée au
  // bureau apparaisse ici sans toucher au mobile.
  const categories: Categorie[] = me?.settings?.task_categories ?? []

  const [titre, setTitre] = useState('')
  const [categorie, setCategorie] = useState('')
  const [employeId, setEmployeId] = useState('')
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10))
  const [priorite, setPriorite] = useState<string>('normale')
  const [description, setDescription] = useState('')
  const [envoye, setEnvoye] = useState(false)

  useEffect(() => {
    void safeLoad('affectation de tâche', async () => {
      setEmployes(await db.ref_employees.where('status').equals('Actif').toArray())
      setConges(await db.ref_employee_leaves.toArray())
    })
  }, [])

  /** Services autorisés pour la catégorie choisie, servis avec elle. */
  const groupeAttendu = useMemo(
    () => categories.find((c) => c.key === categorie)?.group ?? null,
    [categories, categorie],
  )

  const employe = useMemo(
    () => employes.find((e) => e.id === Number(employeId)) ?? null,
    [employes, employeId],
  )

  /*
   * PRÉ-VÉRIFICATION DES CONGÉS, sur le miroir local.
   *
   * Le serveur refuse définitivement une affectation à quelqu'un en congé à la
   * date prévue. On le dit ici, avant l'envoi — l'information est déjà sur le
   * téléphone (`ref_employee_leaves`), il n'y a aucune raison d'attendre.
   */
  const enConge = useMemo(() => {
    if (!employe) return false

    return conges.some(
      (c) =>
        c.employee_id === employe.id &&
        ['approuve', 'en_cours'].includes(c.status) &&
        c.start_date.slice(0, 10) <= date &&
        c.end_date.slice(0, 10) >= date,
    )
  }, [conges, employe, date])

  /*
   * PRÉ-VÉRIFICATION DU SERVICE. Le champ `department` descend depuis peu avec
   * les employés, précisément pour rendre ce contrôle possible au terrain.
   */
  const mauvaisService = useMemo(() => {
    if (!employe || !groupeAttendu || !employe.department) return false

    return employe.department !== groupeAttendu
  }, [employe, groupeAttendu])

  const valide = titre.trim() !== '' && categorie !== '' && !enConge && !mauvaisService

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!valide) return

    await enqueue(
      'task.dispatch',
      {
        title: titre.trim(),
        category: categorie,
        employee_id: employeId ? Number(employeId) : null,
        scheduled_date: date,
        priority: priorite,
        description: description.trim() || null,
      },
      employe
        ? t('Tâche « :titre » → :prenom', { titre: titre.trim(), prenom: employe.first_name ?? '' })
        : t('Tâche « :titre » → libre-service', { titre: titre.trim() }),
    )

    setEnvoye(true)
    setTimeout(() => navigate('/taches'), 900)
  }

  if (envoye) {
    return (
      <div className="screen-center">
        <p className="success big">{t('✓ Tâche affectée')}</p>
        <p className="muted">
          {employe
            ? t('Elle apparaîtra dans la liste de :prenom.', { prenom: employe.first_name ?? '' })
            : t('Elle part au libre-service : le premier qui la prend se l’attribue.')}
        </p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>{t('🧑‍🌾 Affecter une tâche')}</h2>
      <p className="muted">{t('Au rassemblement — la consigne part avec la personne.')}</p>

      <label htmlFor="titre">{t('Quoi faire ?')}</label>
      <input
        id="titre"
        type="text"
        required
        maxLength={255}
        value={titre}
        onChange={(e) => setTitre(e.target.value)}
        placeholder={t('ex. Nettoyage du poulailler B2')}
      />

      <label htmlFor="categorie">{t('Catégorie')}</label>
      <select id="categorie" required value={categorie} onChange={(e) => setCategorie(e.target.value)}>
        <option value="">{t('— choisir —')}</option>
        {categories.map((c) => (
          <option key={c.key} value={c.key}>
            {c.emoji} {t(c.label)}
          </option>
        ))}
      </select>

      <label htmlFor="employe">{t('Pour qui ?')}</label>
      <select id="employe" value={employeId} onChange={(e) => setEmployeId(e.target.value)}>
        <option value="">{t('🙌 Personne — libre-service')}</option>
        {employes.map((e) => (
          <option key={e.id} value={e.id}>
            {e.first_name} {e.last_name}
            {e.job_title ? ` · ${e.job_title}` : ''}
          </option>
        ))}
      </select>

      {enConge && (
        <p className="proof-hint" role="alert">
          ⛔{' '}
          {t(':prenom est en congé le :date — choisissez un collègue disponible.', {
            prenom: employe?.first_name ?? '',
            date: new Date(date).toLocaleDateString(),
          })}
        </p>
      )}

      {mauvaisService && (
        <p className="proof-hint" role="alert">
          ⛔{' '}
          {t(':prenom (:service) n’est pas du service concerné par cette catégorie.', {
            prenom: employe?.first_name ?? '',
            service: employe?.department ?? '',
          })}
        </p>
      )}

      <label htmlFor="date">{t('Pour quand ?')}</label>
      <input id="date" type="date" required value={date} onChange={(e) => setDate(e.target.value)} />

      <label>{t('Priorité')}</label>
      <div className="chip-row">
        {PRIORITES.map((p) => (
          <button
            key={p.value}
            type="button"
            className={`chip ${priorite === p.value ? 'chip-on' : ''}`}
            aria-pressed={priorite === p.value}
            onClick={() => setPriorite(p.value)}
          >
            {t(p.label)}
          </button>
        ))}
      </div>

      <label htmlFor="description">{t('Consigne — optionnel')}</label>
      <textarea
        id="description"
        rows={2}
        maxLength={500}
        value={description}
        onChange={(e) => setDescription(e.target.value)}
      />

      <button type="submit" className="btn-primary" disabled={!valide}>
        {t('Affecter')}
      </button>
    </form>
  )
}
