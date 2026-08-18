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

### Media — remote images & attachments (opt-in, budgeted)

Migrated posts still load their images from wherever they were hosted (imgur,
the old domain, a dead image host). These two steps bring the files onto your
own server — into `public/assets/files`, the folder fof/upload's local adapter
uses — and repoint the posts at the local copies.

They are **outside** every guided sequence (`Run everything` never triggers
them): they hit the network and consume disk, so they are always an explicit
decision, taken with a limit, after eyeballing a sample.

**Nothing here has to be typed in by hand.** With the settings still empty, the
panel auto-detects on first open (and the commands do the same when run from the
CLI):

- **which hosts to localize** — the posts already migrated are scanned and their
  image hosts ranked by usage. The busy ones are applied; the long tail is listed
  on screen marked as left out, never dropped silently.
- **where MyBB's `uploads` folder is** — candidates near the Flarum install are
  probed and each one is *proven* against real `attachname` rows before being
  accepted. A guess that can't be proven is not used.

```bash
# Just one discussion — paste the URL straight from the browser
php flarum mybb:images --force --discussion=https://example.com/d/1661-some-thread

# See what would happen — no downloads, no writes
php flarum mybb:images --force --dry-run --hosts=i.imgur.com --limit=20

# Localize 20 images and stop, so you can look at the forum first
php flarum mybb:images --force --hosts=i.imgur.com --limit=20

# Happy with the result? Lift the budget
php flarum mybb:images --force --hosts=i.imgur.com,damnfineshave.com --limit=0 --max-mb=0

# Attachments: copy straight off the MyBB uploads folder (preferred)
php flarum mybb:attachments --force --uploads-dir=/var/www/mybb/uploads --limit=10
```

| Option | Meaning |
| --- | --- |
| `--hosts=a,b` | Hosts (or full URL prefixes) to localize. A bare host also matches its subdomains. Reads the panel setting when omitted. |
| `--all-hosts` | Localize every external image, ignoring the filter. |
| `--limit=N` | Max **new** URLs attempted this run — downloaded *or* failed. `0` = no cap. |
| `--max-mb=N` | Total download budget for the run. `0` = no cap. |
| `--max-file-mb=N` | Per-file cap; the download aborts mid-stream when exceeded. |
| `--discussion=X` | Only this discussion — accepts an id, a slug or a full Flarum discussion URL. Implies no per-run cap (the discussion *is* the scope) and gives the panel an exact progress percentage. |
| `--posts=N`, `--from-id=N` | Narrow the scan window. |
| `--dry-run` | Report only. |
| `--retry-failed` | Try URLs previously recorded as dead again. |
| `--relink-only` | No network at all: only re-apply URLs already downloaded. |
| `--uploads-dir=PATH` | *(attachments)* Copy from the MyBB `uploads` folder instead of downloading. |
| `--include-hidden` | *(attachments)* Also take attachments still pending approval. |

What makes re-running safe:

- Every URL processed is recorded in **`mybb_migrated_images`**. Successful ones
  are re-pointed without touching the network — that is the "skip images already
  populated" behaviour. Dead ones are remembered as `failed` and are not retried
  unless you ask.
- Local filenames are a hash of the source URL, so the same remote image always
  maps to the same local file; nothing is ever downloaded twice.
- After `mybb:rebuild-formatting` (which re-derives posts from MyBB and brings
  the remote URLs back), run `php flarum mybb:images --force --relink-only` to
  re-apply the whole map in seconds.

Dead-image handling worth knowing about:

- Redirects are followed, but the **final response is validated**: if it is HTML,
  the file is gone. `i.imgur.com/<id>.jpg` redirects to the `imgur.com/<id>` page
  when the stored object is actually a PNG, so the fetcher retries the other
  extensions of the same id before giving up.
- imgur's `removed.png` placeholder is treated as a failure rather than saved as
  a grey "image removed" tile.
- The MIME type comes from the **bytes** (finfo / magic numbers), never from the
  URL extension or the declared `Content-Type`.

#### Restricted tags: files are written outside the document root, not moved later

`public/assets/files` is served by the web server without PHP ever running, so
while a file sits there **no permission of any kind applies to it**. For a forum
imported with restricted tags that matters: the images of a private area would be
readable by anyone holding the URL.

`ramon/dfs` solves this by keeping tag-scoped uploads in `storage/dfs-private-uploads`
and serving them through a permission-checked route. This extension does **not**
reimplement any of that — it just makes sure the bytes never land on the wrong
side to begin with:

- before writing, the discussion is checked with Flarum's own
  `Discussion::whereVisibleTo(new Guest())` — the same call `ramon/dfs` makes, not
  a second reading of the tag rows;
- if a guest cannot see it, the file is written straight into the private store and
  registered in `dfs_private_uploads`;
- at the end of the step, every touched file is handed to `ramon/dfs` for
  reclassification, which is what settles the case of one image appearing in both
  an open and a restricted discussion (its rule is the least restrictive one).

Without `ramon/dfs` installed all of this switches off and files are written
publicly, which is the only possible destination in that case.

The URL frozen into the post is `/assets/files/…` either way — that is precisely
what `GatePrivateUploads` looks for when it rewrites a render to the gated route.
Only the location of the bytes differs.

`fof/upload` is optional: without it the files are still downloaded and the posts
do point at the local copies — they just won't appear in the media manager. With
it installed, each file is also registered in `fof_upload_files` and linked to
its post. The insert is schema-introspective, so it works across fof/upload
versions (which have moved columns around several times).

Attachments are **appended to the end of the post** — the `[attachment=N]` tokens
were dropped during content migration, and MyBB itself renders non-inlined
attachments at the bottom. The post is rewritten through unparse → text → parse,
the same path Flarum uses when a post is edited, so the resulting XML is always
valid.

All of this is also driven from the **Images & attachments** tab of the
extension's admin page. The tab opens with the URLs to localize and the uploads
folder already filled in by auto-detection, and adds a **"Localize this
discussion"** box: paste a discussion URL and only its images are fetched. Both
steps run detached in the background, reporting a **live progress bar** —
a real percentage when the total is cheap to know (a single discussion, the
attachment table), and an indeterminate bar with the running count otherwise.
Counting 273k posts up front just to draw a percentage would cost more than the
work itself, so the bar says "unknown" instead of inventing one.

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
php flarum mybb:fix-spacing         --force  # restore faithful MyBB nl2br spacing on ALREADY
                                             # migrated posts. MyBB renders every newline as a
                                             # <br> and never collapses blank lines; litedown
                                             # merges consecutive blank lines into one paragraph
                                             # break, so migrated posts lost vertical spacing.
                                             # Re-derives affected posts from source with the
                                             # fixed Converter (blank lines -> invisible U+200B
                                             # markers -> <br>). Try --dry-run first; re-parses
                                             # from source, so re-run the Phase-3 content passes
                                             # you use AFTER it (see note below).
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
  Support/ImageFetcher.php         # remote download: redirects, imgur fallbacks, size caps
  Support/ImageStore.php           # writes to the right side + registers in fof_upload_files
  Support/PrivateUploadBridge.php  # guest-visibility check before writing (ramon/dfs, optional)
  Support/UploadVisibilityBridge.php # end-of-step reclassification handoff to ramon/dfs
  Gui/MediaDetector.php            # ranks image hosts from the posts; finds & proves MyBB's uploads dir
  BBCode/Converter.php             # MyBB BBCode -> Flarum (s9e) conversion
  MybbDatabase.php                 # buffered/unbuffered PDO reader for the MyBB DB
  LegacyPassword.php               # Eloquent model for mybb_legacy_passwords
  Console/                         # all mybb:* commands (see migration order)
migrations/
  2026_05_29_100001_create_mybb_legacy_passwords_table.php
  2026_08_17_100001_create_mybb_migrated_images_table.php   # remote URL -> local file map
  2026_08_17_100002_add_progress_to_mybb_migration_steps.php # live progress for the panel's bar
extend.php                         # registers the password checker + all commands
```

Design notes:

- Reads MyBB with a **buffered** cursor for small sets and an **unbuffered**
  cursor (`MybbDatabase::cursor()`) for large tables to keep memory flat.
- Writes in **batches** (200–2000 rows) with `FOREIGN_KEY_CHECKS=0` around bulk
  inserts.
- `mybb:content` self-cleans the Flarum content tables before re-importing, so it
  can be re-run safely.
- The admin panel paints from a **cheap status call** (~10 ms) and loads the
  source/target counts afterwards: those are `COUNT(*)` over large InnoDB tables
  (~13 s on the reference forum) and used to hold the whole page hostage. They
  are cached for `mybb-migrator.counts_ttl` seconds (default 300) and recomputed
  on demand — automatically once a step finishes, or via the **Recount** button.

---

## 7. Caveats

- The fix-up passes in Phase 3 are **data-specific**: they target artifacts seen
  in this particular forum (Tapatalk, DVZ Hash, double-UTF-8 mojibake). Review
  each before running on a different dataset; always try `--dry-run` first where
  available, and back up the Flarum DB.
- **Avatar** files are not copied by this extension (`mybb:avatars` only points
  `users.avatar_url` at files you already placed in `public/assets/avatars`).
  Post **images and attachments** *are* handled — see the media section above —
  but only when you run those steps explicitly, with a budget.
- `mybb:wipe` is destructive (clears Flarum content). It keeps schema, core
  groups and settings, but use it only on a throw-away/staging install.

---

## 8. License

MIT.
