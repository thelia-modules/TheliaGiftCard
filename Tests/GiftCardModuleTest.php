<?php

declare(strict_types=1);

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Tests;

use Thelia\Core\Form\TheliaFormFactory;
use Thelia\Model\FeatureQuery;
use Thelia\Model\TemplateQuery;
use TheliaGiftCard\Form\BuyCustomGiftCardForm;
use TheliaGiftCard\Form\Config\ManualyCreateGiftCard;
use TheliaGiftCard\TheliaGiftCard;

final class GiftCardModuleTest extends GiftCardTestCase
{
    public function testActivatingTheModuleAgainDoesNotCreateAnotherTemplateOrFeature(): void
    {
        $templatesBefore = TemplateQuery::create()->count();
        $featuresBefore = FeatureQuery::create()->count();

        $this->module()->postActivation();
        $this->module()->postActivation();

        self::assertSame($templatesBefore + 1, TemplateQuery::create()->count());
        self::assertSame($featuresBefore + 1, FeatureQuery::create()->count());
    }

    public function testTheIdsStoredByThePreviousReleasesAreReused(): void
    {
        $fixtures = $this->createFixtureFactory();
        $template = $fixtures->template();
        $feature = $fixtures->feature();
        // Up to 3.0.0 the ids were stored under the display names.
        TheliaGiftCard::setConfigValue(TheliaGiftCard::GIFT_CARD_TEMPLATE_NAME, $template->getId());
        TheliaGiftCard::setConfigValue(TheliaGiftCard::GIFT_CARD_FEATURE_NAME, $feature->getId());
        $templatesBefore = TemplateQuery::create()->count();
        $featuresBefore = FeatureQuery::create()->count();

        $this->module()->postActivation();

        self::assertSame($templatesBefore, TemplateQuery::create()->count());
        self::assertSame($featuresBefore, FeatureQuery::create()->count());
        self::assertSame((string) $template->getId(), TheliaGiftCard::getConfigValue(TheliaGiftCard::GIFT_CARD_TEMPLATE_CONFIG_NAME));
    }

    public function testALostIdFallsBackOnTheTemplateAndFeatureTheModuleNamed(): void
    {
        $fixtures = $this->createFixtureFactory();
        $template = $fixtures->template();
        $template->setLocale('fr_FR')->setName(TheliaGiftCard::GIFT_CARD_TEMPLATE_NAME)->save();
        $feature = $fixtures->feature();
        $feature->setLocale('fr_FR')->setTitle(TheliaGiftCard::GIFT_CARD_FEATURE_NAME)->save();
        TheliaGiftCard::setConfigValue(TheliaGiftCard::GIFT_CARD_TEMPLATE_NAME, '999999');
        TheliaGiftCard::setConfigValue(TheliaGiftCard::GIFT_CARD_FEATURE_NAME, '999999');
        $templatesBefore = TemplateQuery::create()->count();
        $featuresBefore = FeatureQuery::create()->count();

        $this->module()->postActivation();

        self::assertSame($templatesBefore, TemplateQuery::create()->count());
        self::assertSame($featuresBefore, FeatureQuery::create()->count());
        self::assertSame((string) $feature->getId(), TheliaGiftCard::getConfigValue(TheliaGiftCard::GIFT_CARD_FEATURE_CONFIG_NAME));
    }

    public function testTheCardBoughtOnTheShopNeedsBothNames(): void
    {
        $form = $this->submit(BuyCustomGiftCardForm::getName(), [
            'product_id' => '1',
            'sponsor_name' => '',
            'beneficiary_name' => ' ',
            'beneficiary_message' => 'Hello',
        ]);

        self::assertFalse($form->isValid());
        self::assertCount(1, $form->get('sponsor_name')->getErrors());
        self::assertCount(1, $form->get('beneficiary_name')->getErrors());
        self::assertCount(0, $form->get('beneficiary_message')->getErrors());
    }

    public function testTheMessageIsBoundedToItsColumn(): void
    {
        $form = $this->submit(BuyCustomGiftCardForm::getName(), [
            'product_id' => '1',
            'sponsor_name' => 'Alice',
            'beneficiary_name' => 'Bob',
            'beneficiary_message' => str_repeat('a', 501),
        ]);

        self::assertCount(1, $form->get('beneficiary_message')->getErrors());
    }

    public function testACardCreatedInTheBackOfficeKeepsOptionalNames(): void
    {
        $form = $this->submit(ManualyCreateGiftCard::getName(), [
            'amount' => '20',
            'expiration_date' => (new \DateTime('+1 year'))->format('Y-m-d'),
        ]);

        self::assertCount(0, $form->get('sponsor_name')->getErrors());
        self::assertCount(0, $form->get('beneficiary_name')->getErrors());
    }

    /**
     * @param array<string, string> $data
     */
    private function submit(string $formName, array $data): \Symfony\Component\Form\FormInterface
    {
        $factory = $this->getService(TheliaFormFactory::class);
        $form = $factory->createForm($formName, options: ['csrf_protection' => false])->getForm();
        $form->submit($data);

        return $form;
    }

    private function module(): TheliaGiftCard
    {
        $module = new TheliaGiftCard();
        $module->setContainer(static::getContainer());

        return $module;
    }
}
