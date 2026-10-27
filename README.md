# KDPika Licensing PHP SDK

## Desktop / Web plan controls — 2.1.0

This source adds Business-only per-software-plan app access: `desktop`, `web`, or `both`. Starter and Pro automatically allow both under the same App ID and cannot create app-specific restrictions. It applies equally to subscriptions, one-time purchases and lifetime access. Devices/seats/credits belong to the same license; choosing both does not double its limits.

Configure the host application, not a customer-editable field:

```
new LicenseClient(product: 'YOUR_APP_ID', applicationType: 'web', storageDir: '/private/web-license')
```

Default: web. Configure the other app with the same App ID and key, its own context and private state folder. SDK storage namespaces include the context. All activation/validation/seat/credit requests send `X-Application-Type`, and the SDK checks signed `application_access` plus `application_type` on network and cached results. A scope-restricted license cannot activate without declaring a context. Errors: APPLICATION_NOT_ALLOWED, APPLICATION_TYPE_REQUIRED, APPLICATION_TYPE_MISMATCH, INVALID_APPLICATION_TYPE. Releasing a machine/seat remains possible after a restriction change.

Context is a declaration by trusted application code, not browser/hardware attestation. A user who controls executable code can modify it; valuable online operations need server-side authorization too. Offline clients learn changed policies only on reconnect and must obey the old signed deadline until then. Browser/WASM hardware fallback remains unsupported.

[Download the source ZIP](https://licensing.kdpika.com/docs/downloads/kdpika-php-sdk-2.1.0.zip) · [Desktop / Web setup and migration](https://licensing.kdpika.com/docs/v1/licensing/application-access)

## Install this release

```sh
composer require kdpika/license-sdk:^2.1
```


Official SDK **2.1.0** for signed licensing, hardware binding, floating seats, metered credits and Desktop / Web access controls.

[Documentation](https://licensing.kdpika.com/docs/v1/sdks/php) · [Download SDK ZIP](https://licensing.kdpika.com/docs/downloads/kdpika-php-sdk-2.1.0.zip) · [GitHub source](https://github.com/kdpika/kdpika-licensing-php)

## Supported licensing capabilities

| Capability | Behavior |
|---|---|
| Signed checks and offline access | Verify Ed25519 results; access never extends beyond signed expiry/offline policy. |
| Device matching | Required product-salted hardware hashes and X-Installation-ID; verify the signed device binding. Raw serials are not sent. |
| Floating licenses | Checkout, heartbeat and checkin with signed lease deadlines; handle ALL_SEATS_IN_USE. |
| Usage credits | Online-only consume with a persisted idempotency key; query balance and handle INSUFFICIENT_CREDITS. |
| Customer portal | Hosted self-service portal managed by the server, not an SDK payment or account feature. |

Advanced matching, floating seats, credits and portal availability depend on your workspace plan. Browser/WASM hardware fingerprint fallback is unsupported. Test on your target OS and in Sandbox before distributing your app.

## Install

```sh
composer require kdpika/license-sdk:^2.1
```


## Hardware matching, shared seats and usage credits

Requires 64-bit PHP 8.2+ and the matching server contract. Windows/macOS/Linux
collect product-salted hardware hashes; raw serials are not stored/transmitted.
Activate/validate send the required fingerprint and installation identity, and
signed strict/fuzzy device binding gates online/cache/offline access. Missing
hardware denies `HARDWARE_UNAVAILABLE`; VM detection is only a hint. The optional
`fingerprintProvider` constructor callback is for a trusted native adapter/tests,
never an end-user-supplied device ID. Browser/JS-in-browser fallback: unsupported.

Floating activation automatically checks out a seat. Use `checkout()`, `heartbeat()`,
`checkin()` or `close()`; explicit full checkout throws `AllSeatsInUseException`
with `errorCode=ALL_SEATS_IN_USE`. Lease expiry and the private session-token hash
are checked on cached/offline and retained feature decisions. PHP has **no background
thread**: `check()` renews when due, and long-running workers must call `tick()`
frequently or `heartbeat()` at the signed interval. A blocking job cannot renew
itself; use a host worker/timer if needed. Explicit close is recommended; destruction
attempts checkin but abrupt process termination relies on server lease expiry.

```php
try {
    $receipt = $client->consume(1, 'durable_export_job_123');
    // Run this export once using the SAME job ID; replay is not a new export.
} catch (\KDPika\LicenseSdk\InsufficientCreditsException $e) {
    showTopupMessage($e->balance);
}
$balance = $client->getBalance(); // signed, online, zero is allowed
```

The readonly receipt exposes `transactionId`, `consumed`, `balance`, `replayed`.
A replay balance is historical; getBalance fetches current balance. Persist/reuse
the job key after an uncertain outcome; no automatic consumption retry occurs.
The last successful charge may leave zero and still permits that operation.
Normal feature checks do not charge credits. Metered cached/offline access denies
`METERED_ONLINE_REQUIRED`. Operations share a process-safe local file lock; use
private local storage outside the web root, not NFS. No dependencies, package
publication, server changes or Ed25519 changes are included in this source update.

Maintained PHP client (source requires PHP 8.2+) for activation, signed validation, controlled offline grace, feature access, and deactivation.

```bash
tar -xzf kdpika-php-sdk-2.1.0.tar.gz
composer install --working-dir ./kdpika-php-sdk
```

```php
require __DIR__ . '/kdpika-php-sdk/vendor/autoload.php';
use KDPika\LicenseSdk\LicenseClient;

$client = new LicenseClient(product: 'YOUR_PUBLIC_APP_ID', environment: 'SANDBOX', applicationName: 'Your App');
$result = $client->check();
if (!$result->valid && $result->errorCode === 'NOT_ACTIVATED') {
    $result = $client->activate('YOUR-LICENSE-KEY', 'customer@example.com');
}
if (!$result->valid) throw new RuntimeException("{$result->errorCode}: {$result->message}");
```

Never place a developer API key in distributed code. PHP must provide cURL; Ed25519 verification uses Sodium or the declared `sodium_compat` fallback.

## Release limitations and trust boundary

Version 2.1.0 requires PHP8.2+ in Composer, matching readonly syntax. It verifies exact `payload_json` signed bytes and guards feature access with validity, signature and deadlines. Keep an explicit validity/signature gate. Older envelopes use the canonical fallback; never bypass verification. Historical signing-key overlap is not implemented.

Your own store handles software-buyer payments. KDPika Licensing bills only your developer platform subscription. Package/import names remain unchanged for compatibility. No registry publication is implied by this local-download README. The short host-application excerpts are not a complete application; the upcoming documentation phases must supply executed, standalone samples before treating them as a quickstart.

Contact: [hi@kdpika.com](mailto:hi@kdpika.com). [Current documentation](https://licensing.kdpika.com/documentation), [security controls](https://licensing.kdpika.com/#security), [service health check](https://licensing.kdpika.com/healthz).

<!-- P7-QUALITY:START -->
## Complete integration guide and source distribution

Read the complete guide at https://licensing.kdpika.com/docs/v1/sdks/php.
The public documentation describes actual source versions, local install steps,
every constructor/member, synchronous/async behavior, storage, offline policy,
logging, framework integration, packaging, upgrades and removal. Downloads are
source packages with SHA-256 checksums; registry publication is not verified.

Use Sandbox explicitly in tests. An App ID is public; lmp_ developer keys belong
only on your trusted backend. Keep a random stable installation ID and private
state outside executable extraction/public web directories. A token-presence
property, HTTP200, true feature helper or successful deactivation is not a
current access grant. Require valid and signature verification before features.

### Current release limitations

Python/PHP/JavaScript/C# 2.1.0 and Rust 0.2.0 include the current signed-response
and feature-guard protections. PHP requires8.2+; PHP8.5 can emit curl_close
deprecation. Old1.0.0 caches may require an online refresh. Never disable
Ed25519, strip signed fields or extend offline permission.
The complete guides provide source-derived boundaries and test vectors.

Python can prefer an installed usable OS keyring; Node/PHP/C# use permission-
protected files, not automatic encrypted vaults. Local machine owners can patch
an app. No universal OS/anti-tamper/security-certification guarantee is made.

### Package tests and examples

The examples folder contains exact documented session/member sources plus layout
instructions. Existing package tests use synthetic signing/transports; run real
Sandbox lifecycle and public verification vectors as separate acceptance tests.

Run `composer install --no-plugins --no-scripts`, then `php tests/standalone.php`. The unchanged legacy `tests/run.php` assumes a repository-root vendor directory and is not the standalone package entry.

See LICENSE for existing license-information boundaries and CHANGELOG.md for verified observations; neither implies an invented public registry release.
<!-- P7-QUALITY:END -->

## Web-request lifecycle (current source addition)

The constructor accepts `releaseSessionOnDestruct: false` for a trusted web app that deliberately keeps a signed seat across PHP requests. Default remains true. Keep the same private state folder, send authenticated heartbeats at the signed interval, explicitly checkin on close, and require `check()` before every protected operation. Expiry still stops access if the browser disappears. This optional source addition is not yet in the published 2.1.0 package; no signature checks are bypassed.
