<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

// AuthorizesRequests isn't included by default in Laravel 11+'s base
// Controller - added here because the Tan90 Master Data/BOM controllers'
// generic CRUD pattern (`$this->authorize(...)`) depends on it. GRN's own
// controllers (ClaudeOAuthController, VerifyEmailController) don't call
// authorize() and are unaffected.
abstract class Controller
{
    use AuthorizesRequests;
}
