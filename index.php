<?php
	//Define SOAP Settings
	define("SOAP_BASE", "http://www.reparacaomobile.pt/administrator/components/com_vm_soa/services");
	define("SYNC_FROM_DATE", date("Y-m-24 00:00:00"));
		
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


	print_r("Starting Sync...<br>");
	
	$loginResult = DRIVE_userLogin();
	if($loginResult == false){
		exit(1);
	}
	
	//get order by id 
	$order = DRIVE_getOrderById("1");
	print_r($order);
	exit(1);
	
		
	$orderArray = WSDL_GetOrderFromDate();
	print_r(json_encode($orderArray,true));
	exit(1);
		
	
	
	
	
	
	/******************************
	 ***   WSDL Call Functions  ***
	 ******************************/
	//Call WSDL to get Orders Between a month
	function WSDL_GetOrderFromDate(){
		//First day of month
		$date_start = SYNC_FROM_DATE;
		
		//To the present
		$date_end= date("Y-m-d H:i:s");
		
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
	 
	 
	 
	 
	 
	 
	 
	
?>