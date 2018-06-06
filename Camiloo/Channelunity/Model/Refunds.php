<?php
/**
 * ChannelUnity connector for Magento Commerce
 *
 * @category   Camiloo
 * @package    Camiloo_Channelunity
 * @copyright  Copyright (c) 2018 ChannelUnity Limited (http://www.channelunity.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */


namespace Camiloo\Channelunity\Model;

use Magento\Framework\Model\AbstractModel;
use Magento\Sales\Model\OrderRepository;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Service\CreditmemoService;
use Magento\Sales\Model\RefundOrder;
use Magento\Sales\Model\Order\Creditmemo\ItemCreationFactory;
use Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterface;

class Refunds extends AbstractModel
{
    private $helper;
    private $orderRepository;
    private $searchCriteriaBuilder;
    private $creditMemoFactory;
    private $creditMemoService;
    private $refundOrder;
    private $itemCreationFactory;
    private $creditMemoArguments;
    
    public function __construct(
        Helper $helper,
        OrderRepository $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        CreditmemoFactory $creditMemoFactory,
        CreditmemoService $creditMemoService,
        RefundOrder $refundOrder,
        ItemCreationFactory $itemCreationFactory,
        CreditmemoCreationArgumentsInterface $creditMemoArguments
            ) {
        
        $this->helper = $helper;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        // Can be used for full order cancellation
        $this->creditMemoFactory = $creditMemoFactory;
        $this->creditMemoService = $creditMemoService;
        // Required for partial cancellation
        $this->refundOrder = $refundOrder;
        $this->itemCreationFactory = $itemCreationFactory;
        $this->creditMemoArguments = $creditMemoArguments;
    }
    
    
    /**
     * Called from CU to notify Magento that a partial order cancellation
     * has occurred.
     * @param type $request
     */
    public function partialRefund($request)
    {
        $this->helper->logInfo("Received partial refund request");
        $str = "<PartialRefund>\n";
        if (isset($request->PartialRefunds)) {
            foreach ($request->PartialRefunds->Refund as $refund) {
                $orderId = (string)$refund->OrderId;
                $sku = (string)$refund->SKU;
                $qty = (int)$refund->Quantity;
                $principalRefund = (float)$refund->PrincipalRefund;
                $taxRefund = (float)$refund->TaxRefund;
                $shippingRefund = (float)$refund->ShippingRefund;
                $shippingTaxRefund = (float)$refund->ShippingTaxRefund;
                $miscRefund = (float)$refund->MiscRefund;
                
                $str .= "<Info>Checking order '$orderId'</Info>\n";
                
                // Now find and load the order in question

                $searchCriteria = $this->searchCriteriaBuilder
                        ->addFilter('increment_id', $orderId, 'eq')->create();

                $orderList = $this->orderRepository->getList($searchCriteria);
                
                $olist = $orderList->getItems();
                foreach ($olist as $existingOrder) {
                    // Yes we found an order
                    $str .= "<Info>Order $orderId found</Info>\n";
                    
                    $orderItemIDUsed = 0;
                    
                    // If a SKU is supplied on the partial refund request,
                    // find this in our data
                    foreach ($existingOrder->getAllItems() as $orderItem) {
                        $orderItemID = $orderItem->getId();
                        $orderItemSKU = $orderItem->getSku();
                        $str .= "<ItemID>$orderItemID $orderItemSKU</ItemID>\n";
                        
                        if ($sku != "" && $orderItemSKU == $sku) {
                            $orderItemIDUsed = $orderItemID;
                            break; // Found the correct SKU on the order
                        }
                    }
                    $itemsArray = [];
                    
                    if ($orderItemIDUsed) {
                        $creditmemoItem = $this->itemCreationFactory->create();
                        $creditmemoItem->setQty($qty)->setOrderItemId($orderItemIDUsed);
                        $itemsArray[] = $creditmemoItem;
                        
                        $str .= "<Info>Using order item</Info>\n";
                    }
                    
                    if ($miscRefund > 0) {
                        // Put this is in as an adjustment amount
                        $this->creditMemoArguments->setAdjustmentPositive($miscRefund);
                        
                        $str .= "<Info>General adjustment used</Info>\n";
                    }
                    if ($shippingRefund > 0) {
                        $this->creditMemoArguments->setShippingAmount($shippingRefund);
                        
                        $str .= "<Info>Shipping amount used</Info>\n";
                    }
                    
                    // Now ready to issue the credit memo
                    $str .= "<Info>Creating credit memo now</Info>\n";
                    
                    $this->refundOrder->execute(
                        $existingOrder->getId(),
                        $itemsArray,
                        false,
                        false,
                        null,
                        $this->creditMemoArguments
                    );
                }
            }
        }
        $str .= "</PartialRefund>\n";
        return $str;
    }
}
