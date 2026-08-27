<?php

declare(strict_types=1);

namespace Prism\Browser\Stores;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Encryption\Encrypter;
use Prism\Browser\Contracts\AttachmentStore;
use Prism\Browser\Data\BrowserAttachment;
use Prism\Browser\Enums\AttachmentState;
use Prism\Browser\Exceptions\BrowserRefused;

final readonly class LaravelAttachmentStore implements AttachmentStore
{
    public function __construct(
        private CacheFactory $cache,
        private Encrypter $encrypter,
        private ?string $store = null,
        private string $prefix = 'prism-browser:',
        private int $ttlSeconds = 86400,
    ) {}

    public function get(string $id): ?BrowserAttachment
    {
        $encrypted = $this->repository()->get($this->prefix.$id);
        if (! is_string($encrypted)) {
            return null;
        }
        $json = $this->encrypter->decrypt($encrypted, unserialize: false);
        if (! is_string($json)) {
            throw new BrowserRefused('corrupt_attachment', 'Stored browser attachment is malformed.');
        }
        $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($value)) {
            throw new BrowserRefused('corrupt_attachment', 'Stored browser attachment is malformed.');
        }

        return new BrowserAttachment(
            (string) $value['id'], (string) $value['owner'], (string) $value['profile'],
            (int) $value['generation'], AttachmentState::from((string) $value['state']),
            is_string($value['observation'] ?? null) ? $value['observation'] : null,
            is_string($value['checkpoint'] ?? null) ? $value['checkpoint'] : null,
        );
    }

    public function put(BrowserAttachment $attachment, ?int $expectedGeneration = null): void
    {
        $current = $this->get($attachment->id);
        if ($expectedGeneration !== null && $current?->generation !== $expectedGeneration) {
            throw new BrowserRefused('stale_generation', 'Browser attachment changed while this worker was acting.');
        }
        $value = ['id' => $attachment->id, 'owner' => $attachment->owner, 'profile' => $attachment->profile, 'generation' => $attachment->generation, 'state' => $attachment->state->value, 'observation' => $attachment->currentObservationId, 'checkpoint' => $attachment->checkpoint];
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->repository()->put($this->prefix.$attachment->id, $this->encrypter->encrypt($json, serialize: false), $this->ttlSeconds);
    }

    public function lock(string $id, Closure $callback): mixed
    {
        $repository = $this->repository();
        if (! method_exists($repository, 'lock')) {
            throw new BrowserRefused('locking_unavailable', 'Configured browser attachment cache does not support atomic locks.');
        }

        return $repository->lock($this->prefix.'lock:'.$id, 30)->block(5, $callback);
    }

    private function repository(): Repository
    {
        return $this->cache->store($this->store);
    }
}
