<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard;

use Exception;
use Propel\Runtime\Connection\ConnectionInterface;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Thelia\Core\Event\Feature\FeatureCreateEvent;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\Template\TemplateCreateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Core\Translation\Translator;
use Thelia\Install\Database;
use Thelia\Model\AddressQuery;
use Thelia\Model\Base\FeatureTemplateQuery;
use Thelia\Model\Base\ModuleConfig;
use Thelia\Model\Cart;
use Thelia\Model\CategoryQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Model\FeatureQuery;
use Thelia\Model\FeatureTemplate;
use Thelia\Model\ModuleConfigQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderStatusQuery;
use Thelia\Model\ProductCategory;
use Thelia\Model\ProductCategoryQuery;
use Thelia\Model\TemplateQuery;
use Thelia\Module\AbstractPaymentModule;
use Thelia\TaxEngine\TaxEngine;
use TheliaGiftCard\Model\GiftCardCartQuery;
use TheliaGiftCard\Model\GiftCardQuery;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use TheliaGiftCard\Model\Map\GiftCardCartTableMap;
use TheliaGiftCard\Service\GiftCardService;
use TheliaGiftCard\Service\GiftCardSpend;

class TheliaGiftCard extends AbstractPaymentModule
{
    const  DOMAIN_NAME = 'theliagiftcard';
    const  MODULE_CODE = 'TheliaGiftCard';

    const GIFT_CARD_CART_PRODUCT_REF = 'GIFTCARD_CART';

    const GIFT_CARD_TOOL_CATEGORY_CONF_NAME = 'gift_card_tool_category';
    const GIFT_CARD_CATEGORY_CONF_NAME = 'gift_card_category';
    const GIFT_CARD_ORDER_STATUS_CONF_NAME = 'gift_card_order_status';
    const GIFT_CARD_MODE_CONF_NAME = 'gift_card_mode';

    const GIFT_CARD_TEMPLATE_NAME = 'Carte cadeau';
    const GIFT_CARD_FEATURE_NAME = 'Montant carte cadeau';
    const GIFT_CARD_TEMPLATE_CONFIG_NAME = 'template_gift_card';
    const GIFT_CARD_FEATURE_CONFIG_NAME = 'forced_amount_gift_card';
    const GIFT_CARD_SESSION_POSTAGE = 'GIFT_CARD_SESSION_POSTAGE';

    const STRING_CODE = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';

    public static function GENERATE_CODE(): string
    {
        $code = '';

        for ($i = 0; $i < 8; $i++) {
            $code .= self::STRING_CODE[rand() % strlen(self::STRING_CODE)];
        }

        $giftCard = GiftCardQuery::create()
            ->filterByCode($code)
            ->findOne();

        if ($giftCard) {
            self::GENERATE_CODE();
        } else {
            return $code;
        }

        return $code;
    }

    public function postActivation(ConnectionInterface $con = null): void
    {
        try {
            GiftCardQuery::create()->findOne();
        } catch (Exception) {
            $database = new Database($con);
            $database->insertSql(null, [__DIR__ . "/Config/TheliaMain.sql"]);
        }
        $locale = $this->getRequest()->getSession()->getLang()->getLocale();

        $this->handleGiftCardTemplate($locale);
    }

    public function update($currentVersion, $newVersion, ConnectionInterface $con = null): void
    {
        $finder = Finder::create()
            ->name('*.sql')
            ->depth(0)
            ->sortByName()
            ->in(__DIR__ . DS . 'Config' . DS . 'update');

        $database = new Database($con);

        /** @var SplFileInfo $file */
        foreach ($finder as $file) {
            if (version_compare($currentVersion, $file->getBasename('.sql'), '<')) {
                $database->insertSql(null, [$file->getPathname()]);
            }
        }
    }

    public function getHooks(): array
    {
        return array(
            [
                "type" => TemplateDefinition::FRONT_OFFICE,
                "code" => "order-invoice.giftcard-form",
                "title" => array(
                    "fr_FR" => "Gift Card invoice Hook",
                    "en_US" => "Gift Card invoice Hook",
                ),
                "description" => array(
                    "fr_FR" => "Gift Card invoice Hook",
                    "en_US" => "Gift Card invoice Hook",
                ),
                "chapo" => array(
                    "fr_FR" => "Gift Card invoice Hook",
                    "en_US" => "Gift Card invoice Hook",
                ),
                "active" => true
            ],
            [
                "type" => TemplateDefinition::FRONT_OFFICE,
                "code" => "order-invoice.cart-giftcard-form",
                "title" => array(
                    "fr_FR" => "Gift Card invoice cart Hook",
                    "en_US" => "Gift Card invoice cart Hook",
                ),
                "description" => array(
                    "fr_FR" => "Gift Card invoice cart Hook",
                    "en_US" => "Gift Card invoice cart Hook",
                ),
                "chapo" => array(
                    "fr_FR" => "Gift Card invoice cart Hook",
                    "en_US" => "Gift Card invoice cart Hook",
                ),
                "active" => true
            ]
        );
    }

    public function isValidPayment(): bool
    {
        /** @var GiftCardService $giftCardService */
        $giftCardService = $this->getContainer()->get('gift.card.service');
        return $giftCardService->isGiftCardPayment();
    }

    public function pay(Order $order): void
    {
        $event = new OrderEvent($order);
        $event->setStatus(OrderStatusQuery::getPaidStatus()->getId());
        $this->getDispatcher()->dispatch($event, TheliaEvents::ORDER_UPDATE_STATUS);
    }

    public function manageStockOnCreation(): bool
    {
        return false;
    }

    public static function isAutoSendEmail(): bool
    {
        return (boolean)ConfigQuery::read(TheliaGiftCard::GIFT_CARD_MODE_CONF_NAME, false);
    }

    public static function getGiftCardCategoryId(): int
    {
        $categoryId = ConfigQuery::read(TheliaGiftCard::GIFT_CARD_CATEGORY_CONF_NAME, '');
        return intval($categoryId);
    }

    public static function getGiftCardOrderStatusId(): int
    {
        $osId = ConfigQuery::read(TheliaGiftCard::GIFT_CARD_ORDER_STATUS_CONF_NAME, '');
        return intval($osId);
    }

    public static function getTotalCartGiftCardAmount(?int $cartId): float
    {
        if (null === $cartId) {
            return 0;
        }

        try {
            $giftCards = GiftCardCartQuery::create()
                ->select([GiftCardCartTableMap::COL_SPEND_AMOUNT, 'spend_amount'])
                ->filterByCartId($cartId)
                ->find();

            if ($giftCards->isEmpty()) {
                return 0;
            }

            return array_reduce($giftCards->toArray(), function ($sum, $giftCard) {
                return $sum + $giftCard['spend_amount'];
            }, 0);

        } catch (Exception) {
            return 0;
        }
    }

    public static function getGiftCardProductList(): array
    {
        $tab = [];

        $category = CategoryQuery::create()->findPk(TheliaGiftCard::getGiftCardCategoryId());

        if (null !== $category) {
            $products = ProductCategoryQuery::create()
                ->filterByCategoryId($category->getId())
                ->find();

            /** @var ProductCategory $product */
            foreach ($products as $product) {
                $tab [] = $product->getProductId();
            }
        }

        return $tab;
    }

    protected function handleGiftCardTemplate($locale): void
    {
        //Creation de gabarit et feature Carte cadeau pour forcer le montant d'une carte cadeau
        $configGCtemplateId = null;
        $configGCfeatureId = null;

        $configsGiftCard = ModuleConfigQuery::create()
            ->filterByModuleId($this->getModuleModel()->getId())
            ->filterByName(self::GIFT_CARD_TEMPLATE_CONFIG_NAME)
            ->_or()
            ->filterByName(self::GIFT_CARD_FEATURE_CONFIG_NAME)
            ->find();

        /** @var ModuleConfig $config */
        foreach ($configsGiftCard as $config) {
            if ($config->getName() == self::GIFT_CARD_TEMPLATE_CONFIG_NAME) {
                $configGCtemplateId = $config->setLocale($locale)->getValue();
                continue;
            }

            if ($config->getName() == self::GIFT_CARD_FEATURE_CONFIG_NAME) {
                $configGCfeatureId = $config->setLocale($locale)->getValue();
            }
        }

        if (null == $configGCtemplateId) {
            $templateGiftCard = TemplateQuery::create()
                ->filterById($configGCfeatureId)
                ->findOne();

            if (null == $templateGiftCard) {
                $createEvent = new TemplateCreateEvent();
                $createEvent
                    ->setLocale($locale)
                    ->setTemplateName(Translator::getInstance()->trans(self::GIFT_CARD_TEMPLATE_NAME));

                $this->getDispatcher()->dispatch($createEvent, TheliaEvents::TEMPLATE_CREATE);

                TheliaGiftCard::setConfigValue(self::GIFT_CARD_TEMPLATE_NAME, $createEvent->getTemplate()->getId());

                $configGCtemplateId = $createEvent->getTemplate()->getId();
            }
        }

        if (null == $configGCfeatureId) {
            $featGiftCard = FeatureQuery::create()
                ->filterById($configGCfeatureId)
                ->findOne();

            if (null == $featGiftCard) {
                $createFeatEvent = new FeatureCreateEvent();
                $createFeatEvent
                    ->setLocale($locale)
                    ->setTitle($this->trans(self::GIFT_CARD_FEATURE_NAME));
                $this->getDispatcher()->dispatch($createFeatEvent, TheliaEvents::FEATURE_CREATE);

                TheliaGiftCard::setConfigValue(self::GIFT_CARD_FEATURE_NAME, $createFeatEvent->getFeature()->getId());

                $configGCfeatureId = $createFeatEvent->getFeature()->getId();
            }
        }

        $featureProduct = FeatureTemplateQuery::create()
            ->filterByFeatureId($configGCfeatureId)
            ->filterByTemplateId($configGCtemplateId)
            ->findOne();

        if (null === $featureProduct) {
            $featureProduct = new FeatureTemplate();
            $featureProduct
                ->setFeatureId($configGCfeatureId)
                ->setTemplateId($configGCtemplateId)
                ->save();
        }
    }

    protected function trans($id, $parameters = [], $locale = null): string
    {
        return Translator::getInstance()->trans($id, $parameters, self::DOMAIN_NAME, $locale);
    }

    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude(["/I18n/*"])
            ->autowire(true)
            ->autoconfigure(true);
    }
}
