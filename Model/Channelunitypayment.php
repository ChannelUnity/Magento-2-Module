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

use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Payment\Helper\Data as PaymentData;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Payment\Model\Method\Logger;
use Camiloo\Channelunity\Helper\Helper;

/**
 * Internal-only payment method for orders imported by ChannelUnity.
 * This method is never shown to customers on the frontend; it is made
 * available exclusively when the cu_order_in_progress registry flag is set.
 */
class Channelunitypayment extends \Magento\Payment\Model\Method\AbstractMethod
{
    /**
     * Payment method code
     * @var string
     */
    protected $_code = 'channelunitypayment';

    /**
     * This is an offline (no gateway) payment method
     * @var bool
     */
    protected $_isOffline = true;

    /**
     * @var Registry
     */
    private $registry;

    /**
     * @var Helper
     */
    private $cuHelper;

    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        PaymentData $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        Helper $cuHelper
    ) {
        $this->registry = $registry;
        $this->cuHelper = $cuHelper;

        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger
        );
    }

    /**
     * Only allow this payment method when a CU order import is in progress.
     *
     * @param \Magento\Quote\Api\Data\CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        $this->cuHelper->logInfo("Channelunitypayment isAvailable");

        $cuOrder = $this->registry->registry('cu_order_in_progress');
        $this->cuHelper->logInfo("Channelunitypayment cu_order_in_progress $cuOrder");

        return $cuOrder == 1;
    }

    /**
     * Read payment config values via the CU helper so we avoid relying on
     * AbstractMethod's scopeConfig instance being set up before this is called.
     *
     * @param string $field
     * @param int|null $storeId
     * @return mixed
     */
    public function getConfigData($field, $storeId = null)
    {
        $path = 'payment/' . $this->getCode() . '/' . $field;
        $value = $this->cuHelper->getConfig($path);

        $this->cuHelper->logInfo("Channelunitypayment getConfigData $field -> $value");

        return $value;
    }

    /**
     * No additional data to assign for this internal payment method.
     *
     * @param \Magento\Framework\DataObject $data
     * @return $this
     */
    public function assignData(\Magento\Framework\DataObject $data)
    {
        return $this;
    }
}
