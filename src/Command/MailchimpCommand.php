<?php
namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\ORM\TableRegistry;
use SpaLiveV1\Controller\MailChimpController;
use SpaLiveV1\Controller\MainController;

class MailchimpCommand extends Command{
    
    public function execute(Arguments $args, ConsoleIo $io){
        
        
        
        $arr_arguments = $args->getArguments();

        if (empty($arr_arguments)) {
            $this->log(__LINE__ . ' ' . json_encode(' no args'));
            return;
        }
        $this->log(__LINE__ . ' ' . json_encode($arr_arguments));
        $uid = $arr_arguments[0];
        

        $this->loadModel('SpaLiveV1.DataMailchimpCampaign');
        $campaign = $this->DataMailchimpCampaign->find()            
        ->where(['DataMailchimpCampaign.uid' => $uid])
        ->first();

        if(empty($campaign)){
             $this->log(__LINE__ . ' ' . json_encode(' campaign not found'));
            return;
        }

        $this->log(__LINE__ . ' ' . json_encode($campaign));
        $type = $campaign->type;
        $subject =  $campaign->subject;
        $body =  $campaign->body;
        $users =  json_decode($campaign->users);
        $this->log(__LINE__ . ' ' . json_encode( $type));
        $this->log(__LINE__ . ' ' . json_encode( $subject));
        $this->log(__LINE__ . ' ' . json_encode( $body));
        


        $is_sms = get('is_sms',0);
        $is_email = get('is_email',1);
        $now = date('Y-m-d H:i:s');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataModelPatient');        
        
        //$ent_user = array();
        $email_array = array();
        $field_valid = $is_email ? '(DNS.allow_email IS NULL OR DNS.allow_email = 1)' :
                       ($is_sms ? '(DNS.allow_sms IS NULL OR DNS.allow_sms = 1)' : '(DNS.allow_push IS NULL OR DNS.allow_push = 1)');

        

        /*if ($type == 'ALL') {
            $ent_user = $this->SysUsers->find()
            ->join(['DNS' => ['table' => 'data_notifications_settings', 'type' => 'LEFT', 'conditions' => 'DNS.user_id = SysUsers.id']])
            ->where(['SysUsers.deleted' => 0, 'SysUsers.login_status' => 'READY','SysUsers.active' => 1, $field_valid])
             ->group(['SysUsers.email'])
            ->toArray();
        } else if ($type == 'INACTIVE INJECTORS') {
            $ent_user = $this->SysUsers->find()
            ->join(['DNS' => ['table' => 'data_notifications_settings', 'type' => 'LEFT', 'conditions' => 'DNS.user_id = SysUsers.id']])
            ->where(['SysUsers.deleted' => 0, 'SysUsers.login_status' => 'READY','SysUsers.type' => 'injector','SysUsers.active' => 0, $field_valid])
             ->group(['SysUsers.email'])
            ->toArray();
        } else if ($type == 'PENDING PAYMENT INJECTORS') {
            $ent_user = $this->SysUsers->find()
            ->join(['DNS' => ['table' => 'data_notifications_settings', 'type' => 'LEFT', 'conditions' => 'DNS.user_id = SysUsers.id']])
            ->where(['SysUsers.deleted' => 0, 'SysUsers.login_status' => 'PAYMENT','SysUsers.type' => 'injector','SysUsers.active' => 1, $field_valid])
             ->group(['SysUsers.email'])
            ->toArray();
        } else if($type == 'INJECTOR BOOKED BASIC TRAINING'){
            $ent_user = $this->SysUsers->find()
            ->join([
                'DNS' => ['table' => 'data_notifications_settings', 'type' => 'LEFT', 'conditions' => 'DNS.user_id = SysUsers.id'],
                'DataTrainings' => ['table' => 'data_trainings', 'type' => 'INNER', 'conditions' => 'DataTrainings.user_id = SysUsers.id'],
                'CatTrainings' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainings.id = DataTrainings.training_id'],
                ])
            ->where(['SysUsers.deleted' => 0,'SysUsers.type' => 'injector','SysUsers.active' => 1, 'CatTrainings.scheduled >=' => $now, 'CatTrainings.level' => 'LEVEL 1', $field_valid])
             ->group(['SysUsers.email'])
            ->toArray();
        } else if($type == 'MODEL PATIENTS') {
            $ent_user = $this->DataModelPatient->find()
            ->join(['DNS' => ['table' => 'data_notifications_settings', 'type' => 'LEFT', 'conditions' => 'DNS.user_id = SysUsers.id']])
            ->where(['DataModelPatient.deleted' => 0, 'DataModelPatient.email !=' => '', $field_valid])
             ->group(['SysUsers.email'])
            ->toArray();
            foreach ($ent_user as $row) {
                $email_array[] = $row['email'];
            }
            $email_string = implode(",",$email_array);
        } else if ($type == 'INJECTORS WITH SUBSCRIPTION') {
            $ent_user = $this->SysUsers->find()
            ->join(['DS' => ['table' => 'data_subscriptions', 'type' => 'INNER', 'conditions' => 'DS.user_id = SysUsers.id AND DS.deleted = 0 AND DS.status = "ACTIVE"']])
            ->where(['SysUsers.deleted' => 0, 'SysUsers.type' => 'injector','SysUsers.active' => 1])
             ->group(['SysUsers.email'])
            ->toArray();
        } else if ($type == 'PATIENTS LOOKING FOR PROVIDER') {
            $ent_user = $this->SysUsers->find()
            ->join(['DC' => ['table' => 'data_consultation', 'type' => 'INNER', 'conditions' => 'DC.patient_id = SysUsers.id AND DC.status = "CERTIFICATE"']])
            ->join(['DCE' => ['table' => 'data_certificates', 'type' => 'INNER', 'conditions' => 'DCE.consultation_id = DC.id AND DCE.deleted = 0 AND CURDATE() <= DCE.date_expiration']])
            ->where(['SysUsers.deleted' => 0, 'SysUsers.type' => 'patient','SysUsers.active' => 1, 'SysUsers.treatment_type' => 'OPENREQUETS'])
             ->group(['SysUsers.email'])
            ->toArray();
        } else if ($type == 'PENDING FROM APPROVAL EXAMINERS') {
            $ent_user = $this->SysUsers->find()->where(['SysUsers.deleted' => 0, 'SysUsers.type' => 'examiner', 'SysUsers.active' => 1, 'SysUsers.login_status' => 'APPROVE'])->toArray();

        } else if ($type == 'UNSUBSCRIBED') {
            $ent_user = $this->SysUsers->find()
            ->join(['DS' => ['table' => 'data_subscriptions', 'type' => 'INNER', 'conditions' => 'DS.user_id = SysUsers.id']])
            ->where(['DS.status' => "CANCELLED"])->group(['SysUsers.email'])->toArray();

        } else if ($type == 'TRIAL ON HOLD') {
            $ent_user = $this->SysUsers->find()
            ->join(['DS' => ['table' => 'data_subscriptions', 'type' => 'INNER', 'conditions' => 'DS.user_id = SysUsers.id']])
            ->where(['DS.status' => "TRIALONHOLD"])->group(['SysUsers.email'])->toArray();

        } else if ($type == 'ON HOLD') {
            $ent_user = $this->SysUsers->find()
            ->join(['DS' => ['table' => 'data_subscriptions', 'type' => 'INNER', 'conditions' => 'DS.user_id = SysUsers.id']])
            ->where(['DS.status' => "HOLD"])->group(['SysUsers.email'])->toArray();

        } else if($type =='WEIGHT LOSS SPECIALIST'){
            $ent_user = $this->SysUsers->find()
            ->join(['DU' => ['table' => 'data_users_other_services_check_in', 'type' => 'INNER', 'conditions' => 'DU.user_id = SysUsers.id']])
            ->where(
                ['SysUsers.deleted' => 0,
                 'SysUsers.type IN' => array('injector', 'gfe+ci'), 
                 'SysUsers.active' => 1, 
                 'DU.deleted' => 0, 
                 'DU.status' => 'WLSHOME'])
                  ->group(['SysUsers.email'])
            ->toArray();
        } else if($type == 'InjectorSubscribedToFillers'){
            $ent_user = $this->SysUsers->find()->select(['SysUsers.id','SysUsers.email','SysUsers.name','SysUsers.lname'])
            ->join(['DS' => ['table' => 'data_subscriptions', 'type' => 'INNER', 'conditions' => 'DS.user_id = SysUsers.id']])
            ->where(
                ['SysUsers.deleted' => 0,
                 'SysUsers.type IN' => array('injector'), 
                 'SysUsers.active' => 1,                   
                 'DS.payment_details like ' => '%filler%'])
                  ->group(['SysUsers.email'])
            ->toArray();            
        } else if($type == 'InjectorWithBasicTrainingWithoutAdvanced'){
            $_having['attend_course >'] =0 ;
            $_having['select_course_adv'] = 0;
            $_fields = ['SysUsers.id','SysUsers.email','SysUsers.name','SysUsers.lname'];
            $_fields['attend_course'] = "    (select count(id) from data_trainings where deleted = 0 and training_id in (select id from cat_trainings where level = 'LEVEL 1' and deleted = 0 ) and user_id = SysUsers.id and attended = 1)"; 
            $_fields['select_course_adv'] = "(select count(id) from data_trainings where deleted = 0 and training_id in (select id from cat_trainings where level = 'LEVEL 2' and deleted = 0 ) and user_id = SysUsers.id) ";
            //$fields['advCourse'] =      "   (SELECT count(DT.id) AS basicCourseBuy    FROM data_trainings DT JOIN cat_trainings CT ON CT.id = DT.training_id WHERE DT.deleted = 0 AND DT.user_id = SysUsers.id AND CT.level = 'LEVEL 2' AND CT.deleted = 0)";
            //$fields['basicCourseAttend'] = "(SELECT count(DT.id) AS basicCourseAttend FROM data_trainings DT JOIN cat_trainings CT ON CT.id = DT.training_id WHERE DT.deleted = 0 AND DT.user_id = SysUsers.id AND CT.level = 'LEVEL 1' AND CT.deleted = 0 AND DT.attended =1)";
            $ent_user = $this->SysUsers->find()->select($_fields)
            //->join(['DS' => ['table' => 'data_subscriptions', 'type' => 'INNER', 'conditions' => 'DS.user_id = SysUsers.id']])
            ->where(
                ['SysUsers.deleted' => 0,
                 'SysUsers.type IN' => array('injector'), 
                 'SysUsers.active' => 1,                   
                 ])
            ->having($_having)
             ->group(['SysUsers.email'])
            ->toArray();            
             
             
        } 
        else if($type == 'FULLYACTIVEIVTHERAPISTS'){
            $_having['ActiveOnIV >'] =0 ;            
            $_fields = ['SysUsers.id','SysUsers.email','SysUsers.name','SysUsers.lname'];
            $_fields['ActiveOnIV'] =            "(SELECT COUNT(DS.id) FROM data_subscriptions DS left join data_subscription_cancelled DSC  ON DSC.subscription_id =  DS.id WHERE DS.user_id = SysUsers.id AND DS.deleted = 0 AND DS.status = 'ACTIVE' AND payment_details  like '%iv%' and DSC.id is null)";                                    
            $ent_user = $this->SysUsers->find()->select($_fields)            
            ->where(
                ['SysUsers.deleted' => 0,
                 'SysUsers.type IN' => array('injector'), 
                 'SysUsers.active' => 1,
                 'SysUsers.name  NOT LIKE' => '%test%',
                 'SysUsers.mname NOT LIKE' => '%test%',
                 'SysUsers.lname NOT LIKE' => '%test%',
                 ])
                  ->group(['SysUsers.email'])
            ->having($_having)
            ->toArray();                        
        }else if($type == 'USERSREQUESTEDBYASHLANTOGETTHEW9'){            
            $_fields = ['SysUsers.id','SysUsers.email','SysUsers.name','SysUsers.lname'];            
            $ent_user = $this->SysUsers->find()->select($_fields)            
            ->where(
                [
                 'SysUsers.email IN' => array(
                 'Amnah.works@gmail.com',
                 'brendalynn1975@gmail.com',
                 'icaarly760@myspalive.com',
                 'ortizdj@icloud.com',
                 'kellyfischerRN@gmail.com',
                 'Aubellebeautystudio@gmail.com',
                 'mstagura@gmail.com',
                 'sculptedbywonder@yahoo.com',
                 's.nadine.br@gmail.com',
                 'rosemary.p.mcgarry@gmail.com',
                ),                  
                 ])            
                 ->toArray();                        
        }else if($type == 'LEVEL3INJECTORS_STUDENTS'){            
            $_having['attend_course '] =0;
            $_having['select_course >']=0;
            $_fields = ['SysUsers.id','SysUsers.email','SysUsers.name','SysUsers.lname'];
            $_fields['attend_course'] = "(select count(id) from data_trainings where deleted = 0 and training_id in (select id from cat_trainings where level = 'LEVEL 3 MEDICAL' and deleted = 0 ) and user_id = SysUsers.id and attended = 1)"; 
            $_fields['select_course'] = "(select count(id) from data_trainings where deleted = 0 and training_id in (select id from cat_trainings where level = 'LEVEL 3 MEDICAL' and deleted = 0 ) and user_id = SysUsers.id) ";            
            $ent_user = $this->SysUsers->find()->select($_fields)            
            ->where(
                ['SysUsers.deleted' => 0,
                 'SysUsers.type IN' => array('injector'), 
                 'SysUsers.active' => 1,                   
                 ])
                  ->group(['SysUsers.email'])
            ->having($_having)
            ->toArray();            
             
        } else if($type == 'LEVEL3INJECTORS_GRADUATED'){            
            $_having['attend_course >'] =0;
            $_having['select_course >']=0;
            $_fields = ['SysUsers.id','SysUsers.email','SysUsers.name','SysUsers.lname'];
            $_fields['attend_course'] = "(select count(id) from data_trainings where deleted = 0 and training_id in (select id from cat_trainings where level = 'LEVEL 3 MEDICAL' and deleted = 0 ) and user_id = SysUsers.id and attended = 1)"; 
            $_fields['select_course'] = "(select count(id) from data_trainings where deleted = 0 and training_id in (select id from cat_trainings where level = 'LEVEL 3 MEDICAL' and deleted = 0 ) and user_id = SysUsers.id) ";            
            $ent_user = $this->SysUsers->find()->select($_fields)            
            ->where(
                ['SysUsers.deleted' => 0,
                 'SysUsers.type IN' => array('injector'), 
                 'SysUsers.active' => 1,                   
                 ])
                  ->group(['SysUsers.email'])
            ->having($_having)
            ->toList();                      
        }   else if($type == 'INJECTORS WITH SUBSCRIPTION AND ON HOLD'){
            $_where[] = ['SysUsers.deleted' => 0, 'SysUsers.type' => 'injector','SysUsers.active' => 1, 'SysUsers.name NOT LIKE' => '%test%','SysUsers.mname NOT LIKE' => '%test%','SysUsers.lname NOT LIKE' => '%test%'];
            $_where['OR'] = [['DS.status' => "HOLD"], ['DS.status' => "ACTIVE"]];
            $ent_user = $this->SysUsers->find()->select(['SysUsers.id','SysUsers.email','SysUsers.name','SysUsers.lname'])
            ->distinct(['SysUsers.id'])  
            ->join(['DS' => ['table' => 'data_subscriptions', 'type' => 'INNER', 'conditions' => 'DS.user_id = SysUsers.id AND DS.deleted = 0 ']])
            //->where(['SysUsers.deleted' => 0, 'SysUsers.type' => 'injector','SysUsers.active' => 1])
            ->where($_where)
             ->group(['SysUsers.email'])
            ->toArray();            
            
        }   else if($type == 'qualityassurance'){
            $_where[] = ['SysUsers.deleted' => 0, 'SysUsers.type' => 'injector','SysUsers.active' => 1, 'SysUsers.email' => 'qualityassurance@myspalive.com',];
            
            $ent_user = $this->SysUsers->find()->select(['SysUsers.id','SysUsers.email','SysUsers.name','SysUsers.lname'])
            ->distinct(['SysUsers.id'])  
            //->join(['DS' => ['table' => 'data_subscriptions', 'type' => 'INNER', 'conditions' => 'DS.user_id = SysUsers.id AND DS.deleted = 0 ']])            
            ->where($_where)
             ->group(['SysUsers.email'])
            ->toArray();            
            
                                          
        }  else if($type == 'GFENOTRESPONSE'){            
            $_having['gfe_cancel >'] =0;
            $_having['gfe_certificate']=0;
            $_fields = ['SysUsers.id','SysUsers.email','SysUsers.name','SysUsers.lname'];
            $_fields['gfe_cancel'] =    "(select count(id) from data_consultation where patient_id = SysUsers.id and status in ('CANCEL') and created >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)  )"; 
            $_fields['gfe_certificate'] = "((select count(id)                      from  data_certificates 	where  consultation_id in (select group_concat(id) from data_consultation where patient_id = SysUsers.id and status in ('INIT','CERTIFICATE')) and date_expiration >= CURDATE() and deleted = 0 )) ";            
            $ent_user = $this->SysUsers->find()->select($_fields)            
            ->where(
                ['SysUsers.deleted' => 0,
            
                 'SysUsers.active' => 1,                   
                 ])
                  ->group(['SysUsers.email'])
            ->having($_having)
            ->toArray();                                               
        }  else if($type == 'test'){            
            
            $ent_user = $this->SysUsers->find()->select(['SysUsers.id','SysUsers.email','SysUsers.name','SysUsers.lname'])
            ->where(
                ['SysUsers.id in ' => [337,9276,17469],
            
                
                 ])
            
            ->toArray();                                               
        }
        else{
            $ent_user = $this->SysUsers->find()->where(['SysUsers.deleted' => 0, 'SysUsers.login_status' => 'READY','SysUsers.active' => 1,'SysUsers.type' => strtolower($type), 'SysUsers.email NOT LIKE' => 'hjmcglasson@yahoo.com'])->group(['SysUsers.email'])->toArray();
        }*/   

        //$this->log(__LINE__ . ' ' . json_encode($ent_user));
        $ent_user = $this->SysUsers->find()->select(['SysUsers.id','SysUsers.email','SysUsers.name','SysUsers.lname'])
            ->where(
                ['SysUsers.id in ' => $users])            
            ->toArray(); 
        $constants = [
            'Injector'   => '*|FNAME|* *|LNAME|*',
        ];
        $msg_mail = $body;
        foreach($constants as $key => $value){
            $msg_mail = str_replace($key, $value, $msg_mail);
        }
        $Main = new MainController();
        $body = $Main->getEmailFormat($msg_mail);
        //$this->log(__LINE__ . ' ' . json_encode( $body));
        $MailChimp = new MailChimpController();
        $MailChimp->main($ent_user,$type,$body,$subject,$uid);

        
    }

}