<?php
namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use PHPUnit\Framework\Constraint\Count;

use SpaLiveV1\Controller\MainController;

class AssignCommand extends Command{
    
    public function execute(Arguments $args, ConsoleIo $io){
 
        $isDev = env('IS_DEV', false);

        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataAssignedJobs');
        $this->loadModel('SpaLiveV1.DataAssignedToRegister');
        $this->loadModel('SpaLiveV1.DataSalesRepresentative');

        $data = $this->DataAssignedJobs->find()->where(['DataAssignedJobs.deleted' => 0])->all();

        if(Count($data) > 0) {
            foreach($data as $row) {

                if( date('Y-m-d H:i:s') >= $row->date_assign->i18nFormat('yyyy-MM-dd HH:mm:ss')) {

                    $user = $this->SysUsers->find()->where(['SysUsers.id' => $row->user_id])->first();
                    /*
                    $assigned = $this->DataAssignedToRegister->find()->select(['DataAssignedToRegister.id','Rep.id'])->join([
                        'Rep' => ['table' => 'data_sales_representative', 'type' => 'INNER', 'conditions' => 'Rep.id = DataAssignedToRegister.cat_id'],
                        'SysUsersAdminStates' => ['table' => 'sys_users_admin_states', 'type' => 'INNER', 'conditions' => 'SysUsersAdminStates.admin_user_id = Rep.admin_user_id'],
                    ])->where(['Rep.deleted' => 0,'DataAssignedToRegister.manual' => 0, 'Rep.team' => 'INSIDE', 'SysUsersAdminStates.state_id' => $user->state])->order(['DataAssignedToRegister.id' => 'DESC'])->first();
            
                    $findRep = $this->DataSalesRepresentative->find()->select(['User.uid','DataSalesRepresentative.user_id','DataSalesRepresentative.id'])->join([
                            'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataSalesRepresentative.user_id'],
                            'SysUsersAdminStates' => ['table' => 'sys_users_admin_states', 'type' => 'INNER', 'conditions' => 'SysUsersAdminStates.admin_user_id = DataSalesRepresentative.admin_user_id'],
                        ])->where(['DataSalesRepresentative.id >' => $assigned['Rep']['id'], 'DataSalesRepresentative.deleted' => 0,'User.deleted' => 0,'DataSalesRepresentative.sales_person' => 1, 'DataSalesRepresentative.team' => 'INSIDE', 'SysUsersAdminStates.state_id' => $user->state])
                        ->order(['DataSalesRepresentative.id' => 'ASC'])->first();
                            
                    if (empty($findRep)) {
                        $findRep = $this->DataSalesRepresentative->find()->select(['User.uid','DataSalesRepresentative.user_id','DataSalesRepresentative.id'])->join([
                            'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataSalesRepresentative.user_id'],
                            'SysUsersAdminStates' => ['table' => 'sys_users_admin_states', 'type' => 'INNER', 'conditions' => 'SysUsersAdminStates.admin_user_id = DataSalesRepresentative.admin_user_id'],
                        ])->where(['DataSalesRepresentative.deleted' => 0,'User.deleted' => 0, 'DataSalesRepresentative.sales_person' => 1, 'DataSalesRepresentative.team' => 'INSIDE', 'SysUsersAdminStates.state_id' => $user->state])
                        ->order(['DataSalesRepresentative.id' => 'ASC'])->first();
                    }
                    */
                    $array_save = array(
                        'user_id' => $row->user_id,
                        'representative_id' => 8468, // <= Jessica //$findRep->user_id, 
                        'deleted' => 0,
                        'manual' => 0,
                        'cat_id' => 42, // <= Jessica //$findRep->id, 
                        'created' => date('Y-m-d H:i:s'),
                    );
        
                    $entity = $this->DataAssignedToRegister->newEntity($array_save);
                    if(!$entity->hasErrors()){
                        $this->DataAssignedToRegister->save($entity);
                    }
                    $row->deleted = 1;
                    $this->DataAssignedJobs->save($row);

                    $Main = new MainController();
                    //$Main->notify_devices('MySpaLive - There is a new lead assigned to you: ' . $user->name . ' ' . $user->lname . ', ' . date('m-d-Y') .', ' . $user->phone, array($findRep->user_id), true, false, true, array(), '', array(), true);
                    $Main->notify_devices('MySpaLive - There is a new lead assigned to you: ' . $user->name . ' ' . $user->lname . ', ' . date('m-d-Y') .', ' . $user->phone, array(8468), true, false, true, array(), '', array(), true);
                }
            }
        }
    }
}