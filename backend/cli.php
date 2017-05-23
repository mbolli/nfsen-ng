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
        -p  Import ports per source
        -f  Force overwriting database and start at the beginning

    Commands:
        import  - Import existing nfdump data to nfsen-ng.
            Notice: If you have existing nfcapd files, better do this overnight.
        start   - Start the daemon for continuous reading of new data
        stop    - Stop the daemon
        status  - Get the daemon's status

<?php
}

else {
    $folder = dirname(__FILE__);
    $pidfile = $folder . '/nfsen-ng.pid';

    if (in_array('import', $argv)) {

        $start = new DateTime();
        $start->setDate(date('Y') - 3, date('m'), date('d'));
        $i = new \common\Import();
        if (in_array('-v', $argv)) $i->setVerbose(true);
        if (in_array('-p', $argv)) $i->setProcessPorts(true);
        if (in_array('-f', $argv)) $i->setForce(true);
        $i->start($start);

    } elseif (in_array('start', $argv)) {

        $pid = exec('nohup `which php` ' . $folder . '/listen.php > nfsen-ng.log 2>&1 & echo $!', $op, $exit);
        var_dump($exit);
        switch (intval($exit)) {
            case 128: echo 'Unexpected error opening or locking lock file. Perhaps you don\'t have permission to write to the lock file or its containing directory?'; break;
            case 129: echo 'Another instance is already running; terminating.'; break;
            default: echo 'Daemon running, pid=' . $pid; break;
        }
        echo "\n";

    } elseif (in_array('stop', $argv)) {

        if (!file_exists($pidfile)) {
            echo "Not running\n";
            exit();
        }
        $pid = file_get_contents($pidfile);
        exec('kill ' . $pid);

        echo "Stopped. \n";

    } elseif (in_array('status', $argv)) {

        if (!file_exists($pidfile)) {
            echo "Not running\n";
            exit();
        }
        $pid = file_get_contents($pidfile);
        exec('ps -p ' . $pid, $op);
        if (!isset($op[1])) echo "Not running\n";
        else echo 'Running: ' . $pid;

    }
}

