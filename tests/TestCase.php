<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // Laravel 11's base testing class bootstraps the application via
    // bootstrap/app.php, so no additional CreatesApplication trait is needed.
}
