<?php

namespace DSpaceClient\Store;

class DefaultTokenStore implements TokenStore {

    protected $csrf;
    protected $bearer;

    public function storeCsrfToken($token) {
        $this->csrf = $token;
    }

    public function storeBearerToken($token) {
        $this->bearer = $token;
    }

    public function fetchCsrfToken() {
        return $this->csrf;
    }

    public function fetchBearerToken() {
        return $this->bearer;
    }

}