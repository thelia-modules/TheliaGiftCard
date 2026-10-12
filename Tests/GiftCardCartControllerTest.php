<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Tests;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\CartItemQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElementsQuery;
use TheliaGiftCard\Model\GiftCardInfoCartQuery;

/**
 * The "buy a gift card" form of the product page, through the kernel.
 */
final class GiftCardCartControllerTest extends GiftCardTestCase
{
    public function testAGiftCardProductIsPutOnTheCartWithItsInformation(): void
    {
        $giftCardProduct = $this->giftCardProduct(50.0);

        $response = $this->postProduct((string) $giftCardProduct->getId());

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(1, CartItemQuery::create()->filterByProductId($giftCardProduct->getId())->count());
        self::assertSame(1, GiftCardInfoCartQuery::create()->filterBySponsorName('Alice')->count());
    }

    public function testAProductOutsideTheGiftCardCategoryIsNotPutOnTheCart(): void
    {
        $this->giftCardProduct(50.0);
        $otherProduct = $this->productOutsideTheGiftCardCategory();

        $response = $this->postProduct((string) $otherProduct->getId());

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(0, CartItemQuery::create()->filterByProductId($otherProduct->getId())->count());
        self::assertSame(0, GiftCardInfoCartQuery::create()->filterBySponsorName('Alice')->count());
    }

    /**
     * @dataProvider unknownProducts
     */
    public function testAnUnknownProductIsRefusedWithoutAnError(string $postedProductId): void
    {
        $this->giftCardProduct(50.0);

        $response = $this->postProduct($postedProductId);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(0, GiftCardInfoCartQuery::create()->filterBySponsorName('Alice')->count());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownProducts(): iterable
    {
        yield 'an id no product has' => ['2147483647'];
        yield 'text' => ['abc'];
        yield 'a number followed by text' => ['1abc'];
    }

    private function productOutsideTheGiftCardCategory(): Product
    {
        $fixtures = $this->createFixtureFactory();

        $product = $fixtures->product($fixtures->category(), $fixtures->taxRule(), $fixtures->currency(), ['basePrice' => 20.0]);

        // In stock, so that nothing but the posted id decides whether the product can be added.
        $productSaleElements = ProductSaleElementsQuery::create()->findOneByProductId($product->getId());
        self::assertNotNull($productSaleElements);
        $productSaleElements->setQuantity(10)->save();

        return $product;
    }

    private function postProduct(string $productId): Response
    {
        $session = new Session(new MockArraySessionStorage());

        $request = Request::create('/gift-card/info/save', 'POST');
        $request->setSession($session);
        $request->request->set('save_gift_card_info', [
            'product_id' => $productId,
            'sponsor_name' => 'Alice',
            'beneficiary_name' => 'Bob',
            '_token' => $this->csrfToken($request, 'save_gift_card_info'),
        ]);

        return $this->handleAsMainRequest($request);
    }
}
