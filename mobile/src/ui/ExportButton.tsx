/**
 * ExportButton — bouton « Exporter / Partager » standard des journaux.
 */
import { t } from '../i18n'

export function ExportButton({ onExport, disabled }: { onExport: () => void; disabled?: boolean }) {
  return (
    <button type="button" className="export-btn" onClick={onExport} disabled={disabled}>
      ⬆︎ {t('Exporter / Partager')}
    </button>
  )
}
