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
class ShipwayCourierController extends Controller
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
    public $parent_courier_id=9;
    public function __construct($order_ids = array() , $courier_id = 0 , $company_id = 0,$courier_settings=array()){
		
		$this->order_ids 	= $order_ids;
		$this->courier_id 	= $courier_id;
		$this->company_id 	= ($company_id) ? $company_id : session('company_id');
        $this->courier_settings = $courier_settings;
        $courier_details = ($courier_settings->courier_details)?json_decode($courier_settings['courier_details'],true):array();
        foreach($courier_details as $key=>$value){
            $this->$key = $value;
        }  
        $this->api_url = 'https://app.shipway.com';        
		
	}
	public function assignTrackingNumber()
    {
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
                    "product" => $orderProduct['product_name'],
                    "product_quantity" => $orderProduct['quantity'],
                    "price" => $orderProduct['unit_price'],
                    "product_code" => $orderProduct['sku'],
                    "discount" => 0,
                    "tax_rate" => 0,
                    "tax_title" => ""
                ];
            }
            // Prepare package information
            $package_dead_weight = $order_info['package_dead_weight'] ?? 0.05;
            $package_dead_weight = $package_dead_weight*1000;           

            $packageInfo = [
                "order_id"            => $order_info['vendor_order_number'] ?? $order_info['id'],
                "carrier_id"          => $this->shipway_courier_id??"",
                "warehouse_id"        => $this->pickup_address->courier_warehouse_id??0,
                "return_warehouse_id" => $this->return_address->courier_warehouse_id??0,
                "ewaybill"            => "",
                "products"            =>$shipment_items,
                "discount"          => $discount,
                "shipping"          => $shipping_charges,
                "order_total"       => $order_info['order_total'],
                "gift_card_amt"     => "0",
                "taxes"             => $taxes,
                "payment_type"      => $payment_mode === 'prepaid' ?'P':'C',
                "email"             => $order_info['email'],
                "billing_address"   => $order_info['b_complete_address'] ?? $order_info['s_complete_address'],
                "billing_address2"  => $order_info['b_landmark'] ?? $order_info['s_landmark'],
                "billing_city"      => $order_info['b_city'] ?? $order_info['s_city'],
                "billing_state"     => $order_info['b_state_code'] ?? $order_info['s_state_code'],
                "billing_country"   => $order_info['b_country_code'] ?? $order_info['s_country_code'],
                "billing_firstname" => $order_info['b_fullname'],
                "billing_lastname"  => "",
                "billing_phone"     => $consignee_phone,
                "billing_zipcode"   => $order_info['b_zipcode'] ?? $order_info['s_zipcode'],
                "billing_latitude"  => "",
                "billing_longitude" => "",
                "shipping_address"  => $order_info['s_complete_address'] ?? $order_info['b_complete_address'],
                "shipping_address2" => $order_info['s_landmark'] ?? $order_info['b_landmark'],
                "shipping_city"     => $order_info['s_city'] ?? $order_info['s_city'],
                "shipping_state"    => $order_info['s_state_code'] ?? $order_info['b_state_code'],
                "shipping_country"  => $order_info['s_country_code'] ?? $order_info['sbcountry_code'],
                "shipping_firstname"=> $order_info['s_fullname'],
                "shipping_lastname" => "",
                "shipping_phone"    => $consignee_phone,
                "shipping_zipcode"  => $order_info['s_zipcode'] ?? $order_info['b_zipcode'],
                "shipping_latitude" => "",
                "shipping_longitude"=> "",
                "order_weight"      => $package_dead_weight,
                "box_length"        => $order_info['package_length'] ?? 10,
                "box_breadth"       => $order_info['package_breadth'] ?? 10,
                "box_height"        => $order_info['package_height'] ?? 10,
                "order_date"        => date('Y-m-d H:i:s',strtotime($order_info['channel_order_date']))
            ];
            if (ShipmentInfo::where('order_id', $order_id)->exists()) {
                $this->result['error'][$order_info['vendor_order_number']] = 'Tracking number already assigned';
                continue;
            }   
            $apiUrl = $this->api_url.'/api/v2orders';
            $headers = [
                'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password),
                'Content-Type'  => 'application/json',
            ];

            $response = Http::withHeaders($headers)->post($apiUrl,$packageInfo);
            $response = $response->json(); 
            $pickup_address = $this->pickupAddressFormat($this->pickup_address);
            $return_address = $this->pickupAddressFormat($this->return_address);
            $status = $response['success']??false;
            $message = $response['message']??'';
            $responsedata = $response['awb_response']??'';
            if(!empty($responsedata) && !is_array($responsedata)){
                $message = $responsedata;
            }
            if ($status===true) {  
                if($responsedata['success']===true){
                    $other_details = array();
                    $trackingNumber = $responsedata['AWB']??'';
                    $other_details['courier_id'] = $responsedata['carrier_id']??'';
                    $other_details['label_url'] = $responsedata['shipping_url']??'';
                    $courier_name = $responsedata['carrier_name']??'';             
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
                        'courier_code' => 'shipway',
                        'courier_name' => $courier_name,
                        'response' => $other_details,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    Order::where('id', $order_id)->update(['status_code' => 'P']);
                    $this->result['success'] = true;

                }else{
                    $error = $message;
                    $this->result['error'][$order_info['vendor_order_number']] = "Failed to assigned tracking number"." Error: $error";
                }   
                
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
    
    public function trackShipment($order_id,$tracking_number){
        $order_info = Order::find($order_id);
        $vendor_order_number = $order_info->vendor_order_number??0;
        $parentId = $this->courier_settings->courier?->parent_id;
       
        $orderService =new OrderService();
        $url = $this->api_url.'/api/getorders?orderid='.$vendor_order_number;
        $headers = [
            'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password),
            'Content-Type'  => 'application/json',
        ];
        $response = Http::withHeaders($headers)->get($url);
        $response = $response->json();
       
        $status = $response['success']??0;
        $scansdata = array();
        $shipmentData = $response['message']??[];
        $message = (!empty($shipmentData) && !is_array($shipmentData))?$shipmentData:'';     
        if($status==1 && !empty($shipmentData) && is_array($shipmentData)){
            $shipmentData = $shipmentData[0]??[];            
            if(!empty($shipmentData)){
                $scansdata['courier_id'] = $this->courier_id;  
                $scansdata['tracking_number'] = $tracking_number;  
                $scansdata['origin'] = $shipmentData['pickup_address']['city']??'';                
                $scansdata['destination'] = $shipmentData['s_city']??'';
                $scansdata['pickup_date'] =  ''; 
                $scansdata['expected_delivery_date'] = '';                
                $scansdata['pod'] = '';                          
                $scansdata['scans'] = [];                
                $scans = $shipmentData['shipment_status_scan']??[];
                if($scans){
                    foreach($scans as $ScanDetail){
                        $trackingHistories = array();
                        if($ScanDetail['sub_status']=='Picked Up'){
                            $scansdata['pickup_date'] = $ScanDetail['datetime'];
                        }
                        $trackingHistories['status'] = $ScanDetail['status']; 
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
                        $shipment_status_code = ($courier_status_mapping->shipment_status_code)?$courier_status_mapping->shipment_status_code:$trackingHistories['status'];
                        $trackingHistories['current_status_code'] = $shipment_status_code;                     
                        $trackingHistories['date'] =$ScanDetail['datetime']??''; 
                        $trackingHistories['location'] = ''; 
                        $scansdata['scans'][] = $trackingHistories;
                        $scansdata['current_status_code'] = $trackingHistories['current_status_code'];
                        $scansdata['current_status_date'] = $trackingHistories['date']; 
                    }
                }
            }
        }
        if($scansdata){
            $orderService->addShipmentTrackDetails($order_id,$scansdata);
        }
        return $scansdata;

    }
    public function cancelShipments(){
        $url = $this->api_url.'/api/Cancel/';
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
                            "awb_number" => [$shipmentInfo->tracking_id]
                        ];
                        $response = Http::withHeaders([
                            'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password),
                            'Content-Type'  => 'application/json',
                        ])->post($url, $requestPayloads);
                        $response = $response->json();
                        $message = $response['message']??'';
                       
                        if($response['success']===true){
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
