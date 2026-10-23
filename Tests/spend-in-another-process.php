<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

/*
 * Spends a part of an order from the gift cards of its cart, from a process of its own, in a transaction it
 * commits: the settlement of another request. Run by GiftCardReleaseConcurrencyTest with the environment of
 * the test process, so that it works on the same database.
 *
 * Usage: php spend-in-another-process.php <bootstrap file> <order id> <amount>
 *
 * Prints, as the last thing on its output, a JSON object: {"outcome": "spent"} or
 * {"outcome": "refused", "class": ..., "message": ...}.
 */

use Propel\Runtime\Propel;
use Thelia\Model\OrderQuery;
use TheliaGiftCard\Model\Map\GiftCardTableMap;
use TheliaGiftCard\Service\GiftCardPaymentService;

require (string) ($argv[1] ?? throw new RuntimeException('No bootstrap file.'));

$orderId = (int) ($argv[2] ?? 0);
$amount = (string) ($argv[3] ?? '0');

$kernelClass = $_SERVER['KERNEL_CLASS'] ?? 'App\Kernel';
$kernel = new $kernelClass('test', false);
$kernel->boot();

$connection = Propel::getWriteConnection(GiftCardTableMap::DATABASE_NAME);
$connection->beginTransaction();

try {
    $order = OrderQuery::create()->findPk($orderId) ?? throw new RuntimeException('No order '.$orderId);
    (new GiftCardPaymentService())->spend($order, $amount);
    $connection->commit();
    $outcome = ['outcome' => 'spent'];
} catch (Throwable $throwable) {
    $connection->rollBack();
    $outcome = ['outcome' => 'refused', 'class' => $throwable::class, 'message' => $throwable->getMessage()];
}

echo "\n", json_encode($outcome, \JSON_THROW_ON_ERROR);

$kernel->shutdown();
