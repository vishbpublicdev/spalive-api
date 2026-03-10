<?php
namespace SpaLiveV1\Model\Behavior;

use ArrayObject;
use Cake\ORM\Entity;
use Cake\Event\Event;
use Cake\ORM\Behavior;
use Cake\Datasource\EntityInterface;

class MyTreeBehavior extends Behavior {
	public function initialize(array $config): void {
	    // Some initialization code here
	}

	public function generate_tree($array_data, $fn, $parent_id = 1, $leve = 1){
		return $this->_generate_tree($array_data, $fn, $parent_id, $leve);
	}

	public function _generate_tree(&$array_data, $fn, $parent_id = 1, $leve = 1){
		$result = array();
		while ($row = array_shift($array_data)) {
			// $name = $this->_table->getAlias();
			$reg_id = $row['id'];
			$reg_parent_id = $row['parent_id'];

			if($reg_parent_id == $parent_id){
				$_children = $this->_generate_tree($array_data, $fn, $reg_id, $leve + 1);

				$_row = $fn($row, empty($_children), $leve);
				$_row['children'] = $_children;

				$result[] = $_row;
			}else{
				array_unshift($array_data, $row);
				break;
			}
		}

		return $result;
	}

	public function beforeSave(Event $event, EntityInterface $entity, ArrayObject $options) {
		$alias = $this->_table->getAlias();
		$table = $this->_table->getTable();

//		if($entity->isNew()){
//			if(isset($entity->parent_id)){
//				$entity->order = $this->_table->find()->where(["{$alias}.parent_id" => $entity->parent_id])->count()+1;
//			}
//		}elseif(!$entity->isNew()){
        
		if(!$entity->isNew()){
			if(isset($entity->active) || isset($entity->deleted)){

				$ent_node = $this->_table->find()->select(["{$alias}.id","{$alias}.active","{$alias}.deleted","{$alias}.lft","{$alias}.rght"])->where(["{$alias}.id" => $entity->id])->first();

				$set = [];
				if($ent_node->deleted != $entity->deleted){
					$operation = $entity->deleted == 0? '-1' : '+1';
					$set[] = "`deleted` = `deleted`{$operation}";
				}
				if($ent_node->active != $entity->active){
					$operation = $entity->active == 0? '-1' : '+1';
					$set[] = "`active` = `active`{$operation}";
				}
				if(!empty($set)){
					$entity->__update = "UPDATE {$table} {$alias} SET ".implode(',', $set)." WHERE {$alias}.lft > {$ent_node->lft} AND {$alias}.rght < {$ent_node->rght}";
				}
			}
		}
	}

	public function afterSave(Event $event, EntityInterface $entity, ArrayObject $options) {
		if(!$entity->isNew()){
			if(isset($entity->__update) && !empty($entity->__update)){
				$this->_table->getConnection()->query($entity->__update);
			}
		}else{
            $alias = $this->_table->getAlias();
            $table = $this->_table->getTable();
            
            if(isset($entity->parent_id)){
                $int_order_count = $this->_table->find()->where(["{$alias}.parent_id" => $entity->parent_id])->count();
                $this->_table->getConnection()->query("UPDATE {$table} {$alias} SET `order` = {$int_order_count} WHERE `id` = {$entity->id}");
            }
        }
	}

    // public function active_node($int_node_id, $active) {
	// 	$result = false;

	// 	$alias = $this->_table->getAlias();
	// 	$table = $this->_table->getTable();

	// 	$ent_node = $this->_table->find()->select(["{$alias}.id","{$alias}.lft","{$alias}.rght"])->where(["{$alias}.id" => $int_node_id])->first();
	// 	if(!empty($ent_node)){
	// 		$operation = $active? '+1' : '-1';
	// 		$this->_table->getConnection()->query("UPDATE {$table} SET `active` = IF(`active` = 0, 0, `active`{$operation}) WHERE lft BETWEEN {$ent_node->lft} AND {$ent_node->rght}");
	// 		$result = true;
	// 	}

	// 	return $result;
	// }

    // public function delete_node($int_node_id, $delete = 1) {
	// 	$bool_result = false;

	// 	$alias = $this->_table->getAlias();
	// 	$table = $this->_table->getTable();

	// 	$ent_node = $this->_table->find()->select(["{$alias}.id","{$alias}.lft","{$alias}.rght"])->where(["{$alias}.id" => $int_node_id])->first();
	// 	if(!empty($ent_node)){
	// 		$operation = $delete? '+1' : '-1';
	// 		$this->_table->getConnection()->query("UPDATE {$table} SET `deleted` = IF(`deleted` = 0, 0, `deleted`{$operation}) WHERE lft BETWEEN {$ent_node->lft} AND {$ent_node->rght}");
	// 		$bool_result = true;
	// 	}

	// 	return $bool_result;
	// }

    public function move($int_node_id, $int_parent_id, $int_index) {
    	$bool_result = false;

    	$int_node_id = intval($int_node_id);
    	$int_parent_id = intval($int_parent_id);

		if($int_node_id == $int_parent_id){
			return $bool_result;
		}

		$alias = $this->_table->getAlias();
		$table = $this->_table->getTable();
    	
		$ent_node = $this->_table->find()->select(['id','order','parent_id'])->where(["{$alias}.id" => $int_node_id])->first();
		$ent_parent_node = $this->_table->find()->select(['id','order','parent_id'])->where(["{$alias}.id" => $int_parent_id])->first();

		if(!empty($ent_node) && !empty($ent_parent_node)){
			$operator = '>=';
			$operation = '+';

			// SI el parent sigue siendo el mismo
			if($ent_node->parent_id == $ent_parent_node->id){
				if($ent_node->order - $int_index == -1){
					$operator = '<=';
					$operation = '-';
				}
			}

			$ent_node->order = $int_index;
			$ent_node->parent_id = $int_parent_id;

			$driver = $this->_table->getConnection()->getDriver();
			// $autoQuouting = $driver->isAutoQuotingEnabled();
			$driver->enableAutoQuoting(true);
							
			$this->_table->getConnection()->quoteIdentifier('order');
			if($this->_table->save($ent_node)){

				$this->_table->getConnection()->query("UPDATE {$table} SET `order` = `order`{$operation}1 WHERE parent_id = {$int_parent_id} AND `order` {$operator} {$int_index} AND id <> {$int_node_id}");
				

				$this->recover_tree();

				$bool_result = true;
			}
		}

		return $bool_result;
	}

    public function move2($int_node_id, $str_drop_position, $int_to_node_id) {
    	$bool_result = false;

    	$int_node_id = intval($int_node_id);
    	$int_to_node_id = intval($int_to_node_id);

		if($int_node_id == $int_to_node_id){
			return $bool_result;
		}

		$alias = $this->_table->getAlias();
		$table = $this->_table->getTable();
    	// print_r($this);exit;
		$ent_node = $this->_table->find()->select(['order','parent_id'])->where(["{$alias}.id" => $int_node_id])->first();
		$ent_to_node = $this->_table->find()->select(['order','parent_id'])->where(["{$alias}.id" => $int_to_node_id])->first();
		if(!empty($ent_node) && !empty($ent_to_node) && in_array($str_drop_position, array('append','before','after'))){
			// $Modelo = $ent_to_node[$this->str_name];

			$int_tonode_order = intval($ent_to_node->order);
			$int_tonode_parent_id = intval($ent_to_node->parent_id);
			// print_r($Modelo);exit;

			if($str_drop_position == 'append'){
				// $int_order_max = intval($this->_table->field('order', array('parent_id' => $int_to_node_id), '`order` DESC'));
				$ent_query  = $this->_table->find()->select(['order_max' => "MAX({$alias}.`order`)"])->where(["{$alias}.parent_id" => $int_to_node_id]);
				$ent_query = $ent_query->first();
				$int_order_max = empty($ent_query)? 0 : intval($ent_query->order_max);

				$array_save = $this->_table->newEntity([
					'id' => $int_node_id,
					'`order`' => ++$int_order_max,
					'parent_id' => $int_to_node_id,
				]);
			}elseif($str_drop_position == 'before'){
				$array_save = $this->_table->newEntity([
					'id' => $int_node_id,
					'`order`' => $int_tonode_order,
					'parent_id' => $int_tonode_parent_id,
				]);
			}elseif($str_drop_position == 'after'){
				$array_save = $this->_table->newEntity([
					'id' => $int_node_id,
					'`order`' => $int_tonode_order,
					'parent_id' => $int_tonode_parent_id,
				]);
			}

			if($this->_table->save($array_save)){
				if($str_drop_position == 'before'){
					$this->_table->getConnection()->query("UPDATE {$table} SET `order` = `order`+1 WHERE parent_id = {$int_tonode_parent_id} AND `order` >= {$int_tonode_order} AND id <> {$int_node_id}");
				}elseif($str_drop_position == 'after'){
					$this->_table->getConnection()->query("UPDATE {$table} SET `order` = `order`-1 WHERE parent_id = {$int_tonode_parent_id} AND `order` <= {$int_tonode_order} AND id <> {$int_node_id}");
				}
				$this->recover_tree();

				$bool_result = true;
			}
		}

		return $bool_result;
    }

    public function recover_tree(){
		set_time_limit(0);
    	ini_set('memory_limit','-1');

		$table = $this->_table->getTable();

		$this->_table->getConnection()->query("UPDATE {$table} SET lft = 0, rght = 0");
		$this->__recover_tree(0, 0);
    }

    private function __recover_tree($parent_id, $count){
		$table = $this->_table->getTable();

    	$count++; $order = 0;
    	$array_data = $this->_table->getConnection()->query("SELECT M.user_id, M.parent_id FROM {$table} M WHERE M.parent_id = $parent_id ORDER BY M.`order` ASC");
    	// print_r($array_data);exit;
    	$str_query = '';
    	foreach ($array_data as $reg){
            $order++;

    		$node_id = $reg['user_id'];
    		$count2 = $this->__recover_tree($node_id, $count);
    		$str_query .= "UPDATE {$table} SET lft = $count, rght = $count2, `order` = $order WHERE user_id = $node_id;";

    		$count = $count2 + 1;
    	}
    	if(!empty($str_query)){
    		$this->_table->getConnection()->query($str_query);
    	}
		return $count;
    }
}