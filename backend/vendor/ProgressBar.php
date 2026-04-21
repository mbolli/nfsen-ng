<?php

/**
 * PHP CLI Progress bar.
 *
 * PHP 5
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright 2011, Andy Dawson
 *
 * @see          http://ad7six.com
 *
 * @license       MIT License (http://www.opensource.org/licenses/mit-license.php)
 */

/**
 * progressbar.
 *
 * Static wrapper class for generating progress bars for cli tasks
 */

namespace mbolli\nfsen_ng\vendor;

class ProgressBar {
    /**
     * Merged with options passed in start function.
     */
    protected static $defaults = ['format' => "\r:message::padding:%.01f%% %2\$d/%3\$d ETC: %4\$s. Elapsed: %5\$s [%6\$s]", 'message' => 'Running', 'size' => 30, 'width' => null];

    /**
     * Runtime options.
     */
    protected static $options = [];

    /**
     * Detect if running in Docker (or other non-interactive environment).
     */
    protected static $isDocker = null;

    /**
     * How much have we done already.
     */
    protected static $done = 0;

    /**
     * The format string used for the rendered status bar - see $defaults.
     */
    protected static $format;

    /**
     * message to display prefixing the progress bar text.
     */
    protected static $message;

    /**
     * How many chars to use for the progress bar itself. Not to be confused with $width.
     */
    protected static $size = 30;

    /**
     * When did we start (timestamp).
     */
    protected static $start;

    /**
     * The width in characters the whole rendered string must fit in. defaults to the width of the
     * terminal window.
     */
    protected static $width;

    /**
     * What's the total number of times we're going to call set.
     */
    protected static $total;

    /**
     * Detect if running in Docker or non-interactive environment.
     */
    protected static function isDocker(): bool {
        if (self::$isDocker === null) {
            // Check for Docker environment indicators
            self::$isDocker = file_exists('/.dockerenv') 
                || (file_exists('/proc/1/cgroup') && strpos(file_get_contents('/proc/1/cgroup'), 'docker') !== false)
                || getenv('DOCKER_CONTAINER') !== false
                || !posix_isatty(STDOUT);
        }
        return self::$isDocker;
    }

    /**
     * Show a progress bar, actually not usually called explicitly. Called by next().
     *
     * @param int $done what fraction of $total to set as progress uses internal counter if not passed
     *
     * @static
     *
     * @return string the formatted progress bar prefixed with a carriage return
     */
    public static function display($done = null) {
        if ($done) {
            self::$done = $done;
        }

        // In Docker or non-interactive mode, output progress every 5% or specific milestones
        if (self::isDocker()) {
            $fractionComplete = self::$total ? (float) (self::$done / self::$total) : 0;
            $percent = number_format($fractionComplete * 100, 1);
            
            // Only output every 5% to avoid log spam
            static $lastPercent = -1;
            $currentPercentMilestone = floor($percent / 5) * 5;
            
            if ($currentPercentMilestone != $lastPercent || self::$done === self::$total) {
                $lastPercent = $currentPercentMilestone;
                $elapsed = time() - self::$start;
                $timeElapsed = self::humanTime($elapsed);
                
                $rate = self::$done ? $elapsed / self::$done : 0;
                $left = self::$total - self::$done;
                $etc = round($rate * $left, 2);
                $timeRemaining = self::humanTime($etc, self::$done ? '< 1 sec' : '???');
                
                return sprintf(
                    "\n%s: %.01f%% (%d/%d) - ETC: %s, Elapsed: %s",
                    self::$message,
                    $percent,
                    self::$done,
                    self::$total,
                    $timeRemaining,
                    $timeElapsed
                );
            }
            return ''; // Don't output between milestones
        }

        $now = time();

        if (self::$total) {
            $fractionComplete = (float) (self::$done / self::$total);
        } else {
            $fractionComplete = 0;
        }

        $bar = floor($fractionComplete * self::$size);
        $barSize = min($bar, self::$size);

        $barContents = str_repeat('=', $barSize);
        if ($bar < self::$size) {
            $barContents .= '>';
            $barContents .= str_repeat(' ', self::$size - $barSize);
        } elseif ($fractionComplete > 1) {
            $barContents .= '!';
        } else {
            $barContents .= '=';
        }

        $percent = number_format($fractionComplete * 100, 1);

        $elapsed = $now - self::$start;
        if (self::$done) {
            $rate = $elapsed / self::$done;
        } else {
            $rate = 0;
        }
        $left = self::$total - self::$done;
        $etc = round($rate * $left, 2);

        if (self::$done) {
            $etcNowText = '< 1 sec';
        } else {
            $etcNowText = '???';
        }
        $timeRemaining = self::humanTime($etc, $etcNowText);
        $timeElapsed = self::humanTime($elapsed);

        $return = \sprintf(
            self::$format,
            $percent,
            self::$done,
            self::$total,
            $timeRemaining,
            $timeElapsed,
            $barContents
        );

        $width = \strlen((string) preg_replace('@(?:\r|:\w+:)@', '', $return));

        if (\strlen((string) self::$message) > ((int) self::$width - (int) $width - 3)) {
            $message = substr((string) self::$message, 0, (int) self::$width - (int) $width - 4) . '...';
            $padding = '';
        } else {
            $message = self::$message;
            $width += \strlen((string) $message);
            $padding = str_repeat(' ', (int) self::$width - (int) $width);
        }

        return str_replace([':message:', ':padding:'], [$message, $padding], $return);
    }

    /**
     * reset internal state, and send a new line so that the progress bar text is "finished".
     *
     * @static
     *
     * @return string a new line
     */
    public static function finish() {
        self::reset();

        return "\n";
    }

    /**
     * Increment the internal counter, and returns the result of display.
     *
     * @param int    $inc     Amount to increment the internal counter
     * @param string $message If passed, overrides the existing message
     *
     * @static
     *
     * @return string - the progress bar
     */
    public static function next($inc = 1, $message = '') {
        self::$done += $inc;

        if ($message) {
            self::$message = $message;
        }

        return self::display();
    }

    /**
     * Called by start and finish.
     *
     * @param array $options array
     *
     * @static
     */
    public static function reset(array $options = []): void {
        $options = array_merge(self::$defaults, $options);

        if (empty($options['done'])) {
            $options['done'] = 0;
        }
        if (empty($options['start'])) {
            $options['start'] = time();
        }
        if (empty($options['total'])) {
            $options['total'] = 0;
        }

        self::$done = $options['done'];
        self::$format = $options['format'];
        self::$message = $options['message'];
        self::$size = $options['size'];
        self::$start = $options['start'];
        self::$total = $options['total'];
        self::setWidth($options['width']);
    }

    /**
     * change the message to be used the next time the display method is called.
     *
     * @param string $message the string to display
     *
     * @static
     */
    public static function setMessage($message = ''): void {
        self::$message = $message;
    }

    /**
     * change the total on a running progress bar.
     *
     * @param int|string $total the new number of times we're expecting to run for
     *
     * @static
     */
    public static function setTotal($total = ''): void {
        self::$total = $total;
    }

    /**
     * Initialize a progress bar.
     *
     * @param null|int $total   number of times we're going to call set
     * @param string   $message message to prefix the bar with
     * @param array    $options overrides for default options
     *
     * @static
     *
     * @return string - the progress bar string with 0 progress
     */
    public static function start(?int $total = null, string $message = '', array $options = []) {
        if ($message) {
            $options['message'] = $message;
        }
        $options['total'] = $total;
        $options['start'] = time();
        self::reset($options);

        return self::display();
    }

    /**
     * Convert a number of seconds into something human readable like "2 days, 4 hrs".
     *
     * @param float|int $seconds how far in the future/past to display
     * @param string    $nowText if there are no seconds, what text to display
     *
     * @static
     *
     * @return string representation of the time
     */
    protected static function humanTime($seconds, string $nowText = '< 1 sec') {
        $prefix = '';
        if ($seconds < 0) {
            $prefix = '- ';
            $seconds = -$seconds;
        }

        $days = $hours = $minutes = 0;

        if ($seconds >= 86400) {
            $days = (int) ($seconds / 86400);
            $seconds = $seconds - $days * 86400;
        }
        if ($seconds >= 3600) {
            $hours = (int) ($seconds / 3600);
            $seconds = $seconds - $hours * 3600;
        }
        if ($seconds >= 60) {
            $minutes = (int) ($seconds / 60);
            $seconds = $seconds - $minutes * 60;
        }
        $seconds = (int) $seconds;

        $return = [];

        if ($days) {
            $return[] = "{$days} days";
        }
        if ($hours) {
            $return[] = "{$hours} hrs";
        }
        if ($minutes) {
            $return[] = "{$minutes} mins";
        }
        if ($seconds) {
            $return[] = "{$seconds} secs";
        }

        if (!$return) {
            return $nowText;
        }

        return $prefix . implode(', ', \array_slice($return, 0, 2));
    }

    /**
     * Set the width the rendered text must fit in.
     *
     * @param int $width passed in options
     *
     * @static
     */
    protected static function setWidth($width = null): void {
        if ($width === null) {
            if (\DIRECTORY_SEPARATOR === '/' && getenv('TERM')) {
                $width = shell_exec('tput cols');
            }
            if ($width < 80) {
                $width = 80;
            }
        }
        self::$width = $width;
    }
}
