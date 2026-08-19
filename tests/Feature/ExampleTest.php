<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_redirects_guests_to_login(): void
    {
        // Unmodified default Laravel scaffold test — '/' now requires auth and
        // redirects guests, so a plain 200 was never realistic for this app.
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }
}
