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
use Camiloo\Channelunity\Model\Helper;

class DeleteStoreObserver implements ObserverInterface
{
    private $helper;
    
    public function __construct(Helper $helper)
    {
        $this->helper = $helper;
        //Observer initialization code...
        //You can use dependency injection to get any class this observer may need.
    }

    public function execute(Observer $observer)
    {
        $store = $observer->getData('store');
        $storeId = $store->getId();
        $this->helper->logInfo("Observer called: Delete Store $storeId");
        $sourceUrl = $this->helper->getBaseUrl();

        // Create XML
        $xml = <<<XML
<StoreDelete>
<SourceURL>{$sourceUrl}</SourceURL>
<StoreId>{$store->getGroupId()}</StoreId>
<DeletedStoreViewId>{$store->getId()}</DeletedStoreViewId>
<WebsiteId>{$store->getWebsiteId()}</WebsiteId>
</StoreDelete>
XML;
        // Send XML to CU
        $this->helper->postToChannelUnity($xml, 'storeDelete');
    }
}
