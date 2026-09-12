<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Commands;

use Illuminate\Console\Command;
use LSNepomuceno\LaravelA1PdfSign\Commands\Concerns\ReadsTypedInput;
use LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign;
use Throwable;

/**
 * Renews a B-LTA document before its archive timestamp ages out.
 *
 * The one operation in the package that is time-driven rather than
 * request-driven, which is exactly why it belongs on the command line: it is
 * what a scheduled job calls.
 */
class ExtendArchiveCommand extends Command
{
    use ReadsTypedInput;

    protected $signature = 'pdf:extend
                           {pdfPath : The path to the signed PDF file}
                           {output? : Where to write, defaulting to the document name}';

    protected $description = 'Append a fresh archive timestamp to a signed PDF document';

    public function handle(A1PdfSign $signing): int
    {
        try {
            $document = $signing->extendArchive($this->stringArgument('pdfPath'));

            $path = $document->save($this->outputPath($document->name()));

            $this->line("The archive has been extended and the document is at: \"{$path}\"", 'info');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->line("Could not extend the archive: {$exception->getMessage()}", 'error');

            return self::FAILURE;
        }
    }

    /**
     * Where to write, defaulting to the name the document carries.
     */
    private function outputPath(string $default): string
    {
        $output = $this->stringArgument('output');

        return $output === '' ? $default : $output;
    }
}
