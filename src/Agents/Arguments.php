<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * The arguments every agent tool shares: a disk and a path on it.
 *
 * Declared once so the MCP tools and the AI SDK tool describe a document the
 * same way to every model, and validated with Laravel's own validator through
 * whichever request object the SDK hands in. Both SDKs return a validation
 * failure to the model as text, so it can correct the call rather than fail
 * the run.
 *
 * **The schema offers the allowed disks as an enum.** A model that is shown
 * the only values it may use rarely invents another; one that is shown a free
 * string guesses `local`. The guess is still refused by `DocumentAccess`, since
 * a schema is a hint to the model and not a control.
 */
final readonly class Arguments
{
    /**
     * How a path is described to the model.
     *
     * **The example is the part a model copies.** It used to read
     * `contracts/2026/deal.pdf`, and DeepSeek, asked about `deal.pdf` on the
     * `contracts` disk, tried `contracts/deal.pdf`, `contracts/2026/deal.pdf`
     * and three more, all of them the disk's name glued to the front. So the
     * examples here name no disk, and the sentence says why.
     */
    public const string PATH = 'The document\'s path relative to the root of that disk, ending in .pdf. '
        . 'Do not repeat the disk\'s name in it: a document at the top of the disk is just its file name, '
        . 'such as deal.pdf, and one inside a folder is folder/deal.pdf.';

    /**
     * @param  list<string>  $disks
     * @return array<string, Type>
     */
    public static function document(JsonSchema $schema, array $disks): array
    {
        return [
            'disk' => self::disk($schema, $disks, 'The disk the document is stored on.')->required(),
            'path' => $schema->string()
                ->description(self::PATH)
                ->required(),
        ];
    }

    /**
     * @param  list<string>  $disks
     */
    public static function disk(JsonSchema $schema, array $disks, string $description): Type
    {
        $disk = $schema->string()->description($description);

        // An empty enum is a schema no value satisfies, which some providers
        // reject outright. With nothing open the tool refuses every call
        // anyway, and says why.
        return $disks === [] ? $disk : $disk->enum($disks);
    }

    /**
     * @return array<string, string>
     */
    public static function documentRules(): array
    {
        return [
            'disk' => 'required|string',
            'path' => 'required|string',
        ];
    }

    /**
     * A validated argument, as the string its rule already guaranteed.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function string(array $validated, string $key): ?string
    {
        $value = $validated[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
