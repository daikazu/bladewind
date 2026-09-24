#!/usr/bin/env sh
# Regenerates every compiled framework fixture under tests/fixtures/public-*/ from the inputs beside
# each build.mjs, then runs the suite, so a framework version bump is one command:
#
#     composer fixtures
#
# Each fixture directory pins its framework in package.json (Tailwind 3, Tachyons, Bootstrap 5,
# Bulma 1, Foundation 6); bump the version there, run this, and read the diff of the compiled CSS
# and the test output. Tailwind 4 has no package.json of its own: its build.mjs resolves
# @tailwindcss/node from a host application (see the header of tests/fixtures/tailwind/build.mjs),
# so it is built last and skipped with a message when that resolution fails.
#
# The compiled CSS is committed on purpose: the suite must not depend on Node being installed.
set -eu

cd "$(dirname "$0")"

for fixture in tailwind3 tachyons bootstrap5 bulma foundation; do
    echo "== $fixture"
    (cd "$fixture" && npm install --silent --no-audit --no-fund && node build.mjs)
done

echo "== tailwind"
if ! node tailwind/build.mjs; then
    echo "   skipped: tests/fixtures/tailwind/build.mjs could not resolve @tailwindcss/node (see its header)"
fi
if [ -f tailwind/input-prefix.css ]; then
    node tailwind/build.mjs --input=input-prefix.css --output=../public-tailwind/build/assets/app-tailwind-prefix.css || true
    node tailwind/build.mjs --input=input-colormix.css --output=../public-tailwind/build/assets/app-tailwind-colormix.css || true
fi

cd ../..
vendor/bin/pest
