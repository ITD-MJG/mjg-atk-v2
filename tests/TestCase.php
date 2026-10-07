<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->guardAgainstNonTestDatabase();
    }

    /**
     * Refuse to run tests against anything but an in-memory/throwaway database.
     *
     * A cached bootstrap/cache/config.php silently overrides phpunit.xml's
     * <env> values, so tests can otherwise connect to the developer database
     * declared in .env and RefreshDatabase will wipe it. This has happened.
     */
    protected function guardAgainstNonTestDatabase(): void
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        $isInMemory = $database === ':memory:';
        $isTestDatabase = is_string($database) && str_ends_with($database, '_test');

        if ($isInMemory || $isTestDatabase) {
            return;
        }

        throw new \RuntimeException(
            "Refusing to run tests against '{$database}' on the '{$connection}' connection.\n".
            "Tests are destructive (RefreshDatabase). Run `php artisan config:clear` and ensure\n".
            "tests use sqlite :memory: (see phpunit.xml), or name the database with\n".
            'a `_test` suffix.'
        );
    }
}
