# Prism Browser

Guarded open-web automation for Prism agents on PHP 8.2+ and Laravel 12/13.

This package is a boundary, not a Playwright wrapper. Applications declare the
hosts and actions an agent may use before an engine receives anything. Page
observations are bounded and provenance-framed because every page is untrusted
model input. Actions bind to a short-lived observation id and package-issued
element reference; CSS, XPath, and arbitrary JavaScript are not accepted.

## Status

The first vertical slice ships the provider-neutral engine contract, explicit
URL/action policy, bounded structured observations, attachment generations,
compare-and-swap storage semantics, stale-observation refusal, and `in_doubt`
state on an ambiguous action failure. It intentionally uses no default engine:
an application must bind `BrowserEngine`. A version-pinned Playwright sidecar is
the intended first production adapter.

The bundled sidecar binds only to loopback and requires both a 32+ character
bearer token and `PRISM_BROWSER_EGRESS_PROXY`. That proxy is the network-level
public/private-address enforcement boundary. For explicit local dogfooding only,
`PRISM_BROWSER_ALLOW_UNVERIFIED_EGRESS=1` bypasses the proxy requirement; the
sidecar still checks DNS, redirects, subresources, ports, and URL credentials,
but correctly does not claim that application-layer DNS checks eliminate
rebinding races.

Human+ is not a browser mode. Joining a Fancy surface belongs to
`particle-academy/prism-human-plus` and may coexist with this package.

## Minimal use

```php
use Prism\Browser\BrowserManager;
use Prism\Browser\Security\BrowserPolicy;

$policy = new BrowserPolicy(['docs.example.com'], requireHttps: true);
$browser = new BrowserManager($engine, $store, $policy);
$attachment = $browser->open($harnessSession->key());
$observation = $browser->navigate($harnessSession->key(), $attachment->id, 'https://docs.example.com');
```

Do not expose an engine directly as a model tool. The safe model surface is a
typed adapter over `open`, `navigate`, structured `observe`, stable-ref `act`,
`status`, and `close`.

Every operation after `open` requires the owner again. Attachment ids are
capability locators, not bearer credentials: knowing another session's id does
not authorize using its browser state.

## Refusals carry a code — BREAKING CHANGE

Every refusal is a `BrowserRefused` carrying a stable, machine-readable code.
**Branch on `code()`. Never on `getMessage()`** — the sentence is outside the
contract and is free to be reworded, in this package and in the TypeScript and
Python ports, which say the same things differently on purpose.

```php
try {
    $browser->navigate($owner, $attachment->id, $url);
} catch (BrowserRefused $refused) {
    match ($refused->code()) {
        'private_address_refused' => $this->hardStop($refused),   // never retry
        'host_not_allowed'        => $this->askToWiden($refused), // a policy question
        default                  => $this->report($refused),
    };
}
```

**Two things changed here, and both break code written against the previous
release.** There is no deprecation shim; see below for why.

| was | is |
|---|---|
| `$refused->reason` (readonly property) | `$refused->code()` (method) |
| `private_network_refused` | `private_address_refused` |

The code moved because the two ports have always called it
`private_address_refused` and they are right: the test is per-ADDRESS, and an
allow-list entry naming one does not widen it. Every language refused the same
URLs the whole time — nothing was unprotected — but a consumer switching on this
package's spelling fell through to its default branch against a port, which for
most consumers reads as "unknown failure" rather than "this was an SSRF
refusal". That is gap G-21 in the ecosystem's port-gaps register, and this is
what closed it.

The accessor moved with it because `.code` is what every other error surface in
this ecosystem carries and `.reason` was the outlier. It is a METHOD rather than
a property because PHP will not have it otherwise: `Exception::$code` is an
untyped int, and a subclass declaring `public readonly string $code` is a fatal
error at load time. The three languages therefore spell the accessor
differently — a field, an attribute, a method — and none of that is observable.
The code string is, and it is now identical in all three.

**No `$reason` alias, deliberately.** An alias would keep the shape while the
value moved underneath it, so `$e->reason === 'private_network_refused'` would
go from true to silently false — on the one branch in this package where a wrong
answer means an SSRF refusal is not recognised as one. Removing the property
makes that same code raise instead, at the exact line that has to change. The
package is pre-1.0 and has never been published, so the loud break costs nobody
a migration it would not have had to make anyway.

Raised in process: `invalid_url`, `url_credentials_refused`, `https_required`,
`private_address_refused`, `host_not_allowed`, `port_not_allowed`,
`action_not_allowed`, `observation_too_large`, `stale_observation`,
`stale_generation`, `attachment_not_found`, `attachment_owner_mismatch`,
`attachment_not_open`, `corrupt_attachment`, `locking_unavailable`,
`malformed_sidecar_response`, `sidecar_failed`.

**A sidecar's code reaches the caller verbatim.** `PlaywrightSidecarEngine`
puts whatever the sidecar named into the `BrowserRefused` it raises, so the
bundled sidecar's own vocabulary is part of this surface too: it mirrors the
policy codes — `private_address_refused` included, and its post-DNS check is the
one that resists rebinding rather than merely bad literals — and adds
`attachment_exists`, `unknown_ref`, `operation_not_found`, `unauthorized`,
`not_found`, `payload_too_large` and `engine_failed`. A third-party engine
chooses its own; `sidecar_failed` is what you get when it named nothing.

## Security limits

PHP URL validation is the first gate, not the network wall. A production engine
must independently enforce redirect, DNS, subresource, worker, WebSocket, and
download policy in an isolated process/network namespace. Credentials and raw
checkpoints never belong in model arguments or tool results.
