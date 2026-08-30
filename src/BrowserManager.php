<?php

declare(strict_types=1);

namespace Prism\Browser;

use Prism\Browser\Contracts\AttachmentStore;
use Prism\Browser\Contracts\BrowserEngine;
use Prism\Browser\Data\BrowserAction;
use Prism\Browser\Data\BrowserAttachment;
use Prism\Browser\Data\Observation;
use Prism\Browser\Enums\AttachmentState;
use Prism\Browser\Exceptions\BrowserRefused;
use Prism\Browser\Security\BrowserPolicy;
use Prism\Browser\Support\OwnerAddress;

final readonly class BrowserManager
{
    public function __construct(
        private BrowserEngine $engine,
        private AttachmentStore $store,
        private BrowserPolicy $policy,
    ) {}

    public function open(string|object $owner, string $profile = 'default'): BrowserAttachment
    {
        $id = 'browser_'.bin2hex(random_bytes(12));
        $attachment = new BrowserAttachment($id, OwnerAddress::from($owner), $profile, 0, AttachmentState::Open);
        $this->engine->open($id);
        $this->store->put($attachment);

        return $attachment;
    }

    public function navigate(string|object $owner, string $id, string $url): Observation
    {
        $this->policy->assertUrl($url);

        return $this->store->lock($id, function () use ($owner, $id, $url): Observation {
            $attachment = $this->required($owner, $id);
            $observation = $this->engine->navigate($id, $url);
            $this->store->put($attachment->withObservation($observation->id, $this->engine->checkpoint($id)), $attachment->generation);

            return $observation;
        });
    }

    public function observe(string|object $owner, string $id): Observation
    {
        return $this->store->lock($id, function () use ($owner, $id): Observation {
            $attachment = $this->required($owner, $id);
            $observation = $this->engine->observe($id);
            $this->store->put($attachment->withObservation($observation->id, $this->engine->checkpoint($id)), $attachment->generation);

            return $observation;
        });
    }

    public function act(string|object $owner, string $id, BrowserAction $action): Observation
    {
        $this->policy->assertAction($action->kind);

        return $this->store->lock($id, function () use ($owner, $id, $action): Observation {
            $attachment = $this->required($owner, $id);
            if ($attachment->currentObservationId !== $action->observationId) {
                throw new BrowserRefused('stale_observation', 'Browser action references an observation that is no longer current.');
            }

            try {
                $observation = $this->engine->act($id, $action);
            } catch (\Throwable $failure) {
                $this->store->put($attachment->inDoubt(), $attachment->generation);
                throw $failure;
            }

            $this->store->put($attachment->withObservation($observation->id, $this->engine->checkpoint($id)), $attachment->generation);

            return $observation;
        });
    }

    public function status(string|object $owner, string $id): BrowserAttachment
    {
        return $this->required($owner, $id);
    }

    public function close(string|object $owner, string $id): BrowserAttachment
    {
        return $this->store->lock($id, function () use ($owner, $id): BrowserAttachment {
            $attachment = $this->required($owner, $id);
            $checkpoint = $this->engine->checkpoint($id);
            $this->engine->close($id);
            $closed = $attachment->closed($checkpoint);
            $this->store->put($closed, $attachment->generation);

            return $closed;
        });
    }

    private function required(string|object $owner, string $id): BrowserAttachment
    {
        $attachment = $this->store->get($id);
        if ($attachment === null) {
            throw new BrowserRefused('attachment_not_found', 'Browser attachment does not exist.');
        }
        if (! hash_equals($attachment->owner, OwnerAddress::from($owner))) {
            throw new BrowserRefused('attachment_owner_mismatch', 'Browser attachment does not belong to this owner.');
        }
        if ($attachment->state !== AttachmentState::Open) {
            throw new BrowserRefused('attachment_not_open', sprintf('Browser attachment is [%s].', $attachment->state->value));
        }

        return $attachment;
    }
}
