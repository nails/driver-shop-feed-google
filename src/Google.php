<?php

namespace Nails\Shop\Driver\Feed;

use Nails\Factory;
use Nails\Shop\Driver\FeedBase;

class Google extends FeedBase
{
    private $bIncludeTax = false;

    // --------------------------------------------------------------------------

    /**
     * The number of products to process in each batch
     */
    const NUM_PER_PROCESS = 50;

    // --------------------------------------------------------------------------

    /**
     * Accepts an array of config values from the main driver model
     * @param array $aConfig The configs to set
     * @return array
     */
    public function setconfig($aConfig)
    {
        parent::setConfig($aConfig);
        $this->bIncludeTax = (bool) $aConfig['includeTax'];
    }

    // --------------------------------------------------------------------------

    /**
     * Generate the feed data
     * @param  object $oHeader File handle to write headers to
     * @param  string $oData   File handle to write data to
     * @return boolean
     */
    public function generate($oHeader, $oData)
    {
        //  Address formatting
        $sAddress = trim(appSetting('invoice_address', 'shop'));
        if (!empty($sAddress)) {

            $aAddress = explode("\n", $sAddress);
            $aAddress = array_filter($aAddress);
            $aAddress = array_map('trim', $aAddress);

        } else {

            $aAddress = array();
        }

        // --------------------------------------------------------------------------

        //  Write the opening structure of the file
        fwrite($oData, '<?xml version="1.0" encoding="utf-8"?>');
        fwrite($oData, '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">');
        fwrite($oData, '<channel>');
        fwrite($oData, '<title><![CDATA[' . appSetting('invoice_company', 'shop') . ']]></title>');
        fwrite($oData, '<description><![CDATA[' . implode(', ', $aAddress) . ']]></description>');
        fwrite($oData, '<link><![CDATA[' . BASE_URL . ']]></link>');

        $iPage = 0;
        do {

            $iNumProcessed = $this->processProducts($iPage, $oData);
            $iPage++;

        } while ($iNumProcessed != 0);


        //  Close the file off
        fwrite($oData, '</channel>');
        fwrite($oData, '</rss>');

        //  Write the headers
        fwrite($oHeader, 'Content-Type: text/xml');

        return true;
    }

    // --------------------------------------------------------------------------


    /**
     * Process a batch of products
     * @param  integer $iPage The page number
     * @param  object  $oData The file handle for the data file
     * @return integer The number of products processed
     */
    public function processProducts($iPage, $oData)
    {
        //  Models
        $oCurrencyModel       = Factory::model('Currency', 'nailsapp/module-shop');
        $oProductModel        = Factory::model('Product', 'nailsapp/module-shop');
        $oShippingDriverModel = Factory::model('ShippingDriver', 'nailsapp/module-shop');

        //  Get the base currency
        $sBaseCurrency = appSetting('base_currency', 'shop');
        $oBaseCurrency = $oCurrencyModel->getByCode($sBaseCurrency);

        //  Other details
        $sWarehouseCountry = appSetting('warehouse_addr_country', 'shop');
        $sInvoiceCompany   = appSetting('invoice_company', 'shop');

        //  Fetch this batch of products
        $products   = $oProductModel->getAll($iPage, self::NUM_PER_PROCESS);
        $aToProcess = array();

        //  Format the batch
        foreach ($products as $p) {
            foreach ($p->variations as $v) {

                $temp = new \stdClass();

                //  General product fields
                if ($p->label != $v->label) {

                    $temp->title = $p->label . ' - ' . $v->label;

                } else {

                    $temp->title = $p->label;
                }

                $temp->url             = $p->url;
                $temp->description     = trim(strip_tags($p->description));
                $temp->productId       = $p->id;
                $temp->variantId       = $v->id;
                $temp->condition       = 'new';
                $temp->sku             = $v->sku;
                $temp->google_category = $p->google_category;

                // --------------------------------------------------------------------------

                //  Work out the brand
                if (isset($p->brands[0])) {

                    $temp->brand = $p->brands[0]->label;

                } else {

                    $temp->brand = $sInvoiceCompany;
                }

                // --------------------------------------------------------------------------

                //  Work out the product type (category)
                if (!empty($p->categories)) {

                    $category = array();
                    foreach ($p->categories as $c) {

                        $category[] = $c->label;
                    }

                    $temp->category = implode(', ', $category);

                } else {

                    $temp->category = '';
                }

                // --------------------------------------------------------------------------

                //  Set the product image
                if ($p->featured_img) {

                    $temp->image = cdnServe($p->featured_img);

                } else {

                    $temp->image = '';
                }

                // --------------------------------------------------------------------------

                //  Stock status
                if ($v->stock_status == 'IN_STOCK') {

                    $temp->availability = 'in stock';

                } else {

                    $temp->availability = 'out of stock';
                }

                // --------------------------------------------------------------------------

                $shippingData = $oShippingDriverModel->calculateVariant($v->id);

                //  Calculate price and price of shipping
                /**
                 * Tax/VAT should NOT be included:
                 * https://support.google.com/merchants/answer/2704214
                 */

                if ($this->bIncludeTax) {

                    $sPrice = $oCurrencyModel->formatBase($p->price->user->min_price_inc_tax, false);

                } else {

                    $sPrice = $oCurrencyModel->formatBase($p->price->user->min_price_ex_tax, false);
                    $sTax   = $oCurrencyModel->formatBase($p->price->user->min_price_tax, false);
                }

                $sShippingPrice = $oCurrencyModel->formatBase($shippingData->base, false);

                $temp->price = $sPrice . ' ' . $oBaseCurrency->code;
                if (!$this->bIncludeTax) {
                    $temp->tax = $sTax . ' ' . $oBaseCurrency->code;
                }
                $temp->shipping_country = $sWarehouseCountry;
                $temp->shipping_service = 'Standard';
                $temp->shipping_price   = $sShippingPrice . ' ' . $oBaseCurrency->code;

                // --------------------------------------------------------------------------

                $aToProcess[] = $temp;
            }
        }

        // --------------------------------------------------------------------------

        //  Write the product data
        if (!empty($aToProcess)) {
            foreach ($aToProcess as $item) {

                fwrite($oData, '<item>');
                    fwrite($oData, '<g:id>' . $item->productId . '.' . $item->variantId . '</g:id>');
                    fwrite($oData, '<title><![CDATA[' . htmlentities($item->title) . ']]></title>');
                    fwrite($oData, '<description><![CDATA[' . htmlentities($item->description) . ']]></description>');
                    fwrite($oData, '<g:product_type><![CDATA[' . htmlentities($item->category) . ']]></g:product_type>');
                    fwrite($oData, $item->google_category ? '<g:google_product_category>' . htmlentities($item->google_category) . '</g:google_product_category>' : '');
                    fwrite($oData, '<link>' . $item->url . '</link>');
                    fwrite($oData, '<g:image_link>' . $item->image . '</g:image_link>');
                    fwrite($oData, '<g:condition>' . $item->condition . '</g:condition>');
                    fwrite($oData, '<g:availability>' . $item->availability . '</g:availability>');
                    fwrite($oData, '<g:price>' . $item->price . '</g:price>');
                    if (!$this->bIncludeTax) {
                        fwrite($oData, '<g:tax>' . $item->tax . '</g:tax>');
                    }
                    fwrite($oData, '<g:brand><![CDATA[' . htmlentities($item->brand) . ']]></g:brand>');
                    fwrite($oData, '<g:gtin>' . $item->sku . '</g:gtin>');
                    fwrite($oData, '<g:shipping>');
                        fwrite($oData, '<g:country>' . htmlentities($item->shipping_country) . '</g:country>');
                        fwrite($oData, '<g:service>' . $item->shipping_service . '</g:service>');
                        fwrite($oData, '<g:price>' . $item->shipping_price . '</g:price>');
                    fwrite($oData, '</g:shipping>');
                fwrite($oData, '</item>');
            }
        }

        return count($products);
    }
}
