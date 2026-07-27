<?php
/**
 * ChannelUnity connector for Magento Commerce
 *
 * @category   Camiloo
 * @package    Camiloo_Channelunity
 * @copyright  Copyright (c) 2016-2024 ChannelUnity Limited (http://www.channelunity.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Camiloo\Channelunity\Block\Adminhtml\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;

/**
 * Renders the "Order Channel Display Names" configuration table, mapping a
 * ChannelUnity subscription ID to the friendly channel name shown in the
 * Orders grid.
 */
class SubscriptionMapping extends AbstractFieldArray
{
    protected function _prepareToRender()
    {
        $this->addColumn('subscription_id', [
            'label' => __('Subscription ID'),
            'class' => 'required-entry validate-number'
        ]);
        $this->addColumn('display_name', [
            'label' => __('Display Name'),
            'class' => 'required-entry'
        ]);
        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add Channel');
    }
}
