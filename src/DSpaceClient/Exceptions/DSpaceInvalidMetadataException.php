<?php

namespace DSpaceClient\Exceptions;

/**
 * 
 */
class DSpaceInvalidMetadataException extends DSpaceException { 

    public function __construct(string $key) {
        parent::__construct("Invalid meta key: $key");
    }

}
