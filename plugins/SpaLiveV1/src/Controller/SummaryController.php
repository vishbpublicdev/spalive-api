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

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PhpOffice\PhpSpreadsheet\Calculation\TextData\Replace;
use SpaLiveV1\Controller\MainController;

use SpaLiveV1\Controller\CourseController;
use SpaLiveV1\Controller\SubscriptionController;
use SpaLiveV1\Controller\Data\TreatmentsHelper;
use SpaLiveV1\Controller\Data\ServicesHelper;
use SpaLiveV1\Controller\Data\NeuroLevel3AccessHelper;

use Cake\Log\Log;

class SummaryController extends AppPluginController{

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
    private $emergencyPhone = "(469) 277 0897, (847) 477 5791";//"9035301512";
    private $emergencyPhone2 = "(847) 477 5791,(812) 322 8388";// "8474775791";
    private $total_subscriptionmsl = 3995;
    private $total_subscriptionmd = 17900;


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

    public function get_dob() {
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

        $tre_ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $user['user_id']])->first();

        // Convertir a timestamp y luego formatear
        $timestamp = strtotime(strval($tre_ent_user['dob']));
        $fechaFormateada = date("m/d/Y", $timestamp);
        // Convertir la fecha al formato 'dd/mm/aaaa'
        if(!empty($tre_ent_user)){
            $this->set('dob', $fechaFormateada);
            $this->success();
        }


    }
    
    public function summary_patient() {
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataConsultation');
        $this->loadModel('SpaLiveV1.DataCertificates');
        $this->loadModel('SpaLiveV1.CatLabels');
        $this->loadModel('SpaLiveV1.CatCITreatments');

        $this->loadModel('SpaLiveV1.DataAgreements');
        $this->loadModel('SpaLiveV1.catAgreements');
        
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

        $this->set('text_after_gfe', "If your exam was completed correctly, your examiner will process the GFE.\n\nOnce it's finalized, you will receive a notification.\n\nThe GFE will also be available in the Good Faith Exams section of this app, which you can find by opening the menu button of this app.\n\nIf your exam did not complete correctly, please try again.");

        //treatments controller para separar los tratamientos
        $Treatments = new TreatmentsController();
            
        $this->set('emergencyPhone', $this->emergencyPhone);
        $patient_consent = false;
        $patient_consent_iv_therapy = false;
        $patient_treatment_type = 'none';
        $this->set('treatment_type', '');
        $tre_ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $user['user_id']])->first();
        if(!empty($tre_ent_user))
            $this->set('treatment_type', $tre_ent_user->treatment_type );
        if(empty($tre_ent_user->dob)){
            $this->set('dob', date("d/m/Y", strtotime(strval('2000-01-01'))  ));
        }else{
            $this->set('dob', date("d/m/Y", strtotime(strval($tre_ent_user->dob))  ));
        }
            
        $userType = $user['user_role'];

        $has_payment_issues = false;
        $payment_issue_message = '';

        $ent_consent_cat = $this->catAgreements
            ->find()
            ->where([
                'catAgreements.user_type' => 'PATIENT', 
                'catAgreements.agreement_type' => 'IVTHERAPHY',
                'catAgreements.deleted' => 0
            ])->first();  

        $ent_consents = $this->DataAgreements
        ->find()
        ->where([
            'DataAgreements.user_id' => USER_ID, 
            'DataAgreements.file_id IS NOT' => 0,
            'DataAgreements.deleted' => 0
        ])->all();  

        $consentsFormatted = [];
        if(!empty($ent_consents)){
            foreach($ent_consents as $consent){
                //$this->set('signature_id', $appointment['signature_id']);
                //$this->set('file_id', $consent['file_id']);
                if($consent['agreement_uid'] == $ent_consent_cat['uid']){
                    $consent['type'] = 'IV THERAPY';
                    $this->set('yes', 'yes');
                } else {
                    $consent['type'] = 'OTHER';
                }
                $consentsFormatted[] = $consent;
            }
        }

        $this->set('conscentsIV', $consentsFormatted);

        $this->loadModel('SpaLiveV1.DataSubscriptionMethodPayments'); 
        $ent_payments = $this->DataSubscriptionMethodPayments
            ->find()    
            ->select()
            ->where([
                'DataSubscriptionMethodPayments.user_id' => USER_ID, 
                'DataSubscriptionMethodPayments.deleted' => 0, 
                'DataSubscriptionMethodPayments.error' => 1
            ])->toArray();   
        $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));        
        $oldCustomer = $stripe->customers->all([
            "email" => $user['email'],
            "limit" => 1,
        ]);

        if (count($oldCustomer) != 0) {
            $customer = $oldCustomer->data[0];
            $payment_methods = $stripe->customers->allPaymentMethods(
                $customer->id,
                ['type' => 'card']
            );
        }else{
            $payment_methods = array();
        }
        
        if(empty($payment_methods)){
            $has_payment_issues = true;
            $payment_issue_message = 'Your app needs to have an associated payment method to function.';
        }else if(!empty($ent_payments)){
            $has_payment_issues = true;
            $payment_issue_message = 'Payment error. Your payment didn\'t go through; please add a new payment method.';
        }

        $this->set('has_payment_issues', $has_payment_issues);
        $this->set('payment_issue_message_1', $ent_payments);        
        $this->set('payment_issue_message', $payment_issue_message);        

        $this->loadModel('SpaLiveV1.DataMessages');
        $c_count = $this->DataMessages->find()->select(['DataMessages.id'])->where(["DataMessages.deleted" => 0,'DataMessages.id_to' => USER_ID, 'DataMessages.readed' => 0])->count();
        $this->set('unread_messages', $c_count);

        // PENDING AGREEMENTS *****************

        $pending_agreements_array = array();
        $this->set('pending_agreements', array());

        $uid_agreement_register = $this->requestRegisterAgreement();
        if (!empty($uid_agreement_register)) $pending_agreements_array[] = $uid_agreement_register;

        $this->set('pending_agreements', $pending_agreements_array);

        if(strtoupper($userType) == 'PATIENT'){

            $str_now = date('Y-m-d H:i:s');
            $str_query_scheduled = "
                SELECT 
                    DC.uid, DC.status, DC.meeting, DC.meeting_pass,CONCAT(U.`name`,\" \",U.lname) patient,CONCAT(UA.`name`,\" \",UA.lname) assistance,U.state, DC.schedule_date, DC.treatments, UA.uid assistance_uid,
                    (SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DC.treatments)) treatments
                FROM data_consultation DC
                JOIN sys_users U ON U.id = DC.patient_id
                LEFT JOIN sys_users UA ON UA.id = DC.assistance_id
                
                WHERE DC.deleted = 0 AND DC.patient_id = " . USER_ID . " AND DC.status = 'INIT' AND DC.schedule_by > 0
                AND TIMESTAMPDIFF(MINUTE, DC.schedule_date, '{$str_now}') <= 15 "; //AND DC.schedule_date > NOW()" ;

            $ent_scheduled = $this->DataConsultation->getConnection()->execute($str_query_scheduled)->fetchAll('assoc');

            $arr_scheduled = array();
            if(!empty($ent_scheduled)){
               foreach ($ent_scheduled as $row) {
                    //JWT for Flutter Web
                    $iat = time();
                    $exp = $iat + 60 * 60;
                    $sdkKey = env('ZOOM_SDK_KEY','');
                    $sdkSecret = env('ZOOM_SDK_SECRET','');
                    $payload = [
                        'sdkKey' => $sdkKey,
                        'mn'=> trim($row['meeting']), //meet number
                        'role' =>  0,
                        'iat' =>  $iat,
                        'exp' =>  $exp,
                        'appKey' =>  $sdkKey,
                        'tokenExp' =>  $exp
                    ];

                    $jwt = JWT::encode($payload, $sdkSecret, 'HS256');
                    $arr_scheduled[] = array(
                        'uid' => $row['uid'],
                        'treatment_uid' => $row['uid'],
                        'meeting' => $row['meeting'],
                        'meeting_pass' => $row['meeting_pass'],
                        'jwt' => !empty($jwt) ? $jwt : '',
                        'schedule_date' => $row['schedule_date'],
                        'treatments' => $row['treatments'],
                        'assistance' => $row['assistance'] ? $row['assistance'] : '',
                        'assistance_uid' => $row['assistance_uid'] ? $row['assistance_uid'] : '',
                        'status' => $row['status'],
                    );
                }
            }
            $this->set('scheduled_evaluations', $arr_scheduled);
            
            //Certificates

            $fields = ['DataConsultation.uid','DataConsultation.payment','DataConsultation.schedule_date','DataCertificates.uid','DataCertificates.date_start','DataCertificates.date_expiration','DataCertificates.certificate_url'];
            $fields['assistance'] = "(SELECT UP.name FROM sys_users UP WHERE UP.id = DataConsultation.assistance_id)";
            $fields['expirate_soon'] = "(IF(DATEDIFF(NOW(), DataCertificates.date_expiration) < 30,1,0))";

            $_where = ['DataConsultation.deleted' => 0];
            $_where['DataConsultation.patient_id'] = USER_ID;
            // $_where['DataConsultation.status'] = "DONE";
            $_where['DataConsultation.status'] = "CERTIFICATE";
            $_where['DataConsultation.treatments <>'] = "";
            // $_where['OR'] = [['DataConsultation.status' => "DONE"], ['DataConsultation.status' => "CERTIFICATE"]];

            $certItem = $this->DataConsultation->find()->select($fields)
            ->join([
                'DataCertificates' => ['table' => 'data_certificates', 'type' => 'INNER', 'conditions' => 'DataCertificates.consultation_id = DataConsultation.id'],
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
                        'date_expiration' => empty($row->DataCertificates['date_expiration']) ? "2029-01-01" : $row->DataCertificates['date_expiration'],
                        'assistance_name' => isset($row['assistance']) ? $row['assistance'] : '',
                        'expirate_soon' => false,//isset($row['expirate_soon']) ? ($row['expirate_soon'] == 1 ? true : false) : '',
                        'certificate_url' => $row->DataCertificates['certificate_url'],
                    );
                }
                $this->set('certificates', $arr_certificates);
            }

            //Treatments
            $this->loadModel('SpaLiveV1.DataTreatment');

            $fields = ['DataTreatment.uid','DataTreatment.treatments','DataTreatment.schedule_date','DataTreatment.status','DataTreatment.address','DataTreatment.zip','DataTreatment.city','DataTreatment.suite','DataTreatment.notes','DataTreatment.reviewed_patient'];
            $fields['assistance'] = "(SELECT CONCAT(' ', U.name, ' ', SUBSTRING(U.lname, 1, 1)) FROM sys_users U WHERE U.id = DataTreatment.assistance_id)";
            $fields['assistance_info'] = "(SELECT CONCAT_WS('||', U.uid, U.short_uid) FROM sys_users U WHERE U.id = DataTreatment.assistance_id)";
            $fields['txt_state'] = "(SELECT name FROM cat_states CS WHERE CS.id = DataTreatment.state LIMIT 1)";
            $_where = ['DataTreatment.deleted' => 0];
            // $_where['DataTreatment.status !='] = "DONE";
            $_where['DataTreatment.patient_id'] = USER_ID;
            // $_where['DataTreatment.status !='] = "CANCEL";
            $_where['OR'] = [['DataTreatment.status' => "INIT"], ['DataTreatment.status' => "INVITATION"], ['DataTreatment.status' => "APPROVE"],['DataTreatment.status' => "CONFIRM"]];
            // $_where[] = "DataTreatment.schedule_date > DATE_ADD(DATE(NOW()), INTERVAL -2 HOUR)";
           
            $certTreatment = $this->DataTreatment->find()->select($fields)->where($_where)->order(['DataTreatment.id' => 'DESC'])->all();
                            
            $arr_treatments = array();
            $treatment_invitations = array();
            if (!empty($certTreatment)) {
                foreach ($certTreatment as $row) {
                    $str_address = $row['address'] . ', ' . $row['city'] . ', ' . $row['txt_state'] . ' ' . $row['zip'];
                    if (!empty($row['suite'])) {
                        $str_address = $row['address'] . ', ' . $row['suite'] . ', ' . $row['city'] . ', ' . $row['txt_state'] . ' ' . $row['zip'];
                    }
                    $str_inj_info = explode('||',$row['assistance_info']);
                    
                    //$TH = new \SpaLiveV1\Controller\Data\TreatmentsHelper($row['id']);
                    $trits = $row['treatments'];
                    $cuttedtrits = explode(',', $trits);
                    $typetreat = 'No IV';
                    //
                    
                    /*if($row['treatments'] > 1000){
                        $typetreat = 'IV THERAPY';
                    }*/
                    
                    $temp_arr = array(
                        'treatment_uid' => $row['uid'],
                        'notes' => $row['notes'],
                        'schedule_date' => $row['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm'),
                        'assistance' => $row['assistance'],
                        'assistance_uid' => $str_inj_info[0],
                        'assistance_shortuid' => $str_inj_info[1],
                        'status' => $row['status'],
                        'address' => $str_address,
                        'reviewed_patient' => $row['reviewed_patient'],
                        //'treatments' => $certTreatment,
                        'types' => $typetreat
                    );
                    if( $row['status'] == 'INVITATION'){
                        $treatment_invitations[] = $temp_arr;
                    }else{
                        $arr_treatments[] = $temp_arr;
                    }
                }
                $this->set('treatment_invitations', $treatment_invitations);
                $this->set('scheduled_treatments', $arr_treatments);
            }

            //Reviews
            $count_invitations = 0;
            if(Count($treatment_invitations) > 0){
                foreach($treatment_invitations as $invitations){
                    if($invitations['reviewed_patient'] == 0){
                        $count_invitations++;
                    }
                }
                if($count_invitations > 0){
                    $this->set('invitation_review', array('show' => true, 'invitations' => $count_invitations));

                }else{
                    $this->set('invitation_review', array('show' => false, 'invitations' => $count_invitations));
                }
            }else{
                $this->set('invitation_review', array('show' => false, 'invitations' => $count_invitations));
            }

            //PENDING REVIEW

            $_usr_id = USER_ID;
            $trWithRevw = $this->DataTreatment->getConnection()->execute("SELECT DTR.treatment_id FROM data_treatment_reviews DTR JOIN data_treatment DT ON DT.id = DTR.treatment_id WHERE DT.patient_id = {$_usr_id}")->fetchAll('assoc');
            $fields = ['DataTreatment.id',
                       'DataTreatment.uid',
                       'DataTreatment.assistance_id',
                       'DataTreatment.patient_id',
                       'DataTreatment.treatments',
                       'DataTreatment.type_uber',
                       'DataTreatment.schedule_date',
                       'DataTreatment.status',
                       'DataTreatment.address',
                       'DataTreatment.zip',
                       'DataTreatment.city',
                       'DataTreatment.suite',
                       'DataTreatment.tip'];
            $fields['assistance'] = "(SELECT CONCAT(' ', U.name, ' ', SUBSTRING(U.lname, 1, 1)) FROM sys_users U WHERE U.id = DataTreatment.assistance_id)";
            $fields['assistance_info'] = "(SELECT CONCAT_WS('||', U.uid, U.short_uid) FROM sys_users U WHERE U.id = DataTreatment.assistance_id)";
            $fields['txt_state'] = "(SELECT name FROM cat_states CS WHERE CS.id = DataTreatment.state LIMIT 1)";

            $fields['treatments_string'] = "(SELECT GROUP_CONCAT(CONCAT(CTC.name,' (',CT.name, ')') ,' (',CTC.type, ')' SEPARATOR ', ') 
                                                FROM cat_treatments_ci CT 
                                                JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
                                                WHERE FIND_IN_SET(CT.id,DataTreatment.treatments) LIMIT 1)";
            
            $fields['treatments_string_id'] = "(SELECT GROUP_CONCAT(CT.id) 
                                                FROM cat_treatments_ci CT 
                                                JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
                                                WHERE FIND_IN_SET(CT.id,DataTreatment.treatments))";

            $_where = ['DataTreatment.deleted' => 0, 'DataTreatment.status' => "DONE", 'DataTreatment.patient_id' => USER_ID, 'DataTreatment.payment <>' => ''];
            if(isset($trWithRevw) && !empty($trWithRevw)){
                $_where['DataTreatment.id NOT IN'] = Hash::extract($trWithRevw, '{n}.treatment_id');
            }
            $this->loadModel('SpaLiveV1.DataPayment');
            $treatNoReviews = $this->DataTreatment->find()->select($fields)->where($_where)->toArray();
    
            foreach ($treatNoReviews as $item) {
                
                $ent_payment = $this->DataPayment->find()->where(['uid' => $item->uid, 'type' => 'TREATMENT'])->first();
                $total_amount = $ent_payment->total;

                if($item->type_uber == 1){
                    $ent_treatments = $this->CatCITreatments->find()->select(['CatCITreatments.id','CatCITreatments.name','CatCITreatments.details', 'CatCITreatments.std_price', 'Cat.name', 'CatCITreatments.category_treatment_id'])
                    ->join([
                        'Cat' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'Cat.id = CatCITreatments.category_treatment_id'],
                    ])
                    ->where(['CatCITreatments.id IN' => explode(',', $item->treatments)]);
                    
                    $array_list = array();
                    $iv_list = array();
                    $neuro_list = array();
                    $filler_list = array();
                    foreach($ent_treatments as $_row){
                        if($_row->name == 'Let my provider choose' || $_row->name == 'Let my provider help me decide' || $_row->name == 'No preference'){
                            if($_row->id == 999){$_row['Cat']['name'] = 'Basic Neurotoxins'; $_row->category_treatment_id = 1;}
                            if($_row->id == 1033){ $filler_list[] = 'Fillers'; continue; }
                            $ent_treatments2 = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.std_price', 'CatCITreatments.name', 'CatCITreatments.category_treatment_id'])->join([
                                'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id AND CatCITreatments.name <> CTC.name'],
                            ])->where(['CatCITreatments.deleted' => 0, 'CatCITreatments.name NOT IN' => array('Let my provider help me decide', 'Let my provider choose', 'No preference') ,'CatCITreatments.category_treatment_id' => $_row->category_treatment_id])->all();
                            $array_prices = array();
                            foreach ($ent_treatments2 as $key => $trea) {
                                $array_prices[] = $trea['name'];
                            }
                            $neuro_list[] = $_row['Cat']['name'];
                        }else{
                            if($_row['Cat']['name'] == 'IV Therapy' || $_row['Cat']['name'] == 'IV'){
                                $iv_list[] = $_row->name;
                            }else{
                                $neuro_list[] = $_row->name == $_row['Cat']['name'] ? $_row->name : $_row['Cat']['name'];
                            }
                        }
                    }

                    $add_iv_button = false;
                    $array_seeprices = array();
                    $string_iv = implode(', ', $iv_list);
                    $string_neuro = implode(', ', $neuro_list);
                    $string_filler = implode(', ', $filler_list);
                    $string_treatments = '';

                    $array_treat = explode(',', $item->treatments);
                    foreach($array_treat as $key => $value){
                        if($value == 0){
                            $string_iv = 'No preference';
                        }
                    }

                    //type treatment
                    $typeTreat = '';
                    $ntTypeFound = false;
                    $ivTypeFound = false;

                    $array_string_treatments = array();
                    if(!empty($string_neuro)){
                        $array_string_treatments[] = $string_neuro;
                        $typetreat = 'NEUROTOXINS';
                        $ntTypeFound = true;
                    }

                    if(!empty($string_filler)){
                        $array_string_treatments[] = $string_filler;
                        $typetreat = 'NEUROTOXINS';
                        $ntTypeFound = true;
                    }
                    
                    if(!empty($string_iv)){
                        $array_string_treatments[] = 'IV(' . $string_iv . ')';

                        $typetreat = 'IV THERAPY';
                        $ivTypeFound = true;
                    }

                    if($ntTypeFound){
                        $typeTreat = 'NT';
                    }
                    if($ivTypeFound){
                        $typeTreat = 'IV';
                    }
                    if($ivTypeFound && $ntTypeFound){
                        $typeTreat = 'NT+IV';
                    }

                    $string_treatments = implode(', ', $array_string_treatments);

                    $sep_neuro = $string_neuro;
                    $sep_iv = $string_iv;
                }else{
                    //type treatment
                    $typeTreat = '';
                    $ntTypeFound = false;
                    $ivTypeFound = false;

                    $ent_treatments = $this->CatCITreatments->find()->select(['CatCITreatments.id','CatCITreatments.name','CatCITreatments.details', 'CatCITreatments.std_price', 'Cat.name', 'CatCITreatments.category_treatment_id'])
                    ->join([
                        'Cat' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'Cat.id = CatCITreatments.category_treatment_id'],
                    ])
                    ->where(['CatCITreatments.id IN' => explode(',', $item->treatments)]);
                    
                    $array_list = array();
                    foreach($ent_treatments as $row){
                        if($row->name == 'Let my provider choose' || $row->name == 'Let my provider help me decide' || $row->name == 'No preference'){
                            if($row->id == 999){$row['Cat']['name'] = 'Basic Neurotoxins'; $row->category_treatment_id = 1;}
                            $ent_treatments2 = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.std_price', 'CatCITreatments.name', 'CatCITreatments.category_treatment_id'])->join([
                                'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id AND CatCITreatments.name <> CTC.name'],
                            ])->where(['CatCITreatments.deleted' => 0, 'CatCITreatments.name NOT IN' => array('Let my provider help me decide', 'Let my provider choose', 'No preference') ,'CatCITreatments.category_treatment_id' => $row->category_treatment_id])->all();
                            $array_prices = array();
                            foreach ($ent_treatments2 as $key => $trea) {
                                $array_prices[] = $trea['name'];
                            }
                            $array_list[] = $row['Cat']['name'] . ' (' . implode(', ', $array_prices) . ')';
                        }else{
                            $array_list[] = $row->name == $row['Cat']['name'] ? $row->name . ' (' . $row->details . ')' : $row['Cat']['name'] . ' ('. $row->name . ' ' . $row->details . ')';
                        }
                    }

                    $string_treatments = implode(', ', $array_list);
                    //foreach ($cuttedtrits as $numtrits) {
                    $string = $item->treatments_string;
                    $elementos = explode(',', $string);

                    foreach ($elementos as $num) {                        
                        if (strpos($num, 'IV THERAPY') !== false) {
                            $typetreat = 'IV THERAPY';
                            $ivTypeFound = true;
                        } 

                        if (strpos($num, 'NEUROTOXINS') !== false) {
                            $typetreat = 'NEUROTOXINS';
                            $ntTypeFound = true;

                        }
                    }

                    if($ntTypeFound){
                        $typeTreat = 'NT';
                    }
                    if($ivTypeFound){
                        $typeTreat = 'IV';
                    }
                    if($ivTypeFound && $ntTypeFound){
                        $typeTreat = 'NT+IV';
                    }

                    $separate_treatments = $Treatments->separate_treatments($item->treatments_string,$item->treatments_string_id,$item->assistance_id);

                    $separate_treatments["neurotoxins"] = str_replace('No preference (Let my provider choose)', '', $separate_treatments["neurotoxins"]);

                    $sep_neuro = $separate_treatments["neurotoxins"];
                    $sep_iv = $separate_treatments["iv_therapy"];
                }
                //}

                $item['schedule_date'] = $item->schedule_date->i18nFormat('yyyy-MM-dd HH:mm');
                $item['score'] = 50;
                $item['type'] = $typeTreat;
                $item['total_amount'] = !empty($total_amount) ? $total_amount : 0;
                $item['treatments'] = $sep_neuro;
                $item['treatments_iv'] = $sep_iv;
                $item['treatments_string'] = $string_treatments;
            }

            $this->set('pending_review', $treatNoReviews);

            //PENDING PAYMENT TREATMENTS

            $fieldstra = ['DataTreatment.uid',
                          'DataTreatment.schedule_date',
                          'DataTreatment.amount',
                          'DataTreatment.treatments',
                          'DataTreatment.promo_code',
                          'DataTreatment.assistance_id',
                          'User.name', 
                          'User.lname'];
            
            $fieldstra['treatments_string'] = "(SELECT GROUP_CONCAT(CONCAT(CTC.name,' (',CT.name, ')') SEPARATOR ', ') 
                                                FROM cat_treatments_ci CT 
                                                JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
                                                WHERE FIND_IN_SET(CT.id,DataTreatment.treatments) LIMIT 1)";
            
            $fieldstra['treatments_string_id'] = "(SELECT GROUP_CONCAT(CT.id) 
                                                FROM cat_treatments_ci CT 
                                                JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
                                                WHERE FIND_IN_SET(CT.id,DataTreatment.treatments))";

            $payment_pending_arr = $this->DataTreatment->find()->select($fieldstra)
            ->join([
                'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataTreatment.assistance_id'],
            ])
            ->where([
                'DataTreatment.patient_id' => USER_ID, 
                'DataTreatment.status' => 'DONE', 
                'DataTreatment.request_payment' => 1, 
                'DataTreatment.payment' => '', 
                'DataTreatment.deleted' => 0
                ]);
            if(!empty($payment_pending_arr)){
                $payment_pending = array();
                foreach ($payment_pending_arr as $entTreatmentPatient) {
                    // $str_query_scheduled = "
                    //     SELECT GROUP_CONCAT(CT.name) product_name, GROUP_CONCAT(DISTINCT CTC.`name`) treatment, GROUP_CONCAT(DISTINCT CTC.type) type 
                    //     FROM cat_treatments_ci CT 
                    //     JOIN cat_treatments_category CTC ON CT.category_treatment_id = CTC.id
                    //     WHERE FIND_IN_SET(CT.id,'".$entTreatmentPatient->treatments."')";

                    // $details = $this->CatCITreatments->getConnection()->execute($str_query_scheduled)->fetchAll('assoc');

                    $separate_treatments = $Treatments->separate_treatments($entTreatmentPatient->treatments_string,$entTreatmentPatient->treatments_string_id,$entTreatmentPatient->assistance_id);

                    $payment_pending[]  = array(
                        'uid' => $entTreatmentPatient->uid,
                        'date' => $entTreatmentPatient->schedule_date->i18nFormat('MM-dd-yyyy HH:mm'),
                        'amount' => $entTreatmentPatient->amount,
                        'injector' => $entTreatmentPatient['User']['name'] . ' ' . $entTreatmentPatient['User']['lname'],
                        'treatments' => $separate_treatments["neurotoxins"],
                        'treatments_iv' => $separate_treatments["iv_therapy"],
                        'promo_code' => $entTreatmentPatient->promo_code,
                        // 'product_name' => $details[0]['product_name'],
                        // 'treatment' => $details[0]['treatment'],
                        // 'type' => $details[0]['type']
                    );
                }
                $this->set('pending_payment_treatment', $payment_pending);
            }            

            // PENDING TREATMENTS ACCEPT ******************
            $this->loadModel('SpaLiveV1.DataClaimTreatments');
            $this->loadModel('SpaLiveV1.CatCITreatments');

            $now = date('Y-m-d H:i:s');

            $extra_fields = ['DataTreatment.id','DataTreatment.uid','DataTreatment.schedule_date','DataTreatment.status','DataTreatment.assistance_id','DataTreatment.request_payment','DataTreatment.payment','DataTreatment.treatments','DataTreatment.patient_id', 
            'DataTreatment.deleted', 'State.name', 'State.id','DataTreatment.address', 'DataTreatment.city', 'DataTreatment.zip',  'DataTreatment.suite', 'DataTreatment.created', 'DataTreatment.review_open_home', 'Injector.name', 'Injector.lname', 'Injector.id', 'Injector.uid', 'Patient.name', 'Patient.lname', 'Patient.id', 'Patient.uid','Injector.phone'];
            $extra_fields['treatments_string'] = "(SELECT GROUP_CONCAT(CT.name) FROM cat_treatments_ci CT WHERE FIND_IN_SET(CT.id,DataTreatment.treatments))";
            $extra_fields['treatments_string_id'] = "(SELECT GROUP_CONCAT(CT.id) 
                                            FROM cat_treatments_ci CT 
                                            JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
                                            WHERE FIND_IN_SET(CT.id,DataTreatment.treatments))";

            $ent_treatment_pending = $this->DataTreatment->find()->select($extra_fields)
            ->join([
                'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
                'Patient' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Patient.id = DataTreatment.patient_id AND Patient.deleted = 0'],
                'Injector' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'Injector.id = DataTreatment.assistance_id AND Injector.deleted = 0'],
            ])
            ->where([
                'DataTreatment.patient_id' => USER_ID,
                'DataTreatment.status IN ("PETITION", "CANCEL")', 
                'DataTreatment.type_uber' => 1, 
                'DataTreatment.request_payment' => 0,
                'DataTreatment.payment' => '', 
                'DataTreatment.deleted' => 0
            ])->all();
            
            $arr_treatments_uber = array();
            $agreements   = $this->get_agreements_patient(USER_ID);
            $certificates = $this->getCertificatesUser(USER_ID);
            
            if(Count($ent_treatment_pending) > 0){
                foreach($ent_treatment_pending as $row){

                    if($now > $row['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm:ss') || $row['status'] == 'CANCEL'){
                        $card_title = 'Choose a Certified Associate';
                        $select_ci = true;
                    }else{
                        $card_title = 'Pending for Associate Assignment';
                        $select_ci = false;
                    }

                    $ent_treatments = $this->CatCITreatments->find()->select(['CatCITreatments.id','CatCITreatments.name','CatCITreatments.details', 'CatCITreatments.std_price', 'Cat.name', 'CatCITreatments.category_treatment_id'])
                    ->join([
                        'Cat' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'Cat.id = CatCITreatments.category_treatment_id'],
                    ])
                    ->where(['CatCITreatments.id IN' => explode(',', $row->treatments)]);
                    
                    $array_list = array();
                    $iv_list = array();
                    $neuro_list = array();
                    $filler_list = array();
                    $other_list = array();
                    foreach($ent_treatments as $_row){
                        if($_row->name == 'Let my provider choose' || $_row->name == 'Let my provider help me decide' || $_row->name == 'No preference'){
                            if($_row->id == 999){$_row['Cat']['name'] = 'Basic Neurotoxins'; $_row->category_treatment_id = 1;}
                            if($_row->id == 1033){ $filler_list[] = 'Fillers'; continue; }
                            $ent_treatments2 = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.std_price', 'CatCITreatments.name', 'CatCITreatments.category_treatment_id'])->join([
                                'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id AND CatCITreatments.name <> CTC.name'],
                            ])->where(['CatCITreatments.deleted' => 0, 'CatCITreatments.name NOT IN' => array('Let my provider help me decide', 'Let my provider choose', 'No preference') ,'CatCITreatments.category_treatment_id' => $_row->category_treatment_id])->all();
                            $array_prices = array();
                            foreach ($ent_treatments2 as $key => $trea) {
                                $array_prices[] = $trea['name'];
                            }
                            $neuro_list[] = $_row['Cat']['name'];
                        }else{
                            if($_row['Cat']['name'] == 'IV Therapy' || $_row['Cat']['name'] == 'IV'){
                                $iv_list[] = $_row->name;
                            }else if($_row['Cat']['name'] == 'Other Treatments'){
                                $other_list[] = $_row->name;
                            }else{
                                $neuro_list[] = $_row->name == $_row['Cat']['name'] ? $_row->name : $_row['Cat']['name'];
                            }
                        }
                    }

                    $add_iv_button = false;
                    $array_seeprices = array();
                    $string_iv = implode(', ', $iv_list);
                    $string_neuro = implode(', ', $neuro_list);
                    $string_filler = implode(', ', $filler_list);
                    $string_other = implode(', ', $other_list);
                    $string_treatments = '';

                    $array_treat = explode(',', $row->treatments);
                    foreach($array_treat as $key => $value){
                        if($value == 0){
                            $string_iv = 'No preference';
                        }
                    }

                    $array_string_treatments = array();
                    if(!empty($string_neuro)){
                        $array_string_treatments[] = $string_neuro;
                        $add_iv_button = true;

                        $ent_neuro_price = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.std_price', 'CatCITreatments.name', 'CatCITreatments.details', 'CatCITreatments.category_treatment_id'])
                        ->where(['CatCITreatments.deleted' => 0, 'CatCITreatments.id IN' => array(1,2,4,73,74,75,76)])->all();

                        $neuro_price = array();

                        foreach ($ent_neuro_price as $key => $price) {
                            if($price['category_treatment_id'] == 1){
                                $neuro_price[] = $price['name'] .'(basic treatments): $' . ($price['std_price'] / 100) . ' ' . $price['details'];

                            }else{
                                $neuro_price[] = $price['name'] .': $' . ($price['std_price'] / 100) . ' ' . $price['details'];
                            }
                        }

                        $array_seeprices = array(
                            array('category' => 'Neurotoxins', 'prices' => $neuro_price),
                        );
                    }

                    if(!empty($string_filler)){
                        $array_string_treatments[] = $string_filler;

                        /*$ent_neuro_price = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.std_price', 'CatCITreatments.name', 'CatCITreatments.details', 'CatCITreatments.category_treatment_id'])
                        ->where(['CatCITreatments.deleted' => 0, 'CatCITreatments.id IN' => array(1,2,4,73,74,75,76)])->all();

                        $neuro_price = array();

                        foreach ($ent_neuro_price as $key => $price) {
                            if($price['category_treatment_id'] == 1){
                                $neuro_price[] = $price['name'] .'(basic treatments): $' . ($price['std_price'] / 100) . ' ' . $price['details'];

                            }else{
                                $neuro_price[] = $price['name'] .': $' . ($price['std_price'] / 100) . ' ' . $price['details'];
                            }
                        }

                        $array_seeprices = array(
                            array('category' => 'Neurotoxins', 'prices' => $neuro_price),
                        );*/
                    }
                    
                    if(!empty($string_iv)){
                        $array_string_treatments[] = 'IV(' . $string_iv . ')';

                        $ent_iv_price = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.std_price', 'CatCITreatments.name', 'CatCITreatments.details', 'CatCITreatments.category_treatment_id', 'Price.price'])
                        ->join([
                            'Price' => ['table' => 'data_treatments_prices', 'type' => 'INNER', 'conditions' => 'Price.treatment_id = CatCITreatments.id'],
                            'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id'],
                        ])->where(['CatCITreatments.deleted' => 0, 'Price.user_id' => USER_ID, 'Price.deleted' => 0, 'CTC.type_uber' => 'IV THERAPY'])->all();

                        $iv_price = array();

                        foreach ($ent_iv_price as $key => $price) {
                            $iv_price[] = $price['name'] . ': $' . ($price['Price']['price'] / 100) . ' ' . $price['details'];
                        }

                        $array_seeprices = array(
                            array('category' => 'IV', 'prices' => $iv_price),
                        );
                    }

                    if(!empty($string_other)){
                        $array_string_treatments[] = $string_other;
                    }

                    $string_treatments = implode(', ', $array_string_treatments);

                    $sstr_address = $row['address'] . ', ' . $row['city'] . ', ' . $row['State']['name'] . ' ' . $row['zip'];
                    if (!empty($row['suite'])) {
                        $sstr_address = $row['address'] . ', ' . $row['suite'] . ', ' . $row['city'] . ', ' . $row['State']['name'] . ' ' . $row['zip'];
                    }

                    // Gfe Required
                    $this->loadModel('SpaLiveV1.CatTreatmentsCi');
                    $array_treatmets = array();
                    $treatments = explode(',', $row['treatments']);
                    foreach($treatments as $id){                                                
                        $ent_treatment = $this->CatTreatmentsCi->find()->select(['CatTreatmentsCi.name', 'CT.name', 'DCP.id', 'CT.id'])
                        ->join([
                            'CT' => ['table' => 'cat_treatments', 'type' => 'INNER', 'conditions' => 'CatTreatmentsCi.treatment_id = CT.id'],
                            'DC' => ['table' => 'data_consultation', 'type' => 'LEFT', 'conditions' => 'DC.deleted = 0 AND DC.status = "CERTIFICATE" AND DC.patient_id = ' . USER_ID],
                            'DCE' => ['table' => 'data_certificates', 'type' => 'LEFT', 'conditions' => 'DCE.consultation_id = DC.id AND DCE.deleted = 0 AND DCE.date_expiration > ' . date('Y-m-d')],
                            'DCP' => ['table' => 'data_consultation_plan', 'type' => 'LEFT', 'conditions' => 'DCP.treatment_id = CatTreatmentsCi.treatment_id AND DCP.proceed = 1 AND DCP.deleted = 0 AND DCP.consultation_id = DC.id']
                        ])->where(['CatTreatmentsCi.id' => $id])->first();
                        
                        if(!empty($ent_treatment)){
                            if($ent_treatment['DCP']['id'] == null || empty($ent_treatment['DCP']['id'])){
                                $value = in_array($ent_treatment['CT']['id'], array_column($array_treatmets, 'id'));
    
                                if($value){
                                    continue;
                                } 
                                $array_treatmets[] = array(
                                    'id' => $ent_treatment['CT']['id'],
                                    'name' => $ent_treatment['CT']['name'],
                                );
                            }
                        }                                                                                             
                    }

                    $require_gfe  = Count($array_treatmets) > 0;                 

                    // patient consent
                    $sign_agreement = false;

                    $this->loadModel('SpaLiveV1.Agreement');
                    $this->loadModel('SpaLiveV1.CatAgreements');
                    $this->loadModel('SpaLiveV1.DataTreatmentsPrice');

                    $ent_agreement = $this->CatAgreements->find()->select(['CatAgreements.uid','DataAgreement.id','DataAgreement.deleted'])->join([
                            'DataAgreement' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'CatAgreements.uid = DataAgreement.agreement_uid AND DataAgreement.user_id = ' . $row['patient_id'] . ' AND DataAgreement.deleted = 0'],
                        ])->where(
                        [
                            'CatAgreements.state_id' => $row['State']['id'],
                            'CatAgreements.user_type' => 'patient',
                            'CatAgreements.agreement_type' => 'REGISTRATION',
                            'CatAgreements.deleted' => 0,
                        ]
                    )->first();
                    if (!empty($ent_agreement) && empty($ent_agreement['DataAgreement']['id'])) {
                        $sign_agreement = true;
                        $patient_consent = true;
                    }

                    $patient_consent_toxin = false;
                    $ent_agreement_toxin = $this->CatAgreements->find()->select(['CatAgreements.uid','DataAgreement.id','DataAgreement.uid','DataAgreement.user_id','DataAgreement.deleted'])->join([
                        'DataAgreement' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'CatAgreements.uid = DataAgreement.agreement_uid AND DataAgreement.user_id = ' . $row['patient_id'] . ' AND DataAgreement.deleted = 0'],
                    ])->where(
                    [
                        'CatAgreements.state_id' => 43,
                        'CatAgreements.user_type' => 'PATIENT',
                        'CatAgreements.agreement_type' => 'REGISTRATION',
                        'CatAgreements.deleted' => 0,
                        'DataAgreement.deleted' => 0,
                    ]
                    )->all();
                    $this->set('patient_id', $row['patient_id']);
                    $this->set('USER_ID', USER_ID);
                    $this->set('ent_agreement_toxin', $ent_agreement_toxin);
                    if (empty($ent_agreement_toxin)) {
                        $this->set('cicle', 'noent');
                        $patient_consent_toxin = false;
                        $patient_uid_consent_toxin = '';
                    }else{
                        foreach($ent_agreement_toxin as $agree){
                            //encontrar id paciente
                            $this->set('cicle', 'ent');
                            if($row['patient_id'] == $agree['DataAgreement']['user_id']){
                                $patient_uid_consent_toxin = $agree['DataAgreement']['uid'];
                                $patient_consent_toxin = true;
                            }                   

                        }
                        
                    }

                    if($patient_consent_toxin == false){
                        $patient_uid_consent_toxin = '';

                    }

                    // Fillers agreement

                    $patient_consent_fillers = false;
                    $patient_uid_consent_fillers = '';

                    $ent_agreement_fillers = $this->CatAgreements->find()->select(['CatAgreements.uid','DataAgreement.id','DataAgreement.uid','DataAgreement.user_id','DataAgreement.deleted'])->join([
                        'DataAgreement' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'CatAgreements.uid = DataAgreement.agreement_uid AND DataAgreement.user_id = ' . $row['patient_id'] . ' AND DataAgreement.deleted = 0'],
                    ])->where(
                    [
                        'CatAgreements.state_id' => 43,
                        'CatAgreements.user_type' => 'PATIENT',
                        'CatAgreements.agreement_type' => 'FILLERS',
                        'CatAgreements.deleted' => 0,
                        'DataAgreement.deleted' => 0,
                        'DataAgreement.user_id' => USER_ID,
                    ]
                    )->first();

                    if (empty($ent_agreement_fillers)) {
                        $patient_consent_fillers = false;
                    }else{
                        $patient_consent_fillers = true;
                        $patient_uid_consent_fillers = $ent_agreement_fillers['DataAgreement']['uid'];
                    }

                    
                    //IV therapy agreement
                    $patient_agreement_uid = "";
                    $patient_consent_iv_therapy = false;

                    $ent_agreement_iv = $this->CatAgreements->find()->select(['CatAgreements.uid','DataAgreement.id','DataAgreement.uid','DataAgreement.deleted'])->join([
                        'DataAgreement' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'CatAgreements.uid = DataAgreement.agreement_uid AND DataAgreement.user_id = ' . $row['patient_id'] . ' AND DataAgreement.deleted = 0'],
                    ])->where(
                    [
                        'CatAgreements.state_id' => 43,
                        'CatAgreements.user_type' => 'PATIENT',
                        'CatAgreements.agreement_type IN' => ['IVTHERAPHY'],
                        'CatAgreements.deleted' => 0,
                        'DataAgreement.deleted' => 0,
                    ]
                    )->first();
                    //$this->set('ent_agreement_iv',$ent_agreement_iv);
                    if (empty($ent_agreement_iv) && empty($ent_agreement_iv['DataAgreement']['id'])) {
                        $patient_consent_iv_therapy = false;
                    }else{
                        $patient_consent_iv_therapy = true;
                        $patient_agreement_uid = $ent_agreement_iv['DataAgreement']['uid'];
                    }

                    $_fields = ['DataTreatmentsPrice.price','DataTreatmentsPrice.treatment_id','Treatments.name','Treatments.qty', 'Treatments.treatment_id'];
                    $_fields['certificate'] = "(SELECT DC.uid
                            FROM cat_treatments_ci CTC
                            JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                            JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                            JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                            WHERE CTC.deleted = 0 AND CTC.id = DataTreatmentsPrice.treatment_id AND DCP.proceed = 1 AND NOW() < DC.date_expiration AND DCO.patient_id = " . $row['patient_id'] . "
                            LIMIT 1)";
                    $_fields['consultation'] = "(SELECT DC.uid
                            FROM cat_treatments_ci CTC
                            JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                            JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                            JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                            WHERE CTC.deleted = 0 AND CTC.id = DataTreatmentsPrice.treatment_id AND NOW() < DC.date_expiration AND DCO.patient_id = " . $row['patient_id'] . "
                            ORDER BY DCO.modified DESC
                            LIMIT 1)";
                    $_fields['certificate_status'] = "(
                    SELECT DC.status FROM data_consultation DC 
                    WHERE FIND_IN_SET(  (SELECT CTpt.parent_id FROM cat_treatments CTpt WHERE CTpt.id = Treatments.treatment_id LIMIT 1)
                        , DC.treatments) AND DC.status = 'DONE' AND DC.patient_id = ".$row['patient_id']." AND DC.deleted = 0 LIMIT 1)";

                    $ent_prices = $this->DataTreatmentsPrice->find()->select($_fields)->join([
                        'Treatments' => ['table' => 'cat_treatments_ci', 'type' => 'INNER', 'conditions' => 'Treatments.id = DataTreatmentsPrice.treatment_id'],
                    ])->where(['DataTreatmentsPrice.deleted' => 0, 'FIND_IN_SET(DataTreatmentsPrice.treatment_id, "' . $row['treatments'] . '")' ,'DataTreatmentsPrice.user_id' => $row['assistance_id']])->all();

                    $data_tr = array();

                    if (!empty($ent_prices)) {
                        foreach ($ent_prices as $_row) {
                            $data_tr[] = array(
                                'name' => $_row['Treatments']['name'],
                                'treatment_id' => intval($_row['treatment_id']),
                                'notes' => $_row['notes'],
                                'price' => intval($_row['price']),
                                'qty' => intval($_row['Treatments']['qty']),
                                'certificate' => !empty($_row['certificate']) ? $_row['certificate'] : '',
                                'consultation' => !empty($_row['consultation']) ? $_row['consultation'] : '',
                                'certificate_status' => !empty($_row['certificate_status']) ? $_row['certificate_status'] : '',
                            );
                        }
                    }

                    $str_query_scheduled = "
                        SELECT GROUP_CONCAT(CT.name) product_name, GROUP_CONCAT(DISTINCT CTC.`name`) treatment, GROUP_CONCAT(DISTINCT CTC.type) type, SUM(CT.std_price) total
                        FROM cat_treatments_ci CT 
                        JOIN cat_treatments_category CTC ON CT.category_treatment_id = CTC.id
                        WHERE FIND_IN_SET(CT.id,'".$row['treatments']."')";

                    $ent_scheduled = $this->CatCITreatments->getConnection()->execute($str_query_scheduled)->fetchAll('assoc');
                    
                    //$cancelDate = date('Y-m-d H:i:s', strtotime($row['created']->i18nFormat('yyyy-MM-dd HH:mm:ss'). ' + 1 day'));  
                    //$cancelAllowed = $cancelDate < date('Y-m-d H:i:s');

                    // Categorias para gfe y consents vieja
                    /*$cats_treatment = $this->CatTreatmentsCi->find()
                    ->select(['name' => 'CTC.type'])
                    ->join([
                        'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatTreatmentsCi.category_treatment_id'],
                    ])
                    ->where(['CatTreatmentsCi.id IN ('.$row['treatments'].')'])
                    ->group(['CTC.type'])
                    ->toArray();

                    foreach($cats_treatment as $cat){
                        if($cat['name'] == 'NEUROTOXINS BASIC' || $cat['name'] == 'NEUROTOXINS ADVANCED'){
                            if(!in_array('NEUROTOXINS', $cats_treatment_arr)){
                                $cats_treatment_arr[] = 'NEUROTOXINS';
                            }
                        }else{
                            $cats_treatment_arr[] = $cat['name'];
                        }
                    }*/

                    $cats_treatment_arr = [];
                    
                    // Categorias para gfe y consents nueva
                    if(!empty($string_neuro)){
                        $cats_treatment_arr[] = 'NEUROTOXINS';
                    }
                    
                    if(!empty($string_filler)){
                        $cats_treatment_arr[] = 'FILLERS';
                    } 
                    
                    if(!empty($string_iv)){
                        $cats_treatment_arr[] = 'IV THERAPY';
                    }

                    if(count($other_list) > 0){
                        foreach($other_list as $other){
                            $cats_treatment_arr[] = $other;
                        }
                    }

                    $schedule_date = $row->schedule_date->i18nFormat('yyyy-MM-dd HH:mm');
                    $fechaInicial = date('Y-m-d H:m');//'2023-01-01 00:00'; now
                    $fechaFinal =   $schedule_date;            // seconds
                    $fechaInicialSegundos = strtotime($fechaInicial);
                    $fechaFinalSegundos = strtotime($fechaFinal);
                    //
                    $hrs = ($fechaFinalSegundos - $fechaInicialSegundos) / 3600;//hrs //86400 days;
                    if($hrs >= 24){
                        $charge_for_cancel = '';
                    }else{
                        $charge_for_cancel = 'You are canceling your appointment within 24 hours of the treatment and will be charged a $50 Cancellation fee.';
                    }
                    $separate_treatments = $Treatments->separate_treatments($row['treatments_string'],$row['treatments_string_id']);

                    $arr_treatments_uber[] = array(
                        'trinf' => '',
                        'uid' => $row->uid,
                        'review_open_home' => $row->review_open_home,
                        'latitude' => doubleval($row->latitude),
                        'longitude' => doubleval($row->longitude),
                        'patient_uid' => $row['Patient']['uid'],
                        'assistance_uid' => $row['Injector']['uid'],
                        'schedule_date' => $row->schedule_date,
                        'status' => $row->status,
                        'gfe_status' => $this->gfeStatusForTreatment(USER_ID, $row->id), 
                        'distance_in_mi' => $row->latitude > 0 ? round($row->distance_in_mi,1) : 0,
                        'address' => $sstr_address,
                        'patient' => $row['Patient']['name'] . ' ' . $row['Patient']['lname'],
                        'provider' => $row['Injector']['name'] . ' ' . $row['Injector']['lname'],
                        'treatments' => $separate_treatments["neurotoxins"],
                        'treatments_iv' => $separate_treatments["iv_therapy"],
                        'treatments_filler' => $separate_treatments["fillers"],
                        'treatments_string' => $string_treatments /*$row->treatments_string*/,
                        'treatments_data' => $ent_scheduled,
                        'signature_id' => $row->signature_id,
                        'treatments_detail' => $data_tr,
                        'out_reach' => ($row->latitude > 0 && round($row->distance_in_mi,1) > $user['user_radius'] ) ? 1 : 0,
                        'sign_patient' => $sign_agreement,
                        'number' => $row['Injector']['phone'],
                        'number_label' => !empty($row['Injector']['phone']) ? $this->formatPhoneNumber($row['Injector']['phone']) : '',
                        'charge_for_cancel' => $charge_for_cancel,
                        'hrs' => $hrs,
                        'hr_server' => $fechaInicial,
                        'patient_consent_toxin' => $patient_consent_toxin,
                        'patient_consent_iv_therapy' => $patient_consent_iv_therapy,
                        'patient_consent_fillers' => $patient_consent_fillers,
                        'patient_agreement_uid' => $patient_agreement_uid,
                        'patient_uid_consent_toxin' => $patient_uid_consent_toxin,
                        'patient_uid_consent_fillers' => $patient_uid_consent_fillers,
                        // 'type' => $typetreat,
                        'treatment_requirements' => $this->treatment_requirements_patients($cats_treatment_arr, $agreements, $certificates, USER_ID),
                        'gfe_message' => '12 hours before starting your treatment, your GFE needs to be approved and your consent form needs to be signed or the appointment will be cancelled.',
                        'card_title' => $card_title,
                        'select_ci' => $select_ci,
                        'add_iv_button' => $add_iv_button,
                        'see_prices' => $array_seeprices,
                    );
                }
            }

            $this->set('pending_uber_treatment', $arr_treatments_uber);

            $this->set('pending_treatment_accept', []);

            // FORCE HOME OPEN REQUEST

            if(Count($arr_treatments_uber) > 0 && $tre_ent_user->treatment_type == 'ONEBYONE'){
                $this->set('force_home_open', true);
            }else{
                $this->set('force_home_open', false);
            }

            //Show icon Alert

            if(Count($arr_treatments_uber) > 0){
                foreach ($arr_treatments_uber as $key => $value) {
                    if($value['review_open_home'] == 1){
                        $this->set('show_icon_alert', true);
                        break;
                    } else{
                        $this->set('show_icon_alert', false);
                    }
                }
            }else{
                $this->set('show_icon_alert', false);
            }


            // REQUESTED APPOINTMENTS (Patient to Provider)

            $fields2 = ['DataTreatment.id','DataTreatment.uid','DataTreatment.schedule_date','DataTreatment.status','DataTreatment.address','DataTreatment.treatments','DataTreatment.state','State.name','DataTreatment.patient_id', 'DataTreatment.assistance_id','DataTreatment.city','DataTreatment.zip','DataTreatment.suite','DataTreatment.latitude','DataTreatment.longitude','Patient.name','Patient.lname','Patient.uid','DataTreatment.notes', 'Provider.uid', 'Provider.name', 'Provider.lname',];
            $fields2['treatments_string'] = "(SELECT GROUP_CONCAT(CT.name) FROM cat_treatments_ci CT WHERE FIND_IN_SET(CT.id,DataTreatment.treatments))";
            
            $fields2['treatments_string_id'] = "(SELECT GROUP_CONCAT(CT.id) 
                                            FROM cat_treatments_ci CT 
                                            JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
                                            WHERE FIND_IN_SET(CT.id,DataTreatment.treatments))";

            $_where2 = ['DataTreatment.deleted' => 0, 'DataTreatment.status IN ("REQUEST", "STOP")', 'DataTreatment.patient_id' => USER_ID, '(DATE_FORMAT(DataTreatment.schedule_date, "%Y-%m-%d 23:59:59") > "' . $now . '")',];

            $reqAppntmntsEnt = $this->DataTreatment->find()->select($fields2)->join([
                'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
                'Patient' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Patient.id = DataTreatment.patient_id AND Patient.deleted = 0'],
                'Provider' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Provider.id = DataTreatment.assistance_id AND Provider.deleted = 0'],
            ])->where($_where2)->order(['DataTreatment.schedule_date' => 'ASC'])->toArray();                        

            $this->loadModel('SpaLiveV1.DataTreatmentsPrice');

            $requestedAppntmnts = [];
            foreach($reqAppntmntsEnt as $index => $certTreatment){

                $_fields = ['DataTreatmentsPrice.price','DataTreatmentsPrice.treatment_id','Treatments.name','Treatments.qty', 'Treatments.treatment_id'];
                $_fields['certificate'] = "(SELECT DC.uid
                        FROM cat_treatments_ci CTC
                        JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                        JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                        JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                        WHERE CTC.deleted = 0 AND CTC.id = DataTreatmentsPrice.treatment_id AND DCP.proceed = 1 AND NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                        LIMIT 1)";
                $_fields['consultation'] = "(SELECT DC.uid
                        FROM cat_treatments_ci CTC
                        JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                        JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                        JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                        WHERE CTC.deleted = 0 AND CTC.id = DataTreatmentsPrice.treatment_id AND NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                        ORDER BY DCO.modified DESC
                        LIMIT 1)";
                $_fields['certificate_status'] = "(
                SELECT DC.status FROM data_consultation DC 
                WHERE FIND_IN_SET(  (SELECT CTpt.parent_id FROM cat_treatments CTpt WHERE CTpt.id = Treatments.treatment_id LIMIT 1)
                    , DC.treatments) AND DC.status = 'DONE' AND DC.patient_id = ".$certTreatment->patient_id." AND DC.deleted = 0 LIMIT 1)";

                $ent_prices = $this->DataTreatmentsPrice->find()->select($_fields)->join([
                    'Treatments' => ['table' => 'cat_treatments_ci', 'type' => 'INNER', 'conditions' => 'Treatments.id = DataTreatmentsPrice.treatment_id'],
                ])->where(['DataTreatmentsPrice.deleted' => 0, 'FIND_IN_SET(DataTreatmentsPrice.treatment_id, "' . $certTreatment->treatments . '")' ,'DataTreatmentsPrice.user_id' => $certTreatment->assistance_id])->all();
                
                $sign_agreement = false;

                $this->loadModel('SpaLiveV1.Agreement');
                $this->loadModel('SpaLiveV1.SysUsers');
                $this->loadModel('SpaLiveV1.CatAgreements');

                $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $certTreatment->patient_id])->first();
            
                $ent_agreement = $this->CatAgreements->find()->select(['CatAgreements.uid','DataAgreement.id','DataAgreement.deleted'])->join([
                        'DataAgreement' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'CatAgreements.uid = DataAgreement.agreement_uid AND DataAgreement.user_id = ' . $certTreatment->patient_id . ' AND DataAgreement.deleted = 0'],
                    ])->where(
                    [
                        'CatAgreements.state_id' => $certTreatment->state,
                        'CatAgreements.user_type' => 'patient',
                        'CatAgreements.agreement_type' => 'REGISTRATION',
                        'CatAgreements.deleted' => 0,
                    ]
                )->first();
                if (!empty($ent_agreement) && empty($ent_agreement['DataAgreement']['id'])) {
                    $sign_agreement = true;
                }

                $data_tr = array();

                if (!empty($ent_prices)) {
                    foreach ($ent_prices as $row) {
                        $data_tr[] = array(
                            'name' => $row['Treatments']['name'],
                            'treatment_id' => intval($row['treatment_id']),
                            'notes' => $row['notes'],
                            'price' => intval($row['price']),
                            'qty' => intval($row['Treatments']['qty']),
                            'certificate' => !empty($row['certificate']) ? $row['certificate'] : '',
                            'consultation' => !empty($row['consultation']) ? $row['consultation'] : '',
                            'certificate_status' => !empty($row['certificate_status']) ? $row['certificate_status'] : '',
                        );
                    }
                }
                $sstr_address = $certTreatment->address . ', ' . $certTreatment->city . ', ' . $certTreatment['State']['name'] . ' ' . $certTreatment->zip;
                if (!empty($certTreatment->suite)) {
                    $sstr_address = $certTreatment->address . ', ' . $certTreatment->suite . ', ' . $certTreatment->city . ', ' . $certTreatment['State']['name'] . ' ' . $certTreatment->zip;
                }

                $str_query_scheduled = "
                    SELECT GROUP_CONCAT(CT.name) product_name, GROUP_CONCAT(DISTINCT CTC.`name`) treatment, GROUP_CONCAT(DISTINCT CTC.type) type 
                    FROM cat_treatments_ci CT 
                    JOIN cat_treatments_category CTC ON CT.category_treatment_id = CTC.id
                    WHERE FIND_IN_SET(CT.id,'".$certTreatment->treatments."')";

                $ent_scheduled = $this->CatCITreatments->getConnection()->execute($str_query_scheduled)->fetchAll('assoc');

                $separate_treatments = $Treatments->separate_treatments($certTreatment->treatments_string,$certTreatment->treatments_string_id,$certTreatment->assistance_id);

                $re_array = array(
                    'uid' => $certTreatment->uid,
                    'latitude' => doubleval($certTreatment->latitude),
                    'longitude' => doubleval($certTreatment->longitude),
                    'patient_uid' => $certTreatment['Patient']['uid'],
                    'provider_uid' => $certTreatment['Provider']['uid'],
                    'schedule_date' => $certTreatment->schedule_date,
                    'status' => $certTreatment->status,
                    'distance_in_mi' => $certTreatment->latitude > 0 ? round($certTreatment->distance_in_mi,1) : 0,
                    'address' => $sstr_address,
                    'patient' => $certTreatment['Patient']['name'] . ' ' . $certTreatment['Patient']['lname'],
                    'provider' => $certTreatment['Provider']['name'] . ' ' . $certTreatment['Provider']['lname'],
                    'treatments' => $separate_treatments['neurotoxins'],
                    'treatments_iv' => $separate_treatments['iv_therapy'],
                    'treatments_data' => $ent_scheduled,
                    'treatments_detail' => $data_tr,
                    'out_reach' => ($certTreatment->latitude > 0 && round($certTreatment->distance_in_mi,1) > $user['user_radius'] ) ? 1 : 0,
                    'sign_patient' => $sign_agreement,
                );

                $requestedAppntmnts[] = $re_array;
            }
            $this->set('requested_appointments', array());

            $this->success();
            $ent_agreement_toxin = $this->CatAgreements->find()->select(['CatAgreements.uid','DataAgreement.id','DataAgreement.deleted'])->join([
                'DataAgreement' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'CatAgreements.uid = DataAgreement.agreement_uid AND DataAgreement.user_id = ' . USER_ID . ' AND DataAgreement.deleted = 0'],
            ])->where(
            [
                'CatAgreements.state_id' => 43,
                'CatAgreements.user_type' => 'patient',
                'CatAgreements.agreement_type' => 'REGISTRATION',
                'CatAgreements.deleted' => 0,
            ]
            )->first();
            if (!empty($ent_agreement_toxin) && empty($ent_agreement_toxin['DataAgreement']['id'])) {
                $patient_consent_toxin = false;
            }else{
                $patient_consent_toxin = true;
            }
            $ent_agreement_iv = $this->CatAgreements->find()->select(['CatAgreements.uid','DataAgreement.id','DataAgreement.deleted'])->join([
                'DataAgreement' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'CatAgreements.uid = DataAgreement.agreement_uid AND DataAgreement.user_id = ' . USER_ID . ' AND DataAgreement.deleted = 0'],
            ])->where(
            [
                'CatAgreements.state_id' => 43,
                'CatAgreements.user_type' => 'patient',
                'CatAgreements.agreement_type' => 'IVTHERAPHY',
                'CatAgreements.deleted' => 0,
            ]
            )->first();
            $this->set('ent_agreement_iv',$ent_agreement_iv);
            if (!empty($ent_agreement_iv) && empty($ent_agreement_iv['DataAgreement']['id'])) {
                $patient_consent_iv_therapy = false;
            }else{
                $patient_consent_iv_therapy = true;
            }
        
        }

        // ACTUAL APPOINTMENT

        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.DataTreatmentsPrice');
        $this->loadModel('SpaLiveV1.CatTreatmentsCi');

        $fields = ['DataTreatment.id','DataTreatment.uid','DataTreatment.signature_id','DataTreatment.schedule_date','DataTreatment.status','DataTreatment.address','DataTreatment.treatments','DataTreatment.state','State.name','DataTreatment.patient_id', 'DataTreatment.city','DataTreatment.zip','DataTreatment.suite','DataTreatment.latitude','DataTreatment.longitude','Patient.name','Patient.lname','Patient.uid','Injector.latitude','Injector.longitude','Injector.uid','Injector.id','Injector.name','Injector.lname','Injector.phone','DataTreatment.notes','DataTreatment.type_uber','DataTreatment.assistance_id'];

        /* $fields['treatments_string'] = "(SELECT GROUP_CONCAT(CONCAT(CTC.name,' (',CT.name, ')') SEPARATOR ', ') 
                                                FROM cat_treatments_ci CT 
                                                JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
                                                WHERE FIND_IN_SET(CT.id,DataTreatment.treatments) LIMIT 1)"; */

        $fields['treatments_string'] = "(SELECT GROUP_CONCAT( IF(CTC.name = CT.name,CONCAT(CT.name),CONCAT(CTC.name,' (',CT.name, ')')) SEPARATOR ', ')
                                                FROM cat_treatments_ci CT 
                                                JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
                                                WHERE FIND_IN_SET(CT.id,DataTreatment.treatments) LIMIT 1)";

        $fields['treatments_string_id'] = "(SELECT GROUP_CONCAT(CT.id) 
                                                FROM cat_treatments_ci CT 
                                                JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
                                                WHERE FIND_IN_SET(CT.id,DataTreatment.treatments))";

        $fields['distance_in_mi'] = "(69.09 * DEGREES(ACOS(LEAST(1.0, COS(RADIANS(DataTreatment.latitude))
                                    * COS(RADIANS(Injector.latitude))
                                    * COS(RADIANS(DataTreatment.longitude - Injector.longitude))
                                    + SIN(RADIANS(DataTreatment.latitude))
                                    * SIN(RADIANS(Injector.latitude))))))";

        $_where = ['DataTreatment.deleted' => 0];
        // $_where['DataTreatment.status'] = "CONFIRM";

        $_where['OR'] = [['DataTreatment.status' => "CONFIRM"], ['DataTreatment.status' => "REQUEST", 'DataTreatment.payment' => ""], ['DataTreatment.status' => "DONE", 'DataTreatment.payment' => ""]];

        $_where['DataTreatment.patient_id'] = USER_ID;
        // $_where[] = "DataTreatment.schedule_date > NOW()";
        // $_where[] = "DataTreatment.schedule_date > DATE_ADD(DATE(NOW()), INTERVAL -2 HOUR)";

        // $certTreatment = $this->DataTreatment->find()->select($fields)->join([
        $certTrtArr = $this->DataTreatment->find()->select($fields)->join([
            'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
            'Patient' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Patient.id = DataTreatment.patient_id AND Patient.deleted = 0'],
            'Injector' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Injector.id = DataTreatment.assistance_id AND Patient.deleted = 0'],
        ])->where($_where)->order(['DataTreatment.schedule_date' => 'ASC'])->toArray();

        $agreements   = $this->get_agreements_patient(USER_ID);
        $certificates = $this->getCertificatesUser(USER_ID);
                        
        $arr_treatments = array();
        $arr_appotments = [];
        // if (!empty($certTreatment)) {
        foreach($certTrtArr as $index => $certTreatment){

            $cats_treatment = $this->CatTreatmentsCi->find()
            ->select(['name' => 'CTC.type', 'name_treatment' => 'CatTreatmentsCi.name'])
            ->join([
                'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatTreatmentsCi.category_treatment_id'],    
            ])
            ->where(['CatTreatmentsCi.id IN ('.$certTreatment->treatments.')'])
            ->group(['CTC.type'])
            ->toArray();      

            //var_dump(highlight_string("<?\n". var_export($certTreatment, true)));
            //exit(1);
            if($certTreatment->type_uber == 1){
                $_fields = ['CatCITreatments.std_price ','CatCITreatments.id','CatCITreatments.name','CatCITreatments.qty', 'CatCITreatments.treatment_id'];
                $_fields['certificate'] = "(SELECT DC.uid
                        FROM cat_treatments_ci CTC
                        JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                        JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                        JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                        WHERE CTC.deleted = 0 AND CTC.id = CatCITreatments.id AND DCP.proceed = 1 AND NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                        LIMIT 1)";
                $_fields['consultation'] = "(SELECT DC.uid
                        FROM cat_treatments_ci CTC
                        JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                        JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                        JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                        WHERE CTC.deleted = 0 AND CTC.id = CatCITreatments.id AND NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                        ORDER BY DCO.modified DESC
                        LIMIT 1)";
                $_fields['certificate_treatments'] = "(SELECT DCO.treatments
                        FROM cat_treatments_ci CTC
                        JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                        JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                        JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                        WHERE CTC.deleted = 0 AND CTC.id = CatCITreatments.id AND DCP.proceed = 1 AND NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                        LIMIT 1)";

                $ent_prices = $this->CatCITreatments->find()->select($_fields)
                ->where(['CatCITreatments.deleted' => 0, 'FIND_IN_SET(CatCITreatments.id, "' . $certTreatment->treatments . '")'])->all();

                $ent_treatments = $this->CatCITreatments->find()->select(['CatCITreatments.id','CatCITreatments.name','CatCITreatments.details', 'CatCITreatments.std_price', 'Cat.name', 'CatCITreatments.category_treatment_id'])
                ->join([
                    'Cat' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'Cat.id = CatCITreatments.category_treatment_id'],
                ])
                ->where(['CatCITreatments.id IN' => explode(',', $certTreatment->treatments)]);
                
                $array_list = array();
                $iv_list = array();
                $neuro_list = array();
                $filler_list = array();
                $other_list = array();
                foreach($ent_treatments as $_row){
                    if($_row->name == 'Let my provider choose' || $_row->name == 'Let my provider help me decide' || $_row->name == 'No preference'){
                        if($_row->id == 999){$_row['Cat']['name'] = 'Basic Neurotoxins'; $_row->category_treatment_id = 1;}
                        if($_row->id == 1033){$filler_list[] = 'Fillers'; continue;}
                        $ent_treatments2 = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.std_price', 'CatCITreatments.name', 'CatCITreatments.category_treatment_id'])->join([
                            'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id AND CatCITreatments.name <> CTC.name'],
                        ])->where(['CatCITreatments.deleted' => 0, 'CatCITreatments.name NOT IN' => array('Let my provider help me decide', 'Let my provider choose', 'No preference') ,'CatCITreatments.category_treatment_id' => $_row->category_treatment_id])->all();
                        $array_prices = array();
                        foreach ($ent_treatments2 as $key => $trea) {
                            $array_prices[] = $trea['name'];
                        }
                        $neuro_list[] = $_row['Cat']['name'];
                    }else{
                        if($_row['Cat']['name'] == 'IV Therapy' || $_row['Cat']['name'] == 'IV'){
                            $iv_list[] = $_row->name;
                        }else if($_row['Cat']['name'] == 'Other Treatments'){
                            $other_list[] = $_row->name;
                        }else{
                            $neuro_list[] = $_row->name == $_row['Cat']['name'] ? $_row->name : $_row['Cat']['name'];
                        }
                    }
                }

                $add_iv_button = false;
                $array_seeprices = array();
                $string_iv = implode(', ', $iv_list);
                $string_neuro = implode(', ', $neuro_list);
                $string_filler = implode(', ', $filler_list);
                $string_other = implode(', ', $other_list);
                $string_treatments = '';

                $array_treat = explode(',', $certTreatment->treatments);
                foreach($array_treat as $key => $value){
                    if($value == 0){
                        $string_iv = 'No preference';
                    }
                }

                $array_string_treatments = array();
                $treatments = "";
                $treatments_filler = "";
                $treatments_iv = "";
                
                if(!empty($string_neuro)){
                    $array_string_treatments[] = $string_neuro;
                    $treatments = $string_neuro;
                    $add_iv_button = true;

                    $ent_neuro_price = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.std_price', 'CatCITreatments.name', 'CatCITreatments.details', 'CatCITreatments.category_treatment_id'])
                    ->where(['CatCITreatments.deleted' => 0, 'CatCITreatments.id IN' => array(1,2,4,73,74,75,76)])->all();

                    $neuro_price = array();

                    foreach ($ent_neuro_price as $key => $price) {
                        if($price['category_treatment_id'] == 1){
                            $neuro_price[] = $price['name'] .'(basic treatments): $' . ($price['std_price'] / 100) . ' ' . $price['details'];

                        }else{
                            $neuro_price[] = $price['name'] .': $' . ($price['std_price'] / 100) . ' ' . $price['details'];
                        }
                    }

                    $array_seeprices = array(
                        array('category' => 'Neurotoxins', 'prices' => $neuro_price),
                    );
                }

                if(!empty($string_filler)){
                    $array_string_treatments[] = $string_filler;
                    $treatments_filler = $string_filler;
                    /*$ent_neuro_price = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.std_price', 'CatCITreatments.name', 'CatCITreatments.details', 'CatCITreatments.category_treatment_id'])
                    ->where(['CatCITreatments.deleted' => 0, 'CatCITreatments.id IN' => array(1,2,4,73,74,75,76)])->all();

                    $neuro_price = array();

                    foreach ($ent_neuro_price as $key => $price) {
                        if($price['category_treatment_id'] == 1){
                            $neuro_price[] = $price['name'] .'(basic treatments): $' . ($price['std_price'] / 100) . ' ' . $price['details'];

                        }else{
                            $neuro_price[] = $price['name'] .': $' . ($price['std_price'] / 100) . ' ' . $price['details'];
                        }
                    }

                    $array_seeprices = array(
                        array('category' => 'Neurotoxins', 'prices' => $neuro_price),
                    );*/
                }
                
                if(!empty($string_iv)){
                    $array_string_treatments[] = 'IV(' . $string_iv . ')';

                    $treatments_iv = 'IV(' . $string_iv . ')';

                    $ent_iv_price = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.std_price', 'CatCITreatments.name', 'CatCITreatments.details', 'CatCITreatments.category_treatment_id', 'Price.price'])
                    ->join([
                        'Price' => ['table' => 'data_treatments_prices', 'type' => 'INNER', 'conditions' => 'Price.treatment_id = CatCITreatments.id'],
                        'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id'],
                    ])->where(['CatCITreatments.deleted' => 0, 'Price.user_id' => USER_ID, 'Price.deleted' => 0, 'CTC.type_uber' => 'IV THERAPY'])->all();

                    $iv_price = array();

                    foreach ($ent_iv_price as $key => $price) {
                        $iv_price[] = $price['name'] . ': $' . ($price['Price']['price'] / 100) . ' ' . $price['details'];
                    }

                    $array_seeprices = array(
                        array('category' => 'IV', 'prices' => $iv_price),
                    );
                }

                if(!empty($string_other)){
                    $array_string_treatments[] = $string_other;
                    //$treatments_other = $string_other;
                }

                $string_treatments = implode(', ', $array_string_treatments);

                $data_tr = array();                
                if (!empty($ent_prices)) {
                    foreach ($ent_prices as $row) {
                        $status = 'EMPTY';
                        if(!empty($row['consultation'])){
                            $status = empty($row['certificate_treatments']) ? 'DENIED' : 'DONE';   
                        }
                        $data_tr[] = array(
                            'name' => $row['name'],
                            'treatment_id' => intval($row['id']),
                            'notes' => $row['notes'],
                            'price' => intval($row['std_price']),
                            'qty' => intval($row['qty']),
                            'certificate' => !empty($row['certificate']) ? $row['certificate'] : '',
                            'consultation' => !empty($row['consultation']) ? $row['consultation'] : '',
                            'certificate_status' => $status,
                        );
                    }
                }
                
                $cats_treatment_arr = [];
                // Categorias para gfe y consents nueva
                if(!empty($string_neuro)){
                    $cats_treatment_arr[] = 'NEUROTOXINS';
                }

                if(!empty($string_filler)){
                    $cats_treatment_arr[] = 'FILLERS';
                }
                
                if(!empty($string_iv)){
                    $cats_treatment_arr[] = 'IV THERAPY';
                }

                if(!empty($string_other)){
                    foreach($other_list as $other){
                        $cats_treatment_arr[] = strtoupper($other);
                    }
                }

            }else{
                $_fields = ['DataTreatmentsPrice.price','DataTreatmentsPrice.treatment_id','Treatments.name','Treatments.qty', 'Treatments.treatment_id'];
                $_fields['certificate'] = "(SELECT DC.uid
                        FROM cat_treatments_ci CTC
                        JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                        JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                        JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                        WHERE CTC.deleted = 0 AND CTC.id = DataTreatmentsPrice.treatment_id AND DCP.proceed = 1 AND NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                        LIMIT 1)";
                $_fields['consultation'] = "(SELECT DC.uid
                        FROM cat_treatments_ci CTC
                        JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                        JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                        JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                        WHERE CTC.deleted = 0 AND CTC.id = DataTreatmentsPrice.treatment_id AND NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                        ORDER BY DCO.modified DESC
                        LIMIT 1)";
                $_fields['certificate_treatments'] = "(SELECT DCO.treatments
                        FROM cat_treatments_ci CTC
                        JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                        JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                        JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                        WHERE CTC.deleted = 0 AND CTC.id = DataTreatmentsPrice.treatment_id AND DCP.proceed = 1 AND NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                        LIMIT 1)";

                $arr_treatments_avai = [];
                $arr_treatments_prices = [];

                if($certTreatment->treatments == 999){

                    $ent_treatments = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.name', 'CatCITreatments.category_treatment_id' , 'CatCITreatments.std_price','CTC.id', 'CTC.name', 'CTC.type_uber'])
                    ->join([
                        'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id'],

                    ])->where(['CatCITreatments.deleted' => 0, 'FIND_IN_SET(CatCITreatments.id, "' . $certTreatment->treatments . '")'])->first();

                    $arr_treatments_prices[] = $ent_treatments['CTC']['name'];

                    $ent_prices = $this->DataTreatmentsPrice->find()->select($_fields)->join([
                        'Treatments' => ['table' => 'cat_treatments_ci', 'type' => 'INNER', 'conditions' => 'Treatments.id = DataTreatmentsPrice.treatment_id'],
                    ])->where(['DataTreatmentsPrice.deleted' => 0, 'FIND_IN_SET(DataTreatmentsPrice.treatment_id, "' . $certTreatment->treatments . '")'])->all();
                    //$this->set('ent_prices_choose', $ent_prices);
                }else{

                    $ent_treatments = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.name', 'CatCITreatments.category_treatment_id' , 'CatCITreatments.std_price','CTC.id', 'CTC.name', 'CTC.type_uber', 'DTP.price'])
                    ->join([
                        'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id'],
                        'DTP' => ['table' => 'data_treatments_prices', 'type' => 'INNER', 'conditions' => 'DTP.user_id = ' . $certTreatment->assistance_id . ' AND CatCITreatments.id = DTP.treatment_id'],
                    ])->where(['CatCITreatments.deleted' => 0, 'FIND_IN_SET(CatCITreatments.id, "' . $certTreatment->treatments . '")'])->all();

                    if(!empty($ent_treatments)){
                        foreach ($ent_treatments as $key => $value) {
                            $array_prices = [];
                            if($value['name' ] == 'Let my provider help me decide' || $value['name' ] == 'Let my provider choose'){
                                $ent_treatments2 = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.std_price', 'CatCITreatments.name', 'DTP.price'])->join([
                                    'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id'],
                                    'DTP' => ['table' => 'data_treatments_prices', 'type' => 'INNER', 'conditions' => 'DTP.user_id = ' . $certTreatment->assistance_id . '  AND CatCITreatments.id = DTP.treatment_id'],
                                ])->where(['CatCITreatments.deleted' => 0, 'CatCITreatments.name <>' => 'Let my provider help me decide' ,'CatCITreatments.category_treatment_id' => $value['category_treatment_id' ] ])->all();
    
                                foreach ($ent_treatments2 as $key => $trea) {
                                    $arr_treatments_avai[] = $trea['id'];
                                    if(count($array_prices) <= 0){
                                        $array_prices[] = $trea['name'] .' $' . ($trea['price'] / 100);
                                    }else{
                                        $array_prices[] = ' ' . $trea['name'] .' $' . ($trea['price'] / 100);
                                    }
                                    
                                }
                            }else {
                                $arr_treatments_avai[] = $value['id'];
                                if(count($array_prices) <= 0){
                                    $array_prices[] = $value['name'] .' $' . ($value['DTP']['price'] / 100);
                                }else{
                                    $array_prices[] = ' ' . $value['name'] .' $' . ($value['DTP']['price'] / 100);
                                }
                                
                            }
                            if($value['name' ] == 'Let my provider help me decide' || $value['name' ] == 'Let my provider choose'){
                                if(count($arr_treatments_prices) <= 0){
                                    $arr_treatments_prices[] = $value['CTC']['name'] . ' (' . $value['name'] . '. Treatment pricing: ' . implode(',', $array_prices) . ')' ;
                                }else{
                                    $arr_treatments_prices[] = ' ' . $value['CTC']['name'] . ' (' . $value['name'] . '. Treatment pricing: ' . implode(',', $array_prices) . ')' ;
                                }
                            }else{
                                if(count($arr_treatments_prices) <= 0){
                                    $arr_treatments_prices[] = $value['CTC']['name'] == $value['name'] ? implode(',', $array_prices) : $value['CTC']['name'] . ' (' . implode(',', $array_prices) . ')' ;
                                }else{
                                    $arr_treatments_prices[] = $value['CTC']['name'] == $value['name'] ? ' ' . implode(',', $array_prices) : ' ' . $value['CTC']['name'] . ' (' . implode(',', $array_prices) . ')' ;
                                }
                                
                            }
                        }
                    }

                    $ent_prices = $this->DataTreatmentsPrice->find()->select($_fields)->join([
                        'Treatments' => ['table' => 'cat_treatments_ci', 'type' => 'INNER', 'conditions' => 'Treatments.id = DataTreatmentsPrice.treatment_id'],
                    ])->where(['DataTreatmentsPrice.deleted' => 0, 'FIND_IN_SET(DataTreatmentsPrice.treatment_id, "' . $certTreatment->treatments . '")' ,'DataTreatmentsPrice.user_id' => $certTreatment['Injector']['id']])->all();
                }

                $data_tr = array();
                if (!empty($ent_prices)) {
                    foreach ($ent_prices as $row) {
                        $status = 'EMPTY';
                        if(!empty($row['consultation'])){
                            $status = empty($row['certificate_treatments']) ? 'DENIED' : 'DONE';
                        }
                        $data_tr[] = array(
                            'name' => $row['Treatments']['name'],
                            'treatment_id' => intval($row['treatment_id']),
                            'notes' => $row['notes'],
                            'price' => intval($row['price']),
                            'qty' => intval($row['Treatments']['qty']),
                            'certificate' => !empty($row['certificate']) ? $row['certificate'] : '',
                            'consultation' => !empty($row['consultation']) ? $row['consultation'] : '',
                            'certificate_status' => $status,
                        );
                    }
                }

                $cats_treatment_arr = [];
                /*foreach($cats_treatment as $cat){
                    if($cat['name'] == 'NEUROTOXINS BASIC' || $cat['name'] == 'NEUROTOXINS ADVANCED'){
                        if(!in_array('NEUROTOXINS', $cats_treatment_arr)){
                            $cats_treatment_arr[] = 'NEUROTOXINS';
                        }
                    }else if($cat['name'] == 'OTHER TREATMENTS'){
                        $cats_treatment_arr[] = $cat['name_treatment'];
                    }else{
                        $cats_treatment_arr[] = $cat['name'];
                    }
                }*/

                $separate_treatments = $Treatments->separate_treatments($certTreatment->treatments_string,$certTreatment->treatments_string_id,$certTreatment->assistance_id);

                $array_treatments = [];

                if(!empty($separate_treatments["neurotoxins"])){
                    $array_treatments[] = $separate_treatments["neurotoxins"];
                    $cats_treatment_arr[] = 'NEUROTOXINS';
                }

                if(!empty($separate_treatments["iv_therapy"])){
                    $array_treatments[] = $separate_treatments["iv_therapy"];
                    $cats_treatment_arr[] = 'IV THERAPY';
                }

                if(!empty($separate_treatments["fillers"])){
                    $array_treatments[] = $separate_treatments["fillers"];
                    $cats_treatment_arr[] = 'FILLERS';
                }

                if(!empty($separate_treatments["other_treatments"])){
                    // limpian el texto de other treatments
                    $treatments_other = $separate_treatments["other_treatments"];
                    $treatments_other = preg_replace('/Other Treatments\s*/', '', $treatments_other); // quita la frase
                    $treatments_other = preg_replace('/[()]/', '', $treatments_other); // quita los paréntesis
                    $array_treatments[] = $treatments_other;
                    $separate_treatments_other = explode(',', $treatments_other);
                    foreach($separate_treatments_other as $treatment){
                        $cats_treatment_arr[] = trim($treatment);
                    }
                }

                $treatments = implode(',', $array_treatments);
                $treatments_iv = '';
                $treatments_filler = '';
            }
            
            $sign_agreement = false;

            $this->loadModel('SpaLiveV1.Agreement');
            $this->loadModel('SpaLiveV1.SysUsers');
            $this->loadModel('SpaLiveV1.CatAgreements');

            $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $certTreatment->patient_id])->first();
        
            $ent_agreement = $this->CatAgreements->find()->select(['CatAgreements.uid','DataAgreement.id','DataAgreement.deleted'])->join([
                    'DataAgreement' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'CatAgreements.uid = DataAgreement.agreement_uid AND DataAgreement.user_id = ' . $certTreatment->patient_id . ' AND DataAgreement.deleted = 0'],
                ])->where(
                [
                    'CatAgreements.state_id' => $certTreatment->state,
                    'CatAgreements.user_type' => 'patient',
                    'CatAgreements.agreement_type' => 'REGISTRATION',
                    'CatAgreements.deleted' => 0,
                ]
            )->first();
            if (!empty($ent_agreement) && empty($ent_agreement['DataAgreement']['id'])) {
                $sign_agreement = true;
            }

            $sstr_address = $certTreatment->address . ', ' . $certTreatment->city . ', ' . $certTreatment['State']['name'] . ' ' . $certTreatment->zip;
            if (!empty($certTreatment->suite)) {
                $sstr_address = $certTreatment->address . ', ' . $certTreatment->suite . ', ' . $certTreatment->city . ', ' . $certTreatment['State']['name'] . ' ' . $certTreatment->zip;
            }

            $str_query_scheduled = "
            SELECT GROUP_CONCAT(CT.name) product_name, GROUP_CONCAT(DISTINCT CTC.`name`) treatment, GROUP_CONCAT(DISTINCT CTC.type) type 
            FROM cat_treatments_ci CT 
            JOIN cat_treatments_category CTC ON CT.category_treatment_id = CTC.id
            WHERE FIND_IN_SET(CT.id,'".$certTreatment->treatments."')";

            $ent_scheduled = $this->CatCITreatments->getConnection()->execute($str_query_scheduled)->fetchAll('assoc');
            $schedule_date = $certTreatment->schedule_date->i18nFormat('yyyy-MM-dd HH:mm');
            $fechaInicial = date('Y-m-d H:m');//'2023-01-01 00:00'; now
            $fechaFinal =   $schedule_date;            // seconds
            $fechaInicialSegundos = strtotime($fechaInicial);
            $fechaFinalSegundos = strtotime($fechaFinal);
            //
            $hrs = ($fechaFinalSegundos - $fechaInicialSegundos) / 3600;//hrs //86400 days;
            if($hrs >= 24){
                $charge_for_cancel = '';
            }else{
                $charge_for_cancel = 'You are canceling your appointment within 24 hours of the treatment and will be charged a $50 Cancellation fee.';
            }

            $patient_consent_toxin = false;
            $ent_agreement_toxin = $this->CatAgreements->find()->select(['CatAgreements.uid','DataAgreement.id','DataAgreement.uid','DataAgreement.user_id','DataAgreement.deleted'])->join([
                'DataAgreement' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'CatAgreements.uid = DataAgreement.agreement_uid AND DataAgreement.user_id = ' . $certTreatment->patient_id . ' AND DataAgreement.deleted = 0'],
            ])->where(
            [
                'CatAgreements.state_id' => 43,
                'CatAgreements.user_type' => 'PATIENT',
                'CatAgreements.agreement_type' => 'REGISTRATION',
                'CatAgreements.deleted' => 0,
                'DataAgreement.deleted' => 0,
            ]
            )->all();
            $this->set('patient_id', $certTreatment->patient_id);
            $this->set('USER_ID', USER_ID);
            $this->set('ent_agreement_toxin', $ent_agreement_toxin);
            if (empty($ent_agreement_toxin)) {
                $this->set('cicle', 'noent');
                $patient_consent_toxin = false;
                $patient_uid_consent_toxin = '';
            }else{
                foreach($ent_agreement_toxin as $agree){
                    //encontrar id paciente
                    $this->set('cicle', 'ent');
                    if($certTreatment->patient_id == $agree['DataAgreement']['user_id']){
                        $patient_uid_consent_toxin = $agree['DataAgreement']['uid'];
                        $patient_consent_toxin = true;
                    }                   

                }
                
            }

            if($patient_consent_toxin == false){
                $patient_uid_consent_toxin = '';

            }

            // Fillers agreement

            $patient_consent_fillers = false;
            $patient_uid_consent_fillers = '';

            $ent_agreement_fillers = $this->CatAgreements->find()->select(['CatAgreements.uid','DataAgreement.id','DataAgreement.uid','DataAgreement.user_id','DataAgreement.deleted'])->join([
                'DataAgreement' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'CatAgreements.uid = DataAgreement.agreement_uid AND DataAgreement.user_id = ' . $certTreatment->patient_id . ' AND DataAgreement.deleted = 0'],
            ])->where(
            [
                'CatAgreements.state_id' => 43,
                'CatAgreements.user_type' => 'PATIENT',
                'CatAgreements.agreement_type' => 'FILLERS',
                'CatAgreements.deleted' => 0,
                'DataAgreement.deleted' => 0,
                'DataAgreement.user_id' => USER_ID,
            ]
            )->first();

            if (empty($ent_agreement_fillers)) {
                $patient_consent_fillers = false;
            }else{
                $patient_consent_fillers = true;
                $patient_uid_consent_fillers = $ent_agreement_fillers['DataAgreement']['uid'];
            }

            
            //IV therapy agreement
            $patient_agreement_uid = "";
            $patient_consent_iv_therapy = false;

            $ent_agreement_iv = $this->CatAgreements->find()->select(['CatAgreements.uid','DataAgreement.id','DataAgreement.uid','DataAgreement.deleted'])->join([
                'DataAgreement' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'CatAgreements.uid = DataAgreement.agreement_uid AND DataAgreement.user_id = ' . $certTreatment->patient_id . ' AND DataAgreement.deleted = 0'],
            ])->where(
            [
                'CatAgreements.state_id' => 43,
                'CatAgreements.user_type' => 'PATIENT',
                'CatAgreements.agreement_type IN' => ['IVTHERAPHY'],
                'CatAgreements.deleted' => 0,
                'DataAgreement.deleted' => 0,
            ]
            )->first();
            //$this->set('ent_agreement_iv',$ent_agreement_iv);
            if (empty($ent_agreement_iv) && empty($ent_agreement_iv['DataAgreement']['id'])) {
                $patient_consent_iv_therapy = false;
            }else{
                $patient_consent_iv_therapy = true;
                $patient_agreement_uid = $ent_agreement_iv['DataAgreement']['uid'];
            }
            
            //ver el tipo de treatment
            
            $trits = strval($ent_scheduled[0]['type']);
            $treatment_inf = explode(',', $trits);
            $typeTreat = '';
            $ntTypeFound = false;
            $ivTypeFound = false;
            $typetreat = '';

            foreach ($treatment_inf as $numtrits) {
                if ($numtrits == 'IV THERAPY'){
                    $typetreat = 'IV THERAPY';
                    $ivTypeFound = true;

                } else {
                    $typetreat = 'NEUROTOXINS';
                    $ntTypeFound = true;

                }

            }

            if($ivTypeFound && $ntTypeFound){
                $typetreat = 'NT+IV';
            }

            //$TH = new \SpaLiveV1\Controller\Data\TreatmentsHelper($certTreatment->id);
            //$trits = strval($certTreatment->treatments);
            //$cuttedtrits = explode(',', $trits);
            //$typeTreat = '';
            //$ntTypeFound = false;
            //$ivTypeFound = false;
            //
            //$typetreat = '';
            //
            //
            //foreach ($cuttedtrits as $numtrits) {
            //    if($numtrits > 1000 && $numtrits < 1033){
            //        $typetreat = 'IV THERAPY';
            //        $ivTypeFound = true;
            //    } 
            //
            //    if($numtrits <= 1000){
            //        $typetreat = 'NEUROTOXINS';
            //        $ntTypeFound = true;
            //
            //    }
            //}

            $posicion = strpos($treatments, 'No preference (Let my provider choose)');
            //if($posicion !== false){
            //    $typetreat = 'NEUROTOXINS';
            //    $ntTypeFound = true;
            //}
            //
            //if($ivTypeFound && $ntTypeFound){
            //    $typetreat = 'NT+IV';
            //}
            /////

            $re_array = array(
                'trinf' => $treatment_inf,
                'uid' => $certTreatment->uid,
                'latitude' => doubleval($certTreatment->latitude),
                'longitude' => doubleval($certTreatment->longitude),
                'patient_uid' => $certTreatment['Patient']['uid'],
                'assistance_uid' => $certTreatment['Injector']['uid'],
                'schedule_date' => $certTreatment->schedule_date,
                'status' => $certTreatment->status,
                'gfe_status' => $this->gfeStatusForTreatment(USER_ID, $certTreatment->id), 
                'distance_in_mi' => $certTreatment->latitude > 0 ? round($certTreatment->distance_in_mi,1) : 0,
                'address' => $sstr_address,
                'patient' => $certTreatment['Patient']['name'] . ' ' . $certTreatment['Patient']['lname'],
                'provider' => $certTreatment['Injector']['name'] . ' ' . $certTreatment['Injector']['lname'],
                'treatments' => $certTreatment->type_uber ? $string_treatments : $treatments,//implode(',', $arr_treatments_prices),
                'treatments_iv' => $treatments_iv,
                'treatments_filler' => $treatments_filler,
                'treatments_string' => $certTreatment->treatments_string,
                'treatments_data' => $ent_scheduled,
                'signature_id' => $certTreatment->signature_id,
                'treatments_detail' => $data_tr,
                'out_reach' => ($certTreatment->latitude > 0 && round($certTreatment->distance_in_mi,1) > $user['user_radius'] ) ? 1 : 0,
                'sign_patient' => $sign_agreement,
                'number' => $certTreatment['Injector']['phone'],
                'number_label' => $this->formatPhoneNumber($certTreatment['Injector']['phone']),
                'charge_for_cancel' => $charge_for_cancel,
                'hrs' => $hrs,
                'hr_server' => $fechaInicial,
                'patient_consent_toxin' => $patient_consent_toxin,
                'patient_consent_iv_therapy' => $patient_consent_iv_therapy,
                'patient_consent_fillers' => $patient_consent_fillers,
                'patient_agreement_uid' => $patient_agreement_uid,
                'patient_uid_consent_toxin' => $patient_uid_consent_toxin,
                'patient_uid_consent_fillers' => $patient_uid_consent_fillers,
                'type' => $typetreat,
                'treatment_requirements' => $this->treatment_requirements_patients($cats_treatment_arr, $agreements, $certificates, USER_ID),
                'uber_type' => $certTreatment->type_uber,
            );
            $arr_appotments[] = $re_array;
            if($index == 0){
                $this->set('actual_appointment', $re_array);
            }
        }

        //agreements foreach
        $arr_appotments_wconsent = [];
        if(!empty($arr_appotments)){
            foreach($arr_appotments as $appointment){
                if(!empty($ent_consents)){
                    foreach($ent_consents as $consent){
                        if($appointment['signature_id'] == $consent['file_id']){
                            $appointment['consent_uid'] = $consent['uid'];
                        }
                    }
                }
                $arr_appotments_wconsent[] = $appointment;
            }

        }

        $this->set('actual_appointments', $arr_appotments_wconsent);//sumary patient

        // CAT LABELS

        $findLabels = $this->CatLabels->find()->select(['CatLabels.key_field', 'CatLabels.value'])->where(['CatLabels.deleted' => 0])->toArray();
        $labels = [];
        foreach($findLabels as $item){
            $labels[$item->key_field] = str_replace('GFE_COST', $this->total/100 , $item->value);
            $labels[$item->key_field] = str_replace('GFE_DOUBLE', ($this->total/100)*2, $labels[$item->key_field]);
            $labels[$item->key_field] = str_replace('GFE_PAYMENT', $this->paymente_gfe/100 , $labels[$item->key_field]);
            $labels[$item->key_field] = str_replace('CI_REGISTRATION', $this->register_total/100 , $labels[$item->key_field]);
            $labels[$item->key_field] = str_replace('CI_REFUND', $this->register_refund/100 , $labels[$item->key_field]);
            $labels[$item->key_field] = str_replace('CI_ADVANCED', $this->training_advanced/100 , $labels[$item->key_field]);
        }
        $this->set('labels', $labels); 

        // SERVICES
        $this->loadModel('SpaLiveV1.CatTreatmentsCi');
        $this->loadModel('SpaLiveV1.CatTreatmentsCategory');

        $array_treatments = array();

        //$cat_categorys = $this->CatTreatmentsCategory->find()->where(['CatTreatmentsCategory.type_uber IN' => array('NEUROTOXINS BASIC','NEUROTOXINS ADVANCED'), 'CatTreatmentsCategory.deleted' => 0])->order(['CatTreatmentsCategory.order' => 'ASC'])->all();
        $cat_categorys = $this->CatTreatmentsCategory->find()->where(['CatTreatmentsCategory.type_uber IN' => array('NEUROTOXINS BASIC','NEUROTOXINS ADVANCED','IV THERAPY'), 'CatTreatmentsCategory.deleted' => 0])->order(['CatTreatmentsCategory.order' => 'ASC'])->all();

        if(Count($cat_categorys) > 0){
            foreach($cat_categorys as $row){
                $array_list = array();
                $cat_treatment = $this->CatTreatmentsCi->find()->where(['CatTreatmentsCi.category_treatment_id' => $row->id, 'CatTreatmentsCi.deleted' => 0,'CatTreatmentsCi.id >' => 81])
                ->order(['CatTreatmentsCi.id' => 'DESC'])->all();
                
                if($row['type'] == 'IV THERAPY'){

                    foreach($cat_treatment as $row2){
                        array_push($array_list, array(
                            'id' => $row2['id'],
                            'name' => $row2['name'],
                            'description' => $row2['description'],
                            'price' => $row2['std_price'],
                            'type' => $row['type'],
                        ));

                        array_push($array_treatments, array(
                            'title' => $row2['name'],
                            'description' => $row2['description'],
                            'image' => $row2['image'],
                            'data' => $array_list,
                            'type' => $row['type'],
                        ));

                        $array_list = [];
                    }

                } else {
                    if(Count($cat_treatment) > 0) {
                        foreach($cat_treatment as $row2){
                            if($row2['name'] == $row['name']) { continue; }
                            array_push($array_list, array(
                                'id' => $row2['id'],
                                'name' => $row2['name'],
                                'description' => $row2['description'],
                                'price' => $row2['std_price'],
                                'type' => $row['type'],
                            ));
                        }
                    }
                
                    $array_treatments[] = array(
                        'title' => $row['name'],
                        'description' => $row['description'],
                        'image' => $row['image'],
                        'data' => $array_list,
                        'type' => $row['type'],
                    );
                }
                
            }
        }
        $this->set('list_treatments', $array_treatments);

        $this->loadModel('SpaLiveV1.DataTreatmentsPrice');
        
        $r_services = true;
        $r_available = true;
        $ent_tprice = $this->DataTreatmentsPrice->find()->where(['DataTreatmentsPrice.user_id' => USER_ID])->first();
        if (!empty($ent_tprice)) {
            $this->loadModel('SpaLiveV1.DataScheduleModel');
            $ent_sch_model = $this->DataScheduleModel->find()->where(['DataScheduleModel.deleted' => 0, 'DataScheduleModel.injector_id' => USER_ID])->first();
            
            if (!empty($ent_sch_model) && !empty($ent_sch_model->days)) {
                $r_available = false;
                $r_services = false;
            }
        }
        
        $this->set('request_services', $r_services);
        $this->set('request_availability', $r_available);
        
        //Request Fill Profil
        $r_profile = true;
        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();
        if (!empty($ent_user)) {
            if ($ent_user->photo_id != 93) {
                $r_profile = false;
            }            
        }
        $this->set('request_profile', $r_profile);
        
        // $this->set('stripe_button', true);
        $this->set('stripe_button', $this->checkStripeACcount());
        $this->set('patient_consent', $patient_consent);
        $this->set('patient_consent_iv_therapy', $patient_consent_iv_therapy);
        if($patient_consent_toxin){
            $patient_treatment_type = 'toxins';
            if($patient_consent_iv_therapy){
                $patient_treatment_type = 'toxins+ivt';
            }
        }else if($patient_consent_iv_therapy){
            $patient_treatment_type = 'ivt';
        }
        $this->set('patient_treatment_type', $patient_treatment_type);
        $this->set('step', USER_STEP);
        
        // Model Patient
        $this->loadModel('SpaLiveV1.DataModelPatient');

        $ent_patient = $this->DataModelPatient->find()->where(['DataModelPatient.email' => USER_EMAIL, 'DataModelPatient.status' => 'assigned', 'DataModelPatient.registered_training_id >' => 0])->first();
        $ent_patient_model = $this->DataModelPatient->find()->where(['DataModelPatient.email' => USER_EMAIL, 'DataModelPatient.deleted' => 0])->first();
         //Verificar si esta registrado como patient model
        if(!empty($ent_patient_model)){
            $this->set('patient_model_register', true);
        }else{
            $this->set('patient_model_register', false);
        }
        $this->set('patient_classes_attended', false);

        if (!empty($ent_patient)) {

            $this->set('patient_model', true);
            $this->set('text_patient_model', 'We have noticed that you are a model patient, please do not ask for your free units here. Choose a class and you will receive free treatment for model patients.');
             //$courses = $this->DataModelPatient->find()->where(['DataModelPatient.email' => USER_EMAIL, 'DataModelPatient.deleted' => 0, 'registered_training_id > 0', 'assistance'=> 0])->all();
            $oneclass = $this->DataModelPatient->find()->where(['DataModelPatient.email' => USER_EMAIL, 'DataModelPatient.deleted' => 0, 'registered_training_id > 0', 'assistance'=> 1])->all();
            if(count($oneclass)>1){
                $this->set('patient_classes_attended', true);
            }
                
        } else {
            $this->set('patient_model', false);
            $this->set('text_patient_model', '');
        }

        $hide_tab_pending = false;

        if(Count($payment_pending) == 0 && Count($arr_appotments) == 0 && Count($requestedAppntmnts) == 0 && Count($treatNoReviews) == 0 && Count($arr_treatments_uber) == 0){
            $hide_tab_pending = true;
        }else{
            $hide_tab_pending = false;
        }

        $this->set('hide_tab_pending', $hide_tab_pending);

        $ent_consultation = $this->DataConsultation->find()
        ->select(['DataConsultation.uid','DataCertificates.uid','DataCertificates.date_start','DataCertificates.date_expiration','DataCertificates.certificate_url'])
        ->join([
            'DataCertificates' => ['table' => 'data_certificates', 'type' => 'LEFT', 'conditions' => 'DataCertificates.consultation_id = DataConsultation.id'],
        ])
        ->where(['DataConsultation.patient_id' => USER_ID, 'DataConsultation.status' => 'CERTIFICATE' ,'DataConsultation.deleted' => 0])->last();
        
        $valid_gfe = true;
        if(empty($ent_consultation)){
            $valid_gfe = false;
        } else {
            if(empty($ent_consultation['DataCertificates']['uid'])){
                $valid_gfe = false;
            } else{
                if($ent_consultation['DataCertificates']['date_expiration'] < date('Y-m-d')){
                    $valid_gfe = true;
                }
            }
        }

        $this->set('valid_gfe', $valid_gfe);

        $this->success(); 
    }

    public function invitations_patient(){
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataConsultation');
        $this->loadModel('SpaLiveV1.DataCertificates');
        
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

        $userType = $user['user_role'];        

        if(strtoupper($userType) == 'PATIENT'){
            $this->loadModel('SpaLiveV1.DataTreatment');
            $this->loadModel('SpaLiveV1.CatTreatmentsCi');

            $fields = ['DataTreatment.uid','DataTreatment.treatments','DataTreatment.schedule_date','DataTreatment.status','DataTreatment.address','DataTreatment.zip','DataTreatment.city','DataTreatment.suite','DataTreatment.notes'];
            $fields['assistance'] = "(SELECT CONCAT(' ', U.name, ' ', SUBSTRING(U.lname, 1, 1)) FROM sys_users U WHERE U.id = DataTreatment.assistance_id)";
            $fields['assistance_info'] = "(SELECT CONCAT_WS('||', U.uid, U.short_uid) FROM sys_users U WHERE U.id = DataTreatment.assistance_id)";
            $fields['txt_state'] = "(SELECT name FROM cat_states CS WHERE CS.id = DataTreatment.state LIMIT 1)";
            $_where = ['DataTreatment.deleted' => 0];
            $_where['DataTreatment.patient_id'] = USER_ID;
            $_where['OR'] = [['DataTreatment.status' => "INVITATION"]];
           
            $certTreatment = $this->DataTreatment->find()->select($fields)->where($_where)->order(['DataTreatment.id' => 'DESC'])->all();
            $ivTherapy = false;

            $treatment_invitations = array();
            if (!empty($certTreatment)) {
                foreach ($certTreatment as $row) {
                    $str_address = $row['address'] . ', ' . $row['city'] . ', ' . $row['txt_state'] . ' ' . $row['zip'];
                    if (!empty($row['suite'])) {
                        $str_address = $row['address'] . ', ' . $row['suite'] . ', ' . $row['city'] . ', ' . $row['txt_state'] . ' ' . $row['zip'];
                    }
                    $str_inj_info = explode('||',$row['assistance_info']);
                    
                    $treatments = explode(',', $row['treatments']);
                    $arr_treatments = array();

                    foreach($treatments as $treatment){
                        
                        $ent_treatment = $this->CatTreatmentsCi->find()->where(['CatTreatmentsCi.id' => $treatment])->first();
                        if(!empty($ent_treatment)){
                            if($ent_treatment->category_treatment_id == 1001){
                                $ent_treatment->ivtherapy = true;
                                $ivTherapy = true;
                            } else {
                                $ent_treatment->ivtherapy = false;
                            }

                            $arr_treatments[] = array(
                                'name' => $ent_treatment->name,
                                'ivtherapy' => $ent_treatment->ivtherapy,
                            );
                        }
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

                    $temp_arr = array(
                        'treatment_uid' => $row['uid'],
                        'notes' => $row['notes'],
                        'schedule_date' => $row['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm'),
                        'assistance' => $row['assistance'],
                        'assistance_uid' => $str_inj_info[0],
                        'assistance_shortuid' => $str_inj_info[1],
                        'status' => $row['status'],
                        'address' => $str_address,
                        'treatments' => $arr_treatments,
                        'require_gfe' => Count($array_treatmets) > 0,
                        'iv_therapy' => $ivTherapy
                    );
                    
                    $treatment_invitations[] = $temp_arr;                    
                }

                
                $this->set('treatment_invitations', $treatment_invitations);

                if(Count($treatment_invitations) > 0){
                    foreach($treatment_invitations as $treatment){
                        $this->DataTreatment->updateAll(
                            ['reviewed_patient' => 1],
                            ['uid' => $treatment['treatment_uid']]
                        );
                    } 
                }

                $this->success();
            }
        }
    }

    public function summary_CP() {
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataConsultation');
        $this->loadModel('SpaLiveV1.DataCertificates');
        $this->loadModel('SpaLiveV1.CatLabels');
        $this->loadModel('SpaLiveV1.DataTrainings');

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

        $Treatments = new TreatmentsController();

        $this->set('emergencyPhone', $this->emergencyPhone);
        $this->set('emergencyPhone2', $this->emergencyPhone2);

        $userType = $user['user_role'];
        $this->loadModel('SpaLiveV1.DataMessages');
        $c_count = $this->DataMessages->find()->select(['DataMessages.id'])->where(["DataMessages.deleted" => 0,'DataMessages.id_to' => USER_ID, 'DataMessages.readed' => 0])->count();
        $this->set('unread_messages', $c_count);
        
        // $fields_cert = ['DataConsultation.uid','DataConsultation.payment','DataCertificates.uid','DataCertificates.date_start','DataCertificates.date_expiration','UserA.name'];
        // $_where_cert = ['DataConsultation.deleted' => 0];

        // PENDING AGREEMENTS *****************

        $pending_agreements_array = array();
        $this->set('pending_agreements', array());
        $uid_agreement_subscription = $this->requestInjectorSubscriptionAgreement();
        if (!empty($uid_agreement_subscription)) $pending_agreements_array[] = $uid_agreement_subscription;

        $uid_agreement_register = $this->requestRegisterAgreement();
        if (!empty($uid_agreement_register)) $pending_agreements_array[] = $uid_agreement_register;
        
        $uid_subscription = $this->requestSubscriptionMDSummary();
        if (!empty($uid_subscription)) $pending_agreements_array[] = $uid_subscription;
        
        $this->set('pending_agreements', $pending_agreements_array);

        // $ent_scheduled = $this->DataConsultation->getConnection()->execute("")->fetchAll('assoc');

        // Treatments allowed provides

        $now = date('Y-m-d H:i:s');

        $treatmets_allowes_provides = array();

        // $user_training = $this->DataTrainings->find()->join([
        //     'CatTrainigs' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainings.training_id'],
        //     ])->where(['CatTrainigs.level' => 'LEVEL 1','DataTrainings.user_id' => USER_ID,'DataTrainings.deleted' => 0, '(DATE_FORMAT(CatTrainigs.scheduled, "%Y-%m-%d 07:00:00") < "'.$now.'")'])->first();
        // if(!empty($user_training)){
        //     $treatmets_allowes_provides[] = array(
        //         'title' => 'Neurotoxins (Basic Treatments)'
        //     );
        // }

        // $user_training_advanced= $this->DataTrainings->find()->join([
        //     'CatTrainigs' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainings.training_id'],
        //     ])->where(['CatTrainigs.level' => 'LEVEL 2','DataTrainings.user_id' => USER_ID,'DataTrainings.deleted' => 0, '(DATE_FORMAT(CatTrainigs.scheduled, "%Y-%m-%d 16:00:00") < "'.$now.'")'])->first();

        // if(!empty($user_training_advanced)){
        //     $treatmets_allowes_provides[] = array(
        //         'title' => 'Neurotoxins (Advanced Treatments)'
        //     );
        // }
        
        $cc = new CourseController();                        
        $trainings_user = $cc->get_courses_user(USER_ID);
        $has_basic_course = $trainings_user['has_basic_course'];
        $has_advanced_course = $trainings_user['has_advanced_course'];

        //CHECK IF INYECTOR HAVE PURCHASE THE BASIC COURSE
        $level1_id = 103; // FIEX ID OF Basic training for dev

        $is_dev = env('IS_DEV', false);
        if(!$is_dev){
            $level1_id = 45; // FIEX ID OF Basic training for prod
        }

        //CHECK IF INYECTOR HAVE PURCHASE THE BASIC COURSE
        $this->loadModel('SpaLiveV1.DataPayment');
        $ent_test_payment = $this->DataPayment->find()
        ->where(['DataPayment.id_from' => $user["user_id"], 'DataPayment.id_to' => 0,'DataPayment.type' => 'BASIC COURSE', 'DataPayment.service_uid' => '','DataPayment.payment <>' => '', 'DataPayment.is_visible' => 1])->first();

        $purchased = false;

        if (!empty($ent_test_payment)) { 
            $purchased = true; 
        }

        $this->set('services_injector', $this->services_injector(USER_ID));
        
        // $this->set('services_injector', []);
        $this->set('bought_basic_course', $purchased);

        if($purchased){
            $_fields = ['CatTrainigs.id', 'CatTrainigs.title', 'CatTrainigs.scheduled', 'CatTrainigs.level', 'DataTrainings.attended'];
            
            $_join = [
                'CatTrainigs' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainings.training_id'],
                'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = CatTrainigs.state_id']
            ];

            $basic_training = $this->DataTrainings->find()->select($_fields)->join($_join)->where(
                ["DataTrainings.deleted" => 0,'DataTrainings.user_id' => $user["user_id"],'CatTrainigs.deleted' => 0,'CatTrainigs.level' => 'LEVEL 1'])->first();
            
            if(!empty($basic_training)){
                
                $now = date('Y-m-d');
                $basic_training_status = "";

                if(date('Y-m-d', strtotime($basic_training["CatTrainigs"]["scheduled"])) > $now){
                    $basic_training_status = "Study";
                }else{
                    
                    $Course = new CourseController();
                    $basic_training_status = $Course->consult_neurotoxin_application($basic_training,$user["user_id"]);

                    if($basic_training_status == "Configure"){
                        $treatmets_allowes_provides[] = array(
                            'title' => 'Neurotoxins (Basic Treatments)'
                        );
                    }
                    
                    if($has_advanced_course){

                        if(count($treatmets_allowes_provides)<=0){
                            $treatmets_allowes_provides[] = array(
                                'title' => 'Neurotoxins (Basic Treatments)'
                            );
            
                            $treatmets_allowes_provides[] = array(
                                'title' => 'Neurotoxins (Advanced Treatments)'
                            );
                        }else{
                            $treatmets_allowes_provides[] = array(
                                'title' => 'Neurotoxins (Advanced Treatments)'
                            );
                        }
                    }
                    
                }

                $this->set("basic_training_status", $basic_training_status);
                $this->set("basic_training_id", $basic_training["CatTrainigs"]["id"]);
            
            }

        }else{
            //schools
            if($has_basic_course){
                $treatmets_allowes_provides[] = array(
                    'title' => 'Neurotoxins (Basic Treatments)'
                );
            }
            
            if($has_advanced_course){
                $treatmets_allowes_provides[] = array(
                    'title' => 'Neurotoxins (Advanced Treatments)'
                );
            }
        }

        $has_ivt = 'NONE';
        $c_ivt = new TherapyController();

        $iv_therapy = $c_ivt->consult_iv_application($user["user_id"]);     
        $this->set('c_ivt', $iv_therapy);        
        if($iv_therapy != ""){
            $this->set('ivt', $iv_therapy);
            if($iv_therapy=='ACCEPTED'){
                $has_ivt = 'ACCEPTED';

                $treatmets_allowes_provides[] = array(
                    'title' => 'IV'
                );

            }else{
                $has_ivt = $iv_therapy;
            }
        }   
        $this->set('has_ivt', $has_ivt);

        $gfe_ci_ivt = false;
        $this->loadModel('SpaLiveV1.DataRequestGfeCi');

        $requestItem = $this->DataRequestGfeCi->find()->where(['DataRequestGfeCi.user_id' => USER_ID])->first();
        if(!empty($requestItem)){
            if($requestItem->status == 'READY' ){
                $gfe_ci_ivt = true;
            }
        }
        $this->set('gfe_ci_ivt', $gfe_ci_ivt);
        $this->set('treatmets_allowes_provides', $treatmets_allowes_provides);

        // ACTUAL APPOINTMENT

        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.DataTreatmentsPrice');
        $this->loadModel('SpaLiveV1.DataPurchases');
        $this->loadModel('SpaLiveV1.DataClaimTreatments');
        $this->loadModel('SpaLiveV1.CatCITreatments');

        
        $this->loadModel('SpaLiveV1.DataAgreements');
        $this->loadModel('SpaLiveV1.catAgreements');

        $ent_consent_cat = $this->catAgreements
            ->find()
            ->where([
                'catAgreements.user_type' => 'INJECTOR', 
                'catAgreements.agreement_type IN' => ['SUBSCRIPTIONMSLIVT', 'SUBSCRIPTIONMDIVT'],
                'catAgreements.deleted' => 0
            ])->first();  

        $ent_consents = $this->DataAgreements
        ->find()
        ->where([
            'DataAgreements.user_id' => USER_ID, 
            'DataAgreements.file_id IS NOT' => 0,
            'DataAgreements.deleted' => 0
        ])->all();  

        $consentsFormatted = [];
        if(!empty($ent_consents)){
            foreach($ent_consents as $consent){
                if($consent['agreement_uid'] == $ent_consent_cat['uid']){
                    $consent['type'] = 'IV THERAPY';
                    $this->set('yes', 'yes');
                } else {
                    $consent['type'] = 'OTHER';
                }
                $consentsFormatted[] = $consent;
            }
        }

        $this->set('conscentsIV', $consentsFormatted);

        $fields = ['DataTreatment.id','DataTreatment.uid','DataTreatment.schedule_date','DataTreatment.status','DataTreatment.address','DataTreatment.treatments','DataTreatment.state','State.name','DataTreatment.patient_id', 'DataTreatment.city','DataTreatment.zip','DataTreatment.suite','DataTreatment.latitude','DataTreatment.longitude','Patient.id','Patient.name','Patient.lname','Patient.uid','Patient.state','Injector.latitude','Injector.longitude','Injector.uid','Injector.name','Injector.lname','DataTreatment.notes', 'DataTreatment.type_uber', 'DataTreatment.signature_id'];

        $fields['treatments_string'] = "(SELECT GROUP_CONCAT(CONCAT(CTC.name,' (',SUBSTRING_INDEX(CT.name, ':', 1), ')') SEPARATOR ', ') 
                                        FROM cat_treatments_ci CT 
                                        JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
                                        WHERE FIND_IN_SET(CT.id,DataTreatment.treatments) LIMIT 1)";

        $fields['treatments_string_id'] = "(SELECT GROUP_CONCAT(CT.id) 
                                        FROM cat_treatments_ci CT 
                                        JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
                                        WHERE FIND_IN_SET(CT.id,DataTreatment.treatments))";

        $fields['distance_in_mi'] = "(69.09 * DEGREES(ACOS(LEAST(1.0, COS(RADIANS(DataTreatment.latitude))
                                    * COS(RADIANS(Injector.latitude))
                                    * COS(RADIANS(DataTreatment.longitude - Injector.longitude))
                                    + SIN(RADIANS(DataTreatment.latitude))
                                    * SIN(RADIANS(Injector.latitude))))))";

        $_where = ['DataTreatment.deleted' => 0];

        $_where['OR'] = [['DataTreatment.status' => "CONFIRM"], ['DataTreatment.status' => "DONE", 'DataTreatment.payment' => ""]];

        $_where['DataTreatment.assistance_id'] = USER_ID;
        $_where['DataTreatment.home'] = 1;

        $certTrtArr = $this->DataTreatment->find()->select($fields)->join([
            'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
            'Patient' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Patient.id = DataTreatment.patient_id AND Patient.deleted = 0'],
            'Injector' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Injector.id = DataTreatment.assistance_id AND Patient.deleted = 0'],
        ])->where($_where)->order(['DataTreatment.schedule_date' => 'ASC'])->toArray();
                        

        $arr_treatments = array();
        $arr_appotments = [];
        // if (!empty($certTreatment)) {
        foreach($certTrtArr as $index => $certTreatment){

            if($certTreatment->type_uber == 1){
                $_fields = ['CatCITreatments.std_price ','CatCITreatments.id','CatCITreatments.name','CatCITreatments.qty', 'CatCITreatments.treatment_id'];
                $_fields['certificate'] = "(SELECT DC.uid
                        FROM cat_treatments_ci CTC
                        JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                        JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                        JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                        WHERE CTC.deleted = 0 AND CTC.id = CatCITreatments.id AND DCP.proceed = 1 AND NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                        LIMIT 1)";
                $_fields['consultation'] = "(SELECT DC.uid
                        FROM cat_treatments_ci CTC
                        JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                        JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                        JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                        WHERE CTC.deleted = 0 AND CTC.id = CatCITreatments.id AND NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                        ORDER BY DCO.modified DESC
                        LIMIT 1)";
                $_fields['certificate_treatments'] = "(SELECT DC.uid
                        FROM cat_treatments_ci CTC
                        JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                        JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                        JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                        WHERE CTC.deleted = 0 AND CTC.id = CatCITreatments.id AND DCP.proceed = 1 AND NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                        LIMIT 1)";

                $ent_prices = $this->CatCITreatments->find()->select($_fields)
                ->where(['CatCITreatments.deleted' => 0, 'FIND_IN_SET(CatCITreatments.id, "' . $certTreatment->treatments . '")'])->all();
                // act
                /*
                $array_list = array();
                $this->loadModel('SpaLiveV1.CatCITreatments');
                $ent_treatments = $this->CatCITreatments->find()->select(['CatCITreatments.id','CatCITreatments.name','CatCITreatments.details', 'CatCITreatments.std_price', 'Cat.name', 'CatCITreatments.category_treatment_id'])
                ->join([
                    'Cat' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'Cat.id = CatCITreatments.category_treatment_id'],
                ])
                ->where(['CatCITreatments.id IN' => explode(',', $certTreatment->treatments)]);

                foreach($ent_treatments as $row){
                    if($row->name == 'Let my provider choose' || $row->name == 'Let my provider help me decide' || $row->name == 'No preference'){
                        if($row->id == 999){$row['Cat']['name'] = 'Basic Neurotoxins'; $row->category_treatment_id = 1;}
                        $ent_treatments2 = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.std_price', 'CatCITreatments.name', 'CatCITreatments.category_treatment_id'])->join([
                            'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id AND CatCITreatments.name <> CTC.name'],
                        ])->where(['CatCITreatments.deleted' => 0, 'CatCITreatments.name NOT IN' => array('Let my provider help me decide', 'Let my provider choose', 'No preference') ,'CatCITreatments.category_treatment_id' => $row->category_treatment_id])->all();
                        $array_prices = array();
                        foreach ($ent_treatments2 as $key => $trea) {
                            $array_prices[] = $trea['name'] .' $' . ($trea['std_price'] / 100);
                        }
                        $array_list[] = $row['Cat']['name'] . ' (' . implode(', ', $array_prices) . ')';
                    }else{
                        $array_list[] = $row->name == $row['Cat']['name'] ? $row->name . ' ($' . $row->std_price/100 . ' ' . $row->details . ')' : $row['Cat']['name'] . ' ('. $row->name .' $' . $row->std_price/100 . ' ' . $row->details . ')';
                    }
                }

                $string_treatments = implode(', ', $array_list);

                */
                // act

                $ent_treatments = $this->CatCITreatments->find()->select(['CatCITreatments.id','CatCITreatments.name','CatCITreatments.details', 'CatCITreatments.std_price', 'Cat.name', 'CatCITreatments.category_treatment_id'])
                ->join([
                    'Cat' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'Cat.id = CatCITreatments.category_treatment_id'],
                ])
                ->where(['CatCITreatments.id IN' => explode(',', $certTreatment->treatments)]);
                
                $array_list = array();
                $iv_list = array();
                $neuro_list = array();
                $filler_list = array();
                $other_list = array();
                foreach($ent_treatments as $_row){
                    if($_row->name == 'Let my provider choose' || $_row->name == 'Let my provider help me decide' || $_row->name == 'No preference'){
                        if($_row->id == 999){$_row['Cat']['name'] = 'Basic Neurotoxins'; $_row->category_treatment_id = 1;}
                        if($_row->id == 1033){$filler_list[] = 'Fillers'; continue;}
                        $ent_treatments2 = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.std_price', 'CatCITreatments.name', 'CatCITreatments.category_treatment_id'])->join([
                            'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id AND CatCITreatments.name <> CTC.name'],
                        ])->where(['CatCITreatments.deleted' => 0, 'CatCITreatments.name NOT IN' => array('Let my provider help me decide', 'Let my provider choose', 'No preference') ,'CatCITreatments.category_treatment_id' => $_row->category_treatment_id])->all();
                        $array_prices = array();
                        foreach ($ent_treatments2 as $key => $trea) {
                            $array_prices[] = $trea['name'];
                        }
                        $neuro_list[] = $_row['Cat']['name'];
                    }else{
                        if($_row['Cat']['name'] == 'IV Therapy' || $_row['Cat']['name'] == 'IV'){
                            $iv_list[] = $_row->name;
                        }else if($_row['Cat']['name'] == 'Other Treatments'){
                            $other_list[] = $_row->name;
                        }else{
                            $neuro_list[] = $_row->name == $_row['Cat']['name'] ? $_row->name : $_row['Cat']['name'];
                        }
                    }
                }

                $add_iv_button = false;
                $array_seeprices = array();
                $string_iv = implode(', ', $iv_list);
                $string_neuro = implode(', ', $neuro_list);
                $string_filler = implode(', ', $filler_list);
                $string_other = implode(', ', $other_list);
                $string_treatments = '';

                $array_treat = explode(',', $certTreatment->treatments);
                foreach($array_treat as $key => $value){
                    if($value == 0){
                        $string_iv = 'No preference';
                    }
                }

                $array_string_treatments = array();

                if(!empty($string_neuro)){
                    $array_string_treatments[] = $string_neuro;
                    $add_iv_button = true;

                    $ent_neuro_price = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.std_price', 'CatCITreatments.name', 'CatCITreatments.details', 'CatCITreatments.category_treatment_id'])
                    ->where(['CatCITreatments.deleted' => 0, 'CatCITreatments.id IN' => array(1,2,4,73,74,75,76)])->all();

                    $neuro_price = array();

                    foreach ($ent_neuro_price as $key => $price) {
                        if($price['category_treatment_id'] == 1){
                            $neuro_price[] = $price['name'] .'(basic treatments): $' . ($price['std_price'] / 100) . ' ' . $price['details'];

                        }else{
                            $neuro_price[] = $price['name'] .': $' . ($price['std_price'] / 100) . ' ' . $price['details'];
                        }
                    }

                    $array_seeprices = array(
                        array('category' => 'Neurotoxins', 'prices' => $neuro_price),
                    );
                }

                if(!empty($string_filler)){
                    $array_string_treatments[] = $string_filler;

                    /*$ent_neuro_price = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.std_price', 'CatCITreatments.name', 'CatCITreatments.details', 'CatCITreatments.category_treatment_id'])
                    ->where(['CatCITreatments.deleted' => 0, 'CatCITreatments.id IN' => array(1,2,4,73,74,75,76)])->all();

                    $neuro_price = array();

                    foreach ($ent_neuro_price as $key => $price) {
                        if($price['category_treatment_id'] == 1){
                            $neuro_price[] = $price['name'] .'(basic treatments): $' . ($price['std_price'] / 100) . ' ' . $price['details'];

                        }else{
                            $neuro_price[] = $price['name'] .': $' . ($price['std_price'] / 100) . ' ' . $price['details'];
                        }
                    }

                    $array_seeprices = array(
                        array('category' => 'Neurotoxins', 'prices' => $neuro_price),
                    );*/
                }
                
                if(!empty($string_iv)){
                    $array_string_treatments[] = 'IV(' . $string_iv . ')';

                    $ent_iv_price = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.std_price', 'CatCITreatments.name', 'CatCITreatments.details', 'CatCITreatments.category_treatment_id', 'Price.price'])
                    ->join([
                        'Price' => ['table' => 'data_treatments_prices', 'type' => 'INNER', 'conditions' => 'Price.treatment_id = CatCITreatments.id'],
                        'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id'],
                    ])->where(['CatCITreatments.deleted' => 0, 'Price.user_id' => USER_ID, 'Price.deleted' => 0, 'CTC.type_uber' => 'IV THERAPY'])->all();

                    $iv_price = array();

                    foreach ($ent_iv_price as $key => $price) {
                        $iv_price[] = $price['name'] . ': $' . ($price['Price']['price'] / 100) . ' ' . $price['details'];
                    }

                    $array_seeprices = array(
                        array('category' => 'IV', 'prices' => $iv_price),
                    );
                }

                if(!empty($string_other)){
                    $array_string_treatments[] = $string_other;
                }

                $string_treatments = implode(', ', $array_string_treatments);

                $data_tr = array();

                if (!empty($ent_prices)) {
                    foreach ($ent_prices as $row) {
                        $status = 'EMPTY';
                        if(!empty($row['consultation'])){
                            $status = empty($row['certificate_treatments']) ? 'DENIED' : 'DONE';   
                        }
                        $data_tr[] = array(
                            'name' => $row['name'],
                            'treatment_id' => intval($row['id']),
                            'notes' => $row['notes'],
                            'price' => intval($row['std_price']),
                            'qty' => intval($row['qty']),
                            'certificate' => !empty($row['certificate']) ? $row['certificate'] : '',
                            'consultation' => !empty($row['consultation']) ? $row['consultation'] : '',
                            'certificate_status' => $status,
                        );
                    }
                }
            }else{
                $_fields = ['DataTreatmentsPrice.price','DataTreatmentsPrice.treatment_id','Treatments.name','Treatments.qty', 'Treatments.treatment_id'];
                $_fields['certificate'] = "(SELECT DC.uid
                        FROM cat_treatments_ci CTC
                        JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                        JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                        JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                        WHERE CTC.deleted = 0 AND CTC.id = DataTreatmentsPrice.treatment_id AND DCP.proceed = 1 AND NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                        LIMIT 1)";
                $_fields['consultation'] = "(SELECT DC.uid
                        FROM cat_treatments_ci CTC
                        JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                        JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                        JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                        WHERE CTC.deleted = 0 AND CTC.id = DataTreatmentsPrice.treatment_id AND NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                        ORDER BY DCO.modified DESC
                        LIMIT 1)";
                $_fields['certificate_treatments'] = "(SELECT DC.uid
                        FROM cat_treatments_ci CTC
                        JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                        JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                        JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                        WHERE CTC.deleted = 0 AND CTC.id = DataTreatmentsPrice.treatment_id AND DCP.proceed = 1 AND NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                        LIMIT 1)";

                $ent_prices = $this->DataTreatmentsPrice->find()->select($_fields)->join([
                    'Treatments' => ['table' => 'cat_treatments_ci', 'type' => 'INNER', 'conditions' => 'Treatments.id = DataTreatmentsPrice.treatment_id'],
                ])->where(['DataTreatmentsPrice.deleted' => 0, 'FIND_IN_SET(DataTreatmentsPrice.treatment_id, "' . $certTreatment->treatments . '")' ,'DataTreatmentsPrice.user_id' => USER_ID])->all();

                $data_tr = array();

                if (!empty($ent_prices)) {
                    foreach ($ent_prices as $row) {
                        $status = 'EMPTY';
                        if(!empty($row['consultation'])){
                            $status = empty($row['certificate_treatments']) ? 'DENIED' : 'DONE';   
                        }
                        $data_tr[] = array(
                            'name' => $row['Treatments']['name'],
                            'treatment_id' => intval($row['treatment_id']),
                            'notes' => !empty($row['notes']) ? $row['notes'] : '',
                            'price' => intval($row['price']),
                            'qty' => intval($row['Treatments']['qty']),
                            'certificate' => !empty($row['certificate']) ? $row['certificate'] : '',
                            'consultation' => !empty($row['consultation']) ? $row['consultation'] : '',
                            'certificate_status' => $status,
                        );
                    }
                }
            }
            $sign_agreement = false;

            $this->loadModel('SpaLiveV1.Agreement');
            $this->loadModel('SpaLiveV1.SysUsers');
            $this->loadModel('SpaLiveV1.CatAgreements');
            $this->loadModel('SpaLiveV1.CatCITreatments');

            $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $certTreatment->patient_id])->first();
        
            $ent_agreement = $this->CatAgreements->find()->select(['CatAgreements.uid','DataAgreement.id','DataAgreement.deleted'])->join([
                    'DataAgreement' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'CatAgreements.uid = DataAgreement.agreement_uid AND DataAgreement.user_id = ' . $certTreatment->patient_id . ' AND DataAgreement.deleted = 0'],
                ])->where(
                [
                    'CatAgreements.state_id' => $certTreatment->state,
                    'CatAgreements.user_type' => 'patient',
                    'CatAgreements.agreement_type' => 'REGISTRATION',
                    'CatAgreements.deleted' => 0,
                ]
            )->first();
            if (!empty($ent_agreement) && empty($ent_agreement['DataAgreement']['id'])) {
                $sign_agreement = true;
            }

            $sstr_address = $certTreatment->address . ', ' . $certTreatment->city . ', ' . $certTreatment['State']['name'] . ' ' . $certTreatment->zip;
            if (!empty($certTreatment->suite)) {
                $sstr_address = $certTreatment->address . ', ' . $certTreatment->suite . ', ' . $certTreatment->city . ', ' . $certTreatment['State']['name'] . ' ' . $certTreatment->zip;
            }

            $str_query_scheduled = "
            SELECT GROUP_CONCAT(CT.name) product_name, GROUP_CONCAT(DISTINCT CTC.`name`) treatment, GROUP_CONCAT(DISTINCT CTC.type) type 
            FROM cat_treatments_ci CT 
            JOIN cat_treatments_category CTC ON CT.category_treatment_id = CTC.id
            WHERE FIND_IN_SET(CT.id,'".$certTreatment->treatments."')";

            $ent_scheduled = $this->CatCITreatments->getConnection()->execute($str_query_scheduled)->fetchAll('assoc');

            //getagreements
            $ent_agreement_toxin = $this->CatAgreements->find()->select(['CatAgreements.uid','DataAgreement.id','DataAgreement.uid','DataAgreement.user_id','DataAgreement.deleted'])->join([
                'DataAgreement' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'CatAgreements.uid = DataAgreement.agreement_uid AND DataAgreement.user_id = ' . $certTreatment['Patient']['id'] . ' AND DataAgreement.deleted = 0'],
            ])->where(
            [
                'CatAgreements.state_id' => 43,
                'CatAgreements.user_type' => 'PATIENT',
                'CatAgreements.agreement_type' => 'REGISTRATION',
                'CatAgreements.deleted' => 0,
            ]
            )->all();

            $patient_consent_toxin = false;
            if (empty($ent_agreement_toxin) && empty($ent_agreement_toxin['DataAgreement']['id'])) {
                $patient_consent_toxin = false;
                $patient_uid_consent_toxin = '';
                $patient_consent_toxin = true;
            }else{
                foreach($ent_agreement_toxin as $agree){

                    if($certTreatment['Patient']['id'] == $agree['DataAgreement']['user_id']){
                        $patient_uid_consent_toxin = $agree['DataAgreement']['uid'];
                        $patient_consent_toxin = true;
                    }
                }
            }

            if($patient_consent_toxin == false){
                $patient_uid_consent_toxin = '';
            }

            $ent_agreement_iv = $this->CatAgreements->find()->select(['CatAgreements.uid','DataAgreement.id','DataAgreement.uid','DataAgreement.deleted'])->join([
                'DataAgreement' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'CatAgreements.uid = DataAgreement.agreement_uid AND DataAgreement.user_id = ' . $certTreatment['Patient']['id']],
            ])->where(
            [
                'CatAgreements.state_id' => 43,
                'CatAgreements.user_type' => 'PATIENT',
                'CatAgreements.agreement_type' => 'IVTHERAPHY',
                'CatAgreements.deleted' => 0,
                'DataAgreement.deleted' => 0,
            ]
            )->first();

            if (empty($ent_agreement_iv) && empty($ent_agreement_iv['DataAgreement']['id'])) {
                $patient_consent_iv_therapy = false;
            }else{
                $patient_consent_iv_therapy = true;
            }

            $ent_agreement_filler = $this->CatAgreements->find()->select(['CatAgreements.uid','DataAgreement.id','DataAgreement.uid','DataAgreement.deleted'])->join([
                'DataAgreement' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'CatAgreements.uid = DataAgreement.agreement_uid AND DataAgreement.user_id = ' . $certTreatment['Patient']['id'] . ' AND DataAgreement.deleted = 0'],
            ])->where(
            [
                'CatAgreements.state_id' => 43,
                'CatAgreements.user_type' => 'PATIENT',
                'CatAgreements.agreement_type' => 'FILLERS',
                'CatAgreements.deleted' => 0,
            ]
            )->first();

            $patient_consent_filler = ''; 
            if (!empty($ent_agreement_filler) && !empty($ent_agreement_filler['DataAgreement']['id'])) {
                $patient_consent_filler = $ent_agreement_filler['DataAgreement']['uid'];
            }
            ///////

            $cadena = $certTreatment->treatments;
            $idsTreats = explode(",", $cadena);

            $this->loadModel('SpaLiveV1.CatTreatmentsCi');
            $typeTreat = '';
            $ntTypeFound = false;
            $ivTypeFound = false;
            $data_tr = [];
            $c_status = '';

            if(!empty($row['certificate_status'])){
                $c_status = $row['certificate_status'];
            }
            foreach($ent_prices as $row){
                
                if($row['treatment_id'] >= 1000){
                    $typeTreat = 'IV Therapy';
                    $ivTypeFound = true;
                    $data_tr[] = array(
                        'name' => $row['Treatments']['name'],
                        'treatment_id' => intval($row['treatment_id']),
                        'notes' => $row['notes'],
                        'price' => intval($row['price']),
                        'qty' => intval($row['Treatments']['qty']),
                        'certificate' => $certTreatment['signature_id'] !=0 ? '' : '',
                        'consultation' => $certTreatment['signature_id'] !=0 ? '' : '',
                        'certificate_status' => $certTreatment['signature_id'] !=0 ? 'DONE' : 'PENDING',
                    );
                } else {
                    $typeTreat = 'Neurotoxins';
                    $ntTypeFound = true;
                    $data_tr[] = array(
                        'name' => $row['Treatments']['name'],
                        'treatment_id' => intval($row['treatment_id']),
                        'notes' => $certTreatment['notes'],
                        'price' => intval($row['price']),
                        'qty' => intval($row['Treatments']['qty']),
                        'certificate' => !empty($row['certificate']) ? $row['certificate'] : '',
                        'consultation' => !empty($row['consultation']) ? $row['consultation'] : '',
                        'certificate_status' => !empty($c_status) ? $c_status : '',
                    );
                }
            }
            
            $separate_treatments = $Treatments->separate_treatments($certTreatment->treatments_string,$certTreatment->treatments_string_id,$user["user_id"]); 

            $cats_treatment_arr = [];
            $array_treatments = [];
            
            if($certTreatment->type_uber != 1){

                if(!empty($separate_treatments["neurotoxins"])){
                    $cats_treatment_arr[] = 'NEUROTOXINS';
                    $array_treatments[] = $this->check_training_medical(USER_ID) ? str_ireplace('basic ', '', $separate_treatments["neurotoxins"]) : $separate_treatments["neurotoxins"];
                }

                if(!empty($separate_treatments["fillers"])){
                    $cats_treatment_arr[] = 'FILLERS';
                    $array_treatments[] = $separate_treatments["fillers"];
                }
                
                if(!empty($separate_treatments["iv_therapy"])){
                    $cats_treatment_arr[] = 'IV THERAPY';
                    $array_treatments[] = $separate_treatments["iv_therapy"];
                }

                if(!empty($separate_treatments["other_treatments"])){
                    // limpian el texto de other treatments
                    $treatments_other = $separate_treatments["other_treatments"];
                    $treatments_other = preg_replace('/Other Treatments\s*/', '', $treatments_other); // quita la frase
                    $treatments_other = preg_replace('/[()]/', '', $treatments_other); // quita los paréntesis
                    $array_treatments[] = $treatments_other;
                    $separate_treatments_other = explode(',', $treatments_other);
                    foreach($separate_treatments_other as $treatment){
                        $cats_treatment_arr[] = trim($treatment);
                    }
                }

                $string_treatments = implode(',', $array_treatments);
            } else {
                // Categorias para gfe y consents nueva
                if(!empty($string_neuro)){
                    $cats_treatment_arr[] = 'NEUROTOXINS';
                }

                if(!empty($string_filler)){
                    $cats_treatment_arr[] = 'FILLERS';
                }
                
                if(!empty($string_iv)){
                    $cats_treatment_arr[] = 'IV THERAPY';
                }

                if(!empty($string_other)){
                    foreach($other_list as $other){
                        $cats_treatment_arr[] = strtoupper($other);
                    }
                }
            }

            if($ivTypeFound && $ntTypeFound){
                $typeTreat = 'Both';
            }

            $patient_agreement_uid = "";

            if(!empty($ent_agreement_iv['DataAgreement']['id'])){
                $patient_agreement_uid = $ent_agreement_iv['DataAgreement']['uid'];
            }

            $agreements   = $this->get_agreements_patient($certTreatment['Patient']['id']);
            $certificates = $this->getCertificatesUser($certTreatment['Patient']['id']);

            //Let my provider choose, eso es para solucionar el porque no aparecia el gfe  
            if(count($data_tr) == 0&&count($idsTreats) == 1 && $idsTreats[0]=="999"){

                //certificate
                $query_certificate = "
                SELECT DC.uid
                FROM cat_treatments_ci CTC
                JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                WHERE DCP.proceed = 1 AND NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                LIMIT 1
                    ";

                $ent_certificate = $this->SysUsers->getConnection()->execute($query_certificate)->fetchAll('assoc');

                if(!empty($ent_certificate)){

                    $data_tr[] = array(
                        'name' => $certTreatment->treatments_string,
                        'treatment_id' => intval($idsTreats[0]),
                        'notes' => $certTreatment['notes'],
                        'price' => 0,
                        'qty' => 0,
                        'certificate' => $ent_certificate[0]["uid"],
                        'consultation' =>  $ent_certificate[0]["uid"],
                        'certificate_status' => 'DONE',
                    );
                }
            }

            $re_array = array(
                'uid' => $certTreatment->uid,
                'latitude' => doubleval($certTreatment->latitude),
                'longitude' => doubleval($certTreatment->longitude),
                'patient_uid' => $certTreatment['Patient']['uid'],
                'assistance_uid' => $certTreatment['Injector']['uid'],
                'schedule_date' => $certTreatment->schedule_date,
                'status' => $certTreatment->status,
                'gfe_status' => $this->gfeStatusForTreatment($certTreatment['Patient']['id'], $certTreatment->id), 
                'distance_in_mi' => $certTreatment->latitude > 0 ? round($certTreatment->distance_in_mi,1) : 0,
                'address' => $sstr_address,
                'patient' => $certTreatment['Patient']['name'] . ' ' . $certTreatment['Patient']['lname'],
                'treatments_string' => $certTreatment->type_uber ? $string_treatments : $certTreatment->treatments_string,
                'treatments' => $string_treatments,
                'treatments_iv' => '',
                'treatments_filler' => '',
                'treatments_data' => $ent_scheduled,
                'treatments_detail' => $data_tr,
                'out_reach' => ($certTreatment->latitude > 0 && round($certTreatment->distance_in_mi,1) > $user['user_radius'] ) ? 1 : 0,
                'sign_patient' => $sign_agreement,
                'number' => $ent_user->phone,
                'number_label' => $this->formatPhoneNumber($ent_user->phone),            
                'is_to_self' => $certTreatment->patient_id == USER_ID,
                'type' => $typeTreat,
                'patient_consent_toxin' => $patient_consent_toxin,
                'patient_uid_consent_toxin' => $patient_uid_consent_toxin,
                'patient_consent_iv_therapy' => $patient_consent_iv_therapy,
                'patient_consent_filler' => $patient_consent_filler,
                'patient_agreement_uid' => $patient_agreement_uid,
                'treatment_categories' => $cats_treatment_arr,
                'treatment_requirements' => $this->treatment_requirements($cats_treatment_arr,$agreements,$certificates,$certTreatment['Patient']['state'],$certTreatment['Patient']['id']),
                'myself' => $certTreatment['Injector']['uid'] == $certTreatment['Patient']['uid'] ? true : false,
            );

            $arr_appotments[] = $re_array;
            if($index == 0){
                $this->set('actual_appointment', $re_array);//summary cp
            }
        }
        $this->set('actual_appointments', $arr_appotments);

        // REQUESTED APPOINTMENTS (Patient to Provider)

        $fields2 = ['DataTreatment.id','DataTreatment.uid','DataTreatment.schedule_date','DataTreatment.status','DataTreatment.address','DataTreatment.treatments','DataTreatment.state','State.name','DataTreatment.patient_id', 'DataTreatment.city','DataTreatment.zip','DataTreatment.suite','DataTreatment.latitude','DataTreatment.longitude','Patient.name','Patient.lname', 'Patient.phone','Patient.uid','DataTreatment.notes'];
        $fields2['treatments_string'] = "(".
        "SELECT GROUP_CONCAT( IF(CTC.name = CT.name,CONCAT(CT.name),CONCAT(CTC.name,' (',SUBSTRING_INDEX(CT.name, ':', 1), ')')) SEPARATOR ', ')
            FROM cat_treatments_ci CT 
            JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
            WHERE FIND_IN_SET(CT.id,DataTreatment.treatments) LIMIT 1)";

        $fields2['treatments_string_id'] = "(SELECT GROUP_CONCAT(CT.id) 
        FROM cat_treatments_ci CT 
        JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
        WHERE FIND_IN_SET(CT.id,DataTreatment.treatments))";

        $_where2 = ['DataTreatment.deleted' => 0, 'DataTreatment.status' => "REQUEST", 'DataTreatment.assistance_id' => USER_ID];

        $reqAppntmntsEnt = $this->DataTreatment->find()->select($fields2)->join([
            'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
            'Patient' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Patient.id = DataTreatment.patient_id AND Patient.deleted = 0'],
        ])->where($_where2)->order(['DataTreatment.schedule_date' => 'ASC'])->toArray();

        
        $requestedAppntmnts = [];
        foreach($reqAppntmntsEnt as $index => $certTreatment){

            $_fields = ['DataTreatmentsPrice.price','DataTreatmentsPrice.treatment_id','Treatments.name','Treatments.qty', 'Treatments.treatment_id'];
            $_fields['certificate'] = "(SELECT DC.uid
                    FROM cat_treatments_ci CTC
                    JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                    JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                    JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                    WHERE CTC.deleted = 0 AND CTC.id = DataTreatmentsPrice.treatment_id AND DCP.proceed = 1 AND NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                    LIMIT 1)";
            $_fields['consultation'] = "(SELECT DC.uid
                    FROM cat_treatments_ci CTC
                    JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                    JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                    JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                    WHERE CTC.deleted = 0 AND CTC.id = DataTreatmentsPrice.treatment_id AND NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                    ORDER BY DCO.modified DESC
                    LIMIT 1)";
            $_fields['certificate_status'] = "(
            SELECT DC.status FROM data_consultation DC 
            WHERE FIND_IN_SET(  (SELECT CTpt.parent_id FROM cat_treatments CTpt WHERE CTpt.id = Treatments.treatment_id LIMIT 1)
                , DC.treatments) AND DC.status = 'DONE' AND DC.patient_id = ".$certTreatment->patient_id." AND DC.deleted = 0 LIMIT 1)";

            $ent_prices = $this->DataTreatmentsPrice->find()->select($_fields)->join([
                'Treatments' => ['table' => 'cat_treatments_ci', 'type' => 'INNER', 'conditions' => 'Treatments.id = DataTreatmentsPrice.treatment_id'],
            ])->where(['DataTreatmentsPrice.deleted' => 0, 'FIND_IN_SET(DataTreatmentsPrice.treatment_id, "' . $certTreatment->treatments . '")' ,'DataTreatmentsPrice.user_id' => USER_ID])->all();
            
            $sign_agreement = false;

            $this->loadModel('SpaLiveV1.Agreement');
            $this->loadModel('SpaLiveV1.SysUsers');
            $this->loadModel('SpaLiveV1.CatAgreements');

            $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $certTreatment->patient_id])->first();
        
            $ent_agreement = $this->CatAgreements->find()->select(['CatAgreements.uid','DataAgreement.id','DataAgreement.deleted'])->join([
                    'DataAgreement' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'CatAgreements.uid = DataAgreement.agreement_uid AND DataAgreement.user_id = ' . $certTreatment->patient_id . ' AND DataAgreement.deleted = 0'],
                ])->where(
                [
                    'CatAgreements.state_id' => $certTreatment->state,
                    'CatAgreements.user_type' => 'patient',
                    'CatAgreements.agreement_type' => 'REGISTRATION',
                    'CatAgreements.deleted' => 0,
                ]
            )->first();
            if (!empty($ent_agreement) && empty($ent_agreement['DataAgreement']['id'])) {
                $sign_agreement = true;
            }

            $data_tr = array();

            if (!empty($ent_prices)) {
                foreach ($ent_prices as $row) {
                    $data_tr[] = array(
                        'name' => $row['Treatments']['name'],
                        'treatment_id' => intval($row['treatment_id']),
                        'notes' => $row['notes'],
                        'price' => intval($row['price']),
                        'qty' => intval($row['Treatments']['qty']),
                        'certificate' => !empty($row['certificate']) ? $row['certificate'] : '',
                        'consultation' => !empty($row['consultation']) ? $row['consultation'] : '',
                        'certificate_status' => !empty($row['certificate_status']) ? $row['certificate_status'] : '',
                    );
                }
            }
            $sstr_address = $certTreatment->address . ', ' . $certTreatment->city . ', ' . $certTreatment['State']['name'] . ' ' . $certTreatment->zip;
            if (!empty($certTreatment->suite)) {
                $sstr_address = $certTreatment->address . ', ' . $certTreatment->suite . ', ' . $certTreatment->city . ', ' . $certTreatment['State']['name'] . ' ' . $certTreatment->zip;
            }


            $cadena = $certTreatment->treatments;
            $idsTreats = explode(",", $cadena);

            //$this->loadModel('SpaLiveV1.CatTreatments');

            $this->loadModel('SpaLiveV1.CatTreatmentsCi');
            $typeTreat = 'n';
            $ntTypeFound = false;
            $ivTypeFound = false;
            foreach($idsTreats as $treat){
                $ent_treatFind = $this->CatTreatmentsCi->find()->where(['CatTreatmentsCi.id' => $treat])->first();
                if($ent_treatFind['category_treatment_id'] == 1001){
                    $typeTreat = 'IV Therapy';
                    $ivTypeFound = true;

                } else {
                    $typeTreat = 'Neurotoxins';
                    $ntTypeFound = true;

                }

            }

            if($ivTypeFound && $ntTypeFound){
                $typeTreat = 'Both';

            }

            $separate_treatments = $Treatments->separate_treatments($certTreatment->treatments_string,$certTreatment->treatments_string_id,$user["user_id"]);

            $array_treatments = [];

            if(!empty($separate_treatments["neurotoxins"])){
                $array_treatments[] = $this->check_training_medical(USER_ID) ? str_ireplace('basic ', '', $separate_treatments["neurotoxins"]) : $separate_treatments["neurotoxins"];
            }

            if(!empty($separate_treatments["fillers"])){
                $array_treatments[] = $separate_treatments["fillers"];
            }
            
            if(!empty($separate_treatments["iv_therapy"])){
                $array_treatments[] = $separate_treatments["iv_therapy"];
            }

            if(!empty($separate_treatments["other_treatments"])){
                // limpian el texto de other treatments
                $treatments_other = $separate_treatments["other_treatments"];
                $treatments_other = preg_replace('/Other Treatments\s*/', '', $treatments_other); // quita la frase
                $treatments_other = preg_replace('/[()]/', '', $treatments_other); // quita los paréntesis
                $array_treatments[] = $treatments_other;
            }

            $string_treatments = implode(',', $array_treatments);

            $re_array = array(
                'uid' => $certTreatment->uid,
                'latitude' => doubleval($certTreatment->latitude),
                'longitude' => doubleval($certTreatment->longitude),
                'patient_uid' => $certTreatment['Patient']['uid'],
                'patient_phone' => $certTreatment['Patient']['phone'],
                'patient_phone_label' => $this->formatPhoneNumber($certTreatment['Patient']['phone']),
                'schedule_date' => $certTreatment->schedule_date,
                'status' => $certTreatment->status,
                'distance_in_mi' => $certTreatment->latitude > 0 ? round($certTreatment->distance_in_mi,1) : 0,
                'address' => $sstr_address,
                'patient' => $certTreatment['Patient']['name'] . ' ' . $certTreatment['Patient']['lname'],
                'treatments_strings' => $string_treatments,
                'treatments' => '',
                'treatments_iv' => '',
                'treatments_detail' => $data_tr,
                'out_reach' => ($certTreatment->latitude > 0 && round($certTreatment->distance_in_mi,1) > $user['user_radius'] ) ? 1 : 0,
                'sign_patient' => $sign_agreement,
                'type' => $typeTreat
            );

            $requestedAppntmnts[] = $re_array;
            if($index == 0){
                $this->set('requested_appointments', $re_array);
            }
        }
        $this->set('requested_appointments', $requestedAppntmnts);
        
        // Aqui iba el codigo de los disponobles para reclamar (summary_available_uber)
        
        // NI IDEA QUE HACE ESTO
        $arr_reclaimends = [];
        
        $fields2['number'] = 'Patient.phone';
        $ent_claimed = $this->DataTreatment->find()->select($fields2)->join([
            'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
            'Patient' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Patient.id = DataTreatment.patient_id AND Patient.deleted = 0'],
            'Injector' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Injector.id = '. USER_ID .' AND Injector.deleted = 0'],
            'DataClaim' => ['table' => 'data_claim_treatments', 'type' => 'INNER', 'conditions' => 'DataClaim.treatment_uid = DataTreatment.uid AND DataClaim.deleted = 0 AND DataClaim.injector_id = '. USER_ID],
            'InjectorClaim' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'InjectorClaim.id = DataTreatment.assistance_id AND InjectorClaim.deleted = 0'],
        ])->where($_where2)->order(['DataTreatment.schedule_date' => 'ASC'])->toArray();
        
        foreach($ent_claimed as $index => $certTreatment){
            
            $ent_treatments = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.name', 'CatCITreatments.category_treatment_id' , 'CatCITreatments.std_price','CTC.id', 'CTC.name', 'CTC.type_uber'])
            ->join([
                'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id'],
            ])->where(['CatCITreatments.deleted' => 0, 'FIND_IN_SET(CatCITreatments.id, "' . $certTreatment->treatments . '")'])->all();
            $arr_treatments_avai = [];
            $arr_treatments_prices = [];
            if(!empty($ent_treatments)){
                foreach ($ent_treatments as $key => $value) {
                    $array_prices = [];
                    if($value['CTC']['type_uber'] == 'NEUROTOXINS ADVANCED' && !$has_advanced_course){
                        continue 2;
                    }
                    if($value['name' ] == 'Let my provider help me decide'){
                        $ent_treatments2 = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.std_price', 'CatCITreatments.name'])->join([
                            'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id AND CatCITreatments.name <> CTC.name'],
                        ])->where(['CatCITreatments.deleted' => 0, 'CatCITreatments.name <>' => 'Let my provider help me decide' ,'CatCITreatments.category_treatment_id' => $value['category_treatment_id' ] ])->all();

                        foreach ($ent_treatments2 as $key => $trea) {
                            $arr_treatments_avai[] = $trea['id'];
                            if(count($array_prices) <= 0){
                                $array_prices[] = $trea['name'] .' $' . ($trea['std_price'] / 100);
                            }else{
                                $array_prices[] = ' ' . $trea['name'] .' $' . ($trea['std_price'] / 100);
                            }
                            
                        }
                    }else {
                        $arr_treatments_avai[] = $value['id'];
                        if(count($array_prices) <= 0){
                            $array_prices[] = $value['name'] .' $' . ($value['std_price'] / 100);
                        }else{
                            $array_prices[] = ' ' . $value['name'] .' $' . ($value['std_price'] / 100);
                        }
                        
                    }
                    if($value['name' ] == 'Let my provider help me decide'){
                        if(count($arr_treatments_prices) <= 0){
                            $arr_treatments_prices[] = $value['CTC']['name'] . ' (' . $value['name'] . '. Treatment pricing: ' . implode(',', $array_prices) . ')' ;
                        }else{
                            $arr_treatments_prices[] = ' ' . $value['CTC']['name'] . ' (' . $value['name'] . '. Treatment pricing: ' . implode(',', $array_prices) . ')' ;
                        }
                    }else{
                        if(count($arr_treatments_prices) <= 0){
                            $arr_treatments_prices[] = $value['CTC']['name'] . ' (' . implode(',', $array_prices) . ')' ;
                        }else{
                            $arr_treatments_prices[] = ' ' . $value['CTC']['name'] . ' (' . implode(',', $array_prices) . ')' ;
                        }
                        
                    }
                }
            }

            $arr_treatments_avai_string = implode(',', $arr_treatments_avai);
            $_fields = ['CatCITreatments.std_price ','CatCITreatments.id','CatCITreatments.name','CatCITreatments.qty','CatCITreatments.treatment_id','CatCITreatments.details','CTC.name'];
            $_fields['certificate'] = "(SELECT DC.uid
                    FROM cat_treatments_ci CTC
                    JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                    JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                    JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                    WHERE CTC.deleted = 0 AND CTC.id = CatCITreatments.id AND DCP.proceed = 1 AND NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                    LIMIT 1)";
            $_fields['consultation'] = "(SELECT DC.uid
                    FROM cat_treatments_ci CTC
                    JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                    JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                    JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                    WHERE CTC.deleted = 0 AND CTC.id = CatCITreatments.id AND NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                    ORDER BY DCO.modified DESC
                    LIMIT 1)";
            $_fields['certificate_status'] = "(
            SELECT DC.status FROM data_consultation DC 
            WHERE FIND_IN_SET(  (SELECT CTpt.parent_id FROM cat_treatments CTpt WHERE CTpt.id = CatCITreatments.id LIMIT 1)
                , DC.treatments) AND DC.status = 'DONE' AND DC.patient_id = ".$certTreatment->patient_id." AND DC.deleted = 0 LIMIT 1)";

            $ent_prices = $this->CatCITreatments->find()->select($_fields)
            ->join([
                'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id AND CatCITreatments.name <> CTC.name'],
            ])->where(['CatCITreatments.deleted' => 0, 'FIND_IN_SET(CatCITreatments.id, "' . $arr_treatments_avai_string . '")'])->all();

            $data_tr = array();

            if (!empty($ent_prices)) {
                foreach ($ent_prices as $row) {
                    $data_tr[] = array(
                        'name' => $row['CTC']['name'].' ('.$row['name'].')',
                        'treatment_id' => intval($row['id']),
                        'notes' => $row['notes'],
                        'price' => intval($row['std_price']),
                        'type' => $row['details'],
                        'qty' => intval($row['qty']),
                        'certificate' => !empty($row['certificate']) ? $row['certificate'] : '',
                        'consultation' => !empty($row['consultation']) ? $row['consultation'] : '',
                        'certificate_status' => !empty($row['certificate_status']) ? $row['certificate_status'] : '',
                    );
                }
            }
            
            // Doesn't show appointments that the CP doesn't have in its Prices
            /* $qtyTreatments = explode(',',$certTreatment->treatments);
            if(count($ent_prices) < count($qtyTreatments)){
                continue;
            } */

            $sign_agreement = false;

            $this->loadModel('SpaLiveV1.Agreement');
            $this->loadModel('SpaLiveV1.SysUsers');
            $this->loadModel('SpaLiveV1.CatAgreements');

            $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $certTreatment->patient_id])->first();
        
            $ent_agreement = $this->CatAgreements->find()->select(['CatAgreements.uid','DataAgreement.id','DataAgreement.deleted'])->join([
                    'DataAgreement' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'CatAgreements.uid = DataAgreement.agreement_uid AND DataAgreement.user_id = ' . $certTreatment->patient_id . ' AND DataAgreement.deleted = 0'],
                ])->where(
                [
                    'CatAgreements.state_id' => $certTreatment->state,
                    'CatAgreements.user_type' => 'patient',
                    'CatAgreements.agreement_type' => 'REGISTRATION',
                    'CatAgreements.deleted' => 0,
                ]
            )->first();
            if (!empty($ent_agreement) && empty($ent_agreement['DataAgreement']['id'])) {
                $sign_agreement = true;
            }
            
            $sstr_address = $certTreatment->city . ', ' . $certTreatment['State']['name'] . ' ' . $certTreatment->zip;
            if (!empty($certTreatment->suite)) {
                $sstr_address = $certTreatment->city . ', ' . $certTreatment['State']['name'] . ' ' . $certTreatment->zip;
            }

            $re_array = array(
                'uid' => $certTreatment->uid,
                'latitude' => doubleval($certTreatment->latitude),
                'longitude' => doubleval($certTreatment->longitude),
                'patient_uid' => $certTreatment['Patient']['uid'],
                'schedule_date' => $certTreatment->schedule_date,
                'status' => $certTreatment->status,
                'distance_in_mi' => $certTreatment->latitude > 0 ? round($certTreatment->distance_in_mi,1) : 0,
                'address' => $sstr_address,
                'patient' => $certTreatment['Patient']['name'] . ' ' . $certTreatment['Patient']['lname'],
                'treatments' => implode(',', $arr_treatments_prices),
                'treatments_detail' => $data_tr,
                'count_injectors' => 1,
                'out_reach' => ($certTreatment->latitude > 0 && round($certTreatment->distance_in_mi,1) > $user['user_radius'] ) ? 1 : 0,
                'sign_patient' => $sign_agreement,
                'notes' => $certTreatment->notes,
            );
            if($this->get_first_cliam_id($certTreatment->uid) == USER_ID){
                $re_array['show_number'] = true;  
                $re_array['number'] = $certTreatment['Patient']['phone'];
            }

            $arr_reclaimends[] = $re_array; 
        }

        $this->set('appointment_reclaimed', $arr_reclaimends);

        // Scheduled appointments
        $this->loadModel('SpaLiveV1.DataTreatment');

        $fields = ['DataTreatment.uid','DataTreatment.schedule_date','DataTreatment.status', 'DataTreatment.address', 'DataTreatment.zip', 'DataTreatment.city', 'State.name', 'DataTreatment.suite','DataTreatment.latitude','DataTreatment.longitude','Patient.name','Patient.lname','Patient.uid','Injector.latitude','Injector.longitude','Injector.uid','Injector.name','Injector.lname', 'treatments_id' => 'DataTreatment.treatments', 'DataTreatment.patient_id','DataTreatment.notes','DataTreatment.type_uber'];
        // $fields['assistance'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.patient_id)";
        // $fields['assistance_uid'] = "(SELECT U.uid FROM sys_users U WHERE U.id = DataTreatment.assistance_id)";
        // $fields['patient_uid'] = "(SELECT U.uid FROM sys_users U WHERE U.id = DataTreatment.patient_id LIMIT 1)";
        $fields['treatments'] = "(SELECT GROUP_CONCAT(CTI.name) FROM cat_treatments_ci CTI WHERE FIND_IN_SET(CTI.id,DataTreatment.treatments))";

        $fields['distance_in_mi'] = "(69.09 * DEGREES(ACOS(LEAST(1.0, COS(RADIANS(DataTreatment.latitude))
                                    * COS(RADIANS(Injector.latitude))
                                    * COS(RADIANS(DataTreatment.longitude - Injector.longitude))
                                    + SIN(RADIANS(DataTreatment.latitude))
                                    * SIN(RADIANS(Injector.latitude))))))";
        
        $_where = ['DataTreatment.deleted' => 0];

        $_where['DataTreatment.status'] = "INIT";
        $_where['DataTreatment.assistance_id'] = USER_ID;
        
        $certTreatment = $this->DataTreatment->find()->select($fields)
        ->join([
                'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
                'Patient' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Patient.id = DataTreatment.patient_id AND Patient.deleted = 0'],
                'Injector' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Injector.id = DataTreatment.assistance_id AND Injector.deleted = 0'],
            ])
            ->where($_where)->order(['DataTreatment.id' => 'DESC'])->all();
                        
        $arr_treatments = array();
        if (!empty($certTreatment)) {
            foreach ($certTreatment as $row) {

                $_fields = ['DataTreatmentsPrice.price','DataTreatmentsPrice.treatment_id','Treatments.name','Treatments.qty', 'Treatments.treatment_id'];
                $_fields['certificate'] = "(SELECT DC.uid
                        FROM cat_treatments_ci CTC
                        JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                        JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                        JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                        WHERE CTC.deleted = 0 AND CTC.id = DataTreatmentsPrice.treatment_id AND DCP.proceed = 1 AND NOW() < DC.date_expiration AND DCO.patient_id = " . $row->patient_id . "
                        LIMIT 1)";
                $_fields['certificate_status'] = "(
                SELECT DC.status FROM data_consultation DC 
                WHERE FIND_IN_SET(  (SELECT CTpt.parent_id FROM cat_treatments CTpt WHERE CTpt.id = Treatments.treatment_id LIMIT 1)
                    , DC.treatments) AND DC.status = 'DONE' AND DC.patient_id = ".$row->patient_id." AND DC.deleted = 0 LIMIT 1)";

                $ent_prices = $this->DataTreatmentsPrice->find()->select($_fields)->join([
                    'Treatments' => ['table' => 'cat_treatments_ci', 'type' => 'INNER', 'conditions' => 'Treatments.id = DataTreatmentsPrice.treatment_id'],
                ])->where(['DataTreatmentsPrice.deleted' => 0, 'FIND_IN_SET(DataTreatmentsPrice.treatment_id, "' . $row->treatments_id . '")' ,'DataTreatmentsPrice.user_id' => USER_ID])->all();

                $data_tr = array();

                if (!empty($ent_prices)) {
                    foreach ($ent_prices as $item) {
                        $data_tr[] = array(
                            'name' => $item['Treatments']['name'],
                            'treatment_id' => intval($item['treatment_id']),
                            'price' => $row->type_uber == 1 ? $item['Treatments']['std_price'] : intval($item['price']),
                            'qty' => intval($item['Treatments']['qty']),
                            'certificate' => !empty($item['certificate']) ? $item['certificate'] : '',
                            'certificate_status' => !empty($item['certificate_status']) ? $item['certificate_status'] : '',
                        );
                    }
                }

                $str_address = $row['address'] . ', ' . $row['city'] . ', ' . $row['State']['name'] . ' ' . $row['zip'];
                if (!empty($row['suite'])) {
                    $str_address = $row['address'] . ', ' . $row['suite'] . ', ' . $row['city'] . ', ' . $row['State']['name'] . ' ' . $row['zip'];
                }

                $arr_treatments[] = array(
                    'treatment_uid' => $row['uid'],
                    'notes' => $row['notes'],
                    'latitude' => doubleval($row['latitude']),
                    'longitude' => doubleval($row['longitude']),
                    'schedule_date' => $row['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm:ss'),
                    'assistance' => $row['Patient']['name'] . ' ' . $row['Patient']['lname'],
                    'assistance_uid' => $row['Injector']['uid'],
                    'patient_uid' => $row['Patient']['uid'],
                    'treatments' => $row['treatments'],
                    'distance_in_mi' => $row->latitude > 0 ? round($row->distance_in_mi,1) : 0,
                    'status' => $row['status'],
                    'address' => $str_address,
                    'out_reach' => ($row->latitude != 0 && round($row->distance_in_mi,1) > $user['user_radius'] ) ? 1 : 0,
                    'treatments_detail' => $data_tr,
                );
            }
            $this->set('scheduled_treatments', $arr_treatments);
        }

        // PENDING APPOINTMENT

        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.DataTreatmentsPrice');
        $this->loadModel('SpaLiveV1.DataPurchases');
        $this->loadModel('SpaLiveV1.DataClaimTreatments');
        $this->loadModel('SpaLiveV1.CatCITreatments');

        $fields = ['DataTreatment.id','DataTreatment.uid','DataTreatment.schedule_date','DataTreatment.status','DataTreatment.address','DataTreatment.treatments','DataTreatment.state','State.name','DataTreatment.patient_id', 'DataTreatment.city','DataTreatment.zip','DataTreatment.suite','DataTreatment.latitude','DataTreatment.longitude','Patient.name','Patient.id','Patient.lname','Patient.uid','Patient.state','Injector.latitude','Injector.longitude','Injector.uid','Injector.name','Injector.lname','DataTreatment.notes', 'DataTreatment.type_uber'];
        // $fields['patient'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.patient_id)";
        // $fields['patient_uid'] = "(SELECT U.uid FROM sys_users U WHERE U.id = DataTreatment.patient_id LIMIT 1)";
        // $fields['assistance_uid'] = "(SELECT U.uid FROM sys_users U WHERE U.id = DataTreatment.assistance_id LIMIT 1)";
        $fields['treatments_string'] = "(SELECT GROUP_CONCAT(CONCAT(CTC.name,' (',CT.name, ')') SEPARATOR ', ') 
                                        FROM cat_treatments_ci CT 
                                        JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
                                        WHERE FIND_IN_SET(CT.id,DataTreatment.treatments) LIMIT 1)";
        
        $fields['treatments_string_id'] = "(SELECT GROUP_CONCAT(CT.id) 
                                        FROM cat_treatments_ci CT 
                                        JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
                                        WHERE FIND_IN_SET(CT.id,DataTreatment.treatments))";

        $fields['distance_in_mi'] = "(69.09 * DEGREES(ACOS(LEAST(1.0, COS(RADIANS(DataTreatment.latitude))
                                    * COS(RADIANS(Injector.latitude))
                                    * COS(RADIANS(DataTreatment.longitude - Injector.longitude))
                                    + SIN(RADIANS(DataTreatment.latitude))
                                    * SIN(RADIANS(Injector.latitude))))))";

        $_where = ['DataTreatment.deleted' => 0];
        // $_where['DataTreatment.status'] = "CONFIRM";

        $_where['OR'] = [['DataTreatment.status' => "CONFIRM"], ['DataTreatment.status' => "DONE", 'DataTreatment.payment' => ""]];

        $_where['DataTreatment.assistance_id'] = USER_ID;
        // $_where[] = "DataTreatment.schedule_date > NOW()";
        // $_where[] = "DataTreatment.schedule_date > DATE_ADD(DATE(NOW()), INTERVAL -2 HOUR)";

        // $certTreatment = $this->DataTreatment->find()->select($fields)->join([
        $certTrtArr = $this->DataTreatment->find()->select($fields)->join([
            'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
            'Patient' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Patient.id = DataTreatment.patient_id AND Patient.deleted = 0'],
            'Injector' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Injector.id = DataTreatment.assistance_id AND Patient.deleted = 0'],
        ])->where($_where)->order(['DataTreatment.schedule_date' => 'ASC'])->toArray();
                        

        $arr_treatments = array();
        $arr_appotments = [];
        // if (!empty($certTreatment)) {
        foreach($certTrtArr as $index => $certTreatment){

            if($certTreatment->type_uber == 1){
                $_fields = ['CatCITreatments.std_price ','CatCITreatments.id','CatCITreatments.name','CatCITreatments.qty', 'CatCITreatments.treatment_id'];
                $_fields['certificate'] = "(SELECT DC.uid
                        FROM cat_treatments_ci CTC
                        JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                        JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                        JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                        WHERE CTC.deleted = 0 AND CTC.id = CatCITreatments.id AND DCP.proceed = 1 AND NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                        LIMIT 1)";
                $_fields['consultation'] = "(SELECT DC.uid
                        FROM cat_treatments_ci CTC
                        JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                        JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                        JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                        WHERE CTC.deleted = 0 AND CTC.id = CatCITreatments.id AND NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                        ORDER BY DCO.modified DESC
                        LIMIT 1)";
                $_fields['certificate_status'] = "(
                SELECT DC.status FROM data_consultation DC 
                WHERE FIND_IN_SET(  (SELECT CTpt.parent_id FROM cat_treatments CTpt WHERE CTpt.id = CatCITreatments.id LIMIT 1)
                    , DC.treatments) AND DC.status = 'DONE' AND DC.patient_id = ".$certTreatment->patient_id." AND DC.deleted = 0 LIMIT 1)";

                $ent_prices = $this->CatCITreatments->find()->select($_fields)
                ->where(['CatCITreatments.deleted' => 0, 'FIND_IN_SET(CatCITreatments.id, "' . $certTreatment->treatments . '")'])->all();

                $data_tr = array();

                if (!empty($ent_prices)) {
                    foreach ($ent_prices as $row) {
                        $data_tr[] = array(
                            'name' => $row['name'],
                            'treatment_id' => intval($row['id']),
                            'notes' => $row['notes'],
                            'price' => intval($row['std_price']),
                            'qty' => intval($row['qty']),
                            'certificate' => !empty($row['certificate']) ? $row['certificate'] : '',
                            'consultation' => !empty($row['consultation']) ? $row['consultation'] : '',
                            'certificate_status' => !empty($row['certificate_status']) ? $row['certificate_status'] : '',
                        );
                    }
                }
            }else{
                $_fields = ['DataTreatmentsPrice.price','DataTreatmentsPrice.treatment_id','Treatments.name','Treatments.qty', 'Treatments.treatment_id'];
                $_fields['certificate'] = "(SELECT DC.uid
                        FROM cat_treatments_ci CTC
                        JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                        JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                        JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                        WHERE CTC.deleted = 0 AND CTC.id = DataTreatmentsPrice.treatment_id AND DCP.proceed = 1 AND NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                        LIMIT 1)";
                $_fields['consultation'] = "(SELECT DC.uid
                        FROM cat_treatments_ci CTC
                        JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                        JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                        JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                        WHERE CTC.deleted = 0 AND CTC.id = DataTreatmentsPrice.treatment_id AND NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                        ORDER BY DCO.modified DESC
                        LIMIT 1)";
                $_fields['certificate_status'] = "(
                SELECT DC.status FROM data_consultation DC 
                WHERE FIND_IN_SET(  (SELECT CTpt.parent_id FROM cat_treatments CTpt WHERE CTpt.id = Treatments.treatment_id LIMIT 1)
                    , DC.treatments) AND DC.status = 'DONE' AND DC.patient_id = ".$certTreatment->patient_id." AND DC.deleted = 0 LIMIT 1)";

                $ent_prices = $this->DataTreatmentsPrice->find()->select($_fields)->join([
                    'Treatments' => ['table' => 'cat_treatments_ci', 'type' => 'INNER', 'conditions' => 'Treatments.id = DataTreatmentsPrice.treatment_id'],
                ])->where(['DataTreatmentsPrice.deleted' => 0, 'FIND_IN_SET(DataTreatmentsPrice.treatment_id, "' . $certTreatment->treatments . '")' ,'DataTreatmentsPrice.user_id' => USER_ID])->all();

                $data_tr = array();

                if (!empty($ent_prices)) {
                    foreach ($ent_prices as $row) {
                        $data_tr[] = array(
                            'name' => $row['Treatments']['name'],
                            'treatment_id' => intval($row['treatment_id']),
                            'notes' => $row['notes'],
                            'price' => intval($row['price']),
                            'qty' => intval($row['Treatments']['qty']),
                            'certificate' => !empty($row['certificate']) ? $row['certificate'] : '',
                            'consultation' => !empty($row['consultation']) ? $row['consultation'] : '',
                            'certificate_status' => !empty($row['certificate_status']) ? $row['certificate_status'] : '',
                        );
                    }
                }else{
                    if($certTreatment->treatments == '999'){
                        $data_tr[] = array(
                            'name' => 'Let my provider choose',
                            'treatment_id' => 999,
                            'notes' => '',
                            'price' => 0,
                            'qty' => 0,
                            'certificate' => '',
                            'consultation' => '',
                            'certificate_status' => '',
                        );
                    }
                }
            }
            $sign_agreement = false;

            $this->loadModel('SpaLiveV1.Agreement');
            $this->loadModel('SpaLiveV1.SysUsers');
            $this->loadModel('SpaLiveV1.CatAgreements');
            $this->loadModel('SpaLiveV1.CatCITreatments');

            $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $certTreatment->patient_id])->first();
        
            $ent_agreement = $this->CatAgreements->find()->select(['CatAgreements.uid','DataAgreement.id','DataAgreement.deleted'])->join([
                    'DataAgreement' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'CatAgreements.uid = DataAgreement.agreement_uid AND DataAgreement.user_id = ' . $certTreatment->patient_id . ' AND DataAgreement.deleted = 0'],
                ])->where(
                [
                    'CatAgreements.state_id' => $certTreatment->state,
                    'CatAgreements.user_type' => 'patient',
                    'CatAgreements.agreement_type' => 'REGISTRATION',
                    'CatAgreements.deleted' => 0,
                    'DataAgreement.deleted' => 0,
                ]
            )->first();
            if (!empty($ent_agreement) && empty($ent_agreement['DataAgreement']['id'])) {
                $sign_agreement = true;
            }

            $sstr_address = $certTreatment->address . ', ' . $certTreatment->city . ', ' . $certTreatment['State']['name'] . ' ' . $certTreatment->zip;
            if (!empty($certTreatment->suite)) {
                $sstr_address = $certTreatment->address . ', ' . $certTreatment->suite . ', ' . $certTreatment->city . ', ' . $certTreatment['State']['name'] . ' ' . $certTreatment->zip;
            }

            $str_query_scheduled = "
            SELECT GROUP_CONCAT(CT.name) product_name, GROUP_CONCAT(DISTINCT CTC.`name`) treatment, GROUP_CONCAT(DISTINCT CTC.type) type 
            FROM cat_treatments_ci CT 
            JOIN cat_treatments_category CTC ON CT.category_treatment_id = CTC.id
            WHERE FIND_IN_SET(CT.id,'".$certTreatment->treatments."')";

            $ent_scheduled = $this->CatCITreatments->getConnection()->execute($str_query_scheduled)->fetchAll('assoc');
            
            //getagreements
            $ent_agreement_toxin = $this->CatAgreements->find()->select(['CatAgreements.uid','DataAgreement.id','DataAgreement.uid','DataAgreement.user_id','DataAgreement.deleted'])->join([
                'DataAgreement' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'CatAgreements.uid = DataAgreement.agreement_uid AND DataAgreement.user_id = ' . $certTreatment['Patient']['id'] . ' AND DataAgreement.deleted = 0'],
            ])->where(
            [
                'CatAgreements.state_id' => 43,
                'CatAgreements.user_type' => 'PATIENT',
                'CatAgreements.agreement_type' => 'REGISTRATION',
                'CatAgreements.deleted' => 0,
            ]
            )->all();
            $patient_consent_toxin = false;
            if (empty($ent_agreement_toxin) && empty($ent_agreement_toxin['DataAgreement']['id'])) {
                $patient_consent_toxin = false;
                $patient_uid_consent_toxin = '';
                $patient_consent_toxin = true;
            }else{
                foreach($ent_agreement_toxin as $agree){
                    //encontrar id paciente
                    $this->set('patient_id_ent', 'ent');
                    $this->set('patient_id_con', $certTreatment['Patient']['id'] == $agree['DataAgreement']['user_id']);
                    if($certTreatment['Patient']['id'] == $agree['DataAgreement']['user_id']){
                        $patient_uid_consent_toxin = $agree['DataAgreement']['uid'];
                        $patient_consent_toxin = true;
                    }
                }
            }

            if($patient_consent_toxin == false){
                $patient_uid_consent_toxin = '';

            }

            //IV therapy agreement
            $ent_agreement_iv = $this->CatAgreements->find()->select(['CatAgreements.uid','DataAgreement.id','DataAgreement.uid','DataAgreement.deleted'])->join([
                'DataAgreement' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'CatAgreements.uid = DataAgreement.agreement_uid AND DataAgreement.user_id = ' . $certTreatment->patient_id . ' AND DataAgreement.deleted = 0'],
            ])->where(
            [
                'CatAgreements.state_id' => 43,
                'CatAgreements.user_type' => 'PATIENT',
                'CatAgreements.agreement_type IN' => ['IVTHERAPHY'],
                'CatAgreements.deleted' => 0,
                'DataAgreement.deleted' => 0,
            ]
            )->first();
           // $this->set('ent_agreement_iv',$ent_agreement_iv);
            if (empty($ent_agreement_iv) && empty($ent_agreement_iv['DataAgreement']['id'])) {
                $patient_consent_iv_therapy = false;
            }else{
                $patient_consent_iv_therapy = true;
                $patient_agreement_uid = $ent_agreement_iv['DataAgreement']['uid'];
            }

            $separate_treatments = $Treatments->separate_treatments($certTreatment->treatments_string,$certTreatment->treatments_string_id,$user["user_id"]);

            $array_treatments = [];
            $cats_treatment_arr = [];

            if(!empty($separate_treatments["neurotoxins"])){
                $array_treatments[] = $separate_treatments["neurotoxins"];
                $cats_treatment_arr[] = 'NEUROTOXINS';
            }

            if(!empty($separate_treatments["iv_therapy"])){
                $array_treatments[] = $separate_treatments["iv_therapy"];
                $cats_treatment_arr[] = 'IV THERAPY';
            }

            if(!empty($separate_treatments["fillers"])){
                $array_treatments[] = $separate_treatments["fillers"];
                $cats_treatment_arr[] = 'FILLERS';
            }

            if(!empty($separate_treatments["other_treatments"])){
                // limpian el texto de other treatments
                $treatments_other = $separate_treatments["other_treatments"];
                $treatments_other = preg_replace('/Other Treatments\s*/', '', $treatments_other); // quita la frase
                $treatments_other = preg_replace('/[()]/', '', $treatments_other); // quita los paréntesis
                $array_treatments[] = $treatments_other;
                $separate_treatments_other = explode(',', $treatments_other);
                foreach($separate_treatments_other as $treatment){
                    $cats_treatment_arr[] = trim($treatment);
                }
            }

            $treatments = implode(',', $array_treatments);

            $cadena = $certTreatment->treatments;
            $idsTreats = explode(",", $cadena);
            $this->loadModel('SpaLiveV1.CatTreatmentsCi');
            /*$cats_treatment = $this->CatTreatmentsCi->find()
                ->select(['name' => 'CTC.type', 'treatment_name' => 'CatTreatmentsCi.name'])
                ->join([
                    'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatTreatmentsCi.category_treatment_id'],    
                ])
                ->where(['CatTreatmentsCi.id IN ('.$certTreatment->treatments.')'])
                ->group(['CTC.type'])
            ->toArray();      

            foreach($cats_treatment as $cat){
                if($cat['name'] == 'NEUROTOXINS BASIC' || $cat['name'] == 'NEUROTOXINS ADVANCED'){
                    if(!in_array('NEUROTOXINS', $cats_treatment_arr)){
                        $cats_treatment_arr[] = 'NEUROTOXINS';
                    }
                }else if($cat['name'] == 'OTHER TREATMENTS'){
                    $cats_treatment_arr[] = $cat['treatment_name'];
                }else{
                    $cats_treatment_arr[] = $cat['name'];
                }
            }*/

            $agreements   = $this->get_agreements_patient($certTreatment['Patient']['id']);
            $certificates = $this->getCertificatesUser($certTreatment['Patient']['id']);

            //Let my provider choose, eso es para solucionar el porque no aparecia el gfe  
            if(count($data_tr) == 0&&count($idsTreats) == 1 && $idsTreats[0]=="999"){

                //certificate
                $query_certificate = "
                SELECT DC.uid
                FROM cat_treatments_ci CTC
                JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                WHERE DCP.proceed = 1 AND NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                LIMIT 1
                    ";

                $ent_certificate = $this->SysUsers->getConnection()->execute($query_certificate)->fetchAll('assoc');

                //consultation no se para que esta consulta si es el mismo uid que el certificate
                /*$query_consultation = "
                SELECT DC.uid
                FROM cat_treatments_ci CTC
                JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                WHERE NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                ORDER BY DCO.modified DESC
                LIMIT 1
                ";*/

                //$ent_consultation = $this->SysUsers->getConnection()->execute($query_consultation)->fetchAll('assoc');

                if(!empty($ent_certificate)){

                    $data_tr[] = array(
                        'name' => $certTreatment->treatments_string,
                        'treatment_id' => intval($idsTreats[0]),
                        'notes' => $certTreatment['notes'],
                        'price' => 0,
                        'qty' => 0,
                        'certificate' => $ent_certificate[0]["uid"],
                        'consultation' =>  $ent_certificate[0]["uid"],
                        'certificate_status' => 'DONE',
                    );
                }
            }

            $re_array = array(
                'uid' => $certTreatment->uid,
                'latitude' => doubleval($certTreatment->latitude),
                'longitude' => doubleval($certTreatment->longitude),
                'patient_uid' => $certTreatment['Patient']['uid'],
                'assistance_uid' => $certTreatment['Injector']['uid'],
                'schedule_date' => $certTreatment->schedule_date,
                'status' => $certTreatment->status,
                'gfe_status' => $this->gfeStatusForTreatment($certTreatment['Patient']['id'], $certTreatment->id), 
                'distance_in_mi' => $certTreatment->latitude > 0 ? round($certTreatment->distance_in_mi,1) : 0,
                'address' => $sstr_address,
                'patient' => $certTreatment['Patient']['name'] . ' ' . $certTreatment['Patient']['lname'],
                'treatments_string' => $certTreatment->treatments == '999' ? 'Let my provider choose' : $certTreatment->treatments_string,     
                'treatments' => $treatments,
                'treatments_iv' => '',
                'treatments_filler' => '',
                'treatments_data' => $ent_scheduled,
                'treatments_detail' => $data_tr,
                'out_reach' => ($certTreatment->latitude > 0 && round($certTreatment->distance_in_mi,1) > $user['user_radius'] ) ? 1 : 0,
                'sign_patient' => $sign_agreement,
                'number' => $ent_user->phone,
                'number_label' => $this->formatPhoneNumber($ent_user->phone),
                'patient_consent_iv_therapy' => $patient_consent_iv_therapy,
                'patient_consent_toxin' => $patient_consent_toxin,
                'patient_uid_consent_toxin' => $patient_uid_consent_toxin,
                'patient_agreement_uid' => $patient_agreement_uid,
                'treatment_categories' => $cats_treatment_arr,
                'treatment_requirements' => $this->treatment_requirements(
                    $cats_treatment_arr,
                    $agreements,
                    $certificates,
                    $certTreatment['Patient']['state'],
                    $certTreatment['Patient']['id']
                ),
            );
            $arr_appotments[] = $re_array;
        }
        $this->set('pending_appointments', $arr_appotments);
        //CI NETWORK SUMMARY

        $result_array = array();
        $arr_search = array();
        $grand_total = 0;
        $arr_search[] = array('user_id' => USER_ID);
        $level_parent_id = USER_ID;

        $should_continue = true;
        $level_count = 0;

        $this->loadModel('SpaLiveV1.DataNetwork');
        $network = $this->__child_invitees(1, USER_ID, true);
        $tmp = [];
        foreach($network as $item){
            $_level = $item['level'];
            $key = intval($_level-1);
            if( isset($tmp[$key]) ){
                $tmp[$key]['data'][] = $item;
            }else{
                $tmp[$key] = [
                    'level' => $item['level'],
                    'data' => array($item)
                ];
            }
        }
        $result = [];
        if(isset($tmp[0])){$result[] = $tmp[0];}
        if(isset($tmp[1])){$result[] = $tmp[1];}
        if(isset($tmp[2])){$result[] = $tmp[2];}
        if(isset($tmp[3])){$result[] = $tmp[3];}
        
        $arr_network = array(
            'network' => $tmp,
            'total' => sizeof($network),
        );

        $this->set('ci_network', $arr_network);
        $this->set('patients', $this->find_patients(true));

        $purchase = $this->DataPurchases->find()->select(['DataPurchases.id'])
        ->join([
            'DPD' => ['table' => 'data_purchases_detail', 'type' => 'INNER', 'conditions' => 'DPD.purchase_id = DataPurchases.id'],
            'CP' => ['table' => 'cat_products', 'type' => 'INNER', 'conditions' => 'CP.id = DPD.product_id'],
        ])
        ->where(['DataPurchases.user_id' => USER_ID, 'DataPurchases.deleted' => 0, 'DataPurchases.payment <>' => '', 'CP.deleted' => 0, 'CP.category IN' => array('NEUROTOXIN PACKAGES', 'FILLERS', 'IV VIALS', 'NEUROTOXINS', 'SKIN PRODUCTS')])->first();

        $this->set('button_patients', !empty($purchase) ? true : false);

        //PAST Treatments

        $this->loadModel('SpaLiveV1.DataTreatment');

        $fields = ['DataTreatment.id','DataTreatment.uid','DataTreatment.promo_code','DataTreatment.schedule_date','DataTreatment.status','DataTreatment.amount','DataTreatment.tip','DataTreatment.address','DataTreatment.city','State.name','DataTreatment.zip', 'DataTreatment.clinic_patient_id','Review.score','Review.comments','Review.id','Note.notes', 'DataTreatment.suite','DataTreatment.notes','DataTreatment.patient_id','Payment.subtotal','Payment.total'];
        $fields['injector'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.assistance_id)";
        $fields['patient'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.patient_id)";
        $fields['clinic_patient'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.clinic_patient_id)";
        $fields['treatments_text'] = "((SELECT GROUP_CONCAT(CONCAT(CTC.name,' (',CT.name, ')') SEPARATOR ', ') 
                                        FROM cat_treatments_ci CT 
                                        JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
                                        WHERE FIND_IN_SET(CT.id,DataTreatment.treatments) LIMIT 1))";

        $fields['treatments_string_id'] = "(SELECT GROUP_CONCAT(CT.id) 
                                        FROM cat_treatments_ci CT 
                                        JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
                                        WHERE FIND_IN_SET(CT.id,DataTreatment.treatments))";

        $fields['treatments_detal'] = "(SELECT GROUP_CONCAT(cat_treatment_id) FROM data_treatment_detail WHERE treatment_id = DataTreatment.id)";
        $fields['images'] = "(GROUP_CONCAT(Image.file_id))";
        $fields['images_after'] = "(GROUP_CONCAT(ImageAfter.file_id))";
        $fields['images_before'] = "(GROUP_CONCAT(ImageBefore.file_id))";
        $_where = ['DataTreatment.deleted' => 0];
        $_where['DataTreatment.status in'] = array("DONE", "DONESELFTREATMENT");
        $_where['DataTreatment.payment <>'] = '';
        $_where['DataTreatment.assistance_id'] = USER_ID;
        // $_where['Review.deleted'] = 0;

        $certTreatment = $this->DataTreatment->find()->select($fields)
        ->join([
            'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
            'Review' => ['table' => 'data_treatment_reviews', 'type' => 'LEFT', 'conditions' => 'Review.treatment_id = DataTreatment.id'],
            'Note' => ['table' => 'data_treatment_notes', 'type' => 'LEFT', 'conditions' => 'Note.treatment_id = DataTreatment.id'],
            'Image' => ['table' => 'data_treatment_image', 'type' => 'LEFT', 'conditions' => 'Image.treatment_id = DataTreatment.id'],
            'ImageAfter' => ['table' => 'data_treatment_image', 'type' => 'LEFT', 'conditions' => 'ImageAfter.treatment_id = DataTreatment.id AND ImageAfter.typeImage = "after"'],
            'ImageBefore' => ['table' => 'data_treatment_image', 'type' => 'LEFT', 'conditions' => 'ImageBefore.treatment_id = DataTreatment.id AND ImageBefore.typeImage = "before"'],
            'Payment' => ['table' => 'data_payment', 'type' => 'LEFT', 'conditions' => 'Payment.uid = DataTreatment.uid'],
            ]
        )->where($_where)->group(['DataTreatment.id'])->order(['DataTreatment.id' => 'DESC']);
                        
        $arr_treatments = array();
        if (!empty($certTreatment)) {
            // pr($certTreatment);exit;
            foreach ($certTreatment as $row) { $this->log(__LINE__ . ' ' . json_encode($row));

                $ss_address = $row['address'] . ', ' . $row['city'] . ', ' . $row['State']['name'] . ' ' . $row['zip'];
                if (!empty($row['suite'])) {
                    $ss_address = $row['address'] . ', ' . $row['suite'] . ', ' . $row['city'] . ', ' . $row['State']['name'] . ' ' . $row['zip'];
                }

                $begin_after_pictures = date('Y-m-d', strtotime($row['schedule_date']->i18nFormat('yyyy-MM-dd') . ' + 14 days'));
                $end_after_pictures = date('Y-m-d', strtotime($row['schedule_date']->i18nFormat('yyyy-MM-dd') . ' + 35 days'));
                $show_add_after_pictures = (date('Y-m-d') >= $begin_after_pictures && date('Y-m-d') <= $end_after_pictures) ? true : false;
                
                $this->loadModel('SpaLiveV1.DataTreatmentImage');
                $images_after = $this->DataTreatmentImage->find()->where(['DataTreatmentImage.treatment_id' => $row['id'], 'DataTreatmentImage.typeImage' => 'after'])->toArray();

                $this->loadModel('SpaLiveV1.CatCITreatments');
                $citreatments = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.name', 'Cat.name'])
                ->join([
                    'Cat' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'Cat.id = CatCITreatments.category_treatment_id'],
                    ]
                )->where(['CatCITreatments.id IN' => explode(',', $row['treatments_detal'])])->all();
                //pr($citreatments);exit;
                $array_treatments_name = [];
                foreach ($citreatments as $citreatment) {
                    $array_treatments_name[] = $citreatment['name'] . ' (' . $citreatment['Cat']['name'] . ')';
                }
                
                $show_add_after_pictures = $show_add_after_pictures && count($images_after) == 0 ? true : false;

                
                // $cadena = $row['treatments_detal'];
                // //$this->set('rowww', $row);
                // $idsTreats = explode(",", $cadena);

                // $this->loadModel('SpaLiveV1.CatTreatmentsCi');
                $typeTreat = 'n';
                $ntTypeFound = false;
                $ivTypeFound = false;
                $otherTreatmentTypeFound = false;
                $fillersTypeFound = false;

                // foreach($idsTreats as $treat){
                //     $ent_treatFind = $this->CatTreatmentsCi->find()->where(['CatTreatmentsCi.id' => $treat])->first();
                //     if($ent_treatFind['category_treatment_id'] == 1001){
                //         $typeTreat = 'IV Therapy';
                //         $ivTypeFound = true;

                //     } else {
                //         $typeTreat = 'Neurotoxins';
                //         $ntTypeFound = true;

                //     }

                // }

                // if($ivTypeFound && $ntTypeFound){
                //     $typeTreat = 'Both';

                // }

                $separate_treatments = $Treatments->separate_treatments($row['treatments_text'],$row['treatments_string_id'],$user["user_id"]);

                $array_treatments = [];

                if(!empty($separate_treatments["neurotoxins"])){
                    $array_treatments[] = $this->check_training_medical(USER_ID) ? str_ireplace('basic ', '', $separate_treatments["neurotoxins"]) : $separate_treatments["neurotoxins"];
                    // condicion para tipo de tratamiento
                    $typeTreat = 'Neurotoxins';
                    $ntTypeFound = true;
                }

                if(!empty($separate_treatments["fillers"])){
                    $array_treatments[] = $separate_treatments["fillers"];
                    // condicion para tipo de tratamiento
                    $typeTreat = 'Fillers';
                    $fillersTypeFound = true;
                }

                if(!empty($separate_treatments["iv_therapy"])){
                    $array_treatments[] = $separate_treatments["iv_therapy"];
                    // condicion para tipo de tratamiento
                    $typeTreat = 'IV Therapy';
                    $ivTypeFound = true;
                }

                if(!empty($separate_treatments["other_treatments"])){
                    // limpian el texto de other treatments
                    $treatments_other = $separate_treatments["other_treatments"];
                    $treatments_other = preg_replace('/Other Treatments\s*/', '', $treatments_other); // quita la frase
                    $treatments_other = preg_replace('/[()]/', '', $treatments_other); // quita los paréntesis
                    $array_treatments[] = $treatments_other;
                    // condicion para tipo de tratamiento
                    $typeTreat = 'Other Treatments';
                    $otherTreatmentTypeFound = true;
                }

                $string_treatments = implode(',', $array_treatments);
                
                if($ivTypeFound && $ntTypeFound){
                    $typeTreat = 'Both';
                }

                if($otherTreatmentTypeFound && !$ivTypeFound && !$ntTypeFound && !$fillersTypeFound ){
                    $typeTreat = 'Other Treatments';
                }

                //$calculate_promo_code = $Treatments->calculate_promo_code($row['amount'],$row['promo_code']);
                $discount = $row['Payment']['subtotal'] - $row['Payment']['total'];  $this->log(__LINE__ . ' ' . json_encode($discount));
                if($discount > 0){
                    $discount = "$" . number_format(($discount/100), 2, '.', ',');
                } else{
                    $discount = '';
                }
                $arr_treatments[] = array(
                    'id' => $row['id'],
                    'images' => (isset($row['images']) && $row['images'] ? array_values(array_unique(explode(',', $row['images']))) : []),
                    'images_after' => (isset($row['images_after']) && $row['images_after'] ? array_values(array_unique(explode(',', $row['images_after']))) : []),
                    'images_before' => (isset($row['images_before']) && $row['images_before'] ? array_values(array_unique(explode(',', $row['images_before']))) : []),
                    'treatment_uid' => $row['uid'],
                    'notes' => $row['notes'],
                    'schedule_date' => $row['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm'),
                    'status' => $row['status'],
                    'treatments_strings' => $string_treatments,
                    'treatments' => $separate_treatments["neurotoxins"],
                    'treatments_iv' => $separate_treatments["iv_therapy"],
                    'injector' => $row['injector'],
                    'patient' => $row['clinic_patient_id'] > 0 ? $row['clinic_patient'] : $row['patient'],
                    'amount' => $row['amount'],
                    'discount' => $discount,
                    'total' =>  $row['Payment']['total']+$row['tip'],
                    'tip' => $row['tip'],
                    'clinic' => $row['clinic_patient_id'] > 0 ? $row['bname'] : '',
                    'address' => $ss_address,
                    'reviewed' => empty($row['Review']['score']) ? false : true,
                    'score' => empty($row['Review']['score']) ? 0 : $row['Review']['score'],
                    'comments' => empty($row['Review']['comments']) ? 'No comments' : $row['Review']['comments'],
                    'review_id' => empty($row['Review']['id']) ? 0 : intval($row['Review']['id']),
                    'notes' => empty($row['Note']['notes']) ? 'Without notes.' : trim($row['Note']['notes']),
                    'show_add_after_pictures' => $show_add_after_pictures,
                    'begin_after_pictures' => $begin_after_pictures,
                    'end_after_pictures' => $end_after_pictures,
                    'treatmentsids' => $row['treatments'],
                    'type' => $typeTreat,
                );
            }  
            $this->set('past_treatments', $arr_treatments);
        }

        $this->loadModel('SpaLiveV1.DataPurchases');
        $orders = $this->DataPurchases->find()->select(['DataPurchases.id'])->join([
            'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataPurchases.user_id']
        ])->where(['DataPurchases.user_id' => USER_ID, 'DataPurchases.payment <>' => ''])->count();
        $this->set('has_orders', $orders > 0 ? true : false);

        // SCHEDULED EXAM PATIENT

        $str_now = date('Y-m-d H:i:s');
        $str_query_scheduled = "
            SELECT 
                DC.uid, DC.status, DC.meeting, DC.meeting_pass,CONCAT(U.`name`,\" \",U.lname) patient,CONCAT(UA.`name`,\" \",UA.lname) assistance,U.state, DC.schedule_date, DC.treatments, UA.uid assistance_uid,
                (SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DC.treatments)) treatments
            FROM data_consultation DC
            JOIN sys_users U ON U.id = DC.patient_id
            #JOIN sys_users Inj ON Inj.id = DC.createdby
            LEFT JOIN sys_users UA ON UA.id = DC.assistance_id
            WHERE DC.deleted = 0 AND DC.createdby = " . USER_ID . " AND DC.status = 'INIT' AND DC.schedule_by > 0
            AND TIMESTAMPDIFF(MINUTE, DC.schedule_date, '{$str_now}') <= 15 "; //AND DC.schedule_date > NOW()" ;

        $ent_scheduled = $this->DataConsultation->getConnection()->execute($str_query_scheduled)->fetchAll('assoc');

        $arr_scheduled = array();
        if(!empty($ent_scheduled)){
            foreach ($ent_scheduled as $row) {
                $arr_scheduled[] = array(
                    'uid' => $row['uid'],
                    'patient_name' => $row['patient'],
                    'treatment_uid' => $row['uid'],
                    'meeting' => $row['meeting'],
                    'meeting_pass' => $row['meeting_pass'],
                    'schedule_date' => $row['schedule_date'],
                    'treatments' => $row['treatments'],
                    'assistance' => $row['assistance'] ? $row['assistance'] : '',
                    'assistance_uid' => $row['assistance_uid'] ? $row['assistance_uid'] : '',
                    'status' => $row['status'],
                );
            }
        }
        $this->set('scheduled_eval_patient', $arr_scheduled);
    
        // CHECK CPR CERTIFICATION
        $this->loadModel('SpaLiveV1.DataUserCprLicence');
        $ent_cpr = $this->DataUserCprLicence->find()->where(['DataUserCprLicence.user_id' => USER_ID])->first();

        $this->set('request_cpr', empty($ent_cpr) ? true : false);

        // CHECK TRAININGS

        $this->loadModel('SpaLiveV1.DataTrainings');
        $this->loadModel('SpaLiveV1.CatTrainigs');

        $now = date('Y-m-d H:i:s');

        $_fields = ['CatTrainigs.id', 'CatTrainigs.title', 'CatTrainigs.scheduled', 'CatTrainigs.neurotoxins', 'CatTrainigs.fillers', 'CatTrainigs.materials', 'CatTrainigs.available_seats', 'CatTrainigs.level','State.name','State.abv','CatTrainigs.address','CatTrainigs.zip','CatTrainigs.city', 'data_training_id' => 'DataTrainigs.id'];
        $_fields['assistants'] = "(SELECT COUNT(id) from data_trainings DT WHERE DT.training_id = CatTrainigs.id AND DT.deleted = 0)";
        $_fields['enrolled'] = "(SELECT COUNT(id) from data_trainings DT WHERE DT.training_id = CatTrainigs.id AND DT.deleted = 0 AND DT.user_id = " . USER_ID . " )";
        $_join = [
            'DataTrainigs' => ['table' => 'data_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainigs.training_id'],
            'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = CatTrainigs.state_id']
        ];
        $_where = ['CatTrainigs.level' => 'LEVEL 1','DataTrainigs.user_id' => USER_ID, 'DataTrainigs.deleted' => 0, 'CatTrainigs.deleted' => 0,'(DATE_FORMAT(CatTrainigs.scheduled, "%Y-%m-%d 12:00:00") > "' . $now . '")'];

        $enrolled_trainings  = $this->CatTrainigs->find()->select($_fields)
        ->join($_join)
        ->where($_where)->all();

        $tr_result = array();

        if (!empty($enrolled_trainings)) {

            foreach($enrolled_trainings as $row) {

                $seats = $row['available_seats'] - $row['assistants'];
                if($seats <= 0) $seats = 0;

                $address = $row->address.', '.$row->city.', '.$row->State['abv'].' '.$row->zip;
                $tr_result[] = array(
                    'id'            => $row['id'],
                    'title'         => $row['title'],
                    'scheduled'   => $row['scheduled']->i18nFormat('MM-dd-Y hh:mm a'),
                    'available_seats' => $seats,
                    'status' => 'ENROLLED',
                    'address' => $address,
                    'level' => $row['level'],
                );
            }
        } 

        $this->set('booked_trainings_basic', $tr_result);

        $_where = ['CatTrainigs.level' => 'LEVEL 2','DataTrainigs.user_id' => USER_ID, 'DataTrainigs.deleted' => 0, 'CatTrainigs.deleted' => 0,'(DATE_FORMAT(CatTrainigs.scheduled, "%Y-%m-%d 12:00:00") > "' . $now . '")'];

        $enrolled_trainings  = $this->CatTrainigs->find()->select($_fields)
        ->join($_join)
        ->where($_where)->all();

        $tr_result = array();

        if (!empty($enrolled_trainings)) {

            foreach($enrolled_trainings as $row) {

                $seats = $row['available_seats'] - $row['assistants'];
                if($seats <= 0) $seats = 0;

                $address = $row->address.', '.$row->city.', '.$row->State['abv'].' '.$row->zip;
                $tr_result[] = array(
                    'id'            => $row['id'],
                    'title'         => $row['title'],
                    'scheduled'   => $row['scheduled']->i18nFormat('MM-dd-Y hh:mm a'),
                    'available_seats' => $seats,
                    'status' => 'ENROLLED',
                    'address' => $address,
                    'level' => $row['level'],
                );
            }
        } 

        $this->set('booked_trainings_advanced', $tr_result);

        $request_training = false;
        $request_training_advanced = false;
        $ent_data_training = $this->DataTrainings->find()->join([
            'CatTrainigs' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainings.training_id'],
        ])->where(['CatTrainigs.level' => 'LEVEL 1','DataTrainings.user_id' => USER_ID,'DataTrainings.deleted' => 0])->first();

        if (empty($ent_data_training)) {
            $request_training = true;
        }

        $level2_id = 44; // FIEX ID OF Advanced training

        $this->loadModel('SpaLiveV1.DataPurchases');

        $_ent_purchases =$this->DataPurchases->find()
        ->join([
            'DataPurchasesDetail' => ['table' => 'data_purchases_detail', 'type' => 'INNER', 'conditions' => 'DataPurchasesDetail.purchase_id = DataPurchases.id']
        ])
        ->where(['DataPurchases.user_id' => USER_ID, 'DataPurchases.payment <>' => '','DataPurchasesDetail.product_id' => $level2_id,'DataPurchases.deleted' => 0])->first();


        if (!empty($_ent_purchases)) {
            $ent_data_training = $this->DataTrainings->find()->join([
            'CatTrainigs' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainings.training_id'],
            ])->where(['CatTrainigs.level' => 'LEVEL 2','DataTrainings.user_id' => USER_ID,'DataTrainings.deleted' => 0])->first();

            if (empty($ent_data_training)) {
                $request_training_advanced = true;
            }
        }

        $this->set('request_schedule_training', $request_training);
        $this->set('request_schedule_training_advanced', $request_training_advanced);

        $this->requestSubscriptions();

        // CAT LABELS

        $findLabels = $this->CatLabels->find()->select(['CatLabels.key_field', 'CatLabels.value'])->where(['CatLabels.deleted' => 0])->toArray();
        $labels = [];
        foreach($findLabels as $item){
            $labels[$item->key_field] = str_replace('GFE_COST', $this->total/100 , $item->value);
            $labels[$item->key_field] = str_replace('GFE_DOUBLE', ($this->total/100)*2, $labels[$item->key_field]);
            $labels[$item->key_field] = str_replace('GFE_PAYMENT', $this->paymente_gfe/100 , $labels[$item->key_field]);
            $labels[$item->key_field] = str_replace('CI_REGISTRATION', $this->register_total/100 , $labels[$item->key_field]);
            $labels[$item->key_field] = str_replace('CI_REFUND', $this->register_refund/100 , $labels[$item->key_field]);
            $labels[$item->key_field] = str_replace('CI_ADVANCED', $this->training_advanced/100 , $labels[$item->key_field]);
        }
        $this->set('labels', $labels);

        //Request DATA

        // summary > request_photo : true/false    
        // summary > request_services : true/false - request_stripe    

        // SERVICES

        $this->loadModel('SpaLiveV1.DataTreatmentsPrice');
        
        $r_services = true;
        $r_available = true;
        $ent_tprice = $this->DataTreatmentsPrice->find()->where(['DataTreatmentsPrice.user_id' => USER_ID])->first();
        if (!empty($ent_tprice)) {
            $this->loadModel('SpaLiveV1.DataScheduleModel');
            $ent_sch_model = $this->DataScheduleModel->find()->where(['DataScheduleModel.deleted' => 0, 'DataScheduleModel.injector_id' => USER_ID])->first();

            if (!empty($ent_sch_model) && !empty($ent_sch_model->days)) {
                $r_available = false;
                $r_services = false;
            }
        }

        $this->set('request_services', $r_services);
        $this->set('request_availability', $r_available);

        //Request Fill Profil
        $r_profile = true;
        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();
        if (!empty($ent_user)) {
            if ($ent_user->photo_id != 93) {
                $r_profile = false;
            }
        }
        $this->set('request_profile', $r_profile);

        // $this->set('stripe_button', true);
        $this->set('stripe_button', $this->checkStripeACcount());

        if(strtoupper($userType) == 'INJECTOR' || strtoupper($userType) == 'GFE+CI'){
            $this->set('trainings', $this->checkTrainings());
        }

        $this->loadModel('SpaLiveV1.DataTrainers');

        $ent_trainer = $this->DataTrainers->find()->where(['DataTrainers.injector_id' => USER_ID, 'DataTrainers.deleted' => 0])->first();

        if(empty($ent_trainer)){
            $this->set('show_trainer', true);
        }else{
            $this->set('show_trainer', false);
        }

        // Provides treatments

        $this->loadModel('SpaLiveV1.CatTreatmentsCi');
        $this->loadModel('SpaLiveV1.CatTreatmentsCategory');
        $this->loadModel('SpaLiveV1.DataTreatmentsPrices');

        $array_treatments = array();

        $cat_categorys = $this->CatTreatmentsCategory->find()->where(['CatTreatmentsCategory.deleted' => 0])->all();

        if(Count($cat_categorys) > 0){
            foreach($cat_categorys as $row){
                $array_list = array();
                $cat_treatment = $this->CatTreatmentsCi->find()->select(['CatTreatmentsCi.id','CatTreatmentsCi.name','ST.description_injector','Treatments.alias', 'ST.image'])
                ->join([
                    'Treatments' => ['table' => 'data_treatments_prices', 'type' => 'INNER', 'conditions' => 'Treatments.treatment_id = CatTreatmentsCi.id AND Treatments.deleted = 0'],
                    'CT' => ['table' => 'cat_treatments', 'type' => 'INNER', 'conditions' => 'CT.id = CatTreatmentsCi.treatment_id'],
                    'ST' => ['table' => 'sys_treatments_ot', 'type' => 'LEFT', 'conditions' => 'ST.id = CT.other_treatment_id'],
                ])
                ->where(['CatTreatmentsCi.category_treatment_id' => $row->id, 'Treatments.user_id' => USER_ID, 'CatTreatmentsCi.deleted' => 0])
                ->order(['CatTreatmentsCi.id' => 'DESC'])->all();

                if(Count($cat_treatment) > 0) {
                    foreach($cat_treatment as $row2){
                        $product_name = $row2['name'];

                        if($row2["Treatments"]["alias"]!=""||$row2["Treatments"]["alias"]!=null){
                            $product_name = $row2["Treatments"]["alias"];
                        }

                        array_push($array_list, array(
                            'id' => $row2['id'],
                            'name' => $product_name,
                            'description' =>'<div>' . $row2['ST']['description_injector'] . '</div>',
                            'image' => empty($row2['ST']['image']) ? '' : '<img src="' . $row2['ST']['image'] . '" width="400" height="210" >',
                        ));
                    }
                }

                // parche

                if($row['name'] == 'Basic Neurotoxins'){
                    $row['name'] = $this->check_training_medical(USER_ID) ? 'Neurotoxins' : $row['name'];
                }

                $array_treatments[] = array(
                    'title' => $row['name'],
                    'description' => $row['description'],
                    'image' => $row['image'],
                    'data' => $array_list
                );
            }
        }

        $this->set('provide_treatments', $array_treatments);   

        // Treatment invitations
        $this->loadModel('SpaLiveV1.DataTreatment');

        $fields = ['DataTreatment.uid','DataTreatment.schedule_date','DataTreatment.status','DataTreatment.address','DataTreatment.zip','DataTreatment.city','DataTreatment.suite','DataTreatment.notes','DataTreatment.treatments','DataTreatment.patient_id'];
        $fields['patient'] = "(SELECT CONCAT(' ', U.name, ' ', SUBSTRING(U.lname, 1, 1)) FROM sys_users U WHERE U.id = DataTreatment.patient_id)";
        $fields['patient_email'] = "(SELECT U.email FROM sys_users U WHERE U.id = DataTreatment.patient_id)";
        $fields['patient_phone'] = "(SELECT U.phone FROM sys_users U WHERE U.id = DataTreatment.patient_id)";
        $fields['patient_info'] = "(SELECT CONCAT_WS('||', U.uid, U.short_uid) FROM sys_users U WHERE U.id = DataTreatment.patient_id)";
        $fields['txt_state'] = "(SELECT name FROM cat_states CS WHERE CS.id = DataTreatment.state LIMIT 1)";
        $fields['treatments_string'] = "(SELECT GROUP_CONCAT(CONCAT(CTC.name,' (',SUBSTRING_INDEX(CT.name, ':', 1), ')') SEPARATOR ', ') 
                                        FROM cat_treatments_ci CT 
                                        JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
                                        WHERE FIND_IN_SET(CT.id,DataTreatment.treatments) LIMIT 1)";

        $fields['treatments_string_id'] = "(SELECT GROUP_CONCAT(CT.id) 
                                        FROM cat_treatments_ci CT 
                                        JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
                                        WHERE FIND_IN_SET(CT.id,DataTreatment.treatments))";
        $_where = ['DataTreatment.deleted' => 0];
        // $_where['DataTreatment.status !='] = "DONE";
        $_where['DataTreatment.assistance_id'] = USER_ID;
        // $_where['DataTreatment.status !='] = "CANCEL";
        $_where['OR'] = [['DataTreatment.status' => "INIT"], ['DataTreatment.status' => "INVITATION"], ['DataTreatment.status' => "APPROVE"],['DataTreatment.status' => "CONFIRM"]];
        // $_where[] = "DataTreatment.schedule_date > DATE_ADD(DATE(NOW()), INTERVAL -2 HOUR)";
       
        $certTreatment = $this->DataTreatment->find()->select($fields)->where($_where)->order(['DataTreatment.id' => 'DESC'])->all();
                        
        $arr_treatments = array();
        $treatment_invitations = array();
        if (!empty($certTreatment)) {
            foreach ($certTreatment as $row) {
                $str_address = $row['address'] . ', ' . $row['city'] . ', ' . $row['txt_state'] . ' ' . $row['zip'];
                if (!empty($row['suite'])) {
                    $str_address = $row['address'] . ', ' . $row['suite'] . ', ' . $row['city'] . ', ' . $row['txt_state'] . ' ' . $row['zip'];
                }
                //parche patient info null
                if ($row['patient_info'] == null){
                    $str_inj_info = [ 'q', '2'];
                } else {
                    $str_inj_info = explode('||',$row['patient_info']);
                }
                //--

                $cats_treatment = $this->CatTreatmentsCi->find()
                ->select(['name' => 'CTC.type'])
                ->join([
                    'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatTreatmentsCi.category_treatment_id'],    
                ])
                ->where(['CatTreatmentsCi.id IN ('.$row->treatments.')'])
                ->group(['CTC.type'])
                ->toArray();

                $cats_treatment_arr = [];
                foreach($cats_treatment as $cat){
                    if($cat['name'] == 'NEUROTOXINS BASIC' || $cat['name'] == 'NEUROTOXINS ADVANCED'){
                        if(!in_array('NEUROTOXINS', $cats_treatment_arr)){
                            $cats_treatment_arr[] = 'NEUROTOXINS';
                        }
                    }else{
                        $cats_treatment_arr[] = $cat['name'];
                    }
                }

                $agreements   = $this->get_agreements_patient($row['patient_id']);
                $certificates = $this->getCertificatesUser($row['patient_id']);

                $separate_treatments = $Treatments->separate_treatments($row->treatments_string,$row->treatments_string_id,USER_ID);

                $full_treatments = "";

                if(!empty($separate_treatments["neurotoxins"])){
                    $full_treatments .= $this->check_training_medical(USER_ID) ? str_ireplace('basic ', '', $separate_treatments["neurotoxins"]) : $separate_treatments["neurotoxins"];
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
                
                $temp_arr = array(
                    'treatment_uid' => $row['uid'],
                    'notes' => $row['notes'],
                    'schedule_date' => $row['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm'),
                    'patient' => trim($row['patient']) == "" ? $row['patient_email'] : $row['patient'],
                    'patient_phone' => $row['patient_phone'],
                    'patient_uid' => $str_inj_info[0],
                    'patient_shortuid' => $str_inj_info[1],
                    'status' => $row['status'],
                    'address' => $str_address,
                    'treatments' => $full_treatments,
                    'treatment_requirements' => $this->treatment_requirements($cats_treatment_arr,$agreements,$certificates,43,$row['patient_id']),
                );
                if( $row['status'] == 'INVITATION'){
                    $treatment_invitations[] = $temp_arr;
                }else{
                    $arr_treatments[] = $temp_arr;
                }
            }
            $this->set('treatment_invitations', $treatment_invitations);
        }

        $this->loadModel('SpaLiveV1.DataSubscriptionMethodPayments');

        $entMethod = $this->DataSubscriptionMethodPayments->find()->where(['DataSubscriptionMethodPayments.user_id' => USER_ID, 'DataSubscriptionMethodPayments.deleted' => 0])->first();

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

            if(!empty($entMethod) && count($payment_methods) <= 0 && $ent_user->spa_work == 0){
                $this->loadModel('SpaLiveV1.DataSubscriptions');
                $ent_subscription = $this->DataSubscriptions->find()->where([
                    'DataSubscriptions.subscription_type IN' => array('SUBSCRIPTIONMSL','SUBSCRIPTIONMD'),
                    'DataSubscriptions.user_id' => USER_ID,
                    'DataSubscriptions.status' => 'ACTIVE',
                    'DataSubscriptions.deleted' => 0
                ])->all();
                /*if(count($ent_subscription) > 0){
                    foreach($ent_subscription as $subscription){
                        $this->DataSubscriptions->updateAll(
                            ['status' => 'HOLD'], 
                            ['id' => $subscription->id]
                        );
                    }
                    $Main = new MainController();
                    $Main->sendEmalSubscriptionHold(USER_EMAIL);
                }*/
                $this->set('add_payment_method', true);
                $this->set('add_payment_method2', array('add_payment' => true, 'message' => 'Your payment method was deleted from stripe, please add another one.'));
            }else{
                $this->set('add_payment_method', false);
                $this->set('add_payment_method2', array('add_payment' => false, 'message' => ''));
            }
        }

        $this->loadModel('SpaLiveV1.DataSubscriptions');

        $ent_subscriptions = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => USER_ID,'DataSubscriptions.deleted' => 0,'DataSubscriptions.status IN' => array('HOLD')])->all();

        if(count($ent_subscriptions) > 0 && $ent_user->spa_work == 0){
            //$this->set('add_payment_method', true);
            //$this->set('add_payment_method2', array('add_payment' => true, 'message' => 'Your subscription payment failed, add a new payment method.'));
        }else{
            $this->set('add_payment_method', false);
            $this->set('add_payment_method2', array('add_payment' => false, 'message' => ''));
        }

        // IV FORM AGREEMENT
        $implement_date_form = date('Y-m-d H:i:s', strtotime('2025-10-30 00:00:00'));
        $purchase = $this->DataPurchases->find()->select(['DataPurchases.id'])
        ->join([
            'DPD' => ['table' => 'data_purchases_detail', 'type' => 'INNER', 'conditions' => 'DPD.purchase_id = DataPurchases.id'],
            'CP' => ['table' => 'cat_products', 'type' => 'INNER', 'conditions' => 'CP.id = DPD.product_id'],
        ])
        ->where(['DataPurchases.user_id' => USER_ID, 'DataPurchases.deleted' => 0, 'DataPurchases.payment <>' => '', 'CP.deleted' => 0, 'CP.category' => 'IV VIALS', 'DataPurchases.created >=' => $implement_date_form])->first();

        if(empty($purchase)){
            $this->set('iv_form_pending', false);
        }else{
            $therapy = new TherapyController();
            $form = $therapy->get_iv_form_info(USER_ID);

            if(!$form){
                $this->set('iv_form_pending', true);
            }else{
                $this->set('iv_form_pending', false);
            }
        }

        $this->success();
    }

    public function treatmente_provider() {

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

        if(empty($user_uid)){
            $this->message('Invalid user.');
            return;
        }
        
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.CatTreatmentsCi');
        $this->loadModel('SpaLiveV1.CatTreatmentsCategory');

        $ent_user = $this->SysUsers->find()->where(['SysUsers.uid' => $user_uid])->first();

        if(empty($ent_user)){
            $this->message('Invalid user.');
            return;
        }

        $array_treatments = array();

        $cat_categorys = $this->CatTreatmentsCategory->find()->where(['CatTreatmentsCategory.deleted' => 0])->all();

        if(Count($cat_categorys) > 0){
            foreach($cat_categorys as $row){
                $array_list = array();
                $cat_treatment = $this->CatTreatmentsCi->find()
                ->join([
                    'Treatments' => ['table' => 'data_treatments_prices', 'type' => 'INNER', 'conditions' => 'Treatments.treatment_id = CatTreatmentsCi.id AND Treatments.deleted = 0']
                ])
                ->where(['CatTreatmentsCi.category_treatment_id' => $row->id, 'Treatments.user_id' => $ent_user->id, 'CatTreatmentsCi.deleted' => 0])
                ->order(['CatTreatmentsCi.id' => 'DESC'])->all();

                if(Count($cat_treatment) > 0) {
                    if(Count($cat_treatment) > 1){
                        $no_preference = $this->CatTreatmentsCi->find()
                            ->where(['CatTreatmentsCi.category_treatment_id' => $row->id, 'CatTreatmentsCi.deleted' => 0, 'CatTreatmentsCi.name' => 'Let my provider help me decide'])
                            ->order(['CatTreatmentsCi.id' => 'DESC'])->first();
                        if(!empty($no_preference)){
                            array_push($array_list, array(
                                'id' => $no_preference['id'],
                                'name' => $no_preference['name'],
                                'description' => $no_preference['description'],
                            ));
                        }
                    }
                    foreach($cat_treatment as $row2){
                        array_push($array_list, array(
                            'id' => $row2['id'],
                            'name' => $row2['name'],
                            'description' => $row2['description'],
                        ));
                    }
                }
                $array_treatments[] = array(
                    'title' => $row['name'],
                    'description' => $row['description'],
                    'image' => $row['image'],
                    'data' => $array_list,
                    'expand' => $row['name'] == 'Basic Neurotoxins' ? true : false,
                );
            }
        }

        $this->set('treatments', $array_treatments);
        $this->success();
    }

    private function requestRegisterAgreement() {

        $this->loadModel('SpaLiveV1.Agreement');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.CatAgreements');

        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();

        $userType = $ent_user->type == 'gfe+ci' ? 'examiner' : $ent_user->type;
    
        $ent_agreement = $this->CatAgreements->find()->select(['CatAgreements.uid','DataAgreement.id','DataAgreement.deleted'])->join([
                'DataAgreement' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'CatAgreements.uid = DataAgreement.agreement_uid AND DataAgreement.user_id = ' . USER_ID . ' AND DataAgreement.deleted = 0'],
            ])->where(
            [
                'CatAgreements.state_id' => $ent_user->state,
                'CatAgreements.user_type' => $userType,
                'CatAgreements.agreement_type' => 'REGISTRATION',
                'CatAgreements.deleted' => 0,
            ]
        )->first();
        if (!empty($ent_agreement) && empty($ent_agreement['DataAgreement']['id'])) {

            return array(
                'uid' => $ent_agreement->uid,
                'message' => 'You have been assigned a new medical director, Dr. Zach Cannon. Please sign the agreement to proceed.',
                'title' => $ent_agreement->agreement_title,
            );
        }

        return "";

    }

    private function checkStripeACcount() { //should request stripe registration?? true means YES, false menas NO (register already completed)
        $this->loadModel('SpaLiveV1.SysUsers');
        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();

        if (empty($ent_user->stripe_account)) return true;
        if ($ent_user->stripe_account_confirm == 1) return false;

        if ($ent_user->stripe_account_confirm == 0) {
        
            try {
                $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));    
               
                $stripe_capability = $stripe->accounts->retrieveCapability(
                    $ent_user->stripe_account,
                    'transfers',
                    []
                );

                if (!empty($stripe_capability)) {

                    if ($stripe_capability['status'] == 'active') {

                         $array_save = array(
                            'id' => USER_ID,
                            'stripe_account_confirm' => 1,
                        );

                        $c_entity = $this->SysUsers->newEntity($array_save);
                        if(!$c_entity->hasErrors()) {
                            if ($this->SysUsers->save($c_entity)) {
                                return false;
                            }
                        }
                    } else {
                        return true;
                    }
                } else {
                    return true;
                }


            } catch(Stripe_CardError $e) {
              $error = $e->getMessage();
            } catch (Stripe_InvalidRequestError $e) {
              // Invalid parameters were supplied to Stripe's API
              $error = $e->getMessage();
            } catch (Stripe_AuthenticationError $e) {
              // Authentication with Stripe's API failed
              $error = $e->getMessage();
            } catch (Stripe_ApiConnectionError $e) {
              // Network communication with Stripe failed
              $error = $e->getMessage();
            } catch (Stripe_Error $e) {
              // Display a very generic error to the user, and maybe send
              // yourself an email
              $error = $e->getMessage();
            } catch (Exception $e) {
              // Something else happened, completely unrelated to Stripe
              $error = $e->getMessage();
            } catch(\Stripe\Exception\CardException $e) {
                $error = $e->getMessage();
             // Since it's a decline, \Stripe\Exception\CardException will be caught
            } catch (\Stripe\Exception\RateLimitException $e) {
                $error = $e->getMessage();
              // Too many requests made to the API too quickly
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                $error = $e->getMessage();
              // Invalid parameters were supplied to Stripe's API
            } catch (\Stripe\Exception\AuthenticationException $e) {
                $error = $e->getMessage();
              // Authentication with Stripe's API failed
              // (maybe you changed API keys recently)
            } catch (\Stripe\Exception\ApiErrorException $e) {
                $error = $e->getMessage();
              // Display a very generic error to the user, and maybe send
              // yourself an email
            } 

            if ($error) return true;
            
        } else {
            return false;
        }
    }

    public function get_labels_register(){
        $this->loadModel('SpaLiveV1.CatLabels');

        $model = get('model', 'REGISTER');
        

        $userType = get('usertype', '');
        $where = ['CatLabels.deleted' => 0, 'CatLabels.tipo' => $model];

        if(!empty($userType)){
            $where['CatLabels.key_field'] = $userType == 'patient' ? 'register_patient' : ( $userType == 'examiner' ? 'register_gfe' : ( $userType == 'clinic' ? 'register_clinic' : ($userType == 'injector' ? 'register_ci' : '') ) );
        }
        
        $findLabels = $this->CatLabels->find()->select(['CatLabels.key_field', 'CatLabels.value'])->where($where)->toArray();

        if(!empty($findLabels)){
            $labels = [];
            foreach($findLabels as $item){
                $any = ($this->register_total/100) - ($this->register_refund/100);
                $labels[$item->key_field] = str_replace('GFE_COST', $this->total/100 , $item->value);
                $labels[$item->key_field] = str_replace('GFE_DOUBLE', ($this->total/100)*2, $labels[$item->key_field]);
                $labels[$item->key_field] = str_replace('GFE_PAYMENT', $this->paymente_gfe/100 , $labels[$item->key_field]);
                $labels[$item->key_field] = str_replace('CI_REGISTRATION', $this->register_total/100 , $labels[$item->key_field]);
                $labels[$item->key_field] = str_replace('CI_ADVANCED', $this->training_advanced/100 , $labels[$item->key_field]);
                $labels[$item->key_field] = str_replace('CI_REFUND', $this->register_refund/100 , $labels[$item->key_field]);
                $labels[$item->key_field] = str_replace('CI_REST ', $any , $labels[$item->key_field]);
                $this->set($item->key_field, $labels[$item->key_field]);
            }

            $this->set('label_os', 'Have you received an aesthetic certificate from a school? *This is not required. More information about this will be asked later in the registration process.');
            $this->set('video_url', Configure::read('App.wordpress_domain') . 'myspa.mp4');
            $this->set('video_url_patient', Configure::read('App.wordpress_domain') . 'patient.mp4');   
            $this->success();
        }
    }

    private function checkTrainings() {

        $this->loadModel('SpaLiveV1.SysUsers');
        $ent_user = $this->SysUsers->find()->join([
            'Training' => ['table' => 'data_trainings', 'type' => 'INNER', 'conditions' => 'Training.user_id = SysUsers.id'],
            'CatTraining' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTraining.id = Training.training_id'],
        ])
        ->where(['SysUsers.id' => USER_ID, 'Training.deleted' => 0, 'CatTraining.deleted' => 0])->first();

        if (!empty($ent_user)) return true;
        return false;
    }

    private function requestInjectorSubscriptionAgreement() {

        $this->loadModel('SpaLiveV1.Agreement');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.CatAgreements');


        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();
        
        $usertype = $ent_user->type == 'gfe+ci' ? 'injector' : $ent_user->type;
        $ent_agreement = $this->CatAgreements->find()->select(['CatAgreements.uid','DataAgreement.id','DataAgreement.deleted'])->join([
                'DataAgreement' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'CatAgreements.uid = DataAgreement.agreement_uid AND DataAgreement.user_id = ' . USER_ID . ' AND DataAgreement.deleted = 0'],
            ])->where(
            [
                'CatAgreements.state_id' => 0,
                'CatAgreements.user_type' => $usertype,
                'CatAgreements.agreement_type' => 'TERMSANDCONDITIONS',
                'CatAgreements.deleted' => 0,
            ]
        )->first();
        if (!empty($ent_agreement) && empty($ent_agreement['DataAgreement']['id'])) {
             return array(
                'uid' => $ent_agreement->uid,
                'message' => '',
                );
        }

        return "";

    }

    private function requestSubscriptionMDSummary() {

        $this->loadModel('SpaLiveV1.Agreement');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.CatAgreements');
        $this->loadModel('SpaLiveV1.DataSubscriptions');


        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();

        $userType = $ent_user->type == 'gfe+ci' ? 'injector' : $ent_user->type;

        $ent_agreement = $this->CatAgreements->find()->select(['CatAgreements.uid','DS.id','DS.deleted', 'DataAgreement.id', 'DataAgreement.deleted'])->join([
                'DS' => ['table' => 'data_subscriptions', 'type' => 'INNER', 'conditions' => 'DS.subscription_type = CatAgreements.agreement_type AND DS.user_id = ' . USER_ID . ' AND DS.deleted = 0'],
                'DataAgreement' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'CatAgreements.uid = DataAgreement.agreement_uid AND DataAgreement.user_id = ' . USER_ID . ' AND DataAgreement.deleted = 0'],
            ])->where(
            [
                'CatAgreements.state_id' => $ent_user->state,
                'CatAgreements.user_type' => $userType,
                'CatAgreements.agreement_type' => 'SUBSCRIPTIONMD',
                'CatAgreements.deleted' => 0,
            ]
        )->first();

        if (!empty($ent_agreement) && empty($ent_agreement['DataAgreement']['id'])) {

            return array(
                'uid' => $ent_agreement->uid,
                'message' => 'You have been assigned a new medical director, Dr. Zach Cannon. Please sign the Medical Director Subscription to proceed.',
                );
        }

        return "";

    }

    private function __child_invitees($level, $parent_id, $forSummary = false){
        $add_level = true;
        $network = $this->DataNetwork->find()->select(['SysUsers.id','DataNetwork.user_id','SysUsers.uid', 'SysUsers.name', 'SysUsers.mname', 'SysUsers.lname','SysUsers.active','SysUsers.email','SysUsers.short_uid'])
        ->join(['SysUsers' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'SysUsers.id = DataNetwork.user_id']])
        ->where([ 'DataNetwork.parent_id' => $parent_id, 'SysUsers.deleted' => 0, 'SysUsers.login_status' => 'READY'])->order(['SysUsers.active' => 'DESC'])->toArray();

        $result = [];
        if($level > 3){
            return $result;
        }

        foreach($network as $item){
            $need_add = false;
            if(intval($item->SysUsers['active']) == 1){
                $need_add = true;
                if($forSummary == true){
                    $result[] = [
                        'name' => $item->SysUsers['name'] . ' ' . $item->SysUsers['lname'],
                        'short_uid' => $item->SysUsers['short_uid'],
                        'user_id' => $item->SysUsers['id'],
                        'active' => $item->SysUsers['active'],
                        'email' => $item->SysUsers['email'],
                        'level' => $level,
                    ];
                }else{
                    $result[] = [
                        'id' => $item->SysUsers['id'],
                        'uid' => $item->SysUsers['uid'],
                        'name' => $item->SysUsers['name'],
                        'mname' => '',
                        'lname' => $item->SysUsers['lname'],
                        'active' => $item->SysUsers['active'],
                        'level' => $level,
                        'isChecked' => false
                    ];
                }
            }
            $result = array_merge($result, $this->__child_invitees($level + ($need_add == true ? 1 : 0) , $item->user_id, $forSummary));
        }
        return $result;
    }

    public function find_patients($return_list = false, $skip_not_registered = false) {
        $this->loadModel('SpaLiveV1.DataPatientClinic');

        $token = get('token', '');
        
        if(!$return_list){
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

            if (USER_TYPE == "patient") {
                $this->message('Invalid user type.');    
                return;
            }
        }

        $_where = ['SysUsers.deleted' => 0, 'DataPatientClinic.injector_id' => USER_ID,'DataPatientClinic.deleted' => 0,'DataPatientClinic.type' => 'neurotoxin'];
        
        $short_uid = get('short_uid','');
        if (!empty($short_uid)) {
            // $_where['SysUsers.short_uid LIKE'] = $short_uid;
            $_where['OR'] = [['SysUsers.short_uid LIKE' => $short_uid], ['SysUsers.name LIKE' => "%$short_uid%"], ['SysUsers.lname LIKE' => "%$short_uid%"]];
        }
    
        if ($skip_not_registered) {
            $_where[] = "SysUsers.steps <> 'REGISTER'";
        }
        
        $ent_users = $this->DataPatientClinic->find()->select(['SysUsers.uid','SysUsers.short_uid','SysUsers.name','SysUsers.lname','SysUsers.id','SysUsers.steps','SysUsers.email'])
        ->join(['SysUsers' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'DataPatientClinic.user_id = SysUsers.id']])
        ->where($_where)->group(['SysUsers.id'])->all();
        
        $result = array();

        if (!empty($ent_users)) {

            
            foreach ($ent_users as $row) {

                //if(USER_ID != $row["SysUsers"]['id']){

                    $t_array = array(
                        'register_pending' => $row->SysUsers['steps'] == 'REGISTER' ? true : false,
                        'uid' => $row->SysUsers['uid'],
                        'short_uid' => $row->SysUsers['short_uid'], 
                        'name' => $row->SysUsers['steps'] == 'REGISTER' ? $row->SysUsers['email'] : $row->SysUsers['name'] . ' ' . $row->SysUsers['lname'],
                    );

                    $result[] = $t_array;
                //}
            }

        }

        if($return_list){
            return $result;
        }else{
            $this->set('data', $result);
            $this->set('injector_uid', USER_UID);
            $this->success();
        }
    }

    private function requestSubscriptions() {

        $this->loadModel('SpaLiveV1.SysUsers');

        $allow_treatments = true;
        $show_subscriptions = false;
        $have_trainings = false;

        $this->loadModel('SpaLiveV1.CatLabels');

        $this->loadModel('SpaLiveV1.DataTrainings');

        $now = date('Y-m-d H:i:s');

        $ent_data_training = $this->DataTrainings->find()->join([
            'CatTrainigs' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainings.training_id'],
        ])->where(
            [
                'CatTrainigs.level' => 'LEVEL 1',
                'DataTrainings.user_id' => USER_ID,
                'DataTrainings.deleted' => 0, 
                '(DATE_FORMAT(CatTrainigs.scheduled, "%Y-%m-%d 12:00:00") < "' . $now . '")',
                'CatTrainigs.deleted' => 0
            ]
        )->first();
        if (!empty($ent_data_training)){
            $have_trainings = true;
        }

        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();

        $ent_agreement = $this->CatAgreements->find()->select(['CatAgreements.uid','DataAgreement.id','DataAgreement.deleted', 'DataSubscriptions.status'])->join([
                'DataAgreement' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'CatAgreements.uid = DataAgreement.agreement_uid AND DataAgreement.user_id = ' . USER_ID. ' AND DataAgreement.deleted = 0'],
                'DataSubscriptions' => ['table' => 'data_subscriptions', 'type' => 'LEFT', 'conditions' => 'DataSubscriptions.user_id='. USER_ID.' AND DataSubscriptions.deleted =  0 AND DataSubscriptions.subscription_type = "SUBSCRIPTIONMSL" AND DataSubscriptions.status IN ("ACTIVE", "HOLD") '],
            ])->where(
            [
                'CatAgreements.state_id' => $ent_user->state,
                'CatAgreements.user_type' => $ent_user->type == 'gfe+ci' ? 'injector' : $ent_user->type,
                'CatAgreements.agreement_type' => 'SUBSCRIPTIONMSL',
                'CatAgreements.deleted' => 0
            ]
        )->first();

        if (!empty($ent_agreement) && empty($ent_agreement['DataAgreement']['id'])) {
            $str_text = '';
            $ent_labels = $this->CatLabels->find()->where(['CatLabels.key_field' => 'ci_subscribe_msl_copy','CatLabels.deleted' => 0])->first();
            if (!empty($ent_labels)) {
                $str_text = $ent_labels->value;
                // if ($have_trainings) $str_text .= ' Use our promo code 50OFFSPA';
            }
            if($have_trainings){
                $this->set('request_subscription_msl', array('display' => true, 'price' => $this->total_subscriptionmsl, 'uid' => $ent_agreement['uid'],'type' => 'SUBSCRIPTIONMSL', 'label' => $str_text, 'status' => '', 'hide' => false));
            }else{
                $this->set('request_subscription_msl', array('display' => true, 'price' => $this->total_subscriptionmsl, 'uid' => $ent_agreement['uid'],'type' => 'SUBSCRIPTIONMSL', 'label' => $str_text, 'status' => '' , 'hide' => true));
            }
            $allow_treatments = false;
            $show_subscriptions = true;
            
        } else {
            $str_status_msl = !empty($ent_agreement['DataSubscriptions']['status']) ? $ent_agreement['DataSubscriptions']['status']  : "" ;
            if($str_status_msl == 'HOLD'){
                $allow_treatments = false;
            }
            $this->set('request_subscription_msl', array('display' => false, 'price' => $this->total_subscriptionmsl, 'uid' => '','type' => 'SUBSCRIPTIONMSL', 'status' => $str_status_msl, 'hide' => false));
        }

        $ent_agreement = $this->CatAgreements->find()->select(['CatAgreements.uid','DataAgreement.id','DataAgreement.deleted', 'DataSubscriptions.status'])->join([
                'DataAgreement' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'CatAgreements.uid = DataAgreement.agreement_uid AND DataAgreement.user_id = ' . USER_ID. ' AND DataAgreement.deleted = 0'],
                'DataSubscriptions' => ['table' => 'data_subscriptions', 'type' => 'LEFT', 'conditions' => 'DataSubscriptions.user_id='. USER_ID.' AND DataSubscriptions.deleted =  0 AND DataSubscriptions.subscription_type = "SUBSCRIPTIONMD" '],
                ])->where(
            [
                'CatAgreements.state_id' => $ent_user->state,
                'CatAgreements.user_type' => $ent_user->type == 'gfe+ci' ? 'injector' : $ent_user->type,
                'CatAgreements.agreement_type' => 'SUBSCRIPTIONMD',
                'CatAgreements.deleted' => 0
            ]
        )->first();
        
        if (!empty($ent_agreement) && empty($ent_agreement['DataAgreement']['id'])) {
            $str_text = '';
            $ent_labels = $this->CatLabels->find()->where(['CatLabels.key_field' => 'ci_subscribe_md_copy','CatLabels.deleted' => 0])->first();
            if (!empty($ent_labels)) {
                $str_text = $ent_labels->value;
            }
            if($have_trainings){
                $this->set('request_subscription_md', array('display' => true, 'price' => $this->total_subscriptionmd, 'uid' => $ent_agreement['uid'],'type' => 'SUBSCRIPTIONMD', 'label' => $str_text, 'status' => '', 'hide' => false));
            }
            else{
                $this->set('request_subscription_md', array('display' => true, 'price' => $this->total_subscriptionmd, 'uid' => $ent_agreement['uid'],'type' => 'SUBSCRIPTIONMD', 'label' => $str_text, 'status' => '', 'hide' => true));
            }
            $allow_treatments = false;
            $show_subscriptions = true;

        } else {
            $str_status_md = !empty($ent_agreement['DataSubscriptions']['status']) ? $ent_agreement['DataSubscriptions']['status']  : "" ;
            if($str_status_md == 'HOLD'){
                $allow_treatments = false;
            }
            $this->set('request_subscription_md', array('display' => false, 'price' => $this->total_subscriptionmd, 'uid' => '','type' => 'SUBSCRIPTIONMD', 'status' => $str_status_md, 'hide' => false));
        }

        if (!$this->checkTrainings()) {
            $show_subscriptions = false;
            $allow_treatments = false;
        }

        $this->set('allow_treatments', $allow_treatments);
        $this->set('show_subscriptions', $show_subscriptions);
    }

    public function list_favorites() {

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

        $this->loadModel('SpaLiveV1.DataFavorites');
        $this->loadModel('SpaLiveV1.DataTreatment');

        $fields = ['User.uid','User.name', 'User.lname', 'User.mname','User.city','User.short_uid','User.score','User.photo_id','User.description','State.abv'];
        $fields['likes'] = "(SELECT Count(DTR.id) FROM data_treatment_reviews DTR WHERE DTR.injector_id = User.id AND DTR.like = 'LIKE')";
        $ent_fav = $this->DataFavorites->find()->select($fields)
        ->join([
            'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataFavorites.injector_id'],
            'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = User.state'],
        ])
        ->where(['DataFavorites.deleted' => 0, 'DataFavorites.patient_id' => USER_ID, 'User.show_in_map' => 1])->order(['DataFavorites.created' => 'DESC'])->all();

        $arr_favorites = array();
        
        if(!empty($ent_fav)){
           foreach ($ent_fav as $row) {
                $first_letter_lname = !empty($row['User']['lname']) ? strtoupper(substr($row['User']['lname'], 0, 1)) : '';
                $arr_favorites[] = array(
                    'uid' => $row['User']['uid'],
                    'short_uid' => $row['User']['short_uid'],
                    'name' => $row['User']['name'] . ' ' . $first_letter_lname,
                    'photo_id' => intval($row['User']['photo_id']),
                    'description' => !empty($row['User']['description']) ? $row['User']['description'] : '',
                    'score' => intval($row['User']['score']),
                    'city' => $row['User']['city'],
                    'state' => $row['State']['abv'],
                    'availability' => $this->schedule_availability($row['User']['uid']),
                    'treatmets_provided' => array(
                        'Neurotoxins (Basic)',
                        'Neurotoxins (Advanced)',
                    ),
                    'likes' => $row['likes'],
                );
            }
        }

        if(Count($arr_favorites) == 0){
            $fields = ['SU.id','SU.uid','SU.name','SU.lname','SU.mname','SU.city','SU.short_uid','SU.score','SU.photo_id','SU.description','State.abv'];
            $fields['likes'] = "(SELECT Count(DTR.id) FROM data_treatment_reviews DTR WHERE DTR.injector_id = SU.id AND DTR.like = 'LIKE')";
            $ent_treatments = $this->DataTreatment->find()
            ->select($fields)
            ->join([
                'SU' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'SU.id = DataTreatment.assistance_id'],
                'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = SU.state'],
            ])
            ->where(['DataTreatment.patient_id' => USER_ID,'DataTreatment.status' => 'DONE', 'DataTreatment.payment <>' => '', 'DataTreatment.deleted' => 0, 'SU.login_status' => 'READY',])->all();

            if(Count($ent_treatments) == 0){
                $this->message('No previous providers.');
                $this->success();
                return;
            }
            
            foreach($ent_treatments as $row) {
                $value = in_array($row['SU']['uid'], array_column($arr_favorites, 'uid'));
                if($value) continue;
                $first_letter_lname = !empty($row['SU']['lname']) ? strtoupper(substr($row['SU']['lname'], 0, 1)) : '';
                $arr_favorites[] = array(
                    'uid' => $row['SU']['uid'],
                    'short_uid' => $row['SU']['short_uid'],
                    'name' => $row['SU']['name'] . ' ' . $first_letter_lname,
                    'photo_id' => intval($row['SU']['photo_id']),
                    'description' => !empty($row['SU']['description']) ? $row['SU']['description'] : '',
                    'score' => intval($row['SU']['score']),
                    'city' => $row['SU']['city'],
                    'state' => $row['State']['abv'],
                    'availability' => $this->schedule_availability($row['SU']['uid']),
                    'treatmets_provided' => array(
                        'Neurotoxins (Basic)',
                        'Neurotoxins (Advanced)',
                    ),
                    'likes' => $row['likes'],
                );
            }
        }

        $this->set('favorites', $arr_favorites);

        $this->success();
    }

    public function recommended_cp(){
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

        $this->loadModel('SpaLiveV1.DataTreatmentReview');

        $str_query_find = "
            SELECT DTR.injector_id, COUNT(DTR.injector_id) likes, SU.`name`, SU.lname , SU.uid, SU.short_uid, SU.photo_id, SU.description, SU.city, CS.abv
            FROM data_treatment_reviews DTR
            JOIN sys_users SU ON SU.id = DTR.injector_id AND SU.login_status = 'READY' AND SU.active = 1 AND SU.name NOT LIKE '%Test%'
            JOIN cat_states CS ON CS.id = SU.state
            WHERE DTR.`like` = 'LIKE' AND DTR.deleted = 0
            GROUP BY DTR.injector_id 
            ORDER BY likes DESC
            LIMIT 10;
        ";
        $ent_providers = $this->DataTreatmentReview->getConnection()->execute($str_query_find)->fetchAll('assoc');
        $arr_recommends = [];
        foreach($ent_providers as $row) {
            $first_letter_lname = !empty($row['lname']) ? strtoupper(substr($row['lname'], 0, 1)) : '';
            $arr_recommends[] = array(
                'uid' => $row['uid'],
                'short_uid' => $row['short_uid'],
                'name' => $row['name'] . ' ' . $first_letter_lname,
                'photo_id' => intval($row['photo_id']),
                'description' => !empty($row['description']) ? $row['description'] : '',
                'score' => intval(40),
                'city' => $row['city'],
                'state' => $row['abv'],
                'availability' => $this->schedule_availability($row['uid']),
                'treatmets_provided' => array(
                    'Neurotoxins (Basic)',
                    'Neurotoxins (Advanced)',
                ),
                'likes' => $row['likes'],
            );
        }

        $this->set('recommended', $arr_recommends);

        $this->success();
    }

    private function schedule_availability($injector_uid) {
        $this->loadModel('SpaLiveV1.DataScheduleDaysOff');
        $this->loadModel('SpaLiveV1.DataUserUnavailable');

        $date = date('Y-m-d');
        $show_date = date('Y-m-d');

        $this->loadModel('SpaLiveV1.SysUsers');

        $injector_id = $this->SysUsers->uid_to_id($injector_uid);
        if($injector_id <= 0){
            $this->message('Invalid Injector.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataScheduleModel');
        
        $day_available = true;

        while ($day_available) {
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
                    $date = date('Y-m-d', strtotime($date.'+ 1 days'));
                }

                //Search next available day
                
                if (!empty($days) && !empty($day)) {
    
                    if(strpos($days, $day) !== false){
                       
                    } else {
                        $date = date('Y-m-d', strtotime($date.'+ 1 days'));
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
                    if(Count($result) > 0){
                        $day_available = false;
                        $show_date = $first_day->format('m-d-Y');
                    }
                    else{
                        $date = date('Y-m-d', strtotime($date.'+ 1 days'));
                    }
                }
    
            } else {
                return 'Not available.';
            }
        }

        return $show_date;
    }

    public function find_treatment() {
        $this->loadModel('SpaLiveV1.DataTreatmentReview');
        $token = get('token', '');
        $show_availability = get('show_availability', 1);
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

        $Treatments = new TreatmentsController();

        // $userType = $user['user_role'];

        // if ($userType != "patient") {
        //     $this->message('Invalid user.');
        //     return;
        // }                
            
        $this->loadModel('SpaLiveV1.SysUsers');

        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();

        $latitude = $ent_user->latitude;
        $longitude = $ent_user->longitude;
        
        $zip = get('zip',0);
        if ($zip == 0) {
            $zip = $ent_user->zip;
        }

        if($latitude == 0 && $longitude == 0){
            $this->loadModel('SpaLiveV1.CatStates');
            $obj_state = $this->CatStates->find()->select(['CatStates.name'])->where(['CatStates.id' => $ent_user->state])->first();
            
            $chain =  $ent_user->address . ' ' . $ent_user->city . ' ' . $zip . ' ,' . $obj_state->name;
            $coordinates = $this->validate_coordinates($chain, $zip);
            $latitude   = $coordinates['latitude'];
            $longitude  = $coordinates['longitude'];
        }

        $str_sort = !empty(get('sort','distance_asc')) ? get('sort','distance_asc') : 'distance_asc'    ;
        $order = '';
        $order_by_review = false;
        if (!empty($str_sort)) {
            if ($str_sort == "distance_desc") {
                $order = 'ORDER BY distance_in_mi DESC';
            } else if ($str_sort == "distance_asc") {
                $order = 'ORDER BY distance_in_mi ASC';
            } else  if ($str_sort == "ci_of_month") {
                $order = 'ORDER BY is_ci_of_month DESC';
            } else  if ($str_sort == "most_reviewed") {
                $order_by_review = true;
            }else  if ($str_sort == "featured_injectors") {
                $order = 'ORDER BY featured_injector DESC';
            }
        }

        //******** TEMP
        // if($latitude == 0 || $longitude == 0) {
        //     $latitude = 29.387579;
        //     $longitude = -98.472777;
        // }        

        if ($latitude == 0 || $longitude == 0) {

            if (strlen(strval($zip)) < 5) {
                for($i = 0; $i < (5 - strlen(get('zip','0')));$i++) {
                    $zip = '0' . $zip;
                }
            }
            require_once(ROOT . DS . 'vendor' . DS  . 'zipcodes' . DS . 'init.php');
            
            $data = isset(\zipcodes\Zipcodes::DATA[$zip]) ? \zipcodes\Zipcodes::DATA[$zip] : null;
            if ($data) {
                $latitude = $data['lat'];
                $longitude = $data['lng']; 
            } else {

                for($i = 1; $i <= 100; $i++) {
                    $nzip = intval($zip)+$i;
                    $bnzip = 5 - strlen(strval($nzip));
                    $rzip = $nzip;
                    if (strlen(strval($nzip)) < 5) {
                        for($c = 0; $c < $bnzip ;$c++) {
                            $rzip = '0' . $rzip;
                        }
                    }
                    
                    $data = isset(\zipcodes\Zipcodes::DATA[$rzip]) ? \zipcodes\Zipcodes::DATA[$rzip] : null;
                    if ($data) {
                        $latitude = $data['lat'];
                        $longitude = $data['lng'];
                        break;
                    }

                }
            }

        }
        
        
        // DEFAULT - Have training and active = 1
        $filter = trim(get('filter', ''));
        $targetString = trim(get('filter', ''));
        $icon_filter = get('filter_icons','');
        $join = '';
        $conditions = '';
        $having = '';
        $str_treatments = get('treatments', '');
        $join = "INNER JOIN data_schedule_model DSM ON DSM.injector_id = DC.id AND DSM.deleted = 0 ";
        $conditions = " AND DSM.days <> '' AND (SELECT COUNT(TrP.id) FROM data_treatments_prices TrP WHERE TrP.user_id = DC.id AND TrP.deleted = 0) > 0 ";
        if($show_availability == 1){        
            $result = $this->search_by_full_name_or_email($filter, $latitude, $longitude, $join, $conditions);
            if(empty($result)){
                if(!empty($str_treatments)){
                    $join .= " INNER JOIN data_treatments_prices TrPr ON TrPr.user_id = DC.id AND TrPr.deleted = 0";
                    $arr_treatments = explode(",", $str_treatments);
                    foreach($arr_treatments as $key => $treatment) {
                        if ($treatment == 0) unset($arr_treatments[$key]);
                    }
                    $conditions .= " AND TrPr.treatment_id IN ({$str_treatments})";        
                    $having = ' HAVING count(distinct TrPr.treatment_id) = ' . count($arr_treatments);
                }

                if(!empty($filter)){
                    $matchValue = str_replace(' ', ' +', $filter);
                    $matchValue = str_replace('@', '', $filter);
                    if(!filter_var($targetString, FILTER_VALIDATE_EMAIL)){
                        $conditions .= " AND ( MATCH(DC.name,DC.mname,DC.lname) AGAINST ('+{$matchValue}' IN BOOLEAN MODE) )";
                    }else{
                        $conditions .= " AND (DC.email LIKE '%{$filter}%')";
                    }
                }

                $filterByMostReview = false;
                $tmp_cond = "";
                if(!empty($icon_filter)){
                    $this->loadModel('CatIconTrophy');
                    $arr_filter = explode('||', $icon_filter);
                    foreach($arr_filter as $item){
                        if($item == 'MOST_REVIEW'){
                            $filterByMostReview = true;
                        }else{
                            $icon = $this->CatIconTrophy->find()->select(['CatIconTrophy.id'])->where(['CatIconTrophy.uid' => $item])->first();
                            if(!empty($icon)){
                                $tmp_cond .= " AND DatIcon.icon_id = ".$icon->id; 
                            }
                        }   
                    }

                    if(!empty($tmp_cond)){
                        $join .= " INNER JOIN  data_user_icon DatIcon ON DatIcon.user_id = DC.id ";
                    }
                }

                // if ($user['user_role'] != 'patient') {
                //     $latitude = 0;
                // }
                
                $first_day = date('Y-m-01');
                $last_day = date('Y-m-t');
                if ($latitude == 0 || $longitude == 0) {
                    $str_query_find = "
                        SELECT 
                            *, 
                            DC.id as user_id, DC.city,
                            DC.show_most_review,
                            (SELECT abv FROM cat_states CS WHERE CS.id = DC.state) state,
                            (SELECT COUNT(Training.id) 
                                FROM data_trainings Training
                                INNER JOIN cat_trainings CatTraining ON CatTraining.id = Training.training_id 
                                WHERE Training.user_id = DC.id AND Training.deleted = 0 AND CatTraining.deleted = 0 GROUP BY DC.id) trainings,
                            9999 distance_in_mi,
                            (SELECT Count(DTRT.id) FROM data_treatment_reviews DTRT WHERE DTRT.injector_id = DC.id AND DTRT.like = 'LIKE') likes    
                        FROM sys_users DC
                        {$join}
                        WHERE (DC.type = 'injector' OR DC.type = 'gfe+ci') AND DC.deleted = 0 AND DC.steps = 'HOME' AND DC.is_test = 0 {$conditions}
                        {$tmp_cond}
                        GROUP BY DC.id
                        {$having}
                        {$order}
                        ";
                } else {
                    $str_query_find = "
                        SELECT 
                            *, 
                            DC.id as user_id, DC.city,
                            DC.show_most_review,
                            (SELECT abv FROM cat_states CS WHERE CS.id = DC.state) state,
                            (SELECT COUNT(Training.id) 
                                FROM data_trainings Training
                                INNER JOIN cat_trainings CatTraining ON CatTraining.id = Training.training_id 
                                WHERE Training.user_id = DC.id AND Training.deleted = 0 AND CatTraining.deleted = 0 GROUP BY DC.id) trainings,
                            69.09 * DEGREES(ACOS(LEAST(1.0, COS(RADIANS({$latitude}))
                                * COS(RADIANS(DC.latitude))
                                * COS(RADIANS({$longitude} - DC.longitude))
                                + SIN(RADIANS({$latitude}))
                                * SIN(RADIANS(DC.latitude))))) AS distance_in_mi,
                            (SELECT Count(DTRT.id) FROM data_treatment_reviews DTRT WHERE DTRT.injector_id = DC.id AND DTRT.like = 'LIKE') likes
                        FROM sys_users DC
                        {$join}
                        WHERE (DC.type = 'injector' OR DC.type = 'gfe+ci') AND DC.deleted = 0 AND DC.steps = 'HOME' AND DC.is_test = 0 {$conditions}
                        {$tmp_cond}
                        GROUP BY DC.id
                        {$having}
                        {$order}
                        ";
                }
                
                $arr_find = $this->SysUsers->getConnection()->execute($str_query_find)->fetchAll('assoc');
                
                $result = array();
                $result2 = array();

                $arr_review_reach = [];
                $arr_review_unreach = [];
                if (!empty($arr_find)) {
                    $most_reviewed = $this->DataTreatmentReview->injectorMostReviewed();                                

                    if(!filter_var($targetString, FILTER_VALIDATE_EMAIL)){
                        usort($arr_find, function($a, $b) use ($targetString) {
                            $full_name_a = $a['mname'] != '' ? $a['name'] . $a['mname'] . $a['lname'] : $a['name'] . $a['lname'];
                            $full_name_b = $b['mname'] != '' ? $b['name'] . $b['mname'] . $b['lname'] : $b['name'] . $b['lname'];
                            $similarityA = similar_text($full_name_a, $targetString);
                            $similarityB = similar_text($full_name_b, $targetString);
                            return $similarityB <=> $similarityA;
                        });
                    }

                    foreach($arr_find as $row) {
                        if ($row['trainings'] > 0 && $row['active'] == 1) {
                            $cc = new CourseController();                        
                            $trainings_user = $cc->get_courses_user($row['user_id']);
                            $first_letter_lname = !empty($row['lname']) ? strtoupper(substr($row['lname'], 0, 1)) : '';
                            $adding = array(
                                'uid' => $row['uid'],
                                'name' => $row['name'] . ' ' . $first_letter_lname,
                                'city' => $row['city'],
                                'short_uid' => $row['short_uid'],
                                'photo_id' => intval($row['photo_id']),
                                'description' => !empty($row['description']) ? $row['description'] : '',
                                'score' => 0,//$score,
                                'state' => $row['state'],
                                'availability' => $show_availability==1 ?$this->schedule_availability($row['uid']) : '',
                                'treatmets_provided' => 
                                $trainings_user['has_advanced_course'] ? 
                                    array(
                                        'Neurotoxins (Basic)',
                                        'Neurotoxins (Advanced)',
                                    ) : 
                                    array(
                                        'Neurotoxins (Basic)',                            
                                    ),
                                'likes' => $row['likes'],
                            );
                            $result[] = $adding;
                        }
                    }

                }
            }
        }else{
            $result = array();
        }

        //PAST Treatments

        $this->loadModel('SpaLiveV1.DataTreatment');

        $fields = ['DataTreatment.id','DataTreatment.promo_code','DataTreatment.uid','DataTreatment.assistance_id','DataTreatment.schedule_date','DataTreatment.status','DataTreatment.amount','DataTreatment.tip','DataTreatment.address','DataTreatment.city','State.name','DataTreatment.zip', 'DataTreatment.clinic_patient_id','Review.score','Review.comments','Review.like','Review.id','Note.notes','DataTreatment.suite','Payment.subtotal','Payment.total'];
        $fields['provider_uid'] = '(SELECT uid FROM sys_users U WHERE U.id = DataTreatment.assistance_id)';
        $fields['injector'] = "(SELECT CONCAT(' ', U.name, ' ', SUBSTRING(U.lname, 1, 1)) FROM sys_users U WHERE U.id = DataTreatment.assistance_id)";
        $fields['patient'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.patient_id)";
        $fields['clinic_patient'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.clinic_patient_id)";
        $fields['treatments_text'] = "(SELECT GROUP_CONCAT(CONCAT(CTC.name,' (',CT.name, ')') SEPARATOR ', ') 
                                        FROM cat_treatments_ci CT 
                                        JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
                                        WHERE CT.deleted = 0 AND FIND_IN_SET(CT.id,DataTreatment.treatments) LIMIT 1)";

        $fields['treatments_string_id'] = "(SELECT GROUP_CONCAT(CT.id) 
                                            FROM cat_treatments_ci CT 
                                            JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
                                            WHERE CT.deleted = 0 AND FIND_IN_SET(CT.id,DataTreatment.treatments))";

        $_where = ['DataTreatment.deleted' => 0];
        $_where['DataTreatment.status'] = "DONE";
        $_where['DataTreatment.payment <>'] = "";
        $_where['DataTreatment.patient_id'] = USER_ID;
        // $_where['Review.deleted'] = 0;
        
        $certTreatment = $this->DataTreatment->find()->select($fields)
        ->join( [
            'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
            'Review' => ['table' => 'data_treatment_reviews', 'type' => 'LEFT', 'conditions' => 'Review.treatment_id = DataTreatment.id'],
            'Note' => ['table' => 'data_treatment_notes', 'type' => 'LEFT', 'conditions' => 'Note.treatment_id = DataTreatment.id'],
            'Payment' => ['table' => 'data_payment', 'type' => 'LEFT', 'conditions' => 'Payment.uid = DataTreatment.uid and Payment.type = "TREATMENT"'],
        ],
        )->where($_where)->order(['DataTreatment.id' => 'DESC'])->all();
        
        $arr_treatments = array();
        if (!empty($certTreatment)) {
             foreach ($certTreatment as $row) {
                if( !empty($row['treatments_string_id']) ){
                    $ss_address = $row['address'] . ', ' . $row['city'] . ', ' . $row['State']['name'] . ' ' . $row['zip'];
                        if (!empty($row['suite'])) {
                            $ss_address = $row['address'] . ', ' . $row['suite'] . ', ' . $row['city'] . ', ' . $row['State']['name'] . ' ' . $row['zip'];
                        }
   
                   $begin_after_pictures = date('Y-m-d', strtotime($row['schedule_date']->i18nFormat('yyyy-MM-dd') . ' + 14 days'));
                   $end_after_pictures = date('Y-m-d', strtotime($row['schedule_date']->i18nFormat('yyyy-MM-dd') . ' + 21 days'));
                   $show_add_after_pictures = (date('Y-m-d') >= $begin_after_pictures && date('Y-m-d') <= $end_after_pictures) ? true : false;
                   
                   $this->loadModel('SpaLiveV1.DataTreatmentImage');
                   $images_after = $this->DataTreatmentImage->find()->where(['DataTreatmentImage.treatment_id' => $row['id'], 'DataTreatmentImage.typeImage' => 'after'])->toArray();
                   
                   $show_add_after_pictures = $show_add_after_pictures && count($images_after) == 0 ? true : false;
   
                    $separate_treatments = $Treatments->separate_treatments($row['treatments_text'],$row['treatments_string_id'],$user["user_id"]);

                    //$calculate_promo_code = $Treatments->calculate_promo_code($row['amount'],$row['promo_code']);
                    $discount = $row['Payment']['subtotal'] - $row['Payment']['total'];  $this->log(__LINE__ . ' ' . json_encode($discount));
                    if($discount > 0){
                        $discount = "$". number_format(($discount/100), 2, '.', ',');
                    } else{
                        $discount = '';
                    }
                    
                    $arr_treatments[] = array(
                        'treatment_uid' => $row['uid'],
                        'schedule_date' => $row['schedule_date']->i18nFormat('yyyy-MM-dd'),
                        'schedule_date_formated' => $row['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm'),
                        'status' => $row['status'],
                        'treatments' => $separate_treatments["neurotoxins"],
                        'treatments_iv' => $separate_treatments["iv_therapy"],
                        'treatmenst_string' => $row['treatments_text'],
                        'injector' => $row['injector'],
                        'patient' => $row['clinic_patient_id'] > 0 ? $row['clinic_patient'] : $row['patient'],
                        'amount' => $row['amount'],
                        'tip' => $row['tip'],
                        'discount' => $discount,
                        'total' =>  $row['Payment']['total']+$row['tip'],
                        'clinic' => $row['clinic_patient_id'] > 0 ? $row['bname'] : '',
                        'address' => $ss_address,
                        'reviewed' => empty($row['Review']['score']) ? false : true,
                        'score' => empty($row['Review']['score']) ? 0 : $row['Review']['score'],
                        'comments' => empty($row['Review']['comments']) ? 'No comments' : $row['Review']['comments'],
                        'review_id' => empty($row['Review']['id']) ? 0 : intval($row['Review']['id']),
                        'notes' => empty($row['Note']['notes']) ? 'Without notes.' : trim($row['Note']['notes']),
                        'like' => empty($row['Review']['like']) ? 'NOTVALUED' : $row['Review']['like'],
                        'rating' => empty($row['Review']['like']) ? 'NOTVALUED' : $row['Review']['like'],
                        'provider_uid' => $row['provider_uid'],
                        'show_button_after' => date('Y-m-d') >= date('Y-m-d', strtotime($row['schedule_date']->i18nFormat('yyyy-MM-dd') . ' + 14 days')) ? true : false,
                        'show_add_after_pictures' => $show_add_after_pictures,
                        'begin_after_pictures' => $begin_after_pictures,
                        'end_after_pictures' => $end_after_pictures,
                    );
                }
            }
            $this->set('past_treatments', $arr_treatments);
        }

        $this->set('data', $result);
        $this->success();
    }

    private function search_by_full_name_or_email($filter, $latitude, $longitude, $join, $conditions){
        $conditions = " AND DSM.days <> '' AND (SELECT COUNT(TrP.id) FROM data_treatments_prices TrP WHERE TrP.user_id = DC.id AND TrP.deleted = 0) > 0 ";
        $having = '';
        $order = '';
        if(!empty($filter)){
            $matchValue = str_replace(' ', '', $filter);
            if(!filter_var($filter, FILTER_VALIDATE_EMAIL)){
                $conditions .= " AND ( CONCAT(DC.name,DC.mname,DC.lname) = '{$matchValue}' OR CONCAT(DC.name,DC.lname) = '{$matchValue}')";                
            }else{
                $conditions .= " AND (DC.email = '{$filter}')";
            }
        }

        $filterByMostReview = false;
        $tmp_cond = "";
        if(!empty($icon_filter)){
            $this->loadModel('CatIconTrophy');
            $arr_filter = explode('||', $icon_filter);
            foreach($arr_filter as $item){
                if($item == 'MOST_REVIEW'){
                    $filterByMostReview = true;
                }else{
                    $icon = $this->CatIconTrophy->find()->select(['CatIconTrophy.id'])->where(['CatIconTrophy.uid' => $item])->first();
                    if(!empty($icon)){
                        $tmp_cond .= " AND DatIcon.icon_id = ".$icon->id; 
                    }
                }   
            }

            if(!empty($tmp_cond)){
                $join .= " INNER JOIN  data_user_icon DatIcon ON DatIcon.user_id = DC.id ";
            }
        }

        // if ($user['user_role'] != 'patient') {
        //     $latitude = 0;
        // }
        
        $first_day = date('Y-m-01');
        $last_day = date('Y-m-t');
        if ($latitude == 0 || $longitude == 0) {
            $str_query_find = "
                SELECT 
                    *, 
                    DC.id as user_id, DC.city,
                    DC.show_most_review,
                    (SELECT abv FROM cat_states CS WHERE CS.id = DC.state) state,
                    (SELECT COUNT(Training.id) 
                        FROM data_trainings Training
                        INNER JOIN cat_trainings CatTraining ON CatTraining.id = Training.training_id 
                        WHERE Training.user_id = DC.id AND Training.deleted = 0 AND CatTraining.deleted = 0 GROUP BY DC.id) trainings,
                    9999 distance_in_mi,
                    (SELECT Count(DTRT.id) FROM data_treatment_reviews DTRT WHERE DTRT.injector_id = DC.id AND DTRT.like = 'LIKE') likes    
                FROM sys_users DC
                {$join}
                WHERE (DC.type = 'injector' OR DC.type = 'gfe+ci') AND DC.deleted = 0 AND DC.steps = 'HOME' AND DC.is_test = 0 {$conditions}
                {$tmp_cond}
                GROUP BY DC.id
                {$having}
                {$order}
                ";
        } else {
            $str_query_find = "
                SELECT 
                    *, 
                    DC.id as user_id, DC.city,
                    DC.show_most_review,
                    (SELECT abv FROM cat_states CS WHERE CS.id = DC.state) state,
                    (SELECT COUNT(Training.id) 
                        FROM data_trainings Training
                        INNER JOIN cat_trainings CatTraining ON CatTraining.id = Training.training_id 
                        WHERE Training.user_id = DC.id AND Training.deleted = 0 AND CatTraining.deleted = 0 GROUP BY DC.id) trainings,
                    69.09 * DEGREES(ACOS(LEAST(1.0, COS(RADIANS({$latitude}))
                        * COS(RADIANS(DC.latitude))
                        * COS(RADIANS({$longitude} - DC.longitude))
                        + SIN(RADIANS({$latitude}))
                        * SIN(RADIANS(DC.latitude))))) AS distance_in_mi,
                    (SELECT Count(DTRT.id) FROM data_treatment_reviews DTRT WHERE DTRT.injector_id = DC.id AND DTRT.like = 'LIKE') likes
                FROM sys_users DC
                {$join}
                WHERE (DC.type = 'injector' OR DC.type = 'gfe+ci') AND DC.deleted = 0 AND DC.steps = 'HOME' AND DC.is_test = 0 {$conditions}
                {$tmp_cond}
                GROUP BY DC.id
                {$having}
                {$order}
                ";
        }
        
        $arr_find = $this->SysUsers->getConnection()->execute($str_query_find)->fetchAll('assoc');
        
        $result = array();
        $result2 = array();

        $arr_review_reach = [];
        $arr_review_unreach = [];
        if (!empty($arr_find)) {
            $most_reviewed = $this->DataTreatmentReview->injectorMostReviewed();                                

            if(!filter_var($filter, FILTER_VALIDATE_EMAIL)){
                usort($arr_find, function($a, $b) use ($filter) {
                    $full_name_a = $a['mname'] != '' ? $a['name'] . $a['mname'] . $a['lname'] : $a['name'] . $a['lname'];
                    $full_name_b = $b['mname'] != '' ? $b['name'] . $b['mname'] . $b['lname'] : $b['name'] . $b['lname'];
                    $similarityA = similar_text($full_name_a, $filter);
                    $similarityB = similar_text($full_name_b, $filter);
                    return $similarityB <=> $similarityA;
                });
            }

            foreach($arr_find as $row) {
                if ($row['trainings'] > 0 && $row['active'] == 1) {
                    $cc = new CourseController();                        
                    $trainings_user = $cc->get_courses_user($row['user_id']);
                    $first_letter_lname = !empty($row['lname']) ? strtoupper(substr($row['lname'], 0, 1)) : '';
                    $adding = array(
                        'uid' => $row['uid'],
                        'name' => $row['name'] . ' ' . $first_letter_lname,
                        'city' => $row['city'],
                        'short_uid' => $row['short_uid'],
                        'photo_id' => intval($row['photo_id']),
                        'description' => !empty($row['description']) ? $row['description'] : '',
                        'score' => 0,//$score,
                        'state' => $row['state'],
                        'availability' => $this->schedule_availability($row['uid']),
                        'treatmets_provided' => 
                        $trainings_user['has_advanced_course'] ? 
                            array(
                                'Neurotoxins (Basic)',
                                'Neurotoxins (Advanced)',
                            ) : 
                            array(
                                'Neurotoxins (Basic)',                            
                            ),
                        'likes' => $row['likes'],
                    );
                    $result[] = $adding;
                }
            }

        }

        return $result;
    }

    public function validate_coordinates($chain, $zip){      
        $latitude = 0;
        $longitude = 0;
        $mapsResponse = $this->get_coordinates($chain);                 
        if( $mapsResponse['status']=='OK' ) {
            $latitude  = isset($mapsResponse['results'][0]['geometry']['location']['lat']) ? $mapsResponse['results'][0]['geometry']['location']['lat'] : "";
            $longitude = isset($mapsResponse['results'][0]['geometry']['location']['lng']) ? $mapsResponse['results'][0]['geometry']['location']['lng'] : "";
        } else {            
            $zipResponse = $this->get_coordinates($zip . '');
            if( $zipResponse['status']=='OK' ) {
                $latitude  = isset($zipResponse['results'][0]['geometry']['location']['lat']) ? $zipResponse['results'][0]['geometry']['location']['lat'] : "";
                $longitude = isset($zipResponse['results'][0]['geometry']['location']['lng']) ? $zipResponse['results'][0]['geometry']['location']['lng'] : "";
            }                        
        }

        if( $latitude == 0 && $longitude == 0){
            require_once(ROOT . DS . 'vendor' . DS  . 'zipcodes' . DS . 'init.php');
            
            $data = isset(\zipcodes\Zipcodes::DATA[$zip]) ? \zipcodes\Zipcodes::DATA[$zip] : null;
            if ($data) {
                $latitude = $data['lat'];
                $longitude = $data['lng']; 
            } else {

                for($i = 1; $i <= 100; $i++) {
                     $nzip = intval($zip)+$i;
                     $bnzip = 5 - strlen(strval($nzip));
                     $rzip = $nzip;
                     if (strlen(strval($nzip)) < 5) {
                        for($c = 0; $c < $bnzip ;$c++) {
                            $rzip = '0' . $rzip;
                        }
                    }
                    
                     $data = isset(\zipcodes\Zipcodes::DATA[$rzip]) ? \zipcodes\Zipcodes::DATA[$rzip] : null;
                     if ($data) {
                        $latitude = $data['lat'];
                        $longitude = $data['lng'];
                        break;
                     }

                 }

            }
        }

        $coordinates = array(
            'latitude'  => $latitude,
            'longitude' => $longitude
        );
        return $coordinates;
    }

    public function get_coordinates($address){
        $gmap_key = "AIzaSyAjgOOZWRGxB_j9AZUKgoa0ohzS3GQ--nU";
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode($address) . '&key=' . $gmap_key;
                    
        $responseData = file_get_contents($url);
        
        return json_decode($responseData, true);
    }

    public function cat_treatments_ci(){

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

        $this->loadModel('SpaLiveV1.CatCITreatments');
        $ent_treatments = $this->CatCITreatments->find()->select(['CatCITreatments.id','CatCITreatments.treatment_id','CatCITreatments.name','CatCITreatments.product_id','CatCITreatments.max','CatCITreatments.details','CatCITreatments.min','CatCITreatments.qty','Product.comission_spalive','Exam.name'])
        ->where([
            'CatCITreatments.deleted' => 0,
            'CatCITreatments.name <>' => 'Let my Certified Provider decide what brand is best for me',
            'Product.category <>' => 'FILLERS',
            'CatCITreatments.id <' => 81,
        ])->join([
            'Exam' => ['table' => 'cat_treatments', 'type' => 'INNER', 'conditions' => 'Exam.id = CatCITreatments.treatment_id'],
            'Product' => ['table' => 'cat_products', 'type' => 'LEFT', 'conditions' => 'Product.id = CatCITreatments.product_id'],
        ])->all();
        

        if(!empty($ent_treatments)){
            $result = array();

            foreach ($ent_treatments as $row) {

                $t_array = array(
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'details' => $row['details'],
                    'exam_id' => $row['treatment_id'],
                    'product_id' => $row['product_id'],
                    'exam_name' => $row['Exam']['name'],
                    'fillers' => 0,
                );
                if (USER_TYPE == "injector" || MASTER || USER_TYPE == "gfe+ci") {
                    $t_array['max'] = $row['max'];
                    $t_array['min'] = $row['min'];
                    $t_array['qty'] = $row['qty'];
                    $t_array['ci_comission'] = intval($row['Product']['comission_spalive']);
                }

                $result[] = $t_array;
            }
        }

        $this->set('data', $result);
        $this->success();
    }

    public function update_provider_description(){
        $token = get('token', '');
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
    
        $user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();
        $user->description = get('description', '');
    
        if($this->SysUsers->save($user)){
            $this->success();
        } else {
            $this->message('Error updating description.');
        }
    }

    public function summary_gfeci(){
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataConsultation');
        $this->loadModel('SpaLiveV1.DataCertificates');
        $this->loadModel('SpaLiveV1.CatLabels');
        $this->loadModel('SpaLiveV1.DataExaminersClinics');
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $token = get('token', '');

        $Treatments = new TreatmentsController();
        
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

        //Neurotoxins status
        $this->loadModel('SpaLiveV1.CatTrainings');
        $this->loadModel('SpaLiveV1.DataTrainings');
        $this->loadModel('SpaLiveV1.DataCourses');
        $this->loadModel('SpaLiveV1.CatAgreements');
        $this->loadModel('SpaLiveV1.DataAgreements');
        $this->loadModel('SpaLiveV1.DataSubscriptions');


        $ntstatustrain = '';
        $attendedTrain = false;
            //get cat trainings nt
        $ntrcats = $this->CatTrainings->find()->where(["CatTrainings.deleted" => 0,'CatTrainings.level IN ("LEVEL 1", "LEVEL 2")' ])->all();

            //get data trainings by user id
        $trrainings = $this->DataTrainings->find()->where(["DataTrainings.deleted" => 0,'DataTrainings.user_id' => USER_ID])->all();

            //find one with the level 1 or 2 compared with the tags

        if(!empty($trraining)){
            foreach($trrainings as $datatrain){
                foreach($ntrcats as $cattrain){
                    if($datatrain['training_id'] == $cattrain['id']){
                        $ntstatustrain = 'train';
                        if($datatrain['attended'] == 1){
                            $attendedTrain = true;

                        }
                    }
                }
            }
        }
        
        //$this->set('trrainings', $trrainings);

            //verify it one of these are attended
        if($attendedTrain){
            $ntstatustrain = 'attended_train';
        }
        
        $this->set('NT_status_train', $ntstatustrain);

            //verify data courses if it has one with his user id
        $coursess = $this->DataCourses->find()->where(["DataCourses.deleted" => 0,'DataCourses.user_id' => USER_ID])->first();
        $coursessdone = $this->DataCourses->find()->where(["DataCourses.deleted" => 0,'DataCourses.user_id' => USER_ID, "DataCourses.status" => 'DONE'])->first();

        $ntstatuscourse = '';
        //$this->set('coursess', $coursess);
        if(!empty($coursess)){
            $ntstatuscourse = 'course';
        }

        if(!empty($coursessdone)){
            $ntstatuscourse = 'course done';
        }

        $this->set('NT_status_course', $coursess);
            //get examiners agreements neurotoxins
        $catagreess = $this->CatAgreements->find()->where(["CatAgreements.deleted" => 0,'CatAgreements.user_type' => 'EXAMINER', 'CatAgreements.agreement_type' => 'REGISTRATION'])->all();
        $datagreess = $this->DataAgreements->find()->where(["DataAgreements.deleted" => 0,'DataAgreements.user_id' => USER_ID])->all();

        //$this->set('catagreess', $catagreess);
        //$this->set('datagreess', $datagreess);
        
        $ntstatusagree = '';
        $ntstatusagreemd = '';
        $ntstatusagreemsl = '';
        $signedagrees = false;
        if(!empty($datagreess)){
            foreach($datagreess as $datagree){
                foreach($catagreess as $catagree){
                    if($datagree['agreement_uid'] == $catagree['uid']){
                        $ntstatusagree = 'agrees';
                        if($catagree['agreement_title']=='REGISTRATION' ){
                            $ntstatusagreemsl = 'registration_msl_agree';
                            if($catagree['agreement_title']=='REGISTRATION' && $datagree['file_id'] > 0){
                                $ntstatusagreemsl = 'registration_msl_agree_signed';
                                $this->set('mslsign', $datagree['file_id']);
                            }
                            $this->set('msl', $catagree['agreement_title']);

                        }

                        if($catagree['agreement_title']=='Examiner Agreement'){
                            $ntstatusagreemd = 'registration_md_agree';
                            if($datagree['file_id'] > 0){
                                $ntstatusagreemd = 'registration_md_agree_signed';
                                $this->set('mdsign', $datagree['file_id']);
                            }
                            $this->set('md', $catagree['agreement_title']);

                        }

                    }
                }
            }
        }

        if(!empty($ntstatusagreemd)){
            $ntstatusagree = $ntstatusagreemd;

        }

        if(!empty($ntstatusagreemsl)){
            $ntstatusagree = $ntstatusagreemsl;

        }
        
        
        $this->set('agrees', $datagreess);
        $this->set('NT_status_agree', $ntstatusagree);
            //verify if it has the subscription
        $ntsubtypes = '"SUBSCRIPTIONMD", "SUBSCRIPTIONMSL", "SUBSCRIPTIONMSLSERVICES", "SUBSCRIPTIONMSL+IVT", "SUBSCRIPTIONMD+IVT", "SUBSCRIPTIONMSL+FILLERS", "SUBSCRIPTIONMD+FILLERS", "SUBSCRIPTIONMSL+IVT+FILLERS", "SUBSCRIPTIONMD+IVT+FILLERS"';
        $datasubs = $this->DataSubscriptions->find()->where(["DataSubscriptions.deleted" => 0,"DataSubscriptions.user_id" => USER_ID,'DataSubscriptions.subscription_type IN ('.$ntsubtypes.')', 'DataSubscriptions.status' => 'ACTIVE' ])->all();

        $ntstatussub = '';
        if(!empty($datasubs)){
            $ntstatussub = 'subscription';

        }

        $this->set('NT_status_subscription', $ntstatussub);

        ////NTsts

        $this->set('emergencyPhone', $this->emergencyPhone);
        // $this->set('emergencyPhone2', $this->emergencyPhone);

        $userType = $user['user_role'];
        $this->loadModel('SpaLiveV1.DataMessages');
        $c_count = $this->DataMessages->find()->select(['DataMessages.id'])->where(["DataMessages.deleted" => 0,'DataMessages.id_to' => USER_ID, 'DataMessages.readed' => 0])->count();
        $this->set('unread_messages', $c_count);
        
        if(USER_TYPE == 'injector' || USER_TYPE == 'gfe+ci'){
            $this->loadModel('SpaLiveV1.DataTrainers');

            $ent_trainer = $this->DataTrainers->find()->where(['DataTrainers.injector_id' => USER_ID, 'DataTrainers.deleted' => 0])->first();

            if(empty($ent_trainer)){
                $this->set('show_trainer', true);
            }else{
                $this->set('show_trainer', false);
            }
        }

        // PENDING AGREEMENTS *****************

        $pending_agreements_array = array();
        $this->set('pending_agreements', array());
        $uid_agreement_subscription = $this->requestInjectorSubscriptionAgreement();
        if (!empty($uid_agreement_subscription)) $pending_agreements_array[] = $uid_agreement_subscription;

        $uid_agreement_register = $this->requestRegisterAgreement();
        if (!empty($uid_agreement_register)) $pending_agreements_array[] = $uid_agreement_register;
        
        $uid_subscription = $this->requestSubscriptionMDSummary();
        if (!empty($uid_subscription)) $pending_agreements_array[] = $uid_subscription;
        

        $this->set('pending_agreements', $pending_agreements_array);

        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();
        if (empty($ent_user)) {
            return; 
        }

        //$ent_permiso = $this->DataExaminersClinics->find()->where(['DataExaminersClinics.user_id' => USER_ID, 'DataExaminersClinics.deleted' => 0])->first();
        $ent_permiso = $this->DataExaminersClinics->find()->where(['DataExaminersClinics.user_id' => USER_ID, 'DataExaminersClinics.aprovied' => 'APPROVED', 'DataExaminersClinics.deleted' => 0])->first();

        $_where = "";
        if($ent_user->speak_spanish == 1){
            $_where = " AND DC.language IN ('ENGLISH', 'SPANISH')";
        }else{
            $_where = " AND DC.language IN ('ENGLISH')";
        }

        $examiner_state = $ent_user->state;
        
        if (empty($ent_permiso)) {
            $_where = " AND DC.type IN ('spa')";
            $_where_state = "AND SU.state = {$examiner_state}";
        }else{
            $_where = " AND DC.type IN ('spa', 'mint')";
            $_where_state = '';
        }

        $c_date = date('Y-m-d H:i:s');
        $str_query = "
        SELECT  
            ( SELECT COUNT(DC.id)
            FROM data_consultation DC
            JOIN sys_users SU ON SU.id = DC.patient_id
            WHERE DC.deleted = 0 {$_where_state} " . $_where . "
            AND (
                (
                    (DC.assistance_id = 0 AND DC.schedule_by = 0 AND status = \"INIT\")
                    OR (assistance_id = {$user['user_id']} AND schedule_by > 0 AND status = \"INIT\" AND is_waiting = 1)
                )
                AND (DC.reserve_examiner_id = " . USER_ID . " OR DC.reserve_examiner_id = 0)
            )
            AND TIMESTAMPDIFF(second, DC.modified, '{$c_date}') < 15
            #AND TIMESTAMPDIFF(MINUTE,schedule_date,NOW()) BETWEEN 0 AND 30 
            #AND DATE(schedule_date) = DATE(NOW())
            ) waiting,
            ( SELECT COUNT(DC.id)
            FROM data_consultation DC
            JOIN sys_users SU ON SU.id = DC.patient_id
            WHERE status = \"INIT\" 
            AND DC.schedule_by > 0 
            {$_where_state}
            AND TIMESTAMPDIFF(MINUTE,DC.schedule_date,'{$c_date}') <= 15
            AND DC.assistance_id = 0 AND DC.deleted = 0
            " . $_where . "
            ) schedule
        ";


        
        $ent_query = $this->SysUsers->getConnection()->execute($str_query)->fetchAll('assoc');
        
        if (empty($ent_query)) {
            return;
        }

        $response_gfe = array();
        $response_gfe['schedule'] = intval($ent_query[0]['schedule']);
        $response_gfe['waiting'] = intval($ent_query[0]['waiting']);

        $this->set('login_status', $ent_user->login_status);
        $this->set('gfe', $response_gfe);

        // other services
        $str_query = "
        SELECT COUNT(DC.id) as waiting
            FROM data_consultation_other_services DC
            JOIN sys_users SU ON SU.id = DC.patient_id
            WHERE SU.state = {$examiner_state} AND DC.deleted = 0
            AND (
                (
                    (DC.assistance_id = 0 AND DC.schedule_by = 0 AND status = 'INIT')
                    OR (assistance_id = {$user['user_id']} AND schedule_by > 0 AND status = 'INIT' AND is_waiting = 1)
                )
                AND (DC.reserve_examiner_id = " . USER_ID . " OR DC.reserve_examiner_id = 0)
            )
            AND TIMESTAMPDIFF(second, DC.modified, '{$c_date}') < 15 
        ";
        
        $ent_query = $this->SysUsers->getConnection()->execute($str_query)->fetchAll('assoc');
        
        if (empty($ent_query)) {
            $this->set('gfe_os', []);
        }else{
            $response_gfe = array();            
            $response_gfe['waiting'] = intval($ent_query[0]['waiting']);
            $this->set('gfe_os', $response_gfe);
        }
        

        //Scheduled Evaluations

        $fields = ['DataConsultation.uid','DataConsultation.schedule_date'];
        $fields['patient'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataConsultation.patient_id)";

        $_where = ['DataConsultation.deleted' => 0];
        $_where['DataConsultation.assistance_id'] = USER_ID;
        $_where['DataConsultation.status NOT IN'] = array('CANCEL', 'DONE', 'CERTIFICATE');
        //$_where['DataConsultation.schedule_date >'] = "NOW()";
        $_where['DataConsultation.schedule_by >'] = 0;
        $str_now = date('Y-m-d H:i:s');
        $_where[] = "TIMESTAMPDIFF(MINUTE, DataConsultation.schedule_date, '{$str_now}') <= 15";

        $cert_schedule_consultations = $this->DataConsultation->find()->select($fields)
        ->where($_where)->order(['DataConsultation.id' => 'DESC'])->all();
        
        $arr_scheduled = array();
        if (!empty($cert_schedule_consultations)) {
            $arr_scheduled = array();
            foreach ($cert_schedule_consultations as $row) {
                $arr_scheduled[] = array(
                        'treatment_uid' => $row['uid'],
                        'schedule_date' => $row['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm'),
                        'patient' => $row['patient'],
                    );
            }
        }

        $this->set('scheduled_evaluations', $arr_scheduled);




        // PENDING EVALUATIONS

        $fields = ['DataConsultation.uid','DataConsultation.schedule_date','DataConsultation.status','State.name'];
        $fields['patient'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataConsultation.patient_id)";
        $fields['treatments'] = "(SELECT GROUP_CONCAT(CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DataConsultation.treatments))";

        $_where = ['DataConsultation.deleted' => 0];
        $_where['DataConsultation.assistance_id'] = USER_ID;
        $_where['DataConsultation.status'] = "DONE";
        $_where['DataCertificates.id IS'] = null;
    

        $ent_pending = $this->DataConsultation->find()->select($fields)
        ->join([
            'DataCertificates' => ['table' => 'data_certificates', 'type' => 'LEFT', 'conditions' => 'DataCertificates.consultation_id = DataConsultation.id'],
            'Patient' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'Patient.id = DataConsultation.patient_id'],
            'State' => ['table' => 'cat_states', 'type' => 'LEFT', 'conditions' => 'State.id = Patient.state'],
        ])
        ->where($_where)->order(['DataConsultation.id' => 'DESC'])->all();

        
        $arr_pending = array();
        if (!empty($ent_pending)) {
            $arr_scheduled = array();
            foreach ($ent_pending as $row) {
                $arr_pending[] = array(
                        'consultation_uid' => $row['uid'],
                        'schedule_date' => $row['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm'),
                        'patient' => $row['patient'],
                        'treatments' => $row['treatments'],
                        'state' => $row['State']['name'],
                    );
            }
        }

        $this->set('pending_evaluations', $arr_pending);


        // PAST EXAMS

            $fields = ['DataConsultation.uid','DataConsultation.status','DataConsultation.schedule_date','DataConsultation.status','DataCertificates.uid'];
        $fields['patient'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataConsultation.patient_id)";
        $fields['treatments'] = "(SELECT GROUP_CONCAT(CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DataConsultation.treatments))";

        $_where = ['DataConsultation.deleted' => 0];
        $_where['DataConsultation.assistance_id'] = USER_ID;
        $_where['OR'] = [['DataConsultation.status' => "DONE"], ['DataConsultation.status' => "CERTIFICATE"]];
        $_where['DataCertificates.id IS NOT'] = null;
    

        $ent_pending = $this->DataConsultation->find()->select($fields)
        ->join([
            'DataCertificates' => ['table' => 'data_certificates', 'type' => 'LEFT', 'conditions' => 'DataCertificates.consultation_id = DataConsultation.id'],
        ])
        ->where($_where)->order(['DataConsultation.id' => 'DESC'])->all();

        
        $arr_pending = array();
        if (!empty($ent_pending)) {
            $arr_scheduled = array();
            foreach ($ent_pending as $row) {
                $arr_pending[] = array(
                        'consultation_uid' => $row['uid'],
                        'certificate_uid' => empty($row['DataCertificates']['uid']) ? '' : $row['DataCertificates']['uid'],
                        'schedule_date' => $row['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm'),
                        'patient' => $row['patient'],
                        'treatments' => $row['treatments'],
                        'status' => $row['status'],
                    );
            }
        }

        $this->set('past_exams', $arr_pending);


        // PAST EXAMS WL

        $fields = ['DataConsultationOtherServices.uid','DataConsultationOtherServices.status','DataConsultationOtherServices.schedule_date','DataConsultationOtherServices.status'];
        $fields['patient'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataConsultationOtherServices.patient_id)";        

        $_where = ['DataConsultationOtherServices.deleted' => 0];
        $_where['DataConsultationOtherServices.assistance_id'] = USER_ID;
        $_where['OR'] = [['DataConsultationOtherServices.status != ' => "CANCEL"], ['DataConsultationOtherServices.status != ' => "REJECTED"]];
        //$_where['DataCertificates.id IS NOT'] = null;    

        $_join = [
            'CheckIn' => ['table' => 'data_other_services_check_in', 'type' => 'LEFT', 'conditions' => 'CheckIn.consultation_uid = DataConsultationOtherServices.uid and CheckIn.support_id =  ' . USER_ID],
        ];

        $ent_pending = $this->DataConsultationOtherServices->find()->select($fields)
        ->join($_join)
        ->where($_where)->order(['DataConsultationOtherServices.id' => 'DESC'])->all();
        
        $arr_pending = array();
        if (!empty($ent_pending)) {
            $arr_scheduled = array();
            foreach ($ent_pending as $row) {
                $arr_pending[] = array(
                        'consultation_uid' => $row['uid'],
                        'schedule_date' => $row['schedule_date']->i18nFormat('MM-dd-yyyy HH:mm'),
                        'patient' => $row['patient'],                        
                        'status' => $row['status'],
                        'call_type' => 'FIRST CONSULTATION',
                    );
            }
        }
        $this->set('past_exams_wl', $arr_pending);

        // ACTUAL APPOINTMENT

        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.DataTreatmentsPrice');
        $this->loadModel('SpaLiveV1.DataPurchases');

        $fields = ['DataTreatment.id','DataTreatment.uid','DataTreatment.schedule_date','DataTreatment.status','DataTreatment.address','DataTreatment.treatments','DataTreatment.state','State.name','DataTreatment.patient_id', 'DataTreatment.city','DataTreatment.zip','DataTreatment.suite','DataTreatment.latitude','DataTreatment.longitude','Patient.name','Patient.lname','Patient.uid','Injector.latitude','Injector.longitude','Injector.uid','Injector.name','Injector.lname','DataTreatment.notes'];
        // $fields['patient'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.patient_id)";
        // $fields['patient_uid'] = "(SELECT U.uid FROM sys_users U WHERE U.id = DataTreatment.patient_id LIMIT 1)";
        // $fields['assistance_uid'] = "(SELECT U.uid FROM sys_users U WHERE U.id = DataTreatment.assistance_id LIMIT 1)";
        $fields['treatments_string'] = "(SELECT GROUP_CONCAT(CT.name) FROM cat_treatments_ci CT WHERE FIND_IN_SET(CT.id,DataTreatment.treatments))";

        
        $fields['distance_in_mi'] = "(69.09 * DEGREES(ACOS(LEAST(1.0, COS(RADIANS(DataTreatment.latitude))
                                    * COS(RADIANS(Injector.latitude))
                                    * COS(RADIANS(DataTreatment.longitude - Injector.longitude))
                                    + SIN(RADIANS(DataTreatment.latitude))
                                    * SIN(RADIANS(Injector.latitude))))))";

        $_where = ['DataTreatment.deleted' => 0];
        // $_where['DataTreatment.status'] = "CONFIRM";

        $_where['OR'] = [['DataTreatment.status' => "CONFIRM"], ['DataTreatment.status' => "DONE", 'DataTreatment.payment' => ""]];

        $_where['DataTreatment.assistance_id'] = USER_ID;
        $_where['DataTreatment.home'] = 1;
        // $_where[] = "DataTreatment.schedule_date > NOW()";
        // $_where[] = "DataTreatment.schedule_date > DATE_ADD(DATE(NOW()), INTERVAL -2 HOUR)";

        // $certTreatment = $this->DataTreatment->find()->select($fields)->join([
        $certTrtArr = $this->DataTreatment->find()->select($fields)->join([
            'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
            'Patient' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Patient.id = DataTreatment.patient_id AND Patient.deleted = 0'],
            'Injector' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Injector.id = DataTreatment.assistance_id AND Patient.deleted = 0'],
        ])->where($_where)->order(['DataTreatment.schedule_date' => 'ASC'])->toArray();
                        

        $arr_treatments = array();
        $arr_appotments = [];
        // if (!empty($certTreatment)) {
        foreach($certTrtArr as $index => $certTreatment){

            $_fields = ['DataTreatmentsPrice.price','DataTreatmentsPrice.treatment_id','Treatments.name','Treatments.qty', 'Treatments.treatment_id'];
            $_fields['certificate'] = "(SELECT DC.uid
                    FROM cat_treatments_ci CTC
                    JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                    JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                    JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                    WHERE CTC.deleted = 0 AND CTC.id = DataTreatmentsPrice.treatment_id AND DCP.proceed = 1 AND NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                    LIMIT 1)";
            $_fields['consultation'] = "(SELECT DC.uid
                    FROM cat_treatments_ci CTC
                    JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                    JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                    JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                    WHERE CTC.deleted = 0 AND CTC.id = DataTreatmentsPrice.treatment_id AND NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                    ORDER BY DCO.modified DESC
                    LIMIT 1)";
            $_fields['certificate_status'] = "(
            SELECT DC.status FROM data_consultation DC 
            WHERE FIND_IN_SET(  (SELECT CTpt.parent_id FROM cat_treatments CTpt WHERE CTpt.id = Treatments.treatment_id LIMIT 1)
                , DC.treatments) AND DC.status = 'DONE' AND DC.patient_id = ".$certTreatment->patient_id." AND DC.deleted = 0 LIMIT 1)";

            

            $ent_prices = $this->DataTreatmentsPrice->find()->select($_fields)->join([
                'Treatments' => ['table' => 'cat_treatments_ci', 'type' => 'INNER', 'conditions' => 'Treatments.id = DataTreatmentsPrice.treatment_id'],
            ])->where(['DataTreatmentsPrice.deleted' => 0, 'FIND_IN_SET(DataTreatmentsPrice.treatment_id, "' . $certTreatment->treatments . '")' ,'DataTreatmentsPrice.user_id' => USER_ID])->all();

            $sign_agreement = false;

            $this->loadModel('SpaLiveV1.Agreement');
            $this->loadModel('SpaLiveV1.SysUsers');
            $this->loadModel('SpaLiveV1.CatAgreements');

            $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $certTreatment->patient_id])->first();
        
            $ent_agreement = $this->CatAgreements->find()->select(['CatAgreements.uid','DataAgreement.id','DataAgreement.deleted'])->join([
                    'DataAgreement' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'CatAgreements.uid = DataAgreement.agreement_uid AND DataAgreement.user_id = ' . $certTreatment->patient_id . ' AND DataAgreement.deleted = 0'],
                ])->where(
                [
                    'CatAgreements.state_id' => $certTreatment->state,
                    'CatAgreements.user_type' => 'patient',
                    'CatAgreements.agreement_type' => 'REGISTRATION',
                    'CatAgreements.deleted' => 0,
                ]
            )->first();
            if (!empty($ent_agreement) && empty($ent_agreement['DataAgreement']['id'])) {
                $sign_agreement = true;
            }

            $data_tr = array();

            if (!empty($ent_prices)) {
                foreach ($ent_prices as $row) {
                    $data_tr[] = array(
                        'name' => $row['Treatments']['name'],
                        'treatment_id' => intval($row['treatment_id']),
                        'price' => intval($row['price']),
                        'qty' => intval($row['Treatments']['qty']),
                        'certificate' => !empty($row['certificate']) ? $row['certificate'] : '',
                        'consultation' => !empty($row['consultation']) ? $row['consultation'] : '',
                        'certificate_status' => !empty($row['certificate_status']) ? $row['certificate_status'] : '',

                    );
                }
            }
            $sstr_address = $certTreatment->address . ', ' . $certTreatment->city . ', ' . $certTreatment['State']['name'] . ' ' . $certTreatment->zip;
            if (!empty($certTreatment->suite)) {
                $sstr_address = $certTreatment->address . ', ' . $certTreatment->suite . ', ' . $certTreatment->city . ', ' . $certTreatment['State']['name'] . ' ' . $certTreatment->zip;
            }

            $re_array = array(
                'uid' => $certTreatment->uid,
                'latitude' => doubleval($certTreatment->latitude),
                'longitude' => doubleval($certTreatment->longitude),
                'patient_uid' => $certTreatment['Patient']['uid'],
                'assistance_uid' => $certTreatment['Injector']['uid'],
                'schedule_date' => $certTreatment->schedule_date,
                'status' => $certTreatment->status,
                'notes' => $certTreatment->notes,
                'distance_in_mi' => $certTreatment->latitude > 0 ? round($certTreatment->distance_in_mi,1) : 0,
                'address' => $sstr_address,
                'patient' => $certTreatment['Patient']['name'] . ' ' . $certTreatment['Patient']['lname'],
                'treatments' => $certTreatment->treatments_string,
                'treatments_detail' => $data_tr,
                'sign_patient' => $sign_agreement,
                'out_reach' => 0,
            );
            $arr_appotments[] = $re_array;
            if($index == 0){
                $this->set('actual_appointment', $re_array);
            }
        }
        $this->set('actual_appointments', $arr_appotments);


        // Scheduled appointments
        $this->loadModel('SpaLiveV1.DataTreatment');

        $fields = ['DataTreatment.uid','DataTreatment.schedule_date','DataTreatment.status', 'DataTreatment.address', 'DataTreatment.zip', 'DataTreatment.city', 'State.name', 'DataTreatment.suite','DataTreatment.latitude','DataTreatment.longitude','Patient.name','Patient.lname','Patient.uid','Injector.latitude','Injector.longitude','Injector.uid','Injector.name','Injector.lname','treatments_id' => 'DataTreatment.treatments', 'DataTreatment.patient_id','DataTreatment.notes'];
        // $fields['assistance'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.patient_id)";
        // $fields['assistance_uid'] = "(SELECT U.uid FROM sys_users U WHERE U.id = DataTreatment.assistance_id)";
        // $fields['patient_uid'] = "(SELECT U.uid FROM sys_users U WHERE U.id = DataTreatment.patient_id LIMIT 1)";
        $fields['treatments'] = "(SELECT GROUP_CONCAT(CTI.name) FROM cat_treatments_ci CTI WHERE FIND_IN_SET(CTI.id,DataTreatment.treatments))";

        $fields['distance_in_mi'] = "(69.09 * DEGREES(ACOS(LEAST(1.0, COS(RADIANS(DataTreatment.latitude))
                                    * COS(RADIANS(Injector.latitude))
                                    * COS(RADIANS(DataTreatment.longitude - Injector.longitude))
                                    + SIN(RADIANS(DataTreatment.latitude))
                                    * SIN(RADIANS(Injector.latitude))))))";
        
        $_where = ['DataTreatment.deleted' => 0];
        // $_where['DataTreatment.status !='] = "DONE";
        $_where['DataTreatment.status'] = "INIT";
        $_where['DataTreatment.assistance_id'] = USER_ID;
        // $_where['DataTreatment.status'] = "!= CANCEL";
        // $_where[] = "DataTreatment.schedule_date > DATE_ADD(DATE(NOW()), INTERVAL -2 HOUR)";
        // $_where[] = "DataTreatment.schedule_date > DATE_ADD(DATE(NOW()), INTERVAL -15 DAY)";
        // $_where[] = "DataTreatment.schedule_date > NOW()";
        

        $certTreatment = $this->DataTreatment->find()->select($fields)
        ->join([
                'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
                'Patient' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Patient.id = DataTreatment.patient_id AND Patient.deleted = 0'],
                'Injector' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Injector.id = DataTreatment.assistance_id AND Injector.deleted = 0'],
            ])
            ->where($_where)->order(['DataTreatment.id' => 'DESC'])->all();
                        
        $arr_treatments = array();
        if (!empty($certTreatment)) {
            foreach ($certTreatment as $row) {

                $_fields = ['DataTreatmentsPrice.price','DataTreatmentsPrice.treatment_id','Treatments.name','Treatments.qty', 'Treatments.treatment_id'];
                $_fields['certificate'] = "(SELECT DC.uid
                        FROM cat_treatments_ci CTC
                        JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                        JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                        JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                        WHERE CTC.deleted = 0 AND CTC.id = DataTreatmentsPrice.treatment_id AND DCP.proceed = 1 AND NOW() < DC.date_expiration AND DCO.patient_id = " . $row->patient_id . "
                        LIMIT 1)";
                $_fields['certificate_status'] = "(
                SELECT DC.status FROM data_consultation DC 
                WHERE FIND_IN_SET(  (SELECT CTpt.parent_id FROM cat_treatments CTpt WHERE CTpt.id = Treatments.treatment_id LIMIT 1)
                    , DC.treatments) AND DC.status = 'DONE' AND DC.patient_id = ".$row->patient_id." AND DC.deleted = 0 LIMIT 1)";

                $ent_prices = $this->DataTreatmentsPrice->find()->select($_fields)->join([
                    'Treatments' => ['table' => 'cat_treatments_ci', 'type' => 'INNER', 'conditions' => 'Treatments.id = DataTreatmentsPrice.treatment_id'],
                ])->where(['DataTreatmentsPrice.deleted' => 0, 'FIND_IN_SET(DataTreatmentsPrice.treatment_id, "' . $row->treatments_id . '")' ,'DataTreatmentsPrice.user_id' => USER_ID])->all();

                $data_tr = array();

                if (!empty($ent_prices)) {
                    foreach ($ent_prices as $item) {
                        $data_tr[] = array(
                            'name' => $item['Treatments']['name'],
                            'treatment_id' => intval($item['treatment_id']),
                            'price' => intval($item['price']),
                            'qty' => intval($item['Treatments']['qty']),
                            'certificate' => !empty($item['certificate']) ? $item['certificate'] : '',
                            'certificate_status' => !empty($item['certificate_status']) ? $item['certificate_status'] : '',
                        );
                    }
                }

                $str_address = $row['address'] . ', ' . $row['city'] . ', ' . $row['State']['name'] . ' ' . $row['zip'];
                if (!empty($row['suite'])) {
                    $str_address = $row['address'] . ', ' . $row['suite'] . ', ' . $row['city'] . ', ' . $row['State']['name'] . ' ' . $row['zip'];
                }

                $arr_treatments[] = array(
                    'treatment_uid' => $row['uid'],
                    'notes' => $row['notes'],
                    'latitude' => doubleval($row['latitude']),
                    'longitude' => doubleval($row['longitude']),
                    'schedule_date' => $row['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm:ss'),
                    'assistance' => $row['Patient']['name'] . ' ' . $row['Patient']['lname'],
                    'assistance_uid' => $row['Injector']['uid'],
                    'patient_uid' => $row['Patient']['uid'],
                    'treatments' => $row['treatments'],
                    'distance_in_mi' => $row->latitude > 0 ? round($row->distance_in_mi,1) : 0,
                    'status' => $row['status'],
                    'address' => $str_address,
                    'out_reach' => ($row->latitude != 0 && round($row->distance_in_mi,1) > $user['user_radius'] ) ? 1 : 0,
                    'treatments_detail' => $data_tr,
                );
            }
            $this->set('scheduled_treatments', $arr_treatments);
        }



            //CI NETWORK

        $result_array = array();
        $arr_search = array();
        $grand_total = 0;
        $arr_search[] = array('user_id' => USER_ID);
        $level_parent_id = USER_ID;


        $should_continue = true;
        $level_count = 0;

        $this->set('patients', $this->find_patients(true));

        //PAST Treatments

        $this->loadModel('SpaLiveV1.DataTreatment');

        $fields = ['DataTreatment.id','DataTreatment.uid','DataTreatment.schedule_date','DataTreatment.status','DataTreatment.amount','DataTreatment.tip','DataTreatment.address','DataTreatment.city','State.name','DataTreatment.zip', 'DataTreatment.clinic_patient_id','Review.score','Review.comments','Review.id','Note.notes', 'DataTreatment.suite','DataTreatment.notes'];
        $fields['injector'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.assistance_id)";
        $fields['patient'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.patient_id)";
        $fields['clinic_patient'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.clinic_patient_id)";
        $fields['treatments_text'] = "((SELECT GROUP_CONCAT(CONCAT(CTC.name,' (',CT.name, ')') SEPARATOR ', ') 
                                        FROM cat_treatments_ci CT 
                                        JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
                                        WHERE FIND_IN_SET(CT.id,DataTreatment.treatments) LIMIT 1))";
        $fields['treatments_detal'] = "(SELECT GROUP_CONCAT(cat_treatment_id) FROM data_treatment_detail WHERE treatment_id = DataTreatment.id)";
        $fields['images'] = "(GROUP_CONCAT(Image.file_id))";
        $fields['images_after'] = "(GROUP_CONCAT(ImageAfter.file_id))";
        $fields['images_before'] = "(GROUP_CONCAT(ImageBefore.file_id))";
        $_where = ['DataTreatment.deleted' => 0];
        $_where['DataTreatment.status in'] = array("DONE", "DONESELFTREATMENT");
        $_where['DataTreatment.payment <>'] = '';
        $_where['DataTreatment.assistance_id'] = USER_ID;
        // $_where['Review.deleted'] = 0;

        $certTreatment = $this->DataTreatment->find()->select($fields)
        ->join([
            'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
            'Review' => ['table' => 'data_treatment_reviews', 'type' => 'LEFT', 'conditions' => 'Review.treatment_id = DataTreatment.id'],
            'Note' => ['table' => 'data_treatment_notes', 'type' => 'LEFT', 'conditions' => 'Note.treatment_id = DataTreatment.id'],
            'Image' => ['table' => 'data_treatment_image', 'type' => 'LEFT', 'conditions' => 'Image.treatment_id = DataTreatment.id'],
            'ImageAfter' => ['table' => 'data_treatment_image', 'type' => 'LEFT', 'conditions' => 'ImageAfter.treatment_id = DataTreatment.id AND ImageAfter.typeImage = "after"'],
            'ImageBefore' => ['table' => 'data_treatment_image', 'type' => 'LEFT', 'conditions' => 'ImageBefore.treatment_id = DataTreatment.id AND ImageBefore.typeImage = "before"']
            ]
        )->where($_where)->group(['DataTreatment.id'])->order(['DataTreatment.id' => 'DESC']);
                        
        $arr_treatments = array();
        if (!empty($certTreatment)) {
            // pr($certTreatment);exit;
            foreach ($certTreatment as $row) {

                $ss_address = $row['address'] . ', ' . $row['city'] . ', ' . $row['State']['name'] . ' ' . $row['zip'];
                if (!empty($row['suite'])) {
                    $ss_address = $row['address'] . ', ' . $row['suite'] . ', ' . $row['city'] . ', ' . $row['State']['name'] . ' ' . $row['zip'];
                }

                $begin_after_pictures = date('Y-m-d', strtotime($row['schedule_date']->i18nFormat('yyyy-MM-dd') . ' + 14 days'));
                $end_after_pictures = date('Y-m-d', strtotime($row['schedule_date']->i18nFormat('yyyy-MM-dd') . ' + 21 days'));
                $show_add_after_pictures = (date('Y-m-d') >= $begin_after_pictures && date('Y-m-d') <= $end_after_pictures) ? true : false;
                
                $this->loadModel('SpaLiveV1.DataTreatmentImage');
                $images_after = $this->DataTreatmentImage->find()->where(['DataTreatmentImage.treatment_id' => $row['id'], 'DataTreatmentImage.typeImage' => 'after'])->toArray();

                $this->loadModel('SpaLiveV1.CatCITreatments');
                $citreatments = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.name', 'Cat.name'])
                ->join([
                    'Cat' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'Cat.id = CatCITreatments.category_treatment_id'],
                    ]
                )->where(['CatCITreatments.id IN' => explode(',', $row['treatments_detal'])])->all();
                //pr($citreatments);exit;
                $array_treatments_name = [];
                foreach ($citreatments as $citreatment) {
                    $array_treatments_name[] = $citreatment['name'] . ' (' . $citreatment['Cat']['name'] . ')';
                }
                
                $show_add_after_pictures = $show_add_after_pictures && count($images_after) == 0 ? true : false;

                $calculate_promo_code = $Treatments->calculate_promo_code($row['amount'],$row['promo_code']);

                $arr_treatments[] = array(
                    'id' => $row['id'],
                    'images' => (isset($row['images']) && $row['images'] ? array_values(array_unique(explode(',', $row['images']))) : []),
                    'images_after' => (isset($row['images_after']) && $row['images_after'] ? array_values(array_unique(explode(',', $row['images_after']))) : []),
                    'images_before' => (isset($row['images_before']) && $row['images_before'] ? array_values(array_unique(explode(',', $row['images_before']))) : []),
                    'treatment_uid' => $row['uid'],
                    'notes' => $row['notes'],
                    'schedule_date' => $row['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm'),
                    'status' => $row['status'],
                    'treatments' => $row['treatments_text'] == '' ? implode(', ', $array_treatments_name) : $row['treatments_text'],
                    'injector' => $row['injector'],
                    'patient' => $row['clinic_patient_id'] > 0 ? $row['clinic_patient'] : $row['patient'],
                    'amount' => $row['amount'] + $row['tip'],
                    'discount' => $calculate_promo_code['discount'],
                    'total' => $calculate_promo_code['total']+$row['tip'],
                    'tip' => $row['tip'],
                    'clinic' => $row['clinic_patient_id'] > 0 ? $row['bname'] : '',
                    'address' => $ss_address,
                    'reviewed' => empty($row['Review']['score']) ? false : true,
                    'score' => empty($row['Review']['score']) ? 0 : $row['Review']['score'],
                    'comments' => empty($row['Review']['comments']) ? 'No comments' : $row['Review']['comments'],
                    'review_id' => empty($row['Review']['id']) ? 0 : intval($row['Review']['id']),
                    'notes' => empty($row['Note']['notes']) ? 'Without notes.' : trim($row['Note']['notes']),
                    'show_add_after_pictures' => $show_add_after_pictures,
                    'begin_after_pictures' => $begin_after_pictures,
                    'end_after_pictures' => $end_after_pictures,
                    'type' => '', // Error en prod, lo mando vacio porque no se que se debe enviar
                );
            }  
            $this->set('past_treatments', $arr_treatments);
        }


        // //PAST Treatments

        // $this->loadModel('SpaLiveV1.DataTreatment');


        // $fields = ['DataTreatment.uid','DataTreatment.schedule_date','DataTreatment.status','DataTreatment.amount', 'DataTreatment.tip','DataTreatment.address','DataTreatment.city','State.name','DataTreatment.zip', 'DataTreatment.clinic_patient_id','Review.score','Review.comments','Review.id','Note.notes', 'DataTreatment.suite'];
        // $fields['injector'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.assistance_id)";
        // $fields['patient'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.patient_id)";
        // $fields['clinic_patient'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.clinic_patient_id)";
        // $fields['treatments_text'] = "(SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments_ci CT WHERE FIND_IN_SET(CT.id,DataTreatment.treatments))";
        // $_where = ['DataTreatment.deleted' => 0];
        // $_where['DataTreatment.status'] = "DONE";
        // $_where['DataTreatment.assistance_id'] = USER_ID;
        // // $_where['Review.deleted'] = 0;
        
        

        // $certTreatment = $this->DataTreatment->find()->select($fields)
        // ->join([
        //     'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
        //     'Review' => ['table' => 'data_treatment_reviews', 'type' => 'LEFT', 'conditions' => 'Review.treatment_id = DataTreatment.id'],
        //     'Note' => ['table' => 'data_treatment_notes', 'type' => 'LEFT', 'conditions' => 'Note.treatment_id = DataTreatment.id']
        // ])->where($_where)->order(['DataTreatment.id' => 'DESC'])->all();
                        
        // $arr_treatments = array();
        // if (!empty($certTreatment)) {
        //     foreach ($certTreatment as $row) {

        //             $ss_address = $row['address'] . ', ' . $row['city'] . ', ' . $row['State']['name'] . ' ' . $row['zip'];
        //             if (!empty($row['suite'])) {
        //                 $ss_address = $row['address'] . ', ' . $row['suite'] . ', ' . $row['city'] . ', ' . $row['State']['name'] . ' ' . $row['zip'];
        //             }

        //             $arr_treatments[] = array(
        //                 'treatment_uid' => $row['uid'],
        //                 'schedule_date' => $row['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm'),
        //                 'status' => $row['status'],
        //                 'treatments' => $row['treatments_text'],
        //                 'injector' => $row['injector'],
        //                 'patient' => $row['clinic_patient_id'] > 0 ? $row['clinic_patient'] : $row['patient'],
        //                 'amount' => $row['amount']+ $row['tip'],
        //                 'clinic' => $row['clinic_patient_id'] > 0 ? $row['bname'] : '',
        //                 'address' => $ss_address,
        //                 'reviewed' => empty($row['Review']['score']) ? false : true,
        //                 'score' => empty($row['Review']['score']) ? 0 : $row['Review']['score'],
        //                 'comments' => empty($row['Review']['comments']) ? 'No comments' : $row['Review']['comments'],
        //                 'review_id' => empty($row['Review']['id']) ? 0 : intval($row['Review']['id']),
        //                 'notes' => empty($row['Note']['notes']) ? 'Without notes.' : trim($row['Note']['notes']),
        //             );
        //     }
        //     $this->set('past_treatments', $arr_treatments);
        // }

        // SCHEDULED EXAM PATIENT

        $str_now = date('Y-m-d H:i:s');
        $str_query_scheduled = "
            SELECT 
                DC.uid, DC.status, DC.meeting, DC.meeting_pass,CONCAT(U.`name`,\" \",U.lname) patient,CONCAT(UA.`name`,\" \",UA.lname) assistance,U.state, DC.schedule_date, DC.treatments, UA.uid assistance_uid,
                (SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DC.treatments)) treatments
            FROM data_consultation DC
            JOIN sys_users U ON U.id = DC.patient_id
            # JOIN sys_users Inj ON Inj.id = DC.createdby
            LEFT JOIN sys_users UA ON UA.id = DC.assistance_id
            WHERE DC.deleted = 0 AND DC.createdby = " . USER_ID . " AND DC.status = 'INIT' AND DC.schedule_by > 0
            AND TIMESTAMPDIFF(MINUTE, DC.schedule_date, '{$str_now}') <= 15 "; //AND DC.schedule_date > NOW()" ;


        $ent_scheduled = $this->DataConsultation->getConnection()->execute($str_query_scheduled)->fetchAll('assoc');

        $arr_scheduled = array();
        if(!empty($ent_scheduled)){
            foreach ($ent_scheduled as $row) {
                $arr_scheduled[] = array(
                    'uid' => $row['uid'],
                    'patient_name' => $row['patient'],
                    'treatment_uid' => $row['uid'],
                    'meeting' => $row['meeting'],
                    'meeting_pass' => $row['meeting_pass'],
                    'schedule_date' => $row['schedule_date'],
                    'treatments' => $row['treatments'],
                    'assistance' => $row['assistance'] ? $row['assistance'] : '',
                    'assistance_uid' => $row['assistance_uid'] ? $row['assistance_uid'] : '',
                    'status' => $row['status'],
                );
            }
        }
        $this->set('scheduled_eval_patient', $arr_scheduled);



            // CHECK CPR CERTIFICATION
        $this->loadModel('SpaLiveV1.DataUserCprLicence');
        $ent_cpr = $this->DataUserCprLicence->find()->where(['DataUserCprLicence.user_id' => USER_ID])->first();

        $this->set('request_cpr', empty($ent_cpr) ? true : false);


        // CHECK TRAININGS

        

        $this->loadModel('SpaLiveV1.DataTrainings');
        $this->loadModel('SpaLiveV1.CatTrainigs');


        $now = date('Y-m-d H:i:s');

        $_fields = ['CatTrainigs.id', 'CatTrainigs.title', 'CatTrainigs.scheduled', 'CatTrainigs.neurotoxins', 'CatTrainigs.fillers', 'CatTrainigs.materials', 'CatTrainigs.available_seats', 'CatTrainigs.level','State.name','State.abv','CatTrainigs.address','CatTrainigs.zip','CatTrainigs.city', 'data_training_id' => 'DataTrainigs.id'];
        $_fields['assistants'] = "(SELECT COUNT(id) from data_trainings DT WHERE DT.training_id = CatTrainigs.id AND DT.deleted = 0)";
        $_fields['enrolled'] = "(SELECT COUNT(id) from data_trainings DT WHERE DT.training_id = CatTrainigs.id AND DT.deleted = 0 AND DT.user_id = " . USER_ID . " )";
        $_join = [
            'DataTrainigs' => ['table' => 'data_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainigs.training_id'],
            'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = CatTrainigs.state_id']
        ];
        $_where = ['CatTrainigs.level' => 'LEVEL 1','DataTrainigs.user_id' => USER_ID, 'DataTrainigs.deleted' => 0, 'CatTrainigs.deleted' => 0,'(DATE_FORMAT(CatTrainigs.scheduled, "%Y-%m-%d 09:00:00") > "' . $now . '")'];

        
        $enrolled_trainings  = $this->CatTrainigs->find()->select($_fields)
        ->join($_join)
        ->where($_where)->all();



        $tr_result = array();

        if (!empty($enrolled_trainings)) {

            foreach($enrolled_trainings as $row) {

                $seats = $row['available_seats'] - $row['assistants'];
                if($seats <= 0) $seats = 0;

                $address = $row->address.', '.$row->city.', '.$row->State['abv'].' '.$row->zip;
                $tr_result[] = array(
                    'id'            => $row['id'],
                    'title'         => $row['title'],
                    'scheduled'   => $row['scheduled']->i18nFormat('MM-dd-Y hh:mm a'),
                    'available_seats' => $seats,
                    'status' => 'ENROLLED',
                    'address' => $address,
                    'level' => $row['level'],
                );
            }
        } 


        $this->set('booked_trainings_basic', $tr_result);

        $_where = ['CatTrainigs.level' => 'LEVEL 2','DataTrainigs.user_id' => USER_ID, 'DataTrainigs.deleted' => 0, 'CatTrainigs.deleted' => 0,'(DATE_FORMAT(CatTrainigs.scheduled, "%Y-%m-%d 09:00:00") > "' . $now . '")'];

        $enrolled_trainings  = $this->CatTrainigs->find()->select($_fields)
        ->join($_join)
        ->where($_where)->all();



        $tr_result = array();

        if (!empty($enrolled_trainings)) {

            foreach($enrolled_trainings as $row) {

                $seats = $row['available_seats'] - $row['assistants'];
                if($seats <= 0) $seats = 0;

                $address = $row->address.', '.$row->city.', '.$row->State['abv'].' '.$row->zip;
                $tr_result[] = array(
                    'id'            => $row['id'],
                    'title'         => $row['title'],
                    'scheduled'   => $row['scheduled']->i18nFormat('MM-dd-Y hh:mm a'),
                    'available_seats' => $seats,
                    'status' => 'ENROLLED',
                    'address' => $address,
                    'level' => $row['level'],
                );
            }
        } 

        $this->set('booked_trainings_advanced', $tr_result);

                
        $request_training = false;
        $request_training_advanced = false;
        $ent_data_training = $this->DataTrainings->find()->join([
            'CatTrainigs' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainings.training_id'],
        ])->where(['CatTrainigs.level' => 'LEVEL 1','DataTrainings.user_id' => USER_ID,'DataTrainings.deleted' => 0])->first();

        if (empty($ent_data_training)) {
            $request_training = true;
        }

        $level2_id = 44; // FIEX ID OF Advanced training

        $this->loadModel('SpaLiveV1.DataPurchases');

        $_ent_purchases =$this->DataPurchases->find()
        ->join([
            'DataPurchasesDetail' => ['table' => 'data_purchases_detail', 'type' => 'INNER', 'conditions' => 'DataPurchasesDetail.purchase_id = DataPurchases.id']
        ])
        ->where(['DataPurchases.user_id' => USER_ID, 'DataPurchases.payment <>' => '','DataPurchasesDetail.product_id' => $level2_id,'DataPurchases.deleted' => 0])->first();


        if (!empty($_ent_purchases)) {
            $ent_data_training = $this->DataTrainings->find()->join([
            'CatTrainigs' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainings.training_id'],
            ])->where(['CatTrainigs.level' => 'LEVEL 2','DataTrainings.user_id' => USER_ID,'DataTrainings.deleted' => 0])->first();

            if (empty($ent_data_training)) {
                $request_training_advanced = true;
            }
        }


        // CAT LABELS
        $findLabels = $this->CatLabels->find()->select(['CatLabels.key_field', 'CatLabels.value'])->where(['CatLabels.deleted' => 0])->toArray();
        $labels = [];
        foreach($findLabels as $item){
            $labels[$item->key_field] = str_replace('GFE_COST', $this->total/100 , $item->value);
            $labels[$item->key_field] = str_replace('GFE_DOUBLE', ($this->total/100)*2, $labels[$item->key_field]);
            $labels[$item->key_field] = str_replace('GFE_PAYMENT', $this->paymente_gfe/100 , $labels[$item->key_field]);
            $labels[$item->key_field] = str_replace('CI_REGISTRATION', $this->register_total/100 , $labels[$item->key_field]);
            $labels[$item->key_field] = str_replace('CI_REFUND', $this->register_refund/100 , $labels[$item->key_field]);
            $labels[$item->key_field] = str_replace('CI_ADVANCED', $this->training_advanced/100 , $labels[$item->key_field]);
        }
        $this->set('labels', $labels);

        $this->loadModel('SpaLiveV1.DataTreatmentsPrice');

        $r_services = true;
        $r_available = true;
        $ent_tprice = $this->DataTreatmentsPrice->find()->where(['DataTreatmentsPrice.user_id' => USER_ID])->first();
        if (!empty($ent_tprice)) {
            $this->loadModel('SpaLiveV1.DataScheduleModel');
            $ent_sch_model = $this->DataScheduleModel->find()->where(['DataScheduleModel.deleted' => 0, 'DataScheduleModel.injector_id' => USER_ID])->first();

            if (!empty($ent_sch_model) && !empty($ent_sch_model->days)) {
                $r_available = false;
                $r_services = false;
            }
            
        }

        $this->set('request_services', $r_services);
        $this->set('request_availability', $r_available);

        $this->requestSubscriptions();      

        $this->success(); 

        //Request Fill Profil
        $r_profile = true;
        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();
        if (!empty($ent_user)) {
            if ($ent_user->photo_id != 93) {
                $r_profile = false;
            }
        }

        /*********** OTHER SERVICES CONSULTATIONS ******************/
        $other_calls = [];

        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $this->loadModel('SpaLiveV1.CatOtherServices');
        $other_services_consultations = $this->DataConsultationOtherServices->find()
        ->select($this->DataConsultationOtherServices)
        ->select(['Service.title'])
        ->select(['patient_name' => "CONCAT_WS(' ', SysUsers.name, SysUsers.lname)"]
        )->join([
            'SysUsers' => [
                'table' => 'sys_users', 
                'type' => 'LEFT', 
                'conditions' => 'SysUsers.id = DataConsultationOtherServices.patient_id'],
            'Service' => [
                    'table' => 'cat_other_services', 
                    'type' => 'LEFT', 
                    'conditions' => 'Service.uid = DataConsultationOtherServices.service_uid'],
        ])->where(['DataConsultationOtherServices.assistance_id' => USER_ID, 'DataConsultationOtherServices.deleted' => 0])->all();

        if(!empty($other_services_consultations)){
            foreach($other_services_consultations as $row_c) {

                $iat = time();
                $exp = $iat + 60 * 60;
                $sdkKey = env('ZOOM_SDK_KEY','');
                $sdkSecret = env('ZOOM_SDK_SECRET','');
                $payload = [
                    'sdkKey' => $sdkKey,
                    'mn'=> $row_c->meeting, //meet number
                    'role' =>  0,
                    'iat' =>  $iat,
                    'exp' =>  $exp,
                    'appKey' =>  $sdkKey,
                    'tokenExp' =>  $exp
                   
                ];

                $jwt = JWT::encode($payload, $sdkSecret, 'HS256');

                $this->loadModel('SpaLiveV1.DataConsultationAnswersOtherServices');
                $this->loadModel('SpaLiveV1.CatQuestionOtherServices');

                $ent_answers = $this->DataConsultationAnswersOtherServices->find()
                ->select($this->DataConsultationAnswersOtherServices)
                ->select(['Question.question'])
                ->join([
                    'Question' => [
                            'table' => 'cat_question_other_services', 
                            'type' => 'LEFT', 
                            'conditions' => 'Question.id = DataConsultationAnswersOtherServices.question_id'],
                ])->where(['DataConsultationAnswersOtherServices.consultation_id' => $row_c->id])->all();

                $questions = [];

                if(!empty($ent_answers)){
                    foreach($ent_answers as $row_ans) {
                        $questions[] = array(
                            'id'        => $row_ans->id,
                            'question'  => $row_ans["Question"]["question"],
                            'response'  => $row_ans->response,
                        );
                    }
                }

                $other_calls[] = array(
                    'uid'              => $row_c->uid,
                    'meeting'          => $row_c->meeting,
                    'meeting_pass'     => $row_c->meeting_pass,
                    'join_url'         => $row_c->join_url,
                    'schedule_date'    => $row_c->schedule_date->i18nFormat('MM-dd-yyyy hh:mm a'),
                    'user_name'        => $row_c->patient_name,
                    'jwt'              => $jwt,
                    'created_by'       => $row_c->createdby,
                    'date_created'     => $row_c->created->i18nFormat('MM-dd-yyyy hh:mm a'),
                    'goals'            => $row_c->goals,
                    'id'               => $row_c->id,
                    'is_waiting'       => $row_c->is_waiting,
                    'notes'            => $row_c->notes,
                    'patient_id'       => $row_c->patient_id,
                    'service_title'    => $row_c["Service"]["title"],
                    'service_uid'      => $row_c->service_uid,
                    'status'           => $row_c->status,
                    'questions'        => $questions,
                );
            }
        }

        $exa_ser_array = [];

        $this->loadModel('SpaLiveV1.DataExaminersOtherServices');
        $examiners_other_services = $this->DataExaminersOtherServices->find()
        ->select($this->DataExaminersOtherServices)
        ->select(['Service.title'])
        ->join([
            'Service' => [
                    'table' => 'cat_other_services', 
                    'type' => 'LEFT', 
                    'conditions' => 'Service.uid = DataExaminersOtherServices.service_uid'],
        ])->where(['DataExaminersOtherServices.user_id' => USER_ID, 'DataExaminersOtherServices.deleted' => 0])->all();

        if(!empty($examiners_other_services)){

            foreach($examiners_other_services as $row_e) {

                if($row_e->aprovied=="APPROVED"||$row_e->aprovied=="NOAPPLY"){
                    $approved = false;

                    if($row_e->aprovied=="APPROVED"){
                        $approved = true;
                    }

                    $exa_ser_array[] = array(
                        'service_title'     => $row_e["Service"]["title"],
                        'aprovied'          => $approved,
                    );
                }
            }

        }

        $check_in = false;
        $this->loadModel('SpaLiveV1.DataUsersOtherServicesCheckIn');
        $can_see_check_in = $this->DataUsersOtherServicesCheckIn->find()
        ->where(['DataUsersOtherServicesCheckIn.user_id' => USER_ID, 'DataUsersOtherServicesCheckIn.deleted' => 0])->first();

        if(!empty($can_see_check_in)){
            $check_in = true;
        }

        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');

        $select = [
            'DataOtherServicesCheckIn.id',
            'DataOtherServicesCheckIn.consultation_uid',
            'DataOtherServicesCheckIn.created',
            'DataOtherServicesCheckIn.patient_id',
            'DataOtherServicesCheckIn.purchase_id',
            'DataOtherServicesCheckIn.call_type',
            'DataOtherServicesCheckIn.call_title',
            'DataOtherServicesCheckIn.current_weight',
            'DataOtherServicesCheckIn.deleted',
            'DataOtherServicesCheckIn.call_date',
            'DataPurchasesOtherServices.status',
            'DataConsultationOtherServices.service_uid',            
            'Patient.name',
            'Patient.lname',
            'DataConsultationOtherServices.main_goal_weight',
        ];
        
        $pending_answers = $this->DataOtherServicesCheckIn->find()
            ->select($select)
            ->join([
                "DataConsultationOtherServices" => ['table' => 'data_consultation_other_services', 'type' => 'LEFT', 'conditions' => 'DataOtherServicesCheckIn.consultation_uid = DataConsultationOtherServices.uid'],
                "DataConsultationOtherServices" => ['table' => 'data_consultation_other_services', 'type' => 'LEFT', 'conditions' => 'DataOtherServicesCheckIn.consultation_uid = DataConsultationOtherServices.uid'],
                "DataPurchasesOtherServices" => ['table' => 'data_purchases_other_services', 'type' => 'LEFT', 'conditions' => 'DataOtherServicesCheckIn.purchase_id = DataPurchasesOtherServices.id'],
                "Patient" =>  ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'DataOtherServicesCheckIn.patient_id = Patient.id']
            ])
            ->where([                
                'DataOtherServicesCheckIn.support_id' => USER_ID,
                'DataOtherServicesCheckIn.pending_answers' => 1,
                'DataOtherServicesCheckIn.deleted' => 0,      
            ])->all();
        
        $array_dos = [];

        foreach($pending_answers as $pa){

            $created = $pa->created->format('m/d/Y H:i');

            $array_dos[] = [
                "id" => $pa->id,
                "consultation_uid" => $pa->consultation_uid,
                "created" => $created,
                "schedule_date" => $pa->call_date->format('m/d/Y'),
                "patient_fname" => $pa["Patient"]["name"].' '.$pa["Patient"]["lname"],
                "call_type" => $pa->call_type, // "FIRST CONSULTATION", "CHECK IN", "FOLLOW UP
                "call_title" => $pa->call_title,
                "service_title" => $this->get_service_title($pa["DataConsultationOtherServices"]["service_uid"]),
                "service_uid" => $pa["DataConsultationOtherServices"]["service_uid"],
                "current_weight" => $pa->current_weight,
                "main_goal_weight" => $pa["DataConsultationOtherServices"]["main_goal_weight"],                 
            ];
        }

        $data_other_services = array(
            'pending_answers' => $array_dos,
            'scheduled_calls' => $other_calls,
            'services'        => $exa_ser_array,
            'check_in'        => $check_in,
        );

        $this->set('data_other_services', $data_other_services);
        $this->set('request_profile', $r_profile);
        $this->set('stripe_button', $this->checkStripeACcount());
        $this->set('request_schedule_training', $request_training);
        $this->set('request_schedule_training_advanced', $request_training_advanced);                            
    }

    private function formatPhoneNumber($str_phone) {
        //(469) 891 9026
        if (strlen($str_phone) != 10) return $str_phone;
        $result = '(' . $str_phone[0] . $str_phone[1] . $str_phone[2] . ')' . ' ' . $str_phone[3] .  $str_phone[4] . $str_phone[5] . ' ' . $str_phone[6] .  $str_phone[7] .  $str_phone[8] .  $str_phone[9];
        return $result;
    }

    private function get_first_cliam_id($treatment_uid) {
        $this->loadModel('SpaLiveV1.DataClaimTreatments');
        $ent_claim = $this->DataClaimTreatments->find()->
            where(['DataClaimTreatments.treatment_uid' => $treatment_uid, 'DataClaimTreatments.deleted' => 0])->first();
        if (!empty($ent_claim)) {
            return $ent_claim->injector_id;    
        }
        return 0;
    }

    // FUNCTIONS FOR THE GFE

    public function gfeStatusForTreatment($user_id, $treatment_id){        
        $this->loadModel('SpaLiveV1.DataTreatments');
        $certificates = $this->getCertificatesUser($user_id);

        if(count($certificates) === 0){
            return 'NOTSTARTED';
        }

        foreach($certificates as $certificate){
            if($certificate['expires_soon']){
                return 'NOTSTARTED';
            }
        }

        $priority = array(
            'DENIED' => 0,
            'WAITING' => 1,
            'CERTIFICATE' => 2
        );        

        $current_status = '';
        $current_priority = -1;

        foreach ($certificates as $key => $certificate) { 
            $temp_priority = $priority[$certificate['status']];
            if($temp_priority > $current_priority){
                $current_status = $certificate['status'];
                $current_priority = $temp_priority;
            }  
        }

        return $current_status;
    }

    public function getCertificatesUser($user_id)
    {
        $this->loadModel('SpaLiveV1.DataConsultation');
        $array_certificates = array();

        $ent_consultations = $this->DataConsultation->find()
        ->select([
            'DataConsultation.id', 
            'DataConsultation.uid', 
            'DataConsultation.status', 
            'DataConsultation.patient_id', 
            'DataConsultation.treatments', 
            'DataCertificates.date_expiration',
            'DataCertificates.certificate_url',
            'details_uid' => 'DataCertificates.uid',
            'treatments_approved' => '(SELECT GROUP_CONCAT(DISTINCT CT.type_trmt) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id, DataConsultation.treatments))',
            'treatments_requested' => '(SELECT GROUP_CONCAT(DISTINCT CT.type_trmt) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id, DataConsultation.treatments_requested))'
        ])
        ->join([
            'DataConsultationPlan' => ['table' => 'data_consultation_plan', 'type' => 'LEFT', 'conditions' => 'DataConsultationPlan.consultation_id = DataConsultation.id'],
            'DataCertificates'     => ['table' => 'data_certificates',      'type' => 'LEFT', 'conditions' => 'DataCertificates.consultation_id = DataConsultation.id'],                
        ])->where([
            'DataConsultation.patient_id' => $user_id, 
            'DataConsultation.deleted' => 0
        ])->toArray();
        
        $this->log('ent_consultations = >'. json_encode($ent_consultations));
        foreach ($ent_consultations as $key => $row) {
            $types = $row['treatments'] != '' ? $row['treatments_approved'] : $row['treatments_requested'];
            if($types != '') $types = explode(',', $types);
            $status = '';
            if($row->status == 'DONE'){            
                $status = 'WAITING';
            } else {
                if($row['treatments'] == ''){
                    $status = 'DENIED';
                }else{
                    $status = 'CERTIFICATE';
                }
            }   

             if($status == 'CERTIFICATE'){   
                $this->log("herere");   
                $date_expiration = $row['DataCertificates']['date_expiration'] ?? null;               

                $expired = false;
                $expires_soon = false;


                
               if(isset($date_expiration) && !empty($date_expiration)){
                    $expired = $date_expiration < date('Y-m-d H:i:s');
                    $expires_soon = date('Y-m-d') >= date('Y-m-d', strtotime($date_expiration . " - 15 days")) && date('Y-m-d') <= $date_expiration ? true : false;
               }

                if($expired){
                    continue;
                }                
            }
             $this->log("herere2");
            $key     = env('API_KEY', '12345678901234567890123456789012');
            $url_api = env('URL_API', false);
            $action_certificate  = 'get-certificate';
            $action_details      = 'get-gfe';
            $token   = get('token', '');
            
            // CERTIFICATE https://api-dev.myspalive.com/?key=2fe548d5ae881ccfbe2be3f6237d7951&l3n4p=6092482f7ce858.91169218&action=get-certificate&uid=89730462-2535-4f72-bfd8-c33b46cce2d4
            
            
            // DETAILS     https://api-dev.myspalive.com/?key=2fe548d5ae881ccfbe2be3f6237d7951
            //                                           &l3n4p=6092482f7ce858.91169218
            //                                           &action=get-gfe
            //                                           &uid=89730462-2535-4f72-bfd8-c33b46cce2d4

            //             https://api-dev.myspalive.com/?key=225sadfgasd123fgkhijjdsadfg16578g12gg3gh
            //                                           &action=get-gfe
            //                                           &uid=b6cf5413-71e4-433a-b7b2-402914722ab7
            //                                           &l3n4p=6092482f7ce858.91169218
            
            $url_certificate = $row['DataCertificates']['certificate_url'];

            $url_details = $url_api . "?key={$key}&action={$action_details}&uid={$row->details_uid}&l3n4p=6092482f7ce858.91169218";

            $array_certificates[] = array(
                'uid' => $row->uid,
                'status' => $status,
                'treatments' => $row->treatments,
                'cert' => $row,
                'types' => $types,
                'url_certificate' => $url_certificate,
                'url_details' => '',
                'expires_soon' => $expires_soon,
            );   
           // $this->log('array_certificates = >'. print_r($array_certificates, true));    
        }

        return $array_certificates;
    }

    public function get_service_title($service_uid){
        $this->loadModel('SpaLiveV1.CatOtherServices');
        $service = $this->CatOtherServices->find()->select(['title'])->where(['uid' => $service_uid])->first();
        return $service->title; 
    }

    public function services_injector(
        $user_id
    ){
        $ServicesHelper = new ServicesHelper($user_id);

        $services_types = [
            array(
                'type'  => 'BASIC NEUROTOXINS',
                'title' => $this->check_training_medical($user_id) ? 'Neurotoxin treatments (Level 3)' : 'Basic Neurotoxin treatments',
                'find' => 'NEUROTOXINS BASIC',
                'name_key' => 'NEUROTOXINS_BASIC',
                'agreement_msl' => '',
                'agreement_md'  => '',
                'training_id' => 0,
                'data_training_id' => 0,
                'data_course_id' => 0,
                'cat_key' => 'LEVEL 1'
            ),
            array(
                'type'  => 'ADVANCED NEUROTOXINS',
                'title' => 'Advanced Neurotoxin treatments',
                'find' => 'NEUROTOXINS ADVANCED',
                'name_key' => 'NEUROTOXINS_ADVANCED',
                'agreement_msl' => '',
                'agreement_md'  => '',
                'training_id' => 0,
                'data_training_id' => 0,
                'data_course_id' => 0,
                'cat_key' => 'LEVEL 2'
            ),
            array(
                'type'  => 'IV THERAPY',
                'title' => 'IV treatments',
                'find'  => 'IV THERAPY',
                'name_key' => 'IV_THERAPY',
                'agreement_msl' => '',
                'agreement_md'  => '',
                'training_id' => 0,
                'data_training_id' => 0,
                'data_course_id' => 0,
                'cat_key' => 'LEVEL IV'
            ),
            array(
                'type'  => 'FILLERS',
                'title' => 'Filler treatments',
                'find'  => 'FILLERS',
                'name_key' => 'FILLERS',
                'agreement_msl' => '',
                'agreement_md'  => '',
                'training_id' => 0,
                'data_training_id' => 0,
                'data_course_id' => 0,
                'cat_key' => 'LEVEL 3 FILLERS'
            ),
        ];

        #region OTHER TREATMENTS
        $other_treatments_user = $this->get_other_treatments_user($user_id);

          
        
        if(count($other_treatments_user) > 0){
            foreach($other_treatments_user as $other_treatment){
                $services_types[] = array(
                    'type'  => $other_treatment['type'],
                    'training_id'  => $other_treatment['training_id'],
                    'data_training_id'  => $other_treatment['data_training_id'],
                    'data_course_id'  => $other_treatment['data_course_id'] ?? 0,
                    'title' => $other_treatment['title'],
                    'find'  => 'OTHER TREATMENTS', //$other_treatment['find'],
                    'name_key' => $other_treatment['name_key'],
                    'agreement_msl' => $other_treatment['agreement_msl'],
                    'agreement_md'  => $other_treatment['agreement_md'],
                    'status' => $other_treatment['status'], // Agregar el status aquí
                );

            }
        }

        #endregion
        //var_dump($services_types);exit;
        $user_state = USER_STATE;
        $str_query_scheduled = "
            SELECT CTCAT.type
            FROM data_treatments_enabled_by_state DTEBS 
            JOIN cat_treatments_ci CTCI ON CTCI.id = DTEBS.treatment_id
            JOIN cat_treatments_category CTCAT ON CTCAT.id = CTCI.category_treatment_id 
            WHERE DTEBS.state_id = {$user_state} AND CTCI.deleted = 0
            GROUP BY CTCAT.type
        ";

        $ent_states = $this->AppToken->getConnection()->execute($str_query_scheduled)->fetchAll('assoc');
        if (!empty($ent_states)) {
            $arr_available_states = array();
            foreach($ent_states as $state) {
                $arr_available_states[] = $state['type'];
            }

            foreach($services_types as $idx=>$service) {
                if (!in_array($service['find'], $arr_available_states)) {
                    unset($services_types[$idx]);
                }
            }
        }

        $map_services = array();

        //$this->loadModel("SpaLiveV1.CatTrainings");
        $this->loadModel("SpaLiveV1.DataTrainings");
        foreach($services_types as $service_type){    
            // Para OTHER TREATMENTS, usar el status que ya calculamos
            // $service_type['data_course_id'] = 0;

            if($service_type['find'] == 'OTHER TREATMENTS'){
                $status = $service_type['status']; // Usar el status que ya calculamos
            } else {
                $service_type['training_id'] = 0;
                $service_type['data_training_id'] = 0;
                 
                $search_helper = $service_type['type'];
                $status = $ServicesHelper->service_status(
                    $search_helper,                
                );  // NOT_STARTED, WAITING, STUDYING, REJECTED, MSL, MD, DONE, SUBSCRIBE
                
                
                if ($status == 'SUBSCRIBE') {
                    $cat_key = $service_type['cat_key'];
                    
                    $arr_options = [$cat_key];
                    if ($cat_key == 'LEVEL 1') $arr_options = ['LEVEL 1', 'MYSPALIVES_HYBRID_TOX_FILLER_COURSE','MYSPALIVE_S_HYBRID_TOX_FILLER_COURSE'];
                    if ($cat_key == 'LEVEL 2') $arr_options = ['LEVEL 2', 'LEVEL_TWO_DUAL_TOX_AND_DEMALL_FILLER'];
                    if ($cat_key == 'FILLERS') $arr_options = ['FILLERS', 'LEVEL_TWO_DUAL_TOX_AND_DEMALL_FILLER','MYSPALIVES_HYBRID_TOX_FILLER_COURSE','MYSPALIVE_S_HYBRID_TOX_FILLER_COURSE'];
                    // Obtener todos los entrenamientos del usuario con información del curso
                    $ent_data_training = $this->DataTrainings->find()->select(['CatTrainigs.id','DataTrainings.id'])->join([
                        'CatTrainigs' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainings.training_id'],
                    ])->where(['CatTrainigs.level IN' => $arr_options,'DataTrainings.user_id' => USER_ID,'DataTrainings.deleted' => 0])->first();
                      
                    if (!empty($ent_data_training)) {
                        $service_type['training_id'] = $ent_data_training['CatTrainigs']['id'];
                        $service_type['data_training_id'] = $ent_data_training['id'];
                    } else {
                        $this->loadModel('SpaLiveV1.DataCourses');
                        $ot_course_data = $this->DataCourses->find()->select(['DataCourses.id'])->join([
                            'CatCourses' => ['table' => 'cat_courses', 'type' => 'INNER', 'conditions' => 'CatCourses.id = DataCourses.course_id'],
                        ])->where(['DataCourses.user_id' => USER_ID,'DataCourses.deleted' => 0,'DataCourses.status' => 'DONE','CatCourses.type' => $service_type['name_key']])->first();
                         
                        if (!empty($ot_course_data)) {
                            $service_type['data_course_id'] = $ot_course_data->id;
                        }
                    }
                }
            }
            
            ///Verify if finalize the training
            $show_check = $status == "DONE";

            // Para OTHER TREATMENTS, usar una clave única basada en el name_key
            $service_key = $service_type['find'] == 'OTHER TREATMENTS' ? 
                $service_type['name_key'] : 
                $service_type['type'];

            $map_services[$service_key] = array(
                'type'       => $service_type['find'] == 'OTHER TREATMENTS' ? $service_type['find'] : $service_type['type'],
                'show_check' => $show_check,
                //'attended' => $userTrainings,
                'title'      => $service_type['title'],
                'status'     => $status,
                'agreement_msl' => $service_type['agreement_msl'],
                'agreement_md'  => $service_type['agreement_md'],
                'name_key' => $service_type['name_key'],
                'training_id'  => $service_type['training_id'],
                'data_training_id'  => $service_type['data_training_id'],
                'data_course_id'  => $service_type['data_course_id'],
            );
        }


        // Solo aparece advanced techniques si tiene advanced neurotoxins en done

        if($map_services['ADVANCED NEUROTOXINS']['status'] != 'DONE'){
            unset($map_services['ADVANCED TECHNIQUES NEUROTOXINS']);
        }

        $bn = $map_services["BASIC NEUROTOXINS"];
        $an = $map_services["ADVANCED NEUROTOXINS"];

        if($bn['status'] == 'NOT_STARTED' && $an['status'] == 'NOT_STARTED'){
            unset($map_services["BASIC NEUROTOXINS"]);
            unset($map_services["ADVANCED NEUROTOXINS"]);

            $map_services["NEUROTOXINS"] = array(
                'type'       => 'NEUROTOXINS',
                'show_check' => false,
                'title'      => 'Basic Neurotoxin treatments',
                'status'     => 'NOT_STARTED',
            );
        }

         if($bn['status'] == 'DONE') {

         }

        $map_services = $this->dedupeServices($map_services);

        $fixed_services = array();

        foreach($map_services as $key => $value){
            $fixed_services[] = $value;
        }

        return $fixed_services;
    }

    private function dedupeServices($services): array
    {    

        // Regla 1: si BASIC NEUROTOXINS está DONE, elimina NEUROTOXINS genérico
        if (isset($services['BASIC NEUROTOXINS']) && 
            (($services['BASIC NEUROTOXINS']['status'] ?? null) === 'DONE' || ($services['BASIC NEUROTOXINS']['status'] ?? null) === 'SUBSCRIBE')) {
            foreach ($services as $key => $svc) {
                if (($svc['name_key'] ?? null) === 'NEUROTOXINS' && $key !== 'BASIC NEUROTOXINS') {
                    unset($services[$key]);
                }
            }
        }

        if (isset($services['ADVANCED NEUROTOXINS']) && 
            (($services['ADVANCED NEUROTOXINS']['status'] ?? null) === 'DONE' || ($services['ADVANCED NEUROTOXINS']['status'] ?? null) === 'SUBSCRIBE')) {
            foreach ($services as $key => $svc) {
                if (($svc['name_key'] ?? null) === 'ADVANCED_NEUROTOXINS' && $key !== 'ADVANCED NEUROTOXINS') {
                    unset($services[$key]);
                }
            }
        }

        // Regla 2: si hay FILLERS en DONE, deja solo una entrada con name_key=FILLERS
        $fillersDoneKey = null;
        foreach ($services as $key => $svc) {
            if (($svc['name_key'] ?? null) === 'FILLERS' && (($svc['status'] ?? null) === 'DONE' || ($svc['status'] ?? null) === 'SUBSCRIBE')) {
                $fillersDoneKey = $key; // guarda la primera que esté DONE
                break;
            }
        }
        if ($fillersDoneKey !== null) {
            foreach ($services as $key => $svc) {
                if ($key !== $fillersDoneKey && ($svc['name_key'] ?? null) === 'FILLERS') {
                    unset($services[$key]);
                }
            }
        }

        return $services;
    }

    public function get_other_treatments_user($user_id){
        try {
            $this->loadModel('SpaLiveV1.SysTreatmentsOt');
            $this->loadModel('SpaLiveV1.CatCoursesType');
            $this->loadModel('SpaLiveV1.DataTrainings');
            $this->loadModel('SpaLiveV1.DataSubscriptions');
            $this->loadModel('SpaLiveV1.CatTrainings');

            // Obtener todos los entrenamientos del usuario con información del curso
            $data_trainings = $this->DataTrainings->find()
                ->select([
                    'training_id' => 'DataTrainings.training_id', 
                    'data_training_id' => 'DataTrainings.id',
                    'course_type_id' => 'CTC.id',
                    'course_type_key' => 'CTC.name_key',
                    'training_title' => 'CT.title',
                    'training_level' => 'CT.level',
                    'attended' => 'DataTrainings.attended'
                ])
                ->join([
                    'CT' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CT.id = DataTrainings.training_id'],
                    'CTC' => ['table' => 'cat_courses_type', 'type' => 'INNER', 'conditions' => 'CTC.name_key = CT.level'],
                ])
                ->where([
                    'DataTrainings.user_id' => $user_id, 
                    'DataTrainings.deleted' => 0,
                    'CT.deleted' => 0,
                    'CTC.deleted' => 0,
                    'CTC.available' => 1
                ])
                ->all();

            $other_treatments_user = [];
            $processed_courses = []; // Para evitar duplicados

            foreach($data_trainings as $data_training){
                // Evitar procesar el mismo curso múltiples veces
                $course_key = $data_training->course_type_id . '_' . $data_training->training_id;
                if(in_array($course_key, $processed_courses)){
                    continue;
                }
                $processed_courses[] = $course_key;

                // Solo procesar cursos completados (attended = 1)
                if($data_training->attended != 1){
                    continue; // Saltar cursos no completados
                }

                // Obtener los servicios asociados al curso usando la lógica correcta
                $course_services = $this->CatCoursesType
                    ->find()
                    ->select([
                        'name_key' => 'OtherTreatment.name_key',
                        'name' => 'OtherTreatment.name',
                        'require_mdsub' => 'OtherTreatment.require_mdsub',
                    ])
                    ->join([
                        'Coverage' => [
                            'table' => 'data_coverage_courses', 
                            'type' => 'INNER', 
                            'conditions' => 'Coverage.course_type_id = CatCoursesType.id'
                        ],
                        'OtherTreatment' => [
                            'table' => 'sys_treatments_ot', 
                            'type' => 'INNER', 
                            'conditions' => 'OtherTreatment.id = Coverage.ot_id AND OtherTreatment.deleted = 0'
                        ]
                    ])
                    ->where(['CatCoursesType.name_key' => $data_training->training_level])
                    ->toArray();

                // Si no hay servicios asociados al curso, saltar
                if (empty($course_services)) {
                    continue;
                }

                // Extraer los name_key de los servicios
                $service_keys = array_column($course_services, 'name_key');
                
                // Buscar suscripción que contenga todos los servicios requeridos
                $__where = [
                    'user_id' => $user_id, 
                    'status' => 'ACTIVE', 
                    'subscription_type LIKE' => '%MSL%',
                    'deleted' => 0, 
                ];
                
                // Crear condiciones OR para cada servicio en main_service o addons_services
                $service_conditions = [];
                foreach ($service_keys as $service_key) {
                    $service_conditions[] = ['main_service' => $service_key];
                    $service_conditions[] = ['addons_services LIKE' => '%' . $service_key . '%'];
                }
                $__where['OR'] = $service_conditions;
                
                $ent_sub = $this->DataSubscriptions->find()->where($__where)->first();
                // $has_subscription = !empty($ent_sub);

                // Crear un registro por cada servicio del curso
                foreach($course_services as $service){
                    $has_subscription = false;

                    if (!empty($ent_sub) && strpos($ent_sub->payment_details, $service['name_key']) !== false) {
                        $has_subscription = true;
                    }
                    

                    $status = $has_subscription ? 'DONE' : 'SUBSCRIBE';
                    if ($has_subscription && !empty($service['require_mdsub']) && (int)$service['require_mdsub'] === 1) {
                        $subscription_md = $this->DataSubscriptions->find()
                            ->where([
                                'DataSubscriptions.user_id' => $user_id,
                                'DataSubscriptions.deleted' => 0,
                                'DataSubscriptions.status' => 'ACTIVE',
                                'DataSubscriptions.subscription_type LIKE' => '%MD%',
                                'OR' => [
                                    ['DataSubscriptions.main_service LIKE' => '%' . $service['name_key'] . '%'],
                                    ['DataSubscriptions.addons_services LIKE' => '%' . $service['name_key'] . '%'],
                                ],
                            ])
                            ->first();
                        if (empty($subscription_md)) {
                            $status = 'MD';
                        }
                    }
                    $agreement_msl = null;
                    $agreement_md = null;

                    // Si tiene suscripción, obtener acuerdos
                    if($has_subscription){
                        $treatments_user = $this->SysTreatmentsOt->find()
                            ->select(['agreement_msl_uid' => 'agreement_msl.uid', 'agreement_md_uid' => 'agreement_md.uid'])
                            ->join([
                                'agreement_msl' => ['table' => 'cat_agreements', 'type' => 'LEFT', 'conditions' => 'agreement_msl.other_treatment_id = SysTreatmentsOt.id AND agreement_msl.deleted = 0 AND agreement_msl.issue_type = "MSL"'],
                                'agreement_md'  => ['table' => 'cat_agreements', 'type' => 'LEFT', 'conditions' => 'agreement_md.other_treatment_id = SysTreatmentsOt.id AND agreement_md.deleted = 0 AND agreement_md.issue_type = "MD"'],
                            ])
                            ->where(['SysTreatmentsOt.name_key' => $service['name_key'], 'SysTreatmentsOt.id NOT IN' => [1,2,3,999]])
                            ->first();

                        if(!empty($treatments_user)){
                            $agreement_msl = $treatments_user->agreement_msl_uid;
                            $agreement_md = $treatments_user->agreement_md_uid;
                        }
                    }

                    $other_treatments_user[] = [
                        'type' => $data_training->training_title,
                        'training_id' => $data_training->training_id,
                        'data_training_id' => $data_training->data_training_id,
                        'data_course_id' => 0,
                        'title' => $service['name'], // Usar el name del servicio individual
                        'find' => 'OTHER TREATMENTS',
                        'name_key' => $service['name_key'], // Usar el name_key del servicio individual
                        'agreement_msl' => $agreement_msl,
                        'agreement_md' => $agreement_md,
                        'status' => $status
                    ];
                }
            }

            // --- Lógica para agregar registros adicionales sin duplicar name_key ---
            
            // 1. Obtener keys existentes
            $existing_keys = array_column($other_treatments_user, 'name_key');

            // 2. Realizar nueva consulta
            $new_records = [];
            $this->loadModel('SpaLiveV1.DataCourses');
             $user_course_other_treatments = $this->DataCourses->find()
            ->select([
                'DataCourses.id',
                'CatCourses.type',
                'CatCourses.id',
                'CatCourses.title',
                'SysTreatmentOT.name_key',
                'SysTreatmentOT.require_mdsub',
                'DataCourses.course_id',
                'SchoolOption.sys_treatment_ot_id',
                'SysTreatmentOT.id',
                'SysTreatmentOT.name'
            ])->join([
                'CatCourses' => ['table' => 'cat_courses', 'type' => 'INNER', 'conditions' => 'CatCourses.id = DataCourses.course_id'],
                'SchoolOption' => ['table' => 'cat_school_option_cert', 'type' => 'LEFT', 'conditions' => 'SchoolOption.id = CatCourses.school_option_id'],
                'SysTreatmentOT' => ['table' => 'sys_treatments_ot', 'type' => 'LEFT', 'conditions' => 'SysTreatmentOT.id = SchoolOption.sys_treatment_ot_id'],
            ])->where(['DataCourses.user_id' => USER_ID,'DataCourses.deleted' => 0,'DataCourses.status' => 'DONE'])->all();

            if (count($user_course_other_treatments) > 0) {
                foreach($user_course_other_treatments as $user_course_other_treatment){
                    if (!empty($user_course_other_treatment['CatCourses']['type']) && $user_course_other_treatment['CatCourses']['type'] != 'OTHER TREATMENTS') {
                        continue;
                    } else if (!empty($user_course_other_treatment['SysTreatmentOT']['name_key'])) {
                        $new_records[] = $user_course_other_treatment;
                    }
                }
            }

           

            // 3. Agregar nuevos registros verificando duplicados
            foreach ($new_records as $record) {
                // Asumiendo que obtienes 'name_key' del registro
                $new_key = $record['SysTreatmentOT']['name_key'];
                $has_subscription = false;

                // Buscar suscripción que contenga todos los servicios requeridos
                $__where = [
                    'user_id' => $user_id, 
                    'status' => 'ACTIVE', 
                    'subscription_type LIKE' => '%MSL%',
                    'deleted' => 0, 
                ];
                
                // Crear condiciones OR para cada servicio en main_service o addons_services
                $service_conditions = [];
                foreach ($service_keys as $service_key) {
                    $service_conditions[] = ['main_service' => $service_key];
                    $service_conditions[] = ['addons_services LIKE' => '%' . $service_key . '%'];
                }
                $__where['OR'] = $service_conditions;
                
                $ent_sub = $this->DataSubscriptions->find()->where($__where)->first();

                if (!empty($ent_sub) && strpos($ent_sub->payment_details, $new_key) !== false) {
                    $has_subscription = true;
                }

                $data_course_id = isset($record->id) ? $record->id : (isset($record['id']) ? $record['id'] : 0);

              
                
                
                $status = $has_subscription ? 'DONE' : 'SUBSCRIBE';
                $req_md = !empty($record['SysTreatmentOT']['require_mdsub']) ? (int)$record['SysTreatmentOT']['require_mdsub'] : 0;
                if ($has_subscription && $req_md === 1) {
                    $subscription_md = $this->DataSubscriptions->find()
                        ->where([
                            'DataSubscriptions.user_id' => $user_id,
                            'DataSubscriptions.deleted' => 0,
                            'DataSubscriptions.status' => 'ACTIVE',
                            'DataSubscriptions.subscription_type LIKE' => '%MD%',
                            'OR' => [
                                ['DataSubscriptions.main_service LIKE' => '%' . $new_key . '%'],
                                ['DataSubscriptions.addons_services LIKE' => '%' . $new_key . '%'],
                            ],
                        ])
                        ->first();
                    if (empty($subscription_md)) {
                        $status = 'MD';
                    }
                }

                if (!in_array($new_key, $existing_keys)) {
                    $other_treatments_user[] = [
                        'type' => $record['CatCourses']['title'], // Ajustar título
                        'training_id' => 0,
                        'data_training_id' => 0,
                        'data_course_id' => $data_course_id,
                        'title' => $record['SysTreatmentOT']['name'], // Ajustar nombre
                        'find' => 'OTHER TREATMENTS',
                        'name_key' => $new_key,
                        'agreement_msl' => null,
                        'agreement_md' => null,
                        'status' => $status // Ajustar status
                    ];
  
                    // Actualizar lista de keys para evitar duplicados internos en la nueva lista
                    $existing_keys[] = $new_key;
                }
            }

            // -----------------------------------------------------------------------

            return $other_treatments_user;

        } catch (Exception $e) {
            return [];
        }
    }
    
    public function check_training_medical($user_id){
        return NeuroLevel3AccessHelper::userHasNeuroLevel3Access((int) $user_id);
    }

    public function get_agreements_patient(
        $user_id
    ){
        $this->loadModel('SpaLiveV1.DataAgreements');
        $ent_agreement = $this->DataAgreements->find()
            ->select([
                'CatAgreements.uid',
                'DataAgreements.agreement_uid',
                'DataAgreements.created',
                'DataAgreements.deleted',
                'CatAgreements.user_type',
                'CatAgreements.agreement_type',
                'patient_uid' => 'Patient.uid',
            ])
            ->join([
                'CatAgreements' => ['table' => 'cat_agreements', 'type' => 'INNER', 'conditions' => 'CatAgreements.uid = DataAgreements.agreement_uid'],
                'Patient'       => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Patient.id = DataAgreements.user_id'],
            ])
            ->where([
                'CatAgreements.user_type'           => 'PATIENT',
                'CatAgreements.agreement_type IN'   => ['REGISTRATION', 'IVTHERAPHY', 'FILLERS'],
                'DataAgreements.user_id'            => $user_id, 
                'DataAgreements.deleted'            => 0,
                'CatAgreements.deleted'             => 0,
            ])
            ->all();
        
        $api_key     = env('API_KEY', '12345678901234567890123456789012');
        $url_api = env('URL_API', false);
        $token   = get('token', '');
        $action  = 'print_agreement';
    
        
        // https://api-dev.myspalive.com/?
        //    key=225sadfgasd123fgkhijjdsadfg16578g12gg3gh
        //    &action=print_agreement
        //    &l3n4p=6092482f7ce858.91169218
        //    &uid=123Sdfadfasdf-xcbv34sadf-g83jgihs5
        //    &patient_uid=c8faa060-c7ec-49af-95d0-49cdf101baa6

        if(!empty($ent_agreement)){
            $array_agreements= array();
            foreach ($ent_agreement as $key => $row) {                

                $uid = $row['CatAgreements']['uid'];
                $patient_uid = $row['patient_uid'];
                $url = $url_api . "?key={$api_key}&action={$action}&l3n4p=6092482f7ce858.91169218&uid={$uid}&patient_uid={$patient_uid}";

                $type = $row['CatAgreements']['agreement_type'];
                $fixed_types = array(
                    'REGISTRATION' => 'NEUROTOXINS',
                    'IVTHERAPHY'   => 'IV THERAPY',
                    'FILLERS'      => 'FILLERS',
                );

                $array_agreements[] = array(                    
                    'type'  => $fixed_types[$type],
                    'url'   => $url,
                );      
            }
            return $array_agreements;
        }

        return array();
    }

    public function treatment_requirements($treatment_categories, $agreements, $certificates, $patient_state = 43, $user_id){
        $list_agreements = [];
        $list_gfe        = [];

        // VALIDATE AGREEMENT 
        
        $map_requirements_categories = array();
        $dictionary_categories = [];
        foreach($treatment_categories as $category){

            if($category == 'NEUROTOXINS' || $category == 'FILLERS' || $category == 'IV THERAPY'){
                $has_gfe       = true;
                $has_agreement = true;
                $dictionary_categories[$category] = $category;

                $aggrement_service = $this->get_agreement_service($agreements, $category);
                $agreement_title   = $this->agreement_title($category);
                if(!$aggrement_service){
                    $has_agreement = false;
                    switch ($category) {
                        case 'NEUROTOXINS':
                            $desc = "Your patient hasn't signed the Neurotoxin patient consent.";
                            break;
                        case 'IV THERAPY':
                            $desc = "Your patient hasn't signed the IV patient consent.";
                            break;
                        case 'FILLERS':
                            $desc = "Your patient hasn't signed the Fillers patient consent.";
                            break;
                        default:
                            $desc = "";
                            break;
                    }
                    $list_agreements[] = array(
                        'title'           => $agreement_title,
                        'status'          => 'PENDING', // PENDING, COMPLETED
                        'url_agreement'   => $aggrement_service ? $aggrement_service['url_agreement'] : '',
                        'type'            => $category,
                        'description'     => $desc,
                    );
                }else{
                    $list_agreements[] = array(
                        'title'           => $agreement_title, 
                        'status'          => 'COMPLETED', // PENDING, COMPLETED
                        'url_agreement'   => $aggrement_service ? $aggrement_service['url'] : '',
                        'type'            => $category,
                        'description'     => "",
                    );
                }
                
                if($category == 'IV THERAPY' && $patient_state != 10){
                    $has_gfe = true; // IV THERAPY DOESNT HAVE GFE SO ALWAYS TRUE :)
                }else{
                    $gfe_service = $this->get_certificate_service($certificates, $category);
                    $title       = $this->ceretificate_title($category);
                    if(!$gfe_service){
                        $has_gfe = false;
                        if (strpos($title, 'Neurotoxin') !== false) {
                            $description = "Your patient hasn't received a GFE for Neurotoxin.";
                        } else {
                            $description = "Your patient hasn't received a GFE for Fillers.";
                        }
                        $list_gfe[] = array(
                            'title'           => $title,
                            'description'     => $description,
                            'status'          => 'PENDING', 
                            'url_certificate' => '',
                            'url_details'     => '',
                        );
                    }else{                
                        $gfe_status     = $gfe_service['status'];    
                        $has_gfe        = $gfe_status == 'CERTIFICATE';
                        $description    = '';
                        if($gfe_status         == 'DENIED'){
                            $description = 'The exam has been denied, please cancel this treatment.';
                        } else if ($gfe_status == 'WAITING') {
                            $description = 'Please wait give the examiner a few minutes to complete the exam.';
                        } else if ($gfe_status == 'EXPIRED') {
                            $description = 'The certificate has expired. The patient requires a new GFE.';
                        }
                        $list_gfe[] = array(
                            'title'           => $title,
                            'description'     => $description,
                            'status'          => $gfe_status, // WAITING, EXPIRED, DENIED, APPROVED
                            'url_certificate' => $gfe_service['url_certificate'],
                            'url_details'     => $gfe_service['url_details'],
                        );
                    }
                }

                $map_requirements_categories[$category] = array(
                    'has_gfe'        => $has_gfe,
                    'has_agreement'  => $has_agreement,
                );
            }else{
                $has_gfe = true;
                $has_agreement = true;
                $this->loadModel('SpaLiveV1.SysTreatmentsOt');
                $this->loadModel('SpaLiveV1.CatTreatmentsCi');

                $ent_treatments = $this->CatTreatmentsCi->find()
                ->select([
                    'ST.id',
                    'ST.name',
                    'CT.qualiphy_exam_id',
                    'require_gfe' => 'ST.require_gfe',
                    'name_key' => 'ST.name_key',
                    'agreement_uid' => 'Agreement.uid',
                    'agreement_id' => 'DataAgreement.id',
                    'gfe_id' => 'GFE.id',
                    'gfe_status' => 'GFE.status',
                    'gfe_url' => 'Cert.certificate_url',
                ])
                ->join([
                    'CT' => ['table' => 'cat_treatments', 'type' => 'INNER', 'conditions' => 'CT.id = CatTreatmentsCi.treatment_id'],
                    'ST' => ['table' => 'sys_treatments_ot', 'type' => 'INNER', 'conditions' => 'ST.id = CT.other_treatment_id'],
                    'Agreement' => ['table' => 'cat_agreements', 'type' => 'LEFT', 'conditions' => 'Agreement.other_treatment_id = ST.id AND Agreement.deleted = 0 AND Agreement.agreement_type = "OTHER_TREATMENTS" AND Agreement.user_type = "PATIENT"'],
                    'DataAgreement' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'DataAgreement.agreement_uid = Agreement.uid AND DataAgreement.deleted = 0 AND DataAgreement.user_id = ' . $user_id],
                    'GFE' => ['table' => 'data_consultation', 'type' => 'LEFT', 'conditions' => 'GFE.patient_id = ' . $user_id . ' AND FIND_IN_SET(CT.id, GFE.treatments) > 0 AND GFE.deleted = 0'],
                    'Cert' => ['table' => 'data_certificates', 'type' => 'LEFT', 'conditions' => 'Cert.consultation_id = GFE.id AND Cert.deleted = 0 AND Cert.date_expiration > "' . date('Y-m-d') . '"'],
                ])
                ->where(['CatTreatmentsCi.name' => $category, 'CatTreatmentsCi.deleted' => 0])
                ->first();

                if(empty($ent_treatments)){
                    $has_gfe = false;
                    $has_agreement = false;
                }

                $dictionary_categories[$category] = $ent_treatments->name_key;

                $agreement_title = $category . ' consent form';

                if(!empty($ent_treatments) && !empty($ent_treatments->agreement_id)){
                    $list_agreements[] = array(
                        'title'           => $agreement_title,
                        'title_consent'   => $category,
                        'status'          => 'COMPLETED', // PENDING, COMPLETED
                        'url_agreement'   => $ent_treatments->agreement_uid,
                        'type'            => 'OTHER_TREATMENTS', // Una bandera para saber que tipo de agreement es
                        'agreement_uid'   => $ent_treatments->agreement_uid,
                    );
                }else{
                    $has_agreement = false;
                    $list_agreements[] = array(
                        'title'           => $agreement_title,
                        'title_consent'   => $category,
                        'status'          => 'PENDING', // PENDING, COMPLETED
                        'url_agreement'   => '',
                        'type'            => 'OTHER_TREATMENTS', // Una bandera para saber que tipo de agreement es
                        'agreement_uid'   => !empty($ent_treatments) ? $ent_treatments->agreement_uid : '',
                    );
                }

                $title = $category . ' Certificate';

                if($ent_treatments->require_gfe == 1 && $ent_treatments['CT']['qualiphy_exam_id'] > 0){
                    if(!empty($ent_treatments) && !empty($ent_treatments->gfe_url)){
                        $list_gfe[] = array(
                            'title'           => $title,
                            'description'     => 'You have to complete your Good Faith Exam (GFE) to continue with your treatment.',
                            'status'          => 'CERTIFICATE', 
                            'url_certificate' => $ent_treatments->gfe_url,
                            'url_details'     => $ent_treatments->gfe_url,
                            'type'            => 'OTHER_TREATMENTS',
                            'name_key'        => !empty($ent_treatments) ? $ent_treatments->name_key : '',
                        );
                    }else{                
    
                        $description    = '';
                        $gfe_status     = '';
                        if(!empty($ent_treatments) && $ent_treatments->gfe_status == 'CANCEL'){
                            $description = 'The exam has been denied, please cancel this treatment.';
                            $gfe_status = 'DENIED';
                            $has_gfe = false;
                        } else if (!empty($ent_treatments) && $ent_treatments->gfe_status == 'INIT') {
                            $description = 'Please wait give the examiner a few minutes to complete the exam.';
                            $gfe_status = 'WAITING';
                            $has_gfe = false;
                        }else{
                            $description = 'You have to complete your Good Faith Exam (GFE) to continue with your treatment.';
                            $gfe_status = 'PENDING';
                            $has_gfe = false;
                        }
    
                        $list_gfe[] = array(
                            'title'           => $title,
                            'description'     => $description,
                            'status'          => $gfe_status, // WAITING, EXPIRED, DENIED, APPROVED
                            'url_certificate' => '',
                            'url_details'     => '',
                            'type'            => 'OTHER_TREATMENTS',
                            'name_key'        => !empty($ent_treatments) ? $ent_treatments->name_key : '',
                        );
                    }
                }

                $map_requirements_categories[$category] = array(
                    'has_gfe'        => $has_gfe,
                    'has_agreement'  => $has_agreement,
                );
            }
        }

        $allow_to_complete = true;
        foreach($map_requirements_categories as $category => $requirements){
            $allow_to_complete = $allow_to_complete && $requirements['has_gfe'] && $requirements['has_agreement'];
        }           
        
        // CHECK INJECTOR SUBSCRIPTION

        $ServicesHelper = new ServicesHelper(USER_ID);
        
        // $status_arr = [];
        foreach ($treatment_categories as $category) {
            $status = $ServicesHelper->service_status(
                $dictionary_categories[$category],                
            );
            // $status_arr[$category] = $status;
            $allow_to_complete = ($allow_to_complete && ($status == 'DONE'));               
        }

        $allow_to_cancel   = true;

        return array(
            'agreements'        => $list_agreements,
            'examns'            => $list_gfe,
            // 'status'            => $status_arr,
            'allow_to_cancel'   => $allow_to_cancel,
            'allow_to_complete' => $allow_to_complete,
        );
    }

    public function treatment_requirements_patients($treatment_categories,$agreements,$certificates,$user_id){
        $list_agreements = [];
        $list_gfe        = [];
        // VALIDATE AGREEMENT 
        
        $map_requirements_categories = array();
        foreach($treatment_categories as $category){

            if($category == 'NEUROTOXINS' || $category == 'FILLERS' || $category == 'IV THERAPY'){
                $has_gfe       = true;
                $has_agreement = true;

                $aggrement_service = $this->get_agreement_service($agreements, $category);
                $agreement_title   = $this->agreement_title($category);
                if(!$aggrement_service){
                    $has_agreement = false;
                    $list_agreements[] = array(
                        'title'           => $agreement_title,
                        'title_consent'   => $category,
                        'status'          => 'PENDING', // PENDING, COMPLETED
                        'url_agreement'   => $aggrement_service ? $aggrement_service['url_agreement'] : '',
                        'type'            => $category, // Una bandera para saber que tipo de agreement es
                        'agreement_uid'   => '',
                    );
                }else{
                    $list_agreements[] = array(
                        'title'           => $agreement_title, 
                        'title_consent'   => $category,
                        'status'          => 'COMPLETED', // PENDING, COMPLETED
                        'url_agreement'   => $aggrement_service ? $aggrement_service['url'] : '',
                        'type'            => $category, // Una bandera para saber que tipo de agreement es
                        'agreement_uid'   => '',
                    );
                }
                
                if($category == 'IV THERAPY' && USER_STATE != 10){
                    $has_gfe = true; // IV THERAPY DOESNT HAVE GFE SO ALWAYS TRUE :)
                }else{
                    $gfe_service = $this->get_certificate_service($certificates, $category);
                    $title       = $this->ceretificate_title($category);
                    if(!$gfe_service){
                        $has_gfe = false;
                        $list_gfe[] = array(
                            'title'           => $title,
                            'description'     => 'You have to complete your Good Faith Exam (GFE) to continue with your treatment.',
                            'status'          => 'PENDING', 
                            'url_certificate' => '',
                            'url_details'     => '',
                            'type'            => $category,
                            'name_key'        => '',
                        );
                    }else{                
                        $gfe_status     = $gfe_service['status'];    
                        $has_gfe        = $gfe_status == 'CERTIFICATE';
                        $description    = '';
                        if($gfe_status         == 'DENIED'){
                            $description = 'The exam has been denied, please cancel this treatment.';
                        } else if ($gfe_status == 'WAITING') {
                            $description = 'Please wait give the examiner a few minutes to complete the exam.';                    
                        } else if ($gfe_status == 'EXPIRED') {
                            $description = 'The certificate has expired. The patient requires a new GFE.';                    
                        }
                        $list_gfe[] = array(
                            'title'           => $title,
                            'description'     => $description,
                            'status'          => $gfe_status, // WAITING, EXPIRED, DENIED, APPROVED
                            'url_certificate' => $gfe_service['url_certificate'],
                            'url_details'     => $gfe_service['url_details'],
                            'type'            => $category, 
                            'name_key'        => '',
                        );
                    }
                }

                $map_requirements_categories[$category] = array(
                    'has_gfe'        => $has_gfe,
                    'has_agreement'  => $has_agreement,
                );
            }else{
                $has_gfe = true;
                $has_agreement = true;

                $this->loadModel('SpaLiveV1.CatTreatmentsCi');
                $ent_treatments = $this->CatTreatmentsCi->find()
                ->select([
                    'ST.id',
                    'ST.name',
                    'CT.qualiphy_exam_id',
                    'require_gfe' => 'ST.require_gfe',
                    'name_key' => 'ST.name_key',
                    'agreement_uid' => 'Agreement.uid',
                    'agreement_id' => 'DataAgreement.id',
                    'gfe_id' => 'GFE.id',
                    'gfe_status' => 'GFE.status',
                    'gfe_url' => 'Cert.certificate_url',
                ])
                ->join([
                    'CT' => ['table' => 'cat_treatments', 'type' => 'INNER', 'conditions' => 'CT.id = CatTreatmentsCi.treatment_id'],
                    'ST' => ['table' => 'sys_treatments_ot', 'type' => 'INNER', 'conditions' => 'ST.id = CT.other_treatment_id'],
                    'Agreement' => ['table' => 'cat_agreements', 'type' => 'LEFT', 'conditions' => 'Agreement.other_treatment_id = ST.id AND Agreement.deleted = 0 AND Agreement.agreement_type = "OTHER_TREATMENTS" AND Agreement.user_type = "PATIENT"'],
                    'DataAgreement' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'DataAgreement.agreement_uid = Agreement.uid AND DataAgreement.deleted = 0 AND DataAgreement.user_id = ' . $user_id],
                    'GFE' => ['table' => 'data_consultation', 'type' => 'LEFT', 'conditions' => 'GFE.patient_id = ' . $user_id . ' AND FIND_IN_SET(CT.id, GFE.treatments) > 0 AND GFE.deleted = 0'],
                    'Cert' => ['table' => 'data_certificates', 'type' => 'LEFT', 'conditions' => 'Cert.consultation_id = GFE.id AND Cert.deleted = 0 AND Cert.date_expiration > "' . date('Y-m-d') . '"'],
                ])
                ->where(['CatTreatmentsCi.name' => $category, 'CatTreatmentsCi.deleted' => 0])
                ->first();

                if(empty($ent_treatments)){
                    $has_gfe = false;
                    $has_agreement = false;
                }

                $agreement_title = $category . ' consent form';

                if(!empty($ent_treatments) && !empty($ent_treatments->agreement_id)){
                    $list_agreements[] = array(
                        'title'           => $agreement_title,
                        'title_consent'   => $category,
                        'status'          => 'COMPLETED', // PENDING, COMPLETED
                        'url_agreement'   => $ent_treatments->agreement_uid,
                        'type'            => 'OTHER_TREATMENTS', // Una bandera para saber que tipo de agreement es
                        'agreement_uid'   => $ent_treatments->agreement_uid,
                    );
                }else{
                    $list_agreements[] = array(
                        'title'           => $agreement_title,
                        'title_consent'   => $category,
                        'status'          => 'PENDING', // PENDING, COMPLETED
                        'url_agreement'   => '',
                        'type'            => 'OTHER_TREATMENTS', // Una bandera para saber que tipo de agreement es
                        'agreement_uid'   => !empty($ent_treatments) ? $ent_treatments->agreement_uid : '',
                    );
                }

                $title = $category . ' Certificate';

                if($ent_treatments->require_gfe == 1 && $ent_treatments['CT']['qualiphy_exam_id'] > 0){
                    if(!empty($ent_treatments) && !empty($ent_treatments->gfe_url)){
                        $list_gfe[] = array(
                            'title'           => $title,
                            'description'     => 'You have to complete your Good Faith Exam (GFE) to continue with your treatment.',
                            'status'          => 'CERTIFICATE', 
                            'url_certificate' => $ent_treatments->gfe_url,
                            'url_details'     => $ent_treatments->gfe_url,
                            'type'            => 'OTHER_TREATMENTS',
                            'name_key'        => !empty($ent_treatments) ? $ent_treatments->name_key : '',
                        );
                    }else{                
    
                        $description    = '';
                        $gfe_status     = '';
                        if(!empty($ent_treatments) && $ent_treatments->gfe_status == 'CANCEL'){
                            $description = 'The exam has been denied, please cancel this treatment.';
                            $gfe_status = 'DENIED';
                            $has_gfe = false;
                        } else if (!empty($ent_treatments) && $ent_treatments->gfe_status == 'INIT') {
                            $description = 'Please wait give the examiner a few minutes to complete the exam.';
                            $gfe_status = 'WAITING';
                            $has_gfe = true;
                        }else{
                            $description = 'You have to complete your Good Faith Exam (GFE) to continue with your treatment.';
                            $gfe_status = 'PENDING';
                            $has_gfe = false;
                        }
    
                        $list_gfe[] = array(
                            'title'           => $title,
                            'description'     => $description,
                            'status'          => $gfe_status, // WAITING, EXPIRED, DENIED, APPROVED
                            'url_certificate' => '',
                            'url_details'     => '',
                            'type'            => 'OTHER_TREATMENTS',
                            'name_key'        => !empty($ent_treatments) ? $ent_treatments->name_key : '',
                        );
                    }
                }

                $map_requirements_categories[$category] = array(
                    'has_gfe'        => $has_gfe,
                    'has_agreement'  => $has_agreement,
                );
            }
        }

        $allow_to_complete = true;
        foreach($map_requirements_categories as $category => $requirements){
            $allow_to_complete = $allow_to_complete && $requirements['has_gfe'] && $requirements['has_agreement'];
        }           

        return array(
            'agreements'        => $list_agreements,
            'examns'            => $list_gfe,
        );
    }
    
    public function agreement_title(
        $service
    ){
        switch($service){
            case 'NEUROTOXINS':
                return 'Neurotoxin consent form';
            case 'IV THERAPY':
                return 'IV consent form';
            case 'FILLERS':
                return 'Fillers consent form';
            default:
                return ucfirst(strtolower($service)) . ' consent form';
        }
    }

    public function ceretificate_title(
        $service
    ){
        switch($service){
            case 'NEUROTOXINS':
                return 'Neurotoxin Certificate';
            case 'IV THERAPY':
                return 'IV Certificate';
            case 'FILLERS':
                return 'Filler Certificate';
            default:
                return ucfirst(strtolower($service)) . ' Certificate';
        }
    }

    public function get_agreement_service(
        $agreements,
        $service
    ){
        foreach($agreements as $agreement){
            if($agreement['type'] == $service){
                return $agreement;
            }
        }
        return false;
    }

    public function get_certificate_service(
        $certificates,
        $service
    ){
        
        foreach($certificates as $certificate){
            if(in_array($service, $certificate['types']) ){
                if($certificate['status'] == 'CERTIFICATE'){ // TO ENSURE THAT THE CURRENT CERTIFICATE IS NOT EXPIRED OR DENIED
                    return $certificate; 
                }                
            }
        }

        foreach($certificates as $certificate){
            if(in_array($service, $certificate['types']) ){       
                return $certificate; // RETURN ANY CERTIFICATE THAT MATCHES THE SERVICE
            }
        }

        return false;
    }

    public function referral_injector(){
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

        if (USER_TYPE != "school") {
            $this->message('Invalid user.');
            return;
        }

        $str_email = get('email','');
        if(empty($str_email)) {
            $this->message('Empty email.');
            return;
        }

        if (!filter_var($str_email, FILTER_VALIDATE_EMAIL)) {
            $this->message('Invalid email format.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataSchoolInvitations');
        $this->loadModel('SpaLiveV1.SysUsers');

        $existUser = $this->DataSchoolInvitations->find()->where(['DataSchoolInvitations.email LIKE' => strtolower($str_email)])->first();

        if(!empty($existUser)){
            $this->message('This email has already been invited.');
            return;
        }

        $existUser2 = $this->SysUsers->find()->where(['SysUsers.email LIKE' => strtolower($str_email), 'SysUsers.deleted' => 0])->first();

        if(!empty($existUser2)){
            $this->message('This email has already been registered.');
            return;
        }


        $this->loadModel("SpaLiveV1.CatNotifications");
        $ent_notification = $this->CatNotifications->find()->where(['CatNotifications.title' => 'CI_TO_CI_INVITE'])->first();
        if (!empty($ent_notification)) {

            $msg_mail = $ent_notification['body'];

            $e_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();
 
            $constants = [
                '[CNT/CIName]' => $e_user['name'],
                '[CNT/CILastName]' => $e_user['lname'],
            ];
            foreach($constants as $key => $value){
                $msg_mail = str_replace($key, $value, $msg_mail);
            }


            $html_content = '<img src="' . $this->URL_PANEL . 'img/logo.png" width="100px"/>' . $msg_mail;

            $data=array(
                'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
                'to'      => $str_email,
                'subject' => 'New message from MySpaLive',
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
        }

        $array_save = array(
            'email' => $str_email,
            'parent_id' => USER_ID,
        );

        $c_entity = $this->DataSchoolInvitations->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            $this->DataSchoolInvitations->save($c_entity);
        }

        $this->success();
    }

    public function get_patients(){
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

        $patients = $this->find_patients(true, true);

        if(count($patients) == 0) {
            $patients[] = array(
                'name' => USER_NAME . ' ' . USER_LNAME,
                'register_pending ' => false,
                'short_uid' => USER_UID,
                'uid' => USER_UID,
            );
        }

        $this->set('patients', $patients);
        $this->success();
    }

    public function summary_like_uber($summary = false){

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

        $cc = new CourseController();                        
        $trainings_user = $cc->get_courses_user(USER_ID);
        $has_basic_course = $trainings_user['has_basic_course'];
        $has_advanced_course = $trainings_user['has_advanced_course'];
        $has_level3_course = $trainings_user['has_level3_course'];
        $this->loadModel('SpaLiveV1.SysUsers');
        $subs = $this->check_subs(USER_ID);
        // AVAILABLE APPOINMENTS

        $now = date('Y-m-d H:i:s');
        $fields2 = ['DataTreatment.id','DataTreatment.uid','DataTreatment.schedule_date','DataTreatment.status','DataTreatment.address','DataTreatment.treatments','DataTreatment.state','State.name','DataTreatment.patient_id', 'DataTreatment.assistance_id','DataTreatment.city','DataTreatment.zip','DataTreatment.suite','DataTreatment.latitude','DataTreatment.longitude','Patient.name','Patient.lname','Patient.phone', 'Patient.gender' ,'Patient.uid','DataTreatment.notes','DataTreatment.type_uber','DataTreatment.created','Injector.latitude','Injector.longitude','Injector.radius'/*, 'DCT.created'*/];
        $fields2['treatments_string'] = "(SELECT GROUP_CONCAT(CONCAT(CTC.name,' (',CT.name, ')') SEPARATOR ', ') 
            FROM cat_treatments_ci CT 
            JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
            WHERE FIND_IN_SET(CT.id,DataTreatment.treatments) LIMIT 1)";

        $fields2['treatments_string_claim'] = "(SELECT GROUP_CONCAT(CONCAT(CTC.name,' (',CT.name, ')',' $',TRUNCATE((CT.std_price / 100  ), 0)) SEPARATOR ', ') 
        FROM cat_treatments_ci CT 
        JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
        WHERE FIND_IN_SET(CT.id,DataTreatment.treatments) LIMIT 1)";

        $fields2['type_treatments'] = "(SELECT GROUP_CONCAT(CAT.type) FROM cat_treatments_ci CTC JOIN cat_treatments_category CAT ON CAT.id = CTC.category_treatment_id WHERE FIND_IN_SET(CTC.id, DataTreatment.treatments) LIMIT 1)";

        $fields2['distance_in_mi'] = "(69.09 * DEGREES(ACOS(LEAST(1.0, COS(RADIANS(DataTreatment.latitude))
            * COS(RADIANS(Injector.latitude))
            * COS(RADIANS(DataTreatment.longitude - Injector.longitude))
            + SIN(RADIANS(DataTreatment.latitude))
            * SIN(RADIANS(Injector.latitude))))))";

        $_where2 = ['DataTreatment.deleted' => 0, 'DataTreatment.status In' => array("PETITION"), 'DataTreatment.type_uber' => 1, 'DATE_FORMAT(DataTreatment.schedule_date, "%Y-%m-%d %H:%i:%s") >= "' . $now . '"', 'DATE_ADD(DATE_FORMAT(DataTreatment.created, "%Y-%m-%d %H:%i:%s"),INTERVAL 1 DAY) >= "' . $now . '"'];

        $_having[] = ['distance_in_mi <= Injector.radius'];

        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.CatCITreatments');
        $avappo_ent = $this->DataTreatment->find()->select($fields2)->join([
            'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
            'Patient' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Patient.id = DataTreatment.patient_id AND Patient.deleted = 0'],
            'Injector' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Injector.id = '. USER_ID .' AND Injector.deleted = 0'],
            //'DCT' => ['table' => 'data_claim_treatments', 'type' => 'LEFT', 'conditions' => 'DCT.treatment_uid = DataTreatment.uid AND DCT.deleted = 0'],
        ])->where($_where2)->having($_having)->order(['DataTreatment.schedule_date' => 'DESC'])->all();
        $this->loadModel('SpaLiveV1.DataClaimTreatments');
        $this->loadModel('SpaLiveV1.DataTreatmentsPrice');
        $available_treatments = array();

        $arr_appotments1 = [];

        foreach($avappo_ent as $index => $certTreatment){

            $ent_treatments = $this->CatCITreatments->find()->select(['CatCITreatments.id','CatCITreatments.name','CatCITreatments.details', 'CatCITreatments.std_price', 'Cat.name', 'CatCITreatments.category_treatment_id'])
                ->join([
                    'Cat' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'Cat.id = CatCITreatments.category_treatment_id'],
                ])
            ->where(['CatCITreatments.id IN' => explode(',', $certTreatment->treatments)]);
                
            $array_list = array();
            $iv_list = array();
            $neuro_list = array();
            $fillers_list = array();
            $other_list = array();
            $other_list_id = array();
            foreach($ent_treatments as $_row){
                if($_row->name == 'Let my provider choose' || $_row->name == 'Let my provider help me decide' || $_row->name == 'No preference'){
                    if($_row->id == 999){$_row['Cat']['name'] = 'Neurotoxins'; $_row->category_treatment_id = 1;}
                    if($_row->id == 1033){$fillers_list[] = 'Fillers'; continue;}
                    $ent_treatments2 = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.std_price', 'CatCITreatments.name', 'CatCITreatments.category_treatment_id'])->join([
                        'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id AND CatCITreatments.name <> CTC.name'],
                    ])->where(['CatCITreatments.deleted' => 0, 'CatCITreatments.name NOT IN' => array('Let my provider help me decide', 'Let my provider choose', 'No preference') ,'CatCITreatments.category_treatment_id' => $_row->category_treatment_id])->all();
                    $array_prices = array();
                    foreach ($ent_treatments2 as $key => $trea) {
                        $array_prices[] = $trea['name'];
                    }
                    $neuro_list[] = $_row['Cat']['name'];
                }else{
                    if($_row['Cat']['name'] == 'IV Therapy' || $_row['Cat']['name'] == 'IV'){
                        $iv_list[] = $_row->name;
                    }else if($_row['Cat']['name'] == 'Fillers'){
                        $fillers_list[] = $_row['Cat']['name'];
                    }else if($_row['Cat']['name'] == 'Other Treatments'){
                        $other_list[] = $_row->name;
                        $other_list_id[] = $_row->id;
                    }else{
                        $neuro_list[] = $_row->name == $_row['Cat']['name'] ? $_row->name : $_row['Cat']['name'];
                    }
                }
            }

            $string_iv = implode(', ', $iv_list);
            $string_neuro = implode(', ', $neuro_list);
            $string_filler = implode(', ', $fillers_list);
            $string_other = implode(', ', $other_list);

            $array_treatments = array();

            if(!empty($string_neuro)){
                $array_treatments['neurotoxins'] = $string_neuro;
            }

            if(!empty($string_iv)){
                $array_treatments['iv'] = $string_iv;
            }else{
                $array_treat = explode(',', $certTreatment->treatments);
            
                foreach($array_treat as $key => $value){
                    if($value == 0){
                        $string_iv = 'No preference';
                        $array_treatments['iv'] = $string_iv;
                    }
                }
            }

            if(!empty($string_filler)){
                $array_treatments['fillers'] = $string_filler;
            }

            if(!empty($string_other)){
                $array_treatments['other'] = $string_other;
            }

            $string_treatments = '';
            $array_string_treatments = array();
            $array_prices = array();

            if(isset($array_treatments['neurotoxins'])){
                if(!$subs['neuro']){
                    continue;
                }

                if(!$has_advanced_course || !$has_level3_course){
                    continue;
                }

                //$string_treatments = 'Neurotoxins';
                $array_string_treatments[] = $string_neuro;

                $ent_neuro_price = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.std_price', 'CatCITreatments.name', 'CatCITreatments.details', 'CatCITreatments.category_treatment_id'])->join([
                ])->where(['CatCITreatments.deleted' => 0, 'CatCITreatments.id IN' => array(1,2,4,73,74,75,76)])->all();

                $neuro_price = array();

                foreach ($ent_neuro_price as $key => $price) {
                    if($price['category_treatment_id'] == 1){
                        $neuro_price[] = $price['name'] .'(basic treatments): $' . ($price['std_price'] / 100) . ' ' . $price['details'];

                    }else{
                        $neuro_price[] = $price['name'] .': $' . ($price['std_price'] / 100) . ' ' . $price['details'];
                    }
                }

                $array_prices[] = array('category' => 'Neurotoxins', 'prices' => $neuro_price);
            }

            if(isset($array_treatments['fillers'])){
                if(!$subs['fillers']){
                    continue;
                }

                $array_string_treatments[] = $string_filler;

                $ent_filler_price = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.std_price', 'CatCITreatments.name', 'CatCITreatments.details', 'CatCITreatments.category_treatment_id', 'Price.price'])
                ->join([
                    'Price' => ['table' => 'data_treatments_prices', 'type' => 'INNER', 'conditions' => 'Price.treatment_id = CatCITreatments.id'],
                    'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id'],
                ])->where(['CatCITreatments.deleted' => 0, 'Price.user_id' => USER_ID, 'Price.deleted' => 0, 'CTC.type_uber' => 'FILLERS'])->all();

                $filler_price = array();

                foreach ($ent_filler_price as $key => $price) {
                    $filler_price[] = $price['name'] . ': $' . ($price['Price']['price'] / 100) . ' ' . $price['details'];    
                }

                $array_prices[] = array('category' => 'Fillers', 'prices' => $filler_price);
            } 
            
            if(isset($array_treatments['iv'])){
                if(!$subs['iv']){
                    continue;
                }

                $join = [];
                if($array_treatments['iv'][0] == 0){
                    $join = ['CTC' => ['table' => 'cat_treatments_ci', 'type' => 'INNER', 'conditions' => 'CTC.id = DataTreatmentsPrice.treatment_id']];
                    $_where = ['DataTreatmentsPrice.user_id' => USER_ID, 'DataTreatmentsPrice.deleted' => 0, 'CTC.category_treatment_id' => 1001, 'CTC.deleted' => 0];

                    $treatment_prices = $this->DataTreatmentsPrice->find()
                    ->join($join)
                    ->where($_where)->all();

                    if(count($treatment_prices) == 0){
                        continue;
                    }
                }else{
                    $_where = ['user_id' => USER_ID, 'deleted' => 0, 'treatment_id IN' => $array_treatments['iv']];

                    $treatment_prices = $this->DataTreatmentsPrice->find()
                    ->join($join)
                    ->where($_where)->count();

                    if($treatment_prices < count($array_treatments['iv'])){
                        continue;
                    }
                }

                //$string_treatments = 'IV';
                $array_string_treatments[] = 'IV(' . $string_iv . ')';

                $ent_iv_price = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.std_price', 'CatCITreatments.name', 'CatCITreatments.details', 'CatCITreatments.category_treatment_id', 'Price.price'])
                ->join([
                    'Price' => ['table' => 'data_treatments_prices', 'type' => 'INNER', 'conditions' => 'Price.treatment_id = CatCITreatments.id'],
                    'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id'],
                ])->where(['CatCITreatments.deleted' => 0, 'Price.user_id' => USER_ID, 'Price.deleted' => 0, 'CTC.type_uber' => 'IV THERAPY'])->all();

                $iv_price = array();

                foreach ($ent_iv_price as $key => $price) {
                    $iv_price[] = $price['name'] . ': $' . ($price['Price']['price'] / 100) . ' ' . $price['details'];    
                }

                $array_prices[] = array('category' => 'IV', 'prices' => $iv_price);
            }

            if(isset($array_treatments['other'])){
                foreach($other_list_id as $treatment_id){

                    $ent_treatments_ci = $this->CatCITreatments->find()
                    ->select(['name_key' => 'ST.name_key', 'name' => 'CatCITreatments.name', 'category' => 'ST.name', 'details' => 'CatCITreatments.details'])
                    ->join([
                        'CT' => ['table' => 'cat_treatments', 'type' => 'INNER', 'conditions' => 'CT.id = CatCITreatments.treatment_id'],
                        'ST' => ['table' => 'sys_treatments_ot', 'type' => 'INNER', 'conditions' => 'ST.id = CT.other_treatment_id'],
                    ])
                    ->where(['CatCITreatments.deleted' => 0, 'CatCITreatments.id' => $treatment_id])
                    ->first();
                    
                    if(empty($ent_treatments_ci)){
                        continue 2;
                    }

                    $service_name = $ent_treatments_ci->name_key;
                    
                    // Verificar suscripción automáticamente
                    $where = ['user_id' => USER_ID, 'status' => 'ACTIVE', 'deleted' => 0, 'subscription_type LIKE' => '%MSL%'];
                    $where['OR'] = [['main_service' => $service_name], ['addons_services LIKE' => '%' . $service_name . '%']];
                    
                    $ent_sub = $this->DataSubscriptions->find()->where($where)->first();

                    if(empty($ent_sub)){
                        continue 2; // Salir del bucle interno
                    }

                    // Verificar precios del tratamiento automáticamente
                    $_where = ['user_id' => USER_ID, 'deleted' => 0, 'treatment_id IN' => $treatment_id];

                    $treatment_prices = $this->DataTreatmentsPrice->find()
                    ->where($_where)->first();

                    if(empty($treatment_prices)){
                        continue 2;
                    }

                    $array_prices[] = array('category' => $ent_treatments_ci->category, 'prices' => [$ent_treatments_ci->name . ': $' . ($treatment_prices->price / 100) . ' ' . $ent_treatments_ci->details]);
                }

                $array_string_treatments[] = $string_other;
            }
            
            $string_treatments = implode(', ', $array_string_treatments);

            $is_dev = env('IS_DEV', false);
            
            if($now > date('Y-m-d H:i:s', strtotime($certTreatment['created']->i18nFormat('yyyy-MM-dd HH:mm:ss') . ' + 2 day'))){
                continue;
            }

            $this->loadModel('SpaLiveV1.SysUsers');

            $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $certTreatment->patient_id])->first();
            
            $sstr_address = $certTreatment->city . ', ' . $certTreatment['State']['name'] . ' ' . $certTreatment->zip;
            if (!empty($certTreatment->suite)) {
                $sstr_address = $certTreatment->city . ', ' . $certTreatment['State']['name'] . ' ' . $certTreatment->zip;
            }
            
            $count_injectors = 0;

            $injector_message = ''; 

            if($certTreatment->status == 'PETITION'){
                $claim = '';
            }else if($certTreatment->status == 'CONFIRM'){
                if($certTreatment->assistance_id == USER_ID){
                    $claim = 'This treatment has already been claimed by you. You can see It in your Requested Appointments section';

                }else{
                    $claim = 'Claimed by another Certified Injector';
                }
            }

            $re_array = array(
                'uid' => $certTreatment->uid,
                'latitude' => doubleval($certTreatment->latitude),
                'longitude' => doubleval($certTreatment->longitude),
                'patient_uid' => $certTreatment['Patient']['uid'],
                'schedule_date' => $certTreatment->schedule_date,
                'status' => $certTreatment->status,
                'distance_in_mi' => $certTreatment->latitude > 0 ? round($certTreatment->distance_in_mi,1) : 0,
                'address' => $sstr_address,
                'patient' => $certTreatment['Patient']['name'] . ' ' . $certTreatment['Patient']['lname'],
                'treatments' => $string_treatments,
                'details' => $array_prices,
                'treatments_detail' => [],
                'count_injectors' => $count_injectors,
                'out_reach' => ($certTreatment->latitude > 0 && round($certTreatment->distance_in_mi,1) > $user['user_radius'] ) ? 1 : 0,
                'sign_patient' => true,
                'notes' => $certTreatment->notes,
                'injector_message' => $injector_message,
                'gender' => $certTreatment['Patient']['gender'],
                'claim' => $claim,
                'myself_claim' => false,
                'message_promo' => 'This is a first time patient and by claiming this appointment you are agreeing to provide a $50 off if the treatment cost is above $200.',
            );
            if($this->get_first_cliam_id($certTreatment->uid) == USER_ID){
                $re_array['show_number'] = true;  
                $re_array['number'] = $certTreatment['Patient']['phone'];
            }
            
            $arr_appotments1[] = $re_array;
        }

        
        $arr_appotments2 = [];

        // Segunda parte

        $_where2 = ['DataTreatment.deleted' => 0, 'DataTreatment.status In' => array("CONFIRM"), 'DataTreatment.type_uber' => 1, 'DATE_FORMAT(DataTreatment.schedule_date, "%Y-%m-%d %H:%i:%s") >= "' . $now . '"', 'DATE_ADD(DATE_FORMAT(DataTreatment.created, "%Y-%m-%d %H:%i:%s"),INTERVAL 1 DAY) >= "' . $now . '"'];

        $avappo_ent = $this->DataTreatment->find()->select($fields2)->join([
            'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
            'Patient' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Patient.id = DataTreatment.patient_id AND Patient.deleted = 0'],
            'Injector' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Injector.id = '. USER_ID .' AND Injector.deleted = 0'],
            //'DCT' => ['table' => 'data_claim_treatments', 'type' => 'LEFT', 'conditions' => 'DCT.treatment_uid = DataTreatment.uid AND DCT.deleted = 0'],
        ])->where($_where2)->having($_having)->order(['DataTreatment.schedule_date' => 'DESC'])->all();
        
        $this->loadModel('SpaLiveV1.DataClaimTreatments');
        $available_treatments = array();
        foreach($avappo_ent as $index => $certTreatment){
            $array_treat = explode(',', $certTreatment->treatments);
            $array_neuro = array();
            $array_iv = array();
            $array_treatments = array();
            foreach($array_treat as $key => $value){
                if($value == 999){
                    $array_neuro[] = $value;
                } else if($value == 0){
                    $array_iv[] = $value;
                } else {
                    //consulta para saber de que tratamiento se trata
                    // de momento todo lo demas que no sea 999 o 0 es IV
                    $array_iv[] = $value;
                }
            }
    
            if(!empty($array_neuro)){
                $array_treatments['neurotoxins'] = $array_neuro;
            }
    
            if(!empty($array_iv)){
                $array_treatments['iv'] = $array_iv;
            }

            if(isset($array_treatments['neurotoxins']) && isset($array_treatments['iv'])){
                if(!$subs['neuro']){
                    continue;
                }

                if(!$has_advanced_course || !$has_level3_course){
                    continue;
                }   

                if(!$subs['iv']){
                    continue;
                }

                $available_treatments[] = $certTreatment;

            }else{
                if(isset($array_treatments['neurotoxins'])){
                    if($subs['neuro']){
                        if($has_advanced_course && $has_level3_course){
                            $available_treatments[] = $certTreatment;
                        }   
                    }
                }else if(isset($array_treatments['iv'])){
                    if($subs['iv']){
                        $available_treatments[] = $certTreatment;
                    }
                }
            }
        }

        foreach($available_treatments as $index => $certTreatment){
            $is_dev = env('IS_DEV', false);
            
            if($now > date('Y-m-d H:i:s', strtotime($certTreatment['created']->i18nFormat('yyyy-MM-dd HH:mm:ss') . ' + 2 day'))){
                continue;
            }

            /*if(!empty($certTreatment['DCT']['created']) ){
                if($now > date('Y-m-d H:i:s', strtotime($certTreatment['DCT']['created'] . ' + 1 day'))){
                    continue;
                }
            }*/

            /*$ent_treatments = $this->CatCITreatments->find()->select(['CatCITreatments.id','CatCITreatments.name','CatCITreatments.details', 'CatCITreatments.std_price', 'Cat.name', 'CatCITreatments.category_treatment_id'])
            ->join([
                'Cat' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'Cat.id = CatCITreatments.category_treatment_id'],
            ])
            ->where(['CatCITreatments.id IN' => explode(',', $certTreatment->treatments)]);
            
            $array_list = array();
            foreach($ent_treatments as $row){
                if($row->name == 'Let my provider choose' || $row->name == 'Let my provider help me decide' || $row->name == 'No preference'){
                    if($row->id == 999){$row['Cat']['name'] = 'Basic Neurotoxins'; $row->category_treatment_id = 1;}
                    $ent_treatments2 = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.std_price', 'CatCITreatments.name', 'CatCITreatments.category_treatment_id'])->join([
                        'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id AND CatCITreatments.name <> CTC.name'],
                    ])->where(['CatCITreatments.deleted' => 0, 'CatCITreatments.name NOT IN' => array('Let my provider help me decide', 'Let my provider choose', 'No preference') ,'CatCITreatments.category_treatment_id' => $row->category_treatment_id])->all();
                    $array_prices = array();
                    foreach ($ent_treatments2 as $key => $trea) {
                        $array_prices[] = $trea['name'] .' $' . ($trea['std_price'] / 100);
                    }
                    $array_list[] = $row['Cat']['name'] . ' (' . implode(', ', $array_prices) . ')';
                }else{
                    $array_list[] = $row->name == $row['Cat']['name'] ? $row->name . ' ($' . $row->std_price/100 . ' ' . $row->details . ')' : $row['Cat']['name'] . ' ('. $row->name .' $' . $row->std_price/100 . ' ' . $row->details . ')';
                }
            }

            $string_treatments = implode(', ', $array_list);*/

            $array_treat = explode(',', $certTreatment->treatments);
            $array_neuro = array();
            $array_iv = array();
            $array_treatments = array();
            foreach($array_treat as $key => $value){
                if($value == 999){
                    $array_neuro[] = $value;
                } else if($value == 0){
                    $array_iv[] = $value;
                } else {
                    //consulta para saber de que tratamiento se trata
                    // de momento todo lo demas que no sea 999 o 0 es IV
                    $array_iv[] = $value;
                }
            }

            $string_treatments = '';
            $array_prices = array();
            if(isset($array_treatments['neurotoxins']) && isset($array_treatments['iv'])){
                $string_treatments = 'Neurotoxins, IV';

                $ent_neuro_price = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.std_price', 'CatCITreatments.name', 'CatCITreatments.details', 'CatCITreatments.category_treatment_id'])
                ->where(['CatCITreatments.deleted' => 0, 'CatCITreatments.id IN' => array(1,2,4,73,74,75,76)])->all();

                $neuro_price = array();

                foreach ($ent_neuro_price as $key => $price) {
                    if($price['category_treatment_id'] == 1){
                        $neuro_price[] = $price['name'] .'(basic treatments): $' . ($price['std_price'] / 100) . ' ' . $price['details'];

                    }else{
                        $neuro_price[] = $price['name'] .': $' . ($price['std_price'] / 100) . ' ' . $price['details'];
                    }
                }

                $ent_iv_price = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.std_price', 'CatCITreatments.name', 'CatCITreatments.details', 'CatCITreatments.category_treatment_id', 'Price.price'])
                ->join([
                    'Price' => ['table' => 'data_treatments_prices', 'type' => 'INNER', 'conditions' => 'Price.treatment_id = CatCITreatments.id'],
                    'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id'],
                ])->where(['CatCITreatments.deleted' => 0, 'Price.user_id' => USER_ID, 'Price.deleted' => 0, 'CTC.type_uber' => 'IV THERAPY'])->all();

                $iv_price = array();

                foreach ($ent_iv_price as $key => $price) {
                    $iv_price[] = $price['name'] . ': $' . ($price['Price']['price'] / 100) . ' ' . $price['details'];
                }

                $array_prices = array(
                    array('category' => 'Neurotoxins', 'prices' => $neuro_price),
                    array('category' => 'IV', 'prices' => $iv_price),
                );
            }else{
                if(isset($array_treatments['neurotoxins'])){
                    $string_treatments = 'Neurotoxins';
                    $ent_neuro_price = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.std_price', 'CatCITreatments.name', 'CatCITreatments.details', 'CatCITreatments.category_treatment_id'])->join([
                    ])->where(['CatCITreatments.deleted' => 0, 'CatCITreatments.id IN' => array(1,2,4,73,74,75,76)])->all();
    
                    $neuro_price = array();
    
                    foreach ($ent_neuro_price as $key => $price) {
                        if($price['category_treatment_id'] == 1){
                            $neuro_price[] = $price['name'] .'(basic treatments): $' . ($price['std_price'] / 100) . ' ' . $price['details'];
    
                        }else{
                            $neuro_price[] = $price['name'] .': $' . ($price['std_price'] / 100) . ' ' . $price['details'];
                        }
                    }

                    $array_prices[] = array('category' => 'Neurotoxins', 'prices' => $neuro_price);
                }else if(isset($array_treatments['iv'])){
                    $string_treatments = 'IV';

                    $ent_iv_price = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.std_price', 'CatCITreatments.name', 'CatCITreatments.details', 'CatCITreatments.category_treatment_id', 'Price.price'])
                    ->join([
                        'Price' => ['table' => 'data_treatments_prices', 'type' => 'INNER', 'conditions' => 'Price.treatment_id = CatCITreatments.id'],
                        'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id'],
                    ])->where(['CatCITreatments.deleted' => 0, 'Price.user_id' => USER_ID, 'Price.deleted' => 0, 'CTC.type_uber' => 'IV THERAPY'])->all();

                    $iv_price = array();

                    foreach ($ent_iv_price as $key => $price) {
                        $iv_price[] = $price['name'] . ': $' . ($price['Price']['price'] / 100) . ' ' . $price['details'];    
                    }

                    $array_prices[] = array('category' => 'IV', 'prices' => $iv_price);
                }
            }
            
            $sstr_address = $certTreatment->city . ', ' . $certTreatment['State']['name'] . ' ' . $certTreatment->zip;
            if (!empty($certTreatment->suite)) {
                $sstr_address = $certTreatment->city . ', ' . $certTreatment['State']['name'] . ' ' . $certTreatment->zip;
            }
            
            $count_injectors = 0;

            if(empty($certTreatment->treatments_string)){
                continue;
            }
            $injector_message = '';

            if($certTreatment->status == 'PETITION'){
                $claim = '';
            }else if($certTreatment->status == 'CONFIRM'){
                if($certTreatment->assistance_id == USER_ID){
                    $claim = 'This treatment has already been claimed by you. You can see It in your Requested Appointments section';
                    $myself_claim = true;
                }else{
                    $claim = 'Claimed by another Certified Injector';
                    $myself_claim = false;
                }
            }

            $re_array = array(
                'uid' => $certTreatment->uid,
                'latitude' => doubleval($certTreatment->latitude),
                'longitude' => doubleval($certTreatment->longitude),
                'patient_uid' => $certTreatment['Patient']['uid'],
                'schedule_date' => $certTreatment->schedule_date,
                'status' => $certTreatment->status,
                'distance_in_mi' => $certTreatment->latitude > 0 ? round($certTreatment->distance_in_mi,1) : 0,
                'address' => $sstr_address,
                'patient' => $certTreatment['Patient']['name'] . ' ' . $certTreatment['Patient']['lname'],
                'title_card' => $string_treatments,
                'treatments' => $string_treatments,
                'treatments_detail' => [],
                'count_injectors' => $count_injectors,
                'out_reach' => ($certTreatment->latitude > 0 && round($certTreatment->distance_in_mi,1) > $user['user_radius'] ) ? 1 : 0,
                'sign_patient' => true,
                'notes' => $certTreatment->notes,
                'injector_message' => $injector_message,
                'gender' => $certTreatment['Patient']['gender'],
                'claim' => $claim,
                'myself_claim' => $myself_claim
            );
            if($this->get_first_cliam_id($certTreatment->uid) == USER_ID){
                $re_array['show_number'] = true;  
                $re_array['number'] = $certTreatment['Patient']['phone'];
            }
            
            $arr_appotments2[] = $re_array;
        }

        if($summary){
            return count($arr_appotments1) + count($arr_appotments2);
        }

        $this->set('available', $arr_appotments1);
        $this->set('claimed', $arr_appotments2);
        $this->success();
    }

    public function summary_available_uber(){
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

        $cc = new CourseController();                        
        $trainings_user = $cc->get_courses_user(USER_ID);

        $subs = $this->check_subs(USER_ID);
        //$has_basic_course = $trainings_user['has_basic_course'];
        $has_advanced_course = $trainings_user['has_advanced_course'];
        $has_level3_course = $trainings_user['has_level3_course'];
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataTreatment');
        // AVAILABLE APPOINMENTS

        $now = date('Y-m-d H:i:s');
        $fields2 = ['DataTreatment.id','DataTreatment.uid','DataTreatment.schedule_date','DataTreatment.status','DataTreatment.address','DataTreatment.treatments','DataTreatment.state','State.name','DataTreatment.patient_id', 'DataTreatment.assistance_id','DataTreatment.city','DataTreatment.zip','DataTreatment.suite','DataTreatment.latitude','DataTreatment.longitude','Patient.name','Patient.lname','Patient.phone', 'Patient.gender' ,'Patient.uid','DataTreatment.notes','DataTreatment.type_uber','DataTreatment.created','Injector.latitude','Injector.longitude','Injector.radius'/*, 'DCT.created'*/];
        $fields2['treatments_string'] = "(SELECT GROUP_CONCAT(CONCAT(CTC.name,' (',CT.name, ')') SEPARATOR ', ') 
            FROM cat_treatments_ci CT 
            JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
            WHERE FIND_IN_SET(CT.id,DataTreatment.treatments) LIMIT 1)";

        $fields2['treatments_string_claim'] = "(SELECT GROUP_CONCAT(CONCAT(CTC.name,' (',CT.name, ')',' $',TRUNCATE((CT.std_price / 100  ), 0)) SEPARATOR ', ') 
        FROM cat_treatments_ci CT 
        JOIN cat_treatments_category CTC ON CTC.id = CT.category_treatment_id 
        WHERE FIND_IN_SET(CT.id,DataTreatment.treatments) LIMIT 1)";

        $fields2['type_treatments'] = "(SELECT GROUP_CONCAT(CAT.type) FROM cat_treatments_ci CTC JOIN cat_treatments_category CAT ON CAT.id = CTC.category_treatment_id WHERE FIND_IN_SET(CTC.id, DataTreatment.treatments) LIMIT 1)";

        $fields2['distance_in_mi'] = "(69.09 * DEGREES(ACOS(LEAST(1.0, COS(RADIANS(DataTreatment.latitude))
            * COS(RADIANS(Injector.latitude))
            * COS(RADIANS(DataTreatment.longitude - Injector.longitude))
            + SIN(RADIANS(DataTreatment.latitude))
            * SIN(RADIANS(Injector.latitude))))))";

        $_where2 = ['DataTreatment.deleted' => 0, 'DataTreatment.status In' => array("PETITION"), 'DataTreatment.type_uber' => 1, 'DATE_FORMAT(DataTreatment.schedule_date, "%Y-%m-%d %H:%i:%s") >= "' . $now . '"', 'DATE_ADD(DATE_FORMAT(DataTreatment.created, "%Y-%m-%d %H:%i:%s"),INTERVAL 1 DAY) >= "' . $now . '"'];

        $_having[] = ['distance_in_mi <= Injector.radius'];

        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.CatCITreatments');
        $avappo_ent = $this->DataTreatment->find()->select($fields2)->join([
            'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
            'Patient' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Patient.id = DataTreatment.patient_id AND Patient.deleted = 0'],
            'Injector' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Injector.id = '. USER_ID .' AND Injector.deleted = 0'],
            //'DCT' => ['table' => 'data_claim_treatments', 'type' => 'LEFT', 'conditions' => 'DCT.treatment_uid = DataTreatment.uid AND DCT.deleted = 0'],
        ])->where($_where2)->having($_having)->order(['DataTreatment.schedule_date' => 'DESC'])->all();
        
        $this->loadModel('SpaLiveV1.DataClaimTreatments');
        $this->loadModel('SpaLiveV1.DataTreatmentsPrice');
        $available_treatments = array();

        $arr_appotments1 = [];

        foreach($avappo_ent as $index => $certTreatment){

            $ent_treatments = $this->CatCITreatments->find()->select(['CatCITreatments.id','CatCITreatments.name','CatCITreatments.details', 'CatCITreatments.std_price', 'Cat.name', 'CatCITreatments.category_treatment_id'])
                ->join([
                    'Cat' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'Cat.id = CatCITreatments.category_treatment_id'],
                ])
            ->where(['CatCITreatments.id IN' => explode(',', $certTreatment->treatments)]);
                
            $array_list = array();
            $iv_list = array();
            $neuro_list = array();
            $fillers_list = array();
            $other_list = array();
            $other_list_id = array();
            foreach($ent_treatments as $_row){
                if($_row->name == 'Let my provider choose' || $_row->name == 'Let my provider help me decide' || $_row->name == 'No preference'){
                    if($_row->id == 999){$_row['Cat']['name'] = 'Neurotoxins'; $_row->category_treatment_id = 1;}
                    if($_row->id == 1033){$fillers_list[] = 'Fillers'; continue;}
                    $ent_treatments2 = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.std_price', 'CatCITreatments.name', 'CatCITreatments.category_treatment_id'])->join([
                        'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id AND CatCITreatments.name <> CTC.name'],
                    ])->where(['CatCITreatments.deleted' => 0, 'CatCITreatments.name NOT IN' => array('Let my provider help me decide', 'Let my provider choose', 'No preference') ,'CatCITreatments.category_treatment_id' => $_row->category_treatment_id])->all();
                    $array_prices = array();
                    foreach ($ent_treatments2 as $key => $trea) {
                        $array_prices[] = $trea['name'];
                    }
                    $neuro_list[] = $_row['Cat']['name'];
                }else{
                    if($_row['Cat']['name'] == 'IV Therapy' || $_row['Cat']['name'] == 'IV'){
                        $iv_list[] = $_row->name;
                    }else if($_row['Cat']['name'] == 'Fillers'){
                        $fillers_list[] = $_row['Cat']['name'];
                    }else if($_row['Cat']['name'] == 'Other Treatments'){
                        $other_list[] = $_row->name;
                        $other_list_id[] = $_row->id;
                    }else{
                        $neuro_list[] = $_row->name == $_row['Cat']['name'] ? $_row->name : $_row['Cat']['name'];
                    }
                }
            }

            $string_iv = implode(', ', $iv_list);
            $string_neuro = implode(', ', $neuro_list);
            $string_filler = implode(', ', $fillers_list);
            $string_other = implode(', ', $other_list);

            $array_treatments = array();

            if(!empty($string_neuro)){
                $array_treatments['neurotoxins'] = $string_neuro;
            }

            if(!empty($string_iv)){
                $array_treatments['iv'] = $string_iv;
            }else{
                $array_treat = explode(',', $certTreatment->treatments);
            
                foreach($array_treat as $key => $value){
                    if($value == 0){
                        $string_iv = 'No preference';
                        $array_treatments['iv'] = $string_iv;
                    }
                }
            }

            if(!empty($string_filler)){
                $array_treatments['fillers'] = $string_filler;
            }

            if(!empty($string_other)){
                $array_treatments['other'] = $string_other;
            }

            $string_treatments = '';
            $array_string_treatments = array();
            $array_prices = array();

            $array_title_card = array();
            $title_card = '';

            if(isset($array_treatments['neurotoxins'])){
                $array_title_card[] = 'Neurotoxins Treatment';
                if(!$subs['neuro']){
                    continue;
                }

                if(!$has_advanced_course || !$has_level3_course){
                    continue;
                }

            }

            if(isset($array_treatments['fillers'])){
                $array_title_card[] = 'Fillers Treatment';
                if(!$subs['fillers']){
                    continue;
                }
            }

            if(isset($array_treatments['iv'])){
                $array_title_card[] = 'IV Treatment';
                if(!$subs['iv']){
                    continue;
                }
                
                $join = [];
                if($array_treatments['iv'][0] == 0){
                    $join = ['CTC' => ['table' => 'cat_treatments_ci', 'type' => 'INNER', 'conditions' => 'CTC.id = DataTreatmentsPrice.treatment_id']];
                    $_where = ['DataTreatmentsPrice.user_id' => USER_ID, 'DataTreatmentsPrice.deleted' => 0, 'CTC.category_treatment_id' => 1001, 'CTC.deleted' => 0];

                    $treatment_prices = $this->DataTreatmentsPrice->find()
                    ->join($join)
                    ->where($_where)->all();

                    if(count($treatment_prices) == 0){
                        continue;
                    }

                }else{
                    $_where = ['user_id' => USER_ID, 'deleted' => 0, 'treatment_id IN' => $array_treatments['iv']];

                    $treatment_prices = $this->DataTreatmentsPrice->find()
                    ->join($join)
                    ->where($_where)->count();

                    if($treatment_prices < count($array_treatments['iv'])){
                        continue;
                    }
                }

            }

            if(isset($array_treatments['other'])){
                $array_title_card[] = $string_other;
                foreach($other_list_id as $treatment_id){
                    $ent_treatments_ci = $this->CatCITreatments->find()
                    ->select(['name_key' => 'ST.name_key'])
                    ->join([
                        'CT' => ['table' => 'cat_treatments', 'type' => 'INNER', 'conditions' => 'CT.id = CatCITreatments.treatment_id'],
                        'ST' => ['table' => 'sys_treatments_ot', 'type' => 'INNER', 'conditions' => 'ST.id = CT.other_treatment_id'],
                    ])
                    ->where(['CatCITreatments.deleted' => 0, 'CatCITreatments.id' => $treatment_id])
                    ->first();

                    if(empty($ent_treatments_ci)){
                        continue 2;
                    }

                    $service_name = $ent_treatments_ci->name_key;
                    
                    $where = ['user_id' => USER_ID, 'status' => 'ACTIVE', 'deleted' => 0, 'subscription_type LIKE' => '%MSL%'];
                    $where['OR'] = [['main_service' => $service_name], ['addons_services LIKE' => '%' . $service_name . '%']];
                    
                    $ent_sub = $this->DataSubscriptions->find()->where($where)->first();

                    if(empty($ent_sub)){
                        continue 2; // Salir del bucle interno
                    }

                    // Verificar precios del tratamiento automáticamente
                    $_where = ['user_id' => USER_ID, 'deleted' => 0, 'treatment_id IN' => $treatment_id];

                    $treatment_prices = $this->DataTreatmentsPrice->find()
                    ->where($_where)->count();

                    if($treatment_prices < 1){
                        continue 2;
                    }
                }
            }

            $title_card = implode(', ', $array_title_card);

            if($now > date('Y-m-d H:i:s', strtotime($certTreatment['created']->i18nFormat('yyyy-MM-dd HH:mm:ss') . ' + 2 day'))){
                continue;
            }
            
            $sstr_address = $certTreatment->city . ', ' . $certTreatment['State']['name'] . ' ' . $certTreatment->zip;
            if (!empty($certTreatment->suite)) {
                $sstr_address = $certTreatment->city . ', ' . $certTreatment['State']['name'] . ' ' . $certTreatment->zip;
            }

            $re_array = array(
                'title_card' => $title_card,
                'uid' => $certTreatment->uid,
                'schedule_date' => $certTreatment->schedule_date,
                'address' => $sstr_address,
            );
            
            $arr_appotments1[] = $re_array;
        }

        $this->set('available_appointments', $arr_appotments1);
        $this->set('claim_button', $this->summary_like_uber(true));
        $this->success();
    }

    public function check_subs($user_id){

        $this->loadModel('SpaLiveV1.DataSubscriptions');

        $ent_sub = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => $user_id, 'DataSubscriptions.status' => 'ACTIVE', 'DataSubscriptions.subscription_type LIKE' => '%MD%', 'DataSubscriptions.deleted' => 0])->first();

        $mian = $ent_sub->main_service;
        $add_on = $ent_sub->addons_services;

        $neuro = false;
        $iv = false;
        $fillers = false;

        if(strpos($mian, 'NEUROTOXINS') !== false || strpos($add_on, 'NEUROTOXINS') !== false){
            $neuro = true;
        }

        if(strpos($mian, 'IV THERAPY') !== false || strpos($add_on, 'IV THERAPY') !== false){
            $iv = true;
        }

        if(strpos($mian, 'FILLERS') !== false || strpos($add_on, 'FILLERS') !== false){
            $fillers = true;
        }

        return array('neuro' => $neuro, 'iv' => $iv, 'fillers' => $fillers);
    }

    function pixel() {

        $this->loadModel('SpaLiveV1.DataVisitsSites');

        $array_save = array(
            // 'id' => USER_ID,
            'created' => date('Y-m-d H:i:s'),
            'ip' => $_SERVER['REMOTE_ADDR'],
            'page' => get('page','')
        );

        $c_entity = $this->DataVisitsSites->newEntity($array_save);
        $this->DataVisitsSites->save($c_entity);


        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');


        $image = imagecreatetruecolor(1, 1);

        // Establecer un color para el píxel (por ejemplo, blanco)
        $color = imagecolorallocate($image, 255, 255, 255);

        // Pintar el píxel con el color asignado
        imagesetpixel($image, 0, 0, $color);

        // Enviar los encabezados adecuados
        header('Content-Type: image/png');

        // Salida de la imagen en formato PNG
        imagepng($image);

        // Liberar recursos
        imagedestroy($image);

    }
}