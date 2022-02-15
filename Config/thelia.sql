
# This is a fix for InnoDB in MySQL >= 4.1.x
# It "suspends judgement" for fkey relationships until are tables are set.
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- gift_card
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `gift_card`;

CREATE TABLE `gift_card`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `sponsor_customer_id` INTEGER,
    `beneficiary_customer_id` INTEGER,
    `order_id` INTEGER,
    `product_id` INTEGER,
    `code` VARCHAR(100) NOT NULL,
    `amount` DECIMAL(16,6),
    `spend_amount` DECIMAL(16,6),
    `status` INTEGER(1),
    `expiration_date` DATE NOT NULL,
    `created_at` DATETIME,
    `updated_at` DATETIME,
    PRIMARY KEY (`id`),
    INDEX `fi_card_gift_sponsor_customer` (`sponsor_customer_id`),
    INDEX `fi_card_gift_beneficiary_customer` (`beneficiary_customer_id`),
    INDEX `fi_gift_card_order` (`order_id`),
    INDEX `fi_gift_card_product` (`product_id`),
    CONSTRAINT `fk_card_gift_sponsor_customer`
        FOREIGN KEY (`sponsor_customer_id`)
        REFERENCES `customer` (`id`)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT `fk_card_gift_beneficiary_customer`
        FOREIGN KEY (`beneficiary_customer_id`)
        REFERENCES `customer` (`id`)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT `fk_gift_card_order`
        FOREIGN KEY (`order_id`)
        REFERENCES `order` (`id`)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT `fk_gift_card_product`
        FOREIGN KEY (`product_id`)
        REFERENCES `product` (`id`)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- gift_card_info_cart
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `gift_card_info_cart`;

CREATE TABLE `gift_card_info_cart`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `order_product_id` INTEGER,
    `gift_card_id` INTEGER,
    `cart_id` INTEGER,
    `cart_item_id` INTEGER,
    `sponsor_name` VARCHAR(250),
    `beneficiary_name` VARCHAR(250),
    `beneficiary_message` VARCHAR(500),
    PRIMARY KEY (`id`),
    INDEX `fi_gift_card_info_cart` (`cart_id`),
    INDEX `fi_gift_card_info_cart_item` (`cart_item_id`),
    CONSTRAINT `fk_gift_card_info_cart`
        FOREIGN KEY (`cart_id`)
        REFERENCES `cart` (`id`)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT `fk_gift_card_info_cart_item`
        FOREIGN KEY (`cart_item_id`)
        REFERENCES `cart_item` (`id`)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- gift_card_cart
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `gift_card_cart`;

CREATE TABLE `gift_card_cart`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `gift_card_id` INTEGER,
    `cart_id` INTEGER NOT NULL,
    `cart_item_id` INTEGER NOT NULL,
    `spend_amount` DECIMAL(16,6),
    `created_at` DATETIME,
    `updated_at` DATETIME,
    PRIMARY KEY (`id`),
    INDEX `fi_cart_gift_card` (`cart_id`),
    INDEX `fi_cart_item_gift_card` (`cart_item_id`),
    INDEX `fi_card_gift_cart_cg` (`gift_card_id`),
    CONSTRAINT `fk_cart_gift_card`
        FOREIGN KEY (`cart_id`)
        REFERENCES `cart` (`id`)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT `fk_cart_item_gift_card`
        FOREIGN KEY (`cart_item_id`)
        REFERENCES `cart_item` (`id`)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT `fk_card_gift_cart_cg`
        FOREIGN KEY (`gift_card_id`)
        REFERENCES `gift_card` (`id`)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- gift_card_order
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `gift_card_order`;

CREATE TABLE `gift_card_order`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `gift_card_id` INTEGER NOT NULL,
    `order_id` INTEGER NOT NULL,
    `spend_amount` DECIMAL(16,6),
    `initial_postage` DECIMAL(16,6),
    `status` INTEGER(1) DEFAULT 0,
    `created_at` DATETIME,
    `updated_at` DATETIME,
    PRIMARY KEY (`id`),
    INDEX `idx_gift_card_id` (`gift_card_id`),
    INDEX `idx_gift_card_order_id` (`order_id`),
    CONSTRAINT `fk_card_gift_order`
        FOREIGN KEY (`order_id`)
        REFERENCES `order` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE,
    CONSTRAINT `fk_card_gift_order_cg`
        FOREIGN KEY (`gift_card_id`)
        REFERENCES `gift_card` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- gift_card_email_status
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `gift_card_email_status`;

CREATE TABLE `gift_card_email_status`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `status_id` INTEGER,
    `special_status` VARCHAR(255),
    `email_subject` VARCHAR(255) NOT NULL,
    `email_text` TEXT NOT NULL,
    PRIMARY KEY (`id`),
    INDEX `fi_gift_card_email_order_status_id` (`status_id`),
    CONSTRAINT `fk_gift_card_email_order_status_id`
        FOREIGN KEY (`status_id`)
        REFERENCES `order_status` (`id`)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT
) ENGINE=InnoDB;

# This restores the fkey checks, after having unset them earlier
SET FOREIGN_KEY_CHECKS = 1;
