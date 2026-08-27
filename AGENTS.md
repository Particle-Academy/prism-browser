# AGENTS.md — particle-academy/prism-browser

Guarded open-web automation for Prism agents. Read the shared ecosystem guide
in `prism-parity/docs/AGENTS.md` first.

## The guard is the product

The engine is replaceable plumbing. The package must refuse before reaching an
engine when navigation, observation, or action falls outside declared policy.
Never add arbitrary JavaScript evaluation, model-supplied selectors, ambient
credentials, unbounded page output, implicit redirects, or silent fallback.

Human+ participation is not a browser mode and belongs in `prism-human-plus`.

## Gates

```sh
composer test && composer types && composer format
```
