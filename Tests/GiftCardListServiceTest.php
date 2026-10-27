<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Tests;

use TheliaGiftCard\Model\GiftCardQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use TheliaGiftCard\Dto\GiftCardListCriteria;
use TheliaGiftCard\Dto\GiftCardListPage;
use TheliaGiftCard\Model\GiftCard;
use TheliaGiftCard\Service\GiftCardListService;

/**
 * The cards of the back-office list: what the type, the status and the search keep, how the sort and
 * the pages cut them. Every card of a test carries a code starting with the marker of the test, so the
 * cards already in the database never count.
 */
final class GiftCardListServiceTest extends GiftCardTestCase
{
    private const MARKER = 'LST';

    public function testTheTypeKeepsTheCardsBoughtInTheShopOrTheOnesOfAnAdministrator(): void
    {
        $order = $this->createFixtureFactory()->order();
        $bought = $this->listedCard(['code' => 'LSTBOUGHT', 'orderId' => $order->getId()]);
        $generated = $this->listedCard(['code' => 'LSTGENERATED']);

        self::assertSame([$bought->getCode(), $generated->getCode()], $this->codes($this->find(type: GiftCardListCriteria::TYPE_ALL), sorted: true));
        self::assertSame(['LSTBOUGHT'], $this->codes($this->find(type: GiftCardListCriteria::TYPE_PURCHASED)));
        self::assertSame(['LSTGENERATED'], $this->codes($this->find(type: GiftCardListCriteria::TYPE_GENERATED)));
    }

    public function testTheStatusKeepsTheActiveCardsOrTheInactiveOnes(): void
    {
        $this->listedCard(['code' => 'LSTON', 'status' => 1]);
        $this->listedCard(['code' => 'LSTOFF', 'status' => 0]);

        self::assertSame(['LSTON'], $this->codes($this->find(status: GiftCardListCriteria::STATUS_ACTIVE)));
        self::assertSame(['LSTOFF'], $this->codes($this->find(status: GiftCardListCriteria::STATUS_INACTIVE)));
        self::assertSame(['LSTOFF', 'LSTON'], $this->codes($this->find(), sorted: true));
    }

    public function testTheSearchReadsTheCodeTheOrderReferenceAndTheNamesOfTheBuyerAndTheBeneficiary(): void
    {
        $fixtures = $this->createFixtureFactory();
        $buyer = $fixtures->customer($fixtures->customerTitle(), ['firstname' => 'Zelda', 'lastname' => 'Quillfeather']);
        $beneficiary = $fixtures->customer($fixtures->customerTitle(), ['firstname' => 'Ulric', 'lastname' => 'Mapleshade']);
        $order = $fixtures->order($buyer);
        $order->setRef('ORD-SEARCHABLE-1')->save();

        $this->listedCard(['code' => 'LSTALPHA'])->setSponsorCustomerId($buyer->getId())->setBeneficiaryCustomerId($beneficiary->getId())->setOrderId($order->getId())->save();
        $this->listedCard(['code' => 'LSTOMEGA']);

        self::assertSame(['LSTALPHA'], $this->codes($this->find(search: 'ALPH')), 'part of the code');
        self::assertSame(['LSTALPHA'], $this->codes($this->find(search: 'SEARCHABLE-1')), 'part of the order reference');
        self::assertSame(['LSTALPHA'], $this->codes($this->find(search: 'Quillfeather')), 'last name of the buyer');
        self::assertSame(['LSTALPHA'], $this->codes($this->find(search: 'Zelda Quill')), 'first and last name of the buyer');
        self::assertSame(['LSTALPHA'], $this->codes($this->find(search: 'Quillfeather Zelda')), 'last and first name of the buyer');
        self::assertSame(['LSTALPHA'], $this->codes($this->find(search: 'Mapleshade')), 'last name of the beneficiary');
        self::assertSame(['LSTALPHA'], $this->codes($this->find(search: 'Ulric Maple')), 'first and last name of the beneficiary');
        self::assertSame([], $this->codes($this->find(search: 'Nobody Atall')));
    }

    public function testTheSearchIsNotAPatternAndKeepsTheFiltersAlongsideIt(): void
    {
        $this->listedCard(['code' => 'LSTONE', 'status' => 1]);
        $this->listedCard(['code' => 'LSTTWO', 'status' => 0]);

        // `%` and `_` are characters of the search, not wildcards.
        self::assertSame([], $this->codes($this->find(search: 'LST%')));
        self::assertSame([], $this->codes($this->find(search: 'LSTO_E')));
        self::assertSame(['LSTTWO'], $this->codes($this->find(search: 'LST', status: GiftCardListCriteria::STATUS_INACTIVE)));
    }

    public function testTheSortOrdersTheCardsByTheColumnAndTheDirection(): void
    {
        $this->listedCard(['code' => 'LSTB', 'amount' => '30', 'expirationDate' => new \DateTime('+2 months')]);
        $this->listedCard(['code' => 'LSTC', 'amount' => '10', 'expirationDate' => new \DateTime('+3 months')]);
        $this->listedCard(['code' => 'LSTA', 'amount' => '20', 'expirationDate' => new \DateTime('+1 month')]);

        self::assertSame(['LSTC', 'LSTA', 'LSTB'], $this->codes($this->find(sort: 'amount', direction: 'asc')));
        self::assertSame(['LSTB', 'LSTA', 'LSTC'], $this->codes($this->find(sort: 'amount', direction: 'desc')));
        self::assertSame(['LSTA', 'LSTB', 'LSTC'], $this->codes($this->find(sort: 'code', direction: 'asc')));
        self::assertSame(['LSTA', 'LSTB', 'LSTC'], $this->codes($this->find(sort: 'expiration_date', direction: 'asc')));
        self::assertSame(['LSTA', 'LSTC', 'LSTB'], $this->codes($this->find(sort: 'id', direction: 'desc')), 'the most recent card first');
    }

    public function testTheTypeAndTheStatusApplyWithoutASearch(): void
    {
        $order = $this->createFixtureFactory()->order();
        $this->listedCard(['code' => 'LSTNOSEARCHB', 'orderId' => $order->getId(), 'status' => 0]);
        $this->listedCard(['code' => 'LSTNOSEARCHG', 'status' => 1]);

        foreach ([
            [GiftCardListCriteria::TYPE_PURCHASED, GiftCardListCriteria::STATUS_ALL, GiftCardQuery::create()->filterByOrderId(null, Criteria::ISNOTNULL)],
            [GiftCardListCriteria::TYPE_GENERATED, GiftCardListCriteria::STATUS_ALL, GiftCardQuery::create()->filterByOrderId(null, Criteria::ISNULL)],
            [GiftCardListCriteria::TYPE_ALL, GiftCardListCriteria::STATUS_ACTIVE, GiftCardQuery::create()->filterByStatus(1)],
            [GiftCardListCriteria::TYPE_PURCHASED, GiftCardListCriteria::STATUS_INACTIVE, GiftCardQuery::create()->filterByOrderId(null, Criteria::ISNOTNULL)->filterByStatus(0)],
        ] as [$type, $status, $expected]) {
            // No search at all: the joins of the search are not there to hide a filter that does not apply.
            $page = $this->service()->find(new GiftCardListCriteria('', $type, $status));

            self::assertSame($expected->count(), $page->total, $type.'/'.$status);
            foreach ($page->cards as $card) {
                if (GiftCardListCriteria::TYPE_PURCHASED === $type) {
                    self::assertNotNull($card->getOrderId());
                }
                if (GiftCardListCriteria::TYPE_GENERATED === $type) {
                    self::assertNull($card->getOrderId());
                }
            }
        }
    }

    public function testTheSortAlsoOrdersByWhatWasSpentTheStatusAndTheCreationDate(): void
    {
        $this->listedCard(['code' => 'LSTX', 'spendAmount' => '5', 'status' => 1])->setCreatedAt(new \DateTime('-3 days'))->save();
        $this->listedCard(['code' => 'LSTY', 'spendAmount' => '1', 'status' => 0])->setCreatedAt(new \DateTime('-1 day'))->save();
        $this->listedCard(['code' => 'LSTZ', 'spendAmount' => '9', 'status' => 1])->setCreatedAt(new \DateTime('-2 days'))->save();

        self::assertSame(['LSTY', 'LSTX', 'LSTZ'], $this->codes($this->find(sort: 'spend_amount', direction: 'asc')));
        self::assertSame('LSTY', $this->codes($this->find(sort: 'status', direction: 'asc'))[0]);
        self::assertSame(['LSTY', 'LSTZ', 'LSTX'], $this->codes($this->find(sort: 'created_at', direction: 'desc')));
    }

    public function testTheSearchReadsTheEmailsTheRowsShow(): void
    {
        $fixtures = $this->createFixtureFactory();
        $buyer = $fixtures->customer($fixtures->customerTitle(), ['email' => 'lst-buyer-mail@example.com']);
        $beneficiary = $fixtures->customer($fixtures->customerTitle(), ['email' => 'lst-recipient-mail@example.com']);
        $this->listedCard(['code' => 'LSTMAIL'])->setSponsorCustomerId($buyer->getId())->setBeneficiaryCustomerId($beneficiary->getId())->save();
        $this->listedCard(['code' => 'LSTNOMAIL']);

        self::assertSame(['LSTMAIL'], $this->codes($this->service()->find(new GiftCardListCriteria('lst-buyer-mail@'))), 'e-mail of the buyer');
        self::assertSame(['LSTMAIL'], $this->codes($this->service()->find(new GiftCardListCriteria('lst-recipient-mail'))), 'e-mail of the beneficiary');
    }

    public function testTheListWithoutASearchReadsEveryCard(): void
    {
        $before = $this->service()->find(new GiftCardListCriteria())->total;
        $this->listedCard(['code' => 'LSTFIRST']);
        $this->listedCard(['code' => 'LSTSECOND', 'status' => 0]);

        $page = $this->service()->find(new GiftCardListCriteria());

        self::assertSame($before + 2, $page->total);
        self::assertContains('LSTSECOND', array_map(static fn (GiftCard $giftCard): ?string => $giftCard->getCode(), $page->cards));
    }

    public function testASortTheListDoesNotKnowFallsBackToTheCreationDate(): void
    {
        $criteria = GiftCardListCriteria::fromQuery(['order' => 'code; DROP TABLE gift_card', 'direction' => 'sideways', 'type' => 'nonsense', 'status' => ['x'], 'page' => '-4', 'q' => ['array']]);

        self::assertSame(GiftCardListCriteria::DEFAULT_SORT, $criteria->sort);
        self::assertSame('desc', $criteria->direction);
        self::assertSame(GiftCardListCriteria::TYPE_ALL, $criteria->type);
        self::assertSame(GiftCardListCriteria::STATUS_ALL, $criteria->status);
        self::assertSame(1, $criteria->page);
        self::assertSame('', $criteria->search);
        self::assertSame([], $criteria->toQuery());
    }

    public function testTheFirstPageIsBoundedAndTheNextPagesFollow(): void
    {
        $size = GiftCardListService::PAGE_SIZE;
        for ($i = 1; $i <= $size + 5; ++$i) {
            $this->listedCard(['code' => \sprintf('LSTPAGE%03d', $i)]);
        }

        $first = $this->find(sort: 'code', direction: 'asc');
        $second = $this->find(sort: 'code', direction: 'asc', page: 2);

        self::assertCount($size, $first->cards, 'the first page is cut at the page size');
        self::assertSame($size + 5, $first->total);
        self::assertSame(2, $first->lastPage);
        self::assertSame(1, $first->page);
        self::assertSame('LSTPAGE001', $first->cards[0]->getCode());
        self::assertCount(5, $second->cards);
        self::assertSame(\sprintf('LSTPAGE%03d', $size + 1), $second->cards[0]->getCode(), 'the second page starts where the first one stopped');
        self::assertSame(2, $second->page);
    }

    public function testAPageBeyondTheLastOneShowsTheLastPage(): void
    {
        $this->listedCard(['code' => 'LSTONLY']);

        $page = $this->find(page: 99);

        self::assertSame(1, $page->page);
        self::assertSame(['LSTONLY'], $this->codes($page));
    }

    public function testNoCardGivesAnEmptyPageAndNotAnError(): void
    {
        $page = $this->find(search: 'LST-NOTHING-HERE');

        self::assertSame([], $page->cards);
        self::assertSame(0, $page->total);
        self::assertSame(1, $page->lastPage);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function listedCard(array $overrides = []): GiftCard
    {
        return $this->giftCard($overrides);
    }

    private function find(string $type = GiftCardListCriteria::TYPE_ALL, string $status = GiftCardListCriteria::STATUS_ALL, string $search = '', string $sort = GiftCardListCriteria::DEFAULT_SORT, string $direction = 'desc', int $page = 1): GiftCardListPage
    {
        // Without a search of its own, the list is narrowed to the cards of the test.
        $criteria = new GiftCardListCriteria('' === $search ? self::MARKER : $search, $type, $status, $sort, $direction, $page);

        return $this->service()->find($criteria);
    }

    private function service(): GiftCardListService
    {
        $service = static::getContainer()->get(GiftCardListService::class);
        self::assertInstanceOf(GiftCardListService::class, $service);

        return $service;
    }

    /**
     * @return list<string>
     */
    private function codes(GiftCardListPage $page, bool $sorted = false): array
    {
        $codes = array_map(static fn (GiftCard $giftCard): string => (string) $giftCard->getCode(), $page->cards);
        if ($sorted) {
            sort($codes);
        }

        return $codes;
    }
}
