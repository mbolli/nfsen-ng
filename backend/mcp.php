#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * MCP server over stdio: read-only access to this installation's NetFlow data.
 *
 * Runs as its own process rather than inside the web worker, so an agent's queries cannot
 * compete with the UI for the single OpenSwoole worker. stdio also means no listening socket
 * and no credentials to manage; an HTTP transport would need both.
 *
 * Point a client at it with:
 *   php /var/www/html/nfsen-ng/backend/mcp.php
 */

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\mcp\ToolRegistry;
use Mcp\Server\Transport\StdioTransport;

require __DIR__ . '/../vendor/autoload.php';

Config::initialize(true);

// stdout carries the protocol, so anything else written there corrupts the stream. The
// transport gets its own handle on the real stdout, and everything PHP would print goes to
// stderr instead: Debug::log() echoes when it detects a CLI, which would otherwise interleave
// log lines with JSON-RPC frames and break the client's parser.
ini_set('display_errors', 'stderr');
$protocolOut = fopen('php://fd/1', 'w');
if ($protocolOut === false) {
    fwrite(\STDERR, "nfsen-ng MCP: cannot open stdout for the protocol stream.\n");

    exit(1);
}
ob_start(static function (string $chunk): string {
    fwrite(\STDERR, $chunk);

    return '';
}, 1);

ToolRegistry::build(Config::VERSION)
    ->build()
    ->run(new StdioTransport(output: $protocolOut))
;
