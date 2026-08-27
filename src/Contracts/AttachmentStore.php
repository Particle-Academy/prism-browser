<?php

declare(strict_types=1);

namespace Prism\Browser\Contracts;

use Closure;
use Prism\Browser\Data\BrowserAttachment;

interface AttachmentStore
{
    public function get(string $id): ?BrowserAttachment;

    public function put(BrowserAttachment $attachment, ?int $expectedGeneration = null): void;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function lock(string $id, Closure $callback): mixed;
}
