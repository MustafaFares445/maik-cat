<?php

it('serves every localized legal page', function (string $uri, string $expectedText) {
    $this->get($uri)
        ->assertOk()
        ->assertSee($expectedText);
})->with([
    'English privacy policy' => ['/privacy-policy/en', 'Privacy Policy'],
    'Arabic privacy policy' => ['/privacy-policy/ar', 'سياسة الخصوصية'],
    'English terms of use' => ['/terms-of-use/en', 'Terms of Use'],
    'Arabic terms of use' => ['/terms-of-use/ar', 'شروط الاستخدام'],
]);

it('redirects the default legal links to English', function () {
    $this->get('/privacy-policy')->assertRedirect('/privacy-policy/en');
    $this->get('/terms-of-use')->assertRedirect('/terms-of-use/en');
});
