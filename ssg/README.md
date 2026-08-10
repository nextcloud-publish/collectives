<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Collectives static site renderer

A small PHP microservice that renders a static HTML site from a set of Collectives
pages **and serves it**. It replaces the in-process rendering that used to run inside
the Nextcloud PHP request worker so that static site generation can run on a
separate, horizontally scalable host.

## Why a separate service?

Rendering a whole collective (Markdown → HTML for every page, plus copying
attachments) is CPU- and memory-heavy. Doing it inside the Nextcloud request worker
ties up an FPM slot for the whole build. This service moves that work out of the app:

- The Collectives app enqueues a **background job** when a user requests a site.
- The background job gathers the page Markdown + attachments and `POST`s them to
  this service.
- This service renders the site into its own storage and returns the **URL** it is
  served under.
- The background job notifies the user with a link to the published site.

Each site gets an unguessable id which doubles as the capability to view it: anyone
who knows the link can open the site, but ids cannot be listed or guessed. Do not
expose the service publicly for collectives with confidential content.

## API

### `GET /health`

Returns `200 {"status":"ok"}`. Used for container health checks.

### `POST /render`

Renders a site and returns `201` with JSON describing where it is served:

```json
{ "id": "0a1b…", "path": "/sites/0a1b…/", "pages": 3 }
```

### `GET /sites/<id>/…`

Serves the rendered site. `/sites/<id>` redirects to `/sites/<id>/`, and directory
paths resolve to `index.html`.

Request body of `POST /render` (JSON):

```json
{
  "title": "My collective",
  "user": "alice",
  "pages": [
    { "id": 12, "title": "🚀 Home", "markdown": "# Hello\n..." }
  ],
  "attachments": [
    { "path": ".attachments.12/image.png", "content": "<base64>" }
  ]
}
```

- `pages[].markdown` is the raw page Markdown.
- `attachments[].path` is the path (relative to the site root) where the decoded
  `content` (base64) is written. Absolute paths and `..` traversal are rejected.

## Running

### Docker Compose

```bash
cd ssg
docker compose up -d --build
```

The service listens on port `8080` (override with `COLLECTIVES_SSG_PORT`). Rendered
sites are bind-mounted to `./data` on the host (override with
`COLLECTIVES_SSG_HOST_DATA_DIR`) and live under `/data/sites` in the container, so
they survive restarts and are readable outside Docker (e.g. `ssg/data/sites/<id>/`).

### Configure the app

In Nextcloud: **Administration settings → Additional settings → Collectives**, set:

- **Static site renderer URL** to a URL the Nextcloud container can reach, e.g.
  `http://host.docker.internal:8080` or `http://collectives-ssg-renderer:8080`
  (only if both containers share a Docker network).
- **Public site URL** to the URL visitors use, e.g. `http://localhost:8080`. Leave it
  empty when the renderer URL already works from a browser.

## Local development

```bash
cd ssg
composer install
COLLECTIVES_SSG_DATA_DIR=./var/sites php -S 0.0.0.0:8080 -t public public/index.php
```
