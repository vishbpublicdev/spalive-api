<?php
namespace SpaLiveV1\Model\Table;


use Cake\ORM\Table;
use Cake\Utility\Hash;
use Cake\Validation\Validator;

class AppTokenTable extends Table
{
    public function initialize(array $config) : void
    {
        $this->setTable('app_tokens'); // Name of the table in the database, if absent convention assumes lowercase version of file prefix

        $this->addBehavior('SpaLiveV1.My');
        // $this->addBehavior('Admin.MyTree');
        // $this->addBehavior('Tree');
        $this->addBehavior('Timestamp'); // Allows your model to timestamp records on creation/modification
    }


    public function validateToken($token){

    	$find = $this->getConnection()->query(
            "SELECT 
                T.user_id, U.uid ,U.type as user_role, U.state as user_state, U.enable_notifications as user_enable_notification, 
                U.email, U.name, U.lname, U.mname, U.phone, U.dob, U.zip, U.city, U.street, U.suite, U.state, T.is_admin, T.created, U.steps, U.radius as user_radius
            FROM app_tokens T
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
            if(!defined('USER_LNAME'))define('USER_LNAME', $find[0]['lname']);
            if(!defined('USER_EMAIL'))define('USER_EMAIL', $find[0]['email']);
            if(!defined('USER_PHONE'))define('USER_PHONE', $find[0]['phone']);
            if(!defined('USER_STATE'))define('USER_STATE', $find[0]['state']);
            if(!defined('USER_STEP'))define('USER_STEP', $find[0]['steps']);
            if(!defined('DOB'))define('DOB', $find[0]['dob']);
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

    public function checkToken($token){

        $find = $this->getConnection()->query(
            "SELECT 
                T.user_id, U.uid ,U.type as user_role, U.state as user_state, U.email, U.name
            FROM app_tokens T
            INNER JOIN sys_users U ON U.id = T.user_id
            WHERE T.token = '{$token}'
            "
        )->fetchAll('assoc');
        if(isset($find[0])){
            return $find[0];
        }
        return false;
    }

    public function getCurrentToken($user_id){
        $entToken = $this->find()->select(['AppToken.token'])->where(['AppToken.user_id' => $user_id, 'AppToken.deleted' => 0])->first();
        return !empty($entToken) ? $entToken->token : false;
    }

}