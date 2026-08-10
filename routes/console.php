<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Refresh every taxpayer's documents overnight so a WhatsApp request is answered
 * from storage instead of waiting on the SAT. Nothing is sent from this run.
 *
 * The timezone is stated explicitly rather than inherited: this must be 02:00 in
 * Mexico City, which is the same clock `generated_for_date` is derived from.
 */
Schedule::command('documents:generate-daily')
    ->dailyAt('02:00')
    ->timezone('America/Mexico_City')
    ->withoutOverlapping();
