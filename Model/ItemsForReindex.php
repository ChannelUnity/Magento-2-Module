<?php
/**
 * ChannelUnity connector for Magento Commerce
 *
 * @category   Camiloo
 * @package    Camiloo_Channelunity
 * @copyright  Copyright (c) 2016-2026 ChannelUnity Limited (http://www.channelunity.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Camiloo\Channelunity\Model;

/**
 * This class is provided by Magento but overridden to fix a core bug encountered
 * in older versions of Magento 2 (e.g., 2.0.7).
 * If Quote InventoryProcessed is true, an error occurs on submit()
 * Invalid argument to foreach() in ReindexQuoteInventoryObserver.php
 */
class ItemsForReindex extends \Magento\CatalogInventory\Observer\ItemsForReindex
{
    /**
     * Safely return items for reindex, ensuring it is always an array to prevent
     * fatal foreach() errors during order submission.
     * * @return array
     */
    public function getItems(): array
    {
        return is_array($this->itemsForReindex) ? $this->itemsForReindex : [];
    }
}