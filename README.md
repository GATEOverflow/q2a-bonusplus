# q2a Bonus Plus

This plugin originally developed by Yhlas Sovbetov. GATEOVERFLOW upgraded this plugin.

A Question2Answer (Q2A) plugin that lets authorised users give **bonus points** to members, with a reason, and keeps a **history** of every bonus. Members can see their own bonus total and history, and can be **notified** when they receive or lose points.

- Give or deduct points from the user's profile page, in a responsive modal (dark mode supported)
- Reasons are managed by the admin, no hard-coded list
- Each reason has a default direction (add or deduct), so you only type a positive number
- Bonus history with totals, per-reason breakdown and "given by" (for givers only)
- Optional notifications through the FNS notification plugin
- Safe to upgrade: the database migration checks what already exists

---

## Requirements

- Question2Answer with a MySQL / MariaDB database (InnoDB and utf8mb4 support)
- A theme whose profile page uses the standard Q2A profile form (tested on **Polaris**)
- *Optional:* the FNS notification plugin (`PUPI_FNS_*`) for notifications

## Installation

1. Copy the plugin folder into `qa-plugin/` on your site.
2. Log in as admin, open **Admin → Plugins → Bonus Plus**, and check the settings.
3. Open **Admin → Plugins → Bonus Plus** again and set the default direction (Add / Deduct) for each reason.

If you update the plugin later, bump the `$version` number in the layer file so browsers load the new `script.js` and CSS files instead of cached copies.

---

## How it works

### Giving a bonus

1. A bonus giver opens a member's profile.
2. Next to **Bonus points** there are two buttons: **Give bonus** and **Bonus history**.
3. In the modal the giver:
   - types an **amount**, a plain positive whole number (for example `100`),
   - picks a **reason**,
   - checks the **type**, **Add (+)** or **Deduct (−)**. It is preset from the reason's default and can be switched for this one bonus.
4. A preview shows the result (for example `+100` or `−50`). After sending, the page reloads and the new total is shown.

### Reasons

- Admins manage reasons in **Admin → Plugins → Bonus Plus**: rename, set the default direction, enable or disable, or add new ones.
- Reasons are **disabled, not deleted**, so old history keeps its text.
- A giver can choose **Other…** and type a new reason. It is saved once and offered next time. If the text already exists (compared as stored), the existing reason is reused and no duplicate is created. The new reason takes the direction chosen at that moment as its default.

### Who can do what

| Person | Give bonus | See history |
|---|---|---|
| User at or above the configured level (default: Administrator) | Yes, to any member | Yes, on any profile, including the **Given by** column |
| Other members | No | Only on their own profile, without "Given by" |
| Self-bonus | Admins only (no notification is sent for it) | - |

### Limits

The plugin settings have a **Minimum** and **Maximum** amount (defaults −1000 and 1000):

- an **Add** bonus can be up to the maximum,
- a **Deduct** bonus can be up to the absolute value of the minimum.

### Where the buttons appear

- **Admins** (who have Q2A's own Bonus points row): the value is shown as plain text, with the buttons beside it.
- **Members and other givers:** the plugin adds its own **Bonus points** row to the profile form, right after the normal Points row (or at the end of the form if there isn't one).

---

## Notifications (optional)

If the FNS notification plugin is active, the receiver gets a notification when a bonus is issued.

| Event id | Meaning | Icon class |
|---|---|---|
| 0 | Bonus received | `bp-fns-icon-plus` (green ring with +) |
| 1 | Bonus deducted | `bp-fns-icon-minus` (red ring with −) |

- Plugin id: `Bonus_PLUS`. Each notification shows the amount, the reason as text, and links to the receiver's profile.
- The giver's identity is **not** included, so members cannot see which admin gave the bonus.
- Icons are drawn from `bonusplus-icons.css` (SVG masks, no font needed). That CSS loads on every page for logged-in users, because the notification bell is everywhere.
- `BonusPlus_FNSExtension` must be registered the same way as any other FNS extension, and loaded only when the FNS plugin exists, because it implements the FNS interface.
- Without the FNS plugin everything else works, and no notification is sent.

---

## Files

| File | Purpose |
|---|---|
| `qa-plugin.php` | Plugin registration. Also holds the shared helper functions (`q2a_bp_lang`, `q2a_bp_get_reasons`, `q2a_bp_find_or_create_reason`, `q2a_save_bonusreasons`, history queries and so on) |
| `q2a-bonusplus-admin.php` | Admin settings, reasons manager, database creation and migration |
| `q2a-bonusplus-layer.php` | Theme layer: profile row, buttons, both modals, loads scripts and CSS |
| `q2a-bonusplus-page.php` | `ajaxbonus` endpoint: gives a bonus, returns history, sends the notification |
| `q2a-bonusplus-fns.php` | Notification extension (FNS) |
| `script.js` | Modal behaviour: opening, validation, sending, history loading |
| `bonusplus.css` | Modal and button styles, with dark mode |
| `bonusplus-icons.css` | Notification icons |
| language file | Texts, registered under the `q2a_bonusplus_lang` prefix |

Example registration in `qa-plugin.php`. Adjust the file names and labels to match your plugin:

```php
qa_register_plugin_module('module', 'q2a-bonusplus-admin.php', 'q2a_bonusplus_admin', 'Bonus Plus Admin');
qa_register_plugin_module('page', 'q2a-bonusplus-page.php', 'q2a_bonusplus_page', 'Bonus Plus Ajax');
qa_register_plugin_layer('q2a-bonusplus-layer.php', 'Bonus Plus Layer');
qa_register_plugin_phrases('q2a-bonusplus-lang-*.php', 'q2a_bonusplus_lang');
```

---

## Database

Two tables (names are prefixed with your Q2A table prefix):

**`bonusplus`**: one row per bonus

| Column | Meaning |
|---|---|
| `bonusid` | Primary key |
| `bonuserid` | User who gave the bonus |
| `receiverid` | User who received it |
| `amount` | Signed amount (negative = deduction) |
| `reasonid` | Reason (see below) |
| `bonustime` | Time given, stored in GMT |

**`bonusplus_reasons`**: the list of reasons

| Column | Meaning |
|---|---|
| `reasonid` | Primary key. Ids 1–8 are the default reasons |
| `title` | Reason text (unique) |
| `active` | 1 = shown in the list, 0 = hidden |
| `effect` | Default direction: `1` add, `-1` deduct, `0` not decided yet (treated as add) |
| `created` | When it was created |

Both tables use InnoDB with `utf8mb4`.

### Migration

The upgrade runs from `init_queries()` and is safe on any starting state:

- **Table missing:** it is created with the current structure.
- **Table on MyISAM or the old `utf8` charset:** converted to InnoDB / utf8mb4 only if it differs.
- **`effect` column missing:** added. Existing reasons are then given a direction from their history (mostly negative amounts become *Deduct*, everything else *Add*). Only rows still at `0` are touched, so values you set are never overwritten.
- **Already up to date:** nothing is queued.

> `utf8mb4_unicode_ci` compares text case-insensitively, so "Late" and "late" are considered the same. If you prefer case-sensitive reasons, change the `$collation` property in `q2a-bonusplus-admin.php` to `utf8mb4_bin` before upgrading.

---

## Security

- The giver is always the logged-in user, never taken from the browser.
- Every request needs the form security code generated for the page.
- Giving a bonus is re-checked on the server against the configured level, with amount limits and a valid, active reason.
- History is checked on the server too: a member can only read their own.
- All output is escaped.
