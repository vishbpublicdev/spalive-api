<?php
namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Utility\Hash;
use Cake\Validation\Validator;

class ApiApplicationTable extends Table
{
    public function initialize(array $config) : void
    {
        $this->setTable('api_applications'); // Name of the table in the database, if absent convention assumes lowercase version of file prefix

        $this->addBehavior('My');
        $this->addBehavior('Timestamp'); // Allows your model to timestamp records on creation/modification
    }

    public function get_app($str_appkey){
        return $this->find()
            ->select([
                'ApiApplication.id',
                'ApiApplication.appname',
                'ApiApplication.debug',
                'ApiApplication.json_config',
                'key_id' => 'ApiKey.id',
                'type' => 'ApiKey.type',
            ])->join([
                'ApiKey' => [
                    'type' => 'INNER',
                    'table' => 'api_keys',
                    'conditions' => "ApiKey.active = 1 AND ApiApplication.id = ApiKey.application_id"
                ],
            ])
            ->where(['ApiKey.key' => $str_appkey])
            ->first();
    }

    // public function validationDefault(Validator $validator){
    //     $validator
    //         ->notEmpty('nombre', 'El nombre del Rol no puede quedar vacio');
    //     //     ->requirePresence([
    //     //     'uid' => [
    //     //         'mode' => 'create',
    //     //         'message' => 'Se requiere una clave UID'
    //     //     ],
    //     // ]);

    //     return $validator;
    // }
}