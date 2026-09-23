<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Ai\Tools;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Carbon;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Contracts\{Approvable, Tool};
use Laravel\Ai\Tools\Request;
use LogicException;
use LSNepomuceno\LaravelA1PdfSign\Agents\{Ability, Arguments, DocumentAccess, ToolCallLedger};
use LSNepomuceno\LaravelA1PdfSign\Contracts\{A1PdfSign, SigningCertificateResolver};
use LSNepomuceno\LaravelA1PdfSign\Events\DocumentSignedByAgent;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\SigningCertificateUnavailable;
use LSNepomuceno\Signet\Data\{Certificate, Signer};
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\Exceptions\SignetException;
use Throwable;

/**
 * Signs a document on an agent's behalf, and only after a human approves it.
 *
 * **A digital signature made with an ICP-Brasil certificate has the legal
 * weight of a handwritten one.** So this tool is built around four refusals,
 * each of which is tested
 * (docs/decisions/0040-agents-read-through-mcp-and-sign-through-the-ai-sdk.md):
 *
 * 1. **Approval cannot be switched off.** `shouldRequestApproval()` always
 *    answers with an `Approval`, and `withoutApproval()` throws. The SDK pauses
 *    the run on every call and hands the application a description of exactly
 *    what would be signed, with which certificate, to show a person.
 * 2. **The certificate never passes through the model.** The schema takes a
 *    document, a destination, a profile and a reason. The key that signs is
 *    whatever `Contracts\SigningCertificateResolver` returns.
 * 3. **A repeated call signs once.** The provider's tool-call id is claimed in
 *    `Agents\ToolCallLedger` before anything is signed, and a second call with
 *    the same id gets the first call's result back.
 * 4. **Only the disks the application opened, and never over an existing
 *    file.** Every path goes through `Agents\DocumentAccess`, which also asks
 *    the application's `Gate` for `Agents\Ability::Sign` when it defines it.
 *
 * What it cannot see is code that calls `handle()` itself, or wraps the tool
 * in something that is not `Approvable`. Approval is the agent loop's to
 * enforce; this makes sure the loop always asks.
 *
 * Once signed, `Events\DocumentSignedByAgent` is dispatched with the receipt.
 *
 * ```php
 * public function tools(): iterable
 * {
 *     return [
 *         new ValidatePdfSignature,
 *         new SignPdf,
 *     ];
 * }
 * ```
 */
final class SignPdf implements Approvable, Tool
{
    /**
     * Prepended to the description of the call, when the application gave one.
     */
    private ?string $approvalNote = null;

    /**
     * @param  SigningCertificateResolver|null  $certificates  Which certificate
     *          signs. Null resolves the contract from the container, which is
     *          where an application binds it once.
     */
    public function __construct(
        private readonly ?SigningCertificateResolver $certificates = null,
    ) {}

    /**
     * The name the model calls it by, matching the MCP tools' snake case.
     */
    public function name(): string
    {
        return 'sign_pdf';
    }

    #[\Override]
    public function description(): string
    {
        return 'Digitally signs a PDF stored on one of the application\'s disks, with the certificate the application '
            . 'holds for the current user, and writes the signed copy beside it. A person has to approve every call '
            . 'before anything is signed. It never overwrites a file, and calling it again with the same call signs '
            . 'nothing new.';
    }

    /**
     * @return array<string, Type>
     */
    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        $disks = self::container()->make(DocumentAccess::class)->disks();

        return [
            ...Arguments::document($schema, $disks),
            'destination_disk' => Arguments::disk(
                $schema,
                $disks,
                'The disk to write the signed copy to. Leave it out to use the document\'s own disk.',
            ),
            'destination_path' => $schema->string()->description(
                'Where to write the signed copy, relative to the destination disk and ending in .pdf. Leave it out '
                . 'to write it beside the original with _signed appended to the name. It must not exist yet.',
            ),
            'profile' => $schema->string()
                ->enum(array_map(static fn(SignatureProfile $profile): string => $profile->value, SignatureProfile::cases()))
                ->description('The PAdES level to sign at. Leave it out to use the application\'s configured profile.'),
            'reason' => $schema->string()->max(255)->description(
                'Why the document is being signed, recorded inside the signature. For example: Contract approval',
            ),
        ];
    }

    /**
     * Signs, or explains to the model why it did not.
     *
     * A refusal the model can act on, a closed disk or an occupied path, comes
     * back as the tool's result so the model can correct itself. A wiring
     * mistake, no certificate resolver bound, is thrown instead: that is for
     * the developer to see, not for the model to work around.
     *
     * @throws SigningCertificateUnavailable When no resolver is bound.
     * @throws \Illuminate\Validation\ValidationException When the arguments
     *                                                     are malformed, which
     *                                                     the SDK returns to
     *                                                     the model.
     * @throws Throwable Whatever signing raised that is not the package's own.
     */
    #[\Override]
    public function handle(Request $request): string
    {
        $arguments = $request->validate(self::rules());
        $toolCallId = $request->toolCallId();
        $ledger = self::container()->make(ToolCallLedger::class);

        if ($toolCallId !== null && ! $ledger->claim($toolCallId)) {
            return $ledger->result($toolCallId) ?? self::json([
                'signed' => false,
                'status' => 'in_progress',
                'message' => 'This call is already being signed. Do not repeat it: the first call carries the result.',
            ]);
        }

        $written = false;

        try {
            [$result, $signed] = $this->sign($arguments, $toolCallId);
            $written = true;
        } catch (SigningCertificateUnavailable $exception) {
            throw $exception;
        } catch (AuthorizationException $exception) {
            return self::json([
                'signed' => false,
                'status' => 'refused',
                'message' => "You may not sign this document: {$exception->getMessage()}",
            ]);
        } catch (SignetException $exception) {
            return self::json([
                'signed' => false,
                'status' => 'refused',
                'message' => $exception->getMessage(),
            ]);
        } finally {
            // Nothing reached the disk, so the id is given back and a retry is
            // free to sign.
            if (! $written && $toolCallId !== null) {
                $ledger->release($toolCallId);
            }
        }

        // Recorded before anybody is told, so a listener that throws cannot
        // leave a signed copy on the disk with its call still unclaimed.
        if ($toolCallId !== null) {
            $ledger->complete($toolCallId, $result);
        }

        self::container()->make(Dispatcher::class)->dispatch($signed);

        return $result;
    }

    /**
     * Always asks. This is the whole point of the class.
     */
    #[\Override]
    public function shouldRequestApproval(Request $request): Approval
    {
        return Approval::required($this->describe($request));
    }

    /**
     * Adds the application's own words above the description of the call.
     *
     * The description of what would be signed is always there; this only
     * puts something in front of it, such as the name of the workflow asking.
     */
    #[\Override]
    public function requireApproval(?string $reason = null): static
    {
        $this->approvalNote = $reason;

        return $this;
    }

    /**
     * Refused, always.
     *
     * @throws LogicException
     */
    #[\Override]
    public function withoutApproval(): static
    {
        throw new LogicException(
            'SignPdf cannot run without approval: every signature it makes needs a person to approve it. '
            . 'Sign in your own code, through the facade\'s newSignature(), when no person is involved.',
        );
    }

    /**
     * @return array<string, string>
     */
    private static function rules(): array
    {
        return [
            ...Arguments::documentRules(),
            'destination_disk' => 'nullable|string',
            'destination_path' => 'nullable|string',
            'profile' => 'nullable|string|in:' . implode(',', array_map(
                static fn(SignatureProfile $profile): string => $profile->value,
                SignatureProfile::cases(),
            )),
            'reason' => 'nullable|string|max:255',
        ];
    }

    /**
     * Signs and writes, and returns what to tell the model and the event to
     * announce it with. The event is not dispatched here: `handle()` records
     * the call first.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{0: string, 1: DocumentSignedByAgent}
     *
     * @throws Throwable
     */
    private function sign(array $arguments, ?string $toolCallId): array
    {
        $container = self::container();
        $documents = $container->make(DocumentAccess::class);

        $disk = (string) Arguments::string($arguments, 'disk');
        $path = (string) Arguments::string($arguments, 'path');
        $destinationDisk = Arguments::string($arguments, 'destination_disk') ?? $disk;
        $destinationPath = Arguments::string($arguments, 'destination_path') ?? DocumentAccess::signedName($path);
        $profile = Arguments::string($arguments, 'profile');
        $reason = Arguments::string($arguments, 'reason');

        // The gate is asked before either end is looked at, so a user who may
        // not sign learns nothing about what exists. Both ends are checked
        // before the certificate is opened, so a closed disk or an occupied
        // destination costs nothing to refuse.
        $documents->authorize(Ability::Sign, $disk, $path, $destinationDisk, $destinationPath);
        $source = $documents->source($disk, $path);
        $destination = $documents->destination($destinationDisk, $destinationPath);
        $certificate = $this->certificate();

        $pending = $container->make(A1PdfSign::class)
            ->newSignature()
            ->usingCertificate($certificate)
            ->from($source);

        if ($profile !== null) {
            $pending = $pending->profile($profile);
        }

        if ($reason !== null) {
            $pending = $pending->info(reason: $reason);
        }

        $signed = $pending->sign();
        $written = $signed->writeTo($destination);
        $receipt = $signed->receipt();

        $event = new DocumentSignedByAgent(
            sourceDisk: $disk,
            sourcePath: $path,
            disk: $destinationDisk,
            path: $written,
            toolCallId: $toolCallId,
            userId: self::userId(),
            receipt: $receipt,
        );

        return [self::json([
            'signed' => true,
            'status' => 'signed',
            'disk' => $destinationDisk,
            'path' => $written,
            'source' => ['disk' => $disk, 'path' => $path],
            'signer' => Signer::fromParsedCertificate($certificate->data)->name(),
            'profile' => $receipt?->profile?->value,
            'size' => $receipt?->size,
            'revision_size' => $receipt?->revisionSize(),
            'sha256' => $receipt?->hash,
        ]), $event];
    }

    /**
     * @throws SigningCertificateUnavailable
     * @throws Throwable Whatever the resolver raised.
     */
    private function certificate(): Certificate
    {
        if ($this->certificates !== null) {
            return $this->certificates->resolve();
        }

        $container = self::container();

        if (! $container->bound(SigningCertificateResolver::class)) {
            throw SigningCertificateUnavailable::unbound();
        }

        return $container->make(SigningCertificateResolver::class)->resolve();
    }

    /**
     * What the person approving is shown, from the arguments as the model
     * sent them.
     *
     * Built from the raw arguments, before validation, because the SDK asks
     * for approval before the tool runs. Anything missing is described as
     * missing rather than guessed at: the call will fail validation after
     * approval, and the person should be able to see that coming.
     */
    private function describe(Request $request): string
    {
        $arguments = $request->all();
        $disk = Arguments::string($arguments, 'disk') ?? '(no disk given)';
        $path = Arguments::string($arguments, 'path') ?? '(no path given)';
        $destinationDisk = Arguments::string($arguments, 'destination_disk') ?? $disk;
        $destinationPath = Arguments::string($arguments, 'destination_path') ?? DocumentAccess::signedName($path);
        $profile = Arguments::string($arguments, 'profile');
        $reason = Arguments::string($arguments, 'reason');

        $allowed = self::container()->make(DocumentAccess::class)
            ->allows(Ability::Sign, $disk, $path, $destinationDisk, $destinationPath);

        $lines = array_filter([
            $this->approvalNote,
            $allowed ? null : 'Your application does not allow you to sign this document, so the call will be refused.',
            "Sign [{$path}] on the disk [{$disk}] with {$this->describeCertificate()}.",
            $profile === null ? 'Profile: the application\'s configured default.' : "Profile: {$profile}.",
            $reason === null ? null : "Reason recorded in the signature: \"{$reason}\".",
            "The signed copy is written to [{$destinationPath}] on the disk [{$destinationDisk}].",
        ], static fn(?string $line): bool => $line !== null && $line !== '');

        return implode("\n", $lines);
    }

    /**
     * Whose certificate signs, as the person approving would recognise it.
     *
     * Their name and, for an ICP-Brasil certificate, the CPF or CNPJ, which is
     * how a Brazilian signer tells two certificates with the same name apart.
     * This text goes to the person approving, not to the model.
     */
    private function describeCertificate(): string
    {
        try {
            $certificate = $this->certificate();
        } catch (Throwable $exception) {
            return "a certificate the application could not open, so signing will fail ({$exception->getMessage()})";
        }

        $signer = Signer::fromParsedCertificate($certificate->data, $certificate->original);
        $registry = $signer->icpBrasil?->formattedRegistry();
        $expiresAt = $certificate->expiresAt();

        $description = 'the certificate of ' . ($signer->name() ?? 'an unnamed holder');
        $description .= $registry === null ? '' : " ({$registry})";

        if ($expiresAt !== null) {
            $date = Carbon::createFromTimestampUTC($expiresAt)->toDateString();
            $description .= $certificate->isExpired() ? ", which EXPIRED on {$date}" : ", valid until {$date}";
        }

        return $description;
    }

    private static function userId(): int|string|null
    {
        $container = self::container();

        return $container->bound(Auth::class) ? $container->make(Auth::class)->guard()->id() : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Tools are built with `new` inside an agent's `tools()`, so what they
     * need comes from the container when they run rather than through the
     * constructor.
     */
    private static function container(): Container
    {
        return Container::getInstance();
    }
}
