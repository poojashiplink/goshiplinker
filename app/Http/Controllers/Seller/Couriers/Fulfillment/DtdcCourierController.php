<?php

namespace App\Http\Controllers\Seller\Couriers\Fulfillment;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Pickup;
use App\Models\ShipmentInfo;
use App\Services\OrderService;
use App\Models\CourierStatusMapping;
use App\Models\OrderCourierResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Services\ShippingRateService;
use App\Services\SellerWalletService;
use Carbon\Carbon;
class DtdcCourierController extends Controller
{
    private $order_ids 		= array();
	private $courier_id 		= 0;
	private $company_id 		= 0;
    private $courier_settings 	= array();
    private $api_mode = '';
    private $api_key ='';
    private $tracking_username ='';
    private $tracking_password ='';
    private $shipment_mode ='';
    private $customer_code ='';
    private $env_type='';
    private $api_url='';
    private $tracking_url='';
    public $pickup_address = array();
    public $return_address = array();
    public $errors = array();
    public $result=array();
    public $action="";
    private $courier_title = '';
    public $print_response=array();
    private int $parent_company_id;
    private int $parent_courier_id;
    public function __construct($order_ids = array() , $courier_id = 0 , $company_id = 0,$courier_settings=array()){
		
		$this->order_ids 	= $order_ids;
		$this->courier_id 	= $courier_id;
		$this->company_id 	= ($company_id) ? $company_id : session('company_id');
        $this->courier_settings = $courier_settings;
        $this->parent_company_id = $courier_settings['company_id'] ?? 0;
        $this->parent_courier_id = 5;
        $courier_details = ($courier_settings->courier_details)?json_decode($courier_settings['courier_details'],true):array();
        foreach($courier_details as $key=>$value){
            $this->$key = $value;
        }
        $this->env_type = $courier_settings['env_type']??'dev';
        $this->courier_title = $courier_settings['courier_title']??'Dtdc';
        if($this->env_type =='dev'){
            $this->api_url = 'https://alphademodashboardapi.shipsy.io/api/customer/integration/consignment';
            $this->tracking_url = 'https://dtdcstagingapi.dtdc.com/dtdc-tracking-api';
        }else{
            $this->api_url = 'https://pxapi.dtdc.in/api/customer/integration/consignment';
            $this->tracking_url = 'https://blktracksvc.dtdc.com';
        }
		
	}
	public function assignTrackingNumber()
    {
        foreach ($this->order_ids as $order_id) {
            $order_info = Order::with('orderProducts', 'orderTotals', 'shipmentInfo','packages')->find($order_id)->toArray();

            if (isset($order_info['shipment_info']) && !empty($order_info['shipment_info'])) {
                $this->result['error'][$order_info['vendor_order_number']] = "Tracking number already assigned";
                continue;
            }
            $pieces_detail = array();
            $package_type = $order_info['package_type'];
            $packages = $order_info['packages']??[];
            $total_package_weight = 0;
            foreach($packages as $package){
                $pieces_detail[] = [
                    "weight"=> $package['dead_weight'],
                    "length"=> $package['length'],
                    "height"=> $package['height'],
                    "width"=> $package['breadth'],
                    "product_code"=> $package['package_code']
                ];
                $total_package_weight +=$package['dead_weight'];

            }
            $pieces_count = !empty($pieces_detail)?count($pieces_detail):1;
            $order_info['payment_mode'] = strtolower($order_info['payment_mode']);     

            $consignee_phone = isset($order_info['s_phone']) ? $order_info['s_phone'] : $order_info['b_phone'];
            $consignee_phone = preg_replace('/[^0-9]/', '', $consignee_phone);
            $consignee_phone = substr($consignee_phone, -10);
            $rateService = app(ShippingRateService::class);

            $weight =($package_type=='SPS')?($order_info['package_dead_weight'] ?? 0.5):$total_package_weight;
            $isCod  = $order_info['payment_mode'] === 'cod';

            $rate = $rateService->calculate(
                $this->parent_company_id,
                $this->company_id,
                $this->courier_id,
                $this->pickup_address->zipcode,
                $consignee_phone,
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
            $description = implode(', ', array_column($order_info['order_products'], 'product_name'));
            $description = mb_substr($description, 0, 250);
            // Prepare package information
            $packageInfo = [
                "consignments" => [
                    [
                        "customer_code" => $this->customer_code,
                        "service_type_id" => $this->shipment_mode,
                        "load_type" => "NON-DOCUMENT",
                        "consignment_type" => "Forward",
                        "dimension_unit" => "cm",
                        "length" => $order_info['package_length'] ?? 10,
                        "width" => $order_info['package_breadth'] ?? 10,
                        "height" =>$order_info['package_height'] ?? 10,
                        "weight_unit" => "kg",
                        "weight" => $order_info['package_dead_weight'] ?? 0.05,
                        "declared_value" => $order_info['order_total'],
                        "eway_bill" => "",
                        "invoice_number" => "",
                        "invoice_date" => "",
                        "num_pieces" => $pieces_count,
                        "origin_details" => [
                            "name" => $this->pickup_address->location_title,
                            "phone" => $this->pickup_address->phone,
                            "alternate_phone" => (!empty($this->pickup_address->alternate_phone))?$this->pickup_address->alternate_phone:$this->pickup_address->phone,
                            "address_line_1" => $this->pickup_address->address,
                            "address_line_2" => "",
                            "pincode" => $this->pickup_address->zipcode,
                            "city" => $this->pickup_address->city,
                            "state" => $this->pickup_address->state_code,
                        ],
                        "destination_details" => [
                            "name" => $order_info['s_fullname'],
                            "phone" => $consignee_phone,
                            "alternate_phone" => $consignee_phone,
                            "address_line_1" =>$order_info['s_complete_address'] ?? $order_info['b_complete_address'],
                            "address_line_2" => "",
                            "pincode" => $order_info['s_zipcode'] ?? $order_info['b_zipcode'],
                            "city" =>$order_info['s_city'] ?? $order_info['b_city'],
                            "state" => $order_info['s_state_code'] ?? $order_info['b_state_code'],
                        ],
                        "return_details" => [
                            "name" =>  $this->return_address->location_title,
                            "phone" =>  $this->return_address->phone,
                            "alternate_phone" => $this->return_address->alternate_phone,
                            "address_line_1" => $this->return_address->address,
                            "address_line_2" => "",
                            "pincode" =>  $this->return_address->zipcode,
                            "city" =>  $this->return_address->city,
                            "state" => $this->return_address->state_code,
                            "country" => $this->return_address->country_code,
                            "email" => $this->return_address->email
                        ],
                        "customer_reference_number" => $order_info['vendor_order_number'] ?? $order_info['id'],
                        "cod_collection_mode" =>$order_info['payment_mode'] === 'prepaid' ?"":"CASH",
                        "cod_amount" => $order_info['payment_mode'] === 'prepaid' ? "":$order_info['order_total'],
                        "commodity_id" => "43",
                        "description" => $description,
                        "reference_number" => "",
                        "pieces_detail"=> $pieces_detail
                    ]
                ]
            ];
            if (ShipmentInfo::where('order_id', $order_id)->exists()) {
                $this->result['error'][$order_info['vendor_order_number']] = 'Tracking number already assigned';
                continue;
            }
            try {

                // ───── Send request ────────────────────────────────────────
                $httpResponse = Http::withHeaders([
                        'api-key'      => $this->api_key,
                        'Content-Type' => 'application/json',
                    ])
                    ->retry(3, 200)         // 3 tries, 200 ms back-off
                    ->timeout(15)
                    ->post("{$this->api_url}/softdata", $packageInfo);

                // ───── Non-2xx?  Throw and handle below. ───────────────────
                if (! $httpResponse->successful()) {                    
                    $this->result['error'][$order_info['vendor_order_number']]='DTDC API call failed';
                    if($this->action == 'print_response'){
                        $this->print_response['assign']['url'] = $this->api_url."/softdata";
                        $this->print_response['assign']['header']=[
                            'api-key' => $this->api_key,
                            'Content-Type' => 'application/json'
                        ];
                        $this->print_response['assign']['request_data']=$packageInfo;
                        $this->print_response['assign']['response_data'] = $httpResponse->body();
                        $this->result['print_response'] = $this->print_response;
                        return $this->result;
                    }   
                    continue;                 
                }
                // ───── Parse JSON safely ───────────────────────────────────
                $payload       = $httpResponse->json();
                $respData      = $payload['data'][0]        ?? [];
                $success       = $respData['success']       ?? false;
                $errorMessage  = $respData['message']       ?? '';
                $trackingNo    = $respData['reference_number'] ?? '';
                $pieces        = $respData['pieces'] ?? [];
                if ($success && $trackingNo) {
                    $pickup_address = $this->pickupAddressFormat($this->pickup_address);
                    $return_address = $this->pickupAddressFormat($this->return_address);
                    $other_details = [];
                    if($package_type=='MPS'){                        
                        $other_details['pieces'] = $pieces;
                    }
                    try{
                        DB::transaction(function () use (
                            $order_id,
                            $order_info,
                            $package_type,
                            $other_details,
                            $trackingNo,
                            $pickup_address,
                            $return_address,
                            $rate
                        ) {
                            /** -------------------------------
                            * STEP 1: Create Shipment
                            * -------------------------------- */
                            $shipment = ShipmentInfo::firstOrCreate([
                                'order_id' => $order_id],[
                                'company_id' => $this->company_id,
                                'shipment_type' => $this->shipment_mode,
                                'courier_id' => $this->courier_id,
                                'tracking_id' => $trackingNo,
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
                                'courier_code'    => 'dtdc',
                                'tracking_number' => $trackingNo,
                                'amount'          => $rate['shipping_cost'],
                                'cod_charges'     => $rate['cod_charge'] ?? 0
                            ]);
                            
                            
                            /** -------------------------------
                            * STEP 3: Update Order Status
                            * -------------------------------- */
                            Order::where('id', $order_id)->update(['status_code' => 'P', 'rate_card_id' => $rate['rate_card_id']??null]);    
                            if($package_type=='MPS'){
                                OrderCourierResponse::create([
                                    'order_id' => $order_id,
                                    'courier_code' => 'dtdc',
                                    'courier_name' => $this->courier_title,
                                    'response' => $other_details,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);  

                            } 
                        });     
                        
                        $this->result['success'] = true;
                    } catch (\Exception $e) {
                        Log::error("Error processing order ID $order_id: " . $e->getMessage());
                        $this->result['error'][$order_info['vendor_order_number']] = "Failed to assigned tracking number"." Error: ".$e->getMessage();
                        continue;
                    }

                } else {
                    $this->result['error'][$order_info['vendor_order_number']]
                        = 'Failed to assign tracking number. Error: ' . $errorMessage;
                }
            } catch (ConnectionException $e) {
                Log::error('DTDC ConnectionException', ['order' => $order_id, 'msg' => $e->getMessage()]);
                $this->result['error'][$order_info['vendor_order_number']]
                    = 'Cannot reach DTDC  DNS/connection problem.';
            } catch (RequestException $e) {
                Log::error('DTDC returned HTTP ' . $e->response->status(), [
                    'order' => $order_id,
                    'body'  => $e->response->body(),
                ]);
                $this->result['error'][$order_info['vendor_order_number']]
                    = 'DTDC API error (' . $e->response->status() . ').';
            } catch (\Throwable $e) {
                Log::error('Unexpected DTDC error', ['order' => $order_id, 'msg' => $e->getMessage()]);
                $this->result['error'][$order_info['vendor_order_number']]
                    = 'Unexpected error: ' . $e->getMessage();
            }
            if($this->action == 'print_response'){
                $this->print_response['assign']['url'] = $this->api_url."/softdata";
                $this->print_response['assign']['header']=[
                    'api-key' => $this->api_key,
                    'Content-Type' => 'application/json'
                ];
                $this->print_response['assign']['request_data']=$packageInfo;
                $this->print_response['assign']['response_data'] = $httpResponse->body();;
                $this->result['print_response'] = $this->print_response;
                return $this->result;
            }
        }

        return $this->result;
    }  
    public function trackShipment($order_id,$tracking_number){
        $token = $this->authentication();
        if(empty($token)){
            $this->result['error'][] = 'Invalid courier credentials';
            return $this->result;
        }
        $parentId = $this->courier_settings->courier?->parent_id;
        $data = array();
        $data['trkType'] = "cnno";
        $data['strcnno'] = $tracking_number;
        $data['addtnlDtl'] = 'Y';
        $orderService =new OrderService();
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Access-Token' => $token,
        ])->post("{$this->tracking_url}/dtdc-api/rest/JSONCnTrk/getTrackDetails", $data);
        $response = $response->json();        
        $error = $response['Unauthorized']??'';
        $error_message = $response['message']??'';
        if($error && $error_message=='Unauthorized: Authentication token was either missing or invalid.'){
            if (Cache::has("api_auth_token_dtdc_{$this->courier_id}_{$this->company_id}")) {
                Cache::forget("api_auth_token_dtdc_{$this->courier_id}_{$this->company_id}");
            }
            $token = $this->authentication();
        }
        
        $statusCode = $response['statusCode']??'';    
        $success_status = $response['status']??'';     
        $errorDetails = $response['errorDetails']??[];   
        if(!empty($errorDetails)){
            $errorDetails = end($errorDetails);
            $error_message = $errorDetails['value']??'';
        }
        $trackHeader = $response['trackHeader']??[];
        $trackDetails = $response['trackDetails']??[];
        $scansdata = array();
        $shipmentData = array();
        if($statusCode==200 && $success_status=='SUCCESS' && !empty($trackDetails)){  
            $strStatus = $trackHeader['strStatus']??''; 
            $courier_status_mapping = CourierStatusMapping::where('courier_id', $parentId)
                ->where('courier_status', $strStatus)
                ->first();
            if (!$courier_status_mapping) {
                $courier_status_mapping = CourierStatusMapping::create([
                        'courier_id' => $parentId,
                        'courier_status' => $strStatus,
                        'shipment_status_code' => '',
                    ]
                );
            }     
            $shipment_status_code = $courier_status_mapping->shipment_status_code?$courier_status_mapping->shipment_status_code:$strStatus;                        
            $strStatusTransOn = $trackHeader['strStatusTransOn']??''; 
            $strStatusTransTime = $trackHeader['strStatusTransTime']??''; 
            $strStatusTransOn = !empty($strStatusTransOn)?Carbon::createFromFormat('dmY', $strStatusTransOn)->startOfDay()->format('Y-m-d'):'';
            $scansdata['current_status_date'] = !empty($strStatusTransTime)?$strStatusTransOn.' '.$strStatusTransTime:$strStatusTransOn; 
            $scansdata['current_status_code'] = $shipment_status_code;
            $strBookedDate = $trackHeader['strBookedDate']??''; 
            $strBookedDate = !empty($strBookedDate)?Carbon::createFromFormat('dmY', $strBookedDate)->startOfDay()->format('Y-m-d'):'';
            $strBookedDate = !empty($trackHeader['strBookedTime'])?$strBookedDate.' '.$trackHeader['strBookedTime']:$strBookedDate; 
            $expected_delivery_date = (!empty($trackHeader['strExpectedDeliveryDate']))?Carbon::createFromFormat('dmY', $trackHeader['strExpectedDeliveryDate'])->startOfDay()->format('Y-m-d'):''; 
            $scansdata['courier_id'] = $this->courier_id;  
            $scansdata['tracking_number'] = $tracking_number;  
            $scansdata['origin'] = $trackHeader['strOrigin']??'';                 
            $scansdata['destination'] = $trackHeader['strDestination']??''; 
            $scansdata['pickup_date'] = $strBookedDate;
            $scansdata['expected_delivery_date'] = $expected_delivery_date;
            $scansdata['pod'] = ''; 
            $scansdata['scans'] = [];
            foreach($trackDetails as $ScanDetail){
                $trackingHistories = array();                                       
                $trackingHistories['status'] = (!empty($ScanDetail['strAction'])?$ScanDetail['strAction']:$ScanDetail['sTrRemarks']);
                $courier_status_mapping = CourierStatusMapping::where('courier_id', $parentId)
                ->where('courier_status', $ScanDetail['strAction'])
                ->first();
                if (!$courier_status_mapping) {
                    $courier_status_mapping = CourierStatusMapping::create([
                            'courier_id' => $parentId,
                            'courier_status' => $ScanDetail['strAction'],
                            'shipment_status_code' => '',
                        ]
                    );
                }        
                $statusDate = !empty($ScanDetail['strActionDate'])?Carbon::createFromFormat('dmY', $ScanDetail['strActionDate'])->startOfDay()->format('Y-m-d'):'';
                $statusTime = !empty($ScanDetail['strActionTime'])?Carbon::createFromFormat('Hi', $ScanDetail['strActionTime'])->format('H:i:s'):'';
                $statusDate = trim($statusDate.' '.$statusTime);           
                $trackingHistories['date'] =$statusDate;
                $shipment_status_code = $courier_status_mapping->shipment_status_code?$courier_status_mapping->shipment_status_code:$ScanDetail['strAction'];                        
                $trackingHistories['current_status_code'] = $shipment_status_code;     
                $trackingHistories['location'] = $ScanDetail['strDestination']; 
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

        $token = $this->authentication();
        if (empty($token)) {
            $this->result['error'][] = 'Invalid courier credentials';
            return response()->json($this->result);
        }

        $orders = Order::with('shipmentInfo')
            ->whereIn('id', $this->order_ids)
            ->get();

        $customerCode = $this->customer_code;
        $successfulOrders = [];

        foreach ($orders as $order) {
            $shipment = $order->shipmentInfo;

            if (!$shipment || !$shipment->tracking_id) {
                $this->result['error'][] = "Tracking ID not found for Order ID {$order->id}";
                continue;
            }

            $payload = [
                'AWBNo' => [$shipment->tracking_id],
                'customerCode' => $customerCode
            ];

            try {
                $response = Http::withHeaders([
                    'api-key' => $this->api_key,
                    'Content-Type' => 'application/json',
                ])->post($this->api_url . '/cancel', $payload);

                $data = $response->json();
                if (!empty($data['successConsignments'])) {
                    DB::transaction(function () use ($order) {
                        $shipmentInfo = $order->shipmentInfo;
                        if ($shipmentInfo) {
                            app(SellerWalletService::class)->revertFreight([
                                'company_id'      => $shipmentInfo->company_id,
                                'shipment_id'     => $shipmentInfo->id,
                                'tracking_number' => $shipmentInfo->tracking_id,
                            ]);
                        }
                        ShipmentInfo::where('order_id', $order->id)->delete();
                        OrderCourierResponse::where('order_id', $order->id)->delete();
                        Order::where('id', $order->id)->update([
                            'status_code' => 'N'
                        ]);
                    });
                    
                    // DB::transaction(function () use ($order) {
                    //     ShipmentInfo::where('order_id', $order->id)->delete();
                    //     OrderCourierResponse::where('order_id', $order->id)->delete();
                    //     $order->update(['status_code' => 'N']);
                    // });
                    $successfulOrders[] = $order->vendor_order_number ?? $order->id;
                }

                if (!empty($data['failures'])) {
                    foreach ($data['failures'] as $failure) {
                        $this->result['error'][] = "Order ID {$order->vendor_order_number} (AWB {$failure['reference_number']}): {$failure['message']}";
                    }
                }

                if (empty($data['successConsignments']) && empty($data['failures'])) {
                    $this->result['error'][] = "Unknown error occurred for Order ID {$order->vendor_order_number}";
                }

            } catch (\Exception $e) {
                $this->result['error'][] = "API error for Order ID {$order->vendor_order_number}: " . $e->getMessage();
            }
        }

        if (!empty($successfulOrders)) {
            $this->result['success'] = 'Shipment cancelled successfully for order ID: ' . implode(', ', $successfulOrders);
        }

        return $this->result;
    }
    public function authentication(){       
        $company_id = $this->company_id;
        $token = Cache::get("api_auth_token_dtdc_{$this->courier_id}_{$company_id}");
        $url = $this->tracking_url.'/dtdc-api/api/dtdc/authenticate?username='.$this->tracking_username.'&password='.$this->tracking_password;
        if (!$token) {
            $response = Http::get($url);             
            $response = $response->body();   
            $token="";
            if($response !='Not Authorized'){
                $token = $response;
            }         
            if($token){
                Cache::put("api_auth_token_dtdc_{$this->courier_id}_{$company_id}", $token, now()->addHours(23));
            }
            if($this->action == 'print_response'){
                $this->print_response['authentication']['auth_url'] = $url;
                $this->print_response['authentication']['auth_response'] = $response;
			}
        }        
        return $token;
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
