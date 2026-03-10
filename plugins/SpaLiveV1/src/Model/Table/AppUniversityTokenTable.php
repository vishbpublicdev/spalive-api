<?php
namespace SpaLiveV1\Model\Table;


use Cake\ORM\Table;
use Cake\Utility\Hash;
use Cake\Validation\Validator;

class AppUniversityTokenTable extends Table
{
    public function initialize(array $config) : void
    {
        $this->setTable('app_university_tokens'); // Name of the table in the database, if absent convention assumes lowercase version of file prefix

        $this->addBehavior('SpaLiveV1.My');
        // $this->addBehavior('Admin.MyTree');
        // $this->addBehavior('Tree');
        $this->addBehavior('Timestamp'); // Allows your model to timestamp records on creation/modification
    }


    public function validateToken($token){

    	$find = $this->getConnection()->query(
            "SELECT 
                T.user_id, U.uid ,U.type as user_role, U.state as user_state, U.enable_notifications as user_enable_notification, 
                U.email, U.name, U.lname, U.mname, T.is_admin, T.created, U.radius as user_radius
            FROM app_university_tokens T
            INNER JOIN sys_users U ON U.id = T.user_id
            WHERE T.token = '{$token}' AND T.deleted = 0
            "
        )->fetchAll('assoc');

    	if(isset($find[0])){
            $tokenTime = strtotime($find[0]['created']);


            if(!defined('USER_ID'))define('USER_ID', $find[0]['user_id']);
            if(!defined('USER_UID'))define('USER_UID', $find[0]['uid']);
            if(!defined('USER_TYPE'))define('USER_TYPE', $find[0]['user_role']);
            if(!defined('USER_NAME'))define('USER_NAME', $find[0]['name']);
            if(!defined('USER_EMAIL'))define('USER_EMAIL', $find[0]['email']);
            if(!defined('USER_ENABLE_NOTIFICATION'))define('USER_ENABLE_NOTIFICATION', $find[0]['user_enable_notification']);
            if(!defined('MASTER')){
                if (USER_ID == 1)
                    define('MASTER', true);
                else
                    define('MASTER', false);
            }
    		return $find[0];
    	}
    	return false;
    }

}