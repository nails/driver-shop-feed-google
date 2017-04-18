<?php

namespace Nails\Shop\Driver\Feed;

use Nails\Factory;
use Nails\Shop\Driver\FeedBase;

class Google extends FeedBase
{
    /**
     * The number of products to process in each batch
     */
    const NUM_PER_PROCESS = 50;

    // --------------------------------------------------------------------------

    /**
     * Generate the feed data
     *
     * @param  resource $rHeader File handle to write headers to
     * @param  resource $rData   File handle to write data to
     *
     * @return boolean
     */
    public function generate($rHeader, $rData)
    {
        //  Address formatting
        $sAddress = trim(appSetting('invoice_address', 'nailsapp/module-shop'));
        if (!empty($sAddress)) {
            $aAddress = explode("\n", $sAddress);
            $aAddress = array_filter($aAddress);
            $aAddress = array_map('trim', $aAddress);
        } else {
            $aAddress = [];
        }

        // --------------------------------------------------------------------------

        //  Write the opening structure of the file
        fwrite($rData, '<?xml version="1.0" encoding="utf-8"?>');
        fwrite($rData, '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">');
        fwrite($rData, '<channel>');
        fwrite($rData, '<title><![CDATA[' . appSetting('invoice_company', 'shop') . ']]></title>');
        fwrite($rData, '<description><![CDATA[' . implode(', ', $aAddress) . ']]></description>');
        fwrite($rData, '<link><![CDATA[' . BASE_URL . ']]></link>');

        $iPage = 0;
        do {
            $iNumProcessed = $this->processProducts($iPage, $rData);
            $iPage++;
        } while ($iNumProcessed != 0);

        //  Close the file off
        fwrite($rData, '</channel>');
        fwrite($rData, '</rss>');

        //  Write the headers
        fwrite($rHeader, 'Content-Type: text/xml');

        return true;
    }

    // --------------------------------------------------------------------------


    /**
     * Process a batch of products
     *
     * @param  integer  $iPage The page number
     * @param  resource $rData The file handle for the data file
     *
     * @return integer The number of products processed
     */
    public function processProducts($iPage, $rData)
    {
        //  Models
        $oCurrencyModel       = Factory::model('Currency', 'nailsapp/module-shop');
        $oProductModel        = Factory::model('Product', 'nailsapp/module-shop');
        $oShippingDriverModel = Factory::model('ShippingDriver', 'nailsapp/module-shop');

        //  Get the base currency
        $sBaseCurrency = appSetting('base_currency', 'nailsapp/module-shop');
        $oBaseCurrency = $oCurrencyModel->getByCode($sBaseCurrency);

        //  Other details
        $sWarehouseCountry = appSetting('warehouse_addr_country', 'nailsapp/module-shop');
        $sInvoiceCompany   = appSetting('invoice_company', 'nailsapp/module-shop');
        $bIncludeTax       = (bool) $this->getSetting('includeTax') ?: false;

        //  Fetch this batch of products
        $aProducts  = $oProductModel->getAll($iPage, self::NUM_PER_PROCESS);
        $aToProcess = [];

        //  Format the batch
        foreach ($aProducts as $oProduct) {
            foreach ($oProduct->variations as $oVariant) {

                if ($oVariant->stock_status != 'IN_STOCK') {
                    continue;
                }

                $oTemp = new \stdClass();

                //  General product fields
                if ($oProduct->label != $oVariant->label) {
                    $oTemp->title = $oProduct->label . ' - ' . $oVariant->label;
                } else {
                    $oTemp->title = $oProduct->label;
                }

                $oTemp->url             = $oProduct->url;
                $oTemp->description     = trim(strip_tags($oProduct->description));
                $oTemp->productId       = $oProduct->id;
                $oTemp->variantId       = $oVariant->id;
                $oTemp->condition       = 'new';
                $oTemp->sku             = $oVariant->sku;
                $oTemp->google_category = $oProduct->google_category;

                // --------------------------------------------------------------------------

                //  Work out the brand
                if (isset($oProduct->brands[0])) {
                    $oTemp->brand = $oProduct->brands[0]->label;
                } else {
                    $oTemp->brand = $sInvoiceCompany;
                }

                // --------------------------------------------------------------------------

                //  Work out the product type (category)
                if (!empty($oProduct->categories)) {
                    $category = [];
                    foreach ($oProduct->categories as $c) {
                        $category[] = $c->label;
                    }
                    $oTemp->category = implode(', ', $category);
                } else {
                    $oTemp->category = '';
                }

                // --------------------------------------------------------------------------

                //  Set the product image
                if ($oVariant->featured_img) {
                    $oTemp->image = cdnServe($oVariant->featured_img);
                } elseif ($oProduct->featured_img) {
                    $oTemp->image = cdnServe($oProduct->featured_img);
                } else {
                    $oTemp->image = '';
                }

                // --------------------------------------------------------------------------

                //  Stock status
                if ($oVariant->stock_status == 'IN_STOCK') {
                    $oTemp->availability = 'in stock';
                } else {
                    $oTemp->availability = 'out of stock';
                }

                // --------------------------------------------------------------------------

                $shippingData = $oShippingDriverModel->calculateVariant($oVariant->id);

                /**
                 * Calculate price and price of shipping
                 * Tax/VAT should NOT be included:
                 * https://support.google.com/merchants/answer/2704214
                 */

                if ($bIncludeTax) {
                    $sPrice = $oCurrencyModel->formatBase($oVariant->price->price->user->value_inc_tax, false);
                } else {
                    $sPrice = $oCurrencyModel->formatBase($oVariant->price->price->user->value_ex_tax, false);
                    $sTax   = $oCurrencyModel->formatBase($oVariant->price->price->user->value_tax, false);
                }

                $sShippingPrice = $oCurrencyModel->formatBase($shippingData->total_inc_tax, false);

                $oTemp->price = $sPrice . ' ' . $oBaseCurrency->code;
                if (!$bIncludeTax) {
                    $oTemp->tax = $sTax . ' ' . $oBaseCurrency->code;
                }
                $oTemp->shipping_country = $sWarehouseCountry;
                $oTemp->shipping_service = 'Standard';
                $oTemp->shipping_price   = $sShippingPrice . ' ' . $oBaseCurrency->code;

                // --------------------------------------------------------------------------

                $aToProcess[] = $oTemp;
            }
        }

        // --------------------------------------------------------------------------

        //  Write the product data
        if (!empty($aToProcess)) {
            foreach ($aToProcess as $oItem) {

                fwrite($rData, '<item>');
                fwrite($rData, '<g:id>' . $oItem->productId . '.' . $oItem->variantId . '</g:id>');
                fwrite($rData, '<title><![CDATA[' . htmlentities($oItem->title) . ']]></title>');
                fwrite($rData, '<description><![CDATA[' . htmlentities($oItem->description) . ']]></description>');
                fwrite($rData, '<g:product_type><![CDATA[' . htmlentities($oItem->category) . ']]></g:product_type>');
                fwrite($rData, $oItem->google_category ? '<g:google_product_category>' . htmlentities($oItem->google_category) . '</g:google_product_category>' : '');
                fwrite($rData, '<link>' . $oItem->url . '</link>');
                fwrite($rData, '<g:image_link>' . $oItem->image . '</g:image_link>');
                fwrite($rData, '<g:condition>' . $oItem->condition . '</g:condition>');
                fwrite($rData, '<g:availability>' . $oItem->availability . '</g:availability>');
                fwrite($rData, '<g:price>' . $oItem->price . '</g:price>');
                if (!$bIncludeTax) {
                    fwrite($rData, '<g:tax>' . $oItem->tax . '</g:tax>');
                }
                fwrite($rData, '<g:brand><![CDATA[' . htmlentities($oItem->brand) . ']]></g:brand>');
                fwrite($rData, '<g:gtin>' . $oItem->sku . '</g:gtin>');
                fwrite($rData, '<g:shipping>');
                fwrite($rData, '<g:country>' . htmlentities($oItem->shipping_country) . '</g:country>');
                fwrite($rData, '<g:service>' . $oItem->shipping_service . '</g:service>');
                fwrite($rData, '<g:price>' . $oItem->shipping_price . '</g:price>');
                fwrite($rData, '</g:shipping>');
                fwrite($rData, '</item>');
            }
        }

        return count($aProducts);
    }
}
