<?php
declare(strict_types=1);

namespace SpaLiveV1\Controller;
use App\Controller\AppPluginController;
use App\Controller\MainController as ControllerMainController;
use Cake\Core\Configure;
use Cake\Utility\Security;
use Cake\Utility\Text;
use Cake\Utility\Hash;
use PHPUnit\Framework\Constraint\Count;

use Cake\Database\Expression\QueryExpression;

require_once(ROOT . DS . 'vendor' . DS  . 'stripe' . DS . 'init.php');
use Stripe\Stripe;
use \Stripe\Error;

require_once(ROOT . DS . 'vendor' . DS  . 'Twilio' . DS . 'autoload.php');
use Twilio\Rest\Client; 
use Twilio\Exceptions\TwilioException;

use SpaLiveV1\Controller\Data\TreatmentsHelper;
use SpaLiveV1\Controller\Data\ProviderProfile;
use SpaLiveV1\Controller\MainController;
use SpaLiveV1\Controller\PaymentsController;
use SpaLiveV1\Controller\SummaryController;
use Cake\I18n\FrozenTime;

class TreatmentsController extends AppPluginController{

    protected $mailgunKey = null;

    protected function getMailgunKey(): ?string
    {
        if ($this->mailgunKey === null) {
            $this->mailgunKey = env('MAILGUN_API_KEY');
        }
        return $this->mailgunKey;
    }

	public function initialize() : void{
        parent::initialize();
        date_default_timezone_set("America/Chicago");
        $this->URL_API = env('URL_API', 'https://api.myspalive.com/');
        $this->URL_WEB = env('URL_WEB', 'https://app.myspalive.com/');
        $this->URL_ASSETS = env('URL_ASSETS', 'https://api.myspalive.com/assets/');
        $this->URL_PANEL = env('URL_PANEL', 'https://panel.myspalive.com/');
        $this->loadModel('SpaLiveV1.AppToken');
    }

    public function treatments_patient() {
        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.DataScheduleDaysOff');
        $this->loadModel('SpaLiveV1.DataScheduleModel');
        $token = get('token', '');
        $this->loadModel('SpaLiveV1.DataClaimTreatments');
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }
        $mounth = get('mounth', '');
        if(empty($mounth)){
            $mounth = date('Y-m');
        }
        $user_uid = get('user_uid', '');
        if(!empty($user_uid)){
            $this->loadModel('SpaLiveV1.SysUsers');
            $user_id = $this->SysUsers->uid_to_id($user_uid);
        }

        $dateFinal = date('Y-m-t', strtotime($mounth));
        $dInicio = date('Y-m-d', strtotime($mounth));
        $now = date('Y-m-d');

        $date = $dInicio;
        $arrayFechas = [];
        
        while ($date <= $dateFinal) {
            $name_day = date('l', strtotime($date));

            $_where = [ 
                'DataTreatment.deleted' => 0,
                'DataTreatment.status NOT IN' => array('CANCEL', 'REJECT'),
                '(DATE_FORMAT(DataTreatment.schedule_date, "%Y-%m-%d") = "' . $date . '")'
            ];


            if(!empty($user_uid)){
                $ent_sch_model = $this->DataScheduleModel->find()->where(['DataScheduleModel.model' => 'injector','DataScheduleModel.deleted' => 0, 'DataScheduleModel.injector_id' => $user_id, 'DataScheduleModel.days' => strtoupper($name_day)])->first();
                if(!isset($ent_sch_model)){
                    $ent_sch_model = $this->DataScheduleModel->find()->where(['DataScheduleModel.model' => 'injector','DataScheduleModel.deleted' => 0, 'DataScheduleModel.injector_id' => $user_id, 'DataScheduleModel.days like "%' . strtoupper($name_day) .'%"'])->first();
                }
                $isDayOff = $this->DataScheduleDaysOff->find()->where(['DataScheduleDaysOff.deleted' => 0, 'DataScheduleDaysOff.date_off' => $date, 'DataScheduleDaysOff.user_id' => $user_id])->first();
                $provider_id = $this->SysUsers->uid_to_id($user_uid);
                if($provider_id >= 0){
                    $_where['DataTreatment.assistance_id'] = $provider_id;
                } else {
                    $_where['DataTreatment.patient_id'] = USER_ID;
                }
            } else {
                $_where['DataTreatment.patient_id'] = USER_ID;
            }

            $fields = ['DataTreatment.uid','DataTreatment.schedule_date','DataTreatment.amount','DataTreatment.tip','DataTreatment.status','User.name','User.lname','Provider.name','Provider.lname','DataTreatment.created'];


            $certTreatment = $this->DataTreatment->find()->select($fields)
            ->join([
                'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataTreatment.patient_id'],
                'Provider' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Provider.id = DataTreatment.assistance_id'],
            ])->where($_where)->all();
            // pr($certTreatment);
            // exit;
            $arrayTimes = [];
            $horaFin="19:30";$horaInicio="08:00";
            if(!empty($user_uid)){
                if(isset($ent_sch_model->time_start) && isset($ent_sch_model->time_end)){ 
                    if($date == date('Y-m-d', strtotime($now))){$this->set("now", date('H:i'));
                        $hora = date('H:i');//13.10
                        $horaInicio = $ent_sch_model->time_start.':00';                       
                        if($hora < date('H:i', strtotime($horaInicio))){//date('H:30')){//12.06<9.00
                            if($horaInicio < date('H:i', strtotime($horaInicio))){
                                $this->set("horaInicio116", $horaInicio);//$horaInicio = date('H:30');
                            } else if($horaInicio >= date('H:30', strtotime($horaInicio)) ){//date('H:30')){
                                $horaInicio = date('H:00', strtotime($hora."+ 30 minutes"));
                            }
                        }else{
                            if($hora < date('H:30')){//18.30
                                $horaInicio = date('H:30');
                            } else if($hora >= date('H:30')){
                                $horaInicio = date('H:00', strtotime($hora."+ 1 hours"));
                            }
                        }
                        $horaFin = $ent_sch_model->time_end.':00';
                        $horaFin =  date('H:i', strtotime($horaFin));
                    } else{
                        $horaInicio = $ent_sch_model->time_start.':00';
                        $horaInicio =  date('H:i', strtotime($horaInicio));    
                        $horaFin = $ent_sch_model->time_end.':00';
                        $horaFin =  date('H:i', strtotime($horaFin));    
                    }
                }
            }else{
                if($date == date('Y-m-d', strtotime($now))){
                    $hora = date('H:i');
                    if($hora < date('H:30')){
                        $horaInicio = date('H:30');
                    } else if($hora >= date('H:30')){
                        $horaInicio = date('H:00', strtotime($hora."+ 1 hours"));
                    }
                } else{
                    $horaInicio = '08:00';
                }
            }
            $datetime = $date.' '.$horaInicio;
            $datetimeend = $date.' '.$horaFin;;
            
            while($datetime <= $datetimeend){
                $hora = date('h:i A', strtotime($datetime));
                if(Count($certTreatment) <= 0){
                    array_push($arrayTimes, array(
                        'time' => $hora,
                        'data' => array(
                            'status' => '',
                            'name' => '',
                            'provider' => '',
                            'date' => ''
                        )
                    ));
                    $datetime = date('Y-m-d H:i', strtotime($datetime."+ 30 minutes"));
                    continue;
                }
                
                $hourMatch = false;
                foreach($certTreatment as $row){
                    if ($row['status'] == 'PETITION') {
                        $ent_claim = $this->DataClaimTreatments->find()->where(['DataClaimTreatments.treatment_uid' => $row['uid'], 'DataClaimTreatments.deleted' => 0])->count();
                        //$row['status'] = $ent_claim > 0 ? 'CLAIMED' : 'PENDING CLAIM';
                        $now = date('Y-m-d H:i:s');
                        if( ($now > date('Y-m-d H:i:s', strtotime($row['created']->i18nFormat('yyyy-MM-dd HH:mm:ss','America/Chicago') . ' + 2 day')) && $ent_claim <= 0) || $now > date('Y-m-d H:i:s', strtotime($row['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm:ss','America/Chicago'))) ) {
                            $row['status'] = 'Expired';
                        }
                    }
                    if($row->schedule_date->i18nFormat('hh:mm a') == $hora){
                        if(!empty($user_uid)){
                            if($row['status'] == 'Expired'){
                                array_push($arrayTimes, array(
                                    'time' => $hora,
                                    'data' => array(
                                        'status' => '',
                                        'name' => '',
                                        'provider' => '',
                                        'date' => ''
                                    )
                                ));
                            }else{
                                array_push($arrayTimes, array(
                                    'time' => $hora,
                                    'data' => array(
                                        'status' => $row->status,
                                        'name' => '',
                                        'provider' => '',
                                        'date' => $row->schedule_date->i18nFormat('yyyy-MM-dd HH:mm')
                                    )
                                ));
                            }
                            
                        } else {
                            if($row['status'] == 'Expired'){
                                array_push($arrayTimes, array(
                                    'time' => $hora,
                                    'data' => array(
                                        'status' => '',
                                        'name' => '',
                                        'provider' => '',
                                        'date' => ''
                                    )
                                ));
                            }else{
                                array_push($arrayTimes, array(
                                    'time' => $hora,
                                    'data' => array(
                                        'status' => $row->status,
                                        'name' => $row['User']['name'] . ' ' . $row['User']['lname'],
                                        'provider' => $row['Provider']['name'] . ' ' . $row['Provider']['lname'],
                                        'date' => $row->schedule_date->i18nFormat('yyyy-MM-dd HH:mm')
                                    )
                                ));
                            }
                        }
                       
                        $hourMatch = true;
                    } 
                }
                if(!$hourMatch){
                    array_push($arrayTimes, array(
                        'time' => $hora,
                        'data' => array(
                            'status' => '',
                            'name' => ' ',
                            'provider' => ' ',
                            'date' => ''
                        )
                    ));
                }
                $datetime = date('Y-m-d H:i', strtotime($datetime."+ 30 minutes"));
            }
            //$index = array_search($row->schedule_date->i18nFormat('HH:mm'), $horas);
            
            if(!empty($user_uid)){
                array_push($arrayFechas, array(
                    'date' => $date,
                    'dayoff' => (!empty($isDayOff) || empty($ent_sch_model) || Count($arrayTimes) <= 0) ? true : false,
                    'ent_sch_model' => $ent_sch_model,
                    'appointments' => Count($certTreatment),
                    'data' => $arrayTimes,
                ));
            }else {
                array_push($arrayFechas, array(
                    'date' => $date,
                    'dayoff' => false,
                    'appointments' => Count($certTreatment),
                    'data' => $arrayTimes,
                ));
            }
            $date = date('Y-m-d', strtotime($date."+ 1 days"));
        }
        $this->set('data', $arrayFechas);
        $this->success();
    }

    public function start_treatment(){
        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.DataConsultationAnswers');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.SysUserAdmin');
        $this->loadModel('SpaLiveV1.DataModelPatient');
        $Main = new MainController();
        $token = get('token', '');
        
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }
        $str_user = $user['email'] . " ". $user['name'] ." ". $user['lname'] . " ". $user['mname'];
        $str_user = strtolower($str_user);
        $user_test = false;
        if (strpos($str_user, "test") !== false) {            
            $user_test= true;
        }
        $createdby = USER_ID;
        $patient_id = USER_ID;
        $assistance_id = 0;

        $injector_id = $this->SysUsers->uid_to_id(get('injector_uid', ''));
        if($injector_id >= 0){
            $assistance_id = $injector_id;
        }

        $patient_uid = $this->SysUsers->uid_to_id(get('patient_uid', ''));
        if($patient_uid >= 0){
            $patient_id = $patient_uid;
        }

        /**********************/

        $string_treatments = get('treatments','');
        $string_treatments = str_replace(" ", "", $string_treatments);
        
        if (empty($string_treatments)) {
            $this->message('Treatments empty.');
            return;
        }
        $arr_treatments = explode(",", $string_treatments);

        $this->loadModel('SpaLiveV1.DataTreatmentsPrice');

        $schedule_by = get('schedule_by',USER_ID);
        $date = \DateTime::createFromFormat('Y-m-d H:i:s', get('schedule_date',''));

        if (empty($date)) {
             $this->message('Invalid date.');
            return;
        }

        $status = get('status','PETITION');
        if($status == 'REQUEST'){
            $assistance_id = get('provider_uid', 0);
            if($assistance_id === 0){
                $this->message('Not Provider Uid provided.');
                return;
            }
            $cpsEnt = $this->SysUsers->find()->select(['SysUsers.id','SysUsers.uid'])->where(['SysUsers.uid' => $assistance_id])->first();

            if(is_null($cpsEnt)){
                $this->message('Provider doesn\'t exist');
                return;
            }
            
            $assistance_id = $cpsEnt['id'];
        }
        
        $this->loadModel('SpaLiveV1.CatTreatmentsCi');
        
        $amount = 0;
        if (count($arr_treatments) > 0 && $assistance_id > 0) {
            foreach ($arr_treatments as $key => $value) {
                $ent_treat_price = $this->DataTreatmentsPrice->find()
                ->where(['DataTreatmentsPrice.treatment_id' => $value, 'DataTreatmentsPrice.user_id' => $assistance_id, 'DataTreatmentsPrice.deleted' => 0])
                ->first();

                if(empty($ent_treat_price)){
                    $no_preference = $this->CatTreatmentsCi->find()
                        ->where(['CatTreatmentsCi.id' => $value, 'CatTreatmentsCi.deleted' => 0, 'CatTreatmentsCi.name' => 'Let my provider help me decide'])
                        ->order(['CatTreatmentsCi.id' => 'DESC'])->first();
                    if(empty($no_preference)){                        
                        $this->message('One of these treatments is not provided by the user.');
                        return;
                    }
                }
            }
        }

        if ($createdby == $assistance_id) {
            $status = 'CONFIRM';
        }

        $schedule_date = get('schedule_date','');

        $treatment_uid = Text::uuid();

        $type_uber = $status == 'PETITION' ? 1 : 0;

        $notes = get('notes','');
        //// Palabras prohibidas
        $array_words = array('free','model', 'test', 'f r e e', 'm o d e l', 't e s t');

        //// Validar si las notas contienen alguna de las palabras prohibidas
        foreach ($array_words as $key => $value) {
            if (strpos(strtolower($notes), $value) !== false) {
                $status = $status == 'PETITION' ? 'STOP' : $status;
                break;
            }
        }

         //// Validar si el usuario es un paciente modelo
        $ent_patient = $this->DataModelPatient->find()->where(['DataModelPatient.email' => $user['email'], 'DataModelPatient.status' => 'assigned', 'DataModelPatient.registered_training_id >' => 0])->first();        
        if (!empty($ent_patient)) {
            $this->set('patient_model', true);
            $status = $status == 'PETITION' ? 'STOP' : $status;
        } else {
            $this->set('patient_model', false);            
        }

         //// Validar si las notas contienen el nombre de algun injector
        $ent_name_users = $this->SysUsers->find()->select(['SysUsers.name','SysUsers.lname'])->where(['SysUsers.type' => 'injector', 'SysUsers.active' => 1, 'SysUsers.deleted' => 0])->all();

        if(Count($ent_name_users) > 0){
            foreach ($ent_name_users as $key => $value) {
                $fullname = strtolower(trim($value['name']) . ' ' . trim($value['lname']));

                if (strpos(strtolower($notes), $fullname) !== false) {
                    $status = $status == 'PETITION' ? 'STOP' : $status;
                    break;
                }
            }
        }

         //// Validar si el nombre del paciente contiene la palabra free, model o test
        $patients_names = $this->SysUsers->find()->select(['SysUsers.name','SysUsers.lname'])->where(['SysUsers.id' => USER_ID, 'SysUsers.active' => 1, 'SysUsers.deleted' => 0])->toList();
        if(Count($patients_names) > 0){
            foreach ($array_words as $key => $value) {
                $fullname = strtolower(trim($patients_names[0]['name']) . ' ' . trim($patients_names[0]['lname']));
                if (strpos(strtolower($fullname), $value) !== false) {
                    $status = $status == 'PETITION' ? 'STOP' : $status;
                    break;
                }
            }
        }     

         //// Validar si la fecha de la cita es menos a las 2 horas de la fecha actual
        if(date('Y-m-d H:i', strtotime(date('Y-m-d H:i').'+ 2 hour')) >= date('Y-m-d H:i', strtotime($schedule_date))){
            $status = $status == 'PETITION' ? 'STOP' : $status;
        }

        //$assigned_doctor = rand(0,1) == 0 ? 'Dr Zach Cannon' : 'Dr Doohi Lee';
        $assigned_doctor = $this->SysUserAdmin->getRandomDoctor($assistance_id);
        

        if (empty($schedule_date)) 
            $schedule_date = date('Y-m-d H:i:s');

        $created = date('Y-m-d H:i:s');
        $array_save = array(
            'uid' => $treatment_uid,
            'notes' => get('notes',''),
            'patient_id' => $patient_id,
            'assistance_id' => $assistance_id,
            'treatments' => $string_treatments,
            'amount' => intval($amount),
            'address' => get('address',''),
            'suite' => get('suite',''),
            'zip' => get('zip',''),
            'city' => get('city',''),
            'state' => get('state',43),
            'schedule_date' => get('schedule_date',''),
            'status' => $status,
            'deleted' => 0,
            'created' => date('Y-m-d H:i:s'),
            'schedule_by' => $createdby,
            'createdby' => $createdby,
            'assigned_doctor' => $assigned_doctor,
            'type_uber' => $type_uber,
        );

        // GETTING COORDINATES

        $this->loadModel('SpaLiveV1.CatStates');
        $obj_state = $this->CatStates->find()->select(['CatStates.name'])->where(['CatStates.id' => get('state',43)])->first();
                    
        $chain =  get('address','') . ' ' . get('city','') . ' ' . get('zip','') . ' ,' . $obj_state->name;

        $coordinates = $Main->validate_coordinates($chain, get('zip',''));
        $array_save['latitude']   = $coordinates['latitude'];
        $array_save['longitude']  = $coordinates['longitude'];

        //******

        $c_entity = $this->DataTreatment->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->DataTreatment->save($c_entity)) {
                $this->loadModel('SpaLiveV1.DataTrainings');
                $this->loadModel('SpaLiveV1.CatCITreatments');
                $users_array = array();
                $this->set('uid', $treatment_uid);
                $this->set('grand_total', intval($amount));
                $fields = ['SysUsers.id', 'SysUsers.radius'];
                $fields['distance_in_mi'] = "(69.09 * DEGREES(ACOS(LEAST(1.0, COS(RADIANS(".$array_save['latitude']."))
                    * COS(RADIANS(SysUsers.latitude))
                    * COS(RADIANS(".$array_save['longitude']." - SysUsers.longitude))
                    + SIN(RADIANS(".$array_save['latitude']."))
                    * SIN(RADIANS(SysUsers.latitude))))))";
                $fields['subscriptions'] = "(SELECT COUNT(DS.id) FROM data_subscriptions DS WHERE DS.user_id = SysUsers.id AND DS.deleted = 0 AND DS.status = 'ACTIVE' AND DS.subscription_type IN ('SUBSCRIPTIONMD', 'SUBSCRIPTIONMSL') )";
                $fields['level'] = "(SELECT count(level) FROM data_trainings dt left join cat_trainings ct on ct.id = dt.training_id and ct.deleted =0  WHERE dt.user_id = SysUsers.id and dt.deleted=0 and ct.level='LEVEL 2' and ct.scheduled < NOW() )";                 
                $now = date('Y-m-d H:i:s');
                $ent_user = $this->SysUsers->find()->select($fields)->where(['SysUsers.deleted' => 0, 'SysUsers.login_status' => 'READY','SysUsers.type' => 'injector','SysUsers.active' => 1,'SysUsers.steps' => 'HOME'])->all();
                foreach ($ent_user as $row) {
                    // Validacion distancia
                    if(env('IS_DEV', false) == false){
                        if($row['distance_in_mi'] > $row['radius']) continue;
                    }
                    // Validacion subscriptions
                    if($row['subscriptions'] < 2) continue;
                    // only injector leve2 can received notify
                    if($type_uber ==1 && $row['level'] == 0) continue;
                    // Validacion tratamientos avanzados
                    $user_training_advanced= $this->DataTrainings->find()->join([
                        'CatTrainigs' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainings.training_id'],
                        ])->where(['CatTrainigs.level' => 'LEVEL 2','DataTrainings.user_id' => $row['id'],'DataTrainings.deleted' => 0, '(DATE_FORMAT(CatTrainigs.scheduled, "%Y-%m-%d 16:00:00") < "'.$now.'")'])->first();
                    $ent_treatments = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.name', 'CatCITreatments.category_treatment_id' , 'CatCITreatments.std_price','CTC.id', 'CTC.name', 'CTC.type_uber'])
                    ->join([
                        'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id'],
                    ])->where(['CatCITreatments.deleted' => 0, 'FIND_IN_SET(CatCITreatments.id, "' . $string_treatments . '")'])->all();
                    foreach ($ent_treatments as $key => $value) {
                        if($value['CTC']['type_uber'] == 'NEUROTOXINS ADVANCED' && empty($user_training_advanced)){
                            continue 2;
                        }
                    }
                    $users_array[] = $row['id'];
                }
                if($status == 'PETITION' && Count($users_array) > 0){ //TODO
                    //TODO Cambiar id hardcodeado por $users_array para produccion
                    //TODO cambiar el id
                    if(date('Y-m-d H:i:s', strtotime($now.'+ 1 hour')) < get('schedule_date','')){
                        $ent_patient = $this->SysUsers->find()->where(['SysUsers.id' => $patient_id, 'SysUsers.is_test' => 0, 'SysUsers.deleted' => 0])->first();

                        if(!empty($ent_patient)){
                            $constants = [
                                '[CNT/PatName]' => trim($ent_patient->name),
                            ];
                
                            if(!$user_test){
                                $Main->notify_devices('TREATMENT_AVAILABLE', $users_array, true, true, true, array(), '', $constants, true);
                                //$Main->notify_devices('TREATMENT_AVAILABLE',$users_array,true,true, true, array(), '', $constants,true);
                            }
                        }
                    }
                }
                
                if($status == 'REQUEST'){                
                    if(date('Y-m-d H:i:s', strtotime($now.'+ 1 hour')) < get('schedule_date','')){
                        $ent_patient = $this->SysUsers->find()->where(['SysUsers.id' => $patient_id, 'SysUsers.is_test' => 0, 'SysUsers.deleted' => 0])->first();
                        $constants = [
                            '[CNT/PName]' => trim($ent_patient->name) . ' ' . trim($ent_patient->lname),
                        ];
                        if(!$user_test)
                            $Main->notify_devices('NEW_TREATMENT_PATIENT',array($assistance_id),true,true, true, array(), '',$constants,true);            
                    }
                }

                if($status == 'STOP'){
                    if($user_test === false)
                        $this->sendEmalStopRequest(USER_EMAIL, USER_NAME . ' ' . USER_LNAME, $notes, get('address','') . ', ' .get('city',''), date('m-d-Y H:i:s'), date('m-d-Y H:i:s', strtotime(get('schedule_date',''))), $treatment_uid);
                }

                $this->success();
            }
        }
    }

    public function create_treatment(){
        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.DataConsultationAnswers');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.SysUserAdmin');
        $this->loadModel('SpaLiveV1.DataModelPatient');
        $Main = new MainController();
        $token = get('token', '');
        
        #region Validaciones 1
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }        

        $signature_id = 0;

        if (!isset($_FILES['file'])) {
           $this->set('error_file',$_FILES);
           return;
        }

        if (!isset($_FILES['file']['name'])) {
           $this->set('error_name',$_FILES['file']);
           return;
        }

        $str_name     = $_FILES['file']['name'];
        $signature_id = $this->Files->upload([
           'name' => $str_name,
           'type' => $_FILES['file']['type'],
           'path' => $_FILES['file']['tmp_name'],
           'size' => $_FILES['file']['size'],
        ]);

        if($signature_id <= 0){
           $this->message('Error in save content file.');
           return;
        }

        $str_user = $user['email'] . " ". $user['name'] ." ". $user['lname'] . " ". $user['mname'];
        $str_user = strtolower($str_user);
        $user_test = false;
        if (strpos($str_user, "test") !== false) {            
            $user_test= true;
        }
        $createdby = USER_ID;
        $patient_id = USER_ID;
        $assistance_id = 0;

        $injector_id = $this->SysUsers->uid_to_id(get('injector_uid', ''));
        if($injector_id >= 0){
            $assistance_id = $injector_id;
        }

        #endregion  

        $string_treatments    = get('treatments','');
        $string_treatments    = str_replace(" ", "", $string_treatments);
        $chose_specific_areas = get('chose_specific_areas', 0);

        if (empty($string_treatments) && $chose_specific_areas == 1) {
            $this->message('Treatments empty.');
            return;
        }
        $arr_treatments = explode(",", $string_treatments);

        $this->loadModel('SpaLiveV1.DataTreatmentsPrice');

        $schedule_by = USER_ID;
        $date = \DateTime::createFromFormat('Y-m-d H:i:s', get('schedule_date',''));

        #region Validaciones 2
        if (empty($date)) {
            $this->message('Invalid date.');
            return;
        }
        
        if($assistance_id === 0){
            $this->message('Not Provider Uid provided.');
            return;
        }
        $cpsEnt = $this->SysUsers->find()->select(['SysUsers.id','SysUsers.uid'])->where(['SysUsers.id' => $assistance_id])->first();

        if(is_null($cpsEnt)){
            $this->message('Provider doesn\'t exist');
            return;
        }
        #endregion  

        $assistance_id = $cpsEnt['id'];
        
        $this->loadModel('SpaLiveV1.CatTreatmentsCi');
        
        $amount = 0;
        if($chose_specific_areas == 1){
            if (count($arr_treatments) > 0 && $assistance_id > 0) {
                foreach ($arr_treatments as $key => $value) {
                    $ent_treat_price = $this->DataTreatmentsPrice->find()
                    ->where(['DataTreatmentsPrice.treatment_id' => $value, 'DataTreatmentsPrice.user_id' => $assistance_id, 'DataTreatmentsPrice.deleted' => 0])
                    ->first();

                    //se agrego la validación si es diferente a 999 porque puede que el paciente eligió neuro y iv y el injector no tiene agregado el treatment 999
                    // if(empty($ent_treat_price) && $value != 999){
                        // I commented this because the patient can choose neuro
                    if(empty($ent_treat_price)){
                        $no_preference = $this->CatTreatmentsCi->find()
                            //comente el nombre porque ocasionaba un error
                            ->where(['CatTreatmentsCi.id' => $value, 'CatTreatmentsCi.deleted' => 0/*, 'CatTreatmentsCi.name' => 'Let my provider help me decide'*/])
                            ->order(['CatTreatmentsCi.id' => 'DESC'])->first();
                        if(empty($no_preference)){                        
                            $this->set('value', $value);
                            
                            $this->message('One of these treatments is not provided by the user.');
                            return;
                        }
                        $string_treatments = $string_treatments. ',' . $value;
                    }
                }
            }
        }else{     
            if( !empty($string_treatments) ){
                $string_treatments = $string_treatments . ",999,1033";
            } else {
                $string_treatments = '999,1033';
            }
        }

        $schedule_date = get('schedule_date','');

        $treatment_uid = Text::uuid();
        
        $status = 'REQUEST';

        $notes = get('notes', '');

        #region Validaciones 3
        //// Palabras prohibidas
        $array_words = array('free','model', 'test', 'f r e e', 'm o d e l', 't e s t');

        //// Validar si las notas contienen alguna de las palabras prohibidas
        foreach ($array_words as $key => $value) {
            if (strpos(strtolower($notes), $value) !== false) {
                $status = $status == 'PETITION' ? 'STOP' : $status;
                break;
            }
        }
         //// Validar si el usuario es un paciente modelo
        $ent_patient = $this->DataModelPatient->find()->where(['DataModelPatient.email' => $user['email'], 'DataModelPatient.status' => 'assigned', 'DataModelPatient.registered_training_id >' => 0])->first();        
        if (!empty($ent_patient)) {
            $this->set('patient_model', true);
            $status = $status == 'PETITION' ? 'STOP' : $status;
        } else {
            $this->set('patient_model', false);            
        }

         //// Validar si las notas contienen el nombre de algun injector
        $ent_name_users = $this->SysUsers->find()->select(['SysUsers.name','SysUsers.lname'])->where(['SysUsers.type' => 'injector', 'SysUsers.active' => 1, 'SysUsers.deleted' => 0])->all();

        if(Count($ent_name_users) > 0){
            foreach ($ent_name_users as $key => $value) {
                $fullname = strtolower(trim($value['name']) . ' ' . trim($value['lname']));

                if (strpos(strtolower($notes), $fullname) !== false) {
                    $status = $status == 'PETITION' ? 'STOP' : $status;
                    break;
                }
            }
        }

         //// Validar si el nombre del paciente contiene la palabra free, model o test
        $patients_names = $this->SysUsers->find()->select(['SysUsers.name','SysUsers.lname'])->where(['SysUsers.id' => USER_ID, 'SysUsers.active' => 1, 'SysUsers.deleted' => 0])->toList();
        if(Count($patients_names) > 0){
            foreach ($array_words as $key => $value) {
                $fullname = strtolower(trim($patients_names[0]['name']) . ' ' . trim($patients_names[0]['lname']));
                if (strpos(strtolower($fullname), $value) !== false) {
                    $status = $status == 'PETITION' ? 'STOP' : $status;
                    break;
                }
            }
        }     

         //// Validar si la fecha de la cita es menos a las 2 horas de la fecha actual
        if(date('Y-m-d H:i', strtotime(date('Y-m-d H:i').'+ 2 hour')) >= date('Y-m-d H:i', strtotime($schedule_date))){
            $status = $status == 'PETITION' ? 'STOP' : $status;
        }
        #endregion  

        //$assigned_doctor = rand(0,1) == 0 ? 'Dr Zach Cannon' : 'Dr Doohi Lee';
        $assigned_doctor = $this->SysUserAdmin->getRandomDoctor($assistance_id);
        

        if (empty($schedule_date)) 
            $schedule_date = date('Y-m-d H:i:s');

        $created = date('Y-m-d H:i:s');
        $array_save = array(
            'uid' => $treatment_uid,
            'notes' => get('notes',''),
            'patient_id' => $patient_id,
            'assistance_id' => $assistance_id,
            'treatments' => $string_treatments,
            'amount' => intval($amount),
            'address' => get('address',''),
            'suite' => get('suite',''),
            'zip' => get('zip',''),
            'city' => get('city',''),
            'state' => USER_STATE,
            'schedule_date' => get('schedule_date',''),
            'status' => $status,
            'deleted' => 0,
            'created' => date('Y-m-d H:i:s'),
            'schedule_by' => $createdby,
            'createdby' => $createdby,
            'assigned_doctor' => $assigned_doctor,
            'type_uber' => 0,
            'signature_id' => $signature_id,
        );

        // GETTING COORDINATES

        $this->loadModel('SpaLiveV1.CatStates');
        $obj_state = $this->CatStates->find()->select(['CatStates.name'])->where(['CatStates.id' => get('state',43)])->first();
                    
        $chain =  get('address','') . ' ' . get('city','') . ' ' . get('zip','') . ' ,' . $obj_state->name;

        $coordinates = $Main->validate_coordinates($chain, get('zip',''));
        $array_save['latitude']   = $coordinates['latitude'];
        $array_save['longitude']  = $coordinates['longitude'];

        //******
         //validar que no tenga citas a ese dia
        $_where = ['DataTreatment.schedule_date' => get('schedule_date',''), 'DataTreatment.deleted' => 0, 'DataTreatment.patient_id' => $patient_id, 
                    'DataTreatment.status !=' => 'CANCEL'];

        $entity = $this->DataTreatment->find()->where($_where)->first();
 
        if(empty($entity)){

            $sstr_address = $array_save['address'] . ', ' . $array_save['city'] . ', ' . $obj_state->name . ' ' . $array_save['zip'];
            if (!empty($array_save['suite'])) {
                $sstr_address = $array_save['address'] . ', ' . $array_save['suite'] . ', ' . $array_save['city'] . ', ' . $obj_state->name . ' ' . $array_save['zip'];
            }

            $c_entity = $this->DataTreatment->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                if ($this->DataTreatment->save($c_entity)) {
                    $now = date('Y-m-d H:i:s');
                    if($status == 'REQUEST'){
                        if(date('Y-m-d H:i:s', strtotime($now.'+ 1 hour')) < get('schedule_date','')){
                            if(!$user_test){
                                $ent_patient = $this->SysUsers->find()->where(['SysUsers.id' => $patient_id, 'SysUsers.is_test' => 0, 'SysUsers.deleted' => 0])->first();
                                $constants = [
                                    '[CNT/PName]' => trim($ent_patient->name) . ' ' . trim($ent_patient->lname),
                                    '[SCHEDULE_PATIENT]' => date('M-d-y h:i a', strtotime($array_save['schedule_date'])),
                                    '[ADDRESS_PATIENT]' => $sstr_address
                                ];
                                $Main->notify_devices('NEW_TREATMENT_PATIENT',array($assistance_id),true,true, true, array(), '',$constants,true);
                            }
                        }
                    }
                    if($status == 'STOP'){
                        if($user_test === false)
                            $this->sendEmalStopRequest(USER_EMAIL, USER_NAME . ' ' . USER_LNAME, $notes, get('address','') . ', ' .get('city',''), date('m-d-Y H:i:s'), date('m-d-Y H:i:s', strtotime(get('schedule_date',''))), $treatment_uid);
                    }

                    $save_default_address = get('save_default_address',0);
                    if($save_default_address == 1){
                        $this->SysUsers->updateAll([
                            'street' => get('address',''), 
                            'suite' => get('suite',''), 
                            'zip' => get('zip',''), 
                            'city' => get('city',''),], 
                            ['id' => USER_ID]
                        );      
                    }
                    $this->success();
                }
            }
        }else{
            $this->message('You already have an appointment scheduled for that day at that time.');
            return;
        }
    }

    public function send_treatment_invitation(){
        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.DataConsultationAnswers');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.SysUserAdmin');
        $Main = new MainController();

        $token = get('token', '');
        
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $createdby = USER_ID;
        $assistance_id = USER_ID;
        $schedule_by = USER_ID;

        $patient_uid = $this->SysUsers->uid_to_id(get('patient_uid', ''));
        if($patient_uid >= 0){
            $patient_id = $patient_uid;            
        }else{
            $this->message('Patient doesn\'t exist.');
            return;
        }

        $string_treatments = get('treatments','');
        $string_treatments = str_replace(" ", "", $string_treatments);
        
        if (empty($string_treatments)) {
            $this->message('Treatments empty.');
            return;
        }

         $date = FrozenTime::createFromFormat(
            'Y-m-d H:i:s',
            get('schedule_date', date('Y-m-d H:i:s')),
            env('APP_DEFAULT_TIMEZONE', 'UTC')
        );

        if (empty($date)) {
            $this->message('Invalid date.');
            return;
        }

        $timestamp = $date->getTimestamp();        
        $now = time();

        if($timestamp < $now){
            $this->set('time', date('Y-m-d H:i:s',$timestamp));
            $this->set('now', date('Y-m-d H:i:s',$now));
            $currentDateTime = FrozenTime::now();
            if ($currentDateTime->isToday($date)) {
                $this->message('You cannot choose a previous hour for a treatment.');                                
            } else {
                $this->message('You cannot choose previous days for a treatment.');                
            }
            return;
        }

        $status = "";
        $schedule_date = get('schedule_date', '');

        $this->set('schedule_date', $schedule_date);

        $_where = ['DataTreatment.schedule_date' => $schedule_date, 'DataTreatment.deleted' => 0, 'DataTreatment.status !=' => 'CANCEL'];

        if(USER_ID == $patient_id){
            $status = 'CONFIRM';
            $_where['DataTreatment.patient_id'] = USER_ID;
        }else{
            $status = 'INVITATION';
            $_where['DataTreatment.assistance_id'] = USER_ID;
        }

        $treatment_uid = Text::uuid();

        $entity = $this->DataTreatment->find()->where($_where)->first();

        if(empty($entity)){

            //$assigned_doctor = rand(0,1) == 0 ? 'Dr Zach Cannon' : 'Dr Doohi Lee';
            $assigned_doctor = $this->SysUserAdmin->getRandomDoctor($assistance_id);
            
            $array_save = array(
                'uid' => $treatment_uid,
                'notes' => get('notes',''),
                'patient_id' => $patient_id,
                'assistance_id' => $assistance_id,
                'treatments' => $string_treatments,
                // 'treatments_prices' => $treatment_prices,
                'amount' => 0,
                'address' => get('address',''),
                'suite' => get('suite',''),
                'zip' => get('zip',''),
                'city' => get('city',''),
                'state' => get('state',43),
                'schedule_date' => $schedule_date,
                'status' => $status,
                'deleted' => 0,
                'created' => date('Y-m-d H:i:s'),
                'schedule_by' => $createdby,
                'createdby' => $createdby,
                'assigned_doctor' => $assigned_doctor
            );

            // GETTING COORDINATES

            $this->loadModel('SpaLiveV1.CatStates');
            $obj_state = $this->CatStates->find()->select(['CatStates.name'])->where(['CatStates.id' => get('state',43)])->first();
                        
            $chain =  get('address','') . ' ' . get('city','') . ' ' . get('zip','') . ' ,' . $obj_state->name;

            $coordinates = $Main->validate_coordinates($chain, get('zip',''));
            $array_save['latitude']   = $coordinates['latitude'];
            $array_save['longitude']  = $coordinates['longitude'];

            $c_entity = $this->DataTreatment->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                if ($this->DataTreatment->save($c_entity)) {
                    $users_array = array();
                    $this->set('uid', $treatment_uid);
                    $this->set('grand_total', intval(0));
                    $this->success();
                    $patient = $this->SysUsers->find()->where(['SysUsers.id' => $patient_id])->first();

                    $str_query_ ="(SELECT GROUP_CONCAT(CONCAT(CTC.name) SEPARATOR ', ') treat
                                  FROM cat_treatments_ci CT 
                                  JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
                                  WHERE FIND_IN_SET(CT.id,'$string_treatments') LIMIT 1)";
                    $treatment = $this->DataTreatment->getConnection()->execute($str_query_)->fetchAll('assoc');

                    if($patient->steps == 'REGISTER'){
                        $str_query_s = "(SELECT GROUP_CONCAT(CONCAT(CTC.name,' (',CT.name, ')') SEPARATOR ', ') string_treatments FROM cat_treatments_ci CT JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id WHERE FIND_IN_SET(CT.id,'$string_treatments') LIMIT 1)";

                        $streatment = $this->DataTreatment->getConnection()->execute($str_query_s)->fetchAll('assoc');

                        $separate_treatments = $this->separate_treatments($streatment[0]['string_treatments']);
                        
                        $type_register = '';
                        if(!empty($separate_treatments["neurotoxins"]) || !empty($separate_treatments["fillers"])){
                            $type_register = 'NEUROTOXIN';
                        }
                
                        if(!empty($separate_treatments["iv_therapy"])){
                            if ($type_register == 'NEUROTOXIN') {
                                $type_register = 'BOTH';
                            } else {
                                $type_register = 'IV THERAPY';
                            }
                        }

                        $this->loadModel('SpaLiveV1.SysPatientsOtherServices');

                        $_array_save = array(
                            'patient_id' => $patient_id,
                            'type'  => $type_register,
                        );
        
                        $_c_entity = $this->SysPatientsOtherServices->newEntity($_array_save);
        
                        if(!$_c_entity->hasErrors()) {
                            $this->SysPatientsOtherServices->save($_c_entity);
                        }
                    }

                    $treat = isset($treatment[0]['treat']) ? $treatment[0]['treat'] :'';
                    $dateTime = new \DateTime(get('schedule_date',''));
                    $formattedDateTime = $dateTime->format('m/d/Y H:i');
                    $constants = [
                        '[Insert_Date]' => $formattedDateTime,
                        '[Insert_Address]' => get('address','') . ' ' . get('suite','') . ' ' . get('city','') . ' ' . $obj_state->name . ' ,' .  get('zip',''),
                        '[Treatment_Type]' => $treat,
                        
                    ];//Insert_Date Insert_Address 
                    $Main->notify_devices('NEW_TREATMENT',array($patient_id),true,true, true, array(), '',$constants,true);
                }
            }
        }else{
            $this->message('You already have an appointment scheduled for that day at that time.');
            return;
        }
    }

    public function treatments_CP() {
        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.DataScheduleModel');
        $this->loadModel('SpaLiveV1.DataScheduleDaysOff');
        $token = get('token', '');
        
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }
        $mounth = get('mounth', '');
        $dateFinal = date('Y-m-t', strtotime($mounth));
        $dInicio = date('Y-m-d', strtotime($mounth));
        $date = $dInicio;
        $arrayFechas = [];
        while ($date <= $dateFinal) {
            $name_day = date('l', strtotime($date));
            $ent_sch_model = $this->DataScheduleModel->find()->where(['DataScheduleModel.model' => 'injector','DataScheduleModel.deleted' => 0, 'DataScheduleModel.injector_id' => USER_ID, 'DataScheduleModel.days' => strtoupper($name_day)])->first();
            if(empty($ent_sch_model)){
                $ent_sch_model = $this->DataScheduleModel->find()->where(['DataScheduleModel.model' => 'injector','DataScheduleModel.deleted' => 0, 'DataScheduleModel.injector_id' => USER_ID, 'DataScheduleModel.days LIKE' => '%,%'])->first();
            }
            $isDayOff = $this->DataScheduleDaysOff->find()->where(['DataScheduleDaysOff.deleted' => 0, 'DataScheduleDaysOff.date_off' => $date, 'DataScheduleDaysOff.user_id' => USER_ID])->first();
            $fields = ['DataTreatment.uid','DataTreatment.schedule_date','DataTreatment.amount','DataTreatment.tip','DataTreatment.status','User.name','User.lname'];
            $_where = ['DataTreatment.assistance_id' => USER_ID,
                       'DataTreatment.deleted' => 0,
                       'DataTreatment.status' => 'CONFIRM',
                       '(DATE_FORMAT(DataTreatment.schedule_date, "%Y-%m-%d") = "' . $date . '")'
                      ];

            $certTreatment = $this->DataTreatment->find()->select($fields)
            ->join([
                'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataTreatment.patient_id'],
            ])->where($_where)->all();
            $out_time = false;
            
            if(!empty($ent_sch_model)){
                if ( date('Y-m-d') == $date && date('H') >= $ent_sch_model->time_end) { 
                    $out_time = true;
                }
            }
            
            array_push($arrayFechas, array(
                'date' => $date,
                'appointments' => Count($certTreatment),
                'dayoff' => (!empty($isDayOff) || empty($ent_sch_model) || $out_time) ? true : false,
            ));
            $date = date('Y-m-d', strtotime($date."+ 1 days"));
        }
        $this->set('data', $arrayFechas);
        $this->success();
    }

    public function schedule_availability() {
        $this->loadModel('SpaLiveV1.DataScheduleDaysOff');
        $this->loadModel('SpaLiveV1.DataUserUnavailable');
        $token = get('token', '');
        
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $date = get('date','');
        if (empty($date)) {
            $this->message('Date empty.');
            return;
        }

        $this->loadModel('SpaLiveV1.SysUsers');

        $injector_id = $this->SysUsers->uid_to_id(get('injector_uid', ''));
        if($injector_id <= 0){
            $this->message('Invalid Injector.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataScheduleModel');

        $first_day = \DateTime::createFromFormat('Y-m-d', $date); // Tipo Fecha
        $day = strtoupper($first_day->format('l'));
        $ent_sch_model = $this->DataScheduleModel->find()->where(['DataScheduleModel.deleted' => 0, 'DataScheduleModel.injector_id' => $injector_id, 'DataScheduleModel.days LIKE' => $day, 'DataScheduleModel.model' => 'injector'])->first();
        if(empty($ent_sch_model)){
            $ent_sch_model = $this->DataScheduleModel->find()->where(['DataScheduleModel.deleted' => 0, 'DataScheduleModel.injector_id' => $injector_id, 'DataScheduleModel.days LIKE' => '%,%'])->first();
        }

        if (!empty($ent_sch_model)) {
            $days = $ent_sch_model->days;
            $hour_start = $ent_sch_model->time_start;
            $hour_end = $ent_sch_model->time_end;

            $find_date_str = $first_day->format('Y-m-d');

            $isDayOff = $this->DataScheduleDaysOff->find()->where(['DataScheduleDaysOff.deleted' => 0, 'DataScheduleDaysOff.date_off' => $find_date_str, 'DataScheduleDaysOff.user_id' => $injector_id])->first();
            if(!empty($isDayOff)){
                $this->set('data', array());
                $this->success();
                return;
            }
          
            $this->set('date', $find_date_str);
           
            $this->set('id', $injector_id);
            //Search next available day
            
            if (!empty($days) && !empty($day)) {

                if(strpos($days, $day) !== false){
                   
                } else {
                    $this->set('data', array());
                    $this->success();
                    return;
                }
            }

            $this->loadModel('SpaLiveV1.DataScheduleAppointment');
            $daysUnavObj = $this->DataUserUnavailable->find()->where(['DataUserUnavailable.day_unavailable' => $find_date_str, 'DataUserUnavailable.deleted' => 0, 'DataUserUnavailable.injector_id' => $injector_id])->toArray();
            $treatments_id = !empty($daysUnavObj) ? Hash::extract($daysUnavObj, '{n}.treatment_id') : [];
            $daysUnav = [];

            foreach($daysUnavObj as $item){
                $daysUnav[] = $find_date_str . " " . $item->time_unavailable->format("H:i:s");
            }
            
            $where = ['DataScheduleAppointment.deleted' => 0, 'DataScheduleAppointment.injector_id' => $injector_id,'DATE(DataScheduleAppointment.created)' => $find_date_str];
            if(!empty($treatments_id))$where['DataScheduleAppointment.treatment_id NOT IN'] = $treatments_id;
            $ent_appointments = $this->DataScheduleAppointment->find()->where($where)->all();

            $not_hours = array();
            $yes_hours = array();

            $today = date('Y-m-d');

            $qlimit = intval(date('H'));
            if ($qlimit > 0) $qlimit--;
            if ($date < $today) $qlimit = 24;
            if ($date > $today) $qlimit = 0;

            for($q=5;$q<=$qlimit;$q++) {
                $qq = $q . ':00';
                $not_hours[$qq] = true;
                $qq = $q . ':30';   
                $not_hours[$qq] = true;   
            }
            
            foreach ($ent_appointments as $row) {
                $not_hours[$row['created']->format("H:i")] = true;
            }

            $array_available = array();
            $result = array();
            
            for ($i = $hour_start; $i < $hour_end; $i++) {
                $ii = $i;
                $add = "a.m.";
                $iii = $i . ':30';
                $iiii = $i . ':00';

                if (!isset($not_hours[$iiii])) {
                    if ($i >= 12)  { $add = "p.m."; if ($ii > 12 ) $ii = $ii - 12; }
                    $array_available[] = array(
                        'label' => $ii . ':00 ' . $add,
                        'save' => $find_date_str . " " . ($i >= 10 ? $i : "0" . $i) . ":00:00"
                    );
                }
               
                if (!isset($not_hours[$iii])) {
                    $array_available[] = array(
                        'label' => $ii . ':30 ' . $add,// . ' - ' . ($ii + 1) . ':00 ' . $add2,
                        'save' => $find_date_str . " " . ($i >= 10 ? $i : "0" . $i) . ":30:00"
                    );
                }
            }

            foreach ($array_available as $key => $item) {
                if(in_array($item['save'], $daysUnav)){
                    //unset($array_available[$key]);
                    continue;
                }
                $result[] = $item;
            }

            if(!empty($ent_appointments)){
                 $this->set('data', $result);
                 $this->set('not_hour', $not_hours);
                 $this->set('yes_hour', $yes_hours);
                 $this->success();
            }

        } else {
            $this->set('data', array());
            $this->success();
        }
    }

    public function claim_treatment(){
        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.DataClaimTreatments');
        $token = get('token', '');
        
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $Main = new MainController();

        $treatment_uid = get('treatment_uid','');
        if(empty($treatment_uid)){
            $this->message('Treatment uid empty.');
            return;
        }
        
        $ent_treatment = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid, 'DataTreatment.deleted' => 0])->first();
        
        $this->loadModel('SpaLiveV1.SysUsers');
        $patient = $this->SysUsers->find()->where(['SysUsers.id' => $ent_treatment->patient_id])->first();
        
        $constants = [
            '[patient]' => $patient['name'] . ' ' . $patient['lname']
        ];
        
        if(empty($ent_treatment)){
            $this->message('Treatment uid invalid.');
            return;
        }

        $is_dev = env('IS_DEV', false);
                        
        if($ent_treatment->assistance_id > 0){
            $this->message('We\'re sorry, but already claimed this appointment.');
            return;
        }

        $patient_name = $patient->name . " " . $patient->lname;
        $injector_name = USER_NAME . " " . USER_LNAME;

        if($ent_treatment->notified == 0){
            $body = "Hi " . $patient_name . ", your appointment with your Myspalive Injector " . $injector_name . " is confirmed for " . $ent_treatment->schedule_date->i18nFormat('MM-dd-yyyy HH:mm:ss') . ". If you did not book this appointment or need assistance, please contact MySpaLive at info@myspalive.com or patientrelations@myspalive.com. We look forward to seeing you!";
            $this->sendNotificationOndemand($patient->email, $body, $patient->phone);
            $body = "Hi " . $injector_name . ", you've successfully claimed a treatment with " . $patient_name . " on " . $ent_treatment->schedule_date->i18nFormat('MM-dd-yyyy HH:mm:ss') . ". Please review the details in your MySpaLive dashboard.
                    *Please call " . $patient_name . " to confirm this was made correctly to eliminate any confusion* if there are any app issues you need assistance with please contact us";
            $this->sendNotificationOndemand(USER_EMAIL, $body, USER_PHONE);

            $this->loadModel('SpaLiveV1.CatNotifications');
            $ent_notification = $this->CatNotifications->find()->where(['CatNotifications.title' => "APPOINTMENT_PREPARATION"])->first();
            $to = $patient['email'];
            if (!empty($ent_notification)) {
                $subject = $ent_notification['subject'];
                $msg_mail = $ent_notification['body'];
                
                foreach($constants as $key => $value){
                        $msg_mail = str_replace($key, $value, $msg_mail);
                }
                
                $Main->send_email_after_register($to, $subject, $msg_mail);
            }
        

            $this->DataTreatment->updateAll(
                ['notified' => 1],
                ['id' => $ent_treatment->id]
            );
        }

        $this->DataTreatment->updateAll(
            [
                'assistance_id' => USER_ID, 
                'status' => 'CONFIRM',
                'review_open_home' => 1
            ],
            ['uid' => $treatment_uid]
        );
        $this->message('You have successfully claimed a treatment request from ' . $patient['name'] . ' ' . $patient['lname'] . '. To see the details, go to the requested appointments section on the homepage.');

        $array_save = array(
            'treatment_uid' => $treatment_uid,
            'injector_id' => USER_ID,
            'created' => date('Y-m-d H:i:s'),
            'deleted' => 0
        );

        $c_entity = $this->DataClaimTreatments->newEntity($array_save);

        if(!$c_entity->hasErrors()){
            $this->DataClaimTreatments->save($c_entity);
        }

        $this->success();

        /*
        $ent_claim = $this->DataClaimTreatments->find()->select(['User.name', 'User.lname'])
        ->join([
            'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataClaimTreatments.injector_id'],
        ])->where(['DataClaimTreatments.treatment_uid' => $treatment_uid, 'DataClaimTreatments.deleted' => 0])->first();*/

        /*
        $ent_claim = $this->DataClaimTreatments->find()
            ->select()
            ->where(['DataClaimTreatments.treatment_uid' => $treatment_uid, 'DataClaimTreatments.injector_id' => USER_ID,'DataClaimTreatments.deleted' => 0])->all();
        
        if(count($ent_claim) > 0){
            $this->message('You already claimed this appointment.');
            return;
        }

        $array_save = array(
            'treatment_uid' => $treatment_uid,
            'injector_id' => USER_ID,
            'created' => date('Y-m-d H:i:s'),
            'deleted' => 0
        );*/
        
    }

    public function cancel_claim(){
        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.DataClaimTreatments');
        $token = get('token', '');
        
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $treatment_uid = get('treatment_uid','');
        if(empty($treatment_uid)){
            $this->message('Treatment uid empty.');
            return;
        }

        $injector_id = 0;
        if($user['user_role'] == 'patient'){
            $this->loadModel('SpaLiveV1.SysUsers');
            $injector_id = $this->SysUsers->uid_to_id(get('injector_uid', 0));
            if($injector_id <= 0){
                $this->message('Invalid Injector.');
                return;
            }
        }

        $ent_treatment = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid, 'DataTreatment.deleted' => 0])->first();

        if(empty($ent_treatment)){
            $this->message('Treatment uid invalid.');
            return;
        }

        $this->DataTreatment->updateAll(
            [
                'notified' => 0,
                'assistance_id' => 0,
                'review_open_home' => 0
            ],
            ['id' => $ent_treatment->id]
        );

        if($user['user_role'] == 'patient' && $injector_id != 0){
            $this->DataClaimTreatments->updateAll(
                ['deleted' => 1],
                ['treatment_uid' => $treatment_uid, 'injector_id' => $injector_id]
            );
        }else{
            $this->DataClaimTreatments->updateAll(
                ['deleted' => 1],
                ['treatment_uid' => $treatment_uid, 'injector_id' => USER_ID]
            );
        }

        $this->success();
    }

    public function confirm_appointment() {
        $Main = new MainController();
        $token = get('token',"");

        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.DataPatientClinic');
        $this->loadModel('SpaLiveV1.SysUsers');

        $treatment_uid = get('uid', '');
        if(empty($treatment_uid)){
            $this->message('uid empty.');
            return;
        }

        $injector_id = get('injector_id', '');
        if(empty($injector_id)){
            $this->message('injector empty.');
            return;
        }

        $ent_treatments = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid])->first();
        if(empty($ent_treatments)){
            $this->message('Treatment not found');
            return;
        }

        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $injector_id])->first();
        if(empty($ent_user)){
            $this->message('Injector not found');
            return;
        }

        $array_save = array(
            'id' => $ent_treatments->id,
            'status' => 'CONFIRM',
            'assistance_id' => $injector_id
        );

        $c_entity = $this->DataTreatment->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->DataTreatment->save($c_entity)) {
                $this->success();
                if(USER_TYPE == 'injector' || USER_TYPE == 'gfe+ci'){
                    $Main->notify_devices('TREATMENT_CONFIRMED',array($ent_treatments->patient_id),true,true,true, array(), '',array(),true,$treatment_uid);
                }else if(USER_TYPE == 'patient'){
                    $Main->notify_devices('TREATMENT_CONFIRMED_INJECTOR',array($injector_id),true,true,true, array(), '',array(),true,$treatment_uid);
                }

                if($ent_treatments->type_uber == 1){
                    $this->loadModel('SpaLiveV1.DataClaimTreatments');             
                    $ent_claimed = $this->DataClaimTreatments->find()->select(['DataClaimTreatments.injector_id'])->where(['DataClaimTreatments.treatment_uid' => $treatment_uid, 'DataClaimTreatments.injector_id <>' => $injector_id])->all();
                    if(Count($ent_claimed) > 0){
                        $ids = array();
                        foreach($ent_claimed as $claim){
                            $ids[] = $claim->injector_id;
                        }
                        $Main->notify_devices('TREATMENT_CONFIRMED_OTHER_INJECTOR',$ids,true,true,true, array(), '',array(),true,$treatment_uid);
                    }
                }
                if($ent_treatments->type_uber == 0){
                    $patient = $this->SysUsers->find()->where(['SysUsers.id' => $ent_treatments->patient_id])->first();
                    //$Main = new MainController();
                    $this->loadModel('SpaLiveV1.CatNotifications');
                    $ent_notification = $this->CatNotifications->find()->where(['CatNotifications.title' => "APPOINTMENT_PREPARATION"])->first();
                    $to = $patient['email'];
                    if (!empty($ent_notification)) {
                        $subject = $ent_notification['subject'];
                        $msg_mail = $ent_notification['body'];
                        
                        $constants = [
                            '[Patient]' => $patient['name'] ." " . $patient['lname'],
                            
                        ];
                        foreach($constants as $key => $value){
                                $msg_mail = str_replace($key, $value, $msg_mail);
                        }
                        
                        $Main->send_email_after_register($to, $subject, $msg_mail);
                    }
                }
            }
        }
    }

    public function reject_appointment() {
        $Main = new MainController();
        $token = get('token',"");

        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $this->loadModel('SpaLiveV1.DataTreatment');

        $treatment_uid = get('uid', '');
        if(empty($treatment_uid)){
            $this->message('uid empty.');
            return;
        }

         $treatment_id = $this->DataTreatment->uid_to_id($treatment_uid);
        if (!$treatment_id) {
            $this->message('treatment not found.');
            return;
        }

        $ent_treatments = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid])->first();
        if(empty($ent_treatments)){
            $this->message('Treatment not found');
            return;
        }

        $array_save = array(
            'id' => $treatment_id,
            'status' => 'REJECT',
        );

        $c_entity = $this->DataTreatment->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->DataTreatment->save($c_entity)) {
                $str_query_renew = "UPDATE data_schedule_appointments SET deleted = 1 WHERE treatment_id = {$treatment_id}";
                $this->DataTreatment->getConnection()->execute($str_query_renew);

                $Main->notify_devices('TREATMENT_REJECTED',array($ent_treatments->patient_id),true,true,true, array(), '',array(),true);
                $this->success();
            }
        }
    }

    public function cancel_appointment() {
        $Main = new MainController();
        $token = get('token',"");

        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $this->loadModel('SpaLiveV1.DataTreatment');

        $treatment_uid = get('uid', '');
        if(empty($treatment_uid)){
            $this->message('uid empty.');
            return;
        }

         $treatment_id = $this->DataTreatment->uid_to_id($treatment_uid);
        if (!$treatment_id) {
            $this->message('treatment not found.');
            return;
        }

        $ent_treatments = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid])->first();
        if(empty($ent_treatments)){
            $this->message('Treatment not found');
            return;
        }

        $reason = get('reason', '');    

        $array_save = array(
            'id' => $treatment_id,
            'status' => 'CANCEL',
            'reason' => $reason
        );
        $schedule_date = $ent_treatments->schedule_date->i18nFormat('yyyy-MM-dd HH:mm:ss');        
        $now =  date('Y-m-d H:i:s'); //2023-04-21 12:50:00 
        $old_status = $ent_treatments->status;
        
        $t1 = strtotime( $schedule_date ); // 2023-04-20 21:00:00
        $t2 = strtotime( $now );        
        $diff = $t1 - $t2;
        $hours = $diff / ( 60 * 60 );        
        
        
        $str_schedule = date('m/d/Y H:s', strtotime($ent_treatments->schedule_date->i18nFormat('yyyy-MM-dd HH:mm:ss').''));        
        $c_entity = $this->DataTreatment->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->DataTreatment->save($c_entity)) {
                                
                $str_query_renew = "UPDATE data_schedule_appointments SET deleted = 1 WHERE treatment_id = {$treatment_id}";
                $this->DataTreatment->getConnection()->execute($str_query_renew);
                if($ent_treatments->status == 'PETITION'){
                    $arr_claimed = array();
                    $this->loadModel('SpaLiveV1.DataClaimTreatments');                 
                    $ent_claimed = $this->DataClaimTreatments->find()->select(['DataClaimTreatments.injector_id'])->where(['DataClaimTreatments.treatment_uid' => $treatment_uid])->toArray();                                             
                    for($i=0;$i<count($ent_claimed);$i++){
                        $arr_claimed[] = $ent_claimed[$i]->injector_id;
                    }
                    $this->set('arr_claimed', $arr_claimed);
                    
                    if(!empty($arr_claimed)){
                        $Main->notify_devices('The patient canceled the appointment set for '.$str_schedule,$arr_claimed,true,true,true, array(), '',array(),true);
                    }
                }else if($ent_treatments->assistance_id > 0) {
                    if(USER_TYPE == 'patient'){
                        $Main->notify_devices('TREATMENT_CANCEL',array($ent_treatments->assistance_id),true,true,true, array(), '',array(),true);
                        if($hours > 24){//no charge
                    
                        }else{//charge $50
                            $paymentsC = new PaymentsController();
                            $payment_bool = $paymentsC->charge_for_cancel_treatment($treatment_id);                    
                            
                            
                            if(isset($payment_bool[0]) && $payment_bool[0] == false){
                                $array_save = array(
                                    'id' => $treatment_id,
                                    'status' => $old_status,
                                    'reason' => $reason
                                );
                                $c_entity = $this->DataTreatment->newEntity($array_save);
                                if(!$c_entity->hasErrors()) {
                                    $this->DataTreatment->save($c_entity);                                                    
                                }
                                if(isset($payment_bool[1])){
                                    $this->message(json_encode($payment_bool[1]));
                                }
                                $this->success(false);
                                return;
                            }
                        }
                    }else{
                        if($ent_treatments->type_uber == 1){
                            $Main->notify_devices('Your claimed request has been cancelled by your Certified Injector.',array($ent_treatments->patient_id),true,true,true, array(), '',array(),true);
                        }else{
                            $Main->notify_devices('Your Certified Provider has canceled the appointment',array($ent_treatments->patient_id),true,true,true, array(), '',array(),true);
                        }
                    }
                }
                $this->success();
            }
        }
    }

    public function gfe_injector_status(){
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataConsultation');
        $this->loadModel('SpaLiveV1.DataCertificates');
        $this->loadModel('SpaLiveV1.DataTrainings');
        $this->loadModel('SpaLiveV1.CatCoursesType');
        $this->loadModel('SpaLiveV1.CatTrainings');

        $token = get('token',"");

        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        // Busqueda de cursos
        // Buscamos cursos del usuario para saber si necesita gfe de esos cursos.
        $user_trainings = $this->DataTrainings->find()->select(['CatTrainings.id','CatTrainings.level','CatCoursesType.title', 'CatCoursesType.id'])
        ->join([
            'CatTrainings' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainings.id = DataTrainings.training_id'],
            'CatCoursesType' => ['table' => 'cat_courses_type', 'type' => 'LEFT', 'conditions' => 'CatCoursesType.name_key = CatTrainings.level AND CatCoursesType.deleted = 0'],
        ])
        ->where(['DataTrainings.user_id' => USER_ID, 'DataTrainings.deleted' => 0, 'CatTrainings.deleted' => 0])
        ->all();
        
        $courses_id = [];
        if(count($user_trainings) > 0){
            foreach($user_trainings as $user_training){
                switch($user_training['CatTrainings']['level']){
                    case 'LEVEL 1':
                        $courses_id[] = 92;
                        break;
                    case 'LEVEL 2':
                        continue 2;
                        break;
                    case 'LEVEL 3 MEDICAL':
                        continue 2;
                        break;
                    case 'LEVEL 3 FILLERS':
                        $courses_id[] = 93;
                        break;
                    case 'LEVEL IV':
                        continue 2;
                        break;
                    default:
                        $courses_id[] = $user_training['CatTrainings']['id'];
                        break;
                }
            }

            if(count($courses_id) > 0){
                $courses_id = array_values(array_unique($courses_id, SORT_NUMERIC));
            }
        }
        // Busqueda de cursos        
        
        $fields = ['DataConsultation.uid','DataConsultation.payment','DataConsultation.schedule_date','DataCertificates.uid','DataCertificates.date_start','DataCertificates.date_expiration', 'DataCertificates.certificate_url', 'treatments_id' => 'DataConsultation.treatments'];
        $fields['assistance'] = "(SELECT UP.name FROM sys_users UP WHERE UP.id = DataConsultation.assistance_id)";
        $fields['expirate_soon'] = "(IF(DATEDIFF(NOW(), DataCertificates.date_expiration) < 30,1,0))";
        $fields['treatments'] = '(SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DataConsultation.treatments))';
        $fields['treatments_requested'] = '(SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DataConsultation.treatments_requested))';

        $_where = ['DataConsultation.deleted' => 0];
        $_where['DataConsultation.status'] = "CERTIFICATE";
        // $_where['DataConsultation.treatments <>'] = "";

        if(strtoupper($user['user_role']) != 'examiner'){
            $_where['DataConsultation.patient_id'] =  USER_ID;
            // $_where['OR'] = ['DataConsultation.assistance_id >' => 0, 'DataConsultation.assistance_id' => -1];

            // CHECK IF THE CERTIFICATE IS WAITING FOR THE EXAMINER
            $__where = ['DataConsultation.deleted' => 0];
            $__where['DataConsultation.status'] = "DONE";
            $__where['DataConsultation.treatments <>'] = "";
            $__where['DataConsultation.patient_id'] = USER_ID;
            
            $waiting_certificate = 
                $this->DataConsultation->find() 
                    ->where($__where)                    
                    ->all();
            $this->set('waiting_certificate', count($waiting_certificate) > 0);

            
        }else{
            $_where['DataConsultation.assistance_id'] = USER_ID;
            // $fields['treatments'] = '(SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DataConsultation.treatments))';
            // $fields['patient'] = "(SELECT CONCAT_WS(' ', UP.name, UP.lastname) FROM sys_users_patient UP JOIN sys_users U ON UP.user_id = U.id WHERE U.id = DataConsultation.patient_id)";
        }

        $certItem = $this->DataConsultation->find()->select($fields)
        ->join([
            'DataCertificates' => ['table' => 'data_certificates', 'type' => 'LEFT', 'conditions' => 'DataCertificates.consultation_id = DataConsultation.id'],
        ])
        ->where($_where)->order(['DataConsultation.id' => 'DESC'])->all();

        $arr_certificates = array();
        $current_date = date('m/d/Y');
        $current_date_validation = date('Y-m-d');
        if (!empty($certItem)) {
            foreach ($certItem as $row) {
                $formatedDateStart = date('m/d/Y', strtotime(empty($row->DataCertificates['date_start']) ? $row['schedule_date'] : $row->DataCertificates['date_start']));
                if(empty($row->DataCertificates['date_expiration']) ? "" : $row->DataCertificates['date_expiration'] === ""){
                    $formatedDateExpiration = "";
                }else{
                    $formatedDateExpiration = date('m/d/Y', strtotime(empty($row->DataCertificates['date_expiration']) ? "" : $row->DataCertificates['date_expiration']));
                }
                $expires_soon = false;

                if(!empty($row->DataCertificates['date_expiration'])){
                    $expires_soon = $current_date_validation >= date('Y-m-d', strtotime($row->DataCertificates['date_expiration'] . " - 15 days")) && $current_date_validation <= $row->DataCertificates['date_expiration'];
                }else{
                    $expires_soon = false;
                }
                $arr_certificates[] = array(
                    'consultation_uid' => $row['uid'],
                    'payment' => empty($row['payment']) ? 0 : 1,
                    'certificate_uid' => empty($row['payment']) ? "" : ($row->DataCertificates['uid'] != null ? $row->DataCertificates['uid'] : ""),
                    'date_start' => $formatedDateStart,
                    'date_expiration' => $formatedDateExpiration,
                    'assistance_name' => isset($row['assistance']) ? $row['assistance'] : '',
                    'treatments' => isset($row["treatments"]) ? $row["treatments"] : $row["treatments_requested"],
                    'treatments_id' => isset($row["treatments_id"]) ? $row["treatments_id"] : "",
                    'show_certificate' => isset($row["treatments"]) ? true : false,
                    'expired' => empty($row->DataCertificates['date_expiration']) ? "" : ($row->DataCertificates['date_expiration'] < $current_date_validation ? true : false),
                    'expires_soon' => $expires_soon,
                    'certificate_url' => empty($row->DataCertificates['certificate_url']) ? "" : $row->DataCertificates['certificate_url'],
                );
            }
        }
        
        $seconds_in_two_weeks = 2 * 7 * 24 * 60 * 60;
        $certificate = array();

        $list_gfe_requires = [];

        function hasTreatmentInList($list, $treatment_id) {
            foreach ($list as $item) {
                if (!isset($item['treatments'])) continue;
                $ids = array_map('trim', explode(',', $item['treatments']));
                if (in_array((string)$treatment_id, $ids, true)) {
                    return true;
                }
            }
            return false;
        }

        if(count($courses_id) > 0){
            foreach($courses_id as $course_id){
                // Saltar si ya existe
                if (hasTreatmentInList($list_gfe_requires, $course_id)) {
                    continue;
                }
                $ot = $course_id == 92 || $course_id == 93 ? false : true;
                $title = '';
                if($ot){
                    $course_type = $this->CatTrainings->find()
                    ->select(['CatCoursesType.title', 'CatCoursesType.id'])
                    ->join([
                        'CatCoursesType' => ['table' => 'cat_courses_type', 'type' => 'INNER', 'conditions' => 'CatCoursesType.name_key = CatTrainings.level'],
                    ])
                    ->where(['CatTrainings.id' => $course_id])->first();
                    //$course_type = $this->CatCoursesType->find()->where(['CatCoursesType.id' => $course_id])->first();
                    $title = $course_type['CatCoursesType']['title'];
                    $cat_course_type_id = $course_type['CatCoursesType']['id'];
                }else{
                    $title = 'Botox & other neurotoxins,Fillers & Hylenex';
                    $cat_course_type_id = $course_id;
                }
                $fields = ['DataConsultation.uid','DataConsultation.payment','DataConsultation.schedule_date','DataCertificates.uid','DataCertificates.date_start','DataCertificates.date_expiration', 'DataCertificates.certificate_url', 'DataConsultation.treatments'];
                $fields['expirate_soon'] = "(IF(DATEDIFF(NOW(), DataCertificates.date_expiration) < 30,1,0))";

                $_where = ['DataConsultation.deleted' => 0];
                $_where['DataConsultation.status'] = "CERTIFICATE";
                $_where['DataConsultation.patient_id'] =  USER_ID;
                $_where['DataConsultation.treatments LIKE'] = "%$cat_course_type_id%";

                // CHECK IF THE CERTIFICATE IS WAITING FOR THE EXAMINER
                $__where = ['DataConsultation.deleted' => 0];
                $__where['DataConsultation.status IN'] = array("DONE", "INIT");
                $__where['DataConsultation.treatments LIKE'] = "%$cat_course_type_id%";
                $__where['DataConsultation.patient_id'] = USER_ID;
                
                $waiting_certificate = 
                    $this->DataConsultation->find() 
                        ->where($__where)                    
                        ->all();

                $gfes = $this->DataConsultation->find()->select($fields)
                ->join([
                    'DataCertificates' => ['table' => 'data_certificates', 'type' => 'LEFT', 'conditions' => 'DataCertificates.consultation_id = DataConsultation.id'],
                ])
                ->where($_where)->last();

                if(empty($gfes)){
                    $list_gfe_requires[] = [
                        'course_id' => $course_id,
                        'title' => $title,
                        'button_text' => 'Get my GFE',
                        'status_gfe' => '',
                        'ot' => $ot,
                        'treatments' => $ot ? $cat_course_type_id : '92,93',
                    ];
                }else{
                    $current_date_validation = date('Y-m-d');
                    //$expired = empty($gfes['DataCertificates']['date_expiration']) ? "" : (date('Y-m-d', strtotime($gfes['DataCertificates']['date_expiration'])) < $current_date_validation ? true : false);
                    $expires_soon = false;
                    $expired = false;

                    if(!empty($gfes['DataCertificates']['date_expiration'])){
                        $expires_soon = $current_date_validation >= date('Y-m-d', strtotime($gfes['DataCertificates']['date_expiration'] . " - 15 days")) && $current_date_validation <= $gfes['DataCertificates']['date_expiration'];
                        $expired = date('Y-m-d', strtotime($gfes['DataCertificates']['date_expiration'])) < $current_date_validation ? true : false;
                    }else{
                        $expires_soon = false;
                        $expired = false;
                    }

                    if($expired != true){
                        if($expires_soon == true){
                            $list_gfe_requires[] = [
                                'course_id' => $course_id,
                                'title' => $title,
                                'button_text' => 'Renew',
                                'status_gfe' => 'Your GFE expires soon '.$gfes['DataCertificates']['date_expiration'],
                                'ot' => $ot,
                                'treatments' => $gfes['treatments'],
                            ];
                        }else{
                            if($gfes['DataCertificates']['date_expiration'] == $current_date_validation){
                                $list_gfe_requires[] = [
                                    'course_id' => $course_id,
                                    'title' => $title,
                                    'button_text' => 'Renew',
                                    'status_gfe' => 'Your GFE expires today '.$gfes['DataCertificates']['date_expiration'],
                                    'ot' => $ot,
                                    'treatments' => $gfes['treatments'],
                                ];
                            }else if($gfes['date_expiration'] >= $current_date_validation){
                                
                                $diference_in_seconds = strtotime($gfes['DataCertificates']['date_expiration']) - strtotime($current_date_validation);
                                
                                if($diference_in_seconds >= $seconds_in_two_weeks) {
                                    continue;
                                }else{
                                    $list_gfe_requires[] = [
                                        'course_id' => $course_id,
                                        'title' => $title,
                                        'button_text' => 'Get my GFE',
                                        'status_gfe' => '',
                                        'ot' => $ot,
                                        'treatments' => $gfes['treatments'],
                                    ];
                                }
                            }
                        }
        
                    }else{
                        $list_gfe_requires[] = [
                            'course_id' => $course_id,
                            'title' => $title,
                            'button_text' => 'Renew',
                            'status_gfe' => 'Your GFE expired on '.$gfes['DataCertificates']['date_expiration'],
                            'ot' => $ot,
                            'treatments' => $gfes['treatments'],
                        ];
                    }
                }
            }
        }

        $this->set('list_gfe_requires', $list_gfe_requires);
        $this->set('certificates', $arr_certificates);
        $this->success();
    }

    public function _gfe_injector_status_(){

        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataConsultation');
        $this->loadModel('SpaLiveV1.DataCertificates');
        
        $token = get('token',"");

        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }
        
        $fields = ['DataConsultation.uid','DataConsultation.payment','DataConsultation.schedule_date','DataCertificates.uid','DataCertificates.date_start','DataCertificates.date_expiration', 'DataCertificates.certificate_url'];
        $fields['assistance'] = "(SELECT UP.name FROM sys_users UP WHERE UP.id = DataConsultation.assistance_id)";
        $fields['expirate_soon'] = "(IF(DATEDIFF(NOW(), DataCertificates.date_expiration) < 30,1,0))";
        $fields['treatments'] = '(SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DataConsultation.treatments))';
        $fields['treatments_requested'] = '(SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DataConsultation.treatments_requested))';

        $_where = ['DataConsultation.deleted' => 0];
        $_where['DataConsultation.status'] = "CERTIFICATE";
        // $_where['DataConsultation.treatments <>'] = "";

        if(strtoupper($user['user_role']) != 'examiner'){
            $_where['DataConsultation.patient_id'] =  USER_ID;
            // $_where['OR'] = ['DataConsultation.assistance_id >' => 0, 'DataConsultation.assistance_id' => -1];

            // CHECK IF THE CERTIFICATE IS WAITING FOR THE EXAMINER
            $__where = ['DataConsultation.deleted' => 0];       
            $__where['DataConsultation.status'] = "DONE";
            $__where['DataConsultation.treatments <>'] = "";
            $__where['DataConsultation.patient_id'] = USER_ID;
            
            $waiting_certificate = 
                $this->DataConsultation->find() 
                    ->where($__where)                    
                    ->all();
            $this->set('waiting_certificate', count($waiting_certificate) > 0);

            
        }else{
            $_where['DataConsultation.assistance_id'] = USER_ID;
            // $fields['treatments'] = '(SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DataConsultation.treatments))';
            // $fields['patient'] = "(SELECT CONCAT_WS(' ', UP.name, UP.lastname) FROM sys_users_patient UP JOIN sys_users U ON UP.user_id = U.id WHERE U.id = DataConsultation.patient_id)";
        }

        $certItem = $this->DataConsultation->find()->select($fields)
        ->join([
            'DataCertificates' => ['table' => 'data_certificates', 'type' => 'LEFT', 'conditions' => 'DataCertificates.consultation_id = DataConsultation.id'],
        ])
        ->where($_where)->order(['DataConsultation.id' => 'DESC'])->all();
        

        $arr_certificates = array();
        $current_date = date('m/d/Y');
        $current_date_validation = date('Y-m-d');
        if (!empty($certItem)) {
            foreach ($certItem as $row) {
                $formatedDateStart = date('m/d/Y', strtotime(empty($row->DataCertificates['date_start']) ? $row['schedule_date'] : $row->DataCertificates['date_start']));
                if(empty($row->DataCertificates['date_expiration']) ? "" : $row->DataCertificates['date_expiration'] === ""){
                    $formatedDateExpiration = "";
                }else{
                    $formatedDateExpiration = date('m/d/Y', strtotime(empty($row->DataCertificates['date_expiration']) ? "" : $row->DataCertificates['date_expiration']));
                }
                $expires_soon = false;

                if(!empty($row->DataCertificates['date_expiration'])){
                    $expires_soon = $current_date_validation >= date('Y-m-d', strtotime($row->DataCertificates['date_expiration'] . " - 15 days")) && $current_date_validation <= $row->DataCertificates['date_expiration'];
                }else{
                    $expires_soon = false;
                }
                $arr_certificates[] = array(
                    'consultation_uid' => $row['uid'],
                    'payment' => empty($row['payment']) ? 0 : 1,
                    'certificate_uid' => empty($row['payment']) ? "" : ($row->DataCertificates['uid'] != null ? $row->DataCertificates['uid'] : ""),
                    'date_start' => $formatedDateStart,
                    'date_expiration' => $formatedDateExpiration,
                    'assistance_name' => isset($row['assistance']) ? $row['assistance'] : '',
                    'treatments' => isset($row["treatments"]) ? $row["treatments"] : $row["treatments_requested"],
                    'show_certificate' => isset($row["treatments"]) ? true : false,
                    'expired' => empty($row->DataCertificates['date_expiration']) ? "" : ($row->DataCertificates['date_expiration'] < $current_date_validation ? true : false),
                    'expires_soon' => $expires_soon,
                    'certificate_url' => empty($row->DataCertificates['certificate_url']) ? "" : $row->DataCertificates['certificate_url'],
                );
            }
        }
        
        $seconds_in_two_weeks = 2 * 7 * 24 * 60 * 60;
        $certificate = array();

        if(!empty($arr_certificates)){

            $certificate = $arr_certificates[0];

            if($certificate['expired'] != true){

                if($certificate['expires_soon'] == true){
                    $this->set('certificates', $arr_certificates);
                    $this->set('require_gfe', true);
                    $this->set('button_text', 'Renew');
                    $this->set('status_gfe','Your GFE expires soon '.$certificate['date_expiration']);
                    $this->success();
                    return;
                }else{
                    if($certificate['date_expiration'] == $current_date){
                        $this->set('certificates', $arr_certificates);
                        $this->set('require_gfe', false);
                        $this->set('button_text', '');
                        $this->set('status_gfe','Your GFE expires today '.$certificate['date_expiration']);
                        $this->success();
                        return;
                    }else if($certificate['date_expiration'] >= $current_date){
                        
                        $diference_in_seconds = strtotime($certificate['date_expiration']) - strtotime($current_date);
                        
                        if($diference_in_seconds >= $seconds_in_two_weeks) {
    
                            $this->set('certificates', $arr_certificates);
                            $this->set('require_gfe', false);
                            $this->set('button_text', '');
                            $this->set('status_gfe','Your GFE expires on '.$certificate['date_expiration']);
                            $this->success();
                            return;
                        }else{
                            $this->set('certificates', $arr_certificates);
                            $this->set('button_text',  'Get my GFE');
                            $this->set('status_gfe', '');
                            $this->set('require_gfe',  true);
                            $this->success();
                            return;
                        }
                    }
                }

            }else{
                $certificate = $arr_certificates[0];
                $this->set('certificates', $arr_certificates);
                $this->set('require_gfe', true);
                $this->set('button_text', 'Renew');
                $this->set('status_gfe','Your GFE expired on '.$certificate['date_expiration']);
                $this->success();
                return;
            }
        }

        $this->set('certificates', $arr_certificates);
        $this->set('button_text', count($certItem) === 0 ? 'Get my GFE':'');
        $this->set('status_gfe', count($certItem) === 0 ? 'Your GFE has no certificates.':'');
        $this->set('require_gfe', empty($certificate) ? true : false);
        $this->success();
    }

    public function list_gfe_require(){
        $token = get('token',"");

        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $array_treatmets = [];
        
        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.CatTreatmentsCi');
        $this->loadModel('SpaLiveV1.DataModelPatient');
        $this->loadModel('SpaLiveV1.DataConsultation');
        $this->loadModel('SpaLiveV1.DataPayment');

        $pay = $this->DataPayment->find()->where(['id_from' => USER_ID, 'service_uid' => '', 'payment <>' => '', 'is_visible' => 1, 'type' => 'GFE'])->first();

        $type = get('type','');
        $name_key = get('name_key','');
    


        if (!empty($type) && $type == 'OTHER_TREATMENTS' && !empty($name_key)) {
             $this->loadModel('SpaLiveV1.SysTreatmentsOt');
            $other_treatment = $this->SysTreatmentsOt->find()
            ->select(['CT.id','CT.name'])
             ->join([
                'CT' => ['table' => 'cat_treatments', 'type' => 'INNER', 'conditions' => 'SysTreatmentsOt.id = CT.other_treatment_id AND CT.deleted = 0'],
            ])
            ->where(['SysTreatmentsOt.deleted' => 0, 'SysTreatmentsOt.active' => 1, 'SysTreatmentsOt.name_key' => $name_key])
            ->first();

            if (!empty($other_treatment)) {
                $array_treatmets[] = $other_treatment['CT']['name'];
                $this->set('treatments', array_unique($array_treatmets));
                $this->set('require_gfe', true);
                $this->set('val', '1');
                $this->set('renew_gfe', false);
                $this->success();
                return;
            }
        }


        if(!empty($pay)){
            $array_treatmets[] = 'Botox & other neurotoxins';
            $this->set('treatments', array_unique($array_treatmets));
            $this->set('require_gfe', true);
            $this->set('val', '1');
            $this->set('renew_gfe', false);
            $this->success();
            return;
        }

        if(USER_TYPE == 'patient'){
            $ent_patient = $this->DataModelPatient->find()->where(['DataModelPatient.email' => USER_EMAIL, 'DataModelPatient.status' => 'assigned', 'DataModelPatient.registered_training_id >' => 0])->first();
            if(!empty($ent_patient)){                
                $ent_treatment = $this->CatTreatmentsCi->find()->select(['CatTreatmentsCi.name', 'CT.name', 'DCP.id'])
                ->join([
                    'CT' => ['table' => 'cat_treatments', 'type' => 'INNER', 'conditions' => 'CatTreatmentsCi.treatment_id = CT.id'],
                    'DC' => ['table' => 'data_consultation', 'type' => 'LEFT', 'conditions' => 'DC.deleted = 0 AND DC.status = "CERTIFICATE" AND DC.patient_id = ' . USER_ID],
                    'DCE' => ['table' => 'data_certificates', 'type' => 'LEFT', 'conditions' => 'DCE.consultation_id = DC.id AND DCE.deleted = 0 AND DCE.date_expiration > ' . date('Y-m-d')],
                    'DCP' => ['table' => 'data_consultation_plan', 'type' => 'LEFT', 'conditions' => 'DCP.treatment_id = CatTreatmentsCi.treatment_id AND DCP.proceed = 1 AND DCP.deleted = 0 AND DCP.consultation_id = DC.id']
                ])->where(['CatTreatmentsCi.id' => 1])->first();
                
                if(!empty($ent_treatment)){
                    if($ent_treatment['DCP']['id'] == null || empty($ent_treatment['DCP']['id'])){
                        $array_treatmets[] = $ent_treatment['CT']['name'];
                    }
                }

                if(Count($array_treatmets) > 0){
                    $this->set('treatments', array_values(array_unique($array_treatmets))); // Usar array_values para reindexar numéricamente, ya que regresaba //!"#": "Lorem"
                    $this->set('require_gfe', true);
                    $this->set('val', '2');
                    $this->success();

                    $fields = ['DataConsultation.uid','DataConsultation.payment','DataConsultation.schedule_date','DataCertificates.uid','DataCertificates.date_start','DataCertificates.date_expiration'];                    
                    $_where = ['DataConsultation.deleted' => 0];
                    $_where['DataConsultation.status'] = "CERTIFICATE";
                    $_where['DataConsultation.patient_id'] = USER_ID;
                    $_where['OR'] = ['DataConsultation.assistance_id >' => 0, 'DataConsultation.assistance_id' => -1];
                    $certexp = $this->DataConsultation->find()->select($fields)
                    ->join([
                        'DataCertificates' => ['table' => 'data_certificates', 'type' => 'LEFT', 'conditions' => 'DataCertificates.consultation_id = DataConsultation.id'],
                    ])
                    ->where($_where)->last();
                    $date = date('Y-m-d');
                    $date = date('Y-m-d', strtotime($date . " + 14 days"));//Change GFE time renewals for injectors
                    if(!empty($certexp) && $certexp['DataCertificates']['date_expiration'] < $date){
                        $this->set('renew_gfe', true);
                    }else{
                        $this->set('renew_gfe', false);
                    }
                    return;
                }
            }
        }

        $ent_datatreatments = $this->DataTreatment->find()->where(['DataTreatment.patient_id' => USER_ID, 'DataTreatment.status IN ("PETITION", "REQUEST", "CONFIRM", "STOP")', 'DataTreatment.deleted' => 0])->all();
        
        if(Count($ent_datatreatments) > 0){
            foreach($ent_datatreatments as $row){
                $ids = explode(',',$row['treatments']);
                for ($i=0; $i < Count($ids); $i++) { 
                    $ent_treatment = $this->CatTreatmentsCi->find()->select(['CatTreatmentsCi.name', 'CT.name', 'DCP.id'])
                    ->join([
                        'CT' => ['table' => 'cat_treatments', 'type' => 'INNER', 'conditions' => 'CatTreatmentsCi.treatment_id = CT.id AND CT.deleted = 0'],
                        'DC' => ['table' => 'data_consultation', 'type' => 'LEFT', 'conditions' => 'DC.deleted = 0 AND DC.status = "CERTIFICATE" AND DC.patient_id = ' . USER_ID],
                        'DCE' => ['table' => 'data_certificates', 'type' => 'LEFT', 'conditions' => 'DCE.consultation_id = DC.id AND DCE.deleted = 0 AND DCE.date_expiration > ' . date('Y-m-d')],
                        'DCP' => ['table' => 'data_consultation_plan', 'type' => 'LEFT', 'conditions' => 'DCP.treatment_id = CatTreatmentsCi.treatment_id AND DCP.proceed = 1 AND DCP.deleted = 0 AND DCP.consultation_id = DC.id']
                    ])->where(['CatTreatmentsCi.id' => $ids[$i]])->first();
                    
                    if(!empty($ent_treatment)){
                        if($ent_treatment['DCP']['id'] == null || empty($ent_treatment['DCP']['id'])){
                            $array_treatmets[] = $ent_treatment['CT']['name'];
                        }
                    }
                }
            }
            if(Count($array_treatmets) > 0){
                $this->set('treatments', array_values(array_unique($array_treatmets))); // Usar array_values para reindexar numéricamente
                $this->set('require_gfe', true);
                $this->set('val', '3');
                $fields = ['DataConsultation.uid','DataConsultation.payment','DataConsultation.schedule_date','DataCertificates.uid','DataCertificates.date_start','DataCertificates.date_expiration'];                    
                    $_where = ['DataConsultation.deleted' => 0];
                    $_where['DataConsultation.status'] = "CERTIFICATE";
                    $_where['DataConsultation.patient_id'] = USER_ID;
                    $_where['OR'] = ['DataConsultation.assistance_id >' => 0, 'DataConsultation.assistance_id' => -1];
                    $certexp = $this->DataConsultation->find()->select($fields)
                    ->join([
                        'DataCertificates' => ['table' => 'data_certificates', 'type' => 'LEFT', 'conditions' => 'DataCertificates.consultation_id = DataConsultation.id'],
                    ])
                    ->where($_where)->last();
                    $date = date('Y-m-d');
                    $date = date('Y-m-d', strtotime($date . " + 14 days"));//Change GFE time renewals for injectors
                    if(!empty($certexp) && $certexp['DataCertificates']['date_expiration'] < $date){
                        $this->set('renew_gfe', true);
                    }else{
                        $this->set('renew_gfe', false);
                    }
                $this->success();
                return;
            }
        }

        $fields = ['DataConsultation.uid','DataConsultation.payment','DataConsultation.schedule_date','DataCertificates.uid','DataCertificates.date_start','DataCertificates.date_expiration'];
        $fields['treatments'] = '(SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DataConsultation.treatments))';
        $fields['treatments_requested'] = '(SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments CT WHERE CT.deleted = 0 AND FIND_IN_SET(CT.id,DataConsultation.treatments_requested))';

        $_where = ['DataConsultation.deleted' => 0];
        $_where['DataConsultation.status'] = "CERTIFICATE";

        $_where['DataConsultation.patient_id'] = USER_ID;
        //$_where['OR'] = ['DataConsultation.assistance_id' => 0, 'DataConsultation.assistance_id' => -1];

        $certItem = $this->DataConsultation->find()->select($fields)
        ->join([
            'DataCertificates' => ['table' => 'data_certificates', 'type' => 'LEFT', 'conditions' => 'DataCertificates.consultation_id = DataConsultation.id'],
        ])
        ->where($_where)->last();
        $date = date('Y-m-d');
        
        $date = date('Y-m-d', strtotime($date . " + 15 days"));//Change GFE time renewals for injectors
        
        if(!empty($certItem) && $certItem['DataCertificates']['date_expiration'] < $date){
            $array_treatmets[] = 'Botox & other neurotoxins';
            $this->set('treatments', array_unique($array_treatmets));
            $this->set('require_gfe', true);
            $this->set('val', '4');
            $this->set('renew_gfe', true);
            $this->success();
            return;
        }

        if(!empty($certItem) && $certItem['DataCertificates']['date_expiration'] < $date){            
            $this->set('renew_gfe', true);                    
        }else{
            $this->set('renew_gfe', false);
        }
        
        $this->set('cert', $certItem);
        $this->set('treatments', array());
        $this->set('require_gfe', false);
        $this->set('val', '5');
        $this->success();
    }

    public function get_certificates() {
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataConsultation');
        $this->loadModel('SpaLiveV1.DataCertificates');

        $token = get('token',"");

        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $patient_uid = get('patient_uid', '');
        $patient_id = 0;
        if(!empty($patient_uid)){
            $patient_id = $this->SysUsers->uid_to_id($patient_uid);
            if($patient_id <= 0){
                $this->message('Invalid patient.');
                return;
            }
        }
        
        $fields = ['DataConsultation.uid','DataConsultation.payment','DataConsultation.schedule_date','DataCertificates.uid','DataCertificates.date_start','DataCertificates.date_expiration',
                    'DataCertificates.certificate_url'];
        $fields['assistance'] = "(SELECT UP.name FROM sys_users UP WHERE UP.id = DataConsultation.assistance_id)";
        $fields['expirate_soon'] = "(IF(DATEDIFF(NOW(), DataCertificates.date_expiration) < 30,1,0))";
        $fields['treatments'] = '(SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DataConsultation.treatments))';
        $fields['treatments_requested'] = '(SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DataConsultation.treatments_requested))';

        $_where = ['DataConsultation.deleted' => 0];
        $_where['DataConsultation.status'] = "CERTIFICATE";
        // $_where['DataConsultation.treatments <>'] = "";

        if(strtoupper($user['user_role']) != 'examiner'){
            $_where['DataConsultation.patient_id'] = ($patient_id > 0) ? $patient_id : USER_ID;
            // $_where['OR'] = ['DataConsultation.assistance_id' => -1,'DataConsultation.assistance_id' => 0];

            // CHECK IF THE CERTIFICATE IS WAITING FOR THE EXAMINER
            $__where = ['DataConsultation.deleted' => 0];       
            $__where['DataConsultation.status'] = "DONE";
            $__where['DataConsultation.treatments <>'] = "";
            $__where['DataConsultation.patient_id'] = USER_ID;
            
            $waiting_certificate = 
                $this->DataConsultation->find() 
                    ->where($__where)                    
                    ->all();
            $this->set('waiting_certificate', count($waiting_certificate) > 0);
        }else{
            $_where['DataConsultation.assistance_id'] = USER_ID;
            // $fields['treatments'] = '(SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DataConsultation.treatments))';
            // $fields['patient'] = "(SELECT CONCAT_WS(' ', UP.name, UP.lastname) FROM sys_users_patient UP JOIN sys_users U ON UP.user_id = U.id WHERE U.id = DataConsultation.patient_id)";
        }

        $certItem = $this->DataConsultation->find()->select($fields)
        ->join([
            'DataCertificates' => ['table' => 'data_certificates', 'type' => 'LEFT', 'conditions' => 'DataCertificates.consultation_id = DataConsultation.id'],
        ])
        ->where($_where)->order(['DataConsultation.id' => 'DESC'])->all();
        
        $arr_certificates = array();
        if (!empty($certItem)) {
           foreach ($certItem as $row) {
                $arr_certificates[] = array(
                    'consultation_uid' => $row['uid'],
                    'payment' => empty($row['payment']) ? 0 : 1,
                    'certificate_uid' => empty($row['payment']) ? "" : ($row->DataCertificates['uid'] != null ? $row->DataCertificates['uid'] : ""),
                    'date_start' => empty($row->DataCertificates['date_start']) ? $row['schedule_date']->i18nFormat('yyyy-MM-dd') : $row->DataCertificates['date_start'],
                    'date_expiration' => empty($row->DataCertificates['date_expiration']) ? "" : $row->DataCertificates['date_expiration'],
                    'assistance_name' => isset($row['assistance']) ? $row['assistance'] : '',
                    'expirate_soon' => false, //isset($row['expirate_soon']) ? ($row['expirate_soon'] == 1 ? true : false) : '',
                    'treatments' => isset($row["treatments"]) ? $row["treatments"] : $row["treatments_requested"],
                    'show_certificate' => isset($row["treatments"]) ? true : false,
                    'certificate_url' => empty($row->DataCertificates['certificate_url']) ? "" : $row->DataCertificates['certificate_url'],
                );
            }
                
            
            $this->set('data', $arr_certificates);
            $this->success();
        }

    }

    public function are_treatments_allowed(){        
        $this->loadModel('SpaLiveV1.CatTreatmentsCi');
        $this->loadModel('SpaLiveV1.SysUsers');
        $token = get('token', '');
        
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $treatments = json_decode(get('treatments', '[]'), true);        

        if(empty($treatments)){
            $this->message('Treatments empty.');
            return;
        }
        
        $array_treatmets = array();
        foreach($treatments as $id){
            
            $ent_treatment = $this->CatTreatmentsCi->find()->select(['CatTreatmentsCi.name', 'CT.name', 'DCP.id'])
            ->join([
                'CT' => ['table' => 'cat_treatments', 'type' => 'INNER', 'conditions' => 'CatTreatmentsCi.treatment_id = CT.id'],
                'DC' => ['table' => 'data_consultation', 'type' => 'LEFT', 'conditions' => 'DC.deleted = 0 AND DC.status = "CERTIFICATE" AND DC.patient_id = ' . USER_ID],
                'DCE' => ['table' => 'data_certificates', 'type' => 'LEFT', 'conditions' => 'DCE.consultation_id = DC.id AND DCE.deleted = 0 AND DCE.date_expiration > ' . date('Y-m-d')],
                'DCP' => ['table' => 'data_consultation_plan', 'type' => 'LEFT', 'conditions' => 'DCP.treatment_id = CatTreatmentsCi.treatment_id AND DCP.proceed = 1 AND DCP.deleted = 0 AND DCP.consultation_id = DC.id']
            ])->where(['CatTreatmentsCi.id' => $id])->first();
            
            if(!empty($ent_treatment)){
                if($ent_treatment['DCP']['id'] == null || empty($ent_treatment['DCP']['id'])){
                    $array_treatmets[] = $ent_treatment['CT']['name'];
                }
            }
        }

        $payment = $this->GetPaymentMethods();

        if(Count($array_treatmets) > 0){
            $this->set('treatments', array_unique($array_treatmets));
            $this->set('require_gfe', true);
            $this->set('has_payment_method', $payment);
            $this->success();
            return;
        }
        
        $this->set('treatments', []);
        $this->set('require_gfe', false);
        $this->set('has_payment_method', $payment);
        $this->success();                
    }

    private function GetPaymentMethods(){
        $this->loadModel('SpaLiveV1.DataSubscriptionMethodPayments');

        $methods_array = array();
        $preferred_method = '';
        $entMethod = $this->DataSubscriptionMethodPayments->find()->where(['DataSubscriptionMethodPayments.user_id' => USER_ID, 'DataSubscriptionMethodPayments.deleted' => 0])->first();
        if (!empty($entMethod)) {
            $preferred_method = $entMethod->payment_id;
        }

        $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));
        
        $oldCustomer = $stripe->customers->all([
            "email" => USER_EMAIL,
            "limit" => 1,
        ]);

        if (count($oldCustomer) > 0) {
           $customer = $oldCustomer->data[0];  

           $payment_methods = $stripe->customers->allPaymentMethods(
                $customer->id,
                ['type' => 'card']
            );
           
            if (empty($entMethod) && count($payment_methods) > 0) {

                 $array_save = array(
                    'user_id' => USER_ID,
                    'payment_id' => $payment_methods->data[0]->id,
                    'preferred' => 1,
                    'error' => 0,
                    'created' => date('Y-m-d H:i:s')
                );
                $preferred_method = $payment_methods->data[0]->id;
                $c_entity = $this->DataSubscriptionMethodPayments->newEntity($array_save);
                if(!$c_entity->hasErrors()) $this->DataSubscriptionMethodPayments->save($c_entity);
            }

            $preferred_ = false;
            foreach($payment_methods as $method) {
                $preferred = $method->id == $preferred_method ? 1 : 0;
                if($preferred == 1) $preferred_ = true;
                $methods_array[] = array(
                    'id' => $method->id,
                    'name' => strtoupper($method->card->brand) . ' ' . 'XXXX' . $method->card->last4,
                    'preferred' => $preferred,
                    'error' => $preferred == 1 && !empty($entMethod) ? $entMethod->error : 0
                ); 
            } 
            
            if (!$preferred_ && !empty($entMethod)) {

                $entMethod->payment_id = $payment_methods->data[0]->id;
                $entMethod->error = 0;
                $entMethod->created = date('Y-m-d H:i:s');
                $entMethod->deleted = 0;
                $entMethod->preferred = 1;
                $this->DataSubscriptionMethodPayments->save($entMethod);

                $methods_array[0]['preferred'] = 1;
            }
        }

        if(Count($methods_array) > 0){
            return true;
        }else{
            return false;
        }
    }

    public function detail_treatment() {
        $this->loadModel('SpaLiveV1.DataTreatmentImage');
        $this->loadModel('SpaLiveV1.DataTreatmentNotes');

        $token = get('token', '');
        
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        if (USER_TYPE != "injector" && USER_TYPE != "gfe+ci") {
            $this->message('Invalid user type.');    
            return;
        }

        $this->loadModel('SpaLiveV1.DataTreatment');

        $treatment_uid = get('uid', '');
        if(empty($treatment_uid)){
            $this->message('uid empty.');
            return;
        }

        $ent_treatment = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid])->first();
        
        if(empty($ent_treatment)){
            $this->message('Invalid treatment');
            return;
        }

        $this->loadModel('SpaLiveV1.DataTreatment');

        $fields = ['DataTreatment.id','DataTreatment.uid','DataTreatment.schedule_date','DataTreatment.status','DataTreatment.address','DataTreatment.treatments','DataTreatment.state','DataTreatment.patient_id'];
        
        $_where = ['DataTreatment.uid' => $treatment_uid];
    
        $certTreatment = $this->DataTreatment->find()->select($fields)->where($_where)->order(['DataTreatment.schedule_date' => 'ASC'])->first();
                        
        $arr_treatments = array();

        $_patient_id = $certTreatment->patient_id;

        $sign_agreement = false;

        $this->loadModel('SpaLiveV1.Agreement');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.CatAgreements');

        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $_patient_id])->first();
        
        $userType = $ent_user->type == 'gfe+ci' ? 'examiner' : $ent_user->type;
    
        $ent_agreement = $this->CatAgreements->find()->select(['CatAgreements.uid','DataAgreement.id','DataAgreement.deleted'])->join([
                'DataAgreement' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'CatAgreements.uid = DataAgreement.agreement_uid AND DataAgreement.user_id = ' . $_patient_id . ' AND DataAgreement.deleted = 0'],
            ])->where(
            [
                'CatAgreements.state_id' => $ent_user->state,
                'CatAgreements.user_type' => $userType,
                'CatAgreements.agreement_type' => 'REGISTRATION',
                'CatAgreements.deleted' => 0,
            ]
        )->first();
        

        $ivtherapy = false;
        if (!empty($ent_agreement) && empty($ent_agreement['DataAgreement']['id'])) {
            $sign_agreement = true;
            //check if signs iv Therapy
            $ent_agreement = $this->CatAgreements->find()->select(['CatAgreements.uid','DataAgreement.id','DataAgreement.deleted'])->join([
                'DataAgreement' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'CatAgreements.uid = DataAgreement.agreement_uid AND DataAgreement.user_id = ' . $_patient_id . ' AND DataAgreement.deleted = 0'],
            ])->where(
            [
                'CatAgreements.state_id' => $ent_user->state,
                'CatAgreements.user_type' => $userType,
                'CatAgreements.agreement_type' => 'IVTHERAPHY',
                'CatAgreements.deleted' => 0,
            ]
            )->first();

            if (!empty($ent_agreement) && !empty($ent_agreement['DataAgreement']['id'])) {
                $sign_agreement = false;
                $ivtherapy = true;
                
                $this->success();
            }
            //
        }

        /*if($sign_agreement){
            $this->message('The patient needs to have signed consent');
            $this->set('block', true);
            return;
        }*/

        $str_query_ = "
            SELECT 
                GROUP_CONCAT(DISTINCT DTC.type_trmt) type_catego
            FROM data_consultation DC
            JOIN data_consultation_plan DCP ON DCP.consultation_id = DC.id
            JOIN cat_treatments DTC ON DTC.id = DCP.treatment_id
            WHERE DC.patient_id = {$_patient_id} AND DCP.proceed = 1";

        $list_patient = $this->DataTreatment->getConnection()->execute($str_query_)->fetchAll('assoc');
        //if iv therapy put the categories
        $str_treatments_type = '';
        if($ivtherapy == false){
            $str_treatments_type = isset($list_patient[0]['type_catego']) ? $list_patient[0]['type_catego'] : '';
        } else {
            $str_treatments_type = 'NEUROTOXINS, FILLERS, IV THERAPY';
        }
        if($str_treatments_type == ''){
            $str_treatments_type = 'NEUROTOXINS, FILLERS, IV THERAPY';
        }
        //
        $this->set('ent_agreement', $ent_agreement);
        $this->set('ivtherapy', $list_patient);
        $this->set('str_treatments_type', $str_treatments_type);
        $data_tr = array();

        $array_categories = array(
            'NEUROTOXINS BASIC' => 'Basic Neurotoxins',
            'NEUROTOXINS ADVANCED' => 'Advanced Neurotoxins',
            'IV THERAPY' => 'IV Therapy',
            'FILLERS' => 'Fillers',
        );

        if($ent_treatment->type_uber == 1) {
            $this->loadModel('SpaLiveV1.CatTrainigs');
            $now = date('Y-m-d H:i:s');
            $fields = ['CatTrainigs.neurotoxins', 'CatTrainigs.fillers', 'CatTrainigs.materials','CatTrainigs.flip','CatTrainigs.lift','Training.id'];
            $fields['Advanced'] = "(SELECT DT.id FROM data_trainings DT 
                                JOIN cat_trainings CT ON CT.level = 'LEVEL 2' AND DT.training_id = CT.id AND DATE_FORMAT(CT.scheduled, '%Y-%m-%d 12:00:00') < '" . $now . "' AND CT.deleted = 0 
                                WHERE DT.user_id = " . USER_ID . " AND DT.deleted = 0 LIMIT 1)";
            $trains_user = $this->CatTrainigs->find()->select($fields)
            ->join([
                'Training' => ['table' => 'data_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = Training.training_id AND CatTrainigs.level = "LEVEL 1"'],
            ])->where(['Training.user_id' => USER_ID, 'Training.deleted' => 0, 'CatTrainigs.deleted' => 0])->first();

            if(empty($trains_user['Training']['id'])){
                $this->message('You must have a training to access this section.');
                return;
            }

            $this->loadModel('SpaLiveV1.CatCITreatments');
            $ent_treatments = $this->CatCITreatments->find()->select(['CatCITreatments.id','CatCITreatments.treatment_id','CatCITreatments.name','CatCITreatments.product_id','CatCITreatments.max','CatCITreatments.details','CatCITreatments.min','CatCITreatments.qty','CatCITreatments.std_price','Product.comission_spalive','Exam.name', 'Exam.type_trmt', 'CTC.name'])
            ->where(['CatCITreatments.deleted' => 0,
                    'CatCITreatments.name NOT IN' => ['Let my provider help me decide', 'Let my provider choose'],
                    'CTC.type' => 'NEUROTOXINS BASIC',
                    'CatCITreatments.id >' => 80,
            ])
            ->join([
                'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id'],
                'Exam' => ['table' => 'cat_treatments', 'type' => 'INNER', 'conditions' => 'Exam.id = CatCITreatments.treatment_id'],
                'Product' => ['table' => 'cat_products', 'type' => 'LEFT', 'conditions' => 'Product.id = CatCITreatments.product_id'],
            ])->all();

            if(!empty($ent_treatments)){
                foreach ($ent_treatments as $row) {

                    $t_array = array(
                        'name' => $row['name'],
                        'treatment_id' => $row['id'],
                        'price' => $row['std_price'],
                        'qty' => $row['qty'],
                        'details' => $row['details'],
                        'comission' => intval($row['Product']['comission_spalive']),
                        'certificate' => '',
                        'category' => $row['CTC']['name'],
                    );
                    
                    $data_tr[] = $t_array;
                }
            }


            if(!empty($trains_user['Advanced'])){

                $this->loadModel('SpaLiveV1.CatCITreatments');
                $ent_treatments = $this->CatCITreatments->find()->select(['CatCITreatments.id','CatCITreatments.treatment_id','CatCITreatments.name','CatCITreatments.product_id','CatCITreatments.max','CatCITreatments.details','CatCITreatments.min','CatCITreatments.qty','CatCITreatments.std_price','Product.comission_spalive','Exam.name', 'Exam.type_trmt', 'CTC.name'])
                ->where(['CatCITreatments.deleted' => 0,
                        'CatCITreatments.name NOT IN' => ['Let my provider help me decide', 'Let my provider choose'],
                        'CTC.type' => 'NEUROTOXINS ADVANCED',
                        'CatCITreatments.id >' => 80,
                ])
                ->join([
                    'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id'],
                    'Exam' => ['table' => 'cat_treatments', 'type' => 'INNER', 'conditions' => 'Exam.id = CatCITreatments.treatment_id'],
                    'Product' => ['table' => 'cat_products', 'type' => 'LEFT', 'conditions' => 'Product.id = CatCITreatments.product_id'],
                ])->all();
        
                if(!empty($ent_treatments)){
                    foreach ($ent_treatments as $row) {
        
                        $t_array = array(
                            'name' => $row['name'],
                            'treatment_id' => $row['id'],
                            'price' => $row['std_price'],
                            'qty' => $row['qty'],
                            'details' => $row['details'],
                            'comission' => intval($row['Product']['comission_spalive']),
                            'certificate' => '',
                            'category' => $row['CTC']['name'],
                            
                        );
                        
                        $data_tr[] = $t_array;
                    }
                }
            }
        } else {
            $categories = explode(',', $str_treatments_type);
                
            $_fields = ['DataTreatmentsPrice.alias','DataTreatmentsPrice.price','DataTreatmentsPrice.treatment_id','Treatments.name','CatTreatments.name',
                            'Treatments.qty','Treatments.details','Treatments.category_treatment_id','Treatments.std_price','Product.comission_spalive'];
            $_fields['certificate'] = "(SELECT DC.uid
                    FROM cat_treatments_ci CTC
                    JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                    JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                    JOIN data_consultation DCO ON DCO.id = DC.consultation_id
                    WHERE CTC.id = DataTreatmentsPrice.treatment_id AND DCP.proceed = 1 AND NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                    LIMIT 1)";
            $_fields['category'] = 'CatTreatments.type';
            $this->loadModel('SpaLiveV1.DataTreatmentsPrice');
            $ent_prices = $this->DataTreatmentsPrice->find()->select($_fields)->join([
                'Treatments' => ['table' => 'cat_treatments_ci', 'type' => 'INNER', 'conditions' => 'Treatments.id = DataTreatmentsPrice.treatment_id'],
                'Product' => ['table' => 'cat_products', 'type' => 'INNER', 'conditions' => 'Product.id = Treatments.product_id'],
                'CatTreatments' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CatTreatments.id = Treatments.category_treatment_id']
            ])->where(
                // ['DataTreatmentsPrice.deleted' => 0, 'FIND_IN_SET(DataTreatmentsPrice.treatment_id, "' . $str_treatments_ . '")' ,'DataTreatmentsPrice.user_id' => USER_ID]
                ['DataTreatmentsPrice.deleted' => 0, 'Product.category IN' => $categories ,'DataTreatmentsPrice.user_id' => USER_ID]
            )->all();

            $data_tr = array();
            
            $this->loadModel('SpaLiveV1.DataTreatmentsPrice');

            if (!empty($ent_prices)) {
                foreach ($ent_prices as $row) {
                    if($row['Treatments']['name'] == 'Let my provider choose' || $row['Treatments']['name'] == 'Let my provider help me decide'){ continue; }
                    
                    $product_name = $row['Treatments']['name'];

                    if($row->alias!=""&&$row->alias!=null){
                        $product_name = $row->alias;
                    }

                    $data_tr[] = array(
                        'name' => $product_name,
                        'treatment_id' => intval($row['treatment_id']),
                        'price' => $ent_treatment->type_uber == 1 ? intval($row['Treatments']['std_price']) : intval($row['price']),
                        'qty' => intval($row['Treatments']['qty']),
                        'details' => $row['Treatments']['details'],
                        'comission' => intval($row['Product']['comission_spalive']),
                        'certificate' => !empty($row['certificate']) ? $row['certificate'] : '',
                        'category' => !empty($row['category']) ? $array_categories[$row['category']] : ''
                    );
                } 

                //fix try
                if(empty($data_tr)){
                    $trits = $certTreatment->treatments;
                    $trit_nums = explode(',', $trits);

                    foreach ($trit_nums as $num) {

                        if($num != 999){
                            $this->loadModel('SpaLiveV1.CatTreatmentsCi');
                            $this->loadModel('SpaLiveV1.CatTreatmentsCategory');
                            $this->loadModel('SpaLiveV1.DataTreatmentsPrices');
                            $this->loadModel('SpaLiveV1.CatProducts');
                            $this->loadModel('SpaLiveV1.DataCertificates');
                            $this->loadModel('SpaLiveV1.DataConsultationPlan');

                            $catTrits = $this->CatTreatmentsCi->find()->where( ['CatTreatmentsCi.deleted' => 0, 'CatTreatmentsCi.id' => $num] )->first();
                            $catCatTrit = $this->CatTreatmentsCategory->find()->where( ['CatTreatmentsCategory.deleted' => 0, 'CatTreatmentsCategory.id' => $catTrits->category_treatment_id] )->first();
                            $dataTritPrice = $this->DataTreatmentsPrices->find()->where( ['DataTreatmentsPrices.deleted' => 0, 'DataTreatmentsPrices.user_id' => USER_ID, 'DataTreatmentsPrices.treatment_id' => $num ] )->first();
                            $catProd = $this->CatProducts->find()->where( ['CatProducts.deleted' => 0, 'CatProducts.id' => $catTrits->product_id ] )->first();
                            $dataConPlan = $this->DataConsultationPlan->find()->where( ['DataConsultationPlan.deleted' => 0, 'DataConsultationPlan.treatment_id' => $catTrits->treatment_id ] )->first();
                            $dataCert = $this->DataCertificates->find()->where( ['DataCertificates.deleted' => 0, 'DataCertificates.consultation_id' => $dataConPlan->consultation_id ] )->first();

                            $data_tr[] = array(
                                'name' => $catTrits->name,
                                'treatment_id' => intval($num),
                                'price' => !empty($dataTritPrice) ?$dataTritPrice->price : 0,
                                'qty' => $catTrits->qty,
                                'details' =>  $catTrits->details,
                                'comission' => $catProd->comission_spalive,
                                'certificate' =>  $dataCert->uid,
                                'category' =>  !empty($catCatTrit->type) ? $array_categories[$catCatTrit->type] : ''
                            );
                        }




                    }
                }
                //

                //$this->set('catTrits', $catTrits);
                //$this->set('catCatTrit', $catCatTrit);
                //$this->set('trains', $certTreatment);
                //$this->set('user_id', USER_ID);

            }
                
                    
            
            $this->success();
        }

        // if (!empty($certTreatment) && !empty($str_treatments_)) {
        if (!empty($str_treatments_type)) {

            $afterImages = $this->DataTreatmentImage->find()
                ->select(['DataTreatmentImage.file_id'])
                ->where([
                    'DataTreatmentImage.treatment_id' => $certTreatment->id, 
                    'DataTreatmentImage.typeImage' => 'after', 
                ])->toArray(); 

            $beforeImages = $this->DataTreatmentImage->find()
                ->select(['DataTreatmentImage.file_id'])
                ->where([
                    'DataTreatmentImage.treatment_id' => $certTreatment->id, 
                    'DataTreatmentImage.typeImage' => 'before', 
                ])->toArray(); 

            $notes = $this->DataTreatmentNotes->find()->select(['DataTreatmentNotes.notes'])->where(['DataTreatmentNotes.treatment_id' => $certTreatment->id])->first(); 

            // Definir el orden de las categorías


            
            // Arrays para cada categoría
            $basicNeurotoxins = [];
            $advancedNeurotoxins = [];
            $ivTherapy = [];
            $fillers = [];

            // Iterar sobre el array original y clasificar por categoría
            foreach ($data_tr as $service) {
                switch ($service['category']) {
                    case 'Basic Neurotoxins':
                        $basicNeurotoxins[] = $service;
                        break;
                    case 'Advanced Neurotoxins':
                        $advancedNeurotoxins[] = $service;
                        break;
                    case 'IV Therapy':
                        $ivTherapy[] = $service;
                        break;
                    case 'FILLERS':
                        $fillers[] = $service;
                        break;
                    // Puedes agregar más categorías si es necesario
                    // ...
                }
            }

            // Unir los arrays clasificados en el orden específico que deseas
            $sortedServices = array_merge($basicNeurotoxins, $advancedNeurotoxins, $ivTherapy, $fillers);

            $data_tr = $sortedServices;

            

            $re_array = array(
                'uid' => $certTreatment->uid,
                'schedule_date' => $certTreatment->schedule_date,
                'status' => $certTreatment->status,
                'treatments_detail' => $data_tr,
                'after_images' =>  isset($afterImages) ? Hash::extract($afterImages, '{n}.file_id') : [],
                'before_images' =>  isset($beforeImages) ? Hash::extract($beforeImages, '{n}.file_id') : [],    
                'notes' => !empty($notes) ? $notes->notes : '',
                
            );

            
            $this->set('data', $re_array);
            $this->success();

            $this->loadModel('SpaLiveV1.SysUsers');
            $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $_patient_id])->first();
            if (!empty($ent_user)) {
                $_userid = $ent_user->id;
                $this->set('patient_uid', $ent_user->uid);
                $this->set('patient_name', $ent_user->name . ' ' . $ent_user->lname);
            }
            
            $this->loadModel('SpaLiveV1.CatTreatmentsCi');
            $this->loadModel('SpaLiveV1.CatTreatments');
            $string_treatments = $certTreatment->treatments;
            $string_treatments = str_replace(" ", "", $string_treatments);            
            $arr_treatments = explode(",", $string_treatments);
        }

        return;
    }

    //public function compareByCategory($a, $b) {
    //    $categoryOrder = [
    //        "Basic Neurotoxins",
    //        "Advanced Neurotoxins",
    //        "IV Therapy",
    //        "FILLERS",
    //    ];
    //
    //    $categoryA = array_search($a["category"], $categoryOrder);
    //    $categoryB = array_search($b["category"], $categoryOrder);
    //
    //    return $categoryA - $categoryB;
    //}

    public function get_language() {
        $this->loadModel('SpaLiveV1.DataConsultation');

        $token = get('token',"");

        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $consultation_uid = get('consultation_uid', '');
        if(empty($consultation_uid)){
            $this->message('consultation_uid empty.');
            return;
        }

        $ent_consultation = $this->DataConsultation->find()->where(['DataConsultation.uid' => $consultation_uid])->first();
        if (empty($ent_consultation)) {
            $this->message('Invalid consultation.');
            return;
        }

        $consultation_id = $this->DataConsultation->uid_to_id($consultation_uid);
        if ($consultation_id == 0) {
            $this->message('Invalid consultation.');
            return;
        }

        $this->set('language', $ent_consultation->language);
        $this->success();
        
    }

    public function add_payment_method_treatment(){
        $token = get('token', '');
        
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $treatment_uid = get('treatment_uid', '');

        if(empty($treatment_uid)){
            $this->message('Treatment uid empty.');
            return;
        }

        $payment_method = get('payment_method', '');

        if(empty($payment_method)){
            $this->message('Payment method empty.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataTreatment');

        $ent_treatment = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid])->first();

        if(empty($ent_treatment)){
            $this->message('Treatment empty.');
            return;
        }

        $ent_treatment->payment_method_patient = $payment_method;
        $this->DataTreatment->save($ent_treatment);
        $this->success();
    }

    public function list_gfe_require_process(){
        $token = get('token',"");

        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $type = get('type','');
        $name_key = get('name_key','');
    


        if (!empty($type) && $type == 'OTHER_TREATMENTS' && !empty($name_key)) {
             $this->loadModel('SpaLiveV1.SysTreatmentsOt');
            $other_treatment = $this->SysTreatmentsOt->find()
            ->select(['CT.id'])
             ->join([
                'CT' => ['table' => 'cat_treatments', 'type' => 'INNER', 'conditions' => 'SysTreatmentsOt.id = CT.other_treatment_id AND CT.deleted = 0'],
            ])
            ->where(['SysTreatmentsOt.deleted' => 0, 'SysTreatmentsOt.active' => 1, 'SysTreatmentsOt.name_key' => $name_key])
            ->first();

            if (!empty($other_treatment)) {
                $this->set('treatments', 'other_treatments');
                $this->set('require_gfe', true);
                $this->success();
                return;
            }
        }else if(USER_STATE != 10){
            $this->loadModel('SpaLiveV1.DataTreatment');
            $this->loadModel('SpaLiveV1.CatTreatmentsCi');

            $ent_datatreatments = $this->DataTreatment->find()->where(['DataTreatment.patient_id' => USER_ID, 'DataTreatment.status IN ("PETITION", "REQUEST", "CONFIRM", "STOP")', 'DataTreatment.deleted' => 0])->all();
            $array_treatmets = [];
            if(Count($ent_datatreatments) > 0){
                foreach($ent_datatreatments as $row){
                    $ids = explode(',',$row['treatments']);
                    for ($i=0; $i < Count($ids); $i++) { 
                        $ent_treatment = $this->CatTreatmentsCi->find()->select(['CatTreatmentsCi.name', 'CT.name', 'DCP.id', 'CT.id', 'DCE.id', 'DCE.date_expiration'])
                        ->join([
                            'CT' => ['table' => 'cat_treatments', 'type' => 'INNER', 'conditions' => 'CatTreatmentsCi.treatment_id = CT.id AND CT.deleted = 0'],
                            'DC' => ['table' => 'data_consultation', 'type' => 'LEFT', 'conditions' => 'DC.deleted = 0 AND DC.status = "CERTIFICATE" AND DC.patient_id = ' . USER_ID],
                            'DCE' => ['table' => 'data_certificates', 'type' => 'LEFT', 'conditions' => 'DCE.consultation_id = DC.id AND DCE.deleted = 0 AND DCE.date_expiration > "' . date('Y-m-d') . '"'],
                            'DCP' => ['table' => 'data_consultation_plan', 'type' => 'LEFT', 'conditions' => 'DCP.treatment_id = CatTreatmentsCi.treatment_id AND DCP.proceed = 1 AND DCP.deleted = 0 AND DCP.consultation_id = DC.id']
                        ])->where(['CatTreatmentsCi.id' => $ids[$i]])->first();
                        
                        if(!empty($ent_treatment)){
                            if($ent_treatment['DCP']['id'] == null || empty($ent_treatment['DCP']['id']) || empty($ent_treatment['DCE']['id'])){
                                $value = in_array($ent_treatment['CT']['id'], array_column($array_treatmets, 'id'));

                                if($value){
                                    continue;
                                } 
                                $array_treatmets[] = array(
                                    'id' => $ent_treatment['CT']['id'],
                                    'name' => $ent_treatment['CT']['name'],
                                    'name_spanish' => $ent_treatment['CT']['name'] == 'Botox & other neurotoxins' ? 'Botox y otras neurotoxinas' : $ent_treatment['CT']['name'],
                                );
                            }
                        }
                    }
                }
                if(Count($array_treatmets) > 0){
                    $this->set('treatments', 'neuro_filler');
                    $this->set('require_gfe', true);
                    $this->success();
                    return;
                }else{
                    $this->set('treatments', '');
                    $this->set('require_gfe', false);
                    $this->success();
                    return;
                }
            }
        }else{
            $this->loadModel('SpaLiveV1.DataConsultation');

            $certificates = $this->DataConsultation->find()->select(['treatments' => "CONCAT_WS(',', DataConsultationPlan.treatment_id)"])
            ->join([
                'DataConsultationPlan' => ['table' => 'data_consultation_plan', 'type' => 'INNER', 'conditions' => 'DataConsultationPlan.consultation_id = DataConsultation.id AND DataConsultationPlan.proceed = 1 AND DataConsultationPlan.deleted = 0'],
                'DataCertificates' => ['table' => 'data_certificates', 'type' => 'INNER', 'conditions' => 'DataCertificates.consultation_id = DataConsultation.id AND DataCertificates.deleted = 0 AND DataCertificates.date_expiration > "' . date('Y-m-d') . '"'],
            ])
            ->where(['DataConsultation.patient_id' => USER_ID, 'DataConsultation.status' => 'CERTIFICATE', 'DataConsultation.deleted' => 0])->first();

            if(empty($certificates)){
                $this->set('treatments', 'neuro_iv');
                $this->set('require_gfe', true);
                $this->success();
                return;
            }else if(strpos($certificates['treatments'], '92') !== false || strpos($certificates['treatments'], '93') !== false){
                if(strpos($certificates['treatments'], '35') !== false){
                    $this->set('treatments', '');
                    $this->set('require_gfe', false);
                    $this->success();
                    return;
                }else{
                    $this->set('treatments', 'iv_therapy');
                    $this->set('require_gfe', true);
                    $this->success();
                    return;
                }
            }else{
                $this->set('treatments', 'neuro_filler');
                $this->set('require_gfe', true);
                $this->success();
                return;
            }
        }

        $this->set('treatments', '');
        $this->set('require_gfe', false);
        $this->success();
    }

    public function handle_invitation_patient(){
        $token = get('token',"");

        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }
        $this->loadModel('SpaLiveV1.DataTreatment');

        $treatment_uid = get('treatment_uid', '');
        
        // ANSWER = ACCEPT, CANCEL
        $answer = get('answer', 'CANCEL');
        $cancel_reason = get('cancel_reason', '');
        
        if(empty($treatment_uid)){
            $this->message('Treatment uid empty.');
            return;
        }

        $ent_treatment = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid])->first();

        if(empty($ent_treatment)){
            $this->message('Treatment does not exist.');
            return;
        }

        $this->DataTreatment->updateAll(['status' => $answer, 'cancel_reason' => $cancel_reason], ['DataTreatment.id' => $ent_treatment['id']]);
        if($answer == "CONFIRM"){
            $Main = new MainController();
            $this->loadModel('SpaLiveV1.CatNotifications');
            $ent_notification = $this->CatNotifications->find()->where(['CatNotifications.title' => "APPOINTMENT_PREPARATION"])->first();
            $to = $user['email'];
            if (!empty($ent_notification)) {
                $subject = $ent_notification['subject'];
                $msg_mail = $ent_notification['body'];
                
                $constants = [
                    '[Patient]' => $user['name'] ." " . $user['lname'],
                    
                ];
                foreach($constants as $key => $value){
                        $msg_mail = str_replace($key, $value, $msg_mail);
                }
                $this->set("to",$to);$this->set("subject",$subject);$this->set("msg_mail",$msg_mail);
                $Main->send_email_after_register($to, $subject, $msg_mail);
            }
        }
        $this->success();
    }

    public function check_provider_appointments_on_date(){
        $token = get('token',"");

        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $this->loadModel('SpaLiveV1.DataTreatment');

        $date = get('date', '');
        if(empty($date)){
            $this->message('Date empty.');
            return;
        }

        $ent_treatments = $this->DataTreatment->find('all',
                                                    array(
                                                    'conditions' => array(
                                                        'DataTreatment.schedule_date >=' => date('y-m-d', strtotime($date)),
                                                        'DataTreatment.schedule_date <' => date('y-m-d', strtotime('+1 day', strtotime($date))),
                                                        ),
                                                    )
                                                )
                                                ->select([
                                                    'DataTreatment.schedule_date',
                                                    'SysUsers.name',
                                                    'SysUsers.mname',
                                                    'SysUsers.lname',
                                                ])
                                                ->join(                                                    
                                                        [
                                                            'table' => 'sys_users',
                                                            'alias' => 'SysUsers',
                                                            'type' => 'INNER',
                                                            'conditions' => 'DataTreatment.patient_id = SysUsers.id'
                                                        ]   
                                                )
                                                ->where(['DataTreatment.assistance_id' => USER_ID, 'DataTreatment.status IN ("CONFIRM")', 'DataTreatment.deleted' => 0])
                                                ->toArray();

        if(Count($ent_treatments) > 0){
            $array_treatments = [];
            foreach($ent_treatments as $row){                            
                $array_treatments[] = array(
                    'patient' => $row->SysUsers['mname'] == '' ? $row->SysUsers['name'] . ' ' . $row->SysUsers['lname'] : $row->SysUsers['name'] . ' ' . $row->SysUsers['mname'] . ' ' . $row->SysUsers['lname'],
                    'schedule_date' => $row['schedule_date'],
                );                
            }
            if(Count($array_treatments) > 0){
                $this->set('appointments', $array_treatments);
                $this->success();
                return;
            }
        }

        $this->set('appointments', $ent_treatments);
        $this->success();
    }
    
    public function finish_treatment() {
        $token = get('token', '');
        
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        if (USER_TYPE != "injector" && USER_TYPE != "gfe+ci") {
            $this->message('Invalid user type.');    
            return;
        }

        $notes = get('notes','');

        $strImgIds = get('imgs_id', '');
        $condImgs = "";
        if(!empty($strImgIds)){
            $condImgs = " AND file_id NOT IN({$strImgIds})";
        }

        $this->loadModel('SpaLiveV1.DataTreatment');

        $treatment_uid = get('uid', '');
        if(empty($treatment_uid)){
            $this->message('uid empty.');
            return;
        }

        $ent_treatment = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid])->first();
        if(empty($ent_treatment)){
            $this->message('Invalid treatment');
            return;
        }

        //***

        //promo day
        $PromosDay = new PromosController();
        $promo_code = get('promo_code', '');
        $discount_to_category = 0;

        $promo_day_response = $PromosDay->get_discount_for_treatments($promo_code,$ent_treatment);

        $has_discount = $promo_day_response['has_discount'];
        $discount = $promo_day_response['discount'];
        $discount_type = $promo_day_response['discount_type'];
        $treatments_categories = $promo_day_response['treatments_categories'];
        $discount_text = $promo_day_response['discount_text'];
        $promo_name = $promo_day_response['promo_name'];

        $string_treatments = get('treatments','');
        if (empty($string_treatments)) {
            $this->message('Treatments empty.');
            return;
        }
        $arr_treatments = explode("|", $string_treatments);

        $arr_treatments = array_unique($arr_treatments);

        $this->loadModel('SpaLiveV1.DataTreatmentDetail');
        $this->loadModel('SpaLiveV1.DataTreatmentsPrice');
        $this->loadModel('SpaLiveV1.CatCITreatments');
        $this->loadModel('SpaLiveV1.CatTreatmentsCategory');

        $treatment_prices = "";
        $amount = 0;
        $card_amount = 0;
        $cash_amount = 0;
        if (count($arr_treatments) > 0) {

            $str_query_del = "
                DELETE FROM data_treatment_detail WHERE treatment_id = " . $ent_treatment->id;

            $this->DataTreatmentDetail->getConnection()->execute($str_query_del);

            $str_del_imgs = "DELETE FROM data_treatment_image WHERE treatment_id = " . $ent_treatment->id . $condImgs;
            $this->DataTreatmentDetail->getConnection()->execute($str_del_imgs);
            
            foreach ($arr_treatments as $_treatment) {
                $category_has_discount = false;

                $arr_components = explode(",", $_treatment);
                
                if (count($arr_components) > 2) {

                    $ent_tr = $this->CatCITreatments->find()
                    ->select([
                        'CatCITreatments.id', 
                        'CatCITreatments.std_price',
                        'CatCITreatments.category_treatment_id', 
                        'DTP.price',
                        'CTC.name',
                        'CTC.type',
                        'ST.name_key',
                        'CT.name',
                    ])
                    ->join([
                        'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id'],
                        'CT' => ['table' => 'cat_treatments', 'type' => 'INNER', 'conditions' => 'CT.id = CatCITreatments.treatment_id'],
                        'ST' => ['table' => 'sys_treatments_ot', 'type' => 'LEFT', 'conditions' => 'ST.id = CT.other_treatment_id'],
                        'DTP' => ['table' => 'data_treatments_prices', 'type' => 'LEFT', 'conditions' => 'DTP.treatment_id = CatCITreatments.id AND DTP.deleted = 0 AND DTP.user_id = ' . USER_ID]
                    ])
                    ->where(['CatCITreatments.id' => $arr_components[0], 'CatCITreatments.deleted' => 0])->first();

                    if($ent_treatment->type_uber == 1){
                        //si tiene descuento buscar la categoria para empatarla con treatments_categories del promo day
                        if($has_discount){

                            if($ent_tr['CTC']['type'] == 'OTHER TREATMENTS'){
                                if(!empty($ent_tr['ST']['name_key'])){
                                    if(str_contains($treatments_categories, $ent_tr['ST']['name_key'])){
                                        $category_has_discount = true;
                                    }
                                }
                            }else{
                                if(str_contains($treatments_categories, $ent_tr['CTC']['type'])){
                                    $category_has_discount = true;
                                }
                            }
                        }

                    }else{
                        //si tiene descuento buscar la categoria para empatarla con treatments_categories del promo day
                        if($has_discount && !empty($ent_tr['DTP']['price'])){
                            if($ent_tr['CTC']['type'] == 'OTHER TREATMENTS'){
                                if(!empty($ent_tr['ST']['name_key'])){
                                    if(str_contains($treatments_categories, $ent_tr['ST']['name_key'])){
                                        $category_has_discount = true;
                                    }
                                }
                            }else{
                                if(str_contains($treatments_categories, $ent_tr['CTC']['type'])){
                                    $category_has_discount = true;
                                }
                            }
                        }
                    }

                    if (empty($ent_tr)) {
                        $this->message('Error in treatments.');
                        return;
                    }

                    if($ent_treatment->type_uber == 1){
                        if($ent_tr->category_treatment_id == 1001 || $ent_tr->category_treatment_id == 1002){
                            $price = $ent_tr['DTP']['price'];
                        }else{
                            $price = $ent_tr->std_price;
                        }
                    }else{
                        $price = $ent_tr['DTP']['price'];
                    }

                    if ($arr_components[1] == 0) continue;
                    
                    // Obtener método de pago (CARD o CASH)
                    $payment_method = isset($arr_components[2]) ? strtoupper(trim($arr_components[2])) : 'CARD';
                    if (!in_array($payment_method, ['CARD', 'CASH'])) {
                        $payment_method = 'CARD'; // Default fallback
                    }
                    
                    $treatment_total = $price * abs($arr_components[1]);
                    
                    $save_ar = array(
                        'treatment_id' => $ent_treatment->id,
                        'quantity' => abs($arr_components[1]),
                        'cat_treatment_id' => $arr_components[0],
                        'price' => $price,
                        'total' => $treatment_total,
                        'payment_method' => $payment_method
                    );

                    $c_entity = $this->DataTreatmentDetail->newEntity($save_ar);
                    if(!$c_entity->hasErrors()) {
                        if (!$this->DataTreatmentDetail->save($c_entity)) {
                            $this->message('Error saving treatments.');
                            return;
                        }
                    }

                    $amount += $treatment_total;
                    
                    // Acumular en el subtotal correspondiente
                    if ($payment_method === 'CARD') {
                        $card_amount += $treatment_total;
                    } else {
                        $cash_amount += $treatment_total;
                    }

                    if($category_has_discount){//sumar el total de las categoria para aplicar el descuento
                        $discount_to_category += $treatment_total;
                    }

                }
            }
        }

        $total_without_discount = $amount;
        $card_amount_without_discount = $card_amount;
        $cash_amount_without_discount = $cash_amount;

        if($has_discount&&$discount_to_category>0){
            if($discount_type == "percentage"){
                //obtener el precio de los tratamines que no tienen descuento
                $amount = $amount - $discount_to_category;

                $discount = ($discount_to_category * $discount) / 100;
                $amount = $amount + ($discount_to_category - $discount);
                
                // Aplicar descuento proporcionalmente a CARD y CASH
                if ($discount_to_category > 0) {
                    $card_discount_portion = ($card_amount / $total_without_discount) * $discount;
                    $cash_discount_portion = ($cash_amount / $total_without_discount) * $discount;
                    
                    $card_amount = $card_amount - $card_discount_portion;
                    $cash_amount = $cash_amount - $cash_discount_portion;
                }

            }else{
                //obtener el precio de los tratamines que no tienen descuento
                $amount = $amount - $discount_to_category;

                // Aplicar descuento fijo, pero limitarlo al monto de la categoría
                $discount_to_apply = min($discount, $discount_to_category);
                $amount = $amount + ($discount_to_category - $discount_to_apply);
                
                // Aplicar descuento proporcionalmente a CARD y CASH
                if ($discount_to_category > 0) {
                    $total_discount_amount = $discount_to_apply;
                    $card_discount_portion = ($card_amount / $total_without_discount) * $total_discount_amount;
                    $cash_discount_portion = ($cash_amount / $total_without_discount) * $total_discount_amount;
                    
                    $card_amount = $card_amount - $card_discount_portion;
                    $cash_amount = $cash_amount - $cash_discount_portion;
                }
            }

            //cambiar los labels para la app
            $treatments_categories = $PromosDay->change_category_labels($treatments_categories);

        }

        // Asegurar que los subtotales no sean negativos
        $card_amount = max(0, $card_amount);
        $cash_amount = max(0, $cash_amount);
        
        // Calcular el total antes de aplicar el descuento de primer tratamiento
        $total_before_first_discount = $card_amount + $cash_amount;
        
        // Asegurar que el total mínimo sea 100 centavos (1 dólar)
        $amount = max(100, $total_before_first_discount);
        
        $this->set('first_time_dsct', '');
        $this->set('first_time_dsct_amount', 0);
        $first_treatment_discount = 0;
        if($amount > 20000){
            $result = $this->get_promo_first_treatment($ent_treatment->id);
            if($result){
                // Limitar el descuento de primer tratamiento al monto total disponible
                $first_treatment_discount = min($result['discount'], $amount - 100);
                $this->set('first_time_dsct', $result['message']);
                $this->set('first_time_dsct_amount', $first_treatment_discount);
            }
        }

        $array_save_t = array(
            'id' => $ent_treatment->id,
            'status' => 'DONE',
            'amount' => $amount, // Usar la variable $amount que ya tiene el total correcto
            'tip' => 0,
            'promo_code' => $promo_code != "" ? $promo_code : $promo_name,
            'amount_cash' => $cash_amount,
        );

        $nc_entity = $this->DataTreatment->newEntity($array_save_t);
        if(!$nc_entity->hasErrors()){
            if($this->DataTreatment->save($nc_entity)){
                $this->success();
                $this->set('uid', $ent_treatment->uid);
                $this->set('grand_total', intval(max(100, ($card_amount + $cash_amount) - $first_treatment_discount)));
                $this->set('total',$total_without_discount);
                $this->set('discount', $total_without_discount - ($card_amount + $cash_amount));
                $this->set('discount_text', $discount_text);
                $this->set('categories', $treatments_categories);
                $this->set('promo_code', $promo_name);
                
                // Nuevos subtotales por método de pago
                $this->set('card_subtotal', intval($card_amount));
                $this->set('cash_subtotal', intval($cash_amount));
                $this->set('card_subtotal_without_discount', intval($card_amount_without_discount));
                $this->set('cash_subtotal_without_discount', intval($cash_amount_without_discount));
            }
        }
    }

    public function get_promo_first_treatment($treatment_uid, $delete = false){
        $this->loadModel('SpaLiveV1.DataPromoFirstTreatments');
        $this->loadModel('SpaLiveV1.DataTreatment');
        $ent_treatment = $this->DataTreatment->find()->where(['DataTreatment.id' => $treatment_uid])->first();
        if ($ent_treatment->type_uber == 0 && !$delete) return false;
        $ent_promo = $this->DataPromoFirstTreatments->find()->where(['treatment_id' => $treatment_uid, 'deleted' => 0])->first();

        if(empty($ent_promo)){
            return false;
        }

        if($delete){
            $ent_promo->deleted = 1;
            $this->DataPromoFirstTreatments->save($ent_promo);
        }

        return array('discount' => $ent_promo->amount, 'message' => 'First time treatment dsct:');
    }

    public function finish_treatment_save_notes(){

        $token = get('token', '');
        
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $treatment_uid = get('uid', '');
        if(empty($treatment_uid)){
            $this->message('uid empty.');
            return;
        }
    
        $this->loadModel('SpaLiveV1.DataTreatment');
        $ent_treatment = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid])->first();
        if(empty($ent_treatment)){
            $this->message('Invalid treatment');
            return;
        }
    
        $notes = get('notes', '');
    
        $n_id = 0;
        $this->loadModel('SpaLiveV1.DataTreatmentNotes');
        $ent_notes = $this->DataTreatmentNotes->find()->where(['DataTreatmentNotes.treatment_id' => $ent_treatment->id])->first();
        if (!empty($ent_notes)) {
            $n_id = $ent_notes->id;
        }
        $array_save = array(
            'id' => $n_id,
            'treatment_id' => $ent_treatment->id,
            'notes' => $notes,
        );
    
        $c_entity = $this->DataTreatmentNotes->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->DataTreatmentNotes->save($c_entity)) {
                $this->success();
            }
        }
    }

    public function remove_treatment_home(){
        $token = get('token', '');
        
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $treatment_uid = get('uid', '');
        if(empty($treatment_uid)){
            $this->message('uid empty.');
            return;
        }
    
        $this->loadModel('SpaLiveV1.DataTreatment');
        $ent_treatment = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid])->first();
        if(empty($ent_treatment)){
            $this->message('Invalid treatment');
            return;
        }

        $this->DataTreatment->updateAll(
            ['home' => 0],
            ['uid' =>  $treatment_uid]
        );

        $this->success();
    }

    public function lock_treatment(){
        $token = get('token', '');
        
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $treatment_uid = get('uid', '');
        if(empty($treatment_uid)){
            $this->message('uid empty.');
            return;
        }
    
        $this->loadModel('SpaLiveV1.DataTreatment');
        $ent_treatment = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid])->first();
        if(empty($ent_treatment)){
            $this->message('Invalid treatment');
            return;
        }
        $promo_code = get('promo_code', '');

        if(empty($promo_code)&&$ent_treatment->promo_code != ''&&$ent_treatment->promo_code != null){
            $promo_code = $ent_treatment->promo_code;
        }

        //check promo day
        $PromosDay = new PromosController();
        $promo_day_response = $PromosDay->get_discount_for_treatments($promo_code,$ent_treatment);

        //el amount para pay day promos se actualiza en la Treatments____finish_treatment
        $total_amount = $ent_treatment->amount;
        
        if($promo_day_response['has_discount'] == false){//si no tiene promoción actualizarlo aqui
            
            $Payment = new PaymentsController();

            $total_amount = $Payment->validateCode($promo_code,$ent_treatment->amount,'TREATMENT', $ent_treatment);

            if ($total_amount < 100) $total_amount = 100;
        }

        $this->DataTreatment->updateAll(
            ['request_payment' => 1, 'promo_code' =>  $promo_code],
            ['uid' =>  $treatment_uid]
        );
        $this->set('data', $this->get_details_lock($ent_treatment));
        $this->success();
    }    

    public function check_lock(){
        $token = get('token', '');
        
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $treatment_uid = get('uid', '');
        if(empty($treatment_uid)){
            $this->message('uid empty.');
            return;
        }
    
        $this->loadModel('SpaLiveV1.DataTreatment');
        $ent_treatment = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid])->first();
        if(empty($ent_treatment)){
            $this->message('Invalid treatment');
            return;
        }

        if($ent_treatment->request_payment == 0){
            $this->set('is_locked', false);            
        }else{
            $this->set('is_locked', true);
            $this->set('data', $this->get_details_lock($ent_treatment));
        }        
        $this->success();
    }    

    private function get_details_lock($ent_treatment){
        $details = array();
        $details['uid'] = $ent_treatment->uid;
        
        $details['promo_code'] = isset($ent_treatment->promo_code) ? $ent_treatment->promo_code : '';        
        $details['total'] = $ent_treatment->amount;
        $details['total_with_discount'] = $ent_treatment->amount;   

        if($details['promo_code'] != ''){
            $Payments = new PaymentsController();
            $total_with_discount = $Payments->validateCodeOutside($ent_treatment->promo_code,$ent_treatment->amount,'TREATMENT', $ent_treatment);
            $details['total_with_discount'] = $total_with_discount;
        }
        $this->loadModel('SpaLiveV1.DataTreatmentDetail');
        $treatments = $this->DataTreatmentDetail->find()->select(['DataTreatmentDetail.price','DataTreatmentDetail.quantity','DataTreatmentDetail.total', 'product_name' => 'CatTreat.name', 'product_detail' => 'CatTreat.details'])
                    ->join(['CatTreat' => ['table' => 'cat_treatments_ci','type' => 'INNER','conditions' => 'CatTreat.id = DataTreatmentDetail.cat_treatment_id']])
                    ->where(['DataTreatmentDetail.treatment_id' => $ent_treatment->id])->toArray();
        $details['treatments'] = $treatments;       
        return $details;
    }

    public function start_treatment_draft(){
        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.DataConsultationAnswers');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.SysUserAdmin');
        $Main = new MainController();
        
        $token = get('token', '');
        
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }
        
        $createdby = USER_ID;
        $patient_id = USER_ID;
        $assistance_id = 0;
        $injector_id =0;
        //$injector_id = $this->SysUsers->uid_to_id(get('injector_uid', ''));
        //if($injector_id >= 0){
        //    $assistance_id = $injector_id;
        //}

        $patient_uid = $this->SysUsers->uid_to_id(get('patient_uid', ''));
        //if($patient_uid >= 0){
        //    $patient_id = $patient_uid;
        //}

        /**********************/

        $string_treatments = get('treatments','');
        $string_treatments = str_replace(" ", "", $string_treatments);
        
        if (empty($string_treatments)) {
            $this->message('Treatments empty.');
            return;
        }
        $arr_treatments = explode(",", $string_treatments);
        
        $this->loadModel('SpaLiveV1.DataTreatmentsPrice');

        $schedule_by = get('schedule_by',USER_ID);
        $date = \DateTime::createFromFormat('Y-m-d H:i:s', get('schedule_date',''));
        
        if (empty($date)) {
             $this->message('Invalid date.');
            return;
        }

        $status = 'DRAFT';//get('status','PETITION');
        /*if($status == 'REQUEST'){
            $assistance_id = get('provider_uid', 0);
            if($assistance_id === 0){
                $this->message('Not Provider Uid provided.');
                return;
            }
            $cpsEnt = $this->SysUsers->find()->select(['SysUsers.id','SysUsers.uid'])->where(['SysUsers.uid' => $assistance_id])->first();

            if(is_null($cpsEnt)){
                $this->message('Provider doesn\'t exist');
                return;
            }
            
            $assistance_id = $cpsEnt['id'];
        }*/

        $amount = 0;
        if (count($arr_treatments) > 0 && $assistance_id > 0) {
            foreach ($arr_treatments as $key => $value) {
                $ent_treat_price = $this->DataTreatmentsPrice->find()
                ->where(['DataTreatmentsPrice.treatment_id' => $value, 'DataTreatmentsPrice.user_id' => $assistance_id, 'DataTreatmentsPrice.deleted' => 0])
                ->first();

                if(empty($ent_treat_price)){
                    $this->message('One of these treatments is not provided by the user.');
                    return;
                }
            }
        }
        
        //if ($createdby == $assistance_id) {
        //    $status = 'CONFIRM';
        //}

        $schedule_date = get('schedule_date','');
        
        $treatment_uid = Text::uuid();
        
        //$assigned_doctor = rand(0,1) == 0 ? 'Dr Zach Cannon' : 'Dr Doohi Lee';
        $assigned_doctor = $this->SysUserAdmin->getRandomDoctor($assistance_id);
        

        if (empty($schedule_date)) 
            $schedule_date = date('Y-m-d H:i:s');

         $array_save = array(
            'uid' => $treatment_uid,
            'notes' => get('notes',''),
            'patient_id' => $patient_id,
            'assistance_id' => $assistance_id,
            'treatments' => $string_treatments,
            // 'treatments_prices' => $treatment_prices,
            'amount' => intval($amount),
            'address' => get('address',''),
            'suite' => get('suite',''),
            'zip' => get('zip',''),
            'city' => get('city',''),
            'state' => get('state',43),
            'schedule_date' => get('schedule_date',''),
            'status' => $status,
            'deleted' => 0,
            'created' => date('Y-m-d H:i:s'),
            'schedule_by' => $createdby,
            'createdby' => $createdby,
            'assigned_doctor' => $assigned_doctor,
            'type_uber' => 1,
            );
            $this->message('array_save '. json_encode($_POST));        
        // GETTING COORDINATES

        $this->loadModel('SpaLiveV1.CatStates');
        $obj_state = $this->CatStates->find()->select(['CatStates.name'])->where(['CatStates.id' => get('state',43)])->first();
                    
        $chain =  get('address','') . ' ' . get('city','') . ' ' . get('zip','') . ' ,' . $obj_state->name;

        $coordinates = $Main->validate_coordinates($chain, get('zip',''));
        $array_save['latitude']   = $coordinates['latitude'];
        $array_save['longitude']  = $coordinates['longitude'];

        //******

        //$c_entity = $this->DataTreatment->newEntity($array_save);
        /*if(!$c_entity->hasErrors()) {
            if ($this->DataTreatment->save($c_entity)) {
                $this->loadModel('SpaLiveV1.DataTrainings');
                $this->loadModel('SpaLiveV1.CatCITreatments');
                $users_array = array();
                $this->set('uid', $treatment_uid);
                $this->set('grand_total', intval($amount));
                $fields = ['SysUsers.id', 'SysUsers.radius'];
                $fields['distance_in_mi'] = "(69.09 * DEGREES(ACOS(LEAST(1.0, COS(RADIANS(".$array_save['latitude']."))
                    * COS(RADIANS(SysUsers.latitude))
                    * COS(RADIANS(".$array_save['longitude']." - SysUsers.longitude))
                    + SIN(RADIANS(".$array_save['latitude']."))
                    * SIN(RADIANS(SysUsers.latitude))))))";
                $fields['subscriptions'] = "(SELECT COUNT(DS.id) FROM data_subscriptions DS WHERE DS.user_id = SysUsers.id AND DS.deleted = 0 AND DS.status = 'ACTIVE' AND DS.subscription_type IN ('SUBSCRIPTIONMD', 'SUBSCRIPTIONMSL') )";
                $now = date('Y-m-d H:i:s');
                $ent_user = $this->SysUsers->find()->select($fields)->where(['SysUsers.deleted' => 0, 'SysUsers.login_status' => 'READY','SysUsers.type' => 'injector','SysUsers.active' => 1,'SysUsers.steps' => 'HOME'])->all();
                foreach ($ent_user as $row) {*/
                    // Validacion distancia
                    /*if(env('IS_DEV', false) == false){
                        if($row['distance_in_mi'] > $row['radius']) continue;
                    }*/
                    // Validacion subscriptions
                    //if($row['subscriptions'] < 2) continue;
                    
                    // Validacion tratamientos avanzados
                    /*$user_training_advanced= $this->DataTrainings->find()->join([
                        'CatTrainigs' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainings.training_id'],
                        ])->where(['CatTrainigs.level' => 'LEVEL 2','DataTrainings.user_id' => $row['id'],'DataTrainings.deleted' => 0, '(DATE_FORMAT(CatTrainigs.scheduled, "%Y-%m-%d 16:00:00") < "'.$now.'")'])->first();
                    $ent_treatments = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.name', 'CatCITreatments.category_treatment_id' , 'CatCITreatments.std_price','CTC.id', 'CTC.name', 'CTC.type_uber'])
                    ->join([
                        'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id'],
                    ])->where(['CatCITreatments.deleted' => 0, 'FIND_IN_SET(CatCITreatments.id, "' . $string_treatments . '")'])->all();
                    foreach ($ent_treatments as $key => $value) {
                        if($value['CTC']['type_uber'] == 'NEUROTOXINS ADVANCED' && empty($user_training_advanced)){
                            continue 2;
                        }
                    }*/
                    //$users_array[] = $row['id'];
                //}
                //if($status == 'PETITION' && Count($users_array) > 0){ //TODO
                    //TODO Cambiar id hardcodeado por $users_array para produccion
                    //TODO cambiar el id
                    //$Main->notify_devices('TREATMENT_AVAILABLE',$users_array,true,true, true, array(), '',array(),true);
                //}
                
                //if($status == 'REQUEST'){
                    //$Main->notify_devices('NEW_TREATMENT_PATIENT',array($assistance_id),true,true, true, array(), '',array(),true);
                //}

                $this->success();
            //}
        //}
    }

    public function sendEmalStopRequest($email, $name, $description, $address, $created, $schedule, $treatment_uid) {

        $to = $email;

        $str_message = '
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
                    <br><br>
                    <!-- START CENTERED WHITE CONTAINER -->
                    <table role="presentation" class="main" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; background: #ffffff; border-radius: 30px;">
                        <!-- START MAIN CONTENT AREA -->
                        <tr>
                            <td class="wrapper" style="font-family: sans-serif; font-size: 14px; box-sizing: border-box; padding: 20px;">
                                <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;">
                                    <tr>
                                        <div style="padding-top: 2vw;">
                                            <center>
                                                <img src="https://panel.myspalive.com/img/MySpaLive-logo-email.png" width="200px"/>
                                            </center><br>
                                        </div>
                                        <div>
                                            <div><p style="font-size: 17px;">We are filtering open requests scheduled for 2 hours or sooner, or requests where the name of an injector is asked (because those should not be open requests), or requests that ask for free units. 
                                                We have received a new request that meets one of those 3 conditions and didn \'t send it to all the injectors who can claim it. You can approve or reject here on this email, or on the admin panel by going to Treatments, choosing "Waiting for approval" and approving or rejecting it. If you approve it, it will be sent to the injectors.</p><br>
                                                Name: ' . $name . ' <br>
                                                Email: ' . $email . ' <br>
                                                Request description: ' . $description . ' <br>
                                                Address: ' . $address . ' <br>
                                                Request time: ' . $created . ' <br>
                                                Scheduled time: ' . $schedule . ' <br>
                                            </div>
                                            <center style="padding-top: 35px;">
                                                <a href="'.$this->URL_API.'?key=225sadfgasd123fgkhijjdsadfg16578g12gg3gh&action=Treatments____validate_treatment&rec=Approve&uid='.$treatment_uid.'" style="background-color: #1D6782; color:white; cursor: pointer; border-color: transparent; margin: 4px 2px; font-size:16px; padding: 9px 26px; text-align: center; text-decoration: none; display: inline-block; border-radius: 42px;">Approve</a>
                                                &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                                                <a href="'.$this->URL_API.'?key=225sadfgasd123fgkhijjdsadfg16578g12gg3gh&action=Treatments____validate_treatment&rec=Reject&uid='.$treatment_uid.'" style="background-color: #EC7063; color:white; cursor: pointer; border-color: transparent; margin: 4px 2px; font-size:16px; padding: 9px 26px; text-align: center; text-decoration: none; display: inline-block; border-radius: 42px;">Reject</a><br><br>
                                            </center>
                                        </div>                                    
                                    </tr>
                                </table>
                            </td>
                        </tr>
                        <!-- END MAIN CONTENT AREA -->
                        <br><br>
                    </table>

                    <!-- START FOOTER -->
                    <div class="footer" style="clear: both; Margin-top: 10px; text-align: center; width: 100%;">
                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;">
                        <tr>
                            <td class="content-block" style="font-family: sans-serif; vertical-align: top; padding-bottom: 10px; padding-top: 10px; font-size: 12px; color: #999999; text-align: center;">
                            <span class="apple-link" style="color: #999999; font-size: 12px; text-align: center;">Visit us at <a href="https://blog.myspalive.com/">MySpaLive</a></span>
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
        $is_dev = env('IS_DEV', false);
        $email = $is_dev ? 'francisco@advantedigital.com' : 'support@myspalive.com';
        $data = array(
            'from'      => 'MySpaLive <noreply@mg.myspalive.com>',
            'to'    => $email,
            'subject'=> 'An open request needs your approval',
            'html' => $str_message,
            //'attachment' => $arr_image_save,
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
        //curl_setopt($curl, CURLOPT_HEADER, true);
       // curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type:multipart/form-data"]);

        $result = curl_exec($curl);
        curl_close($curl);
        $this->success();
    }

    public function validate_treatment(){
        $uid = get('uid', '');
        
        if(empty($uid)){
            $this->message('Uid key.');
            return;
        }
        
        $rec = get('rec', '');
        if(empty($rec)){
            $this->message('Empty rec.');
            return;
        }
        
        $this->loadModel('SpaLiveV1.DataTreatment');

        $ent_validate = $this->DataTreatment->find()->where(['DataTreatment.uid' => $uid, 'DataTreatment.deleted' => 0])->first();

        if(empty($ent_validate)){
            $this->message('Invalid key.');

            echo '
                <!doctype html>
                <html>
                    <head>
                    <meta name="viewport" content="width=device-width">
                    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
                    <title>MySpaLive Message</title>
                    <style>
                    .box-succes {
                        display: block;
                        margin-left: auto;
                        margin-right: auto;
                        width: 50%;
                    }

                    .logo {
                        margin-right: 70%;
                        width: 164px;
                    }

                    .box{
                    margin-top:60px;
                    display:flex;
                    justify-content:space-around;
                    flex-wrap:wrap;
                    }

                    .alert{
                    margin-top:25px;
                    background-color:#fff;
                    font-size:25px;
                    font-family:sans-serif;
                    text-align:center;
                    width:300px;
                    height:100px;
                    padding-top: 150px;
                    position:relative;
                    }

                    .alert::before{
                    width:100px;
                    height:100px;
                    position:absolute;
                    border-radius: 100%;
                    inset: 20px 0px 0px 100px;
                    font-size: 60px;
                    line-height: 100px;
                    border : 5px solid gray;
                    animation-name: reveal;
                    animation-duration: 1.5s;
                    animation-timing-function: ease-in-out;
                    }

                    .alert>.alert-body{
                    opacity:0;
                    animation-name: reveal-message;
                    animation-duration:1s;
                    animation-timing-function: ease-out;
                    animation-delay:1.5s;
                    animation-fill-mode:forwards;
                    }

                    @keyframes reveal-message{
                    from{
                        opacity:0;
                    }
                    to{
                        opacity:1;
                    }
                    }

                    .success{
                    color:#58D68D;
                    }

                    .info{
                    color: #EB984E;
                    }

                    .info::before{
                    content: "!";
                    border : 5px solid #EB984E;
                    }

                    .error{
                    color: #E74C3C;
                    }


                    @keyframes reveal {
                    0%{
                        border: 5px solid transparent;
                        color: transparent;
                        box-shadow: 0px 0px 12px 7px rgba(255,250,250,0.8) inset;
                        transform: rotate(1000deg);
                    }
                    25% {
                        border-top:5px solid gray;
                        color: transparent;
                        box-shadow: 0px 0px 17px 10px rgba(255,250,250,0.8) inset;
                        }
                    50%{
                        border-right: 5px solid gray;
                        border-left : 5px solid gray;
                        color:transparent;
                        box-shadow: 0px 0px 17px 10px rgba(200,200,200,0.8) inset;
                    }
                    75% {
                        border-bottom: 5px solid gray;
                        color:gray;
                        box-shadow: 0px 0px 12px 7px rgba(200,200,200,0.8) inset;
                        }
                    100%{
                        border: 5px solid gray;
                        box-shadow: 0px 0px 12px 7px rgba(200,200,200,0.8) inset;
                    }
                    }
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
                            <br><br>
                            <!-- START CENTERED WHITE CONTAINER -->
                            <table role="presentation" class="main" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; background: #ffffff; border-radius: 30px;">
                                <!-- START MAIN CONTENT AREA -->
                                <tr>
                                <td class="wrapper" style="font-family: sans-serif; font-size: 14px; text-align: center; box-sizing: border-box; padding: 20px;">
                                    <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;">
                                    <tr>
                                        <div style="padding-top: 2vw;">
                                            <center>
                                                <img src="https://panel.myspalive.com/img/MySpaLive-logo-email.png" width="200px"/>
                                            </center>
                                        </div>

                                        <div class="info alert box-succes">
                                            <div class="alert-body">
                                                <span style="font-weight: bold; color: black !important; font-size: 16px;">Important: </span><span style="color: black !important; font-size: 16px;">You have already used this link</span>
                                            </div>
                                        </div>
                                    </tr>
                                    <br><br><br>
                                    </table>
                                </td>
                                </tr>

                            <!-- END MAIN CONTENT AREA -->
                            <br><br>
                            </table>

                            <!-- START FOOTER -->
                            <div class="footer" style="clear: both; Margin-top: 10px; text-align: center; width: 100%;">
                                <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;">
                                <tr>
                                    <td class="content-block" style="font-family: sans-serif; vertical-align: top; padding-bottom: 10px; padding-top: 10px; font-size: 12px; color: #999999; text-align: center;">
                                    <span class="apple-link" style="color: #999999; font-size: 12px; text-align: center;">Visit us at <a  style="color: #1D6782 !important;font-weight: bold !important;" href="https://blog.myspalive.com/">MySpaLive</a></span>
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
            exit;
        }

        if ($rec == 'Approve'){

            if($ent_validate->type_uber == 1){
                $this->DataTreatment->updateAll(
                    ['status' => 'PETITION'],
                    // ['steps' => 'WAITINGSCHOOLAPPROVAL'],
                    ['id' => $ent_validate->id]
                );
            }else{
                $this->DataTreatment->updateAll(
                    ['status' => 'REQUEST'],
                    // ['steps' => 'WAITINGSCHOOLAPPROVAL'],
                    ['id' => $ent_validate->id]
                );
            }

            $validation = '
                <div class="success alert box-succes">
                    <div class="alert-body">
                        You have successfully approved the treatment!
                    </div>
                </div>
            ';
        } else if ($rec == 'Reject'){
            $this->loadModel('SpaLiveV1.SysUsers');

            $this->DataTreatment->updateAll(
                ['status' => 'CANCEL'],
                // ['steps' => 'WAITINGSCHOOLAPPROVAL'],
                ['id' => $ent_validate->id]
            );
            $validation = '
                <div class="error alert box-succes">
                    <div class="alert-body">
                        Has rejected the treatment
                    </div>
                </div>
            ';
        }

        $html = ' 
            <!doctype html>
            <html>
                <head>
                <meta name="viewport" content="width=device-width">
                <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
                <title>MySpaLive Message</title>
                <style>
                .box-succes {
                    display: block;
                    margin-left: auto;
                    margin-right: auto;
                    width: 50%;
                }

                .logo {
                    margin-right: 70%;
                    width: 164px;
                }

                .box{
                margin-top:60px;
                display:flex;
                justify-content:space-around;
                flex-wrap:wrap;
                }

                .alert{
                margin-top:25px;
                background-color:#fff;
                font-size:25px;
                font-family:sans-serif;
                text-align:center;
                width:300px;
                height:100px;
                padding-top: 150px;
                position:relative;
                }

                .alert::before{
                width:100px;
                height:100px;
                position:absolute;
                border-radius: 100%;
                inset: 20px 0px 0px 100px;
                font-size: 60px;
                line-height: 100px;
                border : 5px solid gray;
                animation-name: reveal;
                animation-duration: 1.5s;
                animation-timing-function: ease-in-out;
                }

                .alert>.alert-body{
                opacity:0;
                animation-name: reveal-message;
                animation-duration:1s;
                animation-timing-function: ease-out;
                animation-delay:1.5s;
                animation-fill-mode:forwards;
                }

                @keyframes reveal-message{
                from{
                    opacity:0;
                }
                to{
                    opacity:1;
                }
                }

                .success{
                color:#58D68D;
                }

                .success::before{
                    content: "✓";
                border : 5px solid #58D68D;
                }

                .error{
                color: #E74C3C;
                }

                .error::before{
                content: "✗";
                border : 5px solid #E74C3C;
                }

                @keyframes reveal {
                0%{
                    border: 5px solid transparent;
                    color: transparent;
                    box-shadow: 0px 0px 12px 7px rgba(255,250,250,0.8) inset;
                    transform: rotate(1000deg);
                }
                25% {
                    border-top:5px solid gray;
                    color: transparent;
                    box-shadow: 0px 0px 17px 10px rgba(255,250,250,0.8) inset;
                    }
                50%{
                    border-right: 5px solid gray;
                    border-left : 5px solid gray;
                    color:transparent;
                    box-shadow: 0px 0px 17px 10px rgba(200,200,200,0.8) inset;
                }
                75% {
                    border-bottom: 5px solid gray;
                    color:gray;
                    box-shadow: 0px 0px 12px 7px rgba(200,200,200,0.8) inset;
                    }
                100%{
                    border: 5px solid gray;
                    box-shadow: 0px 0px 12px 7px rgba(200,200,200,0.8) inset;
                }
                }
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
                        <br><br>
                        <!-- START CENTERED WHITE CONTAINER -->
                        <table role="presentation" class="main" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; background: #ffffff; border-radius: 30px;">
                            <!-- START MAIN CONTENT AREA -->
                            <tr>
                            <td class="wrapper" style="font-family: sans-serif; font-size: 14px; text-align: center; box-sizing: border-box; padding: 20px;">
                                <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;">
                                <tr>
                                    <div style="padding-top: 2vw;">
                                        <center>
                                            <img src="https://panel.myspalive.com/img/MySpaLive-logo-email.png" width="200px"/>
                                        </center>
                                    </div>
                                    '.$validation.'
                                </tr>
                                <br><br><br>
                                </table>
                            </td>
                            </tr>

                        <!-- END MAIN CONTENT AREA -->
                        <br><br>
                        </table>

                        <!-- START FOOTER -->
                        <div class="footer" style="clear: both; Margin-top: 10px; text-align: center; width: 100%;">
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;">
                            <tr>
                                <td class="content-block" style="font-family: sans-serif; vertical-align: top; padding-bottom: 10px; padding-top: 10px; font-size: 12px; color: #999999; text-align: center;">
                                <span class="apple-link" style="color: #999999; font-size: 12px; text-align: center;">Visit us at <a  style="color: #1D6782 !important;font-weight: bold !important;" href="https://blog.myspalive.com/">MySpaLive</a></span>
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
            </html>';
        echo $html;exit;
    }

    public function finish_self_treatment()
    {
        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.SysUsers');
        
        $token = get('token', '');
        $id_treats = get('id_treats', '');
        
        
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $appointment_uid = get('appointment_uid', '');

        if(empty($appointment_uid)){
            $this->message('Invalid appointment.');
            return;
        }   

        $appointment = $this->DataTreatment
            ->find()
            ->where([ 'DataTreatment.uid' => $appointment_uid])
            ->first(); 

        if(empty($appointment)){
            $this->message('Invalid appointment.');
            return;
        }

        if(!empty($id_treats)){

            $id_treats_string = str_replace(['[', ']', ' '], '', $id_treats);
            
            $this->DataTreatment->updateAll([
                'status' => 'DONESELFTREATMENT',
                'treatments' => $id_treats_string,
                'payment' => 'ch_',
                'payment_intent' => 'pi_'
            ], [
                'uid' => $appointment_uid
            ]);
            $this->set('ids', 'si');
        }else{
            $this->DataTreatment->updateAll([
                'status' => 'DONESELFTREATMENT',
                'payment' => 'ch_',
                'payment_intent' => 'pi_'
            ], [
                'uid' => $appointment_uid
            ]);
            $this->set('ids', 'no');
        }

        

        $this->success();
    }

    public function save_review() {
        $this->loadModel('SpaLiveV1.SysUsers');
        $token = get('token', '');
        
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

         if (USER_TYPE != "patient") {
            $this->message('Invalid user type.');    
            return;
        }

        $treatment_uid = get('uid', '');
        if(empty($treatment_uid)){
            $this->message('uid empty.');
            return;
        }

        $value = get('value','0');
        $this->loadModel('SpaLiveV1.DataTreatment');
        $ent_treatments = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid])->first();
        if(empty($ent_treatments)){
            $this->message('Treatment not found');
            return;
        }

        $this->loadModel('SpaLiveV1.DataTreatmentReview');
        $int_id = get('id',0);
        if ($int_id == 0) {
            $ent_rev = $this->DataTreatmentReview->find()->where(['DataTreatmentReview.treatment_id' => $ent_treatments->id,'DataTreatmentReview.createdby' => USER_ID])->first();
            if(!empty($ent_rev)){
               $int_id = $ent_rev->id;
            }
        }
        $starts = get('VALUE','');
        if(empty($starts)) $starts =0;        
        $array_save = array(
            'id' => $int_id,
            'treatment_id' => $ent_treatments->id,
            'injector_id' => $ent_treatments->assistance_id,
            'score' => get('score',40),
            'comments' => get('comments','No comments'),
            'like' => $value, 
            'deleted' => 0,
            'createdby' => USER_ID,
            'starts' => $starts,
        );
        
        $c_entity = $this->DataTreatmentReview->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->DataTreatmentReview->save($c_entity)) {
                $this->loadModel('SpaLiveV1.DataFavorites');
                if($value == 'LIKE'){
                    $ent_favorites = $this->DataFavorites->find()->where(['DataFavorites.patient_id' => $ent_treatments->patient_id, 'DataFavorites.injector_id' => $ent_treatments->assistance_id])->first();
                    if(empty($ent_favorites)){
                        $array_favorites = array(
                            'patient_id' => $ent_treatments->patient_id,
                            'injector_id' => $ent_treatments->assistance_id,
                            'deleted' => 0
                        );
                        $c_entityF = $this->DataFavorites->newEntity($array_favorites);
                        if(!$c_entityF->hasErrors()){
                            $this->DataFavorites->save($c_entityF);
                            $this->success();
                        }
                    } else{
                        if($ent_favorites->deleted == 1){
                            $array_favorites = array(
                                'id' => $ent_favorites->id,
                                'patient_id' => $ent_treatments->patient_id,
                                'injector_id' => $ent_treatments->assistance_id,
                                'deleted' => 0
                            );
                            $c_entityF = $this->DataFavorites->newEntity($array_favorites);
                            if(!$c_entityF->hasErrors()){
                                $this->DataFavorites->save($c_entityF);
                                $this->success();
                            }
                        }
                    }
                }else if($value == 'DISLIKE'){
                    $ent_favorites = $this->DataFavorites->find()->where(['DataFavorites.patient_id' => $ent_treatments->patient_id, 'DataFavorites.injector_id' => $ent_treatments->assistance_id])->first();
                    if(!empty($ent_favorites)){
                        if($ent_favorites->deleted == 0){
                            $array_favorites = array(
                                'id' => $ent_favorites->id,
                                'patient_id' => $ent_treatments->patient_id,
                                'injector_id' => $ent_treatments->assistance_id,
                                'deleted' => 1
                            );
                            $c_entityF = $this->DataFavorites->newEntity($array_favorites);
                            if(!$c_entityF->hasErrors()){
                                $this->DataFavorites->save($c_entityF);
                                $this->success();
                            }
                        }
                    }
                }

                $this->loadModel('SpaLiveV1.DataTreatmentReview');
                $ent_rev = $this->DataTreatmentReview->find()->where(['DataTreatmentReview.injector_id' => $ent_treatments->assistance_id,'DataTreatmentReview.deleted' => 0])->all();
                if (!empty($ent_rev)) {

                    $total = 0;
                    $count = 0;
                    foreach($ent_rev as $reg) {
                        $total += $reg['score'];
                        $count++;
                    }

                    $prom = $total/$count;
                    $prom = round( $prom / 5 ) * 5;

                    $existUser = $this->SysUsers->find()->where(['SysUsers.id' => $ent_treatments->assistance_id, 'SysUsers.deleted' => 0])->first();
                    if (!empty($existUser)) {
                        $existUser->score = $prom;
                        $this->SysUsers->save($existUser);
                    }
                }
            }
        }
    }

    public function save_concent_share_images() {
        $this->loadModel('SpaLiveV1.SysUsers');
        $token = get('token', '');
        
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

         if (USER_TYPE != "patient") {
            $this->message('Invalid user type.');    
            return;
        }

        $treatment_uid = get('uid', '');
        if(empty($treatment_uid)){
            $this->message('uid empty.');
            return;
        }
        
        $this->loadModel('SpaLiveV1.DataTreatment');
        $ent_treatments = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid])->first();
        if(empty($ent_treatments)){
            $this->message('Treatment not found');
            return;
        }
                
        $concent_share_images = get('concent_share_images', '');        
        if($concent_share_images=="0"){                     
            $this->success();
            return;
        }
        if(empty($concent_share_images)){
            $this->message('concent value empty.');            
            return;
        }else{            
            if($concent_share_images =="1"){                
                $this->DataTreatment->updateAll(
                    ['concent_share_images' => $concent_share_images],
                    ['id' => $ent_treatments->id]
                );       
            }
            $this->success();
            return;
        }
    }        

    public function delete_treatment_image(){
        $token = get('token', '');
        
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        if (USER_TYPE != "injector" && USER_TYPE != "gfe+ci") {
            $this->message('Invalid user type.');    
            return;
        }

        $image_id = get('image_id', 0);    
        if(empty($image_id)){
            $this->message('image_id empty.');
            return;
        }   

        $treatment_uid = get('uid', '');
        
        $this->loadModel('SpaLiveV1.DataTreatment');
        $ent_treatment = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid])->first();
        if(empty($ent_treatment)){
            $this->message('Invalid treatment');
            return;
        }

        $this->loadModel('SpaLiveV1.DataTreatmentDetail');
        $str_del_imgs = "DELETE FROM data_treatment_image WHERE treatment_id = " . $ent_treatment->id . " AND file_id = " . $image_id;
        $this->DataTreatmentDetail->getConnection()->execute($str_del_imgs);

        $this->success();               
    }

    public function update_views_request(){
        $token = get('token', '');
        
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }
        // PENDING TREATMENTS ACCEPT ******************
        $this->loadModel('SpaLiveV1.DataClaimTreatments');
        $this->loadModel('SpaLiveV1.CatCITreatments');
        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.SysUsers');

        $now = date('Y-m-d H:i:s');

        $extra_fields = ['DataTreatment.id','DataTreatment.uid','DataTreatment.schedule_date','DataTreatment.status','DataTreatment.assistance_id','DataTreatment.request_payment','DataTreatment.payment','DataTreatment.treatments','DataTreatment.patient_id', 'DataTreatment.deleted', 'State.name', 'State.id','DataTreatment.address', 'DataTreatment.city', 'DataTreatment.zip',  'DataTreatment.suite', 'DataTreatment.created'];
        $extra_fields['treatments_string'] = "(SELECT GROUP_CONCAT(CT.name) FROM cat_treatments_ci CT WHERE FIND_IN_SET(CT.id,DataTreatment.treatments))";
        
        $ent_treatment_pending = $this->DataTreatment->find()->select($extra_fields)
        ->join([
            'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
        ])
        ->where([
            'DataTreatment.patient_id' => USER_ID,
            'DataTreatment.status IN ("PETITION", "STOP")', 
            'DataTreatment.type_uber' => 1, 
            'DataTreatment.request_payment' => 0,
            'DATE_FORMAT(DataTreatment.schedule_date, "%Y-%m-%d %H:%i:%s") >= "' . $now . '"',
            '"' . $now . '" < DATE_ADD(DataTreatment.created,INTERVAL 2 DAY)',
            'DataTreatment.payment' => '', 
            'DataTreatment.deleted' => 0
        ])->all();

        $arr_treatments = array();

        if(Count($ent_treatment_pending) > 0){
            foreach($ent_treatment_pending as $row){
                $arr_injectors = array();
                $entclaim = $this->DataClaimTreatments->find()->where(['DataClaimTreatments.treatment_uid' => $row['uid'], 'DataClaimTreatments.deleted' => 0, '"'. $now . '" < DATE_ADD(DataClaimTreatments.created,INTERVAL 1 DAY)'])->all();
                
                if($now > date('Y-m-d H:i:s', strtotime($row['created']->i18nFormat('yyyy-MM-dd HH:mm:ss') . ' + 2 day')) && count($entclaim) <= 0){
                    continue;
                }

                if(Count($entclaim) > 0){
                    foreach($entclaim as $row2){
                        $created = $row2['created']->i18nFormat('yyyy-MM-dd HH:mm:ss');
                        $date = date('Y-m-d H:i:s', strtotime($created . ' + 1 day'));
                        if($date <= $now) continue; 
                        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $row2['injector_id']])->first();
                        if(!empty($ent_user)){
                            $arr_injectors[] = array(
                                'injector_id' => $ent_user['id'],
                                'injector_uid' => $ent_user['uid'],
                                'name' => $ent_user['name'] . ' ' . $ent_user['lname'],
                            );
                        }
                    }
                }

                $arr_treatments[] = array(
                    'id' => $row['id'],
                    'uid' => $row['uid'],
                    'injectors' => $arr_injectors,
                );
            }

            foreach($arr_treatments as $value){
                $this->DataTreatment->updateAll(
                    ['review_open_home' => 0],
                    ['id' => $value['id']]
                );
            }

            $this->success();
            return;
        }else{
            $this->success();
            return;
        }
    }

    public function generate_pdf_consent(
        $id_user,
        $id_signature
    )
    {   
        $this->loadModel('SpaLiveV1.SysUsers');
        $user = $this->SysUsers->find()->where(['SysUsers.id' => $id_user])->toArray();
        
        $html_bulk = "
            <page>
                <div style='width: 210mm; height: 97mm; position:relative;'>
                    <div style='width:210mm; height: 97mm; position:absolute; z-index: 2;'>
                        <div style='top:30mm;'>
                            <img src='" . $this->URL_API . "img/logo.png' style='width=50mm;'/>
                        </div>
                        <div align='center'><h1><b>Scheduling confirmation</b></h1></div>
                    
                        <div style='position: absolute;left: 12mm;top: 70mm; width: 190mm; background-color: white'>
                            <p>
                                GFE
                                <br>
                                In the state of Texas you are required to have a Good Faith Exam by a mid level professional, authorized by a Medical Director before you can have treatment.
                                <br>
                                The cost of the exam is $39
                            </p>
                        </div>

                        <div style='position: absolute;left: 12mm;top: 85mm; width: 190mm; background-color: white'><div align='center'><p><h4>Total: $39</h4></p></div></div>

                        <div style='position: absolute;left: 12mm;top: 110mm; width: 190mm; background-color: white'>
                            <p>
                                I consent to charge my credit card and store it on my account.
                                <br>
                                If you cancel within 24 hours of the treatment you will be charged a $50 cancelation fee.
                            </p>
                        </div>

                        <div style='position: absolute;left: 12mm;top: 130mm; width: 190mm;'>
                            <span><b>" . $user[0]->name . " " . $user[0]->lname . "</b></span>
                            <br>
                            <span>" . date('Y-m-d H:i:s') . "</span>
                        </div>
                    </div>
                </div>
            </page>";

        
        
        $filename = 'WhatIsAPatientModel' . $id_user . '.pdf';
        $html2pdf = new HTML2PDF('F','A4','en', true, 'UTF-8', array(0,0,0,0));
        $html2pdf->writeHTML($html_bulk);
        $html2pdf->Output($filename, 'I'); 
    }

    public function update_image_treatment() 
    {        
        $token = get('token', '');
        
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }
        
        $img_id = get('image_id', 0);

        $str_name     = $_FILES['file']['name'];
        $new_image_id = $this->Files->upload([
            'name' => $str_name,
            'type' => $_FILES['file']['type'],
            'path' => $_FILES['file']['tmp_name'],
            'size' => $_FILES['file']['size'],
        ]);

        if($new_image_id <= 0){
            $this->message('Error on update image.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataTreatmentImage');

        $data = $this->DataTreatmentImage->updateAll(
            ['file_id' => $new_image_id],
            ['file_id' => $img_id]
        );

        $this->set('new_image_id', $new_image_id);
        $this->success();
    }

    public function get_questions_category() {
        $token = get('token', '');

        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false) {
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        }else{
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $treatments_id = get('treatments_id', '');
        $this->loadModel('SpaLiveV1.DataConsultation');

        if (!empty($treatments_id)) {
            $treatments = explode(',', $treatments_id);

            $this->loadModel('SpaLiveV1.CatTreatments');
            $category_treat = $this->CatTreatments->find()
            ->select(['CatTreatments.id','CatTreatments.type_trmt'])
            ->where([
                //* Coment this line couse is only neurotoxins
                //'CatTreatments.parent_id IN' => $treatments,
                'CatTreatments.parent_id IN' => [1],
                'CatTreatments.deleted' => 0
            ])
            ->all();

            $categories = [];
            foreach ($category_treat as $treat) {
                
                $this->loadModel('SpaLiveV1.CatQuestionsCategory');
                $questionary = $this->CatQuestionsCategory->find()
                ->where([
                    'CatQuestionsCategory.cat_treatment_id' => $treat->id,
                    'CatQuestionsCategory.deleted' => 0
                ])
                ->all();

                $q_by_category = array();
                foreach ($questionary as $value) {
                    $q_by_category[] = array(
                        'id' => $value->id,
                        'question' => $value->question,
                        'type_question' => $value->type_question,
                        'answers' => json_decode($value->answers_json ?? '[]'),
                        'section' => $value->section,
                    );
                }
                $categories[strtolower($treat->type_trmt)] = $q_by_category;
            }

            $this->set('questionary', $categories);
            $this->success();
            return;
        } else {
            $this->message('Treatments empty.');
            return;
        }


    }

    // TREATMENT PAYMENT REVISITED. This new section is for the new changes
    // IV Therapy, Neurotoxins and Fillers are included to this version.
    // So let's go 🔥🔥🔥

    public function get_treatment_summary(){
        $token = get('token', '');

        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false) {
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        }else{
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }
        
        $treatment_uid = get('treatment_uid', '');
        if(empty($treatment_uid)){
            $this->message('Treatment uid empty.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataTreatment');
        $ent_treatment = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid])->first();


        if(empty($ent_treatment)){
            $this->message('Treatment not found.');
            return;
        }

        $TH = new \SpaLiveV1\Controller\Data\TreatmentsHelper($ent_treatment->id);

        $treatment = $TH->treatment;
        $is_locked = $treatment->request_payment == 1;

        if($is_locked){            
            $this->set('lock_data', $this->get_details_lock($ent_treatment));            
        }

        $hide_promo_code = false;

        $array_promo = $this->promos_injector($ent_treatment->assistance_id, $ent_treatment->patient_id);

        if(Count($array_promo) > 0){
            $hide_promo_code = true;
        }

        $this->set('hide_promo_code', $hide_promo_code);
        $this->set('is_locked', $is_locked);
        $this->set('catalog', $TH->treatment_catalog);
        $this->set('categories', $TH->treatment_categories);

        $this->success();
    }

    public function promos_injector($injector_id = 0, $patient_id = 0){
        $this->loadModel('SpaLiveV1.DataPromoDay');
        $now = date('Y-m-d H:i:s');
        $promos = $this->DataPromoDay->find()->select(['DataPromoDay.name', 'DataPromoDay.discount_type', 'DataPromoDay.amount', 'DataPromoDay.categories_id', 'DataPromoDay.start_date', 'DataPromoDay.public' ,'DataPromoDay.end_date', 'patients_id' => 'Patients.patients_id' ])
        ->join([
            'Patients' => [
                'table' => 'data_patients_promo_day',
                'type' => 'INNER',
                'conditions' => 'Patients.promo_id = DataPromoDay.id'
            ],
        ])
        ->where(['DataPromoDay.user_id' => $injector_id, 'DataPromoDay.deleted' => 0, 'DataPromoDay.status' => 'ACTIVE', '(DATE_FORMAT(DataPromoDay.end_date, "%Y-%m-%d %H:%i:%s") > "' . $now . '")'])->all();
    
        $result = array();
        if(Count($promos) > 0){
            foreach($promos as $key => $promo){
                $categories = explode(',', $promo->categories_id);
                $categories_name = array();
                foreach($categories as $category){
                    $this->loadModel('SpaLiveV1.CatTreatmentsPromoDay');
                    $category_name = $this->CatTreatmentsPromoDay->find()->select(['name'])->where(['id' => $category])->first();
                    $categories_name[] = $category_name->name;
                }
                $string_categories = implode(', ', $categories_name);
                
                if($promo->public == 1){
                    $reg2 = $promo->discount_type == 'percentage' ? $promo->amount . '% discount on ' . $string_categories : '$' . $promo->amount . ' discount on ' . $string_categories; 
                    $result[] = array(
                        'reg1' => $promo->name,
                        'reg2' => $reg2,
                        'reg3' => 'From ' . date('m/d/Y', strtotime($promo->start_date->i18nFormat('yyyy-MM-dd HH:mm'))) . ' to ' . date('m/d/Y', strtotime($promo->end_date->i18nFormat('yyyy-MM-dd HH:mm'))),
                    );
                } else{
                    if($patient_id > 0){
                        $ids = explode(',', $promo->patients_id);
                        if(in_array($patient_id, $ids)){
                            $reg2 = $promo->discount_type == 'percentage' ? $promo->amount . '% discount on ' . $string_categories : '$' . $promo->amount . ' discount on ' . $string_categories; 
                            $result[] = array(
                                'reg1' => $promo->name,
                                'reg2' => $reg2,
                                'reg3' => 'From ' . date('m/d/Y', strtotime($promo->start_date->i18nFormat('yyyy-MM-dd HH:mm'))) . ' to ' . date('m/d/Y', strtotime($promo->end_date->i18nFormat('yyyy-MM-dd HH:mm'))),
                            );
                        }
                    }
                }
            }
    
        }
        
        return $result;
    }

    public function separate_treatments($treatments_string,$ids = "",$user_id = ""){
        $this->loadModel('SpaLiveV1.DataTreatmentsPrices');

        if($treatments_string == null || $treatments_string == "" || empty($treatments_string)){
            $response = array(
                'neurotoxins' => '',
                'iv_therapy' => '0',
                'fillers' => '',
            );
    
            return $response;
        }

        $treatments = explode(',', $treatments_string);
        $neurotoxins = "";
        $iv_therapy = "";
        $fillers = "";
        $other_treatments = "";

        $array_ids = explode(',', $ids);
        $id_count = !empty($ids) ? count($array_ids) : 0;
        $count = 0;
        foreach($treatments as $t){

            $downcase = strtolower(trim($t));

            if(strpos($downcase, "no preference") !== false){
                $neurotoxins.= trim($t).", ";
            }else if (strpos($downcase, "neurotoxins") !== false || strpos($downcase, "depressor/dao") !== false || strpos($downcase, "mentalis") !== false || strpos($downcase, "brow lift") !== false || strpos($downcase, "lip flip") !== false) {
                $neurotoxins.= trim($t).", ";
            }else if(strpos($downcase, "iv therapy") !== false || strpos($downcase, "iv") !== false){
                $iv_therapy.= trim($t).", ";
                /*if($id_count>0){
                    
                    $id = $array_ids[$count];

                    //revisar si tiene alias el tratamiento
                    $ent_alias = $this->DataTreatmentsPrices->find()->select(['DataTreatmentsPrices.alias'])
                                                                ->where( ['DataTreatmentsPrices.deleted' => 0, 'DataTreatmentsPrices.user_id' => $user_id, 
                                                                        'DataTreatmentsPrices.treatment_id' => $id ] )->first();

                    if(!empty($ent_alias) && $ent_alias->alias != ""){

                        $array_concat = explode(') ', $t);
                        
                        $iv_therapy.= trim($array_concat[0].") ".$ent_alias->alias)."), ";
                    }else{
                        $iv_therapy.= trim($t).", ";
                    }

                }else{
                    $iv_therapy.= trim($t).", ";
                }*/
            } else if (strpos($downcase, "fillers") !== false) {
                $fillers.= trim($t).", ";
            } else if (strpos($downcase, "other treatments") !== false) {
                $other_treatments.= trim($t).", ";
            }

            $count++;
            
        }

        $neurotoxins = rtrim($neurotoxins, ", ");
        $iv_therapy = rtrim($iv_therapy, ", ");
        $fillers = rtrim($fillers, ", ");
        $other_treatments = rtrim($other_treatments, ", ");

        $response = array(
            'neurotoxins' => $neurotoxins,
            'iv_therapy' => $iv_therapy,
            'fillers' => $fillers,
            'other_treatments' => $other_treatments,
        );

        return $response;
    }

    public function calculate_promo_code($amount,$promo){

        $this->loadModel('SpaLiveV1.DataPromoCodes');
        $this->loadModel('SpaLiveV1.DataGiftCards');

        if($promo!=null&&$promo!=""){

            $promo_code = $this->DataPromoCodes->find()
                            ->where(['DataPromoCodes.code' => $promo, 'DataPromoCodes.deleted' => 0, 'DataPromoCodes.category' => "TREATMENT",
                                    'DataPromoCodes.active' => 1])->first();

            if(!empty($promo_code)){

                $total = 0;
                $discount = 0;

                if($promo_code->type=='PERCENTAGE'){

                    $discount = $promo_code->discount."%";
                    $total = $amount - ($amount * ($promo_code->discount) / 100);

                }else if($promo_code->type=='AMOUNT'){

                    $discount = '$'.number_format((float)$promo_code->discount/100, 2, '.', ',');
                    $total = $amount - $promo_code->discount;

                    if($total < 0){
                        $total = 100;
                    }
                }

                return $response = array(
                    'total' => $total,
                    'discount' => $discount,
                );

            }else{

                $gift_card = $this->DataGiftCards->find()
                            ->where(['DataGiftCards.code' => $promo, 'DataGiftCards.deleted' => 0,
                                    'DataGiftCards.active' => 1])->first();

                if(!empty($gift_card)){

                    $discount = '$'.number_format((float)$gift_card->discount/100, 2, '.', ',');
                    $total = $amount - $gift_card->discount;
                    
                    if($total < 0){
                        $total = 100;
                    }

                    return $response = array(
                        'total' => $total,
                        'discount' => $discount,
                    );

                }else{
                    
                    return $response = array(
                        'total' => $amount,
                        'discount' => '',
                    );

                }

            }
        }else{
            return $response = array(
                'total' => $amount,
                'discount' => '',
            );
        }
    }

    public function get_treatment_gfe(){
        $token = get('token', '');

        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false) {
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        }else{
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $this->loadModel('SpaLiveV1.DataTreatment');

        $fields = ['DataTreatment.id','DataTreatment.uid','DataTreatment.treatments','State.name','DataTreatment.address', 'DataTreatment.city', 'DataTreatment.zip',  'DataTreatment.suite', 'DataTreatment.schedule_date', 'Injector.name', 'Injector.lname', 'Injector.uid'];

        $fields['treatments_string'] = "(SELECT GROUP_CONCAT(CONCAT(CTC.name,' (',CT.name, ')') SEPARATOR ', ') 
                                        FROM cat_treatments_ci CT 
                                        JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
                                        WHERE FIND_IN_SET(CT.id,DataTreatment.treatments) LIMIT 1)";

        $ent_treatment = $this->DataTreatment->find()
        ->select($fields)
        ->join([
            'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
            'Injector' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Injector.id = DataTreatment.assistance_id'],
        ])
        ->where(['DataTreatment.deleted' => 0, 'DataTreatment.status' => 'INVITATION', 'DataTreatment.patient_id' => USER_ID])
        ->first();

        if(empty($ent_treatment)){
            $this->set('treatment', []);
            $this->set('profile', []);
            $this->success();
            return;
        }

        $separate_treatments = $this->separate_treatments($ent_treatment->treatments_string);

        $summary = new SummaryController();

        $full_treatments = "";

        if(!empty($separate_treatments["neurotoxins"])){
            $full_treatments .= $summary->check_training_medical(USER_ID) ? str_ireplace('basic ', '', $separate_treatments["neurotoxins"]) : $separate_treatments["neurotoxins"];
        }

        if(!empty($separate_treatments["iv_therapy"])){
            if(!empty($full_treatments)){
                $full_treatments .= ", ";
            }
            $full_treatments .= $separate_treatments["iv_therapy"];
        }

        if(!empty($separate_treatments["fillers"])){
            if(!empty($full_treatments)){
                $full_treatments .= ", ";
            }
            $full_treatments .= $separate_treatments["fillers"];
        }

        if(!empty($separate_treatments["iv_therapy"]) && empty($separate_treatments["neurotoxins"]) && empty($separate_treatments["fillers"])){
            $this->set('treatment', []);
            $this->set('profile', []);
            $this->success();
            return;
        }

        $array_treatment = array(
            'treatment_id' => $ent_treatment->id,
            'treatment_uid' => $ent_treatment->uid,
            'treatments' => $full_treatments,
            'state' => $ent_treatment['State']['name'],
            'address' => $ent_treatment->address,
            'city' => $ent_treatment->city,
            'zip' => $ent_treatment->zip,
            'suite' => $ent_treatment->suite,
            'schedule_date' => $ent_treatment->schedule_date->i18nFormat('yyyy-MM-dd HH:mm'),
            'injector' => $ent_treatment['Injector']['name'] . ' ' . $ent_treatment['Injector']['lname'],
        );

        $this->set('treatment', $array_treatment);

        $profile = ProviderProfile::get_profile_data($ent_treatment['Injector']['uid']);
        $this->set('profile', $profile);
        $this->success();
    }

    public function verfy_state(){
        $token = get('token', '');

        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false) {
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        }else{
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $injector_uid = get('injector_uid', '');

        $this->loadModel('SpaLiveV1.SysUsers');

        $ent_injector = $this->SysUsers->find()->where(['SysUsers.uid' => $injector_uid])->first();

        if($ent_injector->state == USER_STATE){
            $this->success();
            return;
        }else{
            $this->set('dispatch', 'The injector is not available in your state, please start treatment with another injector.');
            $this->success();
            return;
        }

        $this->success();
    }

    public function treatment_options(){
        $services = array();

        $this->loadModel('SpaLiveV1.DataTreatmentsPrice');
        $this->loadModel('SpaLiveV1.CatTreatmentsCi');

        $is_dev = env('IS_DEV', false);

        // change list 
        $no_preference = $this->CatTreatmentsCi->find()
                ->select([
                    'id',
                    'treatment_id',
                    'name',
                    'product_id',
                    'details',
                    'CTC.name', 
                    'CTC.type',
                    'description'
                ])
                ->join([                
                    'CTC' =>  ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatTreatmentsCi.category_treatment_id'],
                ])
                ->where(['CTC.name' => 'Basic Neurotoxins', 'CatTreatmentsCi.deleted' => 0, 'CatTreatmentsCi.name' => 'Let my provider help me decide'])
                ->order(['CatTreatmentsCi.id' => 'DESC'])->first();
            
        if(!empty($no_preference)){
            array_push($services, 
                array(
                    'id'         => strval($no_preference['id']),
                    'name'       => strval($no_preference['name']),
                    'details'    => strval($no_preference['details']),
                    'exam_id'    => strval($no_preference['treatment_id']),
                    'product_id' => strval($no_preference['product_id']),            
                    'type'       => strval($no_preference['CTC']['type']),
                    'category'   => strval($no_preference['CTC']['name']),
                    'price'      => 0,
                    'description'=> empty($no_preference['description']) ? '' : $no_preference['description'],
                )
            );
        }

        $p_entity = $this->CatTreatmentsCi->find()
        ->select([
            'CatTreatmentsCi.id',
            'CatTreatmentsCi.treatment_id',
            'CatTreatmentsCi.name',
            'CatTreatmentsCi.product_id',
            'CatTreatmentsCi.details',
            'CTC.name', 
            'CTC.type',
            'CatTreatmentsCi.description',
            'CatTreatmentsCi.std_price',
            'CatTreatmentsCi.original_price',
        ])
        ->join([                
            'CTC' =>  ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatTreatmentsCi.category_treatment_id'],
        ])
        ->where(['CatTreatmentsCi.id IN' => array(1,2,4,73,74,75,76,1005,1037,1038,1039,1040,1041,1042,1044,1045,1046,1047,1048,1049,1050,1051,1052,1053,1054,1055,1056,1057,1058,1059,1060,1061,1062,1063,1064,1065,1066,1067), 'CatTreatmentsCi.deleted' => 0])
        ->order([
            new QueryExpression("
                CASE 
                    WHEN CatTreatmentsCi.id = 1050 THEN 1 
                    WHEN CatTreatmentsCi.id = 1051 THEN 2 
                    WHEN CatTreatmentsCi.id = 1060 THEN 3
                    ELSE 4
                END
            ")
        ])
        ->all();
        
        foreach ($p_entity as $row) {
    
            if($row['name'] == 'Let my provider help me decide') { continue; }
    
            $t_array = array(
                'id'         => strval($row['id']),
                'name'       => strval($row['name']),
                'details'    => strval($row['details']),
                'exam_id'    => strval($row['treatment_id']),
                'product_id' => strval($row['product_id']),            
                'type'       => strval($row['CTC']['type']),
                'category'   => strval($row['CTC']['name']),
                'price'      => $row['CTC']['name'] == 'IV Therapy' || $row['CTC']['name'] == 'IV' ? intval(0) : intval($row['std_price']),
                'description'=> strval($row['description']),
                'original_price' => intval($row['original_price']),
            ); 
                                
            $services[] = $t_array;
        }

        $temp_services = $services;

        $categories = array();

        foreach($temp_services as $row){
            if($row['type'] == "NEUROTOXINS BASIC" || $row['type'] == "NEUROTOXINS ADVANCED"){
                $categories[] = "NEUROTOXIN";
            } else if(!in_array($row['type'], $categories)){
                $categories[] = $row['type'];
            }
        }

        $filter_categories = array();

        foreach($categories as $cat){
            $temp_array = array();
            foreach($temp_services as $row){
                if($row['name'] == 'Let my provider help me decide'){
                    continue;
                }
                if($cat == "NEUROTOXIN"){
                    if($row['type'] == "NEUROTOXINS BASIC" || $row['type'] == "NEUROTOXINS ADVANCED"){
                        $temp_array[] = $row;
                    } 
                } else {
                    if($row['type'] == $cat){
                        $temp_array[] = $row;
                    }
                }
                
            }

            $filter_categories[$cat] = $temp_array;
        }

        $fixed_categories = array();

        foreach($filter_categories as $key => $row){
            $fixed_categories[] = array(
                'name' => $key,
                'services' => $row
            );
        }

        /* Other treatments */
        $this->loadModel('SpaLiveV1.SysTreatmentsOt');
        $other_treatments = $this->SysTreatmentsOt->find()
        ->select(['SysTreatmentsOt.name'])
        ->where(['SysTreatmentsOt.deleted' => 0, 'SysTreatmentsOt.treatment_active' => 1, 'SysTreatmentsOt.treatments_group' => 'NONE'])
        ->all()
        ->extract('name')
        ->toArray();

        if(!empty($other_treatments)){
            $ot_treatments = $this->CatTreatmentsCi->find()
            ->select(['CatTreatmentsCi.id', 'CatTreatmentsCi.name', 'CatTreatmentsCi.details', 'CatTreatmentsCi.product_id', 'CatTreatmentsCi.std_price', 'CatTreatmentsCi.original_price', 'CatTreatmentsCi.description'])
            ->where(['CatTreatmentsCi.deleted' => 0, 'CatTreatmentsCi.name IN' => $other_treatments])
            ->all();

            foreach($ot_treatments as $row){
                $array_services = array(
                    'id'         => strval($row['id']),
                    'name'       => strval($row['name']),
                    'details'    => strval($row['details']),
                    'exam_id'    => "0",
                    'product_id' => strval($row['product_id']),
                    'type'       => strval($row['name']),
                    'category'   => strval($row['name']),
                    'price'      => intval($row['std_price']),
                    'description'=> strval($row['description']),
                    'original_price' => intval($row['original_price']),
                );

                $services[] = $array_services;

                $fixed_categories[] = array(
                    'name' => $row->name,
                    'services' => [(object)$array_services]
                );
            }
        }

        /* Other treatments */

        $this->set('services', $services);
        $this->set('categorized_services', $fixed_categories);
        $this->set('has_medical', true);
        $this->set('offer_text', 'Take advantage of your $50 first-time discount by selecting any of these:');
        $this->set('not_offer', 'The discount does not apply to fillers, other neurotoxin treatments, or specific injector requests. It will be applied automatically at checkout for orders over $200.');
        $this->success();
    }

    public function available_days() {

        $mounth = get('mounth', '');
        if(empty($mounth)){
            $mounth = date('Y-m');
        }

        $dateFinal = date('Y-m-t', strtotime($mounth));
        $dInicio = date('Y-m-d', strtotime($mounth));
        $now = date('Y-m-d');

        $date = $dInicio;
        $arrayFechas = [];
        
        while ($date <= $dateFinal) {
            $name_day = date('l', strtotime($date));

            $arrayTimes = [];
            $horaFin="19:30";$horaInicio="08:00";
            if($date == date('Y-m-d', strtotime($now))){
                $hora = date('H:i');
                if($hora < date('H:30')){
                    $horaInicio = date('H:30');
                } else if($hora >= date('H:30')){
                    $horaInicio = date('H:00', strtotime($hora."+ 1 hours"));
                }
                $horaInicio = date('H:i', strtotime($horaInicio."+ 30 minutes")); // Siempre se agrega 30 minutos a la hora inicial
            } else{
                $horaInicio = '08:00';
            }

            $datetime = $date.' '.$horaInicio;
            $datetimeend = $date.' '.$horaFin;;
            
            while($datetime <= $datetimeend){
                $hora = date('h:i A', strtotime($datetime));
                array_push($arrayTimes, array(
                    'time' => $hora,
                    'data' => array(
                        'status' => '',
                        'name' => ' ',
                        'provider' => ' ',
                        'date' => ''
                    )
                ));
                $datetime = date('Y-m-d H:i', strtotime($datetime."+ 30 minutes"));
            }
            
            array_push($arrayFechas, array(
                'date' => $date,
                'dayoff' => false,
                'appointments' => 0,
                'data' => $arrayTimes,
            ));

            $date = date('Y-m-d', strtotime($date."+ 1 days"));
        }
        $this->set('data', $arrayFechas);
        $this->success();
    }

    public function find_provider_by_zip(){

        $zip = get('zip', 0);
        $treatments = get('treatments', '');

        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataTrainings');
        $this->loadModel('SpaLiveV1.CatTrainings');
        $this->loadModel('SpaLiveV1.CatStates');
        //$where  = $this->where_provider_condition;
        /*$number_reviews_q = 'IFNULL((SELECT COUNT(*) FROM data_treatment_reviews WHERE data_treatment_reviews.injector_id = SysUsers.id), 0)';
        $score_q = 'IFNULL((SELECT AVG(data_treatment_reviews.score) FROM data_treatment_reviews WHERE data_treatment_reviews.injector_id = SysUsers.id), 0)';
        $select = [ 
            'uid',
            'number_reviews' => $number_reviews_q,
            'score'          => $score_q,
        ];

        if($zip > 0){
            $where['SysUsers.zip'] = $zip;
        }*/
        //$preorder = $order;
        //$order = $order == 'most_popular' ? 'number_reviews' : 'score';
        
        $gmap_key = "AIzaSyCl7ODqv3YlujjSgquv84s41RhixRCkdqQ";//Configure::read('App.google_maps_key');
        
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode($zip) . '&key=' . $gmap_key; 
        
        $responseData = file_get_contents($url);
        
        $response_json = json_decode($responseData, true);

        if($response_json['status'] != 'OK') {
            $this->message('This service to assign an associate to you is not available in your area. Please choose a Certified Associate to receive your treatment.');
            $this->success(false);
            return;
        }

        if( isset($response_json['results'][0]['address_components']) ){
            
            $administrative_area_level_1 = null;

            foreach ($response_json['results'][0]['address_components'] as $item) {
                if (in_array("administrative_area_level_1", $item['types'])) {
                    $administrative_area_level_1 = $item;
                    break; // Se detiene la búsqueda una vez que se encuentra
                }
            }

            if ($administrative_area_level_1) {
                $state = $administrative_area_level_1['long_name'];
                $abv_state = $administrative_area_level_1['short_name'];
            } else {
                $this->message('This service to assign an associate to you is not available in your area. Please choose a Certified Associate to receive your treatment.');
                $this->success(false);
                return;
            }
        } else {
            $this->message('This service to assign an associate to you is not available in your area. Please choose a Certified Associate to receive your treatment.');
            $this->success(false);
            return;
        }

        $ent_state = $this->CatStates->find()->where(['name' => $state, 'abv' => $abv_state, 'enabled' => 1, 'deleted' => 0])->first();

        if(empty($ent_state) || $ent_state->id != 43){
            $this->message('This service to assign an associate to you is not available in your area. Please choose a Certified Associate to receive your treatment.');
            $this->success(false);
            return;
        }

        $state_id = $ent_state->id;

        $latitude  = isset($response_json['results'][0]['geometry']['location']['lat']) ? $response_json['results'][0]['geometry']['location']['lat'] : "";
        $longitude = isset($response_json['results'][0]['geometry']['location']['lng']) ? $response_json['results'][0]['geometry']['location']['lng'] : "";

        $treatments = explode(',', $treatments);
        $this->loadModel('SpaLiveV1.DataTreatmentsEnabledByState');
        foreach($treatments as $key => $value){
            $ent_treatment = $this->DataTreatmentsEnabledByState->find()->where(['treatment_id' => $value, 'state_id' => $ent_state->id])->first();
            if(empty($ent_treatment)){
                $this->message('This service to assign an associate to you is not available in your area. Please choose a Certified Associate to receive your treatment.');
                $this->success(false);
                return;
            }
        }

        $str_query_1 = "SELECT SysUsers.uid, SysUsers.radius, 
                        69.09 * DEGREES(ACOS(LEAST(1.0, COS(RADIANS({$latitude}))
                            * COS(RADIANS(SysUsers.latitude))
                            * COS(RADIANS({$longitude} - SysUsers.longitude))
                            + SIN(RADIANS({$latitude}))
                            * SIN(RADIANS(SysUsers.latitude))))) AS distance_in_mi
                        FROM sys_users as SysUsers 
                        WHERE SysUsers.type = 'injector' AND
                            SysUsers.steps = 'HOME' AND
                            SysUsers.active = 1 AND
                            SysUsers.deleted = 0 AND
                            SysUsers.state = $state_id AND 
                            SysUsers.name NOT LIKE '%test%'
                        HAVING distance_in_mi < SysUsers.radius";

        $providers_query = $this->SysUsers->getConnection()->execute($str_query_1)->fetchAll('assoc');

        if(empty($providers_query)){
            $this->message('This service to assign an associate to you is not available in your area. Please choose a Certified Associate to receive your treatment.');
            $this->success(false);
            return;
        }

        $this->set('providers', $providers_query);
        $this->success();
        return;

        /*$filter_category_arr = array();
        $has_all = in_array('ALL', $cats);

        // CHECK NEURROTOXINS
        if(in_array('NEUROTOXINS', $cats) || $has_all){            
            $neurotoxins  = 'IFNULL( (SELECT COUNT(*) FROM data_trainings DT JOIN cat_trainings CT ON DT.training_id = CT.id WHERE DT.user_id = SysUsers.id AND DT.deleted = 0 AND CT.level != \'LEVEL IV\') ,0)';
            $neurotoxins1 = 'IFNULL( (SELECT COUNT(*) FROM data_courses DC JOIN cat_courses CC ON DC.course_id = CC.id WHERE DC.user_id = SysUsers.id AND DC.deleted = 0 AND CC.type IN (\'NEUROTOXINS BASIC\', \'NEUROTOXINS ADVANCED\', \'BOTH NEUROTOXINS\') AND DC.status = \'DONE\') ,0)';
            $filter_category_arr[] = "($neurotoxins > 0 OR $neurotoxins1 > 0)";
        }

        // CHECK IV THERAPY

        if(in_array('THERAPY', $cats) || $has_all){            
            $neurotoxins = 'IFNULL( (SELECT COUNT(*) FROM data_trainings DT JOIN cat_trainings CT ON DT.training_id = CT.id WHERE DT.user_id = SysUsers.id AND DT.deleted = 0 AND CT.level = \'LEVEL IV\') ,0)';
            $filter_category_arr[] = "($neurotoxins > 0)";
        }

        // CHECK FILLERS

        if(in_array('FILLERS', $cats) || $has_all){            
            $neurotoxins = 'IFNULL( (SELECT COUNT(*) FROM data_courses DC JOIN cat_courses CC ON DC.course_id = CC.id WHERE DC.user_id = SysUsers.id AND DC.deleted = 0 AND CC.type = \'FILLERS\' AND DC.status = \'DONE\') ,0)';
            $filter_category_arr[] = "($neurotoxins > 0)";
        }

        $filter_category = '( ' . implode(' OR ', $filter_category_arr) . ' )';

        if(!isset($latitude) || !isset($longitude) ){
            return array(
                'total'        => 0,
                'data'         => [],
                'has_more'     => false,
                'has_previous' => false,
            );
        }*/

        $str_query_1 = "SELECT SysUsers.uid, SysUsers.radius, 
                        69.09 * DEGREES(ACOS(LEAST(1.0, COS(RADIANS({$latitude}))
                            * COS(RADIANS(SysUsers.latitude))
                            * COS(RADIANS({$longitude} - SysUsers.longitude))
                            + SIN(RADIANS({$latitude}))
                            * SIN(RADIANS(SysUsers.latitude))))) AS distance_in_mi
                        FROM sys_users as SysUsers 
                        WHERE SysUsers.type = 'injector' AND
                            SysUsers.steps = 'HOME' AND
                            SysUsers.active = 1 AND
                            SysUsers.deleted = 0 AND
                            SysUsers.state = $state_id AND 
                            SysUsers.name NOT LIKE '%test%'
                            HAVING distance_in_mi < 21 ";

        $providers_query = $this->SysUsers->getConnection()->execute($str_query_1)->fetchAll('assoc');

        $provider_profiles = array();                 
        $CatTraining = $this->CatTrainings->find()->select(['CatTrainings.id', 'CatTrainings.title', 'CatTrainings.deleted'])->where(['CatTrainings.title' => 'IV Therapy', 'CatTrainings.deleted' => 0])->first();
        foreach ($providers_query as $key => $provider) {            
            $provider_profile     = ProviderProfile::get_profile_data($provider['uid']);

            $injectorIV = $this->SysUsers->find()->select(['SysUsers.id', 'SysUsers.deleted'])->where(['SysUsers.uid' => $provider['uid'], 'SysUsers.deleted' => 0])->first();
            
            $ivTherapyEnrolled = $this->DataTrainings->find()->select(['DataTrainings.user_id','DataTrainings.training_id','DataTrainings.deleted'])->where(['DataTrainings.user_id' => $injectorIV->id, 'DataTrainings.training_id' => $CatTraining->id, 'DataTrainings.deleted' => 0])->first();

            //if orderer
            //$this->set('tos', $preorder);
            if($preorder == 'IV Therapy'){
                //$this->set('tis', $ivTherapyEnrolled);
                if(!empty($ivTherapyEnrolled)){
                    //$this->set('sus', 'noempty');
                  $provider_profiles[]  = $provider_profile;
                }
            }
            else{
                $provider_profiles[]  = $provider_profile;
            }
          
        } 

        $filteredProviders = array();
        // Filtrar los objetos que cumplen con el criterio
        //$this->set('NEUROTOXINS', in_array('NEUROTOXINS', $cats));
        //$this->set('THERAPY', in_array('THERAPY', $cats));
        //$this->set('hasall', $has_all);
        //$this->set('cond', !in_array('NEUROTOXINS', $cats) || !$has_all);

        if(in_array('THERAPY', $cats)){

            if(!in_array('NEUROTOXINS', $cats) && !$has_all){
                foreach ($provider_profiles as $provider) {
    
                    $hasiv = false;
    
                    if(!empty($provider->services)){
    
                        foreach ($provider->services as $service) {
                            if ($service['id'] >= 1000) {
                                $hasiv = true;
                            }
    
                        }
    
                    }
    
                    if($hasiv){
                        $filteredProviders[]  = $provider;
                    }
                }
    
                $provider_profiles = $filteredProviders;

            } else {
                foreach ($provider_profiles as $provider) {
    
                    $hasiv = false;
    
                    if(!empty($provider->services)){
    
                        foreach ($provider->services as $service) {
                            if ($service['id'] >= 1) {
                                $hasiv = true;
                            }
    
                        }
    
                    }
    
                    if($hasiv){
                        $filteredProviders[]  = $provider;
                    }
                }
    
                $provider_profiles = $filteredProviders;
            }

        }

        
        if($preorder == 'IV Therapy'){
            $total = count($provider_profiles);

        } else {
            $total = count($providers_query_count);
        }

        

        return array(
            'total'        => $total,
            'data'         => $provider_profiles,
            'has_more'     => $total > ($page * $limit) ? true : false,
            'has_previous' => $page > 1 ? true : false,
        );
    } 

    public function find_by_zip(){

        $zip = get('zip', 0);
        $treatments = get('treatments', '');

        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataTrainings');
        $this->loadModel('SpaLiveV1.CatTrainings');
        $this->loadModel('SpaLiveV1.CatStates');
        
        $gmap_key = "AIzaSyCl7ODqv3YlujjSgquv84s41RhixRCkdqQ";//Configure::read('App.google_maps_key');
        
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode($zip) . '&key=' . $gmap_key; 
        
        $responseData = file_get_contents($url);
        
        $response_json = json_decode($responseData, true);

        if($response_json['status'] != 'OK') {
            $this->message('We’re sorry, there aren’t providers available in your area for the selected treatments.');
            $this->success(false);
            return;
        }

        if( isset($response_json['results'][0]['address_components']) ){
            
            $administrative_area_level_1 = null;

            foreach ($response_json['results'][0]['address_components'] as $item) {
                if (in_array("administrative_area_level_1", $item['types'])) {
                    $administrative_area_level_1 = $item;
                    break; // Se detiene la búsqueda una vez que se encuentra
                }
            }

            if ($administrative_area_level_1) {
                $state = $administrative_area_level_1['long_name'];
                $abv_state = $administrative_area_level_1['short_name'];
            } else {
                $this->message('We’re sorry, there aren’t providers available in your area for the selected treatments.');
                $this->success(false);
                return;
            }
        } else {
            $this->message('We’re sorry, there aren’t providers available in your area for the selected treatments.');
            $this->success(false);
            return;
        }

        $ent_state = $this->CatStates->find()->where(['name' => $state, 'abv' => $abv_state, 'enabled' => 1, 'deleted' => 0])->first();

        if(empty($ent_state) || $ent_state->id != 43){
            $this->message('We’re sorry, there aren’t providers available in your area for the selected treatments.');
            $this->success(false);
            return;
        }

        $state_id = $ent_state->id;

        $latitude  = isset($response_json['results'][0]['geometry']['location']['lat']) ? $response_json['results'][0]['geometry']['location']['lat'] : "";
        $longitude = isset($response_json['results'][0]['geometry']['location']['lng']) ? $response_json['results'][0]['geometry']['location']['lng'] : "";

        $str_query_1 = "SELECT SysUsers.uid, SysUsers.radius, 
                        69.09 * DEGREES(ACOS(LEAST(1.0, COS(RADIANS({$latitude}))
                            * COS(RADIANS(SysUsers.latitude))
                            * COS(RADIANS({$longitude} - SysUsers.longitude))
                            + SIN(RADIANS({$latitude}))
                            * SIN(RADIANS(SysUsers.latitude))))) AS distance_in_mi
                        FROM sys_users as SysUsers 
                        WHERE SysUsers.type = 'injector' AND
                            SysUsers.steps = 'HOME' AND
                            SysUsers.active = 1 AND
                            SysUsers.deleted = 0 AND
                            SysUsers.state = $state_id AND 
                            SysUsers.name NOT LIKE '%test%'
                        HAVING distance_in_mi < SysUsers.radius";

        $providers_query = $this->SysUsers->getConnection()->execute($str_query_1)->fetchAll('assoc');

        if(empty($providers_query)){
            $this->message('We’re sorry, there aren’t providers available in your area for the selected treatments.');
            $this->success(false);
            return;
        }

        $this->set('providers', $providers_query);
        $this->success();
        return;
    }

    public function find_by_treatment_zip(){

        $zip = get('zip', 0);
        $treatments = get('treatments', '');

        if(empty($treatments)){
            $this->message('We’re sorry, there aren’t providers available in your area for the selected treatments.');
            $this->set('providers', false);
            $this->message('1');
            $this->success(false);
            return;
        }

        $array_treatments = json_decode($treatments, true);

        if(empty($array_treatments)){
            $this->set('text', 'Sorry, no providers available in your area for this treatment.');
            $this->set('providers', false);
            $this->success();
            return;
        }

        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.CatStates');
        
        $gmap_key = "AIzaSyCl7ODqv3YlujjSgquv84s41RhixRCkdqQ";//Configure::read('App.google_maps_key');
        
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode($zip) . '&key=' . $gmap_key; 
        
        $responseData = file_get_contents($url);
        
        $response_json = json_decode($responseData, true);

        if($response_json['status'] != 'OK') {
            $this->message('We’re sorry, there aren’t providers available in your area for the selected treatments.');
            $this->set('providers', false);
            $this->message('2');
            $this->success(false);
            return;
        }

        if( isset($response_json['results'][0]['address_components']) ){
            
            $administrative_area_level_1 = null;

            foreach ($response_json['results'][0]['address_components'] as $item) {
                if (in_array("administrative_area_level_1", $item['types'])) {
                    $administrative_area_level_1 = $item;
                    break; // Se detiene la búsqueda una vez que se encuentra
                }
            }

            if ($administrative_area_level_1) {
                $state = $administrative_area_level_1['long_name'];
                $abv_state = $administrative_area_level_1['short_name'];
            } else {
                $this->message('We’re sorry, there aren’t providers available in your area for the selected treatments.');
                $this->set('providers', false);
                $this->success(false);
                return;
            }
        } else {
            $this->message('We’re sorry, there aren’t providers available in your area for the selected treatments.');
            $this->set('providers', false);
            $this->success(false);
            return;
        }

        $ent_state = $this->CatStates->find()->where(['name' => $state, 'abv' => $abv_state, 'enabled' => 1, 'deleted' => 0])->first();

        if(empty($ent_state) || $ent_state->id != 43){
            $this->message('We’re sorry, there aren’t providers available in your area for the selected treatments.');
            $this->message('3');
            $this->set('providers', false);
            $this->success(false);
            return;
        }

        $state_id = $ent_state->id;

        $latitude  = isset($response_json['results'][0]['geometry']['location']['lat']) ? $response_json['results'][0]['geometry']['location']['lat'] : "";
        $longitude = isset($response_json['results'][0]['geometry']['location']['lng']) ? $response_json['results'][0]['geometry']['location']['lng'] : "";

        $str_query_1 = "SELECT SysUsers.uid, SysUsers.radius, SysUsers.id, 
                        69.09 * DEGREES(ACOS(LEAST(1.0, COS(RADIANS({$latitude}))
                            * COS(RADIANS(SysUsers.latitude))
                            * COS(RADIANS({$longitude} - SysUsers.longitude))
                            + SIN(RADIANS({$latitude}))
                            * SIN(RADIANS(SysUsers.latitude))))) AS distance_in_mi
                        FROM sys_users as SysUsers 
                        WHERE SysUsers.type = 'injector' AND
                            SysUsers.steps = 'HOME' AND
                            SysUsers.active = 1 AND
                            SysUsers.deleted = 0 AND
                            SysUsers.state = $state_id AND 
                            SysUsers.name NOT LIKE '%test%'
                        HAVING distance_in_mi < SysUsers.radius";

        $providers_query = $this->SysUsers->getConnection()->execute($str_query_1)->fetchAll('assoc');

        if(empty($providers_query)){
            $this->set('text', 'Sorry, no providers available in your area for this treatment.');
            $this->set('providers', false);
            $this->success();
            return;
        }

        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $this->loadModel('SpaLiveV1.DataTrainings');
        $this->loadModel('SpaLiveV1.DataTreatmentsPrice');

        foreach($providers_query as $key => $value){
            if(isset($array_treatments['neurotoxins'])){
                $where = ['user_id' => $value['id'], 'status' => 'ACTIVE', 'deleted' => 0, 'subscription_type LIKE' => '%MD%'];
                $where['OR'] = [['main_service' => 'NEUROTOXINS'], ['addons_services LIKE' => '%NEUROTOXINS%']];
                $ent_sub = $this->DataSubscriptions->find()->where($where)->first();

                if(empty($ent_sub)){
                    unset($providers_query[$key]);
                    continue;
                }

                $ent_level1 = $this->DataTrainings->find()->select(['DataTrainings.id', 'DataTrainings.training_id', 'DataTrainings.attended', 'DataTrainings.deleted'])
                ->join([
                    'CatTrainings' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainings.id = DataTrainings.training_id'],
                ])
                ->where(['DataTrainings.user_id' => $value['id'], 'DataTrainings.attended' => 1, 'DataTrainings.deleted' => 0, 'CatTrainings.level' => 'LEVEL 1'])->first();

                $ent_level2 = $this->DataTrainings->find()->select(['DataTrainings.id', 'DataTrainings.training_id', 'DataTrainings.attended', 'DataTrainings.deleted'])
                ->join([
                    'CatTrainings' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainings.id = DataTrainings.training_id'],
                ])
                ->where(['DataTrainings.user_id' => $value['id'], 'DataTrainings.attended' => 1, 'DataTrainings.deleted' => 0, 'CatTrainings.level' => 'LEVEL 2'])->first();

                $ent_level3 = $this->DataTrainings->find()->select(['DataTrainings.id', 'DataTrainings.training_id', 'DataTrainings.attended', 'DataTrainings.deleted'])
                ->join([
                    'CatTrainings' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainings.id = DataTrainings.training_id'],
                ])
                ->where(['DataTrainings.user_id' => $value['id'], 'DataTrainings.attended' => 1, 'DataTrainings.deleted' => 0, 'CatTrainings.level' => 'LEVEL 3 MEDICAL'])->first();

                if(empty($ent_level1) || empty($ent_level2) || empty($ent_level3)){
                    unset($providers_query[$key]);
                    continue;
                }
            }

            if(isset($array_treatments['fillers'])){
                $where = ['user_id' => $value['id'], 'status' => 'ACTIVE', 'deleted' => 0, 'subscription_type LIKE' => '%MD%'];
                $where['OR'] = [['main_service' => 'FILLERS'], ['addons_services LIKE' => '%FILLERS%']];
                $ent_sub = $this->DataSubscriptions->find()->where($where)->first();

                if(empty($ent_sub)){
                    unset($providers_query[$key]);
                    continue;
                }
            }

            if(isset($array_treatments['iv'])){
                $where = ['user_id' => $value['id'], 'status' => 'ACTIVE', 'deleted' => 0, 'subscription_type LIKE' => '%MD%'];
                $where['OR'] = [['main_service' => 'IV THERAPY'], ['addons_services LIKE' => '%IV THERAPY%']];
                $ent_sub = $this->DataSubscriptions->find()->where($where)->first();

                if(empty($ent_sub)){
                    unset($providers_query[$key]);
                    continue;
                }
                $join = [];
                if($array_treatments['iv'][0] == 0){
                    $join = ['CTC' => ['table' => 'cat_treatments_ci', 'type' => 'INNER', 'conditions' => 'CTC.id = DataTreatmentsPrice.treatment_id']];
                    $_where = ['DataTreatmentsPrice.user_id' => $value['id'], 'DataTreatmentsPrice.deleted' => 0, 'CTC.category_treatment_id' => 1001, 'CTC.deleted' => 0];

                    $treatment_prices = $this->DataTreatmentsPrice->find()
                    ->join($join)
                    ->where($_where)->all();

                    if(count($treatment_prices) == 0){
                        unset($providers_query[$key]);
                        continue;
                    }
                }else{
                    $_where = ['user_id' => $value['id'], 'deleted' => 0, 'treatment_id IN' => $array_treatments['iv']];

                    $treatment_prices = $this->DataTreatmentsPrice->find()
                    ->join($join)
                    ->where($_where)->count();

                    if($treatment_prices < count($array_treatments['iv'])){
                        unset($providers_query[$key]);
                        continue;
                    }
                }


                
            }

            if(isset($array_treatments['others'])){
                // Validaciones automáticas para nuevos tipos de tratamientos
                foreach($array_treatments['others'] as $treatment_id){

                    $this->loadModel('SpaLiveV1.CatCITreatments');

                    $ent_treatments = $this->CatCITreatments->find()
                    ->select(['name_key' => 'ST.name_key'])
                    ->join([
                        'CT' => ['table' => 'cat_treatments', 'type' => 'INNER', 'conditions' => 'CT.id = CatCITreatments.treatment_id'],
                        'ST' => ['table' => 'sys_treatments_ot', 'type' => 'INNER', 'conditions' => 'ST.id = CT.other_treatment_id'],
                    ])
                    ->where(['CatCITreatments.deleted' => 0, 'CatCITreatments.id' => $treatment_id])
                    ->first();
                    
                    if(empty($ent_treatments)){
                        unset($providers_query[$key]);
                        continue 2;
                    }

                    $service_name = $ent_treatments->name_key;
                    
                    // Verificar suscripción automáticamente
                    $where = ['user_id' => $value['id'], 'status' => 'ACTIVE', 'deleted' => 0, 'subscription_type LIKE' => '%MSL%'];
                    $where['OR'] = [['main_service' => $service_name], ['addons_services LIKE' => '%' . $service_name . '%']];
                    
                    $ent_sub = $this->DataSubscriptions->find()->where($where)->first();

                    if(empty($ent_sub)){
                        unset($providers_query[$key]);
                        continue 2; // Salir del bucle interno
                    }

                    // Verificar precios del tratamiento automáticamente
                    $_where = ['user_id' => $value['id'], 'deleted' => 0, 'treatment_id IN' => $treatment_id];

                    $treatment_prices = $this->DataTreatmentsPrice->find()
                    ->where($_where)->count();

                    if($treatment_prices < 1){
                        unset($providers_query[$key]);
                        continue 2;
                    }
                }
            }
        }

        if(count($providers_query) <= 0){
            $this->set('text', 'Sorry, no providers available in your area for this treatment.');
            $this->set('providers', false);
            $this->success();
            return;
        }

        $this->set('text', 'There are ' . count($providers_query) . ' certified providers available for your treatment.');
        $this->set('providers', true);
        $this->set('ganadores', $providers_query);
        $this->success();
        return;
    }

    public function summary_treatment(){
        $token = get('token', '');

        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false) {
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        }else{
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $treatment_uid = get('treatment_uid', '');
        if(empty($treatment_uid)){
            $where = ['DataTreatment.patient_id' => USER_ID, 'DataTreatment.deleted' => 0];
        } else{
            $where = ['DataTreatment.uid' => $treatment_uid];
        }
        $this->loadModel('SpaLiveV1.DataTreatment');
        $select = ['DataTreatment.uid', 'DataTreatment.treatments', 'DataTreatment.state', 'DataTreatment.address', 'DataTreatment.city', 'DataTreatment.zip', 'DataTreatment.assistance_id', 
                   'DataTreatment.suite', 'DataTreatment.type_uber', 'DataTreatment.schedule_date', 'Injector.name', 'Injector.lname', 'Injector.uid', 
                   'Injector.photo_id', 'Injector.gender', 'State.name'];

        $select['treatments_string'] = "(SELECT IF(CTC.id = 1, GROUP_CONCAT(CONCAT(CTC.name,' (',CT.name, ' $',FORMAT(CT.std_price/100, 0), ' ', CT.details, ')') SEPARATOR ', '), GROUP_CONCAT(CONCAT(CT.name,' ( $',FORMAT(CT.std_price/100, 0), ' ', CT.details, ')') SEPARATOR ', ')) 
                                    FROM cat_treatments_ci CT 
                                    JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
                                    WHERE FIND_IN_SET(CT.id,DataTreatment.treatments) LIMIT 1)";

        $ent_treatment = $this->DataTreatment->find()->select($select)
        ->join([
            'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
            'Injector' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'Injector.id = DataTreatment.assistance_id'],
        ])
        ->where($where)->last();

        if(empty($ent_treatment)){
            $this->set('data', []);
            $this->success(false);
            return;
        }

        $array_list = array();
        $array_treatments = explode(',', $ent_treatment->treatments);
        $this->loadModel('SpaLiveV1.CatCITreatments');
        $ent_treatments = $this->CatCITreatments->find()->select(['CatCITreatments.id','CatCITreatments.name','CatCITreatments.details', 'CatCITreatments.std_price', 'Cat.name', 'CatCITreatments.category_treatment_id', 'Price.price'])
        ->join([
            'Cat' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'Cat.id = CatCITreatments.category_treatment_id'],
            'Price' => ['table' => 'data_treatments_prices', 'type' => 'LEFT', 'conditions' => 'Price.treatment_id = CatCITreatments.id AND Price.user_id = ' . $ent_treatment->assistance_id],
        ])
        ->where(['CatCITreatments.id IN' => $array_treatments])->all();

        $ondemando_flow = false;

        /*$this->loadModel('SpaLiveV1.DataPatientOndemand');
        $ent_onde = $this->DataPatientOndemand->find()->where(['user_id' => USER_ID, 'deleted' => 0])->first();*/

        if($ent_treatment->type_uber == 1){
            $ondemando_flow = true;
            foreach($array_treatments as $treat){
                if($treat == 999){
                    $array_list[] = 'Neurotoxins';
                }else if($treat == 1033){
                    $array_list[] = 'Fillers';
                }else{
                    if($treat == 0){
                        $array_list[] = 'IV Therapy';
                    }else{
                        $treatment = $this->CatCITreatments->find()->where(['CatCITreatments.id' => $treat])->first();
                        if($treatment->category_treatment_id == 1003){
                            $array_list[] = $treatment->name;
                        }else{
                            $array_list[] = 'IV Therapy';
                        }
                    }
                }
            }

            $array_list = array_unique($array_list);
        }else{
            $ondemando_flow = false;

            foreach($ent_treatments as $row){
                if($row->name == 'Let my provider choose' || $row->name == 'Let my provider help me decide' || $row->name == 'No preference'){
                    if($row->id == 999){$row['Cat']['name'] = 'Basic Neurotoxins'; $row->category_treatment_id = 1;}
                    $ent_treatments2 = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.std_price', 'CatCITreatments.name', 'CatCITreatments.category_treatment_id', 'Price.price'])->join([
                        'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id AND CatCITreatments.name <> CTC.name'],
                        'Price' => ['table' => 'data_treatments_prices', 'type' => 'LEFT', 'conditions' => 'Price.treatment_id = CatCITreatments.id AND Price.user_id = ' . $ent_treatment->assistance_id],
                    ])->where(['CatCITreatments.deleted' => 0, 'CatCITreatments.name NOT IN' => array('Let my provider help me decide', 'Let my provider choose', 'No preference') ,'CatCITreatments.category_treatment_id' => $row->category_treatment_id])->all();
                    $array_prices = array();
                    foreach ($ent_treatments2 as $key => $trea) {
                        $array_prices[] = $trea['name'] .' $' . (!empty($trea['Price']['price']) ? $trea['Price']['price']/100 : $trea['std_price']/100);
                    }
                    $array_list[] = $row['Cat']['name'] . ' (' . implode(', ', $array_prices) . ')';
                }else{
                    $array_list[] = $row->name == $row['Cat']['name'] ? $row->name . ' ($' . (!empty($row['Price']['price']) ? $row['Price']['price']/100 : $row->std_price/100) . ' ' . $row->details . ')' : $row['Cat']['name'] . ' ('. $row->name .' $' . (!empty($row['Price']['price']) ? $row['Price']['price']/100 : $row->std_price/100) . ' ' . $row->details . ')';
                }
            }
        }

        $string_treatments = implode(', ', $array_list);

        $address = $ent_treatment->address . ', ' . $ent_treatment->suite . ', ' . $ent_treatment->city . ', ' . $ent_treatment['State']['name'] . ' ' . $ent_treatment->zip;
        if(empty($ent_treatment->suite)){
            $address = $ent_treatment->address . ', ' . $ent_treatment->city . ', ' . $ent_treatment['State']['name'] . ' ' . $ent_treatment->zip;
        }

        $data = array(
            'treatment_id' => $ent_treatment->id,
            'treatment_uid' => $ent_treatment->uid,
            'treatments' => $string_treatments,
            'state' => $ent_treatment['State']['name'],
            'address' => $address,
            'city' => $ent_treatment->city,
            'zip' => $ent_treatment->zip,
            'suite' => $ent_treatment->suite,
            'schedule_date' => $ent_treatment->schedule_date->i18nFormat('MM-dd-yyyy hh:mm a'),
            'injector' => empty($ent_treatment['Injector']['name']) ? '' : $ent_treatment['Injector']['name'] . ' ' . $ent_treatment['Injector']['lname'],
            'photo_id' => empty($ent_treatment['Injector']['uid']) ? '' : $ent_treatment['Injector']['photo_id'],
            'gender' => empty($ent_treatment['Injector']['uid']) ? '' : $ent_treatment['Injector']['gender'],
            'ondemando_flow' => $ondemando_flow,
        );

        $this->set('data', $data);

        $this->success();
    }

    public function confirm_treatment(){
        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.CatCITreatments');
        $this->loadModel('SpaLiveV1.SysUsers');
        $Main = new MainController();
        $token = get('token', '');
        
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $treatment_uid = get('treatment_uid', '');
        $str_user = $user['email'] . " ". $user['name'] ." ". $user['lname'] . " ". $user['mname'];
        $str_user = strtolower($str_user);
        $user_test = false;
        if (strpos($str_user, "test") !== false) {
            $user_test= true;
        }
        $createdby = USER_ID;
        $patient_id = USER_ID;

        /**********************/ 

        $signature_id = 0;

        if (!isset($_FILES['file'])) {
            $this->set('error_file',$_FILES);
            return;
        }

        if (!isset($_FILES['file']['name'])) {
            $this->set('error_name',$_FILES['file']);
            return;
        }

        $str_name     = $_FILES['file']['name'];
        $signature_id = $this->Files->upload([
            'name' => $str_name,
            'type' => $_FILES['file']['type'],
            'path' => $_FILES['file']['tmp_name'],
            'size' => $_FILES['file']['size'],
        ]);

        $fields = [
            'DataTreatment.id',
            'DataTreatment.uid',
            'DataTreatment.assistance_id',
            'DataTreatment.patient_id',
            'DataTreatment.treatments',
            'DataTreatment.schedule_date',
            'DataTreatment.status',
            'DataTreatment.address',
            'DataTreatment.zip',
            'DataTreatment.city',
            'DataTreatment.suite',
            'DataTreatment.latitude',
            'DataTreatment.longitude',
            'State.name',
            'DataTreatment.tip'
        ];
        $fields['treatments_string'] = "(SELECT GROUP_CONCAT(CONCAT(CTC.name,' (',SUBSTRING_INDEX(CT.name, ':', 1), ')') SEPARATOR ', ') 
                                        FROM cat_treatments_ci CT 
                                        JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
                                        WHERE FIND_IN_SET(CT.id,DataTreatment.treatments) LIMIT 1)";
        $ent_treatment = $this->DataTreatment->find()->select($fields)->join([
            'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
        ])->where(['DataTreatment.uid' => $treatment_uid, 'DataTreatment.deleted' => 0])->first();

        $separate_treatments = $this->separate_treatments($ent_treatment->treatments_string);

        $array_treat = explode(',', $ent_treatment->treatments);
        $array_neuro = array();
        $array_iv = array();
        $array_filler = array();
        $array_other = array();
        $other_list_id = array();
        $array_treatments = array();
        foreach($array_treat as $key => $value){
            if($value == 999){
                $array_neuro[] = $value;
            } else if($value == 1033){
                $array_filler[] = $value;
            } else if($value == 0){
                $array_iv[] = $value;
            } else {
                $ent_treatments = $this->CatCITreatments->find()
                ->select(['CatCITreatments.id','CatCITreatments.name', 'Cat.name', 'CT.other_treatment_id', 'ST.name_key'])
                ->join([
                    'Cat' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'Cat.id = CatCITreatments.category_treatment_id'],
                    'CT' => ['table' => 'cat_treatments', 'type' => 'LEFT', 'conditions' => 'CT.id = CatCITreatments.treatment_id'],
                    'ST' => ['table' => 'sys_treatments_ot', 'type' => 'LEFT', 'conditions' => 'ST.id = CT.other_treatment_id'],
                ])
                ->where(['CatCITreatments.id' => $value])->first();

                if($ent_treatments['Cat']['name'] == 'IV Therapy' || $ent_treatments['Cat']['name'] == 'IV'){
                    $array_iv[] = $value;
                }else if($ent_treatments['Cat']['name'] == 'Other Treatments'){
                    $array_other[] = array(
                        'id' => $ent_treatments->id,
                        'name' => $ent_treatments->name,
                        'other_treatment_id' => $ent_treatments['CT']['other_treatment_id'],
                        'name_key' => $ent_treatments['ST']['name_key'],
                    );
                }
            }
        }

        $count_credits_gfe = 0;

        if(!empty($array_neuro)){
            $array_treatments['neurotoxins'] = $array_neuro;
            $count_credits_gfe = 1;
        }

        if(!empty($array_filler)){
            $array_treatments['fillers'] = $array_filler;
            $count_credits_gfe = 1;
        }

        if(!empty($array_iv)){
            $array_treatments['iv'] = $array_iv;
            if(USER_STATE == 10){
                $count_credits_gfe++;
            }
        }

        if(!empty($array_other)){
            $array_treatments['other'] = $array_other;
            $count_credits_gfe += count($array_other);
        }

        $other_tr = false;
        if (strpos($ent_treatment->treatments_string, "Other Treatments") !== false) {
            $other_tr = true;
        } 

        if(!empty($separate_treatments["iv_therapy"]) && empty($separate_treatments["neurotoxins"]) && empty($separate_treatments["fillers"])){
            if(USER_STEP == 'TREATMENTINFO' && USER_STATE == 10){
                $this->SysUsers->updateAll(['steps' => 'GFEPAYMENT'], ['id' => $patient_id]);
            }else if(USER_STEP == 'TREATMENTINFO' && USER_STATE != 10){
                $this->SysUsers->updateAll(['steps' => 'HOME'], ['id' => $patient_id]);
            }
        }else{
            if(USER_STEP == 'TREATMENTINFO'){

                $this->loadModel('SpaLiveV1.DataPatientOndemand');
                $ent_onde = $this->DataPatientOndemand->find()->where(['user_id' => USER_ID, 'deleted' => 0])->first();
                if(!empty($ent_onde)){
                    //if ($other_tr) {
                    //    $this->SysUsers->updateAll(['steps' => 'HOME'], ['id' => $patient_id]);
                    //} else {
                    $this->SysUsers->updateAll(['steps' => 'GFEFREE'], ['id' => $patient_id]);
                    while($count_credits_gfe > 0){
                        $this->createPayGfeFree(USER_ID, USER_STATE);
                        $count_credits_gfe--;
                    }
                    //}
                    
                }else{
                    $this->SysUsers->updateAll(['steps' => 'GFEPAYMENT'], ['id' => $patient_id]);
                }
            }
        }

        $this->DataTreatment->updateAll(
            [
                'signature_id' => $signature_id, 
                'status' => $ent_treatment->assistance_id > 0 ? 'REQUEST' : 'PETITION'
            ],
            ['id' => $ent_treatment->id]
        );
        $assistance_id = $ent_treatment->assistance_id;
        $users_array = array();
        $notifications_array = array();
        $fields = ['SysUsers.id', 'SysUsers.radius'];
        $fields['distance_in_mi'] = "(69.09 * DEGREES(ACOS(LEAST(1.0, COS(RADIANS(".$ent_treatment['latitude']."))
            * COS(RADIANS(SysUsers.latitude))
            * COS(RADIANS(".$ent_treatment['longitude']." - SysUsers.longitude))
            + SIN(RADIANS(".$ent_treatment['latitude']."))
            * SIN(RADIANS(SysUsers.latitude))))))";
        $fields['subscriptions'] = "(SELECT COUNT(DS.id) FROM data_subscriptions DS WHERE DS.user_id = SysUsers.id AND DS.deleted = 0 AND DS.status = 'ACTIVE' AND DS.subscription_type IN ('SUBSCRIPTIONMD', 'SUBSCRIPTIONMSL') )";
        $fields['level'] = "(SELECT count(dt.id) FROM data_trainings dt left join cat_trainings ct on ct.id = dt.training_id and ct.deleted =0  WHERE dt.user_id = SysUsers.id and dt.deleted=0 and ct.level='LEVEL 2' and ct.scheduled < NOW() )";
        $fields['level3'] = "(SELECT count(dt.id) FROM data_trainings dt left join cat_trainings ct on ct.id = dt.training_id and ct.deleted =0  WHERE dt.user_id = SysUsers.id and dt.deleted=0 and ct.level='LEVEL 3 MEDICAL' and ct.scheduled < NOW() )";
        $now = date('Y-m-d H:i:s');
        $ent_user = $this->SysUsers->find()->select($fields)
        ->where(['SysUsers.deleted' => 0, 'SysUsers.login_status' => 'READY','SysUsers.type' => 'injector','SysUsers.active' => 1,'SysUsers.steps' => 'HOME'])
        ->having(['distance_in_mi < SysUsers.radius'])
        ->all();

        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $this->loadModel('SpaLiveV1.DataTrainings');
        $this->loadModel('SpaLiveV1.DataTreatmentsPrice');

        foreach($ent_user as $key => $value){
            if(isset($array_treatments['neurotoxins'])){
                $where = ['user_id' => $value['id'], 'status' => 'ACTIVE', 'deleted' => 0, 'subscription_type LIKE' => '%MD%'];
                $where['OR'] = [['main_service' => 'NEUROTOXINS'], ['addons_services LIKE' => '%NEUROTOXINS%']];
                $ent_sub = $this->DataSubscriptions->find()->where($where)->first();

                if(empty($ent_sub)){
                    continue;
                }

                $ent_level1 = $this->DataTrainings->find()->select(['DataTrainings.id', 'DataTrainings.training_id', 'DataTrainings.attended', 'DataTrainings.deleted'])
                ->join([
                    'CatTrainings' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainings.id = DataTrainings.training_id'],
                ])
                ->where(['DataTrainings.user_id' => $value['id'], 'DataTrainings.attended' => 1, 'DataTrainings.deleted' => 0, 'CatTrainings.level' => 'LEVEL 1'])->first();

                $ent_level2 = $this->DataTrainings->find()->select(['DataTrainings.id', 'DataTrainings.training_id', 'DataTrainings.attended', 'DataTrainings.deleted'])
                ->join([
                    'CatTrainings' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainings.id = DataTrainings.training_id'],
                ])
                ->where(['DataTrainings.user_id' => $value['id'], 'DataTrainings.attended' => 1, 'DataTrainings.deleted' => 0, 'CatTrainings.level' => 'LEVEL 2'])->first();

                $ent_level3 = $this->DataTrainings->find()->select(['DataTrainings.id', 'DataTrainings.training_id', 'DataTrainings.attended', 'DataTrainings.deleted'])
                ->join([
                    'CatTrainings' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainings.id = DataTrainings.training_id'],
                ])
                ->where(['DataTrainings.user_id' => $value['id'], 'DataTrainings.attended' => 1, 'DataTrainings.deleted' => 0, 'CatTrainings.level' => 'LEVEL 3 MEDICAL'])->first();

                if(empty($ent_level1) || empty($ent_level2) || empty($ent_level3)){
                    continue;
                }
            }

            if(isset($array_treatments['fillers'])){
                $where = ['user_id' => $value['id'], 'status' => 'ACTIVE', 'deleted' => 0, 'subscription_type LIKE' => '%MD%'];
                $where['OR'] = [['main_service' => 'FILLERS'], ['addons_services LIKE' => '%FILLERS%']];
                $ent_sub = $this->DataSubscriptions->find()->where($where)->first();

                if(empty($ent_sub)){
                    continue;
                }
            }

            if(isset($array_treatments['iv'])){
                $where = ['user_id' => $value['id'], 'status' => 'ACTIVE', 'deleted' => 0, 'subscription_type LIKE' => '%MD%'];
                $where['OR'] = [['main_service' => 'IV THERAPY'], ['addons_services LIKE' => '%IV THERAPY%']];
                $ent_sub = $this->DataSubscriptions->find()->where($where)->first();

                if(empty($ent_sub)){
                    continue;
                }
                $join = [];
                if($array_treatments['iv'][0] == 0){
                    $join = ['CTC' => ['table' => 'cat_treatments_ci', 'type' => 'INNER', 'conditions' => 'CTC.id = DataTreatmentsPrice.treatment_id']];
                    $_where = ['DataTreatmentsPrice.user_id' => $value['id'], 'DataTreatmentsPrice.deleted' => 0, 'CTC.category_treatment_id' => 1001, 'CTC.deleted' => 0];

                    $treatment_prices = $this->DataTreatmentsPrice->find()
                    ->join($join)
                    ->where($_where)->all();

                    if(count($treatment_prices) == 0){
                        continue;
                    }
                }else{
                    $_where = ['user_id' => $value['id'], 'deleted' => 0, 'treatment_id IN' => $array_treatments['iv']];

                    $treatment_prices = $this->DataTreatmentsPrice->find()
                    ->join($join)
                    ->where($_where)->count();

                    if($treatment_prices < count($array_treatments['iv'])){
                        continue;
                    }
                }
            }

            if(isset($array_treatments['other'])){
                foreach($array_treatments['other'] as $treatment){
                    $where = ['user_id' => $value['id'], 'status' => 'ACTIVE', 'deleted' => 0, 'subscription_type LIKE' => '%MD%'];
                    $where['OR'] = [['main_service' => $treatment['name_key']], ['addons_services LIKE' => '%' . $treatment['name_key'] . '%']];
                    $ent_sub = $this->DataSubscriptions->find()->where($where)->first();

                    if(empty($ent_sub)){
                        continue;
                    }

                    // Verificar precios del tratamiento automáticamente
                    $_where = ['user_id' => $value['id'], 'deleted' => 0, 'treatment_id IN' => $treatment['id']];

                    $treatment_prices = $this->DataTreatmentsPrice->find()
                    ->where($_where)->count();

                    if($treatment_prices < 1){
                        continue;
                    }
                }
            }

            if(count($users_array) <= 15){
                $users_array[] = $value['id'];
            } else {
                $notifications_array[] = $value['id'];
            }

            if ($ent_treatment->status == 'INITOPEN' && count($notifications_array) == 15 && !$user_test) {
                $this->setOpenTreatmentNotification($ent_treatment->id, $notifications_array);
                $notifications_array = array();
            }
        }

        if ($ent_treatment->status == 'INITOPEN' && count($notifications_array) > 0 && !$user_test) {
            $this->setOpenTreatmentNotification($ent_treatment->id, $notifications_array);
        }


        $sstr_address = $ent_treatment->address . ', ' . $ent_treatment->city . ', ' . $ent_treatment['State']['name'] . ' ' . $ent_treatment->zip;
        if (!empty($certTreatment->suite)) {
            $sstr_address = $ent_treatment->address . ', ' . $ent_treatment->suite . ', ' . $ent_treatment->city . ', ' . $ent_treatment['State']['name'] . ' ' . $ent_treatment->zip;
        }

        if($ent_treatment->status == 'INITOPEN' && Count($users_array) > 0){ //TODO
            //TODO Cambiar id hardcodeado por $users_array para produccion
            //TODO cambiar el id
            //if(date('Y-m-d H:i:s', strtotime($now.'+ 1 hour')) < $ent_treatment['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm:ss')){
                $ent_patient = $this->SysUsers->find()->where(['SysUsers.id' => $patient_id, 'SysUsers.is_test' => 0, 'SysUsers.deleted' => 0])->first();

                if(!empty($ent_patient)){
                    $constants = [
                        '[SCHEDULE_PATIENT]' => $ent_treatment['schedule_date']->i18nFormat('MM-dd-yyyy hh:mm a'),
                        '[ADDRESS_PATIENT]' => $sstr_address
                    ];
        
                    if(!$user_test){
                        $this->loadModel('SpaLiveV1.DataTreatmentNotifications');

                        $arr_save = array(
                            'treatment_id' => $ent_treatment->id,
                            'id_invitations' => implode(',', $users_array),
                            'created' => date('Y-m-d H:i:s'),
                        );
                        $ent_not = $this->DataTreatmentNotifications->newEntity($arr_save);
                        $this->DataTreatmentNotifications->save($ent_not);

                        $Main->notify_devices('TREATMENT_AVAILABLE', $users_array, true, true, true, array(), '', $constants, true);
                    }
                }
            //}
        }
        
        if($ent_treatment->status == 'INIT'){                
            if(date('Y-m-d H:i:s', strtotime($now.'+ 1 hour')) < $ent_treatment['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm:ss')){
                $ent_patient = $this->SysUsers->find()->where(['SysUsers.id' => $patient_id, 'SysUsers.is_test' => 0, 'SysUsers.deleted' => 0])->first();
                $constants = [
                    '[CNT/PName]' => trim($ent_patient->name) . ' ' . trim($ent_patient->lname),
                    '[SCHEDULE_PATIENT]' => $ent_treatment['schedule_date']->i18nFormat('MM-dd-yyyy hh:mm a'),
                    '[ADDRESS_PATIENT]' => $sstr_address
                ];
                $this->set('test', $constants);
                if(!$user_test)
                    $Main->notify_devices('NEW_TREATMENT_PATIENT',array($assistance_id),true,true, true, array(), '',$constants,true);
            }
        }

        /*if($status == 'STOP'){
            if($user_test === false)
                $this->sendEmalStopRequest(USER_EMAIL, USER_NAME . ' ' . USER_LNAME, $notes, get('address','') . ', ' .get('city',''), date('m-d-Y H:i:s'), date('m-d-Y H:i:s', strtotime(get('schedule_date',''))), $treatment_uid);
        }*/


        $this->loadModel('SpaLiveV1.DataPatientOndemand');
        $this->loadModel('SpaLiveV1.DataVisitsSites');


        /// ****************** BOTOX VISITORS MODULE (PANEL) **************** START
        $ent_ondemand = $this->DataPatientOndemand->find()
        ->where(['DataPatientOndemand.user_id' => $patient_id])
        ->first();

        if (!empty($ent_ondemand)) {

            $ent_visit = $this->DataVisitsSites->find()
            ->where(['DataVisitsSites.ip' => $patient_id,'DataVisitsSites.page' => 'treatment'])
            ->first();
            
            if (empty($ent_visit)) {

                $page = 'treatment';
                if(isset($array_treatments['neurotoxins']) && isset($array_treatments['iv']) && isset($array_treatments['fillers'])) $page = 'treatment-neuro-iv-filler';
                if(isset($array_treatments['neurotoxins']) && isset($array_treatments['iv'])) $page = 'treatment-neuro-iv';
                if(isset($array_treatments['fillers'])) $page = 'treatment-filler';
                if(isset($array_treatments['iv'])) $page = 'treatment-iv';

                $array_save = array(
                    'created' => date('Y-m-d H:i:s'),
                    'ip' => $patient_id,
                    'page' => $page
                );
        
                $c_entity = $this->DataVisitsSites->newEntity($array_save);
                $this->DataVisitsSites->save($c_entity);
            }
            
        }

        /// ****************** BOTOX VISITORS MODULE (PANEL) **************** END


        $this->success();
            
    }

    private function createPayGfeFree($user_id, $user_state){

        $this->loadModel('SpaLiveV1.DataPayment');

        $array_save = array(
            'id_from' => $user_id,
            'id_to' => 0,
            'uid' => Text::uuid(),
            'type' => 'GFE', //'CI REGISTER','PURCHASE','GFE','TREATMENT','COMISSION','REFUND'
            'intent' => '_intent_',
            'payment' => '_charge_',
            'receipt' => '_receipt_',
            'discount_credits' => 0,
            'promo_discount' => '',
            'promo_code' =>  '',
            'subtotal' => 0,
            'total' => 0,
            'prod' => 1,
            'is_visible' => 1,
            'comission_payed' => 1,
            'comission_generated' => 0,
            'prepaid' => 1,
            'created' => date('Y-m-d H:i:s'),
            'createdby' => $user_id,
            'payment_option' => 0,
            'state' => $user_state,
        );
        $c_entity = $this->DataPayment->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            $this->DataPayment->save($c_entity); 
        } else {

        }
    }

    private function setOpenTreatmentNotification($treatment_id, $injectors_ids ) {

        $this->loadModel('SpaLiveV1.DataOpenTreatmentNotifications');
        $array_save = array(
            'treatment_id' => $treatment_id,
            'injectors_ids' => implode(',',$injectors_ids),
            'sent' => 0,
            'created' => date('Y-m-d H:i:s'),
            'deleted' => 0
        );

        $c_entity = $this->DataOpenTreatmentNotifications->newEntity($array_save);

        if(!$c_entity->hasErrors()){
            $this->DataOpenTreatmentNotifications->save($c_entity);
        }

    }

    public function save_treatment(){
        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.DataConsultationAnswers');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.SysUserAdmin');
        $this->loadModel('SpaLiveV1.DataModelPatient');
        $Main = new MainController();
        $token = get('token', '');
        
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $createdby = USER_ID;
        $patient_id = USER_ID;
        $assistance_id = get('injector_id', 0);

        $signature_id = 0;

        /**********************/

        $string_treatments = get('treatments','');
        $string_treatments = str_replace(" ", "", $string_treatments);
        
        if ($string_treatments == '') {
            $this->message('Treatments empty.');
            return;
        }

        $schedule_by = USER_ID;

        $status = $assistance_id == 0 ? 'INITOPEN' : 'INIT';

        $schedule_date = get('schedule_date','');

        if (empty($schedule_date)){
            $fecha_schedule = date('Y-m-d');
            $hora = date('H:i');
            if($hora < date('H:30')){
                $horaschedule = date('H:30');
            } else if($hora >= date('H:30')){
                $horaschedule = date('H:00', strtotime($hora."+ 1 hours"));
            }

            $horaschedule = date('H:i', strtotime($horaschedule."+ 30 minutes"));

            $schedule_date = date('Y-m-d H:i:s', strtotime($fecha_schedule . ' ' . $horaschedule));
        }

        $treatment_uid = Text::uuid();

        $type_uber = $status == 'INITOPEN' ? 1 : 0;

        if($assistance_id > 0){
            $this->loadModel('SpaLiveV1.CatCITreatments'); 
            $ent_treatments = $this->CatCITreatments->find()->select(['CatCITreatments.id','CatCITreatments.name','CatCITreatments.details', 'CatCITreatments.std_price', 'Cat.name', 'Cat.type', 'CatCITreatments.category_treatment_id'])
            ->join([
                'Cat' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'Cat.id = CatCITreatments.category_treatment_id'],
            ])
            ->where(['CatCITreatments.id IN' => explode(',', $string_treatments)])->all();

            $array_list = array();
            
            foreach ($ent_treatments as $key => $value) {
                if($value['Cat']['type'] == 'FILLERS'){
                    $this->loadModel('SpaLiveV1.DataSubscriptions');
                    $ent_sub = $this->DataSubscriptions->find()->where(['user_id' => $assistance_id, 'deleted' => 0, 'status' => 'ACTIVE', 'subscription_type LIKE' => '%MD%'])->first();
                    if(strpos($ent_sub->subscription_type, 'FILLERS') !== false){
                        $array_list[] = $value->id;
                    }
                }else{
                    $array_list[] = $value->id;
                }
            }

            $string_treatments = implode(',', $array_list);
        }

        $assigned_doctor = $this->SysUserAdmin->getRandomDoctor($assistance_id);        

        $created = date('Y-m-d H:i:s');
        $array_save = array(
            'uid' => $treatment_uid,
            'notes' => get('notes',''),
            'patient_id' => $patient_id,
            'assistance_id' => $assistance_id,
            'treatments' => $string_treatments,
            'amount' => intval(0),
            'address' => $user['street'],
            'suite' => $user['suite'],
            'zip' => $user['zip'],
            'city' => $user['city'],
            'state' => USER_STATE,
            'schedule_date' => $schedule_date,
            'status' => $status,
            'deleted' => 0,
            'created' => date('Y-m-d H:i:s'),
            'schedule_by' => $createdby,
            'createdby' => $createdby,
            'assigned_doctor' => $assigned_doctor,
            'type_uber' => $type_uber,
            'signature_id' => $signature_id,
        );

        // GETTING COORDINATES

        $this->loadModel('SpaLiveV1.CatStates');
        $obj_state = $this->CatStates->find()->select(['CatStates.name'])->where(['CatStates.id' => USER_STATE])->first();
                    
        $chain =  $user['street'] . ' ' . $user['city'] . ' ' . $user['zip'] . ' ,' . $obj_state->name;

        $coordinates = $Main->validate_coordinates($chain, $user['zip']);
        $array_save['latitude']   = $coordinates['latitude'];
        $array_save['longitude']  = $coordinates['longitude'];

        //******

        $c_entity = $this->DataTreatment->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->DataTreatment->save($c_entity)) {
                $this->set('treatment_uid', $treatment_uid);
                $this->success();
            }
        }
    }

    public function update_treatment(){

        $token = get('token', '');

        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.SysUserAdmin');
        
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $treatment_uid = get('treatment_uid', '');

        if(empty($treatment_uid)) {
            $this->message('Treatment UID empty.');
            return;
        }

        $ent_treatment = $this->DataTreatment->find()->where(['uid' => $treatment_uid])->first();

        $injector_id = get('injector_id', 0);

        /**********************/

        $string_treatments = get('treatments','');
        $string_treatments = str_replace(" ", "", $string_treatments);
        
        /*if (empty($string_treatments)) {
            $this->message('Treatments empty.');
            return;
        }*/

        if($injector_id > 0){
            $this->loadModel('SpaLiveV1.CatCITreatments'); 
            $ent_treatments = $this->CatCITreatments->find()->select(['CatCITreatments.id','CatCITreatments.name','CatCITreatments.details', 'CatCITreatments.std_price', 'Cat.name', 'Cat.type', 'CatCITreatments.category_treatment_id'])
            ->join([
                'Cat' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'Cat.id = CatCITreatments.category_treatment_id'],
            ])
            ->where(['CatCITreatments.id IN' => explode(',', $string_treatments)])->all();

            $array_list = array();
            
            foreach ($ent_treatments as $key => $value) {
                if($value['Cat']['type'] == 'FILLERS'){
                    $this->loadModel('SpaLiveV1.DataSubscriptions');
                    $ent_sub = $this->DataSubscriptions->find()->where(['user_id' => $injector_id, 'deleted' => 0, 'status' => 'ACTIVE', 'subscription_type LIKE' => '%MD%'])->first();
                    if(strpos($ent_sub->subscription_type, 'FILLERS') !== false){
                        $array_list[] = $value->id;
                    }
                }else{
                    $array_list[] = $value->id;
                }
            }

            $string_treatments = implode(',', $array_list);
        }

        $schedule_date = get('schedule_date','');

        if (empty($schedule_date)) 
            $schedule_date = date('Y-m-d H:i:s');

        $this->DataTreatment->updateAll(
            [
                'treatments' => empty($string_treatments) ? $ent_treatment->treatments : $string_treatments,
                'schedule_date' => get('schedule_date',''),
                'assistance_id' => $injector_id,
                'status' => $injector_id > 0 ? 'REQUEST' : 'PETITION',
                'type_uber' => $injector_id > 0 ? 0 : 1,
            ], 
            ['uid' => $treatment_uid]
        );

        //******
        $this->set('treatment_uid', $treatment_uid);
        $this->success();
    }

    public function chnage_injector_uber(){
        $token = get('token', '');

        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.SysUsers');
        
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $treatment_uid = get('treatment_uid', '');

        if (empty($treatment_uid)) {
            $this->message('Treatment UID empty.');
            return;
        }

        $treatment = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid, 'DataTreatment.deleted' => 0])->first();

        $this->DataTreatment->updateAll(
            ['assistance_id' => 0, 'status' => 'PETITION'],
            ['uid' => $treatment_uid]
        );
        $Main = new MainController();
        $Main->notify_devices('CANCEL_CLAIM', array($treatment->assistance_id), true, true, true, array(), '', array(), true);
        $this->success();
    }

    public function list_consents(){
        $token = get('token', '');

        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false) {
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        }else{
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $treatment_uid = get('treatment_uid', '');
        if(empty($treatment_uid)){
            $where = ['DataTreatment.patient_id' => USER_ID, 'DataTreatment.deleted' => 0];
        } else{
            $where = ['DataTreatment.uid' => $treatment_uid];
        }
        $this->loadModel('SpaLiveV1.DataTreatment');
        $select = ['DataTreatment.uid', 'DataTreatment.treatments', 'DataTreatment.state', 'DataTreatment.address', 'DataTreatment.city', 'DataTreatment.zip', 'DataTreatment.suite', 'DataTreatment.schedule_date'];

        $select['treatments_string'] = "(SELECT GROUP_CONCAT(CONCAT(CTC.name,' (',CT.name, ' $',FORMAT(CT.std_price/100, 0), ' ', CT.details, ')') SEPARATOR ', ') 
                                    FROM cat_treatments_ci CT 
                                    JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
                                    WHERE FIND_IN_SET(CT.id,DataTreatment.treatments) LIMIT 1)";

        $ent_treatment = $this->DataTreatment->find()->select($select)->where($where)->last();

        if(empty($ent_treatment)){
            $this->set('data', []);
            $this->success(false);
            return;
        }

        $data = array();
        
        $separate_treatments = $this->separate_treatments($ent_treatment->treatments_string);

        $this->loadModel('SpaLiveV1.CatAgreements');
        $this->loadModel('SpaLiveV1.DataAgreements');

        if(!empty($separate_treatments["neurotoxins"])){
            $ent_neuro = $this->CatAgreements->find()->select(['CatAgreements.id','CatAgreements.uid', 'CatAgreements.agreement_title', 'CatAgreements.content'])
            ->where(['CatAgreements.agreement_type' => 'REGISTRATION', 'CatAgreements.state_id' => USER_STATE, 'CatAgreements.deleted' => 0])->first();

            $agree_neuro = $this->DataAgreements->find()->where(['DataAgreements.user_id' => USER_ID, 'DataAgreements.agreement_uid' => $ent_neuro->uid, 'DataAgreements.deleted' => 0])->first();

            if(empty($agree_neuro)){
                $data[] = array(
                    'id' => $ent_neuro->id,
                    'uid' => $ent_neuro->uid,
                    'type' => 'NEUROTOXINS',
                    'title' => $ent_neuro->agreement_title,
                    'content' => $ent_neuro->content,
                );
            }
        }

        if(!empty($separate_treatments["iv_therapy"])){
            $ent_iv = $this->CatAgreements->find()->select(['CatAgreements.id','CatAgreements.uid', 'CatAgreements.agreement_title', 'CatAgreements.content'])
            ->where(['CatAgreements.agreement_type' => 'IVTHERAPHY', 'CatAgreements.state_id' => USER_STATE, 'CatAgreements.deleted' => 0])->first();

            $agree_iv = $this->DataAgreements->find()->where(['DataAgreements.user_id' => USER_ID, 'DataAgreements.agreement_uid' => $ent_iv->uid, 'DataAgreements.deleted' => 0])->first();

            if(empty($agree_iv)){
                $data[] = array(
                    'id' => $ent_iv->id,
                    'uid' => $ent_iv->uid,
                    'type' => 'IVTHERAPHY',
                    'title' => $ent_iv->agreement_title,
                    'content' => $ent_iv->content,
                );
            }
        }else{
            $array_treat = explode(',', $ent_treatment->treatments);
            foreach($array_treat as $key => $value){
                if($value == 0){
                    $ent_iv = $this->CatAgreements->find()->select(['CatAgreements.id','CatAgreements.uid', 'CatAgreements.agreement_title', 'CatAgreements.content'])
                    ->where(['CatAgreements.agreement_type' => 'IVTHERAPHY', 'CatAgreements.state_id' => USER_STATE, 'CatAgreements.deleted' => 0])->first();

                    $agree_iv = $this->DataAgreements->find()->where(['DataAgreements.user_id' => USER_ID, 'DataAgreements.agreement_uid' => $ent_iv->uid, 'DataAgreements.deleted' => 0])->first();

                    if(empty($agree_iv)){
                        $data[] = array(
                            'id' => $ent_iv->id,
                            'uid' => $ent_iv->uid,
                            'type' => 'IVTHERAPHY',
                            'title' => $ent_iv->agreement_title,
                            'content' => $ent_iv->content,
                        );
                    }
                }
            }
        }

        if(!empty($separate_treatments["fillers"])){
            $ent_fillers = $this->CatAgreements->find()->select(['CatAgreements.id','CatAgreements.uid', 'CatAgreements.agreement_title', 'CatAgreements.content'])
            ->where(['CatAgreements.agreement_type' => 'FILLERS', 'CatAgreements.state_id' => USER_STATE, 'CatAgreements.deleted' => 0])->first();

            $agree_fillers = $this->DataAgreements->find()->where(['DataAgreements.user_id' => USER_ID, 'DataAgreements.agreement_uid' => $ent_fillers->uid, 'DataAgreements.deleted' => 0])->first();

            if(empty($agree_fillers)){
                $data[] = array(
                    'id' => $ent_fillers->id,
                    'uid' => $ent_fillers->uid,
                    'type' => 'FILLERS',
                    'title' => $ent_fillers->agreement_title,
                    'content' => $ent_fillers->content,
                );
            }
        }

        $this->set('data', $data);

        $this->success();
    }

    public function remind_ondemand_treatment(){

        $fecha1 = strtotime("2024-03-10 08:00:00");
        $fecha2 = strtotime("2024-03-10 10:00:00");

        $diferenciaHoras = ($fecha2 - $fecha1); // 1 hora 3600 segundos
        

        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.SysUsers');

        $now = date('Y-m-d H:i:s');
        $treatments = $this->DataTreatment->find()->select(['DataTreatment.id', 'DataTreatment.uid', 'DataTreatment.schedule_date', 'Patient.name', 'Patient.lname', 'Patient.email', 'Patient.phone', 'Injector.name', 'Injector.lname', 'Injector.email' , 'Injector.phone'])
        ->join([
            'Patient' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Patient.id = DataTreatment.patient_id'],
            'Injector' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Injector.id = DataTreatment.assistance_id'],
        ])
        ->where(['DataTreatment.status' => 'CONFIRM', 'DataTreatment.assistance_id >' => 0, 'DataTreatment.type_uber' => 1, 'DataTreatment.deleted' => 0, 'DataTreatment.schedule_date >' => $now])->all();

        if(count($treatments) > 0){
            foreach ($treatments as $treatment) {
                $schedule_date = $treatment->schedule_date->i18nFormat('yyyy-MM-dd HH:mm:ss');

                $date1 = date('Y-m-d H:i:s', strtotime($schedule_date . ' - 90 minutes'));
                $date2 = date('Y-m-d H:i:s', strtotime($schedule_date . ' - 120 minutes'));
                $date3 = date('Y-m-d H:i:s', strtotime($schedule_date . ' - 1410 minutes'));
                $date4 = date('Y-m-d H:i:s', strtotime($schedule_date . ' - 1440 minutes'));

                $injector = $treatment['Injector']['name'] . " " . $treatment['Injector']['lname'];
                $patient = $treatment['Patient']['name'] . " " . $treatment['Patient']['lname'];

                if($now >= $date2 && $now <= $date1){ // 2 hrs antes
                    echo 'Se envia recordatorio a 2 hrs antes de la cita -';
                    $body = "Today is your appointment with " . $patient . " at " . $treatment->schedule_date->i18nFormat('HH:mm:ss') . " please remember to properly document their treatment";
                    $this->sendNotificationOndemand($treatment['Injector']['email'], $body, $treatment['Injector']['phone']);
                    $body = "Hi " . $patient . ", your appointment with your Myspalive injector " . $injector . " is confirmed for today at " . $treatment->schedule_date->i18nFormat('HH:mm:ss') . ". No shows will result in a fee.";
                    $this->sendNotificationOndemand($treatment['Patient']['email'], $body, $treatment['Patient']['phone']);

                } else if($now >= $date4 && $now <= $date3){ // 24 hrs antes
                    echo 'Se envia recordatorio a 24 hrs antes de la cita -';
                    $body = "Hi " . $injector . ", just a heads-up that you have a appointment with " . $patient . " tomorrow at " . $treatment->schedule_date->i18nFormat('HH:mm:ss') . ". Please ensure everything is prepped and ready. If you have had any last minute changes please call your patient immediately.";
                    $this->sendNotificationOndemand($treatment['Injector']['email'], $body, $treatment['Injector']['phone']);
                    $body = "Hi " . $patient . ", your appointment with your Myspalive injector " . $injector . " is confirmed for " . $treatment->schedule_date->i18nFormat('MM-dd-yyyy HH:mm:ss') . ". If this date and time no longer works please cancel your appointment to avoid any charges.";
                    $this->sendNotificationOndemand($treatment['Patient']['email'], $body, $treatment['Patient']['phone']);
                }else { // No se envia nada
                    echo 'No se envia recordatorio -';
                }
            }
            exit;
        }

        $this->success();
    }

    public function sendNotificationOndemand($email, $body, $tel) {

        $is_dev = env('IS_DEV', false);
        $data = array(
            'from'      => 'MySpaLive <noreply@mg.myspalive.com>',
            'to'    => $email,
            'subject'=> 'MySpaLive Message',
            'html' => $body,
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
    }

    public function add_iv_treatment_patient(){

        $token = get('token', '');

        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false) {
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        }else{
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $treatment_uid = get('treatment_uid', '');

        if(empty($treatment_uid)){
            $this->message('Treatment UID empty.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataTreatment');

        $ent_treatment = $this->DataTreatment->find()->where(['uid' => $treatment_uid])->first();

        if(empty($ent_treatment)){
            $this->message('Treatment not found.');
            return;
        }

        $this->DataTreatment->updateAll(
            ['treatments' => $ent_treatment->treatments . ',0'],
            ['uid' => $treatment_uid]
        );

        $Summary = new SummaryController();

        $agreements   = $Summary->get_agreements_patient(USER_ID);
        $certificates = $Summary->getCertificatesUser(USER_ID);
        $cats_treatment_arr = array('IV THERAPY');
        $result = $Summary->treatment_requirements_patients($cats_treatment_arr, $agreements, $certificates);

        $consent = $result['agreements'][0]['status'] == 'COMPLETED' ? true : false;

        $cert = count($result['examns']) == 0 || $result['examns'][0]['status'] ==  'CERTIFICATE' ? true : false;
        
        $step = '';
        if(!$cert){
            $step = 'GFE';
        }else if(!$consent){
            $step = 'CONSENT';
        }else{
            $step = 'HOME';
        }

        $this->set('step', $step);
        $this->success();
    }
}