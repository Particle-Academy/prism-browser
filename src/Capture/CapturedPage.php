<?php

declare(strict_types=1);

namespace Prism\Browser\Capture;

use Prism\Prism\ValueObjects\Media\Image;

/**
 * One width of a page capture. The image is a Prism `Image` (image/png), ready to attach to a model call as it is.
 *
 * - $width: the viewport width asked for; $capturedWidth: the width of the image, larger when the page overflows.
 * - $pageHeight: the page's full height at this width; $capturedHeight: the image's height, which is smaller only when
 *   $clipped (the caller allowed cutting at maxHeight) or for a first-viewport capture.
 * - $facts: what the sidecar measured at this width, when measuring was asked for; null otherwise.
 */
final readonly class CapturedPage
{
    public function __construct(
        public int $width,
        public int $capturedWidth,
        public int $viewportHeight,
        public int $pageHeight,
        public int $capturedHeight,
        public bool $clipped,
        public Image $image,
        public int $bytes,
        public ?PageFacts $facts,
    ) {}
}
