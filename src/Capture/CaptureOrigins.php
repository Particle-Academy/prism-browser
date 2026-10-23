<?php

declare(strict_types=1);

namespace Prism\Browser\Capture;

use Prism\Browser\Exceptions\BrowserRefused;

/**
 * The origins page capture may load: declared by the OPERATOR in configuration (PRISM_BROWSER_CAPTURE_ORIGINS), never
 * by a model or a request. Each is exactly `scheme://host[:port]`, http or https, and never an IP literal: the sidecar
 * resolves the host once, accepts loopback or a public address (no other private range), and pins its capture browser
 * to that address. See sidecar/capture.mjs, which applies the same rules again.
 *
 * Checked here as well as in the sidecar, so an undeclared URL is refused before any request leaves this process.
 */
final readonly class CaptureOrigins
{
    /** @param list<string> $origins normalised: lower-case scheme and host, no default port, no trailing slash */
    private function __construct(public array $origins) {}

    /**
     * @param  string|list<string>  $declared  a comma-separated list, or a list
     *
     * @throws BrowserRefused `invalid_capture_origin`
     */
    public static function parse(string|array $declared): self
    {
        $entries = is_string($declared) ? explode(',', $declared) : $declared;
        $origins = [];
        foreach ($entries as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }
            $origin = self::originOf(rtrim($entry, '/'));
            if ($origin === null || strtolower(rtrim($entry, '/')) !== $origin) {
                throw new BrowserRefused('invalid_capture_origin', 'A capture origin must be exactly scheme://host[:port], http or https, with no path, query, credentials or IP literal.');
            }
            $origins[$origin] = true;
        }

        return new self(array_keys($origins));
    }

    public function isEmpty(): bool
    {
        return $this->origins === [];
    }

    /** Whether a URL is on one of the declared origins: origin equality, nothing looser. */
    public function declares(string $url): bool
    {
        $origin = self::originOf($url, allowPath: true);

        return $origin !== null && in_array($origin, $this->origins, true);
    }

    /** The origin of an http(s) URL with a host name, as a browser spells it; null for anything else. */
    private static function originOf(string $url, bool $allowPath = false): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        if (! $allowPath && (isset($parts['query']) || isset($parts['fragment']) || (($parts['path'] ?? '') !== '' && $parts['path'] !== '/'))) {
            return null;
        }
        $host = rtrim(strtolower($parts['host']), '.');
        if ($host === '' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false || preg_match('/\A[a-z0-9.-]+\z/', $host) !== 1) {
            return null;
        }
        $port = $parts['port'] ?? null;
        $default = $scheme === 'https' ? 443 : 80;

        return $scheme.'://'.$host.($port === null || $port === $default ? '' : ':'.$port);
    }
}
