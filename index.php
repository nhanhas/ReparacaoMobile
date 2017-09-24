<?php
	//Define URL SOAP
	define("SOAP_BASE", "http://www.reparacaomobile.pt/administrator/components/com_vm_soa/services");
	
	//Build a Session Credentials, to use in all requests
	$_SESSION['loginInfo'] = array(
		login=>"carlossantos",
		password=>"xpto1234",
		isEncrypted=>"N",
		lang=>"PT"
	);
	
	//WSDL Reference : http://www.virtuemart-datamanager.com/soap/VM2_SOAP_DOC.html#WS-VM_Product

	print_r("Starting Sync...<br>");
	
	
	
	
	$productarray = WSDL_GetProductsFromCategory(73);
	
	foreach ($productarray as $product){
		print_r($product->product_id);
		
	}
	
	/******************************
	 ***   WSDL Call Functions  ***
	 ******************************/
	//Call WSDL to get Products From Category
	function WSDL_GetProductsFromCategory($category){
		
		//#1 - set Login info
		$loginInfo = $_SESSION['loginInfo'];
		
		//#2 - Build params 
		$params = array(
			loginInfo=>$loginInfo,
			catgory_id=>$category,
			product_publish=>"Y",
			with_childs=>"Y",
			include_prices=>"Y",
			limite_start=>"",
			limite_end=>""
		);
		
		//#3 - Setup Connection SOAP
		$client = new SoapClient(SOAP_BASE . "/VM_ProductWSDL.php");
		
		//#4 - Make the call
		$productarray = array($client->GetProductsFromCategory($params));
		
		//#5 - Treat Result
		$productarray = $productarray[0]->Product;
		
		//#6 - Return Result
		return $productarray;
		
	}
	
?>