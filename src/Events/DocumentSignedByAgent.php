<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Events;

use LSNepomuceno\Signet\Data\SigningReceipt;

/**
 * An agent signed a document, after a human approved it.
 *
 * Dispatched by `Ai\Tools\SignPdf` once the signed copy is written, and never
 * for a call that failed or was answered from the ledger. What an application
 * does with it is its own business: an audit table, a notification to the
 * signer, a webhook. The package only makes sure the fact is not lost
 * (docs/decisions/0040-agents-read-through-mcp-and-sign-through-the-ai-sdk.md).
 *
 * **Everything here is safe to store.** The receipt carries no PDF, no key and
 * no password, only what signing knew and two digests. The user is an id
 * rather than a model, so the event serialises onto a queue without dragging a
 * model behind it.
 */
final readonly class DocumentSignedByAgent
{
    /**
     * @param  string|null  $toolCallId  The provider's id for the approved call,
     *          which is also the key the ledger deduplicated on. Null when the
     *          tool was invoked outside an agent run.
     * @param  int|string|null  $userId  The authenticated user when the tool
     *          ran, from the default guard. Null when nobody was.
     * @param  SigningReceipt|null  $receipt  What signing did, digests
     *          included. Null only if the engine produced no receipt.
     */
    public function __construct(
        public string $sourceDisk,
        public string $sourcePath,
        public string $disk,
        public string $path,
        public ?string $toolCallId,
        public int|string|null $userId,
        public ?SigningReceipt $receipt,
    ) {}
}
