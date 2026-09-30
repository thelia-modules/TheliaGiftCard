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
 * The core runs a module update inside a transaction, and MySQL commits it implicitly on any
 * ALTER TABLE: Propel would then never get back to nesting level 0, and everything the process
 * writes afterwards would run without a transaction. The update therefore works on a
 * connection of its own ({@see self::addOutsideTransaction()}).
 */
final readonly class GiftCardCodeUniqueIndex
{
    public const INDEX_NAME = 'gift_card_code_unique';

    /**
     * Opens a dedicated connection, so that the caller's transaction stays open and usable.
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

        return $this->add($connection);
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
