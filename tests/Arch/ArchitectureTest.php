<?php

declare(strict_types=1);

// Strict types enforcement
arch('common classes use strict types')
    ->expect('mbolli\nfsen_ng\common')
    ->toUseStrictTypes();

arch('datasources use strict types')
    ->expect('mbolli\nfsen_ng\datasources')
    ->toUseStrictTypes();

arch('processors use strict types')
    ->expect('mbolli\nfsen_ng\processor')
    ->toUseStrictTypes();

// Interface implementation
arch('Rrd implements Datasource interface')
    ->expect('mbolli\nfsen_ng\datasources\Rrd')
    ->toImplement('mbolli\nfsen_ng\datasources\Datasource');

arch('VictoriaMetrics implements Datasource interface')
    ->expect('mbolli\nfsen_ng\datasources\VictoriaMetrics')
    ->toImplement('mbolli\nfsen_ng\datasources\Datasource');

arch('Nfdump implements Processor interface')
    ->expect('mbolli\nfsen_ng\processor\Nfdump')
    ->toImplement('mbolli\nfsen_ng\processor\Processor');

// Class design
arch('Nfdump is not abstract')
    ->expect('mbolli\nfsen_ng\processor\Nfdump')
    ->not->toBeAbstract();

arch('Config is abstract')
    ->expect('mbolli\nfsen_ng\common\Config')
    ->toBeAbstract();

arch('Datasource is an interface')
    ->expect('mbolli\nfsen_ng\datasources\Datasource')
    ->toBeInterface();

arch('Processor is an interface')
    ->expect('mbolli\nfsen_ng\processor\Processor')
    ->toBeInterface();

// Forbidden functions in library code
arch('no die or exit in common code')
    ->expect('mbolli\nfsen_ng\common')
    ->not->toUse(['die', 'exit']);

arch('no die or exit in datasources')
    ->expect('mbolli\nfsen_ng\datasources')
    ->not->toUse(['die', 'exit']);

arch('no die or exit in processors')
    ->expect('mbolli\nfsen_ng\processor')
    ->not->toUse(['die', 'exit']);

// Dependency rules
