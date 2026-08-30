<?php

declare(strict_types=1);

use Prism\Browser\BrowserManager;
use Prism\Browser\Contracts\BrowserEngine;
use Prism\Browser\Data\BrowserAction;
use Prism\Browser\Data\Observation;
use Prism\Browser\Enums\ActionKind;
use Prism\Browser\Exceptions\BrowserRefused;
use Prism\Browser\Security\BrowserPolicy;
use Prism\Browser\Security\ObservationGuard;
use Prism\Browser\Stores\InMemoryAttachmentStore;
use Prism\Browser\Tools\BrowserToolset;

function fakeBrowserEngine(): BrowserEngine
{
    return new class implements BrowserEngine
    {
        public int $sequence = 0;

        public function open(string $attachmentId, ?string $checkpoint = null): void {}

        public function navigate(string $attachmentId, string $url): Observation
        {
            return $this->observation($url);
        }

        public function observe(string $attachmentId): Observation
        {
            return $this->observation('https://example.com');
        }

        public function act(string $attachmentId, BrowserAction $action): Observation
        {
            return $this->observation('https://example.com/changed');
        }

        public function checkpoint(string $attachmentId): ?string
        {
            return '{"cookies":[]}';
        }

        public function close(string $attachmentId): void {}

        private function observation(string $url): Observation
        {
            $id = 'obs_'.(++$this->sequence);

            return new Observation($id, $url, 'https://example.com', 'Example', [['ref' => 'e1', 'role' => 'button', 'name' => 'Save']], ['Example']);
        }
    };
}

it('binds actions to the current observation', function (): void {
    $manager = new BrowserManager(fakeBrowserEngine(), new InMemoryAttachmentStore, new BrowserPolicy(['example.com']));
    $attachment = $manager->open('session:one');
    $observation = $manager->navigate('session:one', $attachment->id, 'https://example.com');

    $next = $manager->act('session:one', $attachment->id, new BrowserAction(ActionKind::Click, $observation->id, 'e1'));

    expect($next->id)->toBe('obs_2')
        ->and(fn () => $manager->act('session:one', $attachment->id, new BrowserAction(ActionKind::Click, $observation->id, 'e1')))
        ->toThrow(BrowserRefused::class, 'no longer current');
});

it('refuses navigation before the engine sees it', function (): void {
    $engine = fakeBrowserEngine();
    $manager = new BrowserManager($engine, new InMemoryAttachmentStore, new BrowserPolicy(['example.com']));
    $attachment = $manager->open('session:one');

    expect(fn () => $manager->navigate('session:one', $attachment->id, 'https://localhost/secrets'))->toThrow(BrowserRefused::class)
        ->and($engine->sequence)->toBe(0);
});

it('exposes a bounded typed Prism tool surface', function (): void {
    $manager = new BrowserManager(fakeBrowserEngine(), new InMemoryAttachmentStore, new BrowserPolicy(['example.com']));
    $attachment = $manager->open('session:one');
    $tools = (new BrowserToolset($manager, new ObservationGuard(4096)))->forAttachment('session:one', $attachment->id);

    expect(array_map(fn ($tool) => $tool->name(), $tools))->toBe(['browser_navigate', 'browser_observe', 'browser_act', 'browser_status', 'browser_close'])
        ->and($tools[2]->needsApproval(['kind' => 'click']))->toBeTrue()
        ->and($tools[2]->needsApproval(['kind' => 'hover']))->toBeFalse();
});

it('refuses every operation when the attachment owner does not match', function (): void {
    $manager = new BrowserManager(fakeBrowserEngine(), new InMemoryAttachmentStore, new BrowserPolicy(['example.com']));
    $attachment = $manager->open('session:one');

    expect(fn () => $manager->navigate('session:two', $attachment->id, 'https://example.com'))
        ->toThrow(BrowserRefused::class, 'does not belong')
        ->and(fn () => $manager->observe('session:two', $attachment->id))->toThrow(BrowserRefused::class, 'does not belong')
        ->and(fn () => $manager->status('session:two', $attachment->id))->toThrow(BrowserRefused::class, 'does not belong')
        ->and(fn () => $manager->close('session:two', $attachment->id))->toThrow(BrowserRefused::class, 'does not belong');
});
