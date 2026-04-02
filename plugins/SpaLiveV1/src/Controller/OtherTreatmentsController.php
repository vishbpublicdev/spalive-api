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

use SpaLiveV1\Controller\MainController;

use SpaLiveV1\Controller\CourseController;
use SpaLiveV1\Controller\SubscriptionController;
use SpaLiveV1\Controller\Data\TreatmentsHelper;
use SpaLiveV1\Controller\Data\ServicesHelper;

class OtherTreatmentsController extends AppPluginController{

    private $total = 3900;
    private $paymente_gfe = 1800;
    private $register_total = 89500;
    private $register_refund = 3500;
    private $shipping_cost = 1000;
    private $shipping_cost_both = 3000;
    private $shipping_cost_inj = 2000;
    private $shipping_cost_mat = 1000;
    private $shipping_cost_misc = 1000;
    private $training_advanced = 89500;
    private $emergencyPhone = "(469) 277 0897, (847) 477 5791";//"9035301512";
    private $emergencyPhone2 = "(847) 477 5791,(812) 322 8388";// "8474775791";
    private $total_subscriptionmsl = 3995;
    private $total_subscriptionmd = 17900;
    private $total_subscriptionot = 2000;

    public function initialize() : void{
        parent::initialize();
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
                    //$this->emergencyPhone = $state->phone_number != '' ? $state->phone_number : $this->emergencyPhone;
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

    public function get_info_ot(){
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

        $treatment_id = get('treatment_id', 0);

        if($treatment_id <= 0){
            $this->message('Invalid treatment ID.');
            return;
        }

        $this->loadModel('SpaLiveV1.SysTreatmentsOt');

        $treatment = $this->SysTreatmentsOt->find()
            ->where(['SysTreatmentsOt.id' => $treatment_id])
        ->first();

        if(empty($treatment)){
            $this->message('Treatment not found.');
            return;
        }

        $this->set('treatment', array(
            'id' => $treatment->id,
            'name' => $treatment->name,
            'description' => $treatment->description_injector,
            'image' => 'https://blog.myspalive.com/wp-content/uploads/2024/01/level3filler.png',
        ));

        // Buscamos si hay entrenamientos para este tratamiento

        $this->loadModel('SpaLiveV1.CatTrainings');

        $training = $this->CatTrainings->find()
            ->where(['CatTrainings.level' => $treatment->name, 'CatTrainings.deleted' => 0, 'CatTrainings.scheduled >' => date('Y-m-d H:i:s', strtotime('+ 1 day'))])
        ->first();

        $purchase = false;
        if(!empty($training)){
            $purchase = true;
        }

        $schools = $this->get_schools_by_treatment($treatment->name);

        $show_schools = false;

        if(!$purchase && count($schools) > 0){
            $show_schools = true;
        }

        $status = $this->get_status($user['user_id'], $treatment->name);

        if($status == 'DONE'){
            $this->set('text', "Subscription cost: $" . $this->total_subscriptionot/100 . " per month");
        }else if($status == 'PENDING'){
            $this->set('text', "Your certificate is being reviewed for approval. We'll send you an email once it's approved.");
        }

        $this->set('purchase', $purchase);

        $this->set('show_schools', $show_schools);

        $this->set('status', $status);

        $this->set('schools', $schools);

        $this->set('training', $training);

        $this->success();
    }

    public function get_schools_by_treatment($treatment){
        $this->loadModel('SpaLiveV1.DataSchoolRegister');
        $this->loadModel('SpaLiveV1.DataCourses');
        $this->loadModel('SpaLiveV1.CatCourses');

        $schools = $this->CatCourses->find()
            ->select(['CatCourses.school_id'])
            ->where(['CatCourses.type' => $treatment, 'CatCourses.deleted' => 0])
        ->all();

        if(count($schools) <= 0){
            return array();
        }

        $ids = [];

        foreach ($schools as $school) {
            $ids[] = $school->school_id;
        }

        $where = ['DataSchoolRegister.status' => 'Active', 'DataSchoolRegister.deleted' => 0, 'DataSchoolRegister.id IN' => $ids];
        
        $ent_schools = $this->DataSchoolRegister
            ->find()
            ->select(['DataSchoolRegister.id', 'DataSchoolRegister.uid', 'DataSchoolRegister.nameschool'])
            ->where($where)
            ->group(['DataSchoolRegister.id'])->all();

        if(count($ent_schools) <= 0){
            return array();
        }else{
            foreach ($ent_schools as $row) {
                    $row['is_selected'] = false;
            }
        }

        return $ent_schools;
    }

    public function get_status($user_id, $treatment){
        $this->loadModel('SpaLiveV1.DataCourses');
        $this->loadModel('SpaLiveV1.DataTrainings');
        $this->loadModel('SpaLiveV1.DataPayment');

        $_join = [
            'Cat' => ['table' => 'cat_courses','type' => 'INNER','conditions' => 'Cat.id = DataCourses.course_id'],
        ];

        $ent_course = $this->DataCourses->find()
            ->select(['DataCourses.id', 'DataCourses.status'])
            ->join($_join)
            ->where(['DataCourses.user_id' => $user_id, 'DataCourses.deleted' => 0, 'Cat.deleted' => 0, 'Cat.type' => $treatment])
        ->first();

        if(!empty($ent_course)){
            return $ent_course->status;
        } else {
            $ent_training = $this->DataTrainings->find()
                ->select(['DataTrainings.id', 'DataTrainings.attended'])
                ->join([
                    'Cat' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'Cat.id = DataTrainings.training_id']
                ])
                ->where(['DataTrainings.user_id' => $user_id, 'DataTrainings.deleted' => 0, 'Cat.deleted' => 0, 'Cat.level' => $treatment])
            ->first();

            if(!empty($ent_training)){
                if($ent_training->attended == 1){
                    return 'DONE';
                } elseif($ent_training->attended == 0){
                    return 'PENDING';
                }
            } else {
                $paymet = $this->DataPayment->find()
                    ->where(['DataPayment.id_from' => $user_id, 'DataPayment.is_visible' => 1, 'DataPayment.payment <>' => '', 'DataPayment.type' => $treatment])
                ->first();

                if(!empty($paymet)){
                    return 'SELECT_CLASS';
                } else {
                    return 'INITIAL';
                }
            }
        }
    }

    public function get_agreement(){

        $treatment_id = get('treatment_id', 0);

        if($treatment_id <= 0){
            $this->message('Invalid treatment ID.');
            return;
        }

        $this->loadModel('SpaLiveV1.SysTreatmentsOt');

        $treatment = $this->SysTreatmentsOt->find()
            ->where(['SysTreatmentsOt.id' => $treatment_id])
        ->first();

        if(empty($treatment)){
            $this->message('Treatment not found.');
            return;
        }
        $str_type = get('type','');
        $str_user = get('user','');
        $int_state = get('state',0);
        $str_agreement_uid = get('agreement_uid','');

        $this->loadModel('SpaLiveV1.Agreement');
        if (empty($str_agreement_uid)) {

            if ((empty($str_type) && empty($str_user)) ) {
                $this->message('Incorrect params.');
                return;
            }

            $type = strtoupper($str_type . $treatment->name);
            
            $ent_agreement = $this->Agreement->find()->where(
                [
                    'Agreement.state_id' => $int_state,
                    'Agreement.user_type' => strtoupper($str_user),
                    'Agreement.agreement_type' => $type,
                    'Agreement.deleted' => 0
                ]
            )->first();
        } else {
            $ent_agreement = $this->Agreement->find()->where(
                [
                    'Agreement.uid' => $str_agreement_uid,
                    'Agreement.deleted' => 0
                ]
            )->first();
        }

        if(!empty($ent_agreement)){
        
            $html_ = $ent_agreement['content'];
            $html_ .= '<br><p>Executed to be effective as of ' . date('m-d-Y') . '</p>';
            $result = array(
                'uid' => $ent_agreement['uid'],
                'content' => $html_,
            );
            $require_sign = true;
            if ($ent_agreement->agreement_type == 'TERMSANDCONDITIONS') $require_sign = false;

            if(strtoupper($str_type) == 'SUBSCRIPTION') $this->set('price', $this->total_subscriptionot/100 . ' per month');
            $this->set('require_sign', $require_sign);
            $this->set('data', $result);
        }else{
            $this->message('Agreement not found.');
            return;
        }

        $this->success();
    }

    public function get_info_course_ot(){
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

        $course_type_id = get('course_type_id', 0);

        if($course_type_id <= 0){
            $this->message('Invalid training ID.');
            return;
        }

        $this->loadModel('SpaLiveV1.CatTrainings');
        $this->loadModel('SpaLiveV1.DataPromoCodes');
        $this->loadModel('SpaLiveV1.CatCoursesType');

        $training = $this->CatCoursesType->find()
            ->select([
                'id',
                'price',
                'price_not_cross_full',
                'price_not_cross_installments',
                'image',
                'discount_id',
                'description',
                'description',
                'offer_id',
                'deferred_offer_id',
                'title',
                'name_key'
            ])
            ->where(['CatCoursesType.id' => $course_type_id])
        ->first();

       


        if(empty($training)){
            $this->message('Training not found.');
            return;
        }

        
        $this->loadModel('SpaLiveV1.DataDeferredPayments');
        $deferred_payment = $this->DataDeferredPayments->find()->where(['user_id' => USER_ID, 'status' => 'PENDING', 'type' => $training->name_key, 'deleted' => 0])->first();
        if(!empty($deferred_payment)){
            $this->set('deferred', 1);
        }else{
            $this->set('deferred', 0);
        }

        $discount = 0;
        if ($training->discount_id != 0) {
            $ent_code = $this->DataPromoCodes->find()->where(['DataPromoCodes.deleted' => 0,'DataPromoCodes.active' => 1,'DataPromoCodes.id' => $training->discount_id])->first();
            if (!empty($ent_code)) {
                $discount = $this->validateDefaultPromoCode($ent_code,$training->price);
                $this->set('promo_code', $ent_code->code);
                $this->set('discount', $discount / 100);
                $this->set('text_discount', "Today's Discount: -$" . ($discount / 100) . " (valid for today only!)");
            } else $this->set('promo_code', '');
        } else $this->set('promo_code', '');



        $api_url = env('URL_API', 'https://api.myspalive.com/');
        $training_amount_cents = intval($training->price - $discount);
        $stripe_fee = 0;
        $total = $training_amount_cents;

        $pay_in_full_cents = $training_amount_cents;
        if (!empty($training->price_not_cross_full) && (int)$training->price_not_cross_full > 0) {
            $pay_in_full_cents = (int)$training->price_not_cross_full;
        }

        $this->set('id', $training->id);
        $this->set('course_id', $training->id);
        $this->set('title', $training->title);
        $this->set('level', $training->name_key);
        $this->set('training_amount', number_format(($pay_in_full_cents / 100), 2, '.', ''));
        $installments_cents = $pay_in_full_cents;
        if (!empty($training->price_not_cross_installments) && (int)$training->price_not_cross_installments > 0) {
            $installments_cents = (int)$training->price_not_cross_installments;
        }
        $this->set('training_amount_installments', number_format(($installments_cents / 100), 2, '.', ''));
        $this->set('description', $training->description);
        if (!empty($training->image)) {
            if (strpos($training->image, 'https://') === 0) {
                $this->set('image', $training->image);
            } else {
                $this->set('image', $api_url . '?key=2fe548d5ae881ccfbe2be3f6237d7951&l3n4p=6092482f7ce858.91169218&action=get-file&token=6092482f7ce858.91169218&id=' . $training->image);
            }
        } else {
            $this->set('image', '');
        }
        $this->set('stripe_fee', number_format(($stripe_fee / 100), 2, '.', ''));
        $this->set('total', number_format(($total / 100), 2, '.', ''));

        $this->set('installments', !empty($training->offer_id) ? true : false);
        $this->set('installments_deferred', !empty($training->deferred_offer_id) ? true : false);


      

        $this->success();
    }

    private function validateDefaultPromoCode($ent_codes,$subtotal) {
        $total = $subtotal;
        if ($ent_codes->type == 'PERCENTAGE') {   
            $total = $subtotal * (100 - $ent_codes->discount) / 100;
            if ($total < 100) $total = 100;
        } else if ($ent_codes->type == 'AMOUNT') { 
            $total = $subtotal - $ent_codes->discount;
            if ($total < 100) $total = 100;
        }
        
        return round($subtotal - $total);

    }

    public function get_status_licence(){
        $token = get('token', '');

        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                return;
            }
        }

        // list courses

        $array_training = array(
            array(
                'course_type_id' => 0,
                'name' => 'Basic Neurotoxins',
                'price' => number_format($this->register_total/100, 2, '.', ''),
                'type' => 'basic',
            ),
            array(
                'course_type_id' => 0,
                'name' => 'Become a Weight Loss Specialist',
                'price' => '',
                'type' => 'weight_loss',
            ),
        );

        $courses = $this->get_courses_ot();

        $all_courses = array_merge($array_training, $courses);

        $this->set('courses', $all_courses);

        // end list courses

        // Check if the recently purchased course requires license
        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.CatCoursesType');
        
        // Get the most recent payment for dynamic courses (last 30 minutes)
        $recent_payment = $this->DataPayment->find()
            ->where([
                'DataPayment.id_from' => USER_ID,
                'DataPayment.is_visible' => 1,
                'DataPayment.payment <>' => '',
                'DataPayment.refund_id' => 0,
                'DataPayment.created >' => date('Y-m-d H:i:s', strtotime('-30 minutes'))
            ])
            ->order(['DataPayment.created' => 'DESC'])
            ->first();
        
        $course_requires_license = true; // Default to true for backward compatibility
        
        if(!empty($recent_payment) && !empty($recent_payment->type)){
            // Verify if this payment type corresponds to a dynamic course (name_key)
            $course_type = $this->CatCoursesType->find()
                ->where([
                    'CatCoursesType.name_key' => $recent_payment->type,
                    'CatCoursesType.deleted' => 0
                ])
                ->first();
            
            if(!empty($course_type)){
                // Check if course has require_license field and if it's set to 0
                if(isset($course_type->require_license) && $course_type->require_license == 0){
                    $course_requires_license = false;
                }
            }
        }

        $this->loadModel('SpaLiveV1.SysLicences');

        $licence = $this->SysLicences->find()
            ->where(['SysLicences.user_id' => $user['user_id'], 'SysLicences.deleted' => 0])
        ->order(['SysLicences.id' => 'DESC'])
        ->first();

        if(empty($licence) && $course_requires_license){
            $this->set('status', 'upload_licence');
            $this->success();
            return;
        }

         
        // If course doesn't require license and user doesn't have one, skip to approved
        if(empty($licence) && !$course_requires_license){
            $this->set('status', 'approved');
            $this->success();
            return;
        }

        $status = '';

        if($licence->status == 'PENDING'){
            $status = 'pending';
        }

        if($licence->status == 'APPROVED'){
            $status = 'approved';
        }

        if($licence->status == 'REJECTED'){
            $status = 'upload_licence';
        }

        $this->set('status', $status);
        
        $this->success();
    }

    private function get_courses_ot(){
        $this->loadModel('SpaLiveV1.CatCoursesType');
        $this->loadModel('SpaLiveV1.DataTrainings');
        $this->loadModel('SpaLiveV1.DataPayment');

        $other_t = $this->CatCoursesType->find()
        ->where(['CatCoursesType.available' => 1, 'CatCoursesType.deleted' => 0])
        ->all();

        if(count($other_t) == 0){
            return [];
        }

        $data_trainings = [];
        foreach($other_t as $t){

            if($t->require_msl_basic == 1){
                $level1 = $this->DataTrainings->find()
                ->join(['Cat' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'Cat.id = DataTrainings.training_id']])
                ->where(['DataTrainings.user_id' => USER_ID, 'DataTrainings.deleted' => 0, 'Cat.level' => 'LEVEL 1', 'Cat.deleted' => 0])
                ->first();

                if(empty($level1)){
                    continue;
                }
            }

            $payment = $this->DataPayment->find()
                ->where(['DataPayment.id_from' => USER_ID, 'DataPayment.type' => $t->name_key, 'DataPayment.is_visible' => 1, 'DataPayment.payment <>' => '', 'DataPayment.refund_id' => 0])
            ->first();
            if(!empty($payment)){
                continue;
            }

            $data_trainings[] = [
                'course_type_id' => $t->id,
                'name' => $t->title,
                'price' => number_format($t->price / 100, 2, '.', ''),
                'type' => 'dynamic',
            ];
            
        }

        return $data_trainings;
    }

    public function cat_licences(){
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

        $array_lincences = array(
            array(
                'type' => 'Esthetician',
                'name' => 'Esthetician',
            ),
            array(
                'type' => 'Cosmetology/Esthetician',
                'name' => 'Cosmetology/Esthetician',
            ),
            array(
                'type' => 'LVN',
                'name' => 'LVN',
            ),
            array(
                'type' => 'RN',
                'name' => 'RN',
            ),
            array(
                'type' => 'NP',
                'name' => 'NP',
            ),
            array(
                'type' => 'PA',
                'name' => 'PA',
            ),
            array(
                'type' => 'MD',
                'name' => 'MD',
            ),
            
        );

        $this->set('data', $array_lincences);
        $this->success();
    }

    public function common_documents_index()
    {
        try {
            $this->loadModel('SpaLiveV1.CommonDocuments');
            $this->loadModel('CatTreatments');
            
            $documents = $this->CommonDocuments->find('withTreatmentInfo')
                ->order(['created' => 'DESC'])
                ->toArray();

            $processedDocuments = [];
            foreach ($documents as $document) {
                $permissions = $this->CommonDocuments->getTreatmentPermissionsArray($document->treatment_permissions);
                $treatmentNames = [];
                
                foreach ($permissions as $treatmentId => $isAvailable) {
                    if ($isAvailable) {
                        $treatment = $this->CatTreatments->get($treatmentId);
                        $treatmentNames[] = $treatment->name;
                    }
                }
                
                $processedDocuments[] = [
                    'id' => $document->id,
                    'name' => $document->name,
                    'description' => $document->description,
                    'file_name' => $document->file_name,
                    'file_path' => $document->file_path,
                    'file_size' => $document->file_size,
                    'mime_type' => $document->mime_type,
                    'treatment_permissions' => $permissions,
                    'treatment_names' => $treatmentNames,
                    'created' => $document->created,
                    'modified' => $document->modified
                ];
            }
            
            $this->set([
                'success' => true,
                'data' => $processedDocuments
            ]);
            
        } catch (\Exception $e) {
            $this->set([
                'success' => false,
                'message' => 'Error al obtener documentos: ' . $e->getMessage()
            ]);
        }
        
        $this->viewBuilder()->setOption('serialize', ['success', 'message', 'data']);
    }

    public function common_documents_treatments()
    {
        try {
            $this->loadModel('SpaLiveV1.CommonDocuments');
            $treatments = $this->CommonDocuments->getAvailableTreatments();
            
            $this->set([
                'success' => true,
                'data' => $treatments
            ]);
            
        } catch (\Exception $e) {
            $this->set([
                'success' => false,
                'message' => 'Error al obtener tratamientos: ' . $e->getMessage()
            ]);
        }
        
        $this->viewBuilder()->setOption('serialize', ['success', 'message', 'data']);
    }

    public function common_documents_add()
    {
        if (!$this->request->is('post')) {
            $this->set([
                'success' => false,
                'message' => 'Método no permitido'
            ]);
            $this->viewBuilder()->setOption('serialize', ['success', 'message']);
            return;
        }
        
        try {
            $this->loadModel('SpaLiveV1.CommonDocuments');
            $data = $this->request->getData();
            
            $data = $this->CommonDocuments->processTreatmentPermissions($data);
            
            $document = $this->CommonDocuments->newEntity($data);
            
            if ($this->CommonDocuments->save($document)) {
                $this->set([
                    'success' => true,
                    'message' => 'Documento creado exitosamente',
                    'data' => ['id' => $document->id]
                ]);
            } else {
                $this->set([
                    'success' => false,
                    'message' => 'Error al crear documento',
                    'errors' => $document->getErrors()
                ]);
            }
            
        } catch (\Exception $e) {
            $this->set([
                'success' => false,
                'message' => 'Error al crear documento: ' . $e->getMessage()
            ]);
        }
        
        $this->viewBuilder()->setOption('serialize', ['success', 'message', 'data', 'errors']);
    }

}
