<?php
namespace SpaLiveV1\Model\Table;

use Cake\ORM\Table;
use Cake\Utility\Hash;
use Cake\Validation\Validator;

class DataTreatmentReviewTable extends Table
{
    public function initialize(array $config) : void
    {
        $this->setTable('data_treatment_reviews'); // Name of the table in the database, if absent convention assumes lowercase version of file prefix
        $this->addBehavior('SpaLiveV1.My');
        $this->addBehavior('Timestamp'); // Allows your model to timestamp records on creation/modification
    }

    public function injectorMostReviewed(){
        $str_query = "SELECT injector_id, AVG(score) as avg_score, COUNT(id) as total FROM data_treatment_reviews WHERE deleted = 0 GROUP BY injector_id ORDER BY total DESC";
        $find = $this->getConnection()->execute($str_query)->fetchAll('assoc');

        //$query_usr = "SELECT ROUND(COUNT(id) * 0.1, 0) as ci_percent FROM sys_users WHERE type = 'injector' AND deleted = 0 AND login_status = 'READY'";
        //$allCI = $this->getConnection()->execute($query_usr)->fetchAll('assoc');

        $num_reviewed = sizeof($find);
        $user_percent = $num_reviewed * 0.1;
        $result = [];
        $curItem = 0;

        foreach ($find as $item) {
            if($curItem == $user_percent){
                break;
            }
            if($item['avg_score'] == 50.0){
                $result[] = $item;
                $curItem ++;
            }
        }

        return Hash::extract($result, '{n}.injector_id');
    }

}