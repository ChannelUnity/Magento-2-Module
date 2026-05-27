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

/**
 * ChannelUnity observers.
 * Posts events to the CU cloud when a category is deleted.
 */
class DeleteCategoryObserver implements ObserverInterface
{
    /**
     * @var Helper
     */
    private $helper;

    public function __construct(Helper $helper)
    {
        $this->helper = $helper;
    }

    /**
     * Executes when a category is deleted.
     * * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $this->helper->logInfo("Observer called: Delete Category");

        try {
            $category = $observer->getEvent()->getCategory();

            // Ensure the category object exists before proceeding
            if (!$category || !$category->getId()) {
                return;
            }

            $categoryId = $category->getId();

            $myStoreURL = $this->helper->getBaseUrl() . "channelunity/api/index";

            // Format URL specifically for ChannelUnity API requirements
            $myStoreURL = str_replace(['http://', 'https://'], 'http%://', $myStoreURL);

            // Create XML
            $xml = <<<XML
<CategoryDelete>
    <SourceURL>{$myStoreURL}</SourceURL>
    <DeletedCategoryId>{$categoryId}</DeletedCategoryId>
</CategoryDelete>
XML;

            // Send XML to CU
            $this->helper->postToChannelUnity($xml, 'categoryDelete');

        } catch (\Exception $e) {
            // Ensure category deletes gracefully in Magento even if API sync fails
            $this->helper->logError("Failed to sync category deletion to ChannelUnity: " . $e->getMessage());
        }
    }
}