# RSCD Community Website

**[rscd-community.org](https://rscd-community.org)** — the community site for
RuneScape Classic worlds: account manager, forums, hiscores, beastiary, the
Play Game world list, and the original 2003 game manual.

The 2003 look, with the account management the 2003 site never had. Password
resets, email verification, single sign-on across the site and the forums, and
no third-party JavaScript on any public page.

---

## What is in it

- **Account manager** — register, sign in, verify email, reset passwords, and
  manage your game characters: stats, inventory, bank, quest progress.
- **Forums** — boards with BBCode-style formatting, sharing one login with the
  account manager.
- **Play Game** — the live community world list. Join instantly in the
  browser, nothing to install, or use `rscd://` join links to open the
  downloadable
  [client](https://github.com/RSCD-Community/rscd-community-client) for
  scripting support.
- **Hiscores** and a **Beastiary** generated from the game's own definition
  data, with sprites rendered from the world's cache.
- **The 2003 manual**, rebuilt from archived pages.
- **Admin console** — users, roles, access policies, files, events, tags.

## Run it

You need **PHP 8.1+**, **Composer**, and **MySQL**.

```sh
git clone https://github.com/RSCD-Community/rscd-www.git
cd rscd-www
./run.sh          # Windows: run.bat
```

`run.sh` checks PHP is there and new enough, runs `composer install` if
`vendor/` is missing, copies `app/app.json.example` into place if you have no
config yet, and serves the site on `http://localhost:8080`. Anything missing is
reported in plain words with where to get it. The site starts either way, but
until `app/app.json` holds your real database settings, signing in and
everything else that touches the database will fail.

**The database schema ships with the game server**: `rscd.sql` in
[`rscd-server`](https://github.com/RSCD-Community/rscd-server) — one schema
shared by the world and the site, and that repository's README covers importing
it and granting your first account admin (`role_id 1` in `user_role`). Its
guided `install.sh` sets all of it up for you.

For production, serve `index.php` through Apache with `app/app.htaccess`
semantics — the two vhost files in this repository do exactly that.

Every `CHANGE-ME` value in the template must actually be changed: `salt` and
`encrypter.key` are 32 random characters, `encrypter.iv` is 16 — generate them
once (`openssl rand -base64 24 | cut -c1-32`) and leave them alone afterwards;
session cookies are encrypted with the encrypter key, so changing it signs
every browser out. The full key list is readable in `src/RSCD/Model/App.php`
and `AppBase.php`.

### Stack

- **PHP 8.1+**, Eloquent ORM (`illuminate/database`), MySQL
- **PHPMailer** over SMTP
- Namespace `RSCD\`; routing from `app/routes/app.json`; templates are plain
  HTML with `[{variable}]` injection
- **No CSS or JavaScript framework.** The stylesheet is hand-written.

### Structure

```
app/            # config (app.json), routes, email templates, tmp
src/RSCD/
  Controller/   # MVC controllers (public, Auth, Admin)
  Model/        # App bootstrap, State, Authenticator, Eloquent models
  View/         # page assembly (public + admin)
  Util/         # small dependency-free helpers
ui/             # html layouts, css, images
```

### Serving a world's client assets

If this host also serves a world's assets, populate `cache_data/` with the
server's copy — see [`CACHE_DATA.md`](CACHE_DATA.md) for every
option (symlink, junction, copy, rsync) across Linux, macOS and Windows.
Assets belong to the world, not to the site or the client: the server ships
them, the web host serves them, and the client downloads them from whatever
`cache_url` the world advertises.

## Deployment

Two vhost files ship with this repository, plus the certificate script they
depend on:

| File | What it is |
|---|---|
| `rscd-community.org.conf` | The `:80` vhost. Serves the ACME challenge, redirects everything else to HTTPS. |
| `rscd-community.org-ssl.conf` | The `:443` vhost. **Copy it, but do not enable it by hand** — its certificate paths do not exist until first issuance, and Apache refuses to start on a missing `SSLCertificateFile`. |
| `rscd-letsencrypt.sh` | Issues on first run, renews after that, and enables the `:443` vhost and the redirect once there is a certificate to point at. Install to a scripts directory and run twice a day from cron. |

The numeric prefixes in the install commands are not decoration. Apache treats
the **first** vhost it loads as the default for any `Host:` it does not
recognise, so the site must sort ahead of anything else on the box.

**HTTP → HTTPS is off until a certificate exists.** The redirect lives in one
file, `/etc/apache2/rscd-redirect/redirect.conf`, written by the script and
pulled into each `:80` vhost with `IncludeOptional`. Deleting that one file and
reloading Apache is the whole way back to plain HTTP, which matters on the day a
certificate goes bad. It preserves path and query string, and it exempts
`/.well-known/acme-challenge/` — forget that exemption and renewals fail sixty
days later with nobody watching.

Issuance needs **port 80 reachable from the internet**: Let's Encrypt validates
from its own addresses, so an allowlisted security group fails validation.

### Live or not

`__LIVE__` is a property of the host, not of the source. A deployment marks
itself live by creating `app/live.flag`; every other copy — a fork, a laptop, a
staging box — stays in test mode, with Stripe **test** keys and errors on
screen. The flag is git-ignored and excluded from the deploy rsync, so pushing
an update cannot flip a box back and cloning the repository cannot make somebody
take real payments by accident.

Excluded from the deploy for the same reason: `app/app.json`, which is filled in
on the host and never travels.

### Donations (optional)

`/donate/` takes one-off donations through Stripe's hosted Checkout. It is off
until a key is configured: with none, the page renders the blurb and says
donations are not set up, so **a fresh clone asks nobody for money** — nobody
inherits somebody else's donation button by forking.

To turn it on, put your keys in `app/app.json`:

```json
"stripe": {
    "testPublicKey": "pk_test_...",
    "testSecretKey": "sk_test_...",
    "livePublicKey": "pk_live_...",
    "liveSecretKey": "sk_live_..."
}
```

`__LIVE__` decides which pair is used, so a staging deployment cannot take a
real payment. Only the secret key is read; the public keys are held in the same
shape so one config block can be shared with other projects.

No Stripe library is installed — `RSCD\Model\Stripe` posts to the API directly
through `RSCD\Util\CURL`. The donor pays on Stripe's own page; no card details
are posted to, handled by, or logged on this server.

## Security notes

- Passwords are stored with `password_hash()` (bcrypt) and transparently
  rehashed on sign-in when PHP's default cost changes.
- Sessions are cookie-seed based: the cookie holds an encrypted random seed; the
  database stores only its MD5 serial, so neither artifact alone grants a
  session.
- Password-reset and sign-in activation tokens are single-use, short-lived, and
  stored hashed (SHA-256).
- All random tokens come from a CSPRNG (`random_int`).
- Registration bot protection (Cloudflare Turnstile) is optional and
  config-driven; **no third-party request is made unless it is configured.**

## The rest of the project

| Repository | What it is |
|---|---|
| [`rscd-server`](https://github.com/RSCD-Community/rscd-server) | The game and login daemons, and the assets a world serves. |
| [`rscd-community-client`](https://github.com/RSCD-Community/rscd-community-client) | The desktop client players use. |
| [`rscd-toolkit`](https://github.com/RSCD-Community/rscd-toolkit) | The world editor — edits a server checkout's items, npcs, spawns, sprites and landscape. |

## Licence

**Apache-2.0.** Full text in [`LICENSE`](LICENSE), attribution and lineage in
[`NOTICE`](NOTICE).

RuneScape is a trademark of Jagex Ltd. This project is not affiliated with or
endorsed by Jagex. See
[what this project claims and does not](https://rscd-community.org/about/).
