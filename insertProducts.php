<?php

include("includes/bd.php");
$prod_name = $_GET['name'];//name is our design
$prod_sku = $_GET['sku'];
$prod_desc = $_GET['desc'];
$prod_img = $_GET['img'];
$prod_price = $_GET['price'];


$productToReturn = array();

//#1 - Insert into Products, only SKU (Drive FX Ref)
$sql = "INSERT INTO xmwqp_virtuemart_products (product_sku) 
		VALUES (".$prod_sku.");";
$rs = $db->exec($sql);

if($rs != 1){
	echo "ocorreu 1 erro no insert do sku";
	exit(1);
}

//#1.1 - Get product to reach out the product_id
$sql = "SELECT * FROM xmwqp_virtuemart_products where product_sku='".$prod_sku."'";
$rs = $db->exec($sql);
$item = $db->get_object($rs);

$product_id = $item->virtuemart_product_id;
$productToReturn['product_id'] = $item->virtuemart_product_id;
$productToReturn['product_sku'] = $item->product_sku;

//#2 - insert into xmwqp_virtuemart_products_pt_pt es_es e en_gb //tem descricoes do artigo completas, falta o campo slug
$sql = "INSERT INTO xmwqp_virtuemart_products_pt_pt (virtuemart_product_id, product_s_desc, product_desc, product_name, metadesc, metakey, customtitle, slug) VALUES ('".$product_id."', '', '".$prod_desc."', '".$prod_name."', '', '', '', '')";
$rs = $db->exec($sql);

if($rs != 1){
	echo "ocorreu 1 erro no insert do pt_pt";
	exit(1);
}

//vamos usar o 3386 como examplo

//#3 - Insert do price
$sql = "INSERT INTO xmwqp_virtuemart_product_prices (virtuemart_product_id, virtuemart_shoppergroup_id, product_price, product_currency, product_price_publish_up) VALUES ('".$product_id."', '0', '".$prod_price."', '47', '2017-10-10 00:00:00')";
$rs = $db->exec($sql);

if($rs != 1){
	echo "ocorreu 1 erro no insert do prices";
	exit(1);
}

//#4 - Insert media 
if($prod_img != ''){

	$sql = "INSERT INTO xmwqp_virtuemart_medias (virtuemart_vendor_id, file_title, file_description, file_meta, file_class, file_mimetype, file_type, file_url, file_url_thumb, file_is_product_image, file_is_downloadable, file_is_forSale, file_params, file_lang, shared, published, created_on, created_by, modified_on, modified_by, locked_on, locked_by) VALUES ('1', '".$product_id."', '', '', '', 'image/jpeg', 'product', '".$prod_img."', '', '0', '0', '0', '', '', '0', '1', '0000-00-00 00:00:00.000000', '0', '0000-00-00 00:00:00.000000', '0', '0000-00-00 00:00:00.000000', '0')";
	$rs = $db->exec($sql);
	if($rs != 1){
		echo "ocorreu 1 erro no insert do media";
		exit(1);
	}

	//#4.1 - get inserted media
	$sql = "SELECT * FROM xmwqp_virtuemart_medias where file_title='".$product_id."'";
	$rs = $db->exec($sql);
	$item = $db->get_object($rs);

	if(isset($item)){
		$media_id = $item->virtuemart_media_id;

		$sql = "INSERT INTO xmwqp_virtuemart_product_medias (virtuemart_product_id, virtuemart_media_id, ordering) VALUES ('".$product_id."', '".$media_id."', '0')";
		$rs = $db->exec($sql);
		if($rs != 1){
			echo "ocorreu 1 erro no insert do media no prod_media";
			exit(1);
		}

	}

}

print_r(json_encode($productToReturn));
exit(1);

		 
		 

?>