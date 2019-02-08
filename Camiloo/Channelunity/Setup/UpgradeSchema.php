<?php

namespace Camiloo\Channelunity\Setup;

use Magento\Framework\Setup\UpgradeSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

class UpgradeSchema implements UpgradeSchemaInterface {

    /**
     * {@inheritdoc}
     */
    public function upgrade(SchemaSetupInterface $setup, ModuleContextInterface $context) {
        $setup->startSetup();
        if (version_compare($context->getVersion(), '2.0.1', '<')) {
            /**
             * Create table 'product_updates'.
             */
            $table = $setup->getConnection()
                ->newTable($setup->getTable('product_updates'))
                ->addColumn(
                        'product_id', \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER, 
                        null, ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true], 
                        'Product ID'
                )
                ->addColumn(
                        'notes', \Magento\Framework\DB\Ddl\Table::TYPE_TEXT, 255, 
                        ['nullable' => false, 'default' => ''], 'Notes'
                )->setComment("Bulk update product IDs");
            $setup->getConnection()->createTable($table);
        }
        $setup->endSetup();
    }

}
