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

use Magento\Framework\Registry;

/**
 * Payment method for ChannelUnity.
 */
class Channelunitypayment extends \Magento\Payment\Model\Method\AbstractMethod
{
    /**
     * Payment code
     *
     * @var string
     */
    protected $_code = 'channelunitypayment';

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_isOffline = true;
    
    private $registry;
    
    private $helper;
    
    public function __construct(
        Registry $registry,
        Helper $helper
    ) {
        $this->registry = $registry;
        $this->helper = $helper;
    }
    
    /**
     * We don't want this payment method available on the front end website
     * during checkout.
     * @param \Magento\Quote\Api\Data\CartInterface $quote
     * @return type
     */
    public function isAvailable(
        \Magento\Quote\Api\Data\CartInterface $quote = null
    ) {
        $this->helper->logInfo("Channelunitypayment isAvailable");
        
        $cuOrder = $this->registry->registry('cu_order_in_progress');
        $this->helper->logInfo("Channelunitypayment cu_order_in_progress $cuOrder");

        return $cuOrder == 1;
    }
    
    /**
     * Overriden from base class otherwise there is a crash in the base class.
     * @param type $field
     * @param type $storeId
     * @return type
     */
    public function getConfigData($field, $storeId = null)
    {
        $path = 'payment/' . $this->getCode() . '/' . $field;
        $value = $this->helper->getConfig($path);
        
        $this->helper->logInfo("Channelunitypayment getConfigData $field -> $value");
        
        return $value;
    }
    
    public function assignData(\Magento\Framework\DataObject $data)
    {
        return $this;
    }
}
