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
use Magento\Framework\Registry;
use Camiloo\Channelunity\Helper\Helper;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductFactory;
use Magento\Quote\Model\Quote\Address\Rate;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\OrderRepository;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\DB\TransactionFactory;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Directory\Api\CountryInformationAcquirerInterface;
use Magento\Directory\Helper\Data;
use Magento\GiftMessage\Model\MessageFactory;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\App\ResourceConnection;

class Orders extends AbstractModel
{
    private $product;
    private $registry;
    private $customerFactory;
    private $customerRepository;
    private $transaction;
    private $storeManager;
    private $helper;
    private $stockRegistry;
    private $cartRepositoryInterface;
    private $productRepository;
    private $cartManagementInterface;
    private $orderRepository;
    private $searchCriteriaBuilder;
    private $invoiceService;
    private $transactionFactory;
    private $currencyFactory;
    private $productFactory;
    private $shippingRate;
    private $bIsInventoryProcessed;
    private $countryInformationAcquirer;
    private $directoryHelper;
    private $giftMessageFactory;
    private $dataObjectFactory;
    private $resource;

    public function __construct(
        Helper $helper,
        Registry $registry,
        StoreManagerInterface $storeManager,
        Product $product,
        CustomerFactory $customerFactory,
        CustomerRepositoryInterface $customerRepository,
        Transaction $transaction,
        StockRegistryInterface $stockRegistry,
        CartRepositoryInterface $cartRepositoryInterface,
        ProductRepositoryInterface $productRepository,
        CartManagementInterface $cartManagementInterface,
        OrderRepository $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        CurrencyFactory $currencyFactory,
        ProductFactory $productFactory,
        Rate $shippingRate,
        CountryInformationAcquirerInterface $countryInformationAcquirer,
        Data $directoryHelper,
        MessageFactory $giftMessageFactory,
        DataObjectFactory $dataObjectFactory,
        ResourceConnection $resource
    ) {
        $this->helper = $helper;
        $this->registry = $registry;
        $this->storeManager = $storeManager;
        $this->product = $product;
        $this->customerFactory = $customerFactory;
        $this->customerRepository = $customerRepository;
        $this->transaction = $transaction;
        $this->stockRegistry = $stockRegistry;
        $this->cartRepositoryInterface = $cartRepositoryInterface;
        $this->productRepository = $productRepository;
        $this->cartManagementInterface = $cartManagementInterface;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->invoiceService = $invoiceService;
        $this->transactionFactory = $transactionFactory;
        $this->currencyFactory = $currencyFactory;
        $this->productFactory = $productFactory;
        $this->shippingRate = $shippingRate;
        $this->countryInformationAcquirer = $countryInformationAcquirer;
        $this->directoryHelper = $directoryHelper;
        $this->giftMessageFactory = $giftMessageFactory;
        $this->dataObjectFactory = $dataObjectFactory;
        $this->resource = $resource;
    }

    public function generateCuXmlForOrderStatus(\Magento\Sales\Model\Order $order)
    {
        $orderStatusMapping = [
            "canceled"        => "Cancelled",
            "cancelled"       => "Cancelled",
            "closed"          => "Cancelled",
            "complete"        => "Complete",
            "processing"      => "Processing",
            "holded"          => "OnHold",
            "new"             => "Processing",
            "payment_review"  => "OnHold",
            "pending_payment" => "OnHold",
            "fraud"           => "OnHold"
        ];

        $orderStatus = $orderStatusMapping[$order->getState()] ?? "Processing";

        $payment = $order->getPayment();
        $incrId = $order->getIncrementId();

        $orderXml = "";
        $isCu = false;

        $infoArray = $payment->getAdditionalInformation();
        if (isset($infoArray['subscription_id'])) {
            $orderXml .= "<SubscriptionID>{$infoArray['subscription_id']}</SubscriptionID>\n";
            $isCu = true;
        }

        $orderXml .= "<OrderID>$incrId</OrderID>\n";

        if (!$isCu) {
            return false;
        }

        $orderXml .= "<OrderStatus>$orderStatus</OrderStatus>\n";

        return $orderXml;
    }

    public function generateCuXmlForOrderShip($order, array $trackingNumbers)
    {
        $carrierName = '';
        $shipMethod = '';
        $trackNumber = '';
        $returnTrackingNumber = '';

        if (isset($trackingNumbers['Tracking'])) {
            $carrierName = $trackingNumbers['Tracking']['CarrierName'] ?? '';
            $shipMethod = $trackingNumbers['Tracking']['ShipMethod'] ?? '';
            $trackNumber = $trackingNumbers['Tracking']['TrackingNumber'] ?? '';
            if (isset($trackingNumbers['ReturnTracking'])) {
                $returnTrackingNumber = $trackingNumbers['ReturnTracking']['TrackingNumber'] ?? '';
            }
        }

        $orderXml = $this->generateCuXmlForOrderStatus($order);

        if (!empty($orderXml) && $order->getState() == 'complete') {
            $orderXml .= "<ShipmentDate>" . date("c") . "</ShipmentDate>\n";
            $orderXml .= "<CarrierName><![CDATA[$carrierName]]></CarrierName>\n";
            $orderXml .= "<ShipmentMethod><![CDATA[$shipMethod]]></ShipmentMethod>\n";
            $orderXml .= "<TrackingNumber><![CDATA[$trackNumber]]></TrackingNumber>\n";
            $orderXml .= "<ReturnTrackingNumber><![CDATA[$returnTrackingNumber]]></ReturnTrackingNumber>\n";
        }

        return $orderXml;
    }

    public function generateCuXmlForPartialAction($order, array $actionItems, $shippingAmount)
    {
        $orderXml = $this->generateCuXmlForOrderStatus($order);

        if (!empty($orderXml)) {
            $orderXml .= "\t<PartialActionDate>" . date("c") . "</PartialActionDate>\n";
            foreach ($actionItems as $item) {
                $orderXml .= "\t<PartialActionItem>\n";
                foreach ($item as $k => $v) {
                    $orderXml .= "\t\t<$k>$v</$k>\n";
                }
                $orderXml .= "\t</PartialActionItem>\n";
            }
            if ($shippingAmount > 0) {
                $orderXml .= "\t<ShippingRefund>$shippingAmount</ShippingRefund>\n";
            }
        }
        return $orderXml;
    }

    public function fixEncoding(string $in_str): string
    {
        if (function_exists('mb_strlen')) {
            $cur_encoding = mb_detect_encoding($in_str);
            if (!($cur_encoding == "UTF-8" && mb_check_encoding($in_str, "UTF-8"))) {
                // utf8_encode is deprecated in PHP 8.2, using mb_convert_encoding instead
                $in_str = mb_convert_encoding($in_str, 'UTF-8', $cur_encoding ?: 'ISO-8859-1');
            }
        }

        return $in_str;
    }

    public function doCreate($dataArray, $order)
    {
        $str = "";
        $orderId = trim((string) $order->OrderId);
        $bFoundOrder = $this->checkOrderImportHistory((string) $order->OrderId, (int) $dataArray->SubscriptionId);

        $orderFlags = isset($order->OrderFlags) ? (string) $order->OrderFlags : '';

        $this->helper->logInfo("Order ID {$order->OrderId} - OrderFlags received from CU: '{$orderFlags}'");

        $forceRetry = stripos($orderFlags, 'FORCE_RETRY') !== false;

        if ($bFoundOrder && !$forceRetry) {
            $this->helper->logInfo("Order ID {$order->OrderId} blocked by history. bFoundOrder: true, forceRetry: false");
            $str .= "<Exception>Order blocked, we already tried to create this</Exception>\n";
            $str .= "<NotImported>$orderId</NotImported>\n";
            return $str;
        }

        $this->logOrderImportHistory((string) $order->OrderId, (int) $dataArray->SubscriptionId);

        $store = $this->storeManager->getStore((int) $dataArray->StoreviewId);
        $conversionRate = null;
        $forceTax = $this->helper->forceTaxValues();

        $this->helper->logInfo("Order creation. Force Tax? $forceTax");
        $websiteId = $store->getWebsiteId();

        $shippingInfo = $order->ShippingInfo;
        $billingInfo = $order->BillingInfo;

        $firstNameS = $this->getFirstName((string) $shippingInfo->RecipientName);
        $lastNameS = $this->getLastName((string) $shippingInfo->RecipientName);
        $firstNameB = $this->getFirstName((string) $billingInfo->Name);
        $lastNameB = $this->getLastName((string) $billingInfo->Name);
        $emailAddress = (string) $billingInfo->Email ?: (string) $shippingInfo->Email;

        $customer = $this->customerFactory->create();
        $customer->setWebsiteId($websiteId);
        $customer->loadByEmail($emailAddress);

        if (!$customer->getEntityId()) {
            $customer->setWebsiteId($websiteId)
                ->setStore($store)
                ->setFirstname($firstNameS)
                ->setLastname($lastNameS)
                ->setEmail($emailAddress)
                ->setPassword(bin2hex(random_bytes(16)));
            $customer->save();
        }

        $this->helper->logInfo("Creating quote");
        $cartId = $this->cartManagementInterface->createEmptyCart();
        $quote = $this->cartRepositoryInterface->get($cartId);

        $quote->setStore($store);
        $customerEntityId = $customer->getEntityId();
        $customer = $this->customerRepository->getById($customerEntityId);
        $quote->setCurrency();
        $quote->assignCustomer($customer);

        $destCurrency = $store->getCurrentCurrency()->getCode();
        $sourceCurrency = (string) $order->Currency;

        $this->helper->logInfo("doCreate: source currency $sourceCurrency, destination $destCurrency");

        if ($sourceCurrency != $destCurrency) {
            $allowedCurrs = $store->getAvailableCurrencyCodes();
            if (!in_array($sourceCurrency, $allowedCurrs)) {
                throw new \Magento\Framework\Exception\LocalizedException(__("The order cannot be imported because the currency $sourceCurrency is not available"));
            }

            $currency = $this->currencyFactory->create()->load($sourceCurrency);
            $conversionRate = $store->getCurrentCurrency()->getRate($sourceCurrency);
            $quote->getStore()->setData('current_currency', $currency);

            $this->helper->logInfo("doCreate: Currency conversion rate $conversionRate");
        }

        $rateToRegister = $conversionRate !== null ? $conversionRate : 1;
        $this->registry->unregister('cu_conversion_rate');
        $this->registry->register('cu_conversion_rate', $rateToRegister);

        $this->registry->unregister('cu_shipping_tax');
        $this->registry->register('cu_shipping_tax', (float) $shippingInfo->ShippingTax);

        $quote->save();

        $itemSeq = 1;
        foreach ($order->OrderItems->Item as $item) {
            $this->helper->logInfo("doCreate: Line ".__LINE__."");
            $this->registry->unregister('cu_sequence-'.$itemSeq);
            $this->registry->register('cu_sequence-'.$itemSeq, $item);

            $productPrice = $conversionRate != null && $conversionRate > 0 ? (float)$item->Price/$conversionRate : (float)$item->Price;

            $skuToSearch = (string) $item->SKU;
            $bundleSKUComponents = [];
            if (strpos($skuToSearch, '^') !== false) {
                $bundleSKUComponents = explode('^', $skuToSearch);
                $skuToSearch = $bundleSKUComponents[0];
            }

            $searchCriteria = $this->searchCriteriaBuilder->addFilter(
                (string) $dataArray->SkuAttribute,
                $skuToSearch,
                'eq'
            )->create();

            $existingProducts = $this->productRepository->getList($searchCriteria);
            $itemArray = $existingProducts->getItems();

            if (count($itemArray) == 0) {
                $this->helper->logInfo("doCreate: Product not found in Magento");
                if ($this->helper->allowStubProducts()) {
                    $this->helper->logInfo("doCreate: Creating stub product now");
                    $itemArray = [$this->createStubProduct($item, $productPrice, $websiteId)];
                } else {
                    $this->helper->logInfo("doCreate: Stub products not allowed");
                    throw new \Magento\Framework\Exception\LocalizedException(__("ProductNotFound"));
                }
            }

            foreach ($itemArray as $product) {
                $product->setPrice($productPrice);
                $product->setSpecialPrice($productPrice);
                $product->setCustomPrice($productPrice);
                $product->setOriginalCustomPrice($productPrice);
                $product->setIsSuperMode(true);
                $product->setName($item->Name);

                $productParameters = $this->dataObjectFactory->create();
                $productParameters->setData('product', $product->getId());
                $productParameters->setData('qty', (int) $item->Quantity);

                // Bundle Product Logic Retained
                if ($bundleSKUComponents) {
                    $this->helper->logInfo("Bundle product $skuToSearch");
                    $productsArray = [];
                    $optionIdToPosition = [];

                    $productTypeObj = $product->getTypeInstance(true);
                    $selectionCollection = $productTypeObj->getSelectionsCollection(
                        $productTypeObj->getOptionsIds($product),
                        $product
                    );

                    foreach ($selectionCollection as $pselection) {
                        if (array_key_exists($pselection->getOptionId(), $optionIdToPosition)) {
                            $position = $optionIdToPosition[$pselection->getOptionId()];
                        } else {
                            $position = count($optionIdToPosition)+1;
                            $optionIdToPosition[$pselection->getOptionId()] = $position;
                        }

                        $productsArray[$pselection->getOptionId()][$pselection->getSelectionId()] = [
                            'product_name' => $pselection->getName(),
                            'product_price' => $pselection->getPrice(),
                            'product_qty' => $pselection->getSelectionQty(),
                            'product_id' => $pselection->getProductId(),
                            'product_sku' => $pselection->getSku()
                        ];
                    }

                    $bundleOptions = [];
                    $optionPosition = 1;
                    foreach ($optionIdToPosition as $optionId => $position) {
                        $skuAtPosition = $bundleSKUComponents[$position];
                        foreach ($productsArray[$optionId] as $selectionId => $selectionArray) {
                            if ($skuAtPosition == $selectionArray['product_sku']) {
                                $bundleOptions[$optionId] = $selectionId;
                                $optionPosition++;
                                $this->helper->logInfo("Adding option and selection ID: $optionId -> $selectionId");
                                break;
                            }
                        }
                    }
                    $productParameters->setData('bundle_option', $bundleOptions);
                }

                $this->helper->logInfo("doCreate: Adding {$item->SKU} to quote");
                $quoteItem = $quote->addProduct($product, $productParameters);

                if (is_string($quoteItem)) {
                    throw new \Magento\Framework\Exception\LocalizedException(__($quoteItem));
                }

                if ($quoteItem) {
                    $quoteItem->setOriginalCustomPrice($productPrice);
                    $quoteItem->setCustomPrice($productPrice);
                    $quoteItem->setIsSuperMode(true);
                }

                $itemSeq++;
                break; // Should only process 1 matched product
            }
        }

        $addressArray = array_filter([
            (string) $shippingInfo->Address1,
            (string) $shippingInfo->Address2,
            (string) $shippingInfo->Address3
        ]);

        $state = (string) $shippingInfo->State;
        $countryId = $this->countryCodeToId((string) $shippingInfo->Country);
        if (!$state && $this->isRegionRequired($countryId)) {
            $state = "N/A";
        }

        $baseAddress = [
            'street' => $addressArray,
            'city' => (string) $shippingInfo->City,
            'country_id' => (string) $shippingInfo->Country,
            'region' => $state,
            'postcode' => (string) $shippingInfo->PostalCode,
            'save_in_address_book' => 0
        ];

        $shippingAddress = array_merge($baseAddress, [
            'firstname' => (string) $firstNameS,
            'lastname' => (string) $lastNameS,
            'telephone' => (string) $shippingInfo->PhoneNumber
        ]);

        $billingAddress = array_merge($baseAddress, [
            'firstname' => (string) $firstNameB,
            'lastname' => (string) $lastNameB,
            'telephone' => (string) $billingInfo->PhoneNumber
        ]);

        $regionId = $this->getRegionId((string) $shippingInfo->Country, $state);

        if (is_numeric($regionId)) {
            $shippingAddress['region_id'] = $regionId;
            $billingAddress['region_id'] = $regionId;
        }

        $quote->getBillingAddress()->addData($billingAddress);
        $quote->getShippingAddress()->addData($shippingAddress);

        $this->registry->unregister('cu_shipping_method');
        $this->registry->register('cu_shipping_method', (string) $order->ShippingInfo->Service);

        $shippingPrice = $conversionRate != null ? (float) $order->ShippingInfo->ShippingPrice/$conversionRate : (float) $order->ShippingInfo->ShippingPrice;
        $this->registry->unregister('cu_shipping_price');
        $this->registry->register('cu_shipping_price', $shippingPrice);

        // --- CRITICAL FIX: Add the VIP flag so the payment method allows the order ---
        $this->registry->unregister('cu_order_in_progress');
        $this->registry->register('cu_order_in_progress', true);

        $quote->getShippingAddress()
            ->setCollectShippingRates(true)
            ->collectShippingRates()
            ->setShippingMethod('cucustomrate_cucustomrate');

        $quote->setPaymentMethod('channelunitypayment');
        $quote->setInventoryProcessed($this->bIsInventoryProcessed);

        $quote->getPayment()->importData(['method' => 'channelunitypayment']);
        $quote->getPayment()->setAdditionalInformation([
            'subscription_id' => (string) $dataArray->SubscriptionId,
            'service_sku' => (string) $order->ServiceSku,
            'order_flags' => (string) $order->OrderFlags
        ]);

        $quote->collectTotals();

        $this->helper->logInfo("doCreate: Now submitting order");

        // --- CRITICAL FIX: Wrap submission in try/catch to log Magento validation errors ---
        $newOrder = null;
        try {
            $this->cartRepositoryInterface->save($quote);
            $quote = $this->cartRepositoryInterface->get($quote->getId());
            $newOrder = $this->cartManagementInterface->submit($quote);
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            $this->helper->logError("Quote Submission Failed for Order {$orderId}: " . $msg);

            $str .= "<Info><![CDATA[Magento rejected the order: $msg]]></Info>\n";
            $str .= "<Exception><![CDATA[$msg]]></Exception>\n";
            $str .= "<NotImported>$orderId</NotImported>\n";

            // Delete history so ChannelUnity is allowed to retry it later
            $this->deleteOrderImportHistory((string) $order->OrderId, (int) $dataArray->SubscriptionId);
            return $str;
        }

        if (is_object($newOrder) && $newOrder->getEntityId()) {
            $entityId = $newOrder->getEntityId();
            $newOrder->setIncrementId($order->OrderId);

            $this->createInvoice($newOrder);

            if (isset($order->ShippingInfo->GiftMessage)) {
                try {
                    $giftMessage = $this->giftMessageFactory->create();
                    $giftMessage->setMessage((string) $order->ShippingInfo->GiftMessage);
                    $giftMessage->save();
                    $newOrder->setData('gift_message_id', $giftMessage->getGiftMessageId());
                } catch (\Exception $e) {
                    $msg = $e->getMessage();
                    $str .= "<Info><![CDATA[Gift message error: $msg]]></Info>\n";
                }
            }

            $this->doSingleOrder($order, $newOrder, false);

            $str .= "<Info>We created order ID $entityId</Info>\n";
            $str .= "<Imported>$order->OrderId</Imported>\n";

            $newOrder->setEmailSent(0);
            $newOrder->addStatusHistoryComment('Order imported by ChannelUnity', false);

            $strDeliveryInstr = (string) $order->DeliveryInstructions;
            if ($strDeliveryInstr != '') {
                $newOrder->addStatusHistoryComment('Delivery Instructions: '.$strDeliveryInstr, false);
            }

            $newOrder->setCreatedAt((string) $order->PurchaseDate);
            $newOrder->save();
        } else {
            $str .= "<Info>It seems the order didn't create for an unknown reason.</Info>\n";
        }

        $this->deleteOrderImportHistory((string) $order->OrderId, (int) $dataArray->SubscriptionId);

        return $str;
    }

    private function createInvoice($order)
    {
        try {
            if (!$order->canInvoice()) {
                return null;
            }

            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
            $invoice->register();

            $invoice->getOrder()->setCustomerNoteNotify(false);
            $invoice->getOrder()->setIsInProcess(true);

            $transactionSave = $this->transactionFactory->create()
                ->addObject($invoice)->addObject($invoice->getOrder());
            $transactionSave->save();
        } catch (\Exception $e) {
            $order->addStatusHistoryComment('Exception message: '.$e->getMessage(), false);
            $order->save();
            throw new LocalizedException(__("Invoice create error"));
        }

        return $invoice;
    }

    private function createStubProduct($item, $productPrice, $websiteId)
    {
        $defaultAttributeSet = $this->product->getDefaultAttributeSetId();

        $product = $this->productFactory->create();

        // Explicitly set the website ID matching the order's quote
        $product->setWebsiteIds([$websiteId]);

        $product->setSku((string)$item->SKU);
        $product->setName((string)$item->Name);
        $product->setDescription((string)$item->Name);
        $product->setStatus(1); // 1 = Enabled
        $product->setAttributeSetId($defaultAttributeSet);
        $product->setVisibility(1); // 1 = Not Visible Individually (keeps it hidden from frontend)
        $product->setTaxClassId(0);
        $product->setTypeId('simple');
        $product->setPrice($productPrice);

        $product->setStockData([
            'use_config_manage_stock' => 0,
            'manage_stock' => 0,
            'is_in_stock' => 1,
            'qty' => (int)$item->Quantity
        ]);

        $product->setQuantityAndStockStatus(['is_in_stock' => 1, 'qty' => (int)$item->Quantity]);
        $newProduct = $this->productRepository->save($product);
        return $this->productRepository->getById($newProduct->getId(), false, null, true);
    }

    private function getFirstName(string $name): string
    {
        $lastSpacePos = strrpos($name, " ");
        if ($lastSpacePos !== false) {
            return substr($name, 0, $lastSpacePos);
        }
        return $name;
    }

    private function getLastName(string $name): string
    {
        $exp = explode(" ", $name);
        if (count($exp) > 1) {
            return $exp[count($exp) - 1];
        }
        return "___";
    }

    public function CUOrderStatusToMagentoStatus(string $orderStatus): string
    {
        $statusMap = [
            'Processing' => 'processing',
            'OnHold'     => 'holded',
            'Complete'   => 'complete'
        ];

        return $statusMap[$orderStatus] ?? 'canceled';
    }

    private function doSingleOrder($singleOrder, \Magento\Sales\Model\Order $newOrder, bool $bSaveOrder = true)
    {
        $ordStatus = $this->CUOrderStatusToMagentoStatus((string) $singleOrder->OrderStatus);

        try {
            $newOrder->setData('state', $ordStatus);
            $newOrder->setData('status', $ordStatus);
        } catch (\Exception $x1) {
            try {
                $newOrder->setData('state', 'closed');
                $newOrder->setData('status', 'closed');
            } catch (\Exception $x2) {
                $this->helper->logError("doSingleOrder: Could not update order status ($ordStatus)");
            }
        }

        if ($bSaveOrder) {
            $newOrder->save();
        }
    }

    public function reserveStock($dataArray, $order, int $multiplier = -1)
    {
        $canDecreaseStock = $this->helper->getConfig('cataloginventory/options/can_subtract');

        if ($canDecreaseStock) {
            foreach ($order->OrderItems->Item as $orderitem) {
                $qty = (int) $orderitem->Quantity;
                $product = $this->product->loadByAttribute(
                    (string) $dataArray->SkuAttribute,
                    (string) $orderitem->SKU
                );
                if (is_object($product)) {
                    $productId = $product->getEntityId();
                    $stockItem = $this->stockRegistry->getStockItem($productId);

                    $stockItem->setQtyCorrection($multiplier * $qty);
                    $this->stockRegistry->updateStockItemBySku($product->getSku(), $stockItem);
                }
            }
        }
    }

    public function releaseStock($dataArray, $order)
    {
        $this->reserveStock($dataArray, $order, 1);
    }

    public function doUpdate($dataArray)
    {
        $str = "";
        foreach ($dataArray->Orders->Order as $order) {
            $orderId = trim((string) $order->OrderId);

            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter('increment_id', $orderId, 'eq')->create();

            $orderList = $this->orderRepository->getList($searchCriteria);

            $bOrderExisted = $orderList->getSize() > 0;

            $orderIsPrime = isset($order->OrderFlags) && strpos((string) $order->OrderFlags, 'AMAZON_PRIME') !== false;
            if ($orderIsPrime) {
                $this->registry->unregister('cu_prime_order');
                $this->registry->register('cu_prime_order', 1);
            }

            $orderIsFba = isset($order->OrderFlags) && strpos((string) $order->OrderFlags, 'AMAZON_FBA') !== false;
            $ignoreQty = $this->helper->getConfig('channelunityint/generalsettings/ignorefbaqty');
            $this->bIsInventoryProcessed = $orderIsFba && $ignoreQty;

            if (!$bOrderExisted) {
                if ((string) $order->OrderStatus == "Processing") {
                    if (isset($order->StockReservedCart) && ((string) $order->StockReservedCart) == "1") {
                        $str .= "<StockReleased>$orderId</StockReleased>\n";

                        if (!$orderIsFba || !$ignoreQty) {
                            $this->releaseStock($dataArray, $order);
                        }
                    }

                    try {
                        $this->registry->register('cu_order_in_progress', 1, true);
                        $str .= $this->doCreate($dataArray, $order);
                    } catch (\Exception $e) {
                        $str .= "<Exception>".$e->getMessage()."</Exception>\n";
                        $str .= "<NotImported>$orderId</NotImported>\n";
                        $this->helper->logError($e->getMessage()."-".$e->getTraceAsString());
                    }

                    $this->registry->unregister('cu_order_in_progress');
                } elseif ((string) $order->OrderStatus == "OnHold") {
                    $str .= "<Imported>$orderId</Imported>\n";
                    if (!isset($order->StockReservedCart) || ((string) $order->StockReservedCart) == "0") {
                        $str .= "<StockReserved>$orderId</StockReserved>\n";
                        if (!$orderIsFba || !$ignoreQty) {
                            $this->reserveStock($dataArray, $order);
                        }
                    }
                } else {
                    if ("Cancelled" != (string) $order->OrderStatus) {
                        try {
                            $this->registry->register('cu_order_in_progress', 1, true);
                            $str .= $this->doCreate($dataArray, $order);
                        } catch (\Exception $e) {
                            $str .= "<Exception>".$e->getMessage()."</Exception>\n";
                            $str .= "<NotImported>$orderId</NotImported>\n";
                        }
                        $this->registry->unregister('cu_order_in_progress');
                    } else {
                        if (isset($order->StockReservedCart) && ((string) $order->StockReservedCart) == "1") {
                            $str .= "<StockReleased>$orderId</StockReleased>\n";
                            if (!$orderIsFba || !$ignoreQty) {
                                $this->releaseStock($dataArray, $order);
                            }
                        }
                        $str .= "<Imported>$orderId</Imported>\n";
                    }
                }
            }

            if ($bOrderExisted) {
                $olist = $orderList->getItems();
                foreach ($olist as $existingOrder) {
                    $this->doSingleOrder($order, $existingOrder);
                    break;
                }
                if ((string) $order->OrderStatus == "Cancelled") {
                    if (isset($order->StockReservedCart) && ((string) $order->StockReservedCart) == "1") {
                        $str .= "<StockReleased>$orderId</StockReleased>\n";

                        if (!$orderIsFba || !$ignoreQty) {
                            $this->releaseStock($dataArray, $order);
                        }
                    }
                }
                $str .= "<Imported>$orderId</Imported>\n";
            }
        }
        return $str;
    }

    public function shipmentCheck($request)
    {
        $str = "<ShipmentCheck>\n";
        if (isset($request->OrderIdsToCheck)) {
            foreach ($request->OrderIdsToCheck->OrderId as $orderId) {
                $str .= "<Info>Checking order '$orderId'</Info>\n";

                $searchCriteria = $this->searchCriteriaBuilder
                    ->addFilter('increment_id', $orderId, 'eq')->create();

                $orderList = $this->orderRepository->getList($searchCriteria);
                $olist = $orderList->getItems();
                foreach ($olist as $existingOrder) {
                    $str .= "<Info>Order $orderId found</Info>\n";

                    if ($existingOrder->getState() != 'processing') {
                        $cuXml = $this->generateCuXmlForOrderStatus($existingOrder);
                        $str .= "<Info>Order not processing</Info>\n";

                        if ($cuXml) {
                            $str .= "<Info>Sending message to CU</Info>\n";
                            $cuXml .= "<ShipmentDate>" . date("c") . "</ShipmentDate>\n";
                            $this->helper->postToChannelUnity($cuXml, 'OrderStatusUpdate');
                        } else {
                            $str .= "<Info>Not sending to CU</Info>\n";
                        }
                    } else {
                        $str .= "<Info>Order is processing</Info>\n";
                    }
                }
            }
        }
        $str .= "</ShipmentCheck>\n";
        return $str;
    }

    private function getRegionId($countryCode, $stateName)
    {
        $firstRegionId = 'N/A';
        $countries = $this->countryInformationAcquirer->getCountriesInfo();

        foreach ($countries as $country) {
            if ($country->getTwoLetterAbbreviation() != $countryCode) {
                continue;
            }

            $countryId = $country->getId();
            $bRegionRequired = $this->isRegionRequired($countryId);

            if (!$bRegionRequired) {
                return "N/A";
            }

            if ($availableRegions = $country->getAvailableRegions()) {
                foreach ($availableRegions as $region) {
                    if (!is_numeric($firstRegionId)) {
                        $firstRegionId = $region->getId();
                    }

                    if (strtolower($region->getName()) == strtolower($stateName)
                        || strtolower($region->getCode()) == strtolower($stateName)) {
                        return $region->getId();
                    }
                }
            }
            break;
        }
        return $firstRegionId;
    }

    private function countryCodeToId($countryCode)
    {
        $countryId = 0;
        $countries = $this->countryInformationAcquirer->getCountriesInfo();
        foreach ($countries as $country) {
            if ($country->getTwoLetterAbbreviation() != $countryCode) {
                continue;
            }
            $countryId = $country->getId();
            break;
        }
        return $countryId;
    }

    private function isRegionRequired($countryId)
    {
        return is_object($this->directoryHelper) && $this->directoryHelper->isRegionRequired($countryId);
    }

    private function checkOrderImportHistory($remoteOrderId, $subscriptionId)
    {
        $bOrderFound = false;
        try {
            $orderImportHistoryTable = $this->resource->getTableName('order_import_history');
            $connection = $this->resource->getConnection(\Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION);

            $select = $connection->select()
                ->from(
                    ['oih' => $orderImportHistoryTable],
                    ['remote_order_id', 'subscription_id']
                )
                ->where('remote_order_id = ?', $remoteOrderId)
                ->where('subscription_id = ?', $subscriptionId)
                ->limit(1);

            $data = $connection->fetchAll($select);

            if (is_array($data)) {
                $bOrderFound = count($data) > 0;
            }
        } catch (\Exception $e) {
            // Silently handle exception based on original intent
        }
        return $bOrderFound;
    }

    private function logOrderImportHistory($remoteOrderId, $subscriptionId) {
        try {
            $orderImportHistoryTable = $this->resource->getTableName('order_import_history');
            $connection = $this->resource->getConnection(\Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION);
            $connection->insert($orderImportHistoryTable, [
                'remote_order_id' => $remoteOrderId,
                'subscription_id' => $subscriptionId,
                'created_at' => date("Y-m-d H:i:s")
            ]);
        } catch (\Exception $e) {
        }
    }

    private function deleteOrderImportHistory($remoteOrderId, $subscriptionId) {
        try {
            $orderImportHistoryTable = $this->resource->getTableName('order_import_history');
            $connection = $this->resource->getConnection(\Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION);

            // Security Fix: Prevent SQL injection by using parameterized conditions
            $connection->delete($orderImportHistoryTable, [
                'remote_order_id = ?' => $remoteOrderId,
                'subscription_id = ?' => $subscriptionId
            ]);

        } catch (\Exception $e) {
        }
    }
}