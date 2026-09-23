<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Exceptions;

use InvalidArgumentException;
use LSNepomuceno\Signet\Exceptions\SignetException;

/**
 * An agent asked for a document the application did not make reachable.
 *
 * Raised by `Agents\DocumentAccess` before any byte is read or written: a disk
 * missing from `a1-pdf-sign.agents.disks`, a path that climbs out of the disk
 * with `..`, an absolute path, a file that is not a PDF, a document larger
 * than `a1-pdf-sign.agents.max_bytes`, or a destination that already exists.
 *
 * **The message is written to be read by a model.** Every agent tool returns
 * it verbatim as the tool's error, so it names what was refused and what would
 * be accepted, and never the disk's root or anything else the application did
 * not already hand the agent
 * (docs/decisions/0040-agents-read-through-mcp-and-sign-through-the-ai-sdk.md).
 *
 * It implements signet-pdf's `SignetException` so that an application catching
 * every failure of the package as a group keeps catching this one too.
 */
final class DocumentOutOfReach extends InvalidArgumentException implements SignetException
{
    /**
     * @param  list<string>  $allowed
     */
    public static function disk(string $disk, array $allowed): self
    {
        $offer = $allowed === []
            ? 'No disk is open to agents: the application has to list one in a1-pdf-sign.agents.disks.'
            : 'The disks open to agents are: ' . implode(', ', $allowed) . '.';

        return new self("The disk [{$disk}] is not open to agents. {$offer}");
    }

    public static function path(string $path, string $why): self
    {
        return new self("The path [{$path}] was refused: {$why}");
    }

    public static function missing(string $disk, string $path): self
    {
        return new self("There is no document at [{$path}] on the disk [{$disk}].");
    }

    public static function tooLarge(string $disk, string $path, int $limit): self
    {
        // Not Number::fileSize(), which formats through ext-intl, and a
        // message about a refused document should not need an extension the
        // package does not require.
        $size = $limit >= 1_048_576 ? round($limit / 1_048_576, 1) . ' MB' : "{$limit} bytes";

        return new self(
            "The document at [{$path}] on the disk [{$disk}] is larger than the {$size} agents may work with.",
        );
    }

    public static function occupied(string $disk, string $path): self
    {
        return new self(
            "A file already exists at [{$path}] on the disk [{$disk}], and a signed document never overwrites one. "
            . 'Choose another destination path.',
        );
    }
}
