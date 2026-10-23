<?php

declare(strict_types=1);

namespace Prism\Browser\Capture;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Prism\Browser\Exceptions\BrowserRefused;
use Prism\Prism\ValueObjects\Media\Image;

/**
 * PAGE CAPTURE: screenshots of an operator-declared page at the widths asked for, as Prism images, with the page's
 * measured height and, optionally, a few facts the sidecar measured. For a lab or app looking at its own pages; it is
 * not an agent tool, and the agent browser's policy is untouched by it.
 *
 * The sidecar does the capturing (sidecar/capture.mjs: a separate Chromium pinned to the declared hosts and unable to
 * resolve any other). This class refuses an undeclared URL before any request is made, and trusts nothing the sidecar
 * returns: each PNG's signature and real dimensions are read from its own header and checked against what was asked
 * and what was reported, and every bound is checked again.
 *
 * Refusals are BrowserRefused codes: `capture_origin_not_declared`, `invalid_capture_options`, `capture_too_large`,
 * `capture_timeout`, `capture_failed`, `capture_unavailable` (the sidecar has no declared origins),
 * `malformed_sidecar_response`, `sidecar_failed`.
 */
final readonly class PageCapture
{
    public function __construct(
        private Factory $http,
        private CaptureOrigins $origins,
        private string $baseUrl,
        private string $token,
    ) {}

    /**
     * @return list<CapturedPage> one per requested width, in the order asked
     *
     * @throws BrowserRefused
     */
    public function capture(string $url, CaptureRequest $request): array
    {
        if (! $this->origins->declares($url)) {
            throw new BrowserRefused('capture_origin_not_declared', 'The URL is not on a declared capture origin.');
        }
        try {
            $response = $this->http->baseUrl(rtrim($this->baseUrl, '/'))
                ->withToken($this->token)->acceptJson()
                // The sidecar enforces the capture's own budget; this only stops a sidecar that never answers.
                ->timeout($request->timeoutSeconds + 15)
                ->post('/v1/capture', ['url' => $url, ...$request->toArray()]);
        } catch (ConnectionException) {
            throw new BrowserRefused('sidecar_failed', 'The browser sidecar could not be reached.');
        }
        if (! $response->successful()) {
            $reason = $response->json('error');
            throw new BrowserRefused(is_string($reason) && preg_match('/\A[a-z_]{1,64}\z/', $reason) === 1 ? $reason : 'sidecar_failed', 'The browser sidecar refused or failed the capture.');
        }
        $captures = $response->json('captures');
        if (! is_array($captures) || ! array_is_list($captures) || count($captures) !== count($request->widths)) {
            self::malformed();
        }

        $pages = [];
        foreach ($captures as $index => $capture) {
            $pages[] = self::page($capture, $request->widths[$index], $request);
        }

        return $pages;
    }

    private static function page(mixed $capture, int $width, CaptureRequest $request): CapturedPage
    {
        if (! is_array($capture) || ($capture['width'] ?? null) !== $width) {
            self::malformed();
        }
        foreach (['captured_width', 'viewport_height', 'page_height', 'captured_height'] as $field) {
            if (! is_int($capture[$field] ?? null) || $capture[$field] < 1) {
                self::malformed();
            }
        }
        if (! is_bool($capture['clipped'] ?? null) || ! is_string($capture['png'] ?? null)) {
            self::malformed();
        }
        $png = base64_decode($capture['png'], true);
        if ($png === false || strlen($png) < 24 || substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n" || substr($png, 12, 4) !== 'IHDR') {
            self::malformed();
        }
        $dimensions = unpack('Nwidth/Nheight', substr($png, 16, 8));
        if (! is_array($dimensions)) {
            self::malformed();
        }
        // What the image IS must be what the sidecar SAID, and within what was ASKED.
        if ($dimensions['width'] !== $capture['captured_width'] || $dimensions['height'] !== $capture['captured_height']
            || $dimensions['width'] < $width || $dimensions['height'] > $request->maxHeight
            || ($capture['clipped'] && (! $request->clip || $dimensions['height'] >= $capture['page_height']))) {
            self::malformed();
        }
        if (strlen($png) > $request->maxBytes) {
            throw new BrowserRefused('capture_too_large', 'The capture is larger than allowed.');
        }

        return new CapturedPage(
            width: $width,
            capturedWidth: $dimensions['width'],
            viewportHeight: $capture['viewport_height'],
            pageHeight: $capture['page_height'],
            capturedHeight: $dimensions['height'],
            clipped: $capture['clipped'],
            image: Image::fromRawContent($png, 'image/png'),
            bytes: strlen($png),
            facts: $request->measure ? self::facts($capture['facts'] ?? null) : null,
        );
    }

    private static function facts(mixed $facts): PageFacts
    {
        if (! is_array($facts) || ! is_int($facts['scroll_width'] ?? null) || ! is_bool($facts['horizontal_overflow'] ?? null)
            || ! is_int($facts['small_targets_total'] ?? null) || ! is_array($facts['small_targets'] ?? null)
            || ! array_is_list($facts['small_targets']) || count($facts['small_targets']) > 50) {
            self::malformed();
        }
        $targets = [];
        foreach ($facts['small_targets'] as $target) {
            if (! is_array($target) || ! is_string($target['tag'] ?? null) || ! is_string($target['text'] ?? null)
                || mb_strlen($target['text']) > 80 || strlen($target['tag']) > 32
                || (($target['role'] ?? null) !== null && ! is_string($target['role']))) {
                self::malformed();
            }
            foreach (['x', 'y', 'width', 'height'] as $field) {
                if (! is_int($target[$field] ?? null)) {
                    self::malformed();
                }
            }
            $targets[] = [
                'tag' => $target['tag'],
                'role' => isset($target['role']) ? mb_substr($target['role'], 0, 64) : null,
                'text' => $target['text'],
                'x' => $target['x'], 'y' => $target['y'], 'width' => $target['width'], 'height' => $target['height'],
            ];
        }

        return new PageFacts($facts['scroll_width'], $facts['horizontal_overflow'], $targets, $facts['small_targets_total']);
    }

    private static function malformed(): never
    {
        throw new BrowserRefused('malformed_sidecar_response', 'The browser sidecar returned a capture that does not check out.');
    }
}
