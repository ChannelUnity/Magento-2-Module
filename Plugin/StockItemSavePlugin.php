<?php

/**
 * ChannelUnity connector for Magento Commerce
 *
 * @category   Camiloo
 * @package    Camiloo_Channelunity
 * @copyright  Copyright (c) 2016 ChannelUnity Limited (http://www.channelunity.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * ChannelUnity observers.
 * Posts events to the CU cloud when various Magento events occur.
 */

namespace Camiloo\Channelunity\Plugin;

use \Camiloo\Channelunity\Model\Helper;
use \Magento\Catalog\Api\ProductRepositoryInterface;
use \Magento\Framework\Registry;

/**
 * Used to know when a stock item has been saved.
 */
class StockItemSavePlugin
{
    private $helper;
    private $productRepository;
    private $registry;
    
    public function __construct(
        Helper $helper,
        ProductRepositoryInterface $productRepository,
        Registry $registry
    ) {
        $this->helper = $helper;
        $this->productRepository = $productRepository;
        $this->registry = $registry;
    }
    
    public function aroundAfterSave(\Magento\CatalogInventory\Model\Stock\Item $item, callable $proceed)
    {
        $result = $proceed();
        $productId = $item->getData('product_id');
        $qty = $item->getData('qty');
        
        $this->helper->logInfo("Stock item PID $productId, New qty $qty");
        
        $product = $this->productRepository->getById($productId);
        $sku = $product->getData('sku');
        
        // Get the URL of the store
        $sourceUrl = $this->helper->getBaseUrl();

        $xml = "<Products>
                <SourceURL>{$sourceUrl}</SourceURL>
                <StoreViewId>0</StoreViewId>
                <Data><![CDATA[ $sku,$qty, ]]></Data>
                </Products>";

        // Send to ChannelUnity
        $this->helper->postToChannelUnity($xml, 'ProductDataLite');
        
        // Save the qty for the current request, incase ProductData call happens later
        $this->registry->unregister("cu_product_qty_$productId");
        $this->registry->register("cu_product_qty_$productId", $qty);

        return $result;
    }
}
