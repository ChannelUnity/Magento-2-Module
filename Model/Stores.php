<?php
/**
 * ChannelUnity connector for Magento Commerce
 *
 * @category   Camiloo
 * @package    Camiloo_Channelunity
 * @copyright  Copyright (c) 2016-2026 ChannelUnity Limited (http://www.channelunity.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Camiloo\Channelunity\Model;

use Magento\Framework\Model\AbstractModel;
use Magento\Store\Model\StoreManagerInterface;
use Camiloo\Channelunity\Helper\Data;

/**
 * Reads information about the websites, stores, and store views.
 * ChannelUnity needs to know this information so we can then link
 * specific stores to specific marketplaces.
 */
class Stores extends AbstractModel
{
    /**
     * @var Helper
     */
    private $helper;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    public function __construct(
        Data $helper,
        StoreManagerInterface $storeManager
    ) {
        $this->helper = $helper;
        $this->storeManager = $storeManager;
    }

    /**
     * Posts Magento stores information to the ChannelUnity account.
     * * @param string $myURL
     * @return string
     */
    public function postStoresToCU(string $myURL): string
    {
        $messageToSend = "<StoreList>\n";

        // Get all websites in this Magento instance
        $websites = $this->storeManager->getWebsites();

        foreach ($websites as $website) {
            // Get all store views for the website
            $stores = $website->getStores();

            foreach ($stores as $storeView) {
                // Use strict typed getters instead of generic getData()
                $name = $storeView->getName();
                $code = $storeView->getCode();
                $websiteId = $storeView->getWebsiteId();
                $storeGroupId = $storeView->getStoreGroupId();
                $storeId = $storeView->getId();

                $messageToSend .= "<Store>
                        <FriendlyName><![CDATA[{$name} - {$code}]]></FriendlyName>
                        <URL><![CDATA[{$myURL}]]></URL>
                        <MainCountry><![CDATA[Unknown]]></MainCountry>
                        <FrameworkType><![CDATA[Magento]]></FrameworkType>
                        <WebsiteId><![CDATA[{$websiteId}]]></WebsiteId>
                        <StoreId><![CDATA[{$storeGroupId}]]></StoreId>
                        <StoreviewId><![CDATA[{$storeId}]]></StoreviewId>
                    </Store>\n";
            }
        }

        $messageToSend .= "</StoreList>\n";

        // Send to ChannelUnity
        $result = $this->helper->postToChannelUnity($messageToSend, "StoreData");

        // Safely parse the response to prevent fatal errors on bad API responses
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string((string)$result, 'SimpleXMLElement', LIBXML_NOCDATA);

        $returnXmlMsg = "";

        if ($xml === false) {
            $returnXmlMsg .= "<Status>Error - Invalid XML or unexpected response from API</Status>";
            $returnXmlMsg .= "<CreatedStores></CreatedStores>";
            return $returnXmlMsg;
        }

        if (isset($xml->Status)) {
            $returnXmlMsg .= "<Status>{$xml->Status}</Status>";
        } elseif (isset($xml->status)) {
            $returnXmlMsg .= "<Status>{$xml->status}</Status>";
        } else {
            $returnXmlMsg .= "<Status>Error - unexpected response format</Status>";
        }

        $returnXmlMsg .= "<CreatedStores>";

        // Only iterate if the property exists to prevent warnings
        if (isset($xml->CreatedStoreId)) {
            foreach ($xml->CreatedStoreId as $storeIdCreated) {
                $returnXmlMsg .= "<StoreId>{$storeIdCreated}</StoreId>";
            }
        }

        $returnXmlMsg .= "</CreatedStores>";

        return $returnXmlMsg;
    }
}