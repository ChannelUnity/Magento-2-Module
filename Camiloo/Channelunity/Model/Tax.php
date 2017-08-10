<?php
/**
 * ChannelUnity connector for Magento Commerce
 *
 * @category   Camiloo
 * @package    Camiloo_Channelunity
 * @copyright  Copyright (c) 2016-2017 ChannelUnity Limited (http://www.channelunity.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Camiloo\Channelunity\Model;

use Magento\Framework\Registry;

/**
 * To force in our own tax values to imported orders.
 */
class Tax extends \Magento\Tax\Model\Sales\Total\Quote\Tax
{
    
    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $scopeConfig;
    /**
     * @var \Magento\Framework\Pricing\PriceCurrencyInterface
     */
    private $priceCurrency;
    /**
     * @var \Magento\Tax\Api\Data\QuoteDetailsItemExtensionFactory
     */
    private $extensionFactory;
    
    private $helper;
    
    public function __construct(
        \Magento\Tax\Model\Config $taxConfig,
        \Magento\Tax\Api\TaxCalculationInterface $taxCalculationService,
        \Magento\Tax\Api\Data\QuoteDetailsInterfaceFactory $quoteDetailsDataObjectFactory,
        \Magento\Tax\Api\Data\QuoteDetailsItemInterfaceFactory $quoteDetailsItemDataObjectFactory,
        \Magento\Tax\Api\Data\TaxClassKeyInterfaceFactory $taxClassKeyDataObjectFactory,
        \Magento\Customer\Api\Data\AddressInterfaceFactory $customerAddressFactory,
        \Magento\Customer\Api\Data\RegionInterfaceFactory $customerAddressRegionFactory,
        \Magento\Tax\Helper\Data $taxData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\Pricing\PriceCurrencyInterface $priceCurrency,
        \Magento\Tax\Api\Data\QuoteDetailsItemExtensionFactory $extensionFactory,
        Helper $helper,
        Registry $registry
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->priceCurrency = $priceCurrency;
        $this->extensionFactory = $extensionFactory;
        $this->helper = $helper;
        $this->registry = $registry;
        
        parent::__construct(
            $taxConfig,
            $taxCalculationService,
            $quoteDetailsDataObjectFactory,
            $quoteDetailsItemDataObjectFactory,
            $taxClassKeyDataObjectFactory,
            $customerAddressFactory,
            $customerAddressRegionFactory,
            $taxData
        );
    }
    
    /**
     * Kept for future use incase we need to debug some tax related things.
     * @param type $str
     * @param type $taxObject
     */
    private function logItemTaxDetails($str, $taxObject)
    {
        foreach ($taxObject->getData() as $k => $v) {
            if (is_array($v)) {
                $this->helper->logInfo("$str: $k -> ");
                foreach ($v as $av) {
                    if (is_object($av)) {
                        $this->logItemTaxDetails2($av);
                    } else {
                        $this->helper->logInfo("\t\t\t$av");
                    }
                }
            } elseif (is_object($v)) {
                $v = $v->getData();
                $v = implode(", ", $v);
                $this->helper->logInfo("$str: $k -> $v");
            } else {
                $this->helper->logInfo("$str: $k -> $v");
            }
        }
    }
    
    private function logItemTaxDetails2($av)
    {
        $val = $av->getData();
        foreach ($val as $kk => $vv) {
            if (is_array($vv)) {
                $this->helper->logInfo("\t\t\t$kk => ");

                foreach ($vv as $kk2 => $vv2) {
                    if (is_array($vv2)) {
                        $this->helper->logInfo("\t\t\t\t\t$kk2 => (ARRAY)");
                    } elseif (is_object($vv2)) {
                        $this->helper->logInfo("\t\t\t\t\t$kk2 => (OBJECT ".  get_class($vv2).")");
                    } else {
                        $this->helper->logInfo("\t\t\t\t\t$kk2 => $vv2");
                    }
                }
            } elseif (is_object($vv)) {
                $this->helper->logInfo("\t\t\t$kk => (OBJECT)");
            } else {
                $this->helper->logInfo("\t\t\t$kk => $vv");
            }
        }
    }
    
    /**
     * @Override
     * @param type $quoteItem
     * @param type $itemTaxDetails
     * @param type $baseItemTaxDetails
     * @param type $store
     */
    public function updateItemTaxInfo($quoteItem, $itemTaxDetails, $baseItemTaxDetails, $store)
    {
        $cuOrderCheck = $this->registry->registry('cu_order_in_progress');
        
        if ($this->helper->forceTaxValues() && $cuOrderCheck == 1) {
            $this->logItemTaxDetails("Before updateItemTaxInfo", $itemTaxDetails);
            // Use getCode() to work out which item we are on
            $cuLineItem = $this->registry->registry('cu_'.$itemTaxDetails->getCode());
            
            if (!is_object($cuLineItem)) {
                $this->helper->logInfo("Warning: No line tax details for ".$itemTaxDetails->getCode());
            }
            else {

                $perItemTaxRequired = (float) $cuLineItem->Tax; // In the source currency
                $qtyOfItem = (int) $cuLineItem->Quantity;

                $itemTaxDetails->setRowTax($perItemTaxRequired * $qtyOfItem);
                $itemTaxDetails->setPriceInclTax(
                    $itemTaxDetails->getPrice() + $perItemTaxRequired
                );
                $itemTaxDetails->setRowTotalInclTax(
                    $itemTaxDetails->getRowTotal() + $itemTaxDetails->getRowTax()
                );
                $itemTaxDetails->setTaxPercent(
                    ($itemTaxDetails->getRowTotalInclTax()/$itemTaxDetails->getRowTotal()-1)*100
                );
                $arrayOfTaxes = [] ;//Magento\Tax\Api\Data\AppliedTaxInterface
                $itemTaxDetails->setAppliedTaxes($arrayOfTaxes);

                $this->logItemTaxDetails("After updateItemTaxInfo", $itemTaxDetails);

                // -------------------------------------------------------------------
                $storeToBaseConversionRate = $this->registry->registry('cu_conversion_rate');

                $baseItemTaxDetails->setRowTax(
                    $perItemTaxRequired * $qtyOfItem / $storeToBaseConversionRate
                );
                $baseItemTaxDetails->setPriceInclTax(
                    $baseItemTaxDetails->getPrice() + $perItemTaxRequired/$storeToBaseConversionRate
                );
                $baseItemTaxDetails->setRowTotalInclTax(
                    $baseItemTaxDetails->getRowTotal() + $baseItemTaxDetails->getRowTax()
                );
                $baseItemTaxDetails->setTaxPercent(
                    ($baseItemTaxDetails->getRowTotalInclTax()/$baseItemTaxDetails->getRowTotal()-1)*100
                );
                $baseItemTaxDetails->setAppliedTaxes($arrayOfTaxes);

                $this->logItemTaxDetails("After updateItemTaxInfo (Base)", $baseItemTaxDetails);
            }
        }
        parent::updateItemTaxInfo($quoteItem, $itemTaxDetails, $baseItemTaxDetails, $store);
        
        return $this;
    }
    
    protected function processShippingTaxInfo(
        \Magento\Quote\Api\Data\ShippingAssignmentInterface $shippingAssignment,
        \Magento\Quote\Model\Quote\Address\Total $total,
        $shippingTaxDetails,
        $baseShippingTaxDetails
    ) {
        $cuOrderCheck = $this->registry->registry('cu_order_in_progress');
        
        if ($this->helper->forceTaxValues() && $shippingTaxDetails->getRowTotal() > 0 && $cuOrderCheck == 1) {
            $shippingTaxRequired = $this->registry->registry('cu_shipping_tax');
            $storeToBaseConversionRate = $this->registry->registry('cu_conversion_rate');

            $shippingTaxDetails->setRowTax($shippingTaxRequired);
            $shippingTaxDetails->setPriceInclTax(
                $shippingTaxDetails->getPrice() + $shippingTaxRequired
            );
            $shippingTaxDetails->setRowTotalInclTax(
                $shippingTaxDetails->getRowTotal() + $shippingTaxDetails->getRowTax()
            );
            $shippingTaxDetails->setTaxPercent(
                ($shippingTaxDetails->getRowTotalInclTax()/$shippingTaxDetails->getRowTotal()-1)*100
            );
            $arrayOfTaxes = [] ;//Magento\Tax\Api\Data\AppliedTaxInterface
            $shippingTaxDetails->setAppliedTaxes($arrayOfTaxes);

            $this->logItemTaxDetails("Before processShippingTaxInfo", $shippingTaxDetails);

            $baseShippingTaxDetails->setRowTax($shippingTaxRequired / $storeToBaseConversionRate);
            $baseShippingTaxDetails->setPriceInclTax(
                $baseShippingTaxDetails->getPrice() + $shippingTaxRequired/$storeToBaseConversionRate
            );
            $baseShippingTaxDetails->setRowTotalInclTax(
                $baseShippingTaxDetails->getRowTotal() + $baseShippingTaxDetails->getRowTax()
            );
            $baseShippingTaxDetails->setTaxPercent(
                ($baseShippingTaxDetails->getRowTotalInclTax()/$baseShippingTaxDetails->getRowTotal()-1)*100
            );
            $baseShippingTaxDetails->setAppliedTaxes($arrayOfTaxes);
            $this->logItemTaxDetails("Before processShippingTaxInfo (Base)", $baseShippingTaxDetails);
        }
        parent::processShippingTaxInfo(
            $shippingAssignment,
            $total,
            $shippingTaxDetails,
            $baseShippingTaxDetails
        );
        
        return $this;
    }
}
