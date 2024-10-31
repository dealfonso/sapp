<?php

namespace ddn\sapp;

use Psr\Log\AbstractLogger;

class AlmostOriginalLogger extends AbstractLogger
{
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $dinfo = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT);
        $dinfo = $dinfo[1];

        fwrite(STDERR, sprintf('[%s] %s:%s %s' . PHP_EOL, $level, $dinfo['file'], $dinfo['line'], $message));
    }
}
