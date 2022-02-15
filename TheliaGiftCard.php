<?php
/*************************************************************************************/
/*      Copyright (c) BERTRAND TOURLONIAS                                            */
/*      email : btourlonias@openstudio.fr                                            */
/*************************************************************************************/

namespace TheliaGiftCard;

use Propel\Runtime\Connection\ConnectionInterface;
use Symfony\Component\Finder\Finder;
use Thelia\Core\Event\Category\CategoryCreateEvent;
use Thelia\Core\Event\Feature\FeatureCreateEvent;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\Product\ProductCreateEvent;
use Thelia\Core\Event\Tax\TaxEvent;
use Thelia\Core\Event\Template\TemplateCreateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Core\Translation\Translator;
use Thelia\Install\Database;
use Thelia\Model\Base\FeatureTemplateQuery;
use Thelia\Model\Base\ModuleConfig;
use Thelia\Model\Category;
use Thelia\Model\CategoryI18nQuery;
use Thelia\Model\CategoryQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Country;
use Thelia\Model\CountryQuery;
use Thelia\Model\FeatureQuery;
use Thelia\Model\FeatureTemplate;
use Thelia\Model\ModuleConfigQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderStatusQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductCategory;
use Thelia\Model\ProductCategoryQuery;
use Thelia\Model\ProductPrice;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\Tax;
use Thelia\Model\TaxI18nQuery;
use Thelia\Model\TaxRule;
use Thelia\Model\TaxRuleCountry;
use Thelia\Model\TaxRuleQuery;
use Thelia\Model\TemplateQuery;
use Thelia\Module\AbstractPaymentModule;
use TheliaGiftCard\Model\GiftCardQuery;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;

class TheliaGiftCard extends AbstractPaymentModule
{
    /** @var Translator */
    protected $translator;

    /** @var string */
    const DOMAIN_NAME = 'theliagiftcard';

    /** @var string */
    const MODULE_CODE = 'TheliaGiftCard';

    const GIFT_CARD_CART_PRODUCT_REF = 'GIFTCARD_CART';

    const GIFT_CARD_CATEGORY_CONF_NAME = 'gift_card_category';
    const GIFT_CARD_ORDER_STATUS_CONF_NAME = 'gift_card_order_status';
    const GIFT_CARD_MODE_CONF_NAME = 'gift_card_mode';

    const GIFT_CARD_TEMPLATE_NAME = 'Carte cadeau';
    const GIFT_CARD_FEATURE_NAME = 'Montant carte cadeau';
    const GIFT_CARD_TEMPLATE_CONFIG_NAME = 'template_gift_card';
    const GIFT_CARD_FEATURE_CONFIG_NAME = 'forced_amount_gift_card';
    const GIFT_CARD_SESSION_POSTAGE = 'GIFT_CARD_SESSION_POSTAGE';

    const STRING_CODE = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';

    public static function GENERATE_CODE()
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
        } catch (\Exception $e) {
            $database = new Database($con);
            $database->insertSql(null, [__DIR__ . "/Config/thelia.sql"]);
        }
        $locale = $this->getRequest()->getSession()->getLang()->getLocale();
        $category = $this->createGiftCardCartCategory($locale, $con);
        $this->createCartGiftCartProduct($locale, $category, $con);
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

        /** @var \SplFileInfo $file */
        foreach ($finder as $file) {
            if (version_compare($currentVersion, $file->getBasename('.sql'), '<')) {
                $database->insertSql(null, [$file->getPathname()]);
            }
        }
    }

    public function getHooks()
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

    public function isValidPayment()
    {
        return round($this->getCurrentOrderTotalAmount(true, true, true), 4) == 0;
    }

    public function pay(Order $order)
    {
        $event = new OrderEvent($order);
        $event->setStatus(OrderStatusQuery::getPaidStatus()->getId());
        $this->getDispatcher()->dispatch($event, TheliaEvents::ORDER_UPDATE_STATUS);
    }

    public function manageStockOnCreation()
    {
        return false;
    }

    public static function getGiftCardCategoryId()
    {
        $categoryId = ConfigQuery::read(TheliaGiftCard::GIFT_CARD_CATEGORY_CONF_NAME, '');
        return intval($categoryId);
    }

    public static function getGiftCardModeId()
    {
        $modeId = ConfigQuery::read(TheliaGiftCard::GIFT_CARD_MODE_CONF_NAME, '');
        return intval($modeId);
    }


    public static function getGiftCardOrderStatusId()
    {
        $osId = ConfigQuery::read(TheliaGiftCard::GIFT_CARD_ORDER_STATUS_CONF_NAME, '');
        return intval($osId);
    }

    public static function getGiftCardProductList()
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

    /**
     * @param $locale
     * @param $con
     * @return int|Category
     * @throws \Propel\Runtime\Exception\PropelException
     */
    protected function createGiftCardCartCategory($locale, $con)
    {
        $cat = CategoryI18nQuery::create()
            ->filterByTitle('Gift Card Tools')
            ->findOne();

        if (null == $cat) {
            $eventCat = new CategoryCreateEvent();
            $eventCat
                ->setLocale($locale)
                ->setTitle('Gift Card Tools')
                ->setVisible(0)
                ->setParent(0);

            $this->getDispatcher()->dispatch($eventCat, TheliaEvents::CATEGORY_CREATE);

            return $eventCat->getCategory();
        }

        return $cat->getCategory();
    }

    /**
     * @param $locale
     * @param Category $category
     */
    protected function createCartGiftCartProduct($locale, Category $category, ConnectionInterface $con)
    {
        $product = ProductQuery::create()
            ->filterByRef(self::GIFT_CARD_CART_PRODUCT_REF)
            ->findOne();

        if (null === $product) {
            $event = new ProductCreateEvent();

            $event
                ->setRef(self::GIFT_CARD_CART_PRODUCT_REF)
                ->setTitle($this->trans('Gift Card'))
                ->setLocale('fr_FR')
                ->setQuantity(0)
                ->setVisible(1)
                ->setDefaultCategory($category->getId())
                ->setBasePrice(0)
                ->setVirtual(1)
                ->setTaxRuleId($this->createTax($locale)->getId())
                ->setCurrencyId(1);

            $this->getDispatcher()->dispatch($event, TheliaEvents::PRODUCT_CREATE);
        }
    }

    /**
     * @param $locale
     * @return mixed|TaxRule
     */
    protected function createTax($locale)
    {
        $currentTaxI18n = TaxI18nQuery::create()
            ->filterByTitle('Tax GiftCard')
            ->findOne();

        if (null === $currentTaxI18n) {
            $eventTax = new TaxEvent();
            $eventTax
                ->setTitle('Tax GiftCard')
                ->setLocale($locale)
                ->setDescription('Gift card tax 0%')
                ->setType(Tax::unescapeTypeName('Thelia-TaxEngine-TaxType-PricePercentTaxType'))
                ->setRequirements(array('percent' => 0));
            $this->getDispatcher()->dispatch($eventTax, TheliaEvents::TAX_CREATE);

            $taxRule = new TaxRule();
            $taxRule
                ->setLocale($locale)
                ->setTitle('Tax rule GiftCard')
                ->save();

            $countries = CountryQuery::create()
                ->filterByVisible(1)
                ->find();

            /** @var Country $country */
            foreach ($countries as $country) {
                $taxRuleCountry = new TaxRuleCountry();
                $taxRuleCountry->setTaxRule($taxRule)
                    ->setCountryId($country->getId())
                    ->setTax($eventTax->getTax())
                    ->setPosition(3)
                    ->save();
            }

            return $taxRule;
        }
        /** @var TaxRuleCountry $taxRuleCountry */
        $taxRuleCountry = $currentTaxI18n->getTax()->getTaxRuleCountries()->getFirst();
        return $taxRuleCountry->getTaxRule();
    }

    protected function handleGiftCardTemplate($locale)
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
                continue;
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
                    ->setTitle(Translator::getInstance()->trans(self::GIFT_CARD_FEATURE_NAME));
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

    protected function trans($id, $parameters = [], $locale = null)
    {
        if (null === $this->translator) {
            $this->translator = Translator::getInstance();
        }

        return $this->translator->trans($id, $parameters, self::DOMAIN_NAME, $locale);
    }

    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude([THELIA_MODULE_DIR . ucfirst(self::getModuleCode()). "/I18n/*"])
            ->autowire(true)
            ->autoconfigure(true);
    }
}
