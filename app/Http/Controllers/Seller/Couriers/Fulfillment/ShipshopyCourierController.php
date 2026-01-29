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
use Carbon\Carbon;
class ShipshopyCourierController extends Controller
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
    public $parent_courier_id=13;
    public function __construct($order_ids = array() , $courier_id = 0 , $company_id = 0,$courier_settings=array()){
		
		$this->order_ids 	= $order_ids;
		$this->courier_id 	= $courier_id;
		$this->company_id 	= ($company_id) ? $company_id : session('company_id');
        $this->courier_settings = $courier_settings;
        $courier_details = ($courier_settings->courier_details)?json_decode($courier_settings['courier_details'],true):array();
        foreach($courier_details as $key=>$value){
            $this->$key = $value;
        }      
        $this->api_url = 'https://shipkloud.com';       
		
	}
	public function assignTrackingNumber()
    {
       foreach ($this->order_ids as $order_id) {
            $order_info = Order::with('orderProducts', 'orderTotals', 'shipmentInfo')->find($order_id)->toArray();

            if (isset($order_info['shipment_info']) && !empty($order_info['shipment_info'])) {
                $this->result['error'][$order_info['vendor_order_number']] = "Tracking number already assigned";
                continue;
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
                    "quantity" => $orderProduct['quantity'],
                    "unit_price" => $orderProduct['unit_price'],
                    "sku_number" => $orderProduct['sku'],
                    "hsn" => $orderProduct['hsn'],
                    "discount" => "",
                    "product_category" => "Other"
                ];

            }
            $package_dead_weight = $order_info['package_dead_weight']??0.05;
            $package_dead_weight = $package_dead_weight*1000;
            // Prepare package information
            $order_date = $order_info['channel_order_date'];
            $order_date = Carbon::parse($order_date)->format('Y-m-d');
            $packageInfo = [
                "order_id" => $order_info['vendor_order_number'] ?? $order_info['id'],
                "order_date" => $order_date,
                "order_type" => $this->shipment_mode,
                "consignee_name" => $order_info['b_fullname'] ?? $order_info['s_fullname'],
                "consignee_phone" => $consignee_phone,
                "consignee_alternate_phone" => "",
                "consignee_email" => $order_info['email'],
                "consignee_address_line_one" =>$order_info['b_complete_address'] ?? $order_info['s_complete_address'],
                "consignee_address_line_two" => $order_info['b_landmark'] ?? $order_info['s_landmark'],
                "consignee_pin_code" => $order_info['b_zipcode'] ?? $order_info['s_zipcode'],
                "consignee_city" => $order_info['s_city'] ?? $order_info['b_city'],
                "consignee_state" => $order_info['b_state_code'] ?? $order_info['s_state_code'],
                "product_detail" => $shipment_items,
                "payment_type" => $order_info['payment_mode'] === 'prepaid' ?'PREPAID':'COD',
                "cod_amount" =>$order_info['payment_mode'] != 'prepaid'?$order_info['order_total']:"",
                "length" => $order_info['package_length'] ?? 10,
                "width" => $order_info['package_breadth'] ?? 10,
                "height" => $order_info['package_height'] ?? 10,
                "weight" => $package_dead_weight,
                "warehouse_id" => $this->pickup_address->courier_warehouse_id,
                "gst_ewaybill_number" => "",
                "gstin_number" => ""
            ];
            if (ShipmentInfo::where('order_id', $order_id)->exists()) {
                $this->result['error'][$order_info['vendor_order_number']] = 'Tracking number already assigned';
                continue;
            }
            $apiUrl = $this->api_url.'/app/api/v1/push-order';
            $headers = [
                'public-key' => $this->api_public_key,
                'private-key' => $this->api_private_key,
                'Content-Type' => 'application/json'
            ];
            $response = Http::withHeaders($headers)->post($apiUrl,$packageInfo);
            $response = $response->json(); 
            $result = $response['result']??0;
            $responsedata = $response['data']??[];
            $shipment_id = $responsedata['order_id']??0;
            $message = $responsedata['error']??'';
            if($result==1){
                $assignUrl = $this->api_url.'/app/api/v1/assign-courier';
                $post_fields = [];
                $post_fields['order_id'] = $shipment_id;
                $post_fields['courier_id'] = $this->shipshopy_courier_id??"";
                $courier_resonse = Http::withHeaders($headers)->post($assignUrl,$post_fields);
                $courier_resonse = $courier_resonse->json(); 
                $courier_resonse_result = $courier_resonse['result']??0;
                $message = $courier_resonse['message']??$message;
                $courier_data = $courier_resonse['data']??[];
                if($courier_resonse_result==1){
                    $trackingNumber = $courier_data['awb_number']??'';
                    $courier_name = $responsedata['courier']??'';
                    $pickup_address = $this->pickupAddressFormat($this->pickup_address);
                    $return_address = $this->pickupAddressFormat($this->return_address);
                    if ($trackingNumber) {             
                        $other_details = array();               
                        $other_details['shipment_id'] = $shipment_id;
                        ShipmentInfo::create([
                            'order_id' => $order_id,
                            'company_id' => $this->company_id,
                            'shipment_type' => "",
                            'courier_id' => $this->courier_id,
                            'applied_weight' => $order_info['package_dead_weight'],
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
                            'courier_code' => 'shipshopy',
                            'courier_name' => $courier_name,
                            'response' => $other_details,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        Order::where('id', $order_id)->update(['status_code' => 'P']);
                        $this->result['success'] = true;
                    } 
                }else{
                    $this->result['error'][$order_info['vendor_order_number']] = $message?$message:"Something went wrong";  
                }
                if($this->action == 'print_response'){
                    $this->print_response['assign']['url'] = $assignUrl;
                    $this->print_response['assign']['header']=$headers;
                    $this->print_response['assign']['request_data']=json_encode($post_fields);
                    $this->print_response['assign']['response_data'] = $courier_resonse;
                    $this->result['print_response'] = $this->print_response;
                    return $this->result;
                }
            }else{                
                $this->result['error'][$order_info['vendor_order_number']] = $message?$message:"Something went wrong";  
                if($this->action == 'print_response'){
                    $this->print_response['assign']['url'] = $apiUrl;
                    $this->print_response['assign']['header']=$headers;
                    $this->print_response['assign']['request_data']=json_encode($packageInfo);
                    $this->print_response['assign']['response_data'] = $response;
                    $this->result['print_response'] = $this->print_response;
                    return $this->result;
                }
            } 
        }
        return $this->result;
    }
    public function trackShipment($order_id,$tracking_number){
        $parentId = $this->courier_settings->courier?->parent_id;
        $orderService =new OrderService();
        $url = $this->api_url.'/app/api/v1/track-order?awb_number='.$tracking_number;
        $headers = [
            'public-key' => $this->api_public_key,
            'private-key' => $this->api_private_key,
            'Content-Type' => 'application/json'
        ];
        $response = Http::withHeaders($headers)->get($url);
        if (!$response->ok()) {
            $res['error'][] = "HTTP error for Order ID $order_id: " . $response->status();
            return $res;
        }
        $response = $response->json();
        $status = $response['result']??0;
        $scansdata = array();
        $shipment_track = $response['data']??[];
        $message = $response['message']??'';
        if($status==1 && $shipment_track){            
            $scansdata['courier_id'] = $this->courier_id;  
            $scansdata['tracking_number'] = $tracking_number;  
            $scansdata['origin'] = '';                 
            $scansdata['destination'] = '';   
            $scansdata['pickup_date'] = ''; 
            $scansdata['expected_delivery_date'] = !empty($shipment_track['expected_delivery_date'])?$shipment_track['expected_delivery_date']:'';                
            $scansdata['pod'] ='';  
            $current_status = $shipment_track['current_status']?:'';
            if(strtolower($current_status)=='pickup pending'){
                $current_status = $shipment_track['order_status']??'';
            }
            $shipment_status_code = '';
            if($current_status){
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
                $shipment_status_code = $courier_status_mapping->shipment_status_code?$courier_status_mapping->shipment_status_code:$shipment_track['current_status'];
            }
            $scansdata['current_status_code'] = $shipment_status_code;
            $current_status_date = $shipment_track['status_time']??'';
            $scansdata['current_status_date'] = $current_status_date;               
            $scansdata['scans'] = [];                
            $scans = $shipmentData['scan_detail']??[];
            foreach($scans as $ScanDetail){
                $trackingHistories = array();
                $trackingHistories['status'] = $ScanDetail['status']; 
                $courier_status_mapping = CourierStatusMapping::where('courier_id', $parentId)
                ->where('courier_status', $ScanDetail['status'])
                ->first();
                if (!$courier_status_mapping) {
                    $courier_status_mapping = CourierStatusMapping::create([
                            'courier_id' => $parentId,
                            'courier_status' => $ScanDetail['status'],
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
        }
        if($scansdata){
            $orderService->addShipmentTrackDetails($order_id,$scansdata);
        }
        return $scansdata;

    }
    public function cancelShipments() {
        $this->result = ['success' => false, 'error' => [], 'message' => ''];

        if (!$this->order_ids || count($this->order_ids) == 0) {
            $this->result['error'][] = 'No order IDs found.';
            return response()->json($this->result);
        }
        $orders = Order::with('shipmentInfo')
            ->whereIn('id', $this->order_ids)
            ->get();

        foreach ($orders as $order) {
            $shipment = $order->shipmentInfo;

            if (!$shipment || !$shipment->tracking_id) {
                $this->result['error'][] = "Tracking ID not found for Order ID {$order->id}";
                continue;
            }
            $url = $this->api_url . '/app/api/v1/cancel-order';
            try {
                $payload = [
                    "order_id"    => $order->vendor_order_number,
                    "awb_number" => $shipment->tracking_id
                ];
                $headers = [
                    'public-key' => $this->api_public_key,
                    'private-key' => $this->api_private_key,
                    'Content-Type' => 'application/json'
                ];
                $response = Http::withHeaders($headers)->post($url, $payload);
                if (!$response->ok()) {
                    $this->result['error'][] = "HTTP error for Order ID $orderId: " . $response->status();
                    continue;
                }

                $responseData = $response->json();
                $status  = $responseData['result'] ?? 0;
                $message = $responseData['message'] ?? 'Unknown API response';
                if ($status==1) {
                    DB::transaction(function () use ($order) {
                        ShipmentInfo::where('order_id', $order->id)->delete();
                        OrderCourierResponse::where('order_id', $order->id)->delete();
                        Order::where('id', $order->id)->update(['status_code' => 'N']);
                    });
                    $this->result['success'][] = "Order ID $order->vendor_order_number canceled successfully.";
                } else {
                    $this->result['error'][] = "Order ID $order->vendor_order_number: $message";
                }

            } catch (\Exception $e) {
                $this->result['error'][] = "API error for Order ID {$order->vendor_order_number}: " . $e->getMessage();
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