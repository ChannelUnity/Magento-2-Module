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
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Camiloo\Channelunity\Model\Helper;
use Camiloo\Channelunity\Model\Orders;

/**
 * ChannelUnity observers.
 * Posts events to the CU cloud when various Magento events occur.
 */
class OrderPlacedObserver implements ObserverInterface
{
    /**
     * @var Helper
     */
    private $helper;

    /**
     * @var StockRegistryInterface
     */
    private $stockRegistry;

    /**
     * @var Orders
     */
    private $orderModel;

    public function __construct(
        Helper $helper,
        StockRegistryInterface $stockRegistry,
        Orders $orders
    ) {
        $this->helper = $helper;
        $this->stockRegistry = $stockRegistry;
        $this->orderModel = $orders;
    }

    public function execute(Observer $observer)
    {
        try {
            $event = $observer->getEvent();
            $this->helper->logInfo("Observer called: " . $event->getName());

            // Extract the order safely depending on the event trigger
            $order = $event->getOrder();
            if (!$order) {
                $invoice = $event->getInvoice();
                if ($invoice && $invoice->getOrder()) {
                    $order = $invoice->getOrder();
                }
            }
            if (!$order) {
                $creditmemo = $event->getCreditmemo();
                if ($creditmemo && $creditmemo->getOrder()) {
                    $order = $creditmemo->getOrder();
                }
            }

            if ($order && $order->getId()) {
                $itemsOnOrder = $order->getAllItems();
                $updates = [];

                foreach ($itemsOnOrder as $item) {
                    $product = $item->getProduct();

                    // Skip if product doesn't exist or doesn't have an ID
                    if (!$product || !$product->getId()) {
                        continue;
                    }

                    $productId = $product->getId();
                    $psku = $product->getSku();

                    $this->helper->logInfo("OrderPlacedObserver. Product ID $productId SKU $psku");

                    try {
                        // StockRegistryInterface is the correct way to get stock in M2.1+
                        $stock = $this->stockRegistry->getStockItem($productId);
                        $pqty = (float) $stock->getQty();
                        $updates[] = "$psku,$pqty,";
                    } catch (\Exception $e) {
                        $this->helper->logError("OrderPlacedObserver stock error for PID $productId: " . $e->getMessage());
                    }
                }

                if (!empty($updates)) {
                    $updatesToSend = implode("*\n", $updates);
                    $sourceUrl = $this->helper->getBaseUrl();

                    $xml = "<Products>
                            <SourceURL>{$sourceUrl}</SourceURL>
                            <StoreViewId>0</StoreViewId>
                            <Data><![CDATA[ $updatesToSend ]]></Data>
                            </Products>";

                    // Send to ChannelUnity
                    $this->helper->postToChannelUnity($xml, 'ProductDataLite');
                }

                // Update order status if applicable
                $tracksCollection = $order->getTracksCollection();
                $trackingNumbers = $this->helper->getTrackingNumbers($tracksCollection);

                $cuxml = $this->orderModel->generateCuXmlForOrderShip($order, $trackingNumbers);
                if ($cuxml) {
                    $this->helper->postToChannelUnity($cuxml, 'OrderStatusUpdate');
                }

            } else {
                $this->helper->logError("OrderPlacedObserver: Order data not found in event.");
            }
        } catch (\Exception $e) {
            // NEVER throw exceptions here, it will crash the customer checkout
            $this->helper->logError("OrderPlacedObserver general error: " . $e->getMessage());
        }
    }
}