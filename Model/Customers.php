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
    public function validateCustomer($emailAddress, $password)
    {
        $customer = $this->customerAccountManagement->authenticate($emailAddress, $password);
        return $customer->getId();
    }
    
    /**
     * Creates a new customer record in the store.
     * @param type $emailAddress
     * @param type $password
     * @param type $firstName
     * @param type $lastName
     */
    public function createCustomer($emailAddress, $password, $firstName, $lastName)
    {
        
        $customer = $this->customerFactory->create();
        
        $store = $this->storeManager->getStore(1); // TODO pass as param
        $websiteId = $store->getWebsiteId();
        
        // Create a new customer record
        $customer->setWebsiteId($websiteId)
                ->setStore($store)
                ->setFirstname($firstName)
                ->setLastname($lastName)
                ->setEmail((string)$emailAddress)
                ->setPassword($password);
        $customer->save();

        $customer->sendNewAccountEmail();
        
        return $customer->getId();
    }
    
    /**
     * Returns all orders this customer has placed.
     * @param type $customerId
     */
    public function getOrdersByCustomer($customerId)
    {
        // Limit to 10 as a default
        $orders = $this->orderCollectionFactory->create()
                ->addFieldToSelect('*')
                ->addFieldToFilter('customer_id', $customerId)
                ->setOrder('created_at', 'desc')
                ->limit(10);
        return $orders;
    }
    
    public function getOrdersByCustomerAsXML($customerId)
    {
        $orders = $this->getOrdersByCustomer($customerId);
        $xml = "<Orders>\n";
        
        foreach ($orders as $order) {
            $xml .= "  <Order>\n";
            $keys = array_keys($order->getData());
            
            foreach ($keys as $key) {
                $xml .= "    <$key></$key>\n";
            }
            $xml .= "  </Order>\n";
        }
        $xml .= "</Orders>\n";
        return $xml;
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
