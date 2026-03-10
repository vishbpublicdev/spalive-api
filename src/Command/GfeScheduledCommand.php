<?php
namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\ORM\TableRegistry;
use Cake\Core\Configure;
use Cake\Utility\Text; 
require_once(ROOT . DS . 'vendor' . DS  . 'stripe' . DS . 'init.php');

use App\Command\RemindersCommand;

class GfeScheduledCommand extends Command{
    
    public function execute(Arguments $args, ConsoleIo $io){
        $this->remind_gfe_schedule_ten_minutes_before();
        $hr =  date("H");
        if($hr == "08")
            $this->remind_gfe_schedule_eight_am();
 
    }

    public function remind_gfe_schedule_eight_am(){
        $this->loadModel('SpaLiveV1.DataConsultation');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataSendReminders');
        $this->loadModel('SpaLiveV1.CatNotifications');

        $Reminders = new RemindersCommand();
        
        $today = date('Ymd');
        //$today = '20230414';
        //time 8am
        

        //medical
        $fields = ['DataConsultation.id', 'DataConsultation.patient_id', 'DataConsultation.assistance_id', 'DataConsultation.schedule_date', 'User.phone', 'User.email', 'User.id']; //[,' DATEDIFF(NOW(), SysUsers.modified) AS days'        
        $fields['sms_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'REMIND_GFE_SCHEDULE_TEN_MINUTES_BEFORE' and from_id =DataConsultation.assistance_id and deleted = 0)";        
        $fields['mail_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'REMIND_GFE_SCHEDULE_TEN_MINUTES_BEFORE' and from_id =DataConsultation.assistance_id and deleted = 0 and form = 'EMAIL')";        
        $fields['noty_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'REMIND_GFE_SCHEDULE_TEN_MINUTES_BEFORE' and from_id =DataConsultation.assistance_id and deleted = 0 and form = 'NOTIFICATION')"; 
        $fields['type'] = "(select 'medical')";        
        $fields['PATIENT'] = "(SELECT concat(sys_users.name,' ',sys_users.mname,' ',sys_users.lname) FROM sys_users where id = DataConsultation.patient_id)"; 
        $medical = $this->DataConsultation->find()->select($fields)
        ->join(['User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'DataConsultation.assistance_id = User.id']])
            ->where(['DataConsultation.status' => 'INIT', 'DataConsultation.assistance_id <> 0','DATE_FORMAT(DataConsultation.schedule_date, "%Y%m%d") = '.$today ])
            ->toArray();      
        
        $reminders = array();
        

        //patient
        $fields = ['DataConsultation.id', 'DataConsultation.patient_id', 'DataConsultation.assistance_id', 'DataConsultation.schedule_date', 'User.phone', 'User.email', 'User.id']; //[,' DATEDIFF(NOW(), SysUsers.modified) AS days'        
         $fields['sms_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'REMIND_GFE_SCHEDULE_TEN_MINUTES_BEFORE' and from_id =DataConsultation.assistance_id and deleted = 0 and form = 'SMS')";        
        $fields['mail_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'REMIND_GFE_SCHEDULE_TEN_MINUTES_BEFORE' and from_id =DataConsultation.assistance_id and deleted = 0 and form = 'EMAIL')";        
        $fields['noty_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'REMIND_GFE_SCHEDULE_TEN_MINUTES_BEFORE' and from_id =DataConsultation.assistance_id and deleted = 0 and form = 'NOTIFICATION')";        
        $fields['type'] = "(select 'patient')";        
        $patient = $this->DataConsultation->find()->select($fields)
        ->join(['User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'DataConsultation.patient_id = User.id']])
            ->where(['DataConsultation.status' => 'INIT', 'DataConsultation.assistance_id <> 0','DATE_FORMAT(DataConsultation.schedule_date, "%Y%m%d") = '.$today ])
            ->toArray();      
        
        
        $users = array_merge($medical, $patient);
        
        $type = 'REMIND_GFE_SCHEDULE_TEN_MINUTES_BEFORE';
        $notification = $this->CatNotifications->find()->where(['CatNotifications.title' => $type])->first();
        $this->log(__LINE__ . " " .json_encode($notification));
        $subject    =  $notification->subject;
        $body       = $notification->body;
        $body_push  = $notification->body_push;

        //return;/
        foreach ($users as $c) {
            if($c->type == 'patient'){
                //$subject    .= " " ;
                $body       .= " Schedule date " . $c->schedule_date->i18nFormat('MM-dd-Y hh:mm a');
                $body_push  .= " Schedule date " . $c->schedule_date->i18nFormat('MM-dd-Y hh:mm a');
            }else{
                $pat = $this->SysUsers->find()->select(['SysUsers.name', 'SysUsers.name', 'SysUsers.lname','SysUsers.id'])
                ->where(['id' => $c['User']['id']])->first();
                $body       .= " Schedule date " . $c->schedule_date->i18nFormat('MM-dd-Y hh:mm a') . ", with ". $pat->name . " ". $pat->mname . " ". $pat->lname;
                $body_push  .= " Schedule date " . $c->schedule_date->i18nFormat('MM-dd-Y hh:mm a') . ", with ". $pat->name . " ". $pat->mname . " ". $pat->lname;
            }
            $this->log(__LINE__ . " " .$subject);$this->log(__LINE__ . " " .$body);$this->log(__LINE__ . " " .$body_push);
            
            if(intval($c["sms_send"])!=0){
                if(isset($c["User"]["phone"])){
                    $reminder = array(
                        'from_id' => $c['User']['id'],
                        'type'    => $type,
                        'form'    => 'SMS',
                        'contact' => $c["User"]["phone"],
                        'created' => date('Y-m-d H:i:s')
                    );                    
                    array_push($reminders, $reminder);
                    $newReminder = $this->DataSendReminders->newEntity($reminder);
                    $res = $Reminders->send_reminder_sms($c["User"]["phone"], $body);
                    $rem = $this->DataSendReminders->save($newReminder);
                    
                }                                                                
            }
            if(intval($c["mail_send"])!=0){
                if(isset($c["User"]["email"])){
                    $reminder = array(
                        'from_id' => $c['User']['id'],
                        'type'    => $type,
                        'form'    => 'EMAIL',
                        'contact' => $c["User"]["email"],
                        'created' => date('Y-m-d H:i:s')
                    );                    
                    array_push($reminders, $reminder);
                    $newReminder = $this->DataSendReminders->newEntity($reminder);            
                    $rem = $this->DataSendReminders->save($newReminder);
                    $res = $Reminders->send_reminder_email($c["User"]["email"], $subject, $body);
                    $this->save_reminder($rem->id,$res);
                }
            }
            if(intval($c["noty_send"])!=0){
                if(isset($c["id"])){
                    $reminder = array(
                        'from_id' => $c['User']['id'],
                        'type'    => $type,
                        'form'    => 'NOTIFICATION',
                        'contact' => ($c["type"] =="medical" ? $c->assistance_id : $c->patient_id),
                        'created' => date('Y-m-d H:i:s')
                    );                    
                    array_push($reminders, $reminder);
                    $newReminder = $this->DataSendReminders->newEntity($reminder);            
                    $rem = $this->DataSendReminders->save($newReminder);
                    $res = $Reminders->send_reminder_notification(($c["type"] =="medical" ? $c->assistance_id : $c->patient_id), $body_push);
                    $this->save_reminder($rem->id,$res);
                }
            }   
                 
        }        
    }

    private function save_reminder($id, $status){
        $this->DataSendReminders->updateAll(
            array(
                'status' => $status,
                'tries'  => 1
            ),
            array(
                'id'     => $id
            )
        );
    }

    public function remind_gfe_schedule_ten_minutes_before(){
        $this->loadModel('SpaLiveV1.DataConsultation');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataSendReminders');
        $this->loadModel('SpaLiveV1.CatNotifications');

        $Reminders = new RemindersCommand();
        
        $today = date('Ymd');
        //$today = '20230414';
        //time 8am


        //medical
        $fields = ['DataConsultation.id', 'DataConsultation.patient_id', 'DataConsultation.assistance_id', 'DataConsultation.schedule_date', 'User.phone', 'User.email', 'User.id']; //[,' DATEDIFF(NOW(), SysUsers.modified) AS days'        
        $fields['sms_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'REMIND_GFE_SCHEDULE_TEN_MINUTES_BEFORE' and from_id =DataConsultation.id and deleted = 0 and contact = User.phone)";        
        $fields['mail_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'REMIND_GFE_SCHEDULE_TEN_MINUTES_BEFORE' and from_id =DataConsultation.id and deleted = 0 and form = 'EMAIL' and contact = User.email)";        
        $fields['noty_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'REMIND_GFE_SCHEDULE_TEN_MINUTES_BEFORE' and from_id =DataConsultation.id and deleted = 0 and form = 'NOTIFICATION' and contact = User.id)"; 
        $fields['type'] = "(select 'medical')";        
        $fields['PATIENT'] = "(SELECT concat(sys_users.name,' ',sys_users.mname,' ',sys_users.lname) FROM sys_users where id = DataConsultation.patient_id)"; 
        $medical = $this->DataConsultation->find()->select($fields)
        ->join(['User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'DataConsultation.assistance_id = User.id']])
            ->where(['DataConsultation.status' => 'INIT', 'DataConsultation.assistance_id <> 0','DATE_FORMAT(DataConsultation.schedule_date, "%Y%m%d") = '.$today ])
            ->toArray();      
        
        $reminders = array();
        

        //patient
        $fields = ['DataConsultation.id', 'DataConsultation.patient_id', 'DataConsultation.assistance_id', 'DataConsultation.schedule_date', 'User.phone', 'User.email', 'User.id']; //[,' DATEDIFF(NOW(), SysUsers.modified) AS days'        
        $fields['sms_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'REMIND_GFE_SCHEDULE_TEN_MINUTES_BEFORE' and from_id =DataConsultation.id and deleted = 0 and form = 'SMS' and contact = User.phone)";        
        $fields['mail_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'REMIND_GFE_SCHEDULE_TEN_MINUTES_BEFORE' and from_id =DataConsultation.id and deleted = 0 and form = 'EMAIL' and contact = User.email)";        
        $fields['noty_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'REMIND_GFE_SCHEDULE_TEN_MINUTES_BEFORE' and from_id =DataConsultation.id and deleted = 0 and form = 'NOTIFICATION' and contact = User.id)";        
        $fields['type'] = "(select 'patient')";        
        $patient = $this->DataConsultation->find()->select($fields)
        ->join(['User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'DataConsultation.patient_id = User.id']])
            ->where(['DataConsultation.status' => 'INIT', 'DataConsultation.assistance_id <> 0','DATE_FORMAT(DataConsultation.schedule_date, "%Y%m%d") = '.$today ])
            ->toArray();      
        
        
        $users = array_merge($medical, $patient);

        $type = 'REMIND_GFE_SCHEDULE_TEN_MINUTES_BEFORE';
        $notification = $this->CatNotifications->find()->where(['CatNotifications.title' => $type])->first();
        
        $subject    =  $notification->subject;
        

        //return;/
        foreach ($users as $c) {
            $body       = $notification->body;
            $body_push  = $notification->body_push;
            //$within10patient = $c->schedule_date->isWithinNext('15 minute');
            
            $within10patient = $c->schedule_date->isWithinNext('10 minutes');
            
            
            if(!$within10patient)
                continue;

            if($c->type == 'patient'){
                //$subject    .= " " ;
                $body       .= " Schedule date " . $c->schedule_date->i18nFormat('MM-dd-Y hh:mm a');
                $body_push  .= " Schedule date " . $c->schedule_date->i18nFormat('MM-dd-Y hh:mm a');
            }else{
                $body       .= " Schedule date " . $c->schedule_date->i18nFormat('MM-dd-Y hh:mm a') . ", with ". $c->PATIENT;
                $body_push  .= " Schedule date " . $c->schedule_date->i18nFormat('MM-dd-Y hh:mm a') . ", with ". $c->PATIENT;
            }

            
            if($c["sms_send"]==0){
                if(isset($c["User"]["phone"])){
                    $reminder = array(
                        'from_id' => $c['id'],
                        'type'    => $type,
                        'form'    => 'SMS',
                        'contact' => $c["User"]["phone"],
                        'created' => date('Y-m-d H:i:s')
                    );                    
                    array_push($reminders, $reminder);
                    $newReminder = $this->DataSendReminders->newEntity($reminder);
                    $res = $Reminders->send_reminder_sms($c["User"]["phone"], $body);
                    $rem = $this->DataSendReminders->save($newReminder);
                    
                }                                                                
            }
            if($c["mail_send"]==0){
                if(isset($c["User"]["email"])){
                    $reminder = array(
                        'from_id' => $c['id'],
                        'type'    => $type,
                        'form'    => 'EMAIL',
                        'contact' => $c["User"]["email"],
                        'created' => date('Y-m-d H:i:s')
                    );                    
                    array_push($reminders, $reminder);
                    $newReminder = $this->DataSendReminders->newEntity($reminder);            
                    $rem = $this->DataSendReminders->save($newReminder);
                    $res = $Reminders->send_reminder_email($c["User"]["email"], $subject, $body);
                    $this->save_reminder($rem->id,$res);
                }
            }
            if($c["noty_send"]==0){
                if(isset($c["id"])){
                    $reminder = array(
                        'from_id' => $c['id'],
                        'type'    => $type,
                        'form'    => 'NOTIFICATION',
                        'contact' => ($c["type"] =="medical" ? $c->assistance_id : $c->patient_id),
                        'created' => date('Y-m-d H:i:s')
                    );                    
                    array_push($reminders, $reminder);
                    $newReminder = $this->DataSendReminders->newEntity($reminder);            
                    $rem = $this->DataSendReminders->save($newReminder);
                    $res = $Reminders->send_reminder_notification(($c["type"] =="medical" ? $c->assistance_id : $c->patient_id), $body_push);
                    $this->save_reminder($rem->id,$res);
                }
            }                
        }        
    }
}