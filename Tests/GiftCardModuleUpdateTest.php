<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Propel\Runtime\Connection\ConnectionWrapper;
use Thelia\Model\ModuleQuery;
use Thelia\Module\ModuleManagement;
use TheliaGiftCard\Service\GiftCardCodeUniqueIndex;
use TheliaGiftCard\TheliaGiftCard;

/**
 * The update from 3.0.0, the way the core plays it: inside a transaction of its own.
 *
 * In a process of its own: should the update commit that transaction (an ALTER TABLE does on
 * MySQL), Propel would stay above nesting level 0 and the next tests would write outside any
 * transaction.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GiftCardModuleUpdateTest extends GiftCardTestCase
{
    protected bool $useTransaction = false;

    protected function tearDown(): void
    {
        (new GiftCardCodeUniqueIndex())->add($this->getPropelConnection());

        parent::tearDown();
    }

    public function testTheUpdateAddsTheIndexAndLeavesTheCallerTransactionUsable(): void
    {
        $this->dropIndex();
        $connection = $this->getPropelConnection();
        self::assertInstanceOf(ConnectionWrapper::class, $connection);
        $module = new TheliaGiftCard();
        $module->setContainer(static::getContainer());

        $connection->beginTransaction();
        try {
            $module->update('3.0.0', '3.1.0', $connection);

            self::assertTrue($connection->inTransaction(), 'The transaction of the core is still open.');
            self::assertSame(1, $connection->getNestedTransactionCount());
            $statement = $connection->query('SELECT 1');
            self::assertSame(1, (int) $statement->fetchColumn());
            $statement->close();
        } finally {
            if ($connection->inTransaction()) {
                $connection->forceRollBack();
            }
        }

        self::assertTrue((new GiftCardCodeUniqueIndex())->exists($connection));
    }

    public function testUpdatingTheModuleThroughTheCoreAddsTheIndex(): void
    {
        $this->dropIndex();
        $module = ModuleQuery::create()->findOneByCode(TheliaGiftCard::MODULE_CODE);
        self::assertNotNull($module);
        $installedVersion = (string) $module->getVersion();
        $module->setVersion('3.0.0')->save();

        try {
            (new ModuleManagement(static::getContainer(), static::getContainer()->get('event_dispatcher')))->updateModule(
                new \SplFileInfo(\dirname(__DIR__).'/Config/module.xml'),
                static::getContainer(),
            );
        } finally {
            ModuleQuery::create()->findOneByCode(TheliaGiftCard::MODULE_CODE)?->setVersion($installedVersion)->save();
        }

        $connection = $this->getPropelConnection();
        self::assertInstanceOf(ConnectionWrapper::class, $connection);
        self::assertSame(0, $connection->getNestedTransactionCount(), 'The core transaction was committed normally.');
        self::assertTrue((new GiftCardCodeUniqueIndex())->exists($connection));
    }

    private function dropIndex(): void
    {
        $connection = $this->getPropelConnection();

        if ((new GiftCardCodeUniqueIndex())->exists($connection)) {
            $connection->exec(\sprintf('ALTER TABLE `gift_card` DROP INDEX `%s`', GiftCardCodeUniqueIndex::INDEX_NAME));
        }
    }
}
