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
use Carbon\Carbon;
use App\Models\ImportPincodeNumber;
use App\Models\OrderCourierResponse;
use App\Services\ShippingRateService;
use App\Services\SellerWalletService;
class XpressbeesPostpaidCourierController extends Controller
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
    private int $parent_company_id;
    private int $parent_courier_id;
    public function __construct($order_ids = array() , $courier_id = 0 , $company_id = 0,$courier_settings=array()){		
		$this->order_ids 	= $order_ids;
		$this->courier_id 	= $courier_id;
		$this->company_id 	= ($company_id) ? $company_id : session('company_id');
        $this->courier_settings = $courier_settings;
        $this->parent_company_id = $courier_settings['company_id'] ?? 0;
        $this->parent_courier_id = 6;
        $courier_details = ($courier_settings->courier_details)?json_decode($courier_settings['courier_details'],true):array();
        foreach($courier_details as $key=>$value){
            $this->$key = $value;
        }     
		
	}
	public function assignTrackingNumber()
    {   
        $token = $this->authentication();
        if(empty($token)){
            $this->result['error'][] = "Credentials are invalid.";
            return $this->result;
        }
        // $serviceable_pincodes = $this->getServiceablePincodes($this->courier_id,$this->company_id);
        // if(empty($serviceable_pincodes)){
        //     $this->result['error'][] = "No serviceable pincode found. please <a href=".route('pincode_list')." target='_blank'>click here</a> to upload serviceable pincodes";
        //     return $this->result;

        // }
        $manifest_id = rand(100000,9999999);
        foreach ($this->order_ids as $order_id) {
            $order_info = Order::with('orderProducts', 'orderTotals', 'shipmentInfo')->find($order_id)->toArray();
            if (isset($order_info['shipment_info']) && !empty($order_info['shipment_info'])) {
                $this->result['error'][$order_info['vendor_order_number']] = "Tracking number already assigned`";
                continue;
            }
            $ewaybill_no = '';
            if($order_info['order_total'] >= 50000){
                $this->result['error'][$order_info['vendor_order_number']] = "EwayBill no is required to process shipment >= 50000.";
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
            $payment_type='C';
            $collectable_amount = round($order_info['order_total']);            
            if($order_info['payment_mode']=='prepaid'){
                $payment_type = 'P';
                $collectable_amount =0;
            }
            $zipcode = $order_info['s_zipcode'] ?: $order_info['b_zipcode'];
            
            // if(!isset($serviceable_pincodes[$payment_type]) || !in_array($zipcode,$serviceable_pincodes[$payment_type])){
            //     $this->result['error'][] = 'Pincode is not serviceable for this order id '.$order_info['vendor_order_number'];
            //     continue;
            // }
            $trackingNumber = $this->fetchWaybill($payment_type);
            if (empty($trackingNumber)) {
                $this->result['error'][] = "Tracking number not found for assigning more orders";
                break;
            }            
            $consignee_phone = isset($order_info['s_phone']) ? $order_info['s_phone'] : $order_info['b_phone'];
            $consignee_phone = preg_replace('/[^0-9]/', '', $consignee_phone);
            $consignee_phone = substr($consignee_phone, -10);
            $orderProducts = $order_info['order_products'];
            $shipment_items = [];     
            $quantity = 0;       
            foreach($orderProducts as $key=>$orderProduct){
                $quantity += $orderProduct['quantity'];
                $shipment_items[] = [
                    "ProductCategory"      => "Elecrotnics",
                    "ProductDesc"          => $orderProduct['product_name'],
                    "CGSTAmount"           => null,
                    "Discount"             => null,
                    "GSTTAXRateIGSTN"     => null,
                    "GSTTaxRateCGSTN"     => null,
                    "GSTTaxRateSGSTN"     => null,
                    "GSTTaxTotal"          => null,
                    "HSNCode"              => $orderProduct['hsn'], 
                    "IGSTAmount"           => null,
                    "SGSTAmount"           => null,
                    "TaxableValue"         => null, 
                ];

            }
            $packageInfo = [
                "AirWayBillNO" => $trackingNumber,
                "BusinessAccountName" => $this->business_account_name,
                "OrderNo" => $order_info['vendor_order_number'],
                "SubOrderNo" => $order_info['vendor_order_id'],
                "OrderType" => ($payment_type=='P')?"PrePaid":"COD",
                "CollectibleAmount" =>$collectable_amount,
                "DeclaredValue" => $order_info['order_total'],
                "PickupType" => "Vendor",
                "Quantity" => $quantity,
                "ServiceType" => $this->service_type,
                "DropDetails" => [
                    "Addresses" => [
                        [
                            "Address" => $order_info['s_complete_address'] ?: $order_info['b_complete_address'],
                            "City" =>  $order_info['s_city'] ?: $order_info['b_city'],
                            "EmailID" =>$order_info['email'],
                            "Name" => $order_info['s_fullname'] ?: $order_info['b_fullname'],
                            "PinCode" => $order_info['s_zipcode'] ?: $order_info['b_zipcode'],
                            "State" => $order_info['s_state_code'] ?: $order_info['b_state_code'],
                            "Type" => "Primary",
                        ]
                    ],
                    "ContactDetails" => [
                        [
                            "PhoneNo" => $consignee_phone,
                            "Type" => "Primary",
                            "VirtualNumber" => null,
                        ]
                    ],
                    "IsGenSecurityCode" => null,
                    "SecurityCode" => null,
                    "IsGeoFencingEnabled" => null,
                    "Latitude" => null,
                    "Longitude" => null,
                    "MaxThresholdRadius" => null,
                    "MidPoint" => null,
                    "MinThresholdRadius" => null,
                    "RediusLocation" => null,
                ],
                "PickupDetails" => [
                    "Addresses" => [
                        [
                            "Address" =>  $this->pickup_address->address,
                            "City" =>  $this->pickup_address->city,
                            "EmailID" =>  $this->pickup_address->email,
                            "Name" =>  $this->pickup_address->contact_person_name,
                            "PinCode" =>  $this->pickup_address->zipcode,
                            "State" =>  $this->pickup_address->state_code,
                            "Type" => "Primary",
                        ]
                    ],
                    "ContactDetails" => [
                        [
                            "PhoneNo" => $this->pickup_address->phone,
                            "Type" => "Primary",
                        ]
                    ],
                    "PickupVendorCode" => 'xyz'.$this->company_id,
                    "IsGenSecurityCode" => null,
                    "SecurityCode" => null,
                    "IsGeoFencingEnabled" => null,
                    "Latitude" => null,
                    "Longitude" => null,
                    "MaxThresholdRadius" => null,
                    "MidPoint" => null,
                    "MinThresholdRadius" => null,
                    "RediusLocation" => null,
                ],
                "RTODetails" => [
                    "Addresses" => [
                        [
                            "Address" => $this->return_address->address,
                            "City" => $this->return_address->city,
                            "EmailID" => $this->return_address->email,
                            "Name" =>  $this->return_address->contact_person_name,
                            "PinCode" => $this->return_address->zipcode,
                            "State" => $this->return_address->state_code,
                            "Type" => "Primary",
                        ]
                    ],
                    "ContactDetails" => [
                        [
                            "PhoneNo" => $this->return_address->phone,
                            "Type" => "Primary",
                        ]
                    ],
                ],
                "Instruction" => "",
                "CustomerPromiseDate" => null,
                "IsCommercialProperty" => null,
                "IsDGShipmentType" => null,
                "IsOpenDelivery" => null,
                "IsSameDayDelivery" => ($this->service_type=='SDD')?true:false,
                "ManifestID" => $manifest_id,
                "SenderName" => null,
                "PackageDetails" => [
                    "Dimensions" => [
                        "Height" => $order_info['package_height'],
                        "Length" => $order_info['package_length'],
                        "Width" => $order_info['package_breadth']
                    ],
                    "Weight" => [
                        "BillableWeight" => $order_info['package_dead_weight'],
                        "PhyWeight" => $order_info['package_dead_weight'],
                        "VolWeight" => "0.0",
                    ],
                ],
                "GSTMultiSellerInfo" => [
                    [
                        "BuyerGSTRegNumber" =>null,
                        "EBNExpiryDate" => null,
                        "EWayBillSrNumber" => $ewaybill_no,
                        "InvoiceDate" =>date('d-m-Y',strtotime($order_info['channel_order_date'])),
                        "InvoiceNumber" => $order_info["invoice_prefix"].$order_info["invoice_number"],
                        "InvoiceValue" => null,
                        "IsSellerRegUnderGST" => ($this->pickup_address->gstin)?"Yes":"No",
                        "SellerAddress" => rtrim(', ',($this->pickup_address->address.', '.$this->pickup_address->city.', '.$this->pickup_address->landmark)),
                        "SellerGSTRegNumber" => $this->pickup_address->gstin,
                        "SellerName" => $this->pickup_address->contact_person_name,
                        "SellerPincode" => $this->pickup_address->zipcode,
                        "SupplySellerStatePlace" => $this->pickup_address->state_code,
                        "HSNDetails" => $shipment_items,
                    ]
                ]
            ];
            if (ShipmentInfo::where('order_id', $order_id)->exists()) {
                $this->result['error'][$order_info['vendor_order_number']] = 'Tracking number already assigned';
                continue;
            }
            $apiUrl = 'https://apishipmentmanifestation.xbees.in/shipmentmanifestation/forward';        
           
            $headers = [
                'token' => $token,
                'Content-Type' => 'application/json',
                'versionnumber'=>$this->version
            ];
            
            $response = Http::withHeaders($headers)->post($apiUrl,$packageInfo);
            $response = $response->json(); 
            $ReturnMessage = $response['ReturnMessage']??'';           
            
            if($response['ReturnCode']=='100'){
                $pickup_address = $this->pickupAddressFormat($this->pickup_address);
                $return_address = $this->pickupAddressFormat($this->return_address);
                $other_details=[];
                $other_details['manifest_id'] = $manifest_id;
                $other_details['pickup_id'] = addslashes($response['TokenNumber']);
                    try{
                    DB::transaction(function () use (
                        $order_id,
                        $order_info,
                        $other_details,
                        $trackingNumber,
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
                         * STEP 2: Apply Freight (Wallet Debit)
                         * -------------------------------- */
                        app(SellerWalletService::class)->applyFreight([
                            'company_id'      => $this->company_id,
                            'order_id'        => $order_id,
                            'shipment_id'     => $shipment->id,
                            'courier_id'      => $this->courier_id,
                            'courier_code'    => 'xpressbees_postpaid',
                            'tracking_number' => $trackingNumber,
                            'amount'          => $rate['shipping_cost'],
                            'cod_charges'     => $rate['cod_charge'] ?? 0
                        ]);                   
                        /** -------------------------------
                        * STEP 3: add OrderCourierResponse
                        * -------------------------------- */
                        OrderCourierResponse::create([
                            'order_id' => $order_id,
                            'courier_code' => 'xpressbees_postpaid',
                            'courier_name' => null,
                            'response' => $other_details,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        /** -------------------------------
                        * STEP 4: Update Order Status
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

            }else{
                 if($response['ReturnCode']=='101' && $ReturnMessage=='Token expired'){
                    if (Cache::has("api_auth_token_xpressbees_postpaid_{$this->courier_id}_{$this->company_id}")) {
                        Cache::forget("api_auth_token_xpressbees_postpaid_{$this->courier_id}_{$this->company_id}");
                    }
                    $token = $this->authentication();
                }
               $this->result['error'][]  = $order_info['vendor_order_number'].' '.$ReturnMessage;
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
    public function fetchWaybill($payment_type){     
        $response =  DB::table('import_tracking_numbers')->where('used', 0)
        ->where('company_id', $this->company_id)
        ->where('courier_id', $this->courier_id)
        ->where('payment_type', $payment_type)
        ->select('tracking_number')->first();
        $tracking_number = !empty($response)? $response->tracking_number:0;
        return $tracking_number;
       
    }
    public function authentication(){
        $company_id = $this->company_id;
        $token = Cache::get("api_auth_token_xpressbees_postpaid_{$this->courier_id}_{$company_id}");        
        $apiUrl = 'https://userauthapis.xbees.in/api/auth/generateToken';       
        
        $postData = [
            'username' => $this->username,
            'password' => $this->password,
            'secretkey' => $this->secret_key,
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
            if (isset($responseData['code']) && $responseData['code'] == 200) {
                $token = $responseData['token'];
            }
            if($token){
                Cache::put("api_auth_token_xpressbees_postpaid_{$this->courier_id}_{$company_id}", $token, now()->addMinutes(30));
            }
            if($this->action == 'print_response'){
                $this->print_response['authentication']['auth_url'] = $apiUrl;
				$this->print_response['authentication']['body']=json_encode($postData);
                $this->print_response['authentication']['auth_response'] = $response;
                if (!isset($responseData['code']) || $responseData['code'] != 200) {
                    die();
                }
			}
        }        
        return $token;
    }
    // public function createPickup($manifest_id,$pickup_day=null){
        
    //     $apiUrl = 'https://apishipmentmanifestation.xbees.in/shipmentmanifestation/forward';
        
    //     $token = $this->authentication();
    //     $headers = [
    //         'token' => $token,
    //         'Content-Type' => 'application/json',
    //         'versionnumber'=>$this->version
    //     ];
    //     $pickupdate = $this->pickupDateTime($pickup_day);
    //     $pickup_time = $pickupdate['pickup_time'];
    //     $pickup_date = $pickupdate['pickup_date'];
    //     $name = $this->pickup_address->location_title;
    //     $insertData = [];
    //     $result=array();
    //     $ewaybill_no ='';
    //     foreach ($this->order_ids as $order_id) {
    //         $order_info = Order::with('orderProducts', 'orderTotals', 'shipmentInfo')->find($order_id)->toArray();
    //         $payment_type='COD';
    //         $collectable_amount = round($order_info['order_total']);            
    //         if(strtolower($order_info['payment_mode'])=='prepaid'){
    //             $payment_type = 'PrePaid';
    //             $collectable_amount =0;
    //         }
    //         if(empty($ewaybill_no) && $order_info['order_total']>=50000){
    //             $result['error'][] = $order_info['vendor_order_number'].' pickup is not created because ewaybill_no no is required';
    //             continue;

    //         }
    //         $shipment_info = $order_info['shipment_info']??[];
    //         $tracking_number = $shipment_info['tracking_id'];
    //        // return;
    //         $orderProducts = $order_info['order_products'];
    //         $shipment_items = [];     
    //         $quantity = 0;       
    //         foreach($orderProducts as $key=>$orderProduct){
    //             $quantity += $orderProduct['quantity'];
    //             $shipment_items[] = [
    //                 "ProductCategory"      => "Elecrotnics",
    //                 "ProductDesc"          => $orderProduct['product_name'],
    //                 "CGSTAmount"           => null,
    //                 "Discount"             => null,
    //                 "GSTTAXRateIGSTN"     => null,
    //                 "GSTTaxRateCGSTN"     => null,
    //                 "GSTTaxRateSGSTN"     => null,
    //                 "GSTTaxTotal"          => null,
    //                 "HSNCode"              => $orderProduct['hsn'], 
    //                 "IGSTAmount"           => null,
    //                 "SGSTAmount"           => null,
    //                 "TaxableValue"         => null, 
    //             ];

    //         }
    //         $consignee_phone = isset($order_info['s_phone']) ? $order_info['s_phone'] : $order_info['b_phone'];
    //         $consignee_phone = preg_replace('/[^0-9]/', '', $consignee_phone);
    //         $consignee_phone = substr($consignee_phone, -10);
    //         $data = [
    //             "AirWayBillNO" => $tracking_number,
    //             "BusinessAccountName" => $this->business_account_name,
    //             "OrderNo" => $order_info['vendor_order_number'],
    //             "SubOrderNo" => $order_info['vendor_order_id'],
    //             "OrderType" => $payment_type,
    //             "CollectibleAmount" =>$collectable_amount,
    //             "DeclaredValue" => $order_info['order_total'],
    //             "PickupType" => "Vendor",
    //             "Quantity" => $quantity,
    //             "ServiceType" => $this->service_type,
    //             "DropDetails" => [
    //                 "Addresses" => [
    //                     [
    //                         "Address" => $order_info['s_complete_address'] ?? $order_info['b_complete_address'],
    //                         "City" =>  $order_info['s_city'] ?? $order_info['b_city'],
    //                         "EmailID" =>$order_info['email'],
    //                         "Name" => $order_info['s_fullname'] ?? $order_info['b_fullname'],
    //                         "PinCode" => $order_info['s_zipcode'] ?? $order_info['b_zipcode'],
    //                         "State" => $order_info['s_state_code'] ?? $order_info['b_state_code'],
    //                         "Type" => "Primary",
    //                     ]
    //                 ],
    //                 "ContactDetails" => [
    //                     [
    //                         "PhoneNo" => $consignee_phone,
    //                         "Type" => "Primary",
    //                         "VirtualNumber" => null,
    //                     ]
    //                 ],
    //                 "IsGenSecurityCode" => null,
    //                 "SecurityCode" => null,
    //                 "IsGeoFencingEnabled" => null,
    //                 "Latitude" => null,
    //                 "Longitude" => null,
    //                 "MaxThresholdRadius" => null,
    //                 "MidPoint" => null,
    //                 "MinThresholdRadius" => null,
    //                 "RediusLocation" => null,
    //             ],
    //             "PickupDetails" => [
    //                 "Addresses" => [
    //                     [
    //                         "Address" =>  $this->pickup_address->address,
    //                         "City" =>  $this->pickup_address->city,
    //                         "EmailID" =>  $this->pickup_address->email,
    //                         "Name" =>  $this->pickup_address->contact_person_name,
    //                         "PinCode" =>  $this->pickup_address->zipcode,
    //                         "State" =>  $this->pickup_address->state_code,
    //                         "Type" => "Primary",
    //                     ]
    //                 ],
    //                 "ContactDetails" => [
    //                     [
    //                         "PhoneNo" => $this->pickup_address->phone,
    //                         "Type" => "Primary",
    //                     ]
    //                 ],
    //                 "PickupVendorCode" => 'xyz'.$this->company_id,
    //                 "IsGenSecurityCode" => null,
    //                 "SecurityCode" => null,
    //                 "IsGeoFencingEnabled" => null,
    //                 "Latitude" => null,
    //                 "Longitude" => null,
    //                 "MaxThresholdRadius" => null,
    //                 "MidPoint" => null,
    //                 "MinThresholdRadius" => null,
    //                 "RediusLocation" => null,
    //             ],
    //             "RTODetails" => [
    //                 "Addresses" => [
    //                     [
    //                         "Address" => $this->return_address->address,
    //                         "City" => $this->return_address->city,
    //                         "EmailID" => $this->return_address->email,
    //                         "Name" =>  $this->return_address->contact_person_name,
    //                         "PinCode" => $this->return_address->zipcode,
    //                         "State" => $this->return_address->state_code,
    //                         "Type" => "Primary",
    //                     ]
    //                 ],
    //                 "ContactDetails" => [
    //                     [
    //                         "PhoneNo" => $this->return_address->phone,
    //                         "Type" => "Primary",
    //                     ]
    //                 ],
    //             ],
    //             "Instruction" => "",
    //             "CustomerPromiseDate" => null,
    //             "IsCommercialProperty" => null,
    //             "IsDGShipmentType" => null,
    //             "IsOpenDelivery" => null,
    //             "IsSameDayDelivery" => ($this->service_type=='SDD')?true:false,
    //             "ManifestID" => $manifest_id,
    //             "SenderName" => null,
    //             "PackageDetails" => [
    //                 "Dimensions" => [
    //                     "Height" => $order_info['package_height'],
    //                     "Length" => $order_info['package_length'],
    //                     "Width" => $order_info['package_breadth']
    //                 ],
    //                 "Weight" => [
    //                     "BillableWeight" => $order_info['package_dead_weight'],
    //                     "PhyWeight" => $order_info['package_dead_weight'],
    //                     "VolWeight" => "0.0",
    //                 ],
    //             ],
    //             "GSTMultiSellerInfo" => [
    //                 [
    //                     "BuyerGSTRegNumber" =>null,
    //                     "EBNExpiryDate" => null,
    //                     "EWayBillSrNumber" => $ewaybill_no,
    //                     "InvoiceDate" =>date('d-m-Y',strtotime($order_info['channel_order_date'])),
    //                     "InvoiceNumber" => $order_info["invoice_prefix"].$order_info["invoice_number"],
    //                     "InvoiceValue" => null,
    //                     "IsSellerRegUnderGST" => ($this->pickup_address->gstin)?"Yes":"No",
    //                     "SellerAddress" => rtrim(', ',($this->pickup_address->address.', '.$this->pickup_address->city.', '.$this->pickup_address->landmark)),
    //                     "SellerGSTRegNumber" => $this->pickup_address->gstin,
    //                     "SellerName" => $this->pickup_address->contact_person_name,
    //                     "SellerPincode" => $this->pickup_address->zipcode,
    //                     "SupplySellerStatePlace" => $this->pickup_address->state_code,
    //                     "HSNDetails" => $shipment_items,
    //                 ]
    //             ]
    //         ];
    //         $response = Http::withHeaders($headers)->post($apiUrl,$data);
    //         $response = $response->json(); 
    //         $ReturnMessage = $response['ReturnMessage']??'';           
            
    //         if($response['ReturnCode']=='100'){
    //              $result['success'] = 'Pickup created successfully.';
    //             // Delete existing pickup records for the manifest_id and order_id
    //             DB::table('pickup')
    //                 ->where('manifest_id', $manifest_id)
    //                 ->where('order_id', $order_id)
    //                 ->delete();
    //             $pickedup_date = $pickup_date.' '.$pickup_time;
    //             // Prepare insert data
    //             $insertData[] = [
    //                 'manifest_id' => (int) $manifest_id,
    //                 'order_id' => (int) $order_id,
    //                 'pickup_id' => addslashes($response['TokenNumber']),
    //                 'pickup_time' => $pickedup_date,
    //                 'api_response' => json_encode($response),
    //                 'created_at' => now(),
    //                 'updated_at' => now()
    //             ];
    //             $pickedup_date = $pickup_date.' '.$pickup_time;
    //             DB::table('shipment_info')->where('order_id', $order_id)->update(['pickedup_date' => $pickedup_date,'pickup_id'=>addslashes($response['TokenNumber'])]);
    //         }else{
    //             if($response['ReturnCode']=='101' && $ReturnMessage=='Token expired'){
    //                 if (Cache::has("api_auth_token_xpressbees_postpaid_{$this->courier_id}_{$this->company_id}")) {
    //                     Cache::forget("api_auth_token_xpressbees_postpaid_{$this->courier_id}_{$this->company_id}");
    //                 }
    //                 $token = $this->authentication();
    //             }
    //             $result['error'][] = $order_info['vendor_order_number'].' '.$ReturnMessage;
    //         }

    //     }
    //     DB::table('pickup')->insert($insertData);  
    
    //     return $result;
    // }
    public function trackShipment($order_id,$tracking_number){
        
        
        $current_status_url = 'https://apishipmenttracking.xbees.in/GetCurrentShipmentStatus';
        $apiUrl = 'https://apishipmenttracking.xbees.in/GetShipmentAuditLog';
        
        $token = $this->authentication();
        $parentId = $this->courier_settings->courier?->parent_id;
        $data = array();
        $data['AWBNumber'] = $tracking_number;
        $orderService =new OrderService();
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'token' => $token,
            'versionnumber' => $this->version,
        ])->post($current_status_url, $data);
        
        $current_shipment_details = $response->json(); 
        $ReturnMessage = '';
        $scansdata = array();
        $CurrentShipment_Status='';
        if($current_shipment_details){
            foreach($current_shipment_details as $current_shipment_detail){                
                if($current_shipment_detail['ReturnCode']=='100'){
                    $CurrentShipmentStatus = $current_shipment_detail['CurrentShipmentStatus']??[];
                    $CurrentShipment_Status = $CurrentShipmentStatus['Status']??'';
                    $courier_status_mapping = CourierStatusMapping::where('courier_id', $parentId)
                        ->where('courier_status', $CurrentShipment_Status)
                        ->first();
                    if (!$courier_status_mapping) {
                        $courier_status_mapping = CourierStatusMapping::create([
                                'courier_id' => $parentId,
                                'courier_status' => $CurrentShipment_Status,
                                'shipment_status_code' => '',
                            ]
                        );
                    }
                    $current_shipment_status_code = ($courier_status_mapping->shipment_status_code)?$courier_status_mapping->shipment_status_code:$CurrentShipmentStatus['Status'];
                    $StatusDateTime = $CurrentShipmentStatus['StatusDateTime'];
                    $StatusDateTime = Carbon::createFromFormat('d-m-Y H:i:s', $StatusDateTime);
                    $StatusDateTime = $StatusDateTime->format('Y-m-d H:i:s');     
                    $scansdata['courier_id'] = $this->courier_id;  
                    $scansdata['tracking_number'] = $tracking_number;  
                    $scansdata['origin'] = $CurrentShipmentStatus['OriginLocation']??'';                 
                    $scansdata['destination'] = $CurrentShipmentStatus['FinalDestinationName']??''; 
                    $scansdata['current_status_code'] = $current_shipment_status_code;
                    $scansdata['current_status_date'] = $StatusDateTime;
                    $scansdata['pickup_date'] =  '';
                    $scansdata['expected_delivery_date'] = ''; 
                    $scansdata['pod'] =''; 
                    $scansdata['scans'] = []; 
                    //scan history

                    $response = Http::withHeaders([
                        'Content-Type' => 'application/json',
                        'token' => $token,
                        'versionnumber' => $this->version,
                    ])->post($apiUrl, $data);
                    $trackingdata = $response->json(); 
                    $ReturnMessage = $trackingdata['ReturnMessage']??'';  
                    
                    if($trackingdata['ReturnCode']=='100'){
                        $scans = $trackingdata['ShipmentLogDetails']??[];
                        if($scans){
                            foreach($scans as $ScanDetail){
                                $status_date = $ScanDetail['ShipmentStatusDateTime'];               
                                $status_date = Carbon::createFromFormat('d-m-Y H:i:s', $status_date);
                                $status_date = $status_date->format('Y-m-d H:i:s');                            
                                $trackingHistories = array();                
                                $trackingHistories['status'] = !empty($ScanDetail['Description'])?$ScanDetail['Description']:$ScanDetail['Process'];
                                $courier_status_mapping = CourierStatusMapping::where('courier_id', $parentId)
                                ->where('courier_status', $ScanDetail['Description'])
                                ->first();
                                if (!$courier_status_mapping) {
                                    $courier_status_mapping = CourierStatusMapping::create([
                                            'courier_id' => $parentId,
                                            'courier_status' => $ScanDetail['Description'],
                                            'shipment_status_code' => '',
                                        ]
                                    );
                                }
                                
                                $trackingHistories['date'] = $status_date;
                                $shipment_status_code = $courier_status_mapping->shipment_status_code??$ScanDetail['Description'];
                                
                                $trackingHistories['current_status_code'] =$shipment_status_code;     
                                $trackingHistories['location'] = $ScanDetail['City']; 
                                $scansdata['scans'][] = $trackingHistories;
                            } 

                        }  

                    }else{
                        if($trackingdata['ReturnCode']=='101' && $ReturnMessage=='Token expired'){
                            if (Cache::has("api_auth_token_xpressbees_postpaid_{$this->courier_id}_{$this->company_id}")) {
                                Cache::forget("api_auth_token_xpressbees_postpaid_{$this->courier_id}_{$this->company_id}");
                            }
                            $token = $this->authentication();
                        }
                    }
                }else{
                    $ReturnMessage = $current_shipment_detail['ReturnMessage']??'';  
                    if($current_shipment_detail['ReturnCode']=='101' && $ReturnMessage=='Token expired'){
                        if (Cache::has("api_auth_token_xpressbees_postpaid_{$this->courier_id}_{$this->company_id}")) {
                            Cache::forget("api_auth_token_xpressbees_postpaid_{$this->courier_id}_{$this->company_id}");
                        }
                        $token = $this->authentication();
                    }
                }

            }

        }
        $res = array();
        if($scansdata){
            if (empty($scansdata['scans']) && !empty($scansdata['current_status_code'])) {
                $scansdata['scans'] = [
                    [
                        'date' => $scansdata['current_status_date'] ?? '',
                        'current_status_code' => $scansdata['current_status_code'] ?? '',
                        'status' => $CurrentShipment_Status,
                        'location' => ''
                    ]
                ];
            }
            $res = $orderService->addShipmentTrackDetails($order_id,$scansdata);
        }else{
            $res['error'] = $ReturnMessage;
        }
        return $scansdata;

    }
    public function cancelShipments() {        
        $apiUrl = 'https://clientshipupdatesapi.xbees.in/forwardcancellation';    
        $token = $this->authentication(); 
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
                            "ShippingID" => $shipmentInfo->tracking_id,
                            "CancellationReason" => "Cancel By Customer"
                        ];
                        $response = Http::withHeaders([
                            'Content-Type' => 'application/json',
                            'token' => $token,
                            'versionnumber' => $this->version,
                        ])->post($apiUrl, $requestPayloads);

                        
                        $responseBody = $response->json();
                        $ReturnMessage = $responseBody['ReturnMessage']??'';
                       
                        if($responseBody['ReturnCode']=='100'){
                            DB::transaction(function () use ($orderId, $shipmentInfo) {
                                app(SellerWalletService::class)->revertFreight([
                                    'company_id'      => $shipmentInfo->company_id,
                                    'shipment_id'     => $shipmentInfo->id,
                                    'tracking_number' => $shipmentInfo->tracking_id,
                                ]);
                                ShipmentInfo::where('order_id', $orderId)->delete();
                                OrderCourierResponse::where('order_id', $orderId)->delete();
                                Order::where('id', $orderId)->update(['status_code' => 'N']);
                            });
                            // DB::transaction(function () use ($orderId) {
                            // ShipmentInfo::where('order_id', $orderId)->delete();
                            // OrderCourierResponse::where('order_id', $orderId)->delete();
                            // Order::where('id', $orderId)->update([
                            //         'status_code' => 'N'
                            //     ]);
                            // });
                            $this->result['success'][] = $orderId .' is canceled successfully';
                        }else{
                            if($responseBody['ReturnCode']=='101' && $ReturnMessage=='Token expired'){
                                if (Cache::has("api_auth_token_xpressbees_postpaid_{$this->courier_id}_{$this->company_id}")) {
                                    Cache::forget("api_auth_token_xpressbees_postpaid_{$this->courier_id}_{$this->company_id}");
                                }
                                $token = $this->authentication();
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
    // public function pickupDateTime($pickup_day=null){
    //     $now = Carbon::now();

    //     if ($pickup_day === 1) {
    //         if ($now->lt(Carbon::createFromTime(14, 0))) {
    //             // Before 2 PM – Pickup today at 2 PM
    //             $pickup = $now->copy()->setTime(14, 0);
    //         } elseif ($now->between(Carbon::createFromTime(14, 0), Carbon::createFromTime(16, 0))) {
    //             // Between 2 PM and 4 PM – Pickup today at 5 PM
    //             $pickup = $now->copy()->setTime(17, 0);
    //         } else {
    //             // After 4 PM – Pickup next day at 10 AM
    //             $pickup = $now->copy()->addDay()->setTime(10, 0);
    //         }
    //     } else {
    //         // If pickup_day is not 1 – Pickup next day at 10 AM
    //         $pickup = $now->copy()->addDay()->setTime(10, 0);
    //     }

    //     return [
    //         'pickup_date' => $pickup->format('Y-m-d'),
    //         'pickup_time' => $pickup->format('H:i:s'),
    //     ];
    // }
    // public function getServiceablePincodes($courier_id, $company_id)
    // {
    //     $uploaded_pincodes = ImportPincodeNumber::where('courier_id', $courier_id)
    //         ->where('company_id', $company_id)
    //         ->get();

    //     if ($uploaded_pincodes->isEmpty()) {
    //         return false;
    //     }

    //     $serviceable_pincodes = $uploaded_pincodes->groupBy('payment_type')
    //         ->map(function ($group) {
    //             return $group->pluck('zipcodes')->toArray();
    //         })->toArray();

    //     return $serviceable_pincodes;
    // }
}