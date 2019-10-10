<?php

namespace Camiloo\Channelunity\Plugin;

use \Camiloo\Channelunity\Model\Helper;

/**
 * There is a setting in Magento (Configuration -> Catalog -> Inventory)
 * called "Display Out of Stock Products" which filters out Out of Stock
 * products. This was also affecting this plugin. Therefore we override
 * the setting to always be false (i.e. include both in and out of stock
 * products).
 */
class StockStatusPlugin
{
    private $helper;
    
    public function __construct(
        Helper $helper
    ) {
        $this->helper = $helper;
    }
    
    /**
     * @param \Magento\Catalog\Model\ResourceModel\Product\Collection $collection
     * @param bool $isFilterInStock
     * @return \Magento\Catalog\Model\ResourceModel\Product\Collection $collection
     */

    public function aroundAddStockDataToCollection($targetObj, $proceed, $collection, $isinstock)
    {
        return $proceed($collection, false);
    }
}
