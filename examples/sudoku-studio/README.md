# KDPika Sudoku Studio — PHP web app

A complete application example using the real PHP SDK. The original [License Lab](https://licensing.kdpika.com/license-lab) stays available separately.

**Try online:** [Sudoku Studio](https://licensing.kdpika.com/sudoku-studio) · [Practical guide](https://licensing.kdpika.com/docs/v1/tutorials/php/sudoku) · [Credit packs and codes](https://licensing.kdpika.com/docs/v1/licensing/credits)

## Run without editing code

1. Install 64-bit PHP 8.2 or newer, with `curl` and `sodium`. Enable `zip` for paid printable packs.
2. Extract this folder, open a terminal in it and run:

```sh
php -S 127.0.0.1:8080 -t public public/index.php
```

3. Open http://127.0.0.1:8080/sudoku-studio in your browser.
4. Enter your **App ID**, **Live / Test**, **license key** and optional matching **email** in the form. No code or secret configuration file needs editing.

The matching SDK source is included. The private app secret and saved SDK state are generated automatically under `storage/`. Keep this folder private; do not upload it to GitHub or copy its machine identity to another host. No developer API key, account password or sample customer key is included.

## What to try

- **Generate / JPG:** included with any valid online license. Zero credits does not close the app.
- **PNG / collection:** your software plan enables `png_export` / `batch_generation`.
- **Crown and balance:** shows the current signed real balance. Redeem a code such as KDP100CR, or choose a pack and continue to your own connected WooCommerce store.
- **Paid pack:** enable `create_pack` in the developer workspace's Usage credits → Settings. Confirm its current unit price, then download ten SVG puzzles. A saved job is recovered using its original request key; retrying it cannot debit twice.
- **Shared seats:** reserve/return a seat, test a second server-side installation explicitly, and release it afterwards.
- **Fresh API reply:** a new real request, with tokens and customer details hidden in the viewer. Signature verification uses original unmodified bytes.
- **Offline experiment:** one intentionally blocked request after an online validation. Metered credit access stays online-only; standard signed grace and floating leases enforce their own deadlines.
- **Device unlink:** removes this app's activation; your software subscription and purchases remain.

The browser is a UI for the PHP host. Its fingerprint identifies that **server**, not the visitor's physical computer. A second test installation represents another software installation on the same host. This is an illustrative puzzle generator; puzzles are not certified to have a unique solution. Licensing and credit replies are real.

## Shared hosting

Point the site's document root at `public/` and use HTTPS. Keep `app/`, `sdk/` and `storage/` above the document root. Do not upload a populated storage folder. No Docker, Node, root access or database is required by this example. A working KDPika platform and compatible credit-commerce server update are required for packs/codes; the app cannot grant itself units.

The example still links to KDPika's docs and the original License Lab. These are documentation links, not your store login or a platform-account requirement.

App source: MIT. SDK source: MIT. See LICENSE and sdk/LICENSE. No payment-card details are stored in this app.
