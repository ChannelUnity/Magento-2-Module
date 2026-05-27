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
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Customer\Model\AccountManagement;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Wishlist\Model\WishlistFactory;

/**
 * Deals with the following customer queries:
 * Validate customer
 * Create customer account
 * Get orders placed by a customer
 */
class Customers extends AbstractModel
{
    private $customerDataFactory;
    private $customerRepository;
    private $customerAccountManagement;
    private $storeManager;
    private $orderCollectionFactory;
    private $wishlistRepository;

    public function __construct(
        CustomerInterfaceFactory $customerDataFactory,
        CustomerRepositoryInterface $customerRepository,
        AccountManagementInterface $customerAccountManagement,
        StoreManagerInterface $storeManager,
        CollectionFactory $orderCollectionFactory,
        WishlistFactory $wishlistRepository
    ) {
        $this->customerDataFactory = $customerDataFactory;
        $this->customerRepository = $customerRepository;
        $this->customerAccountManagement = $customerAccountManagement;
        $this->storeManager = $storeManager;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->wishlistRepository = $wishlistRepository;
    }

    /**
     * Given an email address and password of a customer, returns the
     * customer ID if the details are valid, or an error message if the details
     * are not valid.
     */
    public function validateCustomer($websiteId, $emailAddress, $password)
    {
        try {
            $website = $this->storeManager->getWebsite((int) $websiteId);
            if ($website && $website->getId()) {
                $storeId = $website->getDefaultStore()->getId();
                $this->storeManager->setCurrentStore($storeId);

                // M2 authenticate throws exceptions on failure, it does not return false
                $customer = $this->customerAccountManagement->authenticate((string)$emailAddress, (string)$password);

                if ($customer && $customer->getId()) {
                    return "<CustomerID>" . $customer->getId() . "</CustomerID>"
                        . "<Data>"
                        . "<firstname><![CDATA[" . $customer->getFirstname() . "]]></firstname>"
                        . "<lastname><![CDATA[" . $customer->getLastname() . "]]></lastname>"
                        . "<email><![CDATA[" . $customer->getEmail() . "]]></email>"
                        . "<created_at><![CDATA[" . $customer->getCreatedAt() . "]]></created_at>"
                        . "</Data>";
                }
            }
        } catch (\Magento\Framework\Exception\InvalidEmailOrPasswordException $e) {
            // Caught invalid credentials gracefully
        } catch (\Exception $e) {
            // Caught any other store/customer loading issues
        }

        return "<CustomerID>0</CustomerID>";
    }

    /**
     * Creates a new customer record in the store securely.
     */
    public function createCustomer($websiteId, $emailAddress, $password, $firstName, $lastName)
    {
        try {
            $website = $this->storeManager->getWebsite((int) $websiteId);
            if ($website && $website->getId()) {
                $storeId = $website->getDefaultStore()->getId();

                // Use the modern Data Model factory for creation
                $customer = $this->customerDataFactory->create();

                $customer->setWebsiteId((int) $websiteId);
                $customer->setStoreId($storeId);
                $customer->setEmail((string) $emailAddress);
                $customer->setFirstname((string) $firstName);
                $customer->setLastname((string) $lastName);

                // Securely handles hashing and database saving
                $savedCustomer = $this->customerAccountManagement->createAccount($customer, (string) $password);

                return $savedCustomer->getId();
            }
        } catch (\Exception $e) {
            // Handle duplicate email or validation errors
        }

        return 0;
    }

    /**
     * Returns all orders this customer has placed.
     */
    public function getOrdersByCustomer($customerId, $limit, $offset = 0)
    {
        $orderCollection = $this->orderCollectionFactory->create()->addFieldToSelect('*');
        $orderCollection->addAttributeToFilter('customer_id', $customerId)
            ->setOrder('created_at', 'desc');

        // Properly apply pagination rules based on limit and offset
        $limit = (int) $limit ?: 10;
        $offset = (int) $offset;
        $page = ($offset / $limit) + 1;

        $orderCollection->setPageSize($limit)->setCurPage($page);

        return $orderCollection;
    }

    public function getOrdersByCustomerAsXML($websiteId, $emailAddress, $password, $limit, $offset = 0)
    {
        try {
            $website = $this->storeManager->getWebsite((int) $websiteId);
            if ($website && $website->getId()) {
                $storeId = $website->getDefaultStore()->getId();
                $this->storeManager->setCurrentStore($storeId);
            }

            $customer = $this->customerAccountManagement->authenticate((string)$emailAddress, (string)$password);

            if ($customer && $customer->getId()) {
                $orders = $this->getOrdersByCustomer($customer->getId(), $limit, $offset);
                $xml = "<Orders>\n";

                foreach ($orders as $order) {
                    $xml .= "  <Order>\n";
                    $keys = array_keys($order->getData());

                    foreach ($keys as $key) {
                        $val = $order->getData($key);
                        // Prevent Array to String conversion errors on nested objects/arrays
                        if (is_scalar($val) || is_null($val)) {
                            // Strip any existing CDATA terminators to avoid breaking XML
                            $safeVal = str_replace("]]>", "]]&gt;", (string)$val);
                            $xml .= "    <$key><![CDATA[" . $safeVal . "]]></$key>\n";
                        }
                    }
                    $xml .= "  </Order>\n";
                }
                $xml .= "</Orders>\n";

                return $xml;
            }
        } catch (\Exception $e) {
            // Graceful failure on invalid credentials
        }

        return "<Orders></Orders>\n";
    }

    /**
     * Trigger an email to be sent to allow the user to reset their password.
     */
    public function resetPassword($emailAddress)
    {
        try {
            $this->customerAccountManagement->initiatePasswordReset(
                (string)$emailAddress,
                AccountManagement::EMAIL_RESET
            );
        } catch (\Exception $e) {
            // Prevent fatal errors if email does not exist
        }
    }

    /**
     * Returns a list of products the customer has added to their wish list.
     */
    public function getWishlist($emailAddress, $websiteId = null)
    {
        try {
            // Dynamically fetch the default website ID if not provided
            if (!$websiteId) {
                $websiteId = $this->storeManager->getDefaultStoreView()->getWebsiteId();
            }

            // Use Repository instead of deprecated Factory->load()
            $customer = $this->customerRepository->get($emailAddress, $websiteId);
            $customerId = $customer->getId();

            $wishlist = $this->wishlistRepository->create()->loadByCustomerId($customerId, true);

            return $wishlist->getItemCollection();
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            // Customer does not exist
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
}