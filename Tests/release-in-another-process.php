<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

/*
 * Gives back what an order spent from gift cards, from a process of its own, the way a second request does:
 * its own kernel, its own connection, in a transaction it opens and ends. Run by
 * GiftCardReleaseConcurrencyTest with the environment of the test process, so that it works on the same
 * database.
 *
 * Usage: php release-in-another-process.php <bootstrap file> <order id> [expected amount]
 *
 * Prints, as the last thing on its output, a JSON object: {"outcome": "released"} or
 * {"outcome": "refused", "class": ..., "message": ..., "sqlCodes": [codes of the PDOException of the chain]}.
 */

use Propel\Runtime\Propel;
use TheliaGiftCard\Model\Map\GiftCardTableMap;
use TheliaGiftCard\Service\GiftCardPaymentService;

require (string) ($argv[1] ?? throw new RuntimeException('No bootstrap file.'));

$orderId = (int) ($argv[2] ?? 0);
$expectedAmount = isset($argv[3]) && '' !== $argv[3] ? $argv[3] : null;

$kernelClass = $_SERVER['KERNEL_CLASS'] ?? 'App\Kernel';
$kernel = new $kernelClass('test', false);
$kernel->boot();

$connection = Propel::getWriteConnection(GiftCardTableMap::DATABASE_NAME);
$connection->beginTransaction();

try {
    (new GiftCardPaymentService())->release($orderId, $expectedAmount);
    $connection->commit();
    $outcome = ['outcome' => 'released'];
} catch (Throwable $throwable) {
    $connection->rollBack();
    $sqlCodes = [];
    for ($cause = $throwable; null !== $cause; $cause = $cause->getPrevious()) {
        if ($cause instanceof PDOException) {
            $sqlCodes[] = $cause->errorInfo[1] ?? null;
        }
    }
    $outcome = ['outcome' => 'refused', 'class' => $throwable::class, 'message' => $throwable->getMessage(), 'sqlCodes' => $sqlCodes];
}

echo "\n", json_encode($outcome, \JSON_THROW_ON_ERROR);

$kernel->shutdown();
