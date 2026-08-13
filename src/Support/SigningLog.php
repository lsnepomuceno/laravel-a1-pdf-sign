<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Support;

use LSNepomuceno\LaravelA1PdfSign\Enums\SigningEvent;
use Psr\Log\LoggerInterface;

/**
 * The audit trail, when a host asks for one.
 *
 * Corporate deployments audit signing, and nothing here recorded anything. This
 * is opt-in and null by default: a package that logs unasked is a package that
 * fills somebody's disk.
 *
 * **The allowlist is the feature.** This class handles PKCS#12 bundles, private
 * keys and passwords, and a logger is a second channel that
 * `#[\SensitiveParameter]` does not cover: that attribute keeps a value out of
 * a stack trace and has nothing to say about a line somebody wrote to disk.
 *
 * So the context is filtered against a list of keys that may appear, rather
 * than a list of keys that may not. A denylist is how the next property added
 * to a data object ends up in a log file.
 */
final readonly class SigningLog
{
    /**
     * Everything a log line may carry.
     *
     * Nothing here can identify a key, and nothing here is large. The document
     * itself, the CMS, the PFX bytes, any password and any file path are absent
     * on purpose: a path is enough to find the bundle it names.
     *
     * @var list<string>
     */
    public const array ALLOWED = [
        'event',
        'profile',
        'field',
        'certification',
        'signer',
        'serial',
        'signed_at',
        'authority',
        'valid',
        'signatures',
        'exception',
    ];

    public function __construct(private ?LoggerInterface $logger = null) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(SigningEvent $event, array $context = []): void
    {
        $this->logger?->info($event->value, $this->sanitise($event, $context));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sanitise(SigningEvent $event, array $context): array
    {
        $safe = ['event' => $event->value];

        foreach ($context as $key => $value) {
            if (in_array($key, self::ALLOWED, true) && $this->isScalarish($value)) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    /**
     * Scalars only, so an object carrying a key cannot arrive under an allowed
     * name and be serialised by whatever formats the line.
     */
    private function isScalarish(mixed $value): bool
    {
        return $value === null || is_scalar($value);
    }
}
