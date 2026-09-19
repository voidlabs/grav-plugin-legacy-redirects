<?php

declare(strict_types=1);

namespace Grav\Common {
    class Plugin
    {
        public mixed $grav;
        public mixed $config;
    }
}

namespace {
    require_once dirname(__DIR__) . '/legacy-redirects.php';

    $checks = 0;
    $failures = [];

    function check_contract(bool $condition, string $message): void
    {
        global $checks, $failures;
        ++$checks;
        if (!$condition) {
            $failures[] = $message;
        }
    }

    function check_throws(callable $callback, string $message): void
    {
        try {
            $callback();
            check_contract(false, $message);
        } catch (\Throwable) {
            check_contract(true, $message);
        }
    }

    final class RedirectSent extends \RuntimeException
    {
        public function __construct(public string $target, public int $status)
        {
            parent::__construct('Redirect captured by the test double.');
        }
    }

    final class TestConfig
    {
        /** @param array<string,mixed> $values */
        public function __construct(private array $values)
        {
        }

        public function get(string $path, mixed $default = null): mixed
        {
            return $this->values[$path] ?? $default;
        }
    }

    final class TestUri
    {
        /** @param array<string,mixed> $query */
        public function __construct(private string $path, private array $query)
        {
        }

        public function path(): string
        {
            return $this->path;
        }

        public function query(string $name): mixed
        {
            return $this->query[$name] ?? null;
        }
    }

    final class TestGrav implements \ArrayAccess
    {
        /** @var array<string,mixed> */
        private array $services;

        /** @param array<string,mixed> $query */
        public function __construct(string $path, array $query, TestConfig $siteConfig)
        {
            $uri = new TestUri($path, $query);
            $this->services = [
                'page' => null,
                'uri' => $uri,
                'config' => $siteConfig,
            ];
        }

        public function redirect(string $target, int $status): never
        {
            throw new RedirectSent($target, $status);
        }

        public function offsetExists(mixed $offset): bool
        {
            return array_key_exists((string) $offset, $this->services);
        }

        public function offsetGet(mixed $offset): mixed
        {
            return $this->services[(string) $offset] ?? null;
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
            $this->services[(string) $offset] = $value;
        }

        public function offsetUnset(mixed $offset): void
        {
            unset($this->services[(string) $offset]);
        }
    }

    $root = dirname(__DIR__);
    $source = (string) file_get_contents($root . '/legacy-redirects.php');
    $blueprint = (string) file_get_contents($root . '/blueprints.yaml');
    $readme = (string) file_get_contents($root . '/README.md');
    $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);

    check_contract(is_array($composer), 'composer.json non valido.');
    check_contract(($composer['name'] ?? '') === 'voidlabs/grav-plugin-legacy-redirects', 'Nome Composer errato.');
    check_contract(($composer['type'] ?? '') === 'grav-plugin', 'Tipo Composer errato.');
    check_contract(str_contains($blueprint, 'slug: legacy-redirects'), 'Slug del plugin assente.');
    check_contract(str_contains($source, "'onPagesInitialized' => ['onPagesInitialized', 1000]"), 'Priorità di redirect non esplicita.');
    check_contract(str_contains($source, 'redirect_config'), 'Mappe letterali configurabili assenti.');
    check_contract(str_contains($source, 'regex_config'), 'Regex esplicite configurabili assenti.');
    check_contract(str_contains($source, 'wildcards_config'), 'Wildcard configurabili assenti.');
    check_contract(str_contains($source, 'preserve_query'), 'Policy query non esplicita.');
    check_contract(str_contains($source, 'status < 300 || $status > 399'), 'Validazione status redirect assente.');
    check_contract(str_contains($source, "str_starts_with(\$target, '//')"), 'Blocco dei target protocol-relative assente.');
    check_contract(!str_contains($source, '$hasPattern'), 'Matching regex implicito non deve essere reintrodotto.');
    check_contract(str_contains($readme, 'map_file'), 'Formato map_file non documentato.');

    $reflection = new \ReflectionClass(\Grav\Plugin\LegacyRedirectsPlugin::class);
    $instance = $reflection->newInstanceWithoutConstructor();

    $normalizePath = $reflection->getMethod('normalizePath');
    $normalizePath->setAccessible(true);
    check_contract($normalizePath->invoke($instance, '/old/path/') === '/old/path', 'Normalizzazione path errata.');
    check_contract($normalizePath->invoke($instance, '') === '/', 'Path vuoto non normalizzato alla root.');

    $compileRegex = $reflection->getMethod('compileRegex');
    $compileRegex->setAccessible(true);
    check_contract(
        $compileRegex->invoke($instance, '/legacy/(.*)') === '~^(?:/legacy/(.*))$~u',
        'Regex non delimitata non compilata in modo deterministico.'
    );
    check_contract(
        $compileRegex->invoke($instance, '~^/legacy/(.*)$~i') === '~^/legacy/(.*)$~i',
        'Regex già delimitata alterata.'
    );

    $matches = $reflection->getMethod('matches');
    $matches->setAccessible(true);
    $oldQuery = $_SERVER['QUERY_STRING'] ?? null;
    try {
        $_SERVER['QUERY_STRING'] = 'p=133&utm=campaign';
        check_contract($matches->invoke($instance, '/?p=133', '/') === true, 'Query legacy attesa non riconosciuta.');
        check_contract($matches->invoke($instance, '/?p=134', '/') === false, 'Query legacy diversa accettata.');
        check_contract(
            $matches->invoke($instance, '/sites/example.test/repo/dist/file.js', '/sites/example.test/repo/dist/fileXjs') === false,
            'Route letterale con punto interpretata come regex.'
        );
        check_contract($matches->invoke($instance, 'https://example.test/old', '/old') === false, 'Sorgente esterna accettata.');
        check_contract($matches->invoke($instance, '/old#fragment', '/old') === false, 'Fragment nella sorgente accettato.');
    } finally {
        if ($oldQuery === null) {
            unset($_SERVER['QUERY_STRING']);
        } else {
            $_SERVER['QUERY_STRING'] = $oldQuery;
        }
    }

    $normalizeDefinitions = $reflection->getMethod('normalizeDefinitions');
    $normalizeDefinitions->setAccessible(true);
    $wildcards = $normalizeDefinitions->invoke($instance, [
        '/old/*' => '/new/$1',
        ['source' => '/other/*', 'target' => '/different/$1'],
    ]);
    check_contract(count($wildcards) === 2, 'Normalizzazione wildcard incompleta.');
    check_contract($wildcards[0]['source'] === '/old/*' && $wildcards[0]['target'] === '/new/$1', 'Wildcard associativa alterata.');
    check_contract($wildcards[1]['source'] === '/other/*', 'Wildcard strutturata alterata.');

    $normalizeRegexDefinitions = $reflection->getMethod('normalizeRegexDefinitions');
    $normalizeRegexDefinitions->setAccessible(true);
    $regexDefinitions = $normalizeRegexDefinitions->invoke($instance, [
        ['pattern' => '/legacy/(.*)', 'target' => '/new/$1'],
    ]);
    check_contract(isset($regexDefinitions['/legacy/(.*)']), 'Regex in forma lista non normalizzata.');

    $instance->config = new TestConfig([
        'plugins.legacy-redirects.pagination' => [
            'enabled' => true,
            'parameter' => 'page',
            'paths' => ['/articles'],
            'aliases' => ['/articles' => '/news'],
        ],
    ]);
    $pagination = $reflection->getMethod('redirectPagination');
    $pagination->setAccessible(true);

    $instance->grav = new TestGrav('/articles', ['page' => '1'], new TestConfig([]));
    try {
        $pagination->invoke($instance, '/articles');
        check_contract(false, 'Pagina iniziale non rediretta.');
    } catch (RedirectSent $redirect) {
        check_contract($redirect->target === '/news' && $redirect->status === 301, 'Redirect della pagina iniziale errato.');
    }

    $instance->grav = new TestGrav('/articles', ['page' => '2'], new TestConfig([]));
    try {
        $pagination->invoke($instance, '/articles');
        check_contract(false, 'Pagina successiva non rediretta.');
    } catch (RedirectSent $redirect) {
        check_contract($redirect->target === '/news/page:2' && $redirect->status === 301, 'Redirect della pagina successiva errato.');
    }

    $instance->grav = new TestGrav('/other', ['page' => '2'], new TestConfig([]));
    check_contract($pagination->invoke($instance, '/other') === false, 'Path non configurato trattato come paginazione.');

    $instance->config = new TestConfig([
        'plugins.legacy-redirects.pagination' => [
            'enabled' => true,
            'paths' => [],
            'path_config_values' => ['sites.pagination_paths'],
        ],
    ]);
    $instance->grav = new TestGrav('/configured', ['page' => '3'], new TestConfig([
        'sites.pagination_paths' => ['/configured'],
    ]));
    try {
        $pagination->invoke($instance, '/configured');
        check_contract(false, 'Path di configurazione non rediretto.');
    } catch (RedirectSent $redirect) {
        check_contract($redirect->target === '/configured/page:3', 'Redirect del path di configurazione errato.');
    }

    $redirectTarget = $reflection->getMethod('redirectTarget');
    $redirectTarget->setAccessible(true);
    $instance->grav = new TestGrav('/', [], new TestConfig([]));
    $_SERVER['QUERY_STRING'] = 'ref=1';
    try {
        $redirectTarget->invoke($instance, ['target' => '/new', 'status' => 301]);
        check_contract(false, 'Target locale valido non rediretto.');
    } catch (RedirectSent $redirect) {
        check_contract($redirect->target === '/new' && $redirect->status === 301, 'Redirect locale valido errato.');
    }
    try {
        $redirectTarget->invoke($instance, ['target' => '/new?source=1#fragment', 'status' => '302', 'preserve_query' => true]);
        check_contract(false, 'Preservazione query non eseguita.');
    } catch (RedirectSent $redirect) {
        check_contract($redirect->target === '/new?source=1&ref=1#fragment' && $redirect->status === 302, 'Preservazione query errata.');
    }

    foreach ([
        ['target' => 'https://example.test/new'],
        ['target' => '//example.test/new'],
        ['target' => '/../secret'],
        ['target' => '/bad%escape'],
        ['target' => '/new', 'status' => 299],
        ['target' => '/new', 'status' => 400],
    ] as $definition) {
        check_throws(
            static fn() => $redirectTarget->invoke($instance, $definition),
            'Target redirect non valido accettato.'
        );
    }

    if ($failures !== []) {
        foreach ($failures as $failure) {
            fwrite(STDERR, "FAIL: {$failure}" . PHP_EOL);
        }
        exit(1);
    }

    echo "OK: {$checks} legacy-redirects checks." . PHP_EOL;
}
