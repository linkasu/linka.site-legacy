#!/usr/bin/env sh
set -eu

php -l mu-plugins/linka-legacy-analytics-control.php
php -l tests/analytics-control.php
php -l tests/source-policy.php
php tests/analytics-control.php
php tests/source-policy.php
sh -n scripts/deploy.sh
