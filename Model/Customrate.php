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
     * @var Registry
     */
    private $registry;

    /**
     * @var Helper
     */
    private $helper;

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

    /**
     * Collect and get rates
     *
     * @param RateRequest $request
     * @return \Magento\Shipping\Model\Rate\Result
     */
    public function collectRates(RateRequest $request)
    {
        // Check if we are getting the rate for a CU request.
        $cuOrderCheck = $this->registry->registry('cu_order_in_progress');

        // IMPORTANT: If this is not a CU order, we must return false immediately
        // so this internal shipping method doesn't leak into the standard frontend checkout.
        if ($cuOrderCheck != 1) {
            return false;
        }

        // Safely fetch registry values with fallbacks
        $marketplaceShippingMethod = $this->registry->registry('cu_shipping_method') ?: 'ChannelUnity Shipping';
        $shippingPrice = (float) $this->registry->registry('cu_shipping_price') ?: 0.00;

        /** @var \Magento\Shipping\Model\Rate\Result $result */
        $result = $this->_rateResultFactory->create();

        /** @var \Magento\Quote\Model\Quote\Address\RateResult\Method $method */
        $method = $this->_rateMethodFactory->create();

        $method->setCarrier($this->getCarrierCode());

        $cuPrimeCheck = $this->registry->registry('cu_prime_order');
        if ($cuPrimeCheck == 1) {
            $method->setCarrierTitle('Prime Shipping');
        } else {
            $method->setCarrierTitle($marketplaceShippingMethod);
        }

        $method->setMethod($this->getCarrierCode());
        $method->setMethodTitle($marketplaceShippingMethod);
        $method->setPrice($shippingPrice);
        $method->setCost($shippingPrice);

        $result->append($method);

        return $result;
    }

    /**
     * Get allowed shipping methods
     *
     * @return array
     */
    public function getAllowedMethods(): array
    {
        return [$this->getCarrierCode() => $this->getConfigData('name')];
    }
}