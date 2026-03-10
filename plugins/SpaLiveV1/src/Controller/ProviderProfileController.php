<?php
declare(strict_types=1);

namespace SpaLiveV1\Controller;

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
use Cake\Http\Client as HttpClient;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use \PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

use SpaLiveV1\Controller\SubscriptionController;
use SpaLiveV1\Controller\CourseController;
use SpaLiveV1\Controller\Data\ProviderProfile;
use SpaLiveV1\Controller\Data\ProfileAttributes;
#endregion

class ProviderProfileController extends AppPluginController {
    
    #region INITIALIZE
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
    private $URL_API = "";
    private $URL_WEB = "";
    private $URL_ASSETS = "";
    private $URL_PANEL = "";
    private $str_name;

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
    #endregion

    #region ENDPOINTS
    
    public function profile()
    {
        $uid_provider = get('uid_provider', '');
        if($uid_provider === ''){
            $this->message('uid_provider is required');
            return;
        }

        $this->loadModel('SpaLiveV1.SysUsers');
        $user_ent = $this->SysUsers->find()->where(['SysUsers.uid' => $uid_provider])->first(); 

        if(empty($user_ent)){
            $this->message('User not found');
            return;
        }

        $profile = ProviderProfile::get_profile_data($uid_provider);             
        $this->set('profile', $profile);
        $this->success();
    }

    public function find_by_url(){
        $provider_url = get('provider_url', '');
        if($provider_url === ''){
            $this->message('The url provided is empty');
            return;
        }

        $this->loadModel('SpaLiveV1.SysUsers');
        $user_ent = $this->SysUsers->find()->where(['SysUsers.provider_url' => $provider_url, 'SysUsers.deleted' => 0,'SysUsers.active'=>1])->first();

        if(empty($user_ent)){
            $this->message('We\'re not able to find a Certified Injector with this url.');
            return;
        }

        $profile = ProviderProfile::get_profile_data($user_ent->uid);
        $this->set('profile', $profile);
        $this->success();
    }

    public function update_instagram_profile()
    {
        if(!$this->validate_session()) return;
        
        $instagram_profile = get('instagram_profile', '');
        $show_instagram_profile = get('show_instagram_profile', 1);
        if(empty($instagram_profile)){
            $this->message('Instagram profile empty');
            return;
        }             
        
        $instagram_arr = array(
            'show_instagram'    => $show_instagram_profile == 1,
            'instagram_profile' => $instagram_profile,    
            'is_profile_setup'  => true,  
        );
        
        $instagram_encoded = json_encode($instagram_arr);

        $updated = $this->update_profile(
            ProfileAttributes::INSTAGRAM, 
            $instagram_encoded, 
            USER_ID
        );

        if(!$updated){
            $this->message('We\'re unable to update your profile');
            return;
        }

        $this->success();
    }

    public function update_show_rating()
    {
        if(!$this->validate_session()) return;
        
        $show_rating = get('show_rating', -1);

        if($show_rating < 0){
            $this->message('Show rating empty');
            return;
        }             

        $updated = $this->update_profile(
            ProfileAttributes::RATING, 
            $show_rating, 
            USER_ID
        );

        if(!$updated){
            $this->message('We\'re unable to update your profile');
            return;
        }

        $this->success();
    }

    public function update_reviews()
    {
        if(!$this->validate_session()) return;
        
        $id_review = get('id_review', -1);

        if($id_review == -1){
            $this->message('Review empty');
            return;
        }

        $this->loadModel('SpaLiveV1.DataProviderProfile'); 
        $ent_profile = $this->DataProviderProfile->find()->where(['DataProviderProfile.injector_id' => USER_ID])->first(); 

        $reviews = array();        
        if(isset($ent_profile->reviews)){
            $reviews = explode(',', $ent_profile->reviews);
        }
            
        if(in_array($id_review, $reviews)){
            $reviews = array_diff($reviews, array($id_review));
        }else{
            array_push($reviews, $id_review);
        }
        
        
        $reviews_string = count($reviews) == 0 
            ? NULL
            : implode(',', $reviews);
        
        
        $updated = $this->update_profile(
            ProfileAttributes::REVIEWS, 
            $reviews_string, 
            USER_ID
        );

        if(!$updated){
            $this->message('We\'re unable to update your profile');
            return;
        }
        $this->set('reviews_string', $reviews_string ?? '');        
        $this->success();
    }

    public function update_before_after()
    {
        if(!$this->validate_session()) return;
        
        $before_after = get('before_after', '');
        
        if(empty($before_after)){
            $before_after = NULL;
        }

        $updated = $this->update_profile(
            ProfileAttributes::BEFORE_AFTER, 
            $before_after, 
            USER_ID
        );

        if(!$updated){
            $this->message('We\'re unable to update your profile');
            return;
        }

        $this->success();
    }

    public function unset_instagram_profile()
    {
        if(!$this->validate_session()) return;
        
        $instagram_arr = array(
            'show_instagram'    => false,
            'instagram_profile' => '',    
            'is_profile_setup'  => false,  
        );
        
        $instagram_encoded = json_encode($instagram_arr);

        $updated = $this->update_profile(
            ProfileAttributes::INSTAGRAM, 
            $instagram_encoded, 
            USER_ID
        );

        if(!$updated){
            $this->message('We\'re unable to update your profile');
            return;
        }

        $this->success();
    }

    public function get_reviews_provider()
    {
        if(!$this->validate_session()) return;

        $reviews = $this->provider_reviews(USER_ID);
        $this->set('reviews', $reviews);
        $this->success();
    }

    public function get_before_after()
    {
        if(!$this->validate_session()) return;

        $where = array(
            'DataTreatment.assistance_id' => USER_ID,
            'DataTreatment.deleted' => 0,
            'DataTreatment.concent_share_images' => 1,
            'DataTreatment.status' => 'DONE',                  
        );  

        $extra_fields = ['Patient.name', 'Patient.lname', 'DataTreatment.schedule_date'];

        $treatments_provider = $this->get_treatments_injector($where, $extra_fields);

        $treatment_ba = array();
        foreach ($treatments_provider as $key => $treatment) {
            $treatment_id = $treatment['id'];   
            $patient_name = $treatment['Patient']['name'];
            $patient_lname = $treatment['Patient']['lname'];    
            $schedule_date = $treatment['schedule_date'];
            $treatment_images = $this->before_after_treatment_images($treatment_id); 

            $treatment_ba[] = array(
                'id' => $treatment_id,
                'patient_name' => $patient_name,
                'patient_lname' => $patient_lname,  
                'schedule_date' => $schedule_date,
                'before' => $treatment_images['before'],
                'after' => $treatment_images['after'],            
            );
        }   

        $this->set('before_after', $treatment_ba);
        $this->success();
    }

    public function upload_image()
    {
        if(!$this->validate_session()) return;
        $this->loadModel('DataProviderProfile');
        $ent_provider = $this->DataProviderProfile->find()->where(['DataProviderProfile.injector_id' => USER_ID])->first();
        
        $file_id = 0;
        $images  = isset($ent_provider->images) && !empty($ent_provider->images) 
            ? explode(',', $ent_provider->images)     
            : array();        

        $file_id = $this->upload_file();

        if($file_id == 0){
            $this->message('We\'re unable to upload your image');
            return;
        }       

        $images[] = $file_id;

        $updated = $this->update_profile(
            ProfileAttributes::IMAGES, 
            implode(',', $images), 
            USER_ID
        );

        if(!$updated){
            $this->message('We\'re unable to update your profile');
            return;
        }

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

        $this->loadModel('DataProviderProfile');
        $ent_provider = $this->DataProviderProfile->find()->where(['DataProviderProfile.injector_id' => USER_ID])->first();
        
        $images  = isset($ent_provider->images) && !empty($ent_provider->images) 
            ? explode(',', $ent_provider->images)     
            : array();

        if (!in_array($image_id, $images)) {
            $this->message('Image not found');
            return;
        }

        $images = array_diff($images, array($image_id));

        $updated = $this->update_profile(
            ProfileAttributes::IMAGES, 
            count($images) > 0
                ? implode(',', $images)
                : NULL, 
            USER_ID
        );

        if(!$updated){
            $this->message('We\'re unable to update your profile');
            return;
        }

        $this->success();
    }

    public function update_featured()
    {
        if(!$this->validate_session()) return;
        
        $featured = get('featured', '');

        if(empty($featured)){
            $this->message('Featured empty');
            return;
        }             

        if(count(explode(',', $featured)) > 4){
            $this->message('You can only have up to 4 featured images');
            return;
        }

        $updated = $this->update_profile(
            ProfileAttributes::FEATURED, 
            $featured, 
            USER_ID
        );

        if(!$updated){
            $this->message('We\'re unable to update your profile');
            return;
        }

        $this->success();
    }

    public function delete_featured()
    {
        if(!$this->validate_session()) return;

        $image_id = get('image_id', 0);
        if($image_id == 0){
            $this->message('Image id is required');
            return;
        }

        $this->loadModel('DataProviderProfile');
        $ent_provider = $this->DataProviderProfile->find()->where(['DataProviderProfile.injector_id' => USER_ID])->first();
        
        $images  = isset($ent_provider->featured) && !empty($ent_provider->featured) 
            ? explode(',', $ent_provider->featured)     
            : array();

        if (!in_array($image_id, $images)) {
            $this->message('Image not found');
            return;
        }

        $images = array_diff($images, array($image_id));

        $updated = $this->update_profile(
            ProfileAttributes::FEATURED, 
            count($images) > 0
                ? implode(',', $images)
                : NULL, 
            USER_ID
        );

        if(!$updated){
            $this->message('We\'re unable to update your profile');
            return;
        }

        $this->success();
    }

    // This function is used to get the featured images of an injector
    public function get_img_featured() {
        $this->loadModel('SpaLiveV1.SysUsers');
        
        //l3n4p=6092482f7ce858.91169218
        $panel = get('l3n4p', '');
        $photo_id = get('id', '');
        if(empty($panel) || (!empty($panel) && $panel != '6092482f7ce858.91169218')){
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
    
            $file = $this->Files->output($photo_id);
            exit;    
        }else{
            //$file_id = $this->Files->uid_to_id(get('uid', ''));
            //$file = $this->Files->output($file_id);
            
            $file = $this->Files->output($photo_id);
            exit;
        }
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

    public function check_url_exist($url)
    {
        $this->loadModel('SpaLiveV1.SysUsers');              
        $ent_repeated_url = $this->SysUsers->find()
        ->where([
            "SysUsers.provider_url" => $url,
        ])->all();
        return count($ent_repeated_url) > 0;
    }       

    public function provider_reviews($id_provider)
    {
        $this->loadModel('SpaLiveV1.DataTreatmentReviews');
        $this->loadModel('SpaLiveV1.DataProviderProfile');
        $ent_reviews = $this->DataTreatmentReviews->find()->where(['DataTreatmentReviews.injector_id IN' => $id_provider])->all();
        $ent_profile = $this->DataProviderProfile->find()->where(['DataProviderProfile.injector_id' => $id_provider])->first();
        $array_reviews = array();
        if(isset($ent_profile->reviews)){
            $array_reviews = explode(',', $ent_profile->reviews);
        }
        $reviews = array();
        foreach($ent_reviews as $row){
            $reviews[] = array(
                'id' => $row->id,
                'comments' => $row->comments,
                'created' => $row->created,
                'show' => in_array($row->id, $array_reviews),
                #'starts' => $row->starts,  
            );
        }
        return $reviews;
    }

    public function before_after_treatment_images($id_treatment)
    {
        $this->loadModel('SpaLiveV1.DataTreatmentImage');
        $afterImages = $this->DataTreatmentImage->find()
            ->select(['DataTreatmentImage.file_id'])
            ->where([
                'DataTreatmentImage.treatment_id' => $id_treatment, 
                'DataTreatmentImage.typeImage' => 'after', 
            ])->toArray(); 

        $beforeImages = $this->DataTreatmentImage->find()
            ->select(['DataTreatmentImage.file_id'])
            ->where([
                'DataTreatmentImage.treatment_id' => $id_treatment, 
                'DataTreatmentImage.typeImage' => 'before', 
            ])->toArray(); 
        
        return array(
            'after'  =>  isset($afterImages)  ? Hash::extract($afterImages,  '{n}.file_id') : [],
            'before' =>  isset($beforeImages) ? Hash::extract($beforeImages, '{n}.file_id') : [],   
        );
    }

    private function update_profile($profileAttribute, $value, $user_id)
    {
        $this->loadModel('SpaLiveV1.DataProviderProfile'); 
        $ent_profile = $this->DataProviderProfile->find()->where(['DataProviderProfile.injector_id' => $user_id])->first();
        if(empty($ent_profile)){
            return false;
        }
        switch ($profileAttribute) {
            case ProfileAttributes::INSTAGRAM    : $ent_profile->instagram_profile = $value; break;
            case ProfileAttributes::RATING       : $ent_profile->show_rating       = $value; break;
            case ProfileAttributes::REVIEWS      : $ent_profile->reviews           = $value; break;            
            case ProfileAttributes::BEFORE_AFTER : $ent_profile->before_after      = $value; break;
            case ProfileAttributes::FEATURED     : $ent_profile->featured          = $value; break;
            case ProfileAttributes::IMAGES       : $ent_profile->images            = $value; break;
        }

        return $this->DataProviderProfile->save($ent_profile);
    }
    
    private function get_treatments_injector($where, $extra_fields = [])
    {
        $select_fields = ['DataTreatment.id'];
        $select_fields = array_merge($select_fields, $extra_fields);

        $this->loadModel('SpaLiveV1.DataTreatment');
        $ent_treatments = $this->DataTreatment->find()
            ->select($select_fields)
            ->join([
                'Patient' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Patient.id = DataTreatment.patient_id AND Patient.deleted = 0'],
            ])
            ->where($where)
            ->all();
        return $ent_treatments; 
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

    private function edit_file($file_id) : int {

        //$file_id = get('file_id', 0);
        $ent_file = $this->Files->get_data_file($file_id);
        
        if (empty($ent_file)) {
            return 0;
        }

        if (!isset($_FILES['file'])) {        
            return 0;
        }

        if (!isset($_FILES['file']['name'])) {
            return 0;
        }

        $str_name = $_FILES['file']['name'];
        $_file_id = $this->Files->upload([
            'id' => $file_id,
            'name' => $str_name,
            'type' => $_FILES['file']['type'],
            'path' => $_FILES['file']['tmp_name'],
            'size' => $_FILES['file']['size'],
        ], $file_id);

        return $_file_id;
    }

    public function get_insta_auth_url() {
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

        $app_id = '279554517847774';
        $redirect_uri = 'https://blog.myspalive.com/inoauth.php';

        $params = [
            'client_id' => $app_id,
            'redirect_uri' => $redirect_uri,
            'scope' => 'user_profile,user_media',
            'response_type' => 'code'
        ];

        $auth_url = 'https://api.instagram.com/oauth/authorize?' . http_build_query($params);

        $this->loadModel('SpaLiveV1.DataProviderProfile');
                
        $ent_profile = $this->DataProviderProfile->find()->where(['DataProviderProfile.injector_id' => $user['user_id']])->first();

        if(empty($ent_profile->instagram_long_access_token)){
            $this->set('auth_url', $auth_url);
            $this->success();
            return;
        }

        $accessToken = $ent_profile->instagram_long_access_token;
        
        $url = "https://graph.instagram.com/me/media";

        $queryParams = http_build_query(array(
            "fields" => "id,media_type,media_url,username,timestamp",
            "access_token" => $accessToken
        ));
        $curl = curl_init();
        
        curl_setopt($curl, CURLOPT_URL, $url . '?' . $queryParams);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($curl);

        if ($response === false) {
            $errorNumber = curl_errno($curl);
            $errorMessage = curl_error($curl);
            
            // Acciones según el tipo de error
            switch ($errorNumber) {
                case CURLE_COULDNT_CONNECT:
                    $this->message("The connection to the server could not be established.");
                    $this->set('session', false);
                    return;
                    // Manejar el error de conexión
                    break;
                case CURLE_OPERATION_TIMEOUTED:
                    $this->message("Timeout.");
                    $this->set('session', false);
                    return;
                    // Manejar el error de tiempo de espera
                    break;
                default:
                    $this->set('error_message', $errorMessage);
                    $this->set('session', false);
                    return;
                    // Manejar el error de forma genérica
                    break;
            }
        }
        
        curl_close($curl);
        $responseData = json_decode($response);

        // Si responseData contiene un array error regresa el auth_url
        /* if ($responseData->error) {
            $this->set('auth_url', $auth_url);
            $this->success();
            return;
        } */

        // Regreso data con los datos obtenidos
        if (isset($responseData->data)) {
            $images = [];
            foreach ($responseData->data as $item) {
                if ($item->media_type === 'IMAGE') {
                    $images[] = $item;
                }
            }
            $this->set('data',$images);
            $this->set('session', true);
            $this->success();
            return;
        } else {
            $this->set('auth_url', $auth_url);
            $this->success();
            return;
        }
    }

    public function insta_save_access_token() {
        
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

        $url = 'https://api.instagram.com/oauth/access_token';
        $client_id = '279554517847774';
        $client_secret = '58807762ff6ffc7027d0c490e24f200d';
        $grant_type = 'authorization_code';
        $redirect_uri = 'https://blog.myspalive.com/inoauth.php';
        
        $code = get('code', '');
        if(empty($code)){
            $this->message('Code is required.');
            $this->set('session', false);
            return;
        }


        $fields = array(
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'grant_type' => $grant_type,
            'redirect_uri' => $redirect_uri,
            'code' => $code
        );

        // Inicializar la sesión CURL
        $curl = curl_init();

        // Configurar las opciones de la petición
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        // Realizar la petición y guardar la respuesta
        $response = curl_exec($curl);

        // Manejar errores de CURL
        if ($response === false) {
            $errorNumber = curl_errno($curl);
            $errorMessage = curl_error($curl);
            
            // Acciones según el tipo de error
            switch ($errorNumber) {
                case CURLE_COULDNT_CONNECT:
                    $this->message("The connection to the server could not be established.");
                    $this->set('session', false);
                    return;
                    // Manejar el error de conexión
                    break;
                case CURLE_OPERATION_TIMEOUTED:
                    $this->message("Timeout.");
                    $this->set('session', false);
                    return;
                    // Manejar el error de tiempo de espera
                    break;
                default:
                    $this->set('error_message', $errorMessage);
                    $this->set('session', false);
                    return;
                    // Manejar el error de forma genérica
                    break;
            }
        }

        // Cerrar la sesión CURL
        curl_close($curl);

        // Hacer algo con la respuesta
        $responseData = json_decode($response);

        // Verificar si se obtuvo el access_token correctamente
        if (isset($responseData->access_token)) {
            //* Generar un long access_token y despues guardarlo en la base de datos
            $this->loadModel('SpaLiveV1.DataProviderProfile');
            
            $ent_profile = $this->DataProviderProfile->find()->where(['DataProviderProfile.injector_id' => $user['user_id']])->first();
            
            // Guardar el user_id de instagram en la base de datos
            $insta_user_id = $responseData->user_id;
            $ent_profile->instagram_user_id = $insta_user_id;
            $this->DataProviderProfile->save($ent_profile);

            $accessToken = $responseData->access_token;

            $url = "https://graph.instagram.com/access_token";
            $queryParams = http_build_query(array(
                "grant_type" => "ig_exchange_token",
                "client_secret" => $client_secret,
                "access_token" => $accessToken
            ));

            $curl = curl_init();

            curl_setopt($curl, CURLOPT_URL, $url . '?' . $queryParams);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

            $response = curl_exec($curl);

            // Manejar errores de CURL
            if ($response === false) {
                $errorNumber = curl_errno($curl);
                $errorMessage = curl_error($curl);
                
                // Acciones según el tipo de error
                switch ($errorNumber) {
                    case CURLE_COULDNT_CONNECT:
                        $this->message("The connection on the second petition to the server could not be established.");
                        $this->set('session', false);
                        return;
                        // Manejar el error de conexión
                        break;
                    case CURLE_OPERATION_TIMEOUTED:
                        $this->message("Timeout on the second petition.");
                        $this->set('session', false);
                        return;
                        // Manejar el error de tiempo de espera
                        break;
                    default:
                        $this->set('error_message', $errorMessage);
                        $this->set('session', false);
                        return;
                        // Manejar el error de forma genérica
                        break;
                }
            }

            curl_close($curl);

            // Hacer algo con la respuesta
            $responseData = json_decode($response);

            // Verificar si se obtuvo el long_access_token correctamente
            if (isset($responseData->access_token)) {
                // Guardar los datos en la base de datos

                $accessToken = $responseData->access_token;
                
                // El token expira en 60 días
                //$expiresIn = $data['expires_in'];

                // Guardar el access_token en la base de datos
                $ent_profile->instagram_long_access_token = $accessToken;
                $this->DataProviderProfile->save($ent_profile);
                $this->success();
                $this->set('session', true);
                return;

            } else {
                // Mostrar el mensaje de error al no obtener el long_access_token
                $this->message($responseData->error_message);
                $this->set('session', true);
                return;
            }
        } else {
            // Mostrar el mensaje de error si no obtuvo el access_token
            $this->message($responseData->error_message);
            $this->set('session', true);
            return;
        }
    }

    public function get_documents_inj() {
        // Request for get all documents of the injector
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

        #region validate if the injector can upload documents
        $Summary = new SummaryController();
        $services = $Summary->services_injector(USER_ID);

        $have_permission = false;
        foreach ($services as $service) {
            if ($service['type'] == 'IV THERAPY' && $service['status'] == 'DONE') {
                $have_permission = true;
            } else if ($service['type'] == 'FILLERS' && $service['status'] == 'DONE') {
                $have_permission = true;
            }
        }

        $this->set('have_permission', $have_permission);
        #endregion

        $this->set('title_section', 'My Own Documents');
        $this->set('title_button', 'Upload Document');

        #region get documents

        $this->loadModel('SpaLiveV1.DataDocuments');
        $ent_documents = $this->DataDocuments->find()
            ->where([
                'DataDocuments.user_id' => USER_ID,
                'DataDocuments.deleted' => 0
            ])
            ->all();

        if (!empty($ent_documents)) {
            $documents = [];
            foreach ($ent_documents as $document) {
                $documents[] = [
                    'id' => $document->id,
                    'file_id' => $document->file_id,
                    'title' => $document->title,
                    'created' => $document->created->i18nFormat('yyyy-MM-dd HH:mm:ss'),
                ];
            }
            $this->set('documents', $documents);
        } else {
            $this->set('documents', []);
        }
        #endregion

        $this->success();
    }

    public function update_document() {
        // Request for update, delete or upload a document
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

        $type = get('type', '');
        if (empty($type)) {
            $this->message('Type is required.');
            return;
        }

        switch ($type) {
            case 'UPLOAD':
                $title = get('title', '');
                if (empty($title)) {
                    $this->message('Title is required.');
                    return;
                }

                $this->loadModel('SpaLiveV1.DataDocuments');
                $ent_document = $this->DataDocuments->find()
                    ->where([
                        'DataDocuments.title LIKE' => '%'.$title.'%',
                        'DataDocuments.user_id' => USER_ID,
                        'DataDocuments.deleted' => 0
                    ])
                    ->first();
                
                if (!empty($ent_document)) {
                    $this->set('document_id', $ent_document->id);
                    $this->set('title', $ent_document->title);
                    $this->set('exist_doc', true);
                    $this->success();
                    return;
                }

                $file_id = $this->upload_file();
                if ($file_id == 0) {
                    $this->message('We\'re unable to upload your document');
                    return;
                }

                $this->loadModel('SpaLiveV1.DataDocuments');
                $ent_document = $this->DataDocuments->newEmptyEntity();
                $ent_document->user_id = USER_ID;
                $ent_document->title = $title;
                $ent_document->file_id = $file_id;
                $ent_document->created = date('Y-m-d H:i:s');
                $ent_document->modified = date('Y-m-d H:i:s');
                $ent_document->deleted = 0;

                $result = $this->DataDocuments->save($ent_document);

                if ($result) {
                    $this->success();
                } else {
                    $this->message('We\'re unable to upload your document');
                }
                return;
            case 'DELETE':
                $document_id = get('document_id', 0);
                if ($document_id == 0) {
                    $this->message('Document id is required.');
                    return;
                }

                $this->loadModel('SpaLiveV1.DataDocuments');
                $ent_document = $this->DataDocuments->find()
                    ->where([
                        'DataDocuments.id' => $document_id,
                        'DataDocuments.user_id' => USER_ID,
                        'DataDocuments.deleted' => 0
                    ])
                    ->first();

                if (empty($ent_document)) {
                    $this->message('Document not found.');
                    return;
                }

                $ent_document->deleted = 1;
                $ent_document->modified = date('Y-m-d H:i:s');
                $result = $this->DataDocuments->save($ent_document);

                if ($result) {
                    $this->success();
                } else {
                    $this->message('We\'re unable to delete your document');
                }
                return;
            case 'EDIT':
                $document_id = get('document_id', 0);
                if ($document_id == 0) {
                    $this->message('Document id is required.');
                    return;
                }

                $title = get('title', '');
                if (empty($title)) {
                    $this->message('Title is required.');
                    return;
                }

                $this->loadModel('SpaLiveV1.DataDocuments');
                $ent_document = $this->DataDocuments->find()
                    ->where([
                        'DataDocuments.id' => $document_id,
                        'DataDocuments.user_id' => USER_ID,
                        'DataDocuments.deleted' => 0
                    ])
                    ->first();

                if (empty($ent_document)) {
                    $this->message('Document not found.');
                    return;
                }

                if (isset($_FILES['file'])) {        
                    $edit_file_id = $this->edit_file($ent_document->file_id);

                    if ($edit_file_id == 0) {
                        $this->message('We\'re unable to edit your document');
                        return;
                    }

                    $ent_document->title = $title;
                    $ent_document->modified = date('Y-m-d H:i:s');
                    $result = $this->DataDocuments->save($ent_document);

                    if ($result) {
                        $this->success();
                    } else {
                        $this->message('We\'re unable to edit your document');
                    }
                    return;
                }

                $ent_document->title = $title;
                $ent_document->modified = date('Y-m-d H:i:s');
                $result = $this->DataDocuments->save($ent_document);

                if ($result) {
                    $this->success();
                } else {
                    $this->message('We\'re unable to edit your document');
                }
                return;
            default:
                $this->message('Type not available');
                return;
        }
    }
    #endregion
}