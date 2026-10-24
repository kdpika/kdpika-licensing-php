# KDPika Licensing PHP SDK

## Unreleased source — Phase B/C/D parity

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


Official PHP client for license activation, signed validation, controlled offline
access, feature checks and deactivation. **PHP 8.2+**, SDK **1.0.1**.

[Documentation](https://licensing.kdpika.com/docs/v1/sdks/php) ·
[GitHub source](https://github.com/kdpika/kdpika-licensing-php) ·
[Download SDK ZIP](https://github.com/kdpika/kdpika-licensing-php/releases/download/v1.0.1/kdpika-php-sdk-1.0.1.zip) ·
[Packagist package](https://packagist.org/packages/kdpika/license-sdk)

## Install with Composer

From your application's directory, install the published stable package:

```sh
composer require "kdpika/license-sdk:^1.0.1" --no-interaction --no-plugins --no-scripts
```

Load `vendor/autoload.php` in your application and use
`KDPika\LicenseSdk\LicenseClient`. Commit your application's `composer.lock` to
retain its resolved dependency versions. You do not need to download a ZIP or
add a custom repository to use the Packagist package.

## Install from the source ZIP

Download [kdpika-php-sdk-1.0.1.zip from KDPika](https://licensing.kdpika.com/docs/downloads/kdpika-php-sdk-1.0.1.zip)
or the identical versioned asset from GitHub Releases. Verify its SHA-256 against
the [download table](https://licensing.kdpika.com/docs/v1/sdks/overview#download-and-verify),
extract it beside your application, then install dependencies:

```sh
composer install --working-dir ./kdpika-php-sdk --no-interaction --no-plugins --no-scripts --prefer-dist
```

Or clone this repository (select a reviewed tag/commit before distribution):

```sh
git clone https://github.com/kdpika/kdpika-licensing-php.git kdpika-php-sdk
composer install --working-dir ./kdpika-php-sdk --no-interaction --no-plugins --no-scripts --prefer-dist
```

Composer package identity is `kdpika/license-sdk`; namespace is
`KDPika\LicenseSdk`. It is published on
[Packagist](https://packagist.org/packages/kdpika/license-sdk) as version 1.0.1;
Composer and source ZIP installation are alternatives, not two required steps.
Dependencies need PHP cURL/JSON and native Sodium or Composer's declared
`paragonie/sodium_compat` fallback. Run the SDK in a trusted PHP backend or CLI,
never in a public browser.

## Quickstart

Create a product and Sandbox license in your workspace. Set your public App ID,
license key and matching buyer email privately. Choose a private persistent
absolute state directory outside the public web root. The example below uses
the Composer installation in your application's directory:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use KDPika\LicenseSdk\LicenseClient;

$client = new LicenseClient(
    product: (string) getenv('KDP_APP_ID'),
    environment: 'SANDBOX',
    applicationName: 'Your Application',
    applicationVersion: '1.0.0',
    storageDir: (string) getenv('KDP_STATE_DIR'),
);

$result = $client->check();
if (!$result->valid && $result->errorCode === 'NOT_ACTIVATED') {
    // In your app, obtain these privately from the activation form.
    $result = $client->activate(
        (string) getenv('KDP_LICENSE_KEY'),
        getenv('KDP_CUSTOMER_EMAIL') ?: null,
    );
}
if (!$result->valid || !$result->signatureValid) {
    echo 'Please check your license or contact support.';
    exit(1);
}
if ($result->feature('pdf_export')) {
    // Enable only the feature included in this verified license.
}
```

Feature names come from your product's plan. The email must match when supplied.
If you installed the source ZIP beside the example instead, change the autoload
line to `require __DIR__ . '/kdpika-php-sdk/vendor/autoload.php';`.
Never put a developer management API key in distributed customer software. The
customer key is used for activation; the private activation token is stored by
the client. Do not print tokens, raw responses, keys or private state in logs.

## Lifecycle

- `activate(key, email)`: activate online and verify the signed decision.
- `check()`: use a current verified cache, otherwise validate.
- `validate()`: request a check; genuine transport failure may use signed grace.
- `deactivate()`: release the installation and clear stored access after confirmation.
- `activated()`: token presence only, not current approval.
- `result->feature(name)`: use only after checking validity and signature.

A successful deactivation acknowledges a release, **not permission to run paid
features**, even where the operation result reports valid. Keep the installation
identity/state directory stable across restarts and application updates.

## Security and compatibility boundaries

- SDK 1.0.1 verifies the server's exact signed `payload_json` bytes and checks
  product, environment, installation binding, status and time/offline boundaries.
  Legacy envelopes retain the canonicalization fallback; never disable signatures.
- Feature helpers fail closed for invalid/unsigned/outdated access; keep your
  explicit validity/signature gate and type/range checks for numeric quotas.
- Server refusals must not become offline grants. Offline apps cannot learn new
  revocations until they reconnect; offline access is never unlimited.
- Files are permission-protected, not automatic Keychain/DPAPI/Secret Service
  storage. Assess permissions, process concurrency and OS protection for your app.
- Historical signing-key overlap is not supplied. Coordinate pinned-key rotation.
- PHP 8.5 may emit a `curl_close` deprecation notice; do not expose PHP diagnostics
  publicly or suppress a failed license decision.
- A machine owner can patch local software. Protect valuable online operations
  on an authenticated backend; no anti-tampering certification is claimed.
- Your store handles your software buyers' payments. This SDK does not create
  subscriptions, process card payments or replace your application's own auth.

## Examples and tests

See `examples/session.php` and `examples/members.php`, plus their layout
instructions. Session examples use private KDP_* configuration and Sandbox.

From the SDK directory, after Composer installation:

```sh
php tests/standalone.php
```

This package-local suite uses synthetic signing keys/transports, not production
accounts. The legacy `tests/run.php` retains its original repository-relative
autoload assumption and is not the standalone entry. More extensive PHP-generated
numeric/Unicode/cache and isolated Sandbox evidence is documented in the platform
guide; a CI build is not certification of every deployment environment.

The versioned download assets are immutable tested snapshots. GitHub README,
Composer metadata and CI documentation can improve without changing their bytes.
The SDK runtime source is the same 1.0.1 implementation; no new runtime behavior
or universal cross-language parity is implied by this repository publication.

## SDK repositories and ZIP downloads

| Language | GitHub repository | ZIP from documentation | ZIP from GitHub Releases |
| --- | --- | --- | --- |
| Python | [kdpika-licensing-python](https://github.com/kdpika/kdpika-licensing-python) | [Python 1.1.6 ZIP](https://licensing.kdpika.com/docs/downloads/kdpika-python-sdk-1.1.6.zip) | [Python 1.1.6 ZIP](https://github.com/kdpika/kdpika-licensing-python/releases/download/v1.1.6/kdpika-python-sdk-1.1.6.zip) |
| Rust | [kdpika-licensing-rust](https://github.com/kdpika/kdpika-licensing-rust) | [Rust 0.1.1 ZIP](https://licensing.kdpika.com/docs/downloads/kdpika-rust-sdk-0.1.1.zip) | [Rust 0.1.1 ZIP](https://github.com/kdpika/kdpika-licensing-rust/releases/download/v0.1.1/kdpika-rust-sdk-0.1.1.zip) |
| PHP | [kdpika-licensing-php](https://github.com/kdpika/kdpika-licensing-php) | [PHP 1.0.1 ZIP](https://licensing.kdpika.com/docs/downloads/kdpika-php-sdk-1.0.1.zip) | [PHP 1.0.1 ZIP](https://github.com/kdpika/kdpika-licensing-php/releases/download/v1.0.1/kdpika-php-sdk-1.0.1.zip) |

Python is also on [PyPI](https://pypi.org/project/kdpika-licensing/); Rust is on
[crates.io](https://crates.io/crates/kdpika-licensing); PHP is on
[Packagist](https://packagist.org/packages/kdpika/license-sdk).

## License and support

This PHP package **retains its existing proprietary license**, unlike the
MIT-licensed Python/Rust SDKs. Public source access does not convert its license
or grant unrestricted redistribution. See LICENSE and contact
[hi@kdpika.com](mailto:hi@kdpika.com) for applicable integration/distribution terms.
KDPika's SaaS backend is not included.
