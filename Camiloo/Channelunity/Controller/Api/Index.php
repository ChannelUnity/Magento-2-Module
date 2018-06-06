<?php
/**
 * ChannelUnity connector for Magento Commerce
 *
 * @category   Camiloo
 * @package    Camiloo_Channelunity
 * @copyright  Copyright (c) 2016-2017 ChannelUnity Limited (http://www.channelunity.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Camiloo\Channelunity\Controller\Api;

use Camiloo\Channelunity\Model\Products;
use Camiloo\Channelunity\Model\Orders;
use Camiloo\Channelunity\Model\Refunds;
use Camiloo\Channelunity\Model\Stores;
use Camiloo\Channelunity\Model\Helper;
use Camiloo\Channelunity\Model\Categories;
use Camiloo\Channelunity\Model\Customers;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Registry;

/**
 * Endpoint for the ChannelUnity module.
 * http://<URL>/channelunity/api/index
 *
 * This module is tested with the following versions of Magento 2:
 * 2.0.7, 2.1.7, 2.2.3
 */
class Index extends \Magento\Framework\App\Action\Action
{
    private $helper;
    private $cuproducts = null;
    private $cuorders = null;
    private $curefunds = null;
    private $custores = null;
    private $cucategories = null;
    private $cucustomers = null;
    private $rawResultFactory = null;
    private $registry = null;

    public function __construct(
        Context $context,
        Helper $helper,
        Products $cuproducts,
        Orders $cuorders,
        Refunds $curefunds,
        Stores $custores,
        Categories $cucategories,
        Customers $cucustomers,
        Registry $registry
    ) {
        parent::__construct($context);
        $this->helper = $helper;
        $this->cuproducts = $cuproducts;
        $this->cuorders = $cuorders;
        $this->curefunds = $curefunds;
        $this->custores = $custores;
        $this->cucategories = $cucategories;
        $this->cucustomers = $cucustomers;
        $this->rawResultFactory = $context->getResultFactory();
        $this->registry = $registry;
    }

    /**
     * This is the main API endpoint for the connector module.
     * It will verify the request then pass it onto the relevant model.
     */
    public function execute()
    {
        error_reporting(E_ALL);
        $xml = $this->getRequest()->getPost('xml');
        $testmode = $this->getRequest()->getPost('testmode') == 'yes';
        
        $result = $this->rawResultFactory->create(ResultFactory::TYPE_RAW);
        $result->setHeader('Content-Type', 'text/xml');

        if (!isset($xml)) {
            $str = $this->terminate("Error - could not find XML within request");
        } else {
            try {
                $str = $this->doApiProcess(urldecode($xml), $testmode);
            } catch (\Exception $e) {
                $str = $this->terminate("Error - doApiProcess - ".$e->getMessage());
                
                $this->helper->logError($e->getMessage()."-".$e->getTraceAsString());
            }
        }
        $result->setContents($str);
        return $result;
    }

    /**
     * Issue a short XML message to signal an error occurred with our API call.
     * @param string $message The error message
     */
    private function terminate($message)
    {
        $str = '<?xml version="1.0" encoding="utf-8" ?>';
        $str .= '	<ChannelUnity>';
        $str .= '        <Status>' . $message . '</Status>';
        $str .= '  </ChannelUnity>';
        return $str;
    }

    private function doApiProcess($xmlRaw, $testMode = false)
    {
        $xml = simplexml_load_string($xmlRaw, 'SimpleXMLElement', LIBXML_NOCDATA);
        
        if (!$testMode) {
            $payload = trim((string) $xml->Notification->Payload);

            if ($payload != '') {
                // Call home to verify the request is genuine
                $request = $this->helper->verifypost($payload);
            } else {
                $request = "";
            }
        } else {
            $request = $xml->Notification->Payload;
        }
        
        // RequestHeader contains the request type
        $type = (string) $xml->Notification->Type;

        $str = '<?xml version="1.0" encoding="utf-8" ?>';
        $str .= '	<ChannelUnity>';
        $str .= '    <RequestType>' . $type . '</RequestType>';

        switch ($type) {
            case "Ping":
                $str .= $this->helper->verifyMyself();
                break;

            case "GetAllSKUs":
                $str .= $this->cuproducts->getAllSKUs();
                break;

            case "OrderNotification":
                $str .= $this->cuorders->doUpdate($request);
                break;
            
            case "PartialRefundNotification":{
                
                $this->registry->unregister('cu_partial_refund');
                $this->registry->register('cu_partial_refund', 'active');
                $str .= $this->curefunds->partialRefund($request);
                
                $this->registry->unregister('cu_partial_refund');
                break;
            }

            case "ProductData":
                $this->cuproducts->postAttributesToCU();
                $str .= $this->cuproducts->doRead($request);
                break;

            case "ProductDataDelta":
                $str .= $this->cuorders->shipmentCheck($request);
                break;

            case "CartDataRequest":
                // get URL out of the CartDataRequest
                $myStoreURL = $xml->Notification->URL;
                $storeStatus = $this->custores->postStoresToCU($myStoreURL);
                $categoryStatus = $this->cucategories->postCategoriesToCU($myStoreURL);
                $attributeStatus = $this->cuproducts->postAttributesToCU();

                $str .= "<StoreStatus>$storeStatus</StoreStatus>";
                $str .= "<CategoryStatus>$categoryStatus</CategoryStatus>";
                $str .= "<ProductAttributeStatus>$attributeStatus</ProductAttributeStatus>";

                break;
            
            case "ValidateCustomerDetails":
                $customerId = $this->cucustomers->validateCustomer($request->EmailAddress, $request->Password);
                $str .= "<CustomerID>$customerId</CustomerID>";
                break;
            
            case "CreateCustomerAccount":
                $customerId = $this->cucustomers->createCustomer($request->EmailAddress, $request->Password, $request->FirstName, $request->LastName);
                $str .= "<CustomerID>$customerId</CustomerID>";
                break;
            
            case "ResetCustomerPassword":
                $this->cucustomers->resetPassword($request->EmailAddress);
                $str .= "<Status>OK</Status>";
                break;
        }

        $str .= '  </ChannelUnity>';
        return $str;
    }
}
