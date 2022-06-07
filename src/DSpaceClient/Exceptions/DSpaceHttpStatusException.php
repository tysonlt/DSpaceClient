<?php

namespace DSpaceClient\Exceptions;

/**
 * 
 */
class DSpaceHttpStatusException extends DSpaceException {
    public $response;
    public function __construct($status, $response) {
        if (! is_array($response)) {
            $response = json_decode($response, true);
        }
        $message = $response["message"] ?? $response["error"] ?? "HTTP STATUS: $status";
        parent::__construct($message, $status);
        $this->response = $response;
    }
}
