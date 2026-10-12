<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Tests;

use Thelia\Log\AbstractTlogDestination;

/**
 * Keeps the log lines in memory so that a test can read what a controller logged.
 */
final class RecordingTlogDestination extends AbstractTlogDestination
{
    /** @var list<string> */
    public static array $lines = [];

    public function add($string): void
    {
        self::$lines[] = (string) $string;
    }

    public function write(&$res): void
    {
    }

    public function getTitle(): string
    {
        return 'Recording';
    }

    public function getDescription(): string
    {
        return 'Keeps the log lines in memory (tests).';
    }
}
