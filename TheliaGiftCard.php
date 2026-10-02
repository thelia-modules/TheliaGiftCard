<?php

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Connection\ConnectionInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Thelia\Core\Event\Feature\FeatureCreateEvent;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\Template\TemplateCreateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Install\Database;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Core\Translation\Translator;
use Thelia\Model\Base\FeatureTemplateQuery;
use Thelia\Model\CategoryQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Model\FeatureI18nQuery;
use Thelia\Model\FeatureQuery;
use Thelia\Model\FeatureTemplate;
use Thelia\Model\Lang;
use Thelia\Model\Order;
use Thelia\Model\OrderStatusQuery;
use Thelia\Model\ProductCategory;
use Thelia\Model\ProductCategoryQuery;
use Thelia\Model\TemplateI18nQuery;
use Thelia\Model\TemplateQuery;
use Thelia\Module\AbstractPaymentModule;
use TheliaGiftCard\Model\GiftCardCartQuery;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\Model\Map\GiftCardCartTableMap;
use TheliaGiftCard\Service\GiftCardActivationLimiter;
use TheliaGiftCard\Service\GiftCardCodeGenerator;
use TheliaGiftCard\Service\GiftCardCodeUniqueIndex;
use TheliaGiftCard\Service\GiftCardService;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

class TheliaGiftCard extends AbstractPaymentModule
{
    public const DOMAIN_NAME = 'theliagiftcard';
    public const MODULE_CODE = 'TheliaGiftCard';

    /**
     * Front-office translation domain. Thelia derives it from the name of the template
     * directory (`templates/frontOffice/flexy`) and reads the catalogues from the mirrored
     * `I18n/frontOffice/flexy`, so renaming either one renames the domain.
     */
    public const FRONT_TRANSLATION_DOMAIN = 'theliagiftcard.fo.flexy';

    public const GIFT_CARD_CART_PRODUCT_REF = 'GIFTCARD_CART';

    public const GIFT_CARD_TOOL_CATEGORY_CONF_NAME = 'gift_card_tool_category';
    public const GIFT_CARD_CATEGORY_CONF_NAME = 'gift_card_category';
    public const GIFT_CARD_ORDER_STATUS_CONF_NAME = 'gift_card_order_status';
    public const GIFT_CARD_MODE_CONF_NAME = 'gift_card_mode';

    public const GIFT_CARD_TEMPLATE_NAME = 'Carte cadeau';
    public const GIFT_CARD_FEATURE_NAME = 'Montant carte cadeau';
    public const GIFT_CARD_TEMPLATE_CONFIG_NAME = 'template_gift_card';
    public const GIFT_CARD_FEATURE_CONFIG_NAME = 'forced_amount_gift_card';
    public const GIFT_CARD_SESSION_POSTAGE = 'GIFT_CARD_SESSION_POSTAGE';

    public const STRING_CODE = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';

    /**
     * Kept for the callers of the previous releases: the draw lives in GiftCardCodeGenerator.
     */
    public static function GENERATE_CODE(): string
    {
        return (new GiftCardCodeGenerator())->generate();
    }

    public function postActivation(?ConnectionInterface $con = null): void
    {
        try {
            GiftCardQuery::create()->findOne();
        } catch (\Exception) {
            $database = new Database($con);
            $database->insertSql(null, [__DIR__.'/Config/TheliaMain.sql']);
        }
        $request = $this->getContainer()->get('request_stack')->getCurrentRequest();
        $locale = ($request && $request->hasSession() && $request->getSession()->getLang())
            ? $request->getSession()->getLang()->getLocale()
            : Lang::getDefaultLanguage()->getLocale();

        $this->handleGiftCardTemplate($locale);
    }

    public function update($currentVersion, $newVersion, ?ConnectionInterface $con = null): void
    {
        $finder = Finder::create()
            ->name('*.sql')
            ->depth(0)
            ->sortByName()
            ->in(__DIR__.DS.'Config'.DS.'update');

        $database = new Database($con);

        /** @var \SplFileInfo $file */
        foreach ($finder as $file) {
            if (version_compare($currentVersion, $file->getBasename('.sql'), '<')) {
                $database->insertSql(null, [$file->getPathname()]);
            }
        }

        if (version_compare($currentVersion, '3.1.0', '<')) {
            (new GiftCardCodeUniqueIndex())->addOutsideTransaction();
        }
    }

    public function getHooks(): array
    {
        return [
            [
                'type' => TemplateDefinition::FRONT_OFFICE,
                'code' => 'order-invoice.giftcard-form',
                'title' => [
                    'fr_FR' => 'Gift Card invoice Hook',
                    'en_US' => 'Gift Card invoice Hook',
                ],
                'description' => [
                    'fr_FR' => 'Gift Card invoice Hook',
                    'en_US' => 'Gift Card invoice Hook',
                ],
                'chapo' => [
                    'fr_FR' => 'Gift Card invoice Hook',
                    'en_US' => 'Gift Card invoice Hook',
                ],
                'active' => true,
            ],
            [
                'type' => TemplateDefinition::FRONT_OFFICE,
                'code' => 'order-invoice.cart-giftcard-form',
                'title' => [
                    'fr_FR' => 'Gift Card invoice cart Hook',
                    'en_US' => 'Gift Card invoice cart Hook',
                ],
                'description' => [
                    'fr_FR' => 'Gift Card invoice cart Hook',
                    'en_US' => 'Gift Card invoice cart Hook',
                ],
                'chapo' => [
                    'fr_FR' => 'Gift Card invoice cart Hook',
                    'en_US' => 'Gift Card invoice cart Hook',
                ],
                'active' => true,
            ],
        ];
    }

    public function isValidPayment(): bool
    {
        /** @var GiftCardService $giftCardService */
        $giftCardService = $this->getContainer()->get('gift.card.service');

        return $giftCardService->isGiftCardPayment();
    }

    public function pay(Order $order): ?Response
    {
        $event = new OrderEvent($order);
        $event->setStatus(OrderStatusQuery::getPaidStatus()->getId());
        $this->getDispatcher()->dispatch($event, TheliaEvents::ORDER_UPDATE_STATUS);

        return null;
    }

    public function manageStockOnCreation(): bool
    {
        return false;
    }

    public static function isAutoSendEmail(): bool
    {
        return (bool) ConfigQuery::read(TheliaGiftCard::GIFT_CARD_MODE_CONF_NAME, false);
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
        } catch (\Exception) {
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
                $tab[] = $product->getProductId();
            }
        }

        return $tab;
    }

    /**
     * Creates, once, the template and the feature used to force the amount of a gift card.
     *
     * Releases up to 3.0.0 stored their ids under the display names ("Carte cadeau",
     * "Montant carte cadeau") but looked them up under the configuration names, so every
     * activation created a new template and a new feature. Both keys are read, the
     * configuration name is written; when the stored id no longer exists, the template and
     * the feature the module named are looked up before creating new ones.
     */
    protected function handleGiftCardTemplate(string $locale): void
    {
        $templateId = $this->readStoredId(self::GIFT_CARD_TEMPLATE_CONFIG_NAME, self::GIFT_CARD_TEMPLATE_NAME);

        if (null === $templateId || null === TemplateQuery::create()->findPk($templateId)) {
            $templateId = TemplateI18nQuery::create()
                ->filterByName([self::GIFT_CARD_TEMPLATE_NAME, Translator::getInstance()->trans(self::GIFT_CARD_TEMPLATE_NAME)], Criteria::IN)
                ->orderById()
                ->findOne()
                ?->getId();
        }

        if (null === $templateId) {
            $createEvent = new TemplateCreateEvent();
            $createEvent
                ->setLocale($locale)
                ->setTemplateName(Translator::getInstance()->trans(self::GIFT_CARD_TEMPLATE_NAME));

            $this->getDispatcher()->dispatch($createEvent, TheliaEvents::TEMPLATE_CREATE);

            $templateId = $createEvent->getTemplate()->getId();
        }

        $featureId = $this->readStoredId(self::GIFT_CARD_FEATURE_CONFIG_NAME, self::GIFT_CARD_FEATURE_NAME);

        if (null === $featureId || null === FeatureQuery::create()->findPk($featureId)) {
            $featureId = FeatureI18nQuery::create()
                ->filterByTitle([self::GIFT_CARD_FEATURE_NAME, $this->trans(self::GIFT_CARD_FEATURE_NAME)], Criteria::IN)
                ->orderById()
                ->findOne()
                ?->getId();
        }

        if (null === $featureId) {
            $createFeatEvent = new FeatureCreateEvent();
            $createFeatEvent
                ->setLocale($locale)
                ->setTitle($this->trans(self::GIFT_CARD_FEATURE_NAME));

            $this->getDispatcher()->dispatch($createFeatEvent, TheliaEvents::FEATURE_CREATE);

            $featureId = $createFeatEvent->getFeature()->getId();
        }

        self::setConfigValue(self::GIFT_CARD_TEMPLATE_CONFIG_NAME, $templateId);
        self::setConfigValue(self::GIFT_CARD_FEATURE_CONFIG_NAME, $featureId);

        $featureTemplate = FeatureTemplateQuery::create()
            ->filterByFeatureId($featureId)
            ->filterByTemplateId($templateId)
            ->findOne();

        if (null === $featureTemplate) {
            (new FeatureTemplate())
                ->setFeatureId($featureId)
                ->setTemplateId($templateId)
                ->save();
        }
    }

    private function readStoredId(string $configName, string $legacyConfigName): ?int
    {
        $value = self::getConfigValue($configName) ?? self::getConfigValue($legacyConfigName);

        if (null === $value || !ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }

    protected function trans($id, $parameters = [], $locale = null): string
    {
        return Translator::getInstance()->trans($id, $parameters, self::DOMAIN_NAME, $locale);
    }

    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude([
                __DIR__.'/I18n/*',
                __DIR__.'/Config/**/*.php',
                __DIR__.'/Dto/*',
                __DIR__.'/Exception/*',
                __DIR__.'/Model/Map/*',
                __DIR__.'/Tests/*',
                __DIR__.'/TheliaGiftCard.php',
            ])
            ->autowire(true)
            ->autoconfigure(true);

        self::configureActivationLimiter($servicesConfigurator, GiftCardActivationLimiter::PER_CUSTOMER_LIMITER, 10);
        self::configureActivationLimiter($servicesConfigurator, GiftCardActivationLimiter::PER_CLIENT_LIMITER, 30);
    }

    /**
     * Declared here rather than under framework.rate_limiter: a module cannot add to the
     * framework configuration. The pool is the one the framework gives its own limiters.
     */
    private static function configureActivationLimiter(ServicesConfigurator $servicesConfigurator, string $serviceId, int $attemptsPerHour): void
    {
        $servicesConfigurator->set($serviceId.'.storage', CacheStorage::class)
            ->args([service('cache.rate_limiter')]);

        $servicesConfigurator->set($serviceId, RateLimiterFactory::class)
            ->args([
                [
                    'id' => $serviceId,
                    'policy' => 'sliding_window',
                    'limit' => $attemptsPerHour,
                    'interval' => '1 hour',
                ],
                service($serviceId.'.storage'),
            ]);
    }
}
