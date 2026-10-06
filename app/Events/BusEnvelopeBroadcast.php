<?php

namespace App\Events;

class BusEnvelopeBroadcast
{
    /**
     * @param  array<string, mixed>  $envelope
     */
    public function __construct(public array $envelope) {}
}
