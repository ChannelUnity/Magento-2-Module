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

namespace Camiloo\Channelunity\Observer;

use \Magento\Framework\Event\ObserverInterface;
use \Magento\Framework\Event\Observer;
use \Magento\Framework\App\RequestInterface;
use \Magento\Store\Model\StoreManagerInterface;
use \Magento\Catalog\Api\ProductRepositoryInterface;
use \Camiloo\Channelunity\Model\Helper;
use \Camiloo\Channelunity\Model\Products;
use \Magento\Framework\Registry;

class SaveProductObserver implements ObserverInterface
{
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

        //If it's a mass attribute update, we stored the product IDs in the registry
        $productsInRegistry = $this->registry->registry('attribute_pids');
        
        // If it's a mass enable/disable, we can get the ids from $_REQUEST
        $selected = $this->request->getParam('selected');

        if ($productsInRegistry) {
            $this->helper->logInfo("Found Ids in registry:".print_r($productsInRegistry, true));
            $productIds = $productsInRegistry;
            $this->registry->unregister('attribute_pids');
        } elseif ($selected) {
            $this->helper->logInfo("Found Ids in request:".print_r($selected, true));

            $productInRequest = $this->request->getParam('product');
            $productIds = $selected;
        } else {
            $product = $observer->getProduct();
            
            // Child products don't return a product model??
            // Use SKU in request to get the product
            if (!is_object($product)) {
                $productInRequest = $this->request->getParam('product');
                $sku = $productInRequest['sku'];
                $product = $this->productRepository->get($sku);
                $this->helper->logInfo("Got product model from sku in request:".print_r($sku, true));
            }

            if (is_object($product)) {
                $productIds = [$product->getId()];
                $this->helper->logInfo("Got Ids from product model:".print_r($productIds, true));
            }
        }

        if (!is_array($productIds)) {
            $this->helper->logInfo("Save Product Observer couldn't find product ids");
            return false;
        }
        
        foreach ($productIds as $productId) {
            $this->helper->logInfo("SaveProductObserver- ProductId:$productId");

            // Default store view
            $this->helper->logInfo("SaveProductObserver- XML default store");
            $data = $this->productModel->generateCuXmlForSingleProduct($productId, 0);
            // Send to CU
            $this->helper->updateProductData(0, $data);

            // Loop through store views
            $websites = $this->storeManager->getWebsites();
            foreach ($websites as $website) {
                $stores = $website->getStores();
                foreach ($stores as $storeView) {
                    $storeId = $storeView->getData('store_id');
                    $this->helper->logInfo("SaveProductObserver- storeId: $storeId");
                    $data = $this->productModel->generateCuXmlForSingleProduct($productId, $storeId);
                    $this->helper->logInfo("SaveProductObserver- XML generated");
                    // Send to CU
                    $this->helper->updateProductData($storeId, $data);
                }
            }
        }
    }
}
