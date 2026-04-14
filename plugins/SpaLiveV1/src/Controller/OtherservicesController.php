<?php
declare(strict_types=1);

namespace SpaLiveV1\Controller;
use App\Controller\AppPluginController;
use Cake\Core\Configure;
use Cake\Utility\Security;
use Cake\Utility\Text;
use Cake\Utility\Hash;
require_once(ROOT . DS . 'vendor' . DS  . 'Twilio' . DS . 'autoload.php');
use Twilio\Rest\Client; 
use Twilio\Exceptions\TwilioException; 
require_once(ROOT . DS . 'vendor' . DS  . 'stripe' . DS . 'init.php');
use Stripe\Stripe;
use \Stripe\Error;
use Spipu\Html2Pdf\Html2Pdf;
use Spipu\Html2Pdf\Exception\Html2PdfException;
use Spipu\Html2Pdf\Exception\ExceptionFormatter;
use App\Command\RemindersCommand;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use \PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use Cake\I18n\FrozenTime;

use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;

/*require_once(ROOT . DS . 'vendor' . DS  . 'dompdf' . DS . 'init.php');
use Dompdf\Dompdf;
use Dompdf\Options;*/

use SpaLiveV1\Controller\SubscriptionController;
use SpaLiveV1\Controller\MainController;
require_once(ROOT . DS  . 'vendor' .'/include_firebase.php');
use Google\Cloud\Firestore\FirestoreClient;
use phpDocumentor\Reflection\Types\This;

class OtherservicesController extends AppPluginController {
     
    private $total = 3900;
    private $paymente_gfe = 1800;
    private $register_total = 79500;
    private $register_refund = 3500;
    private $shipping_cost = 1000;
    private $shipping_cost_both = 3000;
    private $shipping_cost_inj = 2000;
    private $shipping_cost_mat = 1000;
    private $shipping_cost_misc = 1000;
    private $training_advanced = 89500;
    private $emergencyPhone = "9035301512";
    private $total_subscriptionmsl = 3995;
    private $total_subscriptionmd = 17900;
    private $total_subscriptionmslservice = 9900;
    private $total_services = 9900;
    private $URL_API = "";
    private $URL_WEB = "";
    private $URL_ASSETS = "";
    private $URL_PANEL = "";
    private $URL_SITE = "";
    private $not_allowed_names = ['Test', 'test', 'Tester', 'tester', 'Prueba', 'prueba', 'Testing', 'testing'];

    protected $mailgunKey = null;

    protected function getMailgunKey(): ?string
    {
        if ($this->mailgunKey === null) {
            $this->mailgunKey = env('MAILGUN_API_KEY');
        }
        return $this->mailgunKey;
    }
    
    public function initialize() : void {
        parent::initialize();
        date_default_timezone_set("America/Chicago");
        $this->URL_API = env('URL_API', 'https://api.myspalive.com/');
        $this->URL_WEB = env('URL_WEB', 'https://app.myspalive.com/');
        $this->URL_ASSETS = env('URL_ASSETS', 'https://api.myspalive.com/assets/');
        $this->URL_PANEL = env('URL_PANEL', 'https://panel.myspalive.com/');
        $this->URL_SITE = env('URL_SITE', 'https://blog.myspalive.com/');
        $this->loadModel('SpaLiveV1.AppToken');
        $this->loadModel('SpaLiveV1.CatStates');
        $this->loadModel('SpaLiveV1.CatProducts');
        
        \Stripe\Stripe::setApiKey(Configure::read('App.stripe_secret_key'));   
        
        $token = get('token',"");
        if(isset($token)){
            $user = $this->AppToken->checkToken($token);
            if($user !== false){
                $state = $this->CatStates->find()->select(['CatStates.cost_ci', 'CatStates.refund_ci', 'CatStates.shipping_cost', 'CatStates.cost_gfe', 'CatStates.payment_gfe','CatStates.phone_number'])->where(['CatStates.id' => $user['user_state']])->first();
                if(!empty($state)){
                    $this->register_total = $state->cost_ci > 0 ? $state->cost_ci : $this->register_total;
                    $this->register_refund = $state->refund_ci > 0 ? $state->refund_ci : $this->register_refund;
                    $this->shipping_cost = $state->shipping_cost > 0 ? $state->shipping_cost : $this->shipping_cost;
                    
                    $this->shipping_cost_both = $state->shipping_cost_both > 0 ? $state->shipping_cost_both : $this->shipping_cost_both;
                    $this->shipping_cost_inj = $state->shipping_cost_inj > 0 ? $state->shipping_cost_inj : $this->shipping_cost_inj;
                    $this->shipping_cost_mat = $state->shipping_cost_mat > 0 ? $state->shipping_cost_mat : $this->shipping_cost_mat;

                    $this->total = $state->cost_gfe > 0 ? $state->cost_gfe : $this->total;
                    $this->paymente_gfe = $state->payment_gfe > 0 ? $state->payment_gfe : $this->paymente_gfe;
                    $this->emergencyPhone = $state->phone_number != '' ? $state->phone_number : $this->emergencyPhone;
                    $this->total_subscriptionmsl = $state->price_sub_msl > 0 ? $state->price_sub_msl : $this->total_subscriptionmsl;
                    $this->total_subscriptionmd = $state->price_sub_md > 0 ? $state->price_sub_md : $this->total_subscriptionmd;
                }
            }

            $ver = get('version', '');
            $ver = str_replace('version ', '', $ver);
        } else {
            // TEXAS
            $state = $this->CatStates->find()->select(['CatStates.cost_ci', 'CatStates.refund_ci', 'CatStates.shipping_cost', 'CatStates.cost_gfe', 'CatStates.payment_gfe'])->where(['CatStates.id' => 43])->first();
            if(!empty($state)){
                $this->register_total = $state->cost_ci > 0 ? $state->cost_ci : $this->register_total;
                $this->register_refund = $state->refund_ci > 0 ? $state->refund_ci : $this->register_refund;
                $this->shipping_cost = $state->shipping_cost > 0 ? $state->shipping_cost : $this->shipping_cost;
                
                $this->shipping_cost_both = $state->shipping_cost_both > 0 ? $state->shipping_cost_both : $this->shipping_cost_both;
                $this->shipping_cost_inj = $state->shipping_cost_inj > 0 ? $state->shipping_cost_inj : $this->shipping_cost_inj;
                $this->shipping_cost_mat = $state->shipping_cost_mat > 0 ? $state->shipping_cost_mat : $this->shipping_cost_mat;

                $this->total = $state->cost_gfe > 0 ? $state->cost_gfe : $this->total;
                $this->paymente_gfe = $state->payment_gfe > 0 ? $state->payment_gfe : $this->paymente_gfe;
                $this->emergencyPhone = $state->phone_number != '' ? $state->phone_number : $this->emergencyPhone;
                $this->total_subscriptionmsl = $state->price_sub_msl > 0 ? $state->price_sub_msl : $this->total_subscriptionmsl;
                $this->total_subscriptionmd = $state->price_sub_md > 0 ? $state->price_sub_md : $this->total_subscriptionmd;
            }
        }
        $product = $this->CatProducts->find()->select(['CatProducts.unit_price'])->where(['CatProducts.id' => 44])->first();
        if(!empty($product)){
            $this->training_advanced = $product->unit_price > 0 ? $product->unit_price : $this->training_advanced;
        }
    }

    public function test_n() {
       // Search specialist with wl treatment

        $this->loadModel('SpaLiveV1.SysUsers');
        $users = $this->SysUsers
        ->find()
        ->select([
            'id' => 'SysUsers.id',
            'name' => 'SysUsers.name',
            'email' => 'SysUsers.email',
            'consultation_uid' => 'Consultation.uid',
            'specialist_id' => 'Specialist.user_id',
        ])
        ->join([
            'Consultation' => [
                'table' => 'data_consultation_other_services',
                'type' => 'INNER',
                'conditions' => [
                    'Consultation.patient_id = SysUsers.id',
                    'Consultation.deleted' => 0,
                ]
            ],
            'Specialist' => [
                'table' => 'data_users_other_services_check_in',
                'type' => 'INNER',
                'conditions' => [
                    'Specialist.user_id = SysUsers.id',
                    'Specialist.status' => 'WLSHOME',
                    'Specialist.deleted' => 0,
                ]
            ],
        ])
        ->where([
            'SysUsers.deleted' => 0,
            'SysUsers.type' => 'gfe+ci',
        ])
        ->all();

        $this->set('users', $users);
        return;
    }


    public function get_msl_services(){
        $token = get('token','');
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

        $this->loadModel('SpaLiveV1.CatOtherServices');

        $services = $this->CatOtherServices->find()->where(['CatOtherServices.deleted' => 0])->all();

        $arr_services = [];
        if(Count($services) > 0){
            foreach ($services as $key => $value) {
                $arr_services[] = [
                    'uid' => $value->uid,
                    'title' => $value->title,
                    'short_desc' => $value->short_desc,
                    'long_desc' => $value->long_desc,
                    'url_video' => $value->url_video,
                    'service_type' => $value->service_type,
                ];
            }
            $this->set('services', $arr_services);
        } else {
            $this->set('services', []);
        }

        $this->set('video_url', '');
        $this->success();
    }

    public function get_service(){
        $token = get('token','');
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

        $uid_service = get('uid_service','');
        if(empty($uid_service)){
            $this->message('Service empty.');
            return;
        }

        $this->loadModel('SpaLiveV1.CatOtherServices');

        $service = $this->CatOtherServices->find()->where(['CatOtherServices.uid' => $uid_service, 'CatOtherServices.deleted' => 0])->first();

        if(!empty($service)){
            $this->set('data', [
                'uid' => $service->uid,
                'title' => $service->title,
                'long_desc' => $service->long_desc,
                'url_video' => $service->url_video,
                'service_type' => $service->service_type,
            ]);
            $this->success();
            return;
        } else {
            $this->message('Invalid service.');
            return;
        }
    }

    public function get_service_by_name(){
        $token = get('token','');
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

        $title = get('title','');
        if(empty($title)){
            $this->message('title empty.');
            return;
        }

        $this->loadModel('SpaLiveV1.CatOtherServices');

        $service = $this->CatOtherServices->find()->where(['CatOtherServices.title' => $title, 'CatOtherServices.deleted' => 0])->first();

        if(!empty($service)){
            $this->set('data', [
                'uid' => $service->uid,
                'title' => $service->title,
                'long_desc' => $service->long_desc,
                'url_video' => $service->url_video,
                'service_type' => $service->service_type,
            ]);
            $this->success();
            return;
        } else {
            $this->message('Invalid service.');
            return;
        }
    }

    public function get_questions_service(){
        $token = get('token','');
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

        $service_uid = get('service_uid','');
        if(empty($service_uid)){
            $this->message('Service empty.');
            return;
        }

        $questionary_type = get('questionary_type','');
        if(empty($questionary_type)){
            $this->message('questionary_type empty.');
            return;
        }

        $this->loadModel('SpaLiveV1.CatQuestionOtherServices');
        $this->loadModel('SpaLiveV1.CatAnswerOtherServices');

        $questions = $this->CatQuestionOtherServices->find()->where(['CatQuestionOtherServices.service_uid' => $service_uid, 'CatQuestionOtherServices.questionary_type' => $questionary_type, 'CatQuestionOtherServices.deleted' => 0])->all();

        if(Count($questions) > 0){
            $arr_questions = [];
            foreach ($questions as $key => $value) {

                $array_answers = [];

                $fields_answers = ['CatAnswerOtherServices.id','CatAnswerOtherServices.answer'];
                $_where_answers = ['CatAnswerOtherServices.id_question' => $value->id, 'CatAnswerOtherServices.deleted' => 0];
                $entity_answers = $this->CatAnswerOtherServices->find()->select($fields_answers)->where($_where_answers)->all();

                if(!empty($entity_answers)){
                    foreach($entity_answers as $row_answers) {
                        array_push($array_answers,$row_answers->answer);
                    }
                }
                
                $arr_questions[] = [
                    'id' => $value->id,
                    'question' => $value->question,
                    'type' => $value->type,
                    'options' => $array_answers,
                    'answer' => "",
                    'validate' => $value->validate,
                ];
            }
            $this->set('questions', $arr_questions);
            $this->success();
            return;
        } else {
            $this->set('questions', []);
            $this->message('Questions empty.');
            return;
        }

    }

    public function get_questions_examiner_service(){
        $token = get('token','');
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

        $uid_service = get('uid_service','');
        if(empty($uid_service)){
            $this->message('Service empty.');
            return;
        }

        $this->loadModel('SpaLiveV1.CatQuestionOtherServices');

        $questions = $this->CatQuestionOtherServices->find()->where(['CatQuestionOtherServices.service_uid' => $uid_service, 'CatQuestionOtherServices.deleted' => 0])->all();

        if(Count($questions) > 0){
            $arr_questions = [];
            foreach ($questions as $key => $value) {
                $arr_questions[] = [
                    'id' => $value->id,
                    'question' => $value->question,
                ];
            }
            $this->set('questions', $arr_questions);
            $this->success();
            return;
        } else {
            $this->set('questions', []);
            $this->message('Questions empty.');
            return;
        }

    }

    public function save_shipping_address(){

        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.DataPurchases');
        
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

        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $this->message('consultation uid not found.');
            return;
        }

        $name = get('name','');
        if (empty($name)) {
            $this->message('name not found.');
            return;
        }

        $address = get('address','');
        if (empty($address)) {
            $this->message('address not found.');
            return;
        }

        $suite = get('suite','');
        if (empty($suite)) {
            $this->message('suite not found.');
            return;
        }

        $state = get('state','');
        if (empty($state)) {
            $this->message('state not found.');
            return;
        }

        $city = get('city','');
        if (empty($city)) {
            $this->message('city not found.');
            return;
        }

        $zip = get('zip','');
        if (empty($zip)) {
            $this->message('zip not found.');
            return;
        }

        $call_id = get('call_id','');

        if (empty($call_id)||$call_id=="null") {

            $arr_conditions = [
                'DataOtherServicesCheckIn.consultation_uid' => $consultation_uid,
                'DataOtherServicesCheckIn.call_type' => 'FIRST CONSULTATION',
                'DataOtherServicesCheckIn.deleted' => 0,
            ];

            $getCall = $this->DataOtherServicesCheckIn->find()
            ->select(['DataOtherServicesCheckIn.purchase_id'])
            ->where($arr_conditions)
            ->first();

            $id = $getCall->purchase_id;

            for ($i = 0; $i < 3; $i++) {

                $this->DataPurchases->updateAll(
                    ['name' => $name,
                    'address' => $address,
                    'suite' => $suite,
                    'state' => $state,
                    'city' => $city,
                    'zip' => intval($zip),
                    ], 
                    ['id' => $id]
                );

                $id++;

            }

        }else{

            $getCall = $this->DataOtherServicesCheckIn->find()
            ->select(['DataOtherServicesCheckIn.purchase_id'])
            ->where(['DataOtherServicesCheckIn.id' => $call_id])
            ->first();

            $id = $getCall->purchase_id;

            for ($i = 0; $i < 3; $i++) {

                $this->DataPurchases->updateAll(
                    ['name' => $name,
                    'address' => $address,
                    'suite' => $suite,
                    'state' => $state,
                    'city' => $city,
                    'zip' => intval($zip),
                    ], 
                    ['id' => $id]
                );

                $id++;
            }
        }
        
        $this->success(); 
            
    }

    
    public function save_answers_mslservices(){
        
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $this->loadModel('SpaLiveV1.DataConsultationAnswersOtherServices');

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

        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $this->message('consultation uid not found.');
            return;
        }

        $string_answers = get('answers','');
        $arr_answers = json_decode($string_answers,true);
        
        if (empty($arr_answers)) {
            $this->message('Answers empty.');
            return;
        }

        $consultation_id = $this->DataConsultationOtherServices->find()
        ->select(['DataConsultationOtherServices.id'])
        ->where(['DataConsultationOtherServices.uid' => $consultation_uid])
        ->first();
        
        $arr_conditions = [
            'DataConsultationAnswersOtherServices.consultation_id' => $consultation_id['id'],
            'DataConsultationAnswersOtherServices.deleted' => 0,
        ];
        
        $existAnswers = $this->DataConsultationAnswersOtherServices->find()
        ->select(['DataConsultationAnswersOtherServices.id'])
        ->where($arr_conditions)
        ->all();
        
        if ($existAnswers) {
            foreach($existAnswers as $row){
                $this->DataConsultationAnswersOtherServices->updateAll(
                    ['deleted' => 1], 
                    ['id' => $row['id']]
                 );
            }          
        }

        foreach ($arr_answers as $row) {
            $arr_save_q = array(
                'uid' => Text::uuid(),
                'consultation_id' => $consultation_id['id'],
                'question_id' => $row['id'],
                'response' => $row['response'],
                'details' => $row['details'],
                'deleted' => 0
            );

            $cq_entity = $this->DataConsultationAnswersOtherServices->newEntity($arr_save_q);
            if(!$cq_entity->hasErrors()){
                $this->DataConsultationAnswersOtherServices->save($cq_entity);
            }
        }

        $this->DataConsultationOtherServices->updateAll(
            ['current_weight' => get('current_weight',''),
             'main_goal_weight' => get('main_goal_weight',''),
             'goals' => get('goals',''),
             'notes' => get('notes','')], 
            ['id' => $consultation_id['id']]
         );
        
        $this->success(); 
            
    }

    public function create_consultation(){
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
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

        $payed = $this->DataConsultationOtherServices->find()
            ->where(['DataConsultationOtherServices.payment <>' => '', 
                    'DataConsultationOtherServices.deleted' => 0, 
                    'DataConsultationOtherServices.status' => 'PAID',
                    'DataConsultationOtherServices.patient_id' => USER_ID])
            ->last();

        if(!empty($payed)) {
            $this->message('Payment already done.');
            $this->set('data', $payed);
            $this->success();
            return;
        }

        $service_uid = get('service_uid','');
        if (empty($service_uid)) {
            $this->message('Service uid empty.');
            return;
        }

        $schedule_by = 0;
        $schedule_date = get('schedule_date','');
        if (!empty($schedule_date)) {
            $schedule_by = USER_ID;
        }

        if (empty($schedule_date)) 
            $schedule_date = date('Y-m-d H:i:s');

        $createdby = USER_ID;
        $patient_id = USER_ID;

        $consultation_uid = Text::uuid();

        $array_save = array(
            'uid' => $consultation_uid,
            'patient_id' => $patient_id,
            'assistance_id' => 0,
            'service_uid' => $service_uid,
            'payment' => '',
            'meeting' => '',
            'meeting_pass' => '',
            'schedule_date' => $schedule_date,
            'status' => "INIT",
            'schedule_by' => $schedule_by,
            'deleted' => 0,
            'participants' => 0,
            'createdby' => $createdby,
            'payment_method' => get('payment_method',''),
            'goals' => '',
            'created' => date('Y-m-d H:i:s'),
        );

        $c_entity = $this->DataConsultationOtherServices->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            $this->DataConsultationOtherServices->save($c_entity);
        }
            
        if(!empty($array_save)){
            $this->set('data', $c_entity);
            $this->success(); 
        } else {
            $this->message('Invalid service.');
            return;
        }
    }

    public function update_consultation(){
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');

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

        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $this->message('consultation_uid not found.');
            return;
        }

        $call_type = get('call_type','');
        if (empty($call_type)) {
            $this->message('call type not found.');
            return;
        }

        if($call_type == 'FIRST CONSULTATION'){
            $this->DataConsultationOtherServices->updateAll(
                ['assistance_id' => USER_ID], 
                ['uid' => $consultation_uid]
            );
            $this->DataOtherServicesCheckIn->updateAll(
                ['support_id' => USER_ID], 
                ['uid' => $consultation_uid, 'call_type' => 'FIRST CONSULTATION']
            );
        } else {
            $this->DataOtherServicesCheckIn->updateAll(
                ['support_id' => USER_ID],
                ['uid' => $consultation_uid]
            );
        }
    }
    
    /*public function create_consultation(){
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

        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
       
        $Main = new MainController();

        $service_uid = get('service_uid','');
        if (empty($service_uid)) {
            $this->message('Service uid empty.');
            return;
        }

        $schedule_by = 0;
        $schedule_date = get('schedule_date','');
        if (!empty($schedule_date)) {
            $schedule_by = USER_ID;
        }

        if (empty($schedule_date)) 
            $schedule_date = date('Y-m-d H:i:s');

        $createdby = USER_ID;
        $patient_id = USER_ID;

        //Consulta si la consulta ya existe
        $arr_conditions = [
            'DataConsultationOtherServices.service_uid' => $service_uid,
            'DataConsultationOtherServices.patient_id' => $patient_id,
            'DataConsultationOtherServices.deleted' => 0,
        ];
        
        $existConsultation = $this->DataConsultationOtherServices->find()
        ->select(['DataConsultationOtherServices.uid', 'DataConsultationOtherServices.status'])
        ->where($arr_conditions)
        ->first();
        
        if($existConsultation){
            $consultation_status = $existConsultation['status'];
            $consultation_uid = $existConsultation['uid'];

            if($consultation_status=='INIT'){
                $this->DataConsultationOtherServices->updateAll(
                   ['deleted' => 1], 
                   ['uid' => $consultation_uid]
                );

                $consultation_uid = Text::uuid();

                $array_save = array(
                    'uid' => $consultation_uid,
                    'patient_id' => $patient_id,
                    'assistance_id' => 0,
                    'service_uid' => $service_uid,
                    'payment' => '',
                    'meeting' => '',
                    'meeting_pass' => '',
                    'schedule_date' => $schedule_date,
                    'status' => "INIT",
                    'schedule_by' => $schedule_by,
                    'deleted' => 0,
                    'participants' => 0,
                    'createdby' => $createdby,
                    
                    'payment_method' => get('payment_method',''),
                    'goals' => '',
                    'created' => date('Y-m-d H:i:s'),
                );

                $c_entity = $this->DataConsultationOtherServices->newEntity($array_save);
                if(!$c_entity->hasErrors()) {
                    $this->DataConsultationOtherServices->save($c_entity);
                }

                if(!empty($array_save)){
                    $this->set('data', $c_entity);
                    
                } else {
                    $this->message('Invalid service.');
                    return;
                }

           } else {

            $consultation = $this->DataConsultationOtherServices->find()
                ->where($arr_conditions)
                ->first();

                if(!empty($consultation)){
                    $this->set('data', $consultation);
                } else {
                    $this->message('Invalid service.');
                    return;
                }
           }
            
        } else {

            $consultation_uid = Text::uuid();

                $array_save = array(
                    'uid' => $consultation_uid,
                    'patient_id' => $patient_id,
                    'assistance_id' => 0,
                    'service_uid' => $service_uid,
                    'payment' => '',
                    'meeting' => '',
                    'meeting_pass' => '',
                    'schedule_date' => $schedule_date,
                    'status' => "INIT",
                    'schedule_by' => $schedule_by,
                    'deleted' => 0,
                    'participants' => 0,
                    'createdby' => $createdby,
                    'payment_method' => get('payment_method',''),
                    'goals' => '',
                    'created' => date('Y-m-d H:i:s'),
                );

                $c_entity = $this->DataConsultationOtherServices->newEntity($array_save);
                if(!$c_entity->hasErrors()) {
                    $this->DataConsultationOtherServices->save($c_entity);
                }
                    
                if(!empty($array_save)){
                    $this->set('data', $c_entity);
                } else {
                    $this->message('Invalid service.');
                    return;
                }
                
            
        }

        $this->success(); 
    }*/


    public function start_consultation_msl_service(){
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices'); 

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

        $consultation_uid = get('consultation_uid','');
            if (empty($consultation_uid)) {
                $this->message('consultation_uid not found.');
                return;
            }

        $string_mslservices = get('mslservices','');
            if (empty($string_mslservices)) {
                $this->message('Services empty.');
                return;
            }
        
        $schedule_date = date('Y-m-d H:i:s');
        
        $schedule_by = USER_ID;
        
        $createdby = USER_ID;

        $array_save = array(
            //'id' => $consultation_id,
            'schedule_date' => $schedule_date,
            'schedule_by' => $schedule_by,
            'status' => 'INIT',
            'participants' => 0,
            'is_waiting' => 0
        );

        $Main = new MainController();
        $r = $Main->generateMeeting($schedule_date);

        if ($r) {
            $array_save['meeting'] = $r['id'];
            $array_save['meeting_pass'] = $r['password'];
            $array_save['join_url'] = $r['join_url'];

        } else {
            return;
        }

        $this->DataConsultationOtherServices->updateAll(
            $array_save, 
            ['uid' => $consultation_uid]
        );

        $this->success();

    }


    public function check_msl_service() {
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataModelPatient');
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

        $type_service = get('type_service', '');

        $Main = new MainController();

        $user_id = USER_ID;
        $patient_uid = get('patient_uid','');
        if (!empty($patient_uid)) {
            $this->loadModel('SpaLiveV1.SysUsers');
            $ent_user = $this->SysUsers->find()->where(['SysUsers.uid' => $patient_uid])->first();
            if (!empty($ent_user)) {
                $user_id = $ent_user->id;
            }
        }
          
        $this->loadModel('SpaLiveV1.DataPayment');
        $ent_payment = $this->DataPayment->find()
        ->where(['DataPayment.id_from' => $user_id, 'DataPayment.id_to' => 0,'DataPayment.type' => $type_service, 'DataPayment.service_uid' => '','DataPayment.payment <>' => '','DataPayment.prepaid' => 1, 'DataPayment.comission_payed' => 1])->first();

        $this->set('request_payment_service', empty($ent_payment) ? true : false);
        $this->set('amount_service', empty($ent_payment) ? $this->total_services : $ent_payment->total);
        
        $ent_subscription = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => USER_ID, 'DataSubscriptions.subscription_type' => 'SUBSCRIPTIONMSLSERVICES', 'DataSubscriptions.status IN' => array('ACTIVE','HOLD'), 'DataSubscriptions.deleted' => 0])->first();
        $this->set('request_payment_subscription', empty($ent_subscription) ? true : false);
        $this->set('amount_subscription', empty($ent_subscription) ? $this->total_subscriptionmslservice : $ent_subscription->total);
        
        $av_result = $Main->gfeAvailability();
        $this->set('available', $av_result);

        $this->set('available_message', "Our Weight Loss specialists are available from Monday to Saturday from 7:30 AM to 7:30 PM. If you have reached us outside of our business hours, holidays, etc., please feel free to schedule your exam for a specific date and time or reach back out to us during business hours. Thank you!");
        
        $this->success();

    }

    public function save_referred(){
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $this->loadModel('SpaLiveV1.DataReferredOtherServices');
        $this->loadModel('SpaLiveV1.DataAssignedToRegister');
        $this->loadModel('SpaLiveV1.DataPayment');
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

        $referred = get('referred', 0);
        if($referred <= 0){
            $this->message('Invalid referred.');
            return;
        }
        //echo "entro";
        $referred_id = $this->SysUsers->find()->where(['SysUsers.id' => $referred])->first();

        if(empty($referred_id)){
            $this->message('Invalid referred.');
            return;
        }

        # region validation if referred_id is same as USER_ID

        if($referred_id->id == USER_ID){
            $this->message('You cannot invite yourself.');
            return;
        }

        # endregion

        $service_uid = get('service_uid');
        if(empty($service_uid)){
            $this->message('Invalid service uid.');
            return;
        }
        
        //create injector commission
        $_fields = ['DataConsultationOtherServices.payment_intent','Service.title','DataConsultationOtherServices.monthly_payment','DataConsultationOtherServices.uid',];

        $_join = [
            'Service' => ['table' => 'cat_other_services','type' => 'INNER','conditions' => 'Service.uid = DataConsultationOtherServices.service_uid'],
        ];

        $ent_consultation = $this->DataConsultationOtherServices->find()->select($_fields)->where(
            ['DataConsultationOtherServices.patient_id' => USER_ID,
             'DataConsultationOtherServices.service_uid' => $service_uid])->join($_join)->last();

        if(empty($ent_consultation)){
            $this->message('Consultation not found.');
            return;
        }
        //checar si quien invito al paciente es un weightloss specialist, asignarlo a la primera consulta
        $this->loadModel('SpaLiveV1.DataReferredOtherServices');
        $this->loadModel('SpaLiveV1.DataUsersOtherServicesCheckIn'); 
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        
        /*$ref = $this->DataReferredOtherServices->find()->where(['DataReferredOtherServices.referred_id' => $referred, 'DataReferredOtherServices.deleted' => 0])->first();
        
        if(!empty($ref)){
            $ref_id = $ref->referred_id;
            $referred_user = $this->DataUsersOtherServicesCheckIn->find()->where(['DataUsersOtherServicesCheckIn.user_id' => $ref->referred_id, 'DataUsersOtherServicesCheckIn.deleted' => 0])->first();
            $this->set('referred_user', $referred_user);
            if(!empty($referred_user)){
                $ref_id = $ref->referred_id;                      
                $this->DataOtherServicesCheckIn->updateAll(
                    ['status' => 'CLAIMED', 'support_id' => $ref_id], 
                    ['consultation_uid' => $ent_consultation->uid , 'call_type' => 'FIRST CONSULTATION']
                );
            }
        }*/
        
        $ent_payment = $this->DataPayment->find()->where(
            ['DataPayment.id_from' => USER_ID, 'DataPayment.type' => $ent_consultation["Service"]["title"],
             'DataPayment.intent' => $ent_consultation->payment_intent,
            ])->last();
        
        if(empty($ent_payment)){
            $this->message('Payment not found.');
            return;
        }

        if($ent_payment->comission_generated == 1){
            $this->set('data', array());
            $this->success();
            return;
        }

        $amount = $ent_consultation->monthly_payment == 'MONTHLY' ? 3300 : 7500;
        
        $Main = new MainController();
        $Main->createPaymentCommissionRegister("WEIGHT LOSS COMMISSION",USER_ID,$referred,$ent_payment->uid,$ent_payment->intent,$ent_payment->payment,$ent_payment->receipt,$amount, '',$ent_payment->payment_platform);
        
        if($ent_consultation->monthly_payment == 'MONTHLY' ){
            $Main->notify_devices('INVITED_MONTH_WS',array($referred),false,true,true,array(),'',array(),false);
        } else if($ent_consultation->monthly_payment == '3 MONTHS'){
            $Main->notify_devices('INVITED_3MONTHs_WS',array($referred),false,true,true,array(),'',array(),false);
        }
        
        $ent_payment->comission_generated = 1;
        $this->DataPayment->save($ent_payment);

        $array_save = array(
            'uid' => Text::uuid(),
            'user_id' => USER_ID,
            'referred_id' => $referred,
            'created' => date('Y-m-d H:i:s'),
            'createdby' => USER_ID,
            'deleted' => 0,
        );

        $c_entity = $this->DataReferredOtherServices->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            $ent_saved = $this->DataReferredOtherServices->save($c_entity);
            $this->set('data', $ent_saved);
            $this->success();
        }
    }

    public function search_referred(){
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataReferredOtherServices');
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

        $search = get('search', '');
        if(empty($search)){
            $this->message('Invalid search.');
            return;
        }

        $_join = [
            'State' => ['table' => 'cat_states','type' => 'INNER','conditions' => 'State.id = SysUsers.state'],
        ];

        $arr_val = explode(' ', str_replace('@', '', $search));
        $matchValue = '';
        $sep = '';
        foreach ($arr_val as $value) {
            $matchValue .= $sep.'+'.$value.'*';
            $sep = ' ';
        }

        $_where = ['SysUsers.id <>' => USER_ID];
        $_where['OR'] = [['SysUsers.email LIKE' => "%$search%"],['SysUsers.name LIKE' => "%$search%"],['SysUsers.lname LIKE' => "%$search%"],
            "MATCH(SysUsers.name,SysUsers.mname,SysUsers.lname) AGAINST ('{$matchValue}' IN BOOLEAN MODE)"];
        
        $_where['SysUsers.deleted'] = 0;
        $_where['State.id'] = 43;
        $_where['SysUsers.type IN'] = ['injector','gfe+ci'];
        $_where['SysUsers.steps'] = 'HOME';

        $fields = ['SysUsers.id','SysUsers.name','SysUsers.mname','SysUsers.lname','SysUsers.email'];
        $arrUsers = $this->SysUsers->find()->select($fields)->join($_join)->where($_where)->all();

        $result = [];
        foreach ($arrUsers as $key => $value) {
            $result[] = [
                'id' => $value->id,
                'name' => !empty($value->mname) ? $value->name.' '.$value->mname.' '.$value->lname : $value->name.' '.$value->lname,
                'email' => $value->email,
            ];
        }

        if(Count($result) > 0){
            $this->set('data', $result);
            $this->success();
        } else {
            $this->message('No results found.');
        }
    }

    public function get_mls_service_consultation(){
        $token = get('token','');
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

        $patient_id = get('patient_id', '');
        if(empty($patient_id)){
            $this->message('Invalid patient.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $this->loadModel('SpaLiveV1.CatOtherServices');

        $arr_conditions = [
            'DataConsultationOtherServices.status' => 'INIT',
            'DataConsultationOtherServices.uid <>' => '',
            'DataConsultationOtherServices.patient_id' => $patient_id,
            'DataConsultationOtherServices.deleted' => 0,
        ];

        
        $services = $this->DataConsultationOtherServices->select(['DataConsultationOtherServices.uid','DataConsultationOtherServices.id'])->where($arr_conditions)->join([
            'Services' => ['CatOtherServices' => 'title', 'conditions' => 'CatOtherServices.uid = DataConsultationOtherServices.service_uid'],
            ]);
        
        print_r($services);
        return;
        $arr_services = [];
        if(Count($services) > 0){
            foreach ($services as $key => $value) {
                print_r($value);
                $arr_services[] = [
                    'uid' => $value->uid,
                    'schedule_date' => $value->schedule_date,
                    //'short_desc' => $value->short_desc,
                ];
            }
            $this->set('services', $arr_services);
        } else {
            $this->set('services', []);
        }

        $this->success();
    }


    public function get_online_pharmacy_products(){
        $token = get('token','');
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

        $service_uid = get('service_uid','');
        if(empty($service_uid)){
            $this->message('Service empty.');
            return;
        }

        $this->loadModel('SpaLiveV1.CatProductsOtherServices');
        //$this->loadModel('SpaLiveV1.CatPackagesProductsOtherServices');
        //$this->loadModel('SpaLiveV1.DataPackageProductsOtherServices');


        /*$products = $this->DataPackageProductsOtherServices->find()
        ->select($this->DataPackageProductsOtherServices)
        ->select(['category'=>'CatPackagesProductsOtherServices.category'])
        ->select(['name' => 'CatProductsOtherServices.name','item_description' => 'CatProductsOtherServices.item_description','store_link' => 'CatProductsOtherServices.store_link','qty' => 'CatProductsOtherServices.qty'])
        ->join([
            'CatPackagesProductsOtherServices' => [
            'table' => 'cat_packages_products_other_services',
            'type' => 'INNER',
            'conditions' => 'CatPackagesProductsOtherServices.id = DataPackageProductsOtherServices.package_id'],

            'CatProductsOtherServices' => ['table' => 'cat_products_other_services', 
            'type' => 'LEFT', 
            'conditions' => 'CatProductsOtherServices.id = DataPackageProductsOtherServices.product_id']
        ])->where(['DataPackageProductsOtherServices.service_uid' =>  $service_uid])->all();*/

        $products = $this->CatProductsOtherServices->find()->where(['CatProductsOtherServices.service_uid' => $service_uid, 'CatProductsOtherServices.deleted' => 0])->all();

        if(Count($products) > 0){
            $arr_products = [];
            foreach ($products as $key => $value) {
                $arr_products[] = [
                    'id' => $value->id,
                    'category' => $value->category,
                    'service_uid' => $value->service_uid,
                    'name' => $value->name,
                    'unit_price' => $value->unit_price,
                    'item_description' => $value->item_description,
                    'stock' => $value->stock,
                    'store_link' => $value->store_link,
                ];
            }
            $this->set('products', $products);
            $this->success();
            return;
        } else {
            $this->set('products', []);
            $this->message('Products empty.');
            return;
        }

    }


    public function get_questions_answer_other_service($return_ques = false){
        $token = get('token','');
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

        $uid_service = get('uid_service','');//1q2we3-r4t5y6-7ui8o990
        if(empty($uid_service)){
            $this->message('Service empty.');
            return;
        }

        $patient_id = get('patient_id','');//1q2we3-r4t5y6-7ui8o990
        if(empty($patient_id)){
            $this->message('patient_id empty.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataConsultationAnswersOtherServices');       
        
        $fields = ['DataConsultationAnswersOtherServices.id', 'consultation_id', 'question_id', 'response', 'details', 'findings' , 'cqos.question'];       
        $questions = $this->DataConsultationAnswersOtherServices->find()
        ->select($fields)
        ->join([
            'dcos' => ['table' => 'data_consultation_other_services', 'type' => 'LEFT', 'conditions' => 'dcos.id = DataConsultationAnswersOtherServices.consultation_id and dcos.deleted = 0 and dcos.status ="INIT"'],
            'cqos' => ['table' => 'cat_question_other_services', 'type' => 'LEFT', 'conditions' => ' cqos.id = DataConsultationAnswersOtherServices.question_id and cqos.deleted = 0'],            
        ])
        ->where(['dcos.patient_id' => $patient_id, 'DataConsultationAnswersOtherServices.deleted' => 0,  'dcos.service_uid' => $uid_service])->all();

        if(Count($questions) > 0){
            $arr_questions = [];
            foreach ($questions as $key => $value) {
                $arr_questions[] = [
                    'id' => $value->id,
                    'question' => $value['cqos']['question'],
                    'response' => $value->response,
                ];
            }
            if($return_ques)
                return $arr_questions;
            $this->set('answers', $arr_questions);
            $this->success();
            return;
        } else {
            if($return_ques)
                return  [];
            $this->set('answers', []);
            $this->message('Answers empty.');
            return;
        }
    }

    public function reject_accept_consultaion_other_service(){
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices'); 
        $this->loadModel('SpaLiveV1.DataOtherServices'); 

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

        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $this->message('consultation_uid not found.');
            return;
        }

        $date_start = get('date_start','');
        if (empty($date_start)) {
            $this->message('start date not found.');
            return;
        }

        $date_expiration = get('date_expiration','');
        if (empty($date_expiration)) {
            $this->message('Expiration date not found.');
            return;
        }

        $status = get('status','');//DONE accept, REJECTED reject 
        if (empty($status)) {
            $this->message('status empty.');
            return;
        }                    
        $otherServices = $this->DataConsultationOtherServices->find()->select(['DataConsultationOtherServices.id'])->where(['DataConsultationOtherServices.uid' => $consultation_uid])->first();
        if(empty($otherServices)){
            $this->message('uid not found.');
            return;
        }

        $createdby = $user['user_id'];
        
        $this->DataConsultationOtherServices->updateAll(
            ['status' => $status], 
            ['uid' => $consultation_uid]
        );        

        $r_uid = Text::uuid();
        $this->set("uid",0);
        if($status == 'DONE'){
            $array_save = array(
                'uid' => $r_uid,
                'consultation_id' => $otherServices->id,//
                'date_start' => $date_start,
                'date_expiration' => $date_expiration,                               
                //'status' => "INIT",                
                'createdby ' => $createdby,//                
                'created' => date('Y-m-d H:i:s'),
            );
    
            $c_entity = $this->DataOtherServices->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                if ($this->DataOtherServices->save($c_entity)) {
                    //$this->set('data', $ent_saved);
                    $this->success();
                    $this->set("uid",$r_uid);
                }
            }else{
                $this->message('An error occurred. code:x801');
                return;
            }
        }

        $this->success();

    }

    public function save_products_assigned_patient(){

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

        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $this->message('consultation_uid not found.');
            return;
        }

        $patient_id = get('patient_id','');
        if (empty($patient_id)) {
            $this->message('patient id not found.');
            return;
        }
        $string_products = get('products','');
        if (empty($string_products)) {
            $this->message('Products empty.');
            return;
        }
        
        $this->loadModel('SpaLiveV1.DataOtherServicesDetails'); 
        $this->loadModel('SpaLiveV1.DataOtherServices');
        $otherServices = $this->DataOtherServices->find()->select(['DataOtherServices.id'])->where(['DataOtherServices.uid' => $consultation_uid])->first();

        $str ="";
        $arr =[];
        $arr_components = explode(",", $string_products);
        foreach ($arr_components as $item) {
            $str .= "," . $item;
            $arr_save_q = array(
                'uid' => Text::uuid(),
                'consultation_os_id' => $otherServices->id,
                'product_os_id' => $item,
                'patient_id' => $patient_id,                
                'deleted' => 0
            );
            array_push($arr,$arr_save_q);
            $cq_entity = $this->DataOtherServicesDetails->newEntity($arr_save_q);
            if(!$cq_entity->hasErrors()){
                $this->DataOtherServicesDetails->save($cq_entity);

            }else{
                $this->message('An error occurred. code:x893');
                return;
            }


        }
        
        $this->set('arr', json_encode($arr));
        $this->success();
    }

    public function save_telehealth_call(){                   
    
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

        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $this->message('consultation_uid not found.');
            return;
        }

        $call_number = get('call_number', '');
        if (empty($call_number)) {
            $this->message('call_number not found.');
            return;
        }

        $call_type = get('call_type','');
        if (empty($call_type)) {
            $this->message('call_type not found.');
            return;
        }
        
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        //llamar tablas
        $this->loadModel('SpaLiveV1.DataConsultationAnswersOtherServices');
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.DataPurchasesOtherServices');
        $this->loadModel('SpaLiveV1.DataOtherServicesAnswers');

        $Main = new MainController();

        $av_result = $Main->gfeAvailability();
        $this->set('available', $av_result);

        $this->success();
        $this->message("Success.");
        return;
        
        // Actualiza en la tabla de purchases y le asigna el id
        /*$previousPurchase = $this->DataPurchasesOtherServices->find()
        ->where(['DataPurchasesOtherServices.consultation_uid' => $consultation_uid,
                'DataPurchasesOtherServices.call_type' => 'FIRST CONSULTATION', 
                'DataPurchasesOtherServices.deleted' => 0])->first();*/

        //Guarda la primera consulta en la tabla de check in
        /*$arr_save = array(
            'uid' => Text::uuid(),
            'consultation_uid' => $consultation_uid,
            'patient_id' => USER_ID,
            'call_date' => date('Y-m-d'),
            'status' => 'COMPLETED',
            'call_number' => intval($call_number),
            'call_type' => $call_type,
            'call_title' => 'First Consultation',
            'show' => 1,
            'created' => date('Y-m-d H:i:s'),
            'purchase_id' => $previousPurchase->id,

        );*/

        /*$cq_entity_first_consultation = $this->DataOtherServicesCheckIn->newEntity($arr_save);
        if(!$cq_entity_first_consultation->hasErrors()){
            $this->DataOtherServicesCheckIn->save($cq_entity_first_consultation);
        }*/

        // 

        /*$checkin = $this->DataOtherServicesCheckIn->find()->select()->where(['DataOtherServicesCheckIn.consultation_uid' => $consultation_uid, 'DataOtherServicesCheckIn.call_number' => $call_number, 'DataOtherServicesCheckIn.call_type' => $call_type])->first();
        if (empty($checkin)) {
            $this->message('Data Check in Other Services not found.');
            return;
        }*/
        
        //$this->set('checkin', $checkin);
        //return;

        $otherS = $this->DataConsultationOtherServices->find()->select()->where(['DataConsultationOtherServices.uid' => $consultation_uid])->first();
        if (empty($otherS)) {
            $this->message('Data Consultation Other Services not found.');
            return;
        }$is_dev = env('IS_DEV', false);
        $jwt_data = "";
        //if($otherS->meeting == "" || $otherS->meeting_pass =="" && $otherS->join_url == ""){
            $Main = new MainController();
            if($is_dev){$this->log(__FILE__ . " ".__LINE__ . " generateMeeting date " .json_encode(date('Y-m-d H:i:s')));                
                $r = $Main->generateMeeting(date('Y-m-d H:i:s'));                
            }else{
                $r = $Main->generateMeeting($otherS->schedule_date);                
            }
            
            if ($r) {
                if(isset($r['code'])){
                    $this->log(__FILE__ . " ".__LINE__ . " zoom code " .json_encode($r['code']));
                    $this->set('zoom_code', json_encode($r['code']));
                }                
                if(isset($r['message'])){
                    $this->log(__FILE__ . " ".__LINE__ . " zoom code " .json_encode($r['message']));
                    $this->set('zoom_message', json_encode($r['message']));
                }
                $array_save['meeting'] = isset($r['id'])?$r['id'] :'';
                $array_save['meeting_pass'] = isset($r['password'])?$r['password'] :'';
                $array_save['join_url'] = isset($r['join_url']) ?$r['join_url']:'';
                $jwt_data = isset($r['jwt']) ?$r['jwt']:'';                
                $this->set('meeting', isset($r['id'])?$r['id'] :'');
                $this->set('meeting_pass', isset($r['password'])?$r['password'] :'');
                $this->set('join_url', isset($r['join_url'])?$r['join_url'] :'');
                $this->set('jwt', isset($r['jwt'])?$r['jwt'] :''); 
                $this->DataConsultationOtherServices->updateAll(
                    $array_save, 
                    ['uid' => $consultation_uid]
                );
            } else {
                $this->message("Error to create the meeting.");
                return;
            }            
        //}
        
        
        $fields = ['DataConsultationOtherServices.id',
                'DataConsultationOtherServices.uid',
                'DataConsultationOtherServices.patient_id',
                'DataConsultationOtherServices.service_uid',
                'DataConsultationOtherServices.meeting',
                'DataConsultationOtherServices.meeting_pass',
                'DataConsultationOtherServices.join_url',
                'DataConsultationOtherServices.schedule_date',
                'DataConsultationOtherServices.status',
                'DataConsultationOtherServices.notes',
                'DataConsultationOtherServices.current_weight',
                'DataConsultationOtherServices.main_goal_weight',
                'DataConsultationOtherServices.is_waiting',
                'DataConsultationOtherServices.goals',
                'service_title' =>'service.title',
                'user_name'=>'user.name','user_lname'=>'user.lname',
                'user.phone'
            ];        
        //join cosas
        $otherServices = $this->DataConsultationOtherServices->find()
        ->select($fields)
        ->join([            
            'user' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'user.id = DataConsultationOtherServices.patient_id'],
            'service' => ['table' => 'cat_other_services', 'type' => 'LEFT', 'conditions' => 'service.uid = DataConsultationOtherServices.service_uid'],
        ])
        ->where(['DataConsultationOtherServices.uid' => $consultation_uid])->first();

        if(empty($otherServices)){
            $this->message('uid not found.');
            return;
        }
        
        $otherServices['formatted_phone'] = preg_replace('/(\d{3})(\d{3})(\d{4})/', '($1) $2 $3', $otherServices['user']['phone']);
        
        $_POST['uid_service'] = $otherServices->service_uid;
        $_POST['patient_id'] = $otherServices->patient_id;                    
        $ques_resp = $this->get_questions_answer_other_service(true); 
        $questions = Array();
        //if($this->getParams('questions') !== null){
            $questions = $ques_resp;
        //}        
        //try {
            /*$firestoreClient = new FirestoreClient([        
                'projectId' => 'myspalive-b10d7',
                ]
            );
            $collectionReference = $firestoreClient->collection('services');
            $documentReference = $collectionReference->document('0e4bd8b4b8dd4756a080');
            $snapshot = $documentReference->snapshot();*/
            //$this->set("snapshot",$snapshot->data());           
            
            $checkin = $this->DataOtherServicesCheckIn->find()->select()->where(['DataOtherServicesCheckIn.consultation_uid' => $consultation_uid, 'DataOtherServicesCheckIn.call_number' => $call_number, 'DataOtherServicesCheckIn.call_type' => $call_type])->first();
            if (empty($checkin)) {
                $this->message('Data Check in Other Services not found.');
                return;
            }

            /// Actualiza las respuestas del questionario y les pone el id del checkin
            $ent_answers = $this->DataOtherServicesAnswers->find()->where(['DataOtherServicesAnswers.consultation_id' => $otherS->id])->first();
            $ent_answers->check_in_id = $checkin->id;

            $update = $this->DataOtherServicesAnswers->save($ent_answers);

            if($update){

                $questions = json_decode($ent_answers->data);

                $data = array(
                    "id" => $otherServices->id,
                    "token" => $token,
                    "uid" => $otherServices->uid,
                    "examiner_uid" => "",
                    "patient_id" => $otherServices->patient_id,
                    "patient_phone" => $otherServices->formatted_phone,
                    "service_uid" => $otherServices->service_uid,
                    "meeting" => $otherServices->meeting,
                    "meeting_pass" => $otherServices->meeting_pass,
                    "join_url" => $otherServices->join_url,
                    "scheduled_date" => '',
                    "status" => $av_result ? 'WAITING': '',
                    "notes" => $otherServices->notes,
                    "is_waiting" => $otherServices->is_waiting,
                    "current_weight" => $otherServices->current_weight,
                    "main_goal_weight" => $otherServices->main_goal_weight,
                    "service_title" => $otherServices->service_title,
                    "user_name" => $otherServices->user_name . " " . $otherServices->user_lname,                                
                    'date_created' => date('m-d-Y h:i a'),
                    'created_by' => USER_ID,
                    'questions' => $questions,
                    'jwt' =>  $jwt_data,
                    //añadir chekin_id
                    'call_id' => strval($checkin->id),
                    'call_type' => $call_type,
                    'call_title' => 'First Consultation',
                    'questions'  => $questions,
                    'has_images' => false,
                );

                /*if ($call_number == '1' && $call_type=='FIRST CONSULTATION') {
                    //validar que se asigno el injector que lo recomendo
                    $referred = $this->DataOtherServicesCheckIn->find()
                    ->where([
                        'DataOtherServicesCheckIn.consultation_uid' => $consultation_uid,
                        'DataOtherServicesCheckIn.deleted' => 0,
                        'DataOtherServicesCheckIn.call_type' => 'FIRST CONSULTATION',
                    ])
                    ->order(['DataOtherServicesCheckIn.call_date' => 'DESC'])
                    ->first();
                    if(isset($referred)){
                        if(!empty($referred)){                        
                            $support_id = $referred->support_id;
                            $this->loadModel('SpaLiveV1.SysUsers');
                            $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $referred->support_id])->first();
                            if (!empty($ent_user)) {                            
                                $data['examiner_uid'] = $ent_user->uid;
                                $data["status"] = "CLAIMED";
                            }
                        }
                    }
                    
                }*/

                $this->set('data', $data);
                $this->set('checkin', $checkin);
                
                //$firebase_document = substr($name, strpos($name, "services/") + 9);*/
                $name= $otherServices->uid;
                $data_string = json_encode($data);
                $url = env('URL_MICROSERVICE', 'http://localhost:3131/');
                $ch=curl_init($url);
                curl_setopt($ch, CURLOPT_POST, true);//curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
                curl_setopt($ch, CURLOPT_HEADER, false);
                curl_setopt($ch, CURLOPT_HTTPHEADER,
                    array(
                        'Content-Type:application/json',
                        'Content-Length: ' . strlen($data_string)
                    )
                );
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                $result = curl_exec($ch);  
                if (curl_errno($ch)) {
                    $error_msg = curl_error($ch);
                    $this->message($error_msg);
                    $this->success(false);
                    return;
                    // this would be your first hint that something went wrong                            
                } else {
                    // check the HTTP status code of the request
                    $resultStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    if ($resultStatus == 200) {
                        //unlink($filename);// everything went better than expected
                    } else {
                        // the request did not complete as expected. common errors are 4xx
                        // (not found, bad request, etc.) and 5xx (usually concerning
                        // errors/exceptions in the remote script execution)                                
                    }
                }
                curl_close($ch);

                $array_save['status'] = $av_result ? 'WAITING' : 'SCHEDULED';
                $array_save['firebase_document'] = $name;                  
                $this->DataConsultationOtherServices->updateAll(
                    $array_save, 
                    ['uid' => $consultation_uid] 
                );
                //Se necesita crear documento en firebase para despues agendar
                if (!$av_result) {
                    $this->message('Our Weight Loss specialists are available from Monday to Saturday from 7:30 AM to 7:30 PM. If you have reached us outside of our business hours, holidays, etc., please feel free to schedule your exam for a specific date and time or reach back out to us during business hours. Thank you!');
                    return;
                }
                $this->set("res",$name);
                //$this->save_first_consultation($consultation_uid, $call_type, $call_number);

                $this->success();
                $this->message("Success.");    
                return;
            /*}catch (\Throwable $th) {
                $this->message("An error occurred.". json_encode($th));
                return;                    
            }*/
            

            $this->set("otherServices",$otherServices);
            $this->success();
            return;
        }else{
            $this->message('Error in answers update.');
            return;
        }

    }

    /*public function save_first_consultation($consultation_uid, $call_type, $call_number){
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.DataPurchasesOtherServices');
        
        //prueba
        //$token = get('token', '');
        //$consultation_uid = get('consultation_uid', '');
        //$call_type = get('call_type', '');
        //$call_number = get('call_number', '');
        
        //consulta purchase
        $previousPurchase = $this->DataPurchasesOtherServices->find()
        //  ->select(['DataPurchasesOtherServices.id'])
        ->where(['DataPurchasesOtherServices.consultation_uid' => $consultation_uid,
                'DataPurchasesOtherServices.call_type' => 'FIRST CONSULTATION', 
                'DataPurchasesOtherServices.deleted' => 0])->first();

        $arr_save = array(
            'uid' => Text::uuid(),
            'consultation_uid' => $consultation_uid,
            'patient_id' => USER_ID,
            'call_date' => date('Y-m-d'),
            'status' => 'COMPLETED',
            'call_number' => intval($call_number),
            'call_type' => $call_type,
            'call_title' => 'First Consultation',
            'show' => 1,
            'created' => date('Y-m-d H:i:s'),
            'purchase_id' => $previousPurchase->id
        );

        $cq_entity = $this->DataOtherServicesCheckIn->newEntity($arr_save);
        if(!$cq_entity->hasErrors()){
            $this->DataOtherServicesCheckIn->save($cq_entity);
        }

        $this->success();
    }*/


    /*public function save_telehealth_call_dates(){
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');

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

        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $this->message('consultation_uid not found.');
            return;
        }

        $call_number = get('call_number', '');
        if (empty($call_number)) {
            $this->message('call_number not found.');
            return;
        }

        $call_type = get('call_type','');
        if (empty($call_type)) {
            $this->message('call_type not found.');
            return;
        }

        $previousCalls = $this->DataOtherServicesCheckIn->find()->select(['DataOtherServicesCheckIn.id'])->where(['DataOtherServicesCheckIn.consultation_uid' => $consultation_uid, 'DataOtherServicesCheckIn.deleted' => 0])->all();

        if ($previousCalls) {
            foreach($previousCalls as $row){
                $this->DataOtherServicesCheckIn->updateAll(
                    ['status' => 'DONE'], 
                    ['id' => $row['id']]
                 );
            }          
        }

        $call_title='';
        $next_call_title='';
        $next_call_number=intval($call_number)+1;
        

        switch ($call_number) {
            case 1:
                $call_title='First consultation';
                break;
            case 2:
                $call_title='First check in';
                break;
            case 3:
                $call_title='Second check in';
                break;
            case 4:
                $call_title='Third check in';
                break;
            case 5:
                $call_title='Fourth check in';
                break;
            case 6:
                $call_title='Fifth check in';
                break;
            case 7:
                $call_title='Sixth check in';
                break;
            // Agrega más casos según tus necesidades
        }

        switch ($next_call_number) {
            case 1:
                $next_call_title='First consultation';
                break;
            case 2:
                $next_call_title='First check in';
                break;
            case 3:
                $next_call_title='Second check in';
                break;
            case 4:
                $next_call_title='Third check in';
                break;
            case 5:
                $next_call_title='Fourth check in';
                break;
            case 6:
                $next_call_title='Fifth check in';
                break;
            case 7:
                $next_call_title='Sixth check in';
                break;
            // Agrega más casos según tus necesidades
        }


        if ($call_number == 1 && $call_type=='START TREATMENT'){
            $arr_save = array(
                'uid' => Text::uuid(),
                'consultation_uid' => $consultation_uid,
                'patient_id' => USER_ID,
                'call_date' => date('Y-m-d'),
                'status' => 'DONE',
                'call_number' => intval($call_number),
                'call_type' => $call_type,
                'call_title' => $call_title,
                'created' => date('Y-m-d H:i:s'),
            );

            $cq_entity = $this->DataOtherServicesCheckIn->newEntity($arr_save);
            if(!$cq_entity->hasErrors()){
                $this->DataOtherServicesCheckIn->save($cq_entity);
            }

            $arr_save_2 = array(
                'uid' => Text::uuid(),
                'consultation_uid' => $consultation_uid,
                'patient_id' => USER_ID,
                'call_date' => date('Y-m-d', strtotime(date('Y-m-d') . ' +15 days')),
                'status' => 'PENDING',
                'call_number' => intval($call_number)+1,
                'call_type' => 'CHECK IN',
                'call_title' => $next_call_title,
                'created' => date('Y-m-d H:i:s'),
            );

            $cq_entity_2 = $this->DataOtherServicesCheckIn->newEntity($arr_save_2);
            if(!$cq_entity_2->hasErrors()){
                $this->DataOtherServicesCheckIn->save($cq_entity_2);
            }

        } 

        if ($call_number > 1 && $call_type=='CHECK IN'){
           
            $this->DataOtherServicesCheckIn->updateAll(
                ['status' => 'DONE'], 
                ['consultation_uid' => $consultation_uid,
                'call_number' => $call_number,
                ]
             );

             if($call_number==3 || $call_number==5 || $call_number==7){
                $followUpCallNumber = 0;
                $followUpTitle='';
                switch ($call_number) {
                    case 3:
                        $followUpCallNumber = 1;
                        $followUpTitle='First Follow Up';
                        break;
                    case 5:
                        $followUpCallNumber = 2;
                        $followUpTitle='Second Follow Up';
                        break;
                    case 7:
                        $followUpCallNumber = 3;
                        $followUpTitle='Third Follow Up';
                        break;
                }
                
                $previousFollowUp = $this->DataOtherServicesCheckIn->find()->select(['DataOtherServicesCheckIn.id'])
                    ->where(['DataOtherServicesCheckIn.consultation_uid' => $consultation_uid, 
                            'DataOtherServicesCheckIn.deleted' => 0, 
                            'DataOtherServicesCheckIn.call_type'=>'FOLLOW UP'])->all();
                
                if ($previousFollowUp) {
                    foreach($previousFollowUp as $row){
                        $this->DataOtherServicesCheckIn->updateAll(
                            ['status' => 'DONE'], 
                            ['id' => $row['id']]
                            );
                    }          
                }
                
                $arr_save_follow_up = array(
                    'uid' => Text::uuid(),
                    'consultation_uid' => $consultation_uid,
                    'patient_id' => USER_ID,
                    'call_date' => date('Y-m-d'),
                    'status' => 'PENDING',
                    'call_number' => $followUpCallNumber,
                    'call_type' => 'FOLLOW UP',
                    'call_title' => $followUpTitle,
                    'created' => date('Y-m-d H:i:s'),
                );
    
                $cq_entity_follow_up = $this->DataOtherServicesCheckIn->newEntity($arr_save_follow_up);
                if(!$cq_entity_follow_up->hasErrors()){
                    $this->DataOtherServicesCheckIn->save($cq_entity_follow_up);
                }
            } else {
                $arr_save_2 = array(
                    'uid' => Text::uuid(),
                    'consultation_uid' => $consultation_uid,
                    'patient_id' => USER_ID,
                    'call_date' => date('Y-m-d', strtotime(date('Y-m-d') . ' +15 days')),
                    'status' => 'PENDING',
                    'call_number' => intval($call_number)+1,
                    'call_type' => $call_type,
                    'call_title' => $next_call_title,
                    'created' => date('Y-m-d H:i:s'),
                );
    
                $cq_entity_2 = $this->DataOtherServicesCheckIn->newEntity($arr_save_2);
                if(!$cq_entity_2->hasErrors()){
                    $this->DataOtherServicesCheckIn->save($cq_entity_2);
                }
            }
            

        } 

        if ($call_type=='FOLLOW UP'){


            
            $previousFollowUp = $this->DataOtherServicesCheckIn->find()->select(['DataOtherServicesCheckIn.id'])
                    ->where(['DataOtherServicesCheckIn.consultation_uid' => $consultation_uid, 
                            'DataOtherServicesCheckIn.deleted' => 0, 
                            'DataOtherServicesCheckIn.call_type'=>'FOLLOW UP'])->all();
            
            
            

        }
        
        $this->success();
    }*/

    public function save_telehealth_call_dates(){

        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.DataPurchasesOtherServices');
        $this->loadModel('SpaLiveV1.DataPurchases');

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

        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $this->message('consultation_uid not found.');
            return;
        }

        $call_number = get('call_number', '');
        if (empty($call_number)) {
            $this->message('call_number not found.');
            return;
        }

        $call_type = get('call_type','');
        if (empty($call_type)) {
            $this->message('call_type not found.');
            return;
        }

        $show=0;
        $call_number_checkin=0;
        $call_number_followup=0;
        $nextCallNumber=0;
        if ($call_number == '1' && $call_type=='FIRST CONSULTATION') {
            $callTitle = 'First Check In';
            $followUpTitle = 'First Follow Up';
            $previousCallType = 'FIRST CONSULTATION';
            $previousCallNumber = 1;
            $nextCallNumber=$previousCallNumber+1;
            $call_number_followup=1;
            //$show=1;
        } elseif ($call_number == '1' && $call_type == 'FOLLOW UP') {
            $callTitle = 'Third Check In';
            $followUpTitle = 'Second Follow Up';
            $previousCallType = 'CHECK IN';
            $previousCallNumber = 3;
            $nextCallNumber = $previousCallNumber+1;
            $call_number_followup=2;

            /*$previousCheckIns = $this->DataOtherServicesCheckIn->find()
            ->select(['DataOtherServicesCheckIn.id'])
            ->where(['DataOtherServicesCheckIn.consultation_uid' => $consultation_uid,
                    'DataOtherServicesCheckIn.call_type' => 'CHECK IN',
                    'DataOtherServicesCheckIn.show' => 1, 
                    'DataOtherServicesCheckIn.deleted' => 0])->all();
        
            if ($previousCheckIns) {
                foreach($previousCheckIns as $row){
                    $this->DataOtherServicesCheckIn->updateAll(
                        ['show' => 0], 
                        ['id' => $row['id']]
                     );
                }          
            }*/
            //$show=1;

        } elseif ($call_number == '2' && $call_type == 'FOLLOW UP'){
            $callTitle = 'Fifth Check In';
            $followUpTitle = 'N/A'; 
            $previousCallType = 'CHECK IN';
            $previousCallNumber = 5;
            $nextCallNumber = $previousCallNumber+1;
            /*$previousCheckIns = $this->DataOtherServicesCheckIn->find()
            ->select(['DataOtherServicesCheckIn.id'])
            ->where(['DataOtherServicesCheckIn.consultation_uid' => $consultation_uid,
                    'DataOtherServicesCheckIn.call_type' => 'CHECK IN',
                    'DataOtherServicesCheckIn.show' => 1, 
                    'DataOtherServicesCheckIn.deleted' => 0])->all();
        
            if ($previousCheckIns) {
                foreach($previousCheckIns as $row){
                    $this->DataOtherServicesCheckIn->updateAll(
                        ['show' => 0], 
                        ['id' => $row['id']]
                     );
                }          
            }*/
            //$show=1;
        } 

        $call_title_firebase = "";
        $call_type_firebase="";

        switch ($callTitle) {
            case 'First Check In':
                $call_number_checkin=1;
                $call_title_firebase = "First Check In";
                $call_type_firebase="CHECK IN";
                break;
            case 'Second Check In':
                $call_number_checkin=2;
                $call_title_firebase = "Second Check In";
                $call_type_firebase="CHECK IN";
                break;
            case 'Third Check In':
                $call_number_checkin=3;
                $call_title_firebase = "Third Check In";
                $call_type_firebase="CHECK IN";
                break;
            case 'Fourth Check In':
                $call_number_checkin=4;
                $call_title_firebase = "Fourth Check In";
                $call_type_firebase="CHECK IN";
                break;
            case 'Fifth Check In':
                $call_number_checkin=5;
                $call_title_firebase = "Fifth Check In";
                $call_type_firebase="CHECK IN";
                break;
            case 'Sixth Check In':
                $call_number_checkin=6;
                $call_title_firebase = "Sixth Check In";
                $call_type_firebase="CHECK IN";
                break;
        }
        
        //guarda check in 1, 3 y 5
        $arr_save_call_1 = [
            'uid' => Text::uuid(),
            'consultation_uid' => $consultation_uid,
            'patient_id' => USER_ID,
            'call_date' => date('Y-m-d', strtotime(date('Y-m-d') . ' +7 days')),
            'status' => 'PENDING',
            'call_number' => $previousCallNumber,
            'call_type' => 'CHECK IN',
            'call_title' => $callTitle,
            //'show' => $callTitle === 'First Check In' ? 1 : $show,
            'show' => 1,
            'created' => date('Y-m-d H:i:s'),
        ];
        
        //Guarda check in 2 y 4
        if($call_number!=2){
            $arr_save_call_2 = [
            'uid' => Text::uuid(),
            'consultation_uid' => $consultation_uid,
            'patient_id' => USER_ID,
            'call_date' => date('Y-m-d', strtotime(date('Y-m-d') . ' +15 days')),
            'status' => 'PENDING',
            'call_number' => $nextCallNumber,
            'call_type' => 'CHECK IN',
            'call_title' => $callTitle === 'First Check In' ? 'Second Check In' : 'Fourth Check In',
            'show' => 0,
            'created' => date('Y-m-d H:i:s'),
        ];
        }
        
        
        if($followUpTitle != 'N/A'){
            $call_type_firebase="FOLLOW UP";
            $arr_save_follow = [
                'uid' => Text::uuid(),
                'consultation_uid' => $consultation_uid,
                'patient_id' => USER_ID,
                'call_date' => date('Y-m-d', strtotime(date('Y-m-d') . ' +25 days')),
                'status' => 'PENDING',
                'call_number' => $call_number_followup,
                'call_type' => 'FOLLOW UP',
                'call_title' => $followUpTitle,
                'show' => 0,
                'created' => date('Y-m-d H:i:s'),
            ];
        }
        
        
        $arr_save_sixth = [
            'uid' => Text::uuid(),
            'consultation_uid' => $consultation_uid,
            'patient_id' => USER_ID,
            'call_date' => date('Y-m-d', strtotime(date('Y-m-d') . ' +15 days')), // Adjust the number of days according to your requirements
            'status' => 'PENDING',
            'call_number' => $nextCallNumber,
            'call_type' => 'CHECK IN',
            'call_title' => 'Sixth Check In',
            'show' => 0,
            'created' => date('Y-m-d H:i:s'),
        ];
        
        $cq_entity_1 = $this->DataOtherServicesCheckIn->newEntity($arr_save_call_1);
        if($call_number!=2){
            $cq_entity_2 = $this->DataOtherServicesCheckIn->newEntity($arr_save_call_2);
        }
        if($followUpTitle != 'N/A'){
            $cq_entity_follow = $this->DataOtherServicesCheckIn->newEntity($arr_save_follow);
        }
        $cq_entity_sixth = $this->DataOtherServicesCheckIn->newEntity($arr_save_sixth);
        
        if (!$cq_entity_1->hasErrors()) {
            $this->DataOtherServicesCheckIn->save($cq_entity_1);
        }
         if($call_number!=2){
            if (!$cq_entity_2->hasErrors()) {
                $this->DataOtherServicesCheckIn->save($cq_entity_2);
            }
        }
        if($followUpTitle != 'N/A'){
            if (!$cq_entity_follow->hasErrors()) {
                $this->DataOtherServicesCheckIn->save($cq_entity_follow);
            }
        }
        
        if ($call_number == 2 && !$cq_entity_sixth->hasErrors()) {
            $this->DataOtherServicesCheckIn->save($cq_entity_sixth);
        }

        //Guarda en purchases
        $previousPurchase = $this->DataPurchases->find()
            ->select(['DataPurchases.id'])
            ->where([
                'DataPurchases.uid' => $consultation_uid,
                'DataPurchases.call_type' => $previousCallType,
                'DataPurchases.call_number' => $call_number,
                'DataPurchases.deleted' => 0
            ])
            ->all();
        
        if ($previousPurchase) {
            foreach ($previousPurchase as $row) {
                $this->DataPurchases->updateAll(
                    [
                        'status' => 'RECEIVED',
                        'received_date' => date('Y-m-d'),
        
                    ],
                    ['id' => $row['id']]
                );
            }
        }
        

        /// Guarda en purchases other services
        $previousPurchaseOS = $this->DataPurchasesOtherServices->find()
            ->select(['DataPurchasesOtherServices.id'])
            ->where([
                'DataPurchasesOtherServices.consultation_uid' => $consultation_uid,
                'DataPurchasesOtherServices.call_type' => $previousCallType,
                'DataPurchasesOtherServices.call_number' => $call_number,
                'DataPurchasesOtherServices.deleted' => 0
            ])
            ->all();
        
        if ($previousPurchaseOS) {
            foreach ($previousPurchaseOS as $row) {
                $this->DataPurchasesOtherServices->updateAll(
                    [
                        'status' => 'RECEIVED',
                        'received_date' => date('Y-m-d'),
        
                    ],
                    ['id' => $row['id']]
                );
            }
        }

        $firebase_update_data = array(
            "uid" => $consultation_uid,
            "call_title" => $call_title_firebase, 
            "call_type" => $call_type_firebase,
        );
        
        //print_r($data);exit;
        $data_string = json_encode($firebase_update_data);
        $this->set('firebase_update_data', $firebase_update_data);
        $url = env('URL_MICROSERVICE', 'http://localhost:3131/') . 'updateServicesArr';
        $ch=curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);//curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER,
            array(
                'Content-Type:application/json',
                'Content-Length: ' . strlen($data_string)
            )
        );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);

        $this->set('result_curl', $result);
        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            $this->message($error_msg);
            $this->success(false);
            return;
            // this would be your first hint that something went wrong                            
        } else {
            // check the HTTP status code of the request
            $resultStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($resultStatus == 200) {
                //unlink($filename);// everything went better than expected
            } else {
                // the request did not complete as expected. common errors are 4xx
                // (not found, bad request, etc.) and 5xx (usually concerning
                // errors/exceptions in the remote script execution)                                
            }
        }
        curl_close($ch);

        $this->success();
        
    }

    public function save_calls_records(){
        //Nueva funcion para los wl specialists

        $is_dev = env('IS_DEV', false);
        if (!$is_dev) {
            $this->save_telehealth_call_dates_gage();
            return;
        }

        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.DataPurchasesOtherServices');
        $this->loadModel('SpaLiveV1.DataPurchases');

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

        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $this->message('consultation_uid not found.');
            return;
        }

        //% Call_number puede llegar 1 (First consultation), 1 (First Follow Up), y 2 (Second Follow Up)
        //% Call_number puede llegar 1 (First consultation), 2 (Second Check in), y 4 (Fourth Check in)
        $call_number = get('call_number', '');
        if (empty($call_number)) {
            $this->message('call_number not found.');
            return;
        }

        $call_type = get('call_type','');
        if (empty($call_type)) {
            $this->message('call_type not found.');
            return;
        }

        //% Conocer el mes en base a call_number
        $month = 0;
        switch ($call_number) {
            case '1':
                if ($call_type=='FIRST CONSULTATION') {
                    $month = 1;
                } else if ($call_type == 'FOLLOW UP') {
                    $month = 2;
                }
                break;
            case '2':
                $month = 3;
                break;
        }

        $first_call = [];
        $second_call = [];
        $follow_up = [];
        
        //% Dependiendo del mes, se establecen los array de las llamadas
        switch ($month) {
            case 1:
                //% Primera llamada (Check in 1)
                $first_call = [
                    'uid' => Text::uuid(),
                    'consultation_uid' => $consultation_uid,
                    'patient_id' => USER_ID,
                    'call_date' => date('Y-m-d', strtotime(date('Y-m-d') . ' +7 days')),
                    'status' => 'CLAIM_PENDING',
                    'call_number' => 1,
                    'call_type' => 'CHECK IN',
                    'call_title' => 'First Check In',
                    'show' => 1,
                    'created' => date('Y-m-d H:i:s'),
                ];
                //% Segunda llamada (Check in 2)
                $second_call = [
                    'uid' => Text::uuid(),
                    'consultation_uid' => $consultation_uid,
                    'patient_id' => USER_ID,
                    'call_date' => date('Y-m-d', strtotime(date('Y-m-d') . ' +15 days')),
                    'status' => 'CLAIM_PENDING',
                    'call_number' => 2,
                    'call_type' => 'CHECK IN',
                    'call_title' => 'Second Check In',
                    'show' => 0,
                    'created' => date('Y-m-d H:i:s'),
                ];
                //% First Follow Up
                $follow_up = [
                    'uid' => Text::uuid(),
                    'consultation_uid' => $consultation_uid,
                    'patient_id' => USER_ID,
                    'call_date' => date('Y-m-d', strtotime(date('Y-m-d') . ' +25 days')),
                    'status' => 'WAITING',
                    'call_number' => 1,
                    'call_type' => 'FOLLOW UP',
                    'call_title' => 'First Follow Up',
                    'show' => 0,
                    'created' => date('Y-m-d H:i:s'),
                ];
                break;
            case 2:
                //% Tercera llamada (Check in 3)
                $first_call = [
                    'uid' => Text::uuid(),
                    'consultation_uid' => $consultation_uid,
                    'patient_id' => USER_ID,
                    'call_date' => date('Y-m-d', strtotime(date('Y-m-d') . ' +7 days')),
                    'status' => 'CLAIM_PENDING',
                    'call_number' => 3,
                    'call_type' => 'CHECK IN',
                    'call_title' => 'Third Check In',
                    'show' => 1,
                    'created' => date('Y-m-d H:i:s'),
                ];
                //% Cuarta llamada (Check in 4)
                $second_call = [
                    'uid' => Text::uuid(),
                    'consultation_uid' => $consultation_uid,
                    'patient_id' => USER_ID,
                    'call_date' => date('Y-m-d', strtotime(date('Y-m-d') . ' +15 days')),
                    'status' => 'CLAIM_PENDING',
                    'call_number' => 4,
                    'call_type' => 'CHECK IN',
                    'call_title' => 'Fourth Check In',
                    'show' => 0,
                    'created' => date('Y-m-d H:i:s'),
                ];
                //% Second Follow Up
                $follow_up = [
                    'uid' => Text::uuid(),
                    'consultation_uid' => $consultation_uid,
                    'patient_id' => USER_ID,
                    'call_date' => date('Y-m-d', strtotime(date('Y-m-d') . ' +25 days')),
                    'status' => 'WAITING',
                    'call_number' => 2,
                    'call_type' => 'FOLLOW UP',
                    'call_title' => 'Second Follow Up',
                    'show' => 0,
                    'created' => date('Y-m-d H:i:s'),
                ];
                break;
            case 3:
                //% Quinta llamada (Check in 5)
                $first_call = [
                    'uid' => Text::uuid(),
                    'consultation_uid' => $consultation_uid,
                    'patient_id' => USER_ID,
                    'call_date' => date('Y-m-d', strtotime(date('Y-m-d') . ' +7 days')),
                    'status' => 'CLAIM_PENDING',
                    'call_number' => 5,
                    'call_type' => 'CHECK IN',
                    'call_title' => 'Fifth Check In',
                    'show' => 1,
                    'created' => date('Y-m-d H:i:s'),
                ];
                //% Sexta llamada (Check in 6)
                $second_call = [
                    'uid' => Text::uuid(),
                    'consultation_uid' => $consultation_uid,
                    'patient_id' => USER_ID,
                    'call_date' => date('Y-m-d', strtotime(date('Y-m-d') . ' +15 days')),
                    'status' => 'CLAIM_PENDING',
                    'call_number' => 6,
                    'call_type' => 'CHECK IN',
                    'call_title' => 'Sixth Check In',
                    'show' => 0,
                    'created' => date('Y-m-d H:i:s'),
                ];
                //% No hay follow up, se termina el proceso
                break;
        }
        
        //%Guardar las llamadas

        $cq_entity_1 = $this->DataOtherServicesCheckIn->newEntity($first_call);
        if (!$cq_entity_1->hasErrors()) {
            $this->DataOtherServicesCheckIn->save($cq_entity_1);
        } else {
            $this->message('Error saving first call for the month.');
            return;
        }

        $cq_entity_2 = $this->DataOtherServicesCheckIn->newEntity($second_call);
        if (!$cq_entity_2->hasErrors()) {
            $this->DataOtherServicesCheckIn->save($cq_entity_2);
        } else {
            $this->message('Error saving second call for the month.');
            return;
        }

        //% El follow up solo se guarda en el mes 1 y 2
        if (!empty($follow_up)) {

            $cq_entity_follow = $this->DataOtherServicesCheckIn->newEntity($follow_up);
            if (!$cq_entity_follow->hasErrors()) {
                $this->DataOtherServicesCheckIn->save($cq_entity_follow);
            } else {
                $this->message('Error saving follow up for the month.');
                return;
            }

        }

        //Consultation the last call record
        $data_consultation = $this->DataOtherServicesCheckIn->find()
            ->where([
                'DataOtherServicesCheckIn.consultation_uid' => $consultation_uid,
                'DataOtherServicesCheckIn.deleted' => 0,
                'DataOtherServicesCheckIn.call_type' => 'CHECK IN', 
            ])
            ->order(['DataOtherServicesCheckIn.call_date' => 'DESC'])
            ->all();

        $this->loadModel('SpaLiveV1.DataReferredOtherServices');
        
        // El usuario referido puede no ser especialista
        $data_refered = $this->DataReferredOtherServices->find()
        ->join([
            'Specialist' => [
                    'table' => 'data_users_other_services_check_in', 
                    'type' => 'LEFT', 
                    'conditions' => 'Specialist.id = DataReferredOtherServices.referred_id'
                ]
        ])
        ->where([
            'DataReferredOtherServices.user_id' => USER_ID,
            'DataReferredOtherServices.deleted' => 0,
            'Specialist.status' => 'WLSHOME'
        ])
        ->first();
                
        //% Si el paciente refirio a un especialsita, se le asigna los check in
        //% si no, se le asigna al primer especialista disponible
        if (!empty($data_refered)) {

            foreach ($data_consultation as $call) {
                
                $call_times = $this->get_next_available_date($data_refered->referred_id, $call);
                
                $result = $this->DataOtherServicesCheckIn->updateAll(
                    [
                        'support_id' => $data_refered->referred_id,
                        'call_date' => $call_times['call_date'],
                        'call_time' => $call_times['call_time'],
                        'status' => 'CLAIMED'
                    ],
                    ['id' => $call->id]
                );
    
                if ($result === false) {
                    // La actualización falló
                    $this->message('Error updating the call record.');
                    return;
                }
            }

        } else {

            //% Buscamos especialista
            $result = $this->automatic_assign_calls(USER_ID, $consultation_uid);

            if ($result === false) {
                // La actualización falló
                $this->message('Error updating the call record.');
                return;
            }
        }

        #region save firebase
 
        //% Actualizamos la informacion en firebase de la siguiente llamada (Check in 1, 3, 5),
        //% para actualizar los siguientes check in, se hace en la funcion !! rate_call !!
        
        //Consultation the last call record
        $next_call_record = $this->DataOtherServicesCheckIn->find()
            ->select([
                    'DataOtherServicesCheckIn.id', 
                    'DataOtherServicesCheckIn.consultation_uid', 
                    'DataOtherServicesCheckIn.call_title', 
                    'DataOtherServicesCheckIn.call_type', 
                    'DataOtherServicesCheckIn.status', 
                    'DataOtherServicesCheckIn.call_date', 
                    'DataOtherServicesCheckIn.call_time',
                    'DataOtherServicesCheckIn.support_id',
                    'Injector.uid'
                ])
            ->join([
                'Injector' => [
                        'table' => 'sys_users', 
                        'type' => 'INNER', 
                        'conditions' => 'Injector.id = DataOtherServicesCheckIn.support_id'
                    ]
            ])
            ->where([
                'DataOtherServicesCheckIn.consultation_uid' => $consultation_uid,
                'DataOtherServicesCheckIn.deleted' => 0,
                'DataOtherServicesCheckIn.show' => 1 //? This is the flag to know if the call is active or not
            ])
            ->order(['DataOtherServicesCheckIn.call_date' => 'DESC'])
            ->first();

        if($next_call_record->call_type == "CHECK IN"){
            $concat_date_time = $next_call_record->call_date . ' ' . date_format($next_call_record->call_time, 'h:i A');
            $call_date = date('m/d/Y H:i A', strtotime($concat_date_time));

            $firebase_update_data = array(
                "call_id"           => strval($next_call_record->id),
                "uid"               => $next_call_record->consultation_uid,
                "call_title"        => $next_call_record->call_title,
                "call_type"         => $next_call_record->call_type,
                "status"            => $next_call_record->status,
                "scheduled_date"    => $call_date,
                "examiner_uid"      => $next_call_record['Injector']['uid'],
            );

            // Validar que se asigno el injector que lo recomendo
            $this->loadModel('SpaLiveV1.DataReferredOtherServices');
            
            if (!empty($firebase_update_data)) {
                
                //print_r($data);exit;
                $data_string = json_encode($firebase_update_data);
                $this->set('firebase_update_data', $firebase_update_data);
                $url = env('URL_MICROSERVICE', 'http://localhost:3131/') . 'updateServicesArr';
                $ch=curl_init($url);
                curl_setopt($ch, CURLOPT_POST, true);//curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
                curl_setopt($ch, CURLOPT_HEADER, false);
                curl_setopt($ch, CURLOPT_HTTPHEADER,
                    array(
                        'Content-Type:application/json',
                        'Content-Length: ' . strlen($data_string)
                    )
                );
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                $result = curl_exec($ch);

                $this->set('result_curl', $result);
                if (curl_errno($ch)) {
                    $error_msg = curl_error($ch);
                    $this->message($error_msg);
                    $this->success(false);
                    return;
                    // this would be your first hint that something went wrong                            
                } else {
                    // check the HTTP status code of the request
                    $resultStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    if ($resultStatus == 200) {
                        //unlink($filename);// everything went better than expected
                    } else {
                        // the request did not complete as expected. common errors are 4xx
                        // (not found, bad request, etc.) and 5xx (usually concerning
                        // errors/exceptions in the remote script execution)                                
                    }
                }
                curl_close($ch);
                
            }
        }

        #endregion
        
        $Main = new MainController();
        $Main->notify_devices('NEW_CHECK_IN_AVAILABLE',array($next_call_record->support_id),false,false,true,array(),'',array(),true);
        $this->success();
        
    }

    public function save_calls_weight_loss(){
        //Nueva funcion para los wl specialists, OKAY SIS? ⚠️
        
        $panel = get('l3n4p', '');
        $token = get('token',"");

        if(empty($panel) || (!empty($panel) && $panel != '6092482f7ce858.91169218')){

            $token = get('token',"");

            if(!empty($token)){
                $user = $this->AppToken->validateToken($token, true);
                if($user === false){
                    $this->message('Invalid token.');
                    $this->set('session', false);
                    return;
                }
                $this->set('session', true);

                $_userid = USER_ID;
            } else {
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
        }


        //% Call_number puede llegar 1 (First consultation), 1 (First Follow Up), y 2 (Second Follow Up)
        $call_number = get('call_number', '');
        if (empty($call_number)) {
            $this->message('call_number not found.');
            return;
        }

        $call_type = get('call_type','');
        if (empty($call_type)) {
            $this->message('call_type not found.');
            return;
        }

        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $this->message('consultation_uid not found.');
            return;
        }

        $patient_id = get('patient_id', 0);

        if($patient_id == 0){
            if(defined('USER_ID')){
                $patient_id = USER_ID;
            }else{
                $this->message('patient_id not found.');
                return;
            }
        }
        
        $this->loadModel('SpaLiveV1.DataUsersFreeWl');

        $user_free = $this->DataUsersFreeWl->find()
            ->where([
                'DataUsersFreeWl.user_id' => $patient_id,
                'DataUsersFreeWl.deleted' => 0
            ])
            ->first();

        // CREATE SOME CALLS BEFORE ANYTHING ELSE, THE CHECK INS WITHOUT THE FOLLOW UP.

        $register_calls_result = $this->create_call_records(
            $consultation_uid, 
            $call_number, 
            $call_type,
            $patient_id,
            !empty($user_free) ? true : false
        );        

        if ($register_calls_result === false) {
            $this->message('Error creating the call records.');
            return;
        }

        // UPDATE THE CALL REGISTERS WITH THE REFERRED ID, OKAY SIS? ⚠️

        // no ejecutar si el paciente es free user

        if(empty($user_free)){
            $update_referred_result = $this->set_call_referred_v2($consultation_uid);

            if (is_string($update_referred_result)) {
                $this->message($update_referred_result);
                return;
            }
            if ($update_referred_result === false) {
                $this->message('Error updating the referred.');
                return;
            }

            //Consultation the last call record
            $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
            $next_call_record = $this->DataOtherServicesCheckIn->find()
                ->select([
                        'DataOtherServicesCheckIn.id', 
                        'DataOtherServicesCheckIn.consultation_uid', 
                        'DataOtherServicesCheckIn.call_title', 
                        'DataOtherServicesCheckIn.call_type', 
                        'DataOtherServicesCheckIn.status', 
                        'DataOtherServicesCheckIn.call_date', 
                        'DataOtherServicesCheckIn.call_time',
                        'DataOtherServicesCheckIn.support_id',
                        'Injector.uid'
                    ])
                ->join([
                    'Injector' => [
                            'table' => 'sys_users', 
                            'type' => 'INNER', 
                            'conditions' => 'Injector.id = DataOtherServicesCheckIn.support_id'
                        ]
                ])
                ->where([
                    'DataOtherServicesCheckIn.consultation_uid' => $consultation_uid,
                    'DataOtherServicesCheckIn.deleted' => 0,
                    'DataOtherServicesCheckIn.show' => 1 //? This is the flag to know if the call is active or not
                ])
                ->order(['DataOtherServicesCheckIn.call_date' => 'DESC'])
                ->first();

            #region save firebase
    
            //% Actualizamos la informacion en firebase de la siguiente llamada (Check in 1, 3, 5),
            //% para actualizar los siguientes check in, se hace en la funcion !! rate_call !!            

            // if($next_call_record->call_type == "CHECK IN"){
            //     $concat_date_time = $next_call_record->call_date . ' ' . date_format($next_call_record->call_time, 'h:i A');
            //     $call_date = date('m/d/Y H:i A', strtotime($concat_date_time));
            //     $firebase_update_data = array(
            //         "call_id"           => strval($next_call_record->id),
            //         "uid"               => $next_call_record->consultation_uid,
            //         "call_title"        => $next_call_record->call_title,
            //         "call_type"         => $next_call_record->call_type,
            //         "status"            => $next_call_record->status == 'CLAIM_PENDING' ? 'CLAIM PENDING' : $next_call_record->status,
            //         "scheduled_date"    => $call_date,
            //         "examiner_uid"      => $next_call_record['Injector']['uid'],
            //         "has_images"        => false,
            //         //"examiner_uid"      => '',
            //     );

            //     // Validar que se asigno el injector que lo recomendo
            //     $this->loadModel('SpaLiveV1.DataReferredOtherServices');
                
            //     if (!empty($firebase_update_data)) {
                    
            //         //print_r($data);exit;
            //         $data_string = json_encode($firebase_update_data);
            //         $this->set('firebase_update_data', $firebase_update_data);
            //         $url = env('URL_MICROSERVICE', 'http://localhost:3131/') . 'updateServicesArr';
            //         $ch=curl_init($url);
            //         curl_setopt($ch, CURLOPT_POST, true);//curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            //         curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
            //         curl_setopt($ch, CURLOPT_HEADER, false);
            //         curl_setopt($ch, CURLOPT_HTTPHEADER,
            //             array(
            //                 'Content-Type:application/json',
            //                 'Content-Length: ' . strlen($data_string)
            //             )
            //         );
            //         curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            //         $result = curl_exec($ch);

            //         $this->set('result_curl', $result);
            //         if (curl_errno($ch)) {
            //             $error_msg = curl_error($ch);
            //             $this->message($error_msg);
            //             $this->success(false);
            //             return;
            //             // this would be your first hint that something went wrong                            
            //         } else {
            //             // check the HTTP status code of the request
            //             $resultStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            //             if ($resultStatus == 200) {
            //                 //unlink($filename);// everything went better than expected
            //             } else {
            //                 // the request did not complete as expected. common errors are 4xx
            //                 // (not found, bad request, etc.) and 5xx (usually concerning
            //                 // errors/exceptions in the remote script execution)                                
            //             }
            //         }
            //         curl_close($ch);
                    
            //     }
            // }

            #endregion
            
            $Main = new MainController();
            $Main->notify_devices('NEW_CHECK_IN_AVAILABLE',array($next_call_record->support_id),false,false,true,array(),'',array(),true);
        }
        $this->set('purchases', $this->get_purchases_consultation($consultation_uid));
        $this->success();
    }

    public function return_create_calls_records($consultation_uid, $patient_id) {
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');

        //% Search the last call record that don't work
        $found_calls = $this->DataOtherServicesCheckIn->find()
            ->where([
                'DataOtherServicesCheckIn.status NOT IN ' => ['COMPLETED', 'PENDING EVALUATION'],
                'DataOtherServicesCheckIn.call_type' => 'CHECK IN', 
                'DataOtherServicesCheckIn.consultation_uid' => $consultation_uid, 
                'DataOtherServicesCheckIn.deleted' => 0
            ])
            ->order(['DataOtherServicesCheckIn.call_date' => 'DESC'])
            ->all();

        if (count($found_calls) > 0) {
            foreach ($found_calls as $call) {
                $this->DataOtherServicesCheckIn->updateAll(
                    [
                        'deleted' => 1,
                    ],
                    ['id' => $call->id]
                );
            }
            
        }

        //% Buscar la ultima purshase que no se haya completado
        $this->loadModel('SpaLiveV1.DataPurchases');
        $found_purshase =  $this->DataPurchases->find()
            ->where([
                'DataPurchases.status' => 'RECEIVED', 
                'DataPurchases.user_id' => $patient_id,
                'DataPurchases.deleted' => 0
            ])
            ->order(['DataPurchases.id' => 'DESC'])
            ->first();

        if (!empty($found_purshase)) {
            $this->DataPurchases->updateAll(
                [
                    'status' => 'WAITING TO RECEIVE THE PRODUCT',
                    'received_date' => null,
                    'signature' => null,
                ],
                ['id' => $found_purshase->id]
            );
        }

        $this->set('return_calls', 'Return create calls records successfully.');
    }
    
    public function test_purchases(){
        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $this->message('consultation_uid not found.');
            return;
        }
        $this->set('purchases', $this->get_purchases_consultation($consultation_uid));
        $this->success();
    }    

    public function create_call_records(
        $consultation_uid,
        $call_number,
        $call_type,
        $patient_id,
        $is_free_user = false
    ){
        //% Conocer el mes en base a call_number
        $month = 0;
        switch (intval($call_number)) {
            case 1:
                $month = 1;
                break;
            case 2:
                $month = 2;
                break;
            case 4:
                $month = 3;
                break;
            default:
                $this->message('Invalid call number.');
                return false;
        }

        $purchase_id = 0;

        $purchases = $this->get_purchases_consultation($consultation_uid);

        $arr_calls = [];

        if ($is_free_user) {
            switch($month){
                case 1:
                    //% Primera llamada (Check in 1)
                    $first_call = [
                        'uid' => Text::uuid(),
                        'consultation_uid' => $consultation_uid,
                        'patient_id' => $patient_id,
                        'call_date' => date('Y-m-d'),
                        'status' => 'COMPLETED',
                        'call_number' => 1,
                        'call_type' => 'CHECK IN',
                        'call_title' => 'First Check In',
                        'show' => 1,
                        'created' => date('Y-m-d H:i:s'),
                        'call_time' => date('H:i:s'),
                    ];
                    //% Segunda llamada (Check in 2)
                    $second_call = [
                        'uid' => Text::uuid(),
                        'consultation_uid' => $consultation_uid,
                        'patient_id' => $patient_id,
                        'call_date' => date('Y-m-d'),
                        'status' => 'COMPLETED',
                        'call_number' => 2,
                        'call_type' => 'CHECK IN',
                        'call_title' => 'Second Check In',
                        'show' => 1,
                        'created' => date('Y-m-d H:i:s'),
                        'purchase_id' => $purchases[1]->id,
                        'call_time' => date('H:i:s'),
                    ];
                    break;
                case 2:
                    //% Tercera llamada (Check in 3)
                    $first_call = [
                        'uid' => Text::uuid(),
                        'consultation_uid' => $consultation_uid,
                        'patient_id' => $patient_id,
                        'call_date' => date('Y-m-d'),
                        'status' => 'COMPLETED',
                        'call_number' => 3,
                        'call_type' => 'CHECK IN',
                        'call_title' => 'Third Check In',
                        'show' => 1,
                        'created' => date('Y-m-d H:i:s'),
                        'call_time' => date('H:i:s'),
                    ];
                    //% Cuarta llamada (Check in 4)
                    $second_call = [
                        'uid' => Text::uuid(),
                        'consultation_uid' => $consultation_uid,
                        'patient_id' => $patient_id,
                        'call_date' => date('Y-m-d'),
                        'status' => 'COMPLETED',
                        'call_number' => 4,
                        'call_type' => 'CHECK IN',
                        'call_title' => 'Fourth Check In',
                        'show' => 1,
                        'created' => date('Y-m-d H:i:s'),
                        'purchase_id' => $purchases[2]->id,
                        'call_time' => date('H:i:s'),
                    ];
                    break;
                case 3:
                    //% Quinta llamada (Check in 5)
                    $first_call = [
                        'uid' => Text::uuid(),
                        'consultation_uid' => $consultation_uid,
                        'patient_id' => $patient_id,
                        'call_date' => date('Y-m-d'),
                        'status' => 'COMPLETED',
                        'call_number' => 5,
                        'call_type' => 'CHECK IN',
                        'call_title' => 'Fifth Check In',
                        'show' => 1,
                        'created' => date('Y-m-d H:i:s'),
                        'call_time' => date('H:i:s'),
                    ];
                    //% Sexta llamada (Check in 6)
                    $second_call = [
                        'uid' => Text::uuid(),
                        'consultation_uid' => $consultation_uid,
                        'patient_id' => $patient_id,
                        'call_date' => date('Y-m-d'),
                        'status' => 'COMPLETED',
                        'call_number' => 6,
                        'call_type' => 'CHECK IN',
                        'call_title' => 'Sixth Check In',
                        'show' => 1,
                        'created' => date('Y-m-d H:i:s'),
                        'call_time' => date('H:i:s'),
                    ];
                    break;
            }
        }else{
            switch($month){
                case 1:
                    //% Primera llamada (Check in 1)
                    $first_call = [
                        'uid' => Text::uuid(),
                        'consultation_uid' => $consultation_uid,
                        'patient_id' => $patient_id,
                        'call_date' => date('Y-m-d', strtotime(date('Y-m-d') . ' +7 days')),
                        'status' => 'CLAIM_PENDING',
                        'call_number' => 1,
                        'call_type' => 'CHECK IN',
                        'call_title' => 'First Check In',
                        'show' => 1,
                        'created' => date('Y-m-d H:i:s'),
                    ];
                    //% Segunda llamada (Check in 2)
                    $second_call = [
                        'uid' => Text::uuid(),
                        'consultation_uid' => $consultation_uid,
                        'patient_id' => $patient_id,
                        'call_date' => date('Y-m-d', strtotime(date('Y-m-d') . ' +15 days')),
                        'status' => 'CLAIM_PENDING',
                        'call_number' => 2,
                        'call_type' => 'CHECK IN',
                        'call_title' => 'Second Check In',
                        'show' => 0,
                        'created' => date('Y-m-d H:i:s'),
                        'purchase_id' => $purchases[1]->id
                    ];
                    break;
                case 2:
                    //% Tercera llamada (Check in 3)
                    $first_call = [
                        'uid' => Text::uuid(),
                        'consultation_uid' => $consultation_uid,
                        'patient_id' => $patient_id,
                        'call_date' => date('Y-m-d', strtotime(date('Y-m-d') . ' +7 days')),
                        'status' => 'CLAIM_PENDING',
                        'call_number' => 3,
                        'call_type' => 'CHECK IN',
                        'call_title' => 'Third Check In',
                        'show' => 1,
                        'created' => date('Y-m-d H:i:s'),
                    ];
                    //% Cuarta llamada (Check in 4)
                    $second_call = [
                        'uid' => Text::uuid(),
                        'consultation_uid' => $consultation_uid,
                        'patient_id' => $patient_id,
                        'call_date' => date('Y-m-d', strtotime(date('Y-m-d') . ' +15 days')),
                        'status' => 'CLAIM_PENDING',
                        'call_number' => 4,
                        'call_type' => 'CHECK IN',
                        'call_title' => 'Fourth Check In',
                        'show' => 0,
                        'created' => date('Y-m-d H:i:s'),
                        'purchase_id' => $purchases[2]->id
                    ];
                    break;
                case 3:
                    //% Quinta llamada (Check in 5)
                    $first_call = [
                        'uid' => Text::uuid(),
                        'consultation_uid' => $consultation_uid,
                        'patient_id' => $patient_id,
                        'call_date' => date('Y-m-d', strtotime(date('Y-m-d') . ' +7 days')),
                        'status' => 'CLAIM_PENDING',
                        'call_number' => 5,
                        'call_type' => 'CHECK IN',
                        'call_title' => 'Fifth Check In',
                        'show' => 1,
                        'created' => date('Y-m-d H:i:s'),
                    ];
                    //% Sexta llamada (Check in 6)
                    $second_call = [
                        'uid' => Text::uuid(),
                        'consultation_uid' => $consultation_uid,
                        'patient_id' => $patient_id,
                        'call_date' => date('Y-m-d', strtotime(date('Y-m-d') . ' +15 days')),
                        'status' => 'CLAIM_PENDING',
                        'call_number' => 6,
                        'call_type' => 'CHECK IN',
                        'call_title' => 'Sixth Check In',
                        'show' => 0,
                        'created' => date('Y-m-d H:i:s'),
                    ];
                    break;
            }
        }

        $arr_calls[] = $first_call;
        $arr_calls[] = $second_call;

        //%Guardar las llamadas

        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        foreach ($arr_calls as $call) {
            $cq_entity = $this->DataOtherServicesCheckIn->newEntity($call);
            if (!$cq_entity->hasErrors()) {
                $this->DataOtherServicesCheckIn->save($cq_entity);
            } else {
                return false;
            }
        }
        return true;
    }

    public function test_get_check_ins() {
        $consultation_uid = get('consultation_uid', '');
        if (empty($consultation_uid)) {
            $this->message('consultation_uid not found.');
            return;
        }

        $this->set_call_referred_v2($consultation_uid);

        $this->success();   
    }

    public function set_call_referred(
        $consultation_uid
    ){
        // REPLACE THIS CODE WITH THE CHANGES MADE BY JOEL, OKAY SIS? ⚠️☠️        
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');

        $data_consultation = $this->DataOtherServicesCheckIn->find()
            ->where([
                'DataOtherServicesCheckIn.consultation_uid' => $consultation_uid,
                'DataOtherServicesCheckIn.deleted' => 0,
                'DataOtherServicesCheckIn.call_type' => 'CHECK IN',
                'DataOtherServicesCheckIn.status !=' => 'COMPLETED'
            ])
            ->order(['DataOtherServicesCheckIn.call_date' => 'DESC'])
            ->toArray();            

        foreach ($data_consultation as $call) {   
            $schedules = $this->get_schedules();                                         
            $current_date = date("Y-m-d", strtotime($call->call_date->i18nFormat('yyyy-MM-dd')));
            $id_gage_user = null;
            $desired_date = null;
            $desired_time = null;

            while($desired_date == null){

                $schedule_valid_days = [];
                // $checks = [];
                foreach ($schedules as $schedule_1) {                
                    $days_off = $schedule_1['days_off'];
                    $days_settings = $schedule_1['days_settings'];
                    $day = date('l', strtotime($current_date));
                    $is_in_schedule = $this->is_day_in_array($day, $days_settings);

                    foreach($days_off as $day_off){
                        $day_odd = $day_off->i18nFormat('Y-MM-dd');
                        $current_datee = date('Y-m-d', strtotime($current_date));

                        if($day_odd == $current_datee){
                            $is_in_schedule = false;
                        }
                    }   

                    // $checks[] = array(
                    //     'date' => $current_date,
                    //     'is_in_schedule' => $is_in_schedule
                    // );

                    if($is_in_schedule){
                        $schedule_valid_days[] = $schedule_1;
                    }
                }                                    
                // return $checks;

                if(!empty($schedule_valid_days)){
                    foreach($schedule_valid_days as $schedule){
                        $day = date('l', strtotime($current_date));
                        $days_settings = $schedule['days_settings'];
                        $day_setting = $this->get_day_schedule($day, $days_settings);

                        if(empty($day_setting)){
                            continue;
                        }

                        $start = $day_setting['time_start'];
                        $end = $day_setting['time_end'];
                        
                        $start = $start.":00";
                        $end = $end.":00";

                        $times = $this->get_hours($start, $end);
                    
                        $time = null;
                        foreach($times as $item){
                            $checkins = $this->DataOtherServicesCheckIn->find()->select()
                            ->where([
                                'DataOtherServicesCheckIn.call_date' => $current_date, 
                                'DataOtherServicesCheckIn.call_time' => $item, 
                                'DataOtherServicesCheckIn.deleted'   => 0
                            ])->first();

                            if(empty($checkins)){
                                $time = $item;
                                break;
                            }
                        }

                        if($time != null){
                            $schedule_valid_times[] = array(
                                'id' => $schedule['id'],
                                'time' => $time                        
                            );
                        }                    
                    }                

                    if(!empty($schedule_valid_times)){

                        usort($schedule_valid_times, function ($a, $b) {
                            return strtotime($a['time']) - strtotime($b['time']);
                        });

                        $first_date = $schedule_valid_times[0];

                        $id_gage_user = $first_date['id'];
                        $desired_time = $first_date['time'];
                        $desired_date = $current_date;
                    }else{
                        $current_date = date("Y-m-d", strtotime($current_date . ' + 1 days'));
                    }
                } else {
                    $current_date = date("Y-m-d", strtotime($current_date . ' + 1 days'));
                }
            }

            $timestamp = strtotime($desired_time);
            $formattedTime = date('H:i:s', $timestamp);               

            $result = $this->DataOtherServicesCheckIn->updateAll(
                [
                    'status'     => 'CLAIMED',
                    'support_id' => env('IS_DEV', false) ? $id_gage_user : 18837,
                    'call_date'  => $desired_date,
                    'call_time'  => $formattedTime,
                ],
                ['id' => $call->id]
            );
        }

        return true;
    }

    public function set_call_referred_v2(
        $consultation_uid
    ){
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.DataReferredOtherServices');

        // $token = get('token', '');
        // if(!empty($token)){
        //     $user = $this->AppToken->validateToken($token, true);
        //     if($user === false){
        //         $this->message('Invalid token.');
        //         $this->set('session', false);
        //         return;
        //     }
        //     $this->set('session', true);
        // } else {
        //     $this->message('Invalid token.');
        //     $this->set('session', false);
        //     return;
        // }

        // $consultation_uid  = get('consultation_uid', '');
        // if (empty($consultation_uid)) {
        //     $this->message('consultation_uid not found.');
        //     return;
        // }

        $data_consultation = $this->DataOtherServicesCheckIn
        ->find()
        ->where([
            'DataOtherServicesCheckIn.status NOT IN ' => ['COMPLETED', 'PENDING EVALUATION'],
            'DataOtherServicesCheckIn.call_type' => 'CHECK IN', 
            'DataOtherServicesCheckIn.consultation_uid' => $consultation_uid, 
            'DataOtherServicesCheckIn.deleted' => 0
        ])
        ->all();        
        
        $data_refered = $this->DataReferredOtherServices->find()
        ->select([
            'wls_id' => 'DataReferredOtherServices.referred_id',
            'exist_wls_id' => 'Specialist.id',
        ])
        ->join([
            'User' => [
                    'table' => 'sys_users', 
                    'type' => 'INNER', 
                    'conditions' => 'User.id = DataReferredOtherServices.referred_id
                        and User.active = 1
                        and User.deleted = 0'
                ],
            'Specialist' => [
                    'table' => 'data_users_other_services_check_in', 
                    'type' => 'LEFT', 
                    'conditions' => 'Specialist.user_id = DataReferredOtherServices.referred_id 
                        and Specialist.status = "WLSHOME" 
                        and Specialist.deleted = 0'
                ]
        ])
        ->where([
            'DataReferredOtherServices.user_id' => USER_ID,
            'DataReferredOtherServices.deleted' => 0,
        ])
        ->order(['DataReferredOtherServices.id' => 'ASC'])
        ->first();

        $is_dev = env('IS_DEV', false);

        //% Si el paciente refirio a un especialsita, se le asigna los check in
        //% si no, se le asigna al primer especialista disponible
        // ⚠️ Uncomment when Joel Updates his changes for WL Specialist
        
        // $this->set('data_refered', $data_refered);
        // return;

        if (!empty($data_refered)) {
            $wls_id = $data_refered->wls_id;

            // Only for production
            if (!$is_dev) {
                $this->loadModel('SpaLiveV1.SysUsers');
                $patient = $this->SysUsers->find()->where([
                    'SysUsers.id' => USER_ID,
                    'SysUsers.active' => 1,
                    'SysUsers.deleted' => 0,
                ])->first();

                $patient_name = $patient->mname == '' ? trim($patient->name).' '.trim($patient->lname) : trim($patient->name).' '.trim($patient->mname).' '.trim($patient->lname);
                $is_test_patient = $this->check_test($patient_name);
                //$this->set('patient_test', $is_test_patient);
                
                if ($is_test_patient) {
                    //% Paciente de prueba
                    $injector = $this->SysUsers->find()->where([
                        'SysUsers.id' => $wls_id,
                        'SysUsers.active' => 1,
                        'SysUsers.deleted' => 0
                    ])->first();

                    $injector_name = $injector->mname == '' ? trim($injector->name).' '.trim($injector->lname) : trim($injector->name).' '.trim($injector->mname).' '.trim($injector->lname);
                    $is_test_injector = $this->check_test($injector_name);
                    $this->set('injector_test', $is_test_injector);

                    if ($is_test_injector) {
                        $wls_id = $injector->id;
                        //$this->set('Test', 'Patient and Injector are test users in production.');
                    } else {
                        $wls_id = 21508;
                        //$wls_id = 7619;
                        //$this->set('Test', 'Patient test with injector real');
                    }
                    
                } else {
                    //% Paciente real
                    $injector = $this->SysUsers->find()->where([
                        'SysUsers.id' => $wls_id,
                        'SysUsers.active' => 1,
                        'SysUsers.deleted' => 0
                    ])->first();

                    $injector_name = $injector->mname == '' ? trim($injector->name).' '.trim($injector->lname) : trim($injector->name).' '.trim($injector->mname).' '.trim($injector->lname);
                    $is_test_injector = $this->check_test($injector_name);
                    // $this->set('injector_test', $is_test_injector);

                    // $this->set('Test', 'Patient real with injector real.');
                    // return;

                    if ($is_test_injector) {
                        //$this->set('Test', 'Patient real with injector test.');
                        return $this->automatic_assign_calls(USER_ID, $consultation_uid);
                        
                    }
                    return;
                    
                }
            }

            if (!empty($data_refered->exist_wls_id)) {
                foreach ($data_consultation as $call) {

                    $call_times = $this->get_next_available_date($wls_id, $call);

                    if (isset($call_times["message"])) {
                        //$this->message($call_times["message"]);
                        $this->return_create_calls_records($consultation_uid, USER_ID);
                        return $call_times["message"];
                    }
                    
                    $result = $this->DataOtherServicesCheckIn->updateAll(
                        [
                            'support_id' => $wls_id,
                            'call_date' => $call_times['call_date'],
                            'call_time' => $call_times['call_time'],
                            'status'    => 'CLAIMED',
                        ],
                        ['id' => $call->id]
                    );
        
                    if ($result === false) {
                        return false;
                    }
                }        
            } else {
                //% Buscamos especialista ya que el injector no es especialista
                return $this->automatic_assign_calls(USER_ID, $consultation_uid);
            }

        } else {
            //% Buscamos especialista

            // Only for production
            if (!$is_dev){
                $this->loadModel('SpaLiveV1.SysUsers');
                $patient = $this->SysUsers->find()->where([
                    'SysUsers.id' => USER_ID,
                    'SysUsers.active' => 1,
                    'SysUsers.deleted' => 0
                ])->first();

                $patient_name = $patient->mname == '' ? trim($patient->name).' '.trim($patient->lname) : trim($patient->name).' '.trim($patient->mname).' '.trim($patient->lname);
                $is_test_patient = $this->check_test($patient_name);
                $this->set('patient_test', $is_test_patient);
                
                if ($is_test_patient) {
                    //$this->set('Test', 'Patient with no referred and is test user in production.');
                    
                    foreach ($data_consultation as $call) {

                        $wls_id = 21508;
                        //$wls_id = 7619;
                    
                        $call_times = $this->get_next_available_date($wls_id, $call);
                        
                        $result = $this->DataOtherServicesCheckIn->updateAll(
                            [
                                'support_id' => $wls_id,
                                'call_date' => $call_times['call_date'],
                                'call_time' => $call_times['call_time'],
                                'status'    => 'CLAIMED',
                            ],
                            ['id' => $call->id]
                        );
            
                        if ($result === false) {
                            return false;
                        }
                    }
                } else {
                    //$this->set('Test', 'Patient with no referred and is real user in production.');
                    return $this->automatic_assign_calls(USER_ID, $consultation_uid);
                }
            }
            return $this->automatic_assign_calls(USER_ID, $consultation_uid);
        }
        return true;
    }

    public function get_purchases_consultation(
        $consultation_uid
    ){        
        $this->loadModel('SpaLiveV1.DataPurchases');
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');

        $check_in = $this->DataOtherServicesCheckIn->find()->where([
            'DataOtherServicesCheckIn.consultation_uid' => $consultation_uid,
            'DataOtherServicesCheckIn.call_type' => 'FIRST CONSULTATION',
            'DataOtherServicesCheckIn.deleted' => 0
        ])->first();

        $purchase = $this->DataPurchases->find()->where([
            'DataPurchases.id' => $check_in->purchase_id,
            'DataPurchases.deleted' => 0
        ])->first();

        $purchases = [];

        if(!empty($purchase)){
            $purchases = $this->DataPurchases->find()->where([
                'DataPurchases.payment' => $purchase->payment,
                'DataPurchases.deleted' => 0
            ])->all()->toArray();
        }

        return $purchases;
    }

    public function check_test($name): bool {

        /* $name = get('name', '');
        if (empty($name)) {
            $this->message('Name not found.');
            return;
        } */

        $keywords = array('test', 'prueba', 'pruebas', 'tester', 'testing');

        $name = strtolower(trim($name));

        $found = false;
        foreach ($keywords as $keyword) {
            if (strpos($name, $keyword) !== false) {
                $found = true;
                break;
            }
        }

        //$this->set('found', $found); //Only for testing ⚠️
        return $found;

    }

    public function check_in_status(){
        //? Esta funcion se llama cuando el Examiner completa/incompleta una llamada

        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');

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

        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $this->message('consultation_uid not found.');
            return;
        }

        $call_id = get('id','');
        if (empty($call_id)) {
            $this->message('id not found.');
            return;
        }

        $status = get('status','');
        if (empty($status)) {
            $this->message('status not found.');
            return;
        }
        
        if($status == 'WAITING') {

            $currentRecord = $this->DataOtherServicesCheckIn->find()
                ->where([
                    'consultation_uid' => $consultation_uid,
                    'id' => $call_id
                ])
            ->first();

            // if( $currentRecord->status == 'COMPLETED' || $currentRecord->status == 'INCOMPLETED'){
            //     $this->success();
            //     return;
            // } else {
                $this->DataOtherServicesCheckIn->updateAll(
                    ['status' => $status], 
                    ['id' => $call_id]
                );
            // }
        }

        if($status == 'COMPLETED'){
            ////encontrar consulta
            $currentConsult = $this->DataConsultationOtherServices->find()
            ->where([
                'DataConsultationOtherServices.uid' => $consultation_uid,
                'DataConsultationOtherServices.deleted' => 0
            ])
            ->first();
            //////
            
            $currentRecord = $this->DataOtherServicesCheckIn->find()
                ->where([
                    'DataOtherServicesCheckIn.consultation_uid' => $consultation_uid,
                    'DataOtherServicesCheckIn.id' => $call_id
                ])
            ->first();

            if ($currentRecord) {
                $currentRecord->status = 'COMPLETED';
                $currentRecord->pending_answers = 1;
                $this->DataOtherServicesCheckIn->save($currentRecord);
            }

            //SHIPPING DATE
            if( $currentRecord->call_number == 1 && 
                $currentRecord->call_type == "FIRST CONSULTATION"){
                $this->loadModel('SpaLiveV1.DataPurchases');

                $data_purchase = $this->DataPurchases->find()
                ->where(['DataPurchases.id' => $currentRecord->purchase_id, 
                    'DataPurchases.deleted' => 0])->first();

                $tentative_date = date('Y-m-d');

                $tentative_date = date('Y-m-d', strtotime($tentative_date . ' +5 day'));

                $data_purchase->shipping_date = $tentative_date;

                $update = $this->DataPurchases->save($data_purchase);

                if($update){

                    $id = [$currentRecord->purchase_id];
                    
                    $purchases = $this->DataPurchases->find()
                    ->where(['DataPurchases.payment' => $data_purchase->payment, 'DataPurchases.payment_intent' => $data_purchase->payment_intent, 
                        'DataPurchases.deleted' => 0, 'DataPurchases.id NOT IN' => $id])->order(['DataPurchases.id' => 'ASC'])->limit(2);

                    foreach($purchases as $p){
                        $tentative_date = date('Y-m-d', strtotime($tentative_date . ' +30 day'));

                        $p->shipping_date = $tentative_date;

                        $u_p = $this->DataPurchases->save($p);

                        if(!$u_p){
                            $this->Response->message('Error in update the following purchases.');
                            return;
                        }
                    }

                }else{
                    $this->Response->message('Error in update purchase.');
                    return;
                }

            }

            if($currentRecord->call_number == 6){
             
                $currentConsult->status = "COMPLETED";
                $update = $this->DataConsultationOtherServices->save($currentConsult);

                if($update){
                    //actualizar en firebase para que desaparezca
                    $firebase_update_data = array(
                        "uid"       => $consultation_uid,
                        "status"    => "COMPLETED",
                    );
                    
                    $update_firebase = $this->update_firebase($firebase_update_data); 
                    
                    if(!$update_firebase){
                        $this->message('Error updating firebase status to completed');
                    }
                    
                    $this->set('data', $currentConsult);
                    $this->success();
                    return;
                }else{
                    $this->Response->message('Error in update consultation.');
                    return;
                }

            }

            $next_call_id = 0;
            if ($currentRecord->call_type != 'FIRST CONSULTATION') {

                $foundCurrent = false;

                $all_calls = $this->DataOtherServicesCheckIn->find()
                ->where([
                    'consultation_uid' => $consultation_uid,
                ])
                ->all();

                // Avanzar al siguiente registro
                foreach ($all_calls as $call) {
                    if ($foundCurrent) {
                        $next_call_id = $call->id;
                        break;
                    }
            
                    if ($call->id == $call_id) {
                        $foundCurrent = true;
                    }
                }
                
                $next_call_record = $this->DataOtherServicesCheckIn->find()
                ->select([
                        'DataOtherServicesCheckIn.id', 
                        'DataOtherServicesCheckIn.consultation_uid', 
                        'DataOtherServicesCheckIn.call_title', 
                        'DataOtherServicesCheckIn.call_type', 
                        'DataOtherServicesCheckIn.status', 
                        'DataOtherServicesCheckIn.call_date', 
                        'DataOtherServicesCheckIn.call_time',
                        'DataOtherServicesCheckIn.support_id',
                        'Injector.uid',
                        'Injector.id'
                    ])
                ->join([
                    'Injector' => [
                            'table' => 'sys_users', 
                            'type' => 'INNER', 
                            'conditions' => 'Injector.id = DataOtherServicesCheckIn.support_id'
                        ]
                ])
                ->where([
                    'DataOtherServicesCheckIn.consultation_uid' => $consultation_uid,
                    'DataOtherServicesCheckIn.id' => $next_call_id
                ])
                ->order(['DataOtherServicesCheckIn.call_date' => 'DESC'])
                ->first();

                if ($next_call_record) {
                    $next_call_record->show = 1;
                    $this->DataOtherServicesCheckIn->save($next_call_record);
                    $concat_date_time = $next_call_record->call_date . ' ' . date_format($next_call_record->call_time, 'h:i A');
                    $call_date = date('m/d/Y H:i A', strtotime($concat_date_time));
                }

                //% Si $currentRecord es First Conultation, debería publicar a firebase el siguiente record (First Check In) 
                //% pero para este momento, no hay siguiente record porque se crea hasta que el paciente 
                //% firme que recivió el producto. Por lo tanto se utiliza la funcion !! save_telehealth_call_dates_gage !!
                //% para crear el siguiente record (First Check In) y publicarlo a firebase.
                //% Lo unico que se tiene que hacer es enviar el examiner_uid string vacio para limpiarlo en firebase.
    
                //% Si $currentRecord es el primer check in, se debe publicar el siguiente record (Second Check In).
    
                //% Si $currentRecord es el segundo check in, solo tiene que enviar el examiner_uid string vacio para que 
                //% la siguiente llamada pueda aparecer.
    
                //% Si $currentRecord es Follow Up, pasa lo mismo que con First Consultation.
    
                $firebase_update_data = array();
    
                switch ($currentRecord->call_type) {
                    case 'CHECK IN':
                        
                        // Validar que la llamada sea impar 1, 3 o 5
                        if ($currentRecord->call_number % 2 == !0) {
                            // Publica el Check in impar
    
                            $firebase_update_data = array(
                                "uid" => $next_call_record->consultation_uid,
                                "examiner_uid" => $next_call_record['Injector']['uid'],
                                "call_id" => strval($next_call_record->id),
                                "call_type" => $next_call_record->call_type,
                                "call_title" => $next_call_record->call_title,
                                "status" => "CLAIMED",
                                "scheduled_date" => $call_date,
                                "has_images" => false,
                            );
                        } else {
                            // Limpia el Check in par
                            $firebase_update_data = array(
                                "uid" => $currentRecord->consultation_uid,
                                "examiner_uid" => "",
                            );
                        }
                        break;
                    default:
                        $firebase_update_data = array(
                            "uid" => $currentRecord->consultation_uid,
                            "examiner_uid" => "",
                        );
                        break;
                }
    
                #region save firebase
            
                if(!empty($firebase_update_data)){
    
                    // Espera durante 5 segundos
                    sleep(5);
                
                    //print_r($data);exit;
                    $data_string = json_encode($firebase_update_data);
                    $this->set('firebase_update_data', $firebase_update_data);
                    $url = env('URL_MICROSERVICE', 'http://localhost:3131/') . 'updateServicesArr';
                    $ch=curl_init($url);
                    curl_setopt($ch, CURLOPT_POST, true);//curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
                    curl_setopt($ch, CURLOPT_HEADER, false);
                    curl_setopt($ch, CURLOPT_HTTPHEADER,
                        array(
                            'Content-Type:application/json',
                            'Content-Length: ' . strlen($data_string)
                        )
                    );
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
                    $result = curl_exec($ch);
        
                    $this->set('result_curl', $result);
                    if (curl_errno($ch)) {
                        $error_msg = curl_error($ch);
                        $this->message($error_msg);
                        $this->success(false);
                        return;
                        // this would be your first hint that something went wrong                            
                    } else {
                        // check the HTTP status code of the request
                        $resultStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        if ($resultStatus == 200) {
                            //unlink($filename);// everything went better than expected
                        } else {
                            // the request did not complete as expected. common errors are 4xx
                            // (not found, bad request, etc.) and 5xx (usually concerning
                            // errors/exceptions in the remote script execution)                                
                        }
                    }
                    curl_close($ch);
                }
            }

            #endregion

        } else if($status == 'INCOMPLETED') {

            $this->DataOtherServicesCheckIn->updateAll(
                ['status' => $status], 
                ['id' => $call_id]
            );

            // Espera durante 5 segundos
            sleep(5);

            //!No comentar esta parte, borramos el examiner_uid para aparezca en la siguiente llamada

            $currentRecord = $this->DataOtherServicesCheckIn->find()
                ->where([
                    'consultation_uid' => $consultation_uid,
                    'id' => $call_id
                ])
            ->first();

            if ($currentRecord->call_type == "CHECK IN") {
                $firebase_update_data = array(
                    "uid" =>  $currentRecord->consultation_uid,
                    "status" => "CLAIMED",
                );
            }else {
                $firebase_update_data = array(
                    "uid" => $currentRecord->consultation_uid,
                    "examiner_uid" => "",
                );
            }

            $data_string = json_encode($firebase_update_data);
            $this->set('firebase_update_data', $firebase_update_data);
            $url = env('URL_MICROSERVICE', 'http://localhost:3131/') . 'updateServicesArr';
            $ch=curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);//curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER,
                array(
                    'Content-Type:application/json',
                    'Content-Length: ' . strlen($data_string)
                )
            );
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $result = curl_exec($ch);

            $this->set('result_curl', $result);
            if (curl_errno($ch)) {
                $error_msg = curl_error($ch);
                $this->message($error_msg);
                $this->success(false);
                return;
                // this would be your first hint that something went wrong                            
            } else {
                // check the HTTP status code of the request
                $resultStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($resultStatus == 200) {
                    //unlink($filename);// everything went better than expected
                } else {
                    // the request did not complete as expected. common errors are 4xx
                    // (not found, bad request, etc.) and 5xx (usually concerning
                    // errors/exceptions in the remote script execution)                                
                }
            }
            curl_close($ch);

        }
        ////////
        $this->success();

    }

    public function rate_call(){
        //? Esta funcion es hija de check_in_status, esta se cambio el nombre mas claro y para modijarla para
        //? los nuevos requerimientos del Injector WL Specialist
        //? Esta funcion se llama cuando los WL Specialist completa/incompleta una llamada

        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');

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

        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $this->message('consultation_uid not found.');
            return;
        }

        $call_id = get('id','');
        if (empty($call_id)) {
            $this->message('id not found.');
            return;
        }

        $status = get('status','');
        if (empty($status)) {
            $this->message('status not found.');
            return;
        }

        // Buscar consulta
        $currentConsult = $this->DataConsultationOtherServices->find()
        ->where([
            'DataConsultationOtherServices.uid' => $consultation_uid,
            'DataConsultationOtherServices.deleted' => 0
        ])
        ->first();

        if($status == 'COMPLETED'){
            
            $currentRecord = $this->DataOtherServicesCheckIn->find()
                ->where([
                    'consultation_uid' => $consultation_uid,
                    'id' => $call_id
                ])
            ->first();

            if ($currentRecord) {
                $currentRecord->status = 'PENDING EVALUATION';
                $currentRecord->pending_answers = 1;
                $this->DataOtherServicesCheckIn->save($currentRecord);
            }

            if($currentRecord->call_number == 1 && $currentRecord->call_type == "FIRST CONSULTATION"){
                $this->loadModel('SpaLiveV1.DataPurchases');

                $data_purchase = $this->DataPurchases->find()
                ->where(['DataPurchases.id' => $currentRecord->purchase_id, 
                    'DataPurchases.deleted' => 0])->first();

                $tentative_date = date('Y-m-d');

                $tentative_date = date('Y-m-d', strtotime($tentative_date . ' +5 day'));

                $data_purchase->shipping_date = $tentative_date;

                $update = $this->DataPurchases->save($data_purchase);

                if($update){

                    $id = [$currentRecord->purchase_id];
                    
                    $purchases = $this->DataPurchases->find()
                    ->where(['DataPurchases.payment' => $data_purchase->payment, 'DataPurchases.payment_intent' => $data_purchase->payment_intent, 
                        'DataPurchases.deleted' => 0, 'DataPurchases.id NOT IN' => $id])->order(['DataPurchases.id' => 'ASC'])->limit(2);

                    foreach($purchases as $p){
                        $tentative_date = date('Y-m-d', strtotime($tentative_date . ' +30 day'));

                        $p->shipping_date = $tentative_date;

                        $u_p = $this->DataPurchases->save($p);

                        if(!$u_p){
                            $this->Response->message('Error in update the following purchases.');
                            return;
                        }
                    }

                }else{
                    $this->Response->message('Error in update purchase.');
                    return;
                }

            }

            if($currentRecord->call_number == 6){
             
                $currentConsult->status = "COMPLETED";
                $consult_entity = $this->DataConsultationOtherServices->newEntity($currentConsult->toArray());
                $ent_saved1 = $this->DataConsultationOtherServices->save($consult_entity);
                
                $this->set('data', $ent_saved1);
                $this->success();
                return;
                //return $consult_entity;
            }

            $next_call_id = 0;
            if (!empty($currentRecord)) {

                $foundCurrent = false;
                
                $all_calls = $this->DataOtherServicesCheckIn->find()
                ->where([
                    'consultation_uid' => $consultation_uid,
                ])
                ->all();

                // Avanzar al siguiente registro
                foreach ($all_calls as $call) {
                    if ($foundCurrent) {
                        $next_call_id = $call->id;
                        break;
                    }
            
                    if ($call->id == $call_id) {
                        $foundCurrent = true;
                    }
                }
                
                $next_call_record = $this->DataOtherServicesCheckIn->find()
                ->where([
                    'consultation_uid' => $consultation_uid,
                    'id' => $next_call_id
                ])
                ->first();

                if ($next_call_record) {
                    $next_call_record->show = 1;
                    $this->DataOtherServicesCheckIn->save($next_call_record);
                    $concat_date_time = $next_call_record->call_date . ' ' . date_format($next_call_record->call_time, 'h:i A');
                    $call_date = date('m/d/Y H:i A', strtotime($concat_date_time));
                }
            }
            
            //% Si $currentRecord es First Conultation, debería publicar a firebase el siguiente record (First Check In) 
            //% pero para este momento, no hay siguiente record porque se crea hasta que el paciente 
            //% firme que recivió el producto. Por lo tanto se utiliza la funcion !! save_calls_records !!
            //% para crear el siguiente record (First Check In) y publicarlo a firebase.

            //% Si $currentRecord es el primer check in, se debe publicar el siguiente record (Second Check In).

            //% Si $currentRecord es el segundo check in, solo tiene que enviar el examiner_uid string vacio para que 
            //% la siguiente llamada pueda aparecer.

            //% Si $currentRecord es Follow Up, pasa lo mismo que con First Consultation.

            $firebase_update_data = array();

            switch ($currentRecord->call_type) {
                case 'CHECK IN':
                    
                    // Validar que la llamada sea impar 1, 3 o 5
                    if ($currentRecord->call_number % 2 == !0) {
                        // Publicamos la segunda llamada del mes
                        $firebase_update_data = array(
                            "uid" => $next_call_record->consultation_uid,
                            "call_id" => strval($next_call_record->id),
                            "call_type" => $next_call_record->call_type,
                            "call_title" => $next_call_record->call_title,
                            "status" => $next_call_record->status == 'CLAIM_PENDING' ? 'CLAIM PEENDING' : $next_call_record->call_type,
                            "scheduled_date" => $call_date,
                        );
                    } else {
                        // Limpiamos para el follow up
                        $firebase_update_data = array(
                            "uid" => $currentRecord->consultation_uid,
                            "examiner_uid" => "",
                        );
                    }
                    break;
                default:
                    $firebase_update_data = array(
                        "uid" => $currentRecord->consultation_uid,
                        "examiner_uid" => "",
                    );
                    break;
            }

            #region save firebase
        
            if(!empty($firebase_update_data)){

                // Espera 5 segundos
                sleep(5);
            
                //print_r($data);exit;
                $data_string = json_encode($firebase_update_data);
                $this->set('firebase_update_data', $firebase_update_data);
                $url = env('URL_MICROSERVICE', 'http://localhost:3131/') . 'updateServicesArr';
                $ch=curl_init($url);
                curl_setopt($ch, CURLOPT_POST, true);//curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
                curl_setopt($ch, CURLOPT_HEADER, false);
                curl_setopt($ch, CURLOPT_HTTPHEADER,
                    array(
                        'Content-Type:application/json',
                        'Content-Length: ' . strlen($data_string)
                    )
                );
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
                $result = curl_exec($ch);
    
                $this->set('result_curl', $result);
                if (curl_errno($ch)) {
                    $error_msg = curl_error($ch);
                    $this->message($error_msg);
                    $this->success(false);
                    return;
                    // this would be your first hint that something went wrong                            
                } else {
                    // check the HTTP status code of the request
                    $resultStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    if ($resultStatus == 200) {
                        //unlink($filename);// everything went better than expected
                    } else {
                        // the request did not complete as expected. common errors are 4xx
                        // (not found, bad request, etc.) and 5xx (usually concerning
                        // errors/exceptions in the remote script execution)                                
                    }
                }
                curl_close($ch);
            }

            //send prescription

            #endregion

        } else if($status == 'INCOMPLETED') {

            $this->DataOtherServicesCheckIn->updateAll(
                ['status' => $status], 
                ['id' => $call_id]
            );

        }
        
        $this->success();

    }

   
    public function save_purchase(){
        //// pago de servicio (3 meses)
        //$this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.DataPurchasesOtherServices');
        $this->loadModel('SpaLiveV1.DataPurchases');

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

        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $this->message('consultation_uid not found.');
            return;
        }

        //id follow up
        $id = get('id','');
        if (empty($id)) {
            $this->message('id not found.');
            return;
        }

        $call_number = get('call_number','');
        if (empty($call_number)) {
            $this->message('call_number not found.');
            return;
        }

        $call_type = get('call_type','');
        if (empty($call_type)) {
            $this->message('call_type not found.');
            return;
        }
        
        //follow up id purchase
        $previousFollowUp = $this->DataOtherServicesCheckIn->find()
        ->where([
            'DataOtherServicesCheckIn.id' => $id,
            'DataOtherServicesCheckIn.deleted' => 0
        ])
        ->last();

        //obtener purchases por checkin 
        $getPurchaseId =  $this->DataOtherServicesCheckIn->find()
        ->where([
            'DataOtherServicesCheckIn.consultation_uid' => $consultation_uid,
            'DataOtherServicesCheckIn.purchase_id <>' => 0,
            'DataOtherServicesCheckIn.deleted' => 0,
            'call_type IN' => ['FOLLOW UP', 'FIRST CONSULTATION']
        ])
        ->last();

        $previousPurchase = $this->DataPurchases->find()
            ->where([
                'DataPurchases.id' => $getPurchaseId->purchase_id,
                'DataPurchases.deleted' => 0
            ])
        ->first();

        //print(json_encode($previousPurchase));
        //exit();

        //obtener purchases por consultation id
        /*$previousPurchase = $this->DataPurchases->find()
            ->where([
                'DataPurchases.uid' => $consultation_uid,
                'DataPurchases.deleted' => 0
            ])
            ->last();*/

        
        //guardar registro parecido al previo purchases other services
        $newPurchase = $previousPurchase;
        $newPurchase->id = null;
        $newPurchase->status = 'WAITING FOR THE EXAMINER TO CONFIRM YOUR PRODUCT';
        $newPurchase->signature = 0;
        $newPurchase->call_type = $call_type;
        $newPurchase->call_number = $call_number;

        //print($newPurchase);
        //exit;
        
        $c_entity = $this->DataPurchases->newEntity($newPurchase->toArray());
        if(!$c_entity->hasErrors()) {
            $ent_saved = $this->DataPurchases->save($c_entity);
            $this->set('data', $ent_saved);
            $this->success();
            if ($ent_saved) {
                //guardar registro FOLLOW_UP
                $previousFollowUp->purchase_id = $ent_saved->id;

                $b_entity = $this->DataOtherServicesCheckIn->newEntity($previousFollowUp->toArray());
                if(!$b_entity->hasErrors()) {
                    $ent_saved1 = $this->DataOtherServicesCheckIn->save($b_entity);
                    $this->set('data', $ent_saved1);
                    $this->success();
                    if ($ent_saved1) {
                        return $ent_saved1->id;
                    }
                }
                return $ent_saved->id;
            }
        }




        //obtener purchases por consultation id (purchases other services)
        $previousPurchaseOS = $this->DataPurchasesOtherServices->find()
            ->where([
                'DataPurchasesOtherServices.consultation_uid' => $consultation_uid,
                'DataPurchasesOtherServices.deleted' => 0
            ])
            ->last();        

        //guardar registro parecido al previo purchases other services
        $newPurchaseOS = $previousPurchaseOS;
        $newPurchaseOS->id = null;
        $newPurchaseOS->status = 'WAITING FOR THE EXAMINER TO CONFIRM YOUR PRODUCT';
        $newPurchaseOS->signature = 0;
        $newPurchaseOS->call_type = $call_type;
        $newPurchaseOS->call_number = $call_number;

        //print($newPurchase);
        //exit;
        
        $c_entity_os = $this->DataPurchasesOtherServices->newEntity($newPurchaseOS->toArray());
        if(!$c_entity_os->hasErrors()) {
            $ent_saved = $this->DataPurchasesOtherServices->save($c_entity_os);
            $this->set('data_os', $ent_saved);
            $this->success();
            if ($ent_saved) {
                //guardar registro FOLLOW_UP
                $previousFollowUp->purchase_id = $ent_saved->id;

                $b_entity = $this->DataOtherServicesCheckIn->newEntity($previousFollowUp->toArray());
                if(!$b_entity->hasErrors()) {
                    $ent_saved1 = $this->DataOtherServicesCheckIn->save($b_entity);
                    $this->set('data', $ent_saved1);
                    $this->success();
                    if ($ent_saved1) {
                        return $ent_saved1->id;
                    }
                }
                return $ent_saved->id;
            }
        }

        

        //actualizar registro actual
        //$previousPurchase->show = 0;
        //$d_entity = $this->DataPurchasesOtherServices->newEntity($previousPurchase);
        //if(!$d_entity->hasErrors()) {
        //    $ent_saved = $this->DataPurchasesOtherServices->save($d_entity);
        //    $this->set('data', $ent_saved);
        //    $this->success();
        //    if ($ent_saved) {
        //        return $ent_saved->id;
        //    }
        //}

        //foreach ($previousPurchase as $row) {
        //    print($row['id']);
        //}
 
        //print_r($previousPurchase);
        //echo count($previousPurchase);
        $this->success();
        exit();

    }

    public function get_service_consultation(){
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

        $service_uid = get('service_uid','');
        if (empty($service_uid)) {
            $this->message('service_uid not found.');
            return;
        }

        $patient_id = USER_ID;
    
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $this->loadModel('SpaLiveV1.CatOtherServices');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataUsersFreeWl');

        $free = $this->DataUsersFreeWl->find()->where(['user_id' => $patient_id, 'deleted' => 0])->first();
        if(!empty($free)){
            $this->set('free', true);
        }else{
            $this->set('free', false);
        }
        
        $ent_consultations = $this->DataConsultationOtherServices->find()->where(['DataConsultationOtherServices.patient_id' => 
        $patient_id, 'DataConsultationOtherServices.deleted' => 0, 'DataConsultationOtherServices.service_uid' => $service_uid])->all();

        $services = $this->CatOtherServices->find()->where(['CatOtherServices.deleted' => 0])->all();
        $i=0;
        if($ent_consultations){
            foreach($ent_consultations->toArray() as $consultos){

                //fechaformateada
                if($consultos->start_date != null || $consultos->end_date_string != null){
                    $fecha_objeto1 = $consultos->start_date;
                    $fecha_modificada1 = $fecha_objeto1->format('m-d-Y');

                    $fecha_objeto2 = $consultos->end_date;
                    $fecha_modificada2 = $fecha_objeto2->format('m-d-Y');

                    $consultos->start_date_string = $fecha_modificada1;
                    $consultos->end_date_string = $fecha_modificada2;
                }

                foreach($services->toArray() as $servicesos){
                    if($consultos["service_uid"] == $servicesos["uid"]){
                        $ent_consultations->toArray()[$i]['service_name'] = $servicesos['title'];
                        $fecha = $consultos->schedule_date->i18nFormat('yyyy-MM-dd');
                        $timestamp = strtotime(strval($fecha)); // convierte la cadena en un timestamp
                        $formateada = date("Y-m-d", $timestamp); // formatea la fecha
                        $ent_consultations->toArray()[$i]['schedule_date'] = $formateada;
                        $create = $consultos->created->i18nFormat('yyyy-MM-dd');
                        $timestamp2 = strtotime(strval($create)); // convierte la cadena en un timestamp
                        $formateada2 = date("Y-m-d", $timestamp2); // formatea la fecha
                        $ent_consultations->toArray()[$i]['created'] = $formateada2;
                    }
                }
                $i++;
            }
            $this->set('data', $ent_consultations);
            $this->success();
            //return;
        } else {
            $this->set('data', []);
            $this->success();
        }
    }

    public function gage_schedule_old( $consultation_uid, $baseDays, $nextCallNumber, $callTitle, $show ){

        //$consultation_uid = get('consultation_uid', '');
        //
        //$consultation_uid = get('consultation_uid','');
        //if (empty($consultation_uid)) {
        //    $this->message('consultation_uid not found.');
        //    return;
        //}
        //
        //$baseDays = get('baseDays', '');
        //
        //$baseDays = get('baseDays','');
        //if (empty($baseDays)) {
        //    $this->message('baseDays not found.');
        //    return;
        //}
        //
        //$nextCallNumber = get('nextCallNumber', '');
        //
        //$nextCallNumber = get('nextCallNumber','');
        //if (empty($nextCallNumber)) {
        //    $this->message('nextCallNumber not found.');
        //    return;
        //}
        //
        //$callTitle = get('callTitle','');
        //if (empty($callTitle)) {
        //    $this->message('callTitle not found.');
        //    return;
        //}
        
        //$show = get('show','');
        //if (empty($show)) {
        //    $this->message('show not found.');
        //    return;
        //}

        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');

        //$callDate = date('Y-m-d', strtotime(date('Y-m-d') . ' +15 days'));

        //empieza ciclo
        $i = 0;
        $freeDateHour = false;
        /////
        /////
        //condicion cumplida hasta encontrar fecha libre
        while(!$freeDateHour) {
        //
            $daysToAdd = $baseDays+$i;
            $callDate = date('Y-m-d', strtotime(date('Y-m-d') . ' + '.$daysToAdd.'days'));
            //
            $diaSemana = date('N', strtotime($callDate));
            $this->set( 'callDate', $callDate );
            $this->set( 'diaSemana', $diaSemana );
            $this->set( 'data', $diaSemana );
            //
            //
            //verificar que dia no caiga en sabado o domingo
            if($diaSemana < 6){
                
                //si lunes, miercoles o viernes de 9am a 12pm
                if($diaSemana == 1 || $diaSemana == 3 || $diaSemana == 5){

                    //gage chambea
                    $horaCadena = "09:00";
                    // Convertir la cadena a objeto DateTime
                    $hora = strtotime($horaCadena);
                    
                    $freeHour = false;
                    while(!$freeHour) {
                        $checkins = $this->DataOtherServicesCheckIn->find()->select()->where(['DataOtherServicesCheckIn.call_date' => $callDate, 'DataOtherServicesCheckIn.call_time' => date('H:i:s', $hora), 'DataOtherServicesCheckIn.deleted' => 0])->first();
                        if(empty($checkins)){
                            $arr_save_call_1 = [
                                'uid' => Text::uuid(),
                                'consultation_uid' => $consultation_uid,
                                'patient_id' => USER_ID,
                                //'call_date' => $callDate . ' ' . date('H:i:s', $hora), 
                                'call_date' => $callDate, // Adjust the number of days according to your requirements
                                'call_time' => date('H:i:s', $hora), // Adjust the number of days according to your requirements
                                'status' => 'CLAIM_PENDING',
                                'call_number' => $nextCallNumber,
                                'call_type' => 'CHECK IN',
                                'call_title' => $callTitle,
                                'show' => $show,
                                'created' => date('Y-m-d H:i:s'),
                            ];
                            
                            $cq_entity_1 = $this->DataOtherServicesCheckIn->newEntity($arr_save_call_1);
                            if (!$cq_entity_1->hasErrors()) {
                                $result = $this->DataOtherServicesCheckIn->save($cq_entity_1);
                                //save
                                $this->set( 'data', $result );
                                $this->success();
                                $freeDateHour = true;
                                $freeHour = true;
                                return;
                            }
                        } else {
                            // Sumar 20 minutos al timestamp
                            $hora = $hora + (20 * 60);
                            $this->set( 'horacall MWF', date('H:i:s', $hora) );
                            
                            //checar si esta a las 12
                            if(date('H:i:s', $hora) === "17:00:00"){
                                $freeHour = true;
                                $i++;
                            }
                        }

                    }

                }

                //si martes jueves de 12pm a 3pm
                if($diaSemana == 2 || $diaSemana == 4){
                     //gage chambea
                     $horaCadena = "09:00";
                     // Convertir la cadena a objeto DateTime
                     $hora = strtotime($horaCadena);
                     
                     $freeHour = false;
                     while(!$freeHour) {
                         $checkins = $this->DataOtherServicesCheckIn->find()->select()->where(['DataOtherServicesCheckIn.call_date' => $callDate, 'DataOtherServicesCheckIn.call_time' => date('H:i:s', $hora), 'DataOtherServicesCheckIn.deleted' => 0])->first();
                         if(empty($checkins)){
                             $arr_save_call_1 = [
                                 'uid' => Text::uuid(),
                                 'consultation_uid' => $consultation_uid,
                                 'patient_id' => USER_ID,
                                 'call_date' => $callDate, // Adjust the number of days according to your requirements
                                 'call_time' => date('H:i:s', $hora), // Adjust the number of days according to your requirements
                                 'status' => 'CLAIM_PENDING',
                                 'call_number' => $nextCallNumber,
                                 'call_type' => 'CHECK IN',
                                 'call_title' => $callTitle,
                                 'show' => $show,
                                 'created' => date('Y-m-d H:i:s'),
                             ];
                             
                             $cq_entity_1 = $this->DataOtherServicesCheckIn->newEntity($arr_save_call_1);
                             if (!$cq_entity_1->hasErrors()) {
                                 $result = $this->DataOtherServicesCheckIn->save($cq_entity_1);
                                 //save
                                 $this->set( 'data', $result );
                                 $this->success();
                                 $freeDateHour = true;
                                 $freeHour = true;
                                 return;
                             }
                         } else {
                             // Sumar 20 minutos al timestamp
                             $hora = $hora + (20 * 60);
                             $this->set( 'horacall TT', date('H:i:s', $hora) );
                             //checar si esta a las 12
                             if(date('H:i:s', $hora) === "17:00:00"){
                                 $freeHour = true;
                                 $i++;
                             }
                         }
 
                     }
                }

            } else {
                //si fue fin de semana sumar uno al dia y repetir ciclo
                $i++;
            }
        }
        return;
        
    }

    public function gage_schedule_get_specific_day(){

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
        
        $num_day = get('num_day','');
        if (empty($num_day)) {
            $this->message('num_day not found.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataOtherServicesCheckInSchedule');
    
        $gage_schedule = $this->DataOtherServicesCheckInSchedule->find()->select()->where(['DataOtherServicesCheckInSchedule.num_day' => intval(USER_ID), 'DataOtherServicesCheckInSchedule.id_user' => intval($id_user)])->first();

        
        $this->set('data', $gage_schedule);
            //getdays
        //date('H:i:s', $hora)
        $this->success();

    }

    public function gage_schedule_get_days(){

        $this->loadModel('SpaLiveV1.DataOtherServicesCheckInSchedule');
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

        $this->loadModel('SpaLiveV1.DataOtherServicesCheckInSchedule');
        
        $ent_consultations = $this->DataOtherServicesCheckInSchedule->find()->where(['DataOtherServicesCheckInSchedule.id_user' => USER_ID])->all();

        
        if(!empty($ent_consultations)){
            foreach($ent_consultations as $row) {
                //fechaformateada
                $row->start_hour_string = $row->start_hour->format('h:i:s');
                $row->end_hour_string = $row->end_hour->format('h:i:s');
            
            }
        }

        $this->set('data', $ent_consultations);
            //getdays
        //date('H:i:s', $hora)
        $this->success();

    }
    
    public function gage_schedule_update(){

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
        
        $id = get('id','');
        if (empty($id)) {
            $this->message('id not found.');
            return;
        }

        $start_hour = get('start_hour','');
        if (empty($start_hour)) {
            $this->message('start_hour not found.');
            return;
        }

        $end_hour = get('end_hour','');
        if (empty($end_hour)) {
            $this->message('end_hour not found.');
            return;
        }

        $day_off = get('day_off','');
        if ($day_off == '' || $day_off == null) {
            $this->message('day_off not found.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataOtherServicesCheckInSchedule');

        $arr_save_call_1 = [
            'id' => $id,
            'start_hour' => $start_hour,
            'end_hour' => $end_hour,
            'day_off' => $day_off,
        ];
        
        $cq_entity_1 = $this->DataOtherServicesCheckInSchedule->newEntity($arr_save_call_1);
        if (!$cq_entity_1->hasErrors()) {
            $result = $this->DataOtherServicesCheckInSchedule->save($cq_entity_1);
            //save
            $this->set( 'data', $result );
            $this->success();
            return;
        }

    }

#region gage 1
//     public function gage_schedule( $consultation_uid, $baseDays, $nextCallNumber, $callTitle, $show ){

//                 //
//         //$consultation_uid = get('consultation_uid','');
//         //if (empty($consultation_uid)) {
//         //    $this->message('consultation_uid not found.');
//         //    return;
//         //}
//         //        //
//         //$baseDays = get('baseDays','');
//         //if (empty($baseDays)) {
//         //    $this->message('baseDays not found.');
//         //    return;
//         //}
//         //        //
//         //$nextCallNumber = get('nextCallNumber','');
//         //if (empty($nextCallNumber)) {
//         //    $this->message('nextCallNumber not found.');
//         //    return;
//         //}
//         //        //
//         //$callTitle = get('callTitle','');
//         //if (empty($callTitle)) {
//         //    $this->message('callTitle not found.');
//         //    return;
//         //}
//         //
//         //$show = get('show','');
//         //if (empty($show)) {
//         //    $this->message('show not found.');
//         //    return;
//         //}

//         //Obtener horario gage

//         $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
//         $this->loadModel('SpaLiveV1.DataOtherServicesCheckInSchedule');

//         //$callDate = date('Y-m-d', strtotime(date('Y-m-d') . ' +15 days'));

//         //empieza ciclo
//         $i = 0;
//         $freeDateHour = false;
//         /////
//         //condicion cumplida hasta encontrar fecha libre
//         while(!$freeDateHour) {
// //

//             $this->set('baseDays', $baseDays);
//             $daysToAdd = intval($baseDays)+$i;
//             $this->set('daysToAdd', $daysToAdd);
//             $callDate = date('Y-m-d', strtotime(date('Y-m-d') . ' + '.$daysToAdd.'days'));
//             $this->set('callDate', $callDate);
// //
//             $diaSemana = date('N', strtotime($callDate));
//             $this->set( 'diaSemana', $diaSemana );

            
//             $gage_schedule = $this->DataOtherServicesCheckInSchedule->find()->select()->where(['DataOtherServicesCheckInSchedule.num_day' => intval($diaSemana)])->first();

//             //debug variables
//             $this->set('gageSchedule', $gage_schedule);
//             //return;
//             //exit;
//             //$this->set( 'callDate', $callDate );
//             //$this->set( 'diaSemana', $diaSemana );

//             //iterar dias
//             //si lunes, miercoles o viernes de 9am a 12pm
//             if(empty($gage_schedule)){
//                 $this->message('no schedule assigned.');
//                 return;
//             } 

//             if($gage_schedule->day_off == 0){
            
//                 $horaCadena = $gage_schedule->start_hour;
//                 // Convertir la cadena a objeto DateTime
                
//                 $hora = strtotime($horaCadena->format('H:i'));

                
//                 $horaCadenaFinal = $gage_schedule->end_hour;
//                 // Convertir la cadena a objeto DateTime
                
//                 $horaFinal = strtotime($horaCadenaFinal->format('H:i'));

//                 $freeHour = false;
//                 while(!$freeHour) {
//                     $checkins = $this->DataOtherServicesCheckIn->find()->select()->where(['DataOtherServicesCheckIn.call_date' => $callDate, 'DataOtherServicesCheckIn.call_time' => date('H:i:s', $hora), 'DataOtherServicesCheckIn.deleted' => 0])->first();
//                     if(empty($checkins)){
//                         $arr_save_call_1 = [
//                             'uid' => Text::uuid(),
//                             'consultation_uid' => $consultation_uid,
//                             'patient_id' => USER_ID,
//                             //'patient_id' => 1414,
//                             //'call_date' => $callDate . ' ' . date('H:i:s', $hora), 
//                             'call_date' => $callDate, // Adjust the number of days according to your requirements
//                             'call_time' => date('H:i:s', $hora), // Adjust the number of days according to your requirements
//                             'status' => 'PENDING',
//                             'call_number' => $nextCallNumber,
//                             'call_type' => 'CHECK IN',
//                             'call_title' => $callTitle,
//                             'show' => $show,
//                             'created' => date('Y-m-d H:i:s'),
//                         ];

//                         $cq_entity_1 = $this->DataOtherServicesCheckIn->newEntity($arr_save_call_1);
//                         if (!$cq_entity_1->hasErrors()) {
//                             $result = $this->DataOtherServicesCheckIn->save($cq_entity_1);
//                             //save
//                             $this->set( 'data', $result );
//                             $this->success();
//                             $freeDateHour = true;
//                             $freeHour = true;
//                             return;
//                         }
//                     } else {
//                         // Sumar 20 minutos al timestamp
//                         $hora = $hora + (20 * 60);
//                         $this->set( 'horacall MWF', date('H:i:s', $hora) );

//                         //checar si esta a las 12
//                         if(date('H:i:s', $hora) === date('H:i:s', $horaFinal)){
//                             $freeHour = true;
//                             $i++;
//                         }
//                     }
                
//                 }
            
//             } else {
//                 //si fue fin de semana sumar uno al dia y repetir ciclo
//                 $i++;
//             }
//         }
//         return;
        
//     }
#endregion


    public function test_gage(){
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


        $gage = $this->gage_schedule( 'a', 15, 1, 'a', 1 );
        $this->set('data', $gage);
        $this->success();
    }

    public function semaglutide_types(){
        //$token = get('token',"");
        //
        //if(!empty($token)){
        //    
        //    $user = $this->AppToken->validateToken($token, true);
        //    if($user === false){
        //        $this->message('Invalid token.');
        //        $this->set('session', false);
        //        return;
        //    }
        //    $this->set('session', true);
        //} else {
        //    $this->message('Invalid token.');
        //    $this->set('session', false);
        //    return;
        //}

        $this->loadModel('SpaLiveV1.CatProductsOtherServices');

        
        $wl_products = $this->CatProductsOtherServices->find()->where(['CatProductsOtherServices.category' => "WEIGHT LOSS", 'CatProductsOtherServices.deleted' => 0])->all();
        

        // return el JSON
        $this->set('data', $wl_products);
        $this->success();

        return;
    }

    function get_schedules(){
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataScheduleDaysOff');
        $users = $this->SysUsers->find()->select(['SysUsers.id'])
            ->join([
                "DataUsersOtherServicesCheckIn" => [
                    "table" => "data_users_other_services_check_in",
                    "type" => "INNER",
                    "conditions" => [
                        "DataUsersOtherServicesCheckIn.user_id = SysUsers.id",
                        "DataUsersOtherServicesCheckIn.deleted" => 0
                    ]
                ]
            ])
            ->where([
                "SysUsers.deleted" => 0
            ])
            ->all();

        $model = 'other_services';
        $schedules = [];

        foreach($users as $user){
            $id = $user->id;
            
            //$lm_entity = $this->DataScheduleModel->find()
            //    ->where([
            //        'DataScheduleModel.deleted' => 0, 
            //        'DataScheduleModel.days NOT LIKE' => '', 
            //        'DataScheduleModel.model' => $model,
            //        'DataScheduleModel.injector_id' => $id
            //    ])->toArray();

            $days_settings = [];
            //if(!empty($lm_entity)){
            //    foreach($lm_entity as $item){
            //        $days = explode(',', $item->days);
            //        foreach($days as $day){
            //            if(!empty($day)){
            //                $days_settings[] = [
            //                    'days' => $day,
            //                    'time_start' => $item->time_start,
            //                    'time_end' => $item->time_end
            //                ];
            //            }
            //        }
            //    }            
            //} else {
                $days_settings = [
                    [
                        'days' => 'MONDAY',
                        'time_start' => 9,
                        'time_end' => 17
                    ],
                    [
                        'days' => 'TUESDAY',
                        'time_start' => 9,
                        'time_end' => 17
                    ],
                    [
                        'days' => 'WEDNESDAY',
                        'time_start' => 9,
                        'time_end' => 17
                    ],
                    [
                        'days' => 'THURSDAY',
                        'time_start' => 9,
                        'time_end' => 17
                    ],
                    [
                        'days' => 'FRIDAY',
                        'time_start' => 9,
                        'time_end' => 17
                    ],
                ];
            //}

            $days_off = $this->DataScheduleDaysOff->find()->select(['DataScheduleDaysOff.date_off'])
                ->where(['DataScheduleDaysOff.user_id' => $id, 'DataScheduleDaysOff.deleted' => 0])->toArray();
            $days_off_array = (!empty($days_off) ? Hash::extract($days_off, '{n}.date_off') : []);

            $schedules[] = [
                'id' => $id,
                'days_settings' => $days_settings,
                'days_off' => $days_off_array
            ];
        }

        return $schedules;
    }

    public function gage_schedule( $consultation_uid, $baseDays, $nextCallNumber, $callTitle, $show ){

        //$this->loadModel('SpaLiveV1.DataScheduleModel');
        $this->loadModel('SpaLiveV1.DataScheduleDaysOff');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');

        $schedules = $this->get_schedules();

        $current_date = date("Y-m-d");
        $current_date = date("Y-m-d", strtotime($current_date . ' + '.$baseDays.' days'));

        $id_gage_user = null;
        $desired_date = null;
        $desired_time = null;

        while($desired_date == null){

            $schedule_valid_days = [];
            // $checks = [];
            foreach ($schedules as $schedule_1) {                
                $days_off = $schedule_1['days_off'];
                $days_settings = $schedule_1['days_settings'];
                $day = date('l', strtotime($current_date));
                $is_in_schedule = $this->is_day_in_array($day, $days_settings);

                foreach($days_off as $day_off){
                    $day_odd = $day_off->i18nFormat('Y-MM-dd');
                    $current_datee = date('Y-m-d', strtotime($current_date));

                    if($day_odd == $current_datee){
                        $is_in_schedule = false;
                    }
                }   

                // $checks[] = array(
                //     'date' => $current_date,
                //     'is_in_schedule' => $is_in_schedule
                // );

                if($is_in_schedule){
                    $schedule_valid_days[] = $schedule_1;
                }
            }                                    
            // return $checks;

            if(!empty($schedule_valid_days)){
                foreach($schedule_valid_days as $schedule){
                    $day = date('l', strtotime($current_date));
                    $days_settings = $schedule['days_settings'];
                    $day_setting = $this->get_day_schedule($day, $days_settings);

                    if(empty($day_setting)){
                        continue;
                    }

                    $start = $day_setting['time_start'];
                    $end = $day_setting['time_end'];
                    
                    $start = $start.":00";
                    $end = $end.":00";

                    $times = $this->get_hours($start, $end);
                
                    $time = null;
                    foreach($times as $item){
                        $checkins = $this->DataOtherServicesCheckIn->find()->select()
                        ->where([
                            'DataOtherServicesCheckIn.call_date' => $current_date, 
                            'DataOtherServicesCheckIn.call_time' => $item, 
                            'DataOtherServicesCheckIn.deleted'   => 0
                        ])->first();

                        if(empty($checkins)){
                            $time = $item;
                            break;
                        }
                    }

                    if($time != null){
                        $schedule_valid_times[] = array(
                            'id' => $schedule['id'],
                            'time' => $time                        
                        );
                    }                    
                }                

                if(!empty($schedule_valid_times)){

                    usort($schedule_valid_times, function ($a, $b) {
                        return strtotime($a['time']) - strtotime($b['time']);
                    });

                    $first_date = $schedule_valid_times[0];

                    $id_gage_user = $first_date['id'];
                    $desired_time = $first_date['time'];
                    $desired_date = $current_date;
                }else{
                    $current_date = date("Y-m-d", strtotime($current_date . ' + 1 days'));
                }
            } else {
                $current_date = date("Y-m-d", strtotime($current_date . ' + 1 days'));
            }
        }

        // return array(
        //     'schedules'    => $schedules,
        //     'id_gage_user' => $id_gage_user,
        //     'desired_date' => $desired_date,
        //     'desired_time' => $desired_time,
        //     'formatted_date' => date('l, F jS, Y', strtotime($desired_date)),
        // );
        $timestamp = strtotime($desired_time);
        $formattedTime = date('H:i:s', $timestamp);

        $arr_save_call_1 = [
            'uid'              => Text::uuid(),
            'consultation_uid' => $consultation_uid,
            'patient_id'       => USER_ID,
        //    'support_id'       => $id_gage_user,
            'call_date'        => $desired_date,
            'call_time'        => $formattedTime,
            'status'           => 'CLAIM_PENDING',
            'call_number'      => $nextCallNumber,
            'call_type'        => 'CHECK IN',
            'call_title'       => $callTitle,
            'show'             => $show,
            'created'          => date('Y-m-d H:i:s'),
        ];

        $cq_entity_1 = $this->DataOtherServicesCheckIn->newEntity($arr_save_call_1);
        if (!$cq_entity_1->hasErrors()) {
            $result = $this->DataOtherServicesCheckIn->save($cq_entity_1);
            //save
            $this->set( 'data', $result );
            $this->set( 'time', $desired_time );
            $this->success();
            $freeDateHour = true;
            $freeHour = true;
            return;
        }
    }

    private function get_next_available_day(
        $date,
        $days_off,
        $days_settings
    ){
        $repeat = false;
        do {
            $day = date('l', strtotime($date));
            $is_in_schedule = $this->is_day_in_array($day, $days_settings);

            if(in_array($date, $days_off) || !$is_in_schedule  ){
                $date = date("Y-m-d", strtotime($date . ' + 1 days'));
                $repeat = true;
                continue;
            }

            $repeat = false;
        } while ($repeat);
        return $date;
    }

    private function is_day_in_array($dayToCheck, $array) {
        foreach ($array as $item) {
            if (isset($item['days']) && strtoupper($item['days']) === strtoupper($dayToCheck)) {
                return true;
            }
        }
        return false;
    }

    private function get_day_schedule($dayToCheck, $array) {
        foreach ($array as $item) {
            if (isset($item['days']) && strtoupper($item['days']) === strtoupper($dayToCheck)) {
                return $item;
            }
        }
        return array();
    }

    private function get_time(
        $day,
        $days_setting
    ){
        $time = null;
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        
        $start = $days_setting['time_start'];
        $end = $days_setting['time_end'];
        
        $start = $start.":00";
        $end = $end.":00";

        $times = $this->get_hours($start, $end);
        foreach($times as $item){
            $checkins = $this->DataOtherServicesCheckIn->find()->select()
            ->where([
                'DataOtherServicesCheckIn.call_date' => $day, 
                'DataOtherServicesCheckIn.call_time' => $item, 
                'DataOtherServicesCheckIn.deleted'   => 0
            ])->first();

            if(empty($checkins)){
                $time = $item;
                break;
            }
        }

        return $time;
    }

    private function get_hours(
        $start_date,
        $end_date
    ){
        $times = array();
        $start_date = strtotime($start_date);
        $end_date = strtotime($end_date);
        $gap = 20 * 60; 

        for ($hour = $start_date; $hour <= $end_date; $hour += $gap) {
            $formatted_hour = date("H:i", $hour); 
            $times[] = $formatted_hour;
        }

        return $times;
    }

    public function gage_calendar_old(){
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
        
        $year = get('year','');
        if (empty($year)) {
            $this->message('year not found.');
            return;
        }
        
        $month = get('month','');
        if (empty($month)) {
            $this->message('month not found.');
            return;
        }

        //formato Y m d
        $checkInDate = get('checkInDate','');
        if (empty($checkInDate)) {
            $this->message('checkInDate not found.');
            return;
        }

        //posibles fechas a agendar

        //fecha - 1
        $fecha_objeto1 = date_create($checkInDate);
        $fecha_objeto1->modify('-1 day');
        
        $fecha_modificada1 = $fecha_objeto1->format('Y-m-d');
        $diaMenosUno = date('N', strtotime($fecha_modificada1));

        //comprobar que no caiga en domingo, si lo hace restar -3
        if($diaMenosUno > 5){
            $fecha_objeto1->modify('-2 day');

        }

        $fecha_modificada1 = $fecha_objeto1->format('Y-m-d');
        $this->set( 'chekindate-1', $fecha_modificada1 );
        

        //fecha
        $this->set( 'chekindate', $checkInDate );

        //fecha + 1
        $fecha_objeto2 = date_create($checkInDate);
        $fecha_objeto2->modify('+1 day');
        
        $fecha_modificada2 = $fecha_objeto2->format('Y-m-d');
        $diaMasUno = date('N', strtotime($fecha_modificada2));

        //comprobar que no caiga en sabado, si lo hace sumar + 3
        if($diaMasUno > 5){
            $fecha_objeto2->modify('+2 day');
        }

        $fecha_modificada2 = $fecha_objeto2->format('Y-m-d');
        $this->set( 'chekindate+1', $fecha_modificada2 );

        //fecha + 2
        $fecha_objeto3 = date_create($checkInDate);
        $fecha_objeto3->modify('+2 day');
        
        $fecha_modificada3 = $fecha_objeto3->format('Y-m-d');
        $diaMasDos = date('N', strtotime($fecha_modificada3));

        //comprobar que no caiga en sabado, si lo hace sumar + 4
        if($diaMasDos == 6){
            $fecha_objeto3->modify('+2 day');
        }
        //comprobar que no caiga en domingo, si lo hace sumar + 3
        if($diaMasDos == 7){
            $fecha_objeto3->modify('+2 day');
        }

        $fecha_modificada3 = $fecha_objeto3->format('Y-m-d');
        $this->set( 'chekindate+2', $fecha_modificada3 );

        //return;
        //exit;
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');

        //$fecha = date("$year-$month-01");
        $totalDays = cal_days_in_month(CAL_GREGORIAN, intval($month), intval($year));

        // Arreglo para almacenar todos los días del mes
        $jsonArray = array();
        
        // Recorrer todos los días del mes
        for ($day = 1; $day <= $totalDays; $day++) {

            // Formatear el día y el mes con dos dígitos (por ejemplo, 01, 02, ..., 31)
            $formattedDay = str_pad(strval($day), 2, "0", STR_PAD_LEFT);
            $formattedMonth = str_pad($month, 2, "0", STR_PAD_LEFT);

            // Fecha completa en formato "YYYY-MM-DD"
            $date = "$year-$formattedMonth-$formattedDay";

            
            $diaSemana = date('N', strtotime($date));

            //if(){}
            // Estructura JSON para cada día
            $dayJson = array(
                "date" => $date,
                "appointments" => 0,
                "data" => array(),
                "dayoff" => true
            );

            //Horas dependiendo de que dia es
            if($diaSemana < 6){
                if( $diaSemana == 1 || $diaSemana == 3 || $diaSemana == 5){
                    // Horas del día
                    $hours = array(
                        "09:00 AM", "09:20 AM", "09:40 AM", 
                        "10:00 AM", "10:20 AM", "10:40 AM", 
                        "11:00 AM", "11:20 AM", "11:40 AM",
                    );

                    $values = array(
                        "09:00:00", "09:20:00", "09:40:00", 
                        "10:00:00", "10:20:00", "10:40:00", 
                        "11:00:00", "11:20:00", "11:40:00",
                    );
                    
                    //desbloquear dias disponibles
                    if( 
                        date_create($fecha_modificada1) == date_create("$year-$month-$day") ||
                        date_create($fecha_modificada2) == date_create("$year-$month-$day") ||
                        date_create($fecha_modificada3) == date_create("$year-$month-$day") ||
                        date_create($checkInDate) == date_create("$year-$month-$day")
                    ){
                        // Inicializar el arreglo JSON
                        $dayJson = array(
                            "date" => "$date",
                            "appointments" => 0,
                            "dayoff" => false,
                            "data" => array(),
                        );

                    } else {
                        // Inicializar el arreglo JSON
                        $dayJson = array(
                            "date" => "$date",
                            "appointments" => 0,
                            "dayoff" => true,
                            "data" => array(),
                        );

                    }

                    // Recorrer el arreglo de horas y agregar los datos correspondientes al arreglo JSON
                    foreach ($hours as $index => $time) {
                        $timeValue = $values[$index]; // Obtener el valor correspondiente de $values
                    
                        $fecha = date("$year-$month-$day");
                        $checkin = $this->DataOtherServicesCheckIn->find()->select()->where(
                            [
                                'DataOtherServicesCheckIn.call_date' => $fecha,
                                'DataOtherServicesCheckIn.call_time' => $time,
                                'DataOtherServicesCheckIn.deleted' => 0
                            ]
                        )->first();
                            
                        // Verificar si el checkin está vacío
                        if (empty($checkin)) {
                            $dayJson["data"][] = array(
                                "time" => $time,
                                "timeValue" => $timeValue, // Agregar el valor de timeValue al arreglo JSON
                                "data" => array(
                                    "status" => "free",
                                    "name" => "",
                                    "provider" => "",
                                    "date" => ""
                                )
                            );
                        } else {
                            $dayJson["data"][] = array(
                                "time" => $time,
                                "timeValue" => $timeValue, // Agregar el valor de timeValue al arreglo JSON
                                "data" => array(
                                    "status" => "ocuped",
                                    "name" => "",
                                    "provider" => "",
                                    "date" => ""
                                )
                            );
                        }
                    }
                }

                if( $diaSemana == 2 || $diaSemana == 4 ){
                    // Horas del día
                    $hours = array(
                        "12:00 PM", "12:20 PM", "12:40 PM", 
                        "01:00 PM", "01:20 PM", "01:40 PM", 
                        "02:00 PM", "02:20 PM", "02:40 PM", 
                    );

                    $values = array(
                        "12:00:00", "12:20:00", "12:40:00", 
                        "13:00:00", "13:20:00", "13:40:00", 
                        "14:00:00", "14:20:00", "14:40:00", 
                    );

                    //desbloquear dias disponibles
                    if( 
                        date_create($fecha_modificada1) == date_create("$year-$month-$day") ||
                        date_create($fecha_modificada2) == date_create("$year-$month-$day") ||
                        date_create($fecha_modificada3) == date_create("$year-$month-$day") ||
                        date_create($checkInDate) == date_create("$year-$month-$day")
                    ){
                        // Inicializar el arreglo JSON
                        $dayJson = array(
                            "date" => "$date",
                            "appointments" => 0,
                            "dayoff" => false,
                            "data" => array(),
                        );

                    } else {
                        // Inicializar el arreglo JSON
                        $dayJson = array(
                            "date" => "$date",
                            "appointments" => 0,
                            "dayoff" => true,
                            "data" => array(),
                        );

                    }

                    // Recorrer el arreglo de horas y agregar los datos correspondientes al arreglo JSON
                    foreach ($hours as $index => $time) {
                        $timeValue = $values[$index]; // Obtener el valor correspondiente de $values
                    
                        $fecha = date("$year-$month-$day");
                        $checkin = $this->DataOtherServicesCheckIn->find()->select()->where(
                            [
                                'DataOtherServicesCheckIn.call_date' => $fecha,
                                'DataOtherServicesCheckIn.call_time' => $timeValue, 
                                'DataOtherServicesCheckIn.deleted' => 0
                            ]
                        )->first();
                            
                        // Verificar si el checkin está vacío
                        if (empty($checkin)) {
                            $dayJson["data"][] = array(
                                "time" => $time,
                                "timeValue" => $timeValue, // Agregar el valor de timeValue al arreglo JSON
                                "data" => array(
                                    "status" => "free",
                                    "name" => "",
                                    "provider" => "",
                                    "date" => ""
                                )
                            );
                        } else {
                            $dayJson["data"][] = array(
                                "time" => $time,
                                "timeValue" => $timeValue, // Agregar el valor de timeValue al arreglo JSON
                                "data" => array(
                                    "status" => "ocuped",
                                    "name" => "",
                                    "provider" => "",
                                    "date" => ""
                                )
                            );
                        }
                    }
                }
            }

            
            
        // Agregar la estructura JSON del día al arreglo principal
        $jsonArray[] = $dayJson;
        }


        $this->set( 'data', $jsonArray );
        $this->success();
        

    }

    public function gage_calendar(){
        //$token = get('token', '');
        //
        //if(!empty($token)){
        //    $user = $this->AppToken->validateToken($token, true);
        //    if($user === false){
        //        $this->message('Invalid token.');
        //        $this->set('session', false);
        //        return;
        //    }
        //    $this->set('session', true);
        //} else {
        //    $this->message('Invalid token.');
        //    $this->set('session', false);
        //    return;
        //}
        
        $year = get('year','');
        if (empty($year)) {
            $this->message('year not found.');
            return;
        }
        
        $month = get('month','');
        if (empty($month)) {
            $this->message('month not found.');
            return;
        }

        //formato Y m d
        $checkInDate = get('checkInDate','');
        if (empty($checkInDate)) {
            $this->message('checkInDate not found.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataOtherServicesCheckInSchedule');

        //posibles fechas a agendar

        //fecha - 1
        $fecha_objeto1 = date_create($checkInDate);
        $fecha_objeto1->modify('-1 day');
        
        $fecha_modificada1 = $fecha_objeto1->format('Y-m-d');
        $diaMenosUno = date('N', strtotime($fecha_modificada1));

        $diaMenosUnoGage = $this->DataOtherServicesCheckInSchedule->find()->select()->where(['DataOtherServicesCheckInSchedule.num_day' => intval($diaMenosUno)])->first();
        //comprobar que no caiga en domingo, si lo hace restar -3
        while($diaMenosUnoGage->day_off == 1){
            $fecha_objeto1->modify('-1 day');
            $fecha_modificada1 = $fecha_objeto1->format('Y-m-d');
            $diaMenosUno = date('N', strtotime($fecha_modificada1));
            $diaMenosUnoGage = $this->DataOtherServicesCheckInSchedule->find()->select()->where(['DataOtherServicesCheckInSchedule.num_day' => intval($diaMenosUno)])->first();
        }

        $this->set( 'chekindate-1', $fecha_modificada1 );
        

        //fecha
        $this->set( 'chekindate', $checkInDate );

        //fecha + 1
        $fecha_objeto2 = date_create($checkInDate);
        $fecha_objeto2->modify('+1 day');
        
        $fecha_modificada2 = $fecha_objeto2->format('Y-m-d');
        $diaMasUno = date('N', strtotime($fecha_modificada2));

        $diaMasUnoGage = $this->DataOtherServicesCheckInSchedule->find()->select()->where(['DataOtherServicesCheckInSchedule.num_day' => intval($diaMasUno)])->first();
        //comprobar que no caiga en domingo, si lo hace restar -3
        while($diaMasUnoGage->day_off == 1){
            $fecha_objeto2->modify('+1 day');
            $fecha_modificada2 = $fecha_objeto2->format('Y-m-d');
            $diaMasUno = date('N', strtotime($fecha_modificada2));
            $diaMasUnoGage = $this->DataOtherServicesCheckInSchedule->find()->select()->where(['DataOtherServicesCheckInSchedule.num_day' => intval($diaMasUno)])->first();
        }

        $fecha_modificada2 = $fecha_objeto2->format('Y-m-d');
        $this->set( 'chekindate+1', $fecha_modificada2 );

        //fecha + 2 requiem
        $fecha_objeto3 = date_create($fecha_modificada2);
        $fecha_objeto3->modify('+1 day');

        $fecha_modificada3 = $fecha_objeto3->format('Y-m-d');
        $diaMasDos = date('N', strtotime($fecha_modificada3));

        $diaMasDosGage = $this->DataOtherServicesCheckInSchedule->find()->select()->where(['DataOtherServicesCheckInSchedule.num_day' => intval($diaMasDos)])->first();
        while($diaMasDosGage->day_off == 1){
            $fecha_objeto3->modify('+1 day');
            $fecha_modificada3 = $fecha_objeto3->format('Y-m-d');
            $diaMasDos = date('N', strtotime($fecha_modificada3));
            $diaMasDosGage = $this->DataOtherServicesCheckInSchedule->find()->select()->where(['DataOtherServicesCheckInSchedule.num_day' => intval($diaMasDos)])->first();
        }

        $fecha_modificada3 = $fecha_objeto3->format('Y-m-d');
        $this->set( 'chekindate+2', $fecha_modificada3 );
        
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');

        //$fecha = date("$year-$month-01");
        $totalDays = cal_days_in_month(CAL_GREGORIAN, intval($month), intval($year));

        // Arreglo para almacenar todos los días del mes
        $jsonArray = array();
        
        // Recorrer todos los días del mes
        for ($day = 1; $day <= $totalDays; $day++) {

            // Formatear el día y el mes con dos dígitos (por ejemplo, 01, 02, ..., 31)
            $formattedDay = str_pad(strval($day), 2, "0", STR_PAD_LEFT);
            $formattedMonth = str_pad($month, 2, "0", STR_PAD_LEFT);

            // Fecha completa en formato "YYYY-MM-DD"
            $date = "$year-$formattedMonth-$formattedDay";

            
            $diaSemana = date('N', strtotime($date));

            
            $gage_schedule = $this->DataOtherServicesCheckInSchedule->find()->select()->where(['DataOtherServicesCheckInSchedule.num_day' => intval($diaSemana)])->first();

            //checa si chambea
            if($gage_schedule->day_off == 0){
                //obtner horas
                $horaCadena = $gage_schedule->start_hour;
                // Convertir la cadena a objeto DateTime
                
                $hora = strtotime($horaCadena->format('H:i'));

                
                $horaCadenaFinal = $gage_schedule->end_hour;
                // Convertir la cadena a objeto DateTime
                
                $horaFinal = strtotime($horaCadenaFinal->format('H:i'));

                $hours = array(); // Array para almacenar las iteraciones
                while ($hora < $horaFinal) {
                    //iterar hasta hour
                    $hours[] = date('H:i:s', $hora); // Agregar al array
                    $hora = $hora + (20 * 60);
                    
                    //checar si esta a las 12
                    if(date('H:i:s', $hora) === date('H:i:s', $horaFinal)){
                    }
                }

                //desbloquear dias disponibles
                if( 
                    //date_create($fecha_modificada1) == date_create("$year-$month-$day") ||
                    date_create($fecha_modificada2) == date_create("$year-$month-$day") ||
                    date_create($fecha_modificada3) == date_create("$year-$month-$day") ||
                    date_create($checkInDate) == date_create("$year-$month-$day")
                ){
                    //comprobar que el dia anterior no se pueda seleccionar si estamos en el dia de la cita
                    //if( date_create($fecha_modificada1) == date_create("$year-$month-$day") ){
                    //    // Inicializar el arreglo JSON
                    //    $dayJson = array(
                    //        "date" => "$date",
                    //        "appointments" => 0,
                    //        "dayoff" => true,
                    //        "data" => array(),
                    //    );
                    //}

                    //$fechaHoy = "2023-09-14";
                    $fechaHoy = date('Y-m-d');
                    if (date_create("$year-$month-$day") < date_create($fechaHoy)) {
                        $dayJson = array(
                            "date" => "$date",
                            "appointments" => 0,
                            "dayoff" => true,
                            "data" => array(),
                        );
                    } else {
                        // Inicializar el arreglo JSON
                        $dayJson = array(
                            "date" => "$date",
                            "appointments" => 0,
                            "dayoff" => false,
                            "data" => array(),
                        );

                        // Recorrer el arreglo de horas y agregar los datos correspondientes al arreglo JSON
                        foreach ($hours as $index => $time) {
                            //$timeValue = $values[$index]; // Obtener el valor correspondiente de $values
                        
                            $fecha = date("$year-$month-$day");
                            $checkin = $this->DataOtherServicesCheckIn->find()->select()->where(
                                [
                                    'DataOtherServicesCheckIn.call_date' => $fecha,
                                    'DataOtherServicesCheckIn.call_time' => $time,
                                    'DataOtherServicesCheckIn.deleted' => 0
                                ]
                            )->first();
                                
                            // Verificar si el checkin está vacío
                            if (empty($checkin)) {
                                $dayJson["data"][] = array(
                                    "time" => date("g:i A", strtotime($time)),
                                    "timeValue" => $time, // Agregar el valor de timeValue al arreglo JSON
                                    "data" => array(
                                        "status" => "free",
                                        "name" => "",
                                        "provider" => "",
                                        "date" => ""
                                    )
                                );
                            } else {
                                $dayJson["data"][] = array(
                                    "time" => date("g:i A", strtotime($time)),
                                    "timeValue" => $time, // Agregar el valor de timeValue al arreglo JSON
                                    "data" => array(
                                        "status" => "ocuped",
                                        "name" => "",
                                        "provider" => "",
                                        "date" => ""
                                    )
                                );
                            }
                        }
                    }

                    
                    

                } //elseif que comprueba si es el dia de hoy
                elseif ( date_create($fecha_modificada1) == date_create("$year-$month-$day") ) {
                    $fechaHoy = date('Y-m-d');
                    if ($fecha_objeto1 < date_create($fechaHoy)) {
                        $dayJson = array(
                            "date" => "$date",
                            "appointments" => 0,
                            "dayoff" => true,
                            "data" => array(),
                        );
                    } else {
                        $dayJson = array(
                            "date" => "$date",
                            "appointments" => 0,
                            "dayoff" => false,
                            "data" => array(),
                            //"fecha_objeto1" => "$fecha_objeto1",
                            "fechaHoy" => "$fechaHoy",
                        );
    
                        
                        // Recorrer el arreglo de horas y agregar los datos correspondientes al arreglo JSON
                        foreach ($hours as $index => $time) {
                            //$timeValue = $values[$index]; // Obtener el valor correspondiente de $values
                        
                            $fecha = date("$year-$month-$day");
                            $checkin = $this->DataOtherServicesCheckIn->find()->select()->where(
                                [
                                    'DataOtherServicesCheckIn.call_date' => $fecha,
                                    'DataOtherServicesCheckIn.call_time' => $time,
                                    'DataOtherServicesCheckIn.deleted' => 0
                                ]
                            )->first();
                                
                            // Verificar si el checkin está vacío
                            if (empty($checkin)) {
                                $dayJson["data"][] = array(
                                    "time" => date("g:i A", strtotime($time)),
                                    "timeValue" => $time, // Agregar el valor de timeValue al arreglo JSON
                                    "data" => array(
                                        "status" => "free",
                                        "name" => "",
                                        "provider" => "",
                                        "date" => ""
                                    )
                                );
                            } else {
                                $dayJson["data"][] = array(
                                    "time" => date("g:i A", strtotime($time)),
                                    "timeValue" => $time, // Agregar el valor de timeValue al arreglo JSON
                                    "data" => array(
                                        "status" => "ocuped",
                                        "name" => "",
                                        "provider" => "",
                                        "date" => ""
                                    )
                                );
                            }
                        }
                    }
                }
                else {
                    // Inicializar el arreglo JSON
                    $dayJson = array(
                        "date" => "$date",
                        "appointments" => 0,
                        "dayoff" => true,
                        "data" => array(),
                    );
                }


            } else {
                
                // Estructura JSON para cada día
                $dayJson = array(
                    "date" => $date,
                    "appointments" => 0,
                    "data" => array(),
                    "dayoff" => true
                );
            }

            

            
            
            // Agregar la estructura JSON del día al arreglo principal
            $jsonArray[] = $dayJson;
        }


        $this->set( 'data', $jsonArray );
        $this->success();
        

    }

    public function reschedule_service(){
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

        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $this->message('consultation_uid not found.');
            return;
        }

        $scheduled_date = get('scheduled_date','');
        if (empty($scheduled_date)) {
            $this->message('scheduled_date not found.');
            return;
        }

        $date_id = get('date_id','');
        if (empty($date_id)) {
            $this->message('date_id not found.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');

        $this->DataOtherServicesCheckIn->updateAll(
            ['call_date' => $scheduled_date], 
            ['id' => $date_id]
        );

        $schedule_aux = new FrozenTime($scheduled_date);
        $s_date = date('m/d/Y H:i',strtotime($scheduled_date));
        $this->set('schedule_date', $schedule_aux->i18nFormat('MM-dd-yyyy'));

        $firebase_update_data = array(
            "uid" => $consultation_uid,
            "status" => 'CLAIM PENDING',
            "examiner_uid" => "",
            "is_waiting" => 0,
            "scheduled_date" => $s_date,
        );
        
        //print_r($data);exit;
       /* $result  = $collectionReference->add($data);            
        $name = $result->name();
        
        //$firebase_document = substr($name, strpos($name, "services/") + 9);*/
        $data_string = json_encode($firebase_update_data);
        $this->set('firebase_update_data', $firebase_update_data);
        $url = env('URL_MICROSERVICE', 'http://localhost:3131/') . 'updateServicesArr';
        $ch=curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);//curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER,
            array(
                'Content-Type:application/json',
                'Content-Length: ' . strlen($data_string)
            )
        );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);

        $this->set('result_curl', $result);
        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            $this->message($error_msg);
            $this->success(false);
            return;
            // this would be your first hint that something went wrong                            
        } else {
            // check the HTTP status code of the request
            $resultStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($resultStatus == 200) {
                //unlink($filename);// everything went better than expected
            } else {
                // the request did not complete as expected. common errors are 4xx
                // (not found, bad request, etc.) and 5xx (usually concerning
                // errors/exceptions in the remote script execution)                                
            }
        }
        curl_close($ch);

        $this->success();
        $this->message("Success.");    
        return;

    }

    public function reschedule_gage_service(){
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

        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $this->message('consultation_uid not found.');
            return;
        }

        $scheduled_date = get('scheduled_date','');
        if (empty($scheduled_date)) {
            $this->message('scheduled_date not found.');
            return;
        }

        $scheduled_time = get('scheduled_time','');
        if (empty($scheduled_time)) {
            $this->message('scheduled time not found.');
            return;
        }
        
        $date_id = get('date_id','');
        if (empty($date_id)) {
            $this->message('date_id not found.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        //SUPPORT ID 
        $this->DataOtherServicesCheckIn->updateAll(
            ['call_time' => $scheduled_time, 'call_date' => $scheduled_date,'status' => 'CLAIM_PENDING'], 
            ['id' => $date_id]
        );

        $schedule_aux = new FrozenTime($scheduled_date);

        $this->set('schedule_date', $schedule_aux->i18nFormat('MM-dd-yyyy'));
        $m_date = date('m/d/Y H:i',strtotime($schedule_aux->i18nFormat('yyyy-MM-dd') . " " . $scheduled_time) );                
        $firebase_update_data = array(
            "uid" => $consultation_uid,
            "status" => 'CLAIM PENDING',
            "examiner_uid" => "",
            "is_waiting" => 0,
            "scheduled_date" => $m_date,
        );
        $Main = new MainController();        
        $r = $Main->generateMeeting($schedule_aux->i18nFormat('MM-dd-yyyy'));        
        
        if ($r) {
            if(isset($r['code'])){
                $this->log(__FILE__ . " ".__LINE__ . " zoom code " .json_encode($r['code']));
                $this->set('zoom_code', json_encode($r['code']));
            }                
            if(isset($r['message'])){
                $this->log(__FILE__ . " ".__LINE__ . " zoom code " .json_encode($r['message']));
                $this->set('zoom_message', json_encode($r['message']));
            }
            
            $jwt_data = isset($r['jwt']) ?$r['jwt']:'';                
            $this->set('meeting', isset($r['id'])?$r['id'] :'');
            $this->set('meeting_pass', isset($r['password'])?$r['password'] :'');
            $this->set('join_url', isset($r['join_url'])?$r['join_url'] :'');
            $this->set('jwt', isset($r['jwt'])?$r['jwt'] :'');
            $firebase_update_data['meeting'] = isset($r['id']) ? strval($r['id']) :'';
            $firebase_update_data['meeting_pass'] = isset($r['password'])?$r['password'] :'';
            $firebase_update_data['join_url'] = isset($r['join_url']) ?$r['join_url']:'';
            $firebase_update_data['jwt'] = isset($r['jwt'])?$r['jwt'] :'';

            $array_save['meeting'] = $r['id'];
            $array_save['meeting_pass'] = $r['password'];
            $array_save['join_url'] = $r['join_url'];
            $this->DataConsultationOtherServices->updateAll(
                $array_save, 
                ['uid' => $consultation_uid]
            );
        } else {
            $this->message("Error to create the meeting.");
            return;
        }
        //print_r($data);exit;
       /* $result  = $collectionReference->add($data);            
        $name = $result->name();
        
        //$firebase_document = substr($name, strpos($name, "services/") + 9);*/
        $data_string = json_encode($firebase_update_data);
        $this->set('firebase_update_data', $firebase_update_data);
        $url = env('URL_MICROSERVICE', 'http://localhost:3131/') . 'updateServicesArr';
        $ch=curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);//curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER,
            array(
                'Content-Type:application/json',
                'Content-Length: ' . strlen($data_string)
            )
        );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);

        $this->set('result_curl', $result);
        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            $this->message($error_msg);
            $this->success(false);
            return;
            // this would be your first hint that something went wrong                            
        } else {
            // check the HTTP status code of the request
            $resultStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($resultStatus == 200) {
                //unlink($filename);// everything went better than expected
            } else {
                // the request did not complete as expected. common errors are 4xx
                // (not found, bad request, etc.) and 5xx (usually concerning
                // errors/exceptions in the remote script execution)                                
            }
        }
        curl_close($ch);

        $this->success();
        $this->message("Success.");
        return;

    }

    public function receive_signature(){
        //token
        //signature
        //id de la que va a cambiar
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


        $id = get('id', '');
        $signature = get('sign', '');
        if(empty($id) || $id == ''){
            $this->message('Empty id.');
            return;
        }

        //guardar firma
        if (!isset($_FILES['file'])) {
            $this->set('error_file',$_FILES);
            return;
        }

        if (!isset($_FILES['file']['name'])) {
            $this->set('error_name',$_FILES['file']);
            return;
        }

        $str_name = $_FILES['file']['name'];
        $_file_id = $this->Files->upload([
            'name' => $str_name,
            'type' => $_FILES['file']['type'],
            'path' => $_FILES['file']['tmp_name'],
            'size' => $_FILES['file']['size'],
        ]);

        if($_file_id <= 0){
            $this->message('Error in save content file.');
            return;
        }

        $string_products = get('products', '');

        if(empty($string_products)){
            $this->message('Products empty.');
            return;
        }

        $products = json_decode($string_products);

        $this->loadModel('SpaLiveV1.DataPurchasesOtherServices');
        $this->loadModel('SpaLiveV1.DataPurchasesDetailOtherServices');

        $this->loadModel('SpaLiveV1.DataPurchases');
        $this->loadModel('SpaLiveV1.DataPurchasesDetail');
        
       // $state = $this->DataPurchasesOtherServices->find()->select(['DataPurchasesOtherServices.id', 'DataPurchasesOtherServices.name', 'DataPurchasesOtherServices.uid'])->where(['DataPurchasesOtherServices.id' => $user['user_state']])->first();
        $array_save_p = array(
            'id' => !empty($id) ? (int)$id : null,
            'signature' => intval($_file_id),
            'status' => "RECEIVED",
            'received_date' => date('Y-m-d'),
        );
        
        $c_entity_p = $this->DataPurchases->newEntity($array_save_p);
        if(!$c_entity_p->hasErrors()) {
            
            if ($this->DataPurchases->save($c_entity_p)) {

                $flag = true;
                $error = "";

                foreach($products as $p) {

                    $array_save_products = array(
                        'purchase_id'   => $id,
                        'product_id'    => $p->product->id,
                        'price'         => $p->product->unit_price,
                        'qty'           => $p->product->qty,
                    );
                    
                    $c_entity_products = $this->DataPurchasesDetail->newEntity($array_save_products);
                    if(!$c_entity_products->hasErrors()) {
                        
                        if (!$this->DataPurchasesDetail->save($c_entity_products)) {
                            $error = 'Error in trying to save product with id: '.$p->id;
                            $flag = false;
                            break;
                        }
                    }
                }

                if($flag){ 
                    $this->success();
                }else{
                    $this->message($error);
                    return;
                }

            }else{
                $this->message('Error in update purchase.');
                return;
            }
        }

        
        $array_save = array(
            'id' => !empty($id) ? (int)$id : null,
            'signature' => intval($_file_id),
            'status' => "RECEIVED",
            'shipping_date' => date('Y-m-d'),
        );
        
        $c_entity = $this->DataPurchasesOtherServices->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            
            if ($this->DataPurchasesOtherServices->save($c_entity)) {

                $flag = true;
                $error = "";

                foreach($products as $p) {

                    $array_save_products = array(
                        'purchase_id'   => $id,
                        'product_id'    => $p->product->id,
                        'price'         => $p->product->unit_price,
                        'qty'           => $p->product->qty,
                    );
                    
                    $c_entity_products = $this->DataPurchasesDetailOtherServices->newEntity($array_save_products);
                    if(!$c_entity_products->hasErrors()) {
                        
                        if (!$this->DataPurchasesDetailOtherServices->save($c_entity_products)) {
                            $error = 'Error in trying to save product with id: '.$p->id;
                            $flag = false;
                            break;
                        }
                    }
                }

                if($flag){ 
                    $this->success();
                }else{
                    $this->message($error);
                    return;
                }

            }else{
                $this->message('Error in update purchase.');
                return;
            }
        }

    }

    public function get_past_trestments(){
        
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


        $consultation_uid = get('consultation_uid', '');
        if(empty($consultation_uid) || $consultation_uid == ''){
            $this->message('Empty consultation_uid.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        
        $checkins = $this->DataOtherServicesCheckIn->find()->select()->where(['DataOtherServicesCheckIn.consultation_uid' => $consultation_uid, 'DataOtherServicesCheckIn.deleted' => 0])->all();
        $this->set('data', $checkins); 
        
        $this->success();
    }
    
    public function get_current_wl_treatment(){
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');

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

        $patient_id = get('patient_id','');
        if (empty($patient_id)) {
            $this->message('patient_id not found.');
            return;
        }

        $consultation = $this->DataConsultationOtherServices->find()->select()->where(['DataConsultationOtherServices.patient_id' => $patient_id, 'DataConsultationOtherServices.status' => 'WAITING', 'DataConsultationOtherServices.deleted' => 0])->first();
        
        if ($consultation) {
            $consultation_uid = $consultation->uid;
            //$this->set('consultation_uid', $consultation_uid);
        } else {
            echo "No se encontraron resultados";
        }
        if(empty($consultation_uid) || $consultation_uid == ''){
            $this->message('Empty consultation_uid.');
            return;
        }
//////
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.DataPurchasesOtherServices');
        
        $checkins = $this->DataOtherServicesCheckIn->find()->select()->where(['DataOtherServicesCheckIn.consultation_uid' => $consultation_uid, 'DataOtherServicesCheckIn.deleted' => 0])->all();
        $purchases = $this->DataPurchasesOtherServices->find()->select()->where(['DataPurchasesOtherServices.payment_intent' => $consultation['payment_intent'], 'DataPurchasesOtherServices.deleted' => 0])->all();

        $consultation->purchases = $purchases;
        $consultation->checkins = $checkins;
        //$this->set('paymentint', $consultation['payment_intent']); 
        $this->set('data', $consultation); 
        //$this->set('checkins', $checkins);
        //$this->set('purchases', $purchases);
        $this->success();
    }

    public function get_signature_2(){
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


        $consultation_uid = get('consultation_uid', '');
        if(empty($consultation_uid) || $consultation_uid == ''){
            $this->message('Empty consultation_uid.');
            return;
        }

        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $this->loadModel('SpaLiveV1.DataPurchases');
        
        //$patient_id = USER_ID;
        
        //$payment_intent = $this->DataConsultationOtherServices->find()->select(['DataConsultationOtherServices.payment_intent'])->where(['DataConsultationOtherServices.uid' => $consultation_uid])->first();
        $purchase = $this->DataPurchases->find()
            ->select(['DataPurchases.id', 
                        'DataPurchases.created', 
                        'DataPurchases.name', 
                        'DataPurchases.uid', 
                        'DataPurchases.address', 
                        'DataPurchases.city', 
                        'DataPurchases.state', 
                        'DataPurchases.zip', 
                        'DataPurchases.signature', 
                        'DataPurchases.amount', 
                        'DataPurchases.status', 
                        'DataPurchases.call_type',
                        'DataPurchases.call_number',
                        'DataPurchases.tracking',
                        'DataPurchases.tracking2',
                        'DataPurchases.show',
                        'DataPurchases.delivery_company', 
                        'DataPurchases.shipping_date', 
                        'DataPurchases.received_date'])
            //->where(['DataPurchases.payment_intent' => $payment_intent['payment_intent']])->all();
            ->where(['DataPurchases.uid' => $consultation_uid])->all();

        foreach($purchase as $row) {
            $row->call_number = intval($row->call_number);
            $fecha_objeto1 = $row->shipping_date;
            $fecha_modificada1 = $fecha_objeto1->format('m-d-Y');
            $row->shipping_date_string = $fecha_modificada1;
        }

        $this->set('data', $purchase); 


        
        $this->success();
    }

    public function get_signature(){
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

        $consultation_uid = get('consultation_uid', '');
        if(empty($consultation_uid) || $consultation_uid == ''){
            $this->message('Empty consultation_uid.');
            return;
        }

        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.DataPurchases');
        
        //$patient_id = USER_ID;
        //obtener purchases por checkin 
        $getCalls =  $this->DataOtherServicesCheckIn->find()
        ->where([
            'DataOtherServicesCheckIn.consultation_uid' => $consultation_uid,
            'DataOtherServicesCheckIn.deleted' => 0,
            'DataOtherServicesCheckIn.call_type IN' => ['CHECK IN', 'FIRST CONSULTATION'],
            'DataOtherServicesCheckIn.purchase_id <>' => 0,
        ])
        ->all();
        //print_r(json_encode($getPurchases));
        //exit();


        //$previousPurchase = $this->DataPurchases->find()
        //    ->where([
        //        'DataPurchases.id' => $getPurchaseId->purchase_id,
        //        'DataPurchases.deleted' => 0
        //    ])
        //->first();
        $purchase = [];
        foreach($getCalls as $row) {
            //print_r($row['purchase_id']);

            $purchases = $this->DataPurchases->find()
            ->select(['DataPurchases.id', 
                        'DataPurchases.created', 
                        'DataPurchases.name', 
                        'DataPurchases.uid', 
                        'DataPurchases.address', 
                        'DataPurchases.city', 
                        'DataPurchases.state', 
                        'DataPurchases.zip', 
                        'DataPurchases.signature', 
                        'DataPurchases.amount', 
                        'DataPurchases.status', 
                        'DataPurchases.call_type',
                        'DataPurchases.call_number',
                        'DataPurchases.tracking',
                        'DataPurchases.tracking2',
                        'DataPurchases.show',
                        'DataPurchases.delivery_company', 
                        'DataPurchases.shipping_date', 
                        'DataPurchases.received_date'])
            //->where(['DataPurchases.payment_intent' => $payment_intent['payment_intent']])->all();
            ->where(['DataPurchases.id' => $row['purchase_id']])->first();
        
            if ($purchases) {
                $fecha_objeto1 = $purchases->shipping_date;
                $fecha_modificada1 = $fecha_objeto1 == null || $fecha_objeto1 == '' ? '' : $fecha_objeto1->format('m-d-Y');
                $purchases->shipping_date_string = $fecha_modificada1;
                
                //$purchase[] = $purchases; // Agrega los resultados al arreglo $purchase
            }
    
            $purchase[] = $purchases;
        }
        //exit();
        //$payment_intent = $this->DataConsultationOtherServices->find()->select(['DataConsultationOtherServices.payment_intent'])->where(['DataConsultationOtherServices.uid' => $consultation_uid])->first();
        $this->set('data', $purchase); 
        
        $this->success();
    }

    public function get_signature_c(){
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


        $consultation_uid = get('consultation_uid', '');
        if(empty($consultation_uid) || $consultation_uid == ''){
            $this->message('Empty consultation_uid.');
            return;
        }

        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $this->loadModel('SpaLiveV1.DataPurchasesOtherServices');
        
        $patient_id = USER_ID;

        
        $payment_intent = $this->DataConsultationOtherServices->find()->select(['DataConsultationOtherServices.payment_intent'])->where(['DataConsultationOtherServices.uid' => $consultation_uid])->first();
        
        $state = $this->DataPurchasesOtherServices->find()
            ->select(['DataPurchasesOtherServices.id', 
                        'DataPurchasesOtherServices.created', 
                        'DataPurchasesOtherServices.name', 
                        'DataPurchasesOtherServices.uid', 
                        'DataPurchasesOtherServices.address', 
                        'DataPurchasesOtherServices.city', 
                        'DataPurchasesOtherServices.state', 
                        'DataPurchasesOtherServices.zip', 
                        'DataPurchasesOtherServices.signature', 
                        'DataPurchasesOtherServices.amount', 
                        'DataPurchasesOtherServices.status', 
                        'DataPurchasesOtherServices.call_type',
                        'DataPurchasesOtherServices.call_number',
                        'DataPurchasesOtherServices.show',
                        'DataPurchasesOtherServices.delivery_company', 
                        'DataPurchasesOtherServices.shipping_date', 
                        'DataPurchasesOtherServices.received_date'])
            ->where(['DataPurchasesOtherServices.payment_intent' => $payment_intent['payment_intent']])->all();
        $this->set('data', $state); 
        
        $this->success();
    }

    public function get_checkinv(){
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


        $consultation_uid = get('consultation_uid', '');
        if(empty($consultation_uid) || $consultation_uid == ''){
            $this->message('Empty consultation_uid.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        
        $checkins = $this->DataOtherServicesCheckIn->find()->select()
            ->where(['DataOtherServicesCheckIn.consultation_uid' => $consultation_uid, 
                    'DataOtherServicesCheckIn.show' => 1,
                    'DataOtherServicesCheckIn.deleted' => 0])->orderDesc('DataOtherServicesCheckIn.id')->all();
        $this->set('data', $checkins); 
        
        $this->success();
    }


    public function get_checkin(){
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

        $consultation_uid = get('consultation_uid', '');
        if(empty($consultation_uid) || $consultation_uid == ''){
            $this->message('Empty consultation_uid.');
            return;
        }
        
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.DataPurchases');
        $this->loadModel('SpaLiveV1.DataPurchasesDetail');
        $this->loadModel('SpaLiveV1.DataPurchasesOtherServices');
        $this->loadModel('SpaLiveV1.DataPurchasesDetailOtherServices');
        $this->loadModel('SpaLiveV1.CatProductsOtherServices');
        $this->loadModel('SpaLiveV1.SysUsers');

        $_fields = ['examiner_name' => "IF(DataOtherServicesCheckIn.support_id = 0, 'Marie Beauviour', CONCAT_WS(' ', SysUsers.name, SysUsers.lname))", 'checkin_time' => "DATE_FORMAT(DataOtherServicesCheckIn.call_time, '%H:%i')"];
        // $_fields = ['checkin_time'     => "DATE_FORMAT(DataOtherServicesCheckIn.call_time, '%H:%i')"];
        $checkins_query = $this->DataOtherServicesCheckIn->find()
        ->select($this->DataOtherServicesCheckIn)
        ->select($this->DataPurchases)
        ->select($_fields)
        ->join([
            'DataPurchases' => [
                'table' => 'data_purchases', 
                'type' => 'LEFT', 
                'conditions' => 'DataPurchases.id = DataOtherServicesCheckIn.purchase_id'],
            'SysUsers' => [
                'table' => 'sys_users', 
                'type' => 'LEFT', 
                'conditions' => 'SysUsers.id = DataOtherServicesCheckIn.support_id'],
        ])->where(['DataOtherServicesCheckIn.consultation_uid' =>  $consultation_uid, 'DataOtherServicesCheckIn.deleted' => 0, 'DataOtherServicesCheckIn.show' => 1
        ])->all();                            

        foreach($checkins_query as $row) {
            if($row->call_type == "CHECK IN" ){
                if($row->status == "CLAIM_PENDING" || $row->status == "CLAIM"){               
                    $strDate = $row['call_date'].' '.$row['checkin_time'];
                    $limitDate = new \DateTime($strDate);
                    $limitDate->modify('+1 day');

                    if($limitDate < \DateTime::createFromFormat('Y-m-d H:i:s', date('Y-m-d H:i:s'))){
                        if($row->re_scheduled == null){
                            $call_number = $row->call_number;

                            if($call_number != 6){
                                if($call_number == 2){
                                    $this->DataOtherServicesCheckIn->updateAll(
                                        [
                                            'show' => 1
                                        ], 
                                        [                                   
                                            'patient_id'  => $row->patient_id,     
                                            'call_number' => 1,
                                            'call_type'   => 'FOLLOW UP'
                                        ]                        
                                    );
                                        // pr($row->id);
                                        // pr(1);
                                }else if($call_number == 4){
                                    $this->DataOtherServicesCheckIn->updateAll(
                                        [
                                            'show' => 1
                                        ], 
                                        [                                   
                                            'patient_id'  => $row->patient_id,     
                                            'call_number' => 2,
                                            'call_type'   => 'FOLLOW UP'
                                        ]                        
                                    );
                                    // pr($row->id);
                                    // pr(2);
                                }else{
                                    $this->DataOtherServicesCheckIn->updateAll(
                                        [
                                            'show' => 1
                                        ], 
                                        [                                   
                                            'patient_id'  => $row->patient_id,     
                                            'call_number' => $call_number + 1,
                                            'call_type'   => 'CHECK IN'
                                        ]                        
                                    );
                                    // pr($row->id);
                                    // pr(3);
                                }
                                $this->DataOtherServicesCheckIn->updateAll(
                                    ['show' => 0], 
                                    ['id' => $row->id]                        
                                );
                            }
                            
                        }
                    }
                }
            }
        }

        $calls = $this->DataOtherServicesCheckIn->find()
        ->select($this->DataOtherServicesCheckIn)
        ->select($this->DataPurchases)
        ->select($_fields)
        ->join([
            'DataPurchases' => [
                'table' => 'data_purchases', 
                'type' => 'LEFT', 
                'conditions' => 'DataPurchases.id = DataOtherServicesCheckIn.purchase_id'],
            'SysUsers' => [
                'table' => 'sys_users', 
                'type' => 'LEFT', 
                'conditions' => 'SysUsers.id = DataOtherServicesCheckIn.support_id'],
        ])->where(['DataOtherServicesCheckIn.consultation_uid' =>  $consultation_uid, 'DataOtherServicesCheckIn.deleted' => 0,
        ])->orderDesc('DataOtherServicesCheckIn.id')->all();

        foreach($calls as $call) {

            //agrega una llave nueva con un dia anterior a la fecha de la llamada para validacion de boton de fotos
            $now_date = date('Y-m-d H:i');
            $dateTimeObj = $call['call_date'];
            $dateString = $dateTimeObj->format('Y-m-d');

            $timestamp = strtotime($dateString);
            $previousTimestamp = $timestamp - 86400;
            $previousDate = date('Y-m-d', $previousTimestamp);
        
            $call['call_date_previous'] = $previousDate;

            $date_previous = date('Y-m-d 08:00', strtotime($call['call_date']->i18nFormat('yyyy-MM-dd') . '- 1 day'));

            $call['show_photo_button'] = false;
            if($now_date >= $date_previous){
                $call['show_photo_button'] = true;
            }

            $call['times_canceled'] = 0;
            //cancel_call_coun
            if (isset($call['support_id'])) {
                $rescancel = $this->cancel_call_count($token, $call['id']);
                $call['times_canceled'] = $rescancel;
            }
            //if(!empty($rescancel)){
            //    //$call['times_canceledtok'] = $token;
            //    //$call['times_canceledcaid'] = $call['id'];
            //    $call['times_canceled'] = $rescancel['cancel_call_amounts'];
            //}

            $call['show_results_button'] = false;
            if($call->call_type == "CHECK IN" && $call->status == 'COMPLETED'){
                $now_date = date('Y-m-d H:i');
                $limit_date = date('Y-m-d H:i', strtotime($call['call_date']->i18nFormat('yyyy-MM-dd') . $call['call_time']->i18nFormat('HH:mm') . '+ 2 days'));

                $this->loadModel('SpaLiveV1.DataConsultationPostexamOtherServices');
                $ent_post_exam = $this->DataConsultationPostexamOtherServices->find()
                    ->where(['DataConsultationPostexamOtherServices.check_in_id' => $call->id])->first();

                if($now_date <= $limit_date){
                    $call['show_results_button'] = true;
                }else{
                    $call['show_results_button'] = !empty($ent_post_exam);
                }

                // PREPARING FOR SPECIALIST NEXT MDF WEEK LMAO XDDD ⚠️⚠️⚠️🏳️

                $this->loadModel('SpaLiveV1.DataCheckInReviews');
                $ent_eval = $this->DataCheckInReviews->find()
                    ->where(['DataCheckInReviews.call_id' => $call->id, 'DataCheckInReviews.deleted' => 0])->first();

                $call['has_review']      = !empty($ent_eval);
                $call['two_days_passed'] = $now_date > $limit_date;
                $call['has_results']     = !empty($ent_post_exam);
            }

            $call['show_call_button'] = false;
            
            if($call->call_type == "CHECK IN"){
                
                if($call['call_time'] !== null){
                    $call_time = $call['call_time']->i18nFormat('yyyy-MM-dd');
                    
                    $date_call = date('Y-m-d H:i', strtotime($call['call_date']->i18nFormat('yyyy-MM-dd') . $call['call_time']->i18nFormat('HH:mm')));
                    $date_callone = date('Y-m-d H:i', strtotime($date_call . '+ 15 minutes'));
                    $date_calltwo = date('Y-m-d H:i', strtotime($date_call . '- 15 minutes'));

                    if($now_date >= $date_calltwo && $now_date <= $date_callone){
                        $call['show_call_button'] = true;
                    }
                }
            }


            $array_products = [];
            $i = 0;
            //fechaformateada
            
            $fecha_objeto1 = $call->call_date;
            $fecha_modificada1 = $fecha_objeto1->format('m-d-Y');
            $call->call_date_string = $fecha_modificada1;
            if($call->status == 'CLAIM_PENDING' || $call->status == 'CLAIMED'){                
                if($call['call_time'] !== null){                
                    $call->call_date_string = $call->call_date->format('m-d-Y') . " " . date_format($call->call_time, 'h:i A');
                }else{
                    // In theory, it shouldn't enter here because the time when the injector is available is assigned. 
                    // However, by default, I will set it at noon with 33 minutes
                    $call->call_date_string = $call->call_date->format('m-d-Y') . " 12:33 PM";
                    // So, if the time is 12:33 PM. It means that there is an error in some function that assigns the time.
                }
            }
            if (isset($call['DataPurchases'])) {
                $dataPurchases = $call['DataPurchases'];
                
                // Verificar si la propiedad 'shipping_date' existe en 'DataPurchases'
                if (isset($dataPurchases['shipping_date'])) {
                    $shippingDate = $dataPurchases['shipping_date'];

                    // Convertir la fecha de envío a un timestamp
                    $timestamp = strtotime($shippingDate);

                    // Obtener la fecha de envío formateada como una cadena (mm-dd-yyyy)
                    $shippingDateString = date('m-d-Y', $timestamp);

                    // Agregar la nueva variable 'shipping_date_string' al arreglo 'dataPurchases'
                    $call['DataPurchases']['shipping_date_string'] = $shippingDateString;
                }
            }
            //if($call->DataPurchases->shipping_date != null) {
            //    //$fecha_objeton = $call->DataPurchases->shipping_date;
            //    //$fecha_modificadan = $fecha_objeton->format('m-d-Y');
            //    //$call->DataPurchases->shipping_date_string = $fecha_modificadan;
            //}
            if($call->purchase_id){
                $ent_products = $this->DataPurchasesDetail->find()
                ->select($this->DataPurchasesDetail)
                ->select(['name' => 'CatProductsOtherServices.name'])
                ->join([
                    'CatProductsOtherServices' => ['table' => 'cat_products_other_services', 
                    'type' => 'LEFT', 
                    'conditions' => 'CatProductsOtherServices.id = DataPurchasesDetail.product_id'],
                ])->where(['DataPurchasesDetail.purchase_id' => $call->purchase_id])->all();

                foreach($ent_products as $row_pro) {
                    $array_products[] = array(
                        //"id"    => $row_pro->id,
                        "name"  => $row_pro->name,
                        //"qty"   => $row_pro->qty,   
                    );
                }

                $call["products"] = json_encode($array_products);
            }       
        }

        $this->loadModel('SpaLiveV1.DataUsersFreeWl');

        $user_free = $this->DataUsersFreeWl->find()
        ->where([
            'DataUsersFreeWl.user_id' => USER_ID,
            'DataUsersFreeWl.deleted' => 0
        ])
        ->first();

        $this->set('free_wl', !empty($user_free) ? true : false);

        $this->set('data', $calls); 
        $this->success();
    }


    public function get_subscriptions_by_userid(){
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

        $user_uid = get('user_uid', '');
        if(empty($user_uid) || $user_uid == ''){
            $this->message('Empty user uid.');
            return;
        }
        $this->loadModel('SpaLiveV1.SysUsers');

        $user_id = 0;
        $user_id = $this->SysUsers->find()->where(['uid' => $user_uid])->first();
        $patient_id = intval($user_id['id']);
        //return;
        //$ent_consultations = $this->DataConsultationOtherServices->find()->where(['DataConsultationOtherServices.patient_id' => 
        //$patient_id, 'DataConsultationOtherServices.deleted' => 0, 'DataConsultationOtherServices.status' => 'DONE'])->all();

        $this->loadModel('SpaLiveV1.DataSubscriptions');        
        
        $ent_subscriptions = $this->DataSubscriptions->find()->where(
            ['DataSubscriptions.user_id' => $patient_id,
            'DataSubscriptions.deleted' => 0])->all();
        
        if($ent_subscriptions){
            $this->set('data', $ent_subscriptions);
            $this->success();
            //return;
        } else {
            $this->message('Consultation empty.');
            return;
        }
    }

    

    public function update_cancel_subscription() {
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

        $this->loadModel('SpaLiveV1.DataSubscriptions');


        $subscription_id = get('subscription_id', '');
        if(empty($subscription_id)){
            $this->message('id empty.');
            return;
        }

        $ent_treatments = $this->DataSubscriptions->find()->where(['DataSubscriptions.id' => $subscription_id])->first();
        if(empty($ent_treatments)){
            $this->message('Subscription not found');
            return;
        }


        if ($subscription_id == 0) {
            $this->message('Invalid Subscription id.');
            return;
        }

        $array_save = array(
            'id' => $subscription_id,
        );
        $c_entity = $this->DataSubscriptions->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->DataSubscriptions->save($c_entity)) {
                $str_query_renew = "UPDATE data_subscriptions SET status = 'CANCELLED' WHERE id = ".$ent_treatments->id;
                $this->DataSubscriptions->getConnection()->execute($str_query_renew);
                $this->set('data', $c_entity);

                $this->success();
            }
        }

    }
    
    public function update_susccribe_subscription() {

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

        $this->loadModel('SpaLiveV1.DataSubscriptions');


        $subscription_id = get('subscription_id', '');
        if(empty($subscription_id)){
            $this->message('id empty.');
            return;
        }

        $ent_treatments = $this->DataSubscriptions->find()->where(['DataSubscriptions.id' => $subscription_id])->first();
        if(empty($ent_treatments)){
            $this->message('Subscription not found');
            return;
        }


        if ($subscription_id == 0) {
            $this->message('Invalid Subscription id.');
            return;
        }

        $array_save = array(
            'id' => $subscription_id,
        );
        $c_entity = $this->DataSubscriptions->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->DataSubscriptions->save($c_entity)) {
                $str_query_renew = "UPDATE data_subscriptions SET status = 'ACTIVE' WHERE id = ".$ent_treatments->id;
                $this->DataSubscriptions->getConnection()->execute($str_query_renew);
                $this->set('data', $c_entity);

                $this->success();
            }
        }

    }

    public function save_other_services_plan(){
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $this->loadModel('SpaLiveV1.DataConsultationPlanOtherServices');
        $this->loadModel('SpaLiveV1.DataPrescribedProductsOtherServices');
        $this->loadModel('SpaLiveV1.DataOtherServices');
        $this->loadModel('SpaLiveV1.CatProductsOtherServices');

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

        $consultation_uid = get('consultation_uid', '');
        if(empty($consultation_uid)){
            $this->message('consultation_id empty.');
            return;
        }

        $call_id = get('call_id', '');
        if(empty($call_id)){
            $this->message('Call id empty.');
            return;
        }

        $ent_consultation = $this->DataConsultationOtherServices->find()->where(['DataConsultationOtherServices.uid' => $consultation_uid])->first();
        if (empty($ent_consultation)) {
            $this->message('Invalid consultation.');
            return;
        }

        $consultation_id = $ent_consultation->id;

        // si no tiene productos cancelar la consulta
        //si no tiene productos, poner en estado rejected,

        $products = get('selected_products','');
        $arr_products = json_decode($products,true);

        if($arr_products){

            $oneYearOn = date('Y-m-d',strtotime(date("Y-m-d", time()) . " + 365 day"));

            $cert_uid = Text::uuid();

            $array_save_c = array(
                'uid' => $cert_uid,
                'consultation_id' => $consultation_id,
                'date_start' => Date('Y-m-d'),
                'date_expiration' => $oneYearOn,
                'deleted' => 0,
            );

            $cpc_entity = $this->DataOtherServices->newEntity($array_save_c);
            if(!$cpc_entity->hasErrors()){
                $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
                $currentRecord = $this->DataOtherServicesCheckIn->find()
                ->where([
                    'consultation_uid' => $consultation_uid,
                    'id' => $call_id
                ])->first();            
                $currentRecord->pending_answers = 0;

                $this->DataOtherServicesCheckIn->save($currentRecord);            
                $this->DataOtherServices->save($cpc_entity);

            }else{
                $this->message('Error in save suscription.');
                return;
            }

            foreach ($arr_products as $a_p) {
                foreach ($a_p["products"] as $p) {

                $array_save_p = array(
                    'consultation_id' => $consultation_id,
                    'product_id'      => $p,
                    'qty'             => 0,
                    'created'         => date('Y-m-d H:i:s')
                );
                
                $entity_prescribed = $this->DataPrescribedProductsOtherServices->newEntity($array_save_p);
                if(!$entity_prescribed->hasErrors())
                    $this->DataPrescribedProductsOtherServices->save($entity_prescribed);
                }
            }

            $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
            $currentCheckIn = $this->DataOtherServicesCheckIn->find()
            ->where([
                'consultation_uid' => $consultation_uid,
                'id' => $call_id
            ])->first(); 

            if($currentCheckIn->call_number != 6){

                $ent_consultation->status = "IN PROGRESS";
                $ent_consultation->assistance_id = USER_ID;

                if(!$ent_consultation->hasErrors()) {
                    if ($this->DataConsultationOtherServices->save($ent_consultation)) {
                        $this->success();

                        $ss_date = $ent_consultation->schedule_date->i18nFormat('yyyyMMddHHmmss');
                        $sf_date = date('YmdHis');

                        if ($ss_date > $sf_date) {
                            $this->DataConsultationOtherServices->updateAll(
                                ['schedule_date' => date('Y-m-d H:i:s')], ['DataConsultationOtherServices.uid' => $consultation_uid]
                            );
                        }
                    }
                }
            }
            $questionnaire = get('questionnaire', '');
            $notes = get('notes', '');
            $alergies = get('alergies', '');
            $refills = get('refills', '');
            $qty = get('qty', 0);

            $this->save_question_postexam_other_services(
                $call_id, 
                $consultation_id, 
                $questionnaire, 
                $notes,
                $alergies,
                $refills,
                $qty,
            );           

            if($currentCheckIn->call_type == "FIRST CONSULTATION"){
                $this->loadModel('SpaLiveV1.SysUsers');

                $user_info = $this->SysUsers->find()->select(['SysUsers.name', 'SysUsers.lname', 'SysUsers.mname', 'SysUsers.dob', 'SysUsers.street', 'SysUsers.city', 'SysUsers.zip', 'SysUsers.phone', 'SysUsers.email'])->where(['id' => $ent_consultation->patient_id])->first();

                if(empty($user_info)){
                    $this->message('user empty.');
                    return;
                }

                $this->send_email_prescription_pdf($user_info,$consultation_id);
            }

            $this->set("msg",'You have completed check in with the patient, and will receive $50.');

        }else{
            $ent_consultation->status = "REJECTED";

            if(!$ent_consultation->hasErrors()) {
                if ($this->DataConsultationOtherServices->save($ent_consultation)) {
                    $this->message('The consultation had no products and was rejected.');
                    return;
                }
            }
        }

        $this->success();
    }

    public function save_question_postexam_other_services(
        $call_id,
        $consultation_id,
        $questionnaire,
        $notes,
        $alergies = '',
        $refills  = '',
        $qty      = 0
    ){
        //Only save the notes
        //I commented the questionnaire because it is not used but it is not deleted in case it is used in the future 😋
        $this->loadModel('SpaLiveV1.DataConsultationPostexamOtherServices');
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
    
        if (/* empty($questionnaire) || */ $consultation_id == 0) {
            $this->message('Invalid consultation_id.');
            return;
        }

        $ent_check_in = $this->DataOtherServicesCheckIn->find()
        ->where(['DataOtherServicesCheckIn.id' => $call_id])->first();

        if (empty($ent_check_in)) {
            $this->message('Check in not found.');
            return;
        }


        if($ent_check_in->call_type == 'FIRST CONSULTATION' || $ent_check_in->call_type == 'FOLLOW UP'){
            $examiner_answers = array();            

            $examiner_answers[] = array(
                "question"  => "Allergies",
                "answer"    => $alergies == '' ? 'No allergies provided.' : $alergies
            );

            $examiner_answers[] = array(
                "question"  => "Quantity",
                "answer"    => $qty.' ml.' 
            );

            $examiner_answers[] = array(
                "question"  => "Notes",
                "answer"    => $notes == '' ? 'No notes provided.' : $notes 
            );

            $examiner_answers[] = array(
                "question"  => "Refills",
                "answer"    => $refills == '' ? 'No refills provided.' : $refills 
            );

            $array_save = array(
                'consultation_id' => $consultation_id,
                'data' => json_encode($examiner_answers), //$questionnaire,
                'check_in_id' => $ent_check_in->id,
                'notes' => '',
            );
    
            $c_entity = $this->DataConsultationPostexamOtherServices->newEntity($array_save);
    
            /*var_dump($c_entity);
            exit;*/
            if(!$c_entity->hasErrors()) {
                if ($this->DataConsultationPostexamOtherServices->save($c_entity)) {
                    $this->success();
                }
            }

        } else if($ent_check_in->call_type == 'CHECK IN'){

            $questionnaire_array = json_decode($questionnaire, true);
            $questionnaire_array[] = array('question' => 'Notes', 'answer' => $notes);
            $updated_questionnaire = json_encode($questionnaire_array);
            
            $array_save = array(
                'consultation_id' => $consultation_id,
                'data' => $updated_questionnaire, //$questionnaire,
                'check_in_id' => $call_id,
                'notes' => '',
            );

            $c_entity = $this->DataConsultationPostexamOtherServices->newEntity($array_save);

            if(!$c_entity->hasErrors()) {
                if ($this->DataConsultationPostexamOtherServices->save($c_entity)) {
                    $this->success();
                }
            }
        }
       
    }
    
    public function get_questionionary_by_consultation_id(){

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

        $call_id = get('call_id','');
        if (empty($call_id)) {
            $this->message('call id not found.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $this->loadModel('SpaLiveV1.DataConsultationAnswersOtherServices');
        $this->loadModel('SpaLiveV1.CatQuestionOtherServices');

        $consultation_uid = get('consultation_uid', '');

        //añadir validaciones
        $consult = $this->DataConsultationOtherServices->find()->where(['uid' => $consultation_uid])->first();
        //$this->set('data', $consult);
        //$this->success();
        //return;
        //exit;
        //$questionaries = $this->DataConsultationAnswersOtherServices->find()->where(['consultation_id' => $consult['id']])->all();

        $questionaries = $this->DataConsultationAnswersOtherServices->find()
        ->select($this->DataConsultationAnswersOtherServices)
        ->select(['question' => 'CatQuestionOtherServices.question'])
        ->join([
            'CatQuestionOtherServices' => ['table' => 'cat_question_other_services', 
            'type' => 'LEFT', 
            'conditions' => 'CatQuestionOtherServices.id = DataConsultationAnswersOtherServices.question_id'],
        ])->where(['DataConsultationAnswersOtherServices.consultation_id' =>  $consult['id'], 
                    'DataConsultationAnswersOtherServices.call_id'=> $call_id])->all();

            
        if(count($questionaries) > 0){
            
            $this->set('data', $questionaries);
            $this->success();
            return;
        } else {
            $this->set('data', []);
            $this->message('questionaries empty.');
            return;
        }
    }

    public function examiners_new_service_to_apply(){
        $this->loadModel('SpaLiveV1.DataExaminersOtherServices');
        $this->loadModel('SpaLiveV1.DataExaminersClinics');
        $this->loadModel('SpaLiveV1.CatOtherServices');
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

        $service_uid = get('service_uid','');
        if (empty($service_uid)) {
            $this->message('Service uid not found.');
            return;
        }


        $_fields = ['CatOtherServices.id','CatOtherServices.uid','CatOtherServices.title','CatOtherServices.state_id'];

        $_where = ['CatOtherServices.deleted' => 0, 'CatOtherServices.uid' => $service_uid];

        $entity = $this->CatOtherServices->find()->select($_fields)->where($_where)->first();

        if(!$entity->hasErrors()){
            if($entity->title == 'MINT'){
                $_where = ['DataExaminersClinics.user_id' => $user["user_id"], 
                           'DataExaminersClinics.deleted' => 0];

                $entity_search = $this->DataExaminersClinics->find()->where($_where)->first();

                if(empty($entity_search)){

                    $array_save = array(
                        'user_id'     => $user["user_id"],
                        'aprovied'    => "PENDING",
                        'deleted'     => 0,
                        'created'     => date('Y-m-d H:i:s'),
                    );
        
                    $entity = $this->DataExaminersClinics->newEntity($array_save);
        
                    if(!$entity->hasErrors()) {
                        if ($this->DataExaminersClinics->save($entity)) {
                            $this->success();
                        }
                    }
        
                }else{
                    $this->success();
                    return;
                }

            } else {
                $_where = ['DataExaminersOtherServices.user_id' => $user["user_id"], 
                'DataExaminersOtherServices.service_uid' => $service_uid, 'DataExaminersOtherServices.deleted' => 0];
        
                $entity_search = $this->DataExaminersOtherServices->find()->where($_where)->first();
        
                if(empty($entity_search)){
        
                    $array_save = array(
                        'user_id'     => $user["user_id"],
                        'service_uid' => $service_uid,
                        'aprovied'    => "PENDING",
                        'deleted'     => 0,
                        'created'     => date('Y-m-d H:i:s'),
                    );
        
                    $entity = $this->DataExaminersOtherServices->newEntity($array_save);
        
                    if(!$entity->hasErrors()) {
                        if ($this->DataExaminersOtherServices->save($entity)) {
                            $this->success();
                        }
                    }
        
                }else{
                    $this->success();
                    return;
                }

            }
        }
        $this->message('Fail to load the services');
        return;
       
    }

    public function get_services_examiners(){
        $this->loadModel('SpaLiveV1.DataExaminersOtherServices');
        $this->loadModel('SpaLiveV1.CatOtherServices');
        $this->loadModel('SpaLiveV1.DataExaminersClinics');
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

         $state_id = get('state_id','');
         if (empty($state_id)) {
             $this->message('State id empty.');
             return;
         }

        $array_data = [];
        $state = "";

        $_fields = ['CatOtherServices.id','CatOtherServices.uid','CatOtherServices.title','CatOtherServices.state_id','State.id','State.name'];

        $_where = ['CatOtherServices.deleted' => 0, 'CatOtherServices.state_id' => $state_id];

        $_join = ['State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = CatOtherServices.state_id']];

        $entity = $this->CatOtherServices->find()->select($_fields)->join($_join)->where($_where)->all();

        if(!empty($entity)){
            foreach($entity as $row) {
                $state = $row["State"]["name"];
                
                if($row['title']=='MINT'){

                    $_where_data = ['DataExaminersClinics.deleted' => 0, 
                                    'DataExaminersClinics.user_id' => $user["user_id"]];

                    $_fields_data = ['DataExaminersClinics.aprovied'];

                    $entity_data = $this->DataExaminersClinics->find()->where($_where_data)->first();

                    $aprovied = "NOAPPLY";

                    if(!empty($entity_data)){
                        $aprovied = $entity_data->aprovied;
                    }

                    $array_data[] = array(
                        'service_uid'   => $row['uid'],
                        'name' 	        => $row['title'],
                        'status' 		=> $aprovied,
                    );

                } else {

                    
                    $_where_data = ['DataExaminersOtherServices.deleted' => 0, 'DataExaminersOtherServices.service_uid' => $row->uid,
                    'DataExaminersOtherServices.user_id' => $user["user_id"]];

                    $_fields_data = ['DataExaminersOtherServices.aprovied'];

                    $entity_data = $this->DataExaminersOtherServices->find()->where($_where_data)->first();

                    $aprovied = "NOAPPLY";

                    if(!empty($entity_data)){
                        $aprovied = $entity_data->aprovied;
                    }

                    $array_data[] = array(
                        'service_uid'   => $row->uid,
                        'name' 	        => $row->title,
                        'status' 		=> $aprovied,
                    );

                }
                
            }
        }

        $response[] = array(
            'state'            => $state,
            'provide_services' => $array_data,
        );

        $this->set('services', $response);
        $this->success();
        return;
    }

    public function get_consultation(){
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

        $service_uid = get('service_uid','');
        if (empty($service_uid)) {
            $this->message('Service uid empty.');
            return;
        }

        $patient_id = USER_ID;

        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $arr_conditions = [
            'DataConsultationOtherServices.service_uid' => $service_uid,
            'DataConsultationOtherServices.patient_id' => $patient_id,
            'DataConsultationOtherServices.deleted' => 0,
        ];
        
        $consultation = $this->DataConsultationOtherServices->find()->where($arr_conditions)->first();
        
        $this->set('data', $consultation);
        $this->success();

    }

    public function get_questionnaire_and_products(){
        $token = get('token','');
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

        $uid_service = get('service_uid','');
        if(empty($uid_service)){
            $this->message('Service empty.');
            return;
        }

        $questionary_type = get('questionary_type','');
        if(empty($questionary_type)){
            $this->message('Questionary type empty.');
            return;
        }

        $array_questions = [];

        $this->loadModel('SpaLiveV1.CatQuestionExaminersOtherServices');
        $this->loadModel('SpaLiveV1.CatAnswerExaminersOtherServices');

        $fields = ['CatQuestionExaminersOtherServices.id','CatQuestionExaminersOtherServices.question','CatQuestionExaminersOtherServices.type'];
        $_where = ['CatQuestionExaminersOtherServices.service_uid' => $uid_service, 'CatQuestionExaminersOtherServices.deleted' => 0, 
                   'CatQuestionExaminersOtherServices.questionary_type' => $questionary_type];
        $entity = $this->CatQuestionExaminersOtherServices->find()->select($fields)->where($_where)->all();

        if(!empty($entity)){
            foreach($entity as $row) {
                
                $array_answers = [];

                $fields_answers = ['CatAnswerExaminersOtherServices.id','CatAnswerExaminersOtherServices.answer'];
                $_where_answers = ['CatAnswerExaminersOtherServices.id_question' => $row->id, 'CatAnswerExaminersOtherServices.deleted' => 0];
                $entity_answers = $this->CatAnswerExaminersOtherServices->find()->select($fields_answers)->where($_where_answers)->all();

                if(!empty($entity_answers)){
                    foreach($entity_answers as $row_answers) {
                        array_push($array_answers,$row_answers->answer);
                    }
                }

                $array_questions[] = array(
                    'question'          => $row->question,
                    'type'              => $row->type,
                    'options'           => $array_answers,
                    'answer'            => "",
                );
            }
        }

        $this->set('questions', $array_questions);

        $this->loadModel('SpaLiveV1.CatProductsOtherServices');

        $products = $this->CatProductsOtherServices->find()->where(['CatProductsOtherServices.service_uid' => $uid_service, 'CatProductsOtherServices.deleted' => 0])->all();

        if(count($products) > 0){
            $arr_products = [];
            foreach ($products as $key => $value) {
                $arr_products[] = [
                    'id' => $value->id,
                    'category' => $value->category,
                    'service_uid' => $value->service_uid,
                    'name' => $value->name,
                ];
            }
            $this->set('products', $arr_products);
            $this->success();
            return;
        } else {
            $this->set('products', []);
            $this->message('Products empty.');
            return;
        }
    }

    function was_successfully_completed(){

        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $this->loadModel('SpaLiveV1.DataPurchasesOtherServices');
        $this->loadModel('SpaLiveV1.DataPurchases');
        $this->loadModel('SpaLiveV1.DataPurchasesDetailOtherServices');
        $this->loadModel('SpaLiveV1.DataPayment');

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

        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $this->message('consultation_uid not found.');
            return;
        }

        $was_successfully = get('was_successfully','');
        if (empty($was_successfully)) {
            $this->message('Missing data not found.');
            return;
        }

        $call_type = get('call_type','FIRST CONSULTATION');

        $was_successfully = ($was_successfully === 'true');

        $ent_consultations = $this->DataConsultationOtherServices->find()->where(['DataConsultationOtherServices.uid' => $consultation_uid, 
                                                                                  'DataConsultationOtherServices.deleted' => 0])->first();

        if($ent_consultations){
            $_fields = ['DataConsultationOtherServices.payment_intent','Service.title','DataConsultationOtherServices.patient_id','DataConsultationOtherServices.service_uid'];

            $_join = [
                'Service' => ['table' => 'cat_other_services','type' => 'INNER','conditions' => 'Service.uid = DataConsultationOtherServices.service_uid'],
            ];

            $ent_consultation = $this->DataConsultationOtherServices->find()->select($_fields)->where(
                ['DataConsultationOtherServices.uid' => $consultation_uid])->join($_join)->last();
            
            $ent_payment = $this->DataPayment->find()->where(
                ['DataPayment.id_from' => $ent_consultation["patient_id"], 'DataPayment.type' => 'WEIGHT LOSS',
                'DataPayment.intent' => $ent_consultation->payment_intent
            ])->last();
            
            if (!empty($ent_payment) && $was_successfully) {

                $ent_payment_count = $this->DataPayment->find()->where(
                    ['DataPayment.id_from' => 0, 'DataPayment.type' =>"CHECK IN COMMISSION",'DataPayment.uid' => $ent_payment->uid, 'DataPayment.id_to' => USER_ID, 'DataPayment.total' => 5000
                ])->count();
                if ($ent_payment_count < 5) {
                    $Main = new MainController();
                    $Main->createPaymentCommissionRegister($call_type . " COMMISSION",0,USER_ID,$ent_payment->uid,$ent_payment->intent,$ent_payment->payment,"",5000,'',$ent_payment->payment_platform);
                    $ent_payment->comission_generated = 1;
                    $this->DataPayment->save($ent_payment);
                }
            }
            $this->success();
            $this->message("Success.");    
            return;
        }else{
            $this->message("Consultation not found");
            return;
        }
        
    }


    public function patient_service_completed(){
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');

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

        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $this->message('consultation_uid not found.');
            return;
        }

        $this->DataConsultationOtherServices->updateAll(
                ['status' => 'COMPLETED'], ['DataConsultationOtherServices.uid' => $consultation_uid]
            );
        
        $this->success();
    }

    public function validate_session() : bool 
    {
        $token = get('token', '');
        if(empty($token)) return false;
        
        $user = $this->AppToken->validateToken($token, true);
        $validate = $user != false;

        if(!$validate){ $this->message('Invalid token.'); }
        $this->set('session', $validate);        
        return $validate;            
    }

    public function upload_image(){
        if(!$this->validate_session()) return;
        $this->loadModel('DataOtherServicesPatientImages');

        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $this->message('consultation_uid not found.');
            return;
        }

        $id = get('id','');
        if (empty($id)) {
            $this->message('id not found.');
            return;
        }

        $ent_patient = $this->DataOtherServicesPatientImages->find()
            ->where(['DataOtherServicesPatientImages.patient_id' => USER_ID,
                    'DataOtherServicesPatientImages.consultation_uid' => $consultation_uid,
                    'DataOtherServicesPatientImages.checkin_id' => $id])->first();
        
        $file_id = 0;
        $images  = isset($ent_patient->images) && !empty($ent_patient->images) 
            ? explode(',', $ent_patient->images)     
            : array();        

        $file_id = $this->upload_file();     

        if($file_id == 0){
            $this->message('We\'re unable to upload your image');
            return;
        }  

        $images[] = $file_id;

        if($ent_patient != null || $ent_patient != ''){
            $updated = $this->DataOtherServicesPatientImages->updateAll(
                ['images' => implode(',', $images)], 
                ['consultation_uid' => $consultation_uid,
                'patient_id' => USER_ID,
                'checkin_id' => $id]);

        } else {
            $ent_img = $this->DataOtherServicesPatientImages->newEntity([
                'consultation_uid' => $consultation_uid,
                'patient_id' => USER_ID,
                'images' => implode(',', $images),
                'checkin_id' => $id
            ]);

            $updated = $this->DataOtherServicesPatientImages->save($ent_img);
        }
        

        if(!$updated){
            $this->message('We\'re unable to update your image');
            return;
        }

        $this->success();
    }

    private function upload_file() : int {
        if (!isset($_FILES['file'])) {        
            return 0;
        }

        if (!isset($_FILES['file']['name'])) {
            return 0;
        }

        $str_name = $_FILES['file']['name'];
        $_file_id = $this->Files->upload([
            'name' => $str_name,
            'type' => $_FILES['file']['type'],
            'path' => $_FILES['file']['tmp_name'],
            'size' => $_FILES['file']['size'],
        ]);

        return $_file_id;
    }


    public function get_patient_image(){
        $this->loadModel('DataOtherServicesPatientImages');

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

        $id = get('id','');
        if (empty($id)) {
            $this->message('id not found.');
            return;
        }
        
        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $this->message('consultation_uid not found.');
            return;
        }

        $ent_patient = $this->DataOtherServicesPatientImages->find()->where(['DataOtherServicesPatientImages.patient_id' => USER_ID,
                                                            'DataOtherServicesPatientImages.consultation_uid' => $consultation_uid,
                                                            'DataOtherServicesPatientImages.checkin_id' => $id])->all();

        $this->set('data', $ent_patient);
        $this->success();
    }


    public function delete_image()
    {
        if(!$this->validate_session()) return;

        $image_id = get('image_id', 0);
        if($image_id == 0){
            $this->message('Image id is required');
            return;
        }

        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $this->message('consultation_uid not found.');
            return;
        }

        $id = get('id','');
        if (empty($id)) {
            $this->message('id not found.');
            return;
        }

        $this->loadModel('DataOtherServicesPatientImages');
        $ent_patient = $this->DataOtherServicesPatientImages->find()->
            where(['DataOtherServicesPatientImages.patient_id' => USER_ID,
                    'DataOtherServicesPatientImages.consultation_uid' => $consultation_uid,
                    'DataOtherServicesPatientImages.checkin_id' => $id])->first();
        
        $images  = isset($ent_patient->images) && !empty($ent_patient->images) 
            ? explode(',', $ent_patient->images)     
            : array();

        if (!in_array($image_id, $images)) {
            $this->message('Image not found');
            return;
        }

        $images = array_diff($images, array($image_id));

        $ent_img = $this->DataOtherServicesPatientImages->patchEntity($ent_patient, [
            'images' => count($images) > 0 ? implode(',', $images) : null,
        ]);

        $updated = $this->DataOtherServicesPatientImages->save($ent_img);

        if(!$updated){
            $this->message('We\'re unable to update your profile');
            return;
        }

        $this->success();
    }

    public function create_shipping_record() {

        return;

        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');

        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $this->message('Consultation is required.');
            return;
        }
        
        $fields = ['DataOtherServicesCheckIn.id','DataOtherServicesCheckIn.uid','DataOtherServicesCheckIn.patient_id','DataOtherServicesCheckIn.consultation_uid',
        'DataOtherServicesCheckIn.patient_id','DataOtherServicesCheckIn.support_id','DataOtherServicesCheckIn.call_date','DataOtherServicesCheckIn.status',
        'DataOtherServicesCheckIn.call_number','service_title' =>'service.title','service_uid' =>'service.uid','user_name'=>'user.name','user_lname'=>'user.lname' ];        

        $data_check_in = $this->DataOtherServicesCheckIn->find()
        ->select($fields)
        ->join([            
            'user' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'user.id = DataOtherServicesCheckIn.patient_id'],
            'consultation' => ['table' => 'data_consultation_other_services', 'type' => 'LEFT', 'conditions' => 'consultation.uid = DataOtherServicesCheckIn.consultation_uid'],
            'service' => ['table' => 'cat_other_services', 'type' => 'LEFT', 'conditions' => 'service.uid = consultation.service_uid'],
        ])
        ->where(['DataOtherServicesCheckIn.consultation_uid' => $consultation_uid])->first();
        if(empty($data_check_in)){
            $this->message('Consultation not found.');
            return;
        }

        $data = array(
            "id" => $data_check_in->id,
            "uid" => $data_check_in->uid,
            "consultation_uid" => $data_check_in->consultation_uid,
            "patient_id" => $data_check_in->patient_id,
            "support_id" => $data_check_in->support_id,
            "call_date" => $data_check_in->call_date->i18nFormat('MM-dd-yyyy hh:mm a'),
            "status" => 'PENDING',
            "user_name" => $data_check_in->user_name . " " . $data_check_in->user_lname,
            'date_created' => date('m-d-Y h:i a'),
            'call_number' => $data_check_in->call_number,
            'call_type' => 'FOLLOWUP',
            'service_uid' => $data_check_in->service_uid,
            "service_title" => $data_check_in->service_title,
        );

        $name = $data_check_in->uid;
        $data_string = json_encode($data);
        
        $url = env('URL_MICROSERVICE', 'http://localhost:3131/') . 'followup';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER,
            array(
                'Content-Type:application/json',
                'Content-Length: ' . strlen($data_string)
            )
        );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);  
        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            $this->message($error_msg);
            $this->success(false);
            return;
            // this would be your first hint that something went wrong                            
        } else {
            // check the HTTP status code of the request
            $resultStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($resultStatus == 200) {
                //unlink($filename);// everything went better than expected
            } else {
                // the request did not complete as expected. common errors are 4xx
                // (not found, bad request, etc.) and 5xx (usually concerning
                // errors/exceptions in the remote script execution)                                
            }
        }
        curl_close($ch);

        $array_save['status'] = 'PENDING';             
        $this->DataOtherServicesCheckIn->updateAll(
            $array_save, 
            ['uid' => $data_check_in->uid] 
        );

        $this->set('session', true);
        $this->success();
    }

    public function historial_pending_products() {

        $token = get('token','');
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

        $consultation_uid = get('consultation_uid','');
        if(empty($consultation_uid)){
            $this->message('Consultation uid is required');
            return;
        }

        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.DataPurchasesDetailOtherServices');
        
        $fields = ['DataOtherServicesCheckIn.id','DataOtherServicesCheckIn.uid','DataOtherServicesCheckIn.patient_id','DataOtherServicesCheckIn.consultation_uid',
        'DataOtherServicesCheckIn.support_id','DataOtherServicesCheckIn.call_date','DataOtherServicesCheckIn.status','DataOtherServicesCheckIn.call_number',
        'DataOtherServicesCheckIn.call_type','DataOtherServicesCheckIn.call_title','DataOtherServicesCheckIn.show','DataOtherServicesCheckIn.created',
        'DataOtherServicesCheckIn.deleted', 'DataOtherServicesCheckIn.purchase_id','user_name'=>'user.name','user_lname'=>'user.lname',
        'service_title' =>'service.title','service_uid' =>'service.uid',];

        $data_check_in = $this->DataOtherServicesCheckIn->find()
        ->select($fields)
        ->join([ 
            'user' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'user.id = DataOtherServicesCheckIn.patient_id'],          
            'purchases' => ['table' => 'data_purchases_other_services', 'type' => 'LEFT', 'conditions' => 'purchases.id = DataOtherServicesCheckIn.purchase_id'],
            'consultation' => ['table' => 'data_consultation_other_services', 'type' => 'LEFT', 'conditions' => 'consultation.uid = DataOtherServicesCheckIn.consultation_uid'],
            'service' => ['table' => 'cat_other_services', 'type' => 'LEFT', 'conditions' => 'service.uid = consultation.service_uid'],
        ])
        ->where(['DataOtherServicesCheckIn.consultation_uid' => $consultation_uid, 
        'DataOtherServicesCheckIn.deleted' => 0,
        'DataOtherServicesCheckIn.call_type' => 'FOLLOW UP',])
        ->order(['DataOtherServicesCheckIn.created' => 'DESC'])->all();

        $data = array();

        foreach ($data_check_in as $check_in) {
            $_fields = ['DataPurchasesDetailOtherServices.id','DataPurchasesDetailOtherServices.purchase_id','DataPurchasesDetailOtherServices.product_id',
            'DataPurchasesDetailOtherServices.qty','DataPurchasesDetailOtherServices.price','DataPurchasesDetailOtherServices.deleted',
            'products_name' => 'products.name', 'products_id' => 'products.id'];

            $data_products = $this->DataPurchasesDetailOtherServices->find()
                ->select($_fields)
                ->join([
                    'products' => ['table' => 'cat_products_other_services', 'type' => 'LEFT', 'conditions' => 'products.id = DataPurchasesDetailOtherServices.product_id'],
                ])
                ->where(['DataPurchasesDetailOtherServices.purchase_id' => $check_in->purchase_id, 'DataPurchasesDetailOtherServices.deleted' => 0])
                ->toArray();

            $data[] = array(
                "id" => $check_in->id,
                "uid" => $check_in->uid,
                "servive_title" => $check_in->service_title,
                "service_uid" => $check_in->service_uid,
                "patient_name" => $check_in->patient_name . " " . $check_in->patient_lname,
                "consultation_uid" => $check_in->consultation_uid,
                "support_id" => $check_in->support_id,
                "call_date" => $check_in->call_date->i18nFormat('MM-dd-yyyy hh:mm a'),
                "status" => $check_in->status,
                "call_number" => $check_in->call_number,
                "call_type" => $check_in->call_type,
                "show" => $check_in->show,
                "products" => $data_products,
            );
        }

        $this->set('data', $data);
        $this->success();
    }

    public function consultation_claim_by_examiner() {
        $this->loadModel('DataConsultationOtherServices');
        $this->loadModel('DataOtherServicesCheckIn');
        $token = get('token','');

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

        $consultation_uid = get('consultation_uid','');
        if(empty($consultation_uid)){
            $this->message('Consultation uid empty.');
            return;
        }

        $scheduled_date = get('scheduled_date','');
        if (empty($scheduled_date)) {
            $this->message('Scheduled date not found.');
            return;
        }

        $ent_consultation = $this->DataConsultationOtherServices->find()->where(['DataConsultationOtherServices.uid' => $consultation_uid])->first();

        $ent_check_in = $this->DataOtherServicesCheckIn->find()->where(['DataOtherServicesCheckIn.consultation_uid' => $consultation_uid])->first();

        if($ent_consultation&&$ent_check_in){
            $ent_consultation->status = "IN PROGRESS";
            $ent_consultation->assistance_id = $user["user_id"];

            $update = $this->DataConsultationOtherServices->save($ent_consultation);

            $ent_check_in->support_id = $user["user_id"];
            $ent_check_in->claim_date = date('Y-m-d H:i:s');

            $update_check_in = $this->DataOtherServicesCheckIn->save($ent_check_in);

            if($update&&$update_check_in){
                $this->success();
            }else{
                $this->message('Error in update consultation.');
                return;
            }
            
        }else{
            $schedule_aux = new FrozenTime($scheduled_date);

            $firebase_update_data = array(
                "uid" => $consultation_uid,
                "schedule_date" => $schedule_aux->i18nFormat('MM-dd-yyyy'),
                "status" => 'SCHEDULED',
                "is_waiting" => 0,
            );
            
            //print_r($data);exit;
            /* $result  = $collectionReference->add($data);            
                $name = $result->name();
            
            //$firebase_document = substr($name, strpos($name, "services/") + 9);*/
            $data_string = json_encode($firebase_update_data);
            //$this->set('firebase_update_data', $firebase_update_data);
            $url = env('URL_MICROSERVICE', 'http://localhost:3131/') . 'updateServicesArr';
            $ch=curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);//curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER,
                array(
                    'Content-Type:application/json',
                    'Content-Length: ' . strlen($data_string)
                )
            );
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $result = curl_exec($ch);

            //$this->set('result_curl', $result);
            if (curl_errno($ch)) {
                $error_msg = curl_error($ch);
                $this->message($error_msg);
                $this->success(false);
                return;
                // this would be your first hint that something went wrong                            
            } else {
                // check the HTTP status code of the request
                $resultStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($resultStatus == 200) {
                    //unlink($filename);// everything went better than expected
                } else {
                    // the request did not complete as expected. common errors are 4xx
                    // (not found, bad request, etc.) and 5xx (usually concerning
                    // errors/exceptions in the remote script execution)                                
                }
            }
            curl_close($ch);

            $this->message('Consultation no found.');
            return;
        }
    
    }

    public function get_examiner_questionnaire_answers(){
        
        $this->loadModel('SpaLiveV1.DataConsultationPostexamOtherServices');

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

        $id = get('id','');
        if (empty($id)) {
            $this->message('id not found.');
            return;
        }

        $consultation_id = get('consultation_id', '');

        //añadir validaciones
        $consult = $this->DataConsultationPostexamOtherServices->find()->where(['consultation_id' => $consultation_id])->first();

       
    }

    public function get_follow_up_questionnaire_answers(){
        
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $this->loadModel('SpaLiveV1.DataConsultationAnswersOtherServices');
        $this->loadModel('SpaLiveV1.CatQuestionOtherServices');

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

        $id = get('id','');
        if (empty($id)) {
            $this->message('id not found.');
            return;
        }

        

        $consultation_uid = get('consultation_uid', '');

        //añadir validaciones
        $consult = $this->DataConsultationOtherServices->find()->where(['uid' => $consultation_uid])->first();
        //$this->set('data', $consult);
        //$this->success();
        //return;
        //exit;
        //$questionaries = $this->DataConsultationAnswersOtherServices->find()->where(['consultation_id' => $consult['id']])->all();

        $questionaries = $this->DataConsultationAnswersOtherServices->find()
        ->select($this->DataConsultationAnswersOtherServices)
        ->select(['question' => 'CatQuestionOtherServices.question'])
        ->join([
            'CatQuestionOtherServices' => ['table' => 'cat_question_other_services', 
            'type' => 'LEFT', 
            'conditions' => 'CatQuestionOtherServices.id = DataConsultationAnswersOtherServices.question_id'],
        ])->where(['DataConsultationAnswersOtherServices.consultation_id' =>  $consult['id'] , 
                    'DataConsultationAnswersOtherServices.call_id' =>  $id])->all();

            
        if(count($questionaries) > 0){
            
            $this->set('data', $questionaries);
            $this->success();
            return;
        } else {
            $this->set('data', []);
            $this->message('questionaries empty.');
            $this->success();
            return;
        }
    }

    public function save_answers_follow_up(){
        
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $this->loadModel('SpaLiveV1.DataConsultationAnswersOtherServices');

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

        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $this->message('consultation uid not found.');
            return;
        }

        $id = get('id','');
        if (empty($id)) {
            $this->message('id not found.');
            return;
        }

        $string_answers = get('answers','');
        $arr_answers = json_decode($string_answers,true);
        
        if (empty($arr_answers)) {
            $this->message('Answers empty.');
            return;
        }

        $consultation_id = $this->DataConsultationOtherServices->find()
        ->select(['DataConsultationOtherServices.id'])
        ->where(['DataConsultationOtherServices.uid' => $consultation_uid])
        ->first();

        foreach ($arr_answers as $row) {
            $arr_save_q = array(
                'uid' => Text::uuid(),
                'consultation_id' => $consultation_id['id'],
                'question_id' => $row['id'],
                'response' => $row['response'],
                'details' => $row['details'],
                'call_id' => $id,
                'deleted' => 0
            );

            $cq_entity = $this->DataConsultationAnswersOtherServices->newEntity($arr_save_q);
            if(!$cq_entity->hasErrors()){
                $this->DataConsultationAnswersOtherServices->save($cq_entity);
            }
        }
        
        $this->success(); 
            
    }

    public function consultation_cancel_by_examiner() {
        $this->loadModel('DataConsultationOtherServices');
        $this->loadModel('DataOtherServicesCheckIn');
        $token = get('token','');

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

        $consultation_uid = get('consultation_uid','');
        if(empty($consultation_uid)){
            $this->message('Consultation uid empty.');
            return;
        }

        $ent_consultation = $this->DataConsultationOtherServices->find()->where(['DataConsultationOtherServices.uid' => $consultation_uid])->first();

        //$ent_check_in = $this->DataOtherServicesCheckIn->find()->where(['DataOtherServicesCheckIn.consultation_uid' => $consultation_uid])->first();

        $str_now = date('Y-m-d');

        $diff_days = strtotime($ent_consultation->schedule_date->i18nFormat('yyyy-MM-dd')) - strtotime($str_now);

        $diff_days = $diff_days / 60 / 60 / 24;

        //$this->set('diff_days', $diff_days);

        if($diff_days>2){

            if($ent_consultation/*&&$ent_check_in*/){
                $ent_consultation->status = "IN PROGRESS";
                $ent_consultation->assistance_id = 0;

                $update = $this->DataConsultationOtherServices->save($ent_consultation);

                /*$ent_check_in->support_id = 0;
                $ent_check_in->claim_date = null;

                $update_check_in = $this->DataOtherServicesCheckIn->save($ent_check_in);*/

                if($update/*&&$update_check_in*/){
                    $this->success();
                }else{
                    $this->message('Error in update consultation.');
                    return;
                }
                
            }else{
                $this->message('Consultation no found.');
                return;
            }
        }else{
            $this->message('You cannot cancel an appointment two days before.');
            return;
        }

    }

    public function before_telehealth_call() {
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

        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $this->message('consultation_uid not found.');
            return;
        }

        $call_id = get('call_id','');
        if (empty($call_id)) {
            $this->message('call_id not found.');
            return;
        }

        $Main = new MainController();

        //$av_result = $Main->gfeAvailability();
        $this->set('available', true);

        //if (!$av_result) {
            //$this->message('Our Weight Loss specialists are available from Monday to Saturday from 7:30 AM to 7:30 PM. If you have reached us outside of our business hours, holidays, etc., please feel free to schedule your exam for a specific date and time or reach back out to us during business hours. Thank you!');
            //return;
        //}
        
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $ent_consultation = $this->DataConsultationOtherServices->find()->where(['DataConsultationOtherServices.uid' => $consultation_uid])->first();

        if(empty($ent_consultation)){
            $this->message('Error consultation not found.');
            return;
        }

        $qualiphy = new QualiphyController;

        $r = $qualiphy->generate_meeting(array('consultation_uid' => $consultation_uid, 'user_uid' => USER_UID), 'weight_loss');

        if($r['http_code'] == 200){
            $ent_consultation->meeting = '';
            $ent_consultation->meeting_pass = $r['meeting_uuid'];
            $ent_consultation->join_url = $r['meeting_url'];
            $ent_consultation->created = date('Y-m-d H:i:s');
            $update = $this->DataConsultationOtherServices->save($ent_consultation);
            if($update){
                $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
                $ent_check_in = $this->DataOtherServicesCheckIn->find()->where(['DataOtherServicesCheckIn.id' => $call_id])->first();
                if($ent_check_in){
                    $ent_check_in->status = 'PENDING';
                    $this->DataOtherServicesCheckIn->save($ent_check_in);
                    $this->set('meeting_url', $r['meeting_url']);
                    $this->set('uid', $consultation_uid);
                    $this->success();
                    return;
                }else{
                    $this->message('Error check in not found.');
                    return;
                }
            }else{
                $this->message('Error in update consultation.');
                return;
            }

        } else if($r['http_code'] == 400){
            $this->message($r['error_message']);
            return;
        } else if($r['http_code'] == 401){
            $this->message($r['error_message']);
            return;
        } else if($r['http_code'] == 500){
            $this->message($r['error_message']);
            return;
        }

    }

    public function get_consultation_history() {
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

        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $this->message('consultation_uid not found.');
            return;
        }
        $type = get('type','');
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $ent_consultation = $this->DataConsultationOtherServices->find()
        ->where([
            'DataConsultationOtherServices.uid' => $consultation_uid,
            'DataConsultationOtherServices.deleted' => 0,
        ])->first();

        if($ent_consultation){
            if($type == "join"){
                $this->set('meeting', $ent_consultation->meeting);
                $this->set('meeting_pass', $ent_consultation->meeting_pass);
                $this->set('join_url', $ent_consultation->join_url);
                $this->set('jwt', ''); 
            }else{
                $this->set('meeting', '');
            }
            $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
            $check_ins = $this->DataOtherServicesCheckIn->find()
            ->where([
                'DataOtherServicesCheckIn.consultation_uid' => $consultation_uid,
                'DataOtherServicesCheckIn.deleted' => 0,
                'DataOtherServicesCheckIn.show' => 1
            ])->order(['DataOtherServicesCheckIn.id DESC']);

            $array_call_history = [];
            if($check_ins){

                $this->loadModel('SpaLiveV1.DataOtherServicesAnswers');
                $this->loadModel('SpaLiveV1.CatQuestionOtherServices');
                $this->loadModel('SpaLiveV1.DataOtherServicesPatientImages');
                $this->loadModel('SpaLiveV1.DataConsultationPostexamOtherServices');
                $this->loadModel('SpaLiveV1.DataPrescribedProductsOtherServices');
                $this->loadModel('SpaLiveV1.CatProductsOtherServices');
                
                foreach($check_ins as $row) {

                    $data_images = [];
                    $examiner_answers = [];
                    $patient_answers = [];
                    $prescribed_products = [];          

                    if($row->call_type=="CHECK IN"){
                        /************ PICTURES *******************/

                        $pictures = $this->DataOtherServicesPatientImages->find()
                        ->where(
                            ['DataOtherServicesPatientImages.checkin_id' => $row->id,
                            'DataOtherServicesPatientImages.patient_id' => $row->patient_id]
                        )->first();

                        if($pictures){
                            $data_images = explode(",", $pictures->images);
                        }

                        /************ EXAMINERS ANSWERS *******************/
                        $answers = $this->DataConsultationPostexamOtherServices->find()
                        ->where(
                            ['DataConsultationPostexamOtherServices.consultation_id' => $ent_consultation->id,
                            'DataConsultationPostexamOtherServices.check_in_id' => $row->id]
                            )->first();

                        if($answers){
                            $examiner_answers = json_decode($answers->data);
                        }

                    }else
                    if($row->call_type=="FIRST CONSULTATION"||$row->call_type=="FOLLOW UP"){
                    
                        $id_consultation = $ent_consultation->id;

                        /************ EXAMINERS ANSWERS *******************/
                        $answers = $this->DataConsultationPostexamOtherServices->find()
                        ->where(
                            ['DataConsultationPostexamOtherServices.consultation_id' => $id_consultation,
                             'DataConsultationPostexamOtherServices.check_in_id' => $row->id]
                        )->first();

                        if($answers){
                            $examiner_answers = json_decode($answers->data);
                            $refills     = [];
                            $refillsStr  = [];
                            $indexRefill = 0;

                            for($i=0;$i<count($examiner_answers);$i++){
                                $answer = $examiner_answers[$i];
                                if($answer->question == 'Refills'){
                                    $refills = json_decode($answer->answer);
                                    $indexRefill = $i;
                                    break;
                                }                        
                            }

                            if(!empty($refills)){
                                foreach($refills as $row2){
                                    if($row2->selected){
                                        $refillStr = $row2->text . ($row2->has_refills 
                                                ? ', ' . $row2->refills . ' refill' . ($row2->refills > 1 ? 's' : '')
                                                : '');
                                        $refillsStr[] = $refillStr;
                                    }
                                }  
                                $examiner_answers[$indexRefill]->answer = implode('|', $refillsStr);                      
                            }
                        }

                        /*************** PATIENTS *************************/
                        $answers = $this->DataOtherServicesAnswers->find()
                        ->where(
                            ['DataOtherServicesAnswers.consultation_id' => $id_consultation,
                             'DataOtherServicesAnswers.check_in_id' => $row->id]
                        )->first();

                        if($answers){
                            $patient_answers = json_decode($answers->data);
                        }
                        
                    }
                    if($row->call_type=="FIRST CONSULTATION"){                     
                        $array_call_history[] = array(
                            'call_date'                 => $row->call_date,
                            'call_type'                 => $row->call_type,
                            'call_title'                => $row->call_title,
                            'images'                    => $data_images,
                            'examiner_answers'          => $examiner_answers,
                            'patient_answers'           => $patient_answers,
                            'goal_weight'               => $ent_consultation->main_goal_weight,
                            'current_weight'            => $ent_consultation->current_weight,
                            'current_weight_check_in'   => $row->current_weight,
                        );
                    }else{
                        $array_call_history[] = array(
                            'call_date'                 => $row->call_date,
                            'call_type'                 => $row->call_type,
                            'call_title'                => $row->call_title,
                            'images'                    => $data_images,
                            'examiner_answers'          => $examiner_answers,
                            'patient_answers'           => $patient_answers,
                            'goal_weight'               => $ent_consultation->main_goal_weight,
                            'current_weight'            => $row->current_weight,
                            'current_weight_check_in'   => $row->current_weight,
                        );
                    }
                }

                /*************** PrescribedProducts *************************/
                $ent_pres_pro = $this->DataPrescribedProductsOtherServices->find()
                ->select($this->DataPrescribedProductsOtherServices)
                ->select(['product_name' => 'CatProductsOtherServices.name'])
                ->join([
                    'CatProductsOtherServices' => ['table' => 'cat_products_other_services', 
                    'type' => 'LEFT', 
                    'conditions' => 'CatProductsOtherServices.id = DataPrescribedProductsOtherServices.product_id'],
                ])->where(['DataPrescribedProductsOtherServices.consultation_id' => $ent_consultation->id,
                           'CatProductsOtherServices.deleted' => 0])->all();

                foreach($ent_pres_pro as $row_pres) {
                    $prescribed_products[] = array(
                        'id'    => $row_pres->id,
                        'name'  => $row_pres->product_name,
                        'qty'   => $row_pres->qty,
                    );
                }
                
                //$this->set('prescribed_products', $prescribed_products);
                $this->set('call_history', $array_call_history);
                $this->success();
            }else{
                $this->message('Check ins not found.');
                return;
            }

        }else{
            $this->message('Consultation not found.');
            return;
        }

    }

    public function get_examiner_answers() {
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

        $check_in_id = get('check_in_id','');
        if (empty($check_in_id)) {
            $this->message('check_in_id not found.');
            return;
        } 

        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $check_in = $this->DataOtherServicesCheckIn->find()
        ->where(['DataOtherServicesCheckIn.id' => $check_in_id,
                 'DataOtherServicesCheckIn.deleted' => 0])->first();

        if($check_in){

            $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
            $ent_consultation = $this->DataConsultationOtherServices->find()->where(['DataConsultationOtherServices.uid' => $check_in->consultation_uid])->first();

            if($ent_consultation){ 
                
                $this->loadModel('SpaLiveV1.DataConsultationPostexamOtherServices');
                $answers = $this->DataConsultationPostexamOtherServices->find()
                ->where(
                    ['DataConsultationPostexamOtherServices.consultation_id' => $ent_consultation->id,
                     'DataConsultationPostexamOtherServices.check_in_id' => $check_in_id]
                )->first();


                $this->set('notes', isset($answers->notes) ? $answers->notes : '');
                if($answers){
                    $examiner_answers = json_decode($answers->data);
                    $refills     = [];
                    $refillsStr  = [];
                    $indexRefill = 0;

                    for($i=0;$i<count($examiner_answers);$i++){
                        $answer = $examiner_answers[$i];
                        if($answer->question == 'Refills'){
                            $refills = json_decode($answer->answer);
                            $indexRefill = $i;
                            break;
                        }                        
                    }

                    if(!empty($refills)){
                        foreach($refills as $row){
                            if($row->selected){
                                $refillStr = $row->text . ($row->has_refills 
                                        ? ', ' . $row->refills . ' refill' . ($row->refills > 1 ? 's' : '')
                                        : '');
                                $refillsStr[] = $refillStr;
                            }
                        }  
                        $examiner_answers[$indexRefill]->answer = implode('\n', $refillsStr);                      
                    }
                    
                    $this->set('answers', $examiner_answers);                    
                    $this->success();
                }else{
                    $this->set('answers', []);
                    $this->success();
                }

            }else{
                $this->message('Consultation not found.');
                return;
            }

        }else{
            $this->message('Check in not found.');
            return;
        }
    }

    public function save_current_weight(){
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');

        $token = get('token','');
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

        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $this->message('consultation_uid not found.');
            return;
        }

        $check_in_id = get('check_in_id','');
        if (empty($check_in_id)) {
            $this->message('check_in_id not found.');
            return;
        }

        $current_weight = get('current_weight','');
        if (empty($current_weight)) {
            $this->message('current weight not found.');
            return;
        }

        $arr_conditions = [
            'DataOtherServicesCheckIn.id' => $check_in_id,
            'DataOtherServicesCheckIn.deleted' => 0,
        ];
        
        $this->DataOtherServicesCheckIn->updateAll(
            ['current_weight' => $current_weight], 
            $arr_conditions
        ); 

        
        $arr_conditions_consultation = [
            'DataConsultationOtherServices.uid' => $consultation_uid,
            'DataOtherServicesCheckIn.deleted' => 0,
        ];

        $this->DataConsultationOtherServices->updateAll(
            ['current_weight' => $current_weight], 
            $arr_conditions_consultation
        ); 
        
        
        //////////// actualiza firebase //////////
        $data = array(
            "uid" => $consultation_uid,
            "current_weight" => $current_weight,
            'call_id' => strval($check_in_id),
            "has_images" => true,
        );
        
        $data_string = json_encode($data);
        $url = env('URL_MICROSERVICE', 'http://localhost:3131/') . 'updateServicesArr';
        $ch=curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);//curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER,
            array(
                'Content-Type:application/json',
                'Content-Length: ' . strlen($data_string)
            )
        );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);  
        curl_close($ch);
        $this->success(); 
        return;

        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            $this->message($error_msg);
            $this->success(false);
            return;
            // this would be your first hint that something went wrong                            
        } else {
            // check the HTTP status code of the request
            $resultStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($resultStatus == 200) {
                //unlink($filename);// everything went better than expected
            } else {
                // the request did not complete as expected. common errors are 4xx
                // (not found, bad request, etc.) and 5xx (usually concerning
                // errors/exceptions in the remote script execution)                                
            }
        }
        curl_close($ch);
        $this->success(); 

    }

    public function get_current_weight(){
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');

        $token = get('token','');
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


        $check_in_id = get('check_in_id','');
        if (empty($check_in_id)) {
            $this->message('check_in_id not found.');
            return;
        }

        $arr_conditions = [
            'DataOtherServicesCheckIn.id' => $check_in_id,
            'DataOtherServicesCheckIn.deleted' => 0,
        ];    

        $getConsultation = $this->DataOtherServicesCheckIn->find()
        ->select(['DataOtherServicesCheckIn.current_weight'])
        ->where($arr_conditions)
        ->first();

        if ($getConsultation) {
           $this->set('current_weight', $getConsultation->current_weight);
           $this->success(); 
        } else {
           $this->set('current_weight', ' ');
           $this->success(); 
        }
        
    }

    public function get_checkin_by_months(){
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

        $consultation_uid = get('consultation_uid', '');
        if(empty($consultation_uid) || $consultation_uid == ''){
            $this->message('Empty consultation_uid.');
            return;
        }
        

        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.DataPurchasesOtherServices');
        $this->loadModel('SpaLiveV1.DataPurchases');
        $this->loadModel('SpaLiveV1.DataPurchasesDetail');
        $this->loadModel('SpaLiveV1.DataPurchasesDetailOtherServices');
        $this->loadModel('SpaLiveV1.CatProductsOtherServices');
        $this->loadModel('SpaLiveV1.SysUsers');

        $_fields = ['examiner_name' => "CONCAT_WS(' ', SysUsers.name, SysUsers.lname)"];
        
        $checkins = $this->DataOtherServicesCheckIn->find()
        ->select($this->DataOtherServicesCheckIn)
        ->select($this->DataPurchases)
        ->select($_fields)
        ->join([
            'DataPurchases' => [
                'table' => 'data_purchases', 
                'type' => 'LEFT', 
                'conditions' => 'DataPurchases.id = DataOtherServicesCheckIn.purchase_id'],
            'SysUsers' => [
                'table' => 'sys_users', 
                'type' => 'LEFT', 
                'conditions' => 'SysUsers.id = DataOtherServicesCheckIn.support_id'],
        ])->where(['DataOtherServicesCheckIn.consultation_uid' =>  $consultation_uid])->orderAsc('DataOtherServicesCheckIn.id')->all();
            

        $month1 = array();
        $month2 = array();
        $month3 = array();

        foreach($checkins as $row) {

            $array_products = [];
            $i = 0;

            if($row->purchase_id){
                $ent_products = $this->DataPurchasesDetail->find()
                ->select($this->DataPurchasesDetail)
                ->select(['name' => 'CatProductsOtherServices.name'])
                ->join([
                    'CatProductsOtherServices' => ['table' => 'cat_products_other_services', 
                    'type' => 'LEFT', 
                    'conditions' => 'CatProductsOtherServices.id = DataPurchasesDetail.product_id'],
                ])->where(['DataPurchasesDetail.purchase_id' => $row->purchase_id])->all();

                foreach($ent_products as $row_pro) {
                    $array_products[] = array(
                        //"id"    => $row_pro->id,
                        "name"  => $row_pro->name,
                        //"qty"   => $row_pro->qty,   
                    );
                }

                $row["products"] = json_encode($array_products);
            }

            if($row['call_type'] == 'FIRST CONSULTATION'){
                array_push($month1, $row);
            } else if ($row['call_type'] == 'CHECK IN'){
                switch ($row['call_number']) {
                    case '1':
                        array_push($month1, $row);
                        break;
                    case '2':
                        array_push($month1, $row);
                        break;
                    case '3':
                        array_push($month2, $row);
                        break;
                    case '4':
                        array_push($month2, $row);
                        break;
                    case '5':
                        array_push($month3, $row);
                        break;
                    case '6':
                        array_push($month3, $row);
                        break;
                }

            } else if ($row['call_type'] == 'FOLLOW UP'){ 
                if($row['call_number'] == '1'){
                    array_push($month1, $row);
                } else if($row['call_number'] == '2'){
                    array_push($month2, $row);
                }
            }
            
        }

        $past_treatments = array(
            'month1' => $month1,
            'month2' => $month2,
            'month3' => $month3
        );

        $this->set('past_checkins', $past_treatments); 
        $this->success();
    }

    public function update_id_examiner(){
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

        $call_id = get('call_id', '');
        if(empty($call_id)){
            $this->message('Call id empty.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $checkin = $this->DataOtherServicesCheckIn->find()->select()->where(['DataOtherServicesCheckIn.id' => $call_id])->first();
        if (empty($checkin)) {
            $this->message('Data Check in Other Services not found.');
            return;
        }

        $checkin->support_id = $user["user_id"];

        $update = $this->DataOtherServicesCheckIn->save($checkin);

        if($update){
            $this->success();  
            return;
        }else{
            $this->message("Error in update check in");
            return;
        }
    }

    public function save_patient_answers(){
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.DataOtherServicesAnswers');
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        
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

        $data = get('data',"");

        if (empty($data)) {
            $this->message('Invalid consultation_id.');
            return;
        }

        $call_id = get('call_id',"");

        if (empty($call_id)) {
            $this->message('Invalid check in id.');
            return;
        }

        $consultation_uid = get('consultation_uid', '');
        if(empty($consultation_uid)){
            $this->message('consultation_id empty.');
            return;
        }

        $ent_consultation = $this->DataConsultationOtherServices->find()->where(['DataConsultationOtherServices.uid' => $consultation_uid])->first();
        if (empty($ent_consultation)) {
            $this->message('Invalid consultation.');
            return;
        }

        $consultation_id = $ent_consultation->id;

        $ent_check_in = $this->DataOtherServicesCheckIn->find()
        ->where(['DataOtherServicesCheckIn.id' => $call_id])->first();

        if (empty($ent_check_in)) {
            $this->message('Check in not found.');
            return;
        }

        $array_save = array(
            'consultation_id' => $consultation_id,
            'data' => $data,
            'check_in_id' => $ent_check_in->id,
        );

        $c_entity = $this->DataOtherServicesAnswers->newEntity($array_save);

        if(!$c_entity->hasErrors()) {
            if ($this->DataOtherServicesAnswers->save($c_entity)) {
                $this->success();
            }else{
                $this->message('Error in save answers.');
                return;
            }
        }

        $questions = json_decode($data);
        
        //guarda en firebase
        $firebase_update_data = array(
            "uid"       => $consultation_uid,
            "questions" => $questions
        );
        
        $data_string = json_encode($firebase_update_data);
        //$this->set('firebase_update_data', $firebase_update_data);
        $url = env('URL_MICROSERVICE', 'http://localhost:3131/') . 'updateServicesArr';
        $ch=curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);//curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER,
            array(
                'Content-Type:application/json',
                'Content-Length: ' . strlen($data_string)
            )
        );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);

        //$this->set('result_curl', $result);
        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            $this->message($error_msg);
            $this->success(false);
            return;
            // this would be your first hint that something went wrong                            
        } else {
            // check the HTTP status code of the request
            $resultStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($resultStatus == 200) {
                //unlink($filename);// everything went better than expected
            } else {
                // the request did not complete as expected. common errors are 4xx
                // (not found, bad request, etc.) and 5xx (usually concerning
                // errors/exceptions in the remote script execution)                                
            }
        }
        curl_close($ch);

        $this->success();  
    }

    public function get_patient_address() {
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.CatStates');
        
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

        $_join = [
            'State' => [
                'table' => 'cat_states',
                'type' => 'LEFT',
                'conditions' => 'State.id = SysUsers.state',
                'fields' => ['name'], 
            ],
        ];

        $fields = ["SysUsers.id","SysUsers.uid","SysUsers.email","SysUsers.name","SysUsers.lname","SysUsers.street","SysUsers.city","SysUsers.suite","SysUsers.zip","SysUsers.state"];
        $_where = ["SysUsers.id" => USER_ID,'SysUsers.deleted' => 0];
        $ent_user = $this->SysUsers->find()->select($fields)->join($_join)->where($_where)->first();
            
            if(!empty($ent_user)){
                $ent_state = $this->CatStates->find()->select(["CatStates.id", "CatStates.name", "CatStates.abv"])->where(["CatStates.id" => $ent_user->state])->first();
       
                $this->set('id', $ent_user->id);
                $this->set('uid', $ent_user->uid);
                $this->set('name', $ent_user->name . ' ' . $ent_user->lname);
                $this->set('street', $ent_user->street);
                $this->set('city', $ent_user->city);
                $this->set('suite', $ent_user->suite);
                $this->set('zip', $ent_user->zip);
                $this->set('state_id', $ent_user->state);
                $this->set('state_name', $ent_state->name);
                $this->set('abv', $ent_state->abv);
                //$this->set('State', $ent_user->State);
            
            }
            $this->success();
            return;
            exit();
    }

    public function save_patient_first_consultation_answers(){
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.DataOtherServicesAnswers');
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        
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

        $consultation_uid = get('consultation_uid', '');
        if(empty($consultation_uid)){
            $this->message('consultation_id empty.');
            return;
        }

        $current_weight = get('current_weight','');
        $main_goal_weight = get('main_goal_weight','');

        $ent_consultation = $this->DataConsultationOtherServices->find()->where(['DataConsultationOtherServices.uid' => $consultation_uid])->first();
        if (empty($ent_consultation)) {
            $this->message('Invalid consultation.');
            return;
        }

        $consultation_id = $ent_consultation->id;

        $array_save = array(
            'consultation_id' => $consultation_id,
            'data' => '',
        );

        $c_entity = $this->DataOtherServicesAnswers->newEntity($array_save);

        if(!$c_entity->hasErrors()) {
            if ($this->DataOtherServicesAnswers->save($c_entity)) {
                $this->success();
            }else{
                $this->message('Error in save answers.');
                return;
            }
        }

        $this->DataConsultationOtherServices->updateAll(
            ['current_weight' => $current_weight,
             'main_goal_weight' => $main_goal_weight,
             'goals' => get('goals',''),
             'notes' => get('notes','')], 
            ['id' => $consultation_id]
        );

        $qualiphy = new QualiphyController;

        $r = $qualiphy->generate_meeting(array('consultation_uid' => $consultation_uid, 'user_uid' => USER_UID), 'weight_loss');

        if($r['http_code'] == 200){
            $array_save['meeting'] = '';
            $array_save['meeting_pass'] = $r['meeting_uuid'];
            $array_save['join_url'] = $r['meeting_url'];

        } else if($r['http_code'] == 400){
            $this->message($r['error_message']);
            return;
        } else if($r['http_code'] == 401){
            $this->message($r['error_message']);
            return;
        } else if($r['http_code'] == 500){
            $this->message($r['error_message']);
            return;
        }

        $this->set('meeting_url', $r['meeting_url']);
        $this->set('uid', $consultation_uid);

        //change medical director to marie
        $this->loadModel('SpaLiveV1.SysUserAdmin');
        $this->loadModel('SpaLiveV1.SysUsers');
        $md_id = $this->SysUserAdmin->getAssignedDoctor();
        $pat = $this->SysUsers->find()->where(['SysUsers.id' => $ent_consultation->patient_id])->first();
        if (!empty($pat)) {
            $this->SysUsers->updateAll(
                ['md_id' => $md_id],
                ['SysUsers.id' => $ent_consultation->patient_id]
            );
        }
        $this->success();  
    }

    public function get_patient_answers() {
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

        $check_in_id = get('check_in_id','');
        if (empty($check_in_id)) {
            $this->message('check_in_id not found.');
            return;
        } 

        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.CatQuestionOtherServices');

        $check_in = $this->DataOtherServicesCheckIn->find()
        ->where(['DataOtherServicesCheckIn.id' => $check_in_id,
                 'DataOtherServicesCheckIn.deleted' => 0])->first();

        if($check_in){

            $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
            
            $ent_consultation = $this->DataConsultationOtherServices->find()->where(['DataConsultationOtherServices.uid' => $check_in->consultation_uid])->first();

            if($ent_consultation){ 
                
                $this->loadModel('SpaLiveV1.DataOtherServicesAnswers');
                $answers = $this->DataOtherServicesAnswers->find()
                
                ->where(
                    ['DataOtherServicesAnswers.consultation_id' => $ent_consultation->id,
                     'DataOtherServicesAnswers.check_in_id' => $check_in_id]
                )->first();

                if($answers){
                    $patient_answers = json_decode($answers->data);

                    foreach($patient_answers as $p) {
                        $ent_type = $this->CatQuestionOtherServices->find()->where(['CatQuestionOtherServices.id' => $p->id])->first();

                        if($ent_type){
                            $p->type = $ent_type->type;
                        }
                    }

                    $this->set('answers', $patient_answers);
                    $this->success();
                }else{
                    $this->set('answers', []);
                    $this->success();
                }

            }else{
                $this->message('Consultation not found.');
                return;
            }

        }else{
            $this->message('Check in not found.');
            return;
        }
    }

    public function save_support_id(){
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');


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

        $support_id = USER_ID;
        $checkin_id = get('checkin_id', 0);

        if (empty($checkin_id)) {
            $this->message('Invalid checkin_id.');
            return;
        }

        $this->DataOtherServicesCheckIn->updateAll(
            ['support_id' => $support_id, 'status' => 'ONLINE'], 
            ['id' => $checkin_id]
        );

        $this->success();
    }

    public function call_history(){
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        
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

        $support_id = USER_ID;
        $hs = $this->get_call_history($support_id);

        $this->set('call_history', $hs);
        $this->success();
    }

    public function update_shippping_track(){
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

        $select = [
            'DataOtherServicesCheckIn.id',
            'DataOtherServicesCheckIn.purchase_id',
            'DataConsultationOtherServices.patient_id',
            'Patient.name',
        ];

        $call_id = get('call_id', 0);
        $track_number = get('track_number', '');
        $shipping_date = get('shipping_date', '');

        if (empty($call_id)) {
            $this->message('Invalid call_id.');
            return;
        }

        /* if (empty($track_number)) {
            $this->message('Invalid track_number.');
            return;
        } */

        if (empty($shipping_date)) {
            $this->message('Invalid shipping_date.');
            return;
        }

        $shipping_date = date('Y-m-d', strtotime($shipping_date));
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');        

        $pending_shipping = $this->DataOtherServicesCheckIn->find()
            ->select($select)
            ->join([
                "DataConsultationOtherServices" => ['table' => 'data_consultation_other_services', 'type' => 'LEFT', 'conditions' => 'DataOtherServicesCheckIn.consultation_uid = DataConsultationOtherServices.uid'],
                "DataPurchases" => ['table' => 'data_purchases', 'type' => 'LEFT', 'conditions' => 'DataOtherServicesCheckIn.purchase_id = DataPurchases.id'],
                "Patient" =>  ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'DataOtherServicesCheckIn.patient_id = Patient.id']
            ])
            ->where([
                'DataOtherServicesCheckIn.id' => $call_id,
                'DataOtherServicesCheckIn.deleted' => 0,
            ])->first();
                
        if($pending_shipping){
            $this->loadModel('SpaLiveV1.DataPurchases');
            $this->DataPurchases->updateAll(
                [
                    'tracking' => $track_number, 
                    'shipping_date' => $shipping_date, 
                    'status' => 'WAITING TO RECEIVE THE PRODUCT'                    
                ], 
                ['id' => $pending_shipping->purchase_id]
            );
            $Main->notify_devices('SHIPPING_PRODUCT',array($pending_shipping['DataConsultationOtherServices']['patient_id']), true, true, true, array(), '', array('[CNT/PatName]' => $pending_shipping['Patient']['name']), true);
            $this->success();
        }
    }

    #region FUNCTIONS 
    
    function create_purchase_record(
        $array_save
    ) : int {
        $this->loadModel('SpaLiveV1.DataPurchases');

        $c_entity = $this->DataPurchases->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->DataPurchases->save($c_entity)) {
                return $c_entity->id;
            }
        }
        return 0;
    }

    public function get_call_history($examiner_id){
        $this->loadModel('SpaLiveV1.DataPurchasesOtherServices');
        $this->loadModel('SpaLiveV1.DataPurchases');
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.DataConsultationPostexamOtherServices');
        $this->loadModel('SpaLiveV1.DataOtherServicesAnswers');
        $select = [
            'DataOtherServicesCheckIn.id',
            'DataOtherServicesCheckIn.created',
            'DataOtherServicesCheckIn.patient_id',
            'DataOtherServicesCheckIn.purchase_id',
            'DataOtherServicesCheckIn.call_type',
            'DataOtherServicesCheckIn.call_title',
            'DataOtherServicesCheckIn.current_weight',
            'DataOtherServicesCheckIn.deleted',
            'DataPurchases.status',
            'DataPurchases.tracking',
            'DataPurchases.shipping_date',
            'DataConsultationOtherServices.service_uid',
            'DataConsultationOtherServices.id',     
            'DataConsultationOtherServices.current_weight',          
            'DataConsultationOtherServices.main_goal_weight', 
            'Patient.name',
            'Patient.lname',
            'Patient.email',
            'Patient.phone',
            'Patient.dob',
            'Patient.street',
            'Patient.city',
            'State.name',
            'Patient.suite',
            'Patient.zip',
        ];
        $pending_shipping = $this->DataOtherServicesCheckIn->find()
            ->select($select)
            ->join([
                "DataConsultationOtherServices" => ['table' => 'data_consultation_other_services', 'type' => 'LEFT', 'conditions' => 'DataOtherServicesCheckIn.consultation_uid = DataConsultationOtherServices.uid'],
                "DataPurchases" => ['table' => 'data_purchases', 'type' => 'LEFT', 'conditions' => 'DataOtherServicesCheckIn.purchase_id = DataPurchases.id'],
                "Patient" =>  ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'DataOtherServicesCheckIn.patient_id = Patient.id'],
                "State" => ['table' => 'cat_states', 'type' => 'LEFT', 'conditions' => 'Patient.state = State.id'],
            ])
            ->where([
                'DataOtherServicesCheckIn.support_id' => $examiner_id,
                'DataOtherServicesCheckIn.deleted' => 0,      
                // 'DataPurchases.status' => 'WAITING FOR THE EXAMINER TO CONFIRM YOUR PRODUCT',
                'DataOtherServicesCheckIn.status' => 'COMPLETED',
                // 'DataPurchases.deleted' => 0  
            ])
            ->orderDesc('DataOtherServicesCheckIn.id')->all();
        
        $array_dos = [];

        foreach($pending_shipping as $shipping){
            $request_shipping = 
                //empty($shipping["DataPurchases"]["tracking"]) && 
                $shipping["DataPurchases"]["status"] == 'WAITING FOR THE EXAMINER TO CONFIRM YOUR PRODUCT';

            if(isset($shipping->purchase_id)){
                $products = $this->get_products_purchase($shipping->purchase_id);            
            }else{
                $products = [];
            }

            $created = $shipping->created->format('m/d/Y H:i');

            $data_images = [];
            $array_call_history = [];
            $examiner_answers = [];
            $patient_answers = [];

            if($shipping->call_type=="FIRST CONSULTATION"||$shipping->call_type=="FOLLOW UP"){
                    
                $answers = $this->DataConsultationPostexamOtherServices->find()
                ->where(
                    ['DataConsultationPostexamOtherServices.consultation_id' => $shipping["DataConsultationOtherServices"]["id"],
                     'DataConsultationPostexamOtherServices.check_in_id' => $shipping->id]
                )->first();

                if($answers){
                    $examiner_answers = json_decode($answers->data);
                }

                $answers = $this->DataOtherServicesAnswers->find()
                ->where(
                    ['DataOtherServicesAnswers.consultation_id' => $shipping["DataConsultationOtherServices"]["id"],
                        'DataOtherServicesAnswers.check_in_id' => $shipping->id]
                )->first();

                if($answers){
                    $patient_answers = json_decode($answers->data);
                }
                
            }

            $array_call_history[] = array(
                'call_date'                 => $shipping->call_date,
                'call_type'                 => $shipping->call_type,
                'call_title'                => $shipping->call_title,
                'images'                    => $data_images,
                'examiner_answers'          => $examiner_answers,
                'patient_answers'           => $patient_answers,
                'goal_weight'               => $shipping["DataConsultationOtherServices"]["main_goal_weight"],
                'current_weight'            => $shipping["DataConsultationOtherServices"]["current_weight"],
                'current_weight_check_in'   => $shipping->current_weight
            );

            $array_dos[] = [
                "id" => $shipping->id,
                "created" => $created,
                "patient_fname" => $shipping["Patient"]["name"].' '.$shipping["Patient"]["lname"],
                "patient_email" => $shipping["Patient"]["email"],
                "patient_phone" => $shipping["Patient"]["phone"],
                "patient_dob" => $shipping["Patient"]["dob"],
                "patient_street" => trim($shipping["Patient"]["street"]),
                "patient_city" => trim($shipping["Patient"]["city"]),
                "patient_suite" => trim($shipping["Patient"]["suite"]),
                "patient_zip" => trim($shipping["Patient"]["zip"]),
                "patient_state" => $shipping["State"]["name"],
                "purchase_id" => $shipping->purchase_id, 
                "call_type" => $shipping->call_type, // "FIRST CONSULTATION", "CHECK IN", "FOLLOW UP
                "call_title" => $shipping->call_title,
                "service_title" => $this->get_service_title($shipping["DataConsultationOtherServices"]["service_uid"]),
                "products" => $products,
                "current_weight" => $shipping->current_weight,
                "main_goal_weight" => $shipping["DataConsultationOtherServices"]["main_goal_weight"],   
                "request_shipping" => $request_shipping,                
                "tracking" => $shipping["DataPurchases"]["tracking"],
                "shipping_date" => $shipping["DataPurchases"]["shipping_date"],
                "history"  => $array_call_history,                
            ];
        }       

        return $array_dos;
    }

    public function get_products_purchase($purchase_id){
        /*$this->loadModel('SpaLiveV1.DataPurchasesDetail');
        
        $_fields = ['DataPurchasesDetail.id','DataPurchasesDetail.purchase_id','DataPurchasesDetail.product_id',
            'DataPurchasesDetail.qty','DataPurchasesDetail.price',
            'products_name' => 'products.name', 'products_id' => 'products.id'];
        
        $data_products = $this->DataPurchasesDetail->find()
            ->select($_fields)
            ->join([
                'products' => ['table' => 'cat_products_other_services', 'type' => 'LEFT', 'conditions' => 'products.id = DataPurchasesDetail.product_id'],
            ])
            ->where(['DataPurchasesDetail.purchase_id' => $purchase_id])
            ->toArray();*/

        $data_products = [
            [
                "id" => 4859,
                "purchase_id" => 2120,
                "product_id" => 1,
                "qty" => 0,
                "price" => 215,
                "products_name" => "SEMAGLUTIDE",
                "products_id" => "1"
            ]
        ];

        return $data_products;
    }

    public function get_service_title($service_uid){
        $this->loadModel('SpaLiveV1.CatOtherServices');
        $service = $this->CatOtherServices->find()->select(['title'])->where(['uid' => $service_uid])->first();
        return $service->title; 
    }

    public function save_purchase_follow_up(){
        //// pago de servicio (mensual)
        //$this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.DataPurchases');

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

        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $this->message('consultation_uid not found.');
            return;
        }


        //id follow up
        $call_id = get('call_id','');
        if (empty($call_id)) {
            $this->message('call id not found.');
            return;
        }

        $call_number = get('call_number','');
        if (empty($call_number)) {
            $this->message('call_number not found.');
            return;
        }

        $call_type = get('call_type','');
        if (empty($call_type)) {
            $this->message('call_type not found.');
            return;
        }
        //follow up id purchase
        $previousFollowUp = $this->DataOtherServicesCheckIn->find()
        ->where([
            'DataOtherServicesCheckIn.id' => $call_id,
            'DataOtherServicesCheckIn.deleted' => 0
        ])
        ->last();

        //obtener purchases por checkin 
        $getPurchaseId =  $this->DataOtherServicesCheckIn->find()
        ->where([
            'DataOtherServicesCheckIn.consultation_uid' => $consultation_uid,
            'DataOtherServicesCheckIn.purchase_id <>' => 0,
            'DataOtherServicesCheckIn.deleted' => 0,
            'call_type IN' => ['FOLLOW UP', 'FIRST CONSULTATION']
        ])
        ->last();

        $previousPurchase = $this->DataPurchases->find()
            ->where([
                'DataPurchases.id' => $getPurchaseId->purchase_id,
                'DataPurchases.deleted' => 0
            ])
        ->first();

        //obtener purchases por consultation id
        /*$previousPurchase = $this->DataPurchases->find()
            ->where([
                'DataPurchases.uid' => $consultation_uid,
                'DataPurchases.deleted' => 0
            ])
            ->last();*/

        //guardar registro parecido al previo purchases other services
        $newPurchase = $previousPurchase;
        $newPurchase->id = null;
        $newPurchase->status = "WAITING FOR THE EXAMINER TO CONFIRM YOUR PRODUCT";
        $newPurchase->tracking = null;
        $newPurchase->shipping_date = null;
        $newPurchase->amount = 0;
        $newPurchase->payment = '';
        $newPurchase->payment_intent = '';
        $newPurchase->signature = 0;
        $newPurchase->call_type = $call_type;
        $newPurchase->call_number = $call_number;
        $newPurchase->tracking = "";
       
        $c_entity = $this->DataPurchases->newEntity($newPurchase->toArray());
        if(!$c_entity->hasErrors()) {
            $ent_saved = $this->DataPurchases->save($c_entity);
            $this->set('purchase', $ent_saved);
            $this->success();
            if ($ent_saved) {
                //AAsigna id del purchase en el checkin
                $previousFollowUp->purchase_id = $ent_saved->id;

                $b_entity = $this->DataOtherServicesCheckIn->newEntity($previousFollowUp->toArray());
                if(!$b_entity->hasErrors()) {
                    $ent_saved1 = $this->DataOtherServicesCheckIn->save($b_entity);
                    $this->set('data', $ent_saved1);
                    $this->success();
                    if ($ent_saved1) {
                        return $ent_saved1->id;
                    }
                }
                return $ent_saved->id;
            }
        }

        $this->success();

    }

    public function update_checkin(){
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

        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        //id follow up
        $call_id = get('call_id','');
        if (empty($call_id)) {
            $this->message('call id not found.');
            return;
        }

        $this->DataOtherServicesCheckIn->updateAll(
            ['support_id' => USER_ID,
            'status' => 'CLAIMED'
            ], 
            ['id' => $call_id]
        );

        $this->success();
    }

    public function save_images_in_checkin() {
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');

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

        $has_images = get('has_images','');
        if (empty($has_images)) {
            $this->message('has_images not found.');
            return;
        }

        $call_id = get('call_id','');
        if (empty($call_id)) {
            $this->message('call id not found.');
            return;
        }

        if($has_images == 1){
            $this->DataOtherServicesCheckIn->updateAll(
                ['has_image' => intval($has_images)], 
                ['id' => $call_id]
            );
        }

        $this->success();
    }

    public function save_consultation_start_end_dates() {
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');

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

        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $this->message('consultation_uid not found.');
            return;
        }

        $this->DataConsultationOtherServices->updateAll(
            [
              'start_date' => date('Y-m-d H:i:s'),
              'end_date' => date('Y-m-d H:i:s', strtotime(date('Y-m-d H:i:s') . ' +90 days')),
            ], 
            ['uid' => $consultation_uid]
        );

        $this->success();
    }

    public function set_checkin_status() {
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');

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

        $call_id = get('call_id','');
        if (empty($call_id)) {
            $this->message('call id not found.');
            return;
        }

        $status = get('status','');
        if (empty($status)) {
            $this->message('status not found.');
            return;
        }

        if($status == 'SCHEDULE'){
            $status = 'SCHEDULED';
        }

        $this->DataOtherServicesCheckIn->updateAll(
            ['status' => $status], 
            ['id' => $call_id]
        );

        $this->success();
    }

    public function get_payment_type() {
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');

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

        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $this->message('consultation_uid not found.');
            return;
        }

        $payment = $this->DataConsultationOtherServices->find()
        ->select('DataConsultationOtherServices.monthly_payment')
        ->where(
            ['DataConsultationOtherServices.uid' => $consultation_uid,
                'DataConsultationOtherServices.deleted' => 0]
        )->first();

        if($payment){
            $this->set('payment_type', $payment);
            $this->success();
        }else{
            $this->set('payment_type', 'No payment type selected');
            $this->success();
        }

    }

    public function get_subscription_prices() {
        $this->loadModel('SpaLiveV1.CatOtherServicesSubscriptionPrices');

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

        $service_uid = get('service_uid','');
        if(empty($service_uid)){
            $this->message('Service uid empty.');
            return;
        }

        $prices = $this->CatOtherServicesSubscriptionPrices->find()
        ->select(
            ['CatOtherServicesSubscriptionPrices.id',
            'CatOtherServicesSubscriptionPrices.subscription',
            'CatOtherServicesSubscriptionPrices.price',
            'CatOtherServicesSubscriptionPrices.discount']
        )
        ->where([
            'CatOtherServicesSubscriptionPrices.service_uid' => $service_uid,
            'CatOtherServicesSubscriptionPrices.deleted' => 0
        ])
        ->all();

        if(!empty($prices)){
            $this->set('prices', $prices);
            $this->success();
        }else{
            $this->message('Prices not found.');
            return;
        }

    }

    public function validation_step_injector(){
        
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

        $this->loadModel('SpaLiveV1.DataAgreements');
        
        $ent_agreement = $this->DataAgreements->find()
        ->where(
            ['DataAgreements.user_id' => USER_ID, 
            'DataAgreements.agreement_uid' => '7gd34qwe-acc1-reg996s-b611-8ty4seg', 
            'DataAgreements.deleted' => 0])
        ->first();

        if(empty($ent_agreement)){
            $this->success();
            $this->set('step', 'CONSENTWL');
            return;
        }
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        
        $consultation = $this->DataConsultationOtherServices->find()->where([
            'patient_id' => USER_ID,
            'payment <>' => '',
            'deleted' => 0 
        ])->first();

        if(empty($consultation)){
            $this->success();
            $this->set('step', 'WELCOMEWL');
            return;
        }

        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');

        $arr_conditions = [
            'DataOtherServicesCheckIn.consultation_uid' => $consultation->uid,
            'DataOtherServicesCheckIn.call_type' => 'FIRST CONSULTATION',
            'DataOtherServicesCheckIn.deleted' => 0,
        ];

        $getCall = $this->DataOtherServicesCheckIn->find()
        ->select(['DataOtherServicesCheckIn.purchase_id', 'DataOtherServicesCheckIn.id'])
        ->where($arr_conditions)
        ->first();

        $this->loadModel('SpaLiveV1.DataPurchases');
        
        $purchase = $this->DataPurchases->find()
        ->where(['id' => $getCall->purchase_id])->first();

        if($purchase->address == '' || $purchase->suite == '' || $purchase->state == '' || $purchase->city == '' || $purchase->zip == '' ){
            $this->success();
            $this->set('step', 'PAYMENTSUCCESSWL');
            $this->set('consultation_uid', $consultation->uid);
            $this->set('service_uid', $consultation->service_uid);
            $this->set('call_id', $getCall->id);
            return;
        }

        $this->loadModel('SpaLiveV1.DataOtherServicesAnswers');

        $arr_conditions = [
            'DataOtherServicesAnswers.consultation_id' => $consultation->id,
        ];

        $getAnswers = $this->DataOtherServicesAnswers->find()
        ->select(['DataOtherServicesAnswers.id'])
        ->where($arr_conditions)
        ->all();

        if (count($getAnswers) <= 0) {
            $this->success();
            $this->set('step', 'QUESTIONNARIEWL');
            $this->set('consultation_uid', $consultation->uid);
            $this->set('call_id', $getCall->id);
            return;
        }
        
        $this->success();
        $this->set('step', 'HOMEWL');
        $this->set('consultation_uid', $consultation->uid);
        $this->set('call_id', $getCall->id);
        return;
    }


    public function update_call_status() {
       $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');

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


        $call_id = get('call_id','');
        if (empty($call_id)) {
            $this->message('call_id not found.');
            return;
        }

        if($call_id == null || $call_id == 'null'){
            $this->success();
            return;
        }

        $status = get('status','');
        if (empty($status)) {
            $this->message('status not found.');
            return;
        }

        $ent = $this->DataOtherServicesCheckIn->find()->where(['DataOtherServicesCheckIn.id' => $call_id])->first();
        
        if (!empty($ent) && $ent->status != 'ONLINE') {
            $this->success();
            return;
        }
        
        $update = $this->DataOtherServicesCheckIn->updateAll(
            ['status' => $status], 
            ['id' => $call_id]
        );

        $call_status = $this->DataOtherServicesCheckIn->find()
        ->select('DataOtherServicesCheckIn.status')
        ->where([
            'DataOtherServicesCheckIn.id' => $call_id,
            'DataOtherServicesCheckIn.deleted' => 0
        ])
        ->first();

        $this->set('call_status',$call_status); 
        $this->success();
    }

    public function schedule_weight_loss(){                   
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

        $call_id = get('call_id','');
        if (empty($call_id)) {
            $this->message('call_id not found.');
            return;
        }

        $status = get('status','');
        if (empty($status)) {
            $this->message('status not found.');
            return;
        }

        $scheduled_date = get('scheduled_date','');
        if (empty($scheduled_date)) {
            $this->message('scheduled_date not found.');
            return;
        }
        
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');                
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');                        
        $checkin = $this->DataOtherServicesCheckIn->find()->select(['DataOtherServicesCheckIn.id','DataOtherServicesCheckIn.consultation_uid', 
        'DataOtherServicesCheckIn.call_date', 'DataOtherServicesCheckIn.call_time'])
        ->where(['DataOtherServicesCheckIn.id' => $call_id])->first();
        if (empty($checkin)) {
            $this->message('Data Check in Other Services not found.');
            return;
        }

        $s_date = date('m/d/Y H:i',strtotime($scheduled_date));
         
        $firebase_update_data = array(            
            "uid" => $checkin->consultation_uid,
            "status" => "CLAIM PENDING",
            "scheduled_date" => $s_date,
        );
        
        $data_string = json_encode($firebase_update_data);
        $url = env('URL_MICROSERVICE', 'http://localhost:3131/') . 'updateServicesArr';
        $ch=curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);//curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER,
            array(
                'Content-Type:application/json',
                'Content-Length: ' . strlen($data_string)
            )
        );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);  
        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            $this->message($error_msg);
            $this->success(false);
            return;
            // this would be your first hint that something went wrong                            
        } else {
            // check the HTTP status code of the request
            $resultStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($resultStatus == 200) {
                //unlink($filename);// everything went better than expected
            } else {
                // the request did not complete as expected. common errors are 4xx
                // (not found, bad request, etc.) and 5xx (usually concerning
                // errors/exceptions in the remote script execution)                                
            }
        }
        curl_close($ch);
        
        $date_format = strtotime($scheduled_date);        //$fec = '2023-08-11 4:16 PM';
        $scheduled_date = date('Y-m-d',$date_format);
        $call_time = date('H:i A',$date_format);
        $database_update_data = array(            
            "uid" => $checkin->consultation_uid,
            "status" => "CLAIM_PENDING",
            "call_date" => $scheduled_date,
            "call_time" => $call_time,
            "support_id" => ""
        );
        
        $this->DataOtherServicesCheckIn->updateAll(
            $database_update_data, 
            ['id' => $call_id] 
        );
        $database_update_data = array(                                    
            "schedule_date" => $scheduled_date,
        );
        $this->DataConsultationOtherServices->updateAll(
            ['schedule_date' => $scheduled_date, 'status' => 'IN PROGRESS'],
            ['uid' => $checkin->consultation_uid] 
        );                 
        $this->success();
        $this->message("Success.");    
        return;                                               
    }

    public function get_patients_weightloss() {
        $this->loadModel('SpaLiveV1.DataPatientClinic');

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

       
        $_where = ['SysUsers.deleted' => 0, 'DataPatientClinic.injector_id' => USER_ID,'DataPatientClinic.deleted' => 0, 'SysUsers.steps <>' => 'REGISTER'];

        $_fields = ['SysUsers.uid','SysUsers.short_uid','SysUsers.name','SysUsers.lname','SysUsers.id','DataPayment.id','DataPayment.uid'];
        $_fields['invited'] = "(SELECT count(*) FROM data_patient_clinic  WHERE deleted =0 and type = 'weightloss' and user_id = SysUsers.id)";
        //$_fields['invited'] = "(SELECT count(*) FROM data_patient_clinic  WHERE injector_id = ". USER_ID ."  and deleted =0 and type = 'weightloss' and user_id = SysUsers.id)";
        //$_fields['Payment'] = "(SELECT count(*) FROM data_payment  WHERE type ='WEIGHT LOSS' and cancelled =0 and id_from = SysUsers.id )";
        $_fields['PaymentCom'] = "(SELECT total FROM data_payment  WHERE data_payment.type ='WEIGHT LOSS COMMISSION' and data_payment.cancelled =0 and data_payment.uid = DataPayment.uid and data_payment.id_to = ". USER_ID ." limit 1)";

        $ent_users = $this->DataPatientClinic->find()
        ->select($_fields)
        ->join([
            'SysUsers' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'DataPatientClinic.user_id = SysUsers.id'],
            'DataPayment' => ['table' => 'data_payment', 'type' => 'LEFT', 'conditions' => 'DataPayment.id_from = SysUsers.id and DataPayment.type ="WEIGHT LOSS" and DataPayment.cancelled =0'],
        ])
        ->where($_where)
        ->group(['SysUsers.id'])
        ->all();

        $result = array();

        if (!empty($ent_users)) {

            
            foreach ($ent_users as $row) { 
                $status = 'notinvited';
                $paid = false;
                $amount = 0;
                if($row['invited'] > 0){
                    $status = 'invited';
                }else{
                    $status = 'notinvited';
                }
                if(isset($row->DataPayment['id'])){//, the patient  paid for weight loss
                    continue;
                    $paid = false;
                    $status = "in_process_payment";
                    if(isset($row->DataPayment['id'])){//, the patient  paid for weight loss                        
                        $status = "in_process_payment";
                        if(isset($row->PaymentCom)){//, the injector received the tranfer the commission
                            $status = 'paid';
                            $paid = true;                            
                            $amount = $row->PaymentCom;
                            continue;
                        }
                    }    
                }else{
                    $paid = false;
                    $amount = 0;
                }
                $t_array = array(
                    'id' => $row->SysUsers['id'],
                    'uid' => $row->SysUsers['uid'],
                    'short_uid' => $row->SysUsers['short_uid'], 
                    'name' => $row->SysUsers['name'] . ' ' . $row->SysUsers['lname'],
                    'status' => $status,
                    'paid' => false,                    
                    'amount' => 0,
                );

                $result[] = $t_array;
            }

        }

        $first_text ='MySpalive now offers weight loss treatments to our patients. The service includes one semaglutide vial per month for a duration of 3 months, along with personalized assistance during the treatment, provided by one of our Weight Loss Specialists.';
        $second_text ='You are invited to improve your business revenue by inviting your patients to participate of the program. If the patient pays for the program month to month ($595/monthly), you will receive $33  on each month. And if the patient purchase the full 3 month program which is $1,585 after receiving our $200 discount, you receive $100.';
        $this->set('data', $result);
        $this->set('first_text', $first_text);
        $this->set('second_text', $second_text);
        //$this->set('injector_uid', USER_UID);
        $this->success();
    

    }

    public function save_schedule_other_services(){
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

        $model = 'other_services';
        $this->loadModel('SpaLiveV1.DataScheduleModel');
        $this->loadModel('SpaLiveV1.DataScheduleDaysOff');
        $lm_entity = $this->DataScheduleModel->find()->where([
            'DataScheduleModel.injector_id' => USER_ID, 
            'DataScheduleModel.model' => $model,
            'DataScheduleModel.deleted' => 0
        ])->first();

        if (!empty($lm_entity)) 
            $s_id = $lm_entity->id;
        else 
            $s_id = 0;

        $t_start = get('time_start',9);
        $t_end = get('time_end',20);

        if ($t_end <= $t_start) {
            $this->message('End Time should be greater than Start Time.');
            return;
        }            

        $array_save_m = array(
            'id' => $s_id,
            'injector_id' => USER_ID,
            'days' => get('days',''),
            'time_start' => $t_start,
            'time_end' => $t_end,
            'model' => $model
        );

        $m_entity = $this->DataScheduleModel->newEntity($array_save_m);
        if(!$m_entity->hasErrors()) {
            $this->DataScheduleModel->save($m_entity);    
            
        }

        $skd_dates = json_decode(get('skd_dates', '[]'), true);
        $inj_id = USER_ID;
        $this->DataScheduleModel->getConnection()->execute("UPDATE data_schedule_model SET deleted = 1 WHERE injector_id = {$inj_id} AND days NOT LIKE '%,%' AND model = '{$model}'");
        foreach($skd_dates as $item){
            $arrSaveDay = array(
                'injector_id' => USER_ID,
                'days'        => $item['days'],
                'time_start'  => $item['time_start'],
                'time_end'    => $item['time_end'],
                'model'       => $model
            );
            $day_entity = $this->DataScheduleModel->newEntity($arrSaveDay);
            if(!$day_entity->hasErrors()) {
                $this->DataScheduleModel->save($day_entity);
            }
        }

        $days_off = json_decode(get('days_off', '[]'), true);
        $this->DataScheduleDaysOff->updateAll(
            ['deleted'   => 1], ['user_id' =>  USER_ID]
        );
        foreach ($days_off as $item) {
            $arrSaveDayOff = array(
                'user_id' => USER_ID,
                'date_off' => $item,
            );
            $day_entity = $this->DataScheduleDaysOff->newEntity($arrSaveDayOff);
            if(!$day_entity->hasErrors()) {
                $this->DataScheduleDaysOff->save($day_entity);
            }
        }

        $this->success();
    }

    public function get_schedule_other_services(){
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

        $model = 'other_services';
        $array_response = array();

        $this->loadModel('SpaLiveV1.DataScheduleModel');
        $this->loadModel('SpaLiveV1.DataScheduleDaysOff');
        $lm_entity = $this->DataScheduleModel->find()
            ->where([
                'DataScheduleModel.deleted' => 0, 
                'DataScheduleModel.days NOT LIKE' => '', 
                'DataScheduleModel.model' => $model,
                'DataScheduleModel.injector_id' => USER_ID
            ])->toArray();

        $days_settings = [];
        if(!empty($lm_entity)){
            foreach($lm_entity as $item){
                $days = explode(',', $item->days);
                foreach($days as $day){
                    if(!empty($day)){
                        $days_settings[] = [
                            'days' => $day,
                            'time_start' => $item->time_start,
                            'time_end' => $item->time_end
                        ];
                    }
                }
            }            
        } else {
            $days_settings = [];
        }

        $days_off = $this->DataScheduleDaysOff->find()->select(['DataScheduleDaysOff.date_off'])
            ->where(['DataScheduleDaysOff.user_id' => USER_ID, 'DataScheduleDaysOff.deleted' => 0])->toArray();
        $array_response['days_off'] = (!empty($days_off) ? Hash::extract($days_off, '{n}.date_off') : []);
        $array_response['days_settings'] = $days_settings;
        $array_response['time_start'] = 9;
        $array_response['time_end'] = 20;
        
        $show_schedule = false;

        $this->loadModel('SpaLiveV1.SysUsers');
        $users = $this->SysUsers->find()->select(['SysUsers.id'])
            ->join([
                "DataUsersOtherServicesCheckIn" => [
                    "table" => "data_users_other_services_check_in",
                    "type" => "INNER",
                    "conditions" => [
                        "DataUsersOtherServicesCheckIn.user_id = SysUsers.id",
                        "DataUsersOtherServicesCheckIn.deleted" => 0
                    ]
                ]
            ])
            ->where([
                "SysUsers.id" => USER_ID,
                "SysUsers.deleted" => 0
            ])
            ->first();

        if (!empty($users)) {
            $show_schedule = true;
        }

        $this->set('show_schedule', $show_schedule);
        $this->set('data', $array_response);
        $this->success();
    }    

    public function invite_patien_weightloss()
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
        $patients = get('patients', '');      
        $email = get('email', '');      

        if(empty($patients)){
            $this->message('Invalid patients.');
            return;
        }   

        $pos =  strpos($patients,',');
        if($pos != false){
             
            $arr_usr = explode(',',$patients);
        }else{
            if (ctype_digit($patients)) {
                $arr_usr[] = $patients;
            } else {
                $arr_usr = [];
            }
            
        }

        
         
        if(count($arr_usr)<1){
            $this->message('Invalid patients.');
            return;
        }
        for ($i=0; $i<count($arr_usr); $i++){
        
            $this->loadModel('SpaLiveV1.DataPatientClinic');                      
            $verify_invite = $this->DataPatientClinic->find()->where(['DataPatientClinic.user_id' => $arr_usr[$i], 'DataPatientClinic.type = "weightloss"','DataPatientClinic.deleted = 0'])->all();     
            
            if(count($verify_invite) > 0){
                $resend = get('resend', false);
                $resend = boolval($resend);
                if($resend){
                    $this->loadModel("SpaLiveV1.SysUsers");
                $patient = $this->SysUsers->find()->where(['SysUsers.id' => $arr_usr[$i]])->first();                                
                
                    $msg_mail = "<p>Hey ".$patient['name'] ." ".  $patient['mname'] ." ". $patient['lname'] ."</p> </br>".
                    "<p>It's ".$user['name'] ." ".  $user['mname'] ." ". $user['lname'] ." here! I'm excited to introduce you to MySpaLive's exclusive Semaglutide Weight Loss Program.</p></br>".
                    "<p>The 3-month program is designed to help you embark on a journey towards a healthier, more confident you. With the aid of semaglutide, a cutting-edge weight loss medication, you'll find shedding those extra pounds a breeze.</p></br>".
                    "<p>Here's the scoop:</p></br>".
                    "❤<b>Simple Self-Administered Injections:</b> No need for appointments or waiting rooms. ❤<b>Online Consultations:</b> Get approved for the medication through a hassle-free telehealth meeting. ❤<b>Personalized Plans:</b> Benefit from custom meal and workout plans tailored to your needs. ❤<b>Regular Check-ins:</b> Stay motivated and on track with weekly support from the MySpaLive team. ❤<b>A Special Welcome Gift:</b> Kickstart your journey with a chic MySpaLive water bottle.🎁</br>".
                    "<p>💸Choose your payment plan:</p></br>".
                    "</br><ul>".
                    "<li>Month to Month: $1795 (just $598.30/month)</li>".
                    "<li>Pay Upfront: $1595 (save $200!)</li>".
                    "</ul></br>".
                    "<p>Tap the link below to download the MySpaLive app and take the first step towards embracing your best self:</p></br>".
                    "<p>🔗<a href='https://app.myspalive.com/redirect' link='' style='color:#60537A;text-decoration:underline'><strong>https://app.myspalive.com/redirect</strong></a></p></br>".
                    "<p>Please know that I'm here to support you every step of the way. Feel free to reach out with any questions or to share your progress - I'd love to hear from you!</p></br>".
                    "<p>Here's to a healthier, happier you!</p></br>".
                    "<p>-".$user['name'] ." ".  $user['mname'] ." ". $user['lname'] ."</p>";                                                             

                    $html_content = '<img src="' . $this->URL_PANEL . 'img/logo.png" width="100px"/>' . $msg_mail;
                    $Main = new MainController();
                    $data=array(
                        'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
                        'to'      => $patient['email'],                        
                        'subject' => 'Discover Your Best Self: Exclusive Weight Loss Program Just for You!🌟',
                        'html'    =>  $Main->getEmailFormat($html_content) ,
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
                

                    $this->success();
                }else{
                    $this->message('This patient has already been invited.');            
                    $this->set('patient_id',$arr_usr[$i]);            
                }
                
            }else{
                $invite = array(
                    'uid' => Text::uuid(),
                    'injector_id' => USER_ID,
                    'user_id' => $arr_usr[$i],                
                    'type' => 'weightloss',
                );
            

            

            $ent_invite = $this->DataPatientClinic->newEntity($invite);
            if($this->DataPatientClinic->save($ent_invite)){
                $this->loadModel("SpaLiveV1.SysUsers");
                $patient = $this->SysUsers->find()->where(['SysUsers.id' => $arr_usr[$i]])->first();
                //$ent_notification = $this->CatNotifications->find()->where(['CatNotifications.title' => 'CI_TO_CI_INVITE'])->first();
                //if (!empty($ent_notification)) {
                    //$msg_mail = $ent_notification['body'];
                    $msg_mail = "<p>Hey ".$patient['name'] ." ".  $patient['mname'] ." ". $patient['lname'] ."</p> </br>".
                    "<p>It's ".$user['name'] ." ".  $user['mname'] ." ". $user['lname'] ." here! I'm excited to introduce you to MySpaLive's exclusive Semaglutide Weight Loss Program.</p></br>".
                    "<p>The 3-month program is designed to help you embark on a journey towards a healthier, more confident you. With the aid of semaglutide, a cutting-edge weight loss medication, you'll find shedding those extra pounds a breeze.</p></br>".
                    "<p>Here's the scoop:</p></br>".
                    "❤<b>Simple Self-Administered Injections:</b> No need for appointments or waiting rooms. ❤<b>Online Consultations:</b> Get approved for the medication through a hassle-free telehealth meeting. ❤<b>Personalized Plans:</b> Benefit from custom meal and workout plans tailored to your needs. ❤<b>Regular Check-ins:</b> Stay motivated and on track with weekly support from the MySpaLive team. ❤<b>A Special Welcome Gift:</b> Kickstart your journey with a chic MySpaLive water bottle.🎁</br>".
                    "<p>💸Choose your payment plan:</p></br>".
                    "</br><ul>".
                    "<li>Month to Month: $1795 (just $598.30/month)</li>".
                    "<li>Pay Upfront: $1595 (save $200!)</li>".
                    "</ul></br>".
                    "<p>Tap the link below to download the MySpaLive app and take the first step towards embracing your best self:</p></br>".
                    "<p>🔗<a href='https://app.myspalive.com/redirect' link='' style='color:#60537A;text-decoration:underline'><strong>https://app.myspalive.com/redirect</strong></a></p></br>".
                    "<p>Please know that I'm here to support you every step of the way. Feel free to reach out with any questions or to share your progress - I'd love to hear from you!</p></br>".
                    "<p>Here's to a healthier, happier you!</p></br>".
                    "<p>-".$user['name'] ." ".  $user['mname'] ." ". $user['lname'] ."</p>";


                    
        
                                    

                    $html_content = '<img src="' . $this->URL_PANEL . 'img/logo.png" width="100px"/>' . $msg_mail;
                    $Main = new MainController();
                    $data=array(
                        'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
                        'to'      => $patient['email'],                        
                        'subject' => 'Discover Your Best Self: Exclusive Weight Loss Program Just for You!🌟',
                        'html'    =>  $Main->getEmailFormat($html_content) ,
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
                //}

                $this->success();
            }
        }
        }
    }

    public function add_to_cart_screen(){
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

        $this->loadModel('SpaLiveV1.DataPatientsAddToCart');
       
        $atc_entity = $this->DataPatientsAddToCart->find()
            ->where(['DataPatientsAddToCart.deleted' => 0])
            ->order(['DataPatientsAddToCart.position' => 'ASC'])
            ->all();

        if (!empty($atc_entity)) {
            $this->set('add_to_cart', $atc_entity);
            $this->success();
        } else {
            $this->set('add_to_cart', []);
            $this->success();
        }
        
    }

    public function default_invite_injector(){
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

        $this->loadModel('SpaLiveV1.DataPatientClinic');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataPayment');

        $invitation = $this->DataPatientClinic->find()
        ->where(['DataPatientClinic.user_id' => USER_ID, 'DataPatientClinic.deleted' => 0, 'DataPatientClinic.type' => 'weightloss'])
        ->first();

        if(empty($invitation)){
            $this->set('Invitation', null);
            $this->success();
            return;
        }

        $injector = $this->SysUsers->find()->where(['id' => $invitation->injector_id, 'deleted' => 0])->first();

        if(empty($injector)){
            $this->set('Invitation', null);
            $this->success();
            return;
        }
        if($injector->id == USER_ID){
            $this->set('Invitation', null);
            $this->success();
            return;
        }

        $this->set('Invitation', array(
            'id' => $injector->id,
            'name' => !empty($injector->mname) ? $injector->name.' '.$injector->mname.' '.$injector->lname : $injector->name.' '.$injector->lname,
            'email' => $injector->email,
        ));
        
        $this->success();
    }

    public function getPastCheckIn() {

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

        $fields = [
            'Patient.name',
            'Patient.lname',
            'DataOtherServicesCheckIn.uid',
            'DataOtherServicesCheckIn.purchase_id',
            'DataOtherServicesCheckIn.call_date',
            'DataOtherServicesCheckIn.call_time',
            'Images.images',
            'Questions.data'
        ];

        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $entCheckin = $this->DataOtherServicesCheckIn->find()->select($fields)->join([
            'Patient' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'Patient.id = DataOtherServicesCheckIn.patient_id'],
            'Images' => ['table' => 'data_other_services_patient_images', 'type' => 'LEFT', 'conditions' => 'Images.checkin_id = DataOtherServicesCheckIn.id'],
            'Questions' => ['table' => 'data_consultation_postexam_other_services', 'type' => 'LEFT', 'conditions' => 'Questions.check_in_id = DataOtherServicesCheckIn.id'],
        ])
        ->where([
            'DataOtherServicesCheckIn.support_id' => USER_ID,
            'DataOtherServicesCheckIn.deleted' => 0,
            'DataOtherServicesCheckIn.call_type' => 'CHECK IN',
            'DataOtherServicesCheckIn.status' => 'COMPLETED'
        ])
        ->order(['DataOtherServicesCheckIn.id' => 'DESC'])
        ->all();

        $resultData = array();

        foreach($entCheckin as $row){

            $questions_json = $row['Questions']['data'];
            if(empty($questions_json)){                
                $questions_data = [];
            }else{
                $questions_data = json_decode($questions_json);
            }
            $date = $row->call_date->i18nFormat('MM-dd-Y');
            $time = $row->call_time->i18nFormat('hh:mm a');
            $resultData[] = array(
                'uid' => $row->uid,
                'call_type' => 'CHECK IN',
                'purchase_id' => $row->purchase_id ? $row->purchase_id : 0,
                'call_datetime' => $date . ' ' . $time,
                'patient' => $row['Patient']['name'] . ' ' . $row['Patient']['lname'],
                'images' =>  $row['Images']['images'],
                'questions' => $questions_data
            );
        }

        $this->set('data', $resultData);
        $this->success();
    }

    public function reminder_checkin_nine(){
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');

        $checkins = $this->DataOtherServicesCheckIn->find()
        ->select(['DataOtherServicesCheckIn.call_date', 'DataOtherServicesCheckIn.call_time', 'DataOtherServicesCheckIn.support_id' , 'User.name', 'User.lname'])
        ->join([
             'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataOtherServicesCheckIn.patient_id']
        ])
        ->where(['DataOtherServicesCheckIn.deleted' => 0,
                'DataOtherServicesCheckIn.status' => 'CLAIMED',
                'DataOtherServicesCheckIn.call_type' => 'CHECK IN',
                'DataOtherServicesCheckIn.support_id <>' => 0,
                'User.deleted' => 0])
        ->all();

        if(Count($checkins) > 0){
            $date = date('Y-m-d');
            foreach($checkins as $checkin) {
                if($checkin->call_date->i18nFormat('yyyy-MM-dd') == $date){
                    $Main = new MainController();
                    $Main->notify_devices('Reminder: Today you have a check-in with ' . $checkin['User']['name'] . ' ' . $checkin['User']['lname'] . ' at ' . $checkin->call_time->i18nFormat('HH:mm a'), array($checkin->support_id), false, true, true, array(), '', array(), true);
                }else{
                    continue;
                }
            }
        }
    }

    public function reminder_checkin_fivemin(){
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');

        $checkins = $this->DataOtherServicesCheckIn->find()
        ->select(['DataOtherServicesCheckIn.call_date', 'DataOtherServicesCheckIn.call_time', 'DataOtherServicesCheckIn.support_id' , 'User.name', 'User.lname'])
        ->join([
             'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataOtherServicesCheckIn.patient_id']
        ])
        ->where(['DataOtherServicesCheckIn.deleted' => 0,
                'DataOtherServicesCheckIn.status' => 'CLAIMED',
                'DataOtherServicesCheckIn.call_type' => 'CHECK IN',
                'DataOtherServicesCheckIn.support_id <>' => 0,
                'User.deleted' => 0])
        ->all();

        if(Count($checkins) > 0){
            $date = date('Y-m-d');
            foreach($checkins as $checkin) {
                if($checkin->call_date->i18nFormat('yyyy-MM-dd') == $date){
                    $currentTime = time();
                    $checkinTime = strtotime($checkin->call_time->i18nFormat('HH:mm'));
                    $diffSeconds = $checkinTime - $currentTime;
                    pr($diffSeconds);
                    if($diffSeconds <= 480 && $diffSeconds >= 120){ // 8 min - 2 min
                        $Main = new MainController();
                        $Main->notify_devices('Reminder: In 5 minutes, you have a check-in with ' . $checkin['User']['name'] . ' ' . $checkin['User']['lname'] . ' at ' . $checkin->call_time->i18nFormat('HH:mm a'), array($checkin->support_id), false, true, true, array(), '', array(), true);
                    }else{
                        continue;
                    }
                    exit;
                }else{
                    continue;
                }
            }
        }
    }

    public function information_specialist() {
        $this->loadModel('SpaLiveV1.CatWeightLossSpecialistPage');

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

        $url_video = '';
        $acknows = [];

        $_where = ['CatWeightLossSpecialistPage.deleted' => 0];

        $entity = $this->CatWeightLossSpecialistPage->find()->where($_where);

        if(!empty($entity)){
            foreach($entity as $row) {
                if($row->type == "Video"){
                    $url_video = $row->title;
                }else{
                    $acknows[] = array(
                        'name'  => $row->title,
                        'value' => false,
                    );
                }
            }
        }

        $data = array(
            'acknows' => $acknows,
            'url_video' => $url_video
        );

        $this->set('data', $data);

        $this->success();

    }

    public function save_info_wl_specialist() {
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

        $user_fullname = USER_NAME.' '.USER_LNAME;

        $this->loadModel('SpaLiveV1.DataAgreements');
        $this->loadModel('SpaLiveV1.CatAgreements');
        $this->loadModel('SpaLiveV1.DataUsersOtherServicesCheckIn');

        $is_dev = env('IS_DEV', false);

        $ent_agreement = $this->CatAgreements->find()->where(['CatAgreements.id' => 42,
                                                              'CatAgreements.deleted' => 0])->first();

        $agreement = '
            <h4>Agreement for Weight Loss Specialist</h4>

            <p>This Agreement ("Agreement") is entered into between [Name of the first part], with a registered address at
                [Address of the first part], hereinafter referred to as "part A," and [Name of the second part], with a
                registered address at [Address of the second part], hereinafter referred to as "part B." Both parties shall be
                collectively referred to as the "Parties."</p>

            <h4>1. Purpose of Agreement</h4>
            <p>The Parties agree to [describe the purpose of the agreement].</p>

            <h4>2. Signature</h4>
            <p>This Agreement is signed on [date of signing].</p>

            <p>_____________________________</p>
            <p>'. $user_fullname .'</p>';

        $array_save = array(
            'uid' => Text::uuid(),
            'user_id' => USER_ID,
            'sign' => $user_fullname,
            'agreement_uid' => $ent_agreement->uid,
            'file_id' => 0,
            'content' => $agreement,
            'created' => date('Y-m-d H:i:s'),
        );

        $entity = $this->DataAgreements->newEntity($array_save);
        if(!$entity->hasErrors()){
            if($this->DataAgreements->save($entity)){

                $array_user_other = array(
                    'user_id'   => USER_ID,
                    'deleted'   => 0,
                    'status'    => "AVAILABLEDAYS",
                    'created'   => date('Y-m-d H:i:s'),
                );

                $entity_other = $this->DataUsersOtherServicesCheckIn->newEntity($array_user_other);
                if(!$entity_other->hasErrors()){
                    if($this->DataUsersOtherServicesCheckIn->save($entity_other)){
                        $this->success();
                    }
                }
            }else{
                $this->message('Error trying to save agreement.');
                return;
            }
        }

        //$this->set('data', $array_save);
        $this->send_pdf_questions(USER_EMAIL);
        $this->success();
    }

    private function questions_pdf(){
        // $type = "SUBSCRIPTIONMSL";
        //$type = "SUBSCRIPTIONMD";
        // $realTotal = $total = $amount;
        // $address = 'Alamo 100';
        // $user_name    = 'Luis Valdez';
        
        $date = date('Y-m-d H:i:s');
        $logo = 'myspalive-logo1.png';

        $url_panel = 'https://blog.myspalive.com/';
       
        $html_content = "
        
            <div style=\"padding: 8px 1px 8px 1px; width: 100%\">
                <div style=\"width: 100%; display: inline-flex;\">
                    <img style=\"margin-left: 0px;\" height=\"90\" src=\"{$url_panel}{$logo}\">
                </div>
            </div> 
            <div style=\"margin-top:52px; padding: 0px 16px 16px 16px;\">
                <h1>Check-in questionnaire</h1><br>
                <p>How have you feeling?</p> <br>
                <p>Have you had any weight loss? if so, how much?</p> <br>
                <p>Have you experienced any negative side effects?</p> <br>
                <p>How has your mental health been? Any changes in this area?</p> <br>
                <p>How has your energy level been? Any changes in this area?</p> <br>
                <p>How has your nutrition been this week?</p> <br>
                <p>How many times did you work out this week?</p> <br>
                <p>On a daily basis, how much water you have been drinking?</p> <br>
                <p>Are you seeing the desired results?</p> <br>
                <p>How do you feel you can improve this week? and/or how can we help you this week?</p> <br>
            </div>
            ";

        $filename = TMP . 'subscription_receipts' . DS . 'check_in_questions.pdf';
        $html2pdf = new HTML2PDF('P','A4','en', true, 'UTF-8', array(10, 10, 10, 10));
        $html2pdf->WriteHTML($html_content);
        $html2pdf->Output($filename, 'F'); //,'D'
        return $filename;
    }

    private function send_pdf_questions($str_email){

        $filename = $this->questions_pdf();

        if(empty($filename)){
            return;
        }
        
        $data = array(
            'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
            'to'    => $str_email,
            'subject' => 'MySpaLive Check-in questionnaire',
            'html'    => "Congratulations on your new Weight Loss Specialist approval. <br>
                        If a patient purchases weight loss products after you invite them, you will be assigned as the Weight Loss Specialist and will receive $100. One week after the patient gets the product, you will automatically be scheduled to call them and ask about their progress. You will also need to fill out a questionnaire (see attached PDF) during each check-in call. You will receive $50 for each call you make, up to 7 calls during the process. <br>
                        If you have any questions, send us an email to myspa@myspalive.com",
            'attachment[1]' => curl_file_create($filename, 'application/pdf', 'MySpaLive_questionnaire.pdf'),
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
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type:multipart/form-data"]);

        $result = curl_exec($curl);
        curl_close($curl);

        unlink($filename);
    }

    public function get_step_wl_specialist() {
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

        // verify if is a wls
        $this->loadModel('SpaLiveV1.DataUsersOtherServicesCheckIn');
        $wls = $this->DataUsersOtherServicesCheckIn->find()->where(['DataUsersOtherServicesCheckIn.user_id' => USER_ID,
        'DataUsersOtherServicesCheckIn.deleted' => 0])->first();
        $this->set('type_wls', 'none');

        //verify buy wl program        
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $consultation = $this->DataConsultationOtherServices->find()->where(['DataConsultationOtherServices.patient_id' => USER_ID,
        'DataConsultationOtherServices.deleted' => 0])->first();
        
        if (empty($wls)) {
            // Aqui entra y significa que no es Especialista WL
            $this->set('step', 'INFO');
            if(!empty($consultation)){
                // Compro programa WL Patient
                $this->set('type_wls', 'WL_PROGRAM'); //wl program
            } else {
                // No es especialista ni compro programa WL Patient
                $this->set('type_wls', 'NONE');
            }
            $this->success();
            return;
        } else {
            // Es especialista WL
            switch ($wls->status) {
                case 'AVAILABLEDAYS': // Esta ingresando sus dias disponibles
                    $this->set('step', 'AVAILABLE_DAYS');
                    if(!empty($consultation)){
                        // Especialista WL y compro programa WL Patient
                        $this->set('type_wls', 'WL_SPECIALIST_PROGRAM'); //wl program
                    } else {
                        // Especialista WL y no compro programa WL Patient
                        $this->set('type_wls', 'WL_SPECIALIST'); //wl program
                    }
                    $this->success();
                    return;
                case 'WLSHOME': // Ya esta en el home de Especialista WL
                    $this->set('step', 'WL_SPECIALIST');
                    if(!empty($consultation)){
                        // Especialista WL y compro programa WL Patient
                        $this->set('type_wls', 'WL_SPECIALIST_PROGRAM'); //wl program
                    } else {
                        // Especialista WL y no compro programa WL Patient
                        $this->set('type_wls', 'WL_SPECIALIST'); //wl program
                    }
                    $this->success();
                    return;
                case 'CANCELLED':
                    $this->set('step', 'INFO');
                    if(!empty($consultation)){
                        // Compro programa WL Patient
                        $this->set('type_wls', 'WL_PROGRAM'); //wl program
                    } else {
                        // No es especialista ni compro programa WL Patient
                        $this->set('type_wls', 'NONE');
                    }
                    $this->success();
                    return;
            }
        }

        // if(!empty($wls)){
        //     $this->set('type_wls', 'WL_SPECIALIST'); //wls
        //     if(!empty($consultation)){
        //         $this->set('type_wls', 'WL_SPECIALIST_PROGRAM'); //wl program
        //     }
        // }else if(!empty($consultation)){
        //     $this->set('type_wls', 'WL_PROGRAM'); //wl program
        // }

        // //find if the specialist has agreement
        // $this->loadModel('SpaLiveV1.DataAgreements');

        // $ent_agreement = $this->DataAgreements->find()->where(['DataAgreements.user_id' => USER_ID,
        // 'DataAgreements.agreement_uid' => 'fsdf541af6w4-35asf6a5-5af1we6-JOUL',
        // 'DataAgreements.deleted' => 0])->first();

        // if(empty($ent_agreement)){
        //     $this->set('step', 'INFO');
        //     $this->success();
        //     return;
        // }

        // //find if the specialist has available days
        // $this->loadModel('SpaLiveV1.DataScheduleModel');

        // $ent_schedule = $this->DataScheduleModel->find()->where(['DataScheduleModel.injector_id' => USER_ID,
        // 'DataScheduleModel.model' => 'other_services',
        // 'DataScheduleModel.deleted' => 0])->all();

        // if(count($ent_schedule) <= 0){
        //     $this->set('step', 'AVAILABLE_DAYS');
        //     $this->success();
        //     if(!empty($wls)){// it is a WLS but info is not complete
        //         if(!empty($consultation)){// buy a wl program   
        //             $this->set('type_wls', 'WL_PROGRAM'); //wl program
        //         }else{
        //             $this->set('type_wls', 'NONE');
        //         }
        //     }else if(!empty($consultation)){
        //         $this->set('type_wls', 'WL_PROGRAM'); //wl program
        //     }
        //     return;
        // }

        // $this->set('step', 'WL_SPECIALIST');
        // $this->success();
        
    }

    public function get_specialist_available() {
        $token = get('token', '');
        if (!empty($token)) {
            $user = $this->AppToken->validateToken($token, true);
            if ($user === false) {
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

        //List of arrays with the days and hours of availability
        $available = array(
            array(
                'day' => 'Monday',
                'start' => [
                    '08:00',
                    '19:00'
                ],
                'end' => [
                    '09:00',
                    '20:00'
                ],
                'selected' => true,
                'start_time' => '',
                'end_time' => ''
            ),
            array(
                'day' => 'Tuesday',
                'start' => [
                    '08:00',
                    '19:00'
                ],
                'end' => [
                    '09:00',
                    '20:00'
                ],
                'selected' => true,
                'start_time' => '',
                'end_time' => ''
            ),
            array(
                'day' => 'Wednesday',
                'start' => [
                    '08:00',
                    '19:00'
                ],
                'end' => [
                    '09:00',
                    '20:00'
                ],
                'selected' => true,
                'start_time' => '',
                'end_time' => ''
            ),
            array(
                'day' => 'Thursday',
                'start' => [
                    '08:00',
                    '19:00'
                ],
                'end' => [
                    '09:00',
                    '20:00'
                ],
                'selected' => true,
                'start_time' => '',
                'end_time' => ''
            ),
            array(
                'day' => 'Friday',
                'start' => [
                    '08:00',
                    '19:00'
                ],
                'end' => [
                    '09:00',
                    '20:00'
                ],
                'selected' => true,
                'start_time' => '',
                'end_time' => ''
            ),
            array(
                'day' => 'Saturday',
                'start' => [
                    '08:00',
                    '19:00'
                ],
                'end' => [
                    '09:00',
                    '20:00'
                ],
                'selected' => false,
                'start_time' => '',
                'end_time' => ''
            
            ),
            array(
                'day' => 'Sunday',
                'start' => [
                    '08:00',
                    '19:00'
                ],
                'end' => [
                    '09:00',
                    '20:00'
                ],
                'selected' => false,
                'start_time' => '',
                'end_time' => ''
            )
        );

        $this->set('available', $available);
        $this->success();

    }

    public function save_specialist_available() {
        $token = get('token', '');
        if (!empty($token)) {
            $user = $this->AppToken->validateToken($token, true);
            if ($user === false) {
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

        $model = 'other_services';
        $available_days = json_decode(get('available_days', '[]'), true);

        if (empty($available_days)) {
            $this->message('Invalid available days.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataScheduleModel');
        $this->DataScheduleModel->updateAll(
            ['deleted' => 1], 
            ['injector_id' => $user["user_id"], 'model' => 'other_services']
        );
        foreach($available_days as $item){
            $arrSaveDay = array(
                'injector_id' => $user["user_id"],
                'days'        => $item['day'],
                'time_start'  => $item['start_time'],
                'time_end'    => $item['end_time'],
                'model'       => $model
            );
            $day_entity = $this->DataScheduleModel->newEntity($arrSaveDay);
            if(!$day_entity->hasErrors()) {
                $this->DataScheduleModel->save($day_entity);

                $this->loadModel('SpaLiveV1.DataUsersOtherServicesCheckIn');

                $check = $this->DataUsersOtherServicesCheckIn->find()->where(["DataUsersOtherServicesCheckIn.user_id" => $user["user_id"],
                "DataUsersOtherServicesCheckIn.deleted" => 0])->first();

                if(!empty($check)){
                    $check->status = "WLSHOME";

                    $update = $this->DataUsersOtherServicesCheckIn->save($check);

                    if($update){
                        $this->success();
                    }else{
                        $this->message('Status not updated.');
                        return;
                    }
                }else{
                    $this->message('User does not apply for Weight Loss Specialist.');
                    return;
                }

            }
        }

        //mandar correo al injector
        // $result = $this->sendWelcomeEmailWeightLossSpecialist($user,"WEIGHT LOSS");

        $this->success();

    }

    public function learn_about_program(){
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

        $this->loadModel('SpaLiveV1.CatLabels');
        $arr_texts = $this->CatLabels->find()->where(['CatLabels.deleted' => 0, 'CatLabels.tipo' => 'WLS_PROGRAM'])->first();

        $texts = json_decode($arr_texts->value);
        
        //$txt = 'What was sold to the patient.';
        //$txt2 = 'Questions you need to ask the patient during the check in.';
        $this->set('paragraph1', $texts->txt1);
        $this->set('paragraph2', $texts->txt2);
        
        $this->success();
        
    }

    public function get_patients_injector_pagination() {
        $this->loadModel('SpaLiveV1.DataPatientClinic');

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
        
        $page = get('page', 1);
        $limit = get('limit', 10);                
        $queryCount = "SELECT SysUsers.uid AS SysUsers__uid                  
                  FROM data_patient_clinic DataPatientClinic INNER JOIN sys_users SysUsers ON DataPatientClinic.user_id = SysUsers.id 
                  LEFT JOIN data_payment DataPayment ON DataPayment.id_from = SysUsers.id and DataPayment.type ='WEIGHT LOSS' and DataPayment.cancelled =0 
                  WHERE (SysUsers.deleted = 0 AND DataPatientClinic.injector_id = ".USER_ID." AND DataPatientClinic.deleted = 0 AND SysUsers.steps <> 'REGISTER' AND DataPayment.id IS NULL AND DataPatientClinic.type ='weightloss')";

        $ent_query = $this->DataPatientClinic->getConnection()->execute($queryCount)->fetchAll('assoc');
        
        $total = count($ent_query);        
        
            $offset = (($page - 1) * $limit);
        /* $query = "SELECT 
            SysUsers.uid        AS SysUsers__uid, 
            SysUsers.short_uid  AS SysUsers__short_uid, 
            SysUsers.name       AS SysUsers__name, 
            SysUsers.lname      AS SysUsers__lname,
            SysUsers.id         AS SysUsers__id, 
            DataPayment.id      AS DataPayment__id, 
            DataPayment.uid     AS DataPayment__uid, 
            (SELECT count(*) FROM data_patient_clinic  WHERE deleted =0 and type = 'weightloss' and user_id = SysUsers.id) AS invited, 
            (SELECT total FROM data_payment  WHERE data_payment.type ='WEIGHT LOSS COMMISSION' and data_payment.cancelled =0 and data_payment.uid = DataPayment.uid and data_payment.id_to = 1002 limit 1) AS PaymentCom 
                FROM data_patient_clinic DataPatientClinic 
                INNER JOIN sys_users SysUsers ON DataPatientClinic.user_id = SysUsers.id 
                LEFT JOIN data_payment DataPayment ON DataPayment.id_from = SysUsers.id and DataPayment.type ='WEIGHT LOSS' and DataPayment.cancelled =0 
                WHERE (SysUsers.deleted = 0 AND DataPatientClinic.injector_id = ".USER_ID." AND DataPatientClinic.deleted = 0 AND SysUsers.steps <> 'REGISTER' AND DataPayment.id IS NULL AND DataPatientClinic.type ='neurotoxin')
                limit {$limit} offset {$offset}"; */

        // I commend after code because instead of querying the DataPayment 
        // table it looks to see if the patient has a record in DataConsultationOS
        $query = "SELECT 
            SysUsers.uid        AS SysUsers__uid, 
            SysUsers.short_uid  AS SysUsers__short_uid, 
            SysUsers.name       AS SysUsers__name, 
            SysUsers.lname      AS SysUsers__lname,
            SysUsers.id         AS SysUsers__id,
            SysUsers.type       AS SysUsers__type,
            Consultation.id     AS Consultation__id, 
            Consultation.uid    AS Consultation__uid,
            DataReferred.id     AS DataReferred__id,
            DataReferred.referred_id     AS DataReferred__referred_id
            FROM data_patient_clinic DataPatientClinic
            INNER JOIN sys_users SysUsers ON DataPatientClinic.user_id = SysUsers.id 
            LEFT JOIN data_consultation_other_services Consultation ON Consultation.patient_id = SysUsers.id 
				and Consultation.service_uid ='1q2we3-r4t5y6-7ui8o990' 
				and Consultation.status NOT IN ('INIT', 'COMPLETED')
                and Consultation.deleted = 0
            LEFT JOIN data_referred_other_services DataReferred ON DataReferred.user_id = Consultation.patient_id 
            and DataPatientClinic.injector_id = DataReferred.referred_id
            and DataReferred.deleted = 0
            WHERE (SysUsers.deleted = 0 
                AND DataPatientClinic.injector_id = ".USER_ID." AND DataPatientClinic.deleted = 0 
                AND SysUsers.steps <> 'REGISTER'
                AND DataPatientClinic.show = 1)       
            group by SysUsers.id
            limit {$limit} offset {$offset}";

        $ent_users = $this->DataPatientClinic->getConnection()->execute($query)->fetchAll('assoc');
        
        $has_more = ($total > ($page * $limit));
        $has_less = ($page > 1);
        $result = array();

        if (!empty($ent_users)) {

            foreach ($ent_users as $patient) {
                $status = 'resend';
                $paid = false;
                $amount = 0;

                if (empty($patient['Consultation__id'])) {
                    $status = 'resend';
                } else {
                    if (isset($patient['DataReferred__id'])) {
                        if ($patient['DataReferred__referred_id'] == USER_ID) {
                            $status = 'accepted';

                            $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
                            $calls_patient = $this->DataOtherServicesCheckIn->find()
                            ->where([
                                'DataOtherServicesCheckIn.patient_id' => $patient['SysUsers__id'], 
                                'DataOtherServicesCheckIn.call_number' => 1,
                                'DataOtherServicesCheckIn.call_type' => 'CHECK IN',
                                'DataOtherServicesCheckIn.deleted' => 0
                            ])->first();

                            if (!empty($calls_patient)) {
                                $this->DataPatientClinic->updateAll(
                                    ['show' => 0], 
                                    ['user_id' => $patient['SysUsers__id'], 'injector_id' => USER_ID, 'type' => 'weightloss']
                                );
                            }
                        } else {
                            $status = 'not_chosen';

                            $this->DataPatientClinic->updateAll(
                                ['show' => 0, 'deleted' => 1],
                                ['user_id' => $patient['SysUsers__id'], 'injector_id' => USER_ID, 'type' => 'weightloss']
                            );
                        }
                    } else {
                        $status = 'pending';
                    }
                }
                
                if(isset($patient->DataPayment['id'])){
                    
                }else{
                    $paid = false;
                    $amount = 0;
                }

                if (USER_ID == $patient['SysUsers__id']) {
                    continue;
                }

                $t_array = array(
                    'id' => $patient['SysUsers__id'],
                    'uid' => $patient['SysUsers__uid'],
                    'short_uid' => $patient['SysUsers__short_uid'], 
                    'name' => $patient['SysUsers__name'] . ' ' . $patient['SysUsers__lname'],
                    'status' => $status,
                    'paid' => false,                    
                    'amount' => 0,
                    'refered' => $patient["DataReferred__referred_id"],
                    'id_refered' => $patient["DataReferred__id"],
                );

                $result[] = $t_array;
            }

        }

        
        $this->set('data', array(
            'total' => $total,
            'page'  => intval($page),
            'limit' => intval($limit),
            'patients'  => $result,
            'has_more' => $has_more,
            'has_less' => $has_less
        ));
        
        $this->success();
    

    }

    public function check_exist_wl() {

        $token = get('token','');
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

        $this->loadModel('SpaLiveV1.SysUsers');

        $email = get('email', '');
       
        if (empty($email)) {
            $this->message('Email address empty.');
            return;
        }

        $existUser = $this->SysUsers->find()->where(['SysUsers.email LIKE' => strtolower($email)])->first();

        if(!empty($existUser)) {
            if($existUser->type == 'patient'){
                if($existUser->deleted == 0){
                    $this->loadModel('SpaLiveV1.DataPatientClinic');
                    $existRelat = $this->DataPatientClinic->find()
                        ->where(['DataPatientClinic.injector_id' => USER_ID, 'DataPatientClinic.user_id' => $existUser->id ,'DataPatientClinic.type' => 'weightloss'])->first();
                    if(!empty($existRelat)){
                        $this->message('Is already your patient.');
                        $this->set('patient', 1);
                        //$this->success();
                        return;
                    }else{
                        $user_info = [
                            'user_uid' => $existUser->uid,
                            'user_name' => $existUser->name . ( empty($existUser->mname) ? '' : ' '.$existUser->mname ) . ' ' . $existUser->lname,
                            'user_email' => $existUser->email,
                            'user_phone' => $existUser->phone,
                        ];

                        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
                        $existConsult = $this->DataConsultationOtherServices->find()
                        ->where([
                            'DataConsultationOtherServices.patient_id' => $existUser->id, 
                            'DataConsultationOtherServices.deleted' => 0,
                            'DataConsultationOtherServices.status NOT IN' => ['COMPLETED', 'INIT'],
                        ])->first();

                        if(!empty($existConsult)){
                            // Exist a consultation in progress
                            $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
                            $call = $this->DataOtherServicesCheckIn->find()
                            ->where([
                                'DataOtherServicesCheckIn.patient_id' => $existUser->id, 
                                'DataOtherServicesCheckIn.call_type' => 'CHECK IN',
                            ])->first(); 
                            
                            if (!empty($call)) {
                                if ($call->support_id == USER_ID) {
                                    $this->message('Is already your patient.');
                                    $this->set('patient', 1);
                                    //$this->success();
                                    return;
                                } else {
                                    $this->message('This patient already has a weight loss treatment, wait for him to choose a specialist.');
                                    $this->set('patient', 1);
                                    //$this->success();
                                    return;
                                }
                            }

                            $this->message('This patient already has weight loss treatment.');
                            $this->set('patient', 1);
                            //$this->success();
                            return;
                        }

                        $this->set('user_info', $user_info);
                        $this->set('patient', 2);
                        $this->success();
                        return;
                    }
                } else{
                    $this->loadModel('SpaLiveV1.DataSubscriptionMethodPayments');
                    $entMethod = $this->DataSubscriptionMethodPayments->find()->where(['DataSubscriptionMethodPayments.user_id' => $existUser->id])->first();
                    if(!empty($entMethod)){
                        $this->message('The patient is not active.');
                        $this->set('patient', 1);
                        //$this->success();
                        return;
                    }
                }
            } else if($existUser->type == 'injector'){
                if($existUser->id == USER_ID){
                    $user_info = [
                        'user_uid' => $existUser->uid,
                        'user_name' => $existUser->name . ( empty($existUser->mname) ? '' : ' '.$existUser->mname ) . ' ' . $existUser->lname,
                        'user_email' => $existUser->email,
                        'user_phone' => $existUser->phone,
                    ];

                    $this->set('user_info', $user_info);
                    $this->set('patient', 2);
                    $this->success();
                    return;
                }else{
                    $this->message('Email address already exists but it is not a patient.');
                    $this->set('patient', 1);
                    //$this->success();
                    return;
                }
            }else{
                $this->message('Email address already exists but it is not a patient.');
                $this->set('patient', 1);
                //$this->success();
                return;

            }
            
        }
        $this->set('patient', 3);
        $this->success();
        return;
    }

    public function injector_invite_patient_weight_loss(){
        
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

        $email = get('email', '');
        if(empty($email)){
            $this->message('Email address empty.');
            return;
        }        
        $this->loadModel("SpaLiveV1.SysUsers");
        $this->loadModel('SpaLiveV1.DataPatientClinic');

        $existUser = $this->SysUsers->find()->where(['SysUsers.email LIKE' => strtolower($email)])->first();
        $Main = new MainController();
        if(empty($existUser)) {
            $name = $email;
            $shd = false;
            do {

                $num = substr(str_shuffle("0123456789"), 0, 4);
                $short_uid = $num . "" . strtoupper($Main->generateRandomString(4));

                $existUser_uid = $this->SysUsers->find()->where(['SysUsers.short_uid LIKE' => $short_uid])->first();
            if(empty($existUser_uid))
                $shd = true;

            } while (!$shd);

            $_file_id = 93;
            $email = get('email', '');

            $uuuid = Text::uuid();
            $array_save = array(
                'uid' => $uuuid,
                'short_uid' => $short_uid,
                'name' => '',
                'mname' => '',
                'lname' => '',
                'bname' => '',
                'description' => '',
                'zip' => 0,
                'ein' => '',
                'email' => trim($email),
                'password' => '',
                'type' => 'patient',
                'state' => 43,
                'phone' => '',
                'street' => '',
                'suite' => '',
                'city' => '',
                'dob' => '1070-01-01',
                'gender' => 'Other',
                'active' => 1,
                'login_status' => 'READY',
                'amount' => 0,
                'deleted' => 0,
                'createdby' => USER_ID,
                'modifiedby' => 0,
                'photo_id' => $_file_id,
                'radius' => 30,
                'score' => 0,
                'enable_notifications' => 1,
                'last_status_change' => date('Y-m-d H:i:s'),
                'steps' => 'REGISTER'
            );

            $userEntity = $this->SysUsers->newEntity($array_save);

            if(!$userEntity->hasErrors()){
                $ent_saved1 = $this->SysUsers->save($userEntity);
                //add patien to neurotoxin list pacients
                $arrLinkPat = [
                    'uid' => $this->DataPatientClinic->new_uid(),
                    'injector_id' => USER_ID, 
                    'user_id' => $ent_saved1->id,
                ];
                
                $entLink = $this->DataPatientClinic->newEntity($arrLinkPat);
                if(!$entLink->hasErrors()){
                    $this->DataPatientClinic->save($entLink);
                    $this->success();
                }

                //add patien to weight list 
                $arrLinkPat = [
                    'uid' => $this->DataPatientClinic->new_uid(),
                    'injector_id' => USER_ID, 
                    'user_id' => $ent_saved1->id,
                    'type' => 'weightloss',
                ];
                
                $entLink = $this->DataPatientClinic->newEntity($arrLinkPat);
                if(!$entLink->hasErrors()){
                    $this->DataPatientClinic->save($entLink);
                    $this->success();
                }

                
            }else{
                $this->message("An error occured.");
                return;
            }
        }else{

            if($existUser->id==$user["user_id"]){
                $this->message("You can't invite yourself.");
                return;
            }

            $name = $existUser->name .  ' ' . $existUser->lname . ' ' . $existUser->lname;
            $existRelat = $this->DataPatientClinic->find()
                ->where(['DataPatientClinic.injector_id' => USER_ID, 'DataPatientClinic.user_id' => $existUser->id ,'DataPatientClinic.type' => 'neurotoxin'])->first();
            if(empty($existRelat)){
                //add patien to neurotoxin list pacients
                $arrLinkPat = [
                    'uid' => $this->DataPatientClinic->new_uid(),
                    'injector_id' => USER_ID, 
                    'user_id' => $existUser->id,
                ];
                
                $entLink = $this->DataPatientClinic->newEntity($arrLinkPat);
                if(!$entLink->hasErrors()){
                    $this->DataPatientClinic->save($entLink);
                    $this->success();
                }
            }
            $existRelat = $this->DataPatientClinic->find()
                ->where(['DataPatientClinic.injector_id' => USER_ID, 'DataPatientClinic.user_id' => $existUser->id ,'DataPatientClinic.type' => 'weightloss'])->first();
            if(empty($existRelat)){
                //add patien to weightloss list pacients
                $arrLinkPat = [
                    'uid' => $this->DataPatientClinic->new_uid(),
                    'injector_id' => USER_ID, 
                    'user_id' => $existUser->id,
                    'type' => 'weightloss',
                ];
                
                $entLink = $this->DataPatientClinic->newEntity($arrLinkPat);
                if(!$entLink->hasErrors()){
                    $this->DataPatientClinic->save($entLink);
                    $this->success();
                }
            }
        }
        

        $this->loadModel("SpaLiveV1.SysUsers");
                //$patient = $this->SysUsers->find()->where(['SysUsers.id' => $arr_usr[$i]])->first();                                
                
        $msg_mail = "<p>Hey </p> " . $name ." </br>".
        "<p>It's ".$user['name'] ." ".  $user['mname'] ." ". $user['lname'] ." here! I'm excited to introduce you to MySpaLive's exclusive Semaglutide Weight Loss Program.</p></br>".
        "<p>The 3-month program is designed to help you embark on a journey towards a healthier, more confident you. With the aid of semaglutide, a cutting-edge weight loss medication, you'll find shedding those extra pounds a breeze.</p></br>".
        "<p>Here's the scoop:</p></br>".
        "❤<b>Simple Self-Administered Injections:</b> No need for appointments or waiting rooms. ❤<b>Online Consultations:</b> Get approved for the medication through a hassle-free telehealth meeting. ❤<b>Personalized Plans:</b> Benefit from custom meal and workout plans tailored to your needs. ❤<b>Regular Check-ins:</b> Stay motivated and on track with weekly support from the MySpaLive team. ❤<b>A Special Welcome Gift:</b> Kickstart your journey with a chic MySpaLive water bottle.🎁</br>".
        "<p>💸Choose your payment plan:</p></br>".
        "</br><ul>".
        "<li>Month to Month: $1795 (just $598.30/month)</li>".
        "<li>Pay Upfront: $1595 (save $200!)</li>".
        "</ul></br>".
        "<p>Tap the link below to download the MySpaLive app and take the first step towards embracing your best self:</p></br>".
        "<p>🔗<a href='https://app.myspalive.com/redirect' link='' style='color:#60537A;text-decoration:underline'><strong>https://app.myspalive.com/redirect</strong></a></p></br>".
        "<p>Please know that I'm here to support you every step of the way. Feel free to reach out with any questions or to share your progress - I'd love to hear from you!</p></br>".
        "<p>Here's to a healthier, happier you!</p></br>".
        "<p>-".$user['name'] ." ".  $user['mname'] ." ". $user['lname'] ."</p>";                                                             

        $html_content = '<img src="' . $this->URL_PANEL . 'img/logo.png" width="100px"/>' . $msg_mail;
        
        $data=array(
            'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
            'to'      => $email,                        
            'subject' => 'Discover Your Best Self: Exclusive Weight Loss Program Just for You!🌟',
            'html'    =>  $Main->getEmailFormat($html_content) ,
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


        $this->success();
            
    
    }
    public function get_date_time_server() {

        $token = get('token','');
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
        
        $this->set('date', date('m-d-Y'));
        $this->set('time', date('H:i'));        
        $this->success();
        return;
    }

    protected function send_Android($array_device, $android_access_key, $str_message, $data){
        $notification = array();
        $notification['message'] = $str_message;

        if (!empty($data)) {
            $notification = array_merge($notification, $data);
        }

        foreach ($array_device as /*$reg*/ $Device) {
            //$Device = $reg['ApiDevice'];
            $token = trim($Device);

            $fields = array(
                'to' => $token,
                'data' => $notification
            );
            $this->log(__LINE__ . ' ' . json_encode($fields));
            $url = 'https://fcm.googleapis.com/fcm/send';
            $is_dev = env('IS_DEV', false);
            if($is_dev)                //dev key
                $apiKey='AAAAPgwq6kA:APA91bEkir4A1QLLEE0Uj8tFyUfTGQAP9rR1ZkoJ6zEqpNhcpQI7ql_EwYaEyZNiUnXor3pqea2_NBsjqV3l4B6DpgTGWK5xGfApLNNuq8oBVUhwsoN46BmGqvUlph4J5mz9QKd1_vQl';
            else                //production key
                $apiKey='AAAAA9BBleM:APA91bHVd7eNugYLQjsjAamO7NSnPPc9y8AHsi1j9jRi0ApdVmA8DN27XsObPkezG3akcotIFg0x_fpWnJ-zcTa11s5IdqnMG9NZt3NLStZVmXgSFOYsoRr3QDqDGx5Jz1VjuyEQ34ZB';
            
            //$firebase_api = Configure::read('API_CONFIG.android_access_key');

            $headers = array(
                'Authorization: key=' . $android_access_key,
                'Content-Type: application/json'
                //'Authorization:key='.$apiKey                
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
            $result = curl_exec($ch);
            // if($result === FALSE){
            //     die('Curl failed: ' . curl_error($ch));
            // }
            //echo "::{$result}::";

            curl_close($ch);
        }
    }

    private function send_iOS($array_device, $str_message, $data){
        if(empty($array_device)){
            return false;
        }

       pr('array_device');
       pr($array_device);
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

            $token = trim($Device);
           
            
            $device_token = trim($token);
            $pem_file       = PATH_IOS_CERT;
            $pem_secret     = 'c0ntr01';//Configure::read('API_CONFIG.ios_passphrase');
            $apns_topic     = 'com.advante.SpaLiveMD';

            $url = "https://api.push.apple.com/3/device/$device_token";

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array("apns-topic: $apns_topic"));
            curl_setopt($ch, CURLOPT_SSLCERT, $pem_file);
            curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $pem_secret);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER,true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1);
            curl_setopt($ch, CURLOPT_NOSIGNAL, 1);

            $result = curl_exec($ch);

            if (curl_errno($ch)) {
                $error_msg = curl_error($ch);
                pr($error_msg);
            }

            curl_close($ch);

        }

    }
    public function message_got_new_patient($user_send){
        if(empty($user_send) || $user_send == 0){
            $this->message('invalid user.');
            $this->set('session', false);
            return;
        }
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

        $this->loadModel('SpaLiveV1.ApiApplications');
        $array_application = $this->ApiApplications->find()->where(['id' => 1])->first();
         $this->log(__LINE__ . ' ' . json_encode( $array_application));

        $str_query = " SELECT Devices.token, Devices.device FROM api_devices Devices WHERE Devices.user_id =".$user_send." LIMIT 1        ";
        $find = $this->ApiApplications->getConnection()->execute($str_query)->fetchAll('assoc');
        $this->log(__LINE__ . ' ' . json_encode( $find));
        $data = [];
        $str_msg = 'There is a new weight loss patient assigned to you.';
        
        $config = $array_application;//json_decode($array_application, true);
        $array_config = json_decode($array_application->json_config, true);

        $deviceAndroid = [];
        $deviceIOS = [];

         $this->log(__LINE__ . ' ' . APP_NAME);
         $this->log(__LINE__ . ' ' . $config['appname']);
        if (!defined('APP_NAME')) define('APP_NAME', $config['appname']);
        define('PATH_IOS_CERT', APP . 'Certificates' . DS . APP_NAME . '.pem');


        //$array_save['message'] = $str_msg;
        //$array_save['id_to'] = $item['patient_id'];
        //$c_entity = $this->DataMessages->newEntity($array_save);
        //if(!$c_entity->hasErrors()) 
        //    $this->DataMessages->save($c_entity);

        $this->loadModel('SpaLiveV1.DataNotification');
        if (!defined('USER_ID')) define('USER_ID', 0);
        $arrSave = array(
            'type' => 'NOTIFICATION',
            'message' => $str_msg,
            'json_users' => json_encode($user_send),
            'json_data' => json_encode($data),
            'user_id' => USER_ID,
        );
        $this->log(__LINE__ . ' ' . json_encode($arrSave));
        $ent_noti = $this->DataNotification->newEntity($arrSave);
        if(!$ent_noti->hasErrors()){
            $this->DataNotification->save($ent_noti);
            $this->log(__LINE__ . ' ' );
            foreach($find as $item){
                $this->log(__LINE__ . ' '. json_encode($item));
//                {"CONCAT_WS('||', Devices.token, Devices.device)":"eAehXCtgS2a_X76OHETUck:APA91bHrCHZPGUqQ-5S2RIMUG5LJtyaM8yJlQBY7fhX_ni6hnvrvIJXLMtyuv7bVNfva2wlnzD8tTTYPlYUg8U4UmGuDhQcFMTAgTmDIvirNL9Mp7GFsSCkztDaMJpt7TkOnPR8-_E0Z||ANDROID"}
                if(isset($item['token']) && !empty($item['token'])) {
                    //$arrVals = explode('||', $item['pat_device']);
                    if($item['device'] == "ANDROID"){
                        if(isset($array_config['android_access_key']) && !empty($array_config['android_access_key'])){
                             $this->log(__LINE__ . ' ' . json_encode('send android'));
                            $this->send_Android(array($item['token']), $array_config['android_access_key'], $str_msg, $data);
                        }
                    }else{
                        if(file_exists(PATH_IOS_CERT)){
                            $this->log(__LINE__ . ' ' . json_encode('send ios'));
                            $this->send_iOS(array($item['token']), $str_msg, $data);
                        }
                    }
                }
            }
        }        
    }
    
    public function send_private_message() {

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

        $checkin_id = get('checkin_id', '');
        if(empty($checkin_id)){
            $this->message('Checkin id empty.');
            return;
        }
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $CheckIn = $this->DataOtherServicesCheckIn->find()->where(['DataOtherServicesCheckIn.id' => $checkin_id])->first();

        if(empty($CheckIn)){
            $this->message('Id not found');
            return;
        }


        $str_message = get('message','');
        if (empty($str_message)) {
            $this->message('message empty.');
            return;   
        }

        $str_type = strtoupper(get('type','WEIGHTLOSS'));
        if (empty($str_type)) {
            $this->message('type empty.');
            return;   
        }

        if ($str_type != "WEIGHTLOSS") {
            $this->message('invalid type.');
            return;
        }

        $Main = new MainController();
        $id_from = USER_ID;
        if (USER_TYPE == "patient") {
            $id_to = $CheckIn->support_id;
        } else{
            $id_to = $CheckIn->patient_id;
        }

        $array_save = array(
            'type' => $str_type,
            'id_from' => intval($id_from),
            'id_to' => intval($id_to),
            'message' => $str_message,
            'extra' => trim($CheckIn->uid),
            'deleted' => 0,
            'readed' => 0,
            'notification_type' => 'PUSH',
            'detail_notify' => 'WEIGHT_LOSS_MESSAGE',
            'created' => date('Y-m-d H:i:s'),
        );
        // pr($array_save); exit;

        $this->loadModel('SpaLiveV1.DataMessages');
        $c_entity = $this->DataMessages->newEntity($array_save);

        if(!$c_entity->hasErrors()) {
            if ($this->DataMessages->save($c_entity)) {
                $this->success();

                $Main->notify_devices('You have recieved a message from ' . $user['name'],array($id_to),true,false,false);
            }
        }

    }

    public function get_messages() {

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
        $checkin_id = get('checkin_id', '');
        if(empty($checkin_id)){
            $this->message('Checkin id empty.');
            return;
        }
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $CheckIn = $this->DataOtherServicesCheckIn->find()->where(['DataOtherServicesCheckIn.id' => $checkin_id])->first();
        
        $this->loadModel('SpaLiveV1.DataMessages');
        $find = $this->DataMessages->find()->select(['DataMessages.message','DataMessages.extra','DataMessages.created', 'User.uid','User.short_uid','User.name','User.lname','DataMessages.type', 'DataMessages.readed'])
        ->join([
            'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataMessages.id_from']
        ])->where(["DataMessages.deleted" => 0,'DataMessages.id_to' => USER_ID, 'DataMessages.type' => 'WEIGHTLOSS', 'DataMessages.extra' => $CheckIn->uid])->order(['DataMessages.id' => 'DESC'])->all();

        $c_count = $this->DataMessages->find()->select(['DataMessages.id'])
          ->where(["DataMessages.deleted" => 0,'DataMessages.id_to' => USER_ID, 'DataMessages.type' => 'WEIGHTLOSS', 'DataMessages.extra' => $CheckIn->uid,'DataMessages.readed' => 0 ])->count();

        $arr_treatments = array();
        if (!empty($find)) {
            foreach ($find as $row) {
                $arr_treatments[] = array(
                    'type' => $row['type'],
                    'message' => $row['message'],
                    'read' => $row['readed'],
                    'extra' => $row['extra'],
                    'created' => $row['created']->i18nFormat('yyyy-MM-dd HH:mm'),
                    'from' => !empty($row['User']['name']) ? $row['User']['name'] . ' ' . $row['User']['lname'] : 'MySpaLive',
                    'from_short_uid' => !empty($row['User']['short_uid']) ? $row['User']['short_uid'] : '',
                    'from_uid' =>  !empty($row['User']['uid']) ? $row['User']['uid'] : '',
                );
            }
        }



        $str_quer = "UPDATE data_messages SET `readed` = 1 WHERE id_to = " . USER_ID . " AND type = 'WEIGHTLOSS' AND extra  =".  $CheckIn->uid;

        $this->DataMessages->getConnection()->execute($str_quer);

        $this->success();
        $this->set('data', $arr_treatments);

    }

    public function cancel_join_call() {

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
        

        $call_id = get('call_id', '');
        if(empty($call_id)){
            $this->message('Call id empty.');
            return;
        }


        $this->loadModel('SpaLiveV1.DataOtherServiceCallCancel');
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $CheckIn = $this->DataOtherServicesCheckIn->find()->where(['DataOtherServicesCheckIn.id' => $call_id])->first();        
        if(empty($CheckIn)){
            $this->message('Call id not found.');
            return;
        }
        if(empty($CheckIn->call_time) || is_null($CheckIn->call_time)){
            $call_time = "00:00:01";
        }else{
            $call_time = $CheckIn->call_time;
        }
            
        $arr_save_q = array(
            'uid' => Text::uuid(),
            'call_id' => $call_id,
            'support_id' => $CheckIn->support_id,
            'call_date' => $CheckIn->call_date,
            'call_time' => $call_time,
            'call_number' => $CheckIn->call_number,
            'deleted' => 0,
        );

        $cq_entity = $this->DataOtherServiceCallCancel->newEntity($arr_save_q);
        if(!$cq_entity->hasErrors()){
            $this->DataOtherServiceCallCancel->save($cq_entity);
            $this->success();    
        }else{
            $this->message('There was an error saving the information.');
        }                        

    }

    public function pay_examiner_fifty_weightloss_commission() {

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
        

        $checkin_id = get('call_id', '');
        if(empty($checkin_id)){
            $this->message('Call id empty.');
            return;
        }
        
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.DataPayment');
        $CheckIn = $this->DataOtherServicesCheckIn->find()->where(['DataOtherServicesCheckIn.id' => $checkin_id])->first();        
        if(empty($CheckIn)){
            $this->message('Call id not found.');
            return;
        }               
            
        $_fields = ['DataConsultationOtherServices.payment_intent','Service.title','DataConsultationOtherServices.patient_id','DataConsultationOtherServices.service_uid'];
        $_join = [
            'Service' => ['table' => 'cat_other_services','type' => 'INNER','conditions' => 'Service.uid = DataConsultationOtherServices.service_uid'],
        ];
        $ent_consultation = $this->DataConsultationOtherServices->find()->select($_fields)->where(
            ['DataConsultationOtherServices.uid' => $CheckIn->consultation_uid])->join($_join)->last();

        $ent_payment = $this->DataPayment->find()->where(
            ['DataPayment.id_from' => $ent_consultation["patient_id"], 'DataPayment.type' => 'WEIGHT LOSS',
            'DataPayment.intent' => $ent_consultation->payment_intent
        ])->last();        
        
        if (!empty($ent_payment)) {
            $Main = new MainController();
            $Main->createPaymentCommissionRegister($CheckIn->call_type . " COMMISSION",isset($ent_consultation["patient_id"])?$ent_consultation["patient_id"]:0,USER_ID,$ent_payment->uid,$ent_payment->intent,$ent_payment->payment,$ent_payment->receipt,5000, '', $ent_payment->payment_platform);
        }
    }

    
    public function test_cancel_count() {
        $res = $this->cancel_call_count('65174fa0f2c486.66660407' ,1746);

        $this->set('canceled', $res);
        return;
    }

    public function cancel_call_count($token, $call_id) {
        //$token = get('token', '');
        
        //if(!empty($token)){
        //    $user = $this->AppToken->validateToken($token, true);
        //    if($user === false){
        //        $this->message('Invalid token.');
        //        $this->set('session', false);
        //        return;
        //    }
        //    $this->set('session', true);
        //} else {
        //    $this->message('Invalid token.');
        //    $this->set('session', false);
        //    return;
        //}
        //
//
        // $call_id = get('call_id', '');
        // if(empty($call_id)){
        //    $this->message('Call id empty.');
        //    return;
        // }
        $this->loadModel('SpaLiveV1.DataOtherServiceCallCancel');
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $CheckIn = $this->DataOtherServicesCheckIn->find()->where(['DataOtherServicesCheckIn.id' => $call_id])->first();        
        if(empty($CheckIn)){
            $this->message('Call id not found.');
            return;
        }

        $services = $this->DataOtherServiceCallCancel
        ->find()
        ->where([
            'DataOtherServiceCallCancel.deleted' => 0 ,
            'DataOtherServiceCallCancel.call_id' => $call_id, 
            'DataOtherServiceCallCancel.support_id' => $CheckIn->support_id
            ])
        ->count();                
        
        $this->set("cancel_call_amounts",$services);
        $this->success();

        return $services;
        

    }

    public function weight_loss_specialist_calendar(){
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
        
        $year = get('year','');
        if (empty($year)) {
            $this->message('year not found.');
            return;
        }
        
        $month = get('month','');
        if (empty($month)) {
            $this->message('month not found.');
            return;
        }

        $call_id = get('call_id', '');
        if(empty($call_id)){
            $this->message('Call id empty.');
            return;
        }       
        
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');        
        $CheckIn = $this->DataOtherServicesCheckIn->find()->where(['DataOtherServicesCheckIn.id' => $call_id])->first();        
        if(empty($CheckIn)){
            $this->message('Call id not found.');
            return;
        }
        $user_id =  $CheckIn->support_id;        
        if (empty($user_id)) {
            $this->message('user_id not found.');
            return;
        }

        //$user_id = USER_ID;
        //formato Y m d
        $checkInDate = get('checkInDate','');
        if (empty($checkInDate)) {
            $this->message('checkInDate not found.');
            return;
        }
        $examiner_id = get('examiner_id','');
        if (!empty($examiner_id)) {            
            $user_id =  $examiner_id;
        }
        $this->loadModel('SpaLiveV1.DataScheduleModel');
        $this->loadModel('SpaLiveV1.DataScheduleDaysOff');
        // fechas que trabaja examiner
        $work_days = $this->DataScheduleModel->find()->select()
        //->join(['do' => ['table' => 'data_schedule_days_off','type' => 'LEFT','conditions' => 'DataScheduleModel.injector_id = do.user_id and do.deleted =0'],])
        ->where(['DataScheduleModel.injector_id' => $user_id, 'DataScheduleModel.deleted' => 0 ,'DataScheduleModel.model' => 'other_services'])->toList();        
        $days_str =[];
        $days_hours =[];
        
        for ($i=0; $i < count($work_days) ; $i++) {              
            $days_str[] = $work_days[$i]->days;
            $days_hours[$work_days[$i]->days] = array('time_start'=>$work_days[$i]->time_start, 'time_end'=>$work_days[$i]->time_end);            
        }
        
        //day off
        $days_off_arr =  $this->DataScheduleDaysOff->find()->select()        
        ->where(['DataScheduleDaysOff.user_id' => $user_id, 'DataScheduleDaysOff.deleted' => 0 ])->all();                

        //posibles fechas a agendar
        //fecha - 1
        $fecha_objeto1 = date_create($checkInDate);
        $fecha_objeto1->modify('-1 day');        
        $fecha_modificada1 = $fecha_objeto1->format('Y-m-d');        
        $diaMenosUno = date('l', strtotime($fecha_modificada1));        
        
        // comprobar que el dia seleccionado trabaja
        $continue = true;
        $total_days = count($days_str);
        $j=0;         
        while($continue ){
            
            $j++;
            if($j > $total_days)
                $continue = false;
            $fecha_modificada1 = $fecha_objeto1->format('Y-m-d');
            $diaMenosUno = date('l', strtotime($fecha_modificada1));
            $fecha_objeto1->modify('-1 day');
            $exists = (in_array(strtoupper($diaMenosUno), $days_str));
            if($exists)
                $continue = false;
        }        
        $this->set( 'chekindate-1', $fecha_modificada1 );     
        
        //fecha
        $this->set( 'chekindate', $checkInDate );
        
        //fecha + 1
        $fecha_objeto2 = date_create($checkInDate);
        $fecha_objeto2->modify('+1 day');        
        $fecha_modificada2 = $fecha_objeto2->format('Y-m-d');
        $diaMasUno = date('l', strtotime($fecha_modificada2)); 
        //var to not move the day   
        $diaMasUnoMoved = false;    
        $continue = true;
        $total_days = count($days_str);
        $j=0;         
        while($continue ){            
            $j++;
            if($j > $total_days)
                $continue = false;            
            $fecha_modificada2 = $fecha_objeto2->format('Y-m-d');
            $diaMasUno = date('l', strtotime($fecha_modificada2));
            $fecha_objeto2->modify('+1 day');

            
            $exists = (in_array(strtoupper($diaMasUno), $days_str));            
            if($exists){
                $continue = false;
            } else {
                //change to true to block the function
                $diaMasUnoMoved = true;
            }
        }        
        
        $this->set( 'chekindate+1', $fecha_modificada2 );
        
        //fecha + 2 requiem
        $fecha_objeto3 = date_create($fecha_modificada2);
        $fecha_objeto3->modify('+1 day');
        $fecha_modificada3 = $fecha_objeto3->format('Y-m-d');
        $diaMasDos = date('l', strtotime($fecha_modificada3));
        //var to not move the day   
        $diaMasDosMoved = false;            
        $continue = true;
        $total_days = count($days_str);
        $j=0;         
        while($continue ){            
            $j++;
            if($j > $total_days)
                $continue = false;            
            
            $fecha_modificada3 = $fecha_objeto3->format('Y-m-d');
            $diaMasDos = date('l', strtotime($fecha_modificada3));
            $fecha_objeto3->modify('+1 day');            
            $exists = (in_array(strtoupper($diaMasDos), $days_str));            
            if($exists){
                $continue = false;
            } else {
                //change to true to block the function
                $diaMasDosMoved = true;
                //$this->set('jdiaMasDosMoved', $diaMasDosMoved);
                //return;
                //exit;
            }
        }
        $this->set( 'chekindate+2', $fecha_modificada3 );

        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');        
        $totalDays = cal_days_in_month(CAL_GREGORIAN, intval($month), intval($year));
        // Arreglo para almacenar todos los días del mes
        $jsonArray = array();        
        $now = date('Y-m-d H:i:s');        
        $hr_server = date('H:i', strtotime($now));
        $hr_server_nd = strtotime($hr_server);
        $date_server = date('Y-m-d', strtotime($now));
        // Recorrer todos los días del mes
        for ($day = 1; $day <= $totalDays; $day++) {
            // Formatear el día y el mes con dos dígitos (por ejemplo, 01, 02, ..., 31)
            $formattedDay = str_pad(strval($day), 2, "0", STR_PAD_LEFT);//01
            $formattedMonth = str_pad($month, 2, "0", STR_PAD_LEFT);//09
            // Fecha completa en formato "YYYY-MM-DD"
            $date = "$year-$formattedMonth-$formattedDay";//2023-09-01
            $diaSemana = date('l', strtotime($date));//Friday            
            $gage_schedule = $this->DataScheduleDaysOff->find()->select()->where(['DataScheduleDaysOff.user_id' => $user_id,'DataScheduleDaysOff.date_off' => $date, 'DataScheduleDaysOff.deleted' => 0])->first();
                          
            //checa si chambea            
            if(empty($gage_schedule) && isset($days_hours[strtoupper($diaSemana)])){
                //obtner horas                
                $horaCadena = $days_hours[strtoupper($diaSemana)]['time_start'].":00:00";                
                // Convertir la cadena a objeto DateTime
                $horaCadena = strtotime($horaCadena);                
                $hora = strtotime(date("H:i", $horaCadena));                                
                $horaCadenaFinal = $days_hours[strtoupper($diaSemana)]['time_end'].":00:00";

                // Convertir la cadena a objeto DateTime
                $horaCadenaFinal = strtotime($horaCadenaFinal);                
                $horaFinal = strtotime(date("H:i", $horaCadenaFinal));

                $hours = array(); // Array para almacenar las iteraciones                
                while ($hora < $horaFinal) {
                    //iterar hasta hour
                    $hours[] = date('H:i:s', $hora); // Agregar al array
                    $hora = $hora + (20 * 60);                                        
                }                                
                //desbloquear dias disponibles
                if( 
                    //date_create($fecha_modificada1) == date_create("$year-$month-$day") ||
                    //diaMasUno is to block the days if the date was moved
                    ((date_create($fecha_modificada2) == date_create("$year-$month-$day")) && !$diaMasUnoMoved)  ||
                    ((date_create($fecha_modificada3) == date_create("$year-$month-$day")) && (!$diaMasUnoMoved && !$diaMasDosMoved)) ||
                    date_create($checkInDate) == date_create("$year-$month-$day")
                ){
                    //comprobar que el dia anterior no se pueda seleccionar si estamos en el dia de la cita
                    //if( date_create($fecha_modificada1) == date_create("$year-$month-$day") ){
                    //     Inicializar el arreglo JSON
                    //    $dayJson = array(
                    //        "date" => "$date",
                    //        "appointments" => 0,
                    //        "dayoff" => true,
                    //        "data" => array(),
                    //    );
                    //}
                    // Inicializar el arreglo JSON
                    $dayJson = array(
                        "date" => "$date",
                        "appointments" => 0,
                        "dayoff" => false,
                        "data" => array(),
                    );                    
                    $this->set("diaMasDosMoved", $diaMasDosMoved);
                    $this->set("diaMasUnoMoved", $diaMasUnoMoved);
                    // Recorrer el arreglo de horas y agregar los datos correspondientes al arreglo JSON
                    foreach ($hours as $index => $time) {
                        //$timeValue = $values[$index]; // Obtener el valor correspondiente de $values
                        //validating the day and time is not in the past.
                        $time_nd = strtotime($time);
                        if($date_server == $date && $hr_server_nd > $time_nd)
                        {
                            $dayJson["data"][] = array(
                                "time" => date("g:i A", strtotime($time)),
                                "timeValue" => $time, // Agregar el valor de timeValue al arreglo JSON
                                "data" => array(
                                    "status" => "ocuped",
                                    "name" => "",
                                    "provider" => "",
                                    "date" => ""
                                )
                            );
                        }else{
                                                                            
                            $fecha = date("$year-$month-$day");
                            $checkin = $this->DataOtherServicesCheckIn->find()->select()->where(
                                [
                                    'DataOtherServicesCheckIn.call_date' => $fecha,
                                    'DataOtherServicesCheckIn.call_time' => $time,
                                    'DataOtherServicesCheckIn.deleted' => 0,
                                    'DataOtherServicesCheckIn.support_id' => $user_id,
                                ]
                            )->first();                            
                            // Verificar si el checkin está vacío
                            if (empty($checkin)) {
                                $dayJson["data"][] = array(
                                    "time" => date("g:i A", strtotime($time)),
                                    "timeValue" => $time, // Agregar el valor de timeValue al arreglo JSON
                                    "data" => array(
                                        "status" => "free",
                                        "name" => "",
                                        "provider" => "",
                                        "date" => ""
                                    )
                                );
                            } else {
                                $dayJson["data"][] = array(
                                    "time" => date("g:i A", strtotime($time)),
                                    "timeValue" => $time, // Agregar el valor de timeValue al arreglo JSON
                                    "data" => array(
                                        "status" => "ocuped",
                                        "name" => "",
                                        "provider" => "",
                                        "date" => ""
                                    )
                                );
                            }
                        }
                    }
                } //elseif que comprueba si es el dia de hoy
                elseif ( date_create($fecha_modificada1) == date_create("$year-$month-$day") ) {
                    $fechaHoy = date('Y-m-d');
                    if ($fecha_objeto1 < date_create($fechaHoy)) {
                        $dayJson = array(
                            "date" => "$date",
                            "appointments" => 0,
                            "dayoff" => true,
                            "data" => array(),
                        );
                    } else {
                        $dayJson = array(
                            "date" => "$date",
                            "appointments" => 0,
                            "dayoff" => false,
                            "data" => array(),
                            //"fecha_objeto1" => "$fecha_objeto1",
                            "fechaHoy" => "$fechaHoy",
                        );                            
                        // Recorrer el arreglo de horas y agregar los datos correspondientes al arreglo JSON
                        foreach ($hours as $index => $time) {
                            //$timeValue = $values[$index]; // Obtener el valor correspondiente de $values
                        
                            $fecha = date("$year-$month-$day");
                            $checkin = $this->DataOtherServicesCheckIn->find()->select()->where(
                                [
                                    'DataOtherServicesCheckIn.call_date' => $fecha,
                                    'DataOtherServicesCheckIn.call_time' => $time,
                                    'DataOtherServicesCheckIn.deleted' => 0,
                                    'DataOtherServicesCheckIn.support_id' => $user_id,
                                ]
                            )->first();
                                
                            // Verificar si el checkin está vacío
                            if (empty($checkin)) {
                                $dayJson["data"][] = array(
                                    "time" => date("g:i A", strtotime($time)),
                                    "timeValue" => $time, // Agregar el valor de timeValue al arreglo JSON
                                    "data" => array(
                                        "status" => "free",
                                        "name" => "",
                                        "provider" => "",
                                        "date" => ""
                                    )
                                );
                            } else {
                                $dayJson["data"][] = array(
                                    "time" => date("g:i A", strtotime($time)),
                                    "timeValue" => $time, // Agregar el valor de timeValue al arreglo JSON
                                    "data" => array(
                                        "status" => "ocuped",
                                        "name" => "",
                                        "provider" => "",
                                        "date" => ""
                                    )
                                );
                            }
                        }
                    }
                }
                else {
                    // Inicializar el arreglo JSON
                    $dayJson = array(
                        "date" => "$date",
                        "appointments" => 0,
                        "dayoff" => true,
                        "data" => array(),
                    );
                }
            } else {                
                // Estructura JSON para cada día
                $dayJson = array(
                    "date" => $date,
                    "appointments" => 0,
                    "data" => array(),
                    "dayoff" => true
                );
            }                                
            // Agregar la estructura JSON del día al arreglo principal
            $jsonArray[] = $dayJson;
        }
        $this->set( 'data', $jsonArray );
        $this->success();  
    }

    public function call_not_answer_first_time() {
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
        

        $call_id = get('call_id', '');
        if(empty($call_id)){
            $this->message('Call id empty.');
            return;
        }


        $this->loadModel('SpaLiveV1.DataOtherServiceCallCancel');
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $CheckIn = $this->DataOtherServicesCheckIn->find()->where(['DataOtherServicesCheckIn.id' => $call_id])->first();        
        if(empty($CheckIn)){
            $this->message('Call id not found.');
            return;
        }               
            
        $this->cancel_join_call();            
        $this->set("message",'We apologize that your Weight Loss Specialist didn\'t join to the call. You can reschedule your check in or send a message to your specialist.');
        $this->success();
        

    }

    public function save_call_reviews() {
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

        $call_id = get('call_id', '');
        if(empty($call_id)){
            $this->message('Call id empty.');
            return;
        }

        $score = get('score', '');
        if(empty($score)){
            $this->message('score is empty.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataCheckInReviews');
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $CheckIn = $this->DataOtherServicesCheckIn->find()->where(['DataOtherServicesCheckIn.id' => $call_id])->first();        
        if(empty($CheckIn)){
            $this->message('Call id not found.');
            return;
        }

        $CheckIn->status = 'COMPLETED';
        $this->DataOtherServicesCheckIn->save($CheckIn);

        $arr_save_q = array(
            'call_id'=> $call_id,
            'examiner_id'=>  $CheckIn->support_id,
            'score'=> $score,
            'deleted'=> 0,
            'createdby'=> USER_ID,
            'created'=> date('Y-m-d H:i:s'),            
        );
    
        $cq_entity = $this->DataCheckInReviews->newEntity($arr_save_q);
        if(!$cq_entity->hasErrors()){
            $this->DataCheckInReviews->save($cq_entity);
        }
        
        if($CheckIn->call_number == 6){
            $this->loadModel('SpaLiveV1.DataConsultationOtherServices');

            $currentConsult = $this->DataConsultationOtherServices->find()->where(['DataConsultationOtherServices.uid' => $CheckIn->consultation_uid])->first();
             
            $currentConsult->status = "COMPLETED";
            $consult_entity = $this->DataConsultationOtherServices->newEntity($currentConsult->toArray());
            $ent_saved1 = $this->DataConsultationOtherServices->save($consult_entity);
            
            $this->set('ent_saved', $ent_saved1);
            $this->success();
            return;
            //return $consult_entity;
        } else {
            $this->success();
            return;
        }
    }

    public function sendWelcomeEmailWeightLossSpecialist($user,$category){

        $this->loadModel('SpaLiveV1.CatProductsOtherServices');
        $this->loadModel('SpaLiveV1.CatQuestionOtherServices');

        $service_uid = "";

        $html = '<!doctype html>
        <html>
        
        <head>
            <meta name="viewport" content="width=device-width">
            <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
            <title>Welcome to our Weight Loss Specialist team!</title>
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
        
        <body style="background-color: #f6f6f6; -webkit-font-smoothing: antialiased; font-size: 14px; line-height: 1.4; margin: 0; padding: 0; -ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%;">
            <div class="content" style="box-sizing: border-box; display: block; margin: 0 auto; max-width: 580px; padding: 10px;">
        
            ';

         $html_pdf = $html;
        
         $html.= '
            <div>
                <div style="background: #ffffff; border-radius: 30px;">
                    <div style="padding-top: 2vw;">
                        <div style="text-align: center;">
                            <img src="https://panel.myspalive.com/img/MySpaLive-logo-email.png" width="200px"/>
                        </div>
                    </div>
                    <div style="margin-top: 2em; padding-left: 1em; padding-bottom: 1.5em;">
                        <div><p style="font-size: 17px;">
                            Welcome you our Weight Loss Specialist Team!.</p> 
                        </div>
                    </div> 
                </div>
            </div>

            <div class="content-block" style="vertical-align: top; padding-bottom: 10px; padding-top: 30px; font-size: 12px; color: #999999; text-align: center;">
                <span style="text-decoration: none !important; color: #999999; font-size: 12px; text-align: center;">
                    Visit us at <a style="color: #999999 !important; text-decoration: none !important; font-size: 12px; line-height: 1.4 !important;" href="https://blog.myspalive.com/">MySpaLive</a>
                </span>
            </div>
        ';

        $html_pdf.= '
            <div>
                <div style="background: #ffffff; border-radius: 30px;">
                    <div style="padding-top: 2vw;">
                        <div style="text-align: center;">
                            <img src="https://panel.myspalive.com/img/MySpaLive-logo-email.png" width="250"/>
                        </div>
                    </div>
                    <div style="margin-bottom: 6.5em; margin-top: 3em; padding-left: 1em; padding-bottom: 1.5em;">
                        <div><p style="font-size: 17px;">
                            Welcome you our Weight Loss Specialist Team!.</p> 
                        </div>
                    </div> 
                </div>
            </div>
        ';
            
        $dynamic_content = '
            <div style="margin-top: 1em;">
                Dear: '.$user["name"].' '.$user["lname"].',
            </div>

            <div style="margin-top: 1em;">
                We are excited to welcome you our Weight Loss Specialist Team!
            </div>

            <div style="margin-top: 1em; word-wrap: break-word;">
                Thank you for choosing us. We are committed to providing you with an exceptional experience and look forward to serving you.
            </div>
            ';

        $products = $this->CatProductsOtherServices->find()->select(['CatProductsOtherServices.name','CatProductsOtherServices.unit_price','CatProductsOtherServices.service_uid'])
            ->where(['CatProductsOtherServices.category' => $category,'CatProductsOtherServices.deleted' => 0])->all();        
        if(!empty($products)){
            $dynamic_content.= '<div style="margin-top: 2em;">
                        <b>Our products that you can offer to patients:</b> <br><br>
            
            <table role="presentation" class="main" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; background: #ffffff; border-radius: 3px;">
            ';
            
            foreach($products as $p){
                $service_uid = $p->service_uid;
                $dynamic_content.= '<tr>
                            <td class="wrapper" style="font-size: 14px; box-sizing: border-box; padding: 20px;">
                            '.$p->name.' $'.$p->unit_price.'
                            </td>
                         </tr>';
            }

            $dynamic_content.= '</table></div>';

        }

        $questions = $this->CatQuestionOtherServices->find()->select(['CatQuestionOtherServices.question'])
            ->where(['CatQuestionOtherServices.questionary_type' => "Check In",'CatQuestionOtherServices.deleted' => 0,'CatQuestionOtherServices.service_uid' => $service_uid])->all();        
        
        if(!empty($questions)){
            $dynamic_content.= '<div style="margin-top: 2em;">
                        <b>Important questions to ask the patient during the check in:</b> <br><br>
            
            <table role="presentation" class="main" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; background: #ffffff; border-radius: 3px;">
            ';
            

            foreach($questions as $q){
                $dynamic_content.= '<tr>
                            <td class="wrapper" style="font-size: 14px; box-sizing: border-box; padding: 20px;">
                            '.$q->question.'
                            </td>
                        </tr>';
            }

            $dynamic_content.= '</table></div>';

        }

        $dynamic_content.= "<div style='margin-top: 2em;'>
                    <div style='word-wrap: break-word;'>
                        Your journey with Weight Loss Specialist Team is just beginning, and we're here to make it memorable. 
                    </div>
                    <div style='margin-top: 1em; word-wrap: break-word;'>
                        Browse our website to explore our extensive product catalog, read reviews from satisfied customers, and find inspiration. If you have questions or need assistance, please don't hesitate to reach out to our friendly customer support team.
                    </div>

                    <div style='margin-top: 1em;'>Welcome to Weight Loss Specialist Team!</div>
                </div>

        </div></body></html>";

        $html.= $dynamic_content;
        $html_pdf.= $dynamic_content;

        $filename = TMP . 'reports' . DS. 'welcome_' . $user["user_id"] . '.pdf';

        $html2pdf = new HTML2PDF('P','A4','en', true, 'UTF-8', array(10, 10, 10, 10));
        $html2pdf->setTestTdInOnePage(false);
        $html2pdf->WriteHTML($html_pdf);
        $html2pdf->Output($filename, 'F'); //,'D'

        $data = array(
            'from'      => 'MySpaLive <info@mg.myspalive.com>',
            'to'        => $user["email"],
            'subject'   => "You have become a Weight Loss Specialist",
            'html'      => $html,
            'attachment[1]' => curl_file_create($filename, 'application/pdf', 'MySpaLive Weight Loss Specialist.pdf'),
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
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type:multipart/form-data"]);

        $result = curl_exec($curl);

        curl_close($curl);
    }
    
    public function list_weight_lost_specialist() {
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

        $call_id = get('call_id', '');
        if(empty($call_id)){
            $this->message('Call id empty.');
            return;
        }

        

        
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.SysUsers');
        $CheckIn = $this->DataOtherServicesCheckIn->find()->where(['DataOtherServicesCheckIn.id' => $call_id])->first();        
        if(empty($CheckIn)){
            $this->message('Call id not found.');
            return;
        }
        $examiner_id =  $CheckIn->support_id;
        
        $array_data = [];


        $where = ['SysUsers.deleted' => 0, 'SysUsers.id <>' => $examiner_id];
        
        $entity = $this->SysUsers->find()->select(['SysUsers.id','SysUsers.uid','SysUsers.name','SysUsers.mname','SysUsers.lname','SysUsers.email','SysUsers.active'])->where($where)
        ->join(['uos' => ['table' => 'data_users_other_services_check_in','type' => 'INNER','conditions' => 'uos.user_id = SysUsers.id and uos.deleted = 0 and uos.status = "WLSHOME"']])
            ->all();

        if(!empty($entity)){
           
            foreach($entity as $row){
                // debug($row);exit;
                $patient_name = empty(trim($row['mname'])) || trim($row['mname']) == '' ? 
                    trim($row['name']) ." ". trim($row['lname']) : 
                    trim($row['name']) ." ". trim($row['mname']) ." ". trim($row['lname']);
                $array_data[] = array(
                    'id' => ($row['id']),
                    'uid' => trim($row['uid']),
                    'email' => trim($row['email']),
                    'name' => $patient_name
                );
            }
        }

        $this->set('data', $array_data);
        $this->success();
    }

    public function reschedule_service_date(){
        //
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

        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $this->message('consultation_uid not found.');
            return;
        }

        $scheduled_date = get('scheduled_date','');
        if (empty($scheduled_date)) {
            $this->message('scheduled_date not found.');
            return;
        }

        $scheduled_time = get('scheduled_time','');
        if (empty($scheduled_time)) {
            $this->message('scheduled time not found.');
            return;
        }
        
        $date_id = get('date_id','');
        if (empty($date_id)) {
            $this->message('date_id not found.');
            return;
        }
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $getCall = $this->DataOtherServicesCheckIn->find()                   
        ->where(['DataOtherServicesCheckIn.id' => $date_id])
        ->first();
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        //SUPPORT ID 
        $this->DataOtherServicesCheckIn->updateAll(
            ['call_time' => $scheduled_time, 'call_date' => $scheduled_date,'status' => 'CLAIMED'], 
            ['id' => $date_id]
        );

        $examiner_id = get('examiner_id','');
        if (!empty($examiner_id)) {
            $this->DataOtherServicesCheckIn->updateAll(
                ['support_id' => $examiner_id],
                ['id' => $date_id]
            );  
        }
        $schedule_aux = new FrozenTime($scheduled_date);

        $this->set('schedule_date', $schedule_aux->i18nFormat('MM-dd-yyyy'));
        $m_date = date('m/d/Y H:i',strtotime($schedule_aux->i18nFormat('yyyy-MM-dd') . " " . $scheduled_time) );
        $s_date = date('M/d/Y H:i',strtotime($schedule_aux->i18nFormat('yyyy-MM-dd') . " " . $scheduled_time) );
        $firebase_update_data = array(
            "uid" => $consultation_uid,
            //"status" => 'CLAIM PENDING',
            "is_waiting" => 0,
            "scheduled_date" => $m_date,
        );
        if (!empty($examiner_id)) {//cuando se cambia de examiner se tiene que actualizar todos los check ins con la informacion del nuevo examiner
            $this->loadModel('SpaLiveV1.SysUsers');
            $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $examiner_id])->first();
            if (!empty($ent_user)) {                            
                $firebase_update_data['examiner_uid'] = $ent_user->uid;
                $firebase_update_data["status"] = "CLAIMED";
            }
                        
            if(!empty($getCall)){
                $this->set('get_call',$getCall );
                //get all check in from consultation uid, state not finished
                $calls = $this->DataOtherServicesCheckIn
                ->find()
                ->where([
                        'DataOtherServicesCheckIn.call_type' => 'CHECK IN', 
                        'DataOtherServicesCheckIn.consultation_uid' => $getCall->consultation_uid, 
                        'DataOtherServicesCheckIn.status' => 'CLAIMED',
                        'DataOtherServicesCheckIn.deleted' => 0,                
                        'DataOtherServicesCheckIn.support_id ' => $getCall->support_id,
                        'DataOtherServicesCheckIn.patient_id ' => $getCall->patient_id
                    ])
                ->all();
                $this->set('calls',$calls );
                if(count($calls) > 0){
                    foreach ($calls as $call) {
                        $call_times = $this->get_next_available_date($examiner_id, $call);                
                        $this->DataOtherServicesCheckIn->updateAll(
                            [
                                'support_id' => $examiner_id,
                                'call_date' => $call_times["call_date"],
                                'call_time' => $call_times["call_time"],
                                're_scheduled' => date('Y-m-d H:i:s'),
                                'status' => 'CLAIMED'
                            ], 
                            ['id' => $call->id]
                        );
                    }
                }
            }
        }
        $id_from = 0;
        $id_to = 0;
        $uid = "";
        $this->loadModel('SpaLiveV1.SysUsers');        

        if(USER_TYPE != 'patient'){
            $ent_check_in = $this->DataOtherServicesCheckIn->find()
            ->where(['DataOtherServicesCheckIn.id' => $date_id])->first();
            if (!empty($ent_check_in)) {
                $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $ent_check_in->support_id])->first();
                if (!empty($ent_user)) {                    
                    $message ="Your specialist ".$ent_user->name ." ". $ent_user->mname ." ". $ent_user->lname.", has rescheduled your check in for ". $s_date;
                }else{
                    $message ="Your specialist, has rescheduled your check in for ". $s_date;
                }
                // send message reschedule
                $id_from = intval($ent_check_in->support_id);
                $id_to = intval($ent_check_in->patient_id);
                $uid = $ent_check_in->uid;
            }
        }else{
            $ent_check_in = $this->DataOtherServicesCheckIn->find()
            ->where(['DataOtherServicesCheckIn.id' => $date_id])->first();
            if (!empty($ent_check_in)) {
                $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $ent_check_in->patient_id])->first();
                if (!empty($ent_user)) {                    
                    $message ="The patient ".$ent_user->name ." ". $ent_user->mname ." ". $ent_user->lname.", has rescheduled the check in for ". $s_date;
                }else{
                    $message ="The patient, has rescheduled the check in for ". $s_date;
                }
                // send message reschedule
                $id_to = intval($ent_check_in->support_id);
                $id_from = intval($ent_check_in->patient_id);
                $uid = $ent_check_in->uid;
            }
        }
        if($id_from != 0 and    $id_to != 0){
            $array_save = array(
                'type' => 'NOTIFICATION',
                'id_from' => $id_from,
                'id_to' => $id_to,
                'message' => $message,
                'extra' => trim($ent_check_in->uid),
                'deleted' => 0,
                'readed' => 0,
                'notification_type' => 'PUSH',
                'detail_notify' => 'WEIGHT_LOSS_MESSAGE',
                'created' => date('Y-m-d H:i:s'),
            );
            // pr($array_save); exit;

            $this->loadModel('SpaLiveV1.DataMessages');
            $c_entity = $this->DataMessages->newEntity($array_save);

            if(!$c_entity->hasErrors()) {
                if ($this->DataMessages->save($c_entity)) {
                    $this->success();
                    $Main = new MainController();                    
                    //     notify_devices($message,   $arr_users,$notify_push, $notify_email, $shouldSave, $data,'',$constants, $notify_sms, $force_hour = false, $treatment_uid='')                    
                    $Main->notify_devices($message,array($id_to),true        ,true         ,false        , []   ,'',[]        , true);
                }
            }
        }
        
        //print_r($data);exit;
       /* $result  = $collectionReference->add($data);            
        $name = $result->name();
        
        //$firebase_document = substr($name, strpos($name, "services/") + 9);*/
        $data_string = json_encode($firebase_update_data);
        $this->set('firebase_update_data', $firebase_update_data);
        $url = env('URL_MICROSERVICE', 'http://localhost:3131/') . 'updateServicesArr';
        $ch=curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);//curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER,
            array(
                'Content-Type:application/json',
                'Content-Length: ' . strlen($data_string)
            )
        );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);

        $this->set('result_curl', $result);
        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            $this->message($error_msg);
            $this->success(false);
            return;
            // this would be your first hint that something went wrong                            
        } else {
            // check the HTTP status code of the request
            $resultStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($resultStatus == 200) {
                //unlink($filename);// everything went better than expected
            } else {
                // the request did not complete as expected. common errors are 4xx
                // (not found, bad request, etc.) and 5xx (usually concerning
                // errors/exceptions in the remote script execution)                                
            }
        }
        curl_close($ch);

        $this->success();
        $this->message("Success.");
        return;

    }

    public function automatic_assign_calls($patient_id, $consultation_uid) : bool {

        // $patient_id = get('patient_id', '');
        // if(empty($patient_id)){
        //     $this->message('patient_id empty.');
        //     return;
        // }

        // $consultation_uid = get('consultation_uid', '');
        // if(empty($consultation_uid)){
        //     $this->message('consultation_uid empty.');
        //     return;
        // }

        $is_dev = env('IS_DEV', false);
        
        $this->loadModel('SpaLiveV1.DataUsersOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.DataReferredOtherServices');
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');

        $date_now = date('Y-m-d');
        $not_like = "";

        # Validate patient is test
        $this->loadModel('SpaLiveV1.SysUsers');
        $patient = $this->SysUsers->find()->where(['SysUsers.id' => $patient_id])->first();

        $patient_name = $patient->mname == '' ? trim($patient->name).' '.trim($patient->lname) : trim($patient->name).' '.trim($patient->mname).' '.trim($patient->lname);
        $is_test_patient = $this->check_test($patient_name);

        if ($is_test_patient) {
            for ($i = 0; $i < count($this->not_allowed_names); $i++) {
                $not_like .= " AND User.name LIKE '%".$this->not_allowed_names[$i]."%' AND User.mname LIKE '%".$this->not_allowed_names[$i]."%' AND User.lname LIKE '%".$this->not_allowed_names[$i]."%'";
            }
        } else {
            for ($i = 0; $i < count($this->not_allowed_names); $i++) {
                $not_like .= " AND User.name NOT LIKE '%".$this->not_allowed_names[$i]."%' AND User.mname NOT LIKE '%".$this->not_allowed_names[$i]."%' AND User.lname NOT LIKE '%".$this->not_allowed_names[$i]."%'";
            }
        }

        $sql ="SELECT
                Specialist.id,
                Specialist.user_id,
                Specialist.status,
                Specialist.created,
                COUNT(DOSC.support_id) AS calls
            FROM data_users_other_services_check_in AS Specialist
            LEFT JOIN data_other_services_check_in AS DOSC ON DOSC.support_id = Specialist.user_id 
                    AND DOSC.created >= $date_now
                    AND DOSC.deleted = 0
            INNER JOIN sys_users AS User ON Specialist.user_id = User.id 
                    ".$not_like." AND User.active = 1
                    AND User.deleted = 0
            WHERE Specialist.deleted = 0
                AND Specialist.status = 'WLSHOME'
                AND Specialist.user_id <> $patient_id
                AND Specialist.user_id NOT IN (SELECT referred_id 
                    FROM data_referred_other_services AS Referred 
                    WHERE Referred.user_id = $patient_id AND Referred.deleted = 1
                                        GROUP BY Referred.referred_id)
            GROUP BY Specialist.id
            ORDER BY calls ASC,
                    Specialist.created ASC;
        ";
        
        $results = $this->DataUsersOtherServicesCheckIn->getConnection()->execute($sql)->fetchAll('assoc');
        
        if (empty($results)) {
            if ($is_dev) {
                $this->message('Weigth Loss Injector not found.');
                return false;
            } else {
                $this->message('Set fixed inejctor.');
                if ($is_test_patient) {
                    $results = [
                        [
                            'user_id' => 1393
                        ]
                    ];
                } else {
                    $this->message('Weigth Loss Injector not found.');
                    return false;
                } 
            }
        }

        $first_wls = $results[0];

        $wls_id = $first_wls['user_id'];
        /*
            $arr_assigned_wls = $this->DataReferredOtherServices->find()
            ->where(['DataReferredOtherServices.auto_assigned' => 1])
            ->order(['DataReferredOtherServices.created' => 'DESC'])
            ->first();
        */

        /* 
            $index = 0;

            if(!empty($arr_assigned_wls)){
                foreach ($arr_wls as $wls) {
                    $index++;
                    if($arr_assigned_wls->injector_id == $wls->user_id){
                        break;
                    }
                }
            }

            $copy_id = $index;

            if($index == count($arr_wls)){
                $index = 0;
            }else{
                if (!empty($arr_assigned_wls)) {
                    $index++;
                } else {
                    $index = 0;
                }
            }

            for($i = $index; $i < count($arr_wls); $i++){
                $wls = $arr_wls[$i];
                //Falta validar si el especialista tiene su agenda llena
                if($this->injector_is_referable($wls->user_id, $patient_id)){
                    $wls_id = $wls->user_id;
                    break;
                }

                if($i == count($arr_wls) - 1){
                    $i = 0;
                }

                if($i == $copy_id){
                    break;
                }
            } 
        */

        /* if ($wls_id == 0) {
            $this->message('No Weigth Loss Injector available.');
            return false;
        } */

        $calls = $this->DataOtherServicesCheckIn
        ->find()
        ->where([
                'DataOtherServicesCheckIn.status NOT IN ' => ['COMPLETED', 'PENDING EVALUATION'],
                'DataOtherServicesCheckIn.call_type' => 'CHECK IN', 
                'DataOtherServicesCheckIn.consultation_uid' => $consultation_uid, 
                'DataOtherServicesCheckIn.deleted' => 0
            ])
        ->all();

        //! $this->set('specialist', $wls_id);
        
        foreach ($calls as $call) {

            $call_times = $this->get_next_available_date($wls_id, $call);
                      
            $this->DataOtherServicesCheckIn->updateAll(
                [
                    'support_id' => $wls_id,
                    'call_date' => $call_times["call_date"],
                    'call_time' => $call_times["call_time"],
                    'status' => 'CLAIMED'
                ], 
                ['id' => $call->id]
            );
        }

        $array_save = array(
            'uid' => Text::uuid(),
            'user_id' => $patient_id,
            'referred_id' => $wls_id,
            'created' => date('Y-m-d H:i:s'),
            'createdby' => 0,
            'deleted' => 0,
            'auto_assigned' => 1,
        );

        // Guardamos que el especialista fue asignado automaticamente 🫠🗿
        $c_entity = $this->DataReferredOtherServices->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            $ent_saved = $this->DataReferredOtherServices->save($c_entity);
            //! $this->set('data', $ent_saved);
            return true;
        }
        $this->message('Error in function automatic_assign_calls.');
        return false;
    }
    
    public function get_next_available_date($wls_id, $call){
        //* Esta función busca la próxima fecha y hora disponibles para programar una llamada de tipo "CHECK IN" 
        //* para un especialista "$wls_id". Para hacerlo, itera a través de las fechas y horas 
        //* disponibles y toma en cuenta las restricciones de ocupación. Cuando encuentra una fecha y hora 
        //* disponibles, devuelve esta información en un arreglo.

        $desired_date = null;
        $desired_time = null;

        $call_date = $call['call_date'];

        $this->loadModel('SpaLiveV1.DataScheduleModel');
        // Verificar si el injector por algun motivo no configuro los dias de trabajo
        $data_schedule = $this->DataScheduleModel->find()
        ->where(['DataScheduleModel.injector_id' => $wls_id, 'DataScheduleModel.deleted' => 0, 'DataScheduleModel.model' => "other_services"])
        ->all();

        if (count($data_schedule) == 0) {
            //$this->message('Injector has not configured his schedule.');
            return array(
                "message" => "Injector has not configured his schedule, contact him to make sure of this.",
            );
        }

        $this->loadModel('SpaLiveV1.DataScheduleDaysOff');
        do{            
            //Consulta para buscar si tiene disponibilidad ese dia
            //Dia de la semana de la llamada
            //$this->set('weekday_appoint', $call->call_date->format('l'));
            $data_wls = $this->DataScheduleModel->find()
            ->where([
                'DataScheduleModel.injector_id' => $wls_id, 
                'DataScheduleModel.deleted' => 0, 
                'DataScheduleModel.model' => "other_services",
                'DataScheduleModel.days' => $call_date->format('l')
            ])->first();
            
            //Consulta para buscar si tiene un dia no laborable el dia de la llamada
            $data_days_off_wls = $this->DataScheduleDaysOff->find()
            ->select(['DataScheduleDaysOff.date_off'])
            ->where(['DataScheduleDaysOff.user_id' => $wls_id, 'DataScheduleDaysOff.deleted' => 0])
            ->toArray();

            $data_days_off_wls = array_map(function ($value) {
                return $value['date_off']->i18nFormat('yyyy-MM-dd');
            }, $data_days_off_wls);

            if (in_array($call_date->i18nFormat('yyyy-MM-dd'), $data_days_off_wls)) {
                //Si el dia esta en los dias no laborables
                $call_date = $call_date->modify('+1 day');
                continue;
            }
            
            if (!empty($data_wls)) {
                //Tiene disponibilidad
                //Creamos un array con las horas que tiene disponibilidad
                $hrs_wls_list = array();
                $start = sprintf('%02d:00', $data_wls->time_start);
                $end = sprintf('%02d:00', $data_wls->time_end);
                $end = date("H:i", strtotime('-1 hour', strtotime($end)));
                while ($start <= $end) {
                    $hour = $start;
                    $hrs_wls_list[] = $hour;
                    $start = date("H:i", strtotime('+20 minutes', strtotime($start)));
                }

                //$this->set('hrs_wls_list', $hrs_wls_list);
                //Consulta para buscar si tiene una llamada ese dia
                $data_wls_assign = $this->DataOtherServicesCheckIn->find()
                ->where(['DataOtherServicesCheckIn.call_type' => 'CHECK IN', 'DataOtherServicesCheckIn.call_date' => $call_date, 'DataOtherServicesCheckIn.support_id' => $wls_id, 'DataOtherServicesCheckIn.deleted' => 0])
                ->all();

                $hrs_wls_assign = [];

                foreach ($data_wls_assign as $hrs) {
                    $hrs_mins = $hrs->call_time->i18nFormat('HH:mm');
                    $hrs_wls_assign[] = $hrs_mins;
                }

                // Obtener las horas ocupadas de hrs_wls_assign
                $hrs_result = [];
                foreach ($hrs_wls_assign as $hora_assign) {
                    list($hora, $minuto) = explode(':', $hora_assign);
                    $hrs_result[] = intval($hora);
                    if ($minuto > 0) {
                        $hrs_result[] = intval($hora) + 1;
                    }
                }

                // Filtrar las horas en hrs_wls_list para eliminar las ocupadas
                $hrs_wls_list = array_filter($hrs_wls_list, function ($hora) use ($hrs_result) {
                    list($h, $m) = explode(':', $hora);
                    $hora_entera = intval($h);
                    return !in_array($hora_entera, $hrs_result);
                });

                // Horas disponibles
                $hrs_available = array_values($hrs_wls_list);
               
                if (!empty($hrs_available)) {
                    $desired_date = $call_date;
                    $desired_time = $hrs_available[0];
                } else {
                    $call_date = $call_date->modify('+1 day');
                }
            } else {
                //No tiene disponible ese dia
                $call_date = $call_date->modify('+1 day');
            }

        }while($desired_date == null);

        return array(
            "call_date" => $desired_date,
            "call_time" => $desired_time
        );        
    }

    public function injector_is_referable($injector_id, $patient_id) : bool {
        
        if ($injector_id == $patient_id) {
            return false;
        }

        $this->loadModel('SpaLiveV1.DataUsersOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.DataReferredOtherServices');

        //Verificar que sea especialista en WLSHOME
        $consult_inj = $this->DataUsersOtherServicesCheckIn
        ->find()
        ->where(['DataUsersOtherServicesCheckIn.deleted' => 0, 'DataUsersOtherServicesCheckIn.status' => 'WLSHOME', 'DataUsersOtherServicesCheckIn.user_id' => $injector_id, ])
        ->first();
        
        //TODO ¿Tendremos que contar las veces que a sido referido?
        /* $count_refered = $this->DataReferredOtherServices->find()
        ->where(['DataReferredOtherServices.deleted' => 0, 'DataReferredOtherServices.injector_id' => $injector_id])
        ->count();

        if ($count_refered >= 3) {
            return false;
        } */
        
        if (empty($consult_inj)) {
            return false;
        } else {
            return true;
        }
    }

    public function get_past_checkin_by_injector() {
        $this->loadModel('SpaLiveV1.DataPatientClinic');

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
        
        $page = get('page', 1);
        $limit = get('limit', 5);
        //$sql_where = " AND CI.call_date < CURDATE() AND (CI.status = 'COMPLETED') AND CI.support_id =  " . USER_ID ;                
        $sql_where = " AND (CI.status = 'COMPLETED') AND CI.support_id =  " . USER_ID ;                
        
        $query_total = "SELECT 
                CI.id, CI.status, CI.call_date, CI.call_time, CI.current_weight, CI.call_title,                
                CONCAT(U.name, ' ', U.lname) name,
                U.email, U.phone                
            FROM data_other_services_check_in CI
            INNER JOIN sys_users U ON U.id = CI.patient_id
            WHERE CI.deleted = 0 AND U.deleted = 0
                AND CI.call_type = 'CHECK IN'                
                {$sql_where}                
        ";
         //print_r($query_total); exit;        
        $ent_query = $this->DataPatientClinic->getConnection()->execute($query_total)->fetchAll('assoc');        
        $total = count($ent_query);        
        
        $offset = (($page - 1) * $limit);        
        $query_grid = "SELECT 
                CI.id, CI.status, CI.call_date, CI.call_time, CI.current_weight, CI.call_title, CI.consultation_uid,
                CONCAT(U.name, ' ', U.lname) name,
                U.email, U.phone                
            FROM data_other_services_check_in CI                
            INNER JOIN sys_users U ON U.id = CI.patient_id
            WHERE CI.deleted = 0 AND U.deleted = 0
                AND CI.call_type = 'CHECK IN'                                
                {$sql_where}                
            ORDER BY CI.call_date desc, CI.call_time desc
            limit {$limit} offset {$offset}
        ";
        // echo $query_grid; exit;
        $ent_users = $this->DataPatientClinic->getConnection()->execute($query_grid)->fetchAll('assoc');
        $has_more = ($total > ($page * $limit));
        $has_less = ($page > 1);
        $result = array();
        if (!empty($ent_users)) {            
            foreach ($ent_users as $row) {                
                $row['phone'] =  preg_replace('/(\d{3})(\d{3})(\d{4})/', '($1) $2 $3', $row['phone']);
                $all_call_date = $row['call_date'] . ' ' . $row['call_time'];
                $row['all_call_date'] = date('m/d/Y h:i A', strtotime($all_call_date));
                $t_array = array(
                    'id'                 => $row['id'],
                    'status'             => $row['status'],
                    'call_date'          => $row['call_date'],
                    'call_time'          => $row['call_time'],
                    'all_call_date'      => $row['all_call_date'],
                    'consultation_uid'   => $row['consultation_uid'],
                    'current_weight'     => empty($row['current_weight']) ? '' : $row['current_weight'],
                    'name'               => $row['name'],
                    'email'              => $row['email'],
                    'phone'              => $row['phone'],
                    'call_title'         => $row['call_title'],                    
                );
                $result[] = $t_array;
            }
        }
        
        $this->set('data', array(
            'total' => $total,
            'page'  => intval($page),
            'limit' => intval($limit),
            'patients'  => $result,
            'has_more' => $has_more,
            'has_less' => $has_less
        ));        
        $this->success();    
    }

    public function cancel_wl_specialist(){
        $this->loadModel('SpaLiveV1.DataReferredOtherServices');
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');

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

        $this->loadModel('DataUsersOtherServicesCheckIn');
        $this->DataUsersOtherServicesCheckIn->updateAll(
            ['status' => 'CANCELLED', 'deleted' => 1],
            ['user_id' => USER_ID]
        );

        //% Obtener solo el consultation_uid de las llamadas pendientes asignadas del especialista
        $uid_calls = $this->DataOtherServicesCheckIn
        ->find()
        ->select(['DataOtherServicesCheckIn.consultation_uid'])
        ->where([
            'DataOtherServicesCheckIn.support_id' => USER_ID,
            'DataOtherServicesCheckIn.call_date > ' => date('Y-m-d'),
            'DataOtherServicesCheckIn.deleted' => 0
        ])
        ->distinct(['DataOtherServicesCheckIn.consultation_uid'])
        ->all();

        if(Count($uid_calls) > 0){

            //% Proceso de reasignación de llamadas
            foreach ($uid_calls as $key => $call) {

                $assinged = $this->automatic_assign_calls($call->patient_id, $call->consultation_uid);
                if($assinged){
                    $message = "Weigth Loss Injector available for | ". $key ." | ". $call->consultation_uid .".";
                    $this->message($message);
                } else {
                    $message = "No Weigth Loss Injector available for | ". $key ." | ". $call->consultation_uid .".";
                    $this->message($message);
                    return;
                }

                $new_info = $this->DataOtherServicesCheckIn
                ->find()
                ->select([
                    'DataOtherServicesCheckIn.id',
                    'DataOtherServicesCheckIn.uid',
                    'DataOtherServicesCheckIn.consultation_uid',
                    'DataOtherServicesCheckIn.patient_id',
                    'DataOtherServicesCheckIn.support_id',
                    'DataOtherServicesCheckIn.call_title',
                    'DataOtherServicesCheckIn.call_type',
                    'DataOtherServicesCheckIn.status',
                    'DataOtherServicesCheckIn.call_date',
                    'DataOtherServicesCheckIn.call_time',
                    'Injector.uid',
                ])
                ->join([
                    'Injector' => [
                        'table' => 'sys_users',
                        'type' => 'INNER',
                        'conditions' => 'Injector.id = DataOtherServicesCheckIn.support_id AND Injector.deleted = 0'
                    ]
                ])
                ->where([
                    'DataOtherServicesCheckIn.consultation_uid' => $call->consultation_uid,
                    'DataOtherServicesCheckIn.deleted' => 0,
                    'DataOtherServicesCheckIn.show' => 1,
                ])
                ->order(['DataOtherServicesCheckIn.id' => 'DESC'])
                ->first();

                # Region update firebase
                $concat_date_time = $new_info->call_date . ' ' . date_format($new_info->call_time, 'h:i A');
                $call_date = date('m/d/Y H:i A', strtotime($concat_date_time));
                $firebase_update_data = array(
                    "call_id"           => strval($new_info->id),
                    "uid"               => $new_info->consultation_uid,
                    "call_title"        => $new_info->call_title,
                    "call_type"         => $new_info->call_type,
                    "status"            => $new_info->status,
                    "scheduled_date"    => $call_date,
                    "examiner_uid"      => $new_info['Injector']['uid'],
                );
                
                //print_r($data);exit;
                $data_string = json_encode($firebase_update_data);
                $this->set('firebase_update_data', $firebase_update_data);
                $url = env('URL_MICROSERVICE', 'http://localhost:3131/') . 'updateServicesArr';
                $ch=curl_init($url);
                curl_setopt($ch, CURLOPT_POST, true);//curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
                curl_setopt($ch, CURLOPT_HEADER, false);
                curl_setopt($ch, CURLOPT_HTTPHEADER,
                    array(
                        'Content-Type:application/json',
                        'Content-Length: ' . strlen($data_string)
                    )
                );
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
                $result = curl_exec($ch);
        
                $this->set('result_curl', $result);
                if (curl_errno($ch)) {
                    $error_msg = curl_error($ch);
                    $this->message($error_msg);
                    $this->success(false);
                    return;
                    // this would be your first hint that something went wrong                            
                } else {
                    // check the HTTP status code of the request
                    $resultStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    if ($resultStatus == 200) {
                        //unlink($filename);// everything went better than expected
                    } else {
                        // the request did not complete as expected. common errors are 4xx
                        // (not found, bad request, etc.) and 5xx (usually concerning
                        // errors/exceptions in the remote script execution)                                
                    }
                }
                curl_close($ch);

                # End Region update firebase

                $Main = new MainController();
                $Main->notify_devices('SPECIALIST_CANCEL_WL',array($new_info->patient_id),false,false,true,array(),'',array(),true);
                $this->success();
            }
        } else {
            $this->message('No calls found.');
            $this->success();
            return;
        }
    }

    public function prescription_pdf($user,$consultation_id,$padding){
        $this->loadModel('SpaLiveV1.DataConsultationPostexamOtherServices');
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');

        $allergies = "";
        $quantity = 0;
        $ml = 0;
        $other_direction = "";
        $examiner_name = "";
        $patient_name = $user['name'].' '.$user['lname'];
        $dob = $user['dob']->i18nFormat('MM/dd/Y');
        $street = $user['street'].'. '.$user['city'].', TX '.$user['zip'];
        $phone = $user['phone'];
        $email = $user['email'];
        $date = date('m/d/Y');
        $phone = $user['phone'];

        $circle2 = false;
        $circle4 = false;
        $circle6 = false;

        $refills = [];

        $post_exam = $this->DataConsultationPostexamOtherServices->find()->select(['DataConsultationPostexamOtherServices.data'])
        ->where(['DataConsultationPostexamOtherServices.consultation_id' => $consultation_id])->first();
        if (!empty($post_exam)) {
            $post_data = json_decode($post_exam->data);

            foreach($post_data as $p) {
                if($p->question == 'Allergies'){
                    $allergies = $p->answer;
                }else if($p->question == 'Quantity'){
                    $quantity = $p->answer;
                }else if($p->question == 'Other directions'){
                    $other_direction = $p->answer;
                }else if($p->question == 'Refills'){
                    $refills = json_decode($p->answer);
                }
            }
        }

        if($quantity == "2 ml."){
            $circle2 = true;
        }else if($quantity == "4 ml."){
            $circle4 = true;
        }else if ($quantity == "6 ml."){
            $circle6 = true;
        }

        $_join = [
            'User' => ['table' => 'sys_users','type' => 'INNER','conditions' => 'User.id = DataConsultationOtherServices.assistance_id'],
        ];

        $examiner = $this->DataConsultationOtherServices->find()->select(['User.name','User.lname'])
        ->where(['DataConsultationOtherServices.id' => $consultation_id])->join($_join)->first();

        if (!empty($examiner)) {
            $examiner_name = $examiner["User"]["name"]. " " . $examiner["User"]["lname"];
        }

        $allergies = trim($allergies);

        $first_comment = "";
        $array_paragraphs = [];

        if(strlen($allergies)>0){

            $words = explode(" ", $allergies);

            $attached_words = 0;

            for ($i=0; $i < count($words); $i++) {
                if(strlen($words[$i])<100&&(strlen($words[$i]) + strlen($first_comment))<100){
                    $first_comment.= $words[$i]." ";
                    $attached_words++;
                }else{
                    break;
                }
            }

            $count_paragraphs = 0;

            $paragraph = "";

            for ($i=$attached_words; $i < count($words); $i++) {
                if(strlen($words[$i])<115&&(strlen($words[$i]) + strlen($paragraph))<115){
                    $paragraph .= $words[$i]." ";
                }else{
                    $array_paragraphs[$count_paragraphs] = $paragraph;
                    $count_paragraphs++;
                    $paragraph = $words[$i]." ";
                }
            }

            if(strlen($paragraph)>0){
                $array_paragraphs[$count_paragraphs] = $paragraph;
            }
            
        }else{
            $first_comment = "Not provided by patient.";
        }

        /*$first_direction = "";
        $array_directions = [];

        $other_direction = trim($other_direction);

        if(strlen(trim($other_direction))>0){
            $words = explode(" ", $other_direction);

            $attached_words = 0;

            for ($i=0; $i < count($words); $i++) {
                if(strlen($words[$i])<70&&(strlen($words[$i]) + strlen($first_direction))<70){
                    $first_direction.= $words[$i]." ";
                    $attached_words++;
                }else{
                    break;
                }
            }

            $count_directions = 0;

            $direction = "";

            for ($i=$attached_words; $i < count($words); $i++) {
                if(strlen($words[$i])<85&&(strlen($words[$i]) + strlen($direction))<85){
                    $direction .= $words[$i]." ";
                }else{
                    $array_directions[$count_directions] = $direction;
                    $count_directions++;
                    $direction = $words[$i]." ";
                }
            }

            if(strlen($direction)>0){
                $array_directions[$count_directions] = $direction;
            }
        }else{
            $first_direction = "Not provided by patient.";
        }*/

        $html = "<page>";
        if($padding){
            $html .= "<div style=\"width: 200mm; height: 277mm; position:relative; color: #373a48; padding: 5mm\">";
        }else{
            $html .= "<div style=\"width: 200mm; height: 277mm; position:relative; color: #373a48;\">";
        }
                $html .= "<div style=\"width: 199.5mm !important;\">
                        <table style=\"text-align: center; width: 199.5mm;\">
                            <tr style=\"width: 100%;\">
                                <td style=\"padding-top: 4mm; text-align: center; width: 25%;height: 5mm;\">
                                    <img src=\"{$this->URL_API}img/drug_crafters.png\" style=\"width:70%;\">
                                </td>
                                <td style=\"text-align: center; width: 50%;height: 5mm;\">
                                    <p style=\"font-size: 24px; color: grey; font-weight: bold;\">
                                        Semaglutide 2.5mg/mL <br> (+B6 10mg/mL) Injection
                                    </p>
                                </td>
                                <td style=\"padding-top: 5mm; text-align: center; width: 25%;height: 5mm;\">
                                    <img src=\"{$this->URL_API}img/s.png\" style=\"padding-left: 3.5mm; width:35%;\">
                                </td>
                            </tr>
                        </table>

                        <table style=\"margin-left: 32.2%; text-align: center; margin-top: 2mm;\">
                            <tr>
                                <td style=\"font-size: 19px; text-align: center;\"><b>Bill to Office / Ship to Patient</b></td>
                            </tr>
                        </table>

                        <table style=\"margin-top: 2mm; margin-left: 15mm; text-align: center; width: 170mm; color: white;\">
                            <tbody>
                                <tr>
                                    <td style=\"width: 100%; font-size: 13px; text-align: left;\"><div style=\"padding-top: 2mm; padding-bottom: 2mm; background-color: #a2a2a2; color: #a2a2a2; width: 3%; display: inline;\">a</div> <div style=\"padding-top: 2mm; padding-bottom: 2mm; padding-left: 2mm; background-color: #6a6a6a; width: 96%; display: inline;\">PATIENT INFORMATION</div></td>
                                </tr>
                            </tbody>
                        </table>

                        <table style=\"margin-left: 20mm; margin-top: 3mm; text-align: center; width: 158mm;\">
                            <tbody>
                                <tr>
                                    <td style=\"width: 22%; font-size: 13px; text-align: left;\"><div style=\"padding-top: 1mm;\"><b>Patient Name</b></div></td>
                                    <td style=\"width: 4%; font-size: 13px; text-align: left;\"><div style=\"padding-top: 1mm;\"><b>:</b></div></td>
                                    <td style=\"width: 74%; font-size: 12px; text-align: left; border: 1.5px solid black;\"><div style=\"padding-top: 2mm; padding-bottom: 2mm; padding-left: 1mm;\">".$patient_name."</div></td>
                                </tr>
                            </tbody>
                        </table>

                        <table style=\"margin-top: 1mm; margin-left: 20mm; text-align: center; width: 158mm;\">
                            <tbody>
                                <tr>
                                    <td style=\"width: 22%; font-size: 13px; text-align: left;\"><div style=\"padding-top: 1mm;\"><b>Address</b></div></td>
                                    <td style=\"width: 4%; font-size: 13px; text-align: left;\"><div style=\"padding-top: 1mm;\"><b>:</b></div></td>
                                    <td style=\"width: 74%; font-size: 12px; text-align: left; border: 1.5px solid black;\"><div style=\"padding-top: 2mm; padding-bottom: 2mm; padding-left: 1mm;\">".$street."</div></td>
                                </tr>
                            </tbody>
                        </table>

                        <table style=\"margin-top: 2mm; margin-left: 20mm; text-align: center; width: 158mm;\">
                            <tbody>
                                <tr>
                                    <td style=\"width: 12%; font-size: 13px; text-align: left;\"><b>D.O.B.:</b></td>
                                    <td style=\"width: 17%; font-size: 12px; text-align: left;\">".$dob."<hr style=\"width: 100%;\"></td>
                                    <td style=\"padding-right: 2mm; width: 36%; font-size: 13px; text-align: right;\"><b>Phone Number:</b></td>
                                    <td style=\"width: 35%; font-size: 12px; text-align: left;\">".$phone."<hr style=\"width: 100%;\"></td>
                                </tr>
                            </tbody>
                        </table>

                        <table style=\"margin-left: 20mm; text-align: center; width: 158mm;\">
                            <tbody>
                                <tr>
                                    <td style=\"width: 12%; font-size: 13px; text-align: left;\"><b>Allergies:</b></td>
                                    <td style=\"width: 88%; font-size: 12px; text-align: left;\">".$first_comment."<hr style=\"width: 100%;\"></td>
                                </tr>
                            </tbody>
                        </table>";

                            for ($i=0; $i < count($array_paragraphs); $i++) {
                                $html.= "
                                <table style=\"margin-left: 20mm; text-align: center; width: 158.5mm;\">
                                    <tbody>
                                        <tr>
                                            <td style=\"width: 99.5%; font-size: 12px; text-align: left;\">".$array_paragraphs[$i]."<hr style=\"width: 100%;\"></td>
                                        </tr>
                                    </tbody>
                                </table>";
                            }

                            $html.= "

                        <table style=\"margin-left: 20mm; text-align: center; width: 158mm;\">
                            <tbody>
                                <tr>
                                    <td style=\"width: 12%; font-size: 13px; text-align: left;\"><b>Email:</b></td>
                                    <td style=\"width: 88%; font-size: 12px; text-align: left;\">".$email."<hr style=\"width: 100%;\"></td>
                                </tr>
                            </tbody>
                        </table>

                        <table style=\"margin-top: 2mm; margin-left: 15mm; text-align: center; width: 170mm; color: white;\">
                            <tbody>
                                <tr>
                                    <td style=\"width: 100%; font-size: 13px; text-align: left;\"><div style=\"padding-top: 2mm; padding-bottom: 2mm; background-color: #a2a2a2; color: #a2a2a2; width: 3%; display: inline;\">a</div> <div style=\"padding-top: 2mm; padding-bottom: 2mm; padding-left: 2mm; background-color: #6a6a6a; width: 30%; display: inline;\">COMPLETE PROTOCOL</div> <div style=\"background-color: #6a6a6a; width: 66%; display: inline; padding-top: 2mm; padding-bottom: 2mm;\"> Please check appropriate boxes & circle when necessary</div> </td>
                                </tr>
                            </tbody>
                        </table>

                        <table style=\"margin-top: 2mm; margin-left: 20mm; text-align: center; width: 158mm;\">
                            <tbody>
                                <tr>
                                    <td style=\"width: 38%; font-size: 13px; text-align: left; padding-top: 1mm;\"><b>Reason for requesting compound</b></td>
                                    <td style=\"width: 5%; font-size: 12px; text-align: left;\"> <div style=\"border: 1.5px solid #555; width: 5mm; height: 5mm;\"> </div> </td>
                                    <td style=\"width: 22%; font-size: 13px; text-align: left; padding-top: 1mm;\"><b>Nausea & Fatigue</b></td>
                                    <td style=\"width: 5%; font-size: 12px; text-align: left;\"> <div style=\"border: 1.5px solid #555; width: 5mm; height: 5mm;\"> </div> </td>
                                    <td style=\"width: 20%; font-size: 13px; text-align: left; padding-top: 1mm;\"><b>Flexible Dosing</b></td>
                                    <td style=\"width: 5%; font-size: 15px; text-align: left;\"> <div style=\"border: 1.5px solid #555; width: 5mm; height: 5mm; text-align: center; line-height: 5mm; font-weight: bold;\">X</div> </td>
                                    <td style=\"width: 5%; font-size: 13px; text-align: left; padding-top: 1mm;\"><b>Both</b></td>
                                </tr>
                            </tbody>
                        </table>

                        <table style=\"margin-top: 2mm; margin-left: 20mm; text-align: center; width: 158mm;\">
                            <tbody>
                                <tr>
                                    <td style=\"padding-top: .5mm; width: 50%; font-size: 15px; text-align: left;\"><b>Semaglutide 2.5mg/mL (+B6 10mg/mL)</b></td>
                                    <td style=\"padding-top: .5mm; width: 13%; font-size: 15px; text-align: center;\"><i>Circle one:</i></td>
                                    <td style=\"width: 10%; font-size: 15px; text-align: center;\">";if($circle2){$html.="<div style=\"border: solid 2px grey; border-radius:10px;\"><span style=\"font-weight: bold;\">#2mL</span></div></td>";}else{$html.="<div style=\"padding-top: .5mm;\"><span style=\"font-weight: bold;\">#2mL</span></div></td>";}           
                                    $html.="<td style=\"width: 10%; font-size: 15px; text-align: center;\">";if($circle4){$html.="<div style=\"border: solid 2px grey; border-radius:10px;\"><span style=\"font-weight: bold;\">#4mL</span></div></td>";}else{$html.="<div style=\"padding-top: .5mm;\"><span style=\"font-weight: bold;\">#4mL</span></div></td>";}
                                    $html.="<td style=\"width: 10%; font-size: 15px; text-align: center;\">";if($circle6){$html.="<div style=\"border: solid 2px grey; border-radius:10px;\"><span style=\"font-weight: bold;\">#6mL</span></div></td>";}else{$html.="<div style=\"padding-top: .5mm;\"><span style=\"font-weight: bold;\">#6mL</span></div></td>";}
                                    $html.="</tr>
                            </tbody>
                        </table>

                        <table style=\"margin-left: 20mm; text-align: center; width: 158mm;\">
                            <tbody>
                                <tr>
                                    <td style=\"width: 100%; font-size: 10px; text-align: left;\"><i>This combination is formulated specifically for patients that are either sensitive to nausea & fatigue, require flexible dosing or both. It is not intended for use in place of commercial medications within this therapeutic class of medication. *Patients new to therapy, following the protocol should start with #2mL vial*</i></td>
                                </tr>
                            </tbody>
                        </table>";
                        
                        foreach($refills as $r) {
                            $html.= "
                            <table style=\"margin-top: 2.5mm; margin-left: 20mm; text-align: center; width: 158mm;\">
                                <tbody>
                                    <tr>";

                                    if($r->has_refills){
                                        $html.= " <td style=\"width: 5%; font-size: 15px; text-align: left;\"><div style=\"border: 1.5px solid #555; width: 5mm; height: 5mm;\"> <div style=\"margin-top: .25mm; margin-left: 1.5mm;\">"; if($r->selected){ $html.="X"; } $html.="</div> </div></td>
                                            <td style=\"width: 79%; font-size: 15px; text-align: left;\">".$r->text."</td>
                                            <td style=\"width: 8%; font-size: 15px; text-align: left;\">Refills:</td>
                                            <td style=\"width: 8%; font-size: 15px; text-align: center;\">".$r->refills."<hr style=\"width: 100%;\"></td>";
                                    }else{

                                        $supplies = explode(":", $r->text);

                                        $html.= "<td style=\"width: 5%; font-size: 15px; text-align: left;\"><div style=\"border: 1.5px solid #555; width: 5mm; height: 5mm;\"> <div style=\"margin-top: .25mm; margin-left: 1.5mm;\">"; if($r->selected){ $html.="X"; } $html.="</div> </div></td>
                                        <td style=\"width: 95%; font-size: 13.5px; text-align: left;\"><b>".$supplies[0]." : </b>".$supplies[1]."</td>";
                                    }
                                    
                            $html.= "</tr>
                                </tbody>
                            </table>";
                        }

                        $html.= "
                        <table style=\"margin-top: 3mm; margin-left: 15mm; text-align: center; width: 170mm; color: white;\">
                            <tbody>
                                <tr>
                                    <td style=\"width: 100%; font-size: 13px; text-align: left;\"><div style=\"padding-top: 2mm; padding-bottom: 2mm; background-color: #a2a2a2; color: #a2a2a2; width: 3%; display: inline;\">a</div> <div style=\"padding-top: 2mm; padding-bottom: 2mm; padding-left: 2mm; background-color: #6a6a6a; width: 96%; display: inline;\">PRESCRIBER INFORMATION</div></td>
                                </tr>
                            </tbody>
                        </table>

                        <table style=\"margin-top: 2mm; margin-left: 20mm; text-align: center; width: 158mm;\">
                            <tbody>
                                <tr>
                                    <td style=\"width: 18%; font-size: 14px; text-align: left;\"><b>Prescriber:</b></td>
                                    <td style=\"width: 29%; font-size: 13px; text-align: left;\"><div style=\"padding-bottom: 2mm;\">Marie Beauvoir</div><hr style=\"width: 100%;\"></td>
                                    <td style=\"width: 25%; font-size: 14px; text-align: right;\"><b>Prescriber Signature:</b></td>
                                    <td style=\"width: 28%; font-size: 15px; text-align: center;\"><img src=\"{$this->URL_API}img/signature.png\" style=\"height: 2%; width:100%;\"><hr style=\"width: 100%;\"></td>
                                </tr>
                            </tbody>
                        </table>

                        <table style=\"margin-left: 20mm; text-align: center; width: 158mm;\">
                            <tbody>
                                <tr>
                                    <td style=\"width: 18%; font-size: 14px; text-align: left;\"><b>Practice Name:</b></td>
                                    <td style=\"width: 35%; font-size: 13px; text-align: left;\">Concierge Health Medicine, PLLC<hr style=\"width: 100%;\"></td>
                                    <td style=\"width: 10%; font-size: 14px; text-align: right;\"><b>Phone:</b></td>
                                    <td style=\"width: 17%; font-size: 13px; text-align: center;\">(430) 205 4192<hr style=\"width: 100%;\"></td>
                                    <td style=\"width: 7%; font-size: 14px; text-align: right;\"><b>Date:</b></td>
                                    <td style=\"width: 13%; font-size: 13px; text-align: left;\">".$date."<hr style=\"width: 100%;\"></td>
                                </tr>
                            </tbody>
                        </table>

                        <table style=\"margin-bottom: 3mm; margin-top: 3mm; margin-left: 6.5mm; text-align: center; width: 158mm;\">
                            <tbody>
                                <tr>
                                    <td style=\"width: 7%; font-size: 15px; text-align: center;\"><img src=\"{$this->URL_API}img/location.png\" style=\"height: 3.5%; width:100%;\"></td>
                                    <td style=\"width: 25%; font-size: 12px; text-align: left;\"><div style=\"font-weight: bold; margin-top: 1mm;\">5680 Frisco Square Blvd. <br> #1100 Frisco, TX 75034</div></td>
                                    <td style=\"width: 7%; font-size: 15px; text-align: center;\"><img src=\"{$this->URL_API}img/phone.png\" style=\"height: 3.5%; width:100%;\"></td>
                                    <td style=\"width: 14%; font-size: 12px; text-align: left;\"><div style=\"font-weight: bold; margin-top: 3mm;\">214-618-3511</div></td>
                                    <td style=\"width: 7%; font-size: 15px; text-align: center;\"><img src=\"{$this->URL_API}img/print.png\" style=\"height: 3.5%; width:100%;\"></td>
                                    <td style=\"width: 33%; font-size: 12px; text-align: left;\"><div style=\"font-weight: bold; margin-top: 1mm;\">Patient Fax orders: 888-682-0738 <br> Office Fax orders: 214-618-3720</div></td>
                                    <td style=\"width: 7%; font-size: 15px; text-align: center;\"><img src=\"{$this->URL_API}img/language.png\" style=\"height: 3.5%; width:100%;\"></td>
                                    <td style=\"width: 10%; font-size: 12px; text-align: left;\"><div style=\"font-weight: bold; margin-top: 3mm;\">Drugcrafters.com</div></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </page>";

        /*$html2pdf = new HTML2PDF('F','A4','en', true, 'UTF-8', array(0,0,0,0));
        $html2pdf->writeHTML($html);

        $filename = TMP . 'files' . DS . 'prescription.pdf';
        
        $html2pdf->Output($filename, 'F'); // Generar el archivo PDF
        $html2pdf->Output($filename, 'I'); // Mostrar el archivo PDF en el navegador

        pr($html);
        die();
        exit;*/

        return $html;
    }

    public function change_specialist() {
        
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

        $wls_uid = get('wls_uid', '');

        //% Eliminar especialista en la tabla data_refered

        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $this->loadModel('SpaLiveV1.DataReferredOtherServices');
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');

        $call = $this->DataOtherServicesCheckIn
        ->find()
        ->select(['DataOtherServicesCheckIn.support_id'])
        ->where([
            'DataOtherServicesCheckIn.patient_id' => USER_ID,
            'DataOtherServicesCheckIn.call_type' => 'CHECK IN',
            'DataOtherServicesCheckIn.show' => 1,
            'DataOtherServicesCheckIn.deleted' => 0
        ])
        ->order(['DataOtherServicesCheckIn.id' => 'DESC'])
        ->first();

        $this->DataReferredOtherServices->updateAll(
            ['deleted' => 1],
            ['referred_id' => $call->support_id, 'user_id' => USER_ID]
        );

        $consultation = $this->DataConsultationOtherServices
        ->find()
        ->where(
            [
                'DataConsultationOtherServices.patient_id' => USER_ID,
                'DataConsultationOtherServices.status' => 'IN PROGRESS',
                'DataConsultationOtherServices.deleted' => 0
            ])
        ->first();

        if (empty($consultation)) {
            $this->message('Consultation not found.');
            return;
        }

        $call = $this->DataOtherServicesCheckIn
        ->find()
        ->where([
            'DataOtherServicesCheckIn.patient_id' => USER_ID,
            'DataOtherServicesCheckIn.call_type' => 'CHECK IN',
            'DataOtherServicesCheckIn.show' => 1,
            'DataOtherServicesCheckIn.deleted' => 0
        ])
        ->order(['DataOtherServicesCheckIn.id' => 'DESC'])
        ->first();

        if (empty($call)) {
            $this->message('Call not found.');
            return;
        } else {

            if ($call->call_date <= date('Y-m-d')) {
                $this->DataOtherServicesCheckIn->updateAll(
                    ['call_date' => date('Y-m-d', strtotime('+2 day'))],
                    ['id' => $call->id]
                );
            }
        }
        
        if (empty($wls_uid)) {
            //% Asignar especialista automaticamente

            $changed_wls = $this->automatic_assign_calls(USER_ID, $consultation->uid);

            if (!$changed_wls) {
                $this->message('No Weigth Loss Injector available.');
                return;
            } else {

                $new_info = $this->DataOtherServicesCheckIn
                ->find()
                ->select([
                    'DataOtherServicesCheckIn.id',
                    'DataOtherServicesCheckIn.uid',
                    'DataOtherServicesCheckIn.consultation_uid',
                    'DataOtherServicesCheckIn.patient_id',
                    'DataOtherServicesCheckIn.support_id',
                    'DataOtherServicesCheckIn.call_title',
                    'DataOtherServicesCheckIn.call_type',
                    'DataOtherServicesCheckIn.status',
                    'DataOtherServicesCheckIn.call_date',
                    'DataOtherServicesCheckIn.call_time',
                    'Injector.uid',
                ])
                ->join([
                    'Injector' => [
                        'table' => 'sys_users',
                        'type' => 'INNER',
                        'conditions' => 'Injector.id = DataOtherServicesCheckIn.support_id AND Injector.deleted = 0'
                    ]
                ])
                ->where([
                    'DataOtherServicesCheckIn.consultation_uid' => $consultation->uid,
                    'DataOtherServicesCheckIn.deleted' => 0,
                    'DataOtherServicesCheckIn.call_type' => 'CHECK IN',
                    'DataOtherServicesCheckIn.show' => 1,
                ])
                ->order(['DataOtherServicesCheckIn.id' => 'DESC'])
                ->first();

                $concat_date_time = $new_info->call_date . ' ' . date_format($new_info->call_time, 'h:i A');
                $call_date = date('m/d/Y H:i A', strtotime($concat_date_time));

                # Region update firebase

                $firebase_update_data = array(
                    "call_id"           => strval($new_info->id),
                    "uid"               => $new_info->consultation_uid,
                    "call_title"        => $new_info->call_title,
                    "call_type"         => $new_info->call_type,
                    "status"            => $new_info->status,
                    "scheduled_date"    => $call_date,
                    "examiner_uid"      => $new_info['Injector']['uid'],
                );

                $update_firebase = $this->update_firebase($firebase_update_data);

                if(!$update_firebase){
                    $this->message('Error updating firebase.');
                }
                # End Region update firebase
                $this->success();
                return;
            }

        } else {
            //% Asignar especialista que se escogió

            $this->loadModel('SpaLiveV1.SysUsers');
            $wls = $this->SysUsers
            ->find()
            ->select(['SysUsers.id', 'SysUsers.uid'])
            ->where(
                [
                    'SysUsers.uid' => $wls_uid,
                    'SysUsers.deleted' => 0
                ])
            ->first();

            if (empty($wls)) {
                $this->message('Weigth Loss Injector not found.');
                return;
            }

            //! $this->set('specialist', $wls);

            $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
            $calls = $this->DataOtherServicesCheckIn
            ->find()
            ->where([
                    'DataOtherServicesCheckIn.status NOT IN ' => ['COMPLETED', 'PENDING EVALUATION'],
                    'DataOtherServicesCheckIn.call_type' => 'CHECK IN',
                    'DataOtherServicesCheckIn.consultation_uid' => $consultation->uid, 
                    'DataOtherServicesCheckIn.deleted' => 0
                ])
            ->all();

            //! $this->set('calls', $calls);
            
            $successFlags = array_fill(0, count($calls), false); // Inicializamos un array de flags con valores iniciales en falso
            
            foreach ($calls as $index => $call) {
                $call_times = $this->get_next_available_date($wls->id, $call);

                $result = $this->DataOtherServicesCheckIn->updateAll(
                    [
                        'support_id' => $wls->id,
                        'call_date' => $call_times["call_date"],
                        'call_time' => $call_times["call_time"],
                        'status' => 'CLAIMED'
                    ], 
                    ['id' => $call->id]
                );

                $successFlags[$index] = $result; // Almacenamos el resultado en el array de flags
            }

            if (in_array(false, $successFlags)) {
                $this->message('Error assigning Weigth Loss Injector.');
                return;
            } else {
                
                $new_info = $this->DataOtherServicesCheckIn
                ->find()
                ->select([
                    'DataOtherServicesCheckIn.id',
                    'DataOtherServicesCheckIn.uid',
                    'DataOtherServicesCheckIn.consultation_uid',
                    'DataOtherServicesCheckIn.patient_id',
                    'DataOtherServicesCheckIn.support_id',
                    'DataOtherServicesCheckIn.call_title',
                    'DataOtherServicesCheckIn.call_type',
                    'DataOtherServicesCheckIn.status',
                    'DataOtherServicesCheckIn.call_date',
                    'DataOtherServicesCheckIn.call_time',
                    'Injector.uid',
                ])
                ->join([
                    'Injector' => [
                        'table' => 'sys_users',
                        'type' => 'INNER',
                        'conditions' => 'Injector.id = DataOtherServicesCheckIn.support_id AND Injector.deleted = 0'
                    ]
                ])
                ->where([
                    'DataOtherServicesCheckIn.consultation_uid' => $consultation->uid,
                    'DataOtherServicesCheckIn.deleted' => 0,
                    'DataOtherServicesCheckIn.call_type' => 'CHECK IN',
                    'DataOtherServicesCheckIn.show' => 1,
                ])
                ->order(['DataOtherServicesCheckIn.id' => 'DESC'])
                ->first();

                $concat_date_time = $new_info->call_date . ' ' . date_format($new_info->call_time, 'h:i A');
                $call_date = date('m/d/Y H:i A', strtotime($concat_date_time));

                # Region update firebase

                $firebase_update_data = array(
                    "call_id"           => strval($new_info->id),
                    "uid"               => $new_info->consultation_uid,
                    "call_title"        => $new_info->call_title,
                    "call_type"         => $new_info->call_type,
                    "status"            => $new_info->status,
                    "scheduled_date"    => $call_date,
                    "examiner_uid"      => $new_info['Injector']['uid'],
                );

                $update_firebase = $this->update_firebase($firebase_update_data);

                if(!$update_firebase){
                    $this->message('Error updating firebase.');
                }
                # End Region update firebase
                $this->success();
                return;
            }

        }
    }

    public function change_specialist_score() {
        
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

        $wls_uid = get('wls_uid', '');

        //% Eliminar especialista en la tabla data_refered

        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $this->loadModel('SpaLiveV1.DataReferredOtherServices');
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        
        // save_call_reviews necesita el parametro call_id y el score
        $this->save_call_reviews();
        
        $call = $this->DataOtherServicesCheckIn
        ->find()
        ->select(['DataOtherServicesCheckIn.support_id'])
        ->where([
            'DataOtherServicesCheckIn.patient_id' => USER_ID,
            'DataOtherServicesCheckIn.call_type' => 'CHECK IN',
            'DataOtherServicesCheckIn.show' => 1,
            'DataOtherServicesCheckIn.deleted' => 0
        ])
        ->order(['DataOtherServicesCheckIn.id' => 'DESC'])
        ->first();

        $this->DataReferredOtherServices->updateAll(
            ['deleted' => 1],
            ['referred_id' => $call->support_id, 'user_id' => USER_ID]
        );

        $consultation = $this->DataConsultationOtherServices
        ->find()
        ->where(
            [
                'DataConsultationOtherServices.patient_id' => USER_ID,
                'DataConsultationOtherServices.status' => 'IN PROGRESS',
                'DataConsultationOtherServices.deleted' => 0
            ])
        ->first();

        if (empty($consultation)) {
            $this->message('Consultation not found.');
            return;
        }
        
        if (empty($wls_uid)) {
            //% Asignar especialista automaticamente

            $changed_wls = $this->automatic_assign_calls(USER_ID, $consultation->uid);

            if (!$changed_wls) {
                $this->message('No Weigth Loss Injector available.');
                return;
            } else {

                $new_info = $this->DataOtherServicesCheckIn
                ->find()
                ->select([
                    'DataOtherServicesCheckIn.id',
                    'DataOtherServicesCheckIn.uid',
                    'DataOtherServicesCheckIn.consultation_uid',
                    'DataOtherServicesCheckIn.patient_id',
                    'DataOtherServicesCheckIn.support_id',
                    'DataOtherServicesCheckIn.call_title',
                    'DataOtherServicesCheckIn.call_type',
                    'DataOtherServicesCheckIn.status',
                    'DataOtherServicesCheckIn.call_date',
                    'DataOtherServicesCheckIn.call_time',
                    'Injector.uid',
                ])
                ->join([
                    'Injector' => [
                        'table' => 'sys_users',
                        'type' => 'INNER',
                        'conditions' => 'Injector.id = DataOtherServicesCheckIn.support_id AND Injector.deleted = 0'
                    ]
                ])
                ->where([
                    'DataOtherServicesCheckIn.consultation_uid' => $consultation->uid,
                    'DataOtherServicesCheckIn.deleted' => 0,
                    'DataOtherServicesCheckIn.call_type' => 'CHECK IN',
                    'DataOtherServicesCheckIn.show' => 1,
                ])
                ->order(['DataOtherServicesCheckIn.id' => 'DESC'])
                ->first();

                $concat_date_time = $new_info->call_date . ' ' . date_format($new_info->call_time, 'h:i A');
                $call_date = date('m/d/Y H:i A', strtotime($concat_date_time));


                # Region update firebase

                $firebase_update_data = array(
                    "call_id"           => strval($new_info->id),
                    "uid"               => $new_info->consultation_uid,
                    "call_title"        => $new_info->call_title,
                    "call_type"         => $new_info->call_type,
                    "status"            => $new_info->status,
                    "scheduled_date"    => $call_date,
                    "examiner_uid"      => $new_info['Injector']['uid'],
                );

                $update_firebase = $this->update_firebase($firebase_update_data);

                if(!$update_firebase){
                    $this->message('Error updating firebase.');
                }
                # End Region update firebase
                $this->success();
                return;
            }

        } else {
            //% Asignar especialista que se escogió

            $this->loadModel('SpaLiveV1.SysUsers');
            $wls = $this->SysUsers
            ->find()
            ->select(['SysUsers.id', 'SysUsers.uid'])
            ->where(
                [
                    'SysUsers.uid' => $wls_uid,
                    'SysUsers.deleted' => 0
                ])
            ->first();

            if (empty($wls)) {
                $this->message('Weigth Loss Injector not found.');
                return;
            }

            //! $this->set('specialist', $wls);

            $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
            $calls = $this->DataOtherServicesCheckIn
            ->find()
            ->where([
                    'DataOtherServicesCheckIn.status NOT IN ' => ['COMPLETED', 'PENDING EVALUATION'],
                    'DataOtherServicesCheckIn.call_type' => 'CHECK IN',
                    'DataOtherServicesCheckIn.consultation_uid' => $consultation->uid, 
                    'DataOtherServicesCheckIn.deleted' => 0
                ])
            ->all();

            //! $this->set('calls', $calls);
            
            $successFlags = array_fill(0, count($calls), false); // Inicializamos un array de flags con valores iniciales en falso
            
            foreach ($calls as $index => $call) {
                $call_times = $this->get_next_available_date($wls->id, $call);

                $result = $this->DataOtherServicesCheckIn->updateAll(
                    [
                        'support_id' => $wls->id,
                        'call_date' => $call_times["call_date"],
                        'call_time' => $call_times["call_time"],
                        'status' => 'CLAIMED'
                    ], 
                    ['id' => $call->id]
                );

                $successFlags[$index] = $result; // Almacenamos el resultado en el array de flags
            }

            if (in_array(false, $successFlags)) {
                $this->message('Error assigning Weigth Loss Injector.');
                return;
            } else {
                
                $new_info = $this->DataOtherServicesCheckIn
                ->find()
                ->select([
                    'DataOtherServicesCheckIn.id',
                    'DataOtherServicesCheckIn.uid',
                    'DataOtherServicesCheckIn.consultation_uid',
                    'DataOtherServicesCheckIn.patient_id',
                    'DataOtherServicesCheckIn.support_id',
                    'DataOtherServicesCheckIn.call_title',
                    'DataOtherServicesCheckIn.call_type',
                    'DataOtherServicesCheckIn.status',
                    'DataOtherServicesCheckIn.call_date',
                    'DataOtherServicesCheckIn.call_time',
                    'Injector.uid',
                ])
                ->join([
                    'Injector' => [
                        'table' => 'sys_users',
                        'type' => 'INNER',
                        'conditions' => 'Injector.id = DataOtherServicesCheckIn.support_id AND Injector.deleted = 0'
                    ]
                ])
                ->where([
                    'DataOtherServicesCheckIn.consultation_uid' => $consultation->uid,
                    'DataOtherServicesCheckIn.deleted' => 0,
                    'DataOtherServicesCheckIn.call_type' => 'CHECK IN',
                    'DataOtherServicesCheckIn.show' => 1,
                ])
                ->order(['DataOtherServicesCheckIn.id' => 'DESC'])
                ->first();

                $concat_date_time = $new_info->call_date . ' ' . date_format($new_info->call_time, 'h:i A');
                $call_date = date('m/d/Y H:i A', strtotime($concat_date_time));


                # Region update firebase

                $firebase_update_data = array(
                    "call_id"           => strval($new_info->id),
                    "uid"               => $new_info->consultation_uid,
                    "call_title"        => $new_info->call_title,
                    "call_type"         => $new_info->call_type,
                    "status"            => $new_info->status,
                    "scheduled_date"    => $call_date,
                    "examiner_uid"      => $new_info['Injector']['uid'],
                );

                $update_firebase = $this->update_firebase($firebase_update_data);

                if(!$update_firebase){
                    $this->message('Error updating firebase.');
                }
                # End Region update firebase
                $this->success();
                return;
            }

        }
    }

    public function update_firebase($firebase_update_data) : bool {

        $this->set('firebase_update_data', $firebase_update_data);                                
        
        $data_string = json_encode($firebase_update_data);
        $this->set('firebase_update_data', $firebase_update_data);
        $url = env('URL_MICROSERVICE', 'http://localhost:3131/'). 'updateServicesArr';
        $ch=curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);//curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER,
            array(
                'Content-Type:application/json',
                'Content-Length: ' . strlen($data_string)
            )
        );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);        
        $result = curl_exec($ch);        
        $this->set('result_curl', $result);
        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            $this->message($error_msg);
            return false;
            // this would be your first hint that something went wrong                            
        } else {
            // check the HTTP status code of the request
            $resultStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($resultStatus == 200) {
                //unlink($filename);// everything went better than expected
            } else {
                // the request did not complete as expected. common errors are 4xx
                // (not found, bad request, etc.) and 5xx (usually concerning
                // errors/exceptions in the remote script execution)                                
            }
        }
        curl_close($ch);

        return true;

        # End Region update firebase
        
    }

    public function get_prescription_pdf()
    {
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataPurchases');

        $user_uid = get('user_uid', '');
        $user = $this->SysUsers->find()->select(['SysUsers.name', 'SysUsers.lname', 'SysUsers.dob', 'SysUsers.street', 'SysUsers.city', 'SysUsers.zip', 'SysUsers.phone', 'SysUsers.email'])->where(['uid' => $user_uid])->first();

        if(empty($user)){
            $this->message('user empty.');
            return;
        }

        $purchase_uid = get('purchase_uid', '');
        $query = "
            SELECT OS.id 
            FROM data_other_services_check_in CI 
                INNER JOIN data_consultation_other_services OS ON CI.consultation_uid = OS.uid
                INNER JOIN data_purchases P ON P.id = CI.purchase_id
            WHERE P.uid = '" . $purchase_uid . "'
            LIMIT 1
        ";

        $sql = $this->DataPurchases->getConnection()->execute($query)->fetchAll('assoc');
        
        if(empty($sql)){
            $this->message('consultation empty.');
            return;
        }

        $consultation_id = $sql[0]['id'];

        $html = $this->prescription_pdf($user, $consultation_id,true);
        
        $this->set('html', $html);
        $this->success();    
        return;
    }

    public function get_prescription_pdfi()
    {
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataPurchases');

        $user_uid = get('user_uid', '');
        $user = $this->SysUsers->find()->select(['SysUsers.name', 'SysUsers.lname', 'SysUsers.dob', 'SysUsers.street', 'SysUsers.city', 'SysUsers.zip', 'SysUsers.phone', 'SysUsers.email'])->where(['uid' => $user_uid])->first();

        if(empty($user)){
            $this->message('user empty.');
            return;
        }

        $consultation_id = get('consultation_id', '');

        $html = $this->prescription_pdf($user, $consultation_id,true);

        $html2pdf = new HTML2PDF('F','A4','en', true, 'UTF-8', array(0,0,0,0));
        $html2pdf->writeHTML($html);

        $filename = TMP . 'files' . DS . 'prescription.pdf';
        
        $html2pdf->Output($filename, 'F'); // Generar el archivo PDF
        $html2pdf->Output($filename, 'I'); // Mostrar el archivo PDF en el navegador
        return;
        
        $this->set('html', $html);
        $this->success();    
    }

    public function send_email_prescription_pdf($user, $consultation_id)
    {        
        $html_content = $this->prescription_pdf($user, $consultation_id,false);

        /*$options = new Options();
        $options->set('isRemoteEnabled', true);
        $dompdf = new Dompdf($options);

        $dompdf->loadHtml($html_content);

        // (Optional) Setup the paper size and orientation
        $dompdf->setPaper('A4', 'landscape');

        // Render the HTML as PDF
        $dompdf->render();

        $filename = TMP . 'files' . DS . 'prescription.pdf';

        file_put_contents($filename, $dompdf->output());*/
        
        $filename = TMP . 'files' . DS . 'prescription.pdf';
        $html2pdf = new HTML2PDF('P','A4','en', true, 'UTF-8', array(10, 10, 10, 10));
        $html2pdf->WriteHTML($html_content);
        $html2pdf->Output($filename, 'F');

        $data = array(
            'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
            'subject' => 'Medication Order Request',
            'html'    => 'I need to place an order for a new medication for one of our patients.<br>The prescription is attached.<br><br>Thank you.',
            'attachment[1]' => curl_file_create($filename, 'application/pdf', 'Prescription.pdf'),
        );

        $is_dev = env('IS_DEV', false);
        if($is_dev){
            $data['to'] = "carlos@advantedigital.com, francisco@advantedigital.com";
            //$data['to'] = "alanmal92@gmail.com";
        }else{
            $patient_name = $user->mname == '' ? trim($user->name).' '.trim($user->lname) : trim($user->name).' '.trim($user->mname).' '.trim($user->lname);
            $is_test_patient = $this->check_test($patient_name);

            if ($is_test_patient) {
                $data['to'] = "carlos@advantedigital.com";
            }else{
                $data['to'] = 'DFairleigh@drugcrafters.com, francisco@advantedigital.com, cora@advantedigital.com';
                 //$data['bcc'] = 'oscar.caldera@advantedigital.com';
                //$data['to'] = 'oscar.caldera@advantedigital.com';
            }
        }

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
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type:multipart/form-data"]);
        
        $result = curl_exec($curl);
        
        curl_close($curl);
        
    }

    public function system_change_wl_specialist($update_firebase = true, $source = ""){
        //se llama cuando falla la llamada y el sistema seleccione el injector automaticamente
        $this->loadModel('SpaLiveV1.DataReferredOtherServices');
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.DataUsersOtherServicesCheckIn');

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

        $call_id = get('call_id', '');        
        if(empty($call_id)){
            $this->message('Invalid call id.');
            $this->set('session', false);
            return;        
        }     $this->set('call_id_value', $call_id);
        // get data from call
        $getCall = $this->DataOtherServicesCheckIn->find()                   
        ->where(['DataOtherServicesCheckIn.id' => $call_id])
        ->first();
        //$this->set('getCall', $getCall);
        if(empty($getCall)){
            $this->message('Invalid call id.');
            $this->set('session', false);
            return;        
        }                             
        //get examiner available remove the current examiner
        $sql ="SELECT
                Specialist.id,
                Specialist.user_id,
                Specialist.status,
                Specialist.created,
                COUNT(DOSC.support_id) AS calls
            FROM data_users_other_services_check_in AS Specialist
            LEFT JOIN data_other_services_check_in DOSC ON DOSC.support_id = Specialist.user_id
            WHERE Specialist.deleted = 0
                AND Specialist.status = 'WLSHOME'
                AND Specialist.user_id != '". $getCall->support_id ."'
            GROUP BY Specialist.id
            ORDER BY calls ASC,
                    Specialist.created ASC LIMIT 1;
        ";
        
        $results = $this->DataUsersOtherServicesCheckIn->getConnection()->execute($sql)->fetchAll('assoc');

        if (empty($results)) {
            $this->message('Weigth Loss Injector not found.');
            return false;
        }

        $first_wls = $results[0];
        $wls_id = $first_wls['user_id'];                 
        //get all check in from consultation uid, state not finished
        $where = ['DataOtherServicesCheckIn.call_type' => 'CHECK IN', 
                  'DataOtherServicesCheckIn.consultation_uid' => $getCall->consultation_uid, 
                  'DataOtherServicesCheckIn.patient_id ' => $getCall->patient_id,
                  'DataOtherServicesCheckIn.deleted' => 0                ];                    
        $where['OR'] = [['DataOtherServicesCheckIn.status' => 'CLAIM_PENDING'], ['DataOtherServicesCheckIn.status' => 'CLAIMED']];
        $calls = $this->DataOtherServicesCheckIn
        ->find()
        ->where($where)
        ->all();

        $this->set('specialist', $wls_id);
        if(count($calls) > 0){

            foreach ($calls as $call) {
                $call_times = $this->get_next_available_date($wls_id, $call);                
                $this->DataOtherServicesCheckIn->updateAll(
                    [
                        'support_id' => $wls_id,
                        'call_date' => $call_times["call_date"],
                        'call_time' => $call_times["call_time"],
                        're_scheduled' => date('Y-m-d H:i:s'),
                        'status' => 'CLAIMED'
                    ], 
                    ['id' => $call->id]
                );
            }
            // ----------------------------------------------------------------------------------
            // update firebase
            if(!$update_firebase){
                $this->success();
                return;
            }
            $getCallUpdated  = $this->DataOtherServicesCheckIn->find()
            ->select([
                'DataOtherServicesCheckIn.id',                
                'DataOtherServicesCheckIn.consultation_uid',                
                'DataOtherServicesCheckIn.call_title',
                'DataOtherServicesCheckIn.call_type',
                'DataOtherServicesCheckIn.status',
                'DataOtherServicesCheckIn.call_date',
                'DataOtherServicesCheckIn.call_time',
                'Injector.uid',
                'Injector.id',
            ])        
            ->join([
                'Injector' => [
                        'table' => 'sys_users', 
                        'type' => 'INNER', 
                        'conditions' => 'Injector.id = DataOtherServicesCheckIn.support_id'
                    ]
            ])            
            ->where(['DataOtherServicesCheckIn.id' => $call_id])
            ->first();

            $concat_date_time = $getCallUpdated->call_date . ' ' . date_format($getCallUpdated->call_time, 'h:i A');
            $call_date = date('m/d/Y H:i A', strtotime($concat_date_time));

                # Region update firebase
                $firebase_update_data = array(
                    "call_id"           => strval($getCallUpdated->id),
                    "uid"               => $getCallUpdated->consultation_uid,
                    "call_title"        => $getCallUpdated->call_title,
                    "call_type"         => $getCallUpdated->call_type,
                    "status"            => $getCallUpdated->status,
                    "scheduled_date"    => $call_date,
                    "examiner_uid"      => $getCallUpdated['Injector']['uid'],
                );
                $this->set('firebase_update_data', $firebase_update_data);                                
                
                $data_string = json_encode($firebase_update_data);
                $this->set('firebase_update_data', $firebase_update_data);
                $url = env('URL_MICROSERVICE', 'http://localhost:3131/') . 'updateServicesArr';
                $ch=curl_init($url);
                curl_setopt($ch, CURLOPT_POST, true);//curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
                curl_setopt($ch, CURLOPT_HEADER, false);
                curl_setopt($ch, CURLOPT_HTTPHEADER,
                    array(
                        'Content-Type:application/json',
                        'Content-Length: ' . strlen($data_string)
                    )
                );
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);        
                $result = curl_exec($ch);        
                $this->set('result_curl', $result);
                if (curl_errno($ch)) {
                    $error_msg = curl_error($ch);
                    $this->message($error_msg);
                    $this->success(false);
                    return;
                    // this would be your first hint that something went wrong                            
                } else {
                    // check the HTTP status code of the request
                    $resultStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    if ($resultStatus == 200) {
                        //unlink($filename);// everything went better than expected
                    } else {
                        // the request did not complete as expected. common errors are 4xx
                        // (not found, bad request, etc.) and 5xx (usually concerning
                        // errors/exceptions in the remote script execution)                                
                    }
                }
                curl_close($ch);
                # End Region update firebase
                $Main = new MainController();
                //$Main->notify_devices('SPECIALIST_CANCEL_WL',array($new_info->patient_id),false,false,true,array(),'',array(),true);
                $this->success();
            }else{
                if($source =='wl_specialist_change_system_low_rating'){
                    $this->set("checkins_updated",0);
                    $this->success();
                    return;
                }
                $this->message('No calls found.');
                $this->success();
                return;
            }
        
    }

    public function notify_check_ins(){
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');

        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        
        $check_ins = $this->DataOtherServicesCheckIn->find()
            ->where([
                'DataOtherServicesCheckIn.status' => 'CLAIMED',
                'DataOtherServicesCheckIn.deleted' => 0,
                'DataOtherServicesCheckIn.call_type' => 'CHECK IN',
                'DataOtherServicesCheckIn.call_date' => $tomorrow
            ])
            ->all();

        // pr($check_ins); exit; // FOR TESTING PURPOSES ONLY ⚠️

        $Main = new MainController();  
        foreach ($check_ins as $check_in) {
            // pr($check_in); continue; // FOR TESTING PURPOSES ONLY ⚠️

            $id_patient = $check_in['patient_id'];
            // pr($id_patient); continue; // FOR TESTING PURPOSES ONLY ⚠️
            
            $time = $check_in['call_time'];
            $time = date_format($time, 'H:i a');
            
            // pr($time); continue; // FOR TESTING PURPOSES ONLY ⚠️

            $message = "Please upload pictures to prepare for your check-in. Your specialist will call you tomorrow at ".$time.".";
            
            // pr($message); continue; // FOR TESTING PURPOSES ONLY ⚠️

            $Main->notify_devices(
                $message, 
                array($id_patient),
                true,
                false,
                true,     
            );
        }

        //pr('Done'); exit; // FOR TESTING PURPOSES ONLY ⚠️
    } 

    public function wl_specialist_change_system_low_rating(){
        //se llama cuando el paciente evalua menos de 3 estrellas y quiere que el sistema seleccione el injector automaticamente
        $this->loadModel('SpaLiveV1.DataReferredOtherServices');
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.DataUsersOtherServicesCheckIn');

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

        $call_id = get('call_id', '');        
        if(empty($call_id)){
            $this->message('Invalid call id.');
            $this->set('session', false);
            return;        
        }
        $this->set('call_id', $call_id);
        // get data from call
        $getCall = $this->DataOtherServicesCheckIn->find()                   
        ->where(['DataOtherServicesCheckIn.id' => $call_id])
        ->first();
        
        if(empty($getCall)){
            $this->message('Invalid call id.');
            $this->set('session', false);
            return;        
        }

        $call_title = $getCall->call_title;

        if($call_title == 'Sixth Check In'){//Sixth Check In
            $this->message('No new check in.');
            $this->success();
            return;
        }else if($call_title == 'First Check In'){//First Check In
            $call_title = 'Second Check In';
        }else if($call_title == 'Second Check In'){//Second Check In
            $call_title = 'Third Check In';
        }else if($call_title == 'Third Check In'){//Third Check In
            $call_title = 'Fourth Check In';
        }else if($call_title == 'Fourth Check In'){//Fourth Check In
            $call_title = 'Fifth Check In';
        }else if($call_title == 'Fifth Check In'){//Fifth Check In  
            $call_title = 'Sixth Check In'; 
        }

        $getCallUpdateFisrt = $this->DataOtherServicesCheckIn->find()                   
        ->where([
            'DataOtherServicesCheckIn.consultation_uid' => $getCall->consultation_uid, 
            'DataOtherServicesCheckIn.call_title'=> $call_title , 
            'DataOtherServicesCheckIn.deleted' => 0
            ])->first();

        if(empty($getCallUpdateFisrt)){
            $this->message('No new check in.');
            $this->success();
            return;
        }else{
            //$this->set('idCallUpdate', $getCallUpdate->id);
            //$_POST['call_id'] = $getCallUpdate->id;
            //$this->set('call_id',$getCallUpdate->id);
            $this->system_change_wl_specialist(false,'wl_specialist_change_system_low_rating');
            // ----------------------------------------------------------------------------------                                  
            $getCallUpdated  = $this->DataOtherServicesCheckIn->find()
            ->select([
                'DataOtherServicesCheckIn.id',                
                'DataOtherServicesCheckIn.consultation_uid',                
                'DataOtherServicesCheckIn.call_title',
                'DataOtherServicesCheckIn.call_type',
                'DataOtherServicesCheckIn.status',
                'DataOtherServicesCheckIn.call_date',
                'DataOtherServicesCheckIn.call_time',
                'Injector.uid',
                'Injector.id',
            ])        
            ->join([
                'Injector' => [
                        'table' => 'sys_users', 
                        'type' => 'INNER', 
                        'conditions' => 'Injector.id = DataOtherServicesCheckIn.support_id'
                    ]
            ])            
            ->where(['DataOtherServicesCheckIn.id' => $getCallUpdateFisrt->id])
            ->first();
            if(!empty($getCallUpdated) && $getCallUpdated->status == 'CLAIMED'){// evitar actualizar informacion en firebase
                # Region update firebase
                $firebase_update_data = array(
                    "call_id"           => strval($getCallUpdated->id),
                    "uid"               => $getCallUpdated->consultation_uid,
                    "call_title"        => $getCallUpdated->call_title,
                    "call_type"         => $getCallUpdated->call_type,
                    "status"            => $getCallUpdated->status,
                    "scheduled_date"    => $getCallUpdated->call_date . " " . date_format($getCallUpdated->call_time, 'H:i A'),
                    "examiner_uid"      => $getCallUpdated['Injector']['uid'],
                );
                $this->set('firebase_update_data_update', $firebase_update_data);                                
                
                $data_string = json_encode($firebase_update_data);
                $this->set('firebase_update_data', $firebase_update_data);
                $url = env('URL_MICROSERVICE', 'http://localhost:3131/') . 'updateServicesArr';
                $ch=curl_init($url);
                curl_setopt($ch, CURLOPT_POST, true);//curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
                curl_setopt($ch, CURLOPT_HEADER, false);
                curl_setopt($ch, CURLOPT_HTTPHEADER,
                    array(
                        'Content-Type:application/json',
                        'Content-Length: ' . strlen($data_string)
                    )
                );
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);        
                $result = curl_exec($ch);        
                $this->set('result_curl', $result);
                if (curl_errno($ch)) {
                    $error_msg = curl_error($ch);
                    $this->message($error_msg);
                    $this->success(false);
                    return;
                    // this would be your first hint that something went wrong                            
                } else {
                    // check the HTTP status code of the request
                    $resultStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    if ($resultStatus == 200) {
                        //unlink($filename);// everything went better than expected
                    } else {
                        // the request did not complete as expected. common errors are 4xx
                        // (not found, bad request, etc.) and 5xx (usually concerning
                        // errors/exceptions in the remote script execution)                                
                    }
                }
                curl_close($ch);
                # End Region update firebase
                $Main = new MainController();
                //$Main->notify_devices('SPECIALIST_CANCEL_WL',array($new_info->patient_id),false,false,true,array(),'',array(),true);
                $this->success();
            }
        }
    }

    public function reschedule_service_date_low_rating(){
        //se llama cuando el paciente evalua menos de 3 estrellas y selecciona el injector manualmente
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

        $scheduled_date = get('scheduled_date','');
        if (empty($scheduled_date)) {
            $this->message('scheduled_date not found.');
            return;
        }

        $scheduled_time = get('scheduled_time','');
        if (empty($scheduled_time)) {
            $this->message('scheduled time not found.');
            return;
        }
        
        $call_id = get('call_id','');
        if (empty($call_id)) {
            $this->message('call_id not found.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        // get data from call
        $getCall = $this->DataOtherServicesCheckIn->find()                   
        ->where(['DataOtherServicesCheckIn.id' => $call_id])
        ->first();
        
        if(empty($getCall)){
            $this->message('Invalid call id.');
            $this->set('session', false);
            return;        
        }

        $call_title = $getCall->call_title;

        if($call_title == 'Sixth Check In'){//Sixth Check In
            $this->message('No new check in.');
            $this->success();
            return;
        }else if($call_title == 'First Check In'){//First Check In
            $call_title = 'Second Check In';
        }else if($call_title == 'Second Check In'){//Second Check In
            $call_title = 'Third Check In';
        }else if($call_title == 'Third Check In'){//Third Check In
            $call_title = 'Fourth Check In';
        }else if($call_title == 'Fourth Check In'){//Fourth Check In
            $call_title = 'Fifth Check In';
        }else if($call_title == 'Fifth Check In'){//Fifth Check In  
            $call_title = 'Sixth Check In'; 
        }
        $this->set('getCall', $getCall);
        $getCallUpdateFisrt = $this->DataOtherServicesCheckIn->find()                   
        ->where([
            'DataOtherServicesCheckIn.consultation_uid' => $getCall->consultation_uid, 
            'DataOtherServicesCheckIn.call_title'=> $call_title , 
            'DataOtherServicesCheckIn.deleted' => 0
            ])->first();

        if(empty($getCallUpdateFisrt)){
            $this->message('No new check in.');
            $this->success();
            return;
        }
        $this->set('getCallUpdateFisrt', $getCallUpdateFisrt);
        $call_id = $getCallUpdateFisrt->id;
        // ----------------------------------------------------------------------------------------------------------------------------
        //SUPPORT ID 
        $this->DataOtherServicesCheckIn->updateAll(
            ['call_time' => $scheduled_time, 'call_date' => $scheduled_date,'status' => 'CLAIMED'], 
            ['id' => $call_id]
        );

        $examiner_id = get('examiner_id','');
        if (!empty($examiner_id)) {
            $this->DataOtherServicesCheckIn->updateAll(
                ['support_id' => $examiner_id],
                ['id' => $call_id]
            );  
        }
        $schedule_aux = new FrozenTime($scheduled_date);

        $this->set('schedule_date', $schedule_aux->i18nFormat('MM-dd-yyyy'));
        $m_date = date('m/d/Y H:i',strtotime($schedule_aux->i18nFormat('yyyy-MM-dd') . " " . $scheduled_time) );
        $s_date = date('M/d/Y H:i',strtotime($schedule_aux->i18nFormat('yyyy-MM-dd') . " " . $scheduled_time) );
        $firebase_update_data = array(
            "uid" => $getCall->consultation_uid,
            //"status" => 'CLAIM PENDING',
            "is_waiting" => 0,
            "scheduled_date" => $m_date,
        );
        if (!empty($examiner_id)) {             
            $this->loadModel('SpaLiveV1.SysUsers');
            $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $examiner_id])->first();
            if (!empty($ent_user)) {                            
                $firebase_update_data['examiner_uid'] = $ent_user->uid;
                $firebase_update_data["status"] = "CLAIMED";
            }
        }
                            
        //get all check in from consultation uid, state not finished
        $calls = $this->DataOtherServicesCheckIn
        ->find()
        ->where([
                'DataOtherServicesCheckIn.call_type' => 'CHECK IN', 
                'DataOtherServicesCheckIn.consultation_uid' => $getCall->consultation_uid, 
                'DataOtherServicesCheckIn.status' => 'CLAIMED',
                'DataOtherServicesCheckIn.deleted' => 0,                
                'DataOtherServicesCheckIn.support_id ' => $getCall->support_id,
                'DataOtherServicesCheckIn.patient_id ' => $getCall->patient_id
            ])
        ->all();
        
        if(count($calls) > 0){
            foreach ($calls as $call) {
                $call_times = $this->get_next_available_date($examiner_id, $call);                
                $this->DataOtherServicesCheckIn->updateAll(
                    [
                        'support_id' => $examiner_id,
                        'call_date' => $call_times["call_date"],
                        'call_time' => $call_times["call_time"],
                        're_scheduled' => date('Y-m-d H:i:s'),
                        'status' => 'CLAIMED'
                    ], 
                    ['id' => $call->id]
                );
            }
        }
        
        $id_from = 0;
        $id_to = 0;
        $uid = "";
        $this->loadModel('SpaLiveV1.SysUsers');        

        if(USER_TYPE != 'patient'){
            $ent_check_in = $this->DataOtherServicesCheckIn->find()
            ->where(['DataOtherServicesCheckIn.id' => $call_id])->first();
            if (!empty($ent_check_in)) {
                $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $ent_check_in->support_id])->first();
                if (!empty($ent_user)) {                    
                    $message ="Your specialist ".$ent_user->name ." ". $ent_user->mname ." ". $ent_user->lname.", has rescheduled your check in for ". $s_date;
                }else{
                    $message ="Your specialist, has rescheduled your check in for ". $s_date;
                }
                // send message reschedule
                $id_from = intval($ent_check_in->support_id);
                $id_to = intval($ent_check_in->patient_id);
                $uid = $ent_check_in->uid;
            }
        }else{
            $ent_check_in = $this->DataOtherServicesCheckIn->find()
            ->where(['DataOtherServicesCheckIn.id' => $call_id])->first();
            if (!empty($ent_check_in)) {
                $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $ent_check_in->patient_id])->first();
                if (!empty($ent_user)) {                    
                    $message ="The patient ".$ent_user->name ." ". $ent_user->mname ." ". $ent_user->lname.", has rescheduled the check in for ". $s_date;
                }else{
                    $message ="The patient, has rescheduled the check in for ". $s_date;
                }
                // send message reschedule
                $id_to = intval($ent_check_in->support_id);
                $id_from = intval($ent_check_in->patient_id);
                $uid = $ent_check_in->uid;
            }
        }
        if($id_from != 0 and    $id_to != 0){
            $array_save = array(
                'type' => 'NOTIFICATION',
                'id_from' => $id_from,
                'id_to' => $id_to,
                'message' => $message,
                'extra' => trim($ent_check_in->uid),
                'deleted' => 0,
                'readed' => 0,
                'notification_type' => 'PUSH',
                'detail_notify' => 'WEIGHT_LOSS_MESSAGE',
                'created' => date('Y-m-d H:i:s'),
            );
            // pr($array_save); exit;

            $this->loadModel('SpaLiveV1.DataMessages');
            $c_entity = $this->DataMessages->newEntity($array_save);

            if(!$c_entity->hasErrors()) {
                if ($this->DataMessages->save($c_entity)) {
                    $this->success();
                    $Main = new MainController();                    
                    //     notify_devices($message,   $arr_users,$notify_push, $notify_email, $shouldSave, $data,'',$constants, $notify_sms, $force_hour = false, $treatment_uid='')                    
                    $Main->notify_devices($message,array($id_to),true        ,true         ,false        , []   ,'',[]        , true);
                }
            }
        }            
    
        $data_string = json_encode($firebase_update_data);
        $this->set('firebase_update_data', $firebase_update_data);
        $url = env('URL_MICROSERVICE', 'http://localhost:3131/') . 'updateServicesArr';
        $ch=curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);//curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER,
            array(
                'Content-Type:application/json',
                'Content-Length: ' . strlen($data_string)
            )
        );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);

        $this->set('result_curl', $result);
        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            $this->message($error_msg);
            $this->success(false);
            return;
            // this would be your first hint that something went wrong                            
        } else {
            // check the HTTP status code of the request
            $resultStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($resultStatus == 200) {
                //unlink($filename);// everything went better than expected
            } else {
                // the request did not complete as expected. common errors are 4xx
                // (not found, bad request, etc.) and 5xx (usually concerning
                // errors/exceptions in the remote script execution)                                
            }
        }
        curl_close($ch);

        $this->success();
        $this->message("Success.");
        return;

    }

    public function get_calls_wls() {
        $token = get('token', '');
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.DataOtherServicesPatientImages');

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

        $calls = $this->DataOtherServicesCheckIn->find()
        ->select([
            'DataOtherServicesCheckIn.id',
            'DataOtherServicesCheckIn.consultation_uid',
            'DataOtherServicesCheckIn.call_title',
            'DataOtherServicesCheckIn.call_type',
            'DataOtherServicesCheckIn.status',
            'DataOtherServicesCheckIn.call_date',
            'DataOtherServicesCheckIn.call_time',
            'Patient.id',
            'patient_phone' => 'Patient.phone',
            'patient_name' => 'Patient.name',
            'patient_mname' => 'Patient.mname',
            'patient_lname' => 'Patient.lname',
        ])
        ->join([
            'Patient' => [
                'table' => 'sys_users', 
                'type' => 'LEFT', 
                'conditions' => 'Patient.id = DataOtherServicesCheckIn.patient_id'
            ]
        ])
        ->where([
            'DataOtherServicesCheckIn.support_id' => USER_ID,
            'DataOtherServicesCheckIn.deleted' => 0,
            'DataOtherServicesCheckIn.status NOT IN' => ['COMPLETED', 'INCOMPLETED'],
            'DataOtherServicesCheckIn.show' => 1,
            'DataOtherServicesCheckIn.call_type' => 'CHECK IN',
            ])
        ->order(['DataOtherServicesCheckIn.call_date' => 'ASC'])
        ->toArray();

        if (!empty($calls)){
            foreach ($calls as $call) {
                //Format phone number
                $call['patient_phone'] = preg_replace('/(\d{3})(\d{3})(\d{4})/', '($1) $2 $3', $call['patient_phone']);
                
                // Concat date and time
                if (!empty($call->call_time)) {
                    $concat_date_time = $call->call_date . ' ' . date_format($call->call_time, 'h:i A');
                    $call['call_date'] = date('m/d/Y H:i A', strtotime($concat_date_time));
                } else {
                    $call['call_date'] = $call->call_date->i18nFormat('MM/dd/Y');
                }

                //Concat patient name
                if (!empty($call['Patient']['id'])){
                    if (!empty($call['patient_mname']) || $call['patient_mname'] != '') {
                        $call['patient_fname'] = trim($call['patient_name']) . ' ' . trim($call['patient_mname']) . ' ' . trim($call['patient_lname']);
                    } else {
                        $call['patient_fname'] = trim($call['patient_name']) . ' ' . trim($call['patient_lname']);
                    }
                }
                $find_images = $this->DataOtherServicesPatientImages->find()
                ->where([
                    'DataOtherServicesPatientImages.deleted' => 0,
                    'DataOtherServicesPatientImages.consultation_uid' => $call->consultation_uid,
                    'DataOtherServicesPatientImages.checkin_id' => $call['id'],
                    'DataOtherServicesPatientImages.patient_id' => $call['Patient']['id'],
                    ])
                ->first();

                if (!empty($find_images)) {
                    $count_images = explode(',', $find_images->images);
                    
                    $has_images = count($count_images) > 1 ? true : false;
                } else {
                    $has_images = false;
                }

                $arr_calls[] = array(
                    'id' => $call['id'],
                    'consultation_uid' => $call['consultation_uid'],
                    'call_title' => $call['call_title'],
                    'call_type' => $call['call_type'],
                    'service_title' => 'Weight Loss',
                    'status' => $call['status'],
                    'call_date' => $call['call_date'],
                    'patient_phone' => $call['patient_phone'],
                    'patient_fname' => $call['patient_fname'],
                    'has_images' => $has_images,
                );
            }

            $this->set('calls_check_in', $arr_calls);
            $this->success();
            return;

        } else {
            $this->set('calls_check_in', []);
            $this->success();
            return;
        }

    }

    public function checkin_after_48hrs() {
        //Obtener todas las llamadas
        $is_dev = env('IS_DEV', false);

        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $calls = $this->DataOtherServicesCheckIn->find()
        ->where([
            'DataOtherServicesCheckIn.status IN' => ['CLAIMED'],
            'DataOtherServicesCheckIn.deleted' => 0,
            'DataOtherServicesCheckIn.call_type' => 'CHECK IN',
            ])
        ->all();
        $this->set('count_calls', count($calls));
        $current_date = date('m/d/Y H:i');

        foreach ($calls as $call) {
            if ($call->call_date == '') {
                continue;

            } else {
                if (!empty($call->call_time)){
                    $concat_date_time = $call->call_date . ' ' . date_format($call->call_time, 'h:i');
                    $call_date = date('m/d/Y H:i', strtotime($concat_date_time . ' + 48 hours'));
                    if ($call_date >= $current_date) {
                        continue;

                    } else {
                        // $this->set('aqui', 'esta llamada tiene que cambiar'); // FOR TESTING PURPOSES ONLY ⚠️
                        // $this->set('call', $call); // FOR TESTING PURPOSES ONLY ⚠️
                        // return; // FOR TESTING PURPOSES ONLY ⚠️

                        $this->DataOtherServicesCheckIn->updateAll(
                            [
                                'status' => 'COMPLETED',
                            ], 
                            ['id' => $call->id]
                        );

                        //Obtain next call with the same consultation
                        $next_call = $this->DataOtherServicesCheckIn->find()
                        ->where([
                            'DataOtherServicesCheckIn.deleted' => 0,
                            'DataOtherServicesCheckIn.call_type' => 'CHECK IN',
                            'DataOtherServicesCheckIn.consultation_uid' => $call->consultation_uid,
                            'DataOtherServicesCheckIn.patient_id' => $call->patient_id,
                            'DataOtherServicesCheckIn.id !=' => $call->id,
                            ])
                        ->first();

                        if (!empty($next_call)) {
                            $this->DataOtherServicesCheckIn->updateAll(
                                $is_dev ? [
                                    'status' => 'CLAIMED',
                                    'show' => 1,
                                    //Assign to the same specialist
                                    'support_id' => $call->support_id == null || $call->support_id == 0 ? 6461 : $call->support_id,
                                ] : [
                                    'status' => 'CLAIMED',
                                    'show' => 1,
                                    'support_id' => 18837,
                                ],
                                ['id' => $next_call->id]
                            );

                            $update_info = $this->DataOtherServicesCheckIn->find()
                            ->select([
                                'DataOtherServicesCheckIn.id',
                                'DataOtherServicesCheckIn.consultation_uid',
                                'DataOtherServicesCheckIn.call_title',
                                'DataOtherServicesCheckIn.call_type',
                                'DataOtherServicesCheckIn.status',
                                'DataOtherServicesCheckIn.call_date',
                                'DataOtherServicesCheckIn.call_time',
                                'Injector.uid',
                                'Injector.id',
                            ])
                            ->join([
                                'Injector' => [
                                    'table' => 'sys_users', 
                                    'type' => 'LEFT', 
                                    'conditions' => 'Injector.id = DataOtherServicesCheckIn.support_id'
                                ]
                            ])
                            ->where(['DataOtherServicesCheckIn.id' => $next_call->id])
                            ->first();

                            $concat_date_time = $update_info->call_date . ' ' . date_format($update_info->call_time, 'h:i A');
                            $call_date = date('m/d/Y H:i A', strtotime($concat_date_time));

                            # Region update firebase
                                $firebase_update_data = array(
                                    "call_id"           => strval($update_info->id),
                                    "uid"               => $update_info->consultation_uid,
                                    "call_title"        => $update_info->call_title,
                                    "call_type"         => $update_info->call_type,
                                    "status"            => $update_info->status,
                                    "scheduled_date"    => $call_date,
                                    "examiner_uid"      => $update_info['Injector']['uid'],
                                );
                                
                                $update_firebase = $this->update_firebase($firebase_update_data);

                                if(!$update_firebase){
                                    $this->message('Error updating firebase. call_id: ' . $update_info->id);
                                }
                            # End Region update firebase

                            $this->success();
                            continue;
                        }
                    }
                } else {
                    continue;
                }
            }
        }
    }

    
    public function reminder_gage_check_in(){
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        
        $now = date('Y-m-d H:i:s');
        $ent_check_in = $this->DataOtherServicesCheckIn->find()
            ->select([
                //'now1' => '(\''.$now.'\')',                                                                                                         ⚠️ FOR TESTING PURPOSES ONLY
                //'now2' => '(\''.$now.'\' + INTERVAL 10 MINUTE)',                                                                                    ⚠️ FOR TESTING PURPOSES ONLY
                //'call_time_test' => 'CONCAT(DataOtherServicesCheckIn.call_date, " ", DATE_FORMAT(DataOtherServicesCheckIn.call_time, "%H:%i:00"))', ⚠️ FOR TESTING PURPOSES ONLY
                'DataOtherServicesCheckIn.support_id',
                'time' => 'DATE_FORMAT(DataOtherServicesCheckIn.call_time, "%H:%i %p")',
                'Patient.name',
                'Patient.mname',
                'Patient.lname'                                                   
            ])
            ->join([                
                'Patient' => [
                        'table' => 'sys_users', 
                        'type' => 'INNER', 
                        'conditions' => 'Patient.id = DataOtherServicesCheckIn.patient_id'
                ]            
            ])
            ->where([                
                'CONCAT(DataOtherServicesCheckIn.call_date, " ", DATE_FORMAT(DataOtherServicesCheckIn.call_time, "%H:%i:00")) >= \''.$now.'\'',
                'CONCAT(DataOtherServicesCheckIn.call_date, " ", DATE_FORMAT(DataOtherServicesCheckIn.call_time, "%H:%i:00")) <= (\''.$now.'\' + INTERVAL 10 MINUTE)',
                'DataOtherServicesCheckIn.call_time IS NOT NULL',
                'DataOtherServicesCheckIn.status' => 'CLAIMED',
                'DataOtherServicesCheckIn.deleted' => 0,
            ])
            ->all();
        

        // pr($ent_check_in); exit; // FOR TESTING PURPOSES ONLY ⚠️
        
        $Main = new MainController();
        foreach($ent_check_in as $check_in){
            $support_id = $check_in['support_id'];
            $call_date   = $check_in['time'];
            $patient     = !empty($check_in['Patient']["mname"])
                ? $check_in['Patient']['name'] . ' ' . $check_in['Patient']['mname'] . ' ' . $check_in['Patient']['lname']
                : $check_in['Patient']['name'] . ' ' . $check_in['Patient']['lname'];

            $message = "You have a scheduled check-in with $patient today at $call_date, remember to call the patient on time.";

            // pr($message); continue; // FOR TESTING PURPOSES ONLY ⚠️

            $Main->notify_devices(
                $message, 
                array($support_id),
                true,
                false,
                true,     
            );
        }

        // pr('Done'); exit; // FOR TESTING PURPOSES ONLY ⚠️
    }

    public function restart_firebase() {

        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $this->loadModel('SpaLiveV1.DataOtherServicesAnswers');
        $this->loadModel('SpaLiveV1.DataOtherServicesImages');
        
        $consultation_uid = get('consultation_uid', '');

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

        $fields = ['DataConsultationOtherServices.id',
                'DataConsultationOtherServices.uid',
                'DataConsultationOtherServices.patient_id',
                'DataConsultationOtherServices.service_uid',
                'DataConsultationOtherServices.meeting',
                'DataConsultationOtherServices.meeting_pass',
                'DataConsultationOtherServices.join_url',
                'DataConsultationOtherServices.schedule_date',
                'DataConsultationOtherServices.status',
                'DataConsultationOtherServices.notes',
                'DataConsultationOtherServices.current_weight',
                'DataConsultationOtherServices.main_goal_weight',
                'DataConsultationOtherServices.is_waiting',
                'DataConsultationOtherServices.goals',
                'service_title' =>'service.title',
                'user_name'=>'user.name','user_lname'=>'user.lname',
                'user.phone'
            ];        
        //join cosas
        $otherServices = $this->DataConsultationOtherServices->find()
        ->select($fields)
        ->join([            
            'user' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'user.id = DataConsultationOtherServices.patient_id'],
            'service' => ['table' => 'cat_other_services', 'type' => 'LEFT', 'conditions' => 'service.uid = DataConsultationOtherServices.service_uid'],
        ])
        ->where(['DataConsultationOtherServices.uid' => $consultation_uid])->first();

        if(empty($otherServices)){
            $this->message('uid not found.');
            return;
        }
        
        $otherServices['formatted_phone'] = preg_replace('/(\d{3})(\d{3})(\d{4})/', '($1) $2 $3', $otherServices['user']['phone']);
        
        $_POST['uid_service'] = $otherServices->service_uid;
        $_POST['patient_id'] = $otherServices->patient_id;                    
        $ques_resp = $this->get_questions_answer_other_service(true); 
        $questions = Array();
        
        $questions = $ques_resp;
        
        $checkin = $this->DataOtherServicesCheckIn
        ->find()
        ->select()
        ->where([
            'DataOtherServicesCheckIn.consultation_uid' => $consultation_uid, 
            'DataOtherServicesCheckIn.call_number' => 6, 
            'DataOtherServicesCheckIn.call_type' => 'CHECK IN',
        ])
        ->first();
        if (empty($checkin)) {
            $this->message('Data Check in Other Services not found.');
            return;
        }

        /// Actualiza las respuestas del questionario y les pone el id del checkin
        $ent_answers = $this->DataOtherServicesAnswers
        ->find()->where(['DataOtherServicesAnswers.consultation_id' => $otherServices->id])->first();
        $ent_answers->check_in_id = $checkin->id;

        $update = $this->DataOtherServicesAnswers->save($ent_answers);

        if($update){

            $questions = json_decode($ent_answers->data);

            $update_firebase = array(
                "id" => $otherServices->id,
                "uid" => $otherServices->uid,
                "examiner_uid" => "",
                "patient_id" => $otherServices->patient_id,
                "patient_phone" => $otherServices->formatted_phone,
                "service_uid" => $otherServices->service_uid,
                "meeting" => $otherServices->meeting,
                "meeting_pass" => $otherServices->meeting_pass,
                "join_url" => $otherServices->join_url,
                "scheduled_date" => '',
                "status" => "CLAIMED",
                "notes" => $otherServices->notes,
                "is_waiting" => $otherServices->is_waiting,
                "current_weight" => $otherServices->current_weight,
                "main_goal_weight" => $otherServices->main_goal_weight,
                "service_title" => $otherServices->service_title,
                "user_name" => $otherServices->user_name . " " . $otherServices->user_lname,                                
                'date_created' => date('m-d-Y h:i a'),
                'created_by' => USER_ID,
                'questions' => $questions,
                //añadir chekin_id
                'call_id' => strval($checkin->id),
                'call_type' => "CHECK IN",
                'call_title' => 'First Consultation',
                'questions'  => $questions,
                'has_images' => false,
            );

            $this->set('data', $update_firebase);
            $this->set('checkin', $checkin);
            
            //$firebase_document = substr($name, strpos($name, "services/") + 9);*/
            $update_firebase = $this->update_firebase($update_firebase);

            if(!$update_firebase){
                $this->message('Error updating firebase. consultation_uid: ' . $consultation_uid);
            }
        }else{
            $this->message('Error updating firebase. consultation_uid: ' . $consultation_uid);
        }
    }

    public function create_free_pay_wl(){
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $Pay = new PaymentsController();
        
        $user_uid = get('user_uid', '');

        if (empty($user_uid)) {
            $this->message('User uid empty.');
            return;
        }

        $this->loadModel('SpaLiveV1.SysUsers');
        $user = $this->SysUsers->find()
            ->where(['SysUsers.uid' => $user_uid])
            ->first();

        if (empty($user)) {
            $this->message('User not found.');
            return;
        }

        $createdby = 1;
        $patient_id = $user->id;

        $consultation_uid = Text::uuid();

        $payment_id = 'free_' . $consultation_uid;
        $paymen_intent = 'free_' . $consultation_uid;

        $array_save = array(
            'uid' => $consultation_uid,
            'patient_id' => $patient_id,
            'assistance_id' => 0,
            'service_uid' => '1q2we3-r4t5y6-7ui8o990',
            'payment' => $payment_id,
            'payment_method' => '',
            'payment_intent' => $paymen_intent,
            'meeting' => '',
            'meeting_pass' => '',
            'schedule_date' => date('Y-m-d H:i:s'),
            'status' => "PAID",
            'promo_code' =>  '',
            'schedule_by' => 1,
            'deleted' => 0,
            'participants' => 0,
            'createdby' => $createdby,
            'monthly_payment' => '3 MONTHS',
            'goals' => '',
            'created' => date('Y-m-d H:i:s'),
        );

        $c_entity = $this->DataConsultationOtherServices->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            $this->DataConsultationOtherServices->save($c_entity);
        }

        $total_amount = 0;
        
        $Pay->createPaymentRegister('WEIGHT LOSS', $patient_id, 0, $consultation_uid, $payment_id, $payment_id, $payment_id, $total_amount, $total_amount);

        $tentative_date = date('Y-m-d');
        $flag_purchase = true;
        $purchase_id = 0;
        $call_number = 1;
        $this->loadModel('SpaLiveV1.DataPurchases');
        $this->loadModel('SpaLiveV1.DataPurchasesOtherServices');
        for ($i = 0; $i < 3; $i++) {
            $total_pur = 0;
            $show_pur = 1;
            $call_type = 'FIRST CONSULTATION';
            $purchaseUid = $consultation_uid;

            if($i<1){
                $total_pur = $total_amount;
            }else{
                if($i<2){
                    $call_number++;
                }else{
                    $call_number = $call_number * 2;
                }

                $call_type = 'CHECK IN';
                $show_pur = 0;
                //$tentative_date = date('Y-m-d', strtotime($tentative_date . ' +30 day'));
                $purchaseUid = Text::uuid();
            }

            $array_purchases = array(
                    'uid' => $purchaseUid,
                    'user_id' => $patient_id,
                    'status' => 'NEW',
                    'payment' =>  $payment_id,
                    'payment_intent' => $paymen_intent,
                    'amount' => $total_pur,
                    'status' => 'WAITING TO RECEIVE THE PRODUCT',
                    'shipping_date' => $tentative_date,
                    'deleted' => 0,
                    'call_type' => $call_type,
                    'call_number' => $call_number,
                    'created' => date('Y-m-d H:i:s'),
                    'show' => $show_pur
            );

            $c_entity_p = $this->DataPurchases->newEntity($array_purchases);

            $array_purchases_os = array(
                'uid' => $purchaseUid,
                'user_id' => $patient_id,
                'consultation_uid' => $consultation_uid,
                'status' => 'NEW',
                'payment' =>  $payment_id,
                'payment_intent' => $paymen_intent,
                'amount' => $total_pur,
                'status' => 'WAITING TO RECEIVE THE PRODUCT',
                'shipping_date' => $tentative_date,
                'deleted' => 0,
                'call_type' => $call_type,
                'call_number' => $call_number,
                'created' => date('Y-m-d'),
                'show' => $show_pur
            );
            
            $c_entity_p_os = $this->DataPurchasesOtherServices->newEntity($array_purchases_os);
            
            if ((!$this->DataPurchases->save($c_entity_p))||(!$this->DataPurchasesOtherServices->save($c_entity_p_os))) {
                $flag_purchase = false;
                break;
            }else{
                if($i<1){
                    $purchase_id = $c_entity_p->id;
                }
            }
        }

        $this->loadModel('SpaLiveV1.DataOtherServices');
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');

        if ($flag_purchase) {
            // Guarda la tabla "other services"
            $array_save = array(
                'uid' => Text::uuid(),
                'consultation_uid' => $consultation_uid,
                'date_start' => date('Y-m-d'),
                'date_expiration' => date('Y-m-d', strtotime(date('Y-m-d') . ' +90 days')),
                'deleted' => 0,
                'createdby' => 1,
                'created' => date('Y-m-d H:i:s'),
            );
            $c_entity = $this->DataOtherServices->newEntity($array_save);
            $ent_saved = $this->DataOtherServices->save($c_entity);

            if ($ent_saved) {
                $this->set('data_other_services', $ent_saved);
                // Guarda la tabla "check in"
                $arr_save = array(
                    'uid' => Text::uuid(),
                    'consultation_uid' => $consultation_uid,
                    'patient_id' => $patient_id,
                    'call_date' => date('Y-m-d'),
                    'status' => 'INCOMPLETED',
                    'call_number' => 1,
                    'call_type' => 'FIRST CONSULTATION',
                    'call_title' => 'First Consultation',
                    'show' => 1,
                    'created' => date('Y-m-d H:i:s'),
                    'purchase_id' => $purchase_id,
                );
                $cq_entity_first_consultation = $this->DataOtherServicesCheckIn->newEntity($arr_save);
                $ent_saved_checkin = $this->DataOtherServicesCheckIn->save($cq_entity_first_consultation);

                if($ent_saved_checkin){
                    $this->loadModel('SpaLiveV1.DataUsersFreeWl');
                    $array_save = array(
                        'user_id' => $patient_id,
                        'deleted' => 0,
                        'created' => date('Y-m-d H:i:s'),
                    );
                    $c_entity = $this->DataUsersFreeWl->newEntity($array_save);
                    $ent_saved = $this->DataUsersFreeWl->save($c_entity);

                    $this->set('data_checkin', $ent_saved_checkin);
                    $this->success();
                }
            }
        }else{
            $this->message('Purchases not created.');
            return;
        }
    }
}