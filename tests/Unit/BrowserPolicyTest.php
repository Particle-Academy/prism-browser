<?php

declare(strict_types=1);

use Prism\Browser\Enums\ActionKind;
use Prism\Browser\Exceptions\BrowserRefused;
use Prism\Browser\Security\BrowserPolicy;

it('allows only declared public https hosts', function (): void {
    $policy = new BrowserPolicy(['example.com', '*.example.org']);

    $policy->assertUrl('https://example.com/path');
    $policy->assertUrl('https://docs.example.org/path');

    expect(fn () => $policy->assertUrl('http://example.com'))->toThrow(BrowserRefused::class, 'requires HTTPS')
        ->and(fn () => $policy->assertUrl('https://127.0.0.1'))->toThrow(BrowserRefused::class, 'private network')
        ->and(fn () => $policy->assertUrl('https://169.254.169.254/latest/meta-data'))->toThrow(BrowserRefused::class, 'private network')
        ->and(fn () => $policy->assertUrl('https://user:password@example.com'))->toThrow(BrowserRefused::class, 'may not contain credentials')
        ->and(fn () => $policy->assertUrl('https://example.com:8443'))->toThrow(BrowserRefused::class, 'does not allow port')
        ->and(fn () => $policy->assertUrl('https://evil.test'))->toThrow(BrowserRefused::class, 'does not allow');
});

it('refuses actions outside policy', function (): void {
    $policy = new BrowserPolicy(['example.com'], [ActionKind::Click]);

    expect(fn () => $policy->assertAction(ActionKind::Fill))->toThrow(BrowserRefused::class, 'does not allow');
});
