#!/bin/bash

set -e

LARAVEL_PROJECT="xammie/mailbook:dev-main#fb2a633f49b47c02d3cee8ba1334f313791bd6b6"

echo "Install Laravel project: ${LARAVEL_PROJECT}"
composer create-project --quiet --prefer-dist "${LARAVEL_PROJECT}" ../laravel
cd ../laravel/
composer show --direct

echo "Add Bladestan from source"
composer config minimum-stability dev
composer config repositories.0 '{ "type": "path", "url": "../bladestan", "options": { "symlink": false } }'

# No version information with "type":"path"
composer require --dev --optimize-autoloader "tomasvotruba/bladestan:*"

echo "Test Laravel project"
vendor/bin/phpstan analyse
