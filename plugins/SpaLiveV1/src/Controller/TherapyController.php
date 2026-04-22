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
require_once(ROOT . DS . 'vendor' . DS  . 'aws' . DS . 'aws-autoloader.php');
use \Aws\S3\S3Client;

use Spipu\Html2Pdf\Html2Pdf;
use Spipu\Html2Pdf\Exception\Html2PdfException;
use Spipu\Html2Pdf\Exception\ExceptionFormatter;

use SpaLiveV1\Controller\FillersController;
use SpaLiveV1\Controller\MainController;
use SpaLiveV1\Controller\SubscriptionController;
use Cake\I18n\FrozenTime;

class TherapyController extends AppPluginController {

    #region VARIABLES

	private $total_subscriptionmsl = 3995;
	private $total_subscriptionmd = 17900;
    private $training_basic = 79500;
    private $training_advanced = 89500;
    private $level_3_medical = 99500;//level 3 medical
    private $total = 3900;
    private $allowed_license_types = ['RN', 'NP', 'PA', 'MD'];

    private $URL_API = "";
    private $URL_WEB = "";
    private $URL_ASSETS = "";
    private $URL_PANEL = "";

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
		$this->loadModel('SpaLiveV1.AppToken');
		$this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataPurchases');
		$this->loadModel('SpaLiveV1.DataPayment');
		$this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.CatStates');
        $this->loadModel('SpaLiveV1.CatDiseases');
        $this->loadModel('SpaLiveV1.DataDiseases');

        $this->URL_API = env('URL_API', 'https://api.spalivemd.com/');
        $this->URL_WEB = env('URL_WEB', 'https://app.spalivemd.com/');
        $this->URL_ASSETS = env('URL_ASSETS', 'https://api.spalivemd.com/assets/');
        $this->URL_PANEL = env('URL_PANEL', 'https://panel.spalivemd.com/');

        $token = get('token',"");
        if(isset($token)){
            $user = $this->AppToken->checkToken($token);
            if($user !== false){
                $state = $this->CatStates->find()->select(['CatStates.price_sub_msl', 'CatStates.price_sub_md'])->where(['CatStates.id' => $user['user_state']])->first();
                if(!empty($state)){
                    $this->total_subscriptionmsl = $state->price_sub_msl > 0 ? $state->price_sub_msl : $this->total_subscriptionmsl;
                    $this->total_subscriptionmd = $state->price_sub_md > 0 ? $state->price_sub_md : $this->total_subscriptionmd;
                }
            }
        }
    }

    #endregion

    #region ENDPOINTS

    public function test_n(){
        $this->success();
    }

    public function check_token($token){
        if(!empty($token)){
            
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                return array(
                    'session' => false,
                );
            }
            return array(
                'user' => $user,
                'session' => true
            );
        } else {
            return array(
                'session' => false,
            );
        }
    }

    public function status_iv_therapy(){
        $token = get('token',"");
        
        $token_response = $this->check_token($token);

        if(!$token_response['session']){
            $this->message("Invalid token.");
            $this->set('session', false);
            return;
        }

        $request = $this->request_therapist($token_response["user"]["user_id"]);

        if(!$request['success']&&$request['status']=='ERROR'){
            $this->message($request['message']);
            $this->set("status", "PENDING");
            $this->set("license_status", "PENDING");
        }else{
            if($request['status']=='ACCEPTED'){
                $its_approved = $this->user_has_approved_license($token_response["user"]["user_id"]);

                if($its_approved['has_valid_license']){
                    $this->set("status", $request['status']);
                    $this->set("license_status", "APPROVED");
                }else{
                    $this->set("status", "REJECTED");
                    $this->set("license_status", "REJECTED");
                }

            }else{
                $this->set("status", $request['status']);
                $this->set("license_status", $request['status']);
            }
        }
        $this->set("training_id", $request['training_id']);
        $this->set("data_training_id", '' . $request['data_training_id']);

        $this->success();
    }

    public function apply_as_therapist(){
        $token = get('token',"");
        
        $token_response = $this->check_token($token);

        if(!$token_response['session']){
            $this->message("Invalid token.");
            $this->set('session', false);
            return;
        }else{
            $this->set('session', true);

        }

        $license_valid_arr = $this->has_valid_license($token_response["user"]["user_id"]);

        $has_valid_license = $license_valid_arr['has_valid_license'];
        $license           = $license_valid_arr['license'];
        //pr($license_valid_arr); die(); for testing purposes

        if(!$has_valid_license){
            $this->message('You don\'t have a valid license to apply as a therapist.');
            return;
        }

        $request = $this->request_therapist($token_response["user"]["user_id"], $license);
        if(!$request['success']&&$request['status']=='ERROR'){
            $this->message($request['message']);
            return;
        }else{
            $this->set("status", $request['status']);
            $this->message($request['message']);
        }

        $this->success();
    }

    public function check_licenses_therapist(){
        $this->validate_session();
        $this->set('has_valid_license', $this->has_valid_license(USER_ID));     
        $this->success();
    }

    public function save_iv_form(){
        $token = get('token',"");
        
        $token_response = $this->check_token($token);

        if(!$token_response['session']){
            $this->message("Invalid token.");
            $this->set('session', false);
            return;
        }

        $this->set('session', true);

        $practice_name = get('practice_name',"");
        $primary_contact_name = get('primary_contact_name',"");
        $phone = get('phone',"");
        $office_hour = get('office_hour',"");
        $clinic_name = get('clinic_name',"");
        $npi = get('npi',"");

        $data = array(
            'practice_name' => $practice_name,
            'primary_contact_name' => $primary_contact_name,
            'phone' => $phone,
            'office_hour' => $office_hour,
            'clinic_name' => $clinic_name,
            'user_id' => USER_ID,
            'npi' => $npi,
        );

        if (isset($_FILES['file'])) {
            if (!isset($_FILES['file']['tmp_name']) || empty($_FILES['file']['tmp_name'])) {
                $this->set('error_name','No file uploaded');
                return;
            }
            
            $file = $_FILES['file'];
            $file_uid = Text::uuid();
            
            // DEBUG: Log the original filename
            error_log("Original filename: " . var_export($file['name'], true));
            
            // Try to get extension from filename first
            $file_extension = '';
            if (isset($file['name']) && !empty($file['name'])) {
                $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                error_log("Extension from pathinfo: " . var_export($file_extension, true));
            }
            
            // Clean up extension - remove if it's invalid
            $file_extension = trim($file_extension);
            if ($file_extension === 'null' || $file_extension === '' || $file_extension === null) {
                error_log("Extension was invalid, will use MIME type detection");
                $file_extension = '';
            }
            
            // If no extension from filename, detect from MIME type
            if (empty($file_extension)) {
                // Detect MIME type from the uploaded file
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);
                
                error_log("Detected MIME type: " . $mime_type);
                
                // Map MIME type to extension
                $mime_to_ext = [
                    'image/jpeg' => 'jpg',
                    'image/jpg' => 'jpg',
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
                    'application/pdf' => 'pdf',
                    'application/msword' => 'doc',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                    'application/vnd.ms-excel' => 'xls',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
                    'text/plain' => 'txt',
                ];
                
                $file_extension = isset($mime_to_ext[$mime_type]) ? $mime_to_ext[$mime_type] : 'bin';
                error_log("Extension from MIME type: " . $file_extension);
            }
            
            $str_name = isset($file['name']) ? $file['name'] : 'uploaded_file';
            $filename = $file_uid . '.' . $file_extension;
            
            error_log("Final filename: " . $filename);

            // Create temporary path
            $tmp_path = TMP . "sign_forms/" . $filename;

            // Ensure directory exists
            if (!is_dir(dirname($tmp_path))) {
                mkdir(dirname($tmp_path), 0755, true);
            }
            
            // Move uploaded file to temp location
            if (!move_uploaded_file($file['tmp_name'], $tmp_path)) {
                $this->message('Error moving uploaded file');
                return;
            }

            $serverN = 'sign_forms/' . $file_uid . '.' . $file_extension;

            $this->s3Client = new \Aws\S3\S3Client([
                'version' => 'latest',
                'region'  => 'nyc3',
                'endpoint' => env('SPACES_ORIGIN'),
                'credentials' => [
                    'key'    => env('SPACES_ACCESS_KEY'),
                    'secret' => env('SPACES_SECRET'),
                ],
            ]);
    
            $resFile = $this->s3Client->putObject(array(
                'Bucket'     => env('SPACES_SIMPLE_BUCKET'),
                'SourceFile' => $tmp_path,
                'Key'        => $serverN,
                'ACL' => 'public-read',
            ));
    
            // Use the ObjectURL returned by S3
            $s3File = $resFile['ObjectURL'];

            $data['sign_url'] = $s3File;
            
            // Cleanup temporary file
            if (file_exists($tmp_path)) {
                unlink($tmp_path);
            }
        }

        $res = $this->save_iv_form_info($data);

        if($res){
            $user = $this->SysUsers->find()->where(['id' => USER_ID])->first();
            $purchase = $this->DataPurchases->find()->where(['user_id' => USER_ID, 'deleted' => 0, 'status' => 'PHARMACY FORM PENDING'])->all();
            if(count($purchase) > 0){
                foreach($purchase as $p){
                    $iv_products = $this->bought_iv_therapy_products($p->id);
                    if(count($iv_products)>0){
                        //compro productos de iv therapy (vials)
                        $this->send_email_to_pharmacy($user,$iv_products,$p->id);
                        $this->DataPurchases->updateAll(
                            ['status' => 'NEW'],
                            ['id' => $p->id]
                        );
                    }
                }
            }

            $this->success();
        }else{
            $this->message("Error while saving IV form.");
            return;
        }
    }

    public function get_iv_form_agreement(){
        $token = get('token',"");
        
        $token_response = $this->check_token($token);

        if(!$token_response['session']){
            $this->message("Invalid token.");
            $this->set('session', false);
            return;
        }

        $this->set('session', true);

        $user = $this->SysUsers->find()
        ->select(['SysUsers.name', 'SysUsers.lname', 'SysUsers.phone', 'SysUsers.email', 'SysUsers.street', 'SysUsers.suite', 'SysUsers.city', 'SysUsers.zip', 'SysUsers.state', 'State.name', 'License.number'])
        ->join([
            'State' => ['table' => 'cat_states', 'type' => 'LEFT', 'conditions' => 'State.id = SysUsers.state'],
            'License' => ['table' => 'sys_licences', 'type' => 'LEFT', 'conditions' => 'License.user_id = SysUsers.id AND License.deleted = 0'],
        ])
        ->where(['SysUsers.id' => USER_ID])->first();

        if(empty($user)){
            $this->message("User not found.");
            $this->set('session', false);
            return;
        }

        $iv_form = $this->get_iv_form_info(USER_ID);
        if(!$iv_form){
            $this->set('iv_form', true);
        }else{
            $this->set('iv_form', false);
        }

        $autofiled_data = array(
            'name' => $user->name . ' ' . $user->lname,
            'email' => $user->email,
            'phone' => $user->phone,
            'address' => $user->street,
            'suite' => $user->suite,
            'city' => $user->city,
            'zip' => $user->zip,
            'state' => $user['State']['name'],
            'hear' => 'MySpaLive',
            'prescribers_name' => 'Dr. Marie Beauvoir',
            'licence_number' => $user['License']['number'],
        );

        $agreement = "
            <p> This agreement is intended for Drug Crafters, LP, a 503A Pharmacy, to verify that both sterile and non-sterile compounds, that are prepared at Drug Crafters, are shipped to the Prescriber's office as a patient specific prescription and will be stored and handled properly. Please be advised that Drug Crafters complies with both State and Food and Drug Administration (FDA) 503A regulations. Your facility is required to comply with the following criteria:</p>
            <p> (A)        If prescriber decides to take on the role of caregiver or authorized agent of the patient by administering the patient's medication at their clinic, the prescriber understands: compounded medications may only be administered to the intended patient indicated on the prescription label; medications may not be distributed, upcharged or resold to the patient or sold to another patient or entity; prescriber will provide counseling and be available post therapy to the patient; and the prescriber agrees to provide to the patient advisory leaflets supplied in the shipment so that patient has access to information relevant to their medication.</p>
            <p> (B)        All prescriptions must be patient specific and include:</p>
            <ul>
                <li> Patient name </li>
                <li> Patient date of birth </li>
                <li> Patient address </li>
                <li> Patient phone number </li>
                <li> Refills </li>
                <li> Drug name </li>
                <li> Drug strength </li>
                <li> Drug quantity </li>
                <li> Route of administration </li>
                <li> Dosing frequency must justify day supply </li>
            </ul>
            <p> (C)        Practitioners are required to include the following on their patient's chart:</p>
            <ul>
                <li> Medication order </li>
                <li> Medication administration record </li>
                <li> Lot number of compound </li>
                <li> Beyond use date of compound </li>
            </ul>
            <p>(D)        Submitting a Complaint</p>
            <p>a.        If a patient has an adverse reaction, contact Drug Crafters Pharmacy so we can investigate what the reaction was caused from. Any additional complaints against the pharmacy can be submitted to the Texas State Board of Pharmacy.</p>
            <p>b.        If Drug Crafters recalls a batch of compounds, we will immediately contact the physician and/or patient via telephone or email to let them know details about the recall. In addition, a letter will also be mailed out to the physician and/or patient with the same details.</p>
        ";

        $agreement2 = "
            <p>will handle both sterile and non-sterile compounds properly and will abide by the criteria listed above. I understand that failure to abide by this agreement can result in Drug Crafters terminating business with my clinic, and with any clinic, with which I am associated.</p>
            <p>I understand that state laws require a prescriber to have a valid practitioner- patient relationship, as defined by TX Pharmacy Act Sec 562.056. Our office does not prescribe hormones or any other drugs by phone or internet, without first meeting the patient and establishing a relationship.</p>
            <p>I understand that the law for prescribing and dispensing hormones, Human Growth Hormone and anabolic steroids includes a recognized medical condition. I DO NOT prescribe hormones, Human Growth Hormone and anabolic steroids for anti-aging, sports performance enhancement, or body building.</p>
            <p>Pursuant to Texas Occupations code, Title 3, Subchapter 3, subtitle J, Subchapter B - I understand that I am responsible for the education, medical actions, and activity of those hereto who I have delegated authority to be my designated agent. I agree to maintain supervision and oversight of all prescriptions, to ensure reasonable patient safety.</p>
            <p>If you are a license Nurse Practitioner, Physician Assistant and will be prescribing medications (verbal or signed), the Texas State Board of Pharmacy 291.34 22 TAC, Part 15) requires you to have a supervising physician that has delegated you such prescriptive authority. This applies whether or not the state you practice in requires a supervising physician or not.</p>
        ";

        $this->set('autofiled_data', $autofiled_data);
        $this->set('agreement1', $agreement);
        $this->set('agreement2', $agreement2);
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

    // OBTAINS THE COURSE FOR THERAPIST, LEVEL IV
    public function get_course_therapist(){
        $this->loadModel('SpaLiveV1.CatTrainings');

        $ent_course = $this->CatTrainings->find()
            ->where([
                'CatTrainings.level' => 'LEVEL IV'
            ])
            ->first();
        
        return $ent_course;
    }

    // DERTERMINES IF THE USER HAS A VALID LICENSE FOR THE THERAPIST COURSE
    public function has_valid_license(
        $user_id
    ){
        $licenses = $this->get_licenses($user_id);
        
        $has_valid_license = false;
        $valid_license = array(); 

        foreach($licenses as $license){
            if(in_array($license->type, $this->allowed_license_types)){
                $has_valid_license = true;
                $valid_license = $license;
                break;
            }
        }

        return array(
            'has_valid_license' => $has_valid_license,
            'license' => $valid_license
        );
    }

    // OBTAINS THE LICENSES OF THE USER
    public function get_licenses(
        $user_id
    ){
        $this->loadModel('SpaLiveV1.SysLicences');

        $licenses = $this->SysLicences->find()
            ->where([
                'SysLicences.user_id' => $user_id,
                'SysLicences.deleted' => 0
            ])
            ->toArray();

        return $licenses;
    }    

    // CHECK THE PREVIOUS REQUEST TO BE A THERAPIST AND ADD A NEW ONE IF THERE ISN'T ANY
    public function request_therapist(
        $user_id,
        $license = array()
    ){
        $training_iv = $this->get_course_therapist();     

        $this->loadModel('SpaLiveV1.DataTraining');        

        $prev_request = $this->previous_therapist_request($user_id);


        if(!empty($prev_request)){
            $status = $this->request_status($prev_request);
            if($status == 'PENDING'){
                return array(
                    'status' => 'PENDING',
                    'message' => 'You already have a pending request to be a Therapist.',
                    'success' => false,
                    'training_id' => $prev_request['CatTrainings']['id'],
                    'data_training_id' => $prev_request['id']
                );
            }else if($status == 'ACCEPTED'){
                return array(
                    'status' => 'ACCEPTED',
                    'message' => 'You are already an IV Therapist.',
                    'success' => false,
                    'training_id' => $prev_request['CatTrainings']['id'],
                    'data_training_id' => $prev_request['id']
                );
            }
            // ⚠️⚠️⚠️ If the request was rejected, the user can send a new request, this can be changed in the future.
            else if($status == 'REJECTED'){
                return array(
                    'status' => 'REJECTED',
                    'message' => 'Your request to be a Therapist was rejected.',
                    'success' => false,
                    'training_id' => $prev_request['CatTrainings']['id'],
                    'data_training_id' => $prev_request['id']
                );
            }
        }

        $array_save = array(
            'user_id' => $user_id,
            'training_id' => $training_iv->id,        
        );

        if(!empty($license)){
            $array_save['attended'] = $license['status'] == 'APPROVED' ? 1 : 0;
        }
        
        $this->log(__LINE__ . ' ' . json_encode($array_save));
        $ent_training = $this->DataTrainings->newEntity($array_save);
        $save_res = $this->DataTrainings->save($ent_training);
        if(!$save_res){
            return array(
                'status' => 'ERROR',
                'message' => 'Error while requesting to be a Therapist.',
                'success' => false
            );
        }else{ //save first attempt to be therapy
            $Main = new MainController();
            $Main->notify_devices('APPLY_IVT', array(USER_ID), true, true, true, array(), '', array(), true);
            $this->loadModel('SpaLiveV1.DataInjectorRegistered');
            $exist = $this->DataInjectorRegistered->find()->where([
                'DataInjectorRegistered.user_id' => $user_id ,
                'DataInjectorRegistered.deleted' => 0 ,
            ])->first();

            $array_s = array(
                'user_id' => $user_id,
                'date_start' => date('Y-m-d H:i:s'),
                'type' => 'IV_THERAPY', 
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

        $this->loadModel('SpaLiveV1.SysUsers');
        
        $ent_user = $this->SysUsers->find()->where([
            'SysUsers.id' => $user_id,
        ])->first();

        if(!empty($license)){
            $ent_user->steps = $license['status'] == 'APPROVED' ? 'APPIVAPPROVED' : "WAITINGIVAPPROVAL";
        }else{
            $ent_user->steps = "WAITINGIVAPPROVAL";
        }

        $update = $this->SysUsers->save($ent_user);

        if($update){
            return array(
                'status' => 'SUCCESS',
                'message' => 'Request to be a Therapist sent successfully.',
                'success' => true,
                'course_id' => !empty($save_res) ?  $save_res->id : 0
            );
        }else{
            return array(
                'status' => 'ERROR',
                'message' => 'Error updating registration step.',
                'success' => false
            );
        }
        
    }

    //GET THE CATALOG OF DISEASES
    public function get_diseases(){
        $this->validate_session();
        $this->loadModel('SpaLiveV1.CatDiseases');  
        
        $diseases = $this->CatDiseases->find()->all();
        
        $this->set('data', $diseases);
        $this->success();
        return;
    }
    
    //GET THE AGREEMENT patient
    public function get_agreements(){
        $this->validate_session();
        $this->loadModel('SpaLiveV1.CatAgreements');  
        $this->loadModel('SpaLiveV1.DataAgreements'); 
        //Get patient agreement catalog
        $CatAgreements = $this->CatAgreements->find()->where([
            'CatAgreements.state_id' => USER_STATE,
            'CatAgreements.user_type' => 'PATIENT',
            'CatAgreements.agreement_type IN' => ['REGISTRATION', 'IVTHERAPHY', 'FILLERS'], // Usa IN para verificar si es igual a uno de los valores
            'CatAgreements.deleted' => 0,
        ])->all();

        //variables de control si ya firmaron no cambiar a false
        $signed_iv_agreement = false;
        $signed_nt_agreement = false;
        $patient_consent_fillers = false;


        if(!empty($CatAgreements)){
            foreach($CatAgreements as $element){
                //guardar cada disease
                
                $DataAgreement = $this->DataAgreements->find()->where([
                    'DataAgreements.agreement_uid' => $element->uid,
                    'DataAgreements.user_id' => USER_ID,
                    'DataAgreements.deleted' => 0,
                ])->first();

                if($element->agreement_title == "IV Theraphy"){
                    if(!empty($DataAgreement)){
                        $this->set('signed_iv_agreement', true);
                        $signed_iv_agreement = true;
                    } else {
                        if($signed_iv_agreement == false){
                            $this->set('signed_iv_agreement', false);
                        }
                    }
                }

                
                if($element->agreement_title == "Patient Consent"){
                    if(!empty($DataAgreement)){
                        $this->set('signed_nt_agreement', true);
                        $signed_nt_agreement = true;
                    } else {
                        
                        if($signed_nt_agreement == false){
                            $this->set('signed_nt_agreement', false);
                        }
                        //$this->set('signod', $DataAgreement);
                    }
                }

                if($element->agreement_title == "FILLERS"){
                    if(!empty($DataAgreement)){
                        $this->set('patient_consent_fillers', true);
                        $patient_consent_fillers = true;
                    } else {
                        if($patient_consent_fillers == false){
                            $this->set('patient_consent_fillers', false);
                        }
                    }
                }
            }
        }

        //if(!empty($DataAgreement)){
        //    $this->set('SignedAgreement', 'true');
        //} else {
        //    $this->set('SignedAgreement', 'false');
        //}
        
        //$this->set('DataAgreement', $DataAgreement);
        $this->success();
        return;
    }      

    
    //GET THE AGREEMENT 
    public function get_agreements_gfe(){
        $this->validate_session();
        $this->loadModel('SpaLiveV1.CatAgreements');  
        $this->loadModel('SpaLiveV1.DataAgreements'); 
        //Get patient agreement catalog
        $CatAgreements = $this->CatAgreements->find()->where([
            'CatAgreements.user_type' => 'EXAMINER',
            'CatAgreements.agreement_type IN' => ['TERMSANDCONDITIONS', 'IVTHERAPHY'], // Usa IN para verificar si es igual a uno de los valores
            'CatAgreements.deleted' => 0,
        ])->all();

        if(!empty($CatAgreements)){
            foreach($CatAgreements as $element){
                //guardar cada disease
                
                $DataAgreement = $this->DataAgreements->find()->where([
                    'DataAgreements.agreement_uid' => $element->uid,
                    'DataAgreements.user_id' => USER_ID,
                    'DataAgreements.deleted' => 0,
                ])->first();

                if($element->agreement_title == "IV Theraphy"){
                    if(!empty($DataAgreement)){
                        $this->set('signed_iv_agreement', true);
                    } else {
                        $this->set('signed_iv_agreement', false);
                    }
                }

                
                if($element->agreement_title == "Terms and conditions"){
                    if(!empty($DataAgreement)){
                        $this->set('signed_nt_agreement', true);
                    } else {
                        $this->set('signed_nt_agreement', false);
                    }
                }
            }
        }

        //if(!empty($DataAgreement)){
        //    $this->set('SignedAgreement', 'true');
        //} else {
        //    $this->set('SignedAgreement', 'false');
        //}
        
        //$this->set('DataAgreement', $DataAgreement);
        $this->success();
        return;
    }      
    
    //CREATE PATIENT HISTORY 
    public function create_patient_history(){  
        $this->validate_session();
        $this->loadModel('SpaLiveV1.DataAgreements');
        $this->loadModel('SpaLiveV1.DataPastMedicalHistory');
        $this->loadModel('SpaLiveV1.DataDiseases');

        //get user uid
        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();

        //save the deaseases
        $diseases = get('diseases',  '[]');
        $diseasesList = json_decode($diseases);
        //$this->set('diseases', $diseases);
        //$this->set('diseasesList', $diseasesList);

        
        $primary_care = get('primary_care',  '');
        $current_health = get('current_health',  '');
        $date_last_blood_test = get('date_last_blood_test',  '');
        $pertinent_details = get('pertinent_details',  '');
        $allergic_reactions = get('allergic_reactions',  '');
        $pregnant = get('pregnant',  0);
        $breastfeeding = get('breastfeeding',  0);
        $str_uid = get('agreement_uid','');
        $str_sign = $ent_user->name. ' ' .$ent_user->lname;

        //foreach desease
        if(!empty($diseasesList)){
            foreach($diseasesList as $element){
                //guardar cada disease

                $already_saved = $this->DataDiseases->find()
                ->where([
                    'DataDiseases.user_id' => USER_ID,
                    'DataDiseases.disease_uid' => $element->id,
                    'DataDiseases.deleted' => 0,
                ])->first();

                if(!empty($already_saved)){
                    continue;
                }

                $array_save = array(
                    'user_id' => USER_ID,
                    'disease_uid' => $element->id,
                    'deleted' => 0
                );
    
                $entity = $this->DataDiseases->newEntity($array_save);
                if($entity->hasErrors()){

                    $this->message('Error saving diseases');
                    return;
                }else{
                    $res1 = $this->DataDiseases->save($entity);
                    //if($res1){
                    //    $this->set('dcses', $res1);
                    //}
                }
            }
        }

        $array_saveData = array(
            'uid' => Text::uuid(),
            'user_id' => USER_ID,
            'primary_care' => $primary_care,
            'current_health' => $current_health,
            'date_last_blood_test' => $date_last_blood_test,
            'pertinent_details' => $pertinent_details,
            'allergic_reactions' => $allergic_reactions,
            'pregnant' => $pregnant,
            'breastfeeding' => $breastfeeding,
        );

        $entityData = $this->DataPastMedicalHistory->newEntity($array_saveData);
        if($entityData->hasErrors()){
            
            $this->message('Something was wrong');
            return;
        } else {
            $res = $this->DataPastMedicalHistory->save($entityData);
            if($res){
                $this->set('data', $res);
            }
        }

        $this->set('data', $entityData);
        $patient_uid =  $ent_user->uid;
        $this->register_agreement($str_uid, $str_sign, $patient_uid);
        $this->success();
        return;
    }

    //CREATE AGREEMENT
    public function register_agreement($str_uid, $str_sign, $patient_uid) {

        //$str_uid = get('agreement_uid','');
        //$str_sign = get('sign','');
        $_userid = USER_ID;
        $_file_id = 0;

        //$patient_uid = get('patient_uid','');
        if (!empty($patient_uid)) {
            $this->loadModel('SpaLiveV1.SysUsers');
            $ent_user = $this->SysUsers->find()->where(['SysUsers.uid' => $patient_uid])->first();
            if (!empty($ent_user)) {
                $_userid = $ent_user->id;
            }
        }

        if (empty($str_uid) || empty($str_sign)) {
            $this->message('Invalid params.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataAgreement');
        $hasAgreementSigned = $this->DataAgreement->find()->where(['DataAgreement.agreement_uid' => $str_uid, 'DataAgreement.user_id' => $_userid, 'DataAgreement.deleted' => 0, 'DataAgreement.file_id !=' => 0])->first();

        if(!empty($hasAgreementSigned)){
            $this->success();
            return;
        }

        if (isset($_FILES['file'])) {
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
        }

        $this->loadModel('SpaLiveV1.Agreement');
        $ent_agreement = $this->Agreement->find()->where(
                ['Agreement.uid' => $str_uid,
                'Agreement.deleted' => 0]
            )->first();
        if (empty($ent_agreement)) {
            $this->message('Invalid agreement.');
            return;
        }
        // $agreement_id = $this->Agreement->uid_to_id(get('uid_patient', ''));

        // if ($agreement_id == 0) {
        //     $this->message('Invalid agreement.');
        //     return;
        // }
        

        $array_save = array(
            'uid' => Text::uuid(),
            'user_id' => $_userid,
            'sign' => $str_sign,
            'agreement_uid' => $str_uid,
            'file_id' => $_file_id,
            'content' => $ent_agreement->content,
            'created' => date('Y-m-d H:i:s'),
        );

        $entity = $this->DataAgreement->newEntity($array_save);
        if(!$entity->hasErrors()){
            if($this->DataAgreement->save($entity)){

                $this->loadModel('SpaLiveV1.SysUsers');
                $ent_user = $this->SysUsers->find()->where(['id' => USER_ID])->first();
                $ent_user->steps = "MSLSUBSCRIPTION";
                $this->SysUsers->save($ent_user);

                $this->set('agreement_id', $entity->id);

                if(!empty($entity->id)){
                    //create medical history
                }

                $this->success();
            }
        }
    }

    
    //Change step
    public function set_step() {
        //$this->validate_session();
        //$str_uid = get('agreement_uid','');
        //$str_sign = get('sign','');
        $token = get('token', '');
        $step = get('step', '');

        if(empty($step)){
            $this->message('No step sended');
            return;

        }
        
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
        
        $_userid = USER_ID;

        $this->loadModel('SpaLiveV1.SysUsers');
        $ent_user = $this->SysUsers->find()->where(['id' => USER_ID])->first();
        $ent_user->steps = $step;
        $this->SysUsers->save($ent_user);


        $this->success();
    }

    public function reschedule_treatment(){
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->validate_session();
        $token = get('token', '');
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                return;
            }  
        }
        
        $date = get('date', '');
        $uid = get('uid', '');

        $this->loadModel('SpaLiveV1.DataTreatment');

        $entUpdate = $this->DataTreatment->updateAll(
            ['schedule_date' => $date],
            ['uid' => $uid]
        );     
        
        $this->success();
        
        return;

    }

    public function request_gfeci(){
        $this->loadModel('SpaLiveV1.SysUsers');
        $token = get('token', '');
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                return;
            }  
        }

        $this->loadModel('SpaLiveV1.DataRequestGfeCi');

        $requestItem = $this->DataRequestGfeCi->find()->where(['DataRequestGfeCi.user_id' => USER_ID])->first();
        if(empty($requestItem)){

             $request_save = [
                'user_id' => USER_ID,
                'created' => date('Y-m-d H:i:s'),
                'status' => 'INIT',
            ];

            $entRequestSave = $this->DataRequestGfeCi->newEntity($request_save);
            if(!$entRequestSave->hasErrors()){
                $this->success();
                if($this->DataRequestGfeCi->save($entRequestSave)){
                }
            }

        }
        
    }
    
    // ONLY CHECK THE PREVIOUS REQUEST TO BE A THERAPIST IF EXIST IF NOT SEND A FALSE
    public function check_application(){        
        $this->validate_session();
        //$request = $this->request_therapist(USER_ID);
        //$training_iv = $this->get_course_therapist();     

        $this->loadModel('SpaLiveV1.DataTrainings');        

        $prev_request = $this->previous_therapist_request_check(USER_ID);
        //$this->set('has_valid_license', $prev_request); 
        //$this->set('USER_ID', USER_ID); 
        if(!empty($prev_request)){
            $status = $this->request_status($prev_request);
            if($status == 'PENDING'){
                $this->success();
                $this->set('status', 'PENDING'); 
                return $status;
            }else if($status == 'ACCEPTED'){
                $this->success();
                $this->set('status', 'ACCEPTED'); 
                return $status;
            } else {
                $this->success();
                $this->set('status', 'REJECTED'); 
                return $status;
            }
        }

        $this->success();
        $this->set('status', 'FALSE'); 
        return 'FALSE';
    }

    // OBTAINS THE MOST RECENT REQUEST TO BE A THERAPIST
    public function previous_therapist_request($user_id){
        $this->loadModel('SpaLiveV1.DataTrainings');

        $training_iv = $this->get_course_therapist();

        $ent_training = $this->DataTrainings->find()->select(['CatTrainings.id','DataTrainings.attended','DataTrainings.id'])
            ->join([
                'CatTrainings' => ['table' => 'data_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainings.id = DataTrainings.training_id']
            ])
            ->where([
                'DataTrainings.user_id' => $user_id,
                'DataTrainings.training_id' => $training_iv->id,
                'DataTrainings.deleted' => 0,
            ])
            ->order(['DataTrainings.id' => 'DESC'])
            ->first();

        return $ent_training;
    }

    
    // OBTAINS THE MOST RECENT REQUEST TO BE A THERAPIST without course
    public function previous_therapist_request_check(
        $user_id
    ){
        $this->loadModel('SpaLiveV1.DataTrainings');

        $training_iv = $this->get_course_therapist();

        $ent_training = $this->DataTrainings->find()
            ->where([
                'DataTrainings.user_id' => $user_id,
                'DataTrainings.training_id' => $training_iv->id,
                'DataTrainings.deleted' => 0,
            ])
            ->order(['DataTrainings.id' => 'DESC'])
            ->first();

        return $ent_training;
    }

    // OBTAINS THE STATUS OF THE REQUEST TO BE A THERAPIST
    /*
        PENDING  = 0️⃣ , The request was created but not reviewed yet
        ACCEPTED = 1️⃣ , The request was accepted and the user is a Therapist
        REJECTED = 2️⃣ , The request was rejected and the user is not a Therapist
    */
    public function request_status(
        $data_training
    ){
        if($data_training->attended == 1){
            return 'ACCEPTED';
        }else if($data_training->attended == 2){
            return 'REJECTED';           
        }else{
            return 'PENDING';
        }
    }

    public function get_status_for_login($user_id){

        $ent_iv_training = $this->iv_application($user_id);

        if(empty($ent_iv_training)){
            return "HAS NOT APPLIED";
        }else{
            $license = $this->user_has_approved_license($user_id);

            if($license['has_valid_license']){

                if($ent_iv_training["DataTrainings"]["attended"]==0){
                    return "PENDING";
                }else if($ent_iv_training["DataTrainings"]["attended"]==1){
                    return "ACCEPTED";
                }else if($ent_iv_training["DataTrainings"]["attended"]==2){
                    return "REJECTED";
                }
                
            }else{
                return "INVALID LICENSE";
            }

        }

    }

    /**
     * IV therapy: IVT / IV THERAPY subscription rows, else consult_iv_application() (same rules as get_iv_application).
     *
     * @param int $user_id Injector user id
     * @param array<\Cake\Datasource\EntityInterface>|null $subscriptions Pre-loaded data_subscriptions for user (newest first). Pass null to load.
     */
    public function injectorHasIvTherapyIndicators(int $user_id, ?array $subscriptions = null): bool
    {
        if ($subscriptions === null) {
            $this->loadModel('SpaLiveV1.DataSubscriptions');
            $subscriptions = $this->DataSubscriptions->find()
                ->where([
                    'DataSubscriptions.user_id' => $user_id,
                    'DataSubscriptions.deleted' => 0,
                ])
                ->order(['DataSubscriptions.id' => 'DESC'])
                ->toArray();
        }

        foreach ($subscriptions as $sub) {
            if ($this->dataSubscriptionRowIndicatesIvTherapy($sub)) {
                return true;
            }
        }

        $consult = $this->consult_iv_application($user_id);

        return !in_array($consult, ['HAS NOT APPLIED', 'HAS NOT APPLIED WITH LICENSE'], true);
    }

    private function dataSubscriptionRowIndicatesIvTherapy($sub): bool
    {
        static $ivSubscriptionTypes = [
            'SUBSCRIPTIONMSLIVT',
            'SUBSCRIPTIONMDIVT',
            'SUBSCRIPTIONMSL+IVT',
            'SUBSCRIPTIONMD+IVT',
            'SUBSCRIPTIONMSLIVTFILLERS',
            'SUBSCRIPTIONMDIVTFILLERS',
            'SUBSCRIPTIONMSL+IVT+FILLERS',
            'SUBSCRIPTIONMD+IVT+FILLERS',
        ];

        $type = (string)($sub->subscription_type ?? '');
        if ($type !== '' && in_array($type, $ivSubscriptionTypes, true)) {
            return true;
        }
        if ($type !== '' && stripos($type, 'IVT') !== false) {
            return true;
        }
        $main = (string)($sub->main_service ?? '');
        $addons = (string)($sub->addons_services ?? '');
        if ($main !== '' && stripos($main, 'IV THERAPY') !== false) {
            return true;
        }
        if ($addons !== '' && stripos($addons, 'IV THERAPY') !== false) {
            return true;
        }

        return false;
    }

    public function get_trainings_prices(){
        
        $token = get('token',"");
        
        $token_response = $this->check_token($token);

        if(!$token_response['session']){
            $this->message("Invalid token.");
            $this->set('session', false);
            return;
        }
        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.CatTrainigs');

        $array_data = [];
        $basic_status = 'BUY';
        $ent_payments_basic = $this->DataPayment->find()->where(['DataPayment.id_from' => $token_response["user"]["user_id"], 'DataPayment.type IN' => array('BASIC COURSE', 'CI REGISTER'), 'DataPayment.payment <>' => ''])->first();
        if(!empty($ent_payments_basic))   {
            $basic_status = 'BOOK';
            $ent_training = $this->CatTrainigs->find()
                ->select(['DataTrainigs.attended'])
                ->join([
                'DataTrainigs' => ['table' => 'data_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainigs.training_id']
                ])
                ->where(['DataTrainigs.user_id' => USER_ID,'DataTrainigs.deleted' => 0,'CatTrainigs.deleted' => 0,'CatTrainigs.level = "LEVEL 1"'])
            ->first();
            if(!empty($ent_training)){
                $basic_status = 'STUDING';
            }
        }
        $array_data[] = array(
            'title'  => "Basic Neurotoxins",
            'price' => $this->training_basic,
            'status' => $basic_status,
            'type' => 'COURSEMSL',
            'course_id' => 0
        );

        $get_advanced = get('get_advanced',0);

        if($get_advanced>0){
            $advanced_status = 'BUY';
            $ent_payments_advanced = $this->DataPayment->find()->where(['DataPayment.id_from' => $token_response["user"]["user_id"], 'DataPayment.type' => 'ADVANCED COURSE', 'DataPayment.payment <>' => ''])->first();
            if(!empty($ent_payments_advanced)){
                $advanced_status = 'BOOK';
                $level_3_status = 'BUY';
                $ent_training = $this->CatTrainigs->find()
                    ->select(['DataTrainigs.attended'])
                    ->join([
                    'DataTrainigs' => ['table' => 'data_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainigs.training_id']
                    ])
                    ->where(['DataTrainigs.user_id' => USER_ID,'DataTrainigs.deleted' => 0,'CatTrainigs.deleted' => 0,'CatTrainigs.level = "LEVEL 2"'])
                ->first();
                if(!empty($ent_training)){
                    $advanced_status = 'STUDING';
                }

                $ent_payments_level3 = $this->DataPayment->find()->where(['DataPayment.id_from' => $token_response["user"]["user_id"], 'DataPayment.type' => 'ADVANCED TECHNIQUES MEDICAL', 'DataPayment.payment <>' => ''])->first();

                if(!empty($ent_payments_level3)){
                    $level_3_status = 'BOOK';
                    $ent_training = $this->CatTrainigs->find()
                        ->select(['DataTrainigs.attended'])
                        ->join([
                        'DataTrainigs' => ['table' => 'data_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainigs.training_id']
                        ])
                        ->where(['DataTrainigs.user_id' => USER_ID,'DataTrainigs.deleted' => 0,'CatTrainigs.deleted' => 0,'CatTrainigs.level = "LEVEL 3 MEDICAL"'])
                    ->first();
                    if(!empty($ent_training)){
                        $level_3_status = 'STUDING';
                    }
                }
            }

            $array_data[] = array(
                'title'  => "Advanced Neurotoxins",
                'price' => $this->training_advanced,
                'status' => $advanced_status,
                'type' => 'COURSEMSL',
                'course_id' => 0
            );

            if(!empty($ent_payments_advanced)){
                $array_data[] = array(
                    'title'  => "Neurotoxin Level 3",
                    'price' => $this->level_3_medical,
                    'status' => $level_3_status,
                    'type' => 'COURSEMSL',
                    'course_id' => 0
                );
            }
        }

        // list other courses

        $this->loadModel('SpaLiveV1.CatCoursesType');

        // Obtener niveles dinámicos disponibles
        $dynamic_levels = $this->CatCoursesType->find()
        ->select(['CatCoursesType.title', 'CatCoursesType.price', 'CatCoursesType.id','data_training_id' => 'DataTrainigs.id','data_payment_id' => 'DataPayment.id','CatCoursesType.require_msl_basic'])
        ->join([
            'DataPayment' => ['table' => 'data_payment', 'type' => 'LEFT', 'conditions' => 'DataPayment.id_from = ' . USER_ID . ' AND DataPayment.type = CatCoursesType.name_key AND DataPayment.payment <> "" AND DataPayment.is_visible = 1'],
            'CatTrainigs' => ['table' => 'cat_trainings', 'type' => 'LEFT', 'conditions' => 'CatCoursesType.name_key = CatTrainigs.level AND CatTrainigs.deleted = 0'],
            'DataTrainigs' => ['table' => 'data_trainings', 'type' => 'LEFT', 'conditions' => 'CatTrainigs.id = DataTrainigs.training_id AND DataTrainigs.deleted = 0 AND DataTrainigs.user_id = ' . USER_ID],
        ])
        ->where(['CatCoursesType.deleted' => 0, 'CatCoursesType.available' => 1])->group(['CatCoursesType.id'])
        ->all();
        
        foreach($dynamic_levels as $dynamic_level) {

            if ($dynamic_level->require_msl_basic == 1) {
                $hasBasicCourse = CourseController::validateBasicTraining($this);
                if (!$hasBasicCourse) continue;
            }

            $status = 'BUY';

            if (!empty($dynamic_level->data_payment_id)) {
                $status = 'BOOK';
            }

            if (!empty($dynamic_level->data_training_id)) {
                $status = 'STUDING';
            }

            $array_data[] = [
                'title' => $dynamic_level->title,
                'price' => $dynamic_level->price,
                'status' => $status,
                'type' => 'OTHERCOURSE',
                'course_type_id' => $dynamic_level->id
            ];

        }

        $this->set('data', $array_data);
    }

    public function user_has_approved_license($user_id){

        $this->loadModel('SpaLiveV1.SysLicences');

        $license = $this->SysLicences->find()
        ->where([
            'SysLicences.user_id' => $user_id,
            'SysLicences.deleted' => 0,
            'SysLicences.status' => 'APPROVED',
            'SysLicences.type IN' => $this->allowed_license_types
        ])
        ->first();

        $has_valid_license = false;

        if(!empty($license)) {
            $has_valid_license = true;
        }else{
            $license = $this->SysLicences->find()
            ->where([
                'SysLicences.user_id' => $user_id,
                'SysLicences.deleted' => 0,
                'SysLicences.type IN' => $this->allowed_license_types
            ])
            ->last();
        }

        $license = array(
            'has_valid_license' => $has_valid_license,
            'license' => $license
        );

        return $license;
        
    }

    public function know_subscription_to_sign(){
        $this->loadModel('SpaLiveV1.DataCourses');
        $this->loadModel('SpaLiveV1.DataSubscriptions');  
        $token = get('token',"");
        
        $token_response = $this->check_token($token);

        if(!$token_response['session']){
            $this->message("Invalid token.");
            $this->set('session', false);
            return;
        }

        $source = get('source',"");

        $order = "CASE WHEN (Course.type = 'NEUROTOXINS BASIC' OR Course.type = 'NEUROTOXINS ADVANCED' OR Course.type = 'BOTH NEUROTOXINS') then 1 else 0 END DESC";

        $ent_course = $this->DataCourses->find()->select(['Course.title', 'Course.type'])
        ->join([
            'Course' => ['table' => 'cat_courses','type' => 'INNER','conditions' => 'Course.id = DataCourses.course_id'],
        ])
        ->where(
            ['DataCourses.user_id' => $token_response["user"]["user_id"], 'DataCourses.deleted' => 0, 'DataCourses.status' => "DONE"]
        )->order([$order])->first();

        if(!empty($ent_course)){
            /*
            $FC = new FillersController();
            $has_fillers = $FC->has_fillers_certificate($token_response["user"]["user_id"]);

            $subscription = $has_fillers 
                ? "Fillers"
                : "Other Schools";*/
            $subscription = $ent_course["Course"]["type"] == 'FILLERS' ? "Fillers" : "Other Schools";

            $this->set('subscription', $subscription);
            $this->success(); 
        }else{
            //tiene ya subscripción activa?
            $iv_therapy_application = false;
            $neurotoxin_application = false;

            $_where = ['DataSubscriptions.status' => 'ACTIVE','DataSubscriptions.deleted' => 0,
                       'DataSubscriptions.user_id' => $token_response["user"]["user_id"], 'DataSubscriptions.subscription_type' => "SUBSCRIPTIONMSL"];

            $subscription = $this->DataSubscriptions->find()
            ->where($_where)->first();

            if(!empty($subscription)){
                //si tiene subscripción activa es porque ya firmo neurotoxin
                $neurotoxin_application = true;
            }

            //checar si ya le aprobaron la aplicación de iv therapy para la suscripción
            $iv_therapy = $this->iv_therapy_application($token_response["user"]["user_id"]);

            if(!empty($iv_therapy)){
                $iv_therapy_application = true;                
            }

            if($iv_therapy_application&&$neurotoxin_application){
                $this->set('subscription', "IV Therapy + Neurotoxin");
            }else if($iv_therapy_application&&$neurotoxin_application == false){
                $this->set('subscription', "IV Therapy");
            }else if($iv_therapy_application == false&&$neurotoxin_application == false){
                $this->set('subscription', "Neurotoxin"); 
            }

            $this->success(); 

        }

    }

    public function know_subscription(){
        $this->loadModel('SpaLiveV1.DataSubscriptions');  
        $token = get('token',"");
        
        $token_response = $this->check_token($token);

        if(!$token_response['session']){
            $this->message("Invalid token.");
            $this->set('session', false);
            return;
        }else{
            $this->set('session', true);
        }

        $_where = ['DataSubscriptions.status' => 'ACTIVE','DataSubscriptions.deleted' => 0,
                       'DataSubscriptions.user_id' => $token_response["user"]["user_id"]];

        $subscription = $this->DataSubscriptions->find()
        ->where($_where)->first();

        if(!empty($subscription)){

            if($subscription->subscription_type=="SUBSCRIPTIONMSL"||$subscription->subscription_type=="SUBSCRIPTIONMD"){
                $this->set('subscription', "Neurotoxin");
            }else if($subscription->subscription_type=="SUBSCRIPTIONMSLIVT"||$subscription->subscription_type=="SUBSCRIPTIONMDIVT"){
                $this->set('subscription', "IV Therapy");
            }else if($subscription->subscription_type=="SUBSCRIPTIONMSL+IVT"||$subscription->subscription_type=="SUBSCRIPTIONMD+IVT"){
                $this->set('subscription', "IV Therapy + Neurotoxin");
            }else{
                $this->set('subscription', "No Injector Subscription");
            }

        }else{
            $this->set('subscription', "No Subscription");
        }

        $this->success();

    }

    public function iv_therapy_application(
        $user_id
    ){
        $this->loadModel('SpaLiveV1.DataTrainings');

        $training_iv = $this->get_course_therapist();

        $ent_training = $this->DataTrainings->find()
            ->where([
                'DataTrainings.user_id' => $user_id,
                'DataTrainings.training_id' => $training_iv->id,
                'DataTrainings.attended' => 1,
            ])
            ->order(['DataTrainings.id' => 'DESC'])
            ->first();

        return $ent_training;
    }

    public function approved_ivtherapy($ent_user){
        $this->loadModel('SpaLiveV1.DataTrainings');
        $this->loadModel('SpaLiveV1.DataRequestGfeCi');
        $this->log(__LINE__ . ' ' . json_encode('approved_ivtherapy'));
        $ent_req = $this->DataRequestGfeCi->find()->where(['DataRequestGfeCi.user_id' => $ent_user])->first();
        $this->log(__LINE__ . ' ' . json_encode($ent_req));
        if(empty($ent_req)) return;        
        
        if($ent_req->status == "READY"){
            $training  = $this->previous_therapist_request($ent_user);
            if(empty($training)) return;
            $this->log(__LINE__ . ' ' . json_encode($training));
            $this->DataTrainings->updateAll(
                ['attended' => 1],
                ['id' => $training['id']]
            );                    
        }                
        return;
    }

    
    public function iv_video_watched_register(){
        $this->loadModel('SpaLiveV1.DataWatchedVideos');

        $token = get('token',"");

        $type = get('type',"");

        if(empty($type)){
            $this->message('Need video type');
            return;
        }
        
        $token_response = $this->check_token($token);

        if(!$token_response['session']){
            $this->message("Invalid token.");
            $this->set('session', false);
            return;
        }else{
            $this->set('session', true);
        }

        $array_save = array(
            'user_id' => $token_response["user"]["user_id"],
            'type' => $type,
            'deleted' => 0
        );   

        $ent_training = $this->DataWatchedVideos->newEntity($array_save);
        
        if(!$this->DataWatchedVideos->save($ent_training)){
            $this->message('Error when trying to save your record.');
            return;
        }else{
            $this->success();
        }
    }

    public function consult_iv_application($user_id){
        $ent_iv_training = $this->iv_application($user_id);

        if(empty($ent_iv_training)){

            $license = $this->user_has_approved_license($user_id);

            if($license['has_valid_license']){
                return "HAS NOT APPLIED WITH LICENSE";
            }else{
                return "HAS NOT APPLIED";
            }
            
        }else{
            
            if($ent_iv_training["DataTrainings"]["attended"]==0){
                return "PENDING";
            }else if($ent_iv_training["DataTrainings"]["attended"]==1){

                $license = $this->user_has_approved_license($user_id);

                if($license['has_valid_license']){

                    $subscriptions = $this->get_subscription_status($user_id);

                    if($subscriptions=="ACCEPTED"){

                        /* $video_watched = $this->get_video_watched($user_id);
                        
                        if($video_watched){ */

                        $treatments = $this->get_iv_treatments($user_id);

                        if($treatments){
                            return "ACCEPTED";
                        }else{
                            return "TREATMENTS SETTINGS";
                        }   
                        /* }else{
                            return "TRAINING VIDEO";
                        } */

                    }else{
                        return $subscriptions;
                    }
                    
                }else{
                    if(!empty($license['license'])){
                        if($license['license']['status']=="PENDING"){
                            return "PENDING";
                        }
                    }
                    return "REJECTED";
                }

            }else if($ent_iv_training["DataTrainings"]["attended"]==2){
                return "REJECTED";
            }
                
        }

        return 'HAS NOT APPLIED';
    }

    public function get_iv_application(){
        $token = get('token',"");
        $panel = get('panel',"");
        if(empty($panel) || (!empty($panel) && $panel != '6092482f7ce858.91169218')){
            $token_response = $this->check_token($token);
        } else {
            $token_response["user"]["user_id"] = get('user_id', 0);
            $token_response['session'] = true;
        }

        if(!$token_response['session']){
            $this->message("Invalid token.");
            $this->set('session', false);
            return;
        }else{
            $this->set('session', true);
        }

        $ent_iv_training = $this->iv_application($token_response["user"]["user_id"]);

        if(empty($ent_iv_training)){

            $license = $this->user_has_approved_license($token_response["user"]["user_id"]);

            if($license['has_valid_license']){
                $this->set('status', "HAS NOT APPLIED WITH LICENSE");
            }else{
                $this->set('status', "HAS NOT APPLIED");
            }
        }else{
            
            if($ent_iv_training["DataTrainings"]["attended"]==0){
                $this->set('status', "PENDING");
            }else if($ent_iv_training["DataTrainings"]["attended"]==1){

                $license = $this->user_has_approved_license($token_response["user"]["user_id"]);

                if($license['has_valid_license']){

                    $subscriptions = $this->get_subscription_status($token_response["user"]["user_id"]);

                    if($subscriptions=="ACCEPTED"){

                        // $video_watched = $this->get_video_watched($token_response["user"]["user_id"]);
                        
                        // if($video_watched){

                        $treatments = $this->get_iv_treatments($token_response["user"]["user_id"]);

                        if($treatments){
                            $this->set('status', "ACCEPTED");
                            $this->set('exp_date', $license['license']['exp_date']);
                            $this->loadModel('SpaLiveV1.DataTrainings');
                            $ent_trainings = $this->DataTrainings->find()->select(['DataTrainings.id','Training.title','Training.scheduled','Training.id','Training.level'])
                            ->join([
                                'Training' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'Training.id = DataTrainings.training_id']
                            ])->where(['Training.deleted' => 0, 'DataTrainings.user_id' => $token_response["user"]["user_id"],'DataTrainings.deleted' => 0,'DataTrainings.attended' => 1,'Training.level' => 'LEVEL IV'])->order(['Training.scheduled' => 'DESC'])->first();
                            if(!empty($ent_trainings)){
                                $this->set('certificate_id', $ent_trainings['id']);
                                 $this->set('data_training_id', $ent_trainings['id']);
                                $this->set('training_id', $ent_trainings['Training']['id']);
                            }else{
                                $this->set('certificate_id', 0);
                            }
                        }else{
                            $this->set('status', "TREATMENTS SETTINGS");
                        }
                            
                        /* }else{
                            $this->set('status', "TRAINING VIDEO");
                        } */

                    }else{
                        $this->set('status', $subscriptions);

                        $this->loadModel('SpaLiveV1.DataTrainings');
                        $ent_trainings = $this->DataTrainings->find()->select(['DataTrainings.id','Training.title','Training.scheduled','Training.id','Training.level'])
                        ->join([
                            'Training' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'Training.id = DataTrainings.training_id']
                        ])->where(['Training.deleted' => 0, 'DataTrainings.user_id' => $token_response["user"]["user_id"],'DataTrainings.deleted' => 0,'DataTrainings.attended' => 1,'Training.level' => 'LEVEL IV'])->order(['Training.scheduled' => 'DESC'])->first();
                        if(!empty($ent_trainings)){
                            $this->set('certificate_id', $ent_trainings['id']);
                            $this->set('data_training_id', $ent_trainings['id']);
                            $this->set('training_id', $ent_trainings['Training']['id']);
                        }else{
                            $this->set('certificate_id', 0);
                            $this->set('training_id', 0);
                            $this->set('data_training_id', 0);
                        }
                    }
                    
                }else{
                    
                    if($license['license']['status']=="PENDING"){
                        $this->set('status', "PENDING");
                    }else{
                        $this->set('status', "REJECTED");
                    }
                    
                }

            }else if($ent_iv_training["DataTrainings"]["attended"]==2){
                $this->set('status', "REJECTED");
            }
                
        }

        $this->success();
    }

    
    public function get_nt_courses(){
        $token = get('token',"");
        
        $token_response = $this->check_token($token);

        if(!$token_response['session']){
            $this->message("Invalid token.");
            $this->set('session', false);
            return;
        }else{
            $this->set('session', true);
        }
        
        $this->loadModel('SpaLiveV1.DataCourses');

        $courses =  $this->DataCourses->find()->where(['DataCourses.user_id' => $token_response["user"]["user_id"], 'DataCourses.deleted' => 0])->first();
        
        if(empty($courses)){
            $this->set('ntstatus', "NO COURSES");

        } else {
            $this->set('course_id', $courses['id']);
            if($courses['status'] == 'PENDING'){
                $this->set('ntstatus', "PENDING");

            } else if($courses['status'] == 'DONE'){
                $this->set('ntstatus', "DONE");

            } else {
                $this->set('ntstatus', "REJECTED");

            }
        }

        $this->success();
    }

    public function get_video_watched($user_id){
        $this->loadModel('SpaLiveV1.DataWatchedVideos');
        $entivt =  $this->DataWatchedVideos->find()->where(['DataWatchedVideos.user_id' => $user_id, 
                                                            'DataWatchedVideos.type' => "IV THERAPY",
                                                            'DataWatchedVideos.deleted' => 0])->first();

        if(!empty($entivt)){
            return true;
        }else{
            return false;
        }
    }

    public function get_iv_treatments($user_id){
        $this->loadModel('SpaLiveV1.CatTreatmentsCi');
        $this->loadModel('SpaLiveV1.CatTreatmentsCategory');

        $cat_category = $this->CatTreatmentsCategory->find()
            ->where(['CatTreatmentsCategory.deleted' => 0, 'CatTreatmentsCategory.type' => 'IV THERAPY'])->first();

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

    public function get_subscription_status($user_id){
        $this->loadModel('SpaLiveV1.DataSubscriptions');

        $Subscription = new SubscriptionController();
        $has_msl = $Subscription->has_service_subscription(
            $user_id,
            'IV THERAPY',
            'MSL'
        );

        if($has_msl){
            $has_md = $Subscription->has_service_subscription(
                $user_id,
                'IV THERAPY',
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

    public function get_subscription_nt_status($user_id){
        $this->loadModel('SpaLiveV1.DataSubscriptions');

        $msl_subscription = $this->DataSubscriptions->find()
        ->where([
            'DataSubscriptions.status' => 'ACTIVE', 'DataSubscriptions.user_id' => $user_id, 'DataSubscriptions.deleted' => 0, 
            'DataSubscriptions.subscription_type IN' => ['SUBSCRIPTIONMSL', 'SUBSCRIPTIONMSL']
        ])
        ->first();

        if(!empty($msl_subscription)){
            $md_subscription = $this->DataSubscriptions->find()
            ->where([
                'DataSubscriptions.status' => 'ACTIVE', 'DataSubscriptions.user_id' => $user_id, 'DataSubscriptions.deleted' => 0, 
                'DataSubscriptions.subscription_type IN' => ['SUBSCRIPTIONMD', 'SUBSCRIPTIONMD']
            ])
            ->first();

            if(!empty($md_subscription)){
                return "ACCEPTED";
            }else{
                return "MISSING MD SUBSCRIPTION";
            }
        }else{
            return "MISSING MSL SUBSCRIPTION";
        }   

    }

    public function iv_application($user_id){
        $this->loadModel('SpaLiveV1.CatTrainings');

        $ent_iv_training = $this->CatTrainings->find()
            ->select(['DataTrainings.attended'])
            ->join([
                'DataTrainings' => ['table' => 'data_trainings',
                'type' => 'INNER', 
                'conditions' => 'DataTrainings.training_id = CatTrainings.id'],
            ])
            ->where([
                'CatTrainings.level' => 'LEVEL IV', 'DataTrainings.user_id' => $user_id, 'DataTrainings.deleted' => 0
            ])
            ->first();

        return $ent_iv_training;
    }

    
    public function nt_application($user_id){
        $this->loadModel('SpaLiveV1.CatTrainings');

        $ent_iv_training = $this->CatTrainings->find()
            ->select(['DataTrainings.attended'])
            ->join([
                'DataTrainings' => ['table' => 'data_trainings',
                'type' => 'INNER', 
                'conditions' => 'DataTrainings.training_id = CatTrainings.id'],
            ])
            ->where([
                'CatTrainings.level IN' => ['LEVEL 1', 'LEVEL 2'], 'DataTrainings.user_id' => $user_id, 'DataTrainings.deleted' => 0
            ])
            ->first();

        return $ent_iv_training;
    }

    public function re_apply_for_iv(){
        $this->loadModel('SpaLiveV1.DataTrainings');
        $token = get('token',"");

        $is_registering = get('is_registering',"0");
        
        $token_response = $this->check_token($token);

        if(!$token_response['session']){
            $this->message("Invalid token.");
            $this->set('session', false);
            return;
        }else{
            $this->set('session', true);
        }

        $ent_iv_training = $this->previous_therapist_request($token_response["user"]["user_id"]);

        $valid_license = false;

        $license = $this->user_has_approved_license($token_response["user"]["user_id"]);

        if($license['has_valid_license']){
            $valid_license = true;
        }

        if(empty($ent_iv_training)){
            
            $training_iv = $this->get_course_therapist(); 

            $array_save = array(
                'user_id' => $token_response["user"]["user_id"],
                'training_id' => $training_iv->id,        
            );

            if($valid_license){
                $this->set("message_response", "Your application for IV Therapy has already been approved, complete the following steps to be able to apply IV Therapy treatments.");
                $array_save['attended'] = 1;
            }else{
                $this->set("message_response", "Request to be a Therapist sent successfully.");

                #region 
                // Cuando un examiner quiere ser GFECI se crea este registro en la tabla DataAssignedToRegister, para que pueda ser aceptado en el modulo de GFECI
                if (USER_TYPE == "examiner") {
                    $this->loadModel('SpaLiveV1.DataRequestGfeCi');

                    $requestItem = $this->DataRequestGfeCi->find()->where(['DataRequestGfeCi.user_id' => $token_response["user"]["user_id"]])->first();
                    if(empty($requestItem)){

                            $request_save = [
                            'user_id' => $token_response["user"]["user_id"],
                            'created' => date('Y-m-d H:i:s'),
                            'status' => 'INIT',
                        ];

                        $entRequestSave = $this->DataRequestGfeCi->newEntity($request_save);
                        if(!$entRequestSave->hasErrors()){
                            $this->DataRequestGfeCi->save($entRequestSave);
                        }

                    }
                }
                #endregion

                //si se esta registrando se cambia el paso a waitingivapproval
                if($is_registering){
                    $this->loadModel('SpaLiveV1.SysUsers');
                    $ent_user = $this->SysUsers->find()->where(['id' => $token_response["user"]["user_id"]])->first();
                    $ent_user->steps = "WAITINGIVAPPROVAL";
                    if(!$this->SysUsers->save($ent_user)){
                        $this->message('Error when trying to process application for iv therapy.');
                        return;
                    }
                }

            }

            $ent_training = $this->DataTrainings->newEntity($array_save);
            if(!$this->DataTrainings->save($ent_training)){
                $this->message('Error when trying to process application for iv therapy.');
                return;
            }else{
                $this->success();
            }
            
        }else{

            if($valid_license){
                $this->set("message_response", "Your application for IV Therapy has already been approved, complete the following steps to be able to apply IV Therapy treatments.");
                $ent_iv_training->attended = 1;
            }else{
                $this->set("message_response", "Request to be a Therapist sent successfully.");
                $ent_iv_training->attended = 0;

                //si se esta registrando se cambia el paso a waitingivapproval
                if($is_registering){
                    $this->loadModel('SpaLiveV1.SysUsers');
                    $ent_user = $this->SysUsers->find()->where(['id' => $token_response["user"]["user_id"]])->first();
                    $ent_user->steps = "WAITINGIVAPPROVAL";
                    if(!$this->SysUsers->save($ent_user)){
                        $this->message('Error when trying to process application for iv therapy.');
                        return;
                    }
                }
            }

            $update = $this->DataTrainings->save($ent_iv_training);

            if($update){
                $this->success();
            }else{
                $this->message('Error when trying to process application for iv therapy.');
                return;
            }
        }
    }

    public function send_email_iv_therapy_app(){
        $message = get('message', '');
        $user_id = get('user_id', 0);
        /* $contains = get('contains', array());

        if ($contains != '[]') {
            $contains = json_decode($contains);
        } else {
            $contains = array();
        } */

        $Main = new MainController();
        $Login = new LoginController();
        $Main->notify_devices($message,array($user_id),true,true,true,array(),'',array(),true);
        $is_dev = env('IS_DEV', false);
        // correo para Jenna
        if(!$is_dev){
            $this->loadModel('SpaLiveV1.SysUsers');
            $user = $this->SysUsers->find()->where(['id' => $user_id])->first();
            $body = "A new user has been accepted for IV Therapy : " . $user->name . " " . $user->lname . " (" . $user->phone .").";
            $Login->send_email_after_register("jenna@myspalive.com", 'A new user has been accepted for IV Therapy.', $body);
            try {
                $phone_number = env('TWILIO_PHONE_NUMBER');
                $sid    = env('TWILIO_ACCOUNT_SID'); 
                $token  = env('TWILIO_AUTH_TOKEN'); 
                $twilio = new Client($sid, $token);                  
                $twilio_message = $twilio->messages 
                          ->create($phone_number, // to 
                                   array(  
                                       "messagingServiceSid" => "MG65978a5932f4ba9dd465e05d7b22195e",      
                                       "body" => $body 
                                   ) 
                          ); 
                $account_sid = $twilio_message->accountSid;                                
             } catch (TwilioException $e) {
                $this->log(__LINE__ . " TwilioException ". $phone_number . " ". $body. " ". json_encode($e->getCode()));
             }
        }
    }

    public function send_email_to_pharmacy($user, $iv_products, $purchase_id){

        $this->loadModel('DataPurchases');
        $this->loadModel('DataPurchaseDetailIv');

        $purchase = $this->DataPurchases->find()
        ->select(['DataPurchases.id', 'state' => 'State.abv', 'DataPurchases.address', 'DataPurchases.suite', 'DataPurchases.city', 'DataPurchases.zip'])
        ->join([
            'State' => [
                'table' => 'cat_states',
                'type' => 'INNER',
                'conditions' => 'State.id = DataPurchases.state'
            ]
        ])
        ->where(['DataPurchases.id' => $purchase_id])->first();
        
        if(!empty($purchase->suite)){
            $shipping_address = $purchase->address . ', ' . $purchase->suite . ', ' . $purchase->city . ', ' . $purchase->state . ' ' . $purchase->zip;
        }else{
            $shipping_address = $purchase->address . ', ' . $purchase->city . ', ' . $purchase->state . ' ' . $purchase->zip;
        }

        $patient_detail = $this->DataPurchaseDetailIv->find()->where(['purchase_id' => $purchase_id])->first();

        $html_content = $this->pharmacy_pdf($user, false, $iv_products, $patient_detail, $shipping_address);

        $inyector_name = $user->mname == '' ? trim($user->name).' '.trim($user->lname) : trim($user->name).' '.trim($user->mname).' '.trim($user->lname);

        $is_test_patient = $this->check_test($inyector_name);
        
        $filename = TMP . 'files' . DS . 'pharmacy_pdf.pdf';
        $html2pdf = new HTML2PDF('P','A4','en', true, 'UTF-8', array(10, 10, 10, 10));
        $html2pdf->WriteHTML($html_content);
        $html2pdf->Output($filename, 'F');

        // Create pdf for the iv form
        $html_content2 = $this->drug_crafters_application_pdf($user);
        $filename2 = TMP . 'files' . DS . 'pharmacy_form_pdf.pdf';
        $html2pdf2 = new HTML2PDF('P','Letter','en', true, 'UTF-8', array(0, 0, 0, 0));
        $html2pdf2->WriteHTML($html_content2);
        $html2pdf2->Output($filename2, 'F');

        $data = array(
            'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
            'subject' => 'Nutrition Form',
            'html'    => 'The purchase order is attached.<br><br>Thank you.',
            'attachment[1]' => curl_file_create($filename, 'application/pdf', 'Nutrition Form.pdf'),
            'attachment[2]' => curl_file_create($filename2, 'application/pdf', 'Pharmacy Application Form.pdf'),
        );

        //$data_credit = array();

        $is_dev = env('IS_DEV', false);
        if($is_dev){
            $data['to'] = "francisco@advantedigital.com, john@advantedigital.com, carlos@advantedigital.com";
            //$data_credit['to'] = "francisco@advantedigital.com";
        }else{

            if ($is_test_patient) {
                $data['to'] = "francisco@advantedigital.com";
                //$data_credit['to'] = "francisco@advantedigital.com";
            }else{
                $data['to'] = "francisco@advantedigital.com, areedy@drugcrafters.com";
                //$data_credit['to'] = "francisco@advantedigital.com";
                //$data['to'] = 'DFairleigh@drugcrafters.com';
                //$data_credit['to'] = "DFairleigh@drugcrafters.com";
            }
        }

        /*$send_credit_form = $this->purchased_iv_therapy($user, $purchase_id);

        if($send_credit_form){
            $html_credit_content = $this->credit_pdf($user, false);
        
            $filename_credit = TMP . 'files' . DS . 'credit_pdf.pdf';
            $html2pdf = new HTML2PDF('P','A4','en', true, 'UTF-8', array(10, 10, 10, 10));
            $html2pdf->WriteHTML($html_credit_content);
            $html2pdf->Output($filename_credit, 'F');

            $data_credit['from'] = 'MySpaLive <noreply@mg.myspalive.com>';
            $data_credit['subject'] = 'Credit Form';
            $data_credit['html'] = 'A new credit form is attached.<br><br>Thank you.';
            $data_credit['attachment[1]'] = curl_file_create($filename_credit, 'application/pdf', 'Credit Form.pdf');

            $mailgunKey = $this->getMailgunKey();

            $curl_credit = curl_init();
            curl_setopt($curl_credit, CURLOPT_URL, 'https://api.mailgun.net/v3/mg.myspalive.com/messages');
            curl_setopt($curl_credit, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($curl_credit, CURLOPT_USERPWD,'api:' . $mailgunKey);
            curl_setopt($curl_credit, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl_credit, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($curl_credit, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($curl_credit, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($curl_credit, CURLOPT_POST, true); 
            curl_setopt($curl_credit, CURLOPT_POSTFIELDS, $data_credit);
            curl_setopt($curl_credit, CURLOPT_HEADER, true);
            curl_setopt($curl_credit, CURLOPT_HTTPHEADER, ["Content-Type:multipart/form-data"]);
            
            $result_credit = curl_exec($curl_credit);
            
            curl_close($curl_credit);
        }*/

        /*$array_response = ['data' => $data, 'credit_from' => $send_credit_form, 'data_credit' => $data_credit];

        return $array_response;*/

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

    public function check_test($name): bool {

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

    public function check_iv_products($iv_products, $id_to_find){
        $check = false;
        $qty = 0;
        $item_description = "";

        foreach($iv_products as $p){
            if($p['Product']['id'] == $id_to_find){
                $check = true;
                $qty = $p->qty;
                $item_description = $p['Product']['item_description'];
            }
        }

        return $response = array(
            'check' => $check,
            'qty' => $qty,
            'item_description' => $item_description,
        );
    }

    public function credit_pdf($user, $padding){
        $clinic = $user['name'].' '.$user['lname'];
        $clinic_phone = $user['phone'];
        $clinic_fax = "";
        $clinic_address = $user['street'].'. '.$user['city'].', TX '.$user['zip'];
        $clinic_prescriber = "";
        $clinic_dea = "";
        $clinic_license = "";
        $clinic_website = "";
        $clinic_email = "";

        $html = "<page>";
        if($padding){
            $html .= "<div style=\"width: 200mm; height: 277mm; position:relative; color: #373a48; padding: 5mm\">";
        }else{
            $html .= "<div style=\"width: 200mm; height: 277mm; position:relative; color: #373a48;\">";
        }

        $html .= "<div style=\"margin-left: 5mm;\">

                    <table style=\"margin-top: 2.5mm; text-align: center; width: 100%; display: inline; \">
                        <tbody>
                            <tr>
                                <td>
                                    <table style=\"text-align: left;\">
                                        <tbody>
                                            <tr>
                                                <td> <div style=\"width: 60mm;\"> <img style=\"width: 35mm;\" src=\"{$this->URL_API}img/empower_pharmacy.png\"> </div> </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                                <td>
                                    <table style=\"text-align: center; width: 115mm; font-size: 25px;\">
                                        <tbody>
                                            <tr>
                                                <td style=\"width: 98%; text-align: right;\"> <b> CREDIT FORM </b> </td>
                                                <td style=\"width: 2%; text-align: right; background-color: rgb(0, 82, 201);\" rowspan='2'> </td>
                                            </tr>
                                            <tr>
                                                <td style=\"width: 98%; text-align: right;\"> CHARGE AUTHORIZATION FORM </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <table style=\"font-size: 14px; margin-top: 10mm; text-align: left; width: 180mm;\">
                        <tbody>
                            <tr>
                                <td style=\"width: 100%;\"> <span>I (we) hereby authorize EMPOWER PHARMACY to make recurring charges to my Credit Card listed below, and, if necessary, initiate adjustments for any transactions credited/debited in error. </span> </td>
                            </tr>
                        </tbody>
                    </table>

                    <table style=\"font-size: 14px; margin-top: 5mm; text-align: left; width: 180mm;\">
                        <tbody>
                            <tr>
                                <td style=\"width: 100%;\"> <span>This authority will remain in effect until EMPOWER PHARMACY is notified by me (us) in writing to cancel it in such time as to afford EMPOWER PHARMACY and/or Credit Card Company a reasonable opportunity to act on it. </span> </td>
                            </tr>
                        </tbody>
                    </table>

                    <table style=\"font-size: 14px; margin-top: 5mm; text-align: left; width: 180mm;\">
                        <tbody>
                            <tr>
                                <td style=\"width: 100%;\"> <span>All records are kept in a secure file electronically password protected and accessible to authorized personnel only. </span> </td>
                            </tr>
                        </tbody>
                    </table>

                    <table style=\"font-size: 16px; margin-top: 5mm; text-align: center; width: 180mm;\">
                        <tbody>
                            <tr>
                                <td style=\"width: 100%; border: 1.5px solid royalblue; border-radius: 5.4px; background-color: rgb(0, 82, 201); color: white;\"> <b> CLINIC INFORMATION </b> </td>
                            </tr>
                        </tbody>
                    </table>

                    <table style=\"font-size: 12px; text-align: center; width: 180mm;\">
                        <tbody>
                            <tr>
                                <td style=\"width: 50%; text-align: left; border: 1.5px solid black; border-radius: 10px;\"><div style=\"padding-top: .5mm; padding-bottom: .5mm; padding-left: 1mm;\">Clinic Name: <b style=\"font-size: 14px\">".$clinic."</b></div></td>
                                <td style=\"width: 25%; text-align: left; border: 1.5px solid black; border-radius: 10px;\"><div style=\"padding-top: .5mm; padding-bottom: .5mm; padding-left: 1mm;\">Phone: <b style=\"font-size: 14px\">".$clinic_phone."</b></div></td>
                                <td style=\"width: 25%; text-align: left; border: 1.5px solid black; border-radius: 10px;\"><div style=\"padding-top: .5mm; padding-bottom: .5mm; padding-left: 1mm;\">Fax: <b style=\"font-size: 14px\">".$clinic_fax."</b></div></td>
                            </tr>
                        </tbody>
                    </table>

                    <table style=\"font-size: 12px; text-align: center; width: 180mm;\">
                        <tbody>
                            <tr>
                                <td style=\"width: 50%; text-align: left; border: 1.5px solid black; border-radius: 10px; rowspan='2'\"><div style=\"padding-top: .5mm; padding-bottom: .5mm; padding-left: 1mm;\">Address: <b style=\"font-size: 14px\">".$clinic_address."</b></div></td>
                                <td style=\"width: 50%; text-align: left; border: 1.5px solid black; border-radius: 10px; colspan='2'\"><div style=\"padding-top: .5mm; padding-bottom: .5mm; padding-left: 1mm;\">Provider: <b style=\"font-size: 14px\">".$clinic_prescriber."</b></div></td>
                            </tr>
                            <tr>
                                <td style=\"width: 25%; text-align: left; border: 1.5px solid black; border-radius: 10px;\"><div style=\"padding-top: .5mm; padding-bottom: .5mm; padding-left: 1mm;\">DEA#: <b style=\"font-size: 14px\">".$clinic_dea."</b></div></td>
                                <td style=\"width: 25%; text-align: left; border: 1.5px solid black; border-radius: 10px;\"><div style=\"padding-top: .5mm; padding-bottom: .5mm; padding-left: 1mm;\">State License#: <b style=\"font-size: 14px\">".$clinic_license."</b></div></td>
                            </tr>
                        </tbody>
                    </table>

                    <table style=\"font-size: 12px; text-align: center; width: 180mm;\">
                        <tbody>
                            <tr>
                                <td style=\"width: 50%; text-align: left; border: 1.5px solid black; border-radius: 10px;\"><div style=\"padding-top: .5mm; padding-bottom: .5mm; padding-left: 1mm;\">Website: <b style=\"font-size: 14px\">".$clinic_website."</b></div></td>
                                <td style=\"width: 50%; text-align: left; border: 1.5px solid black; border-radius: 10px;\"><div style=\"padding-top: .5mm; padding-bottom: .5mm; padding-left: 1mm;\">Email: <b style=\"font-size: 14px\">".$clinic_email."</b></div></td>
                            </tr>
                        </tbody>
                    </table>

                    <table style=\"font-size: 12px; text-align: center; width: 180mm;\">
                        <tbody>
                            <tr>
                                <td style=\"width: 100%; text-align: left; border: 1.5px solid black; border-radius: 10px;\">
                                    <div style=\"padding-top: .5mm; padding-bottom: .5mm; padding-left: 1mm;\"> 
                                        <div style=\"width: 25%; display: inline;\"> Preferred Method of Contact: </div> 
                                        <div style=\"width: 74%; display: inline; \"> 
                                            <div style=\"width: 33%; display: inline;\"> 
                                                <div style=\"margin-left: 34%; margin-right: 1mm; width: 2%; display: inline;\"> <div style=\"border: 1.5px solid #555; border-radius: 5.4px; width: 2mm; height: 2mm; display: inline;\"> </div> </div> Phone
                                            </div>
                                            <div style=\"width: 33%; display: inline;\"> 
                                                <div style=\"margin-left: 34%; margin-right: 1mm; width: 2%; display: inline;\"> <div style=\"border: 1.5px solid #555; border-radius: 5.4px; width: 2mm; height: 2mm; display: inline;\"> </div> </div> Fax
                                            </div>
                                            <div style=\"width: 33%; display: inline;\"> 
                                                <div style=\"margin-left: 34%; margin-right: 1mm; width: 2%; display: inline;\"> <div style=\"border: 1.5px solid #555; border-radius: 5.4px; width: 2mm; height: 2mm; display: inline;\"> </div> </div> Email
                                            </div> 
                                        </div> 
                                    </div> 
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <table style=\"font-size: 16px; margin-top: 5mm; text-align: center; width: 180mm;\">
                        <tbody>
                            <tr>
                                <td style=\"width: 100%; border: 1.5px solid royalblue; border-radius: 5.4px; background-color: rgb(0, 82, 201); color: white;\"> <b> BILLING INFORMATION </b> </td>
                            </tr>
                        </tbody>
                    </table>

                    <table style=\"font-size: 12px; text-align: center; width: 180mm;\">
                        <tbody>
                            <tr>
                                <td style=\"width: 50%; text-align: left; border: 1.5px solid black; border-radius: 10px;\"><div style=\"padding-top: .5mm; padding-bottom: .5mm; padding-left: 1mm;\">Card Holders Name: </div></td>
                                <td style=\"width: 50%; text-align: left; border: 1.5px solid black; border-radius: 10px;\">
                                    <div style=\"padding-top: .5mm; padding-bottom: .5mm; padding-left: 1mm;\"> 
                                        <div style=\"width: 20%; display: inline;\">Card Type: </div>
                                        <div style=\"width: 78%; display: inline; \"> 
                                            <div style=\"width: 19%; display: inline;\"> 
                                                <div style=\"margin-right: 1mm; width: 2%; display: inline;\"> <div style=\"border: 1.5px solid #555; border-radius: 5.4px; width: 2mm; height: 2mm; display: inline;\"> </div> </div> Visa
                                            </div>
                                            <div style=\"width: 33%; display: inline;\"> 
                                                <div style=\"margin-right: 1mm; width: 2%; display: inline;\"> <div style=\"border: 1.5px solid #555; border-radius: 5.4px; width: 2mm; height: 2mm; display: inline;\"> </div> </div> Mastercard
                                            </div>
                                            <div style=\"width: 22%; display: inline;\"> 
                                                <div style=\"margin-right: 1mm; width: 2%; display: inline;\"> <div style=\"border: 1.5px solid #555; border-radius: 5.4px; width: 2mm; height: 2mm; display: inline;\"> </div> </div> Amex
                                            </div> 
                                            <div style=\"width: 25%; display: inline;\"> 
                                                <div style=\"margin-right: 1mm; width: 2%; display: inline;\"> <div style=\"border: 1.5px solid #555; border-radius: 5.4px; width: 2mm; height: 2mm; display: inline;\"> </div> </div> Discover
                                            </div> 
                                        </div> 
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <table style=\"font-size: 12px; text-align: center; width: 180mm;\">
                        <tbody>
                            <tr>
                                <td style=\"width: 50%; text-align: left; border: 1.5px solid black; border-radius: 10px;\"><div style=\"padding-top: .5mm; padding-bottom: .5mm; padding-left: 1mm;\">Card Number: </div></td>
                                <td style=\"width: 25%; text-align: left; border: 1.5px solid black; border-radius: 10px;\"> <div style=\"padding-top: .5mm; padding-bottom: .5mm; padding-left: 1mm;\">Exp. Date: </div> </td>
                                <td style=\"width: 25%; text-align: left; border: 1.5px solid black; border-radius: 10px;\"> <div style=\"padding-top: .5mm; padding-bottom: .5mm; padding-left: 1mm;\">CVV#: </div> </td>
                            </tr>
                        </tbody>
                    </table>

                    <table style=\"font-size: 12px; text-align: center; width: 180mm;\">
                        <tbody>
                            <tr>
                                <td style=\"width: 50%; text-align: left; border: 1.5px solid black; border-radius: 10px;\" rowspan='2'><div style=\"padding-top: .5mm; padding-bottom: .5mm; padding-left: 1mm;\">Billing Address <span style=\"font-size: 10px;\"> (if Different From Above) </span>: </div></td>
                                <td style=\"width: 25%; text-align: left; border: 1.5px solid black; border-radius: 10px;\"><div style=\"padding-top: .5mm; padding-bottom: .5mm; padding-left: 1mm;\">Billing Phone:</div></td>
                                <td style=\"width: 25%; text-align: left; border: 1.5px solid black; border-radius: 10px;\"><div style=\"padding-top: .5mm; padding-bottom: .5mm; padding-left: 1mm;\">Today's Date:</div></td>
                            </tr>
                            <tr>
                                <td style=\"width: 25%; text-align: left; border: 1.5px solid black; border-radius: 10px;\" colspan='2'><div style=\"padding-top: .5mm; padding-bottom: .5mm; padding-left: 1mm;\">Card Holder's Signature:</div></td>
                            </tr>
                        </tbody>
                    </table>

                    <table style=\"font-size: 14px; margin-top: 5mm; text-align: left; width: 180mm;\">
                        <tbody>
                            <tr>
                                <td style=\"width: 100%;\"> <span>It is understood that Empower Pharmacy utilizes UPS, FedEx, and USPS as methods of shipping. Empower will choose 
                                the specific shipping vendor unless a specific shipping preference is noted on the prescription. Signature required 
                                delivery will be defaulted if specific shipping instructions are not indicated on the prescription or if previously 
                                discussed and agreed upon. It is understood that by choosing non-signature required delivery, the physician and/or 
                                patient is accepting full responsibility regarding the delivery of the prescription. In the event the shipping vendor 
                                indicates a successful delivery for non-signature required package and the recipient states the package was not
                                delivered, the patient and/or clinic will be responsible for payment of a replacement order. Empower Pharmacy
                                must be notified within 48 hours of receipt of goods if any products are missing from the shipment. Shortages not
                                identified within the 48 hour window will not be subject to replacement or reimbursement. A new prescription must
                                be issued by the prescriber and additional payment will be required.</span> </td>
                            </tr>
                        </tbody>
                    </table>

                    <table style=\"font-size: 16px; margin-top: 5mm; text-align: center; width: 180mm;\">
                        <tbody>
                            <tr>
                                <td style=\"width: 100%; border: 1.5px solid royalblue; border-radius: 5.4px; background-color: rgb(0, 82, 201); color: white;\"> <b> BILLING & SHIPPING INTRUCTIONS </b> </td>
                            </tr>
                        </tbody>
                    </table>

                    <table style=\"font-size: 12px; text-align: center; width: 180mm;\">
                        <tbody>
                            <tr>
                                <td style=\"width: 50%; text-align: left; border: 1.5px solid black; border-radius: 10px;\">
                                    <div style=\"padding-top: .5mm; padding-bottom: .5mm; padding-left: 1mm;\"> 
                                        <div style=\"width: 19%; display: inline;\"> Bill To: </div> 
                                        <div style=\"width: 79.5%; display: inline;\"> 
                                            <div style=\"width: 49.5%; display: inline; padding-left: 5mm;\"> 
                                                <div style=\"margin-right: 1mm; width: 2%; display: inline;\"> <div style=\"border: 1.5px solid #555; border-radius: 5.4px; width: 2mm; height: 2mm; display: inline;\"> </div> </div> Clinic
                                            </div>
                                            <div style=\"width: 49.5%; display: inline; padding-left: 5mm;\"> 
                                                <div style=\"margin-right: 1mm; width: 2%; display: inline;\"> <div style=\"border: 1.5px solid #555; border-radius: 5.4px; width: 2mm; height: 2mm; display: inline;\"> </div> </div> Patient
                                            </div> 
                                        </div> 
                                    </div>
                                </td>
                                <td style=\"width: 50%; text-align: left; border: 1.5px solid black; border-radius: 10px;\">
                                    <div style=\"padding-top: .5mm; padding-bottom: .5mm; padding-left: 1mm;\"> 
                                        <div style=\"width: 19%; display: inline;\"> Ship To: </div> 
                                        <div style=\"width: 79.5%; display: inline;\"> 
                                            <div style=\"width: 49.5%; display: inline; padding-left: 5mm;\"> 
                                                <div style=\"margin-right: 1mm; width: 2%; display: inline;\"> <div style=\"border: 1.5px solid #555; border-radius: 5.4px; width: 2mm; height: 2mm; display: inline;\"> </div> </div> Clinic
                                            </div>
                                            <div style=\"width: 49.5%; display: inline; padding-left: 5mm;\"> 
                                                <div style=\"margin-right: 1mm; width: 2%; display: inline;\"> <div style=\"border: 1.5px solid #555; border-radius: 5.4px; width: 2mm; height: 2mm; display: inline;\"> </div> </div> Patient
                                            </div> 
                                        </div> 
                                    </div> 
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <table style=\"font-size: 12px; text-align: center; width: 180mm;\">
                        <tbody>
                            <tr>
                                <td style=\"width: 100%; text-align: left; border: 1.5px solid black; border-radius: 10px;\">
                                    <div style=\"padding-top: .5mm; padding-bottom: .5mm; padding-left: 1mm;\"> 
                                        <div style=\"width: 25%; display: inline;\"> Preferred Method of Shipping: </div> 
                                        <div style=\"width: 74%; display: inline; \"> 
                                            <div style=\"width: 49.5%; display: inline;\"> 
                                                <div style=\"margin-left: 34%; margin-right: 1mm; width: 2%; display: inline;\"> <div style=\"border: 1.5px solid #555; border-radius: 5.4px; width: 2mm; height: 2mm; display: inline;\"> </div> </div> 2nd Day
                                            </div>
                                            <div style=\"width: 49.5%; display: inline;\"> 
                                                <div style=\"margin-left: 34%; margin-right: 1mm; width: 2%; display: inline;\"> <div style=\"border: 1.5px solid #555; border-radius: 5.4px; width: 2mm; height: 2mm; display: inline;\"> </div> </div> Overnight
                                            </div>
                                        </div> 
                                    </div> 
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </page>";

        /*$html2pdf = new HTML2PDF('F','A4','en', true, 'UTF-8', array(0,0,0,0));
        $html2pdf->writeHTML($html);

        $filename = TMP . 'files' . DS . 'nutrition_form.pdf';
        
        $html2pdf->Output($filename, 'F'); // Generar el archivo PDF
        $html2pdf->Output($filename, 'I'); // Mostrar el archivo PDF en el navegador

        pr($html);
        die();
        exit;*/

        return $html;

    }

    public function pharmacy_pdf($user, $padding, $iv_products, $patient_detail = [], $shipping_address = ''){     

        /*    $products = array(
            array('id' => 0, 'name' => 'Arginine - MDV (0542)','description' => '100mg / mL | 30mL | Inj up to 30mL IV 2-3 X W'),
            array('id' => 180, 'name' => 'Arginine/Glutamine/Taurine - MDV (1157) ','description' => '5%-3%-5% | 30mL | Inj 1mL IV 1-2 X W'),
            array('id' => 0, 'name' => 'Biotin - MDV (0527)','description' => '10mg / mL | 30mL | Inj 1mL IV 1-2 X W'),
            array('id' => 0, 'name' => 'Calcium Chloride - MDV (3035)','description' => '10% | 30mL | Inj up to 5mL IV 2-3 X W'),
            //array('id' => 0, 'name' => 'Calcium EDTA - MDV','description' => '300mg / mL | 50mL | Inj up to 10mL IV 1-2 X W'),
            array('id' => 179, 'name' => 'Calcium Gluconate - MDV (0365)','description' => '100mg / mL | 30mL | Inj up to 15mL IV 2-3 X W'),
            array('id' => 121, 'name' => 'Coenzyme Q10 - MDV (0195)','description' => '20mg / mL | 30mL | Inj up to 1mL IM 2 X W'),
            array('id' => 0, 'name' => 'Dexpanthenol - MDV (0230)','description' => '250mg / mL | 30mL | Inj up to 15mL IV 2-3 X W'),
            array('id' => 0, 'name' => 'DMSO - PF (7936)','description' => '99% | 50mL | Inj up to 10mL IV 2-3 X W'),
            array('id' => 0, 'name' => 'EDTA - MDV (0207)','description' => '150mg / mL | 100mL | Inj up to 20mL IV 2-3 X W'),
            array('id' => 0, 'name' => 'Folic Acid - MDV (0644)','description' => '10mg / mL | 30mL | Inj 1-2mL IV 2-3 X W'),
            array('id' => 0, 'name' => 'Glutathione Buffered - MDV (0241)','description' => '200mg / mL | 10mL | Inj up to 15mL IV 2-3 X W'),
            array('id' => 122, 'name' => 'Glutathione Buffered - MDV (6221)','description' => '200mg / mL | 30mL | Inj up to 15mL IV 2-3 X W'),
            array('id' => 0, 'name' => 'Glutamine - MDV (0670)','description' => '30mg/mL | 30mL | Inj up to 5mL IV 2-3 X W'),
            array('id' => 0, 'name' => 'Glycine - MDV (0775)','description' => '50mg/mL | 30mL | Inj up to 10mL IV 1-2 X W'),
            array('id' => 0, 'name' => 'Hydrochloric Acid - MDV (0793)','description' => '2mg / mL | 30mL | Inj up to 10mL IV 2-3 X W'),
            array('id' => 0, 'name' => 'Hydrogen Peroxide - MDV (7582)','description' => '3% | 30mL | Inj up to 10mL IV 1-2 X W'),
            array('id' => 0, 'name' => 'Hydroxocobalamin (Vit B12A) - MDV (4796)','description' => '1mg / mL | 30mL | Inj up to 5mL IV 2-3 X W'),
            array('id' => 0, 'name' => 'Immune Cocktail Formula #2 (2060)','description' => ' | 30mL | Inj 2-5mL IV Q W'),
            array('id' => 0, 'name' => 'L - Carnitine - MDV (0611)','description' => '500mg / mL | 30mL | Inj up to 2mL IV 2-3 X W'),
            array('id' => 0, 'name' => 'Lipoic Acid Mineral Complex (LAMC) - MDV (0150)','description' => '40mL | Inj up to 40mL IV 2-3 X W'),
            array('id' => 123, 'name' => 'Lipoic Acid - MDV (0621)','description' => '25mg / mL | 30mL | Srt 1-6mL up titrate up to 24mL IV Q WK'),
            array('id' => 0, 'name' => 'Lysine - MDV (0630)','description' => '200mg/mL | 30mL | 2mL IV 2-3 X W'),
            array('id' => 124, 'name' => 'Magnesium Chloride - MDV (0201)','description' => '200mg / mL | 30mL | Inj up to 15mL IV 2-3 X W'),
            array('id' => 125, 'name' => 'Magnesium Sulfate - MDV (8755)','description' => '500mg / mL | 30mL | Inj up to 8mL IV 2-3 X W'),
            array('id' => 0, 'name' => 'Methionine / Inositol / Choline - MDV (7292)','description' => '25-50-50mg / mL | 30mL | Inj up to 5mL IV 2-3 X W'),

            array('id' => 0, 'name' => 'Methylcobalamin (Buffered) - MDV (0033)','description' => '1mg / mL | 30mL | Inj up to 5mL IV 2-3 X W'),
            array('id' => 0, 'name' => 'Methylcobalamin (Buffered) - MDV (0655)','description' => '10mg / mL | 30mL | Inj up to 2mL IV 2-3 X W'),
            array('id' => 0, 'name' => 'Methylcobalamin (Buffered) - MDV (0242)','description' => '25mg / mL | 5mL | Inj up to 2mL IV 2-3 X W'),
            array('id' => 0, 'name' => 'MIC + Carnitine + Methyl B12 (1178)','description' => ' | 30mL| Inj 5mL IV 2-3 X W'),
            array('id' => 0, 'name' => 'MIC Lipo Slim - MDV (0238)','description' => '10mL | Inj up to 2mL IM 2-3 X W'),
            array('id' => 0, 'name' => 'MSM (Dimethyl Sulfone) - MDV (0524)','description' => '200mg / mL | 30mL | Inj up to 15mL IV 2-3 X W'),
            array('id' => 0, 'name' => 'Myers Cocktail IM - MDV (0903)','description' => '30mL | Inj up to 2mL IM Q W'),
            array('id' => 0, 'name' => 'Myers Cocktail IV - P/F (0502)','description' => '50mL | Infuse 50mL in 500cc NS IV over 1hr 2-3 X W'),
            array('id' => 0, 'name' => 'N - Acetyl L - Cysteine - MDV (0872)','description' => '200mg / mL | 30mL | Inj up to 3mL IV 2-3 X W'),
            array('id' => 0, 'name' => 'NAD+ - MDV (5132)','description' => '200mg / mL | 10mL | Inj up to 5mL IV 2-3x X W'),
            array('id' => 0, 'name' => 'Phosphatidylcholine / Deoxych Acid - MDV (9501)','description' => '100-47.5mg / mL | 30mL | Inj up to 5mL IV 2-3 X W'),
            array('id' => 0, 'name' => 'Phosphatidylcholine / Deoxych Acid - PF (0716)','description' => '50-25mg / mL | 10mL | Inj up to 10mL IV 2-3 X W'),
            array('id' => 0, 'name' => 'Proline (L) - MDV (0587)','description' => '50mg / mL | 30mL | Inj 2-6mL IV 2-3 X W'),
            array('id' => 0, 'name' => 'Pyridoxine - MDV (0182)','description' => '100mg / mL | 30mL | Inj up to 4mL IV 2-3 X W'),
            array('id' => 126, 'name' => 'Selenium - MDV (0593)','description' => '200mcg / mL | 30mL | Inj up to 2mL IV 2-3 X W'),
            array('id' => 0, 'name' => 'Sermorelin (SQ) - MDV (0164)','description' => '1mg / mL | 10mL | Inj 0.1-0.3mL SQ Q D'),
            array('id' => 127, 'name' => 'Sodium Ascorbate - MDV (0058)','description' => '500mg / mL | 50mL | Inj up to 150mL IV 2-3 X W'),
            array('id' => 0, 'name' => 'Sodium Ascorbate - MDV (0057)','description' => '500mg / mL | 100mL | Inj up to 150mL IV 2-3 X W'),
            array('id' => 0, 'name' => 'Sodium Ascorbate - PF (0065)','description' => '500mg / mL | 100mL | Inj up to 100mL IV 2-3 X W'),
            array('id' => 0, 'name' => 'Sodium Bicarbonate - MDV 8.4% (4822)','description' => '8.4% | 50mL | Inj up to 15mL IV 2-3 X W'),
            array('id' => 0, 'name' => 'Taurine - MDV (5968)','description' => '50mg / mL | 30mL | Inj up to 40mL IV 2-3 X W'),
            array('id' => 0, 'name' => 'Thiamine HCL - MDV (8884)','description' => '100mg / mL | 30mL | Inj up to 5mL IV 2-3 X W'),

            array('id' => 128, 'name' => 'Trace Min - 5 - MDV (7538)','description' => '10mL | Inj up to 3mL IV 2-3 X W'),
            array('id' => 0, 'name' => 'Trace Min - 5 - PF (0218)','description' => '2mL | Inj up to 3mL IV 2-3 X W'),
            array('id' => 129, 'name' => 'Vit B Complex - MDV (0292)','description' => '30mL | Inj up to 5mL IV 2-3 X W'),
            array('id' => 0, 'name' => 'Vit C (Tapioca) - MDV (4402)','description' => '500mg / mL | 50mL | Inj up to 150mL IV 2-3 X W'),
            array('id' => 0, 'name' => 'Vit C (Tapioca) - MDV (9993)','description' => '500mg / mL | 100mL | Inj up to 150mL IV 2-3 X W'),
            array('id' => 0, 'name' => 'Vit C (Tapioca) - PF (0299)','description' => '500mg / mL | 50mL | Inj up to 100mL IV 2-3 X W'),
            array('id' => 0, 'name' => 'Vit C (Tapioca) - PF (5195)','description' => '500mg / mL | 100mL | Inj up to 100mL IV 2-3 X W'),
            array('id' => 0, 'name' => 'Vit D3 - Oil - MDV (4307)','description' => '100,000IU / mL | 10mL | Inj up to 1mL IM Q W'),
            array('id' => 0, 'name' => 'Vit D3 - Oil - MDV (2054)','description' => '50,000IU / mL | 10mL | Inj up to 1mL IM Q W'),
            array('id' => 130, 'name' => 'Zinc Chloride - MDV (0688)','description' => '10mg / mL | 30mL | Inj up to 1 mL IV 1-2 X W'),
            //array('id' => 0, 'name' => 'Zinc Sulfate - MDV','description' => '5mg / mL | 30mL | Inj up to 2mL IV 1-2 X W'),
            array('id' => 131, 'name' => 'Zinc Sulfate - MDV (0590)','description' => '10mg / mL | 30mL | Inj up to 1mL IV 1-2 X W'),
        );*/
 
        $products = array(
            array('id' => 193, 'name' => 'Biotin - MDV (0527)','description' => '10mg/ mL | 30mL | Inj up to 1mL IV 1-2 X W'),
            array('id' => 219, 'name' => 'Cal-Mag MDV (9198)','description' => ' 50mg-100mg/mL | 50mL | Inj up to 10mL IV 1-3 X W'),
            array('id' => 194, 'name' => 'Folic Acid - MDV (0544)','description' => '10mg/ mL | 30mL | Inj 1mL IV 2-3 X W'),
            array('id' => 203, 'name' => 'Inositol - MDV (0346)','description' => '50mg/ mL | 30mL | Inj up to 4mL IV 2 X W '),
            array('id' => 204, 'name' => 'L-Carnitine - MDV (0527)','description' => '500mg/ mL | 30mL | Inj up to 2mL IV 2-3 X W'),
            array('id' => 205, 'name' => 'Methylcobalamin (Buffered) - MDV (0033)','description' => '1mg/ mL | 30mL | Inj up to 5mL IV 2-3 X W'),
            array('id' => 251, 'name' => 'Methylcobalamin (Buffered) - MDV (0033)','description' => '1mg/ mL | 30mL | Inj up to 1mL IM 1-2 X W'),
            array('id' => 206, 'name' => 'MIC Lipo Slim - MDV (0238)','description' => ' | 10mL | Inj up to 2mL IM 2-3 X W'),
            array('id' => 207, 'name' => 'Myers cocktail IM - MDV (0903)','description' => ' | 10mL | Inj up to 2mL IM 2-3 X W'),
            array('id' => 208, 'name' => 'NAD+ - MDV (5132)','description' => '200mg/ mL | 10mL | Inj up to 5mL IV 2-3X X W'),
            array('id' => 209, 'name' => 'Taurine - PF (0563)','description' => '50mg/ mL | 40mL | Inj up to 40mL IV 2-3 X W'),
            array('id' => 210, 'name' => 'Thiamine HCL - MDV (8884)','description' => '100mg/ mL | 30mL | Inj up to 5mL IV 2-3 X W'),
            array('id' => 218, 'name' => 'Trace Min-5 MDV (7538) ','description' => '10mL |  | Use 5mL in 1L NS IV over 60-90min 1-3 X W'),
            array('id' => 217, 'name' => 'Vitamin B Complex +Vit C MDV ','description' => '30mL |  | Use 10mL in 1L NS IV over 60-90min 1-3 X W'),
            array('id' => 211, 'name' => 'Vit D3 - Oil - MDV (4307)','description' => '100,000IU/mL | 10mL | Inj up to 1mL IM Q W'),
            array('id' => 212, 'name' => 'Vit D3 - Oil - MDV (2054)','description' => '50,000IU/mL | 10mL | Inj up to 1mL IM Q W'),
            array('id' => 213, 'name' => 'Zinc Sulfate - MDV (0590)','description' => '10mg/ mL | 30mL | Inj up to 1mL IV 1-2 X W'),
            array('id' => 214, 'name' => 'Myers Cocktail IV - P/F (0502)','description' => '50mL |  | Use 50mL in 500cc NS IV over 1hr 2-3 X W'),
            array('id' => 215, 'name' => 'Immune Cocktail Formula #2 - P/F (0502)','description' => '30mL |  | Use 2-5mL in 100cc NS IV over 30-45min 1-3 X W'),
        );
            
        $pack = array(
            array('id' => 216, 'name' => 'Fat Burner ','description' => '&nbsp; | &nbsp;| &nbsp;'),
            array('id' => 0, 'name' => 'MIC + Carnitine + Methyl B12 (1178)','description' => '30mL |  | Use 5mL in 250cc NS IV over 60-90min 1-3 X W'),
            array('id' => 255, 'name' => 'Skin & Anti-Aging Drip ','description' => ' | | '),
            array('id' => 0, 'name' => 'Vitamin B Complex +Vit C MDV ','description' => '30mL |  | Use 10mL in 1L NS IV over 60-90min 1-3 X W'),
            array('id' => 0, 'name' => 'Trace Min-5 MDV (7538) ','description' => '10mL |  | Use 5mL in 1L NS IV over 60-90min 1-3 X W'),
            array('id' => 256, 'name' => 'Rehydration Drip ','description' => ' | | '),
            array('id' => 0, 'name' => 'Cal-Mag MDV (9198) 50mg-100mg/mL','description' => '50mL  |  | Use 10mL in 1L NS IV over 60-90min 1-3 X W'),
            array('id' => 0, 'name' => 'Vitamin B Complex + Vit C MDV','description' => '30mL  |  | Use 5mL in 1L NS IV over 60-90min 1-3 X W'),
        );

        $this->loadModel('SpaLiveV1.DataFormInfoDc');
        $user_id = $user['id'];
        
        $form_info = $this->DataFormInfoDc->find()
            ->where(['DataFormInfoDc.user_id' => $user_id, 'DataFormInfoDc.deleted' => 0])
            ->order(['DataFormInfoDc.id' => 'DESC'])
            ->first();
        $user_name = $user['name'].' '.$user['lname'];
        $practice_name = !empty($form_info) ? $form_info->practice_name : $user_name.' Clinic';
        if(strcasecmp($practice_name, $user_name) === 0){
            $practice_name = $user_name.' Clinic';
        }

        $patient_name = !empty($patient_detail) ? $patient_detail['name'] . ' ' . $patient_detail['lname'] : $user['name'].' '.$user['lname'];
        $patient_phone = $user['phone'];
        $patient_dob = !empty($patient_detail) ? $patient_detail['dob'] : $user['dob']->i18nFormat('MM/dd/Y');
        if(!empty($shipping_address)){
            $patient_address = $shipping_address;
        }else{
            $patient_address = $user['street'].'. '.$user['city'].', TX '.$user['zip'];
        }
        $email = $user['email'];
        $patient_allergies = "None";
        $date = date('m/d/Y');

        $html = "<page>";
        if($padding){
            $html .= "<div style=\"width: width: 199.5mm; height: 277mm; position:relative; color: #373a48; padding: 5mm\">";
        }else{
            $html .= "<div style=\"margin-left: -5mm; margin-top: -5mm; width: width: 199.5mm; height: 277mm; position:relative; color: #373a48;\">";
        }

        $header = "<table style=\"text-align: center; width: 180mm; color: black;\">
                        <tbody>
                            <tr>
                                <td style=\"width: 100%; text-align: left;\"> <div style=\"font-size: 30px; width: 75%; display: inline;\"> <img src=\"{$this->URL_API}img/drug_crafters.png\" style=\"width: 20%; margin-left: 20mm;\"> </div> <div style=\"width: 24%; display: inline; color: #228B22; vertical-align: bottom; margin-left: 8mm; margin-top: 10mm;\"> DrugCrafters.com </div> </td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <table style=\"margin-top: 3mm; text-align: center; width: 200mm;\">
                    <tbody>
                        <tr>
                            <td style=\"font-size: 13px; width: 100%; text-align: left;\">
                                <div style=\"width: 2%; display: inline; padding-top: .5mm;\"> <div style=\"border: 1.5px solid #555; width: 4mm; height: 4mm; display: inline; margin-right: 1mm;\"> <div style=\"padding-left: .75mm; height: 4mm; width: inherit; \"> X </div> </div> </div> <div style=\"padding-top: .5mm; width: 12%; display: inline;\"> SHIP Patient </div> 
                                <div style=\"width: 2%; display: inline; padding-top: .5mm;\"> <div style=\"border: 1.5px solid #555; width: 4mm; height: 4mm; display: inline; margin-right: 1mm;\"> <div style=\"padding-left: .75mm; height: 4mm; width: inherit; \"> </div> </div> </div> <div style=\"padding-top: .5mm; width: 17.5%; display: inline;\"> PICK-UP by Patient </div> 
                                <div style=\"width: 2%; display: inline; padding-top: .5mm;\"> <div style=\"border: 1.5px solid #555; width: 4mm; height: 4mm; display: inline; margin-right: 1mm;\"> <div style=\"padding-left: .75mm; height: 4mm; width: inherit; \"> </div> </div> </div> <div style=\"padding-top: .5mm; width: 16.6%; display: inline;\"> PICK-UP by Office </div>
                                <div style=\"width: 2%; display: inline; padding-top: .5mm;\"> <div style=\"border: 1.5px solid #555; width: 4mm; height: 4mm; display: inline; margin-right: 1mm;\"> <div style=\"padding-left: .75mm; height: 4mm; width: inherit; \"> </div> </div> </div> <div style=\"padding-top: .5mm; width: 13.8%; display: inline;\"> SHIP to Doctor </div>
                                <div style=\"width: 2%; display: inline; padding-top: .5mm;\"> <div style=\"border: 1.5px solid #555; width: 4mm; height: 4mm; display: inline; margin-right: 1mm;\"> <div style=\"padding-left: .75mm; height: 4mm; width: inherit; \"> </div> </div> </div> <div style=\"padding-top: .5mm; width: 11.4%; display: inline;\"> BILL Doctor </div>
                                <div style=\"width: 2%; display: inline; padding-top: .5mm;\"> <div style=\"border: 1.5px solid #555; width: 4mm; height: 4mm; display: inline; margin-right: 1mm;\"> <div style=\"padding-left: .75mm; height: 4mm; width: inherit; \"> </div> </div> </div> <div style=\"padding-top: .5mm; width: 10%; display: inline;\"> BILL Patient </div>  
                            </td>
                        </tr>
                    </tbody>
                </table>

                <table style=\"font-size: 12px; margin-top: 4mm; text-align: left; width: 200mm;\">
                    <tbody>
                        <tr>
                            <td style=\"width: 12%;\"> Patient Name: </td>
                            <td style=\"width: 48%;\">".$patient_name."<hr style=\"width: 100%;\"></td>
                            <td style=\"width: 15%;\"> <div style=\"padding-left: 14mm;\"> DOB: </div> </td>
                            <td style=\"width: 25%;\">".$patient_dob."<hr style=\"width: 100%;\"></td>
                        </tr>
                    </tbody>
                </table>

                <table style=\"font-size: 12px; text-align: left; width: 200mm;\">
                    <tbody>
                        <tr>
                            <td style=\"width: 12%;\"> Address: </td>
                            <td style=\"width: 48%;\">".$patient_address."<hr style=\"width: 100%;\"></td>
                            <td style=\"width: 15%;\"> <div style=\"padding-left: 14mm;\"> Allergies: </div> </td>
                            <td style=\"width: 25%;\">None<hr style=\"width: 100%;\"></td>
                        </tr>
                    </tbody>
                </table>

                <table style=\"margin-bottom: 2mm; font-size: 12px; text-align: left; width: 200mm;\">
                    <tbody>
                        <tr>
                            <td style=\"width: 12%;\"> Phone: </td>
                            <td style=\"width: 48%;\">".$patient_phone."<hr style=\"width: 100%;\"></td>
                            <td style=\"width: 15%;\"> <div style=\"padding-left: 14mm;\"> Email: </div> </td>
                            <td style=\"width: 25%;\">".$email."<hr style=\"width: 100%;\"></td>
                        </tr>
                    </tbody>
                </table>
                ";

        $footer = "<table style=\"margin-top: 2mm; font-size: 12px; border: 1.5px solid #555; text-align: left; width: 199mm;\">
                        <tbody>
                            <tr>
                                <td style=\"width: 100%;\">
                                    <table style=\"margin-top: 2mm; margin-bottom: 2mm; margin-left: 5mm; font-size: 12px; text-align: left; width: 192.9mm;\">
                                        <tbody>
                                            <tr>
                                                <td style=\"width: 17%;\"> Prescriber Signature: </td>
                                                <td style=\"width: 41%; border-bottom: solid;\"><img src=\"{$this->URL_API}img/signature.png\" style=\"height: 2%; width:50%;\"></td>
                                                <td style=\"width: 13%;\"> Date: </td>
                                                <td style=\"width: 26%; border-bottom: solid;\">".$date."</td>
                                            </tr>
                                            <tr>
                                                <td style=\"width: 17%;\"> Prescriber Name: </td>
                                                <td style=\"width: 41%;\"> Marie Beauvoir <hr style=\"width: 100%;\"> </td>
                                                <td style=\"width: 13%;\"> Practice Name: </td>
                                                <td style=\"width: 26%;\">" . $practice_name . "<hr style=\"width: 100%;\"></td>
                                            </tr>
                                            <tr>
                                                <td style=\"width: 17%;\"> Phone Number: </td>
                                                <td style=\"width: 41%;\"> 847-477-5791 <hr style=\"width: 100%;\"> </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <table style=\"margin-top: 2mm; font-size: 10px; text-align: center; width: 200mm; color: #228B22;\">
                        <tbody>
                            <tr>
                                <td style=\"width: 100%;\"> 5680 Frisco Square Blvd. Suite 1100 Frisco, TX 75035 | p: (214) 618-3511 | f: <b>(214) 618-3720</b> | <b>OfficeOrders@DrugCrafters.com</b> </td>
                            </tr>
                        </tbody>
                    </table>

                    <table style=\"font-size: 6px; text-align: center; width: 200mm; color: #228B22;\">
                        <tbody>
                            <tr>
                                <td style=\"width: 100%;\"> prices subject to change at anytime </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </page>";
                
        $count_product = 0;
        $limit_products = 0;

        foreach ($products as $p) {   

            if($count_product<=0){
                $html .= $header;
            }

            $description = explode(' | ', $p["description"]);

            $description1 = "";
            $description2 = "";
            $description3 = "";

            if(count($description)>2){
                $description1 = $description[0];
                $description2 = $description[1];
                $description3 = $description[2];
            }else{
                $description1 = "";
                $description2 = $description[0];
                $description3 = $description[1];
            }

            //buscar el productos por id
            $check_product = false;
            $qty = 0;

            foreach($iv_products as $vials){
                if($vials["Product"]["id"] == $p["id"]){
                    $check_product = true;
                    $qty = $vials['qty'];
                    break;
                }
            }

            $html .= "<table style=\"font-size: 10.5px; margin-top: -5px; text-align: center; width: 200mm; \">
                            <tbody>
                                <tr>
                                    <td style=\"font-size: 13px; width:   3%; text-align: left; height: 5mm;\"> ";
                                    if($p['id']==0){
                                        $html.="<div style=\" width: 4mm; height: 4mm; display: inline; margin-right: 1mm;\"> <div style=\"padding-left: .75mm; height: 4mm; width: inherit; \" > "; 
                                        $html .= "</div> </div> </td>
                                        <td style=\"width:31.5%; text-align: left;\"><b> ".$p["name"]." </b></td>
                                        <td style=\"width: 12%; text-align: left;\">&nbsp;  </td>
                                        <td style=\"width: 5.5%; text-align: left;\">&nbsp;  </td>
                                        <td style=\"width: 30%; text-align: left;\">&nbsp;  </td>
                                        <td style=\"width: 5%; text-align: left;\">&nbsp;  </td>
                                        <td style=\"width: 4.5%; text-align: center; \">&nbsp;"; 
                                        
                                        $html.=" </td>
                                        <td style=\"width: 4%; text-align: left;\">&nbsp;  </td>
                                        <td style=\"width: 4.5%; text-align: left; ;\">&nbsp; </td>";
                                    }else{
                                        $html.="<div style=\"border: 1.5px; solid #555; width: 4mm; height: 4mm; display: inline; margin-right: 1mm;\"> <div style=\"padding-left: .75mm; height: 4mm; width: inherit; \">"; 
                                    
                                    
                                        if($check_product){ $html.= "X"; } $html.= "</div> </div> </td>
                                        <td style=\"width:31.5%; text-align: left;\"> <b> ".$p["name"]." </b> </td>
                                        <td style=\"width: 12%; text-align: left;\"> <span> ".$description1." </span> </td>
                                        <td style=\"width: 5.5%; text-align: left;\"> <span> ".$description2." </span> </td>
                                        <td style=\"width: 30%; text-align: left;\"> <span> ".$description3." </span> </td>
                                        <td style=\"width: 5%; text-align: left;\"> <span> QTY: </span> </td>
                                        <td style=\"width: 4.5%; text-align: center; border-bottom: solid;\">"; 
                                        if($check_product){ $html.= $qty; } 
                                        $html.=" </td>
                                        <td style=\"width: 4%; text-align: left;\"> <span> RF: </span> </td>
                                        <td style=\"width: 4.5%; text-align: left; border-bottom: solid;\"> </td>";
                                    }
                                $html.="</tr>
                                <tr> <td colspan=\"9\" style=\"font-size: 13px; width:   3%; text-align: left height: 5mm;\">&nbsp;</td> </tr>

                            </tbody>
                        </table>";

            $count_product++;
            $limit_products++;

            if($count_product>=17){
                $html .= $footer;
                $count_product = 0;

                $html.= "<page>";

                if($padding){
                    $html .= "<div style=\"width: width: 199.5mm; height: 277mm; position:relative; color: #373a48; padding: 5mm\">";
                }else{
                    $html .= "<div style=\"margin-left: -5mm; margin-top: -5mm; width: width: 199.5mm; height: 277mm; position:relative; color: #373a48;\">";
                }   
            }
        }

        // parche rapdio para el pack
        $check_burner = false;
        $qty_mic = 0;
        $check_skin = false;
        $qty_vitamin = 0;
        $qty_trace = 0;
        $check_rehydration = false;
        $qty_vitamin2 = 0;
        $qty_cal = 0;
        foreach ($pack as $key => $value) {
            foreach($iv_products as $vials){
                if($vials["Product"]["id"] == $value["id"]){
                    if($value["id"] == 216){
                        $check_burner = true;
                        $qty_mic = 1;
                        break;
                    }else if($value["id"] == 255){
                        $check_skin = true;
                        $qty_vitamin = $vials['qty'];
                        $qty_trace = $vials['qty'];
                        break;
                    }else if($value["id"] == 256){
                        $check_rehydration = true;
                        $qty_cal = $vials['qty'];
                        $qty_vitamin2 = $vials['qty'];
                        break;
                    }
                }
            }
        }

        $burner_template = 
            "<table style=\"font-size: 10.5px; margin-top: -5px; text-align: center; width: 200mm; \">
                <tbody>
                    <tr>
                        <td style=\"font-size: 13px; width:   3%; text-align: left; height: 5mm;\">
                        <div style=\"border: 1.5px; solid #555; width: 4mm; height: 4mm; display: inline; margin-right: 1mm;\"> <div style=\"padding-left: .75mm; height: 4mm; width: inherit; \">"; 
                        if($check_burner){ $burner_template.= "X"; } $burner_template.= "</div> </div> </td>
                        <td style=\"width:31.5%; text-align: left;\"> <b> Fat Burner </b> </td>
                        <td style=\"width: 12%; text-align: left;\">&nbsp;  </td>
                        <td style=\"width: 5.5%; text-align: left;\">&nbsp;  </td>
                        <td style=\"width: 30%; text-align: left;\">&nbsp;  </td>
                        <td style=\"width: 5%; text-align: left;\">&nbsp;  </td>
                        <td style=\"width: 4.5%; text-align: center; \">&nbsp;</td>
                        <td style=\"width: 4%; text-align: left;\">&nbsp;  </td>
                        <td style=\"width: 4.5%; text-align: left; ;\">&nbsp; </td>
                    </tr>
                    <tr> <td colspan=\"9\" style=\"font-size: 13px; width: 3%; text-align: left height: 5mm;\">&nbsp;</td> </tr>
                </tbody>
            </table>
            <table style=\"font-size: 10.5px; margin-top: -5px; text-align: center; width: 200mm; \">
                <tbody>
                    <tr>
                        <td style=\"font-size: 13px; width:   3%; text-align: left; height: 5mm;\"><div style=\" width: 4mm; height: 4mm; display: inline; margin-right: 1mm;\"> <div style=\"padding-left: .75mm; height: 4mm; width: inherit; \" ></div> </div> </td>
                        <td style=\"width:31.5%; text-align: left;\"><b> MIC + Carnitine + Methyl B12 (1178) </b></td>
                        <td style=\"width: 12%; text-align: left;\">30mL </td>
                        <td style=\"width: 5.5%; text-align: left;\"> </td>
                        <td style=\"width: 30%; text-align: left;\"> Use 5mL in 250cc NS IV over 60-90min 1-3 X W</td>
                        <td style=\"width: 5%; text-align: left;\"> <span> QTY: </span> </td>
                        <td style=\"width: 4.5%; text-align: center; border-bottom: solid;\">"; 
                        if($qty_mic > 0){ $burner_template.= $qty_mic; } 
                        $burner_template.=" </td>
                        <td style=\"width: 4%; text-align: left;\"> <span> RF: </span> </td>
                        <td style=\"width: 4.5%; text-align: left; border-bottom: solid;\"> </td>
                    </tr>
                    <tr> <td colspan=\"9\" style=\"font-size: 13px; width: 3%; text-align: left height: 5mm;\">&nbsp;</td> </tr>
                </tbody>
            </table>";

        $html .= $burner_template;

        $skin_template = 
            "<table style=\"font-size: 10.5px; margin-top: -5px; text-align: center; width: 200mm; \">
                <tbody>
                    <tr>
                        <td style=\"font-size: 13px; width:   3%; text-align: left; height: 5mm;\">
                        <div style=\"border: 1.5px; solid #555; width: 4mm; height: 4mm; display: inline; margin-right: 1mm;\"> <div style=\"padding-left: .75mm; height: 4mm; width: inherit; \">"; 
                        if($check_skin){ $skin_template.= "X"; } $skin_template.= "</div> </div> </td>
                        <td style=\"width:31.5%; text-align: left;\"> <b> Skin & Anti-Aging Drip </b> </td>
                        <td style=\"width: 12%; text-align: left;\">&nbsp;  </td>
                        <td style=\"width: 5.5%; text-align: left;\">&nbsp;  </td>
                        <td style=\"width: 30%; text-align: left;\">&nbsp;  </td>
                        <td style=\"width: 5%; text-align: left;\">&nbsp;  </td>
                        <td style=\"width: 4.5%; text-align: center; \">&nbsp;</td>
                        <td style=\"width: 4%; text-align: left;\">&nbsp;  </td>
                        <td style=\"width: 4.5%; text-align: left; ;\">&nbsp; </td>
                    </tr>
                    <tr> <td colspan=\"9\" style=\"font-size: 13px; width: 3%; text-align: left height: 5mm;\">&nbsp;</td> </tr>
                </tbody>
            </table>
            <table style=\"font-size: 10.5px; margin-top: -5px; text-align: center; width: 200mm; \">
                <tbody>
                    <tr>
                        <td style=\"font-size: 13px; width:   3%; text-align: left; height: 5mm;\"><div style=\" width: 4mm; height: 4mm; display: inline; margin-right: 1mm;\"> <div style=\"padding-left: .75mm; height: 4mm; width: inherit; \" ></div> </div> </td>
                        <td style=\"width:31.5%; text-align: left;\"><b> Vitamin B Complex +Vit C MDV </b></td>
                        <td style=\"width: 12%; text-align: left;\">30mL </td>
                        <td style=\"width: 5.5%; text-align: left;\"> </td>
                        <td style=\"width: 30%; text-align: left;\"> Use 10mL in 1L NS IV over 60-90min 1-3 X W</td>
                        <td style=\"width: 5%; text-align: left;\"> <span> QTY: </span> </td>
                        <td style=\"width: 4.5%; text-align: center; border-bottom: solid;\">"; 
                        if($qty_vitamin > 0){ $skin_template.= $qty_vitamin; } 
                        $skin_template.=" </td>
                        <td style=\"width: 4%; text-align: left;\"> <span> RF: </span> </td>
                        <td style=\"width: 4.5%; text-align: left; border-bottom: solid;\"> </td>
                    </tr>
                    <tr> <td colspan=\"9\" style=\"font-size: 13px; width: 3%; text-align: left height: 5mm;\">&nbsp;</td> </tr>
                </tbody>
            </table>
            <table style=\"font-size: 10.5px; margin-top: -5px; text-align: center; width: 200mm; \">
                <tbody>
                    <tr>
                        <td style=\"font-size: 13px; width:   3%; text-align: left; height: 5mm;\"><div style=\" width: 4mm; height: 4mm; display: inline; margin-right: 1mm;\"> <div style=\"padding-left: .75mm; height: 4mm; width: inherit; \" ></div> </div> </td>
                        <td style=\"width:31.5%; text-align: left;\"><b> Trace Min-5 MDV (7538) </b></td>
                        <td style=\"width: 12%; text-align: left;\">10mL </td>
                        <td style=\"width: 5.5%; text-align: left;\"> </td>
                        <td style=\"width: 30%; text-align: left;\"> Use 5mL in 1L NS IV over 60-90min 1-3 X W</td>
                        <td style=\"width: 5%; text-align: left;\"> <span> QTY: </span> </td>
                        <td style=\"width: 4.5%; text-align: center; border-bottom: solid;\">"; 
                        if($qty_trace > 0){ $skin_template.= $qty_trace; } 
                        $skin_template.=" </td>
                        <td style=\"width: 4%; text-align: left;\"> <span> RF: </span> </td>
                        <td style=\"width: 4.5%; text-align: left; border-bottom: solid;\"> </td>
                    </tr>
                    <tr> <td colspan=\"9\" style=\"font-size: 13px; width: 3%; text-align: left height: 5mm;\">&nbsp;</td> </tr>
                </tbody>
            </table>";

        $html .= $skin_template;

        $rehydration_template = 
            "<table style=\"font-size: 10.5px; margin-top: -5px; text-align: center; width: 200mm; \">
                <tbody>
                    <tr>
                        <td style=\"font-size: 13px; width:   3%; text-align: left; height: 5mm;\">
                        <div style=\"border: 1.5px; solid #555; width: 4mm; height: 4mm; display: inline; margin-right: 1mm;\"> <div style=\"padding-left: .75mm; height: 4mm; width: inherit; \">"; 
                        if($check_rehydration){ $rehydration_template.= "X"; } $rehydration_template.= "</div> </div> </td>
                        <td style=\"width:31.5%; text-align: left;\"> <b> Rehydration Drip </b> </td>
                        <td style=\"width: 12%; text-align: left;\">&nbsp;  </td>
                        <td style=\"width: 5.5%; text-align: left;\">&nbsp;  </td>
                        <td style=\"width: 30%; text-align: left;\">&nbsp;  </td>
                        <td style=\"width: 5%; text-align: left;\">&nbsp;  </td>
                        <td style=\"width: 4.5%; text-align: center; \">&nbsp;</td>
                        <td style=\"width: 4%; text-align: left;\">&nbsp;  </td>
                        <td style=\"width: 4.5%; text-align: left; ;\">&nbsp; </td>
                    </tr>
                    <tr> <td colspan=\"9\" style=\"font-size: 13px; width: 3%; text-align: left height: 5mm;\">&nbsp;</td> </tr>
                </tbody>
            </table>
            <table style=\"font-size: 10.5px; margin-top: -5px; text-align: center; width: 200mm; \">
                <tbody>
                    <tr>
                        <td style=\"font-size: 13px; width:   3%; text-align: left; height: 5mm;\"><div style=\" width: 4mm; height: 4mm; display: inline; margin-right: 1mm;\"> <div style=\"padding-left: .75mm; height: 4mm; width: inherit; \" ></div> </div> </td>
                        <td style=\"width:31.5%; text-align: left;\"><b> Cal-Mag MDV (9198) 50mg-100mg/mL </b></td>
                        <td style=\"width: 12%; text-align: left;\">50mL </td>
                        <td style=\"width: 5.5%; text-align: left;\"> </td>
                        <td style=\"width: 30%; text-align: left;\"> Use 10mL in 1L NS IV over 60-90min 1-3 X W</td>
                        <td style=\"width: 5%; text-align: left;\"> <span> QTY: </span> </td>
                        <td style=\"width: 4.5%; text-align: center; border-bottom: solid;\">"; 
                        if($qty_cal > 0){ $rehydration_template.= $qty_cal; } 
                        $rehydration_template.=" </td>
                        <td style=\"width: 4%; text-align: left;\"> <span> RF: </span> </td>
                        <td style=\"width: 4.5%; text-align: left; border-bottom: solid;\"> </td>
                    </tr>
                    <tr> <td colspan=\"9\" style=\"font-size: 13px; width: 3%; text-align: left height: 5mm;\">&nbsp;</td> </tr>
                </tbody>
            </table>
            <table style=\"font-size: 10.5px; margin-top: -5px; text-align: center; width: 200mm; \">
                <tbody>
                    <tr>
                        <td style=\"font-size: 13px; width:   3%; text-align: left; height: 5mm;\"><div style=\" width: 4mm; height: 4mm; display: inline; margin-right: 1mm;\"> <div style=\"padding-left: .75mm; height: 4mm; width: inherit; \" ></div> </div> </td>
                        <td style=\"width:31.5%; text-align: left;\"><b> Vitamin B Complex + Vit C MDV </b></td>
                        <td style=\"width: 12%; text-align: left;\">30mL </td>
                        <td style=\"width: 5.5%; text-align: left;\"> </td>
                        <td style=\"width: 30%; text-align: left;\"> Use 5mL in 1L NS IV over 60-90min 1-3 X W</td>
                        <td style=\"width: 5%; text-align: left;\"> <span> QTY: </span> </td>
                        <td style=\"width: 4.5%; text-align: center; border-bottom: solid;\">"; 
                        if($qty_vitamin2 > 0){ $rehydration_template.= $qty_vitamin2; } 
                        $rehydration_template.=" </td>
                        <td style=\"width: 4%; text-align: left;\"> <span> RF: </span> </td>
                        <td style=\"width: 4.5%; text-align: left; border-bottom: solid;\"> </td>
                    </tr>
                    <tr> <td colspan=\"9\" style=\"font-size: 13px; width: 3%; text-align: left height: 5mm;\">&nbsp;</td> </tr>
                </tbody>
            </table>";

        $html .= $rehydration_template;

        //ya no hay mas productos
        //llenar el espacio vacío
            $html .= "
                <table style=\"font-size: 10.5px; margin-top: 5.88mm; text-align: center; width: 200mm;\">
                    <tbody>
                        <tr>
                            <td style=\"width: 4.5%; text-align: left; height: 5mm; \"> </td>
                        </tr>
                    </tbody>
                </table>
                <table style=\"font-size: 10.5px; margin-top: 5.88mm; text-align: center; width: 200mm;\">
                    <tbody>
                        <tr>
                            <td style=\"width: 4.5%; text-align: left; height: 5mm; \"> </td>
                        </tr>
                    </tbody>
                </table>
                <table style=\"font-size: 10.5px; margin-top: 5.88mm; text-align: center; width: 200mm;\">
                    <tbody>
                        <tr>
                            <td style=\"width: 4.5%; text-align: left; height: 5mm; \"> </td>
                        </tr>
                    </tbody>
                </table>
                ";

        $html .= $footer;

        /*$html2pdf = new HTML2PDF('F','A4','en', true, 'UTF-8', array(0,0,0,0));
        $html2pdf->writeHTML($html);

        $filename = TMP . 'files' . DS . 'nutrition_form.pdf';
        
        $html2pdf->Output($filename, 'F'); // Generar el archivo PDF
        $html2pdf->Output($filename, 'I'); // Mostrar el archivo PDF en el navegador

        pr($html);
        die();
        exit;*/

        return $html;

    }

    public function drug_crafters_application_pdf($ent_user = null, $prescriber_data = null, $return_html = false){
        $original_error_reporting = error_reporting();
        error_reporting(E_ERROR | E_PARSE);
        
        $this->loadModel('SpaLiveV1.DataFormInfoDc');
        $user_id = !empty($ent_user) ? $ent_user->id : USER_ID;
        
        $form_info = $this->DataFormInfoDc->find()
            ->where(['DataFormInfoDc.user_id' => $user_id, 'DataFormInfoDc.deleted' => 0])
            ->order(['DataFormInfoDc.id' => 'DESC'])
            ->first();
        
        if(empty($prescriber_data)){
            $this->loadModel('SpaLiveV1.SysUsers');
            $this->loadModel('SpaLiveV1.SysLicences');
            
            $user_info = $this->SysUsers->find()
                ->where(['SysUsers.id' => $user_id])
                ->first();
            
            if(!empty($user_info)){
                $state_code = 'TX';
                if(!empty($user_info->state)){
                    $this->loadModel('SpaLiveV1.CatStates');
                    $state = $this->CatStates->find()
                        ->where(['CatStates.id' => $user_info->state])
                        ->first();
                    if(!empty($state)){
                        $state_code = $state->abv;
                    }
                }
                
                $license = $this->SysLicences->find()
                    ->where([
                        'SysLicences.user_id' => $user_id,
                        'SysLicences.deleted' => 0,
                        'SysLicences.status' => 'APPROVED'
                    ])
                    ->order(['SysLicences.id' => 'DESC'])
                    ->first();
                
                if(empty($license)){
                    $license = $this->SysLicences->find()
                        ->where([
                            'SysLicences.user_id' => $user_id,
                            'SysLicences.deleted' => 0
                        ])
                        ->order(['SysLicences.id' => 'DESC'])
                        ->first();
                }
                
                $license_title = !empty($license) && !empty($license->type) ? $license->type : 'MD';
                $license_number = !empty($license) && !empty($license->number) ? $license->number : '';
                
                $prescriber_data = array(
                    'name' => $user_info->name ?? '',
                    'lname' => $user_info->lname ?? '',
                    'title' => $license_title,
                    'practice_name' => !empty($form_info) ? $form_info->practice_name : '',
                    'npi_number' => !empty($form_info) && !empty($form_info->npi) ? $form_info->npi : '',
                    'dea_number' => '',
                    'license' => $license_number,
                    'address' => $user_info->street ?? '',
                    'city' => $user_info->city ?? '',
                    'state' => $state_code,
                    'zip_code' => $user_info->zip ?? '',
                    'phone' => !empty($form_info) ? $form_info->phone : ($user_info->phone ?? ''),
                    'email' => $user_info->email ?? '',
                    'office_hours' => !empty($form_info) ? $form_info->office_hour : '',
                    'hear_about' => '',
                    'sign_url' => !empty($form_info) && !empty($form_info->sign_url) ? $form_info->sign_url : '',
                    'clinic_display_name' => trim(($user_info->name ?? '') . ' ' . ($user_info->lname ?? '')),
                );
            }
        }
        
        $date = date('m/d/Y');
        
        $name = trim(
            ($prescriber_data['name'] ?? '') . ' ' . 
            ($prescriber_data['lname'] ?? '') . 
            (!empty($prescriber_data['title']) ? ', ' . $prescriber_data['title'] : '')
        );
        $clinic_name_line = $prescriber_data['clinic_display_name']
            ?? trim(($prescriber_data['name'] ?? '') . ' ' . ($prescriber_data['lname'] ?? ''));
        $clinic_name_line = trim($clinic_name_line);

        $practice_name_line = trim($prescriber_data['practice_name'] ?? '');
        $normalized_practice = trim(preg_replace('/\s+clinic$/i', '', $practice_name_line));

        if ($practice_name_line === '' && $clinic_name_line !== '') {
            $practice_name_line = $clinic_name_line;
            $normalized_practice = $clinic_name_line;
        }

        if ($clinic_name_line !== '') {
            if ($normalized_practice === '' || strcasecmp($normalized_practice, $clinic_name_line) === 0) {
                if (stripos($practice_name_line, 'clinic') === false) {
                    $practice_name_line = rtrim($practice_name_line) . ' Clinic';
                }
            }
        }
        
        $footer = function($page_number) {
            return "<page_footer>
                <div style='margin:0 18mm 10mm 18mm'>
                    <table style='width:100%;font-size:15px;border:0'>
                        <tr>
                            <td style='width:50%;color:#AEC34F;font-weight:bold;text-align:left'>drugcrafters.com</td>
                            <td style='width:50%;text-align:right;color:#777'>(Page $page_number of 4)</td>
                        </tr>
                        <tr>
                            <td style='color:#666;text-align:left'>AReedy@drugcrafters.com</td>
                            <td></td>
                        </tr>
                    </table>
                </div>
            </page_footer>";
        };
        
        $logo_path = WWW_ROOT . 'img' . DS . 'drug_crafters.png';
        $logo_base64 = '';
        if(file_exists($logo_path)){
            $logo_data = @file_get_contents($logo_path);
            $logo_base64 = 'data:image/png;base64,' . base64_encode($logo_data);
        }
        
        $prescriber_signature_base64 = '';
        if(!empty($prescriber_data['sign_url'])){
            $sign_url = $prescriber_data['sign_url'];
            
            if(strpos($sign_url, 'http://') === 0 || strpos($sign_url, 'https://') === 0){
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $sign_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                $signature_data = @curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if($signature_data !== false && $http_code == 200){
                    @$finfo = new \finfo(FILEINFO_MIME_TYPE);
                    @$mime_type = $finfo->buffer($signature_data);
                    $prescriber_signature_base64 = 'data:' . $mime_type . ';base64,' . base64_encode($signature_data);
                }
            } else {
                $local_path = WWW_ROOT . 'img' . DS . $sign_url;
                if(file_exists($local_path)){
                    $signature_data = @file_get_contents($local_path);
                    if($signature_data !== false){
                        @$finfo = new \finfo(FILEINFO_MIME_TYPE);
                        @$mime_type = $finfo->buffer($signature_data);
                        $prescriber_signature_base64 = 'data:' . $mime_type . ';base64,' . base64_encode($signature_data);
                    }
                }
            }
        }
        
        $supervising_signature_base64 = '';
        $marie_signature_url = 'https://blog.myspalive.com/mariesignature.png';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $marie_signature_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $marie_signature_data = @curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if($marie_signature_data !== false && $http_code == 200){
            $temp_file = TMP . 'marie_signature_temp.png';
            @file_put_contents($temp_file, $marie_signature_data);
            
            $img = @imagecreatefrompng($temp_file);
            if($img !== false){
                ob_start();
                @imagepng($img, null, 9);
                $clean_png_data = ob_get_clean();
                @imagedestroy($img);
                
                $supervising_signature_base64 = 'data:image/png;base64,' . base64_encode($clean_png_data);
            }
            
            @unlink($temp_file);
        }

        $supervising_physician_name = 'Marie Beauvoir, MD';
        $supervising_physician_dea = 'FB9021774';
        $marie_npi = '1548392293';
        
        $supervising_physician_license = '';
        $prescriber_state = strtoupper($prescriber_data['state'] ?? 'TX');
        
        switch($prescriber_state){
            case 'TX':
            case 'TEXAS':
                $supervising_physician_license = 'S1764';
                break;
            case 'AZ':
            case 'ARIZONA':
                $supervising_physician_license = '72207';
                break;
            case 'GA':
            case 'GEORGIA':
                $supervising_physician_license = '98169';
                break;
            case 'WA':
            case 'WASHINGTON':
                $supervising_physician_license = 'MD61510861';
                break;
            default:
                $supervising_physician_license = 'S1764'; 
                break;
        }
        
        $header = "<div style='margin-bottom:3mm;margin-top:-8mm'>
            <img src='".$logo_base64."' style='width:45mm;height:auto;' />
        </div>";
        
        $html = "<style>
            body {
                font-family: Arial;
                font-size: 11px;
                color: #333;
                margin: 0;
                padding: 0;
            }
            .form-field {
                margin-bottom: 5mm;
            }
            .field-label {
                font-size: 14px;
                margin-bottom: 1mm;
                color: #000;
            }
            .field-line {
                border-bottom: 1px solid #333;
                min-height: 6mm;
                padding: 0 0 1mm 0;
                color: #000;
            }
            .inline-fields {
                display: table;
                width: 100%;
            }
            .inline-field {
                display: table-cell;
                padding-right: 3mm;
            }
            .section-title {
                color: #AEC34F;
                font-size: 20px;
                font-weight: bold;
                margin: 0 0 2mm 0;
            }
            .page-title {
                font-size: 22px;
                font-weight: bold;
                color: #000;
                line-height: 1.1;
            }
            .agreement-text {
                font-size: 14px;
                line-height: 1.3;
                text-align: justify;
                margin-bottom: 2.5mm;
            }
            .bullet-item {
                margin-bottom: 1mm;
                font-size: 14px;
                line-height: 1.2;
            }
            .checkbox-item {
                margin-bottom: 2mm;
                font-size: 14px;
                line-height: 1.3;
            }
        </style>";

        // ============= Page 1 =============
        $html .= "<page backtop='15mm' backbottom='15mm' backleft='27mm' backright='27mm'>".$footer(1)."
            <table style='width: 100%; border: 0; font-family:helvetica;font-size:11px;color:#333;'>
                <tr>
                    <td style='width: 50%; vertical-align: top;'>
                        <img src='".$logo_base64."' style='width:45mm;height:auto;margin-top:-3mm;' />
                    </td>
                    <td style='width: 50%; vertical-align: top;'>
                        <div style='font-size: 28px; font-weight: bold; color: #000; line-height: 1.2; font-family: helvetica; text-align: right; margin-top: 1mm;'>
                            CUSTOMER<br/><span style='white-space: nowrap;'>APPLICATION FORM</span>
                        </div>
                    </td>
                </tr>
            </table>

            <div style='margin:5mm 0 8mm 0'><div style='color:#AEC34F;font-size:22px;font-weight:bold;margin-bottom:3mm'>* Important & Required: *</div>
            <div style='font-size:16px;line-height:1.5;margin-left:5mm'>• MUST send current copy of medical license. We <i>cannot</i> set up an account<br/>&nbsp;&nbsp;without a valid license.<br/>• Incomplete or unsigned forms will cause delays in processing your application.</div></div>
            <div style='width:58%;margin-top:3mm;margin-bottom:3mm;margin-left:42%;border-top:3px solid #AEC34F'></div>

            <div class='form-field'><div class='field-label'>Date:</div><div class='field-line'>".$date."</div></div>
            <div class='form-field'><div class='field-label'>Practice Name:</div><div class='field-line'>".$practice_name_line."</div></div>
            <div class='form-field'><div class='field-label'>Prescriber's Name / Title: (MD, DO, DVM, DDS, PA, NP)</div><div class='field-line'>".$supervising_physician_name."</div></div>
            <div class='form-field'><div class='field-label'>Shipping Address:</div><div class='field-line'>".$prescriber_data['address']."</div></div>

            <div style='margin-bottom:5mm'>
                <table style='width:100%;border:0;border-spacing:0;border-collapse:collapse;margin-bottom:0'><tr>
                <td style='width:45%;padding:0;padding-right:3mm'><div class='field-label'>City:</div></td>
                <td style='width:30%;padding:0;padding-right:3mm'><div class='field-label'>State:</div></td>
                <td style='width:25%;padding:0'><div class='field-label'>Zip Code:</div></td></tr></table>
                <table style='width:100%;border:0;border-spacing:0;border-collapse:collapse'><tr>
                <td style='width:45%;padding:0 0 1mm 0;padding-right:3mm;border-bottom:1px solid #333;vertical-align:top;color:#000'>".$prescriber_data['city']."</td>
                <td style='width:30%;padding:0 0 1mm 0;padding-right:3mm;border-bottom:1px solid #333;vertical-align:top;color:#000'>".$prescriber_data['state']."</td>
                <td style='width:25%;padding:0 0 1mm 0;border-bottom:1px solid #333;vertical-align:top;color:#000'>".$prescriber_data['zip_code']."</td></tr></table>
            </div>
            <div class='form-field'><div class='field-label'>Primary Contact Name:</div><div class='field-line'>".$prescriber_data['name']." ".$prescriber_data['lname']."</div></div>
            <div class='form-field'><div class='field-label'>Phone:</div><div class='field-line'>".$prescriber_data['phone']."</div></div>
            <div class='form-field'><div class='field-label'>Email:</div><div class='field-line'>".$prescriber_data['email']."</div></div>
            <div class='form-field'><div class='field-label'>Office Hours:</div><div class='field-line'>".($prescriber_data['office_hours']??'')."</div></div>
            <div class='form-field'><div class='field-label'>How did you hear about us?:</div><div class='field-line'>".($prescriber_data['hear_about']??'')."</div></div>
            <div style='width:58%;margin-top:10mm;margin-bottom:0;margin-left:0;margin-right:auto;border-top:3px solid #AEC34F'></div>
        </page>
        ";

        // ============= Page 2 =============
        $html .= "<page backtop='15mm' backbottom='15mm' backleft='27mm' backright='27mm'>".$footer(2)."<div style='margin-bottom:1.5mm;margin-top:-8mm'>
            <img src='".$logo_base64."' style='width:45mm;height:auto;' />
        </div>
            <div class='section-title'>Written Agreement</div>

            <div class='agreement-text'>This agreement is intended for Drug Crafters, LP, a 503A Pharmacy, to verify that both sterile and non-sterile compounds, that are prepared at Drug Crafters, are shipped to the Prescriber's office as a patient specific prescription and will be stored and handled properly. Please be advised that Drug Crafters complies with both State and Food and Drug Administration (FDA) 503A regulations. Your facility is required to comply with the following criteria:</div>
            <div class='agreement-text'><b>(A)</b> If prescriber decides to take on the role of caregiver or authorized agent of the patient by administering the patient's medication at their clinic, the prescriber understands: compounded medications may only be administered to the intended patient indicated on the prescription label; medications may not be distributed, upcharged or resold to the patient or sold to another patient or entity; prescriber will provide counseling and be available post therapy to the patient; and the prescriber agrees to provide to the patient advisory leaflets supplied in the shipment so that patient has access to information relevant to their medication.</div>
            <div class='agreement-text'><b>(B)</b> All prescriptions must be patient specific and include:</div>
            <table style='width:100%;font-size:9.5px;margin-bottom:2.5mm'><tr>
            <td style='width:50%;vertical-align:top;padding-right:5mm'><div class='bullet-item'>a. Patient name</div><div class='bullet-item'>b. Patient date of birth</div><div class='bullet-item'>c. Patient address</div><div class='bullet-item'>d. Patient phone number</div><div class='bullet-item'>e. Refills</div></td>
            <td style='width:50%;vertical-align:top'><div class='bullet-item'>f. Drug name</div><div class='bullet-item'>g. Drug strength</div><div class='bullet-item'>h. Drug quantity</div><div class='bullet-item'>i. Route of administration</div><div class='bullet-item'>j. Dosing frequency must justify day supply</div></td></tr></table>
            <div class='agreement-text'><b>(C)</b> Practitioners are required to include the following on their patient's chart:</div>
            <div style='margin-left:5mm;margin-bottom:2.5mm'><div class='bullet-item'>a. Medication order &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; c. Lot number of compound</div>
            <div class='bullet-item'>b. Medication administration record &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; d. Beyond use date of compound</div></div>
            <div class='agreement-text'><b>(D)</b> Submitting a Complaint</div>
            <div class='agreement-text'>a. If a patient has an adverse reaction, contact Drug Crafters Pharmacy so we can investigate what the reaction was caused from. Any additional complaints against the pharmacy can be submitted to the Texas State Board of Pharmacy.</div>
            <div class='agreement-text'>b. If Drug Crafters recalls a batch of compounds, we will immediately contact the physician and/or patient via telephone or email to let them know details about the recall. In addition, a letter will also be mailed out to the physician and/or patient with the same details.</div>

            <div style='margin-top:0.5mm'><table style='width:100%;border:0;border-spacing:0;border-collapse:collapse'><tr>
            <td style='width:2%;vertical-align:bottom;font-size:14px;padding-bottom:4mm;padding-right:2px;white-space:nowrap'>I</td>
            <td style='width:35%;vertical-align:bottom;padding:0'><div style='position:relative;height:8mm;border-bottom:1px solid #333;margin-bottom:0.5mm'><div style='position:absolute;bottom:1mm;left:0;right:0;line-height:1;text-align:center;width:100%'>".$supervising_physician_name."</div></div><div style='text-align:center;font-size:13px;color:#000'>(Prescriber's Name)</div></td>
            <td style='width:22%;vertical-align:bottom;text-align:center;font-size:14px;padding-bottom:4mm;padding:0 2px 4mm 2px;white-space:nowrap'>hereby certify that</td>
            <td style='width:39%;vertical-align:bottom;padding:0'><div style='position:relative;height:8mm;border-bottom:1px solid #333;margin-bottom:0.5mm'><div style='position:absolute;bottom:1mm;left:0;right:0;line-height:1;text-align:center;width:100%'>".$clinic_name_line."</div></div><div style='text-align:center;font-size:13px;color:#000'>(Clinic Name)</div></td></tr></table>
            <div class='agreement-text' style='margin-top:1.5mm;margin-bottom:0'>will handle both sterile and non-sterile compounds properly and will abide by the criteria listed above. I understand that failure to abide by this agreement can result in Drug Crafters terminating business with my clinic, and with any clinic, with which I am associated.</div>
            <table style='width:100%;border:0;margin-top:0mm'><tr>
            <td style='width:70%;vertical-align:bottom'><div style='border-bottom:1px solid #333;height:9mm;margin-bottom:0.5mm;padding:0 2mm;text-align:center;display:table-cell;vertical-align:middle;'>".(!empty($supervising_signature_base64) ? "<img src='".$supervising_signature_base64."' style='height:5mm;width:auto;display:inline-block;' />" : "")."</div><div style='font-size:13px;color:#000'>Prescribers' Signature:</div></td>
            <td style='width:30%;vertical-align:bottom;padding-left:3mm'><div style='border-bottom:1px solid #333;height:8mm;margin-bottom:0.5mm;padding:0 2mm;padding-bottom:0.5mm;position:relative'><div style='position:absolute;bottom:0.5mm;left:2mm;right:2mm'>".$date."</div></div><div style='font-size:13px;color:#000'>Date:</div></td></tr>
            <tr><td style='width:70%;vertical-align:bottom;padding-top:2mm'><div style='border-bottom:1px solid #333;height:6mm;margin-bottom:0.5mm'></div><div style='font-size:13px;color:#000'>Drug Crafters Pharmacist Signature:</div></td>
            <td style='width:30%;vertical-align:bottom;padding-left:3mm;padding-top:2mm'><div style='border-bottom:1px solid #333;height:6mm;margin-bottom:0.5mm'></div><div style='font-size:13px;color:#000'>Date:</div></td></tr></table></div>
        </page>
        ";

        // ============= Page 3 =============
        $html .= "<page backtop='15mm' backbottom='15mm' backleft='27mm' backright='27mm'>".$footer(3)."<div style='margin-bottom:1.5mm;margin-top:-8mm'>
            <img src='".$logo_base64."' style='width:45mm;height:auto;' />
        </div>
            <div class='section-title' style='margin-top:-1mm;margin-bottom:1mm'>Account Agreement</div>
            <div style='font-weight:bold;font-size:14px;margin-bottom:0.5mm'>IMPORTANT:</div>
            <div style='font-size:13px;margin-bottom:1.5mm'>Please provide a current copy of your State Medical License number and DEA number.</div>
            <div style='margin-bottom:2mm'>
                <table style='width:100%;border:0;border-spacing:0;border-collapse:collapse;margin-bottom:0'><tr>
                <td style='width:50%;padding:0;padding-right:3mm'><div class='field-label'>Date:</div></td>
                <td style='width:50%;padding:0'><div class='field-label'>NPI:</div></td></tr></table>
                <table style='width:100%;border:0;border-spacing:0;border-collapse:collapse'><tr>
                <td style='width:50%;padding:0 0 0.5mm 0;padding-right:3mm;border-bottom:1px solid #333;vertical-align:bottom;color:#000'>".$date."</td>
                <td style='width:50%;padding:0 0 0.5mm 0;border-bottom:1px solid #333;vertical-align:bottom;color:#000'>".$marie_npi."</td></tr></table>
            </div>
            <div class='form-field' style='margin-bottom:2mm'><div class='field-label'>Authorized Prescriber Name/Title:</div><div class='field-line'>".$supervising_physician_name."</div></div>
            <div class='form-field' style='margin-bottom:2mm'><div class='field-label'>License #:</div><div class='field-line'>".$supervising_physician_license."</div></div>
            <div class='form-field' style='margin-bottom:2mm'><div class='field-label'>Address:</div><div class='field-line'>".$prescriber_data['address']."</div></div>
            <div style='margin-bottom:2mm'>
                <table style='width:100%;border:0;border-spacing:0;border-collapse:collapse;margin-bottom:0'><tr>
                <td style='width:45%;padding:0;padding-right:3mm'><div class='field-label'>City:</div></td>
                <td style='width:30%;padding:0;padding-right:3mm'><div class='field-label'>State:</div></td>
                <td style='width:25%;padding:0'><div class='field-label'>Zip Code:</div></td></tr></table>
                <table style='width:100%;border:0;border-spacing:0;border-collapse:collapse'><tr>
                <td style='width:45%;padding:0 0 1mm 0;padding-right:3mm;border-bottom:1px solid #333;vertical-align:top;color:#000'>".$prescriber_data['city']."</td>
                <td style='width:30%;padding:0 0 1mm 0;padding-right:3mm;border-bottom:1px solid #333;vertical-align:top;color:#000'>".$prescriber_data['state']."</td>
                <td style='width:25%;padding:0 0 1mm 0;border-bottom:1px solid #333;vertical-align:top;color:#000'>".$prescriber_data['zip_code']."</td></tr></table>
            </div>
            <div class='form-field' style='margin-bottom:2mm'><div class='field-label'>Phone:</div><div class='field-line'>".$prescriber_data['phone']."</div></div>

            <div style='font-size:11px;line-height:1.1;margin-top:2mm;margin-bottom:6mm'>
            <div class='checkbox-item' style='margin-bottom:0.8mm'>• I understand that state laws require a prescriber to have a valid practitioner-patient relationship, as defined by TX Pharmacy Act Sec 562.056. Our office does not prescribe hormones or any other drugs by phone or internet, without first meeting the patient and establishing a relationship.</div>
            <div class='checkbox-item' style='margin-bottom:0.8mm'>• I understand that the law for prescribing and dispensing hormones, Human Growth Hormone and anabolic steroids includes a recognized medical condition. I DO NOT prescribe hormones, Human Growth Hormone and anabolic steroids for anti-aging, sports performance enhancement, or body building.</div>
            <div class='checkbox-item' style='margin-bottom:0.8mm'>• Pursuant to Texas Occupations code, Title 3, Subchapter 3, subtitle J, Subchapter B - I understand that I am responsible for the education, medical actions, and activity of those hereto who I have delegated authority to be my designated agent.</div>
            <div class='checkbox-item' style='margin-bottom:0.8mm'>• I agree to maintain supervision and oversight of all prescriptions, to ensure reasonable patient safety.</div></div>
            <div style='font-weight:bold;font-size:12px;margin-top:6mm;margin-bottom:4mm;line-height:1.1'>If you are a license Nurse Practitioner, Physician Assistant and will be prescribing medications (verbal or signed), the Texas State Board of Pharmacy 291.34 22 TAC, Part 15) requires you to have a supervising physician that has delegated you such prescriptive authority. This applies whether or not the state you practice in requires a supervising physician or not.</div>
            <div style='margin-top:3mm;margin-bottom:2mm'><div style='border-bottom:1px solid #333;height:4mm;margin-bottom:0.5mm;padding:0 2mm'>".$supervising_physician_name."</div><div style='font-size:13px;color:#000'>Supervising Physician:</div></div>
            <table style='width:100%;border:0;margin-top:2mm'><tr>
            <td style='width:75%;vertical-align:bottom'><div style='border-bottom:1px solid #333;height:10mm;margin-bottom:0.5mm;padding:0 2mm;text-align:center;display:table-cell;vertical-align:middle;'>".(!empty($supervising_signature_base64) ? "<img src='".$supervising_signature_base64."' style='height:5mm;width:auto;display:inline-block;' />" : "")."</div><div style='font-size:13px;color:#000'>Supervising Physician Signature:</div></td>
            <td style='width:25%;vertical-align:bottom;padding-left:3mm'><div style='border-bottom:1px solid #333;height:7mm;margin-bottom:0.5mm;padding:0 2mm;padding-bottom:0.5mm;position:relative'><div style='position:absolute;bottom:0.5mm;left:2mm;right:2mm'>".$date."</div></div><div style='font-size:13px;color:#000'>Date:</div></td></tr></table>
            <table style='width:100%;border:0;margin-top:2.5mm;margin-bottom:0mm'><tr>
            <td style='width:50%;vertical-align:bottom;padding-right:3mm'><div style='border-bottom:1px solid #333;height:5mm;margin-bottom:0.5mm;padding:0mm;padding-bottom:0.5mm;position:relative'><div style='position:absolute;bottom:0.5mm;left:0'>".$supervising_physician_license."</div></div><div style='font-size:13px;color:#000'>Supervising Physician License #:</div></td>
            <td style='width:50%;vertical-align:bottom'><div style='border-bottom:1px solid #333;height:5mm;margin-bottom:0.5mm;padding:0mm;padding-bottom:0.5mm;position:relative'><div style='position:absolute;bottom:0.5mm;left:0'>".$supervising_physician_dea."</div></div><div style='font-size:13px;color:#000'>Supervising Physician DEA #:</div></td></tr></table>
        </page>
        ";

        // ============= Page 4 =============
        $html .= "<page backtop='15mm' backbottom='15mm' backleft='27mm' backright='27mm'>
            <page_footer>
                <div style='margin:0 18mm 10mm 18mm'>
                    <table style='width:100%;font-size:15px;border:0'>
                        <tr>
                            <td style='width:100%;text-align:right;color:#777'>(Page 4 of 4)</td>
                        </tr>
                    </table>
                </div>
            </page_footer>".$header."
            <div style='font-size:14px;font-weight:bold;margin-bottom:5mm'>My Spa Live-Concierge Health</div>
            <div style='font-size:14px;font-weight:bold;margin-bottom:8mm'>Confirmation Waiver to Ship Prescriptions to a Home Address:</div>
            <div style='font-size:14px;line-height:1.8;margin:10mm 0'>Please accept this waiver as my confirmation that my business is a concierge business model and I require prescriptions to be sent to a home address, rather than a business address.</div>
            <div style='margin-top:0mm;margin-bottom:3mm'>Thank you,</div>
            <div style='margin-top:0mm'><div style='border-bottom:1px solid #333;width:70%;height:18mm;margin-bottom:2mm;padding:0 2mm;text-align:center;display:table-cell;vertical-align:middle;'>".(!empty($prescriber_signature_base64) ? "<img src='".$prescriber_signature_base64."' style='height:16mm;width:auto;display:inline-block;' />" : "")."</div>
            <div style='font-size:14px'>Clinic Representative</div></div>
        </page>
        ";

        error_reporting($original_error_reporting);
        
        if($return_html){
            return $html;
        }

        return $html;
    }

    public function test_drug_crafters_pdf(){
        $this->loadModel('SpaLiveV1.SysUsers');

        $user_id = get('user_id', 6657);
        $ent_user = $this->SysUsers->find()->where(['id' => $user_id])->first();

        if(empty($ent_user)){
            echo "Usuario no encontrado con ID: {$user_id}";
            die();
        }

        // Generar HTML del PDF (usa datos de Marie Beauvoir por defecto)
        $html_content = $this->drug_crafters_application_pdf($ent_user, null, true);

        // Convertir a PDF - FORMATO CARTA (Letter) con márgenes ajustados
        $filename = TMP . 'files' . DS . 'drug_crafters_application.pdf';
        $html2pdf = new HTML2PDF('P', 'Letter', 'en', true, 'UTF-8', array(0, 0, 0, 0));
        $html2pdf->WriteHTML($html_content);
        $html2pdf->Output($filename, 'F');
        $html2pdf->Output('drug_crafters_application.pdf', 'I');
        
        exit;
    }



    public function test_send_email(){
        $this->loadModel('SpaLiveV1.SysUsers');

        $ent_user = $this->SysUsers->find()->where(['id' => 10007])->first();

        $purchase_id = 4227;

        $iv_products = $this->bought_iv_therapy_products($purchase_id);
 
        $user =  json_decode('{"id":10007,"uid":"e92edb49-9dcb-418a-bb7c-fc5e0ace0595","short_uid":"71384NI0","name":"shon","mname":"","lname":"micol","description":"","email":"shon@micol.com","password":"080eaaeb6a557bc3d2d7648abca3b3b722cb4dcac0854d81d0447c1a9c98da0b","type":"injector","state":43,"zip":65487,"city":"city","street":"address ","suite":"546","phone":"5468484545","dob":"2006-01-17","gender":"Female","bname":"","ein":"","active":1,"login_status":"PAYMENT","latitude":37.462127,"longitude":-91.566241,"radius":39,"score":0,"photo_id":93,"stripe_account_confirm":0,"stripe_account":"acct_1OZzFrRXwqBJjpjt","i_nine_id":0,"ten_nintynine_id":0,"amount":79500,"payment":"","payment_intent":"","receipt_url":"","is_test":0,"enable_notifications":1,"deleted":0,"created":"2024-01-17T11:27:05-06:00","createdby":0,"modified":"2024-01-18T14:37:19-06:00","modifiedby":0,"show_in_map":1,"show_most_review":"DEFAULT","last_status_change":"2024-01-18T11:19:48-06:00","custom_pay":0,"md_id":240,"steps":"HOME","spa_work":false,"sales_rep_status":null,"treatment_type":null,"provider_url":"shonmicol","speak_spanish":false,"branch_manager":false,"filler_check":false}', true);
        $iv_products = json_decode('[{"qty":1,"Product":{"id":"186","name":"Biotin","category":"IV VIALS","item_description":"100mg \/mL"}},{"qty":1,"Product":{"id":"187","name":"Folic Acid","category":"IV VIALS","item_description":""}},{"qty":1,"Product":{"id":"203","name":"Inositol","category":"IV VIALS","item_description":"50mg\/ mL"}},{"qty":1,"Product":{"id":"204","name":"L-Carnitine","category":"IV VIALS","item_description":"500mg\/ mL"}},{"qty":1,"Product":{"id":"205","name":"Methylcobalamin","category":"IV VIALS","item_description":"1mg\/ mL"}},{"qty":1,"Product":{"id":"206","name":"MIC Lipo Slim","category":"IV VIALS","item_description":""}},{"qty":1,"Product":{"id":"207","name":"Myers cocktail IM","category":"IV VIALS","item_description":""}},{"qty":1,"Product":{"id":"208","name":"NAD+","category":"IV VIALS","item_description":"200mg\/ mL"}},{"qty":1,"Product":{"id":"209","name":"Taurine","category":"IV VIALS","item_description":"50mg\/ mL"}},{"qty":1,"Product":{"id":"210","name":"Thiamine HCL","category":"IV VIALS","item_description":"100mg\/ mL"}},{"qty":1,"Product":{"id":"211","name":"Vit D3 - Oil","category":"IV VIALS","item_description":"100,000IU\/ mL"}},{"qty":1,"Product":{"id":"212","name":"Vit D3 - Oil","category":"IV VIALS","item_description":"50,000IU\/ mL"}},{"qty":1,"Product":{"id":"213","name":"Zinc Sulfate","category":"IV VIALS","item_description":"10mg\/ mL"}},{"qty":1,"Product":{"id":"214","name":"Myers Cocktail IV","category":"IV VIALS","item_description":"50mL"}},{"qty":1,"Product":{"id":"215","name":"Immune Cocktail Formula #2","category":"IV VIALS","item_description":"30mL"}},{"qty":1,"Product":{"id":"216","name":"MIC + Carnitine + Methyl B12","category":"IV VIALS","item_description":"30mL"}},{"qty":1,"Product":{"id":"217","name":"Vitamin B Complex +Vit C MDV","category":"IV VIALS","item_description":"30mL"}},{"qty":1,"Product":{"id":"218","name":"Trace Min-5 MDV","category":"IV VIALS","item_description":"10mL"}},{"qty":1,"Product":{"id":"219","name":"Cal-Mag MDV 50mg-100mg\/mL","category":"IV VIALS","item_description":"50mL"}},{"qty":1,"Product":{"id":"220","name":"Vitamin B Complex + Vit C","category":"IV VIALS","item_description":"30mL"}}]',true);

        //$response = $this->send_email_to_pharmacy($ent_user,$iv_products,$purchase_id);
        //$response = $this->credit_pdf($ent_user,false);
        //$response = $this->pharmacy_pdf($ent_user, true, $iv_products);
        $html_content = $this->pharmacy_pdf($user, false, $iv_products);

        $inyector_name = $user['mname'] == '' ? trim($user['name']).' '.trim($user['lname']) : trim($user['name']).' '.trim($user['mname']).' '.trim($user['lname']);

        $Otherservices = new OtherservicesController();
        $is_test_patient = $Otherservices->check_test($inyector_name);
        
        $filename = TMP . 'files' . DS . 'pharmacy_pdf.pdf';
        $html2pdf = new HTML2PDF('P','A4','en', true, 'UTF-8', array(10, 10, 10, 10));
        $html2pdf->WriteHTML($html_content);
        $html2pdf->Output($filename, 'F');
/*        $filename = TMP . 'files' . DS . 'pharmacy_pdf.pdf';
        $html2pdf = new HTML2PDF('P','A4','en', true, 'UTF-8', array(10, 10, 10, 10));
        $html2pdf->WriteHTML($response);
        $html2pdf->Output($filename, 'F');*/
        $this->set("response", $html_content);
    }

    public function bought_iv_therapy_products($purchase_id){
        $this->loadModel('SpaLiveV1.DataPurchasesDetail');
    
        $ent_purchases_detail = $this->DataPurchasesDetail->find()->select(['Product.id','Product.name','Product.category','Product.item_description','DataPurchasesDetail.qty'])
        ->join([
            'Product' => ['table' => 'cat_products', 'type' => 'INNER', 'conditions' => 'Product.id = DataPurchasesDetail.product_id']
        ])->where(['DataPurchasesDetail.purchase_id' => $purchase_id])->order(['DataPurchasesDetail.id' => 'DESC'])->all();

        $iv_products = array();

        if(!empty($ent_purchases_detail)){

            foreach ($ent_purchases_detail as $row) {
                if($row["Product"]["category"] == "IV VIALS"){
                    $iv_products[] = $row;
                }
            }
        }

        return $iv_products;
    }

    public function purchased_iv_therapy($user_id, $purchase_id){
        $this->loadModel('SpaLiveV1.DataPurchasesDetail');

        $iv_vials = $this->DataPurchasesDetail->find()
        ->join([
            'Purchase' => ['table' => 'data_purchases', 'type' => 'INNER', 'conditions' => 'Purchase.id = DataPurchasesDetail.purchase_id'],
            'Product' => ['table' => 'cat_products', 'type' => 'INNER', 'conditions' => 'Product.id = DataPurchasesDetail.product_id']
        ])->where(['Product.category' => "IV VIALS", 'Purchase.deleted' => 0, 'Purchase.user_id' => $user_id->id, 
                    'Purchase.id !=' => $purchase_id])->group('Purchase.id')->all();

        if(count($iv_vials) > 0){
            return false;
        }else{
            return true;
        }
    }

    public function save_iv_purchase_detail(){

        $token = get('token',"");
        
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

        $this->loadModel('DataPurchaseDetailIv');
        $this->loadModel('DataPurchases');

        $uid = get('uid', '');
        $patient = $this->SysUsers->find()->where(['uid' => $uid])->first();

        $name = $patient->name;
        $lname = $patient->lname;
        $dob =$patient->dob;
        
        $purchase_uid = get('purchase_uid', '');

        $purchase = $this->DataPurchases->find()->where(['uid' => $purchase_uid])->first();

        if(empty($purchase)){
            $this->message('purchase not found');
            exit;
        }

        if(empty($name) || empty($lname) || empty($dob)){
            $this->message('field empty');
            exit;
        }

        $find = $this->DataPurchaseDetailIv->find()->where(['purchase_id' => $purchase->id])->first();

        if(!empty($find)){
            $this->DataPurchaseDetailIv->updateAll(
                ['name' => $name, 'lname' => $lname, 'dob' => $dob], 
                ['purchase_id' => $purchase->id]
            );
            $this->success();
        }else{
            $array_save = array(
                'purchase_id' => $purchase->id,
                'name' => $name,
                'lname' => $lname,
                'dob' => $dob
            );

            $entity = $this->DataPurchaseDetailIv->newEntity($array_save);
            if(!$entity->hasErrors()){
                $res = $this->DataPurchaseDetailIv->save($entity);
                $this->success();
            }
        }


    }

    public function save_iv_form_info($data = array()){

        if(empty($data)){
            return false;
        }

        if(!isset($data['practice_name'])){
            return false;
        }

        if(!isset($data['primary_contact_name'])){
            return false;
        }

        if(!isset($data['phone'])){
            return false;
        }

        if(!isset($data['office_hour'])){
            return false;
        }

        if(!isset($data['clinic_name'])){
            return false;
        }

        if(!isset($data['npi'])){
            return false;
        }

        if(!isset($data['sign_url'])){
            return false;
        }

        if(!isset($data['user_id'])){
            return false;
        }

        $user = $this->SysUsers->find()->where(['id' => $data['user_id']])->first();

        if(empty($user)){
            return false;
        }

        $this->loadModel('SpaLiveV1.DataFormInfoDc');

        $array_save = array(
            'user_id' => $data['user_id'],
            'practice_name' => $data['practice_name'],
            'prescriber_name' => 'Dr. Marie Beauvoir',
            'primary_contact_name' => $data['primary_contact_name'],
            'phone' => $data['phone'],
            'office_hour' => $data['office_hour'],
            'clinic_name' => $data['clinic_name'],
            'created' => date('Y-m-d H:i:s'),
            'created_by' => $user->id,
            'modified' => date('Y-m-d H:i:s'),
            'modified_by' => $user->id,
            'deleted' => 0,
            'sign_url' => $data['sign_url'],
            'npi' => $data['npi'],
        );

        $entity = $this->DataFormInfoDc->newEntity($array_save);
        if(!$entity->hasErrors()){
            $res = $this->DataFormInfoDc->save($entity);
            if($res){
                return true;
            }
        }
        return false;
    }

    public function get_iv_form_info($user_id){
        $this->loadModel('SpaLiveV1.DataFormInfoDc');

        if(!isset($user_id)){
            return false;
        }

        $form_info = $this->DataFormInfoDc->find()->where(['user_id' => $user_id, 'deleted' => 0])->first();

        if(empty($form_info)){
            return false;
        }

        return $form_info;
    }

   #endregion
}