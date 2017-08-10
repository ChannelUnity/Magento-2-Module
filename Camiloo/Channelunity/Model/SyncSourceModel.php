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

class SyncSourceModel implements \Magento\Framework\Option\ArrayInterface
{
    
    /**
     * {@inheritdoc}
     *
     * @codeCoverageIgnore
     */
    public function toOptionArray()
    {
        return [
            ['value' => '0', 'label' => __('Disabled')],
            ['value' => '1', 'label' => __('Once every 15 minutes')],
            ['value' => '2', 'label' => __('Once every hour')],
            ['value' => '3', 'label' => __('Once every day')]
        ];
    }
}
