<?php

use App\Models\User;

test('login page opens', function () {
    $this->get('/cabinet/login')->assertOk();
});

test('guest opening home is redirected to login', function () {
    $this->get('/')->assertRedirect(route('filament.app.auth.login'));
});

test('authenticated user opening home is redirected to cabinet', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')->assertRedirect(route('filament.app.pages.dashboard'));
});
