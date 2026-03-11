<?php

namespace App\Http\Controllers\Seller\Couriers\Fulfillment;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Pickup;
use App\Models\ShipmentInfo;
use App\Services\OrderService;
use App\Models\CourierStatusMapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Services\ShippingRateService;
use App\Services\SellerWalletService;
use Carbon\Carbon;
class DelhiveryCourierController extends Controller
{
    private $order_ids 		= array();
	private $courier_id 		= 0;
	private $company_id 		= 0;
    private $courier_settings 	= array();
    private $api_mode = '';
    private $token ='';
    private $env_type='';
    private $api_url='';
    public $pickup_address = array();
    public $return_address = array();
    public $errors = array();
    public $result=array();
    public $action="";
    public $print_response=array();
    public $shipment_mode='';
    private int $parent_company_id;
    private int $parent_courier_id;
    public function __construct($order_ids = array() , $courier_id = 0 , $company_id = 0,$courier_settings=array()){
		
		$this->order_ids 	= $order_ids;
		$this->courier_id 	= $courier_id;
		$this->company_id 	= ($company_id) ? $company_id : session('company_id');
        $this->courier_settings = $courier_settings;
        $this->parent_company_id = $courier_settings['company_id'] ?? 0;
        $this->parent_courier_id = 3;
        $courier_details = ($courier_settings->courier_details)?json_decode($courier_settings['courier_details'],true):array();
        $this->token = $courier_details['api_token']??'';
        $this->shipment_mode = $courier_details['shipment_mode']??'';
        $this->env_type = $courier_settings['env_type']??'dev';
        if($this->env_type=='dev'){
            $this->api_url = 'https://staging-express.delhivery.com';
        }else{
            $this->api_url = 'https://track.delhivery.com';
        }
		
	}
	public function assignTrackingNumber()
    {
        // Validate and segregate orders
        list($allowedOrders, $invalidLogin) = $this->checkPincodeServiceability($this->order_ids);

        if ($invalidLogin) {
            $this->result['error'][] = 'Invalid courier credentials';
            if($this->action == 'print_response'){
                $this->result['print_response'] = $this->print_response;
            }
            return $this->result;
        }
        // Separate unserviceable orders
        $removedOrders = array_diff($this->order_ids, array_keys($allowedOrders));
        $this->order_ids = array_intersect($this->order_ids, array_keys($allowedOrders));

        if (!empty($removedOrders)) {
            $this->result['error'] = DB::table('orders')
                ->whereIn('id', $removedOrders)
                ->pluck('vendor_order_number')
                ->mapWithKeys(fn($order) => [$order => "Pincodes is not serviceable by Delhivery."])
                ->toArray();
        }

        foreach ($this->order_ids as $order_id) {
            $order_info = Order::with('orderProducts', 'orderTotals', 'shipmentInfo')->find($order_id)->toArray();

            if (isset($order_info['shipment_info']) && !empty($order_info['shipment_info'])) {
                $this->result['error'][$order_info['vendor_order_number']] = "Tracking number already assigned";
                continue;
            }
            $order_info['payment_mode'] = strtolower($order_info['payment_mode']);
            $rateService = app(ShippingRateService::class);

            $weight = $order_info['package_dead_weight'] ?? 0.5;
            $isCod  = $order_info['payment_mode'] === 'cod';

            $rate = $rateService->calculate(
                $this->parent_company_id,
                $this->company_id,
                $this->courier_id,
                $this->pickup_address->zipcode,
                $order_info['s_zipcode'] ?: $order_info['b_zipcode'],
                $weight,
                $order_info['package_length'] ?? 10,
                $order_info['package_breadth'] ?? 10,
                $order_info['package_height'] ?? 10,
                (int)$isCod,
                $order_info['order_total']
            );
            if (!isset($rate['shipping_cost'])) {
                $this->result['error'][$order_info['vendor_order_number']] = "Pincode is not serviceble";
                continue;
            }
            $courier_shipping_cost = $rate['shipping_cost'];
            $cod_charges = $isCod ? ($rate['cod_charge'] ?? 0) : 0;
            $walletService = app(SellerWalletService::class);
            if (!$walletService->hasSufficientBalance($this->company_id,$courier_shipping_cost)) {
                $this->result['error'][$order_info['vendor_order_number']] = 'Insufficient wallet balance to ship this order';
                continue;
            }
            $trackingNumber = trim($this->fetchWaybill(), '"');
            if (empty($trackingNumber)) {    
                if($this->action == 'print_response'){
                    $this->result['print_response'] = $this->print_response;
                    return $this->result;
                }            
                $this->result['error'][$order_info['vendor_order_number']] = "Tracking number not found";
                continue;
            }

            $consignee_phone = isset($order_info['s_phone']) ? $order_info['s_phone'] : $order_info['b_phone'];
            $consignee_phone = preg_replace('/[^0-9]/', '', $consignee_phone);
            $consignee_phone = substr($consignee_phone, -10);
            $weight = $order_info['package_dead_weight']??0.05;
            $weight = $weight*1000;
            // Prepare package information
            $packageInfo = [
                'pickup_location' => [
                    'add' => $this->removeSpecailCharacter($this->pickup_address->address),
                    'city' => $this->removeSpecailCharacter($this->pickup_address->city),
                    'country' => $this->pickup_address->country_code,
                    'name' => $this->removeSpecailCharacter($this->pickup_address->location_title),
                    'phone' => $this->pickup_address->phone,
                    'pin' => $this->pickup_address->zipcode,
                ],
                'shipments' => [
                    [
                        'waybill' => $trackingNumber,
                        'name' => $this->removeSpecailCharacter($order_info['s_fullname']),
                        'order' => $order_info['vendor_order_number'] ?? $order_info['id'],
                        'products_desc' =>$this->removeSpecailCharacter(implode(', ', array_column($order_info['order_products'], 'product_name'))),
                        'order_date' => $order_info['channel_order_date'],
                        'payment_mode' => $order_info['payment_mode'] === 'prepaid' ?'Prepaid':'COD',
                        'total_amount' => $order_info['order_total'],
                        'cod_amount' => $order_info['payment_mode'] === 'prepaid' ? 0:$order_info['order_total'],
                        'add' => $this->removeSpecailCharacter($order_info['s_complete_address'] ?? $order_info['b_complete_address']),
                        'city' => $this->removeSpecailCharacter($order_info['s_city'] ?? $order_info['b_city']),
                        'state' => $order_info['s_state_code'] ?? $order_info['b_state_code'],
                        'country' => $order_info['s_country_code'] ?? $order_info['b_country_code'],
                        'phone' => $consignee_phone,
                        'pin' => $order_info['s_zipcode'] ?? $order_info['b_zipcode'],
                        'shipping_mode' => $this->shipment_mode,
                        'return_add' => $this->removeSpecailCharacter($this->return_address->address),
                        'return_city' => $this->removeSpecailCharacter($this->return_address->city),
                        'return_country' => $this->return_address->country_code,
                        'return_name' => $this->removeSpecailCharacter($this->return_address->contact_person_name),
                        'return_phone' => $this->return_address->phone,
                        'return_pin' => $this->return_address->zipcode,
                        'return_state' => $this->return_address->state_code,
                        'shipment_length' => $order_info['package_length'] ?? 10,
                        'shipment_width' => $order_info['package_breadth'] ?? 10,
                        'shipment_height' => $order_info['package_height'] ?? 10,
                        'weight' => $weight,
                        'quantity' => array_sum(array_column($order_info['order_products'], 'quantity')),
                    ],
                ],
            ];
            if (ShipmentInfo::where('order_id', $order_id)->exists()) {
                $this->result['error'][$order_info['vendor_order_number']] = 'Tracking number already assigned';
                continue;
            }
            list($status, $response) = $this->orderCreation($packageInfo, $trackingNumber);
            $pickup_address = $this->pickupAddressFormat($this->pickup_address);
            $return_address = $this->pickupAddressFormat($this->return_address);

            if ($status) {
                try {
                
                    DB::transaction(function () use (
                        $order_id,
                        $order_info,
                        $trackingNumber,
                        $pickup_address,
                        $return_address,
                        $rate
                    ) {
                        
                        /** -------------------------------
                        * STEP 1: Create or fetch shipment
                        * -------------------------------- */
                        $shipment = ShipmentInfo::firstOrCreate([
                            'order_id' => $order_id], [
                            'company_id' => $this->company_id,
                            'shipment_type' => $this->shipment_mode,
                            'courier_id' => $this->courier_id,
                            'tracking_id' => $trackingNumber,
                            'applied_weight' => $rate['chargeable_weight']??0,
                            'pickedup_location_id' => $this->pickup_address->id,
                            'pickedup_location_address' => $pickup_address,
                            'return_location_id' => $this->return_address->id,
                            'return_location_address' => $return_address,
                            'manifest_created' => 0,
                            'payment_mode' => $order_info['payment_mode'],
                        ]);
                       
                        /** -------------------------------
                         * STEP 1: Apply Freight (Wallet Debit)
                         * -------------------------------- */
                        app(SellerWalletService::class)->applyFreight([
                            'company_id'      => $this->company_id,
                            'order_id'        => $order_id,
                            'shipment_id'     => $shipment->id,
                            'courier_id'      => $this->courier_id,
                            'courier_code'    => 'delhivery',
                            'tracking_number' => $trackingNumber,
                            'amount'          => $rate['shipping_cost'],
                            'cod_charges'     => $rate['cod_charge'] ?? 0
                        ]);
                        /** -------------------------------
                        * STEP 3: Update Order Status
                        * -------------------------------- */
                        Order::where('id', $order_id)->update(['status_code' => 'P', 'rate_card_id' => $rate['rate_card_id']??null]);
                    });
                    $this->result['success'] = true;
                } catch (\Exception $e) {
                    $this->result['error'][$order_info['vendor_order_number']] = "Failed to assigned tracking number"." Error: ".$e->getMessage();
                }
            } else {
                $rmk = $response['rmk']??'';
                $rmk1 = $response['packages'][0]['remarks']??'';
                $rmk1 = $rmk1['0']??'';
                $rmk = trim($rmk.' '.$rmk1);               
                $this->result['error'][$order_info['vendor_order_number']] = "Failed to assigned tracking number"." Error: $rmk";
            }
            if($this->action == 'print_response'){
                $this->result['print_response'] = $this->print_response;
                return $this->result;
            }
        }

        return $this->result;
    }

    public function validateCredentials()
    {
        return !empty($this->token) && !empty($this->company_id) && !empty($this->courier_id);
    }
    protected function orderCreation($packageInfo , $trackingNumber = 0){
		
		$requestData = array();
		
		$requestData['output'] 		= 'json';
		$requestData['format'] 		= 'json';
		//$requestData['token'] 		= $this->token;		
		$requestData['data']		= json_encode($packageInfo);
		$url =$this->api_url.'/api/cmu/create.json';
		
        $curl = curl_init();
        curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS =>'format=json&data='.json_encode($packageInfo),
        CURLOPT_HTTPHEADER => array(
            'Authorization: Token ' . $this->token,
            'Content-Type: application/json',
            'Accept: application/json'
        ),
        ));

        $response = curl_exec($curl);
        $response = ($response)?json_decode($response,true):array();
        curl_close($curl);
        if($this->action == 'print_response'){
            $this->print_response['assign']['url'] = $url;
            $this->print_response['assign']['header']=array(
                'Authorization: Token ' . $this->token,
                'Content-Type: application/json',
                'Accept: application/json'
            );
            $this->print_response['assign']['request_data']='format=json&data='.json_encode($packageInfo);
            $this->print_response['assign']['response_data'] = $response;
        }
		$status = false;
		
		if( isset($response['success'] ) && !empty($response['success']) ){
			$status = true;
		}
		return array($status , $response);
		
	}
    public function checkPincodeServiceability($order_ids)
    {
        $invalidLogin = false;

        if (!empty($order_ids)) {

            // Build query condition based on input
            $orders = Order::whereIn('id', $order_ids)->get(['id', 'payment_mode', 's_zipcode', 'b_zipcode']);
            $zipcodeData = [];
            $paymentType = [];


            foreach ($orders as $order) {
                $zipcodeData[$order->id] = !empty($order->s_zipcode) ? str_replace(" ", "", $order->s_zipcode) : str_replace(" ", "", $order->b_zipcode);
                $paymentType[$order->id] = ($order->payment_mode == 'prepaid') ?'pre_paid':'cod';
            }

            $url = $this->api_url.'/c/api/pin-codes/json/?token=' . $this->token . '&filter_codes=' . implode(',', $zipcodeData);                
           
            // Use Laravel's HTTP Client to make API request
            $response = Http::get($url);
            $responseBody = $response->body();
            if (str_contains(strtolower($responseBody), 'api key required')) {
                $invalidLogin = true;
            }

            $pincodes = json_decode($responseBody, true);
            if($this->action == 'print_response'){
                $this->print_response['pincode']['url'] = $url;
                $this->print_response['pincode']['response_data'] = $pincodes;
			}
            $availablePincodes = [];

            if (isset($pincodes['delivery_codes'])) {
                foreach ($pincodes['delivery_codes'] as $pincode) {
                    $availablePincodes[$pincode['postal_code']['pin']] = $pincode['postal_code'];
                }
            }

            $allowedOrders = [];

            foreach ($zipcodeData as $order_id => $pincode) {
                if (isset($availablePincodes[$pincode][$paymentType[$order_id]]) && $availablePincodes[$pincode][$paymentType[$order_id]] == 'Y') {
                    $allowedOrders[$order_id] = $pincode;
                }
            }
            return [$allowedOrders, $invalidLogin];
        }

        return [[], $invalidLogin];
    }
    public function fetchWaybill(){
        $url = $this->api_url.'/waybill/api/bulk/json/?count=1';
        // Define headers
        $headers = [
            'Authorization' => 'Token ' . $this->token,
            'Content-Type' => 'application/json',
        ];
        $response = Http::withHeaders($headers)->get($url);
        if($this->action == 'print_response'){
            $this->print_response['fetchawb']['url'] = $url;
            $this->print_response['fetchawb']['header'] = $headers;
            $this->print_response['fetchawb']['response_data'] = $response->json();
        }
        return $response->body();

    }
    public function createPickup($manifest_id,$pickup_day=null){
        $url = $this->api_url.'/fm/request/new/';
        $headers = [
            'Authorization' => 'Token ' . $this->token,
            'Content-Type' => 'application/json',
        ];
        $pickupdate = $this->pickupDateTime($pickup_day);
        $pickup_time = $pickupdate['pickup_time'];
        $pickup_date = $pickupdate['pickup_date'];
        $name = $this->pickup_address->location_title;
        $requestData = array(
			'pickup_time' => $pickup_time,
			'pickup_date' => $pickup_date,
			'pickup_location' => $name,
			'expected_package_count' => count($this->order_ids),
		);
        $response = Http::withHeaders($headers)->post($url,$requestData);
        $response = json_decode($response, true);
        $result=array();
        if(isset($response['pickup_id']) && (!isset($response['pr_exist']) || isset($response['pr_exist']))){
            DB::table('manifests')
            ->where('id', (int)$manifest_id)
            ->update(['pickup_created' => '1']);
            $result['success'] = 'Pickup created successfully.';

            $insertData = [];
            foreach ($this->order_ids as $order_id) {
                // Delete existing pickup records for the manifest_id and order_id
                DB::table('pickup')
                    ->where('manifest_id', $manifest_id)
                    ->where('order_id', $order_id)
                    ->delete();
        
                // Prepare insert data
                $insertData[] = [
                    'manifest_id' => (int) $manifest_id,
                    'order_id' => (int) $order_id,
                    'pickup_id' => addslashes($response['pickup_id']),
                    'pickup_time' => $pickup_time,
                    'api_response' => json_encode($response),
                    'created_at' => now(),
                    'updated_at' => now()
                ];
                $pickedup_date = $pickup_date.' '.$pickup_time;
                DB::table('shipment_info')->where('order_id', $order_id)->update(['pickedup_date' => $pickedup_date]);

            }
            DB::table('pickup')->insert($insertData);            
        }elseif(isset($response['success']) && $response['success']==false){
            $result['error'][] = $response['error']['message']??'somthing went wrong please try after some time';
        }elseif(isset($response['prepaid'])){
            $result['error'][] = $response['prepaid'];
        }elseif(isset($response['pickup_date']) && $response['pickup_date']=='Pickup date cannot be in past'){
            $result['error'][] = $response['pickup_date'];
        }elseif(isset($response['pickup_location']) && $response['pickup_location']=='Invalid Pickup Location ClientWarehouse matching query does not exist.'){
            $result['error'][] = $response['pickup_location'];
        }
    
        return $result;
    }
    public function trackShipment($order_id,$tracking_number){
        $parentId = $this->courier_settings->courier?->parent_id;
        //return $parentId;
        $orderService =new OrderService();
        $url = $this->api_url.'/api/v1/packages/json/?waybill='.$tracking_number;
        $headers = [
            'Authorization' => 'Token ' . $this->token,
            'Content-Type' => 'application/json',
        ];
        $response = Http::withHeaders($headers)->get($url);
        $response = json_decode($response, true);
        
        $scansdata = array();
        $shipmentData = $response['ShipmentData']??[];
      //return $shipmentData;
        if($shipmentData){
            foreach($shipmentData as $shipmentDetails){
                $shipmentDetail = $shipmentDetails['Shipment']??[];
                $scansdata['courier_id'] = $this->courier_id;  
                $scansdata['tracking_number'] = $tracking_number;  
                $scansdata['origin'] = $shipmentDetail['Origin'];                 
                $scansdata['destination'] = $shipmentDetail['Destination']??'';
                $scansdata['pickup_date'] =  (!empty($shipmentDetail['PickUpDate']))?date('Y-m-d H:i:s',strtotime($shipmentDetail['PickUpDate'])):''; 
                $scansdata['expected_delivery_date'] = (!empty($shipmentDetail['ExpectedDeliveryDate']))?date('Y-m-d H:i:s',strtotime($shipmentDetail['ExpectedDeliveryDate'])):''; 
                $currentdata = $shipmentDetail['Status']??[]; 
                $current_status = $currentdata['Status']??'';   
                $courier_status_mapping = CourierStatusMapping::where('courier_id', $parentId)
                    ->where('courier_status', $current_status)
                    ->first(); 
                if (!$courier_status_mapping) {
                    $courier_status_mapping = CourierStatusMapping::create([
                            'courier_id' => $parentId,
                            'courier_status' => $current_status,
                            'shipment_status_code' => '',
                        ]
                    );
                }
                $scansdata['current_status_code'] = $courier_status_mapping->shipment_status_code?$courier_status_mapping->shipment_status_code:$scansdata['current_status'];
                $scansdata['current_status_date'] = (!empty($currentdata['StatusDateTime']))?date('Y-m-d H:i:s',strtotime($currentdata['StatusDateTime'])):''; 
                $scansdata['pod'] = $shipmentDetail['pod']??''; 
                
                $scansdata['scans'] = [];                
                $scans = $shipmentDetail['Scans']??[];
                //return $shipmentDetails;
                foreach($scans as $scan){
                    $trackingHistories = array();
                    $ScanDetail = $scan['ScanDetail'];
                    $trackingHistories['status'] = $ScanDetail['Scan']; 
                    $courier_status_mapping = CourierStatusMapping::where('courier_id', $parentId)
                    ->where('courier_status', $trackingHistories['status'])
                    ->first();
                    if (!$courier_status_mapping) {
                        $courier_status_mapping = CourierStatusMapping::create([
                                'courier_id' => $parentId,
                                'courier_status' => $trackingHistories['status'],
                                'shipment_status_code' => '',
                            ]
                        );
                    }
                    $shipment_status_code = $courier_status_mapping->shipment_status_code??$trackingHistories['status'];
                    $trackingHistories['current_status_code'] = $shipment_status_code;                     
                    $trackingHistories['date'] =(!empty($ScanDetail['StatusDateTime']))?date('Y-m-d H:i:s',strtotime($ScanDetail['StatusDateTime'])):''; 
                    $trackingHistories['location'] = $ScanDetail['ScannedLocation']; 
                    $scansdata['scans'][] = $trackingHistories;
                } 
            }
            
        }
        if($scansdata){
            $orderService->addShipmentTrackDetails($order_id,$scansdata);
        }
        return $scansdata;

    }
    public function cancelShipments() {  
        $apiUrl =  $this->api_url.'/api/p/edit';
        if ($this->order_ids) {
            try {
                foreach ($this->order_ids as $orderId) {
                    try {
                        $shipmentInfo = ShipmentInfo::where('order_id', $orderId)->first();
                        if (!$shipmentInfo) {
                            $this->result['error'][] = "Shipment info not found for Order ID $orderId";
                            continue; 
                        }                         

                        $requestPayloads = [
                            "waybill" => $shipmentInfo->tracking_id,
                            "cancellation"=> true
                        ];
                        $response = Http::withHeaders([
                            'Authorization' => 'Token ' . $this->token,
                            'Content-Type' => 'application/json',
                            'Accept' => 'application/json',
                        ])->post($apiUrl, $requestPayloads);
                        
                        $responseBody = $response->json();
                        $status = $responseBody['status']??false;
                        $ReturnMessage = $responseBody['detail']??'';
                        if($status===true){
                            DB::transaction(function () use ($orderId, $shipmentInfo) {
                                app(SellerWalletService::class)->revertFreight([
                                    'company_id'      => $shipmentInfo->company_id,
                                    'shipment_id'     => $shipmentInfo->id,
                                    'tracking_number' => $shipmentInfo->tracking_id,
                                ]);
                                ShipmentInfo::where('order_id', $orderId)->delete();
                                Order::where('id', $orderId)->update(['status_code' => 'N']);
                            });
                            // DB::transaction(function () use ($orderId) {
                            // ShipmentInfo::where('order_id', $orderId)->delete();
                            // Order::where('id', $orderId)->update([
                            //         'status_code' => 'N'
                            //     ]);
                            // });
                            $this->result['success'][] = $orderId .' is canceled successfully';
                        }else{
                            if (!empty($ReturnMessage)) {
                                $ReturnMessage = $ReturnMessage;
                            } else {
                                $ReturnMessage = $responseBody['error']??'';
                            }
                            $this->result['error'][] = $ReturnMessage ." for Order ID $orderId";
                        }
                        
                    } catch (\Exception $e) {
                        $this->result['error'][] = "Something went wrong with Order ID $orderId: " . $e->getMessage();
                    }
                }
    
            } catch (\Exception $e) {
                $this->result['error'][] = $e->getMessage();
            }
        }    
        return $this->result;
    }
    public function calculateShipping(){
        foreach ($this->order_ids as $order_id) {
            $order_info = Order::find($order_id)->toArray();
            $package_dead_weight = $order_info['package_dead_weight']*1000;
            $payment_mode = strtolower($order_info['payment_mode']);
            $s_zipcode = $order_info['s_zipcode'] ?? $order_info['b_zipcode'];
            $cod_amount=0;
            if($payment_mode==='prepaid'){
                $payment_mode = 'Pre-paid';
            }else{
                $cod_amount = $order_info['order_total'];
                $payment_mode = 'COD';
            }
            $service_type='E';
            if($this->shipment_mode=='surface'){
                $service_type='S';
            }
            $url = $this->api_url.'/api/kinko/v1/invoice/charges/.json?md='.$service_type.'&ss=Delivered&d_pin='.$s_zipcode.'&o_pin='.$this->pickup_address->zipcode.'&cgm='.$package_dead_weight.'&pt='.$payment_mode.'&cod='.$cod_amount;
            $headers = [
                'Authorization' => 'Token ' . $this->token,
                'Content-Type' => 'application/json',
            ];
            $response = Http::withHeaders($headers)->get($url);
            $response = $response->json();
           
            $detail = $response['detail']??'';
            if(empty($detail)){
                $res = $response[0]??[];
                $total_amount=$res['total_amount']??0;
                $this->result['success'] = true;
                $this->result['message'] = "Shipping cost ".$total_amount;
            }else{
                $this->result['error'][] = $detail;
            }            

        }
        return $this->result;

    }
    public function pickupAddressFormat($pickup_address){
        $pick_address = $pickup_address->address;
        if($pickup_address->landmark){
            $pick_address .= ', '.$pickup_address->landmark;
        }
        if($pickup_address->city){
            $pick_address .= ', '.$pickup_address->city;
        }
        if($pickup_address->state_code){
            $pick_address .= ', '.$pickup_address->state_code;
        }
        if($pickup_address->zipcode){
            $pick_address .= ', '.$pickup_address->zipcode;
        } 
        return $pick_address;

    }
    public function pickupDateTime($pickup_day=null){
        $now = Carbon::now();

        if ($pickup_day === 1) {
            if ($now->lt(Carbon::createFromTime(14, 0))) {
                // Before 2 PM – Pickup today at 2 PM
                $pickup = $now->copy()->setTime(14, 0);
            } elseif ($now->between(Carbon::createFromTime(14, 0), Carbon::createFromTime(16, 0))) {
                // Between 2 PM and 4 PM – Pickup today at 5 PM
                $pickup = $now->copy()->setTime(17, 0);
            } else {
                // After 4 PM – Pickup next day at 10 AM
                $pickup = $now->copy()->addDay()->setTime(10, 0);
            }
        } else {
            // If pickup_day is not 1 – Pickup next day at 10 AM
            $pickup = $now->copy()->addDay()->setTime(10, 0);
        }

        return [
            'pickup_date' => $pickup->format('Y-m-d'),
            'pickup_time' => $pickup->format('H:i:s'),
        ];
    }
    public function removeSpecailCharacter($string){    
        // Replace specific symbols
        $string = str_replace('&', 'and', $string);
        $string = str_replace('|', '-', $string);

        // Allow only a-z, A-Z, 0-9, space, comma, dash, parentheses, / and \
        $string = preg_replace('/[^A-Za-z0-9 ,\-()\/\\\\]/', '', $string);

        // Trim multiple spaces
        return trim(preg_replace('/\s+/', ' ', $string));

    }
}
