<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Tests;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Log\Destination\TlogDestinationNull;
use Thelia\Log\Tlog;
use Thelia\Model\CartItemQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductPrice;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;
use TheliaGiftCard\Model\GiftCardInfoCartQuery;

/**
 * The "buy a gift card" form of the product page, through the kernel.
 */
final class GiftCardCartControllerTest extends GiftCardTestCase
{
    private const NOT_A_GIFT_CARD = 'This product is not a gift card.';

    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();

        RecordingTlogDestination::$lines = [];
        Tlog::getInstance()->setLevel(Tlog::DEBUG)->setDestinations(RecordingTlogDestination::class);
    }

    protected function tearDown(): void
    {
        Tlog::getInstance()->setDestinations(TlogDestinationNull::class);

        parent::tearDown();
    }

    public function testAGiftCardProductIsPutOnTheCartWithItsInformation(): void
    {
        $giftCardProduct = $this->giftCardProduct(50.0);

        $response = $this->postProduct((string) $giftCardProduct->getId());

        self::assertSame(302, $response->getStatusCode());
        self::assertStringEndsWith('/checkout/cart', (string) $response->headers->get('Location'));
        self::assertSame(1, CartItemQuery::create()->filterByProductId($giftCardProduct->getId())->count());
        self::assertSame(1, GiftCardInfoCartQuery::create()->filterBySponsorName('Alice')->count());
    }

    public function testTheDefaultDeclinationOfTheGiftCardIsPutOnTheCart(): void
    {
        $giftCardProduct = $this->giftCardProduct(50.0);
        $first = ProductSaleElementsQuery::create()->findOneByProductId($giftCardProduct->getId());
        self::assertNotNull($first);
        $first->setIsDefault(false)->save();
        $default = $this->declination($giftCardProduct, $first, true);

        $this->postProduct((string) $giftCardProduct->getId());

        $cartItem = CartItemQuery::create()->filterByProductId($giftCardProduct->getId())->findOne();
        self::assertNotNull($cartItem);
        self::assertSame($default->getId(), $cartItem->getProductSaleElementsId());
    }

    public function testAProductOutsideTheGiftCardCategoryIsRefusedBackToTheForm(): void
    {
        $this->giftCardProduct(50.0);
        $otherProduct = $this->productOutsideTheGiftCardCategory();

        $response = $this->postProduct((string) $otherProduct->getId(), errorUrl: '/the-product-page');

        $this->assertRefusedBackTo($response, '/the-product-page', self::NOT_A_GIFT_CARD);
        self::assertSame(0, CartItemQuery::create()->filterByProductId($otherProduct->getId())->count());
        self::assertSame(0, GiftCardInfoCartQuery::create()->filterBySponsorName('Alice')->count());
    }

    public function testAProductOfTheCategoryWithoutDeclinationIsRefused(): void
    {
        $giftCardProduct = $this->giftCardProduct(50.0);
        ProductSaleElementsQuery::create()->filterByProductId($giftCardProduct->getId())->delete();

        $response = $this->postProduct((string) $giftCardProduct->getId(), errorUrl: '/the-product-page');

        $this->assertRefusedBackTo($response, '/the-product-page', self::NOT_A_GIFT_CARD);
        self::assertSame(0, GiftCardInfoCartQuery::create()->filterBySponsorName('Alice')->count());
    }

    public function testTheRefusalGoesToTheCartWhenTheFormGivesNoErrorUrl(): void
    {
        $this->giftCardProduct(50.0);
        $otherProduct = $this->productOutsideTheGiftCardCategory();

        $response = $this->postProduct((string) $otherProduct->getId());

        $this->assertRefusedBackTo($response, '/checkout/cart', self::NOT_A_GIFT_CARD);
    }

    /**
     * @dataProvider unknownProducts
     */
    public function testAnUnknownProductIsRefusedWithoutAnError(string $postedProductId): void
    {
        $this->giftCardProduct(50.0);

        $response = $this->postProduct($postedProductId, errorUrl: '/the-product-page');

        $this->assertRefusedBackTo($response, '/the-product-page', self::NOT_A_GIFT_CARD);
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

    public function testAnInvalidFormIsLoggedAndSentBackWithoutPuttingAnythingOnTheCart(): void
    {
        $giftCardProduct = $this->giftCardProduct(50.0);

        $response = $this->postProduct((string) $giftCardProduct->getId(), sponsorName: '', errorUrl: '/the-product-page');

        self::assertSame(302, $response->getStatusCode());
        self::assertStringEndsWith('/the-product-page', (string) $response->headers->get('Location'));
        self::assertSame(0, CartItemQuery::create()->filterByProductId($giftCardProduct->getId())->count());
        self::assertNotSame([], RecordingTlogDestination::$lines, 'The refusal is logged.');
    }

    private function assertRefusedBackTo(Response $response, string $location, string $message): void
    {
        self::assertSame(302, $response->getStatusCode());
        self::assertStringEndsWith($location, (string) $response->headers->get('Location'));

        $informations = array_values($this->session->getFormErrorInformation());
        self::assertCount(1, $informations);
        self::assertTrue($informations[0]['hasError']);
        self::assertSame($message, $informations[0]['errorMessage']);
        self::assertStringContainsString($message, implode("\n", RecordingTlogDestination::$lines));
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

    private function declination(Product $product, ProductSaleElements $like, bool $isDefault): ProductSaleElements
    {
        $declination = (new ProductSaleElements())
            ->setProductId($product->getId())
            ->setRef(strtoupper(bin2hex(random_bytes(4))))
            ->setQuantity(10)
            ->setIsDefault($isDefault);
        $declination->save();

        foreach ($like->getProductPrices() as $price) {
            (new ProductPrice())
                ->setProductSaleElementsId($declination->getId())
                ->setCurrencyId($price->getCurrencyId())
                ->setPrice($price->getPrice())
                ->setPromoPrice($price->getPromoPrice())
                ->save();
        }

        return $declination;
    }

    private function postProduct(string $productId, string $sponsorName = 'Alice', ?string $errorUrl = null): Response
    {
        $this->session = new Session(new MockArraySessionStorage());

        $request = Request::create('/gift-card/info/save', 'POST');
        $request->setSession($this->session);
        $request->request->set('save_gift_card_info', [
            'product_id' => $productId,
            'sponsor_name' => $sponsorName,
            'beneficiary_name' => 'Bob',
            'error_url' => $errorUrl ?? '',
            '_token' => $this->csrfToken($request, 'save_gift_card_info'),
        ]);

        return $this->handleAsMainRequest($request);
    }
}
