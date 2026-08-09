<?php

/**
 * The specification, checked against the code that cites it.
 *
 * Docblocks in src/, comments in the CI workflows, .gitattributes and CLAUDE.md
 * all defer to a documentation file for their justification. Nothing verified
 * those pointers, and they drifted: §12 was cited six times before it had a
 * section of its own, and three roadmap rows shipped unmarked, all noticed by
 * hand months later.
 *
 * The gate landed before the split and earned its keep during it: it failed
 * four times on references that had rotted mid-move, twice on this file's own
 * assertions.
 *
 * It checks paths rather than section numbers because that is what the split
 * replaced them with. A numbered filename survives the next reorganisation;
 * "§3i" did not survive this one.
 *
 * The helpers stay local to this file. They are used nowhere else, and the
 * shared-helper rule in tests/Pest.php exists for the opposite problem: a
 * helper needed by a second file, which is invisible to it under --parallel.
 */

use LSNepomuceno\LaravelA1PdfSign\Exceptions\FileNotFoundException;

/**
 * Every documentation file this content points at, as an absolute path.
 *
 * Relative hops are resolved from the citing file, so a link between two files
 * inside docs/ is checked the same way as a citation from src/.
 *
 * @return list<string>
 */
function specDocReferences(string $contents, string $from): array
{
    $paths = [];

    // Cited from anywhere: a path written from the repository root, which is how
    // a docblock, a CI comment or .gitattributes refers to documentation.
    if (preg_match_all('#(?<![\w./-])docs/[\w./-]+\.md#', $contents, $matches) > 0) {
        foreach ($matches[0] as $path) {
            $paths[] = packageRoot() . '/' . $path;
        }
    }

    // Inside docs/, links are ordinary Markdown and resolve against the file
    // that carries them, since "../spec/invariants.md" names no docs/ segment at all,
    // and the decision index links its records as bare siblings.
    if (str_starts_with($from, packageRoot() . '/docs/')
        && preg_match_all('#\]\(([\w./-]+\.md)\)#', $contents, $links) > 0) {
        foreach ($links[1] as $path) {
            $paths[] = str_starts_with($path, 'docs/')
                ? packageRoot() . '/' . $path
                : dirname($from) . '/' . $path;
        }
    }

    return array_values(array_unique($paths));
}

/**
 * Every file in the package that could carry a reference.
 *
 * @return list<string>
 */
function specScannedFiles(string $directory): array
{
    $skip = ['.git', '.idea', '.output', 'dist', 'node_modules', 'vendor'];

    $entries = scandir($directory);

    if ($entries === false) {
        return [];
    }

    $files = [];

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || in_array($entry, $skip, true)) {
            continue;
        }

        $path = $directory . '/' . $entry;

        if (is_dir($path)) {
            $files = array_merge($files, specScannedFiles($path));

            continue;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if (in_array($extension, ['php', 'md', 'yml', 'yaml'], true) || $entry === '.gitattributes') {
            $files[] = $path;
        }
    }

    return $files;
}

/**
 * @throws FileNotFoundException
 */
function specContents(string $path): string
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new FileNotFoundException($path);
    }

    return $contents;
}

function packageRoot(): string
{
    return dirname(__DIR__);
}

it('resolves every documentation file cited anywhere in the package', function () {
    $missing = [];

    foreach (specScannedFiles(packageRoot()) as $file) {
        foreach (specDocReferences(specContents($file), $file) as $path) {
            if (file_exists($path)) {
                continue;
            }

            // Named rather than counted: the failure has to say which pointer
            // rotted, since the whole problem is that nobody knew it had.
            $missing[] = str_replace(packageRoot() . '/', '', $file)
                . ' → ' . str_replace(packageRoot() . '/', '', $path);
        }
    }

    expect(array_values(array_unique($missing)))->toBe([]);
});

it('finds the references it exists to guard', function () {
    // Without this, a regex that quietly stops matching turns the gate above
    // into a test that passes because it checks nothing.
    $cited = [];

    foreach (specScannedFiles(packageRoot()) as $file) {
        $cited = array_merge($cited, specDocReferences(specContents($file), $file));
    }

    expect(count($cited))->toBeGreaterThanOrEqual(20)
        ->and(array_map(fn(string $path): string => basename($path), $cited))
        ->toContain('invariants.md', '0006-incremental-revision.md');
});

it('resolves a relative hop from inside docs/', function () {
    $from = packageRoot() . '/docs/decisions/0006-incremental-revision.md';

    expect(specDocReferences('See [the invariants](../spec/invariants.md).', $from))
        ->toBe([packageRoot() . '/docs/decisions/../spec/invariants.md']);
});

it('ignores prose that names no file', function () {
    expect(specDocReferences('The documentation lives under docs/, split by lifecycle.', 'x'))
        ->toBe([]);
});

it('every documentation file is reachable from the index', function () {
    // A file nothing links to is a file nobody reads, and it rots without the
    // gate above ever noticing, since the gate only checks pointers that exist.
    $index = specContents(packageRoot() . '/ARCHITECTURE.md');

    $linked = [];

    foreach (specDocReferences($index, packageRoot() . '/ARCHITECTURE.md') as $path) {
        $linked[] = realpath($path);
    }

    // The decision records are indexed by their own README rather than by the
    // root file, so that adding one does not mean editing two indexes.
    foreach (specDocReferences(specContents(packageRoot() . '/docs/decisions/README.md'), packageRoot() . '/docs/decisions/README.md') as $path) {
        $linked[] = realpath($path);
    }

    $orphans = [];

    foreach (specScannedFiles(packageRoot() . '/docs') as $file) {
        if (basename($file) === 'README.md' || in_array(realpath($file), $linked, true)) {
            continue;
        }

        $orphans[] = str_replace(packageRoot() . '/', '', $file);
    }

    expect($orphans)->toBe([]);
});
