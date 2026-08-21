<?php

it('exposes the published mobile store links on the portfolio page', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('https://play.google.com/store/apps/details?id=maik.cat.android', false)
        ->assertSee('https://apps.apple.com/us/app/maik-cat/id6800437659', false)
        ->assertSee('Download for iOS')
        ->assertDontSee('Coming Soon');
});

it('uses the optimized portfolio image sources', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('hero-phone-v2-cutout.webp', false)
        ->assertSee('platinum-bar.webp', false)
        ->assertSee('calculator-phone.webp', false)
        ->assertSee('loading="lazy"', false);
});
