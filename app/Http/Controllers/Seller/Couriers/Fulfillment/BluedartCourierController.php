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
use App\Models\OrderCourierResponse;
use Carbon\Carbon;
use App\Services\ShippingRateService;
use App\Services\SellerWalletService;
use App\Services\OrderShipmentService;
class BluedartCourierController extends Controller
{
    private $order_ids 		= array();
	private $courier_id 		= 0;
	private $company_id 		= 0;
    private $courier_settings 	= array();
    private $api_mode = '';
    private $login_id ='';
    private $licence_key ='';
    private $client_id ='';
    private $client_secret ='';
    private $env_type='';
    private $api_url='';
    private $service_type = '';
    public $pickup_address = array();
    public $return_address = array();
    public $errors = array();
    public $result=array();
    public $action="";
    public $print_response=array();
    public $parent_company_id=0;
    public $parent_courier_id=2;
    public function __construct($order_ids = array() , $courier_id = 0 , $company_id = 0,$courier_settings=array()){
		$this->order_ids 	= $order_ids;
		$this->courier_id 	= $courier_id;
		$this->company_id 	= ($company_id) ? $company_id : session('company_id');
        $this->courier_settings = $courier_settings;
        $this->parent_company_id = $courier_settings['company_id']??0;
        $courier_details = ($courier_settings->courier_details)?json_decode($courier_settings['courier_details'],true):array();
        $this->client_id = $courier_details['client_id']??'';
        $this->client_secret = $courier_details['client_secret']??'';
        $this->login_id = $courier_details['login_id']??'';
        $this->licence_key = $courier_details['licence_key']??'';
        $this->tracking_key = $courier_details['tracking_key']??'';
        $this->service_type =$courier_details['service_type']??'A';
        $this->origin_area = $courier_details['origin_area']??'';
        $this->customer_code = $courier_details['customer_code']??'';
        $this->env_type = $courier_settings['env_type']??'dev';
        if($this->env_type=='dev'){
            $this->api_url = 'https://apigateway-sandbox.bluedart.com/in/transportation';
        }else{
            $this->api_url = 'https://apigateway.bluedart.com/in/transportation';
        }
    }
	public function assignTrackingNumber()
    {
        $token = $this->authentication();
        if(empty($token)){
            $this->result['error'][] = 'Invalid courier credentials';
            if($this->action=='print_response'){
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
            $packtype = "";
            $product_code = $this->service_type;         
            $sub_product_code = $order_info['payment_mode'] === 'prepaid'?'P':'C';  
            $is_b2b = 0;
            if(strlen($product_code)==2){
                //B2C condition for Bhart dart start-----------------                
                $product_code = $product_code['0'];
                $packtype = "L";
                // B2C condition for Bhart dart start-----------------
            }elseif(strlen($product_code) == 4){
                //B2B conditions start----------------------      
                $is_b2b = 1;      
                $product_code = $product_code['0'];
                $sub_product_code = '';
                //B2B conditions end----------------------
            }           
            $invoice_number = $order_info['invoice_number']??'';
            $invoice_prefix = $order_info['invoice_prefix']??'';
            $invoice_number = trim($invoice_prefix.$invoice_number);
            $invoiceDate = $order_info['channel_order_date'];
            $invoiceDate = Carbon::parse($invoiceDate); 
            $invoice_date = "/Date(" . ($invoiceDate->timestamp * 1000) . ")/";
            $orderProducts = $order_info['order_products'];
            $shipment_items = [];     
            $itemcount = 0;       
            foreach($orderProducts as $key=>$orderProduct){
                $itemcount += $orderProduct['quantity'];
                $shipment_items[$key] =[
                    "CGSTAmount" => 0,
                    "HSCode" => $orderProduct['hsn'], 
                    "IGSTAmount" => 0,
                    "IGSTRate" => 0,
                    "Instruction" => "",
                    "InvoiceDate" =>$invoice_date,
                    "InvoiceNumber" => "",
                    "ItemID" =>$orderProduct['id'],
                    "ItemName" => $orderProduct['product_name'],
                    "ItemValue" => $orderProduct['unit_price'],
                    "Itemquantity" => $orderProduct['quantity'],
                    "PlaceofSupply" => "",
                    "ProductDesc1" => "",
                    "ProductDesc2" => "",
                    "ReturnReason" => "",
                    "SGSTAmount" => 0,
                    "SKUNumber" => $orderProduct['sku'],
                    "SellerGSTNNumber" => $this->pickup_address->gstin,
                    "SellerName" => $this->pickup_address->brand_name,
                    "TaxableAmount" => 0,
                    "TotalValue" => $orderProduct['total_price'],
                    "cessAmount" => "0.0",
                    "countryOfOrigin" =>$this->pickup_address->country_code,
                    "docType" => "INV",
                    "subSupplyType" => 1,
                    "supplyType" => "0"
                ];

            }
            $consignee_phone = isset($order_info['s_phone']) ? $order_info['s_phone'] : $order_info['b_phone'];
            $consignee_phone = preg_replace('/[^0-9]/', '', $consignee_phone);
            $consignee_phone = substr($consignee_phone, -10);
            $full_address = $order_info['s_complete_address'] ?? $order_info['b_complete_address'];
            $lineLength=30;
            $add1 = substr($full_address, 0, $lineLength);
            $add2 = substr($full_address, $lineLength, $lineLength);
            $add3 = substr($full_address, $lineLength * 2, $lineLength);

            $pickup_day = $this->pickup_address->pickup_day;           
            $pickupdate = $this->pickupDateTime($pickup_day);
            $pickup_time = $pickupdate['pickup_time'];
            $pickup_date = $pickupdate['pickup_date'];

            // Convert time to Hi format (e.g. 14:30:00 => 1430)
            $formattedPickupTime = Carbon::createFromFormat('H:i:s', $pickup_time)->format('Hi');

            // Combine date and time to form a Carbon instance
            $pickupDateTime = Carbon::createFromFormat('Y-m-d H:i:s', $pickup_date . ' ' . $pickup_time);

            // Format to /Date(timestamp)/
            $formattedPickupDate = "/Date(" . ($pickupDateTime->timestamp * 1000) . ")/";
            
            $packageInfo = [
                "Request" => [
                    "Consignee" => [
                        "AvailableDays" => "",
                        "AvailableTiming" => "",
                        "ConsigneeAddress1" => $add1,
                        "ConsigneeAddress2" => $add2,
                        "ConsigneeAddress3" => $add3,
                        "ConsigneeAddressType" => "",
                        "ConsigneeAddressinfo" => "",
                        "ConsigneeAttention" => "",
                        "ConsigneeEmailID" => $order_info['email']??"",
                        "ConsigneeFullAddress" => $order_info['s_complete_address'] ?? $order_info['b_complete_address'],
                        "ConsigneeGSTNumber" => "",
                        "ConsigneeLatitude" => "",
                        "ConsigneeLongitude" => "",
                        "ConsigneeMaskedContactNumber" => "",
                        "ConsigneeMobile" => $consignee_phone,
                        "ConsigneeName" => $order_info['s_fullname'],
                        "ConsigneePincode" => $order_info['s_zipcode'] ?? $order_info['b_zipcode'],
                        "ConsigneeTelephone" => ""
                    ],
                    "Returnadds" => [
                        "ManifestNumber" => "",
                        "ReturnAddress1" => $this->pickup_address->address,
                        "ReturnAddress2" => $this->pickup_address->city,
                        "ReturnAddress3" => $this->pickup_address->state_code,
                        "ReturnAddressinfo" => "",
                        "ReturnContact" => $this->pickup_address->contact_person_name,
                        "ReturnEmailID" => $this->pickup_address->email,
                        "ReturnLatitude" => "",
                        "ReturnLongitude" => "",
                        "ReturnMaskedContactNumber" => "",
                        "ReturnMobile" => $this->pickup_address->phone,
                        "ReturnPincode" => $this->pickup_address->zipcode,
                        "ReturnTelephone" => ""
                    ],
                    "Services" => [
                        "AWBNo" => "",
                        "ActualWeight" => $order_info['package_dead_weight'] ?? 0.05,
                        "CollectableAmount" => ($is_b2b==1)?0:($order_info['payment_mode'] === 'prepaid' ? 0: $order_info['order_total']) ,
                        "Commodity" => [],
                        "CreditReferenceNo" => $order_info['vendor_order_number'] ?? $order_info['id'],
                        "CreditReferenceNo2" => "",
                        "CreditReferenceNo3" => "",
                        "CurrencyCode" => $order_info['currency_code']??"",
                        "DeclaredValue" => $order_info['order_total']??"0",
                        "DeliveryTimeSlot" => "",
                        "Dimensions" => [
                            [
                                "Breadth" => $order_info['package_breadth'] ?? 10,
                                "Count" => 1,
                                "Height" => $order_info['package_height'] ?? 10,
                                "Length" => $order_info['package_length'] ?? 10,
                            ]
                        ],
                        "FavouringName" => "",
                        "ForwardAWBNo" => "",
                        "ForwardLogisticCompName" => "",
                        "InsurancePaidBy" => "",
                        "InvoiceNo" => "",
                        "IsChequeDD" => "",
                        "IsDedicatedDeliveryNetwork" => false,
                        "IsForcePickup" => false,
                        "IsPartialPickup" => false,
                        "IsReversePickup" => false,
                        "ItemCount" => $itemcount,
                        "OTPBasedDelivery" => "0",
                        "OTPCode" => "",
                        "Officecutofftime" => "",
                        "PDFOutputNotRequired" => true,
                        "PackType" =>$packtype,
                        "ParcelShopCode" => "",
                        "PayableAt" => "",
                        "PickupDate" => $formattedPickupDate,
                        "PickupMode" => "P",
                        "PickupTime" => $formattedPickupTime,
                        "PickupType" => "",
                        "PieceCount" => "1",
                        "PreferredPickupTimeSlot" => "",
                        "ProductCode" => $product_code,
                        "ProductFeature" => "",
                        "ProductType" => 1,
                        "RegisterPickup" => true,
                        "SpecialInstruction" => "",
                        "SubProductCode" => $sub_product_code,
                        "TotalCashPaytoCustomer" => 0,
                        "itemdtl" => $shipment_items,
                        "noOfDCGiven" => 0
                    ],
                    "Shipper" => [
                        "CustomerAddress1" => $this->pickup_address->address,
                        "CustomerAddress2" => $this->pickup_address->city,
                        "CustomerAddress3" => $this->pickup_address->state_code,
                        "CustomerAddressinfo" => "",
                        "CustomerCode" => $this->customer_code,
                        "CustomerEmailID" => $this->pickup_address->email,
                        "CustomerGSTNumber" => "",
                        "CustomerLatitude" => "",
                        "CustomerLongitude" => "",
                        "CustomerMaskedContactNumber" => "",
                        "CustomerMobile" => $this->pickup_address->phone,
                        "CustomerName" =>$this->pickup_address->contact_person_name,
                        "CustomerPincode" => $this->pickup_address->zipcode,
                        "CustomerTelephone" => "",
                        "IsToPayCustomer" => false,
                        "OriginArea" => $this->origin_area,
                        "Sender" => $this->pickup_address->location_title,
                        "VendorCode" => ""
                    ]
                ],
                "Profile" => [
                    "LoginID" => $this->login_id,
                    "LicenceKey" => $this->licence_key,
                    "Api_type" => "S"
                ]
            ];
            if (ShipmentInfo::where('order_id', $order_id)->exists()) {
                $this->result['error'][$order_info['vendor_order_number']] = 'Tracking number already assigned';
                continue;
            }
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'JWTToken' => $token,
            ])->post("{$this->api_url}/waybill/v1/GenerateWayBill", $packageInfo);
            $response = $response->json();   
            if(isset($response['title']) && $response['title']=='Unauthorized'){
                if (Cache::has("api_auth_token_bluedart_{$this->courier_id}_{$this->company_id}")) {
                    Cache::forget("api_auth_token_bluedart_{$this->courier_id}_{$this->company_id}");
                }
                $this->authentication();
            }          
            $pickup_address = $this->pickupAddressFormat($this->pickup_address);
            $return_address = $this->pickupAddressFormat($this->return_address);  
            if (isset($response['GenerateWayBillResult']) && $response['GenerateWayBillResult']) {
                $other_details = array();
                $GenerateWayBillResult = $response['GenerateWayBillResult'];
                $trackingNumber = $GenerateWayBillResult['AWBNo']??"";
                $is_error = $GenerateWayBillResult['IsError']??false;
                $result = $GenerateWayBillResult['Status']??[];
                $token_number = $GenerateWayBillResult['TokenNumber']??null;
                $other_details['cluster_code'] = $GenerateWayBillResult['ClusterCode']??null;
                $other_details['destination_area'] = $GenerateWayBillResult['DestinationArea']??null;
                $other_details['destination_location'] = $GenerateWayBillResult['DestinationLocation']??null;
                $ShipmentPickupDateRaw = $GenerateWayBillResult['ShipmentPickupDate'] ?? "";
                if(!empty($ShipmentPickupDateRaw)){
                    $other_details['shipment_pickup_date'] = $ShipmentPickupDateRaw;
                }
               
                $is_awb_generated=0;
                $is_pickup_generated=0;
               
                if($result){
                    foreach($result as $tracking_status){
                        if($tracking_status['StatusCode']=='Valid' || strpos($tracking_status['StatusInformation'], 'Waybill already genereated') !== false ){                            
                            if (empty($trackingNumber) && preg_match('/Waybill No\s*:\s*(\d+)/', $tracking_status['StatusInformation'], $matches)) {
                                $StatusInformation = explode(':',$tracking_status['StatusInformation']);                        
                                $trackingNumber = str_replace('Dest Area','',$StatusInformation['1']);
                                $trackingNumber = trim($trackingNumber);
                                $DestinationArea = str_replace('Dest Scrcd','',$StatusInformation['2']);
                                $other_details['destination_area'] = trim($DestinationArea);
                                $other_details['destination_location'] = trim($StatusInformation['3']);
                            }
                            $is_awb_generated = 1;
                        }elseif($tracking_status['StatusCode']=='Pickup Registration:Valid' || $tracking_status['StatusInformation']=='PickupIsAlreadyRegister'){
                            $is_pickup_generated = 1;
                        }
                    }
                }
                if ($trackingNumber) {
                    try{
                        DB::transaction(function () use (
                            $order_id,
                            $order_info,
                            $other_details,
                            $trackingNumber,
                            $pickup_address,
                            $return_address,
                            $token_number,
                            $rate
                        ) {

                            /** -------------------------------
                             * STEP 1: Create Shipment
                             * -------------------------------- */
                            $shipment = ShipmentInfo::firstOrCreate([
                                'order_id' => $order_id],[
                                'company_id' => $this->company_id,
                                'shipment_type' => $this->service_type,
                                'courier_id' => $this->courier_id,
                                'tracking_id' => $trackingNumber,
                                'applied_weight' => $rate['chargeable_weight']??0,
                                'pickedup_location_id' => $this->pickup_address->id,
                                'pickedup_location_address' => $pickup_address,
                                'return_location_id' => $this->return_address->id,
                                'return_location_address' => $return_address,
                                'manifest_created' => 0,
                                'pickup_id' => $token_number ?? null,
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
                                'courier_code'    => 'bluedart',
                                'tracking_number' => $trackingNumber,
                                'amount'          => $rate['shipping_cost'],
                                'cod_charges'     => $rate['cod_charge']??0
                            ]);

                            

                            /** -------------------------------
                             * STEP 3: Update ledger with real shipment_id
                             * -------------------------------- */
                            SellerWalletLedger::where([
                                'company_id'      => $this->company_id,
                                'order_id'        => $order_id,
                                'tracking_number' => $trackingNumber,
                                'transaction_type'=> 'freight_charge',
                            ])->latest()->update([
                                'shipment_id' => $shipment->id,
                            ]);

                            /** -------------------------------
                             * STEP 4: Courier response
                             * -------------------------------- */
                            OrderCourierResponse::create([
                                'order_id' => $order_id,
                                'courier_code' => 'bluedart',
                                'courier_name' => 'Bluedart',
                                'response' => $other_details ?? [],
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);

                            /** -------------------------------
                             * STEP 5: Update Order Status
                             * -------------------------------- */
                            Order::where('id', $order_id)->update([
                                'status_code' => 'P',
                                'rate_card_id' => $rate['rate_card_id']??null,
                            ]);
                        });

                        $this->result['success'] = true;
                    }catch(\Exception $e){
                        Log::error("Bluedart Shipment Creation Failed for Order ID {$order_id}: ".$e->getMessage());
                        $this->result['error'][$order_info['vendor_order_number']] = "Shipment creation failed due to internal error.".$e->getMessage();
                        continue;
                    }
                }
                // if($trackingNumber){
                //     ShipmentInfo::create([
                //         'order_id' => $order_id,
                //         'company_id' => $this->company_id,
                //         'shipment_type' => $this->service_type,
                //         'courier_id' => $this->courier_id,
                //         'tracking_id' => $trackingNumber,
                //         'pickedup_location_id' => $this->pickup_address->id,
                //         'pickedup_location_address' => $pickup_address,
                //         'return_location_id' => $this->return_address->id,
                //         'return_location_address' => $return_address,
                //         'manifest_created' => 0,
                //         'pickup_id' => $token_number,
                //         'payment_mode' => $order_info['payment_mode'],
                //         'created_at' => now(),
                //         'updated_at' => now(),
                //     ]);
                //     OrderCourierResponse::create([
                //         'order_id' => $order_id,
                //         'courier_code' => 'bluedart',
                //         'courier_name' => '',
                //         'response' => $other_details,
                //         'created_at' => now(),
                //         'updated_at' => now(),
                //     ]);
                //     Order::where('id', $order_id)->update(['status_code' => 'P']);               

                // }
                // $this->result['success'] = true;
                
            } else {
                $errorResponses = $response['error-response']??[];    
                if($errorResponses){
                    foreach($errorResponses as $errorresponse){    
                        $error_response=[];
                        $result = $errorresponse['Status']??[]; 
                        foreach($result as $tracking_status){                                        
                            if($tracking_status['StatusCode']=='Valid' || strpos($tracking_status['StatusInformation'], 'Waybill already genereated') !== false ){
                                if (preg_match('/Waybill No\s*:\s*(\d+)/', $tracking_status['StatusInformation'], $matches)) {
                                    $this->result['success'] = true;
                                    $StatusInformation = explode(':',$tracking_status['StatusInformation']);                        
                                    $trackingNumber = str_replace('Dest Area','',$StatusInformation['1']);
                                    $trackingNumber = trim($trackingNumber);
                                    $DestinationArea = str_replace('Dest Scrcd','',$StatusInformation['2']);
                                    $other_details['destination_area'] = trim($DestinationArea);
                                    $other_details['destination_location'] = trim($StatusInformation['3']);
                                    try{
                                        DB::transaction(function () use (
                                            $order_id,
                                            $order_info,
                                            $other_details,
                                            $trackingNumber,
                                            $pickup_address,
                                            $return_address,
                                            $token_number,
                                            $rate                                            
                                        ) {

                                            /** -------------------------------
                                             * STEP 1: Create Shipment
                                             * -------------------------------- */
                                            $shipment = ShipmentInfo::firstOrCreate([
                                                'order_id' => $order_id],[
                                                'company_id' => $this->company_id,
                                                'shipment_type' => $this->service_type,
                                                'courier_id' => $this->courier_id,
                                                'tracking_id' => $trackingNumber,
                                                'applied_weight' => $rate['chargeable_weight'],
                                                'pickedup_location_id' => $this->pickup_address->id,
                                                'pickedup_location_address' => $pickup_address,
                                                'return_location_id' => $this->return_address->id,
                                                'return_location_address' => $return_address,
                                                'manifest_created' => 0,
                                                'pickup_id' => $token_number ?? null,
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
                                                'courier_code'    => 'bluedart',
                                                'tracking_number' => $trackingNumber,
                                                'amount'          => $rate['shipping_cost'],
                                                'cod_charges'     => $rate['cod_charge'] ?? 0
                                            ]);

                                            /** -------------------------------
                                             * STEP 3: Courier response
                                             * -------------------------------- */
                                            OrderCourierResponse::create([
                                                'order_id' => $order_id,
                                                'courier_code' => 'bluedart',
                                                'courier_name' => '',
                                                'response' => $other_details ?? [],
                                                'created_at' => now(),
                                                'updated_at' => now(),
                                            ]);

                                            /** -------------------------------
                                             * STEP 5: Update Order Status
                                             * -------------------------------- */
                                            Order::where('id', $order_id)->update([
                                                'status_code' => 'P',
                                                'rate_card_id' => $rate['rate_card_id']??null,
                                            ]);
                                        });
                                    }catch(\Exception $e){
                                        Log::error("Bluedart Shipment Creation Failed for Order ID {$order_id}: ".$e->getMessage());
                                        $this->result['error'][$order_info['vendor_order_number']] = "Shipment creation failed due to internal error.".$e->getMessage();
                                        continue;
                                    }
                                    // ShipmentInfo::create([
                                    //     'order_id' => $order_id,
                                    //     'company_id' => $this->company_id,
                                    //     'shipment_type' => $this->service_type,
                                    //     'courier_id' => $this->courier_id,
                                    //     'tracking_id' => $trackingNumber,
                                    //     'pickedup_location_id' => $this->pickup_address->id,
                                    //     'pickedup_location_address' => $pickup_address,
                                    //     'return_location_id' => $this->return_address->id,
                                    //     'return_location_address' => $return_address,
                                    //     'manifest_created' => 0,
                                    //     'payment_mode' => $order_info['payment_mode'],
                                    //     'created_at' => now(),
                                    //     'updated_at' => now(),
                                    // ]);
                                    // OrderCourierResponse::create([
                                    //     'order_id' => $order_id,
                                    //     'courier_code' => 'bluedart',
                                    //     'courier_name' => '',
                                    //     'response' => $other_details,
                                    //     'created_at' => now(),
                                    //     'updated_at' => now(),
                                    // ]);
                                    // Order::where('id', $order_id)->update(['status_code' => 'P']); 
                                }else{
                                    $error_response[] = $tracking_status['StatusInformation']; 
                                }
                            }else{
                                if($tracking_status['StatusInformation']=='Destination area is invalid'){
                                    $error_response[] = "Pincode is not serviceble";
                                }else{
                                    $error_response[] = $tracking_status['StatusInformation'];
                                }
                               
                            }
                        }
                        $this->result['error'][$order_info['vendor_order_number']] = "Error: ".implode(',',$error_response);
                    }                   

                }else{
                    $this->result['error'][$order_info['vendor_order_number']] = "Error: ".$response['title']??'';
                }
                
            }
            if($this->action == 'print_response'){
                $this->print_response['assign']['url'] = $this->api_url."/token/v1/login";
				$this->print_response['assign']['header']=[
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'JWTToken' => $token,
                ];
                $this->print_response['assign']['request_data']=$packageInfo;
                $this->print_response['assign']['response_data'] = $response;
                $this->result['print_response'] = $this->print_response;
                return $this->result;
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
    public function authentication(){
        $company_id = $this->company_id;
        $token = Cache::get("api_auth_token_bluedart_{$this->courier_id}_{$company_id}");
        if (!$token) {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'ClientID' => $this->client_id,
                'clientSecret' => $this->client_secret,
            ])->get($this->api_url.'/token/v1/login');       
            $response = $response->json();
            $token = $response['JWTToken']??'';
            if($token){
                Cache::put("api_auth_token_bluedart_{$this->courier_id}_{$company_id}", $token, now()->addHours(23));
            }

            if($this->action == 'print_response'){
                $this->print_response['authentication']['auth_url'] = $this->api_url."/token/v1/login";
				$this->print_response['authentication']['auth_header']=[
                    'Content-Type' => 'application/json',
                    'ClientID' => $this->client_id,
                    'clientSecret' => $this->client_secret,
                ];
                $this->print_response['authentication']['auth_response'] = $response;
			}
        }        
        return $token;
    }
    public function trackShipment($order_id,$tracking_number){
        $parentId = $this->courier_settings->courier?->parent_id;
        $token = $this->authentication();
        if(empty($token)){
            $this->result['error'][] = 'Invalid courier credentials';
            return $this->result;
        }
        $orderService =new OrderService();
        $url = $this->api_url."/tracking/v1/shipment?handler=tnt&loginid=".$this->login_id."&numbers=".$tracking_number."&format=json&lickey=".$this->tracking_key."&scan=1&action=custawbquery&verno=1&awb=awb";
       
        $headers = [
            'JWTToken' => $token,
            'Content-Type' => 'application/json',
        ];
        $response = Http::withHeaders($headers)->get($url);
        $response = $response->json();
        
        if(isset($response['title']) && $response['title']=='Unauthorized'){
            if (Cache::has("api_auth_token_bluedart_{$this->courier_id}_{$this->company_id}")) {
                Cache::forget("api_auth_token_bluedart_{$this->courier_id}_{$this->company_id}");
            }
            $token = $this->authentication();
        }  
        $scansdata = array();
        $shipmentData = $response['ShipmentData']??[];
        if($shipmentData){
            $shipmentData = $shipmentData['Shipment']??[];
            foreach($shipmentData as $shipmentDetail){
                if($shipmentDetail['Status']=='Incorrect Waybill number or No Information'){
                    $this->result['error'][] = $shipmentDetail['Status'];
                    return $this->result;
                }
                $scansdata['courier_id'] = $this->courier_id;  
                $scansdata['tracking_number'] = $tracking_number;  
                $scansdata['origin'] = $shipmentDetail['Origin']??'';                 
                $scansdata['destination'] = $shipmentDetail['Destination']??'';
                $pickupDate = (!empty($shipmentDetail['PickUpDate']))?date('Y-m-d',strtotime($shipmentDetail['PickUpDate'])):''; 
                $pickupTime = (!empty($shipmentDetail['PickUpTime']))?date('H:i:s',strtotime($shipmentDetail['PickUpTime'])):''; 
                $scansdata['pickup_date'] = $pickupDate.' '.$pickupTime; 
                $scansdata['expected_delivery_date'] = (!empty($shipmentDetail['ExpectedDelivery']))?date('Y-m-d H:i:s',strtotime($shipmentDetail['ExpectedDelivery'])):''; 
                $current_status = $shipmentDetail['Status']??'';   
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
                $scansdata['current_status_code'] = $courier_status_mapping->shipment_status_code?$courier_status_mapping->shipment_status_code:$current_status;               
                $scansdata['pod'] = ''; 
                
                $scansdata['scans'] = [];                
                $scans = $shipmentDetail['Scans']??[];
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
                    $time =$ScanDetail['ScanTime']??'';
                    $date = (!empty($ScanDetail['ScanDate']))?date('Y-m-d',strtotime($ScanDetail['ScanDate'])):''; 
                    $trackingHistories['date'] =$date.' '.$time; 
                    $trackingHistories['location'] = $ScanDetail['ScannedLocation']; 
                    $scansdata['scans'][] = $trackingHistories;
                } 
                $scansdata['current_status_date'] = $scansdata['scans'][0]['date']??$scansdata['pickup_date']; 

            }
            
        }
        if($scansdata){
            $orderService->addShipmentTrackDetails($order_id,$scansdata);
        }
        return $scansdata;
    }
    public function cancelShipments(){
        if($this->order_ids){
            try {
                $token = $this->authentication(); 
                if (!$token) {
                    $this->result['error'][] = 'Token not generated.';
                    return $this->result;
                }
                foreach ($this->order_ids as $orderId) {
                    try {
                        $shipmentInfo = ShipmentInfo::with('courierResponse')->where('order_id', $orderId)->first();
                        $courierResponse = $shipmentInfo->courierResponse?->response;
                        // Now decode it manually
                        //$courierResponse = json_decode($jsonResponse, true);
                        $shipment_pickup_date = $courierResponse['shipment_pickup_date']??'';

                        $shipmentInfo = ShipmentInfo::where('order_id', $orderId)->first();
                            if (!$shipmentInfo) {
                                $this->result['error'][] = "Shipment info not found for Order ID $orderId";
                                continue; 
                            }
                            $pickupToken = $shipmentInfo->pickup_id;
                            if (!$shipment_pickup_date) {
                                $this->result['error'][] = "Invalid pickup date for Order ID $orderId";
                                continue;
                            }
                            

                            $payload = [
                                "request" => [
                                    "PickupRegistrationDate" =>$shipment_pickup_date,
                                    "Remarks" => "Cancel due to customer request",
                                    "TokenNumber" => $pickupToken // replace with dynamic if needed
                                ],
                                "profile" => [
                                    "Api_type" => "S",
                                    "LicenceKey" => $this->licence_key,
                                    "LoginID" => $this->login_id
                                ]
                            ];
                        $response = Http::withHeaders([
                            'Content-Type' => 'application/json',
                            'JWTToken' => $token
                     ])->post("{$this->api_url}/cancel-pickup/v1/CancelPickup", $payload);

                     if ($response->status() === 200) {
                        $responseBody = $response->json();
                        $statusCode = $responseBody[0]['StatusCode'] ?? '';
                        app(OrderShipmentService::class)->cancelOrderById($orderId);
                        // DB::transaction(function () use ($orderId, $shipmentInfo) {
                        //     app(SellerWalletService::class)->revertFreight([
                        //         'company_id'      => $shipmentInfo->company_id,
                        //         'shipment_id'     => $shipmentInfo->id,
                        //         'tracking_number' => $shipmentInfo->tracking_id,
                        //     ]);
                        //     ShipmentInfo::where('order_id', $orderId)->delete();
                        //     OrderCourierResponse::where('order_id', $orderId)->delete();
                        //     Order::where('id', $orderId)->update(['status_code' => 'N']);
                        // });
                        $message = $orderId." Order cancel successfully";
                        $this->result['success'][] = $message;
                     } elseif ($response->status() === 400) {
                        $responseBody = $response->json();
                        if (!empty($responseBody['error-response'][0])) {
                            $error = $responseBody['error-response'][0];
                            $statusCode = $error['StatusCode'] ?? 'UnknownStatusCode';
                            $statusInfo = $error['StatusInformation'] ?? 'No details provided';
                            if($statusCode == 'PickupAlreadyCancelled'){
                                app(OrderShipmentService::class)->cancelOrderById($orderId);
                                // DB::transaction(function () use ($orderId, $shipmentInfo) {
                                //     app(SellerWalletService::class)->revertFreight([
                                //         'company_id'      => $shipmentInfo->company_id,
                                //         'shipment_id'     => $shipmentInfo->id,
                                //         'tracking_number' => $shipmentInfo->tracking_id,
                                //     ]);

                                //     ShipmentInfo::where('order_id', $orderId)->delete();
                                //     OrderCourierResponse::where('order_id', $orderId)->delete();
                                //     Order::where('id', $orderId)->update(['status_code' => 'N']);
                                // });
                                $message = "$statusCode for Order ID $orderId";
                                $this->result['success'][] = $message;
                            }else{
                                $this->result['error'][] = "$statusCode: $statusInfo for Order ID $orderId";
                            }
                            
                        } else {
                            $this->result['error'][] = "Cancel pickup failed for Order ID $orderId";
                        }
                    }
                } catch (\Exception $e) {
                   $this->result['error'][] = "Something went wrong with Order ID $orderId: " . $e->getMessage();
                }
            }
            } catch (\Exception $e) {
                $this->result['error'][]="Somthing went wrong" . $e->getMessage();
            }
        }
        return $this->result;
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
    
}