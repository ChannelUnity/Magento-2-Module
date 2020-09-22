<?php

/**
 * ChannelUnity connector for Magento Commerce
 *
 * @category   Camiloo
 * @package    Camiloo_Channelunity
 * @copyright  Copyright (c) 2016 ChannelUnity Limited (http://www.channelunity.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
/**
 * ChannelUnity observers.
 * Posts events to the CU cloud when various Magento events occur.
 */

namespace Camiloo\Channelunity\Observer;

use \Magento\Framework\Event\ObserverInterface;
use \Magento\Framework\Event\Observer;
use \Magento\Framework\App\RequestInterface;
use \Magento\Store\Model\StoreManagerInterface;
use \Magento\Catalog\Api\ProductRepositoryInterface;
use \Camiloo\Channelunity\Model\Helper;
use \Camiloo\Channelunity\Model\Products;
use \Magento\Framework\Registry;

class ProductIsSalableObserver implements ObserverInterface
{
    private $helper;
    private $productModel;
    private $storeManager;
    private $productRepository;
    private $request;
    private $registry;

    public function __construct(
        Helper $helper,
        Products $product,
        StoreManagerInterface $storeManager,
        ProductRepositoryInterface $productRepository,
        RequestInterface $request,
        Registry $registry
    ) {
        $this->helper = $helper;
        $this->productModel = $product;
        $this->storeManager = $storeManager;
        $this->productRepository = $productRepository;
        $this->request = $request;
        $this->registry = $registry;
    }

    public function execute(Observer $observer)
    {
        $isCuOrder = $this->registry->registry('cu_order_in_progress');
        if ($isCuOrder) {
            $this->helper->logInfo("Observer called: ProductIsSalableObserver");
        
            $observer->getSalable()->setIsSalable(true);
        }
    }

}
