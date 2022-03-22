<?php

namespace LSNepomuceno\LaravelA1PdfSign\Tests;

use LSNepomuceno\LaravelA1PdfSign\LaravelA1PdfSignServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelA1PdfSignServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        //
    }
}
