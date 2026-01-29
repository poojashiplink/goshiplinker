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
class ShiprocketCourierController extends Controller
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
    public $parent_courier_id=8;
    public function __construct($order_ids = array() , $courier_id = 0 , $company_id = 0,$courier_settings=array()){
		
		$this->order_ids 	= $order_ids;
		$this->courier_id 	= $courier_id;
		$this->company_id 	= ($company_id) ? $company_id : session('company_id');
        $this->courier_settings = $courier_settings;
        $courier_details = ($courier_settings->courier_details)?json_decode($courier_settings['courier_details'],true):array();
        foreach($courier_details as $key=>$value){
            $this->$key = $value;
        }      
        $this->api_url = 'https://apiv2.shiprocket.in';
       
		
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
            $giftwrap_charges = 0;
            $shipping_charges = 0;
            $discount = 0;
            if(!empty($order_info['order_totals'])){
                foreach($order_info['order_totals'] as $order_totals){
                    if($order_totals['code']=='giftwrap'){
                        $giftwrap_charges = $order_totals['value'];
                    }
                    if($order_totals['code']=='shipping'){
                        $shipping_charges = $order_totals['value'];
                    }
                    if($order_totals['code']=='discount'){
                        $discount = $order_totals['value'];
                    }

                }

            }
            $order_info['payment_mode'] = strtolower($order_info['payment_mode']); 
            $consignee_phone = isset($order_info['s_phone']) ? $order_info['s_phone'] : $order_info['b_phone'];
            $consignee_phone = preg_replace('/[^0-9]/', '', $consignee_phone);
            $consignee_phone = substr($consignee_phone, -10);
            $orderProducts = $order_info['order_products'];
            $shipment_items = [];
            foreach($orderProducts as $key=>$orderProduct){
                $shipment_items[] = [
                    "name" => $orderProduct['product_name'],
                    "units" => $orderProduct['quantity'],
                    "selling_price" => $orderProduct['unit_price'],
                    "sku" => $orderProduct['sku'],
                    "hsn" => $orderProduct['hsn']?:''
                ];

            }
            // Prepare package information
            $order_date = $order_info['channel_order_date'];
            $packageInfo =  [
                "mode" => $this->shipment_mode,
                "request_pickup" => true,
                "print_label" => true,
                "generate_manifest" => true,
                "courier_id"=>$this->shiprocket_courier_id??"",
                "order_id" => $order_info['vendor_order_number'] ?? $order_info['id'],
                "order_date" => $order_date,
                "billing_customer_name" => $order_info['b_fullname'] ?: $order_info['s_fullname'],
                "billing_last_name" => "",
                "billing_address" => $order_info['b_complete_address'] ?: $order_info['s_complete_address'],
                "billing_city" => $order_info['b_city'] ?: $order_info['s_city'],
                "billing_pincode" => $order_info['b_zipcode'] ?: $order_info['s_zipcode'],
                "billing_state" => $order_info['b_state_code'] ?: $order_info['s_state_code'],
                "billing_country" => $order_info['b_country_code'] ?: $order_info['s_country_code'],
                "billing_email" => $order_info['email'],
                "billing_phone" => $consignee_phone,
                "shipping_customer_name" => $order_info['s_fullname'],
                "shipping_last_name" => "",
                "shipping_address" => $order_info['s_complete_address'] ?: $order_info['b_complete_address'],
                "shipping_city" => $order_info['s_city'] ?: $order_info['b_city'],
                "shipping_pincode" => $order_info['s_zipcode'] ?: $order_info['b_zipcode'],
                "shipping_state" => $order_info['s_state_code'] ?: $order_info['b_state_code'],
                "shipping_country" => $order_info['s_country_code'] ?: $order_info['b_country_code'],
                "shipping_email" => $order_info['email'],
                "shipping_phone" => $consignee_phone,
                "shipping_is_billing" => true,
                "order_items" => $shipment_items,
                "payment_method" => $order_info['payment_mode'] === 'prepaid' ?'Prepaid':'COD',
                "sub_total" => $order_info['sub_total'],
                "length" => $order_info['package_length'] ?? 10,
                "breadth" => $order_info['package_breadth'] ?? 10,
                "height" => $order_info['package_height'] ?? 10,
                "weight" => $order_info['package_dead_weight'] ?? 0.05,
                "order_type" => "",
                "shipping_charges" => $shipping_charges,
                "giftwrap_charges" => $giftwrap_charges,
                "total_discount" => $discount,
                "pickup_location" => $this->pickup_address->location_title,
                // "vendor_details" => [
                //     "email" => $this->pickup_address->email,
                //     "phone" => $this->pickup_address->phone,
                //     "name" => $this->pickup_address->brand_name,
                //     "address" => $this->pickup_address->address,
                //     "address_2" => $this->pickup_address->landmark,
                //     "city" => $this->pickup_address->city,
                //     "state" =>$this->pickup_address->state_code,
                //     "country" =>$this->pickup_address->country_code,
                //     "pin_code" => $this->pickup_address->zipcode,
                //     "pickup_location" => $this->pickup_address->location_title,
                // ]
            ];
            if (ShipmentInfo::where('order_id', $order_id)->exists()) {
                $this->result['error'][$order_info['vendor_order_number']] = 'Tracking number already assigned';
                continue;
            }   
            $apiUrl = $this->api_url.'/v1/external/shipments/create/forward-shipment';
            $headers = [
                'Authorization' => 'Bearer '.$token,
                'Content-Type' => 'application/json'
            ];
            $response = Http::withHeaders($headers)->post($apiUrl,$packageInfo);
            $response = $response->json(); 
            $status_code = $response['status_code']??'';
            $status = $response['status']??0;
            $message = $response['message']??'';
            $responsedata = $response['payload']??[];
            $trackingNumber = $responsedata['awb_code']??'';
            $awb_generated = $responsedata['awb_generated']??0;
            $pickup_address = $this->pickupAddressFormat($this->pickup_address);
            $return_address = $this->pickupAddressFormat($this->return_address);
            $pickup_token_number = $responsedata['pickup_token_number']??'';
            $pickup_token_number = ($pickup_token_number)?trim(str_replace('Reference No:','',$pickup_token_number)):'';
            $courier_name = $responsedata['courier_name']??'';
            $routing_code = $responsedata['routing_code']??'';
            $manifest_url = $responsedata['manifest_url']??'';
            $label_url = $responsedata['label_url']??'';
            $shipment_id = $responsedata['shipment_id']??'';
            if ($status_code=='' && $status==1 && $awb_generated) {               
                $other_details = array();               
                $other_details['shipment_id'] = $shipment_id;
                $other_details['label_url'] = $label_url;
                $other_details['manifest_url'] = $manifest_url;   
                $other_details['pickup_token'] = $pickup_token_number;
                $other_details['destination_area'] = $routing_code;
                ShipmentInfo::create([
                    'order_id' => $order_id,
                    'company_id' => $this->company_id,
                    'shipment_type' => $this->shipment_mode,
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
                    'courier_code' => 'shiprocket',
                    'courier_name' => $courier_name,
                    'response' => $other_details,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                Order::where('id', $order_id)->update(['status_code' => 'P']);
                $this->result['success'] = true;
            } else {
                if($status_code == '' && $status == 0 && $trackingNumber != ''){  
                    $message = $responsedata['error_message']??'';     
                    $other_details = array();                
                    $other_details['shipment_id'] = $shipment_id;  
                    $other_details['destination_area'] = $routing_code;
                    ShipmentInfo::create([
                        'order_id' => $order_id,
                        'company_id' => $this->company_id,
                        'shipment_type' => $this->shipment_mode,
                        'courier_id' => $this->courier_id,
                        'tracking_id' => $trackingNumber,
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
                        'courier_code' => 'shiprocket',
                        'courier_name' => $courier_name,
                        'response' => $other_details,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    Order::where('id', $order_id)->update(['status_code' => 'P']);
                    $this->result['success'] = true;

                }
                
                $this->result['error'][$order_info['vendor_order_number']] = "Failed to assigned tracking number"." Error: $message";
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
        $token = Cache::get("api_auth_token_shiprocket_{$this->courier_id}_{$company_id}");        
        $apiUrl = $this->api_url.'/v1/external/auth/login';       
        
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
            
            $token = $responseData['token'];
            
            if($token){
                Cache::put("api_auth_token_shiprocket_{$this->courier_id}_{$company_id}", $token, now()->addMinutes(30));
            }
            if($this->action == 'print_response'){
                $this->print_response['authentication']['auth_url'] = $apiUrl;
				$this->print_response['authentication']['body']=json_encode($postData);
                $this->print_response['authentication']['auth_response'] = $response;
                if (empty($token)) {
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
            return $this->result;
        }
        $parentId = $this->courier_settings->courier?->parent_id;
        //return $parentId;
        $orderService =new OrderService();
        $url = $this->api_url.'/v1/external/courier/track/awb/'.$tracking_number;
        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
        ];
        $response = Http::withHeaders($headers)->get($url);
        $response = $response->json();
        $status_code = $response['status_code']??'';
        $scansdata = array();
        $shipmentData = $response['tracking_data']??[];
        $message = $response['message']??'';
      //return $shipmentData;
        if(empty($status_code) && $shipmentData){
            $shipment_track = $shipmentData['shipment_track'][0]??[];
            $error = $shipment_track['error']??'';
            $shipment_status_id = $shipmentData['shipment_status']??0;
            if(empty($error)){
                $scansdata['courier_id'] = $this->courier_id;  
                $scansdata['tracking_number'] = $tracking_number;  
                $scansdata['origin'] = $shipment_track['origin']??'';                 
                $scansdata['destination'] = $shipment_track['destination']??'';   
                $scansdata['pickup_date'] = $shipment_track['pickup_date']; 
                $scansdata['expected_delivery_date'] = !empty($shipment_track['edd'])?$shipment_track['edd']:'';                
                $scansdata['pod'] = ($shipment_status_id==7 && !empty($shipmentDetail['pod_status']))?$shipmentDetail['pod_status']:'';  
                $courier_status_mapping = CourierStatusMapping::where('courier_id', $parentId)
                    ->where('courier_status', $shipment_track['current_status'])
                    ->first();
                if (!$courier_status_mapping) {
                    $courier_status_mapping = CourierStatusMapping::create([
                            'courier_id' => $parentId,
                            'courier_status' => $shipment_track['current_status'],
                            'shipment_status_code' => '',
                        ]
                    );
                }
                $shipment_status_code = $courier_status_mapping->shipment_status_code?$courier_status_mapping->shipment_status_code:$shipment_track['current_status'];
                $scansdata['current_status_code'] = $shipment_status_code;
                $scansdata['current_status_date'] = $shipment_track['updated_time_stamp'];               
                $scansdata['scans'] = [];                
                $scans = $shipmentData['shipment_track_activities']??[];
                //return $shipmentDetails;
                foreach($scans as $ScanDetail){
                    $trackingHistories = array();
                    $trackingHistories['status'] = $ScanDetail['activity']; 
                    $courier_status_mapping = CourierStatusMapping::where('courier_id', $parentId)
                    ->where('courier_status', $ScanDetail['activity'])
                    ->first();
                    if (!$courier_status_mapping) {
                        $courier_status_mapping = CourierStatusMapping::create([
                                'courier_id' => $parentId,
                                'courier_status' => $ScanDetail['activity'],
                                'shipment_status_code' => '',
                            ]
                        );
                    }
                    $shipment_status_code = $courier_status_mapping->shipment_status_code??$trackingHistories['status'];
                    $trackingHistories['current_status_code'] = $shipment_status_code;                     
                    $trackingHistories['date'] =$ScanDetail['date']; 
                    $trackingHistories['location'] = $ScanDetail['location']; 
                    $scansdata['scans'][] = $trackingHistories;                    
                }  

            } else{
                $message = $error;
            }                    
            
        }
        if($scansdata){
            $orderService->addShipmentTrackDetails($order_id,$scansdata);
        }
        return $scansdata;

    }
    public function cancelShipments()
    {
        $token = $this->authentication();
        if(empty($token)){
            $this->result['error'][] = 'Invalid courier credentials';            
            return $this->result;
        }
        $url = $this->api_url . '/v1/external/orders/cancel/shipment/awbs';

        if (!$this->order_ids || empty($this->order_ids)) {
            $this->result['error'][] = "No order IDs provided for cancellation.";
            return $this->result;
        }

        try {
            // 1. Fetch all shipment records in one query
            $shipments = ShipmentInfo::whereIn('order_id', $this->order_ids)->get();

            // Build AWB list and map: tracking_id => order_id
            $awbList  = $shipments->pluck('tracking_id')->toArray();
            $orderMap = $shipments->pluck('order_id', 'tracking_id')->toArray();

            // Find missing orders
            $foundOrderIds = $shipments->pluck('order_id')->toArray();
            $missingOrders = array_diff($this->order_ids, $foundOrderIds);

            foreach ($missingOrders as $missingOrderId) {
                $this->result['error'][] = "Shipment info not found for Order ID $missingOrderId";
            }

            // If no AWBs found, stop here
            if (empty($awbList)) {
                $this->result['error'][] = "No valid shipments found to cancel.";
                return $this->result;
            }

            // 2. Send bulk cancellation request
            $response = Http::withHeaders([
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ])->post($url, [
                "awbs" => $awbList
            ]);
            $response = $response->json();
            $status_code = $response['status_code']??'';
            $message = $response['message']??'';
            // 3. Handle response
            if ($status_code=='' && ($message == 'Bulk Shipment cancellation is in progress. Please wait for some time.' || $message == 'Shipment(s) cancellation is in progress. Please wait for some time.')){
                // Bulk success → update all related orders
                DB::transaction(function () use ($orderMap) {
                    foreach ($orderMap as $awb => $orderId) {
                        ShipmentInfo::where('order_id', $orderId)->delete();
                        OrderCourierResponse::where('order_id', $orderId)->delete();
                        Order::where('id', $orderId)->update([
                            'status_code' => 'N'
                        ]);
                        $this->result['success'][] = "$orderId canceled successfully";
                    }
                });
            }else {
                $this->result['error'][] = $message ?$message: "Unknown API error";
            }
        } catch (\Exception $e) {
            $this->result['error'][] = $e->getMessage();
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