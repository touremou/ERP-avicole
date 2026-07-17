<?php

/*
 * Helper notif_icon() : classe une notification (type libre + sévérité) sur
 * une icône, miroir de la logique mobile. Sert la cloche web.
 */

test('classe par mot-clé du type', function () {
    expect(notif_icon('alert_mortality', 'critique'))->toBe('💀')
        ->and(notif_icon('stock_low', 'attention'))->toBe('📦')
        ->and(notif_icon('weather_forecast', null))->toBe('🌦️')
        ->and(notif_icon('alert_haccp', null))->toBe('🧪')
        ->and(notif_icon('sale_created', null))->toBe('🧾')
        ->and(notif_icon('payment_reminder', null))->toBe('💰');
});

test('repli sur la sévérité quand le type est inconnu (libellés web et mobile)', function () {
    expect(notif_icon('general', 'critique'))->toBe('🔴')
        ->and(notif_icon('general', 'critical'))->toBe('🔴')
        ->and(notif_icon('xyz', 'attention'))->toBe('🟠')
        ->and(notif_icon('xyz', 'warning'))->toBe('🟠')
        ->and(notif_icon(null, null))->toBe('🔔');
});
