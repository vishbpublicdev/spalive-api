<?php
declare(strict_types=1);

namespace SpaLiveV1\Controller;
use App\Controller\AppPluginController;
use Cake\Core\Configure;
use Cake\Utility\Security;
use Cake\Utility\Text;
use CURLFile;
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

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

use SpaLiveV1\Controller\SubscriptionController;
use SpaLiveV1\Controller\TherapyController;
class CourseController extends AppPluginController {
     
    private $total = 3900;
    private $paymente_gfe = 1800;
    private $register_total = 79500;
    private $register_refund = 3500;
    private $shipping_cost = 1000;
    private $shipping_cost_both = 3000;
    private $shipping_cost_inj = 2000;
    private $shipping_cost_mat = 1000;
    private $shipping_cost_misc = 1000;
    private $training_basic = 79500;
    private $training_advanced = 89500;
    private $emergencyPhone = "9035301512";
    private $total_subscriptionmsl = 3995;
    private $total_subscriptionmd = 17900;
    private $URL_API = "";
    private $URL_WEB = "";
    private $URL_ASSETS = "";
    private $URL_PANEL = "";
    private $str_name;

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

    public function get_schools(){
        $this->loadModel('SpaLiveV1.DataSchoolRegister');
        $this->loadModel('SpaLiveV1.DataCourses');

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

        $_join = [
            'Cat' => ['table' => 'cat_courses','type' => 'LEFT','conditions' => 'Cat.id = DataCourses.course_id'],
        ];

        $ent_data = $this->DataCourses->find()
                        ->select(['DataCourses.course_id'])
                        ->where(['DataCourses.user_id' => $user['user_id'], 'DataCourses.deleted' => 0, 'Cat.deleted' => 0])->join($_join)
                        ->all();

        $courses_id = [];
        
        if(count($ent_data)>0){

           foreach ($ent_data as $d_c) {
                $courses_id[] = $d_c->course_id;
            }
        }
        // else{
        //     $courses_id = [-1];
        // }

        $_join = [
            'Cat' => ['table' => 'cat_courses','type' => 'LEFT','conditions' => 'Cat.school_id = DataSchoolRegister.id'],
        ];

        $where = ['DataSchoolRegister.status' => 'Active', 'DataSchoolRegister.deleted' => 0, 'Cat.deleted' => 0];
        if(count($courses_id)>0){
            $where["Cat.id NOT IN"] = $courses_id;
        }
        
        $ent_schools = $this->DataSchoolRegister
            ->find()
            //->find('all', array('conditions' => array('Cat.id NOT IN' => $courses_id),))
            ->select(['DataSchoolRegister.id', 'DataSchoolRegister.uid', 'DataSchoolRegister.nameschool'])
            ->where($where)->join($_join)
            ->group(['DataSchoolRegister.id'])->all();

        if(!$ent_schools){
            $ent_schools = [];

        }else{
            
            foreach ($ent_schools as $row) {
                    $row['is_selected'] = false;
            }

        }
        // if(Count($ent_schools) == 0) {
        //     $this->message('There are no schools available at this time.');
        //     return;
        // }

        // $this->message('There are no schools available at this time.');
        // return;

        $courses_ot = $this->get_courses_ot();

        $this->set('other_courses', $courses_ot);
        $this->set('data', $ent_schools);
        $this->success();
    }

    private function get_courses_ot(){
        $this->loadModel('SpaLiveV1.CatCoursesType');

        $trainings_type = $this->CatCoursesType->find()->where(['CatCoursesType.deleted' => 0, 'CatCoursesType.available' => 1])->all();

        if(count($trainings_type) == 0){
            return [];
        }

        $this->loadModel('SpaLiveV1.CatTrainings');
        $this->loadModel('SpaLiveV1.CatProducts');
        $data_trainings = [];

        // valida si hay stock de fillers y si hay stock lo agrega a la lista
        $fillers = $this->CatProducts->find()->where(['CatProducts.id' => 178, 'CatProducts.deleted' => 0, 'CatProducts.stock' => 1])->first();
        if(!empty($fillers)){
            $data_trainings[] = [
                'training_id' => 0,
                'treatment_id' => 0,
                'name' => 'Foundations in Aesthetic Filler Techniques',
                'price' => $fillers->unit_price / 100,
                'cross_price' => $fillers->unit_price / 100,
                'action' => 'Login____info_advanced_level_three'
            ];
        }
        
        foreach($trainings_type as $t){
            // $training = $this->CatTrainings->find()->where(['CatTrainings.level' => $t->title, 'CatTrainings.deleted' => 0])->first();

            if ($t->require_msl_basic == 1) {
                
               $hasBasicCourse = CourseController::validateBasicTraining($this);
               if (!$hasBasicCourse) {
                continue;
               }
            }

                $data_trainings[] = [
                    'course_type_id' => $t->id,
                    'treatment_id' => $t->id,
                    'name' => $t->title,
                    'price' => $t->price / 100,
                    'cross_price' => $t->price / 100,
                    'action' => 'OtherTreatments____get_info_course_ot'
                ];
            
            
        }


        return $data_trainings;
    }

    public static function validateBasicTraining($ref) {
        $ref->loadModel('SpaLiveV1.DataTrainings');
        $ref->loadModel('SpaLiveV1.DataCourses');

        $level1 = $ref->DataTrainings->find()
            ->join(['Cat' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'Cat.id = DataTrainings.training_id']])
            ->where(['DataTrainings.user_id' => USER_ID, 'DataTrainings.deleted' => 0, 'Cat.level IN' => array('LEVEL 1','MYSPALIVE_S_HYBRID_TOX_FILLER_COURSE','MYSPALIVES_HYBRID_TOX_FILLER_COURSE'), 'Cat.deleted' => 0])
            ->first();

        if(!empty($level1)) return true; 


        $user_course_basic = $ref->DataCourses->find()->select(['CatCourses.type'])->join([
        'CatCourses' => ['table' => 'cat_courses', 'type' => 'INNER', 'conditions' => 'CatCourses.id = DataCourses.course_id'],
        ])->where(['CatCourses.type IN' => array('NEUROTOXINS BASIC', 'BOTH NEUROTOXINS'),'DataCourses.user_id' => USER_ID,'DataCourses.deleted' => 0,'DataCourses.status' => 'DONE'])->first();

        if(!empty($user_course_basic)) return true;

        return false;
    }

    public function licence_types(){
        $token = get('token', "");

        // 👇 validacion para mostrar las nuevas licencias
        $with_other_treatments_licences = get('with_other_treatments_licences', 0);

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

        // Base de licencias
        $elements = array(
            array('value' => 'RN', 'isSelected' => false),
            array('value' => 'NP', 'isSelected' => false),
            array('value' => 'PA', 'isSelected' => false),
            array('value' => 'MD', 'isSelected' => false),
          
        );

        //👇 si mandan with_other_treatments_licences = 1 añade extras
        if ($with_other_treatments_licences) {
            $extra = array(
                array('value' => 'Esthetician',             'isSelected' => false),
                array('value' => 'Cosmetology/Esthetician', 'isSelected' => false),
                array('value' => 'LVN', 'isSelected' => false),
            );
            $elements = array_merge($extra, $elements);
        }

        $this->set('data', $elements);
        $this->success();
        return;
    }

    public function add_licence(){
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

        $type = get('type',"");
        if(empty($type)){
            $this->message('Empty type name.');
            return;
        }

        $state = get('state',"");
        if(empty($state)){
            $this->message('Empty state name.');
            return;
        }

        $number = get('number',0);
        if(empty($number)){
            $this->message('Empty number name.');
            return;
        }

        $start_date = get('start_date',"");
        if(empty($start_date)){
            $this->message('Empty start_date name.');
            return;
        }

        $exp_date = get('exp_date',"");
        if(empty($exp_date)){
            $this->message('Empty exp_date name.');
            return;
        }

        $front = get('front',0);
        if(empty($front)){
            $this->message('Empty front name.');
            return;
        }
        
        $back = get('back', 0);

        $isDev = env('IS_DEV', false);

        $this->loadModel('SpaLiveV1.SysLicence');
        $this->loadModel('SpaLiveV1.SysUsers');
        
        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID, 'SysUsers.deleted' => 0])->first();

        $array_save = array(
            'user_id' => USER_ID,
            'type' => $type,
            'state' => $ent_user->state,
            'number' => $number,
            'start_date' => $start_date,
            'exp_date' => $exp_date,
            'front' => $front,
            'back' => $back,
            'deleted' => 0,
            'status' => "PENDING",
        );

        $ent = $this->SysLicence->newEntity($array_save);
        if(!$ent->hasErrors()){
            $entsve = $this->SysLicence->save($ent);
            $this->set('data', $entsve);
            $this->success();
            //save first attempt other course
            $this->loadModel('SpaLiveV1.DataInjectorRegistered');
            $exist = $this->DataInjectorRegistered->find()->where([
                'DataInjectorRegistered.user_id' => USER_ID ,
                'DataInjectorRegistered.deleted' => 0 ,
            ])->first();

            $html_content =
                '<p><span  style="font-weight: bold;">Name:</span> ' . USER_NAME .' '. USER_LNAME .'<br>'.
                '<span  style="font-weight: bold;">Email:</span> ' .USER_EMAIL .'<br>'.                                
                '<span  style="font-weight: bold;">Phone:</span> ' .USER_PHONE .'<br></p>';

            $data=array(
                'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
                'to'      => $isDev ? 'francisco@advantedigital.com' : 'jennaleighbichler@gmail.com',
                'subject' => 'An injector is waiting for a license approval',
                'html'    => $html_content,
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
            return;
        }
        
    }

    public function update_status_licence(){
        
        $id_licence = get('id_licence',0);
        if(empty($id_licence)){
            $this->message('Empty id_licence.');
            return;
        }

        $status = get('status',0);
        if(empty($status)){
            $this->message('Empty status.');
            return;
        }

        $this->loadModel('SpaLiveV1.SysLicence');

        $this->SysLicence->updateAll(
            ['status' => $status],
            ['id' => $id_licence]
        );

        $this->success();
    }

    public function add_schools(){
        $token = get('token',"");
        $isDev = env('IS_DEV', false);
        
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

        $school_name = get('school_name',"");
        if(empty($school_name)){
            $this->message('Empty school name.');
            return;
        }

        $address = get('address',"");
        if(empty($address)){
            $this->message('Empty address.');
            return;
        }

        $city = get('city',"");
        if(empty($city)){
            $this->message('Empty city.');
            return;
        }
        
        $state = get('state', 0);
        if(empty($state)){
            $this->message('Empty state.');
            return;
        }


        $web = get('web',"");
        if(empty($web)){
            $this->message('Empty web.');
            return;
        }

        $phone = get('phone',"");
        if(empty($phone)){
            $this->message('Empty phone.');
            return;
        }

        $certificate = get('certificate',"");
        if(empty($certificate)){
            $this->message('Empty certificate.');
            return;
        }

        $issued = get('issued',"");
        if(empty($issued)){
            $this->message('Empty issued.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataSchoolRegister');
        $this->loadModel('SpaLiveV1.DataValidateCourse');
        
        $this->loadModel('SpaLiveV1.SysUsers');
        

        $array_save = array(
            'uid' => Text::uuid(),
            'nameschool' => $school_name,
            'schoolweb' => $web,
            'schoolphone' => $phone,
            'address' => $address,
            'city' => $city,
            'id_state' => $state,
            'certifications' => $certificate,
            'deleted' => 0,
            'status' => 'Pending',
            'issued' => strtotime($issued),
            'created' => date('Y-m-d H:i:s'),
            
        );

        $ent = $this->DataSchoolRegister->newEntity($array_save);
        if(!$ent->hasErrors()){
            $entsve = $this->DataSchoolRegister->save($ent);

            $nameschool = $entsve['nameschool'];
            $certificate = $entsve['certifications'];
            $key1 = Text::uuid();
                        
            $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID, 'SysUsers.deleted' => 0])->first();

            $array_save_validate = array(
                'data_course_id' => $entsve->id,
                'key1' => $key1,
                'active' => 1,
                'user_id' => USER_ID
            );

            $c_entity = $this->DataValidateCourse->newEntity($array_save_validate);
            if(!$c_entity->hasErrors()) {
                $this->DataValidateCourse->save($c_entity);    
            }

        }

        if (strpos(strtolower(USER_NAME), 'test') === false || strpos(strtolower(USER_LNAME), 'test') === false) {
            if(!$isDev){
                try {           
                    $sid    = env('TWILIO_ACCOUNT_SID'); 
                    $token  = env('TWILIO_AUTH_TOKEN');  
                    $twilio = new Client($sid, $token); 
                        
                    $message = $twilio->messages 
                        ->create( '+1' . '9034366629', // to 
                                array(  
                                    "messagingServiceSid" => "MG65978a5932f4ba9dd465e05d7b22195e",      
                                    "body" => 'This user wants a new school: ' . USER_NAME . ' ' . USER_LNAME . ' (' . USER_PHONE .')'
                                ) 
                        ); 
                } catch (TwilioException $e) {

                }

                $Main = new MainController();
                $Main->notify_devices('This user wants a new school: ' . USER_NAME . ' ' . USER_LNAME . ' (' . USER_PHONE .')',array(6101),true,true,true,array(),'',array(),true); // 6101 ID user Jenna in live
            }
        }

        $this->set('data', $entsve);
        $this->success();
        return;
    }

    public function edit_school(){
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

        $school_id = get('school_id',"");
        if(empty($school_id)){
            $this->message('Empty school id.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataSchoolRegister');

        $array_save = array(
            'id' => $school_id,
            'status' => 'Active'            
        );

        $ent = $this->DataSchoolRegister->newEntity($array_save);
        if(!$ent->hasErrors()){
            $entsve = $this->DataSchoolRegister->save($ent);
        }

        $this->set('data', $entsve);
        $this->success();
        return;
    }

    public function get_courses_schools() {
        $this->loadModel('SpaLiveV1.CatCourses');
        $this->loadModel('SpaLiveV1.DataCourses');

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

        $school_id = get('school_id', '');

        //pr($school_id); exit;

        if($school_id == ''){
            $this->message('Invalid school.');
            return;
        }

        $_join = [
            'Cat' => ['table' => 'cat_courses','type' => 'LEFT','conditions' => 'Cat.id = DataCourses.course_id'],
        ];

        /* $ent_data = $this->DataCourses->find()
                        ->select(['DataCourses.course_id'])
                        ->where(['DataCourses.user_id' => $user["user_id"], 'DataCourses.deleted' => 0])->join($_join)
                        ->all();

        $courses_id = [];
        
        if(count($ent_data)>0){

           foreach ($ent_data as $d_c) {
                $courses_id[] = $d_c->course_id;
            }
        } */

        $where = ['CatCourses.school_id' => $school_id, 'CatCourses.deleted' => 0];
        /* if(count($courses_id)>0){
            $where["CatCourses.id NOT IN"] = $courses_id;
        } */

        $ent_courses = $this->CatCourses->find()
        ->select(['CatCourses.id', 'CatCourses.title', 'CatCourses.type', 'CatCourses.price'])
        ->where($where)
        ->all();

        if(count($ent_courses) == 0){
            $this->message('There are no courses available at this time.');
            return;
        }

        $this->set('data', $ent_courses);
        $this->success();
    }

    public function get_courses_schools2() {
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

        $school_id = get('school_id', '');

        if($school_id == ''){
            $this->message('Invalid school.');
            return;
        }

        $this->loadModel('SpaLiveV1.CatCourses');
        $this->loadModel('SpaLiveV1.DataCourses');

        // $ent_courses = $this->CatCourses->find()
        // ->select(['CatCourses.id', 'CatCourses.title', 'CatCourses.type', 'CatCourses.price'])
        // ->where(['CatCourses.school_id' => $school_id, 'CatCourses.deleted' => 0])
        // ->all();

        // if(Count($ent_courses) == 0){
        //     $this->message('There are no courses available at this time.');
        //     return;
        // }

        $ent_courses = $this->CatCourses->find()
            ->select(['CatCourses.id', 'CatCourses.title', 'CatCourses.type', 'CatCourses.price'])
            ->where(['CatCourses.school_id' => $school_id, 'CatCourses.deleted' => 0])
            ->all();
    
        if (count($ent_courses) == 0) {
            $this->message('There are no courses available at this time.');
            return;
        }
        
        $ent_dc = $this->DataCourses->find()
        ->select(['course_id'])
        ->where(['user_id' => USER_ID, 'deleted' => 0])
        ->all(); 

        $result = [];
        if($ent_dc){
            foreach ($ent_courses as $courses) {
                $courseId = $courses['id'];
                
                foreach ($ent_dc as $dataCourse) {
                //     $this->set('data', $dataCourse->course_id);
                // return;
                    if ($dataCourse['course_id'] != $courseId) {
                        $result[] = [
                            'id' => $courses['id'],
                            'title' => $courses['title'],
                            'type' => $courses['type'],
                            'price' => $courses['price']
                        ];
                    }
                }
            }
            $this->set('data', $result);
            return;
        }

        $this->set('data', $ent_courses);
        $this->success();
    }
    
    public function get_training() {
        $this->loadModel('SpaLiveV1.CatTrainigs');
        $this->loadModel('SpaLiveV1.CatCoursesType');
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

        $training_id = get('training_id', '');

        $_fields = ['CatTrainigs.id', 'CatTrainigs.title', 'CatTrainigs.scheduled', 'CatTrainigs.neurotoxins', 'CatTrainigs.fillers', 'CatTrainigs.materials', 'CatTrainigs.available_seats', 'CatTrainigs.level','State.name','State.abv','CatTrainigs.address','CatTrainigs.zip','CatTrainigs.city', 'data_training_id' => 'DataTrainigs.id'];
        $_fields['assistants'] = "(SELECT COUNT(id) from data_trainings DT WHERE DT.training_id = CatTrainigs.id AND DT.deleted = 0)";
        $_fields['enrolled'] = "(SELECT COUNT(id) from data_trainings DT WHERE DT.training_id = CatTrainigs.id AND DT.deleted = 0 AND DT.user_id = " . USER_ID . " )";
        $_join = [
                'DataTrainigs' => ['table' => 'data_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainigs.training_id'],
                'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = CatTrainigs.state_id']
            ];
        $_where = ['DataTrainigs.user_id' => USER_ID, 
                   'DataTrainigs.deleted' => 0, 
                   'CatTrainigs.deleted' => 0,                   
                   'CatTrainigs.id' => $training_id];
        
        $training = $this->CatTrainigs->find()
                                      ->select($_fields)
                                      ->join($_join)
                                      ->where($_where)
                                      ->first();

        $course_type = $this->CatCoursesType->find()
            ->where(['CatCoursesType.name_key' => $training['level']])
            ->first();
        $api_url = env('URL_API', 'https://api.myspalive.com/');
        if(!empty($course_type)){
            $materials = json_decode($course_type->materials, true);
            if(!empty($materials)){
                foreach($materials as $material){
                    if (isset($material['type']) && $material['type'] == 'video') {
                        $data[] = [
                            'title' => $material['name'],
                            'url' => $material['url'],
                            'type' => 'video'
                        ];
                    } else {
                        $data[] = [
                        'title' => $material['name'],
                        'url' => $api_url . '?key=2fe548d5ae881ccfbe2be3f6237d7951&l3n4p=6092482f7ce858.91169218&action=get-file&token=6092482f7ce858.91169218&id=' . $material['id'],
                        'type' => 'pdf'
                    ];
                    }
                }
            }else{
                $data = [];
            }
        }else{
            $data = array(
                [
                    'title' => 'MySpaLive video',
                    'url' => Configure::read('App.wordpress_domain') .'myspa.mp4',
                    'type' => 'video'
                ],
                [
                    'title' => 'How to use the App',
                    'url' => Configure::read('App.wordpress_domain') .'wp-content/uploads/howtouse.mp4',
                    'type' => 'video'
                ]
            );
        }

        if(!empty($training)){
            $address = $training->address.', '.$training->city.', '.$training->State['abv'].' '.$training->zip;
            $res = array(
                'id' => $training['id'],
                'title' => $training['title'],
                'scheduled' => $training['scheduled']->i18nFormat('yyyy-MM-dd hh:mm a'),
                'address' => $address,
                'level' => $training['level'],
                'data_training_id' => $training['data_training_id']
            );

            $date = date('Y-m-d H:i:s');
            $scheduled = $training['scheduled']->i18nFormat('yyyy-MM-dd 09:00:00');

            if($date > $scheduled){
                $this->loadModel('SpaLiveV1.SysUsers');
                $this->SysUsers->updateAll(
                    ['steps' => 'MSLSUBSCRIPTION'],
                    ['id' => USER_ID]
                );
                $this->set('coursed', true);
            }else{
                $this->set('coursed', false);
            }

            $this->set('training', $res);
            $this->set('data', $data);

            $this->success();
        }
    } 

    public function get_default_training() {
        $this->loadModel('SpaLiveV1.CatTrainigs');
        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.DataConsultation');
        $this->loadModel('SpaLiveV1.CatCoursesType');
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

        $_fields = ['CatTrainigs.id', 'CatTrainigs.title', 'CatTrainigs.scheduled', 'CatTrainigs.neurotoxins', 'CatTrainigs.fillers', 'CatTrainigs.materials', 'CatTrainigs.available_seats', 'CatTrainigs.level','State.name','State.abv','CatTrainigs.address','CatTrainigs.zip','CatTrainigs.city', 'data_training_id' => 'DataTrainigs.id'];
        $_fields['assistants'] = "(SELECT COUNT(id) from data_trainings DT WHERE DT.training_id = CatTrainigs.id AND DT.deleted = 0)";
        $_fields['enrolled'] = "(SELECT COUNT(id) from data_trainings DT WHERE DT.training_id = CatTrainigs.id AND DT.deleted = 0 AND DT.user_id = " . USER_ID . " )";
        $_fields['attended'] = "(SELECT DT.attended from data_trainings DT WHERE DT.training_id = CatTrainigs.id AND DT.deleted = 0 AND DT.user_id = " . USER_ID . " )";
        $_join = [
                'DataTrainigs' => ['table' => 'data_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainigs.training_id'],
                'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = CatTrainigs.state_id']
            ];
        $_where = ['DataTrainigs.user_id' => USER_ID, 
                   'DataTrainigs.deleted' => 0, 
                   'CatTrainigs.deleted' => 0,                   
                   'CatTrainigs.level IN ("LEVEL 1")'];
        // basic course
        $training = $this->CatTrainigs->find()
                                      ->select($_fields)
                                      ->join($_join)
                                      ->where($_where)
                                      ->first();

        // fillers course
        $_where = ['DataTrainigs.user_id' => USER_ID, 
                   'DataTrainigs.deleted' => 0, 
                   'CatTrainigs.deleted' => 0,                   
                   'CatTrainigs.level IN ("LEVEL 3 FILLERS")'];
        
        $training_fillers = $this->CatTrainigs->find()
                                      ->select($_fields)
                                      ->join($_join)
                                      ->where($_where)
                                      ->first();

        // Obtener niveles dinámicos disponibles
        $dynamic_levels = $this->CatCoursesType->find()
            ->where(['CatCoursesType.deleted' => 0, 'CatCoursesType.available' => 1])
            ->all();

        // Buscar entrenamientos dinámicos del usuario
        $ent_training_dynamic = array();
        $api_url = env('URL_API', 'https://api.myspalive.com/');
        
        foreach($dynamic_levels as $dynamic_level) {
            $_where_dynamic = [
                'DataTrainigs.user_id' => USER_ID,
                'DataTrainigs.deleted' => 0,
                'CatTrainigs.deleted' => 0,
                'CatTrainigs.level' => $dynamic_level->name_key
            ];
            
            $dynamic_trainings = $this->CatTrainigs->find()
                ->select($_fields)
                ->join($_join)
                ->where($_where_dynamic)
                ->all();
            
            foreach($dynamic_trainings as $training) {
                $training->dynamic_level_info = $dynamic_level;
                $ent_training_dynamic[] = $training;
            }
        }


        if(!empty($training) || !empty($ent_training_dynamic) || !empty($training_fillers)){

            if(!empty($training)){
                $address = $training->address.', '.$training->city.', '.$training->State['abv'].' '.$training->zip;
                $res = array(
                    'id'            => $training['id'],
                    'title'         => $training['title'],
                    'scheduled'   => $training['scheduled']->i18nFormat('yyyy-MM-dd hh:mm a'),
                    'address' => $address,
                    'level' => $training['level'],
                    'data_training_id' => $training['data_training_id'],
                    'attended' => $training['attended'],    
                );

                $data = array(
                    [
                        'title' => 'MySpaLive video',
                        'url' => Configure::read('App.wordpress_domain') . '/myspa.mp4',
                        'type' => 'video'
                    ],
                    /* [
                        'title' => 'Ideals of Beauty and Methods of Body Modification',
                        'url' =>
                            Configure::read('App.wordpress_domain') . '/wp-content/uploads/2022/08/Ideals-of-Beauty-and-Methods-of-Body-Modification.ppt',
                        'type' => 'download'
                    ],
                    [
                        'title' => 'Ordering and Storage Guide With Pi',
                        'url' =>
                            Configure::read('App.wordpress_domain') . '/wp-content/uploads/2022/08/ordering_and_storage_guide_with_pi.pdf',
                        'type' => 'pdf'
                    ],
                    [
                        'title' => 'Evolus link',
                        'url' =>
                            'https://www.evolus.com/rewards/',
                        'type' => 'link'
                    ], */
                    [
                        'title' => 'How to use the App',
                        'url' => Configure::read('App.wordpress_domain') . '/wp-content/uploads/howtouse.mp4',
                        'type' => 'video'
                    ]
                );
            }

            // Procesar entrenamientos dinámicos
            foreach($ent_training_dynamic as $training_dynamic) {
                $address = $training_dynamic->address.', '.$training_dynamic->city.', '.$training_dynamic->State['abv'].' '.$training_dynamic->zip;
                $date = date('Y-m-d H:i:s');
                $scheduled = $training_dynamic['scheduled']->i18nFormat('yyyy-MM-dd 08:00:00');
                
                if ($training_dynamic['attended'] == "0") {
                    // Procesar materiales dinámicos
                    $materials_ids = json_decode($training_dynamic->dynamic_level_info->materials, true);
                    $dynamic_materials = [];
                    if(!empty($materials_ids)) {
                        foreach($materials_ids as $material_id) {
                            $dynamic_materials[] = [
                                'title' => 'Study material',
                                'url' => $api_url . 'key=2fe548d5ae881ccfbe2be3f6237d7951&l3n4p=6092482f7ce858.91169218&action=get-file&token=6092482f7ce858.91169218&id=' . $material_id['id'],
                                'type' => 'pdf'
                            ];
                        }
                    }
                    
                    $data[] = array(
                        'id' => $training_dynamic['id'],
                        'title' => $training_dynamic['title'],
                        'scheduled' => $training_dynamic['scheduled']->i18nFormat('yyyy-MM-dd hh:mm a'),
                        'address' => $address,
                        'level' => $training_dynamic['level'],
                        'data_training_id' => $training_dynamic['data_training_id'],
                        'attended' => $training_dynamic['attended'],
                        'show_cancel' => $date > $scheduled ? false : true,
                        'data' => $dynamic_materials
                    );
                }
            }

            // fillers course
            if(!empty($training_fillers)){
                $address = $training_fillers->address.', '.$training_fillers->city.', '.$training_fillers->State['abv'].' '.$training_fillers->zip;
                $res = array(
                    'id' => $training_fillers['id'],
                    'title' => $training_fillers['title'],
                    'scheduled' => $training_fillers['scheduled']->i18nFormat('yyyy-MM-dd hh:mm a'),
                    'address' => $address,
                    'level' => $training_fillers['level'],
                    'data_training_id' => $training_fillers['data_training_id'],
                    'attended' => $training_fillers['attended'],
                );
            }

            $this->set('training', $res);
            
            $date = date('Y-m-d H:i:s');
            if(!empty($training)){
                $scheduled = $training['scheduled']->i18nFormat('yyyy-MM-dd 08:00:00');
            }else{
                $scheduled = $training_fillers['scheduled']->i18nFormat('yyyy-MM-dd 08:00:00');
            }

            $ent_certificate = $this->DataConsultation->find()->select(['DataConsultation.id', 'DataConsultation.status', 'DC.proceed', 'DataConsultation.uid'])
            ->join([
                'DC' => ['table' => 'data_consultation_plan', 'type' => 'LEFT', 'conditions' => 'DC.consultation_id = DataConsultation.id AND DC.deleted = 0'],
            ])
            ->where(['DataConsultation.patient_id' => USER_ID, 'DataConsultation.status IN ' => array('DONE', 'CERTIFICATE'), 'DataConsultation.deleted' => 0])->first();

            $certificate = false;
            $showButton = true;
            $valid_certificate = false;
            $this->set('message_gfe', '');
            $this->set('cert_status', 'PENDING');


            if(!empty($ent_certificate)){
                if($ent_certificate['status'] == 'DONE'){
                    $certificate = false;
                    $showButton = false;
                    $this->set('status', 'Waiting for the Examiner to give you the certificate.'); // DELETE AFTER FEB
                    $this->set('message_gfe', 'Waiting for the Examiner to give you the certificate.');
                    $this->set('cert_status', 'WAITING');                    
                }else if($ent_certificate['status'] == 'CERTIFICATE') {
                    $certificate = true;
                    $showButton = false;
                    if($ent_certificate['DC']['proceed'] == 1){
                        $this->set('status', 'Certificate Received.'); // DELETE AFTER FEB
                        $this->set('message_gfe', 'Certificate Received.');
                        $this->set('cert_uid', $ent_certificate['uid']);
                        $this->set('cert_status', 'DONE');
                        $valid_certificate = true;      
                    }else {
                        $this->set('status', 'Certificate Denied.'); // DELETE AFTER FEB
                        $this->set('message_gfe', 'Certificate Denied.');
                        $this->set('cert_status', 'DONE');
                        $valid_certificate = true;  
                    }
                }
            }else {
                $certificate = false;
            }
            $this->set('show_gfe_button', $showButton);            
            if(!empty($training)){
                $this->set('show_assistance_code', $valid_certificate && $training['scheduled']->i18nFormat('yyyy-MM-dd') <= date('Y-m-d'));    

                if($training['scheduled']->i18nFormat('yyyy-MM-dd') == date('Y-m-d') && $certificate){
                    $this->set('cert_status', 'CONTINUE');
                }

                if(!empty($training)){
                    $this->set('today', false);
                } else {
                    if($training['scheduled']->i18nFormat('yyyy-MM-dd') == date('Y-m-d')){
                        $this->set('today', true);
                    }else{
                        $this->set('today', false);
                    }
                }

                $this->set('data', $data);
            }

            if($date > $scheduled && $certificate){
                $this->loadModel('SpaLiveV1.SysUsers');
                $this->SysUsers->updateAll(
                    ['steps' => 'MSLSUBSCRIPTION'],
                    ['id' => USER_ID]
                );
                $this->set('coursed', true);
            }else{
                $this->set('coursed', false);
            }

            $ent_advan = $this->DataPayment->find()
                                            ->where(['id_from' => USER_ID, 'type' => 'ADVANCED COURSE'])
                                            ->first();
            if(!empty($ent_advan)){
                $this->set('advanced', true);
            }else {
                $this->set('advanced', false);
            }
            $this->success();
        }else{
            $this->message('No training found.');
        }
    }

    public function _get_default_trainings_() {
        $this->loadModel('SpaLiveV1.CatTrainigs');
        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.DataConsultation');
        $this->loadModel('SpaLiveV1.DataRescheduleTrainings');
        $this->loadModel('SpaLiveV1.CatCoursesType');
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

        $Subscription = new SubscriptionController();
        $canResumeTrial = $Subscription->canResumeTrial();  
        $this->set('show_resume_trial', $canResumeTrial);  

        $c_date = strtotime('2023-02-27');

        $_fields = ['CatTrainigs.id', 'CatTrainigs.created','CatTrainigs.title', 'CatTrainigs.scheduled', 'CatTrainigs.neurotoxins', 'CatTrainigs.fillers', 'CatTrainigs.materials', 'CatTrainigs.available_seats', 'CatTrainigs.level','State.name','State.abv','CatTrainigs.address','CatTrainigs.zip','CatTrainigs.city', 'data_training_id' => 'DataTrainigs.id'];
        $_fields['assistants'] = "(SELECT COUNT(id) from data_trainings DT WHERE DT.training_id = CatTrainigs.id AND DT.deleted = 0)";
        $_fields['enrolled'] = "(SELECT COUNT(id) from data_trainings DT WHERE DT.training_id = CatTrainigs.id AND DT.deleted = 0 AND DT.user_id = " . USER_ID . " )";
        $_fields['attended'] = "(SELECT DT.attended from data_trainings DT WHERE DT.training_id = CatTrainigs.id AND DT.deleted = 0 AND DT.user_id = " . USER_ID . " LIMIT 1)";
        $_join = [
                'DataTrainigs' => ['table' => 'data_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainigs.training_id'],
                'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = CatTrainigs.state_id']
            ];
        $_where = ['DataTrainigs.user_id' => USER_ID,
                    'DataTrainigs.deleted' => 0,
                    'CatTrainigs.deleted' => 0,
                    'CatTrainigs.level = "LEVEL 1"'];
        
        $ent_training = $this->CatTrainigs->find()
                        ->select($_fields)
                        ->join($_join)
                        ->where($_where)
                        ->all();  

        $_where = ['DataTrainigs.user_id' => USER_ID, 
                    'DataTrainigs.deleted' => 0, 
                    'CatTrainigs.deleted' => 0,                   
                    'CatTrainigs.level = "LEVEL 2"'];

        $ent_training_adv = $this->CatTrainigs->find()
                            ->select($_fields)
                            ->join($_join)
                            ->where($_where)
                            ->first();

        $_where = ['DataTrainigs.user_id' => USER_ID, 
                    'DataTrainigs.deleted' => 0, 
                    'CatTrainigs.deleted' => 0,                   
                    'CatTrainigs.level = "LEVEL 3 MEDICAL"'];

        $ent_training_level_3 = $this->CatTrainigs->find()
                                        ->select($_fields)
                                        ->join($_join)
                                        ->where($_where)
                                        ->first();

        // Obtener niveles dinámicos disponibles
        $dynamic_levels = $this->CatCoursesType->find()
            ->where(['CatCoursesType.deleted' => 0, 'CatCoursesType.available' => 1])
            ->all();

        // Buscar entrenamientos dinámicos del usuario
        $ent_training_dynamic = array(); 
        $ent_courses_dynamic = array();
        $added_cats = array();

        $api_url = env('URL_API', 'https://api.myspalive.com/');

        $__fields = ['CatTrainigs.id', 'CatTrainigs.created','CatTrainigs.title', 'CatTrainigs.scheduled', 
                    'CatTrainigs.neurotoxins', 'CatTrainigs.fillers', 'CatTrainigs.materials', 'CatTrainigs.available_seats', 
                    'CatTrainigs.level','State.name','State.abv','CatTrainigs.address','CatTrainigs.zip','CatTrainigs.city', 
                    'data_training_id' => 'DataTrainigs.id', 'CTAM.id', 'DTAM.id'];
        $__fields['assistants'] = "(SELECT COUNT(id) from data_trainings DT WHERE DT.training_id = CatTrainigs.id AND DT.deleted = 0)";
        $__fields['enrolled'] = "(SELECT COUNT(id) from data_trainings DT WHERE DT.training_id = CatTrainigs.id AND DT.deleted = 0 AND DT.user_id = " . USER_ID . " )";
        $__fields['attended'] = "(SELECT DT.attended from data_trainings DT WHERE DT.training_id = CatTrainigs.id AND DT.deleted = 0 AND DT.user_id = " . USER_ID . " LIMIT 1)";

        $__join = [
                'DataTrainigs' => ['table' => 'data_trainings', 'type' => 'LEFT', 'conditions' => 'CatTrainigs.id = DataTrainigs.training_id'],
                'CTAM' => ['table' => 'cat_trainings', 'type' => 'LEFT', 'conditions' => 'CTAM.shared_am_course = CatTrainigs.id OR CTAM.shared_pm_course = CatTrainigs.id'],
                'DTAM' => ['table' => 'data_trainings', 'type' => 'LEFT', 'conditions' => 'DTAM.training_id = CTAM.id AND DTAM.user_id = ' . USER_ID . ' AND DTAM.deleted = 0'],
                'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = CatTrainigs.state_id']
            ];
        
        foreach($dynamic_levels as $dynamic_level) {
            $_where_dynamic = [
                'DataTrainigs.user_id' => USER_ID,
                'DataTrainigs.deleted' => 0,
                'CatTrainigs.deleted' => 0,
                'CatTrainigs.level' => $dynamic_level->title
            ];
            
            $dynamic_trainings = $this->CatTrainigs->find()
                ->select($__fields)
                ->join($__join)
                ->where($_where_dynamic)
                ->all();
            
            if (count($dynamic_trainings) > 0) {
                $added_cats[] = $dynamic_level->title;
            }
            foreach($dynamic_trainings as $training) {
                if(!empty($training['DTAM']['id'])){
                    continue;
                }
                $training->dynamic_level_info = $dynamic_level;
                $ent_training_dynamic[] = $training;
            }
        }

        $_where = ['DataTrainigs.user_id' => USER_ID, 
                    'DataTrainigs.deleted' => 0, 
                    'CatTrainigs.deleted' => 0,                   
                    'CatTrainigs.level = "LEVEL 3 FILLERS"'];

        $ent_training_fillers = $this->CatTrainigs->find()
                                        ->select($_fields)
                                        ->join($_join)
                                        ->where($_where)
                                        ->first();

        if(Count($ent_training) > 0 || !empty($ent_training_adv) || !empty($ent_training_dynamic || !empty($ent_training_fillers))){

            $res = array();
            $res2 = array();
            foreach($ent_training as $training){

                $address = $training->address.', '.$training->city.', '.$training->State['abv'].' '.$training->zip;
                $date = date('Y-m-d H:i:s');
                $scheduled = $training['scheduled']->i18nFormat('yyyy-MM-dd 08:00:00');
                $isBeforeChange = $c_date > strtotime($training->scheduled->i18nFormat('yyyy-MM-dd 08:00:00'));
                // certificate
                $certificate = false;
                $showButton = true;
                $valid_certificate = false;

                if(!empty($ent_certificate)){
                    if($ent_certificate['status'] == 'DONE'){
                        $certificate = false;
                        $showButton = false;
                    }else if($ent_certificate['status'] == 'CERTIFICATE') {
                        $certificate = true;
                        $showButton = false;
                        if($ent_certificate['DC']['proceed'] == 1){
                            $valid_certificate = true;
                        }else {
                            $valid_certificate = true;
                        }
                    }
                }else {
                    $certificate = false;
                }
                // Verificar código de asistencia (proteger si no hay LEVEL 1)
                $show_assistance = false;
                if(!empty($training) && $valid_certificate && $training['scheduled']->i18nFormat('yyyy-MM-dd') <= date('Y-m-d')) {
                    $show_assistance = true;
                }
                if ($isBeforeChange) $training['attended'] = "1";
                if ($training['attended'] == "0") {
                    $res[] = array(
                        'id' => $training['id'],
                        'title' => $training['title'],
                        'scheduled' => $training['scheduled']->i18nFormat('yyyy-MM-dd hh:mm a'),
                        'address' => $address,
                        'level' => $training['level'],
                        'data_training_id' => $training['data_training_id'],
                        'attended' => $training['attended'],
                        'show_cancel' => $date > $scheduled ? false : true,
                        'data' => array(
                            [
                                'title' => 'MySpaLive video',
                                'url' => Configure::read('App.wordpress_domain') . '/myspa.mp4',
                                'type' => 'video'
                            ],
                            [
                                'title' => 'Glossary of Terms for Neurotoxin Injection Training',
                                'url' =>
                                    Configure::read('App.wordpress_domain') . '/wp-content/uploads/Glossary_of_Terms_for_Neurotoxin_Injection_Training.pdf',
                                'type' => 'pdf'
                            ],
                            [
                                'title' => 'MySpaLive Level One Pre-Study Guide',
                                'url' =>
                                    Configure::read('App.wordpress_domain') . '/wp-content/uploads/MySpaLive_Level_One_Pre_Study_Guide.pdf',
                                'type' => 'pdf'
                            ],
                            [
                                'title' => 'How to use the App',
                                'url' => Configure::read('App.wordpress_domain') . '/wp-content/uploads/howtouse.mp4',
                                'type' => 'video'
                            ]
                        ),
                        'ot' => 0,
                        'certificate' => array(
                                'required' => false,
                                'url' => ''
                            ),
                        'type' => 'default',
                        'show_assistance_code' => $show_assistance
                    );
                } else {
                    $res2[] = array(
                        'title' => 'Basic Training',
                        'data_training_id' => $training['data_training_id'],
                    );
                }
            }

            // Procesar entrenamientos dinámicos
            foreach($ent_training_dynamic as $training_dynamic) {
                $address = $training_dynamic->address.', '.$training_dynamic->city.', '.$training_dynamic->State['abv'].' '.$training_dynamic->zip;
                $date = date('Y-m-d H:i:s');
                $scheduled = $training_dynamic['scheduled']->i18nFormat('yyyy-MM-dd 08:00:00');

                $show_assistance = false;
                if(!empty($training_dynamic) && $training_dynamic['scheduled']->i18nFormat('yyyy-MM-dd') <= date('Y-m-d')) {
                    $show_assistance = true;
                }

                if($training_dynamic->dynamic_level_info->gfe_id > 0){
                    $ent_certificate = $this->DataConsultation->find()->select(['DataConsultation.id', 'DataConsultation.status', 'DC.proceed', 'DataConsultation.uid'])
                    ->join([
                        'DC' => ['table' => 'data_consultation_plan', 'type' => 'LEFT', 'conditions' => 'DC.consultation_id = DataConsultation.id AND DC.deleted = 0'],
                        'Cert' => ['table' => 'data_certificates', 'type' => 'LEFT', 'conditions' => 'Cert.consultation_id = DataConsultation.id AND Cert.deleted = 0']
                    ])
                    ->where(['DataConsultation.patient_id' => USER_ID, 'DataConsultation.treatments' => $training_dynamic->dynamic_level_info->id, 'DataConsultation.status IN ' => array('CERTIFICATE'), 'DataConsultation.deleted' => 0])->first();

                    if(!empty($ent_certificate)){
                        $certificate = array(
                            'required' => true,
                            'url' => $ent_certificate['Cert']['certificate_url']
                        );
                    }else{
                        $certificate = array(
                            'required' => true,
                            'url' => ''
                        );
                    }
                } else {
                    $certificate = array(
                        'required' => false,
                        'url' => ''
                    );
                }
                
                if ($training_dynamic['attended'] == "0") {
                    // Procesar materiales dinámicos
                    $materials_ids = json_decode($training_dynamic->dynamic_level_info->materials, true);
                    $dynamic_materials = [];
                    if(!empty($materials_ids)) {
                        foreach($materials_ids as $material_id) {
                            $dynamic_materials[] = [
                                'title' => $material_id['name'],
                                'url' => $api_url . '?key=2fe548d5ae881ccfbe2be3f6237d7951&l3n4p=6092482f7ce858.91169218&action=get-file&token=6092482f7ce858.91169218&id=' . $material_id['id'],
                                'type' => 'pdf'
                            ];
                        }
                    }
                    
                    $res[] = array(
                        'id' => $training_dynamic['id'],
                        'title' => $training_dynamic['title'],
                        'scheduled' => $training_dynamic['scheduled']->i18nFormat('yyyy-MM-dd hh:mm a'),
                        'address' => $address,
                        'level' => $training_dynamic['level'],
                        'data_training_id' => $training_dynamic['data_training_id'],
                        'attended' => $training_dynamic['attended'],
                        'show_cancel' => $date > $scheduled ? false : true,
                        'data' => $dynamic_materials,
                        'ot' => 1,
                        'certificate' => $certificate,
                        'type' => 'dynamic',
                        'show_assistance_code' => $show_assistance
                    );
                } else {
                    $res2[] = array(
                        'title' => $training_dynamic['title'],
                        'data_training_id' => $training_dynamic['data_training_id'],
                    );
                }
            }

            // Procesar entrenamiento filler

            if(!empty($ent_training_fillers)){
                $isBeforeChange = $c_date > strtotime($ent_training_fillers->scheduled->i18nFormat('yyyy-MM-dd 08:00:00'));
                if ($isBeforeChange) $training['attended'] = "1";

                $this->loadModel('SpaLiveV1.DataCourses');
                $data_course = $this->DataCourses->find()->where(['DataCourses.user_id' => USER_ID,
                                                    'DataCourses.deleted' => 0,'DataCourses.status' => 'DONE'])->first();

                $show_assistance = false;
                if(!empty($ent_training_fillers) && $ent_training_fillers['scheduled']->i18nFormat('yyyy-MM-dd') <= date('Y-m-d')) {
                    $show_assistance = true;
                }

                if(!empty($data_course)){
                    $res[] = array(
                        'id' => 0,
                        'title' => 'Start Providing Treatments',
                        'scheduled' => '',
                        'address' => '',
                        'level' => 'subscriptions',
                        'data_training_id' => '',
                        'attended' => '',
                        'data' => array(),
                        'ot' => 0,
                        'certificate' => array(
                            'required' => false,
                            'url' => ''
                        ),
                        'type' => 'default',
                        'show_assistance_code' => $show_assistance
                    );
                    $this->loadModel('SpaLiveV1.SysUsers');
                    
                    $this->set('coursed', true);
                }

                
                if ($ent_training_fillers['attended'] == "0") {
                    $advanced_count = 1;
                    $advanced = 'STUDING';
                    $scheduled_fill = $ent_training_fillers['scheduled']->i18nFormat('yyyy-MM-dd 08:00:00');
                    $address = $ent_training_fillers->address.', '.$ent_training_fillers->city.', '.$ent_training_fillers->State['abv'].' '.$ent_training_fillers->zip;
                    $res[] = array(
                        'id' => $ent_training_fillers['id'],
                        'title' => $ent_training_fillers['title'],
                        'scheduled' => $ent_training_fillers['scheduled']->i18nFormat('yyyy-MM-dd hh:mm a'),
                        'address' => $address,
                        'level' => $ent_training_fillers['level'],
                        'data_training_id' => $ent_training_fillers['data_training_id'],
                        'attended' => $ent_training_fillers['attended'],
                        'show_cancel' => false,
                        'data' => array(
                            [
                                'title' => 'MySpaLive video',
                                'url' => Configure::read('App.wordpress_domain') . '/myspa.mp4',
                                'type' => 'video'
                            ],
                            [
                                'title' => 'How to use the App',
                                'url' => Configure::read('App.wordpress_domain') . '/wp-content/uploads/howtouse.mp4',
                                'type' => 'video'
                            ]
                        ),
                        'ot' => 0,
                        'certificate' => array(
                            'required' => false,
                            'url' => ''
                        )
                    );
                } else{ 
                    $res2[] = array(
                        'title' => 'Fundations in Aesthetic Filler Techniques',
                        'data_training_id' => $ent_training_fillers['data_training_id'],
                    );
                }
            }

            $data_reschedule = $this->DataRescheduleTrainings->find()
            ->where(['DataRescheduleTrainings.user_id' => USER_ID, 'DataRescheduleTrainings.level_training' => 'LEVEL 1', 'DataRescheduleTrainings.deleted' => 0])->first();
            
            if(!empty($data_reschedule)){
                $data_reschedule->reschedule_count ++;
                if($data_reschedule->reschedule_count >= 2){
                    $this->set('reschedule_popup_b', "A $50 fee is required to reschedule this class again.\n\nPlease confirm to cancel this class.");
                    $this->set('reschedule_b', true);
                }else {
                    $this->set('reschedule_popup_b', "There is no cost to reschedule your class the first time, but there will be a $50 fee if you reschedule it again.\n\nDo you want to reschedule this class?");
                    $this->set('reschedule_b', false);
                }
            } else{
                $this->set('reschedule_popup_b', "There is no cost to reschedule your class the first time, but there will be a $50 fee if you reschedule it again.\n\nDo you want to reschedule this class?");
                $this->set('reschedule_b', false);
            }

            $ent_certificate = $this->DataConsultation->find()->select(['DataConsultation.id', 'DataConsultation.status', 'DC.proceed', 'DataConsultation.uid'])
            ->join([
                'DC' => ['table' => 'data_consultation_plan', 'type' => 'LEFT', 'conditions' => 'DC.consultation_id = DataConsultation.id AND DC.deleted = 0'],
            ])
            ->where(['DataConsultation.patient_id' => USER_ID, 'DataConsultation.status IN ' => array('DONE', 'CERTIFICATE'), 'DataConsultation.deleted' => 0])->first();

            $certificate = false;
            $showButton = true;
            $valid_certificate = false;
            $this->set('message_gfe', '');
            $this->set('cert_status', 'PENDING');

            if(!empty($ent_certificate)){
                if($ent_certificate['status'] == 'DONE'){
                    $certificate = false;
                    $showButton = false;
                    $this->set('status', 'Waiting for the Examiner to give you the certificate.'); // DELETE AFTER FEB
                    $this->set('message_gfe', 'Waiting for the Examiner to give you the certificate.');
                    $this->set('cert_status', 'WAITING');
                }else if($ent_certificate['status'] == 'CERTIFICATE') {
                    $certificate = true;
                    $showButton = false;
                    if($ent_certificate['DC']['proceed'] == 1){
                        $this->set('status', 'Certificate Received.'); // DELETE AFTER FEB
                        $this->set('message_gfe', 'Certificate Received.');
                        $this->set('cert_uid', $ent_certificate['uid']);
                        $this->set('cert_status', 'DONE');
                        $valid_certificate = true;
                    }else {
                        $this->set('status', 'Certificate Denied.'); // DELETE AFTER FEB
                        $this->set('message_gfe', 'Certificate Denied.');
                        $this->set('cert_status', 'DONE');
                        $valid_certificate = true;
                    }
                }
            }else {
                $certificate = false;
            }
            $this->set('show_gfe_button', $showButton);            
            
            // Verificar código de asistencia (proteger si no hay LEVEL 1)
            $show_assistance = false;
            if(!empty($training) && $valid_certificate && $training['scheduled']->i18nFormat('yyyy-MM-dd') <= date('Y-m-d')) {
                $show_assistance = true;
            }
            $this->set('show_assistance_code', $show_assistance);    

            // Verificar si es hoy (proteger si no hay LEVEL 1)
            if(!empty($training) && !empty($training['scheduled']) && $training['scheduled']->i18nFormat('yyyy-MM-dd') == date('Y-m-d') && $certificate){
                $this->set('cert_status', 'CONTINUE');
            }

            if(!empty($training) && !empty($training['scheduled']) && $training['scheduled']->i18nFormat('yyyy-MM-dd') == date('Y-m-d')){
                $this->set('today', true);
            }else{
                $this->set('today', false);
            }

            // Verificar si hay entrenamientos dinámicos completados y obtener la fecha del completado
            $has_completed_dynamic_training = false;
            $subscription_scheduled = null;
            
            foreach($ent_training_dynamic as $dynamic_training) {
                if($dynamic_training['attended'] == "1") {
                    $has_completed_dynamic_training = true;
                    // Si ya asistió al entrenamiento dinámico, usar fecha pasada para permitir suscripción inmediata
                    $subscription_scheduled = date('Y-m-d 08:00:00', strtotime('-1 day'));
                    break;
                }
            }
            
            // Verificar si puede acceder a suscripciones (LEVEL 1 completado O entrenamiento dinámico completado)
            $can_subscribe = false;
            if(!empty($training) && $training['attended'] == "1") {
                $can_subscribe = true; // LEVEL 1 completado
                // Si ya asistió, usar fecha actual para permitir suscripción inmediata
                $subscription_scheduled = date('Y-m-d 08:00:00', strtotime('-1 day')); // Un día atrás para asegurar que pase la validación
            } elseif($has_completed_dynamic_training) {
                $can_subscribe = true; // Entrenamiento dinámico completado
                // $subscription_scheduled ya se estableció arriba
            }
            
            // Para entrenamientos dinámicos, no requerir certificado de LEVEL 1
            $requires_certificate = true;
            if($has_completed_dynamic_training && (empty($training) || $training['attended'] != "1")) {
                $requires_certificate = false; // Solo entrenamiento dinámico completado, no requiere certificado LEVEL 1
            }
            
            // DEBUG: Comentado para producción
            // pr("=== DEBUG SUBSCRIPTION CONDITIONS ===");
            // pr("can_subscribe: " . ($can_subscribe ? 'true' : 'false'));
            // pr("certificate: " . ($certificate ? 'true' : 'false'));
            // pr("requires_certificate: " . ($requires_certificate ? 'true' : 'false'));
            // pr("date: " . $date);
            // pr("subscription_scheduled: " . ($subscription_scheduled ?? 'null'));
            // pr("date > subscription_scheduled: " . (($subscription_scheduled && $date > $subscription_scheduled) ? 'true' : 'false'));
            // pr("has_completed_dynamic_training: " . ($has_completed_dynamic_training ? 'true' : 'false'));
            // pr("training_attended: " . (!empty($training) ? $training['attended'] : 'no_training'));
            // pr("===============================");
            // exit;
            
            if($can_subscribe && ($certificate || !$requires_certificate) && $date > $subscription_scheduled){
                $res[] = array(
                    'id' => 0,
                    'title' => 'Start Providing Treatments',
                    'scheduled' => '',
                    'address' => '',
                    'level' => 'subscriptions',
                    'data_training_id' => '',
                    'attended' => '',
                    'data' => array(),
                    'ot' => 0,
                    'certificate' => array(
                                'required' => false,
                                'url' => ''
                            )
                );
                $this->loadModel('SpaLiveV1.SysUsers');
                /* $this->SysUsers->updateAll(
                    ['steps' => 'MSLSUBSCRIPTION'],
                    ['id' => USER_ID]
                ); */
                $this->set('coursed', true);
            }else{
                $this->set('coursed', false);
            }

            $buy_level_3 = $this->DataPayment->find()
                ->where(['id_from' => USER_ID, 'type' => 'ADVANCED COURSE', 'is_visible' => 1, 'intent <>' => '', 'payment <>' => '', 'refund_id' => 0])
                ->first();

            $advanced_count = 0;
            $advanced = 'BUY';

            if(!empty($buy_level_3)){
                $advanced = 'BOOK';

                if(!empty($ent_training_adv)){
                    $isBeforeChange = $c_date > strtotime($ent_training_adv->scheduled->i18nFormat('yyyy-MM-dd 08:00:00'));
                    if ($isBeforeChange) $training['attended'] = "1";

                    $this->loadModel('SpaLiveV1.DataCourses');
                    $data_course = $this->DataCourses->find()->where(['DataCourses.user_id' => USER_ID,
                                                        'DataCourses.deleted' => 0,'DataCourses.status' => 'DONE'])->first();

                    if(!empty($data_course)){
                        $res[] = array(
                            'id' => 0,
                            'title' => 'Start Providing Treatments',
                            'scheduled' => '',
                            'address' => '',
                            'level' => 'subscriptions',
                            'data_training_id' => '',
                            'attended' => '',
                            'data' => array(),
                            'ot' => 0
                        );
                        $this->loadModel('SpaLiveV1.SysUsers');
                        
                        $this->set('coursed', true);
                    }

                    
                    if ($ent_training_adv['attended'] == "0") {
                        $advanced_count = 1;
                        $advanced = 'STUDING';
                        $scheduled_adv = $ent_training_adv['scheduled']->i18nFormat('yyyy-MM-dd 08:00:00');
                        $address = $ent_training_adv->address.', '.$ent_training_adv->city.', '.$ent_training_adv->State['abv'].' '.$ent_training_adv->zip;
                        $res[] = array(
                            'id' => $ent_training_adv['id'],
                            'title' => $ent_training_adv['title'],
                            'scheduled' => $ent_training_adv['scheduled']->i18nFormat('yyyy-MM-dd hh:mm a'),
                            'address' => $address,
                            'level' => $ent_training_adv['level'],
                            'data_training_id' => $ent_training_adv['data_training_id'],
                            'attended' => $ent_training_adv['attended'],
                            'show_cancel' => $date > $scheduled_adv ? false : true,
                            'data' => array(
                                [
                                    'title' => 'MySpaLive video',
                                    'url' => Configure::read('App.wordpress_domain') . '/myspa.mp4',
                                    'type' => 'video'
                                ],
                                [
                                    'title' => 'Glossary of Terms for Neurotoxin Injection Training',
                                    'url' =>
                                        Configure::read('App.wordpress_domain') . '/wp-content/uploads/Glossary_of_Terms_for_Neurotoxin_Injection_Training.pdf',
                                    'type' => 'pdf'
                                ],
                                [
                                    'title' => 'MySpaLive Level One Pre-Study Guide',
                                    'url' =>
                                        Configure::read('App.wordpress_domain') . '/wp-content/uploads/MySpaLive_Level_One_Pre_Study_Guide.pdf',
                                    'type' => 'pdf'
                                ],
                                [
                                    'title' => 'How to use the App',
                                    'url' => Configure::read('App.wordpress_domain') . '/wp-content/uploads/howtouse.mp4',
                                    'type' => 'video'
                                ]
                            ),
                            'ot' => 0
                        );
                    } else{ 
                        $advanced_count = 2;
                        $advanced = 'CERTIFICATE';
                        $res2[] = array(
                            'title' => 'Advanced Training',
                            'data_training_id' => $ent_training_adv['data_training_id'],
                        );
                    }
                }
            }

            $data_reschedule = $this->DataRescheduleTrainings->find()
            ->where(['DataRescheduleTrainings.user_id' => USER_ID, 'DataRescheduleTrainings.level_training' => 'LEVEL 2', 'DataRescheduleTrainings.deleted' => 0])->first();
            
            if(!empty($data_reschedule)){
                $data_reschedule->reschedule_count ++;
                if($data_reschedule->reschedule_count >= 2){
                    $this->set('reschedule_popup_a', "A $50 fee is required to reschedule this class again.\n\nPlease confirm to cancel this class.");
                    $this->set('reschedule_a', true);
                }else {
                    $this->set('reschedule_popup_a', "There is no cost to reschedule your class the first time, but there will be a $50 fee if you reschedule it again.\n\nDo you want to reschedule this class?");
                    $this->set('reschedule_a', false);
                }
            } else{
                $this->set('reschedule_popup_a', "There is no cost to reschedule your class the first time, but there will be a $50 fee if you reschedule it again.\n\nDo you want to reschedule this class");
                $this->set('reschedule_a', false);
            }

            $level_3 = 'HIDE';
            $level_3_count = 0;

            if(!empty($buy_level_3)){
                $level_3 = 'BUY';
                $ent_level_3 = $this->DataPayment->find()
                ->where(['id_from' => USER_ID, 'type' => 'ADVANCED TECHNIQUES MEDICAL', 'is_visible' => 1, 'intent <>' => '', 'payment <>' => '', 'refund_id' => 0])
                ->first();

                if(!empty($ent_level_3)){
                    $level_3 = 'BOOK';

                    if(!empty($ent_training_level_3)){
                        if ($ent_training_level_3['attended'] == "0") {
                            $level_3_count = 1;
                            $level_3 = 'STUDING';
                            $scheduled_adv = $ent_training_level_3['scheduled']->i18nFormat('yyyy-MM-dd 08:00:00');
                            $address = $ent_training_level_3->address.', '.$ent_training_level_3->city.', '.$ent_training_level_3->State['abv'].' '.$ent_training_level_3->zip;
                            $res[] = array(
                                'id' => $ent_training_level_3['id'],
                                'title' => $ent_training_level_3['title'],
                                'scheduled' => $ent_training_level_3['scheduled']->i18nFormat('yyyy-MM-dd hh:mm a'),
                                'address' => $address,
                                'level' => $ent_training_level_3['level'],
                                'data_training_id' => $ent_training_level_3['data_training_id'],
                                'attended' => $ent_training_level_3['attended'],
                                'show_cancel' => $date > $scheduled_adv ? false : true,
                                'data' => array(
                                    [
                                        'title' => 'MySpaLive video',
                                        'url' => Configure::read('App.wordpress_domain') . '/myspa.mp4',
                                        'type' => 'video'
                                    ],
                                    [
                                        'title' => 'How to use the App',
                                        'url' => Configure::read('App.wordpress_domain') . '/wp-content/uploads/howtouse.mp4',
                                        'type' => 'video'
                                    ]
                                ),
                                'ot' => 0
                            );
                        } else{
                            $level_3_count = 2;
                            $level_3 = 'CERTIFICATE';
                            $res2[] = array(
                                'title' => 'Level 3',
                                'data_training_id' => $ent_training_level_3['data_training_id'],
                            );
                        }
                    }
                }
            }

            // OTHER COURSES LIST

            $arr_other_courses_list = [];

            if (count($ent_training) == 0) {
                $ent_payments_basic = $this->DataPayment->find()->where(['DataPayment.refund_id' => 0, 'DataPayment.id_from' => USER_ID, 
                                                                 'DataPayment.type' => 'BASIC COURSE', 'DataPayment.payment !=' => '', 
                                                                 'DataPayment.is_visible' => 1])->first();
                                                                 
                if (empty($ent_payments_basic)) $basic_course_status = 'BUY';
                else $basic_course_status = 'BOOK';
                
                $arr_other_courses_list[] = [
                    'title' => 'NEUROTOXINS BASIC',
                    'status' => $basic_course_status,
                    'type' => 'BASIC',
                    'course_type_id' => 0
                ];
                        
            }

            if ($ent_training_fillers) {
                $arr_other_courses_list[] = [
                    'title' => 'Fillers',
                    'status' => 'BUY',
                    'type' => 'FILLER',
                    'course_type_id' => 0
                ];
            }

            // if (count($ent_training) > 0 && $advanced == 'BUY')  {
                if($advanced == 'BUY' || $advanced == 'BOOK'){
                    $arr_other_courses_list[] = [
                        'title' => 'NEUROTOXINS ADVANCED',
                        'status' => $advanced,
                        'type' => 'ADVANCED',
                        'course_type_id' => 0
                    ];
                }
            // }

            if ($level_3 == 'BUY')  {
                $arr_other_courses_list[] = [
                    'title' => 'LEVEL 3',
                    'status' => $level_3,
                    'type' => 'LEVEL3',
                    'course_type_id' => 0
                ];
            }



            // Find available courses per type
            
            $__join = [
                    'DataTrainigs' => ['table' => 'data_trainings', 'type' => 'LEFT', 'conditions' => 'CatTrainigs.id = DataTrainigs.training_id AND DataTrainigs.deleted = 0 AND DataTrainigs.user_id = ' . USER_ID],
                    'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = CatTrainigs.state_id'],
                    'DataPayment' => ['table' => 'data_payment', 'type' => 'LEFT', 'conditions' => 'DataPayment.id_from = ' . USER_ID . ' AND DataPayment.type = CatTrainigs.level AND DataPayment.payment <> "" AND DataPayment.is_visible = 1'],
                ];

            $__fields = ['CatTrainigs.id', 'CatTrainigs.created','CatTrainigs.title', 'CatTrainigs.scheduled', 'CatTrainigs.neurotoxins', 'CatTrainigs.fillers', 'CatTrainigs.materials', 'CatTrainigs.available_seats', 'CatTrainigs.level','State.name','State.abv','CatTrainigs.address','CatTrainigs.zip','CatTrainigs.city', 'data_training_id' => 'DataTrainigs.id','DataPayment.id'];
            

            foreach($dynamic_levels as $dynamic_level) {
                if (in_array($dynamic_level->title,$added_cats)) continue;
                
                $_where_dynamic_ = [
                    'CatTrainigs.deleted' => 0,
                    'CatTrainigs.level' => $dynamic_level->title,
                    // 'CatTrainigs.scheduled > NOW()'
                ];
                
                $dynamic_trainings = $this->CatTrainigs->find()
                    ->select($__fields)
                    ->join($__join)
                    ->where($_where_dynamic_)
                    ->first();



                $status = 'BUY';

               
                if (!empty($dynamic_trainings) && !empty($dynamic_trainings['DataPayment']['id'])) {
                     $status = 'BOOK';
                }

               
                $arr_other_courses_list[] = [
                    'title' => $dynamic_level->title,
                    'status' => $status,
                    'type' => 'OTHERCOURSE',
                    'course_type_id' => $dynamic_level->id
                ];
                

            }


            $this->set('other_courses_list', $arr_other_courses_list);

            // VALIDAR NO OFREZCA ADVANCED SI NO TIENE BASICO
            
            $this->set('other_courses', true);
            // if($advanced_count > 0 && $level_3_count > 0){
            //     $this->set('other_courses', false);
            // }

            if ($advanced == 'HIDE' && $level_3 == 'HIDE')
                $this->set('other_courses', false);
            // Ordenar todos los resultados por fecha
            usort($res, function($a, $b) {
                if(empty($a['scheduled']) && empty($b['scheduled'])) return 0;
                if(empty($a['scheduled'])) return 1;
                if(empty($b['scheduled'])) return -1;
                return strtotime($a['scheduled']) - strtotime($b['scheduled']);
            });

            $this->set('training', $res);
            $this->set('training_cert', $res2);

            // $this->set('level_3', $level_3);
            // $this->set('advanced', $advanced);

            $this->set('number', '9727553038');
            $this->set('number_label', '(972) 755 3038');

            $therapy = new TherapyController();
            $iv_status = $therapy->check_application();            
            $this->set('iv_status', $iv_status);
            // Verificar si hay entrenamientos completados (LEVEL 1 o dinámicos)
            $has_completed_training = false;
            
            // Verificar LEVEL 1
            if(Count($ent_training) > 0){
                $f = $ent_training->first();
                $isBeforeChange = $c_date > strtotime($f->scheduled->i18nFormat('yyyy-MM-dd 08:00:00'));
                if ($isBeforeChange) {
                    $has_completed_training = true;
                }
            }
            
            // Verificar entrenamientos dinámicos si no hay LEVEL 1 completado
            if(!$has_completed_training && !empty($ent_training_dynamic)){
                foreach($ent_training_dynamic as $dynamic_training) {
                    $isBeforeChange = $c_date > strtotime($dynamic_training->scheduled->i18nFormat('yyyy-MM-dd 08:00:00'));
                    if ($isBeforeChange) {
                        $has_completed_training = true;
                        break;
                    }
                }
            }
            
            if($has_completed_training) {
                $res[] = array(
                    'id' => 0,
                    'title' => 'Start Providing Treatments',
                    'scheduled' => '',
                    'address' => '',
                    'level' => 'subscriptions',
                    'data_training_id' => '',
                    'attended' => '',
                    'data' => array(),
                    'ot' => 0
                );
                $this->set('show_assistance_code', false);
                $this->set('message_gfe', 'Certificate Received.');
                $this->set('cert_uid', '');
                $this->set('cert_status', 'DONE');
                $this->set('show_gfe_button', false);
                $this->set('coursed', true);
                $this->set('today', true);
                $this->set('training', $res);
                $this->success();
                return;
            }
            
            $this->success();
        }else{
            $therapy = new TherapyController();
            $iv_status = $therapy->check_application();            
            $this->set('iv_status', $iv_status);
            $this->message('No training found.');
        }
    }

    public function get_default_trainings() {
        $this->loadModel('SpaLiveV1.CatTrainigs');
        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.DataConsultation');
        $this->loadModel('SpaLiveV1.DataRescheduleTrainings');
        $this->loadModel('SpaLiveV1.CatCoursesType');
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

        $Subscription = new SubscriptionController();
        $canResumeTrial = $Subscription->canResumeTrial();  
        $this->set('show_resume_trial', $canResumeTrial);  

        $c_date = strtotime('2023-02-27');

        $_fields = ['CatTrainigs.id', 'CatTrainigs.created','CatTrainigs.title', 'CatTrainigs.scheduled', 'CatTrainigs.neurotoxins', 'CatTrainigs.fillers', 'CatTrainigs.materials', 'CatTrainigs.available_seats', 'CatTrainigs.level','State.name','State.abv','CatTrainigs.address','CatTrainigs.zip','CatTrainigs.city', 'data_training_id' => 'DataTrainigs.id'];
        $_fields['assistants'] = "(SELECT COUNT(id) from data_trainings DT WHERE DT.training_id = CatTrainigs.id AND DT.deleted = 0)";
        $_fields['enrolled'] = "(SELECT COUNT(id) from data_trainings DT WHERE DT.training_id = CatTrainigs.id AND DT.deleted = 0 AND DT.user_id = " . USER_ID . " )";
        $_fields['attended'] = "(SELECT DT.attended from data_trainings DT WHERE DT.training_id = CatTrainigs.id AND DT.deleted = 0 AND DT.user_id = " . USER_ID . " LIMIT 1)";
        $_join = [
                'DataTrainigs' => ['table' => 'data_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainigs.training_id'],
                'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = CatTrainigs.state_id']
            ];
        $_where = ['DataTrainigs.user_id' => USER_ID,
                    'DataTrainigs.deleted' => 0,
                    'CatTrainigs.deleted' => 0,
                    'CatTrainigs.level = "LEVEL 1"'];
        
        $ent_training = $this->CatTrainigs->find()
                        ->select($_fields)
                        ->join($_join)
                        ->where($_where)
                        ->all();  

        $_where = ['DataTrainigs.user_id' => USER_ID, 
                    'DataTrainigs.deleted' => 0, 
                    'CatTrainigs.deleted' => 0,                   
                    'CatTrainigs.level = "LEVEL 2"'];

        $ent_training_adv = $this->CatTrainigs->find()
                            ->select($_fields)
                            ->join($_join)
                            ->where($_where)
                            ->first();

        $_where = ['DataTrainigs.user_id' => USER_ID, 
                    'DataTrainigs.deleted' => 0, 
                    'CatTrainigs.deleted' => 0,                   
                    'CatTrainigs.level = "LEVEL 3 MEDICAL"'];

        $ent_training_level_3 = $this->CatTrainigs->find()
                                        ->select($_fields)
                                        ->join($_join)
                                        ->where($_where)
                                        ->first();

        // Obtener niveles dinámicos disponibles
        $dynamic_levels = $this->CatCoursesType->find()
            ->where(['CatCoursesType.deleted' => 0, 'CatCoursesType.available' => 1])
            ->all();

        // Buscar entrenamientos dinámicos del usuario
        $ent_training_dynamic = array(); 
        $ent_courses_dynamic = array();
        $added_cats = array();

        $api_url = env('URL_API', 'https://api.myspalive.com/');

        $__fields = ['CatTrainigs.id', 'CatTrainigs.created','CatTrainigs.title', 'CatTrainigs.scheduled', 
                    'CatTrainigs.neurotoxins', 'CatTrainigs.fillers', 'CatTrainigs.materials', 'CatTrainigs.available_seats', 
                    'CatTrainigs.level','State.name','State.abv','CatTrainigs.address','CatTrainigs.zip','CatTrainigs.city', 
                    'data_training_id' => 'DataTrainigs.id', 'CTAM.id', 'DTAM.id'];
        $__fields['assistants'] = "(SELECT COUNT(id) from data_trainings DT WHERE DT.training_id = CatTrainigs.id AND DT.deleted = 0)";
        $__fields['enrolled'] = "(SELECT COUNT(id) from data_trainings DT WHERE DT.training_id = CatTrainigs.id AND DT.deleted = 0 AND DT.user_id = " . USER_ID . " )";
        $__fields['attended'] = "(SELECT DT.attended from data_trainings DT WHERE DT.training_id = CatTrainigs.id AND DT.deleted = 0 AND DT.user_id = " . USER_ID . " LIMIT 1)";

        $__join = [
                'DataTrainigs' => ['table' => 'data_trainings', 'type' => 'LEFT', 'conditions' => 'CatTrainigs.id = DataTrainigs.training_id'],
                'CTAM' => ['table' => 'cat_trainings', 'type' => 'LEFT', 'conditions' => 'CTAM.shared_am_course = CatTrainigs.id OR CTAM.shared_pm_course = CatTrainigs.id'],
                'DTAM' => ['table' => 'data_trainings', 'type' => 'LEFT', 'conditions' => 'DTAM.training_id = CTAM.id AND DTAM.user_id = ' . USER_ID . ' AND DTAM.deleted = 0'],
                'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = CatTrainigs.state_id']
            ];
        
        foreach($dynamic_levels as $dynamic_level) {
            $_where_dynamic = [
                'DataTrainigs.user_id' => USER_ID,
                'DataTrainigs.deleted' => 0,
                'CatTrainigs.deleted' => 0,
                'CatTrainigs.level' => $dynamic_level->name_key
            ];
            
            $dynamic_trainings = $this->CatTrainigs->find()
                ->select($__fields)
                ->join($__join)
                ->where($_where_dynamic)
                ->all();
            
            if (count($dynamic_trainings) > 0) {
                $added_cats[] = $dynamic_level->name_key;
            }
            foreach($dynamic_trainings as $training) {
                if(!empty($training['DTAM']['id'])){
                    continue;
                }
                $training->dynamic_level_info = $dynamic_level;
                $ent_training_dynamic[] = $training;
            }
        }

        $_where = ['DataTrainigs.user_id' => USER_ID, 
                    'DataTrainigs.deleted' => 0, 
                    'CatTrainigs.deleted' => 0,                   
                    'CatTrainigs.level = "LEVEL 3 FILLERS"'];

        $ent_training_fillers = $this->CatTrainigs->find()
                                        ->select($_fields)
                                        ->join($_join)
                                        ->where($_where)
                                        ->first();

        if(Count($ent_training) > 0 || !empty($ent_training_adv) || !empty($ent_training_dynamic || !empty($ent_training_fillers))){

            $res = array();
            $res2 = array();
            foreach($ent_training as $training){

                $address = $training->address.', '.$training->city.', '.$training->State['abv'].' '.$training->zip;
                $date = date('Y-m-d H:i:s');
                $scheduled = $training['scheduled']->i18nFormat('yyyy-MM-dd 08:00:00');
                $isBeforeChange = $c_date > strtotime($training->scheduled->i18nFormat('yyyy-MM-dd 08:00:00'));
                // certificate
                $ent_certificate = $this->DataConsultation->find()->select(['DataConsultation.id', 'DataConsultation.status', 'DC.proceed', 'DataConsultation.uid'])
                    ->join([
                        'DC' => ['table' => 'data_consultation_plan', 'type' => 'LEFT', 'conditions' => 'DC.consultation_id = DataConsultation.id AND DC.deleted = 0'],
                    ])
                    ->where([
                        'DataConsultation.patient_id' => USER_ID, 
                        'DataConsultation.deleted' => 0,
                        'DataConsultation.status IN ' => array('INIT', 'CERTIFICATE'),
                        'DataConsultation.treatments LIKE' => '%92%' 
                    ])
                ->first();
                $certificate = false;
                $showButton = true;
                $valid_certificate = false;
                $message = '';
                $cert_status = 'PENDING';
                if(!empty($ent_certificate)){
                    if($ent_certificate['status'] == 'INIT'){
                        $certificate = false;
                        $showButton = true;
                        //$message = 'Waiting for the Examiner to give you the certificate.';
                        $cert_status = 'PENDING';
                    }else if($ent_certificate['status'] == 'CERTIFICATE') {
                        $certificate = true;
                        $showButton = false;
                        $valid_certificate = true;
                        $cert_status = 'DONE';
                        if($ent_certificate['DC']['proceed'] == 1){
                            $message = 'Certificate Received.';
                        }else {
                            $message = 'Certificate Denied.';
                        }
                    }
                }else {
                    $certificate = false;
                }
                // end certificate

                if(!empty($training) && !empty($training['scheduled']) && $training['scheduled']->i18nFormat('yyyy-MM-dd') == date('Y-m-d')){
                    $today = true;
                }else{
                    $today = false;
                }

                // Verificar código de asistencia (proteger si no hay LEVEL 1)
                $show_assistance = false;
                if(!empty($training) && $training['scheduled']->i18nFormat('yyyy-MM-dd') <= date('Y-m-d')) {
                    $show_assistance = true;
                }
                // end
                if ($isBeforeChange) $training['attended'] = "1";
                if ($training['attended'] == "0") {
                    $res[] = array(
                        'id' => $training['id'],
                        'title' => $training['title'],
                        'scheduled' => $training['scheduled']->i18nFormat('yyyy-MM-dd hh:mm a'),
                        'address' => $address,
                        'level' => $training['level'],
                        'data_training_id' => $training['data_training_id'],
                        'attended' => $training['attended'],
                        'show_cancel' => $date > $scheduled ? false : true,
                        'data' => array(
                            [
                                'title' => 'MySpaLive video',
                                'url' => Configure::read('App.wordpress_domain') . '/myspa.mp4',
                                'type' => 'video'
                            ],
                            [
                                'title' => 'Glossary of Terms for Neurotoxin Injection Training',
                                'url' => Configure::read('App.wordpress_domain') . '/wp-content/uploads/Glossary_of_Terms_for_Neurotoxin_Injection_Training.pdf',
                                'type' => 'pdf'
                            ],
                            [
                                'title' => 'MySpaLive Level One Pre-Study Guide',
                                'url' => Configure::read('App.wordpress_domain') . '/wp-content/uploads/MySpaLive_Level_One_Pre_Study_Guide.pdf',
                                'type' => 'pdf'
                            ],
                            [
                                'title' => 'How to use the App',
                                'url' => Configure::read('App.wordpress_domain') . '/wp-content/uploads/howtouse.mp4',
                                'type' => 'video'
                            ]
                        ),
                        'ot' => 0,
                        'certificate' => array(
                            'show_button' => $showButton,
                            'message' => $message,
                            'valid_certificate' => $valid_certificate,
                            'cert_status' => $cert_status,
                            'required' => true,
                            'url' => ''
                        ),
                        'type' => $training['level'],
                        'show_assistance_code' => $show_assistance,
                        'continue_sign' => false,
                        'today' => $today,
                        'coursed' => false,
                        'subscriptions' => []
                    );
                } else {
                    $res[] = array(
                        'id' => $training['id'],
                        'title' => 'Start Providing Neurotoxin Treatments',
                        'scheduled' => $training['scheduled']->i18nFormat('yyyy-MM-dd hh:mm a'),
                        'address' => $address,
                        'level' => 'subscriptions',
                        'data_training_id' => $training['data_training_id'],
                        'attended' => $training['attended'],
                        'show_cancel' => $date > $scheduled ? false : true,
                        'data' => array(
                            [
                                'title' => 'MySpaLive video',
                                'url' => Configure::read('App.wordpress_domain') . '/myspa.mp4',
                                'type' => 'video'
                            ],
                            [
                                'title' => 'Glossary of Terms for Neurotoxin Injection Training',
                                'url' => Configure::read('App.wordpress_domain') . '/wp-content/uploads/Glossary_of_Terms_for_Neurotoxin_Injection_Training.pdf',
                                'type' => 'pdf'
                            ],
                            [
                                'title' => 'MySpaLive Level One Pre-Study Guide',
                                'url' => Configure::read('App.wordpress_domain') . '/wp-content/uploads/MySpaLive_Level_One_Pre_Study_Guide.pdf',
                                'type' => 'pdf'
                            ],
                            [
                                'title' => 'How to use the App',
                                'url' => Configure::read('App.wordpress_domain') . '/wp-content/uploads/howtouse.mp4',
                                'type' => 'video'
                            ]
                        ),
                        'ot' => 0,
                        'certificate' => array(
                            'show_button' => $showButton,
                            'message' => $message,
                            'valid_certificate' => $valid_certificate,
                            'cert_status' => $cert_status,
                            'required' => true,
                            'url' => ''
                        ),
                        'type' => $training['level'],
                        'show_assistance_code' => false,
                        'continue_sign' => $valid_certificate ? true : false,
                        'today' => $today,
                        'coursed' => true,
                        'subscriptions' => []
                    );
                }
            }

            // Procesar entrenamientos dinámicos
            foreach($ent_training_dynamic as $training_dynamic) {
                $address = $training_dynamic->address.', '.$training_dynamic->city.', '.$training_dynamic->State['abv'].' '.$training_dynamic->zip;
                $date = date('Y-m-d H:i:s');
                $scheduled = $training_dynamic['scheduled']->i18nFormat('yyyy-MM-dd 08:00:00');

                if(!empty($training_dynamic) && !empty($training_dynamic['scheduled']) && $training_dynamic['scheduled']->i18nFormat('yyyy-MM-dd') == date('Y-m-d')){
                    $today = true;
                }else{
                    $today = false;
                }

                // Verificar código de asistencia (proteger si no hay LEVEL 1)
                $show_assistance = false;
                if(!empty($training) && $training['scheduled']->i18nFormat('yyyy-MM-dd') <= date('Y-m-d')) {
                    $show_assistance = true;
                }

                $show_assistance = false;
                if(!empty($training_dynamic) && $training_dynamic['scheduled']->i18nFormat('yyyy-MM-dd') <= date('Y-m-d')) {
                    $show_assistance = true;
                }
                $valid_certificate = false;
                if($training_dynamic->dynamic_level_info->gfe_id > 0){
                    $ent_certificate = $this->DataConsultation->find()->select(['DataConsultation.id', 'DataConsultation.status', 'DC.proceed', 'DataConsultation.uid'])
                    ->join([
                        'DC' => ['table' => 'data_consultation_plan', 'type' => 'LEFT', 'conditions' => 'DC.consultation_id = DataConsultation.id AND DC.deleted = 0'],
                    ])
                    ->where(['DataConsultation.patient_id' => USER_ID, 'DataConsultation.course_type_id' => $training_dynamic->dynamic_level_info->id, 'DataConsultation.status IN ' => array('INIT','CERTIFICATE'), 'DataConsultation.deleted' => 0])
                    ->first();

                    $showButton = true;
                    $message = '';
                    $cert_status = 'PENDING';
                    if(!empty($ent_certificate)){
                        if($ent_certificate['status'] == 'INIT'){
                            $certificate = false;
                            $showButton = true;
                            //$message = 'Waiting for the Examiner to give you the certificate.';
                            $cert_status = 'PENDING';
                        }else if($ent_certificate['status'] == 'CERTIFICATE') {
                            $certificate = true;
                            $showButton = false;
                            $valid_certificate = true;
                            $cert_status = 'DONE';
                            if($ent_certificate['DC']['proceed'] == 1){
                                $message = 'Certificate Received.';
                            }else {
                                $message = 'Certificate Denied.';
                            }
                        }
                    }
                    // end certificate
                    $certificate = array(
                        'show_button' => $showButton,
                        'message' => $message,
                        'valid_certificate' => $valid_certificate,
                        'cert_status' => $cert_status,
                        'required' => true,
                        'url' => ''
                    );
                } else {
                    $valid_certificate = true;
                    $certificate = array(
                        'show_button' => false,
                        'message' => '',
                        'valid_certificate' => true,
                        'cert_status' => 'PENDING',
                        'required' => false,
                        'url' => ''
                    );
                }
                
                if ($training_dynamic['attended'] == "0") {
                    // Procesar materiales dinámicos
                    $materials_ids = json_decode($training_dynamic->dynamic_level_info->materials, true);
                    $dynamic_materials = [];
                    if(!empty($materials_ids)) {
                        foreach($materials_ids as $material_id) {
                            $dynamic_materials[] = [
                                'title' => $material_id['name'],
                                'url' => $api_url . '?key=2fe548d5ae881ccfbe2be3f6237d7951&l3n4p=6092482f7ce858.91169218&action=get-file&token=6092482f7ce858.91169218&id=' . $material_id['id'],
                                'type' => 'pdf'
                            ];
                        }
                    }
                    
                    $res[] = array(
                        'id' => $training_dynamic['id'],
                        'title' => $training_dynamic['title'],
                        'scheduled' => $training_dynamic['scheduled']->i18nFormat('yyyy-MM-dd hh:mm a'),
                        'address' => $address,
                        'level' => $training_dynamic['level'],
                        'data_training_id' => $training_dynamic['data_training_id'],
                        'attended' => $training_dynamic['attended'],
                        'show_cancel' => $date > $scheduled ? false : true,
                        'data' => $dynamic_materials,
                        'ot' => 1,
                        'certificate' => $certificate,
                        'type' => $training_dynamic['level'],
                        'show_assistance_code' => $show_assistance,
                        'continue_sign' => false,
                        'today' => $today,
                        'coursed' => false,
                        'subscriptions' => []
                    );
                } else {
                    $materials_ids = json_decode($training_dynamic->dynamic_level_info->materials, true);
                    $dynamic_materials = [];
                    if(!empty($materials_ids)) {
                        foreach($materials_ids as $material_id) {
                            $dynamic_materials[] = [
                                'title' => $material_id['name'],
                                'url' => $api_url . '?key=2fe548d5ae881ccfbe2be3f6237d7951&l3n4p=6092482f7ce858.91169218&action=get-file&token=6092482f7ce858.91169218&id=' . $material_id['id'],
                                'type' => 'pdf'
                            ];
                        }
                    }
                    
                    $res[] = array(
                        'id' => $training_dynamic['id'],
                        'title' => 'Start Providing ' . $training_dynamic['title'] . ' Treatments',
                        'scheduled' => $training_dynamic['scheduled']->i18nFormat('yyyy-MM-dd hh:mm a'),
                        'address' => $address,
                        'level' => 'subscriptions',
                        'data_training_id' => $training_dynamic['data_training_id'],
                        'attended' => $training_dynamic['attended'],
                        'show_cancel' => $date > $scheduled ? false : true,
                        'data' => $dynamic_materials,
                        'ot' => 1,
                        'certificate' => $certificate,
                        'type' => $training_dynamic['level'],
                        'show_assistance_code' => false,
                        'continue_sign' => $valid_certificate ? true : false,
                        'today' => $today,
                        'coursed' => true,
                        'subscriptions' => $this->getOtherTreatmentsSubscriptions($training_dynamic['data_training_id'])
                    );


                }
            }

            // Procesar entrenamiento filler

            if(!empty($ent_training_fillers)){
                $isBeforeChange = $c_date > strtotime($ent_training_fillers->scheduled->i18nFormat('yyyy-MM-dd 08:00:00'));
                if ($isBeforeChange) $training['attended'] = "1";

                $this->loadModel('SpaLiveV1.DataCourses');
                $data_course = $this->DataCourses->find()->where(['DataCourses.user_id' => USER_ID,
                                                    'DataCourses.deleted' => 0,'DataCourses.status' => 'DONE'])->first();

                if(!empty($ent_training_fillers) && !empty($ent_training_fillers['scheduled']) && $ent_training_fillers['scheduled']->i18nFormat('yyyy-MM-dd') == date('Y-m-d')){
                    $today = true;
                }else{
                    $today = false;
                }

                $show_assistance = false;
                if(!empty($ent_training_fillers) && $ent_training_fillers['scheduled']->i18nFormat('yyyy-MM-dd') <= date('Y-m-d')) {
                    $show_assistance = true;
                }

                if(!empty($data_course)){
                    $res[] = array(
                        'id' => 0,
                        'title' => 'Start Providing Treatments',
                        'scheduled' => '',
                        'address' => '',
                        'level' => 'subscriptions',
                        'data_training_id' => '',
                        'attended' => '',
                        'data' => array(),
                        'ot' => 0,
                        'certificate' => array(
                            'show_button' => false,
                            'message' => '',
                            'valid_certificate' => true,
                            'cert_status' => 'PENDING',
                            'required' => false,
                            'url' => ''
                        ),
                        'type' => $ent_training_fillers['level'],
                        'show_assistance_code' => $show_assistance,
                        'continue_sign' => true,
                        'today' => $today,
                        'coursed' => false
                    );
                    $this->loadModel('SpaLiveV1.SysUsers');
                    
                    $this->set('coursed', true);
                }

                $scheduled_fill = $ent_training_fillers['scheduled']->i18nFormat('yyyy-MM-dd 08:00:00');
                $address = $ent_training_fillers->address.', '.$ent_training_fillers->city.', '.$ent_training_fillers->State['abv'].' '.$ent_training_fillers->zip;
                
                if ($ent_training_fillers['attended'] == "0") {
                    $advanced_count = 1;
                    $advanced = 'STUDING';
                    $res[] = array(
                        'id' => $ent_training_fillers['id'],
                        'title' => $ent_training_fillers['title'],
                        'scheduled' => $ent_training_fillers['scheduled']->i18nFormat('yyyy-MM-dd hh:mm a'),
                        'address' => $address,
                        'level' => $ent_training_fillers['level'],
                        'data_training_id' => $ent_training_fillers['data_training_id'],
                        'attended' => $ent_training_fillers['attended'],
                        'show_cancel' => false,
                        'data' => array(
                            [
                                'title' => 'MySpaLive video',
                                'url' => Configure::read('App.wordpress_domain') . '/myspa.mp4',
                                'type' => 'video'
                            ],
                            [
                                'title' => 'How to use the App',
                                'url' => Configure::read('App.wordpress_domain') . '/wp-content/uploads/howtouse.mp4',
                                'type' => 'video'
                            ]
                        ),
                        'ot' => 0,
                        'certificate' => array(
                            'show_button' => false,
                            'message' => '',
                            'valid_certificate' => true,
                            'cert_status' => 'PENDING',
                            'required' => false,
                            'url' => ''
                        ),
                        'type' => $ent_training_fillers['level'],
                        'show_assistance_code' => $show_assistance,
                        'continue_sign' => true,
                        'today' => $today,
                        'coursed' => false
                    );
                } else{ 
                    $res[] = array(
                        'id' => $ent_training_fillers['id'],
                        'title' => 'Start Providing ' . $ent_training_fillers['level'] . ' Treatments',
                        'scheduled' => $ent_training_fillers['scheduled']->i18nFormat('yyyy-MM-dd hh:mm a'),
                        'address' => $address,
                        'level' => 'subscriptions',
                        'data_training_id' => $ent_training_fillers['data_training_id'],
                        'attended' => $ent_training_fillers['attended'],
                        'show_cancel' => false,
                        'data' => array(
                            [
                                'title' => 'MySpaLive video',
                                'url' => Configure::read('App.wordpress_domain') . '/myspa.mp4',
                                'type' => 'video'
                            ],
                            [
                                'title' => 'How to use the App',
                                'url' => Configure::read('App.wordpress_domain') . '/wp-content/uploads/howtouse.mp4',
                                'type' => 'video'
                            ]
                        ),
                        'ot' => 0,
                        'certificate' => array(
                            'show_button' => false,
                            'message' => '',
                            'valid_certificate' => true,
                            'cert_status' => 'PENDING',
                            'required' => false,
                            'url' => ''
                        ),
                        'type' => $ent_training_fillers['level'],
                        'show_assistance_code' => false,
                        'continue_sign' => true,
                        'today' => $today,
                        'coursed' => false
                    );
                }
            }

            $data_reschedule = $this->DataRescheduleTrainings->find()
            ->where(['DataRescheduleTrainings.user_id' => USER_ID, 'DataRescheduleTrainings.level_training' => 'LEVEL 1', 'DataRescheduleTrainings.deleted' => 0])->first();
            
            if(!empty($data_reschedule)){
                $data_reschedule->reschedule_count ++;
                if($data_reschedule->reschedule_count >= 2){
                    $this->set('reschedule_popup_b', "A $50 fee is required to reschedule this class again.\n\nPlease confirm to cancel this class.");
                    $this->set('reschedule_b', true);
                }else {
                    $this->set('reschedule_popup_b', "There is no cost to reschedule your class the first time, but there will be a $50 fee if you reschedule it again.\n\nDo you want to reschedule this class?");
                    $this->set('reschedule_b', false);
                }
            } else{
                $this->set('reschedule_popup_b', "There is no cost to reschedule your class the first time, but there will be a $50 fee if you reschedule it again.\n\nDo you want to reschedule this class?");
                $this->set('reschedule_b', false);
            }

            $buy_level_3 = $this->DataPayment->find()
                ->where([
                    'id_from'   => USER_ID,
                    'type IN'   => ['ADVANCED COURSE', 'LEVEL_TWO_DUAL_TOX_AND_DEMALL_FILLER'],
                    'is_visible' => 1,
                    'intent <>'  => '',
                    'payment <>' => '',
                    'refund_id'  => 0
                ])
                ->first();

            $advanced_count = 0;
            $advanced = 'BUY';

            if(!empty($buy_level_3) && $buy_level_3->type == 'ADVANCED COURSE'){
                $advanced = 'BOOK';

                if(!empty($ent_training_adv)){
                    $isBeforeChange = $c_date > strtotime($ent_training_adv->scheduled->i18nFormat('yyyy-MM-dd 08:00:00'));
                    if ($isBeforeChange) $training['attended'] = "1";

                    $this->loadModel('SpaLiveV1.DataCourses');
                    $data_course = $this->DataCourses->find()->where(['DataCourses.user_id' => USER_ID,
                                                        'DataCourses.deleted' => 0,'DataCourses.status' => 'DONE'])->first();

                    if(!empty($data_course)){
                        $res[] = array(
                            'id' => 0,
                            'title' => 'Start Providing Treatments',
                            'scheduled' => '',
                            'address' => '',
                            'level' => 'subscriptions',
                            'data_training_id' => '',
                            'attended' => '',
                            'data' => array(),
                            'ot' => 0
                        );
                        $this->loadModel('SpaLiveV1.SysUsers');
                        
                        $this->set('coursed', true);
                    }

                    
                    if ($ent_training_adv['attended'] == "0") {
                        $advanced_count = 1;
                        $advanced = 'STUDING';
                        $scheduled_adv = $ent_training_adv['scheduled']->i18nFormat('yyyy-MM-dd 08:00:00');
                        $address = $ent_training_adv->address.', '.$ent_training_adv->city.', '.$ent_training_adv->State['abv'].' '.$ent_training_adv->zip;
                        $res[] = array(
                            'id' => $ent_training_adv['id'],
                            'title' => $ent_training_adv['title'],
                            'scheduled' => $ent_training_adv['scheduled']->i18nFormat('yyyy-MM-dd hh:mm a'),
                            'address' => $address,
                            'level' => $ent_training_adv['level'],
                            'data_training_id' => $ent_training_adv['data_training_id'],
                            'attended' => $ent_training_adv['attended'],
                            'show_cancel' => $date > $scheduled_adv ? false : true,
                            'data' => array(
                                [
                                    'title' => 'MySpaLive video',
                                    'url' => Configure::read('App.wordpress_domain') . '/myspa.mp4',
                                    'type' => 'video'
                                ],
                                [
                                    'title' => 'Glossary of Terms for Neurotoxin Injection Training',
                                    'url' =>
                                        Configure::read('App.wordpress_domain') . '/wp-content/uploads/Glossary_of_Terms_for_Neurotoxin_Injection_Training.pdf',
                                    'type' => 'pdf'
                                ],
                                [
                                    'title' => 'MySpaLive Level One Pre-Study Guide',
                                    'url' =>
                                        Configure::read('App.wordpress_domain') . '/wp-content/uploads/MySpaLive_Level_One_Pre_Study_Guide.pdf',
                                    'type' => 'pdf'
                                ],
                                [
                                    'title' => 'How to use the App',
                                    'url' => Configure::read('App.wordpress_domain') . '/wp-content/uploads/howtouse.mp4',
                                    'type' => 'video'
                                ]
                            ),
                            'ot' => 0,
                            'subscriptions' => []
                        );
                    } else{ 
                        $advanced_count = 2;
                        $advanced = 'CERTIFICATE';
                        $res2[] = array(
                            'title' => 'Advanced Training',
                            'data_training_id' => $ent_training_adv['data_training_id'],
                        );
                    }
                }
            }

            $data_reschedule = $this->DataRescheduleTrainings->find()
            ->where(['DataRescheduleTrainings.user_id' => USER_ID, 'DataRescheduleTrainings.level_training' => 'LEVEL 2', 'DataRescheduleTrainings.deleted' => 0])->first();
            
            if(!empty($data_reschedule)){
                $data_reschedule->reschedule_count ++;
                if($data_reschedule->reschedule_count >= 2){
                    $this->set('reschedule_popup_a', "A $50 fee is required to reschedule this class again.\n\nPlease confirm to cancel this class.");
                    $this->set('reschedule_a', true);
                }else {
                    $this->set('reschedule_popup_a', "There is no cost to reschedule your class the first time, but there will be a $50 fee if you reschedule it again.\n\nDo you want to reschedule this class?");
                    $this->set('reschedule_a', false);
                }
            } else{
                $this->set('reschedule_popup_a', "There is no cost to reschedule your class the first time, but there will be a $50 fee if you reschedule it again.\n\nDo you want to reschedule this class");
                $this->set('reschedule_a', false);
            }

            $level_3 = 'HIDE';
            $level_3_count = 0;

            if(!empty($buy_level_3)){
                $level_3 = 'BUY';
                $ent_level_3 = $this->DataPayment->find()
                ->where(['id_from' => USER_ID, 'type' => 'ADVANCED TECHNIQUES MEDICAL', 'is_visible' => 1, 'intent <>' => '', 'payment <>' => '', 'refund_id' => 0])
                ->first();

                if(!empty($ent_level_3)){
                    $level_3 = 'BOOK';

                    if(!empty($ent_training_level_3)){
                        if ($ent_training_level_3['attended'] == "0") {
                            $level_3_count = 1;
                            $level_3 = 'STUDING';
                            $scheduled_adv = $ent_training_level_3['scheduled']->i18nFormat('yyyy-MM-dd 08:00:00');
                            $address = $ent_training_level_3->address.', '.$ent_training_level_3->city.', '.$ent_training_level_3->State['abv'].' '.$ent_training_level_3->zip;
                            $res[] = array(
                                'id' => $ent_training_level_3['id'],
                                'title' => $ent_training_level_3['title'],
                                'scheduled' => $ent_training_level_3['scheduled']->i18nFormat('yyyy-MM-dd hh:mm a'),
                                'address' => $address,
                                'level' => $ent_training_level_3['level'],
                                'data_training_id' => $ent_training_level_3['data_training_id'],
                                'attended' => $ent_training_level_3['attended'],
                                'show_cancel' => $date > $scheduled_adv ? false : true,
                                'data' => array(
                                    [
                                        'title' => 'MySpaLive video',
                                        'url' => Configure::read('App.wordpress_domain') . '/myspa.mp4',
                                        'type' => 'video'
                                    ],
                                    [
                                        'title' => 'How to use the App',
                                        'url' => Configure::read('App.wordpress_domain') . '/wp-content/uploads/howtouse.mp4',
                                        'type' => 'video'
                                    ]
                                ),
                                'ot' => 0,
                                'subscriptions' => []
                            );
                        } else{
                            $level_3_count = 2;
                            $level_3 = 'CERTIFICATE';
                            $res2[] = array(
                                'title' => 'Level 3',
                                'data_training_id' => $ent_training_level_3['data_training_id'],
                            );
                        }
                    }
                }
            }

            // OTHER COURSES LIST

            $arr_other_courses_list = [];

            if (count($ent_training) == 0) {
                $ent_payments_basic = $this->DataPayment->find()->where(['DataPayment.refund_id' => 0, 'DataPayment.id_from' => USER_ID, 
                                                                 'DataPayment.type' => 'BASIC COURSE', 'DataPayment.payment !=' => '', 
                                                                 'DataPayment.is_visible' => 1])->first();
                                                                 
                if (empty($ent_payments_basic)) $basic_course_status = 'BUY';
                else $basic_course_status = 'BOOK';
                
                $arr_other_courses_list[] = [
                    'title' => 'NEUROTOXINS BASIC',
                    'status' => $basic_course_status,
                    'type' => 'BASIC',
                    'course_type_id' => 0
                ];
                        
            }

            if ($ent_training_fillers) {
                $arr_other_courses_list[] = [
                    'title' => 'Fillers',
                    'status' => 'BUY',
                    'type' => 'FILLER',
                    'course_type_id' => 0
                ];
            }

            // if (count($ent_training) > 0 && $advanced == 'BUY')  {
                if($advanced == 'BUY' || $advanced == 'BOOK'){
                    $arr_other_courses_list[] = [
                        'title' => 'NEUROTOXINS ADVANCED',
                        'status' => $advanced,
                        'type' => 'ADVANCED',
                        'course_type_id' => 0
                    ];
                }
            // }

            if ($level_3 == 'BUY' || $level_3 == 'BOOK')  {
                $arr_other_courses_list[] = [
                    'title' => 'LEVEL 3',
                    'status' => $level_3,
                    'type' => 'LEVEL3',
                    'course_type_id' => 0
                ];
            }

            // Find available courses per type
            
            $__join = [
                'DataTrainigs' => ['table' => 'data_trainings', 'type' => 'LEFT', 'conditions' => 'CatTrainigs.id = DataTrainigs.training_id AND DataTrainigs.deleted = 0 AND DataTrainigs.user_id = ' . USER_ID],
                'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = CatTrainigs.state_id'],
                'DataPayment' => ['table' => 'data_payment', 'type' => 'LEFT', 'conditions' => 'DataPayment.id_from = ' . USER_ID . ' AND DataPayment.type = CatTrainigs.level AND DataPayment.payment <> "" AND DataPayment.is_visible = 1'],
                'CourseType' => ['table' => 'cat_courses_type', 'type' => 'LEFT', 'conditions' => 'CourseType.name_key =  CatTrainigs.level AND CourseType.deleted = 0'],
            ];

            $__fields = ['CatTrainigs.id', 'CatTrainigs.created','CatTrainigs.title', 'CatTrainigs.scheduled', 'CatTrainigs.neurotoxins', 'CatTrainigs.fillers', 'CatTrainigs.materials', 'CatTrainigs.available_seats', 'CatTrainigs.level','State.name','State.abv','CatTrainigs.address','CatTrainigs.zip','CatTrainigs.city', 'data_training_id' => 'DataTrainigs.id','DataPayment.id','CourseType.require_msl_basic'];
            
            foreach($dynamic_levels as $dynamic_level) {
                if (in_array($dynamic_level->name_key,$added_cats)) continue;
                
                $_where_dynamic_ = [
                    'CatTrainigs.deleted' => 0,
                    'CatTrainigs.level' => $dynamic_level->name_key,
                    // 'CatTrainigs.scheduled > NOW()'
                ];
                
                $dynamic_trainings = $this->CatTrainigs->find()
                    ->select($__fields)
                    ->join($__join)
                    ->where($_where_dynamic_)
                    ->first();



                $status = 'BUY';

               
                if (!empty($dynamic_trainings) && !empty($dynamic_trainings['DataPayment']['id'])) {
                     $status = 'BOOK';
                }

                if (isset($dynamic_trainings['CourseType']) && isset($dynamic_trainings['CourseType']['require_msl_basic']) && $dynamic_trainings['CourseType']['require_msl_basic'] == 1) {
                    $hasBasicCourse = CourseController::validateBasicTraining($this);
                    if (!$hasBasicCourse) continue;
                }
               
                $arr_other_courses_list[] = [
                    'title' => $dynamic_level->title,
                    'status' => $status,
                    'type' => 'OTHERCOURSE',
                    'course_type_id' => $dynamic_level->id
                ];
                

            }



            $this->set('other_courses_list', $arr_other_courses_list);

            // VALIDAR NO OFREZCA ADVANCED SI NO TIENE BASICO
            
            $this->set('other_courses', true);
            // if($advanced_count > 0 && $level_3_count > 0){
            //     $this->set('other_courses', false);
            // }

            if ($advanced == 'HIDE' && $level_3 == 'HIDE')
                $this->set('other_courses', false);
            // Ordenar todos los resultados por fecha
            usort($res, function($a, $b) {
                if(empty($a['scheduled']) && empty($b['scheduled'])) return 0;
                if(empty($a['scheduled'])) return 1;
                if(empty($b['scheduled'])) return -1;
                return strtotime($a['scheduled']) - strtotime($b['scheduled']);
            });

            $this->set('training', $res);
            $this->set('training_cert', $res2);

            $this->set('number', '9727553038');
            $this->set('number_label', '(972) 755 3038');

            $therapy = new TherapyController();
            $iv_status = $therapy->check_application();            
            $this->set('iv_status', $iv_status);

            $this->set('training', $res);
            $this->success();
        }else{
            $therapy = new TherapyController();
            $iv_status = $therapy->check_application();            
            $this->set('iv_status', $iv_status);
            $this->message('No training found.');
        }
    }

    private function getOtherTreatmentsSubscriptions($data_training_id) {

        $this->loadModel('SpaLiveV1.DataTrainings');
        $ent_data_trainings = $this->DataTrainings
        ->find()->select([
            'name' => 'OtherTreatment.name',
            'require_mdsub' => 'OtherTreatment.require_mdsub',
            'msl' => 'CatAgreementMSL.uid',
            'md' => 'CatAgreementMD.uid',
            'md_agreement' => 'DataAgreementMD.id',
            'msl_agreement' => 'DataAgreementMSL.id',
        ])
        ->join([
            'Training' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'Training.id = DataTrainings.training_id'],
            'CourseType' => ['table' => 'cat_courses_type', 'type' => 'INNER', 'conditions' => 'CourseType.name_key = Training.level'],
            'Coverage' => ['table' => 'data_coverage_courses', 'type' => 'INNER', 'conditions' => 'Coverage.course_type_id = CourseType.id'],
            'OtherTreatment' => ['table' => 'sys_treatments_ot', 'type' => 'INNER', 'conditions' => 'OtherTreatment.id = Coverage.ot_id AND OtherTreatment.deleted = 0'],
            'CatAgreementMSL' => [
                'table'      => 'cat_agreements',
                'type'       => 'LEFT',
                'conditions' =>
                    "CatAgreementMSL.state_id = " . USER_STATE . "
                     AND CatAgreementMSL.agreement_type = 'OTHER_TREATMENTS'
                     AND CatAgreementMSL.other_treatment_id = OtherTreatment.id
                     AND CatAgreementMSL.deleted = 0
                     AND CatAgreementMSL.issue_type = 'MSL'"
            ],
            'CatAgreementMD' => [
                'table'      => 'cat_agreements',
                'type'       => 'LEFT',
                'conditions' =>
                    "CatAgreementMD.state_id = " . USER_STATE . "
                     AND CatAgreementMD.agreement_type = 'OTHER_TREATMENTS'
                     AND CatAgreementMD.other_treatment_id = OtherTreatment.id
                     AND CatAgreementMD.deleted = 0
                     AND CatAgreementMD.issue_type = 'MD'"
            ],
            'DataAgreementMSL' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'DataAgreementMSL.agreement_uid = CatAgreementMSL.uid AND DataAgreementMSL.deleted = 0 AND DataAgreementMSL.user_id = ' . USER_ID],
            'DataAgreementMD' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'DataAgreementMD.agreement_uid = CatAgreementMD.uid AND DataAgreementMD.deleted = 0 AND DataAgreementMD.user_id = ' . USER_ID],
        ])
        ->where([
                'DataTrainings.id' => $data_training_id,
                'OtherTreatment.deleted' => 0,
                'OR' => [
                    'CatAgreementMSL.uid IS NOT' => null,
                    'CatAgreementMD.uid IS NOT'  => null,
                ],
            ])
        ->group(['CatAgreementMD.id','CatAgreementMSL.id'])
        ->all();

        $subs = [];
        $pos = 1;
        foreach($ent_data_trainings as $row) {
            $subs[] = [
                'title' => $row->name,
                'agreement_md' => !empty($row->md) && empty($row->md_agreement) && $row->require_mdsub == 1 ? $row->md : '',
                'agreement_msl' => !empty($row->msl && empty($row->msl_agreement)) ? $row->msl : '',
            ];
            $pos++;
        }

        return $subs;
    }

    public function save_course(){
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.CatCourses');
        $this->loadModel('SpaLiveV1.DataCourses');
        $this->loadModel('SpaLiveV1.DataInjectorRegistered');
        $this->loadModel('SpaLiveV1.DataValidateCourse');
        $this->loadModel('SpaLiveV1.CatStates');
        $isDev = env('IS_DEV', false);

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

        $data_schools = get('schools', '');
        $schools = json_decode($data_schools);

        $is_register = get('is_register', false);

        $has_filler_in_schools = false;
        $has_neurotoxins_in_schools = false;
        if(empty($schools)){
            $this->message('Schools empty.');
            return;
        }

        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $user["user_id"], 'SysUsers.deleted' => 0])->first();
        $arr_mail_sended = array();
        foreach($schools as $s) {

            $ent_cat_course = $this->CatCourses->find()
            ->select(['DSR.email','DSR.nameschool', 'CatCourses.title','CatCourses.school_id','CatCourses.type'])
            ->join([
                'DSR' => ['table' => 'data_school_register', 'type' => 'INNER', 'conditions' => 'DSR.id = CatCourses.school_id'],
                ])
            ->where(['CatCourses.id' => $s->courses, 'CatCourses.deleted' => 0])
            ->first();

            if(empty($ent_cat_course)){
                $this->message('Error school.');
                return;
            }

            $data_course = $this->DataCourses->find()->where(['DataCourses.course_id' => $s->courses, 'DataCourses.user_id' => $user["user_id"],
                                                        'DataCourses.deleted' => 0])->first();
            $array_datas = [];
            if(empty($data_course)){
            
                $array_save = array(
                    'user_id' => $user["user_id"],
                    'course_id' => $s->courses,
                    'training_id' => 0,
                    'status' => 'PENDING',
                    'payment_intent' => '',
                    'payment' => '',
                    'receipt' => '',
                    'deleted' => 0,
                    'created' => date('Y-m-d H:i:s'),
                    'front' => $s->front,
                    'back' => $s->back == 0 ? null : $s->back,
                    'file_id' => $s->protocol_id == 0 ? null : $s->protocol_id,
                );

                $ent = $this->DataCourses->newEntity($array_save);
                if(!$ent->hasErrors()){
                    $this->DataCourses->save($ent);
                    $array_s = array(
                        'user_id' => USER_ID,
                        'date_start' => date('Y-m-d H:i:s'),
                        'type' => 'OTHER_COURSE',
                        'deleted' => 0
                    );
                    $this->log(__LINE__ . ' ' . json_encode($array_s));
                    if(empty($exist)){              
                        $entity = $this->DataInjectorRegistered->newEntity($array_s);
                        if(!$entity->hasErrors()){
                            $this->DataInjectorRegistered->save($entity);
                            $this->log(__LINE__ . ' DataInjectorRegistered ' );
                        }
                    }
                }

                
                $array_ids = [$s->front, $s->back];

                foreach($array_ids as $row){
                    if($row>0){
                        $file = $this->Files->get_data_file($row);
                        
                        $file_name = $file['name'];                        
                        $substringToFind = ".stub";    
                        $mimetype = $file['mimetype'];                        
                        $ext = explode('/',$mimetype);
                        if(count($ext)>1){
                            $ext = $ext[1];                            
                            if($ext == 'jpeg'){
                                $replacement = ".jpg";                        
                            }else if ($ext == 'png'){
                                $replacement = ".png";
                            }else{
                                $replacement = "";
                            }
                            if($replacement != "" && $replacement != null){
                                $file_name = str_ireplace($substringToFind, $replacement, $file_name);
                            }
                        }                        
                        $file_name = uniqid() . '.' . $file_name;                                                
                        $path = TMP . DS . $file_name;                        
                        //$directory = '/path/to/directory/'; 
                        //$filename = 'example.txt';
                        $fileContents = $file['data'];
                        //$fileWithPath = $directory . $filename;
                        file_put_contents($path, $fileContents);

                        $array_datas[] = new CURLFile($path);
                    }
                }
                
                $key1 = Text::uuid();
                
                
                
                //$key1 = Text::uuid();
                
                $array_save_validate = array(
                    'data_course_id' => $ent->id,
                    'key1' => $key1,
                    'active' => 1,
                    'user_id' => $user["user_id"]
                );

                $c_entity = $this->DataValidateCourse->newEntity($array_save_validate);
                if(!$c_entity->hasErrors()) {
                    $this->DataValidateCourse->save($c_entity);    
                    //$this->sendEmalSchools($ent_cat_course['DSR']['email'], $key1, USER_NAME. ' ' . USER_LNAME, $array_datas ,$ent_cat_course['DSR']['nameschool']);  
                    
                    $this->sendEmalSchoolsAdded($ent_cat_course['DSR']['email'], $key1, USER_NAME. ' ' . USER_LNAME, $array_datas, $ent_cat_course['DSR']['nameschool'], $ent_cat_course['title'], $ent_user->dob);
                    $arr_mail_sended[]= $ent_cat_course['school_id'];                    
                }
            }else{
                $ent = $data_course;
                
                $array_datas=[];
                $array_ids = [$ent->front, $ent->back];
                foreach($array_ids as $row){
                    if($row>0){
                        $file = $this->Files->get_data_file($row);                        
                        $file_name = $file['name'];                        
                        $substringToFind = ".stub";    
                        $mimetype = $file['mimetype'];                        
                        $ext = explode('/',$mimetype);
                        if(count($ext)>1){
                            $ext = $ext[1];                            
                            if($ext == 'jpeg'){
                                $replacement = ".jpg";                        
                            }else if ($ext == 'png'){
                                $replacement = ".png";
                            }else{
                                $replacement = "";
                            }
                            if($replacement != "" && $replacement != null){
                                $file_name = str_ireplace($substringToFind, $replacement, $file_name);
                            }
                        }                        
                        $file_name = uniqid() . '.' . $file_name;                        
                        $path = TMP . DS . $file_name;
                        //$directory = '/path/to/directory/';
                        //$filename = 'example.txt';
                        $fileContents = $file['data'];
                        //$fileWithPath = $directory . $filename;
                        file_put_contents($path, $fileContents);
                        $array_datas[] = new CURLFile($path);
                    }
                }                        
                $key1 = Text::uuid();
                
                $array_save_validate = array(
                    'data_course_id' => $ent->id,
                    'key1' => $key1,
                    'active' => 1,
                    'user_id' => USER_ID
                );    
                $c_entity = $this->DataValidateCourse->newEntity($array_save_validate);
                if(!$c_entity->hasErrors()) {
                    $this->DataValidateCourse->save($c_entity);     
                    //$this->sendEmalSchools($ent_cat_course['DSR']['email'], $key1, USER_NAME. ' ' . USER_LNAME, $array_datas,$ent_cat_course['DSR']['nameschool']);
                    
                    $this->sendEmalSchoolsAdded(/*"felipe@advantedigital.com"*/ $ent_cat_course['DSR']['email'], $key1, USER_NAME. ' ' . USER_LNAME, $array_datas, $ent_cat_course['DSR']['nameschool'], $ent_cat_course['title'], $ent_user->dob);
                    $arr_mail_sended[]= $ent_cat_course['school_id'];                    
                }
            }

            if($ent_cat_course['type'] == 'FILLERS'){
                $has_filler_in_schools = true;
            }else{
                $has_neurotoxins_in_schools = true;
            }
        }

        $Main = new MainController();
        $Main->notify_devices('APPLY_SCHOOL', array(USER_ID), false, true);
    

        if (strpos(strtolower(USER_NAME), 'test') === false || strpos(strtolower(USER_LNAME), 'test') === false) {
            
            if(!$isDev){
                /*try {           
                    $sid    = env('TWILIO_ACCOUNT_SID'); 
                    $token  = env('TWILIO_AUTH_TOKEN'); 
                    $twilio = new Client($sid, $token); 
                        
                    $message = $twilio->messages 
                        ->create( '+1' . '9034366629', // to 
                                array(  
                                    "messagingServiceSid" => "MG65978a5932f4ba9dd465e05d7b22195e",      
                                    "body" => 'New injector register from another school: ' . USER_NAME . ' ' . USER_LNAME . ' (' . USER_PHONE .')'
                                ) 
                        ); 
                } catch (TwilioException $e) {

                }*/
                $user_state = '';
                $user_st = $this->CatStates->find()->where(['CatStates.id' => USER_STATE, 'CatStates.deleted' => 0])->first();
                if(!empty($ent_user)){
                    $user_state = $user_st->name;
                }
                $ent_devices = array(
                    '9034366629',
                    //'9729003944'
                );
                foreach($ent_devices as $ele) {
                
                    $phone_number = '+1' . $ele;      $this->log(__LINE__ . 'New injector register from another school: ' . $phone_number . ' ' . $user_state);           
                    try {     
                        $sid    = env('TWILIO_ACCOUNT_SID'); 
                        $token  = env('TWILIO_AUTH_TOKEN'); 
                        $twilio = new Client($sid, $token); 

                        $twilio_message = $twilio->messages 
                                ->create($phone_number, // to 
                                        array(  
                                            "messagingServiceSid" => "MG65978a5932f4ba9dd465e05d7b22195e",      
                                            "body" => 'New injector register from another school: ' . USER_NAME . ' ' . USER_LNAME . ' ' . $user_state . ' (' . USER_PHONE .')' . date('m-d-Y') 
                                        ) 
                                );
    
                    } catch (TwilioException $e) {
                    $this->log(__LINE__ . " TwilioException ". $phone_number . " ".  json_encode($e->getCode()));
                    }                        
                }
            }
            
            /*if($is_register){
                $Login = new LoginController();
                $Login->reassing_representative();
            }*/
        }
        
        if(!empty($ent_user)){

            if($ent_user->steps=="HOME"||$ent_user->steps=="STARTPROVIDINGTREATMENTS"||$ent_user->steps=="CERTIFICATESCHOOLAPPROVED"||$ent_user->steps=="FILLERSAPPROVED"){

                $this->success();

            }else{
                if($has_filler_in_schools && $has_neurotoxins_in_schools){
                    $ent_user->steps = 'WAITINGSCHOOLAPPROVAL';
                }else if($has_filler_in_schools){
                    $ent_user->steps = 'WAITINGFILLERSAPPROVAL';
                }else{
                    $ent_user->steps = 'WAITINGSCHOOLAPPROVAL';
                }

                if($this->SysUsers->save($ent_user)){
                    $this->success();
                }
            }
        }
    }

    public function upload_file() {

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

        $this->set('file_id', $_file_id);
        $this->success();
    }

    public function test_sendEmal(){
        $jsonData = '[
            {
                "name": "https://www.google.com/url?sa=i&url=https%3A%2F%2Fwww.wikidex.net%2Fwiki%2FPok%25C3%25A9dex&psig=AOvVaw0lSGGmVTixwFWBWHqV34Kh&ust=1695320525285000&source=images&cd=vfe&opi=89978449&ved=0CBAQjRxqFwoTCPjK4J7nuYEDFQAAAAAdAAAAABAE"
            }
        ]';
        
        $data = json_decode($jsonData, true); // El segundo parámetro true convierte el resultado en un array asociativo
        
    }

    public function sendEmalSchools($email, $key, $user_name, $arr_image_save, $school="") {
        
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
                                            <center><b style="text-align: left; font-size: 20px; color: #1D6782;">Hello! '.$school.'</b><br></center>
                                            <div><p style="font-size: 17px;">The person: ' .$user_name. ' is indicating that he/she took the course (Trial course).</p><p>Please verify that the user took the course at the school.</p><br></div>
                                            <center>
                                                <a href="'.$this->URL_API.'?key=225sadfgasd123fgkhijjdsadfg16578g12gg3gh&action=Course____validate_course&code='.$key.'&rec=DONE" style="background-color: #1D6782; color:white; cursor: pointer; border-color: transparent; margin: 4px 2px; font-size:16px; padding: 9px 26px; text-align: center; text-decoration: none; display: inline-block; border-radius: 42px;">CONFIRM</a>
                                                <a href="'.$this->URL_API.'?key=225sadfgasd123fgkhijjdsadfg16578g12gg3gh&action=Course____validate_course&code='.$key.'&rec=REJECTED" style="background-color: #EC7063; color:white; cursor: pointer; border-color: transparent; margin: 4px 2px; font-size:16px; padding: 9px 26px; text-align: center; text-decoration: none; display: inline-block; border-radius: 42px;">CANCEL</a><br><br>
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
        
        $data = array(
            'from'      => 'MySpaLive <noreply@mg.myspalive.com>',
            'to'    => $email.",francisco@advantedigital.com",   
            'subject'=> '✅ Student verification',
            'html' => $str_message,
            //'attachment[1]' => $attachment,
            //'attachment[1]' => $arr_image_save[0],
            //'attachment' => curl_file_create($arr_image_save[0]->name, 'application/jpeg'),
            
        );
        $attachment = [];  
        for ($i=0; $i < count($arr_image_save); $i++) {
            
            //$attachment += 'attachment['.($i+1).']' . curl_file_create($arr_image_save[$i]->name, 'application/jpeg');
            $data['attachment['.$i.']'] = curl_file_create($arr_image_save[$i]->name, 'application/jpeg');
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
        //curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type:multipart/form-data"]);

        $result = curl_exec($curl);
        curl_close($curl);
        $this->success();
    }

    
    public function sendEmalSchoolsAdded($email, $key, $user_name, $arr_image_save, $school="", $certificate="", $dob) {
        
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
                                            <center><b style="text-align: left; font-size: 20px; color: #1D6782;">Hello! '.$school.',</b><br></center>
                                            <div>
                                                <p style="font-size: 17px;">We hope this message finds you well.</p>
                                            </div>
                                            <div>
                                                <p style="font-size: 17px;">We have received an application from ' .$user_name. ' , born on  '.$dob.', expressing interest in joining the MySpaLive platform. As part of our verification process, the applicant has indicated that they graduated from your esteemed institution and have obtained the following certification: ' .$certificate. '.</p>
                                                <p style="font-size: 17px;">We kindly request your assistance in confirming the accuracy of this information.</p><br>
                                            </div>
                                            <center>
                                                <a href="'.$this->URL_API.'?key=225sadfgasd123fgkhijjdsadfg16578g12gg3gh&action=Course____validate_course&code='.$key.'&rec=DONE" style="background-color: #1D6782; color:white; cursor: pointer; border-color: transparent; margin: 4px 2px; font-size:16px; padding: 9px 26px; text-align: center; text-decoration: none; display: inline-block; border-radius: 42px;">Confirm</a>
                                                <a href="'.$this->URL_API.'?key=225sadfgasd123fgkhijjdsadfg16578g12gg3gh&action=Course____validate_course&code='.$key.'&rec=REJECTED" style="background-color: #EC7063; color:white; cursor: pointer; border-color: transparent; margin: 4px 2px; font-size:16px; padding: 9px 29px; text-align: center; text-decoration: none; display: inline-block; border-radius: 42px;">Reject</a><br><br>
                                            </center>
                                            <div>
                                                <p style="font-size: 17px;">Your prompt response would be greatly appreciated to facilitate a seamless onboarding process for our potential injectors.</p>
                                                <p style="font-size: 17px;">Thank you for your cooperation and assistance in this matter.</p>
                                                <p style="font-size: 17px;">Warm regards,</p>
                                                <p style="font-size: 17px;">The MySpaLive Verification Team</p>
                                            </div>
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
        
        $data = array(
            'from'      => 'MySpaLive <noreply@mg.myspalive.com>',
            'to'    => $email.",francisco@advantedigital.com",   
            'subject'=> '✅ Student verification',
            'html' => $str_message,
            //'attachment[1]' => $attachment,
            //'attachment[1]' => $arr_image_save[0],
            //'attachment' => curl_file_create($arr_image_save[0]->name, 'application/jpeg'),
            
        );
        $attachment = [];  
        for ($i=0; $i < count($arr_image_save); $i++) {
            
            //$attachment += 'attachment['.($i+1).']' . curl_file_create($arr_image_save[$i]->name, 'application/jpeg');
            $data['attachment['.$i.']'] = curl_file_create($arr_image_save[$i]->name, 'application/jpeg');
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
        //curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type:multipart/form-data"]);

        $result = curl_exec($curl);
        curl_close($curl);
        $this->success();

    }
    
    public function deny_want_upload_more_certificates(){

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

        $this->loadModel('SpaLiveV1.SysUsers');
        
        $user = $this->SysUsers->find('all', [
            'conditions' => [
                'SysUsers.id' => USER_ID
            ]
        ])->first();

        if($user->steps == 'CERTIFICATESCHOOLDENIED '){
            $this->SysUsers->updateAll([
                'steps' => 'BASICCOURSE'
            ], [
                'SysUsers.id' => USER_ID
            ]);
            //$this->message('You have successfully skipped the advanced course.');
        }

        $this->success();
    }


    

    public function validate_course(){
        $key = get('code', '');
        
        if(empty($key)){
            $this->message('Empty key.');
            return;
        }
        
        $rec = get('rec', '');
        if(empty($rec)){
            $this->message('Empty rec.');
            return;
        }
        
        $this->loadModel('SpaLiveV1.DataValidateCourse');

        $ent_validate = $this->DataValidateCourse->find()->where(['DataValidateCourse.key1' => $key, 'DataValidateCourse.active' => 1])->first();

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

        $this->loadModel('SpaLiveV1.DataCourses');

        $this->DataCourses->updateAll(
            ['status' => $rec],
            ['id' => $ent_validate->data_course_id]
        );

        $this->DataValidateCourse->updateAll(
            ['active' => 0],
            ['id' => $ent_validate->id]
        );

        $Main = new MainController();

        if ($rec == 'DONE'){
            $this->loadModel('SpaLiveV1.SysLicence');
            $this->loadModel('SpaLiveV1.SysUsers');

            $licence_approve = $this->SysLicence->find()->where(['SysLicence.user_id' => $ent_validate->user_id, 'status' => 'APPROVED'])->first();
            if(!empty($licence_approve)){
                $this->SysUsers->updateAll(
                    ['steps' => 'CERTIFICATESCHOOLAPPROVED'],
                    ['SysUsers.id' => $ent_validate->user_id, 'SysUsers.deleted' => 0, 'steps' => 'WAITINGSCHOOLAPPROVAL']
                );
                //$Main->notify_devices('APPROVED_APPLICATION_OS',array($ent_validate->user_id),true,true,true,array(),'',array(),true);
                //$Main->notify_devices('APPROVED_SCHOOL',array($ent_validate->user_id),true,true,true,array(),'',array(),true);
            } 
            // $this->SysUsers->updateAll(
            //     //['login_status' => 'READY', 'steps' => 'MSLSUBSCRIPTION'],
            //     ['login_status' => 'READY', 'steps' => 'CERTIFICATESCHOOLAPPROVED'],
            //     // ['steps' => 'WAITINGSCHOOLAPPROVAL'],
            //     ['SysUsers.id' => $ent_validate->user_id, 'SysUsers.deleted' => 0]
            // );

            $validation = '
                <div class="success alert box-succes">
                    <div class="alert-body">
                        You have successfully confirmed the applicant!
                    </div>
                </div>
            ';
        } else if ($rec == 'REJECTED'){
            $this->loadModel('SpaLiveV1.SysLicence');
            $this->loadModel('SpaLiveV1.SysUsers');

            $licence_reject = $this->SysLicence->find()->where(['SysLicence.user_id' => $ent_validate->user_id, 'status' => 'REJECTED'])->first();
            if(!empty($licence_reject)){
                $this->SysUsers->updateAll(
                    ['steps' => 'CERTIFICATESCHOOLDENIED'],
                    ['SysUsers.id' => $ent_validate->user_id, 'SysUsers.deleted' => 0, 'steps' => 'WAITINGSCHOOLAPPROVAL']
                );
                $Main->notify_devices('REJECT_APPLICATION_OS',array($ent_validate->user_id),true,true,true,array(),'',array(),true);
            }

            // $this->SysUsers->updateAll(
            //     //['steps' => 'DENIED'],
            //     ['steps' => 'CERTIFICATESCHOOLDENIED'],
            //     // ['steps' => 'WAITINGSCHOOLAPPROVAL'],
            //     ['SysUsers.id' => $ent_validate->user_id, 'SysUsers.deleted' => 0]
            // );
            
            $validation = '
                <div class="error alert box-succes">
                    <div class="alert-body">
                        Has rejected the applicant
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

    public function send_email_school(){
        $message = get('message', '');
        $user_id = get('user_id', 0);

        $Main = new MainController();
        $Main->notify_devices('APPROVED_SCHOOL',array($user_id),false,true);
    }

    public function skip_advanced_course(){                        
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

        $user = $this->SysUsers->find('all', [
            'conditions' => [
                'SysUsers.id' => USER_ID
            ]
        ])->first();

        if($user->steps == 'MATERIALS'){
            $this->SysUsers->updateAll([
                'steps' => 'MATERIALS'
            ], [
                'SysUsers.id' => USER_ID
            ]);
            $this->message('You have successfully skipped the advanced course.');
        } else {
            $this->message('You have already skipped the advanced course.');                
        }
        $this->success();
    }

    public function verify_assistance_code(){
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

        $this->loadModel('SpaLiveV1.EntTraining');

        $code      = get('code', '');
        $id_course = get('id_course', 0);

        if (empty($id_course)) {
            $this->message('Please enter a valid course.');
            return;
        }

        if(empty($code)){
            $this->message('Please enter a valid code.');
            return;
        }           

        $this->loadModel('SpaLiveV1.CatTrainings');
        $ent_trainings = $this->CatTrainings
            ->find()
            ->where([
                'CatTrainings.id' => $id_course, 
                'CatTrainings.verify_code' => $code
            ])
            ->first();
        
        if(empty($ent_trainings)){
            $this->message('Wrong code.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataTrainings');
        
        if($ent_trainings->level == 'LEVEL 3 FILLERS'){
        $ent_data_trainings = $this->DataTrainings
        ->find()
        ->where([
                'DataTrainings.user_id' => USER_ID, 
                'DataTrainings.training_id' => $id_course
            ])
        ->first();
            $this->email_level_3_approved($ent_data_trainings->id);
        }

        if($ent_trainings->level == 'LEVEL 1-1 NEUROTOXINS'){
            $ent_data_trainings = $this->DataTrainings
            ->find()
            ->where([
                    'DataTrainings.user_id' => USER_ID, 
                    'DataTrainings.training_id' => $id_course
                ])
            ->first();
        
                $this->email_level_1_to_1($ent_data_trainings->id);
            }

        if($ent_trainings->level == 'LEVEL 3 MEDICAL'){
            $Main = new MainController();
            $Main->notify_devices('APPROVED_TOXTUNEUP_COURSE',array(USER_ID),true,true, true, array(), '');
        }
        // THE CODE IS VALID

        
        $this->DataTrainings->updateAll([
            'attended' => 1
        ], [
            'user_id' => USER_ID,
            'training_id' => $id_course
        ]);

        $this->SysUsers->updateAll([
            'steps' => 'SUBSCRIPTIONPENDING'
        ], [
            'SysUsers.id' => USER_ID
        ]);

        $id     = get('id', 0);
        $img    = get('img', false);

        if (!empty($id) && $id > 0 && !empty($img)) {
            if($ent_trainings->level != 'LEVEL 1-1 NEUROTOXINS'){
                $this->send_certificate();
            }
        }

        $Main = new MainController();
        $message = $this->email_after_validate_course();
        $Main->notify_devices($message,array(USER_ID),false,true,false,array(),'');

        if(!env('IS_DEV', false)){
            $Ghl = new GhlController();
            $array_data = array(
                'email' => USER_EMAIL,
                'name' => USER_NAME,
                'lname' => USER_LNAME,
                'phone' => USER_PHONE,
                'costo' => $this->register_total / 100,
                'course' => 'Level 1 Grads'
            );
            $Ghl->updateOpportunity($array_data);
        }

        $this->success();
    }

    private function email_level_3_approved($id){

        if (empty($id)) {
            $this->message('Invalid training.');
            return;
        }

        $user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();

        $this->loadModel('SpaLiveV1.CatTrainigs');

        $certItem = $this->CatTrainigs->find()->select(['User.name','User.mname','User.lname','CatTrainigs.scheduled','CatTrainigs.title','DataTrainigs.id','CatTrainigs.level'])
        ->join([
            'DataTrainigs' => ['table' => 'data_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainigs.training_id'],
            'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataTrainigs.user_id']
        ])->where(['DataTrainigs.user_id' => USER_ID, 'DataTrainigs.id' => $id])->first();

        if(empty($certItem)){
            $this->message('Training not found.');
            return;
        }

        $name = trim($user['name'].' '.((isset($user['mname']) && !empty($user['mname']) ?$user['mname'] : '' )).' '.$user['lname']);
        $ftmpname = $certItem['DataTrainigs']['id'];

        $dates = $certItem['scheduled']->i18nFormat('MM/dd/yyyy');

        $type_space = imagettfbbox(250, 0, './font/AlexBrush-Regular.ttf', $name);
        $text_width = abs($type_space[4] - $type_space[0]) + 10;
        
        $im   = imagecreatefrompng(TMP . "files/training_certificate_tmp.png");
        $gray = imagecolorallocate($im, 30, 30, 30);
        $px   = (imagesx($im) - $text_width) / 2;
        imagettftext($im, 250, 0, (int)$px, 1750, $gray, './font/AlexBrush-Regular.ttf', $name);

        $cert_title = 'has successfully completed '.$certItem['title'].' on '.$dates;
        if (strlen($cert_title) > 70) {
            $tmp_title = wordwrap($cert_title, 70, "\n");
            $arr_strings = $arr_tttrr = explode("\n",$tmp_title);
            $cert_title = $arr_tttrr[0];
            $cert_title2 = $arr_tttrr[1];
            
            $type_spaceTr = imagettfbbox(70, 0, './font/Garet-Book.ttf', $cert_title);
            $text_widthTr = abs($type_spaceTr[4] - $type_spaceTr[0]) + 10;
            $pxTr = (imagesx($im) - $text_widthTr) / 2;
            imagettftext($im, 70, 0, (int)$pxTr, 1950, $gray, './font/Garet-Book.ttf', $cert_title);

            $type_spaceTr2 = imagettfbbox(70, 0, './font/Garet-Book.ttf', $cert_title2);
            $text_widthTr2 = abs($type_spaceTr2[4] - $type_spaceTr2[0]) + 10;
            $pxTr = (imagesx($im) - $text_widthTr2) / 2;
            imagettftext($im, 70, 0, (int)$pxTr, 2100, $gray, './font/Garet-Book.ttf', $cert_title2);
        } else {
            $type_spaceTr = imagettfbbox(70, 0, './font/Garet-Book.ttf', $cert_title);
            $text_widthTr = abs($type_spaceTr[4] - $type_spaceTr[0]) + 0;
            $pxTr = (imagesx($im) - $text_widthTr) / 2;
            imagettftext($im, 70, 0, (int)$pxTr, 2050, $gray, './font/Garet-Book.ttf', $cert_title);
        }

        //imagettftext($im, 28, 0, 1430, 965, $gray, './font/Garet-Book.ttf', $dates);
        imagepng($im, TMP . "files/tmp_Train_Cert".$ftmpname.".png");
        imagedestroy($im);

        $filename = TMP . "files/tmp_Train_Cert".$ftmpname.".png";

        if(empty($filename)){
            $this->message('Error generating certificate.');
            return;
        } else {
            $type = 'Certificate';
            $subject = 'Congratulations on Completing Your Course - Next Steps';
            $body = '
                <p>Dear ' . USER_NAME . ' ' . USER_LNAME . ', CP</p>
                
                <p>Congratulations on Successfully Completing Your Dermal Fillers Course!</p>
                <p>We are thrilled to congratulate you on successfully completing your Dermal Fillers course with MySpaLive! Your dedication and hard work have paid off, and you are now equipped with valuable skills to enhance your career in aesthetic medicine.</p>

                <p>Here are your next steps to fully integrate into the world of aesthetic professionals:</p>
                <ul>
                    <li><strong>Certification Upload:</strong> Please go to the "Certifications" section within the MySpaLive app. Here, you can upload the certificate you received upon completion of the course. This step is crucial as it verifies your training and allows you to showcase your credentials within our network.</li>
                    <li><strong>Update Your Profile:</strong> Take a moment to update your professional profile on the app. A comprehensive profile helps potential clients understand your expertise, services offered, and availability. Highlight your new skills and any specific areas of interest in aesthetic medicine.</li>
                    <li><strong>Explore Advanced Opportunities:</strong> Now that you have foundational knowledge and skills, consider exploring advanced courses and certifications. MySpaLive and Ageless Aesthetics Academy offer a range of specialized training programs designed to further enhance your expertise.</li>
                    <li><strong>Network with Peers:</strong> Join our community forums and groups within the app. Networking with fellow professionals is a great way to share experiences, learn new techniques, and stay updated on the latest trends in aesthetic medicine.</li>
                    <li><strong>Start Practicing:</strong> With your certification uploaded and profile updated, you´re all set to begin offering your services. Use the MySpaLive app to manage appointments, connect with clients, and grow your practice.</li>
                </ul>
                <p>We are incredibly proud of your achievement and are excited to see the great things you will accomplish in the field of aesthetic medicine. Remember, our support team is always here to assist you as you take these next steps in your professional journey.</p>
                <p>Thank you for choosing MySpaLive and Ageless Aesthetics Academy for your training. We look forward to your continued success and growth in the industry.</p>
                <p><strong>Best,</strong></p>
                <p>The MySpaLive Team</p>
            ';
    
            //Arreglo que almacena la información del correo.
            $data = array(
                'from'      => 'MySpaLive <noreply@mg.myspalive.com>',
                'to'      => $user->email,
                'subject' => $subject,
                'html'    => $body,
                'attachment[1]' => curl_file_create($filename, 'image/png', 'MySpaLive' . $type . '.png'),
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
            if (curl_errno($curl)) {
                // this would be your first hint that something went wrong                            
            } else {
                // check the HTTP status code of the request
                $resultStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                if ($resultStatus == 200) {
                    //unlink($filename);// everything went better than expected
                } else {
                    // the request did not complete as expected. common errors are 4xx
                    // (not found, bad request, etc.) and 5xx (usually concerning
                    // errors/exceptions in the remote script execution)                                
                }
            }                            
            curl_close($curl);
            return;
        }
    }

    private function email_level_1_to_1($id){

        if (empty($id)) {
            $this->message('Invalid training.');
            return;
        }

        $user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();

        $this->loadModel('SpaLiveV1.CatTrainigs');

        $certItem = $this->CatTrainigs->find()->select(['User.name','User.mname','User.lname','CatTrainigs.scheduled','CatTrainigs.title','DataTrainigs.id','CatTrainigs.level'])
        ->join([
            'DataTrainigs' => ['table' => 'data_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainigs.training_id'],
            'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataTrainigs.user_id']
        ])->where(['DataTrainigs.user_id' => USER_ID, 'DataTrainigs.id' => $id])->first();

        if(empty($certItem)){
            $this->message('Training not found.');
            return;
        }

        $name = trim($user['name'].' '.((isset($user['mname']) && !empty($user['mname']) ?$user['mname'] : '' )).' '.$user['lname']);
        $ftmpname = $certItem['DataTrainigs']['id'];

        $dates = $certItem['scheduled']->i18nFormat('MM/dd/yyyy');

        $type_space = imagettfbbox(250, 0, './font/AlexBrush-Regular.ttf', $name);
        $text_width = abs($type_space[4] - $type_space[0]) + 10;
        
        $im   = imagecreatefrompng(TMP . "files/training_certificate_tmp.png");
        $gray = imagecolorallocate($im, 30, 30, 30);
        $px   = (imagesx($im) - $text_width) / 2;
        imagettftext($im, 250, 0, (int)$px, 1750, $gray, './font/AlexBrush-Regular.ttf', $name);

        $cert_title = 'has successfully completed '.$certItem['title'].' on '.$dates;
        if (strlen($cert_title) > 70) {
            $tmp_title = wordwrap($cert_title, 70, "\n");
            $arr_strings = $arr_tttrr = explode("\n",$tmp_title);
            $cert_title = $arr_tttrr[0];
            $cert_title2 = $arr_tttrr[1];
            
            $type_spaceTr = imagettfbbox(70, 0, './font/Garet-Book.ttf', $cert_title);
            $text_widthTr = abs($type_spaceTr[4] - $type_spaceTr[0]) + 10;
            $pxTr = (imagesx($im) - $text_widthTr) / 2;
            imagettftext($im, 70, 0, (int)$pxTr, 1950, $gray, './font/Garet-Book.ttf', $cert_title);

            $type_spaceTr2 = imagettfbbox(70, 0, './font/Garet-Book.ttf', $cert_title2);
            $text_widthTr2 = abs($type_spaceTr2[4] - $type_spaceTr2[0]) + 10;
            $pxTr = (imagesx($im) - $text_widthTr2) / 2;
            imagettftext($im, 70, 0, (int)$pxTr, 2100, $gray, './font/Garet-Book.ttf', $cert_title2);
        } else {
            $type_spaceTr = imagettfbbox(70, 0, './font/Garet-Book.ttf', $cert_title);
            $text_widthTr = abs($type_spaceTr[4] - $type_spaceTr[0]) + 0;
            $pxTr = (imagesx($im) - $text_widthTr) / 2;
            imagettftext($im, 70, 0, (int)$pxTr, 2050, $gray, './font/Garet-Book.ttf', $cert_title);
        }

        //imagettftext($im, 28, 0, 1430, 965, $gray, './font/Garet-Book.ttf', $dates);
        imagepng($im, TMP . "files/tmp_Train_Cert".$ftmpname.".png");
        imagedestroy($im);

        $filename = TMP . "files/tmp_Train_Cert".$ftmpname.".png";

        if(empty($filename)){
            $this->message('Error generating certificate.');
            return;
        } else {
            $type = 'Certificate';
            $subject = 'Congratulations on Completing Your ToxTune-Up Session!';
            $body = '
                <p>Dear Injector, ' . USER_NAME . ' ' . USER_LNAME . '</p></br>
                <p>Bravo! You\'ve successfully completed the ToxTune-Up Session. On behalf of the entire MySpaLive team, we want to extend our heartfelt congratulations to you. It\'s an impressive milestone, and we\'re honored to have played a part in your journey towards mastering advanced facial injection techniques.</p></br>
                <p>We hope the session provided you with valuable insights and skills that you can immediately apply in your practice, setting you apart in the aesthetic field.</p></br>
                <p>As you continue on your path to excellence in aesthetics, remember that MySpaLive is here to support you with further education, resources, and community connections.</p>                                </br>
                <p>Once again, congratulations on this achievement! We can\'t wait to see where your new skills will take you.</p>
            ';
    
            //Arreglo que almacena la información del correo.
            $data = array(
                'from'      => 'MySpaLive <noreply@mg.myspalive.com>',
                'to'      => $user->email,
                'subject' => $subject,
                'html'    => $body,
                'attachment[1]' => curl_file_create($filename, 'image/png', 'MySpaLive' . $type . '.png'),
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
            if (curl_errno($curl)) {
                // this would be your first hint that something went wrong                            
            } else {
                // check the HTTP status code of the request
                $resultStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                if ($resultStatus == 200) {
                    //unlink($filename);// everything went better than expected
                } else {
                    // the request did not complete as expected. common errors are 4xx
                    // (not found, bad request, etc.) and 5xx (usually concerning
                    // errors/exceptions in the remote script execution)                                
                }
            }                            
            curl_close($curl);
            return;
        }
    }

    public function test_courses(){
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

        $this->set('courses', $this->get_courses_user(USER_ID));    
    }

    /* 
        METHODS NOT ENDPOINTS 

    */    

    public function get_courses_user($user_id){
        $this->loadModel('SpaLiveV1.DataTrainings');
        $this->loadModel('SpaLiveV1.CatTrainings');

        $fields = [
            'CatTrainings.id',
            'CatTrainings.title',
            'CatTrainings.scheduled',
            'CatTrainings.address',
            'CatTrainings.city',
            'CatTrainings.state_id',
            'CatTrainings.zip',
            'CatTrainings.level',    
            'CatTrainings.created',
            'data_training' => 'DataTrainings.id', 
            'attended' => 'DataTrainings.attended'
        ];
        $courses = $this->CatTrainings->find('all', [
            'conditions' => [
                'DataTrainings.user_id' => $user_id
            ],            
        ])
        ->select($fields)
        ->join([
            'table' => 'data_trainings',
            'alias' => 'DataTrainings',
            'type' => 'INNER',
            'conditions' => 'CatTrainings.id = DataTrainings.training_id'
        ])
        ->where([
            'DataTrainings.deleted' => 0,
            'CatTrainings.deleted' => 0,            
        ])
        ->toArray();

        
        $user_courses = array();
        $level_type = array_unique(array_column($courses, 'level'));
        
        $now = date('Y-m-d H:i:s');
        foreach ($level_type as $level) {
            $filteredCourses = array_filter($courses, function($course) use ($level) {
                return $course['level'] == $level;
            });                        
            foreach ($filteredCourses as $course) {
                $c_date = date('Y-m-d',strtotime('2023-02-27'));
                $isBeforeChange = $c_date > $course->created->i18nFormat('yyyy-MM-dd');
                if($isBeforeChange){
                    $course['status'] = $course['scheduled']->i18nFormat('yyyy-MM-dd HH:mm:ss') < $now ? 'DONE' : 'PENDING';
                }else {
                    $course['status'] = $course['attended'] == 1 ? 'DONE' : 'PENDING';
                }
            }
            $user_courses[$level] = $filteredCourses;
        }

        $has_basic_course = false;
        $level = 'LEVEL 1'; 
        if(array_key_exists($level, $user_courses)){
            $status = 'DONE';
            $has_basic_course = count(array_filter($user_courses[$level], function($course) use ($status) {
                return $course['status'] == $status;
            })) > 0;
        }

        $has_advanced_course = false;
        $level = 'LEVEL 2'; 
        if(array_key_exists($level, $user_courses)){
            $status = 'DONE';
            $has_advanced_course = count(array_filter($user_courses[$level], function($course) use ($status) {
                return $course['status'] == $status;
            })) > 0;
        }

        $has_level3_course = false;
        $level = 'LEVEL 3 MEDICAL'; 
        if(array_key_exists($level, $user_courses)){
            $status = 'DONE';
            $has_level3_course = count(array_filter($user_courses[$level], function($course) use ($status) {
                return $course['status'] == $status;
            })) > 0;
        }

        $courses_profile = array();
        foreach ($courses as $course) {
            $c_date = date('Y-m-d',strtotime('2023-02-27'));
            $isBeforeChange = $c_date > $course->created->i18nFormat('yyyy-MM-dd');
            if($isBeforeChange){
                $course['status'] = $course['scheduled']->i18nFormat('yyyy-MM-dd HH:mm:ss') < $now ? 'DONE' : 'PENDING';
            }else {
                $course['status'] = $course['attended'] == 1 ? 'DONE' : 'PENDING';
            }
            if($course['status'] == 'DONE'){
                $courses_profile[] = $course;
            }
        }

        $this->loadModel("SpaLiveV1.DataCourses");
        $this->loadModel("SpaLiveV1.CatCourses");
        if(!$has_basic_course){
            $user_course_basic = $this->DataCourses->find()->select(['CatCourses.type'])->join([
                'CatCourses' => ['table' => 'cat_courses', 'type' => 'INNER', 'conditions' => 'CatCourses.id = DataCourses.course_id'],
                ])->where(['CatCourses.type IN' => array('NEUROTOXINS BASIC', 'BOTH NEUROTOXINS'),'DataCourses.user_id' => $user_id,'DataCourses.deleted' => 0,'DataCourses.status' => 'DONE'])->first();
            
            if(!empty($user_course_basic)){
                $has_basic_course = true;
            }else{
                $user_course_basic = $this->DataTrainings->find()
                ->join([
                    'CatTrainings' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainings.id = DataTrainings.training_id'],
                    'CTC' => ['table' => 'cat_courses_type', 'type' => 'INNER', 'conditions' => 'CTC.name_key = CatTrainings.level'],
                    'DCC' => ['table' => 'data_coverage_courses', 'type' => 'INNER', 'conditions' => 'DCC.course_type_id = CTC.id'],
                    'STOT' => ['table' => 'sys_treatments_ot', 'type' => 'INNER', 'conditions' => 'STOT.id = DCC.ot_id'],
                ])
                ->where(['DataTrainings.user_id' => $user_id,'DataTrainings.deleted' => 0,'DataTrainings.attended' => 1,'CatTrainings.deleted' => 0,'STOT.name_key' => 'NEUROTOXINS'])
                ->first();

                if(!empty($user_course_basic)){
                    $has_basic_course = true;
                }
            }
        }

        if(!$has_advanced_course){
            $user_course_school_advanced = $this->DataCourses->find()->select(['CatCourses.type'])->join([
                'CatCourses' => ['table' => 'cat_courses', 'type' => 'INNER', 'conditions' => 'CatCourses.id = DataCourses.course_id'],
                ])->where(['CatCourses.type IN' => array('NEUROTOXINS ADVANCED', 'BOTH NEUROTOXINS'),'DataCourses.user_id' => $user_id,'DataCourses.deleted' => 0,'DataCourses.status' => 'DONE'])->first();

            if(!empty($user_course_school_advanced)){
                $has_advanced_course = true;
            }
        }

        if(!$has_advanced_course){
           
            $user_course = $this->DataTrainings->find()
            ->join([
                'CatTrainings' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainings.id = DataTrainings.training_id'],
                'CTC' => ['table' => 'cat_courses_type', 'type' => 'INNER', 'conditions' => 'CTC.name_key = CatTrainings.level'],
                'DCC' => ['table' => 'data_coverage_courses', 'type' => 'INNER', 'conditions' => 'DCC.course_type_id = CTC.id'],
                'STOT' => ['table' => 'sys_treatments_ot', 'type' => 'INNER', 'conditions' => 'STOT.id = DCC.ot_id'],
            ])
            ->where(['DataTrainings.user_id' => $user_id,'DataTrainings.deleted' => 0,'DataTrainings.attended' => 1,'CatTrainings.deleted' => 0,'STOT.name_key' => 'ADVANCED_NEUROTOXINS'])
            ->first();

            if(!empty($user_course)){
                $has_advanced_course = true;
            }
        }

        $data = array(
            'has_basic_course'    =>  $has_basic_course,
            'has_advanced_course' =>  $has_advanced_course,
            'has_level3_course'   =>  $has_level3_course,
            'courses'             =>  $user_courses,
            'courses_profile'     =>  $courses_profile
        );

        return $data;
    }

    public function get_courses_user_for_profile($user_id){
        // Copia de get_courses_user pero solo para el ferfil del injector, 
        // No se debe mostrar los entrenamientos del nivel 3, pero solo en el perfil del injector
        $this->loadModel('SpaLiveV1.DataTrainings');
        $this->loadModel('SpaLiveV1.CatTrainings');

        $fields = [
            'CatTrainings.id',
            'CatTrainings.title',
            'CatTrainings.scheduled',
            'CatTrainings.address',
            'CatTrainings.city',
            'CatTrainings.state_id',
            'CatTrainings.zip',
            'CatTrainings.level',    
            'CatTrainings.created',
            'data_training' => 'DataTrainings.id', 
            'attended' => 'DataTrainings.attended'
        ];
        $courses = $this->CatTrainings->find('all', [
            'conditions' => [
                'DataTrainings.user_id' => $user_id
            ],            
        ])
        ->select($fields)
        ->join([
            'table' => 'data_trainings',
            'alias' => 'DataTrainings',
            'type' => 'INNER',
            'conditions' => 'CatTrainings.id = DataTrainings.training_id'
        ])
        ->where([
            'DataTrainings.deleted' => 0,
            'CatTrainings.deleted' => 0,
            'CatTrainings.level IN' => array('LEVEL 1', 'LEVEL 2')
        ])
        ->toArray();

        
        $user_courses = array();
        $level_type = array_unique(array_column($courses, 'level'));
        
        $now = date('Y-m-d H:i:s');
        foreach ($level_type as $level) {
            $filteredCourses = array_filter($courses, function($course) use ($level) {
                return $course['level'] == $level;
            });                        
            foreach ($filteredCourses as $course) {
                $c_date = date('Y-m-d',strtotime('2023-02-27'));
                $isBeforeChange = $c_date > $course->created->i18nFormat('yyyy-MM-dd');
                if($isBeforeChange){
                    $course['status'] = $course['scheduled']->i18nFormat('yyyy-MM-dd HH:mm:ss') < $now ? 'DONE' : 'PENDING';
                }else {
                    $course['status'] = $course['attended'] == 1 ? 'DONE' : 'PENDING';
                }
            }
            $user_courses[$level] = $filteredCourses;
        }

        $has_basic_course = false;
        $level = 'LEVEL 1'; 
        if(array_key_exists($level, $user_courses)){
            $status = 'DONE';
            $has_basic_course = count(array_filter($user_courses[$level], function($course) use ($status) {
                return $course['status'] == $status;
            })) > 0;
        }

        $has_advanced_course = false;
        $level = 'LEVEL 2'; 
        if(array_key_exists($level, $user_courses)){
            $status = 'DONE';
            $has_advanced_course = count(array_filter($user_courses[$level], function($course) use ($status) {
                return $course['status'] == $status;
            })) > 0;
        }

        $courses_profile = array();
        foreach ($courses as $course) {
            $c_date = date('Y-m-d',strtotime('2023-02-27'));
            $isBeforeChange = $c_date > $course->created->i18nFormat('yyyy-MM-dd');
            if($isBeforeChange){
                $course['status'] = $course['scheduled']->i18nFormat('yyyy-MM-dd HH:mm:ss') < $now ? 'DONE' : 'PENDING';
            }else {
                $course['status'] = $course['attended'] == 1 ? 'DONE' : 'PENDING';
            }
            if($course['status'] == 'DONE'){
                $courses_profile[] = $course;
            }
        }

        $this->loadModel("SpaLiveV1.DataCourses");
        $this->loadModel("SpaLiveV1.CatCourses");
        if(!$has_basic_course){
            $user_course_basic = $this->DataCourses->find()->select(['CatCourses.type'])->join([
                'CatCourses' => ['table' => 'cat_courses', 'type' => 'INNER', 'conditions' => 'CatCourses.id = DataCourses.course_id'],
                ])->where(['CatCourses.type IN' => array('NEUROTOXINS BASIC', 'BOTH NEUROTOXINS'),'DataCourses.user_id' => $user_id,'DataCourses.deleted' => 0,'DataCourses.status' => 'DONE'])->first();
            
            if(!empty($user_course_basic)){
                $has_basic_course = true;
            }
        }

        if(!$has_advanced_course){
            $user_course_school_advanced = $this->DataCourses->find()->select(['CatCourses.type'])->join([
                'CatCourses' => ['table' => 'cat_courses', 'type' => 'INNER', 'conditions' => 'CatCourses.id = DataCourses.course_id'],
                ])->where(['CatCourses.type IN' => array('NEUROTOXINS ADVANCED', 'BOTH NEUROTOXINS'),'DataCourses.user_id' => $user_id,'DataCourses.deleted' => 0,'DataCourses.status' => 'DONE'])->first();

            if(!empty($user_course_school_advanced)){
                $has_advanced_course = true;
            }
        }

        $data = array(
            'has_basic_course'    =>  $has_basic_course,
            'has_advanced_course' =>  $has_advanced_course,
            'courses'             =>  $user_courses,
            'courses_profile'     =>  $courses_profile
        );

        return $data;
    }

    public function get_schools_certifications($user_id){
        $this->loadModel('SpaLiveV1.DataCourses');

        /*$_join = [
            'CatCourses' => ['table' => 'cat_courses', 'type' => 'INNER', 'conditions' => 'CatCourses.id = DataCourses.course_id'],
            'School' => ['table' => 'data_school_register', 'type' => 'INNER', 'conditions' => 'School.id = CatCourses.school_id'],
            'Licence' => ['table' => 'sys_licences','type' => 'INNER','conditions' => 'Licence.user_id = DataCourses.user_id'],
        ];

        $ent_courses = $this->DataCourses->find()->select(['DataCourses.course_id','DataCourses.front','DataCourses.back', 'CatCourses.type'])
        ->join($_join)
        ->where(
            ['DataCourses.user_id' => $user_id, 'DataCourses.user_id' => $user_id, 'DataCourses.status' => "DONE", 'DataCourses.deleted' => 0,
             'School.status' => "Active", 'School.deleted' => 0, 'CatCourses.deleted' => 0, 'Licence.status' => "APPROVED"])
        ->all();

        $arr_courses = array();
        foreach ($ent_courses as $course) {
            $arr_courses[] = array(
                'course_id' => $course->course_id,
                'front' => $course->front,
                'back' => $course->back,
                'type' => $course['CatCourses']['type']
            );
        }
            
        */
        //school certificate                                  
            $this->loadModel('SpaLiveV1.DataCourses');
    
            $fields = ['DataCourses.id', 'DataCourses.status', 'DataCourses.front', 'DataCourses.back', 'CC.id','CC.type', 'DSR.nameschool','User.uid'];
            $courses = $this->DataCourses->find()->select($fields)
            ->join([
                'CC' => ['table' => 'cat_courses', 'type' => 'INNER', 'conditions' => 'CC.id = DataCourses.course_id'],
                'DSR' => ['table' => 'data_school_register', 'type' => 'INNER', 'conditions' => 'DSR.id = CC.school_id'],
                'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataCourses.user_id']
            ])->where(['DataCourses.deleted' => 0, 'DataCourses.user_id' => $user_id, 'CC.deleted' => 0, 'DSR.deleted' => 0])->all();
            $arr_courses = array();
            foreach ($courses as $item) {                
                if($item->status == 'DONE'){                    
                    $arr_courses[] = [
                        'type' => $item['CC']['type'],
                        'front' => (int) $item['CC']['id'],
                        'back' => $item->back == 0 ? Null : $item->back,                        
                        'course_id' => (int) $item['CC']['id'],
                        'uid' => $item['User']['uid'],
                    ];
                }                             
            }
        return $arr_courses;
        //
    }

    public function get_other_treatments_certifications($user_id){
        // Other treatments certificates
        $this->loadModel('SpaLiveV1.CatCoursesType');
        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.CatTrainings');

        $trainings_ot = $this->CatCoursesType->find()
        ->where(['CatCoursesType.available' => 1, 'CatCoursesType.deleted' => 0])
        ->all();

        // Other treatments certificates array contains the types of treatments that the user has completed and each type has an array of trainings that the user has completed.
        $other_treatments_sections = array();

        foreach($trainings_ot as $training_ot) {
            $p = $this->DataPayment->find()
                ->where(['DataPayment.id_from' => $user_id,'DataPayment.type' => $training_ot->title, 'DataPayment.payment <>' => '', 'DataPayment.is_visible' => 1])
            ->first();

            if(!empty($p)){
                $_fields = ['CatTrainings.id', 'CatTrainings.title', 'CatTrainings.scheduled', 'CatTrainings.address', 'CatTrainings.city', 'CatTrainings.state_id', 'CatTrainings.zip', 'CatTrainings.level', 'CatTrainings.created', 'data_training' => 'DataTrainings.id', 'attended' => 'DataTrainings.attended'];
                $_join = [
                        'table' => 'data_trainings',
                        'alias' => 'DataTrainings',
                        'type' => 'INNER',
                        'conditions' => 'CatTrainings.id = DataTrainings.training_id'
                    ];
                
                $_where = ['DataTrainings.user_id' => $user_id, 'DataTrainings.deleted' => 0, 'CatTrainings.deleted' => 0, 'CatTrainings.level' => $training_ot->title];

                $done_trainings = $this->CatTrainings->find()->select($_fields)
                ->join($_join)
                ->where($_where)->order(['CatTrainings.scheduled' => 'ASC'])->toArray();

                $completed_trainings = array();
                foreach ($done_trainings as $row) {
                    $c_date = date('Y-m-d',strtotime('2023-02-27'));
                    $isBeforeChange = $c_date > $row->created->i18nFormat('yyyy-MM-dd');
                    if($isBeforeChange){
                        $row['status'] = $row['scheduled']->i18nFormat('yyyy-MM-dd HH:mm:ss') < $now ? 'DONE' : 'PENDING';
                    }else {
                        $row['status'] = $row['attended'] == 1 ? 'DONE' : 'PENDING';
                    }
                    
                    if($row['status'] == 'DONE'){
                        $completed_trainings[] = $row;
                    }
                }

                if(!empty($completed_trainings)){
                    $other_treatments_sections[$training_ot->title] = $completed_trainings;
                }
            }
        }

        return $other_treatments_sections;
    }

    public function send_certificate(){
        $this->loadModel('SpaLiveV1.SysUsers');

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

        $id = get('id', 0);
        if (empty($id)) {
            $this->message('Invalid training.');
            return;
        }

        $img = get('img', false);
        if (empty($img)) {
            $this->message('Img key is required.');
            return;
        }

        $user = $this->SysUsers->find()->select(['SysUsers.email'])->where(['SysUsers.id' => USER_ID])->first();

        $Main = new MainController();

        $filename = $Main->get_training_cert(true);

        if(empty($filename)){
            $this->message('Error generating certificate.');
            return;
        } else {
            $type = 'Certificate';
            $subject = 'Training certificate';
            $body = "Congratulations on your training completion. Your certificate is attached.";
    
            //Arreglo que almacena la información del correo.
            $data = array(
                'from'      => 'MySpaLive <noreply@mg.myspalive.com>',
                'to'      => $user->email,
                'subject' => $subject,
                'html'    => $body,
                'attachment[1]' => curl_file_create($filename, 'image/png', 'MySpaLive' . $type . '.png'),
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
            if (curl_errno($curl)) {
                // this would be your first hint that something went wrong                            
            } else {
                // check the HTTP status code of the request
                $resultStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                if ($resultStatus == 200) {
                    //unlink($filename);// everything went better than expected
                } else {
                    // the request did not complete as expected. common errors are 4xx
                    // (not found, bad request, etc.) and 5xx (usually concerning
                    // errors/exceptions in the remote script execution)                                
                }
            }                            
            curl_close($curl);
    
            
            $this->message('Certificate sent successfully.');
            $this->success();
        }
    }

    public function get_training_pm() {
        $this->loadModel('SpaLiveV1.DataModelPatient');
        $this->loadModel('SpaLiveV1.CatTrainings');
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
        //USER_EMAIL
        //$training_id = get('training_id', ''); SELECT `id`,`requested_training_id`,`registered_training_id` FROM `data_model_patient` WHERE `deleted`=0 and `uid` ="UID"
        // SELECT * FROM `cat_trainings` WHERE `deleted`=0 and FIND_IN_SET(id,"46,94");

        $_fields = ['requested_training_id','registered_training_id','status'];                
        $_where = ['DataModelPatient.email' => USER_EMAIL, 
                   'DataModelPatient.deleted' => 0,                                      
                   ];        
        $training = $this->DataModelPatient->find()
                                      ->select($_fields)                                      
                                      ->where($_where);
        $list = [];
        $listRequest = [];
        $listRegister = [];
        $listCancel = [];
        $str_list=""; 
        foreach($training as $t){
            if(isset($t['requested_training_id'])){
                $listRequest[] = $t['requested_training_id'];
                $list[] = $t['requested_training_id'];
            }
            if(isset($t['registered_training_id'])){
                $listRegister[] = $t['registered_training_id'];
                $list[] = $t['registered_training_id'];
                if(isset($t['registered_training_id']) && $t['status']=='cancel'){
                    $listCancel[] = $t['registered_training_id'];                
                }
            }
            
            
        }
        if(count($list)>1){
            $str_list = implode(",", $list);
        }else{
            if(count($list)>0)
                $str_list = $list[0];
            else{
                $this->set('message', "No data found");
                return;
            }        
        }
        //$this->set('str_list', $str_list);
        //$this->set('list', $list);
        //$this->set('data', $training); return;
        $this->set('register', null); 
        $this->set('request', null);
        $this->set('cancel', null);
        if(count($listRequest)>0){
            
            $_fields = ['CatTrainings.id','CatTrainings.title','CatTrainings.scheduled','CatTrainings.address','CatTrainings.city','CatTrainings.state_id','CatTrainings.zip','CatTrainings.level','CatTrainings.created', 'status'=>'DataModelPatient.status', 'id' => 'CatTrainings.id'];            
            $_join = ['table' => 'data_model_patient', 'alias' => 'DataModelPatient','type' => 'left', 'conditions' => 'DataModelPatient.registered_training_id = CatTrainings.id and DataModelPatient.email = "'.USER_EMAIL.'"' ];
            $_where = ['CatTrainings.id IN' => $listRequest, 
                       'CatTrainings.deleted' => 0,                                      
                   ];        
            $arr_Request = $this->CatTrainings->find()
                                      ->select($_fields)                                      
                                      ->join($_join)
                                      ->where($_where);

            $this->set('request', $arr_Request); 
        }$now = date('y-m-d h:m:s');
        if(count($listRegister)>0){ 
            //'data_training_id' =>
            $_fields = ['CatTrainings.id','CatTrainings.title','CatTrainings.scheduled','CatTrainings.address','CatTrainings.city','CatTrainings.state_id','CatTrainings.zip','CatTrainings.level','CatTrainings.created','assistance'=>'DataModelPatient.assistance', 'status'=>'DataModelPatient.status', 'id' => 'CatTrainings.id'];
            $_fields['show_assistance_code'] = "(SELECT  ( CASE WHEN scheduled <= '".$now."' THEN 'TRUE' ELSE 'FALSE' END) AS total FROM `cat_trainings` ct WHERE ct.id = CatTrainings.id)";
            $_join = ['table' => 'data_model_patient', 'alias' => 'DataModelPatient','type' => 'left', 'conditions' => 'DataModelPatient.registered_training_id = CatTrainings.id and DataModelPatient.email = "'.USER_EMAIL.'"' ];
            $_where = ['CatTrainings.id IN' => $listRegister, 
                       'CatTrainings.deleted' => 0,                                      
                   ];        
            $arr_Register = $this->CatTrainings->find()
                                      ->select($_fields)                                      
                                      ->join($_join)
                                      ->where($_where);
            $this->set('register', $arr_Register); 
        }        
        
        if(count($listCancel)>0){ 
            //'data_training_id' =>
            $_fields = ['CatTrainings.id','CatTrainings.title','CatTrainings.scheduled','CatTrainings.address','CatTrainings.city','CatTrainings.state_id','CatTrainings.zip','CatTrainings.level','CatTrainings.created','assistance'=>'DataModelPatient.assistance', 'status'=>'DataModelPatient.status','id' => 'CatTrainings.id'];
            $_fields['show_assistance_code'] = "(SELECT  ( CASE WHEN scheduled <= '".$now."' THEN 'TRUE' ELSE 'FALSE' END) AS total FROM `cat_trainings` ct WHERE ct.id = CatTrainings.id)";
            $_join = ['table' => 'data_model_patient', 'alias' => 'DataModelPatient','type' => 'left', 'conditions' => 'DataModelPatient.registered_training_id = CatTrainings.id and DataModelPatient.email = "'.USER_EMAIL.'"' ];
            $_where = ['CatTrainings.id IN' => $listCancel, 
                       'CatTrainings.deleted' => 0,                                      
                   ];        
            $arr_Register = $this->CatTrainings->find()
                                      ->select($_fields)                                      
                                      ->join($_join)
                                      ->where($_where);
            $this->set('cancel', $arr_Register); 
        }

            $this->success();
        
    }

    public function verify_assistance_code_model_patient(){
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

        $this->loadModel('SpaLiveV1.EntTraining');

        $code      = get('code', '');
        $id_course = get('id_course', 0);

        if (empty($id_course)) {
            $this->message('Please enter a valid course.');
            return;
        }

        if(empty($code)){
            $this->message('Please enter a valid code.');
            return;
        }           

        $this->loadModel('SpaLiveV1.CatTrainings');
        $ent_trainings = $this->CatTrainings
            ->find()
            ->where([
                'CatTrainings.id' => $id_course, 
                'CatTrainings.verify_code' => $code
            ])
            ->first();
        
        if(empty($ent_trainings)){
            $this->message('The code is not valid.');
            return;
        }   

        // THE CODE IS VALID

        $this->loadModel('SpaLiveV1.DataModelPatient');
        $this->DataModelPatient->updateAll([
            'assistance' => 1
        ], [
            'email' => USER_EMAIL,
            'registered_training_id' => $id_course
        ]);
        
        //Se envía el email automáticamente con su certificado.
        //if(!empty($user)){
        //    if(!empty($data_trainings))
        //        $this->send_certificate($data_trainings->id, $data_trainings->training_id, true);
        //}

        $this->success();
    }

    
    public function watch_video() {
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

        $typeVideo = get('typeVideo','');

        if($typeVideo == "Schools"){
            $this->set('video_url', Configure::read('App.wordpress_domain') .'videos/msltraining.mp4');
        }else{
            $this->set('video_url', Configure::read('App.wordpress_domain') .'videos/myspalive_patients.mp4');
        }

        $this->success();

    }

    public function video_watched(){
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

        $typeVideo = get('typeVideo','');

        $this->loadModel('SpaLiveV1.SysUsers');

        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $user["user_id"]])->first();

        if($typeVideo == "IV Therapy"){

            // $ent_user->steps = "W9";

            $this->loadModel('SpaLiveV1.DataWatchedVideos');
            $ent_video = $this->DataWatchedVideos->find()->where(['DataWatchedVideos.user_id' => $user["user_id"], 
                                                                    'DataWatchedVideos.type' => "IV Therapy",
                                                                    'DataWatchedVideos.deleted' => 0])->first();
            
            if(empty($ent_video)){
                $array = array(
                    'user_id' => $user["user_id"],
                    'type' => "IV Therapy",
                    'deleted' => 0
                );

                $entity_video = $this->DataWatchedVideos->newEntity($array);

                if(!$this->DataWatchedVideos->save($entity_video)){
                    $this->message('Error saving video log.');
                    return;
                }

            }
        } else if($typeVideo == 'Schools'){
            $ent_user->steps = "W9";
        } else{
            if($ent_user->steps != "FILLERSAPPROVED"){
                $ent_user->steps = "W9";
            }
        }

        $update = $this->SysUsers->save($ent_user);
        $this->success();
    }

    public function os_approve(){
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

        $typeVideo = get('typeVideo','');
        $recommended_school = get('recommended_by_school',false);

        $this->loadModel('SpaLiveV1.SysUsers');
        
        $this->assing_school($recommended_school);

        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $user["user_id"]])->first();

        if($typeVideo == "IV Therapy"){

            $this->loadModel('SpaLiveV1.DataWatchedVideos');
            $ent_video = $this->DataWatchedVideos->find()->where(['DataWatchedVideos.user_id' => $user["user_id"], 
                                                                    'DataWatchedVideos.type' => "IV Therapy",
                                                                    'DataWatchedVideos.deleted' => 0])->first();
            
            if(empty($ent_video)){
                $array = array(
                    'user_id' => $user["user_id"],
                    'type' => "IV Therapy",
                    'deleted' => 0
                );

                $entity_video = $this->DataWatchedVideos->newEntity($array);

                if(!$this->DataWatchedVideos->save($entity_video)){
                    $this->message('Error saving video log.');
                    return;
                }
            }

        } else if($typeVideo == 'Schools'){
            $ent_user->steps = "MSLSCHOOLSUBSCRIPTION";
        }

        $update = $this->SysUsers->save($ent_user);
        $this->success();
    }

    public function get_trainings_model_patient(){
        $this->loadModel('SpaLiveV1.CatTrainigs');
        $this->loadModel('SpaLiveV1.CatCoursesType');

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

        $now = date('Y-m-d H:i:s');

        // FIND AVAILABLE TRAININGS

        $levels = ['LEVEL 1', 'LEVEL 2', 'LEVEL 3 FILLERS'];
        $levels_dynamic = $this->CatCoursesType->find()->where(['CatCoursesType.deleted' => 0])->all();

        foreach($levels_dynamic as $level_dynamic){
            $levels[] = $level_dynamic->name_key;
        }

        $fields = [
            'CatTrainigs.id', 
            'CatTrainigs.title', 
            'CatTrainigs.scheduled', 
            'CatTrainigs.neurotoxins', 
            'CatTrainigs.fillers', 
            'CatTrainigs.materials', 
            'CatTrainigs.available_seats', 
            'CatTrainigs.level', 
            'CatTrainigs.models_per_class',
            'State.name',
            'State.abv',
            'CatTrainigs.address',
            'CatTrainigs.zip',
            'CatTrainigs.city',
            'CCT.title'
        ];
        $fields['assistants'] = "(SELECT COUNT(id) from data_trainings DT WHERE DT.training_id = CatTrainigs.id AND DT.deleted = 0)";
        $fields['enrolled'] = "(SELECT COUNT(id) from data_trainings DT WHERE DT.training_id = CatTrainigs.id AND DT.deleted = 0 )";
        $fields['assigned_models'] = "(SELECT COUNT(DT.id) from data_model_patient DT WHERE DT.registered_training_id = CatTrainigs.id AND DT.deleted = 0)";
        $fields['requested'] = "(SELECT COUNT(DT.id) from data_model_patient DT WHERE DT.email = '" . USER_EMAIL . "' AND DT.requested_training_id = CatTrainigs.id AND DT.deleted = 0)";
        $_join = [
            'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = CatTrainigs.state_id'],
            'CCT' => ['table' => 'cat_courses_type', 'type' => 'LEFT', 'conditions' => 'CCT.name_key = CatTrainigs.level']
        ];
        $_where = ['CatTrainigs.scheduled >' => $now, 'CatTrainigs.deleted' => 0, 'CatTrainigs.state_id' => USER_STATE, 'CatTrainigs.level IN' => $levels];

        $trainingsavailable  = $this->CatTrainigs->find()->select($fields)
        ->join($_join)
        ->where($_where)
        ->group(['CatTrainigs.id'])
        ->order(['CatTrainigs.scheduled' => 'ASC'])
        ->toArray();

        $tr_result_lvl1 = array();

        foreach ($trainingsavailable as $row) {
            if(($row['assigned_models'] < $row['models_per_class']) && ($row['requested'] == 0)){                
                $scheduled = '';
                $text = '';
                $address = '';
                if($row['level'] == 'LEVEL 1'){
                    // $scheduled = $row['scheduled']->i18nFormat('MM-dd-Y HH:mm a');
                    $scheduled = $row['scheduled']->i18nFormat('MM-dd-Y 04:30') . ' PM';
                    $text = 'Basics (Forehead, Crow’s Feet)';
                    $address = $row->city . ', ' . $row->State['abv'] . ' ' . $row->zip;
                }else if($row['level'] == 'LEVEL 2'){
                    // $scheduled = $row['scheduled']->i18nFormat('MM-dd-Y HH:mm a');
                    $scheduled = $row['scheduled']->i18nFormat('MM-dd-Y 03:30') . ' PM';
                    $text = 'Advanced (Chin, Lip Flip, and Brow Lift)';
                    $address = $row->city . ', ' . $row->State['abv'] . ' ' . $row->zip;
                } else if($row['level'] == 'LEVEL 3 FILLERS'){
                    // $scheduled = $row['scheduled']->i18nFormat('MM-dd-Y HH:mm a');
                    $scheduled = $row['scheduled']->i18nFormat('MM-dd-Y 04:30') . ' PM';
                    $text = 'Fillers';
                    $address = $row->city . ', ' . $row->State['abv'] . ' ' . $row->zip;
                }else{
                    $scheduled = $row['scheduled']->i18nFormat('MM-dd-Y HH:mm a');
                    $text = $row['CCT']['title'];
                    $address = $row->city . ', ' . $row->State['abv'] . ' ' . $row->zip;
                }
                $tr_result_lvl1[] = array(
                    'shceduled'=> $scheduled,
                    'text'     => $text,
                    'address'  => $address,
                    'value'    => $row->id
                );
            }
        }

        $this->set('data_trainings', $tr_result_lvl1);
        $this->success();
    }

    public function save_model_patient(){
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

        $this->loadModel('SpaLiveV1.DataModelPatient');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataConsultation');

        $_where = ['DataModelPatient.deleted' => 0, 'DataModelPatient.email LIKE' => USER_EMAIL, 'DataModelPatient.requested_training_id <>' => 0];
        
        $existUser = $this->DataModelPatient->find()->where($_where)->toArray();
        /*if(!empty($existUser) && (count($existUser) >= 2)){
            //$this->message("The user has many course total: ".count($existUser)); 
            $this->message("You have reached the maximum number of classes; please continue using the app as a patient but not as a patient model.");
            $this->success(false); 
        } else if(count($existUser) == 1 && $existUser[0]->requested_training_id == 0){
            $gfe = false;
            $fields = ['DataConsultation.uid','DataConsultation.payment','DataConsultation.schedule_date','DataCertificates.uid','DataCertificates.date_start','DataCertificates.date_expiration'];

            $_where = ['DataConsultation.deleted' => 0];
            $_where['DataConsultation.status'] = "CERTIFICATE";

            $_where['DataConsultation.patient_id'] = USER_ID;
            $_where['OR'] = ['DataConsultation.assistance_id >' => 0, 'DataConsultation.assistance_id' => -1];

            $certItem = $this->DataConsultation->find()->select($fields)
            ->join([
                'DataCertificates' => ['table' => 'data_certificates', 'type' => 'LEFT', 'conditions' => 'DataCertificates.consultation_id = DataConsultation.id'],
            ])
            ->where($_where)->last();

            if(!empty($certItem) && $certItem['DataCertificates']['date_expiration'] > date('Y-m-d')){
                $gfe = true;
            }

            $this->DataModelPatient->updateAll(
                [
                    'requested_training_id' => intval(get('training','')),
                    'gfe' => $gfe,
                    'understand' => 'Yes',
                ],
                ['DataModelPatient.id' => $existUser[0]->id]
            );
            $this->success(true); 
        }else {*/
            $gfe = false;
            $fields = ['DataConsultation.uid','DataConsultation.payment','DataConsultation.schedule_date','DataCertificates.uid','DataCertificates.date_start','DataCertificates.date_expiration'];

            $_where = ['DataConsultation.deleted' => 0];
            $_where['DataConsultation.status'] = "CERTIFICATE";

            $_where['DataConsultation.patient_id'] = USER_ID;
            $_where['OR'] = ['DataConsultation.assistance_id >' => 0, 'DataConsultation.assistance_id' => -1];

            $certItem = $this->DataConsultation->find()->select($fields)
            ->join([
                'DataCertificates' => ['table' => 'data_certificates', 'type' => 'LEFT', 'conditions' => 'DataCertificates.consultation_id = DataConsultation.id'],
            ])
            ->where($_where)->last();

            if(!empty($certItem) && $certItem['DataCertificates']['date_expiration'] > date('Y-m-d')){
                $gfe = true;
            }

            $array_save = array(
                'uid' => Text::uuid(),
                'name' => USER_NAME,
                'mname' => '',
                'lname' => USER_LNAME,
                'email' => USER_EMAIL,
                'phone' => USER_PHONE,
                'requested_training_id' => intval(get('training','')),
                'gfe' => $gfe,
                'understand' => 'Yes',
            );

            $c_entity = $this->DataModelPatient->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                if ($this->DataModelPatient->save($c_entity)) {
                    $this->success(true); 
                }
            }
        //}
    }

    public function get_summary_trainings_patient_model() {
        $this->loadModel('SpaLiveV1.DataModelPatient');
        $this->loadModel('SpaLiveV1.CatTrainings');
        $this->loadModel('SpaLiveV1.DataConsultation');
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
        //USER_EMAIL
        //$training_id = get('training_id', ''); SELECT `id`,`requested_training_id`,`registered_training_id` FROM `data_model_patient` WHERE `deleted`=0 and `uid` ="UID"
        // SELECT * FROM `cat_trainings` WHERE `deleted`=0 and FIND_IN_SET(id,"46,94");

        // $user_email = 'farmeando@dinero.com';
        $user_email = USER_EMAIL;

        $_fields = ['requested_training_id','registered_training_id','status','deleted'];                
        $_where = ['DataModelPatient.email' => $user_email, 
                   //'DataModelPatient.deleted' => 0,
                ];
        $training = $this->DataModelPatient->find()->select($_fields)->where($_where);
        $list = [];
        $listRequest = [];
        $listRegister = [];
        $listCancel = [];
        $str_list=""; 
        foreach($training as $t){
            if(isset($t['requested_training_id']) && isset($t['registered_training_id']) && $t['registered_training_id'] == 0 && $t['status']!='cancel' && $t['deleted']==0){
                $listRequest[] = $t['requested_training_id'];
                $list[] = $t['requested_training_id'];
            }
            if(isset($t['registered_training_id'])){
                
                $list[] = $t['registered_training_id'];
                if(isset($t['registered_training_id']) && $t['status']=='cancel'){
                    //$listCancel[] = $t['registered_training_id'];                
                }elseif($t['deleted']==0 && $t['registered_training_id'] !=0){
                    $listRegister[] = $t['registered_training_id'];
                }
            }
            if(isset($t['requested_training_id']) && $t['status']=='cancel' && $t['deleted']==0){
                $listCancel[] = $t['requested_training_id'];                
            }
            
        }
        if(count($list)>1){
            $str_list = implode(",", $list);
        }else{
            if(count($list)>0)
                $str_list = $list[0];
            else{
                $this->message("No data found");
                $this->success();
                return;
            }        
        }
        //$this->set('str_list', $str_list);
        //$this->set('list', $list);
        //$this->set('data', $training); return;
        $this->set('register', null); 
        $this->set('request', null);
        $this->set('cancel', null);
        $this->set('show_popup', array('show' => false, 'data' => null));
        $now = date('Y-m-d H:i:s');
        $day_now = date('Y-m-d');
        if(count($listRequest)>0){
            
            $_fields = ['CatTrainings.id','CatTrainings.title','CatTrainings.scheduled','CatTrainings.address','CatTrainings.city','CatTrainings.state_id','CatTrainings.zip','CatTrainings.level','CatTrainings.created', 'status'=>'DataModelPatient.status', 'id' => 'CatTrainings.id', 'DataModelPatient.id'];            
            $_fields['time_left'] = "(SELECT  TIMESTAMPDIFF(HOUR, '".$now."',scheduled)   AS time_left FROM cat_trainings  ct  WHERE  ct.id = CatTrainings.id)";
            $_join = ['table' => 'data_model_patient', 'alias' => 'DataModelPatient','type' => 'left', 'conditions' => 'DataModelPatient.requested_training_id = CatTrainings.id and DataModelPatient.status != "cancel" and DataModelPatient.email = "'.$user_email.'"' ]; 
            $_where = ['CatTrainings.id IN' => $listRequest, 
                       'CatTrainings.deleted' => 0,
                       'DataModelPatient.deleted' => 0,
                   ];        
            $arr_Request = $this->CatTrainings->find()
                                      ->select($_fields)
                                      ->join($_join)
                                      ->where($_where)
                                      ->group(['DataModelPatient.requested_training_id']);
            $array_request = [];

            foreach($arr_Request as $value){

                $array_request[] = [
                    'id' => $value['id'],
                    'title' => $value['title'],
                    // 'scheduled' => date('c', strtotime($value['scheduled']->i18nFormat('yyyy-MM-dd HH:mm:ss'))),
                    // 'scheduled' => $value['scheduled']->i18nFormat('MM-dd-yyyy 04:30') . ' PM',
                    'scheduled' => $value['level'] == 'LEVEL 2' ? $value['scheduled']->i18nFormat('MM-dd-yyyy 03:30') . ' PM' : $value['scheduled']->i18nFormat('MM-dd-yyyy 04:30') . ' PM',
                    'address' => $value['address'],
                    'city' => $value['city'],
                    'state_id' => $value['state_id'],
                    'zip' => $value['zip'],
                    'level' => $value['level'],
                    'created' => $value['created'],
                    'status' => $value['status'],
                    'DataModelPatient' => array('id' => $value['DataModelPatient']['id']),
                    'time_left' => $value['time_left'],
                ];
            }
            
            $this->set('request', $array_request);
        }
        
        if(count($listRegister)>0){ 
            //'data_training_id' =>
            $_fields = ['CatTrainings.id','CatTrainings.title','CatTrainings.scheduled','CatTrainings.address','CatTrainings.city','CatTrainings.state_id','CatTrainings.zip','CatTrainings.level','CatTrainings.created','assistance'=>'DataModelPatient.assistance', 'status'=>'DataModelPatient.status', 'notification'=>'DataModelPatient.notification','id' => 'CatTrainings.id', 'DataModelPatient.id', 'CCT.id'];
            $_fields['show_assistance_code'] = "(SELECT  ( CASE WHEN scheduled <= '".$now."' THEN 'TRUE' ELSE 'FALSE' END) AS total FROM `cat_trainings` ct WHERE ct.id = CatTrainings.id)";
            $_fields['time_left'] = "(SELECT  TIMESTAMPDIFF(HOUR, '".$now."',scheduled)   AS time_left FROM cat_trainings  ct  WHERE  ct.id = CatTrainings.id)";
            $_join = [
                'DataModelPatient' => ['table' => 'data_model_patient', 'type' => 'LEFT', 'conditions' => 'DataModelPatient.registered_training_id = CatTrainings.id and DataModelPatient.email = "'.$user_email.'"' ],
                'CCT' => ['table' => 'cat_courses_type', 'type' => 'LEFT', 'conditions' => 'CCT.name_key = CatTrainings.level AND CCT.deleted = 0'],
            ];
            $_where = ['CatTrainings.id IN' => $listRegister, 'CatTrainings.deleted' => 0, 'DataModelPatient.deleted' => 0,];
            $arr_Register = $this->CatTrainings->find()->select($_fields)->join($_join)->where($_where);

            $array_register = [];

            foreach($arr_Register as $value){
                $ot = '';
                if($value['level'] == 'LEVEL 1' || $value['level'] == 'LEVEL 2' || $value['level'] == 'LEVEL 3 FILLERS' || $value['level'] == 'MYSPALIVES_HYBRID_TOX_FILLER_COURSE' || $value['level'] == 'MYSPALIVE_S_HYBRID_TOX_FILLER_COURSE' || $value['level'] == 'LEVEL 3 MEDICAL'){
                    $ot = $value['level'];
                    $consultation = $this->DataConsultation->find()
                    ->select(['DataConsultation.id', 'DataConsultation.status', 'DataCertificates.date_expiration', 'DataCertificates.certificate_url'])
                    ->join([
                        'DataCertificates' => ['table' => 'data_certificates', 'type' => 'LEFT', 'conditions' => 'DataCertificates.consultation_id = DataConsultation.id']
                    ])
                    ->where([
                        'DataConsultation.patient_id' => USER_ID, 
                        'DataConsultation.deleted' => 0,
                        'OR' => [
                            'FIND_IN_SET("92", DataConsultation.treatments) > 0',
                            'FIND_IN_SET("93", DataConsultation.treatments) > 0'
                        ]
                    ])->last();

                    if(empty($consultation)){
                        $array_gfe = [
                            'status' => 'NO_STARTED',
                            'text' => 'Complete your Good Faith Exam (GFE)',
                            'url_certificate' => '',
                            'id' => 0,
                        ];
                    }else{
                        if($consultation['status'] == 'INIT' || ($consultation['status'] == 'CERTIFICATE' && empty($consultation['DataCertificates']['date_expiration']))){
                            $array_gfe = [
                                'status' => 'WAITING',
                                'text' => 'Waiting for the Examiner to give you the certificate.',
                                'url_certificate' => '',
                                'id' => 0,
                            ];
                        }else{
                            if($consultation['DataCertificates']['date_expiration'] > $day_now){
                                $array_gfe = [
                                    'status' => 'COMPLETED',
                                    'text' => 'Good Faith Exam (GFE) completed',
                                    'url_certificate' => $consultation['DataCertificates']['certificate_url'],
                                    'id' => $consultation['id'],
                                ];
                            }else{
                                $array_gfe = [
                                    'status' => 'EXPIRED',
                                    'text' => 'Good Faith Exam (GFE) expired',
                                    'url_certificate' => $consultation['DataCertificates']['certificate_url'],
                                    'id' => $consultation['id'],
                                ];
                            }
                        }
                    }
                }else{
                    $ot = 'OTHER TREATMENTS';
                    $consultation = $this->DataConsultation->find()
                    ->select(['DataConsultation.id', 'DataConsultation.status', 'DataCertificates.date_expiration', 'DataCertificates.certificate_url'])
                    ->join([
                        'DataCertificates' => ['table' => 'data_certificates', 'type' => 'LEFT', 'conditions' => 'DataCertificates.consultation_id = DataConsultation.id']
                    ])
                    ->where([
                        'DataConsultation.patient_id' => USER_ID, 
                        'DataConsultation.deleted' => 0,
                        'DataConsultation.course_type_id' => $value['CCT']['id'],
                    ])->last();

                    if(empty($consultation)){
                        $array_gfe = [
                            'status' => 'NO_STARTED',
                            'text' => 'Complete your Good Faith Exam (GFE)',
                            'url_certificate' => '',
                            'id' => 0,
                        ];
                    }else{
                        if($consultation['status'] == 'INIT' || ($consultation['status'] == 'CERTIFICATE' && empty($consultation['DataCertificates']['date_expiration']))){
                            $array_gfe = [
                                'status' => 'WAITING',
                                'text' => 'Waiting for the Examiner to give you the certificate.',
                                'url_certificate' => '',
                                'id' => 0,
                            ];
                        }else{
                            if($consultation['DataCertificates']['date_expiration'] > $day_now){
                                $array_gfe = [
                                    'status' => 'COMPLETED',
                                    'text' => 'Good Faith Exam (GFE) completed',
                                    'url_certificate' => $consultation['DataCertificates']['certificate_url'],
                                    'id' => $consultation['id'],
                                ];
                            }else{
                                $array_gfe = [
                                    'status' => 'EXPIRED',
                                    'text' => 'Good Faith Exam (GFE) expired',
                                    'url_certificate' => $consultation['DataCertificates']['certificate_url'],
                                    'id' => $consultation['id'],
                                ];
                            }
                        }
                    }
                }

                $array_register[] = [
                    'id' => $value['id'],
                    'title' => $value['title'],
                    'scheduled' => $value['level'] == 'LEVEL 2' ? $value['scheduled']->i18nFormat('MM-dd-yyyy 03:30') . ' PM' : $value['scheduled']->i18nFormat('MM-dd-yyyy 04:30') . ' PM',
                    'address' => $value['address'],
                    'city' => $value['city'],
                    'state_id' => $value['state_id'],
                    'zip' => $value['zip'],
                    'level' => $value['level'],
                    'created' => $value['created'],
                    'status' => $value['status'],
                    'DataModelPatient' => array('id' => $value['DataModelPatient']['id']),
                    'notification' => $value['notification'],
                    'assistance' => $value['assistance'],
                    'time_left' => $value['time_left'],
                    'show_assistance_code' => $value['show_assistance_code'],
                    'gfe' => $array_gfe,
                    'ot' => $ot,
                ];
            }

            $this->set('register', $array_register); 

            foreach ($arr_Register as $key => $value) {
                if($value['notification'] == 1){
                    $value['scheduled'] = $value['level'] == 'LEVEL 2' ? $value['scheduled']->i18nFormat('MM-dd-yyyy 03:30') . ' PM' : $value['scheduled']->i18nFormat('MM-dd-yyyy 04:30') . ' PM';
                    $this->set('show_popup', array('show' => true, 'data' => $value));
                    $this->DataModelPatient->updateAll(
                        ['notification' => 0],
                        ['id' => $value['DataModelPatient']['id'], 'email' => $user_email]
                    );
                    break;
                }
            }
        }        
        
        if(count($listCancel)>0){ 
            //'data_training_id' =>
            $_fields = ['CatTrainings.id','CatTrainings.title','CatTrainings.scheduled','CatTrainings.address','CatTrainings.city','CatTrainings.state_id','CatTrainings.zip','CatTrainings.level','CatTrainings.created','assistance'=>'DataModelPatient.assistance', 'status'=>'DataModelPatient.status','id' => 'CatTrainings.id'];
            $_join = ['table' => 'data_model_patient', 'alias' => 'DataModelPatient','type' => 'left', 'conditions' => 'DataModelPatient.requested_training_id = CatTrainings.id and DataModelPatient.email = "'.$user_email.'"' ];
            $_where = ['CatTrainings.id IN' => $listCancel, 
                       'CatTrainings.deleted' => 0,
                       'DataModelPatient.deleted' => 0,
                       'DataModelPatient.status' => 'cancel'
                   ];        
            $arr_Register = $this->CatTrainings->find()
                                      ->select($_fields)                                      
                                      ->join($_join)
                                      ->where($_where);

            foreach($arr_Register as $value){
                $array_cancel[] = [
                    'id' => $value['id'],
                    'title' => $value['title'],
                    // 'scheduled' => $value['scheduled']->i18nFormat('MM-dd-yyyy 04:30') . ' PM',
                    'scheduled' => $value['level'] == 'LEVEL 2' ? $value['scheduled']->i18nFormat('MM-dd-yyyy 03:30') . ' PM' : $value['scheduled']->i18nFormat('MM-dd-yyyy 04:30') . ' PM',
                    'address' => $value['address'],
                    'city' => $value['city'],
                    'state_id' => $value['state_id'],
                    'zip' => $value['zip'],
                    'level' => $value['level'],
                    'created' => $value['created'],
                    'assistance' => $value['assistance'],
                    'status' => $value['status']
                ];
            }

            $this->set('cancel', $array_cancel); 
        }
        /*$summ = new SummaryController();
        $res_sc = $summ->gfeStatusForTreatment(USER_ID,0);
        $this->set("status_gfe",$res_sc);*/
        $text = "You can apply to as many trainings as you want. The Selection process is aleatory and we have hundreds of participants. Please don't ask other injectors for free units, they will be given in the class (up to 20, and then $5 per unit).";
        $this->set('text', $text);
        $this->success();
        
    }

    public function cancel_course_patient_model(){
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

        $course_id = get('course_id', 0);
        if($course_id == 0){
            $this->message('Course not found.');
            return;
        }

        $id = get('id', 0);
        if($id == 0){
            $this->message('Id not found.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataModelPatient');

        $req_trainings = $this->DataModelPatient
            ->find()
            ->where([
                'email' => USER_EMAIL,
                'deleted' => 0,
            ])
            ->all();
        $req_model = $this->DataModelPatient
        ->find()
        ->where([
            'id' => $id,
            'email' => USER_EMAIL,
            'deleted' => 0,
        ])
        ->first();

        if(Count($req_trainings) == 1){
            $this->DataModelPatient->updateAll(
                [
                    'requested_training_id' => 0,
                    'registered_training_id' => 0,
                ],
                ['id' => $id]
            );
            $this->success();
        }else if(Count($req_trainings) > 1){
            //validate not register in course
            if($req_model->registered_training_id ==0){
                $this->DataModelPatient->updateAll([
                    'status' => 'cancel',
                    'deleted' => 1,
                ], [
                    'id' => $id,
                ]);
                $this->success();
                return;
            }

            $this->loadModel('SpaLiveV1.CatTrainings');        
            $trainings = $this->CatTrainings->find()->select()->where(['id' => $course_id])->first();

            $now =   date('Y-m-d H:i:s');        
            $modified  = date('Y-m-d H:i:s', strtotime($trainings->scheduled->i18nFormat('yyyy-MM-dd HH:mm:ss') . ' ')) ;
            $timestamp1 = strtotime($modified);
            $timestamp2 = strtotime($now);            
            $hour = ( $timestamp1 - $timestamp2 )/(60*60);            
                
            $this->set('hours', $hour);
            if( $hour < 24 ){
                $this->DataModelPatient->updateAll([
                    'status' => 'cancel'
                ], [
                    'id' => $id,
                ]);
            }else{
                $this->DataModelPatient->updateAll([
                    'status' => 'cancel',
                    'deleted' => 1,
                ], [
                    'id' => $id,
                ]);
            }
            
            $this->success();
        }
        
        /* if($req_trainings->registered_training_id > 0){ 
            $c_trainings = $this->DataModelPatient
                ->find()
                ->where([
                    'email' => USER_EMAIL,
                    'registered_training_id' => $course_id,
                ])
                ->first();
            
            if(empty($c_trainings)){
                $this->message('Invalid course');
                return;
            }                                
            $this->loadModel('SpaLiveV1.CatTrainings');        
            $trainings = $this->CatTrainings->find()->select()->where(['id' => $course_id])->first();

            $now =   date('Y-m-d H:i:s');        
            $modified  = date('Y-m-d H:i:s', strtotime($trainings->scheduled->i18nFormat('yyyy-MM-dd HH:mm:ss') . ' ')) ;
            $timestamp1 = strtotime($modified);
            $timestamp2 = strtotime($now);            
            $hour = ( $timestamp1 - $timestamp2 )/(60*60);            
                
            $this->set('hours', $hour);
            if( $hour >= 24 ){
                $this->DataModelPatient->updateAll([
                    'status' => 'cancel'
                ], [
                    'email' => USER_EMAIL,
                    'registered_training_id' => $course_id
                ]);
            }else{
                $this->DataModelPatient->updateAll([
                    'status' => 'cancel',
                    'deleted' => 1,
                ], [
                    'email' => USER_EMAIL,
                    'registered_training_id' => $course_id
                ]);
            }
            
            $this->success();
        } else if($req_trainings->registered_training_id == 0){ 
            $this->DataModelPatient->updateAll(
                ['requested_training_id' => 0],
                ['id' => $req_trainings->id]
            );
            $this->success();
        }      */

    }

    public function get_certificate_status(){
        $this->loadModel('SpaLiveV1.CatCourses');
        $this->loadModel('SpaLiveV1.DataCourses');

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

        $status = get('status','');
        if (empty($status)) {
            $this->message('Status not found.');
            return;
        }

        $licence_status = $status;

        if($status == "DONE"){
            $licence_status = "APPROVED";
        }

        $_fields = ['School.nameschool','Course.title','Course.type','Course.id','Course.school_option_id','DataCourses.id'];

        $_join = [
            'Course' => ['table' => 'cat_courses','type' => 'INNER','conditions' => 'Course.id = DataCourses.course_id'],
            'School' => ['table' => 'data_school_register','type' => 'INNER','conditions' => 'School.id = Course.school_id'],
            'Licence' => ['table' => 'sys_licences','type' => 'LEFT','conditions' => 'Licence.user_id = DataCourses.user_id'],
        ];

        $order = "CASE WHEN (Course.type = 'NEUROTOXINS BASIC' OR Course.type = 'NEUROTOXINS ADVANCED' OR Course.type = 'BOTH NEUROTOXINS') then 1 else 0 END DESC";

        $ent_course = $this->DataCourses->find()->select($_fields)
        ->where(
            ['DataCourses.status' => $status, 'DataCourses.user_id' => $user["user_id"], 'DataCourses.deleted' => 0]
        )->join($_join)->order([$order])->toArray();

        /*$data = array(
            "school_name" => $ent_course["School"]["nameschool"],
        );*/

        if(Count($ent_course) > 0){
            $this->set('school_name', $ent_course[0]["School"]["nameschool"]);
            $this->set('course_name', $ent_course[0]["Course"]["title"]);
            $this->set('course_type', $ent_course[0]["Course"]["type"]);
            $this->set('course_id', $ent_course[0]["Course"]["id"]);
            $this->set('data_course_id', $ent_course[0]["id"]);

            if ($ent_course[0]["Course"]["type"] == 'OTHER TREATMENTS') {
                $subs = [];

                $this->loadModel('CatSchoolOptionCert');


                $ent_data_trainings = $this->CatSchoolOptionCert
                ->find()->select([
                    'name' => 'OtherTreatment.name',
                    'require_mdsub' => 'OtherTreatment.require_mdsub',
                    'msl' => 'CatAgreementMSL.uid',
                    'md' => 'CatAgreementMD.uid',
                    'md_agreement' => 'DataAgreementMD.id',
                    'msl_agreement' => 'DataAgreementMSL.id',
                ])
                ->join([
                    'OtherTreatment' => ['table' => 'sys_treatments_ot', 'type' => 'INNER', 'conditions' => 'OtherTreatment.id = CatSchoolOptionCert.sys_treatment_ot_id AND OtherTreatment.deleted = 0'],
                    'CatAgreementMSL' => [
                        'table'      => 'cat_agreements',
                        'type'       => 'LEFT',
                        'conditions' =>
                        "CatAgreementMSL.state_id = " . USER_STATE . "
                     AND CatAgreementMSL.agreement_type = 'OTHER_TREATMENTS'
                     AND CatAgreementMSL.other_treatment_id = OtherTreatment.id
                     AND CatAgreementMSL.deleted = 0
                     AND CatAgreementMSL.issue_type = 'MSL'"
                    ],
                    'CatAgreementMD' => [
                        'table'      => 'cat_agreements',
                        'type'       => 'LEFT',
                        'conditions' =>
                        "CatAgreementMD.state_id = " . USER_STATE . "
                     AND CatAgreementMD.agreement_type = 'OTHER_TREATMENTS'
                     AND CatAgreementMD.other_treatment_id = OtherTreatment.id
                     AND CatAgreementMD.deleted = 0
                     AND CatAgreementMD.issue_type = 'MD'"
                    ],
                    'DataAgreementMSL' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'DataAgreementMSL.agreement_uid = CatAgreementMSL.uid AND DataAgreementMSL.deleted = 0 AND DataAgreementMSL.user_id = ' . USER_ID],
                    'DataAgreementMD' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'DataAgreementMD.agreement_uid = CatAgreementMD.uid AND DataAgreementMD.deleted = 0 AND DataAgreementMD.user_id = ' . USER_ID],
                ])
                ->where([
                        'CatSchoolOptionCert.id' => $ent_course[0]["Course"]["school_option_id"],
                        'OtherTreatment.deleted' => 0,
                        'OR' => [
                            'CatAgreementMSL.uid IS NOT' => null,
                            'CatAgreementMD.uid IS NOT'  => null,
                        ],
                    ])
                ->group(['CatAgreementMD.id','CatAgreementMSL.id'])
                ->all();


                $subs = [];
                foreach($ent_data_trainings as $row) {
                    $subs[] = [
                        'title' => $row->name,
                        'agreement_md' => !empty($row->md) && empty($row->md_agreement) && $row->require_mdsub == 1 ? $row->md : '',
                        'agreement_msl' => !empty($row->msl && empty($row->msl_agreement)) ? $row->msl : '',
                    ];
                }
                
                $this->set('subscriptions', $subs);
            } else {
                $this->set('subscriptions', []);
            }
            $this->success();
        }else{
            $this->set('school_name', "");
            $this->set('course_name', "");
            $this->message('Certificate status not found.');
            return;
        }
    }

    public function update_step_subscription(){
        $this->loadModel('SpaLiveV1.SysUsers');

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

        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $user["user_id"]])->first();

        $subscription = get('subscription',"");

        if(empty($subscription)){
            /*descomentar este código cuando se suba iv therapy y borrar el siguiente
            $this->message('Subscription not found.');
            return;
            */
            $ent_user->steps = "MSLSCHOOLSUBSCRIPTION";
            $update = $this->SysUsers->save($ent_user);

            if($update){
                $this->success();
                return;
            }else{
                $this->message('Error in update user step.');
                return;
            }
        }

        if($subscription=="Other Schools"){

            $ent_user->steps = "MSLSCHOOLSUBSCRIPTION";

        }else if($subscription=="IV Therapy"){

            $ent_user->steps = "MSLIVTSUBSCRIPTION";

        }else if($subscription=="Neurotoxin"){

            $ent_user->steps = "MSLSUBSCRIPTION";

        }else if($subscription=="IV Therapy + Neurotoxin"){

            $ent_user->steps = "MSL+IVTSUBSCRIPTION";

        }else if($subscription=="Fillers"){
            $ent_user->steps = "SUBSCRIPTIONMSLFILLERS";
        }

        $update = $this->SysUsers->save($ent_user);

        if($update){
            $this->success();
        }else{
            $this->message('Error in update user step.');
            return;
        }
    }

    public function get_prices_trainings(){
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

        $this->loadModel('SpaLiveV1.DataTrainings');
        $this->loadModel('SpaLiveV1.CatTrainings');

        $fields = [
            'CatTrainings.id',
            'CatTrainings.title',
            'CatTrainings.scheduled',
            'CatTrainings.address',
            'CatTrainings.city',
            'CatTrainings.state_id',
            'CatTrainings.zip',
            'CatTrainings.level',    
            'CatTrainings.created',
            'data_training' => 'DataTrainings.id', 
        ];
        $courses = $this->CatTrainings->find('all', [
            'conditions' => [
                'DataTrainings.user_id' => $user["user_id"],
                'CatTrainings.level' => "LEVEL 2",
            ],            
        ])
        ->select($fields)
        ->join([
            'table' => 'data_trainings',
            'alias' => 'DataTrainings',
            'type' => 'INNER',
            'conditions' => 'CatTrainings.id = DataTrainings.training_id'
        ])
        ->where([
            'DataTrainings.deleted' => 0,
            'CatTrainings.deleted' => 0,            
        ])
        ->first();

        $has_advanced_course = false;
        if(!empty($courses)){
            $has_advanced_course = true;
        }

        $array_data = [];

        $array_data[] = array(
            'title'  => "Basic Neurotoxins",
            'price' => $this->training_basic,
        );

        $now = date('Y-m-d');

        //find payed basic
        $_fields = ['CatTrainings.id', 'CatTrainings.title', 'CatTrainings.scheduled', 'CatTrainings.neurotoxins', 'CatTrainings.fillers', 'CatTrainings.materials', 'CatTrainings.available_seats', 'CatTrainings.level','State.name','State.abv','CatTrainings.address','CatTrainings.zip','CatTrainings.city', 'data_training_id' => 'DataTrainigs.id', 'attended' => 'DataTrainigs.attended'];
        $_fields['assistants'] = "(SELECT COUNT(id) from data_trainings DT WHERE DT.training_id = CatTrainings.id AND DT.deleted = 0)";
        $_fields['enrolled'] = "(SELECT COUNT(id) from data_trainings DT WHERE DT.training_id = CatTrainings.id AND DT.deleted = 0 AND DT.user_id = " . USER_ID . " )";
        $_join = [
                'DataTrainigs' => ['table' => 'data_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainings.id = DataTrainigs.training_id'],
                'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = CatTrainings.state_id']
            ];
        $_where = ['DataTrainigs.user_id' => USER_ID, 'DataTrainigs.deleted' => 0, 'DataTrainigs.attended' => 1, 'CatTrainings.deleted' => 0,'(DATE_FORMAT(CatTrainings.scheduled, "%Y-%m-%d 09:00:00") < "' . $now . '")', 'CatTrainings.level' => 'LEVEL 2'];

        $done_trainings = $this->CatTrainings->find()->select($_fields)
        ->join($_join)
        ->where($_where)->order(['CatTrainings.scheduled' => 'ASC'])->toArray();

        //
        $has_advance_pay = false;
        
        if(!empty($done_trainings)){
            $has_advance_pay = true;
        } else {
            $has_advance_pay = false;
        }
        

        if(!$has_advanced_course){
            if(!$has_advance_pay){
                $array_data[] = array(
                    'title'  => "Advanced Neurotoxins",
                    'price' => $this->training_advanced,
                );
            }
        }

        $this->set('data', $array_data);
        $this->success();
    }

    private function email_after_validate_course(){
        $email ="
            <p>Dear Certified Injector,</p>
            <br>
            <br>
            <p>Congratulations on reaching this milestone in your MySpaLive journey! As you stand on the cusp of an exciting new chapter, we at MySpaLive are immensely proud of your achievements and are here to support your continued success.</p>            
            
            <br>
            <p>To ensure you have all the necessary resources as you move forward, I have listed the key contacts that you might need for additional support:</p>
            <br>
            <p><b>Administrative Coordinator</b></p>
            <ul>
                <li>Main Contact:</li>
                <ul>
                    <li>Email: MySpa@MySpalive.com</li>                
                    <li>Phone: 972.755.3038 EXT 0</li>
                </ul>
            </ul>

            <p><b>For assistance on:</b></p>
            <ul>
                <li>General information</li>
                <li>Shipping-related questions</li>
                <li>Scheduling</li>
                <li>Refunds</li>
                <li>Account holds/status</li>
            </ul>

            <p>
                For specialized support, we've detailed where you should direct your inquiries:
                <ul>
                <li>Tech/App Support: <a href='mailto:Support@myspalive.com'>Support@myspalive.com</a> or 972.755.3038 EXT 5</li>
                <li>Complaints/Quality Control: <a href='mailto:Qualityassurance@myspalive.com'>Qualityassurance@myspalive.com</a> or 972.755.3038 EXT 3</li>
                <li>Medical/After Training Assistance: <a href='mailto:Deidra@myspalive.com'>Deidra@myspalive.com</a> or 830.730.6771</li>
                <li>Patient-Related Questions: <a href='mailto:Patientrelations@myspalive.com'>Patientrelations@myspalive.com</a> or 430.205.4192</li>
                <li>Injector Course Inquiries: <a href='mailto:jenna@myspalive.com'>jenna@myspalive.com</a> or 972.400.0024</li>
                <li>Marketing Support/VIVID Assistance: <a href='mailto:emily@myspalive.com'>emily@myspalive.com</a> or 972.400.3326</li>
                </ul>
            </p>

            <p>I hope this list serves as a helpful resource as you move ahead. Remember, every member of the MySpaLive team is here to ensure your growth, empowerment, and success.</p>
            <br>          
            <p>
                Warm Regards,
                <br>Ashlan Greenfield.
            </p>
            ";

        return $email;
    }

    public function get_injector_certificates(){
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

        $this->loadModel('SpaLiveV1.DataCourses');
        $this->loadModel('SpaLiveV1.CatCourses');

        $_fields = ['DataCourses.course_id','DataCourses.status','Cat.title','DataCourses.front', 'School.nameschool', 'School.status', 'School.id',
        'Licence.status'];

        $_join = [
            'Cat' => ['table' => 'cat_courses','type' => 'LEFT','conditions' => 'Cat.id = DataCourses.course_id'],
            'School' => ['table' => 'data_school_register','type' => 'LEFT','conditions' => 'School.id = Cat.school_id'],
            'Licence' => ['table' => 'sys_licences','type' => 'LEFT','conditions' => 'Licence.user_id = DataCourses.user_id'],
        ];

        $options['group'] = array('DataCourses.id');

        $_where = ['DataCourses.user_id' => $user["user_id"], 'DataCourses.deleted' => 0,
                   'School.status' => "Active", 'School.deleted' => 0, 'Cat.deleted' => 0];

        $ent_certificates = $this->DataCourses->find('all', $options)->select($_fields)->where($_where)->join($_join)->all();

        $array_certificates = [];

        if(!empty($ent_certificates)){
            foreach ($ent_certificates as $c) {

                $array_certificates[] = [
                    'course_id'         => $c->course_id,
                    'status'            => $c->status,
                    'front'             => $c->front,
                    'title'             => $c['Cat']['title'],
                    'school_status'     => $c['School']['status'],
                    'school_id'         => $c['School']['id'],
                    'school_name'       => $c['School']['nameschool'],
                    'licence_status'    => $c['Licence']['status'],
                ];
            }
        }

        $this->set('certificates', $array_certificates);
        $this->success();
    }

    public function update_check_fillers(){
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

        $this->loadModel('SpaLiveV1.SysUsers');

        $this->SysUsers->updateAll(
            ['filler_check' => 1],
            ['id' => $user["user_id"]]
        );

        $this->success();
    }

    public function get_injector_licences(){
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

        $this->loadModel('SpaLiveV1.SysLicences');
        $this->loadModel('SpaLiveV1.CatStates');

        $_fields = ['SysLicences.type','SysLicences.number','SysLicences.state','SysLicences.start_date','SysLicences.exp_date', 'State.name'];

        $_join = [
            'State' => ['table' => 'cat_states','type' => 'LEFT','conditions' => 'State.id = SysLicences.state'],
        ];

        $_where = ['SysLicences.user_id'=>USER_ID, 'SysLicences.deleted' => 0];

        $ent_licences = $this->SysLicences->find()->select($_fields)->where($_where)->join($_join)->all();


        if(!$ent_licences){
            $ent_licences = [];
        } else {
            foreach ($ent_licences as $licence) {
                 $licence['state_name'] = $licence['State']['name'];
                 unset($licence['State']);
            }
        }

        $this->set('licences', $ent_licences);
        $this->success();
    }

    public function refresh_waiting_certificate(){
        $this->loadModel('SpaLiveV1.DataCourses');

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

        if($user["steps"] != "WAITINGSCHOOLAPPROVAL" && $user["steps"] != "CERTIFICATESCHOOLAPPROVED" && 
            $user["steps"] != "CERTIFICATESCHOOLDENIED" && $user["steps"] != "WAITINGFILLERSAPPROVAL" && $user["steps"] != "FILLERSAPPROVED" && 
            $user["steps"] != "FILLERSDENIED"){

            $this->set('step', $user["steps"]);
            $this->set('status', "PENDING");
            $this->success();
            return;

        }else{

            $status = "PENDING";
            $licence_status = "PENDING";

            if($user["steps"] == "CERTIFICATESCHOOLAPPROVED" || $user["steps"] == "CERTIFICATESCHOOLDENIED"){

                if($user["steps"] == "CERTIFICATESCHOOLAPPROVED"){
                    $status = "DONE";
                }else{
                    $status = "REJECTED";
                }

                $licence_status = $status;

                if($status == "DONE"){
                    $licence_status = "APPROVED";
                }

                $_fields = ['School.nameschool','Course.title'];

                $_join = [
                    'Course' => ['table' => 'cat_courses','type' => 'INNER','conditions' => 'Course.id = DataCourses.course_id'],
                    'School' => ['table' => 'data_school_register','type' => 'INNER','conditions' => 'School.id = Course.school_id'],
                    'Licence' => ['table' => 'sys_licences','type' => 'LEFT','conditions' => 'Licence.user_id = DataCourses.user_id'],
                ];

                $ent_course = $this->DataCourses->find()->select($_fields)
                ->where(
                    ['DataCourses.status' => $status, 'DataCourses.user_id' => $user["user_id"], 'DataCourses.deleted' => 0]
                )->join($_join)->last();

                if(empty($ent_course)){
                    $this->set('step', "WAITINGSCHOOLAPPROVAL");
                    $this->set('status', "PENDING");
                    $this->success();
                    return;
                }

            }else if($user["steps"] == "FILLERSAPPROVED" || $user["steps"] == "FILLERSDENIED"){

                if($user["steps"] == "FILLERSAPPROVED"){
                    $status = "DONE";
                    $licence_status = "APPROVED";
                }else{
                    $status = "REJECTED";
                    $licence_status = "REJECTED";
                }

            }

            $this->set('step', $user["steps"]);
            $this->set('status', $licence_status);
            $this->success();
        }

    }

    public function get_schools_application($user_id){
        $this->loadModel('SpaLiveV1.DataCourses');
        $this->loadModel('SpaLiveV1.CatCourses');

        $user_schools = $this->DataCourses->find()->select(['CatCourses.type'])->join([
            'CatCourses' => ['table' => 'cat_courses', 'type' => 'INNER', 'conditions' => 'CatCourses.id = DataCourses.course_id'],
            ])->where(['CatCourses.type IN' => array('NEUROTOXINS BASIC', 'NEUROTOXINS ADVANCED', 'BOTH NEUROTOXINS'),'DataCourses.user_id' => $user_id,'DataCourses.deleted' => 0,'DataCourses.status' => 'DONE'])->first();
        
        $school = true;

        if(empty($user_schools)){
            $school = false;
        }

        return $school;
    }

    public function change_to_w9_step(){
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

        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $user["user_id"]])->first();

        $ent_user->steps = "W9";

        $update = $this->SysUsers->save($ent_user);

        if($update){
            $this->success();
        }else{
            $this->message('Error in update user step.');
            return;
        }
    }

    public function consult_neurotoxin_application($training,$user_id){

        if($training->attended == 0){

            return "Submit code";
            
        }else if($training->attended == 1){
            
            $subscriptions = $this->get_subscription_status($user_id);

            if($subscriptions=="ACCEPTED"){

                //quitar esto porq el cpr no es obligatorio
                /*$cpr = $this->get_cpr($user_id);

                if($cpr){*/

                    $treatments = $this->get_neuro_treatments($user_id);

                    if($treatments){
                        return "Configure";
                    }else{
                        return "Treatments Settings";
                    }

                /*}else{
                    return "Cpr";
                }*/

            }else{

                if($subscriptions=="MISSING MD SUBSCRIPTION"){
                    return "Missing MD Subscription";
                }else{
                    return "Missing MSL Subscription";
                }

            }

        }

    }

    public function get_neurotoxin_application(){
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

        $check_purchase = $this->get_purchase_neurotoxin($user["user_id"]);

        if(empty($check_purchase)){

            $this->set('status', "BUY COURSE");

        }else{

            $ent_neuro_training = $this->neurotoxin_application($user["user_id"]);

            if(empty($ent_neuro_training)){

                $this->set('status', "JOIN COURSE");

            }else{
                    
                if($ent_neuro_training["DataTrainings"]["attended"]==0){

                    $now = date('Y-m-d H:i:s');

                    if($ent_neuro_training->scheduled->i18nFormat('y-MM-dd HH:mm:ss') > $now){
                        $this->set('status', "STUDY");
                    }else{
                        $this->set('status', "SUBMIT CODE");
                    }

                }else if($ent_neuro_training["DataTrainings"]["attended"]==1){

                    /*$gfe = $this->payment_gfe($user["user_id"]);

                    if($gfe){*/

                        $subscriptions = $this->get_subscription_status($user["user_id"]);

                        if($subscriptions=="ACCEPTED"){

                            $cpr = $this->get_cpr($user["user_id"]);

                            if($cpr){

                                $treatments = $this->get_neuro_treatments($user["user_id"]);

                                if($treatments){
                                    $this->set('status', "ACCEPTED");
                                }else{
                                    $this->set('status', "TREATMENTS SETTINGS");
                                }

                            }else{
                                $this->set('status', "CPR");
                            }

                        }else{
                            $this->set('status', $subscriptions);
                        }

                    /*}else{
                        $this->set('status', "GFE");
                    }*/

                }else{
                    $this->set('status', "REJECTED");
                }
            }
        }

        $this->success();
    }

    public function get_purchase_neurotoxin($user_id){
        $this->loadModel('SpaLiveV1.DataPayment');

        $ent_payments_basic = $this->DataPayment->find()->where(['DataPayment.refund_id' => 0, 'DataPayment.id_from' => $user_id, 
                                                                 'DataPayment.type' => 'BASIC COURSE', 'DataPayment.payment !=' => '', 
                                                                 'DataPayment.is_visible' => 1])->first();

        return $ent_payments_basic;
    }

    public function neurotoxin_application($user_id){
        $this->loadModel('SpaLiveV1.CatTrainings');

        $ent_neuro_training = $this->CatTrainings->find()
            ->select(['CatTrainings.scheduled', 'DataTrainings.attended'])
            ->join([
                'DataTrainings' => ['table' => 'data_trainings',
                'type' => 'INNER', 
                'conditions' => 'DataTrainings.training_id = CatTrainings.id'],
            ])
            ->where([
                'CatTrainings.level' => 'LEVEL 1', 'DataTrainings.user_id' => $user_id, 'DataTrainings.deleted' => 0
            ])
            ->first();

        return $ent_neuro_training;
    }

    /*public function payment_gfe($user_id){
        $this->loadModel('SpaLiveV1.DataPayment');

        $ent_payment = $this->DataPayment->find()
        ->where(['DataPayment.id_from' => $user_id, 'DataPayment.id_to' => 0, 'DataPayment.type' => 'GFE', 'DataPayment.is_visible' => 1,
                 'DataPayment.payment <>' => '','DataPayment.prepaid' => 1, 'DataPayment.comission_payed' => 1])->first();
        
        if(!empty($ent_payment)){
            return true;
        }else{
            return false;
        }
    }*/

    public function get_cpr($user_id){
        $this->loadModel('SpaLiveV1.DataUserCprLicence');

        $ent_cpr = $this->DataUserCprLicence->find()
        ->where(['DataUserCprLicence.user_id' => $user_id])->first();

        if(!empty($ent_cpr)){
            return true;
        }else{
            return false;
        }
    }

    public function get_subscription_status($user_id){
        
        $Subscription = new SubscriptionController();
        $has_msl = $Subscription->has_service_subscription(
            $user_id,
            'BASIC NEUROTOXINS',
            'MSL'
        );

        if($has_msl){
            $has_md = $Subscription->has_service_subscription(
                $user_id,
                'BASIC NEUROTOXINS',
                'MD'
            );

            if($has_md){
                return "ACCEPTED";
            }else{
                return "MISSING MD SUBSCRIPTION";
            }
        }else{
            return "MISSING MSL SUBSCRIPTION";
        }    

    }

    public function get_neuro_treatments($user_id){
        $this->loadModel('SpaLiveV1.CatTreatmentsCi');
        $this->loadModel('SpaLiveV1.CatTreatmentsCategory');

        $cat_category = $this->CatTreatmentsCategory->find()
            ->where(['CatTreatmentsCategory.deleted' => 0, 'CatTreatmentsCategory.type' => 'NEUROTOXINS BASIC'])->first();

        if(!empty($cat_category)){
            $cat_treatment = $this->CatTreatmentsCi->find()
                ->join([
                    'Treatments' => ['table' => 'data_treatments_prices', 'type' => 'INNER', 'conditions' => 'Treatments.treatment_id = CatTreatmentsCi.id']
                ])
                ->where(
                    ['CatTreatmentsCi.category_treatment_id' => $cat_category->id, 
                    'Treatments.user_id' => $user_id, 'CatTreatmentsCi.deleted' => 0,'Treatments.deleted' => 0])
                ->order(['CatTreatmentsCi.id' => 'DESC'])->all();

            if(Count($cat_treatment) > 0) {
                return true;
            }else{
                return false;
            }
        }else{
            return false;
        }
    }

    public function get_training_for_services($user_id,$level){
        $this->loadModel('SpaLiveV1.DataTrainings');

        $_fields = ['CatTrainigs.id', 'CatTrainigs.title', 'CatTrainigs.scheduled', 'CatTrainigs.level', 'DataTrainings.attended'];
            
        $_join = [
            'CatTrainigs' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainings.training_id'],
            'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = CatTrainigs.state_id']
        ];

        $training = $this->DataTrainings->find()->select($_fields)->join($_join)->where(
            ["DataTrainings.deleted" => 0, 'DataTrainings.user_id' => $user_id, 'CatTrainigs.deleted' => 0, 'CatTrainigs.level' => $level])->first();

        return $training;

    }

    public function get_course_schools_for_services($user_id, $types){
        $this->loadModel('SpaLiveV1.DataCourses');

        $course = $this->DataCourses->find()->select(['DataCourses.status', 'CatCourses.type'])->join([
            'CatCourses' => ['table' => 'cat_courses', 'type' => 'INNER', 'conditions' => 'CatCourses.id = DataCourses.course_id'],
            ])->where(['CatCourses.type IN' => $types, 'DataCourses.user_id' => $user_id,
                       'DataCourses.deleted' => 0])->last();
        
        return $course;
        
    }

    public function get_uid_call() {
        $this->loadModel('SpaLiveV1.DataConsultation');
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

        $ent_consultation = $this->DataConsultation->find()->select(['DataConsultation.id', 'DataConsultation.status', 'DataConsultation.uid'])
        ->where(['DataConsultation.patient_id' => USER_ID, 'DataConsultation.status IN ' => array('ONLINE'), 'DataConsultation.deleted' => 0])
        ->last();
        if(!empty($ent_consultation)){
            $this->set('uid', $ent_consultation->uid);
        }else{
            $this->set('uid', '');
        }
       
        $this->success();
       

    }

    public function assing_school($recommended_school){
        $this->loadModel('SpaLiveV1.DataAssignedSchool');
        $this->loadModel('SpaLiveV1.DataSchoolInvitations');
        $this->loadModel('SpaLiveV1.DataCourses');

        // Check if user is already assigned to a school to avoid duplicate entry error
        $existing_assignment = $this->DataAssignedSchool->find()
            ->where(['user_id' => USER_ID, 'deleted' => 0])
            ->first();

        if ($existing_assignment) {
            return; // Already assigned, do nothing
        }

        if($recommended_school){
            $recommended = $this->DataSchoolInvitations->find()->where(['DataSchoolInvitations.email' => USER_EMAIL])->first();
            if(empty($recommended)){
                $school = $this->DataCourses->find()->select('DSR.user_id')
                ->join([
                    'CatCourses' => ['table' => 'cat_courses', 'type' => 'INNER', 'conditions' => 'CatCourses.id = DataCourses.course_id'],
                    'DSR' => ['table' => 'data_school_register', 'type' => 'INNER', 'conditions' => 'DSR.id = CatCourses.school_id']
                ])
                ->where(['DataCourses.user_id' => USER_ID, 'DataCourses.deleted' => 0, 'DataCourses.status' => 'DONE'])->first();

                if(!empty($school)){
                    $array_assing = array(
                        'user_id' => USER_ID,
                        'school_id' => $school['DSR']['user_id'],
                        'deleted' => 0,
                        'created' => date('Y-m-d H:i:s')
                    );

                    $entity_assigned = $this->DataAssignedSchool->newEntity($array_assing);
                    if(!$this->DataAssignedSchool->save($entity_assigned)){
                        $this->message('Error saving assigned school.');
                    }
                }else{
                    $this->message('You must be recommended by a school.');
                }
            }else{
                $array_assing = array(
                    'user_id' => USER_ID,
                    'school_id' => $recommended->parent_id,
                    'deleted' => 0,
                    'created' => date('Y-m-d H:i:s')
                );

                $entity_assigned = $this->DataAssignedSchool->newEntity($array_assing);

                if(!$this->DataAssignedSchool->save($entity_assigned)){
                    $this->message('Error saving assigned school.');
                }
            }
        }else{
            $recommended = $this->DataSchoolInvitations->find()->where(['DataSchoolInvitations.email' => USER_EMAIL])->first();
            if(empty($recommended)){
                $this->message('You must be recommended by a school.');
            }else{
                $array_assing = array(
                    'user_id' => USER_ID,
                    'school_id' => $recommended->parent_id,
                    'deleted' => 0,
                    'created' => date('Y-m-d H:i:s')
                );

                $entity_assigned = $this->DataAssignedSchool->newEntity($array_assing);

                if(!$this->DataAssignedSchool->save($entity_assigned)){
                    $this->message('Error saving assigned school.');
                }
            }
        }

    }

    public function syncSeatsToGoogleDocs() {
        try {
            $this->loadModel('SpaLiveV1.CatTrainings');
            $this->loadModel('SpaLiveV1.CatCoursesType');
            $this->loadModel('SpaLiveV1.CatStates');

            $now = date('Y-m-d H:i:s');
            $_where = ['CatTrainings.deleted' => 0, '(DATE_FORMAT(CatTrainings.scheduled, "%Y-%m-%d 19:00:00") >= "' . $now . '")'];

            $fields = [
                'CatTrainings.id',
                'CatTrainings.title',
                'CatTrainings.address',
                'CatTrainings.available_seats',
                'CatTrainings.level',
                'CatTrainings.city',
                'CatTrainings.scheduled',
                'CatTrainings.zip',
                'CatTrainings.state_id',
                'State.name',
                'State.abv'
            ];
            
            $fields['assistants'] = "(SELECT COUNT(DT.id) from data_trainings DT, sys_users SU WHERE DT.training_id = CatTrainings.id AND SU.id = DT.user_id AND DT.deleted = 0 AND SU.deleted = 0 AND SU.active = 1)";

            $ent_trainings = $this->CatTrainings->find()->select($fields)
            ->join([
                'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = CatTrainings.state_id'],
            ])->where($_where)->order(['CatTrainings.scheduled' => 'ASC'])->all();
                    
            $updated_count = 0;
            $errors = [];

            if(!empty($ent_trainings)){
                foreach($ent_trainings as $row) {
                    $seats_remaining = $row['available_seats'] - $row['assistants'];
                    if($seats_remaining <= 0) $seats_remaining = 0;

                    $courseType = $this->CatCoursesType->find()
                        ->where(['CatCoursesType.name_key' => $row->level])
                        ->first();
                    
                    $courseName = !empty($courseType['title']) ? $courseType['title'] : $row->level;
                    $stateInfo = !empty($row['State']['abv']) ? ',' . $row['State']['abv'] . ' ' : '';

                    $data = json_encode([
                        'title' => $row->title,
                        'scheduled' => $row->scheduled->i18nFormat('yyyy-MM-dd HH:mm:ss','America/Chicago'),
                        'id' => $row->id,
                        'address' => $row->address,
                        'city' => $row->city,
                        'zip' => $stateInfo . $row->zip,
                        'seats_remaining' => $seats_remaining,
                        'level' => $courseName
                    ]);

                    $scriptUrl = $this->getScriptUrlForCourse($courseName);
                    if ($scriptUrl) {
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $scriptUrl);
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
                        
                        $response = curl_exec($ch);
                        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        
                        if ($http_code == 200) {
                            $updated_count++;
                        } else {
                            $errors[] = "Training ID {$row->id}: HTTP {$http_code}";
                        }
                        
                        curl_close($ch);
                    }
                }
            }

            error_log("Google Docs Sync: {$updated_count} trainings updated, " . count($errors) . " errors");
            if (!empty($errors)) {
                error_log("Google Docs Sync Errors: " . implode(', ', $errors));
            }

            $this->success();
            $this->set('data', [
                'updated' => $updated_count,
                'errors' => count($errors),
                'total' => count($ent_trainings)
            ]);
            $this->message("Sincronización completada: {$updated_count} clases actualizadas");

        } catch (Exception $e) {
            error_log('Error in syncSeatsToGoogleDocs: ' . $e->getMessage());
            $this->success(false);
            $this->message('Error en sincronización: ' . $e->getMessage());
        }
    }

    private function getScriptUrlForCourse($courseName) {
        $scriptUrl = 'https://script.google.com/macros/s/AKfycbxP9Hd84nxGkHYANA1A-sJzqcSzC2FJpgIenZbBLsGGBtGJMEeUwLEwpOPqDG6SZHeIqw/exec';
        
        $courseScriptMap = [
            'LEVEL 1' => $scriptUrl,
            'LEVEL 3 MEDICAL' => $scriptUrl,
            'MySpaLive\'s Hybrid Tox & Filler Course' => $scriptUrl
        ];
        
        return $courseScriptMap[$courseName] ?? null;
    }
    
}