<?php

declare(strict_types=1);

use Prism\Browser\Exceptions\BrowserRefused;
use Prism\Browser\Security\BrowserPolicy;

/**
 * The cross-language URL-policy corpus from `prism-parity`.
 *
 * This package is the REFERENCE, so this file's job is narrower than the ports'
 * equivalents: it is not proving the policy is right, it is proving the corpus
 * has not drifted from the code it was generated against — so that when a port
 * asserts "I match the reference", the thing it matched is still what the
 * reference produces.
 *
 * G-21 IS CLOSED AND ALL TWELVE ROWS NOW AGREE. The reference used to name
 * three of them `private_network_refused` where both ports said
 * `private_address_refused`; the reference adopted the ports' name, because the
 * check is per-ADDRESS rather than per-network. Agreement is not safety, so the
 * refusals themselves are asserted separately below — a corpus compares
 * languages, and three languages agreeing on an ALLOW would look exactly like
 * this one does now.
 */
function urlPolicyCorpus(): array
{
    return json_decode(
        (string) file_get_contents(__DIR__.'/../fixtures/browser-url-policy.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    )['cases'];
}

function refusalFor(array $case): ?string
{
    $policy = new BrowserPolicy(
        allowedHosts: $case['policy']['allowed_hosts'],
        requireHttps: $case['policy']['require_https'] ?? true,
        allowedPorts: $case['policy']['allowed_ports'] ?? [443],
    );

    try {
        $policy->assertUrl($case['url']);

        return null;
    } catch (BrowserRefused $refused) {
        return $refused->code();
    }
}

it('is the whole suite, not a subset someone trimmed to green', function (): void {
    expect(urlPolicyCorpus())->toHaveCount(12);
});

it('still produces the refusal the corpus recorded for it', function (): void {
    foreach (urlPolicyCorpus() as $case) {
        expect(refusalFor($case))->toBe($case['refusal']['php'], $case['id'].' — '.$case['title']);
    }
});

it('names every refusal exactly as both ports do', function (): void {
    foreach (urlPolicyCorpus() as $case) {
        $produced = refusalFor($case);

        expect($produced)->toBe($case['refusal']['ts'], $case['id'])
            ->and($produced)->toBe($case['refusal']['py'], $case['id']);
    }
});

it('REFUSES every private address, which is the claim the naming argument sits on top of', function (): void {
    // Asserted separately from the code comparison on purpose, and it is the
    // reason G-21 was a rename rather than a hole. The comparison above only
    // checks that this language produces the string the corpus recorded; if a
    // change here turned one of these into an ALLOW, the corpus would be
    // regenerated to record the allow and every comparison would stay green.
    // This is the row that would go red.
    $private = array_values(array_filter(
        urlPolicyCorpus(),
        fn (array $case): bool => $case['refusal']['php'] === 'private_address_refused',
    ));

    expect(array_map(fn (array $case): string => $case['id'], $private))
        ->toBe(['url-0005', 'url-0006', 'url-0007']);

    foreach ($private as $case) {
        expect(refusalFor($case))->toBe('private_address_refused', $case['id']);
    }
});

it('no longer answers to the retired name anywhere in the corpus', function (): void {
    // G-21's regression guard. The rename is only worth anything while nothing
    // re-introduces the old spelling on one of the three paths that can emit it
    // — this policy, the sidecar's literal check, and the sidecar's post-DNS
    // check, whose code reaches a caller through `PlaywrightSidecarEngine`
    // verbatim.
    foreach (urlPolicyCorpus() as $case) {
        foreach (['php', 'ts', 'py'] as $language) {
            expect($case['refusal'][$language])->not->toBe('private_network_refused', $case['id']);
        }
    }

    $sidecar = (string) file_get_contents(__DIR__.'/../../sidecar/security.mjs');

    expect($sidecar)->not->toContain('private_network_refused')
        ->and($sidecar)->toContain('private_address_refused');
});
