<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Agents;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
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
 * - a document larger than `a1-pdf-sign.agents.max_bytes`, since the model
 *   chooses the file and the engine holds it in memory;
 * - a destination that already exists, since a signed copy never overwrites.
 *
 * **What the application's `Gate` says comes first**, when it defines the
 * ability: see `Agents\Ability` and `authorize()`. The disk list decides what is
 * reachable at all; the gate decides who may reach which document
 * (docs/decisions/0041-agents-are-authorised-per-document.md).
 *
 * `..` is refused outright rather than normalised. Flysystem would normalise
 * `a/../b` into `b` and refuse only what escapes the disk's root, which is the
 * right rule for a filesystem and the wrong one here: a path that climbs is a
 * path somebody is steering, and the answer to that is no
 * (docs/decisions/0040-agents-read-through-mcp-and-sign-through-the-ai-sdk.md).
 */
final readonly class DocumentAccess
{
    /**
     * 50 MB, the limit when the application's config does not name one.
     */
    public const int DEFAULT_MAX_BYTES = 52_428_800;

    public function __construct(
        private Config $config,
        private Filesystems $filesystems,
        private Gate $gate,
    ) {}

    /**
     * Asks the application's gate, when it defines the ability.
     *
     * An undefined ability allows, because the disk list is the control this
     * package promised in 3.1.0 and an application upgrading should not find
     * every agent call refused. A defined one is asked with the authenticated
     * user, and a guest is refused unless the ability says otherwise, which
     * is how Laravel's gate treats a guest everywhere.
     *
     * **Tools call this before they look for the document**, so a user who
     * may not read a path cannot learn from the error whether it exists.
     *
     * @throws AuthorizationException
     */
    public function authorize(Ability $ability, string ...$arguments): void
    {
        if ($this->gate->has($ability->value)) {
            $this->gate->authorize($ability->value, array_values($arguments));
        }
    }

    /**
     * The same question, answered rather than thrown, for describing a call
     * before it runs.
     */
    public function allows(Ability $ability, string ...$arguments): bool
    {
        return ! $this->gate->has($ability->value) || $this->gate->allows($ability->value, array_values($arguments));
    }

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

        $limit = $this->maxBytes();

        if ($limit !== null && $filesystem->size($path) > $limit) {
            throw DocumentOutOfReach::tooLarge($disk, $path, $limit);
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
     * The largest document an agent may hand the engine, or null for no limit.
     *
     * **A missing key is the default, and only an explicit null removes it.**
     * `mergeConfigFrom()` merges top-level keys only, so an application that
     * published its config under 3.1.0 has an `agents` block without this key,
     * and that block replaces the package's whole. Reading the absence as "no
     * limit" would have switched the limit off for exactly the applications
     * that had configured agents.
     */
    public function maxBytes(): ?int
    {
        $key = 'a1-pdf-sign.agents.max_bytes';

        if (! $this->config->has($key)) {
            return self::DEFAULT_MAX_BYTES;
        }

        $limit = $this->config->get($key);

        return is_numeric($limit) && (int) $limit > 0 ? (int) $limit : null;
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
