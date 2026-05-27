<?php
/**
 * ChannelUnity connector for Magento Commerce
 *
 * @category   Camiloo
 * @package    Camiloo_Channelunity
 * @copyright  Copyright (c) 2016-2024 ChannelUnity Limited (http://www.channelunity.com)
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
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;

/**
 * Endpoint for the ChannelUnity module.
 * http://<URL>/channelunity/api/index
 *
 * This module is tested with Magento 2.x
 */
class Index extends \Magento\Framework\App\Action\Action implements CsrfAwareActionInterface
{
    private $helper;
    private $cuproducts;
    private $cuorders;
    private $curefunds;
    private $custores;
    private $cucategories;
    private $cucustomers;
    private $rawResultFactory;
    private $registry;

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
     * Bypasses CSRF validation for this API endpoint.
     * Required for Magento 2.3+ compatibility with external POST requests.
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Confirms that CSRF validation should be skipped for this API endpoint.
     * Required for Magento 2.3+ compatibility.
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * This is the main API endpoint for the connector module.
     * It will verify the request then pass it onto the relevant model.
     */
    public function execute()
    {
        $xml = $this->getRequest()->getPost('xml');
        $testmode = $this->getRequest()->getPost('testmode') == 'yes';

        $result = $this->rawResultFactory->create(ResultFactory::TYPE_RAW);
        $result->setHeader('Content-Type', 'text/xml');

        if (empty($xml)) {
            $str = $this->terminate("Error - could not find XML within request");
        } else {
            try {
                $str = $this->doApiProcess(urldecode($xml), $testmode);
            } catch (\Exception $e) {
                $str = $this->terminate("Error - doApiProcess - " . $e->getMessage());
                $this->helper->logError($e->getMessage() . " - " . $e->getTraceAsString());
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
        $str .= '<ChannelUnity>';
        $str .= '<Status><![CDATA[' . $message . ']]></Status>';
        $str .= '</ChannelUnity>';

        return $str;
    }

    private function doApiProcess($xmlRaw, $testMode = false)
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlRaw, 'SimpleXMLElement', LIBXML_NOCDATA);

        if ($xml === false) {
            throw new \Exception("Invalid XML payload received");
        }

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
        $str .= '<ChannelUnity>';
        $str .= '<RequestType>' . $type . '</RequestType>';

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

            case "PartialRefundNotification":
                $this->registry->unregister('cu_partial_refund');
                $this->registry->register('cu_partial_refund', 'active');

                $str .= $this->curefunds->partialRefund($request);

                $this->registry->unregister('cu_partial_refund');
                break;

            case "ProductData":
                $this->cuproducts->postAttributesToCU();
                $str .= $this->cuproducts->doRead($request);
                break;

            case "ProductDataDelta":
                $str .= $this->cuorders->shipmentCheck($request);
                break;

            case "CartDataRequest":
                // get URL out of the CartDataRequest
                $myStoreURL = (string) $xml->Notification->URL;
                $storeStatus = $this->custores->postStoresToCU($myStoreURL);
                $categoryStatus = $this->cucategories->postCategoriesToCU($myStoreURL);
                $attributeStatus = $this->cuproducts->postAttributesToCU();

                $str .= "<StoreStatus>$storeStatus</StoreStatus>";
                $str .= "<CategoryStatus>$categoryStatus</CategoryStatus>";
                $str .= "<ProductAttributeStatus>$attributeStatus</ProductAttributeStatus>";
                break;

            case "ValidateCustomerDetails":
                $customerData = $this->cucustomers->validateCustomer(
                    $request->WebsiteId,
                    $request->EmailAddress,
                    $request->Password
                );
                $str .= $customerData;
                break;

            case "CreateCustomerAccount":
                $customerId = $this->cucustomers->createCustomer(
                    $request->WebsiteId,
                    $request->EmailAddress,
                    $request->Password,
                    $request->FirstName,
                    $request->LastName
                );
                $str .= "<CustomerID>$customerId</CustomerID>";
                break;

            case "ResetCustomerPassword":
                $this->cucustomers->resetPassword($request->EmailAddress);
                $str .= "<Status>OK</Status>";
                break;

            case "GetCustomerOrders":
                try {
                    $limit = isset($request->Limit) ? (int)$request->Limit : 200;
                    $offset = isset($request->Offset) ? (int)$request->Offset : 0;

                    $str .= $this->cucustomers->getOrdersByCustomerAsXML(
                        $request->WebsiteId,
                        $request->EmailAddress,
                        $request->Password,
                        $limit,
                        $offset
                    );

                    $str .= "<Status>OK</Status>";
                } catch (\Exception $e) {
                    $str .= "<Status>Error - " . $e->getMessage() . "</Status>";
                }
                break;

            default:
                // Handle unknown request types safely
                if (!empty($type)) {
                    $str .= "<Status>Error - Unrecognized RequestType: $type</Status>";
                }
                break;
        }

        $str .= '</ChannelUnity>';
        return $str;
    }
}