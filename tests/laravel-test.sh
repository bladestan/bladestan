#!/bin/bash

set -e

# Temporary fork pinned while the upstream Mailbook project is being refactored
# to match Bladestan's template-centric analysis. Revert to Xammie/mailbook once
# the changes land upstream.
MAILBOOK_REPO="https://github.com/AJenbo/mailbook.git"
MAILBOOK_COMMIT="1ca8ddf12a8f8ef387c6550dead46775764d1d51"

echo "Cloning Mailbook project from Git"
git init --quiet ../mailbook
cd ../mailbook
git remote add origin "${MAILBOOK_REPO}"
git fetch --quiet --depth 1 origin "${MAILBOOK_COMMIT}"
git checkout --quiet FETCH_HEAD

composer install --quiet --prefer-dist
composer show --direct

echo "Add Bladestan from source"
composer config minimum-stability dev
composer config repositories.0 '{ "type": "path", "url": "../bladestan", "options": { "symlink": false } }'

# No version information with "type":"path"
composer require --dev --optimize-autoloader "tomasvotruba/bladestan:*"

echo "Test Mailbook project"
# .bladestan on the CLI opts into template-centric analysis: the bootstrap
# compiles blade templates there and PHPStan analyses them as regular PHP.
vendor/bin/phpstan analyse src config .bladestan --error-format=blade
