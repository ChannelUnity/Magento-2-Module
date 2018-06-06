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

class BulkAttributeUpdateObserver implements ObserverInterface
{
    private $helper;
    private $registry;

    public function __construct(
        Helper $helper,
        Registry $registry
    ) {
        $this->helper = $helper;
        $this->registry = $registry;
    }

    public function execute(Observer $observer)
    {
        $this->helper->logInfo("Observer called: Bulk Attribute Update");

        $attributePids = $observer->getProductIds();
        $this->helper->logInfo("Observer Bulk Attribute product IDs are ".implode(",", $attributePids));

        if (is_array($attributePids)) {
            $this->registry->unregister('attribute_pids');
            $this->registry->register('attribute_pids', $attributePids);
            return;
        }
    }
}
