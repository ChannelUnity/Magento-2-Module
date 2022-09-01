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
use \Magento\Store\Model\StoreManagerInterface;
use \Magento\CatalogInventory\Model\Stock\StockItemRepository;
use \Magento\CatalogInventory\Api\StockRegistryInterface;
use \Camiloo\Channelunity\Model\Products;
use \Camiloo\Channelunity\Model\Helper;
use \Camiloo\Channelunity\Model\Orders;

class OrderPlacedObserver implements ObserverInterface
{
    private $helper;
    private $productModel;
    private $storeManager;
    private $stockItemRepository;
    private $stockRegistry;
    private $orderModel;
    
    public function __construct(
        Helper $helper,
        Products $product,
        StoreManagerInterface $storeManager,
        StockItemRepository $stockItemRepository,
        StockRegistryInterface $stockRegistry,
        Orders $orders
    ) {
    
        $this->helper = $helper;
        $this->productModel = $product;
        $this->storeManager = $storeManager;
        $this->stockItemRepository = $stockItemRepository;
        $this->stockRegistry = $stockRegistry;
        $this->orderModel = $orders;
    }

    public function execute(Observer $observer)
    {
        try {
            $this->helper->logInfo("Observer called: ".$observer->getEvent()->getName());
            $order = $observer->getOrder();
            if (!$order) {
                $invoice = $observer->getInvoice();
                if (is_object($invoice)) {
                    $order = $invoice->getOrder();
                }
            }
            if (!$order) {
                $creditmemo = $observer->getCreditmemo();
                if (is_object($creditmemo)) {
                    $order = $creditmemo->getOrder();
                }
            }

            if (is_object($order)) {
                $itemsOnOrder = $order->getAllItems();

                $updates = [];

                foreach ($itemsOnOrder as $item) {
                    // Send updates for these products to ChannelUnity
                    // The only thing that will have changed is the qty
                    // so may as well send a product data lite call
                    $product = $item->getProduct();
                    if (!$product) {
                        continue;
                    }

                    $productId = $product->getId();
                    $psku = $product->getData('sku');

                    $this->helper->logInfo("OrderPlacedObserver. Product ID $productId SKU $psku");

                    try {
                        $stock = $this->stockItemRepository->get($productId);
                        $pqty = $stock->getData('qty');
                    } catch (\Exception $e) {
                        $this->helper->logInfo("OrderPlacedObserver error ".$e->getMessage());
                        // Stock Item with id "****" does not exist
                        $stock = $this->stockRegistry->getStockItem($productId);
                        $pqty = $stock->getQty();
                    }

                    $updates[] = "$psku,$pqty,";
                }

                if ($updates) {
                    $updatesToSend = implode("*\n", $updates);

                    // Get the URL of the store
                    $sourceUrl = $this->helper->getBaseUrl();

                    $xml = "<Products>
                            <SourceURL>{$sourceUrl}</SourceURL>
                            <StoreViewId>0</StoreViewId>
                            <Data><![CDATA[ $updatesToSend ]]></Data>
                            </Products>";

                    // Send to ChannelUnity
                    $response = $this->helper->postToChannelUnity($xml, 'ProductDataLite');
                }

                // ------ Update order status too (will only have an
                // effect if this is a CU order) -----
                $tracksCollection = $order->getTracksCollection();
                $trackingNumbers = $this->helper->getTrackingNumbers($tracksCollection);
                
                $cuxml = $this->orderModel->generateCuXmlForOrderShip(
                    $order,
                    $trackingNumbers
                );
                if ($cuxml) {
                    $this->helper->postToChannelUnity($cuxml, 'OrderStatusUpdate');
                }

            } else {
                $this->helper->logError("!!!Order data not found!!!");
            }
        }
        catch (\Exception $e2) {
            $this->helper->logError("OrderPlacedObserver general error ".$e2->getMessage());
        }
    }
}
