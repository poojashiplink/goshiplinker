<?php

namespace App\Services;

use App\Models\CourierRateCard;
use App\Models\SellerRateCard;
class ShippingRateService
{
    public function calculate($companyId, $seller_company_id,$courierId, $parentCourierId, $origin_pincode, $destination_pincode, $weight, $length, $breadth, $height, $isCOD,$amount)
    {
        // Step 1: volumetric weight
        $volumetricWeight = ($length * $breadth * $height) / 5000;
        \Log::info($volumetricWeight);
        // Chargeable weight
        $chargeableWeight = max($weight, $volumetricWeight);
        // Step 2: Find the zone
        $zone = app(ZoneResolverService::class)->getZone($companyId,$seller_company_id,$parentCourierId,$origin_pincode, $destination_pincode,$isCOD);
        if (!$zone) {
            return ['error' => 'Zone not found'];
        }
        \Log::info("Zone: $zone");
        // Step 3: Find the slab
        if($seller_company_id>0){
            $rate = SellerRateCard::where('company_id', $seller_company_id);
            if($courierId>0){
                $rate =  $rate->where('courier_id', $courierId);
            }
            $rate = $rate->where('zone_name', $zone)->where('status',1);
            if($isCOD){
                $rate =  $rate->where('cod_allowed',$isCOD);
            }
            $rate =$rate->orderBy('weight_slab_kg')
            ->get();

        }else{
            $rate = CourierRateCard::where('company_id', $companyId);
            if($courierId>0){
                $rate =  $rate->where('courier_id', $courierId);
            }
            $rate = $rate->where('zone_name', $zone)->where('status',1);
            if($isCOD){
                $rate =  $rate->where('cod_allowed',$isCOD);
            }
            $rate =$rate->orderBy('weight_slab_kg')
            ->get();
        }
        if ($rate->isEmpty()){
            \Log::info("Rate card not found for Company ID: $companyId, Seller Company ID: $seller_company_id, Courier ID: $courierId, Zone: $zone, COD: $isCOD");
            return ['error' => 'Rate card not found'];
        }
        // Step 4: Calculate base freight
        $slab = $rate->where('weight_slab_kg', '>=', $chargeableWeight)->first();
       
        if (!$slab) {
            // Use last slab
            $slab = $rate->last();
        }
        $zone = $slab->zone_name;
        
        $total = $slab->base_freight_forward;
        // Step 5: Additional freight if weight above slab
        if ($chargeableWeight > $slab->weight_slab_kg) {
            $extra = ceil($chargeableWeight - $slab->weight_slab_kg);
            $total += $extra * $slab->additional_freight;
        }

        // Step 6: COD
        $codcharges=0;
        if ($isCOD) {
            $codcharges = ($amount * $slab->cod_percentage) / 100;
            $codcharges = max($codcharges,$slab->cod_charge);
            $total += $codcharges;
            
        }
        $delivery_sla = $slab->delivery_sla??'';
        return [
            'rate_card_id' => $slab->id,
            'chargeable_weight' => $chargeableWeight,
            'delivery_sla' => $delivery_sla,
            'zone' => $zone,
            'shipping_cost' => round($total, 2),
            'cod_charge' => round($codcharges, 2)
        ];
    }
}
