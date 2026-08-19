# cache_data

This repository deliberately ships **no** `cache_data/` directory. The
sprites, sounds, models, maps and defs a client downloads at boot live in
`rscd-server/cache_data/` — one copy, owned by the server repository — and a
site deployment makes them available here by creating `cache_data` as a link
to that checkout. Skipping this step is silent: the site loads fine either way, only
"Play in Browser" and any world's `cache_url` break, with nothing in the
server logs to point at why.

Every option below assumes `rscd-server` is checked out as a sibling
directory, i.e. `../rscd-server/cache_data` exists from here. Adjust the path
if it lives somewhere else on this host, or skip straight to rsync if it's on
a different host entirely.

**Prefer a link over a copy if this host can have one.** A copy is not wrong,
but it is the option that can quietly stop being true, and there is no warning
when it does. rscd-community.org ran a copy for a day and drifted: an npc added
through the toolkit was written to `rscd-server/cache_data` and never reached
here, so every client downloaded a def list one entry short and drew that npc
as a plain Man. Nothing appears in any log — the client's own fallback is
`if (type >= npcCount()) type = 24;`, which is a silent substitution by
design. A link cannot drift.

## Symlink (Linux / macOS)

One copy on disk, always in sync with `rscd-server`. From the repository
root:

```sh
ln -s ../rscd-server/cache_data cache_data
```

If Apache serves this site, two things have to be true, and the second one is
easy to miss:

1. The directory the link *sits in* needs `Options +FollowSymLinks`, or Apache
   refuses to follow it. Write it explicitly on the `cache_data` block rather
   than relying on inheritance from the document root.
2. Whatever the link *points at* needs `Require all granted`. Ubuntu's stock
   `apache2.conf` denies `<Directory />`, so a target outside the document root
   is forbidden by default. Add a block for the real path alongside the one for
   the published path:

```apache
<Directory /path/to/rscd-www/cache_data>
	Options -Indexes +FollowSymLinks
	AllowOverride None
	Require all granted
</Directory>

<Directory /path/to/rscd-server/cache_data>
	Options -Indexes +FollowSymLinks
	AllowOverride None
	Require all granted
</Directory>
```

Get either wrong and requests under `/cache_data/` return 403, not 404 — which
looks like a permissions problem on the files rather than a symlink one, and
sends you looking in the wrong place.

## Symlink or junction (Windows)

Junctions don't need admin rights; true symlinks need either an elevated
shell or Developer Mode enabled:

```bat
mklink /J cache_data ..\rscd-server\cache_data
```

or, for a true symlink instead of a junction:

```bat
mklink /D cache_data ..\rscd-server\cache_data
```

## Copy (any OS, including across separate hosts)

No ongoing link, so re-run this after every `rscd-server` cache update:

```sh
mkdir -p cache_data && cp -r ../rscd-server/cache_data/. cache_data/
```

```bat
xcopy /E /I ..\rscd-server\cache_data cache_data
```

Or over SSH, for a separate host:

```sh
rsync -a --delete rscd-server-host:/path/to/rscd-server/cache_data/ cache_data/
```

## Either way

Everything under `cache_data/` is already served publicly with
`Access-Control-Allow-Origin: *` (see `app/app.htaccess`) — it's static,
unauthenticated game data the client is expected to fetch before anyone signs
in, same as any world's assets. That's already configured; there's nothing
else to set up once the files (or the link) are in place.
