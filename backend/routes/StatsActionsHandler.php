<?php

/**
 * Handle statistics-related actions from Datastar events.
 * Processes statistics queries and returns formatted results.
 */

declare(strict_types=1);

namespace mbolli\nfsen_ng\routes;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\Table;
use starfederation\datastar\ServerSentEventGenerator;
use Swoole\Http\Request;
use Swoole\Http\Response;

class StatsActionsHandler extends StreamHandler {
    protected const MESSAGE_TARGET = 'statsMessage';

    public function __construct(Debug $debug, ServerSentEventGenerator $sse) {
        parent::__construct($debug, $sse);
    }

    public function handle(Request $request, Response $response): void {
        $signals = $this->extractSignals($request);

        $this->debug->log('Processing statistics', LOG_DEBUG);

        $this->processStatistics($request, $response, $signals);

        $response->end();
    }

    /**
     * Process statistics query and return results.
     * Matches logic from Api::stats() in legacy API.
     */
    private function processStatistics(Request $request, Response $response, array $signals): void {
        // Extract parameters from signals
        $sSignals = $signals['stats'] ?? [];
        $sources = $sSignals['sources'] ?? Config::$cfg['general']['sources'];
        if (empty($sources)) {
            $sources = Config::$cfg['general']['sources'];
        }

        $datestart = (int) ($signals['datestart'] ?? time() - 3600);
        $dateend = (int) ($signals['dateend'] ?? time());
        $filter = $sSignals['nfdump_filter'] ?? '';
        $top = (int) ($sSignals['count'] ?? 10);
        $statsFor = $sSignals['for'] ?? 'record';
        $orderBy = $sSignals['order_by'] ?? 'flows';
        $time = microtime(true);

        // Combine statsFor and orderBy into the 'for' parameter
        $forParam = $statsFor . '/' . $orderBy;

        // Parse output format (only used when statsFor != 'record')
        $outputFormat = $signals['outputFormat'] ?? 'line';
        $customFormat = $signals['customOutputFormat'] ?? '';
        $output = [
            'format' => $outputFormat === 'custom' ? 'custom' : $outputFormat,
            'custom' => $customFormat,
        ];

        try {
            // Build processor options matching legacy API
            $sources_str = \is_array($sources) ? implode(':', $sources) : $sources;

            $processor = new Config::$processorClass();
            $processor->setOption('-M', $sources_str);
            $processor->setOption('-R', [$datestart, $dateend]);
            $processor->setOption('-n', $top);
            // Use JSON output format for structured data
            $processor->setOption('-o', 'json');
            $processor->setOption('-s', $forParam);
            $processor->setFilter($filter);

            $result = $processor->execute();

            // Result format: {command: string, rawOutput: string, decoded: array}
            $command = $result['command'] ?? '';
            $statsData = $result['decoded'] ?? [];

            if (!\is_array($statsData)) {
                throw new \Exception('Invalid data from nfdump processor');
            }

            // reset table
            $event = $this->sse->patchElements('<div id="statsTable"></div>');
            $response->write($event);

            $tableHtml = Table::generate($statsData, 'statsTable', [
                'hiddenFields' => [],
                'linkIpAddresses' => true,
                'originalData' => $result['rawOutput'] ?? null,
            ]);
            $event = $this->sse->patchElements($tableHtml);
            $response->write($event);

            $executionTime = round(microtime(true) - $time, 3);
            // Show success message with command (reset=true clears previous messages)
            if (!empty($command)) {
                $this->sendSuccessMessage($response, "<b>nfdump command:</b> <code>{$command}</code> took {$executionTime} seconds.", true);
            } else {
                $this->sendSuccessMessage($response, "Statistics processed successfully in {$executionTime} seconds.", true);
            }

            // Send stderr warning if present (appends to message container)
            if (!empty($result['stderr'])) {
                $this->sendWarning($response, '<b>nfdump warning:</b> ' . htmlspecialchars((string) $result['stderr']));
            }

            // Update numFlows signal
            $numFlows = \count($statsData);
            $event = $this->sse->patchSignals(['stats' => ['count' => $numFlows]]);
            $response->write($event);
        } catch (\Exception $e) {
            $this->debug->log('Statistics processing error: ' . $e->getMessage(), LOG_ERR);
            $this->sendError($response, 'Error processing statistics: ' . $e->getMessage() . '<br><b>nfdump command:</b><code> ' . ($command ?? 'N/A') . var_export($result ?? null, true) . '</code>');
        }
    }
}
