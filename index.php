<?php
	error_reporting(E_ERROR | E_PARSE);
	//Define SOAP Settings
	define("SOAP_BASE", "http://www.reparacaomobile.pt/administrator/components/com_vm_soa/services");
	define("SYNC_FROM_DATE", date("Y-9-28 00:00:s"));//day 1 of the month date("Y-m-1 00:00:00")
	define("SYNC_TO_DATE", date("Y-9-28 23:30:s")); //today date("Y-m-d H:i:s")
		
	//Build a Session Credentials, to use in all requests
	$_SESSION['loginInfo'] = array(
		login=>"carlossantos",
		password=>"xpto1234",
		isEncrypted=>"N",
		lang=>"PT"
	);
	
	
	//Define DriveFX settings
	define("orderNdoc", 1);
	define("backendUrl", "https://sis07.drivefx.net/2172d06c/PHCWS/REST");//TODO MUDAR AQUI 
	define("backendImgUrl", "https://sis07.drivefx.net/2172d06c/PHCWS");//TODO MUDAR AQUI 
	$_SESSION['driveCredentials'] = array(
		userCode=>"admin",
		password=>"12345678",
		applicationType=>"HYU45F-FKEIDD-K93DUJ-ALRNJE",
		company=>""
	);
	
		
	//set as global Call HEADER for Drive fX
	$ch = curl_init();
	
	//WSDL Reference : http://www.virtuemart-datamanager.com/soap/VM2_SOAP_DOC.html#WS-VM_Product
	//Drive FX : https://sis05.drivefx.net/c2b337a9/html/

	

	$msg = "Starting Sync...<br>";
	echo $msg;
	logData($msg);

	//First Login at Drive
	$loginResult = DRIVE_userLogin();
	if($loginResult == false){
		$msg = "Error on Drive Login.<br>";
		echo $msg;
		logData($msg);
		exit(1);
	}

	//Second get country List
	$countryList = DRIVE_getAllRecordList('Country');
	if (empty($countryList)){
		$msg =  "Error on getting Countries <br>";
		logData($msg);
		echo $msg;
		exit(1);
  	}	  

  	//#Sync Orders from Drive to Store
	syncProductsToStore();
	exit(1); //Remover antes de sincronizar o resto

  	/* Read from GET to check if it is to 
  	 * run sync #A or #A.1
	 */
  	$orderId = 0;
  	if(isset($_GET["id"])){
  		$orderId = intval($_GET["id"]);
  		print_r("Running single order sync... <br>");
  	}

  	if($orderId == 0){
		//#A - Start Syncing Orders
		syncOrders();
	}else{
		//#A.2 - Sync a sinlge order by order_id
		syncSingleOrder($orderId);
	}
	

		
	//#A - Main Sync Orders
	function syncOrders(){
		//#1 - Get Orders from Store
		$orderArray = WSDL_GetOrderFromDate();

		if(empty($orderArray)){
			$msg = "There are no Orders From Server.<br>";
			echo $msg;
			logData($msg);
			exit(1);
		}

		//#2 - For each order check if it is already sync @Drive
		foreach ($orderArray as $order) {
			//#3 - Get order @Drive by BO.obs = order_id
			if(DRIVE_getOrderById($order->id) != null){
				$msg = "Order with Id=".$order->id." already synched.<br><br>";
				echo $msg;
				logData($msg);
				continue;
			}

			//At this point means that order is not yet synched	
			$msg = "Order with Id=".$order->id." starting to sync... .<br><br>";
			echo $msg;
			logData($msg);

			//#4 - Get customer from Store
			$customer = WSDL_GetUserInfoFromOrderId($order->id);
			$customerDrive = processCustomer($customer);//then process it
			if($customerDrive == null){
				$msg = "Error on sync Order with Id=".$order->id." - Error in customer .<br><br>";
				echo $msg;
				logData($msg);
				continue;
			}

			//At this point means that we have customer, now sync product
			
			$orderProducts = WSDL_GetProductsFromOrderId($order->id);
			$driveProducts = processProducts($orderProducts);
			if($driveProducts == null){
				$msg = "Error on sync Order with Id=".$order->id." - Error in products.<br><br>";
				echo $msg;
				logData($msg);
				continue;
			}

			
			//At this point means that we have all (customer and products) to create an order 

			//#5 - Generate a new Order, we send to func $customer=shop customer, because consumidor final
			$newOrderDrive = createOrder($order, $customer, $customerDrive, $driveProducts);
			if($newOrderDrive == null){
				$msg = "Error on sync Order with Id=".$order->id." - Error in save order.<br><br>";
				echo $msg;
				logData($msg);
				continue;
			}

			//At this point the Order has been synchronized

			$msg = "Order with Id=".$order->id." - synched with SUCCESS.<br><br>";
			echo $msg;
			logData($msg);


			echo "<br>END<br>";
			echo "<br><br><br>";

		}
	}

	//#A.2 - Secondary, sync a single order (use #A or #A.2, exclusivity)
	function syncSingleOrder($orderId){
		//#1 - Get order by Id from store
		$order = WSDL_GetOrder($orderId);
		if (empty((array) $order)){
			$msg =  "Error on getting Order by id = ".$orderId." <br>";
			logData($msg);
			echo $msg;
			exit(1);
	  	}	

		//#3 - Get order @Drive by BO.obs = order_id
		if(DRIVE_getOrderById($order->id) != null){
			$msg = "Order with Id=".$order->id." already synched.<br><br>";
			echo $msg;
			logData($msg);
			exit(1);
		}

		//At this point means that order is not yet synched	
		$msg = "Order with Id=".$order->id." starting to sync... .<br><br>";
		echo $msg;
		logData($msg);

		//#4 - Get customer from Store
		$customer = WSDL_GetUserInfoFromOrderId($order->id);
		$customerDrive = processCustomer($customer);//then process it
		if($customerDrive == null){
			$msg = "Error on sync Order with Id=".$order->id." - Error in customer .<br><br>";
			echo $msg;
			logData($msg);
			exit(1);
		}

		//At this point means that we have customer, now sync product
		
		$orderProducts = WSDL_GetProductsFromOrderId($order->id);
		$driveProducts = processProducts($orderProducts);
		if($driveProducts == null){
			$msg = "Error on sync Order with Id=".$order->id." - Error in products.<br><br>";
			echo $msg;
			logData($msg);
			exit(1);
		}

		
		//At this point means that we have all (customer and products) to create an order 

		//#5 - Generate a new Order, we send to func $customer=shop customer, because consumidor final
		$newOrderDrive = createOrder($order, $customer, $customerDrive, $driveProducts);
		if($newOrderDrive == null){
			$msg = "Error on sync Order with Id=".$order->id." - Error in save order.<br><br>";
			echo $msg;
			logData($msg);
			exit(1);
		}

		//At this point the Order has been synchronized

		$msg = "Order with Id=".$order->id." - synched with SUCCESS.<br><br>";
		echo $msg;
		logData($msg);


		echo "<br>END<br>";
		echo "<br><br><br>";	
	}

	//#A.3 - Sync Products From Drive FX to Store
	function syncProductsToStore(){
		//#1 - Get all not synched products
		$driveProductsList = DRIVE_getProductNotSynced();
		
		if(empty($driveProductsList)){
			$msg = "There are no Products From Drive to Sync.<br>";
			echo $msg;
			logData($msg);
			exit(1);
		}

		//#2 - Iterate to create/or update in case of exist
		foreach ($driveProductsList as $driveProduct) {
			//flag to update STOCK
			$toUpdateStock = false;

			$msg = "Synching ref: ".$driveProduct['ref']."...<br>";
			echo $msg;
			logData($msg);

			//#2.1 - Now, check if this product exists in store!
			$productAlreadyInStore = WSDL_GetProductFromSku($driveProduct['ref']);

			if($productAlreadyInStore !== null){
				//first we update prices
				WSDL_UpdProductPricesByProd($productAlreadyInStore, $driveProduct);
				//in this case, update drive product with ST.obs = StoreProd.product_id
				//Update Product and save it in Drive
				$driveProduct['obs'] = strval($productAlreadyInStore->product_id);

				//#update obs in drive
				$driveProduct = DRIVE_saveInstance("St", $driveProduct);
				if($driveProduct == null){
					$msg = "Error on save entity for St. <br><br>";
					echo $msg;
					logData($msg);
					continue;
				}else{
					$msg = "Product obs updated in Drive!product_id:".$productAlreadyInStore->product_id." <br><br>";
					echo $msg;
					logData($msg);
					
					$toUpdateStock = true;//mark flag to update stock later
				}


			}else{
				//Create it in store
				//#3 - Call WSDL (directly url of our php in reparacaomobile server) - return = {product_id, product_sku}
				$productInStore = WSDL_AddProduct($driveProduct);
			
				if(isset($productInStore->error)){
					$msg = "Error on synchronizing ref: ".$driveProduct['ref']."...<br>";
					echo $msg;
					logData($msg);
					continue;
				}
				//#4 - Update Product and save it in Drive
				$driveProduct['obs'] = strval($productInStore->product_id);

				//#4.1 - Save It
				$driveProduct = DRIVE_saveInstance("St", $driveProduct);
				if($driveProduct == null){
					$msg = "Error on save entity for St. <br><br>";
					echo $msg;
					logData($msg);
					continue;
				}

				$msg = "Products with ref: ".$driveProduct['ref']." synched to Store!<br>";
				echo $msg;
				logData($msg);

				
				$toUpdateStock = true;//mark flag to update stock later
			}


			if($toUpdateStock == true){
				$returnStockUpdated=WSDL_UpdProductStockById($driveProduct['obs'], $driveProduct['stock']);

			  	if($returnStockUpdated->returnCode == 0){
			  		$msg = "Product stock updated in STORE!product ref: ".$driveProduct['ref']." <br><br>";
					echo $msg;
					logData($msg);
			  	}
			}


		}

		$msg = "Products synched from Drive to Store!<br>";
		echo $msg;
		logData($msg);

	}
	
	//#B - Minor functions

	function createOrder($order, $shopCustomer, $customerDrive, $driveProducts){
		//#1 - Get an order new instance
		$newInstanceBo = DRIVE_getNewInstance("Bo", orderNdoc);
		if($newInstanceBo == null){
			$msg = "Error on getting new instance Bo. <br><br>";
			echo $msg;
			logData($msg);
			return null;
		}

		//#2 - Add customer no to order
		$newInstanceBo['no'] = $customerDrive['no'];

		//#2.1 - Then sync
		$newInstanceBo = DRIVE_actEntiy("Bo", $newInstanceBo);
		if($newInstanceBo == null){
			$msg = "Error on act entity for Order. <br><br>";
			echo $msg;
			logData($msg);
			return null;
		}

		//#2.2 - Use the order customer billing info
		$shopCustomerName = $shopCustomer->first_name . " " . $shopCustomer->last_name;

		$newInstanceBo['nome2'] = $customerDrive['clivd'] == true ? $shopCustomerName : '';//only if is generic
		$newInstanceBo['morada'] = !empty($shopCustomer->address_1) ? $shopCustomer->address_1 : $shopCustomer->address_2;
		$newInstanceBo['local'] = $shopCustomer->city;
		$newInstanceBo['codpost'] = $shopCustomer->zip;
		$newInstanceBo['telefone'] = empty($shopCustomer->phone_1) ? $shopCustomer->phone_1 : $shopCustomer->phone_2;

		//#2.3 - Then sync
		$newInstanceBo = DRIVE_actEntiy("Bo", $newInstanceBo);
		if($newInstanceBo == null){
			$msg = "Error on act entity for Order. <br><br>";
			echo $msg;
			logData($msg);
			return null;
		}

		//#3 - Now add products (they already have all needed, just join then to Bis)
		$newInstanceBo['bis'] = $driveProducts;

		//#3.1 - Then sync
		$newInstanceBo = DRIVE_actEntiy("Bo", $newInstanceBo);
		if($newInstanceBo == null){
			$msg = "Error on act entity for Order. <br><br>";
			echo $msg;
			logData($msg);
			return null;
		}

		//#3.2 - Add shipping
		if($order->order_shipment > 0){
			$shippingBi = array(
				"design" => "Shipping fee",
				"qtt" => 1,
				"ivaincl" => false,
				"edebito" => $order->order_shipment
			);
			$newInstanceBo['bis'][] = $shippingBi;
		}

		//#3.3 - Add payment
		if($order->order_payment > 0){
			$paymentBi = array(
				"design" => "Payment fee",
				"qtt" => 1,
				"ivaincl" => false,
				"edebito" => $order->order_payment
			);
			$newInstanceBo['bis'][] = $paymentBi;
		}

		//#3.4 - Then sync
		$newInstanceBo = DRIVE_actEntiy("Bo", $newInstanceBo);
		if($newInstanceBo == null){
			$msg = "Error on act entity for Order. <br><br>";
			echo $msg;
			logData($msg);
			return null;
		}

		//Set ID in Bo.obs
		$newInstanceBo['obs'] = $order->id;

		//#4 - Save Order
		$newInstanceBo = DRIVE_saveInstance("Bo", $newInstanceBo);
		if($newInstanceBo == null){
			$msg = "Error on save entity for Order. <br><br>";
			echo $msg;
			logData($msg);
			return null;
		}

		return $newInstanceBo;
	}

	function processProducts($productsList){
		if(empty($productsList)){
			$msg = "No products to process.<br><br>";
			echo $msg;
			logData($msg);
			return null;
		}

		$driveProducts = array();
		//Iterate products to create
		foreach ($productsList as $product) {
			
			#1 - check if it is already created @Drive
			$driveProduct = DRIVE_getProductByRefOrId($product->order_item_sku, $product->product_id);
			if($driveProduct != null){
				//if exists add to list and proceed to next one
				$driveProducts[] = array(
					"ref" => $driveProduct['ref'],
					"design" => $product->order_item_name,
					"qtt" => $product->product_quantity,
					"ivaincl" => false,
					"edebito" => $product->product_item_price
				); 
				continue;
			}

			//At this point means that we need to create a new product

			//#2 - Create the product
			$newInstanceSt = createProduct($product);
			if($newInstanceSt == null){
				//if goes on error, stop immidiatly
				return null;
			}

			//#3 - Add to final Drive Products array 
			$driveProducts[] = array(
					"ref" => $newInstanceSt['ref'],
					"design" => $product->order_item_name,
					"qtt" => $product->product_quantity,
					"ivaincl" => false,
					"edebito" => $product->product_item_price
				); 
		}

		return $driveProducts;
	}

	//Just to Create a Product with all data needed
	function createProduct($product){
		//#1 - get New Instance
		$newInstanceSt = DRIVE_getNewInstance("St", 0);
		if($newInstanceSt == null){
			$msg = "Error on getting new instance ST. <br><br>";
			echo $msg;
			logData($msg);
			return null;
		}

		//#2 - fulfill properties
		$newInstanceSt['ref'] = $product->order_item_sku;
		$newInstanceSt['design'] = $product->order_item_name;
		$newInstanceSt['epv1'] = $product->product_item_price;
		
		$newInstanceSt['obs'] = $product->product_id;//obs will be the product id from store

		
		//#2 - an sync entity
		$newInstanceSt = DRIVE_actEntiy("St", $newInstanceSt);
		if($newInstanceSt == null){
			$msg = "Error on act entity for product name = " .$product->order_item_name . " <br><br>";
			echo $msg;
			logData($msg);
			return null;
		}

		//#3 - Save product
		$newInstanceSt = DRIVE_saveInstance("St", $newInstanceSt);
		if($newInstanceSt == null){
			$msg = "Error on save for product name = " .$product->order_item_name . " <br><br>";
			echo $msg;
			logData($msg);
			return null;
		}

		$msg = "Product created with ref = " .$newInstanceSt['ref']. " <br><br>";
		echo $msg;
		logData($msg);
		return $newInstanceSt;
	}


	//Treat all things to customer - get/create
	function processCustomer($customer){
		//#1 - check if it already exists in Drive
		$driveCustomer = DRIVE_getCustomerByNcontOrId($customer->nif);
		if($driveCustomer != null){			
			return $driveCustomer;
		}
			
		//At this point means that we need to create
		$newInstanceCl = createCustomer($customer);		
		if($newInstanceCl == null){
			$msg = "Error on process customer ,Order with Id=".$order->id.".<br><br>";
			echo $msg;
			logData($msg);
		}

		return $newInstanceCl;
	}

	//Just to Create a customer with all data needed
	function createCustomer($customer){
		global $countryList;		
		
		//aux function  
		$_getPaisstampByPnCont = function($pncont)
		{	
			global $countryList;
			 foreach ($countryList as $country) {
				 if($country['pncont']===$pncont){
					 return $country;
				 }
				 
			 }
			 return null;
			
		}; 
		
		
		//#1 - get New Instance
		$newInstanceCl = DRIVE_getNewInstance("Cl", 0);
		if($newInstanceCl == null){
			$msg = "Error on getting new instance CL. <br><br>";
			echo $msg;
			logData($msg);
			return null;
		}

		//#2 - fulfill properties
		$newInstanceCl['nome'] = $customer->first_name . " " . $customer->last_name;
		$newInstanceCl['email'] = $customer->email;
		$newInstanceCl['morada'] = !empty($customer->address_1) ? $customer->address_1 : $customer->address_2;
		$newInstanceCl['local'] = $customer->city;
		$newInstanceCl['codpost'] = $customer->zip;
		$newInstanceCl['ncont'] = $customer->nif;
		
		$country = $_getPaisstampByPnCont($customer->code);
		if(!empty($country)){
			$newInstanceCl['pncont'] = $customer->code;
			$newInstanceCl['paisesstamp'] = $country['paisesstamp'];
			$newInstanceCl['pais'] = $country['nome'];
		}
		
		$newInstanceCl['obs'] = $customer->user_id;//obs will be the customer id from store

		$newInstanceCl['tlmvl'] = !empty($customer->phone_1) ? $customer->phone_1 : $customer->phone_2;

		//#2 - an sync entity
		$newInstanceCl = DRIVE_actEntiy("Cl", $newInstanceCl);
		if($newInstanceCl == null){
			$msg = "Error on act entity for client name = " .$customer->first_name . " " . $customer->last_name. " <br><br>";
			echo $msg;
			logData($msg);
			return null;
		}

		//#3 - Save Customer
		$newInstanceCl = DRIVE_saveInstance("Cl", $newInstanceCl);
		if($newInstanceCl == null){
			$msg = "Error on save for client name = " .$customer->first_name . " " . $customer->last_name. " <br><br>";
			echo $msg;
			logData($msg);
			return null;
		}

		$msg = "Customer created with number = " .$newInstanceCl['no']. " <br><br>";
		echo $msg;
		logData($msg);
		return $newInstanceCl;
	}
	
	
	
	/******************************
	 ***   WSDL Call Functions  ***
	 ******************************/
			//Call WSDL to get Products By Sku
	function WSDL_UpdProductPricesByProd($storeProduct, $driveProduct){

		
		if($driveProduct['epv2'] > 0 && isset($storeProduct->prices->ProductPrice[1])){
			//epv2 ta na posicao 1
			$storeProduct->prices->ProductPrice[1]->product_price = $driveProduct['epv2'];

			
		}
		//epv2 ta na posicao 0
		$storeProduct->prices->ProductPrice[0]->product_price = $driveProduct['epv1'];
		

		//#1 - set Login info
		$loginInfo = $_SESSION['loginInfo'];

		//#2 - Build params 
		$params = array(
			loginInfo=>$loginInfo,
			ProductPrices=>$storeProduct->prices
			
		);

		//#3 - Setup Connection SOAP
		$client = new SoapClient(SOAP_BASE . "/VM_ProductWSDL.php");
		try{
			//#4 - Make the call
			$productUpdated = array($client->UpdateProductPrices($params));
			print_r($productUpdated);
		
			//#6 - Return Result
			return $productUpdated;

		}catch(Exception $ex){

		}
		
		//#6 - Return Result
		return null;
	} 	

	//Call WSDL to get Products By Sku
	function WSDL_UpdProductStockById($productId, $stock){
		//#1 - set Login info
		$loginInfo = $_SESSION['loginInfo'];

		//#2 - Build params 
		$params = array(
			loginInfo=>$loginInfo,
			UpdateStocks=>array(
				UpdateStock => array(
					product_id=>$productId,
					product_in_stock=> $stock
				)
			)	
		);

		//#3 - Setup Connection SOAP
		$client = new SoapClient(SOAP_BASE . "/VM_ProductWSDL.php");
		try{
			//#4 - Make the call
			$productUpdated = array($client->UpdateStock($params));
			
			//#5 - Treat Result
			$productUpdated = $productUpdated[0];
			
			//#6 - Return Result
			return $productUpdated;

		}catch(Exception $ex){

		}
		
		//#6 - Return Result
		return null;
	} 


	//Call WSDL to get Products By Sku
	function WSDL_GetProductFromSku($orderSku){
		//#1 - set Login info
		$loginInfo = $_SESSION['loginInfo'];

		//#2 - Build params 
		$params = array(
			loginInfo=>$loginInfo,
			product_sku=>$orderSku,
			include_prices=>"Y"			
		);

		//#3 - Setup Connection SOAP
		$client = new SoapClient(SOAP_BASE . "/VM_ProductWSDL.php");
		try{
			//#4 - Make the call
			$productFromSku = array($client->GetProductFromSku($params));

			//#5 - Treat Result
			$productFromSku = $productFromSku[0];
			
			//#6 - Return Result
			return $productFromSku;

		}catch(Exception $ex){

		}
		
		//#6 - Return Result
		return null;
	} 


	//Call WSDL to add a new instance of Products
	function WSDL_AddProduct($product){
		//http://www.reparacaomobile.pt/sincronizer/insertProduct.php?name=teste&sku=123456789&desc=grande%20desc&img=hjsuuhs.pt&price=10.99
		$picLocation = DRIVE_getImageBytes($product['imagestamp'], $product['ref']);

		//should be img/ref.jpg
		$imageUrl = $picLocation;
 
		$productQueryString = "?name=".urlencode($product['design'])."&sku=".urlencode($product['ref'])."&desc=".urlencode($product['desctec'])."&img=".$imageUrl."&price=".$product['epv1']."&price2=".$product['epv2']."";
		
		$callRequest = curl_init(); 


        // set url 
        curl_setopt($callRequest, CURLOPT_URL, "http://www.reparacaomobile.pt/sincronizer/insertProduct.php".$productQueryString); 

        //return the transfer as a string 
        curl_setopt($callRequest, CURLOPT_RETURNTRANSFER, 1); 

        // $output contains the output string 
        $insertedProduct = curl_exec($callRequest); 

        // close curl resource to free up system resources 
        curl_close($callRequest); 

		return json_decode($insertedProduct);
	}

	//Call WSDL (fake call) to get a new instance of Products
	function WSDL_GetNewInstanceProduct(){
		//THIS IS NOTHING...
		return false;
		//creante a product object
		$productNewInstance = array(
            product_id=>"99999",
            virtuemart_vendor_id=>"",
            product_parent_id=>"",
            product_sku=>"99999d",
            product_name=>"TESTE",
            slug=>"tt",
            product_s_desc=>"Mais um teste",
            product_desc=>"Outro tesxte",
            product_weight=>0,
            product_weight_uom=>0,
            product_length=>0,
            product_width=>0,
            product_height=>0,
            product_lwh_uom=>0,
            product_url=>"",
            product_in_stock=>"",
            low_stock_notification=>"",
            product_available_date=>"",
            product_availability=>"",
            product_special=>"",
            ship_code_id=>"",
            product_sales=>"",
            product_unit=>"",
            product_packaging=>"",
            product_ordered=>"",
            hits=>"",
            intnotes=>"",
            metadesc=>"",
            metakey=>"",
            metarobot=>"",
            metaauthor=>"",
            layout=>"",
            published=>"",
            product_categories=>"",
            manufacturer_id=>"",
            product_params=>"",
            img_uri=>"",
            img_thumb_uri=>"",
            shared=>"",
            ordering=>"",
            customtitle=>"",
            shopper_group_ids=>"",
            prices=>array(
               ProductPrice=>array(
                  product_price_id=>0,
                  product_id=>0,
                  product_price=>9.99,
                  product_currency=>"",
                  product_price_vdate=>"",
                  product_price_edate=>"",
                  created_on=>"",
                  modified_on=>"",
                  shopper_group_id=>"",
                  price_quantity_start=>"",
                  price_quantity_end=>"",
                  override=>"",
                  product_override_price=>"",
                  product_tax_id=>"",
                  product_discount_id=>"",
                  product_final_price=>"",
                  product_price_info=>""
                )
            ),
            product_gtin=>"",
            product_mpn=>""
        );

		//try add now
		WSDL_AddProduct($productNewInstance);
		return $productNewInstance;
	}

	//Call WSDL to get Products within order
	function WSDL_GetUserInfoFromOrderId($orderId){
		$callRequest = curl_init(); 

        // set url 
        curl_setopt($callRequest, CURLOPT_URL, "http://www.reparacaomobile.pt/sincronizer/getUserOrder.php?order_id=".$orderId); 

        //return the transfer as a string 
        curl_setopt($callRequest, CURLOPT_RETURNTRANSFER, 1); 

        // $output contains the output string 
        $customer = curl_exec($callRequest); 

        // close curl resource to free up system resources 
        curl_close($callRequest); 
		
		return json_decode($customer);
		
		/*//#1 - set Login info
		$loginInfo = $_SESSION['loginInfo'];

		//#2 - Build params 
		$params = array(
			loginInfo=>$loginInfo,
			order_id=>$orderId		
		);

		//#3 - Setup Connection SOAP
		$client = new SoapClient(SOAP_BASE . "/VM_UsersWSDL.php");

		//#4 - Make the call
		$customer = array($client->GetUserInfoFromOrderId($params));
		
		//#5 - Treat Result
		$customer = $customer[0]->User;
		
		//#6 - Return Result
		return $customer;*/
	} 


	//Call WSDL to get Products within order
	function WSDL_GetProductsFromOrderId($orderId){
		//#1 - set Login info
		$loginInfo = $_SESSION['loginInfo'];

		//#2 - Build params 
		$params = array(
			loginInfo=>$loginInfo,
			order_id=>$orderId,
			include_prices=>"Y"			
		);

		//#3 - Setup Connection SOAP
		$client = new SoapClient(SOAP_BASE . "/VM_ProductWSDL.php");

		//#4 - Make the call
		$orderProducts = array($client->GetProductsFromOrderId($params));
		

		//#5 - Treat Result
		$orderProducts = $orderProducts[0]->OrderItemInfo;
		
		if(is_object($orderProducts)){
			$orderProducts= array($orderProducts);
		}

		//#6 - Return Result
		return $orderProducts;
	} 
	
	//Call WSDL to get a single Order by Id
	function WSDL_GetOrder($orderId){
		//#1 - set Login info
		$loginInfo = $_SESSION['loginInfo'];

		//#2 - Build params 
		$params = array(
			loginInfo=>$loginInfo,
			order_id=>$orderId,
			order_number=>"",
			limite_start=>"",
			limite_end=>""		
		);

		//#3 - Setup Connection SOAP
		$client = new SoapClient(SOAP_BASE . "/VM_OrderWSDL.php");

		//#4 - Make the call
		$order = array($client->GetOrder($params));
		
		//#5 - Treat Result
		$order = $order[0];
		
		//#6 - Return Result
		return $order;
	} 	 

	//Call WSDL to get Orders Between a month
	function WSDL_GetOrderFromDate(){
		//First day of month
		$date_start = SYNC_FROM_DATE;
		
		//To the present
		$date_end= SYNC_TO_DATE;
		
		//#1 - set Login info
		$loginInfo = $_SESSION['loginInfo'];
		
		//#2 - Build params 
		$params = array(
			loginInfo=>$loginInfo,
			order_status=>"",
			date_start=>$date_start,
			date_end=>$date_end			
		);
		
		//#3 - Setup Connection SOAP
		$client = new SoapClient(SOAP_BASE . "/VM_OrderWSDL.php");
		
		//#4 - Make the call
		$orderArray = array($client->GetOrderFromDate($params));
		
		//#5 - Treat Result
		$orderArray = $orderArray[0]->Order;

		if(is_object($orderArray)){
			$orderArray= array($orderArray);
		}
		
		//#6 - Return Result
		return $orderArray;
		
	}


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
	
	
	/******************************
	 *** DriveFX Call Functions ***
	 ******************************/	
	//Call Drive to get Image Data, and save it 
	function DRIVE_getImageBytes($imageStamp, $ref){
		//#1 - Build image Url
		$imageUrl = backendImgUrl . "/cimagem.aspx?iflstamp=" . $imageStamp ;

		// create curl resource 
        global $ch;

        $imageCH = curl_copy_handle($ch);
        //return the transfer as a string 
        $timeout = 0;
		curl_setopt ($imageCH, CURLOPT_URL, $imageUrl);
		curl_setopt ($imageCH, CURLOPT_CONNECTTIMEOUT, $timeout);

		// Getting binary data
		curl_setopt($imageCH, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($imageCH, CURLOPT_BINARYTRANSFER, 1);

		$image = curl_exec($imageCH);
		curl_close($imageCH);

		// output to browser
		if (!file_exists('img/')) {
		    mkdir('../img/', 0777, true);
		}
		$saveTo = '../img/' . $ref . '.jpg';
		file_put_contents($saveTo, $image);
     
		//$saveThumb = '../images/stories/virtuemart/product/' . $ref . '.jpg';
		//file_put_contents($saveThumb, $image);
		

		return 'img/' . $ref . '.jpg';
	}

	//Call Drive to return a list of products not synced
	function DRIVE_getProductNotSynced(){
		global $ch;
		 
		// #1 - get Order By Id
    	$url = backendUrl . '/SearchWS/QueryAsEntities';
    	$params =  array('itemQuery' => '{
    									  "entityName": "St",
    									  "distinct": false,
    									  "lazyLoaded": false,
    									  "SelectItems": [],
    									  "filterItems": [
    									    {
    									      "filterItem": "obs",
    									      "valueItem": "",
    									      "comparison": 0,
    									      "groupItem": 1
    									    }
    									  ],
    									  "orderByItems": [],
    									  "JoinEntities": [],
    									  "groupByItems": []
    									}');
    									
    	$response=DRIVE_Request($ch, $url, $params);
    	
    	if(empty($response)){
    		return false;
    	} else if(count($response['result']) == 0 ){
    		return null;
    	}
        
        return $response['result'];		 
		 
	}


	//Get New Instance (Entity= Cl , Bo, St)
	function DRIVE_getNewInstance($entity, $ndos){
	   
		global $ch;
		   
		$url = backendUrl . "/".$entity."WS/getNewInstance";
		$params =  array('ndos' => $ndos);

		$response=DRIVE_Request($ch, $url, $params);
		
		if(empty($response)){
			return null;
		}
		if(isset($response['messages'][0]['messageCodeLocale'])){
			return null;
		}
		
		
		return $response['result'][0];	
		
	}

	//Sync entity Instance (Entity= Cl , Bo, St)
   	function DRIVE_actEntiy($entity, $itemVO){
	   
	    global $ch;
	   	   
		$url = backendUrl . "/".$entity."WS/actEntity";
		$params =  array('entity' => json_encode($itemVO),
						 'code' => 0,
						 'newValue' => json_encode([])
					);

		$response=DRIVE_Request($ch, $url, $params);
	
		//echo json_encode( $response ); 
		if(empty($response)){
			return null;
		}
		if(isset($response['messages'][0]['messageCodeLocale'])){
			$msg = $response['messages'][0]['messageCodeLocale'];
			logData($msg);
			return null;
		}
		
		
		return $response['result'][0];	
	   
   	}

	//save Instance (Entity= Cl , Bo, St)
   	function DRIVE_saveInstance($entity, $itemVO){
		
		global $ch;
	   	   
		$url = backendUrl .  "/".$entity."WS/Save";
		$params =  array('itemVO' => json_encode($itemVO),
						 'runWarningRules' => 'false'
					);

		$response=DRIVE_Request($ch, $url, $params);
	
		//echo json_encode( $response ); 
		if(empty($response)){
			$msg = "Empty save";
			logData($msg);
			return null;
		}
		if(isset($response['messages'][0]['messageCodeLocale'])){
			$msg = $response['messages'][0]['messageCodeLocale'];
			logData($msg);
			return null;
		}
		
		
		return $response['result'][0];	
		
  	 }


	//Call Drive to return a product by ref(sku) or Id
	function DRIVE_getProductByRefOrId($ref, $id){
		global $ch;
		 
		// #1 - get Order By Id
    	$url = backendUrl . '/SearchWS/QueryAsEntities';
    	$params =  array('itemQuery' => '{
    									  "entityName": "St",
    									  "distinct": false,
    									  "lazyLoaded": false,
    									  "SelectItems": [],
    									  "filterItems": [
    									  	{
    									      "filterItem": "ref",
    									      "valueItem": "'. $ref .'",
    									      "comparison": 0,
    									      "groupItem": 9
    									    },
    									    {
    									      "filterItem": "obs",
    									      "valueItem": "'. $id .'",
    									      "comparison": 0,
    									      "groupItem": 9
    									    }
    									  ],
    									  "orderByItems": [],
    									  "JoinEntities": [],
    									  "groupByItems": []
    									}');
    									
    	$response=DRIVE_Request($ch, $url, $params);
    	
    	if(empty($response)){
    		return false;
    	} else if(count($response['result']) == 0 ){
    		return null;
    	}
        
        return $response['result'][0];		 
		 
	}
	 
	//Call Drive to return an order by observation Id
	function DRIVE_getCustomerByNcontOrId($ncont, $id){
		global $ch;
		
		// #1 - get Order By Id
    	$url = backendUrl . '/SearchWS/QueryAsEntities';

		
		$params =  array('itemQuery' => '{
									  "entityName": "Cl",
									  "distinct": false,
									  "lazyLoaded": false,
									  "SelectItems": [],
									  "filterItems": [
										{
										  "filterItem": "ncont",
										  "valueItem": "'. $ncont .'",
										  "comparison": 0,
										  "groupItem": 0
										}
									  ],
									  "orderByItems": [],
									  "JoinEntities": [],
									  "groupByItems": []
									}');
	

		
    	
    									
    	$response=DRIVE_Request($ch, $url, $params);
    	
    	if(empty($response)){
    		return false;
    	} else if(count($response['result']) == 0 ){
    		return null;
    	}
        
        return $response['result'][0];		 
		 
	}
	  	 
	//Call Drive to return an order by observation Id
	function DRIVE_getOrderById($obsId){

		global $ch;
		 
		// #1 - get Order By Id
		$url = backendUrl . '/SearchWS/QueryAsEntities';
		$params =  array('itemQuery' => '{
										  "entityName": "Bo",
										  "distinct": false,
										  "lazyLoaded": false,
										  "SelectItems": [],
										  "filterItems": [
										  	{
										      "filterItem": "obs",
										      "valueItem": "'. $obsId .'",
										      "comparison": 0,
										      "groupItem": 1
										    }
										  ],
										  "orderByItems": [],
										  "JoinEntities": [],
										  "groupByItems": []
										}');
										
		$response=DRIVE_Request($ch, $url, $params);

		if(empty($response)){
			return false;
		} else if(count($response['result']) == 0 ){
			return null;
		}

		return $response['result'][0];
		 
		 
	 }
	 
	 
	 //Call Login 
	 function DRIVE_userLogin(){
		global $ch;
		
		$url = backendUrl . '/UserLoginWS/userLoginCompany';
		
    	// Create map with request parameters
    	$params = $_SESSION['driveCredentials'];
    	
    	// Build Http query using params
    	$query = http_build_query ($params);
    	//initial request with login data
    	
    	//URL to save cookie "ASP.NET_SessionId"
    	curl_setopt($ch, CURLOPT_URL, $url);
    	curl_setopt($ch, CURLOPT_USERAGENT,'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Ubuntu Chromium/32.0.1700.107 Chrome/32.0.1700.107 Safari/537.36');
    	curl_setopt($ch, CURLOPT_POST, true);
    	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    	//Parameters passed to POST
    	curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
    	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    	curl_setopt($ch, CURLOPT_COOKIESESSION, true);
    	curl_setopt($ch, CURLOPT_COOKIEJAR, '');
    	curl_setopt($ch, CURLOPT_COOKIEFILE, '');
    	$response = curl_exec($ch);
    	
    	// send response as JSON
    	$response = json_decode($response, true);
    	if (curl_error($ch)) {
    		return false;
    	} else if(empty($response)){
    		return false;
    	} else if(isset($response['messages'][0]['messageCodeLocale'])){
			echo $response['messages'][0]['messageCodeLocale']."<br>";
    		echo "Error in login. Please verify your username, password, applicationType and company." ;
    		return false;
    	}
    	return true;
	 }
	
	//Generic function to get all records from an entity (used for Country, Tax and Isemption) 
	function DRIVE_getAllRecordList($entityName){

		global $ch;

		$url = backendUrl . "/SearchWS/QueryAsEntities";
		$params =  array('itemQuery' => '{"distinct": true,
								"groupByItems": [],
								"orderByItems": [],
								"SelectItems": [],
								"entityName": "'. $entityName .'",
								"filterItems": [],
								"joinEntities": []
							}'
					);

		$response=DRIVE_Request($ch, $url,$params);

		if($response == null){
			return $response;			
		}

		return $response['result'];

	}

	// Drive Generic call 
	function DRIVE_Request($ch, $url,$params){
		
    	// Build Http query using params
    	$query = http_build_query ($params);
    	curl_setopt($ch, CURLOPT_URL, $url);
    	curl_setopt($ch, CURLOPT_POST, false);
    	curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
	
		curl_setopt($ch, CURLOPT_BINARYTRANSFER, false);


    	$response = curl_exec($ch);
    	// send response as JSON
    	return json_decode($response, true);
    }
	 
	 

	/* Log Errors and data to Log */
	function logData($data){
		
		$file = 'log.txt';
		// Open the file to get existing content
		$current = file_get_contents($file);
		// Append a new person to the file
		$current .=  "\n\n----------------------" . date("Y-m-d H:i:s") . "----------------------\n" . $data ;
		// Write the contents back to the file
		file_put_contents($file, $current);
		
	} 
	 
	/*Not in use*/
	function storeImage(){
		/*$data = 'iVBORw0KGgoAAAANSUhEUgAAABwAAAASCAMAAAB/2U7WAAAABl'
       . 'BMVEUAAAD///+l2Z/dAAAASUlEQVR4XqWQUQoAIAxC2/0vXZDr'
       . 'EX4IJTRkb7lobNUStXsB0jIXIAMSsQnWlsV+wULF4Avk9fLq2r'
       . '8a5HSE35Q3eO2XP1A1wQkZSgETvDtKdQAAAABJRU5ErkJggg==';
		$data = base64_decode($data);

		$im = imagecreatefromstring($data);
		if ($im !== false) {
		header('Content-Type: image/png');
		imagepng($im);
		imagedestroy($im);
		}
		else {
		echo 'An error occurred.';
		}

		$file = '123.jpg';
			
			file_put_contents($file, $data);
		exit(1);
			*/
		
	}
	 
	 
	
?>
	 
	 
	 
	 
	
?>