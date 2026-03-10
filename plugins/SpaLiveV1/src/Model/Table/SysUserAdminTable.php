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

    public function getRandomDoctor($injector_id = 0){
        //return 139;
        /*$find = $this->getConnection()->query( "SELECT U.is_test FROM sys_users U WHERE U.id = {$patient_id} AND U.deleted = 0")->fetchAll('assoc');
        if(isset($find[0])){
            if ($find[0]['is_test'] == 1)
                return -1;
        }
        //return 89;

    	$doctors = $this->find()->select(['SysUserAdmin.id'])->where(['SysUserAdmin.user_type' => 'DOCTOR','SysUserAdmin.deleted' => 0])->toArray();
    	$numDocts = sizeof($doctors);
    	$pos = rand(0, ($numDocts - 1));
    	return $doctors[$pos]['id'];*/ 
        
        $find = $this->getConnection()->query( "SELECT U.is_test, U.md_id FROM sys_users U WHERE U.id = {$injector_id} AND U.deleted = 0")->fetchAll('assoc');
        if(isset($find[0])){
            return $find[0]['md_id'];
        }else{
            return 0;
        }
    }


    public function getAssignedDoctor(){
        //return 139;
        $last = $this->getConnection()->query( "SELECT U.md_id FROM sys_users U WHERE U.deleted = 0 and U.md_id !=0 and type <> 'injector' order by id desc limit 1 ")->fetchAll('assoc');        
        if(isset($last))
            $last_d =  $last[0]['md_id'];
        else
            $last_d =0;
        
        if($last_d ==0){
            $doctors = $this->find()->select(['SysUserAdmin.id'])->where(['SysUserAdmin.user_type' => 'DOCTOR','SysUserAdmin.deleted' => 0])->toArray();
            $numDocts = sizeof($doctors);
            $pos = rand(0, ($numDocts - 1));
            return $doctors[$pos]['id'];
        }else{            
            $doctors = $this->find()->select(['SysUserAdmin.id'])->where(['SysUserAdmin.user_type' => 'DOCTOR','SysUserAdmin.deleted' => 0,'SysUserAdmin.id <>' => $last_d])->toArray();
            $numDocts = sizeof($doctors);
            $pos = rand(0, ($numDocts - 1));
            return $doctors[$pos]['id'];
        }
    }

    public function getAssignedDoctorInjector($id){    
        
        //return 139;
        $assigned = $this->getConnection()->query( "SELECT U.md_id, U.created FROM sys_users U WHERE U.deleted = 0  and type in ('injector', 'gfe+ci') and U.id = {$id} order by id desc limit 1 ")->fetchAll('assoc');        
        
        if(isset($assigned[0])){
            if(isset($assigned[0]['created'])){      
                
                $date1 = new \DateTime('2023-08-12');
                $date2 = new \DateTime($assigned[0]['created']);                
                if ($date1 >= $date2) { // created < 2023-08-12
                    //check gfe //md_id assigned                    
                    $gfe = $this->getConnection()->query( "select * from data_consultation where patient_id = {$id} and status <> 'CANCEL'  limit 1 ")->fetchAll('assoc');        
                    if(isset($gfe[0])){// has gfe
                        $date3 = new \DateTime('2023-08-12');
                        $date4 = new \DateTime($gfe[0]['schedule_date']);                
                        if ($date3 >= $date4) { // schedule_date < 2023-08-12
                            //return $gfe[0]['schedule_date'];
                            return $assigned[0]['md_id'];
                        }else{
                            return $this->getAssignedDoctorByInjector();
                        }                        
                    }else{                         
                        return $this->getAssignedDoctorByInjector();
                    }
                } else {// no gfe
                    return $this->getAssignedDoctorByInjector();                    
                }
                
            }

        }/*else{
            return;
        }*/                        
    }

    private function getAssignedDoctorByInjector(){
        //return 139;        
        $last = $this->getConnection()->query( "SELECT U.md_id FROM sys_users U WHERE U.deleted = 0 and U.md_id !=0 and type in ('injector', 'gfe+ci') order by id desc limit 1 ")->fetchAll('assoc');        
        if(isset($last))
            $last_d =  $last[0]['md_id'];
        else
            $last_d =0;
        
        if($last_d ==0){
            $doctors = $this->find()->select(['SysUserAdmin.id'])->where(['SysUserAdmin.user_type' => 'DOCTOR','SysUserAdmin.deleted' => 0])->toArray();
            $numDocts = sizeof($doctors);
            $pos = rand(0, ($numDocts - 1));
            return $doctors[$pos]['id'];
        }else{            
            $doctors = $this->find()->select(['SysUserAdmin.id'])->where(['SysUserAdmin.user_type' => 'DOCTOR','SysUserAdmin.deleted' => 0,'SysUserAdmin.id <>' => $last_d])->toArray();
            $numDocts = sizeof($doctors);
            $pos = rand(0, ($numDocts - 1));
            return $doctors[$pos]['id'];
        }
    }
}