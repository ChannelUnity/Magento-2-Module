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
use Magento\Catalog\Model\Product;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Model\ResourceModel\Iterator;
use Magento\Framework\Registry;
use Magento\Catalog\Model\ProductFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Camiloo\Channelunity\Helper\Data;

/**
 * Converts Magento product data into ChannelUnity formatted product data.
 */
class Products extends AbstractModel
{
    private $helper;
    private $product;
    private $storeManager;
    private $iterator;
    private $registry;
    private $upperLimit = 250;
    private $rangeNext = 0;
    private $buffer = "";
    private $productFactory;
    private $stockRegistry;
    private $productRepository;
    private $currentStore;

    public function __construct(
        Data $helper,
        Product $product,
        StoreManagerInterface $storeManager,
        Iterator $iterator,
        Registry $registry, // Note: Registry is deprecated in M2, but kept for interceptor compatibility
        ProductFactory $productFactory,
        StockRegistryInterface $stockRegistry,
        ProductRepositoryInterface $productRepository
    ) {
        $this->helper = $helper;
        $this->product = $product;
        $this->storeManager = $storeManager;
        $this->iterator = $iterator;
        $this->registry = $registry;
        $this->productFactory = $productFactory;
        $this->stockRegistry = $stockRegistry;
        $this->productRepository = $productRepository;
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

            if (!in_array($attr, ['name', 'description', 'sku', 'price', 'qty', 'stock_item'])) {
                $attrType = trim($attribute->getBackendType());
                $feLabel = $attribute->getFrontendLabel();
                $friendlyName = $feLabel != null ? trim($feLabel) : $attr;

                $messageToSend .= "<Attribute><Name>$attr</Name><Type>$attrType</Type>
                    <FriendlyName><![CDATA[{$friendlyName}]]></FriendlyName></Attribute>\n";
            }
        }

        $messageToSend .= "</ProductAttributes>\n";

        $result = $this->helper->postToChannelUnity($messageToSend, "ProductAttributes");
        $xml = simplexml_load_string($result, 'SimpleXMLElement', LIBXML_NOCDATA);

        if (isset($xml->Status)) {
            return (string) $xml->Status;
        } elseif (isset($xml->status)) {
            return (string) $xml->status;
        }

        return "Error - unexpected response";
    }

    /**
     * Given a product ID and store ID, returns an XML representation of this product.
     *
     * @param int|object $productId Product ID or product model
     * @param int $storeId          Store ID for which to generate the data
     * @param int $reduceStockBy    Optional stock qty modifier
     * @return string               XML product representation
     */
    public function generateCuXmlForSingleProduct($productId, $storeId, $reduceStockBy = 0)
    {
        $productXml = "";
        $this->currentStore = $storeId;

        if (is_object($productId)) {
            $productId = $productId->getId();
        }

        try {
            // Using Repository instead of Factory -> load() for significant performance gains
            $product = $this->productRepository->getById($productId, false, $storeId);
        } catch (\Exception $e) {
            $this->helper->logError("Product not found: " . $productId);
            return '<DeletedProductId>' . $productId . '</DeletedProductId>';
        }

        $skipProduct = $this->isProductGloballyDisabled($product->getId());

        if (!$skipProduct) {
            $productXml = $this->generateProductXml($product, $reduceStockBy);
        } else {
            $productXml = '<DeletedProductId>' . $product->getId() . '</DeletedProductId>';
        }

        return $productXml;
    }

    /**
     * Checks all store views to see if a product is disabled on all of them.
     *
     * @param int $productId Product ID only
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

                try {
                    $product = $this->productRepository->getById($productId, false, $storeId);
                    $skipProduct = $this->skipProduct($product);

                    if (!$skipProduct) {
                        return false; // Found an enabled store view, so it's not globally disabled
                    }
                } catch (\Exception $e) {
                    continue; // Skip if product can't be loaded for this store
                }
            }
        }
        return true;
    }

    private function generateProductXml($product, $reduceStockBy)
    {
        $imageUrl = $this->getBaseImageForProduct($product);
        $qty = 0;

        try {
            // StockRegistryInterface is the correct unified standard for Magento 2.1+
            $stock = $this->stockRegistry->getStockItem($product->getId());
            $qty = $stock->getQty() - $reduceStockBy;
        } catch (\Exception $e) {
            $this->helper->logError("Error generating product XML stock - " . $e->getMessage());

            $stock = null;
            $qtyKey = "cu_product_qty_" . $product->getId();
            if ($this->registry->registry($qtyKey) != null) {
                $qty = $this->registry->registry($qtyKey);
            }
        }

        $catids = implode(',', $product->getCategoryIds());
        $prodPrice = $product->getPrice();

        $productXml = "<Product>\n";
        $productXml .= "  <RemoteId>" . $product->getId() . "</RemoteId>\n";
        $productXml .= "  <Title><![CDATA[{$product->getName()} ]]></Title>\n";
        $productXml .= "  <Description><![CDATA[{$product->getData('description')} ]]></Description>\n";
        $productXml .= "  <SKU><![CDATA[{$product->getSku()}]]></SKU>\n";
        $productXml .= "  <Price>" . ($prodPrice != null ? number_format((float)$prodPrice, 2, ".", "") : '0.00') . "</Price>\n";
        $productXml .= "  <Quantity>{$qty}</Quantity>\n";
        $productXml .= "  <Category>{$catids}</Category>\n";
        $productXml .= "  <Image><![CDATA[{$imageUrl}]]></Image>\n";

        $productXml .= "  <RelatedSKUs>\n";
        $variationXml = "  <Variations>\n";

        if ($product->getTypeId() == 'configurable') {
            $prdTypeInst = $product->getTypeInstance();
            $childProducts = $prdTypeInst->getChildrenIds($product->getId());

            foreach ($childProducts as $childProductIds) {
                foreach ($childProductIds as $cpId) {
                    try {
                        $cp = $this->productRepository->getById($cpId);
                        $productXml .= "    <SKU><![CDATA[{$cp->getSku()}]]></SKU>\n";
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            }

            $confAttributes = $prdTypeInst->getConfigurableAttributesAsArray($product);

            if (is_array($confAttributes)) {
                foreach ($confAttributes as $cattr) {
                    $cattr = $this->helper->serialize($cattr);
                    $findTemp = "\"attribute_code\";";
                    $cattrArray = explode($findTemp, $cattr);

                    if (isset($cattrArray[1])) {
                        $cattrVal = explode("\"", $cattrArray[1]);

                        if (isset($cattrVal[1])) {
                            $variationXml .= "    <Variation><![CDATA[{$cattrVal[1]}]]></Variation>\n";
                        }
                    }
                }
            }
        }

        $variationXml .= "  </Variations>\n";
        $productXml .= "  </RelatedSKUs>\n";
        $productXml .= $variationXml;
        $productXml .= "  <Custom>\n";

        $moreAttributes = [];
        $moreAttributes['media_gallery'] = $this->getMediaGalleryString($product);
        $moreAttributes['url_in_store'] = $product->getUrlInStore();

        $productXml .= $this->enumerateCustomAttributesForProduct(
            $product,
            $stock,
            $moreAttributes
        );

        $productXml .= "  </Custom>\n";
        $productXml .= "</Product>\n";
        return $productXml;
    }

    public function generateCuXmlSku($args)
    {
        $row = $args['row'];
        if (!empty($row["sku"])) {
            $this->buffer .= "<SKU><![CDATA[" . $row["sku"] . "]]></SKU>\n";
        }
    }

    public function getRangeNext()
    {
        return $this->rangeNext;
    }

    public function getAllSKUs()
    {
        $this->buffer = "<Payload>\n<CartType>Magento 2</CartType>\n";

        $collectionOfProduct = $this->product->getCollection()->addAttributeToSelect('sku');

        if ($this->helper->ignoreDisabled() == 1) {
            $collectionOfProduct->addFieldToFilter('status', Status::STATUS_ENABLED);
        }

        $this->iterator->walk(
            $collectionOfProduct->getSelect(),
            [[$this, 'generateCuXmlSku']],
            ['storeId' => 0],
            $collectionOfProduct->getSelect()->getAdapter()
        );
        $this->buffer .= "</Payload>\n";
        return $this->buffer;
    }

    public function doRead($request, $bAddSourceUrl = false)
    {
        $rangeFrom = (string) $request->RangeFrom;
        $storeId = (string) $request->StoreviewId;

        $str = "<Products>\n";
        if ($bAddSourceUrl) {
            $sourceUrl = $this->helper->getBaseUrl();
            $str .= "<SourceURL>{$sourceUrl}</SourceURL>\n";
            $str .= "<StoreViewId>0</StoreViewId>\n";
        }

        try {
            $collectionOfProduct = $this->product->getCollection()->addStoreFilter($storeId);
            $collectionOfProduct->addAttributeToFilter("entity_id", ['gteq' => $rangeFrom])
                ->setOrder('entity_id', 'ASC');
            $collectionOfProduct->getSelect()->limit($this->upperLimit);

            $products = $collectionOfProduct->setFlag('has_stock_status_filter', true)->addAttributeToSelect('*')->load();

            foreach ($products as $p) {
                $this->rangeNext = $p->getData("entity_id") + 1;
                $str .= $this->generateCuXmlForSingleProduct($p, $storeId);
            }

            $highestId = $this->getHighestEntityId();

            if ($this->rangeNext > $highestId) {
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

    private function getTotalProductsSize()
    {
        $collectionOfProduct = $this->product->getCollection();

        if ($this->helper->ignoreDisabled()) {
            $collectionOfProduct->addFieldToFilter('status', Status::STATUS_ENABLED);
        }

        return $collectionOfProduct->getSize();
    }

    private function getHighestEntityId()
    {
        $collectionOfProduct = $this->product->getCollection();
        $collectionOfProduct->setOrder('entity_id', 'DESC');
        $firstItem = $collectionOfProduct->getFirstItem();

        return $firstItem->getEntityId();
    }

    private function getBaseImageForProduct($product)
    {
        $imageUrl = '';
        $rawImage = $product->getImage();

        try {
            if (!empty($rawImage)) {
                if ($rawImage[0] != '/') {
                    $rawImage = '/' . $rawImage;
                }

                $storeObject = $this->storeManager->getStore();
                $mediaUrl = $storeObject->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA);
                $imageUrl = $mediaUrl . 'catalog/product' . $rawImage;
            }
        } catch (\Exception $e) {
            $imageUrl = '';
        }
        return $imageUrl;
    }

    private function getProductAttributeData($product)
    {
        $attributeData = $product->getData();
        return $attributeData;
    }

    private function getProductBundleItems($product)
    {
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
            } else {
                $position = count($optionIdToPosition) + 1;
                $optionIdToPosition[$pselection->getOptionId()] = $position;
            }

            $selectionArray = [
                'product_name' => $pselection->getName(),
                'product_price' => $pselection->getPrice(),
                'product_qty' => $pselection->getSelectionQty(),
                'product_id' => $pselection->getProductId(),
                'product_sku' => $pselection->getSku()
            ];

            $qtyInBundle[$position][$pselection->getSku()] = (int)$pselection->getSelectionQty();
            $productsArray[$pselection->getOptionId()][$pselection->getSelectionId()] = $selectionArray;
        }

        $productsArray2 = [];
        $currentSKU = $product->getSku();
        $this->combineBundleSKU($currentSKU, $productsArray, $productsArray2);

        return [$productsArray2, $qtyInBundle];
    }

    private function combineBundleSKU($currentSKU, $productsArray, array &$productsArray2, $kStart = 0)
    {
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

    private function enumerateCustomAttributesForProduct($product, $stock, $moreAttributes)
    {
        $productXml = "";
        $attributeData = $this->getProductAttributeData($product);

        if ($stock) {
            $masterArray = array_merge($attributeData, $stock->getData(), $moreAttributes);
        } else {
            $masterArray = array_merge($attributeData, $moreAttributes);
        }

        ksort($masterArray);

        $ignoredAttributes = [
            'name', 'description', 'sku', 'price', 'qty',
            'stock_item', 'tier_price', '_cache_instance_products',
            '_cache_instance_product_set_attributes',
            '_cache_instance_used_attributes',
            '_cache_instance_used_product_attributes',
            'has_options', 'required_options', 'category_ids',
            'item_id', 'product_id', 'stock_id',
            'use_config_backorders', 'use_config_enable_qty_inc',
            'use_config_manage_stock', 'use_config_max_sale_qty',
            'use_config_min_qty', 'use_config_min_sale_qty',
            'use_config_notify_stock_qty', 'use_config_qty_increments',
            'is_decimal_divided', 'is_qty_decimal',
            'downloadable_links', 'options', 'options_container',
            'stock_status_changed_auto', 'min_qty_allowed_in_shopping_cart',
            'stock_status_changed_automatically_flag', 'tier_price_changed',
        ];

        foreach ($masterArray as $key => $value) {
            if (in_array($key, $ignoredAttributes)) {
                continue;
            }

            $k = preg_replace('/[^A-Za-z0-9_]/', '', $key);

            if (is_numeric(substr($k, 0, 1))) {
                $k = 'C' . $k;
            }

            $attribute = $product->getResource()->getAttribute($key);
            if (is_object($attribute) && $attribute->usesSource() && is_object($attribute->getSource())) {
                $attribute->setStoreId($this->currentStore);
                $attributeOptions = $attribute->getSource()->getAllOptions();

                $valueBuildArray = [];
                $startValues = is_array($value) ? $value : explode(",", (string) $value);

                foreach ($attributeOptions as $attrbOpt) {
                    if (in_array($attrbOpt['value'], $startValues)) {
                        $valueBuildArray[] = (string) $attrbOpt['label'];
                    }
                }
                $value = implode(',', $valueBuildArray);
            }

            if (is_array($value)) {
                $value = $this->helper->serialize($value);
            }

            if (is_numeric($value) || empty($value)) {
                $productXml .= "    <$k>$value</$k>\n";
            } elseif (!is_object($value)) {
                $value = str_replace(["<![CDATA[", "]]>"], "", $value);

                if (preg_match('/^[A-Za-z0-9 ]*$/', $value)) {
                    $productXml .= "    <$k>$value</$k>\n";
                } else {
                    $productXml .= "    <$k><![CDATA[$value]]></$k>\n";
                }
            }
        }

        if (isset($masterArray['type_id']) && $masterArray['type_id'] == 'bundle') {
            list($bundleProducts, $qtyInBundle) = $this->getProductBundleItems($product);
            $qtyInBundleXml = "";

            foreach ($bundleProducts as $bunSKU) {
                $qtyInBundleXml .= "\n<sku_config sku=\"$bunSKU\">\n";
                $skuParts = explode("^", $bunSKU);

                for ($i = 1; $i < count($skuParts); $i++) {
                    $currPart = $skuParts[$i];
                    $v = 0;
                    array_walk_recursive($qtyInBundle, function($item, $key) use(&$v, $currPart) {
                        if ($key == $currPart) {
                            $v = $item;
                        }
                    });
                    $qtyInBundleXml .= "\t<qty_in_bundle sku=\"$currPart\">{$v}</qty_in_bundle>\n";
                }
                $qtyInBundleXml .= "</sku_config>";
            }

            $productXml .= "    <bundle_products><![CDATA[<sku>".implode('</sku><sku>', $bundleProducts)."</sku>$qtyInBundleXml]]></bundle_products>\n";
        }

        return $productXml;
    }

    private function getMediaGalleryString($product)
    {
        // Using deprecated load here strictly to preserve gallery parsing compatibility
        // for images that weren't inherently loaded with the standard attribute select.
        $product->load('media_gallery');
        $gallery = $product->getMediaGalleryImages();
        $mediaGallery = [];

        if ($gallery != null) {
            foreach ($gallery as $im) {
                $mediaGallery[] = $im->getData();
            }
        }

        return $this->helper->serialize(["images" => $mediaGallery]);
    }

    private function skipProduct($product)
    {
        $ignoreDisabled = $this->helper->ignoreDisabled();

        if ($product && $ignoreDisabled == 1) {
            if (is_int($product)) {
                try {
                    $product = $this->productRepository->getById($product);
                } catch (\Exception $e) {
                    return true;
                }
            }

            if (is_object($product) && $product->hasSku()) {
                if ($product->getStatus() == Status::STATUS_DISABLED) {
                    return true;
                }
            }
        }

        return false;
    }
}