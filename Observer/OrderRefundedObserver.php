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
use \Magento\Framework\Registry;
use \Magento\Sales\Api\CreditmemoRepositoryInterface;
use \Camiloo\Channelunity\Model\Helper;
use \Camiloo\Channelunity\Model\Orders;

class OrderRefundedObserver implements ObserverInterface
{
    private $helper;
    private $orderModel;
    private $registry;
    /**
     * @var CreditmemoRepositoryInterface
     */
    private $creditmemoRepository;

    public function __construct(
        Helper $helper,
        Orders $orders,
        Registry $registry,
        CreditmemoRepositoryInterface $creditmemoRepository
    ) {
        $this->helper = $helper;
        $this->orderModel = $orders;
        $this->registry = $registry;
        $this->creditmemoRepository = $creditmemoRepository;
    }
    
    public function execute(Observer $observer)
    {
        $this->helper->logInfo("Observer called: ".$observer->getEvent()->getName());
        if ($this->registry->registry('cu_partial_refund') == 'active') {
            // log and return
            $this->helper->logInfo("Skip observer as we have incoming refund");
            return;
        }
        
        $creditMemo = $observer->getData('creditmemo');
        if (is_object($creditMemo)) {
            $mageOrder = $creditMemo->getOrder();
            $orderStatus = $mageOrder->getStatus();
            $this->helper->logInfo("We have a credit memo. Order status: $orderStatus");
            
            $crMemoTotal = $creditMemo->getGrandTotal();
            $orderTotal = $mageOrder->getGrandTotal();
            
            // Only want this to be called if we are doing a PARTIAL cancellation
            if ($crMemoTotal < $orderTotal) {
                $this->helper->logInfo("Credit memo total $crMemoTotal is less than order total $orderTotal, doing partial cancellation");
                
                // Load the credit memo again just incase the SKUs are missing
                $creditmemoId = $creditMemo->getEntityId();
                // Ensure we have a valid ID!
                if ($creditmemoId) {
                    $this->helper->logInfo("Load credit memo ID {$creditmemoId}");
                    $newCreditMemo = $this->creditmemoRepository->get($creditmemoId);
                    $this->helper->logInfo("Is credit memo loaded: " . (is_object($newCreditMemo) ? "yes" : "no"));

                    $this->doRefund($newCreditMemo, $mageOrder);
                }
                else {
                    $this->doRefund($creditMemo, $mageOrder);
                }
            }
            else {
                $this->helper->logInfo("Credit memo total $crMemoTotal is NOT less than order total $orderTotal, skipping partial cancellation");
            }
        }
    }
    
    private function doRefund($creditMemo, $mageOrder)
    {
        $partialActionItems = [];

        // Get items out of the credit memo
        $items = $creditMemo->getItems();
        
        // Collect all partial action items as required by CU API
        // Each item is a \Magento\Sales\Api\Data\CreditmemoItemInterface
        foreach ($items as $item) {
            $amount = $item->getRowTotalInclTax();
            $sku = $item->getSku();
            $qty = $item->getQty();

            $partialActionItems[] = [
                "Action" => "Refund",
                "Amount" => $amount,
                "SKU" => $sku,
                "QtyAffected" => $qty
            ];
        }

        // Get shipment refund
        $shippingAmount = $creditMemo->getShippingAmount();

        // Send to CU
        $cuXml = $this->orderModel->generateCuXmlForPartialAction(
            $mageOrder,
            $partialActionItems,
            $shippingAmount
        );
        if ($cuXml) {
            $this->helper->postToChannelUnity($cuXml, 'DoPartialAction');
        } else {
            $this->helper->logInfo("Not a CU order");
        }
    }
}
