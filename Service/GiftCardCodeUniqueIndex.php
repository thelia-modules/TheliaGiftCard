<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Service;

use Propel\Runtime\Connection\ConnectionFactory;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Connection\ConnectionManagerSingle;
use Propel\Runtime\Propel;
use Thelia\Log\Tlog;
use TheliaGiftCard\Model\Map\GiftCardTableMap;

/**
 * Adds the unique index on gift_card.code to a shop installed before 3.1.0.
 *
 * The draw of the previous releases could hand out a code already taken, so a shop may hold
 * duplicates: the index cannot be created on them and the update must not fail on it. They
 * are reported and left untouched, both cards stay usable, and the index is added by the next
 * update once they are dealt with.
 *
 * MySQL commits the current transaction implicitly on any ALTER TABLE: run on the connection of
 * a caller in a transaction, Propel would never get back to nesting level 0, and everything the
 * process writes afterwards would run without a transaction. The index is therefore added on a
 * connection of its own ({@see self::addOutsideTransaction()}), which waits for a table lock
 * $lockWaitSeconds at most instead of the default year of MySQL.
 */
final readonly class GiftCardCodeUniqueIndex
{
    public const INDEX_NAME = 'gift_card_code_unique';

    public function __construct(private int $lockWaitSeconds = 10)
    {
    }

    /**
     * Opens a dedicated connection, so that the caller's transaction stays open and usable.
     *
     * Never called while the caller's transaction holds a lock on gift_card: on MySQL 8, a
     * transaction that wrote to `module` holds one through the foreign keys, and the ALTER TABLE
     * would wait for it from the same process.
     */
    public function addOutsideTransaction(): bool
    {
        $manager = Propel::getServiceContainer()->getConnectionManager(GiftCardTableMap::DATABASE_NAME);

        if (!$manager instanceof ConnectionManagerSingle) {
            Tlog::getInstance()->warning('TheliaGiftCard: unique index on gift_card.code not created, the database connection is not a single one.');

            return false;
        }

        $connection = ConnectionFactory::create(
            $manager->getConfiguration(),
            Propel::getServiceContainer()->getAdapter(GiftCardTableMap::DATABASE_NAME),
        );

        $connection->exec(\sprintf('SET SESSION lock_wait_timeout = %d', $this->lockWaitSeconds));

        try {
            return $this->add($connection);
        } catch (\PDOException $exception) {
            Tlog::getInstance()->warning(\sprintf(
                'TheliaGiftCard: unique index on gift_card.code not created, the table stayed locked: %s',
                $exception->getMessage(),
            ));

            return false;
        }
    }

    public function add(ConnectionInterface $connection): bool
    {
        if ($this->exists($connection)) {
            return true;
        }

        $statement = $connection->query('SELECT COUNT(*) FROM (SELECT `code` FROM `gift_card` GROUP BY `code` HAVING COUNT(*) > 1) AS `duplicate`');
        $duplicates = (int) $statement->fetchColumn();
        $statement->close();

        if (0 < $duplicates) {
            // The codes themselves are not logged: a code is enough to spend a card.
            Tlog::getInstance()->warning(\sprintf(
                'TheliaGiftCard: unique index on gift_card.code not created, %d code(s) are used by several cards.',
                $duplicates,
            ));

            return false;
        }

        $connection->exec(\sprintf('ALTER TABLE `gift_card` ADD UNIQUE INDEX `%s` (`code`)', self::INDEX_NAME));

        return true;
    }

    public function exists(ConnectionInterface $connection): bool
    {
        $statement = $connection->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = \'gift_card\' AND index_name = :index'
        );
        $statement->execute(['index' => self::INDEX_NAME]);
        $count = (int) $statement->fetchColumn();
        $statement->closeCursor();

        return 0 < $count;
    }
}
