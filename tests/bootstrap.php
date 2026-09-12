<?php

/**
 * Confirmed incident: this app's bootstrap/cache/config.php (from a routine
 * `php artisan config:cache` on a real deploy) freezes config() at whatever
 * .env said when it was built — including DB_DATABASE. Laravel trusts that
 * cache unconditionally and never re-reads .env or PHPUnit's own <env>
 * overrides while it exists, so `php artisan test` silently resolved the
 * *production* database even with DB_DATABASE=testing set in phpunit.xml.
 * Every Feature test using RefreshDatabase calls migrate:fresh on first use
 * per process, which drops every table — against whatever database that
 * cache pointed at.
 *
 * Deleting the cache here, before Laravel boots for tests, removes the
 * failure mode entirely rather than relying on remembering to run
 * `config:clear` by hand before every test run on every machine this runs
 * on. The safety check below is a second, independent layer: it aborts the
 * whole suite before any test class loads if the resolved database still
 * doesn't look like an isolated test database, for whatever reason.
 */
$root = dirname(__DIR__);

@unlink("{$root}/bootstrap/cache/config.php");

require "{$root}/vendor/autoload.php";

$app = require "{$root}/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$connection = $app['config']->get('database.default');
$database = (string) $app['config']->get("database.connections.{$connection}.database");

$looksLikeTestDatabase = $database === ':memory:' || str_contains(strtolower($database), 'testing');

if (! $looksLikeTestDatabase) {
    fwrite(STDERR, <<<TEXT

    ============================================================
     REFUSING TO RUN TESTS
    ============================================================
     The resolved database connection is '{$database}', which
     does not look like an isolated test database.

     Tests use RefreshDatabase, which runs migrate:fresh and
     drops every table in whatever database this resolves to.
     Aborting before any test class loads to avoid touching a
     real database.

     Check bootstrap/cache/config.php (should not exist during
     tests), phpunit.xml's DB_DATABASE override, and .env.
    ============================================================

    TEXT);

    exit(1);
}
