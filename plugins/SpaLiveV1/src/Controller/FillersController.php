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

    /** `cat_body_areas.id` for lips (FILLER_COURSE_LEVEL_1 CI catalog only). */
    private const FILLER_COURSE_LEVEL1_BODY_AREA_ID = 2;

    /** `cat_ci_treatments.name` / exam display names allowed for FILLER_COURSE_LEVEL_1 (case-insensitive). */
    private const FILLER_COURSE_LEVEL1_CI_TREATMENT_NAMES = [
        'Revanesse Lips + with lidocaine',
        'Juvederm Ultra',
        'Juvederm Volbella',
    ];

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
            } else {
                $level3Fillers = $this->DataTrainings->find()
                    ->join([
                        'CatTrainings' => [
                            'table' => 'cat_trainings',
                            'type' => 'INNER',
                            'conditions' => 'CatTrainings.id = DataTrainings.training_id',
                        ],
                    ])
                    ->select(['DataTrainings.id'])
                    ->where([
                        'DataTrainings.user_id' => $user_id,
                        'DataTrainings.deleted' => 0,
                        'DataTrainings.attended' => 1,
                        'CatTrainings.deleted' => 0,
                        'CatTrainings.level IN' => ['LEVEL 3 FILLERS', 'FILLER_COURSE_LEVEL_1', 'FILLER_COURSE_LEVEL_2'],
                    ])
                    ->order(['DataTrainings.id' => 'DESC'])
                    ->first();

                if (!empty($level3Fillers)) {
                    $has_certificate = true;
                }
            }
        }

        return $has_certificate;
    }

    /**
     * Restricted CI fillers catalog: only users whose only attended fillers *class* is
     * FILLER_COURSE_LEVEL_1 (lips body area + three products). Legacy LEVEL 3 FILLERS,
     * FILLER_COURSE_LEVEL_2, school FILLERS, and dynamic OT fillers track keep full areas + full list.
     */
    public function fillersCiAccessIsRestricted($user_id): bool
    {
        if ($this->hasSchoolFillersCertificate($user_id)) {
            return false;
        }
        if ($this->hasDynamicOtFillersTraining($user_id)) {
            return false;
        }
        if ($this->hasAttendedCatTrainingLevel($user_id, 'LEVEL 3 FILLERS')) {
            return false;
        }
        if ($this->hasAttendedCatTrainingLevel($user_id, 'FILLER_COURSE_LEVEL_2')) {
            return false;
        }

        return $this->hasAttendedCatTrainingLevel($user_id, 'FILLER_COURSE_LEVEL_1');
    }

    private function hasSchoolFillersCertificate($user_id): bool
    {
        $this->loadModel('SpaLiveV1.DataCourses');
        $certificate = $this->DataCourses
            ->find()
            ->join([
                'CatCourses' => [
                    'table' => 'cat_courses',
                    'type' => 'INNER',
                    'conditions' => 'CatCourses.id = DataCourses.course_id',
                ],
            ])
            ->select(['DataCourses.id'])
            ->where([
                'DataCourses.user_id' => $user_id,
                'CatCourses.type' => 'FILLERS',
                'DataCourses.status' => 'DONE',
                'DataCourses.deleted' => 0,
            ])
            ->order(['DataCourses.id' => 'DESC'])
            ->first();

        return !empty($certificate);
    }

    private function hasDynamicOtFillersTraining($user_id): bool
    {
        $this->loadModel('SpaLiveV1.DataTrainings');
        $user_course_basic = $this->DataTrainings->find()
            ->join([
                'CatTrainings' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainings.id = DataTrainings.training_id'],
                'CTC' => ['table' => 'cat_courses_type', 'type' => 'INNER', 'conditions' => 'CTC.name_key = CatTrainings.level'],
                'DCC' => ['table' => 'data_coverage_courses', 'type' => 'INNER', 'conditions' => 'DCC.course_type_id = CTC.id'],
                'STOT' => ['table' => 'sys_treatments_ot', 'type' => 'INNER', 'conditions' => 'STOT.id = DCC.ot_id'],
            ])
            ->where([
                'DataTrainings.user_id' => $user_id,
                'DataTrainings.deleted' => 0,
                'DataTrainings.attended' => 1,
                'CatTrainings.deleted' => 0,
                'STOT.name_key' => 'FILLERS',
            ])
            ->first();

        return !empty($user_course_basic);
    }

    private function hasAttendedCatTrainingLevel($user_id, string $level): bool
    {
        $this->loadModel('SpaLiveV1.DataTrainings');
        $row = $this->DataTrainings->find()
            ->join([
                'CatTrainings' => [
                    'table' => 'cat_trainings',
                    'type' => 'INNER',
                    'conditions' => 'CatTrainings.id = DataTrainings.training_id',
                ],
            ])
            ->select(['DataTrainings.id'])
            ->where([
                'DataTrainings.user_id' => $user_id,
                'DataTrainings.deleted' => 0,
                'DataTrainings.attended' => 1,
                'CatTrainings.deleted' => 0,
                'CatTrainings.level' => $level,
            ])
            ->first();

        return !empty($row);
    }

    /**
     * FILLER_COURSE_LEVEL_1: only body area id 2 (lips) from `cat_body_areas`.
     *
     * @param iterable|\Cake\Datasource\ResultSetInterface|array $body_areas
     * @return array
     */
    public function filterBodyAreasFillersToLipsOnly($body_areas): array
    {
        $list = is_array($body_areas) ? $body_areas : iterator_to_array($body_areas);
        $out = [];
        foreach ($list as $area) {
            $id = null;
            if (is_array($area)) {
                $id = $area['id'] ?? null;
            } elseif (is_object($area) && isset($area->id)) {
                $id = $area->id;
            }
            if ((int)$id === self::FILLER_COURSE_LEVEL1_BODY_AREA_ID) {
                $out[] = $area;
            }
        }

        return $out;
    }

    /**
     * FILLER_COURSE_LEVEL_1: only CI rows whose treatment or exam name matches the allowed list.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function filterFillersCiTreatmentsForLevel1Course(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $allowed = [];
        foreach (self::FILLER_COURSE_LEVEL1_CI_TREATMENT_NAMES as $n) {
            $allowed[] = strtolower(trim($n));
        }

        return array_values(array_filter($rows, function ($r) use ($allowed) {
            $candidates = [
                strtolower(trim((string)($r['name'] ?? ''))),
                strtolower(trim((string)($r['exam_name'] ?? ''))),
            ];
            foreach ($candidates as $c) {
                if ($c !== '' && in_array($c, $allowed, true)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * Shop inventory for users with only FILLER_COURSE_LEVEL_1: product `name` must match
     * {@see FILLER_COURSE_LEVEL1_CI_TREATMENT_NAMES} (same rules as CI filter, no cat_treatments_ci lookup).
     */
    public function shopFillerProductAllowedForLevel1Catalog(string $productName): bool
    {
        return $this->filterFillersCiTreatmentsForLevel1Course([
            ['name' => $productName, 'exam_name' => ''],
        ]) !== [];
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
            $_where['CatTrainings.level IN']   = ['LEVEL 3 FILLERS', 'FILLER_COURSE_LEVEL_1'];
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