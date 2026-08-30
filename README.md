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

## Security limits

PHP URL validation is the first gate, not the network wall. A production engine
must independently enforce redirect, DNS, subresource, worker, WebSocket, and
download policy in an isolated process/network namespace. Credentials and raw
checkpoints never belong in model arguments or tool results.
