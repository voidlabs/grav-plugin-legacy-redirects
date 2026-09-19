# Legacy Redirects

Legacy Redirects is a Grav 2 plugin for migrating Drupal, WordPress and other
legacy routes. It supports literal routes, explicit wildcards, explicit regular
expressions and pagination redirects without treating punctuation in an ordinary
route as a regular expression.

The plugin is deliberately site-agnostic. Redirect maps, aliases, taxonomy
routes and other migration data belong in the consuming Grav site, not in this
package.

## Requirements and installation

- Grav 2.0 or later;
- PHP 8.3 or later.

Install it with the Grav Package Manager after the repository is published:

```bash
bin/gpm install legacy-redirects
```

Composer installation is also supported:

```bash
composer require voidlabs/grav-plugin-legacy-redirects
```

Enable the plugin in the site's configuration:

```yaml
# user/config/plugins/legacy-redirects.yaml
enabled: true
```

## Redirect maps

Use `redirect_config` for literal routes. The value is a configuration path in
the consuming site, so the map remains outside this repository:

```yaml
# user/config/site.yaml
legacy:
  redirects:
    '/?p=133': '/articles/example'
    '/old-section':
      target: '/new-section'
      status: 301
      preserve_query: false
```

```yaml
# user/config/plugins/legacy-redirects.yaml
enabled: true
redirect_config: site.legacy.redirects
```

Do not place these maps under `site.redirects`: Grav reserves that key for its
own resolver. Use a distinct site configuration section such as `site.legacy`.

Literal sources are matched as paths. If a source declares a query, every
declared parameter must be present with the same value; undeclared parameters
do not change the match. For example, `/?p=133` matches that query exactly but
does not match `/?p=134`.

Use `wildcards_config` for definitions with a single `*` capture:

```yaml
wildcards_config: site.legacy.wildcards
```

```yaml
# add under `legacy` in user/config/site.yaml
legacy:
  wildcards:
    - source: /old/*
      target: /new/$1
```

Use `regex_config` only when a regular expression is intentional:

```yaml
regex_config: site.legacy.regex
```

```yaml
# add under `legacy` in user/config/site.yaml
legacy:
  regex:
    '~^/legacy/(.*)$~i': '/new/$1'
```

Undelimited patterns are wrapped in a complete-match expression. Delimited
patterns keep their delimiter and modifiers. The processing order is literal
maps, wildcards and then explicit regular expressions; site configuration
overrides the corresponding configured section from a map file.

## JSON map files

`map_file` can point to a JSON file relative to `GRAV_ROOT` or to a Grav
resource URI. The file may contain `redirects`, `wildcards` and `regex`:

```json
{
  "redirects": {
    "/old": "/new"
  },
  "wildcards": [
    {"source": "/old/*", "target": "/new/$1"}
  ],
  "regex": {
    "~^/legacy/(.*)$~": "/new/$1"
  }
}
```

## Pagination

Pagination can map a query parameter to Grav's colon route syntax:

```yaml
pagination:
  enabled: true
  parameter: page
  zero_based: false
  paths:
    - /articles
  aliases:
    /articles: /news
```

`?page=1` redirects to the base path; later pages redirect to paths such as
`/news/page:2`. `path_config_values` can additionally list site configuration
paths whose string values should be treated as pagination paths.

## Safety rules

Targets must be local absolute paths beginning with exactly one `/`. External
hosts, schemes, credentials, control characters, dot segments and invalid
percent escapes are rejected. Redirect statuses must be in the `300–399`
range. The incoming query string is not copied by default; opt in per
definition with `preserve_query: true`.

The plugin runs at `onPagesInitialized` priority `1000` and skips virtual
collection pages.

## Development

Run the isolated checks from the repository root:

```bash
composer validate --strict
composer check
```

The CI workflow runs the same checks on the supported PHP versions. Test this
plugin again in a clean Grav 2 installation before publishing a release.
