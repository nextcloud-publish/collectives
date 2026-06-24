<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Static Site Generator (SSG) for Collectives

This directory contains the **Hugo**-based static site generator used by the
Collectives app to render static HTML sites from collective content.

> **Status: proof of concept.** The user picks pages from a collective in a
> dialog and the backend renders **those pages' Markdown** into a small static
> HTML site stored in the user's files.

## Why a static site generator?

Collectives are written in Markdown and edited collaboratively. A static site
generator turns selected pages of a collective into a self-contained set of HTML
files that can be archived, downloaded, or published without a running Nextcloud.

[Hugo](https://gohugo.io/) was chosen because it is **lightweight**: it ships as
a **single static binary** with no runtime dependencies (no Node, no
`node_modules`), it has first-class Markdown support, and it builds extremely
fast. This keeps the runtime requirement to "one executable on disk".

## Architecture overview

```
 Browser (Vue)                       PHP backend                       Hugo
 ─────────────                       ───────────                       ────
 "Generate static site"             StaticSiteController (OCS)         ssg/.runtime/hugo
   → StaticSiteModal.vue              │                                  (single binary)
     (pick pages)         ──POST──▶   │                                       ▲
     {collectiveId,                   ▼                                       │
      pageIds, title}        StaticSiteService                                │
                              1. copy ssg/hugo → temp/site                    │
                              2. write selected pages' Markdown to content/   │
                              3. proc_open `hugo --source temp/site …`  ───────┘
                              4. copy the rendered HTML tree into the files
                                     │
                                     ▼
                            /<user>/files/Collectives Static Sites/<name>-<timestamp>/
                              ├── index.html        (overview, links to pages)
                              ├── page-<id>/index.html
                              └── …
```

### Request flow

1. The user clicks **Generate static site** in the collective actions menu
   (`src/components/Collective/NcActionCollectiveActions.vue`), which opens the
   page-selection dialog (`StaticSiteModal.vue`, hosted by `NavigationBar.vue`
   via the `staticSiteCollectiveId` store state).
2. The dialog loads the collective's pages (`getPages`), the user selects the
   pages to include, and confirms.
3. The frontend calls `generateStaticSite(collectiveId, pageIds, title)`
   (`src/apis/collectives/staticSite.js`), which `POST`s to the OCS endpoint
   `POST /ocs/v2.php/apps/collectives/api/v1.0/staticsite`.
4. `StaticSiteController::create()` delegates to
   `StaticSiteService::generateSite()`.
5. The service reads each selected page's Markdown, runs `hugo`, and copies the
   rendered HTML tree into the user's files, returning the relative `path` of the
   output folder and the number of `pages` rendered.
6. The frontend shows a success toast with the saved path.

## Directory layout

```
ssg/
├── README.md            ← this file
├── fetch-hugo.sh        ← downloads a portable Hugo binary into .runtime/
├── .runtime/            ← portable Hugo binary (git-ignored, created by fetch-hugo.sh)
│   └── hugo
└── hugo/                ← the Hugo project
    ├── hugo.toml        ← site config (relative URLs, raw-HTML rendering, env allow-list)
    └── layouts/
        ├── index.html         ← homepage: lists the exported pages
        └── _default/
            ├── baseof.html    ← shared skeleton + inline CSS
            └── single.html    ← one exported page (renders its Markdown)
```

The page Markdown is **not** stored here — it is written into a throwaway
`content/` directory inside a temp copy of the project at build time.

## The runtime problem (and solution)

Hugo needs its **binary at runtime** (when the button is clicked), but the
Nextcloud PHP container ships **without Hugo**.

Solution: a **portable Hugo binary is placed inside this app directory**
(`ssg/.runtime/hugo`). Because the app directory is bind-mounted into the
container and the host and container share the same OS/architecture
(`linux-amd64` in the dev setup), the same binary works in both places and
survives container restarts — without modifying the container image.

`StaticSiteService` checks that `ssg/.runtime/hugo` is executable and otherwise
throws `MissingDependencyException` (→ HTTP 501).

## How the backend invokes Hugo

Because the build now needs to *add* the selected pages as content, the service
copies the (read-only) Hugo project into a private temp directory, drops the
pages into `content/`, then builds. It uses PHP's `proc_open()` (array form, so
**no shell** is involved):

```
<hugo> --source <temp>/site --destination <temp>/out --noBuildLock --quiet
```

Each selected page becomes a Markdown file `content/page-<id>.md` with a small
front matter header:

```markdown
---
title: "📄 My page title"
weight: 3
---

<the page's Markdown content>
```

`weight` preserves the selection order on the overview page. Hugo renders each
file to `page-<id>/index.html` and the homepage (`layouts/index.html`) lists
them. Links are emitted **relative** (`relativeURLs = true`) so the site works
from any folder, and `markup.goldmark.renderer.unsafe = true` lets raw HTML in
the Markdown render.

Two values are still passed to the templates via environment variables, read
with `os.Getenv` (allow-listed in `hugo.toml` under `[security.funcs]`):

| Env variable             | Purpose                                |
| ------------------------ | -------------------------------------- |
| `COLLECTIVES_SSG_TITLE`  | Site title in the hero / `<title>`     |
| `COLLECTIVES_SSG_USER`   | User shown in the page footer          |

Finally the service copies the rendered HTML tree (via `IRootFolder`) into
`Collectives Static Sites/<title>-<timestamp>/` and removes the temp directory.

## Setup

From the app root:

```bash
make ssg-setup
```

This downloads a portable Hugo binary into `ssg/.runtime/` (`fetch-hugo.sh`).
That is the only setup step — there are no further dependencies to install.

In the dev Docker setup, where the host has no direct internet but the container
does, run the download inside the container (the app dir is bind-mounted):

```bash
docker exec master-nextcloud-1 sh -c \
  'cd /var/www/html/apps-extra/collectives && sh ssg/fetch-hugo.sh'
```

The Hugo version and architecture can be overridden via env vars, e.g.
`HUGO_VERSION=0.140.2 HUGO_ARCH=linux-amd64 sh ssg/fetch-hugo.sh`.

## Manual testing

Run a build directly (bypassing PHP) with a hand-written content file:

```bash
tmp=$(mktemp -d)
cp -r ssg/hugo "$tmp/site" && mkdir -p "$tmp/site/content"
printf '%s\n' '---' 'title: "Demo"' 'weight: 1' '---' '' '# Hello **world**' \
  > "$tmp/site/content/page-1.md"
COLLECTIVES_SSG_TITLE="Demo" COLLECTIVES_SSG_USER="alice" \
  ssg/.runtime/hugo --source "$tmp/site" --destination "$tmp/out" --noBuildLock --quiet
find "$tmp/out" -type f   # → index.html, page-1/index.html
```

Exercise the full endpoint (build + store in the user's files):

```bash
curl -s -u admin:admin -H "OCS-APIRequest: true" -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -X POST "http://nextcloud.local/ocs/v2.php/apps/collectives/api/v1.0/staticsite" \
  --data '{"collectiveId": 1, "pageIds": [11, 12], "title": "My Collective"}'
# → {"ocs":{...,"data":{"path":"/Collectives Static Sites/My Collective-…","pages":2}}}
```

The generated site appears in the **Files** app under
`Collectives Static Sites/<name>-<timestamp>/` (open `index.html`).

## Relevant source files

| Concern        | File                                                            |
| -------------- | -------------------------------------------------------------- |
| Hugo project   | `ssg/hugo/` (`hugo.toml`, `layouts/`)                          |
| Hugo runtime   | `ssg/fetch-hugo.sh`, `make ssg-setup`                          |
| Backend logic  | `lib/Service/StaticSiteService.php`                            |
| OCS endpoint   | `lib/Controller/StaticSiteController.php`, `appinfo/routes.php` |
| Frontend API   | `src/apis/collectives/staticSite.js`                          |
| Page-select UI | `src/components/Collective/StaticSiteModal.vue`               |
| Trigger / host | `…/Collective/NcActionCollectiveActions.vue`, `…/NavigationBar.vue`, `src/stores/collectives.js` |

## Building the frontend

The Vue assets require a recent Node.js (Vite 7 needs Node ≥ 20.19 / 22.12).
The bundled Hugo runtime does **not** include Node, so build the frontend with
your own toolchain, e.g. via nvm:

```bash
nvm install 24 && nvm use 24
npm run dev      # or: npm run build
```

## Roadmap / next steps

- Render the **page tree** (nested sections) instead of a flat list.
- Export page **attachments/images** alongside the HTML.
- Offer a **downloadable archive** (zip) of the generated site.
- Make the SSG **pluggable** so other generators can be added alongside Hugo.
