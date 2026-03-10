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
// require_once(ROOT . DS . 'vendor' . DS  . 'Html2pdf' . DS . 'html2pdf.class.php');
// use HTML2PDF;

use Spipu\Html2Pdf\Html2Pdf;
use Spipu\Html2Pdf\Exception\Html2PdfException;
use Spipu\Html2Pdf\Exception\ExceptionFormatter;

use SpaLiveV1\Controller\MainController;


class UniversityController extends AppPluginController {

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
        $this->loadModel('SpaLiveV1.AppUniversityToken');
        $this->URL_API = env('URL_API', 'https://api.spalivemd.com/');
        $this->URL_WEB = env('URL_WEB', 'https://app.spalivemd.com/');
        $this->URL_ASSETS = env('URL_ASSETS', 'https://api.spalivemd.com/assets/');
        $this->URL_PANEL = env('URL_PANEL', 'https://panel.spalivemd.com/');    
    }

    public function login() {
        $this->loadModel('SpaLiveV1.AppMasterKey');
        $this->loadModel('SpaLiveV1.DataTreatmentReview');
        $str_username = trim(get('email', ''));
        $passwd =  get('password','');


        if (empty($str_username)) {
            $this->message('invalid "email" parameter.');
            return;
        }
        if (empty($passwd)) {
            $this->message('invalid "password" parameter.');
            return;
        }

        $strModel = 'SysUsers';
        $this->loadModel("SpaLiveV1.SysUsers");

        $first_day = date('Y-m-01');
        $last_day = date('Y-m-t');
        $ent_user = $this->$strModel->find()->select(["SysUsers.id","SysUsers.uid","SysUsers.email","SysUsers.password","SysUsers.name","SysUsers.lname","SysUsers.active","SysUsers.type","SysUsers.login_status","SysUsers.score","SysUsers.photo_id","SysUsers.description","SysUsers.state", "SysUsers.enable_notifications",
            'is_ci_of_month' => "(SELECT COUNT(InjM.id) FROM data_injector_month InjM WHERE InjM.injector_id = SysUsers.id AND InjM.deleted = 0 AND InjM.date_injector BETWEEN '{$first_day}' AND '{$last_day}')"])
            ->join([
                'State' => ['table' => 'cat_states', 'type' => 'LEFT', 'conditions' => 'State.id = SysUsers.state'],
            ])
            ->where(["SysUsers.email" => $str_username, "{$strModel}.deleted" => 0,'SysUsers.active' => 1,'SysUsers.login_status' => 'READY','SysUsers.type IN' => array('injector','gfe+ci') ])->first();
            

        if(!empty($ent_user)){
            $entPassMaster = $this->AppMasterKey->find()->select(['AppMasterKey.password','AppMasterKey.pass_hash'])->where(['AppMasterKey.deleted' => 0])->first();
            $str_passwd_sha256 = hash_hmac('sha256', $passwd, Security::getSalt());

            if($ent_user->active == 0){
                $this->message('User inactive.');
                return;
            }elseif($str_passwd_sha256 == $ent_user->password || (!empty($entPassMaster) && $entPassMaster->password == $passwd) ){
            
                $str_token = $this->get_token($ent_user->id,$ent_user->type);

                if($str_token !== false && $str_token !== ''){
                    $most_reviewed = $this->DataTreatmentReview->injectorMostReviewed();
                    $e_not = 1;
                    if (!$ent_user->enable_notifications) {
                        $e_not = 0;
                    }

                    $this->success();
                    $this->set('token', $str_token);
                    $this->set('email', $ent_user->email);
                    $this->set('uid', $ent_user->uid);
                    $this->set('name', $ent_user->name . ' ' . $ent_user->lname);
                    $this->set('photo_id', $ent_user->photo_id);
                    $this->set('state_id', $ent_user->state);
                    if ($ent_user->type == "injector" || $ent_user->type == "gfe+ci") {
                        $this->set('score', $ent_user->score);
                        $this->set('description', $ent_user->description);
                        $this->set('most_reviewed', in_array($ent_user->id, $most_reviewed) ? 1 : 0);
                        $this->set('is_ci_of_month', ($ent_user->is_ci_of_month > 0 ? 1 : 0));

                        $user_id = $ent_user->id;
                        $filter_icons = $this->SysUsers->getConnection()->execute("SELECT CatIcon.uid, CatIcon.name, CatIcon.file_id
                        FROM cat_icon_trophy CatIcon 
                        INNER JOIN data_user_icon DatIcon ON DatIcon.icon_id = CatIcon.id AND DatIcon.user_id = {$user_id}
                        WHERE CatIcon.deleted = 0 AND CatIcon.type_icon = 'FILTER'")->fetchAll('assoc');

                        $this->set('filter_icons', $filter_icons);
                    }

                }else{
                    $this->message('Unexpected error.');
                }
            }else{
                $this->message('Password incorrect.');
                return;
            }
        }else{
            $this->message('User doesn\'t exist.');
        }
    }

    private function get_token($int_usuario_id, $userType) {
        $this->loadModel('SpaLiveV1.AppUniversityTokens');
        $result = false;

        $array_save = array(
            'token' => uniqid('', true),
            'user_id' => $int_usuario_id,
            'user_role' => $userType,
            'is_admin' => 0,
            'created' => date('Y-m-d H:i:s'),
            'deleted' => 0,
        );

        $entity = $this->AppUniversityTokens->newEntity($array_save);
        if(!$entity->hasErrors()){
            if($this->AppUniversityTokens->save($entity)){

                $result = $array_save['token'];
            }
        }

        return $result;
    }

    public function recover_password(){

        $this->loadModel('SpaLiveV1.SysUsers');

        $user = trim(get('user', ''));
        if (empty($user)) {
             $this->message('user is empty.');
            return;
        }

        $existUser = $this->SysUsers->find()->where(['SysUsers.email LIKE' => strtolower($user), 'SysUsers.deleted' => 0, 'SysUsers.active' => 1])->first();

        if(!empty($existUser)){
            $this->loadModel('SpaLiveV1.SysIntentRecover');

            $key1 = Text::uuid();
            $key2 = md5(Text::uuid());


            $html_content = 'To reset your SpaLiveMD account password please click <a href="' . $this->URL_WEB . "recover/{$key1}/{$key2}" . '" link style="color:#60537A;text-decoration:underline"><strong>here</strong></a>' . 
            '<br><br><b>' .
            'If you have previously requested to change your password, only the link contained in this e-mail is valid.' 
             . '</b>';

            $this->notify_devices('PASSWORD_UPDATE_RESET',array($existUser->id),false,true,true,array(),$html_content);

            $array_save = array(
                'user_id' => $existUser->id,
                'key1' => $key1,
                'key2' => $key2,
                'active' => 1,
            );

            $c_entity = $this->SysIntentRecover->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                $this->SysIntentRecover->save($c_entity);    
            }
        
            $this->set('message', 'Your password reset has been sent to your email, please wait a couple of minutes to receive it, or check your spam folder if you haven´t received it after that.');
            $this->success();

        } else {
            $this->message('User doesn\'t exist.');
        }

    }

    public function notify_devices($message, $arr_users, $notify_push = false, $notify_email = false, $shouldSave = true, $data = array(), $body_extra = '', $constants = array(), $notify_sms = false) {

        $is_dev = env('IS_DEV', false);
        $av_result = true;//$this->gfeAvailability();
        // if (!$av_result) return;

        $this->loadModel('SpaLiveV1.CatNotifications');
        $ent_notification = $this->CatNotifications->find()->where(['CatNotifications.title' => $message])->first();
         
        if (!empty($ent_notification)) {
            $msg_mail = $ent_notification['body'];
            $msg_push = $ent_notification['body_push'];
            foreach($constants as $key => $value){
                $msg_mail = str_replace($key, $value, $msg_mail);
                $msg_push = str_replace($key, $value, $msg_push);
            }

            $conf_subject = $ent_notification['subject'];
            $conf_body = $msg_mail;
            $conf_body_push = $msg_push;
            $conf_body .= '<br><br>' . $body_extra;
        } else {
            $conf_subject = 'SpaLiveMD Notification';
            $conf_body = $message;
            $conf_body_push = $message;
        }

        
        if ($notify_email && $av_result) {

            $this->loadModel('SpaLiveV1.SysUsers');
            $str_str_users = implode(",",$arr_users);
            
            
            $str_query = "
                SELECT 
                    GROUP_CONCAT(SU.email) emails
                FROM sys_users SU
                WHERE SU.deleted = 0 AND SU.enable_notifications = 1 AND (SU.login_status = \"READY\" OR SU.login_status = \"CHANGEPASSWORD\" OR SU.login_status = \"PAYMENT\") AND FIND_IN_SET(SU.id,'{$str_str_users}')";


            $ent_query = $this->SysUsers->getConnection()->execute($str_query)->fetchAll('assoc');
            
            if (!empty($ent_query)) {
                $ems = $ent_query[0]['emails'];
                $this->send_new_email($conf_body,$ems,$conf_subject);
            }
            
        
        }

        if ($notify_push && $av_result) {

            $array_conditions = [
                'ApiDevice.application_id' => APP_ID
            ];

            $array_conditions['ApiDevice.user_id IN'] = $arr_users;
            
            $this->loadModel('SpaLiveV1.ApiDevice');
            $ent_devices = $this->ApiDevice->find()->where($array_conditions)->toArray();

            $arr_devices = array();

            foreach ($ent_devices as $row) {
                $arr_devices[] = $row->id;
            } 

            $this->loadModel('SpaLiveV1.DataNotification');
            if (!defined('USER_ID')) define('USER_ID', 0);
            $arrSave = array(
                'type' => 'NOTIFICATION',
                'message' => $conf_body_push,
                'json_users' => json_encode($arr_users),
                'json_data' => json_encode($data),
                'user_id' => USER_ID,
            );
            $ent_noti = $this->DataNotification->newEntity($arrSave);
            if(!$ent_noti->hasErrors()){
                $this->DataNotification->save($ent_noti);
            }

            $this->send($conf_body_push,$data,$arr_devices);

        }

        if ($shouldSave) {

             foreach ($arr_users as $user_id) {
                
                $array_save = array(
                    'type' => 'NOTIFICATION',
                    'id_from' => 0,
                    'id_to' => $user_id,
                    'message' => $conf_body_push,
                    'extra' => '',
                    'deleted' => 0,
                    'readed' => 0,
                    'created' => date('Y-m-d H:i:s'),
                );

                $this->loadModel('SpaLiveV1.DataMessages');
                $c_entity = $this->DataMessages->newEntity($array_save);

                if(!$c_entity->hasErrors()) 
                    $this->DataMessages->save($c_entity);
                
            }
        }

        if ($notify_sms && $av_result && $is_dev === false) {

            $this->loadModel('SpaLiveV1.SysUsers');
            $array_conditions = [];
            $array_conditions['SysUsers.id IN'] = $arr_users;
            $array_conditions['SysUsers.is_test'] = 0;
            
            $ent_devices = $this->SysUsers->find()->where($array_conditions)->toArray();

            $fixed_numbers = array();

            foreach($fixed_numbers as $num) {
                
                try {           
                    $sid    = env('TWILIO_ACCOUNT_SID'); 
                    $token  = env('TWILIO_AUTH_TOKEN'); 
                    $twilio = new Client($sid, $token); 
                     
                    $message = $twilio->messages 
                              ->create($num, // to 
                                       array(  
                                           "messagingServiceSid" => "MG65978a5932f4ba9dd465e05d7b22195e",      
                                           "body" => $conf_body_push 
                                       ) 
                              ); 
                 } catch (TwilioException $e) {
                 }
            }
            

            foreach($ent_devices as $ele) {

                $phone_number = '+1' . $ele->phone;

                try {           
                    $sid    = env('TWILIO_ACCOUNT_SID'); 
                    $token  = env('TWILIO_AUTH_TOKEN'); 
                    $twilio = new Client($sid, $token); 
                     
                    $message = $twilio->messages 
                              ->create($phone_number, // to 
                                       array(  
                                           "messagingServiceSid" => "MG65978a5932f4ba9dd465e05d7b22195e",      
                                           "body" => $conf_body_push 
                                       ) 
                              ); 
                 } catch (TwilioException $e) {
                 }
                

            }

        }

    }

    public function load_info(){
        $this->loadModel('DataDirectorClinic');
        $this->loadModel('SpaLiveV1.DataTreatmentReview');
        
        $token = get('token', '');
        
        if(!empty($token)){
            $user = $this->AppUniversityToken->validateToken($token, true);
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

        $this->loadModel("SpaLiveV1.SysUsers");

        $_usr_uid = get('uid', '');
        if(!empty($_usr_uid)){
            $_where = ["SysUsers.uid" => $_usr_uid];
        }else{
            $_where = ["SysUsers.id" => USER_ID];
        }


        $first_day = date('Y-m-01');
        $last_day = date('Y-m-t');    
        $most_reviewed = $this->DataTreatmentReview->injectorMostReviewed();
        $find = $this->SysUsers->find()->select(['SysUsers.id', 'SysUsers.uid','SysUsers.name', 'SysUsers.lname', 'SysUsers.mname','SysUsers.bname','SysUsers.city','SysUsers.dob','SysUsers.short_uid','SysUsers.score','SysUsers.photo_id','SysUsers.ein','SysUsers.email','SysUsers.phone','SysUsers.state','SysUsers.street','SysUsers.zip','SysUsers.type','SysUsers.score','State.name','SysUsers.photo_id','SysUsers.score','SysUsers.description',
            'SysUsers.enable_notifications', 'SysUsers.suite', 'is_ci_of_month' => "(SELECT COUNT(InjM.id) FROM data_injector_month InjM WHERE InjM.injector_id = SysUsers.id AND InjM.deleted = 0 AND InjM.date_injector BETWEEN '{$first_day}' AND '{$last_day}')"])
        ->join([
            'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = SysUsers.state']
        ])->where($_where)->first();

        if(empty($find)){
            $this->message('Can´t find user');
            return;
        }else{
            $this->loadModel("SpaLiveV1.CatStates");
            $state = $this->CatStates->find()->select(['CatStates.require_ci_license'])->where(['CatStates.id' => $user['user_state']])->first();
            if ($state) {
                $this->set('require_ci_license', $state->require_ci_license == 1 ? true : false);
            }
            if($find->type == 'clinic'){
                $director = $this->DataDirectorClinic->find()->where(['DataDirectorClinic.clinic_id' => $find->id])->first();
                $dirVals = [
                    'director_uid' => "",
                    'director_name' => "",
                    'director_phone' => "",
                    'director_license' => "",
                ];
                if(!empty($director)){
                    $dirVals['director_uid'] = $director->uid;
                    $dirVals['director_name'] = $director->director_name;
                    $dirVals['director_phone'] = $director->director_number;
                    $dirVals['director_license'] = $director->director_license;
                }

                $this->set('director', $dirVals);
            }


            $find['enable_notifications'] = (isset($find['enable_notifications']) && ($find['enable_notifications'] === true || $find['enable_notifications'] == 1)) ? 1 : 0;
            $ver = get('version', '');
            $key = get('key', '');
            $ver = str_replace('version ', '', $ver);
            
            unset($find['id']);
            unset($find['active']);
            unset($find['user_id']);
            unset($find['deleted']);
            unset($find['created']);
            unset($find['password']);
            unset($find['modified']);
            unset($find['modifiedby']);
            $state = $find['state'];
            $find['state_txt'] = $find['State']['name'];
            $find['state'] = $state;
            unset($find['State']);
            $find['most_reviewed'] = in_array($user['user_id'], $most_reviewed) ? 1 : 0;
            $find['is_ci_of_month'] = ($find['is_ci_of_month'] > 0 ? 1 : 0);
            if (!isset($find['dob'])) $find['dob'] = '';
            $this->set('data', $find);
            $this->set('type', $find['type']);
            $this->success();
        }


        

        $this->loadModel('SpaLiveV1.DataTreatmentReview');
        $ent_comments = $this->DataTreatmentReview->find()->select(['DataTreatmentReview.score','DataTreatmentReview.comments','DataTreatmentReview.created','User.name'])
        ->join([
            'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataTreatmentReview.createdby']])
        ->where(['DataTreatmentReview.injector_id' => USER_ID, 'DataTreatmentReview.deleted' => 0])->all();
        
        $data_comments = array();
        if (!empty($ent_comments)) {
            foreach ($ent_comments as $row) {
                
                $data_comments[] = array(
                    'score' => intval($row['score']),
                    'comments' => $row['comments'],
                    'created' => $row['created']->format("Y-M-d"),
                    'name' => $row->User['name'],
                );
            }
        }


        $user_id = USER_ID;
        $filter_icons = $this->SysUsers->getConnection()->execute("SELECT CatIcon.uid, CatIcon.name, CatIcon.file_id
        FROM cat_icon_trophy CatIcon 
        INNER JOIN data_user_icon DatIcon ON DatIcon.icon_id = CatIcon.id AND DatIcon.user_id = {$user_id}
        WHERE CatIcon.deleted = 0 AND CatIcon.type_icon = 'FILTER'")->fetchAll('assoc');

        $this->set('filter_icons', $filter_icons);

    
        $this->loadModel('SpaLiveV1.SysLicence');
        $licenceItem = $this->SysLicence->find()->select(['SysLicence.id','SysLicence.number'])->where(['SysLicence.user_id' => USER_ID, 'SysLicence.deleted' => 0])->all();
        $this->set('licenses', $licenceItem);
        


    }

    public function get_file() {
        $this->loadModel('SpaLiveV1.UniversityFiles');
        $_file_id = get('id','');
        if ($_file_id == 0) return;

        $ent_reg = $this->UniversityFiles->find()
        ->select([
            'UniversityFiles.uid','UniversityFiles.name','UniversityFiles.size','UniversityFiles.path','UniversityFiles.created','UniversityFiles.modified',
            'MimeType.type','MimeType.mimetype',
        ])
        ->join([
            'MimeType' => [
                'table' => '_mimetypes',
                'type'  => 'INNER',
                'conditions' => 'MimeType.id = UniversityFiles._mimetype_id'
            ]
        ])
        ->where(['UniversityFiles.id' => $_file_id,])->first();

        // pr($ent_reg); exit;
             
        if (file_exists($ent_reg->path)) {
            $size = filesize($ent_reg->path);
            header("Content-Type: " . $ent_reg['MimeType']['mimetype']);
            header("Content-Disposition: inline; filename=" . $ent_reg->name);
            header("Content-Length: {$size}");
            header('content-Transfer-Encoding:binary');
            header('Accept-Ranges:bytes');
            @ readfile($ent_reg->path);
            return;
        } else {
            $fpath = APP . DS . 'broken.png';
            $size = filesize($fpath);
            header("Content-Type: image/png");
            header("Content-Disposition: inline; filename=broken.png");
            header("Content-Length: {$size}");
            header('content-Transfer-Encoding:binary');
            header('Accept-Ranges:bytes');
            @ readfile($fpath);
            return;
            
        }

    }

    public function list_articles(){
        
        $token = get('token', '');

        if(!empty($token)){
            $user = $this->AppUniversityToken->validateToken($token, true);
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

        $this->loadModel('SpaLiveV1.UniversityArticles');

        $page = intval(get('page', 1));
        $limit = get('limit', 10);

        $_where = ['UniversityArticles.deleted' => 0];

        $_fields = ['UniversityArticles.uid','UniversityArticles.title','UniversityArticles.created'];
        
        $ent_files = $this->UniversityArticles->find()->select($_fields)->where($_where)->order(['UniversityArticles.id' => 'DESC'])->limit($limit)->page($page)->all();
        
        if(!empty($ent_files)){
            $result = array();
            foreach($ent_files as $row) {
                $result[] = array(
                    'uid' => $row->uid,
                    'title' => $row->title,
                    'created' => $row->created->i18nFormat('yyyy-MM-dd'),
                );
            }

            $this->success();
            $this->set('data', $result);
        } 
    
    }

    public function get_article(){
        
        $token = get('token', '');

        if(!empty($token)){
            $user = $this->AppUniversityToken->validateToken($token, true);
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

        $this->loadModel('SpaLiveV1.UniversityArticles');


        
        $uid = get('uid', '');

        if (empty($uid)) {
            $this->message('Empty uid.');
            return;
        }

        $_where = ['UniversityArticles.deleted' => 0, 'UniversityArticles.uid' => $uid];

        $_fields = ['UniversityArticles.uid','UniversityArticles.title','UniversityArticles.created','UniversityArticles.content'];
        
        $ent_file = $this->UniversityArticles->find()->select($_fields)->where($_where)->order(['UniversityArticles.id' => 'DESC'])->first();
        
        if(!empty($ent_file)){
            $this->success();
            $this->set('content', $ent_file->content);
            $this->set('title', $ent_file->title);
            $this->set('title', $ent_file->created->i18nFormat('yyyy-MM-dd'));
        } 
    
    }

    public function list_media(){
        
        $token = get('token', '');

        if(!empty($token)){
            $user = $this->AppUniversityToken->validateToken($token, true);
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

        $this->loadModel('SpaLiveV1.UniversityFiles');
        $this->loadModel('SpaLiveV1.UniversityTags');


        $page = intval(get('page', 1));
        $limit = get('limit', 10);

        $_fields = ['UniversityFiles.id','UniversityFiles.title','UniversityFiles.description','UniversityFiles.created'];
        $_where = ['UniversityFiles.deleted' => 0];
        $_join = [];

        $tag = get('tag',0);
        if ($tag > 0) {
            $_where['Tag.tag_id'] = $tag;
            $_join = [
                'Tag' => ['table' => 'university_files_tags', 'type' => 'LEFT', 'conditions' => 'Tag.file_id = UniversityFiles.id'],
            ];
        }


        $ent_files = $this->UniversityFiles->find()->select($_fields)->join($_join)->where($_where)->order(['UniversityFiles.id' => 'DESC'])->limit($limit)->page($page)->all();
        $ent_tags = $this->UniversityTags->find()->all();

        if(!empty($ent_files)){
            $result = array();
            foreach($ent_files as $row) {
                $result[] = array(
                    'title' => $row->title,
                    'description' => $row->description,
                    'url' => 'https://api-dev.spalivemd.com/?action=University____get_file&key=d4Fd123SDFfgw23r5dfghsd34w34dFx&id=' . $row->id,
                );
            }

            $this->success();
            $this->set('data', $result);
            $this->set('tags', $ent_tags);
        } 
    
    }


    

    private function send_new_email($html_content,$email,$subject = "New alert from SpaLiveMD") {

       $data = array(
            'from'    => 'SpaLiveMD <noreply@mg.spalivemd.com>',
            'to'    => 'SpaLiveMD <noreply@mg.spalivemd.com>',
            'bcc'      => $email,
            'subject' => $subject,
            'html'    => $html_content,
        );

        $mailgunKey = $this->getMailgunKey();


        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, 'https://api.mailgun.net/v3/mg.spalivemd.com/messages');
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


}