# Changelog

## 3.3.0 (unreleased)

Requires Thelia 3.3.0 (`module.xml`, and `conflict` on `thelia/core` below 3.3.0 in `composer.json`).

### Added

- The gift cards are an instant payment means of the core (`InstantPaymentModuleInterface`): the cards put on the cart become one part of the order, settled by the core when the order is paid and released when it is cancelled.
- Front entry on the payment step of the Flexy theme, following the payment parts contract: a live component in the `checkout.payment.parts` theme hook to tick a card, type its code or take the cards off.
- The paid / left-to-pay lines of the checkout summary (3.2.8) stay, and agree with the part the core composes for the order. The `POST /gift-card/spend` form of the summary stays on the steps before the payment.
- `GiftCardOrderList` loop: `status` argument, default 0 (rows spent). Pass 1 for the rows given back.

### Changed

- The checkout summary no longer draws the gift-card form on the payment step (nor on a one-page checkout): the `TheliaGiftCard:PaymentPart` component is the one entry there. The lines stay.
- A row of `gift_card_order` is never deleted: giving it back sets its `status` to 1.
- Giving back is refused (`GiftCardReleaseRefusedException`) when the rows read under the locks do not add up to the part.
- `TheliaGiftCard::pay()` no longer sets the order status: the core turns an order paid in full by cards paid.

### Breaking

- `OrderAfterPayListener::__construct(RequestStack)` is now `__construct(RequestStack, GiftCardPaymentService)`.
- `OrderPayListener::__construct(GiftCardService, GiftCardGenerateService, EventDispatcherInterface, GiftCardPaymentService)` is now `__construct(GiftCardService, GiftCardGenerateService, EventDispatcherInterface)`. `onOrderPayGiftCard(OrderEvent)` and its `ORDER_BEFORE_PAYMENT` subscription are removed: the cards are no longer debited before the payment.
- `PaymentListener::handleGiftCard(OrderPayTotalEvent)` is now `deductWhatWasDebitedBeforePaymentParts(OrderPayTotalEvent)`, on `ORDER_PAY_GET_TOTAL` at priority 60 (`PaymentListener::TOTAL_PRIORITY`). It deducts only for an order with no payment part that has rows in `gift_card_order`, and no longer reads the cards of the cart. Transition, to remove in a later version.
- `GiftCardPaymentService::debitCart(Order $order, int $cartId): void` is replaced by `spend(Order $order, string $amount): void` and `release(int $orderId, ?string $expectedAmount = null, ?ConnectionInterface $connection = null): void`.
