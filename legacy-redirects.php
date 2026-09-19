<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Plugin;

final class LegacyRedirectsPlugin extends Plugin
{
    public static function getSubscribedEvents(): array
    {
        return ['onPagesInitialized' => ['onPagesInitialized', 1000]];
    }

    public function onPagesInitialized(): void
    {
        $page = $this->grav['page'] ?? null;
        if ($page && (string) ($page->header()->virtual_collection ?? '') !== '') return;
        $path = $this->requestPath();
        if ($this->redirectPagination($path)) return;

        $redirects = $this->redirectMap();
        foreach ($redirects as $source => $target) {
            if ($this->matches((string) $source, $path)) {
                $this->redirectTarget($target);
            }
        }

        foreach ($this->wildcardDefinitions() as $definition) {
            if (!is_array($definition)) continue;
            $source = (string) ($definition['source'] ?? '');
            $target = (string) ($definition['target'] ?? '');
            if ($source === '' || $target === '') continue;
            $pattern = '#^' . str_replace('\\*', '(.*)', preg_quote($source, '#')) . '$#u';
            if (preg_match($pattern, $path) === 1) {
                $replacement = preg_replace($pattern, $target, $path);
                if ($replacement === null) throw new \RuntimeException('Wildcard redirect legacy non valido.');
                $this->redirectTarget([
                    'target' => $replacement,
                    'status' => $definition['status'] ?? 301,
                    'preserve_query' => $definition['preserve_query'] ?? false,
                ]);
            }
        }

        foreach ($this->regexDefinitions() as $pattern => $definition) {
            $compiled = $this->compileRegex((string) $pattern);
            $matched = @preg_match($compiled, $path);
            if ($matched === false) throw new \RuntimeException('Pattern regex redirect non valido.');
            if ($matched === 1) {
                $target = is_array($definition) ? ($definition['target'] ?? '') : $definition;
                $replacement = is_string($target) ? preg_replace($compiled, $target, $path) : null;
                if ($replacement === null) throw new \RuntimeException('Target regex redirect legacy non valido.');
                $this->redirectTarget([
                    'target' => $replacement,
                    'status' => is_array($definition) ? ($definition['status'] ?? 301) : 301,
                    'preserve_query' => is_array($definition) ? ($definition['preserve_query'] ?? false) : false,
                ]);
            }
        }
    }

    private function requestPath(): string
    {
        $path = '/' . trim(rawurldecode((string) $this->grav['uri']->path()), '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function redirectPagination(string $path): bool
    {
        $definition = (array) $this->config->get('plugins.legacy-redirects.pagination', []);
        if (($definition['enabled'] ?? false) !== true) return false;
        $parameter = (string) ($definition['parameter'] ?? 'page');
        $value = $this->grav['uri']->query($parameter);
        if (!is_scalar($value) || preg_match('/^\d+$/D', (string) $value) !== 1) return false;

        $paths = array_map([$this, 'normalizePath'], (array) ($definition['paths'] ?? []));
        foreach ((array) ($definition['path_config_values'] ?? []) as $configPath) {
            foreach ((array) $this->grav['config']->get((string) $configPath, []) as $route) {
                if (is_string($route)) $paths[] = $this->normalizePath($route);
            }
        }
        if (!in_array($path, array_unique($paths), true)) return false;

        $aliases = (array) ($definition['aliases'] ?? []);
        $base = $this->normalizePath((string) ($aliases[$path] ?? $path));
        $number = (int) $value + (($definition['zero_based'] ?? false) === true ? 1 : 0);
        $target = $number <= 1 ? $base : rtrim($base, '/') . '/page:' . $number;
        $this->redirectTarget(['target' => $target === '' ? '/' : $target, 'status' => 301, 'preserve_query' => false]);
    }

    private function matches(string $source, string $path): bool
    {
        $parts = parse_url($source);
        if (!is_array($parts) || !str_starts_with($source, '/') || array_intersect_key($parts, array_flip(['scheme', 'host', 'user', 'pass', 'fragment'])) !== []) return false;
        if ($this->normalizePath((string) ($parts['path'] ?? '/')) !== $path) return false;
        if (!isset($parts['query'])) return true;
        parse_str((string) $parts['query'], $expected);
        parse_str((string) ($_SERVER['QUERY_STRING'] ?? ''), $actual);
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual) || (string) $actual[$key] !== (string) $value) return false;
        }
        return true;
    }
    /** @return array<string,mixed> */
    private function configuredMap(string $option): array
    {
        $path = trim((string) $this->config->get('plugins.legacy-redirects.' . $option, ''));
        return $path === '' ? [] : (array) $this->grav['config']->get($path, []);
    }

    /** @return array<string,mixed> */
    private function redirectMap(): array
    {
        $file = $this->mapFile();
        $fromFile = is_array($file['redirects'] ?? null) ? $file['redirects'] : $file;
        $redirects = [];
        foreach ((array) $fromFile as $source => $target) {
            // Structured map files may contain metadata beside redirects.
            // Only route keys and scalar/definition targets enter the matcher.
            $source = (string) $source;
            if (str_starts_with($source, '/') && (is_string($target) || is_array($target))) {
                $redirects[$source] = $target;
            }
        }
        return array_replace($redirects, $this->configuredMap('redirect_config'));
    }

    /** @return list<mixed> */
    private function configuredList(string $option): array
    {
        return array_values($this->configuredMap($option));
    }

    /** @return list<array<string,mixed>> */
    private function wildcardDefinitions(): array
    {
        $file = $this->mapFile();
        $definitions = is_array($file['wildcards'] ?? null) ? $this->normalizeDefinitions($file['wildcards']) : [];
        return array_merge($definitions, $this->normalizeDefinitions($this->configuredList('wildcards_config')));
    }

    /** @return array<string,mixed> */
    private function mapFile(): array
    {
        $file = trim((string) $this->config->get('plugins.legacy-redirects.map_file', ''));
        if ($file === '') return [];
        $root = defined('GRAV_ROOT') ? (string) GRAV_ROOT : dirname(__DIR__, 3);
        if (preg_match('/^[a-z][a-z0-9+.-]*:\\/\\//i', $file) === 1) {
            $path = null;
            try {
                $resolved = $this->grav['locator']->findResource($file, true);
                $path = is_string($resolved) ? $resolved : null;
            } catch (\Throwable) {
                $path = null;
            }
            if ($path === null) throw new \RuntimeException('Mappa redirect legacy non trovata.');
        } else {
            $path = str_starts_with($file, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $file) === 1
                ? $file : $root . '/' . ltrim($file, '/\\');
        }
        if (!is_file($path)) throw new \RuntimeException('Mappa redirect legacy non trovata.');
        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Mappa redirect legacy non valida.', 0, $exception);
        }
        return is_array($data) ? $data : [];
    }

    /** @return array<string,mixed> */
    private function regexDefinitions(): array
    {
        $file = $this->mapFile();
        $definitions = is_array($file['regex'] ?? null) ? $file['regex'] : [];
        return array_replace($this->normalizeRegexDefinitions($definitions), $this->normalizeRegexDefinitions($this->configuredMap('regex_config')));
    }

    /** @param array<mixed> $definitions @return array<string,mixed> */
    private function normalizeRegexDefinitions(array $definitions): array
    {
        if (!array_is_list($definitions)) return $definitions;
        $result = [];
        foreach ($definitions as $definition) {
            if (!is_array($definition)) continue;
            $pattern = (string) ($definition['pattern'] ?? $definition['source'] ?? '');
            if ($pattern === '') continue;
            $result[$pattern] = $definition;
        }
        return $result;
    }

    /** @param array<mixed> $definitions @return list<array<string,mixed>> */
    private function normalizeDefinitions(array $definitions): array
    {
        if (array_is_list($definitions)) {
            return array_values(array_filter($definitions, static fn(mixed $definition): bool => is_array($definition)));
        }
        $result = [];
        foreach ($definitions as $source => $definition) {
            if (is_array($definition)) {
                if (!array_key_exists('source', $definition)) $definition = ['source' => (string) $source] + $definition;
                $result[] = $definition;
            } elseif (is_string($definition)) {
                $result[] = ['source' => (string) $source, 'target' => $definition];
            }
        }
        return $result;
    }

    private function compileRegex(string $pattern): string
    {
        if ($pattern === '') return '~(?!)~';
        $delimiter = $pattern[0];
        if (!ctype_alnum($delimiter) && !ctype_space($delimiter)
            && preg_match('/^' . preg_quote($delimiter, '/') . '.*' . preg_quote($delimiter, '/') . '[a-z]*$/is', $pattern) === 1
        ) return $pattern;
        return '~^(?:' . $pattern . ')$~u';
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function redirectTarget(mixed $definition): never
    {
        $target = is_array($definition) ? ($definition['target'] ?? '') : $definition;
        $target = is_string($target) ? $target : '';
        if ($target === '' || !str_starts_with($target, '/') || str_starts_with($target, '//')
            || preg_match('//u', $target) !== 1
            || str_contains($target, '\\') || preg_match('/[\x00-\x20\x7f]|%(?![0-9A-Fa-f]{2})/u', $target) === 1
            || preg_match('/[\x00-\x20\x7f]/u', rawurldecode($target)) === 1
        ) throw new \RuntimeException('Target redirect legacy non valido.');
        $parts = parse_url($target);
        // Grav's pagination uses colon routes such as /page:2, which PHP's
        // parse_url() rejects as malformed despite being valid local paths.
        if ($parts === false) {
            if (preg_match('~^/[A-Za-z0-9._\~:/%+@,-]+(?:\?[^#]*)?(?:#.*)?$~u', $target) !== 1) {
                throw new \RuntimeException('Target redirect legacy non valido.');
            }
            $parts = [];
        }
        if (!is_array($parts) || array_intersect_key($parts, array_flip(['scheme', 'host', 'user', 'pass'])) !== []) {
            throw new \RuntimeException('Target redirect legacy non valido.');
        }
        $targetPath = (string) ($parts['path'] ?? '');
        foreach (explode('/', rawurldecode($targetPath)) as $segment) {
            if ($segment === '.' || $segment === '..') throw new \RuntimeException('Target redirect legacy non valido.');
        }
        $statusValue = is_array($definition) ? ($definition['status'] ?? 301) : 301;
        if (is_int($statusValue)) {
            $status = $statusValue;
        } elseif (is_string($statusValue) && preg_match('/^\d+$/D', $statusValue) === 1) {
            $status = (int) $statusValue;
        } elseif (is_float($statusValue) && is_finite($statusValue) && floor($statusValue) === $statusValue) {
            $status = (int) $statusValue;
        } else {
            throw new \RuntimeException('Status redirect legacy non valido.');
        }
        if ($status < 300 || $status > 399) throw new \RuntimeException('Status redirect legacy non valido.');
        $preserveQuery = is_array($definition) && ($definition['preserve_query'] ?? false) === true;
        if ($preserveQuery && ($query = (string) ($_SERVER['QUERY_STRING'] ?? '')) !== '') {
            $fragment = '';
            $fragmentPosition = strpos($target, '#');
            if ($fragmentPosition !== false) {
                $fragment = substr($target, $fragmentPosition);
                $target = substr($target, 0, $fragmentPosition);
            }
            $separator = str_contains($target, '?') ? (str_ends_with($target, '?') || str_ends_with($target, '&') ? '' : '&') : '?';
            $target .= $separator . $query . $fragment;
        }
        $this->grav->redirect($target, $status);
    }
}
