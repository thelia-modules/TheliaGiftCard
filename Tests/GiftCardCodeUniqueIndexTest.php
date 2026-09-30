<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Tests;

use Propel\Runtime\Exception\PropelException;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Service\GiftCardCodeUniqueIndex;

/**
 * Plays the 3.1.0 update on the gift_card table of the test database: DDL cannot be rolled back,
 * so the test runs outside a transaction and puts the index back whatever happens.
 *
 * `bin/test-prepare` replays every Config/update/*.sql after TheliaMain.sql, and 1.0.0.sql
 * recreates gift_card without the index: the test adds it the way an update does.
 */
final class GiftCardCodeUniqueIndexTest extends GiftCardTestCase
{
    protected bool $useTransaction = false;

    private const DUPLICATED_CODE = 'UPDATE31';

    protected function tearDown(): void
    {
        GiftCardQuery::create()->filterByCode(self::DUPLICATED_CODE)->delete();
        (new GiftCardCodeUniqueIndex())->add($this->getPropelConnection());

        parent::tearDown();
    }

    public function testTheUpdateAddsTheIndexOnceNoCodeIsDuplicated(): void
    {
        $connection = $this->getPropelConnection();
        $index = new GiftCardCodeUniqueIndex();
        $this->dropIndex();

        $this->giftCard(['code' => self::DUPLICATED_CODE]);
        $this->giftCard(['code' => self::DUPLICATED_CODE]);

        self::assertFalse($index->add($connection), 'Duplicated codes must not make the update fail.');
        self::assertFalse($index->exists($connection));
        self::assertSame(2, GiftCardQuery::create()->filterByCode(self::DUPLICATED_CODE)->count(), 'Both cards are kept.');

        GiftCardQuery::create()->filterByCode(self::DUPLICATED_CODE)->findOne()?->delete();

        self::assertTrue($index->add($connection));
        self::assertTrue($index->exists($connection));

        $this->expectException(PropelException::class);
        $this->giftCard(['code' => self::DUPLICATED_CODE]);
    }

    private function dropIndex(): void
    {
        $connection = $this->getPropelConnection();

        if ((new GiftCardCodeUniqueIndex())->exists($connection)) {
            $connection->exec(\sprintf('ALTER TABLE `gift_card` DROP INDEX `%s`', GiftCardCodeUniqueIndex::INDEX_NAME));
        }
    }
}
