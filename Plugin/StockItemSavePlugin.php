<?php

/**
 * ChannelUnity connector for Magento Commerce
 *
 * @category   Camiloo
 * @package    Camiloo_Channelunity
 * @copyright  Copyright (c) 2016-2024 ChannelUnity Limited (http://www.channelunity.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Camiloo\Channelunity\Plugin;

use Camiloo\Channelunity\Model\Helper;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Registry;
use Magento\CatalogInventory\Model\Stock\Item;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Used to know when a stock item has been saved.
 * Posts events to the CU cloud when various Magento events occur.
 */
class StockItemSavePlugin
{
    /**
     * @var Helper
     */
    private $helper;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var Registry
     */
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

    /**
     * Executes after the stock item has been saved.
     * * @param Item $subject
     * @param mixed $result
     * @return mixed
     */
    public function afterAfterSave(Item $subject, $result)
    {
        $productId = $subject->getProductId();
        $qty = $subject->getQty();

        $this->helper->logInfo("Stock item PID $productId, New qty $qty");

        try {
            // Fetch product to get the SKU
            $product = $this->productRepository->getById($productId);
            $sku = $product->getSku();

            // Get the URL of the store
            $sourceUrl = $this->helper->getBaseUrl();

            $xml = "<Products>
                    <SourceURL>{$sourceUrl}</SourceURL>
                    <StoreViewId>0</StoreViewId>
                    <Data><![CDATA[ $sku,$qty, ]]></Data>
                    </Products>";

            // Send to ChannelUnity
            $this->helper->postToChannelUnity($xml, 'ProductDataLite');

            // Save the qty for the current request, in case ProductData call happens later
            $this->registry->unregister("cu_product_qty_$productId");
            $this->registry->register("cu_product_qty_$productId", $qty);

        } catch (NoSuchEntityException $e) {
            $this->helper->logError("Stock sync failed: Product ID $productId not found.");
        } catch (\Exception $e) {
            $this->helper->logError("Stock sync failed for PID $productId: " . $e->getMessage());
        }

        return $result;
    }
}