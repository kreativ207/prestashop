<?php

set_time_limit(99999);
require 'vendor/autoload.php';
require 'utils.php';
require(dirname(__FILE__) . '/../config/config.inc.php');
error_reporting(E_ALL);
ini_set('display_errors', 1);
Context::getContext()->employee = new Employee(2);

$defaultCategoryOnNewProduct = 964;

/**
 * @var ImageCore    $image
 * @var ProductCore  $product
 * @var CategoryCore $category
 */
$id_lang = (int)Configuration::get('PS_LANG_DEFAULT');

$id_supplier = 4;
$id_feature = 8;

// объявление переменных
/*$local_file = dirname(__FILE__) . 'test_new_qiotti.csv';
$server_file = 'test_qiotti.csv';*/

$local_file = dirname(__FILE__) . '/498105_easydata_komsa.csv'; //kompatibel_zu_loc.csv
$server_file = '498105_easydata.csv'; //kompatibel_zu.csv

$ftp_server = 'ftp.komsa.de';
$ftp_user_name = 'wocomm';
$ftp_user_pass = 'VlyDO7bQ';

// установка соединения
$conn_id = ftp_connect($ftp_server);

// вход с именем пользователя и паролем
$login_result = ftp_login($conn_id, $ftp_user_name, $ftp_user_pass);

// включение пассивного режима
//ftp_pasv($conn_id, true);

// попытка скачать $server_file и сохранить в $local_file

/*$ftp_s = 'ftp.komsa.de';
$ftp_name = 'wocomm';
$ftp_pass = 'VlyDO7bQ';

// установка соединения
$conn = ftp_connect($ftp_s);
$login_res_re = ftp_login($conn, $ftp_name, $ftp_pass);*/

unlink($local_file);
if (ftp_get($conn_id, $local_file, $server_file, FTP_BINARY)) {

    $csvFile = new Keboola\Csv\CsvFile($local_file, ';');
    $colums = array(
        'Reference',
        'Quantity',
        'Name',
        'Price',
        'Wholesale Price',
        'Supplier Reference',
        'Supplier Manufacturer',
        'EAN13',
        'Weight',
        'Short Description',
        'Description',
        'Image URLs',
        'Categories',
        'Feature'
    );

    foreach ($csvFile as $k => $row) {
        if ($row === 0 || $row[0] == NULL || $row[7] == 'HERSTELLERNUMMER') {
            continue;
        }

        $dbrow = Db::getInstance()->getRow(' SELECT `id_product` FROM `' . _DB_PREFIX_ . 'product` p WHERE p.reference = "k-' . pSQL($row[7]) . '"');
		if(isset($product)){
			if(is_object($product)){
				unset($product);
			}
		}
		
        $id_product = $dbrow['id_product'];
		$product = new Product($id_product);

        if (is_null($id_product)) {

            $patterns = array();
            $patterns[] = '/[\xDF]*/';
            $patterns[] = '/[\xF6]*/';
            $patterns[] = '/[\xE4]*/';
            $patterns[] = '/[\xFC]*/';

            $replace = array();
            $replace[] = "\xE1";
            $replace[] = "\x94";
            $replace[] = "\x84";
            $replace[] = "\x81";

            $product->name = createMultiLangField(preg_replace ("/[^a-zA-Z0-9()-\s]/","",strip_tags($row[2])));

            $product->description_short = createMultiLangField($row[13]);
            $product->description_short = substr($product->description_short[1], 0, 808);

            $product->description = createMultiLangField($row[14]); //\xF6\xE4\xFC\xDF

            $product->ean13 = substr($row[8],0,13);
            $product->reference = substr('k-' . $row[7], 0, 32);
            preg_match('~k-GS2210-8-EU0101F~U',$product->reference,$m);
            if(!empty($m)){
                $product->reference = $m[0];
            }

            /**
             * Meta
             */
            $product->active = 0;
            $product->link_rewrite = createMultiLangField(Tools::link_rewrite($product->name[$id_lang]));
            $product->show_price = 1;
            $product->depends_on_stock = 1;


            if (!Shop::isFeatureActive()) {
                $product->shop = 1;
            } elseif (!isset($product->shop) || empty($product->shop)) {
                $product->shop = implode(';', Shop::getContextListShopID());
            }

            if (!Shop::isFeatureActive()) {
                $product->id_shop_default = 1;
            } else {
                $product->id_shop_default = (int)Context::getContext()->shop->id;
            }

            // link product to shops
            $product->id_shop_list = array();
            foreach (explode(';', $product->shop) as $shop) {
                if (!empty($shop) && !is_numeric($shop)) {
                    $product->id_shop_list[] = Shop::getIdByName($shop);
                } elseif (!empty($shop)) {
                    $product->id_shop_list[] = $shop;
                }
            }


            /**
             * End meta
             */
            $errors = $product->validateFields(false, true);
            $error_lang = $product->validateFieldsLang(false, true);

            if ($errors !== true || $error_lang !== true) {
                echo $error_lang . '-> ' . var_dump($product->name);
                echo $errors . '-> ' . var_dump($product->name);
                Logger::addLog("Could not import  product ref {$row[0]}. Error: {$errors} {$error_lang}", 3, null, null,
                    null, true);
                continue;
            } else {
                $product->add();
            }

            //$category_ids = getCategoryIds($row[12], $id_lang);
            $product->addToCategories($defaultCategoryOnNewProduct);        //$category_ids
            $product->id_category_default = $defaultCategoryOnNewProduct;   //$category_ids[0]

            $product->id_supplier = $id_supplier;
            $product->supplier_reference = substr($row[7], 0, 32);
            preg_match('~GS2210-8-EU0101F~U',$product->supplier_reference,$m);
            if(!empty($m)){
                $product->supplier_reference = $m[0];
                $product->id_manufacturer = ManufacturerCore::getIdByName($row[4]);

                if(!$product->id_manufacturer){
                    Db::getInstance()->insert('manufacturer', array(
                        'name' => $row[4],
                        'date_add' => date("Y-m-d H:i:s"),
                        'date_upd' => date("Y-m-d H:i:s"),
                        'active' => 1
                    ));
                    // ID manufacture
                    $manufacture_id = Db::getInstance()->Insert_ID();

                    Db::getInstance()->insert('manufacturer_lang', array(
                        'id_manufacturer' => $manufacture_id,
                        'id_lang' => 1,
                    ));

                    Db::getInstance()->insert('manufacturer_lang', array(
                        'id_manufacturer' => $manufacture_id,
                        'id_lang' => 2,
                    ));

                    Db::getInstance()->insert('manufacturer_shop', array(
                        'id_manufacturer' => $manufacture_id,
                        'id_shop' => 1,
                    ));

                    $product->id_manufacturer = $manufacture_id;

                }
                addProductSupplier($product, substr($m[0], 0, 32), $id_supplier, Tools::ps_round(((float)str_replace(',', '.', $row[11])) / 1.19, 6));
            } else {
                $product->id_manufacturer = ManufacturerCore::getIdByName($row[4]);

                if(!$product->id_manufacturer){
                    Db::getInstance()->insert('manufacturer', array(
                        'name' => $row[4],
                        'date_add' => date("Y-m-d H:i:s"),
                        'date_upd' => date("Y-m-d H:i:s"),
                        'active' => 1
                    ));
                    // ID manufacture
                    $manufacture_id = Db::getInstance()->Insert_ID();

                    Db::getInstance()->insert('manufacturer_lang', array(
                        'id_manufacturer' => $manufacture_id,
                        'id_lang' => 1,
                    ));

                    Db::getInstance()->insert('manufacturer_lang', array(
                        'id_manufacturer' => $manufacture_id,
                        'id_lang' => 2,
                    ));

                    Db::getInstance()->insert('manufacturer_shop', array(
                        'id_manufacturer' => $manufacture_id,
                        'id_shop' => 1,
                    ));

                    $product->id_manufacturer = $manufacture_id;

                }
                addProductSupplier($product, substr($row[7], 0, 32), $id_supplier, Tools::ps_round(((float)str_replace(',', '.', $row[11])) / 1.19, 6));

            }


            $product->save();

            $imgUrl = $row[17];
            $imgName = $row[17];
            if(!empty($imgUrl)){
                if (substr($imgUrl, -4) == '.jpg' || substr($imgUrl, -5) == '.jpeg' || substr($imgUrl, -4) == '.png'){
                    /*$ftp_s = 'ftp.komsa.de';
                    $ftp_name = 'wocomm';
                    $ftp_pass = 'VlyDO7bQ';

                    // установка соединения
                    $conn = ftp_connect($ftp_s);
                    $login_res_re = ftp_login($conn, $ftp_name, $ftp_pass);*/
                    if (ftp_get($conn_id, 'bilder/' . $imgUrl, 'bilder/' . $imgUrl, FTP_BINARY)){
                        $imgUrl = "http://wocomm.de/importer/bilder/" . $imgUrl;
                        var_dump($imgUrl);
                        if (!empty($imgUrl)) {
                            $product_has_images = (bool)Image::getImages($id_lang, $product->id);

                            $image = new Image();
                            $image->id_product = (int)$product->id;
                            $image->position = Image::getHighestPosition($product->id) + 1;
                            $image->cover = (!$product_has_images) ? true : false;
                            $image->add();

                            if (!copyImg($product->id, $image->id, $imgUrl)) {
                                //echo "Could not import  img for product ref {$row[7]}. Url: {$imgUrl}" . '-' . $product->name;
                                Logger::addLog("Could not import  img for product ref {$row[7]}. Url: {$imgUrl}", 3, null, null,
                                    null,
                                    true);
                            }
                        }
                    } else {
                        echo 'EMPTY -> ' . $imgUrl . 'IN -> ' . $product->name[0];
                        // Logger::addLog("We could not get the image to the product " . $product->name, 3, null, null,
                        //     null,
                        //     true);
                    }
                } else {
                    //echo 'ошибка в ' . $imgUrl;
                }
            }

            /*if (!empty($row[13])) {
                try {
                    $id_feature_value = (int)FeatureValue::addFeatureValueImport(
                        $id_feature,
                        strip_tags($row[13]),
                        $product->id,
                        $id_lang,
                        true
                    );
                    Product::addFeatureProductImport($product->id, $id_feature, $id_feature_value);
                } catch (Exception $e) {
                    echo "Could not create feature value.Product ref {$row[0]} Error:" . $e->getMessage();
                    Logger::addLog("Could not create feature value.Product ref {$row[0]} Error:" . $e->getMessage(), 3,
                        null, null, null,
                        true);
                }

            }*/
            //set carrier
            $product->setCarriers(array(4));
            $product->save();
            if(!empty($imgName)){
                unlink("bilder/" . $imgName);
            }

            //Modell Kompatibilität
             if(!empty($row[10])){
                $zu = $row[10];//explode(":",$row[10]);

                $features=explode(';',$row[10]);
                foreach($features as $feature){
					if(!empty($feature)){
						$var_sql=trim($feature);
						$var_sql2=substr_count($var_sql, '"');
						if($var_sql2!=0){
							$val_arr = Db::getInstance()->getRow(" SELECT `id_feature_value` FROM `" . _DB_PREFIX_ . "feature_value_lang` WHERE value = '" .$var_sql. "' AND id_lang = 1");
						}else{
							$val_arr = Db::getInstance()->getRow(' SELECT `id_feature_value` FROM `' . _DB_PREFIX_ . 'feature_value_lang` WHERE value = "' .$var_sql. '" AND id_lang = 1');
						}
						if(!empty($val_arr['id_feature_value'])){
							$id_feature_value=$val_arr['id_feature_value'];
						}else{
							// add ps_feature_value
							Db::getInstance()->insert('feature_value', array(
								'id_feature' => 10,
								'custom' => 0
							));
							$id_feature_value = Db::getInstance()->Insert_ID();
							
							Db::getInstance()->insert('feature_value_lang', array(
								'id_feature_value' => $id_feature_value,
								'id_lang' => 1,
								'value' => $feature
							));

							Db::getInstance()->insert('feature_value_lang', array(
								'id_feature_value' => $id_feature_value,
								'id_lang' => 2,
								'value' => $feature
							));
						}
						
						if(!empty($id_feature_value) && !empty($feature)){
							if($id_feature_value!=0){
								// add ps_feature_product
								Db::getInstance()->insert('feature_product', array(
									'id_feature' => 10,
									'id_product' => $product->id,
									'id_feature_value' => $id_feature_value
								));
							}
						}
					}
                }
            }
        }
        try{
            $product->name = createMultiLangField(preg_replace ("/[^a-zA-Z0-9()-\s]/","",strip_tags($row[2])));

            StockAvailable::setQuantity($product->id, null, (int)$row[19]);

            //$product->addToCategories($defaultCategoryOnNewProduct);        //$category_ids
            //$product->id_category_default = $defaultCategoryOnNewProduct;   //$category_ids[0]

            $product->id_manufacturer = ManufacturerCore::getIdByName($row[4]);

            //Modell Kompatibilität
            if(!empty($row[10])){
                $features=explode(';',$row[10]);
                $d_r = Db::getInstance()->getRow(' SELECT `id_feature_value` FROM `' . _DB_PREFIX_ . 'feature_product` WHERE id_product = "' . $product->id . '"');
                if($d_r){
                    $del_f_v = $d_r['id_feature_value'];
                    $id_feature = 10;

                    $sql = 'DELETE FROM `'._DB_PREFIX_.'feature_value` WHERE `id_feature_value` = "'. $del_f_v .'" AND `id_feature` = "'. $id_feature .'" LIMIT 1';
                    if (!Db::getInstance()->Execute($sql))
                        echo('Error->feature_value');

                    $sql2 = 'DELETE FROM `'._DB_PREFIX_.'feature_value_lang` WHERE `id_feature_value` = "'. $del_f_v .'" LIMIT 2';
                    if (!Db::getInstance()->Execute($sql2))
                        echo('Error->feature_value_lang');

                    $sql3 = 'DELETE FROM `'._DB_PREFIX_.'feature_product` WHERE `id_feature_value` = "'. $del_f_v .'" AND `id_feature` = "'. $id_feature .'" LIMIT 2';
                    if (!Db::getInstance()->Execute($sql3))
                        echo('Error->feature_product');
                }
                foreach($features as $feature){
					if(!empty($feature)){
						$var_sql=trim($feature);
						$var_sql2=substr_count($var_sql, '"');
						if($var_sql2!=0){
							$val_arr = Db::getInstance()->getRow(" SELECT `id_feature_value` FROM `" . _DB_PREFIX_ . "feature_value_lang` WHERE value = '" .$var_sql. "' AND id_lang = 1");
						}else{
							$val_arr = Db::getInstance()->getRow(' SELECT `id_feature_value` FROM `' . _DB_PREFIX_ . 'feature_value_lang` WHERE value = "' .$var_sql. '" AND id_lang = 1');
						}
						if(!empty($val_arr['id_feature_value'])){
							$id_feature_value=$val_arr['id_feature_value'];
						}else{
							// add ps_feature_value
							Db::getInstance()->insert('feature_value', array(
								'id_feature' => 10,
								'custom' => 0
							));
							$id_feature_value = Db::getInstance()->Insert_ID();
							
							Db::getInstance()->insert('feature_value_lang', array(
								'id_feature_value' => $id_feature_value,
								'id_lang' => 1,
								'value' => $feature
							));

							Db::getInstance()->insert('feature_value_lang', array(
								'id_feature_value' => $id_feature_value,
								'id_lang' => 2,
								'value' => $feature
							));
						}
						
						if(!empty($id_feature_value) && !empty($feature)){
							if($id_feature_value!=0){
								// add ps_feature_product
								Db::getInstance()->insert('feature_product', array(
									'id_feature' => 10,
									'id_product' => $product->id,
									'id_feature_value' => $id_feature_value
								));
							}
						}
					}
				}
            }


            if(!$product->id_manufacturer){
                Db::getInstance()->insert('manufacturer', array(
                    'name' => $row[4],
                    'date_add' => date("Y-m-d H:i:s"),
                    'date_upd' => date("Y-m-d H:i:s"),
                    'active' => 1
                ));
                // ID manufacture
                $manufacture_id = Db::getInstance()->Insert_ID();

                Db::getInstance()->insert('manufacturer_lang', array(
                    'id_manufacturer' => $manufacture_id,
                    'id_lang' => 1,
                ));

                Db::getInstance()->insert('manufacturer_lang', array(
                    'id_manufacturer' => $manufacture_id,
                    'id_lang' => 2,
                ));

                Db::getInstance()->insert('manufacturer_shop', array(
                    'id_manufacturer' => $manufacture_id,
                    'id_shop' => 1,
                ));

                $product->id_manufacturer = $manufacture_id;

            }

            $product->wholesale_price = Tools::ps_round(((float)str_replace(',', '.', $row[11])) / 1.19, 6);
            $product->price = Tools::ps_round(((float)str_replace(',', '.', $row[12])) / 1.19, 6);

            $imgUrl = $row[17];
            $imgName = $row[17];
            if(!empty($imgUrl)){
                if (substr($imgUrl, -4) == '.jpg' || substr($imgUrl, -5) == '.jpeg' || substr($imgUrl, -4) == '.png'){
                    /*$ftp_s_re = 'ftp.komsa.de';
                    $ftp_name_re = 'wocomm';
                    $ftp_pass_re = 'VlyDO7bQ';

                    // установка соединения
                    $conn_re = ftp_connect($ftp_s_re);
                    $login_res_re = ftp_login($conn_re, $ftp_name_re, $ftp_pass_re);*/
                    if (ftp_get($conn_id, 'bilder/' . $imgUrl, 'bilder/' . $imgUrl, FTP_BINARY)){
                        $imgUrl = "http://wocomm.de/importer/bilder/" . $imgUrl;
                        if (!empty($imgUrl)) {
                            $product_has_images = (bool)Image::getImages($id_lang, $product->id);

                            $image = new Image();
                            $image->id_product = (int)$product->id;
                            $image->position = Image::getHighestPosition($product->id) + 1;
                            $image->cover = (!$product_has_images) ? true : false;
                            $image->add();
 
                            if (!copyImg($product->id, $image->id, $imgUrl)) {
                                //echo "Could not import  img for product ref {$row[7]}. Url: {$imgUrl}" . '-' . $product->name;
                                Logger::addLog("Could not import  img for product ref {$row[7]}. Url: {$imgUrl}", 3, null, null,
                                    null,
                                    true);
                            }
                        }
                    } else {
                        echo 'EMPTY -> ' . $imgUrl . 'IN -> ' . $product->name[0];
                        // Logger::addLog("We could not get the image to the product " . $product->name, 3, null, null,
                        //     null,
                        //     true);
                    }
                } else {
                    //echo 'ошибка в ' . $imgUrl;
                }
            }
            if(!empty($imgName)){
                unlink("bilder/" . $imgName);
            }
			
            $product->save();
        } catch (Exception $e) {
            var_dump($e);
        }
		/*
		$empty_feature_id = Db::getInstance()->executeS('
			SELECT SQL_CALC_FOUND_ROWS a.id_feature_value  FROM `'._DB_PREFIX_.'feature_value` a 
			LEFT JOIN `'._DB_PREFIX_.'feature_value_lang` b ON (b.`id_feature_value` = a.`id_feature_value` AND b.`id_lang` = 1)
			WHERE 1  AND value IS NULL AND id_feature=10
			ORDER BY `value` asc
		');
		$ids2=array();
		foreach($empty_feature_id as $ids){
			array_push($ids2,$ids['id_feature_value']);
		}
			if(is_array($ids)){
				$all_id_string = implode(",",$ids2);
			}
		$remove_feature_val_by_id = Db::getInstance()->executeS('
			DELETE FROM '._DB_PREFIX_.'feature_product WHERE id_feature=10 AND id_feature_value IN ('.$all_id_string.')
			');
		$remove_feature_prod_by_id = Db::getInstance()->executeS('
			DELETE FROM '._DB_PREFIX_.'feature_value WHERE id_feature=10 AND id_feature_value IN ('.$all_id_string.')
			');
		*/
		
		//$arra=$product->name;
		//echo $arra[1].'<br/>';
    }
}
// connect close
ftp_close($conn_id);

/*$files = glob("bilder/*");
if (count($files) > 0) {
    foreach ($files as $file) {
        if (file_exists($file)) {
            unlink($file);
            echo 'файл удален';
        }
    }
}*/

// delete images
/*if ($han = opendir('/kunden/421024_50996/webseiten/wocommdev/importer/bilder/')) {
    while (false !== ($file = readdir($han))) {
        if ($file != "." && $file != "..") {
            unlink("/kunden/421024_50996/webseiten/wocommdev/importer/bilder/" . $file);
        }
    }
    closedir($han);
}*/

exit;