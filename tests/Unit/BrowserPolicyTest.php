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

/*
| G-89. Every spelling below was ADMITTED: filter_var's private and reserved
| flags do not cover CGNAT (Alibaba Cloud's metadata endpoint lives there), and
| a NAT64 or 6to4 address is 169.254.169.254 written in IPv6. The policy's
| promise is a refusal EVEN WHEN ALLOW-LISTED, so each one is allow-listed here.
*/
dataset('non-public literals', [
    'alibaba cloud metadata (CGNAT)' => ['100.100.100.200'],
    'CGNAT' => ['100.64.0.1'],
    'IETF protocol assignments' => ['192.0.0.170'],
    'TEST-NET-1' => ['192.0.2.1'],
    'benchmarking' => ['198.18.0.1'],
    'TEST-NET-2' => ['198.51.100.1'],
    'TEST-NET-3' => ['203.0.113.1'],
    'multicast' => ['224.0.0.1'],
    'NAT64 of the metadata endpoint' => ['[64:ff9b::a9fe:a9fe]'],
    'NAT64 of the metadata endpoint, dotted' => ['[64:ff9b::169.254.169.254]'],
    'NAT64 of loopback' => ['[64:ff9b::7f00:1]'],
    'local-use NAT64' => ['[64:ff9b:1::a9fe:a9fe]'],
    '6to4 of the metadata endpoint' => ['[2002:a9fe:a9fe::]'],
    '6to4 of a private address' => ['[2002:a00:5::1]'],
    'Teredo' => ['[2001:0:4136:e378:8000:63bf:3fff:fdd2]'],
    'IPv6 documentation' => ['[2001:db8::1]'],
    'IPv6 discard' => ['[100::1]'],
    'deprecated site-local' => ['[fec0::1]'],
    'IPv6 multicast' => ['[ff02::1]'],
    'IPv4-mapped loopback, compressed' => ['[::ffff:7f00:1]'],
    'IPv4-compatible loopback, compressed' => ['[::7f00:1]'],
]);

it('refuses a non-public address even when it is allow-listed', function (string $host): void {
    $policy = new BrowserPolicy([strtolower($host)]);

    expect(refusalCodeFor(fn () => $policy->assertUrl("https://{$host}/latest/meta-data")))->toBe('private_address_refused');
})->with('non-public literals');

// The controls: an allow-listed public address, including through NAT64 and 6to4, still navigates.
it('allows an allow-listed public address, including through NAT64 and 6to4', function (string $host): void {
    $policy = new BrowserPolicy([strtolower($host)]);

    expect(refusalCodeFor(fn () => $policy->assertUrl("https://{$host}/")))->toBe('no_refusal');
})->with(['8.8.8.8', '[64:ff9b::808:808]', '[2002:808:808::1]', '[2606:4700:4700::1111]']);

// A browser reads shorthand, integer, octal and hex IPv4 the way libc's
// inet_aton does: all four of these navigate to 127.0.0.1. parse_url leaves them
// as names, so they reached the allow-list instead of the address check.
it('refuses shorthand IPv4 that a browser resolves to loopback, even when allow-listed', function (string $host): void {
    $policy = new BrowserPolicy([$host]);

    expect(refusalCodeFor(fn () => $policy->assertUrl("https://{$host}/")))->toBe('private_address_refused');
})->with(['127.1', '0x7f000001', '2130706433', '0177.0.0.1', '0x7f.1', '169.254.43518', '0xa9fea9fe']);

it('still treats names that only look numeric-ish as names', function (string $host): void {
    $policy = new BrowserPolicy([$host]);

    expect(refusalCodeFor(fn () => $policy->assertUrl("https://{$host}/")))->toBe('no_refusal');
})->with(['8.8.8.8', '1e100.net', '123.example', '0x.example']);
