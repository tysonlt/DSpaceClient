<?php

namespace DSpaceClient\Store;

class DefaultTokenStore implements TokenStore {

    protected $csrf;
    protected $bearer;
    protected $userData = [];

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

    public function storeUserData(string $key, $value) {
        $this->userData[$key] = $value;
    }

    public function clearUserData(string $key) {
        unset($this->userData[$key]);
    }

    public function fetchUserData(string $key, $default = null) {
        return $this->userData[$key] ?? $default;
    }

}