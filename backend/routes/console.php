<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('nexus:check-domain-expirations')->daily();
