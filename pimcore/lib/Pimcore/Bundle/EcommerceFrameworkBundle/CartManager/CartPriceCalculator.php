<?php

declare(strict_types=1);

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PEL
 */

namespace Pimcore\Bundle\EcommerceFrameworkBundle\CartManager;

use Pimcore\Bundle\EcommerceFrameworkBundle\CartManager\CartPriceModificator\ICartPriceModificator;
use Pimcore\Bundle\EcommerceFrameworkBundle\Exception\UnsupportedException;
use Pimcore\Bundle\EcommerceFrameworkBundle\Factory;
use Pimcore\Bundle\EcommerceFrameworkBundle\Model\Currency;
use Pimcore\Bundle\EcommerceFrameworkBundle\PriceSystem\IModificatedPrice;
use Pimcore\Bundle\EcommerceFrameworkBundle\PriceSystem\IPrice;
use Pimcore\Bundle\EcommerceFrameworkBundle\PriceSystem\Price;
use Pimcore\Bundle\EcommerceFrameworkBundle\PriceSystem\TaxManagement\TaxEntry;

class CartPriceCalculator implements ICartPriceCalculator
{
    /**
     * @var bool
     */
    protected $isCalculated = false;

    /**
     * @var IPrice
     */
    protected $subTotal;

    /**
     * @var IPrice
     */
    protected $grandTotal;

    /**
     * @var ICartPriceModificator[]
     */
    protected $modificators;

    /**
     * @var IModificatedPrice[]
     */
    protected $modifications;

    /**
     * @var ICart
     */
    protected $cart;

    /**
     * @param $config
     * @param ICart $cart
     */
    public function __construct($config, ICart $cart)
    {
        $this->modificators = [];
        if (!empty($config->modificators) && is_object($config->modificators)) {
            foreach ($config->modificators as $modificator) {
                $modificatorClass = new $modificator->class($modificator->config);
                $this->addModificator($modificatorClass);
            }
        }

        $this->cart = $cart;
        $this->isCalculated = false;
    }

    /**
     * @throws UnsupportedException
     */
    public function calculate()
    {

        //sum up all item prices
        $subTotalNet = 0;
        $subTotalGross = 0;
        $currency = null;

        /**
         * @var $subTotalTaxes TaxEntry[]
         * @var $grandTotalTaxes TaxEntry[]
         */
        $subTotalTaxes = [];
        $grandTotalTaxes = [];

        foreach ($this->cart->getItems() as $item) {
            if (is_object($item->getPrice())) {
                if (!$currency) {
                    $currency = $item->getPrice()->getCurrency();
                }

                if ($currency->getShortName() != $item->getPrice()->getCurrency()->getShortName()) {
                    throw new UnsupportedException('Different currencies within one cart are not supported. See cart ' . $this->cart->getId() . ' and product ' . $item->getProduct()->getId() . ')');
                }

                $subTotalNet += $item->getTotalPrice()->getNetAmount();
                $subTotalGross += $item->getTotalPrice()->getGrossAmount();

                $taxEntries = $item->getTotalPrice()->getTaxEntries();
                foreach ($taxEntries as $taxEntry) {
                    $taxId = $taxEntry->getTaxId();
                    if (empty($subTotalTaxes[$taxId])) {
                        $subTotalTaxes[$taxId] = clone $taxEntry;
                        $grandTotalTaxes[$taxId] = clone $taxEntry;
                    } else {
                        $subTotalTaxes[$taxId]->setAmount($subTotalTaxes[$taxId]->getAmount() + $taxEntry->getAmount());
                        $grandTotalTaxes[$taxId]->setAmount($grandTotalTaxes[$taxId]->getAmount() + $taxEntry->getAmount());
                    }
                }
            }
        }

        //by default currency is retrieved from item prices. if there are no items, its loaded from the default locale defined in the environment
        if (!$currency) {
            $currency = $this->getDefaultCurrency();
        }

        //populate subTotal price, set net and gross amount, set tax entries and set tax entry combination mode to fixed
        $this->subTotal = $this->getDefaultPriceObject($subTotalGross, $currency);
        $this->subTotal->setNetAmount($subTotalNet);
        $this->subTotal->setTaxEntries($subTotalTaxes);
        $this->subTotal->setTaxEntryCombinationMode(TaxEntry::CALCULATION_MODE_FIXED);

        //consider all price modificators
        $currentSubTotal = $this->getDefaultPriceObject($subTotalGross, $currency);
        $currentSubTotal->setNetAmount($subTotalNet);
        $currentSubTotal->setTaxEntryCombinationMode(TaxEntry::CALCULATION_MODE_FIXED);

        $this->modifications = [];
        foreach ($this->getModificators() as $modificator) {
            /* @var \Pimcore\Bundle\EcommerceFrameworkBundle\CartManager\CartPriceModificator\ICartPriceModificator $modificator */
            $modification = $modificator->modify($currentSubTotal, $this->cart);
            if ($modification !== null) {
                $this->modifications[$modificator->getName()] = $modification;
                $currentSubTotal->setNetAmount($currentSubTotal->getNetAmount() + $modification->getNetAmount());
                $currentSubTotal->setGrossAmount($currentSubTotal->getGrossAmount() + $modification->getGrossAmount());

                $taxEntries = $modification->getTaxEntries();
                foreach ($taxEntries as $taxEntry) {
                    $taxId = $taxEntry->getTaxId();
                    if (empty($grandTotalTaxes[$taxId])) {
                        $grandTotalTaxes[$taxId] = clone $taxEntry;
                    } else {
                        $grandTotalTaxes[$taxId]->setAmount($grandTotalTaxes[$taxId]->getAmount() + $taxEntry->getAmount());
                    }
                }
            }
        }

        $currentSubTotal->setTaxEntries($grandTotalTaxes);

        $this->grandTotal   = $currentSubTotal;
        $this->isCalculated = true;
    }

    /**
     * gets default currency object based on the default currency locale defined in the environment
     *
     * @return Currency
     */
    protected function getDefaultCurrency()
    {
        return Factory::getInstance()->getEnvironment()->getDefaultCurrency();
    }

    /**
     * Possibility to overwrite the price object that should be used
     *
     * @param $amount
     * @param Currency $currency
     *
     * @return IPrice
     */
    protected function getDefaultPriceObject(PriceAmount $amount, Currency $currency): IPrice
    {
        return new Price($amount, $currency);
    }

    /**
     * @return IPrice $price
     */
    public function getGrandTotal(): IPrice
    {
        if (!$this->isCalculated) {
            $this->calculate();
        }

        return $this->grandTotal;
    }

    /**
     * @return IModificatedPrice[] $priceModification
     */
    public function getPriceModifications(): array
    {
        if (!$this->isCalculated) {
            $this->calculate();
        }

        return $this->modifications;
    }

    /**
     * @return IPrice $price
     */
    public function getSubTotal(): IPrice
    {
        if (!$this->isCalculated) {
            $this->calculate();
        }

        return $this->subTotal;
    }

    /**
     * @return void
     */
    public function reset()
    {
        $this->isCalculated = false;
    }

    /**
     * @param ICartPriceModificator $modificator
     *
     * @return ICartPriceCalculator
     */
    public function addModificator(ICartPriceModificator $modificator)
    {
        $this->reset();
        $this->modificators[] = $modificator;

        return $this;
    }

    /**
     * @return ICartPriceModificator[]
     */
    public function getModificators(): array
    {
        return $this->modificators;
    }

    /**
     * @param ICartPriceModificator $modificator
     *
     * @return ICartPriceCalculator
     */
    public function removeModificator(ICartPriceModificator $modificator)
    {
        foreach ($this->modificators as $key => $mod) {
            if ($mod === $modificator) {
                unset($this->modificators[$key]);
            }
        }

        return $this;
    }
}
