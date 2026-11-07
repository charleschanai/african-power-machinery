<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Any HTTP call the suite does not explicitly fake is a bug in the
         * test, and an expensive one.
         *
         * Without this, a developer who has AI_API_KEY or NEXTSMS_PASSWORD
         * exported in their shell makes the suite issue real, billed requests
         * to OpenAI and the SMS gateway. The AI tests in particular assert on
         * the model's phrasing, so they would be testing a live model instead
         * of the code.
         */
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        // Release any real time frozen by a test.
        \Carbon\Carbon::setTestNow();

        parent::tearDown();
    }
}
