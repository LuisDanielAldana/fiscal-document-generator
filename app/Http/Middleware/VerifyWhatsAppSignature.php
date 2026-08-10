<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects webhook calls that Meta did not sign.
 *
 * The endpoint is public by necessity, and everything behind it acts on the
 * caller's word about who sent a message. Without this check anyone who learns the
 * URL could impersonate a taxpayer's number and be sent their documents, so an
 * unsigned or mis-signed request is refused rather than logged and processed.
 */
class VerifyWhatsAppSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.whatsapp.app_secret');

        if ($secret === '') {
            // Failing closed: an unset secret in production would otherwise turn
            // this middleware into a no-op and the endpoint into an open door.
            Log::error('A WhatsApp webhook arrived but WHATSAPP_APP_SECRET is not configured.');

            abort(Response::HTTP_SERVICE_UNAVAILABLE, 'Webhook verification is not configured.');
        }

        $signature = (string) $request->header('X-Hub-Signature-256');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        if ($signature === '' || ! hash_equals($expected, $signature)) {
            Log::warning('Rejected a WhatsApp webhook with an invalid signature.', [
                'ip' => $request->ip(),
            ]);

            abort(Response::HTTP_FORBIDDEN, 'Invalid signature.');
        }

        return $next($request);
    }
}
