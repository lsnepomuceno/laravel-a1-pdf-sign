<?php

namespace LSNepomuceno\LaravelA1PdfSign\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * A file that deletes itself.
 *
 * The v1 code deleted its temporary files with a call placed after the work
 * that might throw, so any failure leaked them — including PEM files holding
 * a private key. Here deletion happens in a finally block, with the destructor
 * as a backstop. See ARCHITECTURE-V2.md §1.2.
 */
final class TemporaryFile
{
    private bool $deleted = false;

    private function __construct(public readonly string $path) {}

    /**
     * Creates an empty temporary file, optionally seeded with contents.
     */
    public static function create(string $directory, string $extension = '.tmp', string $contents = ''): self
    {
        $directory = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, recursive: true);
        }

        $file = new self($directory . Str::orderedUuid() . $extension);

        File::put($file->path, $contents);

        return $file;
    }

    /**
     * Runs $callback with a temporary file, deleting it however the call ends.
     *
     * @template T
     *
     * @param  callable(self): T  $callback
     * @return T
     */
    public static function with(
        string $directory,
        string $extension,
        string $contents,
        callable $callback,
    ): mixed {
        $file = self::create($directory, $extension, $contents);

        try {
            return $callback($file);
        } finally {
            $file->delete();
        }
    }

    public function contents(): string
    {
        return File::get($this->path);
    }

    public function exists(): bool
    {
        return File::exists($this->path);
    }

    public function delete(): void
    {
        if ($this->deleted) {
            return;
        }

        $this->deleted = true;

        if (File::exists($this->path)) {
            File::delete($this->path);
        }
    }

    public function __destruct()
    {
        $this->delete();
    }
}
