# Dashboard

The dashboard is the first page users see after logging in. It provides a summary of unread mail, new activity, and quick access to common features. It is divided into a wide main column and a narrower sidebar, each containing a set of cards that can be reordered, hidden, or moved between columns.

## Table of Contents

- [Cards](#cards)
- [Echomail Badge Mode](#echomail-badge-mode)
- [Customizing the Layout](#customizing-the-layout)
- [Sysop Configuration](#sysop-configuration)

---

## Cards

The dashboard is composed of cards. Which cards appear depends on which features are enabled and whether the user is an admin.

### Mail & Areas *(always visible)*

A compact arrival summary for every authenticated caller: unread netmail, new echomail with an area count, and unread bulletins. The links open the existing Netmail page, the caller's preferred Echomail landing page, and the unread Bulletins page. It shows counts only, without message bodies, subjects, traversal IDs or area identities. This is part of the existing required Mail & Areas card; there is no separate What's New card.

The card consumes the same `UnifiedNewscanService::plan()` as terminal Newscan. Netmail uses the canonical recipient/unread predicate. Echomail uses subscribed, accessible active areas, their last-read high-watermarks, individual read state, and existing ignore/moderation/future-date filters. This is Newscan's definition even when a caller chooses a different badge mode elsewhere. Bulletins use `BulletinManager` unread state. Notification acknowledgment state is not a source.

Planning and presentation do not mark anything read, advance any high-watermark, or acknowledge notification snapshots. The summary is server-rendered on dashboard navigation/reload, with no new API or polling. Normal reading in the existing readers remains responsible for read-state changes.

An empty complete scan says “You're caught up.” The unchanged terminal caps (300 netmails, 60 candidate areas, 300 messages per area) bound the scan. When the plan signals truncation, a limit notice explains that more may be available; even an empty truncated scan does not claim that the caller is caught up. A failed scan shows a restrained unavailable message rather than false zero counts.

Below the counters, the **New Echo Areas** section lists areas added in the past 30 days (up to 8), with their tag, network, and date. The section can be collapsed; the count is still shown when collapsed. Buttons at the bottom link to subscription management and, if enabled, the Interests page.

Area discovery stats refresh automatically every 30 seconds; they do not overwrite the canonical mail summary.

### System News

Displays the sysop-written MOTD (message of the day), formatted as Markdown. Set it from **Admin → Appearance & Content → Content → System News**.

### Shoutbox

An inline version of the Shoutbox — shows the most recent shouts, lets users post a new shout, and loads older shouts on demand. Appears only when the Shoutbox feature is enabled. See [Shoutbox](Shoutbox.md).

### Advertisement

Displays one or more ANSI-art ads from the ad system. When multiple ads are configured, they rotate automatically and can be navigated with previous/next buttons. Appears only when the Advertising feature is enabled and at least one ad is assigned to the dashboard position. See [Advertising](Advertising.md).

### Bulletins *(sidebar)*

Shows the count of unread bulletins with a link to the Bulletins page. Displays "No new bulletins" when all are read. See [Bulletins](Bulletins.md).

### System Information *(sidebar)*

Shows the sysop name, the system's configured location (when set to something other than the "Unknown Location" placeholder), the logged-in user's username, and the FTN network addresses the BBS is registered on. A few extra running totals — registered user count, today's caller count, system uptime (host OS uptime, when it can be determined), file area file count, all-time total login count, and total echomail message count — can also be shown, controlled by **Admin → Appearance & Content → Dashboard → Extra statistics in System Information card**, which offers three modes: **Off**, **Sysop Only** (default), and **All Users**.

### Recent Callers *(sidebar)*

Available to ordinary callers: up to six public usernames who genuinely arrived within the past seven days, newest first, with profile links and approximate presence (Online now, 12m ago, 2h ago, Yesterday). The card participates in normal dashboard layout customization. Hiding the card changes only the viewer's layout; it does not hide that viewer from other callers.

Arrival persists across logout in `users.last_caller_visit_at`. Normal Web, Telnet and SSH login records it. A remembered Web caller's trusted keyboard, pointer or touch interaction in a focused, visible document records a return; 30 minutes without such interaction starts a new episode. Continuous use sends one signal per episode. Polling, visibility changes, automatic restoration and passive reading of an already-rendered tab do not record arrival. Online now uses the separate existing session definition and can include unattended sessions.

M1 starts with NULL arrival timestamps, without historical seeding. Apply migration `v20260910141945_add_last_caller_visit_at.sql` through the normal human-supervised upgrade workflow. While that migration is pending, recording is inactive and the card shows its empty state. The list refreshes on dashboard navigation/reload.

### Today's Callers *(sidebar, admin only)*

A table of users who have logged in today, with the time of their last activity and an online indicator for users currently active.

### Voting Booth *(sidebar)*

Shows an active poll inline. Users can vote directly from the dashboard; results appear immediately after voting. If multiple polls are active, prev/next buttons cycle between them. Appears only when the Voting Booth feature is enabled. See [Voting Booth](VotingBooth.md).

### Echo Areas *(sidebar)*

Lists all subscribed echo areas with their unread and total message counts. Click an area name to go to its message list.

### Referral *(sidebar)*

Shows the user's personal referral link with a one-click copy button, plus stats (total referrals and credits earned) and a short list of recent referrals. Appears only when the credit system's referral feature is enabled.

---

## Echomail Badge Mode

Notification badges elsewhere can operate in two modes, selectable in **Account Settings**. These settings do not change the canonical Newscan summary in Mail & Areas:

- **New since last visit** (default) — counts messages that arrived after you last opened the echo area list. Fast query; resets automatically when you visit `/echomail`.
- **Unread** — counts messages in your subscribed areas that you have never opened, using the full read-tracking table. More accurate but a heavier database query on large installs.

---

## Customizing the Layout

Click the **Customize** button (top right of the dashboard) to open the layout editor. Each card appears as a draggable chip in either the Main Column or Sidebar Column list.

- **Drag** a chip to reorder it within a zone or move it to the other zone.
- Click the **eye icon** on a chip to hide or show that card.
- Click **Save** to apply the new layout; it is stored in your account settings and persists across sessions.
- Click **Reset** to restore the sysop's default layout (or the built-in defaults if no sysop default is configured).

The **Mail & Areas** card is required and cannot be hidden.

---

## Sysop Configuration

**Default layout** — Sysops can configure the layout new users start with from **Admin → Appearance & Content → Dashboard**. Drag cards between the Main Column, Sidebar Column, and Hidden lists and save. Individual users who have already customized their layout are unaffected; they can reset to the sysop default using the Reset button on their own Customize modal.

**System News** — Edit the MOTD displayed in the System News card from **Admin → Appearance & Content → Content → System News**. The field accepts Markdown.

**System Information extra statistics** — The "Extra statistics in System Information card" setting on **Admin → Appearance & Content → Dashboard** adds registered users, today's callers, system uptime, file area file count, total logins, and total echomail messages to the System Information card. Choose **Off** to hide them, **Sysop Only** (default) to show them only to admins, or **All Users** to show them to everyone.
