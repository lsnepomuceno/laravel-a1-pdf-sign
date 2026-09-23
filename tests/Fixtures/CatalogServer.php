<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Tests\Fixtures;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tools\ToolSearch;
use LSNepomuceno\LaravelA1PdfSign\Mcp\Tools\{ListSignatureFields, ValidatePdfSignature};

/**
 * An application's own server, with the package's tools behind `ToolSearch`.
 *
 * The arrangement the MCP guide describes for a server with many tools: the
 * client sees `search_tools` and `execute_tools`, and finds ours by searching.
 * The package's own server does not do this, and the guide says why.
 */
final class CatalogServer extends Server
{
    protected array $tools = [
        ToolSearch::class => [
            ValidatePdfSignature::class,
            ListSignatureFields::class,
        ],
    ];
}
