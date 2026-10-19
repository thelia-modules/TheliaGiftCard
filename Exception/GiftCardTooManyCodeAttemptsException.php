<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Exception;

/**
 * A customer or a client typed more gift card codes than the limits allow: the code is not even
 * looked up. The message never holds a code.
 */
final class GiftCardTooManyCodeAttemptsException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Too many codes were tried: try again later.');
    }
}
