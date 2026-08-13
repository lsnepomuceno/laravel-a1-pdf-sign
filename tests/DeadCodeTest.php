<?php

declare(strict_types=1);

use SplFileInfo;

/**
 * Code that is written and never read.
 *
 * PHPStan at `level: max` already refuses a private method nobody calls and a
 * property only ever written, verified rather than assumed: a probe class
 * carrying both was reported, `method.unused` and `property.onlyWritten`.
 *
 * **A local variable assigned and never read is the one it does not see**, and
 * no tool in the ecosystem fits here (issue #289):
 *
 * - `shipmonk/phpstan-rules` has no such rule. It forbids an unused closure
 *   parameter, exception and match result, and stops there.
 * - `phpmd/phpmd` cannot run at all in this tree: PDepend's Symfony extension
 *   is incompatible with the installed Symfony and dies in a fatal.
 * - `slevomat/coding-standard` has the sniff and arrives through PHPCS, a
 *   second toolchain beside Pint for one check.
 *
 * So it is written here, which `docs/spec/conventions.md` allows once there is
 * no platform answer, and which `tests/ArchTest.php` and `tests/SpecTest.php`
 * already do by walking the tree with `token_get_all()`.
 *
 * **It under-reports on purpose.** A gate with no baseline that cries wolf is a
 * gate people learn to ignore, so this only flags the unambiguous case: a plain
 * `$x = …` whose variable is named exactly once in the whole function body.
 * A destructuring target, a `foreach` value, a parameter and anything inside a
 * nested closure are all left alone.
 */

/**
 * Every PHP file that could carry dead code.
 *
 * @return list<string>
 */
function deadCodeScannedFiles(): array
{
    $files = [];

    foreach (['/src', '/tests'] as $directory) {
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . $directory)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * The variables a file assigns and never reads.
 *
 * @return list<string> `path:line $variable`
 */
function unusedVariablesIn(string $path): array
{
    $tokens = token_get_all((string) file_get_contents($path));
    $found = [];

    foreach (array_keys($tokens) as $index) {
        $token = $tokens[$index];

        if (! is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }

        [$open, $close] = deadCodeBody($tokens, $index);

        if ($open === null || $close === null) {
            // An abstract method or an interface declaration has no body.
            continue;
        }

        foreach (deadCodeUnusedInScope($tokens, $open, $close) as $line => $name) {
            $found[] = str_replace(dirname(__DIR__) . '/', '', $path) . ":{$line} {$name}";
        }
    }

    return $found;
}

/**
 * The brace positions of a function's body, or nulls when it has none.
 *
 * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
 * @return array{0: ?int, 1: ?int}
 */
function deadCodeBody(array $tokens, int $start): array
{
    $count = count($tokens);
    $depth = 0;
    $open = null;

    for ($index = $start; $index < $count; $index++) {
        $token = $tokens[$index];

        if ($token === ';' && $open === null) {
            return [null, null];
        }

        // String interpolation opens a brace that token_get_all() reports as
        // T_CURLY_OPEN or T_DOLLAR_OPEN_CURLY_BRACES, while the matching close
        // comes back as a plain '}'. Counting only the plain form closes the
        // function early at the first "{$variable}" in it, which is how the
        // first version of this walker reported twenty false positives.
        if (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
            $depth++;

            continue;
        }

        if ($token === '{') {
            $depth++;

            if ($open === null) {
                $open = $index;
            }

            continue;
        }

        if ($token === '}') {
            $depth--;

            if ($depth === 0) {
                return [$open, $index];
            }
        }
    }

    return [null, null];
}

/**
 * Variables named exactly once inside the span, as an assignment target.
 *
 * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
 * @return array<int, string> Line number to variable name.
 */
function deadCodeUnusedInScope(array $tokens, int $open, int $close): array
{
    $occurrences = [];
    $assignments = [];

    for ($index = $open + 1; $index < $close; $index++) {
        $token = $tokens[$index];

        // A nested declaration's parameter list is not this scope's, and a
        // parameter with a default reads exactly like an assignment:
        // `bool $b64 = false` inside an anonymous class was reported five
        // times before this skip existed. The `use (...)` clause is
        // deliberately left in, since it is a genuine read of an outer
        // variable.
        if (is_array($token) && in_array($token[0], [T_FUNCTION, T_FN], true)) {
            $index = deadCodeSkipParameters($tokens, $index, $close);

            continue;
        }

        // A property declared with a default reads exactly like an assignment,
        // and a nested class puts one inside the scope being walked:
        // `public array $lines = [];` was reported once this file's own
        // recording logger existed. Modifiers introducing a method are left
        // alone, since the branch above already handles those.
        if (is_array($token) && in_array($token[0], [T_PUBLIC, T_PRIVATE, T_PROTECTED, T_VAR, T_READONLY], true)) {
            $index = deadCodeSkipDeclaration($tokens, $index, $close);

            continue;
        }

        if (! is_array($token) || $token[0] !== T_VARIABLE || $token[1] === '$this') {
            continue;
        }

        $name = $token[1];
        $occurrences[$name] = ($occurrences[$name] ?? 0) + 1;

        // A plain `=`, which token_get_all() gives as a bare string. Anything
        // compound (==, ===, =>, .=) is a different token entirely, so a
        // comparison or an array key never reaches here.
        $next = deadCodeNextMeaningful($tokens, $index, $close);

        if ($next === '=') {
            $assignments[$name] ??= $token[2];
        }
    }

    $unused = [];

    foreach ($assignments as $name => $line) {
        if ($occurrences[$name] === 1) {
            $unused[$line] = $name;
        }
    }

    return $unused;
}

/**
 * The end of a property declaration, or the modifier itself when it introduces
 * a method.
 *
 * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
 */
function deadCodeSkipDeclaration(array $tokens, int $index, int $close): int
{
    for ($next = $index + 1; $next < $close; $next++) {
        $token = $tokens[$next];

        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        // `public function …` and `public static function …`: the function
        // branch owns those.
        if (is_array($token) && in_array($token[0], [T_FUNCTION, T_FN, T_STATIC, T_READONLY], true)) {
            return $index;
        }

        break;
    }

    for ($next = $index + 1; $next < $close; $next++) {
        if ($tokens[$next] === ';') {
            return $next;
        }
    }

    return $index;
}

/**
 * The index of the closing parenthesis of a declaration's parameter list.
 *
 * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
 */
function deadCodeSkipParameters(array $tokens, int $index, int $close): int
{
    $depth = 0;

    for ($next = $index + 1; $next < $close; $next++) {
        if ($tokens[$next] === '(') {
            $depth++;

            continue;
        }

        if ($tokens[$next] === ')') {
            $depth--;

            if ($depth === 0) {
                return $next;
            }
        }
    }

    return $index;
}

/**
 * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
 */
function deadCodeNextMeaningful(array $tokens, int $index, int $close): ?string
{
    for ($next = $index + 1; $next < $close; $next++) {
        $token = $tokens[$next];

        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return is_array($token) ? $token[1] : $token;
    }

    return null;
}

it('assigns no variable it never reads', function () {
    $found = [];

    foreach (deadCodeScannedFiles() as $file) {
        $found = array_merge($found, unusedVariablesIn($file));
    }

    expect($found)->toBe([]);
});

it('finds the dead code it exists to guard', function () {
    // A check that cannot fail is not a check. This asserts the walker on a
    // file whose contents are known, rather than trusting the empty result
    // above to mean something.
    $path = LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign::tempPath(true, '.php');

    file_put_contents($path, <<<'PHP'
        <?php
        function deliberatelyDead(int $given): int
        {
            $used = $given * 2;
            $neverRead = 'computed and forgotten';

            return $used;
        }
        PHP);

    $found = unusedVariablesIn($path);

    expect($found)->toHaveCount(1)
        ->and($found[0])->toContain('$neverRead');

    unlink($path);
});

it('leaves alone the shapes that look unused and are not', function () {
    // The cases that would make this cry wolf. A gate with no baseline has to
    // be quiet about all of them or nobody will keep it.
    $path = LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign::tempPath(true, '.php');

    file_put_contents($path, <<<'PHP'
        <?php
        function everyShape(array $rows, string $name): string
        {
            [$first, $second] = $rows;

            foreach ($rows as $key => $value) {
                unset($key);
            }

            $interpolated = 'x';
            $callback = static fn (): string => "{$interpolated}{$name}";

            $captured = 'y';
            $closure = function () use ($captured): string {
                $inner = $captured;

                return $inner;
            };

            return $first . $second . $value . $callback() . $closure();
        }
        PHP);

    expect(unusedVariablesIn($path))->toBe([]);

    unlink($path);
});

it('does not read a property default as an assignment', function () {
    // The shape that showed up the moment a test wrote its own recording
    // logger: a nested class declaring a property with a default, inside the
    // function scope being walked.
    $path = LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign::tempPath(true, '.php');

    file_put_contents($path, <<<'PHP'
        <?php
        function makesARecorder(): object
        {
            return new class
            {
                public array $lines = [];

                private string $name = 'unread by anything here';

                public function add(string $line): void
                {
                    $this->lines[] = $line;
                }
            };
        }
        PHP);

    expect(unusedVariablesIn($path))->toBe([]);

    unlink($path);
});

it('does not read a parameter default as an assignment', function () {
    // The shape that produced five false positives on tests/ServiceTest.php:
    // an anonymous class inside a closure, whose methods carry defaults. The
    // parameter list belongs to the nested declaration, not to the scope being
    // walked.
    $path = LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign::tempPath(true, '.php');

    file_put_contents($path, <<<'PHP'
        <?php
        function makesAnObject(): object
        {
            return new class
            {
                public function withDefaults(string $given, bool $flag = false, ?string $other = null): string
                {
                    return $given;
                }
            };
        }
        PHP);

    expect(unusedVariablesIn($path))->toBe([]);

    unlink($path);
});
