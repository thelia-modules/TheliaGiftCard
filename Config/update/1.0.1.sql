# This is a fix for InnoDB in MySQL >= 4.1.x
# It "suspends judgement" for fkey relationships until are tables are set.
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE `gift_card_email_status`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `status_id` INTEGER NOT NULL,
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