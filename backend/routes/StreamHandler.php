<?php

/**
 * Base class for SSE stream handlers.
 * Provides common utilities for Server-Sent Events streaming.
 */

declare(strict_types=1);

namespace mbolli\nfsen_ng\routes;

use mbolli\nfsen_ng\common\Debug;
use starfederation\datastar\enums\ElementPatchMode;
use starfederation\datastar\ServerSentEventGenerator;
use Swoole\Http\Request;
use Swoole\Http\Response;

abstract class StreamHandler {
    /**
     * Message container ID for alerts.
     * Override in subclasses to customize.
     */
    protected const MESSAGE_TARGET = 'message';

    public function __construct(protected Debug $debug, protected ServerSentEventGenerator $sse) {}

    /**
     * Handle the stream request.
     * Must be implemented by subclasses.
     */
    abstract public function handle(Request $request, Response $response): void;

    /**
     * Extract signals from Datastar request (query param or POST body).
     */
    protected function extractSignals(Request $request): array {
        // Check query parameter first (Datastar GET requests)
        if (isset($request->get['datastar'])) {
            return json_decode(urldecode($request->get['datastar']), true) ?? [];
        }

        // Fall back to POST body
        if ($rawContent = $request->rawContent()) {
            return json_decode($rawContent, true) ?? [];
        }

        return [];
    }

    /**
     * Parse query parameters from request.
     */
    protected function parseQuery(Request $request): array {
        $query = [];
        parse_str($request->server['query_string'] ?? '', $query);

        return $query;
    }

    /**
     * Send SSE keepalive comment to prevent connection timeout.
     */
    protected function sendKeepalive(Response $response): void {
        if ($response->isWritable()) {
            $response->write(": keepalive\n\n");
        }
    }

    /**
     * Send success message to client via nfsen-toast component.
     *
     * @param Response    $response    The response object to write to
     * @param string      $message     The message to display
     * @param bool        $reset       If true, clears previous messages first
     * @param null|string $containerId The toast container ID (defaults to MESSAGE_TARGET)
     */
    protected function sendSuccessMessage(Response $response, string $message, bool $reset = false, ?string $containerId = null): void {
        $this->sendToast($response, 'success', $message, $reset, $containerId);
    }

    /**
     * Send warning message to client via nfsen-toast component.
     *
     * @param Response    $response    The response object to write to
     * @param string      $message     The message to display
     * @param bool        $reset       If true, clears previous messages first
     * @param null|string $containerId The toast container ID (defaults to MESSAGE_TARGET)
     */
    protected function sendWarning(Response $response, string $message, bool $reset = false, ?string $containerId = null): void {
        $this->sendToast($response, 'warning', $message, $reset, $containerId);
    }

    /**
     * Send error message to client via nfsen-toast component.
     *
     * @param Response    $response    The response object to write to
     * @param string      $message     The message to display
     * @param bool        $reset       If true, clears previous messages first
     * @param null|string $containerId The toast container ID (defaults to MESSAGE_TARGET)
     */
    protected function sendError(Response $response, string $message, bool $reset = false, ?string $containerId = null): void {
        $this->sendToast($response, 'error', $message, $reset, $containerId);
    }

    /**
     * Send toast notification as complete nfsen-toast element.
     * The component will auto-render and handle dismissal.
     *
     * @param Response    $response    The response object to write to
     * @param string      $type        Message type: 'success', 'error', 'warning', 'info'
     * @param string      $message     The message to display (can contain HTML)
     * @param bool        $reset       If true, replaces all previous messages
     * @param null|string $containerId The container ID to append to (defaults to MESSAGE_TARGET)
     * @param bool        $autoDismiss If true, toast will auto-dismiss after delay
     */
    private function sendToast(Response $response, string $type, string $message, bool $reset = false, ?string $containerId = null, bool $autoDismiss = false): void {
        $containerId ??= static::MESSAGE_TARGET;

        // Create complete nfsen-toast element
        // Note: Message is NOT escaped because it will be inserted via innerHTML in the component
        // Only escape quotes to prevent breaking the HTML attribute
        $escapedMessage = str_replace('"', '&quot;', $message);
        $html = \sprintf(
            '<nfsen-toast data-type="%s" data-message="%s"%s></nfsen-toast>',
            htmlspecialchars($type, ENT_QUOTES),
            $escapedMessage,
            $autoDismiss ? ' data-auto-dismiss="true"' : ''
        );

        if ($reset) {
            // Clear previous messages first
            $clearEvent = $this->sse->patchElements('', [
                'selector' => '#' . $containerId,
                'mode' => ElementPatchMode::Inner,
            ]);
            $response->write($clearEvent);
        }

        $event = $this->sse->patchElements($html, [
            'selector' => '#' . $containerId,
            'mode' => $reset ? ElementPatchMode::Inner : ElementPatchMode::Append,
        ]);
        $response->write($event);
    }
}
