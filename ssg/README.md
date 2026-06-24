<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Static Site Generator (SSG) for Collectives

This directory contains the **Astro**-based static site generator used by the
Collectives app to render static HTML sites from collective content.

> **Status: proof of concept.** Today it renders a bundled *sample* site to
> demonstrate the toolchain end-to-end. The next step is to feed real collective
> pages (Markdown) into the build instead of the sample content.

## Why a static site generator?

Collectives are written in Markdown and edited collaboratively. A static site
generator lets us turn a *scope* of a collective (later: selected pages) into a
self-contained set of HTML files that can be archived, downloaded, or published
without needing a running Nextcloud.

[Astro](https://astro.build/) was chosen as the first SSG because it renders to
plain static HTML by default, has first-class Markdown support, and has a small,
scriptable CLI that is easy to invoke from the backend.

## Architecture overview

```
 Browser (Vue)                     PHP backend                       Node / Astro
 ─────────────                     ───────────                       ────────────
 NcActionCollective-               StaticSiteController (OCS)         ssg/.runtime/node
   Actions.vue                       │                                  (portable Node)
   "Generate static site"  ──POST──▶ │                                       ▲
                                     ▼                                       │
                            StaticSiteService                                │
                              1. locate Node binary  ─────────────────────────┘
                              2. proc_open `astro build` (output → temp dir)
                              3. store the generated index.html in the user's files
                                     │
                                     ▼
                            /<user>/files/Collectives Static Sites/<name>-<timestamp>.html
```

### Request flow

1. The user clicks **Generate static site** in the collective actions menu
   (`src/components/Collective/NcActionCollectiveActions.vue`).
2. The frontend calls `generateStaticSite()`
   (`src/apis/collectives/staticSite.js`), which `POST`s to the OCS endpoint
   `POST /ocs/v2.php/apps/collectives/api/v1.0/staticsite`.
3. `StaticSiteController::create()` delegates to
   `StaticSiteService::generateSampleSite()`.
4. The service runs `astro build` and stores the resulting HTML in the user's
   files, returning the relative `path` of the generated file.
5. The frontend shows a success toast with the saved path.

## Directory layout

```
ssg/
├── README.md            ← this file
├── fetch-node.sh        ← downloads a portable Node.js runtime into .runtime/
├── .runtime/            ← portable Node (git-ignored, created by fetch-node.sh)
│   └── node/bin/node
└── astro/               ← the Astro project
    ├── package.json
    ├── astro.config.mjs
    ├── node_modules/     ← Astro + deps (git-ignored)
    ├── .astro/           ← Astro content-types cache (git-ignored, build-time)
    └── src/pages/index.astro   ← the sample page
```

## The Node runtime problem (and solution)

Astro needs **Node.js at runtime** (when the button is clicked), but the
Nextcloud PHP container ships **without Node**.

Solution: a **portable Node runtime is placed inside this app directory**
(`ssg/.runtime/node`). Because the app directory is bind-mounted into the
container and the host and container share the same OS/architecture
(`linux-x64` in the dev setup), the same binary works in both places and
survives container restarts — without modifying the container image.

`StaticSiteService` checks for the portable runtime at
`ssg/.runtime/node/bin/node` (and the Astro entry point); if either is missing it
throws `MissingDependencyException`.

## How the backend invokes Astro

`StaticSiteService` is intentionally minimal — the whole flow is just *run the
build, then save the result*. It uses PHP's `proc_open()` (array form, so **no
shell** is involved) to run, with `cwd` set to `ssg/astro`:

```
<node> ssg/astro/node_modules/astro/astro.js build
```

Build output and caches are redirected into a per-build temp directory
(`sys_get_temp_dir()/collectives-ssg-<random>`) via environment variables, which
`astro.config.mjs` reads:

| Env variable                       | Purpose                                  |
| ---------------------------------- | ---------------------------------------- |
| `COLLECTIVES_SSG_OUTDIR`           | Astro `outDir` (built site)              |
| `COLLECTIVES_SSG_CACHEDIR`         | Astro `cacheDir`                         |
| `COLLECTIVES_SSG_VITE_CACHEDIR`    | Vite cache dir                           |
| `COLLECTIVES_SSG_TITLE`            | Title rendered on the sample page        |
| `COLLECTIVES_SSG_USER`             | User shown in the page footer            |
| `ASTRO_TELEMETRY_DISABLED=1`       | Disable telemetry (avoids extra writes)  |

The page (`src/pages/index.astro`) reads `COLLECTIVES_SSG_TITLE` /
`COLLECTIVES_SSG_USER` via `process.env` at build time, demonstrating how a
collective scope can parametrise the output, and uses **inline CSS** so the
generated `index.html` is a single self-contained file. The service writes it
into the user's files (via `IRootFolder`) as
`Collectives Static Sites/<title>-<timestamp>.html`, then removes the temp
directory. (A real multi-file site would copy the whole output directory instead
of a single file — that is the natural next step.)

### The `.astro` write requirement

Astro always writes content-collection types into `ssg/astro/.astro/` at build
time, regardless of `cacheDir`. Since the backend runs as the web-server user
(`www-data`), **that directory must be writable by it**. `make ssg-setup` runs
`chmod o+w ssg/astro` to allow Astro to create `.astro/` there.

## Setup

From the app root:

```bash
make ssg-setup
```

This will:

1. Download a portable Node.js runtime into `ssg/.runtime/` (`fetch-node.sh`).
2. Install Astro and its dependencies in `ssg/astro/`.
3. Make `ssg/astro` writable by the web-server user (for `.astro/`).

In the dev Docker setup, where the host has no direct internet but the container
does, run the download/install inside the container (the app dir is bind-mounted):

```bash
docker exec master-nextcloud-1 sh -c \
  'cd /var/www/html/apps-extra/collectives && sh ssg/fetch-node.sh'
docker exec master-nextcloud-1 sh -c \
  'cd /var/www/html/apps-extra/collectives/ssg/astro && \
   ../.runtime/node/bin/npm install --no-audit --no-fund'
```

## Manual testing

Run a build directly (bypassing PHP), writing to a temp folder:

```bash
cd ssg/astro
PATH="$PWD/../.runtime/node/bin:$PATH" \
COLLECTIVES_SSG_TITLE="Demo" COLLECTIVES_SSG_OUTDIR=/tmp/ssg-out \
ASTRO_TELEMETRY_DISABLED=1 \
node node_modules/astro/astro.js build
ls /tmp/ssg-out   # → index.html
```

Exercise the full endpoint (build + store in the user's files):

```bash
curl -s -u admin:admin -H "OCS-APIRequest: true" -H "Accept: application/json" \
  -X POST "http://nextcloud.local/ocs/v2.php/apps/collectives/api/v1.0/staticsite" \
  --data "title=Demo Collective"
# → {"ocs":{...,"data":{"path":"/Collectives Static Sites/Demo Collective-....html"}}}
```

The generated site appears in the **Files** app under
`Collectives Static Sites/<name>-<timestamp>.html`.

## Relevant source files

| Concern        | File                                                          |
| -------------- | ------------------------------------------------------------ |
| Astro project  | `ssg/astro/` (`astro.config.mjs`, `src/pages/index.astro`)  |
| Node runtime   | `ssg/fetch-node.sh`, `make ssg-setup`                        |
| Backend logic  | `lib/Service/StaticSiteService.php`                          |
| OCS endpoint   | `lib/Controller/StaticSiteController.php`, `appinfo/routes.php` |
| Frontend API   | `src/apis/collectives/staticSite.js`                        |
| Button (UI)    | `src/components/Collective/NcActionCollectiveActions.vue`   |

## Roadmap / next steps

- Replace the sample content with **real collective pages** (render the
  Markdown tree of a selected scope).
- Add a **scope/page selector** in the UI before generating.
- Support a **downloadable archive** (zip) of the generated site.
- Make the SSG **pluggable** so other generators can be added alongside Astro.
