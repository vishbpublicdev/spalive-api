<?php
namespace SpaLiveV1\Model\Behavior;

use ArrayObject;
use Cake\ORM\Entity;
use Cake\Event\Event;
use Cake\ORM\Behavior;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Query;
use Cake\ORM\TableRegistry;

use Cake\Utility\Text;
use Cake\Datasource\FactoryLocator;

class MyBehavior extends Behavior {

	public function initialize(array $config): void {
	    // Some initialization code here
	}

	public function new_uid() {
		return Text::uuid(); //uniqid('', true);
    }

	public function id_to_uid($id, $bypassResource = false) {
		$id = intval($id);
		if($id == 0) return '';

		$alias = $this->_table->getAlias();

		$array_query = $this->_table->find()->select(["{$alias}.uid"])->applyOptions(['bypassResource' => $bypassResource])->where(["{$alias}.id" => $id,"{$alias}.deleted" => 0]);
		return $array_query->count() == 0? '' : $array_query->first()->uid;
    }

	public function uid_to_id($uid, $bypassResource = false) {
		$uid = trim($uid);
		// echo "*{$uid}*";exit;
		if(empty($uid)) return 0;

		$alias = $this->_table->getAlias();

		$array_query = $this->_table->find()->select(["{$alias}.id"])->applyOptions(['bypassResource' => $bypassResource])->where(["{$alias}.uid" => $uid,"{$alias}.deleted" => 0]);
		return $array_query->count() == 0? 0 : $array_query->first()->id;
	}

	public function new_entity($array_data) {
    	$new_row = $this->_table->newEntity($array_data);

    	$new_row = $this->_table->save($new_row);

    	return $new_row == false? false : $new_row;
	}
	
}