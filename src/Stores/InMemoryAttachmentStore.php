<?php

declare(strict_types=1);

namespace Prism\Browser\Stores;

use Closure;
use Prism\Browser\Contracts\AttachmentStore;
use Prism\Browser\Data\BrowserAttachment;
use Prism\Browser\Exceptions\BrowserRefused;

final class InMemoryAttachmentStore implements AttachmentStore
{
    /** @var array<string, BrowserAttachment> */
    private array $attachments = [];

    public function get(string $id): ?BrowserAttachment
    {
        return $this->attachments[$id] ?? null;
    }

    public function put(BrowserAttachment $attachment, ?int $expectedGeneration = null): void
    {
        $current = $this->get($attachment->id);
        if ($expectedGeneration !== null && $current?->generation !== $expectedGeneration) {
            throw new BrowserRefused('stale_generation', 'Browser attachment changed while this worker was acting.');
        }
        $this->attachments[$attachment->id] = $attachment;
    }

    public function lock(string $id, Closure $callback): mixed
    {
        return $callback();
    }
}
