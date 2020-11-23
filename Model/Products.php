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
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Model\Stock\StockItemRepository;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Model\ResourceModel\Iterator;
use Magento\Framework\Registry;
use Magento\Catalog\Model\ProductFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;

/**
 * Converts Magento product data into ChannelUnity formatted product data.
 */
class Products extends AbstractModel
{
    private $helper;
    private $product;
    private $stockItemRepository;
    private $storeManager;
    private $iterator;
    private $registry;
    private $upperLimit = 250;
    private $rangeNext = 0;
    private $buffer = "";
    private $productFactory;
    private $stockRegistry;
    private $currentStore;

    public function __construct(
        Helper $helper,
        Product $product,
        StockItemRepository $stockItemRepository,
        StoreManagerInterface $storeManager,
        Iterator $iterator,
        Registry $registry,
        ProductFactory $productFactory,
        StockRegistryInterface $stockRegistry
    ) {
        $this->helper = $helper;
        $this->stockItemRepository = $stockItemRepository;
        $this->product = $product;
        $this->storeManager = $storeManager;
        $this->iterator = $iterator;
        $this->registry = $registry;
        $this->productFactory = $productFactory;
        $this->stockRegistry = $stockRegistry;
    }
    
    /**
     * Makes an API call to ChannelUnity which will post all attributes.
     * @return string
     */
    public function postAttributesToCU()
    {
        $messageToSend = "<ProductAttributes>\n";
    
        $attributes = $this->product->getAttributes();

        foreach ($attributes as $attribute) {
            $attr = $attribute->getData('attribute_code');

            if ($attr != 'name' && $attr != 'description' && $attr != 'sku'
                    && $attr != 'price' && $attr != 'qty' && $attr != 'stock_item') {
                $attrType = trim($attribute->getBackendType());
                $friendlyName = trim($attribute->getFrontendLabel());

                $messageToSend .= "<Attribute><Name>$attr</Name><Type>$attrType</Type>
                    <FriendlyName><![CDATA[{$friendlyName}]]></FriendlyName></Attribute>\n";
            }
        }

        $messageToSend .= "</ProductAttributes>\n";

        $result = $this->helper->postToChannelUnity($messageToSend, "ProductAttributes");
        $xml = simplexml_load_string($result, 'SimpleXMLElement', LIBXML_NOCDATA);

        if (isset($xml->Status)) {
            return $xml->Status;
        } elseif (isset($xml->status)) {
            return $xml->status;
        } else {
            return "Error - unexpected response";
        }
    }

    /**
     * Given a product ID and store ID, returns an XML representation of this
     * product.
     *
     * @param type $productId       Product ID or product model
     * @param type $storeId         Store ID for which to generate the data
     * @param type $reduceStockBy   Optional stock qty modifier
     * @return string               XML product representation
     */
    public function generateCuXmlForSingleProduct($productId, $storeId, $reduceStockBy = 0)
    {
        $productXml = "";
        $this->currentStore = $storeId;
        // Maybe the product model is passed in directly
        if (is_object($productId)) {
            $productId = $productId->getId();
        }
        
        $product = $this->productFactory->create()->setStoreId($storeId)->load($productId);

        $skipProduct = $this->isProductGloballyDisabled($product->getId());

        if (!$skipProduct) {
            $productXml = $this->generateProductXml($product, $reduceStockBy);
            
            unset($product);
        } else {
            $productXml = '<DeletedProductId>' . $product->getId() . '</DeletedProductId>';
        }

        return $productXml;
    }
    
    /**
     * Checks all store views to see if a product is disabled on all of them.
     *
     * @param type $productId Product ID only
     */
    public function isProductGloballyDisabled($productId)
    {
        if (!$this->helper->ignoreDisabled()) {
            return false;
        }
        
        $websites = $this->storeManager->getWebsites();
        foreach ($websites as $website) {
            $stores = $website->getStores();
            foreach ($stores as $storeView) {
                $storeId = $storeView->getData('store_id');

                $product = $this->productFactory->create()->setStoreId($storeId)->load($productId);
                
                $skipProduct = $this->skipProduct($product);
                if (!$skipProduct) {
                    return false;
                }
            }
        }
        return true;
    }
    
    private function generateProductXml($product, $reduceStockBy)
    {
        // Get image URL to use as the 'main' image
        $imageUrl = $this->getBaseImageForProduct($product);
        $qty = 0;
        try {
            if (version_compare($this->helper->getMagentoVersion(), '2.2.0') >= 0) {
                $stock = $this->stockRegistry->getStockItem($product->getId());
            } else {
                $stock = $this->stockItemRepository->get($product->getId());
            }
            
            $qty = $stock->getData('qty') - $reduceStockBy;
        } catch (\Exception $e) {
            $this->helper->logError("Error generating product XML - ".$e->getMessage());
            
            // Stock item may not exist (creating new product)
            // We will get the qty via the afterSave() interceptor
            $stock = null;
            $qtyKey = "cu_product_qty_".$product->getId();
            if ($this->registry->registry($qtyKey) != null) {
                $qty = $this->registry->registry($qtyKey);
            }
        }

        $catids = implode(',', $product->getCategoryIds());

        $productXml = "<Product>\n";
        $productXml .= "  <RemoteId>" . $product->getId() . "</RemoteId>\n";
        $productXml .= "  <Title><![CDATA[{$product->getName()} ]]></Title>\n";
        $productXml .= "  <Description><![CDATA[{$product->getData('description')} ]]></Description>\n";
        $productXml .= "  <SKU><![CDATA[{$product->getData('sku')}]]></SKU>\n";
        $productXml .= "  <Price>" . number_format($product->getData('price'), 2, ".", "") . "</Price>\n";
        $productXml .= "  <Quantity>{$qty}</Quantity>\n";
        $productXml .= "  <Category>{$catids}</Category>\n";
        $productXml .= "  <Image><![CDATA[{$imageUrl}]]></Image>\n";

        // Add associated/child product references if applicable
        $productXml .= "  <RelatedSKUs>\n";

        $variationXml = "  <Variations>\n";

        if ($product->getData("type_id") == 'configurable') {
            $prdTypeInst = $product->getTypeInstance();

            $childProducts = $prdTypeInst->getUsedProducts($product);

            foreach ($childProducts as $cp) {
                $productXml .= "    <SKU><![CDATA[{$cp->getData('sku')}]]></SKU>\n";
            }

            $confAttributes = $prdTypeInst->getConfigurableAttributesAsArray($product);

            // Get the attribute(s) which vary
            if (is_array($confAttributes)) {
                foreach ($confAttributes as $cattr) {
                    $cattr = $this->helper->serialize($cattr);

                    $findTemp = "\"attribute_code\";";

                    $cattr = explode($findTemp, $cattr);

                    if (isset($cattr[1])) {
                        $cattr = explode("\"", $cattr[1]);

                        if (isset($cattr[1])) {
                            $variationXml .= "    <Variation><![CDATA[{$cattr[1]}]]></Variation>\n";
                        }
                    }
                }
            }
        }

        $variationXml .= "  </Variations>\n";
        $productXml .= "  </RelatedSKUs>\n";
        $productXml .= $variationXml;

        $productXml .= "  <Custom>\n";

        // Manually added attributes
        $moreAttributes = [];

        // Add media_gallery so it's the same as previous versions --------
        $mgStr = $this->getMediaGalleryString($product);
        $moreAttributes['media_gallery'] = $mgStr;

        // URL in store, good to get a link to product page ---------------
        $urlInStore = $product->getUrlInStore();
        $moreAttributes['url_in_store'] = $urlInStore;

        // Enumerate all other attribute values
        $productXml .= $this->enumerateCustomAttributesForProduct(
            $product,
            $stock,
            $moreAttributes
        );

        // All custom attributes now done
        $productXml .= "  </Custom>\n";
        $productXml .= "</Product>\n";
        return $productXml;
    }
    
    public function generateCuXmlSku($args)
    {
        $row = $args['row'];
        if (isset($row["sku"]) && !empty($row["sku"])) {
            $productId = $row["sku"];
            $this->buffer .= "<SKU><![CDATA[" . $productId . "]]></SKU>\n";
        }
    }
    
    public function getRangeNext()
    {
        return $this->rangeNext;
    }

    public function getAllSKUs()
    {
        $this->buffer = "<Payload>\n";
        $this->buffer .= "<CartType>Magento 2</CartType>\n";
        // Load collection of all products
        $collectionOfProduct = $this->product->getCollection()->addAttributeToSelect('sku');
        // Are we ignoring disabled products?
        $ignoreDisabled = $this->helper->ignoreDisabled();

        if ($ignoreDisabled == 1) {
            $collectionOfProduct->addFieldToFilter('status', 1);
        }

        // Iterate over all SKUs efficiently
        // Note store ID 0 means we're not doing any store filtering
        $this->iterator->walk(
            $collectionOfProduct->getSelect(),
            [[$this, 'generateCuXmlSku']],
            ['storeId' => 0],
            $collectionOfProduct->getSelect()->getAdapter()
        );
        $this->buffer .= "</Payload>\n";
        return $this->buffer;
    }

    /**
     * Return a set of product data to CU.
     */
    public function doRead($request, $bAddSourceUrl = false)
    {
        // The inclusive Magento product ID from which to start the sync
        // for this batch
        $rangeFrom = (string) $request->RangeFrom;
        // Get the current store view that we're interested in
        $storeId = (string) $request->StoreviewId; // TODO do we actually need the StoreId?

        $str = "<Products>\n";
        if ($bAddSourceUrl) {
            // We are adding the URL of our store
            $sourceUrl = $this->helper->getBaseUrl();
            $str .= "<SourceURL>{$sourceUrl}</SourceURL>\n";
            $str .= "<StoreViewId>0</StoreViewId>\n";
        }

        try {
            // Load product collection, applying store filter and starting ID
            $collectionOfProduct = $this->product->getCollection()->addStoreFilter($storeId);
            $collectionOfProduct->addAttributeToFilter("entity_id", ['gteq' => $rangeFrom])
                    ->setOrder('entity_id', 'ASC');
            $collectionOfProduct->getSelect()->limit($this->upperLimit);

            // Make sure we get all data that we can
            $products = $collectionOfProduct->setFlag('has_stock_status_filter', true)->addAttributeToSelect('*')->load();

            foreach ($products as $p) {
                // Save the ID for when we do the next batch
                $this->rangeNext = $p->getData("entity_id") + 1;
                // Get the actual data itself!
                $str .= $this->generateCuXmlForSingleProduct($p, $storeId);
            }

            // Let the cloud know where to start from the next time it calls
            //   for product data
            $highestId = $this->getHighestEntityId();

            if ($this->rangeNext > $highestId) {
                // Start from beginning next time
                $this->rangeNext = 0;
            }
            
            $str .= "<RangeNext>" . $this->rangeNext . "</RangeNext>\n";

            if (!$bAddSourceUrl) {
                $totalNumProducts = $this->getTotalProductsSize();
                $str .= "<TotalProducts>$totalNumProducts</TotalProducts>\n";
            }
        } catch (\Exception $x) {
            $str .= "<Error><![CDATA[" . $x->getMessage() . ' - ' . $x->getTraceAsString() . "]]></Error>\n";
        }
        $str .= "</Products>\n";
        return $str;
    }
    
    //======================================================================
    // Below here lies private functions
    //======================================================================

    /**
     * Return the total number of products in this store.
     *
     * @return type
     */
    private function getTotalProductsSize()
    {
        $collectionOfProduct = $this->product->getCollection();

        // If ignoring disabled products, count only the enabled ones
        if ($this->helper->ignoreDisabled()) {
            $collectionOfProduct->addFieldToFilter('status', 1);
        }

//        $collectionOfProduct->addStoreFilter($storeId);
        $totalNumProducts = $collectionOfProduct->getSize();

        return $totalNumProducts;
    }

    /**
     * Returns the highest entity ID (product ID) so we know when we
     * reach the end of the list of products.
     *
     * @return type
     */
    private function getHighestEntityId()
    {
        $collectionOfProduct = $this->product->getCollection();

        // Only to retrieve the last item in shop
        $collectionOfProduct->setOrder('entity_id', 'DESC');
        $firstItem = $collectionOfProduct->getFirstItem();//->setPageSize(1); -TODO Test adding this
        $entityId = $firstItem->getEntityId();

        return $entityId;
    }

    /**
     * Returns the product 'base' image, useful to use as the 'main' image
     * for the ChannelUnity product.
     *
     * @param type $product     Product model
     * @return string           Image URL
     */
    private function getBaseImageForProduct($product)
    {
        $imageUrl = '';
        $rawImage = $product->getImage();
        
        try {
            if ($rawImage != '') {
                $storeObject = $this->storeManager->getStore();

                $mediaUrl = $storeObject->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA);

                $imageUrl = $mediaUrl . 'catalog/product' . $rawImage;
            }
        } catch (\Exception $e) {
            $imageUrl = '';
        }
        return $imageUrl;
    }
    
    private function getProductAttributeData($product) {
        
        $attributeData = $product->getData();
        
        $customAttributes = $product->getCustomAttributes(); 
        foreach ($customAttributes as $attribute) {
            if (!isset($attributeData[$attribute->getAttributeCode()]) && false) {
                $attribute->setStoreId($this->currentStore);
                $attributeData[$attribute->getAttributeCode()] = $attribute->getvalue();
                //$product->getResource()->getAttribute($attribute->getAttributeCode())->getFrontend()->getValue($product); 
            }
        }
        
        return $attributeData;
    }
    
    /**
     * Returns bundle items information for the given product.
     * @param type $product
     */
    private function getProductBundleItems($product) {
        $productsArray = [];
        $qtyInBundle = [];
        $optionIdToPosition = [];
        
        $selectionCollection = $product->getTypeInstance(true)
	        ->getSelectionsCollection(
	            $product->getTypeInstance(true)->getOptionsIds($product),
	            $product
	        );
        
        foreach ($selectionCollection as $pselection) {
            
            if (array_key_exists($pselection->getOptionId(), $optionIdToPosition)) {
                $position = $optionIdToPosition[$pselection->getOptionId()];
            }
            else {
                $position = count($optionIdToPosition)+1;
                $optionIdToPosition[$pselection->getOptionId()] = $position;
            }
            $selectionArray = [];
            $selectionArray['product_name'] = $pselection->getName();
            $selectionArray['product_price'] = $pselection->getPrice();
            $selectionArray['product_qty'] = $pselection->getSelectionQty();
            $selectionArray['product_id'] = $pselection->getProductId();
            $selectionArray['product_sku'] = $pselection->getSku();
            $qtyInBundle[$position][$pselection->getSku()] = (int)$pselection->getSelectionQty();
            $productsArray[$pselection->getOptionId()][$pselection->getSelectionId()] = $selectionArray;
        }
        $this->helper->logInfo(var_export($productsArray, true));
        $productsArray2 = [];
        
        // Generate all Bundle SKUs starting with the parent
        $currentSKU = $product->getSku();
        $this->combineBundleSKU($currentSKU, $productsArray, $productsArray2);
        
        return array($productsArray2, $qtyInBundle);
    }
    
    /**
     * Generates all the possible SKU combinations for a Bundle product.
     */
    private function combineBundleSKU($currentSKU, $productsArray, &$productsArray2, $kStart = 0) {
        
        foreach ($productsArray as $k => $itemList) {
            if ($k <= $kStart) {
                continue;
            }
            
            foreach ($itemList as $item) {
                $this->combineBundleSKU($currentSKU."^".$item['product_sku'], $productsArray, $productsArray2, $k);
            }
        }
        
        if (substr_count($currentSKU, '^') == count($productsArray)) {
            $productsArray2[] = $currentSKU;
        }
    }

    /**
     * Iterates over attributes and values of the given product and returns
     * this as an XML snippet.
     *
     * @param type $product Product Model
     * @param type $stock   Stock Model
     * @return string       XML snippet string
     */
    private function enumerateCustomAttributesForProduct(
        $product,
        $stock,
        $moreAttributes
    ) {
        $productXml = "";
        $attributeData = $this->getProductAttributeData($product);
        // Build up array of all keys and values we want to include
        if ($stock) {
            $masterArray = array_merge($attributeData, $stock->getData(), $moreAttributes);
        } else {
            $masterArray = array_merge($attributeData, $moreAttributes);
        }
        
        // Sort array by keys
        ksort($masterArray);
        foreach ($masterArray as $key => $value) {
            
            // Added the below check to skip the loop for _cache_instance_products
            // which is not needed or else it will throw array to string conversion exceptions.
            // Other attributes removed because they would make duplicates
            if (in_array($key, [
                        'name', 'description', 'sku', 'price', 'qty',
                        'stock_item', 'tier_price', '_cache_instance_products',
                        '_cache_instance_product_set_attributes',
                        '_cache_instance_used_attributes',
                        '_cache_instance_used_product_attributes',
                        'has_options', 'required_options', 'category_ids',
                        'item_id', 'product_id', 'stock_id',
                        'use_config_backorders',
                        'use_config_enable_qty_inc',
                        'use_config_manage_stock',
                        'use_config_max_sale_qty',
                        'use_config_min_qty',
                        'use_config_min_sale_qty',
                        'use_config_notify_stock_qty',
                        'use_config_qty_increments',
                        'is_decimal_divided',
                        'is_qty_decimal',
                        'downloadable_links',
                        'options',
                        'options_container',
                        'stock_status_changed_auto',
                        'min_qty_allowed_in_shopping_cart',
                        'stock_status_changed_automatically_flag',
                        'tier_price_changed',
                    ])) {
                continue;
            }

            // Sanitise the key for XML elements
            $k = preg_replace('/[^A-Za-z0-9_]/', '', $key);

            // Not that it should happen, but XML element starting with
            // a number is illegal
            if (is_numeric(substr($k, 0, 1))) {
                $k = 'C' . $k;
            }

            // Check for multi-value attributes. Convert keys/IDs to values
            $attribute = $product->getResource()->getAttribute($key);
            if (is_object($attribute) && $attribute->usesSource() && is_object($attribute->getSource())) {
                $attribute->setStoreId($this->currentStore);
                $attributeOptions = $attribute->getSource()->getAllOptions();

                $valueBuildArray = [];
                // There could be multiple IDs, need to find a label for each one
                if (is_array($value)) {
                    $startValues = $value;
                } else {
                    $startValues = explode(",", (string) $value);
                }

                foreach ($attributeOptions as $attrbOpt) {
                    if (in_array($attrbOpt['value'], $startValues)) {
                        $valueBuildArray[] = (string) $attrbOpt['label'];
                    }
                }

                $value = implode(',', $valueBuildArray);
            }

            // Prevent array to string conversion error
            if (is_array($value)) {
                $value = $this->helper->serialize($value);
            }

            // Try not to add CDATA unless we really need to (messy)
            if (is_numeric($value) || empty($value)) {
                $productXml .= "    <$k>$value</$k>\n";
            } elseif (!is_object($value)) {
                // Make sure double CDATA doesn't break our XML

                $value = str_replace("<![CDATA[", "", $value);
                $value = str_replace("]]>", "", $value);

                if (preg_match('/^[A-Za-z0-9 ]*$/', $value)) {
                    $productXml .= "    <$k>$value</$k>\n";
                } else {
                    $productXml .= "    <$k><![CDATA[$value]]></$k>\n";
                }
            }
            // else Value not outputted because $key is an object
        }
        
        if ($masterArray['type_id'] == 'bundle') {
            // Grab the bundle items from this product
            list($bundleProducts, $qtyInBundle) = $this->getProductBundleItems($product);
            
            $qtyInBundleXml = "";
            foreach ($bundleProducts as $bunSKU) {
                $qtyInBundleXml .= "\n<sku_config sku=\"$bunSKU\">\n";
                $skuParts = explode("^", $bunSKU);
                for ($i = 1; $i < count($skuParts); $i++) {
                    $currPart = $skuParts[$i];
                    $qtyInBundleXml .= "\t<qty_in_bundle sku=\"$currPart\">{$qtyInBundle[$i][$currPart]}</qty_in_bundle>\n";
                }
                $qtyInBundleXml .= "</sku_config>";
            }
            
            $productXml .= "    <bundle_products><![CDATA[<sku>".implode('</sku><sku>', $bundleProducts)."</sku>$qtyInBundleXml]]></bundle_products>\n";
        }

        return $productXml;
    }

    /**
     * Returns serialized media gallery as a string.
     *
     * @param type $product
     * @return type
     */
    private function getMediaGalleryString($product)
    {
        $product->load('media_gallery');
        $gallery = $product->getMediaGalleryImages();
        $mediaGallery = [];

        if ($gallery != null) {
            foreach ($gallery as $im) {
                $mediaGallery[] = $im->getData();
            }
        }

        $mg = ["images" => $mediaGallery];
        $mgStr = $this->helper->serialize($mg);

        return $mgStr;
    }
    
   /**
    * skipProduct - checks whether to skip product to pass it to CU
    *
    * @param type $product - can be product id or product object
    *
    * @return boolean - true-skip, false-don't skip
    */
    private function skipProduct($product)
    {
        $productStatus = 1;

        $ignoreDisabled = $this->helper->ignoreDisabled();

        if ($product && $ignoreDisabled == 1) {
            if (is_int($product)) {
                $product = $this->product->load($product);
            }

            if (is_object($product) && $product->hasSku()) {
                $productStatus = $product->getStatus(); // 1-Enabled, 2-Disabled
            }

            if ($productStatus == 2) {
                return true;
            }
        }

        return false;
    }
}
