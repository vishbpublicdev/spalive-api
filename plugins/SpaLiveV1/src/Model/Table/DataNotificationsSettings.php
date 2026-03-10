<?php
namespace SpaLiveV1\Model\Table;

use Cake\ORM\Table;
use Cake\Utility\Hash;
use Cake\Validation\Validator;

class DataNotificationsSettings extends Table
{
    public function initialize(array $config) : void
    {
        $this->setTable('data_notifications_settings'); // Name of the table in the database, if absent convention assumes lowercase version of file prefix
        $this->addBehavior('SpaLiveV1.My');
    }

}