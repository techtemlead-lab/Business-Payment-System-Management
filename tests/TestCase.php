<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): \Illuminate\Foundation\Application
    {
        // Docker process variables have priority in dotenv. Set the test process
        // explicitly before bootstrap; never allow RefreshDatabase on the demo DB.
        foreach(['DB_CONNECTION'=>'pgsql','DB_DATABASE'=>'madina_testing','APP_ENV'=>'testing','MAIL_MAILER'=>'array','SESSION_DRIVER'=>'array','CACHE_STORE'=>'array','QUEUE_CONNECTION'=>'sync'] as $key=>$value) {
            putenv($key.'='.$value); $_ENV[$key]=$value; $_SERVER[$key]=$value;
        }
        $app=parent::createApplication();
        if($app->make('config')->get('database.connections.pgsql.database')!=='madina_testing') {
            throw new \RuntimeException('Tests must use the isolated madina_testing database.');
        }
        return $app;
    }
}
