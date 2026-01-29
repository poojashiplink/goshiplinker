<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeightDiscrepancy extends Model
{
    protected $fillable = [
        'company_id',
        'order_id',
        'shipment_id',
        'tracking_number',
        'sorting_machine_image',
        'applied_weight',
        'courier_weight',
        'difference_weight',
        'courier_length',
        'courier_breadth',
        'courier_height',
        'extra_charge',
        'wallet_ledger_id',
        'invoice_number',
        'source',
        'status',
        'dispute_reason',
        'dispute_deadline',
    ];

    /* ===============================
     | Relationships
     =============================== */

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function shipment()
    {
        return $this->belongsTo(ShipmentInfo::class, 'shipment_id');
    }

    public function walletLedger()
    {
        return $this->belongsTo(SellerWalletLedger::class, 'wallet_ledger_id');
    }
}
