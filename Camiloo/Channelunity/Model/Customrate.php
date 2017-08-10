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

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Registry;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\ResultFactory;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Psr\Log\LoggerInterface;

/**
 * Implements a custom shipping method.
 */
class Customrate extends AbstractCarrier implements CarrierInterface
{

    /**
     * Carrier's code
     *
     * @var string
     */
    protected $_code = 'cucustomrate';
    /**
     * Whether this carrier has fixed rates calculation
     *
     * @var bool
     */
    protected $_isFixed = true;

    /**
     * @var ResultFactory
     */
    protected $_rateResultFactory;
    /**
     * @var MethodFactory
     */
    protected $_rateMethodFactory;
    
    /**
     *
     * @var Registry
     */
    private $registry;
    
    private $helper;
    
    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param ErrorFactory $rateErrorFactory
     * @param LoggerInterface $logger
     * @param ResultFactory $rateResultFactory
     * @param MethodFactory $rateMethodFactory
     * @param array $data
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        ResultFactory $rateResultFactory,
        MethodFactory $rateMethodFactory,
        Registry $registry,
        Helper $helper,
        array $data = []
    ) {
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
        $this->_rateResultFactory = $rateResultFactory;
        $this->_rateMethodFactory = $rateMethodFactory;
        $this->registry = $registry;
        $this->helper = $helper;
    }

    public function collectRates(RateRequest $request)
    {
        // Check if we are getting the rate for a CU request.
        // to do this, the easiest way is to check for some form
        // of CU data incoming.
        
        $cuOrderCheck = $this->registry->registry('cu_order_in_progress');
        if ($cuOrderCheck == 1) {
            $marketplaceShippingMethod = $this->registry->registry('cu_shipping_method');
            
            /** @var \Magento\Shipping\Model\Rate\Result $result */
            $result = $this->_rateResultFactory->create();
            
            $shippingPrice = $this->registry->registry('cu_shipping_price');
            
            $method = $this->_rateMethodFactory->create();
            /**
             * Set carrier's method data
             */
            $method->setCarrier($this->getCarrierCode());
            $method->setCarrierTitle($this->getConfigData('title'));
            
            /**
             * Displayed as shipping method under Carrier
             */
            $method->setMethod($this->getCarrierCode());
            $method->setMethodTitle($marketplaceShippingMethod);
            $method->setPrice($shippingPrice);
            $method->setCost($shippingPrice);
            $result->append($method);
            
            return $result;
        } else {
            return false;
        }
    }

    public function getAllowedMethods()
    {
        return [$this->getCarrierCode() => $this->getConfigData('name')];
    }
}
