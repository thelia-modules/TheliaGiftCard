# This is a fix for InnoDB in MySQL >= 4.1.x
# It "suspends judgement" for fkey relationships until are tables are set.
SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE gift_card MODIFY product_id INTEGER NULL;

ALTER TABLE gift_card_info_cart MODIFY cart_id INTEGER NULL;
ALTER TABLE gift_card_info_cart MODIFY cart_item_id INTEGER NULL;

# This restores the fkey checks, after having unset them earlier
SET FOREIGN_KEY_CHECKS = 1;