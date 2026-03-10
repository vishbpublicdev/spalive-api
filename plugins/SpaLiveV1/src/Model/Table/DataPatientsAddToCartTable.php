<?php
namespace SpaLiveV1\Model\Table;

use Cake\ORM\Table;
use Cake\Utility\Hash;
use Cake\Validation\Validator;

class DataPatientsAddToCartTable extends Table
{
    public function initialize(array $config) : void
    {
        $this->setTable('data_patients_add_to_cart'); // Name of the table in the database, if absent convention assumes lowercase version of file prefix

        $this->addBehavior('SpaLiveV1.My');
        //$this->addBehavior('Admin.My');
        //$this->addBehavior('Timestamp'); // Allows your model to timestamp records on creation/modification
    }

}