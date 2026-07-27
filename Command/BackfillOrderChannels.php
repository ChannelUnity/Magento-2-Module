<?php
/**
 * ChannelUnity connector for Magento Commerce
 *
 * @category   Camiloo
 * @package    Camiloo_Channelunity
 * @copyright  Copyright (c) 2016-2026 ChannelUnity Limited (http://www.channelunity.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Camiloo\Channelunity\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Console\Cli;
use Camiloo\Channelunity\Helper\Helper;

/**
 * Backfills the "Channel" column on existing ChannelUnity orders (both the
 * sales_order table and the sales_order_grid table it displays from).
 *
 * Re-resolves each order's channel name from its stored subscription details
 * against the current "Order Channel Display Names" mapping, so it can also be
 * re-run after that mapping is changed.
 */
class BackfillOrderChannels extends Command
{
    const ONLY_MISSING_OPTION = "only-missing";

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var Helper
     */
    private $helper;

    public function __construct(
        ResourceConnection $resource,
        Helper $helper
    ) {
        parent::__construct();

        $this->resource = $resource;
        $this->helper = $helper;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('channelunity:backfill_order_channels')
            ->setDescription('Populates the Channel column on existing ChannelUnity orders')
            ->addOption(
                self::ONLY_MISSING_OPTION,
                null,
                InputOption::VALUE_NONE,
                'Only fill orders that have no channel set yet (skip ones already populated)'
            );

        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $onlyMissing = (bool) $input->getOption(self::ONLY_MISSING_OPTION);

        try {
            $connection = $this->resource->getConnection();
            $orderTable = $this->resource->getTableName('sales_order');
            $paymentTable = $this->resource->getTableName('sales_order_payment');
            $gridTable = $this->resource->getTableName('sales_order_grid');

            $select = $connection->select()
                ->from(['sop' => $paymentTable], ['additional_information'])
                ->join(
                    ['so' => $orderTable],
                    'so.entity_id = sop.parent_id',
                    ['entity_id', 'channelunity_channel']
                )
                ->where('sop.method = ?', 'channelunitypayment');

            if ($onlyMissing) {
                $select->where('so.channelunity_channel IS NULL OR so.channelunity_channel = ?', '');
            }

            $rows = $connection->fetchAll($select);

            $updated = 0;
            $skipped = 0;

            foreach ($rows as $row) {
                $info = $this->decodeAdditionalInformation($row['additional_information']);
                $subscriptionId = (string) ($info['subscription_id'] ?? '');
                $serviceSku = (string) ($info['service_sku'] ?? '');

                if ($subscriptionId === '' && $serviceSku === '') {
                    $skipped++;
                    continue;
                }

                $channelName = $this->helper->resolveChannelName($subscriptionId, $serviceSku);

                if ($channelName === (string) $row['channelunity_channel']) {
                    $skipped++;
                    continue;
                }

                $entityId = (int) $row['entity_id'];
                $connection->update(
                    $orderTable,
                    ['channelunity_channel' => $channelName],
                    ['entity_id = ?' => $entityId]
                );
                $connection->update(
                    $gridTable,
                    ['channelunity_channel' => $channelName],
                    ['entity_id = ?' => $entityId]
                );
                $updated++;
            }

            $output->writeln("<info>Channel backfill complete. Updated: $updated, unchanged/skipped: $skipped.</info>");
            return Cli::RETURN_SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("<error>Backfill failed: " . $e->getMessage() . "</error>");
            return Cli::RETURN_FAILURE;
        }
    }

    private function decodeAdditionalInformation($raw): array
    {
        if (empty($raw)) {
            return [];
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
