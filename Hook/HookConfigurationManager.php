<?php

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Hook;

use Propel\Runtime\Exception\PropelException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Form\TheliaFormFactory;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Core\Translation\Translator;
use TheliaGiftCard\Form\Config\GiftCardConfigForm;
use TheliaGiftCard\Form\Config\ManualyCreateGiftCard;
use TheliaGiftCard\Form\Config\ManualyEditGiftCard;
use TheliaGiftCard\Form\GiftCardCustomerEmailForm;
use TheliaGiftCard\Model\Map\GiftCardTableMap;
use TheliaGiftCard\Service\GiftCardEmailService;
use TheliaGiftCard\Service\GiftCardListInfoProvider;
use TheliaGiftCard\TheliaGiftCard;

class HookConfigurationManager extends BaseHook
{
    public function __construct(
        private readonly TheliaFormFactory $formFactory,
        private readonly GiftCardEmailService $giftCardEmailService,
        private readonly GiftCardListInfoProvider $listInfoProvider,
        ?EventDispatcherInterface $dispatcher = null,
        ?ParserResolver $parserResolver = null,
    ) {
        parent::__construct($dispatcher, $parserResolver);
    }

    public static function getSubscribedHooks(): array
    {
        return [
            'module.configuration' => [
                ['type' => 'back', 'method' => 'onConfiguration'],
            ],
            'module.config-js' => [
                ['type' => 'back', 'method' => 'onProductEditJs'],
            ],
        ];
    }

    /**
     * @throws PropelException
     */
    public function onConfiguration(HookRenderEvent $event): void
    {
        $configForm = $this->formFactory->createForm(GiftCardConfigForm::getName());
        $createForm = $this->formFactory->createForm(ManualyCreateGiftCard::getName());
        $editForm = $this->formFactory->createForm(ManualyEditGiftCard::getName());
        $emailForm = $this->formFactory->createForm(GiftCardCustomerEmailForm::getName());

        $event->add(
            $this->render(
                'TheliaGiftCard/gift-card-config.html.twig',
                [
                    'columnsDefinitionTransaction' => $this->defineColumnsDefinition(),
                    'config_form' => $configForm->createView()->getView(),
                    'create_form' => $createForm->createView()->getView(),
                    'edit_form' => $editForm->createView()->getView(),
                    'email_form' => $emailForm->createView()->getView(),
                    'email_default_subject' => $this->giftCardEmailService->defaultEmailSubject(),
                    'email_default_text' => $this->giftCardEmailService->renderDefaultEmailText(),
                ]
            )
        );
    }

    /**
     * @throws PropelException
     */
    public function onProductEditJs(HookRenderEvent $event): void
    {
        $event->add(
            $this->render(
                'TheliaGiftCard/datatable.js.html.twig',
                [
                    'all_info' => $this->listInfoProvider->build(),
                    'columnsDefinitionTransaction' => $this->defineColumnsDefinition(),
                ]
            )
        );
    }

    protected function defineColumnsDefinition(): array
    {
        return HookConfigurationManager::getdefineColumnsDefinition();
    }

    public static function getdefineColumnsDefinition(): array
    {
        $i = -1;

        return [
            [
                'name' => 'id',
                'targets' => ++$i,
                'orm' => GiftCardTableMap::COL_ID,
                'title' => Translator::getInstance()->trans('ID', [], TheliaGiftCard::DOMAIN_NAME),
                'searchable' => false,
            ],
            [
                'name' => 'orderRef',
                'targets' => ++$i,
                'orm' => null,
                'title' => Translator::getInstance()->trans('order', [], TheliaGiftCard::DOMAIN_NAME),
                'searchable' => true,
            ],
            [
                'name' => 'code',
                'targets' => ++$i,
                'orm' => GiftCardTableMap::COL_CODE,
                'title' => Translator::getInstance()->trans('code', [], TheliaGiftCard::DOMAIN_NAME),
                'searchable' => true,
            ],
            [
                'name' => 'sponsor_customer_id',
                'targets' => ++$i,
                'orm' => GiftCardTableMap::COL_SPONSOR_CUSTOMER_ID,
                'title' => Translator::getInstance()->trans('sponsor_customer', [], TheliaGiftCard::DOMAIN_NAME),
                'searchable' => true,
            ],
            [
                'name' => 'beneficiary_customer_id',
                'targets' => ++$i,
                'orm' => GiftCardTableMap::COL_BENEFICIARY_CUSTOMER_ID,
                'title' => Translator::getInstance()->trans('beneficiary_customer', [], TheliaGiftCard::DOMAIN_NAME),
                'searchable' => true,
            ],
            [
                'name' => 'amount',
                'targets' => ++$i,
                'orm' => GiftCardTableMap::COL_AMOUNT,
                'title' => Translator::getInstance()->trans('amount', [], TheliaGiftCard::DOMAIN_NAME),
                'searchable' => true,
            ],
            [
                'name' => 'spend_amount',
                'targets' => ++$i,
                'orm' => GiftCardTableMap::COL_SPEND_AMOUNT,
                'title' => Translator::getInstance()->trans('spend_amount', [], TheliaGiftCard::DOMAIN_NAME),
                'searchable' => true,
            ],
            [
                'name' => 'expiration_date',
                'targets' => ++$i,
                'orm' => GiftCardTableMap::COL_EXPIRATION_DATE,
                'title' => Translator::getInstance()->trans('expiration_date', [], TheliaGiftCard::DOMAIN_NAME),
                'searchable' => false,
            ],
            [
                'name' => 'created_at',
                'targets' => ++$i,
                'orm' => GiftCardTableMap::COL_CREATED_AT,
                'title' => Translator::getInstance()->trans('created_at', [], TheliaGiftCard::DOMAIN_NAME),
                'searchable' => false,
            ],
            [
                'name' => 'status',
                'targets' => ++$i,
                'orm' => GiftCardTableMap::COL_STATUS,
                'title' => Translator::getInstance()->trans('status', [], TheliaGiftCard::DOMAIN_NAME),
                'searchable' => true,
            ],
            [
                'name' => 'pdf',
                'targets' => ++$i,
                'orm' => null,
                'title' => Translator::getInstance()->trans('pdf', [], TheliaGiftCard::DOMAIN_NAME),
                'searchable' => false,
            ],
            [
                'name' => 'action',
                'targets' => ++$i,
                'orm' => null,
                'title' => Translator::getInstance()->trans('action', [], TheliaGiftCard::DOMAIN_NAME),
                'searchable' => false,
            ],
        ];
    }
}
