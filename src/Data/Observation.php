<?php

declare(strict_types=1);

namespace Prism\Browser\Data;

final readonly class Observation
{
    /**
     * @param  list<array{ref:string, role:string, name:string, value?:mixed, disabled?:bool}>  $elements
     * @param  list<string>  $visibleText
     */
    public function __construct(
        public string $id,
        public string $url,
        public string $origin,
        public string $title,
        public array $elements,
        public array $visibleText,
        public bool $truncated = false,
        public ?string $fallback = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'mode' => 'browser', 'observation_id' => $this->id, 'url' => $this->url,
            'origin' => $this->origin, 'title' => $this->title, 'elements' => $this->elements,
            'visible_text' => $this->visibleText, 'fallback' => $this->fallback,
            'truncated' => $this->truncated,
        ];
    }
}
