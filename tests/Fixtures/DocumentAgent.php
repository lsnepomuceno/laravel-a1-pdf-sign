<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Tests\Fixtures;

use Laravel\Ai\Contracts\{Agent, Conversational, HasTools};
use Laravel\Ai\Promptable;
use Laravel\Ai\Tools\McpServerTool;
use LSNepomuceno\LaravelA1PdfSign\Ai\Tools\SignPdf;
use LSNepomuceno\LaravelA1PdfSign\Mcp\Tools\{ListSignatureFields, ValidatePdfSignature};

/**
 * An agent built the way the guide tells an application to build one.
 *
 * It exists so the suite drives the tools through the AI SDK's own loop rather
 * than only calling `handle()`: the loop is what asks for approval, wraps the
 * MCP tools and returns validation failures to the model, and a test that
 * skips it proves none of that.
 *
 * Conversational with no history, because the SDK refuses to pause a run for
 * approval unless it can be resumed, and an empty history is resumable.
 */
final class DocumentAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    public function __construct(private readonly SignPdf $signing = new SignPdf()) {}

    public function instructions(): string
    {
        return 'You answer questions about signed contracts, and sign them when asked.';
    }

    public function messages(): iterable
    {
        return [];
    }

    /**
     * The two MCP tools wrapped for the SDK, and the signing tool.
     *
     * Wrapped by hand, as the guide recommends. The SDK wraps a bare MCP tool
     * on its own at runtime, but `HasTools::tools()` is declared as returning
     * SDK tools only, so an application under static analysis gets an error
     * for relying on it. `McpServerTool` is the same wrapper, named.
     */
    public function tools(): iterable
    {
        return [
            new McpServerTool(new ValidatePdfSignature()),
            new McpServerTool(new ListSignatureFields()),
            $this->signing,
        ];
    }
}
