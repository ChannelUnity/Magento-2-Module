<?php
/**
 * ChannelUnity connector for Magento Commerce
 *
 * @category   Camiloo
 * @package    Camiloo_Channelunity
 * @copyright  Copyright (c) 2016-2026 ChannelUnity Limited (http://www.channelunity.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Camiloo\Channelunity\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Populates the Orders grid "Channel" filter dropdown with the distinct
 * channel names currently present on orders, so the list always matches
 * real data rather than drifting out of sync with the admin-configured mapping.
 */
class OrderChannel implements OptionSourceInterface
{
    /**
     * @var ResourceConnection
     */
    private $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    /**
     * {@inheritdoc}
     */
    public function toOptionArray()
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('sales_order_grid');

        $select = $connection->select()
            ->from($table, ['channelunity_channel'])
            ->distinct(true)
            ->where('channelunity_channel IS NOT NULL')
            ->where('channelunity_channel != ?', '')
            ->order('channelunity_channel ASC');

        $options = [];
        foreach ($connection->fetchCol($select) as $channelName) {
            $options[] = ['value' => $channelName, 'label' => $channelName];
        }

        return $options;
    }
}
