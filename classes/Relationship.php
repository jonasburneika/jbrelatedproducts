<?php

use PrestaShop\PrestaShop\Adapter\Image\ImageRetriever;
use PrestaShop\PrestaShop\Adapter\Product\PriceFormatter;
use PrestaShop\PrestaShop\Adapter\Product\ProductColorsRetriever;

class JbRelatedProductsRelationShip
{

    private $context;

    private $module;

    public function __construct()
    {
        $this->module = Module::getInstanceByName('jbrelatedproducts');
        $this->context = Context::getContext();
    }

    /**
     * Retrieve related product IDs based on a given product ID.
     *
     * This method queries the database to find distinct related product IDs based on the provided product ID.
     * It searches for related products where the provided product ID is found either in
     * the `id_product1` or `id_product2` column of the database table.
     * The result is limited to a specified number of entities.
     *
     * @param int $idProduct The product ID for which related products are to be retrieved.
     * @param int $limit The maximum number of related products to be returned.
     * @return array|false An array of related product IDs or `false` if an error occurs.
     *
     * DISCLAIMER: Description was written with ChatGPT
     */
    public static function getRelatedProducts(int $idProduct, int $limit): array|false
    {
        $sql = '
        SELECT 
            DISTINCT CASE
                WHEN id_product1 = "' . (int)$idProduct . '" 
                THEN id_product2
                ELSE id_product1
            END AS related_id_product
        FROM `' . _DB_PREFIX_ . 'jb_relprod_relationships` 
        WHERE 
            `id_product1` =  "' . (int)$idProduct . '" OR 
            `id_product2` =  "' . (int)$idProduct . '"
        LIMIT ' . (int)$limit . ';';

        return DB::getInstance()->execute($sql);
    }


    /**
     * Set related products for a given product ID.
     *
     * This method establishes relationships between a given product and a list of related products.
     * It takes a product ID and an array of related product IDs, then inserts corresponding entries
     * into the database table for maintaining product relationships.
     *
     * @param int $idProduct The product ID for which related products are being set.
     * @param int|int[] $relatedProducts An array of related product IDs or a single related product ID.
     * @return void
     *
     * DISCLAIMER: Description was written with ChatGPT
     */
    public static function setRelatedProducts(int $idProduct, array|int $relatedProducts): void
    {
        if (empty($relatedProducts)) {
            return;
        }
        if (!is_array($relatedProducts)) {
            $relatedProducts = [$relatedProducts];
        }
        $data = [];

        foreach ($relatedProducts as $relatedProduct) {
            $data[] = [
                'id_product1' => (int)$idProduct,
                'id_product2' => (int)$relatedProduct,
            ];
        }
        try {
            DB::getInstance()->insert('jb_relprod_relationships', $data);
        } catch (Exception $e) {
            $context = Context::getContext();
            $translator = $context->getTranslator();
            JbRelatedProductsLog::logError(
                $translator->trans('Unable to set related products. Get error "%s"',
                    ['%s' => $e->getMessage()],
                    'Modules.JbRelatedProducts.RelationShip'
                ),
                $idProduct
            );
        }
    }

    public static function removeRelationShip($idProduct)
    {
        $context = Context::getContext();
        $translator = $context->getTranslator();

        $dbInstance = DB::getInstance();
        if($dbInstance->execute('
            DELETE 
            FROM `' . _DB_PREFIX_ . 'jb_relprod_relationships`
            WHERE 
                `id_product1` = '.(int) $idProduct.' OR 
                id_product2 = '.(int) $idProduct.';'
        )) {
            JbRelatedProductsLog::logSuccess(
                $translator->trans('Deleted relationship with Product ID:%s',
                ['%s' => $idProduct],
                'Modules.JbRelatedProducts.RelationShip'
            ), $idProduct);
            return true;
        } else {
            JbRelatedProductsLog::logError(
                $translator->trans('Unable to remove products %d relationship. Get error "%s"',
                    [
                        '%d' => $idProduct,
                        '%s' => $dbInstance->getMsgError()
                    ],
                    'Modules.JbRelatedProducts.RelationShip'
                ),
                $idProduct
            );
        }
    }


    public function getRelatedProductsBySettings($idProduct)
    {
        $sql = new DbQuery();
        $sql->select('p.`id_product`');
        $sql->from('product', 'p');
        $sql->join(Shop::addSqlAssociation('product', 'p'));
        $this->setResultLimit($sql);
        $this->setWhereCategory($sql, $idProduct);
        $this->setWhereDefaultCategory($sql, $idProduct);
        $this->setWhereManufacturer($sql, $idProduct);
        $this->setWhereSupplier($sql, $idProduct);
        $this->setWhereFeatures($sql, $idProduct);
        $sql->where('p.`id_product` != ' . $idProduct);
        $sql->groupBy('p.`id_product`');

        $result = Db::getInstance()->executeS($sql);

        if (!$result) {
            return false;
        }
        return $this->getAssembledProducts($result);
    }


    private function setResultLimit(&$sql)
    {

        $limit = (int)Configuration::get($this->module->prefix . 'PRODUCTS_QUANTITY');

        if ($limit) {
            $sql->limit($limit);
        }
    }


    private function setWhereCategory(&$sql, $idProduct)
    {

        $shareCategory = (bool)Configuration::get($this->module->prefix . 'RELATION_CATEGORY');

        if ($shareCategory) {
            $categories = Product::getProductCategories($idProduct);
            if (!empty($categories)) {
                $sql->join('JOIN `' . _DB_PREFIX_ . 'category_product` cp ON cp.`id_product` = p.`id_product`');
                $sql->where('cp.`id_category` in (' . implode(',', $categories) . ')');
            }
        }
    }

    private function setWhereDefaultCategory(&$sql, $idProduct)
    {

        $shareDefaultCategory = (bool)Configuration::get($this->module->prefix . 'RELATION_DEFAULT_CATEGORY');

        if ($shareDefaultCategory) {
            $sql->join('JOIN `' . _DB_PREFIX_ . 'product` pc ON p.`id_category_default` = pc.`id_category_default`');
            $sql->where('pc.`id_product` = ' . (int)$idProduct);
        }
    }

    private function setWhereManufacturer(&$sql, $idProduct)
    {

        $shareManufacturer = (bool)Configuration::get($this->module->prefix . 'RELATION_MANUFACTURER');

        if ($shareManufacturer) {
            $sql->join('JOIN `' . _DB_PREFIX_ . 'product` pm ON p.`id_manufacturer` = pm.`id_manufacturer`');
            $sql->where('pm.`id_product` = ' . (int)$idProduct . ' and pm.`id_manufacturer` != "0"');
        }
    }

    private function setWhereSupplier(&$sql, $idProduct)
    {

        $shareSupplier = (bool)Configuration::get($this->module->prefix . 'RELATION_SUPPLIERS');

        if ($shareSupplier) {
            $sql->join('JOIN `' . _DB_PREFIX_ . 'product` psu ON p.`id_supplier` = psu.`id_supplier`');
            $sql->where('psu.`id_product` = ' . (int)$idProduct . ' and psu.`id_supplier` != "0"');
        }
    }

    private function setWhereFeatures(&$sql, $idProduct)
    {

        $shareFeatures = (bool)Configuration::get($this->module->prefix . 'RELATION_FEATURES');

        if ($shareFeatures) {
            $featureSql = new DbQuery();
            $featureSql->select('fp2.id_product');
            $featureSql->FROM('feature_product', 'fp1');
            $featureSql->join('JOIN `' . _DB_PREFIX_ . 'feature_product` fp2 ON fp2.`id_feature_value` = fp1.`id_feature_value`');
            $featureSql->where('fp1.`id_product` = ' . (int)$idProduct);

            $sql->where('p.`id_product` in (' . $featureSql->__toString() . ')');
        }
    }

    private function getAssembledProducts($products)
    {
        $presenter = new \PrestaShop\PrestaShop\Adapter\Presenter\Product\ProductListingPresenter(
            new ImageRetriever(
                $this->context->link
            ),
            $this->context->link,
            new PriceFormatter(),
            new ProductColorsRetriever(),
            $this->context->getTranslator()
        );


        $products_for_template = [];
        $assembler = new ProductAssembler($this->context);
        $presenterFactory = new ProductPresenterFactory($this->context);
        $presentationSettings = $presenterFactory->getPresentationSettings();


        foreach ($products as $rawProduct) {
            $products_for_template[] = $presenter->present(
                $presentationSettings,
                $assembler->assembleProduct($rawProduct),
                $this->context->language
            );
        }

        return $products_for_template;
    }

}