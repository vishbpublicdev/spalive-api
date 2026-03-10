<?php
namespace SpaLiveV1\Model\Table;


use Cake\ORM\Table;
use Cake\Utility\Hash;
use Cake\Validation\Validator;

class AppMenuTable extends Table
{
    public function initialize(array $config) : void
    {
        $this->setTable('app_menu'); // Name of the table in the database, if absent convention assumes lowercase version of file prefix

        $this->addBehavior('SpaLiveV1.My');
        $this->addBehavior('MyTree');
        $this->addBehavior('Tree');
        $this->addBehavior('Timestamp'); // Allows your model to timestamp records on creation/modification
    }

    function get_tree($parent_id = 1, $only_active = false){
		$result = array();
		$array_conditions = array('AppMenu.id' => $parent_id, 'AppMenu.deleted' => 0);
		if($only_active == true) $array_conditions['AppMenu.active'] = 1;

		$array_query = $this->find()->select(['AppMenu.lft','AppMenu.rght','AppMenu.parent_id'])->where($array_conditions);
		if($array_query->count() > 0){
			$Node = $array_query->first();

			$lft = $Node->lft;
			$rght = $Node->rght;

			$array_conditions = array('AppMenu.lft >=' => $lft, 'AppMenu.rght <=' => $rght, 'AppMenu.deleted' => 0);
			if($only_active == true) $array_conditions['AppMenu.active'] = 1;

			$arrNodes = $this->find()
				->select(['AppMenu.id','AppMenu.uid','AppMenu.active','AppMenu.url','AppMenu.parent_id','AppMenu.permisos','AppMenu.name'])
				->where($array_conditions)->order('AppMenu.lft ASC');

			$x = 0;
			$array_nodes = $arrNodes->toArray();
			$result = $this->__get_tree($array_nodes, $Node->parent_id, $x);
		}

		return $result;
	}

	function __get_tree(&$nodes, $parent_id, &$x){
		$result = array(); $b = true;

		while($b && isset($nodes[$x])){
			$node = $nodes[$x];
			$node_patent_id = $node->parent_id;
			if($parent_id == $node_patent_id){
				$node_id = $node->id; $x++;
				$node['children'] = $this->__get_tree($nodes, $node_id, $x);
				$result[] = $node;
			}else {
				$b = false;
			}
		}
		return $result;
	}


    function get_menu($arrMenu, $array_permisos){
		$str_separator = '';

		$array_permisos[] = -9;

		$result = array();
		// pr($arrMenu);
		// exit;
		foreach ($arrMenu as $Menu){
			// if ($rol_id == 3 && ($Menu->id == 20 || $Menu->parent_id == 20)) continue;

			$str_permisos = trim($Menu->permisos);
			$array_modulo_permisos = empty($str_permisos)? array() : explode(',', $Menu->permisos);

			$array_intersect = array_intersect($array_permisos, $array_modulo_permisos);
			// pr($Menu->Module['nombre']);
			// pr($array_modulo_permisos);
			// pr($array_intersect);
			// exit;

			if(!empty($array_intersect) || empty($str_permisos)){
				// pr('siempre entro aqui');
				// pr('----------------------');
				$text = $Menu->name;
				if($text == '-'){
					$str_separator = "'-'";
				}else{
					//$array_children = $this->get_menu($Menu->children, $array_permisos);
					//$result[] = $str_menu;
					$result[] = $Menu;
				}
			}
		}
		// exit;
		return $result;
    }
}