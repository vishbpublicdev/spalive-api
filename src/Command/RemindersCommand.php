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

class RemindersCommand extends Command{
    protected $mailgunKey = null;

    protected function getMailgunKey(): ?string
    {
        if ($this->mailgunKey === null) {
            $this->mailgunKey = env('MAILGUN_API_KEY');
        }
        return $this->mailgunKey;
    }

    public function execute(Arguments $args, ConsoleIo $io){
        $this->certificates();
        // $this->model();
        $this->TreatmentStartingSoon();
        $this->TreatmentStartingSoonInjector();
        $this->patient_not_confirmed();
        //echo "LONGFORM";
        $this->patient_advance_registration();
        //$this->upload_photos_treatment();        
        $this->remind_upload_photos_weight_loss();
    }

    public function certificates(){
        // same day 7 am
        $date = date('Ymd');
        $hour = date('H');
        $this->log(__LINE__ . ' ' . json_encode($hour));
        
        if($hour != '07'){
            return;
        }

        $this->loadModel('SpaLiveV1.DataCertificates');
        $fields = ['DataCertificates.id',
                'DataCertificates.date_expiration',
                'SysUsers.phone',
                'SysUsers.email'];
        $where = array(
            'DataCertificates.deleted' => 0,
            'SysUsers.deleted'         => 0,
            'DataConsultation.deleted' => 0,
            'DataConsultation.status'  => "CERTIFICATE",
            'DataConsultation.treatments <>' => '',
        );
        $join = array(
            'DataConsultation' => ['table' => 'data_consultation', 'type' => 'INNER', 'conditions' => 'DataConsultation.id = DataCertificates.consultation_id'],
            'SysUsers'         => ['table' => 'sys_users',         'type' => 'INNER', 'conditions' => 'SysUsers.id = DataConsultation.patient_id'],
            
        );
        $arr_certificates = $this->DataCertificates->find()
            ->select($fields)
            ->join($join)
            ->where($where)
            ->all();

        $reminders = array();
        foreach ($arr_certificates as $c) {
            $within1 = $c->date_expiration->isWithinNext('1 days');
            $within15 = $c->date_expiration->isWithinNext('15 days');
            $type = '';
            if($within1){
                $type = 'RENEW_CERTIFICATE_SHORT';
            }else{
                if($within15){
                    $type = 'RENEW_CERTIFICATE_LONG';
                }
            }

            if($type != ''){
                if(isset($c["SysUsers"]["phone"])){
                    $reminder = array(
                        'from_id' => $c->id,
                        'type'    => $type,
                        'form'    => 'SMS',
                        'contact' => $c["SysUsers"]["phone"],
                        'created' => date('Y-m-d H:i:s')
                    );
                    array_push($reminders, $reminder);
                }
                if(isset($c["SysUsers"]["email"])){
                    $reminder = array(
                        'from_id' => $c->id,
                        'type'    => $type,
                        'form'    => 'EMAIL',
                        'contact' => $c["SysUsers"]["email"],
                        'created' => date('Y-m-d H:i:s')
                    );
                    array_push($reminders, $reminder);
                }
                $this->loadModel('SpaLiveV1.DataCertificates');
                $fields = ['id' => 'SysUsers.id'];
                $where = array(
                    'DataCertificates.deleted' => 0,
                    'DataCertificates.id' => $c->id,
                );
                $join = array(
                    'DataConsultation' => ['table' => 'data_consultation', 'type' => 'INNER', 'conditions' => 'DataConsultation.id = DataCertificates.consultation_id'],
                    'SysUsers'         => ['table' => 'sys_users',         'type' => 'INNER', 'conditions' => 'SysUsers.id = DataConsultation.patient_id'],
                );
                $data_certificate = $this->DataCertificates->find()
                    ->select($fields)
                    ->join($join)
                    ->where($where)
                    ->first();
                $reminder = array(
                    'from_id' => $c->id,
                    'type'    => $type,
                    'form'    => 'NOTIFICATION',
                    'contact' => $data_certificate->id,
                    'created' => date('Y-m-d H:i:s')
                );
                array_push($reminders, $reminder);
            }
        }

        $res = array();
        $this->loadModel('SpaLiveV1.DataSendReminders');
        foreach ($reminders as $r){
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
                $r_created = $rem->created;
                $r_type    = $r['type'];
                $within    = $r_type == 'RENEW_CERTIFICATE_LONG' ? '15 days' : '1 days';
                $wasWithin = $r_created->wasWithinLast($within);
                if($wasWithin){
                    continue;
                }
            }

            array_push($res, $r);
            $newReminder = $this->DataSendReminders->newEntity($r);
            $this->DataSendReminders->save($newReminder);
        }
        $this->send_reminders('RENEW_CERTIFICATE_SHORT');
        $this->send_reminders('RENEW_CERTIFICATE_LONG');
    }

    public function model(){

        $this->loadModel('SpaLiveV1.DataModelPatient');
        $this->loadModel('SpaLiveV1.CatTrainings');
        $fields = [
                'DataModelPatient.phone',
                'DataModelPatient.email',
                'scheduled' => 'CatTrainings.scheduled',
                'id' => 'CatTrainings.id'
            ];
        $where = array(
            'DataModelPatient.deleted' => 0,
            'DataModelPatient.deleted'         => 0,
        );
        $join = array(
            // 'DataTreatment' => ['table' => 'data_treatment', 'type' => 'INNER', 'conditions' => 'DataConsultation.id = DataTreatment.consultation_id'],
            'CatTrainings'         => ['table' => 'cat_trainings',         'type' => 'INNER', 'conditions' => 'CatTrainings.id = DataModelPatient.registered_training_id'],
            
        );
        $arr_models = $this->DataModelPatient->find()
            ->select($fields)
            ->join($join)
            ->where($where)
            ->all();
        $reminders = array();
        foreach ($arr_models as $c) {
            $fr = new FrozenTime($c->scheduled);
            $within7 = $fr->isWithinNext('1 day');
            $type = '';
            if($within7){
                $type = 'MODEL_PATIENT_REMINDER';
            }

            if($type != ''){
                if(isset($c["SysUsers"]["phone"])){
                    $reminder = array(
                        'from_id' => $c->id,
                        'type'    => $type,
                        'form'    => 'SMS',
                        'contact' => $c["SysUsers"]["phone"],
                        'created' => date('Y-m-d H:i:s')
                    );
                    array_push($reminders, $reminder);
                }
                if(isset($c["SysUsers"]["email"])){
                    $reminder = array(
                        'from_id' => $c->id,
                        'type'    => $type,
                        'form'    => 'EMAIL',
                        'contact' => $c["SysUsers"]["email"],
                        'created' => date('Y-m-d H:i:s')
                    );
                    array_push($reminders, $reminder);
                }
                /* $this->loadModel('SpaLiveV1.DataModelPatient');
                $fields = ['id' => 'DataModelPatient.id'];
                pr($c -> id);
                $where = array(
                    'DataModelPatient.deleted' => 0,
                    'DataModelPatient.id' => $c->id,

                );
                $join = array(
                    // 'DataConsultation' => ['table' => 'data_consultation', 'type' => 'INNER', 'conditions' => 'DataConsultation.id = DataCertificates.consultation_id'],
                    'CatTrainings'         => ['table' => 'cat_trainings',         'type' => 'INNER', 'conditions' => 'CatTrainings.id = DataModelPatient.registered_training_id'],
                );
                $data_model = $this->DataModelPatient->find()
                    ->select($fields)
                    ->join($join)
                    ->where($where)
                    ->first();
                $reminder = array(
                    'from_id' => $c->id,
                    'type'    => $type,
                    'form'    => 'NOTIFICATION',
                    'contact' => $data_model->id,
                    'created' => date('Y-m-d H:i:s')
                ); */
                // array_push($reminders, $reminder);
                pr($arr_models);

            }
            
        }
        $res = array();
        $this->loadModel('SpaLiveV1.DataSendReminders');
        foreach ($reminders as $r){
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
                ->first();

            if(isset($rem)){
                $r_created = $rem->created;
                $r_type    = $r['type'];
                $within    = $r_type == 'RENEW_CERTIFICATE_LONG' ? '15 days' : '1 day';
                $wasWithin = $r_created->wasWithinLast($within);
                if($wasWithin){
                    continue;
                }
            }

            array_push($res, $r);
            $newReminder = $this->DataSendReminders->newEntity($r);
            $this->DataSendReminders->save($newReminder);
        }
        $this->send_reminders('MODEL_PATIENT_REMINDER');
    }

    public function TreatmentStartingSoon(){

        $this->loadModel('SpaLiveV1.DataTreatment');
        $fields = ['DataTreatment.id',
                'DataTreatment.schedule_date',
                'DataTreatment.state',
                'SysUsers.phone',
                'SysUsers.email'];
        $where = array(
            'DataTreatment.deleted' => 0,
            'DataTreatment.status' => 'CONFIRM',
            'SysUsers.deleted'         => 0,
        );
        $join = array(
            // 'DataTreatment' => ['table' => 'data_treatment', 'type' => 'INNER', 'conditions' => 'DataConsultation.id = DataTreatment.consultation_id'],
            'SysUsers'         => ['table' => 'sys_users',         'type' => 'INNER', 'conditions' => 'SysUsers.id = DataTreatment.patient_id'],
        );
        $arr_models = $this->DataTreatment->find()
            ->select($fields)
            ->join($join)
            ->where($where)
            ->all();
        // pr($arr_models);
        $reminders = array();
        foreach ($arr_models as $c) {
            //$within30patient = $c->schedule_date->isWithinNext('30 minute');
            $within30patient = $this->shouldSendReminderByStateId($c->state, $c->schedule_date);
            $type = '';
            if($within30patient){
                $type = 'TREATMENT_STARTING_SOON';
            }
            if($type != ''){
                if(isset($c["SysUsers"]["phone"])){
                    $reminder = array(
                        'from_id' => $c->id,
                        'type'    => $type,
                        'form'    => 'SMS',
                        'contact' => $c["SysUsers"]["phone"],
                        'created' => date('Y-m-d H:i:s')
                    );
                    array_push($reminders, $reminder);
                }
                if(isset($c["SysUsers"]["email"])){
                    $reminder = array(
                        'from_id' => $c->id,
                        'type'    => $type,
                        'form'    => 'EMAIL',
                        'contact' => $c["SysUsers"]["email"],
                        'created' => date('Y-m-d H:i:s')
                    );
                    array_push($reminders, $reminder);
                }
                $this->loadModel('SpaLiveV1.DataTreatment');
                $fields = ['id' => 'SysUsers.id'];
                $where = array(
                    'DataTreatment.deleted' => 0,
                    'DataTreatment.id' => $c->id,
                );
                $join = array(
                    // 'DataConsultation' => ['table' => 'data_consultation', 'type' => 'INNER', 'conditions' => 'DataConsultation.id = DataCertificates.consultation_id'],
                    'SysUsers'         => ['table' => 'sys_users',         'type' => 'INNER', 'conditions' => 'SysUsers.id = DataTreatment.patient_id'],
                );
                $data_model = $this->DataTreatment->find()
                    ->select($fields)
                    ->join($join)
                    ->where($where)
                    ->first();
                $reminder = array(
                    'from_id' => $c->id,
                    'type'    => $type,
                    'form'    => 'NOTIFICATION',
                    'contact' => $data_model->id,
                    'created' => date('Y-m-d H:i:s')
                );
                array_push($reminders, $reminder);
            }
            
        }
        $res = array();
        $this->loadModel('SpaLiveV1.DataSendReminders');
        foreach ($reminders as $r){
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
                ->first();

            if(isset($rem)){
                $r_created = $rem->created;
                $r_type    = $r['type'];
                $within    = $r_type == 'RENEW_CERTIFICATE_LONG' ? '15 days' : '1 day';
                $wasWithin = $r_created->wasWithinLast($within);
                if($wasWithin){
                    continue;
                }
            }

            array_push($res, $r);
            $newReminder = $this->DataSendReminders->newEntity($r);
            $this->DataSendReminders->save($newReminder);
        }
        pr($arr_models);
        $this->send_reminders('TREATMENT_STARTING_SOON');
    }

    public function patient_not_confirmed(){
        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.DataClaimTreatments');
        $Main = new MainController();

        $fields = ['DataTreatment.id', 'DataTreatment.reminder', 'DataTreatment.schedule_date', 'DataTreatment.patient_id', 'DataTreatment.created'];
        $fields['claims'] = "(SELECT COUNT(D.id) FROM data_claim_treatments D WHERE D.treatment_uid = DataTreatment.uid AND D.deleted = 0)";
        $fields['date_claim'] = "(SELECT D.created FROM data_claim_treatments D WHERE D.treatment_uid = DataTreatment.uid AND D.deleted = 0 LIMIT 1)";

        $ent_treatments = $this->DataTreatment->find()->select($fields)
            ->where(['DataTreatment.deleted' => 0, 'DataTreatment.status' => 'PETITION', 'DataTreatment.reminder' => 0 ,'(DATE_FORMAT(DataTreatment.schedule_date, "%Y-%m-%d %H:%m:%s") > "' . date('Y-m-d H:i:s') . '")'])
            ->all();
        if(Count($ent_treatments) > 0){
            foreach ($ent_treatments as $treatment) {
                if(date('Y-m-d H:i:s', strtotime($treatment->created->i18nFormat('yyyy-MM-dd HH:mm:ss') . '+ 2 days')) <= date('Y-m-d H:i:s') && $treatment->claims == 0){
                    continue;
                }

                if($treatment->claims >= 1 && date('Y-m-d H:i:s', strtotime($treatment->date_claim . '+ 12 hours')) <= date('Y-m-d H:i:s')){
                    $this->DataTreatment->updateAll(['reminder' => 1], ['id' => $treatment->id]);
                    $Main->notify_devices('PATIENT_NOT_CONFIRMED',array($treatment->patient_id),false,false,false,array(),'',array(),true);
                }
            }
        }
    }

    public function TreatmentStartingSoonInjector(){

        $this->loadModel('SpaLiveV1.DataTreatment');
        $fields = ['DataTreatment.id',
                'DataTreatment.schedule_date',
                'DataTreatment.state',
                'SysUsers.phone',
                'SysUsers.email'];
        $where = array(
            'DataTreatment.deleted' => 0,
            'DataTreatment.status' => 'CONFIRM',
            'SysUsers.deleted'         => 0,
        );
        $join = array(
            'SysUsers'         => ['table' => 'sys_users',         'type' => 'INNER', 'conditions' => 'SysUsers.id = DataTreatment.assistance_id'],
        );
        $arr_models = $this->DataTreatment->find()
            ->select($fields)
            ->join($join)
            ->where($where)
            ->all();
        //pr($arr_models);
        $reminders = array();
        foreach ($arr_models as $c) {
            //$within30patient = $c->schedule_date->isWithinNext('30 minute');
            $within30patient = $this->shouldSendReminderByStateId($c->state, $c->schedule_date);
            $type = '';
            if($within30patient){
                $type = 'TREATMENT_STARTING_SOON_INJECTOR';   
            }
            if($type != ''){
                if(isset($c["SysUsers"]["phone"])){
                    $reminder = array(
                        'from_id' => $c->id,
                        'type'    => $type,
                        'form'    => 'SMS',
                        'contact' => $c["SysUsers"]["phone"],
                        'created' => date('Y-m-d H:i:s')
                    );
                    array_push($reminders, $reminder);
                }
                if(isset($c["SysUsers"]["email"])){
                    $reminder = array(
                        'from_id' => $c->id,
                        'type'    => $type,
                        'form'    => 'EMAIL',
                        'contact' => $c["SysUsers"]["email"],
                        'created' => date('Y-m-d H:i:s')
                    );
                    array_push($reminders, $reminder);
                }
                $this->loadModel('SpaLiveV1.DataTreatment');
                $fields = ['id' => 'SysUsers.id'];
                $where = array(
                    'DataTreatment.deleted' => 0,
                    'DataTreatment.id' => $c->id,
                );
                $join = array(
                    'SysUsers'         => ['table' => 'sys_users',         'type' => 'INNER', 'conditions' => 'SysUsers.id = DataTreatment.assistance_id'],
                );
                $data_model = $this->DataTreatment->find()
                    ->select($fields)
                    ->join($join)
                    ->where($where)
                    ->first();
                $reminder = array(
                    'from_id' => $c->id,
                    'type'    => $type,
                    'form'    => 'NOTIFICATION',
                    'contact' => $data_model->id,
                    'created' => date('Y-m-d H:i:s')
                );
                array_push($reminders, $reminder);
            }
            
        }
        $res = array();
        $this->loadModel('SpaLiveV1.DataSendReminders');
        foreach ($reminders as $r){
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
                ->first();

            if(isset($rem)){
                $r_created = $rem->created;
                $r_type    = $r['type'];
                $within    = $r_type == 'RENEW_CERTIFICATE_LONG' ? '15 days' : '1 day';
                $wasWithin = $r_created->wasWithinLast($within);
                if($wasWithin){
                    continue;
                }
            }

            array_push($res, $r);
            $newReminder = $this->DataSendReminders->newEntity($r);
            $this->DataSendReminders->save($newReminder);
        }
        $this->send_reminders('TREATMENT_STARTING_SOON_INJECTOR');
    }

    public function patient_advance_registration(){
        // same day 9 am
        $date = date('Ymd');
        $hour = date('H');
        $this->log(__LINE__ . ' ' . json_encode($hour));        
        if($hour != '09'){
            return;
        }
        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataSendReminders');
        $Main = new MainController();
        //step LONGFORM
        $fields = ['SysUsers.id', 'SysUsers.steps', 'SysUsers.modified', 'SysUsers.created', 'SysUsers.phone']; //[,' DATEDIFF(NOW(), SysUsers.modified) AS days'        
        $fields['sms_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'LONGFORM' and from_id =SysUsers.id and deleted = 0)";        
        $users = $this->SysUsers->find()->select($fields)
            ->where(['SysUsers.deleted' => 0, 'SysUsers.steps' => 'LONGFORM'])
            ->all();      

        $reminders = array();
        foreach ($users as $c) {
            $now =   date('Y-m-d H:i:s');
            $modified = $c['modified'];            
            $modified  = date('Y-m-d H:i:s', strtotime($c->modified->i18nFormat('yyyy-MM-dd HH:mm:ss') . ' ')) ;
            $timestamp1 = strtotime($modified);
            $timestamp2 = strtotime($now);            
            $hour = abs($timestamp2 - $timestamp1)/(60*60);            
                
            $type = 'LONGFORM';                         
            if($type != '' && $c["sms_send"]==0 && $hour > 23){
                if(isset($c["phone"])){
                    $reminder = array(
                        'from_id' => $c->id,
                        'type'    => $type,
                        'form'    => 'SMS',
                        'contact' => $c["phone"],
                        'created' => date('Y-m-d H:i:s')
                    );
                    //if (count($reminders)<1){
                        array_push($reminders, $reminder);
                        $newReminder = $this->DataSendReminders->newEntity($reminder);            
                        $this->DataSendReminders->save($newReminder);
                    //}                        
                }                                                
            }
                        
        }
        
        $this->send_reminders('LONGFORM');


        //GFEPAYMENT************************************************************************************************************************************************
        $fields = ['SysUsers.id', 'SysUsers.steps', 'SysUsers.last_status_change', 'SysUsers.created', 'SysUsers.phone']; //[,' DATEDIFF(NOW(), SysUsers.last_status_change) AS days'        
        $fields['sms_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'GFEPAYMENT' and from_id =SysUsers.id and deleted = 0)";        
        $users = $this->SysUsers->find()->select($fields)
            ->where(['SysUsers.deleted' => 0, 'SysUsers.steps' => 'GFEPAYMENT'])
            ->all();
        


        $reminders = array();
        foreach ($users as $c) {
            $now =   date('Y-m-d H:i:s');
            $last_status_change = $c['last_status_change'];            
            $last_status_change  = date('Y-m-d H:i:s', strtotime($c->last_status_change->i18nFormat('yyyy-MM-dd HH:mm:ss') . ' ')) ;
            $timestamp1 = strtotime($last_status_change);
            $timestamp2 = strtotime($now);            
            $hour = abs($timestamp2 - $timestamp1)/(60*60);
            //echo $hour;
                
            $type = 'GFEPAYMENT';                         
            if($type != '' && $c["sms_send"]==0 && $hour > 23){
                if(isset($c["phone"])){
                    $reminder = array(
                        'from_id' => $c->id,
                        'type'    => $type,
                        'form'    => 'SMS',
                        'contact' => $c["phone"],
                        'created' => date('Y-m-d H:i:s')
                    );
                    //if (count($reminders)<1){
                        array_push($reminders, $reminder);
                        $newReminder = $this->DataSendReminders->newEntity($reminder);            
                        $this->DataSendReminders->save($newReminder);
                    //}
                        
                }                                                
            }
            
        }
        
        $this->send_reminders('GFEPAYMENT');

        //CERTIFICATE CERTIFICATETELEHEALTHCALL healthcall ************************************************************************************************************************************************
        $fields = ['SysUsers.id', 'SysUsers.steps', 'SysUsers.last_status_change', 'SysUsers.created', 'SysUsers.phone']; //[,' DATEDIFF(NOW(), SysUsers.last_status_change) AS days'        
        $fields['sms_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'CERTIFICATE_TELEHEALTH_CALL' and from_id =SysUsers.id and deleted = 0)";
        $fields['certificate'] = '(select count(*) from data_certificates DCE
                left join data_consultation DC on DCE.consultation_id = DC.id AND DCE.deleted = 0 AND DCE.date_expiration >'.  date('Y-m-d').'
                left join data_consultation_plan DCP on  DCP.proceed = 1 AND DCP.deleted = 0 AND DCP.consultation_id = DC.id
                where DC.deleted = 0 AND DC.status = "CERTIFICATE" and DC.patient_id = SysUsers.id)';        
        $having = ['certificate' => 0];
        $users = $this->SysUsers->find()->select($fields)
            ->where(['SysUsers.deleted' => 0, 'SysUsers.steps' => 'HOME', 'SysUsers.last_status_change  IS NOT NULL'])
            ->having($having)
            ->all();
        


        $reminders = array();
        foreach ($users as $c) {
            $now =   date('Y-m-d H:i:s');
            $last_status_change = $c['last_status_change'];
            //if(date('Y-m-d H:i:s', strtotime($treatment->created->i18nFormat('yyyy-MM-dd HH:mm:ss') . '+ 2 days')) <= date('Y-m-d H:i:s') && $treatment->claims == 0){
            $last_status_change  = date('Y-m-d H:i:s', strtotime($c->last_status_change->i18nFormat('yyyy-MM-dd HH:mm:ss') . ' ')) ;
            $timestamp1 = strtotime($last_status_change);
            $timestamp2 = strtotime($now);
            //echo "now " . $now . " hour(s)";
            //echo "last_status_change " . $last_status_change . " hour(s)";
            //echo "Difference between two dates is " . $hour = abs($timestamp2 - $timestamp1)/(60*60) . " hour(s) \n";
            $hour = abs($timestamp2 - $timestamp1)/(60*60);
            //echo $hour;
                
            $type = 'CERTIFICATE_TELEHEALTH_CALL';             
            
            if($type != '' && $c["sms_send"]==0 && $hour > 23){
                if(isset($c["phone"])){
                    $reminder = array(
                        'from_id' => $c->id,
                        'type'    => $type,
                        'form'    => 'SMS',
                        'contact' => $c["phone"],
                        'created' => date('Y-m-d H:i:s')
                    );
                    //if (count($reminders)<1){
                        array_push($reminders, $reminder);
                        $newReminder = $this->DataSendReminders->newEntity($reminder);            
                        $this->DataSendReminders->save($newReminder);
                    //}
                        
                }                                                
            }
            
        }
        
        $this->send_reminders('CERTIFICATE_TELEHEALTH_CALL');

        /////////////////////////////////////////////////////
        //step LONGFORM
        $fields = ['SysUsers.id', 'SysUsers.steps', 'SysUsers.modified', 'SysUsers.created', 'SysUsers.phone']; //[,' DATEDIFF(NOW(), SysUsers.modified) AS days'        
        $fields['sms_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'CODEVERIFICATION' and from_id =SysUsers.id and deleted = 0)";        
        $users = $this->SysUsers->find()->select($fields)
            ->where(['SysUsers.deleted' => 0, 'SysUsers.steps' => 'CODEVERIFICATION'])
            ->all();      

        $reminders = array();
        foreach ($users as $c) {
            $now =   date('Y-m-d H:i:s');
            $modified = $c['modified'];            
            $modified  = date('Y-m-d H:i:s', strtotime($c->modified->i18nFormat('yyyy-MM-dd HH:mm:ss') . ' ')) ;
            $timestamp1 = strtotime($modified);
            $timestamp2 = strtotime($now);            
            $hour = abs($timestamp2 - $timestamp1)/(60*60);            
                
            $type = 'CODEVERIFICATION';                         
            if($type != '' && $c["sms_send"]==0 && $hour > 23){
                if(isset($c["phone"])){
                    $reminder = array(
                        'from_id' => $c->id,
                        'type'    => $type,
                        'form'    => 'SMS',
                        'contact' => $c["phone"],
                        'created' => date('Y-m-d H:i:s')
                    );
                    //if (count($reminders)<1){
                        array_push($reminders, $reminder);
                        $newReminder = $this->DataSendReminders->newEntity($reminder);            
                        $this->DataSendReminders->save($newReminder);
                    //}                        
                }                                                
            }
                        
        }
        
        $this->send_reminders('CODEVERIFICATION');
    }

    public function remind_gfe_schedule(){
        $this->loadModel('SpaLiveV1.DataConsultation');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataSendReminders');
        $Main = new MainController();
        $today = date('Ymd');
        //$today = '20230414';
        //time 8am

        //medical
        $fields = ['DataConsultation.id', 'DataConsultation.patient_id', 'DataConsultation.assistance_id', 'DataConsultation.schedule_date', 'User.phone', 'User.email', 'User.id']; //[,' DATEDIFF(NOW(), SysUsers.modified) AS days'        
        $fields['sms_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'REMIND_GFE_SCHEDULE_EIGHT_AM' and from_id =DataConsultation.assistance_id and deleted = 0)";        
        $fields['mail_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'REMIND_GFE_SCHEDULE_EIGHT_AM' and from_id =DataConsultation.assistance_id and deleted = 0 and form = 'EMAIL')";        
        $fields['noty_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'REMIND_GFE_SCHEDULE_EIGHT_AM' and from_id =DataConsultation.assistance_id and deleted = 0 and form = 'NOTIFICATION')"; 
        $fields['type'] = "(select 'medical')";        
        $medical = $this->DataConsultation->find()->select($fields)
        ->join(['User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'DataConsultation.assistance_id = User.id']])
            ->where(['DataConsultation.status' => 'INIT', 'DataConsultation.assistance_id <> 0','DATE_FORMAT(DataConsultation.schedule_date, "%Y%m%d") = '.$today ])
            ->toArray();      
        
        $reminders = array();
        

        //patient
        $fields = ['DataConsultation.id', 'DataConsultation.patient_id', 'DataConsultation.assistance_id', 'DataConsultation.schedule_date', 'User.phone', 'User.email', 'User.id']; //[,' DATEDIFF(NOW(), SysUsers.modified) AS days'        
         $fields['sms_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'REMIND_GFE_SCHEDULE_EIGHT_AM' and from_id =DataConsultation.assistance_id and deleted = 0 and form = 'SMS')";        
        $fields['mail_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'REMIND_GFE_SCHEDULE_EIGHT_AM' and from_id =DataConsultation.assistance_id and deleted = 0 and form = 'EMAIL')";        
        $fields['noty_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'REMIND_GFE_SCHEDULE_EIGHT_AM' and from_id =DataConsultation.assistance_id and deleted = 0 and form = 'NOTIFICATION')";        
        $fields['type'] = "(select 'patient')";        
        $patient = $this->DataConsultation->find()->select($fields)
        ->join(['User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'DataConsultation.patient_id = User.id']])
            ->where(['DataConsultation.status' => 'INIT', 'DataConsultation.assistance_id <> 0','DATE_FORMAT(DataConsultation.schedule_date, "%Y%m%d") = '.$today ])
            ->toArray();      
        
        
        $users = array_merge($medical, $patient);

        foreach ($users as $c) {                            
            $type = 'REMIND_GFE_SCHEDULE_EIGHT_AM';                         
            if($c["sms_send"]==0){
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
                    $this->DataSendReminders->save($newReminder);
                    
                }                                                                
            }
            if($c["mail_send"]==0){
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
                    $this->DataSendReminders->save($newReminder);
                }
            }
            if($c["noty_send"]==0){
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
                    $this->DataSendReminders->save($newReminder);
                }
            }   
                 
        }
        
        $this->send_reminders('REMIND_GFE_SCHEDULE_EIGHT_AM');

    }
    public function send_reminders($type, $treatment = 0){
        $this->loadModel('SpaLiveV1.DataSendReminders');
        $fields = ['DataSendReminders.id',
                'DataSendReminders.from_id',
                'DataSendReminders.form',
                'DataSendReminders.tries',
                'DataSendReminders.form',
                'DataSendReminders.contact'];
        $where = array(
            'DataSendReminders.status IN ("PENDING", "FAILURE")',
            'DataSendReminders.type' => $type
        );
        $reminders = $this->DataSendReminders->find()
            ->select($fields)
            ->where($where)
            ->all();

        foreach ($reminders as $reminder) {
            $res = false;
            switch ($reminder->form) {
                case 'EMAIL':
                    $res = $this->reminder_email($type, $reminder->from_id, $reminder->contact,$treatment);
                    break;
                case 'SMS':
                    $res = $this->reminder_sms($type, $reminder->from_id, $reminder->contact,$treatment);
                    break;
                case 'NOTIFICATION':
                    $res = $this->reminder_notification($type, $reminder->from_id, $reminder->contact,$treatment);
                break;
            }

            $reminder->tries = $reminder->tries + 1;
            if($res){
                $reminder->status = 'DONE';
            }else{
                $max_tries = 0;
                switch ($type) {
                    case 'RENEW_CERTIFICATE_LONG'  :  $max_tries = 5; break;
                    case 'RENEW_CERTIFICATE_SHORT' :  $max_tries = 1; break;
                    case 'TREATMENT_STARTING_SOON' :  $max_tries = 1; break;
                    case 'TREATMENT_STARTING_SOON_INJECTOR' :  $max_tries = 1; break;
                    case 'MODEL_PATIENT_REMINDER'  :  $max_tries = 1; break;
                    case 'LONGFORM'  :  $max_tries = 1; break;
                    case 'GFEPAYMENT'  :  $max_tries = 1; break;
                    case 'CERTIFICATE_TELEHEALTH_CALL'  :  $max_tries = 1; break;                                        
                    case 'REMIND_GFE_SCHEDULE_EIGHT_AM'  :  $max_tries = 1; break;
                    case 'CODEVERIFICATION'  :  $max_tries = 1; break;
                    case 'REMIND_UPLOAD_PHOTOS'  :  $max_tries = 1; break;
                    case 'REMIND_UPLOAD_PHOTOS_INJECTOR'  :  $max_tries = 1; break;
                    case 'REMIND_UPLOAD_PHOTOS_WL_8AM'  :  $max_tries = 1; break;
                    case 'REMIND_UPLOAD_PHOTOS_WL_ONE_DAY_BEFORE'  :  $max_tries = 1; break;
                }
                if($reminder->tries >= $max_tries){
                    $reminder->status = 'OUT OF TRIES';
                }else{
                    $reminder->status = 'FAILURE';
                }
            }
            $this->DataSendReminders->updateAll(
                array(
                    'status' => $reminder->status,
                    'tries'  => $reminder->tries
                ),
                array(
                    'id'     => $reminder->id
                )
            );
        }
    }

    public function send_reminder_email($to, $subject, $body){

        $data = array(
            'from'    => 'MySpaLive <info@mg.myspalive.com>',
            // 'to'    => 'angel@advantedigital.com'
            'to'    => $to,
            'subject' => $subject,
            'html'    => $this->getEmailFormat($body),
        );

        $mailgunKey = $this->getMailgunKey();

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, 'https://api.mailgun.net/v3/mg.myspalive.com/messages');
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_USERPWD, 'api:' . $mailgunKey);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

        $result = curl_exec($curl);
        curl_close($curl);

        if(isset($result)){
            return true;
        }
        return false;
    }

    public function reminder_email($type, $id, $contact,$treatment){
        $constants = array();
        switch ($type) {
            case 'RENEW_CERTIFICATE_LONG':
                $this->loadModel('SpaLiveV1.DataCertificates');
                $fields = ['DataCertificates.id',
                        'DataCertificates.date_expiration',
                        'SysUsers.name',
                        'SysUsers.mname',
                        'SysUsers.lname'];
                $where = array(
                    'DataCertificates.deleted' => 0,
                    'DataCertificates.id' => $id
                );
                $join = array(
                    'DataConsultation' => ['table' => 'data_consultation', 'type' => 'INNER', 'conditions' => 'DataConsultation.id = DataCertificates.consultation_id'],
                    'SysUsers'         => ['table' => 'sys_users',         'type' => 'INNER', 'conditions' => 'SysUsers.id = DataConsultation.patient_id'],
                );
                $data_certificate = $this->DataCertificates->find()
                    ->select($fields)
                    ->join($join)
                    ->where($where)
                    ->first();
                break;
            case 'RENEW_CERTIFICATE_SHORT':
                $this->loadModel('SpaLiveV1.DataCertificates');
                $fields = ['DataCertificates.id',
                        'DataCertificates.date_expiration',
                        'SysUsers.name',
                        'SysUsers.mname',
                        'SysUsers.lname'];
                $where = array(
                    'DataCertificates.deleted' => 0,
                    'DataCertificates.id' => $id
                );
                $join = array(
                    'DataConsultation' => ['table' => 'data_consultation', 'type' => 'INNER', 'conditions' => 'DataConsultation.id = DataCertificates.consultation_id'],
                    'SysUsers'         => ['table' => 'sys_users',         'type' => 'INNER', 'conditions' => 'SysUsers.id = DataConsultation.patient_id'],
                );
                $data_certificate = $this->DataCertificates->find()
                    ->select($fields)
                    ->join($join)
                    ->where($where)
                    ->first();
                break;
            case 'TREATMENT_STARTING_SOON':
                $this->loadModel('SpaLiveV1.DataTreatment');
                $fields = ['DataTreatment.id',
                        'DataTreatment.schedule_date',
                        'SysUsers.name',
                        'SysUsers.mname',
                        'SysUsers.lname'];
                $where = array(
                    'DataTreatment.deleted' => 0,
                    'DataTreatment.id' => $id
                );
                $join = array(
                    // 'DataTreatment' => ['table' => 'data_treatment', 'type' => 'INNER', 'conditions' => 'DataConsultation.id = DataTreatment.consultation_id'],
                    'SysUsers'         => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'SysUsers.id = DataTreatment.patient_id'],
                );
                $data_treatment = $this->DataTreatment->find()
                    ->select($fields)
                    ->join($join)
                    ->where($where)
                    ->first();
                break;
                
            case 'TREATMENT_STARTING_SOON_INJECTOR':
                $this->loadModel('SpaLiveV1.DataTreatment');
                $fields = ['DataTreatment.id',
                        'DataTreatment.schedule_date',
                        'SysUsers.name',
                        'SysUsers.mname',
                        'SysUsers.lname'];
                $where = array(
                    'DataTreatment.deleted' => 0,
                    'DataTreatment.id' => $id
                );
                $join = array(
                    'SysUsers'         => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'SysUsers.id = DataTreatment.assistance_id'],
                );
                $data_treatment = $this->DataTreatment->find()
                    ->select($fields)
                    ->join($join)
                    ->where($where)
                    ->first();
                break;

            case 'MODEL_PATIENT_REMINDER':
                $this->loadModel('SpaLiveV1.DataModelPatient');
                $this->loadModel('SpaLiveV1.CatTrainings');
                $fields = ['CatTrainings.id',
                        'CatTrainings.scheduled',
                        'DataModelPatient.phone',
                        'DataModelPatient.email'];
                $where = array(
                    'DataModelPatient.deleted' => 0,
                    'DataModelPatient.deleted'         => 0,
                );
                $join = array(
                    // 'DataTreatment' => ['table' => 'data_treatment', 'type' => 'INNER', 'conditions' => 'DataConsultation.id = DataTreatment.consultation_id'],
                    'CatTrainings'         => ['table' => 'cat_trainings',         'type' => 'INNER', 'conditions' => 'CatTrainings.id = DataModelPatient.registered_training_id'],
                    
                );
                $arr_models = $this->DataModelPatient->find()
                    ->select($fields)
                    ->join($join)
                    ->where($where)
                    ->all();
                break;
            
            case 'REMIND_UPLOAD_PHOTOS_INJECTOR':
                $this->loadModel('SpaLiveV1.DataTreatment');
                $fields = ['DataTreatment.id',
                        'DataTreatment.schedule_date',
                        'SysUsers.name',
                        'SysUsers.mname',
                        'SysUsers.lname'];
                $where = array(
                    'DataTreatment.deleted' => 0,
                    'DataTreatment.id' => $treatment
                );
                $join = array(                    
                    'SysUsers'         => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'SysUsers.id = DataTreatment.patient_id'],
                );
                $data_treatment = $this->DataTreatment->find()
                    ->select($fields)
                    ->join($join)
                    ->where($where)
                    ->first();
                
                if(!empty($data_treatment)){
                    $constants = [
                        '[CN/PATIENT]' => $data_treatment['SysUsers']['name'] . " " . $data_treatment['SysUsers']['mname'] . " " . $data_treatment['SysUsers']['lname'],
                    ];
                }
                break;

        }
        $this->loadModel("SpaLiveV1.CatNotifications");
        $ent_notification = $this->CatNotifications->find()->where(['CatNotifications.title' => $type])->first();
        
        if (!empty($ent_notification)) {
            $subject     = $ent_notification['subject'];
            $msg_email   = $ent_notification['body'];
            $body        = $msg_email;
            foreach($constants as $key => $value){
                $body = str_replace($key, $value, $msg_email);
                $subject = str_replace($key, $value, $subject);
            } 
            $this->log("Reminder Email: " . json_encode($contact ." " .$subject ." ".$body));
            return $this->send_reminder_email($contact, $subject, $body);
        }
        return false;
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

    public function reminder_sms($type, $id, $contact, $treatment=0){
        $constants = array();
        switch ($type) {
            case 'RENEW_CERTIFICATE_LONG':
                $this->loadModel('SpaLiveV1.DataCertificates');
                $fields = ['DataCertificates.id',
                        'DataCertificates.date_expiration',
                        'SysUsers.name',
                        'SysUsers.mname',
                        'SysUsers.lname'];
                $where = array(
                    'DataCertificates.deleted' => 0,
                    'DataCertificates.id' => $id,
                );
                $join = array(
                    'DataConsultation' => ['table' => 'data_consultation', 'type' => 'INNER', 'conditions' => 'DataConsultation.id = DataCertificates.consultation_id'],
                    'SysUsers'         => ['table' => 'sys_users',         'type' => 'INNER', 'conditions' => 'SysUsers.id = DataConsultation.patient_id'],
                );
                $data_certificate = $this->DataCertificates->find()
                    ->select($fields)
                    ->join($join)
                    ->where($where)
                    ->first();
                break;
            case 'RENEW_CERTIFICATE_SHORT':
                $this->loadModel('SpaLiveV1.DataCertificates');
                $fields = ['DataCertificates.id',
                        'DataCertificates.date_expiration',
                        'SysUsers.name',
                        'SysUsers.mname',
                        'SysUsers.lname'];
                $where = array(
                    'DataCertificates.deleted' => 0,
                    'DataCertificates.id' => $id
                );
                $join = array(
                    'DataConsultation' => ['table' => 'data_consultation', 'type' => 'INNER', 'conditions' => 'DataConsultation.id = DataCertificates.consultation_id'],
                    'SysUsers'         => ['table' => 'sys_users',         'type' => 'INNER', 'conditions' => 'SysUsers.id = DataConsultation.patient_id'],
                );
                $data_certificate = $this->DataCertificates->find()
                    ->select($fields)
                    ->join($join)
                    ->where($where)
                    ->first();
                break;
            case 'TREATMENT_STARTING_SOON':
                $this->loadModel('SpaLiveV1.DataTreatment');
                $fields = ['DataTreatment.id',
                        'DataTreatment.schedule_date',
                        'SysUsers.name',
                        'SysUsers.mname',
                        'SysUsers.lname'];
                $where = array(
                    'DataTreatment.deleted' => 0,
                    'DataTreatment.id' => $id
                );
                $join = array(
                    // 'DataTreatment' => ['table' => 'data_treatment', 'type' => 'INNER', 'conditions' => 'DataConsultation.id = DataTreatment.consultation_id'],
                    'SysUsers'         => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'SysUsers.id = DataTreatment.patient_id'],
                );
                $data_treatment = $this->DataTreatment->find()
                    ->select($fields)
                    ->join($join)
                    ->where($where)
                    ->first();
                break;

            case 'TREATMENT_STARTING_SOON_INJECTOR':
                $this->loadModel('SpaLiveV1.DataTreatment');
                $fields = ['DataTreatment.id',
                        'DataTreatment.schedule_date',
                        'SysUsers.name',
                        'SysUsers.mname',
                        'SysUsers.lname'];
                $where = array(
                    'DataTreatment.deleted' => 0,
                    'DataTreatment.id' => $id
                );
                $join = array(
                    'SysUsers'         => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'SysUsers.id = DataTreatment.assistance_id'],
                );
                $data_treatment = $this->DataTreatment->find()
                    ->select($fields)
                    ->join($join)
                    ->where($where)
                    ->first();
                break;

            case 'MODEL_PATIENT_REMINDER':
                $this->loadModel('SpaLiveV1.DataModelPatient');
                $this->loadModel('SpaLiveV1.CatTrainings');
                $fields = ['CatTrainings.id',
                        'CatTrainings.scheduled',
                        'DataModelPatient.phone',
                        'DataModelPatient.email'];
                $where = array(
                    'DataModelPatient.deleted' => 0,
                    'DataModelPatient.deleted'         => 0,
                );
                $join = array(
                    // 'DataTreatment' => ['table' => 'data_treatment', 'type' => 'INNER', 'conditions' => 'DataConsultation.id = DataTreatment.consultation_id'],
                    'CatTrainings'         => ['table' => 'cat_trainings',         'type' => 'INNER', 'conditions' => 'CatTrainings.id = DataModelPatient.registered_training_id'],
                    
                );
                $arr_models = $this->DataModelPatient->find()
                    ->select($fields)
                    ->join($join)
                    ->where($where)
                    ->all();
                break;
            case 'LONGFORM':
            break;
            case 'REMIND_UPLOAD_PHOTOS_INJECTOR':
                $this->loadModel('SpaLiveV1.DataTreatment');
                $fields = ['DataTreatment.id',
                        'DataTreatment.schedule_date',
                        'SysUsers.name',
                        'SysUsers.mname',
                        'SysUsers.lname'];
                $where = array(
                    'DataTreatment.deleted' => 0,
                    'DataTreatment.id' => $treatment
                );
                $join = array(                    
                    'SysUsers'         => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'SysUsers.id = DataTreatment.patient_id'],
                );
                $data_treatment = $this->DataTreatment->find()
                    ->select($fields)
                    ->join($join)
                    ->where($where)
                    ->first();
                
                if(!empty($data_treatment)){
                    $constants = [
                        '[CN/PATIENT]' => $data_treatment['SysUsers']['name'] . " " . $data_treatment['SysUsers']['mname'] . " " . $data_treatment['SysUsers']['lname'],
                    ];
                }
                break;
        }
        $this->loadModel("SpaLiveV1.CatNotifications");
        $ent_notification = $this->CatNotifications->find()->where(['CatNotifications.title' => $type])->first();
        if (!empty($ent_notification)) {
            $msg_email   = $ent_notification['body_push'];
            $body        = $msg_email;
            foreach($constants as $key => $value){
                $body = str_replace($key, $value, $msg_email);
            }
            return $this->send_reminder_sms($contact, $body);
        }
        return false;
    }

    public function send_reminder_notification($contact, $body){
        $this->loadModel('ApiApplication');
        $this->loadModel('ApiDebug');

        $array_conditions = [
            'ApiDevice.application_id' => 1
        ];

        $array_conditions['ApiDevice.user_id IN'] = array($contact);

        $this->loadModel('SpaLiveV1.ApiDevice');
        $ent_devices = $this->ApiDevice->find()->where($array_conditions)->toArray();

        $arr_devices = array();

        foreach ($ent_devices as $row) {
            $arr_devices[] = $row->id;
        }

        $this->loadModel('SpaLiveV1.DataNotification');
        if (!defined('USER_ID')) define('USER_ID', 0);
        $arrSave = array(
            'type' => 'NOTIFICATION',
            'message' => $body,
            'json_users' => json_encode(array(intval($contact))),
            'json_data' => json_encode(array()),
            'user_id' => USER_ID,
        );
        $ent_noti = $this->DataNotification->newEntity($arrSave);
        if(!$ent_noti->hasErrors()){
            $this->DataNotification->save($ent_noti);
        }

        try {            
            $MainController = new MainController(); 
            $MainController->send($body,array(),$arr_devices);
            $array_save = array(
                'type' => 'NOTIFICATION',
                'id_from' => 0,
                'id_to' => $contact,
                'message' => $body,
                'extra' => '',
                'deleted' => 0,
                'readed' => 0,
                'created' => date('Y-m-d H:i:s'),
            );

            $this->loadModel('SpaLiveV1.DataMessages');
            $c_entity = $this->DataMessages->newEntity($array_save);

            if(!$c_entity->hasErrors()){
                $this->DataMessages->save($c_entity);
                return true;
            }
            return false;
        } catch (\Throwable $th) {
            pr($th);
            return false;
        }

    }

    public function reminder_notification($type, $id, $contact,$treatment=0){
        $constants = array();
        switch ($type){
            case 'RENEW_CERTIFICATE_LONG':
                $this->loadModel('SpaLiveV1.DataCertificates');
                $fields = ['DataCertificates.id',
                        'DataCertificates.date_expiration',
                        'SysUsers.name',
                        'SysUsers.mname',
                        'SysUsers.lname'];
                $where = array(
                    'DataCertificates.deleted' => 0,
                    'DataCertificates.id' => $id,
                );
                $join = array(
                    'DataConsultation' => ['table' => 'data_consultation', 'type' => 'INNER', 'conditions' => 'DataConsultation.id = DataCertificates.consultation_id'],
                    'SysUsers'         => ['table' => 'sys_users',         'type' => 'INNER', 'conditions' => 'SysUsers.id = DataConsultation.patient_id'],
                );
                $data_certificate = $this->DataCertificates->find()
                    ->select($fields)
                    ->join($join)
                    ->where($where)
                    ->first();
                break;
            case 'RENEW_CERTIFICATE_SHORT':
                $this->loadModel('SpaLiveV1.DataCertificates');
                $fields = ['DataCertificates.id',
                        'DataCertificates.date_expiration',
                        'SysUsers.name',
                        'SysUsers.mname',
                        'SysUsers.lname'];
                $where = array(
                    'DataCertificates.deleted' => 0,
                    'DataCertificates.id' => $id
                );
                $join = array(
                    'DataConsultation' => ['table' => 'data_consultation', 'type' => 'INNER', 'conditions' => 'DataConsultation.id = DataCertificates.consultation_id'],
                    'SysUsers'         => ['table' => 'sys_users',         'type' => 'INNER', 'conditions' => 'SysUsers.id = DataConsultation.patient_id'],
                );
                $data_certificate = $this->DataCertificates->find()
                    ->select($fields)
                    ->join($join)
                    ->where($where)
                    ->first();
                break;
            case 'TREATMENT_STARTING_SOON':
                $this->loadModel('SpaLiveV1.DataTreatment');
                $fields = ['DataTreatment.id',
                        'DataTreatment.schedule_date',
                        'SysUsers.name',
                        'SysUsers.mname',
                        'SysUsers.lname'];
                $where = array(
                    'DataTreatment.deleted' => 0,
                    'DataTreatment.id' => $id
                );
                $join = array(
                    // 'DataTreatment' => ['table' => 'data_treatment', 'type' => 'INNER', 'conditions' => 'DataConsultation.id = DataTreatment.consultation_id'],
                    'SysUsers'         => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'SysUsers.id = DataTreatment.patient_id'],
                );
                $data_treatment = $this->DataTreatment->find()
                    ->select($fields)
                    ->join($join)
                    ->where($where)
                    ->first();
                break;

            case 'TREATMENT_STARTING_SOON_INJECTOR':
                $this->loadModel('SpaLiveV1.DataTreatment');
                $fields = ['DataTreatment.id',
                        'DataTreatment.schedule_date',
                        'SysUsers.name',
                        'SysUsers.mname',
                        'SysUsers.lname'];
                $where = array(
                    'DataTreatment.deleted' => 0,
                    'DataTreatment.id' => $id
                );
                $join = array(
                    'SysUsers'         => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'SysUsers.id = DataTreatment.assistance_id'],
                );
                $data_treatment = $this->DataTreatment->find()
                    ->select($fields)
                    ->join($join)
                    ->where($where)
                    ->first();
                break;

            case 'MODEL_PATIENT_REMINDER':
                $this->loadModel('SpaLiveV1.DataModelPatient');
                $this->loadModel('SpaLiveV1.CatTrainings');
                $fields = ['CatTrainings.id',
                        'CatTrainings.scheduled',
                        'DataModelPatient.phone',
                        'DataModelPatient.email'];
                $where = array(
                    'DataModelPatient.deleted' => 0,
                    'DataModelPatient.deleted'         => 0,
                );
                $join = array(
                    // 'DataTreatment' => ['table' => 'data_treatment', 'type' => 'INNER', 'conditions' => 'DataConsultation.id = DataTreatment.consultation_id'],
                    'CatTrainings'         => ['table' => 'cat_trainings',         'type' => 'INNER', 'conditions' => 'CatTrainings.id = DataModelPatient.registered_training_id'],
                    
                );
                $arr_models = $this->DataModelPatient->find()
                    ->select($fields)
                    ->join($join)
                    ->where($where)
                    ->all();
                break;
                case 'REMIND_UPLOAD_PHOTOS_INJECTOR':
                    $this->loadModel('SpaLiveV1.DataTreatment');
                    $fields = ['DataTreatment.id',
                            'DataTreatment.schedule_date',
                            'SysUsers.name',
                            'SysUsers.mname',
                            'SysUsers.lname'];
                    $where = array(
                        'DataTreatment.deleted' => 0,
                        'DataTreatment.id' => $treatment
                    );
                    $join = array(                    
                        'SysUsers'         => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'SysUsers.id = DataTreatment.patient_id'],
                    );
                    $data_treatment = $this->DataTreatment->find()
                        ->select($fields)
                        ->join($join)
                        ->where($where)
                        ->first();
                    
                    if(!empty($data_treatment)){
                        $constants = [
                            '[CN/PATIENT]' => $data_treatment['SysUsers']['name'] . " " . $data_treatment['SysUsers']['mname'] . " " . $data_treatment['SysUsers']['lname'],
                        ];
                    }
                    break;
        }
        $this->loadModel("SpaLiveV1.CatNotifications");
        $ent_notification = $this->CatNotifications->find()->where(['CatNotifications.title' => $type])->first();
        if (!empty($ent_notification)) {
            $msg_email   = $ent_notification['body_push'];
            $body        = $msg_email;
            foreach($constants as $key => $value){
                $body = str_replace($key, $value, $msg_email);
            }
            return $this->send_reminder_notification($contact, $body);
        }
    }

    public function format_message($type, $form, $constants, $data){
        $this->loadModel("SpaLiveV1.CatNotifications");
        $ent_notification = $this->CatNotifications->find()->where(['CatNotifications.title' => $type])->first();
        if (!empty($ent_notification)) {
            $subject = $ent_notification['subject'];
            $msg     = $form == 'EMAIL' ? $ent_notification['body'] : $ent_notification['body_push'];

            foreach($constants as $key => $value){
                $msg = str_replace($key, $value, $msg);
            }
            $data['subject'] = $subject;
            $data['body'] = $msg;
        }
    }

    // public function send($str_message, $data = array(), $array_devices_ids = array()){
    //     $this->loadModel('SpaLiveV1.ApiDevice');
        
    //     $array_application = $this->ApiApplication->find()->where(['ApiApplication.id' => 1])->first();



    //     if(!empty($array_application)){
    //         $array_config = json_decode($array_application->json_config, true);
    //         //$str_message = get('message', '');
    //         //$str_json_data = get('data', '{}');
    //         //$data = json_decode($str_json_data, true);

    //         if (!defined('NOTIFY_DEVELOPERS')) define('NOTIFY_DEVELOPERS', isset($array_config['notify_developer'])? $array_config['notify_developer'] : 0);
    //         if (!defined('IOS_DEBUG')) define('IOS_DEBUG', isset($array_config['ios_debug'])? $array_config['ios_debug'] : 0);

    //         $array_conditions = [
    //             'ApiDevice.application_id' => 1
    //         ];

    //         if(empty($array_devices_ids)){
    //            return; 
    //         }
    //         $array_conditions['ApiDevice.id IN'] = $array_devices_ids;

    //         if(NOTIFY_DEVELOPERS == 1){
    //             $array_conditions['ApiDevice.developer'] = 1;
    //         }

            
    //         if(isset($array_config['android_access_key']) && !empty($array_config['android_access_key'])){
    //             $array_conditions['ApiDevice.device'] = 'ANDROID';
                
    //             $array_device = $this->ApiDevice->find()->where($array_conditions)->toArray();
                
    //             if(!empty($array_device)){
    //                 $this->send_Android($array_device, $array_config['android_access_key'], $str_message, $data);
    //             }

    //         }
    //         $config = json_decode($array_application, true);
    //         define('APP_NAME', $config['appname']);
    //         if(!defined('PATH_IOS_CERT'))define('PATH_IOS_CERT', APP . 'Certificates' . DS . APP_NAME . '.pem');

    //         if(file_exists(PATH_IOS_CERT)){
    //             $array_conditions['ApiDevice.device'] = 'IOS';

    //             $array_device = $this->ApiDevice->find()->where($array_conditions)->toArray();

    //             if(!empty($array_device)){
    //                 $this->send_iOS($array_device, $str_message, $data, $array_config['ios_passphrase']);
    //             }
                
    //         }
            
    //     }
    // }

    protected function send_Android($array_device, $android_access_key, $str_message, $data){

        $notification = array();
        $notification['message'] = $str_message;

        if (!empty($data)) {
            $notification = array_merge($notification, $data);
        }

        foreach ($array_device as /*$reg*/ $Device) {
            //$Device = $reg['ApiDevice'];
            $token = trim($Device->token);

            $fields = array(
                'to' => $token,
                'data' => $notification
            );

            $url = 'https://fcm.googleapis.com/fcm/send';

            //$firebase_api = Configure::read('API_CONFIG.android_access_key');

            $headers = array(
                'Authorization: key=' . $android_access_key,//$firebase_api,
                'Content-Type: application/json'
            );

            // Open connection
            $ch = curl_init();

            // Set the url, number of POST vars, POST data
            curl_setopt($ch, CURLOPT_URL, $url);

            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            // Disabling SSL Certificate support temporarily
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));

            // Execute post
            curl_exec($ch);

            // if($result === FALSE){

            //     //die('Curl failed: ' . curl_error($ch));

            // }
            

            curl_close($ch);
            
        }
    }

    private function send_iOS($array_device, $str_message, $data, $ios_passphrase){
        if(empty($array_device)){
            return false;
        }

       
        $body = array();
        // Create the payload body


        $body['aps'] = array(
            'alert' => $str_message,
            'message' => $str_message,
            'sound' => 'default',
            'badge' => 1
        );

        if (!empty($data)) {
            $body = array_merge($data,$body);
        }

        // Encode the payload as JSON
        $payload = json_encode($body);
        
        foreach ($array_device as $Device) {


            //curl -v --header "apns-topic: com.advante.SpaLiveMD" --header "apns-push-type: alert" --cert "aps-2.cer" --cert-type DER --key "PushKey.pem" --key-type PEM --data '{"aps":{"alert":"test"}}' --http2  https://api.push.apple.com/3/device/402226284ec39f60bc94ed780d1eed10bcd4a1849c4c959ec3d72e1267554c2a

            
            $device_token = trim($Device->token);
            $pem_file       = PATH_IOS_CERT;
            $pem_secret     = Configure::read('API_CONFIG.ios_passphrase');
            $apns_topic     = 'com.advante.SpaLiveMD';


            $sample_alert = '
             {
                "aps":{
                    "alert":"' . $str_message . '",
                    "sound":"default"
                }
             }';
            // $url = "https://api.development.push.apple.com/3/device/$device_token";
            $url = "https://api.push.apple.com/3/device/$device_token";

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $sample_alert);
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array("apns-topic: $apns_topic"));
            curl_setopt($ch, CURLOPT_SSLCERT, $pem_file);
            curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $pem_secret);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER,true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1);
            curl_setopt($ch, CURLOPT_NOSIGNAL, 1);


            curl_exec($ch);

            // curl_getinfo($ch, CURLINFO_HTTP_CODE);

            //On successful response you should get true in the response and a status code of 200
            //A list of responses and status codes is available at 
            //https://developer.apple.com/library/ios/documentation/NetworkingInternet/Conceptual/RemoteNotificationsPG/Chapters/TheNotificationPayload.html#//apple_ref/doc/uid/TP40008194-CH107-SW1

            //var_dump($response);
            //var_dump($httpcode);

            /*

           $token = trim($Device->token);
           
           $ctx = stream_context_create();
            stream_context_set_option($ctx, 'ssl', 'local_cert', PATH_IOS_CERT);
            stream_context_set_option($ctx, 'ssl', 'passphrase', Configure::read('API_CONFIG.ios_passphrase'));

            
            // Open a connection to the APNS server
            // if(IOS_DEBUG == 1){
            if(1 == 1){
                $fp = stream_socket_client('ssl://gateway.sandbox.push.apple.com:2195', $err, $errstr, 60, STREAM_CLIENT_CONNECT|STREAM_CLIENT_PERSISTENT, $ctx);
            }else{
                $fp = stream_socket_client('ssl://gateway.push.apple.com:2195', $err, $errstr, 60, STREAM_CLIENT_CONNECT|STREAM_CLIENT_PERSISTENT, $ctx);
            }

            if (!$fp) exit("Failed to connect: $err $errstr" . PHP_EOL);

            // Build the binary notification
            $msg = chr(0) . pack('n', 32) . pack('H*', $token) . pack('n', strlen($payload)) . $payload; // Build the binary notification
            $result = fwrite($fp, $msg, strlen($msg)); // Send it to the server
            if (!$result)
                echo 'Message not delivered' . PHP_EOL;
            else
                echo 'Message successfully delivered' . PHP_EOL;
            fclose($fp); // Close the connection to the server
            */            
        }

        

        // Close the connection to the server
    }

    private function getEmailFormat($content) {
        return '
            <!doctype html>
            <html>
                <head>
                <meta name="viewport" content="width=device-width">
                <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
                <title>MySpaLive Message</title>
                <style>
                @media only screen and (max-width: 620px) {
                    table[class=body] h1 {
                    font-size: 28px !important;
                    margin-bottom: 10px !important;
                    }
                    table[class=body] p,
                        table[class=body] ul,
                        table[class=body] ol,
                        table[class=body] td,
                        table[class=body] span,
                        table[class=body] a {
                    font-size: 16px !important;
                    }
                    table[class=body] .wrapper,
                        table[class=body] .article {
                    padding: 10px !important;
                    }
                    table[class=body] .content {
                    padding: 0 !important;
                    }
                    table[class=body] .container {
                    padding: 0 !important;
                    width: 100% !important;
                    }
                    table[class=body] .main {
                    border-left-width: 0 !important;
                    border-radius: 0 !important;
                    border-right-width: 0 !important;
                    }
                    table[class=body] .btn table {
                    width: 100% !important;
                    }
                    table[class=body] .btn a {
                    width: 100% !important;
                    }
                    table[class=body] .img-responsive {
                    height: auto !important;
                    max-width: 100% !important;
                    width: auto !important;
                    }
                }
                /* -------------------------------------
                    PRESERVE THESE STYLES IN THE HEAD
                ------------------------------------- */
                @media all {
                .ExternalClass {
                width: 100%;
                }
                    .ExternalClass,
                        .ExternalClass p,
                        .ExternalClass span,
                        .ExternalClass font,
                        .ExternalClass td,
                        .ExternalClass div {
                    line-height: 100%;
                    }
                    .apple-link a {
                    color: inherit !important;
                    font-family: inherit !important;
                    font-size: inherit !important;
                    font-weight: inherit !important;
                    line-height: inherit !important;
                    text-decoration: none !important;
                    }
                    #MessageViewBody a {
                    color: inherit;
                    text-decoration: none;
                    font-size: inherit;
                    font-family: inherit;
                    font-weight: inherit;
                    line-height: inherit;
                    }
                    .btn-primary table td:hover {
                    background-color: #34495e !important;
                    }
                    .btn-primary a:hover {
                    background-color: #34495e !important;
                    border-color: #34495e !important;
                    }
                }
                </style>
                </head>
                <body class="" style="background-color: #f6f6f6; font-family: sans-serif; -webkit-font-smoothing: antialiased; font-size: 14px; line-height: 1.4; margin: 0; padding: 0; -ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%;">
                <span class="preheader" style="color: transparent; display: none; height: 0; max-height: 0; max-width: 0; opacity: 0; overflow: hidden; mso-hide: all; visibility: hidden; width: 0;">MySpaLive Message.</span>
                <table role="presentation" border="0" cellpadding="0" cellspacing="0" class="body" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; background-color: #f6f6f6;">
                <tr>
                <td style="font-family: sans-serif; font-size: 14px; vertical-align: top;">&nbsp;</td>
                <td class="container" style="font-family: sans-serif; font-size: 14px; vertical-align: top; display: block; Margin: 0 auto; max-width: 580px; padding: 10px; width: 580px;">
                    <div class="content" style="box-sizing: border-box; display: block; Margin: 0 auto; max-width: 580px; padding: 10px;">
                    <center> <img src="https://blog.myspalive.com/wp-content/uploads/2021/05/MySpaLive-logo-login.png" width="180px"/></center>
                    <!-- START CENTERED WHITE CONTAINER -->
                    <table role="presentation" class="main" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; background: #ffffff; border-radius: 3px;">
                        <!-- START MAIN CONTENT AREA -->
                        <tr>
                        <td class="wrapper" style="font-family: sans-serif; font-size: 14px; vertical-align: top; box-sizing: border-box; padding: 20px;">
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;">
                            <tr>
                                ' . $content.'
                            </tr>
                            </table>
                        </td>
                        </tr>
                    <!-- END MAIN CONTENT AREA -->
                    </table>
                    <!-- START FOOTER -->
                    <div class="footer" style="clear: both; Margin-top: 10px; text-align: center; width: 100%;">
                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;">
                        <tr>
                            <td class="content-block" style="font-family: sans-serif; vertical-align: top; padding-bottom: 10px; padding-top: 10px; font-size: 12px; color: #999999; text-align: center;">
                            <center><span class="apple-link" style="color: #654a99; font-size: 12px; text-align: center;">Visit us at <a href="https://blog.myspalive.com/">MySpaLive</a></span></center>
                            </td>
                        </table>
                    </div>
                    <!-- END FOOTER -->
                        <!-- END CENTERED WHITE CONTAINER -->
                        </div>
                    </td>
                    <td style="font-family: sans-serif; font-size: 14px; vertical-align: top;">&nbsp;</td>
                   </tr>
                </table>
                </body>
            </html>
        ';
    }

    public function upload_photos_treatment(){
        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataSendReminders');
        $Main = new MainController();                
        $date = date('Ymd', strtotime('-14 days'));
        //medical REMIND_UPLOAD_PHOTOS upload_photos
        $fields = ['DataTreatment.id', 'DataTreatment.patient_id', 'DataTreatment.assistance_id', 'DataTreatment.schedule_date', 'User.phone', 'User.email', 'User.id']; //[,' DATEDIFF(NOW(), SysUsers.modified) AS days'        
        $fields['sms_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'REMIND_UPLOAD_PHOTOS_INJECTOR' and from_id =DataTreatment.assistance_id and deleted = 0)";        
        $fields['mail_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'REMIND_UPLOAD_PHOTOS_INJECTOR' and from_id =DataTreatment.assistance_id and deleted = 0 and form = 'EMAIL')";        
        $fields['noty_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'REMIND_UPLOAD_PHOTOS_INJECTOR' and from_id =DataTreatment.assistance_id and deleted = 0 and form = 'NOTIFICATION')"; 
        $fields['type'] = "(select 'medical')";        
        $medical = $this->DataTreatment->find()->select($fields)
        ->join(['User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'DataTreatment.assistance_id = User.id']])
            ->where(['DataTreatment.status' => 'DONE', 'DataTreatment.assistance_id <> 0','DATE_FORMAT(DataTreatment.schedule_date, "%Y%m%d") =   ' . "$date" ])
            ->toArray();      
            $reminders = array();
            foreach ($medical as $c) {                            
                $type = 'REMIND_UPLOAD_PHOTOS_INJECTOR';                         
                if($c["sms_send"]==0){
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
                        $this->DataSendReminders->save($newReminder);
                        
                    }                                                                
                }
                if($c["mail_send"]==0){
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
                        $this->DataSendReminders->save($newReminder);
                    }
                }
                if($c["noty_send"]==0){
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
                        $this->DataSendReminders->save($newReminder);
                    }
                }   
                $this->send_reminders($type,$c->id);
                   
            }
            
        
        

        //patient
        $fields = ['DataTreatment.id', 'DataTreatment.patient_id', 'DataTreatment.assistance_id', 'DataTreatment.schedule_date', 'User.phone', 'User.email', 'User.id']; //[,' DATEDIFF(NOW(), SysUsers.modified) AS days'        
        $fields['sms_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'REMIND_UPLOAD_PHOTOS' and from_id =DataTreatment.assistance_id and deleted = 0 and form = 'SMS')";        
        $fields['mail_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'REMIND_UPLOAD_PHOTOS' and from_id =DataTreatment.assistance_id and deleted = 0 and form = 'EMAIL')";        
        $fields['noty_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'REMIND_UPLOAD_PHOTOS' and from_id =DataTreatment.assistance_id and deleted = 0 and form = 'NOTIFICATION')";        
        $fields['type'] = "(select 'patient')";        
        $patient = $this->DataTreatment->find()->select($fields)
        ->join(['User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'DataTreatment.patient_id = User.id']])
            ->where(['DataTreatment.deleted' => 0,'DataTreatment.status' => 'DONE', 'DataTreatment.assistance_id <> 0','DATE_FORMAT(DataTreatment.schedule_date, "%Y%m%d") = '  . "$date"])
            ->toArray();      
         
        //$users = array_merge($medical, $patient);
        $users = $patient;
        foreach ($users as $c) {                            
            $type = 'REMIND_UPLOAD_PHOTOS';                         
            if($c["sms_send"]==0){
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
                    $this->DataSendReminders->save($newReminder);
                    
                }                                                                
            }
            if($c["mail_send"]==0){
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
                    $this->DataSendReminders->save($newReminder);
                }
            }
            if($c["noty_send"]==0){
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
                    $this->DataSendReminders->save($newReminder);
                }
            }   
                 
        }
        
        
        $this->send_reminders('REMIND_UPLOAD_PHOTOS');

    }

    public function remind_upload_photos_weight_loss(){
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataSendReminders');
        $Main = new MainController();                
        // same day 8 am
        $date = date('Ymd');
        $hour = date('H');
         $this->log(__LINE__ . ' ' . json_encode($hour));
        
         if($hour == '08'){
             $this->log(__LINE__ . ' ' . json_encode(''));
            //patient one day before check in
            $fields = ['DataOtherServicesCheckIn.id', 'DataOtherServicesCheckIn.patient_id',  'DataOtherServicesCheckIn.call_date', 'User.phone', 'User.email', 'User.id']; //[,' DATEDIFF(NOW(), SysUsers.modified) AS days'        
            $fields['sms_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'REMIND_UPLOAD_PHOTOS_WL_8AM' and from_id =DataOtherServicesCheckIn.patient_id and deleted = 0 and form = 'SMS')";        
            $fields['mail_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'REMIND_UPLOAD_PHOTOS_WL_8AM' and from_id =DataOtherServicesCheckIn.patient_id and deleted = 0 and form = 'EMAIL')";        
            $fields['noty_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'REMIND_UPLOAD_PHOTOS_WL_8AM' and from_id =DataOtherServicesCheckIn.patient_id and deleted = 0 and form = 'NOTIFICATION')";        
                    
            $patient = $this->DataOtherServicesCheckIn->find()->select($fields)
            ->join(['User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'DataOtherServicesCheckIn.patient_id = User.id']])
                ->where(['DataOtherServicesCheckIn.deleted' => 0,'DataOtherServicesCheckIn.status' => 'CLAIMED', 'DataOtherServicesCheckIn.support_id <> 0','(DataOtherServicesCheckIn.has_image <> 1 OR DataOtherServicesCheckIn.has_image IS NULL)','DATE_FORMAT(DataOtherServicesCheckIn.call_date, "%Y%m%d") = '  . "$date"])
                ->toArray();      
            
            
             $this->log(__LINE__ . ' ' . json_encode($patient));
            foreach ($patient as $c) {                            
                $type = 'REMIND_UPLOAD_PHOTOS_WL_8AM';                         
                if($c["sms_send"]==0){
                    if(isset($c["User"]["phone"])){
                        $reminder = array(
                            'from_id' => $c['User']['id'],
                            'type'    => $type,
                            'form'    => 'SMS',
                            'contact' => $c["User"]["phone"],
                            'created' => date('Y-m-d H:i:s')
                        );                    
                        
                        $newReminder = $this->DataSendReminders->newEntity($reminder);            
                        $this->DataSendReminders->save($newReminder);
                        
                    }                                                                
                }
                if($c["mail_send"]==0){
                    if(isset($c["User"]["email"])){
                        $reminder = array(
                            'from_id' => $c['User']['id'],
                            'type'    => $type,
                            'form'    => 'EMAIL',
                            'contact' => $c["User"]["email"],
                            'created' => date('Y-m-d H:i:s')
                        );                    
                        
                        $newReminder = $this->DataSendReminders->newEntity($reminder);            
                        $this->DataSendReminders->save($newReminder);
                    }
                }
                if($c["noty_send"]==0){
                    if(isset($c["id"])){
                        $reminder = array(
                            'from_id' => $c['User']['id'],
                            'type'    => $type,
                            'form'    => 'NOTIFICATION',
                            'contact' => ($c["type"] =="medical" ? $c->assistance_id : $c->patient_id),
                            'created' => date('Y-m-d H:i:s')
                        );                    
                        
                        $newReminder = $this->DataSendReminders->newEntity($reminder);            
                        $this->DataSendReminders->save($newReminder);
                    }
                }   
                    
            }
            
            
            $this->send_reminders('REMIND_UPLOAD_PHOTOS_WL_8AM');
        }

        // remind one day before call and not image upload
        $date_before = date('Ymd', strtotime('+1 day'));
        $fields = ['DataOtherServicesCheckIn.id', 'DataOtherServicesCheckIn.patient_id',  'DataOtherServicesCheckIn.call_date', 'User.phone', 'User.email', 'User.id']; //[,' DATEDIFF(NOW(), SysUsers.modified) AS days'        
        $fields['sms_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'REMIND_UPLOAD_PHOTOS_WL_ONE_DAY_BEFORE' and from_id =DataOtherServicesCheckIn.patient_id and deleted = 0 and form = 'SMS')";        
        $fields['mail_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'REMIND_UPLOAD_PHOTOS_WL_ONE_DAY_BEFORE' and from_id =DataOtherServicesCheckIn.patient_id and deleted = 0 and form = 'EMAIL')";        
        $fields['noty_send'] = "(SELECT count(*) FROM data_send_reminders where type = 'REMIND_UPLOAD_PHOTOS_WL_ONE_DAY_BEFORE' and from_id =DataOtherServicesCheckIn.patient_id and deleted = 0 and form = 'NOTIFICATION')";        
                
        $patient = $this->DataOtherServicesCheckIn->find()->select($fields)
        ->join(['User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'DataOtherServicesCheckIn.patient_id = User.id']])
            ->where(['DataOtherServicesCheckIn.deleted' => 0,'DataOtherServicesCheckIn.status' => 'CLAIMED', 'DataOtherServicesCheckIn.support_id <> 0',
            '(DataOtherServicesCheckIn.has_image <> 1 OR DataOtherServicesCheckIn.has_image IS NULL)','DATE_FORMAT(DataOtherServicesCheckIn.call_date, "%Y%m%d") = '  . "$date_before"])
            ->toArray();

         $this->log(__LINE__ . ' ' . json_encode($patient));
        foreach ($patient as $c) {                            
            $type = 'REMIND_UPLOAD_PHOTOS_WL_ONE_DAY_BEFORE';                         
            if($c["sms_send"]==0){
                if(isset($c["User"]["phone"])){
                    $reminder = array(
                        'from_id' => $c['User']['id'],
                        'type'    => $type,
                        'form'    => 'SMS',
                        'contact' => $c["User"]["phone"],
                        'created' => date('Y-m-d H:i:s')
                    );                    
                    
                    $newReminder = $this->DataSendReminders->newEntity($reminder);            
                    $this->DataSendReminders->save($newReminder);
                    
                }                                                                
            }
            if($c["mail_send"]==0){
                if(isset($c["User"]["email"])){
                    $reminder = array(
                        'from_id' => $c['User']['id'],
                        'type'    => $type,
                        'form'    => 'EMAIL',
                        'contact' => $c["User"]["email"],
                        'created' => date('Y-m-d H:i:s')
                    );                    
                    
                    $newReminder = $this->DataSendReminders->newEntity($reminder);            
                    $this->DataSendReminders->save($newReminder);
                }
            }
            if($c["noty_send"]==0){
                if(isset($c["id"])){
                    $reminder = array(
                        'from_id' => $c['User']['id'],
                        'type'    => $type,
                        'form'    => 'NOTIFICATION',
                        'contact' => ($c["type"] =="medical" ? $c->assistance_id : $c->patient_id),
                        'created' => date('Y-m-d H:i:s')
                    );                    
                    
                    $newReminder = $this->DataSendReminders->newEntity($reminder);            
                    $this->DataSendReminders->save($newReminder);
                }
            }   
                
        }
        
        
        $this->send_reminders('REMIND_UPLOAD_PHOTOS_WL_ONE_DAY_BEFORE');

        
    }

    private function shouldSendReminderByStateId($stateId, $schedule_date) {
        $this->loadModel('SpaLiveV1.CatStates');
    
        $state = $this->CatStates->find()
            ->select(['timezone'])
            ->where(['id' => $stateId])
            ->first();
    
        // Obtener zona horaria del estado o usar una por defecto
        $timezone = $state && !empty($state->timezone) ? $state->timezone : 'America/Chicago';
    
        // Convertir la fecha programada a DateTime en esa zona horaria
        $scheduled = new \DateTime($schedule_date, new \DateTimeZone($timezone));
    
        // Obtener el "ahora" en esa zona horaria
        $now = new \DateTime('now', new \DateTimeZone($timezone));
    
        // Calcular la diferencia en segundos
        $diffInSeconds = $scheduled->getTimestamp() - $now->getTimestamp();
    
        // Verificar si está entre 0 y 30 minutos (1800 segundos)
        return $diffInSeconds >= 0 && $diffInSeconds <= 1800;
    }
}
