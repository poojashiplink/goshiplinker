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
use App\Models\OrderCourierResponse;
use Illuminate\Support\Facades\Cache;
class NimbusPostCourierController extends Controller
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
    public $parent_courier_id=10;
    public function __construct($order_ids = array() , $courier_id = 0 , $company_id = 0,$courier_settings=array()){
		
		$this->order_ids 	= $order_ids;
		$this->courier_id 	= $courier_id;
		$this->company_id 	= ($company_id) ? $company_id : session('company_id');
        $this->courier_settings = $courier_settings;
        $courier_details = ($courier_settings->courier_details)?json_decode($courier_settings['courier_details'],true):array();
        foreach($courier_details as $key=>$value){
            $this->$key = $value;
        }  
        $this->api_url = 'https://api.nimbuspost.com';        
		
	}
	public function assignTrackingNumber()
    {
        $token = $this->authentication();
        if(empty($token)){
            $this->result['error'][] = 'Invalid courier credentials';
            if($this->action == 'print_response'){
                $this->result['print_response'] = $this->print_response;
            }
            return $this->result;
        }
        foreach ($this->order_ids as $order_id) {
            $order_info = Order::with('orderProducts', 'orderTotals', 'shipmentInfo')->find($order_id)->toArray();

            if (isset($order_info['shipment_info']) && !empty($order_info['shipment_info'])) {
                $this->result['error'][$order_info['vendor_order_number']] = "Tracking number already assigned";
                continue;
            }
            $cod_charges = 0;
            $shipping_charges = 0;
            $discount = 0;
            $taxes = 0;
            if(!empty($order_info['order_totals'])){
                foreach($order_info['order_totals'] as $order_totals){
                    if($order_totals['code']=='cod_charges'){
                        $cod_charges = $order_totals['value'];
                    }
                    if($order_totals['code']=='shipping'){
                        $shipping_charges = $order_totals['value'];
                    }
                    if($order_totals['code']=='discount'){
                        $discount = $order_totals['value'];
                    }
                    if($order_totals['code']=='tax'){
                        $taxes = $order_totals['value'];
                    }

                }

            }
            $payment_mode = strtolower($order_info['payment_mode']); 
            $consignee_phone = isset($order_info['s_phone']) ? $order_info['s_phone'] : $order_info['b_phone'];
            $consignee_phone = preg_replace('/[^0-9]/', '', $consignee_phone);
            $consignee_phone = substr($consignee_phone, -10);
            $orderProducts = $order_info['order_products'];
            $shipment_items = [];
            foreach($orderProducts as $key=>$orderProduct){
                $shipment_items[] = [
                    "name" => $orderProduct['product_name'],
                    "qty" => $orderProduct['quantity'],
                    "price" => $orderProduct['unit_price'],
                    "sku" => $orderProduct['sku']
                ];
            }
            // Prepare package information
            $package_dead_weight = $order_info['package_dead_weight'] ?? 0.05;
            $package_dead_weight = $package_dead_weight*1000;           
            $packageInfo = [
                "order_number" => $order_info['vendor_order_number'] ?? $order_info['id'],
                "shipping_charges" => $shipping_charges,
                "discount" => $discount,
                "cod_charges" => $cod_charges,
                "payment_type" => $payment_mode === 'prepaid' ?'prepaid':"cod",
                "order_amount" => $order_info['order_total'],              
                "package_weight" => $package_dead_weight,
                "package_length" => $order_info['package_length'] ?? 10,
                "package_breadth" => $order_info['package_breadth'] ?? 10,
                "package_height" => $order_info['package_height'] ?? 10,
                "request_auto_pickup" => "yes",
                "consignee" => [
                    "name" => $order_info['s_fullname']?:$order_info['b_fullname'],
                    "address" => $order_info['s_complete_address'] ?:$order_info['b_complete_address'],
                    "address_2" => $order_info['s_landmark'] ?: $order_info['b_landmark'],
                    "city" => $order_info['b_city'] ?: $order_info['s_city'],
                    "state" => $order_info['b_state_code'] ?: $order_info['s_state_code'],
                    "pincode" => $order_info['s_zipcode'] ?: $order_info['b_zipcode'],
                    "phone" => $consignee_phone
                ],                
                "pickup" => [
                    "warehouse_name" => $this->pickup_address->location_title,
                    "name" => $this->pickup_address->contact_person_name,
                    "address" => $this->pickup_address->address,
                    "address_2" => $this->pickup_address->landmark,
                    "city" => $this->pickup_address->city,
                    "state" => $this->pickup_address->state_code,
                    "pincode" => $this->pickup_address->zipcode,
                    "phone" => $this->pickup_address->phone,
                ],
                "order_items" => $shipment_items,
                "courier_id" =>$this->nimbus_post_courier_id??"",
                "is_insurance" => "0",
                "tags" => $order_info['order_tags']??"",
            ];            
                  
            $apiUrl = $this->api_url.'/v1/shipments';
            $headers = [
                'Authorization' => 'Bearer '.$token,
                'Content-Type'  => 'application/json',
            ];
            if (ShipmentInfo::where('order_id', $order_id)->exists()) {
                $this->result['error'][$order_info['vendor_order_number']] = 'Tracking number already assigned';
                continue;
            }

            $response = Http::withHeaders($headers)->post($apiUrl,$packageInfo);
         
            $response = $response->json(); 
            $pickup_address = $this->pickupAddressFormat($this->pickup_address);
            $return_address = $this->pickupAddressFormat($this->return_address);
            $status = $response['status']??false;
            $message = $response['message']??'';
            $responsedata = $response['data']??'';
                       
            if ($status===true) {  
                $other_details = array();
                $trackingNumber = $responsedata['awb_number']??'';
                $other_details['shipment_id'] = $responsedata['shipment_id']??'';
                $other_details['courier_id'] = $responsedata['courier_id']??'';
                $courier_name = $responsedata['courier_name']??'';
                $additional_info = $responseData['additional_info']??'';
                $additional_info = ($additional_info)?explode('/',$additional_info):[];
                $other_details['destination_area'] = (isset($additional_info[0]))?trim($additional_info[0]):'';
                $other_details['destination_location'] = (isset($additional_info[0]))?trim($additional_info[1]):'';
                $other_details['label_url'] = $responsedata['label']??'';    
                ShipmentInfo::create([
                    'order_id' => $order_id,
                    'company_id' => $this->company_id,
                    'shipment_type' => '',
                    'courier_id' => $this->courier_id,
                    'tracking_id' => $trackingNumber,
                    'applied_weight' => $order_info['package_dead_weight'],
                    'pickedup_location_id' => $this->pickup_address->id,
                    'pickedup_location_address' => $pickup_address,
                    'return_location_id' => $this->return_address->id,
                    'return_location_address' => $return_address,
                    'manifest_created' => 0,
                    'payment_mode' => $order_info['payment_mode'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                OrderCourierResponse::create([
                    'order_id' => $order_id,
                    'courier_code' => 'nimbus_post',
                    'courier_name' => $courier_name,
                    'response' => $other_details,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                Order::where('id', $order_id)->update(['status_code' => 'P']);
                $this->result['success'] = true;                 
                
            } else {
                $error = $message??'';
                $this->result['error'][$order_info['vendor_order_number']] = "Failed to assigned tracking number"." Error: $error";
            }
            if($this->action == 'print_response'){
                 $this->print_response['assign']['url'] = $apiUrl;
                $this->print_response['assign']['header']=$headers;
                $this->print_response['assign']['request_data']=json_encode($packageInfo);
                $this->print_response['assign']['response_data'] = $response;
                $this->result['print_response'] = $this->print_response;
                return $this->result;
            }
        }

        return $this->result;
    }   
    public function authentication(){
        $company_id = $this->company_id;
        $token = Cache::get("api_auth_token_nimbus_post_{$this->courier_id}_{$company_id}");        
        $apiUrl = $this->api_url.'/v1/users/login';       
        
        $postData = [
            'email' => $this->username,
            'password' => $this->password
        ];

        if (!$token) {
            $response = Http::timeout(60)
            ->acceptJson()
            ->withHeaders([
                'cache-control' => 'no-cache',
                'content-type' => 'application/json',
            ])
            ->post($apiUrl, $postData);

            $responseData = $response->json();
            if (isset($responseData['status']) && $responseData['status'] === true) {
                $token = $responseData['data']??'';
            }
            if($token){
                Cache::put("api_auth_token_nimbus_post_{$this->courier_id}_{$company_id}", $token, now()->addMinutes(30));
            }
            if($this->action == 'print_response'){
                $this->print_response['authentication']['auth_url'] = $apiUrl;
				$this->print_response['authentication']['body']=json_encode($postData);
                $this->print_response['authentication']['auth_response'] = $response;
                if (isset($responseData['status']) && $responseData['status'] === false) {
                    die();
                }
			}
        }        
        return $token;
    }
    public function trackShipment($order_id,$tracking_number){
        $token = $this->authentication();
        if(empty($token)){
            $this->result['error'][] = 'Invalid courier credentials';
            if($this->action == 'print_response'){
                $this->result['print_response'] = $this->print_response;
            }
            return $this->result;
        }
        $parentId = $this->courier_settings->courier?->parent_id;
       
        $orderService =new OrderService();
        $url = $this->api_url.'/v1/shipments/track/'.$tracking_number;
        $headers = [
            'Authorization' => 'Bearer '.$token,
            'Content-Type'  => 'application/json',
        ];
        $response = Http::withHeaders($headers)->get($url);
        $response = $response->json();
       
        $status = $response['status']??false;
        $scansdata = array();
        $message = $response['message']??[];
        $shipmentData = $response['data']??[];    
        if($status===true && !empty($shipmentData)){
            $scans = $shipmentData['history']??[];            
            if(!empty($scans)){
                $scansdata['courier_id'] = $this->courier_id;  
                $scansdata['tracking_number'] = $tracking_number;  
                $scansdata['origin'] = '';                
                $scansdata['destination'] = '';
                $scansdata['pickup_date'] =  ''; 
                $scansdata['expected_delivery_date'] = '';                
                $scansdata['pod'] = '';                          
                $scansdata['scans'] = []; 
                foreach($scans as $ScanDetail){
                    $trackingHistories = array();
                   
                    $trackingHistories['status'] = $ScanDetail['message']; 
                    $courier_status_mapping = CourierStatusMapping::where('courier_id', $parentId)
                    ->where('courier_status', $ScanDetail['status_code'])
                    ->first();
                    if (!$courier_status_mapping) {
                        $courier_status_mapping = CourierStatusMapping::create([
                                'courier_id' => $parentId,
                                'courier_status' => $ScanDetail['status_code'],
                                'shipment_status_code' => '',
                            ]
                        );
                    }
                    $shipment_status_code = ($courier_status_mapping->shipment_status_code)?$courier_status_mapping->shipment_status_code:$trackingHistories['status'];
                    $trackingHistories['current_status_code'] = $shipment_status_code;                     
                    $trackingHistories['date'] = $ScanDetail['event_time']??''; 
                    $trackingHistories['location'] = $ScanDetail['location']??''; 
                    $scansdata['scans'][] = $trackingHistories;
                    $scansdata['current_status_code'] = $trackingHistories['current_status_code'];
                    $scansdata['current_status_date'] = $trackingHistories['date']; 
                }                
            }
        }
        if($scansdata){
            $orderService->addShipmentTrackDetails($order_id,$scansdata);
        }
        return $scansdata;

    }
    public function cancelShipments(){
        $token = $this->authentication();
        if(empty($token)){
            $this->result['error'][] = 'Invalid courier credentials';
            if($this->action == 'print_response'){
                $this->result['print_response'] = $this->print_response;
            }
            return $this->result;
        }
        $url = $this->api_url.'/v1/shipments/cancel';
        if ($this->order_ids) {
            try {
                foreach ($this->order_ids as $orderId) {
                    try {
                        $shipmentInfo = ShipmentInfo::where('order_id', $orderId)->first();
                        if (!$shipmentInfo) {
                           // Log::warning("Shipment info not found for Order ID: $orderId");
                            $this->result['error'][] = "Shipment info not found for Order ID $orderId";
                            continue; 
                        }                         

                        $requestPayloads = [
                            "awb" => $shipmentInfo->tracking_id
                        ];
                        $response = Http::withHeaders([
                            'Authorization' => 'Bearer '.$token,
                            'Content-Type'  => 'application/json',
                        ])->post($url, $requestPayloads);
                        $response = $response->json();
                        $message = $response['message']??'';
                       
                        if($response['status']===true){
                            DB::transaction(function () use ($orderId) {
                            ShipmentInfo::where('order_id', $orderId)->delete();
                            OrderCourierResponse::where('order_id', $orderId)->delete();
                            Order::where('id', $orderId)->update([
                                    'status_code' => 'N'
                                ]);
                            });
                            $this->result['success'][] = $orderId .' is canceled successfully';
                        }else{                            
                            $this->result['error'][] = $message ." for Order ID $orderId";
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
    
}
