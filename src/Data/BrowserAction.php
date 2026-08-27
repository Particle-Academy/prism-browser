<?php

declare(strict_types=1);

namespace Prism\Browser\Data;

use Prism\Browser\Enums\ActionKind;

final readonly class BrowserAction
{
    public function __construct(
        public ActionKind $kind,
        public string $observationId,
        public string $ref,
        public string|int|float|bool|null $value = null,
        public ?string $idempotencyKey = null,
    ) {}
}
