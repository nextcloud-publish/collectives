<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Collectives static site renderer

A small, stateless PHP microservice that renders a static HTML site from a set of
Collectives pages. It replaces the in-process rendering that used to run inside the
Nextcloud PHP request worker so that static site generation can run on a separate,
horizontally scalable host.

## Why a separate service?

Rendering a whole collective (Markdown → HTML for every page, plus copying
attachments and zipping the result) is CPU- and memory-heavy. Doing it inside the
Nextcloud request worker ties up an FPM slot for the whole build. This service moves
that work out of the app:

- The Collectives app enqueues a **background job** when a user requests a site.
- The background job gathers the page Markdown + attachments and `POST`s them to
  this service.
- This service renders the site and returns it as a **ZIP archive**.
- The background job stores the ZIP in the user's files and sends a notification.

The service keeps no state and can be scaled with multiple replicas behind a load
balancer.

## API

### `GET /health`

Returns `200 {"status":"ok"}`. Used for container health checks.

### `POST /render`

Renders a site and returns a ZIP archive (`Content-Type: application/zip`). The
number of rendered pages is returned in the `X-Rendered-Pages` response header.

Request body (JSON):

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

The service listens on port `8080` (override with `COLLECTIVES_SSG_PORT`).

### Configure the app

In Nextcloud: **Administration settings → Additional settings → Collectives**, set:

- **Static site renderer URL** to a URL the Nextcloud container can reach, e.g.
  `http://host.docker.internal:8080` or `http://collectives-ssg-renderer:8080`
  (only if both containers share a Docker network).

## Local development

```bash
cd ssg
composer install
php -S 0.0.0.0:8080 -t public public/index.php
```
