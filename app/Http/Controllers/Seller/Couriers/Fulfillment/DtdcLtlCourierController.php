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
use App\Services\OrderShipmentService;
class DtdcLtlCourierController extends Controller
{
    private $order_ids 		= array();
	private $courier_id 		= 0;
	private $company_id 		= 0;
    private $courier_settings 	= array();
    private $api_mode = '';
    private $api_key ='';
    private $api_token ='';
    private $pay_basis ='';
    private $shipment_mode ='';
    private $customer_code ='';
    private $env_type='';
    private $api_url='';
    private $tracking_url='';
    private $courier_title='';
    public $pickup_address = array();
    public $return_address = array();
    public $errors = array();
    public $result=array();
    public $action="";
    public $print_response=array();
    public $parent_courier_id=11;
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
        $this->courier_title = $courier_settings['courier_title']??'Dtdc Ltl';
        if($this->env_type =='dev'){
            $this->api_url = 'https://alphademodashboardapi.shipsy.io/api/customer/integration/consignment/softdata';
            $this->tracking_url = 'https://api.mywebxpress.com/tms/dtdc/ops/Docket/TrackDocket/DTDCStaging';
        }else{
            $this->api_url = 'https://dtdcapi.shipsy.io/api/customer/integration/consignment/softdata';
            $this->tracking_url = 'https://api.mywebxpress.com/tms/dtdc/ops/Docket/TrackDocket/DTDC';
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
            $i=0;
            $total_package_weight = 0;
            foreach($packages as $package){
                $i++;
                $pieces_detail[] = [
                    "weight"=> $package['dead_weight'],
                    "length"=> $package['length'],
                    "height"=> $package['height'],
                    "width"=> $package['breadth'],
                    "product_code"=> $package['package_code']
                ];
                $total_package_weight += $package['dead_weight'];

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
                $this->parent_courier_id,
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
                        "pay_basis"=>$this->pay_basis,
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
                            "address_line_2" =>  $this->pickup_address->landmark,
                            "pincode" => $this->pickup_address->zipcode,
                            "city" => $this->pickup_address->city,
                            "state" => $this->pickup_address->state_code,
                        ],
                        "destination_details" => [
                            "name" => $order_info['s_fullname'],
                            "phone" => $consignee_phone,
                            "alternate_phone" => $consignee_phone,
                            "address_line_1" =>$order_info['s_complete_address'] ?: $order_info['b_complete_address'],
                            "address_line_2" => $order_info['s_landmark'] ?: $order_info['b_landmark'],
                            "pincode" => $order_info['s_zipcode'] ?: $order_info['b_zipcode'],
                            "city" =>$order_info['s_city'] ?: $order_info['b_city'],
                            "state" => $order_info['s_state_code'] ?: $order_info['b_state_code'],
                        ],
                        "return_details" => [
                            "name" =>  $this->return_address->location_title,
                            "phone" =>  $this->return_address->phone,
                            "alternate_phone" => $this->return_address->alternate_phone,
                            "address_line_1" => $this->return_address->address,
                            "address_line_2" =>$this->return_address->landmark,
                            "pincode" =>  $this->return_address->zipcode,
                            "city" =>  $this->return_address->city,
                            "state" => $this->return_address->state_code,
                            "country" => $this->return_address->country_code,
                            "email" => $this->return_address->email
                        ],
                        "customer_reference_number" => $order_info['vendor_order_number'] ?: $order_info['id'],
                        "cod_collection_mode" =>$order_info['payment_mode'] === 'prepaid' ?"":"CASH",
                        "cod_amount" => $order_info['payment_mode'] === 'prepaid' ? "":$order_info['order_total'],
                        "commodity_id" => "43",
                        "description" => $description,
                        "reference_number" => "",
                        "pieces_detail"=> $pieces_detail,
                    ]
                ]
            ];
            if (ShipmentInfo::where('order_id', $order_id)->exists()) {
                $this->result['error'][$order_info['vendor_order_number']] = 'Tracking number already assigned';
                continue;
            }
            try {
               // \Log::error(json_encode($packageInfo));

                // ───── Send request ────────────────────────────────────────
                $httpResponse = Http::withHeaders([
                        'api-key'      => $this->api_key,
                        'Content-Type' => 'application/json',
                    ])
                    ->retry(3, 200)         // 3 tries, 200 ms back-off
                    ->timeout(15)
                    ->post($this->api_url, $packageInfo);
                   // \Log::info($httpResponse);
                // ───── Non-2xx?  Throw and handle below. ───────────────────
                if (! $httpResponse->successful()) {                    
                    $this->result['error'][$order_info['vendor_order_number']]='dtdc_ltl API call failed';
                    if($this->action == 'print_response'){
                        $this->print_response['assign']['url'] = $this->api_ur;
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
                $respData      = $payload['data'][0]?? [];
                $success       = $respData['success']?? false;
                $errorMessage  = $respData['message']?? '';
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
                            $shipment =  ShipmentInfo::firstOrCreate([
                                'order_id' => $order_id],[
                                'company_id' => $this->company_id,
                                'shipment_type' => $this->shipment_mode,
                                'courier_id' => $this->courier_id,
                                'tracking_id' => $trackingNo,
                                'applied_weight' => $rate['chargeable_weight'],
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
                                'courier_code'    => 'dtdc_ltl',
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
                                    'courier_code' => 'dtdc_ltl',
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
                Log::error('dtdc_ltl ConnectionException', ['order' => $order_id, 'msg' => $e->getMessage()]);
                $this->result['error'][$order_info['vendor_order_number']]
                    = 'Cannot reach dtdc_ltl  DNS/connection problem.';
            } catch (RequestException $e) {
                Log::error('dtdc_ltl returned HTTP ' . $e->response->status(), [
                    'order' => $order_id,
                    'body'  => $e->response->body(),
                ]);
                $this->result['error'][$order_info['vendor_order_number']]
                    = 'dtdc_ltl API error (' . $e->response->status() . ').';
            } catch (\Throwable $e) {
                Log::error('Unexpected dtdc_ltl error', ['order' => $order_id, 'msg' => $e->getMessage()]);
                $this->result['error'][$order_info['vendor_order_number']]
                    = 'Unexpected error: ' . $e->getMessage();
            }
            if($this->action == 'print_response'){
                $this->print_response['assign']['url'] = $this->api_url;
                $this->print_response['assign']['header']=[
                    'api-key' => $this->api_key,
                    'Content-Type' => 'application/json'
                ];
                $this->print_response['assign']['request_data']=$packageInfo;
                $this->print_response['assign']['response_data'] = $httpResponse->body();
                $this->result['print_response'] = $this->print_response;
                return $this->result;
            }
        }

        return $this->result;
    }  
    public function trackShipment($order_id,$tracking_number){
        
        $parentId = $this->courier_settings->courier?->parent_id;
        $data = array();
        $data['trkType'] = "cnno";
        $data['strcnno'] = $tracking_number;
        $data['addtnlDtl'] = 'Y';
        $orderService =new OrderService();
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'xx-authentication-token' => $this->api_token,
        ])->post("{$this->tracking_url}", $data);
        $response = $response->json();   
        $error_message = $response['strError']??'';        
        $IsSuccess = $response['IsSuccess']??false;    
        
        $CONSIGNMENT = $response['CONSIGNMENT']??[];
        $trackHeader = $CONSIGNMENT['CNHEADER']??[];
        $trackDetails = $CONSIGNMENT['CNBODY']['CNACTION']??[];
        $scansdata = array();
        $shipmentData = array();
        if($IsSuccess===true){           
            $strBookedDate = $trackHeader['strBookedOn']??''; 
            $strBookedDate = !empty($strBookedDate)?Carbon::createFromFormat('d/m/Y', $strBookedDate)->startOfDay()->format('Y-m-d'):'';            
            $expected_delivery_date = (!empty($trackHeader['EDD']))?Carbon::createFromFormat('d/m/Y', $trackHeader['EDD'])->startOfDay()->format('Y-m-d'):''; 
            $scansdata['courier_id'] = $this->courier_id;  
            $scansdata['tracking_number'] = $tracking_number;  
            $scansdata['origin'] = $trackHeader['strOrigin']??'';                 
            $scansdata['destination'] = $trackHeader['strDestination']??''; 
            $scansdata['pickup_date'] = $strBookedDate;
            $scansdata['expected_delivery_date'] = $expected_delivery_date;
            $scansdata['pod'] = $trackHeader['PODLink']??''; 
            $scansdata['scans'] = [];
            foreach($trackDetails as $ScanDetail){
                $trackingHistories = array();                                       
                $trackingHistories['status'] = $ScanDetail['strAction']??'';
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
                $scansdata['current_status_code'] = $trackingHistories['current_status_code'];
                $scansdata['current_status_date'] = $trackingHistories['date'];
            } 
        }
        if($scansdata){
            $orderService->addShipmentTrackDetails($order_id,$scansdata);
        }
        return $scansdata;

    }
    public function cancelShipments()
    {
        if (empty($this->order_ids)) {
            return $this->result;
        }

        $orderIds = $this->order_ids;

        try {
            app(OrderShipmentService::class)->cancelOrderById($orderIds);
            // DB::transaction(function () use ($orderIds) {

            //     $shipmentInfos = ShipmentInfo::whereIn('order_id', $orderIds)->get();

            //     foreach ($shipmentInfos as $shipmentInfo) {
            //         try {
            //             app(SellerWalletService::class)->revertFreight([
            //                 'company_id'      => $shipmentInfo->company_id,
            //                 'shipment_id'     => $shipmentInfo->id,
            //                 'tracking_number' => $shipmentInfo->tracking_id,
            //             ]);
            //         } catch (\Exception $e) {
            //             // Log but DO NOT stop cancellation
            //             Log::warning('Freight revert skipped', [
            //                 'shipment_id' => $shipmentInfo->id,
            //                 'reason' => $e->getMessage(),
            //             ]);
            //         }
            //     }

            //     ShipmentInfo::whereIn('order_id', $orderIds)->delete();
            //     OrderCourierResponse::whereIn('order_id', $orderIds)->delete();   
            //     Order::whereIn('id', $orderIds)->update([
            //         'status_code' => 'N'
            //     ]);
            // });

            $this->result['success'][] = 'Order(s) cancelled successfully';

        } catch (\Exception $e) {
            Log::error('Shipment cancel failed', [
                'error' => $e->getMessage(),
                'order_ids' => $orderIds,
            ]);

            $this->result['error'][] = 'Shipment cancel failed'.$e->getMessage();
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
