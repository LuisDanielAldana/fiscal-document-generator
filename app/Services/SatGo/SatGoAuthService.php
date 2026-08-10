<?php

namespace App\Services\SatGo;

use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Exchanges the permanent SatGo API key for the short-lived JWT that every
 * document request carries.
 *
 * The token is cached until shortly before SatGo says it expires, so a night of
 * generation costs one authentication rather than one per document.
 */
class SatGoAuthService
{
    private const string CACHE_KEY = 'satgo:jwt';

    /**
     * How far ahead of the advertised expiry the cached token is dropped, to
     * cover clock skew and a request that starts just before the boundary.
     */
    private const int EXPIRY_MARGIN_MINUTES = 5;

    /**
     * Used when SatGo omits `expiresAt`, and as a ceiling: caching a token for
     * longer than this risks holding one the service has already rotated.
     */
    private const int FALLBACK_TTL_HOURS = 6;

    /**
     * @throws SatGoException
     */
    public function token(): string
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->requestToken();
    }

    /**
     * Drop the cached token so the next call authenticates again.
     *
     * Used when SatGo rejects a request as unauthorized: the token may have been
     * revoked before its stated expiry, and retrying with it would fail the same
     * way on every attempt.
     */
    public function forgetToken(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @throws SatGoException
     */
    private function requestToken(): string
    {
        $response = Http::baseUrl($this->baseUrl())
            ->asJson()
            ->post('/api/auth/token-json', ['key' => config('services.satgo.api_key')]);

        if ($response->failed()) {
            throw SatGoException::authenticationFailed($response->status(), $response->body());
        }

        $token = $response->json('token');

        if (! is_string($token) || $token === '') {
            throw SatGoException::authenticationFailed($response->status(), $response->body());
        }

        Cache::put(self::CACHE_KEY, $token, $this->ttlFor($response->json('expiresAt')));

        return $token;
    }

    /**
     * When the cached token should be dropped, given SatGo's stated expiry.
     */
    private function ttlFor(mixed $expiresAt): DateTimeInterface
    {
        $fallback = now()->addHours(self::FALLBACK_TTL_HOURS);

        if (! is_string($expiresAt) || $expiresAt === '') {
            return $fallback;
        }

        try {
            $expiry = now()->parse($expiresAt)->subMinutes(self::EXPIRY_MARGIN_MINUTES);
        } catch (Throwable) {
            return $fallback;
        }

        // An expiry already past would cache nothing at all; an implausibly
        // distant one would outlive the real token.
        return match (true) {
            $expiry->isPast() => now()->addMinute(),
            $expiry->isAfter($fallback) => $fallback,
            default => $expiry,
        };
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.satgo.base_url'), '/');
    }
}
