# QWK Offline Mail

## Local QWKnet parser proof (M1)

The isolated `src/QwkNet/Parser.php` accepts an explicit local ZIP path and returns
`Packet` / `Message` objects. It does not load the application, access a database,
extract files, connect to a hub, or invoke the offline-reader REP posting path.
Run the diagnostic from the repository root:

```sh
php -n -d extension=zip -d extension=iconv -d allow_url_fopen=0 tools/qwknet-preview.php /explicit/packet.qwk
php -n -d extension=zip -d extension=iconv -d allow_url_fopen=0 tests/QwkNetParserTest.php
```

Add `--details` to the preview for display bodies, base64 original body blocks,
and selected metadata. Add the original local WeedNet specimen path to the test
command to enable the hash-pinned ten-message acceptance test. The specimen is
not distributed in the repository. Diagnostics omit header passwords and sender
IP/host fields and escape controls using JSON.

Limits are constructor options: 16 MiB archive, 64 MiB total uncompressed content,
256 root-level files, and 10,000 messages. The reader rejects unsafe/duplicate
names, links, encrypted members, corrupt ZIP entries, truncated records,
unmapped conferences, and conflicting metadata. CONTROL.DAT and MESSAGES.DAT are
required. Additional components are retained verbatim; VOTING/NDX/NETFLAGS are
not interpreted in M1.

HEADERS.DAT sections are hexadecimal byte offsets to MESSAGES.DAT record headers.
Every supplied section must resolve exactly. Conference, fixed-header name/subject
prefixes, written wall clock, and inline IDs/timezone tokens are cross-checked
where present. Conflicts fail rather than choosing a source. Compatible extended
names/subjects take precedence over truncated fixed fields; IDs use extended
values when present and inline values otherwise. Repeated import/export times
and ExportedFrom hops remain ordered lists, with the complete raw sections also
retained. No hop is selected as a final provenance policy.

M1 supports CP437, non-QWKE packets. It normalizes a written UTC timestamp only
from an explicit HEADERS.DAT WhenWritten offset. Inline Synchronet timezone tokens
and packet creation time remain raw when no explicit offset is available. Known
Ctrl-A color attributes are removed only from the display projection; unknown
Ctrl-A pairs receive visible markers. Raw archive members, fixed headers, and
padded body blocks are preserved byte-for-byte. External conference numbering is
independent of the local offline-reader conference map described below.

## Inter-BBS QWK networking — inbound import

This is a separate subsystem from the offline reader below: instead of a user
downloading their own packet, BinktermPHP exchanges QWK packets with another BBS
("QWK networking", as used by WeedNet and Synchronet's QNet). This slice covers
**local-file inbound import only** — there is no FTP, polling, scheduler,
outbound REP, or relay/gating yet.

- **Mailboxes** (`qwk_mailboxes`) describe a remote QWK peer: its BBS/packet ID,
  connection details, and a `SysK`-encrypted password. Configure them through
  the admin interface (model: `BinktermPHP\Qwk\QwkMailboxManager`).
- **Subscriptions** (`echo_area_qwk_subscriptions`) map one remote conference
  number on one mailbox to one local echo area. Mappings are explicit — nothing
  is auto-created. The remote conference number comes from that peer's
  `CONTROL.DAT`; it is unrelated to `echoareas.qwk_conference_number` (the local
  offline-reader numbering).
- **Import** (`BinktermPHP\Qwk\QwkInbound`, CLI `scripts/qwknet_import.php`):
  the archive is parsed by the M1 parser above, its `CONTROL.DAT` BBS ID must
  match the mailbox's `bbs_id` (uppercased) or the whole packet is rejected
  before any write, and each message is routed by `mailbox + conference` to a
  subscribed echo area. Messages in unmapped conferences are counted and skipped.
- **Replay safety**: `qwk_inbound_packets` has `UNIQUE (mailbox_id,
  archive_sha256)` — re-importing a byte-identical packet is a clean no-op.
  Per-message, `qwk_inbound_messages` has `UNIQUE (mailbox_id, conference_number,
  dedupe_key)`, where `dedupe_key` is `msgid:<id>` when the packet supplies a
  MSGID for the record and the hub-stable `qwknum:<n>` otherwise (never the
  filename or import time). Each import is a single transaction: an unexpected
  failure rolls back the receipt and every message.
- **Provenance**: `qwk_inbound_messages` keeps the untruncated external MSGID and
  reply MSGID, VIA hops, TZ token, remote message number, and record offset for
  each imported `echomail` row.
- **Isolation**: imported messages are inserted with `user_id` NULL and the
  synthetic non-FTN sender address `qwk:<BBSID>` (it parses to the null FTN
  address, so no FTN spool/export path treats it as a real node). The FTN
  outbound spool, hub fan-out, and uplink relay are never invoked. A remote
  display name that coincides with a local username does not confer local delete
  ownership on an imported message.
- **Reply linking** is by external MSGID within the same mailbox + conference,
  with one bounded backfill pass per import for replies whose parent arrived
  later. Links are never made across mailboxes or conferences, and a bounded
  cycle check prevents `reply_to_id` loops.

### Outbound

- A **locally-authored** echomail post in an area that has a QWK subscription is
  queued for export (`qwk_outbound_messages`, one row per subscribed mailbox,
  state `pending`). The enqueue is wired from `MessageHandler::postEchomail()`
  and `approveEchomail()`. Imported QWK messages and inbound FTN messages are
  never queued (this slice is not the FTN&harr;QWK relay).
- A poll batches all `pending` rows for a mailbox into one `qwk_rep_batches`
  row and builds a single `<BBSID>.REP` (`<BBSID>.MSG` records + `HEADERS.DAT`).
  Batch states: `built` &rarr; `upload_attempted` &rarr; `uploaded` / `failed`.
  A batch's message set is frozen when it is created, and each message keeps a
  stable MSGID, so retrying a `failed` batch rebuilds a byte-identical REP. An
  `upload_attempted` batch (a STOR issued but never confirmed) is **not**
  retried automatically -- it is surfaced for an operator to confirm or clear.
- **Transport** is `BinktermPHP\Qwk\Transport\FtpStreamTransport`, built on
  PHP's native `ftp://` stream wrapper (no `ext-ftp` needed). It is
  **passive-mode only** and **plain FTP** -- credentials and data cross the wire
  unencrypted, matching the Synchronet "QNET over FTP" convention these hubs
  use. The decrypted password only ever appears in the in-memory request URL; it
  is never logged (log lines show `ftp://<user>:***@<host>`).
- One mailbox is run end to end with `php scripts/qwknet_poll.php <mailbox-id>`
  (add `--dry-run` to build the REP without uploading). There is no scheduler
  wiring.
- The mailbox FTP password is stored `SysK`-encrypted; set it with
  `php scripts/qwknet_set_password.php <mailbox-id>` (interactive, no-echo).

QWK is an offline mail format originating from the BBS era. Instead of reading
and writing messages while connected, you download a packet containing all new
messages, disconnect, read and reply at your leisure in a local reader
application, then reconnect and upload the reply packet. BinktermPHP supports
the standard QWK format and the QWKE (QWK Extended) variant which carries full
FidoNet metadata.

## Table of Contents

- [How It Works](#how-it-works)
- [Packet Formats](#packet-formats)
  - [QWK](#qwk)
  - [QWKE](#qwke)
- [Conferences](#conferences)
  - [Personal Mail (Conference 0)](#personal-mail-conference-0)
  - [Echo Areas](#echo-areas)
- [Message Limits](#message-limits)
- [Composing Messages](#composing-messages)
  - [Subject Lines](#subject-lines)
  - [Sending Netmail](#sending-netmail)
- [Uploading a REP Packet](#uploading-a-rep-packet)
  - [Validation](#validation)
  - [Deduplication](#deduplication)
  - [Reply Threading](#reply-threading)
- [API Endpoints](#api-endpoints)
- [Recommended Readers](#recommended-readers)

---

## How It Works

1. **Download** a QWK packet from `/api/qwk/download`. The packet is a ZIP
   archive named `BBSID.QWK` containing all new messages since your last
   download across your subscribed echo areas and personal mail.

2. **Open** the packet in a QWK-capable offline reader. Read messages, compose
   replies, and write new messages.

3. **Export** the reply packet from your reader. This is a ZIP archive named
   `BBSID.REP` containing a `BBSID.MSG` file with your outgoing messages.

4. **Upload** the REP packet to `/api/qwk/upload`. BinktermPHP imports your
   messages, posts echomail to the appropriate areas, and routes netmail.

The same workflow is also available through the optional FTP daemon:

- Download: `/qwk/download/<BBSID>.QWK`
- Upload: `/qwk/upload/<BBSID>.REP` or `/qwk/upload/<BBSID>.ZIP`

You must download a QWK packet at least once before uploading a REP packet.
The download establishes the conference map that BinktermPHP uses to route
your replies back to the correct echo areas.

---

## Packet Formats

### QWK

The standard QWK format encodes message text in CP437 (PC character set).
Conference and message header fields are limited to 25 characters for To, From,
and Subject. QWK is supported by virtually all offline readers.

### QWKE

QWKE (QWK Extended) is a backward-compatible extension that carries full
FidoNet metadata inside the packet. BinktermPHP signals QWKE support via
`CONTROLTYPE = QWKE` in `DOOR.ID` and a `TOREADER.EXT` file listing supported
kludge types: `CHRS`, `MSGID`, `REPLY`, `TZUTC`, `INTL`, `FMPT`, `TOPT`.

Both QWK and QWKE export message bodies in CP437. Differences in QWKE:

- FidoNet kludge lines (`^A`-prefixed) are prepended to each message body,
  carrying `MSGID`, `REPLY`, `TZUTC`, `INTL`, and other metadata. A
  `^ACHRS: CP437 2` kludge signals the body encoding.
- Plain-text extended headers (`Subject:`, `To:`, `From:`) are written before
  the kludge lines when the corresponding field exceeds 25 characters, allowing
  readers that do not support `^A` prefixes to still benefit from extended
  field lengths.

QWKE is recommended for readers that support it. Use plain QWK for maximum
compatibility with older readers.

### Choosing QWK vs QWKE

HTTP downloads can choose the format explicitly with
`/api/qwk/download?format=qwk` or `/api/qwk/download?format=qwke`.

FTP downloads do not currently expose a separate path or filename for choosing
the format. Instead, `/qwk/download/<BBSID>.QWK` uses the account's saved QWK
format preference (`qwk` or `qwke`), the same preference set by the web UI and
`POST /api/qwk/format`.

---

## Conferences

### Personal Mail (Conference 0)

Conference 0 is always Personal Mail — netmail addressed to you. Messages in
this conference are marked private (`+` status byte). When you compose a reply
to a conference-0 message, BinktermPHP routes it to the FTN address of the
original sender using the message index from your most recent download.

### Echo Areas

Each echo area you subscribe to is assigned a stable, BBS-wide conference
number (stored persistently on the echo area record). These numbers are
consistent across all users and across downloads — subscribing or unsubscribing
from other areas does not change the conference numbers you already know.

Conference names in `CONTROL.DAT` are truncated to 13 characters. The format
is `AREANAME` or `AREANAME@DOMAIN` when a network domain is present.

---

## Message Limits

Each download is limited to a configurable number of messages across all
conferences combined:

| Setting | Value |
|---|---|
| Default per-download limit | 2,500 messages |
| Hard cap | 10,000 messages |

You can choose a preferred limit (500 – hard cap) in the web UI; the preference
is saved to your account. The limit can also be overridden per-request via the
`limit` query parameter on `GET /api/qwk/download`.

When the limit is reached, messages are included in conference order (Personal
Mail first, then echo areas in conference-number order) and older messages
within each conference are prioritised over newer ones.

---

## Composing Messages

### Subject Lines

The QWK message header has a fixed 25-character subject field. In QWKE mode,
BinktermPHP writes a plain-text `Subject:` line at the top of the message body
when the subject exceeds 25 characters, and reads it back on REP import. This
means subjects up to 71 characters are preserved end-to-end when using a
QWKE-capable reader.

In plain QWK mode subjects are hard-limited to 25 characters.

### Sending Netmail

Replies to received netmail are automatically routed to the FTN address of the
original sender via the message index — no special action is needed.

To compose **new** netmail to an arbitrary FTN address, put the destination in
the To field using the following format:

```
Name@zone:net/node[.point]
```

Examples:

```
Sysop@1:1/1
John Smith@2:280/464
Point User@3:712/848.5
```

The address portion after `@` is extracted and used for FTN routing. The name
portion before `@` is used as the recipient name. If no name is given (i.e. the
field starts with `@`), the recipient name defaults to `Sysop`.

If no address is embedded and no reply reference is present, the message is
routed to the system address as a fallback.

---

## Uploading a REP Packet

REP upload parsing is shared across the web UI, the HTTP API, and FTP. There is
no separate "QWKE upload mode" switch. BinktermPHP inspects the uploaded
packet's contents and imports QWKE extended headers when they are present.

### Validation

BinktermPHP validates the REP packet before importing any messages:

- The file must be a valid ZIP archive.
- The archive must contain a `BBSID.MSG` file where `BBSID` matches this
  system's BBS ID (derived from the system name: non-alphanumeric characters
  stripped, uppercased, truncated to 8 characters; falls back to `BINKTERM`).
- The MSG file size must be a non-zero multiple of 128 bytes (the QWK block
  size). A file that fails this check is rejected as corrupt.
- The upload must not exceed 10 MB.
- A prior QWK download must exist for your account (the conference map from
  that download is required to route replies).

Messages with an empty body are silently skipped. Messages with activity flag
`0x00` and a block count of 1 or less are treated as end-of-file padding and
skipped.

### Deduplication

BinktermPHP computes a SHA-256 hash of each imported message's content
(conference number, To name, Subject, and body). If an identical message has
been imported before, it is skipped. This means re-uploading the same REP
packet is safe — no duplicate messages will be posted.

### Reply Threading

Each QWK download records a message index mapping QWK logical message numbers
(1-based, sequential across all conferences) to internal database IDs. When a
reply packet references a message number in the `reply-to` header field,
BinktermPHP looks it up in the index to establish the reply relationship and,
for netmail, to resolve the destination FTN address.

The index is replaced on every download, so reply references are only valid
against the most recent packet.

---

## API Endpoints

| Method | Path | Description |
|---|---|---|
| `GET` | `/api/qwk/download` | Build and stream a QWK packet. Optional `?format=qwk\|qwke` and `?limit=N` parameters. |
| `POST` | `/api/qwk/upload` | Accept a REP packet upload (`multipart/form-data`, field name `rep`). Returns `{success, imported, skipped, errors[]}`. |
| `GET` | `/api/qwk/status` | Return current conference state, new message counts, last download timestamp, and format preference. |
| `POST` | `/api/qwk/format` | Save preferred packet format. Body: `{"format": "qwk"}` or `{"format": "qwke"}`. |

All endpoints require authentication and return JSON (except `/download` which
streams a ZIP file).

The browser-oriented `/api/qwk/*` endpoints use the normal logged-in session.
The reader-oriented `/qwk/download` and `/qwk/upload` endpoints use HTTP Basic
auth with your BBS username and password. Failed Basic-auth attempts are
rate-limited by the same shared dual counter (submitted username and source
IP) as the interactive login surfaces — `AUTH_LOGIN_USER_MAX` failures per
username and `AUTH_LOGIN_IP_MAX` per IP within `AUTH_LOGIN_WINDOW` seconds
(defaults 5 / 20 / 900). A throttled request returns the same generic `401`
as a wrong password; there is no account lockout, and a successful login
clears the username counter.

If the optional FTP daemon is enabled, the equivalent FTP paths are:

- `/qwk/download/<BBSID>.QWK`
- `/qwk/upload/<BBSID>.REP`
- `/qwk/upload/<BBSID>.ZIP`

---

## Recommended Readers

| Reader | Platform | QWK | QWKE |
|---|---|---|---|
| MultiMail | Linux, Windows, macOS | ✓ | ✓ |
| OLX | DOS | ✓ | — |
| Yarn | Cross-platform | ✓ | Partial |

QWKE support in readers varies. MultiMail reads and writes QWKE extended
subject, to, and from headers and passes `^A`-prefixed kludge lines through
to the message body for display. It does not write `^AINTL` routing kludges
in reply packets; use the `Name@address` To-field convention (described above)
for new netmail.
