<?php

/*      Copyright (c) BERTRAND TOURLONIAS */
/*      email : btourlonias@openstudio.fr */

namespace TheliaGiftCard\Hook;

use Propel\Runtime\Exception\PropelException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Form\TheliaFormFactory;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Template\Parser\ParserResolver;
use TheliaGiftCard\Dto\GiftCardListCriteria;
use TheliaGiftCard\Form\Config\GiftCardConfigForm;
use TheliaGiftCard\Form\Config\ManualyCreateGiftCard;
use TheliaGiftCard\Form\Config\ManualyEditGiftCard;
use TheliaGiftCard\Form\GiftCardCustomerEmailForm;
use TheliaGiftCard\Presenter\GiftCardListFilterPresenter;
use TheliaGiftCard\Presenter\GiftCardListRowPresenter;
use TheliaGiftCard\Service\GiftCardEmailService;
use TheliaGiftCard\Service\GiftCardListInfoProvider;
use TheliaGiftCard\Service\GiftCardListService;

class HookConfigurationManager extends BaseHook
{
    public function __construct(
        private readonly TheliaFormFactory $formFactory,
        private readonly GiftCardEmailService $giftCardEmailService,
        private readonly GiftCardListService $listService,
        private readonly GiftCardListInfoProvider $listInfoProvider,
        private readonly GiftCardListRowPresenter $rowPresenter,
        private readonly GiftCardListFilterPresenter $filterPresenter,
        private readonly RequestStack $requestStack,
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
        $request = $this->requestStack->getCurrentRequest();
        $criteria = GiftCardListCriteria::fromQuery($request?->query->all() ?? []);
        $page = $this->listService->find($criteria);

        $configForm = $this->formFactory->createForm(GiftCardConfigForm::getName());
        $createForm = $this->formFactory->createForm(ManualyCreateGiftCard::getName());
        $editForm = $this->formFactory->createForm(ManualyEditGiftCard::getName());
        $emailForm = $this->formFactory->createForm(GiftCardCustomerEmailForm::getName());

        $event->add(
            $this->render(
                'TheliaGiftCard/gift-card-config.html.twig',
                [
                    'rows' => $this->rowPresenter->presentAll(
                        $page->cards,
                        $this->listInfoProvider->build($page->cards),
                        $request?->getLocale() ?? 'en_US',
                    ),
                    'filters' => $this->filterPresenter->present($criteria),
                    'current_page' => $page->page,
                    'pages' => $page->lastPage,
                    'total' => $page->total,
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

    public function onProductEditJs(HookRenderEvent $event): void
    {
        $event->add(
            $this->render('TheliaGiftCard/gift-card-config-js.html.twig')
        );
    }
}
