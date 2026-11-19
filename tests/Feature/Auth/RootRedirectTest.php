<?php

declare(strict_types=1);

it('the root of the site redirects to the login instead of the framework welcome page', function (): void {
    $this->get('/')->assertRedirect('/admin/login');
});
