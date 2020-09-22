<?php
/**
 * ChannelUnity connector for Magento Commerce
 *
 * @category   Camiloo
 * @package    Camiloo_Channelunity
 * @copyright  Copyright (c) 2016-2017 ChannelUnity Limited (http://www.channelunity.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */


namespace Camiloo\Channelunity\Model;

use Magento\Framework\Model\AbstractModel;
use Magento\Customer\Model\CustomerFactory;
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
    private $customerFactory;
    private $customerRepository;
    private $customerAccountManagement;
    private $storeManager;
    private $orderCollectionFactory;
    private $wishlistRepository;
    
    public function __construct(
        CustomerFactory $customerFactory,
        CustomerRepositoryInterface $customerRepository,
        AccountManagementInterface $customerAccountManagement,
        StoreManagerInterface $storeManager,
        CollectionFactory $orderCollectionFactory,
        WishlistFactory $wishlistRepository
    ) {
        $this->customerFactory = $customerFactory;
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
     * @param type $emailAddress
     * @param type $password
     */
    public function validateCustomer($websiteId, $emailAddress, $password)
    {
        $website = $this->storeManager->getWebsite((int) $websiteId);
        if (is_object($website)) {
            $storeId = $website->getDefaultStore()->getId();
            $this->storeManager->setCurrentStore($storeId);
            
            $customer = $this->customerAccountManagement->authenticate((string)$emailAddress, (string)$password);
            if (is_object($customer)) {
                $customerId = $customer->getId();

                return "<CustomerID>$customerId</CustomerID>"
                        . "<Data>"
                        . "<firstname><![CDATA[".$customer->getFirstname()."]]></firstname>"
                        . "<lastname><![CDATA[".$customer->getLastname()."]]></lastname>"
                        . "<email><![CDATA[".$customer->getEmail()."]]></email>"
                        . "<created_at><![CDATA[".$customer->getCreatedAt()."]]></created_at>"
                        . "</Data>";
            }
            else {

                return "<CustomerID>0</CustomerID>";
            }
        } else {

            return "<CustomerID>0</CustomerID>";
        }
    }
    
    /**
     * Creates a new customer record in the store.
     * @param type $emailAddress
     * @param type $password
     * @param type $firstName
     * @param type $lastName
     */
    public function createCustomer($websiteId, $emailAddress, $password, $firstName, $lastName)
    {
        $website = $this->storeManager->getWebsite((int) $websiteId);
        if (is_object($website)) {
            $storeId = $website->getDefaultStore()->getId();
            // Create a new customer record
            $customer = $this->customerFactory->create();
            
            $customer->setWebsiteId((int) $websiteId);
            $customer->setStoreId($storeId);
            $customer->setEmail((string) $emailAddress);
            $customer->setFirstname((string) $firstName);
            $customer->setLastname((string) $lastName);
            $customer->setPassword((string) $password);
            
            /** @var \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository */
            $customer->save();

            try {
                $customer->sendNewAccountEmail();
            }
            catch (\Exception $e) {
                //Don't want to fail if the email fails.
            }

            return $customer->getId();
        }
        
        return 0;
    }
    
    /**
     * Returns all orders this customer has placed.
     * @param type $customerId
     */
    public function getOrdersByCustomer($customerId, $limit, $offset)
    {
        // Limit to 10 as a default
        $orderCollection = $this->orderCollectionFactory->create()
                ->addFieldToSelect('*');
        $orderCollection->addAttributeToFilter('customer_id', $customerId)
                ->setOrder('created_at', 'desc')->setPageSize($limit);
        return $orderCollection;
    }
    
    public function getOrdersByCustomerAsXML($websiteId, $emailAddress, $password,
            $limit, $offset)
    {
        $website = $this->storeManager->getWebsite((int) $websiteId);
        if (is_object($website)) {
            $storeId = $website->getDefaultStore()->getId();
            $this->storeManager->setCurrentStore($storeId);
        }
        $customer = $this->customerAccountManagement->authenticate((string)$emailAddress, (string)$password);
        if (is_object($customer)) {
            $customerId = $customer->getId();
            
            $orders = $this->getOrdersByCustomer($customerId, (int)$limit, (int)$offset);
            $xml = "<Orders>\n";
            
            foreach ($orders as $order) {
                $xml .= "  <Order>\n";
                $keys = array_keys($order->getData());

                foreach ($keys as $key) {
                    $xml .= "    <$key><![CDATA[".$order->getData($key)."]]></$key>\n";
                }
                $xml .= "  </Order>\n";
            }
            $xml .= "</Orders>\n";
            return $xml;
        }
    }

    /**
     * Trigger an email to be sent to allow the user to reset their password.
     * @param type $emailAddress
     */
    public function resetPassword($emailAddress)
    {
        $this->customerAccountManagement->initiatePasswordReset(
            (string)$emailAddress,
            AccountManagement::EMAIL_RESET
        );
    }
    
    /**
     * Returns a list of products the customer has added to their wish list.
     * @param type $emailAddress
     */
    public function getWishlist($emailAddress)
    {
        $websiteId = 1;
        $customer = $this->customerFactory->create();
        $customer->setWebsiteId($websiteId);
        $customer->loadByEmail($emailAddress);
        $customerId = $customer->getId();
        
        $wishlist = $this->wishlistRepository->create()->loadByCustomerId($customerId, true);

        return $wishlist->getItemCollection();
    }
}
