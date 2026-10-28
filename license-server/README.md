# Ticket Pro VN license server

This is a standalone PHP 8.2+ license service. Point a web server document root at `public/` and expose it only via HTTPS.

## Setup

1. Copy `config.php.example` to `config.php`, set long unique `TPV_LICENSE_ADMIN_USER` and `TPV_LICENSE_ADMIN_PASSWORD` environment variables, and keep `config.php` outside the document root.
2. Generate a signing key: `openssl genpkey -algorithm RSA -out storage/license-private.pem -pkeyopt rsa_keygen_bits:3072`.
3. Export its public key: `openssl pkey -in storage/license-private.pem -pubout -out storage/license-public.pem`.
4. Protect the application with TLS, set `TPV_LICENSE_ADMIN_USER` and `TPV_LICENSE_ADMIN_PASSWORD`, and browse the service root to issue a key.
5. Paste the service HTTPS URL, created key, and the contents of `storage/license-public.pem` into Ticket Pro VN > License.

The server stores only hashes of issued keys. A raw key is shown once at creation, so retain it securely.

## Subscription terms

New keys default to a 365-day term. Creating or reserving a key does not start
the term: `activated_at` and `expires_at` are set only after the first
successful `activate` request from the customer's WordPress site. The dashboard
shows the activation and expiry dates and lets an administrator renew an active
or expired key. A renewal adds its selected duration to the current expiry, or
from the present time when the key has already expired.

Keys issued before subscription terms were enabled are migrated with a `0` day
term, which means perpetual; their validity is unchanged.

## Recovering issued keys

New keys are encrypted with AES-256-GCM before being stored. The encryption
secret is kept in `storage/license-key-encryption.secret`, outside the public
directory and separate from the SQLite database. The dashboard can copy or
reveal an encrypted key after a CSRF-protected, audited administrator action;
the key is never rendered in the license list itself.

Older hash-only keys are upgraded automatically the next time their WordPress
site validates them. If an older key cannot validate and therefore has no
encrypted copy, use **Cấp lại key** in the dashboard. It keeps the license's
domain, installation, term, expiry, and audit history but invalidates the old
key, so the customer must enter the replacement key before their next check.

The dashboard searches by customer domain, internal note, installation ID,
license ID, or the visible start/end portions of a key. Status filters and
server-side pagination show 25 licenses at a time, so large installations do
not require loading every card in the browser.
