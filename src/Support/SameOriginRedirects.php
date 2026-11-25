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
 * An application that turned redirects off (`Http::globalOptions(['allow_redirects'
 * => false])`, or any other empty value: 0, '' or null) keeps them off: nothing
 * is followed.
 */
final class SameOriginRedirects
{
    /**
     * The request, restricted to same-origin redirects, or left following none
     * when redirects were already turned off for it.
     *
     * @param  Closure(): Throwable  $refusal  what to throw when the origin changes
     */
    public static function apply(PendingRequest $request, string $url, Closure $refusal): PendingRequest
    {
        $options = $request->getOptions();

        // Any empty value is off to Guzzle (false, 0, '', null). Sent on as an
        // explicit false, so it reads the same to every client version.
        if (array_key_exists('allow_redirects', $options) && empty($options['allow_redirects'])) {
            return $request->withOptions(['allow_redirects' => false]);
        }

        return $request->withOptions(self::options($url, $refusal));
    }

    /**
     * @param  Closure(): Throwable  $refusal  what to throw when the origin changes
     * @return array{allow_redirects: array{max: int, on_redirect: Closure}}
     */
    public static function options(string $url, Closure $refusal): array
    {
        $origin = self::origin($url);

        return ['allow_redirects' => [
            'max' => 5,
            'on_redirect' => static function (mixed $request, mixed $response, Stringable $next) use ($origin, $refusal): void {
                if ($origin === null || self::origin((string) $next) !== $origin) {
                    throw $refusal();
                }
            },
        ]];
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
