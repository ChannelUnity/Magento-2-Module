<?php

namespace Camiloo\Channelunity\Plugin;
use Magento\Framework\Registry;

class StockPlugin
{
    private $registry;

    public function __construct(
        Registry $registry) {
      $this->registry = $registry;
    }

    public function aroundCheckQuoteItemQty($target, $proceed, $stockItem, $qty, $summaryQty, $origQty = 0) {
        $isCuOrder = $this->registry->registry('cu_order_in_progress');
        
        return $proceed($stockItem, $qty, $isCuOrder ? $qty : $summaryQty, $origQty);
    }
}

