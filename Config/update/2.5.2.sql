SET FOREIGN_KEY_CHECKS = 0;
ALTER TABLE gift_card_info_cart ADD beneficiary_email VARCHAR(255) NULL;
ALTER TABLE gift_card_info_cart ADD beneficiary_address VARCHAR(255) NULL;

ALTER TABLE gift_card_cart DROP FOREIGN KEY `fk_cart_item_gift_card`;
ALTER TABLE gift_card_cart DROP COLUMN `cart_item_id`;
SET FOREIGN_KEY_CHECKS = 1;