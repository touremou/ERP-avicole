/**
 * PeriodSelector — sélecteur de fenêtre temporelle des journaux : Aujourd'hui /
 * Hier / 7 jours. Miroir du paramètre `period` de l'API (App\Support\JournalPeriod).
 */
import { FilterChips } from './FilterChips'
import { t } from '../i18n'

export function PeriodSelector({ period, onChange }: { period: string; onChange: (period: string) => void }) {
  return (
    <FilterChips
      options={[
        { key: 'today', label: t("Aujourd'hui") },
        { key: 'yesterday', label: t('Hier') },
        { key: '7days', label: t('7 jours') },
      ]}
      active={period}
      onChange={onChange}
    />
  )
}
