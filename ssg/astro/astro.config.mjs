// SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { defineConfig } from 'astro/config'

// The Collectives backend drives the build via environment variables so that
// the same project can render different scopes into different output folders.
export default defineConfig({
	outDir: process.env.COLLECTIVES_SSG_OUTDIR || './dist',
	// The PHP backend runs as a web-server user that may not have write access
	// to the project directory, so all caches are redirected to a writable path.
	cacheDir: process.env.COLLECTIVES_SSG_CACHEDIR || './node_modules/.astro',
	// Assets are referenced relative to the page, so the generated site keeps
	// working no matter which folder it ends up being stored/served from.
	base: './',
	build: {
		format: 'directory',
		assets: 'assets',
	},
	vite: {
		cacheDir: process.env.COLLECTIVES_SSG_VITE_CACHEDIR || './node_modules/.vite',
	},
	// Keep the build quiet & deterministic for server-side invocation.
	devToolbar: { enabled: false },
})
