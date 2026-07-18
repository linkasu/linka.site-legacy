# linka.su Legacy Analytics Control

This repository is intentionally separate from `/Volumes/data/linka/site/backups`. It contains one MU plugin that controls the already installed official `wp-yandex-metrika` plugin without modifying vendor files.

## Behavior

| Admin state | Visitor cookie | Yandex tag | Webvisor |
| --- | --- | --- | --- |
| Unknown | any | absent | absent |
| Disabled | any | absent | absent |
| Visitor choice available | Unknown/Denied | absent | absent |
| Visitor choice available | Granted | loaded by the official plugin | forcibly disabled |

On the first WordPress admin request after deployment, administrators choose whether visitor-level analytics consent is available. Until one is selected, the state is Unknown and fails closed. The admin choice is stored in `linka_legacy_analytics_preference` and can be reopened under **Settings -> Аналитика linka.su**. It never enables analytics for all visitors.

When visitor choice is available, each visitor receives an immediate Enable/Disable panel. The result is persisted in the HttpOnly `linka_analytics_consent` cookie and can be reopened through the footer settings link. The MU plugin allows the vendor plugin only when the admin state permits consent and the current visitor cookie is `granted`.

The consent POST requires a visitor-bound 256-bit random double-submit cookie/form token, WordPress nonce, exact production Origin, and strict same-origin Fetch Metadata. Production consent and CSRF cookies are always `Secure`, `HttpOnly`, path `/`, and `SameSite=Strict`; the CSRF token rotates after a choice.

Responses are marked non-cacheable and vary by Cookie while visitor choice is available, preventing tagged HTML from a consenting visitor from being reused for an Unknown or Denied visitor.

The MU plugin applies both runtime and update filters to the vendor `yam_options` option so every counter has `webvisor=0`. Late action removal and script dequeueing provide defense in depth if the vendor plugin is loaded outside the normal active-plugin path.

## Local Checks

```sh
cd /Volumes/data/linka/linka.site-legacy
tests/run.sh
git status --short
```

## Production Rollout Plan

These commands are a plan only. Do not run `--apply` without explicit approval and a fresh production files backup.

```sh
cd /Volumes/data/linka/site
scripts/linka-status.sh
scripts/linka-wp-info.sh
scripts/linka-backup-files.sh wp-content

cd /Volumes/data/linka/linka.site-legacy
tests/run.sh
scripts/deploy.sh --dry-run

LINKA_LEGACY_BACKUP_CONFIRMED=YES \
LINKA_LEGACY_RELEASE_GO=GO \
LINKA_LEGACY_PRODUCTION_APPLY=YES \
scripts/deploy.sh --apply

ssh linka.su 'docker exec linka-php wp --allow-root --path=/var/www/html cache flush'
ssh linka.su 'docker exec linka-php wp --allow-root --path=/var/www/html option get linka_legacy_analytics_preference'
curl --fail --silent --show-error https://linka.su/ -o /tmp/linka-su-home.html
if grep -Eq 'mc\.yandex\.ru|metrika/tag\.js|webvisor[[:space:]]*:[[:space:]]*true' /tmp/linka-su-home.html; then exit 1; fi
```

The final HTML check shown above is valid for Unknown, Disabled, and every visitor without a `granted` cookie. After visitor-level consent, verify with that browser's cookie rather than a global anonymous curl request.

```sh
curl --fail --silent --show-error --cookie 'linka_analytics_consent=granted' https://linka.su/ -o /tmp/linka-su-home.html
grep -q 'metrika/tag.js' /tmp/linka-su-home.html
if grep -Eq 'webvisor[[:space:]]*:[[:space:]]*true' /tmp/linka-su-home.html; then exit 1; fi
```

Also inspect the browser network panel with cache disabled: Unknown/Disabled must make no requests to `mc.yandex.ru`; Enabled may load the tag but must not initialize session replay. Do not edit `wp-content/plugins/wp-yandex-metrika` during rollout.
