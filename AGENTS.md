# Agent instructions

This is the public standalone `legacy-redirects` plugin for Grav 2.

Keep the plugin slug, public configuration keys and redirect contract stable.
The consuming Grav site owns redirect maps, aliases, taxonomy routes, themes,
accounts and other migration data; none of those belong in this repository.
Do not add source-extraction notes, private site history or migration records.

Keep `composer.json`, `README.md`, `CHANGELOG.md` and the CI workflow aligned
with the supported Grav and PHP versions. Before changing behavior, run:

```bash
composer validate --strict
composer check
```

Before a release, also run the plugin checks in a clean Grav 2 installation and
verify installation, configuration, literal routes, query matching, wildcard
routes, explicit regular expressions, pagination, status validation and local
target validation. Update the changelog and follow `RELEASE.md`.

Never commit site-specific redirect maps, generated runtime data, cache files,
logs, credentials or private records.
