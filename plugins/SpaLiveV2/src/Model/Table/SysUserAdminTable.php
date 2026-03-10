<?php
namespace SpaLiveV1\Model\Table;

use Cake\ORM\Table;
use Cake\Utility\Hash;
use Cake\Validation\Validator;

class SysUserAdminTable extends Table
{
    public function initialize(array $config) : void
    {
        $this->setTable('sys_users_admin'); // Name of the table in the database, if absent convention assumes lowercase version of file prefix

        $this->addBehavior('SpaLiveV1.My');
        // $this->addBehavior('Admin.MyTree');
        // $this->addBehavior('Tree');
        $this->addBehavior('Timestamp'); // Allows your model to timestamp records on creation/modification
    }

    public function getRandomDoctor(){
    	$doctors = $this->find()->select(['SysUserAdmin.id'])->where(['SysUserAdmin.user_type' => 'DOCTOR'])->toArray();
    	$numDocts = sizeof($doctors);
    	$pos = rand(0, ($numDocts - 1));
    	return $doctors[$pos]['id']; 
    }

}