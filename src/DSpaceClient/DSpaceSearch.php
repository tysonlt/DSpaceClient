<?php

namespace DSpaceClient;

/**
 * 
 */
class DSpaceSearch {

    public $scope = null;
    public $pluck_fields = [];
    public $filters = [];

    public function addFilter($key, $value, $operator='equals') {
        $this->filters[] = [
            'key' => $key,
            'value' => $value,
            'operator' => $operator
        ];
        return $this;
    }

    public function setScope($scope) {
        $this->scope = $scope;
        return $this;
    }

    public function pluck($field) {
        $this->pluck_fields[] = $field;
        return $this;
    }

    public function pluckMeta($field) {
        $this->pluck_fields[] = 'meta:'. $field;
        return $this;
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