<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('weight_discrepancies', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('shipment_id')->index();
            $table->string('tracking_number', 100)->index();
            $table->string('sorting_machine_image',255)->nullable();
            // Weights
            $table->decimal('applied_weight', 8, 2)->comment('Seller declared weight');
            $table->decimal('courier_weight', 8, 2)->comment('Courier billed weight');            
            $table->decimal('difference_weight', 8, 2);

            // Dimensions
            $table->decimal('courier_length', 8, 2)->nullable();
            $table->decimal('courier_breadth', 8, 2)->nullable();
            $table->decimal('courier_height', 8, 2)->nullable();

            // Charges
            $table->decimal('extra_charge', 10, 2)->nullable()
                ->comment('Extra charge applied by courier');

            // Wallet linkage
            $table->unsignedBigInteger('wallet_ledger_id')->nullable()
                ->comment('FK to seller_wallet_ledger.id');

            // Invoice reference
            $table->string('invoice_number', 100)->nullable();
            $table->enum('source', ['cron', 'api', 'manual'])->default('cron');

            // Status lifecycle (SYSTEM LEVEL)
            $table->enum('status', [
                'new',
                'auto_accepted',
                'accepted',
                'dispute_raised',
                'dispute_accepted',
                'dispute_rejected',
                'closed'
            ])->default('new');

            // Dispute metadata
            $table->text('dispute_reason')->nullable();
            $table->timestamp('dispute_deadline')->nullable();

            $table->timestamps();

            // Idempotency protection
            $table->unique(
                ['shipment_id', 'courier_weight'],
                'uniq_shipment_weight_discrepancy'
            );
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('weight_discrepancies');
    }
};
