<?php

test('login page opens', function () {
    $this->get('/cabinet/login')->assertOk();
});
