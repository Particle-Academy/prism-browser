<?php

declare(strict_types=1);

namespace Prism\Browser\Contracts;

use Prism\Browser\Data\BrowserAction;
use Prism\Browser\Data\Observation;

interface BrowserEngine
{
    public function open(string $attachmentId, ?string $checkpoint = null): void;

    public function navigate(string $attachmentId, string $url): Observation;

    public function observe(string $attachmentId): Observation;

    public function act(string $attachmentId, BrowserAction $action): Observation;

    public function checkpoint(string $attachmentId): ?string;

    public function close(string $attachmentId): void;
}
