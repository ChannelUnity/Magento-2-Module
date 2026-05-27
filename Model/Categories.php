<?php
/**
 * ChannelUnity connector for Magento Commerce
 *
 * @category   Camiloo
 * @package    Camiloo_Channelunity
 * @copyright  Copyright (c) 2016-2024 ChannelUnity Limited (http://www.channelunity.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Camiloo\Channelunity\Model;

use Magento\Framework\Model\AbstractModel;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Camiloo\Channelunity\Helper\Data;

class Categories extends AbstractModel
{
    /**
     * @var Helper
     */
    private $helper;

    /**
     * @var CollectionFactory
     */
    private $categoryCollectionFactory;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    public function __construct(
        Data $helper,
        CollectionFactory $categoryCollectionFactory,
        StoreManagerInterface $storeManager
    ) {
        $this->helper = $helper;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
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
        $messageToSend = "<CategoryList>
            <URL><![CDATA[{$urlTemp}]]></URL>
            <FrameworkType><![CDATA[{$frameworkType}]]></FrameworkType>
            <WebsiteId><![CDATA[{$websiteId}]]></WebsiteId>
            <StoreId><![CDATA[{$storeId}]]></StoreId>
            <StoreviewId><![CDATA[{$storeViewId}]]></StoreviewId>\n";

        // Load collection highly optimized
        $collection = $this->categoryCollectionFactory->create();
        $collection->setStoreId($storeViewId);

        // Only load the specific EAV attributes we need to save memory
        $collection->addAttributeToSelect('name');

        // Filter by path directly in the database (replaces the PHP strpos loop)
        $collection->addFieldToFilter('path', [
            ['like' => $rootCatId . '/%'],         // Start of path
            ['like' => '%/' . $rootCatId . '/%'],  // Middle of path
            ['like' => '%/' . $rootCatId],         // End of path
            ['eq' => (string) $rootCatId]          // Exact match
        ]);

        foreach ($collection as $category) {
            $catName = $category->getName() ?: 'Unknown'; // Fallback for empty names

            $messageToSend .= "<Category>\n";
            $messageToSend .= "  <ID><![CDATA[{$category->getId()}]]></ID>\n";
            $messageToSend .= "  <Name><![CDATA[{$catName}]]></Name>\n";
            $messageToSend .= "  <Position><![CDATA[{$category->getPosition()}]]></Position>\n";
            $messageToSend .= "  <CategoryPath><![CDATA[{$category->getPath()}]]></CategoryPath>\n";
            $messageToSend .= "  <ParentID><![CDATA[{$category->getParentId()}]]></ParentID>\n";
            $messageToSend .= "  <Level><![CDATA[{$category->getLevel()}]]></Level>\n";
            $messageToSend .= "</Category>\n\n";
        }

        $messageToSend .= "</CategoryList>";

        return $messageToSend;
    }

    public function postCategoriesToCU($urlTemp)
    {
        $finalStatus = "OK";
        $websites = $this->storeManager->getWebsites();

        foreach ($websites as $website) {
            $websiteId = $website->getId();
            $stores = $website->getGroups();

            foreach ($stores as $storeGroup) {
                $rootCatId = $storeGroup->getRootCategoryId();
                $storeGroupId = $storeGroup->getId();
                $storeViews = $storeGroup->getStores();

                foreach ($storeViews as $storeView) {
                    $frameworkType = "Magento";
                    $storeViewId = $storeView->getId();

                    $messageToSend = $this->enumerateCategoriesForStoreView(
                        $urlTemp,
                        $frameworkType,
                        $websiteId,
                        $storeGroupId,
                        $rootCatId,
                        $storeViewId
                    );

                    $result = $this->helper->postToChannelUnity($messageToSend, "CategoryData");

                    if ($result) {
                        $xml = simplexml_load_string($result, 'SimpleXMLElement', LIBXML_NOCDATA);

                        // Capture any errors, otherwise keep "OK"
                        if (isset($xml->Status) && (string)$xml->Status !== "OK") {
                            $finalStatus = (string)$xml->Status;
                        } elseif (isset($xml->status) && (string)$xml->status !== "OK") {
                            $finalStatus = (string)$xml->status;
                        }
                    } else {
                        $finalStatus = "Error - unexpected response";
                    }
                }
            }
        }

        return $finalStatus;
    }
}