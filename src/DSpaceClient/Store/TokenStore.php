<?php

namespace DSpaceClient\Store;

interface TokenStore {

    public function storeCsrfToken($token);

    public function storeBearerToken($token);

    public function fetchCsrfToken();

    public function fetchBearerToken();

}