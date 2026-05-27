<?php
/**
 * ChannelUnity connector for Magento Commerce
 *
 * @category   Camiloo
 * @package    Camiloo_Channelunity
 * @copyright  Copyright (c) 2016-2024 ChannelUnity Limited (http://www.channelunity.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Camiloo\Channelunity\Helper;

use Magento\Framework\App\Helper\Context;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Config\Model\ResourceModel\Config;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Provides access to store settings, and cURL.
 */
class Helper extends \Magento\Framework\App\Helper\AbstractHelper
{
    private $curlClient;
    private $storeManager;
    private $config;
    private $productMetadata;
    private $configWriter;
    private $jsonSerializer;

    public function __construct(
        Context $context,
        Curl $curl,
        StoreManagerInterface $storeManager,
        Config $config,
        ProductMetadataInterface $productMetadata,
        WriterInterface $configWriter,
        Json $jsonSerializer
    ) {
        parent::__construct($context);
        $this->curlClient = $curl;
        $this->storeManager = $storeManager;
        $this->config = $config;
        $this->productMetadata = $productMetadata;
        $this->configWriter = $configWriter;
        $this->jsonSerializer = $jsonSerializer;
    }

    public function getConfig($config)
    {
        return $this->scopeConfig->getValue($config, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Makes a remote web request to the given URL, posting the given data array.
     */
    public function postRequest(string $url, array $requestData): string
    {
        $this->curlClient->setOptions([
            CURLOPT_CONNECTTIMEOUT => 30,  // Increased from 20 to 30
            CURLOPT_TIMEOUT => 120,        // Increased from 20 to 120 seconds!
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POSTREDIR => 7,
        ]);

        $this->curlClient->post($url, $requestData);

        return $this->curlClient->getBody();
    }

    /**
     * The ChannelUnity API endpoint.
     */
    public function getEndpoint(): string
    {
        return "https://my.channelunity.com/event.php";
    }

    /**
     * Calls the VerifyNotification API.
     */
    public function verifypost(string $messageverify)
    {
        libxml_use_internal_errors(true);
        $xml = urlencode("<?xml version=\"1.0\" encoding=\"utf-8\" ?>
            <ChannelUnity>
            <MerchantName>" . $this->getMerchantName() . "</MerchantName>
            <Authorization>" . $this->getValidUserAuth() . "</Authorization>
            <ApiKey>" . $this->getApiKey() . "</ApiKey>
            <RequestType>VerifyNotification</RequestType>
            <Payload>$messageverify</Payload>
            </ChannelUnity>");

        $result = $this->postRequest($this->getEndpoint(), ['message' => $xml]);
        $xmlResult = simplexml_load_string($result, 'SimpleXMLElement', LIBXML_NOCDATA);

        if (!is_object($xmlResult)) {
            throw new LocalizedException(__('Result XML was not loaded in verifypost()'));
        }

        if ((string) $xmlResult->Status != "OK") {
            throw new LocalizedException(__((string) $xmlResult->Status));
        }

        return $xmlResult;
    }

    public function verifyMyself(): string
    {
        libxml_use_internal_errors(true);
        $result = $this->postToChannelUnity("", "ValidateUser");

        if (stripos($result, "<MerchantName>") !== false) {
            return "<Status>OK</Status>\n";
        }

        $xml = simplexml_load_string($result, 'SimpleXMLElement', LIBXML_NOCDATA);

        if (isset($xml->Status)) {
            return "<Status>{$xml->Status}</Status>\n";
        } elseif (isset($xml->status)) {
            return "<Status>{$xml->status}</Status>\n";
        }

        return "<Status>Error - unexpected response</Status>";
    }

    public function getMerchantName()
    {
        return $this->getConfig('channelunityint/generalsettings/merchantname');
    }

    /**
     * Returns a Base64 encoded user authentication string.
     */
    public function getValidUserAuth(string $user = null, string $pass = null): string
    {
        if ($user != null) {
            $auth = $user . ":" . hash("sha256", (string)$pass);
        } else {
            $auth = $this->getConfig('channelunityint/generalsettings/merchantusername')
                . ":" . hash("sha256", (string)$this->getConfig('channelunityint/generalsettings/merchantpassword'));
        }

        return base64_encode($auth);
    }

    /**
     * Returns the CU API Key, calling to CU server if needed to retrieve it.
     */
    public function getApiKey(string $merchantname = null, string $username = null, string $password = null)
    {
        libxml_use_internal_errors(true);
        $apikeyTemp = $this->getConfig('channelunityint/generalsettings/apikey');

        if (!$apikeyTemp) {
            $xml = "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<ChannelUnity>\n";

            if ($merchantname != null) {
                $xml .= "<MerchantName>" . $merchantname . "</MerchantName>\n";
                $xml .= "<Authorization>" . $this->getValidUserAuth($username, $password) . "</Authorization>\n";
            } else {
                $xml .= "<MerchantName>" . $this->getMerchantName() . "</MerchantName>\n";
                $xml .= "<Authorization>" . $this->getValidUserAuth() . "</Authorization>\n";
            }

            $xml .= "<RequestType>ValidateUser</RequestType>\n</ChannelUnity>";

            try {
                $result = $this->postRequest($this->getEndpoint(), ['message' => urlencode($xml)]);
                $xmlObj = simplexml_load_string($result, 'SimpleXMLElement', LIBXML_NOCDATA);

                if (isset($xmlObj->ApiKey)) {
                    $this->config->saveConfig('channelunityint/generalsettings/apikey', $xmlObj->ApiKey, 'default', 0);
                    $this->configWriter->save('channelunityint/generalsettings/apikey', $xmlObj->ApiKey, 'default', 0);
                    $apikeyTemp = $xmlObj->ApiKey;
                }
            } catch (\Exception $e) {
                $this->logError($e->getMessage());
            }
        }

        return $apikeyTemp;
    }

    public function setCredentialsInModule(string $merchantname, string $username, string $password)
    {
        $this->config->saveConfig('channelunityint/generalsettings/merchantname', $merchantname);
        $this->config->saveConfig('channelunityint/generalsettings/merchantusername', $username);
        $this->config->saveConfig('channelunityint/generalsettings/merchantpassword', $password);
        $this->config->saveConfig('channelunityint/generalsettings/apikey', '');

        $this->storeManager->getStore(null)->resetConfig();

        $this->getApiKey($merchantname, $username, $password);
        $this->postMyURLToChannelUnity($merchantname);
    }

    public function postToChannelUnity(string $xmlPayload, string $requestType): string
    {
        $xml = "<?xml version=\"1.0\" encoding=\"utf-8\" ?>
            <ChannelUnity>
            <MerchantName>" . $this->getMerchantName() . "</MerchantName>
            <Authorization>" . $this->getValidUserAuth() . "</Authorization>
            <ApiKey>" . $this->getApiKey() . "</ApiKey>
            <RequestType>$requestType</RequestType>
            <Payload>$xmlPayload</Payload>
            </ChannelUnity>";

        $this->logInfo('TX ' . $xml);
        $result = $this->postRequest($this->getEndpoint(), ['message' => urlencode($xml)]);
        $this->logInfo('RX ' . $result);

        return $result;
    }

    public function postMyURLToChannelUnity(string $merchantName): string
    {
        $baseurl = $this->getBaseUrl();

        $xml = "<?xml version=\"1.0\" encoding=\"utf-8\" ?>
            <ChannelUnity>
            <MerchantName>$merchantName</MerchantName>
            <Authorization>" . $this->getValidUserAuth() . "</Authorization>
            <RequestType>SuggestEndpointURL</RequestType>
            <Payload><URL>$baseurl</URL></Payload>
            </ChannelUnity>";

        return $this->postRequest($this->getEndpoint(), ['message' => urlencode($xml)]);
    }

    public function ignoreDisabled()
    {
        return $this->getConfig('channelunityint/generalsettings/ignoredisabledproducts');
    }

    public function forceTaxValues()
    {
        return $this->getConfig('channelunityint/generalsettings/priceinctax');
    }

    public function getSyncOption()
    {
        return $this->getConfig('channelunityint/generalsettings/updatestockprice');
    }

    public function allowStubProducts()
    {
        return $this->getConfig('channelunityint/generalsettings/allowstubproducts');
    }

    public function enableLogging()
    {
        return $this->getConfig('channelunityint/generalsettings/enablelogging');
    }

    public function updateProductData($storeViewId, string $data): string
    {
        $sourceUrl = $this->getBaseUrl();

        $xml = <<<XML
<Products>
    <SourceURL>{$sourceUrl}</SourceURL>
    <StoreViewId>{$storeViewId}</StoreViewId>
    {$data}
</Products>
XML;
        return $this->postToChannelUnity($xml, 'ProductData');
    }

    public function getBaseUrl(): string
    {
        return $this->storeManager->getStore()->getBaseUrl();
    }

    public function logInfo(string $msg)
    {
        if ($this->enableLogging()) {
            $this->_logger->info($msg);
        }
    }

    public function logError(string $msg)
    {
        if ($this->enableLogging()) {
            $this->_logger->error($msg);
        }
    }

    public function channelSkuToName(string $serviceType): string
    {
        $csku = [
            "CU_AMZ_UK" => "Amazon.co.uk",
            "CU_AMZ_FR" => "Amazon.fr",
            "CU_AMZ_DE" => "Amazon.de",
            "CU_AMZ_CA" => "Amazon.ca",
            "CU_AMZ_COM" => "Amazon.com",
            "CU_AMZ_JP" => "Amazon.co.jp",
            "CU_AMZ_IT" => "Amazon.it",
            "CU_AMZ_ES" => "Amazon.es",
            "CU_AMZ_CN" => "Amazon.cn",
            "CU_AMZ_IN" => "Amazon.in",
            "CU_AMZ_MX" => "Amazon.com.mx",
            "CU_AMZ_SE" => "Amazon.se",
            "CU_AMZ_NL" => "Amazon.nl",
            "CU_AMZ_AU" => "Amazon.com.au",
            "CU_EBAY_COM" => "eBay USA",
            "CU_EBAY_COM_MOTORS" => "eBay USA Motors",
            "CU_EBAY_UK" => "eBay UK",
            "CU_EBAY_CH" => "eBay Switzerland",
            "CU_EBAY_ES" => "eBay Spain",
            "CU_EBAY_SG" => "eBay Singapore",
            "CU_EBAY_PL" => "eBay Poland",
            "CU_EBAY_PH" => "eBay Philippines",
            "CU_EBAY_NL" => "eBay Netherlands",
            "CU_EBAY_MY" => "eBay Malaysia",
            "CU_EBAY_IT" => "eBay Italy",
            "CU_EBAY_IE" => "eBay Ireland",
            "CU_EBAY_IN" => "eBay India",
            "CU_EBAY_HK" => "eBay Hong Kong",
            "CU_EBAY_DE" => "eBay Germany",
            "CU_EBAY_FR" => "eBay France",
            "CU_EBAY_CA" => "eBay Canada",
            "CU_EBAY_CAFR" => "eBay Canada (FR)",
            "CU_EBAY_BEFR" => "eBay Belgium (FR)",
            "CU_EBAY_BENL" => "eBay Belgium (Dutch)",
            "CU_EBAY_AT" => "eBay Austria",
            "CU_EBAY_AU" => "eBay Australia",
            "CU_QOO10" => "Qoo10",
            "CU_NEWEGG" => "Newegg.com",
            "CU_TESCO_COM" => "Tesco.com",
            "CU_FRUUGO_COM" => "Fruugo.com",
            "CU_ETSY_COM" => "Etsy",
            "CU_WAYFAIR_UK" => "Wayfair",
            "CU_CLEVERBOXES_COM" => "Cleverboxes",
            "CU_SPS_COM" => "SPS",
            "CU_OTTO_DE" => "Otto",
            "CU_BIGCOMMERCE_COM" => "Bigcommerce",
            "CU_ZALANDO_COM" => "Zalando",
            "CU_PLEDGEMANAGER_COM" => "Pledgemanager",
            "CU_WALMART_COM" => "Walmart",
        ];

        return $csku[$serviceType] ?? "Unknown";
    }

    public function getMagentoVersion(): string
    {
        return $this->productMetadata->getVersion();
    }

    /**
     * Serializes data using Magento's modern JSON serializer
     * (replacing deprecated ObjectManager and standard serialize)
     */
    public function serialize($data): string
    {
        return $this->jsonSerializer->serialize($data);
    }

    public function getTrackingNumbers($tracksCollection, $newTrack = null): array
    {
        $trkNumbers = [];

        if ($newTrack) {
            $this->checkTrack($newTrack, $trkNumbers);
        }

        foreach ($tracksCollection->getItems() as $track) {
            $this->checkTrack($track, $trkNumbers);
        }
        return $trkNumbers;
    }

    private function checkTrack($track, array &$trkNumbers)
    {
        $carrierName = $track->getCarrierCode();
        if ($carrierName == "custom") {
            $carrierName = $track->getTitle();
        }
        $shipMethod = $track->getTitle();
        $trackingNumber = $track->getNumber();

        $trackingType = strpos($shipMethod, 'Return') === 0 ? 'ReturnTracking' : 'Tracking';
        $trkNumbers[$trackingType] = [
            'TrackingNumber' => $trackingNumber,
            'CarrierName' => $carrierName,
            'ShipMethod' => str_replace('Return', '', $shipMethod)
        ];
    }
}