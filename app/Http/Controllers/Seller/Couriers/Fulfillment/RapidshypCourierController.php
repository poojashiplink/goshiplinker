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
class RapidshypCourierController extends Controller
{
    private $order_ids 		= array();
	private $courier_id 		= 0;
	private $company_id 		= 0;
    private $courier_settings 	= array();
    private $api_mode = '';
    private $api_key ='';
    private $env_type='';
    private $api_url='';
    public $pickup_address = array();
    public $return_address = array();
    public $errors = array();
    public $result=array();
    public $action="";
    public $print_response=array();
    public $shipment_mode='';
    public $parent_courier_id=12;
    public function __construct($order_ids = array() , $courier_id = 0 , $company_id = 0,$courier_settings=array()){
		
		$this->order_ids 	= $order_ids;
		$this->courier_id 	= $courier_id;
		$this->company_id 	= ($company_id) ? $company_id : session('company_id');
        $this->courier_settings = $courier_settings;
        $courier_details = ($courier_settings->courier_details)?json_decode($courier_settings['courier_details'],true):array();
        foreach($courier_details as $key=>$value){
            $this->$key = $value;
        }      
        $this->api_url = 'https://api.rapidshyp.com';
       
		
	}
	public function assignTrackingNumber()
    {
        foreach ($this->order_ids as $order_id) {
            $order_info = Order::with('orderProducts', 'orderTotals', 'shipmentInfo')->find($order_id)->toArray();

            if (isset($order_info['shipment_info']) && !empty($order_info['shipment_info'])) {
                $this->result['error'][$order_info['vendor_order_number']] = "Tracking number already assigned";
                continue;
            }
            $giftwrap_charges = 0;
            $shipping_charges = 0;
            $discount = 0;
            $cod_charges = 0;
            $advanced_paid = 0;
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
                    if($order_totals['code']=='cod_charges'){
                        $cod_charges = $order_totals['value'];
                    }
                    if($order_totals['code']=='advanced'){
                        $advanced_paid = $order_totals['value'];
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
                    "itemName" => $orderProduct['product_name'],
                    "description"=>"",
                    "units" => $orderProduct['quantity'],
                    "sellingPrice" => $orderProduct['unit_price'],
                    "sku" => $orderProduct['sku'],
                    "hsn" => $orderProduct['hsn'],
                   // "tax"=>0,
                ];
            }
            $package_dead_weight = $order_info['package_dead_weight']??0.05;
            $package_dead_weight = $package_dead_weight*1000;
            
            // Prepare package information
            $order_date = $order_info['channel_order_date'];
            $order_date = Carbon::parse($order_date)->format('Y-m-d');
            $packageInfo = [
                "orderId" => $order_info['vendor_order_number'] ?? $order_info['id'],
                "orderDate" => $order_date,
                "pickupAddressName" => $this->pickup_address->location_title,
                "pickupLocation" => [
                    "contactName" => $this->pickup_address->location_title,
                    "pickupName" => $this->pickup_address->brand_name,
                    "pickupEmail" => $this->pickup_address->email,
                    "pickupPhone" => (string)$this->pickup_address->phone,
                    "pickupAddress1" => $this->pickup_address->address,
                    "pickupAddress2" => $this->pickup_address->landmark,
                    "pinCode" => (string)$this->pickup_address->zipcode
                ],
                "storeName" => "DEFAULT",
                "billingIsShipping" => true,
                "shippingAddress" => [
                    "firstName" => $order_info['s_fullname'] ?: $order_info['b_fullname'],
                    "lastName" => "",
                    "addressLine1" => $order_info['s_complete_address'] ?: $order_info['b_complete_address'],
                    "addressLine2" => $order_info['s_landmark'] ?: $order_info['b_landmark'],
                    "pinCode" =>(string)($order_info['s_zipcode'] ?: $order_info['b_zipcode']),
                    "email" => $order_info['email'],
                    "phone" => $consignee_phone
                ],
                "billingAddress" => [
                    "firstName" => $order_info['b_fullname'] ?: $order_info['s_fullname'],
                    "lastName" => "",
                    "addressLine1" => $order_info['b_complete_address'] ?: $order_info['s_complete_address'],
                    "addressLine2" => $order_info['b_landmark'] ?: $order_info['s_landmark'],
                    "pinCode" => (string)($order_info['b_zipcode'] ?: $order_info['s_zipcode']),
                    "email" => $order_info['email'],
                    "phone" => $consignee_phone
                ],
                "orderItems" => $shipment_items,
                "paymentMethod" =>$order_info['payment_mode'] === 'prepaid' ?'PREPAID':'COD',
                "shippingCharges" => (float)$shipping_charges,
                "giftWrapCharges" => (float)$giftwrap_charges,
                "transactionCharges" => 0.0,
                "totalDiscount" =>  (float)$discount,
                "totalOrderValue" =>  (float)$order_info['order_total'],
                "codCharges" =>  (float)$cod_charges,
                "prepaidAmount" => ($order_info['financial_status']=='partially_paid')?$advanced_paid:0.0,
                "packageDetails" => [
                    "packageLength" => $order_info['package_length'] ?? 10,
                    "packageBreadth" => $order_info['package_breadth'] ?? 10,
                    "packageHeight" => $order_info['package_height'] ?? 10,
                    "packageWeight" => $package_dead_weight,
                ]
            ];
                  
            if (ShipmentInfo::where('order_id', $order_id)->exists()) {
                $this->result['error'][$order_info['vendor_order_number']] = 'Tracking number already assigned';
                continue;
            }
            $apiUrl = $this->api_url.'/rapidshyp/apis/v1/wrapper';
            $headers = [
                'rapidshyp-token' => $this->api_key,
                'Content-Type' => 'application/json'
            ];
            $response = Http::withHeaders($headers)->post($apiUrl,$packageInfo);
            $response = $response->json(); 
          
            $status = $response['status']??"";
            $message = $response['remarks']??'';
            $errors = $response['errors']??[];
            $errorresponse = '';
            if($errors){
                $errorresponse = '';
                foreach($errors as $error){
                    $errorresponse .= $error.', ';
                }
                $errorresponse = rtrim($errorresponse, ', ');
            }
           
            $responsedata = $response['shipment'][0]??[];
            $trackingNumber = $responsedata['awb']??'';
            $awb_generated = $responsedata['awbGenerated']??0;
            $pickup_address = $this->pickupAddressFormat($this->pickup_address);
            $return_address = $this->pickupAddressFormat($this->return_address);
            $courier_name = $responsedata['courierName']??'';
            $routing_code = $responsedata['routingCode']??'';
            $rto_routing_code = $responsedata['rtoRoutingCode']??'';
            $manifest_url = $responsedata['manifestURL']??'';
            $label_url = $responsedata['labelURL']??'';
            $shipment_id = $responsedata['shipmentId']??'';
            if ($status=='SUCCESS' && $awb_generated) {               
                $other_details = array();               
                $other_details['shipment_id'] = $shipment_id;
                $other_details['order_id'] =$response['orderId']??'';
                $other_details['label_url'] = $label_url;
                $other_details['manifest_url'] = $manifest_url;   
                $other_details['destination_area'] = $routing_code;
                $other_details['destination_location'] = $rto_routing_code;
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
                    'courier_code' => 'rapidshyp',
                    'courier_name' => $courier_name,
                    'response' => $other_details,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                Order::where('id', $order_id)->update(['status_code' => 'P']);
                $this->result['success'] = true;
            } else {                
                $this->result['error'][$order_info['vendor_order_number']] = "Failed to assign tracking number. Error: " . ($errorresponse ? $errorresponse : $message);

            }
           if($this->action == 'print_response'){
                 $this->print_response['assign']['url'] = $apiUrl;
                $this->print_response['assign']['header']=$headers;
                $this->print_response['assign']['request_data']=$packageInfo;
                $this->print_response['assign']['response_data'] = $response;
                $this->result['print_response'] = $this->print_response;
                return $this->result;
            }
        }

        return $this->result;
    }
    
    public function trackShipment($order_id, $tracking_number)
    {
        $parentId = $this->courier_settings->courier?->parent_id;
        $orderService = new OrderService();

        $url = $this->api_url . '/rapidshyp/apis/v1/track_order';
        $headers = [
            'rapidshyp-token' => $this->api_key,
            'Content-Type'   => 'application/json',
        ];

        try {
            $order = Order::with('shipmentInfo')
            ->where('id', $order_id)
            ->first();
            $response = Http::withHeaders($headers)
                ->timeout(30)
                ->post($url, [
                    "seller_order_id" => $order->vendor_order_number,
                    "contact"         => "",
                    "email"           => "",
                    "awb"             => $tracking_number,
                ]);

            if (!$response->ok()) {
                return ['error' => "HTTP request failed with status: {$response->status()}"];
            }

            $data = $response->json();
            $status = $data['success'] ?? false;
            $shipmentData = $data['records'][0] ?? [];
            $message = $data['msg'] ?? 'Unknown response';

            if (!$status || empty($shipmentData)) {
                return ['error' => $message ?: 'Shipment tracking data not found'];
            }

            $shipmentDetails = $shipmentData['shipment_details'][0] ?? [];
            if (!$shipmentDetails) {
                return ['error' => 'Shipment details missing in response'];
            }

            // Build tracking data
            $scansdata = [
                'courier_id'             => $this->courier_id,
                'tracking_number'        => $tracking_number,
                'origin'                 => $shipmentDetails['warehouse_city'] ?? '',
                'destination'            => $shipmentData['shipping_city'] ?? '',
                'pickup_date'            => '',
                'expected_delivery_date' => $shipmentDetails['current_courier_edd'] ?? '',
                'pod'                    => '',
                'scans'                  => [],
            ];

            // Map current status
            $courier_status = $shipmentDetails['current_tracking_status_desc'] ?? '';
            $courier_status_date = $shipmentDetails['current_status_date'] ?? '';
            $courier_status_date = ($courier_status_date)?Carbon::createFromFormat('d-m-Y H:i:s', $courier_status_date)->format('Y-m-d H:i:s'):'';
            $courier_status_mapping = CourierStatusMapping::firstOrCreate(
                [
                    'courier_id'     => $parentId,
                    'courier_status' => $courier_status,
                ],
                [
                    'shipment_status_code' => '',
                ]
            );

            $scansdata['current_status_code'] = $courier_status_mapping->shipment_status_code ?: $courier_status;
            $scansdata['current_status_date'] = $shipmentDetails['updated_time_stamp'] ?? now();

            // Add scan history
            $scans = $shipmentDetails['track_scans'] ?? [];
            foreach ($scans as $scan) {
                $activity = $scan['scan'] ?? '';
                $location = $scan['scan_location'] ?? '';
                $date = $scan['scan_datetime']??'';
                $date = ($date)?Carbon::createFromFormat('d-m-Y H:i:s', $date)->format('Y-m-d H:i:s'):'';

                $scan_mapping = CourierStatusMapping::firstOrCreate(
                    [
                        'courier_id'     => $parentId,
                        'courier_status' => $activity,
                    ],
                    [
                        'shipment_status_code' => '',
                    ]
                );
                if($scan['rapidshyp_status_code']=='PUC'){
                    $scansdata['pickup_date'] = $date;
                }
                $scansdata['scans'][] = [
                    'status'              => $activity,
                    'current_status_code' => $scan_mapping->shipment_status_code ?: $activity,
                    'date'                => $date,
                    'location'            => $location,
                ];
            }
            
            if($scansdata){
                $orderService->addShipmentTrackDetails($order_id,$scansdata);
            }
            return $scansdata;

        } catch (\Throwable $e) {
            return ['error' => 'Exception: ' . $e->getMessage()];
        }
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
            $url = $this->api_url . '/rapidshyp/apis/v1/cancel_order';
            try {
                $payload = [
                     "orderId"    => $order->vendor_order_number,
                     "storeName" => "DEFAULT"
                ];
                $headers = [
                    'Content-Type'   => 'application/json',
                    'rapidshyp-token' => $this->api_key,
                ];
                $response = Http::withHeaders($headers)->post($url, $payload);
                // if (!$response->ok()) {
                //     $this->result['error'][] = "HTTP error for Order ID $order->id: " . $response->status();
                //     continue;
                // }

                $responseData = $response->json();
                $status  = $responseData['status'] ?? false;
                $message = $responseData['remarks'] ?? 'Unknown API response';
                if ($status) {
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