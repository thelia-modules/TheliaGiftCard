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

### 3.2.8

- A customer can spend their gift cards at checkout again. The form posted to `/gift-card/spend`, which went away with the OpenApi module; the route is back in the module (`POST /gift-card/spend`, signed-in customer, CSRF token), and puts the chosen cards on the cart through `GiftCardCartSpending::spend()` before going back to the payment step.
- The form and the "paid by gift card" / "left to pay" lines are rendered under the total of the Flexy checkout summary, on the `checkout.summary.bottom` theme hook, once a carrier is chosen. "Left to pay" is what the payment module asks for. A Flexy release without that hook shows nothing.
- `ConsumeGiftCart` takes the ids of the cards (`gift_card_ids`, among the cards the customer can spend on the cart) and `amount_used`; `gift_card_codes` and `delivery_module_id` are gone. An amount of 0 takes the cards off the cart.
- The cards are capped on the total of the order with its gift wrapping (Thelia 3.2.4+), and the summary and `ORDER_PAY_GET_TOTAL` compute what is left to pay with the same rule (`GiftCardAmount::leftToPay()`). Before, a card worth more than the cart covered the goods and postage only: the summary said nothing was left to pay, and the card alone was offered as the payment method, while the wrapping was still owed.
- The unused Smarty hooks `order-invoice.giftcard-form` and `order-invoice.cart-giftcard-form` are no longer declared.

### 3.2.5

- Placing an order paid in full by gift cards no longer ends on a 500 (`Call to a member function getProductSaleElementsId() on null` in `OrderPayListener`). Since Thelia 3 keeps the cart until its order is paid, the session hands back a new cart, not saved yet, as soon as the order is paid, which the gift card payment does while the order is placed. The `ORDER_PAY` listener read the id of that cart, null, and the query on a null cart id returned the details of every card created from the back office, which belong to no cart. The listener now reads the cart the order was placed from (`order.cart_id`), and only the details attached to a cart line.
- The recipient details typed for a gift card bought in such an order are linked to its order line, so the card generated afterwards gets them.

### 3.2.4

- The activate and deactivate buttons of the gift card list work again. They are POST forms since 3.0.0, with the CSRF token in the request body since 3.2.1, but the list script still cancelled the submit and requested the `href` of the button, which a button does not have: the request went to the page itself and the card kept its status. The script now posts the form (action and token) and redraws the table once the change is made. The two routes answer `204 No Content` to this script request (`X-Requested-With: XMLHttpRequest`) and still redirect to the list when the form is sent without the script. Any other answer (expired session, refused token, server error) shows a message above the table and enables the button again.
- The edit window opens with the amount, expiration date and recipient e-mail of the card. The template passed the element ids in `attr`, after the id Symfony renders, so the browser kept the generated ids and the script filled nothing. The ids are now passed as the `id` variable. Opening a card cancels the request of the card opened before, and a late answer for another card is ignored, so the window never shows (and saves) the values of a previous card.
- In the edit window, the card id field is hidden again, the recipient e-mail is optional and can be removed by emptying the field, and the usage history table of 2.x, which the script still fills, is back. `/admin/module/theliagiftcard/show/{code}` always returns `beneficiary_address`, `null` when the card has none.
- The edit action no longer fails with a 500 on an invalid form (empty card id), an unknown card or a missing expiration date: it goes back to the list without changing anything.
- The edit window labels and the new message are translated in fr_FR, en_US and de_DE.

#### Template overrides

A shop that overrides `datatable.js.html.twig`, `gift-card-config.html.twig` or `modal/modal_edit_create_gift_card.html.twig` must follow these changes:

- the edit window fields have the ids `amount`, `expirationDate`, `beneficiaryAddress` and `giftCardId` (they were rendered as `edit_gift_card_amount`, `edit_gift_card_expiration_date`, `edit_gift_card_beneficiary_address`), and the window contains the history table (`#logs-body`);
- the status forms of the list carry the `js-gift-card-status-form` class; the `status-button` class is removed from every button and link of the list;
- the configuration page contains the `#gift-card-status-error` message block.

### 3.2.3

- The gift card list of the configuration page loads again. The script declared a thirteenth column (the edit button) that the table header did not have, and DataTables stopped on `Cannot read properties of undefined (reading 'style')` before asking for the cards. The edit column is now part of the column definitions, so the header, the script and the rows of `/admin/module/theliagiftcard/show` all have thirteen columns.
- The back-office templates are translated. They used the `theliagiftcard.bo.default` domain, which is only registered for a `templates/backOffice/default` folder the module no longer has, and no back-office catalogue was shipped. They now use `theliagiftcard.bo.default-twig`, with fr_FR, en_US and de_DE catalogues in `I18n/backOffice/default-twig/`. The `created_at` and `edit` column titles are translated too.
- A shop that overrides these back-office labels in its own catalogues must move them from the `theliagiftcard.bo.default` domain to `theliagiftcard.bo.default-twig`.
- The German column titles of the list and the order block keys (`Montant Carte Cadeau %code : %amount`, `Total payé en carte cadeau`) are translated in fr_FR, en_US and de_DE. The DataTables summary lines are translated as whole sentences.
- The PDF, action and edit columns are no longer sortable: they have no database column to sort on.

### 3.2.2

- The buy form (`/gift-card/info/save`) only accepts a product of the gift card category that has a sale element, and puts its default sale element on the cart. Any other posted product id, unknown or not numeric, is refused with a message instead of being put on the cart with the card information (or failing with a 500).
- A refused post (this case, and also invalid fields such as empty names, a bad e-mail or an expired token) is logged, puts its message on the form and goes back to the form's `error_url`, the cart when the form has none. Before, it always went to the cart without a message. The product page block now posts its own address as `error_url`, so the message is shown above the form.

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
