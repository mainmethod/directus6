<?php

namespace Directus\Db\TableGateway;

use Directus\Auth\Provider as AuthProvider;
use Directus\Bootstrap;
use Directus\Db\Exception;
use Directus\Db\RowGateway\AclAwareRowGateway;
use Directus\Db\TableGateway\DirectusActivityTableGateway;
use Directus\Db\TableSchema;
use Directus\Util\Formatting;
use Zend\Db\RowGateway\AbstractRowGateway;
use Zend\Db\Sql\Expression;
use Zend\Db\Sql\Predicate;
use Zend\Db\Sql\Predicate\PredicateInterface;
use Zend\Db\Sql\Select;
use Zend\Db\Sql\Sql;
use Zend\Db\Sql\Where;

class RelationalTableGatewayWithConditions extends RelationalTableGateway {

    public function applyParamsToTableEntriesSelect(array $params, Select $select, array $schema, $hasActiveColumn = false) {

        $tableName = $this->getTable();

        if(isset($params['group_by'])) {
          $select->group($tableName . '.' . $params['group_by']);
        } else {
          $select->group($tableName . '.id');
        }

        //If this is a relational order, than it is an array.
        if(is_array($params['orderBy'])) {
          $select->join(
            array('jsort' => $params['orderBy']['junction_table']),
            'jsort.'.$params['orderBy']['jkeyRight'].' = '.$tableName.'.id',
            array(),
            $select::JOIN_LEFT
          );

          $select->join(
              array('rsort' => $params['orderBy']['related_table']),
              'rsort.id = jsort.'.$params['orderBy']['jkeyLeft'],
              array(),
              $select::JOIN_LEFT
          );

          $select->order('rsort.title', $params['orderDirection']);
        } else {
          $select->order(implode(' ', array($params['orderBy'], $params['orderDirection'])));
        }

        $select->limit($params['perPage'])
            ->offset($params['currentPage'] * $params['perPage']);

        // Are we sorting on a relationship?
        foreach($schema as $column) {
            if($column['column_name'] != $params['orderBy']) {
                continue;
            }
            // Must have defined table_related
            if(!isset($column['relationship']) || !is_array($column['relationship']) || !isset($column['relationship']['table_related'])) {
                break;
            }
            // Must have defined visible_column
            if(!isset($column['options']) || !is_array($column['options']) || !isset($column['options']['visible_column'])) {
                break;
            }
            $relatedTable = $column['relationship']['table_related'];
            $visibleColumn = $column['options']['visible_column'];
            $keyLeft = $params['table_name'] . "." . $params['orderBy'];
            // @todo it's wrong to assume PKs are "id" but this is currently endemic to directus6
            $keyRight = $relatedTable . ".id";
            $joinedSortColumn = $relatedTable . "." . $visibleColumn;
            $select
                ->reset(Select::ORDER)
                ->join($relatedTable, "$keyLeft = $keyRight", array(), Select::JOIN_LEFT)
                ->order("$joinedSortColumn " . $params['orderDirection']);
            break;
        }


        // Note: be sure to explicitly check for null, because the value may be
        // '0' or 0, which is meaningful.
        if (null !== $params[STATUS_COLUMN_NAME] && $hasActiveColumn) {
            $haystack = is_array($params[STATUS_COLUMN_NAME])
                ? $params[STATUS_COLUMN_NAME]
                : explode(",", $params[STATUS_COLUMN_NAME]);

            if (!isset($params['table_name']) || empty($params['table_name'])) {
              $tableName = $this->getTable();
            } else {
              $tableName = $params['table_name'];
            }

            $select->where->in($tableName.'.'.STATUS_COLUMN_NAME, $haystack);
        }

        // Where
        $select
            ->where
            ->nest
                ->expression('-1 = ?', $params['id'])
                ->or
                ->equalTo($tableName . '.id', $params['id'])
            ->unnest;

        // very very rudimentary ability to supply where conditions to fetch...
        // at the moment, only 'equalTo' and 'between' are supported... also, the 'total' key returned
        // in the json does not reflect these filters...
        // -MG
        if (array_key_exists('where',$params)) {
            $outer = $select->where->nest;
            foreach ($params['where'] as $whereCond) {
                $type = $whereCond['type'];
                $column = $whereCond['column'];

                if ($type == 'equalTo') {
                    $val = $whereCond['val'];
                    if (is_array($val)) {
                        $where = $select->where->nest;
                        foreach ($val as $currentval) {
                            $where->equalTo($column, $currentval);
                            if ($currentval != end($val)) {
                                $where->or;
                            }
                        }
                        $where->unnest;
                    } else {
                        $outer->equalTo($column, $val);
                    }

                } else if ($type == 'between') {
                    $val1 = $whereCond['val1'];
                    $val2 = $whereCond['val2'];
                    $outer->between($column, $val1, $val2);
                }

            }
             $outer->unnest;
        }

        //@TODO: Make this better
        if(isset($params['adv_where'])) {
          $select->where($params['adv_where']);
        }

        if(isset($params['adv_search']) && !empty($params['adv_search'])) {
          $i = 0;
          foreach ($params['adv_search'] as $search_col) {
            $target = array();
            foreach ($schema as $col) {
              if($col['id'] == $search_col['id']) {
                $target = $col;
                break;
              }
            }

            if(empty($target)) {
              continue;
            }

            if(isset($target['relationship']) && $target['relationship']['type'] == "MANYTOMANY") {

              $relatedTable = $target['relationship']['table_related'];
              $relatedAliasName = $relatedTable."_".$i;

              if($target['relationship']['type'] == "MANYTOMANY") {
                $junctionTable = $target['relationship']['junction_table'];
                $jkl = $target['relationship']['junction_key_left'];
                $jkr = $target['relationship']['junction_key_right'];

                $keyleft = $params['table_name']. ".id";
                $keyRight = $junctionTable.'.'.$jkl;

                $jkeyleft = $junctionTable.'.'.$jkr;
                $jkeyright = $relatedAliasName.".id";


                $select->join($junctionTable,
                    "$keyleft = $keyRight",
                    array(),
                    Select::JOIN_INNER);
              } else {
                $select->join(array($relatedAliasName => $relatedTable),
                  $tableName.'.'.$target['column_name']." = ".$relatedAliasName.".id",
                  array(),
                  Select::JOIN_INNER);
              }

              $relatedTableMetadata = TableSchema::getSchemaArray($relatedTable);

              if($search_col['type'] == "like") {
                $select->join(array($relatedAliasName => $relatedTable),
                    "$jkeyleft = $jkeyright",
                    array(),
                    Select::JOIN_INNER);

                $search_col['value'] = "%".$search_col['value']."%";
                if(isset($target['options']['filter_column'])) {
                  $targetCol = $target['options']['filter_column'];
                } else {
                  $targetCol = $target['options']['visible_column'];
                }

                foreach ($relatedTableMetadata as $col) {
                  if($col['id'] == $targetCol) {
                    if ($col['type'] == 'VARCHAR' || $col['type'] == 'INT') {
                      $where = $select->where->nest;
                      $columnName = $this->adapter->platform->quoteIdentifier($col['column_name']);
                      $columnName = $relatedAliasName.".".$columnName;
                      $like = new Predicate\Expression("LOWER($columnName) LIKE ?", $search_col['value']);
                      $where->addPredicate($like, Predicate\Predicate::OP_OR);
                      $where->unnest;
                    }
                  }
                }
              } else {
                  $select->where($jkeyleft.' = '.$this->adapter->platform->quoteValue($search_col['value']));
              }
            } else {
              if($target['type'] == "DATETIME" && strpos($search_col['value'], " ") == false){
                $select->where('date('.$tableName.'.'.$search_col['id'].") = ".$this->adapter->platform->quoteValue($search_col['value']));
              } else {
                if($search_col['type'] == "like") {
                  $select->where($tableName.'.'.$search_col['id']." ".$search_col['type']." ".$this->adapter->platform->quoteValue("%".$search_col['value']."%"));
                } else {
                  $select->where($tableName.'.'.$search_col['id']." ".$search_col['type']." ".$this->adapter->platform->quoteValue($search_col['value']));
                }
              }
            }
            $i++;
          }
        } else if(isset($params['search']) && !empty($params['search'])) {
            $params['search'] = "%" . $params['search'] . "%";
            $where = $select->where->nest;
            foreach ($schema as $col) {
                if ($col['type'] == 'VARCHAR' || $col['type'] == 'INT') {
                    $columnName = $this->adapter->platform->quoteIdentifier($col['column_name']);
                    $like = new Predicate\Expression("LOWER($columnName) LIKE ?", strtolower($params['search']));
                    $where->addPredicate($like, Predicate\Predicate::OP_OR);
                }
            }
            $where->unnest;
        }
        //    $log = Bootstrap::get('log');
        //    $log->info(__CLASS__.'#'.__FUNCTION__);
        //    $log->info("New search query: " . $this->dumpSql($select));
        return $select;
    }


}
