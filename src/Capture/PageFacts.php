<?php

declare(strict_types=1);

namespace Prism\Browser\Capture;

/**
 * What the sidecar measured on a page at one width, by its own script: nothing here comes from the caller or a model.
 *
 * - $scrollWidth against the viewport width gives $horizontalOverflow.
 * - $smallTargets: visible interactive elements narrower or shorter than 44 CSS px (a common minimum touch size), at
 *   most 50, each as tag, role, a text label cut at 80 characters, and its rectangle in page coordinates. The label is
 *   page content, and is as untrusted as anything else the page says. $smallTargetsTotal counts all of them.
 * - $images: each <img>, at most 100, as the source the browser actually selected (`currentSrc`, so the srcset
 *   variant), cut at 2048 characters, and its intrinsic size; 0 x 0 for an image that did not load. $imagesTotal
 *   counts all of them. The source is page content: untrusted.
 * - $fonts: declared FontFaces, at most 50; descriptor strings cut at 128 characters. Page content is untrusted.
 * - $renderedFonts: platform font glyph usage across at most 50 visible text-bearing elements, aggregated by family
 *   and custom status, at most 20 entries. Partial fallback is legitimate for emoji/symbols and is reported here.
 */
final readonly class PageFacts
{
    /**
     * @param  list<array{tag: string, role: ?string, text: string, x: int, y: int, width: int, height: int}>  $smallTargets
     * @param  list<array{src: string, natural_width: int, natural_height: int}>  $images
     * @param  list<array{family: string, weight: string, style: string, status: string}>  $fonts
     * @param  list<array{family: string, glyph_count: int, custom: bool}>  $renderedFonts
     */
    public function __construct(
        public int $scrollWidth,
        public bool $horizontalOverflow,
        public array $smallTargets,
        public int $smallTargetsTotal,
        public array $images = [],
        public int $imagesTotal = 0,
        public array $fonts = [],
        public array $renderedFonts = [],
    ) {}
}
