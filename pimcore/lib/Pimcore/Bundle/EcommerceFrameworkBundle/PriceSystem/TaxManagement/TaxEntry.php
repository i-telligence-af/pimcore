<?php
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

namespace Pimcore\Bundle\EcommerceFrameworkBundle\PriceSystem\TaxManagement;

use Pimcore\Bundle\EcommerceFrameworkBundle\PriceSystem\Value\PriceAmount;
use Pimcore\Model\Object\Fieldcollection\Data\TaxEntry as TaxEntryFieldcollection;
use Pimcore\Model\Object\OnlineShopTaxClass;

class TaxEntry
{
    const CALCULATION_MODE_COMBINE = 'combine';
    const CALCULATION_MODE_ONE_AFTER_ANOTHER = 'oneAfterAnother';
    const CALCULATION_MODE_FIXED = 'fixed';

    /**
     * @var TaxEntryFieldcollection
     */
    protected $entry;

    /**
     * @var float
     */
    protected $percent;

    /**
     * @var PriceAmount
     */
    protected $amount;

    /**
     * @var string
     */
    protected $taxId;

    /**
     * @param $percent
     * @param PriceAmount $amount
     * @param string|null $taxId
     * @param TaxEntryFieldcollection|null $entry
     */
    public function __construct($percent, PriceAmount $amount, string $taxId = null, TaxEntryFieldcollection $entry = null)
    {
        $this->percent = $percent;
        $this->amount = $amount;
        $this->taxId = $taxId;
        $this->entry = $entry;
    }

    /**
     * @return float
     */
    public function getPercent()
    {
        return $this->percent;
    }

    /**
     * @param float $percent
     */
    public function setPercent($percent)
    {
        $this->percent = $percent;
    }

    /**
     * @param TaxEntryFieldcollection $entry
     */
    public function setEntry(TaxEntryFieldcollection $entry)
    {
        $this->entry = $entry;
    }

    /**
     * @return TaxEntryFieldcollection
     */
    public function getEntry(): TaxEntryFieldcollection
    {
        return $this->entry;
    }

    /**
     * @return PriceAmount
     */
    public function getAmount(): PriceAmount
    {
        return $this->amount;
    }

    /**
     * @param PriceAmount $amount
     */
    public function setAmount(PriceAmount $amount)
    {
        $this->amount = $amount;
    }

    /**
     * @return string
     */
    public function getTaxId()
    {
        return $this->taxId;
    }

    /**
     * @param string $taxId
     */
    public function setTaxId(string $taxId = null)
    {
        $this->taxId = $taxId;
    }

    /**
     * Converts tax rate configuration of given OnlineShopTaxClass to TaxEntries that can be used for
     * tax calculation.
     *
     * @param OnlineShopTaxClass $taxClass
     *
     * @return TaxEntry[]
     */
    public static function convertTaxEntries(OnlineShopTaxClass $taxClass)
    {
        $convertedTaxEntries = [];
        if ($taxClass->getTaxEntries()) {
            foreach ($taxClass->getTaxEntries() as $index => $entry) {
                $convertedTaxEntries[] = new static($entry->getPercent(), PriceAmount::create(0), $entry->getName() . '-' . $entry->getPercent(), $entry);
            }
        }

        return $convertedTaxEntries;
    }
}
