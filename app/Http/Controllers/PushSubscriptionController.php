<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Stores and removes the browser's Web Push subscription (Settings → Notifications).
 */
class PushSubscriptionController extends Controller
{
    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'url:https', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'in:aesgcm,aes128gcm'],
        ]);

        $request->user()->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
            $validated['contentEncoding'] ?? 'aes128gcm',
        );

        return response()->noContent();
    }

    public function destroy(Request $request): Response
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2000'],
        ]);

        $request->user()->deletePushSubscription($validated['endpoint']);

        return response()->noContent();
    }
}
