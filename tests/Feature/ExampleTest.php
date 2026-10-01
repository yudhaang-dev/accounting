<?php

test('the health check endpoint returns a successful response', function () {
    $response = $this->get('/up');

    $response->assertOk();
});
