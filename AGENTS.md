# AGENTS.md — particle-academy/prism-browser

Guarded open-web automation for Prism agents. Read the shared ecosystem guide
in `prism-parity/docs/AGENTS.md` first.

## The guard is the product

The engine is replaceable plumbing. The package must refuse before reaching an
engine when navigation, observation, or action falls outside declared policy.
Never add arbitrary JavaScript evaluation, model-supplied selectors, ambient
credentials, unbounded page output, implicit redirects, or silent fallback.

Human+ participation is not a browser mode and belongs in `prism-human-plus`.

## Refusals are codes, and one of them just changed — BREAKING

`BrowserRefused` carries a stable code read with `code()`. **Never branch on the
message**, here or in a consumer; prism-parity
[decision 0004](https://github.com/Particle-Academy/prism-parity/blob/main/docs/decisions/0004-error-codes.md)
is why, and the sentences are deliberately worded differently in each of the
three languages.

Two breaking moves closed gap G-21, and neither has a deprecation shim:

- `$refused->reason` → **`$refused->code()`**. A method, not a property:
  `Exception::$code` is an untyped int and a subclass may not retype it, which
  is the same reason `Prism\Harness\Contracts\HasErrorCode` is a method.
- `private_network_refused` → **`private_address_refused`**, matching both
  ports. The check is per-address; an allow-list entry naming one does not
  widen it.

An alias was rejected on purpose: it would have kept `$e->reason` readable while
its value moved, turning a comparison against the old code from true to silently
false on the one branch where a wrong answer misreads an SSRF refusal as an
unknown failure. Removing the property makes that code raise instead.

**There are three places that can emit a policy code, not one.** `BrowserPolicy`
is the in-process gate; `sidecar/security.mjs` checks the literal AND re-checks
after DNS; and `PlaywrightSidecarEngine` passes whatever the sidecar named to
the caller unchanged. Change a code in one and the package disagrees with
itself, on the path that actually resists rebinding. `tests/Unit/UrlPolicyCorpusTest.php`
asserts the retired name appears in neither the corpus nor the sidecar.

## Gates

```sh
composer test && composer types && composer format
```
