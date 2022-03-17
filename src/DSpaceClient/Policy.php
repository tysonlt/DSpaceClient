<?php

namespace DSpaceClient;

class Policy {

    public $id = null;
    public $name = null;
    public $description = null;
    public $type = 'TYPE_CUSTOM';
    public $action = 'READ';
    public $person_uuid = null;
    public $group_uuid = null;
    public $start_date = null;
    public $end_date = null;

}