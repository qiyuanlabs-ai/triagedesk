<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\KbArticle;
use App\Models\Payment;
use App\Models\TeamSkill;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_the_demo_seed_matches_the_plan(): void
    {
        $this->assertSame(40, Customer::count());
        $this->assertSame(60, Ticket::count());
        $this->assertSame(15, KbArticle::count());
        $this->assertSame(15, Ticket::whereNull('triaged_at')->count());
        $this->assertSame(6, Ticket::distinct()->whereNotNull('category')->count('category'));
        $this->assertTrue(Payment::where('description', 'like', '%duplicate%')->exists());
        $this->assertSame(1, TeamSkill::where('enabled', true)->count());
    }
}
