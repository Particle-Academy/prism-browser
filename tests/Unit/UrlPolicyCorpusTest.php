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
 * It also asserts the divergence FROM THIS SIDE. If a change here quietly
 * renamed `private_network_refused` to match the ports, the finding would
 * silently close and G-21 would be left describing a state of the world that no
 * longer existed.
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
        return $refused->reason;
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

it('still names three refusals differently from the ports', function (): void {
    foreach (urlPolicyCorpus() as $case) {
        $produced = refusalFor($case);

        $case['agrees']
            ? expect($produced)->toBe($case['refusal']['ts'], $case['id'])
            : expect($produced)->not->toBe($case['refusal']['ts'], $case['id']);
    }
});

it('REFUSES every private address, which is the claim the naming argument sits on top of', function (): void {
    // Asserted separately from the code comparison on purpose. The divergence
    // test above only compares strings; if a change here turned one of these
    // into an allow, it would still pass. This is the row that would go red.
    foreach (urlPolicyCorpus() as $case) {
        if ($case['agrees']) {
            continue;
        }

        expect(refusalFor($case))->not->toBeNull($case['id']);
    }
});

it('names the divergent rows, so the count cannot drift silently', function (): void {
    $diverging = array_values(array_map(
        fn (array $case): string => $case['id'],
        array_filter(urlPolicyCorpus(), fn (array $case): bool => $case['agrees'] === false),
    ));

    expect($diverging)->toBe(['url-0005', 'url-0006', 'url-0007']);
});
