<?php

declare(strict_types=1);

namespace Prism\Browser\Data;

use Prism\Browser\Enums\AttachmentState;

final readonly class BrowserAttachment
{
    public function __construct(
        public string $id,
        public string $owner,
        public string $profile,
        public int $generation,
        public AttachmentState $state,
        public ?string $currentObservationId = null,
        public ?string $checkpoint = null,
    ) {}

    public function withObservation(string $id, ?string $checkpoint = null): self
    {
        return new self($this->id, $this->owner, $this->profile, $this->generation + 1, AttachmentState::Open, $id, $checkpoint);
    }

    public function closed(?string $checkpoint): self
    {
        return new self($this->id, $this->owner, $this->profile, $this->generation + 1, AttachmentState::Closed, $this->currentObservationId, $checkpoint);
    }

    public function inDoubt(): self
    {
        return new self($this->id, $this->owner, $this->profile, $this->generation + 1, AttachmentState::InDoubt, $this->currentObservationId, $this->checkpoint);
    }
}
