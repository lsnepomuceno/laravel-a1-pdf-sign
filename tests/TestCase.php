<?php

namespace LSNepomuceno\LaravelA1PdfSign\Tests;

use Illuminate\Support\Facades\File;
use LSNepomuceno\LaravelA1PdfSign\LaravelA1PdfSignServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function tearDown(): void
    {
        $path = dirname(__DIR__) . '/src/Temp/';
        if (File::exists($path)) {
            $files = File::files($path);

            foreach ($files as $file) {
                if ($file->getFilename() !== '.gitkeep') {
                    File::delete($file->getPathname());
                }
            }
        }
        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            LaravelA1PdfSignServiceProvider::class,
        ];
    }
}
