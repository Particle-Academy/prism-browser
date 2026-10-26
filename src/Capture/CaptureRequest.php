<?php

declare(strict_types=1);

namespace Prism\Browser\Capture;

use Prism\Browser\Exceptions\BrowserRefused;

/**
 * What to capture of one page: the widths, and the bounds. Every field is bounded here and again in the sidecar;
 * anything out of bounds is refused (`invalid_capture_options`), never quietly adjusted.
 *
 * - widths: CSS pixels, 240..3840, at most 8; duplicates are dropped.
 * - fullPage: the whole page (default) or the first viewport only. A full page is as wide as its content: a page that
 *   overflows the viewport is captured at its real width, and CapturedPage::$capturedWidth says so.
 * - clip: when the page is taller than maxHeight, cut it there and say so (CapturedPage::$clipped) instead of refusing
 *   (`capture_too_large`, the default).
 * - waitForReadyAttribute: also wait until the PAGE sets `data-prism-capture-ready` on <html>. Always waited for:
 *   the load event, network idle and document.fonts.ready. None of it is supplied by the caller.
 * - measure: return the facts the sidecar measures at each width (horizontal overflow, small interactive targets,
 *   and each image's selected source and intrinsic size).
 * - requireImages: before capturing, every <img> must have loaded AND decoded, with a non-zero intrinsic width;
 *   otherwise the capture is refused (`capture_images_failed`) and BrowserRefused::context() names the failed
 *   images. load, network idle, fonts and a ready marker are all satisfied by a page whose image 404s, so without
 *   this a broken page is captured as if it were the real one. A lazy image that never loads counts as failed:
 *   render captures with eager images. Works on a page that allows no script.
 */
final readonly class CaptureRequest
{
    public const READY_ATTRIBUTE = 'data-prism-capture-ready';

    /** @var list<int> */
    public array $widths;

    /**
     * @param  array<mixed>  $widths  integers; anything else is refused at runtime, as a caller's array may hold anything
     *
     * @throws BrowserRefused `invalid_capture_options`
     */
    public function __construct(
        array $widths,
        public bool $fullPage = true,
        public bool $clip = false,
        public bool $waitForReadyAttribute = false,
        public bool $measure = true,
        public int $viewportHeight = 900,
        public int $maxHeight = 16384,
        public int $maxBytes = 15 * 1024 * 1024,
        public int $timeoutSeconds = 30,
        public bool $requireImages = false,
    ) {
        $widths = array_values(array_unique($widths));
        if ($widths === [] || count($widths) > 8) {
            self::invalid();
        }
        foreach ($widths as $width) {
            if (! is_int($width) || $width < 240 || $width > 3840) {
                self::invalid();
            }
        }
        if ($viewportHeight < 200 || $viewportHeight > 4320 || $maxHeight < 200 || $maxHeight > 32768
            || $maxBytes < 1024 || $maxBytes > 25 * 1024 * 1024 || $timeoutSeconds < 1 || $timeoutSeconds > 120) {
            self::invalid();
        }
        $this->widths = $widths;
    }

    /** @return array<string, mixed> the sidecar's request body, less the URL */
    public function toArray(): array
    {
        return [
            'widths' => $this->widths,
            'full_page' => $this->fullPage,
            'clip' => $this->clip,
            'wait_for_ready_attribute' => $this->waitForReadyAttribute,
            'measure' => $this->measure,
            'viewport_height' => $this->viewportHeight,
            'max_height' => $this->maxHeight,
            'max_bytes' => $this->maxBytes,
            'timeout_ms' => $this->timeoutSeconds * 1000,
            'require_images' => $this->requireImages,
        ];
    }

    private static function invalid(): never
    {
        throw new BrowserRefused('invalid_capture_options', 'Capture options are out of bounds.');
    }
}
