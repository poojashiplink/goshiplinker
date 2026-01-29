<?php

namespace App\Http\Controllers\Seller\Couriers\Fulfillment;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\ShippingRateService;
use App\Services\SellerWalletService;
use App\Services\OrderShipmentService;

class SelfshipCourierController extends Controller
{
    private array $order_ids = [];
    private int $courier_id;
    private int $company_id;
    private int $parent_company_id;
    private int $parent_courier_id=1;

    public $pickup_address;
    public $return_address;

    public array $result = [];

    public function __construct(
        array $order_ids = [],
        int $courier_id = 0,
        int $company_id = 0,
        $courier_settings = null
    ) {
        $this->order_ids         = $order_ids;
        $this->courier_id        = $courier_id;
        $this->company_id        = $company_id ?: session('company_id');
        $this->parent_company_id = $courier_settings->company_id ?? 0;
    }

    /**
     * Assign tracking numbers & create shipments
     */
    public function assignTrackingNumber(): array
    {
        $rateService     = app(ShippingRateService::class);
        $walletService   = app(SellerWalletService::class);
        $shipmentService = app(OrderShipmentService::class);

        foreach ($this->order_ids as $order_id) {

            $order = Order::with('shipmentInfo')->find($order_id);

            if (!$order) {
                continue;
            }

            // ❌ Already shipped
            if ($order->shipmentInfo) {
                $this->result['error'][$order->vendor_order_number] =
                    'Tracking number already assigned';
                continue;
            }

            $paymentMode = strtolower($order->payment_mode);
            $isCod       = $paymentMode === 'cod';

            /**
             * ----------------------------
             * STEP 1: Calculate Rate
             * ----------------------------
             */
            $rate = $rateService->calculate(
                $this->parent_company_id,
                $this->company_id,
                $this->courier_id,
                $this->parent_courier_id,
                $this->pickup_address->zipcode,
                $order->s_zipcode ?: $order->b_zipcode,
                $order->package_dead_weight ?? 0.5,
                $order->package_length ?? 10,
                $order->package_breadth ?? 10,
                $order->package_height ?? 10,
                (int) $isCod,
                $order->order_total
            );

            if (!isset($rate['shipping_cost'])) {
                $this->result['error'][$order->vendor_order_number] =
                    'Pincode is not serviceable';
                continue;
            }

            $freightCharge = $rate['shipping_cost'];//cod charge is included
            $codCharge     = $isCod ? ($rate['cod_charge'] ?? 0) : 0;

            /**
             * ----------------------------
             * STEP 2: Wallet Balance Check
             * ----------------------------
             */
            if (!$walletService->hasSufficientBalance($this->company_id, $freightCharge)) {
                $this->result['error'][$order->vendor_order_number] =
                    'Insufficient wallet balance (Freight + COD)';
                continue;
            }

            /**
             * ----------------------------
             * STEP 3: Fetch Waybill
             * ----------------------------
             */
            $trackingNumber = $shipmentService->fetchWaybill(
                $this->parent_company_id,
                $this->parent_courier_id
            );

            if (!$trackingNumber) {
                $this->result['error'][] = 'Tracking number pool exhausted';
                break;
            }

            /**
             * ----------------------------
             * STEP 4: DB Transaction
             * ----------------------------
             */
            try {
                DB::transaction(function () use (
                    $order,
                    $rate,
                    $trackingNumber,
                    $shipmentService,
                    $walletService,
                ) {
                    /**
                     * 4.1 Create Shipment
                     */
                    $shipment = $shipmentService->createShipmentRecord([
                        'order_id'            => $order->id,
                        'company_id'          => $this->company_id,
                        'courier_id'          => $this->courier_id,
                        'tracking_number'     => $trackingNumber,
                        'service_type'        => 'Surface',
                        'chargeable_weight'   => $rate['chargeable_weight'] ?? 0,
                        'pickup_address_id'   => $this->pickup_address->id,
                        'pickup_address'      => $shipmentService->pickupAddressFormat($this->pickup_address),
                        'return_address_id'   => $this->return_address->id,
                        'return_address'      => $shipmentService->pickupAddressFormat($this->return_address),
                        'payment_mode'        => $order->payment_mode,
                    ]);

                    /**
                     * 4.2 Debit Wallet
                     */
                    $walletService->applyFreight([
                        'company_id'      => $this->company_id,
                        'order_id'        => $order->id,
                        'shipment_id'     => $shipment->id,
                        'courier_id'      => $this->courier_id,
                        'courier_code'    => 'selfship',
                        'tracking_number' => $trackingNumber,
                        'amount'          => $rate['shipping_cost'],
                        'cod_charges'     => $rate['cod_charge'] ?? 0,
                    ]);

                    /**
                     * 4.3 Update Order Status
                     */
                    $shipmentService->moveOrderReadyToShipment(
                        $order->id,
                        $rate['rate_card_id'] ?? null
                    );

                    /**
                     * 4.4 Mark Waybill Used
                     */
                    $shipmentService->markWaybillUsed(
                        $this->parent_company_id,
                        $this->parent_courier_id,
                        $trackingNumber
                    );
                }, 3);

                $this->result['success'] = true;

            } catch (\Throwable $e) {
                Log::error('Selfship assign failed', [
                    'order_id' => $order_id,
                    'error'    => $e->getMessage()
                ]);

                $this->result['error'][$order->vendor_order_number] =
                    'Shipment creation failed';
            }
        }

        return $this->result;
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

            $this->result['error'][] = 'Something went wrong while cancelling orders';
        }

        return $this->result;
    }

    
    
}
