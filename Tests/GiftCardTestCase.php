<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Tests;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Model\ModuleConfigQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderProductTax;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Test\IntegrationTestCase;
use TheliaGiftCard\Model\GiftCard;
use TheliaGiftCard\TheliaGiftCard;

/**
 * Runs on a disposable database whose name ends with `_test` (`php bin/test-prepare`), never on
 * the shop's: each test is rolled back, but a mistake on the target would still reach real cards.
 */
abstract class GiftCardTestCase extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $databaseName = $_SERVER['DATABASE_NAME'] ?? getenv('DATABASE_NAME');
        if (!\is_string($databaseName) || !str_ends_with($databaseName, '_test')) {
            self::fail(\sprintf('Refusing to run on the database "%s": use a *_test database.', (string) $databaseName));
        }

        parent::setUp();

        // The Propel configuration of the test environment is generated once for every project
        // test database: check the database the connection really reached.
        $statement = $this->getPropelConnection()->query('SELECT DATABASE()');
        $connectedTo = $statement->fetchColumn();
        $statement->close();
        if ($connectedTo !== $databaseName) {
            self::fail(\sprintf('Connected to "%s" instead of "%s": warm the test cache up with DATABASE_NAME=%s.', (string) $connectedTo, $databaseName, $databaseName));
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        ModuleConfigQuery::resetConfigCache();
    }

    /**
     * A product of the gift card category, which the module reads from its configuration.
     */
    protected function giftCardProduct(float $price): Product
    {
        $fixtures = $this->createFixtureFactory();
        $category = $fixtures->category();
        ConfigQuery::write(TheliaGiftCard::GIFT_CARD_CATEGORY_CONF_NAME, (string) $category->getId(), false, true);

        return $fixtures->product($category, $fixtures->taxRule(), $fixtures->currency(), ['basePrice' => $price]);
    }

    protected function orderLine(Order $order, Product $product, int $quantity, float $price, float $tax = 0.0): OrderProduct
    {
        $productSaleElements = ProductSaleElementsQuery::create()->findOneByProductId($product->getId());
        self::assertNotNull($productSaleElements);

        $orderProduct = new OrderProduct();
        $orderProduct
            ->setOrderId($order->getId())
            ->setProductRef((string) $product->getRef())
            ->setProductSaleElementsRef((string) $productSaleElements->getRef())
            ->setProductSaleElementsId($productSaleElements->getId())
            ->setQuantity((float) $quantity)
            ->setPrice((string) $price)
            ->setPromoPrice((string) $price)
            ->setWasNew(0)
            ->setWasInPromo(0)
            ->save();

        (new OrderProductTax())
            ->setOrderProductId($orderProduct->getId())
            ->setTitle('Tax')
            ->setAmount((string) $tax)
            ->setPromoAmount((string) $tax)
            ->save();

        return $orderProduct;
    }

    protected function giftCard(array $overrides = []): GiftCard
    {
        $giftCard = new GiftCard();
        $giftCard
            ->setCode($overrides['code'] ?? strtoupper(bin2hex(random_bytes(5))))
            ->setAmount((string) ($overrides['amount'] ?? '50'))
            ->setSpendAmount((string) ($overrides['spendAmount'] ?? '0'))
            ->setStatus($overrides['status'] ?? 1)
            ->setExpirationDate($overrides['expirationDate'] ?? new \DateTime('+6 months'))
            ->setOrderId($overrides['orderId'] ?? null)
            ->setBeneficiaryCustomerId($overrides['beneficiaryCustomerId'] ?? null)
            ->save();

        return $giftCard;
    }

    /**
     * IntegrationTestCase pushes a synthetic request: it is taken off the stack while the
     * kernel handles this one, so that this request is the main request the controller reads.
     */
    protected function handleAsMainRequest(Request $request): Response
    {
        $requestStack = $this->requestStack();
        $pushedRequests = [];
        while (null !== $pushedRequest = $requestStack->pop()) {
            $pushedRequests[] = $pushedRequest;
        }

        try {
            return self::$kernel->handle($request);
        } finally {
            while (null !== $requestStack->pop()) {
            }
            foreach (array_reverse($pushedRequests) as $pushedRequest) {
                $requestStack->push($pushedRequest);
            }
        }
    }

    protected function requestStack(): RequestStack
    {
        $requestStack = static::getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $requestStack);

        return $requestStack;
    }

    protected function csrfToken(Request $request, string $tokenId): string
    {
        $requestStack = $this->requestStack();
        $tokenManager = static::getContainer()->get('security.csrf.token_manager');
        self::assertInstanceOf(CsrfTokenManagerInterface::class, $tokenManager);

        $requestStack->push($request);
        try {
            return $tokenManager->getToken($tokenId)->getValue();
        } finally {
            $requestStack->pop();
        }
    }

    protected function lang(string $locale): Lang
    {
        $lang = LangQuery::create()->findOneByLocale($locale);
        self::assertInstanceOf(Lang::class, $lang, \sprintf('The test database has no %s language.', $locale));

        return $lang;
    }
}
