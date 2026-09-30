<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Tests;

use Random\Engine;

/**
 * An engine that always draws the same bytes: every code it produces is the same one.
 */
final class FixedEngine implements Engine
{
    public function generate(): string
    {
        return "\x00\x00\x00\x00\x00\x00\x00\x00";
    }
}
