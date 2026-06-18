# MyBB Migrator (`ramon/mybb-migrator`)

A faithful, **ID-preserving** migrator from **MyBB** (with the *DVZ Hash* password
plugin) to **Flarum 2**. It is a Flarum extension that ships a suite of
`php flarum mybb:*` console commands. Unlike a generic importer, it keeps the
original primary keys (`uid -> users.id`, `tid -> discussions.id`, `pid -> posts.id`,
`fid -> tags.id`, custom `gid -> groups.id`) so every cross-reference (likes,
mentions, quotes, polls, PMs, trader feedback) lands on the right row.

What it migrates:

- **Users** (IDs preserved) + **original passwords kept working** (MyBB classic
  and DVZ Hash / bcrypt), transparently upgraded to Flarum bcrypt on first login.
- **Groups** (custom groups, IDs preserved) and **role mapping** (MyBB Admin/Mod ->
  Flarum Admin/Mod, Banned -> suspended, Awaiting Activation -> unconfirmed e-mail).
- **Forums -> Tags** (hierarchy preserved) and **per-forum view restrictions**.
- **Threads -> Discussions** and **Posts -> Posts**, with **BBCode -> Flarum**
  conversion (s9e), mojibake repair, sticky/locked/soft-deleted flags, and
  extraction of user/post **mentions**.
- **Likes**, **Subscriptions**, **Private Messages** (-> `fof/byobu`),
  **Polls** (-> `fof/polls`), **iTrader feedback** and **Community Reviews**
  (-> `huseyinfiliz/traderfeedback`), **avatars** (URL backfill), **signatures**.

---

## 1. Requirements

| Requirement | Notes |
|---|---|
| PHP `^8.3` | with `ext-pdo` (MySQL) enabled — used to read the MyBB DB |
| Flarum `^2.0` | target install |
| MySQL/MariaDB | both the MyBB source DB and the Flarum DB |
| CLI access | the migration is run entirely through `php flarum mybb:*` |

### Target Flarum extensions

These must be installed **and enabled** *before* migrating, because the commands
write directly into their tables:

| Extension | Used for |
|---|---|
| `flarum/tags` | forums -> tags (**required** — content depends on tags) |
| `flarum/likes` | `mybb:likes` |
| `flarum/mentions` | user/post mentions extracted by `mybb:content` |
| `flarum/subscriptions` | `mybb:subscriptions` |
| `flarum/suspend` | banned users -> suspended |
| `flarum/sticky` | sticky threads -> `is_sticky` |
| `flarum/lock` | closed threads -> `is_locked` |
| `flarum/approval` | soft-deleted / unapproved state |
| `flarum/bbcode` + `flarum/markdown` | rendering of converted BBCode |
| `fof/byobu` | private messages |
| `fof/polls` | polls + votes |
| `fof/upload` | attachments / uploaded files |
| `huseyinfiliz/traderfeedback` | iTrader + Community Reviews |
| `michaelbelgium/mybb-to-flarum` | **optional companion** — see below |

### About `michaelbelgium/mybb-to-flarum`

You do **not** need it at all. This extension has **no admin settings page** of
its own — the MyBB database credentials are supplied **via CLI flags** and the
extension stores them itself (see *Configuration* below). All data logic lives in
this extension's own `mybb:*` commands.

The companion extension is only useful for **copying the physical files**
(avatars / attachments) into `public/assets/...`, since this migrator only
backfills `users.avatar_url` and references attachments — it does not move files.
If you copy those files manually, you can skip the companion entirely.

> Note on settings keys: the credentials are stored under the keys
> `mybb_host`, `mybb_port`, `mybb_user`, `mybb_password`, `mybb_db`,
> `mybb_prefix`. These happen to be the same keys the companion extension uses,
> so if it *is* installed, filling its admin page is just an alternative way to
> set the same values. The values are written by **this** extension's commands
> regardless — no companion required.

---

## 2. Installation

```bash
# from the Flarum root
composer require ramon/mybb-migrator
php flarum migrate            # creates the mybb_legacy_passwords table
php flarum cache:clear
```

Enable the extension in the Admin panel. Make sure all *target extensions* above
are installed and enabled first.

---

## 2.1. Quick start (full command sequence)

Copy-paste, **edit the credentials on the first line**, and run from the Flarum
root (`d:\laragon\www\flarum`). Only the first command carries the connection
flags — every later command reuses them from `settings`.

```powershell
# 1) FIRST command sets + stores the MyBB DB connection (edit these values!)
php flarum mybb:groups --force --host=127.0.0.1 --port=3306 -u root -p YOUR_PASSWORD -d your_mybb_db --prefix=dfsmybb_

# Phase 1 — core (order matters)
php flarum mybb:users        --force
php flarum mybb:avatars      --force
php flarum mybb:tags         --force
php flarum mybb:content      --force
php flarum mybb:likes        --force
php flarum mybb:permissions  --force
php flarum mybb:forum-perms  --force

# Phase 2 — secondary content
php flarum mybb:subscriptions  --force
php flarum mybb:messages       --force
php flarum mybb:polls          --force
php flarum mybb:trade-feedback --force
php flarum mybb:reviews        --force
php flarum mybb:make-admin --username YOUR_USERNAME --force

# rebuild caches / search index
php flarum cache:clear
```

> **Tip — stop on first error.** Wrap Phases 1–2 in a loop so a failure halts the
> whole run (each step depends on the previous one):
>
> ```powershell
> php flarum mybb:groups --force --host=127.0.0.1 --port=3306 -u root -p YOUR_PASSWORD -d your_mybb_db --prefix=dfsmybb_
> $steps = @(
>   'mybb:users','mybb:avatars','mybb:tags','mybb:content','mybb:likes',
>   'mybb:permissions','mybb:forum-perms','mybb:subscriptions','mybb:messages',
>   'mybb:polls','mybb:trade-feedback','mybb:reviews'
> )
> foreach ($s in $steps) {
>   Write-Host "==> php flarum $s --force" -ForegroundColor Cyan
>   php flarum $s --force
>   if ($LASTEXITCODE -ne 0) { Write-Host "FAILED at $s — stopping." -ForegroundColor Red; break }
> }
> ```

Then run the **Phase 3** clean-up passes you need (see §4). Linux/macOS users:
replace `^`/backtick line breaks with `\` and run under `bash`.

---

## 3. Configuration (MyBB database connection)

This extension has **no admin UI**. You point it at the MyBB database entirely
through CLI flags. Each command resolves the connection in this priority order:

1. the CLI flag you pass (`--host`, `-u`, `-p`, ...);
2. otherwise the value previously stored in Flarum `settings`;
3. otherwise a built-in default.

After resolving, **it writes the values back to `settings`** — so you pass the
flags **once** (on the very first command) and every later command only needs
`--force`. No settings page, no companion extension required.

Every command shares these options:

```text
--host        MyBB DB host        (default 127.0.0.1)
--port        MyBB DB port        (default 3306)
-u, --user    MyBB DB user        (default root)
-p, --password MyBB DB password   (default empty)
-d, --db      MyBB DB name         (default mybb)
--prefix      MyBB table prefix    (default mybb_)
```

> In this project the live MyBB tables used the prefix **`dfsmybb_`**.

Example (first command sets and stores the connection):

```bash
php flarum mybb:groups --force \
  --host=127.0.0.1 --port=3306 -u root -p secret -d mybb_old --prefix=dfsmybb_
```

After that, later commands can be run with just `--force`.

---

## 4. Migration order

> All write commands require `--force`. Most support `--dry-run` to preview.
> Run from the Flarum root: `php flarum <command>`.

### Recommended order at a glance

The order below is **not arbitrary** — it is the exact sequence enforced by the
ID/foreign-key dependencies between MyBB and Flarum. Run the phases top to bottom.

| Phase | # | Command | What it does |
|---|---|---|---|
| **0** (optional) | — | `mybb:wipe` | clears Flarum content for a clean re-run |
| **1 — Core** | 1 | `mybb:groups` | custom groups (gid≥8), IDs preserved |
| | 2 | `mybb:users` | users (uid=id), captures passwords, maps groups |
| | 3 | `mybb:avatars` | backfills `users.avatar_url` |
| | 4 | `mybb:tags` | forums → tags (fid=id) + hierarchy |
| | 5 | `mybb:content` | threads → discussions (tid=id), posts (pid=id), BBCode→Flarum, mentions |
| | 6 | `mybb:likes` | post likes |
| | 7 | `mybb:permissions` | default + custom-group permissions |
| | 8 | `mybb:forum-perms` | per-forum view restrictions → tag perms |
| **2 — Secondary** | 9 | `mybb:subscriptions` | thread/forum follows |
| | 10 | `mybb:messages` | private messages (`fof/byobu`) |
| | 11 | `mybb:polls` | polls + votes (`fof/polls`) |
| | 12 | `mybb:trade-feedback` | iTrader (`traderfeedback`) |
| | 13 | `mybb:reviews` | Community Reviews (`traderfeedback`) |
| | 14 | `mybb:make-admin` | promote your own account to Admin |
| **3 — Cleanup** | — | `mybb:fix-*` / `mybb:revert-*` | data-specific fidelity passes — run only what you need |

> **⚠️ Golden rule:** if any command fails, **stop and fix it before continuing** —
> each step depends on the previous one. Always **back up the Flarum database**
> before you start, and use `--dry-run` first wherever it is available.

**Before you start, confirm:**

- [ ] Flarum DB **backed up**.
- [ ] All *target extensions* (§1) installed **and enabled**.
- [ ] `php flarum migrate` run (creates `mybb_legacy_passwords`).
- [ ] MyBB DB credentials known (host/port/user/password/db/**prefix**).

### Phase 0 — Preparation

```bash
# OPTIONAL: wipe existing Flarum content for a clean re-run
# (keeps schema, core groups and settings)
php flarum mybb:wipe --force
```

### Phase 1 — Core data (order matters — IDs/foreign keys depend on it)

```bash
php flarum mybb:groups   --force      # 1. custom groups (gid>=8), IDs preserved
php flarum mybb:users    --force      # 2. users (uid=id), legacy passwords captured, groups mapped
php flarum mybb:avatars  --force      # 3. backfill users.avatar_url (files copied separately)
php flarum mybb:tags     --force      # 4. forums -> tags (fid=id) + hierarchy
php flarum mybb:content  --force      # 5. threads -> discussions (tid=id), posts -> posts (pid=id),
                                      #    BBCode->Flarum, mentions, sticky/locked/soft-deleted
php flarum mybb:likes        --force  # 6. post likes
php flarum mybb:permissions  --force  # 7. default + custom-group permissions
php flarum mybb:forum-perms  --force  # 8. per-forum view restrictions -> tag perms
```

Why this order:

- **groups before users** — users get attached to custom groups by ID.
- **users before content** — posts/discussions reference `user_id`.
- **tags before content** — discussions are attached to tags (`discussion_tag`).
- **content before likes/mentions/quotes** — those reference `posts.id` /
  `discussions.id`, which are only correct because content preserves `pid`/`tid`.

### Phase 2 — Secondary content (after users + content exist)

```bash
php flarum mybb:subscriptions --force   # thread/forum follows -> flarum/subscriptions
php flarum mybb:messages      --force   # private messages -> fof/byobu private discussions
php flarum mybb:polls         --force   # polls + votes -> fof/polls
php flarum mybb:trade-feedback --force  # iTrader feedback -> traderfeedback
php flarum mybb:reviews       --force   # Community Reviews -> traderfeedback
php flarum mybb:make-admin --username ramon --force   # promote your own account to Admin
```

### Phase 3 — Content clean-up / fidelity passes (run as needed)

These are **idempotent fix-up passes** over already-migrated content. They were
created to repair specific artifacts found in this forum's data (Tapatalk emoji,
mojibake, literal BBCode that didn't parse, quote/mention styling, signatures).
Run only the ones you need; safe to re-run.

> **Do not run them all blindly.** They are specific to your dataset and some are
> *opposites* of each other (e.g. `restore-quote-mentions` ↔ `revert-quote-mentions`,
> `fix-quotes` ↔ `compact-quotes`) — running everything would undo itself. Migrate
> Phases 1–2 first, look at the live forum, then apply only the passes you actually
> need. The most commonly needed ones are `fix-charset`, `fix-smilies`,
> `fix-emojis`, `normalize-bbcode`, `fix-user-mentions` and `fix-signatures`.

```bash
php flarum mybb:fix-charset        --force   # repair mojibake in posts/titles
php flarum mybb:fix-smilies        --force   # textual smilies (:rolleyes:) -> Unicode
                                             # (posts, discussion titles AND signatures)
php flarum mybb:fix-emojis         --force   # [emojiN] (Tapatalk) -> Unicode
php flarum mybb:fix-tapatalk-emoji --force   # re-fix mis-mapped Tapatalk emoji

php flarum mybb:normalize-bbcode   --force   # re-parse size/font/align/hr/php
php flarum mybb:fix-size-bbcode    --force   # literal [size=X] -> <SIZE>
php flarum mybb:fix-font-bbcode    --force   # strip literal [font=...]
php flarum mybb:strip-orphan-bbcode --force  # remove orphan literal BBCode markers
php flarum mybb:rebuild-formatting  --force  # re-derive markdown-broken posts from source:
                                             #  - inline BBCode split across blank lines
                                             #    (orphan [/b][/size], lost colors)
                                             #  - TAB / 4-space lines -> accidental code box
                                             #  - `#` / setext (--, ==) -> accidental heading
                                             #  - \r\r\n -> doubled spacing
                                             # Re-parses from source, so re-run the Phase-3
                                             # content passes you use AFTER it (see note below).
php flarum mybb:revert-md-strike-sub --force # undo ~~/~ markdown that were MyBB separators
php flarum mybb:revert-ispoiler     --force  # <ISPOILER> -> literal ||text||

php flarum mybb:fix-quotes          --force  # inject POSTMENTION into migrated quotes
php flarum mybb:restore-quote-mentions --force
php flarum mybb:revert-quote-mentions  --force
php flarum mybb:compact-quotes      --force  # compact quote style (POSTMENTION only)
php flarum mybb:fix-user-mentions   --force  # @username text -> clickable USERMENTION
php flarum mybb:fix-mention-slugs    --force # add slug attr to existing USERMENTIONs

php flarum mybb:fix-signatures      --force  # clean users.bio (signatures)
php flarum mybb:reimport-signatures  --force # re-import signatures BBCode -> s9e XML
php flarum mybb:fix-usernames        --force # remove invalid chars from usernames
php flarum mybb:apply-nicknames      --force # old_username -> nickname + kebab slug

php flarum mybb:fix-pm-parse         --force # re-parse PM bodies left as raw BBCode
php flarum mybb:recover-protected    --force # rebuild posts with literal PROTECTED_N
```

> **About `mybb:rebuild-formatting`.** Because it re-reads each affected post from
> the MyBB source and re-parses it, it overwrites `posts.content` and therefore
> drops any earlier Phase-3 edits on *those* posts (smilies, mentions, quote
> styling, strike/spoiler reverts). After running it, re-run the idempotent
> Phase-3 passes you use — typically `fix-smilies`, `fix-quotes` (or your chosen
> quote-style pass), `fix-user-mentions`, `fix-mention-slugs`, and any
> `revert-md-strike-sub` / `revert-ispoiler` — to restore them. The converter
> itself is now fixed, so a *fresh* `mybb:content` migration no longer produces
> these markdown artifacts in the first place.

### Helpers / diagnostics

```bash
php flarum mybb:test-credentials --force   # generate test login pairs (1 per hash algorithm)
php flarum mybb:test-bio-render             # render a users.bio and print resulting HTML
```

---

## 5. How passwords keep working

MyBB stores either the **classic** hash `md5(md5(salt) . md5(password))` or, with
the *DVZ Hash* plugin, a **bcrypt** hash (`$2y$...`, sometimes bcrypt over the
classic md5).

1. `mybb:users` copies each original hash/salt/algorithm into the companion table
   **`mybb_legacy_passwords`** (created by this extension's migration).
2. A custom Flarum **password checker** (`mybb-legacy`, see
   `src/Auth/MybbPasswordChecker.php`) intercepts logins:
   - if a legacy row exists, it verifies the password the *MyBB* way
     (`src/Support/MybbPassword.php`);
   - on success it **re-hashes to Flarum bcrypt**, saves, and **deletes** the
     legacy row — so each user is upgraded transparently on first login.

No password resets, no e-mails — users log in with their existing credentials.

---

## 6. Architecture

```text
src/
  Auth/MybbPasswordChecker.php     # legacy login + transparent bcrypt upgrade
  Support/MybbPassword.php         # MyBB classic + DVZ Hash verification
  Support/Charset.php              # mojibake / charset repair
  Support/TapatalkEmoji.php        # Tapatalk emoji -> Unicode map
  BBCode/Converter.php             # MyBB BBCode -> Flarum (s9e) conversion
  MybbDatabase.php                 # buffered/unbuffered PDO reader for the MyBB DB
  LegacyPassword.php               # Eloquent model for mybb_legacy_passwords
  Console/                         # all mybb:* commands (see migration order)
migrations/
  2026_05_29_100001_create_mybb_legacy_passwords_table.php
extend.php                         # registers the password checker + all commands
```

Design notes:

- Reads MyBB with a **buffered** cursor for small sets and an **unbuffered**
  cursor (`MybbDatabase::cursor()`) for large tables to keep memory flat.
- Writes in **batches** (200–2000 rows) with `FOREIGN_KEY_CHECKS=0` around bulk
  inserts.
- `mybb:content` self-cleans the Flarum content tables before re-importing, so it
  can be re-run safely.

---

## 7. Caveats

- The fix-up passes in Phase 3 are **data-specific**: they target artifacts seen
  in this particular forum (Tapatalk, DVZ Hash, double-UTF-8 mojibake). Review
  each before running on a different dataset; always try `--dry-run` first where
  available, and back up the Flarum DB.
- Avatar/attachment **files** are not copied by this extension — use
  `michaelbelgium/mybb-to-flarum` or copy them manually into `public/assets/`.
- `mybb:wipe` is destructive (clears Flarum content). It keeps schema, core
  groups and settings, but use it only on a throw-away/staging install.

---

## 8. License

MIT.
