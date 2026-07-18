# linka.su Legacy Analytics Control

- This repository contains a source-controlled MU plugin and deployment tooling for the legacy `linka.su` WordPress site.
- Never store WordPress, SSH, database, analytics or TLS secrets here.
- Never copy database backups from `/Volumes/data/linka/site/backups` into this repository.
- Do not patch or vendor the official `wp-yandex-metrika` plugin. Control it only through WordPress hooks from the MU plugin.
- Unknown and Disabled admin preferences must fail closed: no Yandex tag may load.
- Admin availability never enables analytics globally. A request may load the tag only after that visitor's explicit cookie consent.
- Visitor consent POST must retain random double-submit CSRF, strict same-origin Fetch Metadata/Origin checks, and production `Secure` cookies.
- Webvisor/session replay must remain permanently disabled even when analytics is Enabled.
- Deployment is dry-run by default. Production apply requires a fresh backup and two explicit environment confirmations.
- Do not commit, push or deploy without an explicit user request.
