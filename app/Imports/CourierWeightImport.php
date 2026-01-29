<?php

namespace App\Imports;

use App\Models\ShipmentInfo;
use App\Models\WeightDiscrepancy;
use App\Models\SellerRateCard;
use App\Models\Order;
use App\Services\SellerWalletService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class CourierWeightImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {

            DB::transaction(function () use ($row) {

                $trackingNumber = trim($row['tracking_number'] ?? '');
                $courierWeight  = (float) ($row['courier_weightkg'] ?? 0);
                $length  = (float) ($row['length_cm'] ?? '');
                $breadth  = (float) ($row['breadth_cm'] ?? '');
                $height  = (float) ($row['height_cm'] ?? '');
                $sorting_machine_image = $row['sorting_machine_captured_image_link']??null;
                //\Log::info($row);
                if (!$trackingNumber || $courierWeight <= 0) {
                    return;
                }

                $shipment = ShipmentInfo::where('tracking_id', $trackingNumber)->first();
                if (!$shipment) {
                    return;
                }

                $order = Order::find($shipment->order_id);
                if (!$order || !$order->rate_card_id) {
                    return;
                }

                $rate = SellerRateCard::find($order->rate_card_id);
                if (!$rate || !$rate->weight_slab_kg) {
                    return;
                }

                // Weight calculations
                $appliedWeight = (float) $shipment->applied_weight;
                $slab          = $rate->weight_slab_kg;

                $appliedSlab = ceil($appliedWeight / $slab) * $slab;
                $courierSlab = ceil($courierWeight / $slab) * $slab;

                if ($courierSlab <= $appliedSlab) {
                    return;
                }

                $differenceWeight = $courierSlab - $appliedSlab;
                $ratePerSlab = (float) ($rate->additional_freight ?? 0);
                $slabDifference = $differenceWeight / $slab;
                $extraCharge = $slabDifference * $ratePerSlab;

                if ($extraCharge <= 0) {
                    return;
                }

                /**
                 * 🔒 Idempotency check
                 */
                $exists = WeightDiscrepancy::where('shipment_id', $shipment->id)
                    ->where('courier_weight', $courierSlab)
                    ->exists();

                if ($exists) {
                    return;
                }

                /**
                 * 1️⃣ Create discrepancy
                 */
                $discrepancy = WeightDiscrepancy::create([
                    'company_id'        => $shipment->company_id,
                    'order_id'          => $shipment->order_id,
                    'shipment_id'       => $shipment->id,
                    'tracking_number'   => $shipment->tracking_id,
                    'sorting_machine_image'   => $sorting_machine_image,
                    'applied_weight'    => $appliedSlab,
                    'courier_weight'    => $courierSlab,
                    'difference_weight' => $differenceWeight,
                    'courier_length'    => $length??null,
                    'courier_breadth'   => $breadth??null,
                    'courier_height'    => $height??null,
                    'extra_charge'      => $extraCharge,
                    'status'            => 'new',
                    'source'            => 'cron',
                ]);

                /**
                 * 2️⃣ Apply wallet deduction
                 */
                $ledger = app(SellerWalletService::class)
                    ->applyWeightDiscrepancyCharge([
                        'company_id'      => $shipment->company_id,
                        'order_id'        => $shipment->order_id,
                        'shipment_id'     => $shipment->id,
                        'courier_id'      => $shipment->courier_id,
                        'tracking_number' => $shipment->tracking_id,
                        'amount'          => $extraCharge,
                    ]);

                /**
                 * 3️⃣ Link ledger to discrepancy
                 */
                if ($ledger) {
                    $discrepancy->update([
                        'wallet_ledger_id' => $ledger->id,
                    ]);
                }
            });
        }
    }
}
