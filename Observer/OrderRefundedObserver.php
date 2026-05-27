<?php

/**
 * ChannelUnity connector for Magento Commerce
 *
 * @category   Camiloo
 * @package    Camiloo_Channelunity
 * @copyright  Copyright (c) 2016-2026 ChannelUnity Limited (http://www.channelunity.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Camiloo\Channelunity\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Registry;
use Camiloo\Channelunity\Model\Helper;
use Camiloo\Channelunity\Model\Orders;

/**
 * ChannelUnity observers.
 * Posts events to the CU cloud when various Magento events occur.
 */
class OrderRefundedObserver implements ObserverInterface
{
    /**
     * @var Helper
     */
    private $helper;

    /**
     * @var Orders
     */
    private $orderModel;

    /**
     * @var Registry
     */
    private $registry;

    public function __construct(
        Helper $helper,
        Orders $orders,
        Registry $registry
    ) {
        $this->helper = $helper;
        $this->orderModel = $orders;
        $this->registry = $registry;
    }

    public function execute(Observer $observer)
    {
        try {
            $this->helper->logInfo("Observer called: " . $observer->getEvent()->getName());

            // Prevent infinite loops if this refund was triggered by ChannelUnity itself
            if ($this->registry->registry('cu_partial_refund') === 'active') {
                $this->helper->logInfo("Skip observer as we have incoming refund from API.");
                return;
            }

            $creditMemo = $observer->getEvent()->getCreditmemo();

            if ($creditMemo && $creditMemo->getId()) {
                $mageOrder = $creditMemo->getOrder();
                $orderStatus = $mageOrder->getStatus();
                $this->helper->logInfo("We have a credit memo. Order status: $orderStatus");

                $crMemoTotal = (float) $creditMemo->getGrandTotal();
                $orderTotal = (float) $mageOrder->getGrandTotal();

                // Only want this to be called if we are doing a PARTIAL cancellation
                if ($crMemoTotal < $orderTotal) {
                    $this->helper->logInfo("Credit memo total $crMemoTotal is less than order total $orderTotal, doing partial cancellation.");

                    // Proceed directly with the in-memory object (safe, prevents database deadlocks)
                    $this->doRefund($creditMemo, $mageOrder);
                } else {
                    $this->helper->logInfo("Credit memo total $crMemoTotal is NOT less than order total $orderTotal, skipping partial cancellation.");
                }
            }
        } catch (\Exception $e) {
            // Catch all exceptions to prevent Magento's refund process from crashing
            $this->helper->logError("Failed to process ChannelUnity refund sync: " . $e->getMessage());
        }
    }

    /**
     * Extracts items and totals from the credit memo and sends to CU API.
     * * @param \Magento\Sales\Model\Order\Creditmemo $creditMemo
     * @param \Magento\Sales\Model\Order $mageOrder
     */
    private function doRefund($creditMemo, $mageOrder)
    {
        $partialActionItems = [];

        // Get items out of the credit memo
        $items = $creditMemo->getItems();

        // Collect all partial action items as required by CU API
        foreach ($items as $item) {
            $amount = (float) $item->getRowTotalInclTax();
            $sku = $item->getSku();
            $qty = (float) $item->getQty();

            // Skip items with zero qty (e.g., shipping-only refunds or bundled parent items)
            if ($qty > 0) {
                $partialActionItems[] = [
                    "Action"      => "Refund",
                    "Amount"      => $amount,
                    "SKU"         => $sku,
                    "QtyAffected" => $qty
                ];
            }
        }

        // Get shipment refund
        $shippingAmount = (float) $creditMemo->getShippingAmount();

        // Send to CU
        $cuXml = $this->orderModel->generateCuXmlForPartialAction(
            $mageOrder,
            $partialActionItems,
            $shippingAmount
        );

        if ($cuXml) {
            $this->helper->postToChannelUnity($cuXml, 'DoPartialAction');
        } else {
            $this->helper->logInfo("Not a ChannelUnity order. Skipping refund sync.");
        }
    }
}