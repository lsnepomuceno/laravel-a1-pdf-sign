<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Agents;

/**
 * The two things an agent can ask to do with a document, as `Gate` abilities.
 *
 * An application that defines one of these decides, per user and per
 * document, whether an agent may do it. One that defines neither gets what
 * 3.1.0 shipped: any document on an open disk, for anybody who reaches the
 * tool (docs/decisions/0041-agents-are-authorised-per-document.md).
 *
 * ```php
 * Gate::define(Ability::Read->value, fn (User $user, string $disk, string $path) => …);
 * Gate::define(Ability::Sign->value, fn (User $user, string $disk, string $path, string $destinationDisk, string $destinationPath) => …);
 * ```
 */
enum Ability: string
{
    /**
     * Validate a document or list its fields. Receives the disk and the path.
     */
    case Read = 'a1-pdf-sign.agents.read';

    /**
     * Sign a document. Receives the source's disk and path, then the
     * destination's, since where a signed copy lands is part of the decision.
     */
    case Sign = 'a1-pdf-sign.agents.sign';
}
