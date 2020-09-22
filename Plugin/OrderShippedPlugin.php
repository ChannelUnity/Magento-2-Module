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
use \Camiloo\Channelunity\Model\Orders;

/**
 * Used for when tracking data is added after an order has already been shipped.
 */
class OrderShippedPlugin
{
    private $helper;
    private $orderModel;
    
    public function __construct(
        Helper $helper,
        Orders $orders
    ) {
    
        $this->helper = $helper;
        $this->orderModel = $orders;
    }
    
    public function aroundAddTrack(\Magento\Sales\Model\Order\Shipment $shipment, callable $proceed, \Magento\Sales\Model\Order\Shipment\Track $track)
    {
        $result = $proceed($track);
        
        if (!is_object($track)) {
            return $result;
        }
        
        $order = $shipment->getOrder();
        
        if (is_object($order)) {
            $payment = $order->getPayment();
            
            if ($order->getState() == 'complete' && is_object($payment)) {
                $infoArray = $payment->getAdditionalInformation();
                if (isset($infoArray['subscription_id'])) {
                    // This is a CU order
                
                    $carrierName = $track->getCarrierCode();
                    if ($carrierName == "custom") {
                        $carrierName = $track->getTitle();
                    }
                    $shipMethod = $track->getTitle();
                    $trackingNumber = $track->getNumber();

                    if ($carrierName) {
                        $cuxml = $this->orderModel->generateCuXmlForOrderShip(
                            $order,
                            $carrierName,
                            $shipMethod,
                            $trackingNumber
                        );
                        if ($cuxml) {
                            $this->helper->postToChannelUnity($cuxml, 'OrderStatusUpdate');
                        }
                    }
                } else {
                    $this->helper->logInfo("!!!!Not a CU order");
                }
            } else {
                $this->helper->logInfo("!!!!Not a complete order");
            }
        } else {
            $this->helper->logInfo("!!!!Not got order object");
        }
        return $result;
    }
}
