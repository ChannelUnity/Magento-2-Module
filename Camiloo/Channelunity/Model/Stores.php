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
use Magento\Store\Model\StoreManagerInterface;

/**
 * Reads information about the websites, stores, and store views.
 * ChannelUnity needs to know this information so we can then link
 * specific stores to specific marketplaces.
 */
class Stores extends AbstractModel
{
    private $helper;
    private $storeManager;
    
    public function __construct(
        Helper $helper,
        StoreManagerInterface $storeManager
    ) {
        $this->helper = $helper;
        $this->storeManager = $storeManager;
    }

    /**
     * Posts Magento stores information to the ChannelUnity account.
     * @param type $myURL
     * @return string
     */
    public function postStoresToCU($myURL)
    {
        $messageToSend = "<StoreList>\n";
        // Get all websites in this Magento
        $websites = $this->storeManager->getWebsites();
        // For each website
        foreach ($websites as $website) {
            // Get all 'store views'
            $stores = $website->getStores();
            // For each store view
            foreach ($stores as $storeView) {
                $messageToSend .= "<Store>
                        <FriendlyName><![CDATA[{$storeView->getData('name')} -"
                        . " {$storeView->getData('code')}]]></FriendlyName>
                        <URL><![CDATA[{$myURL}]]></URL>
                        <MainCountry><![CDATA[Unknown]]></MainCountry>
                        <FrameworkType><![CDATA[Magento]]></FrameworkType>
                        <WebsiteId><![CDATA[{$storeView->getData('website_id')}]]></WebsiteId>
                        <StoreId><![CDATA[{$storeView->getData('group_id')}]]></StoreId>
                        <StoreviewId><![CDATA[{$storeView->getData('store_id')}]]></StoreviewId>
                    </Store>";
            }
        }

        $messageToSend .= "</StoreList>\n";

        $result = $this->helper->postToChannelUnity($messageToSend, "StoreData");
        $xml = simplexml_load_string($result, 'SimpleXMLElement', LIBXML_NOCDATA);

        $returnXmlMsg = "";

        if (isset($xml->Status)) {
            $returnXmlMsg .= "<Status>{$xml->Status}</Status>";
        } elseif (isset($xml->status)) {
            $returnXmlMsg .= "<Status>{$xml->status}</Status>";
        } else {
            $returnXmlMsg .= "<Status>Error - unexpected response</Status>";
        }

        $returnXmlMsg .= "<CreatedStores>";

        foreach ($xml->CreatedStoreId as $storeIdCreated) {
            $returnXmlMsg .= "<StoreId>$storeIdCreated</StoreId>";
        }

        $returnXmlMsg .= "</CreatedStores>";

        return $returnXmlMsg;
    }
}
