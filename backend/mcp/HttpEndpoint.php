<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\mcp;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StatelessHttpTransport;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves MCP over HTTP from the app itself, on the app's own port.
 *
 * A PSR-15 middleware rather than a page handler: the SDK's stateless transport takes a PSR-7
 * request and returns a PSR-7 response, which is exactly what a middleware has in its hands,
 * so nothing has to adapt bodies or headers in between.
 *
 * Sharing the port is the point. nfsen-ng has no authentication of its own and is protected by
 * where it is deployed, so an endpoint on a second port would quietly sit outside whatever
 * guards the dashboard. Here it inherits that protection exactly.
 *
 * Everything is decided per request, not at registration: routes are registered before
 * Config::initialize() runs, so asking whether the endpoint is enabled while building the route
 * table reads settings that do not exist yet.
 */
final class HttpEndpoint implements MiddlewareInterface {
    public const PATH = '/_mcp';

    private ?StatelessHttpTransport $transport = null;

    public function __construct(
        private readonly string $version,
        private readonly string $path = self::PATH,
    ) {}

    public static function isEnabled(): bool {
        return isset(Config::$settings) && Config::$settings->mcpHttpEnabled;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
        if ($request->getUri()->getPath() !== $this->path) {
            return $handler->handle($request);
        }

        // Disabled is indistinguishable from absent, which is what an operator who never
        // turned this on should see.
        if (!self::isEnabled()) {
            return new Response(404, ['Content-Type' => 'text/plain'], 'Not Found');
        }

        Debug::getInstance()->log(
            'MCP HTTP ' . $request->getMethod() . ' from ' . ($request->getServerParams()['remote_addr'] ?? 'unknown'),
            LOG_DEBUG
        );

        return $this->transport()->handle($request);
    }

    /**
     * Built on first use and kept for the worker's lifetime: assembling the tool registry per
     * request would re-reflect every handler for nothing.
     */
    private function transport(): StatelessHttpTransport {
        if ($this->transport instanceof StatelessHttpTransport) {
            return $this->transport;
        }

        $protocol = ToolRegistry::build($this->version)->buildStateless();
        $hosts = Config::$settings->mcpHttpHosts;

        // Passing any middleware replaces the SDK's defaults wholesale, so CORS has to come
        // along explicitly rather than being dropped by accident. An empty host list keeps the
        // SDK's localhost-only rebinding protection, which the specification asks for: a
        // browser tricked by DNS rebinding otherwise reaches a server that trusts its own
        // network position.
        $middleware = $hosts === []
            ? null
            : [new CorsMiddleware(), new DnsRebindingProtectionMiddleware($hosts)];

        return $this->transport = new StatelessHttpTransport($protocol, middleware: $middleware);
    }
}
