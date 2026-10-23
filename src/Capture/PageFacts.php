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
 */
final readonly class PageFacts
{
    /**
     * @param  list<array{tag: string, role: ?string, text: string, x: int, y: int, width: int, height: int}>  $smallTargets
     */
    public function __construct(
        public int $scrollWidth,
        public bool $horizontalOverflow,
        public array $smallTargets,
        public int $smallTargetsTotal,
    ) {}
}
