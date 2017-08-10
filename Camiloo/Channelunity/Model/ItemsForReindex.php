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

/**
 * This class is provided by Magento but overriden to fix a bug encountered
 * in Magento 2.0.7.
 * If Quote InventoryProcessed is true, an error occurs on submit()
 * Invalid argment to foreach() in /<m2-root>/vendor/magento/
 * module-catalog-inventory/Observer/ReindexQuoteInventoryObserver.php line 71
 */
class ItemsForReindex extends \Magento\CatalogInventory\Observer\ItemsForReindex
{
    /**
     * @return array
     */
    public function getItems()
    {
        if (!$this->itemsForReindex) {          // ADDED FIX
            return [];                          // ADDED FIX
        }                                       // ADDED FIX
        return $this->itemsForReindex;
    }
}
