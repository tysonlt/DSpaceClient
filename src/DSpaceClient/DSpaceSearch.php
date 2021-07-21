<?php

namespace DSpaceClient;

/**
 * 
 */
class DSpaceSearch {

    public $scope = null;
    public $filters = [];

    public function addFilter($key, $value, $operator='equals') {
        $this->filters[] = [
            'key' => $key,
            'value' => $value,
            'operator' => $operator
        ];
    }

    public function buildEndpoint($page = false) {

        $endpoint = '/api/discover/search/objects';
        $query = [];
        if ($this->scope) {
            $query['scope'] = $this->scope;
        }

        foreach ($this->filters as $filter) {
            $query[$filter['key']] = $filter['value'] .','. $filter['operator'];
        }

        if (false !== $page) {
            $query['page'] = $page;
        }

        return $endpoint .'?'. http_build_query($query);
    }

}