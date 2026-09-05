<?php

declare(strict_types=1);

use Prism\Browser\Enums\ActionKind;
use Prism\Browser\Exceptions\BrowserRefused;
use Prism\Browser\Security\BrowserPolicy;

/**
 * The code, not the sentence.
 *
 * These assertions used to match on message text — `'private network'`,
 * `'does not allow'` — which is the thing decision 0004 exists to stop: a
 * wording improvement turns the build red without changing behaviour, and
 * `'does not allow'` matched three different refusals, so the host case and the
 * port case were interchangeable as far as the test could tell.
 */
function refusalCodeFor(callable $call): string
{
    try {
        $call();
    } catch (BrowserRefused $refused) {
        return $refused->code();
    }

    return 'no_refusal';
}

it('allows only declared public https hosts', function (): void {
    $policy = new BrowserPolicy(['example.com', '*.example.org']);

    $policy->assertUrl('https://example.com/path');
    $policy->assertUrl('https://docs.example.org/path');

    expect(refusalCodeFor(fn () => $policy->assertUrl('http://example.com')))->toBe('https_required')
        ->and(refusalCodeFor(fn () => $policy->assertUrl('https://127.0.0.1')))->toBe('private_address_refused')
        ->and(refusalCodeFor(fn () => $policy->assertUrl('https://169.254.169.254/latest/meta-data')))->toBe('private_address_refused')
        ->and(refusalCodeFor(fn () => $policy->assertUrl('https://user:password@example.com')))->toBe('url_credentials_refused')
        ->and(refusalCodeFor(fn () => $policy->assertUrl('https://example.com:8443')))->toBe('port_not_allowed')
        ->and(refusalCodeFor(fn () => $policy->assertUrl('https://evil.test')))->toBe('host_not_allowed');
});

it('refuses actions outside policy', function (): void {
    $policy = new BrowserPolicy(['example.com'], [ActionKind::Click]);

    expect(refusalCodeFor(fn () => $policy->assertAction(ActionKind::Fill)))->toBe('action_not_allowed');
});
