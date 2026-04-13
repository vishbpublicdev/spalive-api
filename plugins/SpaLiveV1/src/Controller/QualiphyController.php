<?php
declare(strict_types=1);

namespace SpaLiveV1\Controller;
use App\Controller\AppPluginController;
use Cake\Core\Configure;
use Cake\Utility\Security;
use Cake\Utility\Text;
use Cake\Utility\Hash;
require_once(ROOT . DS . 'vendor' . DS  . 'aws' . DS . 'aws-autoloader.php');
use \Aws\S3\S3Client;
use Stripe\Product;

class QualiphyController extends AppPluginController{
    
    private $total = 3900;
    private $exam_ids = array(
        //'neuro_filler' => 1084,
        'neuro_filler' => 1252,
        'weight_loss' => 1083,
        'iv_therapy' => 309,
        'neuro_iv' => 1417,
    );

    private $service_uid = '1q2we3-r4t5y6-7ui8o990';

    public function initialize() : void{
        parent::initialize();
        $this->loadModel('SpaLiveV1.AppToken');
        $this->loadModel('SpaLiveV1.CatStates');
        $this->loadModel('SpaLiveV1.QualiphyWebhook');
        $is_dev = env('IS_DEV', false);
        if($is_dev){

            $this->exam_ids = array(
                'neuro_filler' => 801,
                'weight_loss' => 813,
                'iv_therapy' => 108,
                'neuro_iv' => 126,
            );

            $this->service_uid = '1q2we3-r4t5y6-7ui8o990';
        }
    }

    public function generate_meeting($additional_data = array(), $exam_name = 'neuro_filler', $ot_exam_id = 0){

        if ($exam_name != 'other_treatments') {
            $exam_id = $this->exam_ids[$exam_name];
        } else {
            $exam_id = $ot_exam_id;
        }

        $qualiphy_key = env('QUALIPHY_KEY','');

        $url = env('QUALIPHY_URL', '') . 'api/exam_invite/';

        $ent_state = $this->CatStates->find()->select(['name', 'abv'])->where(['id' => USER_STATE])->first();
        $abv_state = '';

        $string_treatments = get('treatments','80');
        /*if (empty($string_treatments)) {
            $this->message('Treatments empty.');
            return;
        }*/

        if(empty($ent_state)){
            $abv_state = 'TX';
        }else{
            $abv_state = $ent_state->abv;
        }

        $postData = array(
            'api_key' => $qualiphy_key,
            'exams' => array($exam_id),
            'first_name' => USER_NAME,
            'last_name' => USER_LNAME,
            'email' => USER_EMAIL,
            'dob' => DOB == '0000-00-00' || empty(DOB) ? '2000-01-01' : DOB,
            'phone_number' => USER_PHONE,
            'webhook_url' => env('URL_API', '') . '?key=fdg32jmudsrfbqi28ghjsdodguhusdi&action=Qualiphy____webhook&state=43',
            'additional_data' => json_encode($additional_data),
            'tele_state' => $abv_state,
        );

        $curl = curl_init();
        curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($postData),
        CURLOPT_HTTPHEADER => array(
            "content-type: application/json",
            ),
        ));
        $response = curl_exec($curl);
        $err = (string) curl_error($curl);

        curl_close($curl);

        $responseBody = is_string($response) ? $response : '';
        $arr_response = json_decode($responseBody, true);

        $arr_err = json_decode($err, true);

        $bad = !empty($arr_err) || $response === false || $err !== '' || !is_array($arr_response);
        if (env('IS_DEV', false) && $bad) {
            return [
                'http_code' => 200,
                'meeting_uuid' => Text::uuid(),
                'meeting_url' => env('QUALIPHY_DEV_MEETING_URL', 'https://example.com/qualiphy-dev-stub'),
                'patient_exams' => [['patient_exam_id' => 0]],
            ];
        }

        if (!empty($arr_err)) {
            return false;
        }

        return $arr_response;
    }

    public function webhook(){

        $input = file_get_contents('php://input');
		$data = json_decode($input, true);

        $ent_webhook = $this->QualiphyWebhook->newEntity(array(
            'input' => json_encode($data),
            'created' => date('Y-m-d H:i:s'),
        ));

        $this->QualiphyWebhook->save($ent_webhook);

        $additional_data = json_decode($data['additional_data'], true);

        $is_ot = false;
        if (isset($additional_data['type']) && $additional_data['type'] == 'other_treatments') {

            $this->loadModel('SpaLiveV1.CatTreatments');

            $other_treatment = $this->CatTreatments->find()
            ->where(['CatTreatments.deleted' => 0, 'CatTreatments.qualiphy_exam_id' => $data['exam_id']])
            ->first();

            if (!empty($other_treatment)) {
                $is_ot = true;
            }

        }


        if($data['exam_id'] == $this->exam_ids['neuro_filler'] || $data['exam_id'] == $this->exam_ids['neuro_iv'] || $data['exam_id'] == $this->exam_ids['iv_therapy'] || $is_ot){

            $this->loadModel('SpaLiveV1.SysUsers');
            
            $user_uid = $additional_data['user_uid'];
            $ent_ondemand = $this->SysUsers->find()
            ->join([
                'OnDemand' => ['table' => 'data_patient_ondemand', 'type' => 'INNER', 'conditions' => 'OnDemand.user_id = SysUsers.id AND OnDemand.deleted = 0'],
            ])
            ->where(['SysUsers.uid' => $user_uid])
            ->first();

            if (!empty($ent_ondemand) && !$is_ot) {
                $this->loadModel('SpaLiveV1.DataVisitsSites');

                $page = 'gfe';
                if ($data['exam_id'] == $this->exam_ids['neuro_iv']) $page = 'gfe-neuro-iv';
                if ($data['exam_id'] == $this->exam_ids['iv_therapy']) $page = 'gfe-iv';
                $array_save = array(
                    // 'id' => USER_ID,
                    'created' => date('Y-m-d H:i:s'),
                    'ip' => $ent_ondemand->id,
                    'page' => $page
                );
        
                $c_entity = $this->DataVisitsSites->newEntity($array_save);
                $this->DataVisitsSites->save($c_entity);
                
            }


            if($data['exam_status'] == 'Approved'){
                if($data['additional_data'] != null){
                    $this->loadModel('SpaLiveV1.DataConsultation');
                    $this->loadModel('SpaLiveV1.DataCertificates');

                    $ent_consultation = $this->DataConsultation->find()->where(['uid' => $additional_data['consultation_uid']])->first();

                    if(empty($ent_consultation)){
                        return;
                    }

                    $this->loadModel('SpaLiveV1.DataPayment');
                    $ent_payment = $this->DataPayment->find()
                    ->where(['DataPayment.id_from' => $ent_consultation->patient_id, 'DataPayment.id_to' => 0,'DataPayment.type' => 'GFE', 'DataPayment.service_uid' => '','DataPayment.prepaid' => 1, 'DataPayment.comission_payed' => 1])->first();
                    
                    if (empty($ent_payment)) {
                        return;
                    }

                    $ent_payment->service_uid = $ent_consultation->uid;
                    $this->DataPayment->save($ent_payment);

                    $this->DataConsultation->updateAll(
                        [
                            'status' => 'CERTIFICATE', 
                            'assitance' => $data['provider_name'],
                            'notes' => $data['reason'],
                            'treatments_requested' => $ent_consultation->treatments,
                            'payment' => $ent_payment->intent,
                            'payment_intent' => $ent_payment->intent,
                        ],
                        ['id' => $ent_consultation->id]
                    );

                    $this->s3Client = new \Aws\S3\S3Client([
                        'version' => 'latest',
                        'region'  => 'nyc3',
                        'endpoint' => env('SPACES_ORIGIN'),
                        // 'use_path_style_endpoint' => false, // Configures to use subdomain/virtual calling format.
                        'credentials' => [
                            'key'    => env('SPACES_ACCESS_KEY'),
                            'secret' => env('SPACES_SECRET'),
                        ],
                    ]);
            
                    $file_uid = Text::uuid();
                    $tmp_path = TMP . "qualiphy/" . $file_uid . '.pdf';
                    $serverN = 'qualiphy/' . $additional_data['user_uid'] . "/" . $file_uid . '.pdf';
            
                    file_put_contents(
                        $tmp_path,
                        file_get_contents( $data['exam_url'] )
                    );
            
                    $resFile = $this->s3Client->putObject(array(
                        'Bucket'     => env('SPACES_SIMPLE_BUCKET'),
                        'SourceFile' => $tmp_path,
                        'Key'        => $serverN,
                        'ACL' => 'public-read',
                    ));

                    $s3File = env('SPACES_BUCKET') . '/' . $serverN;

                    $array_save = array(
                        'uid' => Text::uuid(),
                        'consultation_id' => $ent_consultation->id,
                        'date_start' => date('Y-m-d'),
                        'date_expiration' => date('Y-m-d', strtotime('+1 year')),
                        'deleted' => 0,
                        'certificate_url' => $s3File,
                    );

                    $ent_data_certificates = $this->DataCertificates->newEntity($array_save);

                    $this->DataCertificates->save($ent_data_certificates);

                    $arr_treatments = explode(",", $ent_consultation->treatments);

                    $this->loadModel('SpaLiveV1.DataConsultationPlan');

                    foreach($arr_treatments as $treatment){
                        $array_save_a = array(
                            'uid' => Text::uuid(),
                            'consultation_id' => $ent_consultation->id,
                            'detail' => $data['reason'],
                            'treatment_id' => $treatment,
                            'plan' => '',
                            'proceed' => 1,
                            'deleted' => 0,
                        );
            
                        $cp_entity = $this->DataConsultationPlan->newEntity($array_save_a);
                        if(!$cp_entity->hasErrors()){
                            $this->DataConsultationPlan->save($cp_entity);
                        }
                    }

                    $Main = new MainController();
                    $message = 'Your GFE has been approved.';
                    $ent_user = $this->SysUsers->find()->where(['uid' => $additional_data['user_uid']])->first();
                    $user_id = $ent_user->id;
                    $Main->notify_devices($message, array($user_id), true);
                    if($ent_user->type == 'patient'){
                        $this->SysUsers->updateAll(
                            ['steps' => 'HOME'], ['id' => $user_id]
                        );
                    }
                    return;
                }
            } else if($data['exam_status'] == 'Defer to Medical Director'){
                $this->loadModel('SpaLiveV1.DataConsultation');
                $this->loadModel('SpaLiveV1.DataCertificates');
                $this->loadModel('SpaLiveV1.SysUsers');

                $ent_consultation = $this->DataConsultation->find()->where(['uid' => $additional_data['consultation_uid']])->first();

                if(empty($ent_consultation)){
                    return;
                }

                $this->loadModel('SpaLiveV1.DataPayment');
                $ent_payment = $this->DataPayment->find()
                ->where(['DataPayment.id_from' => $ent_consultation->patient_id, 'DataPayment.id_to' => 0,'DataPayment.type' => 'GFE', 'DataPayment.service_uid' => '','DataPayment.prepaid' => 1, 'DataPayment.comission_payed' => 1])->first();
                
                if (empty($ent_payment)) {
                    return;
                }

                $ent_payment->service_uid = $ent_consultation->uid;
                $this->DataPayment->save($ent_payment);

                $this->DataConsultation->updateAll(
                    [
                        'status' => 'CERTIFICATE', 
                        'assitance' => $data['provider_name'], 
                        'notes' => $data['reason'],
                        'payment' => $ent_payment->intent,
                        'payment_intent' => $ent_payment->intent,
                    ],
                    ['id' => $ent_consultation->id]
                );

                $this->loadModel('SpaLiveV1.DataConsultationPlan');
                    $array_save_a = array(
                        'uid' => Text::uuid(),
                        'consultation_id' => $ent_consultation->id,
                        'detail' => $data['reason'],
                        'treatment_id' => 92,
                        'plan' => '',
                        'proceed' => 0,
                        'deleted' => 0,
                    );
        
                    $cp_entity = $this->DataConsultationPlan->newEntity($array_save_a);
                    if(!$cp_entity->hasErrors()){
                        $this->DataConsultationPlan->save($cp_entity);
                    }

                $Main = new MainController();
                $message = 'Your GFE has been rejected.';
                $ent_user = $this->SysUsers->find()->where(['uid' => $additional_data['user_uid']])->first();
                $user_id = $ent_user->id;
                $Main->notify_devices($message, array($user_id), true);
                if($ent_user->type == 'patient'){
                    $this->SysUsers->updateAll(
                        ['steps' => 'HOME'], ['id' => $user_id]
                    );
                }
                return;
            } else {
                $this->loadModel('SpaLiveV1.DataConsultation');
                $this->loadModel('SpaLiveV1.DataCertificates');
                $this->loadModel('SpaLiveV1.SysUsers');

                $ent_consultation = $this->DataConsultation->find()->where(['uid' => $additional_data['consultation_uid']])->first();

                if(empty($ent_consultation)){
                    return;
                }

                $this->DataConsultation->updateAll(
                    [
                        'status' => 'CANCEL', 
                        'assitance' => $data['provider_name'], 
                        'notes' => $data['reason'],
                    ],
                    ['id' => $ent_consultation->id]
                );

                $Main = new MainController();
                $message = 'Your GFE has been cancelled.';
                $ent_user = $this->SysUsers->find()->where(['uid' => $additional_data['user_uid']])->first();
                $user_id = $ent_user->id;
                $Main->notify_devices($message, array($user_id), true);
                if($ent_user->type == 'patient'){
                    $this->SysUsers->updateAll(
                        ['steps' => 'HOME'], ['id' => $user_id]
                    );
                }
                return;
            }
        } else if($data['exam_id'] == $this->exam_ids['weight_loss']) {
            if($data['exam_status'] == 'Approved'){
                if($data['additional_data'] != null){
                    $this->save_other_services_plan($additional_data['consultation_uid'], $data['questions_answers'], $data['reason']);
                }
            }
        }

        return;
    }

    public function save_other_services_plan($consultation_uid, $questionnaire, $notes){
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $this->loadModel('SpaLiveV1.DataConsultationPlanOtherServices');
        $this->loadModel('SpaLiveV1.DataPrescribedProductsOtherServices');
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
        $this->loadModel('SpaLiveV1.DataOtherServices');
        $this->loadModel('SpaLiveV1.CatProductsOtherServices');

        if(empty($consultation_uid)){
            $this->message('consultation_id empty.');
            return;
        }

        $ent_consultation = $this->DataConsultationOtherServices->find()->where(['DataConsultationOtherServices.uid' => $consultation_uid])->first();
        if (empty($ent_consultation)) {
            $this->message('Invalid consultation.');
            return;
        }

        $ent_call = $this->DataOtherServicesCheckIn->find()->select(['id'])->where(['consultation_uid' => $consultation_uid])->first();
        $call_id = $ent_call->id;
        if(empty($call_id)){
            $this->message('Call id empty.');
            return;
        }

        $consultation_id = $ent_consultation->id;

        // si no tiene productos cancelar la consulta
        //si no tiene productos, poner en estado rejected,
        $arr_products = [array('service_uid' => $this->service_uid, 'products' => [1])];

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

        $currentCheckIn = $this->DataOtherServicesCheckIn->find()
        ->where([
            'consultation_uid' => $consultation_uid,
            'id' => $call_id
        ])->first(); 

        if($currentCheckIn->call_number != 6){

            $ent_consultation->status = "IN PROGRESS";
            $ent_consultation->assistance_id = 0;

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

        $alergies = '';
        $refills = array(
            array("text" => "Month 1 : Inject 0.1mL (0.25mg) sq every week/4-weeks", "has_refills" => true, "refills" => 1, "selected" => true),
            array("text" => "As tolerated, Month 2 : Inject 0.2mL (0.5mg) sq every week/4-weeks", "has_refills" => true, "refills" => 1, "selected" => true),
            array("text" => "As tolerated, Month 3 : Inject 0.4mL (1mg) sq every week/4-weeks", "has_refills" => true, "refills" => 1, "selected" => true),
            array("text" => "As tolerated, Month 4 : Inject 0.68mL (1.7mg) sq every week/4-weeks", "has_refills" => true, "refills" => 0, "selected" => false),
            array("text" => "As tolerated, Month 5 : Inject 0.96mL (2.4mg) sq every week/4-weeks", "has_refills" => true, "refills" => 0, "selected" => false),
            array("text" => "Supplies : Insulin Syringes & alcohol Prep Pads. *Our team will assemble according to RX*", "has_refills" => false, "refills" => 0, "selected" => true)
        );
        $qty = "6";

        $OS = new OtherservicesController();
        $OS->save_question_postexam_other_services(
            $call_id, 
            $consultation_id, 
            $questionnaire, 
            $notes,
            $alergies,
            json_encode($refills),
            $qty,
        );           

        if($currentCheckIn->call_type == "FIRST CONSULTATION"){
            $this->loadModel('SpaLiveV1.SysUsers');

            $user_info = $this->SysUsers->find()->select(['SysUsers.name', 'SysUsers.lname', 'SysUsers.mname', 'SysUsers.dob', 'SysUsers.street', 'SysUsers.city', 'SysUsers.zip', 'SysUsers.phone', 'SysUsers.email'])->where(['id' => $ent_consultation->patient_id])->first();

            if(empty($user_info)){
                $this->message('user empty.');
                return;
            }

            $OS->send_email_prescription_pdf($user_info,$consultation_id);
            
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
        }

        return;
    }

    public function get_gfe_status(){
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

        $total = get('count', '');

        $count = 0;
        $this->loadModel('SpaLiveV1.DataConsultation');

        $ent_consultation = $this->DataConsultation->find()->where(['DataConsultation.patient_id' => USER_ID, 'DataConsultation.status' => 'INIT' ,'DataConsultation.deleted' => 0])->last();

        if(!empty($ent_consultation)){
            $count++;
        }

        $this->loadModel('SpaLiveV1.DataTreatment');

        $ent_treatment = $this->DataTreatment->find()
        ->where(['DataTreatment.patient_id' => USER_ID, 'DataTreatment.deleted' => 0, 'DataTreatment.type_uber' => 1, 'DataTreatment.status' => 'PETITION'])->all();

        $count += count($ent_treatment);

        if(!empty($total)){
            if($total != $count){
                $this->set('refresh', true);
            }else{
                $this->set('refresh', false);
            }
        }else{
            $this->set('refresh', false);
        }

        $this->set('count', $count);
        $this->success();
    }

    public function gfe_technical_issues(){
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

        $this->set('email', 'qualityassurance@myspalive.com');
        $this->set('phone', '(972) 369 4414');
        $this->set('phone_f', '9723694414');

        $this->loadModel('SpaLiveV1.DataConsultation');

        $ent_consultation = $this->DataConsultation->find()->where(['DataConsultation.patient_id' => USER_ID, 'DataConsultation.status' => 'INIT' ,'DataConsultation.deleted' => 0])->last();

        if(empty($ent_consultation)){
            $this->set('show_button_start', false);
        }else{
            if($ent_consultation->meeting == '' || $ent_consultation->resend >= 2){
                $this->set('show_button_start', false);
            } else{
                $this->set('show_button_start', true);
            }
        }

        $this->success();
    }

    public function resend_meeting(){

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

        $this->loadModel('SpaLiveV1.DataConsultation');

        $ent_consultation = $this->DataConsultation->find()->where(['DataConsultation.patient_id' => USER_ID, 'DataConsultation.status' => 'INIT' ,'DataConsultation.deleted' => 0])->last();

        if(empty($ent_consultation)){
            $this->message('Consultation not found.');
            return;
        }

        if($ent_consultation->meeting == ''){
            $this->message("You cannot forward this link, please contact support.");
            return;
        }

        $qualiphy_key = env('QUALIPHY_KEY','');

        $url = env('QUALIPHY_URL', '') . 'api/exam_invite_resend/';

        $postData = array(
            'api_key' => $qualiphy_key,
            'meeting_uuid' => $ent_consultation->meeting_pass,
            'patient_exam_id' => $ent_consultation->meeting,
        );

        $curl = curl_init();
        curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($postData),
        CURLOPT_HTTPHEADER => array(
            "content-type: application/json",
            ),
        ));
        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        $arr_response = json_decode($response,true);

        $arr_err = json_decode($err,true);

        if(!empty($arr_err)){
            return;
        }

        if($arr_response['http_code'] == 200){
            $this->success();
            $this->DataConsultation->updateAll(
                ['resend' => $ent_consultation->resend + 1], 
                ['id' => $ent_consultation->id]
            );
            return;
        } else {
            $this->message($arr_response['http_code']);
            $this->message($arr_response['error_message']);
            return;
        }
    }

    public function generate_meeting_ot($exam_id, $additional_data = array()){

        $qualiphy_key = env('QUALIPHY_KEY','');

        $url = env('QUALIPHY_URL', '') . 'api/exam_invite/';

        $ent_state = $this->CatStates->find()->select(['name', 'abv'])->where(['id' => USER_STATE])->first();
        $abv_state = '';

        if(empty($ent_state)){
            $abv_state = 'TX';
        }else{
            $abv_state = $ent_state->abv;
        }

        $postData = array(
            'api_key' => $qualiphy_key,
            'exams' => array($exam_id),
            'first_name' => USER_NAME,
            'last_name' => USER_LNAME,
            'email' => USER_EMAIL,
            'dob' => DOB == '0000-00-00' || empty(DOB) ? '2000-01-01' : DOB,
            'phone_number' => USER_PHONE,
            'webhook_url' => env('URL_API', '') . '?key=fdg32jmudsrfbqi28ghjsdodguhusdi&action=Qualiphy____webhook_ot',
            'additional_data' => json_encode($additional_data),
            'tele_state' => $abv_state,
        );

        $curl = curl_init();
        curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($postData),
        CURLOPT_HTTPHEADER => array(
            "content-type: application/json",
            ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);
        if ($response)
            $arr_response = json_decode($response,true);
        else 
            return false;

        $arr_err = json_decode($err,true);

        if(!empty($arr_err)){
            return false;
        }

        return $arr_response;
    }

    public function webhook_ot(){

        $input = file_get_contents('php://input');
		$data = json_decode($input, true);

        $ent_webhook = $this->QualiphyWebhook->newEntity(array(
            'input' => json_encode($data),
            'created' => date('Y-m-d H:i:s'),
        ));

        $this->QualiphyWebhook->save($ent_webhook);

        $additional_data = json_decode($data['additional_data'], true);

        $this->loadModel('SpaLiveV1.SysUsers');
        
        $user_uid = $additional_data['user_uid'];

        if($data['exam_status'] == 'Approved'){
            if($data['additional_data'] != null){
                $this->loadModel('SpaLiveV1.DataConsultation');
                $this->loadModel('SpaLiveV1.DataCertificates');

                $ent_consultation = $this->DataConsultation->find()->where(['uid' => $additional_data['consultation_uid']])->first();

                if(empty($ent_consultation)){
                    return;
                }

                $this->loadModel('SpaLiveV1.DataPayment');
                $ent_payment = $this->DataPayment->find()
                ->where(['DataPayment.id_from' => $ent_consultation->patient_id, 'DataPayment.id_to' => 0,'DataPayment.type' => 'GFE OT', 'DataPayment.service_uid' => '','DataPayment.prepaid' => 1, 'DataPayment.comission_payed' => 1])->first();
                
                if (empty($ent_payment)) {
                    return;
                }

                $ent_payment->service_uid = $ent_consultation->uid;
                $this->DataPayment->save($ent_payment);

                $this->DataConsultation->updateAll(
                    [
                        'status' => 'CERTIFICATE', 
                        'assitance' => $data['provider_name'],
                        'notes' => $data['reason'],
                        'treatments_requested' => $ent_consultation->treatments,
                        'payment' => $ent_payment->intent,
                        'payment_intent' => $ent_payment->intent,
                    ],
                    ['id' => $ent_consultation->id]
                );

                $this->s3Client = new \Aws\S3\S3Client([
                    'version' => 'latest',
                    'region'  => 'nyc3',
                    'endpoint' => env('SPACES_ORIGIN'),
                    // 'use_path_style_endpoint' => false, // Configures to use subdomain/virtual calling format.
                    'credentials' => [
                        'key'    => env('SPACES_ACCESS_KEY'),
                        'secret' => env('SPACES_SECRET'),
                    ],
                ]);
        
                $file_uid = Text::uuid();
                $tmp_path = TMP . "qualiphy/" . $file_uid . '.pdf';
                $serverN = 'qualiphy/' . $additional_data['user_uid'] . "/" . $file_uid . '.pdf';
        
                file_put_contents(
                    $tmp_path,
                    file_get_contents( $data['exam_url'] )
                );
        
                $resFile = $this->s3Client->putObject(array(
                    'Bucket'     => env('SPACES_SIMPLE_BUCKET'),
                    'SourceFile' => $tmp_path,
                    'Key'        => $serverN,
                    'ACL' => 'public-read',
                ));

                $s3File = env('SPACES_BUCKET') . '/' . $serverN;

                $array_save = array(
                    'uid' => Text::uuid(),
                    'consultation_id' => $ent_consultation->id,
                    'date_start' => date('Y-m-d'),
                    'date_expiration' => date('Y-m-d', strtotime('+1 year')),
                    'deleted' => 0,
                    'certificate_url' => $s3File,
                );

                $ent_data_certificates = $this->DataCertificates->newEntity($array_save);

                $this->DataCertificates->save($ent_data_certificates);

                $arr_treatments = explode(",", $ent_consultation->treatments);

                $this->loadModel('SpaLiveV1.DataConsultationPlan');

                foreach($arr_treatments as $treatment){
                    $array_save_a = array(
                        'uid' => Text::uuid(),
                        'consultation_id' => $ent_consultation->id,
                        'detail' => $data['reason'],
                        'treatment_id' => $treatment,
                        'plan' => '',
                        'proceed' => 1,
                        'deleted' => 0,
                    );
        
                    $cp_entity = $this->DataConsultationPlan->newEntity($array_save_a);
                    if(!$cp_entity->hasErrors()){
                        $this->DataConsultationPlan->save($cp_entity);
                    }
                }

                $Main = new MainController();
                $message = 'Your GFE has been approved.';
                $ent_user = $this->SysUsers->find()->where(['uid' => $additional_data['user_uid']])->first();
                $user_id = $ent_user->id;
                $Main->notify_devices($message, array($user_id), true);
                if($ent_user->type == 'patient'){
                    $this->SysUsers->updateAll(
                        ['steps' => 'HOME'], ['id' => $user_id]
                    );
                }
                return;
            }
        } else if($data['exam_status'] == 'Defer to Medical Director'){
            $this->loadModel('SpaLiveV1.DataConsultation');
            $this->loadModel('SpaLiveV1.DataCertificates');
            $this->loadModel('SpaLiveV1.SysUsers');

            $ent_consultation = $this->DataConsultation->find()->where(['uid' => $additional_data['consultation_uid']])->first();

            if(empty($ent_consultation)){
                return;
            }

            $this->loadModel('SpaLiveV1.DataPayment');
            $ent_payment = $this->DataPayment->find()
            ->where(['DataPayment.id_from' => $ent_consultation->patient_id, 'DataPayment.id_to' => 0,'DataPayment.type' => 'GFE', 'DataPayment.service_uid' => '','DataPayment.prepaid' => 1, 'DataPayment.comission_payed' => 1])->first();
            
            if (empty($ent_payment)) {
                return;
            }

            $ent_payment->service_uid = $ent_consultation->uid;
            $this->DataPayment->save($ent_payment);

            $this->DataConsultation->updateAll(
                [
                    'status' => 'CANCEL', 
                    'assitance' => $data['provider_name'], 
                    'notes' => $data['reason'],
                    'payment' => $ent_payment->intent,
                    'payment_intent' => $ent_payment->intent,
                ],
                ['id' => $ent_consultation->id]
            );

            $this->loadModel('SpaLiveV1.DataConsultationPlan');
                $array_save_a = array(
                    'uid' => Text::uuid(),
                    'consultation_id' => $ent_consultation->id,
                    'detail' => $data['reason'],
                    'treatment_id' => 92,
                    'plan' => '',
                    'proceed' => 0,
                    'deleted' => 0,
                );
    
                $cp_entity = $this->DataConsultationPlan->newEntity($array_save_a);
                if(!$cp_entity->hasErrors()){
                    $this->DataConsultationPlan->save($cp_entity);
                }

            $Main = new MainController();
            $message = 'Your GFE has been rejected.';
            $ent_user = $this->SysUsers->find()->where(['uid' => $additional_data['user_uid']])->first();
            $user_id = $ent_user->id;
            $Main->notify_devices($message, array($user_id), true);
            if($ent_user->type == 'patient'){
                $this->SysUsers->updateAll(
                    ['steps' => 'HOME'], ['id' => $user_id]
                );
            }
            return;
        } else {
            $this->loadModel('SpaLiveV1.DataConsultation');
            $this->loadModel('SpaLiveV1.DataCertificates');
            $this->loadModel('SpaLiveV1.SysUsers');

            $ent_consultation = $this->DataConsultation->find()->where(['uid' => $additional_data['consultation_uid']])->first();

            if(empty($ent_consultation)){
                return;
            }

            $this->DataConsultation->updateAll(
                [
                    'status' => 'CANCEL', 
                    'assitance' => $data['provider_name'], 
                    'notes' => $data['reason'],
                ],
                ['id' => $ent_consultation->id]
            );

            $Main = new MainController();
            $message = 'Your GFE has been cancelled.';
            $ent_user = $this->SysUsers->find()->where(['uid' => $additional_data['user_uid']])->first();
            $user_id = $ent_user->id;
            $Main->notify_devices($message, array($user_id), true);
            if($ent_user->type == 'patient'){
                $this->SysUsers->updateAll(
                    ['steps' => 'HOME'], ['id' => $user_id]
                );
            }
            return;
        }

        return;
    }

    public function start_consultation_ot(){
        $this->loadModel('SpaLiveV1.DataConsultation'); 
        $this->loadModel('SpaLiveV1.DataPayment'); 
        $this->loadModel('SpaLiveV1.DataConsultationAnswers');
        
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

        $training_id = get('training_id', 0);

        if($training_id == 0){
            $this->message('Invalid training id.');
            return;
        }

        $this->loadModel('SpaLiveV1.CatTrainings');
        $this->loadModel('SpaLiveV1.DataCoverageCourses');

         $this->loadModel('SpaLiveV1.SysUsers');
        
        // Verificar el tipo de usuario
        $ent_user = $this->SysUsers->find()
            ->select(['SysUsers.type'])
            ->where(['SysUsers.id' => USER_ID])
            ->first();
        
        $is_patient = !empty($ent_user) && $ent_user->type == 'patient';
        
        // Construir la condición de available según el tipo de usuario
        $available_condition = $is_patient ? ['CCT.available IN' => [0, 1]] : ['CCT.available' => 1];

        
        $ent_training = $this->CatTrainings->find()
        ->select(['CatTrainings.id', 'CCT.id', 'CCT.title', 'CCT.gfe_id'])
        ->join([
            'CCT' => ['table' => 'cat_courses_type', 'type' => 'INNER', 'conditions' => 'CCT.name_key = CatTrainings.level']
        ])
        ->where(array_merge(['CatTrainings.id' => $training_id, 'CCT.deleted' => 0], $available_condition))->first();
        
        if(empty($ent_training)){
            $this->message('You need to complete the training.');
            return;
        }

        $ent_data_coverage_courses = $this->DataCoverageCourses->find()
        ->select(['DataCoverageCourses.ot_id', 'CT.id'])
        ->join([
            'CT' => ['table' => 'cat_treatments', 'type' => 'INNER', 'conditions' => 'CT.other_treatment_id = DataCoverageCourses.ot_id']
        ])
        ->where(['DataCoverageCourses.course_type_id' => $ent_training['CCT']['id']])->all();

        if(count($ent_data_coverage_courses) == 0){
            $this->message('You need to complete the training.');
            return;
        }

        $treatments_array = [];
        $treatments = '';

        foreach($ent_data_coverage_courses as $ent_data_coverage_course){
            $treatments_array[] = $ent_data_coverage_course['CT']['id'];
        }

        $treatments = implode(',', $treatments_array);

        $gfe_id = $ent_training['CCT']['gfe_id'];

        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $consultation_uid = Text::uuid();

            $schedule_by = 0;
            $schedule_date = get('schedule_date','');
            if (!empty($schedule_date)) {
                $schedule_by = USER_ID;
            }
            
            if (empty($schedule_date)) 
                $schedule_date = date('Y-m-d H:i:s');

            $createdby = USER_ID;
            $patient_id = USER_ID;
            
            $this->loadModel('SpaLiveV1.SysUsers');

            $Main = new MainController();
            $available = $Main->gfeAvailability($schedule_date);

            if (!$available) {
                $this->success(false);
                $this->message("Our good faith examiners are available Monday-Saturday from 8 a.m - 8 p.m. If you have reached us outside of our business hours, holidays, etc., please feel free to schedule your exam for a specific date and time or reach back out to us during business hours OR (reconnect with us during business hours) Thank you!");
                $this->set('not_available', true);
                return false;
            }

            $ent_payment = $this->DataPayment->find()
                                ->where(['DataPayment.id_from' => $patient_id, 'DataPayment.id_to' => 0,'DataPayment.type' => 'GFE OT', 
                                        'DataPayment.service_uid' => '','DataPayment.payment <>' => '','DataPayment.prepaid' => 1,
                                        'DataPayment.comission_payed' => 1])->first();
            
            if(empty($ent_payment)){
                $this->message('You need to pay for the GFE.');
                return;
            }

            $array_save = array(
                'uid' => $consultation_uid,
                'patient_id' => $patient_id,
                'assistance_id' => 0,
                'treatments' => $treatments,
                'payment' => '',
                'meeting' => '',
                'meeting_pass' => '',
                'schedule_date' => $schedule_date,
                'status' => "INIT",
                'schedule_by' => $schedule_by,
                'deleted' => 0,
                'participants' => 0,
                'createdby' => $createdby,
                'clinic_patient_id' => $patient_id,
                'payment_method' => get('payment_method',''),
                'language' => get('language','ENGLISH'),
                'state' => USER_STATE,
                'course_type_id' => $ent_training['CCT']['id'],
            );

            $r = $this->generate_meeting_ot($gfe_id, array('consultation_uid' => $consultation_uid, 'user_uid' => USER_UID));

            if($r['http_code'] == 200){
                $array_save['meeting'] = $r['patient_exams'][0]['patient_exam_id'];
                $array_save['meeting_pass'] = $r['meeting_uuid'];
                $array_save['join_url'] = $r['meeting_url'];

            } else {
                $this->message($r['http_code']);
                $this->message($r['error_message']);
                return;
            }

            $c_entity = $this->DataConsultation->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                if ($this->DataConsultation->save($c_entity)) {

                    $this->set('meeting_url', $r['meeting_url']);
                    $this->set('uid', $consultation_uid);
                    $this->success();
                }
            }

        } else {
            // Schedule
            $this->loadModel('SpaLiveV1.SysUsers');

            $consultation_id = $this->DataConsultation->uid_to_id($consultation_uid);
            if (!$consultation_id) {
                $this->message('consultation_uid not found.');
                return;
            }

            $schedule_date = get('schedule_date','');
            if (empty($schedule_date)) {
                $this->message('Invalid schedule date..');
                return;
            }

            $array_save = array(
                'id' => $consultation_id,
                'schedule_date' => $schedule_date,
                'schedule_by' => USER_ID,
                'status' => 'INIT',
                'participants' => 0,
                'is_waiting' => 0,
                'language' => get('language','ENGLISH'),
            );

            $c_entity = $this->DataConsultation->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                if ($this->DataConsultation->save($c_entity)) {
                    $this->success();
                }
            }
        }
    }

    public function check_gfe_ot(){

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

        $this->loadModel('SpaLiveV1.DataConsultation');
        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.CatCoursesType');

        $training_id = get('training_id', 0);

        $ent_cat_courses_type = $this->CatCoursesType->find()
        ->select(['CatCoursesType.id', 'CatCoursesType.gfe_id', 'CatCoursesType.title', 'CT.id', 'CatCoursesType.name_key'])
        ->join([
            'CT' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CT.level = CatCoursesType.name_key']
        ])
        ->where(['CT.id' => $training_id])->first();

        if(empty($ent_cat_courses_type)){
            $this->message('Invalid course type.');
            return;
        }

        $gfe_id = $ent_cat_courses_type->gfe_id;
        
        $ent_payment = $this->DataPayment->find()
        ->where(['DataPayment.id_from' => USER_ID, 'DataPayment.id_to' => 0,'DataPayment.type' => 'GFE OT', 
                    'DataPayment.service_uid' => '','DataPayment.payment <>' => '','DataPayment.prepaid' => 1,
                    'DataPayment.comission_payed' => 1])
        ->first();

        $ent_certificate = $this->DataConsultation->find()->select(['DataConsultation.id', 'DataConsultation.status', 'DC.proceed', 'DataConsultation.uid'])
        ->join([
                'DC' => ['table' => 'data_consultation_plan', 'type' => 'LEFT', 'conditions' => 'DC.consultation_id = DataConsultation.id AND DC.deleted = 0'],
            ])
        ->where(['DataConsultation.patient_id' => USER_ID, 'DataConsultation.treatments' => $ent_cat_courses_type->id, 'DataConsultation.deleted' => 0])->first();

        $require_gfe = false;
        $require_payment = true;
        if(empty($ent_certificate)){
            $require_gfe = true;
        }

        if($require_gfe){
            $require_payment = empty($ent_payment) ? true : false;
        }else{
            $require_payment = false;
        }

        $this->set('crossed_price', '$79');
        $this->set('text_price', 'Online Medical Exam    $79.00');
        $this->set('text_discount', 'Special Discount         -$40.00');
        $this->set('text_total', 'New Price Just $39.00');

        $this->set('require_gfe', $require_gfe);
        $this->set('request_payment', $require_payment);
        $this->set('amount', empty($ent_payment) ? $this->total : $ent_payment->total);
        $this->set('available', true);
        $this->success();
    }
}