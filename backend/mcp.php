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

// stdout carries the protocol, so anything else written there corrupts the stream.
ini_set('display_errors', 'stderr');

ToolRegistry::build(Config::VERSION)
    ->build()
    ->run(new StdioTransport())
;
