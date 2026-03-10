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
                U.email, U.name, U.lname, U.mname, T.is_admin, T.created
            FROM app_tokens T
            INNER JOIN sys_users U ON U.id = T.user_id
            WHERE T.token = '{$token}' AND T.deleted = 0
            "
        )->fetchAll('assoc');
    	if(isset($find[0])){
            $tokenTime = strtotime($find[0]['created']);


            define('USER_ID', $find[0]['user_id']);
            define('USER_UID', $find[0]['uid']);
            define('USER_TYPE', $find[0]['user_role']);
            define('USER_NAME', $find[0]['name']);
            define('USER_EMAIL', $find[0]['email']);
            define('USER_ENABLE_NOTIFICATION', $find[0]['user_enable_notification']);
            if (USER_ID == 1)
                define('MASTER', true);
            else
                define('MASTER', false);
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