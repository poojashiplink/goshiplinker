<?php

namespace App\Http\Controllers\Seller\Couriers\Fulfillment;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\ShipmentInfo;
use App\Services\OrderService;
use App\Models\CourierStatusMapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Services\ShippingRateService;
use App\Services\SellerWalletService;
use App\Services\OrderShipmentService;
class EkartCourierController extends Controller
{
    private $order_ids 		= array();
	private $courier_id 		= 0;
	private $company_id 		= 0;
    private $courier_settings 	= array();
    private $env_type='';
    private $api_url='';
    public $pickup_address = array();
    public $return_address = array();
    public $errors = array();
    public $result=array();
    public $action="";
    public $print_response=array();
    public $parent_courier_id=4;
    public function __construct($order_ids = array() , $courier_id = 0 , $company_id = 0,$courier_settings=array()){
		
		$this->order_ids 	= $order_ids;
		$this->courier_id 	= $courier_id;
		$this->company_id 	= ($company_id) ? $company_id : session('company_id');
        $this->courier_settings = $courier_settings;
        $courier_details = ($courier_settings->courier_details)?json_decode($courier_settings['courier_details'],true):array();
        foreach($courier_details as $key=>$value){
            $this->$key = $value;
        }
        $this->env_type = $courier_settings['env_type']??'dev';
        if($this->env_type=='dev'){
            $this->api_url = 'https://staging.ekartlogistics.com';
        }else{
            $this->api_url = 'https://api.ekartlogistics.com';
        }
		
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
                $this->result['error'][$order_info['vendor_order_number']] = "Tracking number already assigned`   ";
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
                $this->parent_courier_id,
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
                $this->result['error'][] = "Tracking number not found for assigning more orders";
                break;
            }
            $payment_type='C';
            $collectable_amount = round($order_info['order_total']);            
            if($order_info['payment_mode']=='prepaid'){
                $payment_type = 'P';
                $collectable_amount =0;

            }
            $tracking_number = $this->merchant_code.$payment_type.$trackingNumber;
            $orderProducts = $order_info['order_products'];
            $shipment_items = [];
            
            foreach($orderProducts as $key=>$orderProduct){
                $shipment_items[$key] =[
                    "product_id" => $orderProduct['sku'],
                    "category" => "",
                    "product_title" => $orderProduct['product_name'],
                    "quantity" => $orderProduct['quantity'],                    
                    "seller_details" => [
                        "seller_reg_name" => "",
                        "gstin_id" => $this->pickup_address->gstin
                    ],
                    "hsn" =>$orderProduct['hsn'],  
                    "ern" => "",
                    "discount" => $orderProduct['discount'], 
                    "item_attributes" => [
                        [
                            "name" => "order_id",
                            "value" => $order_info["vendor_order_number"]
                        ],
                        [
                            "name" => "invoice_id",
                            "value" => $order_info["invoice_prefix"].$order_info["invoice_number"]
                        ],
                        [
                            "name" => "item_dimensions",
                            "value" => "l:b:h:w"
                        ],
                        [
                            "name" => "brand_name",
                            "value" => ""
                        ]
                    ],
                    "handling_attributes" => [
                        [
                            "name" => "isFragile",
                            "value" => "false"
                        ],
                        [
                            "name" => "isDangerous",
                            "value" => "false"
                        ]
                    ]
                ];

            }
            $consignee_phone = isset($order_info['s_phone']) ? $order_info['s_phone'] : $order_info['b_phone'];
            $consignee_phone = preg_replace('/[^0-9]/', '', $consignee_phone);
            $consignee_phone = substr($consignee_phone, -10);
            $order_info['package_breadth'] = $order_info['package_breadth'] ?? 10;
            $order_info['package_height'] = $order_info['package_height'] ?? 10;
            $order_info['package_length'] = $order_info['package_length'] ?? 10;
            $order_info['package_dead_weight'] = $order_info['package_dead_weight'] ?? 0.05;
            $data = [
                "client_name" => $this->merchant_code,
                "goods_category" => $this->goods_category,
                "services" => [
                    [
                        "service_code" => $this->service_code,
                        "service_details" => [
                            [
                                "service_leg" => "FORWARD",
                                "service_data" => [
                                    "service_types" => [
                                        [
                                            "name" => "regional_handover",
                                            "value" => "true"
                                        ]
                                    ],
                                    "vendor_name" => $order_info['vendor_order_number'],
                                    "amount_to_collect" => $collectable_amount,
                                    "dispatch_date" => "",
                                    "customer_promise_date" => "",
                                    "delivery_type" => $this->delivery_type,
                                    "source" => [
                                        "address" => [
                                            "first_name" =>$this->pickup_address->contact_person_name,
                                            "address_line1" => $this->pickup_address->address,
                                            "address_line2" =>$this->pickup_address->landmark,
                                            "pincode" =>$this->pickup_address->zipcode,
                                            "city" =>$this->pickup_address->city,
                                            "state" =>$this->pickup_address->state_code,
                                            "primary_contact_number" => $this->pickup_address->phone,
                                            "email_id" => $this->pickup_address->email
                                        ]
                                    ],
                                    "destination" => [
                                        "address" => [
                                            "first_name" => $order_info['s_fullname'] ?? $order_info['b_fullname'],
                                            "address_line1" => $this->cleanAddress($order_info['s_complete_address'] ?? $order_info['b_complete_address']),
                                            "address_line2" => $order_info['s_landmark'] ?? $order_info['s_landmark'],
                                            "pincode" => $order_info['s_zipcode'] ?? $order_info['b_zipcode'],
                                            "city" => $order_info['s_city'] ?? $order_info['b_city'],
                                            "state" =>  $order_info['s_state_code'] ?? $order_info['b_state_code'],
                                            "primary_contact_number" => $consignee_phone,
                                            "email_id" => $order_info['email']
                                        ]
                                    ],
                                    "return_location" => [
                                        "address" => [
                                            "first_name" => $this->return_address->contact_person_name,
                                            "address_line1" => $this->return_address->address,
                                            "address_line2" => $this->return_address->landmark,
                                            "pincode" => $this->return_address->zipcode,
                                            "city" => $this->return_address->city,
                                            "state" => $this->return_address->state_code,
                                            "primary_contact_number" => $this->return_address->phone,
                                            "email_id" => $this->return_address->email
                                        ]
                                    ]
                                ],
                                "shipment" => [
                                    "client_reference_id" => $tracking_number,
                                    "tracking_id" => $tracking_number,
                                    "shipment_value" => $order_info['order_total'],
                                    "shipment_dimensions" => [
                                        "length" => ["value" => $order_info['package_length']],
                                        "breadth" => ["value" => $order_info['package_breadth']],
                                        "height" => ["value" => $order_info['package_height']],
                                        "weight" => ["value" => $order_info['package_dead_weight']]
                                    ],
                                    "return_label_desc_1" => "",
                                    "return_label_desc_2" => "",
                                    "shipment_items" => $shipment_items,
                                ]
                            ]
                        ]
                    ]
                ]
            ];
            if (ShipmentInfo::where('order_id', $order_id)->exists()) {
                $this->result['error'][$order_info['vendor_order_number']] = 'Tracking number already assigned';
                continue;
            }
            try {
                $response = Http::withHeaders([
                        'Content-Type'        => 'application/json',
                        'HTTP_X_MERCHANT_CODE'=> $this->merchant_code,
                        'Authorization'       => $token,
                    ])
                    ->timeout(60)
                    ->connectTimeout(20)
                    ->post("{$this->api_url}/v2/shipments/create", $data);
                     $response = $response->json();
                if (isset($response['response'][0]['status']) && $response['response'][0]['status'] == "REQUEST_RECEIVED") {
                    $pickup_address = $this->pickupAddressFormat($this->pickup_address);
                    $return_address = $this->pickupAddressFormat($this->return_address);
                    try{
                        DB::transaction(function () use (
                            $order_id,
                            $order_info,
                            $tracking_number,
                            $pickup_address,
                            $return_address,
                            $rate
                        ) {
                            /** -------------------------------
                            * STEP 1: Create Shipment
                            * -------------------------------- */
                            
                            $shipment =  ShipmentInfo::firstOrCreate([
                                'order_id' => $order_id],[
                                'company_id' => $this->company_id,
                                'shipment_type' => '',
                                'courier_id' => $this->courier_id,
                                'tracking_id' => $tracking_number,
                                'applied_weight' => $rate['chargeable_weight']??0,
                                'pickedup_location_id' => $this->pickup_address->id,
                                'pickedup_location_address' => $pickup_address,
                                'return_location_id' => $this->return_address->id,
                                'return_location_address' => $return_address,
                                'manifest_created' => 0,
                                'payment_mode' => $order_info['payment_mode'],
                            ]);
                            /** -------------------------------
                             * STEP 2: Apply Freight (Wallet Debit)
                             * -------------------------------- */
                            app(SellerWalletService::class)->applyFreight([
                                'company_id'      => $this->company_id,
                                'order_id'        => $order_id,
                                'shipment_id'     => $shipment->id,
                                'courier_id'      => $this->courier_id,
                                'courier_code'    => 'ekart',
                                'tracking_number' => $tracking_number,
                                'amount'          => $rate['shipping_cost'],
                                'cod_charges'     => $rate['cod_charge'] ?? 0
                            ]);
                            
                            /** -------------------------------
                            * STEP 3: Update Order Status
                            * -------------------------------- */
                            DB::table('import_tracking_numbers')->where(['tracking_number'=> $trackingNumber,'company_id'=>$this->company_id,'courier_id'=>$this->courier_id])->update(['used' => 1]);
                            Order::where('id', $order_id)->update(['status_code' => 'P', 'rate_card_id' => $rate['rate_card_id']??null]);
                        });   
                        $this->result['success'] = true;
                    } catch (\Exception $e) {
                        Log::error("Error processing order ID $order_id: " . $e->getMessage());
                        $this->result['error'][$order_info['vendor_order_number']] = "Failed to assigned tracking number"." Error: ".$e->getMessage();
                        continue;
                    }
                }elseif(isset($response['response'][0]['status']) && $response['response'][0]['status'] == "REQUEST_REJECTED")
                {
                    $error_message =  implode(',',$response['response'][0]['message']);
                    if(strpos($error_message, 'already present') !== false || strpos($error_message, 'already exists with the same') !== false){
                        DB::table('import_tracking_numbers')->where(['tracking_number'=> $trackingNumber,'company_id'=>$this->company_id,'courier_id'=>$this->courier_id])->update(['used' => 1]);
                    }
                    $this->result['error'][$order_info['vendor_order_number']] = $error_message;
                }elseif(isset($response['response'][0]['api_response_message']) && !isset($response['response'][0]['status'])){
                    $this->result['error'][$order_info['vendor_order_number']] = $response['response'][0]['api_response_message'];
                }elseif(isset($response['unauthorised']) && !empty($response['unauthorised'])){
                    $this->result['error'][$order_info['vendor_order_number']] = $response['unauthorised'];
                }

                // Log::info('Ekart response', [
                //     'status' => $response->status(),
                //     'body'   => $response->body(),
                // ]);
                if($this->action == 'print_response'){
                    $this->print_response['assign']['url'] = $this->api_url."/v2/shipments/create";
                    $this->print_response['assign']['header']=[
                        'Content-Type' => 'application/json',
                        'HTTP_X_MERCHANT_CODE' => $this->merchant_code,
                        'Authorization' => $token,
                    ];
                    $this->print_response['assign']['request_data']=$data;
                    $this->print_response['assign']['response_data'] = $response;
                    $this->result['print_response'] = $this->print_response;
                    return $this->result;
                }

            } catch (\Exception $e) {
                Log::error('Ekart API Error', [
                    'message' => $e->getMessage(),
                    'line'    => $e->getLine(),
                    'file'    => $e->getFile(),
                ]);
                if($this->action == 'print_response'){
                    $this->print_response['assign']['url'] = $this->api_url."/v2/shipments/create";
                    $this->print_response['assign']['header']=[
                        'Content-Type' => 'application/json',
                        'HTTP_X_MERCHANT_CODE' => $this->merchant_code,
                        'Authorization' => $token,
                    ];
                    $this->print_response['assign']['request_data']=$data;
                    $this->print_response['assign']['response_data'] = $e->getMessage();
                    $this->result['print_response'] = $this->print_response;
                    return $this->result;
                }

            }
        }
        return $this->result;

    }
    public function fetchWaybill(){
     
        $response =  DB::table('import_tracking_numbers')->where('used', 0)
        ->where('company_id', $this->company_id)
        ->where('courier_id', $this->courier_id)
        ->select('tracking_number')->first();
        $tracking_number = !empty($response)? $response->tracking_number:0;
        return $tracking_number;
       
    }
    public function authentication(){
        $company_id = $this->company_id;
        $token = Cache::get("api_auth_token_ekart_{$this->courier_id}_{$company_id}");
        if (!$token) {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'HTTP_X_MERCHANT_CODE' => $this->merchant_code,
                'Authorization' => $this->authorization_code,
            ])->post($this->api_url.'/auth/token');        
            $response = $response->json();
            $token = $response['Authorization']??'';
            if($token){
                Cache::put("api_auth_token_ekart_{$this->courier_id}_{$company_id}", $token, now()->addMinutes(59));
            }
            if($this->action == 'print_response'){
                $this->print_response['authentication']['auth_url'] = $this->api_url."/auth/token";
				$this->print_response['authentication']['auth_header']=[
                    'Content-Type' => 'application/json',
                    'HTTP_X_MERCHANT_CODE' => $this->merchant_code,
                    'Authorization' => $this->authorization_code,
                ];
                $this->print_response['authentication']['auth_response'] = $response;
			}
        }
        
        return $token;
    }
    public function trackShipment($order_id, $tracking_number)
    {
        $token = $this->authentication();

        if (!$token) {
            $this->result['error'][] = 'Invalid courier credentials';
            return $this->result;
        }

        $parentId = $this->courier_settings->courier?->parent_id;

        // Prepare request payload
        $payload = ['tracking_ids' => [$tracking_number]];

        // Send request
        $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'HTTP_X_MERCHANT_CODE' => $this->merchant_code,
                'Authorization' => $token,
            ])
            ->timeout(60)
            ->connectTimeout(20)
            ->post("{$this->api_url}/v2/shipments/track", $payload)
            ->json();

        // Handle expired token
        if (($response['unauthorised'] ?? null) === 'The token has expired. Please request a new token') {
            Cache::forget("api_auth_token_ekart_{$this->courier_id}_{$this->company_id}");
            $token = $this->authentication();
        }

        // Grab the first shipment record
        $shipmentData = $response[0] ?? null;

        if (!$shipmentData) {
            return [];
        }

        $orderService = new OrderService();
        $scansdata = [
            'courier_id' => $this->courier_id,
            'tracking_number' => $tracking_number,
            'origin' => $shipmentData['sender']['address1'] ?? '',
            'destination' => '',
            'pickup_date' => '',
            'expected_delivery_date' => $this->parseDate($shipmentData['expected_delivery_date'] ?? ''),
            'pod' => $shipmentData['pod_url'] ?? '',
            'scans' => []
        ];

        $last_status = null;

        foreach ($shipmentData['history'] ?? [] as $scan) {

            $publicDesc = $scan['public_description'] ?? '';
            $statusText = (empty($publicDesc) || $publicDesc == 'Expected at null')
                ? $scan['status']
                : str_replace('_', ' ', $publicDesc);

            $eventDate = $this->parseDate($scan['event_date'] ?? '');

            // Determine mapping
            $shipmentStatus = $this->getMappedStatus($parentId, $scan['status']);

            // Special OFP rule
            if ($scan['status'] === 'shipment_expected' && in_array($last_status, ['pickup_scheduled', 'shipment_created', 'out_for_pickup'])) {
                $shipmentStatus = 'OFP';
            }

            // Assign pickup date
            if (empty($scansdata['pickup_date']) && $scan['status'] === 'pickup_scheduled') {
                $scansdata['pickup_date'] = $eventDate;
            }

            $scansdata['scans'][] = [
                'status' => $statusText,
                'date' => $eventDate,
                'current_status_code' => $shipmentStatus,
                'location' => $scan['city'] ?? '',
            ];

            $last_status = $scan['status'];
        }

        // Set latest status
        $scansdata['current_status_code'] = $scansdata['scans'][0]['current_status_code'] ?? '';
        $scansdata['current_status_date'] = $scansdata['scans'][0]['date'] ?? '';

        // Save tracking details
        $orderService->addShipmentTrackDetails($order_id, $scansdata);

        return $scansdata;
    }

    /**
     * Parse date string safely.
     */
    private function parseDate($date)
    {
        if (empty($date) || $date === 'Shipment yet to be dispatched') {
            return '';
        }
        return date('Y-m-d H:i:s', strtotime($date));
    }

    /**
     * Get mapped shipment status from DB or create if missing (cached).
     */
    private function getMappedStatus($parentId, $courierStatus)
    {
        return Cache::remember(
            "courier_status_map_{$parentId}_{$courierStatus}",
            3600,
            function () use ($parentId, $courierStatus) {
                $map = CourierStatusMapping::firstOrCreate(
                    ['courier_id' => $parentId, 'courier_status' => $courierStatus],
                    ['shipment_status_code' => '']
                );

                return $map->shipment_status_code ?: $courierStatus;
            }
        );
    }
    public function cancelShipments() {
        if ($this->order_ids) {
            try {
                $token = $this->authentication();
                if (!$token) {
                    $this->result['error'][] = "Token not generated.";
                    return $this->result;
                }

                $orders = Order::with('shipmentInfo') // eager load shipment info
                ->whereIn('id', $this->order_ids)
                ->get();

                $requestPayloads = [];
                $orderMap = [];
                foreach ($orders as $order) {
                    $shipment = $order->shipmentInfo;
                    if (!$shipment || !$shipment->tracking_id) {
                        $this->result['error'][] = "Tracking ID not found for Order ID {$order->id}";
                        continue;
                    }
        
                    $requestPayloads[] = [
                        "tracking_id" => $shipment->tracking_id,
                        "reason" => "Cancel By Customer"
                    ];
                    $orderMap[$shipment->tracking_id] = $order->id;
                }

                foreach (array_chunk($requestPayloads, 20) as $chunk) {
                    $requestDetails = ["request_details" => $chunk];
                    $cancel_response = Http::withHeaders([
                        'HTTP_X_MERCHANT_CODE' => $this->merchant_code,
                        'Authorization' => $token,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ])->put("{$this->api_url}/v2/shipments/rto/create",$requestDetails);
    
                    $response_data = $cancel_response->json();
                    if(isset($response_data['unauthorised']) && $response_data['unauthorised']=='The token has expired. Please request a new token'){
                        if (Cache::has("api_auth_token_ekart_{$this->courier_id}_{$this->company_id}")) {
                            Cache::forget("api_auth_token_ekart_{$this->courier_id}_{$this->company_id}");
                        }
                        $token = $this->authentication();
                    }  
                    foreach ($response_data['response'] as $response_item){
                        $tracking_id = $response_item['merchant_reference_id']??'';
                        if (
                            $response_item['status'] === 'REQUEST_RECEIVED' &&
                            $response_item['status_code'] == 200
                        ) {
                            $order_id = $orderMap[$tracking_id];
                            app(OrderShipmentService::class)->cancelOrderById($order->id);
                            // DB::transaction(function () use ($order) {

                            //     $shipmentInfo = $order->shipmentInfo;

                            //     if ($shipmentInfo) {
                            //         app(SellerWalletService::class)->revertFreight([
                            //             'company_id'      => $shipmentInfo->company_id,
                            //             'shipment_id'     => $shipmentInfo->id,
                            //             'tracking_number' => $shipmentInfo->tracking_id,
                            //         ]);
                            //     }

                            //     ShipmentInfo::where('order_id', $order->id)->delete();

                            //     Order::where('id', $order->id)->update([
                            //         'status_code' => 'N'
                            //     ]);
                            // });

                        } else {
                            $this->result['error'][] = "Cancel failed for Order ID {$orderMap[$tracking_id]}";
                        }
                    }
                    }
    
                $this->result['success'][] = "Shipment Canceled Successfully";
    
            } catch (\Exception $e) {
                Log::error('Shipment cancel failed: ' . $e->getMessage());
                $this->result['error'][] = "Something went wrong.";
            }
        }
    
        return $this->result;
    }
    public function pickupAddressFormat($pickup_address){
        $pickup_address = $this->pickup_address->address;
        if($this->pickup_address->landmark){
            $pickup_address .= ', '.$this->pickup_address->landmark;
        }
        if($this->pickup_address->city){
            $pickup_address .= ', '.$this->pickup_address->city;
        }
        if($this->pickup_address->state_code){
            $pickup_address .= ', '.$this->pickup_address->state_code;
        }
        if($this->pickup_address->zipcode){
            $pickup_address .= ', '.$this->pickup_address->zipcode;
        } 
        return $pickup_address;

    }
    public function cleanAddress($string) {
        // convert unicode dashes/quotes to normal ones
        $string = str_replace(
            ["–", "—", "“", "”", "’"],
            ["-", "-", '"', '"', "'"],
            $string
        );

        // allow only letters, numbers, spaces, and basic punctuation
        return preg_replace('/[^A-Za-z0-9 ,.-]/', '', $string);
    }
}
