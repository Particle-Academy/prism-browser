<?php

declare(strict_types=1);

namespace Prism\Browser\Engine;

use Illuminate\Http\Client\Factory;
use Prism\Browser\Contracts\BrowserEngine;
use Prism\Browser\Data\BrowserAction;
use Prism\Browser\Data\Observation;
use Prism\Browser\Exceptions\BrowserRefused;
use Prism\Browser\Security\BrowserPolicy;

final readonly class PlaywrightSidecarEngine implements BrowserEngine
{
    public function __construct(
        private Factory $http,
        private BrowserPolicy $policy,
        private string $baseUrl,
        private string $token,
        private int $timeoutSeconds = 30,
    ) {}

    public function open(string $attachmentId, ?string $checkpoint = null): void
    {
        $this->request('open', ['attachment' => $attachmentId, 'checkpoint' => $checkpoint]);
    }

    public function navigate(string $attachmentId, string $url): Observation
    {
        return $this->observation($this->request('navigate', ['attachment' => $attachmentId, 'url' => $url, 'policy' => $this->policy->toArray()]));
    }

    public function observe(string $attachmentId): Observation
    {
        return $this->observation($this->request('observe', ['attachment' => $attachmentId]));
    }

    public function act(string $attachmentId, BrowserAction $action): Observation
    {
        return $this->observation($this->request('act', [
            'attachment' => $attachmentId,
            'action' => ['kind' => $action->kind->value, 'observation_id' => $action->observationId, 'ref' => $action->ref, 'value' => $action->value],
        ]));
    }

    public function checkpoint(string $attachmentId): ?string
    {
        $result = $this->request('checkpoint', ['attachment' => $attachmentId]);

        return is_string($result['checkpoint'] ?? null) ? $result['checkpoint'] : null;
    }

    public function close(string $attachmentId): void
    {
        $this->request('close', ['attachment' => $attachmentId]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function request(string $operation, array $payload): array
    {
        $response = $this->http->baseUrl(rtrim($this->baseUrl, '/'))
            ->withToken($this->token)->acceptJson()->timeout($this->timeoutSeconds)
            ->post('/v1/'.$operation, $payload);
        if (! $response->successful()) {
            $reason = $response->json('error');
            throw new BrowserRefused(is_string($reason) ? $reason : 'sidecar_failed', 'Browser sidecar refused or failed the operation.');
        }
        $value = $response->json();
        if (! is_array($value)) {
            throw new BrowserRefused('malformed_sidecar_response', 'Browser sidecar returned malformed JSON.');
        }

        return $value;
    }

    /** @param array<string, mixed> $value */
    private function observation(array $value): Observation
    {
        foreach (['observation_id', 'url', 'origin', 'title', 'elements', 'visible_text'] as $key) {
            if (! array_key_exists($key, $value)) {
                throw new BrowserRefused('malformed_sidecar_response', 'Browser sidecar omitted observation fields.');
            }
        }
        if (! is_string($value['observation_id']) || ! is_string($value['url']) || ! is_string($value['origin']) || ! is_string($value['title']) || ! is_array($value['elements']) || ! is_array($value['visible_text'])) {
            throw new BrowserRefused('malformed_sidecar_response', 'Browser sidecar returned invalid observation fields.');
        }
        /** @var list<array{ref:string, role:string, name:string, value?:mixed, disabled?:bool}> $elements */
        $elements = array_values($value['elements']);
        /** @var list<string> $text */
        $text = array_values(array_filter($value['visible_text'], is_string(...)));

        return new Observation($value['observation_id'], $value['url'], $value['origin'], $value['title'], $elements, $text, (bool) ($value['truncated'] ?? false), is_string($value['fallback'] ?? null) ? $value['fallback'] : null);
    }
}
