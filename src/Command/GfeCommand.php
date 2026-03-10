<?php
namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\ORM\TableRegistry;
use SpaLiveV1\Controller\MainController;

use App\Controller\AppPluginController;
use Cake\I18n\FrozenTime;
use Cake\Core\Configure;
use Cake\Utility\Security;
use Cake\Utility\Text;
use Cake\Utility\Hash;
require_once(ROOT . DS . 'vendor' . DS  . 'Twilio' . DS . 'autoload.php');
use Twilio\Rest\Client;
use Twilio\Exceptions\TwilioException;
require_once(ROOT . DS . 'vendor' . DS  . 'stripe' . DS . 'init.php');

class GfeCommand extends Command{

    public function execute(Arguments $args, ConsoleIo $io){
        // Set the work time range
        $workStartTime = strtotime('08:00'); // 8:00 AM
        $workEndTime = strtotime('20:00'); // 8:00 PM

        // Get the current time
        $currentTime = time();
        // Check if the current time is within the work time range
        if ($currentTime >= $workStartTime && $currentTime <= $workEndTime) {
            //echo "It's work time!";
            $this->gfe_unclamed();
        } else {
            //echo "It's not work time.";
        }
    }

    public function gfe_unclamed(){                

        $this->loadModel('SpaLiveV1.DataConsultation');
        $fields = ['DataConsultation.id',
                'DataConsultation.schedule_date',
                'SysUsers.id', 
                'SysUsers.phone',
                'SysUsers.email',
                'SysUsers.name',
                'SysUsers.lname'];
        $str_now = date('Y-m-d H:i:s');
        $T = "TIMESTAMPDIFF(HOUR,  '{$str_now}', DataConsultation.schedule_date) >= 0";
        $where = array(
            'SysUsers.deleted'         => 0,
            'DataConsultation.deleted' => 0,            
            'DataConsultation.status'  => "INIT",
            'DataConsultation.assistance_id ' => 0,
            $T
        );
        $join = array(            
            'SysUsers'         => ['table' => 'sys_users',         'type' => 'INNER', 'conditions' => 'SysUsers.id = DataConsultation.patient_id'],
            
        );
        $arr_certificates = $this->DataConsultation->find()
            ->select($fields)
            ->join($join)
            ->where($where)
            ->all();
        $this->log(__LINE__ . ' ' . json_encode($arr_certificates));
        //gfe+ci
        $this->loadModel('SpaLiveV1.SysUsers');
        $fields = ['SysUsers.id',                
                'SysUsers.phone',
                'SysUsers.name',
                'SysUsers.lname',
                'SysUsers.email'];

        $where = array(            
            'SysUsers.deleted'         => 0,
            'SysUsers.active'         => 1,
            'SysUsers.deleted'         => 0,
        );

        $str_query_find = 'SELECT sys_users.id, sys_users.phone, sys_users.name, sys_users.lname , sys_users.email,NS.allow_sms from sys_users
        LEFT JOIN data_notifications_settings NS ON NS.user_id = sys_users.id                                            
           where (NS.allow_sms = 1 OR NS.allow_sms IS NULL) AND sys_users.is_test = 0 AND (sys_users.type = "gfe+ci" or sys_users.type = "examiner")and sys_users.deleted = 0 and sys_users.active = 1 and sys_users.deleted = 0 and sys_users.name NOT LIKE "%test%" AND sys_users.lname NOT LIKE "%test%"  ';
        $arr_gfe = $this->SysUsers->getConnection()->execute($str_query_find)->fetchAll('assoc');            

        $reminders = array();
        foreach ($arr_certificates as $c) {             
            foreach ($arr_gfe as $key => $value) {                                 
                $type = 'REMIND_CLAIM_GFE_SCHEDULED';            
                if(isset($value["phone"])){
                    $reminder = array(
                        'from_id' => $c['SysUsers']['id'],
                        'type'    => $type,
                        'form'    => 'SMS',
                        'contact' => $value["phone"],
                        'created' => date('Y-m-d H:i:s'),
                        'status'  => 'DONE',
                        'patient' => $c['SysUsers']['name'] .' '. $c['SysUsers']['lname'],
                    );
                    array_push($reminders, $reminder);
                }                        
                array_push($reminders, $reminder);     
            }       
        }

        $res = array();
        $this->loadModel('SpaLiveV1.DataSendReminders');         
        foreach ($reminders as $r){            
            if(isset($r['contact'])){
                $msg = 'There is an unclaimed scheduled appointment ready for you to claim. Patient name '. $r['patient'];
                $resp = $this->send_reminder_sms($r['contact'],$msg);
                if(!$resp){
                    $r['status'] = 'Failure';
                }
            }
            $fields = ['DataSendReminders.created'];
            $where = array(
                'DataSendReminders.from_id' => $r['from_id'],
                'DataSendReminders.type' => $r['type'],
                'DataSendReminders.form' => $r['form'],
                'DataSendReminders.contact' => $r['contact']
            );
            $rem = $this->DataSendReminders->find()
                ->select($fields)
                ->where($where)
                ->order(['created' => 'DESC'])
                ->first();

            if(isset($rem)){                
                continue;                
            }

            array_push($res, $r);
            $newReminder = $this->DataSendReminders->newEntity($r);
            $this->DataSendReminders->save($newReminder);
        }
        
    }         

    public function send_reminder_sms($to, $body){         
        $phone = preg_replace('/\D+/', '', $to);;
        $phone_number = '';
        if(strlen($phone) == 11){
            $phone = substr($phone, 1);
            $phone_number = '+' . $phone;
        }else if(strlen($phone) == 10){
            $phone_number = '+1' . $phone;
        }else{
            return false;
        }
        
        try {
            $sid    = env('TWILIO_ACCOUNT_SID'); 
            $token  = env('TWILIO_AUTH_TOKEN'); 
            $twilio = new Client($sid, $token);

            $message = $twilio->messages
              ->create($phone_number, // to
                       array(
                            "messagingServiceSid" => "MG65978a5932f4ba9dd465e05d7b22195e",
                            "body" => $body
                       )
                );
            return true;
        } catch (TwilioException $e) {
            pr($e);
            return false;
        }
    }


    

    

   

 

   
}
