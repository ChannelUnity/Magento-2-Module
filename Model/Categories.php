<?php
/**
 * ChannelUnity connector for Magento Commerce
 *
 * @category   Camiloo
 * @package    Camiloo_Channelunity
 * @copyright  Copyright (c) 2016-2017 ChannelUnity Limited (http://www.channelunity.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Camiloo\Channelunity\Model;

use Magento\Framework\Model\AbstractModel;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Catalog\Helper\Category;
use Magento\Store\Model\StoreManagerInterface;

class Categories extends AbstractModel
{
    private $helper;
    private $categoryCollectionFactory;
    private $categoryHelper;
    private $storeManager;

    public function __construct(
        Helper $helper,
        CollectionFactory $categoryCollectionFactory,
        Category $categoryHelper,
        StoreManagerInterface $storeManager
    ) {
        $this->helper = $helper;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->categoryHelper = $categoryHelper;
        $this->storeManager = $storeManager;
    }

    /**
     * Returns an XML list of all categories in this Magento installation.
     */
    public function enumerateCategoriesForStoreView(
        $urlTemp,
        $frameworkType,
        $websiteId,
        $storeId,
        $rootCatId,
        $storeViewId
    ) {
        $messageToSend = "";

        // Load in this root category and enumerate all children
        $collection = $this->categoryCollectionFactory->create();
        $collection->addAttributeToSelect('*');

        // need to be able to link categories up to the right source/store in CU.

        $messageToSend .= "<CategoryList>
            <URL><![CDATA[{$urlTemp}]]></URL>
            <FrameworkType><![CDATA[{$frameworkType}]]></FrameworkType>
            <WebsiteId><![CDATA[{$websiteId}]]></WebsiteId>
            <StoreId><![CDATA[{$storeId}]]></StoreId>
            <StoreviewId><![CDATA[{$storeViewId}]]></StoreviewId>\n";

        foreach ($collection as $category) {
            $catPathTemp = $category->getData('path');
            
            $startOfPath = strpos($catPathTemp, "$rootCatId/");
            $middleOfPath = strpos($catPathTemp, "/$rootCatId/");
            $endOfPath = strpos($catPathTemp, "/$rootCatId");
            $endLen = strlen($catPathTemp) - strlen("/$rootCatId");

            if ($startOfPath === 0     // start of path
                    || $middleOfPath > 0   // middle of path
                    || $endOfPath == $endLen) { // OR at END of path
                $messageToSend .= "<Category>\n";
                $messageToSend .= "  <ID><![CDATA[{$category->getId()}]]></ID>\n";
                $messageToSend .= "  <Name><![CDATA[{$category->getName()}]]></Name>\n";
                $messageToSend .= "  <Position><![CDATA[{$category->getData('position')}]]></Position>\n";
                $messageToSend .= "  <CategoryPath><![CDATA[{$catPathTemp}]]></CategoryPath>\n";
                $messageToSend .= "  <ParentID><![CDATA[{$category->getData('parent_id')}]]></ParentID>\n";
                $messageToSend .= "  <Level><![CDATA[{$category->getData('level')}]]></Level>\n";
                $messageToSend .= "</Category>\n\n";
            }
        }

        $messageToSend .= "</CategoryList>";

        return $messageToSend;
    }

    public function postCategoriesToCU($urlTemp)
    {
        $messageToSend = '';
        
        $websites = $this->storeManager->getWebsites();

        // For each store view ...
        foreach ($websites as $website) {
            $websiteId = $website->getData('website_id');

            // Get all 'store groups'
            $stores = $website->getGroups();
            
            foreach ($stores as $storeGroup) {
                // Get the root category ID ...

                $rootCatId = $storeGroup->getData('root_category_id');
                $storeGroupId = $storeGroup->getData('group_id');
                $storeViews = $storeGroup->getStores();

                foreach ($storeViews as $storeView) {
                    $frameworkType = "Magento";
                    $storeViewId = $storeView->getData('store_id');
                    
                    $messageToSend = $this->enumerateCategoriesForStoreView(
                        $urlTemp,
                        $frameworkType,
                        $websiteId,
                        $storeGroupId,
                        $rootCatId,
                        $storeViewId
                    );
                    $result = $this->helper->postToChannelUnity($messageToSend, "CategoryData");
                    $xml = simplexml_load_string($result, 'SimpleXMLElement', LIBXML_NOCDATA);
                }
            }
        }

        if (isset($xml->Status)) {
            return $xml->Status;
        } elseif (isset($xml->status)) {
            return $xml->status;
        } else {
            return "Error - unexpected response";
        }
    }
}
