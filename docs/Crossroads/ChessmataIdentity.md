# Chessmata identity broker (Crossroads Experience #4, Slice 2)

> L33TEST/Crossroads integration note. This is not a generic BinkTermPHP
> capability — it is the smallest additive glue that gives one authenticated
> BinkTerm user one self-hosted Chessmata account across both future surfaces
> (graphical Web, terminal/Telnet CLI). Slice 3 (Telnet) and Slice 4 (WebDoor)
> consume it; neither surface is wired yet.

## Boundary

```
 authenticated BinkTerm user
        │  int users.id
        ▼
 BinktermPHP\Crossroads\ChessmataIdentity          ← broker (this slice)
        │  ordinary HTTP  (http://chessmata:9029, internal compose network)
        ▼
 Chessmata API  (register / login / refresh / api-keys / me)
        │
        ▼
 Chessmata-owned MongoDB
```

BinkTerm never opens a Mongo connection and never references a Chessmata
collection, document, field name, or ObjectID beyond the opaque
`chessmata_user_id` string the HTTP API returns.

## Classes (`src/Crossroads/`)

| Class | Role |
|---|---|
| `ChessmataIdentity` | the broker: `resolve()`, `terminalCredential()`, `webCredential()`, `existingMapping()`, `forget()` |
| `ChessmataApiInterface` / `ChessmataApiClient` | the ~5 Chessmata HTTP endpoints the broker uses (interface so tests can supply a double) |
| `ChessmataSecretBox` | libsodium `crypto_secretbox` encrypt-at-rest for the stored bearer secrets. BinkTermPHP has no shared encryption facility (other external-service secrets are plaintext columns); this is deliberately Chessmata-scoped, not promoted |
| `ChessmataAccount` | immutable, secret-free descriptor returned by `resolve()` |
| `ChessmataIdentityException` / `…ProvisioningRateLimited` / `…BrokerUnavailable` | typed failures; messages are safe to log |

## Storage — `chessmata_identities`

One row per BinkTerm user (`UNIQUE binkterm_user_id`, `ON DELETE CASCADE`).
`password_enc` (recovery root), `api_key_enc` (durable everyday credential),
`refresh_token_enc`, `access_token_enc` — **every** bearer value is a
`ChessmataSecretBox` ciphertext, never plaintext, never logged. The broker
classes contain no logging/output calls at all.

## Provisioning (first `resolve()`)

Serialised per user by `pg_advisory_xact_lock` + the `UNIQUE` constraint, so Web
and Telnet resolving at the same instant cannot create two accounts.

1. internal email `bt-<users.id>@chessmata.invalid` (RFC 2606 — never
   deliverable, cannot be confused with real mail; Chessmata does no email
   validation).
2. display name from `users.username`, sanitised; `-<id>` suffix on a 409.
3. strong generated password meeting Chessmata's policy (≥10, U+l+d+special).
4. `POST /api/auth/register` — the genuine caller IP is forwarded as
   `X-Forwarded-For` so Chessmata's 5/hour/IP register cap applies per real
   caller, not per container. A `429` becomes `ChessmataProvisioningRateLimited`
   (retry on the caller's next launch — never fall back to anonymous).
5. the account must come back `emailVerified: true` (self-host patch 0003 /
   `auth.autoVerifyEmail`) or the broker refuses it.
6. `POST /api/auth/api-keys` mints the durable `cmk_` credential.
7. all secrets encrypted and stored; access `+30d`, refresh `+90d`.

## Re-resolution & tokens

`resolve()` returns the existing mapping unchanged. `webCredential()` uses the
cached access token, then `POST /auth/refresh`, then a full `POST /auth/login`
from the stored password — the password is retained as the recovery root
because Chessmata refresh tokens expire (90d) and `/auth/refresh` does not
rotate them. `terminalCredential()` returns the stored `cmk_` key (no expiry).

## Surface credentials (for Slice 3 / 4 — not wired here)

* **Terminal:** `ChessmataIdentity::terminalCredential($userId)` → `cmk_…` for
  `~/.config/chessmata/credentials.json`.
* **Web:** `ChessmataIdentity::webCredential($userId)['accessToken']` → a JWT
  for the SAME Chessmata account (same Elo / history / leaderboard row).

## Deployment

* Migration `v20260903204726_add_chessmata_identity_mapping.sql` (run
  `php scripts/upgrade.php`).
* Encryption key: ops compose secret `chessmata_broker_key` (32 bytes), mounted
  at `/run/secrets/chessmata_broker_key`, owned by uid 33 (`www-data`). Never in
  git. `CHESSMATA_INTERNAL_URL` defaults to `http://chessmata:9029`.
* The Chessmata service must run patch 0003 with `auth.autoVerifyEmail: true`.
