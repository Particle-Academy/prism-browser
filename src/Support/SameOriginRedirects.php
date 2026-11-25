<?php

declare(strict_types=1);

namespace Prism\Browser\Support;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Stringable;
use Throwable;

/**
 * Redirect handling for requests to the browser sidecar.
 *
 * A redirect is followed only when it keeps the scheme, host and effective
 * port of the configured sidecar URL. Anything else is refused before the next
 * request is built, so the other server receives nothing. The HTTP client's own
 * redirect handling drops `Authorization` on a cross-origin hop but still sends
 * the request, and its body, to wherever the response pointed.
 *
 * Every hop is checked against the configured origin rather than the previous
 * hop, which is the same answer when each hop must match, and needs nothing
 * from the request objects.
 *
 * The application's own `allow_redirects` setting is read the way the HTTP
 * client reads it (see hopLimit()). When it turned redirects off, nothing is
 * followed. When it allows fewer than 5 hops, that smaller limit holds. Its own
 * `on_redirect` hook is still called, but only for a hop that passed the origin
 * check.
 */
final class SameOriginRedirects
{
    /** The most redirects a sidecar call follows, whatever the application allows. */
    private const MAX_HOPS = 5;

    /**
     * The request, restricted to same-origin redirects, or left following none
     * when redirects were already turned off for it.
     *
     * @param  Closure(): Throwable  $refusal  what to throw when the origin changes
     */
    public static function apply(PendingRequest $request, string $url, Closure $refusal): PendingRequest
    {
        $options = $request->getOptions();
        $setting = array_key_exists('allow_redirects', $options) ? $options['allow_redirects'] : true;
        $hops = self::hopLimit($setting);

        // Sent on as an explicit false, so it reads the same to every client version.
        if ($hops === null) {
            return $request->withOptions(['allow_redirects' => false]);
        }

        $hook = is_array($setting) && isset($setting['on_redirect']) && is_callable($setting['on_redirect'])
            ? $setting['on_redirect']
            : null;

        return $request->withOptions(self::options($url, $refusal, $hops, $hook));
    }

    /**
     * @param  Closure(): Throwable  $refusal  what to throw when the origin changes
     * @param  int  $hops  follow at most this many redirects (1 to 5)
     * @param  callable|null  $hook  the application's own on_redirect, called only after the origin check passes
     * @return array{allow_redirects: array{max: int, on_redirect: Closure}}
     */
    public static function options(string $url, Closure $refusal, int $hops = self::MAX_HOPS, ?callable $hook = null): array
    {
        $origin = self::origin($url);

        return ['allow_redirects' => [
            'max' => max(1, min($hops, self::MAX_HOPS)),
            'on_redirect' => static function (mixed $request, mixed $response, Stringable $next) use ($origin, $refusal, $hook): void {
                if ($origin === null || self::origin((string) $next) !== $origin) {
                    throw $refusal();
                }
                if ($hook !== null) {
                    $hook($request, $response, $next);
                }
            },
        ]];
    }

    /**
     * How many redirects an `allow_redirects` setting allows, or null when it
     * allows none. The same reading as the HTTP client's (Guzzle's
     * RedirectMiddleware), capped at 5:
     *
     * - `true` allows 5.
     * - Any other value that is not an array is off. The empty ones (false,
     *   null, 0, '', '0') are off to the client as well; the rest (`'false'`,
     *   `1`, an object) the client rejects outright, and they are read as off
     *   rather than replaced by a default that follows.
     * - An empty array is off; an array without `max` allows 5.
     * - `max` must be a positive integer or a string of digits. Anything else
     *   (0, null, false, true, a negative number, a fraction, other text) is off.
     *
     * @internal
     */
    public static function hopLimit(mixed $setting): ?int
    {
        if ($setting === true) {
            return self::MAX_HOPS;
        }
        if (! is_array($setting) || $setting === []) {
            return null;
        }
        if (! array_key_exists('max', $setting)) {
            return self::MAX_HOPS;
        }

        $max = $setting['max'];
        if (is_string($max) && strlen($max) <= 9 && ctype_digit($max)) {
            $max = (int) $max;
        }
        if (! is_int($max) || $max < 1) {
            return null;
        }

        return min($max, self::MAX_HOPS);
    }

    /**
     * `scheme://host:port`, with the port made explicit, or null for anything
     * that is not an absolute http(s) URL.
     */
    public static function origin(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? match ($scheme) {
            'http' => 80,
            'https' => 443,
            default => null,
        };

        return $port === null ? null : $scheme.'://'.strtolower($parts['host']).':'.$port;
    }
}
