<?php
/**
 * ChannelUnity connector for Magento Commerce
 *
 * @category   Camiloo
 * @package    Camiloo_Channelunity
 * @copyright  Copyright (c) 2016-2026 ChannelUnity Limited (http://www.channelunity.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Camiloo\Channelunity\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Camiloo\Channelunity\Model\Helper;
use Camiloo\Channelunity\Model\Categories;

/**
 * ChannelUnity observers.
 * Posts events to the CU cloud when various Magento events occur.
 */
class SaveCategoryObserver implements ObserverInterface
{
    /**
     * @var Helper
     */
    private $helper;

    /**
     * @var Categories
     */
    private $cucategories;

    public function __construct(
        Helper $helper,
        Categories $categories
    ) {
        $this->helper = $helper;
        $this->cucategories = $categories;
    }

    /**
     * Executes when a category is saved.
     * * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $this->helper->logInfo("Observer called: Save Category");

        try {
            $myStoreURL = $this->helper->getBaseUrl() . "channelunity/api/index";

            // Format URL specifically for ChannelUnity API requirements
            $myStoreURL = str_replace(['http://', 'https://'], 'http%://', $myStoreURL);

            $this->cucategories->postCategoriesToCU($myStoreURL);
        } catch (\Exception $e) {
            // Ensure category saves gracefully in Magento even if API sync fails
            $this->helper->logError("Failed to sync categories to ChannelUnity: " . $e->getMessage());
        }
    }
}