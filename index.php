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
	define("backendUrl", "https://sis05.drivefx.net/c2b337a9/PHCWS/REST");
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
	
	//TESTS--
	/*

		$customer = WSDL_GetUserInfoFromOrderId(3559);
		print_r(json_encode($customer,true));
		exit(1);

		$orderProducts = WSDL_GetProductsFromOrderId(3559);
		print_r(json_encode($orderProducts,true));
		exit(1);

		
		$loginResult = DRIVE_userLogin();
		if($loginResult == false){
			exit(1);
		}
		
		//get product by ref / id 
		$product = DRIVE_getProductByRefOrId("100192", "1");
		print_r($product);
		exit(1);
		
		//get customer by ncont / id 
		$customer = DRIVE_getCustomerByNcontOrId("123456789", "1");
		print_r($customer);
		exit(1);

		
		//get order by id 
		$order = DRIVE_getOrderById("1");
		print_r($order);
		exit(1);


		$orderArray = WSDL_GetOrderFromDate();
		print_r(json_encode($orderArray,true));
		exit(1);
	*/
		
	
		
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
	 
	 
	 
	 
	
?>
	 
	 
	 
	 
	
?>