<?php

it('returns a healthy api response', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJson([
            'status' => 'ok',
        ]);
});
