<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests never call a real model: any request without a fake fails loudly.
        Http::preventStrayRequests();

        $this->withoutVite();
    }
}
