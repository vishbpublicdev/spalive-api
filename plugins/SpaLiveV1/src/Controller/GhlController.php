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


class GhlController extends AppPluginController {
    
    private function get_token(){   
        // if(env('IS_DEV', false))
        // {
        //     return false;
        // }
        // print_r('buscando token --'); 
        $this->loadModel('SpaLiveV1.SysGhlTokens');

        $ent_sys_token = $this->SysGhlTokens->find()->last();
        
        if(date('Y-m-d H:i:s', strtotime($ent_sys_token->created->i18nFormat('yyyy-MM-dd HH:mm:ss') . '+ 23 hours')) > date('Y-m-d H:i:s')){
            return $ent_sys_token->token;
        }
        // Configura las credenciales de la aplicación
        $client_id = '65b27707e1393d210b1cc1c7-lrtca4jy';
	    $client_secret = 'da2bc9ec-1f6c-4e3b-9368-a9e38cf3bc2a';
	    $redirect_uri = 'https://services.leadconnectorhq.com';
        $refresh_token = $ent_sys_token->refresh_token;

        // Inicializa CURL
        $ch = curl_init();

        // Configura la URL de la API para obtener un token de acceso
        $url = "https://services.leadconnectorhq.com/oauth/token";

        // Configura los encabezados de la solicitud
        $headers = array(
            // "Content-Type: application/x-www-form-urlencoded"
            "Accept: application/json",
            "Authorization: Bearer undefined",
            "Content-Type: application/x-www-form-urlencoded"
        );

        // Configura los datos de la solicitud
        $data = array(
            "client_id" => $client_id,
            "client_secret" => $client_secret,
            "grant_type" => "refresh_token",
            "refresh_token" => $refresh_token,
            "user_type" => "Location",
            "redirect_uri" => $redirect_uri
        );
        //print_r('por'); 
        // Codifica los datos de la solicitud en formato JSON
        // $data_json = json_encode($data);

        // Configura las opciones de CURL
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Ejecuta la solicitud CURL y obtiene la respuesta
        $response = curl_exec($ch);
        $err = curl_error($ch);

        $this->set('token_response', $response);
        $this->set('token_error', $err);

        // Cierra la sesión CURL
        curl_close($ch);
        // print_r($response); exit;
        if($err){
            return false;
        }
        // Decodifica la respuesta JSON de la API en un objeto PHP
        $token_data = json_decode($response);
        // //print_r($token_data);
        if(isset($token_data->error) && !empty($token_data->error)){
            return false;
        }
        //print_r('aqui'); 
        // guardar en base de datos
        $this->loadModel('SpaLiveV1.SysGhlTokens');
        $token = $token_data->access_token;
        $refreshToken = $token_data->refresh_token;
        $expires_in = $token_data->expires_in;
        
        //$encodedData = json_encode($ent_sys_token[0]['refresh_token']);
        //echo($encodedData);

        $array_save = array(
            'token' => $token,
            'refresh_token' => $refreshToken,
            'expires_in' => $expires_in,
            'created' => date('Y-m-d H:i:s'),
            'user' => 0,
        );
        
        $c_entity = $this->SysGhlTokens->newEntity($array_save);
        
        if(!$c_entity->hasErrors()) {
            $ent_saved = $this->SysGhlTokens->save($c_entity);
           return $token;
        }
    }

    private function findUser($AccessToken, $str = '') {
       
        $queryString = 'locationId=ucvQVvzz80CGEqowRjeb&query=' . $str;
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://services.leadconnectorhq.com/contacts/?' . $queryString,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                'Version: 2021-07-28',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $AccessToken
            ),
        ));
        
        $response = curl_exec($curl);
        
        $err = curl_error($curl);

        curl_close($curl);
        if (!$err) {
            $arr_data = json_decode($response, true);
            if (isset($arr_data['contacts']) && count($arr_data['contacts']) > 0) {
                $this->set('id', $arr_data['contacts'][0]['id']);
                return $arr_data['contacts'][0]['id'];
            }
            return false;
        } else {
            return false;
        }
    }

    private function getContact($AccessToken, $str = '') {
       
        $queryString = 'locationId=ucvQVvzz80CGEqowRjeb&query=' . $str;
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://services.leadconnectorhq.com/contacts/?' . $queryString,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                'Version: 2021-07-28',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $AccessToken
            ),
        ));
        
        $response = curl_exec($curl);
        
        $err = curl_error($curl);

        curl_close($curl);
        if (!$err) {
            $arr_data = json_decode($response, true);
            if (isset($arr_data['contacts']) && count($arr_data['contacts']) > 0) {
                $this->set('id', $arr_data['contacts'][0]['id']);
                return $arr_data['contacts'][0]['id'];
            }
            return false;
        } else {
            return false;
        }
    }

    public function createUser($arr_data = array()) {
       
        $AccessToken = $this->get_token();

        if(!$AccessToken) {
            return;
        }

        $str_email = $arr_data['email'];
        $str_name = $arr_data['name'];
        $str_lname = $arr_data['lname'];
        $str_phone = $arr_data['phone'];


        $userId = $this->findUser($AccessToken, $str_email);
        if(!empty($userId)) {
            $userId = $this->findUser($AccessToken, $str_phone);
        }

        if (!empty($userId)) {
            return;
        }

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://services.leadconnectorhq.com/contacts/',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS =>'{
            "name": "'.$str_name. ' ' . $str_lname.'",
                "firstName":"'.$str_name.'",
                "lastName": "'.$str_lname.'",
                "email": "'.$str_email.'",
                "phone": "'.$str_phone.'",
                "locationId": "ucvQVvzz80CGEqowRjeb"
            }',
            CURLOPT_HTTPHEADER => array(
                'Version: 2021-07-28',
                'Content-Type: application/json',
                'Authorization: Bearer '.$AccessToken
            ),
        ));
        
        $response = curl_exec($curl);

        $err = curl_error($curl);

        $this->set('createuser_response', $response);
        $this->set('createuser_error', $err);

        curl_close($curl);
        
        if (!$err) {
            $arr_data = json_decode($response, true);
            if (isset($arr_data['contact']) && count($arr_data['contact']) > 0) {
                $this->set('id', $arr_data['contact']['id']);
                return $arr_data['contact']['id'];
            }
            return false;
        } else {
            return false;
        }
       
    }
    
    public function createUserWithToken($AccessToken, $arr_data = array()) {

        $str_email = $arr_data['email'];
        $str_name = $arr_data['name'];
        $str_lname = $arr_data['lname'];
        $str_phone = $arr_data['phone'];

        $userId = $this->findUser($AccessToken, $str_email);
        if(!empty($userId)) {
            $userId = $this->findUser($AccessToken, $str_phone);
        }

        if (!empty($userId)) {
            return;
        }

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://services.leadconnectorhq.com/contacts/',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS =>'{
                "name": "'.$str_name.' '. $str_lname.'",
                "firstName":"'.$str_name.'",
                "lastName": "'.$str_lname.'",
                "email": "'.$str_email.'",
                "phone": "'.$str_phone.'",
                "locationId": "ucvQVvzz80CGEqowRjeb"
            }',
            CURLOPT_HTTPHEADER => array(
                'Version: 2021-07-28',
                'Content-Type: application/json',
                'Authorization: Bearer '.$AccessToken
            ),
        ));
        
        $response = curl_exec($curl);

        $err = curl_error($curl);

        $this->set('createuser_response', $response);
        $this->set('createuser_error', $err);
        curl_close($curl);
        
        if (!$err) {
            $arr_data = json_decode($response, true);
            if (isset($arr_data['contact']) && count($arr_data['contact']) > 0) {
                $this->set('id', $arr_data['contact']);
                return $arr_data['contact']['id'];
            }
            return false;
        } else {
            return false;
        }
       
    }
    
    public function findOpportunity($AccessToken, $contact_id = '') {
       
        $queryString = 'location_id=ucvQVvzz80CGEqowRjeb&contact_id='.$contact_id;

        $curl = curl_init();
        
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://services.leadconnectorhq.com/opportunities/search?'.$queryString ,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
              'Version: 2021-07-28',
              'Authorization: Bearer '.$AccessToken
            ),
          ));
        
        $response = curl_exec($curl);
        $err = curl_error($curl);
        $this->set('findoportunity_response', $response);
        $this->set('findoportunity_error', $err);
        curl_close($curl);
        if (!$err) {
            $arr_data = json_decode($response, true);
           /*  pr( $arr_data);
            pr($arr_data['opportunities']);exit; */
            if (isset($arr_data['opportunities'][0]) && count($arr_data['opportunities'][0]) > 0) {
                //echo $arr_data->opportunities[0]->id;
                return $arr_data['opportunities'][0];
            }
            
            return false;
        } else {
            return false;
        }
       
    }

    public function updateOpportunityColumn($costo ,$arr_data = array(), $Column_name) {
        //print_r('llegando --');
        // //print_r($costo);
        // //print_r($arr_data);
        // //print_r($Column_name);
        // exit;
        //Obtener codigo autorizacio
        // print_r('llegue'); exit;
        $AccessToken = $this->get_token();        
        if(!$AccessToken) {
            return;
        }
        // print_r('con acceso --'); 
        //arr_data necesita id y el name del anterior opportunity
        $str_name = $arr_data['name'];
        $str_id_opportunity = $arr_data['id'];

        $pipelineId = "ylgFPp82BVXZMA3X74VA";
        //pasarle array id y datos del opportunity, ademas del nombre de la columna del pago

        //definir y cambiar el id de la columna en base a la v $column
        // print_r($Column_name); exit;
        $column_id = "";
        if($Column_name == "Basic"){
            $column_id = "79ab03d6-e3a5-4042-8e48-39265242cb9d";
        }
        if($Column_name == "Advanced"){
            $column_id = "ce3b2ec6-f655-4ce0-9738-e91e5e67e77c";
        }
        if($Column_name == "Downloaded"){
            $column_id = "b3c630d8-44bd-4dba-b789-12add506941a";
        }
        if($Column_name == "Level 1 Grads"){
            $column_id = "b243cc1c-365c-48d2-bf98-5e77bb9a2d9b";
        }
        if($Column_name == "Partial Registration"){
            $column_id = "f913f450-9bff-4ab8-8fb5-2798b59e66ec";
        }
        if($Column_name == "Inactive"){
            $column_id = "642ae0df-865c-4c63-ae08-00453be4ece2";
        }
        if($Column_name == "Injectors Without Subscriptions"){
            $column_id = "67f99f5f-131d-4393-9a0a-fa595d3a6e16";
        }
        if($Column_name == "Injectors With Subscriptions"){
            $column_id = "83c74a34-c4dd-4eb8-b197-0a54d4dc316c";
        }
        
        if($column_id == ""){
            echo "No column name";
            return;
        } else {
            $curl = curl_init();
            curl_setopt_array($curl, array(
              CURLOPT_URL => 'https://services.leadconnectorhq.com/opportunities/'.$str_id_opportunity,
              CURLOPT_RETURNTRANSFER => true,
              CURLOPT_ENCODING => '',
              CURLOPT_MAXREDIRS => 10,
              CURLOPT_TIMEOUT => 0,
              CURLOPT_FOLLOWLOCATION => true,
              CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
              CURLOPT_CUSTOMREQUEST => 'PUT',
              CURLOPT_POSTFIELDS =>'{
              "pipelineId": "'.$pipelineId.'",
              "name": "'.$str_name.'",
              "pipelineStageId": "'.$column_id.'",
              "status": "open",
              "monetaryValue": '.$costo.'
            
            }',
              CURLOPT_HTTPHEADER => array(
                'Version: 2021-07-28',
                'Content-Type: application/json',
                'Authorization: Bearer '.$AccessToken
              ),
            ));

            $response = curl_exec($curl);
            //print_r('respuesta --'); 
            //print_r($response); exit;
            $err = curl_error($curl);
            curl_close($curl);
            if (!$err) {
                $arr_data = json_decode($response, true);
                if (isset($arr_data['opportunity']) && count($arr_data['opportunity']) > 0) {
                    $this->set('id', $arr_data['opportunity']);
                    //echo $arr_data['opportunities'][0]['id'];
                    return $arr_data['opportunity'];
                }
                
                return false;
            } else {
                return false;
            }
        }

    }

    public function updateOpportunityColumnTags($costo ,$arr_data = array(), $Column_name) {

        $AccessToken = $this->get_token();        
        if(!$AccessToken) {
            return;
        }

        $str_name = $arr_data['name'];
        $str_id_opportunity = $arr_data['id'];

        $pipelineId = "E3LQPNEsuRYBAHNLJpCW";

        $column_id = "";

        switch ($Column_name) {
            case 'Inactive':
                $column_id = "975e6639-3479-4f86-8dd2-f8e656fc926c";
                break;
            case 'Registered':
                $column_id = "65f831ea-042d-41b0-8ed7-2143ffe083e9";
                break;
            case 'Purchased basic':
                $column_id = "ca5d5fb2-e068-4b92-8576-ec03cf1f4774";
                break;
            case 'Subscribed to basic neurotoxins':
                $column_id = "9f509a8f-ebc0-4a2f-bcc5-c8542fe9505a";
                break;
            case 'Purchased advanced (no subscription)':
                $column_id = "15db41a6-5180-4f57-85e8-6c1a01ddbb9f";
                break;
            case 'Subscribed with advanced neurotoxins':
                $column_id = "26378d0f-d831-4416-8491-026712931dde";
                break;
            case 'Subscribed from another school':
                $column_id = "c8fd3318-5a8a-474d-919a-7f88a978e21d";
                break;
            case 'Level 3':
                $column_id = "95e16f7e-3ef3-4233-989d-fb30f04a7205";
                break;
            case 'Completely unsubscribed':
                $column_id = "664a2bc6-28c0-4a97-8228-4e33edc38510";
                break;
            default:
                $column_id = "";
                break;
        }
        
        if($column_id == ""){
            return;
        } else {
            $curl = curl_init();
            curl_setopt_array($curl, array(
              CURLOPT_URL => 'https://services.leadconnectorhq.com/opportunities/'.$str_id_opportunity,
              CURLOPT_RETURNTRANSFER => true,
              CURLOPT_ENCODING => '',
              CURLOPT_MAXREDIRS => 10,
              CURLOPT_TIMEOUT => 0,
              CURLOPT_FOLLOWLOCATION => true,
              CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
              CURLOPT_CUSTOMREQUEST => 'PUT',
              CURLOPT_POSTFIELDS =>'{
              "pipelineId": "'.$pipelineId.'",
              "name": "'.$str_name.'",
              "pipelineStageId": "'.$column_id.'",
              "status": "open",
              "monetaryValue": '.$costo.'
            
            }',
              CURLOPT_HTTPHEADER => array(
                'Version: 2021-07-28',
                'Content-Type: application/json',
                'Authorization: Bearer '.$AccessToken
              ),
            ));

            $response = curl_exec($curl);

            $err = curl_error($curl);

            $this->set('updateoportunity_response', $response);
            $this->set('updateoportunity_error', $err);
            curl_close($curl);
            if (!$err) {
                $arr_data = json_decode($response, true);
                if (isset($arr_data['opportunity']) && count($arr_data['opportunity']) > 0) {
                    $this->set('id', $arr_data['opportunity']);
                    //echo $arr_data['opportunities'][0]['id'];
                    return $arr_data['opportunity'];
                }
                
                return false;
            } else {
                return false;
            }
        }

    }

    public function addTag($contactId, $str_email, $str_phone, $Column_name){
        //Obtener codigo autorizacio
        
        $AccessToken = $this->get_token();

        if(!$AccessToken) {
            return;
        }
        $userId = '';

        if(empty($contactId)){

            $userId = $this->findUser($AccessToken, $str_email);

            if(empty($userId)) {
                $userId = $this->findUser($AccessToken, $str_phone);
            }

            if (empty($userId)) {
                return false; 
            }
        } else {
            $userId = $contactId;
        }

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://services.leadconnectorhq.com/contacts/'.$userId.'/tags',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS =>json_encode(array("tags" => array($Column_name))),
            CURLOPT_HTTPHEADER => array(
                'Version: 2021-07-28',
                'Content-Type: application/json',
                'Authorization: Bearer '.$AccessToken
            ),
        ));

        $response = curl_exec($curl);
        
        $err = curl_error($curl);

        curl_close($curl);

        return array('response' => $response, 'error' => $err);
    }

    public function removeTag($contactId, $str_email, $str_phone, $tag_name){
        $AccessToken = $this->get_token();

        if(!$AccessToken) {
            return;
        }

        $userId = '';

        if(empty($contactId)){

            $userId = $this->findUser($AccessToken, $str_email);

            if(empty($userId)) {
                $userId = $this->findUser($AccessToken, $str_phone);
            }

            if (empty($userId)) {
                return false; 
            }
        } else {
            $userId = $contactId;
        }

        $curl = curl_init();
        curl_setopt_array($curl, array(
          CURLOPT_URL => 'https://services.leadconnectorhq.com/contacts/'.$userId.'/tags',
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => '',
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => 'DELETE',
          CURLOPT_POSTFIELDS =>json_encode(array("tags" => array($tag_name))),
          CURLOPT_HTTPHEADER => array(
            'Version: 2021-07-28',
            'Content-Type: application/json',
            'Authorization: Bearer '.$AccessToken
          ),
        ));

        $response = curl_exec($curl);

        
        
        $err = curl_error($curl);

        curl_close($curl);

        return array('response' => $response, 'error' => $err);
    }

    public function addDownloadedAppTags($contactId, $userData = array()) {
        if(env('IS_DEV', false)) {
            return array('dev_mode' => true, 'message' => 'Tags not sent in DEV mode');
        }

        $str_email = isset($userData['email']) ? $userData['email'] : '';
        $str_phone = isset($userData['phone']) ? $userData['phone'] : '';
        $user_type = isset($userData['type']) ? $userData['type'] : '';

        $downloadedAppTag = $this->addTag($contactId, $str_email, $str_phone, 'downloaded app');
 
        $userTypeTag = '';
        if ($user_type == 'injector' || $user_type == 'gfe+ci') {
            $userTypeTag = 'Injector';
        } else if ($user_type == 'patient') {
            $userTypeTag = 'Model';
        }

        $typeTagResponse = array();
        if (!empty($userTypeTag)) {
            $typeTagResponse = $this->addTag($contactId, $str_email, $str_phone, $userTypeTag);
        }

        return array(
            'downloaded_app_tag' => $downloadedAppTag,
            'user_type_tag' => $typeTagResponse,
            'user_type' => $user_type,
            'tag_applied' => $userTypeTag
        );
    }

    public function createOpportunityInColumn($userId, $costo ,$arr_data = array(), $Column_name) {
        //Obtener codigo autorizacio
        $AccessToken = $this->get_token();

        if(!$AccessToken) {
            return;
        }
        //print_r('create --'); 
        //arr_data el name del opportunity
        $str_name = $arr_data['name'];

        $pipelineId = "ylgFPp82BVXZMA3X74VA";

        //definir y cambiar el id de la columna en base a la v $column

        $column_id = "";
        if($Column_name == "Basic"){
            $column_id = "79ab03d6-e3a5-4042-8e48-39265242cb9d";
        }
        if($Column_name == "Advanced"){
            $column_id = "ce3b2ec6-f655-4ce0-9738-e91e5e67e77c";
        }
        if($Column_name == "Downloaded"){
            $column_id = "b3c630d8-44bd-4dba-b789-12add506941a";
        }
        if($Column_name == "Level 1 Grads"){
            $column_id = "b243cc1c-365c-48d2-bf98-5e77bb9a2d9b";
        }
        if($Column_name == "Partial Registration"){
            $column_id = "f913f450-9bff-4ab8-8fb5-2798b59e66ec";
        }
        if($Column_name == "Inactive"){
            $column_id = "642ae0df-865c-4c63-ae08-00453be4ece2";
        }
        if($Column_name == "Injectors Without Subscriptions"){
            $column_id = "67f99f5f-131d-4393-9a0a-fa595d3a6e16";
        }
        if($Column_name == "Injectors With Subscriptions"){
            $column_id = "83c74a34-c4dd-4eb8-b197-0a54d4dc316c";
        }
        
        if($column_id == ""){
            return;
        } else {
            $curl = curl_init();
            curl_setopt_array($curl, array(
              CURLOPT_URL => 'https://services.leadconnectorhq.com/opportunities/',
              CURLOPT_RETURNTRANSFER => true,
              CURLOPT_ENCODING => '',
              CURLOPT_MAXREDIRS => 10,
              CURLOPT_TIMEOUT => 0,
              CURLOPT_FOLLOWLOCATION => true,
              CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
              CURLOPT_CUSTOMREQUEST => 'POST',
              CURLOPT_POSTFIELDS =>'{
                "pipelineId": "'.$pipelineId.'",
                "locationId": "ucvQVvzz80CGEqowRjeb",
                "name": "'.$str_name.'",
                "pipelineStageId": "'.$column_id.'",
                "status": "open",
                "contactId": "'.$userId.'",
                "monetaryValue": '.$costo.'
            
            }',
              CURLOPT_HTTPHEADER => array(
                'Version: 2021-07-28',
                'Content-Type: application/json',
                'Authorization: Bearer '.$AccessToken
              ),
            ));

            $response = curl_exec($curl);
            
            $err = curl_error($curl);
            curl_close($curl);
            if (!$err) {
                $arr_data = json_decode($response, true);
                if (isset($arr_data['opportunity']) && count($arr_data['opportunity']) > 0) {
                    //$this->set('id', $arr_data['opportunity']);
                    //echo $arr_data['opportunities'][0]['id'];
                    return $arr_data['opportunity'];
                }
                
                return false;
            } else {
                return false;
            }
        }

    }

    public function createOpportunityByColumn($userId, $costo ,$arr_data = array(), $Column_name) {
        //Obtener codigo autorizacio
        $AccessToken = $this->get_token();

        if(!$AccessToken) {
            return;
        }
        //print_r('create --'); 
        //arr_data el name del opportunity
        $str_name = $arr_data['name'];

        $pipelineId = "E3LQPNEsuRYBAHNLJpCW";

        //definir y cambiar el id de la columna en base a la v $column

        $column_id = "";

        switch ($Column_name) {
            case 'Inactive':
                $column_id = "975e6639-3479-4f86-8dd2-f8e656fc926c";
                break;
            case 'Registered':
                $column_id = "65f831ea-042d-41b0-8ed7-2143ffe083e9";
                break;
            case 'Purchased basic':
                $column_id = "ca5d5fb2-e068-4b92-8576-ec03cf1f4774";
                break;
            case 'Subscribed to basic neurotoxins':
                $column_id = "9f509a8f-ebc0-4a2f-bcc5-c8542fe9505a";
                break;
            case 'Purchased advanced (no subscription)':
                $column_id = "15db41a6-5180-4f57-85e8-6c1a01ddbb9f";
                break;
            case 'Subscribed with advanced neurotoxins':
                $column_id = "26378d0f-d831-4416-8491-026712931dde";
                break;
            case 'Subscribed from another school':
                $column_id = "c8fd3318-5a8a-474d-919a-7f88a978e21d";
                break;
            case 'Level 3':
                $column_id = "95e16f7e-3ef3-4233-989d-fb30f04a7205";
                break;
            case 'Completely unsubscribed':
                $column_id = "664a2bc6-28c0-4a97-8228-4e33edc38510";
                break;
            default:
                $column_id = "";
                break;
        }
        
        if($column_id == ""){
            return;
        } else {
            $curl = curl_init();
            curl_setopt_array($curl, array(
              CURLOPT_URL => 'https://services.leadconnectorhq.com/opportunities/',
              CURLOPT_RETURNTRANSFER => true,
              CURLOPT_ENCODING => '',
              CURLOPT_MAXREDIRS => 10,
              CURLOPT_TIMEOUT => 0,
              CURLOPT_FOLLOWLOCATION => true,
              CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
              CURLOPT_CUSTOMREQUEST => 'POST',
              CURLOPT_POSTFIELDS =>'{
                "pipelineId": "'.$pipelineId.'",
                "locationId": "ucvQVvzz80CGEqowRjeb",
                "name": "'.$str_name.'",
                "pipelineStageId": "'.$column_id.'",
                "status": "open",
                "contactId": "'.$userId.'",
                "monetaryValue": '.$costo.'
            
            }',
              CURLOPT_HTTPHEADER => array(
                'Version: 2021-07-28',
                'Content-Type: application/json',
                'Authorization: Bearer '.$AccessToken
              ),
            ));

            $response = curl_exec($curl);
            
            $err = curl_error($curl);

            curl_close($curl);
            if (!$err) {
                $arr_data = json_decode($response, true);
                if (isset($arr_data['opportunity']) && count($arr_data['opportunity']) > 0) {
                    //$this->set('id', $arr_data['opportunity']);
                    //echo $arr_data['opportunities'][0]['id'];
                    return $arr_data['opportunity'];
                }
                
                return false;
            } else {
                return false;
            }
        }

    }

    public function updateOpportunity($arr_data = array()) {
        //Prueba no tiene contacto existente
       /*  $arr_data = array(
            "email" => "testnocontact42@gmail.com",
            "name" => "Test",
            "lname" => "NoContact42",
            "phone" => "6181242542",
            "costo" => 597,
            "course" => "Basic"
          ); */
        ////Prueba tiene contacto existente y opportunity
        //$arr_data = array(
        //    "email" => "test@devmyspa.com",
        //    "name" => "Test",
        //    "lname" => "Dev",
        //    "phone" => "+11234567890",
        //    "costo" => 597,
        //    "course" => "Basic"
        //  );
        //  
        //Prueba tiene contacto existente y no tiene opportunity
        //$arr_data = array(
        //    "email" => "testeo@gmail.com",
        //    "name" => "Test",
        //    "lname" => "eo",
        //    "phone" => "+15551234568",
        //    "costo" => 597,
        //    "course" => "Basic"
        //  );
        // print_r('buscando..'); 
        $AccessToken = $this->get_token();
        // print_r('accediendo..'); 
        if(!$AccessToken) {  
            return false;
        }
        // print_r('entrando..'); exit;
        $str_email = $arr_data['email'];
        $str_name = $arr_data['name'];
        $str_lname = $arr_data['lname'];
        $str_phone = $arr_data['phone'];
        $str_costo = $arr_data['costo'];
        $str_course = $arr_data['course'];
        
        //buscar usuario
        $userId = $this->findUser($AccessToken, $str_email);      
        if(empty($userId)) {  
            $userId = $this->findUser($AccessToken, $str_phone);
        }
        
        //si no existe contacto 
        if (empty($userId)) {
            //crear Contacto
            $data = array(
                "email" => $str_email,
                "name" => $str_name,
                "lname" => $str_lname,
                "phone" => $str_phone
              );
            // print_r('1--');  exit;
            //crear contacto 
            $Contact_id = $this->createUserWithToken($AccessToken, $data);  //print_r('2--'); //print_r($Contact_id); 
            //buscar oportunity
            if (!empty($Contact_id)) {
                //sleep(5);
                $opportunity = $this->findOpportunity($AccessToken, $Contact_id);
               /*  pr($opportunity);
                exit; */
                if (!empty($opportunity)) {
                    //actualizar oportunity
                    // echo "upd";
                    //exit;
                    $this->updateOpportunityColumn($str_costo, $opportunity, $str_course);

                }else{
                    $dataOpp = array(
                        "name" => $str_name." ".$str_lname,
                      );
                    $this->createOpportunityInColumn($userId, $str_costo, $dataOpp, $str_course);
                }
                return $Contact_id;
            }

            return false;
        } else {    //print_r('kkkkkkk --'); 
            //si tiene usuario buscar opportunity
            $opportunity = $this->findOpportunity($AccessToken, $userId);
            
            //print_r('encontrado --');
            if (empty($opportunity)) {
                //si no hay opportunitty crear una ya en x rama
                $dataOpp = array(
                    "name" => $str_name." ".$str_lname,
                  );
                //print_r('data --'); 
                //print_r($dataOpp); 
                $this->createOpportunityInColumn($userId, $str_costo, $dataOpp, $str_course);
            }else{   //print_r('accediendo --'); 
                //si lo encuentra actualizar opportunity
                $this->updateOpportunityColumn($str_costo, $opportunity, $str_course);
            }
            return $userId;
        }
       
    }

    public function updateOpportunityTags($arr_data = array()) {
        //Prueba no tiene contacto existente

        $AccessToken = $this->get_token();
        // print_r('accediendo..'); 
        if(!$AccessToken) {  
            return;
        }
        // print_r('entrando..'); exit;
        $str_email = $arr_data['email'];
        $str_name = $arr_data['name'];
        $str_lname = $arr_data['lname'];
        $str_phone = $arr_data['phone'];
        $str_costo = $arr_data['costo'];
        $str_column = $arr_data['column'];
        
        //buscar usuario
        $userId = $this->findUser($AccessToken, $str_email);      
        if(empty($userId)) {  
            $userId = $this->findUser($AccessToken, $str_phone);
        }
        //si no existe contacto 
        if (empty($userId)) {
            //crear Contacto
            $data = array(
                "email" => $str_email,
                "name" => $str_name,
                "lname" => $str_lname,
                "phone" => $str_phone
              );
            // print_r('1--');  exit;
            //crear contacto 
            $Contact_id = $this->createUserWithToken($AccessToken, $data);  //print_r('2--'); //print_r($Contact_id); 
            //buscar oportunity
            // pr($Contact_id);exit;
            if (!empty($Contact_id)) {
                //sleep(5);
                $opportunity = $this->findOpportunity($AccessToken, $Contact_id);
               /*  pr($opportunity);
                exit; */
                if (!empty($opportunity)) {
                    //actualizar oportunity
                    // echo "upd";
                    //exit;
                    $this->updateOpportunityColumnTags($str_costo, $opportunity, $str_column);

                }else{
                    $dataOpp = array(
                        "name" => $str_name." ".$str_lname,
                      );
                    $this->createOpportunityByColumn($Contact_id, $str_costo, $dataOpp, $str_column);
                }
            }

            return $Contact_id;
        } else {    //print_r('kkkkkkk --'); 
            //si tiene usuario buscar opportunity
            $opportunity = $this->findOpportunity($AccessToken, $userId);
            
            //print_r('encontrado --');
            if (empty($opportunity)) {
                //si no hay opportunitty crear una ya en x rama
                $dataOpp = array(
                    "name" => $str_name." ".$str_lname,
                  );
                //print_r('data --'); 
                //print_r($dataOpp); 
                $this->createOpportunityByColumn($userId, $str_costo, $dataOpp, $str_column);

            }else{   //print_r('accediendo --'); 
                //si lo encuentra actualizar opportunity
                $this->updateOpportunityColumnTags($str_costo, $opportunity, $str_column);

            }
            return $userId;
        }
    }

    public function ContactsCicle($arr_data = array()) {
        //$this->loadModel('SpaLiveV1.SysUsers');
        //$ent_users = $this->SysUsers->find()->select(['SysUsers.id','SysUsers.login_status','SysUsers.name','SysUsers.lname','SysUsers.mname','SysUsers.type','SysUsers.payment_intent', 'SysUsers.tracers','SysUsers.dob','SysUsers.tracers', 'SysUsers.email'])
        //->where(['SysUsers.type' => "injector"]);
        //$this->set('data', $ent_users);

        foreach ($arr_data as $Contact) {
            $str_email = $Contact['email'];
            $str_name = $Contact['name'];
            $str_lname = $Contact['lname'];
            $str_phone = $Contact['phone'];
            $str_costo = $Contact['costo'];
            $str_course = $Contact['course'];

            $data = array(
                "email" => $str_email,
                "name" => $str_name,
                "lname" => $str_lname,
                "phone" => $str_phone,
                "costo" => $str_costo,
                "course" => $str_course,
            );

            if(!env('IS_DEV', false))
            {
                $this->updateOpportunity($data);
            }
        }


        //return $ent_users;

    }

    public function update_users_advanced()
    {
        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.SysUsers');

        $query_users_advanced = "
            SELECT DISTINCT u.id, u.name, u.lname, u.email, u.phone, p.type, p.total
            FROM sys_users u
                INNER JOIN data_payment p ON p.id_from = u.id
                    AND (p.type IN ('ADVANCED COURSE'))
                    AND (p.payment <> '')
            WHERE u.deleted = 0
                AND u.active = 1
                AND u.type = 'injector'
            ORDER BY u.name, u.lname
        ";

        $users_advanced = $this->SysUsers->getConnection()->execute($query_users_advanced)->fetchAll('assoc');

        foreach($users_advanced as $user)
        {
            $array_data = array(
                'email' => $user['email'],
                'name' => $user['name'],
                'lname' => $user['lname'],
                'phone' => $user['phone'],
                'costo' => $user['total'] / 100,
                'course' => 'Advanced'
            );

            if(!env('IS_DEV', false))
            {
                $this->updateOpportunity($array_data);
            }
        }
        
        $this->success();
    }

    public function update_users_basic()
    {
        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.SysUsers');

        $query_users_basic = "
            SELECT DISTINCT u.id, u.name, u.lname, u.email, u.phone, p.type, p.total
            FROM sys_users u
                INNER JOIN data_payment p ON p.id_from = u.id
                    AND (p.type IN ('BASIC COURSE', 'CI REGISTER'))
                    AND (p.payment <> '')
            WHERE u.deleted = 0
                AND u.active = 1
                AND u.type = 'injector'
                AND (u.id IN (
                    SELECT DISTINCT id_from
                    FROM data_payment 
                    WHERE type IN ('BASIC COURSE', 'CI REGISTER')
                        AND (id_from NOT IN (
                            SELECT id_from
                            FROM data_payment 
                            WHERE type IN ('ADVANCED COURSE')
                                AND (payment <> '')
                        ))
                ))
            ORDER BY u.name, u.lname
        ";

        $users_basic = $this->SysUsers->getConnection()->execute($query_users_basic)->fetchAll('assoc');

        foreach($users_basic as $user)
        {
            $array_data = array(
                'email' => $user['email'],
                'name' => $user['name'],
                'lname' => $user['lname'],
                'phone' => $user['phone'],
                'costo' => $user['total'] / 100,
                'course' => 'Basic'
            );

            if(!env('IS_DEV', false))
            {
                $this->updateOpportunity($array_data);
            }
        }
        
        $this->success();
    }

    public function update_users_downloaded()
    {
        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.SysUsers');

        $query_users_downloaded = "
            SELECT DISTINCT u.id, u.name, u.lname, u.email, u.phone
            FROM sys_users u
            WHERE u.deleted = 0
                AND u.active = 1
                AND u.type = 'injector'
                AND (u.id NOT IN (
                    SELECT DISTINCT id_from
                    FROM data_payment 
                    WHERE type IN ('BASIC COURSE', 'CI REGISTER', 'ADVANCED COURSE')
                    AND (payment <> '')
                ))
            ORDER BY u.name, u.lname
        ";

        $users_downloaded = $this->SysUsers->getConnection()->execute($query_users_downloaded)->fetchAll('assoc');

        foreach($users_downloaded as $user)
        {
            $array_data = array(
                'email' => $user['email'],
                'name' => $user['name'],
                'lname' => $user['lname'],
                'phone' => $user['phone'],
                'costo' => 0,
                'course' => 'Downloaded'
            );

            if(!env('IS_DEV', false))
            {   //print_r('seguimos --'); 
                $this->updateOpportunity($array_data);
            }
        }
        
        $this->success();
    }

    public function update_users_partial_registration()
    {
        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.SysUsers');

        $query_users_downloaded = "
            SELECT DISTINCT u.id, u.name, u.lname, u.email, u.phone, u.active, u.deleted, u.is_test, u.uid
            FROM sys_users u
            WHERE u.deleted = 0
                AND u.active = 1
                AND u.is_test = 0
                AND u.type = 'injector'
                AND u.name NOT LIKE '%test%'
                AND u.lname NOT LIKE '%test%'
                AND u.email NOT LIKE '%test%'    
                AND (u.id NOT IN (
                        SELECT DISTINCT id_from
                        FROM data_payment 
                        WHERE (payment <> '')
                    ))
                AND (u.id NOT IN (
                        SELECT DISTINCT user_id
                        FROM data_subscriptions 
                        WHERE status = 'ACTIVE'
                    ))
            ORDER BY u.name, u.lname
        ";
        
        $users_downloaded = $this->SysUsers->getConnection()->execute($query_users_downloaded)->fetchAll('assoc');

        foreach($users_downloaded as $user)
        {
            $array_data = array(
                'email' => $user['email'],
                'name' => $user['name'],
                'lname' => $user['lname'],
                'phone' => $user['phone'],
                'costo' => 0,
                'course' => 'Partial Registration'
            );
            
            if(!env('IS_DEV', false))
            {   //print_r('seguimos --'); 
                $this->updateOpportunity($array_data);
            }
        }
        
        $this->success();
    }

    public function update_users_inactive()
    {
        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.SysUsers');

        $query_users_downloaded = "
            SELECT DISTINCT u.id, u.name, u.lname, u.email, u.phone, u.active, u.deleted, u.is_test
            FROM sys_users u
            WHERE (u.deleted = 1)
                AND u.is_test = 0
                AND u.type = 'injector'
                AND u.name NOT LIKE '%test%'
                AND u.lname NOT LIKE '%test%'
                AND u.email NOT LIKE '%test%'       
            ORDER BY u.name DESC, u.lname DESC
        ";
        
        $users_downloaded = $this->SysUsers->getConnection()->execute($query_users_downloaded)->fetchAll('assoc');

        foreach($users_downloaded as $user)
        {
            $array_data = array(
                'email' => $user['email'],
                'name' => $user['name'],
                'lname' => $user['lname'],
                'phone' => $user['phone'],
                'costo' => 0,
                'course' => 'Inactive'
            );
            
            if(!env('IS_DEV', false))
            {   //print_r('seguimos --'); 
                $this->updateOpportunity($array_data);
            }
        }
        
        $this->success();
    }

    public function update_users_with_subscriptions()
    {
        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.SysUsers');

        $query_users_downloaded = "
            SELECT DISTINCT u.id, u.name, u.lname, u.email, u.phone, u.active, u.deleted, u.is_test, u.uid, s.total
            FROM sys_users u
                INNER JOIN data_subscriptions s ON s.user_id = u.id
            WHERE s.status = 'ACTIVE'
                AND s.deleted = 0
                AND u.deleted = 0
                AND u.active = 1
                AND u.is_test = 0
                AND u.type = 'injector'
                AND u.name NOT LIKE '%test%'
                AND u.lname NOT LIKE '%test%'
                AND u.email NOT LIKE '%test%'    
            ORDER BY u.name, u.lname
        ";
        
        $users_downloaded = $this->SysUsers->getConnection()->execute($query_users_downloaded)->fetchAll('assoc');

        foreach($users_downloaded as $user)
        {
            $array_data = array(
                'email' => $user['email'],
                'name' => $user['name'],
                'lname' => $user['lname'],
                'phone' => $user['phone'],
                'costo' => $user['total'] / 100,
                'course' => 'Injectors With Subscriptions'
            );
            
            if(!env('IS_DEV', false))
            {   // print_r('empezamos --'); 
                $this->updateOpportunity($array_data);
            }
        }
        
        $this->success();
    }

    public function update_users_without_subscriptions()
    {
        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.SysUsers');

        $query_users_downloaded = "
            SELECT DISTINCT u.id, u.name, u.lname, u.email, u.phone, u.active, u.deleted, u.is_test, u.uid
            FROM sys_users u
            WHERE u.deleted = 0
                AND u.active = 1
                AND u.is_test = 0
                AND u.type = 'injector'
                AND u.name NOT LIKE '%test%'
                AND u.lname NOT LIKE '%test%'
                AND u.email NOT LIKE '%test%'    
                AND (u.id NOT IN (
                    SELECT DISTINCT u.id
                    FROM sys_users u
                        INNER JOIN data_subscriptions s ON s.user_id = u.id
                    WHERE s.status = 'ACTIVE'
                        AND s.deleted = 0
                        AND u.deleted = 0
                        AND u.active = 1
                        AND u.is_test = 0
                        AND u.type = 'injector'
                        AND u.name NOT LIKE '%test%'
                        AND u.lname NOT LIKE '%test%'
                        AND u.email NOT LIKE '%test%'  
                    ORDER BY u.name, u.lname
                ))
                AND (u.id NOT IN (
                    SELECT DISTINCT id_from
                    FROM data_payment 
                    WHERE type IN ('CI REGISTER', 'BASIC COURSE', 'ADVANCED COURSE')
                        AND (payment <> '')
                ))
            ORDER BY u.name, u.lname
        ";
        
        $users_downloaded = $this->SysUsers->getConnection()->execute($query_users_downloaded)->fetchAll('assoc');

        foreach($users_downloaded as $user)
        {
            $array_data = array(
                'email' => $user['email'],
                'name' => $user['name'],
                'lname' => $user['lname'],
                'phone' => $user['phone'],
                'costo' => 0,
                'course' => 'Injectors Without Subscriptions'
            );
            
            if(!env('IS_DEV', false))
            {   //print_r('seguimos --'); 
                $this->updateOpportunity($array_data);
            }
        }
        
        $this->success();
    }

    public function update_registered(){
        $this->loadModel('SpaLiveV1.DataCodeConfirm');

        $users = $this->DataCodeConfirm->find()
        ->select(['User.name', 'User.email', 'User.lname', 'User.phone'])
        ->join([
            'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataCodeConfirm.user_id']
        ])
        ->where([
            'DataCodeConfirm.status' => 'CONFIRMED', 
            'User.name NOT LIKE' => '%test%', 
            'User.mname NOT LIKE' => '%test%', 
            'User.lname NOT LIKE' => '%test%',
            'User.type' => 'injector',
            'User.deleted' => 0,
            'User.active' => 1])
        ->group(['DataCodeConfirm.user_id'])
        ->all();

        if(Count($users) > 0){
            foreach($users as $user){
                $Ghl = new GhlController();
                $array_ghl = array(
                    'email' => $user['User']['email'],
                    'name' => $user['User']['name'],
                    'lname' => $user['User']['lname'],
                    'phone' => $user['User']['phone'],
                    'costo' => 0,
                    'column' => 'Registered'
                );

                $contactId = $Ghl->updateOpportunityTags($array_ghl);
                $tag = $Ghl->addTag($contactId, $array_ghl['email'], $array_ghl['phone'], 'Registered', true);

                var_dump($contactId . ' ' . $tag);
            }
        }
    }

    public function update_purchase_b(){
        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.SysUsers');

        $users = $this->DataPayment->find()
        ->select(['User.name', 'User.email', 'User.lname', 'User.phone', 'DataPayment.total'])
        ->join([
            'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataPayment.id_from']
        ])
        ->where([
            'DataPayment.type IN' => array('CI REGISTER','BASIC COURSE'),
            'DataPayment.payment <>' => '',
            'DataPayment.is_visible' => 1,
            'User.name NOT LIKE' => '%test%',
            'User.mname NOT LIKE' => '%test%',
            'User.lname NOT LIKE' => '%test%',
            'User.type' => 'injector',
            'User.deleted' => 0,
            'User.active' => 1])
        ->group(['DataPayment.id_from'])
        ->all();

        if(Count($users) > 0){
            foreach($users as $user){
                $Ghl = new GhlController();
                $array_ghl = array(
                    'email' => $user['User']['email'],
                    'name' => $user['User']['name'],
                    'lname' => $user['User']['lname'],
                    'phone' => $user['User']['phone'],
                    'costo' => $user['DataPayment']['total'] / 100,
                    'column' => 'Purchased basic'
                );

                $contactId = $Ghl->updateOpportunityTags($array_ghl);
                $tag = $Ghl->addTag($contactId, $array_ghl['email'], $array_ghl['phone'], 'Purchased basic', true);

                var_dump($contactId . ' ' . $tag);
            }
        }
    }

    public function update_purchase_a(){
        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.SysUsers');

        $users = $this->DataPayment->find()
        ->select(['User.name', 'User.email', 'User.lname', 'User.phone', 'DataPayment.total'])
        ->join([
            'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataPayment.id_from']
        ])
        ->where([
            'DataPayment.type IN' => array('ADVANCED COURSE'),
            'DataPayment.payment <>' => '',
            'DataPayment.is_visible' => 1,
            'User.name NOT LIKE' => '%test%',
            'User.mname NOT LIKE' => '%test%',
            'User.lname NOT LIKE' => '%test%',
            'User.type' => 'injector',
            'User.deleted' => 0,
            'User.active' => 1])
        ->group(['DataPayment.id_from'])
        ->all();

        if(Count($users) > 0){
            foreach($users as $user){
                $Ghl = new GhlController();
                $array_ghl = array(
                    'email' => $user['User']['email'],
                    'name' => $user['User']['name'],
                    'lname' => $user['User']['lname'],
                    'phone' => $user['User']['phone'],
                    'costo' => $user['total'] / 100,
                    'column' => 'Purchased advanced (no subscription)'
                );

                $contactId = $Ghl->updateOpportunityTags($array_ghl);
                $tag = $Ghl->addTag($contactId, $array_ghl['email'], $array_ghl['phone'], 'Purchased advanced', true);

                var_dump($contactId . ' ' . $tag);
            }
        }
    }
    
    public function update_subscription(){
        $this->loadModel('SpaLiveV1.DataSubscriptions');

        $users = $this->DataSubscriptions->find()
        ->select(['User.name', 'User.email', 'User.lname', 'User.phone'])
        ->join([
            'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataSubscriptions.user_id'],
            'Training' => ['table' => 'data_trainings', 'type' => 'INNER', 'conditions' => 'Training.user_id = DataSubscriptions.user_id'],
            'CT' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CT.id = Training.training_id'],
        ])
        ->where([
            'DataSubscriptions.status' => 'ACTIVE',
            'DataSubscriptions.subscription_type' => 'SUBSCRIPTIONMD',
            'DataSubscriptions.deleted' => 0,
            'User.name NOT LIKE' => '%test%',
            'User.mname NOT LIKE' => '%test%',
            'User.lname NOT LIKE' => '%test%',
            'User.type' => 'injector',
            'User.deleted' => 0,
            'User.active' => 1,
            'Training.deleted' => 0,
            'Training.attended' => 1,
            'CT.level' => 'LEVEL 1',
        ])
        ->all();

        if(Count($users) > 0){
            foreach($users as $user){
                $array_ghl = array(
                    'email' => $user['User']['email'],
                    'name' => $user['User']['name'],
                    'lname' => $user['User']['lname'],
                    'phone' => $user['User']['phone'],
                    'costo' => $user['total'] / 100,
                    'column' => 'Subscribed to basic neurotoxins'
                );

                $contactId = $this->updateOpportunityTags($array_ghl);
                $tag = $this->addTag($contactId, $array_ghl['email'], $array_ghl['phone'], 'Subscribed to neurotoxins', true);

                var_dump($contactId . ' ' . $tag);
            }
        }
    }

    public function update_subscription_a(){
        $this->loadModel('SpaLiveV1.DataSubscriptions');

        $users = $this->DataSubscriptions->find()
        ->select(['User.name', 'User.email', 'User.lname', 'User.phone'])
        ->join([
            'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataSubscriptions.user_id'],
            'Training' => ['table' => 'data_trainings', 'type' => 'INNER', 'conditions' => 'Training.user_id = DataSubscriptions.user_id'],
            'CT' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CT.id = Training.training_id'],
        ])
        ->where([
            'DataSubscriptions.status' => 'ACTIVE',
            'DataSubscriptions.subscription_type' => 'SUBSCRIPTIONMD',
            'DataSubscriptions.deleted' => 0,
            'User.name NOT LIKE' => '%test%',
            'User.mname NOT LIKE' => '%test%',
            'User.lname NOT LIKE' => '%test%',
            'User.type' => 'injector',
            'User.deleted' => 0,
            'User.active' => 1,
            'Training.deleted' => 0,
            'Training.attended' => 1,
            'CT.level' => 'LEVEL 2',
        ])
        ->all();

        if(Count($users) > 0){
            foreach($users as $user){
                $array_ghl = array(
                    'email' => $user['User']['email'],
                    'name' => $user['User']['name'],
                    'lname' => $user['User']['lname'],
                    'phone' => $user['User']['phone'],
                    'costo' => $user['total'] / 100,
                    'column' => 'Subscribed with advanced neurotoxins'
                );

                $contactId = $this->updateOpportunityTags($array_ghl);

                var_dump($contactId);
            }
        }
    }

    public function update_sub_school(){
        $this->loadModel('SpaLiveV1.DataSubscriptions');

        $users = $this->DataSubscriptions->find()
        ->select(['User.name', 'User.email', 'User.lname', 'User.phone'])
        ->join([
            'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataSubscriptions.user_id'],
            'Courses' => ['table' => 'data_courses', 'type' => 'INNER', 'conditions' => 'Courses.user_id = DataSubscriptions.user_id'],
            'CC' => ['table' => 'cat_courses', 'type' => 'INNER', 'conditions' => 'CC.id = Courses.course_id'],
        ])
        ->where([
            'DataSubscriptions.status' => 'ACTIVE',
            'DataSubscriptions.subscription_type' => 'SUBSCRIPTIONMD',
            'DataSubscriptions.deleted' => 0,
            'User.name NOT LIKE' => '%test%',
            'User.mname NOT LIKE' => '%test%',
            'User.lname NOT LIKE' => '%test%',
            'User.type' => 'injector',
            'User.deleted' => 0,
            'User.active' => 1,
            'Courses.deleted' => 0,
            'Courses.status' => 'DONE',
            'CC.type NOT IN' => array('FILLERS'),
        ])
        ->all();

        if(Count($users) > 0){
            foreach($users as $user){
                $array_ghl = array(
                    'email' => $user['User']['email'],
                    'name' => $user['User']['name'],
                    'lname' => $user['User']['lname'],
                    'phone' => $user['User']['phone'],
                    'costo' => 0,
                    'column' => 'Subscribed from another school'
                );

                $contactId = $this->updateOpportunityTags($array_ghl);

                var_dump($contactId);
            }
        }
    }

    public function update_unsubscription(){
        $this->loadModel('SpaLiveV1.DataSubscriptions');

        $users = $this->DataSubscriptions->find()
        ->select(['User.name', 'User.email', 'User.lname', 'User.phone'])
        ->join([
            'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataSubscriptions.user_id'],
        ])
        ->where([
            'DataSubscriptions.status' => 'CANCELLED',
            'DataSubscriptions.subscription_type' => 'SUBSCRIPTIONMD',
            'DataSubscriptions.deleted' => 0,
            'User.name NOT LIKE' => '%test%',
            'User.mname NOT LIKE' => '%test%',
            'User.lname NOT LIKE' => '%test%',
            'User.type' => 'injector',
            'User.deleted' => 0,
            'User.active' => 1,
        ])
        ->all();

        if(Count($users) > 0){
            foreach($users as $user){
                $array_ghl = array(
                    'email' => $user['User']['email'],
                    'name' => $user['User']['name'],
                    'lname' => $user['User']['lname'],
                    'phone' => $user['User']['phone'],
                    'costo' => 0,
                    'column' => 'Completely unsubscribed'
                );

                $contactId = $this->updateOpportunityTags($array_ghl);

                $tag = $this->addTag($contactId, $array_ghl['email'], $array_ghl['phone'], 'Unsubscribed', true);

                var_dump($contactId . ' ' . $tag);
            }
        }
    }

    public function update_inactive_tag(){
        $this->loadModel('SpaLiveV1.SysUsers');

        $users = $this->SysUsers->find()
        ->select(['SysUsers.name', 'SysUsers.email', 'SysUsers.lname', 'SysUsers.phone'])
        ->where([
            'SysUsers.name NOT LIKE' => '%test%',
            'SysUsers.mname NOT LIKE' => '%test%',
            'SysUsers.lname NOT LIKE' => '%test%',
            'SysUsers.type' => 'injector',
            'SysUsers.deleted' => 0,
            'SysUsers.active' => 0,
        ])
        ->all();

        if(Count($users) > 0){
            foreach($users as $user){
                $array_ghl = array(
                    'email' => $user['email'],
                    'name' => $user['name'],
                    'lname' => $user['lname'],
                    'phone' => $user['phone'],
                    'costo' => 0,
                    'column' => 'Inactive'
                );

                $contactId = $this->updateOpportunityTags($array_ghl);

                $tag = $this->addTag($contactId, $array_ghl['email'], $array_ghl['phone'], 'Inactive', true);

                var_dump($contactId . ' ' . $tag);
            }
        }
    }

    public function ghl_webhook(){
        $input = file_get_contents('php://input');
		$data = json_decode($input, true);

        if($data['type'] == 'ContactTagUpdate'){
            if(isset($data['id']) && isset($data['tags'])){
                $sales_array = array(
                    'jenna' => array('id' => 13410, 'email' => 'jennaleighbichler@gmail.com', 'name' => 'Jenna Bichler'),
                    'jess' => array('id' => 8468, 'email' => 'jessicalynndejong@gmail.com', 'name' => 'Jessica deJong'),
                    'carly' => array('id' => 24735, 'email' => 'icaarly760@gmail.com', 'name' => 'Carly Camargo'),
                    'april' => array('id' => 20313, 'email' => 'southernedcharm@gmail.com', 'name' => 'April McClure'),
                    'kelcie' => array('id' => 21457, 'email' => 'xkelciej143x@gmail.com', 'name' => 'Kelcie Johnson'),
                );

                $tags = array_reverse($data['tags']);

                $new_rep = 0;
                foreach($tags as $tag){
                    if(isset($sales_array[$tag])){
                        $new_rep = $sales_array[$tag]['id'];
                        break;
                    }
                }

                if($new_rep <= 0){
                    exit;
                }

                $this->loadModel('SpaLiveV1.SysUsers');

                $user = $this->SysUsers->find()->where(['email' => $data['email']])->first();

                if(empty($user)){
                    exit;
                }

                $this->loadModel('SpaLiveV1.DataAssignedToRegister');
                $this->loadModel('SpaLiveV1.DataGhlWebhook');

                $assigned = $this->DataAssignedToRegister->find()->where(['user_id' => $user->id])->first();

                if(empty($assigned)){
                    $array_assign = array(
                        'user_id' => $user->id,
                        'representative_id' => $new_rep,
                        'deleted' => 0,
                        'manual' => 1,
                        'created' => date('Y-m-d H:i:s'),
                    );
                    $new_assigned = $this->DataAssignedToRegister->newEntity($array_assign);
                    $this->DataAssignedToRegister->save($new_assigned);
    
                    $data_ghl = $this->DataGhlWebhook->newEntity(
                        array('ghl_response' => json_encode(array('user_id' => $data['id'], 'email' => $data['email'], 'tags' => $data['tags'])), 
                        'result' => 'Assigned to ' . $new_rep, 
                        'created' => date('Y-m-d H:i:s')));

                    $this->DataGhlWebhook->save($data_ghl);
                } else{
                    $this->loadModel('SpaLiveV1.DataPayment');
                    $pay = $this->DataPayment->find()
                    ->where(['id_from' => $user->id, 'type IN' => array('BASIC COURSE','ADVANCED COURSE'), 'payment <>' => '', 'is_visible' => 1])->first();

                    if(empty($pay)){
                        $assigned->representative_id = $new_rep;
                        $this->DataAssignedToRegister->save($assigned);

                        $data_ghl = $this->DataGhlWebhook->newEntity(
                        array('ghl_response' => json_encode(array('user_id' => $data['id'], 'email' => $data['email'], 'tags' => $data['tags'])), 
                        'result' => 'Reassigned to ' . $new_rep, 
                        'created' => date('Y-m-d H:i:s')));
                    } else{
                        $data_ghl = $this->DataGhlWebhook->newEntity(
                            array('ghl_response' => json_encode(array('user_id' => $data['id'], 'email' => $data['email'], 'tags' => $data['tags'])), 
                            'result' => 'Not assigned',
                            'created' => date('Y-m-d H:i:s')));
                    }
                }
            }
        }
    }

    public function remove_tag_from_panel(){
        $panel = get('l3n4p', '');
        if((empty($panel) || $panel != '609248af7ce858.91s69218')){
            $this->message('Invalid token.');
            return;
        }

        $phone = get('phone', '');
        $email = get('email', '');
        $tag = get('tag', '');

        if(empty($phone) && empty($email)){
            $this->message('Phone or email is required.');
            return;
        }

        if(empty($tag)){
            $this->message('Tag is required.');
            return;
        }

        $response = $this->removeTag('', $email, $phone, $tag);

        return json_encode($response);
    }

    public function add_tag_from_panel(){
        $panel = get('l3n4p', '');
        if((empty($panel) || $panel != '609248af7ce858.91s69218')){
            $this->message('Invalid token.');
            return;
        }

        $phone = get('phone', '');
        $email = get('email', '');
        $tag = get('tag', '');

        if(empty($phone) && empty($email)){
            $this->message('Phone or email is required.');
            return;
        }

        if(empty($tag)){
            $this->message('Tag is required.');
            return;
        }

        $response = $this->addTag('', $email, $phone, $tag);

        return $response;
    }

    public function assing_rep_ghl(){
        $this->loadModel('SpaLiveV1.DataAssignedToRegister');

        $users = $this->DataAssignedToRegister->find()
        ->select(['User.email', 'User.phone', 'DataAssignedToRegister.representative_id'])
        ->join([
            'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataAssignedToRegister.user_id'],
        ])
        ->where(['DataAssignedToRegister.deleted' => 0])->all();

        if(Count($users) > 0){
            foreach($users as $user){
                $sales_array = array(
                    13410 => 'jenna',
                    8468 => 'jess',
                    24735 => 'carly',
                    20313 => 'april',
                );

                $tag = $this->addTag('', $user['User']['email'], $user['User']['phone'], $sales_array[$user['representative_id']]);
                var_dump($tag);
            }
        }
    }
}