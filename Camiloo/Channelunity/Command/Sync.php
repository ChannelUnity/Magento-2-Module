<?php

/**
 * Product Sync Command for Magento Commerce
 *
 * @category   Camiloo
 * @package    Camiloo_Channelunity
 * @copyright  Copyright (c) 2016 ChannelUnity Limited (http://www.channelunity.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Camiloo\Channelunity\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Camiloo\Channelunity\Model\Helper;
use Camiloo\Channelunity\Model\Products;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\CatalogInventory\Model\Stock\StockItemRepository;
use Magento\Framework\Model\ResourceModel\Iterator;
use Magento\CatalogInventory\Model\ResourceModel\Stock\Item;
use Magento\Catalog\Model\ResourceModel\Product;
use Magento\Eav\Model\ResourceModel\Entity\Attribute;
use Magento\Framework\App\ResourceConnection;

/**
 * Sends stock and price information for each SKU in the Magento catalog to CU.
 * Note this only works for the Default Store View.
 */
class Sync extends Command
{
    private $buffer;
    private $lastSyncProd;
    private $cuproducts;
    private $helper;
    private $searchCriteriaBuilder;
    private $iterator;
    private $stockItem;
    private $product;
    private $eavAttribute;
    private $resource;
    
    public function __construct(
        Helper $helper,
        Products $cuproducts,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        StockItemRepository $stockItemRepository,
        Iterator $iterator,
        Item $stockItem,
        Product $product,
        Attribute $eavAttribute,
        ResourceConnection $resource
    ) {
        parent::__construct();
        $this->helper = $helper;
        $this->cuproducts = $cuproducts;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->stockItemRepository = $stockItemRepository;
        $this->iterator = $iterator;
        $this->stockItem = $stockItem;
        $this->product = $product;
        $this->eavAttribute = $eavAttribute;
        $this->resource = $resource;
        $this->buffer = "";
        $this->lastSyncProd = 0;
    }
    
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('channelunity:sync_stock_and_price')
            ->setDescription('Sync all products\' stock and price levels to ChannelUnity');
        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->runSyncNow($output);
    }
    
    private function runSyncNow($output)
    {
        do {
            $this->buffer = "";
            
            // Get a block of products with SKU, qty, price
            $this->fullstockpricemessageAction();
            
            if ($this->buffer) {

                // Get the URL of the store
                $sourceUrl = $this->helper->getBaseUrl();

                $xml = "<Products>
                        <SourceURL>{$sourceUrl}</SourceURL>
                        <StoreViewId>0</StoreViewId>
                        <Data><![CDATA[ ".rtrim($this->buffer)." ]]></Data>
                        </Products>";

                $this->helper->logInfo($xml);
                if ($output) {
                    $output->writeln($xml);
                }

                // Send to ChannelUnity
                $response = $this->helper->postToChannelUnity($xml, 'ProductDataLite');

                $this->helper->logInfo($response);
                if ($output) {
                    $output->writeln($response);
                }
            }
        } while ($this->lastSyncProd > 0);
        // Loop while more products to synchronise
    }

    public function productCallback($args)
    {
        $qtyStock = $args['row']['qty'];
        $row = $args['row']['sku'] . "," . (int)($qtyStock) . "," . $args['row']['value'] . "*\n";
        $this->buffer .= $row;
        $this->lastSyncProd = $args['row']['entity_id'];
    }

    public function fullstockpricemessageAction()
    {
        // Get the attribute ID for prices so we can load our prices
        $attributeId = $this->eavAttribute->getIdByCode('catalog_product', 'price');
        $tableName = $this->resource->getTableName('catalog_product_entity_decimal');
        
        $select = $this->stockItem->getConnection()
                ->select()
                ->from(['t1' => $this->stockItem->getMainTable()])
                ->join(
                    ['t2' => $this->product->getEntityTable()],
                    't1.product_id = t2.entity_id'
                )
                ->join(
                    ['t3' => $tableName],
                    't1.product_id = t3.entity_id'
                )
                ->where('t1.product_id > ?', $this->lastSyncProd)
                ->where('t3.attribute_id = ?', $attributeId)
                ->where('t3.store_id = ?', 0)
                ->order('t1.product_id')
                ->limit(1000);

        $this->lastSyncProd = 0;
        $this->iterator->walk($select, [[$this, 'productCallback']]);
    }
    
    //======================= Cron commands =================================//
    
    public function every15Minutes()
    {
        if ($this->helper->getSyncOption() == 1) {
            // User has set it to every 15 minutes
            $this->helper->logInfo("CRON: Sync job is active");
            $this->runSyncNow(null);
        }
        return $this;
    }
    
    public function everyHour()
    {
        if ($this->helper->getSyncOption() == 2) {
            // User has set it to every hour
            $this->helper->logInfo("CRON: Sync job is active");
            $this->runSyncNow(null);
        }
        return $this;
    }
    
    public function everyDay()
    {
        if ($this->helper->getSyncOption() == 3) {
            // User has set it to every day
            $this->helper->logInfo("CRON: Sync job is active");
            $this->runSyncNow(null);
        }
        return $this;
    }
}
