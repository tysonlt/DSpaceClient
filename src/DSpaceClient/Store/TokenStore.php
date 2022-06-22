<?php

namespace DSpaceClient\Store;

interface TokenStore {

    public function storeCsrfToken($token);

    public function storeBearerToken($token);

    public function fetchCsrfToken();

    public function fetchBearerToken();

    public function storeUserData(string $key, $value);

    public function clearUserData(string $key);

    public function fetchUserData(string $key, $default = null);

}