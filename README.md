# Thelia Gift Card

Sell, send and redeem gift cards on a Thelia 3 shop.

- A product of the configured gift card category is a gift card. When an order reaches the configured status, one
  card is created per unit bought: an eight-character code, the price with taxes as amount, valid for one year,
  disabled until the shop enables it (or enabled at once in the "automatic" mode, which sends nothing).
- The back-office lists the cards, creates, edits, enables and disables them, downloads a card as a PDF and sends it
  by email to an address the administrator types.
- The customer attaches a card to their account with its code, then spends it when paying.

## Configuration

In the module configuration: the gift card category, the order status that creates the cards, the automatic mode.

## Templates

| Output | Template | Theme |
|---|---|---|
| PDF of a card | `giftCard.html.twig` | PDF (`templates/pdf/default/`) |
| Email sent from the back-office | `gift_card_customer_notification.html.twig` | email (`templates/email/default/`) |
| Default body of that email, proposed in the send window | `gift_card_email_text.html.twig` | email |
| Product form, customer area block | `TheliaGiftCard/*.html.twig` | front-office (`templates/frontOffice/flexy/`) |

The module ships generic templates for the `default` themes. A shop overrides one with a file of the same name in its
own PDF or email theme. PDF and email are rendered in the back-office language of the administrator (or the language
picked in the card list for a download); the templates receive it as `gift_card_locale` and translate with
`|theliagiftcard_trans({}, domain, gift_card_locale)`. The names and message typed by the buyer are escaped.

## Changes

### 3.2.1

- `GiftCardPaymentRefusedException` is a checkout payment refusal (`InvalidPaymentException`) with a sentence written for the buyer, so a card that can no longer pay its share sends the buyer back to the payment step instead of failing with a 500.

### 3.2.0

- Spending cards on the cart: `GiftCardCartSpending` lists the cards the customer can spend (`spendableCards()`:
  beneficiary, enabled, unexpired, credit left, none when the cart holds a gift card), puts the selected ones on the
  cart (`spend()`), takes them off (`reset()`), and tells whether they pay for all of it (`coversTheCart()`). The
  cards together never go beyond the amount asked for nor beyond the cart total with its postage; spending them takes
  the coupons off, as before. A theme calls it from its own component: the module still renders no block for it.
- Everything is read from the cart, never from the order kept in session: the Thelia 3 checkout keeps the delivery
  address, the carrier and the postage on the cart. Putting a card on the cart no longer fails on "Delivery address
  not set yet", and a cart fully paid with cards is recognised.
- Amounts compared in cents: cards covering a total to the cent pay for all of it (the previous comparison was made
  on the string forms of two floats).
- `ORDER_PAY_GET_TOTAL` deducts what was debited for the order (or, before the debit, what was put on the order's
  cart), capped at the total: cards worth more than the total now bring it to zero instead of deducting nothing.
- The cards are debited on `ORDER_BEFORE_PAYMENT`, before the payment module is called and before the confirmation
  e-mails, for the order total at most: a card is never debited of more than the payment deducts, and a card refused
  leaves an unpaid order instead of an order already marked paid.
- The amounts put on a cart are also taken off when the quantity of a line changes (`CART_UPDATEITEM`), on the cart of
  the event.
- `GiftCardSpend` is deprecated in favour of `GiftCardCartSpending`; `GiftCardService::isGiftCardPayment()` takes the
  cart to judge (the cart being paid, the session cart otherwise).

### 3.1.0

- Email and PDF ported to Twig: `GiftCardEmailService` renders them through `ParserResolver` from the active email
  and PDF themes (the `ParserInterface` it received resolved to `ParserFallback`, which renders nothing), and sends
  through `MailerFactory`. The Smarty templates are removed, so is the PDF of another shop embedded in the generic
  one. The send window is prefilled with the subject and the body of the email theme.
- Codes drawn from the cryptographically secure engine, again while the code is taken (the previous draw used
  `rand()` and lost the result of its retry, handing out a taken code). Unique index on `gift_card.code`: created by
  the update when no code is duplicated, otherwise reported in the log and left to the shop. The update runs it on
  a connection of its own: an ALTER TABLE would commit the transaction the core runs the update in.
- Attaching a card to a customer account: refused for a disabled or expired card, same message for a wrong,
  disabled or already attached code, rate limited (10 attempts an hour per customer, 30 per client address).
  Behind a reverse proxy, the shop must declare it in Symfony's `trusted_proxies`: otherwise every customer shares
  the proxy address and the per-address limit applies to all of them together.
- Cancelling or refunding an order that bought cards disables those nothing was spent from; a cancelled or
  refunded order paid with cards gives the spent amount back (decimal arithmetic, no float).
- Paying with a card: the card is read again when the order is paid, and debited by one conditional update
  (enabled, unexpired, enough credit left). A card disabled or spent elsewhere since it was put in the cart refuses
  the payment (`GiftCardPaymentRefusedException`), and nothing is debited from the other cards. Paying the same
  order again no longer debits a card twice.
- Back-office rights: sending by email, creating, editing, enabling and disabling a card and saving the
  configuration require the update right on the module, downloading a PDF and reading the card list the view
  right (the previous check never refused an administrator). The email recipient must be an email address.
- Back-office card list: names and references loaded in one query per table instead of three per card; the data
  handed to the list script is JSON-encoded for a script context and every cell is escaped.
- Buying a card requires the sponsor and beneficiary names; names and message are bounded to their columns.
- The forced amount of a card is read in the language of the order, then the default language, then the first
  language that has one, instead of `fr_FR` only; an empty or zero forced amount keeps the price.
- Activation no longer creates a new template and a new feature each time: the stored ids are read under the key
  they were written with, and the template and feature the module named are reused when an id is lost.
- Integration tests.

## Tests

From the root of a Thelia 3 project where the module is installed, against a disposable database whose name ends
with `_test` (created by `php bin/test-prepare` with the same variables):

```
DATABASE_HOST=… DATABASE_PORT=… DATABASE_NAME=giftcard_test DATABASE_USER=… DATABASE_PASSWORD=… \
  KERNEL_CLASS='App\Kernel' APP_ENV=test APP_DEBUG=0 \
  vendor/bin/phpunit --no-configuration --bootstrap vendor/thelia/modules/TheliaGiftCard/Tests/bootstrap.php vendor/thelia/modules/TheliaGiftCard/Tests
```

`GiftCardCodeUniqueIndexTest` drops and recreates the unique index of the test database.
