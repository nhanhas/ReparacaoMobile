<?php
	//Define SOAP Settings
	define("SOAP_BASE", "http://www.reparacaomobile.pt/administrator/components/com_vm_soa/services");
	define("SYNC_FROM_DATE", date("Y-m-29 00:00:00"));
		
	//Build a Session Credentials, to use in all requests
	$_SESSION['loginInfo'] = array(
		login=>"carlossantos",
		password=>"xpto1234",
		isEncrypted=>"N",
		lang=>"PT"
	);
	
	
	//Define DriveFX settings
	define("backendUrl", "https://sis05.drivefx.net/c2b337a9/PHCWS/REST");
	$_SESSION['driveCredentials'] = array(
		userCode=>"admin",
		password=>"12345678",
		applicationType=>"HYU45F-FKEIDD-K93DUJ-ALRNJE",
		company=>""
	);
	
	define("orderNdoc", 1);
	
	//set as global Call HEADER for Drive fX
	$ch = curl_init();
	
	//WSDL Reference : http://www.virtuemart-datamanager.com/soap/VM2_SOAP_DOC.html#WS-VM_Product
	//Drive FX : https://sis05.drivefx.net/c2b337a9/html/

	error_reporting(E_ERROR | E_PARSE);


	print_r("Starting Sync...<br>");

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

	//#A - Start Syncing Orders
	syncOrders();

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
				$msg = "Error on sync Order with Id=".$order->id." .<br><br>";
				echo $msg;
				logData($msg);
				continue;
			}

			//At this point means that we have customer, now sync product

			echo "HERE<br>";
			print_r($customerDrive);
			echo "<br><br><br>";

		}
	}

	
	//#B - Minor functions

	//Treat all things to customer - get/create
	function processCustomer($customer){
		//#1 - check if it already exists in Drive
		//TODO send nif to search
		$driveCustomer = DRIVE_getCustomerByNcontOrId("NO NIF YET", $customer->user_id);
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
		$newInstanceCl['morada'] = $customer->address_1;
		$newInstanceCl['local'] = $customer->city;
		$newInstanceCl['codpost'] = $customer->zip;

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
		//#1 - set Login info
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
		return $customer;
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
		
		//#6 - Return Result
		return $orderProducts;
	} 
	 

	//Call WSDL to get Orders Between a month
	function WSDL_GetOrderFromDate(){
		//First day of month
		$date_start = date("Y-9-28 20:20:s");//SYNC_FROM_DATE;
		
		//To the present
		$date_end= date("Y-9-28 23:30:s");//date("Y-m-d H:i:s");
		
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

		if($id == 0){
			//means that we want generic customer
			$params =  array('itemQuery' => '{
    									  "entityName": "Cl",
    									  "distinct": false,
    									  "lazyLoaded": false,
    									  "SelectItems": [],
    									  "filterItems": [
    									  	{
    									      "filterItem": "clivd",
    									      "valueItem": true,
    									      "comparison": 0,
    									      "groupItem": 1
    									    }
    									  ],
    									  "orderByItems": [],
    									  "JoinEntities": [],
    									  "groupByItems": []
    									}');
		}else{
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
		}

		
    	
    									
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
    		echo "Error in login. Please verify your username, password, applicationType and company." ;
    		return false;
    	}
    	return true;
	 }
	
	//Generic function to get all records from an entity
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