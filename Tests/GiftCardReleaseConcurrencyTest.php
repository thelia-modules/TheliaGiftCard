<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Tests;

use PHPUnit\Framework\Attributes\Group;
use Propel\Runtime\Connection\ConnectionFactory;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Connection\ConnectionManagerSingle;
use Propel\Runtime\Propel;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Model\Cart;
use Thelia\Model\CountryQuery;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Customer;
use Thelia\Model\CustomerTitleQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderAddress;
use Thelia\Model\OrderPaymentPart;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;
use TheliaGiftCard\EventListener\OrderAfterPayListener;
use TheliaGiftCard\Exception\GiftCardReleaseRefusedException;
use TheliaGiftCard\Model\GiftCardCart;
use TheliaGiftCard\Model\GiftCardOrder;
use TheliaGiftCard\Model\Map\GiftCardTableMap;
use TheliaGiftCard\Service\GiftCardPaymentService;
use TheliaGiftCard\TheliaGiftCard;

/**
 * The cards of an order given back by two requests at once, and by a request whose read view of the
 * database is older than what another request committed.
 *
 * Every other test of the module runs inside the transaction the test case wraps around it, where nothing
 * another session commits is at stake. Here the fixtures are committed, and deleted by hand; the competing
 * request is a second PHP process, with its own kernel and connection, started while this one holds the
 * locks. Run on a disposable database only (GiftCardTestCase refuses any other).
 */
#[Group('concurrency')]
final class GiftCardReleaseConcurrencyTest extends GiftCardTestCase
{
    protected bool $useTransaction = false;

    private const PROCESS_TIMEOUT = 60;

    private ?ConnectionInterface $otherSession = null;

    /** @var list<int> */
    private array $orderIds = [];

    /** @var list<int> */
    private array $orderAddressIds = [];

    /** @var list<int> */
    private array $cartIds = [];

    /** @var list<int> */
    private array $customerIds = [];

    /** @var list<int> */
    private array $giftCardIds = [];

    /** @var array<int, array<int, resource>> */
    private array $pipes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->otherSession = $this->openOtherSession();
    }

    protected function tearDown(): void
    {
        try {
            $connection = Propel::getWriteConnection(GiftCardTableMap::DATABASE_NAME);

            while ($connection->inTransaction()) {
                $connection->rollBack();
            }

            $this->deleteFixtures();
        } finally {
            Propel::disableInstancePooling();

            parent::tearDown();
        }
    }

    public function testTheOtherProcessWorksOnTheDatabaseOfThisProcess(): void
    {
        $databaseName = (string) ($_SERVER['DATABASE_NAME'] ?? getenv('DATABASE_NAME'));
        self::assertSame($databaseName, (string) $this->otherSession?->query('SELECT DATABASE()')->fetchColumn());

        [$order] = $this->committedOrderWithCards(['30' => '40']);
        $outcome = $this->waitFor($this->releaseInAnotherProcess($order, '30.000000'));

        self::assertSame('released', $outcome['outcome']);
        self::assertSame(0, $this->storedRowsOf($order));
        self::assertSame(1, $this->storedRowsOf($order, GiftCardPaymentService::STATUS_GIVEN_BACK));
    }

    /**
     * Two releases of the same order at once: this one holds the lock of the card, the other has read
     * the rows and waits for the card. Once this one has credited and committed, the other locks the
     * card, reads its rows again, finds them given back and credits nothing. On MariaDB 11.6.2 and later
     * (snapshot isolation) the database refuses that second locking read instead (1020), which the service
     * turns into a refusal: either way the card is credited once.
     */
    public function testTwoReleasesAtOnceCreditTheCardOnce(): void
    {
        [$order, $giftCards] = $this->committedOrderWithCards(['30' => '40']);
        $connection = Propel::getWriteConnection(GiftCardTableMap::DATABASE_NAME);
        $connection->beginTransaction();
        $connection->query('SELECT `id` FROM `gift_card` WHERE `id` = '.$giftCards[0].' FOR UPDATE')->fetchAll();

        $process = $this->releaseInAnotherProcess($order);
        $this->waitUntilTheOtherProcessWaitsOnTheCards($process);

        (new GiftCardPaymentService())->release((int) $order->getId());
        $connection->commit();
        $outcome = $this->waitFor($process);

        $this->assertTheOtherReleaseCreditedNothing($outcome);
        self::assertSame('10.000000', $this->storedSpentOn($giftCards[0]), 'The card was credited once: 40 less the 30 of the order.');
        self::assertSame(0, $this->storedRowsOf($order));
        self::assertSame(1, $this->storedRowsOf($order, GiftCardPaymentService::STATUS_GIVEN_BACK));
    }

    /**
     * The same, for the release of a part: the second release finds the rows given back, which add up to
     * its amount (a replay: it succeeds and credits nothing), or is refused by the database (1020).
     */
    public function testTwoReleasesOfAPartAtOnceCreditTheCardOnce(): void
    {
        [$order, $giftCards] = $this->committedOrderWithCards(['30' => '40']);
        $connection = Propel::getWriteConnection(GiftCardTableMap::DATABASE_NAME);
        $connection->beginTransaction();
        $connection->query('SELECT `id` FROM `gift_card` WHERE `id` = '.$giftCards[0].' FOR UPDATE')->fetchAll();

        $process = $this->releaseInAnotherProcess($order, '30.000000');
        $this->waitUntilTheOtherProcessWaitsOnTheCards($process);

        $this->giftCardModule()->releasePart($this->partOf($order, '30.000000'));
        $connection->commit();
        $outcome = $this->waitFor($process);

        $this->assertTheOtherReleaseCreditedNothing($outcome);
        self::assertSame('10.000000', $this->storedSpentOn($giftCards[0]));
        self::assertSame(0, $this->storedRowsOf($order));
        self::assertSame(1, $this->storedRowsOf($order, GiftCardPaymentService::STATUS_GIVEN_BACK));
    }

    /**
     * A transaction whose read view was taken before another request settled the part (the order was not
     * locked before the first read) does not see the rows that request committed: its first read of the
     * rows finds none. Releasing on that would credit nothing, and say nothing; the part says what was
     * spent, so the release is refused and the rows are left as they are.
     */
    public function testAReleaseWhoseReadViewIsOlderThanTheSettlementIsRefusedNotSkipped(): void
    {
        [$order, $giftCards] = $this->committedOrderWithCards([], ['30' => '0']);
        $connection = Propel::getWriteConnection(GiftCardTableMap::DATABASE_NAME);
        $connection->beginTransaction();
        // The read that freezes the view of this transaction.
        self::assertSame(0, (int) $connection->query('SELECT COUNT(*) FROM `gift_card_order` WHERE `order_id` = '.$order->getId())->fetchColumn());

        $outcome = $this->waitFor($this->spendInAnotherProcess($order, '30'));
        self::assertSame('spent', $outcome['outcome']);
        self::assertSame(1, $this->storedRowsOf($order), 'The other request committed its rows.');
        self::assertSame(0, (int) $connection->query('SELECT COUNT(*) FROM `gift_card_order` WHERE `order_id` = '.$order->getId())->fetchColumn(), 'This transaction does not see them.');

        try {
            $this->giftCardModule()->releasePart($this->partOf($order, '30.000000'));
            self::fail('A release that cannot see what was spent must not pass for done.');
        } catch (GiftCardReleaseRefusedException) {
        }

        $connection->rollBack();
        self::assertSame('30.000000', $this->storedSpentOn($giftCards[0]), 'Nothing was credited.');
        self::assertSame(1, $this->storedRowsOf($order), 'The rows are still there, spent, for the next release.');

        // Asked again from a transaction that sees them, the release goes through.
        $this->giftCardModule()->releasePart($this->partOf($order, '30.000000'));
        self::assertSame('0.000000', $this->storedSpentOn($giftCards[0]));
        self::assertSame(0, $this->storedRowsOf($order));
        self::assertSame(1, $this->storedRowsOf($order, GiftCardPaymentService::STATUS_GIVEN_BACK));
    }

    /**
     * No transaction is open when an order is cancelled from the back office or from a console command:
     * the listener opens its own, and the credit is committed when it returns.
     */
    public function testTheCancellationListenerCommitsItsOwnTransactionWhenNoneIsOpen(): void
    {
        [$order, $giftCards] = $this->committedOrderWithCards(['30' => '40']);
        $canceled = OrderStatusQuery::create()->findOneByCode(OrderStatus::CODE_CANCELED);
        self::assertNotNull($canceled);
        $order->setStatusId($canceled->getId())->save();
        $order->setOrderStatus($canceled);
        self::assertFalse(Propel::getWriteConnection(GiftCardTableMap::DATABASE_NAME)->inTransaction());

        $listener = static::getContainer()->get(OrderAfterPayListener::class);
        self::assertInstanceOf(OrderAfterPayListener::class, $listener);
        $listener->onOrderCancelGiftCard(new OrderEvent($order));

        self::assertFalse(Propel::getWriteConnection(GiftCardTableMap::DATABASE_NAME)->inTransaction(), 'The transaction of the listener is over.');
        self::assertSame('10.000000', $this->storedSpentOn($giftCards[0]), 'Read by another session: the credit is committed.');
        self::assertSame(0, $this->storedRowsOf($order));
    }

    /**
     * @param array{outcome: string, class?: string, message?: string, sqlCodes?: list<int|null>} $outcome
     */
    private function assertTheOtherReleaseCreditedNothing(array $outcome): void
    {
        if ('released' === $outcome['outcome']) {
            return;
        }

        self::assertSame('refused', $outcome['outcome']);
        self::assertSame(GiftCardReleaseRefusedException::class, $outcome['class'] ?? null, 'Only the refusal of a snapshot conflict is acceptable.');
        self::assertContains(1020, $outcome['sqlCodes'] ?? [], 'The refusal comes from the snapshot conflict (1020), not from rows that do not add up.');
        self::assertStringNotContainsString('according to the rows read under lock', (string) ($outcome['message'] ?? ''), 'Not an amount mismatch.');
    }

    private function giftCardModule(): TheliaGiftCard
    {
        $module = ModuleQuery::create()->findOneByCode(TheliaGiftCard::MODULE_CODE)
            ?? throw new \RuntimeException('The gift card module is not registered: run bin/test-prepare.');
        $instance = $module->getPaymentModuleInstance(static::getContainer());
        self::assertInstanceOf(TheliaGiftCard::class, $instance);

        return $instance;
    }

    private function partOf(Order $order, string $amount): OrderPaymentPart
    {
        return (new OrderPaymentPart())->setOrderId($order->getId())->setAmount($amount);
    }

    /**
     * Committed fixtures. A card is created for each entry, carrying the given initial spend.
     *
     * @param array<int|string, string> $spent     amount the order spent from a card (a row of gift_card_order), initial spend of the card, which includes it
     * @param array<int|string, string> $onTheCart amount put on the cart for a card (no row of gift_card_order yet), initial spend of the card
     *
     * @return array{Order, list<int>} the order, the ids of its cards
     */
    private function committedOrderWithCards(array $spent, array $onTheCart = []): array
    {
        $title = CustomerTitleQuery::create()->findOne() ?? throw new \RuntimeException('No customer title.');
        $country = CountryQuery::create()->findOne() ?? throw new \RuntimeException('No country.');
        $currency = CurrencyQuery::create()->findOne() ?? throw new \RuntimeException('No currency.');
        $lang = LangQuery::create()->findOne() ?? throw new \RuntimeException('No lang.');
        $status = OrderStatusQuery::create()->findOneByCode(OrderStatus::CODE_NOT_PAID) ?? throw new \RuntimeException('No status.');
        $delivery = ModuleQuery::create()->findOneByCode('CustomDelivery') ?? throw new \RuntimeException('No delivery module.');
        $payment = ModuleQuery::create()->findOneByCode(TheliaGiftCard::MODULE_CODE) ?? throw new \RuntimeException('No gift card module.');

        $customer = (new Customer())
            ->setTitleId($title->getId())
            ->setFirstname('Jane')
            ->setLastname('Doe')
            ->setEmail(\sprintf('gift-card-release-%s@test.com', bin2hex(random_bytes(6))))
            ->setPassword('password');
        $customer->save();
        $this->customerIds[] = (int) $customer->getId();

        $cart = (new Cart())
            ->setCustomerId($customer->getId())
            ->setCurrencyId($currency->getId())
            ->setToken('gift-card-release-'.bin2hex(random_bytes(6)));
        $cart->save();
        $this->cartIds[] = (int) $cart->getId();

        $addressIds = [];

        foreach ([1, 2] as $ignored) {
            $address = (new OrderAddress())
                ->setCustomerTitleId($title->getId())
                ->setFirstname('Jane')
                ->setLastname('Doe')
                ->setAddress1('1 Main Street')
                ->setZipcode('75001')
                ->setCity('Paris')
                ->setCountryId($country->getId());
            $address->save();
            $addressIds[] = $this->orderAddressIds[] = (int) $address->getId();
        }

        $order = (new Order())
            ->setCustomerId($customer->getId())
            ->setInvoiceOrderAddressId($addressIds[0])
            ->setDeliveryOrderAddressId($addressIds[1])
            ->setCurrencyId($currency->getId())
            ->setCurrencyRate(1.0)
            ->setPaymentModuleId($payment->getId())
            ->setDeliveryModuleId($delivery->getId())
            ->setStatusId($status->getId())
            ->setLangId($lang->getId())
            ->setCartId($cart->getId())
            ->setPostage('0')
            ->setPostageTax('0');
        $order->save();
        $this->orderIds[] = (int) $order->getId();

        $giftCardIds = [];

        foreach ($spent as $amount => $initialSpend) {
            $giftCard = $this->giftCard(['amount' => '100', 'spendAmount' => $initialSpend]);
            $this->giftCardIds[] = $giftCardIds[] = (int) $giftCard->getId();
            (new GiftCardOrder())->setGiftCardId($giftCard->getId())->setOrderId($order->getId())->setSpendAmount((string) $amount)->setInitialPostage('0')->save();
        }

        foreach ($onTheCart as $amount => $initialSpend) {
            $giftCard = $this->giftCard(['amount' => '100', 'spendAmount' => $initialSpend]);
            $this->giftCardIds[] = $giftCardIds[] = (int) $giftCard->getId();
            (new GiftCardCart())->setCartId($cart->getId())->setGiftCardId($giftCard->getId())->setSpendAmount((string) $amount)->save();
        }

        return [OrderQuery::create()->findPk($order->getId()) ?? throw new \RuntimeException('The order went away.'), $giftCardIds];
    }

    private function storedSpentOn(int $giftCardId): string
    {
        $statement = $this->otherSession?->prepare('SELECT `spend_amount` FROM `gift_card` WHERE `id` = ?');
        self::assertNotNull($statement);
        $statement->execute([$giftCardId]);

        return (string) $statement->fetchColumn();
    }

    private function storedRowsOf(Order $order, int $status = GiftCardPaymentService::STATUS_SPENT): int
    {
        $statement = $this->otherSession?->prepare('SELECT COUNT(*) FROM `gift_card_order` WHERE `order_id` = ? AND `status` = ?');
        self::assertNotNull($statement);
        $statement->execute([(int) $order->getId(), $status]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return resource
     */
    private function releaseInAnotherProcess(Order $order, ?string $expectedAmount = null)
    {
        return $this->startProcess('release-in-another-process.php', (string) $order->getId(), ...(null === $expectedAmount ? [] : [$expectedAmount]));
    }

    /**
     * @return resource
     */
    private function spendInAnotherProcess(Order $order, string $amount)
    {
        return $this->startProcess('spend-in-another-process.php', (string) $order->getId(), $amount);
    }

    /**
     * @return resource
     */
    private function startProcess(string $script, string ...$arguments)
    {
        // The project of the monorepo has its own bootstrap (database and models of the TEST_TOKEN); a project
        // that runs the tests of the module with the bootstrap of the module has none.
        $bootstrap = is_file(getcwd().'/tests/bootstrap.php') ? getcwd().'/tests/bootstrap.php' : __DIR__.'/bootstrap.php';
        $process = proc_open(
            [\PHP_BINARY, __DIR__.'/'.$script, $bootstrap, ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            getcwd() ?: null,
        );
        self::assertIsResource($process);
        $this->pipes[(int) $process] = $pipes;

        return $process;
    }

    /**
     * Returns once the other process shows a locking read of the cards still running after it was seen
     * running: a read of a few rows by primary key only lasts that long when it waits on a lock. A session
     * sees the threads of its own account in PROCESSLIST.
     *
     * @param resource $process
     */
    private function waitUntilTheOtherProcessWaitsOnTheCards($process): void
    {
        $ownThreads = [
            (int) Propel::getWriteConnection(GiftCardTableMap::DATABASE_NAME)->query('SELECT CONNECTION_ID()')->fetchColumn(),
            (int) $this->otherSession?->query('SELECT CONNECTION_ID()')->fetchColumn(),
        ];
        $waiting = $this->otherSession?->prepare(
            "SELECT CONCAT(ID, ':', INFO) FROM information_schema.PROCESSLIST WHERE DB = DATABASE() AND COMMAND IN ('Query', 'Execute') AND INFO LIKE '%FROM `gift_card` WHERE%FOR UPDATE%' AND ID NOT IN (".implode(',', $ownThreads).')',
        );
        self::assertNotNull($waiting);
        $deadline = microtime(true) + self::PROCESS_TIMEOUT;
        $seen = [];

        do {
            if (!proc_get_status($process)['running']) {
                self::fail('The other process ended before waiting: '.stream_get_contents($this->pipes[(int) $process][2]));
            }

            $waiting->execute();
            $threads = $waiting->fetchAll(\PDO::FETCH_COLUMN);
            $waiting->closeCursor();

            if ([] !== array_intersect($threads, $seen)) {
                return;
            }

            $seen = $threads;
            usleep(200000);
        } while (microtime(true) < $deadline);

        self::fail('The other process never waited on the cards.');
    }

    /**
     * @param resource $process
     *
     * @return array{outcome: string, class?: string, message?: string}
     */
    private function waitFor($process): array
    {
        $stdout = $this->pipes[(int) $process][1];
        $stderr = $this->pipes[(int) $process][2];
        unset($this->pipes[(int) $process]);
        $output = stream_get_contents($stdout);
        $errors = stream_get_contents($stderr);
        fclose($stdout);
        fclose($stderr);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, 'The other process failed: '.$errors.$output);

        // The JSON is the last thing the process prints, after whatever booting it wrote.
        return json_decode(substr((string) $output, (int) strrpos((string) $output, '{"outcome"')), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function deleteFixtures(): void
    {
        $session = $this->otherSession;

        if (null === $session) {
            return;
        }

        foreach ($this->pipes as $pipes) {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
        }
        $this->pipes = [];

        foreach ($this->orderIds as $orderId) {
            $session->exec('DELETE FROM `gift_card_order` WHERE `order_id` = '.$orderId);
            $session->exec('DELETE FROM `order_version` WHERE `id` = '.$orderId);
            $session->exec('DELETE FROM `order` WHERE `id` = '.$orderId);
        }

        foreach ($this->orderAddressIds as $addressId) {
            $session->exec('DELETE FROM `order_address` WHERE `id` = '.$addressId);
        }

        foreach ($this->cartIds as $cartId) {
            $session->exec('DELETE FROM `gift_card_cart` WHERE `cart_id` = '.$cartId);
            $session->exec('DELETE FROM `cart` WHERE `id` = '.$cartId);
        }

        foreach ($this->giftCardIds as $giftCardId) {
            $session->exec('DELETE FROM `gift_card_order` WHERE `gift_card_id` = '.$giftCardId);
            $session->exec('DELETE FROM `gift_card_cart` WHERE `gift_card_id` = '.$giftCardId);
            $session->exec('DELETE FROM `gift_card` WHERE `id` = '.$giftCardId);
        }

        foreach ($this->customerIds as $customerId) {
            $session->exec('DELETE FROM `customer_version` WHERE `id` = '.$customerId);
            $session->exec('DELETE FROM `customer` WHERE `id` = '.$customerId);
        }

        $this->orderIds = $this->orderAddressIds = $this->cartIds = $this->customerIds = $this->giftCardIds = [];
    }

    /**
     * A second connection to the same database, opened from the configuration Propel holds: the one
     * Propel hands out is the connection under test.
     */
    private function openOtherSession(): ConnectionInterface
    {
        $serviceContainer = Propel::getServiceContainer();
        $connectionManager = $serviceContainer->getConnectionManager(GiftCardTableMap::DATABASE_NAME);

        if (!$connectionManager instanceof ConnectionManagerSingle) {
            self::markTestSkipped('The test database is not served by a single-connection manager.');
        }

        return ConnectionFactory::create(
            $connectionManager->getConfiguration(),
            $serviceContainer->getAdapter(GiftCardTableMap::DATABASE_NAME),
        );
    }
}
