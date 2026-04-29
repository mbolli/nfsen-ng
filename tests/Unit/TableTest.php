<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Table;

describe('Table', function (): void {
    describe('generate', function (): void {
        test('generates HTML table from array data', function (): void {
            $data = [
                ['name' => 'Test', 'value' => 123],
                ['name' => 'Test2', 'value' => 456],
            ];

            $result = Table::generate($data, 'testTable');

            expect($result)
                ->toBeString()
                ->toContain('<table')
                ->toContain('testTable')
                ->toContain('Test')
                ->toContain('123')
            ;
        });

        test('handles empty data with custom message', function (): void {
            $result = Table::generate([], 'emptyTable', [
                'emptyMessage' => 'No records found',
            ]);

            expect($result)
                ->toContain('No records found')
                ->toContain('emptyTable')
            ;
        });

        test('hides specified fields', function (): void {
            $data = [
                ['visible' => 'yes', 'hidden' => 'no', 'cnt' => 5],
            ];

            $result = Table::generate($data, 'testTable', [
                'hiddenFields' => ['hidden', 'cnt'],
            ]);

            expect($result)
                ->toContain('visible')
                ->not->toContain('>hidden<')
                ->not->toContain('>cnt<')
            ;
        });

        test('applies custom CSS class', function (): void {
            $data = [
                ['col' => 'val'],
            ];

            $result = Table::generate($data, 'testTable', [
                'cssClass' => 'custom-table-class',
            ]);

            expect($result)->toContain('custom-table-class');
        });

        test('handles string data rows as preformatted text', function (): void {
            $data = ['line 1', 'line 2', 'line 3'];

            $result = Table::generate($data, 'textTable');

            expect($result)
                ->toContain('<pre>')
                ->toContain('line 1')
            ;
        });

        test('generates proper table headers', function (): void {
            $data = [
                ['srcip' => '10.0.0.1', 'dstip' => '10.0.0.2', 'bytes' => 1000],
            ];

            $result = Table::generate($data, 'flowTable');

            expect($result)
                ->toContain('<th')
                ->toContain('<thead')
            ;
        });

        test('generates table body with data rows', function (): void {
            $data = [
                ['ip' => '10.0.0.1', 'port' => 80],
                ['ip' => '10.0.0.2', 'port' => 443],
            ];

            $result = Table::generate($data, 'dataTable');

            expect($result)
                ->toContain('<tbody')
                ->toContain('10.0.0.1')
                ->toContain('10.0.0.2')
            ;
        });

        test('escapes HTML in cell values', function (): void {
            $data = [
                ['content' => '<script>alert("xss")</script>'],
            ];

            $result = Table::generate($data, 'xssTable');

            expect($result)
                ->not->toContain('<script>')
                ->toContain('&lt;script&gt;')
            ;
        });
    });

    describe('field title mapping', function (): void {
        test('maps srcip to Source IP', function (): void {
            $data = [['srcip' => '10.0.0.1']];
            $result = Table::generate($data, 'test');

            expect($result)->toContain('Source IP');
        });

        test('maps dstip to Destination IP', function (): void {
            $data = [['dstip' => '10.0.0.2']];
            $result = Table::generate($data, 'test');

            expect($result)->toContain('Destination IP');
        });

        test('maps proto to Protocol', function (): void {
            $data = [['proto' => 'TCP']];
            $result = Table::generate($data, 'test');

            expect($result)->toContain('Protocol');
        });

        test('maps srcport to Source Port', function (): void {
            $data = [['srcport' => 12345]];
            $result = Table::generate($data, 'test');

            expect($result)->toContain('Source Port');
        });

        test('maps dstport to Destination Port', function (): void {
            $data = [['dstport' => 443]];
            $result = Table::generate($data, 'test');

            expect($result)->toContain('Destination Port');
        });
    });

    describe('default hidden fields', function (): void {
        test('hides cnt field by default', function (): void {
            $data = [['name' => 'test', 'cnt' => 10]];
            $result = Table::generate($data, 'test');

            expect($result)->not->toContain('>cnt<');
        });

        test('hides type field by default', function (): void {
            $data = [['name' => 'test', 'type' => 'flow']];
            $result = Table::generate($data, 'test');

            expect($result)->not->toContain('>type<');
        });

        test('hides sampled field by default', function (): void {
            $data = [['name' => 'test', 'sampled' => 1]];
            $result = Table::generate($data, 'test');

            expect($result)->not->toContain('>sampled<');
        });
    });
});
