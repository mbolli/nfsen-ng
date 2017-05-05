#!/usr/bin/php
<?php
spl_autoload_extensions('.php');
spl_autoload_register();

\common\Config::initialize();

if ($argc < 2 || in_array($argv[1], array('--help', '-help', '-h', '-?'))) {
?>

    This is the command line interface to nfsen-ng.

    Usage:
        <?php echo $argv[0]; ?> [options] command

    Options:
        -v  Show verbose output
        -p  Also import ports

    Commands:
        import  - Import existing nfdump data to nfsen-ng.
            Notice: Can take up to 10 minutes per source per year of data.

<?php
}

else {
    if (in_array('import', $argv)) {
        $start = new DateTime();
        $start->setDate(date('Y') - 3, date('m'), date('d'));
        $i = new \common\Import();
        if (in_array('-v', $argv)) $i->setVerbose(true);
        if (in_array('-v', $argv)) $i->setProcessPorts(true);
        $i->start($start);
    }
}

