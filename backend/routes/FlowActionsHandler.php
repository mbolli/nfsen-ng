<?php

/**
 * Handle flow-related actions from Datastar events.
 * Processes flow queries and returns formatted results.
 */

declare(strict_types=1);

namespace mbolli\nfsen_ng\routes;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\Table;
use starfederation\datastar\ServerSentEventGenerator;
use Swoole\Http\Request;
use Swoole\Http\Response;

class FlowActionsHandler extends StreamHandler {
    protected const MESSAGE_TARGET = 'flowMessage';

    public function __construct(Debug $debug, ServerSentEventGenerator $sse) {
        parent::__construct($debug, $sse);
    }

    public function handle(Request $request, Response $response): void {
        $signals = $this->extractSignals($request);

        $this->debug->log('Processing flows', LOG_DEBUG);

        $this->processFlows($request, $response, $signals);

        $response->end();
    }

    /**
     * Process flows query and return results.
     * Matches logic from Api::flows() in legacy API.
     */
    private function processFlows(Request $request, Response $response, array $signals): void {
        // Extract parameters from signals
        $datestart = (int) ($signals['datestart'] ?? time() - 3600);
        $dateend = (int) ($signals['dateend'] ?? time());
        $fSignals = $signals['flows'] ?? '';
        $filter = $fSignals['nfdump_filter'] ?? '';
        $limit = (int) ($fSignals['limit'] ?? 50);
        $time = microtime(true);

        // Parse aggregation
        $aggregation = $fSignals['aggregation'] ?? [];
        $aggregate = $this->buildAggregationString($aggregation);

        // Parse output format
        $outputFormat = $fSignals['outputFormat'] ?? 'line';
        $customFormat = $fSignals['customOutputFormat'] ?? '';
        $output = [
            'format' => $outputFormat === 'custom' ? 'custom' : $outputFormat,
            'custom' => $customFormat,
        ];

        // Other options
        $sort = !empty($fSignals['orderByTstart']) ? 'tstart' : '';

        // reset table
        $event = $this->sse->patchElements('<div id="flowTable"></div>');
        $response->write($event);

        try {
            // Build processor options matching legacy API
            $sources = implode(':', Config::$cfg['general']['sources']);
            $aggregate_command = '';
            if (!empty($aggregate)) {
                $aggregate_command = ($aggregate === 'bidirectional') ? '-B' : '-A' . $aggregate; // no space inbetween
            }

            $processor = new Config::$processorClass();
            $processor->setOption('-M', $sources);
            $processor->setOption('-R', [$datestart, $dateend]);
            $processor->setOption('-c', $limit);
            // Use JSON output format for structured data
            $processor->setOption('-o', 'json');
            if (!empty($sort)) {
                $processor->setOption('-O', 'tstart');
            }
            if (!empty($aggregate_command)) {
                $processor->setOption('-a', $aggregate_command);
            }
            $processor->setFilter($filter);

            $result = $processor->execute();

            // Result format: {command: string, rawOutput: string, decoded: array}
            $command = $result['command'] ?? '';
            $flowData = $result['decoded'] ?? [];

            if (!\is_array($flowData)) {
                throw new \Exception('Invalid data from nfdump processor<br>nfdump command: <code>' . $command . '</code>');
            }

            $executionTime = round(microtime(true) - $time, 3);

            // Update numFlows signal
            $numFlows = \count($flowData);
            $event = $this->sse->patchSignals(['flows' => ['count' => $numFlows]]);
            $response->write($event);

            // Show success message with command (reset=true clears previous messages)
            if (!empty($command)) {
                $this->sendSuccessMessage($response, "<b>nfdump command:</b> <code>{$command}</code> took {$executionTime} seconds.", true);
            } else {
                $this->sendSuccessMessage($response, "Flows processed successfully in {$executionTime} seconds.", true);
            }

            // Send stderr warning if present (appends to message container)
            if (!empty($result['stderr'])) {
                $this->sendWarning($response, '<b>nfdump warning:</b> ' . htmlspecialchars((string) $result['stderr']));
            }

            // Generate HTML table from JSON data
            $tableHtml = Table::generate($flowData, 'flowTable', [
                'hiddenFields' => [],
                'linkIpAddresses' => true,
                'originalData' => $result['rawOutput'] ?? null,
            ]);

            // Update the table container
            $event = $this->sse->patchElements($tableHtml);
            $response->write($event);
        } catch (\Exception $e) {
            $this->debug->log('Flow processing error: ' . $e->getMessage(), LOG_ERR);
            $this->sendError($response, 'Error processing flows: ' . $e->getMessage(), true);
        }
    }

    /**
     * Build aggregation string from aggregation object.
     */
    private function buildAggregationString(array $aggregation): string {
        if (!empty($aggregation['bidirectional'])) {
            return 'bidirectional';
        }

        $parts = [];

        // Protocol
        if (!empty($aggregation['proto'])) {
            $parts[] = 'proto';
        }

        // Source port
        if (!empty($aggregation['srcport'])) {
            $parts[] = 'srcport';
        }

        // Destination port
        if (!empty($aggregation['dstport'])) {
            $parts[] = 'dstport';
        }

        // Source IP
        if (!empty($aggregation['srcip']) && $aggregation['srcip'] !== 'none') {
            $srcip = $aggregation['srcip'];
            if (\in_array($srcip, ['srcip4', 'srcip6'], true) && !empty($aggregation['srcipPrefix'])) {
                $parts[] = $srcip . '/' . $aggregation['srcipPrefix'];
            } else {
                $parts[] = $srcip;
            }
        }

        // Destination IP
        if (!empty($aggregation['dstip']) && $aggregation['dstip'] !== 'none') {
            $dstip = $aggregation['dstip'];
            if (\in_array($dstip, ['dstip4', 'dstip6'], true) && !empty($aggregation['dstipPrefix'])) {
                $parts[] = $dstip . '/' . $aggregation['dstipPrefix'];
            } else {
                $parts[] = $dstip;
            }
        }

        return implode(',', $parts);
    }
}
