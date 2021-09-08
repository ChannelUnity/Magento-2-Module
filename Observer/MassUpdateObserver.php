<?php

/**
 * ChannelUnity connector for Magento Commerce
 *
 * @category   Camiloo
 * @package    Camiloo_Channelunity
 * @copyright  Copyright (c) 2016 ChannelUnity Limited (http://www.channelunity.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
/**
 * ChannelUnity observers.
 * Posts events to the CU cloud when various Magento events occur.
 */

namespace Camiloo\Channelunity\Observer;

use \Magento\Framework\Event\ObserverInterface;
use \Magento\Framework\Event\Observer;
use \Magento\Framework\Registry;
use \Camiloo\Channelunity\Model\Helper;
use \Magento\Framework\App\ResourceConnection;

class MassUpdateObserver implements ObserverInterface
{
    private $helper;
    private $registry;
    private $resource;

    public function __construct(
        Helper $helper,
        Registry $registry,
        ResourceConnection $resource
    ) {
        $this->helper = $helper;
        $this->registry = $registry;
        $this->resource = $resource;
    }

    public function execute(Observer $observer)
    {
        $this->helper->logInfo("Observer called: Mass Product Update");
        $profile = $observer->getEvent()->getProfile();
        
        $successfulIDs = array_merge($profile->_success, // Created products
                    $profile->_notices); // Updated products
        $this->helper->logInfo('Successful: '.var_export($successfulIDs, true));
        
        $productInfo = $profile->_products;
        
        // For each updated SKU, find the product ID
        // Add this into the product_updates table
        $attributePids = [];
        
        foreach ($successfulIDs as $sku) {
            $entityId = $productInfo[$sku];
            if ($entityId > 0) {
                $attributePids[] = $entityId;
            }
        }
        
        $this->helper->logInfo("Observer Mass Product Update product IDs are ".implode(",", $attributePids));

        if (is_array($attributePids)) {
            $this->registry->unregister('attribute_pids');
            $this->registry->register('attribute_pids', $attributePids);
            $connection = $this->resource->getConnection(\Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION);
            // User updated products in bulk, save the product IDs and
            // update them (to ChannelUnity) in the background
            
            $insertData = [];
            foreach ($attributePids as $id) {
                $insertData[] = ['product_id' => $id];
            }
            try {
                $connection->beginTransaction();
                $connection->insertMultiple($this->resource->getTableName('product_updates'), $insertData);
                $connection->commit();
            } catch (\Exception $e) {
                $connection->rollBack();
            }
            
            $this->helper->logInfo("Observer complete");
        }
    }
}
