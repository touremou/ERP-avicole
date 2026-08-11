<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Abonnement d'un APPAREIL au push navigateur.
 *
 * Aucun droit de module : s'abonner à SES propres alertes ne dépend d'aucune
 * permission métier. C'est le navigateur qui garde la vraie clef du
 * consentement — sans autorisation accordée par l'utilisateur, aucun abonnement
 * ne peut même être créé côté client.
 *
 * Ces routes servent aussi bien la PWA que le web : la clef publique est la même,
 * et un promoteur qui accepte les notifications depuis son navigateur de bureau
 * s'abonne par le même chemin.
 */
class PushSubscriptionController extends Controller
{
    public function __construct(private WebPushService $push) {}

    /**
     * Clef publique VAPID + état de configuration.
     *
     * Le client en a besoin AVANT de pouvoir s'abonner : c'est cette clef que le
     * navigateur transmet au serveur de push du fabricant. Si le push n'est pas
     * configuré, on le dit franchement plutôt que de laisser l'appareil échouer.
     */
    public function key(): JsonResponse
    {
        return response()->json([
            'configured' => $this->push->isConfigured(),
            'public_key' => $this->push->publicKey(),
        ]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint'     => ['required', 'string', 'max:1000', 'url'],
            'keys.p256dh'  => ['required', 'string', 'max:255'],
            'keys.auth'    => ['required', 'string', 'max:255'],
            'device_label' => ['nullable', 'string', 'max:120'],
        ]);

        if (! $this->push->isConfigured()) {
            return response()->json([
                'message' => __('Le push n’est pas configuré sur ce serveur.'),
            ], 503);
        }

        $subscription = PushSubscription::register(
            $request->user()->id,
            ['endpoint' => $data['endpoint'], 'keys' => $data['keys']],
            $data['device_label'] ?? null,
        );

        return response()->json([
            'message' => __('Cet appareil recevra les alertes.'),
            'id'      => $subscription->id,
        ]);
    }

    /**
     * Désabonnement d'un appareil.
     *
     * On borne à SES abonnements : un endpoint est une chaîne fournie par le
     * client, on ne veut pas qu'il puisse désabonner le téléphone de quelqu'un
     * d'autre en le devinant.
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000'],
        ]);

        PushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint_hash', PushSubscription::hashFor($data['endpoint']))
            ->delete();

        return response()->json(['message' => __('Cet appareil ne recevra plus d’alertes.')]);
    }

    /**
     * Envoi de test vers les appareils de l'utilisateur.
     *
     * Indispensable au terrain : « ai-je bien accepté les notifications ? » ne se
     * vérifie autrement qu'en attendant une vraie alerte.
     */
    public function test(Request $request): JsonResponse
    {
        $result = $this->push->sendToUser($request->user(), [
            'title'    => __('Test de notification'),
            'body'     => __('Si vous lisez ceci, cet appareil recevra bien les alertes.'),
            'severity' => 'normal',
            // ADRESSE DU TERRAIN. Un push est délivré à la PWA, dont le routeur
            // ne connaît que ses propres chemins : « /notifications » est une route
            // du BUREAU, et la bannière touchée renvoyait donc à l'accueil.
            //
            // Le commentaire précédent affirmait que « /alertes » n'existait pas :
            // c'est vrai du routeur web, et faux de la PWA, où c'est précisément le
            // centre d'alertes (cf. mobile/src/offline/access.ts et le repli de
            // AlertNotification::toWebPush, qui applique déjà la bonne règle).
            //
            // L'outil censé lever un doute — « mes alertes arrivent-elles ? » — en
            // ajoutait un : rien ne distinguait « le push ne marche pas » de « le
            // push marche et m'emmène au mauvais endroit ».
            'url'      => '/alertes',
            'tag'      => 'test',
        ]);

        return response()->json([
            'message' => $result['sent'] > 0
                ? __(':count appareil(s) joint(s).', ['count' => $result['sent']])
                : __('Aucun appareil abonné n’a pu être joint.'),
            'result'  => $result,
        ], $result['sent'] > 0 ? 200 : 422);
    }
}
