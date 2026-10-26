# Current release: 2.0.0

- Required signed hardware binding, floating checkout/heartbeat/checkin and online idempotent usage credits.
- Updated installation, source links and cross-language capability documentation.
- Customer portal remains hosted; browser hardware fallback is unsupported.

# Package observations and documentation changes

## 2.0.0 — 2026-10-26

- Require product-salted hardware hashes and verify signed device/installation binding.
- Add floating checkout, heartbeat and checkin with signed lease expiry and seat exhaustion.
- Add online-only credit balance/consume, idempotent receipts and insufficient-credit errors.
- Breaking pre-launch server contract; older published clients are not compatible.
- Registry availability is recorded separately; a changelog is not proof of publication. Ed25519 is unchanged.

- PHP long-running jobs must call tick(); no background thread.

## Unreleased — Phase B/C/D parity

- Required native hardware hashing and signed strict/fuzzy binding; fail closed,
  no browser fallback or raw-serial persistence.
- Floating checkout/heartbeat/checkin/close, signed lease gating and typed full-seat
  error. PHP renewal is cooperative through check/tick, not a background thread.
- Online-only consume/getBalance, readonly signed receipts, idempotency replay and
  typed insufficient-credit errors. No server, signature or dependency changes.

## 1.0.1 — 2026-10-21

- Guard features with validity, signature and signed deadlines; invalid results ignore enabling defaults. Positive numeric flags match Python semantics.
- Verify additive `payload_json` exact signed bytes and use their decoded fields as authoritative. Existing envelope fallback retained.
- Clear stale cached access on malformed/rejected validation; require confirmed deactivation. Correct Composer minimum to PHP 8.2, matching readonly syntax.
- No additional runtime dependencies; tested against PHP-generated signed numeric, Unicode and cached responses.

## 1.0.0 — observed current source, 2026-10-17

This is the version recorded in current package/project metadata (PHP uses the
existing versioned download label). It is not a reconstructed original release
date, a published registry release or an invented earlier-version history.

- Added complete source-install, lifecycle, storage/offline and known-limit
  documentation, license-information notice and runnable example links.
- Preserved runtime implementation, public APIs, response handling and package
  identity. Documentation changes do not silently fix known signing/cache gaps.
- PHP includes a new package-local standalone test entry; its legacy repository
  test remains unchanged and still assumes the repository-root autoload layout.

Future entries must identify the exact version, verified changes, compatibility
impact and tests. API v1 and SDK package versions are independent.
