#!/usr/bin/env bash
set -Eeuo pipefail

mode="dry-run"
if [[ "${1:-}" == "--apply" ]]; then
  mode="apply"
elif [[ "${1:-}" != "" && "${1:-}" != "--dry-run" ]]; then
  printf 'Usage: %s [--dry-run|--apply]\n' "$0" >&2
  exit 2
fi

remote_host="${LINKA_LEGACY_REMOTE_HOST:-linka.su}"
remote_root="${LINKA_LEGACY_REMOTE_ROOT:-/home/aacidov/linka-docker/wordpress}"
remote_directory="${remote_root}/wp-content/mu-plugins"
source_file="$(cd "$(dirname "$0")/.." && pwd)/mu-plugins/linka-legacy-analytics-control.php"

if [[ ! "$remote_host" =~ ^[a-zA-Z0-9._@-]+$ || ! "$remote_root" =~ ^/[a-zA-Z0-9._/-]+$ ]]; then
  printf 'Invalid remote host or root path\n' >&2
  exit 2
fi

php -l "$source_file"

if [[ "$mode" == "apply" ]]; then
  if [[ "${LINKA_LEGACY_RELEASE_GO:-}" != "GO" || "${LINKA_LEGACY_BACKUP_CONFIRMED:-}" != "YES" || "${LINKA_LEGACY_PRODUCTION_APPLY:-}" != "YES" ]]; then
    printf 'Apply requires LINKA_LEGACY_RELEASE_GO=GO, LINKA_LEGACY_BACKUP_CONFIRMED=YES and LINKA_LEGACY_PRODUCTION_APPLY=YES\n' >&2
    exit 1
  fi
  ssh "$remote_host" "install -d -m 755 '$remote_directory'"
  remote_temp="$(ssh "$remote_host" "mktemp '$remote_directory/.linka-legacy-analytics-control.XXXXXXXX.tmp'")"
  remote_temp_name="${remote_temp##*/}"
  cleanup_remote_temp() {
    ssh "$remote_host" "rm -f '$remote_temp'" >/dev/null 2>&1 || true
  }
  trap cleanup_remote_temp EXIT
  rsync --archive --checksum --itemize-changes "$source_file" "$remote_host:$remote_temp"
  ssh "$remote_host" "docker exec linka-php php -l '/var/www/html/wp-content/mu-plugins/$remote_temp_name'"
  ssh "$remote_host" "chmod 644 '$remote_temp' && mv -f '$remote_temp' '$remote_directory/linka-legacy-analytics-control.php'"
  trap - EXIT
  exit
fi

if ! ssh "$remote_host" "test -d '$remote_directory'"; then
  printf 'Dry-run: remote MU plugin directory does not exist; apply would create %s\n' "$remote_directory"
  exit
fi

rsync --archive --checksum --itemize-changes --dry-run "$source_file" "$remote_host:$remote_directory/linka-legacy-analytics-control.php"
