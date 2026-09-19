<?php

it('renders the dashboard page', function () {
    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard'));
});
