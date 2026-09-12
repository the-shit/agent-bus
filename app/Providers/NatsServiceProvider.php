<?php

namespace App\Providers;

use App\Bus\NatsUrl;
use LaravelNats\Laravel\Providers\NatsServiceProvider as PackageNatsServiceProvider;

/**
 * The package provider with two changes: NATS_URL is the one broker setting
 * (the same variable bin/agent-bus reads on the hot path), and the package's
 * own nats:* console commands stay out of this binary.
 */
class NatsServiceProvider extends PackageNatsServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->applyNatsUrl();
    }

    /**
     * Agent Bus ships its own verbs; the package's stream and consumer commands would only add noise.
     */
    protected function registerJetStreamCommands(): void {}

    private function applyNatsUrl(): void
    {
        $url = env('NATS_URL');

        if (! is_string($url) || trim($url) === '') {
            return;
        }

        $parts = NatsUrl::parse($url);
        $config = $this->app->make('config');

        $config->set('nats_basis.connections.default.host', $parts['host']);
        $config->set('nats_basis.connections.default.port', $parts['port']);

        if ($parts['user'] !== null) {
            $config->set('nats_basis.connections.default.user', $parts['user']);
        }

        if ($parts['pass'] !== null) {
            $config->set('nats_basis.connections.default.pass', $parts['pass']);
        }
    }
}
