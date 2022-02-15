<?php

namespace TheliaGiftCard\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Thelia\Core\Security\SecurityContext;
use Thelia\Tools\URL;
use TheliaGiftCard\Service\GiftCardService;

class RequestListener implements EventSubscriberInterface
{
    /** @var SecurityContext  */
    protected $securityContext;
    /**
     * @var GiftCardService
     */
    private $giftCardService;

    public function __construct(GiftCardService $giftCardService)
    {
        $this->giftCardService = $giftCardService;
    }

    public function resetGiftCard(RequestEvent $event)
    {
        $request = $event->getRequest();

        $allowedRouteToReset =
            [
                "order-delivery",
            ];

        //TODO: pouvoir ajouter des routes avec module config

        foreach ($allowedRouteToReset as $route)
        {
            if (preg_match("/^.*\/$route$/", $request->getRequestUri()))
            {
                $this->giftCardService->reset();
            }
        }
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::REQUEST => ['resetGiftCard', 256],
        ];
    }
}