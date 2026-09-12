<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Commands;

use Illuminate\Console\Command;
use LSNepomuceno\LaravelA1PdfSign\Commands\Concerns\ReadsTypedInput;
use LSNepomuceno\Signet\Data\SealPlacement;
use LSNepomuceno\Signet\Signet;
use Throwable;

/**
 * Adds an empty signature field, which is the half of templates this package
 * could only consume before: it could fill a field somebody else placed, and
 * not place one.
 */
class AddSignatureFieldCommand extends Command
{
    use ReadsTypedInput;

    protected $signature = 'pdf:add-field
                           {pdfPath : The path to the PDF file}
                           {name : The field name, which is how it is addressed when it is filled}
                           {output? : Where to write, defaulting next to the input}
                           {--x= : Left edge, in points}
                           {--y= : Bottom edge, in points}
                           {--width= : Width, in points}
                           {--height= : Height, in points}
                           {--page= : The page to place it on, 1-based}
                           {--password= : The password an encrypted document was produced with}';

    protected $description = 'Add an empty signature field to a PDF document';

    public function handle(Signet $signet): int
    {
        try {
            $document = $signet->addSignatureField(
                $this->stringArgument('pdfPath'),
                $this->stringArgument('name'),
                $this->placement(),
                $this->stringOption('password') ?? '',
            );

            $path = $document->save($this->outputPath($document->name()));

            $this->line("The field has been added and the document is at: \"{$path}\"", 'info');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->line("Could not add the field: {$exception->getMessage()}", 'error');

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

    /**
     * Where the field goes, or null to leave it invisible.
     *
     * An invisible field is the default because it is the safe one: a
     * placement is only meaningful against a page whose size the caller knows,
     * and a field dropped at a guessed rectangle lands over the text.
     */
    private function placement(): ?SealPlacement
    {
        $x = $this->floatOption('x');
        $y = $this->floatOption('y');

        if ($x === null || $y === null) {
            return null;
        }

        // Built once with every option resolved, rather than rebuilt per
        // option: SealPlacement is readonly, and a chain of copies is three
        // chances to drop a field that was already set.
        $defaults = new SealPlacement();

        return new SealPlacement(
            x: $x,
            y: $y,
            width: $this->floatOption('width') ?? $defaults->width,
            height: $this->floatOption('height') ?? $defaults->height,
            page: $this->intOption('page') ?? $defaults->page,
        );
    }
}
