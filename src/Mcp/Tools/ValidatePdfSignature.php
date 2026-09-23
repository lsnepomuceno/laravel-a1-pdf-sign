<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Mcp\Tools;

use Illuminate\Container\Container;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Carbon;
use Laravel\Mcp\{Request, Response, ResponseFactory};
use Laravel\Mcp\Server\Attributes\{Description, Name, Title};
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\{IsIdempotent, IsOpenWorld, IsReadOnly};
use LSNepomuceno\LaravelA1PdfSign\Agents\{Arguments, DocumentAccess};
use LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign;
use LSNepomuceno\Signet\Data\{SignatureDetails, SignatureReport};
use LSNepomuceno\Signet\Enums\ValidationFinding;
use LSNepomuceno\Signet\Exceptions\SignetException;

/**
 * Whether a document's signatures verify, and who made them.
 *
 * The question an agent is asked most about a signed contract, answered from
 * `A1PdfSign::validate()` and nothing else: the tool reads the document from an
 * allowed disk, hands it to the engine and reports what the engine found. It
 * writes nothing and reaches no network, since validation fetches nothing
 * (docs/decisions/0040-agents-read-through-mcp-and-sign-through-the-ai-sdk.md).
 *
 * **It is an MCP tool and an AI SDK tool at once.** An MCP client reaches it
 * through `Mcp\A1PdfSignServer`; an AI SDK agent returns `new
 * ValidatePdfSignature` from `tools()` and the SDK wraps it, handing
 * structured content back to the model as JSON.
 *
 * No trust store is involved, so `trusted` is always null: whom to trust is
 * the application's policy, and an application that has one validates with it
 * in its own tool.
 *
 * **A document with no signature is an error, not `signed: false`.** The
 * engine raises for it, and with the same exception it raises for a signature
 * it cannot parse, so the tool cannot tell the two apart and does not pretend
 * to: it relays the engine's own sentence.
 */
#[Name('validate_pdf_signature')]
#[Title('Validate PDF signatures')]
#[Description(
    'Verifies every digital signature in a PDF stored on one of the application\'s disks, and reports who signed, '
    . 'whether each signature verifies against the bytes it covers, the PAdES profile it satisfies, when it was '
    . 'signed and timestamped, and every finding the validator raised. A document carrying no signature it can '
    . 'read is reported as an error saying so. Read-only: it changes nothing.',
)]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class ValidatePdfSignature extends Tool
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
        $signature = $schema->object([
            'signer' => $schema->string()->nullable()->description('The signer\'s name, without the registry number some certificates append to it.'),
            'registry' => $schema->string()->nullable()->description('CPF or CNPJ, formatted. Null unless the application exposes it.'),
            'verified' => $schema->boolean()->description('Whether the CMS verifies against the bytes it covers.'),
            'is_timestamp' => $schema->boolean()->description('A document timestamp rather than a signature by a person.'),
            'covers_whole_document' => $schema->boolean(),
            'profile' => $schema->string()->nullable()->description('The PAdES level the signature actually satisfies.'),
            'signed_at' => $schema->string()->nullable()->description('The time the signer\'s own clock claimed, ISO 8601.'),
            'attested_at' => $schema->string()->nullable()->description('The time a verified timestamp attests, ISO 8601.'),
            'revocation' => $schema->string()->enum(['good', 'revoked', 'unknown']),
            'findings' => $schema->array()->items($schema->string()),
        ]);

        return [
            'disk' => $schema->string()->required(),
            'path' => $schema->string()->required(),
            'signed' => $schema->boolean()->required()->description('Whether the document carries any signature at all.'),
            'valid' => $schema->boolean()->required()->description('Whether every signature verifies. False for an unsigned document.'),
            'signature_count' => $schema->integer()->required(),
            'certified' => $schema->boolean()->required(),
            'certification_level' => $schema->string()->nullable()->required(),
            'accepts_further_signatures' => $schema->boolean()->required(),
            'trusted' => $schema->boolean()->nullable()->required()->description('Always null: no trust store was consulted.'),
            'document_findings' => $schema->array()->items($schema->string())->required(),
            'signatures' => $schema->array()->items($signature)->required(),
        ];
    }

    /**
     * @throws \Illuminate\Validation\ValidationException When the arguments
     *                                                     are malformed, which
     *                                                     MCP reports back to
     *                                                     the client.
     */
    public function handle(Request $request, A1PdfSign $signing, DocumentAccess $documents): Response|ResponseFactory
    {
        $arguments = $request->validate(Arguments::documentRules());
        $disk = (string) Arguments::string($arguments, 'disk');
        $path = (string) Arguments::string($arguments, 'path');

        try {
            $report = $signing->validate($documents->source($disk, $path));
        } catch (SignetException $exception) {
            return Response::error("The document was not validated: {$exception->getMessage()}");
        }

        return Response::structured([
            'disk' => $disk,
            'path' => $path,
            ...self::summary($report, $documents->exposesRegistry()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function summary(SignatureReport $report, bool $exposeRegistry): array
    {
        return [
            'signed' => $report->isSigned(),
            'valid' => $report->isValid(),
            'signature_count' => $report->count(),
            'certified' => $report->isCertified(),
            'certification_level' => $report->certification?->value,
            'accepts_further_signatures' => $report->acceptsFurtherSignatures(),
            'trusted' => $report->isTrusted(),
            'document_findings' => self::findings($report->documentFindings),
            'signatures' => array_map(
                static fn(SignatureDetails $signature): array => self::signature($signature, $exposeRegistry),
                $report->signatures,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function signature(SignatureDetails $signature, bool $exposeRegistry): array
    {
        $signer = $signature->signer();

        return [
            'signer' => $signer?->name(),
            'registry' => $exposeRegistry ? $signer?->icpBrasil?->formattedRegistry() : null,
            'verified' => $signature->verified,
            'is_timestamp' => $signature->isTimestamp,
            'covers_whole_document' => $signature->coversWholeDocument,
            'profile' => $signature->profile?->value,
            'signed_at' => self::time($signature->signedAt),
            'attested_at' => self::time($signature->attestedAt()),
            'revocation' => $signature->revocation->value,
            'findings' => self::findings($signature->findings()),
        ];
    }

    /**
     * @param  list<ValidationFinding>  $findings
     * @return list<string>
     */
    private static function findings(array $findings): array
    {
        return array_map(static fn(ValidationFinding $finding): string => $finding->value, $findings);
    }

    private static function time(?int $timestamp): ?string
    {
        return $timestamp === null ? null : Carbon::createFromTimestampUTC($timestamp)->toIso8601String();
    }
}
