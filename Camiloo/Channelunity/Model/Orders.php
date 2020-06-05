<?php
/**
 * ChannelUnity connector for Magento Commerce
 *
 * @category   Camiloo
 * @package    Camiloo_Channelunity
 * @copyright  Copyright (c) 2016-2017 ChannelUnity Limited (http://www.channelunity.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */


/*
  RequestType	OrderStatusUpdate

  Payload	XML Message
  <SubscriptionID />  The corresponding ChannelUnity subscription ID
  <OrderID />  The channel specific order ID being shipped
  <OrderStatus /> The new order status

  If being shipped / completed:
  <ShipmentDate />  The date and time the item was shipped
  <CarrierName />  The name of the delivery company
  <ShipmentMethod /> The shipping method used
  <TrackingNumber />  The tracking number for the shipment
 */

namespace Camiloo\Channelunity\Model;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Registry;
use Magento\Framework\Data\Form\FormKey;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductFactory;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteManagement;
use Magento\Quote\Model\Quote\Address\Rate;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\CatalogInventory\Model\ResourceModel\Stock\Item;
use Magento\Sales\Model\Service\OrderService;
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
    private $quote;
    private $quoteManagement;
    private $transaction;
    private $orderService;
    private $storeManager;
    private $formkey;
    private $helper;
    private $stockItemResource;
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
        FormKey $formkey,
        QuoteFactory $quote,
        QuoteManagement $quoteManagement,
        CustomerFactory $customerFactory,
        CustomerRepositoryInterface $customerRepository,
        OrderService $orderService,
        Transaction $transaction,
        Item $stockItemResource,
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
        $this->formkey = $formkey;
        $this->quote = $quote;
        $this->quoteManagement = $quoteManagement;
        $this->customerFactory = $customerFactory;
        $this->customerRepository = $customerRepository;
        $this->orderService = $orderService;
        $this->transaction = $transaction;
        $this->stockItemResource = $stockItemResource;
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
        // Map a Magento order state to a CU order status
        
        $orderStatusMapping =
                [
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
        
        if (array_key_exists($order->getState(), $orderStatusMapping)) {
            $orderStatus = $orderStatusMapping[$order->getState()];
        } else {
            $orderStatus = "Processing";
        }
        
        // Needs to use the payment additional information
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

        // We are only interested in CU imported orders here
        if (!$isCu) {
            return false;
        }

        $orderXml .= "<OrderStatus>$orderStatus</OrderStatus>\n";

        return $orderXml;
    }

    public function generateCuXmlForOrderShip($order, $carrierName, $shipMethod, $trackNumber)
    {
        $orderXml = $this->generateCuXmlForOrderStatus($order);

        if (!empty($orderXml) && $order->getState() == 'complete') {
            $orderXml .= "<ShipmentDate>" . date("c") . "</ShipmentDate>\n";
            $orderXml .= "<CarrierName><![CDATA[$carrierName]]></CarrierName>\n";
            $orderXml .= "<ShipmentMethod><![CDATA[$shipMethod]]></ShipmentMethod>\n";
            $orderXml .= "<TrackingNumber><![CDATA[$trackNumber]]></TrackingNumber>\n";
        }

        return $orderXml;
    }
    
    public function generateCuXmlForPartialAction($order, $actionItems, $shippingAmount)
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

    public function fixEncoding($in_str)
    {
        if (function_exists('mb_strlen')) {
            $cur_encoding = mb_detect_encoding($in_str);
            if (!($cur_encoding == "UTF-8" && mb_check_encoding($in_str, "UTF-8"))) {
                $in_str = utf8_encode($in_str);
            }
        }

        return $in_str;
    }

    /**
     * Creates a new order in Magento based on given ChannelUnity order data.
     * @param type $dataArray
     * @param type $order
     * @return string
     * @throws LocalizedException
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function doCreate($dataArray, $order)
    {
        $str = "";
        // Log that we've tried to create this order
        $orderId = trim((string) $order->OrderId);
        $bFoundOrder = $this->checkOrderImportHistory((string) $order->OrderId, 
            (int) $dataArray->SubscriptionId);
        
        // Allow it through if we're forcing it
        
        $forceRetry = isset($order->OrderFlags) 
            && strpos((string) $order->OrderFlags, 'FORCE_RETRY') !== false;
        
        if ($bFoundOrder && !$forceRetry) {
            $str .= "<Exception>Order blocked, we already tried to create this</Exception>\n";
            $str .= "<NotImported>$orderId</NotImported>\n";
            return $str;
        }
        
        $this->logOrderImportHistory((string) $order->OrderId, 
            (int) $dataArray->SubscriptionId);
        
        $store = $this->storeManager->getStore((int) $dataArray->StoreviewId);
        $conversionRate = null;
        $forceTax = $this->helper->forceTaxValues();
        
        $this->helper->logInfo("Order creation. Force Tax? $forceTax");
        $websiteId = $store->getWebsiteId();
        
        // Construct shipping and billing addresses
        $shippingInfo = $order->ShippingInfo;
        $billingInfo = $order->BillingInfo;
        
        $firstNameS = $this->getFirstName((string) $shippingInfo->RecipientName);
        $lastNameS = $this->getLastName((string) $shippingInfo->RecipientName);
        $firstNameB = $this->getFirstName((string) $billingInfo->Name);
        $lastNameB = $this->getLastName((string) $billingInfo->Name);
        $emailAddress = (string) $billingInfo->Email;
        if (!$emailAddress) {
            $emailAddress = (string) $shippingInfo->Email;
        }
        
        // Check for a customer record
        $customer = $this->customerFactory->create();
        $customer->setWebsiteId($websiteId);
        $customer->loadByEmail($emailAddress);
        
        if (!$customer->getEntityId()) {
            // Create a new customer record
            $customer->setWebsiteId($websiteId)
                    ->setStore($store)
                    ->setFirstname($firstNameS)
                    ->setLastname($lastNameS)
                    ->setEmail($emailAddress)
                    ->setPassword(uniqid());
            $customer->save();
        }
        
        $this->helper->logInfo("Creating quote");
        if (version_compare($this->helper->getMagentoVersion(), '2.1.0') >= 0) {
            // Magento 2.1 requires the use of cart management interface
            $cartId = $this->cartManagementInterface->createEmptyCart();
            $quote = $this->cartRepositoryInterface->get($cartId);
        } else {
            // Create a new quote
            $quote = $this->quote->create();
        }
        $quote->setStore($store); // Set store for which we are creating the order
        $customerEntityId = $customer->getEntityId();
        $customer = $this->customerRepository->getById($customerEntityId);
        $quote->setCurrency();
        $quote->assignCustomer($customer);
        
        // Compare currency of incoming order to currency of destination store
        $destCurrency = $store->getCurrentCurrency()->getCode();
        $sourceCurrency = (string) $order->Currency;
        
        $this->helper->logInfo("doCreate: source currency $sourceCurrency, destination $destCurrency");
        
        if ($sourceCurrency != $destCurrency) {
            // Check the source currency is available
            $allowedCurrs = $store->getAvailableCurrencyCodes();
            $bCurrencyAvailable = false;
            
            foreach ($allowedCurrs as $allowedCurr) {
                if ($allowedCurr == $sourceCurrency) {
                    $bCurrencyAvailable = true;
                    break;
                }
            }
            if (!$bCurrencyAvailable) {
                throw new LocalizedException(__("The order cannot be imported because the currency $sourceCurrency is not available"));
            }

            $currency = $this->currencyFactory->create()->load($sourceCurrency);
            $conversionRate = $store->getCurrentCurrency()->getRate($sourceCurrency);
            $quote->getStore()->setData('current_currency', $currency);
            
            $this->helper->logInfo("doCreate: Currency conversion rate $conversionRate");
        }
        
        if ($conversionRate != null) {
            // Set conversion rate in the registry so that the tax
            // override code can convert the currency properly
            
            $this->registry->unregister('cu_conversion_rate');
            $this->registry->register('cu_conversion_rate', $conversionRate);
        } else {
            $this->registry->unregister('cu_conversion_rate');
            $this->registry->register('cu_conversion_rate', 1);
        }
        // Get the shipping tax ready for the tax intercept code
        $this->registry->unregister('cu_shipping_tax');
        $this->registry->register('cu_shipping_tax', (float) $shippingInfo->ShippingTax);
        
        if (version_compare($this->helper->getMagentoVersion(), '2.1.0') >= 0) {
            // Magento 2.1+
            $quote->save();
        }
        // Add items to the quote
        $itemSeq = 1;
        foreach ($order->OrderItems->Item as $item) {
            $this->helper->logInfo("doCreate: Line ".__LINE__."");
            $this->registry->unregister('cu_sequence-'.$itemSeq);
            $this->registry->register('cu_sequence-'.$itemSeq, $item);
            //
            $productPrice = $conversionRate != null && $conversionRate > 0 ? (float)$item->Price/$conversionRate : (float)$item->Price;
            
            // If the product is a bundled product, need to do some extra setup
            $skuToSearch = (string) $item->SKU;
            $bundleSKUComponents = array();
            if (strpos($skuToSearch, '^') !== false) {
                // We have a bundle product SKU
                $bundleSKUComponents = explode('^', $skuToSearch);
                $skuToSearch = $bundleSKUComponents[0];
            }
            
            $searchCriteria = $this->searchCriteriaBuilder->addFilter(
                (string) $dataArray->SkuAttribute,
                $skuToSearch,
                'eq'
            )->create();
            
            $this->helper->logInfo("doCreate: Line ".__LINE__."");
            $existingProducts = $this->productRepository->getList($searchCriteria);
            
            $itemArray = $existingProducts->getItems();
            $this->helper->logInfo("doCreate: Line ".__LINE__."");
            
            if (count($itemArray) == 0) {
                $this->helper->logInfo("doCreate: Product not found in Magento");
                
                // Check whether or not we want stub products
                if ($this->helper->allowStubProducts()) {
                    $this->helper->logInfo("doCreate: Creating stub product now");
                    $itemArray = [$this->createStubProduct($item, $productPrice)];
                } else {
                    $this->helper->logInfo("doCreate: Stub products not allowed");
                    throw new LocalizedException(__("ProductNotFound"));
                }
            }
            $this->helper->logInfo("doCreate: Line ".__LINE__."");
            
            foreach ($itemArray as $product) {
                $this->helper->logInfo("doCreate: Line ".__LINE__."");
                
                $product->setPrice($productPrice);
                $product->setSpecialPrice($productPrice);
                $product->setName($item->Name);
                
                $productParameters = $this->dataObjectFactory->create();
                $productParameters->setData('product', $product->getId());
                $productParameters->setData('qty', (int) $item->Quantity);
                
                if ($bundleSKUComponents) {
                    $this->helper->logInfo("Bundle product $skuToSearch");
                    // Add in our bundle options structure
                    
                    $productsArray = [];
                    $optionIdToPosition = [];
                    
                    $productTypeObj = $product->getTypeInstance(true);
        
                    $selectionCollection = $productTypeObj
                        ->getSelectionsCollection(
                            $productTypeObj->getOptionsIds($product),
                            $product
                        );
        
                    foreach ($selectionCollection as $pselection) {

                        if (array_key_exists($pselection->getOptionId(), $optionIdToPosition)) {
                            $position = $optionIdToPosition[$pselection->getOptionId()];
                        }
                        else {
                            $position = count($optionIdToPosition)+1;
                            $optionIdToPosition[$pselection->getOptionId()] = $position;
                        }
                        $selectionArray = [];
                        $selectionArray['product_name'] = $pselection->getName();
                        $selectionArray['product_price'] = $pselection->getPrice();
                        $selectionArray['product_qty'] = $pselection->getSelectionQty();
                        $selectionArray['product_id'] = $pselection->getProductId();
                        $selectionArray['product_sku'] = $pselection->getSku();
                        
                        $productsArray[$pselection->getOptionId()][$pselection->getSelectionId()] = $selectionArray;
                    }
                    $this->helper->logInfo(var_export($productsArray, true));
                    
                    $bundleOptions = [];
                    $optionPosition = 1;
                    foreach ($optionIdToPosition as $optionId => $position) {
                        $skuAtPosition = $bundleSKUComponents[$position];
                        
                        foreach ($productsArray[$optionId] as $selectionId => $selectionArray) {
                        
                            // Find the selection ID that we need...
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
                $quote->addProduct($product, $productParameters);
                $itemSeq++;
                break; // There should only be 1 result anyway
            }
        }
        
        $address = (string) $shippingInfo->Address1;
        $address2 = (string) $shippingInfo->Address2;
        $address3 = (string) $shippingInfo->Address3;
        
        $addressArray = [];
        $addressArray[] = $address;
        
        if ($address2 != '') {
            $addressArray[] = $address2;
        }
        if ($address3 != '') {
            $addressArray[] = $address3;
        }
        
        // If the state/region is required but none is given,
        // put something in.
        $state = (string) $shippingInfo->State;
        $countryId = $this->countryCodeToId((string) $shippingInfo->Country);
        if (!$state && $this->isRegionRequired($countryId)) {
            $state = "N/A";
        }
        
        $shippingAddress = [
            'firstname' => (string) $firstNameS,
            'lastname' => (string) $lastNameS,
            'street' => $addressArray,
            'city' => (string) $shippingInfo->City,
            'country_id' => (string) $shippingInfo->Country,
            'region' => $state,
            'postcode' => (string) $shippingInfo->PostalCode,
            'telephone' => (string) $shippingInfo->PhoneNumber,
            'save_in_address_book' => 0
        ];
        $billingAddress = [
            'firstname' => (string) $firstNameB,
            'lastname' => (string) $lastNameB,
            'street' => $addressArray,
            'city' => (string) $shippingInfo->City,
            'country_id' => (string) $shippingInfo->Country,
            'region' => $state,
            'postcode' => (string) $shippingInfo->PostalCode,
            'telephone' => (string) $billingInfo->PhoneNumber,
            'save_in_address_book' => 0
        ];
        
        $regionId = $this->getRegionId((string) $shippingInfo->Country, $state);
        
        if (is_numeric($regionId)) {
            // We found a region ID -- add it in
            $shippingAddress['region_id'] = $regionId;
            $billingAddress['region_id'] = $regionId;
        }
        
        $quote->getBillingAddress()->addData($billingAddress);
        $quote->getShippingAddress()->addData($shippingAddress);
        // Setting variables for the CU shipping class to use
        $this->registry->unregister('cu_shipping_method');
        $this->registry->register('cu_shipping_method', (string) $order->ShippingInfo->Service);
        $this->registry->unregister('cu_shipping_price');
        $this->registry->register('cu_shipping_price', $conversionRate != null
                ? (float) $order->ShippingInfo->ShippingPrice/$conversionRate
                : (float) $order->ShippingInfo->ShippingPrice);

        $this->helper->logInfo("doCreate: Set shipping and payment method");

        $quote->getShippingAddress()
                ->setCollectShippingRates(true)
                ->collectShippingRates()
                ->setShippingMethod('cucustomrate_cucustomrate');

        $this->helper->logInfo("doCreate: ".__LINE__." order");
        
        // We need a custom payment method because the payment was taken
        // on the marketplace website
        $quote->setPaymentMethod('ChannelUnitypayment');
        
        $this->helper->logInfo("doCreate: ".__LINE__." order");
        // Set Inventory Processed to true means that stock won't be decreased
        // We don't want to decrease stock if the Ignore FBA order stock
        // setting is set, and the current order is an FBA order
        
        $quote->setInventoryProcessed($this->bIsInventoryProcessed);
        
        if (version_compare($this->helper->getMagentoVersion(), '2.1.0') >= 0) {
            $this->helper->logInfo("doCreate: ".__LINE__." order");
        } else {
            $this->helper->logInfo("doCreate: ".__LINE__." order");
            $quote->save();
        }
        // Set the payment method
        $this->helper->logInfo("doCreate: ".__LINE__." order");
        $quote->getPayment()->importData(['method' => 'channelunitypayment']);
        
        $quote->getPayment()->setAdditionalInformation([
            'subscription_id' => (string) $dataArray->SubscriptionId,
            'service_sku' => (string) $order->ServiceSku,
            'order_flags' => (string) $order->OrderFlags
        ]);
        
        $this->helper->logInfo("doCreate: ".__LINE__." order");
        
        if (version_compare($this->helper->getMagentoVersion(), '2.1.0') >= 0) {
            // Collect totals only
            $quote->collectTotals();
        } else {
            // Collect totals and save quote
            $quote->collectTotals()->save();
        }
        // Now submit the order depending on the Magento version
        $this->helper->logInfo("doCreate: Now submitting order");
        
        if (is_object($this->cartManagementInterface)
                && version_compare($this->helper->getMagentoVersion(), '2.1.0') >= 0) {
            // Magento 2.1+
            $this->cartRepositoryInterface->save($quote);
            $quote = $this->cartRepositoryInterface->get($quote->getId());
            
            $this->helper->logInfo("doCreate: Line ".__LINE__."");
            $newOrder = $this->cartManagementInterface->submit($quote);
        } else {
            // Magento < 2.1
            $newOrder = $this->quoteManagement->submit($quote);
        }

        if (is_object($newOrder) && $newOrder->getEntityId()) {
            $entityId = $newOrder->getEntityId();
            // Save the marketplace order number
            $newOrder->setIncrementId($order->OrderId);
            
            $this->createInvoice($newOrder);
            
            //////////////////////////////////////////////////////////////////
            
            if (isset($order->ShippingInfo->GiftMessage)) {
                $this->helper->logInfo("Gift message is present");
                
                try {
                    $giftMessage = $this->giftMessageFactory->create();
                    $giftMessage->setMessage((string) $order->ShippingInfo->GiftMessage);
                    $giftMessage->save();
                    
                    $newOrder->setData('gift_message_id', $giftMessage->getGiftMessageId());
                } catch (\Exception $e) {
                    $msg = $e->getMessage();
                    $str .= "<Info><![CDATA[Gift message error: $msg]]></Info>\n";
                }
                $this->helper->logInfo("Gift message complete");
            }
            //////////////////////////////////////////////////////////////////
            // Set status of the created Magento order
            $this->doSingleOrder($order, $newOrder, false);
            
            $str .= "<Info>We created order ID $entityId</Info>\n";
            $str .= "<Imported>$order->OrderId</Imported>\n";
            
            $newOrder->setEmailSent(0);
            $newOrder->addStatusHistoryComment('Order imported by ChannelUnity', false);
            
            // Add delivery instructions
            $strDeliveryInstr = (string) $order->DeliveryInstructions;
            if ($strDeliveryInstr != '') {
                $newOrder->addStatusHistoryComment('Delivery Instructions: '.$strDeliveryInstr, false);
            }
            
            $newOrder->setCreatedAt((string) $order->PurchaseDate);

            $newOrder->save();
        } else {
            $str .= "<Info>It seems the order didn't create</Info>\n";
        }
        // Normal return path, no need to retain the order history
        $this->deleteOrderImportHistory((string) $order->OrderId, 
            (int) $dataArray->SubscriptionId);

        return $str;
    }
    
    /**
     * Creates an invoice for an imported order so we can mark it as paid.
     * @param type $order
     * @return type
     */
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
    
    /**
     * Creates a new product in Magento to allow an order to import.
     * @param type $item
     * @return type
     */
    private function createStubProduct($item, $productPrice)
    {
        // Products belong to websites, so let's get the current website ID
        $websiteId = $this->storeManager->getWebsite()->getWebsiteId();
        $defaultAttributeSet = $this->product->getDefaultAttributeSetId();

        $product = $this->productFactory->create();
        $product->setWebsiteIds([$websiteId]);
        $product->setSku((string)$item->SKU);
        $product->setName((string)$item->Name);
        $product->setDescription((string)$item->Name);
        $product->setStatus(1);
        $product->setAttributeSetId($defaultAttributeSet);
        $product->setVisibility(1);
        $product->setTaxClassId(0);
        $product->setTypeId('simple');
        $product->setPrice($productPrice);
        $product->setStockData([
                'use_config_manage_stock' => 0,
                'manage_stock' => 0,
                'is_in_stock' => 1,
                'qty' => (int)$item->Quantity
            ]);

        $newProduct = $this->productRepository->save($product);
        // -------- Now load the product fresh --------
        
        return $this->productRepository->getById($newProduct->getId());
    }

    /**
     * Extracts the first name from a full name.
     * @param type $name
     * @return type
     */
    private function getFirstName($name)
    {
        $lastSpacePos = strrpos($name, " ");
        if ($lastSpacePos !== false) {
            return substr($name, 0, $lastSpacePos);
        } else {
            return $name;
        }
    }

    /**
     * Extracts the last name from a full name.
     * @param type $name
     * @return string
     */
    private function getLastName($name)
    {
        $exp = explode(" ", $name);
        if (count($exp) > 1) {
            return $exp[count($exp) - 1];
        } else {
            return "___";
        }
    }

    public function CUOrderStatusToMagentoStatus($orderStatus)
    {
        if ($orderStatus == 'Processing') {
            $orderStatus = "processing";
        } elseif ($orderStatus == 'OnHold') {
            $orderStatus = "holded";
        } elseif ($orderStatus == 'Complete') {
            $orderStatus = "complete";
        } else {
            $orderStatus = "canceled";
        }

        return $orderStatus;
    }

    private function doSingleOrder($singleOrder, \Magento\Sales\Model\Order $newOrder, $bSaveOrder = true)
    {
        // Update order status
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

    public function reserveStock($dataArray, $order, $multiplier = -1)
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
                    $this->helper->logInfo("Reserve stock: Product found");
                    
                    $productId = $product->getEntityId();

                    $stockItem = $this->stockRegistry->getStockItem($productId);

                    $stockItem->setQtyCorrection($multiplier * $qty);
                    $this->stockRegistry->updateStockItemBySku($product->getSku(), $stockItem);
                    
                    $this->helper->logInfo("Qty saved");
                } else {
                    $this->helper->logInfo("Reserve stock: Product not found");
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
        $this->helper->logInfo("Orders::doUpdate() called, " . var_export($dataArray, true));

        foreach ($dataArray->Orders->Order as $order) {
            $orderId = trim((string) $order->OrderId);
            
            // Check to see if this order has already been imported
            
            $searchCriteria = $this->searchCriteriaBuilder
                    ->addFilter('increment_id', $orderId, 'eq')->create();

            $orderList = $this->orderRepository->getList($searchCriteria);
            
            $bOrderExisted = $orderList->getSize() > 0;
            $this->helper->logInfo("Does order already exist? $bOrderExisted");
            
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
                    // if the stock has been held, release it

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
                    // Reserve the stock, if not already reserved
                    
                    $str .= "<Imported>$orderId</Imported>\n";
                    
                    if (!isset($order->StockReservedCart) || ((string) $order->StockReservedCart) == "0") {
                        $str .= "<StockReserved>$orderId</StockReserved>\n";

                        if (!$orderIsFba || !$ignoreQty) {
                            $this->reserveStock($dataArray, $order);
                        }
                    }
                } else {
                    // Let's not create cancelled orders !!! We don't have all the details
                    if ("Cancelled" != (string) $order->OrderStatus) {
                        // Just create the order (e.g. previously completed)
                        try {
                            $this->registry->register('cu_order_in_progress', 1, true);
                            $str .= $this->doCreate($dataArray, $order);
                        } catch (\Exception $e) {
                            $str .= "<Exception>".$e->getMessage()."</Exception>\n";
                            $str .= "<NotImported>$orderId</NotImported>\n";
                        }

                        $this->registry->unregister('cu_order_in_progress');
                    } else {
                        // if the stock has been held, release it

                        if (isset($order->StockReservedCart) && ((string) $order->StockReservedCart) == "1") {
                            $str .= "<StockReleased>$orderId</StockReleased>\n";

                            if (!$orderIsFba || !$ignoreQty) {
                                $this->releaseStock($dataArray, $order);
                            }
                        }
                        // Have this order marked as imported anyway
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
                    // Put back our stock
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
    
    /**
     * Called from ChannelUnity to ask for the status of orders it thinks
     * are currently processing. If the orders are now shipped, then a
     * shipment message is generated and posted back to CU.
     * @param type $request
     */
    public function shipmentCheck($request)
    {
        $str = "<ShipmentCheck>\n";
        if (isset($request->OrderIdsToCheck)) {
            foreach ($request->OrderIdsToCheck->OrderId as $orderId) {
                $str .= "<Info>Checking order '$orderId'</Info>\n";
                
                // Now find and load the order in question
                // to check if it's been shipped yet

                $searchCriteria = $this->searchCriteriaBuilder
                        ->addFilter('increment_id', $orderId, 'eq')->create();

                $orderList = $this->orderRepository->getList($searchCriteria);
                $olist = $orderList->getItems();
                foreach ($olist as $existingOrder) {
                    // Yes we found an order
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
    
    /**
     * Find a region ID code based on a country and a state.
     * If not found it will return the state name.
     *
     * @param type $countryCode
     * @param type $stateName
     * @return type
     */
    private function getRegionId($countryCode, $stateName)
    {
        $firstRegionId = 'N/A';
        $this->helper->logInfo("getRegionId: ".$countryCode." / ".$stateName);
        
        $countries = $this->countryInformationAcquirer->getCountriesInfo();

        foreach ($countries as $country) {
            if ($country->getTwoLetterAbbreviation() != $countryCode) {
                continue;
            }
            
            $countryId = $country->getId();
            $this->helper->logInfo("Country ID: $countryId");

            // See if region is required
            $bRegionRequired = $this->isRegionRequired($countryId);
            if (!$bRegionRequired) {
                $this->helper->logInfo("getRegionId: No region required");
                
                return "N/A";
            }
            
            if ($availableRegions = $country->getAvailableRegions()) {
                foreach ($availableRegions as $region) {
                    if (!is_numeric($firstRegionId)) {
                        $firstRegionId = $region->getId();
                    }
                    
                    $this->helper->logInfo("getRegionId: ".$region->getName()." / ".$region->getId());
                    
					if (strtolower($region->getName()) == strtolower($stateName)
		                    || strtolower($region->getCode()) == strtolower($stateName)) {
                        return $region->getId();
                    }
                }
            }
            break;
        }
        $this->helper->logInfo("getRegionId: No region ID found");
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

    /**
     * Returns true if state or region is required for the given country.
     * Can be set as such in the Magento configuration settings.
     * @param type $countryId
     * @return type
     */
    private function isRegionRequired($countryId)
    {
        return is_object($this->directoryHelper) && $this->directoryHelper->isRegionRequired($countryId);
    }
    
    /**
     * Returns true if the given order number and subscription ID combination
     * has been seen before which would indicate the order has already been
     * tried to be imported, false otherwise.
     * @param type $remoteOrderId
     * @param type $subscriptionId
     */
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
        }
        return $bOrderFound;
    }
    
    /**
     * Store an order number in the order import history.
     * @param type $remoteOrderId
     * @param type $subscriptionId
     */
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
    
    /**
     * Deletes an order number from the order import history.
     * @param type $remoteOrderId
     * @param type $subscriptionId
     */
    private function deleteOrderImportHistory($remoteOrderId, $subscriptionId) {
        try {
            $orderImportHistoryTable = $this->resource->getTableName('order_import_history');
            $connection = $this->resource->getConnection(\Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION);
            $connection->delete($orderImportHistoryTable, 
                "remote_order_id = '$remoteOrderId' and subscription_id = '$subscriptionId'");
        } catch (\Exception $e) {
        }
    }
}
