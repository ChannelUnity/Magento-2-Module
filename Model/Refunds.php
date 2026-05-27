<?php
/**
 * ChannelUnity connector for Magento Commerce
 *
 * @category   Camiloo
 * @package    Camiloo_Channelunity
 * @copyright  Copyright (c) 2016-2024 ChannelUnity Limited (http://www.channelunity.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Camiloo\Channelunity\Model;

use Magento\Framework\Model\AbstractModel;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Model\RefundOrder;
use Magento\Sales\Model\Order\Creditmemo\ItemCreationFactory;
use Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterfaceFactory;
use Camiloo\Channelunity\Helper\Data;

class Refunds extends AbstractModel
{
    /**
     * @var Helper
     */
    private $helper;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var RefundOrder
     */
    private $refundOrder;

    /**
     * @var ItemCreationFactory
     */
    private $itemCreationFactory;

    /**
     * @var CreditmemoCreationArgumentsInterfaceFactory
     */
    private $creditMemoArgumentsFactory;

    public function __construct(
        Data $helper,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        RefundOrder $refundOrder,
        ItemCreationFactory $itemCreationFactory,
        CreditmemoCreationArgumentsInterfaceFactory $creditMemoArgumentsFactory
    ) {
        $this->helper = $helper;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->refundOrder = $refundOrder;
        $this->itemCreationFactory = $itemCreationFactory;
        $this->creditMemoArgumentsFactory = $creditMemoArgumentsFactory;
    }

    /**
     * Called from CU to notify Magento that a partial order cancellation
     * has occurred.
     * @param object $request
     */
    public function partialRefund($request)
    {
        $this->helper->logInfo("Received partial refund request");
        $str = "<PartialRefund>\n";

        if (isset($request->PartialRefunds)) {
            foreach ($request->PartialRefunds->Refund as $refund) {
                $orderId = (string)$refund->OrderId;
                $sku = (string)$refund->SKU;
                $qty = (float)$refund->Quantity;
                $miscRefund = (float)$refund->MiscRefund;
                $shippingRefund = (float)$refund->ShippingRefund;

                $str .= "<Info>Checking order '$orderId'</Info>\n";

                try {
                    $searchCriteria = $this->searchCriteriaBuilder
                        ->addFilter('increment_id', $orderId, 'eq')->create();

                    $orderList = $this->orderRepository->getList($searchCriteria);

                    if ($orderList->getTotalCount() === 0) {
                        $str .= "<Info>Order $orderId not found</Info>\n";
                        continue;
                    }

                    foreach ($orderList->getItems() as $existingOrder) {
                        $str .= "<Info>Order $orderId found</Info>\n";

                        $orderItemIDUsed = 0;

                        // If a SKU is supplied, find the corresponding order item
                        if ($sku !== "") {
                            foreach ($existingOrder->getAllItems() as $orderItem) {
                                $orderItemSKU = $orderItem->getSku();

                                if ($orderItemSKU === $sku) {
                                    $orderItemIDUsed = $orderItem->getId();
                                    $str .= "<ItemID>$orderItemIDUsed $orderItemSKU</ItemID>\n";
                                    break;
                                }
                            }
                        }

                        $itemsArray = [];

                        if ($orderItemIDUsed) {
                            $creditmemoItem = $this->itemCreationFactory->create();
                            $creditmemoItem->setQty($qty)->setOrderItemId($orderItemIDUsed);
                            $itemsArray[] = $creditmemoItem;

                            $str .= "<Info>Using order item</Info>\n";
                        }

                        // Create a fresh arguments object for this specific refund to prevent cross-contamination
                        $creditMemoArguments = $this->creditMemoArgumentsFactory->create();

                        if ($miscRefund > 0) {
                            $creditMemoArguments->setAdjustmentPositive($miscRefund);
                            $str .= "<Info>General adjustment used</Info>\n";
                        }
                        if ($shippingRefund > 0) {
                            $creditMemoArguments->setShippingAmount($shippingRefund);
                            $str .= "<Info>Shipping amount used</Info>\n";
                        }

                        $str .= "<Info>Creating credit memo now</Info>\n";

                        // Execute the refund via Magento Service
                        $this->refundOrder->execute(
                            $existingOrder->getEntityId(),
                            $itemsArray,
                            false,
                            false,
                            null,
                            $creditMemoArguments
                        );

                        $str .= "<Info>Credit memo created successfully</Info>\n";
                    }
                } catch (\Exception $e) {
                    $errorMsg = $e->getMessage();
                    $str .= "<Error>Failed to refund order $orderId: $errorMsg</Error>\n";
                    $this->helper->logError("Partial refund failed for order $orderId: " . $errorMsg);
                }
            }
        }
        $str .= "</PartialRefund>\n";
        return $str;
    }
}