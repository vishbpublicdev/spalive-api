<?php
declare(strict_types=1);

namespace SpaLiveV1\Controller\Data;

#region IMPORTS
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
use SpaLiveV1\Controller\CourseController;
use SpaLiveV1\Controller\ProviderProfileController;
use SpaLiveV1\Controller\FillersController;
use SpaLiveV1\Controller\Data\ProfileAttributes;

#endregion

class ServicesHelper {
    #region PROPERTIES
    public $SHD;
    public $user_id;

    public function __construct($user_id)
    {
        $this->SHD      = new ServicesHelperData();        
        $this->user_id  = $user_id;
    }

    public function service_status(
        $service
    ){
        return $this->SHD->register_step_service(
            $service,
            $this->user_id
        );
    }

    public function applied_fillers(){
        return !empty($this->SHD->get_filler_application($this->user_id));
    }
}

class ServicesHelperData extends AppPluginController
{
    private $allowed_license_types = ['RN', 'NP', 'PA', 'MD'];
    public $steps_levels = [
        // 0   MEANS THAT THEY DIDNT START THE PROCESS FOR THIS SERVICE
        "NOT_STARTED"              => 0,
        // 1   MEANS THAT THERE WAS AN ERROR WITH THE PROCESS, SO ITS THE SECOND LOWEST IN THE LIST
        "LICENSE_DENIED"           => 1,
        // 2   SAME AS 1, BUT IT HAS A MORE RELEVANT MEANING
        "CERTIFICATE_DENIED"       => 2,
        "REJECTED"                 => 2,
        // 3   MEANS THAT THEY NEED A REQUIREMENT TO CONTINUE
        "UPLOAD_LICENSE"           => 3,
        "JOIN"                     => 3,
        // 4   MEANS THAT THEY ARE IN THE PROCESS OF GETTING THE REQUIREMENT
        "STUDYING"                 => 4,
        "SUBMIT_CODE"              => 4,//fix error server error
        "WAITING_APPROVAL"         => 4,
        // 5   MEANS THAT THEY ARE WAITING FOR THE APPROVAL OF THE REQUIREMENT, BUT WITH MORE PROGRESS
        "WAITING_LICENSE_APPROVAL" => 5,
        // 6-7 MEANS THAT THEY NEED A SUBSCRIPTION TO CONTINUE
        "MSL"                      => 6,
        "MD"                       => 7,
        // 8   FINAL STEP        
        "DONE"                     => 8,
    ];

    public function __construct()
    {
        $this->loadModel('SpaLiveV1.SysLicences');
        $this->loadModel("SpaLiveV1.DataCourses");
    }

    public function register_step_service(
        $service,
        $user_id 
    ){

        
        $step = '';
        switch($service){
            case 'NEUROTOXINS':
                $step = $this->register_step_neurotoxins($user_id);          break;
            case 'BASIC NEUROTOXINS':
                $step = $this->register_step_neurotoxins_basic($user_id);    break;
            case 'ADVANCED NEUROTOXINS':
                $step = $this->register_step_neurotoxins_advanced($user_id); break;
            case 'ADVANCED TECHNIQUES NEUROTOXINS':
                $step = $this->register_step_neurotoxins_advanced_techniques($user_id); break;
            case 'IV THERAPY':
                $step = $this->register_step_iv_therapy($user_id);           break;
            case 'FILLERS':
                $step = $this->register_step_fillers($user_id);              break;
            default;
                $step = $this->get_step_other_treatments($service, $user_id);
                break;
        }
 
        return $step != '' 
            ? $step 
            : $this->get_status_for_subscriptions(
                $user_id, 
                $service,            
            );
    }

    public function register_step_neurotoxins(
        $user_id
    ){                        
        $step_basic    = $this->register_step_neurotoxins_basic($user_id);
        $step_advanced = $this->register_step_neurotoxins_advanced($user_id);
        $step_advanced_techniques = $this->register_step_neurotoxins_advanced_techniques($user_id);

        if($step_basic == '' || $step_advanced == '' || $step_advanced_techniques == ''){
            return '';
        }

        $basic_points    = $this->steps_levels[$step_basic];
        $advanced_points = $this->steps_levels[$step_advanced];
        $advanced_techniques_points = $this->steps_levels[$step_advanced_techniques];

        if($basic_points > $advanced_points && $basic_points > $advanced_techniques_points){
            return $step_basic;
        }else if($advanced_points > $basic_points && $advanced_points > $advanced_techniques_points){
            return $step_advanced;
        }else if($advanced_techniques_points > $basic_points && $advanced_techniques_points > $advanced_points){
            return $step_advanced_techniques;
        }

    }

    public function register_step_neurotoxins_basic(
        $user_id
    ){
        $Course = new CourseController();
        
        $status_for_purchase = 'NOT_STARTED';
        $status_for_schools  = 'NOT_STARTED';

        $training = $Course->get_training_for_services($user_id, 'LEVEL 1');
        $purchased      = $this->has_purchased_neurotoxins($user_id, 'BASIC COURSE');
        // THIS WILL COVER THE CASE WHEN A TESTER HAS A DELETED PURCHASE OR THE THE COURSE WAS A GIFT
        if(!empty($training)){
            
            $now = date('Y-m-d');
            $basic_training_status = "";
            
            if(date('Y-m-d', strtotime($training["CatTrainigs"]["scheduled"])) > $now){
                $status_for_purchase = 'STUDYING';                
            }else{
                if($training->attended == 1){
                    $status_for_purchase = ''; // THIS WILL BE CHECKED IN THE register_step_service FOR THE SUBSCRIPTION
                }else{
                    $status_for_purchase = 'SUBMIT_CODE'; // THIS WILL BE CHECKED IN THE register_step_service FOR THE SUBSCRIPTION
                }
            }
        } else if($purchased){           
            //GET TRAINING
            if(!empty($training)){

                $now = date('Y-m-d');
                $basic_training_status = "";

                if(date('Y-m-d', strtotime($training["CatTrainigs"]["scheduled"])) > $now){
                    $status_for_purchase = 'STUDYING';
                }else{
                    if($training->attended == 1){
                        $status_for_purchase = ''; // THIS WILL BE CHECKED IN THE register_step_service FOR THE SUBSCRIPTION
                    }else{
                        $status_for_purchase = 'SUBMIT_CODE'; // THIS WILL BE CHECKED IN THE register_step_service FOR THE SUBSCRIPTION
                    }
                }

            }else{
                $status_for_purchase = 'JOIN';
            }
        }

        //SCHOOLS
        $course = $Course->get_course_schools_for_services($user_id, array('NEUROTOXINS BASIC', 'BOTH NEUROTOXINS'));
        if(!empty($course)){
            if($course->status == 'DONE'){

                $license = $this->get_status_for_license($user_id);

                if($license == 'APPROVED_LICENSE'){
                    $status_for_schools = ''; // THIS WILL BE CHECKED IN THE register_step_service FOR THE SUBSCRIPTION
                }else if($license == 'LICENSE_DENIED'){
                    $status_for_schools = 'LICENSE_DENIED';
                }else{
                    $status_for_schools = 'WAITING_LICENSE_APPROVAL';
                }

            }else if($course->status == 'PENDING'){
                $status_for_schools = 'WAITING_APPROVAL';
            }else if($course->status == 'REJECTED'){
                $status_for_schools = 'REJECTED';
            }
        }

        // other treatments basic neurotoxins
        $this->loadModel('SpaLiveV1.DataTrainings');
        $training_other = $this->DataTrainings->find()
        ->select(['id' => 'CatTrainings.id', 'level' => 'CatTrainings.level', 'attended' => 'DataTrainings.attended'])
        ->join([
            'CatTrainings' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainings.id = DataTrainings.training_id'],
            'CTC' => ['table' => 'cat_courses_type', 'type' => 'INNER', 'conditions' => 'CTC.name_key = CatTrainings.level'],
            'DCC' => ['table' => 'data_coverage_courses', 'type' => 'INNER', 'conditions' => 'DCC.course_type_id = CTC.id'],
            'STOT' => ['table' => 'sys_treatments_ot', 'type' => 'INNER', 'conditions' => 'STOT.id = DCC.ot_id'],
        ])
        ->where(['DataTrainings.user_id' => $user_id,'DataTrainings.deleted' => 0,'DataTrainings.attended' => 1,'CatTrainings.deleted' => 0,'STOT.name_key' => 'NEUROTOXINS'])
        ->first();

        if(!empty($training_other)){
            if($training_other->attended == 1){
                $status_for_schools = '';
            }else{
                $status_for_schools = 'SUBMIT_CODE';
            }
        }
        
        if($status_for_schools == '' || $status_for_purchase == ''){
            return '';
        }

        $purchase_points = $this->steps_levels[$status_for_purchase];
        $schools_points  = $this->steps_levels[$status_for_schools];

        return $purchase_points > $schools_points
            ? $status_for_purchase     
            : $status_for_schools;
    }

    public function register_step_neurotoxins_advanced(
        $user_id
    ){
        
        $Course = new CourseController();
        
        $status_for_purchase = 'NOT_STARTED';
        $status_for_schools  = 'NOT_STARTED';

        $training = $Course->get_training_for_services($user_id, 'LEVEL 2');
        $purchased      = $this->has_purchased_neurotoxins($user_id, 'ADVANCED COURSE');
        // THIS WILL COVER THE CASE WHEN A TESTER HAS A DELETED PURCHASE OR THE THE COURSE WAS A GIFT
        if(!empty($training)){
            
            $now = date('Y-m-d');
            $basic_training_status = "";
            
            if(date('Y-m-d', strtotime($training["CatTrainigs"]["scheduled"])) > $now){
                $status_for_purchase = 'STUDYING';                
            }else{
                if($training->attended == 1){
                    $status_for_purchase = ''; // THIS WILL BE CHECKED IN THE register_step_service FOR THE SUBSCRIPTION
                }else{
                    $status_for_purchase = 'SUBMIT_CODE'; // THIS WILL BE CHECKED IN THE register_step_service FOR THE SUBSCRIPTION
                }
            }
        } else if($purchased){           
            //GET TRAINING
            if(!empty($training)){

                $now = date('Y-m-d');
                $basic_training_status = "";

                if(date('Y-m-d', strtotime($training["CatTrainigs"]["scheduled"])) > $now){
                    $status_for_purchase = 'STUDYING';
                }else{
                    if($training->attended == 1){
                        $status_for_purchase = ''; // THIS WILL BE CHECKED IN THE register_step_service FOR THE SUBSCRIPTION
                    }else{
                        $status_for_purchase = 'SUBMIT_CODE'; // THIS WILL BE CHECKED IN THE register_step_service FOR THE SUBSCRIPTION
                    }
                }

            }else{
                $status_for_purchase = 'JOIN';
            }
        }

        //SCHOOLS
        $course = $Course->get_course_schools_for_services($user_id, array('NEUROTOXINS ADVANCED', 'BOTH NEUROTOXINS'));
        if(!empty($course)){
            if($course->status == 'DONE'){

                $license = $this->get_status_for_license($user_id);

                if($license == 'APPROVED_LICENSE'){
                    $status_for_schools = ''; // THIS WILL BE CHECKED IN THE register_step_service FOR THE SUBSCRIPTION
                }else if($license == 'LICENSE_DENIED'){
                    $status_for_schools = 'LICENSE_DENIED';
                }else{
                    $status_for_schools = 'WAITING_LICENSE_APPROVAL';
                }

            }else if($course->status == 'PENDING'){
                $status_for_schools = 'WAITING_APPROVAL';
            }else if($course->status == 'REJECTED'){
                $status_for_schools = 'REJECTED';
            }
        }

        // other treatments advanced neurotoxins
        $this->loadModel('SpaLiveV1.DataTrainings');
        $training_other = $this->DataTrainings->find()
        ->select(['id' => 'CatTrainings.id', 'level' => 'CatTrainings.level', 'attended' => 'DataTrainings.attended'])
        ->join([
            'CatTrainings' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainings.id = DataTrainings.training_id'],
            'CTC' => ['table' => 'cat_courses_type', 'type' => 'INNER', 'conditions' => 'CTC.name_key = CatTrainings.level'],
            'DCC' => ['table' => 'data_coverage_courses', 'type' => 'INNER', 'conditions' => 'DCC.course_type_id = CTC.id'],
            'STOT' => ['table' => 'sys_treatments_ot', 'type' => 'INNER', 'conditions' => 'STOT.id = DCC.ot_id'],
        ])
        ->where(['DataTrainings.user_id' => $user_id,'DataTrainings.deleted' => 0,'DataTrainings.attended' => 1,'CatTrainings.deleted' => 0,'STOT.name_key' => 'ADVANCED_NEUROTOXINS'])
        ->first();

        if(!empty($training_other)){
            if($training_other->attended == 1){
                $status_for_schools = '';
            }else{
                $status_for_schools = 'SUBMIT_CODE';
            }
        }


        
        if($status_for_schools == '' || $status_for_purchase == ''){
            return '';
        }

        $purchase_points = $this->steps_levels[$status_for_purchase];
        $schools_points  = $this->steps_levels[$status_for_schools];

        return $purchase_points > $schools_points
            ? $status_for_purchase     
            : $status_for_schools;
    }

    public function register_step_neurotoxins_advanced_techniques(
        $user_id
    ){
        
        $Course = new CourseController();
        
        $status_for_purchase = 'NOT_STARTED';
        $status_for_schools  = 'NOT_STARTED';

        $training = $Course->get_training_for_services($user_id, 'LEVEL 3');
        $purchased      = $this->has_purchased_neurotoxins($user_id, 'ADVANCED TECHNIQUES COURSE');
        // THIS WILL COVER THE CASE WHEN A TESTER HAS A DELETED PURCHASE OR THE THE COURSE WAS A GIFT
        if(!empty($training)){
            
            $now = date('Y-m-d');
            $basic_training_status = "";
            
            if(date('Y-m-d', strtotime($training["CatTrainigs"]["scheduled"])) > $now){
                $status_for_purchase = 'STUDYING';                
            }else{
                if($training->attended == 1){
                    $status_for_purchase = ''; // THIS WILL BE CHECKED IN THE register_step_service FOR THE SUBSCRIPTION
                }else{
                    $status_for_purchase = 'SUBMIT_CODE'; // THIS WILL BE CHECKED IN THE register_step_service FOR THE SUBSCRIPTION
                }
            }
        } else if($purchased){           
            //GET TRAINING
            if(!empty($training)){

                $now = date('Y-m-d');
                $basic_training_status = "";

                if(date('Y-m-d', strtotime($training["CatTrainigs"]["scheduled"])) > $now){
                    $status_for_purchase = 'STUDYING';
                }else{
                    if($training->attended == 1){
                        $status_for_purchase = ''; // THIS WILL BE CHECKED IN THE register_step_service FOR THE SUBSCRIPTION
                    }else{
                        $status_for_purchase = 'SUBMIT_CODE'; // THIS WILL BE CHECKED IN THE register_step_service FOR THE SUBSCRIPTION
                    }
                }

            }else{
                $status_for_purchase = 'JOIN';
            }
        }

        // other treatments advanced neurotoxins
        $this->loadModel('SpaLiveV1.DataTrainings');
        $training_other = $this->DataTrainings->find()
        ->select(['id' => 'CatTrainings.id', 'level' => 'CatTrainings.level', 'attended' => 'DataTrainings.attended'])
        ->join([
            'CatTrainings' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainings.id = DataTrainings.training_id'],
            'CTC' => ['table' => 'cat_courses_type', 'type' => 'INNER', 'conditions' => 'CTC.name_key = CatTrainings.level'],
            'DCC' => ['table' => 'data_coverage_courses', 'type' => 'INNER', 'conditions' => 'DCC.course_type_id = CTC.id'],
            'STOT' => ['table' => 'sys_treatments_ot', 'type' => 'INNER', 'conditions' => 'STOT.id = DCC.ot_id'],
        ])
        ->where(['DataTrainings.user_id' => $user_id,'DataTrainings.deleted' => 0,'DataTrainings.attended' => 1,'CatTrainings.deleted' => 0,'STOT.name_key' => 'LEVEL3_NEUROTOXINS'])
        ->first();

        if(!empty($training_other)){
            if($training_other->attended == 1){
                $status_for_purchase = '';
            }else{
                $status_for_purchase = 'SUBMIT_CODE';
            }
        }


        //SCHOOLS
        /*$course = $Course->get_course_schools_for_services($user_id, array('NEUROTOXINS ADVANCED', 'BOTH NEUROTOXINS'));
        if(!empty($course)){
            if($course->status == 'DONE'){

                $license = $this->get_status_for_license($user_id);

                if($license == 'APPROVED_LICENSE'){
                    $status_for_schools = ''; // THIS WILL BE CHECKED IN THE register_step_service FOR THE SUBSCRIPTION
                }else if($license == 'LICENSE_DENIED'){
                    $status_for_schools = 'LICENSE_DENIED';
                }else{
                    $status_for_schools = 'WAITING_LICENSE_APPROVAL';
                }

            }else if($course->status == 'PENDING'){
                $status_for_schools = 'WAITING_APPROVAL';
            }else if($course->status == 'REJECTED'){
                $status_for_schools = 'REJECTED';
            }
        }*/
        
        if(/*$status_for_schools == '' ||*/ $status_for_purchase == ''){
            return '';
        }

        $purchase_points = $this->steps_levels[$status_for_purchase];
        //$schools_points  = $this->steps_levels[$status_for_schools];

        /*return $purchase_points > $schools_points
            ? $status_for_purchase     
            : $status_for_schools;*/

        return $status_for_purchase;
    }

    #region IV THERAPY

    public function register_step_iv_therapy(
        $user_id
    ){
        $Therapy = new TherapyController();

        $iv_request = $Therapy->previous_therapist_request_check($user_id);
        $has_iv_request = !empty($iv_request);
        
        if($has_iv_request){
            $status = $Therapy->request_status($iv_request);
            if($status == 'ACCEPTED'){
                $status_license = $this->get_status_for_license($user_id);
                if($status_license == "APPROVED_LICENSE"){
                    return ''; // THIS WILL BE CHECKED IN THE register_step_service FOR THE SUBSCRIPTION
                }else{
                    return $status_license;
                }
            }else if($status == 'PENDING'){                
                return 'WAITING_APPROVAL';
            } else {
                return 'REJECTED';
            }
        }
        
        return 'NOT_STARTED';
    }

    #endregion

    #region FILLERS

    public function get_filler_application(
        $user_id
    ){
        $fillers = $this->DataCourses->find()
            ->join([
                'table' => 'cat_courses',
                'alias' => 'CatCourses',
                'type'  => 'INNER',
                'conditions' => [
                    'CatCourses.id = DataCourses.course_id'
                ]
            ])
            ->where([
                'DataCourses.user_id' => $user_id,
                'CatCourses.type'     => 'FILLERS',
                'DataCourses.deleted' => 0
            ])
            ->first();

        return $fillers;
    }    

    public function register_step_fillers(
        $user_id
    ){
        $status_from_schools = '';     

        $fillers = $this->get_filler_application($user_id);
        $has_fillers = !empty($fillers);
        
        if($has_fillers){
            $request_status = $fillers['status'];
            if($request_status == "DONE"){
                $status_license = $this->get_status_for_license($user_id);

                if($status_license == "APPROVED_LICENSE"){
                    $status_from_schools = ''; // THIS WILL BE CHECKED IN THE register_step_service FOR THE SUBSCRIPTION
                }else{
                    $status_from_schools = $status_license;
                }
            }else if($request_status == "PENDING"){
                $status_from_schools = 'WAITING_APPROVAL';
            }else if($request_status == "REJECTED"){
                $status_from_schools = 'CERTIFICATE_DENIED';
            }
        }else{            
            //check buy course
            $status_for_purchase = 'NOT_STARTED';
            $Course = new CourseController();

            $training = $Course->get_training_for_services($user_id, 'LEVEL 3 FILLERS');
            $purchased      = $this->has_purchased_neurotoxins($user_id, 'FILLERS COURSE');
            if(!empty($training)){
            
                $now = date('Y-m-d');                
                
                if(date('Y-m-d', strtotime($training["CatTrainigs"]["scheduled"])) > $now){
                    $status_for_purchase = 'STUDYING';                
                }else{
                    if($training->attended == 1){
                        $status_for_purchase = ''; 
                        // THIS WILL BE CHECKED IN THE register_step_service FOR THE SUBSCRIPTION
                    }else{
                        $status_for_purchase = 'SUBMIT_CODE'; 
                        // THIS WILL BE CHECKED IN THE register_step_service FOR THE SUBSCRIPTION
                    }
                }
            } else if($purchased){           
                //GET TRAINING
                if(!empty($training)){
    
                    $now = date('Y-m-d');                    
    
                    if(date('Y-m-d', strtotime($training["CatTrainigs"]["scheduled"])) > $now){
                        $status_for_purchase = 'STUDYING';
                    }else{
                        if($training->attended == 1){
                            $status_for_purchase = ''; // THIS WILL BE CHECKED IN THE register_step_service FOR THE SUBSCRIPTION
                        }else{
                            $status_for_purchase = 'SUBMIT_CODE'; // THIS WILL BE CHECKED IN THE register_step_service FOR THE SUBSCRIPTION
                        }
                    }
    
                }else{
                    $status_for_purchase = 'JOIN';
                }
            }                
            
            // other treatments fillers
            $this->loadModel('SpaLiveV1.DataTrainings');
            $training_other = $this->DataTrainings->find()
            ->select(['id' => 'CatTrainings.id', 'level' => 'CatTrainings.level', 'attended' => 'DataTrainings.attended'])
            ->join([
                'CatTrainings' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainings.id = DataTrainings.training_id'],
                'CTC' => ['table' => 'cat_courses_type', 'type' => 'INNER', 'conditions' => 'CTC.name_key = CatTrainings.level'],
                'DCC' => ['table' => 'data_coverage_courses', 'type' => 'INNER', 'conditions' => 'DCC.course_type_id = CTC.id'],
                'STOT' => ['table' => 'sys_treatments_ot', 'type' => 'INNER', 'conditions' => 'STOT.id = DCC.ot_id'],
            ])
            ->where(['DataTrainings.user_id' => $user_id,'DataTrainings.deleted' => 0,'DataTrainings.attended' => 1,'CatTrainings.deleted' => 0,'STOT.name_key' => 'FILLERS'])
            ->first();

            if(!empty($training_other)){
                if($training_other->attended == 1){
                    $this->loadModel('SpaLiveV1.DataSubscriptions');
                    $subscription_msl = $this->DataSubscriptions->find()
                    ->where([
                        'DataSubscriptions.user_id' => $user_id, 
                        'DataSubscriptions.deleted' => 0, 
                        'DataSubscriptions.status' => 'ACTIVE', 
                        'DataSubscriptions.subscription_type LIKE' => '%MSL%',
                        'OR' => [
                            ['DataSubscriptions.main_service LIKE' => '%FILLERS%'],
                            ['DataSubscriptions.addons_services LIKE' => '%FILLERS%']
                        ]
                    ])
                    ->first();

                    if(empty($subscription_msl)){
                        return 'MSL';
                    }

                    $subscription_md = $this->DataSubscriptions->find()
                    ->where([
                        'DataSubscriptions.user_id' => $user_id, 
                        'DataSubscriptions.deleted' => 0, 
                        'DataSubscriptions.status' => 'ACTIVE', 
                        'DataSubscriptions.subscription_type LIKE' => '%MD%',
                        'OR' => [
                            ['DataSubscriptions.main_service LIKE' => '%FILLERS%'],
                            ['DataSubscriptions.addons_services LIKE' => '%FILLERS%']
                        ]
                    ])
                    ->first();

                    if(empty($subscription_md)){
                        return 'MD';
                    }

                    return 'DONE';
                }else{
                    $status_for_purchase = 'SUBMIT_CODE';
                }
            }

            if($status_for_purchase == ''){
                return $status_from_schools;
            }
    
            $status_from_schools = $status_for_purchase;//$this->steps_levels[$status_for_purchase];
        }


        return $status_from_schools;
    }

    #endregion

    #region OTHER TREATMENTS

    public function get_step_other_treatments(
        $service,
        $user_id
    ){
        $this->loadModel('SpaLiveV1.SysTreatmentsOt');
        $this->loadModel('SpaLiveV1.DataTrainings');
        $this->loadModel('SpaLiveV1.DataCourses');
        $this->loadModel('SpaLiveV1.DataSubscriptions');
        
        // Validar que $service no sea null o vacío
        if(empty($service)){
            return 'NOT_STARTED';
        }
        
        $treatment = $this->SysTreatmentsOt->find()
            ->where(['SysTreatmentsOt.name_key' => $service])
        ->first();

        if(empty($treatment)){
            return 'NOT_STARTED';
        }

        $data_trainings = $this->DataTrainings->find()
            ->select([
                    'training_id' => 'DataTrainings.training_id', 
                    'course_type_id' => 'CTC.id',
                    'attended' => 'DataTrainings.attended',
                    'scheduled' => 'CT.scheduled'
                ])
            ->join([
                'CT' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CT.id = DataTrainings.training_id'],
                'CTC' => ['table' => 'cat_courses_type', 'type' => 'INNER', 'conditions' => 'CTC.name_key = CT.level'],
                'Coverage' => ['table' => 'data_coverage_courses', 'type' => 'INNER', 'conditions' => 'Coverage.course_type_id = CTC.id'],
            ])
            ->where(['DataTrainings.user_id' => $user_id, 'DataTrainings.deleted' => 0, 'CT.deleted' => 0, 'Coverage.ot_id' => $treatment->id])
        ->first();

        if(empty($data_trainings)){
            $data_courses = $this->DataCourses->find()
            ->select(['id' => 'STOT.id', 'status' => 'DataCourses.status'])
            ->join([
                'CatCourses' => ['table' => 'cat_courses', 'type' => 'INNER', 'conditions' => 'CatCourses.id = DataCourses.course_id'],
                'CSOC' => ['table' => 'cat_school_option_cert', 'type' => 'INNER', 'conditions' => 'CSOC.id = CatCourses.school_option_id'],
                'STOT' => ['table' => 'sys_treatments_ot', 'type' => 'INNER', 'conditions' => 'STOT.id = CSOC.sys_treatment_ot_id'],
            ])
            ->where(['DataCourses.user_id' => $user_id, 'DataCourses.deleted' => 0, 'STOT.id' => $treatment->id])
            ->first();

            if(empty($data_courses)){
                return 'NOT_STARTED';
            }

            if($data_courses->status == 'DONE'){
                $subscription_msl = $this->DataSubscriptions->find()
                ->where([
                    'DataSubscriptions.user_id' => $user_id, 
                    'DataSubscriptions.deleted' => 0, 
                    'DataSubscriptions.status' => 'ACTIVE', 
                    'DataSubscriptions.subscription_type LIKE' => '%MSL%',
                    'OR' => [
                        ['DataSubscriptions.main_service LIKE' => '%' . $treatment->name_key . '%'],
                        ['DataSubscriptions.addons_services LIKE' => '%' . $treatment->name_key . '%']
                    ]
                ])
                ->first();

                if(empty($subscription_msl)){
                    return 'MSL';
                }

                if($treatment->require_mdsub == 1){
                    $subscription_md = $this->DataSubscriptions->find()
                    ->where([
                        'DataSubscriptions.user_id' => $user_id, 
                        'DataSubscriptions.deleted' => 0, 
                        'DataSubscriptions.status' => 'ACTIVE', 
                        'DataSubscriptions.subscription_type LIKE' => '%MD%',
                        'OR' => [
                            ['DataSubscriptions.main_service LIKE' => '%' . $treatment->name_key . '%'],
                            ['DataSubscriptions.addons_services LIKE' => '%' . $treatment->name_key . '%']
                        ]
                    ])
                    ->first();

                    if(empty($subscription_md)){
                        return 'MD';
                    }
                }

                return 'DONE';
            }else{
                return 'WAITING_APPROVAL';
            }
        }

        if($data_trainings->attended == 1){
            $subscription_msl = $this->DataSubscriptions->find()
            ->where([
                'DataSubscriptions.user_id' => $user_id, 
                'DataSubscriptions.deleted' => 0, 
                'DataSubscriptions.status' => 'ACTIVE', 
                'DataSubscriptions.subscription_type LIKE' => '%MSL%',
                'OR' => [
                    ['DataSubscriptions.main_service LIKE' => '%' . $treatment->name_key . '%'],
                    ['DataSubscriptions.addons_services LIKE' => '%' . $treatment->name_key . '%']
                ]
            ])
            ->first();

            if(empty($subscription_msl)){
                return 'MSL';
            }

            if($treatment->require_mdsub == 1){
                $subscription_md = $this->DataSubscriptions->find()
                ->where([
                    'DataSubscriptions.user_id' => $user_id, 
                    'DataSubscriptions.deleted' => 0, 
                    'DataSubscriptions.status' => 'ACTIVE', 
                    'DataSubscriptions.subscription_type LIKE' => '%MD%',
                    'OR' => [
                        ['DataSubscriptions.main_service LIKE' => '%' . $treatment->name_key . '%'],
                        ['DataSubscriptions.addons_services LIKE' => '%' . $treatment->name_key . '%']
                    ]
                ])
                ->first();

                if(empty($subscription_md)){
                    return 'MD';
                }
            }

            return 'DONE';
        }else{
            $now = date('Y-m-d');
            if(date('Y-m-d', strtotime($data_trainings->scheduled)) > $now){
                return 'STUDYING';
            }else{
                return 'SUBMIT_CODE';
            }
        }
    }

    #endregion

    public function get_status_for_subscriptions(
        $user_id,
        $service
    ){
        $Subscription = new SubscriptionController();

        $has_msl = $Subscription->has_service_subscription($user_id, $service, 'MSL');
        // $has_md  = $Subscription->has_service_subscription($user_id, $service, 'MD');


        if(!$has_msl){
            return 'SUBSCRIBE';
        }

        // if(!$has_md){
        //     return 'SUBSCRIBE';
        // }
        
        return 'DONE';
    }

    public function get_status_for_license(
        $user_id
    ){    
        $licenses = $this->SysLicences->find()
            ->where([
                'SysLicences.user_id' => $user_id,
                'SysLicences.deleted' => 0,
                //'SysLicences.type IN' => $this->allowed_license_types
            ])
            ->all();
        
        if(empty($licenses)){
            return "UPLOAD_LICENSE";
        }

        $licenses_scored = []; 
        foreach($licenses as $license){
            if($license->status == 'APPROVED'){
                $licenses_scored[] = 2;
            }else if($license->status == 'PENDING'){
                $licenses_scored[] = 1;
            }else{
                $licenses_scored[] = 0;
            }
        }

        if(empty($licenses_scored)){
            return "UPLOAD_LICENSE";
        }

        $max_score = max($licenses_scored);
        switch($max_score){
            case 0:
                return 'LICENSE_DENIED';
            case 1:
                return 'WAITING_LICENSE_APPROVAL';
            case 2:
                return 'APPROVED_LICENSE';
        }
    }

    public function has_purchased_neurotoxins($user_id, $training){
        $this->loadModel('SpaLiveV1.DataPayment');
        $payment = $this->DataPayment->find()
            ->where(['DataPayment.id_from' => $user_id, 'DataPayment.id_to' => 0,'DataPayment.type' => $training, 
                 'DataPayment.service_uid' => '','DataPayment.payment <>' => '', 'DataPayment.is_visible' => 1])->first();
        
        if(!empty($payment)){
            return true;
        }else{
            return false;
        }
    }
}
