<?php

declare(strict_types=1);

namespace Prism\Browser\Tools;

use Prism\Browser\BrowserManager;
use Prism\Browser\Data\BrowserAction;
use Prism\Browser\Enums\ActionKind;
use Prism\Browser\Security\ObservationGuard;
use Prism\Prism\Tool;

final readonly class BrowserToolset
{
    public function __construct(private BrowserManager $browser, private ObservationGuard $guard) {}

    /** @return list<Tool> */
    public function forAttachment(string $attachmentId): array
    {
        $navigate = (new Tool)->as('browser_navigate')
            ->for('Navigate the guarded browser attachment to an absolute URL allowed by local policy.')
            ->withStringParameter('url', 'Absolute URL to navigate to.')
            ->using(fn (string $url): string => $this->guard->guard($this->browser->navigate($attachmentId, $url)));

        $observe = (new Tool)->as('browser_observe')
            ->for('Read a bounded structured observation of the current rendered page.')
            ->using(fn (): string => $this->guard->guard($this->browser->observe($attachmentId)));

        $act = (new Tool)->as('browser_act')
            ->for('Act on a package-issued element reference from the current browser observation. CSS, XPath, and JavaScript are not accepted.')
            ->withEnumParameter('kind', 'The typed browser action.', array_column(ActionKind::cases(), 'value'))
            ->withStringParameter('observation_id', 'The current observation id.')
            ->withStringParameter('ref', 'A package-issued element reference from that observation.')
            ->withStringParameter('value', 'Value for fill, select, press, or scroll.', required: false)
            ->requiresApproval(fn (array $arguments): bool => in_array($arguments['kind'] ?? null, ['click', 'fill', 'select', 'press'], true))
            ->using(function (string $kind, string $observation_id, string $ref, ?string $value = null) use ($attachmentId): string {
                $action = new BrowserAction(ActionKind::from($kind), $observation_id, $ref, $value);

                return $this->guard->guard($this->browser->act($attachmentId, $action));
            });

        $status = (new Tool)->as('browser_status')
            ->for('Report the browser attachment state and generation without exposing its checkpoint.')
            ->using(function () use ($attachmentId): string {
                $attachment = $this->browser->status($attachmentId);

                return json_encode(['mode' => 'browser', 'attachment' => $attachment->id, 'state' => $attachment->state->value, 'generation' => $attachment->generation, 'observation_id' => $attachment->currentObservationId], JSON_THROW_ON_ERROR);
            });

        $close = (new Tool)->as('browser_close')
            ->for('Checkpoint and close the browser runtime for this attachment.')
            ->requiresApproval()
            ->using(function () use ($attachmentId): string {
                $attachment = $this->browser->close($attachmentId);

                return json_encode(['mode' => 'browser', 'attachment' => $attachment->id, 'state' => $attachment->state->value, 'generation' => $attachment->generation], JSON_THROW_ON_ERROR);
            });

        return [$navigate, $observe, $act, $status, $close];
    }
}
