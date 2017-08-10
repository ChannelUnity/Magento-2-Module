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
use \Camiloo\Channelunity\Model\Products;
use \Camiloo\Channelunity\Model\Helper;
use \Camiloo\Channelunity\Model\Orders;

class OrderPlacedObserver implements ObserverInterface
{
    private $helper;
    private $productModel;
    private $storeManager;
    private $stockItemRepository;
    private $orderModel;
    
    public function __construct(
        Helper $helper,
        Products $product,
        StoreManagerInterface $storeManager,
        StockItemRepository $stockItemRepository,
        Orders $orders
    ) {
    
        $this->helper = $helper;
        $this->productModel = $product;
        $this->storeManager = $storeManager;
        $this->stockItemRepository = $stockItemRepository;
        $this->orderModel = $orders;
    }

    public function execute(Observer $observer)
    {
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
            
            foreach ($itemsOnOrder as $item) {
                // Send updates for these products to ChannelUnity
                
                $productId = $item->getProduct()->getId();
                
                // The only thing that will have changed is the qty
                // so may as well send a product data lite call
                
                $psku = $item->getProduct()->getData('sku');
                $stock = $this->stockItemRepository->get($productId);
                $pqty = $stock->getData('qty');

                // Get the URL of the store
                $sourceUrl = $this->helper->getBaseUrl();

                $xml = "<Products>
                        <SourceURL>{$sourceUrl}</SourceURL>
                        <StoreViewId>0</StoreViewId>
                        <Data><![CDATA[ $psku,$pqty, ]]></Data>
                        </Products>";

                $this->helper->logInfo($xml);
                // Send to ChannelUnity
                $response = $this->helper->postToChannelUnity($xml, 'ProductDataLite');

                $this->helper->logInfo($response);
            }
            
            // ------ Update order status too (will only have an
            // effect if this is a CU order) -----
            
            $shipments = $order->getShipmentsCollection();
            $carrierName = "";
            $shipMethod = "";
            $trackingNumber = "";
            
            if ($shipments) {
                foreach ($shipments as $shipment) {
                    $tracks = $shipment->getAllTracks();
                    foreach ($tracks as $track) {
                        $carrierName = $track->getCarrierCode();
                        if ($carrierName == "custom") {
                            $carrierName = $track->getTitle();
                        }
                        $shipMethod = $track->getTitle();
                        $trackingNumber = $track->getNumber();
                        break;
                    }
                    break;
                }
            }
            $cuxml = $this->orderModel->generateCuXmlForOrderShip(
                $order,
                $carrierName,
                $shipMethod,
                $trackingNumber
            );
            if ($cuxml) {
                $this->helper->postToChannelUnity($cuxml, 'OrderStatusUpdate');
            }
        } else {
            $this->helper->logError("!!!Order data not found!!!");
        }
    }
}
