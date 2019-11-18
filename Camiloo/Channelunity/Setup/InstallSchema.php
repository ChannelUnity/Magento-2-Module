<?php

namespace Camiloo\Channelunity\Setup;

use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

/**
 * @codeCoverageIgnore
 */
class InstallSchema implements InstallSchemaInterface
{
    /**
    * {@inheritdoc}
    * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
    */
    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
          /**
            * Create table 'product_updates'.
            */
           $table = $setup->getConnection()
               ->newTable($setup->getTable('product_updates'))
               ->addColumn(
                   'product_id',
                   \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER,
                   null,
                   ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true],
                   'Product ID'
               )
               ->addColumn(
                   'notes',
                   \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                   255,
                   ['nullable' => false, 'default' => ''],
                   'Notes'
               )->setComment("Bulk update product IDs");
           $setup->getConnection()->createTable($table);
           
           /**
            * Create table 'order_import_history'.
            */
           $table = $setup->getConnection()
                ->newTable($setup->getTable('order_import_history'))
                ->addColumn(
                    'id',
                    \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER,
                     null,
                     ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true],
                     'ID'
                    )
                ->addColumn(
                    'remote_order_id',
                    \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                    100,
                    ['nullable' => false, 'default' => ''],
                    'Marketplace Order ID'
                )->addColumn(
                    'subscription_id',
                    \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER,
                    null,
                    ['nullable' => false, 'default' => 0],
                    'Subscription ID'
                )->addColumn(
                    'created_at',
                    \Magento\Framework\DB\Ddl\Table::TYPE_DATETIME,
                    null,
                    ['nullable' => false],
                    'Created At'
                )
                ->setComment("Record orders we've tried to import");
            $setup->getConnection()->createTable($table);

            $setup->getConnection()->addIndex(
                $setup->getTable('order_import_history'),
                $setup->getIdxName('order_import_history', ['remote_order_id', 'subscription_id']),
                ['remote_order_id', 'subscription_id']
            );
      }
}