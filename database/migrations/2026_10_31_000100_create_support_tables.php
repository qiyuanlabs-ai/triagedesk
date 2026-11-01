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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('company')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('plan');
            $table->unsignedInteger('mrr_cents');
            $table->string('status');
            $table->timestamp('renews_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('refunded_cents')->default(0);
            $table->string('status');
            $table->string('description');
            $table->timestamp('paid_at');
            $table->timestamps();
        });

        Schema::create('kb_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject');
            $table->string('status')->default('new');
            $table->string('priority')->nullable();
            $table->string('category')->nullable();
            $table->string('sentiment')->nullable();
            $table->string('summary')->nullable();
            $table->timestamp('triaged_at')->nullable();
            $table->string('agent_conversation_id', 36)->nullable()->index();
            $table->string('agent_error')->nullable();
            $table->timestamps();
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->string('author_type');
            $table->string('author_name');
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->timestamps();
        });

        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->string('team');
            $table->string('name');
            $table->string('description');
            $table->text('instructions');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['team', 'name']);
        });

        Schema::create('agent_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('conversation_id', 36)->nullable()->index();
            $table->string('invocation_id', 36)->nullable()->index();
            $table->string('tool_call_id')->unique();
            $table->string('tool');
            $table->json('arguments');
            $table->json('edited_arguments')->nullable();
            $table->string('approval_reason')->nullable();
            $table->string('status');
            $table->boolean('required_approval')->default(false);
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->text('result')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_actions');
        Schema::dropIfExists('skills');
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('kb_articles');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('customers');
    }
};
