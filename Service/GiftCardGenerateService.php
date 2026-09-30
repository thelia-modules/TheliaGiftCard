<?php

declare(strict_types=1);

namespace TheliaGiftCard\Service;

use Propel\Runtime\Exception\PropelException;
use Thelia\Model\Base\ProductSaleElementsQuery;
use Thelia\Model\FeatureAvI18nQuery;
use Thelia\Model\FeatureProductQuery;
use Thelia\Model\Lang;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;
use TheliaGiftCard\Model\Base\GiftCardInfoCartQuery;
use TheliaGiftCard\Model\GiftCard;
use TheliaGiftCard\Model\GiftCardQuery;
use TheliaGiftCard\TheliaGiftCard;

class GiftCardGenerateService
{
    public function __construct(
        protected GiftCardCodeGenerator $codeGenerator,
    ) {
    }

    /**
     * @throws PropelException
     */
    public function generateGifcard(Order $order): void
    {
        // Comptage du nombre de carte cadeau à créer par produit
        $countMaxbyAmountByProduct = $this->getCountGiftCards($order);

        /** @var OrderProduct $orderProduct */
        foreach ($order->getOrderProducts() as $orderProduct) {
            $pse = ProductSaleElementsQuery::create()->findPk($orderProduct->getProductSaleElementsId());
            $productId = $pse->getProduct()->getId();
            $tabProductGiftCard = TheliaGiftCard::getGiftCardProductList();

            // Si l'orderProduct correspond à une carte cadeau
            if (\in_array($productId, $tabProductGiftCard)) {
                $orderId = $order->getId();
                $price = $orderProduct->getPrice();
                $TaxAmount = 0;

                $orderProductTaxes = $orderProduct->getOrderProductTaxes()->getData();

                foreach ($orderProductTaxes as $orderProductTax) {
                    $TaxAmount = $orderProductTax->getAmount();
                }

                for ($i = 1; $i <= $orderProduct->getQuantity(); ++$i) {
                    $expirationDate = new \DateTime('now');
                    $expirationDate = $expirationDate->add(new \DateInterval('P1Y'));
                    $newGiftCard = null;

                    $giftCards = GiftCardQuery::create()
                        ->filterByOrderId($order->getId())
                        ->filterByProductId($productId)
                        ->find();

                    if ($giftCards->count() < $countMaxbyAmountByProduct[$productId]) {
                        // Création de carte cadeaux
                        $amount = (float) $price + (float) $TaxAmount;

                        // Forcer montant de la carte cadeau si besoin (montant de la feature)
                        $featureAmount = FeatureProductQuery::create()
                            ->filterByProductId($productId)
                            ->filterByFreeTextValue(1)
                            ->findOne();

                        if (null !== $featureAmount) {
                            $forcedAmount = $this->forcedAmount($featureAmount->getFeatureAvId(), $order);

                            if ($forcedAmount > 0) {
                                $amount = $forcedAmount;
                            }
                        }

                        // Si auto envoi par email le status est donc activé
                        $status = TheliaGiftCard::isAutoSendEmail() ? 1 : 0;

                        $newGiftCard = new GiftCard();
                        $newGiftCard
                            ->setProductId($productId)
                            ->setSponsorCustomerId($order->getCustomer()->getId())
                            ->setOrderId($orderId)
                            ->setCode($this->codeGenerator->generate())
                            ->setAmount((string) $amount)
                            ->setSpendAmount('0')
                            ->setExpirationDate($expirationDate)
                            ->setStatus($status)
                            ->save();
                    }

                    if (null != $newGiftCard) {
                        $giftCardInfo = GiftCardInfoCartQuery::create()
                            ->filterByOrderProductId($orderProduct->getId())
                            ->findOne();

                        $giftCardInfo
                            ?->setGiftCardId($newGiftCard->getId())
                            ->save();
                    }
                }
            }
        }
    }

    /**
     * @throws PropelException
     */
    public function getCountGiftCards(Order $order): array
    {
        $cpt = [];

        /** @var OrderProduct $orderProduct */
        foreach ($order->getOrderProducts() as $orderProduct) {
            $pse = ProductSaleElementsQuery::create()->findPk($orderProduct->getProductSaleElementsId());
            $productId = $pse->getProduct()->getId();

            // retourne la liste des id produits correspondant à une carte cadeau
            $tabProductsGiftCard = TheliaGiftCard::getGiftCardProductList();

            // Determiner le nombre de produit correspondant à une carte cadeau dans la commande initiale
            if (\in_array($productId, $tabProductsGiftCard)) {
                if (isset($cpt[$productId])) {
                    $cpt[$productId] += $orderProduct->getQuantity();
                    continue;
                }

                $cpt[$productId] = $orderProduct->getQuantity();
            }
        }

        return $cpt;
    }

    /**
     * The amount is a number typed as the title of the feature value. It is read in the language
     * of the order, then in the default language, then in the first language that has one: a
     * title missing in one language must not turn the card into a card of 0.
     */
    private function forcedAmount(?int $featureAvId, Order $order): float
    {
        if (null === $featureAvId) {
            return 0.0;
        }

        $titles = [];
        foreach (FeatureAvI18nQuery::create()->filterById($featureAvId)->find() as $translation) {
            $titles[$translation->getLocale()] = trim((string) $translation->getTitle());
        }

        $preferred = [$this->orderLocale($order), Lang::getDefaultLanguage()->getLocale()];
        $inPreferredLanguages = array_map(static fn (string $locale): string => $titles[$locale] ?? '', $preferred);

        foreach ([...$inPreferredLanguages, ...array_values($titles)] as $title) {
            if ('' !== $title) {
                return (float) str_replace(',', '.', $title);
            }
        }

        return 0.0;
    }

    private function orderLocale(Order $order): string
    {
        return $order->getLang()?->getLocale() ?? Lang::getDefaultLanguage()->getLocale();
    }
}
