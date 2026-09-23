<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Agents;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\DocumentOutOfReach;
use LSNepomuceno\LaravelA1PdfSign\Io\{DiskDestination, DiskSource};

/**
 * The only way an agent reaches a document.
 *
 * **A tool that accepts a disk and a path from a model is a file-reading
 * primitive**, and whoever writes the prompt decides what it reads. So every
 * agent tool in this package, the MCP ones and the AI SDK one, turns the
 * model's arguments into a source or a destination here and nowhere else, and
 * this refuses anything the application did not open on purpose:
 *
 * - a disk missing from `a1-pdf-sign.agents.disks`, which is empty by default,
 *   so a fresh install reaches nothing at all;
 * - an absolute path, a path with `..` in it, a backslash or a null byte;
 * - a file that is not a PDF;
 * - a destination that already exists, since a signed copy never overwrites.
 *
 * `..` is refused outright rather than normalised. Flysystem would normalise
 * `a/../b` into `b` and refuse only what escapes the disk's root, which is the
 * right rule for a filesystem and the wrong one here: a path that climbs is a
 * path somebody is steering, and the answer to that is no
 * (docs/decisions/0040-agents-read-through-mcp-and-sign-through-the-ai-sdk.md).
 */
final readonly class DocumentAccess
{
    public function __construct(
        private Config $config,
        private Filesystems $filesystems,
    ) {}

    /**
     * The disks the application opened to agents, in the order it listed them.
     *
     * @return list<string>
     */
    public function disks(): array
    {
        $disks = $this->config->get('a1-pdf-sign.agents.disks');

        return is_array($disks) ? array_values(array_filter($disks, is_string(...))) : [];
    }

    /**
     * Whether a CPF or CNPJ may be handed to the model.
     *
     * Off by default. A signer's name is what an agent needs to answer "who
     * signed this"; the registry number is personal data under the LGPD, and
     * sending it to a model provider is a decision the application has to
     * make on purpose rather than inherit.
     */
    public function exposesRegistry(): bool
    {
        return (bool) $this->config->get('a1-pdf-sign.agents.expose_registry', false);
    }

    /**
     * A document an agent asked to read.
     *
     * @throws DocumentOutOfReach
     */
    public function source(string $disk, string $path): DiskSource
    {
        $filesystem = $this->disk($disk);
        $path = self::guard($path);

        if (! $filesystem->exists($path)) {
            throw DocumentOutOfReach::missing($disk, $path);
        }

        return new DiskSource($filesystem, $path);
    }

    /**
     * Where an agent asked a signed copy to go.
     *
     * @throws DocumentOutOfReach When the disk is closed, the path is refused
     *                            or something is already there.
     */
    public function destination(string $disk, string $path): DiskDestination
    {
        $filesystem = $this->disk($disk);
        $path = self::guard($path);

        if ($filesystem->exists($path)) {
            throw DocumentOutOfReach::occupied($disk, $path);
        }

        return new DiskDestination($filesystem, $path);
    }

    /**
     * The name a signed copy takes when the agent names none.
     *
     * The same convention the engine applies to a document signed without a
     * destination, the original with `_signed` before the extension, so a copy
     * signed by an agent and one signed by code land in the same place.
     */
    public static function signedName(string $path): string
    {
        $directory = pathinfo($path, PATHINFO_DIRNAME);
        $name = pathinfo($path, PATHINFO_FILENAME) . '_signed.pdf';

        return $directory === '.' || $directory === '' ? $name : "{$directory}/{$name}";
    }

    /**
     * @throws DocumentOutOfReach
     */
    private function disk(string $disk): Filesystem
    {
        $allowed = $this->disks();

        if (! in_array($disk, $allowed, true)) {
            throw DocumentOutOfReach::disk($disk, $allowed);
        }

        return $this->filesystems->disk($disk);
    }

    /**
     * The path, when it is one an agent may name.
     *
     * @throws DocumentOutOfReach
     */
    private static function guard(string $path): string
    {
        $why = match (true) {
            trim($path) === '' => 'it is empty.',
            str_contains($path, "\0") => 'it contains a null byte.',
            str_contains($path, '\\') => 'it contains a backslash. Separate directories with a forward slash.',
            str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1 => 'it is absolute. Give a path relative to the disk.',
            in_array('..', explode('/', $path), true) => 'it climbs out of the directory it names with "..".',
            ! Str::of($path)->lower()->endsWith('.pdf') => 'only PDF documents are reachable, and the name has to end in .pdf.',
            default => null,
        };

        if ($why !== null) {
            throw DocumentOutOfReach::path($path, $why);
        }

        return $path;
    }
}
