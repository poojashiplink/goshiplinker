<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\UpdateShipmentStatusJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

class StoresShipmentStatusUpdate extends Command
{
    protected $signature = 'app:stores-shipment-status-update';
    protected $description = 'Update shipment status on stores for orders';

    public function handle()
    {
        $this->info("Dispatching shipment status jobs in batches with delays...");

        $batchSize = 1000;

        $query = DB::table('orders as o')
            ->join('order_products as op', function ($join) {
                $join->on('op.order_id', '=', 'o.id')
                    ->whereNotNull('op.fulfillment_id');
            })
            ->join('shipment_info as si', 'si.order_id', '=', 'op.order_id')
            ->join('channel_settings as cs', 'cs.channel_id', '=', 'o.channel_id')
           // ->where('o.company_id', 1)
            ->where('cs.status', 1)
            ->where('cs.channel_code', 'shopify')
            ->whereNotNull('si.current_status')
            ->whereIn('si.current_status',['SHB','PKS','PKP','INT','DEL','OFD','UND'])
            ->where(function ($q) {
                $q->whereNull('si.store_shipment_status')
                ->orWhereColumn('si.current_status', '!=', 'si.store_shipment_status');
            })
            ->where('si.fulfillment_status', 1)
            ->orderBy('si.updated_at', 'asc')
            ->select([
                'si.id',
                'o.vendor_order_id',
                'op.fulfillment_id',
                'si.current_status',
                'cs.channel_url',
                'cs.client_id',
                'cs.secret_key',
                'cs.other_details'
            ]);

        $query->chunkById($batchSize, function ($shipments) {
            $this->info("Dispatching batch of " . $shipments->count() . " jobs...");

            $batchJobs = [];
            $delaySeconds = 0;

            foreach ($shipments as $shipment) {
                $batchJobs[] = (new UpdateShipmentStatusJob($shipment))
                    ->delay(now()->addSeconds($delaySeconds));

                $delaySeconds += 2;
            }

            if (!empty($batchJobs)) {
                Bus::batch($batchJobs)
                    ->name('Update Shipment statuses Jobs')
                    ->dispatch();
            }
        }, 'si.id');

        $this->info("All batches dispatched successfully.");
    }
}
