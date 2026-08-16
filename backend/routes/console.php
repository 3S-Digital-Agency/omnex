<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('omnex:check-domain-expirations')->daily();
Schedule::command('omnex:billing-renewals')->daily();
