# Package observations and documentation changes

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
