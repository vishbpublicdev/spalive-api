<?php
declare(strict_types=1);

namespace SpaLiveV1\Controller;
use App\Controller\AppPluginController;
use Cake\Core\Configure;
use Cake\Utility\Security;
use Cake\Utility\Text;
use Cake\Utility\Hash;
ini_set('max_execution_time', '0');
require_once(ROOT . DS . 'vendor' . DS  . 'Twilio' . DS . 'autoload.php');

require_once(ROOT . DS . 'vendor' . DS  . 'stripe' . DS . 'init.php');



class MailChimpController extends AppPluginController {
	
	private $hasError = 0;
	private $errorMessage = "";
	private $lastMessage = "";
	private $arrayErrorMessage = [];
	private $arrayMessage = [];

	public function initialize() : void {
        parent::initialize();
        
		//$this->loadModel('SpaLiveV1.DataMailchimpUser');		
		
    }	

	public function run_main() {	
		
		$type = get('type','');
        $body = get('body','');
        $is_sms = get('is_sms',0);
        $is_email = get('is_email',0);
        $subject = get('subject', '');
        $now = date('Y-m-d H:i:s');              
		
		if(empty($type) || empty($body) || empty($subject) ){
			 $this->log(__LINE__ . ' ' . json_encode(' type body subject empty.'));
			return;
		}
		$this->loadModel('SpaLiveV1.DataMailchimpCampaign');//DataMailchimpCampaign
		$uid = Text::uuid();
		$array_save = array(			
			'uid' => $uid,
			'type' => $type,
			'body' => $body,
			'subject' => $subject,
			'status' => 'start',
			//'error' => 'error',
			//'message' => 'message',			
			'deleted' => 0,						
			'created' => date('Y-m-d H:i:s'),			
		);
		 //$this->log(__LINE__ . ' ' . json_encode($array_save));
		$c_entity = $this->DataMailchimpCampaign->newEntity($array_save);
		if(!$c_entity->hasErrors()) {
			$this->DataMailchimpCampaign->save($c_entity); 
		}else{
			 $this->log(__LINE__ . ' ' . json_encode(' error save '));
			return;
		}

		 
		$this->log(__LINE__ . ' ' . env('COMMAND_PATH', '') . ' mailchimp ' . $uid . ' > /dev/null 2>&1 &');
		//shell_exec(env('COMMAND_PATH', '') . ' mailchimp ' . $type .' '.json_encode($body) .' '.json_encode($subject) . ' > /dev/null 2>&1 &');
		shell_exec(env('COMMAND_PATH', '') . ' mailchimp ' . $uid . '  ');

	}

	public function main($ent_user,$type,$body,$subject,$uid) {
		if(count($ent_user) == 0){
			$this->log(__LINE__ . ' ' . json_encode('No hay datos'));
			return;
		}		

		$this->loadModel('SpaLiveV1.DataMailchimpCampaign');
		$this->DataMailchimpCampaign->updateAll(
			['status' => 'main start', 'error' => '', 'message' => ''],
			['uid' => $uid]
		);
		
		$this->log(__LINE__ . ' ' . json_encode(count($ent_user)));
		//$date = date('mdY');
		//$audience_name = $date;
		$audience_name = 'All';
		//$type = 'INJECTORS WITH SUBSCRIPTION';
		//$type .= "_" .$date;
        $status = $this->check_status();
		if($this->hasError){
			 //$this->log(__LINE__ . ' ' . json_encode($this->errorMessage));
			return;
		}
        if($status){
            //webhooks            
            // -------------------------------------------------------------   busca la audiencia
            $id = $this->getAllLists($audience_name);						
            if($id === 0){//crea la audiencia
                $created = $this->createList([//crear audiencia
                    "name" => $audience_name,
                    "permission_reminder" => "permission_reminder",
                    "email_type_option" => false,
                    "contact" => [
                        "company" => "MySpa Live",
                        "address1" => "MySpaLive 130 N Preston Rd Prosper",
                        "city" => "USA",
                        "state" => "TX",
                        "zip" => "75078 ",
                        "country" => "USA",
                    ],
                    "campaign_defaults" => [
                        "from_name" => "MySpaLive",
                    "from_email" => "dev@myspalive.com",
                    "subject" => $subject,
                    "language" => "EN_US",
                    ],
                ]);                                       
                //no error
                if($created == false){
                    //$this->log(__LINE__ . ' ' . json_encode('Error al crear la audiencia'));                
                    return;
                }else{
					$id = $created;
                    $webhooks = $this->getAllWebhooks($id);
                    if($webhooks == 0){
                        $this->createWebhook($id,'all');
                    }
                }
            }//else// ------------------------------------- ya existe la audiencia continua                        
            $this->log(__LINE__ . 'ya existe la audiencia continua ' . json_encode($id));
            if($id !== 0){ //-------------------------------busca webhook para actualizar tabla y actualizar usuarios unsuscritos
                $webhooks = $this->getAllWebhooks($id);
                 //$this->log(__LINE__ . ' ' . json_encode($webhooks));
                if($webhooks == 0){//---------------------------crea webhook
                    $r_createWebhook = $this->createWebhook($id,'all');
                    //$this->log(__LINE__ . ' ' . json_encode($r_createWebhook));
                    if($r_createWebhook == 0){
                        //$this->log(__LINE__ . ' ' . json_encode('Error al crear webhook'));
                        return;
                    }
                }                                    
                $b=0; //$this->log(__LINE__ . ' ' . json_encode(''));
                $this->loadModel('SpaLiveV1.DataMailchimpUser');
				//busca etiqueta si existe regresa array, no existe regresa arreglo vacio				
				//$exist_tag = $this->searchTags($id,$type);  //$this->log(__LINE__ . ' ' . json_encode($exist_tag));
				//if(count($exist_tag) == 0){
					//crear etiqueta
					$exist_tag['tags'][] = array('name' =>"$type" , 'status'=> "active");					
				//}	else{
				//	$exist_tag['tags'][] = $exist_tag;
				//} 
				$this->log(__LINE__ . ' mainchimp alta usuarios ');               
                foreach ($ent_user as $row_u) {// alta usuarios
                    $search_local = $this->DataMailchimpUser->find()->where(['DataMailchimpUser.id_sys_uses' =>$row_u['id']])->first(); 
                    //valida que desde mailchimp el usuario no haya sido borrado, o unsucrito. 

                    if(!empty($search_local) && $search_local->deleted == 1 ){
						//$this->log(__LINE__ . ' ' . json_encode($search_local));
						//$this->log(__LINE__ . ' ' . json_encode('user deleted continue'));						
                        continue;
                    }
					
                    //$this->log(__LINE__ . ' ' . json_encode('insert update to mailchimp'));
                    $user=[
                        "email_address" => $row_u['email'],
                        "status" => "subscribed",
                        'id_sys_user' => $row_u['id'],
                        "merge_fields" => [
                            "FNAME" => $row_u['name'],
                            "LNAME" => $row_u['lname'],
						],
						
                        ];
					
						$b++;
					
                    $res_add = $this->addListMember($user,$id,$exist_tag);
                    if($res_add == "Member Exists"){
                        //update
                        //$res_sea = $this->searchMemberByEmail($row_u['email'],$id);
						$res_sea = $this->searchMemberBysearchMembers($row_u['email'],$id);						 
                        
                        //update user
                        if(isset($res_sea['id'])){
                        	$res_sea_r = $this->updateMember($res_sea['id'],$user,$id);						
                        	//$this->log(__LINE__ . ' ' . json_encode($res_sea_r));
							//actualizar tag
							$this->addUpdateTag($id,$res_sea['id'],$exist_tag);
						}
                    }
                    /*if($b == 5){
                        break;
                    }*/
                }
                
                //regresa los usuarios registrados en la audiencia en mailchimp
                //$usuarios_mailchimp = $this->getListMember($ent_user,$id);
                //borra los usuarios que no estan en la lista local
				//borrar las etiquetas de los usuarios que no estan en la lista local
				$exist_tag = $this->searchTags($id,$type);//{"id":26,"name":"INJECTOR"}				
				$tag['tags'][] = array('name' =>"$type" , 'status'=> "inactive");;
				//$this->log(__LINE__ . ' ' . json_encode($tag));				
                //$this->comparar_arreglos($usuarios_mailchimp, $ent_user,$id,$tag);
				//campaings buscar
				$Campaing_f = $this->getAllCampaing($type);
				
				
				if($this->hasError){
					$this->log(__LINE__ . ' ' . json_encode($this->errorMessage));
					$this->DataMailchimpCampaign->updateAll(
						['status' => 'main end', 'error' => json_encode($this->arrayErrorMessage), 'message' => json_encode($this->arrayMessage)],
						['uid' => $uid]
					);
					return;
				}
				if($Campaing_f === 0){
					//crear campania
					$Campaing_f = $this->createCampaing($id,$type, $subject,$exist_tag['id']);
						//$this->log(__LINE__ . ' ' . json_encode($Campaing_f));
						$r_addContentCampaing = $this->addContentCampaing($Campaing_f,$body);
						if($r_addContentCampaing != 0){
							$this->sendCampaing($Campaing_f,$body);
						}
				}else{//found campaing
					//actualizar campania
					//$this->log(__LINE__ . ' ' . json_encode($Campaing_f));
					$r_addContentCampaing =$this->addContentCampaing($Campaing_f,$body);
					if($r_addContentCampaing != 0){
						$this->sendCampaing($Campaing_f,$body);
					}
				}
				$this->log(__LINE__ . ' ' . json_encode($this->arrayErrorMessage));
				$this->log(__LINE__ . ' ' . json_encode($this->arrayMessage));
				
				$this->log(__LINE__ . ' ' . json_encode(' mainchimp e n d'));
				$this->DataMailchimpCampaign->updateAll(
					['status' => 'main end', 'error' => json_encode($this->arrayErrorMessage), 'message' => json_encode($this->arrayMessage)],
					['uid' => $uid]
				);								               
            }
        }
	}


    public function  check_status(){  
		//$this->log(__LINE__ . ' ' . json_encode('check_status'));      	
		$mailchimp_secret_key = env('mailchimp_secret_key', '');
		$mailchimp_url = env('mailchimp_url', '');      //https://us13.api.mailchimp.com/3.0/
        $curl = curl_init();
        //$this->log(__LINE__ . ' ' . ($mailchimp_secret_key));
		//$this->log(__LINE__ . ' ' . ($mailchimp_url));
        curl_setopt($curl, CURLOPT_URL, $mailchimp_url.'ping');
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);        
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        //curl_setopt($curl, CURLOPT_POST, true); 
        //curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_HEADER, FALSE);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type:application/json","Authorization: apikey $mailchimp_secret_key"]);

        $result = curl_exec($curl);
		if (curl_errno($curl)) {
			$error_msg = curl_error($curl);
			//$this->log(__LINE__ . ' ' . $error_msg);
			$this->errorMessage = $error_msg;
			$this->hasError = 1;
		}
        curl_close($curl);

        
        //$this->log(__LINE__ . ' ' . ($result));
		$result = json_decode($result, true);
		//$this->log(__LINE__ . ' ' . json_encode($result['health_status']));
		//$this->log(__LINE__ . ' ' . json_encode($result['type']));
		if(isset($result['health_status']) && $result['health_status'] == "Everything's Chimpy!"){
			$this->arrayMessage[] = __LINE__ .'health_status ';
			return true;
		}else{
			if(isset($result['title'])){
			//$this->log(__LINE__ . ' ' . json_encode($result['title']));
			$this->errorMessage = json_encode($result['title']);
			$this->hasError = 1;
			$this->arrayErrorMessage[] = json_encode($result['title']);
			}
		}
		return false;

    }

	public function  searchList($campana){
		//$this->log(__LINE__ . ' ' . json_encode('searchList'));      	
        

		$mailchimp_secret_key = env('mailchimp_secret_key', 'mailchimp_secret_key_1');
		$mailchimp_url = env('mailchimp_url', 'https://us13.api.mailchimp.com/3.0/');      
        $curl = curl_init();
        
        curl_setopt($curl, CURLOPT_URL, $mailchimp_url.'lists');
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);        
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        //curl_setopt($curl, CURLOPT_POST, true); 
        //curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($campana));
        curl_setopt($curl, CURLOPT_HEADER, FALSE);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type:application/json","Authorization: apikey {$mailchimp_secret_key}"]);

        $result = curl_exec($curl);
		if (curl_errno($curl)) {
			$error_msg = curl_error($curl);
			//$this->log(__LINE__ . ' ' . $error_msg);
			$this->errorMessage = $error_msg;
			$this->hasError = 1;
		}
        curl_close($curl);

        
        //$this->log(__LINE__ . ' ' . ($result));
		$result = json_decode($result, true);
		//$this->log(__LINE__ . ' ' . json_encode($result['health_status']));
		//$this->log(__LINE__ . ' ' . json_encode($result['type']));
		if(isset($result['health_status']) && $result['health_status'] == "Everything's Chimpy!"){
			return true;
		}else{
			if(isset($result['title'])){
			//$this->log(__LINE__ . ' ' . json_encode($result['title']));
			$this->errorMessage = json_encode($result['title']);
			$this->hasError = 1;
			$this->arrayErrorMessage[] = json_encode($result['title']);
			}
		}
		return false;

    }
	public function  createList($campana){
        //$this->log(__LINE__ . ' ' . json_encode('createList'));

		$mailchimp_secret_key = env('mailchimp_secret_key', 'mailchimp_secret_key_1');
		$mailchimp_url = env('mailchimp_url', 'https://us13.api.mailchimp.com/3.0/lists/');      
        $curl = curl_init();
        
        curl_setopt($curl, CURLOPT_URL, $mailchimp_url.'lists');
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);        //
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_POST, true); 
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($campana));
        curl_setopt($curl, CURLOPT_HEADER, FALSE);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type:application/json","Authorization: apikey {$mailchimp_secret_key}"]);

        $result = curl_exec($curl);
		if (curl_errno($curl)) {
			$error_msg = curl_error($curl);
			//$this->log(__LINE__ . ' ' . $error_msg);
			$this->errorMessage = $error_msg;
			$this->hasError = 1;
		}
        curl_close($curl);

        
        //$this->log(__LINE__ . ' ' . ($result));
		$result = json_decode($result, true);
		//$this->log(__LINE__ . ' ' . json_encode($result['health_status']));
		//$this->log(__LINE__ . ' ' . json_encode($result['type']));
		if(isset($result['id']) ){
			$this->arrayMessage[] = __LINE__ .'List created '. $result['id'];
			return $result['id'];
		}else{
			if(isset($result['title'])){
			//$this->log(__LINE__ . ' ' . json_encode($result['title']));
			$this->errorMessage = json_encode($result['title']);
			$this->hasError = 1;
			$this->arrayErrorMessage[] = json_encode($result['title']);
			}
		}
		return false;

    }


	public function  getAllLists($campana){  
		//$this->log(__LINE__ . ' ' . json_encode('getAllLists'));      	

		$mailchimp_secret_key = env('mailchimp_secret_key', '');
		$mailchimp_url = env('mailchimp_url', 'https://us13.api.mailchimp.com/3.0/');      								
        //URL_API = env('URL_API', 'https://api.spalivemd.com/');
 		
        $curl = curl_init();        
        curl_setopt($curl, CURLOPT_URL, $mailchimp_url.'lists');
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);        
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        //curl_setopt($curl, CURLOPT_POST, true); 
        //curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($campana));
        curl_setopt($curl, CURLOPT_HEADER, FALSE);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type:application/json","Authorization: apikey {$mailchimp_secret_key}"]);

        $result = curl_exec($curl);
		if (curl_errno($curl)) {
			$error_msg = curl_error($curl);
			//$this->log(__LINE__ . ' ' . $error_msg);
			$this->errorMessage = $error_msg;
			$this->hasError = 1;
		}
        curl_close($curl);

        
        //$this->log(__LINE__ . ' ' . ($result));
		$result = json_decode($result, true);
		$list_id =0;		
		if(isset($result['lists']) ){
			$audiencias = $result['lists']; 
			for ($i=0; $i < count($audiencias); $i++) { 
				//$this->log(__LINE__ . ' ' . json_encode($response));
				//$this->log(__LINE__ . ' ' . json_encode($audiencias[$i]));
				$audiencia = $audiencias[$i];
				//$this->log(__LINE__ . ' ' . json_encode($audiencia));
	
				if($audiencia['name'] == $campana){
					 //$this->log(__LINE__ . 'audience found ' . json_encode($audiencia['id']));
					 $list_id = $audiencia['id'];
					 $this->arrayMessage[] = __LINE__ .'audience found ' . $audiencia['id'];
					 break;
				}            
			  }

			return $list_id;
		}else{
			if(isset($result['title'])){
			//$this->log(__LINE__ . ' ' . json_encode($result['title']));
			$this->errorMessage = json_encode($result['title']);
			$this->hasError = 1;
			$this->arrayErrorMessage[] = json_encode($result['title']);
			}
		}
		return 0;

    }

	
	public function  addListMember($addListMember , $id_audiencia,$exist_tag){
		//$this->log(__LINE__ . ' ' . json_encode('addListMember'));      	

        $mailchimp_secret_key = env('mailchimp_secret_key', 'mailchimp_secret_key_1');
		$mailchimp_url = env('mailchimp_url', 'https://us13.api.mailchimp.com/3.0/lists/');      
       //$this->log(__LINE__ . ' ' . json_encode($id_audiencia));
	   //$this->log(__LINE__ . ' ' . json_encode($addListMember));
	   //$this->log(__LINE__ . ' ' . ($mailchimp_url.$id_audiencia.'/members'));
        $curl = curl_init();
        
        curl_setopt($curl, CURLOPT_URL, $mailchimp_url.'lists/'.$id_audiencia.'/members');
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);        
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_POST, true); 
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($addListMember));
        curl_setopt($curl, CURLOPT_HEADER, FALSE);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type:application/json","Authorization: apikey {$mailchimp_secret_key}"]);

        $result = curl_exec($curl);
		if (curl_errno($curl)) {
			$error_msg = curl_error($curl);
			//$this->log(__LINE__ . ' ' . $error_msg);
			$this->errorMessage = $error_msg;
			$this->hasError = 1;
		}
        curl_close($curl);

        
        //$this->log(__LINE__ . ' ' . ($result));
		$result = json_decode($result, true);
		$this->loadModel('SpaLiveV1.DataMailchimpUser');
		if(isset($result['id'])){
			$this->addUpdateTag($id_audiencia,$result['id'],$exist_tag);
			$this->arrayMessage[] = __LINE__ .'user add ' . $result['id'];
			/*$array_save = array(
				'id_sys_uses' => $addListMember['id_sys_user'],
				'hash_id_audience' =>$id_audiencia,
				'hash_id_mailchimp' => $result['id'],
				'subscribed' =>'subscribed',
				'deleted' => 0,				
				//'updated' => '',				
				'created' => date('Y-m-d H:i:s'),
				
			);
	 		//$this->log(__LINE__ . ' ' . json_encode($array_save));
			$c_entity = $this->DataMailchimpUser->newEntity($array_save);
			if(!$c_entity->hasErrors()) {
				$this->DataMailchimpUser->save($c_entity); 
			}*/
			return true;
		}else{
			if(isset($result['title'])){
			//$this->log(__LINE__ . ' ' . json_encode($result));
			$this->errorMessage = json_encode($result);
			//$this->hasError = 1;
			$this->arrayErrorMessage[] = json_encode($result['title']);
			}
		}
		return 0;

    }

	public function  getListMember($addListMember , $id_audiencia){  
		//$this->log(__LINE__ . ' ' . json_encode('getListMember'));      	

		$mailchimp_secret_key = env('mailchimp_secret_key', '');      
		$mailchimp_url = env('mailchimp_url', 'https://us13.api.mailchimp.com/3.0/lists/');      
		//$this->log(__LINE__ . ' ' . json_encode($id_audiencia));
		//$this->log(__LINE__ . ' ' . json_encode($addListMember));
		//$users = [];
		$users_news = array_map(function($user) {
			return [
				'id' => $user['id'],
				'email' => $user['email'],
			];
		}, $addListMember);
		
		
 		//$this->log(__LINE__ . ' ' . json_encode($users_news));
		 $curl = curl_init();
		 
		 curl_setopt($curl, CURLOPT_URL, $mailchimp_url.'lists/'.$id_audiencia.'/members');
		 curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);		 
		 curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		 curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
		 curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
		 curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
		 //curl_setopt($curl, CURLOPT_POST, true); 
		 //curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($addListMember));
		 curl_setopt($curl, CURLOPT_HEADER, FALSE);
		 curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type:application/json","Authorization: apikey {$mailchimp_secret_key}"]);
 
		 $result = curl_exec($curl);
		 if (curl_errno($curl)) {
			 $error_msg = curl_error($curl);
			 //$this->log(__LINE__ . ' ' . $error_msg);
			 $this->errorMessage = $error_msg;
			$this->hasError = 1;
		 }
		 curl_close($curl); 
		 
		 //$this->log(__LINE__ . ' ' . ($result));
		 $result = json_decode($result, true);
		 $this->loadModel('SpaLiveV1.DataMailchimpUser');
		 if(isset($result['members'])){				
			$r_members = $result['members'];			
			$members = array_map(function($user) { //$this->log(__LINE__ . ' ' . json_encode($user['id']));
				return [
					'id' => $user['id'],
					'email' => $user['email_address'],
				];
			}, $r_members);
			//$this->log(__LINE__ . ' ' . json_encode($members));			
			$this->arrayMessage[] = __LINE__ . ' user found ' . json_encode($members);			
			return $r_members;
		 }else{
			 if(isset($result['title'])){
			 	//$this->log(__LINE__ . ' ' . json_encode($result['title']));
				 $this->errorMessage = json_encode($result['title']);
				 $this->hasError = 1;
				 $this->arrayErrorMessage[] = json_encode($result['title']);
			 }
		 }
		 return [];
 
	 }

	 function buscarPorCorreo($array, $email) {
		foreach ($array as $item) {
			if ($item['email'] === $email) {
				return $item;
			}
		}
		return null;
	}

	public function  searchMemberByEmail($email , $id_audiencia){  
		//$this->log(__LINE__ . ' ' . json_encode('searchMemberByEmail'));      	

		$mailchimp_secret_key = env('mailchimp_secret_key', 'mailchimp_secret_key_1');
		$mailchimp_url = env('mailchimp_url', 'https://us13.api.mailchimp.com/3.0/lists/');      
		 $curl = curl_init();
		  
		 curl_setopt($curl, CURLOPT_URL, $mailchimp_url.'lists/'.$id_audiencia.'/members');
		 curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);		 //
		 curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		 curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
		 curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
		 curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
		 //curl_setopt($curl, CURLOPT_POST, true); 
		 //curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($addListMember));
		 curl_setopt($curl, CURLOPT_HEADER, FALSE);
		 curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type:application/json","Authorization: apikey {$mailchimp_secret_key}"]);
 
		 $result = curl_exec($curl);
		 if (curl_errno($curl)) {
			 $error_msg = curl_error($curl);
			 //$this->log(__LINE__ . ' ' . $error_msg);
			 $this->errorMessage = $error_msg;
			$this->hasError = 1;
		 }
		 curl_close($curl); 		 
		 
		 $result = json_decode($result, true);
		 
		 if(isset($result['members'])){				
			$r_members = $result['members'];			
			$members = array_map(function($user) {
				return [
					'id' => $user['id'],
					'email' => $user['email_address'],
				];
			}, $r_members);

			
			 $this->log(__LINE__ . ' ' . json_encode($members));
			
				$resultado = $this->buscarPorCorreo($members, $email);
				 if ($resultado !== null) {
					
					$this->arrayMessage[] =  __LINE__ . ' user found ' . $email;
					//actualizar
					return $resultado;
				} else {
					//insertar en web mailchimp
					//$this->log(__LINE__ . ' ' . json_encode("No se encontró el elemento con el correo: $email"));
					$this->arrayErrorMessage[] =  __LINE__ ." No se encontró el elemento con el correo: $email";
				}
		
			
			return $r_members;
		 }else{
			 if(isset($result['title'])){
			 	//$this->log(__LINE__ . ' ' . json_encode($result['title']));
				 $this->errorMessage = json_encode($result['title']);
				 $this->hasError = 1;
				 $this->arrayErrorMessage[] = json_encode($result['title']);
			 }
		 }
		 return [];
 
	 }

	 public function  searchMemberBysearchMembers($email , $id_audiencia){  
		//$this->log(__LINE__ . ' ' . json_encode('searchMemberByEmail'));      	

		$mailchimp_secret_key = env('mailchimp_secret_key', 'mailchimp_secret_key_1');
		$mailchimp_url = env('mailchimp_url', 'https://us13.api.mailchimp.com/3.0/lists/');      
		 $curl = curl_init();
//		  $this->log(__LINE__ . ' ' . $mailchimp_url.'search-members/?query='.$email);
		 curl_setopt($curl, CURLOPT_URL, $mailchimp_url.'search-members/?query='.$email);
		 curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);		 //
		 curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		 curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
		 curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
		 curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
		 //curl_setopt($curl, CURLOPT_POST, true); 
		 //curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($addListMember));
		 curl_setopt($curl, CURLOPT_HEADER, FALSE);
		 curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type:application/json","Authorization: apikey {$mailchimp_secret_key}"]);
 
		 $result = curl_exec($curl);
		 if (curl_errno($curl)) {
			 $error_msg = curl_error($curl);
			 //$this->log(__LINE__ . ' ' . $error_msg);
			 $this->errorMessage = $error_msg;
			$this->hasError = 1;
		 }
		 curl_close($curl); 		 
		 
		 $result = json_decode($result, true);
		 
		 if(isset($result['exact_matches'])){				
			if($result['exact_matches']['total_items'] >0)			{
				$member = $result['exact_matches']['members'][0];
				//$this->log(__LINE__ . ' ' . json_encode($result['exact_matches']['members'][0]['id']));
				$this->arrayMessage[] = __LINE__ . ' search email  ' . $member['id'] . ' ' . $email;		 
				return $member;
			}
		 }else{
			 if(isset($result['title'])){
			 	$this->log(__LINE__ . ' ' . json_encode($result['title']));
				$this->errorMessage = json_encode($result['title']);
				$this->hasError = 1;
				$this->arrayErrorMessage[] = json_encode($result['title']);
			 }
		 }
		 return [];
 
	 }

	 public function  updateMember($hash_user,$addListMember , $id_audiencia){
		//$this->log(__LINE__ . ' ' . json_encode('updateMember'));      	

        $mailchimp_secret_key = env('mailchimp_secret_key', 'mailchimp_secret_key_1');
		$mailchimp_url = env('mailchimp_url', 'https://us13.api.mailchimp.com/3.0/lists/');      
		//$this->log(__LINE__ . ' ' . json_encode($id_audiencia));
		//$this->log(__LINE__ . ' ' . json_encode($addListMember));
		 $curl = curl_init();
		 
		 curl_setopt($curl, CURLOPT_URL, $mailchimp_url.'lists/'.$id_audiencia.'/members/'.$hash_user);
		 curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);		 //
		 curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		 curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
		 curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
		 curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
		 //curl_setopt($curl, CURLOPT_POST, true); 
		 curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
		 curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($addListMember));
		 curl_setopt($curl, CURLOPT_HEADER, FALSE);
		 curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type:application/json","Authorization: apikey {$mailchimp_secret_key}"]);
 
		 $result = curl_exec($curl);
		 if (curl_errno($curl)) {
			 $error_msg = curl_error($curl);
			 //$this->log(__LINE__ . ' ' . $error_msg);
		 }
		 curl_close($curl); 
		 
		 //$this->log(__LINE__ . ' ' . ($result));
		 $result = json_decode($result, true);
		 
		 if(isset($result['id'])){	
			 //$this->log(__LINE__ . ' ' . json_encode('updated'));	 			 
			 return true;
		 }else{
			 if(isset($result['title'])){
			 //$this->log(__LINE__ . ' ' . json_encode($result['title']));
			 }
		 }
		 return 0;
 
	 }
	
	 function comparar_arreglos($arr_mailchimp, $arr_local, $id_audiencia,$tag) {
		//$this->log(__LINE__ . ' ' . json_encode('comparar_arreglos'));      	

		//$this->log(__LINE__ . ' ' . json_encode($arr_mailchimp));
		//$this->log(__LINE__ . ' ' . json_encode(count($arr_local)));
		foreach ($arr_mailchimp as $item) {
			 //$this->log(__LINE__ . ' ' . json_encode($item['email_address']));
			 $obj = array_column($arr_local, null, 'email')[$item['email_address']] ?? false;
			 //$this->log(__LINE__ . ' ' . json_encode($obj));
			 //$this->log(__LINE__ . ' ' . json_encode($obj==false));
			if ($obj==false) {//eliminar de mailchimp	
				 //$this->log(__LINE__ . ' ' . json_encode('eliminar de mailchimp'));
				 //$this->log(__LINE__ . ' ' . json_encode($item));
				//$this->deleteMember($item['id'],$id_audiencia);
				$this->addUpdateTag($id_audiencia,$item['id'], $tag);
				//[{"id":710,"email":"deidramcdearman@hotmail.com","name":"Deidra","lname":"Mares","attend_course":"1","select_course":"1"},{"id":1393,"email":"kashmirblack.77@gmail.com","name":"Test Carlos","lname":"Vargas","attend_course":"1","select_course":"1"},{"id":1846,"email":"thefarmerswife.tm@gmail.com","name":"Carrie","lname":"Korenek","attend_course":"1","select_course":"1"},{"id":4373,"email":"skincareman@hotmail.com","name":"Robert","lname":"Allen","attend_course":"1","select_course":"1"},{"id":8660,"email":"info@facelogix.com","name":"Kathryn","lname":"Dean","attend_course":"1","select_course":"1"},{"id":10011,"email":"info@luxeskincare.com","name":"Erica","lname":"Arancibia","attend_course":"1","select_course":"1"},{"id":10194,"email":"roxannekzg@gmail.com","name":"Roxanne","lname":"Zurita","attend_course":"1","select_course":"1"},{"id":11653,"email":"Hiltonmadison97@gmail.com","name":"Madison","lname":"Hilton","attend_course":"1","select_course":"1"},{"id":12569,"email":"tricia@rejuvenatemelounge.com","name":"Patricia","lname":"Garcia","attend_course":"1","select_course":"1"},{"id":12872,"email":"maripau107.pr@gmail.com","name":"mariana","lname":"reyes","attend_course":"1","select_course":"1"},{"id":16493,"email":"ajhalbach@yahoo.com","name":"Andrea","lname":"Halbach MSNed, RNC-OB","attend_course":"1","select_course":"1"},{"id":16533,"email":"chepafer@hotmail.com","name":"Josefa","lname":"Scott","attend_course":"1","select_course":"1"},{"id":19018,"email":"Mon.Moreno.r@gmail.com","name":"Montserrat","lname":"Moreno Ramirez","attend_course":"1","select_course":"1"},{"id":19608,"email":"ericardz817@icloud.com","name":"Erica","lname":"Rodriguez","attend_course":"1","select_course":"1"},{"id":20313,"email":"southernedcharm@gmail.com","name":"April","lname":"McClure","attend_course":"1","select_course":"1"},{"id":20819,"email":"reneerwilliams33@gmail.com","name":"Renee","lname":"Williams","attend_course":"1","select_course":"1"},{"id":21457,"email":"xkelciej143x@gmail.com","name":"Kelcie","lname":"Johnson","attend_course":"1","select_course":"1"},{"id":21500,"email":"Marylouleex@yahoo.com","name":"Maria","lname":"Montes","attend_course":"1","select_course":"1"},{"id":21607,"email":"victoriatorres6674@gmail.com","name":"Victoria","lname":"Torres","attend_course":"1","select_course":"1"},{"id":21930,"email":"larablush0213@gmail.com","name":"lara","lname":"blush","attend_course":"1","select_course":"1"},{"id":24120,"email":"charlenee76@gmail.com","name":"Charlene","lname":"Erskine","attend_course":"1","select_course":"1"},{"id":24282,"email":"crisytx@yahoo.com","name":"Chrissy","lname":"James","attend_course":"1","select_course":"1"},{"id":24735,"email":"icaarly760@gmail.com","name":"Carly","lname":"Camargo","attend_course":"1","select_course":"1"},{"id":25435,"email":"mgardner1977@gmail.com","name":"Maygan","lname":"Gardner","attend_course":"1","select_course":"1"},{"id":26944,"email":"monicadunaway@comcast.net","name":"Monica","lname":"Dunaway","attend_course":"1","select_course":"1"},{"id":27316,"email":"mecsunday@aol.com","name":"Mechelle","lname":"Sunday","attend_course":"1","select_course":"1"},{"id":27475,"email":"sara1bongard@gmail.com","name":"Sara","lname":"Bongard","attend_course":"1","select_course":"1"},{"id":27745,"email":"Sma@rtbspa.com","name":"Stephanie","lname":"Alvarenga","attend_course":"1","select_course":"1"},{"id":27862,"email":"mariaomtz@icloud.com","name":"Maria","lname":"Martinez","attend_course":"1","select_course":"1"},{"id":27968,"email":"roxymedspa@gmail.com","name":"Roksana","lname":"Akter","attend_course":"1","select_course":"1"},{"id":28279,"email":"LeslieDixon73@gmail.com","name":"Leslie","lname":"Dixon","attend_course":"1","select_course":"1"},{"id":28541,"email":"mistyodom@hotmail.com","name":"Misty","lname":"Durmon","attend_course":"1","select_course":"2"}]
				//return $item;
			}
		}

		$array = [
			 ['id' => 'one', 'color' => 'white'],
			 ['id' => 'two', 'color' => 'red'],
			 ['id' => 'three', 'color' => 'blue']
		];
		$obj = array_column($array, null, 'id')['one'] ?? false;

		 
		
		/*$a1=array("a"=>"red","b"=>"green","c"=>"blue");
		$a2=array("a"=>"blue","b"=>"black","e"=>"blue");

		$result=array_udiff($a1,$a2,function($a,$b)
		{
		if ($a===$b)
		  {
		  return 0;
		  }
		  return ($a>$b)?1:-1;
		});
		 //$this->log(__LINE__ . ' ' . json_encode($result));*/
		//print_r($result);

	}

	//lists/{list_id}/members/{subscriber_hash}
	public function  deleteMember($hash_user,$id_audiencia){
		//$this->log(__LINE__ . ' ' . json_encode('deleteMember'));      	

        $mailchimp_secret_key = env('mailchimp_secret_key', 'mailchimp_secret_key_1');
		$mailchimp_url = env('mailchimp_url', 'https://us13.api.mailchimp.com/3.0/lists/');      
		//$this->log(__LINE__ . ' ' . json_encode($id_audiencia));
		
		 $curl = curl_init();
		 
		 curl_setopt($curl, CURLOPT_URL, $mailchimp_url.'lists/'.$id_audiencia.'/members/'.$hash_user);
		 curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);		 //
		 curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		 curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
		 curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
		 curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
		 //curl_setopt($curl, CURLOPT_POST, true); 
		 curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "DELETE");
		 //curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($addListMember));
		 curl_setopt($curl, CURLOPT_HEADER, FALSE);
		 curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type:application/json","Authorization: apikey {$mailchimp_secret_key}"]);
 
		 $result = curl_exec($curl);
		 if (curl_errno($curl)) {
			 $error_msg = curl_error($curl);
			 //$this->log(__LINE__ . ' ' . $error_msg);
			 $this->errorMessage = $error_msg;
			$this->hasError = 1;
		 }
		 curl_close($curl); 
		 
		 //$this->log(__LINE__ . ' ' . ($result));
		 $result = json_decode($result, true);
		 
		 if(isset($result['id'])){	
			 //$this->log(__LINE__ . ' ' . json_encode('DELETED'));	 		
			 $this->arrayMessage[] = __LINE__ . ' deleted user  ' . $hash_user;		 
			 return true;
		 }else{
			 if(isset($result['title'])){
			 //$this->log(__LINE__ . ' ' . json_encode($result['title']));
			 $this->errorMessage = json_encode($result['title']);
			 $this->hasError = 1;
			 $this->arrayErrorMessage[] = json_encode($result['title']);
			 }
		 }
		 return 0;
 
	 }

	 public function  getAllWebhooks($id_audiencia){  
		//$this->log(__LINE__ . ' ' . json_encode('getAllWebhooks'));      	

		$mailchimp_secret_key = env('mailchimp_secret_key', '');
		$mailchimp_url = env('mailchimp_url', 'https://us13.api.mailchimp.com/3.0/');      								
        //URL_API = env('URL_API', 'https://api.spalivemd.com/');
 		 //$this->log(__LINE__ . ' ' . ($mailchimp_url.'lists/'.$id_audiencia.'/webhooks'));
        $curl = curl_init();        
        curl_setopt($curl, CURLOPT_URL, $mailchimp_url.'lists/'.$id_audiencia.'/webhooks');
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);        
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        //curl_setopt($curl, CURLOPT_POST, true); 
        //curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($campana));
        curl_setopt($curl, CURLOPT_HEADER, FALSE);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type:application/json","Authorization: apikey {$mailchimp_secret_key}"]);

        $result = curl_exec($curl);
		if (curl_errno($curl)) {
			$error_msg = curl_error($curl);
			//$this->log(__LINE__ . ' ' . $error_msg);
		}
        curl_close($curl);

        
        //$this->log(__LINE__ . ' ' . ($result));
		$result = json_decode($result, true);
		$list_id =0;		
		if(isset($result['webhooks']) && isset($result['total_items'])){
		
			 //$this->log(__LINE__ . ' ' . json_encode($result['webhooks']));
			 //$this->log(__LINE__ . ' ' . json_encode($result['total_items']));
			return $result['total_items'];
		}else{
			if(isset($result['title'])){
			//$this->log(__LINE__ . ' ' . json_encode($result['title']));
			}
		}
		return 0;

    }

	public function  createWebhook($id_audiencia,$params){  
		//$this->log(__LINE__ . ' ' . json_encode('createWebhook'));      	
		$url_webhook_mailchimp = env('url_webhook_mailchimp','https://api-dev.myspalive.com/?key=fdg32jmudsrfbqi28ghjsdodguhusdi&action=Webhook____mailchimp');

		$mailchimp_secret_key = env('mailchimp_secret_key', '');
		$mailchimp_url = env('mailchimp_url', 'https://us13.api.mailchimp.com/3.0/');      								
        //URL_API = env('URL_API', 'https://api.spalivemd.com/');
 		 //$this->log(__LINE__ . ' ' . json_encode($mailchimp_url.'lists/'.$id_audiencia.'/webhooks'));
        $curl = curl_init();        
        curl_setopt($curl, CURLOPT_URL, $mailchimp_url.'lists/'.$id_audiencia.'/webhooks');
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);        
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_POST, true); 
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
			'url'=>$url_webhook_mailchimp,
			"events"=>["subscribe"=>true,"unsubscribe"=>true,"profile"=>true,"cleaned"=>true,"upemail"=>true,"campaign"=>true],
			"sources"=>["user"=>true,"admin"=>true,"api"=>true]
			])
		);
        curl_setopt($curl, CURLOPT_HEADER, FALSE);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type:application/json","Authorization: apikey {$mailchimp_secret_key}"]);

        $result = curl_exec($curl);
		if (curl_errno($curl)) {
			$error_msg = curl_error($curl);
			//$this->log(__LINE__ . ' ' . $error_msg);
			$this->errorMessage = $error_msg;
			$this->hasError = 1;
		}
        curl_close($curl);

        
        //$this->log(__LINE__ . ' ' . ($result));
		$result = json_decode($result, true);
		$list_id =0;		
		if(isset($result['id']) ){
			$list_id = $result['id']; 		
			$this->arrayMessage[] = __LINE__ . 'createWebhook  ' . $result['id'];	
			return $list_id;
		}else{
			if(isset($result['title'])){
			//$this->log(__LINE__ . ' ' . json_encode($result));
			$this->errorMessage = json_encode($result);
			$this->hasError = 1;
			$this->arrayErrorMessage[] = json_encode($result['title']);
			}
		}
		return 0;

    }
	
	public function  getAllCampaing($campana){  
		//$this->log(__LINE__ . ' ' . json_encode('getAllCampaing'));      	

		$mailchimp_secret_key = env('mailchimp_secret_key', '');
		$mailchimp_url = env('mailchimp_url', 'https://us13.api.mailchimp.com/3.0/');      								
        //URL_API = env('URL_API', 'https://api.spalivemd.com/');
 		
        $curl = curl_init();        
        curl_setopt($curl, CURLOPT_URL, $mailchimp_url.'campaigns');
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);        
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        //curl_setopt($curl, CURLOPT_POST, true); 
        //curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($campana));
        curl_setopt($curl, CURLOPT_HEADER, FALSE);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type:application/json","Authorization: apikey {$mailchimp_secret_key}"]);

        $result = curl_exec($curl);
		if (curl_errno($curl)) {
			$error_msg = curl_error($curl);
			//$this->log(__LINE__ . ' ' . $error_msg);
			$this->errorMessage = $error_msg;
			$this->hasError = 1;
		}
        curl_close($curl);

        
        //$this->log(__LINE__ . ' ' . ($result));
		$result = json_decode($result, true);
		$list_id =0;		
		if(isset($result['campaigns']) ){
			if(isset($result['total_items']) && $result['total_items'] == 0){//no hay campañas
				$this->arrayMessage[] = __LINE__ .'no hay campañas  ';
				return 0;
			}
			$date = date('mdY');
			$audiencias = $result['campaigns']; 
			for ($i=0; $i < count($audiencias); $i++) { 
				//$this->log(__LINE__ . ' ' . json_encode($response));
				//$this->log(__LINE__ . ' ' . json_encode($audiencias[$i]));
				$audiencia = $audiencias[$i];
				//$this->log(__LINE__ . ' ' . json_encode($audiencia));
				//									  'CAMPAIGN_'.$type.'_'.$date,
				if($audiencia['settings']['title'] == 'CAMPAIGN_'.$campana.'_'.$date){
					 //$this->log(__LINE__ . ' ' . json_encode($audiencia['id']));
					 $this->arrayMessage[] = __LINE__ .'campana encontrada  '.$audiencia['id'];
					 $list_id = $audiencia['id'];
					 break;
				}            
			  }

			return $list_id;
		}else{
			if(isset($result['title'])){
			//$this->log(__LINE__ . ' ' . json_encode($result['title']));
			$this->errorMessage = json_encode($result['title']);
			$this->hasError = 1;
			$this->arrayErrorMessage[] = json_encode($result['title']);
			}
		}
		return 0;

    }

	public function  createCampaing($listId,$type,$subject,$saved_segment_id){  
		//$this->log(__LINE__ . ' ' . json_encode('createCampaing'));      	
		$date = date('mdY');
		$campaignData = [
			'type' => 'regular',
			'recipients' => [
				'list_id' => $listId,
				"segment_opts" => [
				"saved_segment_id" => $saved_segment_id,
				"match" =>  "any",
				"conditions" => array([					
						"condition_type" => "StaticSegment",
						"field" => "static_segment",
						"op" => "static_is",
						"value" => $saved_segment_id
				])
				]
			
			],
			'settings' => [
				'subject_line' => $subject,
				'title' => 'CAMPAIGN_'.$type.'_'.$date,
				'from_name' => 'MySpaLive',
				"reply_to"=> "dev@myspalive.com",
                "use_conversation"=> false,
                "to_name"=> "*|FNAME|* *|LNAME|*",
			]
		];

		 //$this->log(__LINE__ . ' ' . json_encode($campaignData));
		$mailchimp_secret_key = env('mailchimp_secret_key', '');
		$mailchimp_url = env('mailchimp_url', 'https://us13.api.mailchimp.com/3.0/');      								
        //URL_API = env('URL_API', 'https://api.spalivemd.com/');
 		
        $curl = curl_init();        
        curl_setopt($curl, CURLOPT_URL, $mailchimp_url.'campaigns');
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);        
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_POST, true); 
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($campaignData));
        curl_setopt($curl, CURLOPT_HEADER, FALSE);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type:application/json","Authorization: apikey {$mailchimp_secret_key}"]);

        $result = curl_exec($curl);
		if (curl_errno($curl)) {
			$error_msg = curl_error($curl);
			//$this->log(__LINE__ . ' ' . $error_msg);
			$this->errorMessage = $error_msg;
			$this->hasError = 1;
		}
        curl_close($curl);

        
        //$this->log(__LINE__ . ' ' . ($result));
		$result = json_decode($result, true);
		$list_id =0;		
		if(isset($result['id']) ){
			$list_id = $result['id']; 			
			$this->arrayMessage[] = __LINE__ . 'campana creada  '.$result['id'];
			return $list_id;
		}else{
			if(isset($result['title'])){
			//$this->log(__LINE__ . ' ' . json_encode($result));
			$this->errorMessage = json_encode($result);
			$this->hasError = 1;
			$this->arrayErrorMessage[] = json_encode($result['title']);
			}
		}
		return 0;

    }

	public function  updateCampaing($listId,$type,$subject){  
		//$this->log(__LINE__ . ' ' . json_encode('createCampaing'));      	

		$campaignData = [
			'type' => 'regular',
			'recipients' => [
				'list_id' => $listId
			],
			'settings' => [
				'subject_line' => $subject,
				'title' => 'CAMPAIGN_'.$type,
				'from_name' => 'MySpaLive',
				"reply_to"=> "dev@myspalive.com",
                "use_conversation"=> false,
                "to_name"=> "*|FNAME|* *|LNAME|*",
			]
		];

		
		$mailchimp_secret_key = env('mailchimp_secret_key', '');
		$mailchimp_url = env('mailchimp_url', 'https://us13.api.mailchimp.com/3.0/');      								
        //URL_API = env('URL_API', 'https://api.spalivemd.com/');
 		
        $curl = curl_init();        
        curl_setopt($curl, CURLOPT_URL, $mailchimp_url.'campaigns');
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);        
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_POST, true); 
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($campaignData));
        curl_setopt($curl, CURLOPT_HEADER, FALSE);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type:application/json","Authorization: apikey {$mailchimp_secret_key}"]);

        $result = curl_exec($curl);
		if (curl_errno($curl)) {
			$error_msg = curl_error($curl);
			//$this->log(__LINE__ . ' ' . $error_msg);
			$this->errorMessage = $error_msg;
			$this->hasError = 1;
		}
        curl_close($curl);

        
        //$this->log(__LINE__ . ' ' . ($result));
		$result = json_decode($result, true);
		$list_id =0;		
		if(isset($result['id']) ){
			$list_id = $result['id']; 		
			$this->arrayMessage[] = __LINE__ . 'campana actualizada  '.$result['id'];	
			return $list_id;
		}else{
			if(isset($result['title'])){
			//$this->log(__LINE__ . ' ' . json_encode($result));
			$this->errorMessage = json_encode($result);
			$this->hasError = 1;
			$this->arrayErrorMessage[] = json_encode($result['title']);
			}
		}
		return 0;

    }
	public function  addContentCampaing($id_campaign,$data){  
		//$this->log(__LINE__ . ' ' . json_encode('addContentCampaing'));      	

		$contentData = [
			'html' => $data
		];
		
		$mailchimp_secret_key = env('mailchimp_secret_key', '');
		$mailchimp_url = env('mailchimp_url', 'https://us13.api.mailchimp.com/3.0/');      								
        //URL_API = env('URL_API', 'https://api.spalivemd.com/');
 		
        $curl = curl_init();        
        curl_setopt($curl, CURLOPT_URL, $mailchimp_url.'campaigns/'.$id_campaign.'/content');
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);        
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($contentData));
        curl_setopt($curl, CURLOPT_HEADER, FALSE);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type:application/json","Authorization: apikey {$mailchimp_secret_key}"]);

        $result = curl_exec($curl);
		if (curl_errno($curl)) {
			$error_msg = curl_error($curl);
			//$this->log(__LINE__ . ' ' . $error_msg);
			$this->errorMessage = $error_msg;
			$this->hasError = 1;
		}
        curl_close($curl);

        
        //$this->log(__LINE__ . ' ' . ($result));
		$result = json_decode($result, true);
		$list_id =0;		
		if(isset($result['html']) ){
			$list_id = 1; 	
			$this->arrayMessage[] = __LINE__ .' add contenido campana ';		
			return $list_id;
		}else{
			if(isset($result['title'])){
			//$this->log(__LINE__ . ' ' . json_encode($result));
			//2024-06-05 18:16:49 Error: 978 {"type":"https://mailchimp.com/developer/marketing/docs/errors/","title":"Invalid Resource","status":400,
			//"detail":"Can only set content on campaigns with status 'save', 'paused', or 'scheduled'","instance":"829989b8-dbdb-a6fc-f439-ac5f9611d635"}
			$this->errorMessage = json_encode($result);
			$this->hasError = 1;
			$this->arrayErrorMessage[] = json_encode($result['title']);
			}
		}
		return 0;

    }

	public function  sendCampaing($id_campaign,$data){  
		//$this->log(__LINE__ . ' ' . json_encode('sendCampaing'));      	

		

		
		$mailchimp_secret_key = env('mailchimp_secret_key', '');
		$mailchimp_url = env('mailchimp_url', 'https://us13.api.mailchimp.com/3.0/');      								
        //URL_API = env('URL_API', 'https://api.spalivemd.com/');
 		
        $curl = curl_init();        
        curl_setopt($curl, CURLOPT_URL, $mailchimp_url.'campaigns/'.$id_campaign.'/actions/send');
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);        
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($curl, CURLOPT_POST, true); 
        //curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($curl, CURLOPT_HEADER, FALSE);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type:application/json","Authorization: apikey {$mailchimp_secret_key}"]);

        $result = curl_exec($curl);
		if (curl_errno($curl)) {
			$error_msg = curl_error($curl);
			//$this->log(__LINE__ . ' ' . $error_msg);
			$this->errorMessage = $error_msg;
			$this->hasError = 1;
		}
        curl_close($curl);

        
        //$this->log(__LINE__ . ' ' . ($result));
		$result = json_decode($result, true);
		$list_id =0;		
		if(isset($result['id']) ){
			$list_id = $result['id']; 
			$this->arrayMessage[] = __LINE__ . ' campana enviada';			
			return $list_id;
		}else{
			if(isset($result['title'])){
			//$this->log(__LINE__ . ' ' . json_encode($result));
			$this->errorMessage = json_encode($result['title']);
			$this->hasError = 1;
			$this->arrayErrorMessage[] = json_encode($result['title']);
			}
		}
		return 0;

    }

	public function  searchTags($listId, $tagname){  
		//$this->log(__LINE__ . ' ' . json_encode('tag-search'));      	

		$mailchimp_secret_key = env('mailchimp_secret_key', '');
		$mailchimp_url = env('mailchimp_url', 'https://us13.api.mailchimp.com/3.0/');      								
        //URL_API = env('URL_API', 'https://api.spalivemd.com/');
 		 //$this->log(__LINE__ . ' ' . ($mailchimp_url.'lists/'.$listId.'/tag-search'));
        $curl = curl_init();        
        curl_setopt($curl, CURLOPT_URL, $mailchimp_url.'lists/'.$listId.'/tag-search');
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);        
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        //curl_setopt($curl, CURLOPT_POST, true); 
        //curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($campana));
        curl_setopt($curl, CURLOPT_HEADER, FALSE);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type:application/json","Authorization: apikey {$mailchimp_secret_key}"]);

        $result = curl_exec($curl);
		if (curl_errno($curl)) {
			$error_msg = curl_error($curl);
			//$this->log(__LINE__ . ' ' . $error_msg);
			$this->errorMessage = $error_msg;
			$this->hasError = 1;
		}
        curl_close($curl);

        
        //$this->log(__LINE__ . ' ' . ($result));
		$result = json_decode($result, true);
		
		if(isset($result['tags']) && isset($result['total_items'])){
		
			$tags = $result['tags']; 
			for ($i=0; $i < count($tags); $i++) { 
				//$this->log(__LINE__ . ' ' . json_encode($response));
				//$this->log(__LINE__ . ' ' . json_encode($tags[$i]));
				$tag = $tags[$i];
				//$this->log(__LINE__ . ' ' . json_encode($tags));	
				if($tag['name'] == $tagname){
					 //$this->log(__LINE__ . ' ' . json_encode($tag['id']));
					 $this->arrayMessage[] = __LINE__ .'tag encontrado ' .$tag['id'];
					 return $tag;
					 break;
				}            
			  }
			
		}else{
			if(isset($result['title'])){
			//$this->log(__LINE__ . ' ' . json_encode($result['title']));
			$this->errorMessage = json_encode($result['title']);
			$this->hasError = 1;
			$this->arrayErrorMessage[] = json_encode($result['title']);
			}
		}
		return [];
    }

	public function  addUpdateTag($id_list,$member,$data){
		$this->arrayErrorMessage[] = 'addUpdateTag ' ;
        //$this->log(__LINE__ . ' ' . json_encode('addUpdateTag'));
		//https://us13.api.mailchimp.com/3.0/lists/1b44829b7f/members/1df5a7ec65240edcd671c18d2ba7a418/tags
		$mailchimp_secret_key = env('mailchimp_secret_key', 'mailchimp_secret_key_1');
		$mailchimp_url = env('mailchimp_url', 'https://us13.api.mailchimp.com/3.0/lists/');      
        $curl = curl_init();
        //$this->log(__LINE__ . ' ' . json_encode($mailchimp_url.'lists/'.$id_list.'/members/'.$member.'/tags'));
		 //$this->log(__LINE__ . ' ' . json_encode($data));
        curl_setopt($curl, CURLOPT_URL, $mailchimp_url.'lists/'.$id_list.'/members/'.$member.'/tags');
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);        //
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_POST, true); 
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($curl, CURLOPT_HEADER, FALSE);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type:application/json","Authorization: apikey {$mailchimp_secret_key}"]);

        $result = curl_exec($curl);
		if (curl_errno($curl)) {
			$error_msg = curl_error($curl);
			//$this->log(__LINE__ . ' ' . $error_msg);
			$this->errorMessage = $error_msg;
			$this->hasError = 1;
		}
        curl_close($curl);

        
        //$this->log(__LINE__ . ' ' . ($result));
		$result = json_decode($result, true);
		//$this->log(__LINE__ . ' ' . json_encode($result['health_status']));
		//$this->log(__LINE__ . ' ' . json_encode($result['type']));
		if(isset($result['id']) ){
			$this->arrayMessage[] = __LINE__ .'Tag found '.$result['id']  ;
			return $result['id'];
		}else{
			if(isset($result['title'])){
				//$this->log(__LINE__ . ' ' . json_encode($result['title']));
				$this->errorMessage = json_encode($result['title']);
				$this->hasError = 1;
				$this->arrayErrorMessage[] = json_encode($result['title']);
			}
		}
		return false;

    }

}