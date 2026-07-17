/**
 * Icône iconographique d'une notification, partagée par le centre d'alertes
 * et l'aperçu « Dernières alertes » de l'accueil. Le `type` serveur est une
 * chaîne libre (alert_mortality, stock_low, weather_forecast…) : on classe par
 * mot-clé, avec repli sur l'icône de sévérité.
 */

const TYPE_ICON: { match: RegExp; icon: string }[] = [
  { match: /mortalit|mortality/, icon: '💀' },
  { match: /stock|threshold|alert_min/, icon: '📦' },
  { match: /weather|meteo/, icon: '🌦️' },
  { match: /temperature|temp/, icon: '🌡️' },
  { match: /haccp|ccp|cleaning|hygien/, icon: '🧪' },
  { match: /health|sante|vaccin/, icon: '🩺' },
  { match: /leave|conge/, icon: '🌴' },
  { match: /maintenance|energy|fuel/, icon: '🔧' },
  { match: /payment|paiement|fraud/, icon: '💰' },
  { match: /sale|vente|invoice|bl|pos|dispatch/, icon: '🧾' },
  { match: /expense|depense|budget/, icon: '💸' },
  { match: /incident/, icon: '⚠️' },
  { match: /task|tache/, icon: '📋' },
]

const SEVERITY_ICON: Record<string, string> = {
  critical: '🔴',
  warning: '🟠',
  normal: '🔔',
}

export function notifIcon(type: string, severity: string): string {
  const found = TYPE_ICON.find((entry) => entry.match.test(type ?? ''))
  return found?.icon ?? SEVERITY_ICON[severity] ?? '🔔'
}
