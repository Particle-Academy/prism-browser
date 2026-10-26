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
            $failedImages = $reason === 'capture_images_failed' ? self::failedImages($response->json()) : [];
            throw new BrowserRefused(is_string($reason) && preg_match('/\A[a-z_]{1,64}\z/', $reason) === 1 ? $reason : 'sidecar_failed', 'The browser sidecar refused or failed the capture.', $failedImages);
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

        $images = self::images($facts['images'] ?? null);
        $imagesTotal = $facts['images_total'] ?? count($images);
        if (! is_int($imagesTotal) || $imagesTotal < count($images)) {
            self::malformed();
        }

        return new PageFacts($facts['scroll_width'], $facts['horizontal_overflow'], $targets, $facts['small_targets_total'], $images, $imagesTotal);
    }

    /**
     * `capture_images_failed` detail, validated like any other sidecar output: the images that did not load or
     * decode. Malformed detail is dropped rather than trusted; the refusal itself still stands.
     *
     * @return array{failed_images?: list<array{src: string, natural_width: int, natural_height: int, complete: bool, decoded: bool}>, failed_images_total?: int}
     */
    private static function failedImages(mixed $body): array
    {
        if (! is_array($body) || ! is_array($body['failed_images'] ?? null) || ! array_is_list($body['failed_images'])
            || count($body['failed_images']) > 100 || ! is_int($body['failed_images_total'] ?? null)) {
            return [];
        }
        $images = [];
        foreach ($body['failed_images'] as $image) {
            if (! is_array($image) || ! is_string($image['src'] ?? null) || strlen($image['src']) > 2048
                || ! is_int($image['natural_width'] ?? null) || ! is_int($image['natural_height'] ?? null)
                || ! is_bool($image['complete'] ?? null) || ! is_bool($image['decoded'] ?? null)) {
                return [];
            }
            $images[] = [
                'src' => $image['src'], 'natural_width' => $image['natural_width'], 'natural_height' => $image['natural_height'],
                'complete' => $image['complete'], 'decoded' => $image['decoded'],
            ];
        }

        return ['failed_images' => $images, 'failed_images_total' => $body['failed_images_total']];
    }

    /**
     * Each image the sidecar measured; none from a sidecar that measured none.
     *
     * @return list<array{src: string, natural_width: int, natural_height: int}>
     */
    private static function images(mixed $images): array
    {
        if ($images === null) {
            return [];
        }
        if (! is_array($images) || ! array_is_list($images) || count($images) > 100) {
            self::malformed();
        }
        $out = [];
        foreach ($images as $image) {
            if (! is_array($image) || ! is_string($image['src'] ?? null) || strlen($image['src']) > 2048
                || ! is_int($image['natural_width'] ?? null) || $image['natural_width'] < 0
                || ! is_int($image['natural_height'] ?? null) || $image['natural_height'] < 0) {
                self::malformed();
            }
            $out[] = ['src' => $image['src'], 'natural_width' => $image['natural_width'], 'natural_height' => $image['natural_height']];
        }

        return $out;
    }

    private static function malformed(): never
    {
        throw new BrowserRefused('malformed_sidecar_response', 'The browser sidecar returned a capture that does not check out.');
    }
}
