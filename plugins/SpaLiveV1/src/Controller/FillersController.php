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

use SpaLiveV1\Controller\MainController;
use Cake\I18n\FrozenTime;

/*

⚠️⚠️⚠️ PAY ATTENTION TO THE COMMENTS IN THE CODE ⚠️⚠️⚠️

Please, if you want to modify this file, take a look to all the code first 
in order to understand what is happening and what is the purpose of each function.

Otherwise, you will probably break the code and the app will not work properly, in result 
i'm going to have to fix it and it will take me more time.

My best regards, Alexis!!! OKAY, SIS???????? 

⚠️⚠️⚠️ PAY ATTENTION TO THE COMMENTS IN THE CODE ⚠️⚠️⚠️

*/

class FillersController extends AppPluginController {

    private $allowed_license_types = ['RN', 'NP', 'PA', 'MD'];

    #region VARIABLES

	public function initialize() : void {
        parent::initialize();
		$this->loadModel('SpaLiveV1.AppToken');
		$this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataPurchases');
		$this->loadModel('SpaLiveV1.DataPayment');
		$this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.CatStates');                
    }

    #endregion

    #region ENDPOINTS

    public function test_n(){
        $this->success();
    }

    public function get_diseases(){
        if(!$this->validate_session()) return;
        $this->loadModel('SpaLiveV1.CatDiseases');  
        
        $diseases = $this->CatDiseases
            ->find()
            ->where([
                'deleted' => 0,
                'disease IN' => ['Hipertension', 'Angina', 'Ankle swelling']
            ])
            ->all();
        
        $this->set('data', $diseases);
        $this->success();        
    }

    #endregion

    #region FUNCTIONS 

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

    public function get_body_areas_fillers(){
        $this->loadModel("SpaLiveV1.CatBodyAreas");

        $_where = [];
        $_where['CatBodyAreas.deleted'] = 0;
        $_where['CatBodyAreas.treatment_type'] = 'FILLERS';

        $body_areas = $this->CatBodyAreas
            ->find()
            ->select(['CatBodyAreas.id', 'CatBodyAreas.name'])
            ->where($_where)
            ->order(['id' => 'ASC'])
            ->all();

        return $body_areas;
    }

    public function get_body_areas(
        $area_ids = ''
    ){
        $this->loadModel("SpaLiveV1.CatBodyAreas");

        $_where = [];
        $_where['CatBodyAreas.deleted'] = 0;

        if(!empty($area_ids)){
            $_where['CatBodyAreas.id IN'] = explode(',', $area_ids);
        }

        $body_areas = $this->CatBodyAreas
            ->find()
            ->select(['CatBodyAreas.id', 'CatBodyAreas.name'])
            ->where($_where)
            ->order(['id' => 'ASC'])
            ->all();

        return $body_areas;
    }

    public function get_body_areas_injector(
        $user_id
    ){
        $this->loadModel("SpaLiveV1.DataBodyAreas");

        $_where = [];        
        $_where['DataBodyAreas.user_id'] = $user_id;
        $_where['DataBodyAreas.treatment_type'] = 'FILLERS';

        $ent_body_areas = $this->DataBodyAreas
            ->find()
            ->select(['DataBodyAreas.body_areas'])
            ->where($_where)
            ->order(['id' => 'DESC'])
            ->first();

        $areas = array();

        if(!empty($ent_body_areas)){
            $body_areas = $this->get_body_areas($ent_body_areas->body_areas);
            if(!empty($body_areas)){
                $areas['ids'] = $ent_body_areas->body_areas;
                $areas['body_areas'] = $body_areas;     
                return $areas;           
            }
        }
        
        $areas['ids'] = array();
        $areas['body_areas'] = array();
        
        return $areas;
    }

    public function has_fillers_certificate($user_id){
        $this->loadModel("SpaLiveV1.DataCourses");

        $has_certificate = false;

        $_where = [];
        $_where['DataCourses.user_id'] = $user_id;
        $_where['CatCourses.type']    = 'FILLERS';
        $_where['DataCourses.status']  = 'DONE';
        $_where['DataCourses.deleted'] = 0;

        $certificate = $this->DataCourses
            ->find()
            ->join([
                "CatCourses" => [
                    "table" => "cat_courses",
                    "type" => "INNER",
                    "conditions" => "CatCourses.id = DataCourses.course_id"
                ]
            ])
            ->select(['DataCourses.id'])
            ->where($_where)
            ->order(['DataCourses.id' => 'DESC'])
            ->first();

        if(!empty($certificate)){
            $has_certificate = true;
        }else{
            $this->loadModel("SpaLiveV1.DataTrainings");
            $user_course_basic = $this->DataTrainings->find()
                ->join([
                    'CatTrainings' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainings.id = DataTrainings.training_id'],
                    'CTC' => ['table' => 'cat_courses_type', 'type' => 'INNER', 'conditions' => 'CTC.name_key = CatTrainings.level'],
                    'DCC' => ['table' => 'data_coverage_courses', 'type' => 'INNER', 'conditions' => 'DCC.course_type_id = CTC.id'],
                    'STOT' => ['table' => 'sys_treatments_ot', 'type' => 'INNER', 'conditions' => 'STOT.id = DCC.ot_id'],
                ])
                ->where(['DataTrainings.user_id' => $user_id,'DataTrainings.deleted' => 0,'DataTrainings.attended' => 1,'CatTrainings.deleted' => 0,'STOT.name_key' => 'FILLERS'])
                ->first();

                if(!empty($user_course_basic)){
                    $has_certificate = true;
                }
        }

        return $has_certificate;
    }

   public function valid_step_application(
        $user_id
    ){
        $this->loadModel("SpaLiveV1.DataCourses");
        $this->loadModel("SpaLiveV1.DataTrainings");

        $_where = [];
        $_where['CatCourses.type']       = 'FILLERS';
        $_where['DataCourses.user_id']   = $user_id;
        $_where['DataCourses.status in'] = array('PENDING', 'DONE');
        $_where['DataCourses.deleted']   = 0;

        $application = $this->DataCourses
            ->find()
            ->join([
                "CatCourses" => [
                    "table" => "cat_courses",
                    "type" => "INNER",
                    "conditions" => "CatCourses.id = DataCourses.course_id"
                ]
            ])
            ->select(['DataCourses.id'])
            ->where($_where)
            ->order(['DataCourses.id' => 'DESC'])
            ->first();
        if (empty($aplication)) {

            $_where = [];
            $_where['CatTrainings.level']       = 'LEVEL 3 FILLERS';
            $_where['DataTrainings.user_id']   = $user_id;
            $_where['DataTrainings.deleted']   = 0;
            $application = $this->DataTrainings
            ->find()
            ->join([
                "CatTrainings" => [
                    "table" => "cat_trainings",
                    "type" => "INNER",
                    "conditions" => "CatTrainings.id = DataTrainings.training_id"
                ]
            ])
            ->select(['DataTrainings.id'])
            ->where($_where)
            ->order(['DataTrainings.id' => 'DESC'])
            ->first();

            if (!empty($application)) {
                return true;
            } return false;
        } else return true;
        
    }
   #endregion
}