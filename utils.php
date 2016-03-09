<?php


/**
 * copyImg copy an image located in $url and save it in a path
 * according to $entity->$id_entity .
 * $id_image is used if we need to add a watermark
 *
 * @param int    $id_entity id of product or category (set in entity)
 * @param int    $id_image  (default null) id of the image if watermark enabled.
 * @param string $url       path or url to use
 * @param        string     entity 'products' or 'categories'
 * @return boolean
 */
function copyImg($id_entity, $id_image = null, $url, $entity = 'products', $regenerate = true)
{
    $tmpfile = tempnam(_PS_TMP_IMG_DIR_, 'ps_import');
    $watermark_types = explode(',', Configuration::get('WATERMARK_TYPES'));

    switch ($entity) {
        default:
        case 'products':
            $image_obj = new Image($id_image);
            $path = $image_obj->getPathForCreation();
            break;
        case 'categories':
            $path = _PS_CAT_IMG_DIR_ . (int)$id_entity;
            break;
        case 'manufacturers':
            $path = _PS_MANU_IMG_DIR_ . (int)$id_entity;
            break;
        case 'suppliers':
            $path = _PS_SUPP_IMG_DIR_ . (int)$id_entity;
            break;
    }
    $url = str_replace(' ', '%20', trim($url));

    // Evaluate the memory required to resize the image: if it's too much, you can't resize it.
    if (!ImageManager::checkImageMemoryLimit($url)) {
        return false;
    }

    // 'file_exists' doesn't work on distant file, and getimagesize makes the import slower.
    // Just hide the warning, the processing will be the same.
    if (Tools::copy($url, $tmpfile)) {
        ImageManager::resize($tmpfile, $path . '.jpg');
        $images_types = ImageType::getImagesTypes($entity);

        if ($regenerate) {
            foreach ($images_types as $image_type) {
                ImageManager::resize($tmpfile, $path . '-' . stripslashes($image_type['name']) . '.jpg',
                    $image_type['width'], $image_type['height']);
                if (in_array($image_type['id_image_type'], $watermark_types)) {
                    Hook::exec('actionWatermark', array('id_image' => $id_image, 'id_product' => $id_entity));
                }
            }
        }
    } else {
        unlink($tmpfile);

        return false;
    }
    unlink($tmpfile);

    return true;
}

function createMultiLangField($field)
{
    $languages = Language::getLanguages(false);
    $res = array();
    foreach ($languages as $lang) {
        $res[ $lang['id_lang'] ] = $field;
    }

    return $res;
}

function getCategoryIds($categoryNames, $id_lang)
{
    $categories = explode(',', $categoryNames);
    $category_ids = array();
    foreach ($categories as $name) {
        if (empty($name)) {
            continue;
        }
        $result = Category::searchByName($id_lang, $name, true);
        if ($result === false) {
            $category = new Category();
            $category->name = createMultiLangField($name);


            /**
             * Meta
             */
            $category->link_rewrite = createMultiLangField(Tools::link_rewrite($category->name[ $id_lang ]));
            $category->id_parent = Configuration::get('PS_HOME_CATEGORY');
            $category->active = 1;
            if (!Shop::isFeatureActive()) {
                $category->id_shop_default = 1;
            } else {
                $category->id_shop_default = (int)Context::getContext()->shop->id;
            }


            /**
             * End meta
             */
            $errors = $category->validateFields(false, true);
            if ($errors !== true) {
                Logger::addLog("Could not create category. Error: {$errors}", 3, null, null, null,
                    true);
                continue;
            } else {
                $category->add();
                $category_ids[] = $category->id;
            }

        } else {
            $category_ids[] = $result['id_category'];
        }
    }

    return array_merge($category_ids, array(Configuration::get('PS_HOME_CATEGORY')));
}

function toASCII($str)
{
    return mb_convert_encoding($str, 'HTML-ENTITIES', "UTF-8");
}

function sanitizeDescription($input)
{
    $input = strip_tags($input, "<p><a><img><b><br>");
    $input = toASCII($input);

    return $input;
}

function addProductSupplier($product, $reference, $id, $price = 0)
{
    /**
     * @var ProductSupplierCore $product_supplier
     */
    $product_supplier = new ProductSupplier();
    $product_supplier->product_supplier_reference = $reference;
    $product_supplier->id_product = $product->id;
    $product_supplier->id_product_attribute = 0;
    $product_supplier->id_supplier = $id;
    $product_supplier->product_supplier_price_te = $price;
    $product_supplier->id_currency = (int)Configuration::get('PS_CURRENCY_DEFAULT');
    $product_supplier->save();
}