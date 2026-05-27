<?php

/**
 * ChannelUnity connector for Magento Commerce
 *
 * @category   Camiloo
 * @package    Camiloo_Channelunity
 * @copyright  Copyright (c) 2016-2024 ChannelUnity Limited (http://www.channelunity.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Camiloo\Channelunity\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Camiloo\Channelunity\Helper\Data;
use Camiloo\Channelunity\Model\Products;
use Magento\Framework\Registry;
use Magento\Store\Model\Store;

/**
 * ChannelUnity observers.
 * Posts events to the CU cloud when various Magento events occur.
 */
class SaveProductObserver implements ObserverInterface
{
    /**
     * Track processed IDs to prevent duplicate syncs in a single request
     * @var array
     */
    private static $processedIds = [];

    private $helper;
    private $productModel;
    private $storeManager;
    private $productRepository;
    private $request;
    private $registry;

    public function __construct(
        Helper $helper,
        Products $product,
        StoreManagerInterface $storeManager,
        ProductRepositoryInterface $productRepository,
        RequestInterface $request,
        Registry $registry
    ) {
        $this->helper = $helper;
        $this->productModel = $product;
        $this->storeManager = $storeManager;
        $this->productRepository = $productRepository;
        $this->request = $request;
        $this->registry = $registry;
    }

    public function execute(Observer $observer)
    {
        $this->helper->logInfo("Observer called: Save Product");

        // If it's a mass attribute update, we stored the product IDs in the registry
        $productsInRegistry = $this->registry->registry('attribute_pids');

        // If it's a mass enable/disable, we can get the ids from $_REQUEST
        $selected = $this->request->getParam('selected');

        $productIds = [];

        if ($productsInRegistry) {
            $this->helper->logInfo("Found Ids in registry, defer saving until later via Bulk Update.");
            $this->registry->unregister('attribute_pids');

            // Exit early since the BulkProductSync command handles this
            return;
        } elseif ($selected && is_array($selected)) {
            $this->helper->logInfo("Found Ids in request (mass action)");
            $productIds = $selected;
        } else {
            // Correctly fetch the product from the event
            $product = $observer->getEvent()->getProduct();

            if (!is_object($product) || !$product->getId()) {
                $productInRequest = $this->request->getParam('product');
                $sku = $productInRequest['sku'] ?? null;

                if (!$sku) {
                    return; // Nothing to process
                }

                try {
                    $product = $this->productRepository->get($sku);
                    $this->helper->logInfo("Got product model from sku in request");
                } catch (\Exception $e) {
                    $this->helper->logError("Could not load product by SKU from request: " . $e->getMessage());
                    return;
                }
            }

            if (is_object($product) && $product->getId()) {
                $productIds = [$product->getId()];
                $this->helper->logInfo("Got Ids from product model");
            }
        }

        if (empty($productIds)) {
            $this->helper->logInfo("Save Product Observer couldn't find product ids");
            return;
        }

        foreach ($productIds as $productId) {
            // Prevent duplicate API calls if the observer fires multiple times
            if (in_array($productId, self::$processedIds)) {
                $this->helper->logInfo("Product $productId already processed in this request. Skipping.");
                continue;
            }
            self::$processedIds[] = $productId;

            $this->helper->logInfo("SaveProductObserver- ProductId:$productId");

            try {
                // Default store view
                $this->helper->logInfo("SaveProductObserver- XML default store");
                $data = $this->productModel->generateCuXmlForSingleProduct($productId, Store::DEFAULT_STORE_ID);
                $this->helper->updateProductData(Store::DEFAULT_STORE_ID, $data);

                // Loop through store views
                $websites = $this->storeManager->getWebsites();
                foreach ($websites as $website) {
                    $stores = $website->getStores();
                    foreach ($stores as $storeView) {
                        $storeId = $storeView->getId();

                        $this->helper->logInfo("SaveProductObserver- storeId: $storeId");
                        $data = $this->productModel->generateCuXmlForSingleProduct($productId, $storeId);
                        $this->helper->logInfo("SaveProductObserver- XML generated");

                        // Send to CU
                        $this->helper->updateProductData($storeId, $data);
                    }
                }
            } catch (\Exception $e) {
                // Ensure product saves gracefully even if API sync fails
                $this->helper->logError("Failed to sync product ID $productId to ChannelUnity: " . $e->getMessage());
            }
        }
    }
}