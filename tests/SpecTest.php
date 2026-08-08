<?php

/**
 * The specification, checked against the code that cites it.
 *
 * ARCHITECTURE-V2.md is load-bearing: docblocks in src/, comments in the CI
 * workflows and even .gitattributes point at a numbered section for their
 * justification. Nothing verified those pointers, and they drifted — §12 was
 * cited six times before it had a row of its own, and three roadmap rows
 * shipped unmarked, all of it noticed by hand months later.
 *
 * This is the gate that was missing, and it lands before the document is split
 * apart on purpose: the reorganisation rewrites every one of these references,
 * and this is what proves the rewrite was complete.
 *
 * The helpers stay local to this file. They are used nowhere else, and the
 * shared-helper rule in tests/Pest.php exists for the opposite problem — a
 * helper needed by a second file, which is invisible to it under --parallel.
 */

use LSNepomuceno\LaravelA1PdfSign\Exceptions\FileNotFoundException;

/**
 * Every section identifier the document actually defines.
 *
 * Four shapes are in use, and all four are cited from code: top-level sections
 * (§4), the ordered diagnosis list that runs 1..14 across the subsections of §1
 * (§1.14), the lettered decisions under §3 including their nested results
 * (§3a, §3e.1), and the numbered toolchain entries (§6.3).
 *
 * @return list<string>
 */
function specAnchors(string $markdown): array
{
    $anchors = [];
    $top = null;
    $inFence = false;

    foreach (explode("\n", $markdown) as $line) {
        // Ordered lists inside fenced examples are not anchors.
        if (str_starts_with($line, '```')) {
            $inFence = ! $inFence;

            continue;
        }

        if ($inFence) {
            continue;
        }

        if (preg_match('/^## (\d+)\./', $line, $matches) === 1) {
            $top = $matches[1];
            $anchors[] = $top;

            continue;
        }

        if ($top === null) {
            continue;
        }

        // ### e.1)  ·  #### g.2)
        if (preg_match('/^#{3,4} ([a-z])\.(\d+)\)/', $line, $matches) === 1) {
            $anchors[] = $top . $matches[1] . '.' . $matches[2];

            continue;
        }

        // ### a)  ·  ### f) ~~reverted~~
        if (preg_match('/^#{3,4} ([a-z])\)/', $line, $matches) === 1) {
            $anchors[] = $top . $matches[1];

            continue;
        }

        // ### 6.3 Mutation testing
        if (preg_match('/^#{3,4} (\d+)\.(\d+)/', $line, $matches) === 1) {
            $anchors[] = $matches[1] . '.' . $matches[2];

            continue;
        }

        if (preg_match('/^(\d+)\. /', $line, $matches) === 1) {
            $anchors[] = $top . '.' . $matches[1];
        }
    }

    return array_values(array_unique($anchors));
}

/**
 * Every section this file points at, by identifier.
 *
 * Only references anchored to the document's own name count. A bare "§7.5.6" is
 * ISO 32000-1 and "§12.8" is an RFC, both of which appear in this codebase, so
 * matching on the marker alone would report the PDF specification as drift.
 *
 * @return list<string>
 */
function specReferences(string $contents): array
{
    // A docblock wraps, so a reference can straddle a line break and its
    // leading asterisk. Flatten those before matching.
    $flat = preg_replace('/\R\s*\*?[ \t]*/', ' ', $contents);

    if ($flat === null) {
        return [];
    }

    $sections = [];
    $offset = 0;
    $name = 'ARCHITECTURE-V2.md';

    while (($position = strpos($flat, $name, $offset)) !== false) {
        $offset = $position + strlen($name);

        // Only the unbroken run of identifiers that follows the name, so that
        // prose merely naming the document contributes nothing, and a "§7.5"
        // later in the same sentence is not swept in. The run may chain, as in
        // "see ARCHITECTURE-V2.md §3a and §6.2".
        $matched = preg_match(
            '/^[\s(`]*(?:§\d+[a-z]?(?:\.\d+)?[\s,.)`]*(?:and|or)?[\s]*)+/',
            substr($flat, $offset, 120),
            $run,
        );

        if ($matched !== 1) {
            continue;
        }

        preg_match_all('/§(\d+[a-z]?(?:\.\d+)?)/', $run[0], $found);

        foreach ($found[1] as $section) {
            $sections[] = $section;
        }
    }

    return $sections;
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

it('resolves every ARCHITECTURE-V2.md section cited anywhere in the package', function () {
    $anchors = specAnchors(specContents(packageRoot() . '/ARCHITECTURE-V2.md'));

    $dangling = [];

    foreach (specScannedFiles(packageRoot()) as $file) {
        foreach (specReferences(specContents($file)) as $section) {
            if (in_array($section, $anchors, true)) {
                continue;
            }

            $dangling[] = str_replace(packageRoot() . '/', '', $file) . ' → §' . $section;
        }
    }

    // Listed rather than counted: the failure has to name the reference to be
    // actionable, since the whole point is that nobody knew it had rotted.
    expect(array_values(array_unique($dangling)))->toBe([]);
});

it('finds the references it exists to guard', function () {
    // Without this, a regex that quietly stops matching turns the gate above
    // into a test that passes because it checks nothing.
    $cited = [];

    foreach (specScannedFiles(packageRoot()) as $file) {
        foreach (specReferences(specContents($file)) as $section) {
            $cited[] = $section;
        }
    }

    expect(count($cited))->toBeGreaterThanOrEqual(20)
        ->and(array_unique($cited))->toContain('3h', '3i', '6.3', '1.14');
});

it('reads the four anchor shapes the document uses', function () {
    $anchors = specAnchors(specContents(packageRoot() . '/ARCHITECTURE-V2.md'));

    expect($anchors)
        ->toContain('4')        // ## 4. Backward compatibility
        ->toContain('1.14')     // 14. in the diagnosis list
        ->toContain('3a')       // ### a) under §3
        ->toContain('3e.1')     // ### e.1) under §3
        ->toContain('3i')       // ### i) under §3
        ->toContain('6.3');     // ### 6.3
});

it('ignores section markers that belong to another specification', function () {
    // ISO 32000-1 and the RFCs are cited by section throughout src/Signing.
    expect(specReferences('Appends a revision, ISO 32000-1 §7.5.6, without rebuilding.'))->toBe([])
        ->and(specReferences('An RFC 3161 timestamp, §2.4.2, over the whole file.'))->toBe([])
        ->and(specReferences('The document merely named, ARCHITECTURE-V2.md, in passing.'))->toBe([]);
});

it('reads a reference that wraps across a docblock line break', function () {
    $docblock = <<<'PHP'
    /**
     * Feeds it to `openssl pkcs12 -in`, which expects binary PKCS#12 and
     * always failed — ARCHITECTURE-V2.md
     * §1.14.
     */
    PHP;

    expect(specReferences($docblock))->toBe(['1.14']);
});

it('reads a chain of references sharing one mention of the document', function () {
    expect(specReferences('See ARCHITECTURE-V2.md §3a and §6.2.'))->toBe(['3a', '6.2']);
});
