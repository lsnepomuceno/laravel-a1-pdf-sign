<?php

declare(strict_types=1);

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

    /**
     * The package, plus the two optional SDKs when they are installed.
     *
     * An application discovers them; Testbench does not. They are registered
     * only when present because CI also runs the suite with both removed, to
     * prove the package installs and boots without them
     * (docs/decisions/0040-agents-read-through-mcp-and-sign-through-the-ai-sdk.md).
     */
    protected function getPackageProviders($app): array
    {
        return array_values(array_filter([
            LaravelA1PdfSignServiceProvider::class,
            'Laravel\Ai\AiServiceProvider',
            'Laravel\Mcp\Server\McpServiceProvider',
        ], class_exists(...)));
    }
}
