<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Mcp\Tools;

use Illuminate\Container\Container;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\{Request, Response, ResponseFactory};
use Laravel\Mcp\Server\Attributes\{Description, Name, Title};
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\{IsIdempotent, IsOpenWorld, IsReadOnly};
use LSNepomuceno\LaravelA1PdfSign\Agents\{Arguments, DocumentAccess};
use LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign;
use LSNepomuceno\Signet\Data\SignatureField;
use LSNepomuceno\Signet\Exceptions\SignetException;

/**
 * The signature fields a document declares, signed or not.
 *
 * What an agent needs before it can say "the manager has not signed yet": a
 * template arrives with its fields placed, and the names are how a person and
 * the engine both address them. It is `A1PdfSign::signatureFields()`, read
 * from an allowed disk, and like `pdf:fields` it only reads
 * (docs/decisions/0013-signing-into-an-existing-field.md,
 * docs/decisions/0040-agents-read-through-mcp-and-sign-through-the-ai-sdk.md).
 */
#[Name('list_signature_fields')]
#[Title('List signature fields')]
#[Description(
    'Lists the signature fields a PDF stored on one of the application\'s disks declares: each field\'s name, '
    . 'whether it is already signed, the page it sits on and whether it is visible. Read-only: it changes nothing.',
)]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class ListSignatureFields extends Tool
{
    /**
     * @return array<string, Type>
     */
    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return Arguments::document($schema, Container::getInstance()->make(DocumentAccess::class)->disks());
    }

    /**
     * @return array<string, Type>
     */
    #[\Override]
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'disk' => $schema->string()->required(),
            'path' => $schema->string()->required(),
            'field_count' => $schema->integer()->required(),
            'unsigned_count' => $schema->integer()->required(),
            'fields' => $schema->array()->items($schema->object([
                'name' => $schema->string()->description('The name the field is addressed by.'),
                'signed' => $schema->boolean(),
                'page' => $schema->integer()->description('One-based. Zero when the field declares no page.'),
                'visible' => $schema->boolean()->description('False for a field with no area, which signs without a seal.'),
            ]))->required(),
        ];
    }

    /**
     * @throws \Illuminate\Validation\ValidationException When the arguments
     *                                                     are malformed.
     */
    public function handle(Request $request, A1PdfSign $signing, DocumentAccess $documents): Response|ResponseFactory
    {
        $arguments = $request->validate(Arguments::documentRules());
        $disk = (string) Arguments::string($arguments, 'disk');
        $path = (string) Arguments::string($arguments, 'path');

        try {
            $fields = $signing->signatureFields($documents->source($disk, $path));
        } catch (SignetException $exception) {
            return Response::error("The document's fields could not be read: {$exception->getMessage()}");
        }

        return Response::structured([
            'disk' => $disk,
            'path' => $path,
            'field_count' => count($fields),
            'unsigned_count' => count(array_filter($fields, static fn(SignatureField $field): bool => ! $field->isSigned)),
            'fields' => array_map(static fn(SignatureField $field): array => [
                'name' => $field->name,
                'signed' => $field->isSigned,
                'page' => $field->pageNumber,
                'visible' => $field->isVisible(),
            ], $fields),
        ]);
    }
}
