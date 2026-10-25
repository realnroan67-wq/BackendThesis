<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /** Guard before test traits can run migrations or reset any database. */
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $configuration = $app->make('config');

        if (! $app->environment('testing')
            || $configuration->get('database.default') !== 'pgsql'
            || $configuration->get('database.connections.pgsql.database') !== 'horizon_realty_test'
            || $configuration->get('database.connections.pgsql.username') !== 'horizon_test'
            || $configuration->get('database.connections.pgsql.url')) {
            throw new RuntimeException('Feature tests require the isolated horizon_realty_test database and horizon_test role.');
        }

        return $app;
    }
}
