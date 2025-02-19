#!/bin/bash

set -e

MAILBOOK_REPO="https://github.com/Xammie/mailbook.git"
MAILBOOK_COMMIT="1.9.0"

echo "Cloning Mailbook project from Git"
git clone --depth 1 --branch "${MAILBOOK_COMMIT}" "${MAILBOOK_REPO}" ../mailbook
cd ../mailbook

composer install --quiet --prefer-dist
composer show --direct

echo "Add Bladestan from source"
composer config minimum-stability dev
composer config repositories.0 '{ "type": "path", "url": "../bladestan", "options": { "symlink": false } }'

# No version information with "type":"path"
composer require --dev --optimize-autoloader "tomasvotruba/bladestan:*"

echo "Test Mailbook project"
vendor/bin/phpstan analyse --error-format=blade
