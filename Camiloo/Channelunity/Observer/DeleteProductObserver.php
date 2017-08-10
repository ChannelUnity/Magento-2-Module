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

/**
 * Called when a product is deleted from Magento.
 */
class DeleteProductObserver implements ObserverInterface
{
    private $helper;
    
    public function __construct(Helper $helper)
    {
        $this->helper = $helper;
    }

    public function execute(Observer $observer)
    {
        $this->helper->logInfo("Observer called: Delete Product");
        
        $product = $observer->getData('product');
        if (is_object($product)) {
            $productXml = '<DeletedProductId>' . $product->getId() . '</DeletedProductId>';
            $this->helper->updateProductData(0, $productXml);
        }
    }
}
