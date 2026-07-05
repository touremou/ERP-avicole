<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Centre de notifications mobile — lit les notifications « database »
 * (AlertNotification : cloche du web) de l'utilisateur courant.
 *
 * Le client les met en miroir local (lecture hors-ligne) ; le push FCM/APNs
 * viendra plus tard via la rampe Capacitor — ici c'est du pull, rafraîchi à
 * chaque cycle de sync.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'since' => 'nullable|date',
        ]);

        $query = $request->user()->notifications()->latest();

        if (isset($validated['since'])) {
            $query->where('created_at', '>', $validated['since']);
        }

        $notifications = $query->limit(50)->get()->map(fn ($n) => [
            'id'         => $n->id,
            'type'       => $n->data['type'] ?? 'general',
            'title'      => $n->data['title'] ?? 'Alerte',
            'message'    => $n->data['message'] ?? '',
            'severity'   => $n->data['severity'] ?? 'normal',
            'url'        => $n->data['url'] ?? null,
            'read_at'    => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at->toIso8601String(),
        ])->values();

        return response()->json([
            'notifications' => $notifications,
            'unread_count'  => $request->user()->unreadNotifications()->count(),
            'server_time'   => now()->toIso8601String(),
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        // Borné aux notifications de l'utilisateur (404 sinon, sans fuite).
        $notification = $request->user()->notifications()->where('id', $id)->first();

        if (! $notification) {
            return response()->json(['message' => 'Notification introuvable.'], 404);
        }

        $notification->markAsRead();

        return response()->json(['message' => 'Lue.']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['message' => 'Toutes lues.']);
    }
}
