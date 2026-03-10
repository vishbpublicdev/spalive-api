<?php
namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Utility\Hash;
use Cake\Validation\Validator;
use Data;

class ApiDebugTable extends Table
{
    public $entity = null;

    public function initialize(array $config) : void
    {
        $this->setTable('api_debug'); // Name of the table in the database, if absent convention assumes lowercase version of file prefix

        $this->addBehavior('My');
        $this->addBehavior('Timestamp'); // Allows your model to timestamp records on creation/modification
    }

    public function new_entity($array_data) {
        $new_row = $this->newEntity($array_data);

        $new_row = $this->save($new_row);

        return $new_row == false? false : $new_row;
    }

    public function create_log($int_application_id, $int_appkey_id, $debug_level, $str_action, $usuario_id = 0){
        //$this->create();
        // $this->getConnection()->getDriver()->enableAutoQuoting();

        $ip = isset($_SERVER['HTTP_X_FORWARDED_FOR'])? $_SERVER['HTTP_X_FORWARDED_FOR'] : (isset($_SERVER['REMOTE_ADDR'])? $_SERVER['REMOTE_ADDR'] : '');
        $agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        $app_version = get('version', '');
        $now = date('Y-m-d H:i:s');
        $saved = $this->new_entity([
            'action' => $str_action,
            'application_id' => $int_application_id,
            'key_id' => $int_appkey_id,
            'post' => json_encode($_POST),
            'post_input' => json_encode(Data::post()),
            'get' => json_encode($_GET),
            'token' => !defined(API_TOKEN) ? "" : API_TOKEN,
            'app_version' => $app_version,
            'version' => API_VERSION,
            // 'result' => '',
            // 'server' => json_encode($_SERVER),
            'files' => $debug_level == 2 ? json_encode($_FILES) : '',
            'ip' => $ip,
            'agent' => $agent,
            'createdby' => $usuario_id,
        ]);
        if($saved !== false){
            $this->entity = $saved;
        }
       
        $find = $this->getConnection()->query(
            "SELECT 
               id FROM sys_users_versions SU
            WHERE SU.createdby = '{$usuario_id}'
            LIMIT 1
            "
        )->fetchAll('assoc');
        if(isset($find[0])){
            $find = $this->getConnection()->query(
                "UPDATE sys_users_versions SET
                    app_version = '{$app_version}', 
                    ip = '{$ip}', 
                    created = '{$now}' 
                WHERE createdby = {$usuario_id}"
            )->fetchAll('assoc');
        } else {
            
            $find = $this->getConnection()->query(
                "INSERT INTO sys_users_versions 
                        (key_id,app_version,ip,createdby,created)
                    VALUES
                        ({$int_appkey_id},'{$app_version}','{$ip}',{$usuario_id},'{$now}')
                "
            )->fetchAll('assoc');
        }


        
    }

    public function set_result($result = array()){
        //STOP UPDATING RESPONSE, add to the method  API_ACTION == 'apply_promo_purchase'
         if(isset($this->entity)){ 
             $this->entity->result = json_encode($result);
             $this->save($this->entity);
         }
    }

    public function set_error($result = array()){
        //STOP UPDATING RESPONSE
        // if(isset($this->entity)){
        //     $this->entity->error = json_encode($result);
        //     $this->save($this->entity);    
        // }
    }
}