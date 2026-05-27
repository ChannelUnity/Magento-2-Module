<?php
/**
 * ChannelUnity connector for Magento Commerce
 *
 * @category   Camiloo
 * @package    Camiloo_Channelunity
 * @copyright  Copyright (c) 2016-2024 ChannelUnity Limited (http://www.channelunity.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Camiloo\Channelunity\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Camiloo\Channelunity\Model\Helper;
use Camiloo\Channelunity\Model\Products;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Console\Cli;
use Magento\Framework\App\Area;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\Store;

/**
 * Sends product updates in bulk to ChannelUnity. This is used as a result
 * of bulk attribute updates when editing products in Magento.
 */
class BulkProductSync extends Command
{
    private $cuproducts;
    private $helper;
    private $resource;
    private $state;
    private $storeManager;
    private $syncLimit = 50;

    public function __construct(
        Helper $helper,
        Products $cuproducts,
        ResourceConnection $resource,
        State $state,
        StoreManagerInterface $storeManager
    ) {
        parent::__construct();
        $this->helper = $helper;
        $this->cuproducts = $cuproducts;
        $this->resource = $resource;
        $this->state = $state;
        $this->storeManager = $storeManager;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('channelunity:sync_products_bulk')
            ->setDescription("Sync products to ChannelUnity that have been recently saved in bulk ({$this->syncLimit} max per run)");
        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Manual run
        $this->updateBatch($output);

        return Cli::RETURN_SUCCESS;
    }

    //======================= Cron commands =================================//

    public function every5Minutes()
    {
        // Crontab run
        $this->updateBatch();
    }

    //======================= Private methods =================================//

    private function updateBatch(OutputInterface $output = null)
    {
        try {
            $this->state->setAreaCode(Area::AREA_GLOBAL);
        } catch (LocalizedException $e) {
            // Area code is already set, safely ignore
        }

        $startTime = time();

        // If a bulk edit recently happened, we'll have stored some product IDs
        $productUpdatesTable = $this->resource->getTableName('product_updates');
        $connection = $this->resource->getConnection(ResourceConnection::DEFAULT_CONNECTION);

        $select = $connection->select()
            ->from(['pu' => $productUpdatesTable], ['product_id'])
            ->limit($this->syncLimit);

        $data = $connection->fetchAll($select);

        if (is_array($data) && count($data) > 0) {
            // Process the current batch
            foreach ($data as $productIdRow) {
                $productId = $productIdRow['product_id'];

                try {
                    // Delete the current product ID so we don't process it again
                    // We do this FIRST so if it crashes, it doesn't get stuck in an infinite loop
                    $connection->delete(
                        $productUpdatesTable,
                        ['product_id = ?' => $productId]
                    );

                    $this->printMessage("BulkUpdateProcessor - ProductId: $productId", $output);

                    // Default store view
                    $this->printMessage("BulkUpdateProcessor - XML default store", $output);
                    $productData = $this->cuproducts->generateCuXmlForSingleProduct($productId, Store::DEFAULT_STORE_ID);

                    // Send to CU
                    $this->helper->updateProductData(Store::DEFAULT_STORE_ID, $productData);

                    // Loop through store views
                    $websites = $this->storeManager->getWebsites();
                    foreach ($websites as $website) {
                        $stores = $website->getStores();
                        foreach ($stores as $storeView) {
                            $storeId = $storeView->getId();
                            $this->printMessage("BulkUpdateProcessor - storeId: $storeId", $output);

                            $storeProductData = $this->cuproducts->generateCuXmlForSingleProduct($productId, $storeId);
                            $this->printMessage("BulkUpdateProcessor - XML generated", $output);

                            // Send to CU
                            $this->helper->updateProductData($storeId, $storeProductData);
                        }
                    }
                } catch (\Exception $e) {
                    $this->printMessage("BulkUpdateProcessor - Error processing ProductId $productId: " . $e->getMessage(), $output);
                }

                $currTime = time();
                // Taking too long (more than 5 minutes), bail out
                if ($currTime - $startTime > 300) {
                    $this->printMessage("BulkUpdateProcessor - Terminating early to prevent overlap", $output);
                    return;
                }
            }

            $this->printMessage("BulkUpdateProcessor - Complete", $output);
        }
    }

    /**
     * Logs an activity message and prints to the screen if being run as a command.
     */
    private function printMessage(string $str, OutputInterface $output = null)
    {
        $this->helper->logInfo($str);
        if ($output) {
            $output->writeln($str);
        }
    }
}