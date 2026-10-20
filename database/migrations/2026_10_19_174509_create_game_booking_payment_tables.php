<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_bookings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('game_id')->constrained()->restrictOnDelete();
            $table->foreignId('team_id')->constrained()->restrictOnDelete();
            $table->foreignId('captain_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('game_room_id')->nullable()->constrained('game_rooms')->restrictOnDelete();
            $table->string('status', 24)->index();
            $table->string('payment_mode', 16);
            $table->unsignedBigInteger('unit_amount');
            $table->unsignedBigInteger('total_amount');
            $table->char('currency', 3);
            $table->jsonb('roster_snapshot');
            $table->jsonb('pricing_snapshot');
            $table->string('idempotency_key', 64)->unique();
            $table->char('request_fingerprint', 64);
            $table->timestampTz('payment_deadline_at')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->index(['game_id', 'status', 'created_at']);
            $table->index(['team_id', 'status']);
        });
        DB::statement("ALTER TABLE game_bookings ADD CONSTRAINT game_bookings_status_check CHECK (status IN ('AWAITING_PAYMENT','PARTIALLY_PAID','PAID','ROOM_CREATED','EXPIRED','CANCELLED','REFUND_PENDING'))");
        DB::statement("ALTER TABLE game_bookings ADD CONSTRAINT game_bookings_mode_check CHECK (payment_mode IN ('FREE','PER_ROOM','PER_PLAYER'))");
        DB::statement('ALTER TABLE game_bookings ADD CONSTRAINT game_bookings_amount_check CHECK (unit_amount >= 0 AND total_amount >= 0)');

        Schema::create('booking_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_booking_id')->constrained('game_bookings')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('status', 16)->default('PENDING');
            $table->foreignId('payment_order_id')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->unique(['game_booking_id', 'user_id']);
            $table->index(['game_booking_id', 'status']);
        });
        DB::statement("ALTER TABLE booking_participants ADD CONSTRAINT booking_participants_status_check CHECK (status IN ('PENDING','PAID','CANCELLED','REFUND_PENDING','REFUNDED'))");

        Schema::create('payment_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('game_booking_id')->constrained('game_bookings')->restrictOnDelete();
            $table->foreignId('payer_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->string('status', 24)->index();
            $table->string('provider', 24)->nullable();
            $table->string('provider_reference', 160)->nullable()->unique();
            $table->string('idempotency_key', 64)->unique();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();
            $table->unique(['game_booking_id', 'payer_user_id']);
        });
        DB::statement("ALTER TABLE payment_orders ADD CONSTRAINT payment_orders_status_check CHECK (status IN ('PENDING','PROCESSING','PAID','FAILED','EXPIRED','CANCELLED','REFUND_PENDING','REFUNDED'))");
        DB::statement('ALTER TABLE payment_orders ADD CONSTRAINT payment_orders_amount_check CHECK (amount >= 0)');
        Schema::table('booking_participants', function (Blueprint $table): void {
            $table->foreign('payment_order_id')->references('id')->on('payment_orders')->restrictOnDelete();
        });

        Schema::create('payment_transactions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('payment_order_id')->constrained('payment_orders')->restrictOnDelete();
            $table->string('provider', 24);
            $table->string('provider_reference', 160)->nullable()->unique();
            $table->string('status', 24);
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->char('response_fingerprint', 64)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at');
            $table->index(['payment_order_id', 'occurred_at']);
        });
        DB::statement("ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_status_check CHECK (status IN ('PROCESSING','PAID','FAILED','REFUND_PENDING','REFUNDED'))");
        DB::unprepared('CREATE TRIGGER payment_transactions_append_only BEFORE UPDATE OR DELETE ON payment_transactions FOR EACH ROW EXECUTE FUNCTION reject_audit_event_mutation();');

        Schema::create('payment_refunds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('payment_order_id')->constrained('payment_orders')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('status', 24)->index();
            $table->string('provider_reference', 160)->nullable()->unique();
            $table->string('idempotency_key', 64)->unique();
            $table->string('reason', 1000);
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE payment_refunds ADD CONSTRAINT payment_refunds_status_check CHECK (status IN ('PENDING','PROCESSING','FAILED','REFUNDED'))");
        DB::statement('ALTER TABLE payment_refunds ADD CONSTRAINT payment_refunds_amount_check CHECK (amount > 0)');
    }

    public function down(): void
    {
        if (! app()->environment('testing')) {
            throw new LogicException('Booking and payment history cannot be removed by rolling back this migration.');
        }

        Schema::dropIfExists('payment_refunds');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('booking_participants');
        Schema::dropIfExists('payment_orders');
        Schema::dropIfExists('game_bookings');
    }
};
