<?php

namespace App\Services;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\OrderTotal;
use App\Models\TrackingHistory;
use App\Models\PaymentMapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Jobs\Shipmentmail;
use App\Jobs\SendSmsjob;
use App\Models\Notification;
use App\Models\SmsGateway;
use App\Models\OrderPackage;
use App\Models\CourierMapping;
use App\Models\ShipmentInfo;
use App\Models\ManifestOrder;
use App\Models\OrderCourierResponse;
use App\Services\SellerWalletService;
class OrderShipmentService
{
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
    public function fetchWaybill($company_id,$courier_id){
     
       $response =  DB::table('import_tracking_numbers')->where('used', 0)
       ->where('company_id', $company_id)
       ->where('courier_id', $courier_id)
       ->select('tracking_number')->first();
       $tracking_number = !empty($response)? $response->tracking_number:0;
       return $tracking_number;
      
    }

    public function markWaybillUsed($company_id,$courier_id,$tracking_number){
     
        DB::table('import_tracking_numbers')
        ->where('company_id', $company_id)
        ->where('courier_id', $courier_id)
        ->where('tracking_number', $tracking_number)
        ->update(['used' => 1, 'updated_at' => now()]);

    }
    public function moveOrderReadyToShipment($order_id,$rate_card_id=null){
        Order::where('id', $order_id)->update([
                    'status_code' => 'P',
                    'rate_card_id' => $rate_card_id
        ]);
    }

    public function createShipmentRecord($data){
        $shipment = ShipmentInfo::firstOrCreate([
            'order_id' => $data['order_id']],[
            'company_id' => $data['company_id'],
            'shipment_type' => $data['service_type']??'Surface',
            'courier_id' => $data['courier_id'],
            'tracking_id' => $data['tracking_number'],
            'applied_weight' => $data['chargeable_weight']??0,
            'pickedup_location_id' => $data['pickup_address_id'],
            'pickedup_location_address' => $data['pickup_address'],
            'return_location_id' => $data['return_address_id'],
            'return_location_address' => $data['return_address'],
            'manifest_created' => 0,
            'payment_mode' => $data['payment_mode'],
        ]);
        return $shipment;
        
    }
    public function cancelOrderById(int|array $orderIds): void
    {
        $orderIds = is_array($orderIds) ? $orderIds : [$orderIds];

        $walletService = app(SellerWalletService::class);

        DB::transaction(function () use ($orderIds, $walletService) {

            $shipments = ShipmentInfo::whereIn('order_id', $orderIds)->get();

            foreach ($shipments as $shipment) {

                // ✅ Revert freight PER shipment (idempotent inside service)
                $walletService->revertFreight([
                    'company_id'      => $shipment->company_id,
                    'shipment_id'     => $shipment->id,
                    'tracking_number' => $shipment->tracking_id,
                ]);
            }

            // ✅ Cleanup related data
            ShipmentInfo::whereIn('order_id', $orderIds)->delete();
            ManifestOrder::whereIn('order_id', $orderIds)->delete();
            OrderCourierResponse::whereIn('order_id', $orderIds)->delete();
            TrackingHistory::whereIn('order_id', $orderIds)->delete();

            // ✅ Reset order status
            Order::whereIn('id', $orderIds)->update([
                'status_code' => 'N'
            ]);
        }, 3); // retry deadlocks
    }

    

}
