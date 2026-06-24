#!/bin/sh
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Downloads a portable Node.js runtime into ssg/.runtime/node so that the PHP
# backend can run the Astro static site generator at runtime, even when the
# Nextcloud container/host has no system-wide Node installed.
#
# The runtime is placed inside the (bind-mounted) app directory on purpose:
# it then works both on the host and inside the Nextcloud container as long as
# they share the same OS/architecture (linux-x64 in the dev setup).
set -eu

NODE_VERSION="${NODE_VERSION:-v22.12.0}"
ARCH="${NODE_ARCH:-linux-x64}"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
RUNTIME_DIR="$SCRIPT_DIR/.runtime"
TARGET_DIR="$RUNTIME_DIR/node"

if [ -x "$TARGET_DIR/bin/node" ]; then
	echo "Node runtime already present: $("$TARGET_DIR/bin/node" -v)"
	exit 0
fi

TARBALL="node-$NODE_VERSION-$ARCH.tar.xz"
URL="https://nodejs.org/dist/$NODE_VERSION/$TARBALL"

echo "Downloading $URL"
mkdir -p "$RUNTIME_DIR"
curl -fsSL "$URL" -o "$RUNTIME_DIR/$TARBALL"
tar -xJf "$RUNTIME_DIR/$TARBALL" -C "$RUNTIME_DIR"
rm -f "$RUNTIME_DIR/$TARBALL"
mv "$RUNTIME_DIR/node-$NODE_VERSION-$ARCH" "$TARGET_DIR"

echo "Installed portable Node runtime: $("$TARGET_DIR/bin/node" -v)"
